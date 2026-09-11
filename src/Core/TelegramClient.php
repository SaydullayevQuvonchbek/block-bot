<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

class TelegramClient
{
    private string $botToken;
    private string $baseApiUrl;
    private string $apiUrl;
    private string $fileBaseUrl;
    private ?string $proxy;
    private ?string $proxySecret;

    public function __construct(?string $token = null, ?string $baseApiUrl = null, ?string $proxy = null)
    {
        $this->botToken = $token ?? (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        $this->baseApiUrl = rtrim($baseApiUrl ?? (string)Config::get('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'), '/');
        if (empty($this->baseApiUrl)) {
            $this->baseApiUrl = 'https://api.telegram.org';
        }
        $this->apiUrl = "{$this->baseApiUrl}/bot{$this->botToken}/";
        $this->fileBaseUrl = "{$this->baseApiUrl}/file/bot{$this->botToken}/";
        $this->proxy = $proxy ?? (Config::get('TELEGRAM_PROXY') ? (string)Config::get('TELEGRAM_PROXY') : null);
        $this->proxySecret = Config::get('TELEGRAM_PROXY_SECRET') ? (string)Config::get('TELEGRAM_PROXY_SECRET') : null;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getBaseApiUrl(): string
    {
        return $this->baseApiUrl;
    }

    public function isConfigured(): bool
    {
        return preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $this->botToken) === 1
            && !str_contains($this->botToken, 'ABCdefGHI');
    }

    public function getBotId(): int
    {
        $parts = explode(':', $this->botToken);
        return (int)($parts[0] ?? 0);
    }

    /**
     * Telegram Bot API so'rovi
     */
    public function request(string $method, array $params = [], int $timeout = 15): array
    {
        if (Config::get('APP_ENV') === 'test') {
            if ($method === 'getChatMember') {
                $userId = (int)($params['user_id'] ?? 0);
                $status = ($userId === 1087968824 || $userId === 1) ? 'creator' : 'member';
                return [
                    'ok' => true,
                    'result' => [
                        'user' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Test User'],
                        'status' => $status,
                    ]
                ];
            }
            return [
                'ok' => true,
                'result' => [
                    'message_id' => 12345,
                    'id' => 12345678,
                ]
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'error_code' => 401,
                'description' => "Telegram Bot Token sozlanmagan (.env faylda TELEGRAM_BOT_TOKEN ko'rsatilmagan).",
            ];
        }

        $url = $this->apiUrl . $method;

        $headers = ['Content-Type: application/json'];
        if (!empty($this->proxySecret)) {
            $headers[] = 'X-Proxy-Secret: ' . $this->proxySecret;
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!empty($this->proxy)) {
            $curlOptions[CURLOPT_PROXY] = $this->proxy;
        }
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error("Telegram API cURL xatosi [{$method}]: {$curlError}", [], 'telegram');
            return [
                'ok' => false,
                'error_code' => 0,
                'description' => "cURL xatosi: {$curlError}",
            ];
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            Logger::error("Telegram API noto'g'ri JSON qaytardi [{$method}] (HTTP {$httpCode}): {$response}", [], 'telegram');
            return [
                'ok' => false,
                'error_code' => $httpCode,
                'description' => "Telegram serveri noto'g'ri javob qaytardi",
            ];
        }

        if (!$result['ok'] && isset($result['parameters']['retry_after'])) {
            $retryAfter = (int)$result['parameters']['retry_after'];
            Logger::warning("Telegram FLOOD_WAIT cheklovi: {$retryAfter} soniya kutish kerak [{$method}]", [], 'telegram');
        }

        if (!$result['ok']) {
            Logger::warning("Telegram API xatolik berdi [{$method}]: " . ($result['description'] ?? 'noma\'lum'), [
                'http_code' => $httpCode,
                'params' => Logger::sanitize(json_encode($params)),
            ], 'telegram');
        }

        return $result;
    }

