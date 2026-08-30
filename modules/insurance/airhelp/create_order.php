<?php
/**
 * AirHelp Partner API v2 — CREATE ORDER (Booking) test file
 * ----------------------------------------------------------
 * Standalone manual test page. No framework, no Composer, no DB.
 *
 * Endpoint reference (from official docs):
 *   POST /v2/booking/{identifier}
 *   Base URL (STAGING)   : https://partner-api-sta.airhelp.com/v2
 *   Base URL (PRODUCTION): https://partner-api.airhelp.com/v2
 *
 * Auth: Bearer token (Partner Token, issued by AirHelp Implementation Team).
 *
 * IMPORTANT NOTES ABOUT CREDENTIALS:
 *   The username/password (external.documentation.access / L5i5...) provided
 *   alongside the docs URL are ONLY for accessing the documentation page
 *   (HTTP Basic auth on the docs site). They are NOT API credentials.
 *   The actual Partner API requires a Bearer "Partner Token" + a configured
 *   "Booking Source" — both must be supplied by the AirHelp Implementation
 *   Team before the API will accept any request. Replace the placeholders
 *   below with the real values once received.
 *
 * Success response: HTTP 202 Accepted (the API is asynchronous).
 *
 * COMMERCIAL CONTEXT (for future reference, not part of API logic):
 *   AirHelp post-flight regular service. AirHelp keeps 35% service fee from
 *   the airline payout; PHPTRAVELS receives 30% of that service fee, which
 *   equals 10.5% of the airline payout per won claim.
 *
 * AirHelp v2 entity model — quick recap:
 *   - There is NO separate "claim" entity in v2. A "Booking" is created with
 *     flights + passengers; AirHelp internally evaluates eligibility and
 *     creates claims when a disruption occurs.
 *   - The Booking "identifier" (URL path param) IS the order/reference ID
 *     used for all subsequent operations. We choose it ourselves.
 */

// ---------------------------------------------------------------------------
// 1. CONFIG  (static for testing — replace with real values before production)
// ---------------------------------------------------------------------------

$debug = true; // master switch — enables verbose output

// AirHelp environment: 'staging' or 'production'
$AIRHELP_ENV = 'staging';

$AIRHELP_BASE_URLS = [
    'staging'    => 'https://partner-api-sta.airhelp.com/v2',
    'production' => 'https://partner-api.airhelp.com/v2',
];

// !!! REPLACE THESE WITH REAL VALUES PROVIDED BY AIRHELP IMPLEMENTATION TEAM !!!
$AIRHELP_PARTNER_TOKEN = 'REPLACE_WITH_REAL_PARTNER_BEARER_TOKEN';
$AIRHELP_PARTNER_ID    = 'REPLACE_WITH_REAL_PARTNER_ID';     // used in meta.partner_id
$AIRHELP_BOOKING_SOURCE = 'REPLACE_WITH_REAL_BOOKING_SOURCE'; // configured by AirHelp

// File where we persist the last successful booking identifier
$LAST_ORDER_FILE = __DIR__ . '/last_order_id.txt';

// ---------------------------------------------------------------------------
// 2. STATIC SAMPLE DATA  (per task spec)
// ---------------------------------------------------------------------------

$samplePassenger = [
    'first_name' => 'Qasim',
    'last_name'  => 'Hussain',
    'email'      => 'info@phptravels.com',
    'phone'      => '+923001234567',
];

$sampleFlight = [
    'airline_code'      => 'EK',
    'flight_number'     => 'EK623',
    'departure_airport' => 'LHE',
    'arrival_airport'   => 'DXB',
    'departure_date'    => '2026-07-20',
    'departure_time'    => '10:30',
    'arrival_date'      => '2026-07-20',
    'arrival_time'      => '13:00',
    'booking_reference' => 'TEST-PHPTRAVELS-001',
    'pnr'               => 'TESTPNR',
    'language'          => 'en',
    'country'           => 'PK',
];

// AirHelp requires ISO-8601 datetimes WITH timezone offset.
// LHE is +05:00 (PKT), DXB is +04:00 (GST).
$departureDateTime = $sampleFlight['departure_date'] . 'T' . $sampleFlight['departure_time'] . ':00+05:00';
$arrivalDateTime   = $sampleFlight['arrival_date']   . 'T' . $sampleFlight['arrival_time']   . ':00+04:00';

// The Booking identifier MUST match ^[a-zA-Z0-9\-.\_\~]+$ and be 6-255 chars.
// We use the partner-side booking_reference as the identifier.
$bookingIdentifier = $sampleFlight['booking_reference'];

// ---------------------------------------------------------------------------
// 3. BUILD THE REQUEST PAYLOAD
//    Shape per docs: { meta: {...}, data: { booking: { ... } } }
// ---------------------------------------------------------------------------

