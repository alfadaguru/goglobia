<?php
// path : modules/flights/adivaha/actions/issue.php
// ISSUE / BOOK ACTION — Adivaha B2C Flights
// Called after payment (the payment gateway loops back to this endpoint with a
// signed internal token) to turn a paid booking into a real ticket/PNR.
//
// Flow (mirrors every other flight provider):
//   1. supplier_action_guard — only gateway-loopback / admin / CSRF may book.
//   2. Idempotency: if the booking already has a PNR, return it (no re-book).
//   3. Concurrency claim on `issue_claimed_at` (NOT booking_status, which is an
//      ENUM) + register_shutdown_function to release the claim on any failure.
//   4. Resolve the chosen offer(s) from bookings.booking_data: a single
//      trace_id+offer_id, OR a legs[] array (round-trip / multi-city).
//   5. Build Adivaha pax[] + the mandatory contact block.
//   6. reconcilePostPaymentPrice — never pay a higher fare than the customer paid.
//   7. POST /flights/book with an Idempotency-Key (stable per invoice) and handle
//      409 price_changed (re-book at the airline price within tolerance using the
//      accept_token), 402 wallet, 422 pax_details_required.
//   8. Persist pnr + booking_status='confirmed' + merge the supplier response.
@$SECURE or die('Access Denied!');

