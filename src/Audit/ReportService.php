<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Config;
use App\Core\Database;
use App\Policy\SettingsService;
use DateTime;
use DateTimeZone;
use PDO;
use Throwable;

class ReportService
{
    /** O'chirishga yaroqli qoidabuzarlik kategoriyalari (AuditCleanupJob bilan bir xil) */
    private const DELETABLE_CATEGORIES = [
        'profanity', 'pornography', 'adult_profile', 'hate_speech',
        'spam_ad', 'gambling', 'trading_scam', 'apk_distribution', 'malicious_link',
    ];

    /**
     * Audit / a'zolar sweep sessiyasi bo'yicha tozalash nishonlarini sanaydi.
     *
     * @return array{messages:int, adult:int, bots:int}
     */
    public static function countCleanupTargets(int $sessionId): array
    {
        $pdo = Database::getConnection();
        $session = $pdo->query("SELECT chat_id FROM audit_sessions WHERE id = " . (int)$sessionId)->fetch();
        $chatId = (int)($session['chat_id'] ?? 0);

        $catPlaceholders = implode(',', array_fill(0, count(self::DELETABLE_CATEGORIES), '?'));
        $msgStmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_items
            WHERE audit_session_id = ? AND status = 'unsafe'
              AND message_id IS NOT NULL AND message_id > 0
              AND (cleanup_status IS NULL OR cleanup_status = 'delete_failed')
              AND category IN ({$catPlaceholders})
        ");
        $msgStmt->execute(array_merge([$sessionId], self::DELETABLE_CATEGORIES));
        $messages = (int)$msgStmt->fetchColumn();

        $adultStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT user_id) FROM audit_items
            WHERE audit_session_id = :sid AND user_id IS NOT NULL AND user_id > 0
              AND category IN ('adult_profile', 'pornography')
        ");
        $adultStmt->execute(['sid' => $sessionId]);
        $adult = (int)$adultStmt->fetchColumn();

