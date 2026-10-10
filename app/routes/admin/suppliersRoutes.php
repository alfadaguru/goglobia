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

//==============================================================
// SUPPLIER LISTING MODERATION (per-listing approval) — inc 7
//==============================================================

// Queue: stays a supplier has SUBMITTED for approval (plus recently reviewed).
$router->get(admin . '/supplier-listings', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    // Only supplier-OWNED stays (owner is a user with role 'supplier') are in this
    // moderation queue — admin/seeded stays were backfilled to 'approved' and never
    // enter it. Submitted first (actionable), then recently decided for reference.
    $submitted = [];
    $others = [];
    try {
        $supplierIds = array_map(fn($u) => (string) $u['user_id'],
            $db->select('users', ['user_id'], ['role' => 'supplier']) ?: []);
        if (!empty($supplierIds)) {
            $submitted = $db->select('stays',
                ['id', 'name', 'location', 'user_id', 'listing_status', 'review_comment', 'created_at'],
                ['user_id' => $supplierIds, 'listing_status' => 'submitted', 'ORDER' => ['id' => 'DESC']]) ?: [];
            $others = $db->select('stays',
                ['id', 'name', 'location', 'user_id', 'listing_status', 'review_comment', 'reviewed_at'],
                ['user_id' => $supplierIds, 'listing_status' => ['approved', 'queried', 'rejected'],
                 'ORDER' => ['reviewed_at' => 'DESC'], 'LIMIT' => 50]) ?: [];
        }
    } catch (\Throwable $e) {
        error_log('admin supplier-listings: ' . $e->getMessage());
    }

    $title = 'Supplier Listings';
    $description = 'Review properties submitted by suppliers';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/suppliers/listings.php";
    require_once views . "includes/footer.php";
});

// Review action: approve | query | reject (modeled on umrah_group_review).
$router->post(admin . '/supplier-listings/review/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();

    $stay = $db->get('stays', ['id', 'user_id', 'name', 'listing_status'], ['id' => (int) $id]);
    if (!$stay) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Listing not found.'];
        header('Location: ' . root . admin . '/supplier-listings');
        exit;
    }

    $decision = $_POST['decision'] ?? '';
    $map = ['approve' => 'approved', 'query' => 'queried', 'reject' => 'rejected'];
    if (!isset($map[$decision])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid decision.'];
        header('Location: ' . root . admin . '/supplier-listings');
        exit;
    }
    $newStatus = $map[$decision];
    $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 255);

    // Approving makes it sellable: also flip the live switch on (status=1).
    // Query/reject take it offline so it can't sell while unresolved.
    $update = [
        'listing_status' => $newStatus,
        'review_comment' => $comment !== '' ? $comment : null,
        'reviewed_by'    => (string) ($_SESSION['user_id'] ?? ''),
        'reviewed_at'    => date('Y-m-d H:i:s'),
        'status'         => $newStatus === 'approved' ? 1 : 0,
        'updated_at'     => date('Y-m-d H:i:s'),
    ];
    try {
        $db->update('stays', $update, ['id' => (int) $id]);

        // Notify the owning supplier of the decision (best-effort).
        if (function_exists('SENDEMAIL')) {
            $owner = $db->get('users', ['email', 'first_name', 'last_name'], ['user_id' => (string) $stay['user_id']]);
            if ($owner && !empty($owner['email'])) {
                $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
                $verb = ['approved' => 'approved and is now live', 'queried' => 'sent back with a query', 'rejected' => 'not approved'][$newStatus];
                SENDEMAIL(
                    $owner['email'], trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')),
                    'Your property "' . ($stay['name'] ?? '') . '" has been ' . $newStatus,
                    '<p>Your property <strong>' . htmlspecialchars((string) ($stay['name'] ?? '')) . '</strong> has been ' . $verb . '.</p>'
                    . ($comment !== '' ? '<p><strong>Note:</strong> ' . htmlspecialchars($comment) . '</p>' : '')
                    . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                    null
                );
            }
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Listing ' . $newStatus . '.'];
    } catch (\Throwable $e) {
        error_log('admin supplier-listings review: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not record the decision.'];
    }
    header('Location: ' . root . admin . '/supplier-listings');
    exit;
});

