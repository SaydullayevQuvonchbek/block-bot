<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Moderation\ProfileModerator;
use App\Policy\ModerationDecisionService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;

class ScanProfileJob
{
    public function handle(array $data): void
    {
        $chatId = (int)($data['chat_id'] ?? 0);
        $user = $data['user'] ?? [];
        $userId = (int)($user['id'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            return;
        }

        $settings = SettingsService::get($chatId);
        $checkBot = (bool)($settings['bot_filter'] ?? true);

        $profileModerator = new ProfileModerator();
        $punishmentService = new PunishmentService();

        $finding = $profileModerator->inspectUser($user, $chatId, false, $checkBot);

        if ($finding['status'] !== 'unsafe') {
            return;
        }

        $category = (string)($finding['category'] ?? '');
        if ($category === 'bot_account' && !$checkBot) {
            return;
        }
        if (in_array($category, ['pornography', 'adult_profile'], true) && !($settings['porn_filter'] ?? true)) {
            return;
        }

        $decision = ModerationDecisionService::decide($finding, $chatId, $userId, null, $settings);
        $punishmentService->execute($chatId, $userId, null, $decision, null, null);
    }
}
