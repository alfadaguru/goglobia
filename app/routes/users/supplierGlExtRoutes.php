<?php
// FILE: app/routes/users/supplierGlExtRoutes.php
// MODULE 34 (general ledger & financial accounting) EXTENSION routes — docs 01b §2.
// Surfaces the gaps on top of the base GL (supplier_ledger.php) + the read-only
// Finance screen (supplierFinanceRoutes.php): manual journal entries, journal browse,
// accounting-period close, cost centres, cash-flow statement, budget-vs-actual, and a
// GL audit-trail report. OWNER-ONLY — these are whole-business books, not property
// scoped, exactly like /supplier/finance (the Phase-1 role catalogue has no finance
// permission, so staff are excluded here). Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF +
// the owner gate. Money writes delegate to gl_post() via glx_post_manual/glx_reverse —
// this layer NEVER posts GL directly and NEVER trusts a client-supplied total.

@$SECURE or die('Access Denied!');

if (!function_exists('_glx_ctx')) {
    /**
     * Shared GL-ext gate. Returns the acting owner's org id (>0) or redirects/denies.
     * Mirrors supplierFinanceRoutes.php exactly: owner-only, whole-business money.
     */
    function _glx_ctx($db): int
    {
        SUPPLIER_OR_STAFF_AUTH($db);
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can manage the ledger.'); }
        $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
        if ($orgId <= 0) { _supplier_stays_deny('No organisation books found.'); }
        if (function_exists('glx_ensure_schema')) { glx_ensure_schema($db); }
        return $orgId;
    }
}

// --- GET: the GL-ext workspace (tabbed: journal / periods / cost centres / cash flow / budget / audit) ---
$router->get('/supplier/finance/ledger', function () use ($SECURE, $db) {
    $orgId = _glx_ctx($db);
    $tab = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['tab'] ?? 'journal')));
    if (!in_array($tab, ['journal', 'entry', 'periods', 'centres', 'cashflow', 'budget', 'audit'], true)) { $tab = 'journal'; }

    $accounts = function_exists('glx_accounts') ? glx_accounts($db, $orgId) : [];
    $costCenters = function_exists('glx_cost_centers') ? glx_cost_centers($db, $orgId) : [];
    $periods = function_exists('glx_periods') ? glx_periods($db, $orgId) : [];

    $journal = ['rows' => [], 'count' => 0]; $entry = null; $cashflow = null; $budget = null; $audit = ['rows' => []];
    $filters = [
        'from'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '',
        'to'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '',
        'source' => substr((string) ($_GET['source'] ?? ''), 0, 40),
    ];

    if ($tab === 'journal') {
        $journal = function_exists('glx_journal_list') ? glx_journal_list($db, $orgId, $filters) : $journal;
    } elseif ($tab === 'entry') {
        $eid = (int) ($_GET['id'] ?? 0);
        $entry = function_exists('glx_journal_entry') ? glx_journal_entry($db, $orgId, $eid) : null;
        if ($entry === null) { $tab = 'journal'; $journal = glx_journal_list($db, $orgId, $filters); }
    } elseif ($tab === 'cashflow') {
        $cfFrom = $filters['from'] !== '' ? $filters['from'] : date('Y-m-01');
        $cfTo   = $filters['to'] !== '' ? $filters['to'] : date('Y-m-d');
        $filters['from'] = $cfFrom; $filters['to'] = $cfTo;
        $cashflow = function_exists('glx_cash_flow') ? glx_cash_flow($db, $orgId, $cfFrom, $cfTo) : null;
    } elseif ($tab === 'budget') {
        $pid = (int) ($_GET['period_id'] ?? 0);
        if ($pid <= 0 && !empty($periods)) { $pid = (int) $periods[0]['id']; }
        $budget = ($pid > 0 && function_exists('glx_budget_vs_actual')) ? glx_budget_vs_actual($db, $orgId, $pid) : null;
        $GLOBALS['__glx_budget_pid'] = $pid;
    } elseif ($tab === 'audit') {
        $audit = function_exists('glx_audit_report')
            ? glx_audit_report($db, $orgId, ['event' => substr((string) ($_GET['event'] ?? ''), 0, 60), 'from' => $filters['from'], 'to' => $filters['to']])
            : $audit;
    }

    $title = 'General ledger'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/gl-ext.php";
    require_once views . "includes/footer.php";
});

$__glxBack = function (string $tab, string $extra = '') { return root . 'supplier/finance/ledger?tab=' . $tab . $extra; };

