<?php

declare(strict_types=1);

/**
 * Block-BOT: bir buyruqli to'liq nosozlik tashxisi va avto-tuzatish.
 *
 *   php bin/doctor.php               -> faqat tekshiradi va hisobot beradi
 *   php bin/doctor.php --fix         -> yetishmayotgan migratsiyani bajaradi
 *   php bin/doctor.php --set-webhook -> webhookni .env TELEGRAM_WEBHOOK_URL ga qayta o'rnatadi
 *
 * Hech qanday maxfiy qiymat to'liq chop etilmaydi (token/secret maskalanadi).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\TelegramClient;
use App\Http\UpdateRouter;
use App\Policy\AdminNotificationService;

Config::load();

$fix = in_array('--fix', $argv, true);
$setWebhook = in_array('--set-webhook', $argv, true);
$problems = [];
$warnings = [];

function line(string $s = ''): void { echo $s . "\n"; }
function mask(string $s): string {
    $s = trim($s);
    if ($s === '') return '(bo\'sh)';
    return strlen($s) <= 8 ? '****' : substr($s, 0, 4) . '…' . substr($s, -3) . ' (' . strlen($s) . ' belgi)';
}
function raw_get(string $url, array $headers = [], int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'error' => $err];
}
function raw_post(string $url, string $json, array $headers, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'error' => $err];
}

line("=========================================================");
line("🩺 Block-BOT: Doctor — nosozlik tashxisi" . ($fix ? " (--fix rejimi)" : ""));
line("=========================================================\n");

// -----------------------------------------------------------------------------
// 1. .env asosiy qiymatlari
// -----------------------------------------------------------------------------
$token       = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
$botUsername = (string)Config::get('TELEGRAM_BOT_USERNAME', '');
$webhookUrl  = (string)Config::get('TELEGRAM_WEBHOOK_URL', '');
$webhookSec  = (string)Config::get('TELEGRAM_WEBHOOK_SECRET', '');
$apiBase     = rtrim((string)Config::get('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'), '/');
$proxySecret = (string)Config::get('TELEGRAM_PROXY_SECRET', '');
$appEnv      = (string)Config::get('APP_ENV', 'production');
$usingProxy  = $apiBase !== '' && $apiBase !== 'https://api.telegram.org';

line("[1] .env qiymatlari");
line("  • APP_ENV              : {$appEnv}");
line("  • TELEGRAM_BOT_TOKEN   : " . mask($token));
line("  • TELEGRAM_BOT_USERNAME: " . ($botUsername ?: '(bo\'sh)'));
line("  • TELEGRAM_WEBHOOK_URL : " . ($webhookUrl ?: '(bo\'sh)'));
line("  • TELEGRAM_WEBHOOK_SECRET: " . mask($webhookSec));
line("  • TELEGRAM_API_BASE_URL: {$apiBase}" . ($usingProxy ? "  → PROKSI rejimi" : "  → to'g'ridan-to'g'ri"));
line("  • TELEGRAM_PROXY_SECRET: " . mask($proxySecret));

if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token)) {
    $problems[] = "TELEGRAM_BOT_TOKEN yaroqsiz yoki bo'sh.";
}
if (!str_starts_with($webhookUrl, 'https://')) {
    $problems[] = "TELEGRAM_WEBHOOK_URL https:// bilan boshlanmadi.";
}
if (strlen($webhookSec) < 32) {
    $problems[] = "TELEGRAM_WEBHOOK_SECRET 32 belgidan qisqa (production'da webhook 503 qaytaradi).";
}
if ($usingProxy && $proxySecret === '') {
    $warnings[] = "Proksi ishlatyapsiz, lekin TELEGRAM_PROXY_SECRET bo'sh. Worker Settings'da PROXY_SECRET bo'lsa, bu yerda ham bir xil bo'lishi kerak.";
}
line();

// -----------------------------------------------------------------------------
// 2. Chiquvchi trafik: to'g'ridan-to'g'ri vs proksi
// -----------------------------------------------------------------------------
line("[2] Chiquvchi (outbound) trafik — bot javob bera oladimi?");
$directRes = raw_get("https://api.telegram.org/bot{$token}/getMe");
$directOk = $directRes['code'] === 200 && str_contains($directRes['body'], '"ok":true');
line("  • To'g'ridan-to'g'ri api.telegram.org : " . ($directOk ? "✅ ochiq" : "❌ yopiq/ishlamadi (HTTP {$directRes['code']} {$directRes['error']})"));

$proxyOk = null;
if ($usingProxy) {
    $ph = $proxySecret !== '' ? ["X-Proxy-Secret: {$proxySecret}"] : [];
    $proxyRes = raw_get("{$apiBase}/bot{$token}/getMe", $ph);
    $proxyOk = $proxyRes['code'] === 200 && str_contains($proxyRes['body'], '"ok":true');
    line("  • Proksi orqali ({$apiBase}) : " . ($proxyOk ? "✅ ishlaydi" : "❌ ishlamadi (HTTP {$proxyRes['code']} {$proxyRes['error']}) — javob: " . substr($proxyRes['body'], 0, 160)));
}

if (!$directOk && !$usingProxy) {
    $problems[] = "Host api.telegram.org ni bloklaydi VA .env da proksi ko'rsatilmagan → bot HECH QACHON javob bera olmaydi. TELEGRAM_API_BASE_URL ni Cloudflare Worker manziliga o'zgartiring.";
}
if (!$directOk && $usingProxy && $proxyOk === false) {
    $problems[] = "Host to'g'ridan-to'g'ri bloklangan, proksi ham ishlamadi. Worker deploy qilinganini va PROXY_SECRET mosligini tekshiring.";
}
if ($directOk && $usingProxy && $proxyOk) {
    $warnings[] = "Ham to'g'ridan-to'g'ri, ham proksi ishlaydi. Host bloklamayotgan bo'lsa proksisiz ham bo'ladi, lekin hozirgi holat ham to'g'ri.";
}

// Klientni haqiqiy sozlama bilan sinash
$telegram = new TelegramClient();
$me = $telegram->getMe();
$meOk = (bool)($me['ok'] ?? false);
line("  • TelegramClient (real sozlama) getMe: " . ($meOk
    ? "✅ @" . ($me['result']['username'] ?? '?')
    : "❌ " . ($me['description'] ?? 'xato')));
if (!$meOk) {
    $problems[] = "Bot o'zining sozlamalari bilan Telegram API ga ulana olmadi → javoblar ketmaydi. [2] natijalariga qarang.";
}
line();

// -----------------------------------------------------------------------------
// 3. AI provider ulanishi (Google Gemini ba'zi mintaqalar IP'laridan kelgan
//    so'rovlarni "User location is not supported" bilan rad etadi — shu holatda
//    rasm/video va AI matn tahlili SAFE emas, REVIEW_ONLY bo'lib qoladi va
//    hech qanday chora ko'rilmaydi).
// -----------------------------------------------------------------------------
line("[3] AI provider ulanishi");
$aiProviderRaw = strtolower(trim((string)Config::get('AI_PROVIDER', 'openrouter')));
$aiProvider = in_array($aiProviderRaw, ['gemini', 'google', 'google_gemini'], true) ? 'gemini' : 'openrouter';
line("  • AI_PROVIDER: {$aiProvider}");

if ($aiProvider === 'gemini') {
    $geminiKey = trim((string)Config::get('GEMINI_API_KEY', ''));
    $geminiBase = rtrim((string)Config::get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $geminiUsingProxy = $geminiBase !== 'https://generativelanguage.googleapis.com/v1beta';
    line("  • GEMINI_BASE_URL: {$geminiBase}" . ($geminiUsingProxy ? " → PROKSI" : " → to'g'ridan-to'g'ri"));
    if ($geminiKey === '' || str_contains(strtolower($geminiKey), 'your_')) {
        $problems[] = "GEMINI_API_KEY sozlanmagan — AI matn/rasm tahlili ishlamaydi (faqat mahalliy qat'iy qoidalar ishlaydi).";
    } else {
        $geminiHeaders = ["x-goog-api-key: {$geminiKey}"];
        if ($proxySecret !== '') $geminiHeaders[] = "X-Proxy-Secret: {$proxySecret}";
        $gemRes = raw_get("{$geminiBase}/models?pageSize=1", $geminiHeaders);
        $gemOk = $gemRes['code'] === 200;
        line("  • Gemini API sinovi (models ro'yxati): HTTP {$gemRes['code']} " . ($gemOk ? "✅" : "❌"));
        if (!$gemOk) {
            if (stripos($gemRes['body'], 'location is not supported') !== false) {
                $problems[] = "Google Gemini API bu server IP-manzili joylashgan mintaqadan so'rovlarni RAD ETADI (\"User location is not supported\") → rasm/video va AI matn tahlili ISHLAMAYDI, bloklanmagan 18+/pornografik kontent o'tib ketadi! YECHIM: deploy/cloudflare-worker/telegram-proxy.js ning yangi versiyasini Worker'ga qayta joylang (endi /gemini yo'lini ham qo'llab-quvvatlaydi), so'ng .env da GEMINI_BASE_URL={$apiBase}/gemini/v1beta qiling. Muqobil: AI_PROVIDER=openrouter ga o'tish (OpenRouter server IP-manzilingizdan emas, o'zining serveridan Google'ga murojaat qiladi).";
            } else {
                $warnings[] = "Gemini API kutilmagan javob berdi (HTTP {$gemRes['code']}): " . substr(trim($gemRes['body']), 0, 150);
            }
        }
    }
} else {
    $orKey = trim((string)Config::get('OPENROUTER_API_KEY', ''));
    $orBase = rtrim((string)Config::get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/');
    line("  • OPENROUTER_BASE_URL: {$orBase}");
    if ($orKey === '' || str_contains($orKey, 'xxxxxxxx')) {
        $problems[] = "OPENROUTER_API_KEY sozlanmagan — AI matn/rasm tahlili ishlamaydi.";
    } else {
        $orRes = raw_get("{$orBase}/models", $proxySecret !== '' ? ["X-Proxy-Secret: {$proxySecret}"] : []);
        line("  • OpenRouter API sinovi: HTTP {$orRes['code']} " . ($orRes['code'] === 200 ? "✅" : "❌"));
        if ($orRes['code'] !== 200) {
            $warnings[] = "OpenRouter API javob bermadi (HTTP {$orRes['code']}): " . substr(trim($orRes['body']), 0, 150);
        }
    }
}

// Google Vision SafeSearch — ixtiyoriy zaxira tekshiruv (sozlanmagan bo'lsa chetlab o'tiladi).
$visionKey = trim((string)Config::get('GOOGLE_VISION_API_KEY', ''));
if ($visionKey !== '' && !str_contains(strtolower($visionKey), 'your_')) {
    $visionBase = rtrim((string)Config::get('GOOGLE_VISION_BASE_URL', 'https://vision.googleapis.com/v1'), '/');
    line("  • GOOGLE_VISION_BASE_URL: {$visionBase}");
    // 1x1 shaffof PNG — bepul/arzon sinov so'rovi.
    $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $visionPayload = json_encode(['requests' => [['image' => ['content' => base64_encode($tinyPng)], 'features' => [['type' => 'SAFE_SEARCH_DETECTION']]]]]);
    $visionHeaders = $proxySecret !== '' ? ["X-Proxy-Secret: {$proxySecret}"] : [];
    $visRes = raw_post("{$visionBase}/images:annotate?key={$visionKey}", $visionPayload, $visionHeaders);
    $visOk = $visRes['code'] === 200 && str_contains($visRes['body'], 'safeSearchAnnotation');
    line("  • Google Vision SafeSearch sinovi: HTTP {$visRes['code']} " . ($visOk ? "✅" : "❌"));
    if (!$visOk) {
        $warnings[] = "Google Vision SafeSearch javob bermadi (HTTP {$visRes['code']}): " . substr(trim($visRes['body']), 0, 150) . ". Zaxira tekshiruv ishlamaydi, lekin asosiy AI provider ta'sirlanmaydi.";
    }
} else {
    line("  • Google Vision SafeSearch: sozlanmagan (ixtiyoriy zaxira tekshiruv o'chiq)");
}
line();

// -----------------------------------------------------------------------------
// 4. Ma'lumotlar bazasi va migratsiya
// -----------------------------------------------------------------------------
line("[4] Ma'lumotlar bazasi va sxema");
$pdo = null;
try {
    $pdo = Database::getConnection();
    line("  • Ulanish: ✅");
} catch (Throwable $e) {
    $problems[] = "Bazaga ulanib bo'lmadi: " . $e->getMessage();
    line("  • Ulanish: ❌ " . $e->getMessage());
}

$missingCols = [];
if ($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $need = [
        'group_settings' => ['bot_filter', 'adult_account_action', 'history_cleanup_enabled'],
        'audit_items'    => ['cleanup_status', 'cleanup_at'],
    ];
    foreach ($need as $table => $cols) {
        try {
            if ($driver === 'sqlite') {
                $have = array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(), 'name');
            } else {
                $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
                $st->execute(['t' => $table]);
                $have = array_column($st->fetchAll(), 'COLUMN_NAME');
            }
            foreach ($cols as $c) {
                if (!in_array($c, $have, true)) {
                    $missingCols[] = "{$table}.{$c}";
                }
            }
        } catch (Throwable $e) {
            $problems[] = "Jadval tekshiruvi xatosi ({$table}): " . $e->getMessage();
        }
    }
    if ($missingCols === []) {
        line("  • Migratsiya 003 ustunlari: ✅ mavjud");
    } else {
        line("  • Migratsiya 003 ustunlari: ❌ YETISHMAYDI → " . implode(', ', $missingCols));
        $problems[] = "003_history_cleanup.sql migratsiyasi bajarilmagan (" . implode(', ', $missingCols) . "). Sozlama yozilganda bot 500 qaytaradi.";
        if ($fix) {
            line("  • --fix: migratsiya ishga tushirilmoqda...");
            $out = [];
            $code = 1;
            @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/migrate.php') . ' 2>&1', $out, $code);
            line("    " . implode("\n    ", $out));
            $problems[] = ($code === 0)
                ? "[TUZATILDI] migratsiya bajarildi — doctor'ni qayta ishga tushiring."
                : "[XATO] migratsiya bajarilmadi (exit {$code}). Qo'lda: php bin/migrate.php";
        }
    }

    // /blockword, /allowword, /wordlist shu jadvalga yozadi — mavjudligini va
    // yozish huquqini haqiqiy INSERT/DELETE bilan tekshiramiz (faqat o'qishni emas).
    try {
        $testChatId = -999000111;
        $testWord = 'doctor_test_' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, 'blacklist', :p, 0, :now)")
            ->execute(['cid' => $testChatId, 'p' => $testWord, 'now' => $now]);
        $check = $pdo->prepare("SELECT id FROM word_rules WHERE chat_id = :cid AND word_pattern = :p");
        $check->execute(['cid' => $testChatId, 'p' => $testWord]);
        $found = (bool)$check->fetch();
        $pdo->prepare("DELETE FROM word_rules WHERE chat_id = :cid AND word_pattern = :p")->execute(['cid' => $testChatId, 'p' => $testWord]);
        line("  • word_rules jadvali (/blockword, /wordlist): " . ($found ? "✅ yozish/o'qish ishlaydi" : "❌ yozildi, lekin o'qib bo'lmadi"));
        if (!$found) {
            $problems[] = "word_rules jadvaliga yozish mumkin, lekin qayta o'qib bo'lmadi — DB replikatsiya/keshlash muammosi bo'lishi mumkin.";
        }
    } catch (Throwable $e) {
        line("  • word_rules jadvali: ❌ " . $e->getMessage());
        $problems[] = "/blockword, /allowword, /wordlist ishlamaydi — word_rules jadvali bilan muammo: " . $e->getMessage() . ". `php bin/migrate.php` ni qayta bajaring.";
    }
}
line();

// -----------------------------------------------------------------------------
// 4. Webhook holati
// -----------------------------------------------------------------------------
line("[5] Webhook holati (Telegram tomonidan ko'rinishi)");
$info = $telegram->getWebhookInfo();
if ($info['ok'] ?? false) {
    $r = $info['result'];
    $registered = (string)($r['url'] ?? '');
    line("  • Ro'yxatdagi URL      : " . ($registered ?: '(o\'rnatilmagan)'));
    line("  • Kutilayotgan update  : " . ($r['pending_update_count'] ?? 0));
    line("  • Allowed updates      : " . implode(', ', (array)($r['allowed_updates'] ?? [])));
    if (!empty($r['last_error_message'])) {
        $when = !empty($r['last_error_date']) ? date('Y-m-d H:i:s', (int)$r['last_error_date']) : '?';
        line("  • ⚠️ SO'NGGI XATO ({$when}): " . $r['last_error_message']);
        $problems[] = "Telegram webhookdan xato oldi: \"" . $r['last_error_message'] . "\" — bu Worker yoki webhook.php javobidagi muammo.";
    } else {
        line("  • So'nggi xato         : yo'q ✅");
    }
    $need = ['message', 'edited_message', 'callback_query', 'my_chat_member', 'chat_member'];
    $missUpd = array_diff($need, (array)($r['allowed_updates'] ?? []));
    if ($missUpd !== []) {
        $warnings[] = "Webhook allowed_updates da yetishmaydi: " . implode(', ', $missUpd) . " (chat_member bo'lmasa yangi a'zo skani ishlamaydi).";
    }
    if ($registered === '') {
        $problems[] = "Webhook umuman o'rnatilmagan.";
    } elseif ($webhookUrl !== '' && $registered !== $webhookUrl && !$usingProxy) {
        $warnings[] = "Ro'yxatdagi webhook URL .env dagi TELEGRAM_WEBHOOK_URL bilan mos emas.";
    }
} else {
    $problems[] = "getWebhookInfo ishlamadi: " . ($info['description'] ?? 'xato');
    line("  • ❌ " . ($info['description'] ?? 'xato'));
}
line();

// -----------------------------------------------------------------------------
// 5. Kiruvchi zanjir: Telegram AYNAN ro'yxatdagi URL ga POST qiladi.
//    Shu URL Block-BOT webhook.php javobini qaytarishi SHART.
// -----------------------------------------------------------------------------
line("[6] Kiruvchi (inbound) zanjir");
$probeId = random_int(900000000, 999999999);
$probe = json_encode(['update_id' => $probeId]);
$secHdr = $webhookSec !== '' ? ["X-Telegram-Bot-Api-Secret-Token: {$webhookSec}"] : [];

// Block-BOT webhook.php ning haqiqiy javobi shu ko'rinishda bo'ladi.
$looksLikeBot = static fn (string $b): bool =>
    str_contains($b, '"ok":true') && str_contains($b, '"result"')
    && !str_contains($b, 'Two-Way Telegram Proxy');

$registeredForTest = ($info['ok'] ?? false) ? (string)($info['result']['url'] ?? '') : $webhookUrl;
if (str_starts_with($registeredForTest, 'https://')) {
    $regRes = raw_post($registeredForTest, $probe, $secHdr);
    $regOk = $regRes['code'] === 200 && $looksLikeBot($regRes['body']);
    line("  • Telegram POST qiladigan URL ({$registeredForTest}):");
    line("      HTTP {$regRes['code']} " . ($regOk ? "✅ Block-BOT javob berdi" : "❌") . "  " . substr(trim(preg_replace('/\s+/', ' ', $regRes['body'])), 0, 150));

    if (!$regOk) {
        if (str_contains($regRes['body'], 'Two-Way Telegram Proxy')) {
            // Worker POST /  ni webhook deb hisoblamayapti — health-check qaytaryapti.
            $altOk = false;
            foreach (['/webhook', '/webhook.php'] as $p) {
                $alt = raw_post(rtrim($registeredForTest, '/') . $p, $probe, $secHdr);
                if ($alt['code'] === 200 && $looksLikeBot($alt['body'])) {
                    line("      → lekin {$registeredForTest}{$p} TO'G'RI ishlaydi ✅");
                    $problems[] = "Telegram webhookni ro'yxatga olgan URL ({$registeredForTest}) Cloudflare Worker health-check sahifasini qaytaryapti — real xabarlar botga YETMAYDI. YECHIM: .env da TELEGRAM_WEBHOOK_URL={$registeredForTest}{$p} qiling va `php bin/doctor.php --set-webhook` bajaring (yoki Worker kodini deploy/cloudflare-worker/telegram-proxy.js bilan yangilang).";
                    $altOk = true;
                    break;
                }
            }
            if (!$altOk) {
                $problems[] = "Ro'yxatdagi webhook URL bot javobini qaytarmayapti (Worker health-check). Worker `TARGET_WEBHOOK_URL` ni tekshiring yoki webhookni to'g'ri path bilan qayta o'rnating.";
            }
        } elseif ($regRes['code'] === 403) {
            $problems[] = "Webhook URL 403 qaytardi — .env TELEGRAM_WEBHOOK_SECRET setWebhook dagi secret bilan mos emas.";
        } elseif ($regRes['code'] === 503) {
            $problems[] = "Webhook URL 503 — production'da secret 32 belgidan qisqa yoki serverda .env yo'q.";
        } elseif ($regRes['code'] === 404) {
            $problems[] = "Webhook URL 404 — DocumentRoot public/ ga qaratilmagan yoki .htaccess rewrite o'chiq, yoki Worker TARGET_WEBHOOK_URL xato.";
        } elseif ($regRes['code'] === 500 && str_contains($regRes['body'], 'TARGET_WEBHOOK_URL')) {
            $problems[] = "Cloudflare Worker'da TARGET_WEBHOOK_URL o'rnatilmagan → xabarlar serverga yetmaydi. Worker → Settings → Variables → TARGET_WEBHOOK_URL = https://<sizning-domen>/webhook.php";
        } elseif ($regRes['code'] === 502) {
            $problems[] = "Worker serverga ulana olmadi (502) — TARGET_WEBHOOK_URL noto'g'ri yoki origin javob bermayapti.";
        } elseif ($regRes['code'] >= 500) {
            $problems[] = "Webhook URL {$regRes['code']} — PHP/baza fatal xatosi. storage/logs/app.log ni ko'ring.";
        } else {
            $problems[] = "Webhook URL kutilgan bot javobini qaytarmadi (HTTP {$regRes['code']}). Javob: " . substr(trim($regRes['body']), 0, 120);
        }
    } else {
        line("      ✅ Telegram → (Worker) → webhook.php → Block-BOT zanjiri to'liq ishlaydi.");
    }
} else {
    $problems[] = "Webhook ro'yxatga olinmagan — `php bin/doctor.php --set-webhook` bajaring.";
}
line();

// -----------------------------------------------------------------------------
// 6. Haqiqiy update Telegram tomonidan yetkazilyaptimi?
// -----------------------------------------------------------------------------
line("[7] So'nggi kelgan update'lar (updates_log — doctor testlari ham kiradi)");
if ($pdo) {
    try {
        $row = $pdo->query("SELECT COUNT(*) c, MAX(created_at) m FROM updates_log")->fetch();
        $cnt = (int)($row['c'] ?? 0);
        $last = $row['m'] ?? null;
        line("  • Jami: {$cnt}, oxirgisi: " . ($last ?: 'yo\'q'));

        // 'unknown' = doctor/test probe; qolganlari haqiqiy Telegram trafik.
        $types = $pdo->query("SELECT update_type, COUNT(*) c FROM updates_log GROUP BY update_type ORDER BY c DESC")->fetchAll();
        $realTypes = array_filter($types, static fn ($t) => $t['update_type'] !== 'unknown');
        $realCount = array_sum(array_column($realTypes, 'c'));
        line("  • Turlari: " . implode(', ', array_map(static fn ($t) => "{$t['update_type']}={$t['c']}", $types)));
        line("  • Haqiqiy Telegram update'lari (test emas): {$realCount}");

        if ($realCount === 0) {
            $problems[] = "updates_log da faqat doctor test yozuvlari bor, HAQIQIY Telegram xabari YO'Q. Demak Telegram POST'lari botga yetmayapti (ehtimol [5] dagi webhook URL/path muammosi) YOKI botga hali hech kim yozmagan / bot guruhga qo'shilmagan.";
        } elseif ($last && (time() - strtotime((string)$last . ' UTC')) > 3600) {
            $warnings[] = "Oxirgi update 1 soatdан oldin kelgan — hozir xabarlar yetmayotган bo'lishi mumkin.";
        }
    } catch (Throwable $e) {
        line("  • ❌ " . $e->getMessage());
    }
}
line();

// -----------------------------------------------------------------------------
// 7. Navbat (queue) — cron/worker ishlayaptimi?
// -----------------------------------------------------------------------------
line("[8] Navbat holati (cron / worker)");
if ($pdo) {
    try {
        $q = $pdo->query("SELECT COUNT(*) c, MIN(available_at) oldest FROM queue_jobs WHERE reserved_at IS NULL")->fetch();
        $qc = (int)($q['c'] ?? 0);
        line("  • Kutayotgan vazifalar: {$qc}");
        if ($qc > 0 && !empty($q['oldest'])) {
            $age = time() - strtotime((string)$q['oldest'] . ' UTC');
            line("  • Eng eski vazifa yoshi: {$age} soniya");
            if ($age > 180) {
                $problems[] = "Navbatда {$qc} vazifa {$age} soniyadan beri turibdi → cron yoki worker ISHLAMAYAPTI. AI moderatsiyasi, media, profil skani va tozalash ishlamaydi.";
            }
        }
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn();
        if ($failed > 0) {
            $warnings[] = "failed_jobs da {$failed} ta yiqilgan vazifa bor — sabablarini ko'ring: SELECT queue, LEFT(exception,200) FROM failed_jobs ORDER BY id DESC LIMIT 5;";
        }
    } catch (Throwable $e) {
        line("  • ❌ " . $e->getMessage());
    }
}
line();

// -----------------------------------------------------------------------------
// 8. Ichki oqim sinovi (webhook.php ichidagi mantiq)
// -----------------------------------------------------------------------------
line("[9] Ichki oqim sinovi (UpdateRouter)");
try {
    $res = (new UpdateRouter())->handle(['update_id' => random_int(700000000, 799999999)]);
    line("  • UpdateRouter->handle(): ✅ " . json_encode($res, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $problems[] = "UpdateRouter ichida fatal xato: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine();
    line("  • ❌ " . $e->getMessage());
}
line();

// -----------------------------------------------------------------------------
// 9. --set-webhook: webhookni .env TELEGRAM_WEBHOOK_URL ga qayta o'rnatish
//    (PROKSI ishlatsangiz, TELEGRAM_WEBHOOK_URL = Worker manzili bo'lishi kerak!)
// -----------------------------------------------------------------------------
if ($setWebhook) {
    if ($meOk && str_starts_with($webhookUrl, 'https://') && strlen($webhookSec) >= 32) {
        line("[10] --set-webhook: webhook o'rnatilmoqda → {$webhookUrl}");
        $set = $telegram->setWebhook($webhookUrl, $webhookSec, ['message', 'edited_message', 'callback_query', 'my_chat_member', 'chat_member']);
        line("  • webhook: " . (($set['ok'] ?? false) ? "✅ o'rnatildi" : "❌ " . ($set['description'] ?? 'xato')));
        $c1 = $telegram->setMyCommands([
            ['command' => 'menu', 'description' => 'Bosh menyu'],
            ['command' => 'mygroups', 'description' => 'Mening guruhlarim'],
            ['command' => 'help', 'description' => 'Yordam'],
            ['command' => 'scan_members', 'description' => "A'zolarni tekshirish"],
            ['command' => 'audit', 'description' => 'Eski xabarlar auditi'],
        ], ['type' => 'all_private_chats']);
        $c2 = $telegram->setMyCommands([
            ['command' => 'settings', 'description' => 'Sozlamalar'],
            ['command' => 'status', 'description' => 'Bot holati'],
            ['command' => 'stats', 'description' => 'Statistika'],
            ['command' => 'scan_members', 'description' => "A'zolarni tekshirish"],
            ['command' => 'audit', 'description' => 'Eski xabarlar auditi'],
            ['command' => 'warn', 'description' => 'Ogohlantirish (reply)'],
            ['command' => 'mute', 'description' => 'Mute (reply)'],
            ['command' => 'ban', 'description' => 'Ban (reply)'],
            ['command' => 'blockword', 'description' => "Maxsus so'zni taqiqlash"],
            ['command' => 'wordlist', 'description' => "So'z qoidalari ro'yxati"],
        ], ['type' => 'all_chat_administrators']);
        line("  • buyruqlar menyusi: " . ((($c1['ok'] ?? false) && ($c2['ok'] ?? false)) ? "✅ o'rnatildi" : "❌"));
    } else {
        line("[10] --set-webhook: o'tkazib yuborildi (getMe/URL/secret shartlari bajarilmadi)");
    }
    line();
}

// -----------------------------------------------------------------------------
// 10. Owner'ga test xabari
// -----------------------------------------------------------------------------
$owners = AdminNotificationService::ownerIds();
if ($meOk && $owners !== []) {
    line("[11] Owner'ga test xabari (ID: {$owners[0]})");
    $sent = $telegram->sendMessage($owners[0], "🩺 Block-BOT doctor test xabari — " . gmdate('H:i:s') . " UTC. Buni ko'rsangiz, chiquvchi javoblar ishlaydi.");
    line("  • " . (($sent['ok'] ?? false) ? "✅ yuborildi — Telegram'da tekshiring" : "❌ " . ($sent['description'] ?? 'xato') . " (owner avval botga /start yozishi kerak)"));
    line();
}

// -----------------------------------------------------------------------------
// XULOSA
// -----------------------------------------------------------------------------
line("=========================================================");
if ($problems === []) {
    line("✅ Jiddiy muammo topilmadi.");
    if ($warnings === []) {
        line("Bot to'g'ri sozlangan ko'rinadi. Guruhda /status ni sinab ko'ring.");
    }
} else {
    line("❌ TOPILGAN MUAMMOLAR (" . count($problems) . "):\n");
    foreach ($problems as $i => $p) {
        line("  " . ($i + 1) . ". " . $p . "\n");
    }
}
if ($warnings !== []) {
    line("⚠️ OGOHLANTIRISHLAR:\n");
    foreach ($warnings as $i => $w) {
        line("  " . ($i + 1) . ". " . $w . "\n");
    }
}
line("=========================================================");
if (!$fix && $problems !== []) {
    line("Ba'zilarini avtomatik tuzatish uchun: php bin/doctor.php --fix");
}

exit($problems === [] ? 0 : 1);
