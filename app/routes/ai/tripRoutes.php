<?php
// ============================================================================
// AI Trip Planner
// - GET /ai-trip
// - GET /ai-trip/booking/{hash}  — combined checkout
// - GET /ai-trip/booking         — bootstrap from session cart → draft
// - GET /ai-trip/confirmation/{invoiceId}
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/ai-trip', function () use ($SECURE, $db) {
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        header('Location: ' . root);
        exit;
    }
    $aiLegacyQ = trim((string)($_GET['q'] ?? ''));
    $title      = 'AI Trip Planner';
    require_once views . 'includes/header.php';
    require_once views . 'ai/trip.php';
    require_once views . 'includes/footer.php';
});

$router->get('/ai-trip/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        header('Location: ' . root);
        exit;
    }
    $row = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);
    if (!$row || empty($row['data'])) {
        header('Location: ' . root . 'ai-trip');
        exit;
    }
    $bookingData = json_decode($row['data'], true);
    if (!$bookingData || ($bookingData['type'] ?? '') !== 'ai_trip') {
        header('Location: ' . root . 'ai-trip');
        exit;
    }
    $bookingHash = $hash;
    $_SESSION['ai_trip_booking_hash'] = $hash;
    $_SESSION['ai_trip_booking_data'] = $bookingData;

    $title = 'Book AI Trip';
    require_once views . 'includes/header.php';
    require_once views . 'ai/booking.php';
    require_once views . 'includes/footer.php';
});

// Bootstrap: if user hits /ai-trip/booking without hash, client will create draft
$router->get('/ai-trip/booking', function () use ($SECURE, $db) {
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        header('Location: ' . root);
        exit;
    }
    $title = 'Book AI Trip';
    require_once views . 'includes/header.php';
    ?>
    <div class="min-h-[50vh] flex items-center justify-center p-6" x-data="aiTripBookingBootstrap()" x-init="init()">
      <div class="text-center">
        <svg class="animate-spin mx-auto text-blue-500" width="28" height="28" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/>
          <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <p class="mt-3 text-sm text-gray-600" x-text="msg">Preparing your trip booking…</p>
      </div>
    </div>
    <?php
    $aiTripJs = views . '../assets/js/ai/trip-page.js';
    // views is typically .../app/views/
    $jsPath = dirname(views) . '/../assets/js/ai/trip-page.js';
    if (!is_file($jsPath)) {
        $jsPath = __DIR__ . '/../../../assets/js/ai/trip-page.js';
    }
    ?>
    <script src="<?= root ?>assets/js/ai/trip-page.js?v=<?= is_file($jsPath) ? filemtime($jsPath) : time() ?>"></script>
    <script>
    function aiTripBookingBootstrap() {
      return {
        msg: 'Preparing your trip booking…',
        async init() {
          const CART_KEY = 'ai_trip_cart_v1';
          let items = [];
          try {
            const raw = sessionStorage.getItem(CART_KEY);
            items = raw ? JSON.parse(raw) : [];
          } catch (e) { items = []; }
          if (!Array.isArray(items) || !items.length) {
            this.msg = 'No items selected. Redirecting…';
            setTimeout(() => { window.location.href = '<?= root ?>ai-trip'; }, 800);
            return;
          }
          const total = items.reduce((s, x) => s + (Number(x.price) || 0), 0);
          try {
            const res = await fetch('<?= root ?>api/ai/trip/save-draft', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                type: 'ai_trip',
                query: sessionStorage.getItem('ai_trip_last_q') || '',
                currency: '<?= htmlspecialchars($_SESSION['app_currency'] ?? ($GLOBALS['app']['currency'] ?? 'USD'), ENT_QUOTES) ?>',
                items, total,
                created_at: new Date().toISOString()
              })
            });
            const data = await res.json();
            if (data && data.success && data.hash) {
              window.location.href = '<?= root ?>ai-trip/booking/' + data.hash;
              return;
            }
            this.msg = (data && data.message) || 'Could not prepare booking.';
          } catch (e) {
            this.msg = 'Could not prepare booking.';
          }
        }
      };
    }
    </script>
    <?php
    require_once views . 'includes/footer.php';
});

