<?php
// FILE: app/routes/users/supplierWalletRoutes.php
// Supplier wallet — the seamless money surface over the supplier_earnings ledger.
// Shows pending/available/paid balances, a transaction statement (reuses
// supplier_settlement_statement), and a rules-aware withdrawal form that submits to the
// existing /supplier/payouts/request endpoint (which enforces the admin rules + fee and
// performs the locked reservation). OWNER-only, mirroring the payouts page. No money
// moves in this file — it only reads and renders.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_wallet_owner')) {
    function _supplier_wallet_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        if (empty($ctx['is_owner'])) { return null; }
        return (string) $ctx['owner'];
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/wallet — balances + statement + withdraw
// ----------------------------------------------------------------------------
// The wallet is now merged into the single Payouts hub (/supplier/payouts).
// This route redirects there so old links/bookmarks keep working. The money surface
// lives in ONE place to avoid the confusing duplicate pages.
$router->get('/supplier/wallet', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    header('Location: ' . root . 'supplier/payouts'); exit;
});
