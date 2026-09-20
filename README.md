# 🛡 Block-BOT: Telegram Guruh Moderatsiyasi va AI Audit Tizimi

**Block-BOT** — PHP 8.1 / 8.3+ va MySQL negizida qurilgan, Google Gemini yoki OpenRouter AI imkoniyatlari bilan boyitilgan professional Telegram guruh moderatsiyasi va tarixiy audit boti. Barcha xabarlar, sozlamalar, ogohlantirishlar va hisobotlar to'liq adabiy **o'zbek tilida**.

---

## 🌟 Asosiy Imkoniyatlar

1. **Servis Xabarlarini Tozalash:**
   - Guruhdagi "kirdi-chiqdi" (`new_chat_members`, `left_chat_member`) xabarlarini avtomatik va tezkor o'chirish.
   - Bir vaqtda bir nechta a'zo kirganda barchasini qamrab olish.
   - Yangi a'zolarni fon rejimida profil tekshiruviga yuborish (servis xabarlariga AI chaqirilmaydi, tejamkor).

2. **Ko'p Bosqichli Matn Moderatsiyasi:**
   - O'zbek lotin, o'zbek kirill, rus va ingliz tillari.
   - Leetspeak (`s0ka`), zero-width belgilar, harflar orasidagi nuqta/bo'shliqlar (`s.u.k.a`), aralash lotin-kirill homoglyphlarini to'g'ri normalizatsiya qilish.
   - **Begunoh so'zlar himoyasi:** Substring xatolari tufayli "qoshiq", "kuchukcha", "siklamen", "shartnoma" kabi so'zlar aslo bloklanmaydi.
   - Deterministik qat'iy so'kinishlar mahalliy filtrda AI'siz, bir zumda jazolanadi. Kontekstli va noaniq holatlar tanlangan AI provider orqali tekshiriladi.
   - **Jargon/lahja so'zlarini qo'shish:** o'rnatilgan ro'yxatda bo'lmagan mahalliy so'kinish yoki
     istisno so'zni admin `/blockword so'z`, `/allowword so'z`, `/unblockword so'z`, `/wordlist`
     buyruqlari bilan darhol (AI'siz, bepul) qo'sha oladi — guruhga xos.

3. **Havolalar va SSRF Himoyasi:**
   - Oddiy va yashirin havolalarni (entities) ajratish.
   - Guruh va global miqyosdagi oq/qora domenlar ro'yxati.
   - SSRF himoyasi: mahalliy (127.0.0.1), xususiy (192.168.x.x, 10.x.x.x) va cloud metadata (169.254.169.254) manzillarini qat'iy bloklash.

4. **Media va Video Kadr Tahlili:**
   - Rasm, video, GIF/animatsiya, stikerlar va hujjatlar.
   - Rasmlarni AI Vision modeliga yuborishdan oldin tokenlarni tejash maqsadida xavfsiz o'lchamga (1024px) kichraytirish.
   - FFmpeg mavjud bo'lsa, videolardan 1s, 3s, 5s vaqt nuqtalaridan kadrlar olinib tekshiriladi.
   - FFmpeg mavjud bo'lmagan shared hostingda video/GIF matni va Telegram yaratgan preview muqovasi AI Vision orqali tekshiriladi.
   - **Albomlar himoyasi:** Bir nechta rasmdan iborat bitta albom (`media_group_id`) uchun takroriy jazo berilmaydi.
   - Tekshirib bo'lmaydigan media (`unscannable`) uchun sozlanadigan siyosat: guruhda qoldirish va adminga bildirish, yoki o'chirish va adminga bildirish.
   - **Google SafeSearch zaxira tekshiruvi (ixtiyoriy):** Gemini/OpenRouter kabi generativ
     modellar o'ta ochiq kontentni tahlil qilishdan yashirin bosh tortib "review" yoki
     tushunarsiz javob qaytarishi mumkin. `GOOGLE_VISION_API_KEY` sozlangan bo'lsa, aynan shu
     holatda Google Cloud Vision "SafeSearch Detection" (bosh tortmaydigan, maxsus rasm
     xavfsizlik API'si) zaxira sifatida ishga tushadi.

5. **A'zolar Profil Tahlili:**
   - Yangi kirgan va xabar yozgan a'zolarning ism, familiya, username va ko'rinadigan profil rasmini tekshirish.
   - Kesh tizimi: har bir xabarda Telegram API yoki AI qayta chaqirilmaydi (7 kunlik kesh).
   - Profil rasmi yo'qligi qoidabuzarlik hisoblanmaydi.
   - Yangi a'zo `new_chat_members` xizmat xabari kelmasa ham, `chat_member` yangilanishi orqali fon tekshiruviga tushadi.

6. **18+ va Bot Akkauntlar Nazorati:**
   - Profil ismi yoki rasmida 18+ kontent aniqlansa — akkaunt vaqtincha cheklanadi va administratorga bir tugmali **"🚫 Ban"** yuboriladi (sozlama: `adult_account_action` — `mute_notify` / `ban` / `notify`).
   - Guruhga ruxsatsiz qo'shilgan bot akkauntlar aniqlanadi; adminlar qo'shgan botlar istisno (`bot_filter` sozlamasi).
   - `/scan_members` yoki `/settings` menyusidagi **"👥 A'zolarni tekshirish"** tugmasi bilan mavjud a'zolarni ommaviy skanerlash (bot ko'rgan yoki eksportda bo'lgan a'zolar; to'liq ro'yxat uchun MTProto).

7. **Bosqichma-bosqich Jazo Siyosati:**
   - 1-qoidabuzarlik: xabarni o'chirish + ogohlantirish (warn).
   - 2-qoidabuzarlik: xabarni o'chirish + 1 soat mute.
   - 3-qoidabuzarlik: xabarni o'chirish + 24 soat mute.
   - 4-qoidabuzarlik: guruhdan chetlatish (ban).
   - Ochiq pornografiya: xabarni darhol o'chirish + darhol ban (sozlanadigan).
   - Ogohlantirishlar muddati standart 30 kun.
   - Oddiy xabar o'chirish va birinchi ogohlantirish guruhda jim bajariladi. Faqat mute/ban bo'lganda sabab va shikoyat tugmasi 10 daqiqaga ko'rsatiladi.
   - Shikoyat AI orqali qayta tekshiriladi, ammo cheklovni olish yoki saqlash bo'yicha yakuniy qarorni administrator tasdiqlaydi.
   - Guruh egasi, adminlar va sender_chat (anonim adminlar) xavfsiz istisno qilingan.
   - **Tezkor admin tugmalari:** AI xatosi yoki noaniqlik tufayli avtomatik chora ko'rilmagan har
     bir holatda (masalan, AI Vision bo'sh/noto'g'ri javob qaytarganda) admin logga
     **"🗑 Xabarni o'chirish"** va **"🚫 Ban qilish"** tugmalari qo'shiladi — xabar allaqachon
     o'chirilgan yoki foydalanuvchi allaqachon ban bo'lgan hollarda tegishli tugma yashiriladi.

8. **Tarixiy Audit, Hisobot va Tasdiqli Tozalash:**
   - Telegram Desktop JSON/ZIP eksportini xavfsiz hajm limiti bilan import qilish (Zip-bomb va Path traversal himoyasi).
   - MTProto CLI moduli orqali guruh tarixini bosqichma-bosqich (checkpoint) o'qish.
   - Tarixiy audit aslo avtomatik ommaviy jazo bermaydi — avval dalillarga asoslangan hisobot yaratadi.
   - Hisobotdan so'ng administratorga **tasdiqli tozalash menyusi** yuboriladi: har bir amal (belgilangan xabarlarni o'chirish, 18+ akkauntlarni cheklash, bot akkauntlarni cheklash) **alohida tugma bilan tasdiqlanadi**. Xabarlar Bot API orqali o'chiriladi (bot superguruhda "Delete messages" huquqiga ega bo'lishi shart), akkauntlar avval mute qilinadi, so'ng bitta tugma bilan hammasini ban qilish mumkin.
   - Telegram uchun qisqa xulosa, batafsil tahlil uchun HTML va Formula Injectiondan himoyalangan CSV eksport.

9. **AI Budjeti va Tejamkorlik:**
   - Kunlik (`AI_DAILY_BUDGET_USD`) va oylik (`AI_MONTHLY_BUDGET_USD`) qat'iy chegaralar.
   - Parallel so'rovlar limitdan oshib ketmasligi uchun atomik budjet rezervatsiyasi.
   - Budjet chegarasiga yetganda AI navbati pauza qilinadi, mahalliy filtrlar uzluksiz ishlashda davom etadi.
   - Google Gemini ba'zi mamlakatlar IP manzillarini bloklaydi ("User location is not supported").
     Shu holatda `GEMINI_BASE_URL`/`OPENROUTER_BASE_URL` ni `deploy/cloudflare-worker/telegram-proxy.js`
     orqali proksilash mumkin (`/gemini`, `/openrouter` yo'llari) — `php bin/doctor.php` buni aniqlaydi.

---

## 🏗 Arxitektura va Modullar Tuzilishi

Loyiha PSR-4 standartiga asoslangan:

```
Block-BOT/
├── bin/
│   ├── diagnose.php          # Muhit, DB, bot huquqlari va AI diagnostikasi
│   ├── doctor.php            # Bir buyruqli nosozlik tashxisi (webhook, DB, AI ulanish, cron; --fix bilan avto-tuzatish)
│   ├── migrate.php           # Ma'lumotlar bazasi migratsiyasi
│   ├── set_webhook.php       # Telegram Webhook o'rnatish/tekshirish
│   ├── worker.php            # VPS uchun doimiy navbat xizmatchisi (daemon)
│   ├── cron.php              # Shared Hosting uchun davriy cron skripti
│   ├── healthcheck.php       # Yengil salomatlik tekshiruvi (cron uchun; muammo bo'lsa bot egasiga xabar)
│   ├── run_tests.php         # Avtomatlashtirilgan sinov holatlarini tekshiruvchi test runner
│   └── mtproto_login.php     # MTProto CLI orqali xavfsiz kirish skripti
├── database/
│   └── migrations/
│       ├── 001_initial_schema.sql       # MySQL to'liq sxemasi
│       ├── 002_moderation_appeals.sql   # Shikoyat jadvali
│       ├── 003_history_cleanup.sql      # Tarixiy tozalash / 18+ / bot sozlamalari
│       └── sqlite_schema.sql            # Testlar uchun SQLite sxemasi
├── public/
│   ├── webhook.php           # Telegram Webhook HTTPS kirish nuqtasi
│   ├── healthz.php           # Autentifikatsiyasiz JSON salomatlik endpointi (uptime-monitoring uchun)
│   └── index.html            # Xavfsiz landing sahifasi
├── src/
│   ├── AI/                   # Google Gemini, OpenRouter va budjet nazorati
│   ├── Audit/                # JSON import, MTProto va hisobotlar
│   ├── Core/                 # Config, Database, Logger, Queue, TelegramClient
│   ├── Http/                 # UpdateRouter (Webhook dispatch)
│   ├── Jobs/                 # Navbat vazifalari: ModerateMessage, ScanProfile, ScanMembers,
│   │                         #   ImportAudit, AuditRunner, AuditCleanup, DeleteMessage
│   ├── Moderation/           # TextNormalizer, TextModerator, MediaModerator, ProfileModerator
│   └── Policy/               # SettingsService, Decision, Punishment, AdminAuth
└── tests/                    # Avtomatlashtirilgan qat'iy testlar
```

---

## 🚀 O'rnatish va Ishga Tushirish

### 1. Talablar
- PHP >= 8.1 (Tavsiya: PHP 8.3+)
- Kengaytmalari: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `json`, `gd` (ixtiyoriy: `zip`, `ffi`)
- MySQL 5.7+ / 8.0+ yoki MariaDB 10.3+
- Composer
- HTTPS domen (Telegram Webhook uchun SSL sertifikat shart)

### 2. O'rnatish qadamlari

```bash
# 1. Loyihani yuklab oling va papkaga o'ting
cd Block-BOT

