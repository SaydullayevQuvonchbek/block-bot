# 🖥 VPS va Server Muhiti Yo'riqnomasi (Block-BOT)

Ushbu yo'riqnoma **Block-BOT** tizimini VPS yoki bag'ishlangan serverda (Ubuntu 22.04 / 24.04 LTS, Debian 12) to'liq quvvatda, doimiy fonda ishlovchi worker (daemon), FFmpeg va MTProto bilan birgalikda o'rnatish uchun mo'ljallangan.

---

## 1. Dasturiy Ta'minotlarni O'rnatish

```bash
# Tizimni yangilash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 va kerakli kengaytmalarni o'rnatish
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring \
                    php8.3-xml php8.3-gd php8.3-zip php8.3-bcmath php8.3-gmp

# Nginx, MySQL va FFmpeg o'rnatish
sudo apt install -y nginx mysql-server ffmpeg certbot python3-certbot-nginx
```

---

## 2. Nginx Konfiguratsiyasi

Nginx konfiguratsiyasini yarating: `/etc/nginx/sites-available/block-bot`

```nginx
server {
    listen 80;
    server_name bot.sizning-domeningiz.uz;

    root /var/www/Block-BOT/public;
    index index.html webhook.php;

    client_max_body_size 25M;

    # Xavfsizlik: .env, bin, config, storage papkalariga tashqi kirishni qat'iy taqiqlash
    location ~ /\.(env|git) {
        deny all;
        return 404;
    }

    location ~ ^/(bin|config|database|src|storage|tests|vendor) {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /webhook.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Saytni faollashtirish va SSL o'rnatish:
```bash
sudo ln -s /etc/nginx/sites-available/block-bot /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d bot.sizning-domeningiz.uz
```

---

## 3. Doimiy Worker (Systemd Daemon) Sozlash

VPS rejimida navbatdagi vazifalar `bin/worker.php` yordamida uzluksiz, kechikishlarsiz qayta ishlanadi.

Systemd xizmat faylini yarating: `/etc/systemd/system/block-bot-worker.service`

```ini
[Unit]
Description=Block-BOT Telegram Moderation Worker Daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/Block-BOT
ExecStart=/usr/bin/php8.3 /var/www/Block-BOT/bin/worker.php
StandardOutput=append:/var/www/Block-BOT/storage/logs/worker.log
StandardError=append:/var/www/Block-BOT/storage/logs/worker_error.log

# Resurs cheklovlari va xavfsizlik
MemoryMax=512M
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

Xizmatni ishga tushiring:
```bash
sudo systemctl daemon-reload
sudo systemctl enable block-bot-worker
sudo systemctl start block-bot-worker

# Holatini tekshirish
sudo systemctl status block-bot-worker
```

---

## 3.1 Gorizontal Scaling — Bir Nechta Worker Parallel Ishlatish

