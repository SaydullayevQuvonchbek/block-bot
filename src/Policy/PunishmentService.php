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
        $statusEmoji = $success ? '✅' : '❌ Xatolik yuz berdi';
        $logText = "🛡 <b>Moderatsiya Harakati:</b>\n"
            . "• Guruh ID: <code>{$chatId}</code>\n"
            . "• Foydalanuvchi: <a href=\"tg://user?id={$userId}\">{$userId}</a>\n"
            . "• Harakat ID: <code>{$actionId}</code>\n"
            . (($messageId ?? 0) > 0 ? "• Xabar ID: <code>{$messageId}</code>\n" : '')
            . "• Amal: <b>" . strtoupper($action) . "</b> ({$statusEmoji})\n"
            . "• Sabab: " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "\n";

        if (!empty($evidence)) {
            $logText .= "• Dalil: <code>" . htmlspecialchars(mb_substr($evidence, 0, 100), ENT_QUOTES, 'UTF-8') . "</code>\n";
        }

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