# 2. Bog'liqliklarni o'rnating
composer install --no-dev --optimize-autoloader

# 3. Konfiguratsiya faylini tayyorlang
cp .env.example .env

# .env faylini o'zingizning DB va Telegram ma'lumotlaringiz bilan to'ldiring:
# DB_HOST=127.0.0.1
# DB_NAME=block_bot
# DB_USER=root
# DB_PASS=
# TELEGRAM_BOT_TOKEN=123456789:ABC...
# TELEGRAM_OWNER_IDS=123456789  # ixtiyoriy platforma superadmini
# TELEGRAM_WEBHOOK_SECRET=maxfiy_kalit_kamida_32_belgi
# TELEGRAM_WEBHOOK_URL=https://sizning-domeningiz.uz/webhook.php
# AI_PROVIDER=gemini
# GEMINI_API_KEY=AQ... yoki AIza...
# GEMINI_TEXT_MODEL=gemini-2.5-flash
# GEMINI_VISION_MODEL=gemini-2.5-flash
# AI_DEFAULT_MODE=comprehensive

# 4. Ma'lumotlar bazasi migratsiyasini ishga tushiring
php bin/migrate.php

# 5. Tizim diagnostikasini tekshiring
php bin/diagnose.php

# 6. Avtomatlashtirilgan testlarni tekshiring
php bin/run_tests.php

