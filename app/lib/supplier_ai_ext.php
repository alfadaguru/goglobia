<?php
// FILE: app/lib/supplier_ai_ext.php
// MODULE 57 (AI & intelligent automation) — DEEPEN. Sits beside the admin AI config
// (settings.ai_configuration + app/lib/ai/*) which it does NOT touch.
//
// PHILOSOPHY — honesty over theatre:
//   * The genuine, shippable surfaces here are REAL *without* an LLM. They are simple
//     statistics computed over the supplier's OWN data (night audits, bookings, surveys):
//       - occupancy forecast  → naive trend/seasonal-DOW model over stays_night_audits
//       - cancellation risk    → own-inventory cancellation-rate + lead-time stats
//       - review themes        → keyword + sentiment rollup (reuses review_sentiment)
//       - operational summary  → a DETERMINISTIC natural-language brief composed from the
//                                BI layer (bi_dashboard) + the three analyses above.
//   * The concierge / free-text drafting is an HONEST STUB. It reports whether an LLM
//     provider is actually connected (via passportAiSettings) and only calls a shared
//     ai_generate_text() helper IF the platform ships one AND a key is configured. It
//     NEVER fabricates a model response. Not connected ⇒ it says so.
//
// No money, no GL, no availability writes. The only write is an optional "saved brief"
// snapshot (ai_ext_briefs) — a plain text log, no financial effect. DB-GATED: the table
// is created by the lead's migration; every writer degrades gracefully if it is absent.
//
// All read helpers are defensive/non-fatal and operate on an OWNER's stay-id set
// (resolved + ownership-checked by the caller route). Reuses: bi_dashboard,
// bi_reservations_by_status, review_sentiment, supplier_property_org.

