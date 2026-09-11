<?php

declare(strict_types=1);

namespace App\Http;

use App\AI\UsageBudgetService;
use App\Audit\AuditService;
use App\Audit\ReportService;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\QueueService;
use App\Core\TelegramClient;
use App\Policy\AdminAuthorizationService;
use App\Policy\AdminNotificationService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use App\Moderation\TextModerator;
use Throwable;

class UpdateRouter
{
    private TelegramClient $telegram;
    private AdminAuthorizationService $auth;
    private PunishmentService $punishment;

    public function __construct(
        ?TelegramClient $telegram = null,
        ?AdminAuthorizationService $auth = null,
        ?PunishmentService $punishment = null
    ) {
        $this->telegram = $telegram ?? new TelegramClient();
        $this->auth = $auth ?? new AdminAuthorizationService($this->telegram);
        $this->punishment = $punishment ?? new PunishmentService($this->telegram, $this->auth);
    }

    /**
     * Webhook orqali kelgan yangilanishni (Update) qabul qilish va yo'naltirish
     */
    public function handle(array $update): array
    {
        $updateId = (int)($update['update_id'] ?? 0);
        if ($updateId <= 0) {
            return ['status' => 'ignored', 'reason' => 'Yaroqsiz update_id'];
        }

        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $updType = array_key_first(array_diff_key($update, ['update_id' => 1])) ?? 'unknown';
        $registered = false;

        try {
            $checkStmt = $pdo->prepare("SELECT update_id FROM updates_log WHERE update_id = :uid");
            $checkStmt->execute(['uid' => $updateId]);
            if ($checkStmt->fetch()) {
                return ['status' => 'duplicate', 'reason' => 'Bu update avval qabul qilingan'];
            }

            try {
                $logStmt = $pdo->prepare("INSERT INTO updates_log (update_id, update_type, created_at) VALUES (:uid, :utype, :now)");
                $logStmt->execute(['uid' => $updateId, 'utype' => $updType, 'now' => $now]);
                $registered = true;
            } catch (Throwable $e) {
                $checkStmt->execute(['uid' => $updateId]);
                if ($checkStmt->fetch()) {
                    return ['status' => 'duplicate', 'reason' => 'Bu update boshqa jarayonda qabul qilingan'];
                }
                throw $e;
            }

            if (isset($update['callback_query'])) {
                return $this->handleCallbackQuery($update['callback_query']);
            }

            if (isset($update['my_chat_member'])) {
                return $this->handleMyChatMember($update['my_chat_member']);
            }

            if (isset($update['chat_member'])) {
                return $this->handleChatMember($update['chat_member']);
            }

            $message = $update['message'] ?? $update['edited_message'] ?? null;
            if ($message) {
                return $this->handleMessage($message, isset($update['edited_message']));
            }

            return ['status' => 'ok', 'reason' => 'Tegishli xabar turi topilmadi'];
        } catch (Throwable $e) {
            // Qayta ishlash tugamagan update Telegram tomonidan qayta yuborilishi uchun claimni bo'shatamiz.
            if ($registered) {
                try {
                    $pdo->prepare("DELETE FROM updates_log WHERE update_id = :uid")->execute(['uid' => $updateId]);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    private function handleMessage(array $message, bool $isEdited = false): array
    {
        $chat = $message['chat'] ?? [];
        $chatId = (int)($chat['id'] ?? 0);
        $chatType = (string)($chat['type'] ?? '');
        $messageId = (int)($message['message_id'] ?? 0);

        if ($chatId === 0 || $messageId === 0) {
            return ['status' => 'ignored'];
        }

        // Shaxsiy chat (Private) bo'lsa
        if ($chatType === 'private') {
            return $this->handlePrivateChat($message);
        }

        // Guruh sozlamalarini olish
        $settings = SettingsService::get($chatId);
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $this->persistUser($from);

        // --- 1. Xizmat xabarlari (Kirdi-Chiqdi) ---
        // A'zolar qo'shilishi (new_chat_members)
        if (isset($message['new_chat_members'])) {
            if ($settings['clean_service_messages']) {
                $this->telegram->deleteMessage($chatId, $messageId);
            }

            $botId = $this->telegram->getBotId();
            $botUsername = strtolower((string)Config::get('TELEGRAM_BOT_USERNAME', ''));
            $isBotSelfAdded = false;

            // Yangi a'zolarni profil tekshiruviga qo'yish (AI xizmat xabari uchun chaqirilmaydi!)
            foreach ($message['new_chat_members'] as $newMember) {
                $this->persistUser($newMember);
                $newMemberId = (int)($newMember['id'] ?? 0);
                if ($newMemberId > 0) {
                    $this->persistChatMember($chatId, $newMemberId, 'member');
                }
                $isSelf = ($botId > 0 && $newMemberId === $botId)
                    || ($botUsername !== '' && strtolower((string)($newMember['username'] ?? '')) === $botUsername);
                if ($isSelf) {
                    $isBotSelfAdded = true;
                    continue;
                }
                $isBotMember = (bool)($newMember['is_bot'] ?? false);
                $shouldScan = $isBotMember
                    ? (bool)($settings['bot_filter'] ?? true)
                    : (bool)($settings['profile_scan'] ?? true);
                if ($shouldScan && $newMemberId > 0) {
                    QueueService::push('App\Jobs\ScanProfileJob', [
                        'chat_id' => $chatId,
                        'user' => $newMember,
                    ], QueueService::PRIORITY_NORMAL);
                }
            }

            return ['status' => 'cleaned_service_message', 'type' => 'new_chat_members'];
        }

        // A'zo chiqishi (left_chat_member)
        if (isset($message['left_chat_member'])) {
            if ($settings['clean_service_messages']) {
                $this->telegram->deleteMessage($chatId, $messageId);
            }
            $leftUser = $message['left_chat_member'];
            $this->persistUser($leftUser);
            $leftUserId = (int)($leftUser['id'] ?? 0);
            if ($leftUserId > 0) {
                $this->persistChatMember($chatId, $leftUserId, 'left');
            }
            return ['status' => 'cleaned_service_message', 'type' => 'left_chat_member'];
        }

        // --- 2. Admin buyruqlari ---
        $rawText = (string)($message['text'] ?? $message['caption'] ?? '');

        if (!empty($message['document']) && $this->auth->isAdmin($chatId, (int)($message['from']['id'] ?? 0), $message['sender_chat'] ?? null)) {
            $uploadResult = $this->handleAuditUpload($message, $chatId);
            if ($uploadResult !== null) {
                return $uploadResult;
            }
        }

        if (str_starts_with($rawText, '/')) {
            $cmdResult = $this->handleCommand($message, $rawText, $chatId);
            if ($cmdResult !== null) {
                return $cmdResult;
            }
        }

        // --- 3. Oddiy yoki media xabar moderatsiyasi ---
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $senderChat = $message['sender_chat'] ?? null;

        // Anonim admin yoki kanal bo'lsa
        $isAdmin = $this->auth->isAdmin($chatId, $userId, $senderChat);
        $isWhitelisted = $this->auth->isWhitelisted($chatId, $userId);
        if ($isAdmin || $isWhitelisted) {
            Logger::info("Xabar moderatsiyadan ozod qilindi", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'reason' => $isAdmin ? 'admin' : 'whitelist',
                'has_media' => !empty($message['photo']) || !empty($message['video'])
                    || !empty($message['animation']) || !empty($message['document'])
                    || !empty($message['sticker']),
            ], 'moderation');
            return ['status' => 'skipped', 'reason' => $isAdmin ? 'Admin xabari' : 'Oq ro\'yxat'];
        }

        if ($userId > 0) {
            $senderIsBot = (bool)($from['is_bot'] ?? false);
            $scanSender = $senderIsBot
                ? (bool)($settings['bot_filter'] ?? true)
                : (bool)($settings['profile_scan'] ?? true);
            if ($scanSender) {
                QueueService::push('App\Jobs\ScanProfileJob', [
                    'chat_id' => $chatId,
                    'user' => $from,
                ], QueueService::PRIORITY_LOW);
            }
        }

        if (Config::get('APP_ENV') === 'test' || Config::getBool('MODERATE_ASYNC', false)) {
            QueueService::push('App\Jobs\ModerateMessageJob', [
                'message' => $message,
                'is_edited' => $isEdited,
                'chat_id' => $chatId,
            ], QueueService::PRIORITY_HIGH);
            return ['status' => 'queued_for_moderation'];
        }

        // Ishlab chiqarishda (Production): webhook javobini bloklamaslik uchun faqat
        // deterministik/mahalliy tekshiruv sinxron ishlaydi. AI matn va media tahlili
        // (kerak bo'lsa) job tomonidan fon navbatiga qo'yiladi — worker/cron bajaradi.
        try {
            (new \App\Jobs\ModerateMessageJob())->handle([
                'message' => $message,
                'is_edited' => $isEdited,
                'chat_id' => $chatId,
                'phase' => 'local',
            ]);
            return ['status' => 'moderated_realtime'];
        } catch (Throwable $e) {
            Logger::error("Realtime moderatsiyada xato: " . $e->getMessage(), ['chat_id' => $chatId], 'moderation');
            QueueService::push('App\Jobs\ModerateMessageJob', [
                'message' => $message,
                'is_edited' => $isEdited,
                'chat_id' => $chatId,
                'phase' => 'full',
            ], QueueService::PRIORITY_HIGH);
            return ['status' => 'queued_for_moderation'];
        }
    }

    private function handleCommand(array $message, string $text, int $chatId): ?array
    {
        $parts = explode(' ', trim($text));
        $cmd = strtolower(explode('@', $parts[0])[0]);
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $senderChat = $message['sender_chat'] ?? null;

        $isAdmin = $this->auth->isAdmin($chatId, $userId, $senderChat);

        // /start va /help guruhni keraksiz bot xabarlari bilan to'ldirmaydi.
        if (in_array($cmd, ['/start', '/help'], true)) {
            if (!$isAdmin) {
                return ['status' => 'command_ignored', 'cmd' => $cmd];
            }
            if ($isAdmin) {
                $help = "🤖 <b>Block-BOT Moderatsiya Boti</b>\n\n"
                    . "<b>Admin buyruqlari:</b>\n"
                    . "/settings - Guruh sozlamalarini boshqarish\n"
                    . "/status - Bot holati va faol modullar\n"
                    . "/stats - Guruh moderatsiya statistikasi\n"
                    . "/warn - Foydalanuvchiga ogohlantirish berish (reply orqali)\n"
                    . "/warnings - Ogohlantirishlarni ko'rish\n"
                    . "/resetwarns - Ogohlantirishlarni bekor qilish\n"
                    . "/mute [soat] - Vaqtincha cheklash (reply orqali)\n"
                    . "/unmute - Cheklovni yechish\n"
                    . "/ban - Guruhdan chiqarish\n"
                    . "/unban - Bandan chiqarish\n"
                    . "/blockword so'z - Maxsus so'z/iborani taqiqlash (jargon/lahja)\n"
                    . "/allowword so'z - Begunoh so'zni istisno qilish\n"
                    . "/unblockword so'z - Qoidani o'chirish\n"
                    . "/wordlist - Guruhning maxsus so'z qoidalari ro'yxati\n"
                    . "/scan_members - A'zolarni 18+ / bot akkauntlarga tekshirish\n"
                    . "/audit [mtproto|json] - Tarixiy auditni boshlash\n"
                    . "/audit_status - Audit jarayoni holati\n"
                    . "/audit_pause - Auditni to'xtatib turish\n"
                    . "/audit_resume - Auditni davom ettirish\n"
                    . "/audit_cancel - Auditni bekor qilish\n"
                    . "/audit_report - Audit hisobotini olish\n"
                    . "/ai_usage - AI xarajatlari va budjet sarfi";
            }
            $this->telegram->sendMessage($chatId, $help);
            return ['status' => 'command_executed', 'cmd' => $cmd];
        }

        // Qolgan buyruqlar faqat adminlar uchun
        if (!$isAdmin) {
            return null; // Oddiy a'zolarga guruhda ortiqcha xabar chiqarmaymiz
        }

        switch ($cmd) {

            case '/settings':
                $messageId = (int)($message['message_id'] ?? 0);
                if ($chatId < 0) {
                    if ($messageId > 0) {
                        $this->telegram->deleteMessage($chatId, $messageId);
                    }
                    $pmRes = $this->sendSettingsMenu($chatId, null, $userId);
                    if (!($pmRes['ok'] ?? false)) {
                        $botUser = Config::get('TELEGRAM_BOT_USERNAME', 'kj_blocker_bot');
                        $btn = [
                            'inline_keyboard' => [
                                [
                                    ['text' => "⚙️ Sozlamalarni shaxsiyda ochish", 'url' => "https://t.me/{$botUser}?start=settings_{$chatId}"]
                                ]
                            ]
                        ];
                        $this->telegram->sendMessage($chatId, "⚙️ Guruh sozlamalarini boshqarish uchun pastdagi tugmani bosing:", ['reply_markup' => $btn]);
                    }
                } else {
                    $this->sendSettingsMenu($chatId);
                }
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/status':
                $statusMsg = "⚙️ <b>Bot Tizim Holati:</b>\n"
                    . "• Webhook: Faol ✅\n"
                    . "• Baza ulanishi: Barqaror ✅\n"
                    . "• AI Rejimi: <b>" . SettingsService::get($chatId)['ai_mode'] . "</b>\n"
                    . "• PHP versiyasi: " . PHP_VERSION . "\n"
                    . "• Vaqt zonasi: " . Config::get('APP_TIMEZONE', 'Asia/Tashkent');
                $this->telegram->sendMessage($chatId, $statusMsg);
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/stats':
                $pdo = Database::getConnection();
                $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
                $queries = [
                    'findings' => "SELECT COUNT(*) FROM moderation_findings WHERE chat_id = :cid AND created_at >= :since",
                    'actions' => "SELECT COUNT(*) FROM telegram_actions WHERE chat_id = :cid AND created_at >= :since AND status IN ('executed', 'partial')",
                    'warnings' => "SELECT COUNT(*) FROM user_warnings WHERE chat_id = :cid AND is_active = 1 AND expires_at > :now",
                ];
                $stats = [];
                foreach ($queries as $key => $sql) {
                    $stmt = $pdo->prepare($sql);
                    $params = ['cid' => $chatId];
                    $params[$key === 'warnings' ? 'now' : 'since'] = $key === 'warnings' ? gmdate('Y-m-d H:i:s') : $since;
                    $stmt->execute($params);
                    $stats[$key] = (int)$stmt->fetchColumn();
                }
                $this->telegram->sendMessage($chatId, "📈 <b>Oxirgi 30 kun statistikasi</b>\n• Topilmalar: {$stats['findings']}\n• Bajarilgan choralar: {$stats['actions']}\n• Faol ogohlantirishlar: {$stats['warnings']}");
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/ai_usage':
                $stats = UsageBudgetService::getSummaryStats($chatId);
                $msg = "📊 <b>AI Xarajatlari va Budjet:</b>\n\n"
                    . "• Bugungi so'rovlar: {$stats['today_requests']}\n"
                    . "• Bugungi tokenlar: {$stats['today_tokens']}\n"
                    . "• Bugungi sarf: \${$stats['today_cost_usd']} / \${$stats['daily_limit_usd']}\n"
                    . "• Oylik sarf: \${$stats['month_cost_usd']} / \${$stats['monthly_limit_usd']}\n"
                    . "• Budjet holati: " . ($stats['is_available'] ? "Faol ✅" : "Chegaraga yetgan ⚠️");
                $this->telegram->sendMessage($chatId, $msg);
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/scan_members':
                $aiMode = (string)(SettingsService::get($chatId)['ai_mode'] ?? 'economical');
                $sessionId = AuditService::createSession($chatId, $userId, 'member_sweep', 'all', $aiMode);
                $this->telegram->sendMessage($chatId, "👥 A'zolar tekshiruvi #{$sessionId} navbatga qo'yildi. 18+ profil va ruxsatsiz bot akkauntlar aniqlanadi; hisobot administratorga yuboriladi.");
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/audit':
                $source = strtolower((string)($parts[1] ?? 'auto'));
                if ($source === 'auto') {
                    $reader = new \App\Audit\MtprotoHistoryReader();
                    $source = ($reader->isConfigured() && class_exists('\danog\MadelineProto\API')) ? 'mtproto' : 'json';
                }
                if ($source === 'mtproto') {
                    $reader = new \App\Audit\MtprotoHistoryReader();
                    if (!$reader->isConfigured() || !class_exists('\danog\MadelineProto\API')) {
                        $this->telegram->sendMessage($chatId, "❌ MTProto sozlanmagan. MTPROTO_API_ID/API_HASH va MadelineProto kutubxonasini tekshiring.");
                        return ['status' => 'error', 'reason' => 'mtproto_not_configured'];
                    }
                    $sessionId = AuditService::createSession($chatId, $userId, 'mtproto', 'all', 'economical');
                    $this->telegram->sendMessage($chatId, "🚀 MTProto audit sessiyasi #{$sessionId} navbatga qo'yildi.");
                } else {
                    $sessionId = AuditService::createSession($chatId, $userId, 'json_export', 'all', 'economical');
                    $this->telegram->sendMessage($chatId, "📦 Audit sessiyasi #{$sessionId} yaratildi. Telegram Bot API eski tarixni o'qiy olmaydi. Telegram Desktop eksportidagi <b>result.json</b> yoki ZIP faylni shu guruhga hujjat sifatida yuboring (maksimum 20 MB), yoki serverda MTProto'ni sozlab <code>/audit mtproto</code> buyrug'idan foydalaning.");
                }
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/audit_status':
                $session = AuditService::latestForChat($chatId);
                if (!$session) {
                    $this->telegram->sendMessage($chatId, "Audit sessiyasi topilmadi.");
                } else {
                    $this->telegram->sendMessage($chatId, "📊 Audit #{$session['id']}\n• Manba: {$session['source_type']}\n• Holat: <b>{$session['status']}</b>\n• Tekshirildi: {$session['total_scanned']}\n• Topilmalar: {$session['total_flagged']}" . (!empty($session['error_message']) ? "\n• Xato: " . htmlspecialchars((string)$session['error_message'], ENT_QUOTES, 'UTF-8') : ''));
                }
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/audit_pause':
            case '/audit_resume':
            case '/audit_cancel':
                $session = AuditService::latestForChat($chatId, ['waiting_upload', 'running', 'paused']);
                if (!$session) {
                    $this->telegram->sendMessage($chatId, "Faol audit sessiyasi topilmadi.");
                    return ['status' => 'error', 'reason' => 'audit_not_found'];
                }
                $ok = match ($cmd) {
                    '/audit_pause' => AuditService::pause((int)$session['id']),
                    '/audit_resume' => AuditService::resume((int)$session['id']),
                    '/audit_cancel' => AuditService::cancel((int)$session['id']),
                };
                $labels = ['/audit_pause' => "to'xtatildi", '/audit_resume' => 'davom ettirildi', '/audit_cancel' => 'bekor qilindi'];
                $this->telegram->sendMessage($chatId, $ok ? "✅ Audit #{$session['id']} {$labels[$cmd]}." : "❌ Audit holatini o'zgartirib bo'lmadi.");
                return ['status' => $ok ? 'command_executed' : 'error', 'cmd' => $cmd];

            case '/audit_report':
                // Oxirgi audit sessiyasini topish
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT id FROM audit_sessions WHERE chat_id = :cid ORDER BY id DESC LIMIT 1");
                $stmt->execute(['cid' => $chatId]);
                $lastSessionId = (int)$stmt->fetchColumn();

                if ($lastSessionId > 0) {
                    $summary = ReportService::generateTelegramSummary($lastSessionId);
                    $this->telegram->sendMessage($chatId, $summary);
                    $this->sendAuditReportFiles($chatId, $lastSessionId);
                } else {
                    $this->telegram->sendMessage($chatId, "Guruhda hali audit o'tkazilmagan.");
                }
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/warn':
            case '/mute':
            case '/ban':
            case '/unmute':
            case '/unban':
            case '/resetwarns':
                return $this->handleModerationCommand($cmd, $message, $parts, $chatId);

            case '/warnings':
                $targetUserId = $this->resolveTargetUserId($message, $parts);
                if ($targetUserId <= 0) {
                    $this->telegram->sendMessage($chatId, "Ogohlantirishlarni ko'rish uchun foydalanuvchi xabariga reply qiling yoki ID kiriting.");
                    return ['status' => 'error', 'reason' => 'target_user_not_found'];
                }
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT reason, expires_at FROM user_warnings WHERE chat_id = :cid AND user_id = :uid AND is_active = 1 AND expires_at > :now ORDER BY id DESC LIMIT 10");
                $stmt->execute(['cid' => $chatId, 'uid' => $targetUserId, 'now' => gmdate('Y-m-d H:i:s')]);
                $rows = $stmt->fetchAll();
                if (!$rows) {
                    $msg = "✅ Foydalanuvchida faol ogohlantirish yo'q.";
                } else {
                    $msg = "⚠️ <b>Faol ogohlantirishlar: " . count($rows) . "</b>\n";
                    foreach ($rows as $index => $row) {
                        $msg .= ($index + 1) . '. ' . htmlspecialchars((string)$row['reason'], ENT_QUOTES, 'UTF-8') . " (gacha: {$row['expires_at']})\n";
                    }
                }
                $this->telegram->sendMessage($chatId, $msg);
                return ['status' => 'command_executed', 'cmd' => $cmd];

            case '/blockword':
            case '/allowword':
            case '/unblockword':
                return $this->handleWordRuleCommand($cmd, $text, $parts, $chatId);

            case '/wordlist':
                return $this->handleWordListCommand($chatId);
        }

        return null;
    }

    /**
     * Guruh uchun maxsus so'z/ibora qoidasini qo'shish yoki o'chirish
     * (/blockword, /allowword, /unblockword). Jargon/lahjadagi so'kinishlarni
     * o'rnatilgan ro'yxatga qo'shimcha ravishda mahalliy tarzda taqiqlash imkonini beradi.
     */
    private function handleWordRuleCommand(string $cmd, string $text, array $parts, int $chatId): array
    {
        $phrase = trim(mb_substr($text, mb_strlen($parts[0])));
        if ($phrase === '' || mb_strlen($phrase) > 100) {
            $this->telegram->sendMessage($chatId, "Foydalanish: <code>{$cmd} so'z_yoki_ibora</code> (1-100 belgi).\nMasalan: <code>{$cmd} qashqaldoq</code>");
            return ['status' => 'error', 'reason' => 'phrase_required'];
        }

        $pdo = Database::getConnection();
        if ($cmd === '/unblockword') {
            $stmt = $pdo->prepare("DELETE FROM word_rules WHERE chat_id = :cid AND word_pattern = :p");
            $stmt->execute(['cid' => $chatId, 'p' => $phrase]);
            $this->telegram->sendMessage($chatId, $stmt->rowCount() > 0
                ? "✅ \"" . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . "\" ro'yxatdan olib tashlandi."
                : "ℹ️ Bunday qoida topilmadi.");
            return ['status' => 'command_executed', 'cmd' => $cmd];
        }

        $ruleType = $cmd === '/allowword' ? 'whitelist' : 'blacklist';
        $existsStmt = $pdo->prepare("SELECT id FROM word_rules WHERE chat_id = :cid AND word_pattern = :p AND rule_type = :t");
        $existsStmt->execute(['cid' => $chatId, 'p' => $phrase, 't' => $ruleType]);
        if ($existsStmt->fetch()) {
            $this->telegram->sendMessage($chatId, "ℹ️ Bu ibora allaqachon ro'yxatda.");
            return ['status' => 'already_exists', 'cmd' => $cmd];
        }

        $pdo->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, :t, :p, 0, :now)")
            ->execute(['cid' => $chatId, 't' => $ruleType, 'p' => $phrase, 'now' => gmdate('Y-m-d H:i:s')]);

        $label = $ruleType === 'whitelist' ? "oq ro'yxatga (hech qachon bloklanmaydi)" : "qora ro'yxatga (darhol o'chiriladi)";
        $this->telegram->sendMessage($chatId, "✅ \"" . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . "\" {$label} qo'shildi.");
        return ['status' => 'command_executed', 'cmd' => $cmd];
    }

    private function handleWordListCommand(int $chatId): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT rule_type, word_pattern FROM word_rules
            WHERE chat_id = :cid ORDER BY rule_type, id DESC LIMIT 50
        ");
        $stmt->execute(['cid' => $chatId]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            $this->telegram->sendMessage($chatId, "Bu guruh uchun maxsus so'z qoidalari yo'q.\nQo'shish: <code>/blockword so'z</code> yoki <code>/allowword so'z</code>");
            return ['status' => 'command_executed', 'cmd' => '/wordlist'];
        }

        $blacklist = array_filter($rows, static fn ($r) => $r['rule_type'] === 'blacklist');
        $whitelist = array_filter($rows, static fn ($r) => $r['rule_type'] === 'whitelist');
        $msg = "📝 <b>Guruhning maxsus so'z qoidalari</b>\n\n";
        if ($blacklist) {
            $msg .= "🚫 <b>Taqiqlangan:</b>\n";
            foreach ($blacklist as $r) {
                $msg .= "• " . htmlspecialchars((string)$r['word_pattern'], ENT_QUOTES, 'UTF-8') . "\n";
            }
        }
        if ($whitelist) {
            $msg .= "\n✅ <b>Ruxsat etilgan (istisno):</b>\n";
            foreach ($whitelist as $r) {
                $msg .= "• " . htmlspecialchars((string)$r['word_pattern'], ENT_QUOTES, 'UTF-8') . "\n";
            }
        }
        $msg .= "\n<i>O'chirish: /unblockword so'z</i>";
        $this->telegram->sendMessage($chatId, $msg);
        return ['status' => 'command_executed', 'cmd' => '/wordlist'];
    }

    private function handleModerationCommand(string $cmd, array $message, array $parts, int $chatId): array
    {
        $targetUserId = $this->resolveTargetUserId($message, $parts);
        $adminUserId = (int)($message['from']['id'] ?? 0);

        if ($targetUserId <= 0) {
            $this->telegram->sendMessage($chatId, "Foydalanuvchini ko'rsatish uchun uning xabariga reply qiling yoki ID raqamini kiriting.");
            return ['status' => 'error', 'reason' => 'target_user_not_found'];
        }

        switch ($cmd) {
            case '/warn':
                $this->punishment->execute($chatId, $targetUserId, (int)($message['message_id'] ?? 0), [
                    'action' => 'warn_user',
                    'delete_message' => false,
                    'reason' => 'Admin tomonidan ogohlantirish berildi',
                    'strike_count' => 1,
                ]);
                break;
            case '/mute':
                $durationArg = isset($message['reply_to_message']['from']['id']) ? ($parts[1] ?? 1) : ($parts[2] ?? 1);
                $durationHours = is_numeric($durationArg) ? max(1, min(720, (int)$durationArg)) : 1;
                $this->punishment->execute($chatId, $targetUserId, (int)($message['message_id'] ?? 0), [
                    'action' => 'mute_user',
                    'delete_message' => false,
                    'mute_duration_sec' => $durationHours * 3600,
                    'reason' => "Admin tomonidan {$durationHours} soatga mute qilindi",
                ]);
                break;
            case '/unmute':
                $this->punishment->unmute($chatId, $targetUserId);
                if ($adminUserId > 0) {
                    $this->telegram->sendMessage($adminUserId, "✅ Foydalanuvchidan cheklov (mute) olib tashlandi.");
                }
                break;
            case '/ban':
                $this->punishment->execute($chatId, $targetUserId, (int)($message['message_id'] ?? 0), [
                    'action' => 'ban_user',
                    'delete_message' => false,
                    'reason' => 'Admin tomonidan guruhdan chetlatildi (ban)',
                ]);
                break;
            case '/unban':
                $this->punishment->unban($chatId, $targetUserId);
                if ($adminUserId > 0) {
                    $this->telegram->sendMessage($adminUserId, "✅ Foydalanuvchi bandan chiqarildi.");
                }
                break;
            case '/resetwarns':
                $this->punishment->resetWarnings($chatId, $targetUserId);
                if ($adminUserId > 0) {
                    $this->telegram->sendMessage($adminUserId, "✅ Foydalanuvchining barcha ogohlantirishlari bekor qilindi.");
                }
                break;
        }

        return ['status' => 'command_executed', 'cmd' => $cmd];
    }

    private function handleCallbackQuery(array $cb): array
    {
        $cbId = (string)($cb['id'] ?? '');
        $data = (string)($cb['data'] ?? '');
        $from = $cb['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        if ($action === 'appeal_request') {
            $precheck = $this->validateAppealRequester((int)($parts[1] ?? 0), $userId);
            if ($precheck !== null) {
                $message = $precheck === 'unauthorized'
                    ? "Bu qaror bo'yicha faqat cheklangan foydalanuvchi shikoyat qila oladi."
                    : "Moderatsiya qarori topilmadi.";
                $this->telegram->answerCallbackQuery($cbId, $message, true);
                return ['status' => $precheck];
            }
            $this->telegram->answerCallbackQuery($cbId, "Shikoyat qabul qilindi, kontent qayta tekshirilmoqda.");
            return $this->createAppeal((int)($parts[1] ?? 0), $userId);
        }

        if (in_array($action, ['appeal_accept', 'appeal_reject'], true)) {
            return $this->reviewAppeal((int)($parts[1] ?? 0), $userId, $action === 'appeal_accept', $cbId);
        }

        // Audit / a'zolar sweep topilmalarini tasdiqli tozalash (parts[1] = session_id).
        if (in_array($action, ['audit_clean_msgs', 'audit_clean_adult', 'audit_clean_bots', 'audit_clean_done', 'audit_ban'], true)) {
            return $this->handleAuditCleanup($action, (int)($parts[1] ?? 0), $userId, $cbId, (string)($parts[2] ?? ''));
        }

        // Shaxsiy chat bosh menyusi tugmalari (guruh ID yo'q).
        if (str_starts_with($action, 'pm_')) {
            $this->telegram->answerCallbackQuery($cbId);
            $msgId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
            return $this->handlePrivateMenu(substr($action, 3), $userId, $msgId);
        }

        $chatId = (int)($parts[1] ?? 0);

        // Adminlik huquqini tekshirish
        if ($chatId !== 0 && !$this->auth->isAdmin($chatId, $userId)) {
            $this->telegram->answerCallbackQuery($cbId, "Sizda ushbu amalni bajarish vakolati yo'q!", true);
            return ['status' => 'unauthorized'];
        }

        if (str_starts_with($action, 'admin_')) {
            $this->telegram->answerCallbackQuery($cbId, "Bajarilmoqda...");
            $targetChatId = (int)($cb['message']['chat']['id'] ?? $userId);
            return $this->handlePrivateAdminAction(substr($action, 6), $chatId, $userId, $targetChatId);
        }

        // Rollback amallari
        if (str_starts_with($action, 'rb_')) {
            $targetId = (int)($parts[2] ?? 0);
            if ($action === 'rb_unmute') {
                $this->punishment->unmute($chatId, $targetId);
                $this->telegram->answerCallbackQuery($cbId, "Mute cheklovi olib tashlandi");
            } elseif ($action === 'rb_unban') {
                $this->punishment->unban($chatId, $targetId);
                $this->telegram->answerCallbackQuery($cbId, "Foydalanuvchi bandan chiqarildi");
            } elseif ($action === 'rb_whitelist' && $targetId > 0) {
                $pdo = Database::getConnection();
                $now = gmdate('Y-m-d H:i:s');
                if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $stmt = $pdo->prepare("INSERT INTO chat_members (chat_id, user_id, role, is_whitelisted, updated_at) VALUES (:cid, :uid, 'member', 1, :now) ON CONFLICT(chat_id, user_id) DO UPDATE SET is_whitelisted = 1, updated_at = excluded.updated_at");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO chat_members (chat_id, user_id, role, is_whitelisted, updated_at) VALUES (:cid, :uid, 'member', 1, :now) ON DUPLICATE KEY UPDATE is_whitelisted = 1, updated_at = VALUES(updated_at)");
                }
                $stmt->execute(['cid' => $chatId, 'uid' => $targetId, 'now' => $now]);
                $this->telegram->answerCallbackQuery($cbId, "Foydalanuvchi oq ro'yxatga qo'shildi");
            } elseif ($action === 'rb_ban' && $targetId > 0) {
                $this->punishment->execute($chatId, $targetId, null, [
                    'action' => 'ban_user',
                    'delete_message' => false,
                    'notify_admin' => false,
                    'reason' => 'Admin tasdig\'i bilan guruhdan chetlatildi',
                    'strike_count' => 99,
                    'quiet' => true,
                ]);
                $this->telegram->answerCallbackQuery($cbId, "Foydalanuvchi guruhdan chetlatildi");
            } elseif ($action === 'rb_delmsg' && $targetId > 0) {
                // Bu yerda targetId — xabar ID (rb_delmsg:{chatId}:{messageId}).
                $deleted = $this->telegram->deleteMessage($chatId, $targetId);
                $this->telegram->answerCallbackQuery($cbId, $deleted ? "Xabar o'chirildi" : "Xabarni o'chirib bo'lmadi (huquq yetarli emas yoki allaqachon yo'q)", !$deleted);
            } elseif ($action === 'rb_false_pos' && $targetId > 0) {
                Database::getConnection()->prepare("UPDATE moderation_findings SET status = 'false_positive' WHERE id = :id AND chat_id = :cid")
                    ->execute(['id' => $targetId, 'cid' => $chatId]);
                $this->telegram->answerCallbackQuery($cbId, "Topilma noto'g'ri deb belgilandi");
            }
            return ['status' => 'rollback_executed'];
        }

        // Sozlamalarni o'zgartirish
        if (str_starts_with($action, 'set_')) {
            $settingKey = substr($action, 4);
            $currentSettings = SettingsService::get($chatId);

            if ($settingKey === 'ai_mode') {
                $newMode = ($currentSettings['ai_mode'] ?? 'comprehensive') === 'comprehensive' ? 'economical' : 'comprehensive';
                SettingsService::update($chatId, ['ai_mode' => $newMode]);
                $this->telegram->answerCallbackQuery($cbId, "AI rejimi: {$newMode}");
                $messageChatId = (int)($cb['message']['chat']['id'] ?? $chatId);
                $this->sendSettingsMenu($chatId, $cb['message']['message_id'] ?? null, $messageChatId);
                return ['status' => 'setting_updated'];
            }

            if (isset($currentSettings[$settingKey])) {
                $newVal = $currentSettings[$settingKey] ? 0 : 1;
                SettingsService::update($chatId, [$settingKey => $newVal]);
                $this->telegram->answerCallbackQuery($cbId, "Sozlama yangilandi");
                $messageChatId = (int)($cb['message']['chat']['id'] ?? $chatId);
                $this->sendSettingsMenu($chatId, $cb['message']['message_id'] ?? null, $messageChatId);
            }
            return ['status' => 'setting_updated'];
        }

        if ($action === 'open_settings') {
            $this->telegram->answerCallbackQuery($cbId);
            $messageChatId = (int)($cb['message']['chat']['id'] ?? $chatId);
            $this->sendSettingsMenu($chatId, $cb['message']['message_id'] ?? null, $messageChatId);
            return ['status' => 'settings_opened'];
        }

        $this->telegram->answerCallbackQuery($cbId, "Qabul qilindi");
        return ['status' => 'ok'];
    }

