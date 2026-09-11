<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Moderation\TextModerator;
use RuntimeException;
use Throwable;

class MtprotoHistoryReader
{
    private string $apiId;
    private string $apiHash;
    private string $sessionPath;
    private array $allowedChats;

    public function __construct()
    {
        $this->apiId = (string)Config::get('MTPROTO_API_ID', '');
        $this->apiHash = (string)Config::get('MTPROTO_API_HASH', '');
        $this->sessionPath = (string)Config::get('MTPROTO_SESSION_PATH', 'storage/sessions/mtproto.session');

        $allowedStr = (string)Config::get('MTPROTO_ALLOWED_CHATS', '');
        $this->allowedChats = !empty($allowedStr) ? array_map('intval', explode(',', $allowedStr)) : [];
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiId) && !empty($this->apiHash) && $this->allowedChats !== [];
    }

    /**
     * MTProto orqali guruh tarixini bosqichma-bosqich (checkpoint bilan) o'qish
     */
    public function scanHistory(int $chatId, int $auditSessionId, int $limit = 100, ?int $fromMessageId = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("MTProto sozlanmagan. .env faylida MTPROTO_API_ID va MTPROTO_API_HASH ko'rsatilishi kerak.");
        }

        // Guruh allowlist'da borligini tekshirish
        if ($this->allowedChats === [] || !in_array($chatId, $this->allowedChats, true)) {
            throw new RuntimeException("Guruh [{$chatId}] MTProto ruxsat etilgan guruhlar (allowlist) ro'yxatida yo'q.");
        }

        $pdo = Database::getConnection();

        // Checkpoint ma'lumotlarini olish
        $stmtSession = $pdo->prepare("SELECT checkpoint_message_id, status FROM audit_sessions WHERE id = :sid");
        $stmtSession->execute(['sid' => $auditSessionId]);
        $session = $stmtSession->fetch();

        if (!$session) {
            throw new RuntimeException("Audit sessiyasi topilmadi: {$auditSessionId}");
        }

        if ($session['status'] === 'cancelled' || $session['status'] === 'paused') {
            return ['status' => $session['status'], 'scanned' => 0];
        }

        $offsetId = $fromMessageId ?? (int)($session['checkpoint_message_id'] ?? 0);

        Logger::info("MTProto tarixini o'qish boshlandi: Chat {$chatId}, Offset: {$offsetId}, Limit: {$limit}", [], 'mtproto');

        // MadelineProto kutubxonasi mavjudligini tekshirish
        if (!class_exists('\danog\MadelineProto\API')) {
            $message = "MadelineProto o'rnatilmagan. CLI orqali 'composer require danog/madelineproto' bajaring.";
            Logger::warning($message, [], 'mtproto');
            AuditService::markFailed($auditSessionId, $message);
            return [
                'status' => 'failed',
                'scanned' => 0,
                'message' => $message,
            ];
        }

        $sessionPath = $this->sessionPath;
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $sessionPath)) {
            $sessionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $sessionPath;
        }

        $settings = [
            'app_info' => ['api_id' => (int)$this->apiId, 'api_hash' => $this->apiHash],
        ];
        $madeline = new \danog\MadelineProto\API($sessionPath, $settings);
        $madeline->start();
        $history = $madeline->messages->getHistory([
            'peer' => $chatId,
            'offset_id' => $offsetId,
            'limit' => max(1, min(100, $limit)),
        ]);
        $messages = is_array($history['messages'] ?? null) ? $history['messages'] : [];

        $textModerator = new TextModerator();
        $scanned = 0;
        $flagged = 0;
        $lastMessageId = $offsetId;
        $now = gmdate('Y-m-d H:i:s');
        $insert = $pdo->prepare("
            INSERT INTO audit_items (audit_session_id, chat_id, message_id, user_id, message_date, category, status, reason, evidence, is_media, created_at)
            VALUES (:sid, :cid, :mid, :uid, :mdate, :cat, :status, :reason, :evidence, :media, :now)
        ");

        foreach ($messages as $message) {
            $messageId = (int)($message['id'] ?? 0);
            if ($messageId <= 0 || isset($message['action'])) {
                continue;
            }
            $scanned++;
            $lastMessageId = $lastMessageId === 0 ? $messageId : min($lastMessageId, $messageId);
            $text = (string)($message['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $finding = $textModerator->inspect($text, [], (string)($session['ai_mode'] ?? 'economical'), $chatId, "mtp_{$messageId}");
            if (($finding['status'] ?? 'safe') === 'safe') {
                continue;
            }
            $flagged++;
            $fromId = is_array($message['from_id'] ?? null) ? (int)($message['from_id']['user_id'] ?? 0) : (int)($message['from_id'] ?? 0);
            $insert->execute([
                'sid' => $auditSessionId,
                'cid' => $chatId,
                'mid' => $messageId,
                'uid' => $fromId ?: null,
                'mdate' => isset($message['date']) ? gmdate('Y-m-d H:i:s', (int)$message['date']) : null,
                'cat' => $finding['category'] ?? 'general',
                'status' => $finding['status'] ?? 'review',
                'reason' => $finding['reason'] ?? 'Shubhali kontent',
                'evidence' => $finding['evidence'] ?? '',
                'media' => isset($message['media']) ? 1 : 0,
                'now' => $now,
            ]);
        }

        $completed = count($messages) < $limit || $scanned === 0;
        $status = $completed ? 'completed' : 'running';
        $pdo->prepare("
            UPDATE audit_sessions
            SET total_scanned = total_scanned + :scanned,
                total_flagged = total_flagged + :flagged,
                checkpoint_message_id = :checkpoint,
                status = :status,
                updated_at = :now
            WHERE id = :sid
        ")->execute([
            'scanned' => $scanned,
            'flagged' => $flagged,
            'checkpoint' => $lastMessageId ?: null,
            'status' => $status,
            'now' => $now,
            'sid' => $auditSessionId,
        ]);

        return ['status' => $status, 'scanned' => $scanned, 'flagged' => $flagged, 'offset_id' => $lastMessageId];
    }

    /**
     * MTProto orqali guruh ishtirokchilari ro'yxatini olish (a'zolar sweep uchun).
     *
     * @return array<int, array{id:int, first_name:string, last_name:?string, username:?string, is_bot:bool}>
     */
    public function getParticipants(int $chatId, int $limit = 500): array
    {
        if (!$this->isConfigured() || !class_exists('\danog\MadelineProto\API')) {
            return [];
        }
        if ($this->allowedChats !== [] && !in_array($chatId, $this->allowedChats, true)) {
            return [];
        }

        $sessionPath = $this->sessionPath;
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $sessionPath)) {
            $sessionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $sessionPath;
        }

        try {
            $madeline = new \danog\MadelineProto\API($sessionPath, [
                'app_info' => ['api_id' => (int)$this->apiId, 'api_hash' => $this->apiHash],
            ]);
            $madeline->start();
            $result = $madeline->getPwrChat($chatId);
            $participants = is_array($result['participants'] ?? null) ? $result['participants'] : [];

            $out = [];
            foreach (array_slice($participants, 0, max(1, $limit)) as $p) {
                $user = $p['user'] ?? $p;
                $uid = (int)($user['id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $out[] = [
                    'id' => $uid,
                    'first_name' => (string)($user['first_name'] ?? ''),
                    'last_name' => $user['last_name'] ?? null,
                    'username' => $user['username'] ?? null,
                    'is_bot' => (bool)($user['bot'] ?? $user['is_bot'] ?? false),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            Logger::warning("MTProto getParticipants xatosi: " . $e->getMessage(), ['chat_id' => $chatId], 'mtproto');
            return [];
        }
    }

    /**
     * Audit checkpoint'ini saqlash
     */
    public static function updateCheckpoint(int $auditSessionId, int $lastMessageId, ?string $lastDate = null): void
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                UPDATE audit_sessions
                SET checkpoint_message_id = :mid,
                    checkpoint_offset_date = :mdate,
                    updated_at = :now
                WHERE id = :sid
            ");
            $stmt->execute([
                'mid' => $lastMessageId,
                'mdate' => $lastDate,
                'now' => $now,
                'sid' => $auditSessionId,
            ]);
        } catch (Throwable $e) {
            Logger::error("MTProto checkpoint saqlashda xato: " . $e->getMessage(), [], 'mtproto');
        }
    }
}
