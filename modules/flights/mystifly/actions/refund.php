<?php
// path: modules/flights/mystifly/actions/refund.php
// Mystifly refund action
// POST /flights/mystifly/refund
@$SECURE or die('Access Denied!');

global $router;

function mystiflyRefundPassengerType($type)
{
    $type = strtoupper((string)$type);
    if (in_array($type, ['CHD', 'CNN', 'CHILD'], true)) {
        return 'CHD';
    }
    if (in_array($type, ['INF', 'INFANT'], true)) {
        return 'INF';
    }
    return 'ADT';
}

function mystiflyRefundTripDetailsPayload(array $raw)
{
    return $raw['Data']['TripDetailsResult']['TravelItinerary']
        ?? $raw['TripDetailsResult']['TravelItinerary']
        ?? $raw['TravelItinerary']
        ?? $raw['BookingDetails']
        ?? $raw;
}

function mystiflyBuildRefundPassengers(array $booking, array $bookingData)
{
    $passengers = [];
    $tripDetails = mystiflyRefundTripDetailsPayload($bookingData['raw_tripdetails'] ?? []);

    $tripPassengers = $tripDetails['PassengerInfos']
        ?? $tripDetails['Passengers']
        ?? $bookingData['PassengerInfos']
        ?? $bookingData['Passengers']
        ?? [];

    if (!empty($tripPassengers) && !isset($tripPassengers[0])) {
        $tripPassengers = [$tripPassengers];
    }

    foreach ($tripPassengers as $pax) {
        if (!is_array($pax)) {
            continue;
        }

        $passengerNode = is_array($pax['Passenger'] ?? null) ? $pax['Passenger'] : $pax;
        $nameNode      = is_array($passengerNode['PaxName'] ?? null) ? $passengerNode['PaxName'] : $passengerNode;
        $eTickets      = $pax['ETickets'] ?? $passengerNode['ETickets'] ?? [];
        if (!empty($eTickets) && !isset($eTickets[0])) {
            $eTickets = [$eTickets];
        }
        $firstTicket = $eTickets[0] ?? [];

        $firstName = trim((string)($nameNode['PassengerFirstName'] ?? $nameNode['FirstName'] ?? $nameNode['firstName'] ?? ''));
        $lastName  = trim((string)($nameNode['PassengerLastName'] ?? $nameNode['LastName'] ?? $nameNode['lastName'] ?? ''));
        $ticket    = trim((string)(
            $pax['TicketNumber']
            ?? $pax['ETicket']
            ?? $pax['eTicket']
            ?? $firstTicket['ETicketNumber']
            ?? $firstTicket['TicketNumber']
            ?? ''
        ));

        if ($firstName === '' && $lastName === '' && $ticket === '') {
            continue;
        }

        $passengers[] = [
            'firstName'     => $firstName,
            'lastName'      => $lastName,
            'title'         => trim((string)($nameNode['PassengerTitle'] ?? $nameNode['Title'] ?? $nameNode['title'] ?? 'Mr')),
            'eTicket'       => $ticket,
            'passengerType' => mystiflyRefundPassengerType($passengerNode['PassengerType'] ?? $passengerNode['PaxType'] ?? $passengerNode['passengerType'] ?? 'ADT'),
        ];
    }

    if (!empty($passengers)) {
        return $passengers;
    }

    $travellers = json_decode($booking['travellers'] ?? '{}', true) ?? [];
    $ticketNumbers = $bookingData['ticket_numbers'] ?? [];
    $ticketIndex = 0;

    foreach ($travellers as $paxKey => $pax) {
        if (!is_array($pax) || !preg_match('/^(adult|child|infant)_\d+$/', (string)$paxKey)) {
            continue;
        }

        $type = 'ADT';
        if (strpos((string)$paxKey, 'child_') === 0) {
            $type = 'CHD';
        } elseif (strpos((string)$paxKey, 'infant_') === 0) {
            $type = 'INF';
        }

        $passengers[] = [
            'firstName'     => trim((string)($pax['first_name'] ?? $pax['firstName'] ?? '')),
            'lastName'      => trim((string)($pax['last_name'] ?? $pax['lastName'] ?? '')),
            'title'         => trim((string)($pax['title'] ?? 'Mr')),
            'eTicket'       => trim((string)($pax['eTicket'] ?? $pax['ticket_number'] ?? $pax['TicketNumber'] ?? ($ticketNumbers[$ticketIndex] ?? ''))),
            'passengerType' => $type,
        ];
        $ticketIndex++;
    }

    return $passengers;
}

function mystiflyRefundPassengersHaveTickets(array $passengers)
{
    if (empty($passengers)) {
        return false;
    }

    foreach ($passengers as $passenger) {
        if (empty($passenger['eTicket'])) {
            return false;
        }
    }

    return true;
}

function mystiflyRefundPayloadData(array $response)
{
    return $response['Data'] ?? $response;
}

