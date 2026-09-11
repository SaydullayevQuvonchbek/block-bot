<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\AI\AIClientFactory;
use App\Core\Config;
use App\Core\Database;
use App\Core\TelegramClient;
use App\Policy\AdminNotificationService;

Config::load();

echo "\n=========================================================\n";
echo "🛡 Block-BOT: Tizim Diagnostikasi va Muhit Tekshiruvi\n";
echo "=========================================================\n\n";

$allPassed = true;
$envFile = dirname(__DIR__) . '/.env';
echo "[0] Konfiguratsiya (.env): ";
if (is_file($envFile) && is_readable($envFile)) {
    echo "✅ Mavjud\n";
} else {
    echo "❌ Topilmadi. .env.example dan .env yarating.\n";
    $allPassed = false;
}

// 1. PHP Versiyasi tekshiruvi
echo "[1] PHP Versiyasi: " . PHP_VERSION . " ... ";
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    echo "✅ [MOS] (Tavsiya: PHP 8.3+)\n";
} else {
    echo "❌ [XATO] PHP kamida 8.1 bo'lishi shart!\n";
    $allPassed = false;
}

// 2. Majburiy PHP kengaytmalari
echo "[2] PHP Kengaytmalari tekshiruvi:\n";
$requiredExts = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'json', 'gd'];
foreach ($requiredExts as $ext) {
    echo "  • {$ext}: ";
    if (extension_loaded($ext)) {
        echo "✅ Mavjud\n";
    } else {
        echo "❌ Yetishmayapti!\n";
        $allPassed = false;
    }
}

// 3. Ma'lumotlar bazasi tekshiruvi
echo "\n[3] MySQL Ma'lumotlar Bazasi: ";
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $tableCount = (int)$stmt->fetchColumn();
    echo "✅ Ulandi (Mavjud jadvallar: {$tableCount})\n";
    if ($tableCount < 20) {
        echo "  ❌ Sxema to'liq emas; php bin/migrate.php ni bajaring.\n";
        $allPassed = false;
    }
} catch (Throwable $e) {
    echo "❌ Xatolik: " . $e->getMessage() . "\n";
    $allPassed = false;
}

// 4. Telegram Bot API tekshiruvi
echo "\n[4] Telegram Bot API: ";
$telegram = new TelegramClient();
$baseApiUrl = $telegram->getBaseApiUrl();
$proxy = Config::get('TELEGRAM_PROXY');

if ($baseApiUrl !== 'https://api.telegram.org') {
    echo "🌐 Proksi orqali: {$baseApiUrl}\n  ";
}
if (!empty($proxy)) {
    echo "🔌 SOCKS5/HTTP proksi: {$proxy}\n  ";
}

if ($telegram->isConfigured()) {
    $botInfo = $telegram->getMe();
    if ($botInfo['ok'] ?? false) {
        $user = $botInfo['result'];
        echo "✅ Ulandi! Bot: @{$user['username']} (ID: {$user['id']})\n";
    } else {
        $desc = (string)($botInfo['description'] ?? 'noma\'lum');
        echo "❌ Ulanish xatosi: {$desc}\n";
        if (str_contains(strtolower($desc), 'curl') || str_contains(strtolower($desc), 'timed out') || str_contains(strtolower($desc), 'connection')) {
            echo "  ⚠️ Reg.ru yoki provayder Telegram API'ni bloklayotganga o'xshaydi!\n";
            echo "  💡 Yechim: docs/CLOUDFLARE_REG_RU_SETUP.md faylidagi qo'llanma bo'yicha Cloudflare Worker proksisini sozlang.\n";
        }
        $allPassed = false;
    }
} else {
    echo "❌ Token kiritilmagan yoki formati noto'g'ri\n";
    $allPassed = false;
}

$webhookUrl = (string)Config::get('TELEGRAM_WEBHOOK_URL', '');
$webhookSecret = (string)Config::get('TELEGRAM_WEBHOOK_SECRET', '');
if (!str_starts_with($webhookUrl, 'https://')) {
    echo "  ❌ TELEGRAM_WEBHOOK_URL haqiqiy https:// manzil bo'lishi kerak\n";
    $allPassed = false;
}
if (strlen($webhookSecret) < 32 || str_contains($webhookSecret, 'your_super_secret')) {
    echo "  ❌ TELEGRAM_WEBHOOK_SECRET kamida 32 belgili haqiqiy maxfiy qiymat bo'lishi kerak\n";
    $allPassed = false;
}
$ownerIds = AdminNotificationService::ownerIds();
if ($ownerIds === []) {
    echo "  ℹ️ TELEGRAM_OWNER_IDS kiritilmagan (ixtiyoriy platforma superadmini)\n";
} else {
    echo "  ✅ Platforma superadministratorlari: " . count($ownerIds) . " ta\n";
}

