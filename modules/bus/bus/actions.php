<?php
// ============================================================================
// LOCAL BUS — ADMIN BOOKING ACTIONS (Issue / Cancel / Void / Refund)
// Registered on the modules router. Invoked by the shared admin booking editor
// (app/views/admin/bookings/edit.php) via POST /modules/bus/bus/{action}.
// Local inventory only — no supplier API calls.
// ============================================================================

if (!function_exists('busModuleAction')) {
    function busModuleAction($db, $action)
    {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        // Issue follows the same server-to-server pattern as local modules;
        // destructive actions remain admin-only.
        if ($action !== 'issue' && empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['status' => false, 'success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        try {
            $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
            if ($invoiceId === '') throw new Exception('Invoice ID is required');

            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'bus']);
            if (!$booking) throw new Exception('Bus booking not found');

            $status = strtolower((string) $booking['booking_status']);

            // Return reserved seats to the route calendar (seats are only
            // decremented once a PNR is issued).
            $restoreSeats = function () use ($db, $booking) {
                if (empty($booking['pnr'])) return;
                $bd = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
                $pax = max(1, (int) ($booking['adults'] ?? 1) + (int) ($booking['childs'] ?? 0));
                $journeys = $bd['journeys'] ?? [[
                    'route_id' => (int)($bd['route_id'] ?? 0),
                    'date' => $bd['date'] ?? '',
                ]];
                foreach ($journeys as $journey) {
                    $routeId = (int)($journey['route_id'] ?? 0);
                    $travelDate = DateTime::createFromFormat('d-m-Y', (string)($journey['date'] ?? ''));
                    if (!$routeId || !$travelDate) continue;
                    $cal = $db->get('bus_routes_calendar', ['id', 'seats_available'], [
                        'route_id' => $routeId, 'date' => $travelDate->format('Y-m-d'),
                    ]);
                    if ($cal) {
                        $db->update('bus_routes_calendar', [
                            'seats_available' => (int) $cal['seats_available'] + $pax,
                        ], ['id' => $cal['id']]);
                    }
                }
            };

            switch ($action) {
                case 'issue':
                    if (in_array($status, ['cancelled', 'voided'], true)) {
                        throw new Exception('Cannot issue a ' . $status . ' booking');
                    }
                    $pnr = trim((string)($booking['pnr'] ?? ''));
                    if ($pnr !== '' && $pnr !== 'Not Issued') {
                        $db->update('bookings', ['booking_status' => 'confirmed', 'error_response' => null, 'booking_payment_issue' => null], ['invoice_id' => $invoiceId]);
                        echo json_encode(['status' => true, 'success' => true, 'Prn' => $pnr, 'booking_reference' => $pnr, 'reference' => $pnr, 'message' => 'Booking already issued', 'pnr' => $pnr, 'booking_status' => $booking['booking_status'], 'already_issued' => true, 'response_error' => '']);
                        exit;
                    }

                    $bookingData = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
                    $journeys = $bookingData['journeys'] ?? [['route_id' => (int)($bookingData['route_id'] ?? 0), 'date' => $bookingData['date'] ?? '']];
                    $pax = max(1, (int)($booking['adults'] ?? 1) + (int)($booking['childs'] ?? 0));
                    $calendarRows = [];
                    foreach ($journeys as $journey) {
                        $routeId = (int)($journey['route_id'] ?? 0);
                        $travelDate = DateTime::createFromFormat('d-m-Y', (string)($journey['date'] ?? ''));
                        if (!$routeId || !$travelDate) throw new Exception('Invalid bus journey details');
                        $calendar = $db->get('bus_routes_calendar', ['id', 'seats_available'], ['route_id' => $routeId, 'date' => $travelDate->format('Y-m-d')]);
                        if (!$calendar || (int)$calendar['seats_available'] < $pax) throw new Exception('One of the selected buses no longer has enough seats');
                        $calendarRows[] = $calendar;
                    }
                    $pnr = 'PNR' . strtoupper(substr(md5($invoiceId . time()), 0, 6));
                    foreach ($calendarRows as $calendar) {
                        $db->update('bus_routes_calendar', ['seats_available' => (int)$calendar['seats_available'] - $pax], ['id' => $calendar['id']]);
                    }
                    $db->update('bookings', [
                        'pnr' => $pnr, 'booking_status' => 'confirmed', 'error_response' => null, 'booking_payment_issue' => null,
                    ], ['invoice_id' => $invoiceId]);
                    echo json_encode(['status' => true, 'success' => true, 'Prn' => $pnr, 'booking_reference' => $pnr, 'reference' => $pnr, 'message' => 'Bus booking issued successfully', 'pnr' => $pnr, 'booking_status' => 'confirmed', 'invoice_id' => $invoiceId, 'response_error' => '']);
                    exit;

                case 'cancel':
                    if (in_array($status, ['cancelled', 'voided'], true)) {
                        throw new Exception('Booking is already ' . $status);
                    }
                    $restoreSeats();
                    $db->update('bookings', [
                        'booking_status' => 'cancelled',
                        'cancellation_status' => 1,
                        'cancellation_request' => 1,
                        'cancellation_response' => 'Cancelled by admin on ' . date('Y-m-d H:i:s'),
                    ], ['invoice_id' => $invoiceId]);
                    echo json_encode([
                        'status' => true, 'success' => true,
                        'message' => 'Booking cancelled successfully' . (!empty($booking['pnr']) ? ' and seats released.' : '.'),
                    ]);
                    exit;

                case 'void':
                    if (in_array($status, ['cancelled', 'voided'], true)) {
                        throw new Exception('Booking is already ' . $status);
                    }
                    $restoreSeats();
                    $db->update('bookings', ['booking_status' => 'voided'], ['invoice_id' => $invoiceId]);
                    echo json_encode(['status' => true, 'success' => true, 'message' => 'Booking voided successfully.']);
                    exit;

                case 'refund':
                    $pay = strtolower((string) $booking['payment_status']);
                    if ($pay === 'refunded') throw new Exception('Payment is already refunded');
                    if ($pay !== 'paid') throw new Exception('Only a paid booking can be refunded');
                    $db->update('bookings', [
                        'payment_status' => 'refunded', 'cancellation_status' => 1,
                    ], ['invoice_id' => $invoiceId]);
                    echo json_encode([
                        'status' => true, 'success' => true,
                        'message' => 'Payment marked as refunded. Process the actual refund on your payment gateway.',
                    ]);
                    exit;

                default:
                    throw new Exception('Unknown action');
            }
        } catch (\Throwable $e) {
            error_log('BUS ACTION (' . $action . ') ERROR | Invoice: ' . ($_POST['invoice_id'] ?? 'N/A') . ' | ' . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'status' => false, 'success' => false,
                'message' => $e->getMessage(), 'response_error' => $e->getMessage(),
            ]);
            exit;
        }
    }
}

// Register the action routes on the modules router.
global $router;
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    foreach (['issue', 'cancel', 'void', 'refund'] as $__busAction) {
        $router->post('bus/bus/' . $__busAction, function () use ($db, $__busAction) {
            busModuleAction($db, $__busAction);
        });
    }
}
