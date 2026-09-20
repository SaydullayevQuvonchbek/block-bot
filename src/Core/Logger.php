<?php

declare(strict_types=1);

namespace App\Core;

class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';

    // storage/logs/*.log cheksiz o'sib ketmasligi uchun standart chegaralar (Phase 2:
    // log rotatsiyasi). LOG_MAX_SIZE_BYTES / LOG_MAX_BACKUPS orqali sozlanadi;
    // LOG_MAX_SIZE_BYTES=0 rotatsiyani butunlay o'chiradi (masalan, tizim logrotate
    // ishlatilganda — README_VPS.md'dagi "Log rotatsiyasi" bo'limiga qarang).
    private const DEFAULT_MAX_LOG_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const DEFAULT_MAX_LOG_BACKUPS = 5;

    private static ?string $logDir = null;

    public static function init(?string $dir = null): void
    {
        self::$logDir = $dir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        if (self::$logDir === null) {
            self::init();
        }

        $timestamp = gmdate('Y-m-d H:i:s') . ' UTC';
        $sanitizedMessage = self::sanitize($message);
        $sanitizedContext = self::sanitizeArray($context);

        $contextStr = !empty($sanitizedContext) ? ' ' . json_encode($sanitizedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logLine = "[{$timestamp}] [{$level}] [{$channel}] {$sanitizedMessage}{$contextStr}" . PHP_EOL;

        $logFile = self::$logDir . DIRECTORY_SEPARATOR . $channel . '.log';
        self::rotateIfNeeded($logFile);
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Agar muhitda APP_DEBUG yoqilgan bo'lsa va CLI bo'lsa, konsolga ham chiqaramiz
        if (PHP_SAPI === 'cli' && Config::getBool('APP_DEBUG', false)) {
            echo $logLine;
        }
    }

    /**
     * Fayl belgilangan hajmdan (standart 10MB) oshsa: joriy fayl `.1` (imkon bo'lsa
     * siqilgan `.1.gz`) deb qayta nomlanadi, avvalgi backuplar bittaga siljiydi
     * (`.1`->`.2`, ...), eng eskisi (standart 5 tadan oshgani) butunlay o'chiriladi.
     * Bu VPS'dagi systemd worker, shared-hosting cron va webhook — barchasida (hatto
     * tizim logrotate'iga kirish bo'lmagan hosting'larda ham) ishlaydi. Parallel
     * jarayonlar (masalan bir nechta worker) bir vaqtda rotatsiya qilib yubormasligi
     * uchun qisqa muddatli fayl qulfidan (flock) foydalaniladi; qulflay olmasa yoki
     * band bo'lsa, rotatsiya shunchaki keyingi log yozuviga qoldiriladi — log yozuvi
     * hech qachon shu sabab bilan yo'qolmaydi.
     */
    private static function rotateIfNeeded(string $logFile): void
    {
        $maxSize = Config::getInt('LOG_MAX_SIZE_BYTES', self::DEFAULT_MAX_LOG_SIZE_BYTES);
        if ($maxSize <= 0) {
            return; // Rotatsiya ataylab o'chirilgan (masalan, tizim logrotate ishlatilganda)
        }

        clearstatcache(true, $logFile);
        if (!is_file($logFile) || filesize($logFile) < $maxSize) {
            return;
        }

        $lockHandle = @fopen($logFile . '.rotate.lock', 'c');
        if ($lockHandle === false) {
            return; // Qulf yaratib bo'lmasa, rotatsiyani shunchaki o'tkazib yuboramiz
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle); // Boshqa jarayon allaqachon rotatsiya qilyapti
            return;
        }

        try {
            // Qulfdan keyin qayta tekshirish: navbatda kutayotganda boshqa jarayon
            // allaqachon rotatsiya qilib ulgurgan bo'lishi mumkin
            clearstatcache(true, $logFile);
            if (!is_file($logFile) || filesize($logFile) < $maxSize) {
                return;
            }

            $maxBackups = max(1, Config::getInt('LOG_MAX_BACKUPS', self::DEFAULT_MAX_LOG_BACKUPS));
            $compress = Config::getBool('LOG_COMPRESS_BACKUPS', true) && function_exists('gzencode');

            // Eng eski backup(lar)ni o'chirish (siqilgan/siqilmagan ikkalasi ham tekshiriladi)
            foreach ([$logFile . '.' . $maxBackups, $logFile . '.' . $maxBackups . '.gz'] as $oldest) {
                if (is_file($oldest)) {
                    @unlink($oldest);
                }
            }

            // Qolganlarini bittaga siljitish: .(N-1)->.N, ..., .1->.2
            for ($i = $maxBackups - 1; $i >= 1; $i--) {
                foreach (['', '.gz'] as $ext) {
                    $src = $logFile . '.' . $i . $ext;
                    $dst = $logFile . '.' . ($i + 1) . $ext;
                    if (is_file($src)) {
                        @rename($src, $dst);
                    }
                }
            }

            // Joriy faylni .1 ga ko'chirish (mumkin bo'lsa siqib)
            if ($compress) {
                $data = @file_get_contents($logFile);
                $encoded = ($data !== false) ? @gzencode($data, 6) : false;
                if ($encoded !== false && @file_put_contents($logFile . '.1.gz', $encoded) !== false) {
                    @unlink($logFile);
                } else {
                    // Siqish muvaffaqiyatsiz bo'lsa ham log tarixi yo'qolmasligi kerak
                    @rename($logFile, $logFile . '.1');
                }
            } else {
                @rename($logFile, $logFile . '.1');
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log(self::INFO, $message, $context, $channel);
    }

    public static function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log(self::WARNING, $message, $context, $channel);
    }

    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log(self::ERROR, $message, $context, $channel);
    }

    public static function debug(string $message, array $context = [], string $channel = 'app'): void
    {
        if (Config::getBool('APP_DEBUG', false)) {
            self::log(self::DEBUG, $message, $context, $channel);
        }
    }

    /**
     * Maxfiy ma'lumotlarni (token, kalit, parol) yashirish (masking)
     */
    public static function sanitize(string $text): string
    {
        $botToken = Config::get('TELEGRAM_BOT_TOKEN');
        if (!empty($botToken) && strlen($botToken) > 10) {
            $text = str_replace($botToken, substr($botToken, 0, 5) . '***[TOKEN_MASKED]***', $text);
        }

        $openRouterKey = Config::get('OPENROUTER_API_KEY');
        if (!empty($openRouterKey) && strlen($openRouterKey) > 8) {
            $text = str_replace($openRouterKey, substr($openRouterKey, 0, 8) . '***[KEY_MASKED]***', $text);
        }

        $geminiKey = Config::get('GEMINI_API_KEY');
        if (!empty($geminiKey) && strlen($geminiKey) > 8) {
            $text = str_replace($geminiKey, substr($geminiKey, 0, 5) . '***[KEY_MASKED]***', $text);
        }

        // Webhook secret
        $secret = Config::get('TELEGRAM_WEBHOOK_SECRET');
        if (!empty($secret) && strlen($secret) > 6) {
            $text = str_replace($secret, '***[SECRET_MASKED]***', $text);
        }

        return $text;
    }

    private static function sanitizeArray(array $data): array
    {
        $clean = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $clean[$k] = self::sanitizeArray($v);
            } elseif (is_string($v)) {
                $clean[$k] = self::sanitize($v);
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }
}
