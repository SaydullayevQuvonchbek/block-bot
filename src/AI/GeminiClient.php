<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Config;
use App\Core\Logger;
use App\Policy\SubscriptionService;

/**
 * Google Gemini REST API klienti.
 *
 * OpenRouterClient'dan voris olish mavjud moderator konstruktorlari va test
 * mocklari bilan orqaga moslikni saqlaydi; barcha tarmoq metodlari bu yerda
 * bevosita Google Gemini API uchun qayta yozilgan.
 */
class GeminiClient extends OpenRouterClient
{
    private string $geminiApiKey;
    private string $geminiBaseUrl;
    private string $geminiTextModel;
    private string $geminiVisionModel;
    private string $geminiAudioModel;
    // $proxySecret — OpenRouterClient'dan meros (protected), shu yerda qayta e'lon qilinmaydi.

    public function __construct()
    {
        $this->geminiApiKey = trim((string)Config::get('GEMINI_API_KEY', ''));
        $this->geminiBaseUrl = rtrim((string)Config::get(
            'GEMINI_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta'
        ), '/');
        $this->geminiTextModel = $this->normalizeModel((string)Config::get('GEMINI_TEXT_MODEL', 'gemini-2.5-flash'));
        $this->geminiVisionModel = $this->normalizeModel((string)Config::get('GEMINI_VISION_MODEL', 'gemini-2.5-flash'));
        // Alohida sozlanmasa, vision modeli bilan bir xil ishlatiladi (Gemini 2.5 Flash
        // audio kiritishni ham native qo'llab-quvvatlaydi, yangi majburiy .env o'zgaruvchisi shart emas).
        $this->geminiAudioModel = $this->normalizeModel((string)Config::get(
            'GEMINI_AUDIO_MODEL',
            (string)Config::get('GEMINI_VISION_MODEL', 'gemini-2.5-flash')
        ));
        // Google ba'zi mamlakatlar (masalan Rossiya/Belarus) IP manzillaridan kelgan so'rovlarni
        // "User location is not supported" xatosi bilan rad etadi. GEMINI_BASE_URL'ni
        // Cloudflare Worker proksisiga (deploy/cloudflare-worker/telegram-proxy.js, /gemini yo'li)
        // yo'naltirilganda shu maxfiy kalit orqali ruxsat beriladi; to'g'ridan-to'g'ri Google'ga
        // yuborilganda bu sarlavha e'tiborsiz qoldiriladi (zararsiz).
        $this->proxySecret = Config::get('TELEGRAM_PROXY_SECRET') ? (string)Config::get('TELEGRAM_PROXY_SECRET') : null;
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->geminiApiKey !== ''
            && !str_contains(strtolower($this->geminiApiKey), 'your_')
            && !str_contains(strtolower($this->geminiApiKey), 'xxxxxxxx');
    }

    public function moderateText(
        string $text,
        string $itemId = 'item_1',
        ?int $chatId = null,
        ?string $customModel = null
    ): array {
        if (!$this->isConfigured()) {
            return $this->configurationError($itemId);
        }

        $parts = [[
            'text' => "item_id: {$itemId}\nQuyidagi matnni tekshir:\n---\n{$text}\n---",
        ]];

        return $this->callGemini(
            $parts,
            $this->normalizeModel($customModel ?: $this->geminiTextModel),
            'text',
            $itemId,
            $chatId
        );
    }

