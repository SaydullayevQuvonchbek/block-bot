<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Logger;

Config::load();

echo "=========================================================\n";
echo "🛡 Block-BOT: MTProto CLI Foydalanuvchi Autentifikatsiyasi\n";
echo "=========================================================\n";
echo "DIQQAT: Ushbu buyruq faqat server konsolida (CLI) bajariladi.\n";
echo "Xavfsizlik talabiga ko'ra, login kodi va 2FA paroli hech qachon\n";
echo "Telegram bot chatida so'ralmaydi!\n\n";

$apiId = (string)Config::get('MTPROTO_API_ID');
$apiHash = (string)Config::get('MTPROTO_API_HASH');
$sessionPath = (string)Config::get('MTPROTO_SESSION_PATH', 'storage/sessions/mtproto.session');

if (empty($apiId) || empty($apiHash)) {
    echo "❌ Xatolik: .env faylida MTPROTO_API_ID va MTPROTO_API_HASH ko'rsatilmagan!\n";
    echo "Telegram my.telegram.org saytidan API_ID va API_HASH oling.\n";
    exit(1);
}

// MadelineProto mavjudligini tekshirish
if (!class_exists('\danog\MadelineProto\API')) {
    echo "ℹ️ MadelineProto kutubxonasi yuklanmagan.\n";
    echo "Uni o'rnatish uchun quyidagi buyruqni bajaring:\n";
    echo "composer require danog/madelineproto\n\n";
    echo "MTProto sessiyasiz ham oddiy Bot API va JSON eksport auditi to'liq ishlayveradi.\n";
    exit(0);
}

$sessionDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . dirname($sessionPath);
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}

$fullSessionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . $sessionPath;

echo "Sessiya fayli joylashuvi: {$fullSessionFile}\n";
echo "Autentifikatsiya jarayoni boshlanmoqda...\n\n";

try {
    $settings = [
        'app_info' => [
            'api_id' => (int)$apiId,
            'api_hash' => $apiHash,
        ],
        'logger' => [
            'logger' => \danog\MadelineProto\Logger::FILE_LOGGER,
            'logger_file' => dirname(__DIR__) . '/storage/logs/mtproto.log',
        ],
    ];

    $madeline = new \danog\MadelineProto\API($fullSessionFile, $settings);
    $madeline->start();

    echo "\n✅ MTProto foydalanuvchi akkauntiga muvaffaqiyatli ulandi!\n";
    echo "Sessiya xavfsiz saqlandi (chmod 0600).\n";
    @chmod($fullSessionFile, 0600);

    $self = $madeline->getSelf();
    echo "Uланган akkaunt: {$self['first_name']} (@" . ($self['username'] ?? 'username_yo\'q') . ", ID: {$self['id']})\n";
} catch (Throwable $e) {
    echo "\n❌ MTProto login xatosi: " . $e->getMessage() . "\n";
    Logger::error("MTProto login xatosi: " . $e->getMessage(), [], 'mtproto');
    exit(1);
}
