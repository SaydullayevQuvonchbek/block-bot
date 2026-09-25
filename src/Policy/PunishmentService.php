<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use App\Core\Translator;
use PDO;
use Throwable;

class PunishmentService
{
    private TelegramClient $telegram;
    private AdminAuthorizationService $auth;
    private AdminNotificationService $adminNotifier;

    public function __construct(
        ?TelegramClient $telegram = null,
        ?AdminAuthorizationService $auth = null
    ) {
        $this->telegram = $telegram ?? new TelegramClient();
        $this->auth = $auth ?? new AdminAuthorizationService($this->telegram);
        $this->adminNotifier = new AdminNotificationService($this->telegram);
    }

    /**
     * Qoidabuzarga nisbatan jazo chorasini qo'llash (Idempotent)
     */
    public function execute(
        int|string $chatId,
        int $userId,
        ?int $messageId,
        array $decision,
        ?string $mediaGroupId = null,
        ?int $findingId = null
    ): array {
        $chatId = (int)$chatId;
        $action = $decision['action'] ?? 'none';

        if ($action === 'none') {
            return ['status' => 'skipped', 'reason' => 'Harakat talab etilmaydi'];
        }

        // 1. Guruh egasi yoki admin ekanini tekshirish (adminlarga jazo qo'llanilmaydi)
        if ($this->auth->isAdmin($chatId, $userId) || $this->auth->isWhitelisted($chatId, $userId)) {
            Logger::info("Admin yoki oq ro'yxatdagi a'zoga jazo qo'llash bekor qilindi [chat: {$chatId}, user: {$userId}]", [], 'moderation');
            return ['status' => 'skipped', 'reason' => 'Foydalanuvchi admin yoki oq ro\'yxatda'];
        }

        // 2. Idempotency kalitini yaratish (albomlar uchun bitta jazo)
        $idempotencyKey = !empty($mediaGroupId)
            ? "act_{$chatId}_album_{$mediaGroupId}_{$action}"
            : (($messageId ?? 0) > 0
                ? "act_{$chatId}_msg_{$messageId}_{$action}"
                : "act_{$chatId}_user_{$userId}_{$action}");

        $pdo = Database::getConnection();

        // 3. Ushbu jazo avval bajarilganini tekshirish
        $stmtCheck = $pdo->prepare("SELECT id, status FROM telegram_actions WHERE idempotency_key = :k");
        $stmtCheck->execute(['k' => $idempotencyKey]);
        $existingAction = $stmtCheck->fetch();
        if ($existingAction && in_array($existingAction['status'], ['executed', 'partial'], true)) {
            return ['status' => 'skipped', 'reason' => 'Ushbu harakat avval bajarilgan (idempotency)'];
        }

        // 4. Boshlang'ich yozuvni saqlash
        $now = gmdate('Y-m-d H:i:s');
        if ($existingAction) {
            $actionRecordId = (int)$existingAction['id'];
            $pdo->prepare("UPDATE telegram_actions SET status = 'pending', error_message = NULL WHERE id = :id")
                ->execute(['id' => $actionRecordId]);
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO telegram_actions (chat_id, user_id, message_id, action_type, idempotency_key, status, created_at)
                VALUES (:cid, :uid, :mid, :type, :k, 'pending', :now)
            ");
            $stmtIns->execute([
                'cid' => $chatId,
                'uid' => $userId,
                'mid' => $messageId,
                'type' => $action,
                'k' => $idempotencyKey,
                'now' => $now,
            ]);
            $actionRecordId = (int)$pdo->lastInsertId();
        }

        $actionSuccess = true;
        $deleteSuccess = true;
        $errors = [];

        // 5. Xabarni o'chirish (agar kerak bo'lsa)
        $deleteRequired = (bool)($decision['delete_message'] ?? false) && $messageId > 0;
        if ($deleteRequired) {
            $deleted = $this->telegram->deleteMessage($chatId, $messageId);
            if (!$deleted) {
                $deleteSuccess = false;
                $errors[] = "Xabarni o'chirib bo'lmadi";
                Logger::warning("Xabarni o'chirib bo'lmadi [chat: {$chatId}, msg: {$messageId}]", [], 'moderation');
            }
        }

        // 6. Asosiy jazo amalini bajarish
        // Guruh a'zosiga (shaxsiy xabar sifatida) yuboriladigan xabar guruhning
        // tanlangan tilida (`group_settings.language`, standart 'uz') tuziladi —
        // 2.0 Phase 3, 1-band (i18n).
        $lang = Translator::normalizeLang(SettingsService::get($chatId)['language'] ?? null);
        $noticeText = "";
        $strike = $decision['strike_count'] ?? 1;
        $warnLimit = max(1, (int)($decision['warn_limit'] ?? 3));
        $reason = (string)($decision['reason'] ?? 'Qoidabuzarlik');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $userLink = "<a href=\"tg://user?id={$userId}\">{$userId}</a>";
        $warningKey = null;

        // Har bir bosqich (warn/mute) keyingi qaror uchun faol strike sifatida saqlanadi.
        if (in_array($action, ['warn_user', 'mute_user'], true) && $strike < 99) {
            $warningKey = "warn_{$idempotencyKey}";
            $warnStmt = $pdo->prepare("
                INSERT INTO user_warnings (chat_id, user_id, idempotency_key, reason, finding_id, is_active, expires_at, created_at)
                VALUES (:cid, :uid, :k, :reason, :fid, 1, :exp, :now)
            ");
            $durationDays = max(1, (int)($decision['warning_duration_days'] ?? 30));
            try {
                $warnStmt->execute([
                    'cid' => $chatId,
                    'uid' => $userId,
                    'k' => $warningKey,
                    'reason' => mb_substr($reason, 0, 255),
                    'fid' => $findingId,
                    'exp' => gmdate('Y-m-d H:i:s', time() + ($durationDays * 86400)),
                    'now' => $now,
                ]);
            } catch (Throwable $e) {
                // Retry paytida unique warning allaqachon mavjud bo'lishi mumkin.
                Logger::warning("Ogohlantirish yozuvi takrorlandi: " . $e->getMessage(), [], 'moderation');
            }
        }

        switch ($action) {
            case 'warn_user':
                $noticeText = Translator::get('punishment.warn', $lang, [
                    'user_link' => $userLink,
                    'reason' => $safeReason,
                    'strike' => $strike,
                    'limit' => $warnLimit,
                ]);
                break;

            case 'mute_user':
                $durationSec = (int)($decision['mute_duration_sec'] ?? 3600);
                $actionSuccess = $this->telegram->muteUser($chatId, $userId, $durationSec);
                $durationHours = round($durationSec / 3600, 1);

                if ($actionSuccess) {
                    $noticeText = Translator::get('punishment.mute', $lang, [
                        'user_link' => $userLink,
                        'hours' => $durationHours,
                        'reason' => $safeReason,
                    ]);
                } else {
                    $errors[] = "Telegram Bot API orqali foydalanuvchini mute qilib bo'lmadi";
                }
                break;

            case 'ban_user':
                $actionSuccess = $this->telegram->banChatMember($chatId, $userId);
                if ($actionSuccess) {
                    $noticeText = Translator::get('punishment.ban', $lang, [
                        'user_link' => $userLink,
                        'reason' => $safeReason,
                    ]);
                } else {
                    $errors[] = "Telegram Bot API orqali foydalanuvchini ban qilib bo'lmadi";
                }
                break;
        }

        $primarySuccess = $actionSuccess;
        if ($warningKey !== null) {
            $pdo->prepare("UPDATE user_warnings SET is_active = :active WHERE idempotency_key = :key")
                ->execute(['active' => $primarySuccess ? 1 : 0, 'key' => $warningKey]);
        }
        $actionSuccess = $primarySuccess && $deleteSuccess;
        $errorMessage = $errors === [] ? null : implode('; ', $errors);

        // 7. Moderatsiya guruhda mutlaqo jim ishlaydi. Sabab va shikoyat faqat foydalanuvchi lichkasiga boradi.
        if (!($decision['quiet'] ?? false) && !empty($noticeText) && $actionSuccess && in_array($action, ['mute_user', 'ban_user'], true)) {
            $appealMarkup = [
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => Translator::get('punishment.appeal_button', $lang), 'callback_data' => "appeal_request:{$actionRecordId}"]
                    ]]
                ]
            ];
            $privateNotice = $noticeText . Translator::get('punishment.appeal_hint', $lang, ['action_id' => $actionRecordId]);
            $privateResult = $this->telegram->sendMessage($userId, $privateNotice, $appealMarkup);
            if (!($privateResult['ok'] ?? false)) {
                Logger::warning("Shikoyat tugmasini foydalanuvchi lichkasiga yuborib bo'lmadi; foydalanuvchi avval botga /start yuborishi kerak", [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'action_id' => $actionRecordId,
                ], 'moderation');
            }
        }

        // 8. Admin Log guruhiga hisobot va bekor qilish (rollback) tugmalarini yuborish.
        // Ommaviy tozalash (audit) vaqtida har bir foydalanuvchi uchun alohida xabar
        // yuborilmaydi — job yakunda bitta umumiy hisobot beradi.
        if (!($decision['quiet'] ?? false)) {
            $this->notifyAdminLog(
                $chatId,
                $userId,
                $action,
                $reason,
                $decision['evidence'] ?? '',
                $actionSuccess,
                $findingId,
                $actionRecordId,
                $messageId,
                $deleteRequired && $deleteSuccess
            );
        }

        // 9. Natijani DB'da yangilash
        $status = $actionSuccess ? 'executed' : (($primarySuccess || ($deleteRequired && $deleteSuccess)) ? 'partial' : 'failed');
        $upd = $pdo->prepare("
            UPDATE telegram_actions
            SET status = :st, error_message = :err
            WHERE id = :id
        ");
        $upd->execute([
            'st' => $status,
            'err' => $errorMessage,
            'id' => $actionRecordId,
        ]);

        return [
            'status' => $status,
            'action' => $action,
            'success' => $actionSuccess,
            'error' => $errorMessage,
        ];
    }

    public function unban(int|string $chatId, int $userId): bool
    {
        $chatId = (int)$chatId;
        return $this->telegram->unbanChatMember($chatId, $userId);
    }

    public function unmute(int|string $chatId, int $userId): bool
    {
        $chatId = (int)$chatId;
        return $this->telegram->unmuteUser($chatId, $userId);
    }

    public function resetWarnings(int|string $chatId, int $userId): bool
    {
        $chatId = (int)$chatId;
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE user_warnings SET is_active = 0 WHERE chat_id = :cid AND user_id = :uid");
        return $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
    }

    /**
     * Ichki amal kodlarini adminга tushunarli o'zbekcha matnga aylantirish
     * (2.0 Phase 7). Avval xabarda "REVIEW_ONLY" kabi xom kodlar chiqardi.
     */
    private const ACTION_LABELS = [
        'warn_user' => "⚠️ Ogohlantirish berildi",
        'mute_user' => "🔇 Vaqtincha yozish taqiqlandi",
        'ban_user' => "⛔ Guruhdan chetlatildi",
        'delete_only' => "🗑 Xabar o'chirildi",
        'alert_only' => "👀 Chora ko'rilmadi — admin qaroriga qoldirildi",
        'review_only' => "👀 Chora ko'rilmadi — qo'lda ko'rib chiqish kerak",
    ];

    /**
     * Texnik/provayder atamalarini odamcha tushuntirishga almashtirish
     * (2.0 Phase 7). Masalan "PROHIBITED_CONTENT" admin uchun hech narsa
     * anglatmaydi.
     */
    private const REASON_HUMANIZE = [
        "Gemini xavfsizlik filtri javobni blokladi: PROHIBITED_CONTENT"
            => "AI kontentni tekshirishdan bosh tortdi (taqiqlangan kontent belgisi) — odatda 18+ yoki zo'ravonlik",
        "Gemini xavfsizlik filtri javobni blokladi: SAFETY"
            => "AI kontentni xavfli deb baholab, tahlil qilishdan bosh tortdi",
        "Gemini xavfsizlik filtri javobni blokladi: IMAGE_SAFETY"
            => "AI rasmni xavfli deb baholab, tahlil qilishdan bosh tortdi",
        "Gemini xavfsizlik filtri javobni blokladi: BLOCKLIST"
            => "AI provayderining taqiqlangan so'zlar ro'yxati ishga tushdi",
        "Gemini xavfsizlik filtri jinsiy kontentni aniqladi"
            => "AI jinsiy (18+) kontent aniqladi",
        'free_tier_limit_reached'
            => "bepul tarifning kunlik AI chegarasi tugagan",
        'budget_exhausted'
            => "AI byudjeti tugagan",
    ];

    private function humanizeReason(string $reason): string
    {
        foreach (self::REASON_HUMANIZE as $technical => $human) {
            if (str_contains($reason, $technical)) {
                $reason = str_replace($technical, $human, $reason);
            }
        }
        return $reason;
    }

    /** Guruh nomini olish (topilmasa ID bilan qaytaradi). */
    private function lookupGroupTitle(int $chatId): string
    {
        try {
            $stmt = Database::getConnection()->prepare("SELECT title FROM `groups` WHERE chat_id = :cid");
            $stmt->execute(['cid' => $chatId]);
            $title = (string)($stmt->fetchColumn() ?: '');
            return $title !== '' ? $title : "Guruh {$chatId}";
        } catch (Throwable) {
            return "Guruh {$chatId}";
        }
    }

    /** Foydalanuvchining ismi va @username'i (topilmasa ID). */
    private function lookupUserLabel(int $userId): string
    {
        try {
            $stmt = Database::getConnection()->prepare(
                "SELECT first_name, last_name, username FROM users WHERE user_id = :uid"
            );
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return (string)$userId;
            }
            $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));
            if ($name === '' && $username === '') {
                return (string)$userId;
            }
            if ($username !== '') {
                return $name !== '' ? "{$name} (@{$username})" : "@{$username}";
            }
            return $name;
        } catch (Throwable) {
            return (string)$userId;
        }
    }

    /** Qoidani buzgan xabarning matni (agar saqlangan bo'lsa). */
    private function lookupMessageText(int $chatId, ?int $messageId): ?string
    {
        if (($messageId ?? 0) <= 0) {
            return null;
        }
        try {
            $stmt = Database::getConnection()->prepare(
                "SELECT raw_text, media_type FROM messages WHERE chat_id = :cid AND message_id = :mid"
            );
            $stmt->execute(['cid' => $chatId, 'mid' => $messageId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $text = trim((string)($row['raw_text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
            // Matnsiz media uchun hech bo'lmasa turini ko'rsatamiz.
            $mediaType = (string)($row['media_type'] ?? '');
            return $mediaType !== '' && $mediaType !== 'text' ? "[{$mediaType}]" : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function notifyAdminLog(
        int $chatId,
        int $userId,
        string $action,
        string $reason,
        string $evidence,
        bool $success,
        ?int $findingId,
        int $actionId,
        ?int $messageId,
        bool $messageAlreadyDeleted = false
    ): void {
        // 2.0 Phase 7: admin xabari avval faqat raqamlardan iborat edi
        // ("Guruh ID: -100...", "Foydalanuvchi: 8412100749", "Amal: REVIEW_ONLY")
        // — admin qaysi guruh, kim va nima yozganini umuman tushunolmasdi.
        // Endi nomlar, xabar matni va odamcha tushuntirish ko'rsatiladi.
        $groupTitle = $this->lookupGroupTitle($chatId);
        $userLabel = $this->lookupUserLabel($userId);
        $messageText = $this->lookupMessageText($chatId, $messageId);

        $actionLabel = self::ACTION_LABELS[$action] ?? strtoupper($action);
        $statusSuffix = $success ? '' : " — ❌ <b>bajarib bo'lmadi</b>";

        $logText = "🛡 <b>Moderatsiya harakati</b>\n\n"
            . "👥 <b>Guruh:</b> " . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . "\n"
            . "👤 <b>Kim:</b> <a href=\"tg://user?id={$userId}\">"
                . htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') . "</a>\n"
            . "⚖️ <b>Nima qilindi:</b> {$actionLabel}{$statusSuffix}\n"
            . "📋 <b>Nima uchun:</b> " . htmlspecialchars($this->humanizeReason($reason), ENT_QUOTES, 'UTF-8') . "\n";

        if ($messageText !== null && trim($messageText) !== '') {
            $snippet = mb_substr(trim($messageText), 0, 400);
            if (mb_strlen(trim($messageText)) > 400) {
                $snippet .= '…';
            }
            $logText .= "\n💬 <b>Xabar matni:</b>\n<blockquote>"
                . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . "</blockquote>\n";
        }

        if (!empty($evidence)) {
            $logText .= "\n🔍 <b>Dalil:</b> <code>"
                . htmlspecialchars(mb_substr($evidence, 0, 150), ENT_QUOTES, 'UTF-8') . "</code>\n";
        }

        // Texnik ma'lumotlar — kerak bo'lganda qidirish uchun, lekin eng oxirida
        // va kichik shriftda, asosiy mazmunni to'sib qo'ymasin.
        $logText .= "\n<i>#" . $actionId
            . " · guruh <code>{$chatId}</code>"
            . (($messageId ?? 0) > 0 ? " · xabar <code>{$messageId}</code>" : '')
            . " · foydalanuvchi <code>{$userId}</code></i>";

        // Rollback tugmalari
        $buttons = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Unmute / Bekor', 'callback_data' => "rb_unmute:{$chatId}:{$userId}"],
                    ['text' => '🔓 Unban', 'callback_data' => "rb_unban:{$chatId}:{$userId}"],
                ],
                [
                    ['text' => '✅ Oq ro\'yxatga qo\'shish', 'callback_data' => "rb_whitelist:{$chatId}:{$userId}"],
                    ['text' => '⚠️ Noto\'g\'ri topilma', 'callback_data' => "rb_false_pos:{$chatId}:" . ($findingId ?: 0)],
                ]
            ]
        ];

        // Tezkor amallar: AI/mahalliy qoida yakuniy hal qila olmagan yoki xabar hali
        // o'chirilmagan hollarda admin bitta tugma bilan qo'lda hal qilishi uchun.
        $quickRow = [];
        if (($messageId ?? 0) > 0 && !$messageAlreadyDeleted) {
            $quickRow[] = ['text' => "🗑 Xabarni o'chirish", 'callback_data' => "rb_delmsg:{$chatId}:{$messageId}"];
        }
        if ($action !== 'ban_user') {
            $quickRow[] = ['text' => '🚫 Ban qilish', 'callback_data' => "rb_ban:{$chatId}:{$userId}"];
        }
        if ($quickRow !== []) {
            array_unshift($buttons['inline_keyboard'], $quickRow);
        }

        $this->adminNotifier->send($chatId, $logText, ['reply_markup' => $buttons]);
    }
}
