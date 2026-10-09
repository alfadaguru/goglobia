<?php
// app/routes/admin/suppliersRoutes.php
// Admin: supplier approval queue + approve/reject actions.
//
// A supplier self-registers via /supplier-signup as role=supplier, status=pending.
// This screen lets an admin review the queue and approve (→ active, can log in)
// or reject (→ rejected, with a reason shown back at the login gate). Both
// actions are ADMIN_AUTH + CSRF::guard protected and email the supplier.

@$SECURE or die('Access Denied!');

//==============================================================
// SUPPLIER QUEUE PAGE
//==============================================================
$router->get(admin . '/suppliers', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    // Pending first (the actionable queue), then the rest for reference.
    $pending = $db->select('users', '*', [
        'role'   => 'supplier',
        'status' => 'pending',
        'ORDER'  => ['id' => 'DESC'],
    ]) ?: [];

    $others = $db->select('users', '*', [
        'role'       => 'supplier',
        'status[!]'  => 'pending',
        'ORDER'      => ['id' => 'DESC'],
    ]) ?: [];

    // Declared services per supplier (for the approval cards + quota inputs),
    // keyed by user_id. Defensive so a schema-lag install still renders the page.
    $servicesByUser = [];
    try {
        $allUserIds = array_values(array_filter(array_map(
            fn($r) => (string) ($r['user_id'] ?? ''),
            array_merge($pending, $others)
        )));
        if (!empty($allUserIds)) {
            $svcRows = $db->select('supplier_services',
                ['user_id', 'service', 'requested_count', 'status', 'max_listings'],
                ['user_id' => $allUserIds]) ?: [];
            foreach ($svcRows as $row) {
                $servicesByUser[(string) $row['user_id']][] = $row;
            }
        }
    } catch (\Throwable $e) {
        error_log('admin/suppliers: services fetch failed: ' . $e->getMessage());
    }

    $title = 'Suppliers';
    $description = 'Review and approve supplier applications';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/suppliers/suppliers.php";
    require_once views . "includes/footer.php";
});

//==============================================================
// APPROVE  (POST admin/suppliers/approve/{user_id})
//==============================================================
$router->post(admin . '/suppliers/approve/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard(); // state-changing admin action

    $supplier = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'supplier']);
    if (!$supplier) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Supplier not found.'];
        header('Location: ' . root . admin . '/suppliers');
        exit;
    }

    $db->update('users', [
        'status'                   => 'active',
        'supplier_rejected_reason' => null,
        'updated_at'               => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    // Approve the supplier's requested services and set the CREATION QUOTA.
    // max_listings defaults to the requested_count; an admin may override per
    // service via approved_count[<service>] on the form. This is the hard gate
    // the /supplier/stays create path enforces (increment 3).
    try {
        $approvedCounts = is_array($_POST['approved_count'] ?? null) ? $_POST['approved_count'] : [];
        $services = $db->select('supplier_services', ['id', 'service', 'requested_count'],
            ['user_id' => $user_id]) ?: [];
        foreach ($services as $svc) {
            $svcKey = (string) $svc['service'];
            $quota  = isset($approvedCounts[$svcKey]) && $approvedCounts[$svcKey] !== ''
                ? max(0, (int) $approvedCounts[$svcKey])
                : (int) $svc['requested_count'];
            $db->update('supplier_services', [
                'status'       => 'approved',
                'max_listings' => $quota,
                'reviewed_by'  => (string) ($_SESSION['user_id'] ?? ''),
                'reviewed_at'  => date('Y-m-d H:i:s'),
            ], ['id' => (int) $svc['id']]);
        }
    } catch (\Throwable $e) {
        error_log('Supplier approve: service/quota update failed: ' . $e->getMessage());
    }

    // Notify the supplier they can now sign in.
    if (function_exists('SENDEMAIL') && !empty($supplier['email'])) {
        try {
            $name = trim(($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? ''));
            $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL(
                $supplier['email'],
                $name,
                'Your supplier account has been approved',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Good news — your supplier account on ' . htmlspecialchars($companyName) . ' has been '
                . '<strong>approved</strong>. You can now sign in and access your supplier dashboard.</p>'
                . '<p><a href="' . root . 'login">Sign in</a></p>'
                . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                null
            );
        } catch (\Throwable $e) {
            error_log('Supplier approve email failed: ' . $e->getMessage());
        }
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($db, (int) $supplier['id'], 'supplier_approved', 'Supplier approved by admin');
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Supplier approved.'];
    header('Location: ' . root . admin . '/suppliers');
    exit;
});

//==============================================================
// REJECT  (POST admin/suppliers/reject/{user_id})
//==============================================================
$router->post(admin . '/suppliers/reject/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();

    $supplier = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'supplier']);
    if (!$supplier) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Supplier not found.'];
        header('Location: ' . root . admin . '/suppliers');
        exit;
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    $reason = mb_substr($reason, 0, 255);

    $db->update('users', [
        'status'                   => 'rejected',
        'supplier_rejected_reason' => $reason !== '' ? $reason : null,
        'updated_at'               => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    if (function_exists('SENDEMAIL') && !empty($supplier['email'])) {
        try {
            $name = trim(($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? ''));
            $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL(
                $supplier['email'],
                $name,
                'Update on your supplier application',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Thank you for your interest in becoming a supplier on ' . htmlspecialchars($companyName) . '. '
                . 'After review, your application was <strong>not approved</strong> at this time.</p>'
                . ($reason !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>' : '')
                . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                null
            );
        } catch (\Throwable $e) {
            error_log('Supplier reject email failed: ' . $e->getMessage());
        }
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($db, (int) $supplier['id'], 'supplier_rejected', 'Supplier rejected by admin' . ($reason !== '' ? ': ' . $reason : ''));
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Supplier application rejected.'];
    header('Location: ' . root . admin . '/suppliers');
    exit;
});
