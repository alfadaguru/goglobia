<?php
// ============================================================================
// FILE: app/routes/bus/invoiceRoutes.php
// GET /invoice/bus/{invoiceId} — standard invoice page (shared payment summary)
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/invoice/bus/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'bus']);
    if (!$booking) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invoice not found'];
        redirect(root . 'bus/');
        return;
    }

    // ── PAYMENT GATEWAY CALLBACK (success / cancel / failure) ───────────────
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';

        if (!$token) {
            $_SESSION['payment_notice'] = ['type' => 'error', 'title' => 'Payment Update', 'message' => 'Missing payment token. Please try again.'];
            header('Location: ' . root . 'invoice/bus/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = ['gateway_data' => $_GET, 'module_type' => 'bus', 'module' => 'bus'];

        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id'] = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            // GENERATE PNR ON CONFIRMATION (LOCAL INVENTORY — NO SUPPLIER API)
            $existingPnr = $db->get('bookings', 'pnr', ['invoice_id' => $invoiceId]);
            if (empty($existingPnr)) {
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $pnr = '';
                for ($i = 0; $i < 6; $i++) { $pnr .= $chars[random_int(0, strlen($chars) - 1)]; }
                $db->update('bookings', ['pnr' => $pnr, 'booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]);

                // DECREMENT SEAT INVENTORY FOR THE BOOKED DATE (ONCE, WITH PNR CREATION)
                $bd = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
                $paxCount = max(1, (int)($bd['adults'] ?? 1) + (int)($bd['children'] ?? 0));
                $journeys = $bd['journeys'] ?? [[
                    'route_id' => (int)($bd['route_id'] ?? 0),
                    'date' => $bd['date'] ?? '',
                ]];
                foreach ($journeys as $journey) {
                    $routeId = (int)($journey['route_id'] ?? 0);
                    $travelDate = DateTime::createFromFormat('d-m-Y', (string)($journey['date'] ?? ''));
                    if (!$routeId || !$travelDate) continue;
                    $calRow = $db->get('bus_routes_calendar', ['id', 'seats_available'], [
                        'route_id' => $routeId, 'date' => $travelDate->format('Y-m-d'),
                    ]);
                    if ($calRow) {
                        $db->update('bus_routes_calendar', [
                            'seats_available' => max(0, (int)$calRow['seats_available'] - $paxCount),
                        ], ['id' => $calRow['id']]);
                    }
                }
            }
            $_SESSION['payment_notice'] = ['type' => 'success', 'title' => 'Payment Successful', 'message' => 'Invoice #' . $invoiceId . ' has been paid successfully.'];
        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = ['type' => 'warning', 'title' => 'Payment Cancelled', 'message' => 'You have cancelled the payment. No charges were made.'];
        } else {
            $_SESSION['payment_notice'] = ['type' => 'error', 'title' => 'Payment Failed', 'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.')];
        }

        header('Location: ' . root . 'invoice/bus/' . $invoiceId);
        exit;
    }

    // Decoded draft (trip, date, passengers) for the view + shared summary
    $bookingData = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    $title = (T::invoice ?? 'Invoice') . ' ' . $invoiceId;
    $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'modules/bus/invoice/index.php';
    require_once views . 'includes/footer.php';
});
