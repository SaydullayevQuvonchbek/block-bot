<?php

declare(strict_types=1);

/**
 * Block-BOT: yengil, tez-tez (masalan, cron orqali har 5 daqiqada) ishga tushirishga
 * mo'ljallangan salomatlik tekshiruvi (2.0 Phase 2: health-check/observability).
 *
 * `bin/doctor.php`dan ATAYLAB FARQLI: doctor.php og'ir — ko'plab tashqi API (Telegram/
 * Gemini/OpenRouter/Vision) so'rovlari yuboradi va HAR SAFAR bot egasiga test xabari
 * yozadi, shuning uchun u faqat qo'lda, kamdan-kam to'liq diagnostika uchun mos.
 * Bu skript esa hech qanday tashqi tarmoq so'rovi yubormaydi (faqat mahalliy DB/fayl
 * holatini tekshiradi — `App\Core\HealthCheck`) va bot egasiga (`TELEGRAM_OWNER_IDS`)
 * FAQAT quyidagi holatlarda xabar yozadi: (a) tizim yangidan nosog'lom holatga o'tganda,
 * (b) muammo uzoq davom etsa — har `HEALTHCHECK_REALERT_SEC` (standart 1800s/30daq)da bir
 * marta eslatma, (c) nosog'lomdan sog'lom holatga qaytganda — bir marta "tiklandi" xabari.
 * Bu spam qilmasdan, doimiy muammoni ham unutmasdan kuzatishni ta'minlaydi.
 *
 *   php bin/healthcheck.php            -> tekshiradi, kerak bo'lsa xabar yuboradi, to'liq natijani chop etadi
 *   php bin/healthcheck.php --quiet    -> faqat muammo bo'lsa chop etadi (cron log faylini kichik saqlash uchun)
 *
 * Tavsiya etilgan crontab (har 5 daqiqada; worker.php allaqachon ishlayotgan bo'lsa ham
 * xavfsiz — bu skript hech qanday vazifani QAYTA ishlamaydi, faqat holatni o'qiydi):
 *   Minut maydoni: "star-slash-5" (5 daqiqada bir) qolgan 4 maydon "*"
 *   /usr/bin/php /path/to/bin/healthcheck.php --quiet >> storage/logs/healthcheck.log 2>&1
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\HealthCheck;
use App\Core\Logger;
use App\Core\TelegramClient;
use App\Policy\AdminNotificationService;

Config::load();

$quiet = in_array('--quiet', $argv, true);
$reAlertAfterSec = Config::getInt('HEALTHCHECK_REALERT_SEC', 1800);

$result = HealthCheck::runChecks();
$healthy = $result['healthy'];

$stateFile = HealthCheck::stateFile();
$prevState = ['healthy' => true, 'last_alert_at' => 0];
if (is_file($stateFile)) {
    $decoded = json_decode((string)@file_get_contents($stateFile), true);
    if (is_array($decoded)) {
        $prevState = array_merge($prevState, $decoded);
    }
}

$now = time();
$justBecameUnhealthy = !$healthy && $prevState['healthy'] === true;
$stillUnhealthyDueForReminder = !$healthy && $prevState['healthy'] === false
    && ($now - (int)$prevState['last_alert_at']) >= $reAlertAfterSec;
$shouldAlert = $justBecameUnhealthy || $stillUnhealthyDueForReminder;
$shouldRecoveryNotice = $healthy && $prevState['healthy'] === false;

$owners = AdminNotificationService::ownerIds();
if (($shouldAlert || $shouldRecoveryNotice) && $owners !== []) {
    $telegram = new TelegramClient();

    if ($shouldAlert) {
        $lines = ["🚨 <b>Block-BOT: salomatlik tekshiruvida muammo</b>\n"];
        foreach ($result['checks'] as $name => $check) {
            if (!($check['ok'] ?? false)) {
                $lines[] = "❌ <b>{$name}</b>: " . htmlspecialchars((string)$check['detail'], ENT_QUOTES, 'UTF-8');
            }
        }
        $lines[] = "\n<i>" . gmdate('Y-m-d H:i:s') . " UTC — server jurnalini (storage/logs/) tekshiring.</i>";
        $text = implode("\n", $lines);
    } else {
        $text = "✅ <b>Block-BOT: tizim normal holatga qaytdi</b>\n<i>" . gmdate('Y-m-d H:i:s') . " UTC</i>";
    }

    foreach ($owners as $ownerId) {
        $telegram->sendMessage($ownerId, $text);
    }
    Logger::info(
        $shouldAlert ? "Salomatlik ogohlantirishi bot egasiga yuborildi" : "Salomatlik: tiklanish xabari yuborildi",
        ['owners' => $owners, 'checks' => $result['checks']],
        'health'
    );
}

@file_put_contents($stateFile, json_encode([
    'healthy' => $healthy,
    'last_alert_at' => $shouldAlert ? $now : (int)$prevState['last_alert_at'],
], JSON_UNESCAPED_UNICODE));

if (!$quiet || !$healthy) {
    echo "[" . gmdate('Y-m-d H:i:s') . "] Block-BOT healthcheck: " . ($healthy ? "✅ SOG'LOM" : "❌ MUAMMO") . "\n";
    foreach ($result['checks'] as $name => $check) {
        $mark = ($check['ok'] ?? false) ? '✅' : '❌';
        echo "  {$mark} {$name}: {$check['detail']}\n";
    }
}

exit($healthy ? 0 : 1);
