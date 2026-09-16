<?php
// app/routes/globalRoutes.php
@$SECURE or die('Access Denied!');

// ========================================================= flights-airport-suggestion
$router->post('/flights-airport-suggestion', function () use ($SECURE, $db) {
    // Clean all output buffers and disable profiler
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set JSON headers before any output
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // Get and validate query
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (empty($query)) {
        echo json_encode([]);
        exit(0);
    }

    // Initialize cURL
    try {
    $ch = curl_init();

    $url = 'https://www.kayak.com/mvm/smartyv2/search?f=j&s=airportonly&where=' . urlencode($query);

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    // Handle errors
    if ($error || $httpCode !== 200 || empty($response)) {
        echo json_encode([]);
        exit(0);
    }

    // Validate JSON before sending
    $decoded = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([]);
        exit(0);
    }

    // Send valid JSON response
    echo $response;
    exit(0);
    } catch (\Throwable $e) {
        error_log("AIRPORT_SUGGESTION ERROR: " . $e->getMessage());
        echo json_encode([]);
        exit(0);
    }
});


// ========================================================= API: Store booking data in session
$router->post('/api/store-booking', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $bookingData = json_decode(file_get_contents('php://input'), true);

        if (!$bookingData) {
            throw new Exception('Invalid booking data');
        }

        // Store in session
        $_SESSION['flight_booking_data'] = $bookingData;

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
});

// ========================================================= API: Clear checkout session
$router->post('/api/clear-checkout', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    unset($_SESSION['flight_checkout_data']);
    unset($_SESSION['flight_checkout_time']);

    echo json_encode(['success' => true]);
    exit;
});

// ========================================================= flights-airlines (single or batch)
$router->post('/flights-airlines', function () use ($SECURE, $db) {

    // Set JSON headers before any output
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // Check if batch request (multiple codes)
    if (isset($_POST['codes']) && is_array($_POST['codes'])) {
        // BATCH REQUEST - return multiple airlines
        $codes = array_filter($_POST['codes']);

        if (empty($codes)) {
            echo json_encode([]);
            exit(0);
        }

        // Fetch all airlines in one query
        $airlines = $db->select("flights_airlines", ["code", "name"], [
            "code" => $codes
        ]);

        // Create associative array: code => name
        $result = [];
        foreach ($airlines as $airline) {
            $result[$airline['code']] = $airline['name'];
        }

        echo json_encode($result);
        exit(0);
    }

    // SINGLE REQUEST - backward compatibility
    $code = $_POST['code'] ?? '';

    if (empty($code)) {
        echo json_encode('');
        exit(0);
    }

    $airlines = $db->select("flights_airlines", "*", [
        "code" => $code
    ], [
        "ORDER" => ["name" => "ASC"]
    ]);

    echo json_encode($airlines[0]['name'] ?? $code);
    exit(0);

});

// ========================================================= CHANGE LANGUAGE
$router->get('/lang', function () use ($SECURE, $db) {

    $lang_code = $_GET['lang'] ?? null;

    // // Validate and get language from database
    if ($lang_code) {
        $language = $db->get("languages", "*", [
            "lang_code" => $lang_code,
            "status" => "1"
        ]);

        if ($language) {
            $_SESSION['app_language'] = $language['lang_code'];
            $_SESSION['app_language_dir'] = $language['type'];
            $_SESSION['app_language_name'] = $language['name'];
        }
    }

    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
    exit;
});

// ========================================================= CHANGE CURRENCY
$router->get('/currency', function () use ($SECURE, $db) {

    $currency_code = $_GET['currency'] ?? null;

    // Validate and get currency from database with country name
    if ($currency_code) {
        $currency = $db->get("currencies", [
            "[>]countries" => ["country" => "iso"]
        ], [
            "currencies.name",
            "currencies.rate",
            "currencies.country",
            "countries.nicename(country_name)"
        ], [
            "currencies.name" => $currency_code,
            "currencies.status" => "1"
        ]);

        if ($currency) {
            $_SESSION['app_currency'] = $currency['name'];
            $_SESSION['app_currency_rate'] = $currency['rate'];
            $_SESSION['app_currency_country'] = $currency['country'];
            $_SESSION['app_currency_country_name'] = $currency['country_name'];
            $_SESSION['app_currency_changed'] = true;
        }
    }

    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
    exit;
});

// ========================================================= LAZY SEARCH FORM PARTIALS
// RETURNS A MODULE'S COMPLETE, UNMODIFIED SEARCH FORM FOR THE HOMEPAGE TABS —
// FETCHED ONLY WHEN A TAB IS ACTIVATED SO THE FORMS ARE NOT IN THE FIRST-PAINT DOM.
$router->get('/partials/search/([a-z]+)', function ($type) use ($SECURE, $db) {

    $enabled = array_column($GLOBALS['modules'] ?? [], 'type');
    $searchFile = views . 'modules/' . $type . '/' . $type . '-search.php';

    if (!in_array($type, $enabled, true) || !file_exists($searchFile)) {
        http_response_code(404);
        exit;
    }

    include_once $searchFile;
    exit;
});

