<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use Throwable;

class ProfileModerator
{
    /**
     * 18+ / so'kinishdan TASHQARI, akkaunt darajasida chora ko'rishga arziydigan
     * kategoriyalar (2.0 Phase 6). AI allaqachon shu kodlarni qaytaradi
     * (`OpenRouterClient`/`GeminiClient` promptidagi kategoriyalar ro'yxati),
     * lekin avval profil tekshiruvida ular E'TIBORSIZ qoldirilardi — faqat
     * `pornography`/`adult_profile`/`profanity` hisobga olinardi. Amalda esa
     * guruhlarga eng ko'p shu turdagi akkauntlar kiradi: kripto-"treyder"
     * signal sotuvchilar, investitsiya/kazino targ'ibotchilari, referal spam
     * tarqatuvchilar va reklama qiluvchi "chat-bot" akkauntlari.
     */
    public const PROMO_CATEGORIES = [
        'trading_scam',
        'spam_ad',
        'gambling',
        'malicious_link',
        'apk_distribution',
    ];

    /** Profil ismi/username va bio tekshiruvida e'tiborga olinadigan barcha kategoriyalar. */
    private const PROFILE_TEXT_CATEGORIES = [
        'pornography',
        'adult_profile',
        'profanity',
        'trading_scam',
        'spam_ad',
        'gambling',
        'malicious_link',
        'apk_distribution',
    ];

    private TelegramClient $telegram;
    private TextModerator $textModerator;
    private MediaModerator $mediaModerator;

    public function __construct(
        ?TelegramClient $telegram = null,
        ?TextModerator $textModerator = null,
        ?MediaModerator $mediaModerator = null
    ) {
        $this->telegram = $telegram ?? new TelegramClient();
        $this->textModerator = $textModerator ?? new TextModerator();
        $this->mediaModerator = $mediaModerator ?? new MediaModerator();
    }

    /**
     * Foydalanuvchi profilini tekshirish (ism, familiya, username, profil rasmi)
     */
    public function inspectUser(array $user, ?int $chatId = null, bool $force = false, bool $checkBot = true): array
    {
        $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['status' => 'safe', 'reason' => 'Yaroqsiz user ID'];
        }

        $this->upsertUser($userId, $user);

        // 0. Ruxsatsiz bot akkaunt tekshiruvi (adminlar qo'shgan botlar istisno).
        $isBot = !empty($user['is_bot']);
        if (!$isBot && $chatId !== null) {
            $isBot = $this->isKnownBot($userId);
        }
        if ($checkBot && $isBot && $chatId !== null) {
            if ($this->isChatAdminOrWhitelisted((int)$chatId, $userId)) {
                return [
                    'status' => 'safe',
                    'category' => 'none',
                    'reason' => 'Admin tomonidan ruxsat etilgan bot akkaunt',
                    'evidence' => '',
                    'source' => 'profile_scan',
                ];
            }
            $this->updateUserStatus($userId, 'unsafe');
            $handle = !empty($user['username']) ? '@' . $user['username'] : (string)$userId;
            return [
                'status' => 'unsafe',
                'category' => 'bot_account',
                'reason' => 'Guruhga ruxsatsiz qo\'shilgan bot akkaunt',
                'evidence' => $handle,
                'source' => 'profile_scan_bot',
            ];
        }

        // 1. Kesh tekshiruvi (oxirgi 7 kun ichida tekshirilgan bo'lsa qayta tekshirmaslik)
        $cachedStatus = !$force ? $this->getRecentStatus($userId) : null;
        if ($cachedStatus !== null) {
            return $cachedStatus === 'unsafe'
                ? ['status' => 'unsafe', 'category' => 'profile', 'reason' => 'Profil yaqinda xavfli deb belgilangan (kesh)', 'evidence' => '', 'source' => 'profile_cache']
                : ['status' => 'safe', 'category' => 'none', 'reason' => 'Profil yaqinda tekshirilgan (kesh)', 'evidence' => '', 'source' => 'profile_cache'];
        }