if (!function_exists('adivaha_country_iso2')) {
    /**
     * Resolve a 2-letter ISO country code from whatever bookings.country /
     * phone_country_code holds (a 2-letter code, a phone calling code, or a full
     * country name/nicename). Adivaha's Contact.country MUST be a 2-letter ISO
     * code (spec L1031). Returns '' when unresolved so the caller can decide.
     */
    function adivaha_country_iso2($value, $db)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        // Already a 2-letter alpha code.
        if (strlen($value) === 2 && !is_numeric($value)) return strtoupper($value);
        // Phone calling code → iso (reuses the platform helper).
        if (function_exists('resolveCountryIso')) {
            $byPhone = resolveCountryIso($value, $db);
            if ($byPhone !== '') return $byPhone;
        }
        // Full name / nicename lookup.
        try {
            $row = $db->get('countries', ['iso'], ['name' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
            $row = $db->get('countries', ['iso'], ['nicename' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
        } catch (\Throwable $e) { /* ignore */ }
        return '';
    }
}

if (!function_exists('adivaha_pax_type')) {
    /** Map the platform traveller-key prefix to Adivaha's pax type enum. */
    function adivaha_pax_type(string $paxKey): string
    {
        if (strpos($paxKey, 'child_') === 0)  return 'child';
        if (strpos($paxKey, 'infant_') === 0) return 'infant';
        return 'adult';
    }
}

$router->post('flights/adivaha/issue', function () use ($db) {
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

        // 2. Idempotency: already issued → return existing PNR, do NOT re-book.
        if (!empty($booking['pnr'])) {
            echo json_encode([
                'status'            => true,
                'message'           => 'Booking already issued',
                'pnr'               => $booking['pnr'],
                'booking_reference' => $booking['pnr'],
                'invoice_id'        => $invoice_id,
                'already_issued'    => true,
            ]);
            exit;
        }
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => false, 'message' => 'Booking is ' . $booking['booking_status'] . ' and cannot be issued.']);
            exit;
        }

        // 2b. On_hold / not-yet-ticketed idempotency: a booking can already exist at
        // Adivaha (status on_hold) with NO local pnr yet — the pnr check above would
        // miss it and a retried callback could book a SECOND time (relying only on
        // Adivaha's Idempotency-Key). If we already stored a supplier reference for
        // this invoice, the supplier booking exists → do NOT re-book.
        $__existingBd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        $__existingRef = is_array($__existingBd)
            ? trim((string) ($__existingBd['supplier_ref'] ?? $__existingBd['order_id'] ?? ($__existingBd['adivaha_response']['reference'] ?? '')))
            : '';
        if ($__existingRef !== '') {
            echo json_encode([
                'status'            => true,
                'message'           => 'Booking already created with Adivaha (awaiting ticketing or confirmation).',
                'pnr'               => (string) ($booking['pnr'] ?? ''),
                'booking_reference' => $__existingRef,
                'reference'         => $__existingRef,
                'invoice_id'        => $invoice_id,
                'already_issued'    => true,
            ]);
            exit;
        }

        // 3. Concurrency claim on a dedicated nullable timestamp (see duffel audit #25).
        try { $db->query("ALTER TABLE `bookings` ADD COLUMN `issue_claimed_at` datetime DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
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
            } catch (\Throwable $e) { error_log('adivaha issue claim-release: ' . $e->getMessage()); }
        });

        // Module + credentials.
        $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'flights']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Adivaha module not configured']);
            exit;
        }
        $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha API credentials not configured']);
            exit;
        }
        $bearer = adivahaBearer($db, $credentials);
        $base   = $credentials['base_url'];

        // 4. Resolve the chosen offer(s) from booking_data.
        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];
        $travellers   = json_decode((string) $booking['travellers'], true);
        if (!is_array($travellers)) $travellers = [];

        // Collect the (trace_id, offer_id) pair(s) for the book call, IN TRIP
        // ORDER. For a round trip Adivaha may price the sectors separately (2x) —
        // the outbound and the return each carry their OWN offer_id, and we must
        // send BOTH in legs[] (spec L41-49). search.php emits a combined offer as
        // segments[0]=outbound + segments[1]=return; the checkout normaliser then
        // stores the chosen flight as flight_data with `segments` (outbound leg
        // groups) and `returnSegments` (return leg groups). We walk BOTH so the
        // return sector is never dropped. A package/one-way offer yields one pair.
        $legs = [];
        $collectLeg = function ($bd) use (&$legs) {
            if (!is_array($bd)) return;
            $t = (string) ($bd['trace_id'] ?? '');
            $o = (string) ($bd['offer_id'] ?? $bd['booking_token'] ?? '');
            if ($t !== '' && $o !== '') {
                $legs[] = ['trace_id' => $t, 'offer_id' => $o];
            }
        };
        // Pull the booking_data out of a leg group, which is shaped [ {flightObj} ]
        // (an array whose first element is the flight carrying booking_data), or a
        // flight object/array directly.
        $collectFromLegGroup = function ($legGroup) use ($collectLeg) {
            if (!is_array($legGroup)) return;
            $flight = $legGroup[0] ?? $legGroup;
            if (is_object($flight)) $flight = (array) $flight;
            if (is_array($flight) && isset($flight['booking_data'])) {
                $bd = is_object($flight['booking_data']) ? (array) $flight['booking_data'] : $flight['booking_data'];
                $collectLeg($bd);
            }
        };

        $fd = is_array($booking_data['flight_data'] ?? null) ? $booking_data['flight_data'] : [];

        // 1) Explicit legs[] (if a future checkout ever stores it) — authoritative.
        if (!empty($booking_data['legs']) && is_array($booking_data['legs'])) {
            foreach ($booking_data['legs'] as $lg) { $collectLeg($lg); }
        }

        // 2) Outbound leg groups: flight_data.segments[], then top-level segments[].
        if (empty($legs)) {
            $outboundSegs = is_array($fd['segments'] ?? null) ? $fd['segments']
                : (is_array($booking_data['segments'] ?? null) ? $booking_data['segments'] : []);
            foreach ($outboundSegs as $legGroup) { $collectFromLegGroup($legGroup); }

            // Return leg groups (round trip 2x): flight_data.returnSegments[].
            $returnSegs = is_array($fd['returnSegments'] ?? null) ? $fd['returnSegments'] : [];
            foreach ($returnSegs as $legGroup) { $collectFromLegGroup($legGroup); }
        }

        // 3) Fallbacks for a one-way whose token sits in a flat booking_data.
        if (empty($legs)) { $collectLeg($booking_data); }
        if (empty($legs) && !empty($fd['booking_data'])) { $collectLeg($fd['booking_data']); }
        if (empty($legs) && !empty($booking_data['booking_data'])) { $collectLeg($booking_data['booking_data']); }

        // De-duplicate identical (trace,offer) pairs while PRESERVING ORDER (a
        // one-way leg may be copied across sources; a round trip keeps both).
        $seen = [];
        $legs = array_values(array_filter($legs, function ($l) use (&$seen) {
            $k = $l['trace_id'] . '|' . $l['offer_id'];
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));

        if (empty($legs)) {
            echo json_encode([
                'status'  => false,
                'message' => 'Offer/trace not found in booking data. Please search and book again.',
                'debug'   => ['available_keys' => array_keys($booking_data)],
            ]);
            exit;
        }

        // 5a. Build pax[] from travellers (keys adult_0, child_0, infant_0, ...).
        $pax = [];
        foreach ($travellers as $paxKey => $p) {
            if (!is_array($p) || !preg_match('/^(adult|child|infant)_\d+$/', (string) $paxKey)) continue;
            $type = adivaha_pax_type((string) $paxKey);

            // DOB (prefer ISO; else day/month/year).
            $dob = null;
            $isoDob = trim((string) ($p['dob'] ?? $p['date_of_birth'] ?? ''));
            if ($isoDob !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDob, $m)) {
                $dob = $m[1] . '-' . $m[2] . '-' . $m[3];
            } elseif (!empty($p['dob_year']) && !empty($p['dob_month']) && !empty($p['dob_day'])) {
                $dob = $p['dob_year'] . '-' . str_pad((string) $p['dob_month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $p['dob_day'], 2, '0', STR_PAD_LEFT);
            }

            $title  = strtolower(trim((string) ($p['title'] ?? 'mr')));
            $gender = strtolower(trim((string) ($p['gender'] ?? '')));
            if ($gender === '') {
                $gender = in_array($title, ['mrs', 'ms', 'miss'], true) ? 'female' : 'male';
            } elseif (in_array($gender, ['m', 'male'], true)) {
                $gender = 'male';
            } elseif (in_array($gender, ['f', 'female'], true)) {
                $gender = 'female';
            } else {
                $gender = 'male';
            }

            $entry = [
                'type'        => $type,                         // adult | child | infant (spec L992)
                'title'       => ucfirst($title),               // "Mr" etc.
                'first_name'  => (string) ($p['first_name'] ?? ''),
                'last_name'   => (string) ($p['last_name'] ?? ''),
                'gender'      => $gender,                        // male | female (spec L996)
            ];
            if ($dob) $entry['dob'] = $dob;

            $nat = adivaha_country_iso2($p['nationality'] ?? ($booking['nationality'] ?? ''), $db);
            if ($nat !== '') $entry['nationality'] = $nat;

            // Passport (international fares). Carry what the booking collected.
            // The flight booking form stores these as `passport_number` /
            // `passport_expiry` (+ split expiry day/month/year); older/API paths use
            // `passport_no`/`passport`/`passport_exp`. Read ALL known keys so a
            // collected passport is actually sent (else the supplier rejects with
            // "passport number is required" even though the user entered one).
            $paxPassportNo = trim((string) (
                $p['passport_no'] ?? $p['passport'] ?? $p['passport_number'] ?? ''
            ));
            if ($paxPassportNo !== '') {
                $entry['passport_no'] = $paxPassportNo;
            }
            $paxPassportExp = trim((string) ($p['passport_exp'] ?? $p['passport_expiry'] ?? ''));
            if ($paxPassportExp === '' && !empty($p['passport_expiry_year']) && !empty($p['passport_expiry_month']) && !empty($p['passport_expiry_day'])) {
                $paxPassportExp = $p['passport_expiry_year'] . '-'
                    . str_pad((string) $p['passport_expiry_month'], 2, '0', STR_PAD_LEFT) . '-'
                    . str_pad((string) $p['passport_expiry_day'], 2, '0', STR_PAD_LEFT);
            }
            if ($paxPassportExp !== '') {
                $entry['passport_exp'] = date('Y-m-d', strtotime($paxPassportExp));
            }
            $issCountry = adivaha_country_iso2($p['passport_issuing_country'] ?? ($p['issuing_country'] ?? ''), $db);
            if ($issCountry !== '') $entry['passport_issuing_country'] = $issCountry;

            $pax[] = $entry;
        }

        // Legacy traveller shapes (primary_guest + travelers[]) as a fallback.
        if (empty($pax) && isset($travellers['primary_guest'])) {
            $g = $travellers['primary_guest'];
            $pax[] = [
                'type'       => 'adult',
                'title'      => ucfirst(strtolower((string) ($g['title'] ?? 'mr'))),
                'first_name' => (string) ($g['first_name'] ?? $booking['first_name']),
                'last_name'  => (string) ($g['last_name'] ?? $booking['last_name']),
                'gender'     => in_array(strtolower((string) ($g['gender'] ?? 'm')), ['f', 'female'], true) ? 'female' : 'male',
            ];
            if (!empty($g['dob'])) $pax[count($pax) - 1]['dob'] = date('Y-m-d', strtotime((string) $g['dob']));
        }

        if (empty($pax)) {
            echo json_encode(['status' => false, 'message' => 'No passengers found in booking data.']);
            exit;
        }

        // 5b. Build the mandatory contact block (spec L1022-1043). bookings has
        // country+address but NOT city/postal — pull those from booking_data's
        // billing block when present, else fall back so the airline gets a
        // complete billing address.
        $billing   = is_array($booking_data['billing'] ?? null) ? $booking_data['billing'] : [];
        $countryIso = adivaha_country_iso2($booking['country'] ?? ($billing['country'] ?? ''), $db);
        if ($countryIso === '') {
            $countryIso = adivaha_country_iso2($booking['phone_country_code'] ?? '', $db) ?: 'IN';
        }
        $phoneIso = adivaha_country_iso2($booking['phone_country_code'] ?? '', $db) ?: $countryIso;
        $phoneE164 = function_exists('buildE164PhoneNumber')
            ? buildE164PhoneNumber($booking['phone'] ?? '', $phoneIso, $db)
            : (string) ($booking['phone'] ?? '');

        $contactName = trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')));
        if ($contactName === '') $contactName = (string) ($billing['name'] ?? 'Guest');

        // COMPANY BILLING ADDRESS fallback: suppliers reject a junk address
        // ('N/A'/'NA'/'000000') with "Address is invalid". When the traveller/
        // booking carries no real address (e.g. a seamless domestic booking), fall
        // back to the clean, admin-set COMPANY address from settings rather than a
        // placeholder, so the supplier always gets a valid billing address.
        $__co = $db->get('settings',
            ['company_address_line', 'company_city', 'company_country', 'company_postal'],
            ['id' => 1]) ?: [];
        $coLine    = trim((string) ($__co['company_address_line'] ?? ''));
        $coCity    = trim((string) ($__co['company_city'] ?? ''));
        $coCountry = strtoupper(trim((string) ($__co['company_country'] ?? '')));
        $coPostal  = trim((string) ($__co['company_postal'] ?? ''));

        $addrLine = (string) ($booking['address'] ?? ($billing['address_line'] ?? ($billing['address'] ?? '')));
        if (trim($addrLine) === '' || strtoupper(trim($addrLine)) === 'N/A') { $addrLine = $coLine ?: 'N/A'; }
        $addrCity = (string) ($billing['city'] ?? ($booking_data['city'] ?? ''));
        if (trim($addrCity) === '' || strtoupper(trim($addrCity)) === 'NA') { $addrCity = $coCity ?: 'NA'; }
        $addrPostal = (string) ($billing['postal_code'] ?? ($billing['postal'] ?? ($booking_data['postal_code'] ?? '')));
        if (trim($addrPostal) === '' || $addrPostal === '000000') { $addrPostal = $coPostal ?: '000000'; }
        // Country: prefer the resolved booking country; if that is empty, use the
        // company country (so the billing country is never blank/wrong).
        if ($countryIso === '' && $coCountry !== '') { $countryIso = $coCountry; }

        $contact = [
            'name'         => $contactName,
            'email'        => (string) ($booking['email'] ?? ($billing['email'] ?? '')),
            'phone'        => $phoneE164,
            'address_line' => $addrLine,
            'city'         => $addrCity,
            'country'      => $countryIso,
            'postal_code'  => $addrPostal,
        ];

        // 6. Price protection. Two independent guards cover a fare that moved
        // between quote and book:
        //   (a) Adivaha re-prices at book and returns 409 price_changed if the
        //       airline fare rose beyond its 2% tolerance (handled at 7a below).
        //   (b) After a successful book we reconcile the ACTUAL all-in total
        //       Adivaha charged (booking.total) against what the customer paid
        //       (reconcilePostPaymentPrice compares its argument to the booking's
        //       price_markup / currency_markup). This is done post-book (7c)
        //       because the authoritative supplier total is only known then.
        // We deliberately do NOT pre-reconcile against a stored estimate here —
        // the stored figure is not the live supplier total and would be a false
        // signal. The display/settlement currency for this booking. Use a chain
        // that skips EMPTY strings (?? only skips null/unset, but currency_markup
        // defaults to '' in the schema), else an empty display_currency would be
        // sent to /flights/book and break the 409 reconcile currency match.
        $displayCurrency = strtoupper((string) (
            ($booking['currency_markup'] ?? '') !== '' ? $booking['currency_markup']
            : (!empty($booking_data['currency']) ? $booking_data['currency']
            : (!empty($booking_data['base_currency']) ? $booking_data['base_currency'] : 'INR'))
        ));

        // 7. Build the Adivaha book request. Single leg → trace_id+offer_id;
        // multiple legs → legs[] (spec L1044-1065).
        $bookBody = [
            'pax'     => $pax,
            'contact' => $contact,
            'remarks' => 'Booked via Goglobia (invoice ' . $invoice_id . ')',
        ];
        if (count($legs) === 1) {
            $bookBody['trace_id'] = $legs[0]['trace_id'];
            $bookBody['offer_id'] = $legs[0]['offer_id'];
        } else {
            $bookBody['legs'] = array_map(function ($l) {
                return ['trace_id' => $l['trace_id'], 'offer_id' => $l['offer_id']];
            }, $legs);
        }

        // Stable Idempotency-Key per invoice so a retried payment callback never
        // issues a second ticket (spec L447-451). Adivaha replays the original
        // response for a completed key and returns 409 for one in flight.
        $idemKey = 'gg-' . substr(hash('sha256', (string) $invoice_id), 0, 32);
        $bookUrl = $base . '/flights/book?display_currency=' . urlencode($displayCurrency);

        $doBook = function ($body) use ($bookUrl, $bearer, $idemKey) {
            return adivahaApiRequest('POST', $bookUrl, $body, $bearer, ['Idempotency-Key: ' . $idemKey], 120);
        };

        $res = $doBook($bookBody);
        adivahaLog('book', $bookBody, $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        // 7a. 409 price_changed → re-book at the airline price using accept_token,
        // but ONLY if the new all-in total still passes reconciliation (never pay
        // more than the customer paid without review).
        if ($res['http_code'] === 409 && is_array($res['data']) && ($res['data']['error'] ?? '') === 'price_changed') {
            $newTotal = (float) ($res['data']['new_total'] ?? 0);
            $newCur   = strtoupper((string) ($res['data']['currency'] ?? $displayCurrency));
            // FAIL-CLOSED: only auto-accept the airline's new fare when we could
            // actually RECONCILE it against what the customer paid and it passed.
            // If reconcile is unavailable OR the 409 body carries no usable
            // new_total (malformed/zero), we CANNOT verify the price — so we hold
            // for review rather than accept an unverified (possibly higher) fare.
            $recheckOk = false;
            if (function_exists('reconcilePostPaymentPrice') && $newTotal > 0) {
                $pc = reconcilePostPaymentPrice($db, $booking, $newTotal, $newCur);
                $recheckOk = !empty($pc['ok']);
            }
            $acceptTokens = [];
            if (!empty($res['data']['legs']) && is_array($res['data']['legs'])) {
                foreach ($res['data']['legs'] as $lg) {
                    if (!empty($lg['accept_token'])) $acceptTokens[] = (string) $lg['accept_token'];
                }
            }
            if ($recheckOk && !empty($acceptTokens)) {
                $bookBody['accept_price'] = $acceptTokens;
                $res = $doBook($bookBody);
                adivahaLog('book/accept-price', $bookBody, $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);
            } else {
                echo json_encode([
                    'status'  => false,
                    'message' => 'Airline fare increased beyond the paid amount — held for review. '
                        . 'Old: ' . ($res['data']['old_total'] ?? '?') . ' New: ' . $newTotal . ' ' . $newCur,
                    'price_review' => $res['data'],
                    'invoice_id'   => $invoice_id,
                ]);
                exit;
            }
        }

        // 7b. Specific non-success statuses with honest messages.
        if ($res['http_code'] === 402) {
            echo json_encode(['status' => false, 'message' => 'Supplier wallet has insufficient balance to issue this ticket. ' . adivahaExtractError($res), 'invoice_id' => $invoice_id]);
            exit;
        }
        if ($res['http_code'] === 422) {
            echo json_encode(['status' => false, 'message' => 'Additional passenger details required by the airline: ' . adivahaExtractError($res), 'invoice_id' => $invoice_id]);
            exit;
        }

        $data = is_array($res['data']) ? ($res['data']['booking'] ?? null) : null;
        $success = $res['success'] && is_array($data) && !empty($data['reference']);

        if ($success) {
            // The REAL airline PNR only. Adivaha's `reference` (BK...) is the
            // booking id, NOT a PNR — never store it in bookings.pnr, or the
            // idempotency guard (which treats a non-empty pnr as "fully issued")
            // would stop an on_hold booking from ever being updated with its real
            // PNR once the airline confirms. pnr stays '' until Adivaha returns one.
            $pnr        = trim((string) ($data['pnr'] ?? ''));
            $supplierRef = (string) ($data['reference'] ?? '');
            $status     = strtolower((string) ($data['status'] ?? 'confirmed'));
            $isTicketed = !empty($data['is_ticketed']);
            // Map Adivaha status to the bookings ENUM('confirmed','pending','cancelled').
            // on_hold (awaiting airline confirmation) → pending; confirmed → confirmed.
            $bookingStatus = ($status === 'on_hold' || !$isTicketed) ? 'pending' : 'confirmed';

            // 7c. Post-book reconciliation (informational): compare the ACTUAL
            // all-in total Adivaha charged (booking.total, in booking.currency)
            // against what the customer paid (price_markup/currency_markup). The
            // ticket is ALREADY issued, so we do NOT abort — we record any overage
            // as a review note for ops. A rise beyond tolerance here is rare
            // because Adivaha's own 409 (7a) already rejects fare jumps > 2%.
            $priceReview = null;
            $supplierTotal = (float) ($data['total'] ?? 0);
            $supplierCur   = strtoupper((string) ($data['currency'] ?? $displayCurrency));
            if (function_exists('reconcilePostPaymentPrice') && $supplierTotal > 0) {
                $pc = reconcilePostPaymentPrice($db, $booking, $supplierTotal, $supplierCur);
                if (empty($pc['ok'])) {
                    $priceReview = $pc;
                    error_log('ADIVAHA ISSUE: post-book price review for ' . $invoice_id . ' — ' . ($pc['reason'] ?? ''));
                }
                // NOTE: reconcilePostPaymentPrice flips booking_status to 'pending'
                // on a block (via _reconcile_block). We re-assert the real booked
                // status below so a successfully ticketed booking is not left
                // showing 'pending' purely because of an informational overage.
            }

            $merged = array_merge($booking_data, [
                'supplier'          => 'adivaha',
                'adivaha_booking_id' => $data['id'] ?? null,
                'supplier_ref'      => $supplierRef,
                'order_id'          => $supplierRef,
                'adivaha_response'  => $data,
                'price_review'      => $priceReview,
            ]);

            // Persist. pnr holds the REAL airline PNR only (may be '' for on_hold
            // / not-yet-ticketed). The supplier booking reference lives in
            // booking_data.supplier_ref/order_id for cancel/void. A later confirm
            // can fill pnr via /flights/booking-details without the idempotency
            // guard having wrongly locked the booking as "issued".
            $writeData = [
                'booking_status' => $bookingStatus,
                'booking_data'   => json_encode($merged),
            ];
            if ($pnr !== '') {
                $writeData['pnr'] = $pnr;
            }
            $updated = $db->update('bookings', $writeData, ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status'            => true,
                'pnr'               => $pnr,                 // '' when on_hold / awaiting ticketing
                'booking_reference' => $supplierRef,         // Adivaha booking id (BK...)
                'reference'         => $supplierRef,
                'order_id'          => $supplierRef,
                'is_ticketed'       => $isTicketed,
                'booking_status'    => $bookingStatus,
                'message'           => ($bookingStatus === 'confirmed'
                        ? 'Booking issued successfully with Adivaha'
                        : 'Booking is on hold at Adivaha — awaiting airline confirmation')
                    . ($priceReview ? ' (flagged for price review — see admin).' : ''),
                'price_review'      => $priceReview,
                'response_error'    => '',
            ]);
            exit;
        }

        // Failure: record the error (leave status for retry; do NOT set a PNR).
        $errMsg = adivahaExtractError($res);
        try {
            $db->update('bookings', [
                'error_response'        => json_encode(['status' => false, 'message' => $errMsg, 'http' => $res['http_code'], 'api_response' => $res['data']]),
                'booking_payment_issue' => substr($errMsg, 0, 250),
            ], ['invoice_id' => $invoice_id]);
        } catch (\Throwable $e) { error_log('adivaha issue error-record: ' . $e->getMessage()); }

        echo json_encode([
            'status'         => false,
            'message'        => 'Failed to issue ticket with Adivaha: ' . $errMsg,
            'http'           => $res['http_code'],
            'debug_response' => $res['data'],
            'invoice_id'     => $invoice_id,
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA ISSUE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while issuing the ticket: ' . $e->getMessage()]);
        exit;
    }
});