function mystiflyRefundExtractPtrId(array $response)
{
    $data = mystiflyRefundPayloadData($response);
    $ptrId = $data['PTRId']
        ?? $data['PtrId']
        ?? $response['PTRId']
        ?? $response['PtrId']
        ?? null;

    if (empty($ptrId)) {
        $message = (string)($data['Message'] ?? $response['Message'] ?? '');
        if (preg_match('/\bPTR\s+(\d+)\b/i', $message, $matches)) {
            $ptrId = $matches[1];
        }
    }

    return $ptrId;
}

function mystiflyRefundSupplierMessage(array $response, $fallback)
{
    $data = mystiflyRefundPayloadData($response);
    return $data['Status']['Message']
        ?? $response['Status']['Message']
        ?? $data['Message']
        ?? $response['Message']
        ?? $fallback;
}

function mystiflyRefundExtractAmount(array $response)
{
    $data = mystiflyRefundPayloadData($response);
    $candidates = [
        $data['RefundAmount'] ?? null,
        $data['Refund']['Amount'] ?? null,
        $data['RefundQuotes'][0]['RefundAmount'] ?? null,
        $data['RefundQuotes'][0]['Refund']['Amount'] ?? null,
        $response['RefundAmount'] ?? null,
        $response['Refund']['Amount'] ?? null,
    ];

    foreach ($candidates as $amount) {
        if ($amount !== null && $amount !== '') {
            return (float)$amount;
        }
    }

    return 0.0;
}

function mystiflyRefundExtractCurrency(array $response)
{
    $data = mystiflyRefundPayloadData($response);
    return $data['Currency']
        ?? $data['CurrencyCode']
        ?? $data['Refund']['CurrencyCode']
        ?? $data['RefundQuotes'][0]['Currency']
        ?? $data['RefundQuotes'][0]['CurrencyCode']
        ?? $data['RefundQuotes'][0]['Refund']['CurrencyCode']
        ?? $response['Currency']
        ?? $response['CurrencyCode']
        ?? 'USD';
}

