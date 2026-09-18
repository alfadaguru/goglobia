<?php
// app/routes/admin/bookingsRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /admin/bookings
$router->get(admin.'/bookings', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::bookings;

    require_once views."includes/header.php";
    require_once "app/views/admin/bookings/bookings.php";
    require_once views."includes/footer.php";

});

// ================================ GET /admin/bookings/edit/{invoice_id}
$router->get(admin.'/bookings/edit/(.*)', function ($invoice_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

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
            $pdfPath = GENERATE_BOOKING_PDF($invoice_id);
            NOTIFY::payment($booking['module'], $data, [
                'invoice_id' => $invoice_id,
                'amount' => $data['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'module_type' => ucfirst($booking['module'])
            ], $pdfPath);
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

    if ($action === 'mark_paid') {
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
            // Trigger Notification
            $pdfPath = GENERATE_BOOKING_PDF($invoice_id);
            NOTIFY::payment($booking['module'], $booking, [
                'invoice_id' => $invoice_id,
                'amount' => $booking['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'payment_status' => 'paid',
                'module_type' => ucfirst($booking['module']),
                'hotel_name' => $booking['hotel_name'] ?? '',
                'tour_name' => $booking['tour_name'] ?? '',
                'car_name' => $booking['car_name'] ?? ''
            ], $pdfPath);

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
            // Trigger Notification (using 'booking' event as confirmation)
            NOTIFY::booking($booking['module'], $booking, [
                'invoice_id' => $invoice_id,
                'amount' => $booking['price_markup'],
                'currency' => $booking['currency'] ?? '',
                'payment_status' => $booking['payment_status'],
                'module_type' => ucfirst($booking['module']),
                'hotel_name' => $booking['hotel_name'] ?? '',
                'tour_name' => $booking['tour_name'] ?? '',
                'car_name' => $booking['car_name'] ?? ''
            ]);

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


    // META DATA
    $title = T::bookings;
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/bookings/bookings.php";
    require_once views."includes/footer.php";

});
