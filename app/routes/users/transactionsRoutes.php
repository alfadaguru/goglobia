<?php
// FILE: app/routes/users/transactionsRoutes.php
// Customer transaction history — read-only view over the money_transactions
// ledger (wallet credits/debits, top-ups, loyalty conversions, refunds, booking
// spends). Scoped to the session user, paginated, currency-aware. No money is
// moved here; this is a statement screen.

@$SECURE or die('Access Denied!');

$router->get('/transactions', function () use ($SECURE, $db) {
    // Auth gate — same pattern as /dashboard, /bookings.
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Agents/suppliers have their own ledgers; keep this the customer surface but
    // don't hard-block (the sidebar + dashboard already route them away). Load the
    // user row for the sidebar.
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    // ---- Pagination (server-side; never trust a huge client limit) ----
    $perPage = 20;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    // ---- Optional direction filter (credit | debit | all) ----
    $allowedDir = ['all', 'credit', 'debit'];
    $dir = strtolower(trim((string) ($_GET['dir'] ?? 'all')));
    if (!in_array($dir, $allowedDir, true)) { $dir = 'all'; }

    // ---- Build the where-clause. ALWAYS scoped to this user_id (no IDOR). ----
    $where = ['user_id' => (string) $user_id];
    if ($dir !== 'all') { $where['direction'] = $dir; }

    $total = 0;
    $rows  = [];
    try {
        $total = (int) $db->count('money_transactions', $where);
        $rows  = $db->select('money_transactions',
            ['txn_ref', 'direction', 'reason', 'amount', 'currency', 'method',
             'invoice_id', 'status', 'description', 'created_at'],
            array_merge($where, ['ORDER' => ['id' => 'DESC'], 'LIMIT' => [$offset, $perPage]])
        ) ?: [];
    } catch (\Throwable $e) {
        // Ledger table may not exist on a fresh install — fail soft to an empty
        // statement rather than a 500 (don't leak the DB error).
        error_log('TRANSACTIONS_VIEW: ' . $e->getMessage());
        $rows = [];
        $total = 0;
    }

    $totalPages = max(1, (int) ceil($total / $perPage));

    // Current wallet balance (display) for the header card — reuse the same
    // single-currency figure the dashboard/sidebar show.
    $walletBalance  = (float) ($user['balance'] ?? 0);
    $walletCurrency = strtoupper((string) ($user['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));

    // Minimal $dashboardData so the shared sidebar renders (it reads total_bookings
    // + member_since when present; otherwise it self-initialises).
    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => $walletCurrency,
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];

    $txnData = [
        'rows'         => $rows,
        'page'         => $page,
        'total_pages'  => $totalPages,
        'total'        => $total,
        'dir'          => $dir,
        'per_page'     => $perPage,
        'wallet'       => number_format($walletBalance, 2),
        'currency'     => $walletCurrency,
    ];

    $title       = 'Transactions';
    $description = 'Your wallet and payment history';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/transactions.php';
    require_once views . 'includes/footer.php';
});
