<?php
// FILE: app/lib/supplier_reservation_ops.php
// Reservation operations for the supplier front desk (PMS completion #04). These are
// the ops the inbox action handler delegates to, beyond the original
// cancel/no-show/check-in/check-out/folio-add set:
//
//   - resv_create_walkin()   : front-desk creates a booking directly (walk-in / phone /
//                              online-by-staff), server-priced for own inventory, with a
//                              real no-oversell hold. Optionally mark paid at the desk.
//   - resv_waitlist_add()    : record a waitlisted request (no inventory) for a date/room.
//   - resv_set_source()      : tag a reservation's booking source (walk_in/phone/ota/…).
//   - resv_modify_dates()    : change check-in/out (extend/shorten/move) — re-holds
//                              inventory atomically (new hold first, release old only on
//                              success) and re-derives the folio room charge.
//   - resv_transfer_room()   : move the booking to a different room/option (re-hold).
//
// EVERYTHING here is own-inventory only ('stays'/'hotels'); ownership is enforced by the
// CALLER (supplier_can with the resolved hotel_id). These helpers assume that check has
// already passed and operate on the booking by invoice id. All are defensive + logged.
//
// NOT runtime-verified (no MySQL in the build env) — logic-reasoned + php -l + harness.

if (!function_exists('resv_booking_sources')) {
    /** Canonical booking-source tags. */
    function resv_booking_sources(): array
    {
        return [
            'website'  => 'Website',
            'walk_in'  => 'Walk-in',
            'phone'    => 'Phone',
            'email'    => 'Email',
            'ota'      => 'OTA / channel',
            'agent'    => 'Travel agent',
            'corporate'=> 'Corporate',
            'other'    => 'Other',
        ];
    }
}