$router->post('/flights/mystifly/refund', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = trim($_POST['invoice_id'] ?? '');

        if (empty($invoiceId)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        $credentials = getMystiflyCredentials($db);
        if (!$credentials) {
            echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.']);
            exit;
        }

        $target      = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];

        $mfReference = $bookingData['mf_reference']
            ?? $bookingData['MFRef']
            ?? $bookingData['UniqueID']
            ?? '';

        if (empty($mfReference)) {
            echo json_encode(['status' => false, 'message' => 'MF Reference not found. Cannot process refund via API.']);
            exit;
        }

        // Demo placeholder ref — skip API call, simulate successful refund
        if (str_starts_with($mfReference, 'PENDING-')) {
            $bookingData['refunded_at'] = date('Y-m-d H:i:s');
            $db->update('bookings', [
                'payment_status'      => 'refunded',
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'booking_data'        => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => true,
                'message' => 'Refund processed successfully.',
                'data'    => [
                    'invoice_id'     => $invoiceId,
                    'mf_reference'   => $mfReference,
                    'refund_amount'  => $booking['price_original'],
                    'currency'       => 'USD',
                    'payment_status' => 'refunded',
                ],
            ]);
            exit;
        }

        // Mark as refund_requested while we wait for supplier
        $bookingData['refund_requested_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', [
            'payment_status' => 'refund_requested',
            'booking_data'   => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId]);

        $passengers = mystiflyBuildRefundPassengers($booking, $bookingData);
        if (!mystiflyRefundPassengersHaveTickets($passengers)) {
            $tripResult = mystiflyApiRequest($db, 'api/TripDetails/' . urlencode($mfReference), [], 'GET', $invoiceId);

            if (!empty($tripResult['data'])) {
                $tripData = $tripResult['data'];
                $bookingData['raw_tripdetails'] = $tripData;
                $tripDetails = mystiflyRefundTripDetailsPayload($tripData);
                $tripPassengers = $tripDetails['PassengerInfos'] ?? $tripDetails['Passengers'] ?? [];
                if (!empty($tripPassengers) && !isset($tripPassengers[0])) {
                    $tripPassengers = [$tripPassengers];
                }

                $ticketNumbers = [];
                foreach ($tripPassengers as $pax) {
                    $eTickets = $pax['ETickets'] ?? ($pax['Passenger']['ETickets'] ?? []);
                    if (!empty($eTickets) && !isset($eTickets[0])) {
                        $eTickets = [$eTickets];
                    }
                    foreach ($eTickets as $ticket) {
                        if (!empty($ticket['ETicketNumber'])) {
                            $ticketNumbers[] = $ticket['ETicketNumber'];
                        }
                    }
                    if (!empty($pax['TicketNumber'])) {
                        $ticketNumbers[] = $pax['TicketNumber'];
                    }
                }
                $bookingData['ticket_numbers'] = array_values(array_unique($ticketNumbers));

                $db->update('bookings', ['booking_data' => json_encode($bookingData)], ['invoice_id' => $invoiceId]);
                $passengers = mystiflyBuildRefundPassengers($booking, $bookingData);
            }
        }

        if (empty($passengers)) {
            echo json_encode(['status' => false, 'message' => 'Passenger details not found. Cannot process refund via API.']);
            exit;
        }

        if (!mystiflyRefundPassengersHaveTickets($passengers)) {
            echo json_encode(['status' => false, 'message' => 'Passenger eTicket not found after Trip Details refresh.']);
            exit;
        }

        // Step 1: create Mystifly PTR Refund request.
        $refundPayload = [
            'ptrType'    => 'Refund',
            'mFRef'      => $mfReference,
            'passengers' => $passengers,
            'Target'     => $target,
        ];
        $result = mystiflyApiRequest($db, 'api/PostTicketingRequest', $refundPayload, 'POST', $invoiceId);

        $data       = $result['data'] ?? [];
        $resultData = mystiflyRefundPayloadData($data);
        $statusCode = $resultData['Status']['Code'] ?? $data['Status']['Code'] ?? '';
        $ptrId      = mystiflyRefundExtractPtrId($data);
        $supplierMessage = mystiflyRefundSupplierMessage($data, 'Refund could not be processed automatically. Marked as pending.');

        if (stripos($supplierMessage, 'already in process') !== false) {
            $bookingData['refund_response'] = $data;
            if (!empty($ptrId)) {
                $bookingData['refund_ptr_id'] = $ptrId;
            }
            $db->update('bookings', [
                'payment_status'        => 'refund_pending',
                'cancellation_response' => json_encode($data),
                'booking_data'          => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => $supplierMessage,
                'data'    => ['payment_status' => 'refund_pending', 'ptr_id' => $ptrId],
            ]);
            exit;
        }

        $apiOk      = $result['success'] && (
            ($data['Success'] ?? false) === true
            || ($resultData['Success'] ?? false) === true
            || $statusCode === '001'
            || !empty($ptrId)
        );

        if (!$apiOk) {
            // Mark as refund_pending – not failed, may still go through manually
            $bookingData['refund_response'] = $data;
            $db->update('bookings', [
                'payment_status'        => 'refund_pending',
                'cancellation_response' => json_encode($data),
                'booking_data'          => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => $supplierMessage,
                'data'    => ['payment_status' => 'refund_pending', 'ptr_id' => $ptrId],
            ]);
            exit;
        }

        if (empty($ptrId)) {
            $bookingData['refund_response'] = $data;
            $db->update('bookings', [
                'payment_status'        => 'refund_pending',
                'cancellation_response' => json_encode($data),
                'booking_data'          => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => mystiflyRefundSupplierMessage($data, 'Refund request submitted but PTRId was not returned by Mystifly.'),
                'data'    => ['payment_status' => 'refund_pending'],
            ]);
            exit;
        }

        // Step 2: search PTR Refund status/details using the PTRId returned above.
        $searchPtrType = $resultData['PTRType'] ?? $data['PTRType'] ?? 'Refund';
        $searchMfReference = $resultData['MFRef'] ?? $data['MFRef'] ?? $mfReference;
        $refundSearchPayload = [
            'ptrType' => $searchPtrType,
            'MFRef'   => $searchMfReference,
            'PTRId'   => (int)$ptrId,
            'Page'    => 1,
        ];
        $searchResult = mystiflyApiRequest($db, 'api/Search/PostTicketingRequest', $refundSearchPayload, 'POST', $invoiceId);
        $searchData   = $searchResult['data'] ?? [];

        $refundAmount   = mystiflyRefundExtractAmount($searchData) ?: mystiflyRefundExtractAmount($data);
        $refundCurrency = mystiflyRefundExtractCurrency($searchData);

        $bookingData['refund_response'] = $data;
        $bookingData['refund_search_response'] = $searchData;
        $bookingData['refund_ptr_id'] = $ptrId;
        $bookingData['refund_ptr_type'] = $searchPtrType;
        $bookingData['refunded_at']     = date('Y-m-d H:i:s');

        // The Mystifly supplier PTR refund succeeded above. Now reverse the
        // CUSTOMER's charge via the payment gateway; only mark 'refunded' if that
        // gateway refund actually goes through (was DB-flip only).
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $gwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Mystifly flight refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $bookingData['gateway_refund'] = $gwRefund;
        $mfGatewayRefunded = ($gwRefund['status'] === 'refunded');

        $db->update('bookings', [
            'payment_status'        => $mfGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode(['supplier' => $data, 'gateway_refund' => $gwRefund]),
            'booking_data'          => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => 'Refund request submitted successfully. PTR ID: ' . $ptrId . '.',
            'data'    => [
                'invoice_id'     => $invoiceId,
                'mf_reference'   => $mfReference,
                'ptr_id'         => $ptrId,
                'refund_amount'  => $refundAmount,
                'currency'       => $refundCurrency,
                'payment_status' => 'refunded',
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY REFUND ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please contact support.']);
    }
});
