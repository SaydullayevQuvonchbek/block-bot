# 🌐 Shared Hosting Yo'riqnomasi (Block-BOT)

Ushbu yo'riqnoma **Block-BOT** tizimini Shared Hosting (cPanel, DirectAdmin, FastPanel, ISPmanager yoki OpenServer) muhitida o'rnatish va sozlash uchun mo'ljallangan.

---

## 1. Arxitektura Xususiyatlari va Cheklovlar

Shared hosting muhitida fonda doimiy ishlovchi protsesslar (daemons) cheklangan yoki taqiqlangan bo'ladi. Shu sababli bot quyidagi rejimda ishlaydi:

* **Webhook:** Telegram'dan kelgan har bir yangilanishni (xabar, servis xabari) bir zumda qabul qilib, uni MySQL `queue_jobs` jadvaliga joylashtiradi va Telegram'ga 200 OK qaytaradi. Bu server resurslarini tejaydi va Telegram timeout xatolarini oldini oladi.
* **Cron:** Serverning `crontab` xizmati orqali har daqiqada `bin/cron.php` skripti ishga tushadi.

> [!IMPORTANT]
> **Media va Profil Tekshiruvi Kechikishi:**
> Shared hostingda `cron` har 1 daqiqada (yoki hosting taqdim etgan minimal oraliqda) ishga tushgani sababli:
> 1. Matndagi aniq so'kinishlar xabar kelgan paytda darhol jazolanishi mumkin.
> 2. Og'ir media (rasmlar, video, stikerlar) va profil tekshiruvi navbatga yoziladi va **navbatdagi cron ishga tushguncha (1-60 soniya)** kechikishi mumkin.
> 3. Bu vaqt ichida taqiqlangan media qisqa vaqt guruhda ko'rinib turishi tabiiy holatdir.

---

## 2. O'rnatish Qadamlari

### 2.1 Fayllarni yuklash
1. Loyiha papkasidagi barcha fayllarni hostingingizdagi domeningiz papkasiga yuklang (masalan: `public_html/` yoki `domains/sizning-domeningiz.uz/`).
2. Web serveringizning **DocumentRoot** manzilini `public/` papkasiga yo'naltiring. Agar DocumentRoot'ni o'zgartirish imkoni bo'lmasa, `.htaccess` orqali `public/` papkasiga yo'naltiring:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^$ public/ [L]
    RewriteRule (.*) public/$1 [L]
</IfModule>
```

### 2.2 Ma'lumotlar bazasini sozlash
1. cPanel / DirectAdmin boshqaruv panelidan yangi MySQL bazasi va foydalanuvchi yarating.
2. `.env.example` faylidan nusxa olib `.env` yarating:
   ```ini
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=sizning_baza_nomingiz
   DB_USER=sizning_baza_useringiz
   DB_PASS=kuchli_parol_123

   TELEGRAM_BOT_TOKEN=123456789:ABC...
   TELEGRAM_WEBHOOK_SECRET=kamida_32_belgidan_iborat_maxfiy_kalit
   TELEGRAM_WEBHOOK_URL=https://sizning-domeningiz.uz/webhook.php
   OPENROUTER_API_KEY=sk-or-v1-...
   ```
3. SSH yoki hosting boshqaruv panelidagi terminal orqali migratsiyani bajaring:
   ```bash
   php bin/migrate.php
   ```
   *(Agar SSH bo'lmasa, `database/migrations/001_initial_schema.sql` faylini phpMyAdmin orqali import qiling).*

### 2.3 Cron sozlash
Hosting boshqaruv panelida **Cron Jobs** (Регулярные задачи) bo'limiga kiring va quyidagi buyruqni har daqiqada bajariladigan qilib qo'shing:

```bash
* * * * * /usr/bin/php /home/username/public_html/bin/cron.php > /dev/null 2>&1
```
*(Eslatma: `/usr/bin/php` o'rniga hostingizdagi PHP 8.1 yoki 8.3 ning to'liq yo'lini ko'rsating, masalan: `/usr/local/bin/ea-php82`).*

### 2.4 Webhook o'rnatish
SSH orqali:
```bash
php bin/set_webhook.php set
```
Yoki brauzer orqali to'g'ridan-to'g'ri Telegram API'ga murojaat qiling:
```text
https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://sizning-domeningiz.uz/webhook.php&secret_token=<TELEGRAM_WEBHOOK_SECRET>
```

---

## 3. Diagnostika va Tekshirish
Har qanday nosozlikni aniqlash uchun terminalda buyruqni bering:
```bash
php bin/diagnose.php
```
Logs papkasini tekshirib boring: `storage/logs/app.log`, `storage/logs/telegram.log`, `storage/logs/ai.log`.