// ========================================================= LAZY HTML PARTIALS
// SERVED ON DEMAND (E.G. WHEN A HEADER DROPDOWN OPENS) TO KEEP FIRST-PAINT DOM SMALL.
// ROUTE PATTERN RESTRICTS NAMES TO [a-z-] SO NO PATH TRAVERSAL IS POSSIBLE.
$router->get('/partials/([a-z-]+)', function ($partial) use ($SECURE, $db) {

    $file = __DIR__ . "/../views/partials/{$partial}.php";

    if (!file_exists($file)) {
        http_response_code(404);
        exit;
    }

    require $file;
    exit;
});

// ========================================================= MODULE ENDPOINTS
$router->get('/modules/([^/]+)/([^/]+)/([^/]+)', function ($moduleType, $moduleName, $endpoint) use ($SECURE, $db) {

    // Sanitize parameters to prevent directory traversal
    $moduleType = preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleType);
    $moduleName = preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleName);
    $endpoint = preg_replace('/[^a-zA-Z0-9_-]/', '', $endpoint);

    // Build the file path
    $filePath = 'modules/' . $moduleType . '/' . $moduleName . '/' . $endpoint . '.php';

    // Check if file exists and is readable
    if (file_exists($filePath) && is_readable($filePath)) {
        // Include the module endpoint file
        include $filePath;
        exit;
    } else {
        // Return 404 if file not found
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Module endpoint not found',
            'endpoint' => $moduleType . '/' . $moduleName . '/' . $endpoint
        ]);
        exit;
    }
});

$router->post('/modules/([^/]+)/([^/]+)/([^/]+)', function ($moduleType, $moduleName, $endpoint) use ($SECURE, $db) {

    // Sanitize parameters to prevent directory traversal
    $moduleType = preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleType);
    $moduleName = preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleName);
    $endpoint = preg_replace('/[^a-zA-Z0-9_-]/', '', $endpoint);

    // Build the file path
    $filePath = 'modules/' . $moduleType . '/' . $moduleName . '/' . $endpoint . '.php';

    // Check if file exists and is readable
    if (file_exists($filePath) && is_readable($filePath)) {
        // Include the module endpoint file
        include $filePath;
        exit;
    } else {
        // Return 404 if file not found
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Module endpoint not found',
            'endpoint' => $moduleType . '/' . $moduleName . '/' . $endpoint
        ]);
        exit;
    }
});

// Process Payment POST
$router->post('/payment/process', function () use ($SECURE, $db) {
    require_once 'app/lib/payment-gateway.php';

    $invoiceId = $_POST['invoice_id'] ?? '';
    $gatewayId = $_POST['gateway_id'] ?? '';

    if (!$invoiceId || !$gatewayId) {
        echo "Missing payment data";
        exit;
    }

    // Get booking and gateway details
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);

    if (!$booking || !$gateway) {
        echo "Invalid payment data";
        exit;
    }

    // Mozio hosted_checkout: never charge a site gateway (would double-bill).
    if (strtolower((string)($booking['module_type'] ?? '')) === 'cars' && strtolower((string)($booking['module'] ?? '')) === 'mozio') {
        require_once 'modules/cars/mozio/lib.php';

        if (mozioIsHostedCheckout($db)) {
            $issueResult = mozioIssueReservation($db, (string)$invoiceId);

            if (!empty($issueResult['status'])) {
                if (!empty($issueResult['requires_mozio_payment']) && !empty($issueResult['mozio_redirect_url'])) {
                    mozioRememberPendingCheckout((string)$invoiceId, (string)($issueResult['search_id'] ?? ''));
                    header('Location: ' . $issueResult['mozio_redirect_url']);
                    exit;
                }

                header('Location: ' . root . 'invoice/cars/' . $invoiceId);
                exit;
            }

            $_SESSION['error'] = 'Could not start Mozio checkout: ' . ($issueResult['message'] ?? 'Unknown error');
            header('Location: ' . root . 'invoice/cars/' . $invoiceId);
            exit;
        }

        $mozioCfg = mozioModuleConfig($db);

        // Direct card tokenization (payment mode #2 in Mozio's docs) needs a
        // Mozio-issued Stripe publishable key and a documented reservation
        // field name for the resulting token — neither is available yet, so
        // block rather than silently charge through the wrong path.
        if ($mozioCfg && $mozioCfg['payment_mode'] === 'tokenized') {
            $_SESSION['error'] = 'Direct card payment for Mozio bookings is not yet available. Please choose a different payment mode in the Mozio module settings.';
            header('Location: ' . root . 'invoice/cars/' . $invoiceId);
            exit;
        }
    }

    // Create payment token
    $token = create_payment_token($booking, $gateway);

    // Log payment transaction and check attempt limit
    $logResult = log_payment_transaction($booking, $gateway, $token);

    if (!$logResult['success']) {
        // Attempt limit reached or error
        $_SESSION['payment_notice'] = [
            'type' => 'error',
            'title' => 'Payment Blocked',
            'message' => $logResult['message']
        ];
        header('Location: ' . root . 'invoice/' . $invoiceId);
        exit;
    }

    // Redirect to secure payment page with hash
    header('Location: ' . root . 'payment/' . $logResult['hash']);
    exit;
});

