<?php

declare(strict_types=1);

namespace App\Moderation;

use App\AI\AIClientFactory;
use App\AI\OpenRouterClient;
use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

class TextModerator
{
    private OpenRouterClient $openRouter;

    /**
     * Oxirgi `checkUrls()` chaqiruvining qisqa tahlili (2.0 Phase 7):
     * oq ro'yxatda bo'lmagan domen bormi va qisqartiruvchi xizmat ishlatilganmi.
     * Oq ro'yxat hisobini ikki marta qilmaslik uchun shu yerda saqlanadi.
     *
     * @var array{has_unknown: bool, has_shortener: bool}
     */
    private array $lastLinkAnalysis = ['has_unknown' => false, 'has_shortener' => false];

    // Begunoh so'zlar ro'yxati (hech qachon bloklanmaydigan istisnolar)
    private const INNOCENT_EXCEPTIONS = [
        'qoshiq', 'qoshiqcha', 'kuchuk', 'kuchukcha', 'siklamen', 'shart', 'shartnoma',
        'ebat', // agar kontekst bo'lsa yoki istisno
        'ebitda', 'pedagog', 'pediatriya', 'fakultet', 'assotsiatsiya',
        'blyuda', 'blyudtse', 'publika', 'rubl', 'stul', 'shkaf', 'somsa',
    ];

    // Deterministik qat'iy taqiqlangan so'zlar ildizlari (so'z chegarasi bilan)
    private const STRICT_PROFANITY_PATTERNS = [
        // O'zbekcha qattiq haqoratlar
        'jalap', 'onangni', 'oneni', 'opangni', 'singlingni', 'skay', 'sikay', 'sikey',
        'sikish', 'sikaman', 'sikasiz', 'qo\'toq', 'qotoq', 'kutog', 'kotog', 'amcha',
        'isqirt', 'itvachcha', 'haromi', 'gandon', 'dalbayob', 'dalbaeb',
        // Ruscha matlar
        'suka', 'soka', 'cuka', 'blyad', 'blyat', 'xuy', 'huy', 'pizda', 'pizdets', 'yebe', 'yob',
        'ebat', 'ebal', 'mudak', 'chmo', 'zaebal', 'otvali',
        // Inglizcha
        'fuck', 'fucking', 'bitch', 'whore', 'cunt', 'dick', 'asshole', 'bastard',
        // Ochiq pornografik spam va intim xizmat iboralari
        'intim xizmat', 'qizlar zakaz', 'massaj intim', 'seks tanishuv', 'intim qizlar',
        'am sotiladi', 'qizlar nomeri', 'porno', 'porn', 'порно', 'порнуха', 'seks', 'sex', 'sekis',
    ];

    // Shubhali kalit so'zlar (AI tekshiruvini talab qiladigan)
    private const SUSPICIOUS_HINTS = [
        'intim', 'seks', 'sex', 'shirin qiz', '18+', 'homiylik', 'sponsor', 'homiy qidiraman',
        'daromad', 'kuniga 500$', 'investitsiya', 'stavka', '1xbet', 'aviator', 'kripto',
        'ish taklif', 'vakansiya admin', 'karta sotiladi', 'hack', 'baza sotiladi',
        'trading', 'treyding', 'forex', 'signal', 'binance', 'melbet', 'mostbet', 'kazino',
        'apk', 'mod apk', 'crack', 'obuna bo\'ling', 'kanalga qo\'shiling', 'reklama',
        'more of me', 'come closer', 'exclusive content', 'private channel', 'onlyfans', 'nudes',
    ];

    /**
     * Havola qisqartiruvchi xizmatlar (2.0 Phase 7 — fishing himoyasi).
     * Bunday havola oxirgi manzilni BUTUNLAY yashiradi, shuning uchun uni
     * oddiy noma'lum domen kabi "o'tkazib yuborish" mumkin emas — kamida AI
     * tahliliga majburan yuboriladi.
     */
    private const URL_SHORTENERS = [
        'tr.ee', 'bit.ly', 'bitly.com', 'tinyurl.com', 'cutt.ly', 'goo.gl', 'is.gd',
        't.co', 'ow.ly', 'rb.gy', 'rebrand.ly', 'shorturl.at', 'clck.ru', 'vk.cc',
        'surl.li', 'u.to', 'qr.ae', 'lnkd.in', 'buff.ly', 'shorte.st', 'linktr.ee',
        'telegra.ph', 'teletype.in', 'dub.sh', 'short.io', 'tiny.cc', 's.id',
    ];

