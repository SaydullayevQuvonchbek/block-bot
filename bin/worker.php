<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\HealthCheck;
use App\Core\Logger;
use App\Core\QueueService;

Config::load();

echo "=========================================================\n";
echo "🛡 Block-BOT: Doimiy Navbat Xizmatchisi (VPS Worker Daemon)\n";
echo "=========================================================\n";
// Gorizontal-scaling: bir nechta worker parallel ishlaganda, har biri o'zining WORKER_ID
// muhit o'zgaruvchisi bilan ishga tushiriladi (README_VPS.md "Gorizontal scaling"
// bo'limiga qarang) — shu orqali har biri alohida health-check "jonlik" fayliga yozadi.
echo "Worker ID: " . HealthCheck::workerId() . "\n";
echo "Worker ishga tushdi. To'xtatish uchun Ctrl+C bosing.\n\n";

$shouldRun = true;

// Signal boshqaruvi (Linux / VPS muhitida graceful shutdown)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldRun) {
        echo "\n[INFO] SIGTERM qabul qilindi. Worker to'xtamoqda...\n";
        $shouldRun = false;
    });
    pcntl_signal(SIGINT, function () use (&$shouldRun) {
        echo "\n[INFO] SIGINT (Ctrl+C) qabul qilindi. Worker to'xtamoqda...\n";
        $shouldRun = false;
    });
}

$processedCount = 0;

while ($shouldRun) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Salomatlik tekshiruvi (bin/healthcheck.php, public/healthz.php) shu belgi orqali
    // workerning "jonligini" biladi — har tsiklda (band yoki bo'sh, farqi yo'q) yangilanadi.
    HealthCheck::writeHeartbeat();

    try {
        $job = QueueService::reserve('default');

        if (!$job) {
            // Agar navbat bo'sh bo'lsa, protsessorni yuklamaslik uchun 1 soniya kutish
            sleep(1);
            continue;
        }

        $jobId = $job['id'];
        $handlerClass = $job['handler'];
        $data = $job['data'];
        $attempts = $job['attempts'];

        echo "[" . gmdate('Y-m-d H:i:s') . "] Vazifa bajarilmoqda #{$jobId} [{$handlerClass}] (Urinish: {$attempts})\n";

        if (!class_exists($handlerClass)) {
            throw new RuntimeException("Handler sinfi topilmadi: {$handlerClass}");
        }

        $handler = new $handlerClass();
        if (!method_exists($handler, 'handle')) {
            throw new RuntimeException("Handler sinfida 'handle' metodi mavjud emas: {$handlerClass}");
        }

        // Vazifani bajarish
        $handler->handle($data);

        // Muvaffaqiyatli yakunlangach navbatdan o'chirish
        QueueService::ack($jobId);
        $processedCount++;

        echo "[" . gmdate('Y-m-d H:i:s') . "] ✅ Vazifa muvaffaqiyatli yakunlandi #{$jobId}\n";

        // Xotira tozalash va davriy restart (Memory leak va xotira shishishining oldini olish)
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        if ($processedCount % 100 === 0) {
            \App\Policy\SettingsService::clearCache();
        }
        if (memory_get_usage(true) > 128 * 1024 * 1024) {
            echo "\n[INFO] Xotira sarfi 128 MB ga yetdi. Worker qayta ishga tushish uchun xavfsiz to'xtamoqda...\n";
            $shouldRun = false;
        }
        if ($processedCount >= 1000) {
            echo "\n[INFO] 1000 ta vazifa yakunlandi. Worker toza jarayon bilan yangilanish uchun to'xtamoqda...\n";
            $shouldRun = false;
        }
    } catch (Throwable $e) {
        echo "[" . gmdate('Y-m-d H:i:s') . "] ❌ Vazifa xatosi #{$jobId}: " . $e->getMessage() . "\n";
        Logger::error("Worker vazifasini bajarishda xato: " . $e->getMessage(), [
            'job_id' => $jobId ?? null,
            'exception' => $e->getTraceAsString(),
        ], 'queue');

        if (isset($jobId)) {
            if ($attempts >= 3) {
                // 3 marta urinishdan so'ng failed_jobs ga o'tkazish
                QueueService::fail($jobId, $e);
                echo "[" . gmdate('Y-m-d H:i:s') . "] ⚠️ Vazifa failed_jobs jadvaliga ko'chirildi #{$jobId}\n";
            } else {
                // Keyinroq qayta urinish (Backoff: 10 soniya)
                QueueService::release($jobId, 10 * $attempts);
            }
        }

        sleep(1);
    }
}

echo "[INFO] Worker o'z ishini xavfsiz yakunladi. Jami bajarilgan vazifalar: {$processedCount}\n";
