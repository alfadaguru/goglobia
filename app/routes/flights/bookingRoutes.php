<?php
// ============================================================================
// FILE: app/routes/flights/booking.php
// FLIGHTS BOOKING ROUTES - All booking-related GET and POST endpoints
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('flightExtractOfferToken')) {
    function flightExtractOfferToken(array $flightData): string
    {
        $bd = $flightData['booking_data'] ?? [];
        $token = trim(
            (string)($bd['FareSourceCode'] ?? $bd['fare_source_code'] ?? $bd['booking_token'] ?? $bd['offer_id'] ?? $bd['offering_id'] ?? $bd['search_id'] ?? '')
        );

        if ($token === '' && !empty($bd['key']) && is_array($bd['key']) && !empty($bd['key']['id'])) {
            $token = (string)$bd['key']['id'];
        }

        if ($token === '' && !empty($bd['flight']) && is_array($bd['flight'])) {
            $token = trim((string)($bd['flight']['id'] ?? $bd['flight']['booking_token'] ?? $bd['flight']['offer_id'] ?? $bd['flight']['offering_id'] ?? ''));
        }

        if ($token !== '' || empty($flightData['segments'])) {
            return $token;
        }

        $firstSegment = $flightData['segments'][0] ?? null;
        if (is_array($firstSegment) && isset($firstSegment[0]) && is_array($firstSegment[0])) {
            $firstSegment = $firstSegment[0];
        }
        if (!is_array($firstSegment)) {
            return '';
        }

        $segBd = $firstSegment['booking_data'] ?? [];
        return trim(
            (string)($segBd['offering_id'] ?? $segBd['booking_token'] ?? $segBd['offer_id'] ?? $segBd['search_id'] ?? $segBd['FareSourceCode'] ?? '')
        );
    }
}

if (!function_exists('flightResolveBookingData')) {
    /**
     * Prefer top-level flight_data.booking_data; fall back to first segment booking_data.
     */
    function flightResolveBookingData(array $flightData): array
    {
        $bd = $flightData['booking_data'] ?? [];
        if (is_array($bd) && (
            !empty($bd['search_id'])
            || !empty($bd['selections'])
            || !empty($bd['offering_id'])
            || !empty($bd['product_id'])
            || !empty($bd['FareSourceCode'])
            || !empty($bd['booking_token'])
            || !empty($bd['key'])
        )) {
            return $bd;
        }

        $firstSegment = $flightData['segments'][0] ?? null;
        if (is_array($firstSegment) && isset($firstSegment[0]) && is_array($firstSegment[0])) {
            $firstSegment = $firstSegment[0];
        }
        if (is_object($firstSegment)) {
            $firstSegment = (array) $firstSegment;
        }
        $segBd = is_array($firstSegment) ? ($firstSegment['booking_data'] ?? []) : [];
        return is_array($segBd) ? $segBd : [];
    }
}

if (!function_exists('flightCallSupplierRevalidate')) {
    function flightCallSupplierRevalidate($db, array $flightData, string $supplier, float $oldPrice, string $currency, array $searchParams = []): array
    {
        $supplier   = strtolower(trim($supplier));
        $moduleType = 'flights';
        $token      = flightExtractOfferToken($flightData);
        $bd         = flightResolveBookingData($flightData);

        if ($supplier === 'amadeus') {
            $flightOffer = null;
            if (!empty($bd['key']) && is_array($bd['key'])) {
                $flightOffer = $bd['key'];
            } elseif (!empty($bd['key']) && is_string($bd['key'])) {
                $flightOffer = json_decode($bd['key'], true);
            }
            if (!$flightOffer && !empty($flightData['segments'])) {
                $firstSegment = $flightData['segments'][0] ?? null;
                if (is_array($firstSegment) && isset($firstSegment[0]) && is_array($firstSegment[0])) {
                    $firstSegment = $firstSegment[0];
                }
                $segBd = is_array($firstSegment) ? ($firstSegment['booking_data'] ?? []) : [];
                if (!empty($segBd['key']) && is_array($segBd['key'])) {
                    $flightOffer = $segBd['key'];
                }
            }
            if (!$flightOffer) {
                return ['status' => false, 'message' => 'Flight offer not found in booking. Please search again.'];
            }
        } elseif ($supplier === 'travelport') {
            if (empty($bd['search_id']) && empty($bd['selections']) && empty($bd['offering_id'])) {
                return ['status' => false, 'message' => 'Travelport offer data not found in booking. Please search again.'];
            }
        } elseif ($token === '') {
            return ['status' => false, 'message' => 'Offer ID not found in booking. Please search again.'];
        }

        if ($oldPrice <= 0) {
            $oldPrice = (float)($flightData['actual_price'] ?? $flightData['price'] ?? 0);
        }
        if ($currency === '') {
            $currency = strtoupper($flightData['currency'] ?? 'USD');
        }

        $adults = max(1, (int) ($searchParams['adults'] ?? $searchParams['adult'] ?? $flightData['adults'] ?? 1));
        $children = (int) ($searchParams['childrens'] ?? $searchParams['children'] ?? $searchParams['child'] ?? $flightData['childrens'] ?? $flightData['children'] ?? 0);
        $infants = (int) ($searchParams['infants'] ?? $searchParams['infant'] ?? $flightData['infants'] ?? 0);

        $revalidateUrl = rtrim(root, '/') . '/modules/' . $moduleType . '/' . $supplier . '/revalidate';
        $postFields = [
            'FareSourceCode'   => $token,
            'fare_source_code' => $token,
            'booking_token'    => $token,
            'offer_id'         => $token,
            'old_price'        => $oldPrice,
            'currency'         => $currency,
            'adults'           => $adults,
            'childrens'        => $children,
            'children'         => $children,
            'infants'          => $infants,
        ];
        if ($supplier === 'amadeus' && !empty($flightOffer)) {
            $postFields['flight_offer'] = json_encode($flightOffer);
        }
        if ($supplier === 'seeru' && !empty($bd['flight'])) {
            $postFields['flight'] = json_encode($bd['flight']);
        }
        // TBO FareQuote needs the stored ResultId + TokenId + TrackingId session
        if ($supplier === 'tbo' && !empty($bd)) {
            $postFields['booking_data'] = json_encode($bd);
        }
        // Travelport Air Price needs search_id + selections/product ids (not just offering token)
        if ($supplier === 'travelport' && !empty($bd)) {
            $postFields['booking_data'] = json_encode($bd);
            if (!empty($bd['search_id'])) {
                $postFields['search_id'] = $bd['search_id'];
            }
            if (!empty($bd['offering_id'])) {
                $postFields['offering_id'] = $bd['offering_id'];
            }
        }
        // Use JSON payload for Seeru, otherwise form‑encoded
        if ($supplier === 'seeru') {
            $postBody = json_encode($postFields);
            $headers = ['Content-Type: application/json'];
        } else {
            $postBody = http_build_query($postFields);
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
        }

        $ch = curl_init($revalidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => ($supplier === 'travelport') ? 90 : 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 404 || $httpCode === 0 || $curlErr) {
            return [
                'status'  => false,
                'message' => 'Unable to verify fare with supplier. Please try again.',
            ];
        }

        $result = json_decode($raw, true);
        if (!is_array($result)) {
            return ['status' => false, 'message' => 'Invalid revalidation response from supplier.'];
        }

        return $result;
    }
}

