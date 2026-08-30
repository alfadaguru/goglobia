<?php
/**
 * AirHelp Partner API v2 - Retrieve Booking (Standalone Test)
 *
 * Retrieves booking details by identifier (booking reference).
 *
 * Usage:
 *   get_order.php?id=TEST-PHPTRAVELS-001
 *   get_order.php          (reads last_order_id.txt automatically)
 *
 * NOTE: The AirHelp Partner API v2 is primarily a push/event API.
 * As of documentation review (2026-06-17), no explicit GET /booking/{id}
 * endpoint is documented. This file attempts GET /booking/{identifier} —
 * if AirHelp does not support it, they will return 404 or 405.
 * Contact AirHelp Implementation Team to confirm if a retrieval endpoint
 * is available on your account tier.
 *
 * API docs: https://partner-api.airhelp.com/docs/v2/partner-api.html
 */

$debug = true;

// ============================================================
// API CONFIGURATION
// ============================================================
$API_BASE_URL = 'https://partner-api-sta.airhelp.com/v2'; // Staging
// $API_BASE_URL = 'https://partner-api.airhelp.com/v2';  // Production

// ----- AirHelp Partner API Bearer Token (real API auth) -----
// Replace with the real Partner Token issued by AirHelp's
// Implementation Team. The docs credentials below are NOT this.
$BEARER_TOKEN = 'REPLACE_WITH_YOUR_AIRHELP_PARTNER_TOKEN';

// ----- AirHelp Documentation Access Credentials -----
// HTTP Basic auth for https://partner-api.airhelp.com/docs/v2/partner-api.html
// (docs site only — these are NOT API credentials).
$DOCS_URL      = 'https://partner-api.airhelp.com/docs/v2/partner-api.html';
$DOCS_USERNAME = 'external.documentation.access';
$DOCS_PASSWORD = 'L5i5YuwoYVKcrla9CBjt2PmkmMTjPZxN53KMIJNX4';

// ============================================================
// LAST ORDER FILE (written by create_order.php)
// ============================================================
$LAST_ORDER_FILE = __DIR__ . '/last_order_id.txt';

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Make a cURL request to the AirHelp API.
 *
 * @param string      $method   HTTP method (GET, POST, etc.)
 * @param string      $url      Full endpoint URL
 * @param array|null  $payload  Request body (JSON-encoded if not null)
 * @param array       $headers  Additional headers merged with defaults
 * @return array{http_code:int, body:string, error:string}
 */
function airhelpRequest(string $method, string $url, ?array $payload = null, array $headers = []): array
{
    global $BEARER_TOKEN;

    $defaultHeaders = [
        'Authorization: Bearer ' . $BEARER_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,           // 30-second timeout
        CURLOPT_SSL_VERIFYPEER => true,         // SSL verification enabled
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($payload !== null) {
        $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }

    $body      = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body'      => $body !== false ? $body : '',
        'error'     => $curlError,
    ];
}

/**
 * Return a pretty-printed JSON string from any value.
 */
function prettyJson($data): string
{
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars($data);
    }
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Render an HTML error block and optionally halt execution.
 */
function showError(string $message, $details = null, bool $halt = true): void
{
    echo '<div style="background:#ffdddd;border:1px solid #cc0000;padding:15px;margin:10px 0;border-radius:4px;">';
    echo '<strong style="color:#cc0000;">ERROR:</strong> ' . htmlspecialchars($message);
    if ($details !== null) {
        echo '<pre style="margin-top:10px;font-size:13px;">' . htmlspecialchars(is_string($details) ? $details : print_r($details, true)) . '</pre>';
    }
    echo '</div>';
    if ($halt) {
        echo '</body></html>';
        exit;
    }
}

// ============================================================
// RESOLVE ORDER / BOOKING IDENTIFIER
// Priority: ?id= query string > last_order_id.txt
// ============================================================
$bookingIdentifier = null;
$idSource          = '';

// 1. Check query string: get_order.php?id=YOUR-ID
if (!empty($_GET['id'])) {
    $raw = trim($_GET['id']);

    // Validate identifier format (AirHelp: 6–255 chars, ^[a-zA-Z0-9\-._~]+$)
    if (preg_match('/^[a-zA-Z0-9\-._~]{6,255}$/', $raw)) {
        $bookingIdentifier = $raw;
        $idSource          = 'Query string (?id=)';
    } else {
        // Invalid format — show error but don't halt; try file fallback
        echo '<!-- id query param failed validation, falling back to file -->';
    }
}

