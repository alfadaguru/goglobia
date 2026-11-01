<?php
// FILE: app/lib/ai/flight_ticket_extract.php
// AI extraction of flight-ticket details from an uploaded PDF or image, for the
// admin Booking Manager "Upload ticket → pick details" feature. Staff upload a
// ticket booked elsewhere and the connected AI fills the manual-flight form
// instead of re-typing (and mistyping) everything.
//
// Reuses the existing AI subsystem's config resolution + HTTP helper, but does
// NOT go through aiExtractionService::extractFromUpload() — that is hard-coupled
// to a logs_bookings draft + a (adult|child|infant)_N passenger key, neither of
// which a BM walk-in booking has. We call the Claude provider directly (the
// pattern GIA uses in gia_media.php) with a flight-ticket prompt.
//
// PDF SUPPORT: unlike the passport path (image-only), flight tickets are usually
// PDFs. Claude's Messages API natively reads a PDF via a `document` content block
// (media_type application/pdf, base64) — no server-side rasterization needed. We
// send a `document` block for PDFs and an `image` block for photos/screenshots.

@$SECURE or (defined('root') or die('Access Denied!'));

require_once __DIR__ . '/aiHttp.php';

if (!function_exists('flight_ticket_extraction_prompt')) {
    /**
     * The extraction instruction + JSON schema. Mirrors the passport prompt's
     * discipline (JSON only, empty string when unreadable, never invent) but with
     * a flight-ticket schema keyed to the BM manual-flight form fields.
     */
    function flight_ticket_extraction_prompt(): string
    {
        return <<<PROMPT
You are a flight-ticket / e-ticket / itinerary data extraction system. Read the
attached document (a flight ticket or booking confirmation) and extract the
booking details.

Return ONLY valid JSON — no markdown, no code fences, no commentary. If a field
is unreadable or absent, use an empty string "" — NEVER invent or guess a value.

Rules:
- Airport fields ("from"/"to") MUST be the 3-letter IATA airport code in UPPERCASE
  (e.g. "LOS", "LHR", "DXB"). If only a city is shown, use that city's main IATA
  code. If genuinely unknown, use "".
- "airline" is the airline's full name (e.g. "British Airways"); "airline_code" is
  its 2-letter IATA code in UPPERCASE (e.g. "BA"). Derive the code from the flight
  number prefix if not stated.
- "flight_number" is the flight number WITHOUT the airline prefix where possible
  (e.g. "081"), or the full code if that's all that's shown.
- Dates/times MUST be "YYYY-MM-DDTHH:MM" (24-hour, local to the airport). If no
  time is shown, use "YYYY-MM-DDT00:00". If no date at all, use "".
- "pnr" is the booking reference / PNR / airline locator (e.g. "ABC123").
- For each passenger, give title (Mr/Mrs/Ms/Miss/Mstr), first_name, last_name, and
  the airline-issued e-ticket/ticket number if present (ticket_number), else "".
- Return EVERY flight segment in order (outbound then return, multi-stop legs
  separate), and EVERY passenger named on the ticket.

JSON schema to return exactly:
{
  "pnr": "",
  "segments": [
    {
      "airline": "",
      "airline_code": "",
      "flight_number": "",
      "from": "",
      "to": "",
      "depart": "",
      "arrive": ""
    }
  ],
  "passengers": [
    { "title": "", "first_name": "", "last_name": "", "ticket_number": "" }
  ],
  "confidence": 0.0,
  "warnings": []
}
PROMPT;
    }
}

