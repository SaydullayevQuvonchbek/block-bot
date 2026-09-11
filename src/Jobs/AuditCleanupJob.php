<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditService;
use App\Core\Database;
use App\Core\Logger;
use App\Core\QueueService;
use App\Core\TelegramClient;
use App\Policy\AdminNotificationService;
use App\Policy\ModerationDecisionService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use Throwable;

/**
 * Audit yoki a'zolar sweep sessiyasidagi topilmalarni admin tasdig'i bilan tozalaydi:
 *  - delete_messages : belgilangan tarixiy xabarlarni Bot API orqali o'chirish
 *  - restrict_adult  : 18+ akkauntlarni vaqtincha cheklash (mute)
 *  - restrict_bots   : ruxsatsiz bot akkauntlarni vaqtincha cheklash
 *  - ban_adult       : 18+ akkauntlarni guruhdan butunlay chetlatish
 *  - ban_bots        : bot akkauntlarni guruhdan butunlay chetlatish
 */
class AuditCleanupJob
{
    /** @var string[] O'chiriladigan qoidabuzarlik kategoriyalari */
    private const DELETABLE_CATEGORIES = [
        'profanity', 'pornography', 'adult_profile', 'hate_speech',
        'spam_ad', 'gambling', 'trading_scam', 'apk_distribution', 'malicious_link',
    ];

    /** Bitta job ishida qayta ishlanadigan maksimum element (qolgani re-queue qilinadi) */
    private const BATCH_LIMIT = 200;

    public function handle(array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $chatId = (int)($data['chat_id'] ?? 0);
        $mode = (string)($data['mode'] ?? '');
        $notifyChatId = (int)($data['notify_chat_id'] ?? 0);

        if ($sessionId <= 0 || $chatId === 0) {
            return;
        }

        $session = AuditService::get($sessionId);
        if (!$session || (int)$session['chat_id'] !== $chatId) {
            Logger::warning("AuditCleanupJob: sessiya topilmadi yoki guruh mos emas", ['session_id' => $sessionId], 'audit');
            return;
        }

        $telegram = new TelegramClient();
        $settings = SettingsService::get($chatId);

        try {
            $summary = match ($mode) {
                'delete_messages' => $this->deleteMessages($telegram, $sessionId, $chatId),
                'restrict_adult'  => $this->actOnAccounts($sessionId, $chatId, $settings, 'adult', false),
                'restrict_bots'   => $this->actOnAccounts($sessionId, $chatId, $settings, 'bot', false),
                'ban_adult'       => $this->actOnAccounts($sessionId, $chatId, $settings, 'adult', true),
                'ban_bots'        => $this->actOnAccounts($sessionId, $chatId, $settings, 'bot', true),
                default           => null,
            };
        } catch (Throwable $e) {
            Logger::error("AuditCleanupJob xatosi ({$mode}): " . $e->getMessage(), ['session_id' => $sessionId], 'audit');
            $summary = ['text' => "❌ Tozalash bajarilmadi: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'markup' => []];
        }

        if ($summary === null) {
            return;
        }

        // Katta hajmda: qolgan elementlarni keyingi job ishига qoldirish.
        if (!empty($summary['requeue'])) {
            QueueService::push('App\Jobs\AuditCleanupJob', [
                'session_id' => $sessionId,
                'chat_id' => $chatId,
                'mode' => $mode,
                'notify_chat_id' => $notifyChatId,
            ], QueueService::PRIORITY_LOW, 3);
        }

        $text = (string)($summary['text'] ?? '');
        $markup = (array)($summary['markup'] ?? []);
        AuditService::saveCleanupSummary($sessionId, strip_tags($text));

        if ($text === '') {
            return;
        }

        if ($notifyChatId !== 0) {
            $telegram->sendMessage($notifyChatId, $text, $markup);
        } else {
            (new AdminNotificationService($telegram))->send($chatId, $text, $markup);
        }
    }

    private function deleteMessages(TelegramClient $telegram, int $sessionId, int $chatId): array
    {
        $pdo = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count(self::DELETABLE_CATEGORIES), '?'));
        $stmt = $pdo->prepare("
            SELECT id, message_id
            FROM audit_items
            WHERE audit_session_id = ?
              AND status = 'unsafe'
              AND message_id IS NOT NULL AND message_id > 0
              AND (cleanup_status IS NULL OR cleanup_status = 'delete_failed')
              AND category IN ({$placeholders})
            ORDER BY message_id ASC
            LIMIT " . self::BATCH_LIMIT . "
        ");
        $stmt->execute(array_merge([$sessionId], self::DELETABLE_CATEGORIES));
        $items = $stmt->fetchAll();

        $mark = $pdo->prepare("UPDATE audit_items SET cleanup_status = :st, cleanup_at = :now WHERE id = :id");
        foreach ($items as $item) {
            $ok = $telegram->deleteMessage($chatId, (int)$item['message_id']);
            $mark->execute([
                'st' => $ok ? 'deleted' : 'delete_failed',
                'now' => gmdate('Y-m-d H:i:s'),
                'id' => (int)$item['id'],
            ]);
            usleep(45000); // ~22 so'rov/s — Telegram flud limitidan xavfsiz
        }

        // Yana qolgan bo'lsa — keyingi job ishida davom etadi.
        $remainStmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_items
            WHERE audit_session_id = ? AND status = 'unsafe'
              AND message_id IS NOT NULL AND message_id > 0
              AND (cleanup_status IS NULL OR cleanup_status = 'delete_failed')
              AND category IN ({$placeholders})
        ");
        $remainStmt->execute(array_merge([$sessionId], self::DELETABLE_CATEGORIES));
        $remaining = (int)$remainStmt->fetchColumn();
        if ($remaining > 0) {
            return ['text' => '', 'markup' => [], 'requeue' => true];
        }

        $deleted = (int)$pdo->query("SELECT COUNT(*) FROM audit_items WHERE audit_session_id = " . (int)$sessionId . " AND cleanup_status = 'deleted'")->fetchColumn();
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM audit_items WHERE audit_session_id = " . (int)$sessionId . " AND cleanup_status = 'delete_failed'")->fetchColumn();

        $text = "🧹 <b>Tarixiy tozalash yakunlandi</b>\n"
            . "• O'chirildi: <b>{$deleted}</b> ta xabar\n"
            . ($failed > 0 ? "• O'chmadi: {$failed} ta (xabar allaqachon yo'q yoki bot huquqi yetarli emas)\n" : '')
            . "\n<i>Eslatma: bot superguruhda \"Delete messages\" huquqiga ega bo'lishi shart.</i>";

        return ['text' => $text, 'markup' => []];
    }

