<?php

declare(strict_types=1);

namespace App\Policy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Bepul/premium tarif tizimi (2.0 Phase 3, 2-band — Telegram Stars orqali
 * monetizatsiya). Har bir GURUH (chat) uchun alohida holat saqlanadi.
 *
 * Bepul tarif: kuniga cheklangan AI so'rovlar (standart `FREE_TIER_DAILY_AI_REQUESTS`,
 * .env orqali sozlanadi). Chegaradan oshgach guruh butunlay himoyasiz qolmaydi —
 * faqat AI tahlil (matn/rasm/video/ovoz) o'sha kun uchun to'xtaydi, mahalliy
 * qoidalar (so'z bloklash, havola filtri va h.k.) baribir ishlashda davom etadi.
 * Premium: cheklovsiz AI, Telegram Stars (bot ichki valyutasi — tashqi to'lov
 * provayderi yoki bank hisobi shart emas) orqali sotib olinadi.
 *
 * `group_settings.premium_expires_at` NULL bo'lsa yoki o'tmishda bo'lsa — guruh
 * "bepul" tarifda hisoblanadi. Bu tekshiruv har chaqiriqda joriy vaqt bilan
 * solishtirib amalga oshiriladi ("lazy") — alohida cron/tozalash vazifasi shart
 * emas, ustunning o'zi har doim haqiqatni aks ettiradi.
 */
class SubscriptionService
{
    public const PLAN_FREE = 'free';
    public const PLAN_PREMIUM = 'premium';

    /**
     * @return array{plan: string, is_premium: bool, premium_expires_at: ?string}
     */
    public static function getPlan(int|string $chatId): array
    {
        $settings = SettingsService::get($chatId);
        $expiresAt = $settings['premium_expires_at'] ?? null;
        $isPremium = !empty($expiresAt) && strtotime((string)$expiresAt) > time();

        return [
            'plan' => $isPremium ? self::PLAN_PREMIUM : self::PLAN_FREE,
            'is_premium' => $isPremium,
            'premium_expires_at' => $expiresAt,
        ];
    }

    /**
     * Bugun (UTC kun boshidan beri) shu guruh uchun muvaffaqiyatli yakunlangan
     * (`ai_usage`ga yozilgan — xato bergan/tugallanmagan so'rovlar hisoblanmaydi)
     * AI so'rovlari soni.
     */
    public static function dailyAiRequestCount(int|string $chatId): int
    {
        $chatId = (int)$chatId;
        $pdo = Database::getConnection();
        $todayStart = gmdate('Y-m-d 00:00:00');

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM ai_usage WHERE chat_id = :cid AND created_at >= :today_start
        ");
        $stmt->execute(['cid' => $chatId, 'today_start' => $todayStart]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Premium guruhlar uchun har doim true. Bepul guruhlar uchun — bugungi AI
     * so'rovlar soni kunlik chegaradan kam bo'lsagina true.
     * `FREE_TIER_DAILY_AI_REQUESTS=0` (yoki manfiy) — chegara butunlay o'chirilgan
     * (operator o'zi shu tarzda cheklovsiz qilib qo'yishi mumkin).
     */
    public static function canUseAi(int|string $chatId): bool
    {
        if (self::getPlan($chatId)['is_premium']) {
            return true;
        }

        $limit = Config::getInt('FREE_TIER_DAILY_AI_REQUESTS', 150);
        if ($limit <= 0) {
            return true;
        }

        return self::dailyAiRequestCount($chatId) < $limit;
    }

    /**
     * Premium muddatini uzaytiradi: agar guruh hozir ham premium (muddati hali
     * tugamagan) bo'lsa — MAVJUD muddatdan boshlab qo'shiladi (stacking, ya'ni
     * muddatidan oldin qayta sotib olish "yo'qotilmaydi"); aks holda hozirgi
     * vaqtdan boshlab hisoblanadi.
     *
     * @return string Yangi tugash sanasi (UTC, 'Y-m-d H:i:s').
     */
    public static function activatePremium(int|string $chatId, int $days): string
    {
        $chatId = (int)$chatId;
        $current = SettingsService::get($chatId)['premium_expires_at'] ?? null;
        $base = (!empty($current) && strtotime((string)$current) > time())
            ? strtotime((string)$current)
            : time();

        $newExpiry = gmdate('Y-m-d H:i:s', $base + (max(1, $days) * 86400));
        SettingsService::update($chatId, ['premium_expires_at' => $newExpiry]);
        return $newExpiry;
    }

    /**
     * Muvaffaqiyatli Telegram Stars to'lovini qayd etadi va premium muddatini
     * shunga qarab uzaytiradi. `telegram_payment_charge_id` bo'yicha idempotent —
     * Telegram webhookni qayta yuborsa (kamdan-kam, lekin mumkin), bitta to'lov
     * ikki marta premium bermaydi.
     *
     * @return array{recorded: bool, premium_expires_at: ?string} `recorded=false`
     *         bo'lsa, bu to'lov avval allaqachon qayd etilgan (dublikat).
     */
    public static function recordStarPayment(
        int|string $chatId,
        int $userId,
        string $telegramPaymentChargeId,
        int $starsAmount,
        int $daysGranted,
        ?string $invoicePayload = null
    ): array {
        $chatId = (int)$chatId;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $ignoreSql = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        try {
            $stmt = $pdo->prepare("
                {$ignoreSql} INTO star_payments
                    (chat_id, user_id, telegram_payment_charge_id, stars_amount, days_granted, invoice_payload, created_at)
                VALUES (:cid, :uid, :charge_id, :stars, :days, :payload, :now)
            ");
            $stmt->execute([
                'cid' => $chatId,
                'uid' => $userId,
                'charge_id' => $telegramPaymentChargeId,
                'stars' => $starsAmount,
                'days' => $daysGranted,
                'payload' => $invoicePayload,
                'now' => $now,
            ]);

            if ($stmt->rowCount() === 0) {
                // UNIQUE(telegram_payment_charge_id)ga to'qnashdi — bu to'lov avval
                // allaqachon qayta ishlangan, premium QAYTA berilmaydi.
                Logger::warning("Takroriy Stars to'lovi e'tiborsiz qoldirildi (idempotency)", [
                    'chat_id' => $chatId,
                    'charge_id' => $telegramPaymentChargeId,
                ], 'billing');
                return ['recorded' => false, 'premium_expires_at' => SettingsService::get($chatId)['premium_expires_at'] ?? null];
            }

            $newExpiry = self::activatePremium($chatId, $daysGranted);
            Logger::info("Stars to'lovi qabul qilindi, premium uzaytirildi", [
                'chat_id' => $chatId,
                'stars' => $starsAmount,
                'days' => $daysGranted,
                'new_expiry' => $newExpiry,
            ], 'billing');

            return ['recorded' => true, 'premium_expires_at' => $newExpiry];
        } catch (Throwable $e) {
            Logger::error("Stars to'lovini qayd etishda xato: " . $e->getMessage(), [
                'chat_id' => $chatId,
                'charge_id' => $telegramPaymentChargeId,
            ], 'billing');
            return ['recorded' => false, 'premium_expires_at' => null];
        }
    }
}
