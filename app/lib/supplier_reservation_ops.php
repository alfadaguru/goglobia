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

if (!function_exists('booking_data_mutate')) {
    /**
     * ATOMIC read-modify-write of a booking's booking_data JSON (audit HIGH fix).
     *
     * The whole front desk stored per-reservation state (check-in/out state, security
     * deposit, no-show, repeat/source flags, room assignment, ...) as keys inside the
     * single booking_data JSON column, and every mutator did an UNLOCKED
     * read -> decode -> change one key -> write the WHOLE column back. Two concurrent
     * desk actions on the same reservation both read the old blob, and the second write
     * clobbered the first — e.g. recording a security deposit while a colleague checks
     * the guest in lost whichever landed second (deposit money-state gone, or the
     * check-in state gone so checkout later refuses). The folio FOR UPDATE locks are on
     * stays_folios, not the bookings row, so they never covered this.
     *
     * This runs the mutation inside a transaction with the bookings ROW locked
     * (SELECT ... FOR UPDATE), decodes the FRESH booking_data (so it sees any concurrent
     * writer's committed change), hands it to $mutator by reference to apply ONLY its own
     * change, and writes it back. Concurrent callers serialise on the row lock, so no
     * update is lost. Transaction-aware (stays_tx_run) so it is safe if a caller already
     * opened a transaction.
     *
     * $mutator(array &$bd, array $row): mixed
     *   - mutate $bd in place; return the caller's result value.
     *   - return (or set via a captured &$ref) ['ok'=>false, ...] / false to ABORT with
     *     NO write (e.g. "no held deposit to release") — checked against the FRESH state.
     *
     * $extraCols: additional bookings columns to write alongside booking_data in the same
     *   locked update (e.g. booking_status on cancel). Values are written verbatim.
     *
     * Returns ['ok'=>bool, 'result'=>mixed, 'message'=>?] — 'result' is the mutator's
     * return value when it committed. On a missing booking: ['ok'=>false,'message'=>...].
     */
    function booking_data_mutate($db, string $invoiceId, callable $mutator, array $extraCols = []): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No invoice.']; }
        $out = ['ok' => false, 'message' => 'Could not update the reservation.'];
        $runner = function_exists('stays_tx_run') ? 'stays_tx_run' : null;
        $work = function ($db) use ($invoiceId, $mutator, $extraCols, &$out) {
            // Lock the bookings row and re-read booking_data FRESH under the lock. Raw
            // query — Medoo get() has no row-lock option (matches supplier_folio.php).
            $st = $db->query('SELECT booking_data FROM bookings WHERE invoice_id = :inv FOR UPDATE', [':inv' => $invoiceId]);
            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$row) { $out = ['ok' => false, 'message' => 'Reservation not found.']; return false; }
            $bd = json_decode((string) ($row['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }

            $abort = false;
            $result = $mutator($bd, $row, $abort);
            // A mutator aborts by (a) setting the by-ref $abort flag, or (b) returning an
            // array with ok===false, or (c) returning the literal false. In all cases we
            // write NOTHING and roll back (no partial state).
            if ($abort === true || $result === false
                || (is_array($result) && array_key_exists('ok', $result) && $result['ok'] === false)) {
                $out = is_array($result) ? (['ok' => false] + $result) : ['ok' => false, 'message' => 'No change.'];
                return false;
            }
            $cols = $extraCols + [
                'booking_data' => json_encode($bd),
                'updated_at'   => date('Y-m-d H:i:s'),
            ];
            $db->update('bookings', $cols, ['invoice_id' => $invoiceId]);
            $out = ['ok' => true, 'result' => $result];
            return true;
        };
        try {
            if ($runner) { $runner($db, $work); }
            else { $db->action($work); }
        } catch (\Throwable $e) {
            error_log('booking_data_mutate(' . $invoiceId . '): ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the reservation.'];
        }
        return $out;
    }
}

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

        // CONSOLIDATION C2 — link this booking to a CUSTOMER (the payer). Resolve BEFORE
        // the booking transaction (the resolver does its own writes via contacts_save):
        // use an explicitly-picked customer if the desk chose one, else find-or-create an
        // individual customer from the guest's name/email/phone (deduped, so a repeat
        // guest reuses their record). customer_id 0 → leave NULL (never blocks the booking).
        // Resolve the org from the property owner.
        $custId = 0; $guestId = 0;
        $orgForContacts = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
        if ($orgForContacts <= 0 && function_exists('supplier_property_org_ensure')) {
            $orgForContacts = (int) supplier_property_org_ensure($db, $stayId);
        }
        if ($orgForContacts > 0 && function_exists('contacts_resolve_for_booking')) {
            $custId = (int) contacts_resolve_for_booking($db, $orgForContacts, [
                'customer_id' => (int) ($in['customer_id'] ?? 0),
                'first_name'  => $firstName, 'last_name' => $lastName,
                'email'       => (string) ($in['email'] ?? ''), 'phone' => (string) ($in['phone'] ?? ''),
            ]);
        }
        // Store the GUEST (the stayer) as a first-class record — ALWAYS, even with no
        // email (phone-primary identity). Links back to the payer via customer_id.
        if ($orgForContacts > 0 && function_exists('guests_resolve_for_booking')) {
            $guestId = (int) guests_resolve_for_booking($db, $orgForContacts, [
                'guest_id'    => (int) ($in['guest_id'] ?? 0),
                'first_name'  => $firstName, 'last_name' => $lastName,
                'email'       => (string) ($in['email'] ?? ''), 'phone' => (string) ($in['phone'] ?? ''),
                'id_doc'      => (string) ($in['id_doc'] ?? ''), 'nationality' => (string) ($in['nationality'] ?? ''),
                'customer_id' => $custId,
            ]);
        }

        $result = ['ok' => false, 'message' => 'Could not create the reservation.'];
        try {
            $db->action(function ($db) use (
                $stayId, $roomId, $optionId, $qty, $checkin, $checkout, $invoiceId,
                $firstName, $lastName, $in, $net, $finalTotal, $taxAmount, $currency,
                $bookingData, $markPaid, $source, $now, $custId, $guestId, &$result
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
                    // NB: the bookings table has only `currency_markup` (install/db.sql);
                    // there is no `currency_original` column — inserting it threw 1054 and
                    // (together with the nested-transaction bug) was a second reason every
                    // walk-in failed. The whole codebase keys currency off currency_markup.
                    'currency_markup' => $currency,
                    'tax'             => $taxAmount,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    // These columns are NOT NULL in the bookings schema (install/db.sql).
                    // A walk-in legitimately has little contact data (no email/phone is
                    // normal), so default the missing ones to '' rather than NULL —
                    // inserting NULL / omitting them threw 1048 "cannot be null" and was a
                    // third reason every walk-in failed.
                    'email'              => trim((string) ($in['email'] ?? '')),
                    'phone'              => trim((string) ($in['phone'] ?? '')),
                    'phone_country_code' => trim((string) ($in['phone_country_code'] ?? '')),
                    'country'            => trim((string) ($in['country'] ?? '')),
                    'address'            => trim((string) ($in['address'] ?? '')),
                    'child_ages'         => trim((string) ($in['child_ages'] ?? '')),
                    'travellers'         => trim((string) ($in['travellers'] ?? '')),
                    'adults'          => max(1, (int) ($in['adults'] ?? 1)),
                    'childs'          => max(0, (int) ($in['children'] ?? 0)),
                    'booking_data'    => json_encode($bookingData),
                    'customer_id'     => $custId > 0 ? $custId : null, // C2: booking→payer link
                    'guest_id'        => $guestId > 0 ? $guestId : null, // booking→stayer link
                    'payment_gateway' => $markPaid ? 'Front desk' : null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                if ((int) $db->id() <= 0) {
                    $result = ['ok' => false, 'message' => 'Could not save the reservation.'];
                    return false;
                }

                // 3) CONSUME the hold → permanent reservation. A front-desk walk-in/phone
                //    booking is created booking_status='confirmed' (a firm commitment the
                //    hotel honors), so its inventory must be reserved REGARDLESS of payment
                //    — "pay at property" is the normal case. Previously this only fired when
                //    $markPaid, so an unpaid confirmed reservation held its rooms only for
                //    the ~20-min hold TTL and then the nights became re-sellable (oversell
                //    of a firm reservation). Consuming unconditionally closes that window.
                //    stays_hold_consume_ref is idempotent (state-guarded held→consumed).
                if (function_exists('stays_hold_consume_ref')) {
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
        // ATOMIC booking_data mutation (audit fix) — row-locked, fresh re-read.
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($source) {
            $bd['booking_source'] = $source;
            return true;
        });
        return !empty($res['ok']);
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

if (!function_exists('resv_online_checkin')) {
    /**
     * DIGITAL GUEST JOURNEY (#20): a guest completes online check-in against their own
     * booking (by invoice). Stores arrival details + a flag in booking_data.online_checkin
     * — it does NOT change the PMS stay-state (the front desk still formally checks them
     * in). Guest-facing: identity is proven by knowing the invoice id (same trust model
     * as the invoice/booking pages). Idempotent (re-submitting updates the details).
     * Returns ['ok','message'].
     */
    function resv_online_checkin($db, string $invoiceId, array $d): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No booking reference.']; }
        // Value-stable guards first (type / cancelled don't change under this flow).
        $b = $db->get('bookings', ['booking_status', 'module_type'], ['invoice_id' => $invoiceId]);
        if (!$b) { return ['ok' => false, 'message' => 'Booking not found.']; }
        if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay booking.']; }
        if (strtolower((string) $b['booking_status']) === 'cancelled') { return ['ok' => false, 'message' => 'This booking is cancelled.']; }
        // ATOMIC booking_data mutation (audit fix) — row-locked, fresh re-read.
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($d) {
            $bd['online_checkin'] = [
                'done'          => true,
                'eta'           => substr(trim((string) ($d['eta'] ?? '')), 0, 20) ?: null,
                'id_number'     => substr(trim((string) ($d['id_number'] ?? '')), 0, 60) ?: null,
                'address'       => substr(trim((string) ($d['address'] ?? '')), 0, 255) ?: null,
                'special_requests' => substr(trim((string) ($d['special_requests'] ?? '')), 0, 500) ?: null,
                'submitted_at'  => date('Y-m-d H:i:s'),
            ];
            return true;
        });
        if (empty($res['ok'])) { return ['ok' => false, 'message' => 'Could not complete check-in.']; }
        return ['ok' => true, 'message' => 'Online check-in complete — see you soon!'];
    }
}

if (!function_exists('resv_set_vip')) {
    /** Flag/unflag a reservation as VIP (stored in booking_data). (PMS #07 front desk.) */
    function resv_set_vip($db, string $invoiceId, bool $vip): bool
    {
        // ATOMIC booking_data mutation (audit fix) — row-locked, fresh re-read.
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($vip) {
            if ($vip) { $bd['vip'] = true; $bd['vip_at'] = date('Y-m-d H:i:s'); }
            else { unset($bd['vip'], $bd['vip_at']); }
            return true;
        });
        return !empty($res['ok']);
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
        // ATOMIC (audit fix): a trace APPENDS to booking_data.traces[]. Two concurrent
        // traces on a stale blob would drop one; the row lock + fresh re-read serialises
        // the append so every trace is kept.
        $actor = (string) ($_SESSION['user_id'] ?? '');
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($note, $forDate, $actor) {
            if (!isset($bd['traces']) || !is_array($bd['traces'])) { $bd['traces'] = []; }
            $bd['traces'][] = [
                'note'     => substr($note, 0, 500),
                'for_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $forDate) ? $forDate : null,
                'by'       => $actor,
                'at'       => date('Y-m-d H:i:s'),
                'done'     => false,
            ];
            return true;
        });
        return !empty($res['ok']);
    }
}

