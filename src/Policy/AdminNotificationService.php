<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use Throwable;

/**
 * Moderatsiya hisobotlarini guruhdan tashqaridagi ishonchli adminlarga yetkazadi.
 */
class AdminNotificationService
{
    public function __construct(private ?TelegramClient $telegram = null)
    {
        $this->telegram ??= new TelegramClient();
    }

    /** @return int[] */
    public static function ownerIds(): array
    {
        $raw = (string)Config::get('TELEGRAM_OWNER_IDS', '');
        $ids = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /** @return int[] */
    public function destinations(int $chatId): array
    {
        $destinations = self::ownerIds();
        $settings = SettingsService::get($chatId);
        $logChatId = (int)($settings['log_chat_id'] ?? Config::get('TELEGRAM_ADMIN_LOG_CHAT_ID', 0));
        if ($logChatId !== 0) {
            $destinations[] = $logChatId;
        } else {
            // Maxsus log chat belgilanmagan bo'lsa, Telegram rate-limit (429) xatolarini oldini olish
            // uchun faqat guruh egasi va eng oxirgi faol adminlarga (maksimum 3 ta) yo'naltiriladi.
            try {
                $stmt = Database::getConnection()->prepare("
                    SELECT c.user_id
                    FROM chat_members c
                    LEFT JOIN users u ON u.user_id = c.user_id
                    WHERE c.chat_id = :cid
                      AND c.role IN ('creator', 'administrator')
                      AND COALESCE(u.is_bot, 0) = 0
                    ORDER BY CASE WHEN c.role = 'creator' THEN 0 ELSE 1 END, c.updated_at DESC
                    LIMIT 3
                ");
                $stmt->execute(['cid' => $chatId]);
                foreach ($stmt->fetchAll() as $row) {
                    $adminId = (int)($row['user_id'] ?? 0);
                    if ($adminId > 0) {
                        $destinations[] = $adminId;
                    }
                }
            } catch (Throwable $e) {
                Logger::warning("Admin xabarnomasi manzillarini olishda xato: " . $e->getMessage(), ['chat_id' => $chatId], 'moderation');
            }
        }

        return array_values(array_unique(array_filter($destinations, static fn (int $id): bool => $id !== 0 && $id !== $chatId)));
    }

    public function send(int $chatId, string $text, array $extra = []): int
    {
        $sent = 0;
        foreach ($this->destinations($chatId) as $destination) {
            $result = $this->telegram->sendMessage($destination, $text, $extra);
            if ($result['ok'] ?? false) {
                $sent++;
            }
        }
        return $sent;
    }
}
