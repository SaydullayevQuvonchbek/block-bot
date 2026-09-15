# Block-BOT — Joriy holat tahlili va 2.0 versiyasi uchun yo'l xaritasi

Sana: 2026-09-14
Manba: `github.com/SaydullayevQuvonchbek/block-bot` (production: `telegram-ai-bot.saydullayevapi.uz`)
Tahlil usuli: to'liq kodni (src/, bin/, config/, database/, tests/, README*) qatordan-qator o'rganish.

---

## 1. Qisqacha xulosa

Block-BOT hozirgi holatida — bitta guruh-moderatsiya boti uchun **kutilganidan ancha yetuk** loyiha. 9600+ qator PHP kod, 49 ta avtomatlashtirilgan test, idempotentlik, konkurentlik xavfsizligi, shikoyat tizimi, rollback tugmalari, AI budjet nazorati kabi "production-grade" narsalar allaqachon mavjud va sinchiklab ishlab chiqilgan. Bu 1.0 emas, aslida "1.5" darajasidagi mahsulot.

Shunga qaramay, quyidagi **olti yo'nalishda** aniq bo'shliqlar bor — bularning har biri 2.0 uchun mustaqil, mantiqiy "katta yangilanish" bo'la oladi:

1. **Guruhni real vaqtda himoya qilish** — flood/spam portlashi va yangi a'zo tekshiruvi (captcha) yo'q.
2. **Boshqaruv tajribasi (UX)** — hammasi Telegram matn-menyu orqali; vizual boshqaruv paneli yo'q.
3. **Kengayish/marketing** — faqat o'zbek tili, faqat matn/rasm/video; ovozli xabar va ko'p tillilik yo'q.
4. **Rol tizimi** — faqat Telegram "admin/creator"; botga xos "moderator" roli yo'q.
5. **Joylashtirish (DevOps)** — Docker/CI yo'q (biz shaxsan shu sababli production'da ikkita xatoga duch keldik).
6. **Pul ishlash** — bot multi-tenant SaaS bo'lishga tayyor, lekin hech qanday tarif/to'lov tizimi yo'q.

Quyida har biri kod darajasida asoslanib, ustuvorlik bilan tartiblangan.

---

## 2. Hozirgi kuchli tomonlar (2.0 buni buzmasligi kerak)

