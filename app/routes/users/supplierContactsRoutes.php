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
    // C6 (data reconcile): link EXISTING own-inventory bookings that pre-date the
    // consolidation to a customer, so the book and the reservations share one record.
    // Idempotent (only NULL customer_id rows), capped per call, self-healing on re-open.
    if ($orgId > 0 && function_exists('contacts_backfill_bookings')) {
        try { contacts_backfill_bookings($db, $orgId, (string) $ctx['owner']); }
        catch (\Throwable $e) { error_log('contacts backfill bookings (list): ' . $e->getMessage()); }
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
// GET /supplier/customers/search — JSON typeahead for the booking form (C3).
// Reuses contacts_list (name/email/phone search); org-scoped; view permission.
// Returns a compact list the "find a customer" box renders; it carries the
// customer_id that gets written onto the booking (C2), closing the connection.
// ---------------------------------------------------------------------------
$router->get('/supplier/customers/search', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = _contacts_ctx($db);
    if ($ctx === null || !supplier_can($db, 'reservations', 'view')) {
        echo json_encode(['ok' => false, 'results' => []]); exit;
    }
    $orgId = (int) $ctx['org'];
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? '');
    $rows = ($orgId > 0 && strlen($q) >= 2)
        ? contacts_list($db, $orgId, ['q' => $q, 'type' => $type, 'limit' => 12]) : [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'    => (int) $r['id'],
            'name'  => (string) ($r['display_name'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'type'  => (string) ($r['type'] ?? 'individual'),
        ];
    }
    echo json_encode(['ok' => true, 'results' => $out]); exit;
});

// ---------------------------------------------------------------------------
// POST /supplier/customers/quick-create — inline "+ Create new" popup (C3).
// Creates a contact and returns its id as JSON so the booking form can attach
// it without leaving the screen. Same guard + contacts_save as /save.
// ---------------------------------------------------------------------------
$router->post('/supplier/customers/quick-create', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard(); // reads X-CSRF-TOKEN header (fetch) or csrf_token field; emits JSON 403 on fail
    $ctx = _contacts_ctx($db);
    if ($ctx === null || !supplier_can($db, 'reservations', 'edit')) {
        echo json_encode(['ok' => false, 'message' => 'Not authorised.']); exit;
    }
    $orgId = (int) $ctx['org'];
    $res = contacts_save($db, $orgId, $_POST, 0);
    if (empty($res['ok'])) { echo json_encode(['ok' => false, 'message' => $res['message'] ?? 'Could not create.']); exit; }
    $row = contacts_get($db, $orgId, (int) $res['id']);
    echo json_encode(['ok' => true, 'id' => (int) $res['id'], 'contact' => [
        'id'    => (int) $res['id'],
        'name'  => (string) ($row['display_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'type'  => (string) ($row['type'] ?? 'individual'),
    ]]); exit;
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
    // Org-wide write (merges wallet balances across the WHOLE customer book) — a
    // property-restricted 'selected'-scope staffer must not reach it (audit #10).
    if (!supplier_can_orgwide($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
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
    // IDEMPOTENCY (audit fix): a double-submit must NOT double-apply the wallet. The
    // form carries a one-time nonce; we derive a stable per-submit ref from it so a
    // resubmit of the same form is a no-op in cwallet_apply (idempotent on that ref).
    // The ref is scoped to this contact so the (org_id, ref) unique key can never
    // collide across contacts. If the nonce is missing (old form / no JS), fall back
    // to a content hash so a rapid double-click of identical inputs still dedupes.
    $nonce = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['wallet_nonce'] ?? ''));
    if ($nonce === '') { $nonce = substr(hash('sha256', $dir . '|' . $amount . '|' . $currency . '|' . $note), 0, 24); }
    $ref = 'cwui:' . (int) $id . ':' . $nonce;
    if ($dir === 'topup') {
        $res = cwallet_topup($db, $orgId, (int) $id, $amount, $currency, ['note' => $note, 'ref' => $ref]);
        $msg = !empty($res['ok']) ? (!empty($res['already']) ? 'Deposit already recorded.' : 'Deposit added to the wallet.') : ($res['message'] ?? 'Could not add deposit.');
    } elseif ($dir === 'drawdown') {
        $res = cwallet_drawdown($db, $orgId, (int) $id, $amount, $currency, ['reason' => 'adjustment', 'note' => $note ?: 'Manual draw-down', 'ref' => $ref]);
        $msg = !empty($res['ok']) ? (!empty($res['already']) ? 'Draw-down already recorded.' : 'Wallet drawn down.') : ($res['message'] ?? 'Could not draw down.');
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
