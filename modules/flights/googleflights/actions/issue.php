<?php
// ============================================================================
// GOOGLE FLIGHTS — Issue/Book
// Google Flights is a price-comparison API; there is no booking API.
// When a customer selects a flight the system records a "confirmed" draft
// booking and provides the booking_token redirect URL so the customer can
// complete purchase directly on the airline/OTA website.
// ============================================================================

$router->post('flights/googleflights/issue', function () use ($db) {

    if (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $invoice_id = '';

    try {
        if (!$db) throw new Exception('Database connection not available');

        $invoice_id = trim($_POST['invoice_id'] ?? '');
        if (empty($invoice_id)) throw new Exception('Missing invoice_id');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) throw new Exception('Booking not found: ' . $invoice_id);

        if (in_array($booking['booking_status'], ['confirmed', 'ticketed'])) {
            ob_clean();
            echo json_encode([
                'status'         => true,
                'message'        => 'Booking already confirmed',
                'invoice_id'     => $invoice_id,
                'booking_status' => $booking['booking_status'],
            ]);
            exit;
        }

        // Decode stored booking data to extract booking_token redirect URL
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
        $flightData  = $bookingData['flight_data'] ?? [];
        $bookToken   = $flightData['booking_data']['booking_token'] ?? '';

        // Mark confirmed locally — customer completes purchase on Google/airline
        $db->update('bookings', [
            'booking_status' => 'confirmed',
            'pnr'            => strtoupper(substr(md5($invoice_id . time()), 0, 8)),
        ], ['invoice_id' => $invoice_id]);

        ob_clean();
        echo json_encode([
            'status'        => true,
            'message'       => 'Booking confirmed. Please complete payment via the redirect URL.',
            'invoice_id'    => $invoice_id,
            'redirect_url'  => $bookToken ?: null,
            'note'          => 'Google Flights does not provide a direct booking API. The customer must complete the purchase on the airline website.',
        ]);

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'status'     => false,
            'message'    => $e->getMessage(),
            'invoice_id' => $invoice_id,
        ]);
    }
    exit;
});