# 7. Telegram Webhook va bot buyruqlar menyusini sozlang
php bin/set_webhook.php set

# (Nosozlik bo'lsa: to'liq tashxis va avto-tuzatish)
php bin/doctor.php --fix
```

> `set_webhook.php set` webhook bilan birga botning **buyruqlar menyusini** ham o'rnatadi
> (Telegram'dagi ☰ tugmasi). Alohida yangilash: `php bin/set_webhook.php commands`.
> Shaxsiy chatда bot **inline bosh menyu** beradi: 📋 Mening guruhlarim · 🤖 AI holati · ❓ Yordam.

Bot universal multi-tenant rejimda ishlaydi: istalgan foydalanuvchi uni o'z guruhiga qo'shib, admin huquqini bergach, bir martalik boshqaruv tugmasi orqali shaxsiy chatini ulaydi. Har bir Telegram guruh administratori faqat o'zi admin bo'lgan guruhlarni ko'radi va shu guruhlar bo'yicha hisobot oladi. `TELEGRAM_OWNER_IDS` faqat barcha guruhlarni ko'ra oladigan ixtiyoriy platforma superadminlari uchun; oddiy mijozlarni bu ro'yxatga qo'shish shart emas.

`diagnose.php` 0 exit-kod va “TIZIM ISHGA TUSHISHGA TO'LIQ TAYYOR” natijasini bermaguncha production worker/webhook'ni yoqmang. Telegram tokeni, HTTPS webhook URL, kamida 32 belgili webhook secret va tanlangan AI provider kaliti tekshiruvdan o'tishi kerak. FFmpeg bo'lmasa video/GIF faqat Telegram muqovasi orqali tekshiriladi.

---

## ⚙️ Ishga Tushirish Rejimlari

Loyiha ikki xil rejimda ishlash uchun moslashgan. **Webhook faqat deterministik (mahalliy)
tekshiruvni sinxron bajaradi** — aniq so'kinish, qora ro'yxat, qimor/APK/reklama qoidalari va
havolalar zumda o'chiriladi. AI matn tahlili, media tahlili, profil skani va tarixiy tozalash
esa navbatga (`queue_jobs`) qo'yiladi, shuning uchun **AI moderatsiyasi ishlashi uchun cron yoki
worker ishlab turishi shart**.

1. **Shared Hosting (cPanel / DirectAdmin):**
   - Webhook kelgan xabarni tezkor qabul qilib MySQL navbatiga (`queue_jobs`) yozadi.
   - Har daqiqada Cron orqali `php bin/cron.php` ishga tushadi (AI topilmasi maksimum ~1 daqiqa kechikadi).
   - *Batafsil yo'riqnoma: [README_SHARED_HOSTING.md](README_SHARED_HOSTING.md)*

2. **VPS Server (Ubuntu / Debian / CentOS):**
   - Doimiy xizmatchi: `php bin/worker.php` systemd orqali fonda uzluksiz ishlaydi.
   - Xabarlar navbatdan millisekundlarda olinadi va zudlik bilan filtrlanadi.
   - FFmpeg va MTProto to'liq quvvatda ishlaydi.
   - *Batafsil yo'riqnoma: [README_VPS.md](README_VPS.md)*

---

## 🩺 Salomatlik monitoringi (health-check)

`bin/doctor.php` og'ir — ko'plab tashqi API so'rovlari yuboradi va har safar bot egasiga
test xabari yozadi, shuning uchun u faqat **qo'lda**, kamdan-kam to'liq diagnostika uchun mos.
Muntazam, avtomatik kuzatuv uchun ikkita yengil vosita bor (ikkalasi ham hech qanday tashqi
tarmoq so'roviga muhtoj emas — faqat DB va fayl holatini tekshiradi):

- **`public/healthz.php`** — autentifikatsiyasiz JSON endpoint. Uptime-monitoring xizmati
  (UptimeRobot, DigitalOcean Monitoring va h.k.) shu manzilga muntazam GET so'rov yuborsin:
  sog'lom bo'lsa HTTP 200, muammo bo'lsa HTTP 503 qaytaradi.
- **`bin/healthcheck.php`** — cron orqali (tavsiya: har 5 daqiqada) ishga tushiriladi.
  Muammo YANGI paydo bo'lganda yoki uzoq davom etsa (standart: har 30 daqiqada bir eslatma,
  `HEALTHCHECK_REALERT_SEC` bilan sozlanadi), shuningdek tiklanganda — `TELEGRAM_OWNER_IDS`ga
  Telegram orqali xabar yuboradi (spam qilmaydi):
  ```
  */5 * * * * /usr/bin/php /path/to/bin/healthcheck.php --quiet >> storage/logs/healthcheck.log 2>&1
  ```

Ikkalasi ham `bin/worker.php` yoki `bin/cron.php` muntazam yozadigan "jonlik" belgisidan
(`storage/health/worker_heartbeat.txt`) va navbat (`queue_jobs`) holatidan foydalanadi —
belgi eskirsa yoki navbatda uzoq kutgan vazifa bo'lsa, worker/cron to'xtagani aniqlanadi.

---

## ⚙️ Gorizontal scaling va log rotatsiyasi

- **Bir nechta worker parallel ishlatish**: yuklama katta bo'lib navbat (`queue_jobs`)
  doimiy to'lib qolsa, `bin/worker.php`ning bir nechta nusxasini `WORKER_ID` muhit
  o'zgaruvchisi bilan (masalan systemd shablon xizmati `block-bot-worker@1`,
  `block-bot-worker@2`) parallel ishga tushirish mumkin — **faqat MySQL bilan**
  (`QueueService::reserve()`ning `SELECT ... FOR UPDATE` qulfi SQLite'da mavjud emas).
  Har bir worker o'zining alohida health-check "jonlik" faylига yozadi, shuning uchun
  qaysi biri to'xtagani `worker_heartbeat.workers` ro'yxatidan ko'rinadi.
  *Batafsil: [README_VPS.md, 3.1-band](README_VPS.md#31-gorizontal-scaling--bir-nechta-worker-parallel-ishlatish).*
- **Log rotatsiyasi**: `storage/logs/*.log` fayllari (Logger orqali yoziladigan
  `ai.log`, `telegram.log`, `moderation.log` va h.k.) 10 MB'dan (standart, `LOG_MAX_SIZE_BYTES`
  bilan sozlanadi) oshsa avtomatik ravishda `.1.gz`, `.2.gz`, ... deb siqilib rotatsiya
  qilinadi va eng eskisi (standart 5 tadan oshgani) o'chiriladi — hech qanday qo'shimcha
  sozlashsiz, shared-hostingda ham ishlaydi.
  *Batafsil: [README_VPS.md, 5.1-band](README_VPS.md#51-log-rotatsiyasi-avtomatik-phase-2).*

---

## 🌐 Ko'p tillilik (i18n)

Guruh a'zolariga BEVOSITA ko'rinadigan xabarlar — yangi a'zo CAPTCHA'si va jazo
(ogohlantirish/mute/ban) bildirishnomasi (foydalanuvchiga shaxsiy xabar sifatida) —
endi guruh tanlagan tilda yuboriladi: o'zbekcha (standart), ruscha yoki inglizcha.
Admin (guruh egasi) DM'da `/til uz`, `/til ru` yoki `/til en` yuborib o'zgartiradi,
argumentsiz `/til` joriy tilni ko'rsatadi. Tarjimalar `lang/uz.php`, `lang/ru.php`,
`lang/en.php` fayllarida oddiy PHP massivi sifatida saqlanadi — yangi til qo'shish
uchun shunga o'xshash yangi `lang/{kod}.php` fayl yaratish va `App\Core\Translator::
SUPPORTED`ga kod qo'shish kifoya.

**Diqqat — tor qamrov**: bu bosqichda faqat yuqoridagi ikki oqim tarjima qilingan.
Admin panel (`/settings` va h.k.), boshqa barcha DM buyruqlari, `/help` matni va
ichki log xabarlari hozircha faqat o'zbek tilida qoladi.

---

## ⭐ Telegram Stars monetizatsiyasi (Premium tarif)

Bot ikki tarifda ishlaydi — har bir GURUH uchun alohida:

- **Bepul**: kuniga cheklangan AI so'rovlar (standart `FREE_TIER_DAILY_AI_REQUESTS=150`,
  matn/rasm/video/ovoz birgalikda hisoblanadi). Chegaradan oshgach guruh himoyasiz
  qolmaydi — mahalliy qoidalar (so'z bloklash, havola filtri, flood-guard, CAPTCHA
  va h.k.) baribir ishlashda davom etadi, faqat AI tahlil o'sha kun uchun to'xtaydi.
- **Premium**: AI tekshiruvlar kunlik chegarasiz. [Telegram Stars](https://telegram.org/blog/telegram-stars)
  — botning ichki valyutasi — orqali sotib olinadi, tashqi to'lov provayderi yoki
  bank hisobi shart emas. Standart narx: `PREMIUM_STARS_PRICE=200` Stars / `PREMIUM_DURATION_DAYS=30` kun.

Admin DM'da `/premium [guruh_id]` yuborib joriy tarif holatini (bepul: bugungi
AI so'rovlar soni; premium: tugash sanasi) va "⭐ Premium sotib olish" tugmasini
ko'radi. Tugma bosilganda Telegram o'zining ichki to'lov oynasini ochadi — to'lov
tasdiqlangach premium avtomatik faollashadi va admin DM'ga tasdiq xabari keladi.
Muddatidan oldin qayta sotib olinsa, qolgan kunlar yo'qolmaydi (yangi muddat
mavjud muddatga qo'shiladi). Har bir to'lov `telegram_payment_charge_id` bo'yicha
idempotent qayd etiladi — Telegram webhookni ehtimoliy qayta yuborsa ham, premium
ikki marta berilmaydi.

**Diqqat**: bu funksiya ishlashi uchun webhook `pre_checkout_query` update turini
ham qabul qilishi shart (quyidagi "Webhook Allowed Updates" bo'limiga qarang) —
`php bin/doctor.php` bu sozlama yetishmasa ogohlantiradi.

---

## 📊 Web Dashboard / Telegram Mini App

Admin uchun botning bosh menyusida (`/menu`) ochiladigan **Telegram Mini App**
(WebApp) — alohida login/parol yoki OAuth shart emas, Telegram o'zi foydalanuvchini
allaqachon tasdiqlagan (`initData`, HMAC-SHA256 bilan imzolangan, bot tokeni orqali
tekshiriladi — `App\Core\MiniAppAuth`).

- **Frontend**: `public/miniapp/index.html` — bitta fayl, hech qanday build-qadam
  yoki tashqi bog'liqlik yo'q (loyihaning umumiy "composer/npm shart emas" falsafasiga
  mos), faqat Telegram'ning rasmiy `telegram-web-app.js` skripti bilan ishlaydi.
  Telegram mavzu ranglariga (`tg-theme-*`) avtomatik moslashadi.
- **Backend**: `public/api.php` → `App\Http\MiniAppApiRouter` — `?action=...` orqali
  ishlaydigan yagona REST kirish nuqtasi (shared-hosting'da ham ishlashi uchun
  maxsus URL-rewrite qoidalari shart emas, xuddi `webhook.php`/`healthz.php` kabi).
  Mavjud xizmatlardan (`SettingsService`, `SubscriptionService`, `PunishmentService`,
  `AdminAuthorizationService`) foydalanadi — hech qanday biznes-mantiq takrorlanmagan.
- **Qamrov**: guruh tanlash (bir nechta guruh boshqarilsa), umumiy holat (tarif,
  bugungi AI ishlatish, faol ogohlantirish/shikoyat soni), sozlamalarni yoqish/
  o'chirish va tahrirlash, faol ogohlantirishlar ro'yxati + bekor qilish, kutilayotgan
  shikoyatlar ro'yxati + qabul qilish/rad etish (mute/unban avtomatik).
- **Xavfsizlik**: har bir guruhga oid amal, frontend'dan kelgan `chat_id`dan qat'i
  nazar, so'rovchi HAQIQATDA o'sha guruh admini ekanini serverda qayta tekshiradi
  (`AdminAuthorizationService::isAdmin()`) — Mini App orqali ham boshqa birovning
  guruhini boshqarib bo'lmaydi.

**Sozlash**: `MINIAPP_URL` ixtiyoriy — bo'sh qoldirilsa `TELEGRAM_WEBHOOK_URL`
domenidan avtomatik hosil qilinadi (`https://domen/miniapp/`), chunki `public/miniapp/`
ham shu domenda joylashadi. Telegram FAQAT `https://` manzilni qabul qiladi — mos
kelmasa (yoki webhook umuman sozlanmagan bo'lsa) bosh menyudagi "📊 Dashboard"
tugmasi shunchaki ko'rsatilmaydi (xatoga olib kelmaydi).

---

## 🧵 Forum-mavzular (topics) qo'llab-quvvatlashi

Telegram "Forum" rejimi yoqilgan superguruhlarda (nomlangan mavzular/topics)
botning javoblari endi foydalanuvchi qaysi mavzuda yozgan bo'lsa, aynan o'sha
mavzuga qaytariladi — standart "General"ga tushib ketmaydi. Bu quyidagilarga
tegishli: yangi a'zo uchun CAPTCHA xush kelibsiz xabari, `/warn`, `/mute`, `/ban`,
`/unmute`, `/unban`, `/resetwarns`, `/warnings`, `/addmod`, `/removemod`
buyruqlarining guruh ichidagi javoblari. Hech qanday qo'shimcha sozlash shart
emas — bot mavzu kontekstini avtomatik aniqlaydi (`is_topic_message` +
`message_thread_id`) va shunga mos javob beradi; oddiy guruh yoki "General"
mavzusida xatti-harakat avvalgidek qoladi.

---

## 📋➡️📋 Sozlamalarni klonlash / eksport-import

Bir nechta guruhni boshqaradigan admin uchun bitta guruhda sozlangan siyosatni
(filtrlar, ogohlantirish/mute chegaralari, anti-flood, CAPTCHA, til va h.k.)
boshqa guruhlarga qayta-qayta qo'lda sozlamasdan ko'chirish mumkin:

- **`/clonesettings manba_guruh_id maqsad_guruh_id`** — JSON'siz, to'g'ridan-
  to'g'ri nusxalash. Chaqiruvchi IKKALA guruhning ham (manba va maqsad)
  admini bo'lishi shart.
- **`/exportsettings [guruh_id]`** — joriy sozlamalarni tuzilgan JSON
  ko'rinishida qaytaradi (zaxira sifatida saqlash yoki boshqa admin bilan
  ulashish uchun).
- **`/importsettings guruh_id {JSON}`** — eksport qilingan (yoki qo'lda
  tuzilgan) JSON'ni tekshirib guruhga qo'llaydi. Har bir maydon turiga
  (mantiqiy 0/1, son diapazoni yoki qat'iy enum ro'yxati) qarab tasdiqlanadi
  — noma'lum, ruxsat etilmagan yoki noto'g'ri qiymatli maydonlar hech qachon
  xatoga olib kelmaydi, shunchaki jim o'tkazib yuboriladi va javobda
  ko'rsatiladi.

**Diqqat**: to'lov holati (`premium_expires_at`) va guruhga xos infratuzilma
sozlamalari (`log_chat_id`, AI model nomlari) ataylab klonlanmaydi/import
qilinmaydi — bular "sozlama" emas, balki guruhga individual holat.

---

## 📢 Ommaviy xabar yuborish (broadcast)

Bir nechta guruhni boshqaradigan admin **`/broadcast matn`** buyrug'i orqali
bitta e'lon yoki ogohlantirishni botning o'zi orqali BOSHQARGAN barcha
guruhlariga bir yo'la yuboradi. Har bir guruh uchun alohida navbat vazifasi
(`App\Jobs\BroadcastMessageJob`) yaratiladi — bitta guruhga yetkazib
bo'lmasa (masalan bot guruhdan chiqarilgan bo'lsa), bu qolgan guruhlarga
ta'sir qilmaydi. Telegram'ning umumiy tezlik chegarasidan saqlanish uchun
ko'p sonli guruhlarda yuborish avtomatik kichik bosqichlarga bo'linadi.
Xavfsizlik uchun matn ekranlanadi (xom HTML sifatida o'tkazilmaydi).

---

## 🤖 BotFather va Telegram Sozlamalari

### 1. Bot yaratish va huquqlar
1. [@BotFather](https://t.me/BotFather) ga kiring va `/newbot` buyrug'i bilan bot oching.
2. Tokenni oling va `.env` fayldagi `TELEGRAM_BOT_TOKEN` ga yozing.
3. **Privacy Mode:**
   - Bot guruhdagi barcha xabarlarni ko'rishi va moderatsiya qilishi uchun:
   - `/setprivacy` -> Botni tanlang -> **Disable** qiling.
4. **Group Admin Rights:**
   - Botni guruhga admin qilib qo'shing va quyidagi huquqlarni bering:
     - ✅ Delete messages (Xabarlarni o'chirish)
     - ✅ Ban users (Foydalanuvchilarni cheklash/bloklash)
     - ✅ Invite users via link (A'zolarni taklif qilish)

### 2. Webhook Allowed Updates
Bot quyidagi update turlarini qabul qiladi:
`message`, `edited_message`, `callback_query`, `chat_member`, `my_chat_member`,
`pre_checkout_query` (Telegram Stars to'lovlari uchun shart — bo'lmasa
premium sotib olish ishlamaydi).

---

## 📋 Admin Buyruqlari

Barcha buyruqlar faqat haqiqiy guruh adminlari yoki egasi tomonidan bajarilishi mumkin:

| Buyruq | Tavsif |
|---|---|
| `/settings` | Guruh moderatsiya sozlamalari menyusi (Inline tugmalar) |
| `/status` | Botning joriy holati va parametrlari |
| `/stats` | Guruh bo'yicha moderatsiya statistikasi |
| `/warn` | Foydalanuvchiga ogohlantirish berish (xabariga reply qilib) |
| `/warnings` | Foydalanuvchining faol ogohlantirishlarini ko'rish |
| `/resetwarns` | Foydalanuvchining barcha ogohlantirishlarini bekor qilish |
| `/mute [soat]` | Foydalanuvchini vaqtincha guruhda mute qilish |
| `/unmute` | Mute cheklovini zudlik bilan olib tashlash |
| `/ban` | Qoidabuzarni guruhdan butunlay chetlatish |
| `/unban` | Foydalanuvchini bandan chiqarish |
| `/scan_members` | Guruh a'zolarini 18+ profil va ruxsatsiz bot akkauntlarga tekshirish (natija adminga) |
| `/blockword so'z` | Jargon/lahjadagi so'zni mahalliy taqiqlash (AI'siz, darhol ishlaydi) |
| `/allowword so'z` | Begunoh so'zni istisno (oq ro'yxat) qilish |
| `/unblockword so'z` | Maxsus qoidani o'chirish |
| `/wordlist` | Guruhning maxsus so'z qoidalari ro'yxati |
| `/til [uz\|ru\|en]` | Guruh a'zolariga ko'rinadigan xabarlar (CAPTCHA, ogohlantirish/mute/ban) tilini ko'rish/o'zgartirish |
| `/premium [guruh_id]` | Tarif holatini (bepul/premium) ko'rish va Telegram Stars orqali sotib olish |
| `/exportsettings [guruh_id]` | Joriy sozlamalarni JSON ko'rinishida olish (zaxira yoki boshqa guruhga ko'chirish uchun) |
| `/importsettings guruh_id {JSON}` | Eksport qilingan (yoki qo'lda tuzilgan) JSON'ni guruhga qo'llash |
| `/clonesettings manba_id maqsad_id` | Bir guruh sozlamalarini boshqasiga to'g'ridan-to'g'ri nusxalash (ikkalasining ham admini bo'lish shart) |
| `/broadcast matn` | Boshqargan barcha guruhlaringizga botning o'zi orqali bitta e'lon/ogohlantirish yuborish |
| `/audit [json\|mtproto]` | JSON/ZIP yuklash auditini yoki MTProto auditini boshlash |
| `/audit_status` | Audit jarayoni va tekshirilgan xabarlar soni |
| `/audit_pause` | Audit jarayonini vaqtincha to'xtatish |
| `/audit_resume` | Auditni to'xtagan joyidan (checkpoint) davom ettirish |
| `/audit_cancel` | Audit sessiyasini bekor qilish |
| `/audit_report` | Audit natijalari bo'yicha qisqa xulosa va eksport |
| `/ai_usage` | AI xarajatlari, sarflangan tokenlar va budjet qoldig'i |

Cheklangan foydalanuvchi botning shaxsiy chatida `/appeal HARAKAT_ID` buyrug'i bilan ham shikoyat yuborishi mumkin. `HARAKAT_ID` mute/ban bildirishnomasida ko'rsatiladi.

> Telegram Bot API bot qo'shilishidan oldingi xabarlar tarixini bera olmaydi. `/audit mtproto` sozlangan MTProto sessiyasi orqali tarixni o'qiydi; aks holda `/audit json` Telegram Desktop eksportidagi `result.json` yoki ZIP faylni qabul qiladi. Tarixiy audit avtomatik ommaviy jazo bermaydi — avval admin hisobotini yaratadi, keyin hisobot ostidagi tugmalar orqali admin har bir tozalash amalini alohida tasdiqlaydi (belgilangan xabarlarni o'chirish, 18+/bot akkauntlarni cheklash). Xabarlarni o'chirish uchun bot superguruhda "Delete messages" adminlik huquqiga ega bo'lishi shart.

---

## 🔒 Xavfsizlik Choralar

- **Idempotency:** Bitta update yoki navbatdagi qayta urinish hech qachon takroriy jazo bermaydi.
- **SSRF Himoyasi:** Matndagi havolalar orqali serverning ichki tarmoqlari va cloud metadatalariga murojaat qilish bloklangan.
- **SQL Injection:** Barcha so'rovlar PDO tayyorlangan so'rovlar (prepared statements) orqali bajariladi.
- **XSS va CSV Injection Himoyasi:** HTML hisobotlarda barcha matnlar `htmlspecialchars` orqali tozalanadi; CSV fayllarda formulalar (`=`, `+`, `-`, `@`) prefikslanadi.
- **Maxfiy Ma'lumotlar Maskalanishi:** Bot tokeni, Gemini/OpenRouter API kaliti va sessiya fayllari hech qachon loglarga ochiq yozilmaydi.
- **MTProto Xavfsizligi:** MTProto autentifikatsiyasi faqat mahalliy server konsoli (CLI) orqali bajariladi. Sessiya web-root tashqarisida saqlanadi.

---

## 🧪 Avtomatlashtirilgan Testlar

Loyiha real vaqt moderatsiyasi, reklama/qimor/APK filtrlari, shikoyat oqimi, tarixiy tozalash
va a'zolar sweep'ini ham qamrab oluvchi test to'plamiga ega:

```bash
php bin/run_tests.php
```

Natija (41 test, jumladan yangi tozalash/sweep oqimi):
```text
=========================================================
🧪 Block-BOT: Avtomatlashtirilgan Qat'iy Testlar To'plami
=========================================================
[01] testDuplicateUpdateDoesNotPunishTwice                   ... ✅ [PASSED]
...
[33] testPlainTextBlacklistWordRuleIsEnforced                ... ✅ [PASSED]
[34] testWhitelistWordRuleOverridesBlacklist                 ... ✅ [PASSED]
[35] testChatMemberJoinQueuesProfileScan                     ... ✅ [PASSED]
[36] testAdultAndBotAccountDecisionsAreMuteWithAdminConfirm  ... ✅ [PASSED]
[37] testProfileModeratorFlagsUnauthorizedBotButExemptsAdminBot ... ✅ [PASSED]
[38] testAuditCleanupDeletesFlaggedHistoricalMessagesOnly    ... ✅ [PASSED]
[39] testAuditCleanupCallbackRequiresAdmin                   ... ✅ [PASSED]
[40] testMemberSweepFlagsAdultAndBotAccounts                 ... ✅ [PASSED]
[41] testLocalPhaseDefersAiButActsOnDeterministicViolation   ... ✅ [PASSED]
=========================================================
Test Natijalari:
• Jami testlar: 41
• Muvaffaqiyatli: 41 ✅
• Xatolar: 0 🎉
=========================================================
```
