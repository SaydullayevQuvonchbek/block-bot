<?php

declare(strict_types=1);

use App\Core\Config;

Config::load();

return [
    'defaults' => [
        'clean_service_messages' => true,
        'profanity_filter' => true,
        'porn_filter' => true,
        'link_filter' => true,
        'media_filter' => true,
        'profile_scan' => true,
        'bot_filter' => true,
        'ai_mode' => Config::get('AI_DEFAULT_MODE', 'comprehensive'),
        'warn_limit' => 3,
        'warn_duration_days' => 30,
        'mute_1st_duration_sec' => 3600,
        'mute_2nd_duration_sec' => 86400,
        'porn_action' => 'ban',
        // 18+ profil va ruxsatsiz bot akkauntlar uchun: 'mute_notify' = vaqtincha cheklab
        // adminга ban tugmasi yuboriladi; 'ban' = darhol chetlatish; 'notify' = faqat xabar.
        'adult_account_action' => 'mute_notify',
        'unscannable_action' => 'leave_alert',
        // Tarixiy audit topilmalarini admin tugma orqali tozalashi mumkinmi.
        'history_cleanup_enabled' => true,
    ],

    'ai' => [
        'provider' => Config::get('AI_PROVIDER', 'openrouter'),
        'gemini_api_key' => Config::get('GEMINI_API_KEY', ''),
        'gemini_base_url' => Config::get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'gemini_text_model' => Config::get('GEMINI_TEXT_MODEL', 'gemini-2.5-flash'),
        'gemini_vision_model' => Config::get('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
        'api_key' => Config::get('OPENROUTER_API_KEY', ''),
        'base_url' => Config::get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'text_model' => Config::get('OPENROUTER_TEXT_MODEL', 'google/gemini-2.5-flash'),
        'vision_model' => Config::get('OPENROUTER_VISION_MODEL', 'google/gemini-2.5-flash'),
        'fallback_model' => Config::get('OPENROUTER_FALLBACK_MODEL', 'meta-llama/llama-3.3-70b-instruct'),
        'daily_budget_usd' => Config::getFloat('AI_DAILY_BUDGET_USD', 1.00),
        'monthly_budget_usd' => Config::getFloat('AI_MONTHLY_BUDGET_USD', 25.00),
    ],

    'mtproto' => [
        'api_id' => Config::get('MTPROTO_API_ID', ''),
        'api_hash' => Config::get('MTPROTO_API_HASH', ''),
        'session_path' => Config::get('MTPROTO_SESSION_PATH', 'storage/sessions/mtproto.session'),
        'allowed_chats' => Config::get('MTPROTO_ALLOWED_CHATS', ''),
    ],
];
