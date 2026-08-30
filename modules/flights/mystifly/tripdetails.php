<?php
// path: modules/flights/mystifly/tripdetails.php
// Mystifly trip details route
// POST flights/mystifly/tripdetails

global $router;

$router->post('flights/mystifly/tripdetails', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId   = trim($_POST['invoice_id'] ?? '');
        $mfReference = trim($_POST['mf_reference'] ?? '');

        if (empty($invoiceId) && empty($mfReference)) {
            echo json_encode(['status' => false, 'message' => 'invoice_id or mf_reference is required']);
            exit;
        }

        $booking = null;
        if (!empty($invoiceId)) {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!$booking) {
                echo json_encode(['status' => false, 'message' => 'Booking not found']);
                exit;
            }
        }

        // Extract MF Reference
        if (empty($mfReference) && $booking) {
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            $mfReference = $bookingData['mf_reference']
                ?? $bookingData['MFRef']
                ?? $bookingData['UniqueID']
                ?? '';
        }

        if (empty($mfReference)) {
            echo json_encode(['status' => false, 'message' => 'MF Reference not found in booking data']);
            exit;
        }

        $credentials = getMystiflyCredentials($db);
        $target      = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';
        $debugMode   = $credentials['debug'] ?? false;

        $result = mystiflyApiRequest(
            $db,
            'api/TripDetails/' . urlencode($mfReference),
            [],
            'GET',
            $invoiceId ?: null
        );

        if (!$result['success']) {
            echo json_encode(['status' => false, 'message' => 'Failed to retrieve trip details.']);
            exit;
        }

        $data       = $result['data'] ?? [];
        $statusCode = $data['Status']['Code'] ?? '';

        if ($statusCode !== '001') {
            echo json_encode([
                'status'  => false,
                'message' => $data['Status']['Message'] ?? 'Trip details unavailable.',
                'data'    => null,
            ]);
            exit;
        }

        $tripDetails   = $data['Data']['TripDetailsResult']['TravelItinerary']
            ?? $data['TripDetailsResult']['TravelItinerary']
            ?? $data['TravelItinerary']
            ?? $data['BookingDetails']
            ?? $data;
        $bookingStatus = $data['Data']['TripDetailsResult']['BookingStatus']
            ?? $data['BookingStatus']
            ?? $tripDetails['BookingStatus']
            ?? 'Unknown';
        $ticketStatus  = $data['Data']['TripDetailsResult']['TicketStatus']
            ?? $data['TicketStatus']
            ?? $tripDetails['TicketStatus']
            ?? 'Unknown';
        $pnr           = $data['PNR'] ?? $tripDetails['PNR'] ?? '';
        $airlinePnr    = $data['AirlinePNR'] ?? $tripDetails['AirlinePNR'] ?? '';

        // Collect ticket numbers
        $ticketNumbers = [];
        $travellers    = $tripDetails['PassengerInfos'] ?? $tripDetails['Passengers'] ?? [];
        if (!empty($travellers) && !isset($travellers[0])) {
            $travellers = [$travellers];
        }
        foreach ($travellers as $pax) {
            if (!empty($pax['TicketNumber'])) {
                $ticketNumbers[] = $pax['TicketNumber'];
            }
            $eTickets = $pax['ETickets'] ?? ($pax['Passenger']['ETickets'] ?? []);
            if (!empty($eTickets) && !isset($eTickets[0])) {
                $eTickets = [$eTickets];
            }
            foreach ($eTickets as $ticket) {
                if (!empty($ticket['ETicketNumber'])) {
                    $ticketNumbers[] = $ticket['ETicketNumber'];
                }
            }
        }
        $ticketNumbers = array_values(array_unique($ticketNumbers));

        // -----------------------------------------------------------------------
        // Update booking in DB if invoice_id was provided
        // -----------------------------------------------------------------------
        if ($booking) {
            $existingBd = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
            $existingBd['raw_tripdetails']    = $data;
            $existingBd['booking_status_mf']  = $bookingStatus;
            $existingBd['ticket_status_mf']   = $ticketStatus;
            $existingBd['pnr']                = $pnr;
            $existingBd['airline_pnr']        = $airlinePnr;
            $existingBd['ticket_numbers']     = $ticketNumbers;

            // Map Mystifly booking status to PHP Travels booking_status
            $newStatus = $booking['booking_status'];
            $lowerStatus = strtolower($bookingStatus);
            if (strpos($lowerStatus, 'confirmed') !== false || strpos($lowerStatus, 'ticketed') !== false) {
                $newStatus = 'confirmed';
            } elseif (strpos($lowerStatus, 'cancel') !== false) {
                $newStatus = 'cancelled';
            }

            $db->update('bookings', [
                'booking_status'   => $newStatus,
                'pnr'              => $pnr ?: $booking['pnr'],
                'booking_data'     => json_encode($existingBd),
                'booking_response' => json_encode($data),
            ], ['invoice_id' => $invoiceId]);
        }

        // // -----------------------------------------------------------------------
        // // Build enriched flight-details summary for the log
        // // -----------------------------------------------------------------------
        // $segments = [];
        // $reservationItems = $tripDetails['ItineraryInfo']['ReservationItems']['Item']
        //     ?? $tripDetails['ItineraryInfo']['ReservationItems']
        //     ?? $tripDetails['ReservationItems']
        //     ?? [];
        // if (!empty($reservationItems) && !isset($reservationItems[0])) {
        //     $reservationItems = [$reservationItems];
        // }
        // foreach ($reservationItems as $item) {
        //     $segments[] = [
        //         'flight_no'        => ($item['OperatingAirlineCode'] ?? $item['MarketingAirlineCode'] ?? '')
        //                               . ($item['FlightNumber'] ?? ''),
        //         'airline'          => $item['OperatingAirlineCode'] ?? $item['MarketingAirlineCode'] ?? '',
        //         'departure'        => [
        //             'airport'  => $item['DepartureAirportLocationCode'] ?? '',
        //             'datetime' => $item['DepartureDateTime'] ?? '',
        //             'terminal' => $item['DepartureTerminal'] ?? '',
        //         ],
        //         'arrival'          => [
        //             'airport'  => $item['ArrivalAirportLocationCode'] ?? '',
        //             'datetime' => $item['ArrivalDateTime'] ?? '',
        //             'terminal' => $item['ArrivalTerminal'] ?? '',
        //         ],
        //         'cabin_class'      => $item['CabinClassCode'] ?? $item['ResBookDesigCode'] ?? '',
        //         'fare_basis'       => $item['FareBasisCode'] ?? '',
        //         'stop_quantity'    => $item['StopQuantity'] ?? 0,
        //         'equipment_type'   => $item['EquipmentType'] ?? '',
        //     ];
        // }

        // $passengerSummary = [];
        // foreach ($travellers as $pax) {
        //     $passengerSummary[] = [
        //         'name'          => trim(
        //             ($pax['PassengerTitle'] ?? '') . ' ' .
        //             ($pax['PassengerFirstName'] ?? $pax['FirstName'] ?? '') . ' ' .
        //             ($pax['PassengerLastName'] ?? $pax['LastName'] ?? '')
        //         ),
        //         'type'          => $pax['PassengerType'] ?? $pax['PaxType'] ?? '',
        //         'ticket_number' => $pax['TicketNumber'] ?? '',
        //         'passport'      => $pax['PassportNumber'] ?? '',
        //         'nationality'   => $pax['Nationality'] ?? '',
        //         'dob'           => $pax['DateOfBirth'] ?? '',
        //     ];
        // }

        // $priceSummary = [];
        // if ($booking) {
        //     $priceSummary = [
        //         'currency'       => $booking['currency_markup'] ?? $booking['currency'] ?? '',
        //         'total_paid'     => $booking['price_markup'] ?? 0,
        //         'base_fare'      => $booking['price_original'] ?? 0,
        //         'tax'            => $booking['tax'] ?? 0,
        //         'payment_status' => $booking['payment_status'] ?? '',
        //     ];
        // }

        $response = [
            'status'  => true,
            'message' => 'Trip details retrieved successfully.',
            'data'    => [
                'mf_reference'   => $mfReference,
                'booking_status' => $bookingStatus,
                'ticket_status'  => $ticketStatus,
                'pnr'            => $pnr,
                'airline_pnr'    => $airlinePnr,
                'ticket_numbers' => $ticketNumbers,
                'travellers'     => $travellers,
                'trip_details'   => $tripDetails,
            ],
        ];

        if ($debugMode) {
            $response['raw_response'] = $data;
        }

        echo json_encode($response);

    } catch (Throwable $e) {
        error_log('MYSTIFLY TRIPDETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.']);
    }
});