if (!function_exists('resv_resolve_trace')) {
    /** Mark the Nth trace (0-based index) resolved. (PMS #07.) */
    function resv_resolve_trace($db, string $invoiceId, int $index): bool
    {
        // ATOMIC (audit fix): resolving the Nth trace indexes into booking_data.traces,
        // which a concurrent add_trace reorders — row-lock + fresh re-read so the index
        // is resolved against the current trace list.
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd, array $row, bool &$abort) use ($index) {
            if (!isset($bd['traces'][$index])) { $abort = true; return false; }
            $bd['traces'][$index]['done'] = true;
            $bd['traces'][$index]['done_at'] = date('Y-m-d H:i:s');
            return true;
        });
        return !empty($res['ok']);
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
        // ATOMIC: the deposit money-state lives in booking_data; mutate it under a row
        // lock (fresh re-read) so a concurrent check-in / no-show can't clobber it, and
        // the state checks below run against the FRESH deposit, not a stale read.
        $now = date('Y-m-d H:i:s');
        $actor = (string) ($_SESSION['user_id'] ?? '');
        $msg = '';
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd, array $row, bool &$abort) use ($action, &$amount, $reason, $now, $actor, &$msg) {
            $dep = (isset($bd['deposit']) && is_array($bd['deposit'])) ? $bd['deposit'] : [];
            if ($action === 'hold') {
                if ($amount <= 0) { $abort = true; return ['ok' => false, 'message' => 'Enter the deposit amount taken.']; }
                $dep = ['state' => 'held', 'amount' => round($amount, 2), 'held_at' => $now, 'by' => $actor];
                $msg = 'Security deposit recorded.';
            } elseif ($action === 'release') {
                if (($dep['state'] ?? '') !== 'held') { $abort = true; return ['ok' => false, 'message' => 'No held deposit to release.']; }
                $dep['state'] = 'released'; $dep['released_at'] = $now; $dep['released_by'] = $actor;
                $msg = 'Deposit released in full.';
            } else { // deduct
                if (($dep['state'] ?? '') !== 'held') { $abort = true; return ['ok' => false, 'message' => 'No held deposit to deduct from.']; }
                $held = (float) ($dep['amount'] ?? 0);
                $amount = round(max(0, min($amount, $held)), 2); // can't deduct more than held
                $dep['state'] = 'deducted'; $dep['deducted'] = $amount;
                $dep['refunded'] = round($held - $amount, 2);
                $dep['reason'] = substr(trim($reason), 0, 255) ?: null;
                $dep['deducted_at'] = $now; $dep['deducted_by'] = $actor;
                $msg = 'Deposit: ' . number_format($amount, 2) . ' deducted, ' . number_format($held - $amount, 2) . ' refunded.';
            }
            $bd['deposit'] = $dep;
            return true;
        });
        if (empty($res['ok'])) { return ['ok' => false, 'message' => $res['message'] ?? 'Could not update the deposit.']; }
        return ['ok' => true, 'message' => $msg];
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
                // 3) The new hold is permanent for a CONFIRMED reservation — same
                //    invariant as create: a confirmed booking holds its inventory
                //    regardless of payment (pay-at-property is normal). Previously gated
                //    on $wasPaid, which left a modified unpaid-confirmed reservation with
                //    only an expiring hold → same oversell window as the create path.
                //    stays_hold_consume_ref is idempotent (state-guarded held→consumed).
                if (function_exists('stays_hold_consume_ref')) {
                    stays_hold_consume_ref($db, $invoiceId);
                }
                // 4) Update the booking money + dates + selected_rooms.
                $bd = $ctx['bd'];
                $bd['checkin']  = $newCheckin;
                $bd['checkout'] = $newCheckout;
                // CLEAR a now-mismatched physical-room assignment (audit N9). An assigned
                // physical room belongs to a specific sellable room (stays_physical_rooms.
                // room_id). If this transfer changes the booked room/type, the old physical
                // unit no longer matches — leaving pms_physical_room_id pointing at it made
                // the tape chart/front-desk show the guest in the wrong-type room, marked the
                // wrong room dirty on checkout, and kept it blocking under a type the
                // reservation no longer books. Drop the assignment (the desk re-assigns a
                // room of the new type) when the mapped room_id differs; keep it when the
                // physical room still belongs to the new room (e.g. a dates-only modify).
                $prevPrid = (int) ($bd['pms_physical_room_id'] ?? 0);
                if ($prevPrid > 0) {
                    $prMappedRoom = (int) ($db->get('stays_physical_rooms', 'room_id', ['id' => $prevPrid]) ?: 0);
                    if ($prMappedRoom !== (int) $newRoomId) {
                        unset($bd['pms_physical_room_id']);
                    }
                }
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

