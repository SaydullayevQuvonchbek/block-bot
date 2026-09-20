# O'zgarishlar tarixi (Changelog)

Ushbu loyiha [Semantic Versioning](https://semver.org/lang/ru/) tartibiga amal qiladi: `MAJOR.MINOR.PATCH`.

## [Unreleased] — v2.0.0-dev

### O'zgartirildi
- **Foydalanish qulayligi: guruh ID'sini qo'lda yozish bekor qilindi (2.0 Phase 5 — UX)**:
  avval bir nechta guruhni boshqaradigan admin `/premium`, `/til`, `/wordlist`,
  `/modlist`, `/exportsettings`, `/blockword`, `/importsettings` buyruqlarida
  `-1002341052295` kabi uzun guruh ID'sini QO'LDA ko'chirib yozishi kerak edi —
  bu amalda juda noqulay bo'lib chiqdi. Endi bot bunday holatda **guruh tanlash
  tugmalarini** chiqaradi va bir bosishda amal bajariladi. Argument talab
  qiladigan buyruqlarda (`/blockword so'z`, `/importsettings {JSON}`) tugmali
  xabar foydalanuvchining asl buyrug'iga *javob* (reply) sifatida yuboriladi —
  tugma bosilganda asl matn `callback_query.message.reply_to_message`dan qayta
  o'qiladi, shuning uchun hech qanday yangi jadval yoki "kutilayotgan amal"
  holati saqlanmaydi (migratsiya talab qilinmaydi).
- **Phase 3-4 funksiyalari sozlamalar paneliga ko'chirildi (2.0 Phase 5 — UX)**:
  til, tarif (Premium), so'zlar ro'yxati, moderatorlar, sozlamalar eksporti va
  boshqa guruhga nusxalash — barchasi endi mavjud `⚙️ Guruh sozlamalari`
  tugmalar panelida. Ish oqimi yagona bo'ldi: `/mygroups` → guruh → hamma narsa
  bitta panelda, matnli buyruq yozish shart emas.
- **`/til` va `/clonesettings` to'liq tugmali bo'ldi**: `/til` endi til kodini
  yozishni so'ramaydi — 🇺🇿/🇷🇺/🇬🇧 tugmalari ko'rsatiladi (joriy til ✅ bilan
  belgilanadi). `/clonesettings` ikkita uzun ID o'rniga ikki bosqichli tanlovga
  o'tdi: avval manba guruh tugmasi, so'ng maqsad guruh tugmasi (maqsad
  ro'yxatidan manba guruhning o'zi chiqarib tashlanadi).
- Eski `/buyruq -100... argument` sintaksisi **saqlanib qoldi** — skript yoki
  odatiga ko'ra ID bilan yozadiganlar uchun hech narsa buzilmadi. Testlar:
  95 → **99** (yangi 4 ta test guruh tanlash tugmalari, til tugmasi,
  `reply_to_message`dan argument tiklash va ikki bosqichli klonlashni qamraydi).

### Qo'shildi
- **Ko'p tillilik / i18n (2.0 Phase 3, 1-band)**: guruh a'zolariga BEVOSITA
  ko'rinadigan xabarlar — yangi a'zo CAPTCHA'si (xush kelibsiz matni, tugma,
  tasdiqlandi/muddat tugadi xabarlari) va jazo bildirishnomalari (ogohlantirish/
  mute/ban + shikoyat taklifi, foydalanuvchiga shaxsiy xabar sifatida) — endi
  guruh tanlagan tilda yuboriladi: o'zbekcha (standart), ruscha yoki inglizcha.
  Yangi `App\Core\Translator` xizmati + `lang/uz.php`, `lang/ru.php`, `lang/en.php`
  (oddiy `return [...]` massiv fayllari, hech qanday tashqi i18n kutubxonasiz —
  BLOCKBOT_2.0_TAHLIL.md 6-bandidagi tavsiyaga aynan muvofiq). Tanlangan tilda
  kalit topilmasa standart ('uz')ga, undan ham topilmasa kalitning o'ziga
  tushadi — hech qachon xato bermaydi. Yangi ustun: `group_settings.language`
  (standart `'uz'`, migratsiya `database/migrations/007_language_preference.sql`,
  SQLite test sxemasi versiyasi `sqlite_schema_v7`ga oshirildi). Yangi DM
  buyrug'i **`/til [guruh_id] [uz|ru|en]`** — argumentsiz joriy tilni ko'rsatadi,
  kod bilan chaqirilsa o'zgartiradi (noto'g'ri kod — xato xabari, hech narsa
  o'zgarmaydi). **Ataylab tor qamrov**: bu bosqichda faqat yuqoridagi ikki oqim
  tarjima qilindi — admin panel (`/settings` va h.k.), boshqa DM buyruqlari,
  `/help` matni va ichki log xabarlari hozircha faqat o'zbek tilida qoladi
  (butun 32 ta fayldagi minglab qattiq-yozilgan matnni bir yo'la tarjima qilish
  juda katta va xavfli o'zgarish bo'lardi — BLOCKBOT_2.0_TAHLIL.md'ning o'zi
  ham "bosqichma-bosqich" deb tavsiya qilgan). Yangi test: 4 ta —
  `testTranslatorFallsBackToDefaultForUnsupportedLanguageAndMissingKey`,
  `testCaptchaMessagesUseGroupLanguage`, `testPunishmentNoticeUsesGroupLanguage`,
  `testLanguageCommandShowsAndChangesGroupLanguage` — jami endi **77/77 test
  o'tadi** (bir necha marta ketma-ket ishga tushirilib tasdiqlangan, barqaror).
  Qo'shimcha tasdiqlash: `bin/migrate.php` haqiqiy (`:memory:` emas) SQLite
  faylga qarshi qo'lda ishga tushirilib, `group_settings.language` ustuni
  to'g'ri (`DEFAULT 'uz'`) yaratilgani tekshirildi.
  (`src/Core/Translator.php`, `lang/uz.php`, `lang/ru.php`, `lang/en.php`,
  `src/Policy/PunishmentService.php`, `src/Policy/SettingsService.php`,
  `src/Http/UpdateRouter.php`, `src/Jobs/CaptchaTimeoutJob.php`,
  `database/migrations/007_language_preference.sql`,
  `database/migrations/sqlite_schema.sql`, `bin/migrate.php`,
  `tests/ModerationBotTest.php`)
