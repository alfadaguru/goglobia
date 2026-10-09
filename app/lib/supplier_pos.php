<?php
// FILE: app/lib/supplier_pos.php
// FOOD & BEVERAGE / POS (Phase 1 inc S24; docs 01 §5). Stage D module.
//
// A lean POS core whose headline feature is CHARGE-TO-ROOM: settling a POS order
// against an in-house guest posts a charge line onto that guest's FOLIO (S21) instead
// of taking tender now. Cash settlement is also supported (records the tender).
//
// Model: outlets (restaurant/bar/…) → menu items → orders → order items → settlement.
// Order-item prices are SNAPSHOT at add-time; the order total is always recomputed
// SERVER-SIDE from the snapshots (never trusted from the client). Charge-to-room is
// only allowed to a reservation that (a) belongs to the same property/owner and
// (b) is currently checked_in with an OPEN folio. All functions non-fatal.

if (!function_exists('pos_outlet_types')) {
    function pos_outlet_types(): array { return ['restaurant' => 'Restaurant', 'bar' => 'Bar', 'cafe' => 'Café', 'room_service' => 'Room service']; }
}

if (!function_exists('pos_order_total')) {
    /** Server-side total of an order from its item snapshots. */
    function pos_order_total($db, int $orderId): float
    {
        try {
            $rows = $db->select('pos_order_items', ['qty', 'unit_price'], ['order_id' => $orderId]) ?: [];
            $t = 0.0;
            foreach ($rows as $r) { $t += ((int) $r['qty']) * (float) $r['unit_price']; }
            return round($t, 2);
        } catch (\Throwable $e) {
            error_log('pos_order_total: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('pos_order_create')) {
    /** Open a new order for an outlet (validated owned). Returns order id (0 fail). */
    function pos_order_create($db, int $stayId, int $outletId, array $opts = []): int
    {
        if ($stayId <= 0 || $outletId <= 0) { return 0; }
        try {
            $outlet = $db->get('stays_outlets', ['id', 'org_id'], ['id' => $outletId, 'stay_id' => $stayId]);
            if (!$outlet) { return 0; }
            $db->insert('pos_orders', [
                'org_id'      => $outlet['org_id'] !== null ? (int) $outlet['org_id'] : null,
                'stay_id'     => $stayId,
                'outlet_id'   => $outletId,
                'table_label' => trim((string) ($opts['table_label'] ?? '')) ?: null,
                'status'      => 'open',
                'currency'    => strtoupper(substr((string) ($opts['currency'] ?? 'USD'), 0, 3)),
                'server_id'   => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('pos_order_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('pos_order_add_item')) {
    /**
     * Add a menu item to an OPEN order, snapshotting the current price. The item must
     * belong to the order's outlet. Returns bool.
     */
    function pos_order_add_item($db, int $orderId, int $menuItemId, int $qty = 1): bool
    {
        if ($orderId <= 0 || $menuItemId <= 0) { return false; }
        if ($qty < 1) { $qty = 1; }
        try {
            $order = $db->get('pos_orders', ['id', 'outlet_id', 'status'], ['id' => $orderId]);
            if (!$order || $order['status'] !== 'open') { return false; }
            $item = $db->get('stays_menu_items', ['id', 'name', 'price', 'active'],
                ['id' => $menuItemId, 'outlet_id' => (int) $order['outlet_id']]);
            if (!$item || (int) $item['active'] !== 1) { return false; }
            $db->insert('pos_order_items', [
                'order_id'   => $orderId,
                'menu_item_id' => $menuItemId,
                'name'       => substr((string) $item['name'], 0, 191),  // snapshot name
                'qty'        => $qty,
                'unit_price' => round((float) $item['price'], 2),         // snapshot price
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('pos_order_add_item: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pos_order_settle_cash')) {
    /** Settle an open order as CASH. Records a tender + marks the order settled.
     *  Server-computed amount. Returns ['ok'=>,'amount'=>,'message'=>]. */
    function pos_order_settle_cash($db, int $orderId): array
    {
        return _pos_settle($db, $orderId, 'cash', null);
    }
}

if (!function_exists('pos_order_settle_charge_to_room')) {
    /**
     * Settle an open order by CHARGE-TO-ROOM: post the order total as a 'charge' line
     * onto the in-house guest's folio (S21) and mark the order settled. The target
     * reservation ($invoiceId) MUST belong to the order's property and be checked_in
     * with an OPEN folio. Amount is the server-computed order total. Returns
     * ['ok'=>,'amount'=>,'message'=>].
     */
    function pos_order_settle_charge_to_room($db, int $orderId, string $invoiceId): array
    {
        return _pos_settle($db, $orderId, 'room', trim($invoiceId));
    }
}

if (!function_exists('_pos_settle')) {
    /** Shared settlement. Validates the order is open + non-empty; computes the total
     *  server-side; for 'room' validates + posts to the folio; records a pos_payment;
     *  marks the order settled — all in one transaction. Idempotent on order status. */
    function _pos_settle($db, int $orderId, string $tender, ?string $invoiceId): array
    {
        $tender = in_array($tender, ['cash', 'room'], true) ? $tender : '';
        if ($orderId <= 0 || $tender === '') { return ['ok' => false, 'message' => 'Bad settlement']; }

        $order = $db->get('pos_orders', '*', ['id' => $orderId]);
        if (!$order) { return ['ok' => false, 'message' => 'Order not found']; }
        if ($order['status'] !== 'open') { return ['ok' => false, 'message' => 'Order is not open (' . $order['status'] . ')']; }

        $total = pos_order_total($db, $orderId);
        if ($total <= 0) { return ['ok' => false, 'message' => 'Order is empty']; }
        $stayId = (int) $order['stay_id'];

        // For charge-to-room: validate the target reservation BEFORE taking any action.
        $folioId = 0;
        if ($tender === 'room') {
            if ($invoiceId === null || $invoiceId === '') { return ['ok' => false, 'message' => 'No room/reservation selected']; }
            // The reservation must be an own-inventory stay for THIS property…
            $b = $db->get('bookings', ['invoice_id', 'module_type', 'booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Reservation not found']; }
            if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay reservation']; }
            $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
            if ($hid !== $stayId) { return ['ok' => false, 'message' => 'That room is not in this property']; }
            // …and must be checked in.
            $state = function_exists('folio_stay_state') ? folio_stay_state($b['booking_data'] ?? null) : 'confirmed';
            if ($state !== 'checked_in') { return ['ok' => false, 'message' => 'Guest is not checked in — cannot charge to room']; }
            $folioId = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
            if ($folioId <= 0) { return ['ok' => false, 'message' => 'No open folio for that reservation']; }
            $f = $db->get('stays_folios', ['status'], ['id' => $folioId]);
            if (!$f || $f['status'] !== 'open') { return ['ok' => false, 'message' => 'That folio is already closed']; }
        }

        $result = ['ok' => false, 'message' => 'Settlement failed'];
        try {
            $db->action(function ($db) use ($orderId, $order, $tender, $invoiceId, $folioId, $total, &$result) {
                // Re-lock the order + re-check status (idempotency under concurrency).
                $locked = $db->query('SELECT status FROM pos_orders WHERE id = :o FOR UPDATE', [':o' => $orderId]);
                $cur = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || $cur['status'] !== 'open') { $result = ['ok' => false, 'message' => 'Order already settled']; return false; }

                if ($tender === 'room') {
                    $desc = 'F&B — order #' . $orderId;
                    $ok = function_exists('folio_add_item')
                        && folio_add_item($db, $folioId, 'charge', $total, $desc, (string) ($_SESSION['user_id'] ?? ''));
                    if (!$ok) { $result = ['ok' => false, 'message' => 'Could not post to the folio']; return false; } // rollback
                }

                $db->insert('pos_payments', [
                    'order_id'   => $orderId,
                    'tender'     => $tender,
                    'amount'     => $total,
                    'invoice_id' => $tender === 'room' ? $invoiceId : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $db->update('pos_orders',
                    ['status' => 'settled', 'settled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $orderId, 'status' => 'open']);
                $result = ['ok' => true, 'amount' => $total, 'message' => $tender === 'room' ? 'Charged to room.' : 'Settled (cash).'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('_pos_settle: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Settlement error'];
        }
        return $result;
    }
}

if (!function_exists('pos_checked_in_reservations')) {
    /** In-house (checked_in) reservations for a property — the charge-to-room targets.
     *  Returns [invoice_id => label]. Bounded scan of own-inventory bookings. */
    function pos_checked_in_reservations($db, int $stayId): array
    {
        $out = [];
        try {
            $rows = $db->select('bookings', ['invoice_id', 'first_name', 'last_name', 'booking_data'],
                ['module_type' => ['stays', 'hotels'],
                 'booking_data[~]' => '"hotel_id":' . (int) $stayId,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd) || (int) ($bd['hotel_id'] ?? 0) !== $stayId) { continue; } // verify
                if (($bd['pms_stay_state'] ?? '') !== 'checked_in') { continue; }
                $label = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
                $prid = (int) ($bd['pms_physical_room_id'] ?? 0);
                $out[(string) $r['invoice_id']] = ($label !== '' ? $label : (string) $r['invoice_id']) . ($prid > 0 ? ' (room assigned)' : '');
            }
        } catch (\Throwable $e) {
            error_log('pos_checked_in_reservations: ' . $e->getMessage());
        }
        return $out;
    }
}
