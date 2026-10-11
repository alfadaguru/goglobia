<?php
// FILE: app/lib/supplier_pos_ext.php
// F&B / POS — DEEPENING (PMS #30; docs 01b §2 module 30). Builds the genuine gaps on
// top of the lean core in app/lib/supplier_pos.php WITHOUT rebuilding it:
//   • order-level ADJUSTMENTS — discount · service charge · tip (server-computed,
//     folded into the settled total; charge-to-room posts the EFFECTIVE total).
//   • SPLIT BILL — move selected lines off an open order into a new open order so
//     parties can settle separately (atomic, ownership re-checked by caller).
//   • POS SHIFTS — open/close a cashier shift; cash settlements are stamped to the
//     open shift; a Z-report sums tenders + adjustments for the shift window.
//   • OUTLET REPORTING — per-outlet daily sales summary (settled orders, tender mix,
//     discounts/service/tips, voids), read-only.
//
// MONEY RULES (same as the core): the client NEVER supplies a price or a total. Item
// prices are the snapshots taken at add-time; adjustments are server-recomputed from
// their stored basis; discounts are clamped so a total can never go negative. The only
// money sink is folio_add_item (charge-to-room), reused unchanged. All fns non-fatal.
//
// DEFERRED (honest — not built here): modifiers/variants, table/floor map, table
// reservations, KDS, takeaway channel, card/wallet standalone tender (gateway =
// DB-GATED, see supplier_pos: pos_payments.tender already has 'card'/'wallet' but no
// processor is wired — a stub here would fake money, so it is intentionally omitted).

if (!function_exists('pos_adjustment_types')) {
    /** The order-level adjustment kinds. Keys are stored in pos_order_adjustments.kind. */
    function pos_adjustment_types(): array
    {
        return [
            'discount'       => 'Discount',
            'service_charge' => 'Service charge',
            'tip'            => 'Tip / gratuity',
        ];
    }
}

