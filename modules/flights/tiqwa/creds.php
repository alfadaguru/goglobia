<?php
// path: modules/flights/tiqwa/creds.php
// Tiqwa Flights API — credential helpers + validation route.
// POST flights/tiqwa/creds
//
// Auth model (docs/research/tiqwa/Authentication.v1.json):
//   • Every call: `Authorization: Bearer <ACCESS_TOKEN>`.
//   • The token is created in the Tiqwa dashboard and is sandbox-OR-production
//     (chosen at creation). Sandbox data is a non-real cache of production.
//   • Base URLs: sandbox https://sandbox.tiqwa.com , production https://prod.tiqwa.com
//   • Flight service path prefix: /v1/flight   Utilities prefix: /v1
//
// Credential column mapping in the `modules` row (name='tiqwa', type='flights'):
//   c1 = Bearer access token
//   c2 = Base URL override (optional; blank → dev_mode picks sandbox/prod host)
//   c3..c6 = unused (c4 reserved as a private cache slot if ever needed)
//   dev_mode: '1' = sandbox (sandbox.tiqwa.com), '0' = production (prod.tiqwa.com)

global $router;

if (!defined('TIQWA_SANDBOX_HOST')) { define('TIQWA_SANDBOX_HOST', 'https://sandbox.tiqwa.com'); }
if (!defined('TIQWA_PROD_HOST'))    { define('TIQWA_PROD_HOST', 'https://prod.tiqwa.com'); }

// ===========================================================================
// HELPERS (shared by search.php / revalidate.php / utilities.php / actions/*)
// ===========================================================================

if (!function_exists('getTiqwaModule')) {
    function getTiqwaModule($db)
    {
        return $db->get('modules', '*', ['name' => 'tiqwa', 'type' => 'flights']);
    }
}