        $botStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT user_id) FROM audit_items
            WHERE audit_session_id = :sid AND user_id IS NOT NULL AND user_id > 0
              AND category = 'bot_account'
        ");
        $botStmt->execute(['sid' => $sessionId]);
        $bots = (int)$botStmt->fetchColumn();

        if ($chatId !== 0) {
            $chatBots = $pdo->prepare("
                SELECT COUNT(*) FROM chat_members c
                JOIN users u ON u.user_id = c.user_id
                WHERE c.chat_id = :cid AND u.is_bot = 1
                  AND c.role NOT IN ('creator', 'administrator')
                  AND c.is_whitelisted = 0 AND c.is_admin_exempt = 0
            ");
            $chatBots->execute(['cid' => $chatId]);
            $bots = max($bots, (int)$chatBots->fetchColumn());
        }

        return ['messages' => $messages, 'adult' => $adult, 'bots' => $bots];
    }

    /**
     * Hisobotdan keyin adminга yuboriladigan tasdiqli tozalash menyusi.
     *
     * @return array{text:string, reply_markup:array}|null
     */
    public static function buildCleanupPrompt(int $sessionId): ?array
    {
        $pdo = Database::getConnection();
        $session = $pdo->query("SELECT chat_id FROM audit_sessions WHERE id = " . (int)$sessionId)->fetch();
        if (!$session) {
            return null;
        }
        $settings = SettingsService::get((int)$session['chat_id']);
        if (!($settings['history_cleanup_enabled'] ?? true)) {
            return null;
        }

        $counts = self::countCleanupTargets($sessionId);
        $rows = [];
        if ($counts['messages'] > 0) {
            $rows[] = [['text' => "🗑 Belgilangan xabarlarni o'chirish ({$counts['messages']})", 'callback_data' => "audit_clean_msgs:{$sessionId}"]];
        }
        if ($counts['adult'] > 0) {
            $rows[] = [['text' => "🔇 18+ akkauntlarni cheklash ({$counts['adult']})", 'callback_data' => "audit_clean_adult:{$sessionId}"]];
        }
        if ($counts['bots'] > 0) {
            $rows[] = [['text' => "🤖 Bot akkauntlarni cheklash ({$counts['bots']})", 'callback_data' => "audit_clean_bots:{$sessionId}"]];
        }
        if ($rows === []) {
            return null;
        }
        $rows[] = [['text' => '✅ Tugatish (o\'zgarishsiz qoldirish)', 'callback_data' => "audit_clean_done:{$sessionId}"]];

        return [
            'text' => "🧹 <b>Tozalash amallari</b>\n"
                . "Har bir amal alohida tasdiqlanadi va <b>bekor qilib bo'lmaydi</b>. "
                . "Xabarlar Bot API orqali o'chiriladi (bot \"Delete messages\" huquqiga ega bo'lishi shart), "
                . "akkauntlar avval vaqtincha cheklanadi.",
            'reply_markup' => ['inline_keyboard' => $rows],
        ];
    }

    /**
     * Telegram uchun qisqa matnli hisobot tayyorlash
     */
    public static function generateTelegramSummary(int $sessionId): string
    {
        $pdo = Database::getConnection();

        $stmtSession = $pdo->prepare("SELECT * FROM audit_sessions WHERE id = :sid");
        $stmtSession->execute(['sid' => $sessionId]);
        $session = $stmtSession->fetch();

        if (!$session) {
            return "❌ Audit sessiyasi topilmadi.";
        }

        $chatId = (int)$session['chat_id'];
        $tzName = Config::get('APP_TIMEZONE', 'Asia/Tashkent');

        // Kategoriya bo'yicha hisob-kitoblar
        $stmtCats = $pdo->prepare("
            SELECT category, status, COUNT(*) as cnt
            FROM audit_items
            WHERE audit_session_id = :sid
            GROUP BY category, status
        ");
        $stmtCats->execute(['sid' => $sessionId]);
        $catCounts = $stmtCats->fetchAll();

        $profanityCount = 0;
        $pornCount = 0;
        $spamCount = 0;
        $unscannableCount = 0;
        $reviewCount = 0;

        foreach ($catCounts as $c) {
            $cat = $c['category'];
            $cnt = (int)$c['cnt'];
            if ($cat === 'profanity') $profanityCount += $cnt;
            elseif ($cat === 'pornography') $pornCount += $cnt;
            elseif (in_array($cat, ['spam_ad', 'malicious_link'], true)) $spamCount += $cnt;

            if ($c['status'] === 'unscannable') $unscannableCount += $cnt;
            if ($c['status'] === 'review') $reviewCount += $cnt;
        }

        $totalScanned = (int)$session['total_scanned'];
        $totalFlagged = (int)$session['total_flagged'];
        $costUsd = (float)$session['budget_spent_usd'];

        $createdDate = new DateTime($session['created_at'], new DateTimeZone('UTC'));
        $createdDate->setTimezone(new DateTimeZone($tzName));
        $formattedDate = $createdDate->format('Y-m-d H:i');

        $isMemberSweep = ($session['source_type'] ?? '') === 'member_sweep';
        $scannedLabel = $isMemberSweep ? "Tekshirilgan a'zolar" : "Tekshirilgan xabarlar";

        $out = $isMemberSweep
            ? "👥 <b>Guruh A'zolari Tekshiruvi Hisoboti</b>\n"
            : "📊 <b>Guruh Tarixiy Audit Hisoboti</b>\n";
        $out .= "• Guruh ID: <code>{$chatId}</code>\n";
        $out .= "• Manba: <b>" . strtoupper($session['source_type']) . "</b>\n";
        $out .= "• Sana: {$formattedDate} ({$tzName})\n";
        $out .= "• Holat: <b>" . strtoupper($session['status']) . "</b>\n";
        $out .= "----------------------------------------\n";
        $out .= "🔹 <b>{$scannedLabel}:</b> {$totalScanned}\n";
        $out .= "⚠️ <b>Topilmalar:</b> {$totalFlagged}\n";
        if ($isMemberSweep) {
            $adultAccts = 0;
            $botAccts = 0;
            foreach ($catCounts as $c) {
                if ($c['category'] === 'adult_profile') $adultAccts += (int)$c['cnt'];
                if ($c['category'] === 'bot_account') $botAccts += (int)$c['cnt'];
            }
            $out .= "  - 18+ profil akkauntlar: {$adultAccts}\n";
            $out .= "  - Ruxsatsiz bot akkauntlar: {$botAccts}\n";
        } else {
            $out .= "  - So'kinish va haqorat: {$profanityCount}\n";
            $out .= "  - Pornografik kontent: {$pornCount}\n";
            $out .= "  - Reklama va spam: {$spamCount}\n";
            $out .= "  - Tekshirib bo'lmagan: {$unscannableCount}\n";
            $out .= "  - Ko'rib chiqish talab etiladi: {$reviewCount}\n";
        }
        $out .= "----------------------------------------\n";
        $out .= "💵 <b>AI xarajati:</b> \${$costUsd}\n\n";

        // Tavsiyalar
        $out .= "💡 <b>Amaliy tavsiyalar:</b>\n";
        if ($pornCount > 0) {
            $out .= "• Guruhda pornografiya oqimi mavjud: Media filtrini va AI Vision tekshiruvini qat'iy yoqish tavsiya etiladi.\n";
        }
        if ($profanityCount > 5) {
            $out .= "• So'kinish darajasi yuqori: Ogohlantirishlar chegarasini 2 tagacha kamaytirish va 1 soatlik mute qo'llash tavsiya etiladi.\n";
        }
        if ($totalFlagged === 0) {
            $out .= $isMemberSweep
                ? "• Tekshirilgan a'zolar orasida 18+ yoki bot akkaunt aniqlanmadi.\n"
                : "• Tekshirilgan xabarlar doirasida jiddiy qoidabuzarlik aniqlanmadi.\n";
        } else {
            $out .= "• Quyidagi tugmalar orqali topilmalarni tasdiq bilan tozalashingiz mumkin.\n";
        }

        $out .= $isMemberSweep
            ? "\n<i>To'liq ro'yxatni /audit_report buyrug'idan yuklab oling.</i>"
            : "\n<i>To'liq HTML va CSV hisobotni yuklab olish uchun /audit_report buyrug'idan foydalaning.</i>";
        return $out;
    }

    /**
     * To'liq xavfsiz HTML hisobot yaratish (XSS himoyasi bilan)
     */
    public static function generateHtmlReport(int $sessionId): string
    {
        $pdo = Database::getConnection();

        $stmtSession = $pdo->prepare("SELECT * FROM audit_sessions WHERE id = :sid");
        $stmtSession->execute(['sid' => $sessionId]);
        $session = $stmtSession->fetch();

        if (!$session) {
            return "<h1>Audit sessiyasi topilmadi</h1>";
        }

        $stmtItems = $pdo->prepare("
            SELECT * FROM audit_items
            WHERE audit_session_id = :sid
            ORDER BY id ASC
            LIMIT 1000
        ");
        $stmtItems->execute(['sid' => $sessionId]);
        $items = $stmtItems->fetchAll();

        $chatId = htmlspecialchars((string)$session['chat_id'], ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars((string)$session['status'], ENT_QUOTES, 'UTF-8');
        $totalScanned = (int)$session['total_scanned'];
        $totalFlagged = (int)$session['total_flagged'];

        $rowsHtml = '';
        foreach ($items as $item) {
            $mid = (int)($item['message_id'] ?? 0);
            $uid = (int)($item['user_id'] ?? 0);
            $cat = htmlspecialchars((string)$item['category'], ENT_QUOTES, 'UTF-8');
            $st = htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8');
            $reason = htmlspecialchars((string)$item['reason'], ENT_QUOTES, 'UTF-8');
            $evidence = htmlspecialchars((string)$item['evidence'], ENT_QUOTES, 'UTF-8');
            $mdate = htmlspecialchars((string)$item['message_date'], ENT_QUOTES, 'UTF-8');

            $statusBadge = match ($st) {
                'unsafe' => '<span style="color:#e53e3e;font-weight:bold;">UNSAFE</span>',
                'review' => '<span style="color:#dd6b20;font-weight:bold;">REVIEW</span>',
                'unscannable' => '<span style="color:#718096;">UNSCANNABLE</span>',
                default => '<span style="color:#38a169;">SAFE</span>',
            };

            $rowsHtml .= "<tr>
                <td>{$mid}</td>
                <td>{$uid}</td>
                <td>{$mdate}</td>
                <td>{$cat}</td>
                <td>{$statusBadge}</td>
                <td>{$reason}</td>
                <td><code>{$evidence}</code></td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Guruh Tarixiy Moderatsiya Auditi</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 30px; background: #f7fafc; color: #2d3748; }
        .card { background: white; border-radius: 8px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }
        h1 { margin-top: 0; color: #1a202c; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; border: 1px solid #e2e8f0; text-align: left; font-size: 14px; }
        th { background: #edf2f7; font-weight: 600; }
        code { background: #edf2f7; padding: 2px 6px; border-radius: 4px; font-size: 13px; color: #c53030; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Guruh Tarixiy Moderatsiya Auditi</h1>
        <p><strong>Guruh ID:</strong> {$chatId} | <strong>Holat:</strong> {$status}</p>
        <p><strong>Jami o'qilgan xabarlar:</strong> {$totalScanned} | <strong>Qoidabuzarliklar:</strong> {$totalFlagged}</p>
    </div>
    <div class="card">
        <h2>Topilmalar Ro'yxati</h2>
        <table>
            <thead>
                <tr>
                    <th>Xabar ID</th>
                    <th>Foydalanuvchi</th>
                    <th>Sana</th>
                    <th>Kategoriya</th>
                    <th>Holat</th>
                    <th>Sabab</th>
                    <th>Dalil</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
            </tbody>
        </table>
    </div>
</body>
</html>
HTML;
    }

    /**
     * CSV eksport (CSV Formula Injection va Excel hujumlaridan himoyalangan)
     */
    public static function generateCsvReport(int $sessionId): string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM audit_items WHERE audit_session_id = :sid ORDER BY id ASC");
        $stmt->execute(['sid' => $sessionId]);
        $items = $stmt->fetchAll();

        $fp = fopen('php://temp', 'r+');

        // CSV sarlavhalari
        fputcsv($fp, ['ID', 'Xabar ID', 'Foydalanuvchi ID', 'Sana', 'Kategoriya', 'Holat', 'Sabab', 'Dalil', 'Media']);

        foreach ($items as $item) {
            fputcsv($fp, [
                $item['id'],
                $item['message_id'],
                $item['user_id'],
                $item['message_date'],
                self::escapeCsvFormula((string)$item['category']),
                self::escapeCsvFormula((string)$item['status']),
                self::escapeCsvFormula((string)$item['reason']),
                self::escapeCsvFormula((string)$item['evidence']),
                $item['is_media'] ? 'HA' : 'YO\'Q',
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /**
     * CSV Formula Injection himoyasi:
     * Agar matn =, +, -, @, |, %, \t, \r belgilari bilan (yoki probeldan so'ng shu belgilar bilan) boshlansa, oldiga ' qo'yish
     */
    public static function escapeCsvFormula(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $trimmed = ltrim($value);
        if ($trimmed !== '') {
            $firstChar = $trimmed[0];
            if (in_array($firstChar, ['=', '+', '-', '@', '|', '%', "\t", "\r"], true)) {
                return "'" . $value;
            }
        }

        return $value;
    }
}