    /**
     * Matnda ko'rinadigan, lekin aslida BOSHQA manzilga olib boradigan havolani
     * aniqlash uchun domenga o'xshash bo'lakni topuvchi regex (2.0 Phase 7).
     * Masalan ko'rinishi `gov.uz/viplat24sep`, haqiqiy manzili `tr.ee/xxxx`.
     */
    private const DOMAIN_IN_TEXT_PATTERN = '/\b((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:uz|ru|com|net|org|info|biz|site|online|xyz|top|io|co|me|kz|kg|tj|tm|az|ua|by|gov|edu))\b/i';

    /** @var array<int, array{pattern:string, category:string, reason:string}> */
    private const STRICT_POLICY_PATTERNS = [
        ['pattern' => '/(?<![\p{L}\p{N}])(1xbet|melbet|mostbet|pin[\s._-]*up|parimatch|aviator|kazino|casino|bukmeker)(?![\p{L}\p{N}])/ui', 'category' => 'gambling', 'reason' => 'Qimor yoki bukmekerlik targ\'iboti aniqlandi'],
        ['pattern' => '/\b(stavka\s+(qil|qo[\'’]?y)|betting\s+signal|qimor\s+o[\'’]?yna)\b/ui', 'category' => 'gambling', 'reason' => 'Qimor o\'ynashga chaqiriq aniqlandi'],
        ['pattern' => '/\b(forex|trading|treyding|crypto|kripto|binance)\s+(signal|signal(?:lar)?i|kurs|robot|bot)\b/ui', 'category' => 'trading_scam', 'reason' => 'Treyding/kripto signal yoki bot reklamasi aniqlandi'],
        ['pattern' => '/\b(kafolatlangan|garantiyali)\s+(daromad|foyda)|\b(copy\s*trading|binary\s+options?)\b/ui', 'category' => 'trading_scam', 'reason' => 'Shubhali investitsiya yoki treyding taklifi aniqlandi'],
        ['pattern' => '/(?:^|[^\p{L}\p{N}])(?:mod|premium|crack(?:ed)?)?\s*[\w.-]+\.apk(?:$|[^\p{L}\p{N}])/ui', 'category' => 'apk_distribution', 'reason' => 'APK fayl tarqatish havolasi aniqlandi'],
        ['pattern' => '/\b(kanal(?:imiz)?ga\s+(obuna\s+bo[\'’]?ling|qo[\'’]?shiling)|guruh(?:imiz)?ga\s+qo[\'’]?shiling|reklama\s+joylaymiz|reklama\s+uchun\s+(yozing|murojaat))\b/ui', 'category' => 'spam_ad', 'reason' => 'Ruxsatsiz reklama yoki kanalga jalb qilish aniqlandi'],
        ['pattern' => '/\b(more\s+of\s+me|come\s+closer|join\s+my\s+private|exclusive\s+content|private\s+channel|my\s+nudes?|onlyfans|fansly|hot\s+girls?)\b[\s\S]{0,300}(?:https?:\/\/)?t\.me\/(?:\+|joinchat\/|[a-z0-9_]+)/ui', 'category' => 'spam_ad', 'reason' => 'Jinsiy mazmundagi kanal reklama yoki jalb qilish aniqlandi'],
        ['pattern' => '/(?:https?:\/\/)?t\.me\/(?:\+|joinchat\/)[^\s]+[\s\S]{0,300}\b(more\s+of\s+me|come\s+closer|exclusive\s+content|nudes?|18\+)\b/ui', 'category' => 'spam_ad', 'reason' => 'Shubhali Telegram taklif havolasi va jinsiy reklama aniqlandi'],
        // Telegram kanal "story" havolasi (t.me/<kanal>/s/<raqam>) — guruhда deyarli har doim
        // ruxsatsiz reklama/jalb qilish (ko'pincha 18+ kanallar). Oddiy post havolasi (t.me/x/123) bloklanmaydi.
        ['pattern' => '/(?:https?:\/\/)?t\.me\/[A-Za-z][A-Za-z0-9_]{3,31}\/s\/\d+/i', 'category' => 'spam_ad', 'reason' => 'Ruxsatsiz Telegram kanal "story" reklamasi aniqlandi'],
        // "Kino kodi / Kodni botga yuboring" — kontent (ko'pincha 18+) tarqatuvchi bot reklama shabloni.
        // AI Vision ishonchsiz bo'lgan hollarda ham (media tekshirilmasdan) darhol ushlaydi.
        ['pattern' => '/\bkodni\s+botga\s+yubor(?:ing|amiz|dim)?\b|\bkino\s+kodi\s*[:=]?\s*\d+/ui', 'category' => 'spam_ad', 'reason' => "\"Kodni botga yuboring\" — kontent tarqatuvchi bot reklama shabloni aniqlandi"],
        // Bitta @bot/@kanal manzili bir xabarda 3+ marta takrorlansa — deyarli har doim
        // ommaviy reklama flud shabloni (haqiqiy suhbatda bunday takror bo'lmaydi).
        ['pattern' => '/(@[A-Za-z][A-Za-z0-9_]{4,31})(?:[\s\S]{0,80}\1){2,}/u', 'category' => 'spam_ad', 'reason' => "Bitta bot/kanal manzili bir necha marta takrorlangan reklama aniqlandi"],
    ];

