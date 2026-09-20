<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Logger;
use App\Core\TelegramClient;

/**
 * Admin `/broadcast` buyrug'i orqali bitta xabarni o'zi boshqargan barcha
 * guruhlarga yuborish uchun QueueService'ga qo'yiladigan vazifa — har bir
 * guruh uchun ALOHIDA job (2.0 Phase 4, 3-band). Bu ikki sababga ko'ra:
 * (1) bitta guruhga yuborish muvaffaqiyatsiz bo'lsa (masalan bot guruhdan
 * chiqarilgan/admin huquqidan mahrum qilingan) qolganlariga ta'sir qilmaydi;
 * (2) `UpdateRouter::handleBroadcastCommand()` navbatga qo'yishda har 20 ta
 * guruhdan keyin +1 soniya kechikish qo'shadi — Telegram'ning umumiy bot
 * tezlik chegarasidan (~30 xabar/soniya) saqlanish uchun.
 */
class BroadcastMessageJob
{
    public function handle(array $data): void
    {
        $chatId = (int)($data['chat_id'] ?? 0);
        $text = (string)($data['text'] ?? '');
        $adminUserId = (int)($data['admin_user_id'] ?? 0);

        if ($chatId === 0 || trim($text) === '') {
            return;
        }

        $telegram = new TelegramClient();
        $result = $telegram->sendMessage($chatId, $text, [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (empty($result['ok'])) {
            $reason = (string)($result['description'] ?? "noma'lum xato");
            Logger::warning("Broadcast xabari guruhga yetkazilmadi", [
                'chat_id' => $chatId,
                'admin_user_id' => $adminUserId,
                'reason' => $reason,
            ], 'broadcast');
            return;
        }

        Logger::info("Broadcast xabari guruhga yuborildi", [
            'chat_id' => $chatId,
            'admin_user_id' => $adminUserId,
        ], 'broadcast');
    }
}
