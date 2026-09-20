#!/usr/bin/env bash
#
# Block-BOT 2.0 — Production deploy (HAQIQIY server yo'llari bilan)
# ==================================================================
# Bu skript DigitalOcean droplet konsolida ROOT sifatida ishga tushiriladi.
# Men konsolga ulanib, quyidagi haqiqiy joylashuvlarni aniqladim:
#
#   Ilova papkasi : /home/imezon/web/telegram-ai-bot.saydullayevapi.uz
#   Veb-root       : .../public_html  (repo'dagi public/ papkasiga mos keladi)
#   Worker xizmati : blockbot-worker.service  (user=imezon)
#   Domen          : telegram-ai-bot.saydullayevapi.uz
#   DB             : MySQL (production)
#
# Men bu joygacha bajardim:
#   1. Xavfsizlik nusxasi olindi: /root/blockbot-backups/pre-deploy-*.tar.gz
#   2. Yangi kod GitHub'dan /tmp/block-bot-deploy ga clone qilindi (commit 16f2d42)
#
# Keyingi qadam — quyidagi buyruqlarni konsolga joylashtiring (u allaqachon
# ochiq turibdi, root@telegram-ai-bot:~$ ko'rinishida). Xavfsizlik tizimi
# ko'p qatorli "ko'r" buyruqlarni avtomatik bajarishimga to'sqinlik qildi,
# shuning uchun buni endi siz o'zingiz bajarishingiz kerak.
# ==================================================================

set -euo pipefail

APP=/home/imezon/web/telegram-ai-bot.saydullayevapi.uz
SRC=/tmp/block-bot-deploy

# Agar /tmp/block-bot-deploy allaqachon mavjud bo'lmasa (masalan, konsol
# vaqti tugagan bo'lsa), qayta clone qilamiz:
if [ ! -d "$SRC/.git" ]; then
    rm -rf "$SRC"
    git clone --depth 1 https://github.com/SaydullayevQuvonchbek/block-bot.git "$SRC"
fi
echo "[OK] Manba kod: $(cd "$SRC" && git log -1 --oneline)"

echo ""
echo "[1/7] Kod fayllarini yangilash (.env va storage/ tegilmaydi)..."
rsync -a --delete "$SRC/bin/" "$APP/bin/"
rsync -a --delete "$SRC/config/" "$APP/config/"
rsync -a --delete "$SRC/database/" "$APP/database/"
rsync -a --delete "$SRC/src/" "$APP/src/"
rsync -a "$SRC/lang/" "$APP/lang/"
cp "$SRC/composer.json" "$APP/composer.json"
cp "$SRC/composer.lock" "$APP/composer.lock"
# DIQQAT: --delete YO'Q — public_html/robots.txt kabi repo'da bo'lmagan
# fayllarni saqlab qolish uchun, faqat qo'shish/yangilash:
rsync -a "$SRC/public/" "$APP/public_html/"
echo "[OK] Fayllar yangilandi."

echo ""
echo "[2/7] Egalik huquqlarini tiklash (imezon:imezon)..."
chown -R imezon:imezon "$APP/bin" "$APP/config" "$APP/database" "$APP/src" "$APP/lang" "$APP/public_html" "$APP/composer.json" "$APP/composer.lock"

echo ""
echo "[3/7] Composer bog'liqliklarini yangilash..."
cd "$APP"
sudo -u imezon composer install --no-dev --optimize-autoloader --no-interaction

echo ""
echo "[4/7] PHP sintaksis tekshiruvi..."
LINT_FAIL=0
while IFS= read -r -d '' f; do
    if ! php -l "$f" > /tmp/lint_out.$$ 2>&1; then
        echo "  [XATO] $f"; cat /tmp/lint_out.$$; LINT_FAIL=1
    fi
    rm -f /tmp/lint_out.$$
done < <(find "$APP" -name "*.php" -not -path "*/vendor/*" -print0)
if [ "$LINT_FAIL" -eq 1 ]; then
    echo "  [TO'XTATILDI] Lint xatolari topildi."
    exit 1
fi
echo "  [OK] Barcha PHP fayllar toza."

echo ""
echo "[5/7] Ma'lumotlar bazasi migratsiyasi (004 dan 008 gacha)..."
sudo -u imezon php "$APP/bin/migrate.php"

echo ""
echo "[6/7] storage/ ruxsatlarini tekshirish..."
mkdir -p "$APP/storage/logs" "$APP/storage/temp" "$APP/storage/sessions" "$APP/storage/health"
chown -R imezon:imezon "$APP/storage"

echo ""
echo "[7/7] Worker xizmatini qayta ishga tushirish va tekshirish..."
systemctl restart blockbot-worker.service
sleep 2
systemctl is-active --quiet blockbot-worker.service && echo "  [OK] blockbot-worker.service ishlamoqda" || echo "  [XATO] worker ishga tushmadi! journalctl -u blockbot-worker -n 50 bilan tekshiring"

echo ""
echo "Health-check (agar public_html/healthz.php ishlasa):"
curl -sS "https://telegram-ai-bot.saydullayevapi.uz/healthz.php" || echo "  [OGOHLANTIRISH] healthz.php so'roviga javob bo'lmadi"

echo ""
echo "=========================================================="
echo " DEPLOY YAKUNLANDI"
echo " Orqaga qaytarish kerak bo'lsa: /root/blockbot-backups/ dagi tar.gz'dan tiklang"
echo "=========================================================="
