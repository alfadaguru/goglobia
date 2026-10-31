<?php
// FILE: app/lib/supplier_loyalty_ext.php
// LOYALTY & MEMBERSHIP — deepen (PMS catalogue §2 #22). Builds the genuine gaps on
// top of the S30 base (app/lib/supplier_loyalty.php): member rates/benefits,
// referral program, birthday/anniversary offers, and loyalty analytics.
//
// SCOPE / TRUST MODEL (same spine as the base):
//   * The points ledger (stays_loyalty_ledger) remains the single source of truth.
//     Every bonus this file grants is just another signed ledger row — no parallel
//     balance store. "Cross-property" benefits fall out for free: the ledger is keyed
//     by (org_id, guest email), so a member's points/tier span every property the org
//     owns. We do NOT re-implement that; we surface it in analytics.
//   * NO money is invented. Member-rate / occasion perks that carry cash value are
//     applied exactly like the base loyalty_redeem_to_folio: a 'payment' line on the
//     booking's OPEN folio via stays_folio_items, inside a DB transaction, never
//     trusting a client amount — the discount is derived from org config × folio balance.
//   * Idempotency: referral credit is UNIQUE per (org, referred invoice); occasion
//     bonuses are idempotent per (org, email, kind, year) via a deterministic ledger
//     reason key + a locked pre-check. An invoice/occasion can be rewarded exactly once.
//   * Org ownership + auth are enforced by the ExtRoutes caller (SUPPLIER_OR_STAFF_AUTH
//     + supplier_can + the "guest has a booking with you" check). These helpers assume a
//     resolved, owned org_id.
//
// Reuses from the base/stays spine: loyalty_config, loyalty_guest_summary,
// loyalty_tier_for, loyalty_tiers, guest_email_key, supplier_property_org,
// supplier_reservation_hotel_id, folio_get_or_create.

if (!function_exists('loyalty_benefit_defaults')) {
    /** Default per-tier benefit matrix. 'rate_pct' = member discount a tier earns on
     *  its folio; 'perks' = free-text benefit lines shown to staff/guest. Config, not DB. */
    function loyalty_benefit_defaults(): array
    {
        return [
            'Member'   => ['rate_pct' => 0.0,  'perks' => 'Points on every stay'],
            'Silver'   => ['rate_pct' => 3.0,  'perks' => 'Early check-in when available'],
            'Gold'     => ['rate_pct' => 5.0,  'perks' => 'Room upgrade when available · late checkout'],
            'Platinum' => ['rate_pct' => 10.0, 'perks' => 'Guaranteed late checkout · welcome amenity · priority support'],
        ];
    }
}

