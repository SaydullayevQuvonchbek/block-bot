<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\MiniAppAuth;
use App\Core\TelegramClient;
use App\Policy\AdminAuthorizationService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use App\Policy\SubscriptionService;
use Throwable;

/**
 * Telegram Mini App uchun REST API (2.0 Phase 3, 3-band — Web Dashboard / Mini App).
 * `public/api.php` orqali chaqiriladi. `UpdateRouter`dan MUSTAQIL — Telegram update
 * emas, oddiy JSON so'rov/javob bilan ishlaydi, lekin xuddi shu mavjud xizmatlardan
 * (`SettingsService`, `SubscriptionService`, `PunishmentService`, `AdminAuthorizationService`)
 * foydalanadi, hech qanday biznes-mantiqni takrorlamaydi.
 *
 * Autentifikatsiya: HAR bir chaqiruv Telegram WebApp `initData`sini talab qiladi
 * (`App\Core\MiniAppAuth::verify()`) — bot API tokeni bilan HMAC tekshiriladi,
 * alohida login/parol/sessiya SHART EMAS. Guruhga oid har bir amal, bundan tashqari,
 * so'rovchi HAQIQATDA o'sha guruh admini/egasi ekanini `AdminAuthorizationService::
 * isAdmin()` orqali qayta tekshiradi (frontend'dan kelgan `chat_id`ga hech qachon
 * ishonilmaydi).
 */
class MiniAppApiRouter
{
    private TelegramClient $telegram;
    private AdminAuthorizationService $auth;
    private PunishmentService $punishment;

    public function __construct(
        ?TelegramClient $telegram = null,
        ?AdminAuthorizationService $auth = null,
        ?PunishmentService $punishment = null
    ) {
        $this->telegram = $telegram ?? new TelegramClient();
        $this->auth = $auth ?? new AdminAuthorizationService($this->telegram);
        $this->punishment = $punishment ?? new PunishmentService($this->telegram, $this->auth);
    }

    /**
     * @param array<string, mixed> $query  $_GET
     * @param array<string, mixed> $body   JSON so'rov tanasi (POST)
     * @return array{ok: bool, http_status: int, ...}
     */
    public function handle(string $action, array $query, array $body, string $initData): array
    {
        $ctx = MiniAppAuth::verify($initData);
        if ($ctx === null) {
            return $this->fail('unauthorized', "Sessiya yaroqsiz yoki eskirgan. Botni Telegram ichida qayta oching.", 401);
        }
        $userId = $ctx['user_id'];

        try {
            return match ($action) {
                'me' => $this->me($userId),
                'overview' => $this->overview($userId, (int)($query['chat_id'] ?? 0)),
                'settings_get' => $this->getSettings($userId, (int)($query['chat_id'] ?? 0)),
                'settings_update' => $this->updateSettings($userId, (int)($body['chat_id'] ?? 0), (array)($body['settings'] ?? [])),
                'warnings' => $this->listWarnings($userId, (int)($query['chat_id'] ?? 0)),
                'unmute' => $this->moderationAction($userId, (int)($body['chat_id'] ?? 0), (int)($body['user_id'] ?? 0), 'unmute'),
                'unban' => $this->moderationAction($userId, (int)($body['chat_id'] ?? 0), (int)($body['user_id'] ?? 0), 'unban'),
                'resetwarns' => $this->moderationAction($userId, (int)($body['chat_id'] ?? 0), (int)($body['user_id'] ?? 0), 'resetwarns'),
                'appeals' => $this->listAppeals($userId, (int)($query['chat_id'] ?? 0)),
                'review_appeal' => $this->reviewAppeal($userId, (int)($body['appeal_id'] ?? 0), !empty($body['accept'])),
                default => $this->fail('unknown_action', "Noma'lum amal: {$action}", 404),
            };
        } catch (Throwable $e) {
            Logger::error("Mini App API xatosi [action: {$action}]: " . $e->getMessage(), [], 'miniapp');
            return $this->fail('internal_error', "Kutilmagan xatolik yuz berdi.", 500);
        }
    }

    private function ok(array $data): array
    {
        return array_merge(['ok' => true, 'http_status' => 200], $data);
    }

    private function fail(string $error, string $message, int $httpStatus): array
    {
        return ['ok' => false, 'http_status' => $httpStatus, 'error' => $error, 'message' => $message];
    }

