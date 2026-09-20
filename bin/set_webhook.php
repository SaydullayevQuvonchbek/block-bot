<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\TelegramClient;

Config::load();

$action = strtolower($argv[1] ?? 'info');
$telegram = new TelegramClient();

if (!$telegram->isConfigured()) {
    echo "❌ Xatolik: .env faylida TELEGRAM_BOT_TOKEN ko'rsatilmagan!\n";
    exit(1);
}

$applyCommands = static function (TelegramClient $telegram): void {
    $privateCmds = [
        ['command' => 'menu', 'description' => 'Bosh menyu'],
        ['command' => 'mygroups', 'description' => 'Mening guruhlarim'],
        ['command' => 'help', 'description' => 'Yordam'],
        ['command' => 'ai_usage', 'description' => 'AI xarajatlari'],
        ['command' => 'scan_members', 'description' => "A'zolarni 18+ / bot ga tekshirish"],
        ['command' => 'audit', 'description' => 'Eski xabarlar auditi'],
        ['command' => 'appeal', 'description' => 'Cheklovga shikoyat: /appeal ID'],
    ];
    $groupAdminCmds = [
        ['command' => 'settings', 'description' => 'Moderatsiya sozlamalari'],
        ['command' => 'status', 'description' => 'Bot holati'],
        ['command' => 'stats', 'description' => '30 kunlik statistika'],
        ['command' => 'scan_members', 'description' => "A'zolarni 18+ / bot ga tekshirish"],
        ['command' => 'audit', 'description' => 'Eski xabarlar auditi'],
        ['command' => 'warn', 'description' => 'Ogohlantirish (reply)'],
        ['command' => 'mute', 'description' => 'Vaqtincha cheklash (reply)'],
        ['command' => 'ban', 'description' => 'Guruhdan chetlatish (reply)'],
        ['command' => 'unmute', 'description' => 'Cheklovni yechish (reply)'],
        ['command' => 'unban', 'description' => 'Bandan chiqarish (reply)'],
        ['command' => 'blockword', 'description' => "Maxsus so'zni taqiqlash"],
        ['command' => 'allowword', 'description' => "Begunoh so'zni istisno qilish"],
        ['command' => 'unblockword', 'description' => "Maxsus qoidani o'chirish"],
        ['command' => 'wordlist', 'description' => "Maxsus so'z qoidalari ro'yxati"],
        ['command' => 'help', 'description' => 'Yordam'],
    ];
    $r1 = $telegram->setMyCommands($privateCmds, ['type' => 'all_private_chats']);
    $r2 = $telegram->setMyCommands($groupAdminCmds, ['type' => 'all_chat_administrators']);
    echo "  • Shaxsiy chat buyruqlari: " . (($r1['ok'] ?? false) ? "✅" : "❌ " . ($r1['description'] ?? '')) . "\n";
    echo "  • Guruh admin buyruqlari : " . (($r2['ok'] ?? false) ? "✅" : "❌ " . ($r2['description'] ?? '')) . "\n";
};

echo "=========================================================\n";
echo "🛡 Block-BOT: Telegram Webhook Boshqaruvi\n";
echo "=========================================================\n\n";

switch ($action) {
    case 'set':
        $url = Config::get('TELEGRAM_WEBHOOK_URL');
        $secret = Config::get('TELEGRAM_WEBHOOK_SECRET');

        if (empty($url) || !str_starts_with($url, 'https://')) {
            echo "❌ Xatolik: TELEGRAM_WEBHOOK_URL https:// bilan boshlanadigan to'liq domen bo'lishi shart!\n";
            exit(1);
        }
        if (!is_string($secret) || strlen($secret) < 32) {
            echo "❌ Xatolik: TELEGRAM_WEBHOOK_SECRET kamida 32 belgidan iborat bo'lishi shart!\n";
            exit(1);
        }

        $allowedUpdates = ['message', 'edited_message', 'callback_query', 'chat_member', 'my_chat_member', 'pre_checkout_query'];
        echo "Webhook o'rnatilmoqda: {$url} ...\n";
        $res = $telegram->setWebhook($url, $secret, $allowedUpdates);

        if ($res['ok'] ?? false) {
            echo "✅ Webhook muvaffaqiyatli o'rnatildi!\n";
            echo "Ruxsat etilgan update turlari: " . implode(', ', $allowedUpdates) . "\n";
            echo "Bot buyruqlar menyusi o'rnatilmoqda...\n";
            $applyCommands($telegram);
        } else {
            echo "❌ Xatolik: " . ($res['description'] ?? 'noma\'lum') . "\n";
        }
        break;

    case 'commands':
        echo "Bot buyruqlar menyusi o'rnatilmoqda...\n";
        $applyCommands($telegram);
        break;

    case 'delete':
        echo "Webhook o'chirilmoqda...\n";
        $res = $telegram->deleteWebhook(true);
        if ($res['ok'] ?? false) {
            echo "✅ Webhook o'chirildi (Kutilayotgan update'lar tozalandi).\n";
        } else {
            echo "❌ Xatolik: " . ($res['description'] ?? 'noma\'lum') . "\n";
        }
        break;

    case 'info':
    default:
        $res = $telegram->getWebhookInfo();
        if ($res['ok'] ?? false) {
            $info = $res['result'];
            echo "Joriy Webhook holati:\n";
            echo "• URL: " . ($info['url'] ?: '(o\'rnatilmagan)') . "\n";
            echo "• Kutilayotgan yangilanishlar (pending): " . ($info['pending_update_count'] ?? 0) . "\n";
            echo "• So'nggi xatolik sanasi: " . (!empty($info['last_error_date']) ? date('Y-m-d H:i:s', $info['last_error_date']) : 'yo\'q') . "\n";
            echo "• So'nggi xatolik xabari: " . ($info['last_error_message'] ?? 'yo\'q') . "\n";
            echo "• Max connections: " . ($info['max_connections'] ?? 40) . "\n";
        } else {
            echo "❌ Ma'lumot olib bo'lmadi: " . ($res['description'] ?? 'noma\'lum') . "\n";
        }
        break;
}

echo "\nFoydalanish: php bin/set_webhook.php [set|delete|info|commands]\n";
