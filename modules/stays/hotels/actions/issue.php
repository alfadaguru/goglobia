<?php
// path : modules/stays/hotels/actions/issue.php
// ISSUE BOOKING ACTION
// This action issues/confirms a booking with supplier
@$SECURE or die('Access Denied!');

$router->post('stays/hotels/issue', function() use ($db) {
    header('Content-Type: application/json');

    // Get required data
    $invoice_id = $_POST['invoice_id'] ?? '';
    $module_type = $_POST['module_type'] ?? 'hotels';

    if (empty($invoice_id)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
        exit;
    }

    // Fetch booking
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found']);
        exit;
    }

    // PRICE-TRUST (audit H4): this local "hotels" supplier is a mock with no external
    // rate to reconcile against, so its trusted price is the catalog price in
    // stays_rooms. Without a check, a tampered/stale draft could confirm a real
    // catalog stay for an arbitrary paid amount (e.g. $1). Guard with a server-side
    // floor: the amount paid must be at least the cheapest catalog room base rate for
    // the booked hotel (a single night is the absolute minimum any real stay costs).
    // This blocks gross underpayment without a fragile full nights*rooms recompute.
    $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
    $hotelId = (int) ($bookingData['hotel_id'] ?? $bookingData['id'] ?? 0);
    $paidAmount = (float) ($booking['price_markup'] ?? 0);
    if ($hotelId > 0 && $paidAmount > 0) {
        $minBase = null;
        try {
            $rooms = $db->select('stays_rooms', ['room_options'], ['stay_id' => $hotelId, 'status' => 1]) ?: [];
            foreach ($rooms as $r) {
                $opts = json_decode($r['room_options'] ?? '[]', true);
                if (!is_array($opts)) { continue; }
                // room_options may be a single option object or a list of them.
                $optList = isset($opts['price']) ? [$opts] : $opts;
                foreach ($optList as $o) {
                    $p = (float) ($o['price'] ?? 0);
                    if ($p > 0 && ($minBase === null || $p < $minBase)) { $minBase = $p; }
                }
            }
        } catch (\Throwable $e) {
            error_log('hotels(mock) price-floor lookup failed for invoice ' . $invoice_id . ': ' . $e->getMessage());
        }
        // Only enforce when we actually resolved a catalog base rate. A 5% grace
        // absorbs currency-conversion rounding between the display price and the
        // stored base rate; anything below that is a genuine underpayment.
        if ($minBase !== null && $minBase > 0 && $paidAmount < ($minBase * 0.95)) {
            error_log('HOTELS(mock) PRICE-TRUST BLOCK | invoice=' . $invoice_id
                . ' | paid=' . $paidAmount . ' < min_catalog_base=' . $minBase);
            $db->update('bookings', [
                'booking_status' => 'pending',
                'error_response' => json_encode([
                    'review_state' => 'price_mismatch',
                    'reason'       => 'Paid amount is below the catalog minimum room rate.',
                    'paid'         => $paidAmount,
                    'min_base'     => $minBase,
                    'flagged_at'   => date('Y-m-d H:i:s'),
                ]),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode([
                'status'  => false,
                'message' => 'Booking held for review: the amount paid is below the room rate. Please search again.',
            ]);
            exit;
        }
    }

    // GENERATE PNR (Passenger Name Record)
    $pnr = 'PNR' . strtoupper(substr(md5($invoice_id . time()), 0, 6));

    // UPDATE DATABASE
    $updated = $db->update('bookings', [
        'pnr' => $pnr,
        'booking_status' => 'confirmed'
    ], ['invoice_id' => $invoice_id]);

    if ($updated) {
        echo json_encode([
            'status' => true,
            'Prn' => $pnr,                      // payment-gateway.php expects 'Prn'
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'Booking issued successfully',
            'pnr' => $pnr,                      // keep for backward compatibility
            'response_error' => ''
        ]);
    } else {
        echo json_encode([
            'status' => false,
            'Prn' => '',
            'message' => 'Failed to update booking',
            'response_error' => 'Failed to update booking'
        ]);
    }
});