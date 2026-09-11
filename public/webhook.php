<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Logger;
use App\Http\UpdateRouter;

// Konfiguratsiyani yuklash
Config::load();

// Faqat POST so'rovlarni qabul qilish
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// 1. Webhook Secret Token tekshiruvi (Xavfsizlik)
$configuredSecret = Config::get('TELEGRAM_WEBHOOK_SECRET');
if (Config::get('APP_ENV', 'production') === 'production' && (!is_string($configuredSecret) || strlen($configuredSecret) < 32)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Webhook is not configured']);
    exit;
}
if (!empty($configuredSecret)) {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (empty($incomingSecret) && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $hKey => $hVal) {
            if (strcasecmp($hKey, 'X-Telegram-Bot-Api-Secret-Token') === 0) {
                $incomingSecret = (string)$hVal;
                break;
            }
        }
    }
    if (!hash_equals($configuredSecret, $incomingSecret)) {
        http_response_code(403);
        Logger::warning("Webhook secret token mos kelmadi. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'noma\'lum'));
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

// 2. Request body hajmini va JSON formatini tekshirish
$rawBody = file_get_contents('php://input');
if (empty($rawBody) || strlen($rawBody) > 1048576) { // 1 MB cheklov
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad Request: Payload empty or exceeds 1MB']);
    exit;
}

$update = json_decode($rawBody, true);
if (!is_array($update)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad Request: Invalid JSON']);
    exit;
}

// 3. UpdateRouter orqali tez qayta ishlash
try {
    $router = new UpdateRouter();
    $result = $router->handle($update);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    Logger::error("Webhook qayta ishlashda kutilmagan xatolik: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
    // 5xx Telegram'ga update qayta yuborilishi kerakligini bildiradi.
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Temporary processing failure']);
}