// ============================================================================
// GET: Booking page with hash
// GET /flights/booking/{hash}
// ============================================================================
$router->get('/flights/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    // Get booking data from database
    $booking = $db->get('logs_bookings', ['hash', 'data', 'created_at'], ['hash' => $hash]);

    if (!$booking || empty($booking['data'])) {
        header('Location: ' . root . 'flights');
        exit;
    }

    // Decode booking data
    $bookingData = json_decode($booking['data'], true);

    if (!$bookingData) {
        header('Location: ' . root . 'flights');
        exit;
    }

    // Store in session for booking page
    $_SESSION['booking_data'] = $bookingData;
    $_SESSION['booking_hash'] = $hash;
    $_SESSION['booking_created_at'] = $booking['created_at'];

    // Render booking page
    $title = 'Complete Booking - ' . $GLOBALS['app']['home_title'];
    $description = "Complete your flight booking";
    require_once views."includes/header.php";
    require_once views."modules/flights/booking/index.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// POST: AI document extraction (passport scan on flight booking)
// POST /flights/passport/extract
// ============================================================================
$router->post('/flights/passport/extract', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!CSRF::validateToken($csrfToken)) {
            http_response_code(403);
            echo json_encode([
                'status' => false,
                'message' => 'Security token expired. Please refresh the page and try again.',
                'error_code' => 'AI_CSRF_FAILED',
            ]);
            return;
        }

        $bookingHash = trim((string) ($_POST['booking_hash'] ?? ''));
        $passengerKey = trim((string) ($_POST['passenger_key'] ?? ''));

        // Accept either flight booking session OR AI trip package session.
        // A leftover $_SESSION['booking_hash'] from an earlier flight checkout must not
        // block passport scan on /ai-trip/booking/{hash}.
        $flightSessionHash = (string) ($_SESSION['booking_hash'] ?? '');
        $aiTripSessionHash = (string) ($_SESSION['ai_trip_booking_hash'] ?? '');
        $validSessionHashes = [];
        if ($flightSessionHash !== '') {
            $validSessionHashes[] = $flightSessionHash;
        }
        if ($aiTripSessionHash !== '' && !in_array($aiTripSessionHash, $validSessionHashes, true)) {
            $validSessionHashes[] = $aiTripSessionHash;
        }

        if ($bookingHash !== '' && !empty($validSessionHashes)) {
            $hashAllowed = false;
            foreach ($validSessionHashes as $sessionHash) {
                if (hash_equals($sessionHash, $bookingHash)) {
                    $hashAllowed = true;
                    break;
                }
            }
            if (!$hashAllowed) {
                http_response_code(403);
                echo json_encode([
                    'status' => false,
                    'message' => 'Invalid booking session.',
                    'error_code' => 'AI_INVALID_BOOKING',
                ]);
                return;
            }
        }
        if ($bookingHash === '') {
            $bookingHash = $flightSessionHash !== '' ? $flightSessionHash : $aiTripSessionHash;
        }

        if (!isset($_FILES['passport_image'])) {
            echo json_encode([
                'status' => false,
                'message' => 'Please upload or capture a passport image.',
                'error_code' => 'AI_UPLOAD_FAILED',
            ]);
            return;
        }

        $service = new \App\lib\ai\aiExtractionService($db);
        $result = $service->extractFromUpload($_FILES['passport_image'], $bookingHash, $passengerKey);

        if (empty($result['status'])) {
            http_response_code(400);
        }

        // Strip null error_code on success
        if (!empty($result['status'])) {
            unset($result['error_code']);
        }

        echo json_encode($result);
    } catch (Throwable $e) {
        error_log('[ai] extract endpoint error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Passport scanning failed. Please enter details manually.',
            'error_code' => 'AI_INTERNAL_ERROR',
        ]);
    }
});

