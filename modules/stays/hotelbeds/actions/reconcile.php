<?php
// ============================================================================
// HOTELBEDS BOOKING RECONCILE — resolve timeout/unknown after issue
// ENDPOINTS:
//   POST /stays/hotelbeds/reconcile          { invoice_id, mark_failed_if_not_found? }
//   POST /stays/hotelbeds/reconcile_pending   { limit? } — batch/cron helper
// Guide: §38–§39, §42, §69
// ============================================================================

function hotelbedsSendReconcileJsonResponse(array $result): void
{
    if (ob_get_level()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'status' => !empty($result['success']),
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? '',
        'data' => $result['data'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function hotelbedsProcessReconcile($db, string $invoice_id, array $options = []): array
{
    $markFailedIfNotFound = !empty($options['mark_failed_if_not_found']);
    $invoice_id = trim($invoice_id);

    try {
        if ($invoice_id === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        if (strtolower((string)($booking['module'] ?? '')) !== 'hotelbeds') {
            throw new Exception('Reconcile is only supported for Hotelbeds stays bookings');
        }

        if (!empty($booking['pnr'])) {
            return [
                'success' => true,
                'message' => 'Booking already has a PNR — no reconcile needed',
                'data' => [
                    'invoice_id' => $invoice_id,
                    'pnr' => $booking['pnr'],
                    'already_confirmed' => true,
                ],
            ];
        }

        $moduleData = $db->get('modules', '*', [
            'name' => $booking['module'] ?? 'hotelbeds',
            'type' => $booking['module_type'] ?? 'stays',
        ]);

        if (!$moduleData) {
            throw new Exception('Hotelbeds module is not configured');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        $clientReference = function_exists('hotelbedsClientReference')
            ? hotelbedsClientReference($invoice_id)
            : ('INV-' . $invoice_id);

        $checkin = $bookingData['checkin'] ?? date('Y-m-d', strtotime('-7 days'));
        $checkout = $bookingData['checkout'] ?? date('Y-m-d', strtotime('+7 days'));
        $startDate = date('Y-m-d', strtotime($checkin . ' -2 days'));
        $endDate = date('Y-m-d', strtotime($checkout . ' +2 days'));

        $listQuery = [
            'start' => $startDate,
            'end' => $endDate,
            'from' => 1,
            'to' => 25,
            'clientReference' => $clientReference,
        ];

        if (!function_exists('hotelbedsBookingApiRequest')) {
            throw new Exception('Hotelbeds booking API helper is unavailable');
        }

        $listResponse = hotelbedsBookingApiRequest($moduleData, 'GET', 'bookings', $listQuery);
        if (!$listResponse['success']) {
            throw new Exception($listResponse['message'] ?? ('Booking list lookup failed (HTTP ' . ($listResponse['http_code'] ?? 0) . ')'));
        }

        $rows = hotelbedsNormalizeBookingListRows($listResponse['data']);
        $matched = null;

        foreach ($rows as $row) {
            $rowClientRef = $row['clientReference'] ?? $row['client_reference'] ?? null;
            if ($rowClientRef !== null && strcasecmp((string)$rowClientRef, $clientReference) !== 0) {
                continue;
            }

            $status = strtoupper((string)($row['status'] ?? ''));
            if (in_array($status, ['CANCELLED', 'VOIDED'], true)) {
                continue;
            }

            $matched = $row;
            break;
        }

        if ($matched === null && !empty($rows)) {
            foreach ($rows as $row) {
                $status = strtoupper((string)($row['status'] ?? ''));
                if (!in_array($status, ['CANCELLED', 'VOIDED'], true)) {
                    $matched = $row;
                    break;
                }
            }
        }

        if ($matched === null || empty($matched['reference'])) {
            if ($markFailedIfNotFound) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'type' => 'BOOKING_RECONCILE_NOT_FOUND',
                        'clientReference' => $clientReference,
                        'timestamp' => date('Y-m-d H:i:s'),
                        'message' => 'No matching Hotelbeds booking found for clientReference',
                    ]),
                ], ['invoice_id' => $invoice_id]);
            }

            return [
                'success' => false,
                'message' => 'No matching Hotelbeds booking found for ' . $clientReference,
                'data' => [
                    'invoice_id' => $invoice_id,
                    'clientReference' => $clientReference,
                    'found' => false,
                    'marked_failed' => $markFailedIfNotFound,
                ],
            ];
        }

        $reference = (string)$matched['reference'];
        $detailResponse = hotelbedsBookingApiRequest($moduleData, 'GET', 'bookings/' . rawurlencode($reference));
        $detailRows = hotelbedsNormalizeBookingListRows($detailResponse['data'] ?? []);
        $detailBooking = $detailRows[0] ?? $matched;
        $supplierStatus = strtoupper((string)($detailBooking['status'] ?? $matched['status'] ?? ''));

        if (in_array($supplierStatus, ['CANCELLED', 'VOIDED'], true)) {
            throw new Exception('Supplier booking exists but status is ' . $supplierStatus);
        }

        $db->update('bookings', [
            'booking_status' => 'confirmed',
            'pnr' => $reference,
            'booking_response' => $detailResponse['raw'] ?? json_encode($detailResponse['data'] ?? $matched),
            'error_response' => null,
        ], ['invoice_id' => $invoice_id]);

        return [
            'success' => true,
            'message' => 'Booking reconciled — PNR updated from Hotelbeds',
            'data' => [
                'invoice_id' => $invoice_id,
                'clientReference' => $clientReference,
                'pnr' => $reference,
                'supplier_status' => $supplierStatus,
                'found' => true,
            ],
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'data' => [
                'invoice_id' => $invoice_id ?: null,
                'error' => $e->getMessage(),
            ],
        ];
    }
}

function hotelbedsProcessReconcilePending($db, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $candidates = $db->select('bookings', [
        'invoice_id',
        'booking_status',
        'pnr',
        'module',
        'module_type',
        'error_response',
    ], [
        'module' => 'hotelbeds',
        'module_type' => 'stays',
        'ORDER' => ['id' => 'DESC'],
        'LIMIT' => 200,
    ]) ?: [];

    $processed = [];
    $count = 0;

    foreach ($candidates as $row) {
        if ($count >= $limit) {
            break;
        }

        if (!function_exists('hotelbedsBookingNeedsReconcile') || !hotelbedsBookingNeedsReconcile($row)) {
            continue;
        }

        $count++;
        $processed[] = array_merge(
            ['invoice_id' => $row['invoice_id']],
            hotelbedsProcessReconcile($db, (string)$row['invoice_id'], ['mark_failed_if_not_found' => false])
        );
    }

    return [
        'success' => true,
        'message' => 'Processed ' . count($processed) . ' reconcile candidate(s)',
        'data' => [
            'processed' => $processed,
            'count' => count($processed),
        ],
    ];
}

$router->post('stays/hotelbeds/reconcile', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    $markFailed = !empty($_POST['mark_failed_if_not_found']) || !empty($_POST['mark_failed']);
    hotelbedsSendReconcileJsonResponse(
        hotelbedsProcessReconcile($db, $_POST['invoice_id'] ?? '', [
            'mark_failed_if_not_found' => $markFailed,
        ])
    );
});

$router->post('stays/hotelbeds/reconcile_pending', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    $limit = (int)($_POST['limit'] ?? 25);
    hotelbedsSendReconcileJsonResponse(hotelbedsProcessReconcilePending($db, $limit));
});
