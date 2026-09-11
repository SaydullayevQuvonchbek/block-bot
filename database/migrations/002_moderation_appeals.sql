CREATE TABLE IF NOT EXISTS `moderation_appeals` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `action_id` BIGINT NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `finding_id` BIGINT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `recheck_status` VARCHAR(20) NOT NULL DEFAULT 'review',
    `recheck_reason` TEXT NOT NULL,
    `reviewed_by` BIGINT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_appeal_action` (`action_id`),
    KEY `idx_appeal_status` (`chat_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `group_settings`
SET `ai_mode` = 'comprehensive'
WHERE `ai_mode` = 'economical';