    public function __construct(?OpenRouterClient $openRouter = null)
    {
        $this->openRouter = $openRouter ?? AIClientFactory::create();
    }

    /**
     * Matnni to'liq tekshirish
     */
    public function inspect(
        string $rawText,
        array $entities = [],
        string $aiMode = 'economical',
        ?int $chatId = null,
        string $itemId = 'msg_1',
        array $filters = [],
        ?string $customModel = null,
        bool $localOnly = false
    ): array {
        $filters = array_merge([
            'profanity_filter' => true,
            'porn_filter' => true,
            'link_filter' => true,
        ], $filters);
        if (empty(trim($rawText))) {
            return [
                'status' => 'safe',
                'category' => 'none',
                'reason' => 'Bo\'sh xabar',
                'evidence' => '',
                'source' => 'local_rules',
            ];
        }

        $normalized = TextNormalizer::normalize($rawText);
        $collapsed = TextNormalizer::collapsePunctuation($rawText);

        // Reklama, qimor, treyding va APK tarqatish uchun yuqori aniqlikdagi tezkor qoidalar.
        foreach (self::STRICT_POLICY_PATTERNS as $rule) {
            if (preg_match($rule['pattern'], $rawText . ' ' . $normalized, $matches)) {
                return [
                    'status' => 'unsafe',
                    'category' => $rule['category'],
                    'reason' => $rule['reason'],
                    'evidence' => trim((string)($matches[0] ?? '')),
                    'source' => 'local_policy',
                ];
            }
        }

        // 1. Havolalar va domenlarni tekshirish
        $urls = [];
        $hasShortener = false;
        $hasUnknownLink = false;
        if ($filters['link_filter']) {
            // 2.0 Phase 7: avval NIQOBLANGAN havola tekshiruvi — ko'rinadigan
            // matn bir domenni ko'rsatib, haqiqiy havola boshqasiga olib borsa
            // (masalan "gov.uz/..." deb yozilib, aslida "tr.ee/..."), bu deyarli
            // har doim fishing bo'ladi.
            $maskedFinding = $this->checkMaskedLinks($rawText, $entities);
            if ($maskedFinding !== null) {
                return $maskedFinding;
            }

            $urls = TextNormalizer::extractUrls($rawText, $entities);
            $linkFinding = $this->checkUrls($urls, $chatId);
            if ($linkFinding !== null) {
                return $linkFinding;
            }
            // `checkUrls()` oq ro'yxatni allaqachon hisoblab chiqqan — uni qayta
            // so'ramaslik uchun natijasidan foydalanamiz.
            $hasShortener = (bool)($this->lastLinkAnalysis['has_shortener'] ?? false);
            $hasUnknownLink = (bool)($this->lastLinkAnalysis['has_unknown'] ?? false);
        }

        // 2. Mahalliy so'z qoidalarini (DB va o'rnatilgan) tekshirish
        if ($filters['profanity_filter'] || $filters['porn_filter']) {
            $localFinding = $this->checkLocalRules($normalized, $collapsed, $rawText, $chatId);
            if ($localFinding !== null) {
                $categoryEnabled = ($localFinding['category'] === 'pornography')
                    ? (bool)$filters['porn_filter']
                    : (bool)$filters['profanity_filter'];
                if ($categoryEnabled || $localFinding['status'] === 'safe') {
                    return $localFinding;
                }
            }
        }

        // 3. AI rejimi va shubha darajasini tekshirish.
        // 2.0 Phase 7: avval bu shart faqat so'kinish/porno filtrlariga bog'liq
        // edi — ya'ni sof fishing/reklama havolasi bo'lgan xabar "economical"
        // rejimda AI'ga UMUMAN yetib bormasdi. Endi havola filtri yoqilgan
        // bo'lsa, havolali xabar ham AI tahlilига tushadi (qisqartirilgan
        // havola esa har doim shubhali hisoblanadi).
        $needsAi = ($filters['profanity_filter'] || $filters['porn_filter'] || $filters['link_filter'])
            && (($aiMode === 'comprehensive')
                || $this->hasSuspiciousContext($normalized, $collapsed)
                || $hasShortener
                || $hasUnknownLink);

        if (!$needsAi || $aiMode === 'disabled') {
            return [
                'status' => 'safe',
                'category' => 'none',
                'reason' => 'Mahalliy filtrdan muvaffaqiyatli o\'tdi',
                'evidence' => '',
                'source' => 'local_rules',
            ];
        }

        // Mahalliy (sinxron) bosqich: AI kerak, lekin uni webhook bloklamasin.
        // Chaqiruvchi bu natijani ko'rib to'liq tekshiruvni fon navbatiga qo'yadi.
        if ($localOnly) {
            return [
                'status' => 'pending_ai',
                'category' => 'none',
                'reason' => 'Mahalliy filtrdan o\'tdi; AI tahlili fon rejimida bajariladi',
                'evidence' => '',
                'source' => 'local_rules',
            ];
        }

        // 4. Keshni tekshirish (AI xarajatini tejash)
        $provider = $this->openRouter->providerName();
        $cacheKey = 'txt_' . hash('sha256', $provider . '|' . $normalized . '|' . $chatId . '|' . $aiMode . '|' . ($customModel ?? '') . '|' . json_encode($filters));
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        // 5. Tanlangan AI provider orqali tekshirish.
        // 2.0 Phase 7: AI'ga faqat KO'RINADIGAN matn yuborilardi — Telegram
        // `text_link` entity'si ichidagi HAQIQIY manzil esa yashirin qolardi.
        // Natijada "gov.uz/..." deb ko'rsatilgan, aslida qisqartiruvchi xizmatga
        // olib boradigan fishing xabari AI uchun butunlay qonuniy ko'rinardi.
        // Endi haqiqiy havolalar matnga alohida ilova sifatida qo'shiladi.
        $aiText = $rawText;
        if ($urls !== []) {
            $aiText .= "\n\n[Tizim ilovasi — xabardagi HAQIQIY havolalar: " . implode(', ', array_slice($urls, 0, 10)) . "]";
            if ($hasShortener) {
                $aiText .= "\n[Diqqat: havola qisqartiruvchi xizmat ishlatilgan — oxirgi manzil yashirilgan.]";
            }
        }
        $aiResult = $this->openRouter->moderateText($aiText, $itemId, $chatId, $customModel);
        $aiResult['source'] = $provider . '_ai';

        if (in_array(($aiResult['category'] ?? ''), ['pornography', 'adult_profile'], true) && !$filters['porn_filter']) {
            $aiResult = ['status' => 'safe', 'category' => 'none', 'reason' => 'Pornografiya filtri o\'chirilgan', 'evidence' => '', 'source' => 'settings'];
        } elseif (($aiResult['category'] ?? '') === 'profanity' && !$filters['profanity_filter']) {
            $aiResult = ['status' => 'safe', 'category' => 'none', 'reason' => 'So\'kinish filtri o\'chirilgan', 'evidence' => '', 'source' => 'settings'];
        }

        if ($aiResult['status'] === 'safe' || $aiResult['status'] === 'unsafe') {
            $this->saveToCache($cacheKey, 'text', $aiResult, 86400); // 24 soat kesh
        }

        return $aiResult;
    }