$router->get('/ai-trip/confirmation/([A-Za-z0-9]+)', function ($packageId) use ($SECURE, $db) {
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        header('Location: ' . root);
        exit;
    }
    $packageId = preg_replace('/[^A-Za-z0-9]/', '', (string)$packageId);
    if ($packageId === '') {
        header('Location: ' . root . 'ai-trip');
        exit;
    }

    // New model: one combined ai_trip booking (invoice_id / transaction_id = package id)
    $packageBooking = $db->get('bookings', '*', [
        'module_type' => 'ai_trip',
        'invoice_id' => $packageId,
    ]);
    if (!$packageBooking) {
        $packageBooking = $db->get('bookings', '*', [
            'module_type' => 'ai_trip',
            'transaction_id' => $packageId,
        ]);
    }

    $bookings = [];
    $isPackage = false;
    if ($packageBooking) {
        $bookings = [$packageBooking];
        $isPackage = true;
    } else {
        // Back-compat: older multi-row packages linked by transaction_id
        $bookings = $db->select('bookings', '*', [
            'transaction_id' => $packageId,
            'ORDER' => ['id' => 'ASC'],
        ]) ?: [];
    }

    if (empty($bookings)) {
        header('Location: ' . root . 'ai-trip');
        exit;
    }

    $title = 'AI Trip Confirmation';
    require_once views . 'includes/header.php';
    require_once views . 'ai/confirmation.php';
    require_once views . 'includes/footer.php';
});

// Package invoice (AI trip only — does not affect flights/stays/cars invoice routes)
$router->get('/invoice/ai_trip/([A-Za-z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string)$invoiceId);

    // Payment callback (same pattern as cars/flights)
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus && isset($_GET['token'])) {
        require_once 'app/lib/payment-gateway.php';
        $token = (string)($_GET['token'] ?? '');
        if ($token === '') {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Update',
                'message' => 'Missing payment token. Please try again.',
            ];
            header('Location: ' . root . 'invoice/ai_trip/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $bookingLookup = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);
        $extra = [
            'gateway' => $bookingLookup['payment_method'] ?? ($bookingLookup['payment_gateway'] ?? 'unknown'),
            'transaction_id' => $_GET['transaction_id'] ?? ($_GET['session_id'] ?? null),
            'session_id' => $_GET['session_id'] ?? null,
            'module_type' => 'ai_trip',
            'module' => $bookingLookup['module'] ?? 'ai_trip',
            'gateway_data' => $_GET,
        ];
        if ($action !== 'success') {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => 'Payment completed successfully. Your AI trip package is being processed.',
            ];
        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = [
                'type' => 'warning',
                'title' => 'Payment Cancelled',
                'message' => 'You have cancelled the payment. No charges were made.',
            ];
        } else {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Failed',
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.'),
            ];
        }

        header('Location: ' . root . 'invoice/ai_trip/' . $invoiceId);
        exit;
    }

    $booking = $db->get('bookings', '*', [
        'OR' => [
            'id' => $invoiceId,
            'invoice_id' => $invoiceId,
        ],
        'module_type' => 'ai_trip',
    ]);

    if (!$booking) {
        http_response_code(404);
        $title = 'Invoice Not Found';
        $description = 'The requested invoice could not be found';
        $header = true;
        $footer = true;
        require_once views . 'includes/header.php';
        require_once views . 'errors/404.php';
        require_once views . 'includes/footer.php';
        exit;
    }

    $invoiceId = $booking['invoice_id'];

    require_once __DIR__ . '/tripRevalidateHelper.php';
    aiTripEnsureLocalHotelPnr($db, $booking);

    // Owner check when logged in (same as cars)
    if (isset($_SESSION['user_id']) && !empty($booking['user_id'])) {
        $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
        if (!$isAdmin && (string)$booking['user_id'] !== (string)$_SESSION['user_id']) {
            $_SESSION['error'] = 'Unauthorized access';
            header('Location: ' . root . 'bookings');
            exit;
        }
    }

    $title = 'AI Trip Invoice #' . $invoiceId;
    $description = 'View your AI trip package invoice';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'ai/invoice.php';
    require_once views . 'includes/footer.php';
});
