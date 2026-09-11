<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

class UsageBudgetService
{
    public static function isBudgetAvailable(float $estimatedCost = 0.001): bool
    {
        $dailyLimit = Config::getFloat('AI_DAILY_BUDGET_USD', 1.00);
        $monthlyLimit = Config::getFloat('AI_MONTHLY_BUDGET_USD', 25.00);

        $pdo = Database::getConnection();

        $todayStart = gmdate('Y-m-d 00:00:00');
        $monthStart = gmdate('Y-m-01 00:00:00');

        // Bugungi sarf
        $stmtToday = $pdo->prepare("
            SELECT COALESCE(SUM(estimated_cost_usd), 0) as spent
            FROM ai_usage
            WHERE created_at >= :today_start
        ");
        $stmtToday->execute(['today_start' => $todayStart]);
        $todaySpent = (float)$stmtToday->fetchColumn();

        // Oylik sarf
        $stmtMonth = $pdo->prepare("
            SELECT COALESCE(SUM(estimated_cost_usd), 0) as spent
            FROM ai_usage
            WHERE created_at >= :month_start
        ");
        $stmtMonth->execute(['month_start' => $monthStart]);
        $monthSpent = (float)$stmtMonth->fetchColumn();

        // Band qilingan, hali yakunlanmagan (unsettled) so'rovlar (oxirgi 10 daqiqa)
        $tenMinAgo = gmdate('Y-m-d H:i:s', time() - 600);
        $stmtPending = $pdo->prepare("
            SELECT COALESCE(SUM(reserved_cost_usd), 0) as pending
            FROM ai_budget_reservations
            WHERE settled = 0 AND created_at >= :ten_min_ago
        ");
        $stmtPending->execute(['ten_min_ago' => $tenMinAgo]);
        $pendingCost = (float)$stmtPending->fetchColumn();

        if (($todaySpent + $pendingCost + $estimatedCost) > $dailyLimit) {
            Logger::warning("AI kunlik budjet tugadi! Sarflangan: \${$todaySpent}, Kutilayotgan: \${$pendingCost}, Limit: \${$dailyLimit}", [], 'ai');
            return false;
        }

        if (($monthSpent + $pendingCost + $estimatedCost) > $monthlyLimit) {
            Logger::warning("AI oylik budjet tugadi! Sarflangan: \${$monthSpent}, Limit: \${$monthlyLimit}", [], 'ai');
            return false;
        }

        return true;
    }

    /**
     * Parallel so'rovlar budjetdan oshib ketmasligi uchun atomik rezervatsiya
     */
    public static function reserveBudget(float $estimatedCost = 0.001): ?string
    {
        $pdo = Database::getConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $namedLock = false;

        if ($driver === 'mysql') {
            $namedLock = (int)$pdo->query("SELECT GET_LOCK('block_bot_ai_budget', 5)")->fetchColumn() === 1;
            if (!$namedLock) {
                Logger::warning("AI budjet blokirovkasini olib bo'lmadi", [], 'ai');
                return null;
            }
        }

        Database::beginTransaction();

        try {
            if (!self::isBudgetAvailable($estimatedCost)) {
                Database::rollBack();
                return null;
            }

            $reservationKey = bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');

            $stmt = $pdo->prepare("
                INSERT INTO ai_budget_reservations (reservation_key, reserved_cost_usd, settled, created_at)
                VALUES (:key, :cost, 0, :now)
            ");
            $stmt->execute([
                'key' => $reservationKey,
                'cost' => $estimatedCost,
                'now' => $now,
            ]);

            Database::commit();
            return $reservationKey;
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::error("Budjet rezervatsiyasida xato: " . $e->getMessage(), [], 'ai');
            return null;
        } finally {
            if ($namedLock) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('block_bot_ai_budget')");
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * So'rov yakunlangach aniq xarajatni qayd etish va rezervatsiyani yopish
     */
    public static function settleBudget(
        ?string $reservationKey,
        float $actualCostUsd,
        ?int $chatId,
        string $model,
        string $requestType,
        int $promptTokens,
        int $completionTokens,
        bool $isEstimated = false
    ): void {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $now = gmdate('Y-m-d H:i:s');
            $totalTokens = $promptTokens + $completionTokens;

            $stmt = $pdo->prepare("
                INSERT INTO ai_usage (chat_id, model, request_type, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, is_estimated, created_at)
                VALUES (:chat_id, :model, :req_type, :p_tokens, :c_tokens, :tot_tokens, :cost, :is_est, :now)
            ");
            $stmt->execute([
                'chat_id' => $chatId,
                'model' => $model,
                'req_type' => $requestType,
                'p_tokens' => $promptTokens,
                'c_tokens' => $completionTokens,
                'tot_tokens' => $totalTokens,
                'cost' => $actualCostUsd,
                'is_est' => $isEstimated ? 1 : 0,
                'now' => $now,
            ]);

            if ($reservationKey !== null) {
                $updStmt = $pdo->prepare("
                    UPDATE ai_budget_reservations
                    SET settled = 1
                    WHERE reservation_key = :key
                ");
                $updStmt->execute(['key' => $reservationKey]);
            }

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::error("Budjet hisob-kitobini yopishda xato: " . $e->getMessage(), [], 'ai');
        }
    }

    public static function releaseBudget(?string $reservationKey): void
    {
        if ($reservationKey === null) {
            return;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM ai_budget_reservations WHERE reservation_key = :key");
        $stmt->execute(['key' => $reservationKey]);
    }

    public static function getSummaryStats(?int $chatId = null): array
    {
        $pdo = Database::getConnection();
        $todayStart = gmdate('Y-m-d 00:00:00');
        $monthStart = gmdate('Y-m-01 00:00:00');

        $chatFilter = $chatId !== null ? "AND chat_id = :chat_id" : "";
        $params = $chatId !== null ? ['chat_id' => $chatId] : [];

        $stmtToday = $pdo->prepare("
            SELECT COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_usd), 0) as cost
            FROM ai_usage
            WHERE created_at >= '{$todayStart}' {$chatFilter}
        ");
        $stmtToday->execute($params);
        $today = $stmtToday->fetch();

        $stmtMonth = $pdo->prepare("
            SELECT COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_usd), 0) as cost
            FROM ai_usage
            WHERE created_at >= '{$monthStart}' {$chatFilter}
        ");
        $stmtMonth->execute($params);
        $month = $stmtMonth->fetch();

        return [
            'today_requests' => (int)($today['requests'] ?? 0),
            'today_tokens' => (int)($today['tokens'] ?? 0),
            'today_cost_usd' => (float)($today['cost'] ?? 0.0),
            'month_requests' => (int)($month['requests'] ?? 0),
            'month_tokens' => (int)($month['tokens'] ?? 0),
            'month_cost_usd' => (float)($month['cost'] ?? 0.0),
            'daily_limit_usd' => Config::getFloat('AI_DAILY_BUDGET_USD', 1.00),
            'monthly_limit_usd' => Config::getFloat('AI_MONTHLY_BUDGET_USD', 25.00),
            'is_available' => self::isBudgetAvailable(0.0001),
        ];
    }
}