        $firstName = (string)($user['first_name'] ?? '');
        $lastName = (string)($user['last_name'] ?? '');
        $username = (string)($user['username'] ?? '');

        // 2. Ism va username'dagi matnni tekshirish
        $fullName = trim("{$firstName} {$lastName} {$username}");
        if (!empty($fullName)) {
            $nameResult = $this->textModerator->inspect($fullName, [], 'economical', $chatId, "usr_{$userId}");
            if ($nameResult['status'] === 'unsafe' && in_array($nameResult['category'], self::PROFILE_TEXT_CATEGORIES, true)) {
                $this->updateUserStatus($userId, 'unsafe');
                return [
                    'status' => 'unsafe',
                    'category' => $nameResult['category'],
                    'reason' => "Profil ismida qoidabuzarlik aniqlandi: {$nameResult['reason']}",
                    'evidence' => $nameResult['evidence'] ?: $fullName,
                    'source' => 'profile_scan_name',
                ];
            }
        }

        // 2.1. Profil "bio" (about) matnini tekshirish — 2.0 Phase 6.
        // Reklama/skam akkauntlar odatda ismini toza qoldirib, butun targ'ibotni
        // (kripto signal kanali havolasi, "investitsiya" takliflari, referal
        // kodlari) aynan bio'da saqlaydi — shuning uchun faqat ismni tekshirish
        // amalda bu akkauntlarning ko'pini o'tkazib yuborardi.
        $bio = $this->fetchBio($userId);
        if ($bio !== null && $bio !== '') {
            $bioResult = $this->textModerator->inspect($bio, [], 'economical', $chatId, "bio_{$userId}");
            if ($bioResult['status'] === 'unsafe' && in_array($bioResult['category'], self::PROFILE_TEXT_CATEGORIES, true)) {
                $this->updateUserStatus($userId, 'unsafe');
                return [
                    'status' => 'unsafe',
                    'category' => $bioResult['category'],
                    'reason' => "Profil bio'sida qoidabuzarlik aniqlandi: {$bioResult['reason']}",
                    'evidence' => $bioResult['evidence'] ?: mb_substr($bio, 0, 200),
                    'source' => 'profile_scan_bio',
                ];
            }
        }

        // 3. Ko'rinadigan profil rasmini tekshirish
        $photoFinding = $this->inspectProfilePhoto($userId, $chatId);
        if ($photoFinding !== null && $photoFinding['status'] === 'unsafe') {
            $this->updateUserStatus($userId, 'unsafe');
            return [
                'status' => 'unsafe',
                'category' => $photoFinding['category'],
                'reason' => "Profil rasmida qoidabuzarlik aniqlandi: {$photoFinding['reason']}",
                'evidence' => $photoFinding['evidence'],
                'source' => 'profile_scan_photo',
            ];
        }

        $this->updateUserStatus($userId, 'safe');

