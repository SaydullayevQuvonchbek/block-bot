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
use App\Core\Translator;
use App\Policy\AdminAuthorizationService;
use App\Policy\AdminNotificationService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use App\Policy\SubscriptionService;
use App\Moderation\CaptchaGuard;
use App\Moderation\FloodGuard;
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

            // Telegram Stars to'lovi (2.0 Phase 3, 2-band): pre_checkout_query'ga 10
            // soniya ichida javob berilishi SHART, shuning uchun sinxron ishlanadi.
            if (isset($update['pre_checkout_query'])) {
                return $this->handlePreCheckoutQuery($update['pre_checkout_query']);
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

                // --- CAPTCHA: bot bo'lmagan yangi a'zoni vaqtincha cheklash ---
                // Bot akkauntlar bu yerdan o'tkazilmaydi — ular allaqachon mavjud bot_filter/
                // ScanProfileJob mexanizmi orqali alohida ko'rib chiqiladi.
                if (!$isBotMember && $newMemberId > 0 && (bool)($settings['captcha_enabled'] ?? false)) {
                    $this->startCaptcha($chatId, $newMemberId, $newMember, $settings, $this->threadIdFrom($message));
                }

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

        // GURUHDA FAQAT TO'G'RIDAN-TO'G'RI MODERATSIYA BUYRUQLARI ISHLAYDI: admin botni
        // asosan shaxsiy chatda (DM) boshqaradi (/menu, /settings, /stats, /blockword va
        // h.k. — o'sha yerda ishlaydi). Istisno — /warn, /mute, /ban, /unmute, /unban,
        // /resetwarns, /warnings, /addmod, /removemod: bular ma'lum bir xabarga "reply"
        // qilib berilgani uchun shaxsiy chatga ko'chirib bo'lmaydi, shu bois guruhda
        // qoldirilgan (o'zi hech qanday keraksiz "menyu" xabari chiqarmaydi — faqat amal
        // natijasini yozadi). Qolgan barcha "/..." matni pastdagi oddiy moderatsiyadan
        // o'tishda davom etadi. Avtomatik ogohlantirish/jazo (warn/mute/ban) xabarlari
        // ModerateMessageJob/PunishmentService orqali ishlaydi va bunga umuman ta'sir qilmaydi.
        static $groupAllowedCommands = ['/warn', '/mute', '/ban', '/unmute', '/unban', '/resetwarns', '/warnings', '/addmod', '/removemod'];
        if (str_starts_with($rawText, '/')) {
            $cmdOnly = strtolower(explode('@', explode(' ', trim($rawText))[0])[0]);
            if (in_array($cmdOnly, $groupAllowedCommands, true)) {
                $cmdResult = $this->handleCommand($message, $rawText, $chatId);
                if ($cmdResult !== null) {
                    return $cmdResult;
                }
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

        // --- 2.5. Anti-flood / spam-portlash himoyasi ---
        // AI yoki matn/media tahlilidan mustaqil, sof deterministik tezlik nazorati:
        // belgilangan oyna (masalan 10 soniya) ichida ruxsat etilganidan ortiq xabar
        // yuborgan a'zo darhol (qisqa muddatga) mute qilinadi. Bu bosqich content
        // moderatsiyasidan OLDIN ishlaydi — flood holatida xabar mazmuni tekshirilmaydi,
        // chunki muammo aynan "juda tez-tez yozish"ning o'zi.
        if ($userId > 0 && (bool)($settings['flood_enabled'] ?? true)) {
            $floodWindowSec = max(1, (int)($settings['flood_window_sec'] ?? 10));
            $floodMaxMessages = max(1, (int)($settings['flood_max_messages'] ?? 6));
            $floodResult = FloodGuard::register($chatId, $userId, $floodWindowSec, $floodMaxMessages);

            if ($floodResult['flooding']) {
                $floodMuteDuration = max(30, (int)($settings['flood_mute_duration_sec'] ?? 600));
                $decision = [
                    'action' => 'mute_user',
                    'delete_message' => false,
                    'mute_duration_sec' => $floodMuteDuration,
                    'reason' => "Flood/spam-portlash: {$floodWindowSec} soniyada {$floodResult['count']} tadan ortiq xabar yuborildi",
                    'evidence' => "xabarlar soni: {$floodResult['count']}, oyna: {$floodWindowSec}s",
                    // strike_count = 99: bu oddiy ogohlantirish zinapoyasiga (warn->mute->ban)
                    // qo'shilmaydi — flood mustaqil, alohida turdagi qoidabuzarlik.
                    'strike_count' => 99,
                ];
                $this->punishment->execute($chatId, $userId, $messageId, $decision);
                Logger::info("Flood aniqlandi, foydalanuvchi vaqtincha cheklandi", [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'message_count' => $floodResult['count'],
                    'window_sec' => $floodWindowSec,
                ], 'moderation');
                return ['status' => 'flood_muted', 'message_count' => $floodResult['count']];
            }
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

    /**
     * Forum (Topics) rejimidagi superguruhda xabar qaysi mavzuga tegishli ekanini
     * aniqlaydi (2.0 Phase 4 — forum-topics qo'llab-quvvatlashi). Telegram FAQAT
     * nomlangan mavzu ichidagi xabarlarga `is_topic_message: true` +
     * `message_thread_id` qo'yadi — "General" mavzusida yoki oddiy (forum bo'lmagan)
     * guruhda bu maydonlar umuman kelmaydi. Shu sababli `null` qaytarilganda
     * `sendMessage()`ga `message_thread_id` UMUMAN yuborilmaydi — aks holda Telegram
     * "message thread not found" xatosi bilan rad etadi.
     */
    private function threadIdFrom(array $message): ?int
    {
        if (empty($message['is_topic_message']) || !isset($message['message_thread_id'])) {
            return null;
        }
        $threadId = (int)$message['message_thread_id'];
        return $threadId > 0 ? $threadId : null;
    }

    /**
     * `threadIdFrom()`ning qulay shakli — to'g'ridan-to'g'ri `sendMessage()`ning
     * `$extra` massiviga (yoki mavjud `$extra`ga `array_merge` orqali) qo'shish uchun.
     * Mavzu topilmasa bo'sh massiv qaytadi — hech narsa o'zgarmaydi.
     *
     * @return array{message_thread_id?: int}
     */
    private function threadExtra(array $message): array
    {
        $threadId = $this->threadIdFrom($message);
        return $threadId !== null ? ['message_thread_id' => $threadId] : [];
    }

    /**
     * Yangi a'zoni CAPTCHA oqimiga qo'yish: xabar yozish huquqini vaqtincha
     * cheklaydi (mute), "✅ Men botman emas" tugmali xabar yuboradi va
     * kechiktirilgan CaptchaTimeoutJob'ni navbatga qo'yadi (agar vaqtida
     * tasdiqlanmasa — chetlatish uchun). `$messageThreadId` berilsa (forum
     * guruhning nomlangan mavzusida qo'shilgan bo'lsa) — CAPTCHA xabari
     * "General"ga emas, aynan o'sha mavzuga yuboriladi.
     */
    private function startCaptcha(int $chatId, int $userId, array $user, array $settings, ?int $messageThreadId = null): void
    {
        $timeoutSec = max(10, (int)($settings['captcha_timeout_sec'] ?? 60));

        // Bir oz qo'shimcha bufer bilan cheklash — CaptchaTimeoutJob ishlashidan oldin
        // Telegram tomonidan avtomatik ochilib ketmasligi uchun.
        $restricted = $this->telegram->muteUser($chatId, $userId, $timeoutSec + 30);
        if (!$restricted) {
            // Bot cheklash huquqiga ega emas (admin emas yoki "Restrict members" huquqi
            // berilmagan) — captcha oqimini boshlashning ma'nosi yo'q.
            Logger::warning("CAPTCHA: foydalanuvchini cheklab bo'lmadi (bot huquqi yetarli emasmi?)", [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
            return;
        }

        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $name = $name !== '' ? $name : 'Foydalanuvchi';
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $nameLink = "<a href=\"tg://user?id={$userId}\">{$safeName}</a>";

        // Guruhga ko'rinadigan CAPTCHA matni guruhning tanlangan tilida
        // (`group_settings.language`, standart 'uz') tuziladi — 2.0 Phase 3, 1-band (i18n).
        $lang = Translator::normalizeLang($settings['language'] ?? null);
        $text = Translator::get('captcha.welcome', $lang, ['name' => $nameLink, 'timeout' => $timeoutSec]);
        $markup = ['inline_keyboard' => [[
            ['text' => Translator::get('captcha.button', $lang), 'callback_data' => "captcha_verify:{$chatId}:{$userId}"],
        ]]];

        $extra = ['reply_markup' => $markup];
        if ($messageThreadId !== null) {
            $extra['message_thread_id'] = $messageThreadId;
        }
        $sent = $this->telegram->sendMessage($chatId, $text, $extra);
        $messageId = (int)($sent['result']['message_id'] ?? 0);
        if ($messageId === 0) {
            Logger::warning("CAPTCHA: tasdiqlash xabarini yuborib bo'lmadi", [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ], 'moderation');
            $this->telegram->unmuteUser($chatId, $userId);
            return;
        }

        CaptchaGuard::start($chatId, $userId, $messageId, $timeoutSec);
        QueueService::push('App\Jobs\CaptchaTimeoutJob', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], QueueService::PRIORITY_NORMAL, $timeoutSec + 5);
    }

    /**
     * DIQQAT: bu metod endi FAQAT guruhda qoladigan 7 ta to'g'ridan-to'g'ri moderatsiya
     * buyrug'i (/warn, /mute, /ban, /unmute, /unban, /resetwarns, /warnings) uchun
     * chaqiriladi — handleMessage() dagi $groupAllowedCommands ro'yxatiga qarang. Qolgan
     * barcha admin buyruqlari (/settings, /status, /stats, /ai_usage, /scan_members,
     * /audit*, /blockword-oilasi, /wordlist) endi faqat shaxsiy chatda (handlePrivateChat)
     * ishlaydi, chunki ular reply-kontekstiga bog'liq emas va DM'ga ko'chirilgan.
     */
    private function handleCommand(array $message, string $text, int $chatId): ?array
    {
        $parts = explode(' ', trim($text));
        $cmd = strtolower(explode('@', $parts[0])[0]);
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $senderChat = $message['sender_chat'] ?? null;

        $isAdmin = $this->auth->isAdmin($chatId, $userId, $senderChat);
        $isModerator = !$isAdmin && $this->auth->isModerator($chatId, $userId);
        if (!$isAdmin && !$isModerator) {
            return null; // Oddiy a'zolarga guruhda ortiqcha xabar chiqarmaymiz
        }

        // Botning ichki "moderator" roli faqat cheklangan, qaytariladigan chora
        // buyruqlariga ruxsat beradi — /ban, /unban, /resetwarns, /addmod, /removemod
        // kabi og'irroq/qaytarib bo'lmaydigan yoki rol-boshqaruv buyruqlari faqat
        // to'liq adminlarga (yoki guruh egasi/bot egasiga) qoladi.
        static $moderatorAllowedCommands = ['/warn', '/mute', '/unmute', '/warnings'];
        if ($isModerator && !in_array($cmd, $moderatorAllowedCommands, true)) {
            $this->telegram->sendMessage($chatId, "⛔ Bu buyruq faqat guruh administratorlari uchun.", $this->threadExtra($message));
            return ['status' => 'forbidden_for_moderator', 'cmd' => $cmd];
        }

        switch ($cmd) {
            case '/warn':
            case '/mute':
            case '/ban':
            case '/unmute':
            case '/unban':
            case '/resetwarns':
                return $this->handleModerationCommand($cmd, $message, $parts, $chatId);

            case '/addmod':
            case '/removemod':
                return $this->handleModeratorRoleCommand($cmd, $message, $parts, $chatId);

            case '/warnings':
                $targetUserId = $this->resolveTargetUserId($message, $parts);
                if ($targetUserId <= 0) {
                    $this->telegram->sendMessage($chatId, "Ogohlantirishlarni ko'rish uchun foydalanuvchi xabariga reply qiling yoki ID kiriting.", $this->threadExtra($message));
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
                $this->telegram->sendMessage($chatId, $msg, $this->threadExtra($message));
                return ['status' => 'command_executed', 'cmd' => $cmd];
        }

        return null;
    }

    /**
     * Guruh uchun maxsus so'z/ibora qoidasini qo'shish yoki o'chirish
     * (/blockword, /allowword, /unblockword). Jargon/lahjadagi so'kinishlarni
     * o'rnatilgan ro'yxatga qo'shimcha ravishda mahalliy tarzda taqiqlash imkonini beradi.
     */
    private function handleWordRuleCommand(string $cmd, string $text, array $parts, int $chatId, ?int $replyChatId = null): array
    {
        $replyChatId ??= $chatId;
        $phrase = trim(mb_substr($text, mb_strlen($parts[0])));
        if ($phrase === '' || mb_strlen($phrase) > 100) {
            $this->telegram->sendMessage($replyChatId, "Foydalanish: <code>{$cmd} so'z_yoki_ibora</code> (1-100 belgi).\nMasalan: <code>{$cmd} qashqaldoq</code>");
            return ['status' => 'error', 'reason' => 'phrase_required'];
        }

        $pdo = Database::getConnection();
        if ($cmd === '/unblockword') {
            $stmt = $pdo->prepare("DELETE FROM word_rules WHERE chat_id = :cid AND word_pattern = :p");
            $stmt->execute(['cid' => $chatId, 'p' => $phrase]);
            $this->telegram->sendMessage($replyChatId, $stmt->rowCount() > 0
                ? "✅ \"" . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . "\" ro'yxatdan olib tashlandi."
                : "ℹ️ Bunday qoida topilmadi.");
            return ['status' => 'command_executed', 'cmd' => $cmd];
        }

        $ruleType = $cmd === '/allowword' ? 'whitelist' : 'blacklist';
        $existsStmt = $pdo->prepare("SELECT id FROM word_rules WHERE chat_id = :cid AND word_pattern = :p AND rule_type = :t");
        $existsStmt->execute(['cid' => $chatId, 'p' => $phrase, 't' => $ruleType]);
        if ($existsStmt->fetch()) {
            $this->telegram->sendMessage($replyChatId, "ℹ️ Bu ibora allaqachon ro'yxatda.");
            return ['status' => 'already_exists', 'cmd' => $cmd];
        }

        $pdo->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, :t, :p, 0, :now)")
            ->execute(['cid' => $chatId, 't' => $ruleType, 'p' => $phrase, 'now' => gmdate('Y-m-d H:i:s')]);

        $label = $ruleType === 'whitelist' ? "oq ro'yxatga (hech qachon bloklanmaydi)" : "qora ro'yxatga (darhol o'chiriladi)";
        $this->telegram->sendMessage($replyChatId, "✅ \"" . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . "\" {$label} qo'shildi.");
        return ['status' => 'command_executed', 'cmd' => $cmd];
    }

    private function handleWordListCommand(int $chatId, ?int $replyChatId = null): array
    {
        $replyChatId ??= $chatId;
        $stmt = Database::getConnection()->prepare("
            SELECT rule_type, word_pattern FROM word_rules
            WHERE chat_id = :cid ORDER BY rule_type, id DESC LIMIT 50
        ");
        $stmt->execute(['cid' => $chatId]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            $this->telegram->sendMessage($replyChatId, "Bu guruh uchun maxsus so'z qoidalari yo'q.\nQo'shish: <code>/blockword so'z</code> yoki <code>/allowword so'z</code>");
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
        $this->telegram->sendMessage($replyChatId, $msg);
        return ['status' => 'command_executed', 'cmd' => '/wordlist'];
    }

    /**
     * /modlist — guruhga botning ichki "moderator" roli bilan tayinlangan
     * a'zolar ro'yxatini shaxsiy chatda ko'rsatish.
     */
    private function handleModListCommand(int $chatId, int $replyChatId): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT user_id, updated_at FROM chat_members
            WHERE chat_id = :cid AND bot_role = 'moderator'
            ORDER BY updated_at DESC LIMIT 50
        ");
        $stmt->execute(['cid' => $chatId]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            $this->telegram->sendMessage($replyChatId,
                "Bu guruhda hozircha botning ichki moderator roliga ega a'zo yo'q.\n"
                . "Tayinlash uchun guruhda a'zoning xabariga reply qilib <code>/addmod</code> yozing (faqat adminlar).");
            return ['status' => 'command_executed', 'cmd' => '/modlist'];
        }

        $msg = "🛡 <b>Botning ichki moderatorlari (" . count($rows) . ")</b>\n"
            . "<i>(faqat /warn, /mute, /unmute, /warnings buyruqlariga ruxsat bor)</i>\n\n";
        foreach ($rows as $row) {
            $uid = (int)$row['user_id'];
            $msg .= "• <a href=\"tg://user?id={$uid}\">{$uid}</a>\n";
        }
        $msg .= "\n<i>Bekor qilish: guruhda o'sha a'zoning xabariga reply qilib /removemod</i>";
        $this->telegram->sendMessage($replyChatId, $msg);
        return ['status' => 'command_executed', 'cmd' => '/modlist'];
    }

    /**
     * /til — guruh a'zolariga ko'rinadigan xabarlar (CAPTCHA, ogohlantirish/mute/ban)
     * qaysi tilda yuborilishini ko'rsatish (argumentsiz) yoki o'zgartirish
     * (`/til uz|ru|en`). Admin panel/DM buyruqlarining o'zi bu bosqichda hali
     * faqat o'zbek tilida qoladi (2.0 Phase 3, 1-band — i18n, bosqichma-bosqich).
     */
    private function handleLanguageCommand(int $chatId, string $arg, int $replyChatId): array
    {
        $arg = strtolower(trim($arg));
        $current = Translator::normalizeLang(SettingsService::get($chatId)['language'] ?? null);
        $optionsList = "<code>uz</code> — o'zbekcha\n<code>ru</code> — русский\n<code>en</code> — English";

        if ($arg === '') {
            $this->telegram->sendMessage($replyChatId,
                "🌐 Joriy til: <code>{$current}</code>\n\n"
                . "Guruh a'zolariga ko'rinadigan xabarlar (yangi a'zo CAPTCHA'si, ogohlantirish/mute/ban) shu tilda yuboriladi. "
                . "Admin panel va buyruqlarning o'zi hozircha o'zbek tilida qoladi.\n\n"
                . "O'zgartirish uchun: <code>/til uz</code>, <code>/til ru</code> yoki <code>/til en</code>\n\n"
                . "Mavjud tillar:\n{$optionsList}");
            return ['status' => 'command_executed', 'cmd' => '/til'];
        }

        if (!in_array($arg, Translator::SUPPORTED, true)) {
            $this->telegram->sendMessage($replyChatId,
                "❌ Noma'lum til kodi: <code>{$arg}</code>\n\nMavjud tillar:\n{$optionsList}");
            return ['status' => 'invalid_language', 'cmd' => '/til'];
        }

        SettingsService::update($chatId, ['language' => $arg]);
        $this->telegram->sendMessage($replyChatId,
            "✅ Til <code>{$arg}</code>ga o'zgartirildi. Endi guruh a'zolariga yuboriladigan yangi xabarlar (CAPTCHA, ogohlantirish/mute/ban) shu tilda bo'ladi.");
        return ['status' => 'command_executed', 'cmd' => '/til', 'language' => $arg];
    }

    /**
     * /exportsettings — guruhning joriy (klonlanishi mumkin bo'lgan)
     * sozlamalarini JSON ko'rinishida ko'rsatadi — boshqa guruhga
     * `/importsettings` orqali qo'lda ko'chirish yoki zaxira sifatida
     * saqlash uchun (2.0 Phase 4, 2-band).
     */
    private function handleExportSettingsCommand(int $chatId, int $replyChatId): array
    {
        $exported = SettingsService::exportSettings($chatId);
        $json = json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $title = $this->resolveGroupTitle($chatId, '');

        $this->telegram->sendMessage($replyChatId,
            "📤 <b>Sozlamalar eksporti</b> — {$title} (<code>{$chatId}</code>)\n\n"
            . "<pre>{$json}</pre>\n\n"
            . "Boshqa guruhga qo'llash uchun: <code>/importsettings maqsad_guruh_id &lt;yuqoridagi JSON&gt;</code>\n"
            . "Yoki ikkala guruhni ham o'zingiz boshqarsangiz: <code>/clonesettings {$chatId} maqsad_guruh_id</code>",
            ['disable_web_page_preview' => true]);
        return ['status' => 'command_executed', 'cmd' => '/exportsettings', 'chat_id' => $chatId, 'exported' => $exported];
    }

    /**
     * `/importsettings`ga berilgan xom (json_decode qilingan) massivni
     * SettingsService::CLONEABLE_COLUMNS'ga qarshi tekshiradi — har bir
     * qiymat turi/oralig'i/enum ro'yxati bo'yicha tasdiqlanadi, noto'g'ri
     * yoki noma'lum kalitlar jim tashlab ketiladi (import hech qachon
     * fatal xato bermaydi, faqat "qo'llangan"/"o'tkazib yuborilgan"
     * ro'yxatini qaytaradi) — 2.0 Phase 4, 2-band.
     *
     * @return array{applied: array<string,mixed>, skipped: array<int,string>}
     */
    private function sanitizeSettingsImport(array $raw): array
    {
        $boolFields = [
            'clean_service_messages', 'profanity_filter', 'porn_filter',
            'link_filter', 'media_filter', 'profile_scan', 'bot_filter',
            'history_cleanup_enabled', 'flood_enabled', 'captcha_enabled',
        ];
        $enumFields = [
            'ai_mode' => ['comprehensive', 'economical'],
            'unscannable_action' => ['leave_alert', 'delete_notify'],
            'porn_action' => ['ban', 'mute', 'warn'],
            'adult_account_action' => ['ban', 'notify', 'mute_notify'],
            'language' => Translator::SUPPORTED,
        ];
        $intRangeFields = [
            'warn_limit' => [1, 20],
            'warn_duration_days' => [1, 365],
            'mute_1st_duration_sec' => [60, 2592000],
            'mute_2nd_duration_sec' => [60, 2592000],
            'flood_max_messages' => [1, 100],
            'flood_window_sec' => [1, 3600],
            'flood_mute_duration_sec' => [30, 2592000],
            'captcha_timeout_sec' => [10, 3600],
        ];

        $applied = [];
        $skipped = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || !in_array($key, SettingsService::CLONEABLE_COLUMNS, true)) {
                $skipped[] = is_string($key) ? $key : (string)$key;
                continue;
            }

            if (in_array($key, $boolFields, true)) {
                if ($value === true || $value === 1 || $value === '1') {
                    $applied[$key] = 1;
                } elseif ($value === false || $value === 0 || $value === '0') {
                    $applied[$key] = 0;
                } else {
                    $skipped[] = $key;
                }
                continue;
            }

            if (isset($enumFields[$key])) {
                if (is_string($value) && in_array(strtolower($value), $enumFields[$key], true)) {
                    $applied[$key] = strtolower($value);
                } else {
                    $skipped[] = $key;
                }
                continue;
            }

            if (isset($intRangeFields[$key])) {
                [$min, $max] = $intRangeFields[$key];
                if (is_numeric($value) && (int)$value >= $min && (int)$value <= $max) {
                    $applied[$key] = (int)$value;
                } else {
                    $skipped[] = $key;
                }
                continue;
            }

            $skipped[] = $key;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * /importsettings — foydalanuvchi tomonidan qo'lda kiritilgan (yoki
     * `/exportsettings`dan nusxalangan) JSON'ni tekshirib, guruhga qo'llash
     * (2.0 Phase 4, 2-band).
     */
    private function handleImportSettingsCommand(int $chatId, string $jsonText, int $replyChatId): array
    {
        $jsonText = trim($jsonText);
        if ($jsonText === '') {
            $this->telegram->sendMessage($replyChatId,
                "Foydalanish: <code>/importsettings guruh_id {...JSON...}</code>\n\n"
                . "JSON'ni oldin <code>/exportsettings</code> orqali olishingiz mumkin.");
            return ['status' => 'error', 'reason' => 'empty_json'];
        }

        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $this->telegram->sendMessage($replyChatId, "❌ JSON noto'g'ri formatda. Iltimos, <code>/exportsettings</code> chiqargan matnni o'zgartirmasdan joylashtiring.");
            return ['status' => 'error', 'reason' => 'invalid_json'];
        }

        $result = $this->sanitizeSettingsImport($decoded);
        if ($result['applied'] === []) {
            $this->telegram->sendMessage($replyChatId, "❌ Hech qanday tanish/to'g'ri sozlama topilmadi — hech narsa o'zgartirilmadi.");
            return ['status' => 'error', 'reason' => 'nothing_applied'];
        }

        SettingsService::update($chatId, $result['applied']);
        $appliedList = implode(', ', array_map(static fn (string $k): string => "<code>{$k}</code>", array_keys($result['applied'])));
        $text = "✅ " . count($result['applied']) . " ta sozlama qo'llandi:\n{$appliedList}";
        if ($result['skipped'] !== []) {
            $skippedList = implode(', ', array_map(static fn (string $k): string => "<code>{$k}</code>", $result['skipped']));
            $text .= "\n\n⚠️ O'tkazib yuborildi (noma'lum yoki noto'g'ri qiymat): {$skippedList}";
        }
        $this->telegram->sendMessage($replyChatId, $text);
        return ['status' => 'command_executed', 'cmd' => '/importsettings', 'chat_id' => $chatId, 'applied' => $result['applied'], 'skipped' => $result['skipped']];
    }

    /**
     * /clonesettings manba_guruh_id maqsad_guruh_id — bitta guruhning
     * klonlanishi mumkin bo'lgan sozlamalarini boshqasiga to'g'ridan-to'g'ri
     * (JSON qo'lda kiritilmasdan) nusxalash. Xavfsizlik uchun chaqiruvchi
     * IKKALA guruhning ham (manba VA maqsad) admini bo'lishi shart — aks
     * holda o'zi boshqarmagan guruhning sozlamalarini o'qib/yozib bo'lardi
     * (2.0 Phase 4, 2-band).
     */
    private function handleCloneSettingsCommand(int $userId, int $sourceChatId, int $targetChatId, int $replyChatId): array
    {
        if ($sourceChatId === $targetChatId) {
            $this->telegram->sendMessage($replyChatId, "❌ Manba va maqsad guruh bir xil bo'lishi mumkin emas.");
            return ['status' => 'error', 'reason' => 'same_chat'];
        }
        if (!$this->auth->isAdmin($sourceChatId, $userId) || !$this->auth->isAdmin($targetChatId, $userId)) {
            $this->telegram->sendMessage($replyChatId, "❌ Bu amal uchun IKKALA guruhning ham (manba va maqsad) administratori bo'lishingiz kerak.");
            return ['status' => 'error', 'reason' => 'unauthorized'];
        }

        $applied = SettingsService::cloneInto($sourceChatId, $targetChatId);
        $sourceTitle = $this->resolveGroupTitle($sourceChatId, '');
        $targetTitle = $this->resolveGroupTitle($targetChatId, '');
        $this->telegram->sendMessage($replyChatId,
            "✅ Sozlamalar nusxalandi:\n"
            . "Manba: {$sourceTitle} (<code>{$sourceChatId}</code>)\n"
            . "Maqsad: {$targetTitle} (<code>{$targetChatId}</code>)\n\n"
            . count($applied) . " ta sozlama qo'llandi.");
        return ['status' => 'command_executed', 'cmd' => '/clonesettings', 'source_chat_id' => $sourceChatId, 'target_chat_id' => $targetChatId, 'applied' => $applied];
    }

    /**
     * /broadcast matn — chaqiruvchi admin BOSHQARGAN barcha (faol) guruhlarga
     * botning o'zi orqali bitta xabar yuborish (e'lon/ogohlantirish). Har bir
     * guruh uchun alohida `App\Jobs\BroadcastMessageJob` navbatga qo'yiladi
     * (bitta guruhga yetkazib bo'lmasa — masalan bot guruhdan chiqarilgan —
     * qolganlariga ta'sir qilmaydi) va Telegram'ning umumiy bot tezlik
     * chegarasidan (~30 xabar/soniya) saqlanish uchun har 20 ta guruhdan
     * keyin +1 soniya kechikish qo'shiladi (2.0 Phase 4, 3-band).
     */
    private function handleBroadcastCommand(int $userId, string $text, int $replyChatId): array
    {
        $text = trim($text);
        $groups = $this->adminGroupsOf($userId);

        if ($groups === []) {
            $this->telegram->sendMessage($replyChatId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
            return ['status' => 'error', 'reason' => 'no_groups'];
        }

        if ($text === '') {
            $this->telegram->sendMessage($replyChatId,
                "Foydalanish: <code>/broadcast xabar matni</code>\n\n"
                . "Xabar siz boshqargan barcha (<b>" . count($groups) . "</b> ta) guruhga botning o'zi orqali yuboriladi.\n\n"
                . "Guruhlaringiz:\n" . $this->managedGroupsHintText($groups));
            return ['status' => 'error', 'reason' => 'empty_text'];
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $fullText = "📢 <b>Administrator xabari</b>\n\n{$escaped}";

        $queued = 0;
        foreach ($groups as $g) {
            $targetChatId = (int)$g['chat_id'];
            QueueService::push('App\Jobs\BroadcastMessageJob', [
                'chat_id' => $targetChatId,
                'text' => $fullText,
                'admin_user_id' => $userId,
            ], QueueService::PRIORITY_NORMAL, intdiv($queued, 20));
            $queued++;
        }

        $this->telegram->sendMessage($replyChatId,
            "✅ Xabaringiz <b>{$queued}</b> ta guruhga yuborish uchun navbatga qo'yildi.\n\n"
            . "Yetkazilishi bir necha soniya ichida amalga oshadi (fon worker orqali). "
            . "Agar botni chiqarib yuborgan yoki admin huquqini olib qo'ygan guruhlaringiz bo'lsa, ularga yetmaydi — boshqalariga ta'sir qilmaydi.");
        return ['status' => 'command_executed', 'cmd' => '/broadcast', 'queued_groups' => $queued];
    }

    /**
     * Telegram Stars pre_checkout_query'siga javob berish. Telegram bu so'rovni
     * to'lov "Pay" tugmasi bosilgan zahoti yuboradi va 10 soniya ichida javob
     * kutadi — shuning uchun bu yerda hech qanday og'ir/tarmoq amali BO'LMASLIGI
     * kerak, faqat payload formatini tekshirish (2.0 Phase 3, 2-band).
     */
    private function handlePreCheckoutQuery(array $pcq): array
    {
        $id = (string)($pcq['id'] ?? '');
        $payload = (string)($pcq['invoice_payload'] ?? '');

        if (!preg_match('/^premium_v1:-?\d+:\d+$/', $payload)) {
            $this->telegram->answerPreCheckoutQuery($id, false, "Noto'g'ri buyurtma. Iltimos, /premium buyrug'ini qayta yuboring.");
            return ['status' => 'pre_checkout_rejected'];
        }

        $this->telegram->answerPreCheckoutQuery($id, true);
        return ['status' => 'pre_checkout_accepted'];
    }

    /**
     * Telegram Stars to'lovi muvaffaqiyatli yakunlangach (shaxsiy chatda oddiy
     * message sifatida keladi, `successful_payment` maydoni bilan). Payloaddan
     * maqsadli GURUH ID'sini ajratib oladi (invoys admin shaxsiy chatiga
     * yuborilgan bo'lsa-da, premium GURUHga taalluqli — 2.0 Phase 3, 2-band).
     */
    private function handleSuccessfulPayment(array $payment, int $userId): array
    {
        $payload = (string)($payment['invoice_payload'] ?? '');
        if (!preg_match('/^premium_v1:(-?\d+):(\d+)$/', $payload, $m)) {
            Logger::error("Noma'lum formatdagi successful_payment payload", ['payload' => $payload], 'billing');
            $this->telegram->sendMessage($userId, "❌ To'lov qabul qilindi, lekin buyurtma ma'lumotlarini aniqlab bo'lmadi. Iltimos, botga murojaat qiling.");
            return ['status' => 'payment_payload_invalid'];
        }

        $chatId = (int)$m[1];
        $days = (int)$m[2];
        $chargeId = (string)($payment['telegram_payment_charge_id'] ?? '');
        $starsAmount = (int)($payment['total_amount'] ?? 0);

        $result = SubscriptionService::recordStarPayment($chatId, $userId, $chargeId, $starsAmount, $days, $payload);

        if (!$result['recorded']) {
            $this->telegram->sendMessage($userId,
                "ℹ️ Bu to'lov allaqachon qayta ishlangan. Joriy premium muddati: <code>{$result['premium_expires_at']}</code>");
            return ['status' => 'payment_already_recorded', 'chat_id' => $chatId];
        }

        $groupLabel = $this->resolveGroupTitle($chatId, '');
        $this->telegram->sendMessage($userId,
            "✅ Rahmat! To'lov qabul qilindi.\n\n"
            . "Guruh: {$groupLabel}\n"
            . "Premium muddati: <code>{$result['premium_expires_at']}</code> (UTC) gacha uzaytirildi.\n\n"
            . "Endi bu guruhda AI tekshiruvlar kunlik chegarasiz ishlaydi.");
        return ['status' => 'payment_recorded', 'chat_id' => $chatId, 'premium_expires_at' => $result['premium_expires_at']];
    }

    /**
     * /premium buyrug'i — guruhning joriy tarif holatini (bepul/premium)
     * ko'rsatadi va sotib olish/uzaytirish tugmasini yuboradi
     * (2.0 Phase 3, 2-band — monetizatsiya).
     */
    private function handlePremiumStatusCommand(int $chatId, int $replyChatId): array
    {
        $plan = SubscriptionService::getPlan($chatId);
        $stars = Config::getInt('PREMIUM_STARS_PRICE', 200);
        $days = Config::getInt('PREMIUM_DURATION_DAYS', 30);
        $buttons = ['inline_keyboard' => [[
            ['text' => "⭐ Premium sotib olish ({$stars} Stars / {$days} kun)", 'callback_data' => "buy_premium:{$chatId}"],
        ]]];

        if ($plan['is_premium']) {
            $this->telegram->sendMessage($replyChatId,
                "⭐ Bu guruh hozir <b>Premium</b> tarifda.\n\n"
                . "Muddati: <code>{$plan['premium_expires_at']}</code> (UTC) gacha.\n"
                . "AI tekshiruvlar kunlik chegarasiz.\n\n"
                . "Muddatni uzaytirmoqchi bo'lsangiz, quyidagi tugmadan foydalaning — qolgan kunlar yo'qolmaydi.",
                ['reply_markup' => $buttons]);
            return ['status' => 'premium_status_shown', 'plan' => 'premium'];
        }

        $used = SubscriptionService::dailyAiRequestCount($chatId);
        $limit = Config::getInt('FREE_TIER_DAILY_AI_REQUESTS', 150);
        $limitText = $limit > 0 ? "{$used}/{$limit}" : "{$used} (chegarasiz)";
        $this->telegram->sendMessage($replyChatId,
            "🆓 Bu guruh hozir <b>Bepul</b> tarifda.\n\n"
            . "Bugungi AI so'rovlar: <code>{$limitText}</code>\n\n"
            . "Premium olsangiz — AI tekshiruvlar (matn/rasm/video/ovoz) kunlik chegarasiz bo'ladi. "
            . "Mahalliy qoidalar (so'z bloklash, havola filtri) chegaradan qat'i nazar doim ishlaydi.",
            ['reply_markup' => $buttons]);
        return ['status' => 'premium_status_shown', 'plan' => 'free'];
    }

    /**
     * "⭐ Premium sotib olish" tugmasi bosilganda Telegram Stars invoysini
     * yuboradi. Faqat shu guruh admini bosishi mumkin (chaqiruvchi
     * handleCallbackQuery'da allaqachon admin ekanligi tekshirilgan).
     */
    private function handleBuyPremiumCallback(int $chatId, int $userId, string $cbId): array
    {
        $stars = Config::getInt('PREMIUM_STARS_PRICE', 200);
        $days = Config::getInt('PREMIUM_DURATION_DAYS', 30);
        $payload = "premium_v1:{$chatId}:{$days}";

        $this->telegram->answerCallbackQuery($cbId);
        $this->telegram->sendInvoice(
            $userId,
            "Block-BOT Premium — {$days} kun",
            "Ushbu guruh uchun {$days} kunlik Premium obuna: AI tekshiruvlar (matn/rasm/video/ovoz) kunlik chegarasiz.",
            $payload,
            $stars,
            "Premium obuna ({$days} kun)"
        );
        return ['status' => 'invoice_sent', 'chat_id' => $chatId, 'stars' => $stars, 'days' => $days];
    }

    /**
     * Shaxsiy chatda /blockword, /allowword, /unblockword, /wordlist buyruqlari uchun
     * qaysi guruhga tegishli ekanini aniqlaydi. Admin faqat bitta guruhni boshqarsa —
     * avtomatik shu guruh tanlanadi; bir nechta bo'lsa, buyruq guruh ID bilan
     * boshlanishi kerak (masalan: "/blockword -1001234567890 so'z").
     *
     * @return array{chat_id:int, rest:string, error:?string, groups:array}
     */
    private function resolvePrivateManagedChat(int $userId, string $arg): array
    {
        $groups = $this->adminGroupsOf($userId);
        if ($groups === []) {
            return ['chat_id' => 0, 'rest' => '', 'error' => 'no_groups', 'groups' => []];
        }

        if (preg_match('/^(-?\d{6,})(?:\s+(.*))?$/s', $arg, $m)) {
            $chatId = (int)$m[1];
            $rest = trim((string)($m[2] ?? ''));
            $known = false;
            foreach ($groups as $g) {
                if ((int)$g['chat_id'] === $chatId) {
                    $known = true;
                    break;
                }
            }
            if (!$known || !$this->auth->isAdmin($chatId, $userId)) {
                return ['chat_id' => 0, 'rest' => '', 'error' => 'unauthorized', 'groups' => $groups];
            }
            return ['chat_id' => $chatId, 'rest' => $rest, 'error' => null, 'groups' => $groups];
        }

        if (count($groups) === 1) {
            return ['chat_id' => (int)$groups[0]['chat_id'], 'rest' => trim($arg), 'error' => null, 'groups' => $groups];
        }

        return ['chat_id' => 0, 'rest' => trim($arg), 'error' => 'ambiguous', 'groups' => $groups];
    }

    private function managedGroupsHintText(array $groups): string
    {
        $lines = [];
        foreach ($groups as $g) {
            $gId = (int)$g['chat_id'];
            $lines[] = "• " . $this->resolveGroupTitle($gId, (string)($g['title'] ?? '')) . " — <code>{$gId}</code>";
        }
        return implode("\n", $lines);
    }

    private function handleModerationCommand(string $cmd, array $message, array $parts, int $chatId): array
    {
        $targetUserId = $this->resolveTargetUserId($message, $parts);
        $adminUserId = (int)($message['from']['id'] ?? 0);

        if ($targetUserId <= 0) {
            $this->telegram->sendMessage($chatId, "Foydalanuvchini ko'rsatish uchun uning xabariga reply qiling yoki ID raqamini kiriting.", $this->threadExtra($message));
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

    /**
     * /addmod, /removemod — botning ichki, cheklangan huquqli "moderator" rolini
     * (faqat /warn, /mute, /unmute, /warnings buyruqlariga ruxsat beradi) xabarga
     * reply qilib belgilash/bekor qilish. Faqat to'liq adminlar chaqira oladi
     * (handleCommand() darajasida allaqachon tekshirilgan).
     */
    private function handleModeratorRoleCommand(string $cmd, array $message, array $parts, int $chatId): array
    {
        $targetUserId = $this->resolveTargetUserId($message, $parts);
        if ($targetUserId <= 0) {
            $this->telegram->sendMessage($chatId, "Foydalanuvchini ko'rsatish uchun uning xabariga reply qiling yoki ID raqamini kiriting.", $this->threadExtra($message));
            return ['status' => 'error', 'reason' => 'target_user_not_found'];
        }

        if ($this->auth->isAdmin($chatId, $targetUserId)) {
            $this->telegram->sendMessage($chatId, "ℹ️ Bu foydalanuvchi allaqachon guruh administratori — alohida moderator huquqi shart emas.", $this->threadExtra($message));
            return ['status' => 'already_admin', 'cmd' => $cmd, 'target_user_id' => $targetUserId];
        }

        $makeModerator = $cmd === '/addmod';
        AdminAuthorizationService::setModeratorRole($chatId, $targetUserId, $makeModerator);

        $this->telegram->sendMessage($chatId, $makeModerator
            ? "✅ <a href=\"tg://user?id={$targetUserId}\">Foydalanuvchi</a> endi moderator: /warn, /mute, /unmute, /warnings buyruqlaridan foydalana oladi (boshqa admin buyruqlari va /settings unga yopiq)."
            : "✅ <a href=\"tg://user?id={$targetUserId}\">Foydalanuvchi</a>ning moderator huquqi bekor qilindi.", $this->threadExtra($message));

        return ['status' => 'command_executed', 'cmd' => $cmd, 'target_user_id' => $targetUserId, 'is_moderator' => $makeModerator];
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

        if ($action === 'captcha_verify') {
            return $this->handleCaptchaVerify((int)($parts[1] ?? 0), (int)($parts[2] ?? 0), $userId, $cbId);
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

        // Telegram Stars orqali premium sotib olish (2.0 Phase 3, 2-band).
        // $chatId ustidagi admin tekshiruvi yuqorida (satr ~772) allaqachon o'tildi.
        if ($action === 'buy_premium') {
            return $this->handleBuyPremiumCallback($chatId, $userId, $cbId);
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

    /**
     * "✅ Men botman emas" tugmasi bosilganda chaqiriladi. Faqat tugmada
     * ko'rsatilgan (yangi qo'shilgan) foydalanuvchining o'zi bosishi mumkin.
     */
    private function handleCaptchaVerify(int $chatId, int $targetUserId, int $clickerId, string $cbId): array
    {
        $lang = Translator::normalizeLang(SettingsService::get($chatId)['language'] ?? null);

        if ($chatId === 0 || $targetUserId === 0) {
            $this->telegram->answerCallbackQuery($cbId, Translator::get('captcha.invalid_request', $lang), true);
            return ['status' => 'not_found'];
        }
        if ($clickerId !== $targetUserId) {
            $this->telegram->answerCallbackQuery($cbId, Translator::get('captcha.not_your_button', $lang), true);
            return ['status' => 'unauthorized'];
        }

        $result = CaptchaGuard::verify($chatId, $targetUserId);
        if ($result === null) {
            $this->telegram->answerCallbackQuery($cbId, Translator::get('captcha.expired', $lang), true);
            return ['status' => 'captcha_not_pending'];
        }

        $this->telegram->unmuteUser($chatId, $targetUserId);
        $this->telegram->answerCallbackQuery($cbId, Translator::get('captcha.verified_toast', $lang));

        $messageId = (int)$result['message_id'];
        if ($messageId > 0) {
            $userLink = "<a href=\"tg://user?id={$targetUserId}\">" . Translator::get('common.user', $lang) . "</a>";
            $this->telegram->request('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => Translator::get('captcha.verified_message', $lang, ['user_link' => $userLink]),
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        return ['status' => 'captcha_verified'];
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

        if (in_array($action, ['audit_pause', 'audit_resume', 'audit_cancel'], true)) {
            $session = AuditService::latestForChat($chatId, ['waiting_upload', 'running', 'paused']);
            if (!$session) {
                $this->telegram->sendMessage($targetChatId, "Bu guruh uchun faol audit sessiyasi topilmadi.");
                return ['status' => 'audit_not_found'];
            }
            $ok = match ($action) {
                'audit_pause' => AuditService::pause((int)$session['id']),
                'audit_resume' => AuditService::resume((int)$session['id']),
                'audit_cancel' => AuditService::cancel((int)$session['id']),
            };
            $labels = ['audit_pause' => "to'xtatildi", 'audit_resume' => 'davom ettirildi', 'audit_cancel' => 'bekor qilindi'];
            $this->telegram->sendMessage($targetChatId, $ok
                ? "✅ Audit #{$session['id']} {$labels[$action]}."
                : "❌ Audit holatini o'zgartirib bo'lmadi.");
            return ['status' => $ok ? 'audit_state_changed' : 'error', 'action' => $action];
        }

        if ($action === 'status') {
            $statusMsg = "⚙️ <b>Bot Tizim Holati</b>\n"
                . "• Webhook: Faol ✅\n"
                . "• Baza ulanishi: Barqaror ✅\n"
                . "• AI Rejimi: <b>" . SettingsService::get($chatId)['ai_mode'] . "</b>\n"
                . "• PHP versiyasi: " . PHP_VERSION . "\n"
                . "• Vaqt zonasi: " . Config::get('APP_TIMEZONE', 'Asia/Tashkent');
            $this->telegram->sendMessage($targetChatId, $statusMsg);
            return ['status' => 'private_admin_action', 'action' => $action];
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
                    ['text' => (($s['flood_enabled'] ?? 1) ? '✅' : '❌') . " Anti-flood (" . (int)($s['flood_max_messages'] ?? 6) . "/" . (int)($s['flood_window_sec'] ?? 10) . "s)", 'callback_data' => "set_flood_enabled:{$chatId}"],
                ],
                [
                    ['text' => (($s['captcha_enabled'] ?? 0) ? '✅' : '❌') . " Yangi a'zo CAPTCHA (" . (int)($s['captcha_timeout_sec'] ?? 60) . "s)", 'callback_data' => "set_captcha_enabled:{$chatId}"],
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
    /**
     * Mini App (Web Dashboard) manzili — 2.0 Phase 3, 3-band. Ixtiyoriy
     * `MINIAPP_URL` bilan aniq belgilanadi; bo'lmasa `TELEGRAM_WEBHOOK_URL`ning
     * domenidan avtomatik hosil qilinadi (`/miniapp/` yo'li, xuddi shu domenda
     * `public/miniapp/index.html` joylashgani uchun). Telegram `web_app`
     * tugmasi FAQAT https:// manzilni qabul qiladi — mos kelmasa (yoki hech
     * narsa sozlanmagan bo'lsa) tugma butunlay ko'rsatilmaydi.
     */
    private function miniAppUrl(): ?string
    {
        $explicit = trim((string)Config::get('MINIAPP_URL', ''));
        $url = $explicit;
        if ($url === '') {
            $webhookUrl = trim((string)Config::get('TELEGRAM_WEBHOOK_URL', ''));
            if ($webhookUrl === '') {
                return null;
            }
            $parts = parse_url($webhookUrl);
            if (!isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $url = "{$parts['scheme']}://{$parts['host']}{$port}/miniapp/";
        }
        return str_starts_with($url, 'https://') ? $url : null;
    }

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
        $miniAppUrl = $this->miniAppUrl();
        if ($miniAppUrl !== null) {
            $rows[] = [['text' => '📊 Dashboard', 'web_app' => ['url' => $miniAppUrl]]];
        }
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
            . "<b>Guruhда (faqat admin, faqat quyidagilar):</b>\n"
            . "Botni keraksiz xabar bilan to'ldirmaslik uchun guruhda FAQAT xabarga \"reply\" "
            . "qilib beriladigan to'g'ridan-to'g'ri moderatsiya buyruqlari ishlaydi:\n"
            . "/warn /mute [soat] /ban — xabarga reply qilib jazo berish\n"
            . "/unmute /unban /resetwarns /warnings — cheklovni yechish/ko'rish\n"
            . "/addmod /removemod — xabarga reply qilib botning ichki \"moderator\" rolini "
            . "berish/bekor qilish (faqat to'liq adminlar chaqira oladi)\n\n"
            . "<b>Botning ichki \"moderator\" roli (/addmod bilan berilgan a'zolar):</b>\n"
            . "Faqat /warn, /mute, /unmute, /warnings buyruqlaridan foydalana oladi — /ban, "
            . "/unban, /resetwarns, /settings va boshqa admin buyruqlari ularga yopiq.\n\n"
            . "<b>Shaxsiy chatда (barcha boshqa buyruqlar shu yerda):</b>\n"
            . "/menu — bosh menyu\n"
            . "/mygroups — guruhlaringiz ro'yxati va sozlamalari\n"
            . "/settings — moderatsiya sozlamalari menyusi (yoki /mygroups orqali)\n"
            . "/status — bot holati\n"
            . "/stats — 30 kunlik statistika\n"
            . "/ai_usage — AI xarajatlari va budjet sarfi\n"
            . "/scan_members — a'zolarni 18+ / bot akkauntlarga tekshirish\n"
            . "/audit [mtproto|json] — eski xabarlar tarixini tahlil qilish\n"
            . "/audit_status /audit_pause /audit_resume /audit_cancel /audit_report — audit boshqaruvi\n"
            . "/blockword, /allowword, /unblockword guruh_id so'z — jargon/lahjadagi maxsus so'zlarni boshqarish\n"
            . "/wordlist [guruh_id] — maxsus so'z qoidalari ro'yxati\n"
            . "/modlist [guruh_id] — botning ichki moderatorlari ro'yxati\n"
            . "/til [guruh_id] [uz|ru|en] — guruh a'zolariga ko'rinadigan xabarlar "
            . "(CAPTCHA, ogohlantirish/mute/ban) tilini ko'rish/o'zgartirish\n"
            . "/exportsettings [guruh_id] — guruh sozlamalarini JSON ko'rinishida olish\n"
            . "/importsettings guruh_id {JSON} — eksport qilingan JSON'ni guruhga qo'llash\n"
            . "/clonesettings manba_id maqsad_id — bir guruh sozlamalarini boshqasiga to'g'ridan-to'g'ri nusxalash "
            . "(ikkalasining ham admini bo'lishingiz shart)\n"
            . "/broadcast matn — boshqargan barcha guruhlaringizga botning o'zi orqali bitta e'lon/ogohlantirish yuborish\n"
            . "<i>(Bir nechta guruhni boshqarsangiz, guruh ID'ni buyruqdan oldin ko'rsating — /mygroups orqali ko'rish mumkin)</i>\n"
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
        // Yagona manba: App\Policy\AdminAuthorizationService::adminGroupsOfUser()
        // (Mini App REST API ham xuddi shu metoddan foydalanadi — 2.0 Phase 3, 3-band).
        return AdminAuthorizationService::adminGroupsOfUser($userId);
    }

    private function handlePrivateChat(array $message): array
    {
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $text = trim((string)($message['text'] ?? ''));

        // Telegram Stars to'lovi muvaffaqiyatli yakunlandi (2.0 Phase 3, 2-band).
        if (!empty($message['successful_payment'])) {
            return $this->handleSuccessfulPayment($message['successful_payment'], $userId);
        }

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

        if (preg_match('/^\/(ai_usage|stats|status|audit_status|audit_report|audit_pause|audit_resume|audit_cancel|audit|scan_members)(?:@\w+)?(?:\s+(json|mtproto))?$/i', $text, $match)) {
            $command = strtolower($match[1]);
            if ($command === 'audit') {
                $command = strtolower((string)($match[2] ?? 'json')) === 'mtproto' ? 'audit_mtproto' : 'audit_json';
            }
            return $this->sendPrivateCommandGroupPicker($userId, $command);
        }

        // /blockword, /allowword, /unblockword — guruh maxsus so'z qoidalarini shaxsiy chatdan boshqarish.
        if (preg_match('/^\/(blockword|allowword|unblockword)(?:@\w+)?(?:\s+(.*))?$/is', $text, $match)) {
            $cmd = '/' . strtolower($match[1]);
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[2] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous' || $resolved['rest'] === '') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz (yoki so'z ko'rsatilmadi). Foydalanish:\n"
                    . "<code>{$cmd} -100... so'z_yoki_ibora</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            $fakeParts = [$cmd, $resolved['rest']];
            return $this->handleWordRuleCommand($cmd, "{$cmd} {$resolved['rest']}", $fakeParts, $resolved['chat_id'], $userId);
        }

        // /wordlist — guruhning maxsus so'z qoidalari ro'yxatini shaxsiy chatda ko'rsatish.
        if (preg_match('/^\/wordlist(?:@\w+)?(?:\s+(.*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz. Foydalanish: <code>/wordlist -100...</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handleWordListCommand($resolved['chat_id'], $userId);
        }

        // /modlist — guruhning botga tayinlangan moderatorlari ro'yxatini shaxsiy chatda ko'rsatish.
        if (preg_match('/^\/modlist(?:@\w+)?(?:\s+(.*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz. Foydalanish: <code>/modlist -100...</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handleModListCommand($resolved['chat_id'], $userId);
        }

        // /til — guruh a'zolariga ko'rinadigan xabarlar (CAPTCHA, ogohlantirish/mute/ban)
        // qaysi tilda yuborilishini tanlash/ko'rish (2.0 Phase 3, 1-band — i18n).
        if (preg_match('/^\/til(?:@\w+)?(?:\s+(.*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz. Foydalanish: <code>/til -100... uz|ru|en</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handleLanguageCommand($resolved['chat_id'], $resolved['rest'], $userId);
        }

        // /premium — guruhning tarif holatini (bepul/premium) ko'rsatish va Telegram
        // Stars orqali sotib olish tugmasini yuborish (2.0 Phase 3, 2-band — monetizatsiya).
        if (preg_match('/^\/premium(?:@\w+)?(?:\s+(.*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz. Foydalanish: <code>/premium -100...</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handlePremiumStatusCommand($resolved['chat_id'], $userId);
        }

        // /exportsettings — guruhning joriy sozlamalarini JSON ko'rinishida ko'rsatish
        // (2.0 Phase 4, 2-band — sozlamalarni klonlash/eksport-import).
        if (preg_match('/^\/exportsettings(?:@\w+)?(?:\s+(.*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz. Foydalanish: <code>/exportsettings -100...</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handleExportSettingsCommand($resolved['chat_id'], $userId);
        }

        // /importsettings guruh_id {...JSON...} — /exportsettings'dan olingan (yoki
        // qo'lda tuzilgan) JSON'ni guruhga qo'llash (2.0 Phase 4, 2-band).
        if (preg_match('/^\/importsettings(?:@\w+)?(?:\s+([\s\S]*))?$/i', $text, $match)) {
            $resolved = $this->resolvePrivateManagedChat($userId, trim((string)($match[1] ?? '')));

            if ($resolved['error'] === 'no_groups') {
                $this->telegram->sendMessage($userId, "❌ Siz boshqaradigan faol guruh topilmadi. Botni guruhingizga admin qilib qo'shing.");
                return ['status' => 'managed_group_not_found'];
            }
            if ($resolved['error'] === 'unauthorized') {
                $this->telegram->sendMessage($userId, "❌ Bu guruh administratori emassiz yoki guruh ID noto'g'ri.");
                return ['status' => 'unauthorized'];
            }
            if ($resolved['error'] === 'ambiguous' || $resolved['rest'] === '') {
                $this->telegram->sendMessage($userId,
                    "Siz bir nechta guruhni boshqarasiz (yoki JSON ko'rsatilmadi). Foydalanish:\n"
                    . "<code>/importsettings -100... {...JSON...}</code>\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($resolved['groups']));
                return ['status' => 'private_group_selection_required'];
            }

            return $this->handleImportSettingsCommand($resolved['chat_id'], $resolved['rest'], $userId);
        }

        // /clonesettings manba_guruh_id maqsad_guruh_id — bitta guruh sozlamalarini
        // to'g'ridan-to'g'ri (JSON'siz) boshqa guruhga nusxalash. Ikkala ID ham
        // aniq ko'rsatilishi SHART (2.0 Phase 4, 2-band).
        if (preg_match('/^\/clonesettings(?:@\w+)?(?:\s+(.*))?$/is', $text, $match)) {
            $arg = trim((string)($match[1] ?? ''));
            if (!preg_match('/^(-?\d{6,})\s+(-?\d{6,})$/', $arg, $ids)) {
                $groups = $this->adminGroupsOf($userId);
                $this->telegram->sendMessage($userId,
                    "Foydalanish: <code>/clonesettings manba_guruh_id maqsad_guruh_id</code>\n\n"
                    . "Ikkalasining ham administratori bo'lishingiz shart.\n\n"
                    . "Guruhlaringiz:\n" . $this->managedGroupsHintText($groups));
                return ['status' => 'error', 'reason' => 'invalid_arguments'];
            }
            return $this->handleCloneSettingsCommand($userId, (int)$ids[1], (int)$ids[2], $userId);
        }

        // /broadcast matn — admin boshqargan barcha guruhlarga botning o'zi orqali
        // bitta xabar (e'lon/ogohlantirish) yuborish (2.0 Phase 4, 3-band).
        if (preg_match('/^\/broadcast(?:@\w+)?(?:\s+([\s\S]*))?$/i', $text, $match)) {
            return $this->handleBroadcastCommand($userId, trim((string)($match[1] ?? '')), $userId);
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
