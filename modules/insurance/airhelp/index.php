<?php
// ============================================================================
// AIRHELP — FLIGHT COMPENSATION CLAIM MODULE (framework-integrated)
// ============================================================================
//
// WHAT THIS IS (verified against AirHelp Partner API v2 docs, Sept 2026):
//   AirHelp is a POST-FLIGHT COMPENSATION-CLAIM service, not a policy the
//   customer buys. The customer is NEVER charged. AirHelp keeps a 35% service
//   fee from the airline payout and pays the platform ~10.5% of the payout per
//   WON claim (commission to us). A "Booking" in the v2 API registers a
//   passenger + flight(s) so AirHelp can pursue compensation if the flight is
//   disrupted. It is asynchronous (HTTP 202) and push/event based.
//
//   Therefore this module is a FREE claim registration — it is NOT wired into
//   the payment-gateway auto-issue path (that path is for paid supplier
//   bookings). The claim is registered at booking-creation time via
//   airhelp_register_claim().
//
// ENDPOINT: POST /v2/booking/{identifier}
//   Base (staging)    : https://partner-api-sta.airhelp.com/v2
//   Base (production) : https://partner-api.airhelp.com/v2
//   Auth: Bearer "Partner Token" (issued by AirHelp Implementation Team) +
//         a configured "Booking Source". Stored in the modules row.
//
// CREDENTIAL MAP (modules table row: name='airhelp', type='insurance'):
//   c1 = Partner Bearer Token
//   c2 = Partner ID          (meta.partner_id)
//   c3 = Booking Source      (data.booking.source, configured by AirHelp)
//   dev_mode: '1' = staging, '0' = production
//
// SAFE NO-OP: until a real Partner Token + Booking Source are configured, the
// remote call is SKIPPED (no fake creds are ever sent). The claim is still
// recorded locally on the booking row and marked 'pending_credentials' so an
// operator can register it once AirHelp provisions the account.
// ============================================================================

// The MODULES API GATEWAY (modules/index.php) provides $db and $router.
if (!isset($router)) {
    return; // never load standalone
}

/**
 * True only when the module row holds a real (non-placeholder) AirHelp token
 * and booking source. Guards every remote call so placeholders are never sent.
 */
if (!function_exists('airhelp_is_configured')) {
    function airhelp_is_configured($moduleRow): bool
    {
        $token  = trim((string) ($moduleRow['c1'] ?? ''));
        $source = trim((string) ($moduleRow['c3'] ?? ''));
        if ($token === '' || $source === '') {
            return false;
        }
        // Reject the shipped placeholders.
        foreach (['REPLACE_WITH', 'YOUR_', 'TEST', 'PLACEHOLDER'] as $bad) {
            if (stripos($token, $bad) !== false) {
                return false;
            }
        }
        return true;
    }
}

/**
 * Low-level AirHelp HTTP call (SSL verification ON). Returns
 * ['http_code'=>int,'body'=>string,'decoded'=>array|null,'error'=>string|null].
 */
if (!function_exists('airhelp_request')) {
    function airhelp_request(string $method, string $url, string $bearer, ?array $payload = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bearer,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,   // keep TLS verification ON
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
                $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        }
        $raw   = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = $errNo ? curl_error($ch) : null;
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $tmp;
            }
        }
        return [
            'http_code' => $code,
            'body'      => is_string($raw) ? $raw : '',
            'decoded'   => $decoded,
            'error'     => $err,
        ];
    }
}

/**
 * RFC-4122 v4 UUID for meta.request_uuid (AirHelp tracing).
 */
if (!function_exists('airhelp_uuid_v4')) {
    function airhelp_uuid_v4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12));
    }
}

/**
 * Register a flight-compensation claim with AirHelp for a given booking.
 *
 * Reads the insurance booking row + its stored booking_data (flight + passenger),
 * builds the v2 payload, and calls POST /v2/booking/{identifier}. Stores the
 * AirHelp identifier as bookings.pnr and the raw response in booking_response.
 *
 * SAFE: if AirHelp is not configured (placeholder creds), it records the claim
 * locally with booking_status='pending_credentials' and returns without sending
 * anything to AirHelp. Never throws into the caller — always returns a result.
 *
 * @return array ['status'=>bool,'message'=>string,'reference'=>?string,'pending'=>bool]
 */