    /**
     * Audit / a'zolar sweep topilmalarini admin tasdig'i bilan tozalash tugmalari.
     */
    private function handleAuditCleanup(string $action, int $sessionId, int $userId, string $cbId, string $kind = ''): array
    {
        $session = AuditService::get($sessionId);
        if (!$session) {
            $this->telegram->answerCallbackQuery($cbId, "Audit sessiyasi topilmadi.", true);
            return ['status' => 'not_found'];
        }
        $chatId = (int)$session['chat_id'];
        if (!$this->auth->isAdmin($chatId, $userId)) {
            $this->telegram->answerCallbackQuery($cbId, "Bu guruh bo'yicha vakolatingiz yo'q.", true);
            return ['status' => 'unauthorized'];
        }

        if ($action === 'audit_clean_done') {
            $this->telegram->answerCallbackQuery($cbId, "Yakunlandi. Hech narsa o'zgartirilmadi.");
            return ['status' => 'audit_cleanup_dismissed'];
        }

        $mode = match ($action) {
            'audit_clean_msgs'  => 'delete_messages',
            'audit_clean_adult' => 'restrict_adult',
            'audit_clean_bots'  => 'restrict_bots',
            'audit_ban'         => $kind === 'bot' ? 'ban_bots' : 'ban_adult',
            default             => '',
        };
        if ($mode === '') {
            return ['status' => 'ignored'];
        }

        QueueService::push('App\Jobs\AuditCleanupJob', [
            'session_id' => $sessionId,
            'chat_id' => $chatId,
            'mode' => $mode,
            'notify_chat_id' => $userId,
        ], QueueService::PRIORITY_LOW);

        $this->telegram->answerCallbackQuery($cbId, "✅ Navbatga qo'yildi. Natija shu chatga yuboriladi.");
        return ['status' => 'audit_cleanup_queued', 'mode' => $mode, 'session_id' => $sessionId];
    }

