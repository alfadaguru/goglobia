<?php
// FILE: app/lib/supplier_reviews_ext.php
// MODULE 23 (Reputation & reviews) — DEEPEN. Builds the genuine gaps ON TOP of the
// existing supplier_reviews.php (public star reviews + rollup + reply, which we DO NOT
// touch): guest SURVEYS (NPS + per-category sub-scores), staff INTERNAL ratings, a simple
// keyword SENTIMENT pass, DEPARTMENT performance (category→department rollup), a REPUTATION
// DASHBOARD aggregate, and an honest AI response-suggestion helper (DB-GATED on
// settings.ai_configuration; falls back to a deterministic template — never faked).
//
// Two NEW tables (self-healing, lazy-ensured, mirrors the house pattern):
//   stays_surveys        — one structured survey per (stay, invoice); NPS 0-10 + category
//                          sub-ratings (1-5) + free comment + sentiment cache.
//   stays_internal_notes — staff-entered internal quality score (1-5) + note per booking,
//                          NEVER public, for operational QA.
// No live-stays write happens here (the public rating rollup stays owned by
// supplier_reviews.php). Surveys/internal ratings are reputation intelligence only.
//
// Reuse: review_token / review_token_decode / review_eligible_booking / guest_email_key /
// supplier_property_org from the base module. NOT runtime-verified (no MySQL here).

// The survey category keys and their owning department. Kept in one place so the survey
// form, the department rollup and the dashboard all agree.
if (!function_exists('survey_categories')) {
    /** [category_key => ['label'=>..., 'department'=>...]]. Departments match the PMS
     *  operational vocabulary (housekeeping / front_office / f_and_b / maintenance). */
    function survey_categories(): array
    {
        return [
            'cleanliness' => ['label' => 'Cleanliness',        'department' => 'housekeeping'],
            'staff'       => ['label' => 'Staff & service',    'department' => 'front_office'],
            'comfort'     => ['label' => 'Room comfort',       'department' => 'maintenance'],
            'facilities'  => ['label' => 'Food & facilities',  'department' => 'f_and_b'],
            'value'       => ['label' => 'Value for money',    'department' => 'front_office'],
            'location'    => ['label' => 'Location',           'department' => 'front_office'],
        ];
    }
}
if (!function_exists('survey_department_label')) {
    function survey_department_label(string $dept): string
    {
        $m = [
            'housekeeping' => 'Housekeeping',
            'front_office' => 'Front office',
            'f_and_b'      => 'Food & beverage',
            'maintenance'  => 'Maintenance / engineering',
        ];
        return $m[$dept] ?? ucfirst(str_replace('_', ' ', $dept));
    }
}

if (!function_exists('_reviews_ext_ensure_schema')) {
    /**
     * Lazy, idempotent, self-healing schema for the two NEW reputation tables. Mirrors the
     * house ensure* pattern (CREATE IF NOT EXISTS + guarded ALTER, swallow failures). Runs
     * at most once per request. The lead ALSO lists this DDL in schema_sql for install/db.sql.
     */
    function _reviews_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_surveys` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) DEFAULT NULL,
                `stay_id` INT(11) NOT NULL,
                `invoice_id` VARCHAR(255) NOT NULL,
                `guest_email` VARCHAR(191) DEFAULT NULL,
                `guest_name` VARCHAR(120) DEFAULT NULL,
                `nps` TINYINT(2) DEFAULT NULL,
                `scores_json` TEXT DEFAULT NULL,
                `comment` TEXT DEFAULT NULL,
                `sentiment` ENUM('positive','neutral','negative') DEFAULT NULL,
                `sentiment_score` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_survey_stay_invoice` (`stay_id`,`invoice_id`),
                KEY `idx_survey_stay` (`stay_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) { error_log('_reviews_ext_ensure_schema surveys: ' . $e->getMessage()); }
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_internal_notes` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) DEFAULT NULL,
                `stay_id` INT(11) NOT NULL,
                `invoice_id` VARCHAR(255) DEFAULT NULL,
                `rating` TINYINT(1) NOT NULL DEFAULT 3,
                `department` VARCHAR(40) DEFAULT NULL,
                `note` TEXT DEFAULT NULL,
                `created_by` VARCHAR(191) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_internal_stay` (`stay_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) { error_log('_reviews_ext_ensure_schema internal: ' . $e->getMessage()); }
    }
}

