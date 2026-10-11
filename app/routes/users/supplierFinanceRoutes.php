<?php
// FILE: app/routes/users/supplierFinanceRoutes.php
// SUPPLIER FINANCE (PMS completion #34). Surfaces the GL that previously had NO UI:
// trial balance + a derived P&L and balance-sheet summary for the acting owner's org.
// Read-only. Owner-only (money overview); staff access would need a finance permission
// which the role catalogue doesn't grant in Phase 1, so this is gated to the owner.

@$SECURE or die('Access Denied!');

$router->get('/supplier/finance', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    // Owner-only: the financial statements are whole-business money, not property-scoped.
    if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can view finances.'); }
    $owner = (string) $ctx['owner'];
    $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;

    $summary = ($orgId > 0 && function_exists('gl_financial_summary'))
        ? gl_financial_summary($db, $orgId)
        : ['accounts' => [], 'tb_debit' => 0, 'tb_credit' => 0, 'balanced' => true,
           'pl' => ['revenue' => 0, 'expense' => 0, 'net_income' => 0],
           'bs' => ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'equity_plus_income' => 0, 'balanced' => true]];
    // Accounts-payable summary (PMS #36): owed to vendors from received POs.
    $ap = ($orgId > 0 && function_exists('ap_summary')) ? ap_summary($db, $orgId) : ['vendors' => [], 'total' => 0, 'currency' => 'USD'];

    $title = 'Finance'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/finance.php";
    require_once views . "includes/footer.php";
});
