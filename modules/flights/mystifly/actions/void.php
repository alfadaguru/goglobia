<?php
// path: modules/flights/mystifly/actions/void.php
// Mystifly void booking action
// POST /flights/mystifly/void
@$SECURE or die('Access Denied!');

global $router;

function mystiflyVoidPassengerType($type)
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

function mystiflyVoidTripDetailsPayload(array $raw)
{
    return $raw['Data']['TripDetailsResult']['TravelItinerary']
        ?? $raw['TripDetailsResult']['TravelItinerary']
        ?? $raw['TravelItinerary']
        ?? $raw['BookingDetails']
        ?? $raw;
}

function mystiflyBuildVoidPassengers(array $booking, array $bookingData)
{
    $passengers = [];
    $tripDetails = mystiflyVoidTripDetailsPayload($bookingData['raw_tripdetails'] ?? []);

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
            'passengerType' => mystiflyVoidPassengerType($passengerNode['PassengerType'] ?? $passengerNode['PaxType'] ?? $passengerNode['passengerType'] ?? 'ADT'),
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

function mystiflyVoidPassengersHaveTickets(array $passengers)
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

function mystiflyVoidPayloadData(array $response)
{
    return $response['Data'] ?? $response;
}

function mystiflyVoidExtractPtrId(array $response)
{
    $data = mystiflyVoidPayloadData($response);
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

function mystiflyVoidSupplierMessage(array $response, $fallback)
{
    $data = mystiflyVoidPayloadData($response);
    return $data['Status']['Message']
        ?? $response['Status']['Message']
        ?? $data['Message']
        ?? $response['Message']
        ?? $fallback;
}

function mystiflyVoidSuccessMessage(array $createResponse, array $searchResponse, $ptrId)
{
    $message = 'Void request submitted successfully.';

    if (!empty($ptrId)) {
        $message .= ' PTR ID: ' . $ptrId . '.';
    }

    return $message;
}

$router->post('/flights/mystifly/void', function () use ($db) {
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
            echo json_encode(['status' => false, 'message' => 'MF Reference not found. Cannot void via API.']);
            exit;
        }

        if (str_starts_with($mfReference, 'PENDING-')) {
            $bookingData['voided_at'] = date('Y-m-d H:i:s');
            $db->update('bookings', [
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'booking_data'        => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => true,
                'message' => 'Booking voided successfully.',
                'data'    => ['invoice_id' => $invoiceId, 'mf_reference' => $mfReference],
            ]);
            exit;
        }

        $passengers = mystiflyBuildVoidPassengers($booking, $bookingData);
        if (!mystiflyVoidPassengersHaveTickets($passengers)) {
            $tripResult = mystiflyApiRequest($db, 'api/TripDetails/' . urlencode($mfReference), [], 'GET', $invoiceId);

            if (!empty($tripResult['data'])) {
                $tripData = $tripResult['data'];
                $bookingData['raw_tripdetails'] = $tripData;
                $tripDetails = mystiflyVoidTripDetailsPayload($tripData);
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
                $passengers = mystiflyBuildVoidPassengers($booking, $bookingData);
            }
        }

        if (empty($passengers)) {
            echo json_encode(['status' => false, 'message' => 'Passenger details not found. Cannot void via API.']);
            exit;
        }

        if (!mystiflyVoidPassengersHaveTickets($passengers)) {
            echo json_encode(['status' => false, 'message' => 'Passenger eTicket not found after Trip Details refresh.']);
            exit;
        }

        $voidPayload = [
            'ptrType'    => 'Void',
            'mFRef'      => $mfReference,
            'passengers' => $passengers,
            'Target'     => $target,
        ];

        $result = mystiflyApiRequest($db, 'api/PostTicketingRequest', $voidPayload, 'POST', $invoiceId);

        $data       = $result['data'] ?? [];
        $resultData = mystiflyVoidPayloadData($data);
        $statusCode = $resultData['Status']['Code'] ?? $data['Status']['Code'] ?? '';
        $ptrId      = mystiflyVoidExtractPtrId($data);

        $apiOk = $result['success'] && (
            ($data['Success'] ?? false) === true
            || ($resultData['Success'] ?? false) === true
            || $statusCode === '001'
            || !empty($ptrId)
        );

        if (!$apiOk) {
            echo json_encode([
                'status'  => false,
                'message' => mystiflyVoidSupplierMessage($data, 'Void failed. Please contact support.'),
            ]);
            exit;
        }

        if (empty($ptrId)) {
            $bookingData['void_response'] = $data;
            $db->update('bookings', [
                'cancellation_response' => json_encode($data),
                'booking_data'          => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => mystiflyVoidSupplierMessage($data, 'Void request submitted but PTRId was not returned by Mystifly.'),
            ]);
            exit;
        }

        $searchPtrType = $resultData['PTRType'] ?? $data['PTRType'] ?? 'Void';
        $searchMfReference = $resultData['MFRef'] ?? $data['MFRef'] ?? $mfReference;
        $voidSearchPayload = [
            'ptrType' => $searchPtrType,
            'MFRef'   => $searchMfReference,
            'PTRId'   => (int)$ptrId,
            'Page'    => 1,
        ];
        $searchResult = mystiflyApiRequest($db, 'api/Search/PostTicketingRequest', $voidSearchPayload, 'POST', $invoiceId);
        $searchData   = $searchResult['data'] ?? [];

        $bookingData['void_response'] = $data;
        $bookingData['void_search_response'] = $searchData;
        $bookingData['void_ptr_id'] = $ptrId;
        $bookingData['void_ptr_type'] = $searchPtrType;
        $bookingData['voided_at']     = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($data),
            'booking_data'          => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => mystiflyVoidSuccessMessage($data, $searchData, $ptrId),
            'data'    => [
                'invoice_id'   => $invoiceId,
                'mf_reference' => $mfReference,
                'ptr_id'       => $ptrId,
                'ptr_status'   => $searchData['Data']['PTRDetail'][0]['PTRStatus']
                    ?? $searchData['PTRDetail'][0]['PTRStatus']
                    ?? $resultData['PTRStatus']
                    ?? null,
                'sla_minutes'  => $resultData['SLAInMinutes'] ?? null,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY VOID ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please contact support.']);
    }
});
