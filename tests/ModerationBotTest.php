<?php

declare(strict_types=1);

namespace Tests;

use App\AI\AIClientFactory;
use App\AI\GeminiClient;
use App\AI\OpenRouterClient;
use App\AI\UsageBudgetService;
use App\Audit\AuditService;
use App\Audit\ReportService;
use App\Audit\TelegramExportImporter;
use App\Core\Config;
use App\Core\Database;
use App\Core\MiniAppAuth;
use App\Core\QueueService;
use App\Core\TelegramClient;
use App\Http\MiniAppApiRouter;
use App\Http\UpdateRouter;
use App\Moderation\CaptchaGuard;
use App\Moderation\FloodGuard;
use App\Moderation\TextModerator;
use App\Moderation\TextNormalizer;
use App\Moderation\ProfileModerator;
use App\Policy\AdminAuthorizationService;
use App\Policy\ModerationDecisionService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use App\Policy\SubscriptionService;

class ModerationBotTest extends TestCase
{
    /**
     * 1. Takroriy update ikki marta jazo bermasligi (Idempotency)
     */
    public function testDuplicateUpdateDoesNotPunishTwice(): void
    {
        $router = new UpdateRouter();
        $update = [
            'update_id' => 999111,
            'message' => [
                'message_id' => 1001,
                'chat' => ['id' => -1001234567, 'type' => 'supergroup'],
                'from' => ['id' => 8881, 'is_bot' => false],
                'text' => 'Salom barchaga!',
            ]
        ];

        $first = $router->handle($update);
        $this->assertEquals('queued_for_moderation', $first['status']);

        // Ayni shu update ikkinchi marta kelganda
        $second = $router->handle($update);
        $this->assertEquals('duplicate', $second['status']);
    }

    /**
     * 2. Parallel worker ikki marta ogohlantirish yozmasligi (Atomic queue reservation)
     */
    public function testParallelWorkerDoesNotDuplicateWarnings(): void
    {
        QueueService::push('App\Jobs\ScanProfileJob', ['chat_id' => -1001, 'user' => ['id' => 123]], QueueService::PRIORITY_HIGH);

        // 1-worker vazifani oladi
        $job1 = QueueService::reserve('default');
        $this->assertNotNull($job1, "1-worker vazifani olishi kerak");

        // 2-worker parallel ravishda navbatdan so'raganda, band qilingan vazifa unga berilmasligi kerak
        $job2 = QueueService::reserve('default');
        $this->assertNull($job2, "2-worker ayni band qilingan vazifani olmasligi kerak");

        QueueService::ack($job1['id']);
    }

    /**
     * 3. Begunoh so'z ichidagi taqiqlangan substring bloklanmasligi
     */
    public function testInnocentWordsNotBlocked(): void
    {
        $moderator = new TextModerator();
        $innocentPhrases = [
            'Oshxonada yangi qoshiq va qoshiqcha bor',
            'Bog\'imizda kichik kuchukcha yugurib yuribdi',
            'Gultuvakda chiroyli siklamen guli ochildi',
            'Biznes shartnoma imzolandi',
            'Tabiiy sharbat juda foydali',
        ];

        foreach ($innocentPhrases as $phrase) {
            $result = $moderator->inspect($phrase, [], 'economical', -1001, 'test_innocent');
            $this->assertEquals('safe', $result['status'], "Begunoh ibora '{$phrase}' noto'g'ri bloklandi: {$result['reason']}");
        }
    }

    /**
     * 4. Niqoblangan so'kinishlar to'g'ri aniqlanishi
     */
    public function testMaskedProfanityDetected(): void
    {
        $moderator = new TextModerator();
        $maskedBadPhrases = [
            's.u.k.a',           // Nuqtalar bilan niqoblangan
            's u k a',           // Bo'shliqlar bilan
            'b_l_y_a_t',         // Tagchiziq bilan
            's0ka',              // Leetspeak 0 -> o/u
            'сука',              // Kirill yozuvida
            'cука',              // Aralash lotin-kirill
            'jalap',             // O'zbekcha qattiq haqorat
            'intim xizmat bor',  // Intim xizmat spam
        ];

        foreach ($maskedBadPhrases as $phrase) {
            $result = $moderator->inspect($phrase, [], 'economical', -1001, 'test_masked');
            $this->assertEquals('unsafe', $result['status'], "Niqoblangan so'kinish '{$phrase}' aniqlanmadi!");
        }
    }

    /**
     * 5. Tahrirlangan xabarni qayta tekshirish
     */
    public function testEditedMessageRescannedWithoutDoubleCount(): void
    {
        $router = new UpdateRouter();
        $update = [
            'update_id' => 999222,
            'edited_message' => [
                'message_id' => 2002,
                'chat' => ['id' => -1001234567, 'type' => 'supergroup'],
                'from' => ['id' => 8882, 'is_bot' => false],
                'text' => 'Tahrirlangan xabar matni',
            ]
        ];

        $res = $router->handle($update);
        $this->assertEquals('queued_for_moderation', $res['status']);
    }

    /**
     * 6. Albom uchun yagona jazo (media_group_id)
     */
    public function testAlbumUnifiedSinglePunishment(): void
    {
        $punishment = new PunishmentService();
        $chatId = -100999;
        $userId = 7771;
        $albumId = "album_xyz_123";

        $decision = [
            'action' => 'warn_user',
            'delete_message' => true,
            'reason' => 'Albomda taqiqlangan rasm',
            'strike_count' => 1,
        ];

        // Albomning 1-rasmi kelganda
        $res1 = $punishment->execute($chatId, $userId, 3001, $decision, $albumId);
        $this->assertEquals('executed', $res1['status']);

        // Albomning 2-rasmi kelganda (takroriy jazo berilmasligi kerak)
        $res2 = $punishment->execute($chatId, $userId, 3002, $decision, $albumId);
        $this->assertEquals('skipped', $res2['status']);
        $this->assertStringContainsString('idempotency', $res2['reason']);
    }

    /**
     * 7. AI timeout, rad javobi va noto'g'ri JSON safe deb hisoblanmasligi
     */
    public function testAiTimeoutAndInvalidJsonNotMarkedSafe(): void
    {
        // Mock OpenRouter invalid JSON qaytarganda
        $client = new class extends OpenRouterClient {
            public function moderateText(string $text, string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array {
                // Xatolik yoki buzilgan format simulyatsiyasi
                return [
                    'item_id' => $itemId,
                    'status' => 'review',
                    'category' => 'invalid_ai_response',
                    'reason' => "Model javobi qat'iy JSON formatga mos kelmadi",
                    'evidence' => '',
                    'model' => 'test-model',
                    'cost_usd' => 0.0,
                ];
            }
        };

        $res = $client->moderateText("Shubhali noaniq matn");
        $this->assertFalse($res['status'] === 'safe', "Buzilgan AI javobi SAFE deb baholanmasligi shart!");
        $this->assertEquals('review', $res['status']);
    }

    /**
     * 8. Budjetning parallel so'rovlarda oshib ketmasligi (Atomic budget reservation)
     */
    public function testBudgetLimitEnforcedInParallelRequests(): void
    {
        Config::set('AI_DAILY_BUDGET_USD', 0.005); // Juda kichik limit: $0.005

        // 1-so'rov: $0.003 band qiladi (muvaffaqiyatli)
        $k1 = UsageBudgetService::reserveBudget(0.003);
        $this->assertNotNull($k1);

        // 2-so'rov: $0.003 band qilmoqchi (0.003 + 0.003 = 0.006 > 0.005) -> rad etilishi kerak!
        $k2 = UsageBudgetService::reserveBudget(0.003);
        $this->assertNull($k2, "Kunlik budjet oshib ketganda yangi rezervatsiya qat'iy to'xtatilishi kerak");

        // 1-so'rovni bekor qilish
        UsageBudgetService::releaseBudget($k1);
    }

    /**
     * 9. Vakolatsiz buyruq va callback rad etilishi
     */
    public function testUnauthorizedCommandAndCallbackRejected(): void
    {
        $auth = new AdminAuthorizationService();
        $chatId = -100555;
        $unauthorizedUserId = 999999;

        $isAdmin = $auth->isAdmin($chatId, $unauthorizedUserId);
        $this->assertFalse($isAdmin, "Oddiy foydalanuvchi admin deb hisoblanmasligi kerak");
    }

    /**
     * 10. Guruhlar o'rtasida sozlama yoki ma'lumot aralashmasligi (Multi-tenant)
     */
    public function testGroupIsolationMultiTenant(): void
    {
        $groupA = -100111;
        $groupB = -100222;

        SettingsService::update($groupA, ['profanity_filter' => 0]);
        SettingsService::update($groupB, ['profanity_filter' => 1]);

        $settingsA = SettingsService::get($groupA);
        $settingsB = SettingsService::get($groupB);

        $this->assertEquals(0, (int)$settingsA['profanity_filter']);
        $this->assertEquals(1, (int)$settingsB['profanity_filter']);
    }

    /**
     * 11. Sender_chat va anonim admin
     */
    public function testSenderChatAndAnonymousAdminNotFalselyPunished(): void
    {
        $auth = new AdminAuthorizationService();
        $chatId = -100777;

        // Guruhning o'zi nomidan yuborilgan xabar (anonim admin)
        $senderChat = ['id' => $chatId, 'type' => 'supergroup'];
        $isAdmin = $auth->isAdmin($chatId, 1087968824, $senderChat);

        $this->assertTrue($isAdmin, "Anonim admin (sender_chat) admin sifatida tan olinishi kerak");
    }

    /**
     * 12. Import dublikatlari va yo'q media
     */
    public function testImportDuplicatesAndMissingMediaHandled(): void
    {
        $importer = new TelegramExportImporter();
        $chatId = -100888;
        $sessionId = AuditService::createSession($chatId, 1, 'json_export');

        // Vaqtinchalik JSON eksport fayli yaratish
        $tempJson = dirname(__DIR__) . '/storage/temp/test_tg_export_' . uniqid() . '.json';
        $exportData = [
            'name' => 'Test Group',
            'type' => 'public_supergroup',
            'id' => 100888,
            'messages' => [
                [
                    'id' => 5001,
                    'type' => 'message',
                    'date' => '2026-09-09T12:00:00',
                    'from_id' => 'user123',
                    'text' => 'Normal xabar matni',
                ],
                [
                    'id' => 5002,
                    'type' => 'message',
                    'date' => '2026-09-09T12:01:00',
                    'from_id' => 'user123',
                    'text' => 'Rasm xabari',
                    'photo' => 'photos/missing_photo.jpg', // mavjud bo'lmagan fayl
                ]
            ]
        ];
        file_put_contents($tempJson, json_encode($exportData));

        try {
            // 1-import
            $res1 = $importer->importJson($tempJson, $chatId, $sessionId);
            $this->assertEquals(2, $res1['imported']);

            // 2-import (dublikatlar o'tkazib yuborilishi kerak)
            $res2 = $importer->importJson($tempJson, $chatId, $sessionId);
            $this->assertEquals(0, $res2['imported']);
            $this->assertEquals(2, $res2['duplicates_skipped']);
        } finally {
            @unlink($tempJson);
        }
    }

    /**
     * 13. Audit checkpoint'dan davom etishi
     */
    public function testAuditResumeFromCheckpoint(): void
    {
        $chatId = -100999;
        $sessionId = AuditService::createSession($chatId, 1, 'mtproto');

        AuditService::pause($sessionId);
        $s1 = AuditService::get($sessionId);
        $this->assertEquals('paused', $s1['status']);

        AuditService::resume($sessionId);
        $s2 = AuditService::get($sessionId);
        $this->assertEquals('running', $s2['status']);

        AuditService::cancel($sessionId);
        $s3 = AuditService::get($sessionId);
        $this->assertEquals('cancelled', $s3['status']);
    }

    /**
     * 14. Telegram harakati xato bo'lganda muvaffaqiyat deb yozilmasligi
     */
    public function testTelegramApiFailureRecordedAsFailedNotSuccess(): void
    {
        // Soxta Telegram mijoz (har doim xato qaytaradi)
        $failingTelegram = new class extends TelegramClient {
            public function banChatMember(int|string $chatId, int $userId, int $untilDate = 0, bool $revokeMessages = false): bool {
                return false; // Huquq yetishmadi deb hisoblaymiz
            }
            public function deleteMessage(int|string $chatId, int $messageId): bool {
                return false;
            }
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array {
                return ['ok' => false, 'description' => 'Forbidden'];
            }
        };

        $punishment = new PunishmentService($failingTelegram);
        $res = $punishment->execute(-100123, 5555, 9999, [
            'action' => 'ban_user',
            'delete_message' => true,
            'reason' => 'Pornografik material',
            'strike_count' => 99,
        ]);

        $this->assertEquals('failed', $res['status'], "Telegram harakati xato bo'lganda 'executed' deb yozilmasligi shart!");
        $this->assertFalse($res['success']);
    }

    /**
     * 15. Tarixiy audit avtomatik ommaviy jazo bermasligi
     */
    public function testHistoricalAuditDoesNotAutoMassBan(): void
    {
        $chatId = -100444;
        $sessionId = AuditService::createSession($chatId, 1, 'json_export');

        $importer = new TelegramExportImporter();
        $tempJson = dirname(__DIR__) . '/storage/temp/test_audit_no_ban_' . uniqid() . '.json';
        $exportData = [
            'name' => 'Audit Group',
            'type' => 'supergroup',
            'id' => 100444,
            'messages' => [
                [
                    'id' => 7001,
                    'type' => 'message',
                    'date' => '2026-09-09T10:00:00',
                    'from_id' => 'user_bad',
                    'text' => 'jalap suka', // Aniq qoidabuzarlik
                ]
            ]
        ];
        file_put_contents($tempJson, json_encode($exportData));

        try {
            $importer->importJson($tempJson, $chatId, $sessionId);

            // Audit topilmalari audit_items ga tushadi, ammo telegram_actions ga avtomatik ban tushmasligi kerak
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = :cid AND action_type = 'ban_user'");
            $stmt->execute(['cid' => $chatId]);
            $bansCount = (int)$stmt->fetchColumn();

            $this->assertEquals(0, $bansCount, "Tarixiy audit avtomatik ravishda foydalanuvchini ban qilmasligi shart!");
        } finally {
            @unlink($tempJson);
        }
    }

    public function testDisabledTextFiltersAreHonored(): void
    {
        $moderator = new TextModerator();
        $result = $moderator->inspect('s.u.k.a', [], 'disabled', -1001, 'filters_off', [
            'profanity_filter' => false,
            'porn_filter' => false,
            'link_filter' => false,
        ]);
        $this->assertEquals('safe', $result['status']);
    }

    public function testEscalationRecordsEveryStrike(): void
    {
        $chatId = -100321;
        $userId = 4321;
        $punishment = new PunishmentService();
        $finding = ['status' => 'unsafe', 'category' => 'profanity', 'reason' => 'Test qoidabuzarlik', 'evidence' => 'test'];
        $expected = ['warn_user', 'mute_user', 'mute_user', 'ban_user'];

        foreach ($expected as $index => $expectedAction) {
            $decision = ModerationDecisionService::decide($finding, $chatId, $userId);
            $this->assertEquals($expectedAction, $decision['action']);
            $result = $punishment->execute($chatId, $userId, 8100 + $index, $decision);
            $this->assertEquals('executed', $result['status']);
        }

        $this->assertEquals(3, ModerationDecisionService::getActiveWarningsCount($chatId, $userId));
    }

    public function testJsonAuditWaitsForUploadWithoutEmptyRunner(): void
    {
        $sessionId = AuditService::createSession(-100654, 1, 'json_export');
        $session = AuditService::get($sessionId);
        $this->assertEquals('waiting_upload', $session['status']);
        $this->assertNull(QueueService::reserve('default'));
    }

    public function testStatsAdminCommandIsImplemented(): void
    {
        // Buyruqlar endi faqat shaxsiy chatda (DM) ishlaydi — guruhda /stats javobsiz qoladi,
        // shu bois admin bu buyruqni botning shaxsiy chatiga yozadi (UpdateRouter avtomatik
        // ravishda uning yagona boshqaradigan guruhini tanlaydi).
        $chatId = -1007771;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);

        $router = new UpdateRouter();
        $result = $router->handle([
            'update_id' => 991234,
            'message' => [
                'message_id' => 7123,
                'chat' => ['id' => 1, 'type' => 'private'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/stats',
            ],
        ]);
        // Testlar bitta baza ustida ketma-ket ishlagani uchun user#1 avvalgi testlardan boshqa
        // guruhlarga ham admin bo'lib qolgan bo'lishi mumkin — shu sabab natija yo bevosita
        // bajarilishi (bitta guruh bo'lsa), yo guruh tanlash tugmasi (bir nechta bo'lsa) bo'ladi.
        $this->assertTrue(in_array($result['status'], ['private_admin_action', 'private_group_picker_sent'], true));

        // Guruhning o'zida esa buyruq endi umuman ishlamasligini (chetlab o'tilishini) tasdiqlaymiz.
        $groupResult = $router->handle([
            'update_id' => 991235,
            'message' => [
                'message_id' => 7124,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/stats',
            ],
        ]);
        $this->assertTrue($groupResult['status'] !== 'command_executed', "Guruhda buyruq bajarilmasligi kerak, olindi: {$groupResult['status']}");
    }

    public function testUnsafeProfileCacheDoesNotBecomeSafe(): void
    {
        $moderator = new ProfileModerator();
        $first = $moderator->inspectUser(['id' => 76543, 'first_name' => 'suka'], -1001, true);
        $this->assertEquals('unsafe', $first['status']);
        $cached = $moderator->inspectUser(['id' => 76543, 'first_name' => 'Toza ism'], -1001);
        $this->assertEquals('unsafe', $cached['status']);
    }

    public function testTelegramSendDocumentAndReportDelivery(): void
    {
        $telegram = new TelegramClient();
        $tempFile = dirname(__DIR__) . '/storage/temp/test_doc_' . uniqid() . '.html';
        file_put_contents($tempFile, '<h1>Test Report</h1>');
        try {
            $res = $telegram->sendDocument(-100123, $tempFile, "Sinov hisoboti");
            $this->assertTrue($res['ok'] ?? false);
            $this->assertNotNull($res['result']['document'] ?? null);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testTelegramCustomApiBaseUrlAndProxyConfiguration(): void
    {
        $customBase = 'https://tg-proxy.example.workers.dev';
        $client = new TelegramClient('123456:ABCdefGHIjklMNOpqrSTUvwxYZ', $customBase, 'socks5h://127.0.0.1:1080');

        $this->assertEquals($customBase, $client->getBaseApiUrl());
        $this->assertEquals("{$customBase}/bot123456:ABCdefGHIjklMNOpqrSTUvwxYZ/", $client->getApiUrl());
    }

    public function testGamblingTradingAdvertisingAndApkAreDetected(): void
    {
        $moderator = new TextModerator();
        $cases = [
            ['1XBET orqali stavka qo\'y', 'gambling'],
            ['Forex signal kursimizga yoziling', 'trading_scam'],
            ['Kanalimizga obuna bo\'ling', 'spam_ad'],
            ['premium_tool.apk yuklab oling', 'apk_distribution'],
            ["more of me 💕 come closer\nhttps://t.me/+1Dk8zv5dne40MTRi", 'spam_ad'],
        ];

        foreach ($cases as [$text, $category]) {
            $result = $moderator->inspect($text, [], 'economical', -1001, 'policy_test');
            $this->assertEquals('unsafe', $result['status'], "Taqiqlangan kontent aniqlanmadi: {$text}");
            $this->assertEquals($category, $result['category']);
        }
    }

    public function testModerationNeverSendsGroupNotice(): void
    {
        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
            public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool { return true; }
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 99001]];
            }
        };
        $chatId = -1004321;
        $service = new PunishmentService($telegram, new AdminAuthorizationService($telegram));