// 2. Fallback: read from last_order_id.txt written by create_order.php
if ($bookingIdentifier === null) {
    if (file_exists($LAST_ORDER_FILE) && is_readable($LAST_ORDER_FILE)) {
        $fromFile = trim(file_get_contents($LAST_ORDER_FILE));
        if ($fromFile !== '' && preg_match('/^[a-zA-Z0-9\-._~]{6,255}$/', $fromFile)) {
            $bookingIdentifier = $fromFile;
            $idSource          = 'File: last_order_id.txt';
        }
    }
}

// ============================================================
// HTML PAGE START
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AirHelp API Test - Get Order</title>
    <style>
        body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; margin: 0; padding: 20px; }
        h1   { color: #569cd6; border-bottom: 1px solid #444; padding-bottom: 8px; }
        h2   { color: #9cdcfe; margin-top: 30px; }
        pre  { background: #252526; border: 1px solid #3c3c3c; padding: 15px; border-radius: 4px;
               overflow-x: auto; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
        .status-box { background: #0e3a4f; border: 2px solid #3498db; padding: 15px; border-radius: 6px;
                      margin: 15px 0; font-size: 16px; color: #3498db; }
        .label { color: #ce9178; font-weight: bold; }
        .badge-200 { background: #0e4f1f; color: #2ecc71; padding: 3px 10px; border-radius: 3px; }
        .badge-202 { background: #0e4f1f; color: #2ecc71; padding: 3px 10px; border-radius: 3px; }
        .badge-404 { background: #5a3a10; color: #e67e22; padding: 3px 10px; border-radius: 3px; }
        .badge-405 { background: #5a3a10; color: #e67e22; padding: 3px 10px; border-radius: 3px; }
        .badge-401 { background: #5a1010; color: #ff6b6b; padding: 3px 10px; border-radius: 3px; }
        .badge-err { background: #5a1010; color: #ff6b6b; padding: 3px 10px; border-radius: 3px; }
        .info-box  { background: #1e3a1e; border: 1px solid #2ecc71; padding: 12px; border-radius: 4px;
                     margin: 10px 0; color: #a8e6a8; }
        .warn-box  { background: #3a2e10; border: 1px solid #e67e22; padding: 12px; border-radius: 4px;
                     margin: 10px 0; color: #f0c07a; }
        table { border-collapse: collapse; margin: 10px 0; }
        td, th { border: 1px solid #444; padding: 8px 14px; text-align: left; }
        th { background: #2d2d2d; color: #9cdcfe; }
    </style>
</head>
<body>
<h1>AirHelp Partner API v2 — Retrieve Booking</h1>
<p style="color:#888;">Standalone test file &bull; Does not touch PHPTRAVELS core &bull; <?= date('Y-m-d H:i:s') ?></p>

<?php
// ============================================================
// GUARD: No identifier found from either source
// ============================================================
if ($bookingIdentifier === null) {
    showError(
        'No booking identifier provided.',
        "Options:\n" .
        "  1. Pass via query string: get_order.php?id=YOUR-BOOKING-ID\n" .
        "  2. Run create_order.php first — it saves the last ID to last_order_id.txt\n\n" .
        "Expected format: 6–255 chars, only: A-Z a-z 0-9 - . _ ~"
    );
}

// ============================================================
// BUILD ENDPOINT URL
// Attempting GET /booking/{identifier}
// ============================================================
$endpointUrl = rtrim($API_BASE_URL, '/') . '/booking/' . rawurlencode($bookingIdentifier);

// ============================================================
// SHOW REQUEST DETAILS
// ============================================================
echo '<h2>1. Request Details</h2>';
echo '<table>';
echo '<tr><th>Field</th><th>Value</th></tr>';
echo '<tr><td class="label">Endpoint URL</td><td>' . htmlspecialchars($endpointUrl) . '</td></tr>';
echo '<tr><td class="label">HTTP Method</td><td>GET</td></tr>';
echo '<tr><td class="label">Environment</td><td>Staging (partner-api-sta)</td></tr>';
echo '<tr><td class="label">Booking Identifier</td><td>' . htmlspecialchars($bookingIdentifier) . '</td></tr>';
echo '<tr><td class="label">Identifier Source</td><td>' . htmlspecialchars($idSource) . '</td></tr>';
echo '<tr><td class="label">Bearer Token</td><td>' . htmlspecialchars(substr($BEARER_TOKEN, 0, 20)) . '…</td></tr>';
echo '</table>';

echo '<div class="warn-box">';
echo '<strong>⚠ API Note:</strong> The AirHelp Partner API v2 is a push/event API. ';
echo 'A GET /booking endpoint may not be available on all account tiers. ';
echo 'If you receive 404 or 405, contact AirHelp Implementation Team for retrieval options.';
echo '</div>';

// ============================================================
// MAKE THE API REQUEST (GET)
// ============================================================
echo '<h2>2. API Response</h2>';

$result = airhelpRequest('GET', $endpointUrl);

// Handle cURL-level errors (network, SSL, timeout)
if (!empty($result['error'])) {
    showError('cURL error — could not reach AirHelp API.', $result['error']);
}

$httpCode  = $result['http_code'];
$rawBody   = $result['body'];
$decoded   = json_decode($rawBody, true);
$jsonValid = (json_last_error() === JSON_ERROR_NONE);

// HTTP status badge
$badgeMap   = [200 => 'badge-200', 202 => 'badge-202', 404 => 'badge-404', 405 => 'badge-405', 401 => 'badge-401'];
$badgeClass = $badgeMap[$httpCode] ?? 'badge-err';
echo '<p><span class="label">HTTP Status:</span> <span class="' . $badgeClass . '">' . $httpCode . '</span></p>';

// ============================================================
// PROCESS RESPONSE
// ============================================================
if (in_array($httpCode, [200, 202]) && $jsonValid) {
    // Extract booking status if present in response
    $status     = $decoded['data']['booking']['status']     ?? null;
    $identifier = $decoded['data']['booking']['identifier'] ?? $bookingIdentifier;

    echo '<div class="status-box">';
    echo 'BOOKING IDENTIFIER: <strong>' . htmlspecialchars($identifier) . '</strong>';
    if ($status) {
        echo '<br>STATUS: <strong>' . htmlspecialchars($status) . '</strong>';
    }
    echo '</div>';

    echo '<h2>3. Decoded JSON Response</h2>';
    echo '<pre>' . prettyJson($decoded) . '</pre>';

} elseif ($httpCode === 404) {
    showError(
        'Booking not found (HTTP 404).',
        "Identifier used: {$bookingIdentifier}\n" .
        "The booking may not exist, or GET retrieval may not be supported on this endpoint.",
        false
    );
    if ($jsonValid && $decoded) {
        echo '<h2>Error Response (Decoded)</h2>';
        echo '<pre>' . prettyJson($decoded) . '</pre>';
    }
} elseif ($httpCode === 405) {
    showError(
        'Method Not Allowed (HTTP 405) — GET is not supported on this endpoint.',
        "The AirHelp API may not expose a read endpoint for bookings. " .
        "Contact AirHelp Implementation Team for guidance on booking retrieval.",
        false
    );
} elseif ($httpCode === 401) {
    showError(
        'Unauthorized (HTTP 401) — Bearer token is missing or invalid.',
        'Set $BEARER_TOKEN to your real AirHelp Partner Token.',
        false
    );
} elseif (!$jsonValid && $rawBody !== '') {
    showError(
        'Invalid JSON in API response (HTTP ' . $httpCode . ').',
        'Raw response: ' . substr($rawBody, 0, 2000),
        false
    );
} else {
    $errTitle  = $decoded['errors'][0]['title']  ?? 'Unknown error';
    $errCauses = $decoded['errors'][0]['causes'] ?? [];
    showError(
        'API returned HTTP ' . $httpCode . ': ' . $errTitle,
        $errCauses ? implode("\n", $errCauses) : null,
        false
    );
    if ($jsonValid && $decoded) {
        echo '<h2>Error Response (Decoded)</h2>';
        echo '<pre>' . prettyJson($decoded) . '</pre>';
    }
}

// ============================================================
// RAW RESPONSE (always shown in debug mode)
// ============================================================
if ($debug) {
    echo '<h2>4. Raw API Response Body</h2>';
    echo '<pre>' . htmlspecialchars($rawBody ?: '(empty — possibly no body returned)') . '</pre>';
}
?>

<hr style="border-color:#444;margin-top:40px;">
<p style="color:#555;font-size:12px;">
    AirHelp Partner API v2 &bull; Staging: partner-api-sta.airhelp.com &bull;
    <a href="create_order.php" style="color:#569cd6;">← Create New Order</a> &bull;
    <a href="get_order.php" style="color:#569cd6;">↺ Reload</a>
</p>
</body>
</html>