$payload = [
    'meta' => [
        'partner_id'   => $AIRHELP_PARTNER_ID,
        // request_uuid must be unique per request — used by AirHelp for tracing
        'request_uuid' => generateUuidV4(),
    ],
    'data' => [
        'booking' => [
            'source'     => $AIRHELP_BOOKING_SOURCE,
            'references' => [$sampleFlight['pnr']],  // PNR list
            'flights'    => [
                [
                    'id'                     => 'FLIGHT_1',
                    'booking_references'     => [$sampleFlight['pnr']],
                    'airline_code'           => $sampleFlight['airline_code'],
                    'flight_number'          => $sampleFlight['flight_number'],
                    'departure_date_time'    => $departureDateTime,
                    'departure_airport_code' => $sampleFlight['departure_airport'],
                    'arrival_date_time'      => $arrivalDateTime,
                    'arrival_airport_code'   => $sampleFlight['arrival_airport'],
                ],
            ],
            'passengers' => [
                [
                    // Unique passenger id within the request scope
                    'id'           => 'PAX_1',
                    'email'        => $samplePassenger['email'],
                    'phone_number' => $samplePassenger['phone'],
                    'first_name'   => $samplePassenger['first_name'],
                    'last_name'    => $samplePassenger['last_name'],
                    'age_category' => 'adult',
                    'communication' => [
                        'preferred_method' => 'email',
                        'language'         => $sampleFlight['language'],
                    ],
                    'address' => [
                        // address.country_code carries the country (PK in our case)
                        'country_code' => $sampleFlight['country'],
                    ],
                ],
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// 4. EXECUTE THE REQUEST
// ---------------------------------------------------------------------------

$endpointUrl = rtrim($AIRHELP_BASE_URLS[$AIRHELP_ENV], '/') . '/booking/' . rawurlencode($bookingIdentifier);

$result = airhelpRequest('POST', $endpointUrl, $payload, [
    'Authorization: Bearer ' . $AIRHELP_PARTNER_TOKEN,
]);

// ---------------------------------------------------------------------------
// 5. PARSE / DERIVE THE ORDER ID
//    In v2 the identifier we sent in the URL IS the AirHelp order ID. The
//    response body for a successful 202 echoes the booking. We treat the
//    identifier as authoritative, but also try to read it from the response.
// ---------------------------------------------------------------------------

$airhelpOrderId = null;
$decoded = $result['decoded'];

if (is_array($decoded)) {
    // Try a few likely shapes for forward compatibility
    if (!empty($decoded['data']['booking']['identifier'])) {
        $airhelpOrderId = $decoded['data']['booking']['identifier'];
    } elseif (!empty($decoded['data']['identifier'])) {
        $airhelpOrderId = $decoded['data']['identifier'];
    }
}

$httpOk = ($result['http_code'] >= 200 && $result['http_code'] < 300);

// Fall back to the identifier we used for the URL (this is what AirHelp stores it as)
if ($httpOk && !$airhelpOrderId) {
    $airhelpOrderId = $bookingIdentifier;
}

// Persist the last successful order id for get_order.php to use
if ($httpOk && $airhelpOrderId) {
    @file_put_contents($LAST_ORDER_FILE, $airhelpOrderId);
}

// ---------------------------------------------------------------------------
// 6. RENDER THE RESPONSE PAGE
// ---------------------------------------------------------------------------

renderPage([
    'title'           => 'AirHelp — Create Order (Test)',
    'order_id'        => $airhelpOrderId,
    'http_ok'         => $httpOk,
    'method'          => 'POST',
    'endpoint'        => $endpointUrl,
    'request_payload' => $payload,
    'http_code'       => $result['http_code'],
    'curl_error'      => $result['curl_error'],
    'raw_response'    => $result['raw'],
    'decoded'         => $decoded,
    'json_error'      => $result['json_error'],
]);

// ===========================================================================
// HELPER FUNCTIONS
// ===========================================================================

/**
 * Perform an AirHelp API HTTP request via cURL.
 *
 * @param string     $method  HTTP method (GET, POST, DELETE, ...)
 * @param string     $url     Full endpoint URL
 * @param array|null $payload Associative array to be JSON-encoded as body
 * @param array      $headers Additional HTTP headers (one per entry)
 * @return array     [ http_code, raw, decoded, curl_error, json_error ]
 */
function airhelpRequest($method, $url, $payload = null, $headers = [])
{
    $ch = curl_init();

    // AirHelp REQUIRES Content-Type: application/json on every request
    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $allHeaders = array_merge($defaultHeaders, $headers);

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_TIMEOUT        => 30,           // 30s timeout per spec
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,         // SSL verification ENABLED
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw       = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr   = $curlErrNo ? curl_error($ch) : null;
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Decode JSON safely
    $decoded   = null;
    $jsonError = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            $decoded   = null;
        }
    }

    return [
        'http_code'  => $httpCode,
        'raw'        => is_string($raw) ? $raw : '',
        'decoded'    => $decoded,
        'curl_error' => $curlErr,
        'json_error' => $jsonError,
    ];
}

/**
 * Pretty-print any value as readable JSON (UTF-8 safe).
 */
function prettyJson($data)
{
    if (is_string($data)) {
        // If it's already JSON, decode then re-encode for pretty layout
        $tmp = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($tmp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $data;
    }
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Render an error block on the page (does not halt execution by default).
 */
function showError($message, $details = null)
{
    echo '<div class="error"><strong>Error:</strong> ' . htmlspecialchars($message) . '</div>';
    if ($details !== null) {
        echo '<pre class="error-details">' . htmlspecialchars(is_string($details) ? $details : prettyJson($details)) . '</pre>';
    }
}

/**
 * Generate an RFC 4122 v4 UUID (used for meta.request_uuid).
 */
function generateUuidV4()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/**
 * Render the HTML page showing request & response details.
 */
function renderPage($ctx)
{
    $orderIdHtml = $ctx['order_id']
        ? '<div class="order-id">AIRHELP ORDER ID: ' . htmlspecialchars($ctx['order_id']) . '</div>'
        : '<div class="order-id failed">AIRHELP ORDER ID: (none — request failed)</div>';

    $statusClass = $ctx['http_ok'] ? 'ok' : 'fail';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($ctx['title']) ?></title>
        <style>
            body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; background: #f5f6f8; color: #222; }
            h1 { margin: 0 0 16px; }
            h2 { margin: 24px 0 8px; font-size: 16px; color: #555; text-transform: uppercase; letter-spacing: .5px; }
            pre { background: #1e1e1e; color: #e6e6e6; padding: 14px; border-radius: 6px; overflow: auto; font-size: 13px; line-height: 1.45; }
            .order-id { font-size: 18px; font-weight: 700; padding: 12px 16px; background: #0a7; color: #fff; border-radius: 6px; margin-bottom: 16px; }
            .order-id.failed { background: #b22; }
            .meta { background: #fff; padding: 12px 16px; border-radius: 6px; border: 1px solid #e1e4e8; margin-bottom: 12px; }
            .meta div { margin: 4px 0; }
            .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 700; }
            .badge.ok { background: #def7e6; color: #06632a; }
            .badge.fail { background: #ffe1e1; color: #8a1010; }
            .error { background: #ffe1e1; color: #8a1010; padding: 10px 14px; border-radius: 6px; margin: 8px 0; }
            .error-details { background: #fff5f5; color: #8a1010; }
            .nav a { display: inline-block; margin-right: 12px; }
        </style>
    </head>
    <body>
        <h1><?= htmlspecialchars($ctx['title']) ?></h1>
        <div class="nav">
            <a href="create_order.php">↻ Re-run create_order</a>
            <a href="get_order.php">→ get_order.php (uses last_order_id.txt)</a>
        </div>

        <?= $orderIdHtml ?>

        <div class="meta">
            <div><strong>Request endpoint:</strong> <?= htmlspecialchars($ctx['endpoint']) ?></div>
            <div><strong>Request method:</strong> <?= htmlspecialchars($ctx['method']) ?></div>
            <div><strong>HTTP status:</strong> <span class="badge <?= $statusClass ?>"><?= (int) $ctx['http_code'] ?></span></div>
        </div>

        <?php if ($ctx['curl_error']): ?>
            <?php showError('cURL error', $ctx['curl_error']); ?>
        <?php endif; ?>

        <?php if ($ctx['json_error']): ?>
            <?php showError('Invalid JSON in API response', $ctx['json_error']); ?>
        <?php endif; ?>

        <?php if (!$ctx['http_ok']): ?>
            <?php showError('AirHelp API returned a non-2xx status code: ' . (int) $ctx['http_code']); ?>
        <?php endif; ?>

        <h2>Request Payload</h2>
        <pre><?= htmlspecialchars(prettyJson($ctx['request_payload'])) ?></pre>

        <h2>Raw API Response</h2>
        <pre><?= htmlspecialchars($ctx['raw_response'] !== '' ? $ctx['raw_response'] : '(empty body)') ?></pre>

        <h2>Decoded JSON Response</h2>
        <pre><?= htmlspecialchars($ctx['decoded'] !== null ? prettyJson($ctx['decoded']) : '(no JSON body)') ?></pre>
    </body>
    </html>
    <?php
}
