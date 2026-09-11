/**
 * =============================================================================
 * Block-BOT: Cloudflare Worker Ko'p Tomonlama Proksi (Reverse Proxy)
 * =============================================================================
 *
 * Reg.ru, Roskomnadzor yoki boshqa geografik blokirovkalarni aylanib o'tib,
 * bot serveri bilan tashqi xizmatlar o'rtasidagi aloqani tiklaydi:
 *
 * 1. Kiruvchi (Inbound):  Telegram -> Worker -> Sizning server (Webhook Relay)
 *    Yo'llar: "/", "/webhook", "/webhook.php" (POST) — hammasi bir xil ishlaydi.
 *
 * 2. Chiquvchi (Outbound) — Telegram Bot API:
 *    Yo'l: /bot<TOKEN>/... yoki /file/bot<TOKEN>/...  ->  api.telegram.org
 *
 * 3. Chiquvchi (Outbound) — Google Gemini API:
 *    Yo'l: /gemini/...  ->  generativelanguage.googleapis.com/...
 *    (Google ba'zi mamlakatlar IP manzillaridan so'rovlarni "User location is
 *    not supported" xatosi bilan rad etadi — shu holatda kerak bo'ladi.)
 *
 * 4. Chiquvchi (Outbound) — OpenRouter API:
 *    Yo'l: /openrouter/...  ->  openrouter.ai/api/...
 *
 * 5. Chiquvchi (Outbound) — Google Cloud Vision API (SafeSearch zaxira tekshiruvi):
 *    Yo'l: /vision/...  ->  vision.googleapis.com/...
 *
 * O'rnatish:
 * 1. https://dash.cloudflare.com -> Workers & Pages -> Create Application -> Create Worker
 * 2. Ushbu kodni to'liq nusxalab, Worker muharririga joylang (Deploy bosing).
 * 3. Worker sozlamalarida (Settings -> Variables and Secrets) qo'shing:
 *    - TARGET_WEBHOOK_URL: Serveringizdagi webhook manzili (masalan: https://sizning-domen/webhook.php)
 *    - PROXY_SECRET: Maxfiy kalit (.env dagi TELEGRAM_PROXY_SECRET bilan bir xil bo'lishi shart)
 * 4. .env faylida:
 *    TELEGRAM_WEBHOOK_URL=https://<worker-nomi>.workers.dev/webhook
 *    TELEGRAM_API_BASE_URL=https://<worker-nomi>.workers.dev
 *    TELEGRAM_PROXY_SECRET=<PROXY_SECRET bilan bir xil>
 *    # Faqat Gemini bloklangan bo'lsa kerak:
 *    GEMINI_BASE_URL=https://<worker-nomi>.workers.dev/gemini/v1beta
 *    # Faqat OpenRouter bloklangan bo'lsa kerak:
 *    OPENROUTER_BASE_URL=https://<worker-nomi>.workers.dev/openrouter/v1
 */

const DEFAULT_TARGET_WEBHOOK_URL = ""; // masalan: "https://sizning-domeningiz/webhook.php"
const DEFAULT_PROXY_SECRET = "";       // masalan: "my_super_secret_proxy_key_123"

function checkSecret(request, url, requiredSecret) {
  if (!requiredSecret) return true;
  const clientSecret = request.headers.get("X-Proxy-Secret") || url.searchParams.get("proxy_secret");
  return clientSecret === requiredSecret;
}

function forbidden() {
  return new Response(JSON.stringify({
    ok: false,
    error_code: 403,
    description: "Kirish taqiqlangan: X-Proxy-Secret yaroqsiz"
  }), { status: 403, headers: { "Content-Type": "application/json; charset=utf-8" } });
}

