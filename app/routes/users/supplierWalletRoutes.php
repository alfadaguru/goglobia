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
$router->get('/supplier/wallet', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $owner = _supplier_wallet_owner($db);
    if ($owner === null) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can view the wallet.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    // Balances from the earnings ledger (the single source of truth).
    $summary   = function_exists('supplier_earning_summary') ? supplier_earning_summary($db, $owner) : [];
    $available = function_exists('supplier_payable_available') ? supplier_payable_available($db, $owner) : [];

    // Saved bank accounts for the withdraw form (verified ones only are usable).
    if (function_exists('supplier_bank_account_backfill')) { supplier_bank_account_backfill($db, $owner); }
    $accounts  = function_exists('supplier_bank_accounts_for_owner') ? supplier_bank_accounts_for_owner($db, $owner) : [];

    // Admin rules + enable flag (so the UI can show min/fee and gate the form honestly).
    $payoutSettings = function_exists('supplier_payout_settings') ? supplier_payout_settings($db) : ['enabled' => false, 'currencies' => []];
    $payoutsLive    = !empty($payoutSettings['enabled']);

    // Recent payouts (history) + a current-month statement for the primary currency.
    $payouts   = $db->select('supplier_payouts',
        ['id', 'currency', 'amount', 'fee_amount', 'state', 'reference', 'failure_reason', 'requested_at', 'paid_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 25]) ?: [];

    // Pick a primary currency to statement on: the one with the most available, else NGN.
    $primaryCur = 'NGN';
    if (!empty($available)) {
        $byAmount = $available;      // copy — do not disturb the display order of $available
        arsort($byAmount);
        $primaryCur = (string) array_key_first($byAmount);
    }
    $statement = function_exists('supplier_settlement_statement')
        ? supplier_settlement_statement($db, $owner, $primaryCur, date('Y-m-01'), date('Y-m-d'))
        : ['lines' => []];

    $title = 'Wallet'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/wallet.php";
    require_once views . "includes/footer.php";
});
