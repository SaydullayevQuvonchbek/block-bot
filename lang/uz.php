<?php

declare(strict_types=1);

/**
 * O'zbek tili (standart/fallback til). Kalitlar guruh a'zolariga bevosita
 * ko'rinadigan xabarlarni qamrab oladi: CAPTCHA (yangi a'zo tekshiruvi) va
 * jazo (ogohlantirish/mute/ban) xabarlari. Admin panel/DM buyruqlari va
 * ichki log xabarlari bu bosqichda hali tarjima qilinmagan — App\Core\Translator
 * va BLOCKBOT_2.0_TAHLIL.md 6-bandiga qarang.
 *
 * {parametr} shaklidagi joy egallovchilar App\Core\Translator::get() tomonidan
 * almashtiriladi.
 */
return [
    'common.user' => "Foydalanuvchi",

    // --- CAPTCHA (yangi a'zo tekshiruvi) ---
    'captcha.welcome' => "👋 {name}, xush kelibsiz!\n\n🤖 Bot emasligingizni tasdiqlash uchun <b>{timeout} soniya</b> ichida pastdagi tugmani bosing, aks holda guruhdan chiqarilasiz.",
    'captcha.button' => "✅ Men botman emas",
    'captcha.invalid_request' => "Noto'g'ri so'rov.",
    'captcha.not_your_button' => "Bu tugma sizga tegishli emas.",
    'captcha.expired' => "Bu tasdiqlash muddati allaqachon tugagan yoki bajarilgan.",
    'captcha.verified_toast' => "✅ Tasdiqlandingiz, xush kelibsiz!",
    'captcha.verified_message' => "✅ {user_link} tasdiqlandi, xush kelibsiz!",
    'captcha.timeout_message' => "⌛ {user_link} vaqtida tasdiqlamadi va guruhdan chiqarildi.",

    // --- Jazo xabarlari (foydalanuvchiga shaxsiy xabar sifatida yuboriladi) ---
    'punishment.warn' => "⚠️ <b>Ogohlantirish!</b>\nFoydalanuvchi: {user_link}\nSabab: {reason}\n<i>Ogohlantirishlar: {strike}/{limit}. Guruh qoidalariga rioya qiling!</i>",
    'punishment.mute' => "🔇 <b>Vaqtincha cheklov (MUTE)!</b>\nFoydalanuvchi: {user_link}\nMuddat: {hours} soat\nSabab: {reason}",
    'punishment.ban' => "🚫 <b>Guruhdan chetlatish (BAN)!</b>\nFoydalanuvchi: {user_link}\nSabab: {reason}",
    'punishment.appeal_hint' => "\n\n<i>Qaror noto'g'ri deb hisoblasangiz, quyidagi tugma orqali shikoyat yuboring.</i>\nHarakat ID: <code>{action_id}</code>\nMuqobil buyruq: <code>/appeal {action_id}</code>",
    'punishment.appeal_button' => "📝 Shikoyat qilish",
];
