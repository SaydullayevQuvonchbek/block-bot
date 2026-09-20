-- SQLite Test Schema
CREATE TABLE IF NOT EXISTS groups (
    chat_id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'supergroup',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS group_settings (
    chat_id INTEGER PRIMARY KEY,
    clean_service_messages INTEGER NOT NULL DEFAULT 1,
    profanity_filter INTEGER NOT NULL DEFAULT 1,
    porn_filter INTEGER NOT NULL DEFAULT 1,
    link_filter INTEGER NOT NULL DEFAULT 1,
    media_filter INTEGER NOT NULL DEFAULT 1,
    profile_scan INTEGER NOT NULL DEFAULT 1,
    bot_filter INTEGER NOT NULL DEFAULT 1,
    ai_mode TEXT NOT NULL DEFAULT 'comprehensive',
    ai_model_text TEXT NOT NULL DEFAULT 'google/gemini-2.5-flash',
    ai_model_vision TEXT NOT NULL DEFAULT 'google/gemini-2.5-flash',
    unscannable_action TEXT NOT NULL DEFAULT 'leave_alert',
    warn_limit INTEGER NOT NULL DEFAULT 3,
    warn_duration_days INTEGER NOT NULL DEFAULT 30,
    mute_1st_duration_sec INTEGER NOT NULL DEFAULT 3600,
    mute_2nd_duration_sec INTEGER NOT NULL DEFAULT 86400,
    porn_action TEXT NOT NULL DEFAULT 'ban',
    adult_account_action TEXT NOT NULL DEFAULT 'mute_notify',
    history_cleanup_enabled INTEGER NOT NULL DEFAULT 1,
    log_chat_id INTEGER NULL,
    custom_rules_json TEXT NULL,
    flood_enabled INTEGER NOT NULL DEFAULT 1,
    flood_max_messages INTEGER NOT NULL DEFAULT 6,
    flood_window_sec INTEGER NOT NULL DEFAULT 10,
    flood_mute_duration_sec INTEGER NOT NULL DEFAULT 600,
    captcha_enabled INTEGER NOT NULL DEFAULT 0,
    captcha_timeout_sec INTEGER NOT NULL DEFAULT 60,
    -- Guruhga ko'rinadigan xabarlar (CAPTCHA, ogohlantirish/mute/ban) tili:
    -- 'uz' (standart), 'ru', 'en'. Admin panel/DM buyruqlari hozircha o'zbekcha
    -- qoladi (2.0 Phase 3, 1-band — i18n, bosqichma-bosqich).
    language TEXT NOT NULL DEFAULT 'uz',
    -- NULL = bepul tarif. Muddat o'tgan bo'lsa ham avtomatik bepulga tushadi
    -- (App\Policy\SubscriptionService "lazy" tekshiradi) — 2.0 Phase 3, 2-band.
    premium_expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    first_name TEXT NOT NULL,
    last_name TEXT NULL,
    username TEXT NULL,
    is_bot INTEGER NOT NULL DEFAULT 0,
    profile_checked_at TEXT NULL,
    profile_status TEXT NOT NULL DEFAULT 'unscanned',
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS chat_members (
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    -- Telegram'ning o'zi sinxronlaydigan `role`dan ataylab alohida: botning ichki,
    -- cheklangan huquqli "moderator" roli ('none' | 'moderator'). Telegram sinxronizatsiyasi
    -- bu ustunga hech qachon tegmaydi (AdminAuthorizationService::isAdmin()ga qarang).
    bot_role TEXT NOT NULL DEFAULT 'none',
    is_admin_exempt INTEGER NOT NULL DEFAULT 0,
    is_whitelisted INTEGER NOT NULL DEFAULT 0,
    mute_until TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (chat_id, user_id)
);

CREATE TABLE IF NOT EXISTS updates_log (
    update_id INTEGER PRIMARY KEY,
    chat_id INTEGER NULL,
    update_type TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    sender_chat_id INTEGER NULL,
    media_group_id TEXT NULL,
    media_type TEXT NOT NULL DEFAULT 'text',
    raw_text TEXT NULL,
    normalized_text TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    message_date TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (chat_id, message_id)
);

CREATE TABLE IF NOT EXISTS message_edits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    old_text TEXT NULL,
    new_text TEXT NULL,
    edit_date TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS moderation_findings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    message_id INTEGER NULL,
    user_id INTEGER NULL,
    source TEXT NOT NULL,
    category TEXT NOT NULL,
    status TEXT NOT NULL,
    reason TEXT NOT NULL,
    evidence TEXT NOT NULL,
    model TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS user_warnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    reason TEXT NOT NULL,
    finding_id INTEGER NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS telegram_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    message_id INTEGER NULL,
    action_type TEXT NOT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    telegram_response TEXT NULL,
    error_message TEXT NULL,
    cleanup_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS queue_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default',
    priority INTEGER NOT NULL DEFAULT 10,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at TEXT NULL,
    available_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NULL,
    model TEXT NOT NULL,
    request_type TEXT NOT NULL,
    prompt_tokens INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens INTEGER NOT NULL DEFAULT 0,
    estimated_cost_usd REAL NOT NULL DEFAULT 0.0,
    is_estimated INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_budget_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reservation_key TEXT NOT NULL UNIQUE,
    reserved_cost_usd REAL NOT NULL,
    settled INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS moderation_cache (
    cache_key TEXT PRIMARY KEY,
    item_type TEXT NOT NULL,
    result_json TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS word_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NULL,
    rule_type TEXT NOT NULL,
    word_pattern TEXT NOT NULL,
    is_regex INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS domain_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NULL,
    rule_type TEXT NOT NULL,
    domain TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    admin_user_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    filter_scope TEXT NOT NULL DEFAULT 'all',
    ai_mode TEXT NOT NULL DEFAULT 'economical',
    max_budget_usd REAL NOT NULL DEFAULT 1.0,
    budget_spent_usd REAL NOT NULL DEFAULT 0.0,
    checkpoint_message_id INTEGER NULL,
    checkpoint_offset_date TEXT NULL,
    total_scanned INTEGER NOT NULL DEFAULT 0,
    total_flagged INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    cleanup_summary TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audit_session_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    message_id INTEGER NULL,
    user_id INTEGER NULL,
    message_date TEXT NULL,
    category TEXT NOT NULL,
    status TEXT NOT NULL,
    reason TEXT NOT NULL,
    evidence TEXT NOT NULL,
    is_media INTEGER NOT NULL DEFAULT 0,
    cleanup_status TEXT NULL,
    cleanup_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (audit_session_id) REFERENCES audit_sessions (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS flood_counters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    window_start TEXT NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL,
    UNIQUE (chat_id, user_id)
);

CREATE TABLE IF NOT EXISTS captcha_pending (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (chat_id, user_id)
);

CREATE TABLE IF NOT EXISTS moderation_appeals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action_id INTEGER NOT NULL UNIQUE,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    finding_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    recheck_status TEXT NOT NULL DEFAULT 'review',
    recheck_reason TEXT NOT NULL,
    reviewed_by INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Har bir muvaffaqiyatli Telegram Stars to'lovi (2.0 Phase 3, 2-band —
-- monetizatsiya). UNIQUE(telegram_payment_charge_id) — Telegram webhookni
-- qayta yuborsa ham premium muddati ikki marta uzaytirilmasligi uchun.
CREATE TABLE IF NOT EXISTS star_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    telegram_payment_charge_id TEXT NOT NULL UNIQUE,
    stars_amount INTEGER NOT NULL,
    days_granted INTEGER NOT NULL,
    invoice_payload TEXT NULL,
    created_at TEXT NOT NULL
);
