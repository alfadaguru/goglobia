<?php
// FILE: app/routes/users/supplierMarketplaceRoutes.php
// MODULE 11 (GoGlobia marketplace management). Supplier-facing marketplace performance
// scorecard + the platform commission they're on. Owner-level (portfolio-wide). Read-only.

@$SECURE or die('Access Denied!');

$router->get('/supplier/marketplace', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $perf = function_exists('marketplace_performance') ? marketplace_performance($db, $owner) : [];
    $title = 'Marketplace'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/marketplace.php";
    require_once views . "includes/footer.php";
});
