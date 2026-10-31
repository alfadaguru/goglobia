<?php
// app/lib/support/campaigns.php
// Customer Support module — Phase 4: ad-campaign registry + attribution +
// conversion engine. Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md
// (§7 LOCKED owner decisions for P4).
//
// What this provides:
//   - ensureAdCampaignSchema()           : self-healing schema (gia_ad_campaigns
//                                           + gia_conversations.campaign_id).
//   - ad_campaign_capture_from_request() : PURE read of ad/utm params + referer.
//   - ad_campaign_resolve()              : captured params -> best ACTIVE campaign.
//   - ad_campaign_stamp_conversation()   : first-touch attribution (no overwrite).
//   - ad_campaign_system_note()          : the campaign-aware note GIA receives.
//   - ad_campaign_conversion_report()    : per-campaign funnel (ad->chat->held->paid).
//
// LOCKED owner decisions (P4):
//   - Platforms: Meta, TikTok, Google (enum meta/tiktok/google/other).
//   - A visitor arriving from a registered ad -> GIA knows the campaign/offer and
//     answers campaign-aware.
//   - Conversion = BOTH stages: ad -> chat -> HELD booking -> PAID booking,
//     tracked per campaign.
//
// All functions are global (namespace-free) + function_exists-guarded so this
// file is safe to require more than once (config.php + route files).
// NOTE: this is a LIBRARY (loaded from config.php during bootstrap, BEFORE
// $SECURE is set), so it must NOT carry the `@$SECURE or die` route/view guard —
// doing so kills the whole app at boot (the P1/P2 boot bug). No app/lib file
// carries it. Everything here is FAIL-SOFT: a DB error must never break the chat.

