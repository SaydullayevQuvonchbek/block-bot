<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditService;
use App\Audit\ReportService;
use App\Audit\TelegramExportImporter;
use App\Core\Logger;
use App\Core\TelegramClient;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class ImportAuditJob
{
    public function handle(array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $chatId = (int)($data['chat_id'] ?? 0);
        $notifyChatId = (int)($data['notify_chat_id'] ?? $chatId);
        $fileId = (string)($data['file_id'] ?? '');
        $originalName = basename((string)($data['file_name'] ?? 'result.json'));

        if ($sessionId <= 0 || $chatId === 0 || $fileId === '') {
            throw new RuntimeException("Audit import ma'lumotlari to'liq emas");
        }

        $telegram = new TelegramClient();
        $tempRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
        $workDir = $tempRoot . DIRECTORY_SEPARATOR . 'audit_' . $sessionId . '_' . bin2hex(random_bytes(4));
        if (!is_dir($workDir) && !mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            throw new RuntimeException("Audit vaqtinchalik papkasini yaratib bo'lmadi");
        }

        try {
            $fileInfo = $telegram->getFile($fileId);
            if (!$fileInfo || empty($fileInfo['file_path'])) {
                throw new RuntimeException("Telegram'dan audit fayli ma'lumotini olib bo'lmadi");
            }
            $maxBytes = 20 * 1024 * 1024;
            if ((int)($fileInfo['file_size'] ?? 0) > $maxBytes) {
                throw new RuntimeException("Audit fayli 20 MB limitdan katta");
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['json', 'zip'], true)) {
                throw new RuntimeException("Faqat .json yoki .zip audit fayli qabul qilinadi");
            }

            $downloadPath = $workDir . DIRECTORY_SEPARATOR . $originalName;
            if (!$telegram->downloadFile((string)$fileInfo['file_path'], $downloadPath)) {
                throw new RuntimeException("Audit faylini Telegram'dan yuklab bo'lmadi");
            }

            $importer = new TelegramExportImporter();
            $jsonPath = $downloadPath;
            if ($extension === 'zip') {
                $extractDir = $workDir . DIRECTORY_SEPARATOR . 'extracted';
                $importer->extractSafeZip($downloadPath, $extractDir);
                $jsonPath = $this->findResultJson($extractDir);
            }

            AuditService::markRunning($sessionId);
            $result = $importer->importJson($jsonPath, $chatId, $sessionId);
            $telegram->sendMessage($notifyChatId, "✅ Audit yakunlandi. Tekshirildi: {$result['imported']}, topilmalar: {$result['flagged']}.");
            $this->sendReports($telegram, $notifyChatId, $sessionId, $workDir);

            $prompt = ReportService::buildCleanupPrompt($sessionId);
            if ($prompt !== null) {
                $telegram->sendMessage($notifyChatId, $prompt['text'], ['reply_markup' => $prompt['reply_markup']]);
            }
        } catch (Throwable $e) {
            AuditService::markFailed($sessionId, $e->getMessage());
            Logger::error("Audit import xatosi #{$sessionId}: " . $e->getMessage(), [], 'audit');
            $telegram->sendMessage($notifyChatId, "❌ Audit bajarilmadi: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function findResultJson(string $directory): string
    {
        $fallback = null;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            if (strtolower($file->getFilename()) === 'result.json') {
                return $file->getPathname();
            }
            $fallback ??= $file->getPathname();
        }
        if ($fallback === null) {
            throw new RuntimeException("ZIP ichida Telegram result.json fayli topilmadi");
        }
        return $fallback;
    }

    private function sendReports(TelegramClient $telegram, int $chatId, int $sessionId, string $workDir): void
    {
        $htmlPath = $workDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}.html";
        $csvPath = $workDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}.csv";
        file_put_contents($htmlPath, ReportService::generateHtmlReport($sessionId));
        file_put_contents($csvPath, ReportService::generateCsvReport($sessionId));
        $telegram->sendDocument($chatId, $htmlPath, "Audit #{$sessionId} — HTML hisobot");
        $telegram->sendDocument($chatId, $csvPath, "Audit #{$sessionId} — CSV hisobot");
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
