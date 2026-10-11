<?php
// FILE: app/routes/users/supplierChainRoutes.php
// MODULE 03 (multi-property / chain management). Owner-level corporate/portfolio
// dashboard that aggregates ACROSS all the owner's properties: consolidated KPIs,
// brand/region/type breakdown, cross-property availability, centralized guest DB.
// Read-only roll-ups (supplier_chain.php). Owner-only (portfolio spans properties).

@$SECURE or die('Access Denied!');

$router->get('/supplier/chain', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
    if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) {
        _supplier_stays_deny('Only the account owner can view the portfolio.');
    }
    $owner = (string) $ctx['owner'];
    $orgId = function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0;

    $summary    = function_exists('chain_portfolio_summary') ? chain_portfolio_summary($db, $orgId, $owner) : [];
    $properties = function_exists('chain_property_rows') ? chain_property_rows($db, $orgId, $owner) : [];
    $stayIds    = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, $owner) : [];
    $guests     = function_exists('chain_guest_directory') ? chain_guest_directory($db, $stayIds, 100) : [];

    // Optional cross-property availability probe for a chosen date.
    $availDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : '';
    $availability = $availDate !== '' && function_exists('chain_cross_availability')
        ? chain_cross_availability($db, $owner, $availDate) : [];

    $title = 'Portfolio / chain'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/chain.php";
    require_once views . "includes/footer.php";
});
