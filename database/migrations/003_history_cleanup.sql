-- -----------------------------------------------------------------------------
-- Block-BOT 003: Tarixiy tozalash, a'zolar sweep va 18+/bot akkaunt siyosati
-- -----------------------------------------------------------------------------

ALTER TABLE `audit_items`
    ADD COLUMN `cleanup_status` VARCHAR(20) NULL AFTER `is_media`;

ALTER TABLE `audit_items`
    ADD COLUMN `cleanup_at` DATETIME NULL AFTER `cleanup_status`;

ALTER TABLE `audit_items`
    ADD KEY `idx_audit_cleanup` (`audit_session_id`, `status`, `cleanup_status`);

ALTER TABLE `audit_sessions`
    ADD COLUMN `cleanup_summary` TEXT NULL AFTER `error_message`;

ALTER TABLE `group_settings`
    ADD COLUMN `bot_filter` TINYINT(1) NOT NULL DEFAULT 1 AFTER `profile_scan`;

ALTER TABLE `group_settings`
    ADD COLUMN `adult_account_action` VARCHAR(20) NOT NULL DEFAULT 'mute_notify' AFTER `porn_action`;

ALTER TABLE `group_settings`
    ADD COLUMN `history_cleanup_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `adult_account_action`;
