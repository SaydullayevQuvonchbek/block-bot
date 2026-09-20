<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Guruhda xabar tezligini (flood / spam-portlash) kuzatuvchi sodda
 * "fixed window" hisoblagich. Har bir chat+user juftligi uchun bitta qator
 * saqlanadi (`flood_counters`); oyna (window) muddati tugagach hisoblagich
 * avtomatik nolga tushadi.
 *
 * Ataylab AI yoki tashqi xizmatga bog'liq emas — webhook javobini
 * bloklamaydigan darajada tez (bitta SELECT + bitta INSERT/UPDATE), aynan
 * TextModerator'ning "mahalliy/deterministik" tekshiruvlari singari
 * webhook'ning sinxron (phase=local) bosqichida ham xavfsiz chaqirish
 * mumkin.
 */
class FloodGuard
{
    /**
     * Yangi xabarni hisoblagichga qo'shish va chegaradan oshganini tekshirish.
     *
     * @return array{flooding: bool, count: int}
     */
    public static function register(int $chatId, int $userId, int $windowSeconds, int $maxMessages): array
    {
        if ($userId <= 0 || $windowSeconds <= 0 || $maxMessages <= 0) {
            return ['flooding' => false, 'count' => 0];
        }

        try {
            $pdo = Database::getConnection();
            $now = time();
            $nowStr = gmdate('Y-m-d H:i:s', $now);

            $select = $pdo->prepare("SELECT window_start, message_count FROM flood_counters WHERE chat_id = :cid AND user_id = :uid");
            $select->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $select->fetch();

            if ($row === false) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO flood_counters (chat_id, user_id, window_start, message_count, updated_at)
                        VALUES (:cid, :uid, :ws, 1, :now)
                    ");
                    $ins->execute(['cid' => $chatId, 'uid' => $userId, 'ws' => $nowStr, 'now' => $nowStr]);
                } catch (Throwable) {
                    // Parallel so'rov bir vaqtda qator yaratgan bo'lishi mumkin — xavfsiz e'tibor bermaslik.
                }
                return ['flooding' => false, 'count' => 1];
            }

            $windowStart = strtotime((string)$row['window_start']) ?: $now;
            $elapsed = $now - $windowStart;

            if ($elapsed >= $windowSeconds) {
                // Oyna muddati tugagan — hisoblagichni yangi oyna bilan qayta boshlash.
                $pdo->prepare("
                    UPDATE flood_counters SET window_start = :ws, message_count = 1, updated_at = :now
                    WHERE chat_id = :cid AND user_id = :uid
                ")->execute(['ws' => $nowStr, 'now' => $nowStr, 'cid' => $chatId, 'uid' => $userId]);
                return ['flooding' => false, 'count' => 1];
            }

            $newCount = (int)$row['message_count'] + 1;

            if ($newCount > $maxMessages) {
                // Chegaradan oshdi: jazo darhol qo'llanadi (chaqiruvchi tomonidan), takroriy
                // tetiklanmasligi uchun oynani shu yerdayoq qayta boshlaymiz.
                $pdo->prepare("
                    UPDATE flood_counters SET window_start = :ws, message_count = 0, updated_at = :now
                    WHERE chat_id = :cid AND user_id = :uid
                ")->execute(['ws' => $nowStr, 'now' => $nowStr, 'cid' => $chatId, 'uid' => $userId]);
                return ['flooding' => true, 'count' => $newCount];
            }

            $pdo->prepare("
                UPDATE flood_counters SET message_count = :cnt, updated_at = :now
                WHERE chat_id = :cid AND user_id = :uid
            ")->execute(['cnt' => $newCount, 'now' => $nowStr, 'cid' => $chatId, 'uid' => $userId]);

            return ['flooding' => false, 'count' => $newCount];
        } catch (Throwable $e) {
            Logger::error("FloodGuard xatosi: " . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
            // Xatolik yuz bersa — xavfsizroq tomoni: flood deb hisoblamaslik (oddiy xabarni bloklamaslik).
            return ['flooding' => false, 'count' => 0];
        }
    }
}
