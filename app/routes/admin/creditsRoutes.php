<?php
// app/routes/admin/creditsRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /finance/credits(.*)
$router->get(admin.'/finance/credits(.*)', function ($user_id) use ($SECURE,$db) {

    $user_id = ltrim($user_id, '/');
    $_GET['user_id'] = $user_id;

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::credits_management;

    require_once views."includes/header.php";
    require_once "app/views/admin/finance/credits.php";
    require_once views."includes/footer.php";

});

// ================================ POST /finance/credits
$router->post(admin.'/finance/credits', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    // CSRF (audit money-integrity): this POST credits/debits an agent wallet, so
    // it must carry a valid token. Was previously unguarded.
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token. Please try again.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/finance/credits');
        return;
    }

    // FORM DATA VALIDATION
    $user_id = $_POST['user_id'] ?? 0;
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $credits = intval($_POST['credits'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    // VALIDATION RULES
    $errors = [];

    if ($user_id <= 0) {
        $errors[] = T::please_select_valid_user;
    }

    if (empty($transaction_type)) {
        $errors[] = T::transaction_type_required;
    } elseif (!in_array($transaction_type, ['credit', 'debit'])) {
        $errors[] = T::invalid_transaction_type;
    }

    if ($credits <= 0) {
        $errors[] = T::credits_must_be_positive;
    }

    if (!empty($description) && strlen($description) > 255) {
        $errors[] = T::description_max_length;
    }

    // CHECK IF USER EXISTS AND GET CURRENT CREDITS
    $user = null;
    if ($user_id > 0) {
        $user = $db->get('users', ['user_id', 'credit_limits'], ['user_id' => $user_id]);
        if (!$user) {
            $errors[] = T::user_not_exist;
        }
    }

    // ADDITIONAL VALIDATION FOR DEBIT TRANSACTIONS
    if ($transaction_type === 'debit' && $user) {
        // SIRF CREDIT_LIMITS CHECK KARENGE (AVAILABLE BALANCE)
        if ($credits > $user['credit_limits']) {
            $errors[] = T::insufficient_credits . " " . T::available . ": {$user['credit_limits']}, " . T::requested . ": {$credits}";
        }
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/finance/credits/' . $user_id);
        return;
    }

    $currency = $_POST['currency'] ?? $db->get('currencies', 'name', ['default' => '1']);
    $adminRef = 'ADMINCR-' . time() . '-' . bin2hex(random_bytes(4));

    try {
        // MONEY MOVEMENT VIA THE SPINE (audit money-integrity): route the admin
        // credit/debit through wallet_refund()/wallet_spend() so it is traceable
        // (money_transactions + wallet_ledger + journey) AND — because the spine
        // mirrors AGENT wallets to the legacy `credits` ledger — it writes exactly
        // ONE `credits` row (no double count). This replaces the old direct
        // $db->insert('credits', ...) which had no spine record and no journey.
        if (!function_exists('wallet_refund')) {
            require_once dirname(__DIR__, 2) . '/lib/wallet.php';
        }
        $spineOk = true;
        if ($transaction_type === 'credit') {
            $sr = wallet_refund($db, (string) $user_id, (float) $credits, (string) $currency, [
                'reason' => 'adjustment', 'ref_type' => 'admin_credit', 'ref_id' => $adminRef,
                'idempotency_key' => $adminRef,
                'note' => 'Admin credit' . ($description !== '' ? ': ' . $description : ''),
            ]);
            $spineOk = !empty($sr['ok']);
        } else { // debit
            $sr = wallet_spend($db, (string) $user_id, (float) $credits, (string) $currency, [
                'reason' => 'adjustment', 'method' => 'manual', 'allow_credit_line' => true,
                'ref_type' => 'admin_credit', 'ref_id' => $adminRef,
                'idempotency_key' => $adminRef,
                'note' => 'Admin debit' . ($description !== '' ? ': ' . $description : ''),
            ]);
            $spineOk = !empty($sr['ok']);
        }
        $result = $spineOk;

        if ($result) {
            // Keep the agent's member tier in step with the new lifetime balance.
            if (function_exists('agent_recompute_tier')) { agent_recompute_tier($db, (string) $user_id); }
            // UPDATE USER'S CREDIT_LIMITS BASED ON TRANSACTION TYPE
            if ($transaction_type === 'credit') {
                $new_credit_limits = $user['credit_limits'] + $credits;
            } else {
                // DEBIT MEIN BHI CREDIT_LIMITS KO MINUS KARENGE
                $new_credit_limits = $user['credit_limits'] - $credits;
                if ($new_credit_limits < 0) {
                    $new_credit_limits = 0;
                }
            }

            $update_data = ['credit_limits' => $new_credit_limits];

            // If this is a RECHARGE (credit), reset the usage date
            if ($transaction_type === 'credit') {
                $update_data['first_credit_usage_date'] = null;
            }

            // If this is the FIRST credit usage (debit), record the date
            // This date is used by the cron job to calculate when payment is due
            if ($transaction_type === 'debit') {
                $existing_usage = $db->get('users', 'first_credit_usage_date', ['user_id' => $user_id]);
                if (empty($existing_usage)) {
                    $update_data['first_credit_usage_date'] = date('Y-m-d H:i:s');
                }
            }

            $db->update('users', $update_data, ['user_id' => $user_id]);

            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::credits_processed_successfully
            ];

        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_process_credits
            ];
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    // REDIRECT BACK TO CREDITS PAGE WITH USER_ID
    redirect(root . admin . '/finance/credits/' . $user_id);
});

