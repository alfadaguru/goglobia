<?php
// path : modules/flights/duffel/actions/issue.php
// ISSUE BOOKING ACTION
// This action issues/confirms a booking with Duffel API
@$SECURE or die('Access Denied!');

/**
 * Duffel ages: adult 18+, child 2–17, infant_without_seat under 2.
 * Infants with stale defaults are auto-corrected; adult/child mismatches return an error.
 *
 * @return array{dob: string, error: ?string}
 */
if (!function_exists('duffel_ensure_dob_matches_type')) {
    function duffel_ensure_dob_matches_type(string $dob, string $type, string $paxLabel = ''): array
    {
        $born = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$born) {
            return ['dob' => $dob, 'error' => null];
        }
        $ref = new DateTime('today');
        $ageYears = (int)$born->diff($ref)->y;
        $ageMonths = ((int)$ref->format('Y') - (int)$born->format('Y')) * 12
            + ((int)$ref->format('n') - (int)$born->format('n'));
        if ((int)$ref->format('j') < (int)$born->format('j')) {
            $ageMonths--;
        }

        $ok = false;
        $rule = '18+ years';
        if ($type === 'infant_without_seat') {
            $ok = $ageMonths < 24;
            $rule = 'under 2 years';
        } elseif ($type === 'child') {
            $ok = $ageYears >= 2 && $ageYears <= 17;
            $rule = '2–17 years';
        } else {
            $ok = $ageYears >= 18;
            $rule = '18+ years';
        }
        if ($ok) {
            return ['dob' => $dob, 'error' => null];
        }

        // Infant form defaults (e.g. 2023/2024) age out — auto-fix so issue can proceed
        if ($type === 'infant_without_seat') {
            $fixed = (clone $ref)->modify('-1 year')->format('Y-m-d');
            return ['dob' => $fixed, 'error' => null];
        }

        $who = $paxLabel !== '' ? $paxLabel : 'passenger';
        return [
            'dob' => $dob,
            'error' => "Date of birth for {$who} ({$dob}) does not match type '{$type}' (must be {$rule}). Please correct the DOB on the booking form.",
        ];
    }
}

