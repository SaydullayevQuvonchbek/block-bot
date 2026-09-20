<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Logger;
use App\Core\TelegramClient;
use App\Core\Translator;
use App\Moderation\CaptchaGuard;
use App\Policy\SettingsService;

/**
 * Yangi a'zo CAPTCHA muddatida ("✅ Men botman emas" tugmasi) tasdiqlamasa,
 * QueueService orqali kechiktirilgan holda ishga tushadi (delaySeconds =
 * captcha_timeout_sec) va foydalanuvchini guruhdan chetlatadi (kick — ban +
 * darhol unban, ya'ni keyinroq qayta qo'shilishi mumkin, bu doimiy ban emas).
 *
 * Agar foydalanuvchi bu orada allaqachon tugmani bosgan bo'lsa,
 * CaptchaGuard::resolveTimeout() null qaytaradi va bu job hech narsa qilmaydi.
 */
class CaptchaTimeoutJob
{
    public function handle(array $data): void
    {
        $chatId = (int)($data['chat_id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            return;
        }

        $result = CaptchaGuard::resolveTimeout($chatId, $userId);
        if ($result === null) {
            // Allaqachon tasdiqlangan (yoki yozuv topilmadi) — hech narsa qilinmaydi.
            return;
        }

        $telegram = new TelegramClient();
        // Kick: ban + darhol unban — foydalanuvchi guruhdan chiqariladi, lekin
        // doimiy taqiqlanmaydi (xohласа qayta qo'shilishi mumkin).
        $telegram->banChatMember($chatId, $userId);
        $telegram->unbanChatMember($chatId, $userId, true);

        $messageId = (int)$result['message_id'];
        if ($messageId > 0) {
            $lang = Translator::normalizeLang(SettingsService::get($chatId)['language'] ?? null);
            $userLink = "<a href=\"tg://user?id={$userId}\">" . Translator::get('common.user', $lang) . "</a>";
            $telegram->request('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => Translator::get('captcha.timeout_message', $lang, ['user_link' => $userLink]),
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        Logger::info("CAPTCHA muddati tugadi, foydalanuvchi chetlatildi", [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], 'moderation');
    }
}