// --------------------------------------------------------------------------------------
// 1) OCCUPANCY FORECAST — naive but honest. Uses the real night-audit history.
//    Model: baseline = trailing N-day mean occupancy; day-of-week seasonal factor from
//    the same window; forecast = baseline * dow_factor, clamped 0-100. Confidence is a
//    function of sample size — we never pretend to a precision the data can't support.
// --------------------------------------------------------------------------------------
if (!function_exists('ai_ext_occupancy_forecast')) {
    /**
     * @param int $stayId  single OWNED property (caller must own it)
     * @param int $horizon days ahead to project (clamped 1-30)
     * @param int $window  trailing history days to learn from (clamped 14-180)
     * @return array ['ok','sample','baseline_pct','trend_pct_per_day','confidence',
     *                'days'=>[['date','dow','forecast_pct','basis']...], 'message']
     */
    function ai_ext_occupancy_forecast($db, int $stayId, int $horizon = 14, int $window = 90): array
    {
        $horizon = max(1, min(30, $horizon));
        $window  = max(14, min(180, $window));
        $out = ['ok' => false, 'sample' => 0, 'baseline_pct' => null, 'trend_pct_per_day' => 0.0,
                'confidence' => 'none', 'days' => [], 'message' => ''];
        if ($stayId <= 0) { $out['message'] = 'No property.'; return $out; }

        $hist = function_exists('bi_audit_trend') ? bi_audit_trend($db, $stayId, $window) : [];
        // bi_audit_trend returns chronological asc rows with business_date + occupancy_pct.
        $points = [];
        foreach ($hist as $r) {
            $occ = $r['occupancy_pct'];
            if ($occ === null || $occ === '') {
                // Derive from sold/available when the stored pct is null.
                $avail = (int) ($r['rooms_available'] ?? 0);
                $occ = $avail > 0 ? round(((int) ($r['rooms_sold'] ?? 0) / $avail) * 100, 1) : null;
            }
            if ($occ === null) { continue; }
            $ts = strtotime((string) ($r['business_date'] ?? ''));
            if ($ts === false) { continue; }
            $points[] = ['ts' => $ts, 'dow' => (int) date('w', $ts), 'occ' => max(0.0, min(100.0, (float) $occ))];
        }
        $n = count($points);
        $out['sample'] = $n;
        if ($n < 7) {
            $out['message'] = 'Not enough night-audit history yet (need ≥ 7 days). Run the night audit daily to build a forecast.';
            return $out;
        }

        // Baseline = mean occupancy over the window.
        $sum = 0.0; foreach ($points as $p) { $sum += $p['occ']; }
        $baseline = round($sum / $n, 1);

        // Trend: least-squares slope of occ vs day-index (pct per day), lightly damped.
        $sx = 0.0; $sy = 0.0; $sxx = 0.0; $sxy = 0.0;
        foreach ($points as $i => $p) { $x = (float) $i; $sx += $x; $sy += $p['occ']; $sxx += $x * $x; $sxy += $x * $p['occ']; }
        $denom = ($n * $sxx) - ($sx * $sx);
        $slope = $denom != 0.0 ? (($n * $sxy) - ($sx * $sy)) / $denom : 0.0;
        $slope = max(-1.5, min(1.5, $slope)); // damp — a naive model should not extrapolate wildly
        $out['trend_pct_per_day'] = round($slope, 3);

        // Day-of-week seasonal factor (ratio of that DOW's mean to the overall mean).
        $dowSum = array_fill(0, 7, 0.0); $dowCnt = array_fill(0, 7, 0);
        foreach ($points as $p) { $dowSum[$p['dow']] += $p['occ']; $dowCnt[$p['dow']]++; }
        $dowFactor = [];
        for ($d = 0; $d < 7; $d++) {
            $m = $dowCnt[$d] > 0 ? $dowSum[$d] / $dowCnt[$d] : $baseline;
            $dowFactor[$d] = $baseline > 0 ? $m / $baseline : 1.0;
        }

        // Project forward from today.
        $today = strtotime(date('Y-m-d'));
        $lastIdx = $n - 1;
        for ($h = 1; $h <= $horizon; $h++) {
            $ts = $today + ($h * 86400);
            $dow = (int) date('w', $ts);
            $trended = $baseline + ($slope * ($lastIdx + $h));
            $fc = $trended * ($dowFactor[$dow] ?? 1.0);
            $fc = max(0.0, min(100.0, $fc));
            $out['days'][] = [
                'date' => date('Y-m-d', $ts),
                'dow'  => date('D', $ts),
                'forecast_pct' => round($fc, 1),
                'basis' => 'trend+dow',
            ];
        }

        $out['baseline_pct'] = $baseline;
        $out['confidence'] = $n >= 60 ? 'moderate' : ($n >= 30 ? 'low' : 'very low');
        $out['ok'] = true;
        $out['message'] = 'Naive statistical projection from your own night-audit history — not an AI model.';
        return $out;
    }
}