// --------------------------------------------------------------------------------------
// SENTIMENT — simple deterministic keyword scorer (spec: "sentiment analysis"; the known
// base is "simple keyword", so this is intentionally lexical, not an LLM call). Returns
// ['label'=>positive|neutral|negative, 'score'=>int]. Score = (positive hits − negative
// hits); negation ("not clean") flips the following token's polarity.
// --------------------------------------------------------------------------------------
if (!function_exists('review_sentiment')) {
    function review_sentiment(?string $text): array
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') { return ['label' => 'neutral', 'score' => 0]; }
        $pos = ['great','excellent','amazing','wonderful','clean','friendly','comfortable',
                'perfect','lovely','helpful','spotless','fantastic','good','best','enjoyed',
                'recommend','pleasant','quiet','spacious','value','delicious','superb'];
        $neg = ['dirty','rude','noisy','broken','terrible','awful','poor','bad','worst',
                'uncomfortable','smelly','cold','slow','disappointing','overpriced','unclean',
                'cramped','stained','unhelpful','horrible','filthy','leaking','cockroach'];
        $negators = ['not','no','never','nt','without','lacking','barely'];
        $words = preg_split('/[^a-z]+/', $text) ?: [];
        $score = 0; $prevNeg = false;
        foreach ($words as $w) {
            if ($w === '') { continue; }
            if (in_array($w, $negators, true)) { $prevNeg = true; continue; }
            $hit = in_array($w, $pos, true) ? 1 : (in_array($w, $neg, true) ? -1 : 0);
            if ($hit !== 0) { $score += $prevNeg ? -$hit : $hit; }
            $prevNeg = false;
        }
        $label = $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral');
        return ['label' => $label, 'score' => $score];
    }
}