// ============================================================================
// SECURE PAYMENT PAGE ROUTE - /payment/{hash}
// Displays payment page with logged transaction data
// MUST BE BEFORE generic /payment/(.+) route
// ============================================================================
$router->get('/payment/([a-f0-9]{32})', function ($hash) use ($SECURE, $db) {
    require_once 'app/lib/payment-gateway.php';

    // Get transaction from logs
    $transaction = get_payment_transaction($hash);

    if (!$transaction || !$transaction['data']) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invalid or expired payment link'
        ];
        header('Location: ' . root);
        exit;
    }

    $transactionData = $transaction['data'];
    $booking = $transactionData['booking_data'];
    $gateway = $transactionData['gateway_config'];
    $token = $transactionData['token'];

    // Prepare payment data for gateway — module-aware callback URL
    $_mType = $booking['module_type'] ?? '';
    $_iPrefix = match($_mType) {
        'ferries' => 'invoice/ferries/',
        'tours'   => 'invoice/tours/',
        'flights' => 'invoice/flights/',
        'stays'   => 'invoice/stays/',
        'cars'    => 'invoice/cars/',
        'visa'    => 'invoice/visa/',
        'esim'    => 'invoice/esim/',
        'bus'     => 'invoice/bus/',
        'rail'    => 'invoice/rail/',
        default   => 'invoice/',
    };
    $callbackBase = root . $_iPrefix . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=';
    $paymentData = [
        'booking' => $booking,
        'gateway' => $gateway,
        'token' => $token,
        'success_url' => $callbackBase . 'success',
        'cancel_url' => $callbackBase . 'cancel',
        'failure_url' => $callbackBase . 'failure',
    ];

    // Prepare legacy payload format for gateways. Charge the amount DUE NOW
    // (deposit/installment-aware for umrah), not always the full total (M7).
    $payload = [
        'booking_ref_no' => $booking['ref'] ?? $booking['invoice_id'],
        'invoice_id' => $booking['invoice_id'],
        'client_email' => $booking['email'],
        'price' => function_exists('payment_amount_due') ? payment_amount_due($booking, $db) : $booking['price_markup'],
        'currency' => $booking['currency_markup'],
        'invoice_url' => root . 'invoice/' . $booking['invoice_id']
    ];
    $_POST['payload'] = base64_encode(json_encode((object) $payload));
    $_POST['success_url'] = $paymentData['success_url'];
    $_POST['cancel_url'] = $paymentData['cancel_url'];
    $_POST['failure_url'] = $paymentData['failure_url'];

    // Render payment page (standalone - no header/footer)
    $title = 'Proceed to Payment - ' . $GLOBALS['app']['home_title'];
    $description = 'Complete your payment for invoice ' . $booking['invoice_id'];

    require_once views . 'payment-gateways/pay_view.php';
});

