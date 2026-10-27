<?php
// FILE: app/routes/users/supplierContactsRoutes.php
// FRONT-DESK OVERHAUL — Phase 1: THE CUSTOMER BOOK routes.
// Staff-usable (supplier_can('reservations',...)) org-scoped CRM of first-class
// contacts (individual/company/ngo/agent) searchable by phone/name/email/type.
// Mirrors the _guests_ctx guard pattern (owner OR staff -> parent owner -> org),
// NOT the owner-only corporate gate. Writes are CSRF-guarded. Backend = supplier_contacts.php.

@$SECURE or die('Access Denied!');

if (!function_exists('_contacts_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier (owner or staff→parent), or null. */
    function _contacts_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => (int) $org];
    }
}

// ---------------------------------------------------------------------------
// GET /supplier/customers — the Customer Book list (search + type filter)
// ---------------------------------------------------------------------------
$router->get('/supplier/customers', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];

    // One-time idempotent backfill so the book isn't empty on first open. Cheap on
    // re-run (has()-guarded skips), and only when there's a real org.
    if ($orgId > 0 && function_exists('contacts_backfill')) {
        try { contacts_backfill($db, $orgId, (string) $ctx['owner']); }
        catch (\Throwable $e) { error_log('contacts backfill (list): ' . $e->getMessage()); }
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? '');
    $contacts = $orgId > 0 ? contacts_list($db, $orgId, ['q' => $q, 'type' => $type]) : [];
    $counts = $orgId > 0 ? contacts_counts_by_type($db, $orgId) : ['all' => 0];
    $types = contacts_types();
    $canEdit = supplier_can($db, 'reservations', 'edit');
    // P2b: surface possible duplicates (same phone / name) for a one-click merge — only
    // on the unfiltered view, so it reads as a "tidy up your book" prompt, not noise.
    $dupes = ($orgId > 0 && $q === '' && $type === '' && function_exists('contacts_duplicates'))
        ? contacts_duplicates($db, $orgId) : [];

    $title = 'Customers';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/customers/list.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// POST /supplier/customers/merge — fold a duplicate (loser) into a survivor.
// Moves the loser's wallet balance into the survivor, keeps history, deletes loser.
// ---------------------------------------------------------------------------
$router->post('/supplier/customers/merge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];
    $survivor = (int) ($_POST['survivor_id'] ?? 0);
    // The loser set = the OTHER members of this duplicate group (sent as a JSON id list),
    // NOT a client-trusted single loser — so picking any radio as survivor is correct,
    // and a 3+ group folds all the others into the chosen keeper. Validated server-side.
    $groupIds = [];
    $raw = (string) ($_POST['group_ids'] ?? '');
    if ($raw !== '') { $d = json_decode($raw, true); if (is_array($d)) { foreach ($d as $x) { $groupIds[] = (int) $x; } } }
    $losers = array_values(array_filter($groupIds, fn($x) => $x > 0 && $x !== $survivor));
    if ($survivor <= 0 || empty($losers)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick which record to keep.'];
        header('Location: ' . root . 'supplier/customers'); exit;
    }
    $okAll = true; $lastMsg = '';
    foreach ($losers as $loser) {
        $res = function_exists('contacts_merge') ? contacts_merge($db, $orgId, $survivor, $loser) : ['ok' => false, 'message' => 'Merge unavailable.'];
        if (empty($res['ok'])) { $okAll = false; $lastMsg = $res['message'] ?? 'Merge failed.'; break; }
        $lastMsg = $res['message'] ?? 'Merged.';
    }
    $_SESSION['message'] = ['type' => $okAll ? 'success' : 'error', 'text' => $okAll ? ('Merged ' . count($losers) . ' record(s) into the one you kept.') : $lastMsg];
    header('Location: ' . root . 'supplier/customers'); exit;
});

// ---------------------------------------------------------------------------
// GET /supplier/customers/new — create-contact form
// ---------------------------------------------------------------------------
$router->get('/supplier/customers/new', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $types = contacts_types();
    // ?id= present → prefill for EDIT (same form serves create + edit); else blank new.
    $contact = null;
    $editId = (int) ($_GET['id'] ?? 0);
    if ($editId > 0) { $contact = contacts_get($db, (int) $ctx['org'], $editId); }
    $title = $contact ? 'Edit customer' : 'New customer';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/customers/new.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// POST /supplier/customers/save — create or update a contact
// ---------------------------------------------------------------------------
$router->post('/supplier/customers/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];
    $id = (int) ($_POST['id'] ?? 0);

    $res = contacts_save($db, $orgId, $_POST, $id);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    if (!empty($res['ok']) && (int) ($res['id'] ?? 0) > 0) {
        header('Location: ' . root . 'supplier/customers/' . (int) $res['id']); exit;
    }
    header('Location: ' . root . 'supplier/customers' . ($id > 0 ? '/' . $id : '/new')); exit;
});