- **Telegram Stars monetizatsiyasi / Premium tarif (2.0 Phase 3, 2-band)**: har
  bir GURUH uchun alohida bepul/premium holat. **Bepul** tarifda kunlik AI
  so'rovlar soni cheklanadi (standart `FREE_TIER_DAILY_AI_REQUESTS=150`,
  matn/rasm/video/ovoz birgalikda — mavjud `ai_usage` jadvali orqali hisoblanadi,
  yangi kuzatuv jadvali shart emas edi); chegaradan oshgach ham mahalliy qoidalar
  (so'z bloklash, havola filtri, flood-guard, CAPTCHA) ishlashda davom etadi —
  faqat AI tahlil o'sha kun uchun to'xtaydi. Yagona to'siq nuqtasi: yangi
  `App\Policy\SubscriptionService::canUseAi()` — `GeminiClient::callGemini()`
  va `OpenRouterClient::callWithFallback()` ichida, MAVJUD $ byudjet tekshiruvi
  bilan bir qatorda, undan OLDIN chaqiriladi — shu sabab barcha AI turlari
  (matn/rasm/video/ovoz, ikkala provayder) `TextModerator`/`MediaModerator`
  dispatch mantig'iga tegmasdan avtomatik qamrab olindi. **Premium**: AI
  tekshiruvlar kunlik chegarasiz, [Telegram Stars](https://telegram.org/blog/telegram-stars)
  (botning ichki valyutasi, `currency: XTR`, tashqi to'lov provayderi shart
  emas) orqali sotib olinadi — standart narx `PREMIUM_STARS_PRICE=200` Stars /
  `PREMIUM_DURATION_DAYS=30` kun. Yangi DM buyrug'i **`/premium [guruh_id]`** —
  joriy tarif holatini (bepul: bugungi AI so'rovlar soni; premium: tugash
  sanasi) va "⭐ Premium sotib olish" inline tugmasini ko'rsatadi. To'lov oqimi:
  tugma → `sendInvoice()` (admin shaxsiy chatiga, `payload: "premium_v1:{guruh_id}:{kun}"`)
  → Telegram `pre_checkout_query` yuboradi (10 soniya ichida `answerPreCheckoutQuery()`
  bilan SINXRON javob berilishi shart — webhook'da navbatga qo'yilmaydi) → to'lov
  yakunlangach shaxsiy chatga oddiy `message` sifatida `successful_payment` keladi.
  Har bir to'lov `star_payments` jadvalida `UNIQUE(telegram_payment_charge_id)`
  bo'yicha idempotent qayd etiladi (`INSERT OR IGNORE`/`INSERT IGNORE` + `rowCount()`
  tekshiruvi) — Telegram webhookni ehtimoliy qayta yuborsa ham, premium ikki marta
  berilmaydi. Muddatidan oldin qayta sotib olinsa — qolgan kunlar yo'qolmaydi
  (`activatePremium()` mavjud muddatdan boshlab uzaytiradi, "stacking"). Yangi
  ustun: `group_settings.premium_expires_at` (`NULL`/o'tmish = bepul, tekshiruv
  har chaqiriqda "lazy" — alohida cron shart emas), yangi jadval `star_payments`
  (migratsiya `database/migrations/008_premium_subscription.sql`, SQLite test
  sxemasi versiyasi `sqlite_schema_v8`ga oshirildi). Webhook `allowed_updates`
  ro'yxatiga `pre_checkout_query` qo'shildi (`bin/set_webhook.php`, `bin/doctor.php`
  — bo'lmasa to'lovlar ishlamaydi, `doctor.php` buni ogohlantiradi). Yangi
  `.env` kalitlari: `FREE_TIER_DAILY_AI_REQUESTS`, `PREMIUM_STARS_PRICE`,
  `PREMIUM_DURATION_DAYS` (barchasi ixtiyoriy, standart qiymatlar bilan). Yangi
  test: 7 ta — `testSubscriptionServiceEnforcesFreeTierDailyLimitAndPremiumBypass`,
  `testAiClientReturnsFreeTierLimitReachedWhenDailyLimitExceeded`,
  `testRecordStarPaymentIsIdempotentAndStacksOnRepeatPurchase`,
  `testPremiumStatusCommandShowsFreeAndPremiumStates`,
  `testBuyPremiumCallbackSendsInvoiceWithCorrectPayload`,
  `testPreCheckoutQueryAcceptsValidAndRejectsInvalidPayload`,
  `testSuccessfulPaymentActivatesPremiumAndIsIdempotent` — jami endi **84/84
  test o'tadi** (bir necha marta ketma-ket ishga tushirilib tasdiqlangan,
  barqaror).
  (`src/Policy/SubscriptionService.php`, `src/AI/GeminiClient.php`,
  `src/AI/OpenRouterClient.php`, `src/Core/TelegramClient.php`,
  `src/Http/UpdateRouter.php`, `src/Policy/SettingsService.php`,
  `database/migrations/008_premium_subscription.sql`,
  `database/migrations/sqlite_schema.sql`, `bin/migrate.php`,
  `bin/set_webhook.php`, `bin/doctor.php`, `.env.example`,
  `tests/TestCase.php`, `tests/ModerationBotTest.php`)
