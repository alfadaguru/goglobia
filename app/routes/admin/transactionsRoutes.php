<?php
// app/routes/admin/transactionsRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /finance/transactions(.*)
$router->get(admin.'/finance/transactions(.*)', function ($user_id) use ($SECURE,$db) {

    $user_id = ltrim($user_id, '/');
    $_GET['user_id'] = $user_id;

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::transactions_management;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/finance/transactions.php";
    require_once views."includes/footer.php";

});

// ================================ POST /finance/transactions
$router->post(admin.'/finance/transactions', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    // CSRF (audit money-integrity): this POST moves money / adjusts a credit line,
    // so it must carry a valid token. Was previously unguarded.
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token. Please try again.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/finance/transactions');
        return;
    }

    // FORM DATA VALIDATION AND SANITIZATION
    $user_id = $_POST['user_id'] ?? 0;
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0); // Changed to float for decimal support
    $description = trim(strip_tags($_POST['description'] ?? ''));
    $currency = trim(strip_tags($_POST['currency'] ?? ''));
    $payment_gateway = trim(strip_tags($_POST['payment_gateway'] ?? 'manual'));
    $attachment = $_FILES['attachment'] ?? null;

    // VALIDATION RULES
    $errors = [];

    if (empty($user_id)) {
        $errors[] = T::please_select_valid_user;
    }

    if (empty($transaction_type)) {
        $errors[] = T::transaction_type_required;
    } elseif (!in_array($transaction_type, ['credit', 'debit', 'purchase', 'refund'])) {
        $errors[] = T::invalid_transaction_type;
    }

    if ($amount <= 0) {
        $errors[] = T::amount_must_be_positive;
    }

    if (!empty($description) && strlen($description) > 255) {
        $errors[] = T::description_max_length;
    }

    // CHECK IF USER EXISTS AND GET CURRENT BALANCE
    $user = null;
    if ($user_id > 0) {
        $user = $db->get('users', ['user_id', 'credit_limits'], ['user_id' => $user_id]);
        if (!$user) {
            $errors[] = T::user_not_exist;
        }
    }

    // ADDITIONAL VALIDATION FOR DEBIT/PURCHASE TRANSACTIONS
    if (in_array($transaction_type, ['debit', 'purchase']) && $user) {
        if ($amount > $user['credit_limits']) {
            $errors[] = T::insufficient_balance . " " . T::available . ": {$user['credit_limits']}, " . T::requested . ": {$amount}";
        }
    }

    // FILE UPLOAD VALIDATION
    $attachment_path = null;
    if ($attachment && $attachment['error'] === UPLOAD_ERR_OK) {
        // SECURITY: validate real MIME + safe extension (finfo).
        $chk = secureUploadCheck($attachment, ['jpg', 'jpeg', 'png', 'gif', 'pdf'], 5 * 1024 * 1024);
        if (!$chk['ok']) {
            $errors[] = $chk['error'] ?? T::invalid_file_type;
        } else {
            // Generate unique filename
            $filename = 'attachment_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $chk['ext'];
            $upload_dir = 'uploads/transactions/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if (move_uploaded_file($attachment['tmp_name'], $upload_dir . $filename)) {
                @chmod($upload_dir . $filename, 0644);
                $attachment_path = $upload_dir . $filename;
            } else {
                $errors[] = T::file_upload_failed;
            }
        }
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/finance/transactions/' . $user_id);
        return;
    }

    // Get default currency if not provided
    if (empty($currency)) {
        $currency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';
    }

    // Generate transaction ID
    $trx_id = 'TRX_' . time() . '_' . uniqid();

    // PREPARE DATA FOR INSERTION IN transactions TABLE
    // NOTE: the column is `gateway_id` (varchar), not `payment_gateway` — inserting
    // the wrong column name made EVERY admin credit/debit fail with SQLSTATE 42S22
    // ("Unknown column 'payment_gateway'"), so no transaction was recorded and no
    // wallet was credited. Existing rows store the gateway id (e.g. '21') or a
    // label like 'manual' here.
    $transaction_data = [
        'user_id' => $user_id,
        'trx_id' => $trx_id,
        'type' => $transaction_type,
        'amount' => $amount,
        'description' => $description,
        'currency' => $currency,
        'gateway_id' => $payment_gateway,
        'attachment' => $attachment_path,
        'status' => 'approved',
        'created_by' => $_SESSION['admin_id'] ?? 'Admin',
        'date' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    try {
        // START TRANSACTION FOR DATA CONSISTENCY
        $db->pdo->beginTransaction();

        // INSERT INTO transactions TABLE
        $result = $db->insert('transactions', $transaction_data);

        if ($result) {
            // UPDATE USER'S CREDIT_LIMITS BASED ON TRANSACTION TYPE
            $new_credit_limits = $user['credit_limits'];

            switch ($transaction_type) {
                case 'credit':
                case 'refund':
                    $new_credit_limits += $amount;
                    break;
                case 'debit':
                case 'purchase':
                    $new_credit_limits -= $amount;
                    if ($new_credit_limits < 0) {
                        $new_credit_limits = 0;
                    }
                    break;
            }

            $db->update('users',
                ['credit_limits' => $new_credit_limits],
                ['user_id' => $user_id]
            );

            $db->pdo->commit();

            // SPINE MIRROR (audit money-integrity): record this admin adjustment in
            // the unified money spine too, so it is traceable (money_transactions +
            // wallet_ledger + journey) and the agent's wallet + member-tier reflect
            // it. Runs AFTER commit (wallet_apply opens its own transaction — must
            // not nest). Best-effort: never fail the admin action on a mirror error.
            try {
                if (!function_exists('wallet_refund')) {
                    require_once dirname(__DIR__, 2) . '/lib/wallet.php';
                }
                $mirrorCur = $currency;
                if (in_array($transaction_type, ['credit', 'refund'], true) && function_exists('wallet_refund')) {
                    wallet_refund($db, (string) $user_id, (float) $amount, (string) $mirrorCur, [
                        'reason' => 'adjustment', 'ref_type' => 'admin_txn', 'ref_id' => $trx_id,
                        'idempotency_key' => 'ADMINTXN-' . $trx_id,
                        'note' => 'Admin ' . $transaction_type . ($description !== '' ? ': ' . $description : ''),
                    ]);
                    if (function_exists('agent_recompute_tier')) { agent_recompute_tier($db, (string) $user_id); }
                } elseif (in_array($transaction_type, ['debit', 'purchase'], true) && function_exists('wallet_spend')) {
                    wallet_spend($db, (string) $user_id, (float) $amount, (string) $mirrorCur, [
                        'reason' => 'adjustment', 'method' => 'manual', 'allow_credit_line' => true,
                        'ref_type' => 'admin_txn', 'ref_id' => $trx_id,
                        'idempotency_key' => 'ADMINTXN-' . $trx_id,
                        'note' => 'Admin ' . $transaction_type . ($description !== '' ? ': ' . $description : ''),
                    ]);
                }
            } catch (\Throwable $eMirror) { error_log('admin transaction spine mirror: ' . $eMirror->getMessage()); }

            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::transaction_processed_successfully
            ];

        } else {
            $db->pdo->rollBack();
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_process_transaction
            ];
        }

    } catch (Exception $e) {
        $db->pdo->rollBack();
        // Delete uploaded file if transaction failed
        if ($attachment_path && file_exists($attachment_path)) {
            unlink($attachment_path);
        }
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    // REDIRECT BACK TO transactions PAGE WITH USER_ID
    redirect(root . admin . '/finance/transactions/' . $user_id);
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
    $query = isset($_POST['query']) ? trim(strip_tags($_POST['query'])) : '';

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
                'credit_limits'
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

        // FORMAT RESULTS
        $results = [];
        foreach ($users as $user) {
            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            $credit_limit = $user['credit_limits'] ?? 0;

            $results[] = [
                'id' => $user['user_id'],
                'user_id' => $user['user_id'],
                'name' => $name,
                'email' => $user['email'],
                'full_name' => $name . ' - ' . $user['email'],
                'display_text' => $name . ' - ' . $user['email'],
                'credit_limits' => $credit_limit,
                'available_balance' => $credit_limit
            ];
        }

        echo json_encode($results);

    } catch (Exception $e) {
        error_log("User search error: " . $e->getMessage());
        echo json_encode([]);
    }

    exit(0);
});