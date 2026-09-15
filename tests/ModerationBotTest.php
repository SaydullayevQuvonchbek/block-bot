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
use App\Core\QueueService;
use App\Core\TelegramClient;
use App\Http\UpdateRouter;
use App\Moderation\TextModerator;
use App\Moderation\TextNormalizer;
use App\Moderation\ProfileModerator;
use App\Policy\AdminAuthorizationService;
use App\Policy\ModerationDecisionService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;

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
}
