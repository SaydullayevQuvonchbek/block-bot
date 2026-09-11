<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            try {
                // Tezkor ping: ulanish uzilgan bo'lsa (masalan: MySQL wait_timeout), uni avtomatik qayta tiklaymiz
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (Throwable) {
                self::$pdo = null;
            }
        }

        $env = Config::get('APP_ENV', 'production');
        $dbDriver = Config::get('DB_DRIVER', 'mysql');

        try {
            if ($dbDriver === 'sqlite' || $env === 'test') {
                $sqlitePath = Config::get('DB_SQLITE_PATH', ':memory:');
                self::$pdo = new PDO("sqlite:{$sqlitePath}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
                return self::$pdo;
            }

            $host = Config::get('DB_HOST', '127.0.0.1');
            $port = Config::getInt('DB_PORT', 3306);
            $dbname = Config::get('DB_NAME', 'block_bot');
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASS', '');
            $charset = Config::get('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_TIMEOUT => 5,
            ];

            self::$pdo = new PDO($dsn, $user, $pass, $options);
            return self::$pdo;
        } catch (PDOException $e) {
            Logger::error("Ma'lumotlar bazasiga ulanishda xatolik: " . $e->getMessage(), [], 'database');
            throw new RuntimeException("Ma'lumotlar bazasiga ulanib bo'lmadi: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    public static function beginTransaction(): bool
    {
        $conn = self::getConnection();
        if (!$conn->inTransaction()) {
            return $conn->beginTransaction();
        }
        return false;
    }

    public static function commit(): bool
    {
        $conn = self::getConnection();
        if ($conn->inTransaction()) {
            return $conn->commit();
        }
        return false;
    }

    public static function rollBack(): bool
    {
        $conn = self::getConnection();
        if ($conn->inTransaction()) {
            return $conn->rollBack();
        }
        return false;
    }

    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }
}
