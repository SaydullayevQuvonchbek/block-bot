<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Database;
use PDO;

abstract class TestCase
{
    protected static ?PDO $testPdo = null;

    public static function setUpBeforeClass(): void
    {
        Config::set('APP_ENV', 'test');
        Config::set('DB_DRIVER', 'sqlite');
        Config::set('DB_SQLITE_PATH', ':memory:');

        self::$testPdo = Database::getConnection();
        self::runMigrations(self::$testPdo);
    }

    protected function setUp(): void
    {
        // Har bir testdan oldin vaqtinchalik ma'lumotlarni tozalash
        if (self::$testPdo) {
            self::$testPdo->exec("DELETE FROM updates_log");
            self::$testPdo->exec("DELETE FROM messages");
            self::$testPdo->exec("DELETE FROM moderation_findings");
            self::$testPdo->exec("DELETE FROM user_warnings");
            self::$testPdo->exec("DELETE FROM telegram_actions");
            self::$testPdo->exec("DELETE FROM queue_jobs");
            self::$testPdo->exec("DELETE FROM failed_jobs");
            self::$testPdo->exec("DELETE FROM ai_usage");
            self::$testPdo->exec("DELETE FROM ai_budget_reservations");
            self::$testPdo->exec("DELETE FROM audit_sessions");
            self::$testPdo->exec("DELETE FROM audit_items");
            self::$testPdo->exec("DELETE FROM moderation_appeals");
            self::$testPdo->exec("DELETE FROM word_rules");
            self::$testPdo->exec("DELETE FROM domain_rules");
        }
    }

    private static function runMigrations(PDO $pdo): void
    {
        $schemaFile = dirname(__DIR__) . '/database/migrations/sqlite_schema.sql';
        $sql = file_get_contents($schemaFile);

        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                $pdo->exec($stmt);
            }
        }
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message ?: "Assert True kutilgan edi, lekin False qaytdi");
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new \AssertionError($message ?: "Assert False kutilgan edi, lekin True qaytdi");
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $eStr = var_export($expected, true);
            $aStr = var_export($actual, true);
            throw new \AssertionError($message ?: "Qiymatlar mos kelmadi. Kutilgan: {$eStr}, Haqiqiy: {$aStr}");
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \AssertionError($message ?: "'{$haystack}' ichida '{$needle}' topilmadi");
        }
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        if ($actual === null) {
            throw new \AssertionError($message ?: "Qiymat null bo'lmasligi kutilgan edi");
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        if ($actual !== null) {
            throw new \AssertionError($message ?: "Qiymat null bo'lishi kutilgan edi");
        }
    }
}
