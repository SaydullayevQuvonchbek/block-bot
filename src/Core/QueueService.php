<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

class QueueService
{
    public const PRIORITY_CRITICAL = 100;
    public const PRIORITY_HIGH = 50;
    public const PRIORITY_NORMAL = 20;
    public const PRIORITY_LOW = 5;

    public static function push(
        string $handlerClass,
        array $data,
        int $priority = self::PRIORITY_NORMAL,
        int $delaySeconds = 0,
        string $queue = 'default'
    ): int {
        $pdo = Database::getConnection();

        $availableAt = gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $createdAt = gmdate('Y-m-d H:i:s');

        $payload = json_encode([
            'handler' => $handlerClass,
            'data' => $data,
            'created_at' => $createdAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $pdo->prepare("
            INSERT INTO queue_jobs (queue, priority, payload, attempts, reserved_at, available_at, created_at)
            VALUES (:queue, :priority, :payload, 0, NULL, :available_at, :created_at)
        ");

        $stmt->execute([
            'queue' => $queue,
            'priority' => $priority,
            'payload' => $payload,
            'available_at' => $availableAt,
            'created_at' => $createdAt,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Navbatdan keyingi vazifani atomik tarzda band qilish (Atomic Reservation)
     */
    public static function reserve(string $queue = 'default', int $lockTimeoutSec = 300): ?array
    {
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $staleTime = gmdate('Y-m-d H:i:s', time() - $lockTimeoutSec);

        Database::beginTransaction();
        try {
            // SQLite yoki MySQL uchun mos so'rov
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockClause = ($driver === 'mysql') ? 'FOR UPDATE' : '';

            $selectSql = "
                SELECT id, queue, priority, payload, attempts
                FROM queue_jobs
                WHERE queue = :queue
                  AND available_at <= :now
                  AND (reserved_at IS NULL OR reserved_at < :stale_time)
                ORDER BY priority DESC, id ASC
                LIMIT 1 {$lockClause}
            ";

            $stmt = $pdo->prepare($selectSql);
            $stmt->execute([
                'queue' => $queue,
                'now' => $now,
                'stale_time' => $staleTime,
            ]);

            $job = $stmt->fetch();
            if (!$job) {
                Database::commit();
                return null;
            }

            $updateStmt = $pdo->prepare("
                UPDATE queue_jobs
                SET reserved_at = :now, attempts = attempts + 1
                WHERE id = :id
            ");
            $updateStmt->execute([
                'now' => $now,
                'id' => $job['id'],
            ]);

            Database::commit();

            $decoded = json_decode($job['payload'], true);
            return [
                'id' => (int)$job['id'],
                'queue' => $job['queue'],
                'priority' => (int)$job['priority'],
                'handler' => $decoded['handler'] ?? '',
                'data' => $decoded['data'] ?? [],
                'attempts' => (int)$job['attempts'] + 1,
            ];
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::error("Navbatdan vazifani band qilishda xato: " . $e->getMessage(), [], 'queue');
            return null;
        }
    }

    /**
     * Muvaffaqiyatli yakunlangan vazifani navbatdan o'chirish
     */
    public static function ack(int $jobId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM queue_jobs WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
    }

    /**
     * Vazifani keyinroq qayta urinish uchun navbatga chiqarish (Backoff)
     */
    public static function release(int $jobId, int $delaySeconds = 10): void
    {
        $pdo = Database::getConnection();
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delaySeconds);

        $stmt = $pdo->prepare("
            UPDATE queue_jobs
            SET reserved_at = NULL, available_at = :available_at
            WHERE id = :id
        ");
        $stmt->execute([
            'available_at' => $availableAt,
            'id' => $jobId,
        ]);
    }

    /**
     * Maksimal urinishlardan so'ng vazifani failed_jobs jadvaliga ko'chirish
     */
    public static function fail(int $jobId, Throwable $e): void
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT queue, payload FROM queue_jobs WHERE id = :id");
            $stmt->execute(['id' => $jobId]);
            $job = $stmt->fetch();

            if ($job) {
                $failedStmt = $pdo->prepare("
                    INSERT INTO failed_jobs (queue, payload, exception, failed_at)
                    VALUES (:queue, :payload, :exception, :failed_at)
                ");
                $failedStmt->execute([
                    'queue' => $job['queue'],
                    'payload' => $job['payload'],
                    'exception' => $e->getMessage() . "\n" . $e->getTraceAsString(),
                    'failed_at' => gmdate('Y-m-d H:i:s'),
                ]);

                $deleteStmt = $pdo->prepare("DELETE FROM queue_jobs WHERE id = :id");
                $deleteStmt->execute(['id' => $jobId]);
            }

            Database::commit();
        } catch (Throwable $dbError) {
            Database::rollBack();
            Logger::error("Failed job saqlashda xato: " . $dbError->getMessage(), [], 'queue');
        }
    }
}