// --------------------------------------------------------------------------------------
// GUEST SURVEY — structured post-stay capture, keyed to the SAME tokened eligibility as a
// public review (reuses review_eligible_booking so only post-stay/paid, own-inventory
// bookings qualify). One survey per (stay, invoice). Not public; feeds the dashboard.
// --------------------------------------------------------------------------------------
if (!function_exists('survey_eligible')) {
    /** Thin wrapper over review_eligible_booking that also reports whether a survey already
     *  exists for this invoice. Returns the base array + 'survey_done' bool. */
    function survey_eligible($db, string $invoiceId): array
    {
        _reviews_ext_ensure_schema($db);
        if (!function_exists('review_eligible_booking')) { return ['ok' => false, 'message' => 'Unavailable']; }
        $e = review_eligible_booking($db, $invoiceId);
        if (empty($e['ok'])) { return $e; }
        try { $e['survey_done'] = (bool) $db->has('stays_surveys', ['stay_id' => (int) $e['stay_id'], 'invoice_id' => $invoiceId]); }
        catch (\Throwable $ex) { $e['survey_done'] = false; }
        return $e;
    }
}
if (!function_exists('survey_submit')) {
    /**
     * Store a guest survey. $nps clamped 0-10; each category score clamped 1-5 (missing =
     * skipped). Caches the keyword sentiment. One per (stay, invoice) via UNIQUE + locked
     * pre-check in a transaction. Returns ['ok','message'].
     */
    function survey_submit($db, string $invoiceId, int $nps, array $scores, string $comment): array
    {
        $elig = survey_eligible($db, $invoiceId);
        if (empty($elig['ok'])) { return ['ok' => false, 'message' => $elig['message'] ?? 'Not eligible']; }
        if (!empty($elig['survey_done'])) { return ['ok' => false, 'message' => 'You have already completed this survey.']; }
        $stayId = (int) $elig['stay_id'];
        $nps = max(0, min(10, $nps));
        $cats = survey_categories();
        $clean = [];
        foreach ($scores as $k => $v) {
            if (!isset($cats[$k])) { continue; }
            $iv = (int) $v;
            if ($iv < 1) { continue; }
            $clean[$k] = max(1, min(5, $iv));
        }
        $comment = substr(trim($comment), 0, 2000);
        $sent = review_sentiment($comment);

        $result = ['ok' => false, 'message' => 'Could not submit survey'];
        try {
            $db->action(function ($db) use ($stayId, $invoiceId, $nps, $clean, $comment, $sent, $elig, &$result) {
                $lock = $db->query('SELECT id FROM stays_surveys WHERE stay_id=:s AND invoice_id=:i FOR UPDATE',
                    [':s' => $stayId, ':i' => $invoiceId]);
                if ($lock && $lock->fetch(\PDO::FETCH_ASSOC)) { $result = ['ok' => false, 'message' => 'Already submitted']; return false; }
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_surveys', [
                    'org_id'          => $orgId > 0 ? $orgId : null,
                    'stay_id'         => $stayId,
                    'invoice_id'      => $invoiceId,
                    'guest_email'     => (string) ($elig['email'] ?? ''),
                    'guest_name'      => substr((string) ($elig['guest_name'] ?? ''), 0, 120) ?: null,
                    'nps'             => $nps,
                    'scores_json'     => $clean ? json_encode($clean) : null,
                    'comment'         => $comment !== '' ? $comment : null,
                    'sentiment'       => $sent['label'],
                    'sentiment_score' => (int) $sent['score'],
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'message' => 'Thank you — your feedback has been recorded.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('survey_submit: ' . $e->getMessage());
        }
        return $result;
    }
}

// --------------------------------------------------------------------------------------
// INTERNAL RATING — staff-entered QA score, NEVER public. Property must belong to the
// owner (the ROUTE enforces supplier_can + ownership; this function just persists).
// --------------------------------------------------------------------------------------
if (!function_exists('internal_rating_add')) {
    /** Insert an internal QA note. $rating clamped 1-5; $department validated against the
     *  known department set (else stored null). Returns bool. */
    function internal_rating_add($db, int $stayId, string $invoiceId, int $rating, string $department, string $note, string $by): bool
    {
        _reviews_ext_ensure_schema($db);
        if ($stayId <= 0) { return false; }
        $rating = max(1, min(5, $rating));
        $deptOk = in_array($department, ['housekeeping', 'front_office', 'f_and_b', 'maintenance'], true);
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_internal_notes', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'invoice_id' => trim($invoiceId) !== '' ? substr(trim($invoiceId), 0, 255) : null,
                'rating'     => $rating,
                'department' => $deptOk ? $department : null,
                'note'       => substr(trim($note), 0, 2000) ?: null,
                'created_by' => substr($by, 0, 191) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('internal_rating_add: ' . $e->getMessage());
            return false;
        }
    }
}
if (!function_exists('internal_rating_list')) {
    /** Latest internal notes across the owner's properties. Read-only. */
    function internal_rating_list($db, array $stayIds, int $limit = 100): array
    {
        _reviews_ext_ensure_schema($db);
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_internal_notes',
                ['id', 'stay_id', 'invoice_id', 'rating', 'department', 'note', 'created_by', 'created_at'],
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            error_log('internal_rating_list: ' . $e->getMessage());
            return [];
        }
    }
}