// ============================================================================
// POST: Save booking draft
// POST /api/flight/booking/save-draft
// ============================================================================
$router->post('/api/flight/booking/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['flight_data'])) {
            throw new Exception('This flight is unavailable at the moment, please book another.');
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if ($result) {
            // Trigger draft created webhook
            triggerWebhook('flights/booking', 'flights.booking.draft_created', [
                'booking_hash' => $hash,
                'flight_data' => $input['flight_data'] ?? [],
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_data']['id'] ?? null,
                'session_id' => session_id()
            ]);
            
            echo json_encode([
                'success' => true,
                'hash' => $hash,
                'message' => 'Booking draft saved successfully'
            ]);
        } else {
            throw new Exception('Failed to save booking data');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});


// ============================================================================
// POST: Resend invoice email
// ============================================================================
// GET: Download invoice PDF
// GET /api/flight/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/flight/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    try {
        // Check if booking exists
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        // Always generate/refresh PDF before download
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            // Serve PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="flight_invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            exit;
        }

        throw new Exception('Failed to generate or find invoice PDF');

    } catch (Exception $e) {
        http_response_code(500);
        die('Error downloading invoice');
    }
});
// POST /api/flight/booking/resend-invoice
// ============================================================================
$router->post('/api/flight/booking/resend-invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = $input['invoice_id'] ?? '';

        if (empty($invoiceId)) {
            throw new Exception('Invoice ID is required');
        }

        // Get booking details
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // Generate or get existing PDF
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        // Send consolidated notification (Customer only)
        NOTIFY::resend('flights', [
            'email' => $booking['email'],
            'phone' => $booking['phone'],
            'first_name' => $booking['first_name'],
            'last_name' => $booking['last_name'],
            'country_code' => $booking['phone_country_code']
        ], [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status']
        ], $pdfPath);

        echo json_encode([
            'success' => true,
            'message' => 'Invoice has been resent successfully to ' . $booking['email']
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ============================================================================
// POST: Request cancellation
// POST /api/flight/booking/request-cancellation
// ============================================================================
$router->post('/api/flight/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = $input['invoice_id'] ?? '';

        if (empty($invoiceId)) {
            throw new Exception('Invoice ID is required');
        }

        // Get booking details
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // Check if already cancelled or cancellation requested
        if ($booking['booking_status'] === 'cancelled') {
            throw new Exception('This booking is already cancelled');
        }

        if ($booking['cancellation_request'] == 1) {
            throw new Exception('Cancellation request already submitted');
        }

        // Update cancellation request
        $db->update('bookings', [
            'cancellation_request' => 1
        ], [
            'invoice_id' => $invoiceId
        ]);

        // Send notification using NOTIFY library
        NOTIFY::cancellation('flights', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'] ?? 0,
            'currency' => $booking['currency'] ?? 'USD',
            'module_type' => 'Flight'
        ]);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Cancellation request submitted successfully'
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ============================================================================
// POST: Pre-payment revalidation (global — works for any supplier module)
// POST /api/flight/booking/revalidate
//
// Reads the booking draft, determines the supplier, and calls that supplier's
// revalidate endpoint (modules/{type}/{supplier}/revalidate) if it exists.
// If the supplier has no revalidate endpoint → passes through with status:true.
// Standardised response: { status, skipped, data: { is_valid, price_changed,
//                          new_price, actual_price, currency, fare_source_code } }
// ============================================================================
$router->post('/api/flight/booking/revalidate', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $bookingHash = trim($_POST['booking_hash'] ?? '');
        $oldPrice    = (float)($_POST['old_price'] ?? 0);
        $currency    = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $purpose     = strtolower(trim($_POST['purpose'] ?? ''));

        if (empty($bookingHash)) {
            echo json_encode(['status' => false, 'message' => 'booking_hash is required']);
            exit;
        }

        $draft = $db->get('logs_bookings', ['id', 'data'], ['hash' => $bookingHash]);
        if (!$draft || empty($draft['data'])) {
            echo json_encode(['status' => false, 'message' => 'Booking draft not found. Please search again.']);
            exit;
        }

        $draftData  = json_decode($draft['data'], true) ?? [];
        $flightData = $draftData['flight_data'] ?? [];
        $supplier   = strtolower(trim($flightData['supplier'] ?? ''));
        $bookingData = $flightData['booking_data'] ?? [];

        $revalidateFile     = __DIR__ . '/../../../modules/flights/' . $supplier . '/revalidate.php';
        $requiresRevalidate = !empty($supplier) && file_exists($revalidateFile);

        if (empty($supplier) || in_array($supplier, ['flight', 'flights', ''], true)) {
            echo json_encode(['status' => true, 'skipped' => true, 'message' => 'No supplier module — skipping revalidation']);
            exit;
        }

        if (!$requiresRevalidate) {
            echo json_encode(['status' => true, 'skipped' => true, 'message' => "Supplier '{$supplier}' has no revalidate endpoint — skipping"]);
            exit;
        }

        if ($supplier === 'mystifly') {
            $alreadyRevalidated = !empty($flightData['revalidated'])
                || !empty($bookingData['revalidated_at']);
            if ($alreadyRevalidated) {
                $currentFsc = flightExtractOfferToken($flightData);
                echo json_encode([
                    'status'  => true,
                    'skipped' => false,
                    'message' => 'Fare already revalidated.',
                    'data'    => [
                        'is_valid'          => true,
                        'supplier_is_valid' => true,
                        'fare_source_code'  => $currentFsc,
                        'FareSourceCode'    => $currentFsc,
                        'price_changed'     => false,
                        'old_price'         => $oldPrice,
                        'new_price'         => $flightData['price'] ?? $oldPrice,
                        'actual_price'      => $flightData['actual_price'] ?? $oldPrice,
                        'currency'          => $currency,
                        'extra_services'    => $bookingData['extra_services'] ?? [],
                    ],
                ]);
                exit;
            }
        }

        if ($purpose === 'ancillaries' && $supplier === 'mystifly') {
            $lastAncillaryRevalidate = $bookingData['ancillary_revalidated_at'] ?? null;
            $lastTimestamp = $lastAncillaryRevalidate ? strtotime($lastAncillaryRevalidate) : false;
            if ($lastTimestamp && (time() - $lastTimestamp) < 600) {
                $currentFsc = flightExtractOfferToken($flightData);
                echo json_encode([
                    'status'  => true,
                    'skipped' => false,
                    'message' => 'Fare already revalidated for ancillaries.',
                    'data'    => [
                        'is_valid'          => true,
                        'supplier_is_valid' => true,
                        'fare_source_code'  => $currentFsc,
                        'FareSourceCode'    => $currentFsc,
                        'price_changed'     => false,
                        'old_price'         => $oldPrice,
                        'new_price'         => $flightData['price'] ?? $oldPrice,
                        'actual_price'      => $flightData['actual_price'] ?? $oldPrice,
                        'currency'          => $currency,
                        'extra_services'    => $bookingData['extra_services'] ?? [],
                    ],
                ]);
                exit;
            }
        }

        $result = flightCallSupplierRevalidate(
            $db,
            $flightData,
            $supplier,
            $oldPrice,
            $currency,
            is_array($draftData['search_params'] ?? null) ? $draftData['search_params'] : []
        );

        if (empty($result['status'])) {
            echo json_encode([
                'status'  => false,
                'message' => $result['message'] ?? 'Selected fare is no longer available. Please search again.',
                'data'    => $result['data'] ?? null,
            ]);
            exit;
        }

        $fareSourceCode = flightExtractOfferToken($flightData);
        $newFsc         = $result['data']['fare_source_code'] ?? $result['data']['FareSourceCode'] ?? null;
        if (!isset($draftData['flight_data']['booking_data']) || !is_array($draftData['flight_data']['booking_data'])) {
            $draftData['flight_data']['booking_data'] = [];
        }
        if ($newFsc && $newFsc !== $fareSourceCode) {
            $draftData['flight_data']['booking_data']['FareSourceCode']   = $newFsc;
            $draftData['flight_data']['booking_data']['fare_source_code'] = $newFsc;
            $draftData['flight_data']['booking_data']['booking_token']    = $newFsc;
            $draftData['flight_data']['booking_data']['offer_id']         = $newFsc;
        }
        if (!empty($result['data']['flight_offer']) && is_array($result['data']['flight_offer'])) {
            $repricedOffer = $result['data']['flight_offer'];
            if ($supplier === 'seeru') {
                $draftData['flight_data']['booking_data']['flight'] = $repricedOffer;
            } else {
                $draftData['flight_data']['booking_data']['key'] = $repricedOffer;
            }
            $draftData['flight_data']['booking_data']['amount'] = $result['data']['new_price']
                ?? $draftData['flight_data']['booking_data']['amount'];
            $draftData['flight_data']['booking_data']['actual_amount'] = $result['data']['actual_price']
                ?? $draftData['flight_data']['booking_data']['actual_amount'];
        }

        if (!empty($result['data']['price_changed'])) {
            if (!empty($result['data']['actual_price'])) {
                $draftData['flight_data']['actual_price'] = $result['data']['actual_price'];
            }
            if (!empty($result['data']['new_price'])) {
                $draftData['flight_data']['price'] = $result['data']['new_price'];
            }

            $actualTotal = (float)($draftData['flight_data']['actual_price'] ?? 0);
            $markupTotal = (float)($draftData['flight_data']['price'] ?? 0);
            if (!isset($draftData['pricing_breakdown']) || !is_array($draftData['pricing_breakdown'])) {
                $draftData['pricing_breakdown'] = [];
            }
            if ($actualTotal > 0) {
                $draftData['pricing_breakdown']['actual_total'] = $actualTotal;
            }
            if ($markupTotal > 0) {
                $draftData['pricing_breakdown']['markup_total'] = $markupTotal;
                $draftData['pricing_breakdown']['markup_amount'] = round($markupTotal - $actualTotal, 2);
            }
        }

        if (!isset($draftData['flight_data']['booking_data']) || !is_array($draftData['flight_data']['booking_data'])) {
            $draftData['flight_data']['booking_data'] = [];
        }
        $draftData['flight_data']['booking_data']['revalidated_at'] = date('c');
        $draftData['flight_data']['revalidated'] = true;
        if ($purpose === 'ancillaries' && $supplier === 'mystifly') {
            $draftData['flight_data']['booking_data']['ancillary_revalidated_at'] = date('c');
        }
        if ($supplier === 'mystifly' && isset($result['data']['extra_services'])) {
            $draftData['flight_data']['booking_data']['extra_services'] = $result['data']['extra_services'];
        }

        // Save hold_allowed from revalidate response so issue.php can read it on ERREV011
        if (isset($result['data']['hold_allowed'])) {
            $holdRaw = $result['data']['hold_allowed'];
            $holdBool = is_string($holdRaw) ? (strtolower($holdRaw) === 'true') : (bool)$holdRaw;
            $draftData['flight_data']['booking_data']['hold_allowed'] = $holdBool;
            $draftData['flight_data']['hold_allowed']                 = $holdBool;
        }

        $db->update('logs_bookings', ['data' => json_encode($draftData)], ['hash' => $bookingHash]);

        echo json_encode([
            'status'  => true,
            'skipped' => false,
            'message' => $result['message'] ?? 'Fare revalidated successfully.',
            'data'    => $result['data'] ?? null,
        ]);

    } catch (Throwable $e) {
        error_log('FLIGHT REVALIDATE ROUTE ERROR: ' . $e->getMessage());
        $failMessage = trim($e->getMessage());
        echo json_encode([
            'status'  => false,
            'message' => $failMessage !== '' ? $failMessage : 'Revalidation failed. Please try again.',
        ]);
    }
    exit;
});

// ============================================================================
// POST: Submit final booking (complex - includes pricing, passengers, email, PDF)
// POST /api/flight/booking/submit
// ============================================================================
$router->post('/api/flight/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking data');
        }

        // Get draft booking
        $draft = $db->get('logs_bookings', ['data'], ['hash' => $input['booking_hash']]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Booking draft not found');
        }

        $draftData = json_decode($draft['data'], true);
        $flightData = $draftData['flight_data'] ?? [];
        
        // Extract supplier name from flight data
        $supplierName = $flightData['supplier'] ?? 'flights';

        // Extract form data (guest_details contains the entire formData object)
        $formData = $input['guest_details'] ?? [];
        $primaryGuest = $formData['primary_guest'] ?? [];
        $passengers = $formData['passengers'] ?? [];

        if (empty($primaryGuest['first_name']) || empty($primaryGuest['last_name']) || empty($primaryGuest['email'])) {
            throw new Exception('Please fill in all required guest details.');
        }

        $countryIso = resolveCountryIso($primaryGuest['country_code'] ?? '', $db) ?: 'US';
        $guestPhone = trim((string)($primaryGuest['phone'] ?? ''));
        if ($guestPhone === '') {
            throw new Exception('Phone number is required.');
        }

        if (!validatePhoneForCountry($guestPhone, $countryIso, $db)) {
            throw new Exception('Please enter a valid phone number matching the selected country code (e.g. 4155552671 for US, without mixing country codes).');
        }

        $primaryGuest['phone'] = normalizePhoneForStorage($guestPhone, $countryIso, $db);

        foreach ($passengers as $paxKey => $pax) {
            if (!empty($pax['phone'])) {
                if (!validatePhoneForCountry($pax['phone'], $countryIso, $db)) {
                    throw new Exception('Please enter a valid passenger phone number matching the selected country code.');
                }
                $passengers[$paxKey]['phone'] = normalizePhoneForStorage($pax['phone'], $countryIso, $db);
            }
        }

        $alreadyRevalidated = !empty($flightData['revalidated']);
        $revalidateFile = __DIR__ . '/../../../modules/flights/' . strtolower($supplierName) . '/revalidate.php';
        if (!$alreadyRevalidated && file_exists($revalidateFile)) {
            $oldPrice = (float)($input['base_price'] ?? $flightData['actual_price'] ?? $flightData['price'] ?? 0);
            $currency = strtoupper($input['base_currency'] ?? $flightData['currency'] ?? 'USD');
            $revalidateResult = flightCallSupplierRevalidate(
                $db,
                $flightData,
                $supplierName,
                $oldPrice,
                $currency,
                is_array($draftData['search_params'] ?? null) ? $draftData['search_params'] : []
            );

            if (empty($revalidateResult['status'])) {
                throw new Exception($revalidateResult['message'] ?? 'Selected fare is no longer available. Please search again.');
            }

            if (!empty($revalidateResult['data']['price_changed'])) {
                if (!empty($revalidateResult['data']['actual_price'])) {
                    $input['base_price'] = $revalidateResult['data']['actual_price'];
                }
                if (!empty($revalidateResult['data']['new_price'])) {
                    $input['subtotal'] = $revalidateResult['data']['new_price'];
                }
            }
        }
        $fareType = $formData['fareType'] ?? 'standard';
        $paymentGateway = $formData['selected_payment'] ?? '';
        $specialRequests = $formData['special_requests'] ?? '';

        // Extract Ancillaries from root or form data
        $baggage = $input['baggage'] ?? $formData['baggage'] ?? [];
        $meals = $input['meals'] ?? $formData['meals'] ?? [];
        $selectedSeat = $input['seat'] ?? $formData['selectedSeat'] ?? null;
        $ancillaryData = $input['ancillary_data'] ?? [];

        // Count passengers
        $adultsCount = 0;
        $childrenCount = 0;
        $infantsCount = 0;
        $childAges = [];

        foreach ($passengers as $key => $pax) {
            if (strpos($key, 'adult_') === 0) $adultsCount++;
            if (strpos($key, 'child_') === 0) {
                $childrenCount++;
                $childAges[] = $pax['age'] ?? 0;
            }
            if (strpos($key, 'infant_') === 0) $infantsCount++;

            // Construct Date of Birth for database save
            if (isset($pax['dob_year']) && isset($pax['dob_month']) && isset($pax['dob_day'])) {
                $passengers[$key]['dob'] = $pax['dob_year'] . '-' . $pax['dob_month'] . '-' . $pax['dob_day'];
            }

            // Construct Passport Expiry Date for database save
            if (isset($pax['passport_expiry_year']) && isset($pax['passport_expiry_month']) && isset($pax['passport_expiry_day'])) {
                $passengers[$key]['passport_expiry'] = $pax['passport_expiry_year'] . '-' . $pax['passport_expiry_month'] . '-' . $pax['passport_expiry_day'];
            }
        }

        // Generate invoice ID
        $invoiceId = str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        // Logged-in identity is users.user_id (e.g. USR…), not numeric PK.
        // Agent dashboard /bookings filter by bookings.user_id = $_SESSION['user_id'].
        $userId = (string)($_SESSION['user_id'] ?? '');
        if ($userId === '' && !empty($_SESSION['user_data']['user_id'])) {
            $userId = (string)$_SESSION['user_data']['user_id'];
        }
        // Never persist a numeric PK as user_id — dashboard will not match it
        if ($userId !== '' && ctype_digit($userId)) {
            $resolved = $db->get('users', 'user_id', ['id' => (int) $userId]);
            $userId = is_string($resolved) && $resolved !== '' ? $resolved : '';
        }
        // Logged-in agents/customers must be linked or the booking is invisible on /dashboard
        $sessionRole = strtolower((string) ($_SESSION['user_role'] ?? ''));
        if ($userId === '' && $sessionRole !== '') {
            throw new Exception('Your session is missing account identity. Please log out, log in again, and retry the booking.');
        }

        // ============================================================================
        // EXTRACT PRICING DATA FROM REQUEST
        // ============================================================================
        // All amounts in BASE CURRENCY (for payment processing and database storage)
        $actualPriceBase = (float)($input['base_price'] ?? 0);        // Original supplier price (BASE)
        $markupAmount = (float)($input['markup_amount'] ?? 0);        // Commission/markup amount (BASE)
        $subtotal = (float)($input['subtotal'] ?? 0);                 // Markup price (BASE) - before tax
        $taxAmountBase = (float)($input['tax_amount'] ?? 0);          // Tax amount (BASE)
        
        // Add Ancillary total to the base price and subtotal
        $ancillaryTotal = (float)($ancillaryData['total_base'] ?? 0);
        $actualPriceBase += $ancillaryTotal;
        $subtotal += $ancillaryTotal;

        // Calculate ancillary total in display currency for reference
        $conversionRate = 1;
        if (isset($input['conversion_rate'])) {
            $conversionRate = (float)$input['conversion_rate'];
        }
        $ancillaryTotalDisplay = $ancillaryTotal * $conversionRate;
        
        // Reconstruct pre-discount total from components
        // (frontend sends already-discounted final_total, so we rebuild here to ensure logic consistency)
        $finalTotalBase = $subtotal + $taxAmountBase;
        
        // Validate and recalculate final_total if missing or invalid
        if ($finalTotalBase <= 0 && ($subtotal > 0 || $taxAmountBase > 0)) {
            $finalTotalBase = $subtotal + $taxAmountBase;
        }

        // ============================================================================
        // SECURITY (H3): price-tampering floor. The amount charged (price_markup)
        // is built from client-submitted subtotal/base_price. Validate it against
        // the TRUSTED supplier price captured server-side in the search draft
        // ($flightData from logs_bookings) — the platform must never charge below
        // the supplier's own price. A client that lowers base_price/subtotal to
        // pay a fraction of the fare is rejected. A 10% tolerance absorbs
        // currency-rounding / legitimate revalidation variance.
        $trustedBase = (float)($flightData['actual_price'] ?? $flightData['price'] ?? 0);
        if ($trustedBase > 0) {
            // The client's supplier-cost figure (base_price + ancillaries) must
            // cover at least 90% of the trusted supplier price.
            $clientSupplierCost = (float)$actualPriceBase; // already includes ancillaries
            if ($clientSupplierCost + 0.01 < ($trustedBase * 0.90)) {
                error_log(sprintf(
                    'PRICE TAMPER BLOCKED | invoice=%s | client_base=%.2f trusted_base=%.2f',
                    $invoiceId, $clientSupplierCost, $trustedBase
                ));
                throw new Exception('The fare price could not be verified. Please search again and retry your booking.');
            }
        }

        $baseCurrency = $input['base_currency'] ?? 'USD';
        $displayCurrency = $input['display_currency'] ?? 'USD';
        
        // Calculate commission (markup amount) - already in base currency
        $commissionBase = $markupAmount;
        
        // Validate pricing data
        if ($subtotal <= 0) {
            throw new Exception('Invalid booking price. Please try again.');
        }
        
        // Get tax type from the specific supplier module configuration
        $moduleData = $db->get('modules', ['tax_type'], [
            'name' => $supplierName,
            'type' => 'flights',
            'status' => '1'
        ]);

        // Fallback to generic flights module if supplier module not found
        if (!$moduleData && $supplierName !== 'flights') {
            $moduleData = $db->get('modules', ['tax_type'], [
                'name' => 'flights',
                'type' => 'flights',
                'status' => '1'
            ]);
        }

        $taxType = $moduleData['tax_type'] ?? 'percentage';
        
        // Determine if user is agent (for commission tracking)
        $isAgent = false;
        $userRole = (string)($_SESSION['user_role'] ?? '');
        $userRowData = null;
        if (!empty($userId)) {
            $userRowData = $db->get('users', ['role', 'user_id', 'email', 'first_name', 'last_name'], ['user_id' => $userId]);
            if (is_array($userRowData) && !empty($userRowData['role'])) {
                $userRole = (string)$userRowData['role'];
            }
            $isAgent = strtolower($userRole) === 'agent';
        }
        
        // Calculate agent earning (for B2B agents only)
        // B2B agents earn the markup as commission
        $agentEarning = $isAgent ? $commissionBase : 0;

        // PROMO CODE HANDLING
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData = null;
        if (!empty($promoCodeStr) && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code' => $promoData['code'],
                    'discount_type' => $promoData['discount_type'],
                    'discount_value' => floatval($promoData['discount_value']),
                    'discount_amount' => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description' => $promoData['description'],
                    'module' => $promoData['module']
                ]);
            }
        }

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotalBase = round($finalTotalBase - $promoDiscount, 2);
        }

        // Prepare booking data (save flight routes data in booking_data column)
        $bookingDataJson = json_encode([
            'flight_data' => $flightData,
            'key' => $draftData['key'] ?? null,  // Preserve original flight offer for PNR issuing
            'search_support_log' => $flightData['booking_data']['support_log'] ?? ($draftData['flight_data']['booking_data']['support_log'] ?? null),
            'search_request' => $flightData['booking_data']['search_request'] ?? ($draftData['flight_data']['booking_data']['search_request'] ?? null),
            'search_response' => $flightData['booking_data']['search_response'] ?? ($draftData['flight_data']['booking_data']['search_response'] ?? null),
            'auth_request' => $flightData['booking_data']['auth_request'] ?? ($draftData['flight_data']['booking_data']['auth_request'] ?? null),
            'auth_response' => $flightData['booking_data']['auth_response'] ?? ($draftData['flight_data']['booking_data']['auth_response'] ?? null),
            'baggage' => $baggage,
            'meals' => $meals,
            'seat' => $selectedSeat,
            'ancillary_data' => $ancillaryData,
            'fare_type' => $fareType,
            'passengers' => $passengers,
            
            // Pricing breakdown (BASE currency)
            'base_price' => $actualPriceBase,
            'markup_amount' => $commissionBase,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmountBase,
            'final_total' => $finalTotalBase,
            
            // Display amounts (for reference)
            'base_price_display' => ($input['base_price_display'] ?? 0) + $ancillaryTotalDisplay,
            'markup_amount_display' => $input['markup_amount_display'] ?? 0,
            'subtotal_display' => ($input['subtotal_display'] ?? 0) + $ancillaryTotalDisplay,
            'tax_amount_display' => $input['tax_amount_display'] ?? 0,
            'final_total_display' => $input['display_total'] ?? 0,
            
            // Currency info
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency
        ]);

        // Prepare travellers data
        $travellersJson = json_encode($passengers);

        // Insert into bookings table
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            
            // Pricing breakdown (all in BASE currency)
            'price_original' => $actualPriceBase,      // ACTUAL PRICE IN BASE CURRENCY
            'price_markup' => $finalTotalBase,         // MARKUP PRICE + TAX IN BASE CURRENCY
            'agent_earning' => $agentEarning,          // Agent commission (B2B only)
            'tax_type' => $taxType,                    // Tax type from module config
            'tax' => $taxAmountBase,                   // TAX IN BASE CURRENCY
            'first_name' => $primaryGuest['first_name'] ?? '',
            'last_name' => $primaryGuest['last_name'] ?? '',
            'email' => $primaryGuest['email'] ?? '',
            'phone_country_code' => getPhoneCode($countryIso, $db),
            'phone' => $primaryGuest['phone'] ?? '',
            'country' => '',
            'address' => '',
            'adults' => $adultsCount,
            'infants' => (string)$infantsCount,
            'childs' => $childrenCount,
            'child_ages' => implode(',', $childAges),
            'currency_markup' => $baseCurrency,        // BASE CURRENCY (for consistency with tours/stays)
            'paid_at' => null,                        // Set when payment confirmed
            'cancellation_request' => 0,
            'cancellation_status' => 0,
            'cancellation_response' => null,
            'booking_data' => $bookingDataJson,
            'transaction_id' => '',
            'user_id' => $userId,
            'user_data' => $userRowData ? json_encode($userRowData) : '',
            'travellers' => $travellersJson,
            'nationality' => '',
            'payment_gateway' => $paymentGateway,
            'module_type' => 'flights',
            'pnr' => '',
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commissionBase,          // COMMISSION IN BASE CURRENCY (amount, not percentage)
            'module' => $supplierName,                 // Supplier name (duffel, amadeus, etc.)
            'special_requests' => $specialRequests,
            'created_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d'),
            'promo_codes' => $promoCodeJson
        ]);
        
        $bookingId = $db->id();

        if ($bookingId) {
            // Record promo code usage
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
            }
            // Trigger booking confirmed webhook
            triggerWebhook('flights/booking', 'flights.booking.confirmed', [
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'customer_name' => ($primaryGuest['first_name'] ?? '') . ' ' . ($primaryGuest['last_name'] ?? ''),
                'customer_email' => $primaryGuest['email'] ?? '',
                'customer_phone' => $primaryGuest['phone'] ?? '',
                'total_amount' => $finalTotalBase,
                'currency' => $baseCurrency,
                'adults' => $adultsCount,
                'children' => $childrenCount,
                'infants' => $infantsCount,
                'payment_gateway' => $paymentGateway,
                'from' => $flightData['from'] ?? '',
                'to' => $flightData['to'] ?? '',
                'departure_date' => $flightData['departure_date'] ?? '',
                'return_date' => $flightData['return_date'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Generate PDF invoice
            $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
            
            if (!$pdfPath || !file_exists($pdfPath)) {
                error_log("Failed to generate PDF for flight booking: $invoiceId");
            } else {
                // Trigger PDF generated webhook
                triggerWebhook('flights/invoice', 'flights.invoice.pdf_generated', [
                    'invoice_id' => $invoiceId,
                    'booking_id' => $bookingId,
                    'pdf_path' => $pdfPath,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

            // ================================================================
            // SEND NOTIFICATIONS USING NOTIFY LIBRARY
            // ================================================================
            
            // Prepare notification data
            $notifyBookingData = [
                'invoice_id' => $invoiceId,
                'amount' => $finalTotalBase,
                'currency' => $baseCurrency,
                'payment_status' => 'unpaid',
                'module_type' => 'Flight',
                'from' => $flightData['from'] ?? '',
                'to' => $flightData['to'] ?? '',
                'date' => $flightData['departure_date'] ?? date('Y-m-d'),
                'departure_date' => $flightData['departure_date'] ?? date('Y-m-d'),
                'flight_number' => $flightData['flight_number'] ?? ($flightData['routes'][0]['flight_number'] ?? ''),
                'airline_name' => $flightData['airline_name'] ?? ($flightData['routes'][0]['airline_name'] ?? ''),
                'booking_data' => $bookingDataJson,
                'booking_status' => 'pending',
                'currency_markup' => $baseCurrency,
                'price_markup' => $finalTotalBase
            ];

            // Send to Customer & Admin - HANDLED BY WEBHOOK
            // NOTIFY::booking('flights', [
            //     'email' => $primaryGuest['email'] ?? '',
            //     'phone' => $primaryGuest['phone'] ?? '',
            //     'first_name' => $primaryGuest['first_name'] ?? '',
            //     'last_name' => $primaryGuest['last_name'] ?? '',
            //     'country_code' => $primaryGuest['country_code'] ?? $primaryGuest['phone_country_code'] ?? ''
            // ], $notifyBookingData, $pdfPath);
            
            // ✅ SEND NOTIFICATION TO FLIGHT OWNER (If manual flight)
            try {
                $flightId = $flightData['id'] ?? $flightData['flight_id'] ?? 0;
                
                if ($flightId > 0) {
                    $flightInfo = $db->get('flights', ['user_id'], ['id' => $flightId]);
                    
                    if (!empty($flightInfo['user_id'])) {
                        $ownerDetails = $db->get('users', ['first_name', 'last_name', 'email', 'phone', 'phone_country_code'], ['user_id' => $flightInfo['user_id']]);
                        
                        if ($ownerDetails) {
                            NOTIFY::vendor('flights', $ownerDetails, $notifyBookingData);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to send flight owner notification: " . $e->getMessage());
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'message' => 'Booking created successfully'
            ]);
        } else {
            throw new Exception('Failed to create booking');
        }

    } catch (Exception $e) {
        error_log("FLIGHT BOOKING SUBMIT ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        http_response_code(400);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});
