<?php
/**
 * Wanderbeds BookingList + BookInfo helpers
 * POST stays/wanderbeds/bookinglist
 * POST stays/wanderbeds/bookinfo
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/wanderbeds/bookinglist', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $dateFrom = trim((string) ($input['datefrom'] ?? $input['date_from'] ?? ''));
        $dateTo = trim((string) ($input['dateto'] ?? $input['date_to'] ?? ''));
        if ($dateFrom === '' || $dateTo === '') {
            throw new Exception('datefrom and dateto are required (YYYY-MM-DD).');
        }

        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Wanderbeds module not configured');
        }

        $payload = [
            'datefrom' => $dateFrom,
            'dateto' => $dateTo,
        ];
        $result = wanderbedsCall($module, 'hotel/bookinglist', $payload, 'POST', 60);
        logApiCall(
            'BookingList',
            $payload,
            wanderbedsSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'bookinglist_' . date('Y-m-d')
        );

        if (!$result['success']) {
            throw new Exception(wanderbedsFormatError($result['error'] ?? 'BookingList failed'));
        }

        $bookings = $result['data']['data']['bookings']
            ?? $result['data']['bookings']
            ?? [];
        $normalized = [];
        foreach ((array) $bookings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wbStatus = strtoupper(trim((string) ($row['booking_status'] ?? $row['status'] ?? '')));
            $normalized[] = [
                'created' => $row['created'] ?? null,
                'hotelid' => $row['hotelid'] ?? null,
                'hotelname' => $row['hotelname'] ?? null,
                'checkin' => $row['checkin'] ?? null,
                'checkout' => $row['checkout'] ?? null,
                'roomindex' => $row['roomindex'] ?? null,
                'roomname' => $row['roomname'] ?? null,
                'booking_reference' => $row['booking_reference'] ?? null,
                'client_reference' => $row['client_reference'] ?? null,
                'reference' => $row['reference'] ?? null,
                'confirmation_number' => $row['confirmation_number'] ?? null,
                'booking_status' => $wbStatus,
                'platform_status' => wanderbedsMapPlatformStatus($wbStatus),
                'deadline' => $row['deadline'] ?? null,
                'pnr' => (string) ($row['booking_reference'] ?? ''),
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($normalized),
            'bookings' => $normalized,
            'raw' => $result['data'] ?? null,
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});

$router->post('stays/wanderbeds/bookinfo', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        $bookingRef = trim((string) ($input['booking_reference'] ?? ''));
        $clientRef = trim((string) ($input['client_reference'] ?? ''));

        $booking = null;
        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', [
                'invoice_id' => $invoiceId,
                'module' => 'wanderbeds',
            ]);
            if ($booking) {
                $bd = json_decode($booking['booking_data'] ?? '{}', true);
                if (!is_array($bd)) {
                    $bd = [];
                }
                if ($bookingRef === '') {
                    $bookingRef = (string) ($bd['wb_booking_reference'] ?? $booking['pnr'] ?? '');
                }
                if ($clientRef === '') {
                    $clientRef = (string) ($bd['wb_client_reference'] ?? '');
                }
            }
        }

        if ($bookingRef === '' && $clientRef === '') {
            throw new Exception('Provide booking_reference, client_reference, or invoice_id.');
        }

        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Wanderbeds module not configured');
        }

        $payload = [];
        if ($bookingRef !== '') {
            $payload['booking_reference'] = $bookingRef;
        }
        if ($clientRef !== '') {
            $payload['client_reference'] = $clientRef;
        }

        $result = wanderbedsCall($module, 'hotel/bookinfo', $payload, 'POST', 60);
        logApiCall(
            'BookInfo',
            $payload,
            wanderbedsSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'bookinfo_' . preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId !== '' ? $invoiceId : ($bookingRef ?: $clientRef))
        );

        if (!$result['success']) {
            throw new Exception(wanderbedsFormatError($result['error'] ?? 'BookInfo failed'));
        }

        $refs = wanderbedsExtractBookingRefs($result['data'] ?? []);

        if ($booking && !empty($booking['id']) && $refs['pnr'] !== '') {
            $bd = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bd)) {
                $bd = [];
            }
            $bd['wb_booking_reference'] = $refs['booking_reference'];
            $bd['wb_reference'] = $refs['reference'];
            $bd['wb_confirmation'] = $refs['confirmation_number'];
            $bd['wb_status'] = $refs['primary_status'];
            $bd['wb_room_refs'] = $refs['room_refs'];
            $bd['supplier_pnr'] = $refs['pnr'];
            $bd['wb_bookinfo'] = $result['data'] ?? null;

            $db->update('bookings', [
                'pnr' => $refs['pnr'],
                'booking_status' => $refs['platform_status'] === 'pending' ? 'pending' : (
                    $refs['platform_status'] === 'cancelled' ? 'cancelled' : (
                        $refs['platform_status'] === 'failed' ? 'failed' : 'confirmed'
                    )
                ),
                'booking_data' => json_encode($bd),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }

        echo json_encode([
            'success' => true,
            'pnr' => $refs['pnr'],
            'booking_reference' => $refs['booking_reference'],
            'reference' => $refs['reference'],
            'confirmation_number' => $refs['confirmation_number'],
            'client_reference' => $refs['client_reference'],
            'wb_status' => $refs['primary_status'],
            'booking_status' => $refs['platform_status'],
            'room_refs' => $refs['room_refs'],
            'data' => $result['data'] ?? null,
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});
