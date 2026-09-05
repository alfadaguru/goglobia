<?php
// path : modules/cars/cartrawler/void.php
// VOID CAR BOOKING ACTION FOR CARTRAWLER
//
// For a car rental there is no separate "void" transaction distinct from a
// cancellation — CarTrawler only exposes OTA_VehCancelRQ. This endpoint exists
// so the admin/lifecycle surface is uniform with flight modules; it performs
// the same supplier cancellation (OTA_VehCancelRQ) as cars/cartrawler/cancel.
@$SECURE or die('Access Denied!');

$router->post('cars/cartrawler/void', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided.', 'invoice_id' => $invoice_id]);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'cartrawler', 'type' => 'cars']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'CarTrawler module not configured']);
            exit;
        }

        $clientId    = $module['c1'] ?? '';
        $environment = (($module['dev_mode'] ?? '1') === '1') ? 'test' : 'live';
        if (empty($clientId)) {
            echo json_encode(['status' => false, 'message' => 'CarTrawler API credentials not configured']);
            exit;
        }

        $pnr = $booking['pnr'] ?? '';
        if (empty($pnr)) {
            echo json_encode(['status' => false, 'message' => 'PNR not found. Cannot void a booking without a PNR.']);
            exit;
        }

        $target  = ($environment === 'live') ? 'Production' : 'Test';
        $baseUrl = ($environment === 'live')
            ? 'https://ota.cartrawler.com/cartrawlerota'
            : 'https://external-dev.cartrawler.com/cartrawlerota';

        $xmlRequest = '<OTA_CancelRQ xmlns="http://www.opentravel.org/OTA/2003/05" Target="' . $target . '" Version="1.005">
  <POS>
    <Source>
      <RequestorID Type="16" ID="' . htmlspecialchars($clientId) . '" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <UniqueID Type="14" ID="' . htmlspecialchars($pnr) . '"/>
</OTA_CancelRQ>';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xmlRequest,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml', 'User-Agent: PHPTravels-v10/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $apiResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            echo json_encode(['status' => false, 'message' => 'API connection error: ' . $curlError]);
            exit;
        }

        $status        = false;
        $error_message = "CarTrawler API Error HTTP {$httpCode}";
        if ($httpCode === 200 && !empty($apiResponse)) {
            $xml = simplexml_load_string($apiResponse);
            if ($xml === false) {
                $error_message = 'Failed to parse XML response from supplier.';
            } elseif (isset($xml->Errors) && !empty($xml->Errors)) {
                $error_message = (string) ($xml->Errors->Error['ShortText'] ?? $xml->Errors->Error[0] ?? 'Unknown supplier error');
            } elseif (isset($xml->VehCancelRSCore)) {
                $resStatus = strtolower((string) ($xml->VehCancelRSCore['CancelStatus'] ?? ''));
                if ($resStatus === 'cancelled' || $resStatus === 'commit') {
                    $status = true;
                } else {
                    $error_message = 'Status was not updated to cancelled.';
                }
            } elseif (isset($xml->Success)) {
                $status = true;
            } else {
                $error_message = 'Invalid response structure from CarTrawler.';
            }
        }

        if ($status) {
            $db->update('bookings', [
                'booking_status'      => 'cancelled',
                'cancellation_request' => 1,
                'cancellation_status'  => 1,
            ], ['invoice_id' => $invoice_id]);
            echo json_encode(['status' => true, 'message' => 'Car booking voided (cancelled) successfully.']);
        } else {
            echo json_encode(['status' => false, 'message' => $error_message, 'api_response' => mb_substr((string) $apiResponse, 0, 1000)]);
        }

    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});
