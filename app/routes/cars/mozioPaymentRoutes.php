<?php
// app/routes/cars/mozioPaymentRoutes.php
// Mozio payment mode #3: Stripe redirect return + reservation poll (per Mozio API docs)

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/cars/mozio/lib.php';

/**
 * Shared redirect after Mozio Stripe (or Partner /bookingconfirmed/ default).
 * Always prefer the cars invoice — never dump the customer on the site home.
 */
$mozioRedirectToInvoice = static function (string $invoiceId, string $searchId = '') use ($db): void {
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '' && $searchId !== '') {
        $invoiceId = mozioResolveReturnInvoice($db, $searchId);
    }

    if ($invoiceId === '') {
        $_SESSION['error'] = 'We could not match your payment to a booking. Please open your invoice from My Bookings or contact support.';
        header('Location: ' . (function_exists('urlOnCurrentHost') ? urlOnCurrentHost('cars') : (root . 'cars')));
        exit;
    }

    mozioRememberPendingCheckout($invoiceId, $searchId);
    $target = 'invoice/cars/' . $invoiceId . '?mozio_return=1';
    header('Location: ' . (function_exists('urlOnCurrentHost') ? urlOnCurrentHost($target) : (root . $target)));
    exit;
};

/**
 * Mozio hosted-checkout often returns customers to Partner Portal default
 * `/bookingconfirmed/{search_id}/` instead of our `success_url`.
 */
$router->get('/bookingconfirmed/([a-fA-F0-9-]{16,64})/?', function ($mozioId) use ($SECURE, $db, $mozioRedirectToInvoice) {
    $searchId = trim((string)$mozioId);
    $invoiceId = mozioResolveReturnInvoice($db, $searchId);
    $mozioRedirectToInvoice($invoiceId, $searchId);
});

/**
 * Fallback for links still pointing at /cars/mozio/success.
 */
$router->get('/cars/mozio/success', function () use ($SECURE, $db, $mozioRedirectToInvoice) {
    $searchId = trim((string)($_GET['search_id'] ?? ''));
    $invoiceId = trim((string)($_GET['invoice_id'] ?? ''));

    // Prefer explicit invoice_id from success_url; otherwise resolve from search/session.
    if ($invoiceId === '') {
        $invoiceId = mozioResolveReturnInvoice($db, $searchId);
    } else {
        // Mark return so invoice does not bounce back to Stripe.
        mozioRememberPendingCheckout($invoiceId, $searchId);
    }

    $mozioRedirectToInvoice($invoiceId, $searchId);
});

$router->get('/cars/mozio/poll-status', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    $invoiceId = trim((string)($_GET['invoice_id'] ?? ''));
    $searchId = trim((string)($_GET['search_id'] ?? ''));

    if ($invoiceId === '' && $searchId !== '') {
        $invoiceId = mozioResolveReturnInvoice($db, $searchId);
    }

    if ($invoiceId === '') {
        echo json_encode(['success' => false, 'message' => 'Missing invoice_id or search_id', 'status' => 'error']);
        exit;
    }

    $cfg = mozioModuleConfig($db);
    if (!$cfg) {
        echo json_encode(['success' => false, 'message' => 'Mozio not configured', 'status' => 'error']);
        exit;
    }

    $result = mozioPollAndFinalizeReservation($db, $cfg, $invoiceId, 1);
    echo json_encode([
        'success'             => !empty($result['success']),
        'status'              => $result['status'] ?? ($result['success'] ? 'completed' : 'pending'),
        'confirmation_number' => $result['confirmation_number'] ?? null,
        'message'             => $result['message'] ?? '',
        'redirect_url'        => urlOnCurrentHost('invoice/cars/' . $invoiceId),
    ]);
});

$router->get('/cars/mozio/payment-status', function () use ($SECURE, $db) {
    $invoiceId = $_GET['invoice_id'] ?? $_GET['invoice'] ?? '';
    if (empty($invoiceId)) {
        header('Location: ' . root . 'cars');
        exit;
    }

    $cfg = mozioModuleConfig($db);
    if ($cfg) {
        mozioPollAndFinalizeReservation($db, $cfg, (string)$invoiceId, 10);
    }

    header('Location: ' . root . 'invoice/cars/' . $invoiceId);
    exit;
});

/**
 * Start (or resume) Mozio hosted checkout without charging a site gateway.
 */
$router->post('/cars/mozio/checkout', function () use ($SECURE, $db) {
    $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));
    if ($invoiceId === '') {
        $_SESSION['error'] = 'Missing invoice.';
        header('Location: ' . root . 'cars');
        exit;
    }

    $booking = $db->get('bookings', ['invoice_id', 'module', 'module_type', 'pnr'], ['invoice_id' => $invoiceId]);
    if (!$booking || strtolower((string)$booking['module']) !== 'mozio') {
        $_SESSION['error'] = 'Booking not found.';
        header('Location: ' . root . 'invoice/cars/' . $invoiceId);
        exit;
    }

    if (!empty($booking['pnr'])) {
        header('Location: ' . root . 'invoice/cars/' . $invoiceId);
        exit;
    }

    $issueResult = mozioIssueReservation($db, $invoiceId);

    if (!empty($issueResult['status']) && !empty($issueResult['requires_mozio_payment']) && !empty($issueResult['mozio_redirect_url'])) {
        mozioRememberPendingCheckout($invoiceId, (string)($issueResult['search_id'] ?? ''));
        header('Location: ' . $issueResult['mozio_redirect_url']);
        exit;
    }

    if (!empty($issueResult['status']) && !empty($issueResult['pnr'])) {
        header('Location: ' . root . 'invoice/cars/' . $invoiceId);
        exit;
    }

    $_SESSION['error'] = 'Could not start checkout: ' . ($issueResult['message'] ?? 'Unknown error');
    header('Location: ' . root . 'invoice/cars/' . $invoiceId);
    exit;
});
