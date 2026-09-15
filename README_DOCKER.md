# Block-BOT — Docker orqali ishga tushirish

Bu qo'llanma loyihani Docker Compose yordamida (lokal ishlab chiqish yoki alohida VPS'da)
ishga tushirish uchun. Production'dagi haqiqiy server (HestiaCP, `telegram-ai-bot.saydullayevapi.uz`)
hozircha to'g'ridan-to'g'ri (Docker'siz) ishlayapti — bu fayllar **2.0 versiyasining birinchi
bosqichi** sifatida qo'shildi va kelajakda o'sha serverni ham Docker'ga o'tkazish yoki yangi
serverlarni tezroq joylashtirish uchun ishlatilishi mumkin.

## Talablar

- Docker Engine 24+ va Docker Compose plugin (`docker compose version`)
- Tashqi internetga chiqish (Telegram Bot API, Gemini/OpenRouter API bilan bog'lanish uchun —
  agar bloklangan bo'lsa, `deploy/cloudflare-worker/telegram-proxy.js` orqali proksi sozlang)

## Ishga tushirish

```bash
git clone https://github.com/SaydullayevQuvonchbek/block-bot.git
cd block-bot

cp .env.docker.example .env
# .env faylini oching va TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET,
# GEMINI_API_KEY (yoki OPENROUTER_API_KEY) va DB_PASS qiymatlarini to'ldiring.

docker compose up -d --build

# Ma'lumotlar bazasi jadvallarini yaratish (birinchi marta va har yangilanishdan keyin):
docker compose exec app php bin/migrate.php

# Webhook'ni o'rnatish (TELEGRAM_WEBHOOK_URL .env'da to'g'ri ko'rsatilgan bo'lishi kerak,
# masalan https://your-domain.com/webhook.php — domen nginx konteyneriga yo'naltirilgan bo'lishi kerak):
docker compose exec app php bin/set_webhook.php set
```

Standart holatda ilova `http://localhost:8080` portida ko'rinadi (`.env`dagi `APP_PORT` bilan
o'zgartirish mumkin). Haqiqiy foydalanish uchun bu portni HTTPS reverse-proxy (masalan, Caddy,
Traefik yoki mavjud nginx/Apache + Let's Encrypt) orqasiga qo'yish kerak, chunki Telegram
webhook'lari faqat HTTPS orqali ishlaydi.

## Xizmatlar (services)

| Xizmat    | Vazifasi                                                             |
|-----------|-----------------------------------------------------------------------|
| `nginx`   | HTTP so'rovlarni qabul qiladi, `public/` ni beradi, `.php` so'rovlarni `app`ga uzatadi |
| `app`     | PHP-FPM — `public/webhook.php` ni ishga tushiradi (Telegram webhook so'rovlari) |
| `worker`  | `bin/worker.php` — fon rejimida AI moderatsiya navbatini (`queue_jobs`) qayta ishlaydi, production'dagi systemd worker bilan bir xil |
| `db`      | MySQL 8 — barcha ma'lumotlar shu yerda saqlanadi (`db_data` volume) |

## Foydali buyruqlar

```bash
docker compose logs -f worker      # worker jurnalini kuzatish
docker compose logs -f app         # PHP-FPM/webhook xatolarini ko'rish
docker compose exec app php bin/diagnose.php   # sozlamalarni tekshirish
docker compose exec app php bin/set_webhook.php info   # Telegram webhook holatini ko'rish
docker compose down                # to'xtatish (ma'lumotlar volume'da saqlanib qoladi)
docker compose down -v             # to'xtatish va barcha ma'lumotlarni o'chirish
```

## Eslatma

Bu sessiyada Docker CLI mavjud, ammo Docker daemon ishlamayotgani sababli `docker compose
up`/`build` shu yerda ishga tushirilib, oxirigacha sinovdan o'tkazilmadi — fayllar sintaktik
jihatdan tekshirildi (`docker compose config`), lekin real build/ishga tushirish loyihani
GitHub Actions CI (`.github/workflows/ci.yml`) orqali yoki Docker o'rnatilgan har qanday
mashinada tasdiqlanadi.
