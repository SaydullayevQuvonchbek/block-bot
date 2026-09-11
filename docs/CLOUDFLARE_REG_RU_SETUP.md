# Reg.ru Serverida Telegram Blokirovkasini Cloudflare Orqali Hal Qilish Qo'llanmasi

Ushbu qo'llanma Reg.ru hostingida Telegram Bot API (`api.telegram.org`) va Webhook blokirovkasini Cloudflare orqali **ikki tomonlama (two-way)** aylanib o'tishni bosqichma-bosqich tushuntiradi.

---

## 1. Muammoning Sababi

Reg.ru (Rossiya serverlari) Roskomnadzor cheklovlari tufayli:
1. **Chiquvchi blok:** Reg.ru serveringizdan `https://api.telegram.org` ga yuborilgan barcha cURL so'rovlarini to'xtatadi (Connection timeout yoki Connection refused).
2. **Kiruvchi blok:** Telegram serverlaridan Reg.ru IP manziliga yuboriladigan Webhook yangilanishlari yetib bormasligi yoki SSL muammosi yuzaga kelishi mumkin.

---

## 2. Yechim Arxitekturasi

```text
[Telegram Guruh]
       ▲
       │
       ▼
[Telegram Serverlari]
       ▲
       │  (100% ochiq, bloklanmagan)
       ▼
[Cloudflare Worker (Ikki tomonlama proksi)]
       ▲
       │  (100% ochiq, Reg.ru Cloudflare'ni bloklamaydi)
       ▼
[Reg.ru Hosting Serveri] ──► (Block-BOT / Webhook + Queue)
```

* **Chiquvchi yo'nalish:** Reg.ru serveringiz so'rovlarni Telegramga emas, Cloudflare Workerga yuboradi. Worker uni Telegramga uzatib, javobni qaytaradi.
* **Kiruvchi yo'nalish:** Telegram yangilanishlarni Workerga yuboradi (yoki Cloudflare Proxied DNS orqali), Worker esa Reg.ru dagi `webhook.php` ga yetkazadi.
* **Xarajat:** Cloudflare Worker bepul tarifida kuniga **100 000 ta so'rov** mutlaqo bepul!

---

## 3. Bosqichma-bosqich O'rnatish

### 1-Qadam: Cloudflare Worker Yaratish (2 daqiqa)

