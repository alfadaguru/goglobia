<?php
// FILE: app/lib/supplier_direct_booking.php
// DIRECT-BOOKING ENGINE (Phase 1 inc S27; docs 01 §4.1). Stage D module.
//
// Lets a property take a booking from its OWN branded site (source=direct_site),
// consuming the SAME pooled stays_inventory via stays_hold_create (S6) — so direct
// + marketplace + walk-in can never oversell the same room (the core anti-oversell
// guarantee). It produces a bookings row shaped like the marketplace path (module_type
// 'stays'), so everything downstream — the reservations inbox (S11), folio (S21),
// earnings-on-paid (S18) — works unchanged.
//
// This is a MINIMAL, SERVER-PRICED booking creator: the amount is computed from the
// room option's stored price × nights (never from the client), + tax via the existing
// engine when available. Default payment_status='unpaid' (pay-at-property) — a direct
// booking does not take card tender here; the folio/front-desk settles it. No promo,
// no currency conversion (uses the property currency) — deliberately lean.

if (!function_exists('direct_booking_quote')) {
    /**
     * Server-side price quote for a direct booking. Returns
     *   ['ok','nights','currency','room_total','tax','total','option'] or ['ok'=>false].
     * Price = option.price × nights; tax via calculateTax() if present, else 0.
     */
    function direct_booking_quote($db, int $stayId, int $roomId, int $optionId, string $checkin, string $checkout): array
    {
        $out = ['ok' => false];
        $nights = function_exists('stays_date_range') ? stays_date_range($checkin, $checkout) : [];
        $n = count($nights);
        if ($n < 1) { $out['message'] = 'Invalid dates'; return $out; }

        $stay = $db->get('stays', ['id', 'currency', 'user_id', 'status', 'listing_status'], ['id' => $stayId]);
        if (!$stay) { $out['message'] = 'Property not found'; return $out; }

        // Resolve the option (with stable ids) on the room, for THIS stay.
        $opts = function_exists('stays_room_option_ids') ? stays_room_option_ids($db, $roomId, $stayId) : [];
        $option = null;
        foreach ($opts as $o) { if ((int) ($o['option_id'] ?? 0) === $optionId) { $option = $o; break; } }
        if (!$option) { $out['message'] = 'Rate option not found'; return $out; }
        if ((int) ($option['status'] ?? 1) !== 1) { $out['message'] = 'Rate not available'; return $out; }

        $unit = round((float) ($option['price'] ?? 0), 2);
        if ($unit <= 0) { $out['message'] = 'Rate has no price'; return $out; }
        $roomTotal = round($unit * $n, 2);

        // Tax via the platform engine if available; otherwise 0 (property-inclusive).
        // calculateTax() returns ['tax_amount'=>, 'total_with_tax'=>, …] (functions.php:4410).
        $tax = 0.0;
        if (function_exists('calculateTax')) {
            try {
                $t = calculateTax($roomTotal, 'stays', $db);
                if (is_array($t)) { $tax = round((float) ($t['tax_amount'] ?? 0), 2); }
                elseif (is_numeric($t)) { $tax = round((float) $t, 2); }
            } catch (\Throwable $e) { $tax = 0.0; }
        }

        return [
            'ok' => true, 'nights' => $n, 'currency' => strtoupper((string) ($stay['currency'] ?? 'USD')),
            'room_total' => $roomTotal, 'tax' => $tax, 'total' => round($roomTotal + $tax, 2),
            'option' => $option, 'owner' => (string) ($stay['user_id'] ?? ''),
        ];
    }
}