    public function moderateImage(
        string $imagePath,
        string $caption = '',
        string $itemId = 'item_1',
        ?int $chatId = null,
        ?string $customModel = null
    ): array {
        if (!$this->isConfigured()) {
            return $this->configurationError($itemId);
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return [
                'item_id' => $itemId,
                'status' => 'unscannable',
                'category' => 'error',
                'reason' => "Rasm fayli serverda topilmadi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $optimizedPath = $this->optimizeImage($imagePath);
        if ($optimizedPath === '') {
            return [
                'item_id' => $itemId,
                'status' => 'unscannable',
                'category' => 'image_dimensions_exceeded',
                'reason' => "Rasm piksel o'lchamlari ruxsat etilgan xavfsiz limitdan katta (25 MP)",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }
        $mime = mime_content_type($optimizedPath) ?: 'image/jpeg';
        $imageData = file_get_contents($optimizedPath);
        if ($optimizedPath !== $imagePath) {
            @unlink($optimizedPath);
        }

        if ($imageData === false || $imageData === '') {
            return [
                'item_id' => $itemId,
                'status' => 'unscannable',
                'category' => 'error',
                'reason' => "Rasmni o'qib bo'lmadi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $prompt = $caption !== ''
            ? "item_id: {$itemId}\nUshbu rasm va uning izohini moderatsiya qil. Izoh: {$caption}"
            : "item_id: {$itemId}\nUshbu rasmni moderatsiya qil.";

        $parts = [
            ['text' => $prompt],
            [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => base64_encode($imageData),
                ],
            ],
        ];

        return $this->callGemini(
            $parts,
            $this->normalizeModel($customModel ?: $this->geminiVisionModel),
            'vision',
            $itemId,
            $chatId
        );
    }

    /**
     * Ovozli/audio xabarni (Telegram voice — OGG/Opus, yoki audio — MP3/M4A va h.k.)
     * Gemini'ning native audio kiritish qo'llab-quvvatlashi orqali moderatsiya qilish.
     * Qayta kodlash (transcoding) talab qilinmaydi — fayl to'g'ridan-to'g'ri inline_data
     * sifatida yuboriladi (rasm bilan bir xil mexanizm).
     */
    public function moderateAudio(
        string $audioPath,
        string $caption = '',
        string $itemId = 'item_1',
        ?int $chatId = null,
        ?string $customModel = null
    ): array {
        if (!$this->isConfigured()) {
            return $this->configurationError($itemId);
        }

        if (!is_file($audioPath) || !is_readable($audioPath)) {
            return [
                'item_id' => $itemId,
                'status' => 'unscannable',
                'category' => 'error',
                'reason' => "Audio fayli serverda topilmadi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $mime = mime_content_type($audioPath) ?: 'audio/ogg';
        $audioData = file_get_contents($audioPath);

        if ($audioData === false || $audioData === '') {
            return [
                'item_id' => $itemId,
                'status' => 'unscannable',
                'category' => 'error',
                'reason' => "Audio faylni o'qib bo'lmadi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $prompt = $caption !== ''
            ? "item_id: {$itemId}\nUshbu ovozli xabarni tinglab, undagi nutqni (va fon ovozini) moderatsiya qil. Izoh: {$caption}"
            : "item_id: {$itemId}\nUshbu ovozli xabarni tinglab, undagi nutqni moderatsiya qil.";

        $parts = [
            ['text' => $prompt],
            [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => base64_encode($audioData),
                ],
            ],
        ];

        return $this->callGemini(
            $parts,
            $this->normalizeModel($customModel ?: $this->geminiAudioModel),
            'audio',
            $itemId,
            $chatId
        );
    }

    private function callGemini(
        array $parts,
        string $model,
        string $requestType,
        string $itemId,
        ?int $chatId
    ): array {
        // Bepul tarif kunlik AI so'rov chegarasi (2.0 Phase 3, 2-band — monetizatsiya).
        // Premium guruhlar uchun har doim o'tadi. Global $ budjet tekshiruvidan (pastda)
        // ATAYLAB ALOHIDA — bu GURUH darajasidagi (per-chat) chegara.
        if ($chatId !== null && !SubscriptionService::canUseAi($chatId)) {
            return [
                'item_id' => $itemId,
                'status' => 'review',
                'category' => 'free_tier_limit_reached',
                'reason' => "Bepul tarifning kunlik AI so'rov chegarasiga yetdi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $estimatedCost = match ($requestType) {
            'vision', 'audio' => 0.002,
            default => 0.0005,
        };
        $reservationKey = UsageBudgetService::reserveBudget($estimatedCost);
        if ($reservationKey === null) {
            return [
                'item_id' => $itemId,
                'status' => 'review',
                'category' => 'budget_exhausted',
                'reason' => "AI kunlik yoki oylik budjeti chegarasiga yetdi",
                'evidence' => '',
                'model' => 'none',
                'cost_usd' => 0.0,
            ];
        }

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $this->buildModerationSystemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 400,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->responseSchema(),
            ],
        ];

        $url = $this->geminiBaseUrl . '/models/' . rawurlencode($model) . ':generateContent';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->geminiApiKey,
        ];
        if (!empty($this->proxySecret)) {
            $headers[] = 'X-Proxy-Secret: ' . $this->proxySecret;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            UsageBudgetService::releaseBudget($reservationKey);
            $errorPayload = json_decode((string)$response, true);
            $apiMessage = trim((string)(
                $errorPayload['error']['message']
                ?? $errorPayload['error']
                ?? $curlError
                ?? ''
            ));
            if ($apiMessage === '') {
                $apiMessage = 'Noma\'lum API xatosi';
            }
            $isRegionBlocked = $httpCode === 400 && stripos($apiMessage, 'location is not supported') !== false;
            Logger::error(
                "Gemini API so'rovi xatosi (HTTP {$httpCode}): {$curlError} | Javob: " . (string)$response,
                [],
                'ai'
            );
            return [
                'item_id' => $itemId,
                'status' => 'review',
                'category' => $isRegionBlocked ? 'region_blocked' : match ($httpCode) {
                    400, 401, 403 => 'configuration_error',
                    429 => 'rate_limit',
                    default => 'network_error',
                },
                'reason' => $isRegionBlocked
                    ? "Google Gemini API serverning IP-manzili joylashgan mintaqadan so'rovlarni rad etyapti (\"User location is not supported\"). Yechim: GEMINI_BASE_URL'ni Cloudflare Worker proksisiga yo'naltiring (php bin/doctor.php buni aniqlaydi) yoki AI_PROVIDER=openrouter ga o'ting."
                    : "Google Gemini API xatosi (HTTP {$httpCode}): {$apiMessage}",
                'evidence' => '',
                'model' => $model,
                'cost_usd' => 0.0,
            ];
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            UsageBudgetService::releaseBudget($reservationKey);
            Logger::error("Gemini API noto'g'ri JSON javob qaytardi. Xom javob: " . substr((string)$response, 0, 500), [], 'ai');
            return $this->invalidResponse($itemId, $model);
        }

        $blocked = $this->blockedResponse($decoded, $itemId, $model);
        if ($blocked !== null) {
            UsageBudgetService::releaseBudget($reservationKey);
            return $blocked;
        }

        $content = (string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $parsed = $content !== '' ? $this->validateAndParseOutput($content, $itemId) : null;
        if ($parsed === null) {
            UsageBudgetService::releaseBudget($reservationKey);
            if ($content === '') {
                // Model hech qanday matn qaytarmadi (odatda finishReason/blockReason
                // ko'rsatilmagan yashirin xavfsizlik rad etishi yoki bo'sh candidates).
                $finishReason = (string)($decoded['candidates'][0]['finishReason'] ?? 'yo\'q');
                Logger::error(
                    "Gemini bo'sh javob qaytardi (finishReason: {$finishReason}). To'liq javob: " . substr((string)$response, 0, 500),
                    [],
                    'ai'
                );
                return [
                    'item_id' => $itemId,
                    'status' => 'unscannable',
                    'category' => 'ai_empty_response',
                    'reason' => "AI hech qanday tahlil qaytarmadi (finishReason: {$finishReason}). Bu ko'pincha modelning kontentni tahlil qilishdan yashirin bosh tortishi (xavfsizlik siyosati) belgisi — qo'lda tekshiring.",
                    'evidence' => '',
                    'model' => $model,
                    'cost_usd' => 0.0,
                ];
            }
            Logger::error("Gemini noto'g'ri moderatsiya JSON qaytardi: " . substr($content, 0, 500), [], 'ai');
            return $this->invalidResponse($itemId, $model);
        }

        $usage = (array)($decoded['usageMetadata'] ?? []);
        $promptTokens = (int)($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int)($usage['candidatesTokenCount'] ?? 0);
        UsageBudgetService::settleBudget(
            $reservationKey,
            $estimatedCost,
            $chatId,
            $model,
            $requestType,
            $promptTokens,
            $completionTokens,
            true
        );

        $parsed['model'] = $model;
        $parsed['cost_usd'] = $estimatedCost;
        return $parsed;
    }

    private function blockedResponse(array $decoded, string $itemId, string $model): ?array
    {
        $blockReason = (string)($decoded['promptFeedback']['blockReason'] ?? '');
        $finishReason = (string)($decoded['candidates'][0]['finishReason'] ?? '');
        $reason = $blockReason !== '' ? $blockReason : $finishReason;

        if (!in_array($reason, ['SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY'], true)) {
            return null;
        }

        $ratings = (array)($decoded['promptFeedback']['safetyRatings']
            ?? $decoded['candidates'][0]['safetyRatings']
            ?? []);
        $sexualRisk = false;
        foreach ($ratings as $rating) {
            if (($rating['category'] ?? '') === 'HARM_CATEGORY_SEXUALLY_EXPLICIT'
                && in_array(($rating['probability'] ?? ''), ['MEDIUM', 'HIGH'], true)) {
                $sexualRisk = true;
                break;
            }
        }

        return [
            'item_id' => $itemId,
            'status' => $sexualRisk ? 'unsafe' : 'review',
            'category' => $sexualRisk ? 'pornography' : 'safety_blocked',
            'reason' => $sexualRisk
                ? "Gemini xavfsizlik filtri jinsiy kontentni aniqladi"
                : "Gemini xavfsizlik filtri javobni blokladi: {$reason}",
            'evidence' => $reason,
            'model' => $model,
            'cost_usd' => 0.0,
        ];
    }

    private function normalizeModel(string $model): string
    {
        $model = trim($model);
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }
        if (str_starts_with($model, 'google/')) {
            $model = substr($model, 7);
        }
        return $model !== '' ? $model : 'gemini-2.5-flash';
    }

    private function configurationError(string $itemId): array
    {
        return [
            'item_id' => $itemId,
            'status' => 'review',
            'category' => 'unscanned',
            'reason' => "GEMINI_API_KEY sozlanmagan",
            'evidence' => '',
            'model' => 'none',
            'cost_usd' => 0.0,
        ];
    }

    private function invalidResponse(string $itemId, string $model): array
    {
        return [
            'item_id' => $itemId,
            'status' => 'review',
            'category' => 'invalid_ai_response',
            'reason' => "Gemini javobi qat'iy moderatsiya formatiga mos kelmadi",
            'evidence' => '',
            'model' => $model,
            'cost_usd' => 0.0,
        ];
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'item_id' => ['type' => 'STRING'],
                'status' => ['type' => 'STRING', 'enum' => ['safe', 'unsafe', 'review', 'unscannable']],
                'category' => [
                    'type' => 'STRING',
                    'enum' => [
                        'none', 'profanity', 'pornography', 'adult_profile', 'spam_ad',
                        'gambling', 'trading_scam', 'apk_distribution', 'malicious_link', 'hate_speech',
                    ],
                ],
                'reason' => ['type' => 'STRING'],
                'evidence' => ['type' => 'STRING'],
            ],
            'required' => ['item_id', 'status', 'category', 'reason', 'evidence'],
        ];
    }
}
