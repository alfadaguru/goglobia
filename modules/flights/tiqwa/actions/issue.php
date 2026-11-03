<?php
// path: modules/flights/tiqwa/actions/issue.php
// ISSUE ACTION — Tiqwa Flights.
// Called after payment (the payment gateway loops back here with a signed
// internal token). Tiqwa SEPARATES reserve from pay, so this does THREE Tiqwa
// calls in sequence, all post-payment:
//   1. GET  /confirm_price/{flight_id}  — re-validate + get the (possibly NEW)
//      confirmed flight_id + fresh amount + document_required (reconcile here).
//   2. POST /book/{flight_id}           — RESERVE → returns a booking `reference`
//      (TW-...) + expires_at. Persist the reference IMMEDIATELY so a retried
//      callback never reserves twice.
//   3. POST /pay/{reference}            — ISSUE the ticket from the TICKET wallet.
//
// KEY FACTS (docs/research/tiqwa):
//   • /pay returns ONLY { message } — the real PNR + ticket number arrive
//     ASYNCHRONOUSLY via the `ticket.issued` webhook (or a poll of
//     GET /{booking_reference}). So on a successful /pay we mark the booking
//     PAID + status 'pending' with pnr='' and the stored supplier reference; the
//     webhook/poll (utilities.php + app/routes/flights/tiqwaWebhookRoutes.php)
//     fills pnr later. We return status:true so the gateway does NOT auto-refund.
//   • /pay 403 = the platform's TICKET wallet is underfunded → we return
//     status:false; the payment gateway then holds the booking 'pending' and
//     AUTO-REFUNDS the customer (owner decision B). Never a false ticket.
//   • Markup is OURS (search applies MARKUP); we never send a Tiqwa-side markup.
@$SECURE or die('Access Denied!');