if (!function_exists('getTiqwaCredentials')) {
    /**
     * Load + normalise Tiqwa credentials from the module row. Reads the discrete
     * c1 (token) / c2 (base override) columns; tolerates a legacy JSON
     * `credentials` blob holding the same keys.
     *
     * Returns:
     *   api_token    — Bearer access token
     *   host         — resolved host (sandbox/prod or the c2 override's host)
     *   flight_base  — host + /v1/flight   (Flight service)
     *   util_base    — host + /v1          (Utilities service)
     *   mode         — 'test' (sandbox) | 'live' (production)
     *   debug        — bool
     *   module       — the raw module row
     * or null when the module row is missing.
     */
    function getTiqwaCredentials($db)
    {
        $module = getTiqwaModule($db);
        if (!$module) return null;

        $creds = [];
        if (!empty($module['credentials']) && $module['credentials'] !== '1' && $module['credentials'] !== '0') {
            $parsed = json_decode($module['credentials'], true);
            if (is_array($parsed)) $creds = $parsed;
        }
        foreach (['c1', 'c2'] as $col) {
            if (empty($creds[$col]) && !empty($module[$col])) {
                $creds[$col] = $module[$col];
            }
        }

        $devMode = (isset($module['dev_mode']) && (string) $module['dev_mode'] === '1');

        // Resolve host: an explicit c2 override wins (we take its scheme+host),
        // else the dev_mode-selected default host.
        $host = $devMode ? TIQWA_SANDBOX_HOST : TIQWA_PROD_HOST;
        $override = trim((string) ($creds['c2'] ?? ''));
        if ($override !== '') {
            // Accept either a full base URL or a bare host; normalise to scheme+host.
            $parts = parse_url($override);
            if (!empty($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'https';
                $host = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            } else {
                $host = rtrim($override, '/');
            }
        }
        $host = rtrim($host, '/');

        return [
            'api_token'   => trim((string) ($creds['c1'] ?? '')),
            'host'        => $host,
            'flight_base' => $host . '/v1/flight',
            'util_base'   => $host . '/v1',
            'mode'        => $devMode ? 'test' : 'live',
            'debug'       => $devMode,
            'module'      => $module,
        ];
    }
}

if (!function_exists('tiqwaApiRequest')) {
    /**
     * Low-level JSON request to a Tiqwa endpoint. Adds the Bearer token + JSON
     * headers, honours the shared supplier timeout constants, returns a
     * normalised result array ({success, http_code, raw, data, curl_error}).
     *
     * @param string     $method  GET|POST|DELETE
     * @param string     $url     Fully-qualified URL (incl. any query string)
     * @param array|null $payload JSON body for POST (null for GET/DELETE)
     * @param string     $token   Bearer access token
     */
    function tiqwaApiRequest($method, $url, $payload, $token, array $extraHeaders = [], $timeout = null, $connectTimeout = null)
    {
        $requestTimeout = $timeout !== null
            ? (int) $timeout
            : (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 60);
        $connTimeout = $connectTimeout !== null
            ? (int) $connectTimeout
            : (defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10);

        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        foreach ($extraHeaders as $h) {
            if (is_string($h) && $h !== '') $headers[] = $h;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $connTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        $m = strtoupper($method);
        if ($m === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $payload !== null ? json_encode($payload) : '';
        } elseif ($m !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $m; // DELETE, etc.
            if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string) $raw, true);

        return [
            'success'    => ($httpCode >= 200 && $httpCode < 300 && !$curlErr),
            'http_code'  => (int) $httpCode,
            'raw'        => $raw,
            'data'       => is_array($data) ? $data : null,
            'curl_error' => $curlErr ?: null,
        ];
    }
}

if (!function_exists('tiqwaExtractError')) {
    /** Human-readable error from a Tiqwa error body or transport failure. */
    function tiqwaExtractError($result)
    {
        if (!empty($result['curl_error'])) {
            return 'Network error: ' . $result['curl_error'];
        }
        $d = $result['data'] ?? null;
        if (is_array($d)) {
            if (!empty($d['message'])) return (string) $d['message'];
            if (!empty($d['error']))   return (string) (is_array($d['error']) ? json_encode($d['error']) : $d['error']);
            if (!empty($d['errors']))  return (string) (is_array($d['errors']) ? json_encode($d['errors']) : $d['errors']);
        }
        if (!empty($result['raw']) && is_string($result['raw'])) {
            return 'HTTP ' . ($result['http_code'] ?? 0) . ': ' . substr($result['raw'], 0, 300);
        }
        return 'HTTP ' . ($result['http_code'] ?? 0) . ' (empty response)';
    }
}

if (!function_exists('tiqwaLog')) {
    /** Log a Tiqwa API call (never leaks the token) + persist to supplier_api_log. */
    function tiqwaLog($endpoint, $request, $response, $httpCode, $error = null, $invoiceId = null)
    {
        $safeReq = is_array($request) ? $request : (json_decode((string) $request, true) ?? []);
        error_log(sprintf(
            "[TIQWA][%s] endpoint=%s invoice=%s http=%d error=%s\nREQ: %s\nRES: %s",
            date('Y-m-d H:i:s'),
            $endpoint,
            $invoiceId ?? '-',
            (int) $httpCode,
            $error ?? '-',
            is_array($safeReq) ? json_encode($safeReq) : (string) $safeReq,
            is_string($response) ? substr($response, 0, 2000) : json_encode($response)
        ));
        if (function_exists('supplierApiLog') && $invoiceId) {
            supplierApiLog('tiqwa', 'flights', (string) $endpoint, $request, $response, $httpCode, $error, (string) $invoiceId);
        }
    }
}

// ===========================================================================
// ROUTE: POST flights/tiqwa/creds — validate credentials (admin "Test")
// ---------------------------------------------------------------------------
// Validates the Bearer token with the cheapest authenticated read:
// GET /v1/wallet (Utilities). On success it surfaces BOTH balances Tiqwa
// reports — the API balance (for making calls) and the TICKET balance (for
// issuing/paying for tickets) — because the Ticket balance must be funded for
// POST /pay to succeed (see actions/issue.php).
// ===========================================================================
$router->post('flights/tiqwa/creds', function () use ($db) {

    $start = microtime(true);
    header('Content-Type: application/json');

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'        => 'tiqwa',
            'service'       => 'flights',
            'provider'      => 'Tiqwa Flight API',
            'api_version'   => 'v1',
            'timestamp'     => date('c'),
            'response_time' => 0,
            'environment'   => 'test',
        ],
    ];

    // Accept credentials from the POST form (test BEFORE saving) or the stored row.
    $postToken = isset($_POST['c1']) ? trim((string) $_POST['c1']) : '';
    $postBase  = isset($_POST['c2']) ? trim((string) $_POST['c2']) : '';
    $postEnv   = isset($_POST['env']) ? strtolower(trim((string) $_POST['env'])) : '';

    $stored = getTiqwaCredentials($db);

    $token = $postToken !== '' ? $postToken : ($stored['api_token'] ?? '');
    $mode  = $postEnv !== '' ? ($postEnv === 'test' ? 'test' : 'live') : ($stored['mode'] ?? 'test');

    // Resolve the host for THIS test (honour a posted base override + chosen env).
    $host = ($mode === 'test') ? TIQWA_SANDBOX_HOST : TIQWA_PROD_HOST;
    if ($postBase !== '') {
        $parts = parse_url($postBase);
        if (!empty($parts['host'])) {
            $host = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        } else {
            $host = rtrim($postBase, '/');
        }
    } elseif (!empty($stored['host']) && $postEnv === '') {
        $host = $stored['host'];
    }
    $host = rtrim($host, '/');

    $response['metadata']['environment'] = $mode;

    if ($token === '') {
        $response['message'] = 'Access token (c1) is required. Paste the Bearer token from your Tiqwa dashboard.';
        $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
        echo json_encode($response);
        return;
    }

    $walletUrl = $host . '/v1/wallet';
    $res = tiqwaApiRequest('GET', $walletUrl, null, $token, [], 30);
    tiqwaLog('creds/validate', ['url' => $walletUrl, 'mode' => $mode], $res['raw'], $res['http_code'], $res['curl_error']);

    if ($res['success'] && is_array($res['data'])) {
        // Wallet shape: {data:{...}} with api/ticket balances (field names vary;
        // surface the whole data block + best-effort pick the two balances).
        $w = $res['data']['data'] ?? $res['data'];
        $apiBal    = $w['api_balance']    ?? $w['api']    ?? ($w['balances']['api']    ?? null);
        $ticketBal = $w['ticket_balance'] ?? $w['ticket'] ?? ($w['balances']['ticket'] ?? null);

        $response['success'] = true;
        $response['message'] = 'Credentials are valid. Connected to Tiqwa (' . strtoupper($mode) . ').';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication'    => ['method' => 'Bearer token', 'status' => 'ok'],
            'host'              => $host,
            'mode'              => $mode,
            'wallet'            => [
                'api_balance'    => $apiBal,
                'ticket_balance' => $ticketBal,
                'raw'            => $w,
            ],
            'note' => 'The TICKET balance must stay funded for ticket issuing (POST /pay). '
                . 'API balance is consumed by API calls (search/confirm).',
        ];
    } else {
        $apiError = tiqwaExtractError($res);
        if ($res['http_code'] === 401) {
            $response['message'] = 'Authentication failed (401): the access token is invalid or expired. ' . $apiError;
        } elseif ($res['http_code'] === 0) {
            $response['message'] = 'Could not reach Tiqwa at ' . $host . '. Check the host / network. ' . $apiError;
        } else {
            $response['message'] = 'Validation failed (HTTP ' . $res['http_code'] . '): ' . $apiError;
        }
        $response['data'] = [
            'connection_status' => 'failed',
            'http_code'         => $res['http_code'],
            'mode'              => $mode,
            'host'              => $host,
        ];
    }

    $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($response);
});

