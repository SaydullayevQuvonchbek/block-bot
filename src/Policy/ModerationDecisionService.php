<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Database;
use App\Moderation\ProfileModerator;
use PDO;
use Throwable;

class ModerationDecisionService
{
    /**
     * Moderatsiya natijasidan kelib chiqib tegishli jazo va harakatni belgilash
     */
    public static function decide(
        array $finding,
        int|string $chatId,
        int $userId,
        ?int $messageId = null,
        ?array $settings = null
    ): array {
        $chatId = (int)$chatId;
        $settings = $settings ?? SettingsService::get($chatId);
        $status = $finding['status'] ?? 'safe';
        $category = $finding['category'] ?? 'none';
        $reason = $finding['reason'] ?? '';
        $evidence = $finding['evidence'] ?? '';

        if ($status === 'safe') {
            return [
                'action' => 'none',
                'delete_message' => false,
                'notify_admin' => false,
                'reason' => 'Xavfsiz kontent',
            ];
        }

        if ($status === 'unscannable') {
            $unscannableAction = $settings['unscannable_action'] ?? 'leave_alert';
            return [
                'action' => $unscannableAction === 'delete_notify' ? 'delete_only' : 'alert_only',
                'delete_message' => ($unscannableAction === 'delete_notify'),
                'notify_admin' => true,
                'reason' => "Tekshirib bo'lmagan media: {$reason}",
                'evidence' => $evidence,
                'unscannable' => true,
            ];
        }

        if ($status === 'review') {
            return [
                'action' => 'review_only',
                'delete_message' => false,
                'notify_admin' => true,
                'reason' => "Qo'lda ko'rib chiqish talab etiladi: {$reason}",
                'evidence' => $evidence,
            ];
        }

        // 18+ profil va ruxsatsiz bot akkauntlar: bosqichma-bosqich emas, akkaunt darajasidagi
        // chora. Standart siyosat — mute qilib, adminга yakuniy "Ban" tugmasini yuborish.
        $isProfileSource = str_starts_with((string)($finding['source'] ?? ''), 'profile_');
        // 2.0 Phase 6: profil tekshiruvida topilgan reklama/skam akkauntlar
        // (kripto-"treyder" signallari, investitsiya/kazino targ'iboti, referal
        // spam, reklama tarqatuvchi "chat-bot" akkauntlari) ham akkaunt
        // darajasidagi choraga olib keladi — lekin ALOHIDA sozlama orqali
        // (`spam_account_action`), chunki bu 18+ akkauntdan boshqa toifa va
        // admin ular uchun boshqacha qattiqlik tanlashi mumkin.
        $isPromoAccount = $isProfileSource && in_array($category, ProfileModerator::PROMO_CATEGORIES, true);
        if ($category === 'adult_profile' || $category === 'bot_account'
            || ($category === 'pornography' && $isProfileSource) || $isPromoAccount) {
            $accountAction = $isPromoAccount
                ? (string)($settings['spam_account_action'] ?? 'mute_notify')
                : (string)($settings['adult_account_action'] ?? 'mute_notify');
            $label = match (true) {
                $category === 'bot_account' => "Ruxsatsiz bot akkaunt",
                $isPromoAccount => "Reklama/skam akkaunt",
                default => "18+ profil akkaunt",
            };
            $deleteMessage = $messageId !== null && $messageId > 0;

            if ($accountAction === 'ban') {
                return [
                    'action' => 'ban_user',
                    'delete_message' => $deleteMessage,
                    'notify_admin' => true,
                    'reason' => "{$label}: {$reason}",
                    'evidence' => $evidence,
                    'strike_count' => 99,
                ];
            }

            if ($accountAction === 'notify') {
                return [
                    'action' => 'alert_only',
                    'delete_message' => $deleteMessage,
                    'notify_admin' => true,
                    'reason' => "{$label}: {$reason}",
                    'evidence' => $evidence,
                    'strike_count' => 99,
                    'admin_confirm_ban' => true,
                ];
            }

            return [
                'action' => 'mute_user',
                'delete_message' => $deleteMessage,
                'notify_admin' => true,
                'mute_duration_sec' => 86400 * 30,
                'reason' => "{$label}: {$reason}",
                'evidence' => $evidence,
                'strike_count' => 99,
                'admin_confirm_ban' => true,
            ];
        }

        // Status === 'unsafe'
        // Matndagi pornografik so'z uchun darhol ban emas: mute -> mute -> ban.
        $source = (string)($finding['source'] ?? '');
        $isVisualOrProfile = str_starts_with($source, 'ai_vision') || str_starts_with($source, 'profile_');
        if ($category === 'pornography' && !$isVisualOrProfile) {
            $activeWarnsCount = self::getActiveWarningsCount($chatId, $userId, (int)($settings['warn_duration_days'] ?? 30));
            $newStrike = $activeWarnsCount + 1;
            $warnLimit = max(1, (int)($settings['warn_limit'] ?? 3));
            $warningDurationDays = max(1, (int)($settings['warn_duration_days'] ?? 30));

            if ($newStrike > $warnLimit) {
                return [
                    'action' => 'ban_user',
                    'delete_message' => true,
                    'notify_admin' => true,
                    'reason' => "Takroriy pornografik matn sababli guruhdan chetlatish: {$reason}",
                    'evidence' => $evidence,
                    'strike_count' => $newStrike,
                    'warn_limit' => $warnLimit,
                    'warning_duration_days' => $warningDurationDays,
                ];
            }

            $duration = $newStrike === 1
                ? (int)($settings['mute_1st_duration_sec'] ?? 3600)
                : (int)($settings['mute_2nd_duration_sec'] ?? 86400);
            return [
                'action' => 'mute_user',
                'delete_message' => true,
                'notify_admin' => true,
                'mute_duration_sec' => $duration,
                'reason' => "Pornografik matn ({$newStrike}-qoidabuzarlik): {$reason}",
                'evidence' => $evidence,
                'strike_count' => $newStrike,
                'warn_limit' => $warnLimit,
                'warning_duration_days' => $warningDurationDays,
            ];
        }

        // Ochiq pornografik media va 18+ profil uchun maxsus qat'iy qoida.
        if ($category === 'pornography' || $category === 'adult_profile') {
            $pornAction = $settings['porn_action'] ?? 'ban';
            return [
                'action' => match ($pornAction) {
                    'ban' => 'ban_user',
                    'mute' => 'mute_user',
                    default => 'warn_user',
                },
                'delete_message' => true,
                'notify_admin' => true,
                'mute_duration_sec' => 86400 * 7, // 7 kun mute
                'reason' => ($category === 'adult_profile' ? "18+ profil: " : "Ochiq pornografik media: ") . $reason,
                'evidence' => $evidence,
                'strike_count' => 99,
            ];
        }

        // Bosqichma-bosqich jazolash tizimi (ogohlantirishlar bo'yicha)
        $activeWarnsCount = self::getActiveWarningsCount($chatId, $userId, (int)($settings['warn_duration_days'] ?? 30));
        $newStrike = $activeWarnsCount + 1;
        $warnLimit = max(1, (int)($settings['warn_limit'] ?? 3));
        $warningDurationDays = max(1, (int)($settings['warn_duration_days'] ?? 30));

        if ($newStrike === 1) {
            return [
                'action' => 'warn_user',
                'delete_message' => true,
                'notify_admin' => true,
                'reason' => "1-ogohlantirish: {$reason}",
                'evidence' => $evidence,
                'strike_count' => 1,
                'warn_limit' => $warnLimit,
                'warning_duration_days' => $warningDurationDays,
            ];
        }

        if ($newStrike > $warnLimit) {
            return [
                'action' => 'ban_user',
                'delete_message' => true,
                'notify_admin' => true,
                'reason' => "Takroriy qoidabuzarliklar sababli guruhdan chetlatish (BAN): {$reason}",
                'evidence' => $evidence,
                'strike_count' => $newStrike,
                'warn_limit' => $warnLimit,
                'warning_duration_days' => $warningDurationDays,
            ];
        }

        if ($newStrike === 2) {
            $duration = (int)($settings['mute_1st_duration_sec'] ?? 3600);
            return [
                'action' => 'mute_user',
                'delete_message' => true,
                'notify_admin' => true,
                'mute_duration_sec' => $duration,
                'reason' => "2-qoidabuzarlik (1 soat mute): {$reason}",
                'evidence' => $evidence,
                'strike_count' => 2,
                'warn_limit' => $warnLimit,
                'warning_duration_days' => $warningDurationDays,
            ];
        }

        if ($newStrike <= $warnLimit) {
            $duration = (int)($settings['mute_2nd_duration_sec'] ?? 86400);
            return [
                'action' => 'mute_user',
                'delete_message' => true,
                'notify_admin' => true,
                'mute_duration_sec' => $duration,
                'reason' => "3-qoidabuzarlik (24 soat mute): {$reason}",
                'evidence' => $evidence,
                'strike_count' => $newStrike,
                'warn_limit' => $warnLimit,
                'warning_duration_days' => $warningDurationDays,
            ];
        }

        // Himoya fallbacki; odatda yuqoridagi warn_limit tekshiruvi ishlaydi.
        return [
            'action' => 'ban_user',
            'delete_message' => true,
            'notify_admin' => true,
            'reason' => "Takroriy qoidabuzarliklar sababli guruhdan chetlatish (BAN): {$reason}",
            'evidence' => $evidence,
            'strike_count' => $newStrike,
            'warn_limit' => $warnLimit,
            'warning_duration_days' => $warningDurationDays,
        ];
    }

    public static function getActiveWarningsCount(int $chatId, int $userId, int $durationDays = 30): int
    {
        try {
            $pdo = Database::getConnection();
            $since = gmdate('Y-m-d H:i:s', time() - ($durationDays * 86400));
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM user_warnings
                WHERE chat_id = :cid
                  AND user_id = :uid
                  AND is_active = 1
                  AND created_at >= :since
                  AND expires_at > :now
            ");
            $stmt->execute([
                'cid' => $chatId,
                'uid' => $userId,
                'since' => $since,
                'now' => gmdate('Y-m-d H:i:s'),
            ]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