    private function createAppeal(int $actionId, int $requesterId): array
    {
        if ($actionId <= 0 || $requesterId <= 0) {
            return ['status' => 'not_found'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM telegram_actions WHERE id = :id AND action_type IN ('mute_user', 'ban_user')");
        $stmt->execute(['id' => $actionId]);
        $action = $stmt->fetch();
        if (!$action) {
            return ['status' => 'not_found'];
        }

        $chatId = (int)$action['chat_id'];
        $targetUserId = (int)$action['user_id'];
        if ($requesterId !== $targetUserId && !$this->auth->isAdmin($chatId, $requesterId)) {
            return ['status' => 'unauthorized'];
        }

        $existing = $pdo->prepare("SELECT id, status FROM moderation_appeals WHERE action_id = :aid");
        $existing->execute(['aid' => $actionId]);
        if ($existing->fetch()) {
            return ['status' => 'already_pending'];
        }

        $messageId = (int)($action['message_id'] ?? 0);
        if ($messageId > 0) {
            $findingStmt = $pdo->prepare("SELECT * FROM moderation_findings WHERE chat_id = :cid AND message_id = :mid ORDER BY id DESC LIMIT 1");
            $findingStmt->execute(['cid' => $chatId, 'mid' => $messageId]);
        } else {
            $findingStmt = $pdo->prepare("SELECT * FROM moderation_findings WHERE chat_id = :cid AND user_id = :uid ORDER BY id DESC LIMIT 1");
            $findingStmt->execute(['cid' => $chatId, 'uid' => $targetUserId]);
        }
        $finding = $findingStmt->fetch() ?: [];

        $rawText = '';
        if ($messageId > 0) {
            $messageStmt = $pdo->prepare("SELECT raw_text FROM messages WHERE chat_id = :cid AND message_id = :mid");
            $messageStmt->execute(['cid' => $chatId, 'mid' => $messageId]);
            $rawText = (string)($messageStmt->fetchColumn() ?: '');
        }

        if (trim($rawText) !== '') {
            $settings = SettingsService::get($chatId);
            $recheck = (new TextModerator())->inspect(
                $rawText,
                [],
                'comprehensive',
                $chatId,
                "appeal_{$actionId}",
                ['profanity_filter' => true, 'porn_filter' => true, 'link_filter' => true],
                !empty($settings['ai_model_text']) ? (string)$settings['ai_model_text'] : null
            );
        } else {
            $recheck = [
                'status' => 'review',
                'reason' => "Media yoki profil dalilini administrator qo'lda qayta ko'rishi kerak",
            ];
        }

        $now = gmdate('Y-m-d H:i:s');
        $insert = $pdo->prepare("
            INSERT INTO moderation_appeals
                (action_id, chat_id, user_id, finding_id, status, recheck_status, recheck_reason, created_at, updated_at)
            VALUES
                (:aid, :cid, :uid, :fid, 'pending', :rst, :reason, :now, :now)
        ");
        try {
            $insert->execute([
                'aid' => $actionId,
                'cid' => $chatId,
                'uid' => $targetUserId,
                'fid' => !empty($finding['id']) ? (int)$finding['id'] : null,
                'rst' => (string)($recheck['status'] ?? 'review'),
                'reason' => mb_substr((string)($recheck['reason'] ?? "Qayta tekshiruv xulosasi yo'q"), 0, 1000),
                'now' => $now,
            ]);
        } catch (Throwable) {
            return ['status' => 'already_pending'];
        }
        $appealId = (int)$pdo->lastInsertId();

        $oldReason = htmlspecialchars((string)($finding['reason'] ?? $action['error_message'] ?? 'Moderatsiya qarori'), ENT_QUOTES, 'UTF-8');
        $recheckReason = htmlspecialchars((string)($recheck['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        $recheckStatus = strtoupper((string)($recheck['status'] ?? 'review'));
        $recommendation = ($recheck['status'] ?? '') === 'safe'
            ? "Qabul qilish tavsiya etiladi"
            : ((($recheck['status'] ?? '') === 'unsafe') ? "Rad etish tavsiya etiladi" : "Qo'lda tekshirish zarur");

        $adminText = "📝 <b>Yangi moderatsiya shikoyati #{$appealId}</b>\n"
            . "• Guruh ID: <code>{$chatId}</code>\n"
            . "• Foydalanuvchi: <a href=\"tg://user?id={$targetUserId}\">{$targetUserId}</a>\n"
            . "• Asl qaror: <b>" . strtoupper((string)$action['action_type']) . "</b>\n"
            . "• Asl sabab: {$oldReason}\n"
            . "• AI qayta tekshiruvi: <b>{$recheckStatus}</b>\n"
            . "• Xulosa: {$recheckReason}\n"
            . "• Tavsiya: <b>{$recommendation}</b>\n\n"
            . "Yakuniy qarorni administrator tasdiqlashi shart.";

        (new AdminNotificationService($this->telegram))->send($chatId, $adminText, [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '✅ Shikoyatni qabul qilish', 'callback_data' => "appeal_accept:{$appealId}"],
                    ['text' => '❌ Rad etish', 'callback_data' => "appeal_reject:{$appealId}"],
                ]]
            ]
        ]);

        return ['status' => 'appeal_created', 'appeal_id' => $appealId, 'recheck_status' => $recheck['status'] ?? 'review'];
    }

    private function validateAppealRequester(int $actionId, int $requesterId): ?string
    {
        if ($actionId <= 0 || $requesterId <= 0) {
            return 'not_found';
        }
        $stmt = Database::getConnection()->prepare("SELECT chat_id, user_id FROM telegram_actions WHERE id = :id AND action_type IN ('mute_user', 'ban_user')");
        $stmt->execute(['id' => $actionId]);
        $action = $stmt->fetch();
        if (!$action) {
            return 'not_found';
        }
        $chatId = (int)$action['chat_id'];
        return $requesterId === (int)$action['user_id'] || $this->auth->isAdmin($chatId, $requesterId)
            ? null
            : 'unauthorized';
    }

    private function reviewAppeal(int $appealId, int $adminId, bool $accept, string $callbackId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT a.*, t.action_type
            FROM moderation_appeals a
            JOIN telegram_actions t ON t.id = a.action_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $appealId]);
        $appeal = $stmt->fetch();
        if (!$appeal) {
            $this->telegram->answerCallbackQuery($callbackId, "Shikoyat topilmadi.", true);
            return ['status' => 'not_found'];
        }

        $chatId = (int)$appeal['chat_id'];
        if (!$this->auth->isAdmin($chatId, $adminId)) {
            $this->telegram->answerCallbackQuery($callbackId, "Sizda yakuniy qaror berish vakolati yo'q.", true);
            return ['status' => 'unauthorized'];
        }
        if ($appeal['status'] !== 'pending') {
            $this->telegram->answerCallbackQuery($callbackId, "Bu shikoyat avval ko'rib chiqilgan.", true);
            return ['status' => 'already_reviewed'];
        }

        $targetUserId = (int)$appeal['user_id'];
        if ($accept) {
            $restored = $appeal['action_type'] === 'ban_user'
                ? $this->punishment->unban($chatId, $targetUserId)
                : $this->punishment->unmute($chatId, $targetUserId);
            if (!$restored) {
                $this->telegram->answerCallbackQuery($callbackId, "Telegram cheklovni olib tashlay olmadi. Bot huquqlarini tekshiring.", true);
                return ['status' => 'restore_failed'];
            }

            if (!empty($appeal['finding_id'])) {
                $pdo->prepare("UPDATE moderation_findings SET status = 'false_positive' WHERE id = :id AND chat_id = :cid")
                    ->execute(['id' => (int)$appeal['finding_id'], 'cid' => $chatId]);
                $pdo->prepare("UPDATE user_warnings SET is_active = 0 WHERE finding_id = :id")
                    ->execute(['id' => (int)$appeal['finding_id']]);
            }
        }

        $newStatus = $accept ? 'approved' : 'rejected';
        $pdo->prepare("UPDATE moderation_appeals SET status = :status, reviewed_by = :admin, updated_at = :now WHERE id = :id AND status = 'pending'")
            ->execute(['status' => $newStatus, 'admin' => $adminId, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $appealId]);

        $resultText = $accept ? "Shikoyat qabul qilindi, cheklov olib tashlandi." : "Shikoyat rad etildi, cheklov saqlanadi.";
        $this->telegram->answerCallbackQuery($callbackId, $resultText, true);
        $this->telegram->sendMessage($targetUserId, ($accept ? '✅ ' : '❌ ') . "Shikoyat #{$appealId}: {$resultText}");
        return ['status' => $newStatus, 'appeal_id' => $appealId];
    }

