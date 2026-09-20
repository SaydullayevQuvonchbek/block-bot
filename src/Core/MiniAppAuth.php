<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Telegram Mini App (WebApp) autentifikatsiyasi — 2.0 Phase 3, 3-band
 * (Web Dashboard / Mini App). Telegram klient ilovasi (mobil/desktop) Mini App'ni
 * ochganda, unga `window.Telegram.WebApp.initData` qatorini beradi — bu qator
 * foydalanuvchi ma'lumotlari (`user`, JSON) va `hash` (bot tokeni bilan
 * HMAC-SHA256 imzo) dan iborat. Alohida login/parol yoki OAuth SHART EMAS —
 * Telegram'ning o'zi allaqachon foydalanuvchini tasdiqlagan, bizning vazifamiz
 * faqat shu imzoni tekshirish (rasmiy Telegram Mini Apps validatsiya algoritmi:
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app).
 *
 * Muhim: bu HECH QACHON tarmoq so'rovi yubormaydi — faqat mahalliy HMAC
 * hisoblash, shuning uchun tez va offline-xavfsiz.
 */
class MiniAppAuth
{
    /**
     * `initData` necha soniya "yangi" hisoblanadi — undan eski bo'lsa (masalan,
     * eski, saqlab qo'yilgan havola qayta ishlatilsa) rad etiladi.
     */
    private const MAX_AGE_SEC = 86400;

    /**
     * `initData` qatorini tekshiradi va haqiqiy bo'lsa foydalanuvchi kontekstini
     * qaytaradi. Imzo mos kelmasa, `auth_date` juda eski bo'lsa yoki `user`
     * maydoni yo'q/buzilgan bo'lsa — `null` (hech qachon istisno otmaydi).
     *
     * @return array{user_id: int, first_name: string, username: ?string, auth_date: int}|null
     */
    public static function verify(string $initData, ?string $botToken = null): ?array
    {
        $botToken = $botToken ?? (string)Config::get('TELEGRAM_BOT_TOKEN', '');
        if ($initData === '' || $botToken === '') {
            return null;
        }

        parse_str($initData, $data);
        if (!is_array($data) || !isset($data['hash']) || !is_string($data['hash'])) {
            return null;
        }

        $hash = $data['hash'];
        unset($data['hash']);
        if ($data === []) {
            return null;
        }

        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = "{$key}=" . (is_string($value) ? $value : (string)$value);
        }
        $checkString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (!hash_equals($calculatedHash, $hash)) {
            return null;
        }

        $authDate = (int)($data['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > self::MAX_AGE_SEC) {
            return null;
        }

        $userJson = (string)($data['user'] ?? '');
        $user = $userJson !== '' ? json_decode($userJson, true) : null;
        if (!is_array($user) || empty($user['id'])) {
            return null;
        }

        return [
            'user_id' => (int)$user['id'],
            'first_name' => (string)($user['first_name'] ?? ''),
            'username' => isset($user['username']) ? (string)$user['username'] : null,
            'auth_date' => $authDate,
        ];
    }
}
