<?php
// ============================================================================
// KIKOTO FERRIES — ISSUE BOOKING (ADMIN ACTION)
// ============================================================================
// ENDPOINT: POST ferries/kikoto/issue
// Called from admin bookings/edit page.
// Receives: invoice_id (form field)
// Calls Kikoto /bookings/{reference}/confirm, stores locators, updates DB.
// ============================================================================

global $router, $db;

$router->post('ferries/kikoto/issue', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $invoiceId = trim($_POST['invoice_id'] ?? '');
        if (!$invoiceId) {
            echo json_encode(['success' => false, 'message' => 'invoice_id is required']);
            return;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ferries']);
        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            return;
        }

        if (!empty($booking['pnr'])) {
            echo json_encode(['success' => true, 'message' => 'Booking already issued. PNR: ' . $booking['pnr']]);
            return;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        $reference   = $bookingData['reference'] ?? '';
        if (!$reference) {
            echo json_encode(['success' => false, 'message' => 'No Kikoto reference found for this booking']);
            return;
        }

        $cfg = _kikoto_cfg($db);
        if (empty($cfg)) {
            echo json_encode(['success' => false, 'message' => 'Kikoto module not configured']);
            return;
        }

        // TODO (price reconciliation — §8.1(2), docs/MODULES.md §13.9):
        //   The Kikoto draft (and its price) is created earlier at booking time in
        //   app/routes/api/ferries/bookingRoutes.php; issue.php only CONFIRMS it,
        //   so there is no live re-price at this step. To close the gap, re-quote
        //   with _kikoto_request('POST','/prices',[…stored sailings+passengers…]),
        //   apply _kikoto_apply_markup(), sum total_price, and run
        //   reconcilePostPaymentPrice($db,$booking,$liveTotal,$currency) BEFORE
        //   confirm. NOT wired: rebuilding the /prices body (sailings +
        //   passenger-refs) from stored booking_data varies by booking path
        //   (AI-trip vs ferries) and a wrong shape would false-block every booking
        //   — documented gap, not a fabricated call.
        $confirmRes = _kikoto_request('POST', '/bookings/' . urlencode($reference) . '/confirm', [
            'cfg'     => $cfg,
            'timeout' => 30,
        ]);

        if (!$confirmRes['ok']) {
            $err = $confirmRes['error'] ?? json_encode($confirmRes['data'] ?? '');
            echo json_encode(['success' => false, 'message' => 'Kikoto confirm failed: ' . $err]);
            return;
        }

        $confirmed = $confirmRes['data']['data'] ?? [];
        $locators  = [];
        foreach ($confirmed['sailings'] ?? [] as $s) {
            if (!empty($s['locator'])) $locators[] = $s['locator'];
        }

        $bookingData['confirmed'] = $confirmed;
        $bookingData['locators']  = $locators;

        $db->update('bookings', [
            'booking_status'   => 'confirmed',
            'pnr'              => implode(',', $locators),
            'booking_response' => json_encode($confirmed, JSON_UNESCAPED_SLASHES),
            'booking_data'     => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'success'  => true,
            'message'  => 'Booking issued successfully. Locator(s): ' . implode(', ', $locators),
            'locators' => $locators,
            'reference'=> $reference,
        ]);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
});