    private function handlePrivateAdminAction(string $action, int $chatId, int $adminId, int $targetChatId): array
    {
        if ($chatId === 0 || !$this->auth->isAdmin($chatId, $adminId)) {
            $this->telegram->sendMessage($targetChatId, "❌ Siz bu guruh administratori emassiz.");
            return ['status' => 'unauthorized'];
        }

        if ($action === 'ai_usage') {
            $stats = UsageBudgetService::getSummaryStats($chatId);
            $this->telegram->sendMessage($targetChatId, "💰 <b>AI sarfi</b>\n"
                . "• Bugungi so'rovlar: {$stats['today_requests']}\n"
                . "• Bugungi tokenlar: {$stats['today_tokens']}\n"
                . "• Bugungi sarf: \${$stats['today_cost_usd']} / \${$stats['daily_limit_usd']}\n"
                . "• Oylik sarf: \${$stats['month_cost_usd']} / \${$stats['monthly_limit_usd']}\n"
                . "• Holat: " . ($stats['is_available'] ? 'Faol ✅' : 'Limit tugagan ⚠️'));
            return ['status' => 'private_admin_action', 'action' => $action];
        }

        if ($action === 'stats') {
            $pdo = Database::getConnection();
            $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            $findingStmt = $pdo->prepare("SELECT COUNT(*) FROM moderation_findings WHERE chat_id = :cid AND created_at >= :since");
            $findingStmt->execute(['cid' => $chatId, 'since' => $since]);
            $actionStmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = :cid AND created_at >= :since AND status IN ('executed', 'partial')");
            $actionStmt->execute(['cid' => $chatId, 'since' => $since]);
            $warningStmt = $pdo->prepare("SELECT COUNT(*) FROM user_warnings WHERE chat_id = :cid AND is_active = 1 AND expires_at > :now");
            $warningStmt->execute(['cid' => $chatId, 'now' => gmdate('Y-m-d H:i:s')]);
            $this->telegram->sendMessage($targetChatId, "📈 <b>Oxirgi 30 kun statistikasi</b>\n"
                . "• Topilmalar: " . (int)$findingStmt->fetchColumn() . "\n"
                . "• Bajarilgan choralar: " . (int)$actionStmt->fetchColumn() . "\n"
                . "• Faol ogohlantirishlar: " . (int)$warningStmt->fetchColumn());
            return ['status' => 'private_admin_action', 'action' => $action];
        }

        if ($action === 'scan_members') {
            $running = AuditService::latestForChat($chatId, ['running']);
            if ($running && ($running['source_type'] ?? '') === 'member_sweep') {
                $this->telegram->sendMessage($targetChatId, "ℹ️ A'zolar tekshiruvi #{$running['id']} allaqachon davom etmoqda.");
                return ['status' => 'member_sweep_already_running', 'session_id' => (int)$running['id']];
            }
            $aiMode = (string)(SettingsService::get($chatId)['ai_mode'] ?? 'economical');
            $sessionId = AuditService::createSession($chatId, $adminId, 'member_sweep', 'all', $aiMode);
            $this->telegram->sendMessage($targetChatId, "👥 <b>A'zolar tekshiruvi #{$sessionId} boshlandi.</b>\n"
                . "18+ profil va ruxsatsiz bot akkauntlar aniqlanadi. Hisobot shu chatga yuboriladi.\n\n"
                . "<i>Eslatma: bot faqat o'zi ko'rgan yoki eksportda bo'lgan a'zolarni tekshira oladi. "
                . "To'liq ro'yxat uchun serverда MTProto sozlanishi kerak.</i>");
            return ['status' => 'member_sweep_started', 'session_id' => $sessionId];
        }

        if (in_array($action, ['audit_json', 'audit_mtproto'], true)) {
            $active = AuditService::latestForChat($chatId, ['waiting_upload', 'running', 'paused']);
            if ($active) {
                $this->telegram->sendMessage($targetChatId, "ℹ️ Guruhda audit #{$active['id']} allaqachon faol. Holat: <b>{$active['status']}</b>.");
                return ['status' => 'audit_already_active', 'session_id' => (int)$active['id']];
            }

            if ($action === 'audit_mtproto') {
                $reader = new \App\Audit\MtprotoHistoryReader();
                if (!$reader->isConfigured() || !class_exists('\danog\MadelineProto\API')) {
                    $this->telegram->sendMessage($targetChatId, "❌ MTProto sozlanmagan. Shared hostingda <b>Eski xabarlar auditi</b> tugmasidan foydalanib Telegram Desktop JSON/ZIP eksportini yuboring.");
                    return ['status' => 'error', 'reason' => 'mtproto_not_configured'];
                }
                $sessionId = AuditService::createSession($chatId, $adminId, 'mtproto', 'all', 'economical');
                $this->telegram->sendMessage($targetChatId, "🚀 MTProto audit #{$sessionId} boshlandi.");
                return ['status' => 'audit_started', 'session_id' => $sessionId];
            }

            $sessionId = AuditService::createSession($chatId, $adminId, 'json_export', 'all', 'economical');
            $groupTitle = htmlspecialchars($this->resolveGroupTitle($chatId), ENT_QUOTES, 'UTF-8');
            $this->telegram->sendMessage($targetChatId, "📦 <b>{$groupTitle}</b> uchun audit #{$sessionId} yaratildi.\n\n"
                . "Telegram Desktop'dan guruh tarixini <b>JSON</b> formatida eksport qiling. Keyin <code>result.json</code> yoki ZIP faylni aynan shu shaxsiy chatga yuboring. Maksimum: 20 MB.\n\n"
                . "Eslatma: tarixiy audit xavfli xabarlarni topib hisobot qiladi, ammo eski xabarlarni avtomatik ommaviy o'chirmaydi.");
            return ['status' => 'audit_waiting_upload', 'session_id' => $sessionId];
        }

        if ($action === 'audit_status') {
            $session = AuditService::latestForChat($chatId);
            if (!$session) {
                $this->telegram->sendMessage($targetChatId, "Bu guruh uchun audit hali o'tkazilmagan.");
                return ['status' => 'audit_not_found'];
            }
            $this->telegram->sendMessage($targetChatId, "📊 <b>Audit #{$session['id']}</b>\n"
                . "• Holat: <b>{$session['status']}</b>\n"
                . "• Tekshirildi: {$session['total_scanned']}\n"
                . "• Topilmalar: {$session['total_flagged']}"
                . (!empty($session['error_message']) ? "\n• Xato: " . htmlspecialchars((string)$session['error_message'], ENT_QUOTES, 'UTF-8') : ''));
            return ['status' => 'audit_status_sent'];
        }

        if ($action === 'audit_report') {
            $session = AuditService::latestForChat($chatId);
            if (!$session) {
                $this->telegram->sendMessage($targetChatId, "Bu guruh uchun audit hisoboti topilmadi.");
                return ['status' => 'audit_not_found'];
            }
            $sessionId = (int)$session['id'];
            $this->telegram->sendMessage($targetChatId, ReportService::generateTelegramSummary($sessionId));
            $this->sendAuditReportFiles($targetChatId, $sessionId);
            return ['status' => 'audit_report_sent', 'session_id' => $sessionId];
        }

        return ['status' => 'ignored'];
    }

