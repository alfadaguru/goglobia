<?php
// modules/cars/cartrawler/issue.php
// CARTRAWLER CAR BOOKING ISSUE ENDPOINT
// Called by payment-gateway.php via HTTP POST after successful payment

@$SECURE or die('Access Denied!');

$router->post('cars/cartrawler/issue', function() use ($db) {

header('Content-Type: application/json');

// Clean output buffer to ensure pure JSON
while (ob_get_level()) {
    ob_end_clean();
}

try {
    $invoice_id = $_POST['invoice_id'] ?? '';

    if (empty($invoice_id)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required', 'response_error' => 'Invoice ID required']);
        exit;
    }

    // Fetch booking
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found', 'response_error' => 'Booking not found']);
        exit;
    }

    // Get module credentials
    $module = $db->get('modules', '*', ['name' => 'cartrawler', 'type' => 'cars']);
    if (!$module) {
        echo json_encode(['status' => false, 'message' => 'CarTrawler module not configured', 'response_error' => 'Module not configured']);
        exit;
    }

    $clientId   = $module['c1'] ?? '';
    $environment = ($module['dev_mode'] ?? '1') === '1' ? 'test' : 'live';
    $currency   = !empty($module['currency']) ? $module['currency'] : 'USD';

    if (empty($clientId)) {
        echo json_encode(['status' => false, 'message' => 'CarTrawler Client ID not configured', 'response_error' => 'Missing Client ID']);
        exit;
    }

    // Decode booking data
    $booking_data = json_decode($booking['booking_data'] ?? '{}', true);
    $car_data     = is_array($booking_data['car_data'] ?? null) ? $booking_data['car_data'] : [];
    $search_params = is_array($booking_data['search_params'] ?? null) ? $booking_data['search_params'] : [];

    $pickup_location  = (string)($car_data['pickup_location']  ?? '');
    $dropoff_location = (string)($car_data['dropoff_location'] ?? $pickup_location);
    $pickup_datetime  = (string)($car_data['pickup_datetime']  ?? '');
    $dropoff_datetime = (string)($car_data['dropoff_datetime'] ?? '');
    $vendor_code      = trim((string)($car_data['vendor_code'] ?? ''));
    $reference_id     = trim((string)($car_data['reference_id'] ?? ''));

    // LocationCode must be IATA (e.g. BCN). Prefer explicit codes from search_params
    // when car_data still has a city/airport label from AI trip params.
    $isIata = static function ($code): bool {
        return (bool) preg_match('/^[A-Za-z]{3}$/', trim((string)$code));
    };
    $pickupCode = (string)($search_params['pickup_code'] ?? ($car_data['pickup_code'] ?? ''));
    $dropoffCode = (string)($search_params['dropoff_code'] ?? ($car_data['dropoff_code'] ?? $pickupCode));
    if ($isIata($pickupCode)) {
        $pickup_location = strtoupper($pickupCode);
    } elseif ($isIata($pickup_location)) {
        $pickup_location = strtoupper($pickup_location);
    }
    if ($isIata($dropoffCode)) {
        $dropoff_location = strtoupper($dropoffCode);
    } elseif ($isIata($dropoff_location)) {
        $dropoff_location = strtoupper($dropoff_location);
    } else {
        $dropoff_location = $pickup_location;
    }

    if ($reference_id === '') {
        error_log("CARTRAWLER ISSUE ERROR: Missing reference_id for Invoice " . $invoice_id);
        echo json_encode(['status' => false, 'message' => 'Reference ID missing from car booking data', 'response_error' => 'Missing reference_id']);
        exit;
    }
    if (!$isIata($pickup_location) || !$isIata($dropoff_location)) {
        error_log("CARTRAWLER ISSUE ERROR: Non-IATA location for Invoice {$invoice_id}: pickup={$pickup_location} dropoff={$dropoff_location}");
        echo json_encode([
            'status' => false,
            'message' => 'Pickup/dropoff must be IATA airport codes (e.g. BCN).',
            'response_error' => 'Invalid LocationCode',
        ]);
        exit;
    }

    // Fallback for missing datetimes from search_params
    if ($pickup_datetime === '' || $dropoff_datetime === '') {
        $p_date = (string)($search_params['pickup_date'] ?? ''); // DD-MM-YYYY
        $p_time = (string)($search_params['pickup_time'] ?? '10:00');
        $d_date = (string)($search_params['return_date'] ?? ($search_params['dropoff_date'] ?? '')); // DD-MM-YYYY
        $d_time = (string)($search_params['return_time'] ?? ($search_params['dropoff_time'] ?? '10:00'));

        if ($p_date !== '' && $d_date !== '') {
            $p_parts = explode('-', $p_date);
            $d_parts = explode('-', $d_date);
            if (count($p_parts) === 3 && count($d_parts) === 3) {
                // Support both DD-MM-YYYY and YYYY-MM-DD
                if (strlen($p_parts[0]) === 4) {
                    if ($pickup_datetime === '') $pickup_datetime = "{$p_parts[0]}-{$p_parts[1]}-{$p_parts[2]}T{$p_time}:00";
                    if ($dropoff_datetime === '') $dropoff_datetime = "{$d_parts[0]}-{$d_parts[1]}-{$d_parts[2]}T{$d_time}:00";
                } else {
                    if ($pickup_datetime === '') $pickup_datetime = "{$p_parts[2]}-{$p_parts[1]}-{$p_parts[0]}T{$p_time}:00";
                    if ($dropoff_datetime === '') $dropoff_datetime = "{$d_parts[2]}-{$d_parts[1]}-{$d_parts[0]}T{$d_time}:00";
                }
            }
        }
    }

    if ($pickup_datetime === '' || $dropoff_datetime === '') {
        error_log("CARTRAWLER ISSUE ERROR: Missing dateTime for Invoice " . $invoice_id);
        echo json_encode(['status' => false, 'message' => 'Pickup/Dropoff Date/Time missing', 'response_error' => 'Missing dateTime']);
        exit;
    }

    $firstName = trim((string)($booking['first_name'] ?? 'Guest')) ?: 'Guest';
    $lastName  = trim((string)($booking['last_name'] ?? 'User')) ?: 'User';
    $email     = trim((string)($booking['email'] ?? 'guest@example.com')) ?: 'guest@example.com';
    $phone     = preg_replace('/[^\d+]/', '', (string)($booking['phone'] ?? '')) ?: '1234567890';

    // Country of residence MUST match the availability search (OTA_VehAvailRateRQ driver_country).
    // Prefer search_params first — changing it at issue time fails the quote.
    // Never use phone dial codes (e.g. "01") for CitizenCountryName.
    $isoCountry = static function ($value): string {
        $v = strtoupper(trim((string)$value));
        return preg_match('/^[A-Z]{2}$/', $v) ? $v : '';
    };
    $country = '';
    $candidates = [
        $search_params['driver_country'] ?? '',
        $car_data['driver_country'] ?? '',
        $booking['nationality'] ?? '',
        $booking_data['guest_details']['primary_guest']['nationality'] ?? '',
        $booking_data['guest']['nationality'] ?? '',
    ];
    $travellersRaw = $booking['travellers'] ?? null;
    if (is_string($travellersRaw) && $travellersRaw !== '') {
        $travellersDecoded = json_decode($travellersRaw, true);
        if (is_array($travellersDecoded)) {
            $candidates[] = $travellersDecoded['passengers']['adult_0']['nationality'] ?? '';
            $candidates[] = $travellersDecoded['primary_guest']['nationality'] ?? '';
        }
    } elseif (is_array($travellersRaw)) {
        $candidates[] = $travellersRaw['passengers']['adult_0']['nationality'] ?? '';
        $candidates[] = $travellersRaw['primary_guest']['nationality'] ?? '';
    }
    $candidates[] = $booking['country_code'] ?? '';
    $candidates[] = $booking['country'] ?? '';
    foreach ($candidates as $cand) {
        $resolved = $isoCountry($cand);
        if ($resolved !== '') {
            $country = $resolved;
            break;
        }
    }
    if ($country === '') {
        $country = 'US';
    }

    // Driver age must match availability search (CarTrawler error 10015 if missing)
    $driverAge = (int)($search_params['driver_age'] ?? ($car_data['driver_age'] ?? 30));
    if ($driverAge < 18 || $driverAge > 99) {
        $driverAge = 30;
    }

    // Address is mandatory (CarTrawler error 319 "Required data missing: address")
    $guest = is_array($booking_data['guest_details'] ?? null)
        ? $booking_data['guest_details']
        : (is_array($booking_data['guest'] ?? null) ? $booking_data['guest'] : []);
    if (isset($guest['primary_guest']) && is_array($guest['primary_guest'])) {
        $guest = $guest['primary_guest'];
    }
    $street = trim((string)(
        $booking['address']
        ?? ($guest['address'] ?? ($guest['street'] ?? ($booking_data['address'] ?? '')))
    ));
    $city = trim((string)(
        $guest['city']
        ?? ($booking_data['city'] ?? ($search_params['pickup_location'] ?? 'City'))
    ));
    // Strip " Airport" for city display when we only have airport labels
    $city = trim(preg_replace('/\s+Airport$/i', '', $city) ?? $city) ?: 'City';
    $postal = trim((string)($guest['postal_code'] ?? ($guest['zip'] ?? ($booking_data['postal_code'] ?? ''))));
    $state = trim((string)($guest['state'] ?? ($guest['province'] ?? '')));
    if ($street === '') {
        $street = '1 Traveller Street';
    }
    if ($postal === '' || !preg_match('/^[A-Za-z0-9 \-]{2,12}$/', $postal)) {
        $postal = '00000';
    }

    $consumerIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($consumerIP === '::1' || $consumerIP === '') {
        $consumerIP = '127.0.0.1';
    }
    $target = ($environment === 'live') ? 'Production' : 'Test';

    // CarTrawler requires <Reference> from OTA_VehAvailRateRS (not UniqueID).
    // UniqueID is for looking up an existing reservation; booking a quote needs Reference.
    $vendorPrefXml = $vendor_code !== ''
        ? "\n    <VendorPref CompanyShortName=\"" . htmlspecialchars($vendor_code) . "\"/>"
        : '';
    $stateXml = $state !== ''
        ? "\n          <StateProv StateCode=\"" . htmlspecialchars(strtoupper(substr($state, 0, 3))) . "\">" . htmlspecialchars($state) . "</StateProv>"
        : '';

    // Build OTA_VehResRQ XML
    $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
<OTA_VehResRQ xmlns="http://www.opentravel.org/OTA/2003/05"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.opentravel.org/OTA/2003/05 OTA_VehResRQ.xsd"
    Version="1.005" Target="' . $target . '">
  <POS>
    <Source ISOCurrency="' . $currency . '">
      <RequestorID ID="' . htmlspecialchars($clientId) . '" Type="16" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <VehResRQCore>
    <VehRentalCore PickUpDateTime="' . htmlspecialchars($pickup_datetime) . '" ReturnDateTime="' . htmlspecialchars($dropoff_datetime) . '">
      <PickUpLocation LocationCode="' . htmlspecialchars($pickup_location) . '" CodeContext="IATA"/>
      <ReturnLocation LocationCode="' . htmlspecialchars($dropoff_location) . '" CodeContext="IATA"/>
    </VehRentalCore>
    <Customer>
      <Primary>
        <PersonName>
          <GivenName>' . htmlspecialchars($firstName) . '</GivenName>
          <Surname>' . htmlspecialchars($lastName) . '</Surname>
        </PersonName>
        <Telephone PhoneNumber="' . htmlspecialchars($phone) . '" PhoneTechType="1"/>
        <Email>' . htmlspecialchars($email) . '</Email>
        <Address>
          <StreetNmbr>' . htmlspecialchars($street) . '</StreetNmbr>
          <CityName>' . htmlspecialchars($city) . '</CityName>
          <PostalCode>' . htmlspecialchars($postal) . '</PostalCode>' . $stateXml . '
          <CountryName Code="' . htmlspecialchars($country) . '"/>
        </Address>
        <CitizenCountryName Code="' . htmlspecialchars($country) . '"/>
      </Primary>
    </Customer>' . $vendorPrefXml . '
    <Reference ID="' . htmlspecialchars($reference_id) . '" Type="16"/>
    <DriverType Age="' . (int)$driverAge . '"/>
  </VehResRQCore>
  <VehResRQInfo>
    <TPA_Extensions>
      <ConsumerIP>' . htmlspecialchars($consumerIP) . '</ConsumerIP>
    </TPA_Extensions>
  </VehResRQInfo>
</OTA_VehResRQ>';

    // Log request for debugging
    // @file_put_contents('/tmp/cartrawler_issue_request.log', date('Y-m-d H:i:s') . " | Invoice: {$invoice_id}\n" . $xmlRequest . "\n---\n", FILE_APPEND);

    // API endpoint
    $baseUrl = ($environment === 'live')
        ? 'https://ota.cartrawler.com/cartrawlerota'
        : 'https://external-dev.cartrawler.com/cartrawlerota';

    // cURL call
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xmlRequest,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml',
            'User-Agent: PHPTravels-v10/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $apiResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    // Log response
    // @file_put_contents('/tmp/cartrawler_issue_response.log', date('Y-m-d H:i:s') . " | HTTP: {$httpCode}\n" . $apiResponse . "\n---\n", FILE_APPEND);

    if (!empty($curlError)) {
        echo json_encode([
            'status'         => false,
            'message'        => 'cURL error: ' . $curlError,
            'response_error' => $curlError
        ]);
        exit;
    }

    // Parse XML Response
    $pnr     = '';
    $success = false;
    $errMsg  = '';

    if ($httpCode === 200 && !empty($apiResponse)) {
        $xml = @simplexml_load_string($apiResponse);
        if ($xml === false) {
            $errMsg = 'Failed to parse XML response from CarTrawler.';
        } else {
            $rootName = $xml->getName();
            if ($rootName === 'OTA_ErrorRS') {
                $errMsg = (string)($xml['ErrorMessage'] ?? $xml['ShortText'] ?? 'CarTrawler returned an error.');
            } elseif (isset($xml->Errors)) {
                $errMsg = (string)($xml->Errors->Error['ShortText'] ?? $xml->Errors->Error ?? 'Supplier error.');
            } elseif (isset($xml->VehResRSCore->VehReservation)) {
                $res = $xml->VehResRSCore->VehReservation;
                $pnr = (string)($res->VehSegmentCore->ConfID['ID'] ?? '');
                if (empty($pnr)) {
                    $pnr = (string)($res->VehResRQInfo->Reference['ID'] ?? '');
                }
                if (!empty($pnr)) {
                    $success = true;
                } else {
                    $errMsg = 'Booking placed but PNR not found in response.';
                }
            } else {
                $errMsg = 'Unexpected response structure from CarTrawler.';
            }
        }
    } else {
        $errMsg = "CarTrawler API HTTP Error: {$httpCode}";
    }

    if ($success) {
        // Update booking with PNR
        $db->update('bookings', [
            'pnr'            => $pnr,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'booking_data'   => json_encode(array_merge($booking_data, [
                'cartrawler_pnr'  => $pnr,
                'issue_timestamp' => date('Y-m-d H:i:s')
            ]))
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'            => true,
            'Prn'               => $pnr,
            'pnr'               => $pnr,
            'booking_reference' => $pnr,
            'reference'         => $pnr,
            'message'           => 'Car booking confirmed with CarTrawler. PNR: ' . $pnr,
            'response_error'    => ''
        ]);
    } else {
        // Save error
        $db->update('bookings', [
            'error_response' => json_encode([
                'error'     => $errMsg,
                'response'  => mb_substr($apiResponse ?? '', 0, 500),
                'timestamp' => date('Y-m-d H:i:s')
            ])
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'         => false,
            'message'        => $errMsg,
            'response_error' => $errMsg
        ]);
    }

} catch (Exception $e) {
    error_log('CARTRAWLER ISSUE ERROR: ' . $e->getMessage());
    echo json_encode([
        'status'         => false,
        'message'        => 'Exception: ' . $e->getMessage(),
        'response_error' => $e->getMessage()
    ]);
    exit;
}

});
