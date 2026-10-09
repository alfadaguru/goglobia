<?php
// FILE: app/routes/users/supplierDashboardRoutes.php
// Supplier dashboard — the supplier's own area.
//
// First build: login + a read-only overview. The supplier sees their account
// status/profile and the inventory an admin has assigned to them (rows in
// flights/stays/tours/cars whose user_id is this supplier). Self-service upload
// of inventory is a later phase; this area intentionally does not write.

@$SECURE or die('Access Denied!');

$router->get('/supplier/dashboard', function () use ($SECURE, $db) {
    // Gate: must be a logged-in supplier. SUPPLIER_AUTH() redirects to /login
    // otherwise (and a pending/rejected supplier never gets a session anyway —
    // the status gate holds at login).
    SUPPLIER_AUTH();

    $user_id  = $_SESSION['user_id'];
    $supplier = $db->get('users', '*', ['user_id' => $user_id]);
    if (!$supplier) {
        // Session points at a user that no longer exists — bounce to login.
        header('Location: ' . root . 'login');
        exit;
    }

    // Owned inventory across the four service tables that carry an owner
    // user_id. Each lookup is defensive: a table/column a given install lacks
    // must not fatal the dashboard (mirrors the codebase's try/catch style).
    $inventory = [
        'flights' => ['count' => 0, 'items' => [], 'label' => 'Flights', 'icon' => 'flight'],
        'stays'   => ['count' => 0, 'items' => [], 'label' => 'Stays',   'icon' => 'hotel'],
        'tours'   => ['count' => 0, 'items' => [], 'label' => 'Tours',   'icon' => 'tour'],
        'cars'    => ['count' => 0, 'items' => [], 'label' => 'Cars',    'icon' => 'directions_car'],
    ];

    // stays/tours/cars have a `name` column; flights does not (airline/route ids).
    $nameCols = [
        'flights' => ['id', 'status'],
        'stays'   => ['id', 'name', 'status'],
        'tours'   => ['id', 'name', 'status'],
        'cars'    => ['id', 'name', 'status'],
    ];

    foreach ($inventory as $table => &$info) {
        try {
            $info['count'] = (int) $db->count($table, ['user_id' => $user_id]);
            if ($info['count'] > 0) {
                $info['items'] = $db->select($table, $nameCols[$table], [
                    'user_id' => $user_id,
                    'ORDER'   => ['id' => 'DESC'],
                    'LIMIT'   => 5,
                ]) ?: [];
            }
        } catch (\Throwable $e) {
            // Table missing on this install, or column mismatch — leave at zero.
            error_log('supplier dashboard inventory (' . $table . '): ' . $e->getMessage());
        }
    }
    unset($info);

    $totalInventory = array_sum(array_map(fn($i) => $i['count'], $inventory));

    $title = 'Supplier Dashboard';
    $description = 'Your supplier account overview';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once views . "supplier/dashboard.php";
    require_once views . "includes/footer.php";
});