Odatiy holatda bitta `bin/worker.php` jarayoni yetarli (navbatdagi vazifalar juda tez, ~1 soniyalik siklda ishlanadi). Lekin guruhlar/xabarlar soni juda ko'payib, navbat (`queue_jobs`) doimiy to'lib qolsa (buni `php bin/healthcheck.php` yoki `/healthz.php` orqali kuzatish mumkin — "queue" tekshiruvi "eskirgan" deb ko'rsata boshlaydi), bir nechta worker jarayonini **parallel** ishga tushirish mumkin.

**Muhim shart: faqat MySQL bilan.** `QueueService::reserve()` vazifani band qilishda MySQL'da `SELECT ... FOR UPDATE` (qator darajasidagi qulf) ishlatadi — bu bir nechta worker bitta vazifani ikki marta olib qo'ymasligini kafolatlaydi. SQLite'da esa bu funksiya mavjud emas va SQLite umuman parallel yozishni yaxshi qo'llab-quvvatlamaydi — shuning uchun **gorizontal scaling faqat `DB_DRIVER=mysql` bilan ishlatilsin**, SQLite'da bir nechta worker "database is locked" xatolariga olib kelishi mumkin.

Systemd **shablon xizmati** yarating: `/etc/systemd/system/block-bot-worker@.service` (fayl nomidagi `@` belgisiga e'tibor bering — bitta oddiy `.service` emas):

```ini
[Unit]
Description=Block-BOT Telegram Moderation Worker Daemon (instance %i)
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/Block-BOT
# %i — instansiya nomi (masalan "1", "2"), WORKER_ID sifatida uzatiladi:
# health-check har bir workerni alohida "jonlik" fayli orqali kuzatishi uchun kerak
Environment=WORKER_ID=%i
ExecStart=/usr/bin/php8.3 /var/www/Block-BOT/bin/worker.php
StandardOutput=append:/var/www/Block-BOT/storage/logs/worker-%i.log
StandardError=append:/var/www/Block-BOT/storage/logs/worker-%i_error.log

MemoryMax=512M
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

Ikkita (yoki xohlagancha) instansiyani ishga tushirish:
```bash
sudo systemctl daemon-reload

# Eski oddiy block-bot-worker.service ishlatilgan bo'lsa, avval uni to'xtating
sudo systemctl disable --now block-bot-worker 2>/dev/null || true

sudo systemctl enable --now block-bot-worker@1
sudo systemctl enable --now block-bot-worker@2

# Har birining holatini alohida tekshirish
sudo systemctl status block-bot-worker@1 block-bot-worker@2
```

Nechta worker kerakligi haqida: bu bot vazifalari asosan AI provayder (Gemini/OpenRouter) javobini **kutish** bilan bog'liq (tarmoq so'rovi, CPU emas) — shuning uchun 2-4 ta worker odatda yetarli, undan ko'pi asosan tarmoq/AI so'rov chegarasiga (rate limit) va MySQL ulanishlar soniga tirmashadi, foyda bermay qo'yadi. `php bin/healthcheck.php` yoki `/healthz.php`'dagi `worker_heartbeat.workers` ro'yxatidan har bir workerning alohida holatini (jonlimi, qancha vaqtdan beri jim) kuzatib, kerak bo'lsa sonini sozlang.

---

## 4. MTProto CLI Autentifikatsiyasi (Tarixiy Audit uchun)

MTProto moduli bot qo'shilishidan oldingi tarixni va guruhning ko'rinadigan a'zolarini o'qish uchun alohida Telegram foydalanuvchi akkauntidan foydalanadi:

1. MadelineProto kutubxonasini o'rnating:
   ```bash
   composer require danog/madelineproto
   ```
2. Server konsolida (CLI) autentifikatsiyani bajaring:
   ```bash
   php bin/mtproto_login.php
   ```
3. Telefon raqamingiz, Telegram'ga kelgan tasdiqlash kodi va (agar yoqilgan bo'lsa) 2FA parolini konsolda xavfsiz kiriting.
4. Sessiya fayli `storage/sessions/mtproto.session` manzilida cheklangan huquqlar (0600) bilan saqlanadi.

---

## 5. Monitoring va Loglarni Kuzatish

```bash
# Jonli moderatsiya va worker loglari
tail -f /var/www/Block-BOT/storage/logs/worker.log

# Telegram API so'rovlari va xatoliklari
tail -f /var/www/Block-BOT/storage/logs/telegram.log

# AI xarajatlari va Gemini/OpenRouter so'rovlari
tail -f /var/www/Block-BOT/storage/logs/ai.log

# Tizim diagnostikasi
php bin/diagnose.php
```

### 5.1 Log Rotatsiyasi (avtomatik, Phase 2)

`App\Core\Logger` yozadigan kanal fayllari (`ai.log`, `telegram.log`, `moderation.log`, `queue.log`, `database.log`, `app.log` — `storage/logs/`ichida) **avtomatik ravishda rotatsiya qilinadi**: fayl 10 MB'dan (standart) oshsa, `channel.log.1.gz` deb siqilib saqlanadi, eskilari bittaga siljiydi (`.1`→`.2`, ...), eng eskisi (standart 5 tadan oshgani) butunlay o'chiriladi. Bu tizim logrotate'iga kirish bo'lmagan shared-hosting o'rnatishlarda ham ishlaydi, shuning uchun standart holatda hech qanday qo'shimcha sozlash shart emas.

Sozlash (`.env` orqali, ixtiyoriy):
```bash
# Chegara (bayt). 0 = rotatsiyani butunlay o'chirish (masalan, tizim logrotate ishlatilsa)
LOG_MAX_SIZE_BYTES=10485760
# Nechta eski backup saqlanadi
LOG_MAX_BACKUPS=5
# Backuplarni gzip bilan siqishmi (standart: ha, zlib kengaytmasi mavjud bo'lsa)
LOG_COMPRESS_BACKUPS=true
```

**Muhim farq:** yuqoridagi rotatsiya faqat PHP kodi `Logger::log()` orqali yozadigan fayllarni qamrab oladi. Systemd xizmat fayli (3-band) `StandardOutput=append:.../worker.log` orqali workerning konsol chiqishini **alohida** faylga yozadi — bu operatsion tizim darajasida, Logger'dan tashqarida ishlaydi, shuning uchun uni ham cheklash kerak. Buning uchun tizim `logrotate`'idan foydalaning: `/etc/logrotate.d/block-bot-worker`

```
/var/www/Block-BOT/storage/logs/worker*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```
(`copytruncate` kerak, chunki systemd fayl deskriptorni ochiq tutadi — oddiy `rename` unga ta'sir qilmaydi.)