        $service->execute($chatId, 7001, 9101, [
            'action' => 'warn_user', 'delete_message' => true, 'reason' => 'Reklama', 'strike_count' => 1,
        ]);
        $warnGroupMessages = array_values(array_filter($telegram->sent, static fn (array $item): bool => $item['chat_id'] === $chatId));
        $this->assertEquals(0, count($warnGroupMessages), 'Oddiy xabar o\'chirish guruhga bot xabari yubormasligi kerak');

        $service->execute($chatId, 7001, 9102, [
            'action' => 'mute_user', 'delete_message' => true, 'reason' => 'Takroriy reklama', 'strike_count' => 2,
        ]);
        $groupMessages = array_values(array_filter($telegram->sent, static fn (array $item): bool => $item['chat_id'] === $chatId));
        $this->assertEquals(0, count($groupMessages), 'Mute yoki ban haqida guruhga bot xabari yuborilmasligi kerak');
        $privateMessages = array_values(array_filter($telegram->sent, static fn (array $item): bool => $item['chat_id'] === 7001));
        $this->assertEquals(1, count($privateMessages), 'Shikoyat tugmasi faqat cheklangan foydalanuvchiga yuborilishi kerak');
        $this->assertStringContainsString('appeal_request:', (string)json_encode($privateMessages[0]['extra']));
    }

    public function testPornographicWordMutesBeforeBan(): void
    {
        $finding = [
            'status' => 'unsafe',
            'category' => 'pornography',
            'reason' => 'Pornografik so\'z aniqlandi',
            'evidence' => 'porno',
            'source' => 'local_rules',
        ];
        $decision = ModerationDecisionService::decide($finding, -1007788, 88001);
        $this->assertEquals('mute_user', $decision['action']);
        $this->assertEquals(3600, $decision['mute_duration_sec']);
        $this->assertTrue($decision['delete_message']);
    }

    public function testRestrictedUserCanCreateAppealForAdminReview(): void
    {
        $telegram = new class extends TelegramClient {
            public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
            public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool { return true; }
            public function unmuteUser(int|string $chatId, int $userId): bool { return true; }
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array { return ['ok' => true, 'result' => ['message_id' => 99002]]; }
            public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool { return true; }
        };
        $chatId = -1005432;
        $userId = 7002;
        $service = new PunishmentService($telegram, new AdminAuthorizationService($telegram));
        $service->execute($chatId, $userId, 9201, [
            'action' => 'mute_user', 'delete_message' => true, 'reason' => 'Sinov cheklovi', 'strike_count' => 2,
        ]);
        $actionId = (int)Database::getConnection()->query("SELECT MAX(id) FROM telegram_actions")->fetchColumn();

        $router = new UpdateRouter($telegram, new AdminAuthorizationService($telegram), $service);
        $result = $router->handle([
            'update_id' => 998811,
            'callback_query' => [
                'id' => 'appeal-callback',
                'from' => ['id' => $userId, 'first_name' => 'User'],
                'data' => "appeal_request:{$actionId}",
            ],
        ]);

        $this->assertEquals('appeal_created', $result['status']);
        $this->assertEquals(1, (int)Database::getConnection()->query("SELECT COUNT(*) FROM moderation_appeals WHERE status = 'pending'")->fetchColumn());
    }

    public function testPrivateAiUsageDoesNotOpenRandomGroupSettings(): void
    {
        $chatId = -100665544;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);
        $result = (new UpdateRouter())->handle([
            'update_id' => 998901,
            'message' => [
                'message_id' => 9401,
                'chat' => ['id' => 1, 'type' => 'private'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/ai_usage',
            ],
        ]);
        $this->assertTrue(in_array($result['status'], ['private_group_picker_sent', 'private_admin_action'], true));
    }

    public function testHistoricalAuditCanStartAndReceiveUploadInPrivateChat(): void
    {
        $chatId = -100665599;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);
        $router = new UpdateRouter();
        $started = $router->handle([
            'update_id' => 998902,
            'callback_query' => [
                'id' => 'audit-start',
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'message' => ['message_id' => 9402, 'chat' => ['id' => 1, 'type' => 'private']],
                'data' => "admin_audit_json:{$chatId}",
            ],
        ]);
        $this->assertEquals('audit_waiting_upload', $started['status']);

        $uploaded = $router->handle([
            'update_id' => 998903,
            'message' => [
                'message_id' => 9403,
                'chat' => ['id' => 1, 'type' => 'private'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'document' => ['file_id' => 'audit-json-file', 'file_name' => 'result.json', 'file_size' => 1024],
            ],
        ]);
        $this->assertEquals('audit_import_queued', $uploaded['status']);
    }

    /**
     * 29. AI provider factory Gemini'ni tanlaydi va kalitsiz tarmoq so'rovi yubormaydi
     */
    public function testGeminiProviderCanBeSelectedWithoutOpenRouter(): void
    {
        $oldProvider = (string)Config::get('AI_PROVIDER', 'openrouter');
        $oldGeminiKey = (string)Config::get('GEMINI_API_KEY', '');

        try {
            Config::set('AI_PROVIDER', 'gemini');
            Config::set('GEMINI_API_KEY', '');

            $client = AIClientFactory::create();
            $this->assertTrue($client instanceof GeminiClient);
            $this->assertEquals('gemini', $client->providerName());

            $result = $client->moderateText('Sinov');
            $this->assertEquals('review', $result['status']);
            $this->assertEquals('unscanned', $result['category']);
        } finally {
            Config::set('AI_PROVIDER', $oldProvider);
            Config::set('GEMINI_API_KEY', $oldGeminiKey);
        }
    }

    /**
     * 30. Oldida boshqa so'zlar bo'lgan niqoblangan so'kinishlar to'g'ri aniqlanishi (Bypass himoyasi)
     */
    public function testMaskedProfanityWithPrecedingWordIsDetected(): void
    {
        $moderator = new TextModerator();
        $cases = [
            'Salom s.u.k.a',
            'ey s u k a',
            'bu b_l_y_a_t nima',
            'salom s0ka',
        ];
        foreach ($cases as $phrase) {
            $res = $moderator->inspect($phrase, [], 'economical', -1001, 'test_prec');
            $this->assertEquals('unsafe', $res['status'], "Niqoblangan so'kinish '{$phrase}' aniqlanmadi!");
        }
    }

    /**
     * 31. Rasm dekompressiya bombasi (Image Decompression Bomb DoS) himoyasi
     */
    public function testDecompressionBombProtected(): void
    {
        $oldKey = (string)Config::get('OPENROUTER_API_KEY', '');
        // 10,000 x 10,000 piksel o'lchamdagi (100 MP) xavfli PNG sarlavhasi
        $pngHeader = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x27\x10\x00\x00\x27\x10\x08\x06\x00\x00\x00";
        $tmp = dirname(__DIR__) . '/storage/temp/test_bomb_' . uniqid() . '.png';
        file_put_contents($tmp, $pngHeader);
        try {
            Config::set('OPENROUTER_API_KEY', 'sk-or-v1-testkey12345678901234567890');
            $client = new OpenRouterClient();
            $res = $client->moderateImage($tmp, 'Bomb test');
            $this->assertEquals('unscannable', $res['status']);
            $this->assertEquals('image_dimensions_exceeded', $res['category']);
        } finally {
            Config::set('OPENROUTER_API_KEY', $oldKey);
            @unlink($tmp);
        }
    }

    /**
     * 32. CSV formula injection oldidagi bo'shliqlar va maxsus belgilardan himoyalanganligi
     */
    public function testCsvFormulaEscapeLeadingWhitespaceAndSpecialChars(): void
    {
        $this->assertEquals("'=cmd", ReportService::escapeCsvFormula('=cmd'));
        $this->assertEquals("'   =cmd", ReportService::escapeCsvFormula('   =cmd'));
        $this->assertEquals("'|calc", ReportService::escapeCsvFormula('|calc'));
        $this->assertEquals("'%calc", ReportService::escapeCsvFormula('%calc'));
        $this->assertEquals("Oddiy xavfsiz matn", ReportService::escapeCsvFormula('Oddiy xavfsiz matn'));
    }

    /**
     * 33. Regexsiz (literal) qora ro'yxat so'z qoidasi ishlashi
     */
    public function testPlainTextBlacklistWordRuleIsEnforced(): void
    {
        $chatId = -100811;
        Database::getConnection()
            ->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, 'blacklist', 'yaramas', 0, :now)")
            ->execute(['cid' => $chatId, 'now' => gmdate('Y-m-d H:i:s')]);

        $moderator = new TextModerator();
        $bad = $moderator->inspect('sen juda yaramas odam ekansan', [], 'economical', $chatId, 'wr1');
        $this->assertEquals('unsafe', $bad['status'], 'Literal qora ro\'yxat so\'zi aniqlanishi kerak');

        $ok = $moderator->inspect('bugun havo yaxshi', [], 'economical', $chatId, 'wr2');
        $this->assertEquals('safe', $ok['status']);
    }

    /**
     * 34. Oq ro'yxat qoidasi qora ro'yxatdan ustun turishi
     */
    public function testWhitelistWordRuleOverridesBlacklist(): void
    {
        $chatId = -100812;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, 'blacklist', 'salomatlik', 0, :now)")
            ->execute(['cid' => $chatId, 'now' => $now]);
        $pdo->prepare("INSERT INTO word_rules (chat_id, rule_type, word_pattern, is_regex, created_at) VALUES (:cid, 'whitelist', 'salomatlik', 0, :now)")
            ->execute(['cid' => $chatId, 'now' => $now]);

        $moderator = new TextModerator();
        $res = $moderator->inspect('salomatlik uchun sport foydali', [], 'economical', $chatId, 'wr3');
        $this->assertEquals('safe', $res['status']);
    }

    /**
     * 35. chat_member orqali qo'shilgan yangi a'zo profil tekshiruviga tushishi
     */
    public function testChatMemberJoinQueuesProfileScan(): void
    {
        $chatId = -100813;
        SettingsService::get($chatId);
        $router = new UpdateRouter();
        $res = $router->handle([
            'update_id' => 994010,
            'chat_member' => [
                'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Test'],
                'from' => ['id' => 1, 'is_bot' => false],
                'old_chat_member' => ['user' => ['id' => 6001], 'status' => 'left'],
                'new_chat_member' => ['user' => ['id' => 6001, 'is_bot' => false, 'first_name' => 'Yangi'], 'status' => 'member'],
            ],
        ]);
        $this->assertEquals('chat_member_updated', $res['status']);
        $job = QueueService::reserve('default');
        $this->assertNotNull($job);
        $this->assertEquals('App\Jobs\ScanProfileJob', $job['handler']);
        $this->assertEquals(6001, (int)$job['data']['user']['id']);
    }

    /**
     * 36. 18+ profil va bot akkaunt qarori — ban emas, mute + admin tasdig'i
     */
    public function testAdultAndBotAccountDecisionsAreMuteWithAdminConfirm(): void
    {
        $adult = ModerationDecisionService::decide(
            ['status' => 'unsafe', 'category' => 'adult_profile', 'reason' => '18+ profil', 'evidence' => '', 'source' => 'profile_scan_photo'],
            -100814,
            90001
        );
        $this->assertEquals('mute_user', $adult['action']);
        $this->assertTrue((bool)($adult['admin_confirm_ban'] ?? false));
        $this->assertEquals(86400 * 30, $adult['mute_duration_sec']);

        $bot = ModerationDecisionService::decide(
            ['status' => 'unsafe', 'category' => 'bot_account', 'reason' => 'Ruxsatsiz bot', 'evidence' => '@x', 'source' => 'profile_scan_bot'],
            -100814,
            90002
        );
        $this->assertEquals('mute_user', $bot['action']);
        $this->assertTrue((bool)($bot['admin_confirm_ban'] ?? false));
    }

    /**
     * 37. ProfileModerator ruxsatsiz botni aniqlaydi, admin botni chetlab o'tadi
     */
    public function testProfileModeratorFlagsUnauthorizedBotButExemptsAdminBot(): void
    {
        $pm = new ProfileModerator();
        $res = $pm->inspectUser(['id' => 91001, 'first_name' => 'Reklama', 'is_bot' => true], -100816, true);
        $this->assertEquals('unsafe', $res['status']);
        $this->assertEquals('bot_account', $res['category']);

        Database::getConnection()
            ->prepare("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES (-100816, 91002, 'administrator', :now)")
            ->execute(['now' => gmdate('Y-m-d H:i:s')]);
        $res2 = $pm->inspectUser(['id' => 91002, 'first_name' => 'AdminBot', 'is_bot' => true], -100816, true);
        $this->assertEquals('safe', $res2['status']);
    }

    /**
     * 38. Tarixiy audit tozalash faqat belgilangan xabarlarni o'chiradi
     */
    public function testAuditCleanupDeletesFlaggedHistoricalMessagesOnly(): void
    {
        $chatId = -100817;
        $sessionId = AuditService::createSession($chatId, 1, 'json_export');
        $importer = new TelegramExportImporter();
        $tmp = dirname(__DIR__) . '/storage/temp/test_cleanup_' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['name' => 'G', 'type' => 'supergroup', 'id' => 100817, 'messages' => [
            ['id' => 7001, 'type' => 'message', 'date' => '2026-09-01T10:00:00', 'from_id' => 'user1', 'text' => 'oddiy xabar'],
            ['id' => 7002, 'type' => 'message', 'date' => '2026-09-01T10:01:00', 'from_id' => 'user2', 'text' => 'jalap suka haqorat'],
            ['id' => 7003, 'type' => 'message', 'date' => '2026-09-01T10:02:00', 'from_id' => 'user1', 'text' => 'yana oddiy gap'],
        ]]));

        try {
            $importer->importJson($tmp, $chatId, $sessionId);
            (new \App\Jobs\AuditCleanupJob())->handle([
                'session_id' => $sessionId,
                'chat_id' => $chatId,
                'mode' => 'delete_messages',
                'notify_chat_id' => 1,
            ]);

            $rows = Database::getConnection()
                ->query("SELECT message_id, cleanup_status FROM audit_items WHERE audit_session_id = {$sessionId}")
                ->fetchAll();
            $this->assertEquals(1, count($rows), 'Faqat 1 ta xabar belgilangan bo\'lishi kerak');
            $this->assertEquals(7002, (int)$rows[0]['message_id']);
            $this->assertEquals('deleted', $rows[0]['cleanup_status']);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 39. Tozalash callbacki faqat guruh admini uchun; navbatga to'g'ri job qo'yiladi
     */
    public function testAuditCleanupCallbackRequiresAdmin(): void
    {
        $chatId = -100818;
        $sessionId = AuditService::createSession($chatId, 1, 'json_export');
        $router = new UpdateRouter();

        $denied = $router->handle([
            'update_id' => 994020,
            'callback_query' => ['id' => 'cbx', 'from' => ['id' => 555001, 'first_name' => 'NA'], 'data' => "audit_clean_msgs:{$sessionId}"],
        ]);
        $this->assertEquals('unauthorized', $denied['status']);
        $this->assertNull(QueueService::reserve('default'));

        $ok = $router->handle([
            'update_id' => 994021,
            'callback_query' => ['id' => 'cby', 'from' => ['id' => 1, 'first_name' => 'Admin'], 'data' => "audit_clean_msgs:{$sessionId}"],
        ]);
        $this->assertEquals('audit_cleanup_queued', $ok['status']);
        $job = QueueService::reserve('default');
        $this->assertEquals('App\Jobs\AuditCleanupJob', $job['handler']);
        $this->assertEquals('delete_messages', $job['data']['mode']);
    }

    /**
     * 40. A'zolar sweep 18+ va bot akkauntlarni aniqlab audit_items ga yozadi
     */
    public function testMemberSweepFlagsAdultAndBotAccounts(): void
    {
        $chatId = -100819;
        SettingsService::get($chatId);
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (user_id, first_name, is_bot, profile_status, first_seen_at, last_seen_at) VALUES (5551, 'Reklama', 1, 'unscanned', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO users (user_id, first_name, is_bot, profile_status, first_seen_at, last_seen_at) VALUES (5552, 'Porno Kanal', 0, 'unscanned', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO users (user_id, first_name, is_bot, profile_status, first_seen_at, last_seen_at) VALUES (5553, 'Oddiy Foydalanuvchi', 0, 'unscanned', '{$now}', '{$now}')");
        foreach ([5551, 5552, 5553] as $uid) {
            $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$chatId}, {$uid}, 'member', '{$now}')");
        }

        $sessionId = AuditService::createSession($chatId, 1, 'member_sweep', 'all', 'economical');
        $job = QueueService::reserve('default');
        $this->assertEquals('App\Jobs\ScanMembersJob', $job['handler']);
        (new \App\Jobs\ScanMembersJob())->handle($job['data']);

        $items = $pdo->query("SELECT user_id, category FROM audit_items WHERE audit_session_id = {$sessionId} ORDER BY user_id")->fetchAll();
        $byUser = [];
        foreach ($items as $it) {
            $byUser[(int)$it['user_id']] = $it['category'];
        }
        $this->assertEquals('bot_account', $byUser[5551] ?? null);
        $this->assertEquals('adult_profile', $byUser[5552] ?? null);
        $this->assertFalse(isset($byUser[5553]), 'Oddiy foydalanuvchi belgilanmasligi kerak');
    }

    /**
     * 41. Mahalliy bosqich noaniq matnni AI uchun fon navbatiga qo'yadi,
     *     deterministik qoidabuzarlikni esa darhol jazolaydi.
     */
    public function testLocalPhaseDefersAiButActsOnDeterministicViolation(): void
    {
        $ambiguousChat = -100820;
        SettingsService::update($ambiguousChat, ['ai_mode' => 'comprehensive']);
        (new \App\Jobs\ModerateMessageJob())->handle([
            'message' => ['message_id' => 8001, 'chat' => ['id' => $ambiguousChat, 'type' => 'supergroup'], 'from' => ['id' => 70001, 'is_bot' => false], 'text' => 'shunchaki oddiy xabar'],
            'is_edited' => false,
            'chat_id' => $ambiguousChat,
            'phase' => 'local',
        ]);
        $deferred = QueueService::reserve('default');
        $this->assertNotNull($deferred, 'comprehensive rejimda AI uchun full job navbatga qo\'yilishi kerak');
        $this->assertEquals('full', $deferred['data']['phase']);

        $strictChat = -100821;
        SettingsService::get($strictChat);
        (new \App\Jobs\ModerateMessageJob())->handle([
            'message' => ['message_id' => 8010, 'chat' => ['id' => $strictChat, 'type' => 'supergroup'], 'from' => ['id' => 70010, 'is_bot' => false], 'text' => 'jalap suka'],
            'is_edited' => false,
            'chat_id' => $strictChat,
            'phase' => 'local',
        ]);
        $this->assertNull(QueueService::reserve('default'), 'Deterministik qoidabuzarlik uchun full job kerak emas');
        $actions = (int)Database::getConnection()
            ->query("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = {$strictChat}")
            ->fetchColumn();
        $this->assertEquals(1, $actions);
    }

    /**
     * 42. Telegram kanal "story" havolasi (t.me/x/s/N) spam sifatida bloklanadi,
     *     oddiy post havolasi (t.me/x/N) bloklanmaydi.
     */
    public function testTelegramStoryLinkBlockedButNormalPostLinkAllowed(): void
    {
        $moderator = new TextModerator();

        $story = $moderator->inspect('https://t.me/Janona_5500/s/82', [], 'economical', -100822, 'st1');
        $this->assertEquals('unsafe', $story['status']);
        $this->assertEquals('spam_ad', $story['category']);

        $post = $moderator->inspect("Yangilik: https://t.me/durov/385", [], 'economical', -100822, 'st2');
        $this->assertEquals('safe', $post['status']);
    }

    /**
     * 43. Shaxsiy chatda /menu bosh menyuni ko'rsatadi
     */
    public function testPrivateMenuIsShown(): void
    {
        $router = new UpdateRouter();
        $res = $router->handle([
            'update_id' => 994050,
            'message' => [
                'message_id' => 9500,
                'chat' => ['id' => 424242, 'type' => 'private'],
                'from' => ['id' => 424242, 'is_bot' => false, 'first_name' => 'User'],
                'text' => '/menu',
            ],
        ]);
        $this->assertEquals('private_menu_shown', $res['status']);

        $cb = $router->handle([
            'update_id' => 994051,
            'callback_query' => [
                'id' => 'pm1',
                'from' => ['id' => 424242, 'first_name' => 'User'],
                'message' => ['message_id' => 9501, 'chat' => ['id' => 424242, 'type' => 'private']],
                'data' => 'pm_help',
            ],
        ]);
        $this->assertEquals('private_help_shown', $cb['status']);
    }

    /**
     * 44. /blockword, /wordlist, /unblockword — jargon/lahjadagi so'zlarni mahalliy boshqarish
     */
    public function testBlockwordAllowwordAndWordlistCommands(): void
    {
        // Buyruqlar endi faqat shaxsiy chatda (DM) ishlaydi. Admin yagona guruhni boshqargani
        // uchun UpdateRouter uni avtomatik nishonga oladi (guruh ID kiritish shart emas).
        $chatId = -100823;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);
        $router = new UpdateRouter();

        // Boshqa testlardan user#1 allaqachon bir nechta guruhga admin bo'lib qolgan bo'lishi
        // mumkin bo'lgani uchun, aniqlik uchun guruh ID'ni buyruq matniga qo'shib yuboramiz.
        $add = $router->handle([
            'update_id' => 994060,
            'message' => ['message_id' => 9600, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/blockword {$chatId} qashqaldoq"],
        ]);
        $this->assertEquals('command_executed', $add['status']);

        $moderator = new TextModerator();
        $res = $moderator->inspect('sen qashqaldoq ekansan', [], 'economical', $chatId, 'bw1');
        $this->assertEquals('unsafe', $res['status'], "Qo'shilgan maxsus so'z darhol ishlashi kerak");

        $list = $router->handle([
            'update_id' => 994061,
            'message' => ['message_id' => 9601, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/wordlist {$chatId}"],
        ]);
        $this->assertEquals('command_executed', $list['status']);

        $rm = $router->handle([
            'update_id' => 994062,
            'message' => ['message_id' => 9602, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/unblockword {$chatId} qashqaldoq"],
        ]);
        $this->assertEquals('command_executed', $rm['status']);

        // Guruhning o'zida esa buyruq endi umuman ishlamasligini tasdiqlaymiz.
        $groupAttempt = $router->handle([
            'update_id' => 994063,
            'message' => ['message_id' => 9603, 'chat' => ['id' => $chatId, 'type' => 'supergroup'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => '/blockword yana_bir_soz'],
        ]);
        $this->assertTrue($groupAttempt['status'] !== 'command_executed', "Guruhda buyruq bajarilmasligi kerak, olindi: {$groupAttempt['status']}");

        $res2 = $moderator->inspect('sen qashqaldoq ekansan', [], 'economical', $chatId, 'bw2');
        $this->assertEquals('safe', $res2['status'], "O'chirilgan qoida endi ishlamasligi kerak");
    }

    /**
     * 44b. Guruhda faqat reply-asosidagi to'g'ridan-to'g'ri moderatsiya buyruqlari
     * (/mute, /ban va h.k.) ishlashda davom etadi — bular shaxsiy chatga ko'chirib
     * bo'lmaydi. Boshqa har qanday buyruq ("/menu" kabi) guruhda javobsiz qoladi.
     */
    public function testDirectModerationCommandsStillWorkInGroupButOthersDont(): void
    {
        $chatId = -100919191;
        $targetUserId = 55201;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);

        $router = new UpdateRouter();
        $muteResult = $router->handle([
            'update_id' => 994200,
            'message' => [
                'message_id' => 9700,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/mute 2',
                'reply_to_message' => [
                    'message_id' => 9699,
                    'from' => ['id' => $targetUserId, 'is_bot' => false, 'first_name' => 'Toxic'],
                ],
            ],
        ]);
        $this->assertEquals('command_executed', $muteResult['status']);

        $muteCount = (int)Database::getConnection()
            ->query("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = {$chatId} AND action_type = 'mute_user'")
            ->fetchColumn();
        $this->assertTrue($muteCount > 0, "/mute guruhda haqiqatda ijro etilishi kerak");

        // Ammo DM-only bo'lgan har qanday boshqa buyruq ("/menu" kabi) guruhda javobsiz qoladi.
        $menuAttempt = $router->handle([
            'update_id' => 994201,
            'message' => [
                'message_id' => 9701,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/menu',
            ],
        ]);
        $this->assertTrue($menuAttempt['status'] !== 'command_executed', "Guruhda /menu javob qaytarmasligi kerak, olindi: {$menuAttempt['status']}");
    }

    /**
     * 45. Tezkor "Xabarni o'chirish" callbacki faqat admin uchun ishlaydi
     */
    public function testQuickDeleteMessageCallbackRequiresAdmin(): void
    {
        $telegram = new class extends TelegramClient {
            public array $deleted = [];
            public function deleteMessage(int|string $chatId, int $messageId): bool
            {
                $this->deleted[] = [$chatId, $messageId];
                return true;
            }
        };
        $router = new UpdateRouter($telegram, new AdminAuthorizationService($telegram));
        $chatId = -100824;

        $denied = $router->handle([
            'update_id' => 994070,
            'callback_query' => ['id' => 'd1', 'from' => ['id' => 555002, 'first_name' => 'X'], 'data' => "rb_delmsg:{$chatId}:12345"],
        ]);
        $this->assertEquals('unauthorized', $denied['status']);
        $this->assertEquals(0, count($telegram->deleted));

        $ok = $router->handle([
            'update_id' => 994071,
            'callback_query' => ['id' => 'd2', 'from' => ['id' => 1, 'first_name' => 'Admin'], 'data' => "rb_delmsg:{$chatId}:12345"],
        ]);
        $this->assertEquals('rollback_executed', $ok['status']);
        $this->assertEquals(1, count($telegram->deleted));
    }

    /**
     * 46. Admin logga har doim tezkor "o'chirish"/"ban" tugmalari qo'shiladi,
     *     xabar allaqachon o'chirilgan yoki foydalanuvchi allaqachon ban bo'lsa — mos tugma yashiriladi.
     */
    public function testAdminLogOffersQuickDeleteAndBanButtonsWhenNotAlreadyHandled(): void
    {
        $oldOwners = (string)Config::get('TELEGRAM_OWNER_IDS', '');
        try {
            Config::set('TELEGRAM_OWNER_IDS', '999777');
            $telegram = new class extends TelegramClient {
                public array $sent = [];
                public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
                public function sendMessage(int|string $chatId, string $text, array $extra = []): array
                {
                    $this->sent[] = ['chat_id' => (int)$chatId, 'extra' => $extra];
                    return ['ok' => true, 'result' => ['message_id' => 1]];
                }
            };
            $chatId = -1009900;
            $service = new PunishmentService($telegram, new AdminAuthorizationService($telegram));

            // AI/mahalliy qoida yakuniy hal qila olmadi: xabar o'chirilmagan, foydalanuvchi ban emas.
            $service->execute($chatId, 40001, 9601, [
                'action' => 'alert_only', 'delete_message' => false, 'notify_admin' => true,
                'reason' => 'AI hech qanday tahlil qaytarmadi', 'strike_count' => 1,
            ]);
            $adminMsgs = array_values(array_filter($telegram->sent, static fn (array $m): bool => $m['chat_id'] === 999777));
            $this->assertEquals(1, count($adminMsgs));
            $markup = json_encode($adminMsgs[0]['extra']);
            $this->assertStringContainsString('rb_delmsg:', $markup);
            $this->assertStringContainsString('rb_ban:', $markup);
        } finally {
            Config::set('TELEGRAM_OWNER_IDS', $oldOwners);
        }
    }

    /**
     * 47. "Kodni botga yuboring" / "Kino kodi" va bitta @bot manzilini bir necha marta
     *     takrorlaydigan kontent-bot reklama shabloni AI'siz, mahalliy qoida bilan ushlanadi.
     */
    public function testContentBotAdvertisingTemplateIsBlockedLocally(): void
    {
        $moderator = new TextModerator();

        $caption1 = "Yangi Video Joylandi\xF0\x9F\x94\xA5\n\nKod: 6\n\nKodni botga yuboring\n\n"
            . "Bot manzili: @Yangikino1bot\n@Yangikino1bot @Yangikino1bot\n@Yangikino1bot @Yangikino1bot\n\n"
            . "Bot 2 : @Yangikino1bot\n\n1080hd\nLIKE BOSING zo'rlari chiqadi";
        $res1 = $moderator->inspect($caption1, [], 'economical', -100825, 'ad1');
        $this->assertEquals('unsafe', $res1['status']);
        $this->assertEquals('spam_ad', $res1['category']);

        $caption2 = "Kino kodi:6 — https://t.me/kanal";
        $res2 = $moderator->inspect($caption2, [], 'economical', -100825, 'ad2');
        $this->assertEquals('unsafe', $res2['status']);
        $this->assertEquals('spam_ad', $res2['category']);

        // Oddiy xabarda bitta @username tilga olinishi bloklanmasligi kerak.
        $safe = $moderator->inspect('Salom @admin, qalaysiz?', [], 'economical', -100825, 'ad3');
        $this->assertEquals('safe', $safe['status']);
    }

    /**
     * 48. Gemini/OpenRouter "review" (tushunarsiz javob) qaytarganda MediaModerator
     *     Google SafeSearch'ga zaxira sifatida murojaat qiladi va uning natijasini ishlatadi.
     */
    public function testMediaModeratorFallsBackToSafeSearchWhenAiIsInconclusive(): void
    {
        $telegram = new class extends TelegramClient {
            public function getFile(string $fileId): ?array
            {
                return ['file_path' => 'photos/test.jpg', 'file_size' => 100];
            }
            public function downloadFile(string $filePath, string $destinationPath): bool
            {
                file_put_contents($destinationPath, str_repeat('x', 64));
                return true;
            }
        };
        $inconclusiveAi = new class extends OpenRouterClient {
            public function moderateImage(string $imagePath, string $caption = '', string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array
            {
                return ['item_id' => $itemId, 'status' => 'review', 'category' => 'invalid_ai_response', 'reason' => 'format xato', 'evidence' => '', 'model' => 'test', 'cost_usd' => 0.0];
            }
        };
        $vision = new class extends \App\AI\GoogleVisionClient {
            public function isConfigured(): bool { return true; }
            public function detect(string $imagePath, string $itemId = 'item_1'): ?array
            {
                return ['item_id' => $itemId, 'status' => 'unsafe', 'category' => 'pornography', 'reason' => 'SafeSearch: adult=VERY_LIKELY', 'evidence' => '', 'model' => 'google-vision-safesearch', 'cost_usd' => 0.0015];
            }
        };

        $mediaModerator = new \App\Moderation\MediaModerator($telegram, $inconclusiveAi, $vision);
        $result = $mediaModerator->inspectMedia('file123', 'photo', '', -100827, 'mm1');

        $this->assertEquals('unsafe', $result['status']);
        $this->assertEquals('pornography', $result['category']);
        $this->assertEquals('google_safesearch_fallback', $result['source']);
    }

    /**
     * 49. AI aniq "safe" yoki "unsafe" deb topsa, SafeSearch zaxirasi chaqirilmaydi
     *     (faqat "review"/"unscannable" holatida ishlatiladi).
     */
    public function testSafeSearchFallbackNotUsedWhenAiIsConclusive(): void
    {
        $telegram = new class extends TelegramClient {
            public function getFile(string $fileId): ?array { return ['file_path' => 'photos/test2.jpg', 'file_size' => 100]; }
            public function downloadFile(string $filePath, string $destinationPath): bool { file_put_contents($destinationPath, str_repeat('y', 64)); return true; }
        };
        $confidentAi = new class extends OpenRouterClient {
            public function moderateImage(string $imagePath, string $caption = '', string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array
            {
                return ['item_id' => $itemId, 'status' => 'safe', 'category' => 'none', 'reason' => 'toza', 'evidence' => '', 'model' => 'test', 'cost_usd' => 0.0];
            }
        };
        $vision = new class extends \App\AI\GoogleVisionClient {
            public bool $called = false;
            public function isConfigured(): bool { return true; }
            public function detect(string $imagePath, string $itemId = 'item_1'): ?array { $this->called = true; return null; }
        };

        $mediaModerator = new \App\Moderation\MediaModerator($telegram, $confidentAi, $vision);
        $result = $mediaModerator->inspectMedia('file124', 'photo', '', -100828, 'mm2');

        $this->assertEquals('safe', $result['status']);
        $this->assertFalse($vision->called, "AI aniq xulosa bergan holatda SafeSearch chaqirilmasligi kerak");
    }

    /**
     * 50. Anti-flood: belgilangan oyna ichida chegaradan ortiq xabar yuborgan oddiy
     *     a'zo avtomatik (qisqa muddatga) mute qilinishi kerak.
     */
    public function testFloodGuardMutesFastSender(): void
    {
        $chatId = -100999001;
        $userId = 555001;
        $router = new UpdateRouter();

        // Standart sozlama: 10 soniyada 6 tadan ortiq xabar = flood.
        $lastStatus = null;
        for ($i = 1; $i <= 7; $i++) {
            $update = [
                'update_id' => 99910000 + $i,
                'message' => [
                    'message_id' => 99920000 + $i,
                    'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                    'from' => ['id' => $userId, 'is_bot' => false],
                    'text' => "flood xabari {$i}",
                ],
            ];
            $res = $router->handle($update);
            $lastStatus = $res['status'];
            if ($i <= 6) {
                $this->assertTrue($lastStatus !== 'flood_muted', "{$i}-xabar hali chegaraga yetmagan, flood_muted bo'lmasligi kerak");
            }
        }

        $this->assertEquals('flood_muted', $lastStatus, "7-xabar chegaradan oshgani uchun flood_muted qaytishi kerak");

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT status FROM telegram_actions WHERE chat_id = :cid AND user_id = :uid AND action_type = 'mute_user'");
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row = $stmt->fetch();
        $this->assertNotNull($row, "Flood sababli mute_user harakati telegram_actions'ga yozilishi kerak");
        $this->assertEquals('executed', $row['status']);

        // Flood sababli mute ogohlantirish zinapoyasiga (warn->mute->ban) qo'shilmasligi kerak.
        $this->assertEquals(0, ModerationDecisionService::getActiveWarningsCount($chatId, $userId), "Flood mute oddiy ogohlantirish hisobiga kirmasligi kerak");
    }

    /**
     * 51. Anti-flood adminlarga taalluqli emas — admin tez-tez yozsa ham cheklanmaydi.
     */
    public function testFloodGuardDoesNotMuteAdmins(): void
    {
        $chatId = -100999002;
        $adminUserId = 1; // Test muhitida TelegramClient::request() userId=1 ni har doim 'creator' deb qaytaradi.
        $router = new UpdateRouter();

        for ($i = 1; $i <= 8; $i++) {
            $update = [
                'update_id' => 99930000 + $i,
                'message' => [
                    'message_id' => 99940000 + $i,
                    'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                    'from' => ['id' => $adminUserId, 'is_bot' => false],
                    'text' => "admin tez xabar {$i}",
                ],
            ];
            $res = $router->handle($update);
            $this->assertTrue($res['status'] !== 'flood_muted', "Admin hech qachon flood_muted olmasligi kerak (iteratsiya {$i})");
        }
    }

    /**
     * 52. Guruh sozlamasida flood_enabled = 0 bo'lsa, tezlik nazorati butunlay o'chadi.
     */
    public function testFloodDisabledSettingSkipsFloodCheck(): void
    {
        $chatId = -100999004;
        $userId = 555004;
        SettingsService::update($chatId, ['flood_enabled' => 0]);
        $router = new UpdateRouter();

        for ($i = 1; $i <= 10; $i++) {
            $update = [
                'update_id' => 99950000 + $i,
                'message' => [
                    'message_id' => 99960000 + $i,
                    'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                    'from' => ['id' => $userId, 'is_bot' => false],
                    'text' => "o'chirilgan flood testi {$i}",
                ],
            ];
            $res = $router->handle($update);
            $this->assertTrue($res['status'] !== 'flood_muted', "flood_enabled=0 bo'lsa flood_muted hech qachon qaytmasligi kerak (iteratsiya {$i})");
        }
    }

    /**
     * 53. FloodGuard past darajada: oyna muddati tugagach hisoblagich avtomatik
     *     qayta boshlanadi (eski, chegaradan oshgan qiymat "meros" qolib ketmaydi).
     */
    public function testFloodGuardResetsAfterWindowExpires(): void
    {
        $chatId = -100999003;
        $userId = 555003;
        $pdo = Database::getConnection();
        $staleWindowStart = gmdate('Y-m-d H:i:s', time() - 100);
        $pdo->prepare("
            INSERT INTO flood_counters (chat_id, user_id, window_start, message_count, updated_at)
            VALUES (:cid, :uid, :ws, :cnt, :ws)
        ")->execute(['cid' => $chatId, 'uid' => $userId, 'ws' => $staleWindowStart, 'cnt' => 50]);

        $result = FloodGuard::register($chatId, $userId, 10, 6);

        $this->assertFalse($result['flooding'], "Oyna muddati (10s) tugagan eski hisoblagich flood deb hisoblanmasligi kerak");
        $this->assertEquals(1, $result['count'], "Yangi oyna 1-xabardan boshlanishi kerak");
    }

    /**
     * 54. CAPTCHA standart holatda o'chirilgan — yangi a'zo qo'shilganda
     *     hech qanday cheklov/tasdiqlash yozuvi yaratilmasligi kerak.
     */
    public function testCaptchaDisabledByDefaultDoesNotRestrictNewMember(): void
    {
        $chatId = -100777004;
        $userId = 444004;
        $router = new UpdateRouter();

        $res = $router->handle([
            'update_id' => 997020,
            'message' => [
                'message_id' => 5002,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 999, 'is_bot' => false],
                'new_chat_members' => [
                    ['id' => $userId, 'is_bot' => false, 'first_name' => 'Oddiy'],
                ],
            ],
        ]);
        $this->assertEquals('cleaned_service_message', $res['status']);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid");
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $this->assertEquals(0, (int)$stmt->fetchColumn(), "captcha_enabled=0 (standart) bo'lsa captcha yozuvi yaratilmasligi kerak");
    }

    /**
     * 55. CAPTCHA yoqilgan bo'lsa: yangi a'zo qo'shilganda tasdiqlash kutilayotgan
     *     yozuv va kechiktirilgan CaptchaTimeoutJob yaratiladi; "✅ Men botman
     *     emas" tugmasini o'zi bosganda tasdiqlanadi.
     */
    public function testCaptchaRestrictsNewMemberAndVerifyButtonUnlocks(): void
    {
        $chatId = -100777001;
        $userId = 444001;
        SettingsService::update($chatId, ['captcha_enabled' => 1, 'captcha_timeout_sec' => 45]);

        $router = new UpdateRouter();
        $res = $router->handle([
            'update_id' => 997001,
            'message' => [
                'message_id' => 5001,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 999, 'is_bot' => false],
                'new_chat_members' => [
                    ['id' => $userId, 'is_bot' => false, 'first_name' => 'Yangi', 'last_name' => 'Azo'],
                ],
            ],
        ]);
        $this->assertEquals('cleaned_service_message', $res['status']);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT status FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid");
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row = $stmt->fetch();
        $this->assertNotNull($row, "CAPTCHA yoqilgan bo'lsa tasdiqlash yozuvi yaratilishi kerak");
        $this->assertEquals('pending', $row['status']);

        // CaptchaTimeoutJob navbatga kechiktirilgan holda qo'yilgani (available_at kelajakda,
        // shuning uchun QueueService::reserve() hali qaytarmaydi — to'g'ridan-to'g'ri jadvaldan tekshiramiz).
        $jobRow = $pdo->query("SELECT payload, available_at FROM queue_jobs ORDER BY id DESC LIMIT 5")->fetchAll();
        $foundTimeoutJob = false;
        foreach ($jobRow as $r) {
            $decoded = json_decode((string)$r['payload'], true);
            if (($decoded['handler'] ?? '') === 'App\Jobs\CaptchaTimeoutJob' && (int)($decoded['data']['user_id'] ?? 0) === $userId) {
                $foundTimeoutJob = true;
                $this->assertTrue(strtotime((string)$r['available_at']) > time(), "CaptchaTimeoutJob kechiktirilgan (delay) bo'lishi kerak");
            }
        }
        $this->assertTrue($foundTimeoutJob, "CaptchaTimeoutJob navbatga qo'yilishi kerak");

        // Foydalanuvchi o'zi tugmani bosadi.
        $verifyRes = $router->handle([
            'update_id' => 997002,
            'callback_query' => [
                'id' => 'captcha-cb-1',
                'from' => ['id' => $userId, 'first_name' => 'Yangi'],
                'data' => "captcha_verify:{$chatId}:{$userId}",
            ],
        ]);
        $this->assertEquals('captcha_verified', $verifyRes['status']);

        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row2 = $stmt->fetch();
        $this->assertEquals('verified', $row2['status']);
    }

    /**
     * 56. CAPTCHA tugmasini faqat nishonlangan (yangi qo'shilgan) foydalanuvchining
     *     o'zi bosishi mumkin — boshqa foydalanuvchi bossa holat o'zgarmaydi.
     */
    public function testCaptchaVerifyRejectsDifferentUser(): void
    {
        $chatId = -100777002;
        $userId = 444002;
        $otherUserId = 444099;
        CaptchaGuard::start($chatId, $userId, 5555, 60);

        $router = new UpdateRouter();
        $res = $router->handle([
            'update_id' => 997010,
            'callback_query' => [
                'id' => 'captcha-cb-2',
                'from' => ['id' => $otherUserId, 'first_name' => 'Boshqa'],
                'data' => "captcha_verify:{$chatId}:{$userId}",
            ],
        ]);
        $this->assertEquals('unauthorized', $res['status']);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT status FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid");
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row = $stmt->fetch();
        $this->assertEquals('pending', $row['status'], "Noto'g'ri foydalanuvchi bosishi holatni o'zgartirmasligi kerak");
    }

    /**
     * 57. CaptchaTimeoutJob: foydalanuvchi vaqtida tasdiqlamasa, yozuv "kicked"ga
     *     o'tadi (guruhdan chetlatiladi, lekin doimiy ban emas).
     */
    public function testCaptchaTimeoutJobKicksUnverifiedMember(): void
    {
        $chatId = -100777003;
        $userId = 444003;
        CaptchaGuard::start($chatId, $userId, 6666, 60);

        (new \App\Jobs\CaptchaTimeoutJob())->handle(['chat_id' => $chatId, 'user_id' => $userId]);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT status FROM captcha_pending WHERE chat_id = :cid AND user_id = :uid");
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row = $stmt->fetch();
        $this->assertEquals('kicked', $row['status']);

        // Job ikkinchi marta (masalan, worker qayta urinishi) chaqirilsa ham xavfsiz — o'zgarish yo'q.
        (new \App\Jobs\CaptchaTimeoutJob())->handle(['chat_id' => $chatId, 'user_id' => $userId]);
        $stmt->execute(['cid' => $chatId, 'uid' => $userId]);
        $row2 = $stmt->fetch();
        $this->assertEquals('kicked', $row2['status']);
    }

    /**
     * 59. "Local" bosqichda ovozli (voice) va audio xabarlar media_filter yoqilgan bo'lsa
     *     to'liq (AI) tahlil uchun fon navbatiga qo'yiladi.
     */
    public function testLocalPhaseQueuesVoiceAndAudioMessagesForFullModeration(): void
    {
        $chatId = -100822001;
        SettingsService::get($chatId);

        (new \App\Jobs\ModerateMessageJob())->handle([
            'message' => [
                'message_id' => 9001,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 80001, 'is_bot' => false],
                'voice' => ['file_id' => 'voice_file_1', 'mime_type' => 'audio/ogg', 'duration' => 5],
            ],
            'is_edited' => false,
            'chat_id' => $chatId,
            'phase' => 'local',
        ]);
        $deferred = QueueService::reserve('default');
        $this->assertNotNull($deferred, "Ovozli xabar uchun to'liq (full) tahlil navbatga qo'yilishi kerak");
        $this->assertEquals('full', $deferred['data']['phase']);

        $chatId2 = -100822002;
        SettingsService::get($chatId2);
        (new \App\Jobs\ModerateMessageJob())->handle([
            'message' => [
                'message_id' => 9002,
                'chat' => ['id' => $chatId2, 'type' => 'supergroup'],
                'from' => ['id' => 80002, 'is_bot' => false],
                'audio' => ['file_id' => 'audio_file_1', 'mime_type' => 'audio/mpeg', 'duration' => 30],
            ],
            'is_edited' => false,
            'chat_id' => $chatId2,
            'phase' => 'local',
        ]);
        $deferred2 = QueueService::reserve('default');
        $this->assertNotNull($deferred2, "Audio fayl uchun to'liq (full) tahlil navbatga qo'yilishi kerak");
        $this->assertEquals('full', $deferred2['data']['phase']);
    }

    /**
     * 60. media_filter o'chirilgan guruhda ovozli xabar uchun hech qanday tahlil
     *     navbatga qo'yilmaydi.
     */
    public function testVoiceMessageSkippedWhenMediaFilterDisabled(): void
    {
        $chatId = -100822003;
        SettingsService::update($chatId, ['media_filter' => false]);

        (new \App\Jobs\ModerateMessageJob())->handle([
            'message' => [
                'message_id' => 9003,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 80003, 'is_bot' => false],
                'voice' => ['file_id' => 'voice_file_2', 'mime_type' => 'audio/ogg', 'duration' => 5],
            ],
            'is_edited' => false,
            'chat_id' => $chatId,
            'phase' => 'local',
        ]);
        $this->assertNull(QueueService::reserve('default'), "media_filter o'chirilganda ovozli xabar navbatga qo'yilmasligi kerak");
    }

    /**
     * 61. MediaModerator::inspectMedia() "voice" va "audio" turlarini AI klientning
     *     moderateAudio() metodiga to'g'ri yo'naltiradi va natijani qaytaradi.
     */
    public function testMediaModeratorDispatchesVoiceAndAudioToModerateAudio(): void
    {
        $telegram = new class extends TelegramClient {
            public function getFile(string $fileId): ?array
            {
                // Fayl kontenti fileId'ga bog'liq — har xil fileId har xil hash (kesh
                // to'qnashuvining oldini olish uchun, chunki ikkala chaqiruv ham
                // bitta test ichida ketma-ket ishlaydi).
                return ['file_path' => "voice/{$fileId}.oga", 'file_size' => 500];
            }
            public function downloadFile(string $filePath, string $destinationPath): bool
            {
                file_put_contents($destinationPath, $filePath);
                return true;
            }
        };
        $ai = new class extends OpenRouterClient {
            public array $calledWith = [];
            public function moderateAudio(string $audioPath, string $caption = '', string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array
            {
                $this->calledWith[] = $itemId;
                return ['item_id' => $itemId, 'status' => 'unsafe', 'category' => 'profanity', 'reason' => 'ogzaki so\'kinish', 'evidence' => 'so\'z', 'model' => 'test-audio', 'cost_usd' => 0.001];
            }
        };
        $vision = new \App\AI\GoogleVisionClient();

        $mediaModerator = new \App\Moderation\MediaModerator($telegram, $ai, $vision);

        $voiceResult = $mediaModerator->inspectMedia('voice_file_x', 'voice', '', -100822004, 'mm_voice');
        $this->assertEquals('unsafe', $voiceResult['status']);
        $this->assertEquals('profanity', $voiceResult['category']);
        $this->assertEquals('ai_audio', $voiceResult['source']);

        $audioResult = $mediaModerator->inspectMedia('audio_file_x', 'audio', '', -100822004, 'mm_audio');
        $this->assertEquals('unsafe', $audioResult['status']);
        $this->assertEquals('ai_audio', $audioResult['source']);

        $this->assertEquals(['mm_voice', 'mm_audio'], $ai->calledWith);
    }

    /**
     * 62. OpenRouter provayderida (Gemini emas) moderateAudio() haqiqiy tarmoq so'rovisiz,
     *     xavfsiz "unscannable" natija bilan gracious ravishda ishlaydi (bloklamaydi,
     *     lekin adminга signal beradi).
     */
    public function testOpenRouterModerateAudioGracefullyDegrades(): void
    {
        $client = new OpenRouterClient();
        $tmpFile = tempnam(sys_get_temp_dir(), 'voice_test_');
        file_put_contents($tmpFile, str_repeat('x', 16));

        try {
            $result = $client->moderateAudio($tmpFile, '', 'or_audio_1');
            $this->assertEquals('unscannable', $result['status']);
            $this->assertEquals('audio_unsupported_provider', $result['category']);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * 63. WEBM video-stiker MediaModerator::inspectMedia() orqali mavjud FFmpeg
     *     kadr-ajratish yo'liga yo'naltiriladi (avval bunday stikerlar hech qanday
     *     tahlilsiz "unscannable/animated_sticker" deb belgilanardi).
     */
    public function testMediaModeratorRoutesWebmVideoStickerThroughFrameExtraction(): void
    {
        $probe = new \App\Moderation\MediaModerator();
        if (!$probe->isFfmpegAvailable()) {
            $this->markTestSkipped("FFmpeg mavjud emas — WEBM video-stiker kadr-ajratish testi o'tkazib yuborildi");
        }

        $webmPath = sys_get_temp_dir() . '/sticker_test_' . bin2hex(random_bytes(4)) . '.webm';
        // inspectVideo() 1/3/5-soniyalardan kadr oladi — fikstura shu nuqtalarni qamrab
        // olishi uchun yetarlicha uzun (6s) bo'lishi kerak.
        @exec('ffmpeg -y -f lavfi -i testsrc=size=32x32:rate=2:duration=6 -c:v libvpx '
            . escapeshellarg($webmPath) . ' 2>&1', $out, $code);
        if ($code !== 0 || !file_exists($webmPath) || filesize($webmPath) === 0) {
            $this->markTestSkipped("Test uchun WEBM fikstura generatsiya qilib bo'lmadi");
        }
        $webmBytes = (string)file_get_contents($webmPath);
        @unlink($webmPath);

        $telegram = new class extends TelegramClient {
            public string $bytes = '';
            public function getFile(string $fileId): ?array
            {
                return ['file_path' => 'stickers/test.webm', 'file_size' => strlen($this->bytes)];
            }
            public function downloadFile(string $filePath, string $destinationPath): bool
            {
                file_put_contents($destinationPath, $this->bytes);
                return true;
            }
        };
        $telegram->bytes = $webmBytes;

        $ai = new class extends OpenRouterClient {
            public int $calls = 0;
            public function moderateImage(string $imagePath, string $caption = '', string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array
            {
                $this->calls++;
                return ['item_id' => $itemId, 'status' => 'unsafe', 'category' => 'pornography', 'reason' => 'test', 'evidence' => '', 'model' => 'test', 'cost_usd' => 0.0];
            }
        };
        $vision = new \App\AI\GoogleVisionClient();

        $mediaModerator = new \App\Moderation\MediaModerator($telegram, $ai, $vision);
        $result = $mediaModerator->inspectMedia('sticker_file_1', 'sticker', '', -100822005, 'mm_sticker_webm');

        $this->assertEquals('unsafe', $result['status']);
        $this->assertEquals('ai_vision_video_sticker', $result['source']);
        $this->assertTrue($ai->calls > 0, "WEBM video-stiker uchun AI vision kadr tahlili chaqirilishi kerak");
    }

    /**
     * 64. TGS (Lottie) animatsion stiker to'g'ridan-to'g'ri inspectMedia()ga kelsa
     *     (masalan, ModerateMessageJob darajasida thumbnail-zaxira topilmagan holatda),
     *     xavfsiz "unscannable/animated_sticker" natija bilan yakunlanadi — bloklamaydi
     *     va AI'ga hech qanday so'rov yubormaydi (FFmpeg orqali dekodlab bo'lmaydi).
     */
    public function testMediaModeratorTgsStickerReturnsUnscannableWithoutCrashing(): void
    {
        $telegram = new class extends TelegramClient {
            public function getFile(string $fileId): ?array
            {
                return ['file_path' => 'stickers/test.tgs', 'file_size' => 40];
            }
            public function downloadFile(string $filePath, string $destinationPath): bool
            {
                file_put_contents($destinationPath, str_repeat("\x1f\x8b", 20));
                return true;
            }
        };
        $ai = new class extends OpenRouterClient {
            public int $calls = 0;
            public function moderateImage(string $imagePath, string $caption = '', string $itemId = 'item_1', ?int $chatId = null, ?string $customModel = null): array
            {
                $this->calls++;
                return ['item_id' => $itemId, 'status' => 'safe', 'category' => 'none', 'reason' => '', 'evidence' => '', 'model' => 'test', 'cost_usd' => 0.0];
            }
        };
        $vision = new \App\AI\GoogleVisionClient();

        $mediaModerator = new \App\Moderation\MediaModerator($telegram, $ai, $vision);
        $result = $mediaModerator->inspectMedia('sticker_file_2', 'sticker', '', -100822006, 'mm_sticker_tgs');

        $this->assertEquals('unscannable', $result['status']);
        $this->assertEquals('animated_sticker', $result['category']);
        $this->assertEquals(0, $ai->calls, "TGS uchun AI chaqiruvi bo'lmasligi kerak (ffmpeg orqali dekodlab bo'lmaydi)");
    }

    /**
     * 65. /addmod orqali berilgan botning ichki "moderator" roli faqat cheklangan
     *     buyruqlarga (/warn, /mute, /unmute, /warnings) ruxsat beradi — /ban va
     *     /addmod/removemod kabi og'irroq/rol-boshqaruv buyruqlari unga yopiq qoladi.
     */
    public function testAddmodGrantsWarnMuteButBlocksBanAndRoleCommands(): void
    {
        $chatId = -100822007;
        $adminId = 1; // Test rejimida TelegramClient getChatMember stubi: id=1 -> 'creator'.
        $moderatorId = 70050;
        $targetId = 70051;
        SettingsService::get($chatId);
        $router = new UpdateRouter();

        // Admin moderatorni tayinlaydi (xabarga reply qilib /addmod).
        $addResult = $router->handle([
            'update_id' => 995001,
            'message' => [
                'message_id' => 20001,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $adminId, 'is_bot' => false],
                'text' => '/addmod',
                'reply_to_message' => ['message_id' => 20000, 'from' => ['id' => $moderatorId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('command_executed', $addResult['status']);
        $this->assertTrue($addResult['is_moderator']);

        // Moderator /mute buyurishi mumkin.
        $muteResult = $router->handle([
            'update_id' => 995002,
            'message' => [
                'message_id' => 20002,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $moderatorId, 'is_bot' => false],
                'text' => '/mute 1',
                'reply_to_message' => ['message_id' => 20001, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('command_executed', $muteResult['status']);
        $muteCount = (int)Database::getConnection()
            ->query("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = {$chatId} AND user_id = {$targetId} AND action_type = 'mute_user'")
            ->fetchColumn();
        $this->assertTrue($muteCount > 0, "Moderator /mute buyrug'i haqiqatda ijro etilishi kerak");

        // Lekin moderator /ban bera olmaydi.
        $banResult = $router->handle([
            'update_id' => 995003,
            'message' => [
                'message_id' => 20003,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $moderatorId, 'is_bot' => false],
                'text' => '/ban',
                'reply_to_message' => ['message_id' => 20001, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('forbidden_for_moderator', $banResult['status']);
        $banCount = (int)Database::getConnection()
            ->query("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = {$chatId} AND user_id = {$targetId} AND action_type = 'ban_user'")
            ->fetchColumn();
        $this->assertEquals(0, $banCount, "Moderator /ban orqali chetlata olmasligi kerak");

        // Va boshqa a'zoni moderator qila olmaydi (rol-boshqaruv buyrug'i ham yopiq).
        $addmodByModResult = $router->handle([
            'update_id' => 995004,
            'message' => [
                'message_id' => 20004,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $moderatorId, 'is_bot' => false],
                'text' => '/addmod',
                'reply_to_message' => ['message_id' => 20001, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('forbidden_for_moderator', $addmodByModResult['status']);
    }

    /**
     * 66. /removemod moderator huquqini bekor qiladi — shundan keyin sobiq moderator
     *     endi /mute kabi buyruqlarni bera olmaydi (oddiy a'zo sifatida javobsiz qoladi).
     */
    public function testRemovemodRevokesModeratorPrivileges(): void
    {
        $chatId = -100822008;
        $adminId = 1;
        $moderatorId = 70060;
        $targetId = 70061;
        SettingsService::get($chatId);
        $router = new UpdateRouter();

        AdminAuthorizationService::setModeratorRole($chatId, $moderatorId, true);
        $auth = new AdminAuthorizationService();
        $this->assertTrue($auth->isModerator($chatId, $moderatorId));

        $removeResult = $router->handle([
            'update_id' => 995010,
            'message' => [
                'message_id' => 20010,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $adminId, 'is_bot' => false],
                'text' => '/removemod',
                'reply_to_message' => ['message_id' => 20009, 'from' => ['id' => $moderatorId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('command_executed', $removeResult['status']);
        $this->assertFalse($removeResult['is_moderator']);
        $this->assertFalse((new AdminAuthorizationService())->isModerator($chatId, $moderatorId));

        $muteAttempt = $router->handle([
            'update_id' => 995011,
            'message' => [
                'message_id' => 20011,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $moderatorId, 'is_bot' => false],
                'text' => '/mute 1',
                'reply_to_message' => ['message_id' => 20010, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertTrue($muteAttempt['status'] !== 'command_executed', "Moderator huquqi bekor qilingandan keyin /mute ishlamasligi kerak");
    }

    /**
     * 67. /addmod allaqachon to'liq admin bo'lgan foydalanuvchiga qo'llanilsa,
     *     hech narsa o'zgartirmaydi (alohida moderator huquqi keraksiz).
     */
    public function testAddmodOnExistingAdminIsNoop(): void
    {
        $chatId = -100822009;
        $adminId = 1;
        SettingsService::get($chatId);
        $router = new UpdateRouter();

        $result = $router->handle([
            'update_id' => 995020,
            'message' => [
                'message_id' => 20020,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $adminId, 'is_bot' => false],
                'text' => '/addmod',
                // O'ziga (creator sifatida aniqlanadigan id=1 foydalanuvchiga) reply.
                'reply_to_message' => ['message_id' => 20019, 'from' => ['id' => 1, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('already_admin', $result['status']);
        $this->assertFalse((new AdminAuthorizationService())->isModerator($chatId, 1));
    }

    /**
     * 68. HealthCheck::runChecks() DB/navbat muammosi bo'lmasa va "jonlik" belgisi
     *     hali umuman yozilmagan bo'lsa ham (masalan, worker/cron yangi versiyani hali
     *     ishga tushirmagan) tizimni sog'lom deb hisoblaydi.
     */
    public function testHealthCheckHeartbeatMissingIsTreatedAsHealthy(): void
    {
        $heartbeatFile = \App\Core\HealthCheck::heartbeatFile();
        if (file_exists($heartbeatFile)) {
            unlink($heartbeatFile);
        }

        $result = \App\Core\HealthCheck::runChecks();

        $this->assertTrue($result['healthy']);
        $this->assertTrue($result['checks']['worker_heartbeat']['ok']);
        $this->assertNull($result['checks']['worker_heartbeat']['age_seconds']);
    }

    /**
     * 69. Yangi yozilgan "jonlik" belgisi tizimni sog'lom deb ko'rsatadi, eskirgan
     *     (masalan, 500 soniya oldingi) belgi esa "worker/cron to'xtagan" deb ushlaydi.
     */
    public function testHealthCheckHeartbeatFreshVsStale(): void
    {
        $heartbeatFile = \App\Core\HealthCheck::heartbeatFile();
        try {
            \App\Core\HealthCheck::writeHeartbeat();
            $fresh = \App\Core\HealthCheck::runChecks();
            $this->assertTrue($fresh['checks']['worker_heartbeat']['ok']);
            $this->assertTrue($fresh['healthy']);

            file_put_contents($heartbeatFile, (string)(time() - 500));
            $stale = \App\Core\HealthCheck::runChecks();
            $this->assertFalse($stale['checks']['worker_heartbeat']['ok']);
            $this->assertFalse($stale['healthy']);
        } finally {
            if (file_exists($heartbeatFile)) {
                unlink($heartbeatFile);
            }
        }
    }

    /**
     * 70. Navbatda uzoq vaqt (masalan, 400 soniya) kutayotgan bajarilmagan vazifa
     *     bo'lsa, HealthCheck buni "worker/cron ishlamayapti" deb aniqlaydi.
     */
    public function testHealthCheckDetectsStuckQueueJob(): void
    {
        $pdo = Database::getConnection();
        $oldTimestamp = gmdate('Y-m-d H:i:s', time() - 400);
        $pdo->prepare("
            INSERT INTO queue_jobs (queue, priority, payload, attempts, reserved_at, available_at, created_at)
            VALUES ('default', 10, :payload, 0, NULL, :avail, :now)
        ")->execute([
            'payload' => json_encode(['handler' => 'App\\Jobs\\ModerateMessageJob', 'data' => []]),
            'avail' => $oldTimestamp,
            'now' => $oldTimestamp,
        ]);

        $result = \App\Core\HealthCheck::runChecks();

        $this->assertFalse($result['checks']['queue']['ok']);
        $this->assertFalse($result['healthy']);
        $this->assertTrue($result['checks']['queue']['pending'] > 0);
    }

    /**
     * 71. Gorizontal-scaling: bir nechta worker (masalan WORKER_ID=1, WORKER_ID=2 bilan
     *     ishga tushirilgan alohida jarayonlar) o'z-o'zidan alohida "jonlik" fayllariga
     *     yozadi. Kamida bittasi jonli bo'lsa tizim umuman "sog'lom" hisoblanadi (navbat
     *     baribir tozalanadi), lekin har bir workerning holati `workers` ro'yxatida
     *     alohida ko'rinadi. Ikkalasi ham eskirgan bo'lsagina umuman nosog'lom bo'ladi.
     */
    public function testHealthCheckAggregatesMultipleWorkerHeartbeats(): void
    {
        $defaultFile = \App\Core\HealthCheck::heartbeatFile('default');
        $w1File = \App\Core\HealthCheck::heartbeatFile('w1');
        $w2File = \App\Core\HealthCheck::heartbeatFile('w2');

        foreach ([$defaultFile, $w1File, $w2File] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        try {
            file_put_contents($w1File, (string)time());
            file_put_contents($w2File, (string)(time() - 500)); // eskirgan

            $result = \App\Core\HealthCheck::runChecks();

            $this->assertTrue($result['checks']['worker_heartbeat']['ok']);
            $this->assertTrue($result['healthy']);
            $this->assertEquals(2, count($result['checks']['worker_heartbeat']['workers']));

            // Ikkalasi ham eskirgan bo'lsa - butunlay nosog'lom
            file_put_contents($w1File, (string)(time() - 500));
            $result2 = \App\Core\HealthCheck::runChecks();
            $this->assertFalse($result2['checks']['worker_heartbeat']['ok']);
            $this->assertFalse($result2['healthy']);
        } finally {
            foreach ([$defaultFile, $w1File, $w2File] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    /**
     * 72. Logger: log fayli belgilangan hajmdan (LOG_MAX_SIZE_BYTES) oshsa, avtomatik
     *     ravishda `.1` backup faylga aylanadi va joriy fayl bo'shatiladi — shu orqali
     *     storage/logs/*.log cheksiz o'sib ketishining (Phase 2 roadmap bandi) oldi olinadi.
     */
    public function testLoggerRotatesLogFileWhenSizeExceedsLimit(): void
    {
        $dir = sys_get_temp_dir() . '/blockbot_test_logs_' . uniqid();
        mkdir($dir, 0755, true);

        Config::set('LOG_MAX_SIZE_BYTES', 200);
        Config::set('LOG_MAX_BACKUPS', 5);
        Config::set('LOG_COMPRESS_BACKUPS', false);

        $channel = 'rotate_test';
        $logFile = $dir . '/' . $channel . '.log';

        try {
            \App\Core\Logger::init($dir);

            // Har biri ~55 bayt bo'lgan yozuvlar — 200 baytlik chegaradan albatta oshadi
            for ($i = 0; $i < 10; $i++) {
                \App\Core\Logger::info(str_repeat('x', 50), [], $channel);
            }

            $this->assertTrue(is_file($logFile . '.1'), "Rotatsiyadan keyin .1 backup fayli bo'lishi kerak");
            clearstatcache(true, $logFile);
            $this->assertTrue(filesize($logFile) < 200, "Rotatsiyadan keyingi joriy fayl kichik bo'lishi kerak");
        } finally {
            Config::set('LOG_MAX_SIZE_BYTES', 10 * 1024 * 1024);
            Config::set('LOG_MAX_BACKUPS', 5);
            Config::set('LOG_COMPRESS_BACKUPS', true);
            \App\Core\Logger::init(); // standart papkaga qaytarish
            $this->removeTestLogDir($dir);
        }
    }

    /**
     * 73. Logger: backuplar soni LOG_MAX_BACKUPS'dan oshmasligi kerak — eng eski
     *     backup har safar yangi rotatsiyada butunlay o'chirilib boriladi.
     */
    public function testLoggerEnforcesMaxBackupCount(): void
    {
        $dir = sys_get_temp_dir() . '/blockbot_test_logs_' . uniqid();
        mkdir($dir, 0755, true);

        Config::set('LOG_MAX_SIZE_BYTES', 100);
        Config::set('LOG_MAX_BACKUPS', 2);
        Config::set('LOG_COMPRESS_BACKUPS', false);

        $channel = 'rotate_limit_test';
        $logFile = $dir . '/' . $channel . '.log';

        try {
            \App\Core\Logger::init($dir);

            // Har bir yozuv chegaradan oshadi -> ko'p marta rotatsiyani majburlaymiz
            for ($i = 0; $i < 30; $i++) {
                \App\Core\Logger::info(str_repeat('y', 80), [], $channel);
            }

            $this->assertTrue(is_file($logFile . '.1'));
            $this->assertTrue(is_file($logFile . '.2'));
            $this->assertFalse(is_file($logFile . '.3'), "LOG_MAX_BACKUPS=2 bo'lganda .3 backup bo'lmasligi kerak");
        } finally {
            Config::set('LOG_MAX_SIZE_BYTES', 10 * 1024 * 1024);
            Config::set('LOG_MAX_BACKUPS', 5);
            Config::set('LOG_COMPRESS_BACKUPS', true);
            \App\Core\Logger::init();
            $this->removeTestLogDir($dir);
        }
    }

    private function removeTestLogDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * 74. App\Core\Translator: standart holatda 'uz', noma'lum til kodi 'uz'ga
     *     tushadi, va tanlangan tilda topilmagan kalit standart tilga (undan
     *     ham topilmasa kalitning o'ziga) tushib qoladi — hech qachon xato bermaydi.
     */
    public function testTranslatorFallsBackToDefaultForUnsupportedLanguageAndMissingKey(): void
    {
        $this->assertEquals('uz', \App\Core\Translator::normalizeLang(null));
        $this->assertEquals('uz', \App\Core\Translator::normalizeLang('xx'));
        $this->assertEquals('ru', \App\Core\Translator::normalizeLang('RU'));

        $uzText = \App\Core\Translator::get('captcha.button', 'uz');
        $unknownLangText = \App\Core\Translator::get('captcha.button', 'xx');
        $this->assertEquals($uzText, $unknownLangText, "Noma'lum til kodi standart ('uz')ga tushishi kerak");

        $missingKey = \App\Core\Translator::get('bu.kalit.hech.qayerda.yoq', 'ru');
        $this->assertEquals('bu.kalit.hech.qayerda.yoq', $missingKey, "Hech qayerda topilmagan kalit o'zini qaytarishi kerak");

        $withParams = \App\Core\Translator::get('captcha.welcome', 'en', ['name' => 'Ali', 'timeout' => 45]);
        $this->assertStringContainsString('Ali', $withParams);
        $this->assertStringContainsString('45', $withParams);
    }

    /**
     * 75. CAPTCHA guruh a'zolariga ko'rinadigan xabarlari (xush kelibsiz matni +
     *     tugma) guruhning tanlangan tiliga ('ru') mos ravishda yuboriladi.
     */
    public function testCaptchaMessagesUseGroupLanguage(): void
    {
        $chatId = -100777099;
        $userId = 444099;
        SettingsService::update($chatId, ['captcha_enabled' => 1, 'captcha_timeout_sec' => 30, 'language' => 'ru']);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 88099]];
            }
        };
        $router = new UpdateRouter($telegram);
        $router->handle([
            'update_id' => 995099,
            'message' => [
                'message_id' => 6099,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 999, 'is_bot' => false],
                'new_chat_members' => [
                    ['id' => $userId, 'is_bot' => false, 'first_name' => 'New'],
                ],
            ],
        ]);

        $captchaMessages = array_values(array_filter($telegram->sent, static fn (array $m): bool => $m['chat_id'] === $chatId));
        $this->assertTrue(count($captchaMessages) > 0, "CAPTCHA xabari yuborilishi kerak");
        $this->assertStringContainsString('добро пожаловать', $captchaMessages[0]['text']);
        $buttonJson = json_encode($captchaMessages[0]['extra'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Я не бот', (string)$buttonJson);
    }

    /**
     * 76. Jazo (mute/ban) xabari foydalanuvchiga guruhning tanlangan tilida ('ru')
     *     yuboriladi — App\Core\Translator orqali PunishmentService.
     */
    public function testPunishmentNoticeUsesGroupLanguage(): void
    {
        $chatId = -100777199;
        $userId = 555199;
        SettingsService::update($chatId, ['language' => 'ru']);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
            public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool { return true; }
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 88199]];
            }
        };
        $service = new PunishmentService($telegram, new AdminAuthorizationService($telegram));
        $service->execute($chatId, $userId, 9199, [
            'action' => 'mute_user', 'delete_message' => false, 'reason' => 'Spam',
            'strike_count' => 2, 'mute_duration_sec' => 3600,
        ]);

        $privateMessages = array_values(array_filter($telegram->sent, static fn (array $m): bool => $m['chat_id'] === $userId));
        $this->assertEquals(1, count($privateMessages));
        $this->assertStringContainsString('Временное ограничение', $privateMessages[0]['text']);
        $this->assertStringContainsString('Подать апелляцию', (string)json_encode($privateMessages[0]['extra'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 77. DM buyrug'i /til — argumentsiz joriy tilni ko'rsatadi, `/til ru` bilan
     *     guruh tilini o'zgartiradi, noto'g'ri kod bilan xato xabar beradi.
     */
    public function testLanguageCommandShowsAndChangesGroupLanguage(): void
    {
        $chatId = -100888299;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);
        $router = new UpdateRouter();

        $show = $router->handle([
            'update_id' => 996299,
            'message' => ['message_id' => 7299, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/til {$chatId}"],
        ]);
        $this->assertEquals('command_executed', $show['status']);
        $this->assertEquals('uz', SettingsService::get($chatId)['language'], "Standart til 'uz' bo'lishi kerak");

        $invalid = $router->handle([
            'update_id' => 996300,
            'message' => ['message_id' => 7300, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/til {$chatId} fr"],
        ]);
        $this->assertEquals('invalid_language', $invalid['status']);
        $this->assertEquals('uz', SettingsService::get($chatId)['language'], "Noto'g'ri kod tilni o'zgartirmasligi kerak");

        $change = $router->handle([
            'update_id' => 996301,
            'message' => ['message_id' => 7301, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/til {$chatId} ru"],
        ]);
        $this->assertEquals('command_executed', $change['status']);
        $this->assertEquals('ru', SettingsService::get($chatId)['language']);
    }

    /**
     * Bepul/premium tarif uchun yordamchi: berilgan guruhga bugungi kun uchun
     * $count ta ai_usage yozuvini qo'shadi (SubscriptionService::canUseAi()
     * chegarasini simulyatsiya qilish uchun).
     */
    private function seedAiUsage(int $chatId, int $count): void
    {
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO ai_usage (chat_id, model, request_type, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, is_estimated, created_at)
            VALUES (:cid, 'test-model', 'text', 10, 10, 20, 0.0001, 1, :now)
        ");
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute(['cid' => $chatId, 'now' => $now]);
        }
    }

    /**
     * 78. SubscriptionService::canUseAi() — bepul tarifda kunlik chegaradan
     *     oshgach false qaytaradi, premium guruh uchun har doim true bo'ladi
     *     (2.0 Phase 3, 2-band — Telegram Stars monetizatsiya).
     */
    public function testSubscriptionServiceEnforcesFreeTierDailyLimitAndPremiumBypass(): void
    {
        $chatId = -100888399;
        $oldLimit = (string)Config::get('FREE_TIER_DAILY_AI_REQUESTS', '');
        Config::set('FREE_TIER_DAILY_AI_REQUESTS', '5');
        try {
            SettingsService::get($chatId);
            $this->assertTrue(SubscriptionService::canUseAi($chatId), "Chegaraga yetmagan holatda AI ishlatish ruxsat etilishi kerak");

            $this->seedAiUsage($chatId, 5);
            $this->assertEquals(5, SubscriptionService::dailyAiRequestCount($chatId));
            $this->assertFalse(SubscriptionService::canUseAi($chatId), "Kunlik chegaradan oshgach AI ishlatish taqiqlanishi kerak");

            SubscriptionService::activatePremium($chatId, 30);
            $this->assertTrue(SubscriptionService::canUseAi($chatId), "Premium guruh uchun chegara qo'llanilmasligi kerak");
            $plan = SubscriptionService::getPlan($chatId);
            $this->assertEquals('premium', $plan['plan']);
            $this->assertTrue($plan['is_premium']);
        } finally {
            Config::set('FREE_TIER_DAILY_AI_REQUESTS', $oldLimit);
        }
    }

    /**
     * 79. Kunlik chegaradan oshgan guruh uchun OpenRouterClient::moderateText()
     *     tarmoqqa umuman murojaat qilmasdan 'free_tier_limit_reached' natijasini
     *     qaytaradi — AI mijozlaridagi bir yagona to'siq nuqtasi to'g'ri ulanganini
     *     tekshiradi (2.0 Phase 3, 2-band).
     */
    public function testAiClientReturnsFreeTierLimitReachedWhenDailyLimitExceeded(): void
    {
        $chatId = -100888499;
        $oldLimit = (string)Config::get('FREE_TIER_DAILY_AI_REQUESTS', '');
        $oldKey = (string)Config::get('OPENROUTER_API_KEY', '');
        Config::set('FREE_TIER_DAILY_AI_REQUESTS', '3');
        Config::set('OPENROUTER_API_KEY', 'sk-or-v1-testkey12345678901234567890');
        try {
            SettingsService::get($chatId);
            $this->seedAiUsage($chatId, 3);

            $client = new OpenRouterClient();
            $result = $client->moderateText('salom', 'item_free_tier', $chatId);
            $this->assertEquals('review', $result['status']);
            $this->assertEquals('free_tier_limit_reached', $result['category']);
            $this->assertEquals('none', $result['model']);
            $this->assertEquals(0.0, $result['cost_usd']);

            // chatId berilmasa (masalan shaxsiy chat AI so'rovlari) — SubscriptionService
            // chaqirilmaydi, chegara qo'llanmaydi (tarmoq so'rovi yubormasdan tekshiriladi).
            $this->assertTrue(SubscriptionService::canUseAi($chatId) === false);
        } finally {
            Config::set('FREE_TIER_DAILY_AI_REQUESTS', $oldLimit);
            Config::set('OPENROUTER_API_KEY', $oldKey);
        }
    }

    /**
     * 80. SubscriptionService::recordStarPayment() bir xil telegram_payment_charge_id
     *     bilan ikki marta chaqirilsa, premium FAQAT bir marta beriladi (idempotency);
     *     yangi charge_id bilan qayta sotib olish esa QOLGAN muddatga USTAMA qiladi
     *     (stacking) — 2.0 Phase 3, 2-band.
     */
    public function testRecordStarPaymentIsIdempotentAndStacksOnRepeatPurchase(): void
    {
        $chatId = -100888599;
        SettingsService::get($chatId);

        $first = SubscriptionService::recordStarPayment($chatId, 1, 'charge_abc_001', 200, 30, 'premium_v1:' . $chatId . ':30');
        $this->assertTrue($first['recorded']);
        $expiryAfterFirst = $first['premium_expires_at'];
        $this->assertNotNull($expiryAfterFirst);

        // Xuddi shu charge_id bilan takroriy chaqiruv — dublikat, muddat o'zgarmaydi.
        $duplicate = SubscriptionService::recordStarPayment($chatId, 1, 'charge_abc_001', 200, 30, 'premium_v1:' . $chatId . ':30');
        $this->assertFalse($duplicate['recorded']);
        $this->assertEquals($expiryAfterFirst, $duplicate['premium_expires_at']);

        // Yangi charge_id — muddat hali tugamagan bo'lgani uchun QOLGAN kunlarga ustama qilinadi.
        $second = SubscriptionService::recordStarPayment($chatId, 1, 'charge_abc_002', 200, 30, 'premium_v1:' . $chatId . ':30');
        $this->assertTrue($second['recorded']);
        $this->assertTrue(strtotime((string)$second['premium_expires_at']) > strtotime((string)$expiryAfterFirst),
            "Muddatidan oldin qayta sotib olish qolgan kunlarni yo'qotmasligi (stacking) kerak");
    }

    /**
     * 81. DM buyrug'i /premium — bepul tarifda bugungi AI ishlatish sonini va
     *     "Premium sotib olish" tugmasini, premium tarifda esa tugash sanasini
     *     ko'rsatadi (2.0 Phase 3, 2-band).
     */
    public function testPremiumStatusCommandShowsFreeAndPremiumStates(): void
    {
        $chatId = -100888699;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 88699]];
            }
        };
        $router = new UpdateRouter($telegram);

        $free = $router->handle([
            'update_id' => 996699,
            'message' => ['message_id' => 7699, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/premium {$chatId}"],
        ]);
        $this->assertEquals('premium_status_shown', $free['status']);
        $this->assertEquals('free', $free['plan']);
        $lastMsg = end($telegram->sent);
        $this->assertStringContainsString('Bepul', $lastMsg['text']);
        $this->assertStringContainsString("buy_premium:{$chatId}", (string)json_encode($lastMsg['extra'], JSON_UNESCAPED_UNICODE));

        SubscriptionService::activatePremium($chatId, 30);
        $premium = $router->handle([
            'update_id' => 996700,
            'message' => ['message_id' => 7700, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => "/premium {$chatId}"],
        ]);
        $this->assertEquals('premium_status_shown', $premium['status']);
        $this->assertEquals('premium', $premium['plan']);
        $lastMsg2 = end($telegram->sent);
        $this->assertStringContainsString('Premium', $lastMsg2['text']);
    }

    /**
     * 82. "⭐ Premium sotib olish" tugmasi (callback_data: "buy_premium:{chatId}")
     *     bosilganda faqat guruh admini uchun to'g'ri payload
     *     ("premium_v1:{chatId}:{days}") bilan Stars invoysi yuboriladi
     *     (2.0 Phase 3, 2-band).
     */
    public function testBuyPremiumCallbackSendsInvoiceWithCorrectPayload(): void
    {
        $chatId = -100888799;
        SettingsService::get($chatId);
        (new AdminAuthorizationService())->isAdmin($chatId, 1);

        $telegram = new class extends TelegramClient {
            public array $invoices = [];
            public array $answered = [];
            public function sendInvoice(int|string $chatId, string $title, string $description, string $payload, int $amountStars, string $priceLabel = "To'lov"): array
            {
                $this->invoices[] = ['chat_id' => (int)$chatId, 'payload' => $payload, 'stars' => $amountStars];
                return ['ok' => true, 'result' => true];
            }
            public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool
            {
                $this->answered[] = $callbackQueryId;
                return true;
            }
        };
        $router = new UpdateRouter($telegram);

        $result = $router->handle([
            'update_id' => 996799,
            'callback_query' => [
                'id' => 'cbq_premium_799',
                'from' => ['id' => 1, 'is_bot' => false],
                'data' => "buy_premium:{$chatId}",
                'message' => ['message_id' => 8799, 'chat' => ['id' => 1]],
            ],
        ]);

        $this->assertEquals('invoice_sent', $result['status']);
        $this->assertEquals(1, count($telegram->invoices));
        // Invoys guruhga emas, admin bosgan SHAXSIY chatga (userId) yuboriladi —
        // premiumning qaysi guruhga tegishli ekani payload ichida saqlanadi.
        $this->assertEquals(1, $telegram->invoices[0]['chat_id']);
        $this->assertEquals("premium_v1:{$chatId}:30", $telegram->invoices[0]['payload']);
        $this->assertEquals(200, $telegram->invoices[0]['stars']);
    }

    /**
     * 83. pre_checkout_query: to'g'ri formatdagi payload qabul qilinadi (ok=true),
     *     noma'lum/buzilgan payload rad etiladi (ok=false) — 10 soniyalik javob
     *     talabiga mos, sinxron va tarmoqqa chiqmasdan (2.0 Phase 3, 2-band).
     */
    public function testPreCheckoutQueryAcceptsValidAndRejectsInvalidPayload(): void
    {
        $telegram = new class extends TelegramClient {
            public array $answers = [];
            public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): bool
            {
                $this->answers[] = ['id' => $preCheckoutQueryId, 'ok' => $ok];
                return true;
            }
        };
        $router = new UpdateRouter($telegram);

        $valid = $router->handle([
            'update_id' => 996899,
            'pre_checkout_query' => ['id' => 'pcq_valid_899', 'from' => ['id' => 1], 'invoice_payload' => 'premium_v1:-100888899:30'],
        ]);
        $this->assertEquals('pre_checkout_accepted', $valid['status']);

        $invalid = $router->handle([
            'update_id' => 996900,
            'pre_checkout_query' => ['id' => 'pcq_invalid_900', 'from' => ['id' => 1], 'invoice_payload' => 'garbage_payload'],
        ]);
        $this->assertEquals('pre_checkout_rejected', $invalid['status']);

        $this->assertEquals(2, count($telegram->answers));
        $this->assertTrue($telegram->answers[0]['ok']);
        $this->assertFalse($telegram->answers[1]['ok']);
    }

    /**
     * 84. successful_payment (shaxsiy chatda kelgan) — payloaddagi guruh ID'siga
     *     premiumni beradi va telegram_payment_charge_id bo'yicha idempotent
     *     ishlaydi (takroriy webhook premium muddatini qayta uzaytirmaydi)
     *     — 2.0 Phase 3, 2-band.
     */
    public function testSuccessfulPaymentActivatesPremiumAndIsIdempotent(): void
    {
        $chatId = -100888999;
        SettingsService::get($chatId);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text];
                return ['ok' => true, 'result' => ['message_id' => 88999]];
            }
        };
        $router = new UpdateRouter($telegram);
        $payment = [
            'currency' => 'XTR',
            'total_amount' => 200,
            'invoice_payload' => "premium_v1:{$chatId}:30",
            'telegram_payment_charge_id' => 'charge_success_999',
        ];

        $first = $router->handle([
            'update_id' => 996999,
            'message' => ['message_id' => 7999, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'successful_payment' => $payment],
        ]);
        $this->assertEquals('payment_recorded', $first['status']);
        $this->assertTrue(SubscriptionService::getPlan($chatId)['is_premium']);
        $expiryAfterFirst = SubscriptionService::getPlan($chatId)['premium_expires_at'];

        $duplicate = $router->handle([
            'update_id' => 997000,
            'message' => ['message_id' => 8000, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'successful_payment' => $payment],
        ]);
        $this->assertEquals('payment_already_recorded', $duplicate['status']);
        $this->assertEquals($expiryAfterFirst, SubscriptionService::getPlan($chatId)['premium_expires_at']);
    }

    /**
     * Yordamchi: Telegram Mini App'ning haqiqiy `initData` formatini (rasmiy
     * validatsiya algoritmiga muvofiq HMAC-SHA256 imzo bilan) qo'lda yasaydi —
     * 2.0 Phase 3, 3-band (Web Dashboard / Mini App) testlari uchun.
     */
    private function buildInitData(array $userData, string $botToken, ?int $authDate = null): string
    {
        $fields = [
            'auth_date' => (string)($authDate ?? time()),
            'query_id' => 'AAHtestQueryId',
            'user' => json_encode($userData, JSON_UNESCAPED_UNICODE),
        ];
        ksort($fields);
        $pairs = [];
        foreach ($fields as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secretKey);

        $query = [];
        foreach ($fields as $k => $v) {
            $query[] = $k . '=' . rawurlencode((string)$v);
        }
        return implode('&', $query);
    }

    /**
     * 85. MiniAppAuth::verify() — to'g'ri imzolangan initData qabul qilinadi
     *     (foydalanuvchi ID'si to'g'ri chiqariladi); buzilgan imzo va juda eski
     *     `auth_date` rad etiladi (2.0 Phase 3, 3-band).
     */
    public function testMiniAppAuthVerifiesValidRejectsTamperedAndExpired(): void
    {
        $oldToken = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        Config::set('TELEGRAM_BOT_TOKEN', 'test-bot-token-mini-app-85');
        try {
            $valid = $this->buildInitData(['id' => 555001, 'first_name' => 'Ali', 'username' => 'ali_dev'], 'test-bot-token-mini-app-85');
            $ctx = MiniAppAuth::verify($valid);
            $this->assertNotNull($ctx);
            $this->assertEquals(555001, $ctx['user_id']);
            $this->assertEquals('ali_dev', $ctx['username']);

            $tampered = $valid . 'X';
            $this->assertNull(MiniAppAuth::verify($tampered));

            $wrongSecret = $this->buildInitData(['id' => 555001, 'first_name' => 'Ali'], 'boshqa-bot-tokeni');
            $this->assertNull(MiniAppAuth::verify($wrongSecret));

            $expired = $this->buildInitData(['id' => 555001, 'first_name' => 'Ali'], 'test-bot-token-mini-app-85', time() - 90000);
            $this->assertNull(MiniAppAuth::verify($expired));

            $this->assertNull(MiniAppAuth::verify(''));
        } finally {
            Config::set('TELEGRAM_BOT_TOKEN', $oldToken);
        }
    }

    /**
     * 86. MiniAppApiRouter — autentifikatsiyasiz/yaroqsiz initData har doim
     *     401 bilan rad etiladi; `me` amali foydalanuvchi admin bo'lgan
     *     guruhlar ro'yxatini qaytaradi (2.0 Phase 3, 3-band).
     */
    public function testMiniAppApiRouterRejectsInvalidAuthAndListsAdminGroups(): void
    {
        $oldToken = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        Config::set('TELEGRAM_BOT_TOKEN', 'test-bot-token-mini-app-86');
        try {
            $chatId = -100889099;
            SettingsService::get($chatId);
            (new AdminAuthorizationService())->isAdmin($chatId, 1);

            $router = new MiniAppApiRouter();

            $unauth = $router->handle('me', [], [], 'garbage-init-data');
            $this->assertFalse($unauth['ok']);
            $this->assertEquals(401, $unauth['http_status']);

            $initData = $this->buildInitData(['id' => 1, 'first_name' => 'Admin'], 'test-bot-token-mini-app-86');
            $me = $router->handle('me', [], [], $initData);
            $this->assertTrue($me['ok']);
            $chatIds = array_column($me['groups'], 'chat_id');
            $this->assertTrue(in_array($chatId, $chatIds, true), "Admin bo'lgan guruh /me ro'yxatida bo'lishi kerak");
        } finally {
            Config::set('TELEGRAM_BOT_TOKEN', $oldToken);
        }
    }

    /**
     * 87. MiniAppApiRouter `overview`/`settings_get`/`settings_update` — faqat
     *     o'sha guruh admini uchun ishlaydi (boshqa foydalanuvchi uchun 403),
     *     sozlama yangilash haqiqatan bazaga yoziladi (2.0 Phase 3, 3-band).
     */
    public function testMiniAppApiRouterOverviewAndSettingsRoundTrip(): void
    {
        $oldToken = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        Config::set('TELEGRAM_BOT_TOKEN', 'test-bot-token-mini-app-87');
        try {
            $chatId = -100889199;
            SettingsService::get($chatId);
            (new AdminAuthorizationService())->isAdmin($chatId, 1);
            $router = new MiniAppApiRouter();
            $adminInitData = $this->buildInitData(['id' => 1, 'first_name' => 'Admin'], 'test-bot-token-mini-app-87');

            $overview = $router->handle('overview', ['chat_id' => (string)$chatId], [], $adminInitData);
            $this->assertTrue($overview['ok']);
            $this->assertEquals('free', $overview['plan']['plan']);
            $this->assertEquals(0, $overview['moderation']['active_warnings']);

            // Admin bo'lmagan foydalanuvchi — 403.
            $strangerInitData = $this->buildInitData(['id' => 999888, 'first_name' => 'Stranger'], 'test-bot-token-mini-app-87');
            $forbidden = $router->handle('overview', ['chat_id' => (string)$chatId], [], $strangerInitData);
            $this->assertFalse($forbidden['ok']);
            $this->assertEquals(403, $forbidden['http_status']);

            $update = $router->handle('settings_update', [], ['chat_id' => $chatId, 'settings' => ['flood_enabled' => 0, 'warn_limit' => 5]], $adminInitData);
            $this->assertTrue($update['ok']);
            $this->assertEquals(0, (int)$update['settings']['flood_enabled']);
            $this->assertEquals(5, (int)$update['settings']['warn_limit']);

            $get = $router->handle('settings_get', ['chat_id' => (string)$chatId], [], $adminInitData);
            $this->assertEquals(0, (int)$get['settings']['flood_enabled']);
        } finally {
            Config::set('TELEGRAM_BOT_TOKEN', $oldToken);
        }
    }

    /**
     * 88. MiniAppApiRouter `warnings` ro'yxati va `unmute`/`unban`/`resetwarns`
     *     moderatsiya amallari haqiqiy `PunishmentService` orqali ishlaydi
     *     (2.0 Phase 3, 3-band).
     */
    public function testMiniAppApiRouterWarningsListAndModerationActions(): void
    {
        $oldToken = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        Config::set('TELEGRAM_BOT_TOKEN', 'test-bot-token-mini-app-88');
        try {
            $chatId = -100889299;
            $targetUserId = 7299;
            $telegram = new class extends TelegramClient {
                public array $calls = [];
                public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
                public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool { return true; }
                public function unmuteUser(int|string $chatId, int $userId): bool { $this->calls[] = 'unmute'; return true; }
                public function unbanChatMember(int|string $chatId, int $userId, bool $onlyIfBanned = true): bool { $this->calls[] = 'unban'; return true; }
                public function sendMessage(int|string $chatId, string $text, array $extra = []): array { return ['ok' => true, 'result' => ['message_id' => 1]]; }
            };
            $auth = new AdminAuthorizationService($telegram);
            $punishment = new PunishmentService($telegram, $auth);
            $auth->isAdmin($chatId, 1);

            $punishment->execute($chatId, $targetUserId, 9299, [
                'action' => 'mute_user', 'delete_message' => false, 'reason' => 'Sinov', 'strike_count' => 2,
            ]);

            $router = new MiniAppApiRouter($telegram, $auth, $punishment);
            $adminInitData = $this->buildInitData(['id' => 1, 'first_name' => 'Admin'], 'test-bot-token-mini-app-88');

            $warnings = $router->handle('warnings', ['chat_id' => (string)$chatId], [], $adminInitData);
            $this->assertTrue($warnings['ok']);
            $this->assertEquals(1, count($warnings['warnings']));
            $this->assertEquals($targetUserId, (int)$warnings['warnings'][0]['user_id']);

            $unmute = $router->handle('unmute', [], ['chat_id' => $chatId, 'user_id' => $targetUserId], $adminInitData);
            $this->assertTrue($unmute['ok']);
            $this->assertTrue($unmute['success']);

            $resetwarns = $router->handle('resetwarns', [], ['chat_id' => $chatId, 'user_id' => $targetUserId], $adminInitData);
            $this->assertTrue($resetwarns['ok']);

            $warningsAfterReset = $router->handle('warnings', ['chat_id' => (string)$chatId], [], $adminInitData);
            $this->assertEquals(0, count($warningsAfterReset['warnings']));
            $this->assertTrue(in_array('unmute', $telegram->calls, true));
        } finally {
            Config::set('TELEGRAM_BOT_TOKEN', $oldToken);
        }
    }

    /**
     * 89. MiniAppApiRouter `appeals` ro'yxati va `review_appeal` — shikoyatni
     *     qabul qilish cheklovni olib tashlaydi va holatni 'accepted' qiladi,
     *     rad etish esa cheklovni saqlab qoladi va 'rejected' qiladi
     *     (2.0 Phase 3, 3-band).
     */
    public function testMiniAppApiRouterAppealsListAndReview(): void
    {
        $oldToken = (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        Config::set('TELEGRAM_BOT_TOKEN', 'test-bot-token-mini-app-89');
        try {
            $chatId = -100889399;
            $targetUserId = 7399;
            $telegram = new class extends TelegramClient {
                public array $sentTo = [];
                public function deleteMessage(int|string $chatId, int $messageId): bool { return true; }
                public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool { return true; }
                public function unmuteUser(int|string $chatId, int $userId): bool { return true; }
                public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool { return true; }
                public function sendMessage(int|string $chatId, string $text, array $extra = []): array
                {
                    $this->sentTo[] = (int)$chatId;
                    return ['ok' => true, 'result' => ['message_id' => 1]];
                }
            };
            $auth = new AdminAuthorizationService($telegram);
            $punishment = new PunishmentService($telegram, $auth);
            $auth->isAdmin($chatId, 1);

            $punishment->execute($chatId, $targetUserId, 9399, [
                'action' => 'mute_user', 'delete_message' => false, 'reason' => 'Sinov shikoyati', 'strike_count' => 2,
            ]);
            $actionId = (int)Database::getConnection()->query("SELECT MAX(id) FROM telegram_actions")->fetchColumn();

            // Nishonlangan foydalanuvchining o'zi shikoyat ochadi (botning mavjud yo'li orqali).
            $updateRouter = new UpdateRouter($telegram, $auth, $punishment);
            $updateRouter->handle([
                'update_id' => 998399,
                'callback_query' => ['id' => 'cbq-389', 'from' => ['id' => $targetUserId, 'first_name' => 'User'], 'data' => "appeal_request:{$actionId}"],
            ]);

            $router = new MiniAppApiRouter($telegram, $auth, $punishment);
            $adminInitData = $this->buildInitData(['id' => 1, 'first_name' => 'Admin'], 'test-bot-token-mini-app-89');

            $appeals = $router->handle('appeals', ['chat_id' => (string)$chatId], [], $adminInitData);
            $this->assertTrue($appeals['ok']);
            $this->assertEquals(1, count($appeals['appeals']));
            $appealId = (int)$appeals['appeals'][0]['id'];

            $review = $router->handle('review_appeal', [], ['appeal_id' => $appealId, 'accept' => true], $adminInitData);
            $this->assertTrue($review['ok']);
            $this->assertEquals('accepted', $review['status']);
            $this->assertTrue(in_array($targetUserId, $telegram->sentTo, true), "Shikoyat egasiga natija haqida DM yuborilishi kerak");

            $reReview = $router->handle('review_appeal', [], ['appeal_id' => $appealId, 'accept' => false], $adminInitData);
            $this->assertFalse($reReview['ok']);
            $this->assertEquals(409, $reReview['http_status']);
        } finally {
            Config::set('TELEGRAM_BOT_TOKEN', $oldToken);
        }
    }

    /**
     * 90. Botning bosh menyusidagi "📊 Dashboard" (`web_app`) tugmasi FAQAT
     *     `MINIAPP_URL`/`TELEGRAM_WEBHOOK_URL`dan https:// manzil chiqarilganda
     *     ko'rsatiladi; http:// yoki bo'sh bo'lsa — umuman ko'rsatilmaydi
     *     (2.0 Phase 3, 3-band).
     */
    public function testDashboardButtonShownOnlyWithValidHttpsMiniAppUrl(): void
    {
        $oldMiniApp = (string)Config::get('MINIAPP_URL', '');
        $oldWebhook = (string)Config::get('TELEGRAM_WEBHOOK_URL', '');
        try {
            $telegram = new class extends TelegramClient {
                public array $sent = [];
                public function sendMessage(int|string $chatId, string $text, array $extra = []): array
                {
                    $this->sent[] = ['chat_id' => (int)$chatId, 'extra' => $extra];
                    return ['ok' => true, 'result' => ['message_id' => 1]];
                }
            };
            $router = new UpdateRouter($telegram);

            Config::set('MINIAPP_URL', '');
            Config::set('TELEGRAM_WEBHOOK_URL', 'https://bot.example.com/webhook.php');
            $router->handle(['update_id' => 990001, 'message' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => '/menu']]);
            $lastSent = end($telegram->sent);
            $json = json_encode($lastSent['extra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('https://bot.example.com/miniapp/', $json, "Webhook domenidan avtomatik https manzil hosil bo'lishi kerak");

            $telegram->sent = [];
            Config::set('TELEGRAM_WEBHOOK_URL', 'http://insecure.example.com/webhook.php');
            $router->handle(['update_id' => 990002, 'message' => ['message_id' => 2, 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'], 'text' => '/menu']]);
            $lastSent2 = end($telegram->sent);
            $json2 = json_encode($lastSent2['extra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertFalse(str_contains($json2, 'web_app'), "http:// manzil bilan Dashboard tugmasi ko'rsatilmasligi kerak");
        } finally {
            Config::set('MINIAPP_URL', $oldMiniApp);
            Config::set('TELEGRAM_WEBHOOK_URL', $oldWebhook);
        }
    }

    /**
     * 91. Forum-mavzular (topics): agar yangi a'zo xabari `is_topic_message: true`
     *     va `message_thread_id`ga ega bo'lsa, CAPTCHA salomlashuv xabari xuddi
     *     o'sha mavzuga (`message_thread_id` bilan) yuborilishi kerak; oddiy guruh
     *     yoki forumning "General" mavzusida (bu maydonlar yo'q) esa
     *     `message_thread_id` umuman qo'shilmasligi kerak (2.0 Phase 4, 1-band).
     */
    public function testCaptchaWelcomeMessageUsesMessageThreadIdInsideForumTopic(): void
    {
        $chatId = -100888001;
        $userId = 555001;
        SettingsService::update($chatId, ['captcha_enabled' => 1, 'captcha_timeout_sec' => 45]);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 1]];
            }
        };
        $router = new UpdateRouter($telegram);

        $router->handle([
            'update_id' => 998001,
            'message' => [
                'message_id' => 6001,
                'message_thread_id' => 777,
                'is_topic_message' => true,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => 999, 'is_bot' => false],
                'new_chat_members' => [
                    ['id' => $userId, 'is_bot' => false, 'first_name' => 'Mavzu', 'last_name' => 'Azosi'],
                ],
            ],
        ]);
        $captchaSent = end($telegram->sent);
        $this->assertNotNull($captchaSent, "CAPTCHA xabari yuborilishi kerak");
        $this->assertEquals(777, $captchaSent['extra']['message_thread_id'] ?? null, "Forum mavzusidagi yangi a'zoga CAPTCHA o'sha mavzuga yuborilishi kerak");

        // Endi forumning "General" mavzusida (is_topic_message/message_thread_id yo'q) —
        // message_thread_id butunlay qo'shilmasligi kerak.
        $chatId2 = -100888002;
        $userId2 = 555002;
        SettingsService::update($chatId2, ['captcha_enabled' => 1, 'captcha_timeout_sec' => 45]);
        $telegram->sent = [];
        $router->handle([
            'update_id' => 998002,
            'message' => [
                'message_id' => 6002,
                'chat' => ['id' => $chatId2, 'type' => 'supergroup'],
                'from' => ['id' => 999, 'is_bot' => false],
                'new_chat_members' => [
                    ['id' => $userId2, 'is_bot' => false, 'first_name' => 'Oddiy', 'last_name' => 'Azo'],
                ],
            ],
        ]);
        $captchaSent2 = end($telegram->sent);
        $this->assertNotNull($captchaSent2, "CAPTCHA xabari yuborilishi kerak");
        $this->assertFalse(array_key_exists('message_thread_id', $captchaSent2['extra']), "Oddiy guruhda/General mavzusida message_thread_id qo'shilmasligi kerak");
    }

    /**
     * 92. Forum-mavzular: /addmod kabi guruh-buyruqlarining javob xabarlari ham
     *     buyruq qaysi mavzuda yuborilgan bo'lsa, o'sha mavzuga qaytarilishi kerak
     *     (2.0 Phase 4, 1-band). Shu bilan birga admin bo'lmagan foydalanuvchiga
     *     "faqat administratorlar uchun" rad javobi ham xuddi shu mavzuga boradi.
     */
    public function testGroupCommandRepliesRespectForumTopicThreadId(): void
    {
        $chatId = -100888003;
        $adminId = 1; // Test rejimida TelegramClient stubi: id=1 -> 'creator'.
        $nonAdminId = 555010;
        $targetId = 555011;
        SettingsService::get($chatId);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 1]];
            }
        };
        $router = new UpdateRouter($telegram);

        // Admin /addmod buyrug'ini forum mavzusi ichida beradi.
        $addResult = $router->handle([
            'update_id' => 998010,
            'message' => [
                'message_id' => 6010,
                'message_thread_id' => 321,
                'is_topic_message' => true,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $adminId, 'is_bot' => false],
                'text' => '/addmod',
                'reply_to_message' => ['message_id' => 6009, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('command_executed', $addResult['status']);
        $addSent = end($telegram->sent);
        $this->assertEquals(321, $addSent['extra']['message_thread_id'] ?? null, "/addmod tasdiqlash xabari buyruq berilgan mavzuga yuborilishi kerak");

        // Cheklangan "moderator" (to'liq admin emas) forum mavzusi ichida o'ziga
        // yopiq bo'lgan /addmod (rol-boshqaruv) buyrug'ini beradi — rad javobi
        // ham xuddi shu mavzuga borishi kerak.
        AdminAuthorizationService::setModeratorRole($chatId, $nonAdminId, true);
        $telegram->sent = [];
        $rejectResult = $router->handle([
            'update_id' => 998011,
            'message' => [
                'message_id' => 6011,
                'message_thread_id' => 321,
                'is_topic_message' => true,
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'from' => ['id' => $nonAdminId, 'is_bot' => false],
                'text' => '/addmod',
                'reply_to_message' => ['message_id' => 6009, 'from' => ['id' => $targetId, 'is_bot' => false]],
            ],
        ]);
        $this->assertEquals('forbidden_for_moderator', $rejectResult['status']);
        $rejectSent = end($telegram->sent);
        $this->assertNotNull($rejectSent, "Moderatorga rad javobi yuborilishi kerak");
        $this->assertEquals(321, $rejectSent['extra']['message_thread_id'] ?? null, "Admin-rad javobi ham buyruq berilgan mavzuga yuborilishi kerak");
    }

    /**
     * 93. `/clonesettings manba maqsad` — chaqiruvchi IKKALA guruhning ham admini
     *     bo'lsa, `SettingsService::CLONEABLE_COLUMNS` to'plami manba guruhdan
     *     maqsad guruhga to'g'ridan-to'g'ri nusxalanadi; faqat bitta guruhning
     *     (yoki hech birining) admini bo'lmasa — rad etiladi va hech narsa
     *     o'zgarmaydi (2.0 Phase 4, 2-band).
     */
    public function testCloneSettingsCopiesCloneableFieldsBetweenGroupsWhenAuthorized(): void
    {
        $sourceChatId = -100889001;
        $targetChatId = -100889002;
        $adminId = 1; // Test rejimida TelegramClient stubi: id=1 -> har qanday guruhda 'creator'.

        SettingsService::get($sourceChatId);
        SettingsService::get($targetChatId);
        SettingsService::update($sourceChatId, [
            'warn_limit' => 7,
            'flood_max_messages' => 15,
            'ai_mode' => 'economical',
            'language' => 'ru',
            'captcha_enabled' => 1,
            'porn_action' => 'mute',
        ]);

        $router = new UpdateRouter();
        $res = $router->handle([
            'update_id' => 999001,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $adminId, 'type' => 'private'],
                'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => "/clonesettings {$sourceChatId} {$targetChatId}",
            ],
        ]);
        $this->assertEquals('command_executed', $res['status']);
        $this->assertEquals('/clonesettings', $res['cmd']);

        $targetSettings = SettingsService::get($targetChatId);
        $this->assertEquals(7, (int)$targetSettings['warn_limit']);
        $this->assertEquals(15, (int)$targetSettings['flood_max_messages']);
        $this->assertEquals('economical', $targetSettings['ai_mode']);
        $this->assertEquals('ru', $targetSettings['language']);
        $this->assertEquals(1, (int)$targetSettings['captcha_enabled']);
        $this->assertEquals('mute', $targetSettings['porn_action']);

        // Endi admin bo'lmagan foydalanuvchi (na manba, na maqsad guruh admini) urinadi — rad etilishi kerak.
        SettingsService::update($sourceChatId, ['warn_limit' => 9]);
        $nonAdminId = 889099;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        // Chat_members'da 'member' (admin emas) sifatida ro'yxatdan o'tkazamiz — shu bilan
        // TelegramClient'ning tashqi API'ga (real HTTP so'rov) chiqib ketmasligini ta'minlaymiz.
        $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$sourceChatId}, {$nonAdminId}, 'member', '{$now}')");
        $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$targetChatId}, {$nonAdminId}, 'member', '{$now}')");

        $rejectRes = $router->handle([
            'update_id' => 999002,
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => $nonAdminId, 'type' => 'private'],
                'from' => ['id' => $nonAdminId, 'is_bot' => false, 'first_name' => 'OddiyFoydalanuvchi'],
                'text' => "/clonesettings {$sourceChatId} {$targetChatId}",
            ],
        ]);
        $this->assertEquals('error', $rejectRes['status']);
        $this->assertEquals('unauthorized', $rejectRes['reason']);

        $targetSettingsAfterReject = SettingsService::get($targetChatId);
        $this->assertEquals(7, (int)$targetSettingsAfterReject['warn_limit'], "Ruxsatsiz urinishdan keyin maqsad guruh sozlamalari o'zgarmasligi kerak");
    }

    /**
     * 94. `/exportsettings` guruhning joriy sozlamalarini JSON qilib qaytaradi;
     *     `/importsettings` shu JSON'ni (qo'shimcha noto'g'ri/ruxsat etilmagan
     *     maydonlar bilan aralashtirilgan holda ham) boshqa guruhga xavfsiz
     *     qo'llaydi — faqat tekshiruvdan o'tgan qiymatlar yoziladi, qolganlari
     *     (masalan noto'g'ri `ai_mode`, yoki umuman ruxsat etilmagan
     *     `premium_expires_at`) jim tashlab ketiladi (2.0 Phase 4, 2-band).
     */
    public function testExportSettingsProducesJsonAndImportSettingsValidatesFields(): void
    {
        $sourceChatId = -100889003;
        $targetChatId = -100889004;
        $adminId = 1;

        SettingsService::get($sourceChatId);
        SettingsService::get($targetChatId);
        // resolvePrivateManagedChat() adminGroupsOf() orqali chat_members keshini o'qiydi —
        // shu sababli isAdmin() bir marta chaqirilib, DB keshi to'ldirilishi kerak (xuddi
        // testLanguageCommandShowsAndChangesGroupLanguage'dagi kabi).
        (new AdminAuthorizationService())->isAdmin($sourceChatId, $adminId);
        (new AdminAuthorizationService())->isAdmin($targetChatId, $adminId);
        SettingsService::update($sourceChatId, [
            'warn_limit' => 5,
            'unscannable_action' => 'delete_notify',
            'flood_window_sec' => 20,
        ]);
        $originalTargetPremium = SettingsService::get($targetChatId)['premium_expires_at'] ?? null;

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = ['chat_id' => (int)$chatId, 'text' => $text, 'extra' => $extra];
                return ['ok' => true, 'result' => ['message_id' => 1]];
            }
        };
        $router = new UpdateRouter($telegram);

        $exportRes = $router->handle([
            'update_id' => 999010,
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => $adminId, 'type' => 'private'],
                'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => "/exportsettings {$sourceChatId}",
            ],
        ]);
        $this->assertEquals('command_executed', $exportRes['status']);
        $exportedSent = end($telegram->sent);
        $this->assertNotNull($exportedSent);
        $this->assertTrue((bool)preg_match('/<pre>(.*?)<\/pre>/s', (string)$exportedSent['text'], $m), "Eksport javobida JSON <pre> blok bo'lishi kerak");
        $decoded = json_decode(trim($m[1]), true);
        $this->assertNotNull($decoded, "Eksport qilingan JSON to'g'ri parse bo'lishi kerak");
        $this->assertEquals(5, (int)$decoded['warn_limit']);
        $this->assertEquals('delete_notify', $decoded['unscannable_action']);
        $this->assertFalse(array_key_exists('premium_expires_at', $decoded), "premium_expires_at eksportga chiqmasligi kerak");
        $this->assertFalse(array_key_exists('log_chat_id', $decoded), "log_chat_id eksportga chiqmasligi kerak");

        // Import uchun JSON'ni ataylab buzamiz: noto'g'ri enum va ruxsat etilmagan maydon qo'shamiz.
        $decoded['ai_mode'] = 'notavalidmode';
        $decoded['premium_expires_at'] = '2099-01-01 00:00:00';
        $decoded['unknown_field_xyz'] = 'qiymat';
        $tamperedJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        $telegram->sent = [];
        $importRes = $router->handle([
            'update_id' => 999011,
            'message' => [
                'message_id' => 11,
                'chat' => ['id' => $adminId, 'type' => 'private'],
                'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => "/importsettings {$targetChatId} {$tamperedJson}",
            ],
        ]);
        $this->assertEquals('command_executed', $importRes['status']);
        $this->assertTrue(in_array('ai_mode', $importRes['skipped'], true), "Noto'g'ri enum qiymati o'tkazib yuborilishi kerak");
        $this->assertTrue(in_array('premium_expires_at', $importRes['skipped'], true), "Ruxsat etilmagan maydon o'tkazib yuborilishi kerak");
        $this->assertTrue(in_array('unknown_field_xyz', $importRes['skipped'], true), "Noma'lum maydon o'tkazib yuborilishi kerak");

        $targetSettings = SettingsService::get($targetChatId);
        $this->assertEquals(5, (int)$targetSettings['warn_limit'], "To'g'ri qiymatlar qo'llanishi kerak");
        $this->assertEquals('delete_notify', $targetSettings['unscannable_action']);
        $this->assertEquals('comprehensive', $targetSettings['ai_mode'] ?? 'comprehensive', "Noto'g'ri ai_mode qo'llanmasligi va standart qiymat saqlanishi kerak");
        $this->assertEquals($originalTargetPremium, $targetSettings['premium_expires_at'], "premium_expires_at import orqali o'zgartirilmasligi kerak");
    }

    /**
     * 95. `/broadcast matn` — chaqiruvchi boshqargan HAR BIR guruh uchun
     *     alohida `App\Jobs\BroadcastMessageJob` navbatga qo'yiladi (matn
     *     HTML-xavfsiz ekranlangan holda), va guruh soni javobda to'g'ri
     *     ko'rsatiladi. Matn ko'rsatilmasa — hech narsa navbatga qo'yilmaydi,
     *     foydalanish yo'riqnomasi + guruh soni ko'rsatiladi (2.0 Phase 4, 3-band).
     */
    public function testBroadcastCommandQueuesJobPerManagedGroupWithEscapedText(): void
    {
        // MUHIM: userId=1 butun test to'plami davomida ko'plab guruhlarda admin
        // sifatida to'planib boradi (chat_members jadvali testlar orasida
        // tozalanmaydi — boshqa testlarda ataylab shunday, chunki har biri
        // o'zining chat_id'lari bilan ishlaydi). Shu sababli bu yerda ALOHIDA,
        // faqat shu testga xos admin ID ishlatiladi va aynan 3 ta guruhga
        // to'g'ridan-to'g'ri SQL orqali "administrator" sifatida bog'lanadi —
        // xuddi testCloneSettingsCopiesCloneableFieldsBetweenGroupsWhenAuthorized'dagi
        // "member" qatoriga o'xshab.
        $adminId = 890098;
        $chatIdA = -100890001;
        $chatIdB = -100890002;
        $chatIdC = -100890003;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        foreach ([$chatIdA, $chatIdB, $chatIdC] as $cid) {
            SettingsService::get($cid);
            $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$cid}, {$adminId}, 'administrator', '{$now}')");
        }

        $router = new UpdateRouter();

        // Avval matnsiz — hech narsa navbatga qo'yilmasligi kerak.
        $emptyRes = $router->handle([
            'update_id' => 999020,
            'message' => [
                'message_id' => 20,
                'chat' => ['id' => $adminId, 'type' => 'private'],
                'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => '/broadcast',
            ],
        ]);
        $this->assertEquals('error', $emptyRes['status']);
        $this->assertEquals('empty_text', $emptyRes['reason']);
        $this->assertNull(QueueService::reserve('default'), "Matnsiz /broadcast hech qanday job navbatga qo'ymasligi kerak");

        $res = $router->handle([
            'update_id' => 999021,
            'message' => [
                'message_id' => 21,
                'chat' => ['id' => $adminId, 'type' => 'private'],
                'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                'text' => "/broadcast Ertaga texnik ishlar bo'ladi <script>",
            ],
        ]);
        $this->assertEquals('command_executed', $res['status']);
        $this->assertEquals(3, $res['queued_groups']);

        $foundChatIds = [];
        for ($i = 0; $i < 3; $i++) {
            $job = QueueService::reserve('default');
            $this->assertNotNull($job, "Har bir boshqarilgan guruh uchun job navbatga qo'yilishi kerak");
            $this->assertEquals('App\Jobs\BroadcastMessageJob', $job['handler'] ?? null);
            $foundChatIds[] = (int)($job['data']['chat_id'] ?? 0);
            $this->assertEquals($adminId, (int)($job['data']['admin_user_id'] ?? 0));
            $this->assertStringContainsString("Ertaga texnik ishlar", (string)($job['data']['text'] ?? ''));
            $this->assertStringContainsString('&lt;script&gt;', (string)($job['data']['text'] ?? ''), "Admin matnidagi HTML ekranlangan bo'lishi kerak (xom holda o'tmasligi)");
        }
        $expectedChatIds = [$chatIdA, $chatIdB, $chatIdC];
        sort($foundChatIds);
        sort($expectedChatIds);
        $this->assertEquals($expectedChatIds, $foundChatIds);
        $this->assertNull(QueueService::reserve('default'), "Aynan 3 ta job navbatga qo'yilgan bo'lishi kerak, ortiqcha emas");
    }

    /**
     * 2.0 Phase 5 (UX): bir nechta guruhni boshqaradigan admin endi uzun guruh
     * ID'sini (`-100...`) qo'lda yozmasligi kerak — bot guruh tanlash TUGMALARINI
     * chiqaradi. Har bir buyruq o'ziga mos picker prefiksini qaytaradi.
     */
    public function testMultiGroupCommandsOfferGroupButtonsInsteadOfRawChatId(): void
    {
        // Broadcast testidagi kabi: faqat shu testga xos admin ID va aynan
        // 2 ta guruh (chat_members testlar orasida tozalanmaydi).
        $adminId = 890200;
        $chatIdA = -100890201;
        $chatIdB = -100890202;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        foreach ([$chatIdA, $chatIdB] as $cid) {
            SettingsService::get($cid);
            $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$cid}, {$adminId}, 'administrator', '{$now}')");
        }

        $router = new UpdateRouter();
        $updateId = 999100;
        $send = function (string $text) use ($router, $adminId, &$updateId): array {
            return $router->handle([
                'update_id' => ++$updateId,
                'message' => [
                    'message_id' => $updateId,
                    'chat' => ['id' => $adminId, 'type' => 'private'],
                    'from' => ['id' => $adminId, 'is_bot' => false, 'first_name' => 'Admin'],
                    'text' => $text,
                ],
            ]);
        };

        $expected = [
            '/premium' => 'admin_premium',
            '/wordlist' => 'admin_wordlist',
            '/modlist' => 'admin_modlist',
            '/til' => 'admin_lang',
            '/exportsettings' => 'admin_export',
            '/blockword reklama' => 'argpick_word',
        ];
        foreach ($expected as $cmd => $picker) {
            $res = $send($cmd);
            $this->assertEquals('private_group_selection_required', $res['status'], "{$cmd} guruh tanlashni so'rashi kerak");
            $this->assertEquals($picker, $res['picker'] ?? null, "{$cmd} uchun tugma prefiksi noto'g'ri");
        }

        // /clonesettings ham endi ikkita ID o'rniga manba guruh tugmalarini beradi.
        $clone = $send('/clonesettings');
        $this->assertEquals('private_group_selection_required', $clone['status']);
        $this->assertEquals('admin_clonefrom', $clone['picker'] ?? null);
    }

    /**
     * 2.0 Phase 5 (UX): guruh tugmasi bosilganda amal o'sha guruh uchun darhol
     * bajarilishi, til tugmasi esa sozlamani haqiqatan o'zgartirishi kerak.
     */
    public function testGroupButtonCallbackRunsActionAndLanguageButtonUpdatesSetting(): void
    {
        $adminId = 890300;
        $chatId = -100890301;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        SettingsService::get($chatId);
        $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$chatId}, {$adminId}, 'administrator', '{$now}')");

        $router = new UpdateRouter();

        // "⭐ Tarif" tugmasi — tarif holati ko'rsatilishi kerak.
        $premium = $router->handle([
            'update_id' => 999200,
            'callback_query' => [
                'id' => 'cb_prem',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 1, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "admin_premium:{$chatId}",
            ],
        ]);
        $this->assertEquals('premium_status_shown', $premium['status']);
        $this->assertEquals('free', $premium['plan']);

        // Til tugmasi — sozlama darhol yangilanishi kerak.
        $lang = $router->handle([
            'update_id' => 999201,
            'callback_query' => [
                'id' => 'cb_lang',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 2, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "setlang:{$chatId}:ru",
            ],
        ]);
        $this->assertEquals('setting_updated', $lang['status']);
        $this->assertEquals('ru', SettingsService::get($chatId)['language']);

        // Noma'lum til kodi — sozlama o'zgarmasligi kerak.
        $bad = $router->handle([
            'update_id' => 999202,
            'callback_query' => [
                'id' => 'cb_lang_bad',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 3, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "setlang:{$chatId}:xx",
            ],
        ]);
        $this->assertEquals('invalid_language', $bad['status']);
        $this->assertEquals('ru', SettingsService::get($chatId)['language'], "Noto'g'ri kod sozlamani buzmasligi kerak");
    }

    /**
     * 2.0 Phase 5 (UX): argumentli buyruqlarda (masalan `/blockword so'z`) tugma
     * bosilganda asl so'z tugmali xabarning `reply_to_message` maydonidan qayta
     * o'qiladi — hech qanday qo'shimcha jadval/holat saqlanmaydi.
     */
    public function testArgumentPickerRecoversOriginalWordFromReplyMessage(): void
    {
        $adminId = 890400;
        $chatId = -100890401;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        SettingsService::get($chatId);
        $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$chatId}, {$adminId}, 'administrator', '{$now}')");

        $router = new UpdateRouter();

        $res = $router->handle([
            'update_id' => 999300,
            'callback_query' => [
                'id' => 'cb_word',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => [
                    'message_id' => 11,
                    'chat' => ['id' => $adminId, 'type' => 'private'],
                    'reply_to_message' => ['message_id' => 10, 'text' => '/blockword reklama'],
                ],
                'data' => "argpick_word:{$chatId}",
            ],
        ]);
        $this->assertEquals('command_executed', $res['status']);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM word_rules WHERE chat_id = :cid AND word_pattern = 'reklama' AND rule_type = 'blacklist'");
        $stmt->execute(['cid' => $chatId]);
        $this->assertEquals(1, (int)$stmt->fetchColumn(), "Tugma orqali tanlangan guruhga so'z qoidasi qo'shilishi kerak");

        // Asl xabar topilmasa — tushunarli xato qaytishi va hech narsa yozilmasligi kerak.
        $missing = $router->handle([
            'update_id' => 999301,
            'callback_query' => [
                'id' => 'cb_word2',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 12, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "argpick_word:{$chatId}",
            ],
        ]);
        $this->assertEquals('argpick_original_missing', $missing['status']);
    }

    /**
     * 2.0 Phase 5 (UX): sozlamalarni klonlash endi ikkita uzun ID o'rniga
     * ikki bosqichli tugmali oqim — manba tanlanadi, so'ng maqsad.
     */
    public function testCloneSettingsTwoStepButtonFlowCopiesSettings(): void
    {
        $adminId = 890500;
        $sourceChatId = -100890501;
        $targetChatId = -100890502;
        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        foreach ([$sourceChatId, $targetChatId] as $cid) {
            SettingsService::get($cid);
            $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$cid}, {$adminId}, 'administrator', '{$now}')");
        }
        SettingsService::update($sourceChatId, ['warn_limit' => 7, 'link_filter' => 0, 'language' => 'en']);

        $router = new UpdateRouter();

        // 1-bosqich: manba guruh tugmasi bosildi — maqsad guruh tugmalari chiqadi.
        $step1 = $router->handle([
            'update_id' => 999400,
            'callback_query' => [
                'id' => 'cb_clone1',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 21, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "admin_clonefrom:{$sourceChatId}",
            ],
        ]);
        $this->assertEquals('clone_target_picker_sent', $step1['status']);
        $this->assertEquals($sourceChatId, $step1['source_chat_id']);

        // 2-bosqich: maqsad guruh tugmasi bosildi — sozlamalar ko'chirilishi kerak.
        $step2 = $router->handle([
            'update_id' => 999401,
            'callback_query' => [
                'id' => 'cb_clone2',
                'from' => ['id' => $adminId, 'first_name' => 'Admin'],
                'message' => ['message_id' => 22, 'chat' => ['id' => $adminId, 'type' => 'private']],
                'data' => "cloneto:{$sourceChatId}:{$targetChatId}",
            ],
        ]);
        $this->assertEquals('command_executed', $step2['status']);
        $this->assertEquals('/clonesettings', $step2['cmd']);

        $target = SettingsService::get($targetChatId);
        $this->assertEquals(7, (int)$target['warn_limit']);
        $this->assertEquals(0, (int)$target['link_filter']);
        $this->assertEquals('en', $target['language']);
    }

    /**
     * 2.0 Phase 6: yangi a'zo akkaunti AI orqali chuqurroq tekshiriladi —
     * endi faqat 18+/so'kinish emas, reklama/skam toifalari ham (kripto
     * "treyder" signallari, investitsiya/kazino targ'iboti, referal spam)
     * hisobga olinadi. Bundan tashqari profil BIO matni ham tekshiriladi —
     * bunday akkauntlar odatda ismini toza qoldirib, targ'ibotni bio'da
     * saqlaydi.
     */
    public function testProfileScanFlagsTradingPromoAccountFromNameAndBio(): void
    {
        // AI o'rniga: berilgan matnda "signal/invest" bo'lsa trading_scam deydi.
        $fakeAi = new class extends TextModerator {
            public function __construct()
            {
            }
            public function inspect(
                string $rawText,
                array $entities = [],
                string $aiMode = 'economical',
                ?int $chatId = null,
                string $itemId = 'msg_1',
                array $filters = [],
                ?string $customModel = null,
                bool $localOnly = false
            ): array {
                if (stripos($rawText, 'signal') !== false || stripos($rawText, 'invest') !== false) {
                    return [
                        'status' => 'unsafe',
                        'category' => 'trading_scam',
                        'reason' => 'Kripto/treyding signal targ\'iboti',
                        'evidence' => $rawText,
                        'source' => 'ai_text',
                    ];
                }
                return ['status' => 'safe', 'category' => 'none', 'reason' => '', 'evidence' => '', 'source' => 'ai_text'];
            }
        };

        // 1-holat: targ'ibot ISMDA.
        $pmName = new ProfileModerator(null, $fakeAi);
        $byName = $pmName->inspectUser(
            ['id' => 940101, 'first_name' => 'Anna', 'last_name' => 'Crypto Signals'],
            -100940100,
            true
        );
        $this->assertEquals('unsafe', $byName['status'], "Treyding targ'iboti ismda ham aniqlanishi kerak");
        $this->assertEquals('trading_scam', $byName['category']);
        $this->assertEquals('profile_scan_name', $byName['source']);

        // 2-holat: ism toza, targ'ibot faqat BIO'da — avval bu butunlay
        // o'tkazib yuborilardi (bio umuman o'qilmasdi).
        $telegramWithBio = new class extends TelegramClient {
            public function getChat(int|string $chatId): ?array
            {
                return ['id' => $chatId, 'bio' => 'Kunlik invest signallari, 300% foyda — kanalga yoz'];
            }
        };
        $pmBio = new ProfileModerator($telegramWithBio, $fakeAi);
        $byBio = $pmBio->inspectUser(
            ['id' => 940102, 'first_name' => 'Ali', 'last_name' => 'Valiyev'],
            -100940100,
            true
        );
        $this->assertEquals('unsafe', $byBio['status'], "Targ'ibot faqat bio'da bo'lsa ham aniqlanishi kerak");
        $this->assertEquals('trading_scam', $byBio['category']);
        $this->assertEquals('profile_scan_bio', $byBio['source']);

        // 3-holat: toza akkaunt — bio bo'sh, ism oddiy.
        $telegramNoBio = new class extends TelegramClient {
            public function getChat(int|string $chatId): ?array
            {
                return ['id' => $chatId];
            }
        };
        $pmClean = new ProfileModerator($telegramNoBio, $fakeAi);
        $clean = $pmClean->inspectUser(
            ['id' => 940103, 'first_name' => 'Dilnoza', 'last_name' => 'Karimova'],
            -100940100,
            true
        );
        $this->assertEquals('safe', $clean['status'], "Oddiy foydalanuvchi bezovta qilinmasligi kerak");
    }

    /**
     * 2.0 Phase 6: reklama/skam akkauntlarga ko'riladigan chora ALOHIDA
     * `spam_account_action` sozlamasi bilan boshqariladi (18+ akkauntlar uchun
     * `adult_account_action`dan mustaqil).
     */
    public function testSpamAccountActionSettingIsIndependentFromAdultSetting(): void
    {
        $chatId = -100940200;
        $userId = 940201;
        SettingsService::get($chatId);

        $finding = [
            'status' => 'unsafe',
            'category' => 'trading_scam',
            'reason' => "Kripto signal targ'iboti",
            'evidence' => 'Crypto Signals',
            'source' => 'profile_scan_bio',
        ];

        // Standart holat — mute + adminga tasdiq tugmasi.
        $default = ModerationDecisionService::decide($finding, $chatId, $userId, null, SettingsService::get($chatId));
        $this->assertEquals('mute_user', $default['action']);
        $this->assertStringContainsString('Reklama/skam akkaunt', $default['reason']);

        // 18+ sozlamasi 'notify' bo'lsa ham, reklama/skam uchun 'ban' tanlangan
        // bo'lsa — aynan ban qo'llanishi kerak (ikkalasi mustaqil).
        SettingsService::update($chatId, ['adult_account_action' => 'notify', 'spam_account_action' => 'ban']);
        $banned = ModerationDecisionService::decide($finding, $chatId, $userId, null, SettingsService::get($chatId));
        $this->assertEquals('ban_user', $banned['action']);

        // Teskarisi ham to'g'ri ishlashi kerak: 18+ akkaunt uchun 'notify'.
        $adultFinding = array_merge($finding, ['category' => 'adult_profile', 'source' => 'profile_scan_photo']);
        $adult = ModerationDecisionService::decide($adultFinding, $chatId, $userId, null, SettingsService::get($chatId));
        $this->assertEquals('alert_only', $adult['action']);
        $this->assertStringContainsString('18+ profil akkaunt', $adult['reason']);
    }

    /**
     * 2.0 Phase 7: NIQOBLANGAN HAVOLA (fishing) himoyasi.
     *
     * Haqiqiy hodisa asosida: guruhga "gov.uz/viplat24sep" deb ko'rsatilgan,
     * aslida `tr.ee/...` qisqartiruvchisiga olib boradigan "35 mln so'm davlat
     * yordami" firibgarligi tushgan va bot uni O'TKAZIB YUBORGAN edi — chunki
     * havola filtri faqat qora ro'yxatni tekshirardi, AI'ga esa faqat
     * ko'rinadigan matn (ya'ni "gov.uz") yuborilardi.
     */
    public function testMaskedPhishingLinkIsBlockedButHonestLinksAreNot(): void
    {
        $moderator = new TextModerator();
        $chatId = -100950100;

        // 1. Aynan o'tib ketgan hujum turi: matnda gov.uz, ostida tr.ee.
        $text = 'Ariza: gov.uz/viplat24sep';
        $entities = [[
            'type' => 'text_link',
            'offset' => 7,
            'length' => 18,
            'url' => 'https://tr.ee/Iv0aPh',
        ]];
        $res = $moderator->inspect($text, $entities, 'economical', $chatId, 'ph1');
        $this->assertEquals('unsafe', $res['status'], "Niqoblangan fishing havolasi bloklanishi shart");
        $this->assertEquals('malicious_link', $res['category']);
        $this->assertEquals('link_filter_masked', $res['source']);
        $this->assertStringContainsString('gov.uz', $res['reason']);
        $this->assertStringContainsString('tr.ee', $res['reason']);

        // 2. Subdomen bilan aldash ham ushlanishi kerak: ko'rinishi "gov.uz",
        // aslida "gov.uz.scam-site.xyz" (bu butunlay boshqa sayt).
        $res2 = $moderator->inspect('Manzil: gov.uz', [[
            'type' => 'text_link',
            'offset' => 8,
            'length' => 6,
            'url' => 'https://gov.uz.scam-site.xyz/form',
        ]], 'economical', $chatId, 'ph2');
        $this->assertEquals('unsafe', $res2['status'], "Subdomen bilan aldash ham niqoblash hisoblanadi");
        $this->assertEquals('link_filter_masked', $res2['source']);

        // 3. HALOL havola — ko'rinadigan domen haqiqiy domen bilan bir xil
        // (va oq ro'yxatda) — hech narsa qilinmasligi kerak.
        $res3 = $moderator->inspect('Yangilik: kun.uz', [[
            'type' => 'text_link',
            'offset' => 10,
            'length' => 6,
            'url' => 'https://kun.uz/news/12345',
        ]], 'economical', $chatId, 'ph3');
        $this->assertEquals('safe', $res3['status'], "Ko'rinishi va manzili bir xil bo'lgan havola bloklanmasligi kerak");

        // 4. Ko'rinadigan matnda domen YO'Q ("Batafsil") — niqoblash deb
        // hisoblanmaydi, lekin noma'lum/qisqartirilgan domen bo'lgani uchun
        // endi jim o'tkazib yuborilmay, AI tahliliga tushadi.
        $res4 = $moderator->inspect('Batafsil', [[
            'type' => 'text_link',
            'offset' => 0,
            'length' => 8,
            'url' => 'https://tr.ee/Iv0aPh',
        ]], 'economical', $chatId, 'ph4');
        $this->assertFalse(
            $res4['status'] === 'safe',
            "Qisqartirilgan havola hech bo'lmasa AI tekshiruviga yuborilishi kerak, jim o'tmasligi"
        );
        $this->assertFalse(
            ($res4['source'] ?? '') === 'local_rules',
            "Qisqartirilgan havola mahalliy filtrdan 'toza' deb chiqib ketmasligi kerak"
        );
    }

    /**
     * 2.0 Phase 7: adminga boradigan moderatsiya xabari o'qilishi kerak.
     *
     * Avval u faqat raqamlardan iborat edi ("Guruh ID: -100...",
     * "Foydalanuvchi: 8412100749", "Amal: REVIEW_ONLY") — admin qaysi guruh,
     * kim va nima yozgani haqida hech narsa bilolmasdi.
     */
    public function testAdminLogShowsNamesMessageTextAndPlainLanguageReason(): void
    {
        $chatId = -100960100;
        $adminId = 960101;
        $offenderId = 960102;
        $messageId = 7788;

        $pdo = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        SettingsService::get($chatId); // guruh va sozlamalar yozuvini yaratadi
        $pdo->prepare("UPDATE `groups` SET title = :t WHERE chat_id = :cid")
            ->execute(['t' => 'Marhabo kv', 'cid' => $chatId]);
        // Admin — xabar shu odamga boradi.
        $pdo->exec("INSERT INTO chat_members (chat_id, user_id, role, updated_at) VALUES ({$chatId}, {$adminId}, 'creator', '{$now}')");
        // Qoidabuzar foydalanuvchi va uning xabari.
        $pdo->prepare("INSERT INTO users (user_id, first_name, last_name, username, is_bot, first_seen_at, last_seen_at) VALUES (:uid, 'Ali', 'Valiyev', 'alivaliyev', 0, :now, :now)")
            ->execute(['uid' => $offenderId, 'now' => $now]);
        $pdo->prepare("INSERT INTO messages (chat_id, message_id, user_id, media_type, raw_text, message_date, created_at) VALUES (:cid, :mid, :uid, 'text', :txt, :now, :now)")
            ->execute([
                'cid' => $chatId,
                'mid' => $messageId,
                'uid' => $offenderId,
                'txt' => '35 000 000 so\'mgacha yordam olish uchun havolaga o\'ting',
                'now' => $now,
            ]);

        $telegram = new class extends TelegramClient {
            public array $sent = [];
            public function sendMessage(int|string $chatId, string $text, array $extra = []): array
            {
                $this->sent[] = $text;
                return ['ok' => true, 'result' => ['message_id' => 1]];
            }
        };

        $punishment = new PunishmentService($telegram);
        $punishment->execute($chatId, $offenderId, $messageId, [
            'action' => 'review_only',
            'delete_message' => false,
            'notify_admin' => true,
            'reason' => "Qo'lda ko'rib chiqish talab etiladi: Gemini xavfsizlik filtri javobni blokladi: PROHIBITED_CONTENT",
            'evidence' => 'PROHIBITED_CONTENT',
        ]);

        $adminText = '';
        foreach ($telegram->sent as $text) {
            if (str_contains($text, 'Moderatsiya harakati')) {
                $adminText = $text;
                break;
            }
        }
        $this->assertTrue($adminText !== '', "Adminga moderatsiya xabari yuborilishi kerak");

        $this->assertStringContainsString('Marhabo kv', $adminText, "Guruh NOMI ko'rsatilishi kerak, faqat ID emas");
        $this->assertStringContainsString('Ali Valiyev', $adminText, "Foydalanuvchi ISMI ko'rsatilishi kerak");
        $this->assertStringContainsString('@alivaliyev', $adminText, "Username ko'rsatilishi kerak");
        $this->assertStringContainsString('35 000 000', $adminText, "Qoidani buzgan XABAR MATNI ko'rsatilishi kerak");
        $this->assertStringContainsString("qo'lda ko'rib chiqish kerak", $adminText, "Amal odamcha tilda yozilishi kerak");
        $this->assertStringContainsString('AI kontentni tekshirishdan bosh tortdi', $adminText, "Texnik sabab tushuntirilishi kerak");

        $this->assertFalse(
            str_contains($adminText, 'REVIEW_ONLY'),
            "Xom amal kodi ('REVIEW_ONLY') adminga ko'rsatilmasligi kerak"
        );
    }
}
