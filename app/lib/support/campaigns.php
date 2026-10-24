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
