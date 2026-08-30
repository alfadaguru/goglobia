<?php
/**
 * AIRALO eSIM CANCELLATION HANDLER
 * Endpoint: POST /esim/airalo/cancel (via modules API gateway)
 */

@$SECURE or die('Access Denied!');

if (!function_exists('airaloHandleCancel')) {
    function airaloHandleCancel($db)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        header('Content-Type: application/json; charset=utf-8');

        try {
            if (!isset($db) || !$db) {
                throw new Exception('Database connection not available');
            }

            $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
            if ($invoiceId === '') {
                throw new Exception('Invoice ID is required');
            }

            $booking = $db->get('bookings', '*', [
                'invoice_id' => $invoiceId,
                'module_type' => 'esim',
                'module' => 'airalo',
            ]);

            if (!$booking) {
                throw new Exception('eSIM booking not found');
            }

            if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
                throw new Exception('Booking is already cancelled');
            }

            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }

            $provision = (array) ($bookingData['airalo_provision'] ?? []);
            $providerOrderId = (string) ($provision['id']
                ?? $provision['order_id']
                ?? $booking['pnr']
                ?? '');

            $issued = $providerOrderId !== '';
            $now = date('Y-m-d H:i:s');

            if (!$issued) {
                $note = 'Cancelled by admin on ' . $now . ' (no supplier order existed; safe to refund if payment was captured).';

                $bookingData['airalo_cancellation'] = [
                    'cancelled_at' => $now,
                    'issued' => false,
                    'provider_action' => 'none',
                    'note' => $note,
                ];

                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'cancellation_status' => 1,
                    'cancellation_request' => 1,
                    'cancellation_response' => $note,
                    'booking_data' => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
                ], ['invoice_id' => $invoiceId]);

                ob_clean();
                echo json_encode([
                    'status' => true,
                    'success' => true,
                    'message' => 'Booking cancelled successfully (was not yet issued)',
                    'issued' => false,
                    'refundable' => true,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            // For an already-issued eSIM, Airalo does not support programmatic cancellation.
            // We flag the booking as "cancellation requested" WITHOUT changing booking_status
            // to 'cancelled', so the Cancel button remains active and the admin can update
            // the status manually once Airalo support confirms the reversal.
            $note = 'Cancellation requested by admin on ' . $now
                . '. eSIM order ' . $providerOrderId
                . ' has been issued. Airalo does not support automated cancellation — '
                . 'please contact Airalo support with this order ID to process a refund, '
                . 'then manually update the booking status to Cancelled.';

            $bookingData['airalo_cancellation'] = [
                'requested_at' => $now,
                'issued' => true,
                'provider_order_id' => $providerOrderId,
                'provider_action' => 'manual_support_request',
                'note' => $note,
            ];

            // Only mark cancellation_request; do NOT set booking_status = 'cancelled'
            // so the admin can still act on this booking after contacting Airalo.
            $db->update('bookings', [
                'cancellation_request' => 1,
                'cancellation_response' => $note,
                'booking_data' => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            ], ['invoice_id' => $invoiceId]);

            ob_clean();
            echo json_encode([
                'status' => true,
                'success' => true,
                'issued' => true,
                'provider_order_id' => $providerOrderId,
                'refundable' => false,
                'requires_manual_followup' => true,
                'message' => 'Cancellation request recorded. eSIM order ' . $providerOrderId
                    . ' is already issued — contact Airalo support for a refund, then set the booking status to Cancelled manually.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            error_log('AIRALO CANCEL ERROR | Invoice: ' . ($_POST['invoice_id'] ?? 'N/A') . ' | ' . $error);

            ob_clean();
            echo json_encode([
                'status' => false,
                'success' => false,
                'message' => $error,
                'response_error' => $error,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

global $router;
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    $router->post('esim/airalo/cancel', function () use ($db) {
        airaloHandleCancel($db);
    });
}
