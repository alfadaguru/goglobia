<?php
// FILE: app/lib/supplier_laundry_ext.php
// MODULE 25 (Laundry & linen) DEEPENING. The base (supplier_facilities.php) already
// covers the laundry BATCH lifecycle: laundry_add / laundry_set_status / laundry_list
// (collected → in_wash → ready → delivered) and a per-batch `charge` column. This file
// adds the genuine spec gaps on TOP of that, in NEW tables only:
//   • linen CATALOGUE + PAR-LEVEL management        → stays_linen_items
//   • issue/return stock cycle + LOST/DAMAGED writeoff → stays_linen_moves
//   • external laundry VENDOR register (DB-only)     → stays_laundry_vendors
//   • guest-laundry → FOLIO posting (money spine)    → reuses folio_add_item (DB-GATED)
//   • par-shortfall + laundry COST report (read-only)
//
// Scope/auth: the CALLER authorizes the stay via supplier_can(...) BEFORE calling these;
// these functions still re-scope every write by stay_id (defence in depth). All are
// defensive/non-fatal (try/catch + error_log), matching house style. NOT runtime-verified
// (no MySQL in the build env) — logic-reasoned + php -l only.

// ---------------------------------------------------------------------------
// LINEN CATALOGUE + PAR LEVELS  (stays_linen_items)
// ---------------------------------------------------------------------------
if (!function_exists('linen_item_add')) {
    /** Create/register a linen catalogue item with a par level and opening on-hand.
     *  Returns id (0 on failure). Property-scoped. */
    function linen_item_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $onHand = isset($d['on_hand']) && $d['on_hand'] !== '' ? max(0, (int) $d['on_hand']) : 0;
            $par    = isset($d['par_level']) && $d['par_level'] !== '' ? max(0, (int) $d['par_level']) : 0;
            $cost   = ($d['unit_cost'] ?? '') !== '' ? max(0, round((float) $d['unit_cost'], 2)) : null;
            $db->insert('stays_linen_items', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'name'       => substr($name, 0, 120),
                'category'   => substr(trim((string) ($d['category'] ?? '')), 0, 60) ?: null,
                'par_level'  => $par,
                'on_hand'    => $onHand,
                'unit_cost'  => $cost,
                'active'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('linen_item_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('linen_item_update')) {
    /** Edit par level / unit cost / active flag of a catalogue item. Property-scoped.
     *  on_hand is NOT editable here — stock changes only through linen_move(). */
    function linen_item_update($db, int $id, int $stayId, array $d): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_linen_items', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $set = [];
            if (array_key_exists('par_level', $d) && $d['par_level'] !== '') { $set['par_level'] = max(0, (int) $d['par_level']); }
            if (array_key_exists('unit_cost', $d)) { $set['unit_cost'] = $d['unit_cost'] !== '' ? max(0, round((float) $d['unit_cost'], 2)) : null; }
            if (array_key_exists('category', $d)) { $set['category'] = substr(trim((string) $d['category']), 0, 60) ?: null; }
            if (array_key_exists('active', $d)) { $set['active'] = ((int) $d['active'] === 1) ? 1 : 0; }
            if (empty($set)) { return false; }
            $set['updated_at'] = date('Y-m-d H:i:s');
            $db->update('stays_linen_items', $set, ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('linen_item_update: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('linen_items_list')) {
    /** Catalogue for a property's set of stays. Read-only. */
    function linen_items_list($db, array $stayIds, int $limit = 500): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_linen_items', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('linen_items_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// ISSUE / RETURN / LOST / DAMAGED  (stays_linen_moves) — the stock ledger
// ---------------------------------------------------------------------------
if (!function_exists('linen_move_types')) {
    /** receive=stock in · issue=sent out to floor/wash (−) · return=came back (+) ·
     *  lost / damaged=write-off (−, optionally valued). */
    function linen_move_types(): array
    {
        return [
            'receive' => 'Receive (+)',
            'issue'   => 'Issue (−)',
            'return'  => 'Return (+)',
            'lost'    => 'Lost (−)',
            'damaged' => 'Damaged (−)',
        ];
    }
}

if (!function_exists('linen_move_sign')) {
    /** +1 adds to on-hand, -1 removes. */
    function linen_move_sign(string $type): int
    {
        return in_array($type, ['receive', 'return'], true) ? 1
             : (in_array($type, ['issue', 'lost', 'damaged'], true) ? -1 : 0);
    }
}

if (!function_exists('linen_move')) {
    /**
     * Record a stock movement for a catalogue item and adjust on_hand atomically.
     * $type ∈ linen_move_types(). $qty > 0. For lost/damaged the loss is VALUED at the
     * item's unit_cost (snapshotted) for the cost report. on_hand is never driven below 0.
     * Property-scoped (re-checks the item belongs to $stayId). Returns bool.
     */
    function linen_move($db, int $stayId, int $itemId, string $type, int $qty, array $d = []): bool
    {
        $sign = linen_move_sign($type);
        $qty  = (int) $qty;
        if ($stayId <= 0 || $itemId <= 0 || $sign === 0 || $qty <= 0) { return false; }
        try {
            $ok = false;
            $db->action(function ($db) use ($stayId, $itemId, $type, $qty, $sign, $d, &$ok) {
                // Lock the item row and confirm ownership inside the txn.
                $row = $db->query(
                    'SELECT id, on_hand, unit_cost, org_id FROM stays_linen_items WHERE id = :id AND stay_id = :sid FOR UPDATE',
                    [':id' => $itemId, ':sid' => $stayId]
                );
                $item = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$item) { $ok = false; return false; }

                $onHand = (int) $item['on_hand'];
                $delta  = $sign * $qty;
                $newOnHand = $onHand + $delta;
                if ($newOnHand < 0) { $newOnHand = 0; $delta = -$onHand; } // clamp; never negative
                $applied = abs($delta);                                    // qty actually applied

                // Value a write-off at the snapshotted unit cost (for the cost report).
                $unitCost = $item['unit_cost'] !== null ? round((float) $item['unit_cost'], 2) : null;
                $lossValue = null;
                if (in_array($type, ['lost', 'damaged'], true) && $unitCost !== null) {
                    $lossValue = round($unitCost * $applied, 2);
                }

                $db->update('stays_linen_items',
                    ['on_hand' => $newOnHand, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $itemId, 'stay_id' => $stayId]);

                $db->insert('stays_linen_moves', [
                    'org_id'        => isset($item['org_id']) ? $item['org_id'] : null,
                    'stay_id'       => $stayId,
                    'item_id'       => $itemId,
                    'move_type'     => $type,
                    'qty'           => $applied,
                    'unit_cost'     => $unitCost,
                    'loss_value'    => $lossValue,
                    'vendor_id'     => isset($d['vendor_id']) && (int) $d['vendor_id'] > 0 ? (int) $d['vendor_id'] : null,
                    'room_number'   => substr(trim((string) ($d['room_number'] ?? '')), 0, 40) ?: null,
                    'reference'     => substr(trim((string) ($d['reference'] ?? '')), 0, 120) ?: null,
                    'notes'         => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                    'moved_by'      => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $ok = ($applied > 0);
                return true;
            });
            return $ok;
        } catch (\Throwable $e) { error_log('linen_move: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('linen_moves_list')) {
    /** Recent stock movements for a property's set of stays. Read-only. */
    function linen_moves_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_linen_moves', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('linen_moves_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('linen_par_shortfall')) {
    /**
     * Par-level status for each catalogue item: shortfall = max(0, par_level − on_hand),
     * and a 'reorder' flag when on_hand < par_level. Read-only.
     * Returns a list of [id,name,par_level,on_hand,shortfall,reorder,unit_cost].
     */
    function linen_par_shortfall($db, array $stayIds): array
    {
        $out = [];
        foreach (linen_items_list($db, $stayIds) as $it) {
            if ((int) ($it['active'] ?? 1) !== 1) { continue; }
            $par = (int) $it['par_level']; $onHand = (int) $it['on_hand'];
            $short = max(0, $par - $onHand);
            $out[] = [
                'id'        => (int) $it['id'],
                'stay_id'   => (int) $it['stay_id'],
                'name'      => (string) $it['name'],
                'par_level' => $par,
                'on_hand'   => $onHand,
                'shortfall' => $short,
                'reorder'   => $short > 0,
                'unit_cost' => $it['unit_cost'] !== null ? (float) $it['unit_cost'] : null,
            ];
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// EXTERNAL LAUNDRY VENDORS  (stays_laundry_vendors) — DB-only register.
// NOTE: there is NO external vendor API/gateway here — this is purely a contact/rate
// book the property keeps. No network calls. (If a real vendor EDI integration is ever
// added it must be DB-GATED behind configured credentials, per house rules.)
// ---------------------------------------------------------------------------
if (!function_exists('laundry_vendor_add')) {
    function laundry_vendor_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_laundry_vendors', [
                'org_id'       => $orgId > 0 ? $orgId : null,
                'stay_id'      => $stayId,
                'name'         => substr($name, 0, 120),
                'contact'      => substr(trim((string) ($d['contact'] ?? '')), 0, 120) ?: null,
                'phone'        => substr(trim((string) ($d['phone'] ?? '')), 0, 40) ?: null,
                'rate_per_kg'  => ($d['rate_per_kg'] ?? '') !== '' ? max(0, round((float) $d['rate_per_kg'], 2)) : null,
                'active'       => 1,
                'notes'        => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('laundry_vendor_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('laundry_vendor_toggle')) {
    function laundry_vendor_toggle($db, int $id, int $stayId): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            $v = $db->get('stays_laundry_vendors', ['id', 'active'], ['id' => $id, 'stay_id' => $stayId]);
            if (!$v) { return false; }
            $db->update('stays_laundry_vendors',
                ['active' => ((int) $v['active'] === 1) ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('laundry_vendor_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('laundry_vendors_list')) {
    function laundry_vendors_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_laundry_vendors', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('laundry_vendors_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// GUEST LAUNDRY → FOLIO POSTING  (money spine — DB-GATED)
// ---------------------------------------------------------------------------
if (!function_exists('laundry_post_guest_charge')) {
    /**
     * DB-GATED: post a guest-laundry batch to the GUEST'S OPEN FOLIO as an `extra` line,
     * reusing the folio money spine (folio_get_or_create + folio_add_item). The amount is
     * NEVER taken from the client — it is read from the laundry batch's own stored `charge`
     * (set by staff when the batch was logged via laundry_add). This resolves the invoice →
     * folio through folio_get_or_create (which itself only accepts stays/hotels bookings
     * owned by this property), so an attacker cannot aim a charge at someone else's folio.
     *
     * Guards: batch must exist, be kind='guest', belong to $stayId, have a positive charge,
     * and not already be posted. On success stamps the batch folio_posted=1 + folio_item ref
     * so it cannot be double-posted. Returns ['ok'=>bool,'msg'=>string].
     */
    function laundry_post_guest_charge($db, int $laundryId, int $stayId, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($laundryId <= 0 || $stayId <= 0 || $invoiceId === '') {
            return ['ok' => false, 'msg' => 'Missing batch, property or invoice.'];
        }
        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            return ['ok' => false, 'msg' => 'Folio engine unavailable.'];
        }
        try {
            $batch = $db->get('stays_laundry', ['id', 'stay_id', 'item', 'qty', 'kind', 'charge', 'folio_posted'],
                ['id' => $laundryId, 'stay_id' => $stayId]);
            if (!$batch) { return ['ok' => false, 'msg' => 'Laundry batch not found for this property.']; }
            if ((string) $batch['kind'] !== 'guest') { return ['ok' => false, 'msg' => 'Only guest laundry can be charged to a folio.']; }
            if ((int) ($batch['folio_posted'] ?? 0) === 1) { return ['ok' => false, 'msg' => 'Already posted to a folio.']; }
            $amount = $batch['charge'] !== null ? round((float) $batch['charge'], 2) : 0.0;
            if ($amount <= 0) { return ['ok' => false, 'msg' => 'This batch has no positive charge to post.']; }

            // Resolve invoice → folio. folio_get_or_create enforces that the invoice is a
            // stays/hotels booking on an owned property; cross-check the folio's stay_id too.
            $folioId = folio_get_or_create($db, $invoiceId);
            if ($folioId <= 0) { return ['ok' => false, 'msg' => 'No folio for that invoice (not a stays booking on this property).']; }
            $folio = $db->get('stays_folios', ['id', 'stay_id', 'status'], ['id' => $folioId]);
            if (!$folio || (int) ($folio['stay_id'] ?? 0) !== $stayId) {
                return ['ok' => false, 'msg' => 'That folio does not belong to this property.'];
            }
            if ((string) $folio['status'] !== 'open') { return ['ok' => false, 'msg' => 'The folio is not open.']; }

            $desc = 'Laundry: ' . (int) $batch['qty'] . '× ' . (string) $batch['item'];
            $actor = (string) ($_SESSION['user_id'] ?? '') ?: 'laundry';
            $posted = folio_add_item($db, $folioId, 'extra', $amount, $desc, $actor, 1);
            if (!$posted) { return ['ok' => false, 'msg' => 'Folio refused the charge (closed folio?).']; }

            $db->update('stays_laundry',
                ['folio_posted' => 1, 'folio_id' => $folioId, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $laundryId, 'stay_id' => $stayId]);
            return ['ok' => true, 'msg' => 'Charged ' . number_format($amount, 2) . ' to folio #' . $folioId . '.'];
        } catch (\Throwable $e) {
            error_log('laundry_post_guest_charge: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not post the charge.'];
        }
    }
}

// ---------------------------------------------------------------------------
// LAUNDRY COST REPORT (read-only aggregate)
// ---------------------------------------------------------------------------
if (!function_exists('laundry_cost_report')) {
    /**
     * Aggregate laundry economics for a property's set of stays over an optional date
     * window [$from,$to] (YYYY-MM-DD, inclusive; matched on created_at date). Returns:
     *   guest_charges  — sum of guest-batch charges (what guests were billed)
     *   guest_posted   — subset of guest_charges actually posted to folios
     *   house_batches  — count of house (non-billable) batches
     *   loss_value     — valued lost/damaged linen (from stays_linen_moves.loss_value)
     *   lost_qty/damaged_qty — write-off quantities
     *   par_shortfall_units — total units below par across the catalogue
     * All read-only; the caller has already property-scoped $stayIds.
     */
    function laundry_cost_report($db, array $stayIds, string $from = '', string $to = ''): array
    {
        $out = ['guest_charges' => 0.0, 'guest_posted' => 0.0, 'house_batches' => 0,
                'guest_batches' => 0, 'loss_value' => 0.0, 'lost_qty' => 0, 'damaged_qty' => 0,
                'par_shortfall_units' => 0];
        if (empty($stayIds)) { return $out; }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';
        $inWindow = function ($ts) use ($from, $to): bool {
            $d = substr((string) $ts, 0, 10);
            if ($from !== '' && $d < $from) { return false; }
            if ($to !== '' && $d > $to) { return false; }
            return true;
        };
        try {
            foreach ($db->select('stays_laundry', ['kind', 'charge', 'folio_posted', 'created_at'],
                        ['stay_id' => $stayIds]) ?: [] as $b) {
                if (!$inWindow($b['created_at'] ?? '')) { continue; }
                if ((string) $b['kind'] === 'guest') {
                    $out['guest_batches']++;
                    $c = $b['charge'] !== null ? round((float) $b['charge'], 2) : 0.0;
                    $out['guest_charges'] += $c;
                    if ((int) ($b['folio_posted'] ?? 0) === 1) { $out['guest_posted'] += $c; }
                } else {
                    $out['house_batches']++;
                }
            }
        } catch (\Throwable $e) { error_log('laundry_cost_report batches: ' . $e->getMessage()); }
        try {
            foreach ($db->select('stays_linen_moves', ['move_type', 'qty', 'loss_value', 'created_at'],
                        ['stay_id' => $stayIds, 'move_type' => ['lost', 'damaged']]) ?: [] as $m) {
                if (!$inWindow($m['created_at'] ?? '')) { continue; }
                $out['loss_value'] += $m['loss_value'] !== null ? round((float) $m['loss_value'], 2) : 0.0;
                if ((string) $m['move_type'] === 'lost') { $out['lost_qty'] += (int) $m['qty']; }
                else { $out['damaged_qty'] += (int) $m['qty']; }
            }
        } catch (\Throwable $e) { error_log('laundry_cost_report moves: ' . $e->getMessage()); }
        foreach (linen_par_shortfall($db, $stayIds) as $s) { $out['par_shortfall_units'] += (int) $s['shortfall']; }

        $out['guest_charges'] = round($out['guest_charges'], 2);
        $out['guest_posted']  = round($out['guest_posted'], 2);
        $out['loss_value']    = round($out['loss_value'], 2);
        return $out;
    }
}
