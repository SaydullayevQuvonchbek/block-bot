#!/usr/bin/env bash
#
# Block-BOT 2.0 — Batched production deploy (Phase 2, 3, 4 to'liq)
# ==================================================================
# QO'LLANMA:
#   1. Avval blockbot-2.0-final-deploy.zip fayldagi kodni o'z GitHub
#      repozitoriyangizga (SaydullayevQuvonchbek/block-bot, "main" branch)
#      yuklab qo'ying (push qiling).
#   2. Shu skriptni DigitalOcean droplet konsoliga (Web Console yoki SSH)
#      TO'LIQ nusxalab joylashtiring va Enter bosing.
#   3. Skript o'zi: git pull -> migratsiya -> lint tekshiruvi -> worker
#      qayta ishga tushirish -> health-check tasdiqlash bosqichlaridan
#      iborat. Har bosqichda xato bo'lsa, skript to'xtaydi (set -e).
#
# MUHIM: agar loyihangiz "/var/www/Block-BOT"dan boshqa joyda bo'lsa,
# pastdagi APP_DIR qiymatini o'zgartiring.
# ==================================================================

set -euo pipefail

APP_DIR="/var/www/Block-BOT"
BRANCH="main"
PHP_BIN="php8.3"
WORKER_SERVICE="block-bot-worker"   # bitta workerli standart o'rnatish uchun
# Agar gorizontal scaling (bir nechta worker) ishlatilsa, quyidagi qatorni
# WORKER_SERVICE o'rniga izohdan chiqaring va mos instansiyalarni yozing:
# WORKER_SERVICES=("block-bot-worker@1" "block-bot-worker@2")

echo "=========================================================="
echo " Block-BOT 2.0 — Production Deploy"
echo " Sana: $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "=========================================================="

cd "$APP_DIR"

echo ""
echo "[1/8] Joriy holatni tekshirish..."
if [ -n "$(git status --porcelain)" ]; then
    echo "  [OGOHLANTIRISH] Working tree'da saqlanmagan lokal o'zgarishlar bor:"
    git status --porcelain
    echo "  Bular avtomatik 'git stash' qilinadi (keyin qayta tiklashingiz mumkin: git stash pop)."
    git stash push -u -m "auto-stash before deploy $(date +%s)"
fi

CURRENT_COMMIT=$(git rev-parse HEAD)
echo "  Joriy commit: ${CURRENT_COMMIT}"

echo ""
echo "[2/8] GitHub'dan so'nggi kodni olish (git pull)..."
git fetch origin "$BRANCH"
git merge --ff-only "origin/${BRANCH}"
NEW_COMMIT=$(git rev-parse HEAD)
echo "  Yangi commit: ${NEW_COMMIT}"

if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
    echo "  [DIQQAT] Kod o'zgarmadi — GitHub'da yangi commit topilmadi."
    echo "  Avval o'zgarishlarni push qilganingizga ishonch hosil qiling."
fi

echo ""
echo "[3/8] Composer bog'liqliklarini tekshirish..."
if [ -f composer.lock ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "  [SKIP] composer.lock topilmadi, o'tkazib yuborildi."
fi

echo ""
echo "[4/8] PHP sintaksis tekshiruvi (php -l), barcha o'zgargan fayllar..."
LINT_FAIL=0
while IFS= read -r -d '' f; do
    if ! "$PHP_BIN" -l "$f" > /tmp/lint_out.$$ 2>&1; then
        echo "  [XATO] $f"
        cat /tmp/lint_out.$$
        LINT_FAIL=1
    fi
    rm -f /tmp/lint_out.$$
done < <(find . -name "*.php" -not -path "./vendor/*" -print0)

if [ "$LINT_FAIL" -eq 1 ]; then
    echo "  [TO'XTATILDI] Lint xatolari topildi, deploy davom ettirilmadi."
    exit 1
fi
echo "  [OK] Barcha PHP fayllar toza."

echo ""
echo "[5/8] Ma'lumotlar bazasi migratsiyasi (bin/migrate.php)..."
"$PHP_BIN" bin/migrate.php

echo ""
echo "[6/8] Papka/fayl ruxsatlarini tekshirish (storage/)..."
mkdir -p storage/logs storage/temp storage/sessions storage/health
chown -R www-data:www-data storage
chmod -R 775 storage

echo ""
echo "[7/8] Worker xizmatini qayta ishga tushirish..."
if [ -n "${WORKER_SERVICES:-}" ]; then
    for svc in "${WORKER_SERVICES[@]}"; do
        echo "  Qayta ishga tushirilmoqda: $svc"
        systemctl restart "$svc"
    done
else
    systemctl restart "$WORKER_SERVICE"
fi
systemctl reload "php${PHP_BIN#php}-fpm" 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true

echo ""
echo "[8/8] Health-check tasdiqlash..."
sleep 2
if [ -n "${WORKER_SERVICES:-}" ]; then
    for svc in "${WORKER_SERVICES[@]}"; do
        systemctl is-active --quiet "$svc" && echo "  [OK] $svc ishlamoqda" || echo "  [XATO] $svc ishlamayapti!"
    done
else
    systemctl is-active --quiet "$WORKER_SERVICE" && echo "  [OK] $WORKER_SERVICE ishlamoqda" || echo "  [XATO] $WORKER_SERVICE ishlamayapti!"
fi
"$PHP_BIN" bin/healthcheck.php || echo "  [OGOHLANTIRISH] healthcheck.php ogohlantirish qaytardi, yuqoridagi natijani ko'rib chiqing."

echo ""
echo "=========================================================="
echo " DEPLOY YAKUNLANDI"
echo " ${CURRENT_COMMIT} -> ${NEW_COMMIT}"
echo "=========================================================="
echo ""
echo "Ixtiyoriy: yangi funksiyalarni sozlash uchun .env fayliga"
echo "qo'shishingiz mumkin bo'lgan qiymatlar (.env.example'da izohli):"
echo "  MINIAPP_URL, PREMIUM_STARS_PRICE, PREMIUM_DURATION_DAYS,"
echo "  FREE_TIER_DAILY_AI_REQUESTS, GEMINI_AUDIO_MODEL,"
echo "  LOG_MAX_SIZE_BYTES, LOG_MAX_BACKUPS, LOG_COMPRESS_BACKUPS."
echo "Bularsiz ham hammasi standart qiymatlar bilan ishlayveradi."