- **Ko'p bosqichli matn moderatsiyasi**: leetspeak/homoglif normalizatsiya, "begunoh so'z" istisnolari (`qoshiq`, `kuchukcha` kabi false-positive'lar oldini olish), regex asosidagi tezkor qoida bazasi (qimor, treyding-firibgarlik, APK tarqatish, reklama shablonlari) — bularning barchasi AI'siz, bepul va bir zumda ishlaydi.
- **AI xarajatini tejash**: 24 soatlik natija keshi, "shubhali kontekst" bo'lmasa AI umuman chaqirilmaydi, kunlik/oylik qat'iy budjet va **atomik rezervatsiya** (parallel so'rovlar budjetni "oshirib yubormasligi" testlar bilan tasdiqlangan — `BudgetLimitEnforcedInParallelRequests`).
- **Konkurentlik va ishonchlilik**: idempotency kalitlari (`telegram_actions`), albom (`media_group_id`) uchun bitta jazo, tahrirlangan xabar qayta hisoblanmasligi, parallel worker'lar bir xabarni ikki marta jazolamasligi — bularning har biri alohida test bilan qoplangan.
- **Shikoyat va rollback**: har bir mute/ban uchun foydalanuvchiga shaxsiy "Shikoyat qilish" tugmasi, admin logida "Unmute/Unban/Oq ro'yxat/Noto'g'ri topilma" bitta-tugmali bekor qilish.
- **Tarixiy audit**: Telegram Desktop eksportini xavfsiz import qilish (zip-bomb va path-traversal himoyasi), MTProto orqali checkpoint bilan davom ettiriladigan tarixiy skanerlash, **hech qachon avtomatik ommaviy ban bermaydi** — avval hisobot, keyin tasdiq.
- **Xavfsizlik**: SSRF himoyasi (localhost/private IP/cloud metadata bloklash), CSV formula-injection himoyasi, PDO prepared statement'lar hamma joyda.
- **Multi-tenant arxitektura**: har bir admin faqat o'zi boshqargan guruhlarni ko'radi; `TELEGRAM_OWNER_IDS` faqat platforma egasi uchun. Bu allaqachon SaaS bo'lishga tayyor poydevor.

---

## 3. 2.0 uchun taklif etilgan yo'nalishlar

### P0 — Muhim: guruh xavfsizligidagi haqiqiy bo'shliqlar

Bular "moderatsiya boti" deb ataladigan mahsulot uchun endi standart hisoblanadi (@GroupHelp, @Combot, @Rose kabi raqobatchilarda bor), lekin Block-BOT'da yo'q:

1. **Anti-flood / spam-portlash himoyasi yo'q.**
   Kod bazasida `flood`/`rate limit` so'zlari faqat Telegram'ning o'zi qaytaradigan 429 xatosini ushlash uchun ishlatiladi (`src/Core/TelegramClient.php:138`) — foydalanuvchi tomonidan yuborilgan xabarlar sonini kuzatuvchi hech qanday mexanizm yo'q. Ya'ni kimdir 1 soniyada 20 ta xabar yozsa (flood/spam-bombardimon), har biri **alohida-alohida** moderatsiyadan o'tadi, lekin "juda tez-tez yozayapti" degan mustaqil qoidabuzarlik sifatida hech qachon ushlanmaydi.
   *Taklif:* `chat_members` yoki yangi `message_rate` jadvalida sliding-window hisoblagich (masalan, "10 soniyada 6+ xabar = avtomatik qisqa mute + admin xabarnomasi").

2. **Yangi a'zolar uchun CAPTCHA/tasdiqlash yo'q.**
   Hozir yangi a'zo faqat **fon rejimida** (`ScanProfileJob`) profil bo'yicha tekshiriladi — lekin guruhga kirgan zahoti darhol yoza oladi. Ko'plab spam-bot hujumlari aynan shu oynada (kirish va birinchi xabar orasida) sodir bo'ladi.
   *Taklif:* ixtiyoriy sozlama — yangi a'zo birinchi 60 soniya davomida "✅ Men botman emas" tugmasini bosmaguncha `can_send_messages=false` qilib qo'yish (Telegram Bot API `restrictChatMember` orqali — qo'shimcha kutubxona kerak emas, `TelegramClient`da allaqachon shunga o'xshash metod bor).

3. **Ovozli xabar (voice/audio) moderatsiyasi umuman yo'q.**
   `MediaModerator` faqat `photo`, `video`, `animation`, `sticker`, `document` turlarini biladi (`src/Moderation/MediaModerator.php:175`) — `voice` va `audio` turi hech qayerda ishlanmaydi, ya'ni ovozli xabar orqali yuborilgan haqorat/reklama **hech qachon tekshirilmaydi**.
   *Taklif:* Gemini/OpenRouter'ning audio-transkripsiya (yoki Whisper API) orqali ovozni matnga o'girib, mavjud `TextModerator::inspect()` orqali yuborish — infratuzilma allaqachon tayyor, faqat yangi kirish nuqtasi kerak.

4. **Animatsion stikerlar (TGS/WEBM) tekshirilmaydi.**
   `inspectSticker()` faqat statik `.webp`ni tekshiradi, TGS (Lottie) va video-stikerlar `unscannable` deb belgilanadi (`MediaModerator.php:297-313`). Bugungi kunda ko'pchilik ofensiv stiker to'plamlari aynan animatsion formatda.
   *Taklif:* video-stiker uchun mavjud FFmpeg kadr-ajratish logikasini qayta ishlatish mumkin (WEBM — video sifatida); TGS uchun `lottie-to-png` konvertatsiyasi qo'shish kerak.

### P1 — Katta qiymat, o'rtacha mehnat

5. **Vizual boshqaruv paneli (Web Dashboard / Telegram Mini App) yo'q.**
   Hozir HAMMA narsa — sozlamalar, statistika, audit hisobotlari — Telegram ichidagi matn-menyu va inline tugmalar orqali boshqariladi. Bu kichik guruh uchun yetarli, lekin ko'p guruhli admin (masalan, 10 ta guruhni boshqaruvchi) uchun noqulay: grafik statistika, jadval ko'rinishidagi ogohlantirishlar tarixi, bir joyda barcha guruh sozlamalarini solishtirish imkoni yo'q.
   *Taklif:* Telegram Mini App (WebView) sifatida oddiy dashboard — mavjud `/stats`, `/ai_usage`, `/warnings` ma'lumotlarini vizual jadval/diagramma qilib ko'rsatish. Backend allaqachon tayyor (bir xil MySQL bazasi), faqat frontend va bir nechta REST endpoint kerak.

6. **Ko'p tillilik (i18n) yo'q.**
   Kod bazasida bironta `locale`/`i18n`/`lang_` fayli yoki tuzilmasi topilmadi — barcha xabarlar (`"⚠️ Ogohlantirish!"`, xato matnlari va h.k.) to'g'ridan-to'g'ri PHP fayllar ichida qattiq yozilgan o'zbek tilida. Bu O'zbekiston tashqarisidagi (yoki rus/ingliz tilida ishlaydigan) guruhlar uchun botni ishlatib bo'lmasligini anglatadi.
   *Taklif:* `lang/uz.php`, `lang/ru.php`, `lang/en.php` kabi qaytaruvchi massiv fayllar va `SettingsService`ga `language` ustuni qo'shish — katta arxitektura o'zgarishisiz, bosqichma-bosqich amalga oshiriladigan ish.

7. **Moderator roli yo'q — faqat Telegram "admin/creator".**
   `AdminAuthorizationService::isAdmin()` faqat Telegram'ning o'z `creator`/`administrator` statusiga tayanadi (`AdminAuthorizationService.php:62`). Botga xos, cheklangan huquqli "ishonchli a'zo" (masalan, faqat `/warn` bera oladigan, lekin `/settings`ga kira olmaydigan) roli yo'q.
   *Taklif:* `chat_members`ga `bot_role` ustuni (`moderator`/`admin`/`none`) qo'shib, har bir buyruq uchun minimal talab qilingan rolni belgilash.

8. **Kuzatuv (observability) minimal.**
   Faqat fayl-log (`storage/logs/*.log`) bor; xato kuzatuv xizmati (Sentry va h.k.), metrikalar (navbat uzunligi, AI xarajat grafikasi, worker sog'ligi) yoki health-check HTTP endpoint yo'q. Biz o'zimiz production'da duch kelgan ikkita xato (`chmod`/`open_basedir`) aynan **shunday kuzatuv yo'qligi tufayli** faqat foydalanuvchi shikoyat qilgandan keyin aniqlandi.
   *Taklif:* `bin/worker.php`ga oddiy `/healthz` yozuvi (masalan, har daqiqada faylga "men tirikman" belgisi qo'yish) + `bin/doctor.php`ni cron orqali soatiga bir marta ishga tushirib, muammo topilsa admin log guruhiga avtomatik xabar yuborish.

9. **Monetizatsiya / tarif tizimi yo'q.**
   Bot to'liq multi-tenant qilib qurilgan (har bir admin faqat o'z guruhini ko'radi), lekin `group_settings`da yoki boshqa joyda "tarif" (free/premium) tushunchasi mutlaqo yo'q — barcha guruhlar bir xil cheksiz imkoniyatga ega. Agar bu mahsulot sifatida tarqatilishi rejalashtirilsa, buni pul ishlash yo'liga aylantirish mumkin.
   *Taklif:* Telegram Stars to'lovi (Bot API'ning o'ziga o'rnatilgan, tashqi to'lov tizimi shart emas) orqali: bepul tarif — asosiy filtrlash + kuniga cheklangan AI so'rovlar; premium — yuqori AI budjet, tarixiy audit, ovozli moderatsiya, dashboard.

### P2 — Kelajak uchun, past-o'rta mehnat

10. **CI/CD yo'q.** Repo'da `.github/workflows`, `Dockerfile` yoki `docker-compose.yml` topilmadi — hech qanday narsa avtomatik tekshirilmaydi. Har bir `git push`dan keyin `php -l` va `php bin/run_tests.php`ni avtomatik ishga tushiradigan bitta oddiy GitHub Actions fayli xatolarni production'ga yetib borishidan oldin ushlab qoladi.
11. **Docker yo'q.** Biz shaxsan Hestia serverida qo'lda joylashtirishda ikki soatlab vaqt ketadigan ikkita nozik xatoga (papka huquqi, `open_basedir`) duch keldik — bular Docker konteynerida umuman yuzaga kelmasdi. 2.0 uchun rasmiy `Dockerfile` + `docker-compose.yml` (PHP-FPM + nginx + MySQL) qo'shish keyingi joylashtirishlarni soatlar emas, daqiqalarga tushiradi.
12. **Forum-mavzular (topics) qo'llab-quvvatlanishi tekshirilmagan.** Zamonaviy Telegram superguruhlarida "Forum" rejimi (`message_thread_id`) keng tarqalgan; kodda bu maydon alohida ishlanmagan — mavzular ichida moderatsiya to'g'ri ishlashi tasdiqlanmagan.
13. **Guruhlar orasida sozlamalarni nusxalash/zaxiralash yo'q.** Ko'p guruhli admin uchun "bitta guruhdagi sozlamalarni boshqasiga nusxalash" yoki "sozlamalarni export/import qilish" funksiyasi qulay bo'lardi.
14. **Rejalashtirilgan/ommaviy xabar yuborish yo'q.** Admin o'z guruhlariga e'lon yoki ogohlantirish botning o'zi orqali (masalan, "barcha guruhlarimga xabar yubor") yubora olmaydi.

---

## 4. Texnik qarz (kichik, lekin e'tiborga loyiq)

- **Composer bog'liqliklari yo'q** (`composer.json`da faqat PHP kengaytmalari bor) — bu ataylab qilingan (shared hosting'da composer paketlarini o'rnatib bo'lmasligi mumkin degan taxmin bilan) va portativlik uchun yaxshi, lekin bu degani hamma narsa — HTTP client, logger, test runner — qo'lda yozilgan. 2.0'da bu tanlovni saqlab qolish tavsiya etiladi (ishlab chiqilgan kod sifatli), faqat ixtiyoriy "agar composer mavjud bo'lsa, PHPUnit orqali ham ishga tushirish mumkin" moslashuvchan qatlam qo'shsa bo'ladi.
- **Git tarixi yo'q** — repo bitta "Block-BOT deploy" commiti bilan boshlangan (squash qilingan holda push qilingan). 2.0'dan boshlab oddiy commit gigienasi (`CHANGELOG.md`, semantik versiyalash `v2.0.0` teglari) loyihani kuzatishni osonlashtiradi.
- **`storage/logs/`dagi eski test-qoldiq yozuvlar** — avvalgi tahlilda qayd etilgan (`chat: -100123` kabi) haqiqiy production xatosi emasga o'xshaydi, lekin tozalab, log-rotatsiya (`logrotate`) sozlash tavsiya etiladi.

---

## 5. Tavsiya etilgan 2.0 ustuvorlik tartibi

Agar bittadan boshlash kerak bo'lsa, ta'sir/mehnat nisbati bo'yicha eng oqilona tartib:

1. **Anti-flood himoyasi** (P0-1) — eng kam mehnat, eng ko'rinadigan xavfsizlik yutug'i.
2. **Yangi a'zo uchun CAPTCHA** (P0-2) — Telegram Bot API'da allaqachon bor metodlar bilan amalga oshiriladi.
3. **Docker + CI/CD** (P2-10, P2-11) — keyingi joylashtirishlarni tezlashtiradi va bizning o'zimiz duch kelgan xatolarni takrorlanmasligini kafolatlaydi.
4. **Moderator roli** (P1-7) — ko'p guruhli adminlar uchun darhol foydali, ma'lumotlar bazasiga bitta ustun qo'shish darajasida.
5. **Ovozli xabar moderatsiyasi** (P0-3) — mavjud AI infratuzilmasidan foydalanadi, faqat yangi kirish nuqtasi.
6. **Monetizatsiya (Telegram Stars tarif tizimi)** (P1-9) — agar bot boshqa foydalanuvchilarga ham tarqatilishi rejalashtirilsa, bu bosqichda joriy qilinishi kerak.
7. **Web Dashboard / Mini App** (P1-5) va **ko'p tillilik** (P1-6) — eng katta mehnat talab qiladigan, lekin mahsulotni "ixlosmand loyiha"dan "tijorat mahsuloti"ga aylantiradigan ikki yo'nalish.

---

## 6. Metodologiya eslatmasi

Ushbu tahlil GitHub'dagi `main` branch'ining to'liq nusxasini klonlab, quyidagilarni bevosita o'qish orqali tayyorlandi: barcha `src/` moduli (AI, Audit, Core, Http, Jobs, Moderation, Policy — 9671 qator PHP), `config/*.php`, `database/migrations/*.sql`, `tests/ModerationBotTest.php` (49 test nomi), `bin/worker.php`, uchta README fayli va `composer.json`. Git tarixi faqat bitta commit bo'lgani uchun xususiyatlarning rivojlanish tarixini kuzatib bo'lmadi — tahlil faqat joriy holatga asoslangan.