    /**
     * Guruh berilganini va so'rovchi haqiqatan o'sha guruh admini/egasi ekanini
     * tekshiradi. Xato bo'lsa javob massivini, hammasi joyida bo'lsa `null`ni qaytaradi.
     */
    private function requireGroupAdmin(int $userId, int $chatId): ?array
    {
        if ($chatId === 0) {
            return $this->fail('bad_request', "chat_id ko'rsatilmagan.", 400);
        }
        if (!$this->auth->isAdmin($chatId, $userId)) {
            return $this->fail('unauthorized', "Bu guruh bo'yicha vakolatingiz yo'q.", 403);
        }
        return null;
    }

    /**
     * Dashboard ochilganda birinchi chaqiriladigan amal — foydalanuvchi boshqaradigan
     * guruhlar ro'yxati (guruh tanlash ekrani uchun).
     */
    private function me(int $userId): array
    {
        $groups = AdminAuthorizationService::adminGroupsOfUser($userId);
        $list = array_map(static fn (array $g): array => [
            'chat_id' => (int)$g['chat_id'],
            'title' => (string)($g['title'] ?? 'Guruh'),
        ], $groups);

        return $this->ok(['user_id' => $userId, 'groups' => $list]);
    }

    /**
     * Guruh tanlangandan keyingi bosh ekran: tarif holati, bugungi AI ishlatish,
     * faol ogohlantirishlar/shikoyatlar soni va sozlamalarning qisqacha ko'rinishi.
     */
    private function overview(int $userId, int $chatId): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }

        $settings = SettingsService::get($chatId);
        $plan = SubscriptionService::getPlan($chatId);
        $limit = Config::getInt('FREE_TIER_DAILY_AI_REQUESTS', 150);

        $pdo = Database::getConnection();
        $todayStart = gmdate('Y-m-d 00:00:00');

        $warnStmt = $pdo->prepare("SELECT COUNT(*) FROM user_warnings WHERE chat_id = :cid AND is_active = 1 AND expires_at > :now");
        $warnStmt->execute(['cid' => $chatId, 'now' => gmdate('Y-m-d H:i:s')]);
        $activeWarnings = (int)$warnStmt->fetchColumn();

        $appealStmt = $pdo->prepare("SELECT COUNT(*) FROM moderation_appeals WHERE chat_id = :cid AND status = 'pending'");
        $appealStmt->execute(['cid' => $chatId]);
        $pendingAppeals = (int)$appealStmt->fetchColumn();

        $actionsStmt = $pdo->prepare("
            SELECT COUNT(*) FROM telegram_actions
            WHERE chat_id = :cid AND created_at >= :today_start
              AND action_type IN ('warn_user', 'mute_user', 'ban_user') AND status = 'success'
        ");
        $actionsStmt->execute(['cid' => $chatId, 'today_start' => $todayStart]);
        $actionsToday = (int)$actionsStmt->fetchColumn();

        return $this->ok([
            'group' => ['chat_id' => $chatId, 'title' => $this->groupTitle($chatId)],
            'plan' => [
                'plan' => $plan['plan'],
                'is_premium' => $plan['is_premium'],
                'premium_expires_at' => $plan['premium_expires_at'],
                'ai_requests_today' => SubscriptionService::dailyAiRequestCount($chatId),
                'ai_daily_limit' => $limit > 0 ? $limit : null,
            ],
            'moderation' => [
                'active_warnings' => $activeWarnings,
                'pending_appeals' => $pendingAppeals,
                'actions_today' => $actionsToday,
            ],
            'settings_summary' => [
                'flood_enabled' => (bool)$settings['flood_enabled'],
                'captcha_enabled' => (bool)$settings['captcha_enabled'],
                'media_filter' => (bool)$settings['media_filter'],
                'profanity_filter' => (bool)$settings['profanity_filter'],
                'link_filter' => (bool)$settings['link_filter'],
                'language' => $settings['language'] ?? 'uz',
            ],
        ]);
    }

    private function getSettings(int $userId, int $chatId): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        $settings = SettingsService::get($chatId);
        unset($settings['chat_id'], $settings['created_at'], $settings['updated_at']);
        return $this->ok(['settings' => $settings]);
    }

    /**
     * `SettingsService::update()`ning o'zi ustun nomlarini whitelist qiladi
     * (`$allowedColumns`) — bu yerda qo'shimcha filtr shart emas, frontend'dan
     * kelgan har qanday noma'lum kalit shunchaki e'tiborsiz qoldiriladi.
     */
    private function updateSettings(int $userId, int $chatId, array $values): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        if ($values === []) {
            return $this->fail('bad_request', "Yangilanadigan sozlama berilmagan.", 400);
        }
        SettingsService::update($chatId, $values);
        $settings = SettingsService::get($chatId);
        unset($settings['chat_id'], $settings['created_at'], $settings['updated_at']);
        return $this->ok(['settings' => $settings]);
    }

    private function listWarnings(int $userId, int $chatId): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT w.id, w.user_id, w.reason, w.expires_at, w.created_at,
                   u.first_name, u.username
            FROM user_warnings w
            LEFT JOIN users u ON u.user_id = w.user_id
            WHERE w.chat_id = :cid AND w.is_active = 1 AND w.expires_at > :now
            ORDER BY w.created_at DESC
            LIMIT 100
        ");
        $stmt->execute(['cid' => $chatId, 'now' => gmdate('Y-m-d H:i:s')]);
        return $this->ok(['warnings' => $stmt->fetchAll() ?: []]);
    }

    private function moderationAction(int $userId, int $chatId, int $targetUserId, string $kind): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        if ($targetUserId <= 0) {
            return $this->fail('bad_request', "user_id ko'rsatilmagan.", 400);
        }

        $success = match ($kind) {
            'unmute' => $this->punishment->unmute($chatId, $targetUserId),
            'unban' => $this->punishment->unban($chatId, $targetUserId),
            'resetwarns' => $this->punishment->resetWarnings($chatId, $targetUserId),
            default => false,
        };

        return $this->ok(['success' => $success]);
    }

    private function listAppeals(int $userId, int $chatId): array
    {
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT a.id, a.user_id, a.recheck_status, a.recheck_reason, a.created_at, t.action_type,
                   u.first_name, u.username
            FROM moderation_appeals a
            JOIN telegram_actions t ON t.id = a.action_id
            LEFT JOIN users u ON u.user_id = a.user_id
            WHERE a.chat_id = :cid AND a.status = 'pending'
            ORDER BY a.created_at DESC
            LIMIT 100
        ");
        $stmt->execute(['cid' => $chatId]);
        return $this->ok(['appeals' => $stmt->fetchAll() ?: []]);
    }

    /**
     * `UpdateRouter::reviewAppeal()`dan ATAYLAB mustaqil — o'sha metod Telegram
     * callback_query'ga javob berishga bog'langan (`answerCallbackQuery`), bu yerda
     * esa oddiy JSON javob kerak. Ikkalasi ham bir xil ma'lumotlar bazasi holatini
     * (`moderation_appeals.status`) yangilaydi, shuning uchun bot orqali yoki
     * dashboard orqali ko'rib chiqilishidan qat'i nazar natija bir xil bo'ladi.
     */
    private function reviewAppeal(int $userId, int $appealId, bool $accept): array
    {
        if ($appealId <= 0) {
            return $this->fail('bad_request', "appeal_id ko'rsatilmagan.", 400);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT a.*, t.action_type
            FROM moderation_appeals a
            JOIN telegram_actions t ON t.id = a.action_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $appealId]);
        $appeal = $stmt->fetch();
        if (!$appeal) {
            return $this->fail('not_found', "Shikoyat topilmadi.", 404);
        }

        $chatId = (int)$appeal['chat_id'];
        if (($err = $this->requireGroupAdmin($userId, $chatId)) !== null) {
            return $err;
        }
        if ($appeal['status'] !== 'pending') {
            return $this->fail('already_reviewed', "Bu shikoyat avval ko'rib chiqilgan.", 409);
        }

        $targetUserId = (int)$appeal['user_id'];
        if ($accept) {
            $appeal['action_type'] === 'ban_user'
                ? $this->punishment->unban($chatId, $targetUserId)
                : $this->punishment->unmute($chatId, $targetUserId);
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare("
            UPDATE moderation_appeals
            SET status = :status, reviewed_by = :reviewer, updated_at = :now
            WHERE id = :id
        ");
        $update->execute([
            'status' => $accept ? 'accepted' : 'rejected',
            'reviewer' => $userId,
            'now' => $now,
            'id' => $appealId,
        ]);

        $notice = $accept
            ? "✅ Sizning shikoyatingiz qabul qilindi — cheklov olib tashlandi."
            : "❌ Sizning shikoyatingiz ko'rib chiqildi va rad etildi.";
        $this->telegram->sendMessage($targetUserId, $notice);

        return $this->ok(['status' => $accept ? 'accepted' : 'rejected']);
    }

    private function groupTitle(int $chatId): string
    {
        $stmt = Database::getConnection()->prepare("SELECT title FROM `groups` WHERE chat_id = :cid");
        $stmt->execute(['cid' => $chatId]);
        $title = $stmt->fetchColumn();
        return $title !== false && $title !== '' ? (string)$title : 'Guruh';
    }
}