// ===========================================================================
// MODULE 04 DEEPEN: front-desk dashboard, room rack / tape chart, clone/repeat.
// All read-only aggregations over the owner's reservations + a safe clone action.
// ===========================================================================

if (!function_exists('resv_dashboard_buckets')) {
    /**
     * Front-desk dashboard for a business date: arrivals (checkin==date), departures
     * (checkout==date), in-house (checkin<=date<checkout) and stayovers. Operates on the
     * already-ownership-filtered reservation rows from supplier_fetch_reservations().
     * Cancelled rows are excluded. Returns counts + the row lists.
     */
    function resv_dashboard_buckets(array $reservations, string $date): array
    {
        $out = ['date' => $date, 'arrivals' => [], 'departures' => [], 'in_house' => [], 'stayover' => [],
                'arrivals_n' => 0, 'departures_n' => 0, 'in_house_n' => 0, 'stayover_n' => 0];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        foreach ($reservations as $r) {
            if (strtolower((string) ($r['booking_status'] ?? '')) === 'cancelled') { continue; }
            $ci = (string) ($r['_checkin'] ?? ''); $co = (string) ($r['_checkout'] ?? '');
            if ($ci === '' || $co === '') { continue; }
            if ($ci === $date) { $out['arrivals'][] = $r; }
            if ($co === $date) { $out['departures'][] = $r; }
            if ($ci <= $date && $date < $co) {
                $out['in_house'][] = $r;
                if ($ci < $date) { $out['stayover'][] = $r; } // in-house but not arriving today
            }
        }
        $out['arrivals_n'] = count($out['arrivals']);
        $out['departures_n'] = count($out['departures']);
        $out['in_house_n'] = count($out['in_house']);
        $out['stayover_n'] = count($out['stayover']);
        return $out;
    }
}

