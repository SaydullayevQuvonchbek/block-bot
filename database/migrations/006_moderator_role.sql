-- -----------------------------------------------------------------------------
-- Block-BOT 006: Bot-darajasidagi "moderator" roli (2.0 Phase 2, 1-band)
-- -----------------------------------------------------------------------------
-- Telegram'ning o'zi sinxronlaydigan `chat_members.role` ustunidan (creator/
-- administrator/member) ATAYLAB ALOHIDA: bu ustun faqat botning ICHKI, cheklangan
-- huquqli "ishonchli a'zo" rolini bildiradi va Telegram tomonidan hech qachon
-- qayta yozilmaydi (AdminAuthorizationService::isAdmin() sinxronizatsiyasi faqat
-- `role` ustunini yangilaydi, `bot_role`ga tegmaydi).

ALTER TABLE `chat_members`
    ADD COLUMN `bot_role` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `role`;
