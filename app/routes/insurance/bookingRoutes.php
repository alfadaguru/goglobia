<?php

// app/routes/insurance/bookingRoutes.php
// Create a FREE flight-compensation-claim "booking" and register it with AirHelp.
// No payment gateway: the customer is not charged (AirHelp pays the platform a
// commission on won claims). The booking is a zero-price record whose lifecycle
// is: submit -> register with AirHelp -> track status.
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/insurance/airhelp/index.php';

$router->post('/insurance/claim/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // CSRF (JSON): accepts token in body or X-CSRF-TOKEN header.
        if (class_exists('CSRF') && !CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new Exception('Invalid or expired session token. Please refresh and try again.');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            // fall back to form post
            $input = $_POST;
        }

        // Insurance module must be active.
        $module = $db->get('modules', '*', ['name' => 'airhelp', 'type' => 'insurance', 'status' => 1]);
        if (!$module) {
            throw new Exception('Flight compensation service is not available right now.');
        }

        // --- Required fields ---
        $flight = [
            'airline_code'        => strtoupper(trim((string) ($input['airline_code'] ?? ''))),
            'flight_number'       => trim((string) ($input['flight_number'] ?? '')),
            'departure_airport'   => strtoupper(trim((string) ($input['departure_airport'] ?? ''))),
            'arrival_airport'     => strtoupper(trim((string) ($input['arrival_airport'] ?? ''))),
            'departure_datetime'  => trim((string) ($input['departure_datetime'] ?? '')),
            'arrival_datetime'    => trim((string) ($input['arrival_datetime'] ?? '')),
            'pnr'                 => trim((string) ($input['pnr'] ?? '')),
            'booking_reference'   => trim((string) ($input['booking_reference'] ?? ($input['pnr'] ?? ''))),
        ];
        $passenger = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name'  => trim((string) ($input['last_name'] ?? '')),
            'email'      => trim((string) ($input['email'] ?? '')),
            'phone'      => trim((string) ($input['phone'] ?? '')),
            'country'    => strtoupper(trim((string) ($input['country'] ?? ''))),
            'language'   => $_SESSION['app_language'] ?? 'en',
        ];

        $missing = [];
        foreach (['airline_code', 'flight_number', 'departure_airport', 'arrival_airport', 'departure_datetime'] as $k) {
            if ($flight[$k] === '') { $missing[] = $k; }
        }
        foreach (['first_name', 'last_name', 'email'] as $k) {
            if ($passenger[$k] === '') { $missing[] = $k; }
        }
        if ($missing) {
            throw new Exception('Please complete: ' . implode(', ', $missing));
        }
        if (!filter_var($passenger['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        // --- Create the zero-price booking record ---
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $bookingData = [
            'supplier'    => 'airhelp',
            'flight'      => $flight,
            'passenger'   => $passenger,
            'claim_type'  => 'flight_compensation',
        ];

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'booking_date'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'price_original'  => 0,
            'price_markup'    => 0,
            'currency_markup' => strtoupper((string) ($module['currency'] ?? 'USD')),
            'first_name'      => $passenger['first_name'],
            'last_name'       => $passenger['last_name'],
            'email'           => $passenger['email'],
            'phone'           => $passenger['phone'],
            'country'         => $passenger['country'],
            'adults'          => 1,
            'infants'         => 0,
            'childs'          => 0,
            'booking_data'    => json_encode($bookingData),
            // payment_status ENUM = paid|unpaid|refunded. A compensation claim is
            // free (no charge), so 'unpaid' with price 0 represents "nothing due".
            'payment_status'  => 'unpaid',
            'user_id'         => $_SESSION['user_id'] ?? null,
            'module_type'     => 'insurance',
            'module'          => 'airhelp',
            'pnr'             => null,
            'booking_response'=> null,
            'error_response'  => null,
        ]);

        // --- Register the claim with AirHelp (safe no-op if unconfigured) ---
        $result = airhelp_register_claim($db, $invoiceId);

        echo json_encode([
            'status'      => true,
            'invoice_id'  => $invoiceId,
            'claim'       => $result,
            'redirect'    => root . 'insurance/claim/' . $invoiceId,
            'message'     => $result['pending']
                ? 'Your claim has been submitted and is being reviewed.'
                : 'Your flight compensation claim has been registered.',
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
});
