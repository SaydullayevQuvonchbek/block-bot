<?php

declare(strict_types=1);

namespace App\Moderation;

use App\AI\AIClientFactory;
use App\AI\GoogleVisionClient;
use App\AI\OpenRouterClient;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramClient;
use Throwable;

class MediaModerator
{
    private TelegramClient $telegram;
    private OpenRouterClient $openRouter;
    private GoogleVisionClient $visionFallback;
    private string $tempDir;
    private string $ffmpegPath;
    private ?bool $ffmpegAvailable = null;

    /** @var string[] Bu kategoriyalar uchun SafeSearch zaxira tekshiruvi ishlatilmaydi */
    private const NO_FALLBACK_CATEGORIES = ['budget_exhausted', 'image_dimensions_exceeded'];

    public function __construct(
        ?TelegramClient $telegram = null,
        ?OpenRouterClient $openRouter = null,
        ?GoogleVisionClient $visionFallback = null
    ) {
        $this->telegram = $telegram ?? new TelegramClient();
        $this->openRouter = $openRouter ?? AIClientFactory::create();
        $this->visionFallback = $visionFallback ?? new GoogleVisionClient();
        $this->tempDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
        $this->ffmpegPath = (string)Config::get('FFMPEG_PATH', 'ffmpeg');

        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Gemini/OpenRouter "review" yoki tushunarsiz javob qaytarganda (masalan, modelning
     * o'ta ochiq kontentni tahlil qilishdan yashirin bosh tortishi tufayli) Google Cloud
     * Vision SafeSearch orqali qo'shimcha, ishonchli tekshiruv o'tkazadi. SafeSearch
     * sozlanmagan yoki o'zi ham xato bersa, chaqiruvchi asl AI natijasini saqlab qoladi.
     */
    private function applySafeSearchFallback(array $primary, string $path, string $itemId): array
    {
        if (!$this->visionFallback->isConfigured()) {
            return $primary;
        }
        $status = $primary['status'] ?? '';
        if (!in_array($status, ['review', 'unscannable'], true)) {
            return $primary;
        }
        if (in_array($primary['category'] ?? '', self::NO_FALLBACK_CATEGORIES, true)) {
            return $primary;
        }

        $fallback = $this->visionFallback->detect($path, $itemId);
        if ($fallback === null) {
            return $primary;
        }

        Logger::info("SafeSearch zaxira tekshiruvi ishlatildi", [
            'item_id' => $itemId,
            'primary_category' => $primary['category'] ?? '',
            'fallback_status' => $fallback['status'],
            'fallback_category' => $fallback['category'],
        ], 'ai');

        $fallback['source'] = 'google_safesearch_fallback';
        $fallback['frames_scanned'] = $primary['frames_scanned'] ?? 1;
        return $fallback;
    }

    public function isFfmpegAvailable(): bool
    {
        if ($this->ffmpegAvailable !== null) {
            return $this->ffmpegAvailable;
        }
        $output = [];
        $code = 1;
        @exec(escapeshellarg($this->ffmpegPath) . ' -version 2>&1', $output, $code);
        return $this->ffmpegAvailable = ($code === 0);
    }

    /**
     * FFmpeg mavjud bo'lmaganda Telegram yaratgan video muqovasini AI Vision bilan tekshiradi.
     */
    public function inspectVideoPreview(
        string $thumbnailFileId,
        string $caption = '',
        ?int $chatId = null,
        string $itemId = 'video_preview_1',
        ?string $customModel = null
    ): array {
        $previewCaption = trim("Video/GIF muqovasi. " . $caption);
        $result = $this->inspectMedia($thumbnailFileId, 'photo', $previewCaption, $chatId, $itemId, $customModel);
        $result['source'] = 'ai_vision_video_preview';
        $result['frames_scanned'] = 1;
        $result['reason'] = (string)($result['reason'] ?? '') . " (FFmpeg yo'q: Telegram video muqovasi tekshirildi)";
        return $result;
    }

    /**
     * Mediani tekshirish (rasm, video, stiker, animatsiya, hujjat)
     */
    public function inspectMedia(
        string $fileId,
        string $mediaType,
        string $caption = '',
        ?int $chatId = null,
        string $itemId = 'media_1',
        ?string $customModel = null
    ): array {
        // 1. Telegramdan fayl yo'lini olish
        $fileInfo = $this->telegram->getFile($fileId);
        if (!$fileInfo || empty($fileInfo['file_path'])) {
            return [
                'status' => 'unscannable',
                'category' => 'error',
                'reason' => "Telegram serveridan fayl ma'lumotlarini olib bo'lmadi",
                'evidence' => '',
                'source' => 'media_moderator',
                'frames_scanned' => 0,
            ];
        }

        $remotePath = $fileInfo['file_path'];
        $fileSize = (int)($fileInfo['file_size'] ?? 0);
        $maxSize = Config::getInt('MAX_UPLOAD_SIZE_BYTES', 20971520);

        if ($fileSize > $maxSize) {
            return [
                'status' => 'unscannable',
                'category' => 'file_size_exceeded',
                'reason' => "Fayl hajmi ruxsat etilgan limitdan katta (" . round($fileSize / 1048576, 2) . " MB)",
                'evidence' => '',
                'source' => 'media_moderator',
                'frames_scanned' => 0,
            ];
        }

        $extension = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'tmp';
        $localTempPath = $this->tempDir . DIRECTORY_SEPARATOR . 'dl_' . bin2hex(random_bytes(8)) . '.' . $extension;

        try {
            // Faylni yuklab olish
            $downloaded = $this->telegram->downloadFile($remotePath, $localTempPath);
            if (!$downloaded || !file_exists($localTempPath)) {
                return [
                    'status' => 'unscannable',
                    'category' => 'download_failed',
                    'reason' => "Faylni serverga yuklab olish muvaffaqiyatsiz bo'ldi",
                    'evidence' => '',
                    'source' => 'media_moderator',
                    'frames_scanned' => 0,
                ];
            }

            // Fayl xeshini hisoblash (takroriy mediani keshdan olish uchun)
            $fileHash = hash_file('sha256', $localTempPath);
            $cacheKey = 'med_' . $fileHash . '_' . $this->openRouter->providerName() . '_' . ($customModel ?? '');
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                $cached['source'] = 'cache';
                return $cached;
            }

            // Media turiga qarab tahlil qilish
            $result = match ($mediaType) {
                'photo' => $this->inspectPhoto($localTempPath, $caption, $chatId, $itemId, $customModel),
                'video', 'animation' => $this->inspectVideo($localTempPath, $caption, $chatId, $itemId, $customModel),
                'sticker' => $this->inspectSticker($localTempPath, $extension, $chatId, $itemId),
                'document' => $this->inspectDocument($localTempPath, $caption, $chatId, $itemId),
                'voice', 'audio' => $this->inspectVoice($localTempPath, $caption, $chatId, $itemId, $customModel),
                default => [
                    'status' => 'unscannable',
                    'category' => 'unsupported_media_type',
                    'reason' => "Qo'llab-quvvatlanmaydigan media turi: {$mediaType}",
                    'evidence' => '',
                    'source' => 'media_moderator',
                    'frames_scanned' => 0,
                ],
            };

            if (in_array($result['status'], ['safe', 'unsafe'], true)) {
                $this->saveToCache($cacheKey, 'media_hash', $result, 86400 * 7); // 7 kunlik kesh
            }

            return $result;
        } finally {
            // Vaqtinchalik faylni xavfsiz tozalash
            if (file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }

    private function inspectPhoto(string $path, string $caption, ?int $chatId, string $itemId, ?string $customModel = null): array
    {
        $res = $this->openRouter->moderateImage($path, $caption, $itemId, $chatId, $customModel);
        $res['frames_scanned'] = 1;
        $res['source'] = 'ai_vision';
        return $this->applySafeSearchFallback($res, $path, $itemId);
    }

    private function inspectVideo(string $path, string $caption, ?int $chatId, string $itemId, ?string $customModel = null): array
    {
        if (!$this->isFfmpegAvailable()) {
            return [
                'status' => 'unscannable',
                'category' => 'ffmpeg_missing',
                'reason' => "Videoni tahlil qilish uchun tizimda FFmpeg mavjud emas",
                'evidence' => '',
                'source' => 'media_moderator',
                'frames_scanned' => 0,
            ];
        }

        // FFmpeg orqali 3 ta kadr olish (1-soniya, 3-soniya, 5-soniya)
        $timestamps = [1, 3, 5];
        $framesScanned = 0;
        $extractedFrames = [];

        foreach ($timestamps as $sec) {
            $frameFile = $this->tempDir . DIRECTORY_SEPARATOR . 'frame_' . bin2hex(random_bytes(6)) . "_{$sec}.jpg";
            // Shell injectiondan himoyalangan exec
            $cmd = sprintf(
                '%s -ss %d -i %s -vframes 1 -q:v 2 %s -y 2>&1',
                escapeshellarg($this->ffmpegPath),
                $sec,
                escapeshellarg($path),
                escapeshellarg($frameFile)
            );

            @exec($cmd, $out, $ret);
            if ($ret === 0 && file_exists($frameFile) && filesize($frameFile) > 0) {
                $extractedFrames[] = $frameFile;
            }
        }

        if (empty($extractedFrames)) {
            return [
                'status' => 'unscannable',
                'category' => 'frame_extraction_failed',
                'reason' => "Videodan kadr ajratib olish imkoni bo'lmadi",
                'evidence' => '',
                'source' => 'media_moderator',
                'frames_scanned' => 0,
            ];
        }

        $worstStatus = 'safe';
        $worstFinding = null;

        foreach ($extractedFrames as $idx => $framePath) {
            $framesScanned++;
            $finding = $this->openRouter->moderateImage($framePath, $caption, "{$itemId}_f{$idx}", $chatId, $customModel);
            $finding = $this->applySafeSearchFallback($finding, $framePath, "{$itemId}_f{$idx}");

            if ($finding['status'] === 'unsafe') {
                $worstStatus = 'unsafe';
                $worstFinding = $finding;
                break; // Xavfli kadr topilsa darhol to'xtatish
            } elseif ($finding['status'] === 'review' && $worstStatus !== 'unsafe') {
                $worstStatus = 'review';
                $worstFinding = $finding;
            }
        }

        // Kadr fayllarini tozalash
        foreach ($extractedFrames as $framePath) {
            @unlink($framePath);
        }

        if ($worstFinding !== null) {
            $worstFinding['frames_scanned'] = $framesScanned;
            $worstFinding['reason'] .= " (Video kadr tahlili: {$framesScanned} ta kadr ko'rildi)";
            $worstFinding['source'] = 'ai_vision_video';
            return $worstFinding;
        }

        return [
            'status' => 'safe',
            'category' => 'none',
            'reason' => "Tekshirilgan {$framesScanned} ta kadrda qoidabuzarlik topilmadi. Qayd: tanlangan kadrlar butun videoni 100% kafolatlamaydi.",
            'evidence' => '',
            'source' => 'ai_vision_video',
            'frames_scanned' => $framesScanned,
        ];
    }

    private function inspectSticker(string $path, string $extension, ?int $chatId, string $itemId): array
    {
        $ext = strtolower($extension);
        if ($ext === 'webp') {
            return $this->inspectPhoto($path, 'Sticker', $chatId, $itemId);
        }

        if ($ext === 'webm') {
            // WEBM video-stiker — oddiy video bilan bir xil FFmpeg kadr-ajratish
            // logikasi qayta ishlatiladi (video sifatida tekshiriladi). FFmpeg mavjud
            // bo'lmagan holat odatda ModerateMessageJob darajasida thumbnail-zaxira
            // bilan oldindan hal qilinadi; bu yerga to'g'ridan-to'g'ri yetib kelsa ham
            // inspectVideo() o'zi mos "ffmpeg_missing" natijasini qaytaradi.
            $result = $this->inspectVideo($path, 'Video-stiker', $chatId, $itemId);
            $result['source'] = $result['source'] === 'ai_vision_video' ? 'ai_vision_video_sticker' : $result['source'];
            return $result;
        }

        // TGS (Lottie) — FFmpeg orqali dekodlab bo'lmaydi. ModerateMessageJob darajasida
        // Telegram taqdim etadigan statik muqova (thumbnail) orqali tekshiriladi; bu yerga
        // to'g'ridan-to'g'ri yetib kelsa (masalan, muqova yo'q holatda), unscannable qaytariladi.
        return [
            'status' => 'unscannable',
            'category' => 'animated_sticker',
            'reason' => "Animatsion stiker formati ({$ext}) to'liq tahlil qilib bo'lmaydi",
            'evidence' => '',
            'source' => 'media_moderator',
            'frames_scanned' => 0,
        ];
    }

    /**
     * Ovozli xabar (voice, OGG/Opus) yoki audio fayl (audio, mp3/m4a va h.k.) tahlili.
     * SafeSearch zaxira mexanizmi (rasm-asosli) bu yerda qo'llanilmaydi — faqat AI
     * klientning (Gemini: native, OpenRouter: "unscannable" bilan gracious degradatsiya)
     * javobi ishlatiladi.
     */
    private function inspectVoice(string $path, string $caption, ?int $chatId, string $itemId, ?string $customModel = null): array
    {
        $res = $this->openRouter->moderateAudio($path, $caption, $itemId, $chatId, $customModel);
        $res['frames_scanned'] = 0;
        $res['source'] = $res['source'] ?? 'ai_audio';
        return $res;
    }

    private function inspectDocument(string $path, string $caption, ?int $chatId, string $itemId): array
    {
        $mime = mime_content_type($path) ?: '';
        if (str_starts_with($mime, 'image/')) {
            return $this->inspectPhoto($path, $caption, $chatId, $itemId);
        }
        if (str_starts_with($mime, 'video/')) {
            return $this->inspectVideo($path, $caption, $chatId, $itemId);
        }

        return [
            'status' => 'safe',
            'category' => 'document',
            'reason' => "Media bo'lmagan fayl hujjati ({$mime})",
            'evidence' => '',
            'source' => 'media_moderator',
            'frames_scanned' => 0,
        ];
    }

    private function getFromCache(string $key): ?array
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT result_json FROM moderation_cache WHERE cache_key = :k AND expires_at > :now");
            $stmt->execute(['k' => $key, 'now' => $now]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['result_json'], true) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function saveToCache(string $key, string $type, array $result, int $ttl): void
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("REPLACE INTO moderation_cache (cache_key, item_type, result_json, expires_at) VALUES (:k, :t, :j, :e)");
            $stmt->execute([
                'k' => $key,
                't' => $type,
                'j' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'e' => gmdate('Y-m-d H:i:s', time() + $ttl),
            ]);
        } catch (Throwable) {
        }
    }
}
