-- -----------------------------------------------------------------------------
-- Block-BOT 008: Telegram Stars orqali monetizatsiya (2.0 Phase 3, 2-band)
-- -----------------------------------------------------------------------------
-- Bepul tarif: kuniga cheklangan AI so'rovlar (standart FREE_TIER_DAILY_AI_REQUESTS,
-- .env orqali sozlanadi) — chegaradan oshgach guruh AI tahlilsiz (faqat mahalliy
-- qoidalar bilan) himoyalanishda davom etadi, hech narsa butunlay to'xtamaydi.
-- Premium: cheklovsiz AI, Telegram Stars (ichki valyuta XTR, tashqi to'lov
-- provayderi shart emas) orqali sotib olinadi.
--
-- `group_settings.premium_expires_at` NULL bo'lsa — guruh bepul tarifda (hech
-- qachon sotib olmagan). Muddat o'tgan bo'lsa ham avtomatik "bepul"ga tushadi —
-- alohida cron/tozalash job shart emas, App\Policy\SubscriptionService har safar
-- shu ustunni joriy vaqt bilan solishtirib tekshiradi ("lazy" tekshiruv).
ALTER TABLE `group_settings`
    ADD COLUMN `premium_expires_at` DATETIME NULL AFTER `language`;

-- Har bir muvaffaqiyatli Telegram Stars to'lovi shu yerga yoziladi.
-- `telegram_payment_charge_id` UNIQUE — Telegram webhookni qayta yuborishi
-- (kamdan-kam, lekin mumkin) natijasida bitta to'lov ikki marta premium
-- muddatini uzaytirib yubormasligi uchun (idempotency).
CREATE TABLE IF NOT EXISTS `star_payments` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `telegram_payment_charge_id` VARCHAR(255) NOT NULL,
    `stars_amount` INT NOT NULL,
    `days_granted` INT NOT NULL,
    `invoice_payload` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_star_payment_charge_id` (`telegram_payment_charge_id`),
    KEY `idx_star_payments_chat` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