// ================================ USER SEARCH SUGGESTION - AJAX
$router->post(admin.'/user-search-suggestion', function () use ($SECURE,$db) {
    // CLEAN ALL OUTPUT BUFFERS
    while (ob_get_level()) {
        ob_end_clean();
    }

    // SET JSON HEADERS
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // GET AND VALIDATE QUERY
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (empty($query) || strlen($query) < 2) {
        echo json_encode([]);
        exit(0);
    }

    try {
        // SEARCH USERS
        $users = $db->select('users',
            [
                'user_id',
                'first_name',
                'last_name',
                'email',
                'credit_limits',
                'credit_payment_days',
                'first_credit_usage_date',
                'credit_usage_reminder_percent',
            ],
            [
                'OR' => [
                    'first_name[~]' => $query,
                    'last_name[~]' => $query,
                    'email[~]' => $query,
                    'user_id' => $query
                ],
                'status' => 'active',
                'LIMIT' => 10
            ]
        );

        // FORMAT RESULTS WITH USED CREDITS CALCULATION
        $results = [];
        foreach ($users as $user) {
            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            $credit_limit = floatval($user['credit_limits'] ?? 0);

            $used_credits = floatval($db->sum('credits', 'credits', [
                'user_id' => $user['user_id'],
                'type' => 'debit'
            ]) ?: 0);
            $assigned_credits = floatval($db->sum('credits', 'credits', [
                'user_id' => $user['user_id'],
                'type' => 'credit'
            ]) ?: 0);

            $usage_percent = max(0, min(100, intval($user['credit_usage_reminder_percent'] ?? 0)));
            $usage_threshold = 0;
            if ($usage_percent > 0 && $assigned_credits > 0) {
                $usage_threshold = (int) floor($assigned_credits * $usage_percent / 100);
            }
            $payment_days = intval($user['credit_payment_days'] ?? 0);
            $first_usage_date = $user['first_credit_usage_date'] ?? null;
            $due_date = null;
            if (!empty($first_usage_date) && $payment_days > 0) {
                try {
                    $due = new DateTime($first_usage_date);
                    $due->modify("+{$payment_days} days");
                    $due_date = $due->format('Y-m-d');
                } catch (Throwable $e) {
                    $due_date = null;
                }
            }

            $available_credits = $credit_limit;

            $results[] = [
                'id' => $user['user_id'],
                'user_id' => $user['user_id'],
                'name' => $name,
                'email' => $user['email'],
                'full_name' => $name . ' - ' . $user['email'],
                'display_text' => $name . ' - ' . $user['email'],
                'credit_limits' => $credit_limit,
                'available_credits' => $available_credits,
                'used_credits' => $used_credits,
                'assigned_credits' => $assigned_credits,
                'usage_percent' => $usage_percent,
                'usage_threshold' => $usage_threshold,
                'payment_days' => $payment_days,
                'first_usage_date' => $first_usage_date,
                'due_date' => $due_date,
            ];
        }

        echo json_encode($results);

    } catch (Exception $e) {
        echo json_encode([]);
    }

    exit(0);
});