    /**
     * @param 'adult'|'bot' $kind
     */
    private function actOnAccounts(int $sessionId, int $chatId, array $settings, string $kind, bool $permanentBan): array
    {
        // Restrict rejimida allaqachon mute/ban qilinganlar chetlab o'tiladi; ban rejimida
        // faqat allaqachon ban qilinganlar. Bu batch'lar oldinga siljishini kafolatlaydi.
        $excludeActions = $permanentBan ? ['ban_user'] : ['mute_user', 'ban_user'];
        $allIds = $this->collectAccountIds($sessionId, $chatId, $kind, $excludeActions);
        $requeue = count($allIds) > self::BATCH_LIMIT;
        $userIds = array_slice($allIds, 0, self::BATCH_LIMIT);
        $punishment = new PunishmentService();

        $done = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($userIds as $userId) {
            $decision = $permanentBan
                ? [
                    'action' => 'ban_user',
                    'delete_message' => false,
                    'notify_admin' => false,
                    'reason' => $kind === 'bot' ? 'Bot akkaunt — admin tasdig\'i bilan chetlatildi' : '18+ akkaunt — admin tasdig\'i bilan chetlatildi',
                    'evidence' => '',
                    'strike_count' => 99,
                    'quiet' => true,
                ]
                : ModerationDecisionService::decide([
                    'status' => 'unsafe',
                    'category' => $kind === 'bot' ? 'bot_account' : 'adult_profile',
                    'reason' => $kind === 'bot' ? 'A\'zolar tekshiruvi: ruxsatsiz bot' : 'A\'zolar / tarix tekshiruvi: 18+ akkaunt',
                    'evidence' => '',
                    'source' => 'audit_cleanup',
                ], $chatId, $userId, null, array_merge($settings, ['adult_account_action' => 'mute_notify']));

            $decision['quiet'] = true;
            $res = $punishment->execute($chatId, $userId, null, $decision);
            $status = (string)($res['status'] ?? 'failed');

            if (in_array($status, ['executed', 'partial'], true)) {
                $done++;
            } elseif ($status === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
            usleep(45000);
        }

        if ($requeue) {
            return ['text' => '', 'markup' => [], 'requeue' => true];
        }

        $label = $kind === 'bot' ? 'bot akkaunt' : '18+ akkaunt';
        if ($permanentBan) {
            $text = "🚫 <b>{$label}lar chetlatildi</b>\n"
                . "• Ban qilindi: <b>{$done}</b>\n"
                . ($skipped > 0 ? "• O'tkazib yuborildi (admin/oq ro'yxat): {$skipped}\n" : '')
                . ($failed > 0 ? "• Bajarilmadi: {$failed} (bot huquqi yetarli emas)\n" : '');
            return ['text' => $text ?: "🚫 {$label}: chetlatish uchun akkaunt topilmadi.", 'markup' => []];
        }

        if ($done === 0 && $skipped === 0 && $failed === 0) {
            return ['text' => "🔇 {$label}: cheklash uchun akkaunt topilmadi.", 'markup' => []];
        }

        $text = "🔇 <b>{$label}lar vaqtincha cheklandi (mute)</b>\n"
            . "• Cheklandi: <b>{$done}</b>\n"
            . ($skipped > 0 ? "• O'tkazib yuborildi (allaqachon cheklangan / admin): {$skipped}\n" : '')
            . ($failed > 0 ? "• Bajarilmadi: {$failed}\n" : '')
            . "\n<i>Ularni butunlay chetlatish uchun quyidagi tugmani bosing.</i>";

        $markup = $done > 0 ? [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => "🚫 Barcha {$label}larni ban qilish", 'callback_data' => "audit_ban:{$sessionId}:{$kind}"],
                ]],
            ],
        ] : [];

        return ['text' => $text, 'markup' => $markup];
    }

    /**
     * @param string[] $excludeActions
     * @return int[]
     */
    private function collectAccountIds(int $sessionId, int $chatId, string $kind, array $excludeActions = []): array
    {
        $pdo = Database::getConnection();
        $ids = [];

        if ($kind === 'adult') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT user_id FROM audit_items
                WHERE audit_session_id = :sid
                  AND user_id IS NOT NULL AND user_id > 0
                  AND category IN ('adult_profile', 'pornography')
            ");
            $stmt->execute(['sid' => $sessionId]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT user_id FROM audit_items
                WHERE audit_session_id = :sid
                  AND user_id IS NOT NULL AND user_id > 0
                  AND category = 'bot_account'
            ");
            $stmt->execute(['sid' => $sessionId]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

            // Shu guruhda ma'lum bo'lgan barcha adminmas bot akkauntlar.
            $botStmt = $pdo->prepare("
                SELECT c.user_id
                FROM chat_members c
                JOIN users u ON u.user_id = c.user_id
                WHERE c.chat_id = :cid
                  AND u.is_bot = 1
                  AND c.role NOT IN ('creator', 'administrator')
                  AND c.is_whitelisted = 0
                  AND c.is_admin_exempt = 0
            ");
            $botStmt->execute(['cid' => $chatId]);
            $ids = array_merge($ids, array_map('intval', $botStmt->fetchAll(\PDO::FETCH_COLUMN)));
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === [] || $excludeActions === []) {
            return $ids;
        }

        // Allaqachon chora ko'rilgan akkauntlarni chiqarib tashlash (batch'lar oldinga siljishi uchun).
        $actionPlaceholders = implode(',', array_fill(0, count($excludeActions), '?'));
        $doneStmt = $pdo->prepare("
            SELECT DISTINCT user_id FROM telegram_actions
            WHERE chat_id = ? AND status IN ('executed', 'partial')
              AND action_type IN ({$actionPlaceholders})
        ");
        $doneStmt->execute(array_merge([$chatId], $excludeActions));
        $alreadyDone = array_flip(array_map('intval', $doneStmt->fetchAll(\PDO::FETCH_COLUMN)));

        return array_values(array_filter($ids, static fn (int $id): bool => !isset($alreadyDone[$id])));
    }
}
