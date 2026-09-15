# O'zgarishlar tarixi (Changelog)

Ushbu loyiha [Semantic Versioning](https://semver.org/lang/ru/) tartibiga amal qiladi: `MAJOR.MINOR.PATCH`.

## [Unreleased] — v2.0.0-dev

### Qo'shildi
- **Docker qo'llab-quvvatlashi**: `docker/php/Dockerfile` (PHP 8.3-FPM Alpine, ffmpeg bilan),
  `docker/nginx/default.conf`, `docker-compose.yml` (app + worker + nginx + MySQL 8 xizmatlari,
  production'dagi kabi `public/` web-root topologiyasi bilan), `.env.docker.example`, `.dockerignore`.
- **CI (GitHub Actions)**: `.github/workflows/ci.yml` — har bir push/PR'da PHP 8.1/8.2/8.3 ustida
  `php -l` sintaksis tekshiruvi, `bin/run_tests.php` test to'plami (SQLite xotira bazasi bilan) va
  Docker tasvirini yig'ish smoke-testi.
- Ushbu `CHANGELOG.md` — kelgusi barcha 2.0 o'zgarishlari shu faylga yoziladi.

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
- **1-bosqich**: anti-flood (xabar tezligi cheklovi), yangi a'zolar uchun CAPTCHA/tasdiqlash,
  ovozli/audio xabarlar moderatsiyasi, animatsion stikerlar (`.tgs`/`.webm`) qo'llab-quvvatlashi.
- **2-bosqich**: bot-darajasidagi "moderator" roli, health-check/observability, worker'ni
  gorizontal masshtablash bo'yicha hujjat, log rotatsiyasi.
- **3-bosqich**: i18n (ko'p tillilik), Telegram Stars orqali monetizatsiya, Web Dashboard / Mini App.
- **4-bosqich (ixtiyoriy)**: forum-topics qo'llab-quvvatlashi, sozlamalarni klonlash/eksport-import,
  rejalashtirilgan xabar yuborish (broadcast), PHPUnit qatlami.

## [1.0.0] — Ishlab chiqarishga joylashtirildi

- Birinchi to'liq ishlaydigan versiya: Telegram webhook + fon worker (VPS) yoki cron (shared
  hosting) orqali AI (Gemini/OpenRouter) yordamida guruh moderatsiyasi, ogohlantirish/mute/ban
  jazolari, shikoyat (appeal) tizimi, admin panel buyruqlari, tarixiy auditni import qilish va
  boshqa funksiyalar.
