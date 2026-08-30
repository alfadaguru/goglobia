<?php
// path : modules/cars/rental/cartrawler/cancel.php
// CANCEL CAR BOOKING ACTION FOR CARTRAWLER
@$SECURE or die('Access Denied!');

$router->post('cars/cartrawler/cancel', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        $module = $db->get('modules', '*', [
            'name' => 'cartrawler',
            'type' => 'cars'
        ]);

        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'CarTrawler module not configured']);
            exit;
        }

        $clientId = $module['c1'] ?? '';
        $apiKey = $module['c2'] ?? ''; // TV
        $environment = ($module['dev_mode'] ?? '1') === '1' ? 'test' : 'live';

        if (empty($clientId) || empty($apiKey)) {
            echo json_encode(['status' => false, 'message' => 'CarTrawler API credentials not configured']);
            exit;
        }

        $pnr = $booking['pnr'] ?? '';
        if (empty($pnr)) {
            echo json_encode(['status' => false, 'message' => 'PNR not found. Cannot cancel a booking without a PNR.']);
            exit;
        }

        $target = ($environment === 'live') ? 'Production' : 'Test';

        // OTA_CancelRQ XML For CarTrawler
        $xmlRequest = '<OTA_CancelRQ xmlns="http://www.opentravel.org/OTA/2003/05" Target="' . $target . '" Version="1.005">
  <POS>
    <Source>
      <RequestorID Type="16" ID="' . htmlspecialchars($clientId) . '" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <UniqueID Type="14" ID="' . htmlspecialchars($pnr) . '"/>
</OTA_CancelRQ>';

        $baseUrl = ($environment === 'live') 
            ? 'https://ota.cartrawler.com/cartrawlerota' 
            : 'https://external-dev.cartrawler.com/cartrawlerota';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            echo json_encode([
                'status' => false,
                'message' => 'API connection error: ' . $curlError
            ]);
            exit;
        }

        $status = false;
        $error_message = '';

        if ($httpCode === 200 && !empty($apiResponse)) {
            $xml = simplexml_load_string($apiResponse);
            if ($xml === false) {
                $error_message = 'Failed to parse XML response from supplier.';
            } else {
                if (isset($xml->Errors) && !empty($xml->Errors)) {
                    $error_message = (string)($xml->Errors->Error['ShortText'] ?? $xml->Errors->Error[0] ?? 'Unknown supplier error');
                } else if (isset($xml->VehCancelRSCore->VehReservation)) {
                    $resStatus = (string)($xml->VehCancelRSCore['CancelStatus'] ?? '');
                    if (strtolower($resStatus) === 'cancelled' || strtolower($resStatus) === 'commit') {
                        $status = true;
                    } else {
                        $error_message = 'Status was not updated to cancelled.';
                    }
                } else {
                    // Try general OTA success
                    if (isset($xml->Success)) {
                        $status = true;
                    } else {
                        $error_message = 'Invalid response structure from CarTrawler.';
                    }
                }
            }
        } else {
            $error_message = "CarTrawler API Error HTTP {$httpCode}";
        }

        if ($status) {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'cancellation_request' => 1
            ], ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled successfully.'
            ]);
        } else {
            echo json_encode([
                'status' => false,
                'message' => $error_message,
                'api_response' => mb_substr($apiResponse, 0, 1000)
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ]);
    }
});