if (!function_exists('ensureAdCampaignSchema')) {
    /**
     * Idempotent self-healing schema for the ad-campaign registry (§7, P4).
     * Mirrors ensureSupportRbacSchema: CREATE TABLE IF NOT EXISTS wrapped in
     * try/catch with error_log, guarded by a request-static so the SHOW/CREATE
     * runs at most once per request. ALSO adds gia_conversations.campaign_id
     * (SHOW COLUMNS + ALTER) — CREATE TABLE IF NOT EXISTS never alters an
     * existing table, so the attribution column needs its own idempotent ADD.
     */
    function ensureAdCampaignSchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            // --- gia_ad_campaigns: one row per registered ad campaign / offer ------
            // platform is the LOCKED enum (meta/tiktok/google/other). match_key is
            // the value we match an incoming visit on — usually utm_campaign or a
            // short code the ad's landing URL carries. offer_product scopes the
            // offer to one service so GIA prioritises matching inventory.
            $db->query("CREATE TABLE IF NOT EXISTS `gia_ad_campaigns` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `platform` ENUM('meta','tiktok','google','other') NOT NULL DEFAULT 'other',
                `name` VARCHAR(191) NOT NULL,
                `offer_intent` VARCHAR(255) DEFAULT NULL,
                `offer_product` VARCHAR(50) DEFAULT NULL,
                `utm_source` VARCHAR(100) DEFAULT NULL,
                `utm_campaign` VARCHAR(100) DEFAULT NULL,
                `utm_medium` VARCHAR(50) DEFAULT NULL,
                `match_key` VARCHAR(120) DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `starts_at` DATETIME DEFAULT NULL,
                `ends_at` DATETIME DEFAULT NULL,
                `created_by` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_active` (`active`),
                KEY `idx_match` (`match_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // --- match_keyword: the word an ad tells people to send on a
            // click-to-WhatsApp / messaging ad (e.g. "UMRAHDEC"). Lets the channel
            // path attribute a lead with no cookie/UTM. Idempotent ADD (older installs
            // created the table above without it). ------------------------------------
            try {
                $exists = $db->query("SHOW COLUMNS FROM `gia_ad_campaigns` LIKE 'match_keyword'")->fetch();
                if (!$exists) {
                    $db->query("ALTER TABLE `gia_ad_campaigns` ADD COLUMN `match_keyword` VARCHAR(60) DEFAULT NULL AFTER `match_key`");
                    try { $db->query("ALTER TABLE `gia_ad_campaigns` ADD KEY `idx_keyword` (`match_keyword`)"); }
                    catch (\Throwable $e) { /* index may exist; swallow */ }
                }
            } catch (\Throwable $e) {
                error_log('ensureAdCampaignSchema(match_keyword): ' . $e->getMessage());
            }

            // --- scope_route: optional per-route targeting for a FLIGHTS campaign ---
            // Canonical directional ORIG-DEST IATA (e.g. 'LOS-ABV'). Blank = all flights
            // (today's behaviour). Lets an owner run a route-specific ad and get its own
            // attribution; per-route conversion stats work even WITHOUT a scope_route row
            // (every flight lead stamps gia_conversations.route below). -------------------
            try {
                $exists = $db->query("SHOW COLUMNS FROM `gia_ad_campaigns` LIKE 'scope_route'")->fetch();
                if (!$exists) {
                    $db->query("ALTER TABLE `gia_ad_campaigns` ADD COLUMN `scope_route` VARCHAR(20) DEFAULT NULL AFTER `offer_product`");
                    try { $db->query("ALTER TABLE `gia_ad_campaigns` ADD KEY `idx_route` (`scope_route`)"); }
                    catch (\Throwable $e) { /* index may exist; swallow */ }
                }
            } catch (\Throwable $e) {
                error_log('ensureAdCampaignSchema(scope_route): ' . $e->getMessage());
            }

            // --- gia_conversations.route: the route the lead actually searched ----------
            // Stamped at flights_search time (first-search wins). Powers the per-route
            // conversion breakdown with zero setup, independent of scope_route campaigns.
            try {
                $exists = $db->query("SHOW COLUMNS FROM `gia_conversations` LIKE 'route'")->fetch();
                if (!$exists) {
                    $db->query("ALTER TABLE `gia_conversations` ADD COLUMN `route` VARCHAR(20) DEFAULT NULL");
                    try { $db->query("ALTER TABLE `gia_conversations` ADD KEY `idx_route` (`route`)"); }
                    catch (\Throwable $e) { /* index may exist; swallow */ }
                }
            } catch (\Throwable $e) {
                error_log('ensureAdCampaignSchema(conv.route): ' . $e->getMessage());
            }

            // --- gia_conversations.campaign_id: first-touch attribution anchor -----
            // Idempotent ADD (CREATE TABLE IF NOT EXISTS above left existing
            // installs untouched). The chat route stamps this once per conv.
            try {
                $exists = $db->query("SHOW COLUMNS FROM `gia_conversations` LIKE 'campaign_id'")->fetch();
                if (!$exists) {
                    $db->query("ALTER TABLE `gia_conversations` ADD COLUMN `campaign_id` INT(11) DEFAULT NULL");
                    // Best-effort index for the conversion report's group-by.
                    try {
                        $db->query("ALTER TABLE `gia_conversations` ADD KEY `idx_campaign` (`campaign_id`)");
                    } catch (\Throwable $e) { /* index may already exist; swallow */ }
                }
            } catch (\Throwable $e) {
                error_log('ensureAdCampaignSchema(campaign_id): ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('ensureAdCampaignSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ad_campaign_capture_from_request')) {
    /**
     * PURE read of the ad-attribution signals present on THIS request. Reads
     * $_GET (utm_source, utm_campaign, utm_medium, fbclid, gclid, ttclid) and
     * $_SERVER['HTTP_REFERER']. Returns a normalised assoc array of ONLY the keys
     * that are actually present + non-empty (empty array if none). Does NOT write
     * a cookie — capture-to-cookie belongs with gia_browser_id() (gia_crm.php),
     * the natural landing-time companion.
     *
     * @return array{utm_source?:string,utm_campaign?:string,utm_medium?:string,
     *               fbclid?:string,gclid?:string,ttclid?:string,referer?:string}
     */
    function ad_campaign_capture_from_request(): array
    {
        $out = [];

        $get = static function (string $k): string {
            $v = $_GET[$k] ?? '';
            if (!is_string($v)) {
                return '';
            }
            // Trim + clamp to the column widths; strip control chars defensively.
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $v));
            return mb_substr($v, 0, 191);
        };

        foreach (['utm_source', 'utm_campaign', 'utm_medium', 'fbclid', 'gclid', 'ttclid'] as $k) {
            $v = $get($k);
            if ($v !== '') {
                $out[$k] = $v;
            }
        }

        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (is_string($ref) && $ref !== '') {
            $out['referer'] = mb_substr(trim($ref), 0, 255);
        }

        return $out;
    }
}

if (!function_exists('ad_campaign_resolve')) {
    /**
     * Resolve captured params to the single best-matching ACTIVE campaign, or
     * null. Match priority (first hit wins):
     *   1) exact utm_campaign == gia_ad_campaigns.match_key
     *   2) utm_source == gia_ad_campaigns.utm_source
     *   3) platform inferred from a click id (fbclid->meta, gclid->google,
     *      ttclid->tiktok) -> a generic active campaign for that platform.
     * Every candidate query already filters active=1 and the starts_at/ends_at
     * window (NULL bound = open), so an expired/not-yet-live campaign never
     * resolves. Returns the full campaign row.
     *
     * @param object $db
     * @param array  $params  output of ad_campaign_capture_from_request()
     */
    function ad_campaign_resolve($db, array $params): ?array
    {
        if (empty($params)) {
            return null;
        }
        ensureAdCampaignSchema($db);

        // Shared active + time-window predicate. MySQL evaluates these against the
        // current server time; NULL starts_at/ends_at mean "no lower/upper bound".
        $window = [
            'active' => 1,
            'OR #window_start' => [
                'starts_at' => null,
                'starts_at[<=]' => date('Y-m-d H:i:s'),
            ],
            'OR #window_end' => [
                'ends_at' => null,
                'ends_at[>=]' => date('Y-m-d H:i:s'),
            ],
        ];

        $pick = static function (array $extra) use ($db, $window): ?array {
            try {
                $row = $db->get('gia_ad_campaigns', '*', array_merge($extra, $window, [
                    'ORDER' => ['id' => 'DESC'],
                ]));
                return is_array($row) ? $row : null;
            } catch (\Throwable $e) {
                error_log('ad_campaign_resolve(pick): ' . $e->getMessage());
                return null;
            }
        };

        // 1) Exact utm_campaign == match_key (the strongest signal).
        if (!empty($params['utm_campaign'])) {
            $row = $pick(['match_key' => $params['utm_campaign']]);
            if ($row) {
                return $row;
            }
        }

        // 2) utm_source match.
        if (!empty($params['utm_source'])) {
            $row = $pick(['utm_source' => $params['utm_source']]);
            if ($row) {
                return $row;
            }
        }

        // 3) Platform inferred from a click id -> a generic active campaign on
        //    that platform (e.g. a brand/always-on campaign with no utm set).
        $platform = null;
        if (!empty($params['fbclid'])) {
            $platform = 'meta';
        } elseif (!empty($params['gclid'])) {
            $platform = 'google';
        } elseif (!empty($params['ttclid'])) {
            $platform = 'tiktok';
        }
        if ($platform !== null) {
            $row = $pick(['platform' => $platform]);
            if ($row) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('ad_campaign_resolve_channel')) {
    /**
     * Resolve a campaign for a MESSAGING-channel lead (WhatsApp/Telegram/Messenger),
     * which carries NO cookie/UTM. Match priority (first hit wins):
     *   1) a campaign's match_keyword appears as a whole word in the first inbound
     *      message (case-insensitive) — the ad tells people to send e.g. "UMRAHDEC";
     *   2) click-to-WhatsApp referral: source_id / ctwa_clid / headline matched against
     *      match_key or name;
     *   3) referral source_url parsed for utm params -> handed to ad_campaign_resolve().
     * All candidates still pass the active + time-window filter (via the shared pick
     * inside ad_campaign_resolve / direct queries here). Fail-soft -> null.
     *
     * @param object      $db
     * @param array       $referral  normalized CTWA referral (source_url/source_id/
     *                               ctwa_clid/headline) or [] if none
     * @param string      $firstText the first inbound message text (for keyword match)
     */
    function ad_campaign_resolve_channel($db, array $referral, string $firstText): ?array
    {
        ensureAdCampaignSchema($db);

        $window = [
            'active' => 1,
            'OR #window_start' => ['starts_at' => null, 'starts_at[<=]' => date('Y-m-d H:i:s')],
            'OR #window_end'   => ['ends_at' => null, 'ends_at[>=]' => date('Y-m-d H:i:s')],
        ];

        // 1) Keyword in the first message. Only consider campaigns that HAVE a keyword,
        //    then test each as a whole-word, case-insensitive hit in the text (so a
        //    campaign "DEC" never matches the word "december" accidentally).
        $txt = ' ' . strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $firstText)) . ' ';
        if (trim($txt) !== '') {
            try {
                $kw = $db->select('gia_ad_campaigns', '*', array_merge(
                    ['match_keyword[!]' => null],
                    $window,
                    ['ORDER' => ['id' => 'DESC']]
                )) ?: [];
                foreach ($kw as $c) {
                    $k = strtolower(trim((string) ($c['match_keyword'] ?? '')));
                    if ($k !== '' && strpos($txt, ' ' . $k . ' ') !== false) {
                        return $c;
                    }
                }
            } catch (\Throwable $e) { error_log('ad_campaign_resolve_channel(kw): ' . $e->getMessage()); }
        }

        // 2) CTWA referral source_id / ctwa_clid / headline -> match_key or name.
        $refId    = trim((string) ($referral['source_id'] ?? ($referral['ctwa_clid'] ?? '')));
        $headline = trim((string) ($referral['headline'] ?? ''));
        $pick = static function (array $extra) use ($db, $window): ?array {
            try {
                $row = $db->get('gia_ad_campaigns', '*', array_merge($extra, $window, ['ORDER' => ['id' => 'DESC']]));
                return is_array($row) ? $row : null;
            } catch (\Throwable $e) { error_log('ad_campaign_resolve_channel(pick): ' . $e->getMessage()); return null; }
        };
        if ($refId !== '')    { $row = $pick(['match_key' => $refId]);    if ($row) { return $row; } }
        if ($headline !== '') { $row = $pick(['name' => $headline]);      if ($row) { return $row; } }

        // 3) referral source_url -> parse utm params -> existing resolver.
        $srcUrl = trim((string) ($referral['source_url'] ?? ''));
        if ($srcUrl !== '') {
            $qs = (string) (parse_url($srcUrl, PHP_URL_QUERY) ?: '');
            if ($qs !== '') {
                parse_str($qs, $q);
                $params = [];
                foreach (['utm_source', 'utm_campaign', 'utm_medium', 'fbclid', 'gclid', 'ttclid'] as $k) {
                    if (!empty($q[$k]) && is_string($q[$k])) { $params[$k] = mb_substr(trim($q[$k]), 0, 191); }
                }
                if ($params) {
                    $row = ad_campaign_resolve($db, $params);
                    if ($row) { return $row; }
                }
            }
        }

        return null;
    }
}

if (!function_exists('ad_campaign_route_from_flight_result')) {
    /**
     * Derive the canonical DIRECTIONAL route key 'ORIG-DEST' (uppercase IATA, e.g.
     * 'KAN-ABV') from a flights_search tool run, or '' if it can't be determined.
     * Prefers the RESOLVED airport codes on the first offer's outbound segment (what
     * actually flies); falls back to the tool INPUT origin/destination when they are
     * already 3-letter codes. Multi-city (segments[] input) returns '' — no single
     * corridor to attribute. Pure; no DB. $input = the tool call input, $output = its
     * result (offers[]).
     */
    function ad_campaign_route_from_flight_result(array $input, array $output): string
    {
        // Multi-city: the model sent a 'segments' array instead of origin/destination.
        if (!empty($input['segments']) && is_array($input['segments'])) {
            return '';
        }
        $code = static function ($v): string {
            $v = strtoupper(trim((string) $v));
            return preg_match('/^[A-Z]{3}$/', $v) ? $v : '';
        };

        // 1) Resolved codes from the first offer's outbound segment (most accurate).
        $offers = (isset($output['offers']) && is_array($output['offers'])) ? $output['offers']
                : ((isset($output['flights']) && is_array($output['flights'])) ? $output['flights'] : []);
        foreach ($offers as $o) {
            if (!is_array($o)) { continue; }
            // Try common shapes: flat departure_code/arrival_code, or nested first segment.
            $seg = $o;
            if (isset($o['segments'][0]) && is_array($o['segments'][0])) { $seg = $o['segments'][0]; }
            elseif (isset($o['routes'][0]['segments'][0]) && is_array($o['routes'][0]['segments'][0])) { $seg = $o['routes'][0]['segments'][0]; }
            $dep = $code($seg['departure_code'] ?? ($seg['departure_airport'] ?? ($seg['origin'] ?? ($seg['from'] ?? ''))));
            $arr = $code($seg['arrival_code'] ?? ($seg['arrival_airport'] ?? ($seg['destination'] ?? ($seg['to'] ?? ''))));
            if ($dep !== '' && $arr !== '') { return $dep . '-' . $arr; }
            break; // only inspect the first offer
        }

        // 2) Fall back to the tool input when it's already IATA codes.
        $o = $code($input['origin'] ?? '');
        $d = $code($input['destination'] ?? '');
        if ($o !== '' && $d !== '') { return $o . '-' . $d; }

        return '';
    }
}

if (!function_exists('ad_campaign_resolve_route')) {
    /**
     * Resolve the best ACTIVE flights campaign whose scope_route EXACTLY matches the
     * searched route (DIRECTIONAL — 'LOS-ABV' does NOT match 'ABV-LOS', owner decision).
     * Used as a SECOND-STAGE attribution at flights_search time (the route isn't known
     * at first-turn). Returns the campaign row or null. Respects the active/time-window.
     */
    function ad_campaign_resolve_route($db, string $route): ?array
    {
        $route = strtoupper(trim($route));
        if (!preg_match('/^[A-Z]{3}-[A-Z]{3}$/', $route)) { return null; }
        ensureAdCampaignSchema($db);
        try {
            $row = $db->get('gia_ad_campaigns', '*', [
                'scope_route' => $route,
                'active' => 1,
                'OR #window_start' => ['starts_at' => null, 'starts_at[<=]' => date('Y-m-d H:i:s')],
                'OR #window_end'   => ['ends_at' => null, 'ends_at[>=]' => date('Y-m-d H:i:s')],
                'ORDER' => ['id' => 'DESC'],
            ]);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            error_log('ad_campaign_resolve_route: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('ad_campaign_stamp_conversation')) {
    /**
     * First-touch attribution: set gia_conversations.campaign_id = $campaignId
     * ONLY when the conversation has no campaign yet. A prior attribution is
     * never overwritten (the WHERE campaign_id IS NULL / 0 guard makes this
     * atomic — a concurrent request cannot double-stamp). Fail-soft.
     */
    function ad_campaign_stamp_conversation($db, int $convId, int $campaignId): void
    {
        if ($convId <= 0 || $campaignId <= 0) {
            return;
        }
        ensureAdCampaignSchema($db);
        try {
            $db->update('gia_conversations', ['campaign_id' => $campaignId], [
                'id' => $convId,
                // Only stamp when unattributed. Medoo OR over NULL + 0.
                'OR' => ['campaign_id' => null, 'campaign_id[=]' => 0],
            ]);
        } catch (\Throwable $e) {
            error_log('ad_campaign_stamp_conversation: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ad_campaign_system_note')) {
    /**
     * Build the short, safe addSystemNote text GIA receives when the current
     * conversation is attributed to a campaign. Kept deliberately terse and
     * CONTAINED: it tells GIA the offer context + the one product to prioritise,
     * and explicitly forbids inventing offer details beyond what's stated — so
     * the model cannot hallucinate a fake deal (the containment lesson).
     * All values are coerced to plain strings; empty fields degrade gracefully.
     */
    function ad_campaign_system_note(array $campaign): string
    {
        $platformMap = [
            'meta'   => 'Meta',
            'tiktok' => 'TikTok',
            'google' => 'Google',
            'other'  => 'online',
        ];
        $platform = $platformMap[(string) ($campaign['platform'] ?? 'other')] ?? 'online';
        $name     = trim((string) ($campaign['name'] ?? ''));
        $intent   = trim((string) ($campaign['offer_intent'] ?? ''));
        $product  = trim((string) ($campaign['offer_product'] ?? ''));

        $note = 'The customer arrived from our ' . $platform . ' ad campaign';
        if ($name !== '') {
            $note .= ' "' . $name . '"';
        }
        if ($intent !== '') {
            $note .= ' about ' . $intent;
        }
        if ($product !== '') {
            $note .= ' (' . $product . ')';
        }
        $note .= '. Acknowledge this naturally';
        if ($product !== '') {
            $note .= ' and prioritise ' . $product . ' options that match the offer';
        }
        $note .= '. Never invent offer details, prices, or discounts beyond what is stated here.';

        return $note;
    }
}

if (!function_exists('ad_campaign_conversion_report')) {
    /**
     * Per-campaign conversion funnel over conversations whose started_at falls in
     * [$from, $to] (inclusive date-time strings, 'Y-m-d H:i:s'). Returns one row
     * per campaign_id plus a "(none)" bucket for unattributed conversations,
     * sorted by conversations DESC.
     *
     * COUNTING METHOD (the exact proxies used — documented per the task):
     *   - conversations : COUNT(*) of gia_conversations in the window with that
     *                     campaign_id (NULL grouped into the "(none)" bucket).
     *   - held_bookings : COUNT of those conversations with booking_count > 0.
     *                     GIA stamps gia_conversations.booking_count when it
     *                     creates a HELD (unpaid) booking, so booking_count>0 is
     *                     the cheap, reliable "ad -> chat -> held" proxy. (One
     *                     conv may hold >1 booking but we count the CONVERSATION,
     *                     which is the funnel-stage unit we want.)
     *   - paid_bookings : COUNT of bookings rows linked to one of that bucket's
     *                     conv_keys via booking_data LIKE '%"gia_conv_key":"<key>"%',
     *                     with module_type IN ('ai_trip','umrah') AND
     *                     payment_status = 'paid'. This is the precise paid-stage
     *                     measure (GIA stamps booking_data.gia_conv_key on the
     *                     booking it creates). Done as a per-key Medoo count loop
     *                     (bounded by the conv count in the window) rather than a
     *                     JSON join, which Medoo cannot express portably.
     *   - revenue       : SUM(gia_conversations.revenue) over the bucket. GIA
     *                     stamps per-conversation revenue + revenue_currency when a
     *                     booking is PAID, so this is the currency-consistent
     *                     realised revenue for the funnel (paid stage). The
     *                     dominant revenue_currency in the bucket is returned as
     *                     'revenue_currency' (mixed-currency buckets are rare;
     *                     this is reporting, not accounting).
     *   - conversion_rate = held_bookings / conversations (0.0 when no convs).
     *
     * Fail-soft: any query error returns [] (never throws into the caller).
     *
     * @param object $db
     * @param string $from 'Y-m-d H:i:s'
     * @param string $to   'Y-m-d H:i:s'
     * @return array<int,array<string,mixed>>
     */
    function ad_campaign_conversion_report($db, string $from, string $to): array
    {
        ensureAdCampaignSchema($db);

        try {
            // Pull every conversation in the window with just the fields the funnel
            // needs. Bounded by the date window; this is an admin report, not a
            // hot path. One pass, then aggregate in PHP so the NULL/(none) bucket
            // and the per-key paid count are both expressible.
            $convs = $db->select('gia_conversations',
                ['campaign_id', 'conv_key', 'booking_count', 'revenue', 'revenue_currency'],
                ['started_at[<>]' => [$from, $to], 'LIMIT' => 100000]
            );
        } catch (\Throwable $e) {
            error_log('ad_campaign_conversion_report(convs): ' . $e->getMessage());
            return [];
        }
        if (!is_array($convs)) {
            return [];
        }

        // Bucket conversations by campaign_id (0 == "(none)").
        $buckets = []; // cid => ['conversations'=>int,'held'=>int,'revenue'=>float,'keys'=>[],'cur'=>[cur=>count]]
        foreach ($convs as $c) {
            $cid = (int) ($c['campaign_id'] ?? 0);
            if (!isset($buckets[$cid])) {
                $buckets[$cid] = [
                    'conversations' => 0,
                    'held'          => 0,
                    'revenue'       => 0.0,
                    'keys'          => [],
                    'cur'           => [],
                ];
            }
            $buckets[$cid]['conversations']++;
            if ((int) ($c['booking_count'] ?? 0) > 0) {
                $buckets[$cid]['held']++;
            }
            $buckets[$cid]['revenue'] += (float) ($c['revenue'] ?? 0);
            $key = (string) ($c['conv_key'] ?? '');
            if ($key !== '') {
                $buckets[$cid]['keys'][] = $key;
            }
            $cur = trim((string) ($c['revenue_currency'] ?? ''));
            if ($cur !== '') {
                $buckets[$cid]['cur'][$cur] = ($buckets[$cid]['cur'][$cur] ?? 0) + 1;
            }
        }

        // Resolve campaign name/platform for every attributed bucket in one go.
        $cids = array_values(array_filter(array_keys($buckets), static fn($k) => $k > 0));
        $meta = []; // cid => ['name'=>..,'platform'=>..]
        if (!empty($cids)) {
            try {
                $rows = $db->select('gia_ad_campaigns', ['id', 'name', 'platform', 'offer_product'], ['id' => $cids]);
                foreach ((array) $rows as $r) {
                    $meta[(int) $r['id']] = [
                        'name'          => (string) ($r['name'] ?? ''),
                        'platform'      => (string) ($r['platform'] ?? ''),
                        'offer_product' => (string) ($r['offer_product'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                error_log('ad_campaign_conversion_report(meta): ' . $e->getMessage());
            }
        }

        // Precise PAID count: per bucket, count paid ai_trip/umrah bookings linked
        // to one of the bucket's conv_keys via the booking_data JSON substring.
        $paidForKeys = static function (array $keys) use ($db): int {
            $keys = array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
            if (empty($keys)) {
                return 0;
            }
            $paid = 0;
            foreach ($keys as $k) {
                try {
                    // Match the exact JSON the booking carries: "gia_conv_key":"<key>".
                    // LIKE on the needle; $db->count escapes the pattern value.
                    $needle = '%"gia_conv_key":"' . $k . '"%';
                    $paid += (int) $db->count('bookings', [
                        'module_type'      => ['ai_trip', 'umrah'],
                        'payment_status'   => 'paid',
                        'booking_data[~]'  => $needle,
                    ]);
                } catch (\Throwable $e) {
                    error_log('ad_campaign_conversion_report(paid): ' . $e->getMessage());
                }
            }
            return $paid;
        };

        $report = [];
        foreach ($buckets as $cid => $b) {
            $convCount = (int) $b['conversations'];
            $held      = (int) $b['held'];
            $paid      = $paidForKeys($b['keys']);

            // Dominant currency in the bucket (most-stamped), '' if none.
            $cur = '';
            if (!empty($b['cur'])) {
                arsort($b['cur']);
                $cur = (string) array_key_first($b['cur']);
            }

            $report[] = [
                'campaign_id'       => $cid > 0 ? $cid : null,
                'campaign_name'     => $cid > 0 ? ($meta[$cid]['name'] ?? ('#' . $cid)) : '(none)',
                'platform'          => $cid > 0 ? ($meta[$cid]['platform'] ?? '') : '',
                'offer_product'     => $cid > 0 ? ($meta[$cid]['offer_product'] ?? '') : '',
                'conversations'     => $convCount,
                'held_bookings'     => $held,
                'paid_bookings'     => $paid,
                'revenue'           => round((float) $b['revenue'], 2),
                'revenue_currency'  => $cur,
                'conversion_rate'   => $convCount > 0 ? round($held / $convCount, 4) : 0.0,
            ];
        }

        // Sort by conversations DESC (stable-ish: ties keep insertion order enough).
        usort($report, static fn($a, $b) => $b['conversations'] <=> $a['conversations']);

        return $report;
    }
}

if (!function_exists('ad_campaign_route_conversion_report')) {
    /**
     * PER-ROUTE flight funnel over the window — chats -> held -> paid -> revenue grouped
     * by gia_conversations.route (e.g. 'LOS-ABV', 'KAN-ABV'). Works with ZERO setup
     * (every flight lead stamps its route), independent of whether a route-scoped
     * campaign exists. Mirrors ad_campaign_conversion_report's counting (booking_count>0
     * = held; paid = ai_trip bookings linked by conv_key JSON). One row per route,
     * sorted by conversations DESC. Fail-soft -> []. Directional (LOS-ABV vs ABV-LOS
     * are separate rows), per owner decision.
     */
    function ad_campaign_route_conversion_report($db, string $from, string $to): array
    {
        ensureAdCampaignSchema($db);
        try {
            $convs = $db->select('gia_conversations',
                ['route', 'conv_key', 'booking_count', 'revenue', 'revenue_currency'],
                ['route[!]' => null, 'started_at[<>]' => [$from, $to], 'LIMIT' => 100000]);
        } catch (\Throwable $e) {
            error_log('ad_campaign_route_conversion_report(convs): ' . $e->getMessage());
            return [];
        }
        if (!is_array($convs)) { return []; }

        $buckets = []; // route => [conversations, held, revenue, keys[], cur[]]
        foreach ($convs as $c) {
            $route = strtoupper(trim((string) ($c['route'] ?? '')));
            if ($route === '') { continue; }
            if (!isset($buckets[$route])) {
                $buckets[$route] = ['conversations' => 0, 'held' => 0, 'revenue' => 0.0, 'keys' => [], 'cur' => []];
            }
            $buckets[$route]['conversations']++;
            if ((int) ($c['booking_count'] ?? 0) > 0) { $buckets[$route]['held']++; }
            $buckets[$route]['revenue'] += (float) ($c['revenue'] ?? 0);
            $key = (string) ($c['conv_key'] ?? '');
            if ($key !== '') { $buckets[$route]['keys'][] = $key; }
            $cur = trim((string) ($c['revenue_currency'] ?? ''));
            if ($cur !== '') { $buckets[$route]['cur'][$cur] = ($buckets[$route]['cur'][$cur] ?? 0) + 1; }
        }

        $paidForKeys = static function (array $keys) use ($db): int {
            $keys = array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
            $paid = 0;
            foreach ($keys as $k) {
                try {
                    $paid += (int) $db->count('bookings', [
                        'module_type'     => ['ai_trip', 'umrah'],
                        'payment_status'  => 'paid',
                        'booking_data[~]' => '%"gia_conv_key":"' . $k . '"%',
                    ]);
                } catch (\Throwable $e) { error_log('ad_campaign_route_conversion_report(paid): ' . $e->getMessage()); }
            }
            return $paid;
        };

        $report = [];
        foreach ($buckets as $route => $b) {
            $convCount = (int) $b['conversations'];
            $cur = '';
            if (!empty($b['cur'])) { arsort($b['cur']); $cur = (string) array_key_first($b['cur']); }
            $report[] = [
                'route'            => $route,
                'conversations'    => $convCount,
                'held_bookings'    => (int) $b['held'],
                'paid_bookings'    => $paidForKeys($b['keys']),
                'revenue'          => round((float) $b['revenue'], 2),
                'revenue_currency' => $cur,
                'conversion_rate'  => $convCount > 0 ? round($b['held'] / $convCount, 4) : 0.0,
            ];
        }
        usort($report, static fn($a, $b) => $b['conversations'] <=> $a['conversations']);
        return $report;
    }
}
