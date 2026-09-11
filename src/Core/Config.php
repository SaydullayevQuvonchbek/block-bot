<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded && $envPath === null) {
            return;
        }

        $envPath = $envPath ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        if (file_exists($envPath) && is_readable($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $key = trim($parts[0]);
                        $value = trim($parts[1]);

                        // Qavs yoki qo'shtirnoqlarni tozalash
                        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                            $value = substr($value, 1, -1);
                        }

                        self::$data[$key] = $value;
                        if (!isset($_ENV[$key])) {
                            $_ENV[$key] = $value;
                        }
                        if (!isset($_SERVER[$key])) {
                            $_SERVER[$key] = $value;
                        }
                    }
                }
            }
        }

        self::$loaded = true;
    }

    public static function set(string $key, mixed $value): void
    {
        if (!self::$loaded) {
            self::load();
        }
        self::$data[$key] = $value;
        $_ENV[$key] = (string)$value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        if (array_key_exists($key, self::$data)) {
            return self::$data[$key];
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $envVal = getenv($key);
        if ($envVal !== false) {
            return $envVal;
        }

        return $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default);
        if (is_bool($val)) {
            return $val;
        }
        $val = strtolower(trim((string)$val));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, $default);
        return (int)$val;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $val = self::get($key, $default);
        return (float)$val;
    }

    public static function reset(): void
    {
        self::$data = [];
        self::$loaded = false;
    }
}
