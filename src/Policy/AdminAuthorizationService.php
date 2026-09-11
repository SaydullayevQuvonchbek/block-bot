<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use Throwable;

class AdminAuthorizationService
{
    private TelegramClient $telegram;
    private static array $memoryCache = [];

    public function __construct(?TelegramClient $telegram = null)
    {
        $this->telegram = $telegram ?? new TelegramClient();
    }

    /**
     * Foydalanuvchining aynan shu guruhda admin yoki guruh egasi ekanini tekshirish
     */
    public function isAdmin(int|string $chatId, int $userId, ?array $senderChat = null): bool
    {
        $chatId = (int)$chatId;

        // 1. Anonim admin tekshiruvi (sender_chat guruhning o'zi bo'lsa)
        if ($senderChat !== null && isset($senderChat['id']) && (int)$senderChat['id'] === $chatId) {
            return true;
        }

        if ($userId <= 0) {
            return false;
        }

        // Bot egasi barcha botga ulangan guruhlarni markazdan boshqara oladi.
        if ($this->isSystemAdmin($userId)) {
            return true;
        }

        $cacheKey = "{$chatId}_{$userId}";
        if (isset(self::$memoryCache[$cacheKey]) && self::$memoryCache[$cacheKey]['expires_at'] > time()) {
            return self::$memoryCache[$cacheKey]['value'];
        }

        // 2. DB keshini tekshirish (oxirgi 5 daqiqa)
        $pdo = Database::getConnection();
        $fiveMinAgo = gmdate('Y-m-d H:i:s', time() - 300);

        try {
            $stmt = $pdo->prepare("
                SELECT role, updated_at
                FROM chat_members
                WHERE chat_id = :cid AND user_id = :uid
            ");
            $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $stmt->fetch();

            if ($row && $row['updated_at'] >= $fiveMinAgo) {
                $isAdmin = in_array($row['role'], ['creator', 'administrator'], true);
                self::$memoryCache[$cacheKey] = ['value' => $isAdmin, 'expires_at' => time() + 300];
                return $isAdmin;
            }
        } catch (Throwable) {
        }

        // 3. Telegram Bot API orqali haqiqiy statusni so'rash
        try {
            $member = $this->telegram->getChatMember($chatId, $userId);
            if ($member) {
                $status = $member['status'] ?? 'member';
                $isAdmin = in_array($status, ['creator', 'administrator'], true);

                // DB'ga keshlab qo'yish
                $now = gmdate('Y-m-d H:i:s');
                if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $saveStmt = $pdo->prepare("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES (:cid, :uid, :role, :now) ON CONFLICT(chat_id, user_id) DO UPDATE SET role = excluded.role, updated_at = excluded.updated_at");
                } else {
                    $saveStmt = $pdo->prepare("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES (:cid, :uid, :role, :now) ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = VALUES(updated_at)");
                }
                $saveStmt->execute([
                    'cid' => $chatId,
                    'uid' => $userId,
                    'role' => $status,
                    'now' => $now,
                ]);

                self::$memoryCache[$cacheKey] = ['value' => $isAdmin, 'expires_at' => time() + 300];
                return $isAdmin;
            }
        } catch (Throwable $e) {
            Logger::error("Admin tekshiruvida xato [chat: {$chatId}, user: {$userId}]: " . $e->getMessage(), [], 'telegram');
        }

        // API vaqtincha ishlamasa noto'g'ri natijani uzoq saqlamaymiz.
        self::$memoryCache[$cacheKey] = ['value' => false, 'expires_at' => time() + 30];
        return false;
    }

    public function isSystemAdmin(int $userId): bool
    {
        return $userId > 0 && in_array($userId, AdminNotificationService::ownerIds(), true);
    }

    public static function forget(int $chatId, int $userId): void
    {
        unset(self::$memoryCache["{$chatId}_{$userId}"]);
    }

    /**
     * Foydalanuvchi oq ro'yxatda (whitelist) bor-yo'qligini tekshirish
     */
    public function isWhitelisted(int|string $chatId, int $userId): bool
    {
        $chatId = (int)$chatId;
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                SELECT is_whitelisted, is_admin_exempt
                FROM chat_members
                WHERE chat_id = :cid AND user_id = :uid
            ");
            $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $stmt->fetch();

            return (bool)($row['is_whitelisted'] ?? false) || (bool)($row['is_admin_exempt'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }
}