    public function getMe(): array
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url, ?string $secretToken = null, array $allowedUpdates = []): array
    {
        $params = [
            'url' => $url,
            'drop_pending_updates' => false,
        ];
        if (!empty($secretToken)) {
            $params['secret_token'] = $secretToken;
        }
        if (!empty($allowedUpdates)) {
            $params['allowed_updates'] = $allowedUpdates;
        }
        return $this->request('setWebhook', $params);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->request('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo');
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        return $this->request('sendMessage', $params);
    }

    public function sendDocument(int|string $chatId, string $filePath, string $caption = ''): array
    {
        if (Config::get('APP_ENV') === 'test') {
            return [
                'ok' => true,
                'result' => [
                    'message_id' => 12345,
                    'document' => ['file_id' => 'doc_test_123', 'file_name' => basename($filePath)],
                ]
            ];
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token sozlanmagan'];
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['ok' => false, 'description' => 'Yuboriladigan fayl topilmadi'];
        }

        $headers = [];
        if (!empty($this->proxySecret)) {
            $headers[] = 'X-Proxy-Secret: ' . $this->proxySecret;
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $this->apiUrl . 'sendDocument',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => (string)$chatId,
                'caption' => $caption,
                'document' => new \CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath)),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!empty($headers)) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }
        if (!empty($this->proxy)) {
            $curlOptions[CURLOPT_PROXY] = $this->proxy;
        }
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error("Telegram hujjat yuborish xatosi: {$error}", [], 'telegram');
            return ['ok' => false, 'error_code' => 0, 'description' => $error];
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            return ['ok' => false, 'error_code' => $httpCode, 'description' => "Telegram noto'g'ri javob qaytardi"];
        }
        return $result;
    }

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        $res = $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
        return (bool)($res['ok'] ?? false);
    }

    public function restrictChatMember(int|string $chatId, int $userId, array $permissions, int $untilDate = 0): bool
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => $permissions,
        ];
        if ($untilDate > 0) {
            $params['until_date'] = $untilDate;
        }
        $res = $this->request('restrictChatMember', $params);
        return (bool)($res['ok'] ?? false);
    }

    public function muteUser(int|string $chatId, int $userId, int $durationSeconds = 3600): bool
    {
        $untilDate = time() + max(30, $durationSeconds);
        // Barcha xabar yozish huquqlarini cheklash (mute)
        $noPermissions = [
            'can_send_messages' => false,
            'can_send_audios' => false,
            'can_send_documents' => false,
            'can_send_photos' => false,
            'can_send_videos' => false,
            'can_send_video_notes' => false,
            'can_send_voice_notes' => false,
            'can_send_polls' => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false,
            'can_change_info' => false,
            'can_invite_users' => false,
            'can_pin_messages' => false,
        ];
        return $this->restrictChatMember($chatId, $userId, $noPermissions, $untilDate);
    }

    public function unmuteUser(int|string $chatId, int $userId): bool
    {
        // Standart a'zo huquqlarini qaytarish
        $defaultPermissions = [
            'can_send_messages' => true,
            'can_send_audios' => true,
            'can_send_documents' => true,
            'can_send_photos' => true,
            'can_send_videos' => true,
            'can_send_video_notes' => true,
            'can_send_voice_notes' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
            'can_change_info' => false,
            'can_invite_users' => true,
            'can_pin_messages' => false,
        ];
        return $this->restrictChatMember($chatId, $userId, $defaultPermissions, 0);
    }

    public function banChatMember(int|string $chatId, int $userId, int $untilDate = 0, bool $revokeMessages = false): bool
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => $revokeMessages,
        ];
        if ($untilDate > 0) {
            $params['until_date'] = $untilDate;
        }
        $res = $this->request('banChatMember', $params);
        return (bool)($res['ok'] ?? false);
    }

    public function unbanChatMember(int|string $chatId, int $userId, bool $onlyIfBanned = true): bool
    {
        $res = $this->request('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => $onlyIfBanned,
        ]);
        return (bool)($res['ok'] ?? false);
    }

    public function getChatAdministrators(int|string $chatId): array
    {
        $res = $this->request('getChatAdministrators', ['chat_id' => $chatId]);
        return (array)($res['result'] ?? []);
    }

    public function getChat(int|string $chatId): ?array
    {
        $res = $this->request('getChat', ['chat_id' => $chatId]);
        return ($res['ok'] ?? false) && is_array($res['result'] ?? null)
            ? $res['result']
            : null;
    }

    public function getChatMember(int|string $chatId, int $userId): ?array
    {
        $res = $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
        return ($res['ok'] ?? false) ? ($res['result'] ?? null) : null;
    }

    public function getUserProfilePhotos(int $userId, int $offset = 0, int $limit = 1): array
    {
        $res = $this->request('getUserProfilePhotos', [
            'user_id' => $userId,
            'offset' => $offset,
            'limit' => $limit,
        ]);
        return (array)($res['result'] ?? []);
    }

    public function getFile(string $fileId): ?array
    {
        $res = $this->request('getFile', ['file_id' => $fileId]);
        return ($res['ok'] ?? false) ? ($res['result'] ?? null) : null;
    }

    public function downloadFile(string $filePath, string $destinationPath): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = "{$this->fileBaseUrl}{$filePath}";

        $dir = dirname($destinationPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($destinationPath, 'w+');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if (!empty($this->proxy)) {
            $curlOptions[CURLOPT_PROXY] = $this->proxy;
        }
        if (!empty($this->proxySecret)) {
            $curlOptions[CURLOPT_HTTPHEADER] = ['X-Proxy-Secret: ' . $this->proxySecret];
        }
        curl_setopt_array($ch, $curlOptions);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            @unlink($destinationPath);
            Logger::error("Telegram faylini yuklab olishda xato (HTTP {$httpCode})", [], 'telegram');
            return false;
        }

        return true;
    }

    /**
     * Bot buyruqlar ro'yxatini o'rnatish (Telegram'dagi "Menu" tugmasi uchun).
     *
     * @param array<int, array{command:string, description:string}> $commands
     */
    public function setMyCommands(array $commands, ?array $scope = null, ?string $languageCode = null): array
    {
        $params = ['commands' => $commands];
        if ($scope !== null) {
            $params['scope'] = $scope;
        }
        if ($languageCode !== null) {
            $params['language_code'] = $languageCode;
        }
        return $this->request('setMyCommands', $params);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool
    {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ];
        $res = $this->request('answerCallbackQuery', $params);
        return (bool)($res['ok'] ?? false);
    }
}