        return [
            'status' => 'safe',
            'category' => 'none',
            'reason' => "Foydalanuvchi profili xavfsiz",
            'evidence' => '',
            'source' => 'profile_scan',
        ];
    }

    /**
     * Foydalanuvchining profil "bio" (about) matnini olish (2.0 Phase 6).
     *
     * Telegram Bot API'da bu `getChat(user_id)` orqali beriladi. Bot
     * foydalanuvchini "ko'rmagan" bo'lsa yoki maxfiylik sozlamalari yopiq
     * bo'lsa — xato emas, shunchaki `null` qaytadi va tekshiruv bu bosqichni
     * jim o'tkazib yuboradi (hech qachon fatal bo'lmaydi).
     */
    private function fetchBio(int $userId): ?string
    {
        try {
            $chat = $this->telegram->getChat($userId);
            if (!is_array($chat)) {
                return null;
            }
            $bio = trim((string)($chat['bio'] ?? $chat['description'] ?? ''));
            return $bio !== '' ? $bio : null;
        } catch (Throwable $e) {
            Logger::warning("Profil bio'sini olishda xatolik", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ], 'moderation');
            return null;
        }
    }

    private function inspectProfilePhoto(int $userId, ?int $chatId): ?array
    {
        try {
            $photos = $this->telegram->getUserProfilePhotos($userId, 0, 1);
            if (empty($photos['photos']) || empty($photos['photos'][0])) {
                // Rasm yo'qligi yoki maxfiylik cheklovi qoidabuzarlik EMAS
                return null;
            }

            // Eng oxirgi rasmning o'rtacha/katta o'lchamdagi variantini olish
            $photoVariants = $photos['photos'][0];
            $selectedPhoto = end($photoVariants);
            $fileId = $selectedPhoto['file_id'] ?? null;

            if (!$fileId) {
                return null;
            }

            return $this->mediaModerator->inspectMedia($fileId, 'photo', 'Profile Photo', $chatId, "pfp_{$userId}");
        } catch (Throwable $e) {
            Logger::error("Profil rasmini tekshirishda xato [user: {$userId}]: " . $e->getMessage(), [], 'profile');
            return null;
        }
    }

    private function isKnownBot(int $userId): bool
    {
        try {
            $stmt = Database::getConnection()->prepare("SELECT is_bot FROM users WHERE user_id = :uid");
            $stmt->execute(['uid' => $userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function isChatAdminOrWhitelisted(int $chatId, int $userId): bool
    {
        try {
            $stmt = Database::getConnection()->prepare("
                SELECT role, is_whitelisted, is_admin_exempt
                FROM chat_members
                WHERE chat_id = :cid AND user_id = :uid
            ");
            $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }
            return in_array((string)$row['role'], ['creator', 'administrator'], true)
                || (bool)($row['is_whitelisted'] ?? false)
                || (bool)($row['is_admin_exempt'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    private function getRecentStatus(int $userId): ?string
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT profile_checked_at, profile_status FROM users WHERE user_id = :uid");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch();
            $checkedAt = $row['profile_checked_at'] ?? null;

            if ($checkedAt && (time() - strtotime((string)$checkedAt)) < 86400 * 7) {
                return (string)($row['profile_status'] ?? 'safe');
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function updateUserStatus(int $userId, string $status): void
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                UPDATE users
                SET profile_checked_at = :now, profile_status = :status
                WHERE user_id = :uid
            ");
            $stmt->execute([
                'now' => $now,
                'status' => $status,
                'uid' => $userId,
            ]);
        } catch (Throwable) {
        }
    }

    private function upsertUser(int $userId, array $user): void
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $params = [
                'uid' => $userId,
                'first' => (string)($user['first_name'] ?? ''),
                'last' => $user['last_name'] ?? null,
                'username' => $user['username'] ?? null,
                'is_bot' => !empty($user['is_bot']) ? 1 : 0,
                'first_seen' => $now,
                'last_seen' => $now,
            ];
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $sql = "INSERT INTO users (user_id, first_name, last_name, username, is_bot, first_seen_at, last_seen_at) VALUES (:uid, :first, :last, :username, :is_bot, :first_seen, :last_seen) ON CONFLICT(user_id) DO UPDATE SET first_name = excluded.first_name, last_name = excluded.last_name, username = excluded.username, is_bot = excluded.is_bot, last_seen_at = excluded.last_seen_at";
            } else {
                $sql = "INSERT INTO users (user_id, first_name, last_name, username, is_bot, first_seen_at, last_seen_at) VALUES (:uid, :first, :last, :username, :is_bot, :first_seen, :last_seen) ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), username = VALUES(username), is_bot = VALUES(is_bot), last_seen_at = VALUES(last_seen_at)";
            }
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            Logger::error("Foydalanuvchini saqlashda xato [user: {$userId}]: " . $e->getMessage(), [], 'profile');
        }
    }
}