if (!function_exists('flight_ticket_extract_from_upload')) {
    /**
     * Extract flight details from an uploaded ticket (PDF or image) via Claude.
     *
     * @param array  $file  a $_FILES[...] entry (PDF/jpg/png/webp, <= 10MB)
     * @param object $db    Medoo instance (for config resolution)
     * @return array ['status'=>bool,'message'=>string,'data'=>array|null,'error_code'=>?string]
     *               data = { pnr, segments:[...], passengers:[...], confidence, warnings }
     */
    function flight_ticket_extract_from_upload(array $file, $db): array
    {
        $fail = function (string $code, string $msg): array {
            return ['status' => false, 'message' => $msg, 'data' => null, 'error_code' => $code];
        };

        // --- AI must be configured. Reuse the existing resolver. -------------
        if (!function_exists('passportAiActiveProviderConfig')) {
            return $fail('AI_NOT_CONFIGURED', 'The AI service is not available.');
        }
        $config = passportAiActiveProviderConfig($db);
        if (!is_array($config) || empty($config['api_key'])) {
            return $fail('AI_NOT_CONFIGURED', 'No AI provider is configured. Set an API key under Admin → Settings → AI.');
        }
        // This feature uses Claude (native PDF reading). If the active provider
        // isn't Claude, pull the Claude provider's own config from settings.
        $providerKey = strtolower((string) ($config['key'] ?? ''));
        if ($providerKey !== 'claude') {
            $settings = function_exists('passportAiSettings') ? passportAiSettings($db) : [];
            $claude = $settings['providers']['claude'] ?? null;
            if (is_array($claude) && !empty($claude['api_key'])) {
                // Merge timeout/endpoint defaults from the resolved config, override
                // the provider fields with Claude's.
                $config = array_merge($config, $claude, [
                    'key'     => 'claude',
                    'timeout' => $config['timeout'] ?? ($settings['timeout'] ?? 60),
                ]);
            } else {
                return $fail('AI_PROVIDER_UNSUPPORTED', 'Ticket reading uses Claude. Add a Claude API key under Admin → Settings → AI.');
            }
        }

        // --- Upload validation (own, light — BM has no logs_bookings draft). -
        if (!isset($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $fail('AI_UPLOAD_FAILED', 'The file did not upload correctly. Please try again.');
        }
        $maxBytes = 10 * 1024 * 1024; // 10MB (tickets can be a few MB; Claude allows 32MB)
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            return $fail('AI_FILE_TOO_LARGE', 'The file is larger than 10MB. Please upload a smaller ticket file.');
        }

        $binary = @file_get_contents($file['tmp_name']);
        if ($binary === false || $binary === '') {
            return $fail('AI_CORRUPT_FILE', 'The file could not be read.');
        }

        // Sniff the real MIME, not the client-declared one.
        $mime = '';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) { $mime = (string) finfo_file($f, $file['tmp_name']); finfo_close($f); }
        }
        $mime = strtolower(trim($mime));

        // Build the content block: PDF → document block; image → image block.
        $contentBlock = null;
        if ($mime === 'application/pdf') {
            $contentBlock = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode($binary),
                ],
            ];
        } elseif (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $contentBlock = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => ($mime === 'image/jpg') ? 'image/jpeg' : $mime,
                    'data' => base64_encode($binary),
                ],
            ];
        } else {
            return $fail('AI_INVALID_FILE_TYPE', 'Upload a PDF ticket or a clear photo/screenshot (JPG, PNG or WEBP).');
        }

        // --- Call Claude. ----------------------------------------------------
        $apiKey   = trim((string) $config['api_key']);
        $endpoint = trim((string) ($config['endpoint'] ?? '')) ?: 'https://api.anthropic.com/v1/messages';
        $timeout  = max(20, (int) ($config['timeout'] ?? 60));
        // Current, capable model for document extraction. The configured model
        // (often a stale 'claude-sonnet-4-6') is overridden here deliberately.
        $model = 'claude-opus-4-8';

        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    $contentBlock,
                    ['type' => 'text', 'text' => flight_ticket_extraction_prompt()],
                ],
            ]],
        ];

        $http = aiHttpPost($endpoint, $payload, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ], $timeout);

        if ((int) ($http['errno'] ?? 0) === CURLE_OPERATION_TIMEDOUT) {
            return $fail('AI_PROVIDER_TIMEOUT', 'The ticket reader timed out. Please try again.');
        }
        if (empty($http['ok'])) {
            // Surface a hint when the model id is rejected (helps the stale-model case).
            $apiMsg = (string) ($http['json']['error']['message'] ?? $http['error'] ?? '');
            error_log('[ai][flight-ticket] http=' . (int) ($http['http'] ?? 0) . ' msg=' . $apiMsg);
            return $fail('AI_PROVIDER_UNAVAILABLE', 'The ticket reader is temporarily unavailable. Please try again or enter the details manually.');
        }

        $response = is_array($http['json'] ?? null) ? $http['json'] : null;
        if (!is_array($response)) {
            return $fail('AI_INVALID_RESPONSE', 'The ticket reader returned an unexpected response.');
        }

        // Concatenate text blocks (same as the passport provider does).
        $content = '';
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') { $content .= (string) ($block['text'] ?? ''); }
        }
        $content = trim($content);
        if ($content === '') {
            return $fail('AI_EXTRACTION_FAILED', 'No details could be read from that file. Try a clearer copy.');
        }

        $parsed = flight_ticket_parse_json($content);
        if (!is_array($parsed)) {
            return $fail('AI_INVALID_RESPONSE', 'Could not parse the ticket details from the AI response.');
        }

        $data = flight_ticket_normalize($parsed);
        if (empty($data['segments']) && empty($data['passengers']) && $data['pnr'] === '') {
            return $fail('AI_DOCUMENT_NOT_DETECTED', 'This does not look like a flight ticket. Upload the e-ticket or itinerary.');
        }

        return ['status' => true, 'message' => 'Ticket details extracted.', 'data' => $data, 'error_code' => null];
    }
}