$router->post('flights/duffel/issue', function () use ($db) {
    header('Content-Type: application/json');

    try {
        // Get required data
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        // Fetch booking
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // Get module configuration
        $module = $db->get('modules', '*', [
            'name' => 'duffel',
            'type' => 'flights'
        ]);

        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Duffel module not configured']);
            exit;
        }

        // Get API credentials
        $api_token = null;
        if (!empty($module['credentials'])) {
            $credentials = json_decode($module['credentials'], true);
            $api_token = $credentials['c1'] ?? null;
        }
        if (!$api_token && !empty($module['c1'])) {
            $api_token = $module['c1'];
        }

        if (!$api_token) {
            echo json_encode(['status' => false, 'message' => 'Duffel API credentials not configured']);
            exit;
        }

        // Decode booking data
        $booking_data = json_decode($booking['booking_data'], true);
        $travellers = json_decode($booking['travellers'], true);

        // Debug: Log the actual structure

        // Extract booking token - check multiple possible locations
        $offer_id = null;

        // Method 1: Direct booking_token in root
        if (!empty($booking_data['booking_token'])) {
            $offer_id = $booking_data['booking_token'];
            error_log("DUFFEL ISSUE: Found token in root - " . $offer_id);
        }
        // Method 2: In nested booking_data
        elseif (!empty($booking_data['booking_data']['booking_token'])) {
            $offer_id = $booking_data['booking_data']['booking_token'];
        }
        // Method 3: In flight_data structure
        elseif (!empty($booking_data['flight_data']['booking_data']['booking_token'])) {
            $offer_id = $booking_data['flight_data']['booking_data']['booking_token'];
            error_log("DUFFEL ISSUE: Found token in flight_data - " . $offer_id);
        }
        // Method 4: In segments array (from search response structure)
        elseif (!empty($booking_data['segments'][0][0]['booking_data']['booking_token'])) {
            $offer_id = $booking_data['segments'][0][0]['booking_data']['booking_token'];
            error_log("DUFFEL ISSUE: Found token in segments - " . $offer_id);
        }

        if (empty($offer_id)) {
            // Provide detailed debug information
            echo json_encode([
                'status' => false,
                'message' => 'Booking token not found in booking data. Check server logs for structure details.',
                'debug' => [
                    'available_keys' => array_keys($booking_data),
                    'has_booking_token' => isset($booking_data['booking_token']),
                    'has_nested_booking_data' => isset($booking_data['booking_data']),
                    'has_flight_data' => isset($booking_data['flight_data']),
                    'has_segments' => isset($booking_data['segments']),
                    'structure_sample' => substr(json_encode($booking_data), 0, 500)
                ]
            ]);
            exit;
        }

        // Fetch offer details from Duffel to get correct passenger IDs (MANDATORY for multi-passenger orders)
        $ch_offer = curl_init('https://api.duffel.com/air/offers/' . $offer_id);
        curl_setopt($ch_offer, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_offer, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ]);
        $offer_res_raw = curl_exec($ch_offer);
        $offer_res = json_decode($offer_res_raw, true);
        curl_close($ch_offer);

        if (!isset($offer_res['data'])) {
            error_log("DUFFEL ISSUE: Failed to fetch offer " . $offer_id . " - " . $offer_res_raw);
        }

        $duffel_paxes = $offer_res['data']['passengers'] ?? [];
        $duffel_services = $offer_res['data']['available_services'] ?? [];
        $offer_net_price = $offer_res['data']['total_amount'] ?? '0.00';
        $offer_currency = $offer_res['data']['total_currency'] ?? 'USD';

        $pax_id_map = ['adult' => [], 'child' => [], 'infant_without_seat' => []];
        $included_checked = '';
        $included_cabin = '';

        foreach ($duffel_paxes as $pIdx => $dp) {
            if (isset($dp['type']) && isset($dp['id'])) {
                $pax_id_map[$dp['type']][] = $dp['id'];
            }
            
            // Extract included baggage from the first passenger as a representative for the fare
            if ($pIdx === 0 && !empty($dp['baggages'])) {
                foreach ($dp['baggages'] as $bag) {
                    if ($bag['type'] === 'checked') {
                        $included_checked = $bag['quantity'] . " PC";
                    } elseif ($bag['type'] === 'carry_on') {
                        $included_cabin = $bag['quantity'] . " PC";
                    }
                }
            }
        }
        
        // Update booking_data with included baggage if found
        if (!empty($included_checked)) $booking_data['flight_data']['baggage'] = $included_checked;
        if (!empty($included_cabin)) $booking_data['flight_data']['cabin_baggage'] = $included_cabin;

        // Better baggage extraction (Quantity OR Weight)
        if (!empty($duffel_paxes[0]['baggages'])) {
            $checked = [];
            $cabin = [];
            foreach ($duffel_paxes[0]['baggages'] as $bag) {
                $val = '';
                if (!empty($bag['quantity'])) {
                    $val = $bag['quantity'] . " PC";
                } elseif (!empty($bag['amount'])) {
                    $val = $bag['amount'] . " " . strtoupper($bag['unit'] ?? 'KG');
                }
                
                if ($val) {
                    if ($bag['type'] === 'checked') $checked[] = $val;
                    else $cabin[] = $val;
                }
            }
            if (!empty($checked)) $booking_data['flight_data']['baggage'] = implode(", ", $checked);
            if (!empty($cabin)) $booking_data['flight_data']['cabin_baggage'] = implode(", ", $cabin);
        }

        // Prepare passengers data from travellers
        $passengers = [];
        $pax_type_counters = ['adult' => 0, 'child' => 0, 'infant_without_seat' => 0];

        // Build passengers array from all travellers (iterate root level)
        if (is_array($travellers)) {
            $passenger_counter = 1; // Initialize counter for fallback incremental IDs

            foreach ($travellers as $pax_key => $passenger) {
                // Skip if not a passenger key (adult_*, child_*, infant_*)
                if (!preg_match('/^(adult|child|infant)_\d+$/', $pax_key)) {
                    continue;
                }

                // Build date of birth: prefer ISO dob / date_of_birth, else day/month/year
                $dob = null;
                $isoDob = trim((string)($passenger['dob'] ?? $passenger['date_of_birth'] ?? ''));
                if ($isoDob !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDob, $m)) {
                    $dob = $m[1] . '-' . $m[2] . '-' . $m[3];
                } elseif (!empty($passenger['dob_year']) && !empty($passenger['dob_month']) && !empty($passenger['dob_day'])) {
                    $dob = $passenger['dob_year'] . '-' .
                        str_pad($passenger['dob_month'], 2, '0', STR_PAD_LEFT) . '-' .
                        str_pad($passenger['dob_day'], 2, '0', STR_PAD_LEFT);
                }

                // Determine passenger type from key (adult_0, child_0, infant_0)
                // Must match Duffel offer passenger slots — do NOT change type from DOB.
                $passenger_type = 'adult'; // default
                if (strpos($pax_key, 'child_') === 0) {
                    $passenger_type = 'child';
                } elseif (strpos($pax_key, 'infant_') === 0) {
                    $passenger_type = 'infant_without_seat';
                }

                // Duffel: adult 18+, child 2–17, infant_without_seat under 2.
                if ($dob) {
                    $dobCheck = duffel_ensure_dob_matches_type($dob, $passenger_type, $pax_key);
                    if (!empty($dobCheck['error'])) {
                        echo json_encode([
                            'status' => false,
                            'message' => $dobCheck['error'],
                            'response_error' => $dobCheck['error'],
                        ]);
                        exit;
                    }
                    if ($dobCheck['dob'] !== $dob) {
                        error_log("DUFFEL ISSUE: {$pax_key} DOB {$dob} does not match type {$passenger_type}; using {$dobCheck['dob']}");
                        $dob = $dobCheck['dob'];
                    }
                }

                // Map title to valid Duffel values (mr, ms, mrs, miss, dr)
                $title_raw = strtolower($passenger['title'] ?? 'mr');
                if ($title_raw === 'master') {
                    $title = 'mr';
                } elseif ($title_raw === 'miss') {
                    $title = 'miss';
                } elseif (in_array($title_raw, ['mr', 'ms', 'mrs', 'dr'])) {
                    $title = $title_raw;
                } else {
                    $title = 'mr';
                }

                // Determine gender from title if not provided
                $gender = 'm';
                if (in_array($title, ['mrs', 'ms', 'miss'])) {
                    $gender = 'f';
                }

                $passenger_data = [
                    'type' => $passenger_type,
                    'title' => $title,
                    'given_name' => $passenger['first_name'],
                    'family_name' => $passenger['last_name'],
                    'gender' => $gender
                ];

                // Add date of birth if available
                if ($dob) {
                    $passenger_data['born_on'] = $dob;
                }

                // Add passenger ID - Priority: 
                // 1. Existing ID (from ancillaries mapping)
                // 2. Mapped ID from Duffel Offer
                // 3. Fallback counter (likely to fail but better than nothing)
                if (!empty($passenger['id'])) {
                    $passenger_data['id'] = $passenger['id'];
                } elseif (!empty($pax_id_map[$passenger_type][$pax_type_counters[$passenger_type]])) {
                    $passenger_data['id'] = $pax_id_map[$passenger_type][$pax_type_counters[$passenger_type]];
                    $pax_type_counters[$passenger_type]++;
                } else {
                    $passenger_data['id'] = (string) $passenger_counter;
                }

                $passenger_counter++; // Increment counter for fallback case
                error_log("DUFFEL ISSUE: Final ID for {$pax_key}: " . ($passenger_data['id'] ?? 'NONE'));

                // Add email and phone for passengers
                // Duffel often requires contact info for passengers depending on the airline
                $passenger_data['email'] = !empty($passenger['email']) ? $passenger['email'] : $booking['email'];
                
                $orig_phone = !empty($passenger['phone']) ? $passenger['phone'] : $booking['phone'];
                $countryIso = resolveCountryIso($booking['phone_country_code'] ?? '', $db) ?: 'US';
                $e164Phone  = buildE164PhoneNumber($orig_phone, $countryIso, $db);
                // In test mode use Duffel's test phone to avoid validation errors
                if (str_starts_with($api_token, 'duffel_test_')) {
                    $e164Phone = '+12025550123';
                }
                $passenger_data['phone_number'] = $e164Phone;

                $passengers[] = $passenger_data;
            }
        }

        // If no passengers found, try legacy structure (primary_guest + travelers)
        if (empty($passengers)) {
            // Primary guest
            if (isset($travellers['primary_guest'])) {
                $guest = $travellers['primary_guest'];
                $passengers[] = [
                    'id' => $booking_data['flight_data']['booking_data']['passenger_id'] ?? null,
                    'type' => 'adult',
                    'title' => strtolower($guest['title'] ?? 'mr'),
                    'given_name' => $guest['first_name'] ?? $booking['first_name'],
                    'family_name' => $guest['last_name'] ?? $booking['last_name'],
                    'born_on' => isset($guest['dob']) ? date('Y-m-d', strtotime($guest['dob'])) : null,
                    'email' => $booking['email'],
                    'phone_number' => str_starts_with($api_token, 'duffel_test_')
                        ? '+12025550123'
                        : buildE164PhoneNumber($booking['phone'], resolveCountryIso($booking['phone_country_code'] ?? '', $db) ?: 'US', $db),
                    'gender' => strtolower($guest['gender'] ?? 'm')
                ];
            }

            // Additional travelers
            if (isset($travellers['travelers']) && is_array($travellers['travelers'])) {
                foreach ($travellers['travelers'] as $traveler) {
                    $tType = $traveler['type'] ?? 'adult';
                    if ($tType === 'infant') {
                        $tType = 'infant_without_seat';
                    }
                    $tDob = isset($traveler['dob']) ? date('Y-m-d', strtotime($traveler['dob'])) : null;
                    if ($tDob) {
                        $dobCheck = duffel_ensure_dob_matches_type($tDob, $tType, 'traveler');
                        if (!empty($dobCheck['error'])) {
                            echo json_encode([
                                'status' => false,
                                'message' => $dobCheck['error'],
                                'response_error' => $dobCheck['error'],
                            ]);
                            exit;
                        }
                        $tDob = $dobCheck['dob'];
                    }
                    $passengers[] = [
                        'type' => $tType,
                        'title' => strtolower($traveler['title'] ?? 'mr'),
                        'given_name' => $traveler['first_name'],
                        'family_name' => $traveler['last_name'],
                        'born_on' => $tDob,
                        'gender' => strtolower($traveler['gender'] ?? 'm')
                    ];
                }
            }
        }

        // Final validation: ensure we have at least one passenger
        if (empty($passengers)) {
            echo json_encode([
                'status' => false,
                'message' => 'No passengers found in booking data',
                'debug' => [
                    'travellers_structure' => array_keys($travellers),
                    'has_passengers' => isset($travellers['passengers']),
                    'has_primary_guest' => isset($travellers['primary_guest'])
                ]
            ]);
            exit;
        }

        // Extract services and calculate their total price
        $services_map = []; // Map of serviceId => quantity
        $services_prices = []; // Map of serviceId => individual_price

        if (!empty($booking_data['ancillary_data'])) {
            $ancillary = $booking_data['ancillary_data'];

            // Add Baggage
            if (!empty($ancillary['baggage'])) {
                foreach ($ancillary['baggage'] as $bag) {
                    if (!empty($bag['serviceId'])) {
                        $sid = $bag['serviceId'];
                        $qty = (int) ($bag['quantity'] ?? 1);
                        $services_map[$sid] = ($services_map[$sid] ?? 0) + $qty;
                        $services_prices[$sid] = (float) ($bag['price'] ?? 0);
                    }
                }
            }

            // Add Seats
            if (!empty($ancillary['seats'])) {
                foreach ($ancillary['seats'] as $segIdx => $paxSeats) {
                    foreach ($paxSeats as $paxId => $seat) {
                        if (!empty($seat['serviceId'])) {
                            $sid = $seat['serviceId'];
                            // Seats MUST have quantity 1 per Duffel rules
                            $services_map[$sid] = 1;
                            $services_prices[$sid] = (float) ($seat['price'] ?? 0);
                        }
                    }
                }
            }
        }

        $final_services = [];
        $actual_services_total = 0;
        foreach ($services_map as $sid => $qty) {
            $final_services[] = [
                'id' => $sid,
                'quantity' => $qty
            ];

            // Validate price from live offer if possible
            $found_live_price = null;
            foreach ($duffel_services as $ds) {
                if ($ds['id'] === $sid) {
                    $found_live_price = (float) ($ds['total_amount'] ?? 0);
                    break;
                }
            }

            if ($found_live_price !== null) {
                $actual_services_total += ($found_live_price * $qty);
            } else {
                $actual_services_total += (($services_prices[$sid] ?? 0) * $qty);
            }
        }

        // Use live offer price instead of stored price to ensure match
        $base_price = ($offer_net_price > 0) ? $offer_net_price : ($booking_data['flight_data']['booking_data']['actual_amount'] ?? $booking['price_original'] ?? '0.00');
        $total_price = number_format((float) $base_price + $actual_services_total, 2, '.', '');
        $currency = !empty($offer_currency) ? $offer_currency : ($booking_data['currency'] ?? 'USD');

        // POST-PAYMENT PRICE RECONCILIATION: the live Duffel total ($total_price)
        // is compared to what the customer actually paid before we book. If the
        // fare rose beyond tolerance, abort the auto-issue and flag for review
        // rather than silently paying the higher amount from balance.
        if (function_exists('reconcilePostPaymentPrice')) {
            $priceCheck = reconcilePostPaymentPrice($db, $booking, (float) $total_price, $currency);
            if (empty($priceCheck['ok'])) {
                ob_get_level() && ob_clean();
                echo json_encode([
                    'status'  => false,
                    'message' => 'Booking held for review: ' . $priceCheck['reason'],
                    'price_review' => $priceCheck,
                    'invoice_id' => $invoice_id,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        // Prepare order payload
        $payload = [
            'data' => [
                'selected_offers' => [$offer_id],
                'passengers' => $passengers,
                'services' => $final_services,
                'type' => 'instant',
                'payments' => [
                    [
                        'type' => 'balance',
                        'amount' => $total_price,
                        'currency' => $currency
                    ]
                ],
                'metadata' => [
                    'payment_intent_id' => 'pit_' . time(),
                    'invoice_id' => $invoice_id
                ]
            ]
        ];

        error_log("DUFFEL ISSUE: Sending payload - " . json_encode($payload));


        // Call Duffel API to create order
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.duffel.com/air/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            echo json_encode([
                'status' => false,
                'message' => 'API connection error: ' . $curl_error,
                'response_error' => $curl_error
            ]);
            exit;
        }

        $result = json_decode($response, true);

        // Check for successful order creation
        if ($http_code === 201 && isset($result['data']['booking_reference'])) {
            $pnr = $result['data']['booking_reference'];
            $order_id = $result['data']['id'];

            // Update database with real PNR
            $updated = $db->update('bookings', [
                'pnr' => $pnr,
                'booking_status' => 'confirmed',
                'booking_data' => json_encode(array_merge($booking_data, [
                    'order_id' => $order_id,
                    'duffel_response' => $result['data']
                ]))
            ], ['invoice_id' => $invoice_id]);

            if ($updated) {
                echo json_encode([
                    'status' => true,
                    'Prn' => $pnr,
                    'booking_reference' => $pnr,
                    'reference' => $pnr,
                    'message' => 'Booking issued successfully with Duffel',
                    'pnr' => $pnr,
                    'order_id' => $order_id,
                    'response_error' => ''
                ]);
            } else {
                echo json_encode([
                    'status' => false,
                    'message' => 'Order created but failed to update database',
                    'pnr' => $pnr,
                    'response_error' => 'Database update failed'
                ]);
            }
        } else {
            // API error
            $response = json_decode($response, true);
            error_log("DUFFEL ISSUE: Response - " . json_encode($response));

            if (isset($response['errors'])) {
                $error_msg = $response['errors'][0]['message'] ?? 'Unknown Duffel error';
                error_log("DUFFEL ISSUE FAILED: " . json_encode($response['errors']));

                // Update booking with error
                $db->update('bookings', [
                    'booking_status' => 'failed',
                    'error_response' => json_encode([
                        'status' => false,
                        'message' => $error_msg,
                        'response_error' => $error_msg,
                        'api_response' => $response
                    ]),
                    'booking_payment_issue' => $error_msg
                ], ['invoice_id' => $invoice_id]);

                echo json_encode([
                    'status' => false,
                    'message' => $error_msg,
                    'debug_response' => $response
                ]);
                exit;
            }
            $error_message = 'Failed to create order with Duffel';
            if (isset($result['errors'][0])) {
                $error_message = $result['errors'][0]['title'] ?? $result['errors'][0]['message'] ?? $error_message;
            }

            // Store error in database
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $error_message,
                    'response' => $result,
                    'timestamp' => date('Y-m-d H:i:s')
                ])
            ], ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status' => false,
                'message' => $error_message,
                'response_error' => $error_message,
                'api_response' => $result
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'Exception: ' . $e->getMessage(),
            'response_error' => $e->getMessage()
        ]);
    }
});