    private function sendPrivateCommandGroupPicker(int $userId, string $action): array
    {
        $pdo = Database::getConnection();
        if ($this->auth->isSystemAdmin($userId)) {
            $stmt = $pdo->prepare("SELECT chat_id, title FROM `groups` WHERE is_active = 1 ORDER BY updated_at DESC");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT c.chat_id, g.title
                FROM chat_members c
                JOIN `groups` g ON g.chat_id = c.chat_id
                WHERE c.user_id = :uid
                  AND c.role IN ('creator', 'administrator')
                  AND g.is_active = 1
                ORDER BY g.updated_at DESC
            ");
            $stmt->execute(['uid' => $userId]);
        }
        $groups = $stmt->fetchAll();
        if (!$groups) {
            $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi.");
            return ['status' => 'managed_group_not_found'];
        }
        if (count($groups) === 1) {
            return $this->handlePrivateAdminAction($action, (int)$groups[0]['chat_id'], $userId, $userId);
        }

        $buttons = [];
        foreach ($groups as $group) {
            $chatId = (int)$group['chat_id'];
            $title = $this->resolveGroupTitle($chatId, (string)($group['title'] ?? ''));
            $buttons[] = [[
                'text' => "👥 {$title}",
                'callback_data' => "admin_{$action}:{$chatId}",
            ]];
        }
        $this->telegram->sendMessage($userId, "Amal bajariladigan guruhni tanlang:", ['reply_markup' => ['inline_keyboard' => $buttons]]);
        return ['status' => 'private_group_picker_sent', 'action' => $action];
    }