// --- POST: manual journal entry (delegates to gl_post via glx_post_manual) ---
$router->post('/supplier/finance/ledger/journal', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    // Build lines from parallel arrays account_id[]/debit[]/credit[]/line_memo[].
    $accts  = (array) ($_POST['account_id'] ?? []);
    $debits = (array) ($_POST['debit'] ?? []);
    $credits= (array) ($_POST['credit'] ?? []);
    $memos  = (array) ($_POST['line_memo'] ?? []);
    $lines = [];
    foreach ($accts as $i => $a) {
        $lines[] = [
            'account_id' => (int) $a,
            'debit'      => (float) ($debits[$i] ?? 0),
            'credit'     => (float) ($credits[$i] ?? 0),
            'memo'       => (string) ($memos[$i] ?? ''),
        ];
    }
    $opts = [
        'date'           => (string) ($_POST['entry_date'] ?? date('Y-m-d')),
        'memo'           => (string) ($_POST['memo'] ?? ''),
        'currency'       => (string) ($_POST['currency'] ?? ''),
        'cost_center_id' => (int) ($_POST['cost_center_id'] ?? 0),
    ];
    $res = function_exists('glx_post_manual') ? glx_post_manual($db, $orgId, $lines, $opts) : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) {
        audit_log($db, 'gl.journal.posted', 'journal_entries', (int) ($res['entry_id'] ?? 0),
            ['amount' => $res['amount'] ?? null, 'manual' => true], $orgId);
    }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => !empty($res['ok']) ? ('Journal entry posted (#' . ($res['entry_id'] ?? '?') . ').') : ('Could not post: ' . ($res['message'] ?? 'error'))];
    header('Location: ' . $__glxBack('journal')); exit;
});

// --- POST: reverse a posted entry (mirror via gl_post) ---
$router->post('/supplier/finance/ledger/journal/reverse', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $eid = (int) ($_POST['entry_id'] ?? 0);
    $res = function_exists('glx_reverse_entry') ? glx_reverse_entry($db, $orgId, $eid, (string) ($_POST['memo'] ?? '')) : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) {
        audit_log($db, 'gl.journal.reversed', 'journal_entries', $eid, ['reversal_id' => $res['entry_id'] ?? null], $orgId);
    }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => !empty($res['ok']) ? 'Entry reversed.' : ('Could not reverse: ' . ($res['message'] ?? 'error'))];
    header('Location: ' . $__glxBack('journal')); exit;
});

// --- POST: accounting periods ---
$router->post('/supplier/finance/ledger/period', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $res = function_exists('glx_period_create')
        ? glx_period_create($db, $orgId, (string) ($_POST['name'] ?? ''), (string) ($_POST['start_date'] ?? ''), (string) ($_POST['end_date'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) { audit_log($db, 'gl.period.created', 'fiscal_periods', (int) ($res['id'] ?? 0), [], $orgId); }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Period created.' : ('Could not create: ' . ($res['message'] ?? 'error'))];
    header('Location: ' . $__glxBack('periods')); exit;
});

$router->post('/supplier/finance/ledger/period/close', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $pid = (int) ($_POST['period_id'] ?? 0);
    $res = function_exists('glx_period_close') ? glx_period_close($db, $orgId, $pid) : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) { audit_log($db, 'gl.period.closed', 'fiscal_periods', $pid, [], $orgId); }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => ($res['message'] ?? 'Done')];
    header('Location: ' . $__glxBack('periods')); exit;
});

$router->post('/supplier/finance/ledger/period/reopen', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $pid = (int) ($_POST['period_id'] ?? 0);
    $res = function_exists('glx_period_reopen') ? glx_period_reopen($db, $orgId, $pid) : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) { audit_log($db, 'gl.period.reopened', 'fiscal_periods', $pid, [], $orgId); }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => ($res['message'] ?? 'Done')];
    header('Location: ' . $__glxBack('periods')); exit;
});

// --- POST: cost centres ---
$router->post('/supplier/finance/ledger/centre', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $res = function_exists('glx_cost_center_add')
        ? glx_cost_center_add($db, $orgId, (string) ($_POST['code'] ?? ''), (string) ($_POST['name'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) { audit_log($db, 'gl.cost_center.created', 'gl_cost_centers', (int) ($res['id'] ?? 0), [], $orgId); }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Cost centre added.' : ('Could not add: ' . ($res['message'] ?? 'error'))];
    header('Location: ' . $__glxBack('centres')); exit;
});

$router->post('/supplier/finance/ledger/centre/toggle', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $ok = function_exists('glx_cost_center_set_active')
        && glx_cost_center_set_active($db, $orgId, (int) ($_POST['id'] ?? 0), ((string) ($_POST['active'] ?? '1')) === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Cost centre updated.' : 'Could not update.'];
    header('Location: ' . $__glxBack('centres')); exit;
});

// --- POST: budget line upsert ---
$router->post('/supplier/finance/ledger/budget', function () use ($SECURE, $db, $__glxBack) {
    $orgId = _glx_ctx($db); CSRF::guard();
    $pid = (int) ($_POST['period_id'] ?? 0);
    $cc = (int) ($_POST['cost_center_id'] ?? 0);
    $res = function_exists('glx_budget_set')
        ? glx_budget_set($db, $orgId, $pid, (int) ($_POST['account_id'] ?? 0), (float) ($_POST['amount'] ?? 0), $cc > 0 ? $cc : null)
        : ['ok' => false, 'message' => 'Unavailable'];
    if (!empty($res['ok']) && function_exists('audit_log')) { audit_log($db, 'gl.budget.set', 'gl_budget_lines', null, ['period_id' => $pid], $orgId); }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => ($res['message'] ?? 'Done')];
    header('Location: ' . $__glxBack('budget', $pid > 0 ? '&period_id=' . $pid : '')); exit;
});
