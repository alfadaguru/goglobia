<?php

// ============================================================================
// AMADEUS ENTERPRISE FLIGHT BOOKING API ENDPOINT - ISSUE PNR
// ============================================================================
//
// PURPOSE:
// Confirm a booked Amadeus Enterprise flight after payment by creating
// the flight order and saving the PNR/reference in the bookings table.
//
// ENDPOINT: POST /flights/amadeus_enterprise/issue
//
// EXPECTED BOOKING DATA:
// - booking_data.key / flight_data.booking_data.key: Flight offer from search
// - travellers: Passenger details captured during booking
//
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('flights/amadeus_enterprise/issue', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }

    ob_start();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $invoiceId = '';
    $supportLogText = '';

    try {
        @set_time_limit(480);

        $invoiceId = trim($_POST['invoice_id'] ?? '');
        if ($invoiceId === '') {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if (!empty($booking['pnr'])) {
            echo json_encode([
                'status' => true,
                'Prn' => $booking['pnr'],
                'booking_reference' => $booking['pnr'],
                'reference' => $booking['pnr'],
                'message' => 'Booking already issued',
                'invoice_id' => $invoiceId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $moduleRows = $db->select('modules', '*', [
            'name' => 'amadeus_enterprise',
            'type' => 'flights',
            'active' => '1',
            'status' => '1',
            'ORDER' => ['id' => 'DESC']
        ]);

        if (empty($moduleRows)) {
            $moduleRows = $db->select('modules', '*', [
                'name' => 'amadeus_enterprise',
                'type' => 'flights',
                'ORDER' => ['id' => 'DESC']
            ]);
        }

        $module = !empty($moduleRows) ? $moduleRows[0] : null;
        if (!$module) {
            throw new Exception('Amadeus Enterprise module not configured');
        }

        $clientId = trim((string)($module['c1'] ?? ''));
        $clientSecret = trim((string)($module['c2'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new Exception('Amadeus Enterprise API credentials not configured');
        }

        $envRaw = strtolower(trim((string)($module['env'] ?? $module['mode'] ?? '')));
        $isProduction = in_array($envRaw, ['pro', 'production', 'live'], true)
            || ($envRaw === '' && (int)($module['dev_mode'] ?? 0) === 0);

        // Prefer travel.api (Enterprise), fall back to api.amadeus.com (Self-Service host).
        $endpointCandidates = $isProduction
            ? [
                ['v1' => 'https://travel.api.amadeus.com/v1/', 'env' => 'production'],
                ['v1' => 'https://api.amadeus.com/v1/', 'env' => 'production_legacy'],
            ]
            : [
                ['v1' => 'https://test.travel.api.amadeus.com/v1/', 'env' => 'test'],
                ['v1' => 'https://test.api.amadeus.com/v1/', 'env' => 'test_self_service'],
            ];

        $bookingData = json_decode((string)($booking['booking_data'] ?? '{}'), true) ?: [];
        $travellers = json_decode((string)($booking['travellers'] ?? '[]'), true) ?: [];
        $existingSearchLog = (string)($bookingData['search_support_log']
            ?? $bookingData['flight_data']['booking_data']['support_log']
            ?? $bookingData['flight_data']['booking_data']['search_support_log']
            ?? '');

        $flightOffer = null;
        $candidates = [
            $bookingData['key'] ?? null,
            $bookingData['flight_data']['booking_data']['key'] ?? null,
            $bookingData['flight_data']['key'] ?? null,
            $bookingData['offer'] ?? null,
            $bookingData['flight_offer'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                $flightOffer = $candidate;
                break;
            }
        }

        if (empty($flightOffer)) {
            echo json_encode([
                'status' => false,
                'message' => 'Flight offer data not found in booking_data',
                'debug' => [
                    'available_keys' => array_keys($bookingData),
                    'structure_sample' => substr(json_encode($bookingData), 0, 500),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Airline PNRs reject multi-word / special-char names; Amadeus often returns opaque 38189.
        $sanitizeAmadeusName = static function (string $name, int $maxLen = 29): string {
            $name = strtoupper(trim($name));
            $name = preg_replace('/[^A-Z\s]/', ' ', $name) ?? $name;
            $name = preg_replace('/\s+/', ' ', $name) ?? $name;
            $name = trim($name);
            // Keep first token only — "DOLORE AUTEM ES" → "DOLORE"
            if ($name !== '' && str_contains($name, ' ')) {
                $parts = explode(' ', $name);
                $name = $parts[0];
            }
            if ($maxLen > 0 && strlen($name) > $maxLen) {
                $name = rtrim(substr($name, 0, $maxLen));
            }
            return $name;
        };

        $fitAmadeusNamePair = static function (string $firstName, string $lastName) use ($sanitizeAmadeusName): array {
            $firstName = $sanitizeAmadeusName($firstName, 29);
            $lastName = $sanitizeAmadeusName($lastName, 29);
            $maxCombined = 29;

            if ($firstName === '') {
                $firstName = 'GUEST';
            }
            if ($lastName === '') {
                $lastName = 'USER';
            }

            if (strlen($firstName) + strlen($lastName) <= $maxCombined) {
                return [$firstName, $lastName];
            }

            $lastBudget = min(strlen($lastName), 14);
            $lastName = rtrim(substr($lastName, 0, $lastBudget));
            $firstBudget = max(1, $maxCombined - strlen($lastName));
            $firstName = rtrim(substr($firstName, 0, $firstBudget));

            if ($firstName === '') {
                $firstName = 'GUEST';
            }
            if ($lastName === '') {
                $lastName = 'USER';
            }

            return [$firstName, $lastName];
        };

        $normalizePhone = static function (string $rawPhone, string $callingCode): array {
            $callingCode = preg_replace('/\D+/', '', $callingCode) ?: '1';
            $number = preg_replace('/\D+/', '', $rawPhone) ?? '';

            // Drop leading zeros from national number.
            $number = ltrim($number, '0');

            // Strip duplicated country calling code from the national number.
            if ($number !== '' && $callingCode !== '' && str_starts_with($number, $callingCode) && strlen($number) > strlen($callingCode) + 5) {
                $number = substr($number, strlen($callingCode));
                $number = ltrim($number, '0');
            }

            // Amadeus expects 6–15 digits; fall back to a safe placeholder.
            if ($number === '' || strlen($number) < 6 || strlen($number) > 15) {
                $number = '480080076';
                if ($callingCode === '' || strlen($callingCode) > 4) {
                    $callingCode = '1';
                }
            }

            return [$callingCode, $number];
        };

        $normalizeCountryCode = static function (string $raw): string {
            $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
            if (strlen($code) >= 2) {
                return substr($code, 0, 2);
            }
            return 'US';
        };

        $buildTravelers = static function (array $sourceTravellers, array $bookingRow) use (
            $fitAmadeusNamePair,
            $normalizePhone,
            $normalizeCountryCode
        ): array {
            if (!empty($sourceTravellers['primary_guest']) || !empty($sourceTravellers['travelers'])) {
                $flat = [];
                if (!empty($sourceTravellers['primary_guest']) && is_array($sourceTravellers['primary_guest'])) {
                    $flat['adult_0'] = $sourceTravellers['primary_guest'];
                }
                if (!empty($sourceTravellers['travelers']) && is_array($sourceTravellers['travelers'])) {
                    foreach ($sourceTravellers['travelers'] as $k => $v) {
                        if (is_array($v)) {
                            $flat[is_string($k) ? $k : ('adult_' . $k)] = $v;
                        }
                    }
                }
                $sourceTravellers = $flat;
            }

            $travelers = [];
            $index = 1;
            $adultIds = [];
            [$phoneCallingCode, ] = $normalizePhone(
                (string)($bookingRow['phone'] ?? ''),
                (string)($bookingRow['phone_country_code'] ?? '1')
            );

            foreach ($sourceTravellers as $key => $traveller) {
                if (!is_array($traveller)) {
                    continue;
                }

                $travellerKey = is_string($key) ? strtolower($key) : '';
                $isInfant = str_starts_with($travellerKey, 'infant_');
                $isChild = str_starts_with($travellerKey, 'child_');

                [$firstName, $lastName] = $fitAmadeusNamePair(
                    (string)($traveller['first_name'] ?? ''),
                    (string)($traveller['last_name'] ?? '')
                );
                if ($firstName === '' || $lastName === '') {
                    continue;
                }

                $dob = $traveller['dob'] ?? '';
                if ($dob === '' && !empty($traveller['dob_year']) && !empty($traveller['dob_month']) && !empty($traveller['dob_day'])) {
                    $dob = sprintf('%04d-%02d-%02d', (int)$traveller['dob_year'], (int)$traveller['dob_month'], (int)$traveller['dob_day']);
                }
                if ($dob === '') {
                    if ($isInfant) {
                        $dob = date('Y-m-d', strtotime('-1 year'));
                    } elseif ($isChild) {
                        $dob = date('Y-m-d', strtotime('-8 years'));
                    } else {
                        $dob = date('Y-m-d', strtotime('-30 years'));
                    }
                }

                $genderRaw = strtolower(trim((string)($traveller['gender'] ?? '')));
                if ($genderRaw === '') {
                    $genderRaw = in_array(strtolower((string)($traveller['title'] ?? 'mr')), ['mrs', 'ms', 'miss'], true) ? 'f' : 'm';
                }
                $gender = (str_starts_with($genderRaw, 'f') || $genderRaw === 'female') ? 'FEMALE' : 'MALE';

                $contactEmail = trim((string)($traveller['email'] ?? $bookingRow['email'] ?? ''));
                if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                    $contactEmail = 'noreply@example.com';
                }

                [, $contactPhone] = $normalizePhone(
                    (string)($traveller['phone'] ?? $bookingRow['phone'] ?? ''),
                    $phoneCallingCode
                );

                $nationality = $normalizeCountryCode((string)(
                    $traveller['nationality']
                    ?? $traveller['passport_country']
                    ?? $bookingRow['nationality']
                    ?? 'US'
                ));
                $issuanceCountry = $normalizeCountryCode((string)(
                    $traveller['passport_country']
                    ?? $traveller['nationality']
                    ?? $nationality
                ));

                $traveler = [
                    'id' => (string)$index,
                    'dateOfBirth' => date('Y-m-d', strtotime($dob)),
                    'gender' => $gender,
                    'name' => [
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                    ],
                    'contact' => [
                        'emailAddress' => $contactEmail,
                        'phones' => [[
                            'deviceType' => 'MOBILE',
                            'countryCallingCode' => $phoneCallingCode,
                            'number' => $contactPhone,
                        ]],
                    ],
                ];

                // Search requests HELD_INFANT, which requires the infant to be linked
                // to the adult it travels with.
                if ($isInfant && !empty($adultIds)) {
                    $traveler['associatedAdultId'] = (string)$adultIds[0];
                }

                $passportNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($traveller['passport_number'] ?? ''))) ?? '';
                if (strlen($passportNumber) > 15) {
                    $passportNumber = substr($passportNumber, 0, 15);
                }
                if ($passportNumber === '') {
                    $passportNumber = '00000000';
                }

                $passportExpiry = '';
                if (!empty($traveller['passport_expiry'])) {
                    $passportExpiry = date('Y-m-d', strtotime((string)$traveller['passport_expiry']));
                } elseif (!empty($traveller['passport_expiry_year']) && !empty($traveller['passport_expiry_month']) && !empty($traveller['passport_expiry_day'])) {
                    $passportExpiry = sprintf(
                        '%04d-%02d-%02d',
                        (int)$traveller['passport_expiry_year'],
                        (int)$traveller['passport_expiry_month'],
                        (int)$traveller['passport_expiry_day']
                    );
                }
                if ($passportExpiry === '' || strtotime($passportExpiry) < strtotime('+30 days')) {
                    $passportExpiry = date('Y-m-d', strtotime('+5 years'));
                }

                $issuanceDate = '';
                if (!empty($traveller['passport_issue_date'])) {
                    $issuanceDate = date('Y-m-d', strtotime((string)$traveller['passport_issue_date']));
                }
                if ($issuanceDate === '' || strtotime($issuanceDate) >= strtotime($passportExpiry)) {
                    $issuanceDate = date('Y-m-d', strtotime($passportExpiry . ' -5 years'));
                }

                $birthPlace = trim((string)($traveller['birth_place'] ?? $traveller['city'] ?? 'Unknown'));
                if ($birthPlace === '') {
                    $birthPlace = 'Unknown';
                }

                // Full document block matching Amadeus official Flight Create Orders examples.
                $traveler['documents'] = [[
                    'documentType' => 'PASSPORT',
                    'birthPlace' => $birthPlace,
                    'issuanceLocation' => $birthPlace,
                    'issuanceDate' => $issuanceDate,
                    'number' => $passportNumber,
                    'expiryDate' => $passportExpiry,
                    'issuanceCountry' => $issuanceCountry,
                    'validityCountry' => $issuanceCountry,
                    'nationality' => $nationality,
                    'holder' => true,
                ]];

                if (!$isInfant && !$isChild) {
                    $adultIds[] = $index;
                }

                $travelers[] = $traveler;
                $index++;
            }

            if (empty($travelers)) {
                [$firstName, $lastName] = $fitAmadeusNamePair(
                    (string)($bookingRow['first_name'] ?? 'Guest'),
                    (string)($bookingRow['last_name'] ?? 'User')
                );
                [$fallbackCallingCode, $fallbackPhone] = $normalizePhone(
                    (string)($bookingRow['phone'] ?? ''),
                    (string)($bookingRow['phone_country_code'] ?? '1')
                );
                $fallbackCountry = $normalizeCountryCode((string)($bookingRow['nationality'] ?? 'US'));
                $travelers[] = [
                    'id' => '1',
                    'dateOfBirth' => date('Y-m-d', strtotime('-30 years')),
                    'gender' => 'MALE',
                    'name' => [
                        'firstName' => $firstName !== '' ? $firstName : 'GUEST',
                        'lastName' => $lastName !== '' ? $lastName : 'USER',
                    ],
                    'contact' => [
                        'emailAddress' => trim((string)($bookingRow['email'] ?? '')) ?: 'noreply@example.com',
                        'phones' => [[
                            'deviceType' => 'MOBILE',
                            'countryCallingCode' => $fallbackCallingCode,
                            'number' => $fallbackPhone,
                        ]],
                    ],
                    'documents' => [[
                        'documentType' => 'PASSPORT',
                        'birthPlace' => 'Unknown',
                        'issuanceLocation' => 'Unknown',
                        'issuanceDate' => date('Y-m-d', strtotime('-5 years')),
                        'number' => '00000000',
                        'expiryDate' => date('Y-m-d', strtotime('+5 years')),
                        'issuanceCountry' => $fallbackCountry,
                        'validityCountry' => $fallbackCountry,
                        'nationality' => $fallbackCountry,
                        'holder' => true,
                    ]],
                ];
            }

            return $travelers;
        };

        $prepareOfferForBooking = static function ($offer) {
            if (is_string($offer)) {
                $decoded = json_decode($offer, true);
                $offer = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($offer)) {
                return null;
            }

            // Strip search/pricing-only metadata that can trigger opaque 500s on create-order.
            unset($offer['fareRules'], $offer['dictionaries'], $offer['meta']);
            if (isset($offer['pricingOptions']) && is_array($offer['pricingOptions'])) {
                unset($offer['pricingOptions']['refundableFare']);
            }
            if (isset($offer['price']) && is_array($offer['price'])) {
                unset($offer['price']['margin'], $offer['price']['refundableTaxes']);
            }

            return $offer;
        };

        // OAuth against preferred host, then fallback host (same pattern as search.php).
        $tokenData = null;
        $tokenHttpCode = 0;
        $tokenResponse = null;
        $tokenCurlError = '';
        $endPointV1 = $endpointCandidates[0]['v1'];
        $selectedEnv = $endpointCandidates[0]['env'];

        foreach ($endpointCandidates as $endpoint) {
            $tokenCurl = curl_init();
            curl_setopt_array($tokenCurl, [
                CURLOPT_URL => $endpoint['v1'] . 'security/oauth2/token',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id=' . urlencode($clientId) . '&client_secret=' . urlencode($clientSecret),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 30,
            ]);

            $tokenResponse = curl_exec($tokenCurl);
            $tokenHttpCode = (int)curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);
            $tokenCurlError = curl_error($tokenCurl);
            curl_close($tokenCurl);

            $tokenData = json_decode((string)$tokenResponse, true) ?: [];
            if ($tokenHttpCode === 200 && !empty($tokenData['access_token'])) {
                $endPointV1 = $endpoint['v1'];
                $selectedEnv = $endpoint['env'];
                break;
            }
            $tokenData = null;
        }

        if (empty($tokenData['access_token'])) {
            $supportLogText = "STEP: oauth_token\n"
                . "REQUEST:\nPOST " . $endpointCandidates[0]['v1'] . "security/oauth2/token (+ fallback host)\n"
                . "Content-Type: application/x-www-form-urlencoded\n"
                . "Body: grant_type=client_credentials&client_id=" . $clientId . "&client_secret=[REDACTED]\n\n"
                . "RESPONSE:\nHTTP " . $tokenHttpCode . "\n"
                . (is_string($tokenResponse) ? $tokenResponse : json_encode($tokenResponse));

            if ($existingSearchLog !== '') {
                $supportLogText = $existingSearchLog . "\n\n------------------------------\n\n" . $supportLogText;
            }

            $db->update('bookings', [
                'error_response' => json_encode([
                    'step' => 'oauth_token',
                    'message' => 'Failed to get OAuth token from Amadeus API',
                    'http_code' => $tokenHttpCode,
                    'request' => [
                        'method' => 'POST',
                        'url' => $endpointCandidates[0]['v1'] . 'security/oauth2/token',
                        'content_type' => 'application/x-www-form-urlencoded',
                        'body' => 'grant_type=client_credentials&client_id=' . $clientId . '&client_secret=[REDACTED]',
                    ],
                    'response_raw' => $tokenResponse,
                    'response' => json_decode((string)$tokenResponse, true),
                    'support_log' => $supportLogText,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['invoice_id' => $invoiceId]);

            throw new Exception('Failed to get OAuth token from Amadeus API: ' . ($tokenCurlError ?: 'Unknown error'));
        }

        $amadeusTravelers = $buildTravelers(is_array($travellers) ? $travellers : [], $booking);
        if (empty($amadeusTravelers)) {
            throw new Exception('No valid traveler data found in booking');
        }

        $flightOffer = $prepareOfferForBooking($flightOffer);
        if (empty($flightOffer)) {
            throw new Exception('Flight offer data is invalid or corrupted');
        }

        // Align traveler count with offer travelerPricings (prevents opaque create-order failures).
        $offerTravelerCount = isset($flightOffer['travelerPricings']) && is_array($flightOffer['travelerPricings'])
            ? count($flightOffer['travelerPricings'])
            : 0;
        if ($offerTravelerCount > 0 && count($amadeusTravelers) > $offerTravelerCount) {
            $amadeusTravelers = array_slice($amadeusTravelers, 0, $offerTravelerCount);
            foreach ($amadeusTravelers as $i => &$t) {
                $t['id'] = (string)($i + 1);
            }
            unset($t);
        }

        $repricingPayload = json_encode([
            'data' => [
                'type' => 'flight-offers-pricing',
                'flightOffers' => [$flightOffer],
            ]
        ]);

        $runRepricing = static function (string $query = 'forceClass=false') use ($endPointV1, $tokenData, $repricingPayload): array {
            $repricingCurl = curl_init();
            curl_setopt_array($repricingCurl, [
                CURLOPT_URL => $endPointV1 . 'shopping/flight-offers/pricing?' . $query,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $repricingPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $tokenData['access_token'],
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $resp = curl_exec($repricingCurl);
            $code = curl_getinfo($repricingCurl, CURLINFO_HTTP_CODE);
            $err = curl_error($repricingCurl);
            curl_close($repricingCurl);

            return [
                'http_code' => $code,
                'curl_error' => $err,
                'raw' => $resp,
                'json' => json_decode((string)$resp, true),
                'query' => $query,
            ];
        };

        $repricingResult = $runRepricing('forceClass=false');
        $repricingHttpCode = (int)$repricingResult['http_code'];
        $repricingCurlError = (string)$repricingResult['curl_error'];
        $repricingData = is_array($repricingResult['json']) ? $repricingResult['json'] : [];

        $firstCode = (string)($repricingData['errors'][0]['code'] ?? '');
        if (($repricingHttpCode >= 500 || $firstCode === '141') && empty($repricingData['data']['flightOffers'][0])) {
            $repricingResult = $runRepricing('forceClass=false');
            $repricingHttpCode = (int)$repricingResult['http_code'];
            $repricingCurlError = (string)$repricingResult['curl_error'];
            $repricingData = is_array($repricingResult['json']) ? $repricingResult['json'] : [];
            $firstCode = (string)($repricingData['errors'][0]['code'] ?? '');
        }

        if ($repricingHttpCode !== 200 || empty($repricingData['data']['flightOffers'][0])) {
            if (in_array($firstCode, ['4926', '34651', '32171'], true) || stripos((string)($repricingData['errors'][0]['detail'] ?? ''), 'fare') !== false) {
                $repricingResult = $runRepricing('forceClass=true');
                $repricingHttpCode = (int)$repricingResult['http_code'];
                $repricingCurlError = (string)$repricingResult['curl_error'];
                $repricingData = is_array($repricingResult['json']) ? $repricingResult['json'] : [];
            }
        }

        if ($repricingHttpCode !== 200 || empty($repricingData['data']['flightOffers'][0])) {
            $repricingCode = (string)($repricingData['errors'][0]['code'] ?? '');
            $repricingTitle = (string)($repricingData['errors'][0]['title'] ?? '');
            $repricingDetail = (string)($repricingData['errors'][0]['detail'] ?? '');
            $rawRepricingMsg = $repricingDetail !== ''
                ? $repricingDetail
                : ((string)($repricingData['message'] ?? $repricingCurlError ?? 'Unable to reprice flight offer'));

            $supplierContext = trim('Amadeus ' . $repricingCode . ($repricingTitle !== '' ? ' (' . $repricingTitle . ')' : '') . ': ' . $rawRepricingMsg);
            $repricingMsg = $supplierContext;

            $pricingUrl = $endPointV1 . 'shopping/flight-offers/pricing?' . ($repricingResult['query'] ?? 'forceClass=false');
            $supportLogText = "STEP: repricing\n"
                . "Environment: " . $selectedEnv . "\n"
                . "REQUEST:\nPOST " . $pricingUrl . "\n"
                . "Content-Type: application/json\n"
                . "Body:\n" . (string)$repricingPayload . "\n\n"
                . "RESPONSE:\nHTTP " . $repricingHttpCode . "\n"
                . (is_string($repricingResult['raw'] ?? null)
                    ? (string)$repricingResult['raw']
                    : json_encode($repricingData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($existingSearchLog !== '') {
                $supportLogText = $existingSearchLog . "\n\n------------------------------\n\n" . $supportLogText;
            }

            $db->update('bookings', [
                'error_response' => json_encode([
                    'step' => 'repricing',
                    'code' => $repricingCode,
                    'message' => $repricingMsg,
                    'supplier_error' => $supplierContext,
                    'http_code' => $repricingHttpCode,
                    'request' => [
                        'method' => 'POST',
                        'url' => $pricingUrl,
                        'content_type' => 'application/json',
                        'body' => json_decode((string)$repricingPayload, true),
                    ],
                    'response_raw' => $repricingResult['raw'] ?? null,
                    'response' => $repricingData,
                    'support_log' => $supportLogText,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['invoice_id' => $invoiceId]);

            throw new Exception($repricingMsg);
        }

        $flightOffer = $prepareOfferForBooking($repricingData['data']['flightOffers'][0]);
        if (empty($flightOffer)) {
            throw new Exception('Repriced flight offer is invalid');
        }

        // POST-PAYMENT PRICE RECONCILIATION (§8.1(2) fix): compare the repriced
        // Amadeus grandTotal to the amount paid before creating the order; abort +
        // flag if the fare rose beyond tolerance. Read the price from the raw
        // repriced offer (prepareOfferForBooking may drop fields).
        if (function_exists('reconcilePostPaymentPrice')) {
            $aeRawPrice   = $repricingData['data']['flightOffers'][0]['price'] ?? [];
            $aeLiveTotal  = (float) ($aeRawPrice['grandTotal'] ?? $aeRawPrice['total'] ?? 0);
            $aeCurrency   = (string) ($aeRawPrice['currency'] ?? ($booking['currency_markup'] ?? 'USD'));
            if ($aeLiveTotal > 0) {
                $aePriceCheck = reconcilePostPaymentPrice($db, $booking, $aeLiveTotal, $aeCurrency);
                if (empty($aePriceCheck['ok'])) {
                    echo json_encode([
                        'status'  => false,
                        'Prn'     => '',
                        'message' => 'Booking held for review: ' . $aePriceCheck['reason'],
                        'price_review' => $aePriceCheck,
                        'response_error' => 'price_mismatch',
                    ], JSON_UNESCAPED_SLASHES);
                    return;
                }
            }
        }

        [$phoneCallingCode, $contactPhone] = $normalizePhone(
            (string)($booking['phone'] ?? $amadeusTravelers[0]['contact']['phones'][0]['number'] ?? ''),
            (string)($booking['phone_country_code'] ?? $amadeusTravelers[0]['contact']['phones'][0]['countryCallingCode'] ?? '1')
        );

        $contactEmail = trim((string)($booking['email'] ?? ''));
        if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactEmail = (string)($amadeusTravelers[0]['contact']['emailAddress'] ?? 'noreply@example.com');
        }

        $contactCountry = $normalizeCountryCode((string)(
            $amadeusTravelers[0]['documents'][0]['nationality']
            ?? $booking['nationality']
            ?? 'US'
        ));

        [$contactFirstName, $contactLastName] = $fitAmadeusNamePair(
            (string)($booking['first_name'] ?? $amadeusTravelers[0]['name']['firstName'] ?? 'Guest'),
            (string)($booking['last_name'] ?? $amadeusTravelers[0]['name']['lastName'] ?? 'User')
        );

        $postalCode = preg_replace('/[^A-Za-z0-9\- ]/', '', (string)($booking['postal_code'] ?? '')) ?: '00000';
        if (strlen($postalCode) > 10) {
            $postalCode = substr($postalCode, 0, 10);
        }
        $cityName = trim((string)($booking['city'] ?? ''));
        if ($cityName === '') {
            $cityName = 'City';
        }
        $addressLine = trim((string)($booking['address'] ?? ''));
        if ($addressLine === '') {
            $addressLine = '123 Main Street';
        }

        $buildOrderPayload = static function (array $offer, array $travelers, bool $minimal = false) use (
            $invoiceId,
            $contactFirstName,
            $contactLastName,
            $phoneCallingCode,
            $contactPhone,
            $contactEmail,
            $addressLine,
            $postalCode,
            $cityName,
            $contactCountry
        ): string {
            $data = [
                'type' => 'flight-order',
                'flightOffers' => [$offer],
                'travelers' => $travelers,
                'ticketingAgreement' => [
                    'option' => 'DELAY_TO_CANCEL',
                    'delay' => '6D',
                ],
                'contacts' => [[
                    'addresseeName' => [
                        'firstName' => $contactFirstName !== '' ? $contactFirstName : 'GUEST',
                        'lastName' => $contactLastName !== '' ? $contactLastName : 'USER',
                    ],
                    'companyName' => $GLOBALS['app']['business_name'] ?? $GLOBALS['app']['website_title'] ?? 'PHPTRAVELS',
                    'purpose' => 'STANDARD',
                    'phones' => [[
                        'deviceType' => 'MOBILE',
                        'countryCallingCode' => $phoneCallingCode,
                        'number' => $contactPhone,
                    ]],
                    'emailAddress' => $contactEmail,
                    'address' => [
                        'lines' => [$addressLine],
                        'postalCode' => $postalCode,
                        'cityName' => $cityName,
                        'countryCode' => $contactCountry,
                    ],
                ]],
            ];

            if (!$minimal) {
                $data['remarks'] = [
                    'general' => [[
                        'subType' => 'GENERAL_MISCELLANEOUS',
                        'text' => 'Booking via invoice: ' . $invoiceId,
                    ]],
                ];
            }

            return json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };

        $postFlightOrder = static function (string $payload) use ($endPointV1, $tokenData): array {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $endPointV1 . 'booking/flight-orders',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/vnd.amadeus+json',
                    'Authorization: Bearer ' . $tokenData['access_token'],
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $response = curl_exec($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            return [
                'raw' => $response,
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'json' => json_decode((string)$response, true),
                'payload' => $payload,
            ];
        };

        $payload = $buildOrderPayload($flightOffer, $amadeusTravelers, false);
        $orderResult = $postFlightOrder($payload);
        $httpCode = (int)$orderResult['http_code'];
        $response = $orderResult['raw'];
        $curlError = (string)$orderResult['curl_error'];
        $responseData = is_array($orderResult['json']) ? $orderResult['json'] : [];

        // Retry opaque 38189 once with a minimal payload (no remarks) — common Amadeus flake.
        $errorCode = (string)($responseData['errors'][0]['code'] ?? '');
        if (($httpCode >= 500 || $errorCode === '38189') && empty($responseData['data'])) {
            usleep(500000);
            $payload = $buildOrderPayload($flightOffer, $amadeusTravelers, true);
            $orderResult = $postFlightOrder($payload);
            $httpCode = (int)$orderResult['http_code'];
            $response = $orderResult['raw'];
            $curlError = (string)$orderResult['curl_error'];
            $responseData = is_array($orderResult['json']) ? $orderResult['json'] : [];
            $errorCode = (string)($responseData['errors'][0]['code'] ?? '');
        }

        if ($response === false || ($response !== '' && $responseData === [] && json_decode((string)$response) === null && json_last_error() !== JSON_ERROR_NONE)) {
            throw new Exception('Invalid JSON response from API' . ($curlError !== '' ? ': ' . $curlError : ''));
        }

        if ($httpCode === 201 && !empty($responseData['data'])) {
            $bookingReference = '';
            if (!empty($responseData['data']['associatedRecords'])) {
                foreach ($responseData['data']['associatedRecords'] as $record) {
                    if (!empty($record['reference'])) {
                        $bookingReference = $record['reference'];
                        break;
                    }
                }
            }

            if ($bookingReference === '' && !empty($responseData['data']['id'])) {
                $bookingReference = $responseData['data']['id'];
            }

            if ($bookingReference === '') {
                throw new Exception('Booking created but no reference found in response');
            }

            $db->update('bookings', [
                'booking_status' => 'confirmed',
                'pnr' => $bookingReference,
                'booking_response' => $response,
                'error_response' => null,
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status' => true,
                'Prn' => $bookingReference,
                'booking_reference' => $bookingReference,
                'reference' => $bookingReference,
                'response' => $responseData,
                'invoice_id' => $invoiceId,
                'booking_details' => [
                    'order_id' => $responseData['data']['id'] ?? '',
                    'type' => $responseData['data']['type'] ?? 'flight-order',
                    'travelers_count' => count($amadeusTravelers),
                    'flight_offers_count' => 1,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $errorMessage = $responseData['errors'][0]['detail']
            ?? $responseData['errors'][0]['title']
            ?? $responseData['message']
            ?? $curlError
            ?? 'Booking commit failed';

        // Amadeus returns opaque sell-stage errors whose raw text ("An internal error
        // occured, please contact your administrator") is meaningless to a customer.
        // Translate the known ones; keep the code in the message for support.
        $sellFailureCodes = [
            '38189' => 'This fare could not be confirmed by the airline. Please search again and select another flight.',
            '34651' => 'The airline could not sell the selected seats. Please search again and select another flight.',
            '4926'  => 'The selected fare is no longer available. Please search again.',
        ];
        if (isset($sellFailureCodes[$errorCode])) {
            $errorMessage = $sellFailureCodes[$errorCode];
        }

        if ($errorCode !== '') {
            $errorMessage = 'Amadeus ' . $errorCode . ': ' . $errorMessage;
        }

        $supportLogText = "STEP: issue\n"
            . "Environment: " . $selectedEnv . "\n"
            . "Travelers: " . count($amadeusTravelers) . "\n"
            . "Names: " . ($amadeusTravelers[0]['name']['firstName'] ?? '') . ' / ' . ($amadeusTravelers[0]['name']['lastName'] ?? '') . "\n"
            . "Phone: +" . $phoneCallingCode . ' ' . $contactPhone . "\n"
            . "REQUEST:\nPOST " . $endPointV1 . "booking/flight-orders\n"
            . "Content-Type: application/json\n"
            . "Body:\n" . (string)$payload . "\n\n"
            . "RESPONSE:\nHTTP " . $httpCode . "\n"
            . (is_string($response) ? $response : json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($existingSearchLog !== '') {
            $supportLogText = $existingSearchLog . "\n\n------------------------------\n\n" . $supportLogText;
        }

        $db->update('bookings', [
            'error_response' => json_encode([
                'step' => 'issue',
                'code' => $errorCode,
                'message' => $errorMessage,
                'http_code' => $httpCode,
                'request' => [
                    'method' => 'POST',
                    'url' => $endPointV1 . 'booking/flight-orders',
                    'content_type' => 'application/json',
                    'body' => json_decode((string)$payload, true),
                ],
                'response_raw' => $response,
                'response' => $responseData,
                'support_log' => $supportLogText,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], ['invoice_id' => $invoiceId]);

        throw new Exception($errorMessage);
    } catch (Throwable $e) {
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'debug_log' => $supportLogText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
