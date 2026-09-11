<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Config;
use App\Core\Logger;
use Throwable;

/**
 * Google Cloud Vision "SafeSearch Detection" klienti.
 *
 * Gemini kabi generativ (suhbat) modellardan farqli o'laroq, bu — maxsus,
 * faqat rasm xavfsizligini baholash uchun yaratilgan API. U hech qachon
 * "tahlil qilishdan bosh tortmaydi" — faqat adult/racy/violence ehtimolini
 * foiz (likelihood) darajasida qaytaradi. Shuning uchun Gemini/OpenRouter
 * "review" yoki tushunarsiz javob qaytarganda **zaxira (fallback)** tekshiruv
 * sifatida ishlatiladi — asosiy tahlilchini almashtirmaydi.
 *
 * Sozlash: .env da GOOGLE_VISION_API_KEY (https://console.cloud.google.com/apis/credentials,
 * "Cloud Vision API" yoqilgan bo'lishi kerak). Sozlanmagan bo'lsa, bu klient
 * shunchaki chetlab o'tiladi — asosiy oqimga ta'sir qilmaydi.
 */
class GoogleVisionClient
{
    private string $apiKey;
    private string $baseUrl;
    private ?string $proxySecret;

    private const LIKELIHOOD_RANK = [
        'UNKNOWN' => 0, 'VERY_UNLIKELY' => 1, 'UNLIKELY' => 2,
        'POSSIBLE' => 3, 'LIKELY' => 4, 'VERY_LIKELY' => 5,
    ];

    public function __construct()
    {
        $this->apiKey = trim((string)Config::get('GOOGLE_VISION_API_KEY', ''));
        $this->baseUrl = rtrim((string)Config::get('GOOGLE_VISION_BASE_URL', 'https://vision.googleapis.com/v1'), '/');
        // Gemini/OpenRouter bilan bir xil Cloudflare Worker proksisidan foydalanish uchun
        // (agar server IP-manzili vision.googleapis.com'ni ham bloklasa).
        $this->proxySecret = Config::get('TELEGRAM_PROXY_SECRET') ? (string)Config::get('TELEGRAM_PROXY_SECRET') : null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && !str_contains(strtolower($this->apiKey), 'your_');
    }

    /**
     * Rasmni SafeSearch orqali tekshirish.
     *
     * @return array{status:string,category:string,reason:string,evidence:string,model:string,cost_usd:float}|null
     *         null — sozlanmagan, fayl o'qilmadi yoki API xatosi (chaqiruvchi asl AI natijasini saqlaydi).
     */
    public function detect(string $imagePath, string $itemId = 'item_1'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return null;
        }

        $imageData = @file_get_contents($imagePath);
        if ($imageData === false || $imageData === '') {
            return null;
        }
        // Vision API hajm limiti (~20MB rasm uchun yetarlicha xavfsiz chegara).
        if (strlen($imageData) > 20 * 1024 * 1024) {
            return null;
        }

        $payload = [
            'requests' => [[
                'image' => ['content' => base64_encode($imageData)],
                'features' => [['type' => 'SAFE_SEARCH_DETECTION']],
            ]],
        ];

        $headers = ['Content-Type: application/json'];
        if (!empty($this->proxySecret)) {
            $headers[] = 'X-Proxy-Secret: ' . $this->proxySecret;
        }

        $url = $this->baseUrl . '/images:annotate?key=' . rawurlencode($this->apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Logger::error(
                "Google Vision SafeSearch xatosi (HTTP {$httpCode}): {$curlError} | Javob: " . substr((string)$response, 0, 300),
                [],
                'ai'
            );
            return null;
        }

        try {
            $decoded = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            Logger::error("Google Vision noto'g'ri JSON qaytardi: " . substr((string)$response, 0, 300), [], 'ai');
            return null;
        }

        $annotation = $decoded['responses'][0]['safeSearchAnnotation'] ?? null;
        if (!is_array($annotation)) {
            $err = $decoded['responses'][0]['error']['message'] ?? 'noma\'lum';
            Logger::warning("Google Vision safeSearchAnnotation topilmadi: {$err}", [], 'ai');
            return null;
        }

        $adult = self::LIKELIHOOD_RANK[$annotation['adult'] ?? 'UNKNOWN'] ?? 0;
        $racy = self::LIKELIHOOD_RANK[$annotation['racy'] ?? 'UNKNOWN'] ?? 0;
        $violence = self::LIKELIHOOD_RANK[$annotation['violence'] ?? 'UNKNOWN'] ?? 0;
        $detail = "adult={$annotation['adult']}, racy={$annotation['racy']}, violence={$annotation['violence']}";

        if ($adult >= 4 || $racy >= 5) {
            return $this->result('unsafe', 'pornography', "Google SafeSearch: ochiq/yarim ochiq jinsiy kontent aniqlandi ({$detail})", $itemId);
        }
        if ($violence >= 4) {
            return $this->result('unsafe', 'violence', "Google SafeSearch: zo'ravonlik kontenti aniqlandi ({$detail})", $itemId);
        }
        if ($adult >= 3 || $racy >= 4) {
            return $this->result('review', 'pornography', "Google SafeSearch shubhali natija berdi, qo'lda tekshiring ({$detail})", $itemId);
        }

        return $this->result('safe', 'none', "Google SafeSearch: xavfsiz ({$detail})", $itemId);
    }

    private function result(string $status, string $category, string $reason, string $itemId): array
    {
        return [
            'item_id' => $itemId,
            'status' => $status,
            'category' => $category,
            'reason' => $reason,
            'evidence' => '',
            'model' => 'google-vision-safesearch',
            'cost_usd' => 0.0015,
        ];
    }
}
