<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\Logger;
use App\Core\QueueService;
use PDO;
use Throwable;

class AuditService
{
    public static function createSession(
        int|string $chatId,
        int $adminUserId,
        string $sourceType = 'json_export',
        string $scope = 'all',
        string $aiMode = 'economical',
        float $maxBudgetUsd = 1.00
    ): int {
        $chatId = (int)$chatId;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $initialStatus = $sourceType === 'json_export' ? 'waiting_upload' : 'running';

        $stmt = $pdo->prepare("
            INSERT INTO audit_sessions (
                chat_id, admin_user_id, source_type, status, filter_scope,
                ai_mode, max_budget_usd, budget_spent_usd, total_scanned,
                total_flagged, created_at, updated_at
            ) VALUES (
                :cid, :uid, :stype, :status, :scope,
                :aimode, :maxb, 0.0000, 0,
                0, :created_at, :updated_at
            )
        ");

        $stmt->execute([
            'cid' => $chatId,
            'uid' => $adminUserId,
            'stype' => $sourceType,
            'status' => $initialStatus,
            'scope' => $scope,
            'aimode' => $aiMode,
            'maxb' => $maxBudgetUsd,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sessionId = (int)$pdo->lastInsertId();

        if ($sourceType === 'mtproto') {
            QueueService::push(
                'App\Jobs\AuditRunnerJob',
                ['session_id' => $sessionId, 'chat_id' => $chatId],
                QueueService::PRIORITY_LOW
            );
        } elseif ($sourceType === 'member_sweep') {
            QueueService::push(
                'App\Jobs\ScanMembersJob',
                ['session_id' => $sessionId, 'chat_id' => $chatId, 'notify_chat_id' => $adminUserId],
                QueueService::PRIORITY_LOW
            );
        }

        return $sessionId;
    }

    public static function markCompleted(int $sessionId): bool
    {
        return self::updateStatus($sessionId, 'completed');
    }

    public static function saveCleanupSummary(int $sessionId, string $summary): void
    {
        try {
            $pdo = Database::getConnection();
            $pdo->prepare("UPDATE audit_sessions SET cleanup_summary = :s, updated_at = :now WHERE id = :sid")
                ->execute(['s' => mb_substr($summary, 0, 4000), 'now' => gmdate('Y-m-d H:i:s'), 'sid' => $sessionId]);
        } catch (Throwable) {
        }
    }

    public static function pause(int $sessionId): bool
    {
        return self::updateStatus($sessionId, 'paused');
    }

    public static function resume(int $sessionId): bool
    {
        $session = self::get($sessionId);
        if (!$session) {
            return false;
        }
        $nextStatus = ($session['source_type'] ?? '') === 'json_export' ? 'waiting_upload' : 'running';
        $ok = self::updateStatus($sessionId, $nextStatus);
        if ($ok) {
            if (($session['source_type'] ?? '') === 'mtproto') {
                QueueService::push(
                    'App\Jobs\AuditRunnerJob',
                    ['session_id' => $sessionId, 'chat_id' => (int)$session['chat_id']],
                    QueueService::PRIORITY_LOW
                );
            }
        }
        return $ok;
    }

    public static function cancel(int $sessionId): bool
    {
        return self::updateStatus($sessionId, 'cancelled');
    }

    public static function markRunning(int $sessionId): bool
    {
        return self::updateStatus($sessionId, 'running');
    }

    public static function markFailed(int $sessionId, string $message): bool
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                UPDATE audit_sessions
                SET status = 'failed', error_message = :message, updated_at = :now
                WHERE id = :sid
            ");
            return $stmt->execute([
                'message' => mb_substr($message, 0, 2000),
                'now' => gmdate('Y-m-d H:i:s'),
                'sid' => $sessionId,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    public static function latestForChat(int|string $chatId, ?array $statuses = null): ?array
    {
        try {
            $pdo = Database::getConnection();
            $params = ['cid' => (int)$chatId];
            $where = '';
            if ($statuses !== null && $statuses !== []) {
                $placeholders = [];
                foreach (array_values($statuses) as $index => $status) {
                    $key = 'status_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $status;
                }
                $where = ' AND status IN (' . implode(', ', $placeholders) . ')';
            }

            $stmt = $pdo->prepare("SELECT * FROM audit_sessions WHERE chat_id = :cid{$where} ORDER BY id DESC LIMIT 1");
            $stmt->execute($params);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function get(int $sessionId): ?array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM audit_sessions WHERE id = :sid");
            $stmt->execute(['sid' => $sessionId]);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function estimateCost(int $messageCount, string $aiMode = 'economical'): array
    {
        // Tejamkor rejimda taxminan 10% xabarlar shubhali deb topilib AI ga yuboriladi
        $aiRatio = ($aiMode === 'comprehensive') ? 1.0 : 0.10;
        $estimatedAiMessages = (int)ceil($messageCount * $aiRatio);

        // O'rtacha 1 xabar = 100 token input + 50 token output (~$0.000030)
        $costPerMessage = 0.000030;
        $estimatedCostUsd = round($estimatedAiMessages * $costPerMessage, 4);

        return [
            'total_messages' => $messageCount,
            'ai_mode' => $aiMode,
            'estimated_ai_scans' => $estimatedAiMessages,
            'estimated_cost_usd' => $estimatedCostUsd,
            'known_factors' => "Matnlar soni va mahalliy qoidalar qamrovi",
            'unknown_factors' => "Noma'lum: rasmlar/videolar soni, ularning hajmi va video kadrlarining aniq soni",
        ];
    }

    private static function updateStatus(int $sessionId, string $status): bool
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE audit_sessions SET status = :st, updated_at = :now WHERE id = :sid");
            return $stmt->execute(['st' => $status, 'now' => $now, 'sid' => $sessionId]);
        } catch (Throwable) {
            return false;
        }
    }
}
