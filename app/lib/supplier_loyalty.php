<?php
// FILE: app/lib/supplier_loyalty.php
// LOYALTY POINTS (Phase 1 inc S30; docs 01 §3 loyalty). Built on the S29 guest key
// (lowercased email) so points follow a returning guest across stays.
//
// An append-only points LEDGER per (org, guest email): earn (on a PAID stay),
// redeem, and manual adjust rows; the balance is the signed sum. Earning is
// idempotent per booking via UNIQUE(org_id, invoice_id) + a locked pre-check (an
// invoice can earn points exactly once). Earn rate is per-org config
// (points_per_currency). Tiers are derived from lifetime earned points (read-only).
//
// This NEVER touches money — points are a soft currency in their own ledger, with
// no automatic cash value. Redemption here records a redeem row (negative points);
// applying a redemption as a discount on a folio is the caller's choice, not done
// implicitly. Org ownership is enforced by the caller routes.

if (!function_exists('loyalty_config')) {
    /** Per-org loyalty config: ['enabled'=>bool,'points_per_currency'=>float].
     *  Defaults: disabled, 1 point per 1 currency unit. */
    function loyalty_config($db, int $orgId): array
    {
        $out = ['enabled' => false, 'points_per_currency' => 1.0];
        if ($orgId <= 0) { return $out; }
        try {
            $row = $db->get('stays_loyalty_config', ['enabled', 'points_per_currency'], ['org_id' => $orgId]);
            if ($row) {
                $out['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
                $out['points_per_currency'] = (float) ($row['points_per_currency'] ?? 1);
            }
        } catch (\Throwable $e) { error_log('loyalty_config: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('loyalty_config_save')) {
    /** Upsert the per-org loyalty config. Returns bool. */
    function loyalty_config_save($db, int $orgId, bool $enabled, float $rate): bool
    {
        if ($orgId <= 0) { return false; }
        $rate = max(0.0, round($rate, 4));
        try {
            $fields = ['enabled' => $enabled ? 1 : 0, 'points_per_currency' => $rate, 'updated_at' => date('Y-m-d H:i:s')];
            if ($db->has('stays_loyalty_config', ['org_id' => $orgId])) {
                $db->update('stays_loyalty_config', $fields, ['org_id' => $orgId]);
            } else {
                $db->insert('stays_loyalty_config', array_merge($fields, ['org_id' => $orgId, 'created_at' => date('Y-m-d H:i:s')]));
            }
            return true;
        } catch (\Throwable $e) { error_log('loyalty_config_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('loyalty_tiers')) {
    /** Tier thresholds by LIFETIME earned points (asc). Config, not DB. */
    function loyalty_tiers(): array
    {
        return [ 0 => 'Member', 1000 => 'Silver', 5000 => 'Gold', 15000 => 'Platinum' ];
    }
}
if (!function_exists('loyalty_tier_for')) {
    function loyalty_tier_for(int $lifetimePoints): string
    {
        $tier = 'Member';
        foreach (loyalty_tiers() as $threshold => $name) { if ($lifetimePoints >= $threshold) { $tier = $name; } }
        return $tier;
    }
}

if (!function_exists('loyalty_accrue_for_booking')) {
    /**
     * Idempotently earn points for a PAID own-inventory stay. Points =
     * round(price_markup × org.points_per_currency). One earn row per (org, invoice)
     * via UNIQUE + a locked pre-check. No-op if loyalty disabled, booking not
     * paid/own-inventory, no guest email, or already accrued. Returns ['ok','points','already'].
     */
    function loyalty_accrue_for_booking($db, int $orgId, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($orgId <= 0 || $invoiceId === '') { return ['ok' => false, 'message' => 'Bad input']; }
        $cfg = loyalty_config($db, $orgId);
        if (!$cfg['enabled']) { return ['ok' => false, 'message' => 'Loyalty disabled']; }

        try {
            $b = $db->get('bookings', ['invoice_id', 'module_type', 'payment_status', 'booking_status', 'price_markup', 'email', 'first_name', 'last_name'],
                ['invoice_id' => $invoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Booking not found']; }
            if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay']; }
            if (strtolower((string) $b['payment_status']) !== 'paid') { return ['ok' => false, 'message' => 'Not paid']; }
            if (strtolower((string) $b['booking_status']) === 'cancelled') { return ['ok' => false, 'message' => 'Cancelled']; }
            $email = function_exists('guest_email_key') ? guest_email_key($b['email'] ?? '') : strtolower(trim((string) ($b['email'] ?? '')));
            if ($email === '') { return ['ok' => false, 'message' => 'No guest email']; }

            $points = (int) round(((float) $b['price_markup']) * $cfg['points_per_currency']);
            if ($points <= 0) { return ['ok' => false, 'message' => 'No points earned']; }

            $result = ['ok' => false, 'message' => 'Accrual failed'];
            $db->action(function ($db) use ($orgId, $invoiceId, $email, $points, $b, &$result) {
                $locked = $db->query('SELECT id FROM stays_loyalty_ledger WHERE org_id=:o AND invoice_id=:i FOR UPDATE',
                    [':o' => $orgId, ':i' => $invoiceId]);
                if ($locked && $locked->fetch(\PDO::FETCH_ASSOC)) { $result = ['ok' => true, 'already' => true]; return true; }
                $db->insert('stays_loyalty_ledger', [
                    'org_id'     => $orgId, 'email' => $email, 'type' => 'earn',
                    'points'     => $points, 'invoice_id' => $invoiceId,
                    'reason'     => 'Stay ' . $invoiceId,
                    'created_by' => 'system', 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'already' => false, 'points' => $points];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('loyalty_accrue_for_booking: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Accrual error'];
        }
    }
}

if (!function_exists('loyalty_manual')) {
    /**
     * Owner manual adjust or redeem. $type ∈ adjust|redeem. For 'redeem' $points is the
     * (positive) number to deduct; it is stored NEGATIVE and refused if it would push
     * the balance below zero. 'adjust' may be + or −. Returns ['ok','message'].
     */
    function loyalty_manual($db, int $orgId, string $email, string $type, int $points, string $reason = ''): array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        $type = in_array($type, ['adjust', 'redeem'], true) ? $type : '';
        if ($orgId <= 0 || $email === '' || $type === '' || $points === 0) { return ['ok' => false, 'message' => 'Bad input']; }

        $signed = $type === 'redeem' ? -abs($points) : $points;
        try {
            $result = ['ok' => false, 'message' => 'Failed'];
            $db->action(function ($db) use ($orgId, $email, $type, $signed, $reason, &$result) {
                // Lock this guest's rows to compute a consistent balance for the floor check.
                $locked = $db->query('SELECT COALESCE(SUM(points),0) AS bal FROM stays_loyalty_ledger WHERE org_id=:o AND email=:e FOR UPDATE',
                    [':o' => $orgId, ':e' => $email]);
                $bal = $locked ? (int) ($locked->fetch(\PDO::FETCH_ASSOC)['bal'] ?? 0) : 0;
                if ($bal + $signed < 0) { $result = ['ok' => false, 'message' => 'Insufficient points balance (' . $bal . ').']; return false; }
                $db->insert('stays_loyalty_ledger', [
                    'org_id' => $orgId, 'email' => $email, 'type' => $type,
                    'points' => $signed, 'invoice_id' => null,
                    'reason' => substr(trim($reason), 0, 191) ?: ucfirst($type),
                    'created_by' => (string) ($_SESSION['user_id'] ?? ''), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'message' => ($type === 'redeem' ? 'Redeemed' : 'Adjusted') . ' ' . abs($signed) . ' points.'];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('loyalty_manual: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error'];
        }
    }
}

if (!function_exists('loyalty_guest_summary')) {
    /** For a guest: ['balance'=>int,'lifetime_earned'=>int,'tier'=>string] in an org. */
    function loyalty_guest_summary($db, int $orgId, string $email): array
    {
        $out = ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'Member'];
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return $out; }
        try {
            $out['balance'] = (int) $db->sum('stays_loyalty_ledger', 'points', ['org_id' => $orgId, 'email' => $email]);
            $out['lifetime_earned'] = (int) $db->sum('stays_loyalty_ledger', 'points', ['org_id' => $orgId, 'email' => $email, 'type' => 'earn']);
            $out['tier'] = loyalty_tier_for($out['lifetime_earned']);
        } catch (\Throwable $e) { error_log('loyalty_guest_summary: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('loyalty_guest_ledger')) {
    /** Recent ledger rows for a guest (read-only). */
    function loyalty_guest_ledger($db, int $orgId, string $email, int $limit = 50): array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return []; }
        try {
            return $db->select('stays_loyalty_ledger', ['type', 'points', 'reason', 'invoice_id', 'created_at'],
                ['org_id' => $orgId, 'email' => $email, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('loyalty_guest_ledger: ' . $e->getMessage()); return []; }
    }
}
