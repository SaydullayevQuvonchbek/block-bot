-- -----------------------------------------------------------------------------
-- Block-BOT 005: Yangi a'zolar uchun CAPTCHA/tasdiqlash (2.0 Phase 1, 2-band)
-- -----------------------------------------------------------------------------

ALTER TABLE `group_settings`
    ADD COLUMN `captcha_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `flood_mute_duration_sec`;

ALTER TABLE `group_settings`
    ADD COLUMN `captcha_timeout_sec` INT NOT NULL DEFAULT 60 AFTER `captcha_enabled`;

CREATE TABLE IF NOT EXISTS `captcha_pending` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `message_id` BIGINT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_captcha_chat_user` (`chat_id`, `user_id`),
    KEY `idx_captcha_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
