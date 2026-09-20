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
     * Foydalanuvchi ADMIN yoki EGASI bo'lgan barcha faol guruhlar ro'yxati.
     * Platforma egasi (`TELEGRAM_OWNER_IDS`) uchun — barcha faol guruhlar.
     * `UpdateRouter::adminGroupsOf()` (shaxsiy DM buyruqlari) va Mini App
     * REST API (`MiniAppApiRouter::me()`) ikkalasi ham shu YAGONA joydan
     * foydalanadi — dublikat SQL yo'q (2.0 Phase 3, 3-band).
     *
     * @return array<int, array{chat_id: int|string, title: ?string}>
     */
    public static function adminGroupsOfUser(int $userId): array
    {
        $pdo = Database::getConnection();
        if ($userId > 0 && in_array($userId, AdminNotificationService::ownerIds(), true)) {
            $stmt = $pdo->prepare("SELECT chat_id, title FROM `groups` WHERE is_active = 1 ORDER BY updated_at DESC");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT c.chat_id, g.title
                FROM chat_members c
                LEFT JOIN `groups` g ON g.chat_id = c.chat_id
                WHERE c.user_id = :uid AND c.role IN ('creator', 'administrator')
                  AND (g.is_active = 1 OR g.is_active IS NULL)
                ORDER BY g.updated_at DESC
            ");
            $stmt->execute(['uid' => $userId]);
        }
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Botning ICHKI "moderator" roli — Telegram'ning o'z admin/creator statusidan
     * mustaqil, faqat botning cheklangan huquqli buyruqlariga (masalan /warn, /mute)
     * ruxsat beradigan, admin tomonidan qo'lda belgilanadigan rol (`chat_members.bot_role`).
     * Bu ustun `isAdmin()`ning Telegram-sinxronizatsiyasiga umuman aloqador emas.
     */
    public function isModerator(int|string $chatId, int $userId): bool
    {
        $chatId = (int)$chatId;
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = Database::getConnection()->prepare("
                SELECT bot_role FROM chat_members WHERE chat_id = :cid AND user_id = :uid
            ");
            $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
            return (string)($stmt->fetchColumn() ?: 'none') === 'moderator';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * To'liq admin (creator/administrator/bot egasi) YOKI botning ichki moderator
     * roliga ega foydalanuvchi uchun true qaytaradi. Moderator kengaytmagan
     * (cheklangan) buyruqlar to'plamiga ruxsat berish uchun ishlatiladi — chaqiruvchi
     * darajada aynan qaysi buyruq ruxsat etilganini alohida tekshirishi kerak.
     */
    public function hasModeratorPrivileges(int|string $chatId, int $userId, ?array $senderChat = null): bool
    {
        return $this->isAdmin($chatId, $userId, $senderChat) || $this->isModerator($chatId, $userId);
    }

    /**
     * Guruh admini tomonidan boshqa a'zoga botning ichki "moderator" rolini
     * belgilash/bekor qilish. `chat_members` qatori hali mavjud bo'lmasa (masalan,
     * foydalanuvchi hali hech qachon sinxronlanmagan), xavfsiz standart qiymatlar
     * bilan yangi qator yaratiladi — `role` keyinchalik `isAdmin()` sinxronizatsiyasi
     * orqali to'g'irlanadi.
     */
    public static function setModeratorRole(int $chatId, int $userId, bool $isModerator): void
    {
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $botRole = $isModerator ? 'moderator' : 'none';

        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO chat_members (chat_id, user_id, role, bot_role, updated_at)
                VALUES (:cid, :uid, 'member', :br, :now)
                ON CONFLICT(chat_id, user_id) DO UPDATE SET bot_role = excluded.bot_role, updated_at = excluded.updated_at
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO chat_members (chat_id, user_id, role, bot_role, updated_at)
                VALUES (:cid, :uid, 'member', :br, :now)
                ON DUPLICATE KEY UPDATE bot_role = VALUES(bot_role), updated_at = VALUES(updated_at)
            ");
        }
        $stmt->execute(['cid' => $chatId, 'uid' => $userId, 'br' => $botRole, 'now' => $now]);
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
