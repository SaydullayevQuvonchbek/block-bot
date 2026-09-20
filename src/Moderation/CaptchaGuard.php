<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Yangi a'zolar uchun CAPTCHA/tasdiqlash holatini kuzatuvchi mahalliy hisoblagich
 * (`captcha_pending` jadvali). Bu klass Telegram bilan bevosita ishlamaydi — faqat
 * "kim, qaysi guruhda, qaysi xabar (tugma) orqali, qachongacha tasdiqlashi kerak"
 * holatini saqlaydi. Telegram amallari (cheklash, xabar yuborish, kick qilish)
 * chaqiruvchi tomon (UpdateRouter / CaptchaTimeoutJob) tomonidan bajariladi.
 */
class CaptchaGuard
{
    /**
     * Yangi a'zo uchun tasdiqlash kutilayotganini ro'yxatga olish. Agar shu
     * chat+user uchun eski (masalan, avval chiqib ketib qayta qo'shilgan) yozuv
     * bo'lsa — ustidan yoziladi (upsert).
     */
    public static function start(int $chatId, int $userId, int $messageId, int $timeoutSeconds): void
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, $timeoutSeconds));

            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $sql = "
                    INSERT INTO captcha_pending (chat_id, user_id, message_id, status, expires_at, created_at, updated_at)
                    VALUES (:cid, :uid, :mid, 'pending', :exp, :now, :now)
                    ON CONFLICT(chat_id, user_id) DO UPDATE SET
                        message_id = excluded.message_id,
                        status = 'pending',
                        expires_at = excluded.expires_at,
                        updated_at = excluded.updated_at
                ";
            } else {
                $sql = "
                    INSERT INTO captcha_pending (chat_id, user_id, message_id, status, expires_at, created_at, updated_at)
                    VALUES (:cid, :uid, :mid, 'pending', :exp, :now, :now)
                    ON DUPLICATE KEY UPDATE
                        message_id = VALUES(message_id),
                        status = 'pending',
                        expires_at = VALUES(expires_at),
                        updated_at = VALUES(updated_at)
                ";
            }

            $pdo->prepare($sql)->execute([
                'cid' => $chatId,
                'uid' => $userId,
                'mid' => $messageId,
                'exp' => $expiresAt,
                'now' => $now,
            ]);
        } catch (Throwable $e) {
            Logger::error("CaptchaGuard::start xatosi: " . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
        }
    }

    /**
     * Foydalanuvchi "✅ Men botman emas" tugmasini bosganda chaqiriladi. Faqat
     * hali "pending" holatidagi yozuvni "verified"ga o'tkazadi (atomik —
     * CaptchaTimeoutJob bilan poyga holatidan xoli: kim UPDATE'ni birinchi
     * bajarsa, o'sha g'olib).
     *
     * @return array{message_id:int}|null Muvaffaqiyatli tasdiqlansa — xabar ID'si; aks holda null.
     */
    public static function verify(int $chatId, int $userId): ?array
    {
        try {
            $pdo = Database::getConnection();
            $select = $pdo->prepare("SELECT message_id FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid AND status = 'pending'");
            $select->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $select->fetch();
            if ($row === false) {
                return null;
            }

            $now = gmdate('Y-m-d H:i:s');
            $update = $pdo->prepare("
                UPDATE captcha_pending SET status = 'verified', updated_at = :now
                WHERE chat_id = :cid AND user_id = :uid AND status = 'pending'
            ");
            $update->execute(['now' => $now, 'cid' => $chatId, 'uid' => $userId]);

            if ($update->rowCount() === 0) {
                // Ayni shu lahzada CaptchaTimeoutJob allaqachon "kicked" deb belgilagan bo'lishi mumkin.
                return null;
            }

            return ['message_id' => (int)$row['message_id']];
        } catch (Throwable $e) {
            Logger::error("CaptchaGuard::verify xatosi: " . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
            return null;
        }
    }

    /**
     * CaptchaTimeoutJob tomonidan chaqiriladi: agar foydalanuvchi hali ham
     * "pending" holatida bo'lsa (ya'ni vaqtida tasdiqlamagan) — "kicked"ga
     * o'tkazadi va xabar ID'sini qaytaradi (chaqiruvchi Telegram orqali
     * chetlatish va xabarni yangilashni bajaradi). Agar allaqachon tasdiqlangan
     * bo'lsa (yoki yozuv topilmasa) — null qaytaradi (hech narsa qilinmaydi).
     *
     * @return array{message_id:int}|null
     */
    public static function resolveTimeout(int $chatId, int $userId): ?array
    {
        try {
            $pdo = Database::getConnection();
            $select = $pdo->prepare("SELECT message_id FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid AND status = 'pending'");
            $select->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $select->fetch();
            if ($row === false) {
                return null;
            }

            $now = gmdate('Y-m-d H:i:s');
            $update = $pdo->prepare("
                UPDATE captcha_pending SET status = 'kicked', updated_at = :now
                WHERE chat_id = :cid AND user_id = :uid AND status = 'pending'
            ");
            $update->execute(['now' => $now, 'cid' => $chatId, 'uid' => $userId]);

            if ($update->rowCount() === 0) {
                // Ayni shu lahzada foydalanuvchi allaqachon tugmani bosgan bo'lishi mumkin.
                return null;
            }

            return ['message_id' => (int)$row['message_id']];
        } catch (Throwable $e) {
            Logger::error("CaptchaGuard::resolveTimeout xatosi: " . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
            return null;
        }
    }
}
