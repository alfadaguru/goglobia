<?php
// ============================================================================
// app/routes/admin/agentsRoutes.php — Agent ERP P1: admin approval queue.
// Mirrors app/routes/admin/suppliersRoutes.php (approve/reject + notify), but
// agents have no per-service quota — approval just flips status to 'active'.
//   GET  /admin/agents                 → pending queue + all agents
//   POST /admin/agents/approve/{uid}    → status=active + notify + seed roles
//   POST /admin/agents/reject/{uid}     → status=rejected + reason + notify
// MUST be registered inside the admin lazy block in _routes.php.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get(admin . '/agents', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('admin_require')) { admin_require($db, 'users', 'view'); }

    $pending = $db->select('users', ['id','user_id','first_name','last_name','email','phone','status','created_at'],
        ['role' => 'agent', 'status' => 'pending', 'ORDER' => ['id' => 'DESC']]) ?: [];
    $others  = $db->select('users', ['id','user_id','first_name','last_name','email','phone','status','created_at'],
        ['role' => 'agent', 'status[!]' => 'pending', 'ORDER' => ['id' => 'DESC']]) ?: [];

    // Agency profile (name/licence) per agent for the cards.
    $agencyByUser = [];
    try {
        $rows = $db->select('agencies', ['user_id','agency_name','license_number','country'],
            ['user_id' => array_values(array_filter(array_map(fn($u)=> (string)$u['user_id'], array_merge($pending,$others))))]) ?: [];
        foreach ($rows as $r) { $agencyByUser[(string)$r['user_id']] = $r; }
    } catch (\Throwable $e) {}

    $title = 'Travel Agents';
    require_once views."includes/header.php";
    require_once views."admin/agents/index.php";
    require_once views."includes/footer.php";
});

$router->post(admin . '/agents/approve/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('admin_require')) { admin_require($db, 'users', 'edit'); }
    CSRF::guard();

    $agent = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'agent']);
    if (!$agent) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Agent not found.'];
        header('Location: ' . root . admin . '/agents'); exit;
    }

    $db->update('users', [
        'status'                   => 'active',
        'supplier_rejected_reason' => null, // reused generic rejection column
        'updated_at'               => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    // Seed the agency's default roles on approval (idempotent).
    if (function_exists('agent_seed_default_roles')) {
        agent_seed_default_roles($db, (string) $user_id);
    }

    // Notify — template first, inline fallback (mirrors supplier approve).
    $sent = false;
    if (function_exists('sendNotification') && !empty($agent['id'])) {
        try { $r = sendNotification((int) $agent['id'], 'agent_approved', []); $sent = !empty($r['email']); }
        catch (\Throwable $e) { error_log('Agent approve notify: ' . $e->getMessage()); }
    }
    if (!$sent && function_exists('SENDEMAIL') && !empty($agent['email'])) {
        try {
            $name = trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? ''));
            $brand = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL($agent['email'], $name, 'Your travel-agent account has been approved',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Your travel-agent account on ' . htmlspecialchars($brand) . ' has been <strong>approved</strong>. '
                . 'You can now sign in and access your agent portal.</p>'
                . '<p><a href="' . root . 'login">Sign in</a></p><p>— ' . htmlspecialchars($brand) . '</p>', null);
        } catch (\Throwable $e) { error_log('Agent approve email fallback: ' . $e->getMessage()); }
    }
    if (function_exists('logUserActivity')) { logUserActivity($db, (int) $agent['id'], 'agent_approved', 'Agent approved by admin'); }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Agent approved.'];
    header('Location: ' . root . admin . '/agents'); exit;
});

$router->post(admin . '/agents/reject/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('admin_require')) { admin_require($db, 'users', 'edit'); }
    CSRF::guard();

    $agent = $db->get('users', '*', ['user_id' => $user_id, 'role' => 'agent']);
    if (!$agent) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Agent not found.'];
        header('Location: ' . root . admin . '/agents'); exit;
    }
    $reason = substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);

    $db->update('users', [
        'status'                   => 'rejected',
        'supplier_rejected_reason' => $reason !== '' ? $reason : 'Application not approved.',
        'updated_at'               => date('Y-m-d H:i:s'),
    ], ['user_id' => $user_id]);

    $sent = false;
    if (function_exists('sendNotification') && !empty($agent['id'])) {
        try { $r = sendNotification((int) $agent['id'], 'agent_rejected', ['reason' => $reason]); $sent = !empty($r['email']); }
        catch (\Throwable $e) { error_log('Agent reject notify: ' . $e->getMessage()); }
    }
    if (!$sent && function_exists('SENDEMAIL') && !empty($agent['email'])) {
        try {
            $name = trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? ''));
            $brand = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            SENDEMAIL($agent['email'], $name, 'Update on your travel-agent application',
                '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Thank you for your interest. Unfortunately your travel-agent application was not approved'
                . ($reason !== '' ? ': <em>' . htmlspecialchars($reason) . '</em>' : '.') . '</p>'
                . '<p>— ' . htmlspecialchars($brand) . '</p>', null);
        } catch (\Throwable $e) { error_log('Agent reject email fallback: ' . $e->getMessage()); }
    }
    if (function_exists('logUserActivity')) { logUserActivity($db, (int) $agent['id'], 'agent_rejected', 'Agent rejected by admin'); }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Agent rejected.'];
    header('Location: ' . root . admin . '/agents'); exit;
});
