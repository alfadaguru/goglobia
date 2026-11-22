<?php
// app/routes/admin/bookingsRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /admin/bookings
$router->get(admin.'/bookings', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');

    // META DATA
    $title = T::bookings;

    require_once views."includes/header.php";
    require_once "app/views/admin/bookings/bookings.php";
    require_once views."includes/footer.php";

});

// ================================ GET /admin/supplier-log/{invoice_id}
// Raw supplier request/response log for a booking (admin one-click debug panel).
// Returns JSON of every call we made to the supplier for this invoice, with keys
// redacted. Rendered by the "Supplier log" button on the invoice/ticket view.
$router->get(admin.'/supplier-log/(.*)', function ($invoice_id) use ($SECURE,$db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');
    header('Content-Type: application/json; charset=utf-8');
    $invoice_id = trim((string)$invoice_id);
    $rows = [];
    try {
        $rows = $db->select('supplier_api_log',
            ['id','supplier','service','endpoint','http_code','request','response','error','created_at'],
            ['invoice_id' => $invoice_id, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
    } catch (\Throwable $e) {
        // Table may not exist yet (pre-deploy) — degrade to empty, not 500.
        error_log('admin/supplier-log: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'invoice_id' => $invoice_id, 'count' => count($rows), 'logs' => $rows]);
    exit;
});

// ================================ GET /admin/bookings/edit/{invoice_id}
$router->get(admin.'/bookings/edit/(.*)', function ($invoice_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');

    // META DATA
    $title = T::edit . ' ' . T::booking;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/bookings/edit.php";
    require_once views."includes/footer.php";

});

// ================================ GET /admin/bookings/document/{invoice_id}/{traveller_index}/{document_type}
$router->get(admin.'/bookings/document/([^/]+)/(\d+)/(passport|national_id_front|national_id_back)', function ($invoice_id, $traveller_index, $document_type) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');

    $booking = $db->get('bookings', ['invoice_id', 'module_type', 'travellers'], ['invoice_id' => $invoice_id]);

    if (!$booking || ($booking['module_type'] ?? '') !== 'visa') {
        http_response_code(404);
        exit('Document not found');
    }

    $travellers = json_decode($booking['travellers'] ?? '', true);
    if (!is_array($travellers)) {
        http_response_code(404);
        exit('Document not found');
    }

    $travellerIndex = (int) $traveller_index;
    $documentFields = [
        'passport' => 'passport_copy',
        'national_id_front' => 'national_id_front_copy',
        'national_id_back' => 'national_id_back_copy',
    ];
    $field = $documentFields[$document_type] ?? '';
    $nameField = $field . '_name';

    $traveller = $travellers[$travellerIndex] ?? null;
    $documentPath = is_array($traveller) ? ($traveller[$field] ?? '') : '';

    if ($documentPath === '') {
        http_response_code(404);
        exit('Document not found');
    }

    $relativePath = str_replace('\\', '/', ltrim($documentPath, '/'));
    if (strpos($relativePath, 'uploads/visa/') !== 0) {
        http_response_code(403);
        exit('Invalid document path');
    }

    $projectRoot = realpath(dirname(__DIR__, 3));
    $allowedDir = realpath(uploads . 'visa/');
    $filePath = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

    if (!$allowedDir || !$filePath || strpos($filePath, $allowedDir) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('Document not found');
    }

    $downloadName = basename($traveller[$nameField] ?? $filePath);
    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    if ($downloadName === '') {
        $downloadName = basename($filePath);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $filePath) : 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (ob_get_length()) {
        ob_clean();
    }

    $disposition = (isset($_GET['view']) && $_GET['view'] === '1') ? 'inline' : 'attachment';

    header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
    if ($disposition === 'inline') {
        header('X-Frame-Options: SAMEORIGIN');
    }
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;

});

// ================================ POST /admin/bookings/edit/{invoice_id}
$router->post(admin.'/bookings/edit/(.*)', function ($invoice_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    // CSRF: this handler writes booking_status/payment_status/prices from $_POST.
    // guard() is fail-closed on POST and a no-op otherwise (form or AJAX aware).
    CSRF::guard();

    // Get the booking
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Booking not found'
        ];
        header('Location: ' . root . admin . '/bookings');
        exit;
    }

    // Get POST data
    $data = [
        'booking_status' => $_POST['booking_status'] ?? $booking['booking_status'],
        'payment_status' => $_POST['payment_status'] ?? $booking['payment_status'],
        'price_original' => floatval($_POST['price_original'] ?? $booking['price_original']),
        'price_markup' => $_POST['price_markup'] ?? $booking['price_markup'],
        'commission' => $_POST['commission'] ?? $booking['commission'],
        'cancellation_status' => intval($_POST['cancellation_status'] ?? $booking['cancellation_status']),
        'cancellation_request' => intval($_POST['cancellation_request'] ?? $booking['cancellation_request']),
        'cancellation_response' => $_POST['cancellation_response'] ?? $booking['cancellation_response'],
        'first_name' => $_POST['first_name'] ?? $booking['first_name'],
        'last_name' => $_POST['last_name'] ?? $booking['last_name'],
        'email' => $_POST['email'] ?? $booking['email'],
        'phone' => $_POST['phone'] ?? $booking['phone'],
        'phone_country_code' => $_POST['phone_country_code'] ?? $booking['phone_country_code'],
        'country' => $_POST['country'] ?? $booking['country'],
        'address' => $_POST['address'] ?? $booking['address'],
        'special_requests' => $_POST['special_requests'] ?? $booking['special_requests'],
        'pnr' => !empty($_POST['pnr']) ? trim($_POST['pnr']) : $booking['pnr'],
    ];

    // Update travellers if provided
    if (isset($_POST['travellers'])) {
        $data['travellers'] = $_POST['travellers'];
    }

    // Update the booking
    $updated = $db->update('bookings', $data, ['invoice_id' => $invoice_id]);

    if ($updated) {
        // Trigger Notifications for status changes
        // 1. Payment Confirmation
        if ($data['payment_status'] === 'paid' && $booking['payment_status'] !== 'paid') {
            // B-01 (admin-bookings audit): this full-edit form is a SECOND unpaid->paid
            // path alongside the quick-action `mark_paid`. It previously only sent the
            // notification + PDFs and skipped every settlement side-effect the action
            // path runs — so an admin settling a booking here left own-inventory stays
            // able to oversell (hold never consumed), the supplier earning never
            // accrued (supplier under-paid), Pay-Later still being chased by the cron,
            // and paid_at unset. Run the SAME settlement here so the two paths agree.

            // Stamp paid_at (the quick-action path sets it; the edit $data did not).
            $db->update('bookings', ['paid_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoice_id]);

            // PAY-LATER: stop the reminder/auto-cancel cron chasing a now-settled booking.
            if (function_exists('pay_later_clear_on_payment')) {
                try { pay_later_clear_on_payment($db, (string) $invoice_id); }
                catch (\Throwable $e) { error_log('admin edit mark_paid pay-later: ' . $e->getMessage()); }
            }

            // STAYS no-oversell: consume the availability hold (settlement bypasses
            // handle_payment_callback). Idempotent by invoice ref; no-op for API suppliers.
            if (in_array(strtolower((string) ($booking['module'] ?? $booking['module_type'] ?? '')), ['stays', 'hotels'], true)) {
                if (!function_exists('stays_hold_consume_ref')) {
                    $invLib = dirname(__DIR__, 2) . '/lib/stays_inventory.php';
                    if (is_file($invLib)) { require_once $invLib; }
                }
                if (function_exists('stays_hold_consume_ref')) {
                    try { stays_hold_consume_ref($db, (string) $invoice_id); }
                    catch (\Throwable $e) { error_log('admin edit mark_paid stays hold consume: ' . $e->getMessage()); }
                }
            }

            // SUPPLIER EARNINGS: accrue own-inventory revenue (bypasses handle_payment_callback).
            // Idempotent + self-guarding (own-inventory real-supplier owner only). Best effort.
            if (!function_exists('supplier_earning_accrue_for_booking')) {
                $seLib = dirname(__DIR__, 2) . '/lib/supplier_earnings.php';
                if (is_file($seLib)) { require_once $seLib; }
            }
            if (function_exists('supplier_earning_accrue_for_booking')) {
                try { supplier_earning_accrue_for_booking($db, (string) $invoice_id); }
                catch (\Throwable $e) { error_log('admin edit mark_paid accrue: ' . $e->getMessage()); }
            }

            // INVOICE (priced) for payer/owner/admin; no-price TICKET for the
            // TRAVELLER (3-document model). NOTIFY routes each to the right party.
            $pdfPath    = GENERATE_BOOKING_PDF($invoice_id);
            $ticketPath = GENERATE_BOOKING_PDF($invoice_id, 'ticket');
            NOTIFY::payment($booking['module'], $data, [
                'invoice_id' => $invoice_id,
                'amount' => $data['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'module_type' => ucfirst($booking['module'])
            ], $pdfPath, $ticketPath);
        }

        // 2. Cancellation Confirmation
        if ($data['cancellation_status'] == 1 && $booking['cancellation_status'] == 0) {
            NOTIFY::cancellation($booking['module'], $data, [
                'invoice_id' => $invoice_id,
                'amount' => $data['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'module_type' => ucfirst($booking['module'])
            ]);
        }

        // OWN-INVENTORY stays: when this edit cancels the booking, free the availability
        // hold/reservation (so the night can be resold) and void the supplier's
        // not-yet-paid-out earning. The generic edit form previously did neither, so an
        // admin cancelling here lost the room + still paid the supplier. Idempotent;
        // no-op for API suppliers. (Money refund is the separate refund action.)
        $wasCancelledNow = strtolower((string) ($data['booking_status'] ?? '')) === 'cancelled'
            && strtolower((string) ($booking['booking_status'] ?? '')) !== 'cancelled';
        if ($wasCancelledNow && in_array(strtolower((string) ($booking['module_type'] ?? '')), ['stays', 'hotels'], true)) {
            if (!function_exists('stays_on_cancelled')) {
                $invLib = dirname(__DIR__, 2) . '/lib/stays_inventory.php';
                if (is_file($invLib)) { require_once $invLib; }
            }
            if (function_exists('stays_on_cancelled')) {
                stays_on_cancelled($db, (string) $invoice_id);
            }
        } elseif ($wasCancelledNow && in_array(strtolower((string) ($booking['module_type'] ?? '')), ['tours', 'cars'], true)) {
            // tours/cars also accrue an own-inventory supplier earning (see
            // supplier_earning_resolve_owner) but have no room/availability hold, so
            // stays_on_cancelled does not apply. Void the earning directly so an admin
            // cancel here does not leave the supplier payable for a cancelled booking.
            // Idempotent; refuses rows already reserved for a payout; no-op for API suppliers.
            if (!function_exists('supplier_earning_void_for_booking')) {
                $seLib = dirname(__DIR__, 2) . '/lib/supplier_earnings.php';
                if (is_file($seLib)) { require_once $seLib; }
            }
            if (function_exists('supplier_earning_void_for_booking')) {
                try { supplier_earning_void_for_booking($db, (string) $invoice_id); }
                catch (\Throwable $e) { error_log('admin cancel earning-void ' . $invoice_id . ': ' . $e->getMessage()); }
            }
        } elseif ($wasCancelledNow && strtolower((string) ($booking['module_type'] ?? '')) === 'umrah') {
            // UMRAH (U-ENG-2): the edit-form flip to 'cancelled' previously freed NO
            // inventory (umrah was absent from the branches above) so the seat's hold
            // leaked. Route through the service layer, which reverses any money
            // collected (only if this admin holds bookings.refund — a plain editor
            // cancels + frees the seat without refunding) and ALWAYS releases the hold.
            if (!function_exists('umrah_admin_cancel_refund')) {
                $umrahLib = dirname(__DIR__, 2) . '/lib/umrah/services.php';
                if (is_file($umrahLib)) { require_once $umrahLib; }
            }
            if (function_exists('umrah_admin_cancel_refund')) {
                try {
                    $__canRef = function_exists('admin_can') ? admin_can($db, 'bookings', 'refund') : false;
                    umrah_admin_cancel_refund($db, (string) $invoice_id, [
                        'refund' => $__canRef,
                        'reason' => 'Umrah booking cancelled by admin (edit form)',
                    ]);
                } catch (\Throwable $e) { error_log('admin edit-cancel umrah ' . $invoice_id . ': ' . $e->getMessage()); }
            }
        }

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Booking updated successfully'
        ];
    } else {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Failed to update booking'
        ];
    }

    header('Location: ' . root . admin . '/bookings/edit/' . $invoice_id);
    exit;

});

// ================================ POST /admin/bookings/change-flight/{invoice_id}
// MANUAL flight CHANGE (record edit). A manual booking has no supplier, so a change
// is purely rewriting the stored itinerary + reconciling the money. Guarded to
// module='manual' && module_type='flights'. Rebuilds booking_data.flight_data from the
// edited segments using the SAME pure builder the create path uses
// (bmBuildManualBookingData), applies the fare-difference/change-fee via
// bm_apply_booking_adjustment, and (since GENERATE_BOOKING_PDF re-reads booking_data on
// every call) the regenerated ticket/invoice reflect the new flight automatically.
// LIVE flights do NOT use this route — they go through the guided cancel+rebook flow.
$router->post(admin.'/bookings/change-flight/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    $moduleType = strtolower((string) ($booking['module_type'] ?? ''));
    $module     = strtolower((string) ($booking['module'] ?? ''));
    if ($moduleType !== 'flights' || $module !== 'manual') {
        echo json_encode(['success' => false, 'message' => 'This change path is for manual flight bookings only. Use the guided change for live supplier bookings.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }
    if (!function_exists('bmBuildManualBookingData')) {
        echo json_encode(['success' => false, 'message' => 'Itinerary builder unavailable.']);
        exit;
    }

    // Rebuild the flight_data from the edited segments, reusing the create builder.
    // Preserve existing travellers (the change is itinerary + money, not passengers).
    $travellers = json_decode((string) ($booking['travellers'] ?? '[]'), true);
    if (!is_array($travellers)) { $travellers = []; }
    $currency = strtoupper(trim((string) ($booking['currency_markup'] ?? 'NGN'))) ?: 'NGN';

    $built = bmBuildManualBookingData('flights', $_POST, $currency, $travellers);
    $newFlightData = $built['flight_data'] ?? null;
    if (!is_array($newFlightData) || empty($newFlightData['segments'])) {
        echo json_encode(['success' => false, 'message' => 'At least one valid flight segment is required.']);
        exit;
    }

    // Store the uploaded CHANGED ticket (optional) exactly like a manual booking's
    // document — same validated storage (bmStoreManualDocument: extension + real MIME
    // check, 8MB cap, uploads/booking_manager/). It becomes the booking's current
    // manual_document; the PRIOR document path is retained in the change history below.
    $priorDocument = (string) ($booking['manual_document'] ?? '');
    $newDocument = null;
    if (!empty($_FILES['changed_ticket']['name']) && function_exists('bmStoreManualDocument')) {
        $newDocument = bmStoreManualDocument($_FILES['changed_ticket'], (string) $invoice_id);
    }

    // Merge the rebuilt flight_data/passengers into the EXISTING booking_data so we
    // keep meta (adjustments, change ref, no_show) and any other stored keys.
    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    $prevFlight = $bd['flight_data'] ?? [];
    $bd['flight_data'] = $newFlightData;
    if (isset($built['passengers'])) { $bd['passengers'] = $built['passengers']; }
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }

    // FULL CHANGE HISTORY — append one entry per change so the system has the complete
    // record (old itinerary → new itinerary, the document on file at the time, actor,
    // money). The most recent entry is also mirrored to meta.last_changed for quick reads.
    $histEntry = [
        'at'        => date('Y-m-d H:i:s'),
        'by'        => $_SESSION['user_id'] ?? null,
        'from'      => ['from' => $prevFlight['from'] ?? '', 'to' => $prevFlight['to'] ?? '', 'departure_date' => $prevFlight['departure_date'] ?? '', 'segments' => $prevFlight['segments'] ?? []],
        'to'        => ['from' => $newFlightData['from'] ?? '', 'to' => $newFlightData['to'] ?? '', 'departure_date' => $newFlightData['departure_date'] ?? '', 'segments' => $newFlightData['segments'] ?? []],
        'prior_document' => $priorDocument,
        'new_document'   => $newDocument,
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $histEntry;
    $bd['meta']['last_changed'] = $histEntry;

    // If the booking was marked no_show, a successful rebook returns it to confirmed.
    $statusUpdate = [];
    if (strtolower((string) ($booking['booking_status'] ?? '')) === 'no_show') {
        $statusUpdate['booking_status'] = 'confirmed';
    }
    // Promote the newly uploaded ticket to the booking's current document.
    if ($newDocument !== null) {
        $statusUpdate['manual_document'] = $newDocument;
        $histEntry['new_document'] = $newDocument; // (already set; explicit for clarity)
    }

    $ok = $db->update('bookings', array_merge(['booking_data' => json_encode($bd)], $statusUpdate), ['invoice_id' => $invoice_id]);
    if ($ok === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the new itinerary.']);
        exit;
    }

    // Money reconcile: fare difference (signed) + change fee. $delta is the NET the
    // customer owes(+)/is owed(−). The caller supplies fare_difference (new−old) and
    // change_fee. Charge = fare_diff + fee; refund = −(|fare_diff| − fee) i.e. the fee
    // is retained out of any refund. We compute the net here and hand it to the primitive.
    $fareDiff = (float) ($_POST['fare_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = 0.0;
    if ($fareDiff > 0)      { $delta = $fareDiff + $changeFee; }          // owes more
    elseif ($fareDiff < 0)  { $delta = $fareDiff + $changeFee; }          // refund minus fee (fee offsets refund)
    else                    { $delta = $changeFee; }                      // flat fee only

    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Manual flight change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Customer notice + regenerated ticket (GENERATE_BOOKING_PDF re-reads booking_data).
    try {
        $ticketPath = GENERATE_BOOKING_PDF($invoice_id, 'ticket');
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Flights',
            'event_note'  => 'changed',
        ], $ticketPath);
    } catch (\Throwable $e) { error_log('change-flight notify ' . $invoice_id . ': ' . $e->getMessage()); }

    echo json_encode([
        'success'     => true,
        'message'     => 'Flight itinerary updated.'
            . ($newDocument !== null ? ' Changed ticket saved to the booking.' : '')
            . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
        'document'    => $newDocument,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-stay/{invoice_id}
// STAYS change (hotel booking). Purpose-built for a hotel: edit property / dates /
// room+board / guests, reconcile the rate difference + change fee, and (for a REAL
// own-inventory booking only) move the availability hold to the new dates. LIVE
// supplier stays use the guided cancel+rebook flow (existing cancel + change_refund +
// BM rebook), not this editable path. The voucher re-reads booking_data so it updates.
$router->post(admin.'/bookings/change-stay/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    $moduleType = strtolower((string) ($booking['module_type'] ?? ''));
    if (!in_array($moduleType, ['stays', 'hotels'], true)) {
        echo json_encode(['success' => false, 'message' => 'This change path is for hotel bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    // Load stays inventory helpers on demand (for the own-inventory hold move).
    if (!function_exists('staysStayNights') || !function_exists('stays_hold_supersede_ref')) {
        foreach (['/lib/stays_inventory.php'] as $libRel) {
            $lib = dirname(__DIR__, 2) . $libRel;
            if (is_file($lib)) { require_once $lib; }
        }
        // staysStayNights lives in the stays routes helper set; fall back to a local calc.
    }
    $calcNights = function (string $ci, string $co): int {
        if (function_exists('staysStayNights')) { return (int) staysStayNights($ci, $co); }
        $a = strtotime($ci); $b = strtotime($co);
        return ($a && $b && $b > $a) ? (int) round(($b - $a) / 86400) : 0;
    };

    // Existing stay data (keep meta + any keys we do not touch).
    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    $prev = $bd; // snapshot for history

    // Edited stay fields (all optional — only overwrite when provided).
    $set = function (string $key, $val) use (&$bd) { if ($val !== null && $val !== '') { $bd[$key] = $val; } };
    $set('hotel_name',    isset($_POST['hotel_name'])    ? trim((string) $_POST['hotel_name'])    : null);
    $set('hotel_address', isset($_POST['hotel_address']) ? trim((string) $_POST['hotel_address']) : null);
    $set('hotel_city',    isset($_POST['hotel_city'])    ? trim((string) $_POST['hotel_city'])    : null);
    $set('hotel_country', isset($_POST['hotel_country']) ? trim((string) $_POST['hotel_country']) : null);
    if (isset($_POST['hotel_stars']) && $_POST['hotel_stars'] !== '') { $bd['hotel_stars'] = (int) $_POST['hotel_stars']; }
    $ci = isset($_POST['checkin'])  ? trim((string) $_POST['checkin'])  : (string) ($bd['checkin'] ?? '');
    $co = isset($_POST['checkout']) ? trim((string) $_POST['checkout']) : (string) ($bd['checkout'] ?? '');
    $bd['checkin']  = $ci;
    $bd['checkout'] = $co;
    $bd['nights']   = $calcNights($ci, $co);
    if (isset($_POST['board_type'])) { $bd['board_type'] = trim((string) $_POST['board_type']); }

    // Rooms — accept an edited selected_rooms JSON (room_name/quantity/board), PRESERVING
    // any inventory ids (room_id/option_id/hotel_id) already on the row so an own-inventory
    // hold can still be moved. We merge name/quantity/board onto the existing room entries.
    if (isset($_POST['rooms']) && $_POST['rooms'] !== '') {
        $roomsIn = json_decode((string) $_POST['rooms'], true);
        if (is_array($roomsIn)) {
            $existing = is_array($bd['selected_rooms'] ?? null) ? array_values($bd['selected_rooms']) : [];
            $merged = [];
            foreach (array_values($roomsIn) as $i => $r) {
                if (!is_array($r)) { continue; }
                $base = is_array($existing[$i] ?? null) ? $existing[$i] : [];
                $base['room_name'] = trim((string) ($r['room_name'] ?? ($base['room_name'] ?? 'Room')));
                $base['quantity']  = max(1, (int) ($r['quantity'] ?? ($base['quantity'] ?? 1)));
                if (isset($r['board_name'])) {
                    if (!is_array($base['option'] ?? null)) { $base['option'] = []; }
                    $base['option']['board_name'] = trim((string) $r['board_name']);
                }
                $merged[] = $base;
            }
            if ($merged !== []) { $bd['selected_rooms'] = $merged; }
        }
    }

    // Guests — accept an edited flat guest map (adult_0/child_0/… + primary_guest kept).
    if (isset($_POST['travellers']) && $_POST['travellers'] !== '') {
        $tin = json_decode((string) $_POST['travellers'], true);
        if (is_array($tin)) { $db->update('bookings', ['travellers' => json_encode($tin)], ['invoice_id' => $invoice_id]); }
    }

    // CHANGE HISTORY (same meta shape flights use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => ['hotel_name' => $prev['hotel_name'] ?? '', 'checkin' => $prev['checkin'] ?? '', 'checkout' => $prev['checkout'] ?? '', 'nights' => $prev['nights'] ?? 0],
        'to'   => ['hotel_name' => $bd['hotel_name'] ?? '', 'checkin' => $bd['checkin'] ?? '', 'checkout' => $bd['checkout'] ?? '', 'nights' => $bd['nights'] ?? 0],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the stay change.']); exit; }

    // OWN-INVENTORY hold move — ONLY when the booking carries real inventory ids
    // (hotel_id + selected_rooms[].room_id + option.option_id). A typed manual stay has
    // free-text room names with no ids → nothing to move (it never held inventory), so
    // this is correctly skipped. When ids exist AND dates/rooms changed, release the old
    // held/consumed rows then re-reserve for the new range via the shared reserve path.
    $datesChanged = ($prev['checkin'] ?? '') !== ($bd['checkin'] ?? '') || ($prev['checkout'] ?? '') !== ($bd['checkout'] ?? '');
    $hasInventoryIds = (int) ($bd['hotel_id'] ?? 0) > 0
        && is_array($bd['selected_rooms'] ?? null)
        && (int) ($bd['selected_rooms'][0]['room_id'] ?? 0) > 0;
    $holdMoved = false;
    if ($datesChanged && $hasInventoryIds && function_exists('stays_hold_supersede_ref') && function_exists('stays_hold_consume_or_reserve')) {
        try {
            stays_hold_supersede_ref($db, (string) $invoice_id);        // free old range
            $res = stays_hold_consume_or_reserve($db, (string) $invoice_id); // re-reserve new range from booking_data
            $holdMoved = ($res['reserved'] ?? 0) > 0 || ($res['consumed'] ?? 0) > 0;
        } catch (\Throwable $e) { error_log('change-stay hold move ' . $invoice_id . ': ' . $e->getMessage()); }
    }

    // Money reconcile — rate difference (+) owes / (−) refund, plus change fee. Same
    // module-agnostic primitive flights use; no new money code.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Hotel stay change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Regenerate the voucher + notify (voucher re-reads booking_data automatically).
    // IMPORTANT: GENERATE_BOOKING_PDF (voucher doc) and the notify path can echo HTML
    // to the output buffer — capture and discard it so this endpoint returns CLEAN JSON
    // (an un-buffered echo here corrupts the browser's response.json()).
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Stays',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-stay notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Stay updated.'
            . ($holdMoved ? ' Availability hold moved to the new dates.' : '')
            . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
        'hold_moved'  => $holdMoved,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-rental/{invoice_id}
// CARS change (car hire). Purpose-built for a rental: edit vehicle / pickup+dropoff
// location / dates / times / driver age, and reconcile the rate difference + change
// fee. Manual/own-inventory rewrites the flat car keys; a live (cartrawler/mozio)
// booking also mirrors the editable fields into booking_data.search_params so the
// voucher stays consistent. Cars have NO availability-hold system, so no hold move.
// (A live supplier EXCHANGE isn't offered — cars suppliers have no amend API; a true
// supplier swap is the guided cancel+rebook from the UI.)
$router->post(admin.'/bookings/change-rental/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'cars') {
        echo json_encode(['success' => false, 'message' => 'This change path is for car rental bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    if (!isset($bd['search_params']) || !is_array($bd['search_params'])) { $bd['search_params'] = []; }
    // Snapshot for history (read from the normalised locations the panel shows).
    $prev = [
        'pickup_location'  => (string) ($bd['pickup_location'] ?? $bd['search_params']['pickup_location'] ?? ''),
        'dropoff_location' => (string) ($bd['dropoff_location'] ?? $bd['search_params']['dropoff_location'] ?? ''),
        'pickup_date'      => (string) ($bd['pickup_date'] ?? $bd['search_params']['pickup_date'] ?? ''),
        'dropoff_date'     => (string) ($bd['dropoff_date'] ?? $bd['search_params']['return_date'] ?? ''),
    ];

    // Apply edited car fields onto BOTH the flat keys (manual read) and search_params
    // (live read), so whichever the voucher/panel consults is consistent. Only overwrite
    // keys that were provided.
    $applied = [];
    $put = function (string $flatKey, string $spKey, $val) use (&$bd, &$applied) {
        if ($val === null) { return; }
        $v = trim((string) $val);
        $bd[$flatKey] = $v;
        $bd['search_params'][$spKey] = $v;
        $applied[$flatKey] = $v;
    };
    $put('car_name',         'car_name',         $_POST['car_name']         ?? null);
    $put('vehicle_type',     'vehicle_type',     $_POST['vehicle_type']     ?? null);
    $put('transmission',     'transmission',     $_POST['transmission']     ?? null);
    $put('pickup_location',  'pickup_location',  $_POST['pickup_location']  ?? null);
    $put('dropoff_location', 'dropoff_location', $_POST['dropoff_location'] ?? null);
    $put('pickup_date',      'pickup_date',      $_POST['pickup_date']      ?? null);
    $put('pickup_time',      'pickup_time',      $_POST['pickup_time']      ?? null);
    // drop-off date/time: live search_params historically uses return_date/return_time.
    if (isset($_POST['dropoff_date'])) { $v = trim((string) $_POST['dropoff_date']); $bd['dropoff_date'] = $v; $bd['search_params']['return_date'] = $v; }
    if (isset($_POST['dropoff_time'])) { $v = trim((string) $_POST['dropoff_time']); $bd['dropoff_time'] = $v; $bd['search_params']['return_time'] = $v; }
    if (isset($_POST['driver_age']) && $_POST['driver_age'] !== '') {
        $bd['driver_age'] = (int) $_POST['driver_age'];
        $bd['search_params']['driver_age'] = (int) $_POST['driver_age'];
    }

    // CHANGE HISTORY (same meta shape stays/flights use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'pickup_location'  => (string) ($bd['pickup_location'] ?? ''),
            'dropoff_location' => (string) ($bd['dropoff_location'] ?? ''),
            'pickup_date'      => (string) ($bd['pickup_date'] ?? ''),
            'dropoff_date'     => (string) ($bd['dropoff_date'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the rental change.']); exit; }

    // Money reconcile — rate difference (+) owes / (−) refund, plus change fee.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Car rental change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Regenerate the voucher + notify. Buffer/discard output — GENERATE_BOOKING_PDF
    // (voucher) + NOTIFY echo HTML which would corrupt this JSON response (proven on
    // the stays build). Keep the response clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Cars',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-rental notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Rental updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-cruise/{invoice_id}
// CRUISE change (sailing). Cruise is own-inventory (module='goglobia', no supplier,
// not in the Booking Manager), so a change is a pure RECORD edit: rewrite the sailing
// in booking_data (cruise/ship/line, departure port + date, nights, cabin, pax) and
// reconcile the fare difference + change fee. No supplier call, no rebook deep-link.
$router->post(admin.'/bookings/change-cruise/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'cruises') {
        echo json_encode(['success' => false, 'message' => 'This change path is for cruise bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    $prev = [
        'cruise_name'    => (string) ($bd['cruise_name'] ?? ''),
        'departure_port' => (string) ($bd['departure_port'] ?? ''),
        'departure_date' => (string) ($bd['departure_date'] ?? ''),
        'room_type'      => (string) ($bd['room_type'] ?? ''),
    ];

    // Apply edited cruise fields (only overwrite when provided).
    $setStr = function (string $k) use (&$bd) {
        if (isset($_POST[$k])) { $bd[$k] = trim((string) $_POST[$k]); }
    };
    foreach (['cruise_name', 'cruise_line', 'ship_name', 'departure_port', 'departure_date', 'room_type'] as $k) { $setStr($k); }
    if (isset($_POST['nights']) && $_POST['nights'] !== '')     { $bd['nights']   = max(0, (int) $_POST['nights']); }
    if (isset($_POST['adults']) && $_POST['adults'] !== '')     { $bd['adults']   = max(1, (int) $_POST['adults']); }
    if (isset($_POST['children']) && $_POST['children'] !== '') { $bd['children'] = max(0, (int) $_POST['children']); }

    // CHANGE HISTORY (same meta shape stays/cars/flights use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'cruise_name'    => (string) ($bd['cruise_name'] ?? ''),
            'departure_port' => (string) ($bd['departure_port'] ?? ''),
            'departure_date' => (string) ($bd['departure_date'] ?? ''),
            'room_type'      => (string) ($bd['room_type'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the cruise change.']); exit; }

    // Money reconcile.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Cruise change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Voucher + notify, buffered so the JSON response stays clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Cruises',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-cruise notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Cruise updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-tour/{invoice_id}
// TOURS change (tour/activity). Purpose-built: edit tour name / destination / start
// date / duration / tour type / pickup / party size, and reconcile the fare difference
// + change fee. Manual/own rewrites the flat keys; a live tour (adivaha/toursbms) uses
// the guided cancel+rebook from the UI (no supplier amend API). This route is the
// record-edit path (manual + own); it is safe for a live tour's record too.
$router->post(admin.'/bookings/change-tour/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'tours') {
        echo json_encode(['success' => false, 'message' => 'This change path is for tour bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    $prev = [
        'tour_name'   => (string) ($bd['tour_name'] ?? ''),
        'destination' => (string) ($bd['destination'] ?? ''),
        'start_date'  => (string) ($bd['start_date'] ?? ''),
        'tour_type'   => (string) ($bd['tour_type'] ?? ''),
    ];

    // Apply edited tour fields (only overwrite when provided).
    foreach (['tour_name', 'destination', 'start_date', 'duration', 'tour_type', 'pickup_location'] as $k) {
        if (isset($_POST[$k])) { $bd[$k] = trim((string) $_POST[$k]); }
    }
    // Keep activity_date (live key) aligned to start_date when the start date changes.
    if (isset($_POST['start_date'])) { $bd['activity_date'] = trim((string) $_POST['start_date']); }
    if (isset($_POST['adults']) && $_POST['adults'] !== '')     { $bd['adults']   = max(1, (int) $_POST['adults']); }
    if (isset($_POST['children']) && $_POST['children'] !== '') { $bd['children'] = max(0, (int) $_POST['children']); }

    // CHANGE HISTORY (same meta shape stays/cars/cruise use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'tour_name'   => (string) ($bd['tour_name'] ?? ''),
            'destination' => (string) ($bd['destination'] ?? ''),
            'start_date'  => (string) ($bd['start_date'] ?? ''),
            'tour_type'   => (string) ($bd['tour_type'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the tour change.']); exit; }

    // Money reconcile.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Tour change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Voucher + notify, buffered so the JSON response stays clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Tours',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-tour notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Tour updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-bus/{invoice_id}
// BUS change (trip). Bus is own-inventory local (no supplier, not in the Booking
// Manager), so a change is a RECORD edit of the displayed trip: operator/service,
// origin→destination, travel date, times, bus type/seat class + money reconcile. It
// writes BOTH the nested `trip` object (manual read) and the top-level `date`, so the
// panel/voucher stay consistent. It deliberately does NOT move seat inventory — a true
// route/date-with-seats swap is cancel + rebook (bus's working cancel releases seats).
$router->post(admin.'/bookings/change-bus/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'bus') {
        echo json_encode(['success' => false, 'message' => 'This change path is for bus bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    if (!isset($bd['trip']) || !is_array($bd['trip'])) { $bd['trip'] = []; }
    $prev = [
        'origin'      => (string) ($bd['trip']['origin'] ?? ''),
        'destination' => (string) ($bd['trip']['destination'] ?? ''),
        'date'        => (string) ($bd['date'] ?? ''),
    ];

    // Apply edited trip fields onto the nested trip object (only when provided).
    foreach (['service_name', 'operator', 'origin', 'destination', 'departure_time', 'arrival_time', 'bus_type', 'seat_class'] as $k) {
        if (isset($_POST[$k])) { $bd['trip'][$k] = trim((string) $_POST[$k]); }
    }
    // Travel date lives at the top level for bus; keep it there.
    if (isset($_POST['date'])) { $bd['date'] = trim((string) $_POST['date']); }

    // CHANGE HISTORY (same meta shape stays/cars/cruise/tours use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'origin'      => (string) ($bd['trip']['origin'] ?? ''),
            'destination' => (string) ($bd['trip']['destination'] ?? ''),
            'date'        => (string) ($bd['date'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the trip change.']); exit; }

    // Money reconcile.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Bus trip change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Voucher + notify, buffered so the JSON response stays clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Bus',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-bus notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Trip updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-ferry/{invoice_id}
// FERRIES change (sailing). Both manual and live (kikoto) store the readable trip under
// a nested `draft` object (from_name/to_name/date/departure_time) + vehicles[]/pets[]
// placeholder arrays the ticket counts. A change is a RECORD edit of those (kikoto has
// no amend API; a true sailing swap is cancel + rebook). Edits booking_data.draft +
// resizes the vehicles/pets arrays + reconciles money.
$router->post(admin.'/bookings/change-ferry/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'ferries') {
        echo json_encode(['success' => false, 'message' => 'This change path is for ferry bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    if (!isset($bd['draft']) || !is_array($bd['draft'])) { $bd['draft'] = []; }
    $prev = [
        'from_name' => (string) ($bd['draft']['from_name'] ?? ''),
        'to_name'   => (string) ($bd['draft']['to_name'] ?? ''),
        'date'      => (string) ($bd['draft']['date'] ?? ''),
    ];

    // Apply edited sailing fields inside draft (only when provided).
    foreach (['from_name', 'to_name', 'date', 'departure_time'] as $k) {
        if (isset($_POST[$k])) { $bd['draft'][$k] = trim((string) $_POST[$k]); }
    }
    // Vehicles / pets are placeholder arrays the ticket count()s — resize to the new counts.
    if (isset($_POST['vehicles']) && $_POST['vehicles'] !== '') {
        $bd['vehicles'] = array_fill(0, max(0, (int) $_POST['vehicles']), ['type' => 'vehicle']);
    }
    if (isset($_POST['pets']) && $_POST['pets'] !== '') {
        $bd['pets'] = array_fill(0, max(0, (int) $_POST['pets']), ['type' => 'pet']);
    }

    // CHANGE HISTORY (same meta shape the other services use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'from_name' => (string) ($bd['draft']['from_name'] ?? ''),
            'to_name'   => (string) ($bd['draft']['to_name'] ?? ''),
            'date'      => (string) ($bd['draft']['date'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the sailing change.']); exit; }

    // Money reconcile.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Ferry sailing change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Voucher + notify, buffered so the JSON response stays clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Ferries',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-ferry notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Sailing updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/change-visa/{invoice_id}
// VISA change (application). Visa is own-record (no supplier), so a change is a RECORD
// edit of the application: visa type, from/to country, entry date, processing speed +
// money reconcile. Individual applicant edits / document re-uploads stay in the existing
// applicant/document flow — this route handles the application-level change + money.
$router->post(admin.'/bookings/change-visa/(.*)', function ($invoice_id) use ($SECURE,$db) {

    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    CSRF::guard();
    header('Content-Type: application/json');

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    if (strtolower((string) ($booking['module_type'] ?? '')) !== 'visa') {
        echo json_encode(['success' => false, 'message' => 'This change path is for visa bookings only.']);
        exit;
    }
    if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
        echo json_encode(['success' => false, 'message' => 'A cancelled/voided booking cannot be changed.']);
        exit;
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
    if (!is_array($bd)) { $bd = []; }
    $prev = [
        'visa_type_name'   => (string) ($bd['visa_type_name'] ?? ''),
        'to_country_name'  => (string) ($bd['to_country_name'] ?? ''),
        'entry_date'       => (string) ($bd['entry_date'] ?? ''),
    ];

    // Apply edited application fields (only when provided).
    foreach (['visa_type_name', 'from_country_name', 'to_country_name', 'entry_date', 'processing_speed_name'] as $k) {
        if (isset($_POST[$k])) { $bd[$k] = trim((string) $_POST[$k]); }
    }

    // CHANGE HISTORY (same meta shape the other services use).
    if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
    $hist = [
        'at'   => date('Y-m-d H:i:s'),
        'by'   => $_SESSION['user_id'] ?? null,
        'from' => $prev,
        'to'   => [
            'visa_type_name'  => (string) ($bd['visa_type_name'] ?? ''),
            'to_country_name' => (string) ($bd['to_country_name'] ?? ''),
            'entry_date'      => (string) ($bd['entry_date'] ?? ''),
        ],
    ];
    if (!isset($bd['meta']['change_history']) || !is_array($bd['meta']['change_history'])) { $bd['meta']['change_history'] = []; }
    $bd['meta']['change_history'][] = $hist;
    $bd['meta']['last_changed'] = $hist;

    $ok = $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);
    if ($ok === false) { echo json_encode(['success' => false, 'message' => 'Failed to save the visa change.']); exit; }

    // Money reconcile.
    $rateDiff  = (float) ($_POST['rate_difference'] ?? 0);
    $changeFee = max(0.0, (float) ($_POST['change_fee'] ?? 0));
    $delta = ($rateDiff > 0) ? ($rateDiff + $changeFee) : (($rateDiff < 0) ? ($rateDiff + $changeFee) : $changeFee);
    $money = ['direction' => 'none', 'message' => 'No money movement.'];
    if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
        $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $changeFee,
            'Visa application change', ['actor' => $_SESSION['user_id'] ?? null]);
    }

    // Voucher + notify, buffered so the JSON response stays clean.
    ob_start();
    try {
        $voucher = GENERATE_BOOKING_PDF($invoice_id);
        NOTIFY::resend($booking['module'], $booking, [
            'invoice_id'  => $invoice_id,
            'amount'      => $booking['price_markup'],
            'currency'    => $booking['currency'] ?? '',
            'module_type' => 'Visa',
            'event_note'  => 'changed',
        ], $voucher);
    } catch (\Throwable $e) { error_log('change-visa notify ' . $invoice_id . ': ' . $e->getMessage()); }
    ob_end_clean();

    echo json_encode([
        'success'     => true,
        'message'     => 'Visa application updated.' . (($money['direction'] ?? 'none') !== 'none' ? ' ' . ($money['message'] ?? '') : ''),
        'money'       => $money,
        'payment_due' => $money['payment_due'] ?? 0,
        'refunded'    => $money['refunded'] ?? 0,
    ]);
    exit;

});

// ================================ POST /admin/bookings/action/{invoice_id}
$router->post(admin.'/bookings/action/(.*)', function ($invoice_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    // CSRF: state-changing admin action (JSON). guard() emits a JSON 403 for
    // AJAX callers on a bad/missing token and is a no-op on non-POST.
    CSRF::guard();

    header('Content-Type: application/json');

    // Get the booking
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ========================================================================
    // A cancelled or voided booking is final. Nothing may re-issue it, flip it
    // back to confirmed, or mark it paid — the Issue button is already hidden
    // for these, but the quick actions posted here bypassed that entirely,
    // which is how a cancelled invoice could end up confirmed again.
    // ========================================================================
    $bookingStatusLc = strtolower(trim((string) ($booking['booking_status'] ?? '')));
    if (in_array($bookingStatusLc, ['cancelled', 'voided'], true)) {
        $blockedActions = ['mark_paid', 'mark_confirmed', 'issue_booking', 'void_booking', 'cancel_booking'];
        if (in_array($action, $blockedActions, true)) {
            ob_clean();
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'This booking is ' . $bookingStatusLc . '. No further action can be taken on it.',
            ]);
            exit;
        }
    }

    // RESEND TICKET — re-deliver the traveller's no-price TICKET to the customer,
    // per CHANNEL (email or WhatsApp). A read-only action (no state change), so
    // it is allowed even on cancelled/voided bookings. The document is ALWAYS the
    // no-price ticket (travel document), never the priced invoice. Admins often
    // need exactly this ("the customer lost their ticket — resend it").
    //   channel = 'email'    -> NOTIFY::resend (customer-only) with the ticket pdf
    //   channel = 'whatsapp' -> upload the ticket pdf to the customer's WhatsApp
    //                           (Green API sendFileByUpload, via SENDWHATSAPP)
    if ($action === 'resend_voucher' || $action === 'resend_ticket') {
        try {
            $channel = strtolower(trim((string) ($_POST['channel'] ?? 'email')));
            $module  = (string) ($booking['module'] ?? $booking['module_type'] ?? '');
            // Always the no-price TICKET — the traveller's travel document.
            $ticketPath = function_exists('GENERATE_BOOKING_PDF') ? GENERATE_BOOKING_PDF($invoice_id, 'ticket') : null;

            if ($channel === 'whatsapp') {
                $phone = trim((string) ($booking['phone'] ?? ''));
                if ($phone === '') {
                    echo json_encode(['success' => false, 'status' => false, 'message' => 'No phone number on this booking to send a WhatsApp ticket.']);
                    exit;
                }
                if (!$ticketPath || !is_file($ticketPath)) {
                    echo json_encode(['success' => false, 'status' => false, 'message' => 'Could not generate the ticket to send.']);
                    exit;
                }
                // Prefix the dialing code the way NOTIFY does, then deliver the PDF.
                // PREFERRED: a signed PUBLIC ticket URL that Green API fetches via
                // sendFileByUrl (reliable — the upload host times out); local-file
                // upload is the fallback inside SENDWHATSAPP.
                $cc  = trim((string) ($booking['phone_country_code'] ?? ''));
                $full = $cc !== '' ? (preg_replace('/[^\d]/', '', $cc) . preg_replace('/[^\d]/', '', $phone)) : $phone;
                $ticketUrl = function_exists('ticket_public_url') ? ticket_public_url((string) $invoice_id) : null;
                $waCaption = function_exists('ticket_whatsapp_caption')
                    ? ticket_whatsapp_caption($booking, (string) $invoice_id, $module)
                    : ('🎫 Your ticket for booking ' . $invoice_id . ' is attached.');
                $sent = function_exists('SENDWHATSAPP')
                    ? SENDWHATSAPP($full, trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))), $waCaption, $ticketPath, $ticketUrl)
                    : false;
                echo json_encode($sent
                    ? ['success' => true, 'status' => true, 'message' => 'Ticket sent to the customer on WhatsApp (' . $phone . ').']
                    : ['success' => false, 'status' => false, 'message' => 'Could not send the ticket on WhatsApp. The message/credentials are fine for text, but the file send failed — check the WhatsApp provider is connected and the site is reachable over HTTPS (Green API fetches the ticket by link).']);
                exit;
            }

            // Default: EMAIL the ticket (customer-only via NOTIFY::resend).
            if (class_exists('NOTIFY')) {
                NOTIFY::resend($module, $booking, [
                    'invoice_id'  => $invoice_id,
                    'amount'      => $booking['price_markup'] ?? '',
                    'currency'    => $booking['currency_markup'] ?? ($booking['currency'] ?? ''),
                    'module_type' => ucfirst($module),
                ], $ticketPath);
            }
            echo json_encode(['success' => true, 'status' => true, 'message' => 'Ticket re-sent by email to ' . ($booking['email'] ?: 'the customer') . '.']);
        } catch (\Throwable $e) {
            error_log('[admin] resend_ticket ' . $invoice_id . ': ' . $e->getMessage());
            echo json_encode(['success' => false, 'status' => false, 'message' => 'Could not re-send the ticket.']);
        }
        exit;
    }

    // SEND TRAVEL REMINDER — manually fire a warm flight reminder to the customer
    // on email + WhatsApp (the same message the auto cron sends). Lets staff nudge
    // a traveller on demand. $_POST['kind'] = 'pre' (upcoming-flight reminder,
    // default) or 'post' (thank-you + rebook + review). Read-only; allowed even on
    // cancelled bookings (staff judgement). Reuses flight_reminders_send().
    if ($action === 'send_reminder') {
        try {
            require_once dirname(__DIR__, 3) . '/app/lib/flight_reminders.php';
            if (!function_exists('flight_reminders_send')) {
                echo json_encode(['success' => false, 'status' => false, 'message' => 'Reminder sender is unavailable.']);
                exit;
            }
            $kind = strtolower(trim((string) ($_POST['kind'] ?? 'pre')));
            if (!in_array($kind, ['pre', 'post'], true)) { $kind = 'pre'; }
            $hasEmail = trim((string) ($booking['email'] ?? '')) !== '';
            $hasPhone = trim((string) ($booking['phone'] ?? '')) !== '';
            if (!$hasEmail && !$hasPhone) {
                echo json_encode(['success' => false, 'status' => false, 'message' => 'No email or phone on this booking to send a reminder to.']);
                exit;
            }
            flight_reminders_send($db, $booking, $kind);
            $where = trim(($hasEmail ? 'email' : '') . ($hasEmail && $hasPhone ? ' + ' : '') . ($hasPhone ? 'WhatsApp' : ''));
            echo json_encode([
                'success' => true, 'status' => true,
                'message' => ($kind === 'post' ? 'Thank-you + next-trip' : 'Flight') . ' reminder sent to the customer via ' . $where . '.',
            ]);
        } catch (\Throwable $e) {
            error_log('[admin] send_reminder ' . $invoice_id . ': ' . $e->getMessage());
            echo json_encode(['success' => false, 'status' => false, 'message' => 'Could not send the reminder.']);
        }
        exit;
    }

    if ($action === 'mark_paid') {
        // ALREADY-PAID GUARD (audit #46): without this, re-firing mark_paid on an
        // already-paid booking re-runs NOTIFY::payment + regenerates the PDF every time,
        // spamming the customer with repeat "payment received" emails. Short-circuit so
        // the notify/PDF side-effects only fire on the actual unpaid→paid transition.
        if (strtolower(trim((string) ($booking['payment_status'] ?? ''))) === 'paid') {
            ob_clean();
            echo json_encode(['success' => true, 'status' => true, 'message' => 'Booking is already marked paid.']);
            exit;
        }
        // Update payment status to paid
        $updated = $db->update('bookings', [
            'payment_status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s')
        ], ['invoice_id' => $invoice_id]);

        // PAY-LATER: a settled Pay-Later booking must stop being chased by the
        // reminder/auto-cancel cron — mark its pay_later_status 'paid'.
        if (function_exists('pay_later_clear_on_payment')) {
            pay_later_clear_on_payment($db, (string) $invoice_id);
        }

        if ($updated) {
            // STAYS no-oversell: an admin "mark paid" (offline/bank-transfer/Pay-Later
            // settlement) confirms the booking WITHOUT going through
            // handle_payment_callback, so consume the availability hold here too, or an
            // own-inventory stay's seat would free up after its ~20-min hold expires and
            // could be resold. Idempotent by invoice ref; best effort.
            if (in_array(strtolower((string) ($booking['module'] ?? $booking['module_type'] ?? '')), ['stays', 'hotels'], true)) {
                if (!function_exists('stays_hold_consume_ref')) {
                    $invLib = dirname(__DIR__, 2) . '/lib/stays_inventory.php';
                    if (is_file($invLib)) { require_once $invLib; }
                }
                if (function_exists('stays_hold_consume_ref')) {
                    try {
                        $consumed = stays_hold_consume_ref($db, (string) $invoice_id);
                        if ($consumed === 0) {
                            error_log('STAYS hold-consume (admin mark_paid): no held rows for invoice ' . $invoice_id . ' — benign for integration suppliers; if own-inventory the hold may have expired (possible oversell).');
                        }
                    } catch (\Throwable $e) { error_log('admin mark_paid stays hold consume: ' . $e->getMessage()); }
                }
            }

            // SUPPLIER EARNINGS: an admin mark-paid (offline/bank-transfer/Pay-Later) also
            // settles own-inventory revenue and must accrue the supplier earning, since it
            // bypasses handle_payment_callback. Idempotent + self-guarding (own-inventory
            // real-supplier owner only; no-op otherwise). Best effort.
            if (function_exists('supplier_earning_accrue_for_booking')) {
                try { supplier_earning_accrue_for_booking($db, (string) $invoice_id); }
                catch (\Throwable $e) { error_log('admin mark_paid accrue: ' . $e->getMessage()); }
            }

            // Trigger Notification. INVOICE (priced) for payer/owner/admin; no-price
            // TICKET for the TRAVELLER (3-document model). NOTIFY routes each.
            $pdfPath    = GENERATE_BOOKING_PDF($invoice_id);
            $ticketPath = GENERATE_BOOKING_PDF($invoice_id, 'ticket');
            NOTIFY::payment($booking['module'], $booking, [
                'invoice_id' => $invoice_id,
                'amount' => $booking['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'payment_status' => 'paid',
                'module_type' => ucfirst($booking['module']),
                'hotel_name' => $booking['hotel_name'] ?? '',
                'tour_name' => $booking['tour_name'] ?? '',
                'car_name' => $booking['car_name'] ?? ''
            ], $pdfPath, $ticketPath);

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Payment status updated to paid']);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to update payment status']);
        }
        exit;
    }

    if ($action === 'mark_confirmed') {
        // Update booking status to confirmed
        $updated = $db->update('bookings', [
            'booking_status' => 'confirmed'
        ], ['invoice_id' => $invoice_id]);

        if ($updated) {
            // Trigger Notification (using 'booking' event as confirmation). The
            // TRAVELLER gets the no-price TICKET (5th arg). The owner/admin copy
            // carries the priced INVOICE only when the booking is actually paid;
            // otherwise no priced doc exists yet (4th arg null). Email body
            // price-gates on payment_status regardless (3-document model).
            $isPaid     = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
            $ticketPath = GENERATE_BOOKING_PDF($invoice_id, 'ticket');
            $invoicePath = $isPaid ? GENERATE_BOOKING_PDF($invoice_id) : null;
            NOTIFY::booking($booking['module'], $booking, [
                'invoice_id' => $invoice_id,
                'amount' => $booking['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'payment_status' => $booking['payment_status'],
                'module_type' => ucfirst($booking['module']),
                'hotel_name' => $booking['hotel_name'] ?? '',
                'tour_name' => $booking['tour_name'] ?? '',
                'car_name' => $booking['car_name'] ?? ''
            ], $invoicePath, $ticketPath);

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Booking status updated to confirmed']);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to update booking status']);
        }
        exit;
    }

    // MARK NO-SHOW — flight missed-flight / passenger did not fly. Sets the dedicated
    // 'no_show' booking_status (added to the enum by ensureBookingHoldSchema) so the
    // booking stops reading as a live/confirmed trip, stamps who/when into booking_data
    // meta for traceability, and sends the customer an informational notice. From a
    // no_show booking staff can then start a rebook (the Change-flight flow). NO money
    // moves here — any penalty/refund is a separate explicit adjustment.
    if ($action === 'mark_no_show') {
        if (strtolower((string) ($booking['module_type'] ?? '')) !== 'flights') {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'No-show applies to flight bookings only.']);
            exit;
        }
        $curStatus = strtolower((string) ($booking['booking_status'] ?? ''));
        if (in_array($curStatus, ['cancelled', 'no_show'], true)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Booking is already ' . $curStatus . '.']);
            exit;
        }

        // Stamp no-show meta (actor/time) into booking_data without disturbing the rest.
        $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
        if (!is_array($bd)) { $bd = []; }
        if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
        $bd['meta']['no_show'] = [
            'at'    => date('Y-m-d H:i:s'),
            'by'    => $_SESSION['user_id'] ?? null,
            'prior' => $booking['booking_status'] ?? '',
        ];

        $updated = $db->update('bookings', [
            'booking_status' => 'no_show',
            'booking_data'   => json_encode($bd),
        ], ['invoice_id' => $invoice_id]);

        if ($updated !== false) {
            // Informational notice to the customer (reuse the resend channel — carries
            // the booking's current ticket; copy is driven by the template layer).
            try {
                NOTIFY::resend($booking['module'], $booking, [
                    'invoice_id'   => $invoice_id,
                    'amount'       => $booking['price_markup'],
                    'currency'     => $booking['currency'] ?? '',
                    'module_type'  => ucfirst((string) ($booking['module'] ?? 'flights')),
                    'event_note'   => 'no_show',
                ]);
            } catch (\Throwable $e) { error_log('mark_no_show notify ' . $invoice_id . ': ' . $e->getMessage()); }

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Booking marked as no-show. You can now start a rebook if the passenger wants a new flight.']);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to mark no-show.']);
        }
        exit;
    }

    // CHANGE REFUND — step 2 of the LIVE guided cancel+rebook flow. Refunds the
    // customer the amount paid MINUS the change fee (fee retained), via the shared
    // money primitive, and stamps a linked change record onto this (the ORIGINAL)
    // booking so the rebook can cross-reference it. Requires the refund grant.
    if ($action === 'change_refund') {
        admin_require($db, 'bookings', 'refund');
        if (strtolower((string) ($booking['module_type'] ?? '')) !== 'flights') {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Change refund applies to flight bookings only.']);
            exit;
        }
        $paid = (float) ($_POST['refund_amount'] ?? 0);
        $fee  = max(0.0, (float) ($_POST['change_fee'] ?? 0));
        // Net refund to the customer = paid − fee. As a signed adjustment delta this is
        // negative (customer is owed). The primitive bakes the fee into the delta.
        $delta = -1 * max(0.0, $paid - $fee);

        $money = ['direction' => 'none', 'message' => 'No refund (zero net).'];
        if (function_exists('bm_apply_booking_adjustment') && abs($delta) > 0.001) {
            $money = bm_apply_booking_adjustment($db, (string) $invoice_id, round($delta, 2), $fee,
                'Flight change — refund on cancel+rebook', ['actor' => $_SESSION['user_id'] ?? null]);
        }

        // Stamp/refresh the linked change record on the ORIGINAL booking (resumable).
        $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
        if (!is_array($bd)) { $bd = []; }
        if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
        $existing = $bd['meta']['change'] ?? [];
        $changeId = $existing['change_id'] ?? ('CHG-' . $invoice_id . '-' . date('ymdHis'));
        $bd['meta']['change'] = [
            'change_id'          => $changeId,
            'role'              => 'original',
            'counterpart_invoice' => $existing['counterpart_invoice'] ?? null,
            'step_state'        => 3, // refund done → next is rebook
            'actor'             => $_SESSION['user_id'] ?? null,
            'ts'                => date('Y-m-d H:i:s'),
            'refund'            => ['paid' => $paid, 'fee' => $fee, 'net' => max(0.0, $paid - $fee), 'result' => $money['direction'] ?? 'none'],
        ];
        $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoice_id]);

        ob_clean();
        echo json_encode([
            'success'   => true,
            'message'   => 'Refund step complete. ' . ($money['message'] ?? ''),
            'change_id' => $changeId,
            'refunded'  => $money['refunded'] ?? 0,
        ]);
        exit;
    }

    if ($action === 'issue_booking') {
        $_POST['invoice_id'] = $invoice_id;
        $_POST['module_type'] = $booking['module_type'];

        $moduleType = $booking['module_type'] ?? 'hotels';
        $module = $booking['module'] ?? 'hotels';
        $basePath = dirname(__DIR__, 3);
        $issueFile = $basePath . '/modules/' . $moduleType . '/' . $module . '/actions/issue.php';

        if (!file_exists($issueFile)) {
            echo json_encode([
                'status' => false,
                'message' => 'Issue file not found',
                'path' => $issueFile
            ]);
            exit;
        }

        ob_start();
        try {
            include $issueFile;
            $output = ob_get_clean();
            echo $output;
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode([
                'status' => false,
                'message' => 'Failed: ' . $e->getMessage(),
                'path' => $issueFile
            ]);
        }
        exit;
    }

    if ($action === 'void_booking') {
        admin_require($db, 'bookings', 'void');
        $_POST['invoice_id'] = $invoice_id;
        $_POST['module_type'] = $booking['module_type'];

        $moduleType = $booking['module_type'] ?? 'hotels';
        $module = $booking['module'] ?? 'hotels';
        $basePath = dirname(__DIR__, 3);
        $voidFile = $basePath . '/modules/' . $moduleType . '/' . $module . '/actions/void.php';

        if (!file_exists($voidFile)) {
            echo json_encode([
                'status' => false,
                'message' => 'Void file not found',
                'path' => $voidFile
            ]);
            exit;
        }

        ob_start();
        try {
            include $voidFile;
            $output = ob_get_clean();
            echo $output;
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode([
                'status' => false,
                'message' => 'Failed: ' . $e->getMessage(),
                'path' => $voidFile
            ]);
        }
        exit;
    }

    if ($action === 'cancel_booking') {
        admin_require($db, 'bookings', 'cancel');
        $_POST['invoice_id'] = $invoice_id;
        $_POST['module_type'] = $booking['module_type'];

        // UMRAH (U-ENG-2): own-inventory, NOT a /modules gateway — there is no
        // modules/umrah/umrah/actions/cancel.php to include (that legacy supplier
        // dir was removed). Handle it via the v2 service layer: reverse any money
        // collected, cancel, and RELEASE the inventory hold (the seat leak). Refund
        // is gated separately (bookings.refund) — a plain cancel with bookings.cancel
        // still releases the seat; the refund only runs when the admin also holds
        // refund rights, otherwise we cancel-without-refund and say so.
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'umrah') {
            if (!function_exists('umrah_admin_cancel_refund')) {
                $umrahLib = dirname(__DIR__, 2) . '/lib/umrah/services.php';
                if (is_file($umrahLib)) { require_once $umrahLib; }
            }
            if (!function_exists('umrah_admin_cancel_refund')) {
                echo json_encode(['status' => false, 'success' => false, 'message' => 'Umrah cancel unavailable']);
                exit;
            }
            $canRefund = function_exists('admin_can') ? admin_can($db, 'bookings', 'refund') : true;
            $r = umrah_admin_cancel_refund($db, (string) $invoice_id, [
                'refund' => $canRefund,
                'reason' => 'Umrah booking cancelled by admin',
            ]);
            echo json_encode([
                'status'  => !empty($r['ok']),
                'success' => !empty($r['ok']),
                'message' => $r['message'] ?? ($r['ok'] ? 'Cancelled' : 'Cancel failed'),
                'refunded'=> $r['refunded'] ?? 0,
            ]);
            exit;
        }

        // CRUISE (own-inventory, module='goglobia', confirm-on-pay, no PNR): like umrah,
        // there is NO modules/cruises/goglobia/actions/cancel.php to include — the generic
        // include fallback below would fail with "Cancel file not found", which is why a
        // paid cruise had no working admin cancel. Handle it here: mark cancelled, and if
        // this admin also holds bookings.refund AND the booking is paid, reverse the money
        // (gateway refund of the amount paid). A plain cancel (no refund right) still
        // cancels and says so — the money-safe split umrah uses.
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'cruises') {
            $refunded = 0.0;
            $isPaid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
            $canRefund = function_exists('admin_can') ? admin_can($db, 'bookings', 'refund') : true;
            $db->update('bookings', [
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            if ($isPaid && $canRefund) {
                if (!function_exists('refund_gateway_payment')) {
                    $pg = dirname(__DIR__, 2) . '/lib/payment-gateway.php';
                    if (is_file($pg)) { require_once $pg; }
                }
                if (function_exists('refund_gateway_payment')) {
                    try {
                        $rr = refund_gateway_payment($db, $booking, null, 'Cruise booking cancelled by admin');
                        if (is_array($rr) && (string) ($rr['status'] ?? '') === 'success') {
                            $refunded = (float) ($booking['price_markup'] ?? 0);
                            $db->update('bookings', ['payment_status' => 'refunded'], ['invoice_id' => $invoice_id]);
                        }
                    } catch (\Throwable $e) { error_log('cruise admin cancel refund ' . $invoice_id . ': ' . $e->getMessage()); }
                }
            }
            ob_start();
            try {
                NOTIFY::cancellation($booking['module'], $booking, [
                    'invoice_id' => $invoice_id,
                    'amount'     => $booking['price_markup'],
                    'currency'   => $booking['currency_markup'] ?? $booking['currency'] ?? '',
                    'module_type'=> 'Cruises',
                ]);
            } catch (\Throwable $e) { error_log('cruise cancel notify ' . $invoice_id . ': ' . $e->getMessage()); }
            ob_end_clean();
            echo json_encode([
                'status'   => true,
                'success'  => true,
                'message'  => $refunded > 0
                    ? ('Cruise cancelled and ' . $refunded . ' refunded to the customer.')
                    : ($isPaid && !$canRefund ? 'Cruise cancelled (no refund — you do not hold refund rights).' : 'Cruise cancelled.'),
                'refunded' => $refunded,
            ]);
            exit;
        }

        // VISA (own-record, no supplier): like cruise, there is NO modules/visa/visa/
        // actions/cancel.php — the include fallback would fail with "Cancel file not
        // found", which is why a paid visa had no working admin cancel. Handle it here:
        // mark cancelled, and if paid + the admin holds bookings.refund, reverse the
        // gateway payment; otherwise cancel-without-refund and say so.
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'visa') {
            $refunded = 0.0;
            $isPaid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
            $canRefund = function_exists('admin_can') ? admin_can($db, 'bookings', 'refund') : true;
            $db->update('bookings', [
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            if ($isPaid && $canRefund) {
                if (!function_exists('refund_gateway_payment')) {
                    $pg = dirname(__DIR__, 2) . '/lib/payment-gateway.php';
                    if (is_file($pg)) { require_once $pg; }
                }
                if (function_exists('refund_gateway_payment')) {
                    try {
                        $rr = refund_gateway_payment($db, $booking, null, 'Visa application cancelled by admin');
                        if (is_array($rr) && (string) ($rr['status'] ?? '') === 'success') {
                            $refunded = (float) ($booking['price_markup'] ?? 0);
                            $db->update('bookings', ['payment_status' => 'refunded'], ['invoice_id' => $invoice_id]);
                        }
                    } catch (\Throwable $e) { error_log('visa admin cancel refund ' . $invoice_id . ': ' . $e->getMessage()); }
                }
            }
            ob_start();
            try {
                NOTIFY::cancellation($booking['module'], $booking, [
                    'invoice_id' => $invoice_id,
                    'amount'     => $booking['price_markup'],
                    'currency'   => $booking['currency_markup'] ?? $booking['currency'] ?? '',
                    'module_type'=> 'Visa',
                ]);
            } catch (\Throwable $e) { error_log('visa cancel notify ' . $invoice_id . ': ' . $e->getMessage()); }
            ob_end_clean();
            echo json_encode([
                'status'   => true,
                'success'  => true,
                'message'  => $refunded > 0
                    ? ('Visa application cancelled and ' . $refunded . ' refunded to the customer.')
                    : ($isPaid && !$canRefund ? 'Visa application cancelled (no refund — you do not hold refund rights).' : 'Visa application cancelled.'),
                'refunded' => $refunded,
            ]);
            exit;
        }

        $moduleType = $booking['module_type'] ?? 'hotels';
        $module = $booking['module'] ?? 'hotels';
        $basePath = dirname(__DIR__, 3);
        $cancelFile = $basePath . '/modules/' . $moduleType . '/' . $module . '/actions/cancel.php';

        if (!file_exists($cancelFile)) {
            echo json_encode([
                'status' => false,
                'message' => 'Cancel file not found',
                'path' => $cancelFile
            ]);
            exit;
        }

        ob_start();
        try {
            include $cancelFile;
            $output = ob_get_clean();
            echo $output;
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode([
                'status' => false,
                'message' => 'Failed: ' . $e->getMessage(),
                'path' => $cancelFile
            ]);
        }
        exit;
    }

    if ($action === 'refund_request') {
        admin_require($db, 'bookings', 'refund');
        $_POST['invoice_id'] = $invoice_id;
        $_POST['module_type'] = $booking['module_type'];

        // UMRAH (U-ENG-2): own-inventory — no modules/umrah/umrah/actions/refund.php.
        // Reverse the collected amount, cancel, release the hold via the service layer.
        // This handler already requires bookings.refund (above), so refund=true.
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'umrah') {
            if (!function_exists('umrah_admin_cancel_refund')) {
                $umrahLib = dirname(__DIR__, 2) . '/lib/umrah/services.php';
                if (is_file($umrahLib)) { require_once $umrahLib; }
            }
            if (!function_exists('umrah_admin_cancel_refund')) {
                echo json_encode(['status' => false, 'success' => false, 'message' => 'Umrah refund unavailable']);
                exit;
            }
            $r = umrah_admin_cancel_refund($db, (string) $invoice_id, [
                'refund' => true,
                'reason' => 'Umrah booking refunded by admin',
            ]);
            echo json_encode([
                'status'  => !empty($r['ok']),
                'success' => !empty($r['ok']),
                'message' => $r['message'] ?? ($r['ok'] ? 'Refunded' : 'Refund failed'),
                'refunded'=> $r['refunded'] ?? 0,
            ]);
            exit;
        }

        // CRUISE (own-inventory): no modules/cruises/goglobia/actions/refund.php to
        // include. Reverse the paid amount via the gateway, mark refunded + cancelled.
        // This handler already requires bookings.refund (above).
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'cruises') {
            $refunded = 0.0;
            if (!function_exists('refund_gateway_payment')) {
                $pg = dirname(__DIR__, 2) . '/lib/payment-gateway.php';
                if (is_file($pg)) { require_once $pg; }
            }
            if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid' && function_exists('refund_gateway_payment')) {
                try {
                    $rr = refund_gateway_payment($db, $booking, null, 'Cruise booking refunded by admin');
                    if (is_array($rr) && (string) ($rr['status'] ?? '') === 'success') {
                        $refunded = (float) ($booking['price_markup'] ?? 0);
                    }
                } catch (\Throwable $e) { error_log('cruise admin refund ' . $invoice_id . ': ' . $e->getMessage()); }
            }
            $db->update('bookings', [
                'payment_status'      => $refunded > 0 ? 'refunded' : ($booking['payment_status'] ?? 'unpaid'),
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            ob_start();
            try {
                NOTIFY::cancellation($booking['module'], $booking, [
                    'invoice_id' => $invoice_id,
                    'amount'     => $booking['price_markup'],
                    'currency'   => $booking['currency_markup'] ?? $booking['currency'] ?? '',
                    'module_type'=> 'Cruises',
                ]);
            } catch (\Throwable $e) { error_log('cruise refund notify ' . $invoice_id . ': ' . $e->getMessage()); }
            ob_end_clean();
            echo json_encode([
                'status'   => true,
                'success'  => true,
                'message'  => $refunded > 0 ? ('Cruise refunded (' . $refunded . ') and cancelled.') : 'Cruise cancelled — no gateway payment to reverse.',
                'refunded' => $refunded,
            ]);
            exit;
        }

        // VISA (own-record): no modules/visa/visa/actions/refund.php. Reverse the paid
        // amount via the gateway, mark refunded + cancelled. Requires bookings.refund (above).
        if (strtolower((string) ($booking['module_type'] ?? '')) === 'visa') {
            $refunded = 0.0;
            if (!function_exists('refund_gateway_payment')) {
                $pg = dirname(__DIR__, 2) . '/lib/payment-gateway.php';
                if (is_file($pg)) { require_once $pg; }
            }
            if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid' && function_exists('refund_gateway_payment')) {
                try {
                    $rr = refund_gateway_payment($db, $booking, null, 'Visa application refunded by admin');
                    if (is_array($rr) && (string) ($rr['status'] ?? '') === 'success') {
                        $refunded = (float) ($booking['price_markup'] ?? 0);
                    }
                } catch (\Throwable $e) { error_log('visa admin refund ' . $invoice_id . ': ' . $e->getMessage()); }
            }
            $db->update('bookings', [
                'payment_status'      => $refunded > 0 ? 'refunded' : ($booking['payment_status'] ?? 'unpaid'),
                'booking_status'      => 'cancelled',
                'cancellation_status' => 1,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            ob_start();
            try {
                NOTIFY::cancellation($booking['module'], $booking, [
                    'invoice_id' => $invoice_id,
                    'amount'     => $booking['price_markup'],
                    'currency'   => $booking['currency_markup'] ?? $booking['currency'] ?? '',
                    'module_type'=> 'Visa',
                ]);
            } catch (\Throwable $e) { error_log('visa refund notify ' . $invoice_id . ': ' . $e->getMessage()); }
            ob_end_clean();
            echo json_encode([
                'status'   => true,
                'success'  => true,
                'message'  => $refunded > 0 ? ('Visa refunded (' . $refunded . ') and cancelled.') : 'Visa cancelled — no gateway payment to reverse.',
                'refunded' => $refunded,
            ]);
            exit;
        }

        $moduleType = $booking['module_type'] ?? 'hotels';
        $module = $booking['module'] ?? 'hotels';
        $basePath = dirname(__DIR__, 3);
        $refundFile = $basePath . '/modules/' . $moduleType . '/' . $module . '/actions/refund.php';

        if (!file_exists($refundFile)) {
            echo json_encode([
                'status' => false,
                'message' => 'Refund file not found',
                'path' => $refundFile
            ]);
            exit;
        }

        ob_start();
        try {
            include $refundFile;
            $output = ob_get_clean();
            echo $output;
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode([
                'status' => false,
                'message' => 'Failed: ' . $e->getMessage(),
                'path' => $refundFile
            ]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;

});

// ================================ GET /admin/bookings(.*)
$router->get(admin.'/bookings/(.*)', function ($module) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');


    // META DATA
    $title = T::bookings;
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/bookings/bookings.php";
    require_once views."includes/footer.php";

});