1. [Cloudflare Dashboard](https://dash.cloudflare.com/) saytiga kiring (agar hisobingiz bo'lmasa, bepul ro'yxatdan o'ting).
2. Chap menyudan **Workers & Pages** bo'limiga o'ting.
3. **Create Application** tugmasini bosing, so'ng **Create Worker** tugmasini bosing.
4. Workerga ixtiyoriy nom bering (masalan: `block-bot-proxy`) va **Deploy** tugmasini bosing.
5. Chiqqan sahifada **Edit code** tugmasini bosing.
6. Loyihangizdagi [`deploy/cloudflare-worker/telegram-proxy.js`](../deploy/cloudflare-worker/telegram-proxy.js) fayli kodini to'liq nusxalab, Worker muharririga joylang (mavjud kodni almashtiring).
7. Yuqori o'ng burchakdagi **Deploy** tugmasini bosing.

---

### 2-Qadam: Worker Sozlamalari (Variables)

1. Worker sahifasiga qaytib, **Settings** -> **Variables and Secrets** bo'limiga o'ting.
2. Quyidagi 2 ta o'zgaruvchini qo'shing:
   * `TARGET_WEBHOOK_URL`: Sizning Reg.ru serveringizdagi `webhook.php` manzili.
     * *Misol:* `https://mysite.ru/webhook.php`
   * `PROXY_SECRET`: Ixtiyoriy maxfiy kalit (proksingizdan boshqalar tekinga foydalanmasligi uchun).
     * *Misol:* `my_strong_proxy_secret_849204`
3. **Save and Deploy** tugmasini bosing.

> **Eslatma:** Sizning Workeringiz tayyor bo'ldi! Uning manzili taxminan shunday bo'ladi:
> `https://block-bot-proxy.sizning-subdomeningiz.workers.dev`

---

### 3-Qadam: Reg.ru Serveridagi `.env` Faylini Sozlash

Reg.ru serveringizdagi `.env` faylini oching va quyidagi qatorlarni o'zgartiring:

```env
# 1. Telegram rasmiy manzili o'rniga Cloudflare Worker manzilingizni yozing:
TELEGRAM_API_BASE_URL=https://block-bot-proxy.sizning-subdomeningiz.workers.dev

# 2. Agar Worker'da PROXY_SECRET o'rnatgan bo'lsangiz, bir xil kalitni yozing:
TELEGRAM_PROXY_SECRET=my_strong_proxy_secret_849204

# 3. Webhook URL:
# Agar domeningiz Cloudflare DNS'da bo'lsa (Orange Cloud ☁️ yoqilgan):
TELEGRAM_WEBHOOK_URL=https://mysite.ru/webhook.php

# Agar domeningiz Cloudflare'da bo'lmasa, to'g'ridan-to'g'ri Worker orqali yo'naltiring:
# TELEGRAM_WEBHOOK_URL=https://block-bot-proxy.sizning-subdomeningiz.workers.dev/webhook
```

---

### 4-Qadam: Ulanishni Tekshirish va Webhookni Faollashtirish

Reg.ru serveri terminalida (SSH orqali):

1. **Diagnostika o'tkazing:**
   ```bash
   php bin/diagnose.php
   ```
   * Ko'rsatkich: `[4] Telegram Bot API: 🌐 Proksi orqali: https://block-bot-proxy... ✅ Ulandi!` chiqishi kerak.

2. **Webhookni Telegramga o'rnating:**
   ```bash
   php bin/set_webhook.php set
   ```
   * Natija: `✅ Webhook muvaffaqiyatli o'rnatildi!`

3. **Webhook holatini tekshiring:**
   ```bash
   php bin/set_webhook.php info
   ```

---

## 4. Savol-Javoblar (FAQ)

### 1. Fayl yuklash (rasm/video/stiker tahlili) ham Cloudflare orqali ishlaydimi?
**Ha!** Biz yaratgan Worker `/file/bot<TOKEN>/...` so'rovlarini ham qabul qiladi. `TelegramClient::downloadFile()` metodi Cloudflare Worker orqali rasmlarni yuklab oladi va moderatsiyaga uzatadi.

### 2. Kiruvchi xabarlar kechikadimi?
**Yo'q.** Cloudflare dunyo bo'yicha eng tezkor chekka (Edge) serverlarga ega. So'rov o'tish kechikishi (ping) odatda atigi 10-30 ms ni tashkil etadi va inson sezmaydi.

### 3. Agar serverimda SOCKS5 proksi (masalan Shadowsocks yoki Tor) bo'lsachi?
Agar serveringizda shaxsiy SOCKS5 proksi bo'lsa, Worker ochmasdan `.env` fayliga quyidagini yozishingiz ham mumkin:
```env
TELEGRAM_PROXY=socks5h://127.0.0.1:1080
```
Bunda bot cURL orqali to'g'ridan-to'g'ri lokal SOCKS5 proksingizdan foydalanadi.

### 4. Telegram ishlayapti, lekin AI (Google Gemini) "User location is not supported" xatosi berayapti
Bu — Google'ning o'zi, sizning server IP-manzili joylashgan mintaqadan (masalan Rossiya/Belarus)
kelgan so'rovlarni rad etishi. Telegram bilan bog'liq emas, alohida muammo. Yechim: shu bir xil
Worker Gemini va OpenRouter uchun ham proksi bo'la oladi (`/gemini/...` va `/openrouter/...`
yo'llari) — Worker kodini eng so'nggi `deploy/cloudflare-worker/telegram-proxy.js` bilan qayta
deploy qiling (agar eski versiya bo'lsa), so'ng `.env` ga qo'shing:
```env
GEMINI_BASE_URL=https://block-bot-proxy.sizning-subdomeningiz.workers.dev/gemini/v1beta
```
Tekshirish: `php bin/doctor.php` — `[3] AI provider ulanishi` bo'limi buni avtomatik aniqlaydi.