if (!function_exists('fd_vip_map')) {
    /**
     * C4 (console-as-hub): batch-resolve which of these reservations belong to a VIP
     * guest, in ONE query, so the front-desk lists can flag VIPs without an N+1 lookup.
     * VIP lives in stays_guest_profiles keyed by (org_id, guest_email_key). Returns a
     * set of normalised email keys that are VIP => [emailKey => true]. The caller matches
     * a row by guest_email_key($row['email']). Fail-safe: returns [] on any error.
     */
    function fd_vip_map($db, int $orgId, array $reservations): array
    {
        if ($orgId <= 0 || empty($reservations) || !function_exists('guest_email_key')) { return []; }
        $keys = [];
        foreach ($reservations as $r) {
            $k = guest_email_key((string) ($r['email'] ?? ''));
            if ($k !== '') { $keys[$k] = true; }
        }
        if (empty($keys)) { return []; }
        try {
            $rows = $db->select('stays_guest_profiles', ['email'],
                ['org_id' => $orgId, 'email' => array_keys($keys), 'vip' => 1]) ?: [];
            $vip = [];
            foreach ($rows as $row) { $vip[(string) $row['email']] = true; }
            return $vip;
        } catch (\Throwable $e) { error_log('fd_vip_map: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('resv_tape_chart')) {
    /**
     * Room rack / tape chart for one property over a date window: a grid of physical
     * rooms (rows) × dates (cols), each cell marked occupied when a non-cancelled
     * reservation for that room covers that night. Reservations are matched to a physical
     * room by booking_data.pms_physical_room_id when present; otherwise listed in an
     * "unassigned" lane so the desk can still see demand. Read-only.
     * Returns ['dates'=>[...], 'rows'=>[ ['room'=>.., 'cells'=>[date=>resv|null] ] ],
     *          'unassigned'=>[ ['label','checkin','checkout','invoice'] ] ].
     */
    function resv_tape_chart($db, int $stayId, array $reservations, string $from, int $days = 14): array
    {
        $out = ['dates' => [], 'rows' => [], 'unassigned' => []];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { return $out; }
        $days = max(1, min(60, $days));
        $dates = [];
        $t = strtotime($from);
        for ($i = 0; $i < $days; $i++) { $dates[] = date('Y-m-d', $t); $t = strtotime('+1 day', $t); }
        $out['dates'] = $dates;
        // Physical rooms for this property.
        $phys = [];
        try { $phys = $db->select('stays_physical_rooms', ['id', 'room_number', 'floor', 'floor_id', 'room_id'],
            ['stay_id' => $stayId, 'active' => 1, 'ORDER' => ['room_number' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
        // Resolve each room's TYPE NAME (Deluxe / King / …) so the rack reads "Deluxe D-12".
        $typeByRoomId = [];
        try {
            $rtNames = [];
            foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type']) ?: [] as $rt) { $rtNames[(int) $rt['id']] = (string) $rt['name']; }
            $ridSet = array_values(array_unique(array_filter(array_map(fn($p) => (int) ($p['room_id'] ?? 0), $phys))));
            if (!empty($ridSet)) { foreach ($db->select('stays_rooms', ['id', 'room_type_id'], ['id' => $ridSet]) ?: [] as $sr) { $typeByRoomId[(int) $sr['id']] = $rtNames[(int) $sr['room_type_id']] ?? ''; } }
        } catch (\Throwable $e) {}
        // Resolve the FLOOR NAME from the structured floor_id (stays_floors) — that's what
        // the room-manager form actually sets; the plain `floor` text is a legacy fallback.
        $floorNameById = [];
        try { foreach ($db->select('stays_floors', ['id', 'name'], ['stay_id' => $stayId]) ?: [] as $f) { $floorNameById[(int) $f['id']] = (string) $f['name']; } } catch (\Throwable $e) {}
        $cellsByRoom = [];
        foreach ($phys as $p) {
            $p['room_type'] = $typeByRoomId[(int) ($p['room_id'] ?? 0)] ?? '';
            // Effective floor label: structured floor name first, else legacy text.
            $fid = (int) ($p['floor_id'] ?? 0);
            $p['floor_label'] = $fid > 0 && isset($floorNameById[$fid]) ? $floorNameById[$fid] : trim((string) ($p['floor'] ?? ''));
            $cellsByRoom[(int) $p['id']] = ['room' => $p, 'cells' => array_fill_keys($dates, null)];
        }
        $end = $dates[count($dates) - 1];
        foreach ($reservations as $r) {
            if (strtolower((string) ($r['booking_status'] ?? '')) === 'cancelled') { continue; }
            if ((int) ($r['_hotel_id'] ?? 0) !== $stayId) { continue; }
            $ci = (string) ($r['_checkin'] ?? ''); $co = (string) ($r['_checkout'] ?? '');
            if ($ci === '' || $co === '' || $co <= $from || $ci > $end) { continue; }
            $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
            $prid = is_array($bd) ? (int) ($bd['pms_physical_room_id'] ?? 0) : 0;
            $label = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) $r['invoice_id'];
            if ($prid > 0 && isset($cellsByRoom[$prid])) {
                foreach ($dates as $d) { if ($ci <= $d && $d < $co) { $cellsByRoom[$prid]['cells'][$d] = ['label' => $label, 'invoice' => (string) $r['invoice_id']]; } }
            } else {
                $out['unassigned'][] = ['label' => $label, 'checkin' => $ci, 'checkout' => $co, 'invoice' => (string) $r['invoice_id']];
            }
        }
        $out['rows'] = array_values($cellsByRoom);

        // Floor-by-floor grouping for the tape chart (keeps $rows above intact for any
        // other reader). Each floor section: ['floor'=>raw, 'label'=>'Floor N'/'No floor',
        // 'rows'=>[...]], ordered numerically where the floor is a number, else
        // alphabetically, with un-floored rooms last.
        $byFloor = [];
        foreach ($out['rows'] as $row) {
            $fl = trim((string) ($row['room']['floor_label'] ?? ($row['room']['floor'] ?? '')));
            $byFloor[$fl][] = $row;
        }
        uksort($byFloor, function ($a, $b) {
            if ($a === '') { return 1; } if ($b === '') { return -1; } // blank floor last
            $na = is_numeric($a); $nb = is_numeric($b);
            if ($na && $nb) { return (float) $a <=> (float) $b; }
            if ($na !== $nb) { return $na ? -1 : 1; } // numbered floors before named
            return strcasecmp($a, $b);
        });
        $out['floors'] = [];
        foreach ($byFloor as $fl => $rws) {
            $out['floors'][] = [
                'floor' => $fl,
                'label' => $fl === '' ? 'No floor set' : (is_numeric($fl) ? ('Floor ' . $fl) : (string) $fl),
                'rows'  => $rws,
            ];
        }
        return $out;
    }
}

if (!function_exists('resv_clone')) {
    /**
     * REPEAT a past reservation: create a NEW reservation for the same guest + room/
     * option/qty on new dates, via resv_create_walkin (server-priced, real hold). Returns
     * the ops array from create. The source reservation must belong to $stayId (caller
     * authorized). Never copies payment/folio — it's a fresh unpaid booking.
     */
    function resv_clone($db, string $srcInvoiceId, string $newCheckin, string $newCheckout): array
    {
        if (!function_exists('resv_create_walkin')) { return ['ok' => false, 'message' => 'Unavailable.']; }
        $b = $db->get('bookings', ['first_name', 'last_name', 'email', 'phone', 'adults', 'childs', 'booking_data', 'customer_id'], ['invoice_id' => $srcInvoiceId]);
        if (!$b) { return ['ok' => false, 'message' => 'Source reservation not found.']; }
        $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
        if (!is_array($bd)) { return ['ok' => false, 'message' => 'Source reservation is unreadable.']; }
        $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($b['booking_data']) : 0;
        $sel = $bd['selected_rooms'][0] ?? null;
        $roomId = (int) ($sel['room_id'] ?? 0);
        $optionId = (int) ($sel['option']['option_id'] ?? 0);
        $qty = max(1, (int) ($sel['quantity'] ?? 1));
        if ($hid <= 0 || $roomId <= 0 || $optionId <= 0) {
            return ['ok' => false, 'message' => 'Source reservation has no room selection to repeat.'];
        }
        return resv_create_walkin($db, $hid, [
            'room_id' => $roomId, 'option_id' => $optionId, 'qty' => $qty,
            'checkin' => $newCheckin, 'checkout' => $newCheckout,
            'first_name' => (string) $b['first_name'], 'last_name' => (string) $b['last_name'],
            'email' => (string) $b['email'], 'phone' => (string) $b['phone'],
            'adults' => (int) $b['adults'], 'children' => (int) $b['childs'],
            'source' => 'repeat', 'mark_paid' => false,
            // C2 (owner decision #3): a repeat booking INHERITS the same payer/customer.
            'customer_id' => (int) ($b['customer_id'] ?? 0),
        ]);
    }
}

if (!function_exists('resv_unassign_physical_room')) {
    /**
     * ROOM MAPPING (PMS #05): clear a reservation's physical-room assignment. The ASSIGN
     * side is the canonical hk_assign_room_to_booking() (supplier_housekeeping.php), which
     * validates the room is active/assignable/not-in-use and stores the SAME key
     * (pms_physical_room_id) the check-in picker + tape chart use. This unassign is its
     * missing inverse so the desk can reassign before check-in. Returns bool.
     */
    function resv_unassign_physical_room($db, string $invoiceId): bool
    {
        // ATOMIC booking_data mutation (audit fix) — row-locked, fresh re-read.
        $res = booking_data_mutate($db, $invoiceId, function (array &$bd) {
            unset($bd['pms_physical_room_id']);
            return true;
        });
        return !empty($res['ok']);
    }
}