    private function sendSettingsMenu(int $chatId, ?int $editMessageId = null, ?int $targetChatId = null): array
    {
        $sendTo = $targetChatId ?? $chatId;
        $s = SettingsService::get($chatId);
        $title = '';
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT title FROM `groups` WHERE chat_id = :cid");
            $stmt->execute(['cid' => $chatId]);
            $t = $stmt->fetchColumn();
            if (!empty($t)) {
                $title = (string)$t;
            }
        } catch (Throwable) {
        }
        $title = $this->resolveGroupTitle($chatId, $title);

        $text = "⚙️ <b>Guruh Moderatsiya Sozlamalari</b>\n"
              . "👥 Guruh: <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b> (<code>{$chatId}</code>)\n\n"
              . "Tegishli funksiyani yoqish yoki o'chirish uchun tugmani bosing:";

        $buttons = [
            'inline_keyboard' => [
                [
                    ['text' => ($s['clean_service_messages'] ? '✅' : '❌') . " Kirdi-chiqdi tozalash", 'callback_data' => "set_clean_service_messages:{$chatId}"],
                ],
                [
                    ['text' => ($s['profanity_filter'] ? '✅' : '❌') . " So'kinish filtri", 'callback_data' => "set_profanity_filter:{$chatId}"],
                    ['text' => ($s['porn_filter'] ? '✅' : '❌') . " Pornografiya filtri", 'callback_data' => "set_porn_filter:{$chatId}"],
                ],
                [
                    ['text' => ($s['media_filter'] ? '✅' : '❌') . " Media tekshiruvi", 'callback_data' => "set_media_filter:{$chatId}"],
                    ['text' => ($s['profile_scan'] ? '✅' : '❌') . " Profil tekshiruvi", 'callback_data' => "set_profile_scan:{$chatId}"],
                ],
                [
                    ['text' => (($s['bot_filter'] ?? 1) ? '✅' : '❌') . " Bot akkaunt filtri", 'callback_data' => "set_bot_filter:{$chatId}"],
                    ['text' => ($s['link_filter'] ? '✅' : '❌') . " Havola (link) filtri", 'callback_data' => "set_link_filter:{$chatId}"],
                ],
                [
                    ['text' => (($s['ai_mode'] ?? '') === 'comprehensive' ? '🧠' : '⚡') . " AI: " . ($s['ai_mode'] ?? 'comprehensive'), 'callback_data' => "set_ai_mode:{$chatId}"],
                ],
                [
                    ['text' => '👥 A\'zolarni tekshirish (18+ / bot)', 'callback_data' => "admin_scan_members:{$chatId}"],
                ],
                [
                    ['text' => '📦 Eski xabarlar auditi', 'callback_data' => "admin_audit_json:{$chatId}"],
                ],
                [
                    ['text' => '📊 Audit holati', 'callback_data' => "admin_audit_status:{$chatId}"],
                    ['text' => '📄 Audit hisoboti', 'callback_data' => "admin_audit_report:{$chatId}"],
                ],
                [
                    ['text' => '📈 Statistika', 'callback_data' => "admin_stats:{$chatId}"],
                    ['text' => '💰 AI sarfi', 'callback_data' => "admin_ai_usage:{$chatId}"],
                ],
            ]
        ];

        // "Bosh menyu" tugmasi faqat shaxsiy chatда ko'rsatiladi.
        if ($sendTo > 0) {
            $buttons['inline_keyboard'][] = [['text' => '⬅️ Bosh menyu', 'callback_data' => 'pm_menu']];
        }

        if ($editMessageId) {
            return $this->telegram->request('editMessageText', [
                'chat_id' => $sendTo,
                'message_id' => $editMessageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $buttons,
            ]);
        }

