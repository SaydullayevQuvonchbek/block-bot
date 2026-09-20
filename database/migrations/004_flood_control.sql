-- -----------------------------------------------------------------------------
-- Block-BOT 004: Anti-flood / spam-portlash himoyasi (2.0 Phase 1)
-- -----------------------------------------------------------------------------

ALTER TABLE `group_settings`
    ADD COLUMN `flood_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `history_cleanup_enabled`;

ALTER TABLE `group_settings`
    ADD COLUMN `flood_max_messages` INT NOT NULL DEFAULT 6 AFTER `flood_enabled`;

ALTER TABLE `group_settings`
    ADD COLUMN `flood_window_sec` INT NOT NULL DEFAULT 10 AFTER `flood_max_messages`;

ALTER TABLE `group_settings`
    ADD COLUMN `flood_mute_duration_sec` INT NOT NULL DEFAULT 600 AFTER `flood_window_sec`;

CREATE TABLE IF NOT EXISTS `flood_counters` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `window_start` DATETIME NOT NULL,
    `message_count` INT NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_flood_chat_user` (`chat_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
