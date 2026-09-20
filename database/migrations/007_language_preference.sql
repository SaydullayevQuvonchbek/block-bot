-- -----------------------------------------------------------------------------
-- Block-BOT 007: Guruh tili / ko'p tillilik (i18n) (2.0 Phase 3, 1-band)
-- -----------------------------------------------------------------------------
-- Guruh a'zolariga ko'rinadigan xabarlar (CAPTCHA, ogohlantirish/mute/ban) endi
-- shu ustunga qarab tanlangan tilda yuboriladi ('uz' — standart, 'ru', 'en').
-- Admin panel/DM buyruqlari bu bosqichda hali faqat o'zbek tilida qoladi —
-- BLOCKBOT_2.0_TAHLIL.md 6-bandiga muvofiq, "katta arxitektura o'zgarishisiz,
-- bosqichma-bosqich" amalga oshirilmoqda.

ALTER TABLE `group_settings`
    ADD COLUMN `language` VARCHAR(5) NOT NULL DEFAULT 'uz' AFTER `captcha_timeout_sec`;