- **Web Dashboard / Telegram Mini App (2.0 Phase 3, 3-band)**: admin uchun botning
  bosh menyusidan (`/menu`) ochiladigan Telegram Mini App (WebApp) — alohida login/
  parol yoki OAuth shart emas, Telegram o'zi foydalanuvchini allaqachon tasdiqlagan.
  Yangi `App\Core\MiniAppAuth::verify()` — Telegram WebApp `initData`sini rasmiy
  validatsiya algoritmi bo'yicha tekshiradi (bot tokeni bilan HMAC-SHA256 imzo,
  `auth_date` eskirganini rad etadi) — tashqi tarmoq so'rovisiz, faqat mahalliy
  hisoblash. Yangi `App\Http\MiniAppApiRouter` + `public/api.php` — `?action=...`
  orqali ishlaydigan yagona REST kirish nuqtasi (shared-hosting'da maxsus URL-rewrite
  qoidalarisiz ishlashi uchun, xuddi `webhook.php`/`healthz.php` kabi): `me` (boshqarilgan
  guruhlar ro'yxati), `overview` (tarif holati, bugungi AI ishlatish, faol ogohlantirish/
  shikoyat soni), `settings_get`/`settings_update` (mavjud `SettingsService`ning o'zi
  orqali), `warnings` (faol ogohlantirishlar ro'yxati), `unmute`/`unban`/`resetwarns`
  (mavjud `PunishmentService` orqali), `appeals` (kutilayotgan shikoyatlar ro'yxati),
  `review_appeal` (qabul/rad — qabul qilinsa mute/ban avtomatik olib tashlanadi).
  **Xavfsizlik**: har bir guruhga oid amal, frontend'dan kelgan `chat_id`dan qat'i
  nazar, so'rovchi HAQIQATDA o'sha guruh admini ekanini serverda qayta tekshiradi
  (`AdminAuthorizationService::isAdmin()`). Kichik refaktor: guruh-adminlik ro'yxati
  so'rovi (`adminGroupsOf`) endi `AdminAuthorizationService::adminGroupsOfUser()`
  YAGONA joyidan olinadi — `UpdateRouter` (DM buyruqlari) va yangi Mini App API
  ikkalasi ham shu bitta manbadan foydalanadi, SQL dublikat qilinmagan.
  **Frontend**: `public/miniapp/index.html` — bitta HTML fayl, build-qadamsiz,
  faqat Telegram'ning rasmiy `telegram-web-app.js` skripti bilan (loyihaning
  "composer/npm shart emas" falsafasiga mos), Telegram mavzu ranglariga
  avtomatik moslashadi (guruh tanlash → Umumiy/Sozlamalar/Moderatsiya tab'lari).
  Botning bosh menyusiga yangi "📊 Dashboard" (`web_app` turidagi) tugma qo'shildi —
  FAQAT ixtiyoriy `MINIAPP_URL` (yoki undan hosil bo'lgan `TELEGRAM_WEBHOOK_URL`
  domeni) `https://` bilan boshlanganda ko'rsatiladi, aks holda shunchaki
  ko'rsatilmaydi (xatoga olib kelmaydi). Yangi test: 6 ta —
  `testMiniAppAuthVerifiesValidRejectsTamperedAndExpired`,
  `testMiniAppApiRouterRejectsInvalidAuthAndListsAdminGroups`,
  `testMiniAppApiRouterOverviewAndSettingsRoundTrip`,
  `testMiniAppApiRouterWarningsListAndModerationActions`,
  `testMiniAppApiRouterAppealsListAndReview`,
  `testDashboardButtonShownOnlyWithValidHttpsMiniAppUrl` — jami endi **90/90
  test o'tadi** (4 marta ketma-ket ishga tushirilib tasdiqlangan, barqaror).
  Qo'shimcha qo'lda tekshiruv: `public/api.php` va `public/miniapp/index.html`
  PHP built-in server orqali haqiqiy HTTP so'rovlar bilan sinovdan o'tkazildi
  (to'g'ri HTTP status kodlari — 400/401 — va JSON javoblar tasdiqlandi).
  (`src/Core/MiniAppAuth.php`, `src/Http/MiniAppApiRouter.php`, `public/api.php`,
  `public/miniapp/index.html`, `src/Http/UpdateRouter.php`,
  `src/Policy/AdminAuthorizationService.php`, `.env.example`,
  `tests/ModerationBotTest.php`)
- **Forum-mavzular (topics) qo'llab-quvvatlashi (2.0 Phase 4, 1-band)**: Telegram
  "Forum" turidagi superguruhlarda (nomlangan mavzular/topics yoqilgan) botning
  javoblari endi foydalanuvchi qaysi mavzuda yozgan bo'lsa, aynan o'sha mavzuga
  qaytariladi — avval hammasi standart "General" mavzusiga tushib ketardi.
  Telegram xabarlarida forum-mavzu ichida yozilgan xabar `is_topic_message: true`
  va `message_thread_id: N` bilan keladi (oddiy guruh yoki "General" mavzusida
  ikkalasi ham yo'q). Yangi ikkita xususiy yordamchi metod —
  `UpdateRouter::threadIdFrom(array $message): ?int` (shartlarni tekshirib
  ID'ni ajratadi yoki `null`) va `UpdateRouter::threadExtra(array $message): array`
  (`['message_thread_id' => N]` yoki bo'sh massiv — to'g'ridan-to'g'ri
  `TelegramClient::sendMessage()`ning uchinchi `$extra` argumentiga qo'shiladi,
  klient darajasida hech qanday o'zgarish shart emas edi). Threading qo'llanilgan
  joylar: yangi a'zo uchun CAPTCHA xush kelibsiz xabari, moderator uchun
  "faqat administratorlar uchun" rad javobi, `/warnings` ro'yxati va xato
  xabarlari, `/addmod`/`/removemod` (rol-boshqaruv) tasdiqlash/rad xabarlari.
  **Ataylab o'zgartirilmagan**: `deleteMessage`, `restrictChatMember`
  (mute/unmute), `banChatMember`/`unbanChatMember` — Telegram Bot API'da bular
  umuman `message_thread_id` parametrini qabul qilmaydi (mavzudan mustaqil
  ishlaydi); shaxsiy chat (DM)ga yuboriladigan xabarlar ham (jazo bildirishnomasi,
  `/premium`, Dashboard tugmasi va h.k.) — bular hech qachon guruh mavzusiga
  bog'liq emas. `PunishmentService` ham o'zgartirilmadi — u guruh chatiga hech
  qachon to'g'ridan-to'g'ri `sendMessage` qilmaydi (faqat DM yoki admin-log
  kanaliga). Yangi test: 2 ta —
  `testCaptchaWelcomeMessageUsesMessageThreadIdInsideForumTopic`,
  `testGroupCommandRepliesRespectForumTopicThreadId` — jami endi **92/92
  test o'tadi** (4 marta ketma-ket ishga tushirilib tasdiqlangan, barqaror;
  mavjud 90 ta test forum-topics maydonlarisiz o'zgarishsiz o'tishda davom etadi).
  (`src/Http/UpdateRouter.php`, `tests/ModerationBotTest.php`)
- **Guruhlar orasida sozlamalarni klonlash / eksport-import (2.0 Phase 4, 2-band)**:
  ko'p guruhli admin uchun bitta guruhda sozlangan siyosatni (filtrlar,
  ogohlantirish/mute chegaralari, anti-flood, CAPTCHA, til va h.k.) boshqa
  guruhlarga qayta-qayta qo'ldan sozlamasdan ko'chirish imkoni. Yangi
  `SettingsService::CLONEABLE_COLUMNS` — mavjud `ALLOWED_UPDATE_COLUMNS`ning
  ATAYLAB torroq pastki to'plami: `log_chat_id` (guruhga xos infratuzilma
  ko'rsatkichi — boshqa guruhga ko'chirilsa noto'g'ri kanalga log ketishi
  mumkin), `premium_expires_at` (to'lov holati, "sozlama" emas) va
  `ai_model_text`/`ai_model_vision` (erkin matnli model nomlari — import
  orqali qabul qilinsa AI so'rovlarini buzishi mumkin) chiqarib tashlangan.
  Yangi `SettingsService::exportSettings()`/`cloneInto()`.
  - **`/clonesettings manba_guruh_id maqsad_guruh_id`**: JSON'siz, to'g'ridan-
    to'g'ri nusxalash. Chaqiruvchi IKKALA guruhning ham (manba VA maqsad)
    admini bo'lishi SHART — aks holda o'zi boshqarmagan guruhning
    sozlamalarini o'qib/yozib bo'lardi.
  - **`/exportsettings [guruh_id]`**: joriy sozlamalarni tuzilgan JSON
    ko'rinishida (`<pre>` blokida) qaytaradi — zaxira sifatida saqlash yoki
    boshqa guruhga qo'lda ko'chirish uchun.
  - **`/importsettings guruh_id {...JSON...}`**: eksport qilingan (yoki
    qo'lda tuzilgan) JSON'ni tekshirib guruhga qo'llaydi. Yangi
    `UpdateRouter::sanitizeSettingsImport()` — har bir maydonni turiga qarab
    (mantiqiy 0/1, butun son diapazoni, yoki qat'iy enum ro'yxati — masalan
    `ai_mode: comprehensive|economical`, `porn_action: ban|mute|warn`)
    tekshiradi; noma'lum, ruxsat etilmagan yoki noto'g'ri qiymatli maydonlar
    FATAL XATOSIZ jim tashlab ketiladi va javobda alohida "qo'llandi"/
    "o'tkazib yuborildi" ro'yxati sifatida ko'rsatiladi — import hech qachon
    guruhni yarim-buzilgan holatga keltirmaydi.
  - Barcha uchta buyruq mavjud `resolvePrivateManagedChat()`/
    `managedGroupsHintText()` infratuzilmasidan (bitta guruh boshqarilsa
    avtomatik tanlanadi, bir nechtasi bo'lsa guruh ID talab qilinadi)
    foydalanadi — `/til`, `/wordlist`, `/premium` bilan bir xil UX.
  - **Hujjatlar**: `README.md` buyruqlar jadvaliga uchta yangi qator, `/help`
    matniga qo'shildi.
  - Yangi test: 2 ta — `testCloneSettingsCopiesCloneableFieldsBetweenGroupsWhenAuthorized`
    (muvaffaqiyatli klonlash + faqat bitta/hech qaysi guruhning admini
    bo'lmagan foydalanuvchi rad etilishini ham tekshiradi),
    `testExportSettingsProducesJsonAndImportSettingsValidatesFields`
    (eksport JSON'ining to'g'riligi, `premium_expires_at`/`log_chat_id`ning
    eksportga chiqmasligi, import paytida noto'g'ri enum/ruxsat etilmagan/
    noma'lum maydonlarning xavfsiz o'tkazib yuborilishi) — jami endi
    **94/94 test o'tadi** (4 marta ketma-ket ishga tushirilib tasdiqlangan,
    barqaror).
  (`src/Policy/SettingsService.php`, `src/Http/UpdateRouter.php`,
  `README.md`, `tests/ModerationBotTest.php`)
- **Rejalashtirilgan/ommaviy xabar yuborish — broadcast (2.0 Phase 4, 3-band)**:
  ko'p guruhli admin uchun bitta e'lon yoki ogohlantirishni botning o'zi
  orqali BOSHQARGAN barcha guruhlariga bir yo'la yuborish imkoni. Yangi
  **`/broadcast matn`** DM buyrug'i — matnsiz chaqirilsa foydalanish
  yo'riqnomasi + boshqariladigan guruhlar soni/ro'yxatini ko'rsatadi.
  Yangi `App\Jobs\BroadcastMessageJob` — har bir guruh uchun ALOHIDA
  `QueueService` vazifasi (bitta guruhga yetkazib bo'lmasa — masalan bot
  guruhdan chiqarilgan yoki admin huquqidan mahrum qilingan bo'lsa —
  qolganlariga ta'sir qilmaydi, xato shunchaki loglanadi). Telegram'ning
  umumiy bot tezlik chegarasidan (~30 xabar/soniya) saqlanish uchun har
  20 ta guruhdan keyin navbatga qo'yishda +1 soniya kechikish qo'shiladi.
  Admin kiritgan matn `htmlspecialchars()` bilan ekranlanadi va qat'iy
  belgilangan HTML shablonga ("📢 <b>Administrator xabari</b>") joylashtiriladi
  — xom holda o'tkazilmaydi (noto'g'ri/tugallanmagan HTML teglar Telegram
  API xatosiga olib kelmasligi uchun).
  - **Hujjatlar**: `README.md` buyruqlar jadvaliga yangi qator, `/help` matniga qo'shildi.
  - Yangi test: 1 ta — `testBroadcastCommandQueuesJobPerManagedGroupWithEscapedText`
    (har bir boshqariladigan guruh uchun to'g'ri job navbatga qo'yilishi,
    matn ekranlanishi, matnsiz chaqirilganda hech narsa navbatga
    qo'yilmasligi) — jami endi **95/95 test o'tadi** (4 marta ketma-ket
    ishga tushirilib tasdiqlangan, barqaror).
  (`src/Jobs/BroadcastMessageJob.php`, `src/Http/UpdateRouter.php`,
  `README.md`, `tests/ModerationBotTest.php`)
- **Docker qo'llab-quvvatlashi**: `docker/php/Dockerfile` (PHP 8.3-FPM Alpine, ffmpeg bilan),
  `docker/nginx/default.conf`, `docker-compose.yml` (app + worker + nginx + MySQL 8 xizmatlari,
  production'dagi kabi `public/` web-root topologiyasi bilan), `.env.docker.example`, `.dockerignore`.
- **CI (GitHub Actions)**: `.github/workflows/ci.yml` — har bir push/PR'da PHP 8.1/8.2/8.3 ustida
  `php -l` sintaksis tekshiruvi, `bin/run_tests.php` test to'plami (SQLite xotira bazasi bilan) va
  Docker tasvirini yig'ish smoke-testi.
- Ushbu `CHANGELOG.md` — kelgusi barcha 2.0 o'zgarishlari shu faylga yoziladi.
- **Anti-flood / spam-portlash himoyasi (2.0 Phase 1, 1-band)**: `src/Moderation/FloodGuard.php` —
  har bir guruh a'zosi uchun "fixed window" hisoblagich (bitta qator `flood_counters` jadvalida).
  Standart holatda 10 soniyada 6 tadan ortiq xabar yuborgan a'zo avtomatik 10 daqiqaga mute
  qilinadi (`PunishmentService` orqali, mavjud rollback/shikoyat mexanizmi bilan birga ishlaydi).
  Har bir guruh uchun alohida sozlanadi: yangi `group_settings` ustunlari `flood_enabled`
  (yoqish/o'chirish — `/settings` menyusida tugma bilan ham), `flood_max_messages`,
  `flood_window_sec`, `flood_mute_duration_sec`. Adminlar va oq ro'yxatdagi a'zolar bundan ozod
  (flood tekshiruvi admin/whitelist tekshiruvidan keyin ishlaydi). Flood sababli mute oddiy
  ogohlantirish zinapoyasiga (warn→mute→ban) qo'shilmaydi — mustaqil, alohida turdagi chora.
  Yangi migratsiya: `database/migrations/004_flood_control.sql` (`bin/migrate.php` orqali
  qo'llaniladi; SQLite test sxemasi ham yangilandi, versiya `sqlite_schema_v4`ga oshirildi).
  4 ta yangi test: `testFloodGuardMutesFastSender`, `testFloodGuardDoesNotMuteAdmins`,
  `testFloodDisabledSettingSkipsFloodCheck`, `testFloodGuardResetsAfterWindowExpires`.
  (`src/Moderation/FloodGuard.php`, `src/Http/UpdateRouter.php`, `src/Policy/SettingsService.php`,
  `database/migrations/004_flood_control.sql`, `database/migrations/sqlite_schema.sql`,
  `bin/migrate.php`, `config/moderation.php`, `tests/ModerationBotTest.php`, `tests/TestCase.php`)
- **Yangi a'zolar uchun CAPTCHA/tasdiqlash (2.0 Phase 1, 2-band)**: `src/Moderation/CaptchaGuard.php`
  — yangi `captcha_pending` jadvalida tasdiqlash holatini kuzatuvchi mahalliy (Telegram'ga bog'liq
  bo'lmagan) hisoblagich. Standart holatda **o'chirilgan** (`group_settings.captcha_enabled=0`,
  `/settings` menyusida tugma bilan yoqiladi). Yoqilgan guruhda: bot bo'lmagan yangi a'zo qo'shilishi
  bilan darhol `muteUser()` orqali xabar yozish huquqidan mahrum qilinadi va guruhga "✅ Men botman
  emas" tugmali xabar yuboriladi; `captcha_timeout_sec` (standart 60s) ichida shu foydalanuvchining
  o'zi tugmani bossa — cheklov olib tashlanadi (`captcha_verify:{chat_id}:{user_id}` callback,
  faqat nishonlangan foydalanuvchi bosishi mumkin). Vaqtida bosilmasa — yangi `src/Jobs/
  CaptchaTimeoutJob.php` (`QueueService`ga kechiktirilgan holda, `delaySeconds=captcha_timeout_sec+5`
  bilan navbatga qo'yiladi) foydalanuvchini guruhdan chetlatadi (kick — ban+darhol unban, doimiy
  ban emas). Tasdiqlash/kick — mavjud tugma bosilishi bilan CaptchaTimeoutJob orasidagi poyga holati
  atomik `UPDATE ... WHERE status='pending'` orqali hal qilinadi (qaysi biri birinchi bo'lsa, o'sha
  g'olib). Yangi migratsiya: `database/migrations/005_captcha_verification.sql` (SQLite test sxemasi
  ham yangilandi, versiya `sqlite_schema_v5`ga oshirildi). 4 ta yangi test:
  `testCaptchaDisabledByDefaultDoesNotRestrictNewMember`,
  `testCaptchaRestrictsNewMemberAndVerifyButtonUnlocks`, `testCaptchaVerifyRejectsDifferentUser`,
  `testCaptchaTimeoutJobKicksUnverifiedMember` (jami endi **58/58 test o'tadi**).
  (`src/Moderation/CaptchaGuard.php`, `src/Jobs/CaptchaTimeoutJob.php`, `src/Http/UpdateRouter.php`,
  `src/Policy/SettingsService.php`, `database/migrations/005_captcha_verification.sql`,
  `database/migrations/sqlite_schema.sql`, `bin/migrate.php`, `config/moderation.php`,
  `tests/ModerationBotTest.php`, `tests/TestCase.php`)
- **Ovozli/audio xabarlar moderatsiyasi (2.0 Phase 1, 3-band)**: guruhga yuborilgan ovozli
  xabarlar (`voice`, OGG/Opus) va audio fayllar (`audio`, mp3/m4a va h.k.) endi boshqa media
  turlari (rasm/video) bilan bir xil tarzda AI orqali tekshiriladi — avval bular butunlay
  e'tiborsiz qoldirilar edi. `GeminiClient::moderateAudio()` — Gemini'ning native audio kiritish
  qo'llab-quvvatlashi (`inline_data`, `mime_type: audio/...`) orqali ishlaydi, **qayta kodlash
  (transcoding) shart emas**: Telegram voice fayli to'g'ridan-to'g'ri yuboriladi (xuddi rasm
  tahlili bilan bir xil mexanizm — `callGemini()` umumiy yordamchisi orqali). Model ixtiyoriy
  `GEMINI_AUDIO_MODEL` bilan sozlanadi, belgilanmasa `GEMINI_VISION_MODEL` bilan bir xil
  ishlatiladi (yangi majburiy `.env` o'zgaruvchisi shart emas). `OpenRouterClient::moderateAudio()`
  — asosiy (base) klassda xavfsiz "gracious degradatsiya" standart amalga oshirilgan: OpenRouter
  chat-completions API'si Telegram voice OGG/Opus formatini rasman qo'llab-quvvatlamagani sababli
  tarmoq so'rovi umuman yuborilmaydi, natija darhol `status: unscannable,
  category: audio_unsupported_provider` bilan qaytadi (production `AI_PROVIDER=gemini` bilan
  ishlagani uchun haqiqiy tahlil productionda to'liq ishlaydi). `MediaModerator::inspectMedia()`
  endi `voice`/`audio` turlarini yangi `inspectVoice()` metodiga yo'naltiradi (SafeSearch zaxira
  tekshiruvi — rasm-asosli mexanizm — audio uchun qo'llanilmaydi). `ModerateMessageJob::
  extractMediaInfo()` endi `message.voice` va `message.audio` maydonlarini taniydi. Yangi
  sozlama qo'shilmadi — mavjud `media_filter` sozlamasi (rasm/video/stiker bilan bir xil)
  ovozli/audio xabarlarni ham boshqaradi. 5 ta yangi test:
  `testLocalPhaseQueuesVoiceAndAudioMessagesForFullModeration`,
  `testVoiceMessageSkippedWhenMediaFilterDisabled`,
  `testMediaModeratorDispatchesVoiceAndAudioToModerateAudio`,
  `testOpenRouterModerateAudioGracefullyDegrades` (jami endi **62/62 test o'tadi**).
  (`src/AI/GeminiClient.php`, `src/AI/OpenRouterClient.php`, `src/Moderation/MediaModerator.php`,
  `src/Jobs/ModerateMessageJob.php`, `.env.example`, `tests/ModerationBotTest.php`)
- **Animatsion stikerlar (TGS/WEBM) qo'llab-quvvatlashi (2.0 Phase 1, 4-band)**: avval har
  qanday animatsion/video-stiker (TGS yoki WEBM) mutlaqo tekshirilmasdan darhol
  `unscannable/animated_sticker` deb belgilanardi. Endi: **WEBM video-stikerlar** mavjud video
  kadr-ajratish (FFmpeg) infratuzilmasini qayta ishlatib, oddiy video bilan bir xil tarzda
  tekshiriladi (`MediaModerator::inspectSticker()` `.webm` kengaytmasini aniqlab
  `inspectVideo()`ga yo'naltiradi, natija manbasi `ai_vision_video_sticker`). **TGS (Lottie)**
  formatini FFmpeg dekodlay olmaydi — shu sababli `ModerateMessageJob` darajasida Telegram
  taqdim etadigan statik muqova (`sticker.thumbnail`) orqali tekshiriladi (video-muqova
  zaxira yo'li bilan bir xil mexanizm — `inspectVideoPreview()`); muqova mavjud bo'lmasa,
  xavfsiz `unscannable/lottie_sticker_no_preview` bilan yakunlanadi (bloklamaydi). WEBM
  video-stiker uchun ham FFmpeg mavjud bo'lmagan holatda xuddi shu muqova-zaxira yo'liga
  tushadi (avvalgi video/animatsiya bilan bir xil ffmpeg-yo'q fallback mantig'i kengaytirildi).
  Yangi sozlama qo'shilmadi — mavjud `media_filter` bilan boshqariladi. 2 ta yangi test:
  `testMediaModeratorRoutesWebmVideoStickerThroughFrameExtraction` (FFmpeg mavjud bo'lmasa
  avtomatik `markTestSkipped`),
  `testMediaModeratorTgsStickerReturnsUnscannableWithoutCrashing` (jami endi **64/64 test
  o'tadi**).
  (`src/Moderation/MediaModerator.php`, `src/Jobs/ModerateMessageJob.php`,
  `tests/ModerationBotTest.php`)
- **Bot-darajasidagi "moderator" roli (2.0 Phase 2, 1-band)**: guruh administratori endi
  oddiy a'zoga cheklangan huquqli "ishonchli a'zo" (moderator) maqomini bera oladi — Telegram'ning
  o'z admin/creator statusidan mutlaqo mustaqil, botning ICHKI roli. Yangi `chat_members.bot_role`
  ustuni (`'none'`/`'moderator'`, standart `'none'`) — mavjud `chat_members.role` ustunidan
  ataylab alohida, chunki `role` `AdminAuthorizationService::isAdmin()` sinxronizatsiyasi orqali
  Telegram'dan doimiy qayta yoziladi, `bot_role`ga esa hech qachon tegilmaydi. Tayinlash:
  guruhda a'zoning xabariga reply qilib <code>/addmod</code> (faqat to'liq adminlar chaqira
  oladi); bekor qilish — <code>/removemod</code>. Moderator FAQAT `/warn`, `/mute`, `/unmute`,
  `/warnings` buyruqlaridan foydalana oladi — `/ban`, `/unban`, `/resetwarns`, `/addmod`,
  `/removemod`, `/settings` va boshqa barcha admin buyruqlari unga yopiq (ruxsatsiz urinish
  `⛔ Bu buyruq faqat guruh administratorlari uchun.` xabari bilan rad etiladi). Yangi DM
  buyrug'i `/modlist [guruh_id]` — guruhning joriy moderatorlari ro'yxatini ko'rsatadi (mavjud
  `/wordlist` bilan bir xil "guruh tanlash" mexanizmi orqali). `/addmod` allaqachon to'liq admin
  bo'lgan foydalanuvchiga qo'llanilsa, hech narsa o'zgartirmaydi (`already_admin`). Yangi
  migratsiya: `database/migrations/006_moderator_role.sql` (SQLite test sxemasi ham yangilandi,
  versiya `sqlite_schema_v6`ga oshirildi). 3 ta yangi test:
  `testAddmodGrantsWarnMuteButBlocksBanAndRoleCommands`,
  `testRemovemodRevokesModeratorPrivileges`, `testAddmodOnExistingAdminIsNoop` (jami endi
  **67/67 test o'tadi**).
  (`src/Policy/AdminAuthorizationService.php`, `src/Http/UpdateRouter.php`,
  `database/migrations/006_moderator_role.sql`, `database/migrations/sqlite_schema.sql`,
  `bin/migrate.php`, `tests/ModerationBotTest.php`)
- **Yengil salomatlik tekshiruvi / health-check (2.0 Phase 2, 2-band)**: yangi
  `src/Core/HealthCheck.php` — DB ulanishi, navbat (`queue_jobs`) holati va worker/cron
  "jonlik" belgisini tekshiradi. `bin/doctor.php`dan ATAYLAB FARQLI: doctor.php og'ir
  (ko'plab tashqi Telegram/AI API so'rovlari + har safar bot egasiga test xabari) va faqat
  qo'lda ishga tushirishga mos; `HealthCheck` esa hech qanday tashqi tarmoq so'rovi
  yubormaydi (faqat mahalliy DB/fayl holati), shuning uchun tez-tez chaqirilishi xavfsiz.
  Ikkita yangi kirish nuqtasi: **`public/healthz.php`** — autentifikatsiyasiz JSON HTTP
  endpoint (uptime-monitoring xizmatlari uchun; sog'lom bo'lsa HTTP 200, muammo bo'lsa 503,
  hech qanday maxfiy ma'lumot qaytarmaydi); **`bin/healthcheck.php`** — cron orqali (tavsiya:
  har 5 daqiqada) ishga tushiriladi, muammo YANGI paydo bo'lganda yoki uzoq davom etsa
  (standart har 30 daqiqada bir eslatma, `HEALTHCHECK_REALERT_SEC` bilan sozlanadi) va
  tiklanganda `TELEGRAM_OWNER_IDS`ga Telegram xabari yuboradi — spam qilmaydi (holat
  `storage/health/alert_state.json`da saqlanadi). "Jonlik" belgisi (`storage/health/
  worker_heartbeat.txt`) endi ham `bin/worker.php` (har tsiklda), ham `bin/cron.php` (har
  chaqiriqda) tomonidan yoziladi — qaysi rejim ishlatilishidan qat'i nazar ishlaydi; fayl
  umuman yo'qligi xato hisoblanmaydi (masalan, hali yangi versiya deploy qilinmagan bo'lishi
  mumkin), faqat MAVJUD BO'LIB eskirgan bo'lsa muammo deb belgilanadi. 3 ta yangi test:
  `testHealthCheckHeartbeatMissingIsTreatedAsHealthy`, `testHealthCheckHeartbeatFreshVsStale`,
  `testHealthCheckDetectsStuckQueueJob` (jami endi **70/70 test o'tadi**).
  (`src/Core/HealthCheck.php`, `public/healthz.php`, `bin/healthcheck.php`, `bin/worker.php`,
  `bin/cron.php`, `.env.example`, `README.md`, `tests/ModerationBotTest.php`)
- **Worker'ni gorizontal masshtablash + log rotatsiyasi (2.0 Phase 2, 3-band)**:
  - *Gorizontal scaling*: `bin/worker.php`ning bir nechta nusxasini parallel ishga
    tushirish endi hujjatlashtirilgan va health-check darajasida qo'llab-quvvatlanadi.
    Har bir worker `WORKER_ID` muhit o'zgaruvchisi orqali (masalan systemd shablon
    xizmati `block-bot-worker@1`, `block-bot-worker@2`, `%i` shu qiymatga aylanadi)
    o'zining alohida `storage/health/worker_heartbeat_<id>.txt` fayliga yozadi —
    shunda bitta worker qulab tushsa, qolganlari ishlab turgani buni yashirib
    qo'ymaydi. `HealthCheck::runChecks()`'ning `worker_heartbeat` tekshiruvi endi
    barcha mavjud "jonlik" fayllarini yig'ib chiqadi: umumiy holat "sog'lom" (kamida
    bitta worker jonli bo'lsa), lekin har bir workerning holati `workers` ro'yxatida
    alohida ko'rinadi (orqaga moslik: `WORKER_ID` belgilanmagan standart bitta-workerli
    o'rnatishda fayl nomi va xatti-harakat aynan avvalgidek qoladi). **Muhim shart:
    faqat MySQL bilan** — `QueueService::reserve()`'ning `SELECT ... FOR UPDATE` qulfi
    SQLite'da mavjud emas, shuning uchun SQLite'da bir nechta worker "database is
    locked" xatolariga olib kelishi mumkin (README_VPS.md 3.1-bandida ogohlantirilgan).
  - *Log rotatsiyasi*: `App\Core\Logger` endi har bir yozuvdan oldin fayl hajmini
    tekshiradi — standart 10 MB (`LOG_MAX_SIZE_BYTES`)dan oshsa, joriy fayl `.1.gz`
    (zlib mavjud bo'lsa siqilgan holda, `LOG_COMPRESS_BACKUPS`) deb qayta nomlanadi,
    eskilari bittaga siljiydi, eng eskisi (standart 5 tadan oshgani, `LOG_MAX_BACKUPS`)
    butunlay o'chiriladi. Bu tizim `logrotate`'iga kirish bo'lmagan shared-hosting
    o'rnatishlarida ham ishlaydi (hech qanday qo'shimcha sozlash shart emas);
    `LOG_MAX_SIZE_BYTES=0` bilan butunlay o'chirib, o'rniga tizim logrotate ishlatish
    ham mumkin. Parallel jarayonlar bir vaqtda rotatsiya qilib yubormasligi uchun
    qisqa muddatli fayl qulfi (`flock`) ishlatiladi. **Diqqat**: bu faqat `Logger`
    orqali yoziladigan kanal fayllarini (`ai.log`, `telegram.log`, `moderation.log`
    va h.k.) qamrab oladi — systemd'ning `StandardOutput=append:.../worker.log`
    orqali yozadigan konsol chiqishi bundan mustaqil, shuning uchun README_VPS.md
    5.1-bandida buning uchun alohida tizim `logrotate` namunasi ham berilgan.
  - Yangi test: `testHealthCheckAggregatesMultipleWorkerHeartbeats`,
    `testLoggerRotatesLogFileWhenSizeExceedsLimit`, `testLoggerEnforcesMaxBackupCount`
    (jami endi **73/73 test o'tadi**, 4 marta ketma-ket barqaror tasdiqlandi).
  (`src/Core/HealthCheck.php`, `src/Core/Logger.php`, `bin/worker.php`, `.env.example`,
  `.gitignore`, `README.md`, `README_VPS.md`, `tests/ModerationBotTest.php`)

### O'zgartirildi
- Guruh chatlarida bot buyruqlarga (`/menu`, `/settings` va h.k.) endi javob bermaydi — buyruqlar
  faqat botning shaxsiy (DM) chatida ishlaydi. Guruhdagi avtomatik moderatsiya ogohlantirish/jazo
  xabarlari (`ModerateMessageJob` / `PunishmentService`) bunga ta'sirlanmaydi.
  (`src/Http/UpdateRouter.php`)

### Tuzatildi (yuqoridagi o'zgarishning nojo'ya ta'siri)
- **Muhim**: guruh buyruqlarini o'chirish birinchi urinishda `/warn`, `/mute`, `/ban`, `/unmute`,
  `/unban`, `/resetwarns`, `/warnings` kabi reply-asosidagi to'g'ridan-to'g'ri moderatsiya
  buyruqlarini ham, `/blockword`, `/allowword`, `/unblockword`, `/wordlist` kabi so'z-qoidasi
  buyruqlarini ham butunlay ishlamay qo'ygan edi (chunki ular guruhdagi handleCommand() orqali
  ishlagan, endi esa hech qanday DM muqobili yo'q edi). Tuzatildi:
  - `/warn /mute /ban /unmute /unban /resetwarns /warnings` — reply-kontekstiga bog'liqligi
    sababli **guruhda qoldirildi** (foydalanuvchi tanlovi bilan; boshqa hech qanday buyruq guruhda
    javob bermaydi).
  - `/blockword`, `/allowword`, `/unblockword`, `/wordlist` — endi shaxsiy chatdan (DM) ishlaydi;
    admin bitta guruhni boshqarsa avtomatik nishonga olinadi, bir nechta bo'lsa buyruqni
    `guruh_id so'z` formatida yuborish kerak (masalan: `/blockword -100123456789 so'z`).
  - `/status`, `/audit_pause`, `/audit_resume`, `/audit_cancel` — DM orqali `/stats`, `/audit_status`
    kabi mavjud "guruh tanlash" mexanizmiga qo'shildi.
  - `handleCommand()` ichidagi endi mutlaqo ishlamaydigan (`/start`, `/help`, `/settings`, `/status`,
    `/stats`, `/ai_usage`, `/scan_members`, `/audit*`, `/blockword`-oilasi, `/wordlist`) o'lik kod
    olib tashlandi — bu buyruqlarning barchasi endi faqat `handlePrivateChat()` orqali ishlaydi.
  - `/help` matni (DM'dagi `/help`) guruh/DM buyruqlari bo'linishini aniq ko'rsatadigan holga
    yangilandi.
  - Yangi test: `testDirectModerationCommandsStillWorkInGroupButOthersDont` — guruhda `/mute`
    ishlashini va `/menu` kabi boshqa buyruqlar javobsiz qolishini tasdiqlaydi.
  (`src/Http/UpdateRouter.php`, `tests/ModerationBotTest.php`)

### Rejalashtirilgan (keyingi bosqichlar)
To'liq reja: 2.0 yo'l xaritasi artifaktiga qarang. Qisqacha:
- **1-bosqich**: ~~anti-flood (xabar tezligi cheklovi)~~ ✅ bajarildi, ~~yangi a'zolar uchun
  CAPTCHA/tasdiqlash~~ ✅ bajarildi, ~~ovozli/audio xabarlar moderatsiyasi~~ ✅ bajarildi,
  ~~animatsion stikerlar (`.tgs`/`.webm`) qo'llab-quvvatlashi~~ ✅ bajarildi (yuqoriga qarang).
  **1-bosqich to'liq yakunlandi.**
- **2-bosqich**: ~~bot-darajasidagi "moderator" roli~~ ✅ bajarildi, ~~health-check/
  observability~~ ✅ bajarildi, ~~worker'ni gorizontal masshtablash bo'yicha hujjat, log
  rotatsiyasi~~ ✅ bajarildi (yuqoriga qarang). **2-bosqich to'liq yakunlandi.**
- **3-bosqich**: ~~i18n (ko'p tillilik)~~ ✅ bajarildi — tor qamrovda: guruhga
  ko'rinadigan CAPTCHA/jazo xabarlari (yuqoriga qarang), ~~Telegram Stars orqali
  monetizatsiya~~ ✅ bajarildi (yuqoriga qarang), ~~Web Dashboard / Mini App~~ ✅
  bajarildi (yuqoriga qarang). **3-bosqich to'liq yakunlandi.**
- **4-bosqich (ixtiyoriy)**: ~~forum-topics qo'llab-quvvatlashi~~ ✅ bajarildi,
  ~~sozlamalarni klonlash/eksport-import~~ ✅ bajarildi, ~~rejalashtirilgan
  xabar yuborish (broadcast)~~ ✅ bajarildi (yuqoriga qarang), PHPUnit qatlami
  (tanlovga kiritilmagan, kelajakda ko'rib chiqilishi mumkin).

## [1.0.0] — Ishlab chiqarishga joylashtirildi

- Birinchi to'liq ishlaydigan versiya: Telegram webhook + fon worker (VPS) yoki cron (shared
  hosting) orqali AI (Gemini/OpenRouter) yordamida guruh moderatsiyasi, ogohlantirish/mute/ban
  jazolari, shikoyat (appeal) tizimi, admin panel buyruqlari, tarixiy auditni import qilish va
  boshqa funksiyalar.
