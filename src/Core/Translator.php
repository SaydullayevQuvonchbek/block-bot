<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Yengil i18n (ko'p tillilik) xizmati (2.0 Phase 3, 1-band). Faqat guruh
 * a'zolariga BEVOSITA ko'rinadigan xabarlarni (CAPTCHA, ogohlantirish/mute/ban)
 * qamrab oladi — admin panel/DM buyruqlari va ichki log xabarlari hozircha
 * o'zbek tilida qoladi (BLOCKBOT_2.0_TAHLIL.md 6-bandidagi tavsiyaga muvofiq,
 * "katta arxitektura o'zgarishisiz, bosqichma-bosqich" amalga oshirilmoqda).
 *
 * Tarjimalar `lang/{kod}.php` fayllarida oddiy PHP massivi sifatida saqlanadi
 * (har biri `return [...]`) — bironta tashqi kutubxona yoki .po/.mo formatiga
 * ehtiyoj yo'q. Kalit tanlangan tilda topilmasa, standart ('uz') tilga, undan
 * ham topilmasa kalitning o'zi qaytariladi (hech qachon fatal xato bermaydi).
 */
class Translator
{
    public const DEFAULT_LANG = 'uz';
    public const SUPPORTED = ['uz', 'ru', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * Berilgan til kodini qo'llab-quvvatlanadigan qiymatga normallashtiradi;
     * bo'sh, noma'lum yoki noto'g'ri qiymat standart ('uz')ga tushadi.
     */
    public static function normalizeLang(?string $lang): string
    {
        $lang = strtolower(trim((string)$lang));
        return in_array($lang, self::SUPPORTED, true) ? $lang : self::DEFAULT_LANG;
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $lang): array
    {
        if (isset(self::$cache[$lang])) {
            return self::$cache[$lang];
        }

        $file = dirname(__DIR__, 2) . '/lang/' . $lang . '.php';
        $data = is_file($file) ? (require $file) : [];
        self::$cache[$lang] = is_array($data) ? $data : [];
        return self::$cache[$lang];
    }

    /**
     * Tarjima matnini olib, {parametr} shaklidagi joy egallovchilarni
     * $params qiymatlari bilan almashtiradi.
     *
     * @param array<string, string|int|float> $params
     */
    public static function get(string $key, ?string $lang = null, array $params = []): string
    {
        $lang = self::normalizeLang($lang);
        $text = self::load($lang)[$key] ?? null;

        if ($text === null && $lang !== self::DEFAULT_LANG) {
            $text = self::load(self::DEFAULT_LANG)[$key] ?? null;
        }

        if ($text === null) {
            // Kalit hech qayerda topilmadi — jim tarzda kalitning o'zini
            // qaytaramiz (foydalanuvchiga xato ko'rsatish yoki crash o'rniga).
            return $key;
        }

        foreach ($params as $paramKey => $value) {
            $text = str_replace('{' . $paramKey . '}', (string)$value, $text);
        }

        return $text;
    }

    /**
     * Faqat testlar uchun: `lang/*.php`ni qayta o'qishga majburlash.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
