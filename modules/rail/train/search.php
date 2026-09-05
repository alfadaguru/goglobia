<?php
// ============================================================================
// TRAIN BOOKING (RAIL) — CORE API HELPERS
// ============================================================================
// Low-level HTTP requests, dynamic credentials loading, and helper utilities.
// Single-responsibility: NO route registration here.
// ============================================================================

@$SECURE or die('Access Denied!');

// ----------------------------------------------------------------------------
// CONFIG RETRIEVER
// ----------------------------------------------------------------------------
if (!function_exists('_train_cfg')) {
    function _train_cfg($db)
    {
        try {
            $row = $db->get('modules', '*', ['type' => 'rail', 'name' => 'train']);
            if (!$row) {
                $row = $db->get('modules', '*', ['name' => 'train']);
            }
        } catch (Throwable $e) {
            return [];
        }
        return is_array($row) ? $row : [];
    }
}

// ----------------------------------------------------------------------------
// API CREDENTIALS
// ----------------------------------------------------------------------------
if (!function_exists('_train_base_url')) {
    function _train_base_url($cfg)
    {
        $url = trim((string)($cfg['c2'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        return 'http://121.43.107.128'; // fallback
    }
}

if (!function_exists('_train_api_key')) {
    function _train_api_key($cfg)
    {
        return trim((string)($cfg['c1'] ?? ''));
    }
}

if (!function_exists('_train_log_dir')) {
    function _train_log_dir(): string
    {
        return __DIR__ . '/logs';
    }
}

if (!function_exists('_train_ensure_log_dir')) {
    function _train_ensure_log_dir(): bool
    {
        $logDir = _train_log_dir();
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
                return false;
            }
        }

        return is_dir($logDir) && is_writable($logDir);
    }
}

if (!function_exists('_train_can_write_log')) {
    function _train_can_write_log(string $logFile): bool
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return false;
        }

        return !file_exists($logFile) || is_writable($logFile);
    }
}

