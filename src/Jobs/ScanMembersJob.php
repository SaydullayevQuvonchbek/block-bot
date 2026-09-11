<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditService;
use App\Audit\MtprotoHistoryReader;
use App\Audit\ReportService;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use App\Moderation\ProfileModerator;
use App\Policy\SettingsService;
use Throwable;

/**
 * Guruhning ma'lum a'zolarini 18+ profil va ruxsatsiz bot akkauntlarga tekshiradi.
 * Natijalar `member_sweep` turidagi audit sessiyasiga (`audit_items`) yoziladi va
 * shu yerdan admin tugmalari orqali tozalanadi.
 *
 * Cheklov: Bot API to'liq a'zolar ro'yxatini bermaydi — nomzodlar bot ko'rgan
 * (`chat_members`, `messages`) yoki MTProto ishtirokchilar ro'yxatidan olinadi.
 */
class ScanMembersJob
{
    private const MAX_CANDIDATES = 400;

    public function handle(array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);
        $chatId = (int)($data['chat_id'] ?? 0);
        $notifyChatId = (int)($data['notify_chat_id'] ?? 0);

        if ($sessionId <= 0 || $chatId === 0) {
            return;
        }

        $session = AuditService::get($sessionId);
        if (!$session || in_array($session['status'], ['cancelled', 'completed', 'failed'], true)) {
            return;
        }

        $settings = SettingsService::get($chatId);
        $telegram = new TelegramClient();

        try {
            $candidates = $this->collectCandidates($chatId);
            $capped = count($candidates) > self::MAX_CANDIDATES;
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);

            $profileModerator = new ProfileModerator();
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $insert = $pdo->prepare("
                INSERT INTO audit_items (audit_session_id, chat_id, message_id, user_id, message_date, category, status, reason, evidence, is_media, created_at)
                VALUES (:sid, :cid, NULL, :uid, NULL, :cat, :status, :reason, :evi, 0, :now)
            ");

            $scanned = 0;
            $flagged = 0;
            foreach ($candidates as $candidate) {
                $scanned++;
                // force=true: sweep har doim yangi tekshiruv qiladi (7 kunlik keshni chetlab o'tadi).
                $finding = $profileModerator->inspectUser($candidate, $chatId, true, (bool)($settings['bot_filter'] ?? true));
                if (($finding['status'] ?? 'safe') !== 'unsafe') {
                    usleep(20000);
                    continue;
                }
                $category = (string)($finding['category'] ?? 'adult_profile');
                if ($category === 'pornography') {
                    $category = 'adult_profile';
                }
                if (!in_array($category, ['adult_profile', 'bot_account'], true)) {
                    // Ism ichidagi oddiy so'kinish kabi — 18+/bot sweep doirasidan tashqarida.
                    continue;
                }
                $flagged++;
                $insert->execute([
                    'sid' => $sessionId,
                    'cid' => $chatId,
                    'uid' => (int)($candidate['id'] ?? 0),
                    'cat' => $category,
                    'status' => 'unsafe',
                    'reason' => mb_substr((string)($finding['reason'] ?? ''), 0, 500),
                    'evi' => mb_substr((string)($finding['evidence'] ?? ''), 0, 255),
                    'now' => $now,
                ]);
                usleep(20000);
            }

            $pdo->prepare("
                UPDATE audit_sessions
                SET total_scanned = :scanned, total_flagged = :flagged, status = 'completed', updated_at = :now
                WHERE id = :sid
            ")->execute(['scanned' => $scanned, 'flagged' => $flagged, 'now' => $now, 'sid' => $sessionId]);

            $target = $notifyChatId !== 0 ? $notifyChatId : $chatId;
            $telegram->sendMessage($target, ReportService::generateTelegramSummary($sessionId)
                . ($capped ? "\n\n⚠️ Nomzodlar soni " . self::MAX_CANDIDATES . " tadan oshdi; qolgani keyingi skanda tekshiriladi." : ''));

            $prompt = ReportService::buildCleanupPrompt($sessionId);
            if ($prompt !== null) {
                $telegram->sendMessage($target, $prompt['text'], ['reply_markup' => $prompt['reply_markup']]);
            }
        } catch (Throwable $e) {
            AuditService::markFailed($sessionId, $e->getMessage());
            Logger::error("ScanMembersJob xatosi #{$sessionId}: " . $e->getMessage(), [], 'audit');
            if ($notifyChatId !== 0) {
                $telegram->sendMessage($notifyChatId, "❌ A'zolarni tekshirish bajarilmadi: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }
    }

    /**
     * @return array<int, array{id:int, first_name:string, last_name:?string, username:?string, is_bot:bool}>
     */
    private function collectCandidates(int $chatId): array
    {
        $pdo = Database::getConnection();
        $byId = [];

        $seed = static function (array $rows) use (&$byId): void {
            foreach ($rows as $row) {
                $uid = (int)($row['user_id'] ?? $row['id'] ?? 0);
                if ($uid <= 0 || isset($byId[$uid])) {
                    continue;
                }
                $byId[$uid] = [
                    'id' => $uid,
                    'first_name' => (string)($row['first_name'] ?? ''),
                    'last_name' => $row['last_name'] ?? null,
                    'username' => $row['username'] ?? null,
                    'is_bot' => (bool)($row['is_bot'] ?? false),
                ];
            }
        };

        // 1. Bot ko'rgan a'zolar (admin/creator/oq ro'yxat chetda).
        $stmt = $pdo->prepare("
            SELECT c.user_id, u.first_name, u.last_name, u.username, u.is_bot
            FROM chat_members c
            LEFT JOIN users u ON u.user_id = c.user_id
            WHERE c.chat_id = :cid
              AND c.role NOT IN ('creator', 'administrator')
              AND c.is_whitelisted = 0
              AND c.is_admin_exempt = 0
              AND c.role <> 'left'
        ");
        $stmt->execute(['cid' => $chatId]);
        $seed($stmt->fetchAll());

        // 2. Guruh xabarlarida ko'ringan foydalanuvchilar (import yoki real vaqt).
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.user_id, u.first_name, u.last_name, u.username, u.is_bot
            FROM messages m
            LEFT JOIN users u ON u.user_id = m.user_id
            WHERE m.chat_id = :cid AND m.user_id IS NOT NULL AND m.user_id > 0
            LIMIT 2000
        ");
        $stmt->execute(['cid' => $chatId]);
        $seed($stmt->fetchAll());

        // 3. MTProto sozlangan bo'lsa — to'liq ishtirokchilar ro'yxati.
        try {
            $reader = new MtprotoHistoryReader();
            if ($reader->isConfigured() && method_exists($reader, 'getParticipants')) {
                $seed($reader->getParticipants($chatId, 500));
            }
        } catch (Throwable $e) {
            Logger::warning("ScanMembersJob MTProto ishtirokchilar xatosi: " . $e->getMessage(), [], 'mtproto');
        }

        // Adminlarni yakuniy filtr (chat_members keshida bo'lmaganlar uchun).
        $adminIds = [];
        $adminStmt = $pdo->prepare("SELECT user_id FROM chat_members WHERE chat_id = :cid AND role IN ('creator', 'administrator')");
        $adminStmt->execute(['cid' => $chatId]);
        foreach ($adminStmt->fetchAll(\PDO::FETCH_COLUMN) as $adminId) {
            $adminIds[(int)$adminId] = true;
        }

        return array_values(array_filter($byId, static fn (array $u): bool => !isset($adminIds[$u['id']])));
    }
}