if (!function_exists('direct_booking_create')) {
    /**
     * Create a direct booking (source=direct_site). Places an atomic pooled-inventory
     * hold FIRST (no oversell vs marketplace/walk-in); only on a successful hold does
     * it insert the bookings row. Returns ['ok','invoice_id','total','message'].
     *
     * $guest = ['first_name','last_name','email','phone'] (minimal).
     * payment_status defaults 'unpaid' (pay at property); booking_status 'pending'.
     */
    function direct_booking_create($db, int $stayId, int $roomId, int $optionId, string $checkin, string $checkout, array $guest, int $qty = 1): array
    {
        if ($qty < 1) { $qty = 1; }
        $q = direct_booking_quote($db, $stayId, $roomId, $optionId, $checkin, $checkout);
        if (empty($q['ok'])) { return ['ok' => false, 'message' => $q['message'] ?? 'Could not price the stay']; }

        $first = trim((string) ($guest['first_name'] ?? ''));
        $last  = trim((string) ($guest['last_name'] ?? ''));
        $email = trim((string) ($guest['email'] ?? ''));
        if ($first === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'A guest name and valid email are required'];
        }

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)); // like the marketplace path
        $currency = $q['currency'];
        $total = (float) $q['total'];
        $roomTotal = (float) $q['room_total'];
        $tax = (float) $q['tax'];

        // 1) HOLD first — the anti-oversell gate shared with every channel.
        if (!function_exists('stays_hold_create')) { return ['ok' => false, 'message' => 'Inventory engine unavailable']; }
        $hold = stays_hold_create($db, $stayId, $roomId, $optionId, $checkin, $checkout, $qty, $invoiceId);
        if (empty($hold['ok'])) {
            return ['ok' => false, 'message' => $hold['message'] ?? 'Those dates are no longer available.'];
        }

        // 2) Build the booking_data + row (shaped like the marketplace path).
        $bookingData = [
            'hotel_id'   => $stayId,
            'checkin'    => $checkin,
            'checkout'   => $checkout,
            'supplier'   => 'stays',
            'source'     => 'direct_site',
            'rooms_data' => [[ 'room_id' => $roomId, 'option_id' => $optionId, 'qty' => $qty ]],
            'nights'     => (int) $q['nights'],
        ];

        try {
            $db->insert('bookings', [
                'invoice_id'           => $invoiceId,
                'booking_date'         => date('Y-m-d H:i:s'),
                'booking_status'       => 'pending',
                'payment_status'       => 'unpaid',          // pay-at-property; folio settles
                'price_original'       => $roomTotal,        // operator net = room (no platform markup on direct)
                'price_markup'         => $total,            // guest total (room + tax)
                'agent_earning'        => 0,
                'tax'                  => $tax,
                'first_name'           => substr($first, 0, 50),
                'last_name'            => substr($last, 0, 50),
                'email'                => substr($email, 0, 50),
                'address'              => '',
                'phone_country_code'   => '',
                'phone'                => substr(trim((string) ($guest['phone'] ?? '')), 0, 15),
                'country'              => '',
                'adults'               => max(1, (int) ($guest['adults'] ?? 1)),
                'infants'              => 0,
                'childs'               => max(0, (int) ($guest['childs'] ?? 0)),
                'child_ages'           => json_encode([]),
                'currency_markup'      => $currency,
                'cancellation_request' => 0,
                'cancellation_status'  => 0,
                'booking_data'         => json_encode($bookingData),
                'transaction_id'       => null,
                'user_id'              => '',                // guest/walk-in — no account
                'travellers'           => json_encode(['primary_guest' => ['first_name' => $first, 'last_name' => $last, 'email' => $email]]),
                'nationality'          => '',
                'payment_gateway'      => '',
                'module_type'          => 'stays',
                'commission'           => 0,                 // direct sale — no platform commission
                'module'               => 'stays',
                'special_requests'     => trim((string) ($guest['special_requests'] ?? '')) ?: null,
                'created_at'           => date('Y-m-d H:i:s'),
            ]);
            $bookingId = (int) $db->id();
            if ($bookingId <= 0) {
                // Insert failed → release the hold we just placed so the room frees up.
                if (function_exists('stays_hold_release')) { stays_hold_release($db, $invoiceId); }
                return ['ok' => false, 'message' => 'Could not create the booking'];
            }
        } catch (\Throwable $e) {
            error_log('direct_booking_create: ' . $e->getMessage());
            if (function_exists('stays_hold_release')) { stays_hold_release($db, $invoiceId); }
            return ['ok' => false, 'message' => 'Could not create the booking'];
        }

        if (function_exists('emit_event')) {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            emit_event($db, 'reservation.created',
                ['invoice_id' => $invoiceId, 'source' => 'direct_site', 'total' => $total, 'currency' => $currency],
                'stays', $stayId, $orgId > 0 ? $orgId : null);
        }
        return ['ok' => true, 'invoice_id' => $invoiceId, 'total' => $total, 'currency' => $currency, 'message' => 'Booking confirmed.'];
    }
}