if (!function_exists('_train_logging_enabled')) {
    function _train_logging_enabled($db): bool
    {
        if ($db === null || !function_exists('log_setting')) {
            return true;
        }
        try {
            $setting = log_setting($db, 'train');
            return $setting === '1' || $setting === 1;
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('_train_log_exchange')) {
    /**
     * Append full request/response exchange for every supplier API call.
     * Log file: modules/rail/train/logs/rail_api_YYYY-MM-DD.log
     */
    function _train_log_exchange(
        string $method,
        string $path,
        string $url,
        array $payload,
        $response,
        int $httpStatus = 0,
        ?bool $ok = null,
        ?string $error = null,
        int $attempt = 1
    ): void {
        if (!_train_ensure_log_dir()) {
            return;
        }

        $logDir  = _train_log_dir();
        $logFile = $logDir . '/rail_api_' . date('Y-m-d') . '.log';
        if (!_train_can_write_log($logFile)) {
            return;
        }

        $responseText = is_string($response)
            ? $response
            : json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entry = str_repeat('=', 80) . "\n"
            . date('Y-m-d H:i:s') . " — {$method} {$path}"
            . ($attempt > 1 ? " (attempt {$attempt})" : '') . "\n"
            . "URL: {$url}\n"
            . 'HTTP: ' . $httpStatus
            . ($ok === null ? '' : (' | OK: ' . ($ok ? 'true' : 'false')))
            . ($error ? (' | Error: ' . $error) : '') . "\n"
            . "REQUEST:\n"
            . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "RESPONSE:\n"
            . ($responseText !== false ? $responseText : '') . "\n";

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

// ----------------------------------------------------------------------------
// HTTP CLIENT WRAPPER (POST WITH JSON AND APIKEY HEADER)
// ----------------------------------------------------------------------------
if (!function_exists('_train_is_api_success')) {
    function _train_is_api_success($code): bool
    {
        return $code === 200 || $code === '200';
    }
}

if (!function_exists('_train_api_error_catalog')) {
    /**
     * Supplier API response codes (ticket/* endpoints).
     *
     * @return array<int,string>
     */
    function _train_api_error_catalog(): array
    {
        return [
            200 => 'Success',
            0   => 'An unexpected error occurred. Please try again.',
            1   => 'Passenger information is not valid. Please check names, document type, and document number.',
            2   => 'No tickets left for this train and seat class. Please choose another train or class.',
            3   => 'Scheduling conflict. This train is no longer available for the selected route or time.',
        ];
    }
}

if (!function_exists('_train_api_error_message')) {
    /** Map supplier code/msg to a user-friendly English message. */
    function _train_api_error_message($code, string $supplierMsg = ''): string
    {
        $supplierMsg = trim($supplierMsg);
        $catalog = _train_api_error_catalog();

        if ($code !== null && $code !== '' && is_numeric($code)) {
            $normalized = (int)$code;
            if ($normalized !== 200 && isset($catalog[$normalized])) {
                // Code 0 is a generic bucket — prefer the supplier message when present.
                if ($normalized === 0 && $supplierMsg !== '') {
                    return _train_human_fail_message($supplierMsg);
                }
                return $catalog[$normalized];
            }
        }

        if ($supplierMsg !== '') {
            return _train_human_fail_message($supplierMsg);
        }

        return $catalog[0];
    }
}

if (!function_exists('_train_enrich_supplier_payload')) {
    /** Add msg_en + error_code to supplier JSON payloads for clients. */
    function _train_enrich_supplier_payload(array $payload): array
    {
        $code = $payload['code'] ?? null;
        if (!_train_is_api_success($code)) {
            $payload['error_code'] = is_numeric($code) ? (int)$code : $code;
            $payload['msg_en'] = _train_api_error_message($code, (string)($payload['msg'] ?? ''));
        }

        return $payload;
    }
}

if (!function_exists('_train_supplier_json_response')) {
    function _train_supplier_json_response(array $res): string
    {
        $decoded = is_array($res['data'] ?? null)
            ? $res['data']
            : json_decode((string)($res['raw'] ?? ''), true);

        if (!is_array($decoded)) {
            return json_encode([
                'code'    => 0,
                'msg'     => (string)($res['error'] ?? 'Unknown error'),
                'msg_en'  => _train_api_error_message(0, (string)($res['error'] ?? '')),
                'data'    => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode(
            _train_enrich_supplier_payload($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}

if (!function_exists('_train_request')) {
    /**
     * @param  string $path     API endpoint path (e.g. '/ticket/trainQuery')
     * @param  array  $body     Payload array
     * @param  array  $opts     Keys: db (object), cfg (array), timeout (int), retries (int)
     * @return array  {ok, status, data, error, raw}
     */
    function _train_request($path, array $body = [], array $opts = [])
    {
        $cfg     = $opts['cfg']     ?? [];
        $db      = $opts['db']      ?? null;
        $timeout = (int)($opts['timeout'] ?? 30);
        // Retries are opt-in and should ONLY be used for idempotent, read-only calls
        // (e.g. trainQuery/trainWayQuery search). Order/cancel/refund/issue calls must
        // NOT retry, since a retried request could place/cancel/refund a duplicate order.
        $retries = max(0, (int)($opts['retries'] ?? 0));

        if (empty($cfg) && $db !== null) {
            $cfg = _train_cfg($db);
        }

        $apiKey  = _train_api_key($cfg);
        $baseUrl = _train_base_url($cfg);
        $url     = $baseUrl . $path;

        $headers = [
            'Content-Type: application/json',
            'apiKey: ' . $apiKey,
        ];

        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $attempts = $retries + 1;
        $result   = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init();
            // §16 HIGH: verify TLS on HTTPS supplier calls (was hardcoded off).
            // Only skip for the plaintext http:// IP fallback where there is no
            // certificate to verify anyway.
            $__isHttps = stripos((string) $url, 'https://') === 0;
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => $__isHttps,
                CURLOPT_SSL_VERIFYHOST => $__isHttps ? 2 : 0,
            ]);

            $raw    = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            $logPayload = [
                'endpoint' => $path,
                'url'      => $url,
                'method'   => 'POST',
                'body'     => $body,
            ];

            if ($raw === false) {
                $result = ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err, 'raw' => ''];

                if (_train_logging_enabled($db)) {
                    _train_log_exchange('POST', $path, $url, $logPayload, [
                        'transport_error' => $err,
                    ], 0, false, $err, $attempt);
                }
            } else {
                $decoded = json_decode($raw, true);
                $ok      = ($status === 200) && is_array($decoded) && _train_is_api_success($decoded['code'] ?? null);

                if (!$ok && is_array($decoded)) {
                    $decoded = _train_enrich_supplier_payload($decoded);
                    $raw     = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $failMsg = is_array($decoded)
                    ? _train_api_error_message($decoded['code'] ?? null, (string)($decoded['msg'] ?? ''))
                    : (string)($err ?: 'API returned failure code');

                $result = [
                    'ok'     => $ok,
                    'status' => $status,
                    'data'   => $decoded,
                    'error'  => $ok ? null : $failMsg,
                    'raw'    => $raw,
                ];

                if (_train_logging_enabled($db)) {
                    _train_log_exchange(
                        'POST',
                        $path,
                        $url,
                        $logPayload,
                        $decoded ?? $raw,
                        $status,
                        $ok,
                        $result['error'],
                        $attempt
                    );
                }

                if ($status > 0 && $status < 500) {
                    break;
                }
            }

            if (_train_logging_enabled($db) && _train_ensure_log_dir() && function_exists('logApiCall')) {
                $logDir   = _train_log_dir();
                $date     = date('Y-m-d');
                $reqFile  = $logDir . '/train_requests_' . $date . '.json';
                $respFile = $logDir . '/train_responses_' . $date . '.json';
                if (_train_can_write_log($reqFile) && _train_can_write_log($respFile)) {
                    logApiCall(
                        'TRAIN_API:' . $path,
                        $logPayload,
                        $raw === false ? ['transport_error' => $err] : ($raw ?? ''),
                        $status,
                        $logDir,
                        'train'
                    );
                }
            }

            if ($attempt < $attempts) {
                usleep(300000); // 300ms backoff before retrying a transient failure
            }
        }

        return $result;
    }
}

if (!function_exists('_train_order_result_data')) {
    /** Extract the inner data object from an orderResultData API response. */
    function _train_order_result_data(array $res): ?array
    {
        $data = $res['data']['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        // Supplier may return code 0 with fail_msg only on the top-level msg field.
        if (trim((string)($data['fail_msg'] ?? '')) === '') {
            $topMsg = trim((string)($res['data']['msg'] ?? ''));
            if ($topMsg !== '') {
                $data['fail_msg'] = $topMsg;
            }
        }

        return $data;
    }
}

if (!function_exists('_train_order_has_seats')) {
    function _train_order_has_seats(array $data): bool
    {
        foreach (($data['journey'] ?? []) as $leg) {
            foreach (($leg['passengers'] ?? []) as $p) {
                if (!empty($p['train_seat_no']) || !empty($p['train_coach_no'])) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('_train_apply_order_result')) {
    /**
     * Persist orderResultData / offlinePush payload onto the local booking row.
     *
     * @return array{action:string,rows_matched:int}
     */
    function _train_apply_order_result($db, string $mainOrderId, array $data, $storedResponse = null): array
    {
        if ($mainOrderId === '') {
            return ['action' => 'skipped_empty_order_id', 'rows_matched' => 0];
        }

        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        $hasSeatAssignment = _train_order_has_seats($data);
        $encodedResponse = is_string($storedResponse)
            ? $storedResponse
            : json_encode($storedResponse ?? ['data' => $data]);

        if ($failMsg !== '') {
            // fail_msg is a HARD FAILURE from the supplier (e.g. "Tickets could not
            // be issued", "No railway supplier matched", "Invalid document type" —
            // see _train_human_fail_message). The order did NOT succeed, so it must
            // NOT be marked 'confirmed' (the old code did — a failed order shown as
            // a confirmed sale). Record it as pending/for-review with the failure.
            $updated = $db->update('bookings', [
                'booking_status'   => 'pending',
                'booking_response' => $encodedResponse,
                'error_response'   => json_encode([
                    'fail_msg'    => $failMsg,
                    'fail_msg_en' => _train_human_fail_message($failMsg),
                    'source'      => 'orderResultData',
                    'note'        => 'Supplier reported a ticketing failure; not confirmed.',
                ]),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);

            return [
                'action'        => 'failed',
                'rows_matched'  => $updated ? $updated->rowCount() : 0,
            ];
        }

        if ($hasSeatAssignment) {
            $updated = $db->update('bookings', [
                'booking_status'   => 'confirmed',
                'booking_response' => $encodedResponse,
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);

            return [
                'action'        => 'confirmed',
                'rows_matched'  => $updated ? $updated->rowCount() : 0,
            ];
        }

        // Response received but no seat numbers yet — store it and do not retry.
        $updated = $db->update('bookings', [
            'booking_status'   => 'confirmed',
            'booking_response' => $encodedResponse,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);

        return [
            'action'       => 'no_seats_yet',
            'rows_matched' => $updated ? $updated->rowCount() : 0,
        ];
    }
}

if (!function_exists('_train_mark_order_result_checked')) {
    /** Record that orderResultData was fetched once for this booking. */
    function _train_mark_order_result_checked($db, string $invoiceId): void
    {
        if ($invoiceId === '') {
            return;
        }

        $booking = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            return;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        if (!empty($bookingData['order_result_checked'])) {
            return;
        }

        $bookingData['order_result_checked'] = true;
        $bookingData['order_result_checked_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
    }
}

if (!function_exists('_train_fetch_order_result_once')) {
    /**
     * Call /ticket/orderResultData once, persist the response, and never retry.
     */
    function _train_fetch_order_result_once($db, string $mainOrderId, string $invoiceId = ''): ?array
    {
        $mainOrderId = trim($mainOrderId);
        if ($mainOrderId === '') {
            return null;
        }

        if ($invoiceId === '') {
            $row = $db->get('bookings', ['invoice_id'], ['pnr' => $mainOrderId, 'module_type' => 'rail']);
            $invoiceId = (string)($row['invoice_id'] ?? '');
        }

        if ($invoiceId !== '') {
            $booking = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
            if (!empty($bookingData['order_result_checked'])) {
                return null;
            }
        }

        $res = _train_request('/ticket/orderResultData', ['main_order_id' => $mainOrderId], ['db' => $db]);
        $data = _train_order_result_data($res);

        if (!is_array($data)) {
            if ($invoiceId !== '') {
                _train_mark_order_result_checked($db, $invoiceId);
            }
            return null;
        }

        _train_apply_order_result($db, $mainOrderId, $data, $res['data'] ?? null);

        if ($invoiceId !== '') {
            _train_mark_order_result_checked($db, $invoiceId);
        }

        return $data;
    }
}

if (!function_exists('_train_issue_booking')) {
    /**
     * Place a paid rail booking with the supplier (POST /ticket/order).
     * Used by admin Issue Booking, payment gateway, and payment webhooks.
     *
     * @return array{status:bool,message?:string,Prn?:string,pnr?:string,booking_status?:string,seats_assigned?:bool,fail_msg?:string,fail_msg_en?:string,response_error?:string}
     */
    function _train_issue_booking($db, string $invoiceId): array
    {
        if ($invoiceId === '') {
            return ['status' => false, 'message' => 'Invoice ID required'];
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            return ['status' => false, 'message' => 'Booking not found'];
        }

        if (!empty($booking['pnr'])) {
            return [
                'status'         => true,
                'Prn'            => $booking['pnr'],
                'pnr'            => $booking['pnr'],
                'booking_status' => $booking['booking_status'],
                'message'        => 'Booking already issued',
            ];
        }

        $orderInput = json_decode($booking['booking_data'] ?? '', true);
        if (!is_array($orderInput) || empty($orderInput['cus_main_order_id']) || empty($orderInput['journey']) || empty($orderInput['passengers'])) {
            return ['status' => false, 'message' => 'Original booking data missing, cannot issue'];
        }

        $callbackRoot = defined('root') ? root : '';
        if ($callbackRoot === '' && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $callbackRoot = $protocol . $_SERVER['HTTP_HOST'] . '/';
        }

        $orderPayload = [
            'cus_main_order_id' => $orderInput['cus_main_order_id'],
            'journey' => array_map(static function ($leg) {
                return [
                    'cus_order_id'      => $leg['cus_order_id']      ?? '',
                    'journey_type'      => $leg['journey_type']      ?? 1,
                    'traffic_no'        => $leg['traffic_no']        ?? '',
                    'from_station_code' => $leg['from_station_code'] ?? '',
                    'to_station_code'   => $leg['to_station_code']   ?? '',
                    'from_date_time'    => $leg['from_date_time']    ?? '',
                    'to_date_time'      => $leg['to_date_time']      ?? '',
                    'seat_class'        => $leg['seat_class']        ?? '',
                    'price_total_limit' => $leg['price_total_limit'] ?? 0,
                    'end_datetime'      => $leg['end_datetime']      ?? '',
                ];
            }, $orderInput['journey']),
            'passengers' => array_map(
                static fn($p) => _train_order_passenger_row_for_supplier(is_array($p) ? $p : []),
                $orderInput['passengers']
            ),
            'callBackUrl' => $orderInput['callBackUrl'] ?? (rtrim($callbackRoot, '/') . '/ticket/offlinePush'),
        ];

        $orderPayload = _train_normalize_order_payload($orderPayload, $db);
        $res = _train_request('/ticket/order', $orderPayload, ['db' => $db]);

        if (empty($res['ok']) || empty($res['data']['data']['main_order_id'])) {
            $errMsg = _train_api_error_message(
                $res['data']['code'] ?? 0,
                (string)($res['data']['msg'] ?? $res['error'] ?? 'Train order request failed')
            );
            $db->update('bookings', [
                'error_response' => json_encode($res['data'] ?? ['error' => $errMsg]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            return [
                'status'         => false,
                'message'        => $errMsg,
                'response_error' => $errMsg,
                'error_code'     => $res['data']['code'] ?? 0,
            ];
        }

        $mainOrderId   = (string)$res['data']['data']['main_order_id'];
        $orderResponse = json_encode($res['data']);

        $db->update('bookings', [
            'pnr'              => $mainOrderId,
            'booking_response' => $orderResponse,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $pollData = _train_fetch_order_result_once($db, $mainOrderId, $invoiceId);
        $hasSeats = is_array($pollData) && _train_order_has_seats($pollData);
        $failMsg  = is_array($pollData) ? trim((string)($pollData['fail_msg'] ?? '')) : '';

        // If the supplier reported a hard failure (fail_msg), the order did NOT
        // succeed — do NOT overwrite it to 'confirmed' (the old code did, masking a
        // failed order as a confirmed sale). _train_apply_order_result already set
        // it to 'pending' with the failure; return that truthfully.
        if ($failMsg !== '') {
            $db->update('bookings', [
                'booking_status' => 'pending',
                'error_response' => json_encode([
                    'fail_msg'    => $failMsg,
                    'fail_msg_en' => _train_human_fail_message($failMsg),
                    'source'      => 'issue',
                    'note'        => 'Supplier reported a ticketing failure; not confirmed.',
                ]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            return [
                'status'            => false,
                'Prn'               => '',
                'pnr'               => $mainOrderId,
                'booking_status'    => 'pending',
                'seats_assigned'    => false,
                'message'           => _train_human_fail_message($failMsg) ?: 'Train ticketing failed at the supplier.',
                'fail_msg'          => $failMsg,
                'fail_msg_en'       => _train_human_fail_message($failMsg),
                'response_error'    => $failMsg,
            ];
        }

        $db->update('bookings', [
            'booking_status' => 'confirmed',
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        return [
            'status'            => true,
            'Prn'               => $mainOrderId,
            'pnr'               => $mainOrderId,
            'booking_reference' => $mainOrderId,
            'booking_status'    => 'confirmed',
            'seats_assigned'    => $hasSeats,
            'message'           => $hasSeats
                ? 'Train order ticketed successfully.'
                : 'Train order placed with supplier. Seat assignment pending.',
            'fail_msg'          => null,
            'fail_msg_en'       => null,
        ];
    }
}

if (!function_exists('_train_issue_rail_after_payment')) {
    /**
     * Issue a paid rail booking and fetch orderResultData once before invoice redirect.
     */
    function _train_issue_rail_after_payment($db, string $invoiceId): array
    {
        if ($invoiceId === '') {
            return ['status' => false, 'message' => 'Invoice ID required'];
        }

        $booking = $db->get('bookings', ['module_type', 'pnr', 'booking_data'], ['invoice_id' => $invoiceId]);
        if (!$booking || ($booking['module_type'] ?? '') !== 'rail') {
            return ['status' => false, 'message' => 'Not a rail booking'];
        }

        if (!empty($booking['pnr'])) {
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
            if (empty($bookingData['order_result_checked'])) {
                _train_fetch_order_result_once($db, (string)$booking['pnr'], $invoiceId);
            }

            return [
                'status'  => true,
                'pnr'     => $booking['pnr'],
                'message' => 'Already issued',
            ];
        }

        return _train_issue_booking($db, $invoiceId);
    }
}

if (!function_exists('_train_human_fail_message')) {
    function _train_human_fail_message(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        if (is_numeric($message)) {
            return _train_api_error_message((int)$message, '');
        }

        $known = [
            '未匹配到供应商，分单停止。' => 'No railway supplier could be matched for this route. Tickets could not be issued.',
            '未匹配到供应商，分单停止'  => 'No railway supplier could be matched for this route. Tickets could not be issued.',
            '第1个乘客的证件类型不正确'   => 'Invalid document type for passenger 1. Use Passport (B) for Jakarta–Bandung routes, or the correct ID type for China routes.',
        ];

        if (preg_match('/第(\d+)个乘客的证件类型不正确/u', $message, $m)) {
            return 'Invalid document type for passenger ' . $m[1] . '. Use Passport (B) for Jakarta–Bandung routes, or the correct ID type for China routes.';
        }

        return $known[$message] ?? $message;
    }
}

if (!function_exists('_train_seat_classes')) {
    /** @return array<string,string> code => English label */
    function _train_seat_classes(): array
    {
        return [
            'W' => 'No Seat',
            '1' => 'Hard Seat',
            '2' => 'Soft Seat',
            '3' => 'Hard Sleeper',
            '4' => 'Soft Sleeper',
            '5' => 'Hard Sleeper Compartment',
            '6' => 'Deluxe Soft Sleeper',
            '7' => 'First-Class Seat',
            '8' => 'Second-Class Seat',
            '9' => 'Business Class Seat',
            'A' => 'Deluxe Sleeper EMU',
            'B' => 'Mixed Hard Seat',
            'C' => 'Mixed Hard Sleeper',
            'D' => 'Preferred First-Class Seat',
            'E' => 'Premium Soft Seat',
            'F' => 'Sleeper EMU',
            'G' => 'Two-Person Soft Compartment',
            'H' => 'Single Soft Compartment',
            'I' => 'First-Class Sleeper',
            'J' => 'Second-Class Sleeper',
            'K' => 'Mixed Soft Seat',
            'L' => 'Mixed Soft Sleeper',
            'M' => 'First-Class Seat',
            'O' => 'Second-Class Seat',
            'P' => 'Premium Seat',
            'Q' => 'Multi-Function Seat',
            'S' => 'Second-Class Compartment Seat',
        ];
    }
}

if (!function_exists('_train_seat_class_label')) {
    function _train_seat_class_label(string $code, $db = null): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        if ($db !== null) {
            $row = _train_seat_class_row($db, $code);
            if ($row && !empty($row['label'])) {
                return (string)$row['label'];
            }
        }

        return _train_seat_classes()[$code] ?? $code;
    }
}

if (!function_exists('_train_seat_classes_for_api')) {
    /** @return list<array{code:string,label:string}> */
    function _train_seat_classes_for_api(): array
    {
        $out = [];
        foreach (_train_seat_classes() as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }
}

if (!function_exists('_train_ensure_seat_classes_table')) {
    function _train_ensure_seat_classes_table($db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `rail_seat_classes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(5) NOT NULL UNIQUE,
            `label` VARCHAR(120) NOT NULL,
            `tier` VARCHAR(20) NOT NULL DEFAULT 'second',
            `sort_order` INT NOT NULL DEFAULT 0,
            KEY `idx_tier` (`tier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        _train_seed_seat_classes($db);
    }
}

if (!function_exists('_train_seed_seat_classes')) {
    function _train_seed_seat_classes($db): void
    {
        $tiers = _train_seat_class_tiers();
        $order = 0;
        foreach (_train_seat_classes() as $code => $label) {
            $tier = _train_seat_class_tier($code);
            $existing = $db->get('rail_seat_classes', ['id', 'label', 'tier'], ['code' => $code]);
            if ($existing) {
                $db->update('rail_seat_classes', [
                    'label'      => $label,
                    'tier'       => $tier,
                    'sort_order' => $order,
                ], ['id' => $existing['id']]);
            } else {
                $db->insert('rail_seat_classes', [
                    'code'       => $code,
                    'label'      => $label,
                    'tier'       => $tier,
                    'sort_order' => $order,
                ]);
            }
            $order++;
        }
    }
}

if (!function_exists('_train_seat_class_catalog')) {
    /**
     * Seat class definitions from rail_seat_classes table.
     *
     * @return list<array{code:string,label:string,tier:string,tier_label:string}>
     */
    function _train_seat_class_catalog($db): array
    {
        _train_ensure_seat_classes_table($db);

        $tierLabels = [
            'second'   => 'Second Class',
            'first'    => 'First Class',
            'business' => 'Business Class',
        ];

        $rows = $db->select('rail_seat_classes', ['code', 'label', 'tier'], [
            'ORDER' => ['sort_order' => 'ASC', 'code' => 'ASC'],
        ]);

        $out = [];
        foreach ($rows as $row) {
            $tier = strtolower((string)($row['tier'] ?? 'second'));
            $out[] = [
                'code'       => strtoupper((string)$row['code']),
                'label'      => (string)$row['label'],
                'tier'       => $tier,
                'tier_label' => $tierLabels[$tier] ?? ucfirst($tier),
            ];
        }

        return $out;
    }
}

if (!function_exists('_train_seat_class_row')) {
    function _train_seat_class_row($db, string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        _train_ensure_seat_classes_table($db);
        $row = $db->get('rail_seat_classes', ['code', 'label', 'tier'], ['code' => $code]);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('_train_filter_train_seats')) {
    /**
     * Filter train seat rows by selected seat class codes (server-side).
     *
     * @param  list<string> $selectedCodes  Uppercase seat_class codes; empty = hide all
     */
    function _train_filter_train_seats(array $seats, array $selectedCodes, float $priceMin = 0, float $priceMax = PHP_FLOAT_MAX): array
    {
        if ($selectedCodes === []) {
            return [];
        }

        $allowed = array_flip(array_map(static fn($c) => strtoupper(trim((string)$c)), $selectedCodes));

        return array_values(array_filter($seats, static function ($seat) use ($allowed, $priceMin, $priceMax) {
            if (!is_array($seat)) {
                return false;
            }
            $code = strtoupper(trim((string)($seat['seat_class'] ?? $seat['seat_type'] ?? '')));
            if ($code === '' || !isset($allowed[$code])) {
                return false;
            }
            $price = (float)($seat['price'] ?? 0);
            return $price >= $priceMin && $price <= $priceMax;
        }));
    }
}

if (!function_exists('_train_seat_class_tiers')) {
    /**
     * Map supplier seat_class codes to listing filter tiers.
     *
     * @return array{business:string[],first:string[],second:string[]}
     */
    function _train_seat_class_tiers(): array
    {
        return [
            'business' => ['9', 'P', 'Q', 'E'],
            'first'    => ['M', '7', 'D', 'I', '6', '4', 'A', 'F', 'G', 'H'],
            'second'   => ['O', '8', 'S', '1', '2', '3', '5', 'B', 'C', 'J', 'K', 'L', 'W'],
        ];
    }
}

if (!function_exists('_train_seat_class_tier')) {
    /** @return 'business'|'first'|'second' */
    function _train_seat_class_tier(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 'second';
        }

        foreach (_train_seat_class_tiers() as $tier => $codes) {
            if (in_array($code, $codes, true)) {
                return $tier;
            }
        }

        $label = strtoupper(_train_seat_class_label($code));
        if (str_contains($label, 'BUSINESS') || str_contains($label, 'MULTI-FUNCTION')) {
            return 'business';
        }
        if (str_contains($label, 'FIRST') || str_contains($label, 'DELUXE')) {
            return 'first';
        }

        return 'second';
    }
}

if (!function_exists('_train_supplier_order_price')) {
    /** Supplier ceiling price for /ticket/order (minPrice/midPrice — not marked-up display price). */
    function _train_supplier_order_price(array $seat): float
    {
        foreach (['minPrice', 'midPrice', 'maxPrice', 'original_price'] as $key) {
            if (isset($seat[$key]) && (float)$seat[$key] > 0) {
                return round((float)$seat[$key], 2);
            }
        }

        return round((float)($seat['price'] ?? 0), 2);
    }
}

if (!function_exists('_train_apply_seat_price_markup')) {
    /** Apply display markup while keeping supplier_order_price for ticketing. */
    function _train_apply_seat_price_markup(array &$seat, $db, string $displayCurrency = 'USD'): void
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $supplierOrderPrice = _train_supplier_order_price($seat);
        $seat['supplier_order_price'] = $supplierOrderPrice;

        // Mark up supplier net (minPrice), not the supplier retail list price field.
        $displayBase = $supplierOrderPrice;
        if ($displayBase <= 0) {
            $displayBase = (float)($seat['price'] ?? 0);
        }

        $markupData = MARKUP($displayBase, 'rail', $db, 'USD', $displayCurrency);
        $seat['original_price'] = $supplierOrderPrice;
        $seat['price']          = (float)$markupData['price'];
        $seat['markup']         = (float)$markupData['markup'];
        $seat['currency']       = $displayCurrency;
    }
}

if (!function_exists('_train_target_currency')) {
    function _train_target_currency(array $input = []): string
    {
        $currency = strtoupper(trim((string)($input['currency'] ?? $_SESSION['app_currency'] ?? 'USD')));
        return $currency !== '' ? $currency : 'USD';
    }
}

if (!function_exists('_train_billable_passenger_count')) {
    /** Passengers charged a ticket (free infants / no-seat infants are excluded). */
    function _train_billable_passenger_count(int $journeyType, int $adults, int $children, int $infants): int
    {
        $adults = max(0, $adults);
        $children = max(0, $children);
        $infants = max(0, $infants);
        $mode = (string)(_train_region_policy($journeyType)['child_policy_mode'] ?? 'age');

        $billable = $adults + $children;

        if ($mode === 'infant_age') {
            $billable += max(0, $infants - min($infants, $adults));
        }

        return max(1, $billable);
    }
}

if (!function_exists('_train_free_infant_count')) {
    function _train_free_infant_count(int $journeyType, int $adults, int $infants): int
    {
        $infants = max(0, $infants);
        if ($infants <= 0) {
            return 0;
        }

        $mode = (string)(_train_region_policy($journeyType)['child_policy_mode'] ?? 'age');
        if ($mode === 'infant_age') {
            return min($infants, max(0, $adults));
        }

        return $infants;
    }
}

if (!function_exists('_train_passenger_requires_traveler_details')) {
    /** Free/no-seat passengers (type 3) are counted in search but need no PII at booking. */
    function _train_passenger_requires_traveler_details(int $passengerType): bool
    {
        return (int)$passengerType !== 3;
    }
}

if (!function_exists('_train_split_infant_records')) {
    /**
     * Split search infant slots into free (type 3) vs billable adult-fare infants.
     *
     * @return array{free:array<int,array{passenger_type:int,passenger_age:int}>,billable_ages:int[]}
     */
    function _train_split_infant_records(int $journeyType, int $adults, int $infants, array $infantAges): array
    {
        $infants = max(0, $infants);
        $adults = max(0, $adults);
        $mode = (string)(_train_region_policy($journeyType)['child_policy_mode'] ?? 'age');
        $free = [];
        $billableAges = [];
        $freeSlots = $adults;

        for ($i = 0; $i < $infants; $i++) {
            $age = (int)($infantAges[$i] ?? _train_parse_category_metrics([], 1, $journeyType, 'infant')[0] ?? 1);
            if ($mode === 'infant_age' && $freeSlots <= 0) {
                $billableAges[] = $age;
                continue;
            }
            $free[] = ['passenger_type' => 3, 'passenger_age' => $age];
            if ($mode === 'infant_age') {
                $freeSlots--;
            }
        }

        return ['free' => $free, 'billable_ages' => $billableAges];
    }
}

if (!function_exists('_train_order_passenger_row_for_supplier')) {
    /** Shape a stored passenger row for POST /ticket/order (free infants: type + age only). */
    function _train_order_passenger_row_for_supplier(array $p): array
    {
        $type = (int)($p['passenger_type'] ?? 1);
        if ($type === 3) {
            $row = ['passenger_type' => 3];
            if (isset($p['passenger_age']) && $p['passenger_age'] !== '' && $p['passenger_age'] !== null) {
                $row['passenger_age'] = (int)$p['passenger_age'];
            }
            return $row;
        }

        $row = [
            'passenger_first_name'    => $p['passenger_first_name']    ?? '',
            'passenger_last_name'     => $p['passenger_last_name']     ?? '',
            'passenger_type'          => $type,
            'passenger_card_type'     => $p['passenger_card_type']     ?? '',
            'passenger_card_no'       => $p['passenger_card_no']       ?? '',
            'passenger_sex_code'      => $p['passenger_sex_code']      ?? '',
            'passenger_birth_date'    => $p['passenger_birth_date']    ?? '',
            'passenger_country_code'  => $p['passenger_country_code']  ?? '',
            'passenger_card_validity' => $p['passenger_card_validity'] ?? '',
        ];
        if (isset($p['passenger_age']) && $p['passenger_age'] !== '' && $p['passenger_age'] !== null) {
            $row['passenger_age'] = (int)$p['passenger_age'];
        }

        return $row;
    }
}

if (!function_exists('_train_billable_passengers_from_list')) {
    /** Count ticketed passengers from an order payload (type 3 = free infant / no seat). */
    function _train_billable_passengers_from_list(array $passengers): int
    {
        $billable = 0;
        foreach ($passengers as $p) {
            if (!is_array($p)) {
                continue;
            }
            if ((int)($p['passenger_type'] ?? 1) === 3) {
                continue;
            }
            $billable++;
        }
        return max(1, $billable);
    }
}

if (!function_exists('_train_calculate_display_price')) {
    /**
     * Apply rail markup and convert supplier USD pricing to the user's display currency.
     *
     * @return array{display_currency:string,display_total:float,display_per_seat:float,supplier_per_seat_usd:float,supplier_total_usd:float,markup:float}
     */
    function _train_calculate_display_price($db, float $supplierUsdPerSeat, int $passengers, array $input = []): array
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $passengers = max(1, $passengers);
        $supplierUsdPerSeat = max(0, $supplierUsdPerSeat);
        $displayCurrency = _train_target_currency($input);
        $supplierTotalUsd = round($supplierUsdPerSeat * $passengers, 2);

        // Per-seat markup then multiply — matches listing and fixed-markup modules.
        $markupData = MARKUP($supplierUsdPerSeat, 'rail', $db, 'USD', $displayCurrency);
        $displayPerSeat = (float)$markupData['price'];
        $displayTotal = round($displayPerSeat * $passengers, 2);
        $markupTotal = round((float)$markupData['markup'] * $passengers, 2);

        return [
            'display_currency'      => $displayCurrency,
            'display_total'         => $displayTotal,
            'display_per_seat'      => $displayPerSeat,
            'supplier_per_seat_usd' => round($supplierUsdPerSeat, 2),
            'supplier_total_usd'    => $supplierTotalUsd,
            'markup'                => $markupTotal,
        ];
    }
}

if (!function_exists('_train_apply_booking_markup')) {
    /** Markup + currency conversion for a supplier USD order total at checkout. */
    function _train_apply_booking_markup($db, float $supplierTotalUsd, array $input = []): array
    {
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 3) . '/app/lib/functions.php';
        }

        $displayCurrency = _train_target_currency($input);
        $supplierTotalUsd = max(0, $supplierTotalUsd);

        $passengers = max(1, (int)($input['passengers_count'] ?? 0));
        if (!empty($input['passengers']) && is_array($input['passengers'])) {
            $passengers = _train_billable_passengers_from_list($input['passengers']);
        } elseif ($passengers <= 1) {
            $journeyType = (int)($input['journey'][0]['journey_type'] ?? $input['journey_type'] ?? 1);
            $adults = max(0, (int)($input['adults'] ?? 0));
            $children = max(0, (int)($input['children'] ?? 0));
            $infants = max(0, (int)($input['infants'] ?? 0));
            $passengers = _train_billable_passenger_count($journeyType, $adults, $children, $infants);
        }

        $supplierPerSeat = $supplierTotalUsd / $passengers;
        $markupData = MARKUP($supplierPerSeat, 'rail', $db, 'USD', $displayCurrency);
        $finalPrice = round((float)$markupData['price'] * $passengers, 2);
        $commission = round((float)$markupData['markup'] * $passengers, 2);

        return [
            'display_currency'   => $displayCurrency,
            'final_price'        => $finalPrice,
            'commission'         => $commission,
            'supplier_total_usd' => round($supplierTotalUsd, 2),
        ];
    }
}

if (!function_exists('_train_poll_order_result')) {
    /** @deprecated Use _train_fetch_order_result_once() — single call only. */
    function _train_poll_order_result($db, string $mainOrderId, int $maxAttempts = 1, int $sleepMs = 0): ?array
    {
        unset($maxAttempts, $sleepMs);
        return _train_fetch_order_result_once($db, $mainOrderId);
    }
}

if (!function_exists('_train_order_ticketing_failed')) {
    function _train_order_ticketing_failed(array $data): bool
    {
        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        if ($failMsg === '') {
            return false;
        }
        if (_train_order_has_seats($data)) {
            return false;
        }
        $status = (int)($data['order_status'] ?? 0);
        return $status === 3 || $status === 0;
    }
}

if (!function_exists('_train_enrich_seat_labels')) {
    /** Add seat_class_label to each seat row (mutates array in place). */
    function _train_enrich_seat_labels(array &$seats): void
    {
        foreach ($seats as &$seat) {
            if (!is_array($seat)) {
                continue;
            }
            $code = strtoupper(trim((string)($seat['seat_class'] ?? $seat['seat_type'] ?? '')));
            $seat['seat_class_code']  = $code;
            $seat['seat_class_label'] = _train_seat_class_label($code);
            if (empty($seat['seat_name_english']) && empty($seat['seat_name'])) {
                $seat['seat_name_english'] = $seat['seat_class_label'];
            }
        }
        unset($seat);
    }
}

if (!function_exists('_train_normalize_passenger_country_code')) {
    /**
     * Resolve passenger_country_code from countries table iso3 column.
     * Booking form stores countries.iso (2-letter); supplier API expects iso3.
     */
    function _train_normalize_passenger_country_code(string $code, $db = null): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || $db === null) {
            return $code;
        }

        if (strlen($code) === 2) {
            $iso3 = $db->get('countries', 'iso3', ['iso' => $code]);
            return !empty($iso3) ? strtoupper((string)$iso3) : $code;
        }

        if (strlen($code) === 3) {
            $iso3 = $db->get('countries', 'iso3', ['iso3' => $code]);
            return !empty($iso3) ? strtoupper((string)$iso3) : $code;
        }

        return $code;
    }
}

if (!function_exists('_train_normalize_order_payload')) {
    /**
     * Normalize /ticket/order payload before sending to supplier.
     */
    function _train_normalize_order_payload(array $payload, $db = null): array
    {
        if (trim((string)($payload['callBackUrl'] ?? '')) === '') {
            $payload['callBackUrl'] = rtrim(root, '/') . '/ticket/offlinePush';
        }

        if (!empty($payload['journey']) && is_array($payload['journey'])) {
            foreach ($payload['journey'] as &$leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $leg['from_date_time'] = (int)($leg['from_date_time'] ?? 0);
                $leg['to_date_time']   = (int)($leg['to_date_time'] ?? 0);
                $leg['end_datetime']   = (int)($leg['end_datetime'] ?? 0);
                if ($leg['end_datetime'] <= 0 || ($leg['from_date_time'] > 0 && $leg['end_datetime'] < $leg['from_date_time'])) {
                    $leg['end_datetime'] = $leg['from_date_time'];
                }
                if (isset($leg['price_total_limit_original']) && (float)$leg['price_total_limit_original'] > 0) {
                    $leg['price_total_limit'] = round((float)$leg['price_total_limit_original'], 2);
                } else {
                    $leg['price_total_limit'] = round((float)($leg['price_total_limit'] ?? 0), 2);
                }
                unset($leg['price_total_limit_original']);
            }
            unset($leg);
        }

        $journeyType = 1;
        if (!empty($payload['journey']) && is_array($payload['journey'])) {
            $firstLeg = $payload['journey'][0] ?? [];
            if (is_array($firstLeg)) {
                $journeyType = (int)($firstLeg['journey_type'] ?? 1);
            }
        }

        if (!empty($payload['passengers']) && is_array($payload['passengers'])) {
            $payload['passengers'] = _train_finalize_order_passengers($journeyType, $payload['passengers']);

            foreach ($payload['passengers'] as &$p) {
                if (!is_array($p)) {
                    continue;
                }
                if (!_train_passenger_requires_traveler_details((int)($p['passenger_type'] ?? 1))) {
                    continue;
                }
                $p['passenger_first_name'] = strtoupper(trim((string)($p['passenger_first_name'] ?? '')));
                $p['passenger_last_name']  = strtoupper(trim((string)($p['passenger_last_name'] ?? '')));
                $p['passenger_card_no']    = strtoupper(trim((string)($p['passenger_card_no'] ?? '')));
                $p['passenger_card_type']  = strtoupper(trim((string)($p['passenger_card_type'] ?? '')));
                // Legacy UI sent "A" for ID card; supplier API uses "1".
                if ($p['passenger_card_type'] === 'A') {
                    $p['passenger_card_type'] = '1';
                }
                $p['passenger_sex_code']   = strtoupper(trim((string)($p['passenger_sex_code'] ?? 'M')));

                $cc = strtoupper(trim((string)($p['passenger_country_code'] ?? '')));
                $p['passenger_country_code'] = _train_normalize_passenger_country_code($cc, $db);
            }
            unset($p);
        }

        return $payload;
    }
}

if (!function_exists('_train_region_policies')) {
    /**
     * Booking rules per journey_type (1=China, 2=Laos–China, 3=Jakarta–Bandung).
     * Child metric in search URLs: age (years) for types 1 & 3, height (cm) for type 2.
     */
    function _train_region_policies(): array
    {
        return [
            1 => [
                'journey_type'            => 1,
                'label'                   => 'China Railway Tickets',
                'child_policy_mode'       => 'age',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Age 14+',
                'child_label'             => 'Children',
                'child_hint'              => 'Age 6–14',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 6 (free, no seat)',
                'show_child_category'     => true,
                'child_metric_label'      => 'Age',
                'child_metric_unit'       => 'years',
                'infant_metric_label'     => 'Age',
                'infant_metric_unit'      => 'years',
                'infant_metric_min'       => 0,
                'infant_metric_max'       => 5,
                'infant_metric_default'   => 3,
                'child_metric_min'        => 6,
                'child_metric_max'        => 14,
                'child_metric_default'    => 8,
                'free_max_age'            => 5,
                'child_max_age'           => 14,
                'adult_min_age'           => 14,
                'inquiry_timezone'        => 'UTC+8',
                'inquiry_timezone_offset' => 8,
                'operating_hours'         => [
                    'start'     => '08:00',
                    'end'       => '23:00',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'Beijing Time (UTC+8)',
                ],
                'online_refund'           => true,
                'online_reschedule'       => true,
                'id_must_be_genuine'      => true,
                'rules'                   => [
                    'The time zone of the timestamp for the booking inquiry is UTC+8.',
                    'Operating hours: 08:00–23:00 (Beijing Time, UTC+8).',
                    'Children under 6 years old travel free (no seat); children aged 6–14 purchase discounted child tickets; passengers aged 14 and above purchase full-fare tickets. A child should be accompanied by an adult.',
                    'Ticket issuance, refund, and rescheduling are supported.',
                    'Passenger name and ID number must be genuine.',
                ],
                'note'                    => 'Children under 6 travel free (no seat). Ages 6–14 use child tickets. Age 14+ is full fare. Each child must travel with an adult.',
            ],
            2 => [
                'journey_type'            => 2,
                'label'                   => 'Laos–China Railway Tickets',
                'child_policy_mode'       => 'height',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Height 1.5 m and above',
                'child_label'             => 'Children',
                'child_hint'              => '1.2–1.5 m',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 1.2 m',
                'show_child_category'     => true,
                'child_metric_label'      => 'Height',
                'child_metric_unit'       => 'cm',
                'infant_metric_label'     => 'Height',
                'infant_metric_unit'      => 'cm',
                'infant_metric_min'       => 80,
                'infant_metric_max'       => 115,
                'infant_metric_default'   => 100,
                'child_metric_min'        => 120,
                'child_metric_max'        => 145,
                'child_metric_default'    => 130,
                'free_max_height_cm'      => 119,
                'child_max_height_cm'     => 149,
                'adult_min_height_cm'     => 150,
                'inquiry_timezone'        => 'UTC+7',
                'inquiry_timezone_offset' => 7,
                'operating_hours'         => [
                    'start'     => '07:30',
                    'end'       => '23:30',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'Beijing Time (UTC+8)',
                ],
                'online_refund'           => true,
                'online_reschedule'       => true,
                'id_must_be_genuine'      => false,
                'rules'                   => [
                    'The time zone of the timestamp for the booking inquiry is UTC+7.',
                    'Operating hours: 07:30–23:30 (Beijing Time, UTC+8).',
                    'Child ticket rules: height under 1.2 m travels free (no seat); 1.2–1.5 m uses a discounted child ticket (half price); 1.5 m and above uses a full-fare ticket. A child should be accompanied by an adult.',
                    'Ticket issuance, refund, and rescheduling are supported.',
                ],
                'note'                    => 'Height under 1.2 m travels free (no seat). 1.2–1.5 m uses discounted child tickets. 1.5 m+ is full fare. Each child must travel with an adult.',
            ],
            3 => [
                'journey_type'            => 3,
                'label'                   => 'Jakarta–Bandung Railway Tickets',
                'child_policy_mode'       => 'infant_age',
                'adult_label'             => 'Adults',
                'adult_hint'              => 'Full fare ticket',
                'child_label'             => 'Children',
                'child_hint'              => '',
                'infant_label'            => 'Infants',
                'infant_hint'             => 'Under 3 years',
                'show_child_category'     => false,
                'infant_metric_label'     => 'Age',
                'infant_metric_unit'      => 'years',
                'infant_metric_min'       => 0,
                'infant_metric_max'       => 2,
                'infant_metric_default'   => 1,
                'child_metric_min'        => 0,
                'child_metric_max'        => 0,
                'child_metric_default'    => 0,
                'infant_max_age'          => 2,
                'infants_per_adult_free'  => 1,
                'inquiry_timezone'        => 'UTC+7',
                'inquiry_timezone_offset' => 7,
                'operating_hours'         => [
                    'start'     => '00:00',
                    'end'       => '24:00',
                    'timezone'  => 'Asia/Shanghai',
                    'label'     => 'China Time (UTC+8)',
                ],
                'online_refund'           => false,
                'online_reschedule'       => false,
                'id_must_be_genuine'      => false,
                'rules'                   => [
                    'The time zone of the timestamp for the booking inquiry is UTC+7.',
                    'Operating hours: 00:00–24:00 (China Time, UTC+8).',
                    'Accompanied infant policy: only one infant per adult may travel without a separate ticket; any additional accompanying infant(s) must be issued a ticket at the full adult fare. Infants must be under 3 years and added as passengers.',
                    'Online refunds and rescheduling are not supported — passengers shall request refunds/rescheduling at the train station.',
                ],
                'note'                    => 'One infant under 3 per adult travels without a separate ticket. Additional infants require a full adult-fare ticket. All infants must be added as passengers.',
            ],
        ];
    }
}

if (!function_exists('_train_region_policy')) {
    function _train_region_policy(int $journeyType = 1): array
    {
        $policies = _train_region_policies();
        return $policies[$journeyType] ?? $policies[1];
    }
}

if (!function_exists('_train_region_policies_for_api')) {
    function _train_region_policies_for_api(): array
    {
        $out = [];
        foreach (_train_region_policies() as $id => $policy) {
            $out[] = array_merge(['id' => $id], $policy);
        }
        return $out;
    }
}

if (!function_exists('_train_booking_journey_type')) {
    /** Resolve journey_type (1/2/3) from a bookings row's stored booking_data. */
    function _train_booking_journey_type(array $booking): int
    {
        $data = json_decode($booking['booking_data'] ?? '', true) ?: [];
        $journey = is_array($data['journey'][0] ?? null) ? $data['journey'][0] : [];
        return (int)($journey['journey_type'] ?? $data['journey_type'] ?? 1);
    }
}

if (!function_exists('_train_assert_online_action')) {
    /**
     * Gate a post-issuance action (cancel/refund/reschedule) against the region policy.
     * Jakarta–Bandung (journey_type 3) does not support online cancellation, refund, or
     * reschedule — passengers must request all of these in person at the train station.
     * Cancellation is bundled under the same "online_refund" policy flag as refund: an
     * online-only cancel with no way to get the fare back at the station doesn't make sense.
     *
     * @param  string $action 'cancel'|'refund'|'reschedule'
     * @return array{allowed:bool,message:string}
     */
    function _train_assert_online_action(int $journeyType, string $action): array
    {
        $policy = _train_region_policy($journeyType);
        $policyKey = $action === 'reschedule' ? 'online_reschedule' : 'online_refund';

        if (!empty($policy[$policyKey])) {
            return ['allowed' => true, 'message' => ''];
        }

        $actionLabels = [
            'reschedule' => 'Rescheduling',
            'cancel'     => 'Cancellation',
            'refund'     => 'Online refunds',
        ];
        $actionLabel = $actionLabels[$action] ?? 'This action';
        $regionLabel = (string)($policy['label'] ?? 'this route');
        $verb = $action === 'refund' ? 'are' : 'is';

        return [
            'allowed' => false,
            'message' => $actionLabel . ' ' . $verb . ' not supported online for ' . $regionLabel
                . '. Please contact the train station directly to request this.',
        ];
    }
}

if (!function_exists('_train_submit_reschedule')) {
    /**
     * Submit a reschedule (order change) request to the supplier (POST /ticket/orderChange).
     * Customer-facing only — the invoice page (app/routes/rail/bookingRoutes.php, /rail/reschedule)
     * is the only caller. There is no admin "Reschedule Request" action.
     *
     * Payload shape matches the supplier's documented /ticket/orderChange schema exactly
     * (cus_order_id, cus_change_id, traffic_no, from/to_station_code, from/to_date_time,
     * seat_class, choose_seat_must, price_total_limit, end_datetime, train_info, passengers,
     * call_back_url) — passengers use passenger_name (not first/last) plus passenger_mobile_no.
     *
     * @param  array $newLeg  {traffic_no, from_station_code, to_station_code, from_date_time,
     *                         to_date_time, seat_class, price_total_limit, end_datetime?}
     * @return array{status:bool,message:string,cus_change_id?:string,change_id?:mixed}
     */
    function _train_submit_reschedule($db, string $invoiceId, array $newLeg): array
    {
        if ($invoiceId === '') {
            return ['status' => false, 'message' => 'Invoice ID required'];
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            return ['status' => false, 'message' => 'Booking not found'];
        }

        if (empty($booking['pnr'])) {
            return ['status' => false, 'message' => 'Booking has not been issued with the supplier yet, nothing to reschedule'];
        }

        if (($booking['booking_status'] ?? '') === 'cancelled') {
            return ['status' => false, 'message' => 'This booking has already been cancelled'];
        }

        $journeyType = _train_booking_journey_type($booking);
        $gate = _train_assert_online_action($journeyType, 'reschedule');
        if (!$gate['allowed']) {
            return ['status' => false, 'message' => $gate['message']];
        }

        $trafficNo = trim((string)($newLeg['traffic_no'] ?? ''));
        $fromStationCode = strtoupper(trim((string)($newLeg['from_station_code'] ?? '')));
        $toStationCode = strtoupper(trim((string)($newLeg['to_station_code'] ?? '')));
        $fromDateTime = (int)($newLeg['from_date_time'] ?? 0);
        $toDateTime = (int)($newLeg['to_date_time'] ?? 0);
        $seatClass = strtoupper(trim((string)($newLeg['seat_class'] ?? '')));
        $priceTotalLimit = round((float)($newLeg['price_total_limit'] ?? 0), 2);
        $endDatetime = (int)($newLeg['end_datetime'] ?? $toDateTime);
        $chooseSeatMust = !empty($newLeg['choose_seat_must']) ? 1 : 0;

        if ($trafficNo === '' || $fromStationCode === '' || $toStationCode === '' || $fromDateTime <= 0 || $toDateTime <= 0 || $seatClass === '' || $priceTotalLimit <= 0) {
            return [
                'status'  => false,
                'message' => 'New train number, stations, departure/arrival time, seat class, and price are all required to request a reschedule',
            ];
        }

        $orderInput = json_decode($booking['booking_data'] ?? '', true);
        $journeyLeg = (is_array($orderInput) && !empty($orderInput['journey'][0])) ? $orderInput['journey'][0] : [];
        $cusOrderId = $journeyLeg['cus_order_id'] ?? '';

        if ($cusOrderId === '') {
            return ['status' => false, 'message' => 'cus_order_id missing from booking data, cannot request reschedule'];
        }

        $passengersSource = (is_array($orderInput) && !empty($orderInput['passengers'])) ? $orderInput['passengers'] : [];
        if (empty($passengersSource)) {
            return ['status' => false, 'message' => 'No passengers found in booking data, cannot request reschedule'];
        }

        $contactMobile = trim((string)($booking['phone'] ?? ''));
        $passengers = array_map(function ($p) use ($contactMobile) {
            $name = trim((string)($p['passenger_first_name'] ?? '') . ' ' . (string)($p['passenger_last_name'] ?? ''));
            return [
                'passenger_name'                => $name,
                'passenger_type'                => $p['passenger_type']       ?? 1,
                'passenger_card_type'            => $p['passenger_card_type'] ?? '',
                'passenger_card_no'              => $p['passenger_card_no']   ?? '',
                'passenger_sex_code'             => $p['passenger_sex_code']  ?? '',
                'passenger_birth_date'           => $p['passenger_birth_date'] ?? '',
                'passenger_country_code'         => $p['passenger_country_code'] ?? '',
                'passenger_card_validity'        => $p['passenger_card_validity'] ?? '',
                'passenger_card_start_validity'  => $p['passenger_card_start_validity'] ?? '',
                'passenger_mobile_no'            => $p['passenger_mobile_no'] ?? $contactMobile,
            ];
        }, $passengersSource);

        // Unique client-side reference for this change request, per the API's cus_* convention.
        $cusChangeId = 'CHANGE' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) . time();

        $changePayload = [
            'cus_order_id'      => $cusOrderId,
            'cus_change_id'     => $cusChangeId,
            'traffic_no'        => $trafficNo,
            'from_station_code' => $fromStationCode,
            'to_station_code'   => $toStationCode,
            'from_date_time'    => $fromDateTime,
            'to_date_time'      => $toDateTime,
            'seat_class'        => $seatClass,
            'choose_seat_must'  => $chooseSeatMust,
            'price_total_limit' => $priceTotalLimit,
            'end_datetime'      => $endDatetime,
            'train_info'        => [
                'accept_none_seat' => 0,
                'choose_seats'     => '',
                'choose_bunks'     => '',
                'quiet_seat'       => 0,
            ],
            'passengers'        => $passengers,
            'call_back_url'     => rtrim(root, '/') . '/ticket/offlinePush',
        ];

        $res = _train_request('/ticket/orderChange', $changePayload, ['db' => $db]);

        $bookingData = is_array($orderInput) ? $orderInput : [];

        if (!empty($res['ok'])) {
            // /ticket/orderChange's response body is just {"code":200,"msg":"success","data":{}} —
            // the supplier does not hand back its own change identifier here. The "change_id" that
            // /ticket/changeResultData later expects is simply the cus_change_id we generated above.
            $changeId = $cusChangeId;

            $bookingData['reschedule'] = [
                'cus_change_id'     => $cusChangeId,
                'change_id'         => $changeId,
                'requested_new_leg' => [
                    'traffic_no'        => $trafficNo,
                    'from_station_code' => $fromStationCode,
                    'to_station_code'   => $toStationCode,
                    'from_date_time'    => $fromDateTime,
                    'to_date_time'      => $toDateTime,
                    'seat_class'        => $seatClass,
                ],
                'status'            => 'requested',
                'supplier_response' => $res['data'],
                'requested_at'      => date('Y-m-d H:i:s'),
            ];

            $db->update('bookings', [
                'booking_data' => json_encode($bookingData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            return [
                'status'        => true,
                'cus_change_id' => $cusChangeId,
                'change_id'     => $changeId,
                'message'       => 'Reschedule request submitted to supplier successfully. Awaiting confirmation.',
            ];
        }

        $errPayload = is_array($res['data'] ?? null)
            ? _train_enrich_supplier_payload($res['data'])
            : [];
        $errMsg = _train_api_error_message(
            $errPayload['code'] ?? $errPayload['error_code'] ?? 0,
            (string)($errPayload['msg'] ?? $res['error'] ?? 'Reschedule request failed')
        );
        if ($errMsg === '' || !empty($errPayload['msg_en'])) {
            $errMsg = (string)($errPayload['msg_en'] ?? $errMsg);
        }
        $errPayload['response_error'] = $errMsg;

        $db->update('bookings', [
            'error_response' => json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        return [
            'status'         => false,
            'message'        => $errMsg,
            'response_error' => $errMsg,
        ];
    }
}

if (!function_exists('_train_find_invoice_by_change_id')) {
    /**
     * Resolve invoice_id for an inbound offlinePush reschedule webhook. change_id has no
     * dedicated indexed column (it's our own cus_change_id, stored inside booking_data.reschedule),
     * so this falls back to a LIKE match on the JSON blob — same as the rest of this codebase does
     * for ad-hoc JSON text search (see stations.php / destinationSuggestionRoutes.php).
     */
    function _train_find_invoice_by_change_id($db, string $changeId): string
    {
        // This endpoint is a public, unauthenticated webhook — unlike the exact-match pnr lookup
        // used for ticketing pushes, LIKE matching means a crafted change_id (e.g. containing
        // "%") could otherwise match unrelated bookings. Only accept our own generated format
        // (see the cus_change_id built in _train_submit_reschedule()).
        if (!preg_match('/^CHANGE[0-9A-F]{8}\d+$/', $changeId)) {
            return '';
        }

        $row = $db->get('bookings', ['invoice_id'], [
            'module_type'      => 'rail',
            'booking_data[~]'  => '"change_id":"' . $changeId . '"',
        ]);

        return is_array($row) ? (string)($row['invoice_id'] ?? '') : '';
    }
}

if (!function_exists('_train_reschedule_result_has_seats')) {
    /** True once the supplier has assigned a coach/seat on the new train (change confirmed). */
    function _train_reschedule_result_has_seats(array $data): bool
    {
        foreach (($data['passengers'] ?? []) as $p) {
            if (is_array($p) && (!empty($p['train_seat_no']) || !empty($p['train_coach_no']))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('_train_reschedule_change_status_label')) {
    /** Per the supplier's documented /ticket/changeResultData change_status enum. */
    function _train_reschedule_change_status_label(int $status): string
    {
        return [
            0 => 'Pending ticket issuance',
            1 => 'Issuing in progress',
            2 => 'Issued successfully',
            3 => 'Issuance failed',
        ][$status] ?? 'Unknown';
    }
}

if (!function_exists('_train_apply_reschedule_result')) {
    /**
     * Apply a /ticket/changeResultData response to the booking's stored reschedule state.
     * Request schema is {"change_id": "string"} (change_id = our own cus_change_id — see
     * _train_submit_reschedule(), since /ticket/orderChange's own response body is empty).
     *
     * Confirmed response schema:
     * {"code":0,"msg":"string","data":{"change_id","cus_order_id","sequence_no","change_status",
     *  "ticket_price_total","pay_price_total","resign_ticket_cost_total","resign_return_fact_total",
     *  "from_station_code","from_station_name_chinese","from_station_name_english","to_station_code",
     *  "to_station_name_chinese","to_station_name_english","traffic_no","from_date_time",
     *  "ticket_success_time","passengers":[{"passenger_name","passenger_type","passenger_card_type",
     *  "passenger_card_no","ticket_gate","seat_class","ticket_price","pay_price","resign_ticket_cost",
     *  "resign_return_fact","train_coach_no","train_seat_no","qr_code","cus_remark","ticket_no"}]}}
     *
     * change_status is documented: 0 = pending ticket issuance, 1 = issuing in progress,
     * 2 = issued successfully, 3 = issuance failed. _train_reschedule_result_has_seats() is
     * kept as a secondary confirmation signal (matches the original order-ticketing flow's
     * own convention) in case a supplier response ever omits change_status.
     *
     * @param  array $supplierPayload  Decoded top-level supplier response (code/msg/data)
     * @return array{action:string}
     */
    function _train_apply_reschedule_result($db, string $invoiceId, array $supplierPayload): array
    {
        if ($invoiceId === '') {
            return ['action' => 'skipped_empty_invoice_id'];
        }

        $booking = $db->get('bookings', ['booking_data', 'booking_response'], ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            return ['action' => 'booking_not_found'];
        }

        $bookingData = json_decode($booking['booking_data'] ?? '', true);
        if (!is_array($bookingData) || !is_array($bookingData['reschedule'] ?? null)) {
            return ['action' => 'no_reschedule_state'];
        }

        $data = is_array($supplierPayload['data'] ?? null) ? $supplierPayload['data'] : [];
        $changeStatus = isset($data['change_status']) ? (int)$data['change_status'] : null;

        $succeeded = $changeStatus === 2
            || _train_reschedule_result_has_seats($data)
            || (int)($data['ticket_success_time'] ?? 0) > 0;
        $failed = !$succeeded && (
            $changeStatus === 3
            || (($supplierPayload['code'] ?? null) !== null && !_train_is_api_success($supplierPayload['code']))
        );

        $action = 'pending';
        if ($succeeded) {
            $bookingData['reschedule']['status'] = 'confirmed';
            $action = 'confirmed';

            // The response carries the supplier's own confirmed leg — use it directly rather
            // than what we merely requested. Only overwrite fields the response actually filled in.
            if (!empty($bookingData['journey'][0]) && is_array($bookingData['journey'][0])) {
                foreach (['traffic_no', 'from_station_code', 'to_station_code', 'from_date_time'] as $field) {
                    if (!empty($data[$field])) {
                        $bookingData['journey'][0][$field] = $data[$field];
                    }
                }
            }

            // Feed the new coach/seat/ticket assignments into the same booking_response shape
            // the invoice/admin views already read (_train_extract_rsp_passengers), so the new
            // seats show up without any further UI changes.
            $bookingResponse = json_decode($booking['booking_response'] ?? '', true);
            $bookingResponse = is_array($bookingResponse) ? $bookingResponse : [];
            $bookingResponse['data']['journey'][0]['passengers'] = $data['passengers'] ?? [];
            $db->update('bookings', [
                'booking_response' => json_encode($bookingResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['invoice_id' => $invoiceId]);
        } elseif ($failed) {
            $bookingData['reschedule']['status'] = 'failed';
            $action = 'failed';
        }

        $bookingData['reschedule']['last_check_response'] = $supplierPayload;
        $bookingData['reschedule']['last_checked_at'] = date('Y-m-d H:i:s');
        if ($changeStatus !== null) {
            $bookingData['reschedule']['change_status'] = $changeStatus;
            $bookingData['reschedule']['change_status_label'] = _train_reschedule_change_status_label($changeStatus);
        }

        $db->update('bookings', [
            'booking_data' => json_encode($bookingData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        return ['action' => $action];
    }
}

if (!function_exists('_train_is_within_operating_hours')) {
    function _train_is_within_operating_hours(int $journeyType = 1, ?DateTimeInterface $at = null): bool
    {
        $hours = _train_region_policy($journeyType)['operating_hours'] ?? [];
        $start = (string)($hours['start'] ?? '00:00');
        $end = (string)($hours['end'] ?? '24:00');
        if ($start === '00:00' && $end === '24:00') {
            return true;
        }

        $tzName = (string)($hours['timezone'] ?? 'Asia/Shanghai');
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Shanghai');
        }

        $now = $at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($at)->setTimezone($tz)
            : new DateTimeImmutable('now', $tz);

        $day = $now->format('Y-m-d');
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day . ' ' . $start, $tz);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day . ' ' . $end, $tz);
        if (!$startDt || !$endDt) {
            return true;
        }

        return $now >= $startDt && $now <= $endDt;
    }
}

if (!function_exists('_train_inquiry_timestamp_from_date')) {
    /**
     * Build trainQuery from_date using the region inquiry timezone (+8 China, +7 LCR/Whoosh).
     */
    function _train_inquiry_timestamp_from_date($dateInput, int $journeyType = 1): int
    {
        if (is_numeric($dateInput)) {
            return (int)$dateInput;
        }

        $offset = (int)(_train_region_policy($journeyType)['inquiry_timezone_offset'] ?? 8);
        $sign = $offset >= 0 ? '+' : '-';
        try {
            $tz = new DateTimeZone(sprintf('%s%02d:00', $sign, abs($offset)));
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Shanghai');
        }

        $dateStr = trim((string)$dateInput);
        foreach (['d-m-Y', 'Y-m-d', 'd/m/Y'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $dateStr, $tz);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->setTime(0, 0, 0)->getTimestamp();
            }
        }

        return (int)strtotime($dateStr);
    }
}

if (!function_exists('_train_finalize_order_passengers')) {
    /**
     * Normalize passenger_type / passenger_age per region rules before storing or sending to supplier.
     */
    function _train_finalize_order_passengers(int $journeyType, array $passengers): array
    {
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');
        $freeInfantSlots = 0;

        foreach ($passengers as $p) {
            if (!is_array($p)) {
                continue;
            }
            if ((int)($p['passenger_type'] ?? 1) === 1) {
                $freeInfantSlots++;
            }
        }

        $out = [];
        foreach ($passengers as $p) {
            if (!is_array($p)) {
                continue;
            }

            $row = $p;
            unset($row['adult_fare_infant']);

            $age = null;
            if (isset($row['passenger_age']) && $row['passenger_age'] !== '' && $row['passenger_age'] !== null) {
                $age = (int)$row['passenger_age'];
                $row['passenger_age'] = $age;
            }

            $type = (int)($row['passenger_type'] ?? 1);

            if ($mode === 'infant_age' && $age !== null && $age <= (int)($policy['infant_max_age'] ?? 2)) {
                if ($freeInfantSlots > 0 && $type !== 1) {
                    $row['passenger_type'] = 3;
                    $freeInfantSlots--;
                } elseif ($freeInfantSlots <= 0) {
                    $row['passenger_type'] = 1;
                }
            } elseif ($age !== null && $type !== 1) {
                $row['passenger_type'] = _train_passenger_type_for_age($age, $journeyType);
            }

            if (!in_array((int)($row['passenger_type'] ?? 1), [2, 3], true)) {
                unset($row['passenger_age']);
            }

            $out[] = $row;
        }

        return $out;
    }
}

if (!function_exists('_train_validate_order_passengers')) {
    /**
     * Validate traveler details against region-specific rules before order storage / supplier call.
     *
     * @return array{valid:bool,message:string}
     */
    function _train_validate_order_passengers(int $journeyType, array $passengers): array
    {
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');

        if ($passengers === []) {
            return ['valid' => false, 'message' => 'At least one passenger is required'];
        }

        $adultCount = 0;
        $nonAdultCount = 0;
        $required = [
            'passenger_first_name',
            'passenger_last_name',
            'passenger_card_type',
            'passenger_card_no',
            'passenger_sex_code',
            'passenger_birth_date',
            'passenger_country_code',
            'passenger_card_validity',
        ];

        foreach ($passengers as $idx => $p) {
            if (!is_array($p)) {
                return ['valid' => false, 'message' => 'Invalid passenger data at position ' . ($idx + 1)];
            }

            $label = 'Passenger ' . ($idx + 1);

            $type = (int)($p['passenger_type'] ?? 1);

            if (!_train_passenger_requires_traveler_details($type)) {
                if (!isset($p['passenger_age']) || $p['passenger_age'] === '' || $p['passenger_age'] === null) {
                    $metricLabel = $mode === 'height' ? 'height (cm)' : 'age';
                    return ['valid' => false, 'message' => $label . ': ' . $metricLabel . ' is required for free infant'];
                }
                $nonAdultCount++;
                continue;
            }

            foreach ($required as $field) {
                if (trim((string)($p[$field] ?? '')) === '') {
                    return ['valid' => false, 'message' => $label . ': all traveler details are required'];
                }
            }

            if (preg_match('/[0-9]/', (string)($p['passenger_first_name'] ?? '')) || preg_match('/[0-9]/', (string)($p['passenger_last_name'] ?? ''))) {
                return ['valid' => false, 'message' => $label . ': name must contain letters only, no numbers'];
            }

            if (!empty($policy['id_must_be_genuine'])) {
                $cardNo = trim((string)($p['passenger_card_no'] ?? ''));
                if (strlen($cardNo) < 6 || strlen($cardNo) > 20) {
                    return ['valid' => false, 'message' => $label . ': a genuine ID number (6–20 characters) is required'];
                }
            }

            if ($type === 1) {
                $adultCount++;
            } else {
                $nonAdultCount++;
            }

            if (in_array($type, [2, 3], true)) {
                if (!isset($p['passenger_age']) || $p['passenger_age'] === '' || $p['passenger_age'] === null) {
                    $metricLabel = $mode === 'height' ? 'height (cm)' : 'age';
                    return ['valid' => false, 'message' => $label . ': ' . $metricLabel . ' is required for child/infant tickets'];
                }

                $age = (int)$p['passenger_age'];
                $expectedType = _train_passenger_type_for_age($age, $journeyType);

                if ($mode === 'infant_age' && $age <= (int)($policy['infant_max_age'] ?? 2) && $type === 1) {
                    // Jakarta: extra infant billed as adult fare — allowed.
                } elseif ($expectedType !== $type) {
                    return ['valid' => false, 'message' => $label . ': ticket type does not match the selected ' . ($mode === 'height' ? 'height' : 'age')];
                }
            }
        }

        if ($nonAdultCount > 0 && $adultCount < 1) {
            return ['valid' => false, 'message' => 'Each child or infant must be accompanied by at least one adult'];
        }

        return ['valid' => true, 'message' => ''];
    }
}

if (!function_exists('_train_prepare_train_query_input')) {
    /** Normalize trainQuery payload: inquiry timestamp and passenger_type_list. */
    function _train_prepare_train_query_input(array &$input): ?array
    {
        $journeyType = (int)($input['journey_type'] ?? 1);

        if (isset($input['from_date']) && $input['from_date'] !== '' && $input['from_date'] !== null) {
            $input['from_date'] = _train_inquiry_timestamp_from_date($input['from_date'], $journeyType);
        }

        $adults = max(0, (int)($input['adults'] ?? 0));
        $childrenCount = max(0, (int)($input['children'] ?? 0));
        $infantCount = max(0, (int)($input['infants'] ?? 0));

        if (is_array($input['child_ages'] ?? null) || is_array($input['infant_ages'] ?? null)) {
            $metrics = [
                'child_ages'  => _train_parse_category_metrics($input['child_ages'] ?? [], $childrenCount, $journeyType, 'child'),
                'infant_ages' => _train_parse_category_metrics($input['infant_ages'] ?? [], $infantCount, $journeyType, 'infant'),
            ];
        } else {
            $metrics = _train_parse_passenger_metrics_bundle(
                $input['passenger_metrics'] ?? $input['child_ages'] ?? $input['child_age'] ?? [],
                $childrenCount,
                $infantCount,
                $journeyType
            );
        }

        _train_apply_train_query_passengers($input, $adults, $metrics['child_ages'], $metrics['infant_ages']);

        return ['valid' => true, 'message' => ''];
    }
}

if (!function_exists('_train_extract_train_query_rows')) {
    /** Normalize supplier trainQuery rows from decoded API body or handler payload. */
    function _train_extract_train_query_rows($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $candidates = [
            $payload['data']['data']['data'] ?? null,
            $payload['data']['data'] ?? null,
            $payload['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ($candidate === []) {
                continue;
            }
            if (array_is_list($candidate)) {
                return $candidate;
            }
            if (isset($candidate['data']) && is_array($candidate['data']) && array_is_list($candidate['data'])) {
                return $candidate['data'];
            }
        }

        return [];
    }
}

if (!function_exists('_train_child_age_policy')) {
    /** Supplier child fare rules (trainQuery + /ticket/order) for the given journey_type. */
    function _train_child_age_policy(int $journeyType = 1): array
    {
        $policy = _train_region_policy($journeyType);
        $out = [
            'journey_type'       => (int)$policy['journey_type'],
            'child_policy_mode'  => (string)($policy['child_policy_mode'] ?? 'age'),
            'note'               => (string)($policy['note'] ?? ''),
            'rules'              => $policy['rules'] ?? [],
        ];

        if (($policy['child_policy_mode'] ?? '') === 'height') {
            $out['free_max_height_cm']  = (int)($policy['free_max_height_cm'] ?? 119);
            $out['child_max_height_cm'] = (int)($policy['child_max_height_cm'] ?? 149);
            $out['adult_min_height_cm'] = (int)($policy['adult_min_height_cm'] ?? 150);
            return $out;
        }

        if (($policy['child_policy_mode'] ?? '') === 'infant_age') {
            $out['infant_max_age']         = (int)($policy['infant_max_age'] ?? 2);
            $out['infants_per_adult_free'] = (int)($policy['infants_per_adult_free'] ?? 1);
            return $out;
        }

        $out['free_max_age']  = (int)($policy['free_max_age'] ?? 5);
        $out['child_max_age'] = (int)($policy['child_max_age'] ?? 13);
        $out['adult_min_age'] = (int)($policy['adult_min_age'] ?? 14);
        return $out;
    }
}

if (!function_exists('_train_parse_category_metrics')) {
    /**
     * Parse age/height values for a passenger category (child or infant).
     *
     * @return int[]
     */
    function _train_parse_category_metrics($raw, int $expectedCount, int $journeyType, string $category = 'child'): array
    {
        $policy = _train_region_policy($journeyType);
        $prefix = $category === 'infant' ? 'infant_metric_' : 'child_metric_';
        $min = (int)($policy[$prefix . 'min'] ?? 0);
        $max = (int)($policy[$prefix . 'max'] ?? 17);
        $default = (int)($policy[$prefix . 'default'] ?? 6);

        if (is_array($raw)) {
            $values = array_map('intval', $raw);
        } elseif ($raw === null || $raw === '' || $raw === '0') {
            $values = [];
        } elseif (is_string($raw) && str_contains($raw, ',')) {
            $values = array_map('intval', explode(',', $raw));
        } else {
            $values = array_map('intval', explode('-', (string)$raw));
        }

        $values = array_values(array_filter(
            $values,
            static fn($value) => $value >= $min && $value <= $max
        ));

        if ($expectedCount <= 0) {
            return [];
        }

        if (count($values) > $expectedCount) {
            $values = array_slice($values, 0, $expectedCount);
        }
        while (count($values) < $expectedCount) {
            $values[] = $default;
        }

        return $values;
    }
}

if (!function_exists('_train_parse_passenger_metrics_bundle')) {
    /**
     * Parse combined metrics string: "6-8~1-2" (child ages ~ infant ages).
     *
     * @return array{child_ages:int[],infant_ages:int[]}
     */
    function _train_parse_passenger_metrics_bundle($raw, int $childCount, int $infantCount, int $journeyType = 1): array
    {
        if (is_array($raw)) {
            return [
                'child_ages'  => _train_parse_category_metrics($raw, $childCount, $journeyType, 'child'),
                'infant_ages' => [],
            ];
        }

        $raw = (string)($raw ?? '0');
        $childPart = $raw;
        $infantPart = '';

        if (str_contains($raw, '~')) {
            [$childPart, $infantPart] = explode('~', $raw, 2);
        } elseif ($infantCount <= 0 && $childCount > 0) {
            $childPart = $raw;
        } elseif ($infantCount > 0 && $childCount <= 0) {
            $infantPart = $raw;
            $childPart = '0';
        }

        return [
            'child_ages'  => _train_parse_category_metrics($childPart, $childCount, $journeyType, 'child'),
            'infant_ages' => _train_parse_category_metrics($infantPart, $infantCount, $journeyType, 'infant'),
        ];
    }
}

if (!function_exists('_train_encode_passenger_metrics')) {
    function _train_encode_passenger_metrics(array $childAges, array $infantAges): string
    {
        $childPart = _train_encode_child_ages($childAges);
        $infantPart = _train_encode_child_ages($infantAges);
        if ($childPart === '0' && $infantPart === '0') {
            return '0';
        }
        if ($infantPart === '0') {
            return $childPart;
        }
        if ($childPart === '0') {
            return '~' . $infantPart;
        }
        return $childPart . '~' . $infantPart;
    }
}

if (!function_exists('_train_parse_child_ages')) {
    /**
     * Parse child age/height values from URL, session, or API input.
     *
     * @param  mixed $raw  "4-8-10", comma-separated, array, or empty
     * @return int[]
     */
    function _train_parse_child_ages($raw, int $expectedCount = 0, int $journeyType = 1): array
    {
        return _train_parse_category_metrics($raw, $expectedCount, $journeyType, 'child');
    }
}

if (!function_exists('_train_encode_child_ages')) {
    function _train_encode_child_ages(array $ages): string
    {
        if ($ages === []) {
            return '0';
        }
        return implode('-', array_map('intval', $ages));
    }
}

if (!function_exists('_train_passenger_type_for_age')) {
    /** Map age or height (cm) to supplier passenger_type: 1=Adult, 2=Child, 3=Free/no seat or infant. */
    function _train_passenger_type_for_age(int $value, int $journeyType = 1): int
    {
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');

        if ($mode === 'height') {
            if ($value <= (int)($policy['free_max_height_cm'] ?? 119)) {
                return 3;
            }
            if ($value < (int)($policy['adult_min_height_cm'] ?? 150)) {
                return 2;
            }
            return 1;
        }

        if ($mode === 'infant_age') {
            if ($value <= (int)($policy['infant_max_age'] ?? 2)) {
                return 3;
            }
            return 1;
        }

        if ($value <= (int)($policy['free_max_age'] ?? 5)) {
            return 3;
        }
        if ($value < (int)($policy['adult_min_age'] ?? 14)) {
            return 2;
        }
        return 1;
    }
}

if (!function_exists('_train_build_train_query_passenger_list')) {
    /**
     * Build supplier trainQuery passenger_type_list from adults + children + infants.
     *
     * @return array<int,array{passenger_type:int,passenger_num:int,passenger_age?:int}>
     */
    function _train_build_train_query_passenger_list(int $adults, array $childAges, int $journeyType = 1, array $infantAges = []): array
    {
        $adults = max(0, $adults);
        $journeyType = in_array($journeyType, [1, 2, 3], true) ? $journeyType : 1;
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');

        $list = [];
        if ($adults > 0) {
            $list[] = ['passenger_type' => 1, 'passenger_num' => $adults];
        }

        if ($mode === 'infant_age') {
            $freeInfantSlots = max(0, $adults);
            foreach ($infantAges as $age) {
                $age = max(0, min((int)($policy['infant_max_age'] ?? 2), (int)$age));
                if ($freeInfantSlots > 0) {
                    $list[] = ['passenger_type' => 3, 'passenger_num' => 1, 'passenger_age' => $age];
                    $freeInfantSlots--;
                    continue;
                }
                $list[] = ['passenger_type' => 1, 'passenger_num' => 1, 'passenger_age' => $age];
            }
            return $list;
        }

        foreach ($infantAges as $metric) {
            $metric = max(0, (int)$metric);
            $list[] = ['passenger_type' => 3, 'passenger_num' => 1, 'passenger_age' => $metric];
        }

        foreach ($childAges as $metric) {
            $metric = max(0, (int)$metric);
            $list[] = ['passenger_type' => 2, 'passenger_num' => 1, 'passenger_age' => $metric];
        }

        return $list;
    }
}

if (!function_exists('_train_apply_train_query_passengers')) {
    /** Merge passenger_type_list into a trainQuery payload when passengers are specified. */
    function _train_apply_train_query_passengers(array &$payload, int $adults = 0, array $childAges = [], array $infantAges = []): void
    {
        if (!empty($payload['passenger_type_list']) && is_array($payload['passenger_type_list'])) {
            return;
        }

        $journeyType = (int)($payload['journey_type'] ?? 1);
        $adults = max(0, (int)($payload['adults'] ?? $adults));
        $childrenCount = max(0, (int)($payload['children'] ?? 0));
        $infantCount = max(0, (int)($payload['infants'] ?? 0));

        if ($childAges === [] && $infantAges === []) {
            $metricsRaw = $payload['passenger_metrics'] ?? $payload['child_ages'] ?? $payload['child_age'] ?? [];
            // Only strings can use the bundled "child~infant" format; arrays (e.g. child_ages: [8, 3])
            // are already structured per-category data and must skip straight to the age-list branch —
            // casting an array to string here would trigger an "Array to string conversion" error.
            $isBundledString = is_string($metricsRaw) && str_contains($metricsRaw, '~');
            if ($infantCount > 0 || $isBundledString) {
                $bundle = _train_parse_passenger_metrics_bundle($metricsRaw, $childrenCount, $infantCount, $journeyType);
                $childAges = $bundle['child_ages'];
                $infantAges = $bundle['infant_ages'];
            } else {
                $childAges = $childrenCount > 0
                    ? _train_parse_category_metrics($metricsRaw, $childrenCount, $journeyType, 'child')
                    : [];
            }
        }

        if ($adults < 1 && $childAges === [] && $infantAges === []) {
            $adults = 1;
        }

        $list = _train_build_train_query_passenger_list($adults, $childAges, $journeyType, $infantAges);
        if ($list !== []) {
            $payload['passenger_type_list'] = $list;
        }
    }
}

if (!function_exists('_train_journey_types')) {
    /**
     * Supplier journey_type codes (trainQuery + /ticket/order).
     * 1 = China Railway, 2 = Laos–China, 3 = Jakarta–Bandung (Whoosh).
     */
    function _train_journey_types(): array
    {
        return [
            1 => [
                'label'   => 'China Railway Ticket',
                'short'   => 'China Railway',
                'country' => 'China',
            ],
            2 => [
                'label'   => 'Laos–China Railway Ticket',
                'short'   => 'Laos–China',
                'country' => 'Laos / China',
            ],
            3 => [
                'label'   => 'Jakarta–Bandung Railway Ticket',
                'short'   => 'Jakarta–Bandung',
                'country' => 'Indonesia',
            ],
        ];
    }
}

if (!function_exists('_train_journey_type_label')) {
    function _train_journey_type_label(int $journeyType, bool $short = false): string
    {
        $types = _train_journey_types();
        if (!isset($types[$journeyType])) {
            return $short ? 'Jakarta–Bandung' : 'Jakarta–Bandung Railway Ticket';
        }
        return $short ? $types[$journeyType]['short'] : $types[$journeyType]['label'];
    }
}

if (!function_exists('_train_journey_types_for_api')) {
    function _train_journey_types_for_api(): array
    {
        $out = [];
        foreach (_train_journey_types() as $id => $meta) {
            $out[] = [
                'id'      => $id,
                'label'   => $meta['label'],
                'short'   => $meta['short'],
                'country' => $meta['country'],
            ];
        }
        return $out;
    }
}

if (!function_exists('_train_passenger_card_types')) {
    /**
     * Supplier passenger_card_type codes (order/issue API).
     * @see ID Document Type Enumeration in supplier API docs.
     */
    function _train_passenger_card_types(): array
    {
        return [
            '1' => 'PRC Resident ID / HK-Macao-Taiwan Residence Permit',
            'B' => 'Passport',
            'H' => 'Foreigner Permanent Residence ID',
            'C' => 'Mainland Travel Permit — Hong Kong & Macao',
            'G' => 'Mainland Travel Permit — Taiwan',
        ];
    }
}

if (!function_exists('_train_passenger_card_type_meta')) {
    /** Digit-length hints from supplier ID Document Type Enumeration. */
    function _train_passenger_card_type_meta(): array
    {
        return [
            '1' => ['digits' => 18, 'note' => 'China: PRC ID. Laos–China: Laos ID — set passenger_country_code to LAO.'],
            'B' => ['digits' => 9,  'note' => 'Passport number.'],
            'H' => ['digits' => 18, 'note' => 'Foreigner permanent residence ID.'],
            'C' => ['digits' => 9,  'note' => 'Hong Kong / Macao travel permit.'],
            'G' => ['digits' => 8,  'note' => 'Taiwan travel permit.'],
        ];
    }
}

if (!function_exists('_train_passenger_card_types_for_journey')) {
    /** Document types allowed/recommended per journey_type (1=China, 2=Laos–China, 3=Jakarta–Bandung). */
    function _train_passenger_card_types_for_journey(int $journeyType): array
    {
        $all = _train_passenger_card_types();

        if ($journeyType === 3) {
            return ['B' => $all['B']];
        }

        if ($journeyType === 2) {
            return [
                '1' => 'Laos ID / PRC Resident ID',
                'B' => $all['B'],
                'C' => $all['C'],
                'G' => $all['G'],
                'H' => $all['H'],
            ];
        }

        return $all;
    }
}

if (!function_exists('_train_passenger_card_type_label')) {
    function _train_passenger_card_type_label(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        return _train_passenger_card_types()[$code] ?? $code;
    }
}

if (!function_exists('_train_passenger_card_types_for_api')) {
    function _train_passenger_card_types_for_api(?int $journeyType = null): array
    {
        $types = $journeyType !== null
            ? _train_passenger_card_types_for_journey($journeyType)
            : _train_passenger_card_types();
        $meta = _train_passenger_card_type_meta();
        $out = [];
        foreach ($types as $code => $label) {
            $m = $meta[$code] ?? [];
            $out[] = [
                'code'    => $code,
                'label'   => $label,
                'digits'  => $m['digits'] ?? null,
                'note'    => $m['note'] ?? '',
            ];
        }
        return $out;
    }
}

if (!function_exists('_train_enrich_supplier_passenger')) {
    /**
     * Map orderResultData passenger row to display-friendly seat/coach/class fields.
     *
     * @return array<string,mixed>
     */
    function _train_enrich_supplier_passenger(array $passenger, string $failMsg = ''): array
    {
        $seatCode = strtoupper(trim((string)($passenger['seat_class'] ?? '')));
        $coach    = trim((string)($passenger['train_coach_no'] ?? ''));
        $seatNo   = trim((string)($passenger['train_seat_no'] ?? ''));
        $hasSeat  = $coach !== '' || $seatNo !== '';

        $passenger['seat_class_code']  = $seatCode;
        $passenger['seat_class_label'] = _train_seat_class_label($seatCode);
        $passenger['passenger_card_type_label'] = _train_passenger_card_type_label((string)($passenger['passenger_card_type'] ?? ''));
        $passenger['has_assigned_seat'] = $hasSeat;

        $displayParts = [];
        if ($coach !== '') {
            $displayParts[] = 'Coach ' . $coach;
        }
        if ($seatNo !== '') {
            $displayParts[] = 'Seat ' . $seatNo;
        }
        if ($seatCode !== '') {
            $displayParts[] = $passenger['seat_class_label'];
        }
        $passenger['seat_display'] = implode(' · ', $displayParts);

        if ($hasSeat) {
            $passenger['seat_status'] = 'assigned';
            $passenger['seat_status_message'] = $passenger['seat_display'];
        } elseif ($failMsg !== '') {
            $passenger['seat_status'] = 'failed';
            $passenger['seat_status_message'] = _train_human_fail_message($failMsg);
        } else {
            $passenger['seat_status'] = 'pending';
            $passenger['seat_status_message'] = 'Seat allocation pending...';
        }

        return $passenger;
    }
}

if (!function_exists('_train_extract_rsp_passengers')) {
    /** Pull passenger rows from a stored booking_response JSON object. */
    function _train_extract_rsp_passengers(array $bookingResponse): array
    {
        $passengers = $bookingResponse['data']['journey'][0]['passengers']
            ?? $bookingResponse['journey'][0]['passengers']
            ?? $bookingResponse['poll']['journey'][0]['passengers']
            ?? [];
        return is_array($passengers) ? $passengers : [];
    }
}

if (!function_exists('_train_merge_passenger_seats')) {
    /**
     * Merge local travellers with orderResultData passengers (matched by passenger_card_no).
     *
     * @return list<array{traveller:array,supplier:array,name:string}>
     */
    function _train_merge_passenger_seats(array $travellers, array $rspPassengers, string $failMsg = ''): array
    {
        $byCard = [];
        foreach ($rspPassengers as $rp) {
            if (!is_array($rp)) {
                continue;
            }
            $cardNo = trim((string)($rp['passenger_card_no'] ?? ''));
            if ($cardNo !== '') {
                $byCard[$cardNo] = $rp;
            }
        }

        $rows = [];
        foreach ($travellers as $idx => $traveller) {
            if (!is_array($traveller)) {
                continue;
            }
            $cardNo = trim((string)($traveller['passenger_card_no'] ?? ''));
            $rawRsp = ($cardNo !== '' && isset($byCard[$cardNo]))
                ? $byCard[$cardNo]
                : (is_array($rspPassengers[$idx] ?? null) ? $rspPassengers[$idx] : []);

            $rows[] = [
                'traveller' => $traveller,
                'supplier'  => _train_enrich_supplier_passenger($rawRsp, $failMsg),
                'name'      => trim((string)($traveller['passenger_first_name'] ?? '') . ' ' . ($traveller['passenger_last_name'] ?? '')),
            ];
        }

        return $rows;
    }
}

if (!function_exists('_train_enrich_order_result_data')) {
    /** Enrich full orderResultData payload for API/mobile display. */
    function _train_enrich_order_result_data(array $data): array
    {
        $failMsg = trim((string)($data['fail_msg'] ?? ''));
        $data['fail_msg_en'] = $failMsg !== '' ? _train_human_fail_message($failMsg) : '';
        $data['has_assigned_seats'] = _train_order_has_seats($data);
        $data['order_status_label'] = match ((int)($data['order_status'] ?? 0)) {
            2       => 'Ticketed',
            3       => 'Failed',
            default => 'Pending',
        };

        if (!empty($data['journey']) && is_array($data['journey'])) {
            foreach ($data['journey'] as &$leg) {
                if (empty($leg['passengers']) || !is_array($leg['passengers'])) {
                    continue;
                }
                foreach ($leg['passengers'] as &$p) {
                    if (is_array($p)) {
                        $p = _train_enrich_supplier_passenger($p, $failMsg);
                    }
                }
                unset($p);
            }
            unset($leg);
        }

        return $data;
    }
}

if (!function_exists('_train_format_supplier_date')) {
    /** Format supplier Ymd date strings for admin/invoice display. */
    function _train_format_supplier_date($raw): string
    {
        $digits = preg_replace('/\D/', '', (string)$raw);
        if (strlen($digits) !== 8) {
            return trim((string)$raw);
        }
        $dt = DateTimeImmutable::createFromFormat('Ymd', $digits);
        return $dt instanceof DateTimeImmutable ? $dt->format('d M Y') : $digits;
    }
}

if (!function_exists('_train_format_supplier_datetime')) {
    function _train_format_supplier_datetime($timestamp): string
    {
        $ts = (int)$timestamp;
        if ($ts <= 0) {
            return '';
        }
        return date('d M Y H:i', $ts);
    }
}

if (!function_exists('_train_passenger_type_label')) {
    function _train_passenger_type_label(int $type): string
    {
        return match ($type) {
            2       => 'Child',
            3       => 'Free infant',
            default => 'Adult',
        };
    }
}

if (!function_exists('_train_admin_booking_view_model')) {
    /**
     * Normalize rail booking rows for admin edit / invoice tooling.
     *
     * @return array<string,mixed>
     */
    function _train_admin_booking_view_model(array $booking, $db = null): array
    {
        if (!function_exists('_train_station_label')) {
            global $SECURE;
            require_once __DIR__ . '/stations.php';
        }

        $bookingData = json_decode($booking['booking_data'] ?? '', true) ?: [];
        $travellers = json_decode($booking['travellers'] ?? '', true) ?: [];
        if ((!is_array($travellers) || !array_is_list($travellers)) && !empty($bookingData['passengers']) && is_array($bookingData['passengers'])) {
            $travellers = array_values($bookingData['passengers']);
        }
        if (!is_array($travellers) || !array_is_list($travellers)) {
            $travellers = [];
        }

        $journey = is_array($bookingData['journey'][0] ?? null) ? $bookingData['journey'][0] : [];
        $journeyType = (int)($journey['journey_type'] ?? 1);
        $policy = _train_region_policy($journeyType);
        $mode = (string)($policy['child_policy_mode'] ?? 'age');
        $metricLabel = $mode === 'height' ? 'Height (cm)' : 'Age';

        $bookingResponse = json_decode($booking['booking_response'] ?? '', true) ?: [];
        $rspPassengers = _train_extract_rsp_passengers($bookingResponse);
        $failMsg = trim((string)($bookingResponse['fail_msg'] ?? $bookingResponse['data']['fail_msg'] ?? ''));
        $merged = _train_merge_passenger_seats($travellers, $rspPassengers, $failMsg);

        $rows = [];
        $freeCount = 0;
        $billableCount = 0;
        $adultCounter = 0;
        $childCounter = 0;
        $infantCounter = 0;

        foreach ($merged as $item) {
            $traveller = is_array($item['traveller'] ?? null) ? $item['traveller'] : [];
            $supplier = is_array($item['supplier'] ?? null) ? $item['supplier'] : [];
            $type = (int)($traveller['passenger_type'] ?? 1);
            $requiresDetails = _train_passenger_requires_traveler_details($type);

            if ($requiresDetails) {
                $billableCount++;
            } else {
                $freeCount++;
            }

            if ($type === 1) {
                $badge = 'bg-green-100 text-green-700';
                $heading = 'Adult ' . (++$adultCounter);
            } elseif ($type === 3) {
                $badge = 'bg-emerald-100 text-emerald-800';
                $heading = 'Free infant ' . (++$infantCounter);
            } else {
                $badge = 'bg-orange-100 text-orange-700';
                $heading = 'Child ' . (++$childCounter);
            }

            $rows[] = [
                'requires_details'    => $requiresDetails,
                'type'                => $type,
                'type_label'          => _train_passenger_type_label($type),
                'badge_class'         => $badge,
                'heading'             => $heading,
                'name'                => trim((string)($traveller['passenger_first_name'] ?? '') . ' ' . ($traveller['passenger_last_name'] ?? '')),
                'gender'              => strtoupper(trim((string)($traveller['passenger_sex_code'] ?? ''))),
                'birth_date'          => _train_format_supplier_date($traveller['passenger_birth_date'] ?? ''),
                'country'             => strtoupper(trim((string)($traveller['passenger_country_code'] ?? ''))),
                'doc_type'            => _train_passenger_card_type_label((string)($traveller['passenger_card_type'] ?? '')),
                'doc_no'              => trim((string)($traveller['passenger_card_no'] ?? '')),
                'doc_expiry'          => _train_format_supplier_date($traveller['passenger_card_validity'] ?? ''),
                'metric'              => isset($traveller['passenger_age']) ? (int)$traveller['passenger_age'] : null,
                'metric_label'        => $metricLabel,
                'seat_display'        => trim((string)($supplier['seat_display'] ?? '')),
                'seat_status'         => trim((string)($supplier['seat_status'] ?? '')),
                'seat_status_message' => trim((string)($supplier['seat_status_message'] ?? '')),
            ];
        }

        $fromCode = strtoupper(trim((string)($journey['from_station_code'] ?? '')));
        $toCode = strtoupper(trim((string)($journey['to_station_code'] ?? '')));
        $fromName = trim((string)($journey['from_station_name'] ?? ''));
        $toName = trim((string)($journey['to_station_name'] ?? ''));
        if ($fromName === '' && $fromCode !== '' && $db !== null) {
            $fromName = _train_station_label($db, $fromCode);
        }
        if ($toName === '' && $toCode !== '' && $db !== null) {
            $toName = _train_station_label($db, $toCode);
        }

        return [
            'journey'           => $journey,
            'journey_type'      => $journeyType,
            'region_label'      => _train_journey_type_label($journeyType, true),
            'from_code'         => $fromCode,
            'to_code'           => $toCode,
            'from_name'         => $fromName !== '' ? $fromName : $fromCode,
            'to_name'           => $toName !== '' ? $toName : $toCode,
            'train_no'          => trim((string)($journey['traffic_no'] ?? '')),
            'seat_class'        => _train_seat_class_label((string)($journey['seat_class'] ?? ''), $db),
            'departure_at'      => _train_format_supplier_datetime($journey['from_date_time'] ?? 0),
            'arrival_at'        => _train_format_supplier_datetime($journey['to_date_time'] ?? 0),
            'passengers'        => $rows,
            'free_infant_count' => $freeCount,
            'billable_count'    => $billableCount,
            'cus_main_order_id' => trim((string)($bookingData['cus_main_order_id'] ?? '')),
            'online_refund'     => !empty($policy['online_refund']),
            'contact_name'      => trim((string)($booking['first_name'] ?? '') . ' ' . (string)($booking['last_name'] ?? '')),
            'contact_email'     => trim((string)($booking['email'] ?? '')),
            'contact_phone'     => trim((string)($booking['phone'] ?? '')),
        ];
    }
}
