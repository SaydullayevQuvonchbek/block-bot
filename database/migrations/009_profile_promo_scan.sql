-- 2.0 Phase 6: yangi a'zo akkauntini AI orqali chuqurroq tekshirish.
--
-- Avval profil tekshiruvi faqat 18+ (pornografiya/adult_profile) va so'kinishga
-- munosabat bildirardi; reklama/skam akkauntlar (kripto-"treyder" signallari,
-- investitsiya/kazino targ'iboti, referal spam, "chat-bot" xizmat reklamasi)
-- AI tomonidan aniqlansa ham E'TIBORSIZ qoldirilardi. Endi bu kategoriyalar
-- ham akkaunt darajasidagi choraga olib keladi va chora turi shu ustun orqali
-- guruhga alohida sozlanadi.
--
-- Qiymatlar: 'notify' (faqat adminga xabar + "Ban" tugmasi),
--            'mute_notify' (standart — 30 kunga mute + adminga tasdiq tugmasi),
--            'ban' (darhol chetlatish).

ALTER TABLE group_settings
    ADD COLUMN spam_account_action VARCHAR(20) NOT NULL DEFAULT 'mute_notify';
