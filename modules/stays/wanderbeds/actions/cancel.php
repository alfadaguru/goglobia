<?php
/**
 * Wanderbeds cancel
 * POST stays/wanderbeds/cancel
 *
 * Params:
 *   invoice_id (required)
 *   reference / room_reference — cancel one room (Wanderbeds per-room key)
 *   room_index — 0-based index into stored wb_room_refs
 *   wb_roomindex — match roomindex field from Book/BookInfo
 *   cancel_all=1 or scope=all — cancel whole booking (reference must be empty)
 *
 * Empty reference = whole booking. Do NOT fall back to wb_reference.
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/wanderbeds/cancel', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');

    $booking = null;

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'wanderbeds',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if (empty($booking['pnr'])) {
            throw new Exception('Booking has not been issued yet. Cannot cancel.');
        }

        $moduleData = wanderbedsGetModule($db);
        if (!$moduleData) {
            throw new Exception('Wanderbeds module not configured');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $roomRefs = is_array($bookingData['wb_room_refs'] ?? null)
            ? $bookingData['wb_room_refs']
            : [];
        $cancelledRefs = is_array($bookingData['wb_cancelled_rooms'] ?? null)
            ? $bookingData['wb_cancelled_rooms']
            : [];

        $bookingRef = (string) ($bookingData['wb_booking_reference'] ?? '');
        if ($bookingRef === '') {
            $bookingRef = (string) ($booking['pnr'] ?? '');
        }

        $cancelAll = !empty($input['cancel_all'])
            || strtolower(trim((string) ($input['scope'] ?? ''))) === 'all';

        $roomReference = trim((string) (
            $input['reference']
            ?? $input['room_reference']
            ?? ''
        ));

        // Resolve by array index into wb_room_refs
        if ($roomReference === '' && !$cancelAll && array_key_exists('room_index', $input) && $input['room_index'] !== '' && $input['room_index'] !== null) {
            $idx = (int) $input['room_index'];
            if (!isset($roomRefs[$idx]) || !is_array($roomRefs[$idx])) {
                throw new Exception('Invalid room_index: no room at index ' . $idx);
            }
            $roomReference = trim((string) ($roomRefs[$idx]['reference'] ?? ''));
            if ($roomReference === '') {
                throw new Exception('Selected room has no cancel reference');
            }
        }

        // Resolve by Wanderbeds roomindex field
        if ($roomReference === '' && !$cancelAll && isset($input['wb_roomindex']) && $input['wb_roomindex'] !== '' && $input['wb_roomindex'] !== null) {
            $want = (int) $input['wb_roomindex'];
            foreach ($roomRefs as $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                if ((int) ($rr['roomindex'] ?? -1) === $want) {
                    $roomReference = trim((string) ($rr['reference'] ?? ''));
                    break;
                }
            }
            if ($roomReference === '') {
                throw new Exception('No room found with wb_roomindex=' . $want);
            }
        }

        if ($cancelAll) {
            $roomReference = '';
        }

        $isPartial = ($roomReference !== '');

        // Block only when fully cancelled (partial still allows cancelling remaining rooms)
        if (!$isPartial && in_array($booking['booking_status'], ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already cancelled');
        }

        if ($isPartial) {
            $statusOfTarget = '';
            foreach ($roomRefs as $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                if (trim((string) ($rr['reference'] ?? '')) === $roomReference) {
                    $statusOfTarget = strtoupper(trim((string) ($rr['status'] ?? '')));
                    break;
                }
            }
            if ($statusOfTarget === 'X' || in_array($roomReference, $cancelledRefs, true)) {
                throw new Exception('This room is already cancelled');
            }
            if (in_array($booking['booking_status'], ['cancelled', 'voided'], true) && count($roomRefs) <= 1) {
                throw new Exception('Booking is already cancelled');
            }
        }

        $cancelPayload = [
            'booking_reference' => $bookingRef,
            'reference' => $roomReference,
        ];
        $result = wanderbedsCall($moduleData, 'hotel/cancel', $cancelPayload, 'POST', 60);
        logApiCall(
            $isPartial ? 'CancelRoom' : 'Cancel',
            $cancelPayload,
            wanderbedsSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId)
        );

        $dataSuccess = $result['data']['data']['success'] ?? $result['data']['success'] ?? null;
        $desc = strtolower((string) ($result['error'] ?? ''));
        $alreadyCancelled = str_contains($desc, 'already cancel');

        if (!$result['success'] && !$alreadyCancelled && $dataSuccess !== true) {
            throw new Exception((string) ($result['error'] ?? 'Cancel failed'));
        }

        $bookingData['wb_cancel'] = [
            'cancelled_at' => date('Y-m-d H:i:s'),
            'partial' => $isPartial,
            'reference' => $roomReference,
            'booking_reference' => $bookingRef,
            'data' => $result['data'] ?? null,
        ];

        // Track which room(s) we asked to cancel (audit only — real status comes from BookInfo)
        if ($isPartial) {
            if (!in_array($roomReference, $cancelledRefs, true)) {
                $cancelledRefs[] = $roomReference;
            }
        } else {
            foreach ($roomRefs as $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                $ref = trim((string) ($rr['reference'] ?? ''));
                if ($ref !== '' && !in_array($ref, $cancelledRefs, true)) {
                    $cancelledRefs[] = $ref;
                }
            }
        }
        $bookingData['wb_cancelled_rooms'] = $cancelledRefs;

        // Authoritative status = supplier BookInfo (NO|RQ|C|X|RJ), never hardcode X
        $primaryWbStatus = '';
        $platformStatus = '';
        $gotBookInfo = false;
        try {
            $infoPayload = ['booking_reference' => $bookingRef];
            $clientRef = (string) ($bookingData['wb_client_reference'] ?? '');
            if ($clientRef !== '') {
                $infoPayload['client_reference'] = $clientRef;
            }
            $info = wanderbedsCall($moduleData, 'hotel/bookinfo', $infoPayload, 'POST', 45);
            logApiCall(
                'BookInfoAfterCancel',
                $infoPayload,
                wanderbedsSanitizeForLog($info['data'] ?? ['error' => $info['error'] ?? null]),
                (int) ($info['http_code'] ?? 0),
                __DIR__ . '/../logs',
                'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId)
            );
            if (!empty($info['success'])) {
                $refs = wanderbedsExtractBookingRefs($info['data'] ?? []);
                if (!empty($refs['room_refs'])) {
                    $bookingData['wb_room_refs'] = $refs['room_refs'];
                    $roomRefs = $refs['room_refs'];
                }
                if ($refs['booking_reference'] !== '') {
                    $bookingData['wb_booking_reference'] = $refs['booking_reference'];
                }
                if ($refs['reference'] !== '') {
                    $bookingData['wb_reference'] = $refs['reference'];
                }
                $bookingData['wb_bookinfo'] = $info['data'] ?? null;
                $primaryWbStatus = (string) ($refs['primary_status'] ?? '');
                $platformStatus = (string) ($refs['platform_status'] ?? '');
                $gotBookInfo = ($primaryWbStatus !== '');
            }
        } catch (Throwable $ignored) {
            // Fallback below only if BookInfo unavailable
        }

        if (!$gotBookInfo) {
            // Cancel succeeded but BookInfo failed — best-effort local mirror only for rooms we cancelled
            foreach ($roomRefs as $i => $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                $ref = trim((string) ($rr['reference'] ?? ''));
                if ($isPartial) {
                    if ($ref === $roomReference) {
                        $roomRefs[$i]['status'] = 'X';
                    }
                } else {
                    $roomRefs[$i]['status'] = 'X';
                }
            }
            $bookingData['wb_room_refs'] = $roomRefs;

            $statuses = [];
            foreach ($roomRefs as $rr) {
                if (is_array($rr) && trim((string) ($rr['status'] ?? '')) !== '') {
                    $statuses[] = strtoupper(trim((string) $rr['status']));
                }
            }
            $primaryWbStatus = wanderbedsResolvePrimaryStatus($statuses);
            $platformStatus = wanderbedsMapPlatformStatus($primaryWbStatus);
        }

        $bookingData['wb_status'] = $primaryWbStatus;

        // Platform booking_status from mapped supplier codes only
        switch ($platformStatus) {
            case 'cancelled':
                $newBookingStatus = 'cancelled';
                break;
            case 'failed':
                $newBookingStatus = 'failed';
                break;
            case 'pending':
                $newBookingStatus = 'pending';
                break;
            case 'confirmed':
                $newBookingStatus = 'confirmed';
                break;
            default:
                $newBookingStatus = (string) ($booking['booking_status'] ?? 'processing');
                break;
        }

        $db->update('bookings', [
            'booking_status' => $newBookingStatus,
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        echo json_encode([
            'success' => true,
            'message' => $isPartial
                ? ($newBookingStatus === 'cancelled'
                    ? 'Room cancelled; booking is now fully cancelled'
                    : 'Room cancelled successfully')
                : 'Booking cancelled successfully',
            'partial' => $isPartial,
            'reference' => $roomReference,
            'confirmation_number' => $booking['pnr'],
            'booking_reference' => $bookingRef,
            'booking_status' => $newBookingStatus,
            'wb_status' => $primaryWbStatus,
            'room_refs' => $bookingData['wb_room_refs'] ?? [],
            'data' => $result['data'] ?? null,
        ]);
    } catch (Exception $e) {
        if (!empty($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'action' => 'cancel',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]),
            ], ['id' => $booking['id']]);
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});
