<?php

declare(strict_types=1);

namespace App\Moderation;

class TextNormalizer
{
    /**
     * Kirill harflarining lotin muqobillari (Homoglyph replacement)
     */
    private const CYRILLIC_HOMOGLYPHS = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'j', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ў' => 'o\'', 'ғ' => 'g\'',
        'қ' => 'q', 'ҳ' => 'h',
    ];

    /**
     * Leetspeak (raqam va belgilarni harflarga almashtirish)
     */
    private const LEETSPEAK_MAP = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '@' => 'a',
        '5' => 's',
        '$' => 's',
        '7' => 't',
        '8' => 'b',
    ];

    /**
     * Matnni ko'p bosqichli normalizatsiya qilish (faqat tekshiruv nusxasi uchun)
     */
    public static function normalize(string $text): string
    {
        // 1. Kichik harflarga o'tkazish
        $text = mb_strtolower($text, 'UTF-8');

        // 2. Zero-width va ko'rinmas belgilarni tozalash
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{200E}\x{200F}]/u', '', $text);

        // 3. Barcha turdagi apostroflarni standart ' ga keltirish
        $text = preg_replace('/[\'’‘ʻʼ`]/u', "'", $text);

        // 4. Leetspeak almashtirishlari
        $text = strtr($text, self::LEETSPEAK_MAP);

        // 5. Kirill homoglyphlarini lotinlashtirish (aralash alifbodan himoya)
        $text = strtr($text, self::CYRILLIC_HOMOGLYPHS);

        // 6. Takrorlangan harflarni qisqartirish (masalan: "suuukaaa" -> "suka")
        // 3 yoki undan ko'p takrorlanishni 1 taga tushirish
        $text = preg_replace('/(.)\1{2,}/u', '$1', $text);

        return $text;
    }

    /**
     * Harflar orasiga ataylab qo'yilgan nuqta, chiziqcha va bo'shliqlarni olib tashlash
     * Masalan: "s.u.k.a" -> "suka", "b l y a t" -> "blyat"
     */
    public static function collapsePunctuation(string $text): string
    {
        $normalized = self::normalize($text);
        // 1. Harflar orasidagi nuqta, yulduzcha, tire yoki maxsus belgilarni tozalash (so'zlar orasidagi bo'shliqni buzmasdan)
        $collapsed = preg_replace('/(?<=\p{L})[._\-*~#]+(?=\p{L})/u', '', $normalized);

        // 2. Yagona harflar orasiga ataylab qo'yilgan bo'shliqlarni birlashtirish (masalan: "s u k a" -> "suka")
        $collapsed = preg_replace_callback('/(?:\b\p{L}\s+)+\b\p{L}\b/u', static function (array $matches): string {
            return preg_replace('/\s+/u', '', $matches[0]);
        }, $collapsed);

        return $collapsed;
    }

    /**
     * Matndagi barcha havolalarni (oddiy va xavfsiz URL manzillarini) ajratib olish
     */
    public static function extractUrls(string $text, array $entities = []): array
    {
        $urls = [];

        // Telegram entities orqali yashirin havolalarni olish
        foreach ($entities as $entity) {
            $type = $entity['type'] ?? '';
            $offset = (int)($entity['offset'] ?? 0);
            $length = (int)($entity['length'] ?? 0);

            if ($type === 'text_link' && !empty($entity['url'])) {
                $urls[] = $entity['url'];
            } elseif ($type === 'url') {
                $sub = mb_substr($text, $offset, $length, 'UTF-8');
                if (!empty($sub)) {
                    $urls[] = $sub;
                }
            }
        }

        // Matn ichidan regex orqali qolgan havolalarni topish
        if (preg_match_all('/(?:https?:\/\/|www\.)[^\s<>"\'{}|\\^`]+/i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return array_unique($urls);
    }
}