if (!function_exists('flight_ticket_parse_json')) {
    /** Strip code fences / extract the {...} and json_decode (same idea as parseJsonContent). */
    function flight_ticket_parse_json(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $content, $m)) {
            $content = trim($m[1]);
        } elseif (($s = strpos($content, '{')) !== false && ($e = strrpos($content, '}')) !== false && $e > $s) {
            $content = substr($content, $s, $e - $s + 1);
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('flight_ticket_normalize')) {
    /** Coerce the model output into the exact shape the BM form fill expects. */
    function flight_ticket_normalize(array $p): array
    {
        $upper = fn($v) => strtoupper(trim((string) $v));
        $str   = fn($v) => trim((string) $v);

        $segments = [];
        foreach ((array) ($p['segments'] ?? []) as $s) {
            if (!is_array($s)) { continue; }
            $seg = [
                'airline'       => $str($s['airline'] ?? ''),
                'airline_code'  => $upper($s['airline_code'] ?? ''),
                'flight_number' => $str($s['flight_number'] ?? ''),
                'from'          => $upper($s['from'] ?? ''),
                'to'            => $upper($s['to'] ?? ''),
                'depart'        => flight_ticket_norm_datetime($s['depart'] ?? ''),
                'arrive'        => flight_ticket_norm_datetime($s['arrive'] ?? ''),
            ];
            // Drop a wholly-empty segment row.
            if (implode('', $seg) !== '') { $segments[] = $seg; }
        }

        $passengers = [];
        foreach ((array) ($p['passengers'] ?? []) as $pax) {
            if (!is_array($pax)) { continue; }
            $title = $str($pax['title'] ?? '');
            // Keep only titles the form's select offers.
            if (!in_array($title, ['Mr', 'Mrs', 'Ms', 'Miss', 'Mstr'], true)) { $title = ''; }
            $row = [
                'title'         => $title,
                'first_name'    => $str($pax['first_name'] ?? ''),
                'last_name'     => $str($pax['last_name'] ?? ''),
                'ticket_number' => $str($pax['ticket_number'] ?? ''),
            ];
            if ($row['first_name'] !== '' || $row['last_name'] !== '') { $passengers[] = $row; }
        }

        $conf = $p['confidence'] ?? null;
        return [
            'pnr'         => $str($p['pnr'] ?? ''),
            'segments'    => $segments,
            'passengers'  => $passengers,
            'confidence'  => is_numeric($conf) ? max(0.0, min(1.0, (float) $conf)) : null,
            'warnings'    => array_values(array_filter(array_map($str, (array) ($p['warnings'] ?? [])))),
        ];
    }
}

if (!function_exists('flight_ticket_norm_datetime')) {
    /** Normalise to YYYY-MM-DDTHH:MM (what <input type=datetime-local> expects). */
    function flight_ticket_norm_datetime($v): string
    {
        $v = trim((string) $v);
        if ($v === '') { return ''; }
        // Already in the target shape?
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) { return $v; }
        $ts = strtotime($v);
        if ($ts === false) { return ''; }
        return date('Y-m-d\TH:i', $ts);
    }
}
