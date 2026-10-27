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