    /**
     * Havolalarni taqiqlangan/ruxsat etilgan domenlar ro'yxatiga solishtirish
     */
    /**
     * NIQOBLANGAN HAVOLA tekshiruvi (2.0 Phase 7 — fishing himoyasi).
     *
     * Telegram'da havolani istalgan matn ostiga yashirish mumkin (`text_link`
     * entity'si): foydalanuvchi `gov.uz/viplat24sep` deb yozilganini ko'radi,
     * bosganda esa `tr.ee/Iv0aPh` ga tushadi. Davlat saytlari, banklar va
     * to'lov tizimlari nomidan qilinadigan firibgarliklar deyarli har doim
     * shu usulda ishlaydi.
     *
     * Qoida: ko'rinadigan matnda domenga o'xshash bo'lak bo'lsa va u haqiqiy
     * havolaning hostiga MOS KELMASA — bu niqoblangan havola.
     */
    private function checkMaskedLinks(string $text, array $entities): ?array
    {
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') !== 'text_link' || empty($entity['url'])) {
                continue;
            }

            $visible = mb_substr($text, (int)($entity['offset'] ?? 0), (int)($entity['length'] ?? 0), 'UTF-8');
            if (!preg_match(self::DOMAIN_IN_TEXT_PATTERN, $visible, $m)) {
                // Ko'rinadigan matnda domen umuman yo'q ("Batafsil" kabi) —
                // bu normal holat, niqoblash deb hisoblanmaydi.
                continue;
            }

