<?php
// app/routes/admin/supportRoutes.php
// Customer Support module — Phase 1 shell: the landing/home route that makes the
// module reachable and links out to every sub-area. Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§3 routes, §9 dashboard).
//
// Every support route pairs ADMIN_AUTH() with support_can($db, '<cap>'); the
// RBAC gate + schema live in app/lib/support/rbac.php (loaded in config.php).
@$SECURE or die('Access Denied!');

// ================================ SUPPORT HOME
// A simple home page with cards/links into the sub-areas. Some sub-areas exist
// today (tickets, bookings, roles, staff); the rest land in later phases and are
// marked "coming" in the view.
$router->get(admin.'/support', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    // Self-heal the RBAC foundation + seed the 4 default roles so an admin can
    // reach the module on a fresh install before any staff is provisioned.
    if (function_exists('ensureSupportRbacSchema')) { ensureSupportRbacSchema($db); }
    if (function_exists('support_seed_default_roles')) { support_seed_default_roles($db); }

    // Capability gate: anyone who may see the support dashboard. Admins resolve to
    // the wildcard '*' implicitly (see support_current_staff()).
    if (!support_can($db, 'dashboard.view')) {
        header('Location: ' . root . admin . '/dashboard');
        exit;
    }

    $title = 'Customer Support'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/home.php";
    require_once views."includes/footer.php";
});
