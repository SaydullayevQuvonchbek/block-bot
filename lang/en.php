<?php

declare(strict_types=1);

/**
 * English. See lang/uz.php for the file structure and key meanings — the
 * same keys are translated here.
 */
return [
    'common.user' => "User",

    // --- CAPTCHA (new member verification) ---
    'captcha.welcome' => "👋 {name}, welcome!\n\n🤖 To confirm you're not a bot, press the button below within <b>{timeout} seconds</b>, or you'll be removed from the group.",
    'captcha.button' => "✅ I'm not a bot",
    'captcha.invalid_request' => "Invalid request.",
    'captcha.not_your_button' => "This button isn't for you.",
    'captcha.expired' => "This verification has already expired or was already completed.",
    'captcha.verified_toast' => "✅ Verified, welcome!",
    'captcha.verified_message' => "✅ {user_link} was verified, welcome!",
    'captcha.timeout_message' => "⌛ {user_link} didn't verify in time and was removed from the group.",

    // --- Punishment notices (sent to the user as a private message) ---
    'punishment.warn' => "⚠️ <b>Warning!</b>\nUser: {user_link}\nReason: {reason}\n<i>Warnings: {strike}/{limit}. Please follow the group rules!</i>",
    'punishment.mute' => "🔇 <b>Temporary restriction (MUTE)!</b>\nUser: {user_link}\nDuration: {hours}h\nReason: {reason}",
    'punishment.ban' => "🚫 <b>Removed from group (BAN)!</b>\nUser: {user_link}\nReason: {reason}",
    'punishment.appeal_hint' => "\n\n<i>If you believe this decision is wrong, send an appeal using the button below.</i>\nAction ID: <code>{action_id}</code>\nAlternative command: <code>/appeal {action_id}</code>",
    'punishment.appeal_button' => "📝 Appeal",
];