// --------------------------------------------------------------------------------------
// DEPARTMENT PERFORMANCE — roll the survey per-category sub-scores up to the owning
// department, blended with staff internal ratings for that department. All read-only,
// property-scoped by the passed $stayIds.
// --------------------------------------------------------------------------------------
if (!function_exists('reputation_department_scores')) {
    /**
     * Returns [department => ['label','guest_avg','guest_n','internal_avg','internal_n',
     * 'score']] where 'score' is the 1-5 blend (guest-weighted). Averages over published
     * survey category scores + internal notes.
     */
    function reputation_department_scores($db, array $stayIds): array
    {
        _reviews_ext_ensure_schema($db);
        $cats = survey_categories();
        // department => ['guest_sum','guest_n','int_sum','int_n']
        $agg = [];
        foreach ($cats as $meta) { $agg[$meta['department']] = ['guest_sum' => 0.0, 'guest_n' => 0, 'int_sum' => 0.0, 'int_n' => 0]; }
        if (empty($stayIds)) { $agg = []; }
        try {
            if (!empty($stayIds)) {
                // Guest category scores from surveys.
                $rows = $db->select('stays_surveys', ['scores_json'], ['stay_id' => $stayIds]) ?: [];
                foreach ($rows as $r) {
                    $sc = json_decode((string) ($r['scores_json'] ?? ''), true);
                    if (!is_array($sc)) { continue; }
                    foreach ($sc as $k => $v) {
                        if (!isset($cats[$k])) { continue; }
                        $dept = $cats[$k]['department'];
                        $agg[$dept]['guest_sum'] += (float) $v;
                        $agg[$dept]['guest_n']++;
                    }
                }
                // Internal ratings by department.
                $ints = $db->select('stays_internal_notes', ['department', 'rating'], ['stay_id' => $stayIds]) ?: [];
                foreach ($ints as $r) {
                    $dept = (string) ($r['department'] ?? '');
                    if ($dept === '' || !isset($agg[$dept])) { continue; }
                    $agg[$dept]['int_sum'] += (float) $r['rating'];
                    $agg[$dept]['int_n']++;
                }
            }
        } catch (\Throwable $e) {
            error_log('reputation_department_scores: ' . $e->getMessage());
        }
        $out = [];
        foreach ($agg as $dept => $a) {
            $gAvg = $a['guest_n'] > 0 ? $a['guest_sum'] / $a['guest_n'] : null;
            $iAvg = $a['int_n'] > 0 ? $a['int_sum'] / $a['int_n'] : null;
            // Blend: guest feedback weighted 2:1 over internal QA (guest voice leads).
            $score = null;
            if ($gAvg !== null && $iAvg !== null) { $score = ($gAvg * 2 + $iAvg) / 3; }
            elseif ($gAvg !== null) { $score = $gAvg; }
            elseif ($iAvg !== null) { $score = $iAvg; }
            $out[$dept] = [
                'label'        => survey_department_label($dept),
                'guest_avg'    => $gAvg !== null ? round($gAvg, 2) : null,
                'guest_n'      => $a['guest_n'],
                'internal_avg' => $iAvg !== null ? round($iAvg, 2) : null,
                'internal_n'   => $a['int_n'],
                'score'        => $score !== null ? round($score, 2) : null,
            ];
        }
        return $out;
    }
}

// --------------------------------------------------------------------------------------
// REPUTATION DASHBOARD — the single aggregate the dashboard view consumes. Blends the
// PUBLIC review rollup (owned by the base module) with survey NPS + sentiment + department
// scorecard. Read-only, property-scoped.
// --------------------------------------------------------------------------------------
if (!function_exists('reputation_dashboard')) {
    /**
     * Returns: review_avg, review_n (published public reviews), nps (−100..100 or null),
     * nps_n, promoters/passives/detractors, sentiment=['positive','neutral','negative'],
     * departments (reputation_department_scores), recent_negative (surveys/reviews with
     * negative sentiment for triage).
     */
    function reputation_dashboard($db, array $stayIds): array
    {
        _reviews_ext_ensure_schema($db);
        $out = [
            'review_avg' => null, 'review_n' => 0,
            'nps' => null, 'nps_n' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0,
            'sentiment' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
            'departments' => reputation_department_scores($db, $stayIds),
            'recent_negative' => [],
        ];
        if (empty($stayIds)) { return $out; }
        try {
            // Public review rollup (same semantics as review_rollup but across many stays).
            $out['review_n'] = (int) $db->count('stays_reviews', ['stay_id' => $stayIds, 'status' => 'published']);
            if ($out['review_n'] > 0) {
                $sum = (float) $db->sum('stays_reviews', 'rating', ['stay_id' => $stayIds, 'status' => 'published']);
                $out['review_avg'] = round($sum / $out['review_n'], 2);
            }
            // NPS from surveys.
            $surveys = $db->select('stays_surveys', ['nps', 'sentiment'], ['stay_id' => $stayIds]) ?: [];
            foreach ($surveys as $s) {
                if ($s['nps'] !== null && $s['nps'] !== '') {
                    $n = (int) $s['nps']; $out['nps_n']++;
                    if ($n >= 9) { $out['promoters']++; } elseif ($n >= 7) { $out['passives']++; } else { $out['detractors']++; }
                }
                $lab = (string) ($s['sentiment'] ?? '');
                if (isset($out['sentiment'][$lab])) { $out['sentiment'][$lab]++; }
            }
            if ($out['nps_n'] > 0) {
                $out['nps'] = (int) round((($out['promoters'] - $out['detractors']) / $out['nps_n']) * 100);
            }
            // Recent negative sentiment, for triage (surveys with a negative comment).
            $out['recent_negative'] = $db->select('stays_surveys',
                ['id', 'stay_id', 'guest_name', 'nps', 'comment', 'sentiment_score', 'created_at'],
                ['stay_id' => $stayIds, 'sentiment' => 'negative', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 10]) ?: [];
        } catch (\Throwable $e) {
            error_log('reputation_dashboard: ' . $e->getMessage());
        }
        return $out;
    }
}
if (!function_exists('survey_list_for_org')) {
    /** Recent surveys across the owner's properties. Read-only. */
    function survey_list_for_org($db, array $stayIds, int $limit = 200): array
    {
        _reviews_ext_ensure_schema($db);
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_surveys',
                ['id', 'stay_id', 'guest_name', 'nps', 'scores_json', 'comment', 'sentiment', 'sentiment_score', 'created_at'],
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            error_log('survey_list_for_org: ' . $e->getMessage());
            return [];
        }
    }
}