// --------------------------------------------------------------------------------------
// 2) CANCELLATION RISK — own-inventory cancellation statistics + lead-time distribution.
//    Pure counting over bookings (decoded + hotel_id-verified, never LIKE-only). No LLM.
// --------------------------------------------------------------------------------------
if (!function_exists('ai_ext_cancellation_risk')) {
    /**
     * @param array $stayIds owned stay ids (caller resolves + owns)
     * @return array ['total','cancelled','rate_pct','by_lead'=>[bucket=>['n','cancelled']],
     *                'high_risk_buckets'=>[...], 'message']
     */
    function ai_ext_cancellation_risk($db, array $stayIds, int $limit = 3000): array
    {
        $buckets = ['0-3d' => [0, 3], '4-14d' => [4, 14], '15-45d' => [15, 45], '46d+' => [46, 99999]];
        $by = [];
        foreach (array_keys($buckets) as $b) { $by[$b] = ['n' => 0, 'cancelled' => 0]; }
        $out = ['total' => 0, 'cancelled' => 0, 'rate_pct' => 0.0, 'by_lead' => $by,
                'high_risk_buckets' => [], 'message' => ''];
        if (empty($stayIds)) { $out['message'] = 'No properties.'; return $out; }

        $set = array_fill_keys(array_map('intval', $stayIds), true);
        $likeValues = [];
        foreach ($stayIds as $sid) { $likeValues[] = '%"hotel_id":' . (int) $sid . '%'; } // wrap % ourselves: the _ in hotel_id defeats Medoo auto-wrap (Medoo.php:1163)
        try {
            $rows = $db->select('bookings',
                ['booking_status', 'booking_data', 'created_at', 'paid_at'],
                ['module_type' => ['stays', 'hotels'], 'booking_data[~]' => $likeValues,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            // created_at may not exist on every install — retry without it.
            try {
                $rows = $db->select('bookings', ['booking_status', 'booking_data', 'paid_at'],
                    ['module_type' => ['stays', 'hotels'], 'booking_data[~]' => $likeValues,
                     'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            } catch (\Throwable $e2) { error_log('ai_ext_cancellation_risk: ' . $e2->getMessage()); $rows = []; }
        }

        foreach ($rows as $r) {
            $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
            if (!is_array($bd) || empty($set[(int) ($bd['hotel_id'] ?? 0)])) { continue; } // verify ownership
            $out['total']++;
            $isCancelled = strtolower((string) ($r['booking_status'] ?? '')) === 'cancelled';
            if ($isCancelled) { $out['cancelled']++; }

            // Lead time = days between booking creation and check-in, when both are known.
            $checkin = strtotime((string) ($bd['checkin'] ?? ''));
            $bookedTs = strtotime((string) ($r['created_at'] ?? ($r['paid_at'] ?? '')));
            if ($checkin !== false && $bookedTs !== false && $checkin >= $bookedTs) {
                $lead = (int) floor(($checkin - $bookedTs) / 86400);
                foreach ($buckets as $bname => $range) {
                    if ($lead >= $range[0] && $lead <= $range[1]) {
                        $by[$bname]['n']++;
                        if ($isCancelled) { $by[$bname]['cancelled']++; }
                        break;
                    }
                }
            }
        }

        $out['by_lead'] = $by;
        $out['rate_pct'] = $out['total'] > 0 ? round(($out['cancelled'] / $out['total']) * 100, 1) : 0.0;
        // Flag any lead-time bucket with ≥ 5 samples and a cancel-rate above the overall rate + 10pts.
        foreach ($by as $bname => $bv) {
            if ($bv['n'] >= 5) {
                $r = round(($bv['cancelled'] / $bv['n']) * 100, 1);
                if ($r >= $out['rate_pct'] + 10.0) { $out['high_risk_buckets'][$bname] = $r; }
            }
        }
        $out['message'] = 'Observed cancellation statistics from your own reservations — descriptive, not a prediction.';
        return $out;
    }
}

// --------------------------------------------------------------------------------------
// 3) REVIEW THEMES — keyword + sentiment rollup over post-stay survey comments. Reuses the
//    existing review_sentiment() lexicon (module 23). No LLM; a transparent word tally.
// --------------------------------------------------------------------------------------
if (!function_exists('ai_ext_review_themes')) {
    /**
     * @param array $stayIds owned stays (caller owns)
     * @return array ['ok','n_comments','sentiment'=>['positive','neutral','negative'],
     *                'top_positive'=>[word=>count], 'top_negative'=>[word=>count], 'message']
     */
    function ai_ext_review_themes($db, array $stayIds, int $limit = 500): array
    {
        $out = ['ok' => false, 'n_comments' => 0, 'sentiment' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                'top_positive' => [], 'top_negative' => [], 'message' => ''];
        if (empty($stayIds)) { $out['message'] = 'No properties.'; return $out; }
        if (!function_exists('review_sentiment')) { $out['message'] = 'Sentiment helper unavailable.'; return $out; }

        // The same positive/negative lexicon review_sentiment() uses — mirrored here as a
        // read-only tally so this stays decoupled (no behavioural change to module 23).
        $pos = ['great','excellent','amazing','wonderful','clean','friendly','comfortable',
                'perfect','lovely','helpful','spotless','fantastic','good','best','enjoyed',
                'recommend','pleasant','quiet','spacious','value','delicious','superb'];
        $neg = ['dirty','rude','noisy','broken','terrible','awful','poor','bad','worst',
                'uncomfortable','smelly','cold','slow','disappointing','overpriced','unclean',
                'cramped','stained','unhelpful','horrible','filthy','leaking','cockroach'];
        $posSet = array_fill_keys($pos, 0);
        $negSet = array_fill_keys($neg, 0);

        try {
            $rows = $db->select('stays_surveys', ['comment', 'sentiment'],
                ['stay_id' => array_map('intval', $stayIds),
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('ai_ext_review_themes: ' . $e->getMessage()); $rows = []; }

        foreach ($rows as $r) {
            $comment = trim((string) ($r['comment'] ?? ''));
            if ($comment === '') { continue; }
            $out['n_comments']++;
            // Prefer the cached label (column `sentiment`); recompute if missing.
            $label = (string) ($r['sentiment'] ?? '');
            if (!in_array($label, ['positive', 'neutral', 'negative'], true)) {
                $label = review_sentiment($comment)['label'];
            }
            if (isset($out['sentiment'][$label])) { $out['sentiment'][$label]++; }

            foreach (preg_split('/[^a-z]+/', strtolower($comment)) ?: [] as $w) {
                if ($w === '') { continue; }
                if (isset($posSet[$w])) { $posSet[$w]++; }
                elseif (isset($negSet[$w])) { $negSet[$w]++; }
            }
        }

        $posSet = array_filter($posSet); arsort($posSet);
        $negSet = array_filter($negSet); arsort($negSet);
        $out['top_positive'] = array_slice($posSet, 0, 8, true);
        $out['top_negative'] = array_slice($negSet, 0, 8, true);
        $out['ok'] = $out['n_comments'] > 0;
        $out['message'] = $out['ok']
            ? 'Keyword + sentiment tally over your guest-survey comments.'
            : 'No survey comments yet — send post-stay surveys to populate this.';
        return $out;
    }
}

// --------------------------------------------------------------------------------------
// 4) OPERATIONAL SUMMARY — a DETERMINISTIC natural-language brief composed from the BI
//    layer + the analyses above. This is template text, labelled as such. It is NOT an
//    LLM output and does not claim to be.
// --------------------------------------------------------------------------------------
if (!function_exists('ai_ext_ops_summary')) {
    /**
     * @return array ['generated_at','lines'=>[string...], 'source'=>'rule-based']
     */
    function ai_ext_ops_summary($db, string $owner, array $stayIds, int $focusStayId = 0): array
    {
        $lines = [];
        $dash = function_exists('bi_dashboard') ? bi_dashboard($db, $owner, $stayIds, $focusStayId) : [];

        // Reservations + channel mix.
        $res = $dash['reservations'] ?? ['total' => 0, 'status' => [], 'channels' => []];
        $total = (int) ($res['total'] ?? 0);
        if ($total > 0) {
            $conf = (int) ($res['status']['confirmed'] ?? 0);
            $canc = (int) ($res['status']['cancelled'] ?? 0);
            $lines[] = "You have {$total} reservation" . ($total === 1 ? '' : 's') . " on record across your properties"
                . ($conf ? ", {$conf} confirmed" : '') . ($canc ? " and {$canc} cancelled" : '') . '.';
            $chan = $res['channels'] ?? [];
            arsort($chan);
            $topChan = array_key_first($chan);
            if ($topChan !== null && ($chan[$topChan] ?? 0) > 0) {
                $lines[] = 'Your strongest booking channel is ' . str_replace('_', ' ', (string) $topChan)
                    . ' (' . (int) $chan[$topChan] . ' bookings).';
            }
        } else {
            $lines[] = 'No reservations recorded yet for your own inventory.';
        }

        // Occupancy trend (focus property).
        $trendStay = (int) ($dash['trend_stay'] ?? ($focusStayId ?: (int) ($stayIds[0] ?? 0)));
        if ($trendStay > 0) {
            $fc = ai_ext_occupancy_forecast($db, $trendStay, 7, 90);
            if (!empty($fc['ok'])) {
                $avg = 0.0; $cnt = 0;
                foreach ($fc['days'] as $d) { $avg += (float) $d['forecast_pct']; $cnt++; }
                $avg = $cnt ? round($avg / $cnt, 0) : 0;
                $dir = $fc['trend_pct_per_day'] > 0.1 ? 'trending up' : ($fc['trend_pct_per_day'] < -0.1 ? 'trending down' : 'flat');
                $lines[] = "Projected 7-day occupancy on your focus property averages ~{$avg}% and is {$dir} "
                    . "(confidence: {$fc['confidence']}, from {$fc['sample']} days of audit history).";
            }
        }

        // Cancellation risk.
        $cx = ai_ext_cancellation_risk($db, $stayIds);
        if ((int) $cx['total'] > 0) {
            $lines[] = "Overall cancellation rate is {$cx['rate_pct']}% ({$cx['cancelled']} of {$cx['total']}).";
            if (!empty($cx['high_risk_buckets'])) {
                $parts = [];
                foreach ($cx['high_risk_buckets'] as $b => $r) { $parts[] = "{$b} lead time ({$r}%)"; }
                $lines[] = 'Higher-than-average cancellations cluster in: ' . implode(', ', $parts) . '.';
            }
        }

        // Review themes.
        $themes = ai_ext_review_themes($db, $stayIds);
        if (!empty($themes['ok'])) {
            $s = $themes['sentiment'];
            $lines[] = "Guest sentiment over {$themes['n_comments']} survey comment" . ($themes['n_comments'] === 1 ? '' : 's')
                . ": {$s['positive']} positive, {$s['neutral']} neutral, {$s['negative']} negative.";
            if (!empty($themes['top_negative'])) {
                $lines[] = 'Most-mentioned issues: ' . implode(', ', array_slice(array_keys($themes['top_negative']), 0, 5)) . '.';
            }
            if (!empty($themes['top_positive'])) {
                $lines[] = 'Guests most often praise: ' . implode(', ', array_slice(array_keys($themes['top_positive']), 0, 5)) . '.';
            }
        }

        // Ops counts.
        $ops = $dash['ops'] ?? [];
        $wo = $ops['work_orders'] ?? ['open' => 0, 'in_progress' => 0];
        if (((int) ($wo['open'] ?? 0) + (int) ($wo['in_progress'] ?? 0)) > 0) {
            $lines[] = 'Maintenance backlog: ' . (int) $wo['open'] . ' open and ' . (int) $wo['in_progress'] . ' in-progress work orders.';
        }

        if (empty($lines)) { $lines[] = 'Not enough data yet to build a summary. Add reservations, run the night audit, and collect surveys.'; }

        return [
            'generated_at' => date('Y-m-d H:i'),
            'lines' => $lines,
            'source' => 'rule-based', // honest: composed from your data, NOT written by an LLM
        ];
    }
}

// --------------------------------------------------------------------------------------
// 5) CONCIERGE / DRAFTING — HONEST STUB. Reports provider connection status and only
//    attempts generation if a provider key is actually configured AND the platform ships
//    a shared ai_generate_text() helper. It NEVER fabricates a model reply.
// --------------------------------------------------------------------------------------
if (!function_exists('ai_ext_concierge_status')) {
    /** @return array ['connected'=>bool,'provider'=>string,'can_generate'=>bool,'reason'=>string] */
    function ai_ext_concierge_status($db): array
    {
        $connected = false; $provider = ''; $reason = 'No AI provider configured by your administrator.';
        try {
            if (function_exists('passportAiSettings')) {
                $cfg = passportAiSettings($db);
                $connected = !empty($cfg['enabled']); // a provider key is present + a feature enabled
                $provider  = (string) ($cfg['provider'] ?? '');
                if ($connected) { $reason = 'Provider "' . $provider . '" is configured.'; }
            }
        } catch (\Throwable $e) { error_log('ai_ext_concierge_status: ' . $e->getMessage()); }
        // We can only generate free text if BOTH a provider is connected AND a shared
        // text-generation helper exists in this build. Today it does not — so can_generate
        // is honestly false and the UI shows "not connected".
        $canGenerate = $connected && function_exists('ai_generate_text');
        if ($connected && !$canGenerate) {
            $reason = 'Provider "' . $provider . '" is configured, but this build ships no text-generation helper yet.';
        }
        return ['connected' => $connected, 'provider' => $provider, 'can_generate' => $canGenerate, 'reason' => $reason];
    }
}

if (!function_exists('ai_ext_concierge_draft')) {
    /**
     * Attempt to draft a short guest-facing message. Returns ['ok','text','source','reason'].
     * source ∈ {'ai','unavailable'}. On any failure OR when no provider is connected, returns
     * ok=false with source='unavailable' — it does NOT invent a reply.
     */
    function ai_ext_concierge_draft($db, string $intent, string $context = ''): array
    {
        $status = ai_ext_concierge_status($db);
        if (empty($status['can_generate'])) {
            return ['ok' => false, 'text' => '', 'source' => 'unavailable', 'reason' => $status['reason']];
        }
        $intent  = substr(trim($intent), 0, 400);
        $context = substr(trim($context), 0, 1500);
        try {
            $prompt = "You are the front-desk assistant for a hotel. Write a short (max 80 words), warm, "
                . "professional message. Intent: {$intent}. Context: {$context}.";
            $text = ai_generate_text($db, $prompt); // only reachable when the helper truly exists
            if (is_string($text) && trim($text) !== '') {
                return ['ok' => true, 'text' => trim($text), 'source' => 'ai', 'reason' => ''];
            }
        } catch (\Throwable $e) { error_log('ai_ext_concierge_draft: ' . $e->getMessage()); }
        return ['ok' => false, 'text' => '', 'source' => 'unavailable', 'reason' => 'The AI provider did not return a usable draft.'];
    }
}

// --------------------------------------------------------------------------------------
// 6) SAVED BRIEF — optional snapshot of an ops summary for the record. DB-GATED: writes to
//    ai_ext_briefs only if the table exists (lead's migration). No money/GL effect.
// --------------------------------------------------------------------------------------
if (!function_exists('_ai_ext_has_briefs_table')) {
    function _ai_ext_has_briefs_table($db): bool
    {
        static $known = null;
        if ($known !== null) { return $known; }
        try { $cols = $db->query('SHOW COLUMNS FROM `ai_ext_briefs`')->fetchAll(); $known = !empty($cols); }
        catch (\Throwable $e) { $known = false; }
        return $known;
    }
}

if (!function_exists('ai_ext_brief_save')) {
    /** Persist a brief snapshot for one org. Returns ['ok','message']. DB-GATED. */
    function ai_ext_brief_save($db, int $orgId, string $title, array $summary, string $by): array
    {
        if (!_ai_ext_has_briefs_table($db)) {
            return ['ok' => false, 'message' => 'Saving is not available until the ai_ext_briefs table is installed.'];
        }
        $title = substr(trim($title) !== '' ? trim($title) : ('Operational brief ' . date('Y-m-d')), 0, 180);
        $body  = substr(json_encode($summary['lines'] ?? [], JSON_UNESCAPED_UNICODE), 0, 60000);
        try {
            $db->insert('ai_ext_briefs', [
                'org_id'     => $orgId,
                'title'      => $title,
                'body_json'  => $body,
                'source'     => (string) ($summary['source'] ?? 'rule-based'),
                'created_by' => substr($by, 0, 155),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => 'Brief saved.'];
        } catch (\Throwable $e) {
            error_log('ai_ext_brief_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the brief.'];
        }
    }
}

if (!function_exists('ai_ext_brief_list')) {
    /** Recent saved briefs for an org. Returns rows (decoded lines). DB-GATED. */
    function ai_ext_brief_list($db, int $orgId, int $limit = 20): array
    {
        if (!_ai_ext_has_briefs_table($db) || $orgId <= 0) { return []; }
        try {
            $rows = $db->select('ai_ext_briefs', ['id', 'title', 'body_json', 'source', 'created_by', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(100, $limit))]) ?: [];
            foreach ($rows as &$r) { $r['lines'] = json_decode((string) ($r['body_json'] ?? '[]'), true) ?: []; }
            unset($r);
            return $rows;
        } catch (\Throwable $e) { error_log('ai_ext_brief_list: ' . $e->getMessage()); return []; }
    }
}
