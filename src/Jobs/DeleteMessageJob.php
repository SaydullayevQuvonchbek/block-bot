<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\TelegramClient;

class DeleteMessageJob
{
    public function handle(array $data): void
    {
        $chatId = $data['chat_id'] ?? 0;
        $messageId = (int)($data['message_id'] ?? 0);

        if (!empty($chatId) && $messageId > 0) {
            $telegram = new TelegramClient();
            $telegram->deleteMessage($chatId, $messageId);
        }
    }
}