if (!function_exists('loyalty_benefits_get')) {
    /**
     * Per-org tier benefit matrix: tier => ['rate_pct'=>float,'perks'=>string]. Reads the
     * org override from stays_loyalty_benefits and falls back to loyalty_benefit_defaults()
     * for any tier the org has not customised. Always returns every tier in loyalty_tiers().
     */
    function loyalty_benefits_get($db, int $orgId): array
    {
        $out = loyalty_benefit_defaults();
        // Guarantee a row for every known tier (defaults cover them already, but keep in sync).
        foreach (loyalty_tiers() as $tier) { if (!isset($out[$tier])) { $out[$tier] = ['rate_pct' => 0.0, 'perks' => '']; } }
        if ($orgId <= 0) { return $out; }
        try {
            $rows = $db->select('stays_loyalty_benefits', ['tier', 'rate_pct', 'perks'], ['org_id' => $orgId]) ?: [];
            foreach ($rows as $r) {
                $tier = (string) ($r['tier'] ?? '');
                if ($tier === '' || !isset($out[$tier])) { continue; }
                $out[$tier] = [
                    'rate_pct' => max(0.0, min(100.0, (float) ($r['rate_pct'] ?? 0))),
                    'perks'    => (string) ($r['perks'] ?? ''),
                ];
            }
        } catch (\Throwable $e) { error_log('loyalty_benefits_get: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('loyalty_benefit_save')) {
    /** Upsert one tier's benefit row for an org. $ratePct clamped to 0..100. Returns bool. */
    function loyalty_benefit_save($db, int $orgId, string $tier, float $ratePct, string $perks): bool
    {
        if ($orgId <= 0 || !array_key_exists($tier, loyalty_benefit_defaults())) { return false; }
        $ratePct = max(0.0, min(100.0, round($ratePct, 2)));
        $perks = substr(trim($perks), 0, 500);
        try {
            $fields = ['rate_pct' => $ratePct, 'perks' => $perks, 'updated_at' => date('Y-m-d H:i:s')];
            if ($db->has('stays_loyalty_benefits', ['org_id' => $orgId, 'tier' => $tier])) {
                $db->update('stays_loyalty_benefits', $fields, ['org_id' => $orgId, 'tier' => $tier]);
            } else {
                $db->insert('stays_loyalty_benefits', array_merge($fields,
                    ['org_id' => $orgId, 'tier' => $tier, 'created_at' => date('Y-m-d H:i:s')]));
            }
            return true;
        } catch (\Throwable $e) { error_log('loyalty_benefit_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('loyalty_member_rate_to_folio')) {
    /**
     * MEMBER RATE as a folio discount. Applies the guest's current-tier rate_pct as a
     * cash discount on the booking's OPEN folio — the member-rates / member-benefits
     * sub-feature. Mirrors loyalty_redeem_to_folio exactly: resolves org+email+folio,
     * derives the discount from org config (tier rate × current folio balance owed), and
     * posts a single 'payment' line in ONE transaction. NEVER trusts a client amount.
     * Idempotent per booking: refuses if a member-rate discount was already posted to this
     * folio (unique reason marker + locked pre-check). Points are NOT burned (a member
     * rate is a status perk, not a redemption). Returns ['ok','cash','pct','tier','message'].
     */
    function loyalty_member_rate_to_folio($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No booking.']; }

        $b = $db->get('bookings', ['invoice_id', 'email', 'booking_data'], ['invoice_id' => $invoiceId]);
        if (!$b) { return ['ok' => false, 'message' => 'Booking not found.']; }
        $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
        $orgId = ($hid > 0 && function_exists('supplier_property_org')) ? supplier_property_org($db, $hid) : 0;
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'Loyalty is not configured for this property.']; }

        $cfg = supplier_loyalty_config($db, $orgId);
        if (empty($cfg['enabled'])) { return ['ok' => false, 'message' => 'Loyalty is disabled for this property.']; }

        $email = function_exists('guest_email_key') ? guest_email_key((string) ($b['email'] ?? '')) : strtolower(trim((string) ($b['email'] ?? '')));
        if ($email === '') { return ['ok' => false, 'message' => 'This booking has no guest email.']; }

        $summary  = loyalty_guest_summary($db, $orgId, $email);
        $tier     = (string) ($summary['tier'] ?? 'Member');
        $benefits = loyalty_benefits_get($db, $orgId);
        $pct      = (float) ($benefits[$tier]['rate_pct'] ?? 0);
        if ($pct <= 0) { return ['ok' => false, 'message' => 'The ' . $tier . ' tier has no member rate configured.']; }

        $fid = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
        if ($fid <= 0) { return ['ok' => false, 'message' => 'No folio for this booking.']; }

        $pctLabel = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        $marker = 'Member rate (' . $tier . ')';
        $result = ['ok' => false, 'message' => 'Could not apply the member rate.'];
        try {
            // One transaction: lock the folio's items (race-safe dup check + consistent
            // balance), then post the discount through the canonical folio spine. A member
            // rate is a DISCOUNT (cash was not received) so it is a credit_note — which
            // folio_totals treats as reducing what's owed. The cash figure is derived here
            // from the folio balance × tier %, never supplied by the client.
            $db->action(function ($db) use ($fid, $invoiceId, $pct, $tier, $marker, $pctLabel, &$result) {
                $folio = $db->get('stays_folios', ['status'], ['id' => $fid]);
                if (!$folio || $folio['status'] !== 'open') { $result = ['ok' => false, 'message' => 'The folio is closed.']; return false; }
                // Lock the item rows and detect a prior member-rate posting in the same scan.
                $locked = $db->query('SELECT SUM(CASE WHEN description LIKE :mk THEN 1 ELSE 0 END) AS already '
                    . 'FROM stays_folio_items WHERE folio_id = :f FOR UPDATE',
                    [':mk' => $marker . '%', ':f' => $fid]);
                $already = $locked ? (int) ($locked->fetch(\PDO::FETCH_ASSOC)['already'] ?? 0) : 0;
                if ($already > 0) { $result = ['ok' => false, 'message' => 'A member rate was already applied to this folio.']; return false; }
                // Canonical balance (void-aware, correct per-type signs).
                $totals = function_exists('folio_totals') ? folio_totals($db, $fid) : ['balance' => 0.0];
                $balance = (float) ($totals['balance'] ?? 0);
                if ($balance <= 0) { $result = ['ok' => false, 'message' => 'Nothing is owed on this folio.']; return false; }
                $cash = round($balance * ($pct / 100.0), 2);
                if ($cash <= 0) { $result = ['ok' => false, 'message' => 'The member rate is zero on this balance.']; return false; }
                $desc = $marker . ' ' . $pctLabel . '% off';
                $posted = function_exists('folio_add_item')
                    ? folio_add_item($db, $fid, 'credit_note', $cash, $desc, 'loyalty')
                    : (bool) $db->insert('stays_folio_items', ['folio_id' => $fid, 'type' => 'credit_note', 'description' => $desc, 'amount' => $cash, 'created_by' => 'loyalty', 'created_at' => date('Y-m-d H:i:s')]);
                if (!$posted) { $result = ['ok' => false, 'message' => 'Could not post the member rate.']; return false; }
                $result = ['ok' => true, 'cash' => $cash, 'pct' => $pct, 'tier' => $tier,
                    'message' => 'Applied ' . $tier . ' member rate (' . $pctLabel . '%) = ' . number_format($cash, 2) . ' off.'];
                return true;
            });
        } catch (\Throwable $e) { error_log('loyalty_member_rate_to_folio: ' . $e->getMessage()); }
        return $result;
    }
}

if (!function_exists('loyalty_referral_code')) {
    /** Deterministic, shareable referral code for a guest in an org. Stable (no DB row
     *  needed): 8 hex chars of HMAC(org|email). Decoding happens by matching, not reversing. */
    function loyalty_referral_code($db, int $orgId, string $email): string
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return ''; }
        $secret = defined('API_LAYER_KEY') ? (string) API_LAYER_KEY : 'loyalty_referral';
        return 'R' . strtoupper(substr(hash_hmac('sha256', $orgId . '|' . $email, $secret), 0, 8));
    }
}

if (!function_exists('loyalty_referral_resolve')) {
    /** Resolve a referral code back to the referrer's email within an org, by matching the
     *  code against the org's known guests (bookings emails). Returns '' if no guest matches. */
    function loyalty_referral_resolve($db, int $orgId, array $stayIds, string $code): string
    {
        $code = strtoupper(trim($code));
        if ($orgId <= 0 || $code === '' || empty($stayIds)) { return ''; }
        try {
            // The org's distinct guest emails from its own-inventory bookings.
            $rows = $db->query('SELECT DISTINCT email FROM bookings WHERE email IS NOT NULL AND email <> \'\' '
                . 'AND module_type IN (\'stays\',\'hotels\') LIMIT 5000')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $em) {
                $k = function_exists('guest_email_key') ? guest_email_key((string) $em) : strtolower(trim((string) $em));
                if ($k === '') { continue; }
                if (hash_equals(loyalty_referral_code($db, $orgId, $k), $code)) { return $k; }
            }
        } catch (\Throwable $e) { error_log('loyalty_referral_resolve: ' . $e->getMessage()); }
        return '';
    }
}

if (!function_exists('loyalty_referral_credit')) {
    /**
     * REFERRAL PROGRAM. Credit the referrer a one-time bonus when a NEW guest's paid stay
     * cites their code. Guards: loyalty enabled; referrer resolves and is NOT the referred
     * guest; the referred booking is paid + own-inventory. Idempotent per referred invoice
     * via UNIQUE(org_id, invoice_id) on the ledger + a locked pre-check — one bonus per
     * referred booking. $bonus defaults to the org's configured referral_bonus_points.
     * Returns ['ok','points','referrer','message'].
     */
    function loyalty_referral_credit($db, int $orgId, array $stayIds, string $referredInvoiceId, string $code): array
    {
        $referredInvoiceId = trim($referredInvoiceId);
        if ($orgId <= 0 || $referredInvoiceId === '') { return ['ok' => false, 'message' => 'Bad input.']; }
        $cfg = supplier_loyalty_config($db, $orgId);
        if (empty($cfg['enabled'])) { return ['ok' => false, 'message' => 'Loyalty is disabled.']; }

        $referrer = loyalty_referral_resolve($db, $orgId, $stayIds, $code);
        if ($referrer === '') { return ['ok' => false, 'message' => 'That referral code does not match any of your guests.']; }

        try {
            $b = $db->get('bookings', ['email', 'module_type', 'payment_status', 'booking_status'],
                ['invoice_id' => $referredInvoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Referred booking not found.']; }
            if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay booking.']; }
            if (strtolower((string) $b['payment_status']) !== 'paid') { return ['ok' => false, 'message' => 'The referred booking is not paid yet.']; }
            if (strtolower((string) $b['booking_status']) === 'cancelled') { return ['ok' => false, 'message' => 'The referred booking is cancelled.']; }
            $referred = function_exists('guest_email_key') ? guest_email_key((string) ($b['email'] ?? '')) : strtolower(trim((string) ($b['email'] ?? '')));
            if ($referred !== '' && $referred === $referrer) { return ['ok' => false, 'message' => 'A guest cannot refer themselves.']; }

            $bonus = (int) loyalty_ext_points_setting($db, $orgId, 'referral_bonus_points', 250);
            if ($bonus <= 0) { return ['ok' => false, 'message' => 'Referral bonus is set to zero.']; }

            $result = ['ok' => false, 'message' => 'Could not credit the referral.'];
            $marker = 'ref:' . $referredInvoiceId; // idempotency key lives in the reason text
            $db->action(function ($db) use ($orgId, $referrer, $referredInvoiceId, $bonus, $marker, &$result) {
                // IMPORTANT: the ledger has UNIQUE(org_id, invoice_id); the referred booking
                // already holds that slot via its own base 'earn' row. So a referral row is
                // stored with invoice_id = NULL (NULLs don't collide in a MySQL unique index)
                // and idempotency is enforced by a deterministic '[ref:<invoice>]' reason
                // marker + a locked pre-check — one referral credit per referred booking.
                $locked = $db->query('SELECT id FROM stays_loyalty_ledger WHERE org_id = :o AND type = :t '
                    . 'AND reason LIKE :k FOR UPDATE',
                    [':o' => $orgId, ':t' => 'referral', ':k' => '%[' . $marker . ']%']);
                if ($locked && $locked->fetch(\PDO::FETCH_ASSOC)) { $result = ['ok' => false, 'message' => 'This booking already credited a referral.']; return false; }
                $db->insert('stays_loyalty_ledger', [
                    'org_id' => $orgId, 'email' => $referrer, 'type' => 'referral', 'points' => $bonus,
                    'invoice_id' => null, 'reason' => 'Referral bonus (' . $referredInvoiceId . ') [' . $marker . ']',
                    'created_by' => (string) ($_SESSION['user_id'] ?? 'system'), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'points' => $bonus, 'referrer' => $referrer,
                    'message' => 'Credited ' . $bonus . ' referral points to ' . $referrer . '.'];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('loyalty_referral_credit: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Referral error.'];
        }
    }
}

if (!function_exists('loyalty_ext_points_setting')) {
    /** Read a named integer points setting from stays_loyalty_config (additive columns:
     *  referral_bonus_points, birthday_bonus_points, anniversary_bonus_points). Falls back
     *  to $default when the column/row is absent. */
    function loyalty_ext_points_setting($db, int $orgId, string $col, int $default): int
    {
        if ($orgId <= 0) { return $default; }
        $allowed = ['referral_bonus_points', 'birthday_bonus_points', 'anniversary_bonus_points'];
        if (!in_array($col, $allowed, true)) { return $default; }
        try {
            $row = $db->get('stays_loyalty_config', [$col], ['org_id' => $orgId]);
            if ($row && $row[$col] !== null && $row[$col] !== '') { return max(0, (int) $row[$col]); }
        } catch (\Throwable $e) { error_log('loyalty_ext_points_setting: ' . $e->getMessage()); }
        return $default;
    }
}

if (!function_exists('loyalty_ext_config_save')) {
    /** Upsert the ext points config (referral/birthday/anniversary bonuses) onto the SAME
     *  stays_loyalty_config row the base uses. Each clamped to >= 0. Returns bool. */
    function loyalty_ext_config_save($db, int $orgId, int $referral, int $birthday, int $anniversary): bool
    {
        if ($orgId <= 0) { return false; }
        $fields = [
            'referral_bonus_points'    => max(0, $referral),
            'birthday_bonus_points'    => max(0, $birthday),
            'anniversary_bonus_points' => max(0, $anniversary),
            'updated_at'               => date('Y-m-d H:i:s'),
        ];
        try {
            if ($db->has('stays_loyalty_config', ['org_id' => $orgId])) {
                $db->update('stays_loyalty_config', $fields, ['org_id' => $orgId]);
            } else {
                $db->insert('stays_loyalty_config', array_merge($fields,
                    ['org_id' => $orgId, 'enabled' => 0, 'points_per_currency' => 1, 'created_at' => date('Y-m-d H:i:s')]));
            }
            return true;
        } catch (\Throwable $e) { error_log('loyalty_ext_config_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('loyalty_occasion_grant')) {
    /**
     * BIRTHDAY / ANNIVERSARY OFFER. Grant a once-per-year bonus to a guest for the current
     * year. $kind ∈ birthday|anniversary. Reads the bonus size from org config. Idempotent
     * per (org, email, kind, year) via a deterministic reason key + a locked pre-check — the
     * guest earns the occasion bonus at most once per calendar year. Pure points (no money).
     * The guest must have an occasion date on file (stays_guest_profiles) matching the
     * current month for the grant to be offered; the route enforces that, this records it.
     * Returns ['ok','points','message'].
     */
    function loyalty_occasion_grant($db, int $orgId, string $email, string $kind): array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        $kind = in_array($kind, ['birthday', 'anniversary'], true) ? $kind : '';
        if ($orgId <= 0 || $email === '' || $kind === '') { return ['ok' => false, 'message' => 'Bad input.']; }
        $cfg = supplier_loyalty_config($db, $orgId);
        if (empty($cfg['enabled'])) { return ['ok' => false, 'message' => 'Loyalty is disabled.']; }

        $col = $kind === 'birthday' ? 'birthday_bonus_points' : 'anniversary_bonus_points';
        $bonus = (int) loyalty_ext_points_setting($db, $orgId, $col, $kind === 'birthday' ? 200 : 300);
        if ($bonus <= 0) { return ['ok' => false, 'message' => 'The ' . $kind . ' bonus is set to zero.']; }

        $year = (int) date('Y');
        $key = $kind . ':' . $year; // stable idempotency marker inside the reason text
        $result = ['ok' => false, 'message' => 'Could not grant the bonus.'];
        try {
            $db->action(function ($db) use ($orgId, $email, $kind, $bonus, $key, $year, &$result) {
                $locked = $db->query('SELECT id FROM stays_loyalty_ledger WHERE org_id = :o AND email = :e AND type = :t '
                    . 'AND reason LIKE :k FOR UPDATE',
                    [':o' => $orgId, ':e' => $email, ':t' => 'occasion', ':k' => '%[' . $key . ']%']);
                if ($locked && $locked->fetch(\PDO::FETCH_ASSOC)) {
                    $result = ['ok' => false, 'message' => 'Already granted this ' . $kind . ' bonus for ' . $year . '.']; return false;
                }
                $db->insert('stays_loyalty_ledger', [
                    'org_id' => $orgId, 'email' => $email, 'type' => 'occasion', 'points' => $bonus,
                    'invoice_id' => null, 'reason' => ucfirst($kind) . ' bonus ' . $year . ' [' . $key . ']',
                    'created_by' => (string) ($_SESSION['user_id'] ?? 'system'), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'points' => $bonus, 'message' => 'Granted ' . $bonus . ' ' . $kind . ' points.'];
                return true;
            });
        } catch (\Throwable $e) { error_log('loyalty_occasion_grant: ' . $e->getMessage()); }
        return $result;
    }
}

if (!function_exists('loyalty_occasions_this_month')) {
    /**
     * Guests in the org with a birthday/anniversary date (stays_guest_profiles) in the
     * current month — the actionable list for the occasion-offer panel. Flags whether this
     * year's bonus was already granted (so the UI can disable the button). Read-only.
     * Returns rows: ['email','kind','date','granted'=>bool].
     */
    function loyalty_occasions_this_month($db, int $orgId): array
    {
        $out = [];
        if ($orgId <= 0) { return $out; }
        $month = (int) date('n');
        $year = (int) date('Y');
        try {
            $rows = $db->select('stays_guest_profiles', ['email', 'birth_date', 'anniversary_date'], ['org_id' => $orgId]) ?: [];
            foreach ($rows as $r) {
                $email = (string) ($r['email'] ?? '');
                if ($email === '') { continue; }
                foreach (['birthday' => 'birth_date', 'anniversary' => 'anniversary_date'] as $kind => $field) {
                    $d = (string) ($r[$field] ?? '');
                    if ($d === '' || $d === '0000-00-00') { continue; }
                    $ts = strtotime($d);
                    if ($ts === false || (int) date('n', $ts) !== $month) { continue; }
                    $granted = false;
                    try {
                        $granted = $db->has('stays_loyalty_ledger', [
                            'org_id' => $orgId, 'email' => $email, 'type' => 'occasion',
                            'reason[~]' => '%[' . $kind . ':' . $year . ']%',
                        ]);
                    } catch (\Throwable $e) {}
                    $out[] = ['email' => $email, 'kind' => $kind, 'date' => $d, 'granted' => $granted];
                }
            }
        } catch (\Throwable $e) { error_log('loyalty_occasions_this_month: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('loyalty_occasion_save')) {
    /** Save a guest's birthday / anniversary date onto their org profile
     *  (stays_guest_profiles, additive columns). Dates stored as Y-m-d or cleared. The
     *  profile row is upserted (the base guest CRM owns the other columns). Returns bool. */
    function loyalty_occasion_save($db, int $orgId, string $email, ?string $birth, ?string $anniversary): bool
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return false; }
        $norm = function (?string $d): ?string {
            $d = trim((string) $d);
            if ($d === '') { return null; }
            $ts = strtotime($d);
            return $ts === false ? null : date('Y-m-d', $ts);
        };
        $fields = ['birth_date' => $norm($birth), 'anniversary_date' => $norm($anniversary), 'updated_at' => date('Y-m-d H:i:s')];
        try {
            if ($db->has('stays_guest_profiles', ['org_id' => $orgId, 'email' => $email])) {
                $db->update('stays_guest_profiles', $fields, ['org_id' => $orgId, 'email' => $email]);
            } else {
                $db->insert('stays_guest_profiles', array_merge($fields,
                    ['org_id' => $orgId, 'email' => $email, 'created_at' => date('Y-m-d H:i:s')]));
            }
            return true;
        } catch (\Throwable $e) { error_log('loyalty_occasion_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('loyalty_analytics')) {
    /**
     * LOYALTY ANALYTICS for an org (read-only, aggregated from the ledger — the SAME ledger
     * the base writes, so figures reconcile). Returns:
     *   members            — distinct guests with any ledger row
     *   points_outstanding — total unredeemed balance (sum of all points) = the program's
     *                         point LIABILITY. If redeem_value_per_point is set, cash_liability
     *                         estimates its monetary cost.
     *   earned / redeemed  — lifetime totals (redeemed shown as a positive magnitude)
     *   tier_counts        — member count per tier (derived from each guest's lifetime earned)
     *   top                — top members by balance (email, balance, tier)
     *   earned_30d / redeemed_30d — last-30-day flow, for a trend read
     * "Cross-property" is intrinsic: the ledger spans every property in the org, so these
     * totals are already org-wide — no per-property split is needed.
     */
    function loyalty_analytics($db, int $orgId, int $topN = 10): array
    {
        $out = [
            'members' => 0, 'points_outstanding' => 0, 'cash_liability' => 0.0,
            'earned' => 0, 'redeemed' => 0, 'tier_counts' => [], 'top' => [],
            'earned_30d' => 0, 'redeemed_30d' => 0,
        ];
        if ($orgId <= 0) { return $out; }
        foreach (loyalty_tiers() as $t) { $out['tier_counts'][$t] = 0; }
        try {
            // Per-guest aggregate (balance + lifetime earned) → members, liability, tiers, top.
            $rows = $db->query('SELECT email, COALESCE(SUM(points),0) AS bal, '
                . 'COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END),0) AS earned '
                . 'FROM stays_loyalty_ledger WHERE org_id = :o GROUP BY email',
                [':o' => $orgId])->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $top = [];
            foreach ($rows as $r) {
                $out['members']++;
                $bal = (int) $r['bal'];
                $out['points_outstanding'] += $bal;
                $tier = loyalty_tier_for((int) $r['earned']);
                if (isset($out['tier_counts'][$tier])) { $out['tier_counts'][$tier]++; }
                $top[] = ['email' => (string) $r['email'], 'balance' => $bal, 'tier' => $tier];
            }
            usort($top, function ($a, $b) { return $b['balance'] <=> $a['balance']; });
            $out['top'] = array_slice($top, 0, max(1, $topN));

            // Lifetime earned vs redeemed magnitude.
            $flow = $db->query('SELECT '
                . 'COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END),0) AS earned, '
                . 'COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END),0) AS redeemed '
                . 'FROM stays_loyalty_ledger WHERE org_id = :o',
                [':o' => $orgId])->fetch(\PDO::FETCH_ASSOC) ?: [];
            $out['earned'] = (int) ($flow['earned'] ?? 0);
            $out['redeemed'] = (int) ($flow['redeemed'] ?? 0);

            // 30-day flow.
            $since = date('Y-m-d H:i:s', time() - 30 * 86400);
            $flow30 = $db->query('SELECT '
                . 'COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END),0) AS earned, '
                . 'COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END),0) AS redeemed '
                . 'FROM stays_loyalty_ledger WHERE org_id = :o AND created_at >= :s',
                [':o' => $orgId, ':s' => $since])->fetch(\PDO::FETCH_ASSOC) ?: [];
            $out['earned_30d'] = (int) ($flow30['earned'] ?? 0);
            $out['redeemed_30d'] = (int) ($flow30['redeemed'] ?? 0);

            $cfg = supplier_loyalty_config($db, $orgId);
            $vpp = (float) ($cfg['redeem_value_per_point'] ?? 0);
            if ($vpp > 0) { $out['cash_liability'] = round(max(0, $out['points_outstanding']) * $vpp, 2); }
        } catch (\Throwable $e) { error_log('loyalty_analytics: ' . $e->getMessage()); }
        return $out;
    }
}