if (!function_exists('pos_order_adjustments')) {
    /** All adjustment rows for an order (ordered). Returns []. */
    function pos_order_adjustments($db, int $orderId): array
    {
        if ($orderId <= 0) { return []; }
        try {
            return $db->select('pos_order_adjustments',
                ['id', 'kind', 'label', 'mode', 'rate', 'amount', 'created_at'],
                ['order_id' => $orderId, 'ORDER' => ['id' => 'ASC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('pos_order_adjustments: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pos_order_adjust_breakdown')) {
    /**
     * Server-side breakdown of an order:
     *   items    — sum of item snapshots (pos_order_total)
     *   discount — total discount applied (clamped to items)
     *   service  — total service charge
     *   tip      — total tip
     *   total    — items - discount + service + tip (never < 0)
     * Each adjustment's cash value is recomputed HERE from its stored basis (a percent of
     * the item subtotal, or a fixed amount) — the stored `amount` is only a cache. This is
     * the single source of truth the settle path uses, so a tampered cache cannot leak.
     */
    function pos_order_adjust_breakdown($db, int $orderId): array
    {
        $items = function_exists('pos_order_total') ? pos_order_total($db, $orderId) : 0.0;
        $out = ['items' => round($items, 2), 'discount' => 0.0, 'service' => 0.0, 'tip' => 0.0, 'total' => round($items, 2)];
        if ($orderId <= 0) { return $out; }
        $disc = 0.0; $svc = 0.0; $tip = 0.0;
        foreach (pos_order_adjustments($db, $orderId) as $a) {
            $val = pos_adjustment_value($items, (string) $a['mode'], (float) $a['rate'], (float) $a['amount']);
            switch ($a['kind']) {
                case 'discount':       $disc += $val; break;
                case 'service_charge': $svc  += $val; break;
                case 'tip':            $tip  += $val; break;
            }
        }
        // Discount can never exceed the item subtotal (so items-discount >= 0).
        $disc = min(round($disc, 2), round($items, 2));
        $total = round($items - $disc + $svc + $tip, 2);
        if ($total < 0) { $total = 0.0; } // belt-and-braces
        $out['discount'] = round($disc, 2);
        $out['service']  = round($svc, 2);
        $out['tip']      = round($tip, 2);
        $out['total']    = $total;
        return $out;
    }
}

if (!function_exists('pos_adjustment_value')) {
    /** Resolve one adjustment to a cash value from its basis. mode: 'pct'|'fixed'. */
    function pos_adjustment_value(float $itemSubtotal, string $mode, float $rate, float $fallbackAmount): float
    {
        if ($mode === 'pct') {
            $rate = max(0.0, min(100.0, $rate));
            return round($itemSubtotal * ($rate / 100.0), 2);
        }
        // fixed
        return round(max(0.0, $fallbackAmount), 2);
    }
}

if (!function_exists('pos_order_add_adjustment')) {
    /**
     * Add a discount / service-charge / tip to an OPEN order. `mode` is 'pct' (a percent of
     * the current item subtotal) or 'fixed' (a cash amount in the order currency). The cash
     * value is computed server-side and cached in `amount`; the breakdown always recomputes.
     * A percent is clamped 0..100; a fixed amount must be > 0. Returns ['ok','message'].
     */
    function pos_order_add_adjustment($db, int $orderId, int $stayId, string $kind, string $mode, float $value, string $label = ''): array
    {
        if ($orderId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $kind = isset(pos_adjustment_types()[$kind]) ? $kind : '';
        if ($kind === '') { return ['ok' => false, 'message' => 'Unknown adjustment type']; }
        $mode = in_array($mode, ['pct', 'fixed'], true) ? $mode : 'fixed';
        try {
            $order = $db->get('pos_orders', ['id', 'status'], ['id' => $orderId, 'stay_id' => $stayId]);
            if (!$order) { return ['ok' => false, 'message' => 'Order not found.']; }
            if ($order['status'] !== 'open') { return ['ok' => false, 'message' => 'Only an open order can be adjusted (' . $order['status'] . ').']; }

            $items = function_exists('pos_order_total') ? pos_order_total($db, $orderId) : 0.0;
            $rate = 0.0; $amount = 0.0;
            if ($mode === 'pct') {
                $rate = max(0.0, min(100.0, $value));
                if ($rate <= 0) { return ['ok' => false, 'message' => 'Enter a percent between 0 and 100.']; }
                $amount = pos_adjustment_value($items, 'pct', $rate, 0.0);
            } else {
                $amount = round(max(0.0, $value), 2);
                if ($amount <= 0) { return ['ok' => false, 'message' => 'Enter an amount greater than zero.']; }
            }
            // A single fixed discount cannot exceed the current subtotal (keeps the cache sane).
            if ($kind === 'discount' && $mode === 'fixed' && $amount > $items) { $amount = round($items, 2); }

            $db->insert('pos_order_adjustments', [
                'order_id'   => $orderId,
                'kind'       => $kind,
                'label'      => trim($label) !== '' ? substr(trim($label), 0, 120) : pos_adjustment_types()[$kind],
                'mode'       => $mode,
                'rate'       => $mode === 'pct' ? $rate : 0.0,
                'amount'     => $amount,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => pos_adjustment_types()[$kind] . ' added.'];
        } catch (\Throwable $e) {
            error_log('pos_order_add_adjustment: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not add the adjustment.'];
        }
    }
}

if (!function_exists('pos_order_remove_adjustment')) {
    /** Remove an adjustment from an OPEN order (property-scoped via the order). bool-ish array. */
    function pos_order_remove_adjustment($db, int $orderId, int $stayId, int $adjId): array
    {
        if ($orderId <= 0 || $adjId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        try {
            $order = $db->get('pos_orders', ['id', 'status'], ['id' => $orderId, 'stay_id' => $stayId]);
            if (!$order) { return ['ok' => false, 'message' => 'Order not found.']; }
            if ($order['status'] !== 'open') { return ['ok' => false, 'message' => 'Only an open order can be adjusted.']; }
            $del = $db->delete('pos_order_adjustments', ['id' => $adjId, 'order_id' => $orderId]);
            if (!$del || $del->rowCount() < 1) { return ['ok' => false, 'message' => 'Adjustment not found.']; }
            return ['ok' => true, 'message' => 'Adjustment removed.'];
        } catch (\Throwable $e) {
            error_log('pos_order_remove_adjustment: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not remove the adjustment.'];
        }
    }
}

if (!function_exists('pos_order_split')) {
    /**
     * SPLIT BILL: move a chosen subset of an order's item lines onto a NEW open order in the
     * same outlet, so two parties can settle independently. $lineIds are pos_order_items.id
     * values; only lines that actually belong to the source order are moved. At least one
     * line must remain on the source and at least one must move (no empty split). Adjustments
     * are NOT copied — the two bills re-apply their own service/tip/discount (simplest honest
     * behaviour; avoids silently duplicating a percentage). Atomic. Returns
     * ['ok','new_order_id','message'].
     */
    function pos_order_split($db, int $orderId, int $stayId, array $lineIds): array
    {
        if ($orderId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), fn($v) => $v > 0)));
        if (empty($lineIds)) { return ['ok' => false, 'message' => 'Select at least one line to split off.']; }

        $order = $db->get('pos_orders', '*', ['id' => $orderId, 'stay_id' => $stayId]);
        if (!$order) { return ['ok' => false, 'message' => 'Order not found.']; }
        if ($order['status'] !== 'open') { return ['ok' => false, 'message' => 'Only an open order can be split.']; }

        $result = ['ok' => false, 'message' => 'Split failed'];
        try {
            $db->action(function ($db) use ($order, $orderId, $stayId, $lineIds, &$result) {
                // Re-lock the source order so a concurrent settle/void cannot race the move.
                $locked = $db->query('SELECT status FROM pos_orders WHERE id = :o FOR UPDATE', [':o' => $orderId]);
                $cur = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || $cur['status'] !== 'open') { $result = ['ok' => false, 'message' => 'Order is no longer open.']; return false; }

                // Which of the requested lines really belong to this order?
                $owned = $db->select('pos_order_items', ['id'], ['id' => $lineIds, 'order_id' => $orderId]) ?: [];
                $moveIds = array_map(fn($r) => (int) $r['id'], $owned);
                $total = (int) $db->count('pos_order_items', ['order_id' => $orderId]);
                if (empty($moveIds)) { $result = ['ok' => false, 'message' => 'None of those lines are on this order.']; return false; }
                if (count($moveIds) >= $total) { $result = ['ok' => false, 'message' => 'Keep at least one line on the original bill.']; return false; }

                $db->insert('pos_orders', [
                    'org_id'      => $order['org_id'] !== null ? (int) $order['org_id'] : null,
                    'stay_id'     => (int) $order['stay_id'],
                    'outlet_id'   => (int) $order['outlet_id'],
                    'table_label' => ($order['table_label'] !== null && $order['table_label'] !== '')
                                        ? substr('Split of #' . $orderId . ' · ' . $order['table_label'], 0, 60)
                                        : 'Split of #' . $orderId,
                    'status'      => 'open',
                    'currency'    => $order['currency'] ?? 'USD',
                    'server_id'   => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $newId = (int) $db->id();
                if ($newId <= 0) { $result = ['ok' => false, 'message' => 'Could not open the split bill.']; return false; }

                $moved = $db->update('pos_order_items', ['order_id' => $newId], ['id' => $moveIds, 'order_id' => $orderId]);
                if (!$moved || $moved->rowCount() !== count($moveIds)) {
                    $result = ['ok' => false, 'message' => 'Lines changed during the split — nothing moved.'];
                    return false; // rollback
                }
                $result = ['ok' => true, 'new_order_id' => $newId, 'message' => 'Split into order #' . $newId . '.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('pos_order_split: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Split error'];
        }
        return $result;
    }
}

if (!function_exists('pos_order_settle_ext')) {
    /**
     * SETTLE WITH ADJUSTMENTS (discount/service/tip). The core _pos_settle() in
     * supplier_pos.php settles the bare item subtotal only; this variant settles the
     * EFFECTIVE total from pos_order_adjust_breakdown() so service charges and tips are
     * actually collected (and discounts honoured). Charge-to-room posts the effective total
     * to the folio via folio_add_item; cash records the tender and stamps the open shift.
     *
     * The client NEVER supplies the amount — it is recomputed here. Mirrors the core's
     * guards: order must be open + non-empty; for 'room' the reservation must be an own
     * stay for this property, checked_in, with an OPEN folio. Fully transactional +
     * FOR UPDATE re-lock for idempotency. Returns ['ok','amount','message'].
     *
     * @param string $tender 'cash'|'room'
     */
    function pos_order_settle_ext($db, int $orderId, int $stayId, string $tender, ?string $invoiceId): array
    {
        $tender = in_array($tender, ['cash', 'room'], true) ? $tender : '';
        if ($orderId <= 0 || $tender === '') { return ['ok' => false, 'message' => 'Bad settlement']; }

        $order = $db->get('pos_orders', '*', ['id' => $orderId, 'stay_id' => $stayId]);
        if (!$order) { return ['ok' => false, 'message' => 'Order not found']; }
        if ($order['status'] !== 'open') { return ['ok' => false, 'message' => 'Order is not open (' . $order['status'] . ')']; }

        $b = pos_order_adjust_breakdown($db, $orderId);
        $total = (float) $b['total'];
        if ($b['items'] <= 0) { return ['ok' => false, 'message' => 'Order is empty']; }
        if ($total <= 0) { return ['ok' => false, 'message' => 'Nothing to settle after discount']; }

        // Validate charge-to-room target BEFORE mutating (same rules as the core).
        $folioId = 0;
        if ($tender === 'room') {
            $invoiceId = $invoiceId !== null ? trim($invoiceId) : '';
            if ($invoiceId === '') { return ['ok' => false, 'message' => 'No room/reservation selected']; }
            $resv = $db->get('bookings', ['invoice_id', 'module_type', 'booking_data'], ['invoice_id' => $invoiceId]);
            if (!$resv) { return ['ok' => false, 'message' => 'Reservation not found']; }
            if (!in_array(strtolower((string) $resv['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay reservation']; }
            $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($resv['booking_data'] ?? null) : 0;
            if ($hid !== $stayId) { return ['ok' => false, 'message' => 'That room is not in this property']; }
            $state = function_exists('folio_stay_state') ? folio_stay_state($resv['booking_data'] ?? null) : 'confirmed';
            if ($state !== 'checked_in') { return ['ok' => false, 'message' => 'Guest is not checked in — cannot charge to room']; }
            $folioId = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
            if ($folioId <= 0) { return ['ok' => false, 'message' => 'No open folio for that reservation']; }
            $f = $db->get('stays_folios', ['status'], ['id' => $folioId]);
            if (!$f || $f['status'] !== 'open') { return ['ok' => false, 'message' => 'That folio is already closed']; }
        }

        $openShiftId = ($tender === 'cash') ? (int) (pos_open_shift($db, (int) $order['outlet_id'])['id'] ?? 0) : 0;

        $result = ['ok' => false, 'message' => 'Settlement failed'];
        try {
            $db->action(function ($db) use ($orderId, $tender, $invoiceId, $folioId, $total, $b, $openShiftId, &$result) {
                $locked = $db->query('SELECT status FROM pos_orders WHERE id = :o FOR UPDATE', [':o' => $orderId]);
                $cur = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || $cur['status'] !== 'open') { $result = ['ok' => false, 'message' => 'Order already settled']; return false; }

                if ($tender === 'room') {
                    $desc = 'F&B — order #' . $orderId
                        . ($b['service'] > 0 ? ' · svc ' . number_format($b['service'], 2) : '')
                        . ($b['tip'] > 0 ? ' · tip ' . number_format($b['tip'], 2) : '')
                        . ($b['discount'] > 0 ? ' · disc -' . number_format($b['discount'], 2) : '');
                    $ok = function_exists('folio_add_item')
                        && folio_add_item($db, $folioId, 'charge', $total, $desc, (string) ($_SESSION['user_id'] ?? ''));
                    if (!$ok) { $result = ['ok' => false, 'message' => 'Could not post to the folio']; return false; }
                }

                $db->insert('pos_payments', [
                    'order_id'   => $orderId,
                    'tender'     => $tender,
                    'amount'     => $total,
                    'invoice_id' => $tender === 'room' ? $invoiceId : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $db->update('pos_orders', [
                    'status'     => 'settled',
                    'shift_id'   => $openShiftId > 0 ? $openShiftId : null,
                    'settled_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $orderId, 'status' => 'open']);
                $result = ['ok' => true, 'amount' => $total, 'message' => $tender === 'room' ? 'Charged to room.' : 'Settled (cash).'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('pos_order_settle_ext: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Settlement error'];
        }
        return $result;
    }
}

/* ------------------------------------------------------------------ POS SHIFTS ------ */

if (!function_exists('pos_shift_open')) {
    /**
     * Open a cashier shift for an outlet with an opening cash float. Only ONE shift may be
     * open per outlet at a time (enforced by a conditional check inside a transaction).
     * Returns ['ok','shift_id','message'].
     */
    function pos_shift_open($db, int $outletId, int $stayId, float $openingFloat = 0.0): array
    {
        if ($outletId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $openingFloat = round(max(0.0, $openingFloat), 2);
        $result = ['ok' => false, 'message' => 'Could not open shift'];
        try {
            $db->action(function ($db) use ($outletId, $stayId, $openingFloat, &$result) {
                $openCount = (int) $db->count('pos_shifts', ['outlet_id' => $outletId, 'status' => 'open']);
                if ($openCount > 0) { $result = ['ok' => false, 'message' => 'A shift is already open for this outlet.']; return false; }
                $db->insert('pos_shifts', [
                    'outlet_id'     => $outletId,
                    'stay_id'       => $stayId,
                    'opened_by'     => (string) ($_SESSION['user_id'] ?? ''),
                    'opening_float' => $openingFloat,
                    'status'        => 'open',
                    'opened_at'     => date('Y-m-d H:i:s'),
                ]);
                $id = (int) $db->id();
                if ($id <= 0) { $result = ['ok' => false, 'message' => 'Could not open shift']; return false; }
                $result = ['ok' => true, 'shift_id' => $id, 'message' => 'Shift opened.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('pos_shift_open: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not open shift'];
        }
        return $result;
    }
}

if (!function_exists('pos_open_shift')) {
    /** The currently-open shift row for an outlet, or null. */
    function pos_open_shift($db, int $outletId): ?array
    {
        if ($outletId <= 0) { return null; }
        try {
            return $db->get('pos_shifts', ['id', 'opened_by', 'opening_float', 'opened_at'],
                ['outlet_id' => $outletId, 'status' => 'open', 'ORDER' => ['id' => 'DESC']]) ?: null;
        } catch (\Throwable $e) { error_log('pos_open_shift: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('pos_shift_report')) {
    /**
     * Z-REPORT for a shift: sum settled POS payments whose order closed within the shift
     * window, grouped by tender, plus the adjustment totals for those orders. All money is
     * recomputed server-side from pos_payments + the adjustment breakdown — never taken from
     * a client or a cached column. Returns a structured report (safe empties on error).
     */
    function pos_shift_report($db, int $shiftId): array
    {
        $empty = ['shift' => null, 'cash' => 0.0, 'room' => 0.0, 'orders' => 0,
                  'discount' => 0.0, 'service' => 0.0, 'tip' => 0.0, 'expected_drawer' => 0.0];
        if ($shiftId <= 0) { return $empty; }
        try {
            $shift = $db->get('pos_shifts', '*', ['id' => $shiftId]);
            if (!$shift) { return $empty; }
            $outletId = (int) $shift['outlet_id'];
            $from = (string) $shift['opened_at'];
            $to   = ($shift['status'] === 'open' || empty($shift['closed_at'])) ? date('Y-m-d H:i:s') : (string) $shift['closed_at'];

            // Settled orders for this outlet whose settle time falls in the window.
            $orders = $db->select('pos_orders', ['id'],
                ['outlet_id' => $outletId, 'status' => 'settled',
                 'settled_at[<>]' => [$from, $to]]) ?: [];
            $orderIds = array_map(fn($r) => (int) $r['id'], $orders);

            $cash = 0.0; $room = 0.0; $disc = 0.0; $svc = 0.0; $tip = 0.0;
            if (!empty($orderIds)) {
                $pays = $db->select('pos_payments', ['tender', 'amount'], ['order_id' => $orderIds]) ?: [];
                foreach ($pays as $p) {
                    $amt = round((float) $p['amount'], 2);
                    if ($p['tender'] === 'cash') { $cash += $amt; }
                    elseif ($p['tender'] === 'room') { $room += $amt; }
                }
                foreach ($orderIds as $oid) {
                    $b = pos_order_adjust_breakdown($db, $oid);
                    $disc += $b['discount']; $svc += $b['service']; $tip += $b['tip'];
                }
            }
            return [
                'shift'           => $shift,
                'cash'            => round($cash, 2),
                'room'            => round($room, 2),
                'orders'          => count($orderIds),
                'discount'        => round($disc, 2),
                'service'         => round($svc, 2),
                'tip'             => round($tip, 2),
                'expected_drawer' => round((float) $shift['opening_float'] + $cash, 2),
            ];
        } catch (\Throwable $e) {
            error_log('pos_shift_report: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('pos_shift_close')) {
    /**
     * Close the open shift: record the counted drawer cash + compute the variance against the
     * expected drawer (opening float + cash tenders in the window). State-guarded conditional
     * UPDATE so a double-submit can't close twice. Returns ['ok','report','variance','message'].
     */
    function pos_shift_close($db, int $shiftId, int $outletId, float $countedCash): array
    {
        if ($shiftId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $countedCash = round(max(0.0, $countedCash), 2);
        try {
            $shift = $db->get('pos_shifts', ['id', 'status'], ['id' => $shiftId, 'outlet_id' => $outletId]);
            if (!$shift) { return ['ok' => false, 'message' => 'Shift not found.']; }
            if ($shift['status'] !== 'open') { return ['ok' => false, 'message' => 'Shift is already closed.']; }

            $report = pos_shift_report($db, $shiftId);
            $expected = (float) $report['expected_drawer'];
            $variance = round($countedCash - $expected, 2);
            $now = date('Y-m-d H:i:s');

            $upd = $db->update('pos_shifts', [
                'status'        => 'closed',
                'closed_by'     => (string) ($_SESSION['user_id'] ?? ''),
                'counted_cash'  => $countedCash,
                'expected_cash' => round($expected, 2),
                'variance'      => $variance,
                'closed_at'     => $now,
            ], ['id' => $shiftId, 'outlet_id' => $outletId, 'status' => 'open']);
            if (!$upd || $upd->rowCount() !== 1) { return ['ok' => false, 'message' => 'Shift was already closed.']; }

            $report['shift']['status'] = 'closed';
            $report['shift']['closed_at'] = $now;
            return ['ok' => true, 'report' => $report, 'variance' => $variance,
                    'message' => 'Shift closed. Variance ' . number_format($variance, 2) . '.'];
        } catch (\Throwable $e) {
            error_log('pos_shift_close: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not close the shift.'];
        }
    }
}

if (!function_exists('pos_outlet_sales_report')) {
    /**
     * OUTLET REPORTING: a read-only daily sales summary for an outlet over a date range
     * (inclusive, server-clamped to <= 92 days). Per-day: settled order count, cash/room
     * tender totals, discount/service/tip totals, and void count. All recomputed from the
     * ledger tables. Returns ['rows'=>[day=>{...}], 'totals'=>{...}].
     */
    function pos_outlet_sales_report($db, int $outletId, string $from, string $to): array
    {
        $out = ['rows' => [], 'totals' => ['orders' => 0, 'cash' => 0.0, 'room' => 0.0, 'discount' => 0.0, 'service' => 0.0, 'tip' => 0.0, 'voids' => 0]];
        if ($outletId <= 0) { return $out; }
        $f = date('Y-m-d', strtotime($from) ?: time());
        $t = date('Y-m-d', strtotime($to) ?: time());
        if ($f > $t) { [$f, $t] = [$t, $f]; }
        // Clamp range to 92 days to bound the scan.
        if ((strtotime($t) - strtotime($f)) > 92 * 86400) { $t = date('Y-m-d', strtotime($f) + 92 * 86400); }
        $fromDt = $f . ' 00:00:00'; $toDt = $t . ' 23:59:59';
        try {
            $settled = $db->select('pos_orders', ['id', 'settled_at'],
                ['outlet_id' => $outletId, 'status' => 'settled', 'settled_at[<>]' => [$fromDt, $toDt]]) ?: [];
            $voidRows = $db->select('pos_orders', ['updated_at'],
                ['outlet_id' => $outletId, 'status' => 'void', 'updated_at[<>]' => [$fromDt, $toDt]]) ?: [];

            $rows = [];
            $allIds = [];
            foreach ($settled as $o) {
                $day = substr((string) $o['settled_at'], 0, 10);
                $rows[$day] = $rows[$day] ?? ['orders' => 0, 'cash' => 0.0, 'room' => 0.0, 'discount' => 0.0, 'service' => 0.0, 'tip' => 0.0, 'voids' => 0];
                $rows[$day]['orders']++;
                $allIds[(int) $o['id']] = $day;
            }
            foreach ($voidRows as $v) {
                $day = substr((string) $v['updated_at'], 0, 10);
                $rows[$day] = $rows[$day] ?? ['orders' => 0, 'cash' => 0.0, 'room' => 0.0, 'discount' => 0.0, 'service' => 0.0, 'tip' => 0.0, 'voids' => 0];
                $rows[$day]['voids']++;
            }
            if (!empty($allIds)) {
                $pays = $db->select('pos_payments', ['order_id', 'tender', 'amount'], ['order_id' => array_keys($allIds)]) ?: [];
                foreach ($pays as $p) {
                    $day = $allIds[(int) $p['order_id']] ?? null;
                    if ($day === null) { continue; }
                    $amt = round((float) $p['amount'], 2);
                    if ($p['tender'] === 'cash') { $rows[$day]['cash'] += $amt; }
                    elseif ($p['tender'] === 'room') { $rows[$day]['room'] += $amt; }
                }
                foreach ($allIds as $oid => $day) {
                    $b = pos_order_adjust_breakdown($db, (int) $oid);
                    $rows[$day]['discount'] += $b['discount'];
                    $rows[$day]['service']  += $b['service'];
                    $rows[$day]['tip']      += $b['tip'];
                }
            }
            krsort($rows);
            foreach ($rows as $r) {
                $out['totals']['orders']   += $r['orders'];
                $out['totals']['cash']     += $r['cash'];
                $out['totals']['room']     += $r['room'];
                $out['totals']['discount'] += $r['discount'];
                $out['totals']['service']  += $r['service'];
                $out['totals']['tip']      += $r['tip'];
                $out['totals']['voids']    += $r['voids'];
            }
            foreach ($out['totals'] as $k => $v) { if (is_float($v)) { $out['totals'][$k] = round($v, 2); } }
            $out['rows'] = $rows;
        } catch (\Throwable $e) {
            error_log('pos_outlet_sales_report: ' . $e->getMessage());
        }
        return $out;
    }
}
