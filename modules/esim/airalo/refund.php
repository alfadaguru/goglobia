<?php
/**
 * AIRALO eSIM REFUND HANDLER
 * Endpoint: POST /esim/airalo/refund (via modules API gateway)
 *
 * SUPPLIER REALITY: Airalo has no programmatic refund endpoint — a refund on an
 * issued eSIM order is processed through Airalo support. The maximum automation
 * here is to reverse the CUSTOMER's card charge via the payment gateway (real
 * for Paystack/Stripe, 'unsupported' otherwise → manual) and flag the
 * supplier-side reversal for operations. booking_status ENUM is
 * confirmed|pending|cancelled — the money state lives in payment_status.
 */

@$SECURE or die('Access Denied!');

if (!function_exists('airaloHandleRefund')) {
    function airaloHandleRefund($db)
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

            $invoiceId    = trim((string) ($_POST['invoice_id'] ?? ''));
            $refundReason = trim((string) ($_POST['refund_reason'] ?? 'Airalo eSIM refund'));
            if ($invoiceId === '') {
                throw new Exception('Invoice ID is required');
            }

            $booking = $db->get('bookings', '*', [
                'invoice_id'  => $invoiceId,
                'module_type' => 'esim',
                'module'      => 'airalo',
            ]);
            if (!$booking) {
                throw new Exception('eSIM booking not found');
            }

            if (($booking['payment_status'] ?? '') === 'refunded') {
                ob_clean();
                echo json_encode([
                    'status'  => true,
                    'success' => true,
                    'message' => 'Booking already marked as refunded.',
                    'invoice_id' => $invoiceId,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }
            $provision = (array) ($bookingData['airalo_provision'] ?? []);
            $providerOrderId = (string) ($provision['id'] ?? $provision['order_id'] ?? $booking['pnr'] ?? '');

            require_once dirname(__DIR__, 3) . '/app/lib/payment-gateway.php';
            $gwRefund = function_exists('refund_gateway_payment')
                ? refund_gateway_payment($db, $booking, null, $refundReason)
                : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
            $gatewayRefunded = ($gwRefund['status'] === 'refunded');

            $now = date('Y-m-d H:i:s');
            $bookingData['airalo_refund'] = [
                'requested_at'      => $now,
                'provider_order_id' => $providerOrderId,
                'gateway_refund'    => $gwRefund,
                'provider_action'   => 'manual_support_request',
                'reason'            => $refundReason,
            ];

            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'payment_status'        => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                'cancellation_status'   => 1,
                'cancellation_request'  => 1,
                'cancellation_response' => json_encode($bookingData['airalo_refund'], JSON_UNESCAPED_SLASHES),
                'booking_data'          => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            ], ['invoice_id' => $invoiceId]);

            ob_clean();
            echo json_encode([
                'status'         => true,
                'success'        => true,
                'invoice_id'     => $invoiceId,
                'gateway_refund' => $gatewayRefunded,
                'message'        => $gatewayRefunded
                    ? ('Card refunded via ' . ($gwRefund['gateway'] ?? 'gateway') . '. Airalo has no refund API; if the eSIM order '
                        . ($providerOrderId ?: 'N/A') . ' was issued, confirm the reversal with Airalo support.')
                    : ('Automated card refund not possible (' . ($gwRefund['message'] ?? 'unsupported')
                        . '). Refund the customer manually and, if issued, process the reversal with Airalo support (order '
                        . ($providerOrderId ?: 'N/A') . ').'),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            error_log('AIRALO REFUND ERROR | Invoice: ' . ($_POST['invoice_id'] ?? 'N/A') . ' | ' . $error);
            ob_clean();
            echo json_encode(['status' => false, 'success' => false, 'message' => $error, 'response_error' => $error], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

global $router;
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    $router->post('esim/airalo/refund', function () use ($db) {
        airaloHandleRefund($db);
    });
}