// ---------------------------------------------------------------------------
// SHARED PASSENGER BUILDER — used by BOTH the pay-then-issue path (actions/
// issue.php) and the Book-on-Hold reserve path (reserve.php) so the Tiqwa
// `passengers[]` payload is built in exactly ONE place (no divergence between
// reserve and issue). All guarded, so a module that already defined these
// (issue.php historically did) keeps its copy; load order (creds.php first)
// means these win.
// ---------------------------------------------------------------------------
if (!function_exists('tiqwa_pax_type')) {
    /** Map a draft pax key (adult_1 / child_2 / infant_1) to Tiqwa's type. */
    function tiqwa_pax_type(string $paxKey): string
    {
        if (strpos($paxKey, 'child') === 0) return 'child';
        if (strpos($paxKey, 'infant') === 0) return 'infant';
        return 'adult';
    }
}

if (!function_exists('tiqwa_iso2')) {
    /** Best-effort ISO-3166 alpha-2 for a country value (code or name). */
    function tiqwa_iso2($value, $db)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (strlen($value) === 2 && !is_numeric($value)) return strtoupper($value);
        if (function_exists('resolveCountryIso')) {
            $byPhone = resolveCountryIso($value, $db);
            if ($byPhone !== '') return $byPhone;
        }
        try {
            $row = $db->get('countries', ['iso'], ['name' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
            $row = $db->get('countries', ['iso'], ['nicename' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
        } catch (\Throwable $e) {}
        return '';
    }
}

if (!function_exists('tiqwa_build_pax')) {
    /**
     * Build the Tiqwa `passengers[]` array from a booking's stored travellers.
     * Returns [] if there are no valid passengers. Identical logic to what
     * actions/issue.php used inline — extracted so reserve + issue never drift.
     */
    function tiqwa_build_pax($db, array $booking): array
    {
        $travellers = json_decode((string) ($booking['travellers'] ?? ''), true);
        if (!is_array($travellers)) { $travellers = []; }

        // SUPPLIER-HIDING: the per-pax contact FALLBACK is the booking contact, which
        // Tiqwa notifies. Resolve it per supplier policy (default company → our
        // contact; 'customer' → the traveller's). Per-pax explicit values are kept.
        $contactEmail = trim((string) ($booking['email'] ?? ''));
        $contactPhone = trim((string) ($booking['phone'] ?? ''));
        if (function_exists('supplier_resolve_contact')) {
            $__tqR = supplier_resolve_contact($db, 'flights', 'tiqwa',
                ['name' => trim((string)(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))), 'email' => $contactEmail, 'phone' => $contactPhone]);
            $contactEmail = (string) ($__tqR['email'] ?? $contactEmail);
            $contactPhone = (string) ($__tqR['phone'] ?? $contactPhone);
        }

        $pax = [];
        foreach ($travellers as $paxKey => $p) {
            if (!is_array($p) || !preg_match('/^(adult|child|infant)_\d+$/', (string) $paxKey)) continue;
            $ptype = tiqwa_pax_type((string) $paxKey);

            $dob = '';
            $isoDob = trim((string) ($p['dob'] ?? $p['date_of_birth'] ?? ''));
            if ($isoDob !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDob, $m)) {
                $dob = $m[1] . '-' . $m[2] . '-' . $m[3];
            } elseif (!empty($p['dob_year']) && !empty($p['dob_month']) && !empty($p['dob_day'])) {
                $dob = $p['dob_year'] . '-' . str_pad((string) $p['dob_month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $p['dob_day'], 2, '0', STR_PAD_LEFT);
            }

            $title = strtolower(trim((string) ($p['title'] ?? 'mr')));
            if (!in_array($title, ['mr', 'miss', 'mrs', 'ms'], true)) $title = 'mr';
            $gender = strtolower(trim((string) ($p['gender'] ?? '')));
            if (in_array($gender, ['m', 'male'], true)) $gender = 'male';
            elseif (in_array($gender, ['f', 'female'], true)) $gender = 'female';
            else $gender = in_array($title, ['mrs', 'ms', 'miss'], true) ? 'female' : 'male';

            $entry = [
                'passenger_type' => $ptype,
                'first_name'     => (string) ($p['first_name'] ?? ''),
                'last_name'      => (string) ($p['last_name'] ?? ''),
                'gender'         => $gender,
                'title'          => $title,
                'email'          => trim((string) ($p['email'] ?? '')) ?: $contactEmail,
                'phone_number'   => trim((string) ($p['phone'] ?? $p['phone_number'] ?? '')) ?: $contactPhone,
            ];
            if ($dob !== '') $entry['dob'] = $dob;

            $passportNo  = trim((string) ($p['passport_no'] ?? $p['passport'] ?? $p['passport_number'] ?? ''));
            $passportExp = trim((string) ($p['passport_exp'] ?? $p['passport_expiry'] ?? ''));
            if ($passportExp === '' && !empty($p['passport_expiry_year']) && !empty($p['passport_expiry_month']) && !empty($p['passport_expiry_day'])) {
                $passportExp = $p['passport_expiry_year'] . '-' . str_pad((string) $p['passport_expiry_month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $p['passport_expiry_day'], 2, '0', STR_PAD_LEFT);
            }
            if ($passportNo !== '') {
                // Tiqwa /book `documents` requires ALL of: number, document_type,
                // holder, issuing_country, nationality_country, issuing_date,
                // expiry_date (Flight.v1.json). Only passport is accepted.
                $passIss = trim((string) ($p['passport_issue'] ?? $p['passport_issue_date'] ?? ''));
                $entry['documents'] = [
                    'number'              => $passportNo,
                    'document_type'       => 'passport',
                    'holder'              => true,
                    'issuing_country'     => tiqwa_iso2($p['passport_issuing_country'] ?? ($p['issuing_country'] ?? ($booking['country'] ?? '')), $db),
                    'nationality_country' => tiqwa_iso2($p['nationality'] ?? ($booking['country'] ?? ''), $db),
                    'expiry_date'         => $passportExp !== '' ? date('Y-m-d', strtotime($passportExp)) : '',
                    'issuing_date'        => $passIss !== '' ? date('Y-m-d', strtotime($passIss)) : '',
                ];
            }

            $pax[] = $entry;
        }
        return $pax;
    }
}

if (!function_exists('tiqwa_validate_pax')) {
    /**
     * Validate a built Tiqwa passengers[] array against Tiqwa's /book requirements
     * BEFORE calling /book — so a customer is blocked (with a clear message) at the
     * pre-payment gate instead of failing a post-payment 422 and being auto-refunded.
     *
     * ALWAYS required per passenger (Flight.v1.json passengers[].required): dob,
     * first_name, last_name, gender, title, email, phone_number — on EVERY flight,
     * domestic or international.
     *
     * PASSPORT (`documents`) is required ONLY when $documentRequired is true (the
     * `document_required` flag from GET /confirm_price). When true, each passenger
     * needs a COMPLETE passport: number, expiry_date, issuing_date, issuing_country,
     * nationality_country. For a domestic route ($documentRequired=false) passport is
     * NOT required and never requested.
     *
     * @return array{ok:bool, message:string}
     */
    function tiqwa_validate_pax(array $pax, bool $documentRequired): array
    {
        if (empty($pax)) {
            return ['ok' => false, 'message' => 'No passengers found for this booking.'];
        }
        $n = 0;
        foreach ($pax as $p) {
            $n++;
            $who = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? '')) ?: ('Passenger ' . $n);

            // Always-required fields (per the Tiqwa /book schema).
            foreach (['first_name', 'last_name', 'gender', 'title', 'email', 'phone_number'] as $f) {
                if (trim((string) ($p[$f] ?? '')) === '') {
                    return ['ok' => false, 'message' => $who . ': ' . str_replace('_', ' ', $f) . ' is required.'];
                }
            }
            // Date of birth is required for EVERY passenger on EVERY flight.
            if (trim((string) ($p['dob'] ?? '')) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['dob'])) {
                return ['ok' => false, 'message' => $who . ': a valid date of birth (YYYY-MM-DD) is required.'];
            }

            // Passport only when the airline/route requires it.
            if ($documentRequired) {
                $doc = is_array($p['documents'] ?? null) ? $p['documents'] : [];
                if (trim((string) ($doc['number'] ?? '')) === '') {
                    return ['ok' => false, 'message' => $who . ': a passport is required for this flight (international / document-required route). Please add passport details.'];
                }
                foreach (['expiry_date' => 'passport expiry date', 'issuing_date' => 'passport issue date', 'issuing_country' => 'passport issuing country', 'nationality_country' => 'nationality'] as $k => $label) {
                    if (trim((string) ($doc[$k] ?? '')) === '') {
                        return ['ok' => false, 'message' => $who . ': ' . $label . ' is required for this flight.'];
                    }
                }
                // Passport must not be expired.
                $exp = strtotime((string) $doc['expiry_date']);
                if ($exp !== false && $exp < time()) {
                    return ['ok' => false, 'message' => $who . ': the passport has expired. Please provide a valid passport.'];
                }
            }
        }
        return ['ok' => true, 'message' => ''];
    }
}
