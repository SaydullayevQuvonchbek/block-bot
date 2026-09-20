<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Yengil, tez ishlaydigan tizim salomatligi tekshiruvchisi (2.0 Phase 2:
 * health-check/observability). `bin/doctor.php`dan ATAYLAB FARQLI: doctor.php og'ir —
 * ko'plab tashqi API so'rovlari (Telegram/Gemini/OpenRouter/Vision) yuboradi va har safar
 * bot egasiga test xabari yozadi, shuning uchun u faqat QO'LDA, kamdan-kam ishga
 * tushiriladigan to'liq diagnostika uchun mo'ljallangan. Bu klass esa faqat MAHALLIY
 * holatni (DB ulanishi, navbat holati, worker/cron "jonlik" belgisi) tekshiradi —
 * hech qanday tashqi tarmoq so'rovi yubormaydi va hech kimga xabar yozmaydi — shuning
 * uchun tez-tez (masalan, cron orqali har 5 daqiqada — `bin/healthcheck.php`) yoki HTTP
 * monitoring xizmati tomonidan (`public/healthz.php`) xavfsiz chaqirilishi mumkin.
 */
class HealthCheck
{
    // Worker (doimiy demon, bin/worker.php) yoki cron (bin/cron.php) muntazam yozadigan
    // "tirikman" belgisi shuncha soniyadan ortiq yangilanmasa — eskirgan hisoblanadi.
    private const HEARTBEAT_STALE_AFTER_SEC = 150;

    // Navbatdagi eng eski vazifa shuncha soniyadan beri kutayotgan bo'lsa — worker/cron
    // ishlamayapti degani (bin/doctor.php'dagi 180s chegarasi bilan bir xil, izchillik uchun).
    private const QUEUE_STUCK_AFTER_SEC = 180;

    // Bunchalik ko'p yiqilgan vazifa — alohida-alohida xatolardan ko'ra tizimli
    // muammoga (masalan, AI provider butunlay ishlamay qolgani) ishora qiladi.
    private const FAILED_JOBS_CRITICAL_THRESHOLD = 50;

