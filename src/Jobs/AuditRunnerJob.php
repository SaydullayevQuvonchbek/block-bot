<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditService;
use App\Audit\MtprotoHistoryReader;
use App\Audit\ReportService;
use App\Core\Database;
use App\Core\Logger;
use App\Core\QueueService;
use App\Core\TelegramClient;

class AuditRunnerJob
{
    public function handle(array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $chatId = (int)($data['chat_id'] ?? 0);

        if ($sessionId <= 0 || $chatId === 0) {
            return;
        }

        $session = AuditService::get($sessionId);
        if (!$session || in_array($session['status'], ['paused', 'cancelled', 'completed'], true)) {
            return;
        }

        Logger::info("Audit sessiyasi #{$sessionId} fon vazifasi ishlamoqda...", [], 'audit');

        $sourceType = $session['source_type'] ?? 'json_export';
        $adminUserId = (int)($session['admin_user_id'] ?? 0);
        $notifyChatId = (int)($data['notify_chat_id'] ?? ($adminUserId > 0 ? $adminUserId : $chatId));

        if ($sourceType === 'mtproto') {
            $reader = new MtprotoHistoryReader();
            if ($reader->isConfigured()) {
                $result = $reader->scanHistory($chatId, $sessionId, 50);
                if ($result['status'] === 'running' && ($result['scanned'] ?? 0) > 0) {
                    // Keyingi paketni navbatga qo'yish
                    QueueService::push('App\Jobs\AuditRunnerJob', array_merge($data, ['notify_chat_id' => $notifyChatId]), QueueService::PRIORITY_LOW, 2);
                } elseif ($result['status'] === 'completed') {
                    $this->deliverReport($notifyChatId, $sessionId, $chatId);
                }
            }
        }

        // Baza statusini yangilash
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE audit_sessions SET updated_at = :now WHERE id = :sid")
            ->execute(['now' => gmdate('Y-m-d H:i:s'), 'sid' => $sessionId]);
    }

    private function deliverReport(int $targetChatId, int $sessionId, ?int $groupChatId = null): void
    {
        $telegram = new TelegramClient();
        $telegram->sendMessage($targetChatId, ReportService::generateTelegramSummary($sessionId));
        $tempDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
        $suffix = bin2hex(random_bytes(4));
        $htmlPath = $tempDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}_{$suffix}.html";
        $csvPath = $tempDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}_{$suffix}.csv";
        try {
            file_put_contents($htmlPath, ReportService::generateHtmlReport($sessionId));
            file_put_contents($csvPath, ReportService::generateCsvReport($sessionId));
            $telegram->sendDocument($targetChatId, $htmlPath, "Audit #{$sessionId} — HTML hisobot");
            $telegram->sendDocument($targetChatId, $csvPath, "Audit #{$sessionId} — CSV hisobot");
        } finally {
            @unlink($htmlPath);
            @unlink($csvPath);
        }

        $prompt = ReportService::buildCleanupPrompt($sessionId);
        if ($prompt !== null) {
            $telegram->sendMessage($targetChatId, $prompt['text'], ['reply_markup' => $prompt['reply_markup']]);
        }

        if ($groupChatId !== null && $groupChatId !== $targetChatId) {
            $telegram->sendMessage($groupChatId, "✅ Guruh auditi yakunlandi. To'liq hisobot administrator shaxsiy chatiga yetkazildi.");
        }
    }
}