// 5. Tanlangan AI provider sozlamalari
$aiProvider = AIClientFactory::providerName();
$aiProviderLabel = $aiProvider === 'gemini' ? 'Google Gemini' : 'OpenRouter';
echo "\n[5] {$aiProviderLabel} AI Tahlili: ";
$aiClient = AIClientFactory::create();
if ($aiClient->isConfigured()) {
    $modelText = $aiProvider === 'gemini'
        ? Config::get('GEMINI_TEXT_MODEL', 'gemini-2.5-flash')
        : Config::get('OPENROUTER_TEXT_MODEL', 'google/gemini-2.5-flash');
    $modelVision = $aiProvider === 'gemini'
        ? Config::get('GEMINI_VISION_MODEL', 'gemini-2.5-flash')
        : Config::get('OPENROUTER_VISION_MODEL', 'google/gemini-2.5-flash');
    echo "✅ Sozlangan!\n";
    echo "  • Provider: {$aiProviderLabel}\n";
    echo "  • Matn modeli: {$modelText}\n";
    echo "  • Vision modeli: {$modelVision}\n";
    if ($aiProvider === 'gemini') {
        $geminiKey = trim((string)Config::get('GEMINI_API_KEY', ''));
        $keyType = str_starts_with($geminiKey, 'AQ.')
            ? 'yangi Google Auth key (AQ.)'
            : (str_starts_with($geminiKey, 'AIza')
                ? 'eski Google Standard key (AIza)'
                : "noma'lum format");
        echo "  • API kalit turi: {$keyType}; uzunligi: " . strlen($geminiKey) . " belgi\n";
        if ($keyType === "noma'lum format") {
            echo "    ⚠️ Kalit AQ. yoki AIza bilan boshlanishi kutiladi; kalitning to'liq qiymatini hech kimga yubormang\n";
        }
    }

    // Agar CLI argumentda --test-ai berilgan bo'lsa pullik matn sinovi yuboramiz
    if (in_array('--test-ai', $argv, true)) {
        echo "  • Pullik sinov so'rovi yuborilmoqda (Taxminiy xarajat: ~$0.00002)...\n";
        $res = $aiClient->moderateText("Salom, bu sinov xabari.", "diag_test");
        echo "    Natija: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
        if (!in_array(($res['status'] ?? ''), ['safe', 'unsafe'], true)) {
            $allPassed = false;
        }
    } else {
        echo "  • Eslatma: Haqiqiy pullik AI so'rovini sinash uchun: php bin/diagnose.php --test-ai\n";
    }

    // Vision modelni Telegramdan mustaqil tekshirish. Ixtiyoriy ravishda rasm yo'lini
    // --test-vision=/to/liq/rasm.jpg shaklida berish mumkin.
    $visionRequested = false;
    $visionPath = null;
    foreach ($argv as $arg) {
        if ($arg === '--test-vision') {
            $visionRequested = true;
        } elseif (str_starts_with($arg, '--test-vision=')) {
            $visionRequested = true;
            $visionPath = substr($arg, strlen('--test-vision='));
        }
    }

    if ($visionRequested) {
        $generatedTestImage = false;
        if ($visionPath === null || $visionPath === '') {
            $visionPath = dirname(__DIR__) . '/storage/temp/diagnose_vision_test.png';
            $img = imagecreatetruecolor(320, 180);
            $background = imagecolorallocate($img, 242, 247, 252);
            $foreground = imagecolorallocate($img, 25, 55, 85);
            imagefilledrectangle($img, 0, 0, 319, 179, $background);
            imagestring($img, 5, 92, 82, 'VISION TEST', $foreground);
            imagepng($img, $visionPath);
            imagedestroy($img);
            $generatedTestImage = true;
        }

        if (!is_file($visionPath) || !is_readable($visionPath)) {
            echo "  • Vision sinovi: ❌ Rasm topilmadi yoki o'qib bo'lmaydi: {$visionPath}\n";
            $allPassed = false;
        } else {
            echo "  • Vision modelga rasm yuborilmoqda (pullik sinov)...\n";
            $visionResult = $aiClient->moderateImage($visionPath, 'Oddiy diagnostika rasmi', 'diag_vision');
            echo "    Natija: " . json_encode($visionResult, JSON_UNESCAPED_UNICODE) . "\n";
            if (!in_array(($visionResult['status'] ?? ''), ['safe', 'unsafe'], true)) {
                $allPassed = false;
            }
        }

        if ($generatedTestImage && is_file($visionPath)) {
            @unlink($visionPath);
        }
    } else {
        echo "  • Vision sinovi: php bin/diagnose.php --test-vision\n";
        echo "    O'z rasmingiz bilan: php bin/diagnose.php --test-vision=/to'liq/rasm.jpg\n";
    }
} else {
    $requiredKey = $aiProvider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENROUTER_API_KEY';
    echo "⚠️ {$requiredKey} kiritilmagan (Mahalliy deterministik filtrlar ishlaydi)\n";
    if (Config::get('AI_DEFAULT_MODE', 'comprehensive') !== 'disabled') {
        $allPassed = false;
    }
}

// 6. FFmpeg tekshiruvi
echo "\n[6] FFmpeg (Video/GIF kadr tahlili): ";
$ffmpegPath = Config::get('FFMPEG_PATH', 'ffmpeg');
$code = 1;
@exec(escapeshellarg((string)$ffmpegPath) . " -version 2>&1", $out, $code);
if ($code === 0) {
    echo "✅ O'rnatilgan va faol\n";
} else {
    echo "⚠️ Topilmadi; video/GIF uchun Telegram muqovasi AI fallback sifatida tekshiriladi\n";
}

// 7. Navbat (Queue) va saqlash papkalari
echo "\n[7] Papkalar va huquqlar:\n";
$dirs = [
    'storage/logs' => dirname(__DIR__) . '/storage/logs',
    'storage/temp' => dirname(__DIR__) . '/storage/temp',
    'storage/sessions' => dirname(__DIR__) . '/storage/sessions',
];
foreach ($dirs as $name => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    $isWritable = is_writable($path);
    echo "  • {$name}: " . ($isWritable ? "✅ Yozish mumkin" : "❌ Yozib bo'lmaydi!") . "\n";
    if (!$isWritable) $allPassed = false;
}

echo "\n=========================================================\n";
if ($allPassed) {
    echo "🎉 TIZIM ISHGA TUSHISHGA TO'LIQ TAYYOR!\n";
} else {
    echo "⚠️ Ba'zi talablar bajarilmagan. Yuqoridagi xabarlarni ko'rib chiqing.\n";
}
echo "=========================================================\n\n";
exit($allPassed ? 0 : 1);