// ============================================================================
// GATEWAY LOADER ROUTE - /payment/gateway/{hash}
// Loads gateway content via AJAX
// MUST BE BEFORE generic /payment/(.+) route
// ============================================================================
$router->get('/payment/gateway/([a-f0-9]{32})', function ($hash) use ($SECURE, $db) {
    require_once 'app/lib/payment-gateway.php';

    // Get transaction from logs
    $transaction = get_payment_transaction($hash);

    if (!$transaction || !$transaction['data']) {
        echo '<p style="color:red;text-align:center;">Invalid or expired payment link</p>';
        exit;
    }

    $transactionData = $transaction['data'];
    $booking = $transactionData['booking_data'];
    $gateway = $transactionData['gateway_config'];
    $token = $transactionData['token'];

    // Prepare payment data for gateway — module-aware callback URL
    $_mType2 = $booking['module_type'] ?? '';
    $_iPrefix2 = match($_mType2) {
        'ferries' => 'invoice/ferries/',
        'tours'   => 'invoice/tours/',
        'flights' => 'invoice/flights/',
        'stays'   => 'invoice/stays/',
        'cars'    => 'invoice/cars/',
        'visa'    => 'invoice/visa/',
        'esim'    => 'invoice/esim/',
        'bus'     => 'invoice/bus/',
        'rail'    => 'invoice/rail/',
        default   => 'invoice/',
    };
    $callbackBase = root . $_iPrefix2 . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=';
    $paymentData = [
        'booking' => $booking,
        'gateway' => $gateway,
        'token' => $token,
        'success_url' => $callbackBase . 'success',
        'cancel_url' => $callbackBase . 'cancel',
        'failure_url' => $callbackBase . 'failure',
    ];

    // Prepare legacy payload format. Charge the amount DUE NOW (deposit/
    // installment-aware for umrah), not always the full total (M7).
    $payload = [
        'booking_ref_no' => $booking['ref'] ?? $booking['invoice_id'],
        'invoice_id' => $booking['invoice_id'],
        'client_email' => $booking['email'],
        'price' => function_exists('payment_amount_due') ? payment_amount_due($booking, $db) : $booking['price_markup'],
        'currency' => $booking['currency_markup'],
        'invoice_url' => root . 'invoice/' . $booking['invoice_id']
    ];
    $_POST['payload'] = base64_encode(json_encode((object) $payload));
    $_POST['success_url'] = $paymentData['success_url'];
    $_POST['cancel_url'] = $paymentData['cancel_url'];
    $_POST['failure_url'] = $paymentData['failure_url'];

    // Get full gateway record from database
    $gatewayFull = $db->get('payment_gateways', '*', ['id' => $gateway['id']]);
    if ($gatewayFull) {
        $gateway = $gatewayFull;
        // Update paymentData with full gateway info
        $paymentData['gateway'] = $gatewayFull;
    }

    // Load gateway file
    $gatewayName = strtolower(str_replace(' ', '_', $gateway['name']));
    $gatewayFile = __DIR__ . '/../views/payment-gateways/' . $gatewayName . '.php';

    if (file_exists($gatewayFile)) {
        include $gatewayFile;
    } else {
        echo '<p style="color:red;text-align:center;">Payment gateway not supported</p>';
    }
    exit;
});

// Payment selection handler (placed after specific routes to avoid conflicts)
$router->get('/payment/(.+)', function ($invoiceId) use ($SECURE, $db) {
    // Show payment selection page
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) {
        echo "<h3>Invoice not found</h3>";
        echo "<a href='" . root . "'>Back to Home</a>";
        exit;
    }

    // SECURITY (IDOR): this legacy fallback page echoed the invoice amount and a
    // payment form for ANY invoice_id, leaking pricing/existence of other
    // customers' bookings. Restrict to admin / owner / creating session / valid
    // payment token (the real flow uses /payment/process -> /payment/{hash}).
    if (function_exists('enforceInvoiceAccess')) {
        enforceInvoiceAccess($db, $booking);
    }
    // Reflected-XSS: $invoiceId was echoed raw into HTML/attribute below.
    $safeInvoiceId = htmlspecialchars((string) $invoiceId, ENT_QUOTES, 'UTF-8');

    $gateways = $db->select('payment_gateways', ['id', 'name', 'display_name'], ['status' => 1]);

    echo "<h3>Payment for Invoice: {$safeInvoiceId}</h3>";
    echo "<p>Amount: " . htmlspecialchars((string) $booking['currency_markup'], ENT_QUOTES, 'UTF-8') . " " . number_format((float) $booking['price_markup'], 2) . "</p>";
    echo "<form method='POST' action='" . root . "payment/process'>";
    echo "<input type='hidden' name='invoice_id' value='{$safeInvoiceId}'>";
    echo "<select name='gateway_id' required>";
    foreach ($gateways as $gateway) {
        echo "<option value='" . htmlspecialchars((string) $gateway['id'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars(getGatewayDisplayName($gateway)) . "</option>";
    }
    echo "</select>";
    echo "<button type='submit'>Continue to Payment</button>";
    echo "</form>";
    exit;
});

// Generic invoice route - auto-detects module and redirects
$router->get('/invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    // Get booking to determine module type
    $booking = $db->get('bookings', ['module_type', 'module'], ['invoice_id' => $invoiceId]);

    if (!$booking) {
        header('Location: ' . root);
        exit;
    }

    // Redirect to module-specific invoice route
    $moduleType = $booking['module_type'] ?? 'stays';
    header('Location: ' . root . 'invoice/' . $moduleType . '/' . $invoiceId . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
});

$router->get('sd', function () use ($SECURE,$db) {
    session_destroy();
    echo "Session destroyed.";
});