if (!function_exists('tiqwa_pax_type')) {
    function tiqwa_pax_type(string $paxKey): string
    {
        if (strpos($paxKey, 'child_') === 0)  return 'child';
        if (strpos($paxKey, 'infant_') === 0) return 'infant';
        return 'adult';
    }
}
if (!function_exists('tiqwa_iso2')) {
    /** Resolve a 2-letter ISO country from a code / phone code / name. */
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

$router->post('flights/tiqwa/issue', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        // 1. Authorize the caller (gateway loopback / admin / CSRF). Anonymous → 403.
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // 2. Idempotency: already ticketed (pnr present) → return it, never re-pay.
        if (!empty($booking['pnr'])) {
            echo json_encode([
                'status' => true, 'message' => 'Booking already issued',
                'pnr' => $booking['pnr'], 'booking_reference' => $booking['pnr'],
                'invoice_id' => $invoice_id, 'already_issued' => true,
            ]);
            exit;
        }
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => false, 'message' => 'Booking is ' . $booking['booking_status'] . ' and cannot be issued.']);
            exit;
        }

        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];

        // 2b. Reserve-exists idempotency: if we already have a Tiqwa reference for
        // this invoice, the reserve (and possibly the pay) already happened — do NOT
        // re-book. Try to advance to pay if not yet paid; else report it's in flight.
        $existingRef = trim((string) ($booking_data['supplier_ref'] ?? $booking_data['tiqwa_reference'] ?? ''));

        // 3. Concurrency claim on issue_claimed_at (NOT booking_status, an ENUM).
        try { $db->query("ALTER TABLE `bookings` ADD COLUMN `issue_claimed_at` datetime DEFAULT NULL"); } catch (\Throwable $e) {}
        $claim = $db->query(
            "UPDATE bookings SET issue_claimed_at = NOW()
             WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')
               AND booking_status NOT IN ('confirmed','cancelled')
               AND (issue_claimed_at IS NULL OR issue_claimed_at < (NOW() - INTERVAL 10 MINUTE))",
            [':inv' => $invoice_id]
        );
        if (!$claim || $claim->rowCount() < 1) {
            echo json_encode(['status' => false, 'message' => 'This booking is already being issued or has been issued.']);
            exit;
        }
        register_shutdown_function(function () use ($db, $invoice_id) {
            try {
                $b = $db->get('bookings', ['pnr'], ['invoice_id' => $invoice_id]);
                if ($b && empty($b['pnr'])) {
                    $db->query("UPDATE bookings SET issue_claimed_at = NULL WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')", [':inv' => $invoice_id]);
                }
            } catch (\Throwable $e) { error_log('tiqwa issue claim-release: ' . $e->getMessage()); }
        });

        $module = getTiqwaModule($db);
        $credentials = getTiqwaCredentials($db);
        if (!$module || !$credentials || $credentials['api_token'] === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa module/credentials not configured']);
            exit;
        }
        $token = $credentials['api_token'];
        $base  = $credentials['flight_base'];

        // 4. Resolve the chosen offer's flight_id from booking_data.
        $fd = is_array($booking_data['flight_data'] ?? null) ? $booking_data['flight_data'] : [];
        $flightId = trim((string) (
            $booking_data['flight_id'] ?? $booking_data['offer_id'] ?? $booking_data['booking_token'] ?? ''
        ));
        if ($flightId === '') {
            // Dig out of a stored leg group (segments[0][0].booking_data).
            $segs = is_array($fd['segments'] ?? null) ? $fd['segments'] : (is_array($booking_data['segments'] ?? null) ? $booking_data['segments'] : []);
            $first = $segs[0][0] ?? null;
            if (is_object($first)) $first = (array) $first;
            if (is_array($first) && isset($first['booking_data'])) {
                $bd = is_object($first['booking_data']) ? (array) $first['booking_data'] : $first['booking_data'];
                $flightId = trim((string) ($bd['flight_id'] ?? $bd['offer_id'] ?? ''));
            }
        }
        if ($flightId === '' && $existingRef === '') {
            echo json_encode(['status' => false, 'message' => 'Flight offer not found in booking data. Please search and book again.']);
            exit;
        }

        // 5. Build pax[] from travellers (keys adult_0, child_0, infant_0, ...).
        // Tiqwa pax (Flight.v1.json /book passengers[]): passenger_type (adult|child|
        // infant), first_name, last_name, dob (YYYY-MM-DD), gender (male|female),
        // title (mr|miss|mrs|ms), email, phone_number — ALL required per pax; plus a
        // documents{} object required ONLY when confirm said document_required.
        $travellers = json_decode((string) $booking['travellers'], true);
        if (!is_array($travellers)) $travellers = [];

        $contactEmail = trim((string) ($booking['email'] ?? ''));
        $contactPhone = trim((string) ($booking['phone'] ?? ''));

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
                // Tiqwa wants per-pax email + phone; fall back to the booking contact.
                'email'          => trim((string) ($p['email'] ?? '')) ?: $contactEmail,
                'phone_number'   => trim((string) ($p['phone'] ?? $p['phone_number'] ?? '')) ?: $contactPhone,
            ];
            if ($dob !== '') $entry['dob'] = $dob;

            // Passport/documents (only when the airline requires it — see confirm's
            // document_required). Carry whatever the booking collected.
            $passportNo  = trim((string) ($p['passport_no'] ?? $p['passport'] ?? $p['passport_number'] ?? ''));
            $passportExp = trim((string) ($p['passport_exp'] ?? $p['passport_expiry'] ?? ''));
            if ($passportExp === '' && !empty($p['passport_expiry_year']) && !empty($p['passport_expiry_month']) && !empty($p['passport_expiry_day'])) {
                $passportExp = $p['passport_expiry_year'] . '-' . str_pad((string) $p['passport_expiry_month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $p['passport_expiry_day'], 2, '0', STR_PAD_LEFT);
            }
            if ($passportNo !== '') {
                $entry['documents'] = [
                    'number'              => $passportNo,
                    'document_type'       => 'passport',
                    'holder'              => true,
                    'issuing_country'     => tiqwa_iso2($p['passport_issuing_country'] ?? ($p['issuing_country'] ?? ($booking['country'] ?? '')), $db),
                    'nationality_country' => tiqwa_iso2($p['nationality'] ?? ($booking['country'] ?? ''), $db),
                ];
                if ($passportExp !== '') $entry['documents']['expiry_date'] = date('Y-m-d', strtotime($passportExp));
                $passIss = trim((string) ($p['passport_issue'] ?? $p['passport_issue_date'] ?? ''));
                if ($passIss !== '') $entry['documents']['issuing_date'] = date('Y-m-d', strtotime($passIss));
            }

            $pax[] = $entry;
        }
        if (empty($pax)) {
            echo json_encode(['status' => false, 'message' => 'No passengers found in booking data.']);
            exit;
        }

        // Display/settlement currency for reconcile.
        $displayCurrency = strtoupper((string) (
            ($booking['currency_markup'] ?? '') !== '' ? $booking['currency_markup']
            : (!empty($booking_data['currency']) ? $booking_data['currency'] : 'NGN')
        ));

        // ---- STEP 1: confirm_price (re-validate + capture the confirmed id) -----
        $reference = $existingRef; // skip confirm+book if a reference already exists
        if ($reference === '') {
            $confUrl = $base . '/confirm_price/' . rawurlencode($flightId);
            $conf = tiqwaApiRequest('GET', $confUrl, null, $token, [], 45);
            tiqwaLog('issue/confirm_price', ['flight_id' => $flightId], $conf['raw'], $conf['http_code'], $conf['curl_error'], $invoice_id);
            if (!$conf['success'] || !is_array($conf['data'])) {
                $msg = tiqwaExtractError($conf);
                $db->update('bookings', ['error_response' => json_encode(['step' => 'confirm_price', 'message' => $msg, 'http' => $conf['http_code']]), 'booking_payment_issue' => substr($msg, 0, 240)], ['invoice_id' => $invoice_id]);
                echo json_encode(['status' => false, 'message' => 'Fare could not be confirmed with Tiqwa: ' . $msg, 'invoice_id' => $invoice_id]);
                exit;
            }
            $confOffer = isset($conf['data']['id']) ? $conf['data'] : ($conf['data']['data'] ?? $conf['data']);
            $confirmedId = (string) ($confOffer['id'] ?? $flightId);
            $confCurrency = strtoupper((string) ($confOffer['currency'] ?? $displayCurrency));
            $confTotal = (float) ($confOffer['amount'] ?? 0);

            // Reconcile: never pay a higher all-in fare than the customer paid.
            if (function_exists('reconcilePostPaymentPrice') && $confTotal > 0) {
                $pc = reconcilePostPaymentPrice($db, $booking, $confTotal, $confCurrency);
                if (empty($pc['ok'])) {
                    $db->update('bookings', ['error_response' => json_encode(['step' => 'confirm_reconcile', 'detail' => $pc]), 'booking_payment_issue' => 'Fare increased beyond paid amount — held for review.'], ['invoice_id' => $invoice_id]);
                    echo json_encode(['status' => false, 'message' => 'Airline fare increased beyond the paid amount — held for review.', 'price_review' => $pc, 'invoice_id' => $invoice_id]);
                    exit;
                }
            }

            // ---- STEP 2: book/{flight_id} → reserve, get reference ----
            $bookBody = ['passengers' => $pax];
            $bookUrl  = $base . '/book/' . rawurlencode($confirmedId);
            $bookRes  = tiqwaApiRequest('POST', $bookUrl, $bookBody, $token, [], 120);
            tiqwaLog('issue/book', $bookBody, $bookRes['raw'], $bookRes['http_code'], $bookRes['curl_error'], $invoice_id);

            if ($bookRes['http_code'] === 422) {
                echo json_encode(['status' => false, 'message' => 'Additional passenger details required: ' . tiqwaExtractError($bookRes), 'invoice_id' => $invoice_id]);
                exit;
            }
            $bookData = is_array($bookRes['data']) ? ($bookRes['data']['data'] ?? $bookRes['data']) : null;
            $reference = is_array($bookData) ? trim((string) ($bookData['reference'] ?? $bookData['booking_reference'] ?? '')) : '';
            if (!$bookRes['success'] || $reference === '') {
                $msg = tiqwaExtractError($bookRes);
                $db->update('bookings', ['error_response' => json_encode(['step' => 'book', 'message' => $msg, 'http' => $bookRes['http_code']]), 'booking_payment_issue' => substr($msg, 0, 240)], ['invoice_id' => $invoice_id]);
                echo json_encode(['status' => false, 'message' => 'Flight could not be reserved with Tiqwa: ' . $msg, 'invoice_id' => $invoice_id]);
                exit;
            }

            // Persist the reservation reference IMMEDIATELY (idempotency: a retried
            // callback sees supplier_ref and skips confirm+book).
            $booking_data['supplier_ref']    = $reference;
            $booking_data['tiqwa_reference'] = $reference;
            $booking_data['tiqwa_confirmed_id'] = $confirmedId;
            $booking_data['tiqwa_expires_at'] = (string) ($bookData['expires_at'] ?? '');
            $booking_data['supplier']        = 'tiqwa';
            $db->update('bookings', ['booking_data' => json_encode($booking_data)], ['invoice_id' => $invoice_id]);
        }

        // ---- STEP 3: pay/{reference} → issue the ticket from the TICKET wallet ---
        $payUrl = $base . '/pay/' . rawurlencode($reference);
        $payRes = tiqwaApiRequest('POST', $payUrl, [], $token, [], 120);
        tiqwaLog('issue/pay', ['reference' => $reference], $payRes['raw'], $payRes['http_code'], $payRes['curl_error'], $invoice_id);

        // 403 = ticket wallet underfunded (owner decision B): return status:false so
        // the payment gateway holds 'pending' + AUTO-REFUNDS the customer. The
        // reservation stays at Tiqwa and will auto-expire; admin is alerted to fund.
        if ($payRes['http_code'] === 403) {
            $msg = tiqwaExtractError($payRes);
            $db->update('bookings', [
                'error_response'        => json_encode(['step' => 'pay', 'message' => $msg, 'http' => 403, 'reference' => $reference]),
                'booking_payment_issue' => 'Tiqwa ticket wallet underfunded — held for review + auto-refund. ' . substr($msg, 0, 180),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode([
                'status'  => false,
                'message' => 'Could not issue the ticket: Tiqwa ticket wallet is underfunded. The customer will be refunded and admin alerted to top up.',
                'reference' => $reference,
                'invoice_id' => $invoice_id,
            ]);
            exit;
        }
        if (!$payRes['success']) {
            $msg = tiqwaExtractError($payRes);
            $db->update('bookings', ['error_response' => json_encode(['step' => 'pay', 'message' => $msg, 'http' => $payRes['http_code'], 'reference' => $reference]), 'booking_payment_issue' => substr($msg, 0, 240)], ['invoice_id' => $invoice_id]);
            echo json_encode(['status' => false, 'message' => 'Ticket issuing failed at Tiqwa: ' . $msg, 'invoice_id' => $invoice_id]);
            exit;
        }

        // SUCCESS — /pay accepted. But /pay returns only { message }; the real PNR +
        // ticket number arrive ASYNC via the ticket.issued webhook (or a poll of
        // GET /{booking_reference}). So we mark the booking status 'pending' with
        // pnr='' and the stored reference. We return status:true so the gateway does
        // NOT refund (the money legitimately bought a reserved+paid ticket); the
        // webhook/poll completion path fills pnr and flips to 'confirmed'.
        $booking_data['tiqwa_paid_at'] = date('c');
        $booking_data['supplier']      = 'tiqwa';
        $db->update('bookings', [
            'booking_status' => 'pending',
            'booking_data'   => json_encode($booking_data),
            'error_response' => null,
            'booking_payment_issue' => null,
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'            => true,
            'pnr'               => '',                 // async — filled by webhook/poll
            'booking_reference' => $reference,
            'reference'         => $reference,
            'booking_status'    => 'pending',          // honoured by the gateway callback
            'is_ticketed'       => false,
            'message'           => 'Ticket payment accepted by Tiqwa — awaiting ticket number (arrives via webhook shortly).',
            'response_error'    => '',
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('TIQWA ISSUE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while issuing the ticket: ' . $e->getMessage()]);
        exit;
    }
});
