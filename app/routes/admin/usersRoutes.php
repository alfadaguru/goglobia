<?php
// app/routes/admin/usersRoutes.php
@$SECURE or die('Access Denied!');

//==============================================================
// USERS
$router->get(admin.'/users', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Users';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/users.php";
    require_once views."includes/footer.php";

});

//==============================================================
// USERS - POST (handles bulk delete, toggle status, etc)
$router->post(admin.'/users', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Start output buffering to prevent header errors
    ob_start();

    // META DATA
    $title = 'Users';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/users.php";
    require_once views."includes/footer.php";

    // If we reach here, flush the output (CRUD didn't redirect)
    ob_end_flush();

});

//==============================================================
// USERS EDIT
$router->get(admin.'/users/edit/(.+)', function ($user_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATAz
    $title = 'Edit User';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/user.php";
    require_once views."includes/footer.php";

});

//==============================================================
// USER ADD
$router->get(admin.'/users/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add User';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/user.php";
    require_once views."includes/footer.php";

});

//==============================================================
// USERS CREATE/UPDATE POST
$router->post(admin.'/users/save', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_user') {

        // CSRF token check — CSRF::validateToken() does a constant-time hash_equals
        // compare AND enforces the 1-hour token expiry, unlike the raw !== this
        // replaced (timing-leaky, never-expiring). Accept the token from the POST
        // field or the X-CSRF-TOKEN header (fetch/XHR path).
        if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $_SESSION['error'] = 'Invalid CSRF token';
            header('Location: ' . root . 'admin/users');
            exit;
        }

        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'phone_country_code' => trim($_POST['phone_country_code'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'address2' => trim($_POST['address2'] ?? ''),
            'country' => trim($_POST['country'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'po_box' => trim($_POST['po_box'] ?? ''),
            'role' => trim($_POST['role'] ?? 'customer'),
            'status' => trim($_POST['status'] ?? 'active'),
            'banned' => intval($_POST['banned'] ?? 0),
            'timezone' => trim($_POST['timezone'] ?? 'UTC'),
            'language' => trim($_POST['language'] ?? 'en'),
        ];

        // Credit reminder fields live in the agent sidebar (outside this form) and are
        // saved via AJAX. Only persist them here when they were actually posted.
        if (isset($_POST['credit_days']) && $_POST['credit_days'] !== '') {
            $data['credit_payment_days'] = intval($_POST['credit_days']);
        }
        if (isset($_POST['credit_usage_reminder_percent']) && $_POST['credit_usage_reminder_percent'] !== '') {
            $data['credit_usage_reminder_percent'] = max(0, min(100, intval($_POST['credit_usage_reminder_percent'])));
        }

        // Validation
        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
            $_SESSION['error'] = 'First name, last name, and email are required';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Invalid email format';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        // Check if edit or create
        $isEdit = !empty($_POST['user_id']);

        if ($isEdit) {
            // Update existing user
            $user_id = $_POST['user_id'];
            $data['updated_at'] = date('Y-m-d H:i:s');

            // Check if email already exists for another user
            if ($db->has('users', [
                'email' => $data['email'],
                'user_id[!]' => $user_id
            ])) {
                $_SESSION['error'] = 'This email already used';
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }

            if (!empty($_POST['password'])) {
                $data['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
            }

            $result = $db->update('users', $data, ['user_id' => $user_id]);
            if ($result->rowCount() > 0 || $db->error()[0] == '00000') {
                $_SESSION['success'] = 'User updated successfully';
            } else {
                $_SESSION['error'] = 'Failed to update user';
            }

            header('Location: ' . root . 'admin/users/edit/' . $user_id . '#' . ($_POST['current_tab'] ?? 'profile'));
            exit;

        } else {
            // Create new user
            if (empty($_POST['password'])) {
                $_SESSION['error'] = 'Password is required for new users';
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }

            $user_id = $data['user_id'] = generateUserId();
            $data['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');

            // Get default currency from database
            $default_currency = $db->get('currencies', 'name', ['default' => 1]);
            $data['currency'] = $default_currency ?? 'USD'; // Set default currency for new users

            // Check if email already exists
            if ($db->has('users', ['email' => $data['email']])) {
                $_SESSION['error'] = 'This email already used';
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }

            $result = $db->insert('users', $data);
            if ($result->rowCount() > 0) {
                $_SESSION['success'] = 'User created successfully';
                header('Location: ' . root . 'admin/users/edit/' . $user_id . '#' . ($_POST['current_tab'] ?? 'profile'));
                exit;
            } else {
                $_SESSION['error'] = 'Failed to create user: ' . implode(', ', $db->error());
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }
        }
    }
});

//==============================================================
// NOTES POST HANDLING
$router->post(admin.'/users/notes', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    if ($_POST && isset($_POST['action'])) {

        // CSRF token check — CSRF::validateToken() does a constant-time hash_equals
        // compare AND enforces the 1-hour token expiry, unlike the raw !== this
        // replaced (timing-leaky, never-expiring). Accept the token from the POST
        // field or the X-CSRF-TOKEN header (fetch/XHR path).
        if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $_SESSION['error'] = 'Invalid CSRF token';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        if ($_POST['action'] === 'add_note') {
            $noteText = trim($_POST['note_text'] ?? '');
            $userId = trim($_POST['user_id'] ?? '');

            if (empty($noteText)) {
                $_SESSION['error'] = 'Note text is required';
            } elseif (empty($userId)) {
                $_SESSION['error'] = 'User ID is required';
            } else {
                $data = [
                    'user_id' => $userId,
                    'note' => $noteText,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                $result = $db->insert('notes', $data);
                if ($result->rowCount() > 0) {
                    $_SESSION['success'] = 'Note added successfully';
                } else {
                    $_SESSION['error'] = 'Failed to add note: ' . implode(', ', $db->error());
                }
            }

            header('Location: ' . root . 'admin/users/edit/' . $userId . '#' . ($_POST['current_tab'] ?? 'notes'));
            exit;
        }

        // Handle note deletion
        if ($_POST['action'] === 'delete_note') {
            $noteId = intval($_POST['note_id'] ?? 0);
            $userId = trim($_POST['user_id'] ?? '');

            if ($noteId > 0) {
                $result = $db->delete('notes', ['id' => $noteId]);
                if ($result->rowCount() > 0) {
                    $_SESSION['success'] = 'Note deleted successfully';
                } else {
                    $_SESSION['error'] = 'Failed to delete note';
                }
            } else {
                $_SESSION['error'] = 'Invalid note ID';
            }

            header('Location: ' . root . 'admin/users/edit/' . $userId . '#' . ($_POST['current_tab'] ?? 'notes'));
            exit;
        }
    }
});

//==============================================================
// ADMIN USERS ROUTES - FULL RENDERING WITH CRUD
// $router->get(admin.'/users/roles', function () use ($SECURE,$db) {

//     // ADMIN AUTH CHECK
//     ADMIN_AUTH();

//     // META DATA
//     $title = 'Users Roles';
//     $description = '';
//     $header = true;
//     $footer = true;

//     require_once views."includes/header.php";
//     require_once "app/views/admin/users/users-roles.php";
//     require_once views."includes/footer.php";

// });

// //==============================================================
// // USERS ROLES EDIT
// $router->get(admin.'/users/roles/edit/(.+)', function ($role_id) use ($SECURE,$db) {

//     // ADMIN AUTH CHECK
//     ADMIN_AUTH();

//     // META DATA
//     $title = 'Edit Role';
//     $description = '';
//     $header = true;
//     $footer = true;

//     require_once views."includes/header.php";
//     require_once "app/views/admin/users/users-roles-manage.php";
//     require_once views."includes/footer.php";

// });

// //==============================================================
// // USERS ROLES ADD
// $router->get(admin.'/users/roles/add', function () use ($SECURE,$db) {

//     // ADMIN AUTH CHECK
//     ADMIN_AUTH();

//     // META DATA
//     $title = 'Add Role';
//     $description = '';
//     $header = true;
//     $footer = true;

//     require_once views."includes/header.php";
//     require_once "app/views/admin/users/users-roles-manage.php";
//     require_once views."includes/footer.php";

// });

// //==============================================================
// // USERS ROLES ADD - POST
// $router->post(admin.'/users/roles/add', function () use ($SECURE,$db) {

//     // ADMIN AUTH CHECK
//     ADMIN_AUTH();

//     // META DATA
//     $title = 'Add Role';
//     $description = '';
//     $header = true;
//     $footer = true;

//     require_once views."includes/header.php";
//     require_once "app/views/admin/users/users-roles-manage.php";
//     require_once views."includes/footer.php";

// });

// //==============================================================
// // USERS ROLES EDIT - POST
// $router->post(admin.'/users/roles/edit/(.+)', function ($role_id) use ($SECURE,$db) {

//     // ADMIN AUTH CHECK
//     ADMIN_AUTH();

//     // META DATA
//     $title = 'Edit Role';
//     $description = '';
//     $header = true;
//     $footer = true;

//     require_once views."includes/header.php";
//     require_once "app/views/admin/users/users-roles-manage.php";
//     require_once views."includes/footer.php";

// });

//==============================================================
// USERS ADD FUND
$router->get(admin.'/users/manage-funds/(.+)', function ($user_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Manage Funds';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/manage-funds.php";
    require_once views."includes/footer.php";

});

//==============================================================
// PROCESS MANAGE FUNDSprocess-manage-funds
$router->post(admin.'/users/process-manage-funds', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_funds') {

        // CSRF token check — CSRF::validateToken() does a constant-time hash_equals
        // compare AND enforces the 1-hour token expiry, unlike the raw !== this
        // replaced (timing-leaky, never-expiring). Accept the token from the POST
        // field or the X-CSRF-TOKEN header (fetch/XHR path).
        if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $_SESSION['error'] = 'Invalid CSRF token';
            header('Location: ' . root . 'admin/users');
            exit;
        }

        // Get form data
        $user_id = trim($_POST['user_id'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'USD');
        $transaction_type = trim($_POST['transaction_type'] ?? '');
        $payment_gateway_id = intval($_POST['payment_gateway_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $reference = trim($_POST['reference'] ?? '');
        $converted_amount = floatval($_POST['converted_amount'] ?? 0);
        $exchange_rate = floatval($_POST['exchange_rate'] ?? 1);
        $final_amount = floatval($_POST['final_amount'] ?? 0);

        // Validation
        if (empty($user_id)) {
            $_SESSION['error'] = 'User ID is required';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        if ($amount <= 0) {
            $_SESSION['error'] = 'Amount must be greater than 0';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        if (empty($transaction_type) || !in_array($transaction_type, ['credit', 'debit'])) {
            $_SESSION['error'] = 'Invalid transaction type';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        // Get user data and current balance
        $user = $db->get('users', ['balance'], ['user_id' => $user_id]);
        if (!$user) {
            $_SESSION['error'] = 'User not found';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        $current_balance = floatval($user['balance'] ?? 0);

        // Calculate new balance
        if ($transaction_type === 'credit') {
            $new_balance = $current_balance + $final_amount;
        } else {
            $new_balance = max(0, $current_balance - $final_amount);
        }

        // Get payment gateway name
        $gateway_name = 'Manual';
        if ($payment_gateway_id > 0) {
            $gateway = $db->get('payment_gateways', ['name'], ['id' => $payment_gateway_id]);
            if ($gateway) {
                $gateway_name = $gateway['name'];
            }
        }

        // Prepare description
        $admin_name = $db->get('users', ['first_name', 'last_name'], ['user_id' => $_SESSION['user_id'], 'role' => 'admin']);

        $description = !empty($notes) ? $notes :
                      ($transaction_type === 'credit' ? 'Funds added by admin(' . htmlspecialchars($admin_name['first_name'] . ' ' . $admin_name['last_name']) . ')' : 'Funds deducted by admin(' . htmlspecialchars($admin_name['first_name'] . ' ' . $admin_name['last_name']) . ')');

        // Add conversion details to description
        $conversion_note = " [{$amount} {$currency} = {$final_amount} USD @ rate {$exchange_rate}]";
        $description .= $conversion_note;

        if (!empty($reference)) {
            $description .= " (Ref: {$reference})";
        }

        // Handle multiple file uploads
        $uploaded_files = [];
        $upload_errors = [];

        if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
            $upload_dir = __DIR__ . '/../../../uploads/transactions/';

            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'image/jpg'];
            $max_file_size = 2 * 1024 * 1024; // 5MB
            $max_total_size = 10 * 1024 * 1024; // 20MB total

            // Check total files size
            $total_size = 0;
            foreach ($_FILES['attachments']['size'] as $size) {
                $total_size += $size;
            }

            if ($total_size > $max_total_size) {
                $_SESSION['error'] = 'Total files size exceeds 20MB limit';
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }

            // Process each file
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['attachments']['tmp_name'][$key];

                    // SECURITY: validate real MIME + safe extension (finfo).
                    $chk = secureUploadCheck(
                        ['name' => $name, 'tmp_name' => $file_tmp, 'size' => $_FILES['attachments']['size'][$key], 'error' => UPLOAD_ERR_OK],
                        ['jpg', 'jpeg', 'png', 'gif', 'pdf'], $max_file_size
                    );
                    if (!$chk['ok']) {
                        $upload_errors[] = ($chk['error'] ?? 'Invalid file') . ": {$name}";
                        continue;
                    }

                    // Generate unique filename
                    $unique_filename = 'transaction_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '_' . $key . '.' . $chk['ext'];
                    $destination = $upload_dir . $unique_filename;

                    if (move_uploaded_file($file_tmp, $destination)) {
                        @chmod($destination, 0644);
                        $uploaded_files[] = $unique_filename; // Store only filename
                    } else {
                        $upload_errors[] = "Failed to upload: {$name}";
                    }
                } elseif ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                    $upload_errors[] = "Upload error for: {$name}";
                }
            }
        }

        // If there were upload errors but some files uploaded successfully
        if (!empty($upload_errors)) {
            $_SESSION['warning'] = 'Some files failed to upload: ' . implode(', ', $upload_errors);
        }

        // Insert transaction record
        $transaction_data = [
            'user_id' => $user_id,
            'trx_id' => 'TRX' . date('YmdHis') . rand(1000, 9999),
            'type' => $transaction_type,
            'date' => date('Y-m-d H:i:s'),
            'gateway_id' => $gateway_name,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'attachment' => !empty($uploaded_files) ? json_encode($uploaded_files) : null, // Store as JSON array
            'created_by' => $admin_name['first_name'] . ' ' . $admin_name['last_name'] ?? 'admin',
        ];

        // Insert transaction
        $transaction_result = $db->insert('transactions', $transaction_data);
        $transaction_id_row = $transaction_result ? (int) $db->id() : 0;

        if (!$transaction_result) {
            // Delete uploaded files if transaction failed
            foreach ($uploaded_files as $filename) {
                $file_path = $upload_dir . $filename;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            $_SESSION['error'] = 'Failed to create transaction record';
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }

        // Credit/debit the MONEY SPINE (wallets + wallet_ledger), NOT just the
        // legacy users.balance column. Previously this did
        //   $db->update('users', ['balance' => $new_balance], ...)
        // which wrote a number nobody spends: wallet_balance() / wallet_spend()
        // (checkout) read the wallet spine, and an AGENT's balance lives in the
        // `credits` ledger with users.balance staying 0 — so admin-added funds
        // were INVISIBLE and UNSPENDABLE for both customers and agents. wallet_apply()
        // updates the correct per-currency wallet under a row lock and mirrors to
        // credits (agent) / users.balance (customer), so the funds are real.
        require_once __DIR__ . '/../../lib/wallet.php';
        // The manage-funds amounts are normalised to USD (see $final_amount); the
        // wallet operates in that same unit here for parity with the old behaviour.
        $walletCurrency = 'USD';
        if (function_exists('wallet_get_or_create')) {
            wallet_get_or_create($db, $user_id, $walletCurrency);
        }
        // MONEY MOVEMENT VIA THE SPINE (audit H1 — double-credit fix): the old code
        // called wallet_apply() with an 'idempotency_key' opt that wallet_apply()
        // IGNORES — its only dedupe is on 'transaction_id' against
        // wallet_ledger.uq_txn, and no transaction_id was passed. Worse, the key
        // was derived from $transaction_id_row, a FRESH transactions-row id minted
        // on every POST, so it differed on each submit anyway. Net effect: a
        // double-clicked / retried "Add Funds" form credited the wallet TWICE.
        //
        // Fix: route through wallet_refund()/wallet_spend() exactly like the sibling
        // admin credit route (creditsRoutes.php). These create a money_transactions
        // row via txn_create(), which dedupes on money_transactions.idempotency_key
        // (uq_idem) BEFORE any money moves. The key is derived from the operation's
        // content + the form's CSRF token (stable across an accidental re-submit of
        // the same form, but distinct for a genuinely new add-funds action), so a
        // duplicate POST is a no-op that returns the existing transaction.
        $idemBasis = implode('|', [
            (string) $user_id,
            $transaction_type === 'credit' ? 'credit' : 'debit',
            number_format((float) $final_amount, 2, '.', ''),
            $walletCurrency,
            (string) $description,
            (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')),
        ]);
        $adminFundIdem = 'ADMINFUND-' . hash('sha256', $idemBasis);
        if (!function_exists('wallet_refund') || !function_exists('wallet_spend')) {
            $spineResult = ['ok' => false, 'message' => 'Wallet engine unavailable'];
        } elseif ($transaction_type === 'credit') {
            $spineResult = wallet_refund($db, (string) $user_id, (float) $final_amount, $walletCurrency, [
                'reason'          => 'adjustment',
                'ref_type'        => 'admin_manage_funds',
                'ref_id'          => (string) ($transaction_id_row ?: ''),
                'idempotency_key' => $adminFundIdem,
                'note'            => $description,
            ]);
        } else {
            $spineResult = wallet_spend($db, (string) $user_id, (float) $final_amount, $walletCurrency, [
                'reason'          => 'adjustment',
                'method'          => 'manual',
                'allow_credit_line' => false,
                'ref_type'        => 'admin_manage_funds',
                'ref_id'          => (string) ($transaction_id_row ?: ''),
                'idempotency_key' => $adminFundIdem,
                'note'            => $description,
            ]);
        }

        if (empty($spineResult['ok'])) {
            // Roll back the transaction log + uploaded files so we don't leave a
            // record of funds that never actually landed in the wallet.
            if ($transaction_id_row) { $db->delete('transactions', ['id' => $transaction_id_row]); }
            foreach ($uploaded_files as $filename) {
                $file_path = $upload_dir . $filename;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            $_SESSION['error'] = 'Failed to update wallet balance: ' . ($spineResult['message'] ?? 'unknown error');
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }
        // Keep the legacy column in sync for any old read path (wallet_apply already
        // mirrors it for customers; this is a harmless belt-and-braces for the row).
        $new_balance = isset($spineResult['balance']) ? (float) $spineResult['balance'] : $new_balance;

        // Success message
        $success_msg = $transaction_type === 'credit'
            ? "Funds added successfully. "
            : "Funds deducted successfully. ";

        $success_msg .= "{$amount} {$currency} = {$final_amount} USD. ";
        $success_msg .= "New balance: " . number_format($new_balance, 2) . " USD";

        if (!empty($uploaded_files)) {
            $success_msg .= " (" . count($uploaded_files) . " files uploaded)";
        }

        $_SESSION['success'] = $success_msg;

        // Redirect to user edit page with transactions tab
        header('Location: ' . root . admin . '/users/edit/' . $user_id . '#transactions');
        exit;

    } else {
        $_SESSION['error'] = 'Invalid form submission';
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }
});


//==============================================================
// Credit days update route
$router->post(admin.'/users/update-credit-days', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK — this mutates a user's credit terms (credit_payment_days /
    // reminder settings). It had NO auth guard, so an unauthenticated request
    // could change any agent's credit terms (proven: anon set credit_payment_days
    // to 999). The 'Invalid request'/action gate is NOT authentication.
    ADMIN_AUTH();
    CSRF::guard();

    // Handle AJAX request
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_credit_days') {

        // Get form data
        $user_id = trim($_POST['user_id'] ?? '');
        $credit_days = intval($_POST['credit_days'] ?? 0);

        // Validation
        if (empty($user_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }

        if ($credit_days < 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Credit days cannot be negative']);
            exit;
        }

        // Check if user exists
        $user = $db->get('users', ['user_id'], ['user_id' => $user_id]);
        if (!$user) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        // Update credit reminder settings
        $updateData = ['credit_payment_days' => $credit_days];
        if (array_key_exists('credit_usage_reminder_percent', $_POST)) {
            $updateData['credit_usage_reminder_percent'] = max(0, min(100, intval($_POST['credit_usage_reminder_percent'])));
        }

        $db->update('users',
            $updateData,
            ['user_id' => $user_id]
        );

        $dbError = method_exists($db, 'error') ? $db->error : null;
        $failed = is_array($dbError) && !empty($dbError[1]);

        // Success even when rowCount is 0 (same value re-saved)
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        if ($failed) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update credit reminder settings'
                    . (!empty($dbError[2]) ? ': ' . $dbError[2] : ''),
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Credit reminder settings updated successfully',
            'credit_days' => $credit_days,
            'credit_usage_reminder_percent' => $updateData['credit_usage_reminder_percent'] ?? null,
        ]);
        exit;

    } else {
        // CLEAN ALL BUFFERS BEFORE OUTPUT
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
});

//==============================================================
// UPDATE MARKUP CONFIGURATION ROUTE
$router->post(admin.'/users/update-markup', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    // Handle AJAX request
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_markup') {

        // Get form data
        $user_id = trim($_POST['user_id'] ?? '');
        $apply_markup = trim($_POST['apply_markup'] ?? 'global');
        $markup_type = trim($_POST['markup_type'] ?? 'percentage');
        $markup_value = floatval($_POST['markup_value'] ?? 0);

        // Validation
        if (empty($user_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }

        // Validate apply_markup value
        if (!in_array($apply_markup, ['global', 'custom'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid markup application type']);
            exit;
        }

        // Validate markup_type value
        if (!in_array($markup_type, ['percentage', 'fixed'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid markup type']);
            exit;
        }

        // Validate markup_value
        if ($markup_value < 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Markup value cannot be negative']);
            exit;
        }

        // Check if user exists
        $user = $db->get('users', ['user_id'], ['user_id' => $user_id]);
        if (!$user) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        // Prepare update data
        $update_data = [
            'apply_markup' => $apply_markup,
            'markup_type' => $markup_type,
            'markup_value' => $markup_value,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Update markup configuration in database
        $update_result = $db->update('users', $update_data, ['user_id' => $user_id]);

        if ($update_result) {
            // Success response - CLEAN ALL BUFFERS BEFORE OUTPUT
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Markup configuration updated successfully',
                'data' => [
                    'apply_markup' => $apply_markup,
                    'markup_type' => $markup_type,
                    'markup_value' => $markup_value
                ]
            ]);
            exit;
        } else {
            // Error response - CLEAN ALL BUFFERS BEFORE OUTPUT
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update markup configuration in database']);
            exit;
        }

    } else {
        // CLEAN ALL BUFFERS BEFORE OUTPUT
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
});