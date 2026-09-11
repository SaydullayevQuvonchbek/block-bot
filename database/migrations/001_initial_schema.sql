-- -----------------------------------------------------------------------------
-- Telegram Moderatsiya Boti (Block-BOT) - Baza Sxemasi
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `groups` (
    `chat_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT 'supergroup',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_settings` (
    `chat_id` BIGINT NOT NULL,
    `clean_service_messages` TINYINT(1) NOT NULL DEFAULT 1,
    `profanity_filter` TINYINT(1) NOT NULL DEFAULT 1,
    `porn_filter` TINYINT(1) NOT NULL DEFAULT 1,
    `link_filter` TINYINT(1) NOT NULL DEFAULT 1,
    `media_filter` TINYINT(1) NOT NULL DEFAULT 1,
    `profile_scan` TINYINT(1) NOT NULL DEFAULT 1,
    `ai_mode` VARCHAR(20) NOT NULL DEFAULT 'comprehensive',
    `ai_model_text` VARCHAR(100) NOT NULL DEFAULT 'google/gemini-2.5-flash',
    `ai_model_vision` VARCHAR(100) NOT NULL DEFAULT 'google/gemini-2.5-flash',
    `unscannable_action` VARCHAR(20) NOT NULL DEFAULT 'leave_alert',
    `warn_limit` INT NOT NULL DEFAULT 3,
    `warn_duration_days` INT NOT NULL DEFAULT 30,
    `mute_1st_duration_sec` INT NOT NULL DEFAULT 3600,
    `mute_2nd_duration_sec` INT NOT NULL DEFAULT 86400,
    `porn_action` VARCHAR(20) NOT NULL DEFAULT 'ban',
    `log_chat_id` BIGINT NULL,
    `custom_rules_json` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`chat_id`),
    CONSTRAINT `fk_settings_group` FOREIGN KEY (`chat_id`) REFERENCES `groups` (`chat_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `user_id` BIGINT NOT NULL,
    `first_name` VARCHAR(255) NOT NULL,
    `last_name` VARCHAR(255) NULL,
    `username` VARCHAR(255) NULL,
    `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
    `profile_checked_at` DATETIME NULL,
    `profile_status` VARCHAR(20) NOT NULL DEFAULT 'unscanned',
    `first_seen_at` DATETIME NOT NULL,
    `last_seen_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_members` (
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `role` VARCHAR(32) NOT NULL DEFAULT 'member',
    `is_admin_exempt` TINYINT(1) NOT NULL DEFAULT 0,
    `is_whitelisted` TINYINT(1) NOT NULL DEFAULT 0,
    `mute_until` DATETIME NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`chat_id`, `user_id`),
    KEY `idx_chat_role` (`chat_id`, `role`),
    CONSTRAINT `fk_cm_group` FOREIGN KEY (`chat_id`) REFERENCES `groups` (`chat_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `updates_log` (
    `update_id` BIGINT NOT NULL,
    `chat_id` BIGINT NULL,
    `update_type` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `message_id` BIGINT NOT NULL,
    `user_id` BIGINT NULL,
    `sender_chat_id` BIGINT NULL,
    `media_group_id` VARCHAR(128) NULL,
    `media_type` VARCHAR(32) NOT NULL DEFAULT 'text',
    `raw_text` TEXT NULL,
    `normalized_text` TEXT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'active',
    `message_date` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_chat_msg` (`chat_id`, `message_id`),
    KEY `idx_media_group` (`chat_id`, `media_group_id`),
    KEY `idx_user_messages` (`chat_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_edits` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `message_id` BIGINT NOT NULL,
    `old_text` TEXT NULL,
    `new_text` TEXT NULL,
    `edit_date` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_msg_edit` (`chat_id`, `message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moderation_findings` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `message_id` BIGINT NULL,
    `user_id` BIGINT NULL,
    `source` VARCHAR(32) NOT NULL,
    `category` VARCHAR(64) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `reason` TEXT NOT NULL,
    `evidence` TEXT NOT NULL,
    `model` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_find_chat_user` (`chat_id`, `user_id`),
    KEY `idx_find_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_warnings` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `finding_id` BIGINT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_warn_idem` (`idempotency_key`),
    KEY `idx_active_warns` (`chat_id`, `user_id`, `is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_actions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `user_id` BIGINT NULL,
    `message_id` BIGINT NULL,
    `action_type` VARCHAR(64) NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `telegram_response` TEXT NULL,
    `error_message` TEXT NULL,
    `cleanup_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_action_idem` (`idempotency_key`),
    KEY `idx_action_cleanup` (`status`, `cleanup_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_jobs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
    `priority` INT NOT NULL DEFAULT 10,
    `payload` LONGTEXT NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `reserved_at` DATETIME NULL,
    `available_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_q_reserve` (`queue`, `reserved_at`, `available_at`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(64) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_usage` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NULL,
    `model` VARCHAR(100) NOT NULL,
    `request_type` VARCHAR(32) NOT NULL,
    `prompt_tokens` INT NOT NULL DEFAULT 0,
    `completion_tokens` INT NOT NULL DEFAULT 0,
    `total_tokens` INT NOT NULL DEFAULT 0,
    `estimated_cost_usd` DECIMAL(10, 6) NOT NULL DEFAULT 0.000000,
    `is_estimated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    KEY `idx_ai_chat_date` (`chat_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_budget_reservations` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `reservation_key` VARCHAR(128) NOT NULL,
    `reserved_cost_usd` DECIMAL(10, 6) NOT NULL,
    `settled` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_budget_key` (`reservation_key`),
    KEY `idx_budget_settled` (`settled`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moderation_cache` (
    `cache_key` VARCHAR(128) NOT NULL,
    `item_type` VARCHAR(20) NOT NULL,
    `result_json` TEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`cache_key`),
    KEY `idx_cache_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `word_rules` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NULL,
    `rule_type` VARCHAR(20) NOT NULL,
    `word_pattern` VARCHAR(255) NOT NULL,
    `is_regex` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    KEY `idx_word_chat` (`chat_id`, `rule_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domain_rules` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NULL,
    `rule_type` VARCHAR(20) NOT NULL,
    `domain` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_domain_chat` (`chat_id`, `rule_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_sessions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `admin_user_id` BIGINT NOT NULL,
    `source_type` VARCHAR(32) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `filter_scope` VARCHAR(32) NOT NULL DEFAULT 'all',
    `ai_mode` VARCHAR(20) NOT NULL DEFAULT 'economical',
    `max_budget_usd` DECIMAL(8, 4) NOT NULL DEFAULT 1.0000,
    `budget_spent_usd` DECIMAL(8, 4) NOT NULL DEFAULT 0.0000,
    `checkpoint_message_id` BIGINT NULL,
    `checkpoint_offset_date` DATETIME NULL,
    `total_scanned` INT NOT NULL DEFAULT 0,
    `total_flagged` INT NOT NULL DEFAULT 0,
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_items` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `audit_session_id` BIGINT NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `message_id` BIGINT NULL,
    `user_id` BIGINT NULL,
    `message_date` DATETIME NULL,
    `category` VARCHAR(64) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `reason` TEXT NOT NULL,
    `evidence` TEXT NOT NULL,
    `is_media` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    KEY `idx_audit_session` (`audit_session_id`),
    CONSTRAINT `fk_ai_session` FOREIGN KEY (`audit_session_id`) REFERENCES `audit_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