//==============================================================
// SUPPLIERS OVERVIEW DASHBOARD (inc S34) — one place to see the supplier programme
//==============================================================
$router->get(admin . '/suppliers-overview', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $stats = [
        'suppliers_total'    => (int) $db->count('users', ['role' => 'supplier']),
        'suppliers_pending'  => (int) $db->count('users', ['role' => 'supplier', 'status' => 'pending']),
        'suppliers_active'   => (int) $db->count('users', ['role' => 'supplier', 'status' => 'active']),
    ];
    // Defensive counts over the supplier tables (may be absent pre-migration).
    foreach ([
        'service_requests_pending' => ['supplier_quota_requests', ['status' => 'pending']],
        'listings_submitted'       => ['stays', ['listing_status' => 'submitted']],
        'payouts_requested'        => ['supplier_payouts', ['state' => 'requested']],
    ] as $k => [$tbl, $w]) {
        try { $stats[$k] = (int) $db->count($tbl, $w); } catch (\Throwable $e) { $stats[$k] = 0; }
    }
    // Recent suppliers.
    $recent = $db->select('users', ['user_id', 'first_name', 'last_name', 'title', 'email', 'status', 'created_at'],
        ['role' => 'supplier', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 10]) ?: [];

    $title = 'Suppliers overview'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "admin/suppliers/overview.php";
    require_once views . "includes/footer.php";
});

//==============================================================
// SUPPLIER SERVICE/QUOTA REQUESTS QUEUE (inc S34)
//==============================================================
$router->get(admin . '/supplier-service-requests', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $pending = function_exists('supplier_requests_pending') ? supplier_requests_pending($db) : [];
    // Owner display names + current grant for context.
    $names = []; $currentMax = [];
    foreach ($pending as $r) {
        $oid = (string) $r['owner_user_id'];
        if (!isset($names[$oid])) {
            $u = $db->get('users', ['first_name', 'last_name', 'title', 'email'], ['user_id' => $oid]);
            $names[$oid] = $u ? (trim(($u['title'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) . ' · ' . ($u['email'] ?? '')) : $oid;
        }
        $g = $db->get('supplier_services', ['max_listings'], ['user_id' => $oid, 'service' => (string) $r['service']]);
        $currentMax[(int) $r['id']] = $g && $g['max_listings'] !== null ? (int) $g['max_listings'] : null;
    }
    $title = 'Supplier service requests'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "admin/suppliers/service-requests.php";
    require_once views . "includes/footer.php";
});

$router->post(admin . '/supplier-service-requests/decide', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
    $back = root . admin . '/supplier-service-requests';
    $res = function_exists('supplier_request_decide')
        ? supplier_request_decide($db, $id, $decision, (string) ($_POST['comment'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    // Best-effort notify the supplier of the decision.
    if (!empty($res['ok']) && function_exists('SENDEMAIL')) {
        try {
            $req = $db->get('supplier_quota_requests', ['owner_user_id', 'service', 'status'], ['id' => $id]);
            if ($req) {
                $u = $db->get('users', ['email', 'first_name'], ['user_id' => (string) $req['owner_user_id']]);
                if ($u && !empty($u['email'])) {
                    $brand = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
                    SENDEMAIL($u['email'], (string) ($u['first_name'] ?? ''),
                        'Your service request has been ' . ($req['status'] ?? 'reviewed'),
                        '<p>Your request for <strong>' . htmlspecialchars((string) $req['service']) . '</strong> has been <strong>' . htmlspecialchars((string) $req['status']) . '</strong>.</p><p>— ' . htmlspecialchars($brand) . '</p>',
                        null);
                }
            }
        } catch (\Throwable $e) { error_log('service-request notify: ' . $e->getMessage()); }
    }
    header('Location: ' . $back); exit;
});