if (!function_exists('resv_generate_invoice_id')) {
    /** An 8-char uppercase invoice id, matching the booking paths' format. */
    function resv_generate_invoice_id(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}

if (!function_exists('resv_create_walkin')) {
    /**
     * Create a front-desk reservation for OWN inventory. The net is recomputed
     * server-side from room_options + calendar (never trusted from the form), markup +
     * tax applied, a no-oversell hold placed, and (optionally) the booking marked paid
     * at the desk — which consumes the hold so the seat is reserved permanently.
     *
     * @param array $in [
     *     'stay_id'=>int, 'room_id'=>int, 'option_id'=>int, 'qty'=>int,
     *     'checkin'=>'Y-m-d', 'checkout'=>'Y-m-d',
     *     'first_name','last_name','email','phone','adults','children',
     *     'source'=>string, 'mark_paid'=>bool, 'notes'=>string ]
     * @return array ['ok'=>bool, 'invoice_id'=>?string, 'message'=>string]
     */
    function resv_create_walkin($db, int $stayId, array $in): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'message' => 'No property']; }

        $roomId   = (int) ($in['room_id'] ?? 0);
        $optionId = (int) ($in['option_id'] ?? 0);
        $qty      = max(1, (int) ($in['qty'] ?? 1));
        $checkin  = trim((string) ($in['checkin'] ?? ''));
        $checkout = trim((string) ($in['checkout'] ?? ''));
        if ($roomId <= 0 || $optionId <= 0) { return ['ok' => false, 'message' => 'Pick a room and rate.']; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)) {
            return ['ok' => false, 'message' => 'Valid check-in and check-out dates are required.'];
        }
        if (strtotime($checkout) <= strtotime($checkin)) {
            return ['ok' => false, 'message' => 'Check-out must be after check-in.'];
        }

        $firstName = trim((string) ($in['first_name'] ?? ''));
        $lastName  = trim((string) ($in['last_name'] ?? ''));
        if ($firstName === '' && $lastName === '') {
            return ['ok' => false, 'message' => 'Guest name is required.'];
        }

        // Property + its currency (own inventory, must be approved-or-admin? No — the
        // owner is booking their OWN property, which may still be draft; front desk can
        // always take a direct booking. We only require it to exist and be own-inventory.)
        $stay = $db->get('stays', ['id', 'currency', 'user_id'], ['id' => $stayId]);
        if (!$stay) { return ['ok' => false, 'message' => 'Property not found.']; }
        $currency = strtoupper(trim((string) ($stay['currency'] ?? 'USD'))) ?: 'USD';

        // SERVER-AUTHORITATIVE net (same helper the public submit uses).
        if (!function_exists('stays_recompute_net')) {
            $lib = __DIR__ . '/stays_inventory.php';
            if (is_file($lib)) { require_once $lib; }
        }
        if (!function_exists('stays_recompute_net')) {
            return ['ok' => false, 'message' => 'Pricing engine unavailable.'];
        }
        $selected = [['room_id' => $roomId, 'quantity' => $qty, 'option' => ['option_id' => $optionId]]];
        $netRes = stays_recompute_net($db, $stayId, $selected, $checkin, $checkout, $currency);
        if (empty($netRes['ok'])) {
            return ['ok' => false, 'message' => $netRes['message'] ?? 'Could not price that room for those dates.'];
        }
        $net = (float) $netRes['net'];

        // Markup + tax, mirroring the booking submit path.
        $markup = $net;
        if (function_exists('MARKUP')) {
            $moduleRow = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1'])
                ?: $db->get('modules', '*', ['type' => 'stays', 'status' => '1']);
            $mk = MARKUP($net, $moduleRow ?: null, $db, $currency, $currency);
            if (!empty($mk['price']) && (float) $mk['price'] > 0) { $markup = round((float) $mk['price'], 2); }
        }
        $taxAmount = 0.0;
        if (function_exists('calculateTax')) {
            $ti = calculateTax($markup, 'hotels', $db);
            $taxAmount = round((float) ($ti['tax_amount'] ?? 0), 2);
        }
        $finalTotal = round($markup + $taxAmount, 2);

        $invoiceId = resv_generate_invoice_id();
        $source = (string) ($in['source'] ?? 'walk_in');
        if (!array_key_exists($source, resv_booking_sources())) { $source = 'walk_in'; }
        $markPaid = !empty($in['mark_paid']);
        $now = date('Y-m-d H:i:s');

        $bookingData = [
            'hotel_id'       => $stayId,
            'supplier'       => 'hotels',
            'checkin'        => $checkin,
            'checkout'       => $checkout,
            'currency'       => $currency,
            'booking_source' => $source,
            'created_by_desk'=> (string) ($_SESSION['user_id'] ?? ''),
            'rooms_data'     => [['adults' => max(1, (int) ($in['adults'] ?? 1)), 'children' => max(0, (int) ($in['children'] ?? 0))]],
            'selected_rooms' => [[
                'room_id'  => $roomId,
                'quantity' => $qty,
                'option'   => ['option_id' => $optionId],
            ]],
        ];
        if (!empty($in['notes'])) { $bookingData['desk_notes'] = substr((string) $in['notes'], 0, 1000); }

        $result = ['ok' => false, 'message' => 'Could not create the reservation.'];
        try {
            $db->action(function ($db) use (
                $stayId, $roomId, $optionId, $qty, $checkin, $checkout, $invoiceId,
                $firstName, $lastName, $in, $net, $finalTotal, $taxAmount, $currency,
                $bookingData, $markPaid, $source, $now, &$result
            ) {
                // 1) Place the no-oversell hold FIRST — a failed hold aborts the whole
                //    create (can't take a walk-in for a room we don't have).
                if (function_exists('stays_hold_create')) {
                    $hold = stays_hold_create($db, $stayId, $roomId, $optionId, $checkin, $checkout, $qty, $invoiceId);
                    if (empty($hold['ok'])) {
                        $result = ['ok' => false, 'message' => $hold['message'] ?? 'Those dates are not available.'];
                        return false; // rollback
                    }
                }

                // 2) Insert the booking row the rest of the PMS recognizes.
                $db->insert('bookings', [
                    'invoice_id'      => $invoiceId,
                    'module_type'     => 'stays',
                    'module'          => 'hotels',
                    'booking_date'    => $now,
                    'booking_status'  => 'confirmed',
                    'payment_status'  => $markPaid ? 'paid' : 'unpaid',
                    'paid_at'         => $markPaid ? $now : null,
                    'price_original'  => round($net, 2),
                    'price_markup'    => $finalTotal,
                    'currency_original' => $currency,
                    'currency_markup' => $currency,
                    'tax'             => $taxAmount,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'email'           => trim((string) ($in['email'] ?? '')) ?: null,
                    'phone'           => trim((string) ($in['phone'] ?? '')) ?: null,
                    'adults'          => max(1, (int) ($in['adults'] ?? 1)),
                    'childs'          => max(0, (int) ($in['children'] ?? 0)),
                    'booking_data'    => json_encode($bookingData),
                    'payment_gateway' => $markPaid ? 'Front desk' : null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                if ((int) $db->id() <= 0) {
                    $result = ['ok' => false, 'message' => 'Could not save the reservation.'];
                    return false;
                }

                // 3) If paid at the desk, consume the hold (permanent reservation).
                if ($markPaid && function_exists('stays_hold_consume_ref')) {
                    stays_hold_consume_ref($db, $invoiceId);
                }

                $result = ['ok' => true, 'invoice_id' => $invoiceId,
                           'message' => 'Reservation ' . $invoiceId . ' created.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('resv_create_walkin: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create the reservation.'];
        }
        return $result;
    }
}

if (!function_exists('resv_set_source')) {
    /** Tag an existing reservation's booking source. Returns bool. */
    function resv_set_source($db, string $invoiceId, string $source): bool
    {
        if (!array_key_exists($source, resv_booking_sources())) { return false; }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $bd['booking_source'] = $source;
            $db->update('bookings',
                ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) {
            error_log('resv_set_source: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('resv_waitlist_add')) {
    /**
     * Record a waitlisted request for a property/room/date range. No inventory is held
     * — it is a lead the desk can convert to a walk-in when space frees up. Stored in
     * stays_waitlist (created by ensureSupplierStaysSchema).
     * @return array ['ok'=>bool, 'id'=>?int, 'message'=>string]
     */
    function resv_waitlist_add($db, int $stayId, array $in): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'message' => 'No property']; }
        $checkin  = trim((string) ($in['checkin'] ?? ''));
        $checkout = trim((string) ($in['checkout'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)) {
            return ['ok' => false, 'message' => 'Valid dates are required.'];
        }
        $name = trim((string) ($in['guest_name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'Guest name is required.']; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_waitlist', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'room_id'     => (int) ($in['room_id'] ?? 0) ?: null,
                'guest_name'  => substr($name, 0, 120),
                'email'       => trim((string) ($in['email'] ?? '')) ?: null,
                'phone'       => trim((string) ($in['phone'] ?? '')) ?: null,
                'checkin'     => $checkin,
                'checkout'    => $checkout,
                'pax'         => max(1, (int) ($in['pax'] ?? 1)),
                'status'      => 'waiting',
                'notes'       => substr(trim((string) ($in['notes'] ?? '')), 0, 1000) ?: null,
                'created_by'  => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            return $id > 0
                ? ['ok' => true, 'id' => $id, 'message' => 'Added to the waitlist.']
                : ['ok' => false, 'message' => 'Could not add to the waitlist.'];
        } catch (\Throwable $e) {
            error_log('resv_waitlist_add: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not add to the waitlist.'];
        }
    }
}

if (!function_exists('resv_waitlist_set_status')) {
    /** Update a waitlist row's status (waiting|converted|cancelled). Owner-scoped by stay. */
    function resv_waitlist_set_status($db, int $waitId, int $stayId, string $status): bool
    {
        if (!in_array($status, ['waiting', 'converted', 'cancelled'], true)) { return false; }
        try {
            $row = $db->get('stays_waitlist', ['id'], ['id' => $waitId, 'stay_id' => $stayId]);
            if (!$row) { return false; }
            $db->update('stays_waitlist',
                ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $waitId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('resv_waitlist_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('resv_set_vip')) {
    /** Flag/unflag a reservation as VIP (stored in booking_data). (PMS #07 front desk.) */
    function resv_set_vip($db, string $invoiceId, bool $vip): bool
    {
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            if ($vip) { $bd['vip'] = true; $bd['vip_at'] = date('Y-m-d H:i:s'); }
            else { unset($bd['vip'], $bd['vip_at']); }
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) { error_log('resv_set_vip: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('resv_add_trace')) {
    /**
     * Add a front-desk TRACE (an operational note/reminder for a date — housekeeping,
     * guest-service, follow-up). Stored as an array in booking_data.traces. (PMS #07.)
     * @return bool
     */
    function resv_add_trace($db, string $invoiceId, string $note, string $forDate = ''): bool
    {
        $note = trim($note);
        if ($note === '') { return false; }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            if (!isset($bd['traces']) || !is_array($bd['traces'])) { $bd['traces'] = []; }
            $bd['traces'][] = [
                'note'     => substr($note, 0, 500),
                'for_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $forDate) ? $forDate : null,
                'by'       => (string) ($_SESSION['user_id'] ?? ''),
                'at'       => date('Y-m-d H:i:s'),
                'done'     => false,
            ];
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) { error_log('resv_add_trace: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('resv_resolve_trace')) {
    /** Mark the Nth trace (0-based index) resolved. (PMS #07.) */
    function resv_resolve_trace($db, string $invoiceId, int $index): bool
    {
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd) || !isset($bd['traces'][$index])) { return false; }
            $bd['traces'][$index]['done'] = true;
            $bd['traces'][$index]['done_at'] = date('Y-m-d H:i:s');
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) { error_log('resv_resolve_trace: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('resv_deposit_set')) {
    /**
     * SECURITY-DEPOSIT lifecycle on a reservation (PMS #39). Records that a refundable
     * security deposit was taken at the property and tracks its disposition. This is
     * OPERATIONAL record-keeping — it does NOT charge/refund through the payment rails
     * (deposits are collected/released at the desk; auto-charging a card deposit is a
     * rails feature, deferred). Stored in booking_data.deposit.
     * $action ∈ hold (record taken) | release (refunded in full) | deduct (kept, with
     * an amount + reason). Returns ['ok','message'].
     */
    function resv_deposit_set($db, string $invoiceId, string $action, float $amount = 0.0, string $reason = ''): array
    {
        if (!in_array($action, ['hold', 'release', 'deduct'], true)) { return ['ok' => false, 'message' => 'Unknown deposit action']; }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Booking not found.']; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $dep = (isset($bd['deposit']) && is_array($bd['deposit'])) ? $bd['deposit'] : [];
            $now = date('Y-m-d H:i:s');
            $actor = (string) ($_SESSION['user_id'] ?? '');
            if ($action === 'hold') {
                if ($amount <= 0) { return ['ok' => false, 'message' => 'Enter the deposit amount taken.']; }
                $dep = ['state' => 'held', 'amount' => round($amount, 2), 'held_at' => $now, 'by' => $actor];
                $msg = 'Security deposit recorded.';
            } elseif ($action === 'release') {
                if (($dep['state'] ?? '') !== 'held') { return ['ok' => false, 'message' => 'No held deposit to release.']; }
                $dep['state'] = 'released'; $dep['released_at'] = $now; $dep['released_by'] = $actor;
                $msg = 'Deposit released in full.';
            } else { // deduct
                if (($dep['state'] ?? '') !== 'held') { return ['ok' => false, 'message' => 'No held deposit to deduct from.']; }
                $held = (float) ($dep['amount'] ?? 0);
                $amount = round(max(0, min($amount, $held)), 2); // can't deduct more than held
                $dep['state'] = 'deducted'; $dep['deducted'] = $amount;
                $dep['refunded'] = round($held - $amount, 2);
                $dep['reason'] = substr(trim($reason), 0, 255) ?: null;
                $dep['deducted_at'] = $now; $dep['deducted_by'] = $actor;
                $msg = 'Deposit: ' . number_format($amount, 2) . ' deducted, ' . number_format($held - $amount, 2) . ' refunded.';
            }
            $bd['deposit'] = $dep;
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => $now], ['invoice_id' => $invoiceId]);
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            error_log('resv_deposit_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the deposit.'];
        }
    }
}

if (!function_exists('_resv_load_for_reprice')) {
    /**
     * Internal: load a reservation's repricing context from booking_data. Returns
     * ['ok','bd','hid','room_id','option_id','qty','checkin','checkout','paid','currency']
     * or ['ok'=>false,'message'].
     */
    function _resv_load_for_reprice($db, string $invoiceId): array
    {
        $b = $db->get('bookings',
            ['invoice_id', 'payment_status', 'booking_status', 'booking_data', 'currency_markup'],
            ['invoice_id' => $invoiceId]);
        if (!$b) { return ['ok' => false, 'message' => 'Reservation not found.']; }
        if (strtolower((string) $b['booking_status']) === 'cancelled') {
            return ['ok' => false, 'message' => 'This reservation is cancelled.'];
        }
        $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
        if (!is_array($bd)) { return ['ok' => false, 'message' => 'Reservation data is unreadable.']; }
        $hid = (int) ($bd['hotel_id'] ?? $bd['id'] ?? 0);
        $sel = $bd['selected_rooms'] ?? [];
        $first = (is_array($sel) && !empty($sel)) ? $sel[0] : [];
        $roomId   = (int) ($first['room_id'] ?? 0);
        $optionId = (int) ($first['option']['option_id'] ?? ($first['option_id'] ?? 0));
        $qty      = max(1, (int) ($first['quantity'] ?? 1));
        if ($hid <= 0 || $roomId <= 0 || $optionId <= 0) {
            return ['ok' => false, 'message' => 'This reservation has no priceable room (legacy booking); dates/room cannot be changed here.'];
        }
        return [
            'ok' => true, 'bd' => $bd, 'hid' => $hid,
            'room_id' => $roomId, 'option_id' => $optionId, 'qty' => $qty,
            'checkin' => (string) ($bd['checkin'] ?? ''), 'checkout' => (string) ($bd['checkout'] ?? ''),
            'paid' => strtolower((string) $b['payment_status']) === 'paid',
            'currency' => strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD',
        ];
    }
}

if (!function_exists('_resv_reprice_and_rehold')) {
    /**
     * The shared engine for modify-dates and transfer-room: given a reservation and a
     * NEW (room_id, option_id, qty, checkin, checkout), atomically:
     *   1. supersede the OLD holds (held + consumed) for this invoice,
     *   2. create the NEW hold (fail-closed → rollback restores the old holds),
     *   3. if the booking was paid, consume the new hold (permanent),
     *   4. recompute net+markup+tax server-side and update the booking money + dates +
     *      selected_rooms in booking_data,
     *   5. adjust the folio's room+tax lines to match (only if an OPEN folio exists).
     * Returns ['ok','message','old_total','new_total'].
     */
    function _resv_reprice_and_rehold($db, string $invoiceId, int $stayId, int $newRoomId, int $newOptionId, int $newQty, string $newCheckin, string $newCheckout, array $ctx): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newCheckin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newCheckout)
            || strtotime($newCheckout) <= strtotime($newCheckin)) {
            return ['ok' => false, 'message' => 'Valid dates are required (check-out after check-in).'];
        }
        if (!function_exists('stays_recompute_net') || !function_exists('stays_hold_create')) {
            $lib = __DIR__ . '/stays_inventory.php';
            if (is_file($lib)) { require_once $lib; }
        }
        if (!function_exists('stays_recompute_net')) {
            return ['ok' => false, 'message' => 'Pricing engine unavailable.'];
        }

        $currency = $ctx['currency'];
        $selected = [['room_id' => $newRoomId, 'quantity' => $newQty, 'option' => ['option_id' => $newOptionId]]];
        $netRes = stays_recompute_net($db, $stayId, $selected, $newCheckin, $newCheckout, $currency);
        if (empty($netRes['ok'])) {
            return ['ok' => false, 'message' => $netRes['message'] ?? 'Could not price the new selection.'];
        }
        $net = (float) $netRes['net'];
        $markup = $net;
        if (function_exists('MARKUP')) {
            $moduleRow = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1'])
                ?: $db->get('modules', '*', ['type' => 'stays', 'status' => '1']);
            $mk = MARKUP($net, $moduleRow ?: null, $db, $currency, $currency);
            if (!empty($mk['price']) && (float) $mk['price'] > 0) { $markup = round((float) $mk['price'], 2); }
        }
        $tax = 0.0;
        if (function_exists('calculateTax')) {
            $ti = calculateTax($markup, 'hotels', $db);
            $tax = round((float) ($ti['tax_amount'] ?? 0), 2);
        }
        $finalTotal = round($markup + $tax, 2);
        $wasPaid = !empty($ctx['paid']);
        $now = date('Y-m-d H:i:s');

        $result = ['ok' => false, 'message' => 'Could not change the reservation.'];
        try {
            $db->action(function ($db) use (
                $invoiceId, $stayId, $newRoomId, $newOptionId, $newQty, $newCheckin, $newCheckout,
                $ctx, $net, $markup, $tax, $finalTotal, $wasPaid, $now, &$result
            ) {
                // 1) Free the old nights/room (held + consumed) for THIS invoice.
                if (function_exists('stays_hold_supersede_ref')) {
                    stays_hold_supersede_ref($db, $invoiceId);
                }
                // 2) Hold the new selection. Fail-closed → rollback restores the old holds.
                $hold = stays_hold_create($db, $stayId, $newRoomId, $newOptionId, $newCheckin, $newCheckout, $newQty, $invoiceId);
                if (empty($hold['ok'])) {
                    $result = ['ok' => false, 'message' => $hold['message'] ?? 'The new dates/room are not available.'];
                    return false; // rollback — original holds are restored
                }
                // 3) If the booking was paid, the new hold is permanent.
                if ($wasPaid && function_exists('stays_hold_consume_ref')) {
                    stays_hold_consume_ref($db, $invoiceId);
                }
                // 4) Update the booking money + dates + selected_rooms.
                $bd = $ctx['bd'];
                $bd['checkin']  = $newCheckin;
                $bd['checkout'] = $newCheckout;
                $bd['selected_rooms'] = [[
                    'room_id'  => $newRoomId,
                    'quantity' => $newQty,
                    'option'   => ['option_id' => $newOptionId],
                ]];
                $bd['last_modified_by_desk'] = (string) ($_SESSION['user_id'] ?? '');
                $bd['last_modified_at'] = $now;
                $db->update('bookings', [
                    'price_original'  => round($net, 2),
                    'price_markup'    => $finalTotal,
                    'tax'             => $tax,
                    'booking_data'    => json_encode($bd),
                    'updated_at'      => $now,
                ], ['invoice_id' => $invoiceId]);

                // 5) Re-sync an OPEN folio's room + tax lines to the new figures. We do
                //    NOT touch payment/extra lines — only the system room/tax seed. If
                //    the folio is closed (checked out) we leave it (historical record).
                $folio = $db->get('stays_folios', ['id', 'status'], ['invoice_id' => $invoiceId]);
                if ($folio && $folio['status'] === 'open') {
                    $fid = (int) $folio['id'];
                    $room = round($markup, 2); // folio 'room' = guest room charge pre-tax (= markup)
                    // Replace the system-seeded room/tax lines; keep everything else.
                    $db->delete('stays_folio_items', ['folio_id' => $fid, 'type' => ['room', 'tax'], 'created_by' => 'system']);
                    if ($room > 0) {
                        $db->insert('stays_folio_items', ['folio_id' => $fid, 'type' => 'room',
                            'description' => 'Room charge (revised)', 'amount' => $room, 'created_by' => 'system', 'created_at' => $now]);
                    }
                    if ($tax > 0) {
                        $db->insert('stays_folio_items', ['folio_id' => $fid, 'type' => 'tax',
                            'description' => 'Taxes & fees (revised)', 'amount' => $tax, 'created_by' => 'system', 'created_at' => $now]);
                    }
                }

                $result = ['ok' => true, 'message' => 'Reservation updated.', 'new_total' => $finalTotal];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('_resv_reprice_and_rehold: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not change the reservation.'];
        }
        return $result;
    }
}

if (!function_exists('resv_modify_dates')) {
    /**
     * Change a reservation's check-in/out (move / extend / shorten). Same room/option/
     * qty, new dates. Re-holds inventory atomically and re-prices. Returns the ops array.
     */
    function resv_modify_dates($db, string $invoiceId, string $newCheckin, string $newCheckout): array
    {
        $ctx = _resv_load_for_reprice($db, $invoiceId);
        if (empty($ctx['ok'])) { return $ctx; }
        return _resv_reprice_and_rehold(
            $db, $invoiceId, $ctx['hid'],
            $ctx['room_id'], $ctx['option_id'], $ctx['qty'],
            $newCheckin, $newCheckout, $ctx
        );
    }
}

if (!function_exists('resv_transfer_room')) {
    /**
     * Move a reservation to a different room/option (same dates unless also changed).
     * Re-holds inventory atomically and re-prices. The new room/option must belong to
     * the SAME property (own inventory) — the caller has already authorized the stay.
     */
    function resv_transfer_room($db, string $invoiceId, int $newRoomId, int $newOptionId, ?int $newQty = null): array
    {
        $ctx = _resv_load_for_reprice($db, $invoiceId);
        if (empty($ctx['ok'])) { return $ctx; }
        if ($newRoomId <= 0 || $newOptionId <= 0) {
            return ['ok' => false, 'message' => 'Pick a destination room and rate.'];
        }
        // Verify the destination room belongs to this property (never cross-property).
        $room = $db->get('stays_rooms', ['id'], ['id' => $newRoomId, 'stay_id' => $ctx['hid']]);
        if (!$room) { return ['ok' => false, 'message' => 'That room is not part of this property.']; }
        $qty = ($newQty !== null && $newQty > 0) ? $newQty : $ctx['qty'];
        return _resv_reprice_and_rehold(
            $db, $invoiceId, $ctx['hid'],
            $newRoomId, $newOptionId, $qty,
            $ctx['checkin'], $ctx['checkout'], $ctx
        );
    }
}