// --------------------------------------------------------------------------------------
// AI RESPONSE SUGGESTION — DB-GATED. Real AI runs ONLY if settings.ai_configuration is
// present/enabled AND the shared AI text helper exists; otherwise returns a deterministic,
// sentiment-aware template. The response is ALWAYS honestly labelled ['source'=>...].
// We do NOT invent an API call here — flagged DB-GATED per the deepen rules.
// --------------------------------------------------------------------------------------
if (!function_exists('review_ai_reply_suggestion')) {
    /**
     * Suggest a public reply to a review. Returns ['text'=>..., 'source'=>'ai'|'template'].
     * Never writes anything (the owner edits + submits via the existing reply route).
     */
    function review_ai_reply_suggestion($db, int $rating, ?string $comment, string $propertyName = ''): array
    {
        $comment = trim((string) $comment);
        $sent = review_sentiment($comment);
        $prop = $propertyName !== '' ? $propertyName : 'our property';

        // DB gate: only attempt AI when a provider is actually configured AND a shared
        // text-generation helper exists. We deliberately do NOT fabricate a provider call.
        $aiReady = false;
        try {
            $raw = $db->get('settings', ['ai_configuration'], ['id' => 1]);
            $cfg = $raw && !empty($raw['ai_configuration']) ? json_decode((string) $raw['ai_configuration'], true) : null;
            $aiReady = is_array($cfg) && !empty($cfg['enabled']) && function_exists('ai_generate_text');
        } catch (\Throwable $e) { $aiReady = false; }

        if ($aiReady) {
            try {
                $prompt = "Write a short, warm, professional public reply (max 60 words) from the management of "
                    . $prop . " to this guest review. Rating: {$rating}/5. Review: \"" . $comment . "\". "
                    . "Thank them, acknowledge specifics, and " . ($rating <= 3 ? "apologise and invite them back." : "invite them to return.");
                $text = ai_generate_text($db, $prompt); // shared helper, if the platform ships one
                if (is_string($text) && trim($text) !== '') {
                    return ['text' => trim($text), 'source' => 'ai'];
                }
            } catch (\Throwable $e) {
                error_log('review_ai_reply_suggestion ai: ' . $e->getMessage());
            }
            // fall through to template on any AI failure (fail-closed to the safe default)
        }

        // Deterministic sentiment-aware template (honest fallback).
        if ($rating >= 4 && $sent['label'] !== 'negative') {
            $text = "Thank you so much for the wonderful feedback and for choosing {$prop}. "
                . "We're delighted you enjoyed your stay and we look forward to welcoming you back soon.";
        } elseif ($rating <= 2 || $sent['label'] === 'negative') {
            $text = "Thank you for taking the time to share your experience, and we're sorry your stay at {$prop} "
                . "fell short of expectations. Your comments have been shared with our team and we would welcome the "
                . "chance to make it right on a future visit.";
        } else {
            $text = "Thank you for staying at {$prop} and for your feedback. We appreciate you highlighting what worked "
                . "well and what we can improve, and we hope to host you again.";
        }
        return ['text' => $text, 'source' => 'template'];
    }
}
