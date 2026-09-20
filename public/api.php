<?php

declare(strict_types=1);

/**
 * Telegram Mini App / Web Dashboard uchun yagona REST API kirish nuqtasi
 * (2.0 Phase 3, 3-band). `public/miniapp/index.html` shu manzilga `fetch()`
 * so'rovlari yuboradi. Barcha amallar `?action=...` orqali tanlanadi (oddiy
 * shared-hosting'da ham ishlashi uchun — `.htaccess`da maxsus "pretty URL"
 * qayta yo'naltirish qoidalari SHART EMAS, xuddi `webhook.php`/`healthz.php`
 * kabi).
 *
 * Autentifikatsiya har bir so'rovda Telegram WebApp `initData`si orqali
 * amalga oshiriladi (`App\Core\MiniAppAuth`) — bu yerda hech qanday
 * sessiya/cookie ishlatilmaydi. `initData` `X-Telegram-Init-Data` sarlavhasi
 * orqali yuboriladi (frontend shunday jo'natadi).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Logger;
use App\Http\MiniAppApiRouter;

Config::load();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request', 'message' => 'action ko\'rsatilmagan'], JSON_UNESCAPED_UNICODE);
    exit;
}

$initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
if ($initData === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $hKey => $hVal) {
        if (strcasecmp($hKey, 'X-Telegram-Init-Data') === 0) {
            $initData = (string)$hVal;
            break;
        }
    }
}

$body = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw) && strlen($raw) <= 262144) { // 256 KB cheklov — sozlamalar/shikoyat ko'rib chiqish uchun yetarli
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
}

try {
    $router = new MiniAppApiRouter();
    $result = $router->handle($action, $_GET, $body, (string)$initData);
} catch (Throwable $e) {
    Logger::error("Mini App API kirish nuqtasida kutilmagan xatolik: " . $e->getMessage(), [
        'action' => $action,
        'trace' => $e->getTraceAsString(),
    ], 'miniapp');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    exit;
}

$httpStatus = (int)($result['http_status'] ?? 200);
unset($result['http_status']);
http_response_code($httpStatus);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