if (!function_exists('airhelp_register_claim')) {
    function airhelp_register_claim($db, string $invoiceId): array
    {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            return ['status' => false, 'message' => 'Booking not found: ' . $invoiceId, 'reference' => null, 'pending' => false];
        }

        $module = $db->get('modules', '*', ['name' => 'airhelp', 'type' => 'insurance']);
        if (!$module) {
            return ['status' => false, 'message' => 'AirHelp module not configured in DB', 'reference' => null, 'pending' => true];
        }

        $data = json_decode((string) ($booking['booking_data'] ?? ''), true) ?: [];
        $flight    = $data['flight']    ?? [];
        $passenger = $data['passenger'] ?? [];

        // Booking identifier AirHelp will store the claim under. Must match
        // ^[a-zA-Z0-9\-._~]+$ and be 6-255 chars. Derive from invoice + pnr.
        $rawRef = (string) ($flight['booking_reference'] ?? $invoiceId);
        $identifier = preg_replace('/[^a-zA-Z0-9\-._~]/', '-', $rawRef);
        if (strlen($identifier) < 6) {
            $identifier = 'CLAIM-' . $invoiceId;
        }

        // ISO-8601 with offset — fall back to +00:00 if the offset is unknown.
        $depDT = trim((string) ($flight['departure_datetime'] ?? ''));
        $arrDT = trim((string) ($flight['arrival_datetime'] ?? ''));
        $pnr   = (string) ($flight['pnr'] ?? $identifier);

        $payload = [
            'meta' => [
                'partner_id'   => (string) ($module['c2'] ?? ''),
                'request_uuid' => airhelp_uuid_v4(),
            ],
            'data' => [
                'booking' => [
                    'source'     => (string) ($module['c3'] ?? ''),
                    'references' => [$pnr],
                    'flights'    => [[
                        'id'                     => 'FLIGHT_1',
                        'booking_references'     => [$pnr],
                        'airline_code'           => (string) ($flight['airline_code'] ?? ''),
                        'flight_number'          => (string) ($flight['flight_number'] ?? ''),
                        'departure_date_time'    => $depDT,
                        'departure_airport_code' => (string) ($flight['departure_airport'] ?? ''),
                        'arrival_date_time'      => $arrDT,
                        'arrival_airport_code'   => (string) ($flight['arrival_airport'] ?? ''),
                    ]],
                    'passengers' => [[
                        'id'            => 'PAX_1',
                        'email'         => (string) ($passenger['email'] ?? $booking['email'] ?? ''),
                        'phone_number'  => (string) ($passenger['phone'] ?? $booking['phone'] ?? ''),
                        'first_name'    => (string) ($passenger['first_name'] ?? $booking['first_name'] ?? ''),
                        'last_name'     => (string) ($passenger['last_name'] ?? $booking['last_name'] ?? ''),
                        'age_category'  => 'adult',
                        'communication' => [
                            'preferred_method' => 'email',
                            'language'         => (string) ($passenger['language'] ?? 'en'),
                        ],
                        'address' => [
                            'country_code' => (string) ($passenger['country'] ?? $booking['country'] ?? ''),
                        ],
                    ]],
                ],
            ],
        ];

        // ---- SAFE NO-OP when AirHelp is not yet provisioned ----
        // NB: bookings.booking_status is an ENUM('confirmed','pending','cancelled')
        // — stay 'pending' and record the AirHelp sub-state in booking_response,
        // rather than an out-of-enum value that MySQL would truncate to ''.
        if (!airhelp_is_configured($module)) {
            $db->update('bookings', [
                'booking_status'   => 'pending',
                'pnr'              => $identifier,
                'booking_response' => json_encode(['airhelp_state' => 'pending_credentials', 'note' => 'AirHelp not configured — claim recorded locally only', 'identifier' => $identifier, 'payload' => $payload]),
            ], ['invoice_id' => $invoiceId]);
            error_log('AIRHELP: claim ' . $identifier . ' recorded locally (no partner token configured)');
            return ['status' => true, 'message' => 'Claim recorded locally — awaiting AirHelp credentials', 'reference' => $identifier, 'pending' => true];
        }

        // ---- Real AirHelp call ----
        $isStaging = (($module['dev_mode'] ?? '1') != '0');
        $base = $isStaging ? 'https://partner-api-sta.airhelp.com/v2' : 'https://partner-api.airhelp.com/v2';
        $url  = rtrim($base, '/') . '/booking/' . rawurlencode($identifier);

        $res = airhelp_request('POST', $url, (string) $module['c1'], $payload);
        $ok  = ($res['http_code'] >= 200 && $res['http_code'] < 300);

        // v2: the identifier we sent IS the AirHelp reference; try to read it back too.
        $ref = $identifier;
        if (is_array($res['decoded'])) {
            $ref = $res['decoded']['data']['booking']['identifier']
                ?? $res['decoded']['data']['identifier']
                ?? $identifier;
        }

        // booking_status ENUM has no 'failed' — on failure stay 'pending' (so it
        // can be retried) and record the failure in error_response.
        $db->update('bookings', [
            'booking_status'   => $ok ? 'confirmed' : 'pending',
            'pnr'              => $ok ? $ref : null,
            'booking_response' => $ok ? $res['body'] : null,
            'error_response'   => $ok ? null : json_encode(['airhelp_state' => 'failed', 'http_code' => $res['http_code'], 'body' => $res['body'], 'error' => $res['error']]),
        ], ['invoice_id' => $invoiceId]);

        if ($ok) {
            error_log('AIRHELP: claim registered ' . $ref . ' (HTTP ' . $res['http_code'] . ')');
            return ['status' => true, 'message' => 'Claim registered with AirHelp', 'reference' => $ref, 'pending' => false];
        }
        error_log('AIRHELP: claim registration FAILED for ' . $identifier . ' (HTTP ' . $res['http_code'] . ') ' . $res['body']);
        return ['status' => false, 'message' => 'AirHelp registration failed (HTTP ' . $res['http_code'] . ')', 'reference' => null, 'pending' => false];
    }
}

// ----------------------------------------------------------------------------
// ROUTE: on-demand claim registration / retry (JSON). Used by the insurance
// booking flow and by an admin "register claim" action.
// ----------------------------------------------------------------------------
$router->post('insurance/airhelp/issue', function () use ($db) {
    header('Content-Type: application/json');
    $invoiceId = $_POST['invoice_id'] ?? '';
    if ($invoiceId === '') {
        echo json_encode(['status' => false, 'message' => 'Missing invoice_id']);
        return;
    }
    $result = airhelp_register_claim($db, $invoiceId);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
});
