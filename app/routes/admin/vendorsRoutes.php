<?php
// app/routes/admin/vendorsRoutes.php
// Admin: vendor approval queue + approve/reject actions.
//
// A vendor self-registers via /vendor-signup as role=vendor, status=pending.
// This screen lets an admin review the queue and approve (→ active, can log in)
// or reject (→ rejected, with a reason shown back at the login gate). Both
// actions are ADMIN_AUTH + CSRF::guard protected and email the vendor.

@$SECURE or die('Access Denied!');

//==============================================================
// VENDOR QUEUE PAGE
//==============================================================
$router->get(admin . '/vendors', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    // Pending first (the actionable queue), then the rest for reference.
    $pending = $db->select('users', '*', [
        'role'   => 'vendor',
        'status' => 'pending',
        'ORDER'  => ['id' => 'DESC'],
    ]) ?: [];

    $others = $db->select('users', '*', [
        'role'       => 'vendor',
        'status[!]'  => 'pending',
        'ORDER'      => ['id' => 'DESC'],
    ]) ?: [];

    $title = 'Vendors';
    $description = 'Review and approve vendor applications';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/vendors/vendors.php";
    require_once views . "includes/footer.php";
});

//==============================================================
// APPROVE  (POST admin/vendors/approve/{user_id})
//==============================================================
$router->post(admin . '/vendors/approve/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard(); // state-changing admin action

    $vendor = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'vendor']);
    if (!$vendor) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Vendor not found.'];
        header('Location: ' . root . admin . '/vendors');
        exit;
    }

    $db->update('users', [
        'status'                 => 'active',
        'vendor_rejected_reason' => null,
        'updated_at'             => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    // Notify the vendor they can now sign in.
    if (function_exists('SENDEMAIL') && !empty($vendor['email'])) {
        try {
            $name = trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['last_name'] ?? ''));
            $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL(
                $vendor['email'],
                $name,
                'Your vendor account has been approved',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Good news — your vendor account on ' . htmlspecialchars($companyName) . ' has been '
                . '<strong>approved</strong>. You can now sign in and access your vendor dashboard.</p>'
                . '<p><a href="' . root . 'login">Sign in</a></p>'
                . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                null
            );
        } catch (\Throwable $e) {
            error_log('Vendor approve email failed: ' . $e->getMessage());
        }
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($db, (int) $vendor['id'], 'vendor_approved', 'Vendor approved by admin');
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Vendor approved.'];
    header('Location: ' . root . admin . '/vendors');
    exit;
});

//==============================================================
// REJECT  (POST admin/vendors/reject/{user_id})
//==============================================================
$router->post(admin . '/vendors/reject/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();

    $vendor = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'vendor']);
    if (!$vendor) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Vendor not found.'];
        header('Location: ' . root . admin . '/vendors');
        exit;
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    $reason = mb_substr($reason, 0, 255);

    $db->update('users', [
        'status'                 => 'rejected',
        'vendor_rejected_reason' => $reason !== '' ? $reason : null,
        'updated_at'             => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    if (function_exists('SENDEMAIL') && !empty($vendor['email'])) {
        try {
            $name = trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['last_name'] ?? ''));
            $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL(
                $vendor['email'],
                $name,
                'Update on your vendor application',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Thank you for your interest in becoming a vendor on ' . htmlspecialchars($companyName) . '. '
                . 'After review, your application was <strong>not approved</strong> at this time.</p>'
                . ($reason !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>' : '')
                . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                null
            );
        } catch (\Throwable $e) {
            error_log('Vendor reject email failed: ' . $e->getMessage());
        }
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($db, (int) $vendor['id'], 'vendor_rejected', 'Vendor rejected by admin' . ($reason !== '' ? ': ' . $reason : ''));
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Vendor application rejected.'];
    header('Location: ' . root . admin . '/vendors');
    exit;
});
