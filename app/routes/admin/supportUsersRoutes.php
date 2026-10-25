<?php
// app/routes/admin/supportUsersRoutes.php
// Customer Support module — sub-module 5: USERS DIRECTORY. A support-facing
// directory of customers / agents / suppliers with search, plus a per-user
// profile that cross-links their bookings + tickets (so a support agent can see
// the whole relationship in one place). Design-of-record: CUSTOMER_SUPPORT_MODULE_SPEC.md §1.5/§3.
//
// Gated ADMIN_AUTH() + admin_can('users','view'). Supplier-identity hiding is not
// a concern here (this lists the PLATFORM's own users, not back-office suppliers
// behind inventory). Read-only — all mutations stay in the core Users admin.
@$SECURE or die('Access Denie'.'d!');

// ================================ DIRECTORY (tabs: customers / agents / suppliers)
$router->get(admin.'/support/users', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!admin_can($db, 'users', 'view')) { http_response_code(403); die('Access Denied!'); }

    // Tab => the users.role(s) it covers.
    $tabs = [
        'customers' => ['label' => 'Customers', 'roles' => ['customer', 'user']],
        'agents'    => ['label' => 'Agents',    'roles' => ['agent']],
        'suppliers' => ['label' => 'Suppliers', 'roles' => ['supplier']],
    ];
    $tab = (string) ($_GET['tab'] ?? 'customers');
    if (!isset($tabs[$tab])) { $tab = 'customers'; }
    $q = trim((string) ($_GET['q'] ?? ''));

    // Per-tab counts for the tab chips.
    $counts = [];
    foreach ($tabs as $k => $def) {
        try { $counts[$k] = (int) $db->count('users', ['role' => $def['roles']]); }
        catch (\Throwable $e) { $counts[$k] = 0; }
    }

    // Build the filtered list (role tab + optional search over name/email/phone).
    $where = ['role' => $tabs[$tab]['roles']];
    if ($q !== '') {
        $where['OR'] = [
            'first_name[~]' => $q,
            'last_name[~]'  => $q,
            'email[~]'      => $q,
            'phone[~]'      => $q,
            'user_id'       => $q,
        ];
    }
    $where['ORDER'] = ['id' => 'DESC'];
    $where['LIMIT'] = 100;

    $users = [];
    try {
        $users = $db->select('users',
            ['user_id', 'first_name', 'last_name', 'email', 'phone', 'country', 'role', 'status', 'created_at'],
            $where) ?: [];
    } catch (\Throwable $e) {
        error_log('[support/users] list: ' . $e->getMessage());
    }

    $title = 'Users Directory'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/users.php";
    require_once views."includes/footer.php";
});

// ================================ PROFILE (cross-links bookings + tickets)
$router->get(admin.'/support/users/([^/]+)', function ($uid) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!admin_can($db, 'users', 'view')) { http_response_code(403); die('Access Denied!'); }

    $uid = (string) $uid;
    $profile = null;
    try {
        $profile = $db->get('users',
            ['user_id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'country', 'role', 'status', 'created_at'],
            ['user_id' => $uid]);
    } catch (\Throwable $e) {
        error_log('[support/users] profile: ' . $e->getMessage());
    }
    if (!$profile) {
        $_SESSION['flash_error'] = 'User not found.';
        header('Location: ' . root . admin . '/support/users');
        exit;
    }

    // Cross-links: the user's bookings + tickets (read-only summaries).
    $bookings = [];
    $tickets  = [];
    try {
        $bookings = $db->select('bookings',
            ['invoice_id', 'module', 'booking_status', 'payment_status', 'price_markup', 'currency_markup', 'created_at'],
            ['user_id' => $uid, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 25]) ?: [];
    } catch (\Throwable $e) { error_log('[support/users] bookings: ' . $e->getMessage()); }
    try {
        $tickets = $db->select('tickets',
            ['ticket_id', 'subject', 'status', 'priority', 'date'],
            ['user_id' => $uid, 'type' => 'parent', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 25]) ?: [];
    } catch (\Throwable $e) { error_log('[support/users] tickets: ' . $e->getMessage()); }

    $title = 'User Profile'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/user-profile.php";
    require_once views."includes/footer.php";
});
