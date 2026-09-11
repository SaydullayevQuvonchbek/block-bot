<?php

declare(strict_types=1);

use App\Core\Config;

Config::load();

return [
    'default' => Config::get('DB_DRIVER', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => Config::get('DB_HOST', '127.0.0.1'),
            'port' => Config::getInt('DB_PORT', 3306),
            'database' => Config::get('DB_NAME', 'block_bot'),
            'username' => Config::get('DB_USER', 'root'),
            'password' => Config::get('DB_PASS', ''),
            'charset' => Config::get('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => Config::get('DB_SQLITE_PATH', ':memory:'),
        ],
    ],
];
