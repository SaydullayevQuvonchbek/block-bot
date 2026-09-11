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
