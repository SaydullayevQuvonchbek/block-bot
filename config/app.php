<?php

declare(strict_types=1);

use App\Core\Config;

Config::load();

return [
    'name' => 'Block-BOT',
    'env' => Config::get('APP_ENV', 'production'),
    'debug' => Config::getBool('APP_DEBUG', false),
    'timezone' => Config::get('APP_TIMEZONE', 'Asia/Tashkent'),
    'data_retention_days' => Config::getInt('DATA_RETENTION_DAYS', 60),
    'moderate_async' => Config::getBool('MODERATE_ASYNC', false),

    'telegram' => [
        'bot_token' => Config::get('TELEGRAM_BOT_TOKEN', ''),
        'bot_username' => Config::get('TELEGRAM_BOT_USERNAME', ''),
        'api_base_url' => Config::get('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'proxy' => Config::get('TELEGRAM_PROXY', null),
        'proxy_secret' => Config::get('TELEGRAM_PROXY_SECRET', null),
        'webhook_url' => Config::get('TELEGRAM_WEBHOOK_URL', ''),
        'webhook_secret' => Config::get('TELEGRAM_WEBHOOK_SECRET', ''),
        'admin_log_chat_id' => Config::get('TELEGRAM_ADMIN_LOG_CHAT_ID', null),
        'owner_ids' => Config::get('TELEGRAM_OWNER_IDS', ''),
    ],

    'media' => [
        'ffmpeg_path' => Config::get('FFMPEG_PATH', 'ffmpeg'),
        'max_upload_size' => Config::getInt('MAX_UPLOAD_SIZE_BYTES', 20971520),
    ],
];