// ---------------------------------------------------------------------------
// GET /supplier/customers/{id} — contact detail (profile + history + VIP/loyalty + notes)
// ---------------------------------------------------------------------------
$router->get('/supplier/customers/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];
    $contact = contacts_get($db, $orgId, (int) $id);
    if (!$contact) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Customer not found.'];
        header('Location: ' . root . 'supplier/customers'); exit;
    }

    // Reuse the existing guest engine for stay history + loyalty when this contact is
    // linked to a guest email. Scoped to the staffer's permitted stays.
    $stayIds = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, (string) $ctx['owner']) : [];
    $history = [];
    $guestToken = '';
    if (!empty($contact['guest_email_key']) && function_exists('guest_history')) {
        try { $history = guest_history($db, $stayIds, (string) $contact['guest_email_key']); }
        catch (\Throwable $e) { error_log('contact history: ' . $e->getMessage()); }
        if (function_exists('guest_token')) { $guestToken = guest_token((string) $contact['guest_email_key']); }
    }
    $types = contacts_types();
    $canEdit = supplier_can($db, 'reservations', 'edit');

    // Phase 4b: the customer's deposit wallet (balances per currency + recent ledger).
    $walletBalances = function_exists('cwallet_balances') ? cwallet_balances($db, $orgId, (int) $id) : [];
    $walletLedger   = function_exists('cwallet_ledger') ? cwallet_ledger($db, $orgId, (int) $id, 25) : [];

    $title = $contact['display_name'];
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/customers/detail.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// POST /supplier/customers/{id}/wallet — top-up or draw-down the deposit wallet
// (stored value the hotel holds for this customer). Prepaid: draw-down can't overdraw.
// ---------------------------------------------------------------------------
$router->post('/supplier/customers/([0-9]+)/wallet', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];
    $back = root . 'supplier/customers/' . (int) $id;
    if (!contacts_get($db, $orgId, (int) $id)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Customer not found.'];
        header('Location: ' . root . 'supplier/customers'); exit;
    }
    $dir = (string) ($_POST['direction'] ?? '');
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $currency = strtoupper(substr((string) ($_POST['currency'] ?? 'USD'), 0, 3)) ?: 'USD';
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($amount <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a positive amount.'];
        header('Location: ' . $back); exit;
    }
    if ($dir === 'topup') {
        $res = cwallet_topup($db, $orgId, (int) $id, $amount, $currency, ['note' => $note]);
        $msg = !empty($res['ok']) ? 'Deposit added to the wallet.' : ($res['message'] ?? 'Could not add deposit.');
    } elseif ($dir === 'drawdown') {
        $res = cwallet_drawdown($db, $orgId, (int) $id, $amount, $currency, ['reason' => 'adjustment', 'note' => $note ?: 'Manual draw-down']);
        $msg = !empty($res['ok']) ? 'Wallet drawn down.' : ($res['message'] ?? 'Could not draw down.');
    } else {
        $res = ['ok' => false]; $msg = 'Pick top-up or draw-down.';
    }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $msg];
    header('Location: ' . $back); exit;
});

// ---------------------------------------------------------------------------
// POST /supplier/customers/{id}/note — update notes / status / tax_exempt (quick edit)
// ---------------------------------------------------------------------------
$router->post('/supplier/customers/([0-9]+)/note', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $ctx = _contacts_ctx($db);
    if ($ctx === null) { _supplier_stays_deny('Customers is not available for this account.'); }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = (int) $ctx['org'];
    $existing = contacts_get($db, $orgId, (int) $id);
    if (!$existing) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Customer not found.'];
        header('Location: ' . root . 'supplier/customers'); exit;
    }
    // Merge the quick-edit fields onto the existing row (keeps everything else).
    $d = $existing;
    $d['notes'] = (string) ($_POST['notes'] ?? $existing['notes']);
    $d['tax_exempt'] = !empty($_POST['tax_exempt']) ? 1 : 0;
    if (in_array(($_POST['status'] ?? ''), ['active', 'suspended'], true)) { $d['status'] = $_POST['status']; }
    $res = contacts_save($db, $orgId, $d, (int) $id);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . root . 'supplier/customers/' . (int) $id); exit;
});