async function relay(request, targetUrl, extraHeaders = {}) {
  const headers = new Headers(request.headers);
  headers.set("Host", new URL(targetUrl).host);
  headers.delete("X-Proxy-Secret");
  for (const [k, v] of Object.entries(extraHeaders)) headers.set(k, v);

  try {
    const upstream = await fetch(targetUrl, {
      method: request.method,
      headers,
      body: (request.method === "GET" || request.method === "HEAD") ? undefined : request.body,
      redirect: "follow"
    });
    const responseHeaders = new Headers(upstream.headers);
    responseHeaders.set("Access-Control-Allow-Origin", "*");
    return new Response(upstream.body, { status: upstream.status, headers: responseHeaders });
  } catch (err) {
    return new Response(JSON.stringify({ ok: false, error_code: 502, description: "Upstream bilan bog'lanishda xato: " + err.message }), {
      status: 502,
      headers: { "Content-Type": "application/json; charset=utf-8" }
    });
  }
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const targetWebhook = (env && env.TARGET_WEBHOOK_URL) || DEFAULT_TARGET_WEBHOOK_URL;
    const requiredSecret = (env && env.PROXY_SECRET) || DEFAULT_PROXY_SECRET;

    // -------------------------------------------------------------------------
    // 1. KIRUVCHI TRAFIK (Inbound Webhook Relay: Telegram -> Server)
    // -------------------------------------------------------------------------
    const isWebhookRequest = request.method === "POST" && (
      url.pathname === "/" || url.pathname === "/webhook" || url.pathname === "/webhook.php"
    );

    if (isWebhookRequest) {
      if (!targetWebhook) {
        return new Response(JSON.stringify({
          ok: false,
          error: "TARGET_WEBHOOK_URL o'rnatilmagan. Worker Settings -> Variables and Secrets."
        }), { status: 500, headers: { "Content-Type": "application/json; charset=utf-8" } });
      }
      return relay(request, targetWebhook, { "X-Forwarded-For": request.headers.get("CF-Connecting-IP") || "" });
    }

    // -------------------------------------------------------------------------
    // 2. CHIQUVCHI — Telegram Bot API (/bot<TOKEN>/... yoki /file/bot<TOKEN>/...)
    // -------------------------------------------------------------------------
    if (url.pathname.startsWith("/bot") || url.pathname.startsWith("/file/bot")) {
      if (!checkSecret(request, url, requiredSecret)) return forbidden();
      const target = new URL(request.url);
      target.protocol = "https:";
      target.hostname = "api.telegram.org";
      target.port = "443";
      const res = await relay(request, target.toString());
      const h = new Headers(res.headers);
      h.set("X-Proxied-By", "Block-BOT-Worker");
      return new Response(res.body, { status: res.status, headers: h });
    }

    // -------------------------------------------------------------------------
    // 3. CHIQUVCHI — Google Gemini API (/gemini/...)
    // -------------------------------------------------------------------------
    if (url.pathname.startsWith("/gemini/") || url.pathname === "/gemini") {
      if (!checkSecret(request, url, requiredSecret)) return forbidden();
      const target = new URL(request.url);
      target.protocol = "https:";
      target.hostname = "generativelanguage.googleapis.com";
      target.port = "443";
      target.pathname = url.pathname.replace(/^\/gemini/, "") || "/";
      return relay(request, target.toString());
    }

    // -------------------------------------------------------------------------
    // 4. CHIQUVCHI — OpenRouter API (/openrouter/...)
    // -------------------------------------------------------------------------
    if (url.pathname.startsWith("/openrouter/") || url.pathname === "/openrouter") {
      if (!checkSecret(request, url, requiredSecret)) return forbidden();
      const target = new URL(request.url);
      target.protocol = "https:";
      target.hostname = "openrouter.ai";
      target.port = "443";
      target.pathname = "/api" + (url.pathname.replace(/^\/openrouter/, "") || "/");
      return relay(request, target.toString());
    }

    // -------------------------------------------------------------------------
    // 5. CHIQUVCHI — Google Cloud Vision API / SafeSearch (/vision/...)
    // -------------------------------------------------------------------------
    if (url.pathname.startsWith("/vision/") || url.pathname === "/vision") {
      if (!checkSecret(request, url, requiredSecret)) return forbidden();
      const target = new URL(request.url);
      target.protocol = "https:";
      target.hostname = "vision.googleapis.com";
      target.port = "443";
      target.pathname = url.pathname.replace(/^\/vision/, "") || "/";
      return relay(request, target.toString());
    }

    // -------------------------------------------------------------------------
    // 6. Asosiy sahifa (Sog'liq tekshiruvi / Health check)
    // -------------------------------------------------------------------------
    return new Response(JSON.stringify({
      status: "ok",
      service: "Block-BOT Multi-Proxy Worker",
      time: new Date().toISOString(),
      usage: {
        inbound_webhook: "https://" + url.host + "/webhook",
        outbound_telegram: "https://" + url.host + "/bot<TOKEN>/getMe",
        outbound_gemini: "https://" + url.host + "/gemini/v1beta/models/<model>:generateContent",
        outbound_openrouter: "https://" + url.host + "/openrouter/v1/chat/completions",
        outbound_vision: "https://" + url.host + "/vision/v1/images:annotate?key=<KEY>"
      }
    }, null, 2), {
      status: 200,
      headers: { "Content-Type": "application/json; charset=utf-8" }
    });
  }
};
