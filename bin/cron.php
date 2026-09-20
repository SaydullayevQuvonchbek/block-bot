<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\HealthCheck;
use App\Core\Logger;
use App\Core\QueueService;

Config::load();

// Salomatlik tekshiruvi (bin/healthcheck.php, public/healthz.php) shu belgi orqali
// cron'ning "jonligini" biladi — worker.php o'rniga cron.php ishlatilayotgan
// (shared hosting) o'rnatishlarda ham har chaqiriqda yangilanadi.
HealthCheck::writeHeartbeat();

$startTime = microtime(true);
$maxRunTime = 55; // Cron har daqiqada takrorlanganda bir-biriga xalaqit bermasligi uchun 55 soniya
$maxJobs = 50;

echo "[" . gmdate('Y-m-d H:i:s') . "] Block-BOT Cron ishga tushdi (Shared Hosting Mode)\n";

$processed = 0;
$now = gmdate('Y-m-d H:i:s');

// 1. Navbatdagi vazifalarni ketma-ket bajarish
while ((microtime(true) - $startTime) < $maxRunTime && $processed < $maxJobs) {
    $job = QueueService::reserve('default', 60);
    if (!$job) {
        break; // Navbatda boshqa vazifa yo'q
    }

    $jobId = $job['id'];
    $handlerClass = $job['handler'];
    $data = $job['data'];
    $attempts = $job['attempts'];

    try {
        if (!class_exists($handlerClass)) {
            throw new RuntimeException("Handler sinfi topilmadi: {$handlerClass}");
        }

        $handler = new $handlerClass();
        $handler->handle($data);

        QueueService::ack($jobId);
        $processed++;
    } catch (Throwable $e) {
        Logger::error("Cron navbat vazifasida xato #{$jobId}: " . $e->getMessage(), [], 'queue');
        if ($attempts >= 3) {
            QueueService::fail($jobId, $e);
        } else {
            QueueService::release($jobId, 15);
        }
    }
}

// 2. Muddati o'tgan ogohlantirishlarni nofaol qilish (Warn retention)
try {
    $pdo = Database::getConnection();
    $now = gmdate('Y-m-d H:i:s');
    $stmtExp = $pdo->prepare("UPDATE user_warnings SET is_active = 0 WHERE is_active = 1 AND expires_at < :now");
    $stmtExp->execute(['now' => $now]);
} catch (Throwable $e) {
    Logger::error("Ogohlantirishlarni tozalashda xato: " . $e->getMessage(), [], 'database');
}

// 3. Qadimgi ma'lumotlarni tozalash (Data retention)
$retentionDays = Config::getInt('DATA_RETENTION_DAYS', 60);
try {
    $pdo = Database::getConnection();
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

    // Qadimgi xabarlar matnini tozalash (xotirani tejash)
    $pdo->prepare("DELETE FROM messages WHERE created_at < :cut")->execute(['cut' => $cutoff]);
    $pdo->prepare("DELETE FROM moderation_cache WHERE expires_at < :now")->execute(['now' => $now]);
} catch (Throwable) {
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "[" . gmdate('Y-m-d H:i:s') . "] Cron yakunlandi. Bajarilgan vazifalar: {$processed}, Vaqt: {$elapsed}s\n";
