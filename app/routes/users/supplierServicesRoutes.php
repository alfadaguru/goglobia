<?php
// FILE: app/routes/users/supplierServicesRoutes.php
// Supplier service landing / "read-more" pages (Phase 1 inc S9).
//
// On registration a supplier ticks which services they will supply. Each of
// those services gets its own PUBLIC landing page explaining what it offers and
// who it is for, so a prospective supplier can "read more" before (or while)
// signing up. This file serves:
//   GET /supplier/services            → overview of all first-class services
//   GET /supplier/services/{service}  → one service's detail page
//
// These are marketing pages, like the public /stays and /tours home pages, so
// they carry NO auth guard. They ARE gated on the same settings.supplier_registration
// feature flag as /supplier-signup: when the supplier program is closed the pages
// 404 (there would be nothing to sign up for). Content comes entirely from
// supplier_service_landing() in app/lib/functions.php (single source of truth).
//
// NOTE on route ordering: these paths live under /supplier/* but are registered
// in the always-loaded users block. The authenticated supplier area
// (/supplier/dashboard, /supplier/stays, …) uses distinct path segments, so
// /supplier/services never collides with them.

@$SECURE or die('Access Denied!');

// Guard helper: the feature flag that opens the supplier program. Mirrors the
// gate in supplierSignupRoutes.php. When closed, fall through to a 404.
$__supplierProgramOpen = (($GLOBALS['app']['supplier_registration'] ?? '0') === '1');

// ====================================
// OVERVIEW — all services
// ====================================
$router->get('/supplier/services', function () use ($SECURE, $db, $__supplierProgramOpen) {
    if (!$__supplierProgramOpen) {
        // Render the standard 404 so a closed program is indistinguishable from a
        // non-existent page (no feature discovery).
        http_response_code(404);
        $title = 'Not found';
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        return;
    }

    // Only advertise services that are currently first-class AND active, so the
    // overview never lists a service a supplier cannot actually pick at signup.
    $active   = supplier_first_class_services($db);     // [key => label/icon], active-filtered
    $landing  = supplier_service_landing();             // [key => full content]
    $services = [];
    foreach ($active as $key => $_meta) {
        if (isset($landing[$key])) { $services[$key] = $landing[$key]; }
    }

    $title       = 'Supply your services on ' . ($GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'GoGlobia'));
    $description = 'Learn about the services you can supply on our marketplace and become a supplier.';
    require_once views . "includes/header.php";
    require_once views . "supplier/services/index.php";
    require_once views . "includes/footer.php";
});

// ====================================
// DETAIL — one service
// ====================================
// Constrain the slug to the known service keys so unknown paths fall through to
// the router's 404 rather than reaching the handler.
$router->get('/supplier/services/(stays|flights|tours|cars|bus)', function ($service) use ($SECURE, $db, $__supplierProgramOpen) {
    if (!$__supplierProgramOpen) {
        http_response_code(404);
        $title = 'Not found';
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        return;
    }

    $svc = supplier_service_landing($service);
    // Also require the service to be currently first-class/active; if an operator
    // disabled the module, its learn-more page should not be reachable.
    $active = supplier_first_class_services($db);
    if ($svc === null || !isset($active[strtolower($service)])) {
        http_response_code(404);
        $title = 'Not found';
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        return;
    }

    $serviceKey  = strtolower($service);
    $title       = $svc['label'] . ' — become a supplier';
    $description = $svc['summary'];
    require_once views . "includes/header.php";
    require_once views . "supplier/services/show.php";
    require_once views . "includes/footer.php";
});
