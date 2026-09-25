<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

class SettingsService
{
    private static array $cache = [];

    /**
     * `update()` orqali yozilishi mumkin bo'lgan barcha ustunlar.
     */
    public const ALLOWED_UPDATE_COLUMNS = [
        'clean_service_messages', 'profanity_filter', 'porn_filter',
        'link_filter', 'media_filter', 'profile_scan', 'bot_filter', 'ai_mode',
        'ai_model_text', 'ai_model_vision', 'unscannable_action',
        'warn_limit', 'warn_duration_days', 'mute_1st_duration_sec',
        'mute_2nd_duration_sec', 'porn_action', 'adult_account_action', 'spam_account_action',
        'history_cleanup_enabled', 'log_chat_id',
        'flood_enabled', 'flood_max_messages', 'flood_window_sec', 'flood_mute_duration_sec',
        'captcha_enabled', 'captcha_timeout_sec', 'language', 'premium_expires_at',
    ];

    /**
     * Guruhlar orasida klonlash/eksport-import uchun "xavfsiz" deb topilgan
     * pastki to'plam — ATAYLAB `log_chat_id` (guruhga xos infratuzilma
     * ko'rsatkichi — boshqa guruhga ko'chirilsa noto'g'ri kanalga log
     * yuborilishi mumkin), `premium_expires_at` (to'lov holati, "sozlama"
     * emas) va `ai_model_text`/`ai_model_vision` (erkin matnli model nomlari —
     * import orqali qabul qilinsa keyinchalik AI so'rovlarini buzishi mumkin)
     * chiqarib tashlangan (2.0 Phase 4, 2-band).
     */
    public const CLONEABLE_COLUMNS = [
        'clean_service_messages', 'profanity_filter', 'porn_filter',
        'link_filter', 'media_filter', 'profile_scan', 'bot_filter', 'ai_mode',
        'unscannable_action',
        'warn_limit', 'warn_duration_days', 'mute_1st_duration_sec',
        'mute_2nd_duration_sec', 'porn_action', 'adult_account_action', 'spam_account_action',
        'history_cleanup_enabled',
        'flood_enabled', 'flood_max_messages', 'flood_window_sec', 'flood_mute_duration_sec',
        'captcha_enabled', 'captcha_timeout_sec', 'language',
    ];

    public static function get(int|string $chatId): array
    {
        $chatId = (int)$chatId;
        if (isset(self::$cache[$chatId])) {
            return self::$cache[$chatId];
        }

        $pdo = Database::getConnection();

        // Guruh mavjudligini tekshirish
        $stmt = $pdo->prepare("SELECT * FROM group_settings WHERE chat_id = :cid");
        $stmt->execute(['cid' => $chatId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            $now = gmdate('Y-m-d H:i:s');
            $provider = strtolower((string)Config::get('AI_PROVIDER', 'openrouter'));
            $defaultTextModel = in_array($provider, ['gemini', 'google', 'google_gemini'], true)
                ? (string)Config::get('GEMINI_TEXT_MODEL', 'gemini-2.5-flash')
                : (string)Config::get('OPENROUTER_TEXT_MODEL', 'google/gemini-2.5-flash');
            $defaultVisionModel = in_array($provider, ['gemini', 'google', 'google_gemini'], true)
                ? (string)Config::get('GEMINI_VISION_MODEL', 'gemini-2.5-flash')
                : (string)Config::get('OPENROUTER_VISION_MODEL', 'google/gemini-2.5-flash');
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $ignoreSql = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            // Guruhni ro'yxatga olish
            $insGroup = $pdo->prepare("
                {$ignoreSql} INTO `groups` (chat_id, title, type, is_active, created_at, updated_at)
                VALUES (:cid, 'Guruh', 'supergroup', 1, :created_at, :updated_at)
            ");
            $insGroup->execute(['cid' => $chatId, 'created_at' => $now, 'updated_at' => $now]);

            // Standart sozlamalar
            $insSettings = $pdo->prepare("
                INSERT INTO group_settings (
                    chat_id, clean_service_messages, profanity_filter, porn_filter,
                    link_filter, media_filter, profile_scan, bot_filter, ai_mode,
                    ai_model_text, ai_model_vision, unscannable_action,
                    warn_limit, warn_duration_days, mute_1st_duration_sec,
                    mute_2nd_duration_sec, porn_action, adult_account_action,
                    history_cleanup_enabled, log_chat_id,
                    flood_enabled, flood_max_messages, flood_window_sec, flood_mute_duration_sec,
                    captcha_enabled, captcha_timeout_sec,
                    created_at, updated_at
                ) VALUES (
                    :cid, 1, 1, 1,
                    1, 1, 1, 1, 'comprehensive',
                    :text_model, :vision_model, 'leave_alert',
                    3, 30, 3600,
                    86400, 'ban', 'mute_notify',
                    1, NULL,
                    1, 6, 10, 600,
                    0, 60,
                    :created_at, :updated_at
                )
            ");
            $insSettings->execute([
                'cid' => $chatId,
                'text_model' => $defaultTextModel,
                'vision_model' => $defaultVisionModel,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stmt->execute(['cid' => $chatId]);
            $settings = $stmt->fetch();
        }

        self::$cache[$chatId] = $settings;
        return $settings;
    }

    public static function update(int|string $chatId, array $values): bool
    {
        $chatId = (int)$chatId;
        self::get($chatId); // Guruh va sozlamalar bazada mavjudligini kafolatlash
        $pdo = Database::getConnection();

        $updates = [];
        $params = ['cid' => $chatId];

        foreach ($values as $col => $val) {
            if (in_array($col, self::ALLOWED_UPDATE_COLUMNS, true)) {
                $updates[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $updates[] = "`updated_at` = :now";
        $params['now'] = $now;

        $sql = "UPDATE group_settings SET " . implode(', ', $updates) . " WHERE chat_id = :cid";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);

        unset(self::$cache[$chatId]);
        return $ok;
    }

    public static function clearCache(?int $chatId = null): void
    {
        if ($chatId !== null) {
            unset(self::$cache[$chatId]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * Guruhning joriy sozlamalaridan faqat CLONEABLE_COLUMNS to'plamini
     * ajratib olish — eksport/klonlash uchun (2.0 Phase 4, 2-band).
     *
     * @return array<string,mixed>
     */
    public static function exportSettings(int|string $chatId): array
    {
        $settings = self::get($chatId);
        $exported = [];
        foreach (self::CLONEABLE_COLUMNS as $col) {
            if (array_key_exists($col, $settings)) {
                $exported[$col] = $settings[$col];
            }
        }
        return $exported;
    }

    /**
     * Manba guruhning klonlanishi mumkin bo'lgan sozlamalarini boshqa
     * (maqsad) guruhga nusxalash. Ikkalasi ham allaqachon bazada mavjud
     * bo'lishini kafolatlash uchun `get()` chaqiriladi.
     *
     * @return array<string,mixed> Maqsad guruhga qo'llanilgan qiymatlar.
     */
    public static function cloneInto(int|string $sourceChatId, int|string $targetChatId): array
    {
        $exported = self::exportSettings($sourceChatId);
        self::update($targetChatId, $exported);
        return $exported;
    }
}