    private static function healthDir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'health';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Gorizontal-scaling (bir nechta `bin/worker.php` jarayoni parallel ishlashi, masalan
     * yuqori yuklama ostida) uchun: har bir worker o'zining muhit o'zgaruvchisi
     * `WORKER_ID` orqali (masalan, systemd shablon xizmati `block-bot-worker@1`,
     * `block-bot-worker@2` — `%i` shu qiymatga aylanadi, README_VPS.md'dagi
     * "Gorizontal scaling" bo'limiga qarang) ALOHIDA "jonlik" fayliga yozadi — shunda
     * bitta worker qulab tushsa, qolganlari ishlab turgani "sog'lom" holatni yashirib
     * qo'ymaydi VA bitta workerning o'zi to'xtaganini ham runChecks()'dagi `workers`
     * ro'yxatidan ko'rish mumkin. `WORKER_ID` belgilanmagan bo'lsa (bitta workerli,
     * standart o'rnatish) — 'default' ishlatiladi va fayl nomi ORQAGA MOSLIK uchun aynan
     * avvalgidek ('worker_heartbeat.txt', suffikssiz) qoladi.
     */
    public static function workerId(): string
    {
        $raw = trim((string)(getenv('WORKER_ID') ?: ''));
        if ($raw === '') {
            return 'default';
        }
        // Fayl nomi sifatida xavfsiz bo'lishi uchun faqat harf/raqam/tire/pastki chiziq
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
        return ($clean === '' || $clean === null) ? 'default' : $clean;
    }

    public static function heartbeatFile(?string $workerId = null): string
    {
        $id = $workerId ?? self::workerId();
        $suffix = ($id === 'default') ? '' : ('_' . $id);
        return self::healthDir() . DIRECTORY_SEPARATOR . "worker_heartbeat{$suffix}.txt";
    }

    /**
     * Diskda mavjud bo'lgan barcha worker "jonlik" fayllarini topadi (bitta workerli
     * o'rnatishda — bitta fayl, ko'p workerli o'rnatishda — har biri uchun alohida).
     *
     * @return array<string, string> workerId => fayl yo'li
     */
    private static function heartbeatFiles(): array
    {
        $dir = self::healthDir();
        $paths = glob($dir . DIRECTORY_SEPARATOR . 'worker_heartbeat*.txt') ?: [];
        $result = [];
        foreach ($paths as $path) {
            $base = basename($path, '.txt'); // worker_heartbeat yoki worker_heartbeat_<id>
            $id = ($base === 'worker_heartbeat') ? 'default' : substr($base, strlen('worker_heartbeat_'));
            $result[$id] = $path;
        }
        ksort($result);
        return $result;
    }

    public static function stateFile(): string
    {
        return self::healthDir() . DIRECTORY_SEPARATOR . 'alert_state.json';
    }

    /**
     * `bin/worker.php` (doimiy demon) va `bin/cron.php` (har daqiqalik cron) — ikkalasi
     * ham shu metodni chaqiradi, qaysi rejim ishlatilishidan qat'i nazar "jonlik" belgisi
     * yangilanishi uchun. Xatolik jim yutiladi (disk to'la bo'lsa ham worker to'xtamasligi
     * kerak). Bir nechta worker parallel ishlaganda, har biri o'z `WORKER_ID`si bilan
     * alohida faylga yozadi (workerId() izohiga qarang).
     */
    public static function writeHeartbeat(): void
    {
        @file_put_contents(self::heartbeatFile(), (string)time());
    }

    /**
     * @return int|null Standart ('default') workerning oxirgi "jonlik" belgisidan necha
     *                   soniya o'tgani, yoki fayl umuman mavjud/yaroqli bo'lmasa null.
     *                   Ko'p workerli tafsilot uchun runChecks()['checks']['worker_heartbeat']['workers']ga qarang.
     */
    public static function heartbeatAgeSeconds(): ?int
    {
        $file = self::heartbeatFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = trim((string)@file_get_contents($file));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        return max(0, time() - (int)$raw);
    }

    /**
     * Barcha tekshiruvlarni bajarib, strukturalangan natija qaytaradi. Hech qanday
     * tashqi (Telegram/AI) tarmoq so'rovi yuborilmaydi — faqat mahalliy DB va fayl
     * holati o'qiladi, shuning uchun bir necha millisekundda yakunlanadi.
     *
     * @return array{healthy: bool, checks: array<string, array>, checked_at: string}
     */
    public static function runChecks(): array
    {
        $checks = [];

        // 1. Ma'lumotlar bazasi ulanishi
        try {
            Database::getConnection()->query("SELECT 1");
            $checks['database'] = ['ok' => true, 'detail' => "Ulanish ishlayapti"];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'detail' => "Ulanib bo'lmadi: " . $e->getMessage()];
        }

        // 2. Worker/cron "jonlik" belgisi/belgilari. Fayl(lar) umuman yo'q bo'lishi xato
        //    emas — bu install worker.php/cron.php'ning yangi versiyasini hali ishga
        //    tushirmagan bo'lishi mumkin (masalan, deploy qilinmagan) — shu holatda faqat
        //    ma'lumot sifatida qayd etiladi, "sog'lom" hisoblanadi. Gorizontal-scaling
        //    (bir nechta WORKER_ID'li worker) holatida har bir worker alohida faylga
        //    yozadi — shu yerda ularning barchasi yig'ib chiqiladi: umumiy holat "sog'lom"
        //    hisoblanadi agar KAMIDA BITTA worker jonli bo'lsa (navbat baribir tozalanadi),
        //    lekin har bir workerning alohida holati `workers` ro'yxatida ko'rinadi — shu
        //    orqali "N tadan faqat 1 tasi ishlayapti" holatini ham kuzatish mumkin.
        $files = self::heartbeatFiles();
        if (empty($files)) {
            $checks['worker_heartbeat'] = [
                'ok' => true,
                'detail' => "Hali yozilmagan (worker/cron eski versiyada yoki hali ishga tushmagan)",
                'age_seconds' => null,
                'workers' => [],
            ];
        } else {
            $workers = [];
            $freshCount = 0;
            foreach ($files as $workerId => $file) {
                $raw = trim((string)@file_get_contents($file));
                if ($raw === '' || !ctype_digit($raw)) {
                    continue;
                }
                $workerAge = max(0, time() - (int)$raw);
                $workerStale = $workerAge > self::HEARTBEAT_STALE_AFTER_SEC;
                if (!$workerStale) {
                    $freshCount++;
                }
                $workers[] = ['id' => $workerId, 'age_seconds' => $workerAge, 'ok' => !$workerStale];
            }

            $ok = $freshCount > 0;
            // Yagona (bitta) worker holatida orqaga moslik uchun eski maydon nomi bilan
            // to'g'ridan-to'g'ri o'sha workerning yoshini ham qaytaramiz.
            $singleAge = (count($workers) === 1) ? $workers[0]['age_seconds'] : null;

            if (count($workers) <= 1) {
                $detail = $ok
                    ? "So'nggi belgi {$singleAge} soniya oldin yangilangan"
                    : "So'nggi belgidan {$singleAge} soniya o'tdi — worker/cron to'xtagan bo'lishi mumkin";
            } else {
                $staleCount = count($workers) - $freshCount;
                $detail = $ok
                    ? "{$freshCount}/" . count($workers) . " worker jonli" . ($staleCount > 0 ? " ({$staleCount} tasi eskirgan)" : "")
                    : "Barcha " . count($workers) . " worker eskirgan — hech biri javob bermayapti";
            }

            $checks['worker_heartbeat'] = [
                'ok' => $ok,
                'detail' => $detail,
                'age_seconds' => $singleAge,
                'workers' => $workers,
            ];
        }

        // 3. Navbat (queue) holati — eng eski kutayotgan vazifa qancha "eskirgan".
        try {
            $pdo = Database::getConnection();
            $row = $pdo->query("SELECT COUNT(*) c, MIN(available_at) oldest FROM queue_jobs WHERE reserved_at IS NULL")->fetch();
            $pending = (int)($row['c'] ?? 0);
            $oldestAge = null;
            $stuck = false;
            if ($pending > 0 && !empty($row['oldest'])) {
                $oldestAge = time() - strtotime((string)$row['oldest'] . ' UTC');
                $stuck = $oldestAge > self::QUEUE_STUCK_AFTER_SEC;
            }
            $checks['queue'] = [
                'ok' => !$stuck,
                'detail' => $stuck
                    ? "{$pending} ta vazifa {$oldestAge} soniyadan beri kutmoqda — worker/cron ishlamayapti"
                    : "{$pending} ta vazifa navbatda",
                'pending' => $pending,
                'oldest_age_seconds' => $oldestAge,
            ];
        } catch (Throwable $e) {
            $checks['queue'] = ['ok' => false, 'detail' => "Tekshirib bo'lmadi: " . $e->getMessage(), 'pending' => 0, 'oldest_age_seconds' => null];
        }

        // 4. Yiqilgan vazifalar (faqat ma'lumot uchun — kamdan-kam yiqilish normal holat,
        //    faqat juda ko'p bo'lsa tizimli muammo sifatida belgilanadi).
        try {
            $failed = (int)Database::getConnection()->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn();
            $critical = $failed > self::FAILED_JOBS_CRITICAL_THRESHOLD;
            $checks['failed_jobs'] = [
                'ok' => !$critical,
                'detail' => $critical
                    ? "{$failed} ta yiqilgan vazifa — tizimli muammo bo'lishi mumkin"
                    : "{$failed} ta yiqilgan vazifa",
                'count' => $failed,
            ];
        } catch (Throwable $e) {
            $checks['failed_jobs'] = ['ok' => false, 'detail' => "Tekshirib bo'lmadi: " . $e->getMessage(), 'count' => 0];
        }

        $healthy = true;
        foreach ($checks as $check) {
            if (!($check['ok'] ?? false)) {
                $healthy = false;
                break;
            }
        }

        return ['healthy' => $healthy, 'checks' => $checks, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
