<?php

declare(strict_types=1);

/**
 * Yengil, autentifikatsiyasiz HTTP salomatlik endpointi (2.0 Phase 2: health-check/
 * observability). Uptime-monitoring xizmatlari (UptimeRobot, DigitalOcean Monitoring
 * va h.k.) shu manzilga muntazam GET so'rov yuborishi mumkin: sog'lom bo'lsa HTTP 200,
 * muammo bo'lsa HTTP 503 qaytaradi. Hech qanday maxfiy ma'lumot (token, kalit, xato
 * stack-trace) qaytarilmaydi — faqat holat va sanoq (masalan, navbatdagi vazifalar soni).
 *
 * Tashqi tarmoq so'rovi yuborilmaydi (Telegram/AI API'ga murojaat qilinmaydi) —
 * `App\Core\HealthCheck` faqat mahalliy DB va fayl holatini o'qiydi, shuning uchun
 * tez-tez chaqirilsa ham xavfsiz va tez javob beradi.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\HealthCheck;

Config::load();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = HealthCheck::runChecks();

http_response_code($result['healthy'] ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