        return $this->telegram->sendMessage($sendTo, $text, ['reply_markup' => $buttons]);
    }

    /**
     * Shaxsiy chatdagi bosh menyu (inline tugmalar).
     */
    private function sendMainMenu(int $userId, ?int $editMessageId = null): array
    {
        $botUser = ltrim((string)Config::get('TELEGRAM_BOT_USERNAME', ''), '@');
        $text = "🛡 <b>Block-BOT</b> — guruh moderatsiya yordamchisi\n\n"
            . "Guruhingizga meni <b>admin</b> qilib qo'shing (Delete messages, Ban users huquqlari bilan). "
            . "So'ng shu yerdan guruhlaringizni boshqaring.";

        $rows = [
            [['text' => '📋 Mening guruhlarim', 'callback_data' => 'pm_mygroups']],
            [
                ['text' => '🤖 AI holati', 'callback_data' => 'pm_ai'],
                ['text' => '❓ Yordam', 'callback_data' => 'pm_help'],
            ],
        ];
        if ($botUser !== '') {
            $rows[] = [['text' => '➕ Meni guruhga qo\'shish', 'url' => "https://t.me/{$botUser}?startgroup=true"]];
        }
        $markup = ['inline_keyboard' => $rows];

        if ($editMessageId) {
            return $this->telegram->request('editMessageText', [
                'chat_id' => $userId,
                'message_id' => $editMessageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $markup,
            ]);
        }
        return $this->telegram->sendMessage($userId, $text, ['reply_markup' => $markup]);
    }

    private function helpText(): string
    {
        return "❓ <b>Block-BOT — yordam</b>\n\n"
            . "<b>Guruhда (faqat admin):</b>\n"
            . "/settings — moderatsiya sozlamalari menyusi\n"
            . "/status — bot holati\n"
            . "/stats — 30 kunlik statistika\n"
            . "/scan_members — a'zolarni 18+ / bot akkauntlarga tekshirish\n"
            . "/audit — eski xabarlar tarixini tahlil qilish\n"
            . "/warn /mute [soat] /ban — xabarga reply qilib jazo berish\n"
            . "/unmute /unban /resetwarns — cheklovni yechish\n"
            . "/blockword, /allowword, /unblockword, /wordlist — jargon/lahjadagi maxsus so'zlarni boshqarish\n\n"
            . "<b>Shaxsiy chatда:</b>\n"
            . "/menu — bosh menyu\n"
            . "/mygroups — guruhlaringiz ro'yxati\n"
            . "/appeal ID — cheklovga shikoyat\n\n"
            . "<b>Eski xabarlar auditi qanday ishlaydi?</b>\n"
            . "Telegram Bot API bot qo'shilishidan oldingi xabarlarni o'qiy olmaydi. Shuning uchun: "
            . "Telegram Desktop → guruh → ⋮ → <b>Export chat history</b> → format <b>JSON</b> → "
            . "hosil bo'lgan <code>result.json</code> (yoki ZIP) faylni shu botga yuboring. "
            . "Bot uni tahlil qilib, so'kinish/18+/spam xabarlarni topadi va tasdiqingiz bilan o'chiradi.\n\n"
            . "<i>Eslatma: adminlar va oq ro'yxatdagi a'zolar moderatsiyadan ozod — sinovni oddiy a'zo akkaunt bilan qiling.</i>\n\n"
            . "<b>AI nima deb javob berganini ko'rish (serverda):</b>\n"
            . "<code>tail -n 50 storage/logs/ai.log</code>";
    }

    private function handlePrivateMenu(string $action, int $userId, ?int $editMessageId): array
    {
        if ($action === 'menu') {
            $this->sendMainMenu($userId, $editMessageId);
            return ['status' => 'private_menu_shown'];
        }

        if ($action === 'help') {
            $markup = ['inline_keyboard' => [[['text' => '⬅️ Orqaga', 'callback_data' => 'pm_menu']]]];
            if ($editMessageId) {
                $this->telegram->request('editMessageText', [
                    'chat_id' => $userId, 'message_id' => $editMessageId,
                    'text' => $this->helpText(), 'parse_mode' => 'HTML', 'reply_markup' => $markup,
                ]);
            } else {
                $this->telegram->sendMessage($userId, $this->helpText(), ['reply_markup' => $markup]);
            }
            return ['status' => 'private_help_shown'];
        }

        if ($action === 'mygroups') {
            $groups = $this->adminGroupsOf($userId);
            if ($groups === []) {
                $botUser = ltrim((string)Config::get('TELEGRAM_BOT_USERNAME', ''), '@');
                $rows = [];
                if ($botUser !== '') {
                    $rows[] = [['text' => '➕ Meni guruhga qo\'shish', 'url' => "https://t.me/{$botUser}?startgroup=true"]];
                }
                $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'pm_menu']];
                $body = "📋 Siz administrator bo'lgan, botga ulangan guruh topilmadi.\n\n"
                    . "Meni guruhingizga admin qilib qo'shing va bir marta guruhда <code>/settings</code> yozing.";
                $editMessageId
                    ? $this->telegram->request('editMessageText', ['chat_id' => $userId, 'message_id' => $editMessageId, 'text' => $body, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $rows]])
                    : $this->telegram->sendMessage($userId, $body, ['reply_markup' => ['inline_keyboard' => $rows]]);
                return ['status' => 'private_no_groups'];
            }

            if (count($groups) === 1) {
                $this->sendSettingsMenu((int)$groups[0]['chat_id'], $editMessageId, $userId);
                return ['status' => 'private_settings_opened'];
            }
            $rows = [];
            foreach ($groups as $g) {
                $gId = (int)$g['chat_id'];
                $rows[] = [['text' => "⚙️ " . $this->resolveGroupTitle($gId, (string)($g['title'] ?? '')), 'callback_data' => "open_settings:{$gId}"]];
            }
            $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'pm_menu']];
            $body = "📋 <b>Guruhlaringiz</b>\nSozlamalarini ochish uchun guruhni tanlang:";
            $editMessageId
                ? $this->telegram->request('editMessageText', ['chat_id' => $userId, 'message_id' => $editMessageId, 'text' => $body, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $rows]])
                : $this->telegram->sendMessage($userId, $body, ['reply_markup' => ['inline_keyboard' => $rows]]);
            return ['status' => 'private_group_list_sent'];
        }

        if ($action === 'ai') {
            $groups = $this->adminGroupsOf($userId);
            if ($groups === []) {
                $this->telegram->sendMessage($userId, "AI holatini ko'rish uchun avval botni guruhingizga admin qiling.");
                return ['status' => 'private_no_groups'];
            }
            if (count($groups) === 1) {
                return $this->handlePrivateAdminAction('ai_usage', (int)$groups[0]['chat_id'], $userId, $userId);
            }
            $rows = [];
            foreach ($groups as $g) {
                $gId = (int)$g['chat_id'];
                $rows[] = [['text' => "💰 " . $this->resolveGroupTitle($gId, (string)($g['title'] ?? '')), 'callback_data' => "admin_ai_usage:{$gId}"]];
            }
            $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'pm_menu']];
            $this->telegram->sendMessage($userId, "🤖 Qaysi guruh bo'yicha AI sarfini ko'rasiz?", ['reply_markup' => ['inline_keyboard' => $rows]]);
            return ['status' => 'private_group_picker_sent'];
        }

        return ['status' => 'ignored'];
    }

    /**
     * @return array<int, array{chat_id:int, title:?string}>
     */
    private function adminGroupsOf(int $userId): array
    {
        $pdo = Database::getConnection();
        if ($this->auth->isSystemAdmin($userId)) {
            $stmt = $pdo->prepare("SELECT chat_id, title FROM `groups` WHERE is_active = 1 ORDER BY updated_at DESC");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT c.chat_id, g.title
                FROM chat_members c
                LEFT JOIN `groups` g ON g.chat_id = c.chat_id
                WHERE c.user_id = :uid AND c.role IN ('creator', 'administrator')
                  AND (g.is_active = 1 OR g.is_active IS NULL)
                ORDER BY g.updated_at DESC
            ");
            $stmt->execute(['uid' => $userId]);
        }
        return $stmt->fetchAll() ?: [];
    }

    private function handlePrivateChat(array $message): array
    {
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $text = trim((string)($message['text'] ?? ''));

        if (!empty($message['document'])) {
            $upload = $this->handlePrivateAuditUpload($message, $userId);
            if ($upload !== null) {
                return $upload;
            }
        }

        if (preg_match('/^\/(?:myid|id)(?:@\w+)?$/i', $text)) {
            $this->telegram->sendMessage($userId, "Sizning Telegram ID: <code>{$userId}</code>\nUni serverdagi <code>TELEGRAM_OWNER_IDS</code> qiymatiga yozing.");
            return ['status' => 'private_id_sent'];
        }

        if (preg_match('/^\/appeal(?:@\w+)?\s+(\d+)$/i', $text, $match)) {
            $result = $this->createAppeal((int)$match[1], $userId);
            $messages = [
                'appeal_created' => "✅ Shikoyatingiz qabul qilindi. AI xulosasi administratorga yuborildi va yakuniy qarorni admin tasdiqlaydi.",
                'already_pending' => "ℹ️ Bu qaror bo'yicha shikoyat avval yuborilgan.",
                'unauthorized' => "❌ Bu harakat sizga tegishli emas.",
                'not_found' => "❌ Bunday moderatsiya harakati topilmadi.",
            ];
            $this->telegram->sendMessage($userId, $messages[$result['status'] ?? 'not_found'] ?? "❌ Shikoyatni yuborib bo'lmadi.");
            return $result;
        }

        if (preg_match('/^\/(ai_usage|stats|audit_status|audit_report|audit|scan_members)(?:@\w+)?(?:\s+(json|mtproto))?$/i', $text, $match)) {
            $command = strtolower($match[1]);
            if ($command === 'audit') {
                $command = strtolower((string)($match[2] ?? 'json')) === 'mtproto' ? 'audit_mtproto' : 'audit_json';
            }
            return $this->sendPrivateCommandGroupPicker($userId, $command);
        }

        // Agar /start settings_-100... deb kelgan bo'lsa:
        if (str_starts_with($text, '/start settings_')) {
            $targetChatId = (int)substr($text, 16);
            if ($targetChatId !== 0 && $this->auth->isAdmin($targetChatId, $userId)) {
                $this->sendSettingsMenu($targetChatId, null, $userId);
                return ['status' => 'private_settings_opened'];
            }
        }

        if (preg_match('/^\/help(?:@\w+)?$/i', $text)) {
            return $this->handlePrivateMenu('help', $userId, null);
        }
        if (preg_match('/^\/(menu|mygroups)(?:@\w+)?$/i', $text, $m)) {
            return $this->handlePrivateMenu($m[1] === 'menu' ? 'menu' : 'mygroups', $userId, null);
        }

        // Plain /start: avval kutilayotgan shikoyatni ko'rsatamiz, aks holda bosh menyu.
        if (preg_match('/^\/start(?:@\w+)?$/i', $text)) {
            if ($this->sendLatestAppealPrompt($userId)) {
                $this->sendMainMenu($userId);
                return ['status' => 'private_appeal_prompt_sent'];
            }
            $this->sendMainMenu($userId);
            return ['status' => 'private_menu_shown'];
        }

        // Boshqa har qanday matn — bosh menyu.
        $this->sendMainMenu($userId);
        return ['status' => 'private_menu_shown'];
    }

    private function sendLatestAppealPrompt(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = Database::getConnection()->prepare("
                SELECT t.id, t.chat_id, t.action_type
                FROM telegram_actions t
                LEFT JOIN moderation_appeals a ON a.action_id = t.id
                WHERE t.user_id = :uid
                  AND t.action_type IN ('mute_user', 'ban_user')
                  AND t.status IN ('executed', 'partial')
                  AND a.id IS NULL
                  AND t.created_at >= :since
                ORDER BY t.id DESC
                LIMIT 1
            ");
            $stmt->execute(['uid' => $userId, 'since' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            $action = $stmt->fetch();
            if (!$action) {
                return false;
            }

            $actionId = (int)$action['id'];
            $chatId = (int)$action['chat_id'];
            $groupTitle = $this->resolveGroupTitle($chatId);
            $actionLabel = $action['action_type'] === 'ban_user' ? 'guruhdan chiqarish' : 'vaqtincha cheklash';
            $this->telegram->sendMessage($userId,
                "📝 <b>Moderatsiya qarori topildi</b>\n"
                . "Guruh: <b>" . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . "</b>\n"
                . "Qaror: {$actionLabel}\n"
                . "Harakat ID: <code>{$actionId}</code>\n\n"
                . "Qaror noto'g'ri bo'lsa, shikoyat yuborishingiz mumkin.",
                ['reply_markup' => ['inline_keyboard' => [[
                    ['text' => '📝 Shikoyat qilish', 'callback_data' => "appeal_request:{$actionId}"]
                ]]]]
            );
            return true;
        } catch (Throwable $e) {
            Logger::warning("Shaxsiy shikoyat tugmasini tayyorlashda xato: " . $e->getMessage(), ['user_id' => $userId], 'moderation');
            return false;
        }
    }

    private function resolveTargetUserId(array $message, array $parts): int
    {
        if (isset($message['reply_to_message']['from']['id'])) {
            return (int)$message['reply_to_message']['from']['id'];
        }
        return isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;
    }

    private function handlePrivateAuditUpload(array $message, int $adminId): ?array
    {
        $document = $message['document'] ?? [];
        $fileName = basename((string)($document['file_name'] ?? ''));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'zip'], true)) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM audit_sessions
            WHERE admin_user_id = :uid
              AND source_type = 'json_export'
              AND status = 'waiting_upload'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['uid' => $adminId]);
        $session = $stmt->fetch();
        if (!$session) {
            $this->telegram->sendMessage($adminId, "❌ Kutilayotgan audit topilmadi. Avval guruh menyusidan <b>Eski xabarlar auditi</b> tugmasini bosing.");
            return ['status' => 'error', 'reason' => 'audit_session_not_waiting'];
        }

        $chatId = (int)$session['chat_id'];
        if (!$this->auth->isAdmin($chatId, $adminId)) {
            return ['status' => 'unauthorized'];
        }
        if ((int)($document['file_size'] ?? 0) > 20 * 1024 * 1024) {
            $this->telegram->sendMessage($adminId, "❌ Audit fayli 20 MB limitdan katta.");
            return ['status' => 'error', 'reason' => 'audit_file_too_large'];
        }

        QueueService::push('App\Jobs\ImportAuditJob', [
            'session_id' => (int)$session['id'],
            'chat_id' => $chatId,
            'notify_chat_id' => $adminId,
            'file_id' => (string)($document['file_id'] ?? ''),
            'file_name' => $fileName,
        ], QueueService::PRIORITY_LOW);
        AuditService::markRunning((int)$session['id']);
        $this->telegram->sendMessage($adminId, "⏳ Audit fayli qabul qilindi. Tekshiruv navbatga qo'yildi. Holatni guruh menyusidagi <b>Audit holati</b> tugmasidan ko'ring.");
        return ['status' => 'audit_import_queued', 'session_id' => (int)$session['id']];
    }

    private function handleAuditUpload(array $message, int $chatId): ?array
    {
        $document = $message['document'] ?? [];
        $fileName = basename((string)($document['file_name'] ?? ''));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'zip'], true)) {
            return null;
        }

        $session = AuditService::latestForChat($chatId, ['waiting_upload']);
        if (!$session || ($session['source_type'] ?? '') !== 'json_export') {
            $this->telegram->sendMessage($chatId, "Avval /audit buyrug'i bilan audit sessiyasini yarating.");
            return ['status' => 'error', 'reason' => 'audit_session_not_waiting'];
        }

        if ((int)($document['file_size'] ?? 0) > 20 * 1024 * 1024) {
            $this->telegram->sendMessage($chatId, "Audit fayli 20 MB limitdan katta.");
            return ['status' => 'error', 'reason' => 'audit_file_too_large'];
        }

        QueueService::push('App\Jobs\ImportAuditJob', [
            'session_id' => (int)$session['id'],
            'chat_id' => $chatId,
            'file_id' => (string)($document['file_id'] ?? ''),
            'file_name' => $fileName,
        ], QueueService::PRIORITY_LOW);
        AuditService::markRunning((int)$session['id']);
        $this->telegram->sendMessage($chatId, "⏳ Audit fayli qabul qilindi va navbatga qo'yildi.");
        return ['status' => 'audit_import_queued', 'session_id' => (int)$session['id']];
    }

    private function sendAuditReportFiles(int $chatId, int $sessionId): void
    {
        $tempDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
        $htmlPath = $tempDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}_" . bin2hex(random_bytes(4)) . '.html';
        $csvPath = $tempDir . DIRECTORY_SEPARATOR . "audit_{$sessionId}_" . bin2hex(random_bytes(4)) . '.csv';
        try {
            file_put_contents($htmlPath, ReportService::generateHtmlReport($sessionId));
            file_put_contents($csvPath, ReportService::generateCsvReport($sessionId));
            $this->telegram->sendDocument($chatId, $htmlPath, "Audit #{$sessionId} — HTML hisobot");
            $this->telegram->sendDocument($chatId, $csvPath, "Audit #{$sessionId} — CSV hisobot");
        } finally {
            @unlink($htmlPath);
            @unlink($csvPath);
        }
    }

    private function persistUser(array $user): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $params = [
            'uid' => $userId,
            'first' => (string)($user['first_name'] ?? ''),
            'last' => $user['last_name'] ?? null,
            'username' => $user['username'] ?? null,
            'is_bot' => !empty($user['is_bot']) ? 1 : 0,
            'first_seen' => $now,
            'last_seen' => $now,
        ];
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = "INSERT INTO users (user_id, first_name, last_name, username, is_bot, first_seen_at, last_seen_at) VALUES (:uid, :first, :last, :username, :is_bot, :first_seen, :last_seen) ON CONFLICT(user_id) DO UPDATE SET first_name = excluded.first_name, last_name = excluded.last_name, username = excluded.username, is_bot = excluded.is_bot, last_seen_at = excluded.last_seen_at";
        } else {
            $sql = "INSERT INTO users (user_id, first_name, last_name, username, is_bot, first_seen_at, last_seen_at) VALUES (:uid, :first, :last, :username, :is_bot, :first_seen, :last_seen) ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), username = VALUES(username), is_bot = VALUES(is_bot), last_seen_at = VALUES(last_seen_at)";
        }
        $pdo->prepare($sql)->execute($params);
    }

    private function persistChatMember(int $chatId, int $userId, string $role = 'member'): void
    {
        if ($chatId === 0 || $userId <= 0) {
            return;
        }
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        try {
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                if ($role === 'member') {
                    $sql = "INSERT INTO chat_members (chat_id, user_id, role, updated_at)
                            VALUES (:cid, :uid, :role, :now)
                            ON CONFLICT(chat_id, user_id) DO UPDATE SET
                            role = CASE WHEN chat_members.role IN ('creator', 'administrator') THEN chat_members.role ELSE excluded.role END,
                            updated_at = excluded.updated_at";
                } else {
                    $sql = "INSERT INTO chat_members (chat_id, user_id, role, updated_at)
                            VALUES (:cid, :uid, :role, :now)
                            ON CONFLICT(chat_id, user_id) DO UPDATE SET
                            role = excluded.role,
                            updated_at = excluded.updated_at";
                }
            } else {
                if ($role === 'member') {
                    $sql = "INSERT INTO chat_members (chat_id, user_id, role, updated_at)
                            VALUES (:cid, :uid, :role, :now)
                            ON DUPLICATE KEY UPDATE
                            role = IF(role IN ('creator', 'administrator'), role, VALUES(role)),
                            updated_at = VALUES(updated_at)";
                } else {
                    $sql = "INSERT INTO chat_members (chat_id, user_id, role, updated_at)
                            VALUES (:cid, :uid, :role, :now)
                            ON DUPLICATE KEY UPDATE
                            role = VALUES(role),
                            updated_at = VALUES(updated_at)";
                }
            }
            $pdo->prepare($sql)->execute(['cid' => $chatId, 'uid' => $userId, 'role' => $role, 'now' => $now]);
            AdminAuthorizationService::forget($chatId, $userId);
        } catch (Throwable $e) {
            Logger::error("Chat a'zosini saqlashda xato: " . $e->getMessage(), ['chat_id' => $chatId, 'user_id' => $userId], 'database');
        }
    }

    private function handleMyChatMember(array $myChatMember): array
    {
        $chat = $myChatMember['chat'] ?? [];
        $chatId = (int)($chat['id'] ?? 0);
        $newMember = $myChatMember['new_chat_member'] ?? [];
        $status = (string)($newMember['status'] ?? '');
        $oldStatus = (string)($myChatMember['old_chat_member']['status'] ?? '');

        if ($chatId === 0) {
            return ['status' => 'ignored'];
        }

        // Guruh sozlamalarini yuklash/yaratish
        SettingsService::get($chatId);
        $this->persistGroup($chat, !in_array($status, ['left', 'kicked'], true));

        $actor = $myChatMember['from'] ?? [];
        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId > 0) {
            $this->persistUser($actor);
            // Haqiqiy rol Telegram orqali tasdiqlanib DB keshiga yoziladi.
            $this->auth->isAdmin($chatId, $actorId);
        }

        if (in_array($status, ['administrator', 'creator'], true)) {
            $this->syncGroupAdministrators($chatId);
            $text = "🛡 <b>Block-BOT administrator sifatida ulandi.</b>\n"
                  . "Guruh xabarlari real vaqtda tekshiriladi. Sozlamalar: /settings\n"
                  . "Admin hisobotlari shu shaxsiy chatga yuboriladi.\n\n"
                  . "Eski xabarlar tarixini tekshirish uchun /audit, guruhdagi 18+ va bot "
                  . "akkauntlarni aniqlash uchun quyidagi tugmani bosing.";
            if ($actorId > 0) {
                $this->telegram->sendMessage($actorId, $text, [
                    'reply_markup' => ['inline_keyboard' => [[
                        ['text' => '👥 A\'zolarni tekshirish (18+ / bot)', 'callback_data' => "admin_scan_members:{$chatId}"],
                    ]]],
                ]);
            }

            // Faqat birinchi ulanishda barcha guruh adminlari uchun self-service boshqaruv havolasi.
            if (!in_array($oldStatus, ['administrator', 'creator'], true)) {
                $botUsername = ltrim((string)Config::get('TELEGRAM_BOT_USERNAME', ''), '@');
                if ($botUsername !== '') {
                    $markup = ['inline_keyboard' => [[
                        ['text' => '⚙️ Guruh boshqaruvini ochish', 'url' => "https://t.me/{$botUsername}?start=settings_{$chatId}"]
                    ]]];
                    $result = $this->telegram->sendMessage($chatId, "🛡 Bot ishga tushdi. Guruh administratorlari boshqaruvni shaxsiy chatda ulashi mumkin.", ['reply_markup' => $markup]);
                    if (($result['ok'] ?? false) && isset($result['result']['message_id'])) {
                        QueueService::push('App\Jobs\DeleteMessageJob', [
                            'chat_id' => $chatId,
                            'message_id' => (int)$result['result']['message_id'],
                        ], QueueService::PRIORITY_NORMAL, 600);
                    }
                }
            }
            return ['status' => 'bot_promoted_admin', 'chat_id' => $chatId];
        }

        return ['status' => 'my_chat_member_updated', 'status_type' => $status];
    }

    private function handleChatMember(array $chatMember): array
    {
        $chat = $chatMember['chat'] ?? [];
        $chatId = (int)($chat['id'] ?? 0);
        $member = $chatMember['new_chat_member'] ?? [];
        $user = $member['user'] ?? [];
        $userId = (int)($user['id'] ?? 0);
        if ($chatId === 0 || $userId === 0) {
            return ['status' => 'ignored'];
        }

        $settings = SettingsService::get($chatId);
        $this->persistGroup($chat, true);
        $this->persistUser($user);
        $newStatus = (string)($member['status'] ?? 'member');
        $oldStatus = (string)($chatMember['old_chat_member']['status'] ?? '');
        $role = match ($newStatus) {
            'creator' => 'creator',
            'administrator' => 'administrator',
            'left' => 'left',
            'kicked' => 'banned',
            default => 'member',
        };
        $this->persistChatMember($chatId, $userId, $role);

        // Yangi qo'shilgan a'zoni (new_chat_members xizmat xabari kelmagan bo'lsa ham)
        // fon rejimida profil / bot tekshiruviga yuborish.
        $isJoin = in_array($newStatus, ['member', 'restricted'], true)
            && in_array($oldStatus, ['left', 'kicked', ''], true);
        if ($isJoin) {
            $isBotMember = (bool)($user['is_bot'] ?? false);
            $shouldScan = $isBotMember
                ? (bool)($settings['bot_filter'] ?? true)
                : (bool)($settings['profile_scan'] ?? true);
            if ($shouldScan) {
                QueueService::push('App\Jobs\ScanProfileJob', [
                    'chat_id' => $chatId,
                    'user' => $user,
                ], QueueService::PRIORITY_NORMAL);
            }
        }

        return ['status' => 'chat_member_updated', 'role' => $role];
    }

    private function persistGroup(array $chat, bool $active): void
    {
        $chatId = (int)($chat['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare("UPDATE `groups` SET title = :title, type = :type, is_active = :active, updated_at = :now WHERE chat_id = :cid")
                ->execute([
                    'title' => mb_substr((string)($chat['title'] ?? 'Guruh'), 0, 255),
                    'type' => (string)($chat['type'] ?? 'supergroup'),
                    'active' => $active ? 1 : 0,
                    'now' => $now,
                    'cid' => $chatId,
                ]);
        } catch (Throwable $e) {
            Logger::warning("Guruh ma'lumotini yangilashda xato: " . $e->getMessage(), ['chat_id' => $chatId], 'database');
        }
    }

    private function resolveGroupTitle(int $chatId, string $cachedTitle = ''): string
    {
        $cachedTitle = trim($cachedTitle);
        $needsRefresh = $cachedTitle === '' || $cachedTitle === 'Guruh' || str_starts_with($cachedTitle, 'Guruh #');
        if ($needsRefresh) {
            try {
                $chat = $this->telegram->getChat($chatId);
                $freshTitle = trim((string)($chat['title'] ?? ''));
                if ($freshTitle !== '') {
                    $this->persistGroup($chat, true);
                    return $freshTitle;
                }
            } catch (Throwable $e) {
                Logger::warning("Guruh nomini Telegram'dan olishda xato: " . $e->getMessage(), ['chat_id' => $chatId], 'telegram');
            }
        }

        return $cachedTitle !== '' && $cachedTitle !== 'Guruh'
            ? $cachedTitle
            : "Guruh #{$chatId}";
    }

    private function syncGroupAdministrators(int $chatId): void
    {
        try {
            foreach ($this->telegram->getChatAdministrators($chatId) as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $user = $member['user'] ?? [];
                $userId = (int)($user['id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $this->persistUser($user);
                $role = ($member['status'] ?? '') === 'creator' ? 'creator' : 'administrator';
                $this->persistChatMember($chatId, $userId, $role);
            }
        } catch (Throwable $e) {
            Logger::warning("Guruh administratorlarini sinxronlashda xato: " . $e->getMessage(), ['chat_id' => $chatId], 'telegram');
        }
    }
}
