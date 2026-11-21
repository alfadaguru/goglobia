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
