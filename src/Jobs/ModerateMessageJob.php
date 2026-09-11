<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Core\Logger;
use App\Core\QueueService;
use App\Moderation\MediaModerator;
use App\Moderation\TextModerator;
use App\Moderation\TextNormalizer;
use App\Policy\ModerationDecisionService;
use App\Policy\PunishmentService;
use App\Policy\SettingsService;
use Throwable;

class ModerateMessageJob
{
    public function handle(array $data): void
    {
        $message = $data['message'] ?? [];
        $isEdited = (bool)($data['is_edited'] ?? false);
        $chatId = (int)($data['chat_id'] ?? 0);
        // 'local'  = webhookда sinxron: faqat deterministik/mahalliy tekshiruv, AI'siz.
        // 'full'   = fon navbatida: AI matn + media tahlili.
        $phase = (string)($data['phase'] ?? 'full');
        $localOnly = $phase === 'local';

        if (empty($message) || $chatId === 0) {
            return;
        }

        $messageId = (int)($message['message_id'] ?? 0);
        $from = $message['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $rawText = (string)($message['text'] ?? $message['caption'] ?? '');
        $entities = $message['entities'] ?? $message['caption_entities'] ?? [];
        $mediaGroupId = $message['media_group_id'] ?? null;
        $this->persistMessage($message, $chatId, $userId, $rawText, $isEdited);

        $settings = SettingsService::get($chatId);
        $aiMode = (string)($settings['ai_mode'] ?? 'economical');

        $textModerator = new TextModerator();
        $mediaModerator = new MediaModerator();
        $punishmentService = new PunishmentService();

        // 1. Matnni tekshirish
        $finding = null;
        if (!empty($message['document'])) {
            $fileName = strtolower((string)($message['document']['file_name'] ?? ''));
            $mimeType = strtolower((string)($message['document']['mime_type'] ?? ''));
            if (str_ends_with($fileName, '.apk') || $mimeType === 'application/vnd.android.package-archive') {
                $finding = [
                    'status' => 'unsafe',
                    'category' => 'apk_distribution',
                    'reason' => 'Guruhda APK ilova faylini tarqatish taqiqlangan',
                    'evidence' => $fileName ?: $mimeType,
                    'source' => 'document_policy',
                ];
            }
        }
        if ($finding === null && !empty(trim($rawText))) {
            $finding = $textModerator->inspect(
                $rawText,
                $entities,
                $aiMode,
                $chatId,
                "msg_{$messageId}",
                [
                    'profanity_filter' => (bool)($settings['profanity_filter'] ?? true),
                    'porn_filter' => (bool)($settings['porn_filter'] ?? true),
                    'link_filter' => (bool)($settings['link_filter'] ?? true),
                ],
                !empty($settings['ai_model_text']) ? (string)$settings['ai_model_text'] : null,
                $localOnly
            );
        }

        // Mahalliy (sinxron) bosqich: AI yoki media tekshiruvi kerak bo'lsa — fon navbatiga.
        if ($localOnly) {
            $mediaPending = (($finding === null) || ($finding['status'] ?? '') === 'safe')
                && ($settings['media_filter'] ?? true)
                && $this->extractMediaInfo($message) !== null;

            if (($finding['status'] ?? '') === 'pending_ai' || $mediaPending) {
                QueueService::push('App\Jobs\ModerateMessageJob', [
                    'message' => $message,
                    'is_edited' => $isEdited,
                    'chat_id' => $chatId,
                    'phase' => 'full',
                ], QueueService::PRIORITY_HIGH);
                return;
            }
        }

        // 'pending_ai' hech qachon jazo asosiga aylanmaydi (xavfsizlik).
        if (($finding['status'] ?? '') === 'pending_ai') {
            return;
        }

        // 2. Media mavjud bo'lsa tekshirish (agar matn allaqachon qoidabuzar deb topilmagan bo'lsa)
        if (!$localOnly && ($finding === null || $finding['status'] === 'safe') && ($settings['media_filter'] ?? true)) {
            $mediaInfo = $this->extractMediaInfo($message);
            if ($mediaInfo !== null) {
                $visionModel = !empty($settings['ai_model_vision']) ? (string)$settings['ai_model_vision'] : null;
                $isVideoLike = in_array($mediaInfo['type'], ['video', 'animation'], true)
                    || ($mediaInfo['type'] === 'document' && str_starts_with((string)($mediaInfo['mime_type'] ?? ''), 'video/'));

                if ($isVideoLike && !$mediaModerator->isFfmpegAvailable()) {
                    if (!empty($mediaInfo['thumbnail_file_id'])) {
                        $finding = $mediaModerator->inspectVideoPreview(
                            (string)$mediaInfo['thumbnail_file_id'],
                            $rawText,
                            $chatId,
                            "med_preview_{$messageId}",
                            $visionModel
                        );
                    } else {
                        $finding = [
                            'status' => 'unscannable',
                            'category' => 'ffmpeg_missing',
                            'reason' => "FFmpeg va Telegram video muqovasi mavjud emas",
                            'evidence' => '',
                            'source' => 'media_moderator',
                            'frames_scanned' => 0,
                        ];
                    }
                } else {
                    $finding = $mediaModerator->inspectMedia(
                        $mediaInfo['file_id'],
                        $mediaInfo['type'],
                        $rawText,
                        $chatId,
                        "med_{$messageId}",
                        $visionModel
                    );
                }

                Logger::info("Media tahlili yakunlandi", [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'media_type' => $mediaInfo['type'],
                    'status' => $finding['status'] ?? 'unknown',
                    'category' => $finding['category'] ?? 'unknown',
                    'source' => $finding['source'] ?? 'unknown',
                    'model' => $finding['model'] ?? null,
                    'frames_scanned' => $finding['frames_scanned'] ?? 0,
                    'reason' => $finding['reason'] ?? '',
                ], 'moderation');
            }
        }

        if ($finding === null) {
            return;
        }

        if (in_array(($finding['category'] ?? ''), ['pornography', 'adult_profile'], true) && !($settings['porn_filter'] ?? true)) {
            return;
        }
        if (($finding['category'] ?? '') === 'profanity' && !($settings['profanity_filter'] ?? true)) {
            return;
        }

        // 3. Qoidabuzarlikni DB'ga yozish
        $findingId = null;
        if ($finding['status'] !== 'safe') {
            try {
                $pdo = Database::getConnection();
                $now = gmdate('Y-m-d H:i:s');
                $ins = $pdo->prepare("
                    INSERT INTO moderation_findings (chat_id, message_id, user_id, source, category, status, reason, evidence, model, created_at)
                    VALUES (:cid, :mid, :uid, :src, :cat, :st, :reason, :evi, :mod, :now)
                ");
                $ins->execute([
                    'cid' => $chatId,
                    'mid' => $messageId,
                    'uid' => $userId,
                    'src' => $finding['source'] ?? 'moderator',
                    'cat' => $finding['category'] ?? 'general',
                    'st' => $finding['status'],
                    'reason' => $finding['reason'] ?? '',
                    'evi' => $finding['evidence'] ?? '',
                    'mod' => $finding['model'] ?? null,
                    'now' => $now,
                ]);
                $findingId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                Logger::error("Moderation finding saqlashda xato: " . $e->getMessage(), [], 'moderation');
            }
        }

        // 4. Qaror qabul qilish va jazo qo'llash
        if ($isEdited && $this->messageAlreadyActioned($chatId, $messageId)) {
            Logger::info("Tahrirlangan xabar avval jazolangan; takroriy jazo berilmadi [chat: {$chatId}, msg: {$messageId}]", [], 'moderation');
            return;
        }
        $decision = ModerationDecisionService::decide($finding, $chatId, $userId, $messageId, $settings);
        $punishmentService->execute($chatId, $userId, $messageId, $decision, $mediaGroupId, $findingId);
    }

    private function extractMediaInfo(array $message): ?array
    {
        if (!empty($message['photo'])) {
            $photos = $message['photo'];
            $best = end($photos);
            return ['file_id' => $best['file_id'], 'type' => 'photo'];
        }
        if (!empty($message['video'])) {
            return [
                'file_id' => $message['video']['file_id'],
                'type' => 'video',
                'thumbnail_file_id' => $message['video']['thumbnail']['file_id'] ?? $message['video']['thumb']['file_id'] ?? null,
                'mime_type' => $message['video']['mime_type'] ?? 'video/mp4',
            ];
        }
        if (!empty($message['animation'])) {
            return [
                'file_id' => $message['animation']['file_id'],
                'type' => 'animation',
                'thumbnail_file_id' => $message['animation']['thumbnail']['file_id'] ?? $message['animation']['thumb']['file_id'] ?? null,
                'mime_type' => $message['animation']['mime_type'] ?? 'video/mp4',
            ];
        }
        if (!empty($message['sticker'])) {
            return ['file_id' => $message['sticker']['file_id'], 'type' => 'sticker'];
        }
        if (!empty($message['document'])) {
            return [
                'file_id' => $message['document']['file_id'],
                'type' => 'document',
                'thumbnail_file_id' => $message['document']['thumbnail']['file_id'] ?? $message['document']['thumb']['file_id'] ?? null,
                'mime_type' => $message['document']['mime_type'] ?? '',
            ];
        }

        return null;
    }

    private function persistMessage(array $message, int $chatId, int $userId, string $rawText, bool $isEdited): void
    {
        try {
            $pdo = Database::getConnection();
            $messageId = (int)($message['message_id'] ?? 0);
            $now = gmdate('Y-m-d H:i:s');
            $messageDate = gmdate('Y-m-d H:i:s', (int)($message['date'] ?? time()));
            $media = $this->extractMediaInfo($message);
            $stmt = $pdo->prepare("SELECT raw_text FROM messages WHERE chat_id = :cid AND message_id = :mid");
            $stmt->execute(['cid' => $chatId, 'mid' => $messageId]);
            $oldText = $stmt->fetchColumn();

            if ($oldText === false) {
                $insert = $pdo->prepare("
                    INSERT INTO messages (chat_id, message_id, user_id, sender_chat_id, media_group_id, media_type, raw_text, normalized_text, status, message_date, created_at)
                    VALUES (:cid, :mid, :uid, :sender, :album, :media, :raw, :normalized, 'active', :mdate, :now)
                ");
                $insert->execute([
                    'cid' => $chatId,
                    'mid' => $messageId,
                    'uid' => $userId ?: null,
                    'sender' => isset($message['sender_chat']['id']) ? (int)$message['sender_chat']['id'] : null,
                    'album' => $message['media_group_id'] ?? null,
                    'media' => $media['type'] ?? 'text',
                    'raw' => $rawText,
                    'normalized' => TextNormalizer::normalize($rawText),
                    'mdate' => $messageDate,
                    'now' => $now,
                ]);
                return;
            }

            if ($isEdited && (string)$oldText !== $rawText) {
                $pdo->prepare("INSERT INTO message_edits (chat_id, message_id, old_text, new_text, edit_date, created_at) VALUES (:cid, :mid, :old, :new, :edit, :now)")
                    ->execute(['cid' => $chatId, 'mid' => $messageId, 'old' => $oldText, 'new' => $rawText, 'edit' => $messageDate, 'now' => $now]);
                $pdo->prepare("UPDATE messages SET raw_text = :raw, normalized_text = :normalized, message_date = :mdate WHERE chat_id = :cid AND message_id = :mid")
                    ->execute(['raw' => $rawText, 'normalized' => TextNormalizer::normalize($rawText), 'mdate' => $messageDate, 'cid' => $chatId, 'mid' => $messageId]);
            }
        } catch (Throwable $e) {
            Logger::error("Xabar tarixini saqlashda xato: " . $e->getMessage(), [], 'database');
        }
    }

    private function messageAlreadyActioned(int $chatId, int $messageId): bool
    {
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM telegram_actions WHERE chat_id = :cid AND message_id = :mid AND status IN ('executed', 'partial')");
        $stmt->execute(['cid' => $chatId, 'mid' => $messageId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