            $claimedHost = $this->registrableHost(strtolower($m[1]));
            $actualHost = $this->registrableHost(strtolower((string)(parse_url((string)$entity['url'], PHP_URL_HOST) ?? '')));

            if ($claimedHost === '' || $actualHost === '' || $claimedHost === $actualHost) {
                continue;
            }

            return [
                'status' => 'unsafe',
                'category' => 'malicious_link',
                'reason' => "Niqoblangan havola: matnda \"{$claimedHost}\" ko'rsatilgan, "
                    . "lekin havola aslida \"{$actualHost}\" manziliga olib boradi (fishing belgisi)",
                'evidence' => trim($visible) . ' → ' . (string)$entity['url'],
                'source' => 'link_filter_masked',
            ];
        }

        return null;
    }

    /**
     * Hostni solishtirish uchun normallashtirish: "www." prefiksi olib
     * tashlanadi. Subdomenlar ataylab saqlanadi — "gov.uz" va
     * "gov.uz.scam-site.xyz" bir xil deb hisoblanmasligi kerak.
     */
    private function registrableHost(string $host): string
    {
        $host = trim($host, " \t\n\r\0\x0B.");
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function checkUrls(array $urls, ?int $chatId): ?array
    {
        $this->lastLinkAnalysis = ['has_unknown' => false, 'has_shortener' => false];

        if (empty($urls)) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT domain, rule_type
            FROM domain_rules
            WHERE chat_id IS NULL OR chat_id = :chat_id
        ");
        $stmt->execute(['chat_id' => $chatId]);
        $dbRules = $stmt->fetchAll();

        $blacklist = [];
        $whitelist = ['t.me', 'telegram.org', 'google.com', 'yandex.uz', 'uzum.uz', 'kun.uz', 'daryo.uz', 'wikipedia.org'];

        foreach ($dbRules as $r) {
            if ($r['rule_type'] === 'blacklist') {
                $blacklist[] = strtolower($r['domain']);
            } elseif ($r['rule_type'] === 'whitelist') {
                $whitelist[] = strtolower($r['domain']);
            }
        }

        foreach ($urls as $url) {
            $parsed = parse_url($url);
            $host = strtolower($parsed['host'] ?? '');

            if (empty($host)) {
                continue;
            }

            // SSRF himoyasi: mahalliy yoki ichki tarmoq manzillarini tekshirish
            if ($this->isInternalOrPrivateHost($host)) {
                return [
                    'status' => 'unsafe',
                    'category' => 'malicious_link',
                    'reason' => "Taqiqlangan ichki tarmoq yoki havola: {$host}",
                    'evidence' => $url,
                    'source' => 'link_filter',
                ];
            }

            $isWhitelisted = false;
            foreach ($whitelist as $allowedDomain) {
                if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                    $isWhitelisted = true;
                    break;
                }
            }
            if ($isWhitelisted) {
                continue;
            }

            // 2.0 Phase 7: oq ro'yxatda bo'lmagan domen — AI tahliliga arziydi
            // (avval bunday havola qora ro'yxatda bo'lmasa, jim o'tkazib
            // yuborilardi va "economical" rejimda AI umuman chaqirilmasdi).
            $this->lastLinkAnalysis['has_unknown'] = true;
            if (in_array($this->registrableHost($host), self::URL_SHORTENERS, true)) {
                $this->lastLinkAnalysis['has_shortener'] = true;
            }

            // Qora ro'yxatdagi domenlar
            foreach ($blacklist as $badDomain) {
                if ($host === $badDomain || str_ends_with($host, '.' . $badDomain)) {
                    return [
                        'status' => 'unsafe',
                        'category' => 'malicious_link',
                        'reason' => "Taqiqlangan domen aniqlandi: {$host}",
                        'evidence' => $url,
                        'source' => 'link_filter',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Mahalliy deterministik qoidalar bo'yicha tekshirish
     */
    private function checkLocalRules(string $normalized, string $collapsed, string $rawText, ?int $chatId): ?array
    {
        // 1. Begunoh so'zlarni tekshirish (istisnolar)
        // Agar butun matn begunoh so'zdan iborat bo'lsa, xavfsiz
        foreach (self::INNOCENT_EXCEPTIONS as $innocent) {
            if (trim($normalized) === $innocent) {
                return [
                    'status' => 'safe',
                    'category' => 'none',
                    'reason' => "Begunoh so'z istisnosi: {$innocent}",
                    'evidence' => '',
                    'source' => 'local_rules',
                ];
            }
        }

        // 2. Guruh va global maxsus qoidalarni DB'dan olish.
        // Oq ro'yxat qoidalari qora ro'yxatdan oldin tekshiriladi (istisno ustunligi).
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT word_pattern, rule_type, is_regex
            FROM word_rules
            WHERE chat_id IS NULL OR chat_id = :chat_id
            ORDER BY CASE WHEN rule_type = 'whitelist' THEN 0 ELSE 1 END
        ");
        $stmt->execute(['chat_id' => $chatId]);
        $customRules = $stmt->fetchAll();

        foreach ($customRules as $cr) {
            $pat = (string)$cr['word_pattern'];
            if ($pat === '') {
                continue;
            }

            $matched = false;
            if (!empty($cr['is_regex'])) {
                $matched = @preg_match($pat, $normalized) === 1 || @preg_match($pat, $collapsed) === 1;
            } else {
                // Regexsiz literal qoida: so'z chegarasi bilan (harflar ichida emas).
                $literal = '/(?<!\p{L})' . preg_quote(TextNormalizer::normalize($pat), '/') . '(?!\p{L})/u';
                $matched = preg_match($literal, $normalized) === 1 || preg_match($literal, $collapsed) === 1;
            }

            if (!$matched) {
                continue;
            }

            if ($cr['rule_type'] === 'whitelist') {
                return [
                    'status' => 'safe',
                    'category' => 'none',
                    'reason' => "Maxsus oq ro'yxat qoidasi",
                    'evidence' => '',
                    'source' => 'local_rules',
                ];
            }

            return [
                'status' => 'unsafe',
                'category' => 'profanity',
                'reason' => "Maxsus taqiqlangan ibora aniqlandi",
                'evidence' => $pat,
                'source' => 'local_rules',
            ];
        }

        // 3. Standart taqiqlangan so'zlar (so'z chegarasi bilan tekshirish)
        foreach (self::STRICT_PROFANITY_PATTERNS as $badWord) {
            // So'z chegarasi bilan regex: \b yoki belgilar orasida
            $escaped = preg_quote($badWord, '/');
            $pattern = '/(?<!\p{L})' . $escaped . '(?!\p{L})/u';

            // Oddiy normalizatsiyalangan matnda tekshirish
            if (preg_match($pattern, $normalized, $matches)) {
                // Agar begunoh so'z ichida bo'lsa, istisno qilish
                if ($this->isPartOrWhitelistedInnocent($normalized, $badWord)) {
                    continue;
                }

                $isPornWord = in_array($badWord, [
                    'intim xizmat', 'qizlar zakaz', 'massaj intim', 'seks tanishuv',
                    'intim qizlar', 'am sotiladi', 'qizlar nomeri', 'porno', 'porn', 'порно', 'порнуха', 'seks', 'sex', 'sekis'
                ], true);
                $category = $isPornWord ? 'pornography' : 'profanity';
                return [
                    'status' => 'unsafe',
                    'category' => $category,
                    'reason' => $isPornWord ? "Pornografik yoki intim ibora aniqlandi: {$badWord}" : "Taqiqlangan so'kinish yoki haqorat aniqlandi: {$badWord}",
                    'evidence' => $matches[0],
                    'source' => 'local_rules',
                ];
            }

            // Niqoblangan (harflar orasiga belgi qo'yilgan) matnda tekshirish
            if (preg_match($pattern, $collapsed, $matches)) {
                if ($this->isPartOrWhitelistedInnocent($collapsed, $badWord)) {
                    continue;
                }

                $isPornWord = in_array($badWord, [
                    'intim xizmat', 'qizlar zakaz', 'massaj intim', 'seks tanishuv',
                    'intim qizlar', 'am sotiladi', 'qizlar nomeri', 'porno', 'porn', 'порно', 'порнуха', 'seks', 'sex', 'sekis'
                ], true);
                $category = $isPornWord ? 'pornography' : 'profanity';
                return [
                    'status' => 'unsafe',
                    'category' => $category,
                    'reason' => $isPornWord ? "Niqoblangan pornografik ibora aniqlandi: {$badWord}" : "Niqoblangan so'kinish aniqlandi: {$badWord}",
                    'evidence' => $matches[0],
                    'source' => 'local_rules',
                ];
            }
        }

        return null;
    }

    private function isPartOrWhitelistedInnocent(string $text, string $badWord): bool
    {
        foreach (self::INNOCENT_EXCEPTIONS as $innocent) {
            if (str_contains($text, $innocent) && str_contains($innocent, $badWord)) {
                return true;
            }
        }
        return false;
    }

    private function hasSuspiciousContext(string $normalized, string $collapsed): bool
    {
        foreach (self::SUSPICIOUS_HINTS as $hint) {
            if (str_contains($normalized, $hint) || str_contains($collapsed, $hint)) {
                return true;
            }
        }
        return false;
    }

    private function isInternalOrPrivateHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        // Cloud metadata IP (AWS/GCP/DigitalOcean metadata)
        if ($host === '169.254.169.254') {
            return true;
        }

        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return false;
    }

    private function getFromCache(string $cacheKey): ?array
    {
        try {
            $pdo = Database::getConnection();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                SELECT result_json FROM moderation_cache
                WHERE cache_key = :key AND expires_at > :now
            ");
            $stmt->execute(['key' => $cacheKey, 'now' => $now]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['result_json'], true) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function saveToCache(string $cacheKey, string $itemType, array $result, int $ttlSeconds = 86400): void
    {
        try {
            $pdo = Database::getConnection();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $pdo->prepare("
                REPLACE INTO moderation_cache (cache_key, item_type, result_json, expires_at)
                VALUES (:key, :type, :json, :exp)
            ");
            $stmt->execute([
                'key' => $cacheKey,
                'type' => $itemType,
                'json' => $json,
                'exp' => $expiresAt,
            ]);
        } catch (Throwable) {
            // Kesh xatosi asosiy oqimni to'xtatmasligi kerak
        }
    }
}
