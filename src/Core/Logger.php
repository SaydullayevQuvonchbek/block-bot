<?php

declare(strict_types=1);

namespace App\Core;

class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';

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
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Agar muhitda APP_DEBUG yoqilgan bo'lsa va CLI bo'lsa, konsolga ham chiqaramiz
        if (PHP_SAPI === 'cli' && Config::getBool('APP_DEBUG', false)) {
            echo $logLine;
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
