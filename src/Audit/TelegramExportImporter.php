<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\Logger;
use App\Moderation\TextModerator;
use RuntimeException;
use Throwable;
use ZipArchive;

class TelegramExportImporter
{
    private TextModerator $textModerator;

    public function __construct(?TextModerator $textModerator = null)
    {
        $this->textModerator = $textModerator ?? new TextModerator();
    }

    /**
     * Telegram Desktop ZIP arxivini xavfsiz ochish (Zip-Bomb va Path Traversal himoyasi bilan)
     */
    public function extractSafeZip(string $zipPath, string $extractToDir): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("PHP ZipArchive kengaytmasi o'rnatilmagan.");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("ZIP arxivni ochib bo'lmadi.");
        }

        $maxFiles = 5000;
        $maxTotalSize = 500 * 1024 * 1024; // 500 MB limit
        $totalSize = 0;

        if ($zip->numFiles > $maxFiles) {
            $zip->close();
            throw new RuntimeException("Xavfsizlik xatosi: ZIP arxivda fayllar soni juda ko'p (Zip bomb gumoni).");
        }

        // Barcha fayl nomlarini tekshirish
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Path traversal himoyasi: ../ yoki / bilan boshlanishini taqiqlash
            $normalizedName = str_replace('\\', '/', $filename);
            if (str_contains($normalizedName, '../') || str_starts_with($normalizedName, '/') || preg_match('/^[A-Za-z]:/', $normalizedName)) {
                $zip->close();
                throw new RuntimeException("Xavfsizlik xatosi: Arxivda taqiqlangan fayl yo'li mavjud (Path traversal).");
            }

            $stat = $zip->statIndex($i);
            $totalSize += (int)($stat['size'] ?? 0);
            if ($totalSize > $maxTotalSize) {
                $zip->close();
                throw new RuntimeException("Xavfsizlik xatosi: Arxiv hajmi ruxsat etilgan limitdan oshdi (Zip bomb).");
            }
        }

        if (!is_dir($extractToDir)) {
            @mkdir($extractToDir, 0755, true);
        }

        $zip->extractTo($extractToDir);
        $zip->close();

        return $extractToDir;
    }

    /**
     * result.json faylini o'qib, audit sessiyasiga import qilish
     */
    public function importJson(string $jsonFilePath, int $chatId, int $auditSessionId): array
    {
        if (!file_exists($jsonFilePath) || !is_readable($jsonFilePath)) {
            throw new RuntimeException("JSON eksport fayli topilmadi: {$jsonFilePath}");
        }

        $pdo = Database::getConnection();
        $handle = fopen($jsonFilePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Faylni o'qish uchun ochib bo'lmadi.");
        }

        $maxJsonBytes = 64 * 1024 * 1024;
        if ((int)filesize($jsonFilePath) > $maxJsonBytes) {
            fclose($handle);
            throw new RuntimeException("JSON eksport fayli 64 MB limitdan katta. Uni qismlarga ajrating.");
        }

        // Telegram Bot orqali qabul qilinadigan fayllar kichik bo'lgani uchun xavfsiz limit bilan o'qiymiz.
        $content = stream_get_contents($handle);
        fclose($handle);

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['messages'])) {
            throw new RuntimeException("Telegram Desktop formati yaroqsiz (messages maydoni topilmadi).");
        }

        $sessionStmt = $pdo->prepare("SELECT ai_mode FROM audit_sessions WHERE id = :sid");
        $sessionStmt->execute(['sid' => $auditSessionId]);
        $aiMode = (string)($sessionStmt->fetchColumn() ?: 'economical');

        $messages = $data['messages'];
        $totalImported = 0;
        $totalFlagged = 0;
        $duplicatesSkipped = 0;

        $exportDir = dirname($jsonFilePath);

        $now = gmdate('Y-m-d H:i:s');
        $insStmt = $pdo->prepare("
            INSERT INTO messages (chat_id, message_id, user_id, raw_text, media_type, status, message_date, created_at)
            VALUES (:cid, :mid, :uid, :txt, :mtype, 'active', :mdate, :now)
        ");

        $auditItemStmt = $pdo->prepare("
            INSERT INTO audit_items (audit_session_id, chat_id, message_id, user_id, message_date, category, status, reason, evidence, is_media, created_at)
            VALUES (:sid, :cid, :mid, :uid, :mdate, :cat, :st, :reason, :evi, :ismedia, :now)
        ");

        $checkExistStmt = $pdo->prepare("SELECT id FROM messages WHERE chat_id = :cid AND message_id = :mid");

        foreach ($messages as $msg) {
            $msgType = $msg['type'] ?? '';
            if ($msgType !== 'message') {
                continue;
            }

            $messageId = (int)($msg['id'] ?? 0);
            if ($messageId <= 0) {
                continue;
            }

            // Dublikat tekshiruvi
            $checkExistStmt->execute(['cid' => $chatId, 'mid' => $messageId]);
            if ($checkExistStmt->fetch()) {
                $duplicatesSkipped++;
                continue;
            }

            $fromIdStr = (string)($msg['from_id'] ?? '');
            $userId = (int)preg_replace('/\D/', '', $fromIdStr);

            $rawText = is_array($msg['text'] ?? '') ? $this->extractTextFromParts($msg['text']) : (string)($msg['text'] ?? '');
            $messageDate = !empty($msg['date']) ? gmdate('Y-m-d H:i:s', strtotime((string)$msg['date'])) : $now;

            $mediaType = 'text';
            $isMedia = 0;
            if (!empty($msg['photo'])) {
                $mediaType = 'photo';
                $isMedia = 1;
            } elseif (!empty($msg['media_type'])) {
                $mediaType = (string)$msg['media_type'];
                $isMedia = 1;
            }

            // Xabarni bazaga kiritish
            $insStmt->execute([
                'cid' => $chatId,
                'mid' => $messageId,
                'uid' => $userId ?: null,
                'txt' => $rawText,
                'mtype' => $mediaType,
                'mdate' => $messageDate,
                'now' => $now,
            ]);
            $totalImported++;

            // Matn bo'lsa moderatsiyadan o'tkazish
            if (!empty(trim($rawText))) {
                $finding = $this->textModerator->inspect($rawText, [], $aiMode, $chatId, "imp_{$messageId}");
                if ($finding['status'] === 'unsafe' || $finding['status'] === 'review') {
                    $totalFlagged++;
                    $auditItemStmt->execute([
                        'sid' => $auditSessionId,
                        'cid' => $chatId,
                        'mid' => $messageId,
                        'uid' => $userId ?: null,
                        'mdate' => $messageDate,
                        'cat' => $finding['category'] ?? 'profanity',
                        'st' => $finding['status'],
                        'reason' => $finding['reason'] ?? 'Shubhali kontent',
                        'evi' => $finding['evidence'] ?? mb_substr($rawText, 0, 100),
                        'ismedia' => 0,
                        'now' => $now,
                    ]);
                }
            }

            // Media mavjud bo'lsa, fayl borligini tekshirish
            if ($isMedia) {
                $relPhoto = (string)($msg['photo'] ?? $msg['file'] ?? '');
                $fullMediaPath = $exportDir . DIRECTORY_SEPARATOR . $relPhoto;

                if (!empty($relPhoto) && (!file_exists($fullMediaPath) || !is_file($fullMediaPath))) {
                    $totalFlagged++;
                    $auditItemStmt->execute([
                        'sid' => $auditSessionId,
                        'cid' => $chatId,
                        'mid' => $messageId,
                        'uid' => $userId ?: null,
                        'mdate' => $messageDate,
                        'cat' => 'media_missing',
                        'st' => 'unscannable',
                        'reason' => "Tekshirilmadi: media fayli import papkasida mavjud emas",
                        'evi' => $relPhoto,
                        'ismedia' => 1,
                        'now' => $now,
                    ]);
                }
            }
        }

        // Audit sessiyasini yangilash
        $updSession = $pdo->prepare("
            UPDATE audit_sessions
            SET total_scanned = total_scanned + :scanned,
                total_flagged = total_flagged + :flagged,
                status = 'completed',
                updated_at = :now
            WHERE id = :sid
        ");
        $updSession->execute([
            'scanned' => $totalImported,
            'flagged' => $totalFlagged,
            'now' => $now,
            'sid' => $auditSessionId,
        ]);

        return [
            'imported' => $totalImported,
            'flagged' => $totalFlagged,
            'duplicates_skipped' => $duplicatesSkipped,
        ];
    }

    private function extractTextFromParts(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $text .= $part;
            } elseif (is_array($part) && isset($part['text'])) {
                $text .= (string)$part['text'];
            }
        }
        return $text;
    }
}
