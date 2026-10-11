<?php
// FILE: app/lib/supplier_nightaudit_ext.php
// MODULE 10 (night audit & business-day close → 360): daily revenue report, cashier
// reconciliation, payment reconciliation, and a logged REOPEN control — on top of the
// existing night_audit_run / history / exceptions (supplier_night_audit.php). All reads
// are property-scoped; the caller authorizes the stay. NOT runtime-verified.

if (!function_exists('na_daily_revenue')) {
    /**
     * DAILY REVENUE REPORT for a business date: the night-audit snapshot's room revenue
     * (ADR/RevPAR/occupancy) + folio extras/tax posted that day + F&B (POS) revenue
     * settled that day. Read-only, grouped so the owner sees the day's economics.
     * Returns ['rooms','extras','tax','fnb','total','adr','revpar','occupancy','snapshot'].
     */
    function na_daily_revenue($db, int $stayId, string $date): array
    {
        $out = ['rooms' => 0.0, 'extras' => 0.0, 'tax' => 0.0, 'fnb' => 0.0, 'total' => 0.0,
                'adr' => 0.0, 'revpar' => 0.0, 'occupancy' => null, 'snapshot' => null];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        try {
            // Room economics from the night-audit snapshot (already per-night-prorated).
            $snap = $db->get('stays_night_audits', '*', ['stay_id' => $stayId, 'business_date' => $date]);
            if ($snap) {
                $out['snapshot'] = $snap;
                $out['rooms']     = round((float) ($snap['room_revenue'] ?? 0), 2);
                $out['adr']       = round((float) ($snap['adr'] ?? 0), 2);
                $out['revpar']    = round((float) ($snap['revpar'] ?? 0), 2);
                $out['occupancy'] = $snap['occupancy_pct'] !== null ? (float) $snap['occupancy_pct'] : null;
            }
            // Folio extras + tax posted on the date (non-void), for this property's folios.
            $folioIds = [];
            foreach ($db->select('stays_folios', ['id'], ['stay_id' => $stayId]) ?: [] as $f) { $folioIds[] = (int) $f['id']; }
            if ($folioIds) {
                $items = $db->select('stays_folio_items', ['type', 'amount', 'void', 'created_at'],
                    ['folio_id' => $folioIds]) ?: [];
                foreach ($items as $it) {
                    if ((int) ($it['void'] ?? 0) === 1) { continue; }
                    if (substr((string) ($it['created_at'] ?? ''), 0, 10) !== $date) { continue; }
                    $amt = round((float) $it['amount'], 2);
                    if (in_array((string) $it['type'], ['extra', 'charge', 'debit_note'], true)) { $out['extras'] += $amt; }
                    elseif ((string) $it['type'] === 'tax') { $out['tax'] += $amt; }
                    elseif ((string) $it['type'] === 'credit_note') { $out['extras'] -= $amt; }
                }
            }
            // F&B revenue: POS orders for this property settled on the date.
            $orders = $db->select('pos_orders', ['id'], ['stay_id' => $stayId, 'status' => 'settled']) ?: [];
            $orderIds = array_map(fn($o) => (int) $o['id'], $orders);
            if ($orderIds) {
                $pays = $db->select('pos_payments', ['amount', 'created_at'], ['order_id' => $orderIds]) ?: [];
                foreach ($pays as $p) {
                    if (substr((string) ($p['created_at'] ?? ''), 0, 10) === $date) { $out['fnb'] += round((float) $p['amount'], 2); }
                }
            }
            $out['rooms'] = round($out['rooms'], 2); $out['extras'] = round($out['extras'], 2);
            $out['tax'] = round($out['tax'], 2); $out['fnb'] = round($out['fnb'], 2);
            $out['total'] = round($out['rooms'] + $out['extras'] + $out['tax'] + $out['fnb'], 2);
        } catch (\Throwable $e) { error_log('na_daily_revenue: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('na_cashier_reconciliation')) {
    /**
     * CASHIER RECONCILIATION for a date: the cashier shifts closed that day for the org,
     * with their expected vs counted and over/short. Read-only. Returns
     * ['shifts'=>[...],'expected'=>float,'counted'=>float,'over_short'=>float].
     */
    function na_cashier_reconciliation($db, int $orgId, string $date): array
    {
        $out = ['shifts' => [], 'expected' => 0.0, 'counted' => 0.0, 'over_short' => 0.0];
        if ($orgId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        try {
            $shifts = $db->select('cashier_shifts',
                ['id', 'user_id', 'station', 'opening_float', 'closing_counted', 'expected_amount', 'over_short', 'closed_at'],
                ['org_id' => $orgId, 'status' => 'closed']) ?: [];
            foreach ($shifts as $s) {
                if (substr((string) ($s['closed_at'] ?? ''), 0, 10) !== $date) { continue; }
                $out['shifts'][] = $s;
                $out['expected']   = round($out['expected'] + (float) ($s['expected_amount'] ?? 0), 2);
                $out['counted']    = round($out['counted'] + (float) ($s['closing_counted'] ?? 0), 2);
                $out['over_short'] = round($out['over_short'] + (float) ($s['over_short'] ?? 0), 2);
            }
        } catch (\Throwable $e) { error_log('na_cashier_reconciliation: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('na_payment_reconciliation')) {
    /**
     * PAYMENT RECONCILIATION for a date: folio payments + POS payments taken that day for
     * the property, so the desk can tie out the day's collections. Read-only. Returns
     * ['folio_payments'=>float,'pos_payments'=>float,'total'=>float,'count'=>int].
     */
    function na_payment_reconciliation($db, int $stayId, string $date): array
    {
        $out = ['folio_payments' => 0.0, 'pos_payments' => 0.0, 'total' => 0.0, 'count' => 0];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        try {
            $folioIds = [];
            foreach ($db->select('stays_folios', ['id'], ['stay_id' => $stayId]) ?: [] as $f) { $folioIds[] = (int) $f['id']; }
            if ($folioIds) {
                foreach ($db->select('stays_folio_items', ['type', 'amount', 'void', 'created_at'], ['folio_id' => $folioIds]) ?: [] as $it) {
                    if ((int) ($it['void'] ?? 0) === 1) { continue; }
                    if (substr((string) ($it['created_at'] ?? ''), 0, 10) !== $date) { continue; }
                    if ((string) $it['type'] === 'payment') { $out['folio_payments'] += round((float) $it['amount'], 2); $out['count']++; }
                    elseif ((string) $it['type'] === 'refund') { $out['folio_payments'] -= round((float) $it['amount'], 2); }
                }
            }
            $orders = $db->select('pos_orders', ['id'], ['stay_id' => $stayId, 'status' => 'settled']) ?: [];
            $orderIds = array_map(fn($o) => (int) $o['id'], $orders);
            if ($orderIds) {
                foreach ($db->select('pos_payments', ['amount', 'created_at'], ['order_id' => $orderIds]) ?: [] as $p) {
                    if (substr((string) ($p['created_at'] ?? ''), 0, 10) === $date) { $out['pos_payments'] += round((float) $p['amount'], 2); $out['count']++; }
                }
            }
            $out['folio_payments'] = round($out['folio_payments'], 2);
            $out['pos_payments'] = round($out['pos_payments'], 2);
            $out['total'] = round($out['folio_payments'] + $out['pos_payments'], 2);
        } catch (\Throwable $e) { error_log('na_payment_reconciliation: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('na_reopen')) {
    /**
     * REOPEN a closed business date (owner-only exception; logged on the snapshot). Marks
     * the snapshot reopened + records who/why. Does NOT reverse the GL (that would be a
     * manual adjustment) — it flags the day for review so a correction can be posted.
     * Returns ['ok','message']. The CALLER must gate this to owners (not staff).
     */
    function na_reopen($db, int $stayId, string $date, string $reason): array
    {
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return ['ok' => false, 'message' => 'Bad input.']; }
        $reason = trim($reason);
        if ($reason === '') { return ['ok' => false, 'message' => 'A reason is required to reopen a closed day.']; }
        try {
            $snap = $db->get('stays_night_audits', ['id', 'reopened'], ['stay_id' => $stayId, 'business_date' => $date]);
            if (!$snap) { return ['ok' => false, 'message' => 'No closed audit for that date.']; }
            $db->update('stays_night_audits', [
                'reopened' => 1, 'reopened_by' => (string) ($_SESSION['user_id'] ?? '') ?: 'owner',
                'reopened_at' => date('Y-m-d H:i:s'), 'reopen_reason' => substr($reason, 0, 255),
            ], ['id' => (int) $snap['id']]);
            // Audit-trail via the shared event bus if present.
            if (function_exists('emit_event')) {
                emit_event($db, 'nightaudit.reopened', ['stay_id' => $stayId, 'date' => $date, 'reason' => $reason]);
            }
            return ['ok' => true, 'message' => 'Business date ' . $date . ' flagged reopened for review.'];
        } catch (\Throwable $e) { error_log('na_reopen: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Could not reopen.']; }
    }
}
