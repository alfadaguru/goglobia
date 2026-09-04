<?php
// modules/cars/mozio/lib.php
// Shared Mozio API v2 helpers (search issue, payment-status poll, etc.)

if (!function_exists('mozioModuleConfig')) {

    /**
     * Load Mozio module credentials from the modules table.
     */
    function mozioModuleConfig($db): ?array
    {
        $module = $db->get('modules', '*', ['name' => 'mozio', 'type' => 'cars']);
        if (!$module || empty($module['c1'])) {
            return null;
        }

        $environment = ($module['dev_mode'] ?? '0') === '1' ? 'test' : 'production';

        $paymentMode = $module['c2'] ?? '';
        if (!in_array($paymentMode, ['partner_managed', 'hosted_checkout', 'tokenized'], true)) {
            $paymentMode = 'partner_managed';
        }

        return [
            'api_key'      => $module['c1'],
            'base_url'     => $environment === 'test' ? 'https://api-testing.mozio.com' : 'https://api.mozio.com',
            'environment'  => $environment,
            'payment_mode' => $paymentMode,
            'module'       => $module,
        ];
    }

    /**
     * True when Admin → Mozio → Payment Mode (c2) is Mozio-hosted checkout.
     * Does not require API key — used to hide site payment gateways in UI.
     */
    function mozioIsHostedCheckout($db): bool
    {
        $module = $db->get('modules', ['c2'], ['name' => 'mozio', 'type' => 'cars']);
        if (!$module) {
            return false;
        }
        return trim((string)($module['c2'] ?? '')) === 'hosted_checkout';
    }

    /**
     * Generic Mozio API request.
     */
    function mozioApiRequest(array $cfg, string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($path, '/');
        $headers = [
            'API-KEY: ' . $cfg['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];

        $method = strtoupper($method);
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = null;
        if ($response !== false && $response !== '') {
            $decoded = json_decode($response, true);
        }

        return [
            'http_code'   => $httpCode,
            'body'        => $response,
            'data'        => is_array($decoded) ? $decoded : null,
            'curl_error'  => $curlError ?: null,
            'success'     => $httpCode >= 200 && $httpCode < 300,
        ];
    }

    /**
     * POST /v2/reservations/
     */
    function mozioCreateReservation(array $cfg, array $payload): array
    {
        return mozioApiRequest($cfg, 'POST', '/v2/reservations/', $payload, 60);
    }

    /**
     * GET /v2/reservations/{search_id}/poll/
     */
    function mozioPollReservationStatus(array $cfg, string $searchId): array
    {
        $searchId = trim($searchId);
        if ($searchId === '') {
            return [
                'http_code'  => 0,
                'data'       => null,
                'success'    => false,
                'curl_error' => 'Missing search_id',
            ];
        }

        return mozioApiRequest($cfg, 'GET', '/v2/reservations/' . rawurlencode($searchId) . '/poll/', null, 30);
    }

    /**
     * Poll until reservation reaches completed or failed (or max attempts).
     */
    function mozioPollReservationUntilFinal(array $cfg, string $searchId, int $maxAttempts = 10, int $sleepMicros = 1500000): array
    {
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                usleep($sleepMicros);
            }

            $last = mozioPollReservationStatus($cfg, $searchId);
            $status = strtolower((string)($last['data']['status'] ?? ''));

            if (in_array($status, ['completed', 'failed'], true)) {
                $last['final_status'] = $status;
                $last['attempts'] = $attempt;
                return $last;
            }
        }

        if (is_array($last)) {
            $last['final_status'] = strtolower((string)($last['data']['status'] ?? 'pending'));
            $last['attempts'] = $maxAttempts;
        }

        return $last ?? [
            'http_code'    => 0,
            'data'         => null,
            'success'      => false,
            'final_status' => 'pending',
        ];
    }

    /**
     * Normalize ISO country code (2 letters).
     */
    function mozioCountryCode(string $value, string $fallback = 'US'): string
    {
        $code = strtoupper(trim($value));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : $fallback;
    }

    /**
     * Strip phone to digits and optional leading + for Mozio.
     */
    function mozioFormatPhone(string $phone, string $countryCode = 'US'): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/[^\d]/', '', $phone);
        if ($digits === '') {
            return '';
        }

        return $hasPlus ? '+' . $digits : $digits;
    }

    /**
     * Parse airline + flight number from separate fields or combined string (e.g. "EK501").
     * When flight_number looks like IATA+digits, prefer that over a mismatched airline field.
     */
    function mozioParseAirlineFlight(string $airline = '', string $flightNumber = '', string $combined = ''): array
    {
        $airline = strtoupper(trim($airline));
        $flightNumber = trim($flightNumber);
        $combined = trim($combined !== '' ? $combined : $flightNumber);

        if ($combined !== '' && preg_match('/^([A-Za-z]{2})\s*(\d+[A-Za-z]?)$/i', $combined, $m)) {
            return [
                'airline'       => strtoupper($m[1]),
                'flight_number' => $m[2],
            ];
        }

        return [
            'airline'       => $airline,
            'flight_number' => $flightNumber,
        ];
    }

    /**
     * Interpret POST /v2/reservations/ response per Mozio docs.
     *
     * Mode 1 (partner payment): HTTP 201 + status pending|completed → poll.
     * Mode 3 (Mozio Stripe):    HTTP 202 + redirect URL → send customer to Stripe.
     */
    function mozioInterpretCreateResponse(array $create): array
    {
        $httpCode = (int)($create['http_code'] ?? 0);
        $data = is_array($create['data'] ?? null) ? $create['data'] : [];

        if ($httpCode === 202 && !empty($data['redirect'])) {
            return [
                'action'       => 'stripe_redirect',
                'redirect_url' => (string)$data['redirect'],
                'http_code'    => $httpCode,
            ];
        }

        if ($httpCode === 201) {
            $status = strtolower((string)($data['status'] ?? ''));
            if (in_array($status, ['pending', 'completed'], true)) {
                return [
                    'action'         => 'poll',
                    'initial_status' => $status,
                    'http_code'      => $httpCode,
                    'data'           => $data,
                ];
            }
        }

        $errMsg = 'Mozio reservation request failed';
        if (!empty($data['non_field_errors']) && is_array($data['non_field_errors'])) {
            $first = $data['non_field_errors'][0] ?? [];
            $errMsg = (string)($first['user_message'] ?? $first['message'] ?? $errMsg);
        } elseif (!empty($data['detail'])) {
            $errMsg = (string)$data['detail'];
        } elseif (!empty($data['message'])) {
            $errMsg = (string)$data['message'];
        }

        return [
            'action'    => 'error',
            'message'   => $errMsg,
            'http_code' => $httpCode,
            'data'      => $data,
        ];
    }

    /**
     * Create / resume a Mozio reservation for an invoice (no HTTP self-call).
     * Used by modules/cars/mozio/issue, payment/process, and cars checkout route.
     *
     * @return array JSON-shaped result (status, requires_mozio_payment, mozio_redirect_url, …)
     */
    function mozioIssueReservation($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            return [
                'status'         => false,
                'message'        => 'Invoice ID required',
                'response_error' => 'Invoice ID required',
            ];
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            return [
                'status'         => false,
                'message'        => 'Booking not found',
                'response_error' => 'Booking not found',
            ];
        }

        if (!empty($booking['pnr'])) {
            return [
                'status'              => true,
                'Prn'                 => $booking['pnr'],
                'pnr'                 => $booking['pnr'],
                'confirmation_number' => $booking['pnr'],
                'booking_reference'   => $booking['pnr'],
                'message'             => 'Mozio reservation already issued. PNR: ' . $booking['pnr'],
                'response_error'      => '',
            ];
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        // Resume an existing Mozio Stripe session instead of creating a duplicate.
        $existingStripe = trim((string)($bookingData['mozio']['stripe_redirect_url'] ?? ''));
        if ($existingStripe !== '') {
            $searchId = mozioResolveSearchId($booking, $bookingData);
            return [
                'status'                 => true,
                'requires_mozio_payment' => true,
                'mozio_redirect_url'     => $existingStripe,
                'mozio_success_url'      => mozioSuccessPageUrl($searchId, $invoiceId),
                'search_id'              => $searchId,
                'booking_status'         => 'pending',
                'message'                => 'Complete payment on Mozio checkout to confirm.',
                'response_error'         => '',
            ];
        }

        $cfg = mozioModuleConfig($db);
        if (!$cfg) {
            return [
                'status'         => false,
                'message'        => 'Mozio module not configured',
                'response_error' => 'Module not configured',
            ];
        }

        // PRICE RECONCILIATION — N/A by design (docs/MODULES.md §13.10):
        //   In hosted_checkout mode Mozio is the Merchant of Record: Mozio sets the
        //   customer-facing price and the customer pays MOZIO directly, so there is
        //   no "amount paid to us" to reconcile against a supplier price. Our
        //   earnings are a profit share (partner_profit_usd), not a markup. For
        //   partner_managed mode the reservation commits at the stored result_id
        //   (no pre-commit re-quote step in this flow). A price-check does not apply
        //   here — not a gap, a business-model difference. Left un-wired on purpose.
        try {
            $payload = mozioBuildReservationPayload($booking, $bookingData, $invoiceId, $invoiceId);
        } catch (InvalidArgumentException $e) {
            return [
                'status'         => false,
                'message'        => $e->getMessage(),
                'response_error' => $e->getMessage(),
            ];
        }

        if (($payload['search_id'] ?? '') === '' || ($payload['result_id'] ?? '') === '') {
            error_log("MOZIO ISSUE ERROR: Missing search_id/result_id for invoice {$invoiceId}");
            return [
                'status'         => false,
                'message'        => 'Missing Mozio search_id or result_id in booking data',
                'response_error' => 'Missing search_id or result_id',
            ];
        }

        $create = mozioCreateReservation($cfg, $payload);

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            if (!function_exists('logApiCall')) {
                require_once dirname(__DIR__, 2) . '/helpers.php';
            }
            logApiCall(
                'mozio_reservation_create',
                $payload,
                $create['data'] ?? ['error' => $create['curl_error'] ?? 'Empty response'],
                $create['http_code'],
                __DIR__ . '/logs',
                'Mozio_Reservation'
            );
        }

        if (!empty($create['curl_error'])) {
            return [
                'status'         => false,
                'message'        => 'Network error: ' . $create['curl_error'],
                'response_error' => $create['curl_error'],
            ];
        }

        $interpreted = mozioInterpretCreateResponse($create);
        $searchId = $payload['search_id'];

        if ($interpreted['action'] === 'stripe_redirect') {
            $redirectUrl = $interpreted['redirect_url'];
            mozioSaveStripeRedirectState($db, $invoiceId, $bookingData, $payload, $redirectUrl);

            return [
                'status'                 => true,
                'requires_mozio_payment' => true,
                'mozio_redirect_url'     => $redirectUrl,
                'mozio_success_url'      => mozioSuccessPageUrl($searchId, $invoiceId),
                'search_id'              => $searchId,
                'booking_status'         => 'pending',
                'message'                => 'Reservation submitted. Complete payment on Mozio checkout to confirm.',
                'response_error'         => '',
            ];
        }

        if ($interpreted['action'] === 'poll') {
            $initialStatus = $interpreted['initial_status'] ?? 'pending';

            if ($initialStatus === 'completed' && !empty($interpreted['data']['reservations'])) {
                $result = mozioSaveCompletedReservation(
                    $db,
                    $invoiceId,
                    $bookingData,
                    $payload,
                    $interpreted['data'],
                    'completed'
                );
            } else {
                $result = mozioPollAndFinalizeReservation($db, $cfg, $invoiceId, 10);
            }

            if (!empty($result['success'])) {
                $confirmation = $result['confirmation_number'];
                return [
                    'status'              => true,
                    'Prn'                 => $confirmation,
                    'pnr'                 => $confirmation,
                    'confirmation_number' => $confirmation,
                    'booking_reference'   => $confirmation,
                    'reference'           => $confirmation,
                    'message'             => $result['message'],
                    'response_error'      => '',
                ];
            }

            $mergedBookingData = array_merge($bookingData, [
                'mozio' => array_merge(is_array($bookingData['mozio'] ?? null) ? $bookingData['mozio'] : [], [
                    'search_id'   => $searchId,
                    'result_id'   => $payload['result_id'] ?? '',
                    'poll_status' => $result['status'] ?? 'pending',
                ]),
            ]);

            $db->update('bookings', [
                'booking_data'   => json_encode($mergedBookingData),
                'error_response' => json_encode([
                    'error'     => $result['message'] ?? 'Poll failed',
                    'status'    => $result['status'] ?? 'pending',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]),
            ], ['invoice_id' => $invoiceId]);

            return [
                'status'         => false,
                'message'        => $result['message'] ?? 'Mozio reservation failed or timed out',
                'response_error' => $result['message'] ?? 'Poll failed',
            ];
        }

        $errMsg = $interpreted['message'] ?? 'Mozio reservation request failed';
        $db->update('bookings', [
            'error_response' => json_encode([
                'error'     => $errMsg,
                'http_code' => $interpreted['http_code'] ?? ($create['http_code'] ?? 0),
                'response'  => mb_substr($create['body'] ?? '', 0, 1000),
                'timestamp' => date('Y-m-d H:i:s'),
            ]),
        ], ['invoice_id' => $invoiceId]);

        return [
            'status'         => false,
            'message'        => $errMsg,
            'response_error' => $errMsg,
        ];
    }

    /**
     * Resolve Mozio search_id from booking row / decoded booking_data (no DB column).
     */
    function mozioResolveSearchId(array $booking, ?array $bookingData = null): string
    {
        if ($bookingData === null) {
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }
        }

        $searchId = trim((string)($bookingData['mozio']['search_id'] ?? ''));
        if ($searchId !== '') {
            return $searchId;
        }

        $carData = is_array($bookingData['car_data'] ?? null) ? $bookingData['car_data'] : [];
        $raw = is_array($carData['_raw'] ?? null) ? $carData['_raw'] : [];

        return trim((string)($carData['search_id'] ?? $raw['search_id'] ?? ''));
    }

    /**
     * Find a Mozio booking invoice_id by search_id stored in booking_data JSON.
     */
    function mozioFindInvoiceBySearchId($db, string $searchId): string
    {
        $searchId = trim($searchId);
        if ($searchId === '') {
            return '';
        }

        // Loose LIKE — JSON spacing/" vs ' can vary after merges.
        $candidates = $db->select('bookings', ['invoice_id', 'booking_data'], [
            'module'          => 'mozio',
            'booking_data[~]' => $searchId,
            'ORDER'           => ['id' => 'DESC'],
            'LIMIT'           => 10,
        ]);

        if (empty($candidates) || !is_array($candidates)) {
            return '';
        }

        foreach ($candidates as $booking) {
            if (empty($booking['invoice_id'])) {
                continue;
            }
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }
            if (mozioResolveSearchId($booking, $bookingData) === $searchId) {
                return (string)$booking['invoice_id'];
            }
        }

        return '';
    }

    /**
     * Remember which invoice/search is waiting on Mozio Stripe (return fallback).
     */
    function mozioRememberPendingCheckout(string $invoiceId, string $searchId = ''): void
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['mozio_return'] = [
            'invoice_id' => $invoiceId,
            'search_id'  => trim($searchId),
            'at'         => time(),
        ];
        $_SESSION['mozio_checkout_sent'][$invoiceId] = time();
    }

    /**
     * Resolve invoice after Mozio Stripe return (DB lookup, then session fallback).
     * Mozio's /bookingconfirmed/{id}/ id is often NOT our search_id — still use the
     * pending checkout session so the customer lands on the invoice.
     */
    function mozioResolveReturnInvoice($db, string $searchId = ''): string
    {
        $searchId = trim($searchId);
        if ($searchId !== '') {
            $found = mozioFindInvoiceBySearchId($db, $searchId);
            if ($found !== '') {
                return $found;
            }

            // Sometimes the return token is already our invoice_id.
            $byInvoice = $db->get('bookings', ['invoice_id', 'module'], ['invoice_id' => $searchId]);
            if ($byInvoice && strtolower((string)($byInvoice['module'] ?? '')) === 'mozio') {
                return (string)$byInvoice['invoice_id'];
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $pending = $_SESSION['mozio_return'] ?? null;
        if (!is_array($pending) || empty($pending['invoice_id'])) {
            return '';
        }

        // Drop stale pending checkouts (older than 6 hours).
        $at = (int)($pending['at'] ?? 0);
        if ($at > 0 && (time() - $at) > 21600) {
            unset($_SESSION['mozio_return']);
            return '';
        }

        $invoiceId = (string)$pending['invoice_id'];
        $booking = $db->get('bookings', ['invoice_id', 'module'], ['invoice_id' => $invoiceId]);
        if (!$booking || strtolower((string)($booking['module'] ?? '')) !== 'mozio') {
            return '';
        }

        return $invoiceId;
    }

    /**
     * Persist a completed Mozio reservation on the bookings row.
     */
    function mozioSaveCompletedReservation($db, string $invoiceId, array $bookingData, array $payload, array $pollData, string $finalStatus): array
    {
        $reservationMeta = mozioExtractReservationFromPoll($pollData);
        $confirmation = $reservationMeta['confirmation_number'] ?? '';
        $searchId = $payload['search_id'] ?? '';

        if ($finalStatus !== 'completed' || $confirmation === '') {
            return [
                'success' => false,
                'message' => $finalStatus === 'failed'
                    ? 'Mozio reservation failed at supplier'
                    : 'Mozio reservation is still pending',
            ];
        }

        $mergedBookingData = array_merge($bookingData, [
            'mozio' => [
                'search_id'           => $searchId,
                'result_id'           => $payload['result_id'] ?? '',
                'confirmation_number' => $confirmation,
                'reservation_id'      => $reservationMeta['reservation_id'] ?? '',
                'pickup_instructions' => $reservationMeta['pickup_instructions'] ?? '',
                'issue_timestamp'     => date('Y-m-d H:i:s'),
                'poll_status'         => $finalStatus,
                'partner_profit_usd'  => $reservationMeta['partner_profit_usd'] ?? null,
                'mozio_profit_usd'    => $reservationMeta['mozio_profit_usd'] ?? null,
                'gross_revenue_usd'   => $reservationMeta['gross_revenue_usd'] ?? null,
                'amount_paid'         => $reservationMeta['amount_paid'] ?? null,
            ],
        ]);

        $updateData = [
            'pnr'            => $confirmation,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'booking_data'   => json_encode($mergedBookingData),
            'error_response' => null,
        ];

        // Mozio is Merchant of Record in this mode (hosted_checkout) — it, not
        // your own markup, sets the customer-facing price. Your earnings are
        // your negotiated share of Mozio's profit on this booking, returned
        // directly on the completed reservation (partner_profit_usd). Capture
        // it as the commission of record instead of a site-side markup figure.
        if (($reservationMeta['partner_profit_usd'] ?? null) !== null) {
            $updateData['commission'] = $reservationMeta['partner_profit_usd'];
        }

        $db->update('bookings', $updateData, ['invoice_id' => $invoiceId]);

        return [
            'success'             => true,
            'confirmation_number' => $confirmation,
            'message'             => 'Mozio transfer confirmed. Confirmation: ' . $confirmation,
        ];
    }

    /**
     * Store Mozio Stripe checkout state (payment mode #3) after POST /v2/reservations/.
     */
    function mozioSaveStripeRedirectState($db, string $invoiceId, array $bookingData, array $payload, string $redirectUrl): void
    {
        $searchId = $payload['search_id'] ?? '';
        $mergedBookingData = array_merge($bookingData, [
            'mozio' => [
                'search_id'              => $searchId,
                'result_id'              => $payload['result_id'] ?? '',
                'stripe_redirect_url'    => $redirectUrl,
                'mozio_payment_pending'  => true,
                'reservation_created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $db->update('bookings', [
            'booking_status' => 'pending',
            'booking_data'   => json_encode($mergedBookingData),
            'error_response' => null,
        ], ['invoice_id' => $invoiceId]);

        mozioRememberPendingCheckout($invoiceId, (string)$searchId);
    }

    /**
     * Poll and finalize reservation (shared by issue.php and success page).
     */
    function mozioPollAndFinalizeReservation($db, array $cfg, string $invoiceId, int $maxAttempts = 10): array
    {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found'];
        }

        if (!empty($booking['pnr'])) {
            return [
                'success'             => true,
                'confirmation_number' => $booking['pnr'],
                'message'             => 'Already confirmed',
                'status'              => 'completed',
            ];
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $searchId = mozioResolveSearchId($booking, $bookingData);

        if ($searchId === '') {
            return ['success' => false, 'message' => 'Missing Mozio search_id'];
        }

        $poll = mozioPollReservationUntilFinal($cfg, $searchId, $maxAttempts, 1500000);
        $finalStatus = strtolower((string)($poll['final_status'] ?? ($poll['data']['status'] ?? '')));

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            logApiCall(
                'mozio_reservation_poll',
                ['search_id' => $searchId, 'invoice_id' => $invoiceId, 'attempts' => $poll['attempts'] ?? null],
                $poll['data'] ?? ['error' => $poll['curl_error'] ?? 'Empty response'],
                $poll['http_code'] ?? 0,
                __DIR__ . '/logs',
                'Mozio_Reservation_Poll'
            );
        }

        $payload = [
            'search_id' => $searchId,
            'result_id' => $bookingData['mozio']['result_id'] ?? '',
        ];

        if ($finalStatus === 'completed') {
            $result = mozioSaveCompletedReservation($db, $invoiceId, $bookingData, $payload, $poll['data'] ?? [], $finalStatus);
            $result['status'] = 'completed';
            return $result;
        }

        if ($finalStatus === 'failed') {
            $failMsg = 'Mozio reservation failed at supplier';
            if (is_array($poll['data']['reservations'][0] ?? null)) {
                $failMsg = (string)($poll['data']['reservations'][0]['error_message'] ?? $failMsg);
            }
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error'        => $failMsg,
                    'final_status' => $finalStatus,
                    'timestamp'    => date('Y-m-d H:i:s'),
                ]),
            ], ['invoice_id' => $invoiceId]);

            return ['success' => false, 'message' => $failMsg, 'status' => 'failed'];
        }

        return ['success' => false, 'message' => 'Reservation still pending', 'status' => 'pending'];
    }

    /**
     * Mozio Stripe return URL on the current site (payment mode #3).
     * Goes through /cars/mozio/success so invoice_id is always in the URL when
     * Mozio honors success_url; that handler then 302s to the invoice.
     */
    function mozioSuccessPageUrl(string $searchId, string $invoiceId = ''): string
    {
        $searchId = trim($searchId);
        $invoiceId = trim($invoiceId);

        $parts = [];
        if ($invoiceId !== '') {
            $parts[] = 'invoice_id=' . urlencode($invoiceId);
        }
        if ($searchId !== '') {
            $parts[] = 'search_id=' . urlencode($searchId);
        }
        $parts[] = 'mozio_return=1';

        $path = 'cars/mozio/success' . (!empty($parts) ? ('?' . implode('&', $parts)) : '');
        return function_exists('urlOnCurrentHost') ? urlOnCurrentHost($path) : (root . $path);
    }

    /**
     * Build Mozio reservation payload from a bookings row + decoded booking_data.
     */
    function mozioBuildReservationPayload(array $booking, array $bookingData, string $partnerTrackingId, string $invoiceId = ''): array
    {
        $carData = is_array($bookingData['car_data'] ?? null) ? $bookingData['car_data'] : [];
        $raw = is_array($carData['_raw'] ?? null) ? $carData['_raw'] : [];

        $searchId = trim((string)($carData['search_id'] ?? $raw['search_id'] ?? ''));
        $resultId = trim((string)($carData['result_id'] ?? $carData['reference_id'] ?? $raw['result_id'] ?? $raw['reference_id'] ?? ''));

        $transferDetails = is_array($bookingData['transfer_details'] ?? null)
            ? $bookingData['transfer_details']
            : [];

        $guest = is_array($bookingData['guest_details'] ?? null) ? $bookingData['guest_details'] : [];
        if (isset($guest['primary_guest']) && is_array($guest['primary_guest'])) {
            $guest = $guest['primary_guest'];
        }

        $firstName = trim((string)($guest['first_name'] ?? $booking['first_name'] ?? ''));
        $lastName  = trim((string)($guest['last_name'] ?? $booking['last_name'] ?? ''));
        $email     = trim((string)($guest['email'] ?? $booking['email'] ?? ''));
        $country   = mozioCountryCode((string)($guest['country_code'] ?? $booking['country_code'] ?? 'US'));
        $phone     = mozioFormatPhone((string)($guest['phone'] ?? $booking['phone'] ?? ''), $country);

        $flightRequired = !empty($carData['flight_info_required']) || !empty($raw['flight_info_required']);
        $extraPaxRequired = !empty($carData['extra_pax_required']) || !empty($raw['extra_pax_required']);
        $tripType = (string)($carData['trip_type'] ?? $raw['trip_type'] ?? 'one_way');
        $isRoundTrip = $tripType === 'round_trip';

        $payload = [
            'search_id'            => $searchId,
            'result_id'            => $resultId,
            'email'                => $email,
            'first_name'           => $firstName !== '' ? $firstName : 'Guest',
            'last_name'            => $lastName !== '' ? $lastName : 'Traveler',
            'country_code_name'    => $country,
            'phone_number'         => $phone !== '' ? $phone : '1234567890',
            'partner_tracking_id'  => $partnerTrackingId,
        ];

        if ($flightRequired) {
            $outbound = mozioParseAirlineFlight(
                (string)($transferDetails['airline'] ?? ''),
                (string)($transferDetails['flight_number'] ?? ''),
                (string)($transferDetails['flight_number'] ?? '')
            );
            if ($outbound['airline'] === '' || $outbound['flight_number'] === '') {
                throw new InvalidArgumentException(
                    'Airline (IATA) and flight number are required for this Mozio booking (airport pickup/dropoff).'
                );
            }
            $payload['airline'] = $outbound['airline'];
            $payload['flight_number'] = $outbound['flight_number'];

            if ($isRoundTrip) {
                $returnFlight = mozioParseAirlineFlight(
                    (string)($transferDetails['return_airline'] ?? ''),
                    (string)($transferDetails['return_flight_number'] ?? ''),
                    (string)($transferDetails['return_flight_number'] ?? '')
                );
                if ($returnFlight['airline'] === '' || $returnFlight['flight_number'] === '') {
                    throw new InvalidArgumentException(
                        'Return airline (IATA) and flight number are required for this round-trip Mozio booking.'
                    );
                }
                $payload['return_airline'] = $returnFlight['airline'];
                $payload['return_flight_number'] = $returnFlight['flight_number'];
            }
        } elseif (!empty($transferDetails['airline']) || !empty($transferDetails['flight_number'])) {
            $optional = mozioParseAirlineFlight(
                (string)($transferDetails['airline'] ?? ''),
                (string)($transferDetails['flight_number'] ?? ''),
                (string)($transferDetails['flight_number'] ?? '')
            );
            if ($optional['airline'] !== '') {
                $payload['airline'] = $optional['airline'];
            }
            if ($optional['flight_number'] !== '') {
                $payload['flight_number'] = $optional['flight_number'];
            }
        }

        if ($extraPaxRequired) {
            $extraPax = [];
            $rows = $transferDetails['extra_passengers'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $fn = trim((string)($row['first_name'] ?? ''));
                    $ln = trim((string)($row['last_name'] ?? ''));
                    if ($fn === '' && $ln === '') {
                        continue;
                    }
                    $extraPax[] = [
                        'first_name' => $fn !== '' ? $fn : 'Guest',
                        'last_name'  => $ln !== '' ? $ln : 'Traveler',
                    ];
                }
            }
            if (empty($extraPax)) {
                throw new InvalidArgumentException(
                    'Additional passenger names are required for this Mozio booking (extra_pax_info).'
                );
            }
            $payload['extra_pax_info'] = $extraPax;
        }

        $instructions = [];
        if (!empty($transferDetails['pickup_address'])) {
            $instructions[] = 'Pickup: ' . trim((string)$transferDetails['pickup_address']);
        }
        // Hourly has no dropoff destination — do not send a dropoff note to Mozio.
        $serviceType = strtolower((string)($carData['service_type'] ?? $bookingData['search_params']['service_type'] ?? ''));
        $isHourlyBooking = ($serviceType === 'hourly' || $tripType === 'hourly');
        if (!$isHourlyBooking && !empty($transferDetails['dropoff_address'])) {
            $instructions[] = 'Dropoff: ' . trim((string)$transferDetails['dropoff_address']);
        }
        $special = trim((string)($booking['special_requests'] ?? ($bookingData['special_requests'] ?? '')));
        if ($special !== '') {
            $instructions[] = $special;
        }
        if (!empty($instructions)) {
            $payload['customer_special_instructions'] = implode("\n", $instructions);
        }

        $optionalAmenities = $transferDetails['optional_amenities'] ?? [];
        if (is_array($optionalAmenities) && !empty($optionalAmenities)) {
            $payload['optional_amenities'] = array_values(array_filter(array_map('strval', $optionalAmenities)));
        }

        $searchIdForReturn = trim((string)($payload['search_id'] ?? ''));
        if ($searchIdForReturn !== '') {
            $successUrl = mozioSuccessPageUrl($searchIdForReturn, $invoiceId);
            // Payment mode #3: Mozio redirects here after Stripe checkout (per partner setup / API).
            $payload['success_url'] = $successUrl;
            $payload['redirect_url'] = $successUrl;
            $payload['return_url'] = $successUrl;
        }

        return $payload;
    }

    /**
     * Extract confirmation number and reservation metadata from poll response.
     */
    function mozioExtractReservationFromPoll(?array $pollData): array
    {
        if (!is_array($pollData)) {
            return [];
        }

        $reservations = $pollData['reservations'] ?? [];
        if (!is_array($reservations) || empty($reservations)) {
            return [];
        }

        $first = $reservations[0];
        if (!is_array($first)) {
            return [];
        }

        return [
            'confirmation_number'   => trim((string)($first['confirmation_number'] ?? '')),
            'reservation_id'        => trim((string)($first['id'] ?? '')),
            'pickup_instructions'   => $first['pickup_instructions'] ?? null,
            'mobile_pickup_instructions' => $first['mobile_pickup_instructions'] ?? null,
            'can_cancel'            => $first['can_cancel'] ?? null,
            // Only present when Mozio is Merchant of Record (hosted_checkout):
            // this is your negotiated share of Mozio's profit on this specific
            // booking, per the Net Rate Distribution Agreement §2.3.2 — the
            // authoritative per-booking commission figure in that mode.
            'partner_profit_usd'   => isset($first['partner_profit_usd']) ? (float)$first['partner_profit_usd'] : null,
            'mozio_profit_usd'     => isset($first['mozio_profit_usd']) ? (float)$first['mozio_profit_usd'] : null,
            'gross_revenue_usd'    => isset($first['gross_revenue_usd']) ? (float)$first['gross_revenue_usd'] : null,
            'amount_paid'          => $first['amount_paid'] ?? null,
            'reservation'           => $first,
        ];
    }
}
