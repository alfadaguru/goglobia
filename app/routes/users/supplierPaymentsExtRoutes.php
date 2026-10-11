<?php
// FILE: app/routes/users/supplierPaymentsExtRoutes.php
// MODULE 39 (payment processing & deposits → full spec) extension routes.
// Landing: GET /supplier/payments  (money overview; OWNER-ONLY, like /supplier/finance —
// the Phase-1 role catalogue grants no sub-finance permission to staff).
// Per-booking actions reuse supplier_can($db,'reservations','edit',$stayId) so the
// ownership/IDOR + property-scope gate fires on the booking's OWN property.
//
// All money writes go through app/lib/supplier_payments_ext.php, which posts to the GL
// via gl_post() (unbalanced entries refused) and derives amounts from the booking's own
// stored total — never the client. Card preauth + payment-link SENDING are DB-GATED
// (no supplier-initiated charge rail exists); those record an intent and report
// "not connected" honestly.
//
// Reuses: _supplier_stays_owner / _supplier_stays_deny / supplier_owned_stay_ids
// (defined in supplierStaysRoutes.php / supplierReservationsRoutes.php, loaded earlier).

@$SECURE or die('Access Denied!');

if (!function_exists('_spayx_owner_ctx')) {
    /**
     * Owner-only gate for the payments landing + shared POST context. Returns
     * ['owner','org_id','stayIds'] or redirects/denies. Payments is a money overview,
     * so (like finance) it is owner-only; admin is bounced to the admin screens.
     */
    function _spayx_owner_ctx($db): array
    {
        SUPPLIER_OR_STAFF_AUTH($db);
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        $ctx = supplier_acting_context($db);
        if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can manage payments.'); }
        $orgId   = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        $stayIds = supplier_owned_stay_ids($db, $owner);
        return ['owner' => $owner, 'org_id' => (int) $orgId, 'stayIds' => $stayIds];
    }
}

if (!function_exists('_spayx_booking_ctx')) {
    /**
     * Shared POST guard for a per-booking action: auth + CSRF + owner + load/verify the
     * booking against the owner's stays (IDOR) + reservations/edit permission on THAT
     * property. Returns the enriched booking ctx (ready for the lib fns) or denies/exits.
     */
    function _spayx_booking_ctx($db, string $invoiceId): array
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $base = _spayx_owner_ctx($db); // owner-only + org + stay set
        $bk = spayx_load_booking($db, $invoiceId, $base['stayIds']);
        if (empty($bk['ok'])) { _spayx_deny($bk['message'] ?? 'Not your booking.'); }
        if (!supplier_can($db, 'reservations', 'edit', (int) $bk['stay_id'])) { _spayx_deny('Not authorised for this property.'); }
        return [
            'invoice_id' => $invoiceId,
            'stay_id'    => (int) $bk['stay_id'],
            'org_id'     => (int) $base['org_id'],
            'currency'   => (string) $bk['currency'],
            'total'      => (float) $bk['total'],
            'paid'       => (bool) $bk['paid'],
        ];
    }
}

if (!function_exists('_spayx_deny')) {
    function _spayx_deny(string $msg): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'supplier/payments');
        exit;
    }
}
$__spayx_back = function (string $invoiceId = '') {
    return root . 'supplier/payments' . ($invoiceId !== '' ? '?invoice=' . urlencode($invoiceId) : '');
};

// ---------------------------------------------------------------------------
// LANDING — money overview + (optional) a single booking's payment workspace
// ---------------------------------------------------------------------------
$router->get('/supplier/payments', function () use ($SECURE, $db) {
    $base    = _spayx_owner_ctx($db);
    $stayIds = $base['stayIds'];
    spayx_ensure_schema($db);

    $summary     = spayx_scope_summary($db, $stayIds);
    $chargebacks = spayx_chargeback_list($db, $stayIds); // scope-wide (newest first)

    // Property name map for display.
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }

    // Optional single-booking workspace (owner must own it).
    $invoice = trim((string) ($_GET['invoice'] ?? ''));
    $booking = null; $schedule = []; $intents = []; $trail = []; $bkChargebacks = [];
    if ($invoice !== '') {
        $bk = spayx_load_booking($db, $invoice, $stayIds);
        if (!empty($bk['ok'])) {
            $booking = ['invoice_id' => $invoice] + $bk;
            $schedule      = spayx_schedule_list($db, $invoice);
            $intents       = spayx_intent_list($db, $invoice);
            $trail         = spayx_audit_trail($db, $invoice);
            $bkChargebacks = spayx_chargeback_list($db, $stayIds, $invoice);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => $bk['message'] ?? 'Booking not found.'];
            $invoice = '';
        }
    }

    $gatewayConnected = false; // honest: no supplier-initiated charge rail (DB-GATED)
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Payments & deposits'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/payments-ext.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// DEPOSIT SCHEDULE
// ---------------------------------------------------------------------------
$router->post('/supplier/payments/schedule', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    // Parse parallel stage arrays (label[], amount[], due_date[]).
    $labels = (array) ($_POST['label'] ?? []);
    $amts   = (array) ($_POST['amount'] ?? []);
    $dues   = (array) ($_POST['due_date'] ?? []);
    $stages = [];
    foreach ($amts as $i => $a) {
        $stages[] = ['label' => (string) ($labels[$i] ?? ''), 'amount' => $a, 'due_date' => (string) ($dues[$i] ?? '')];
    }
    $res = spayx_schedule_set($db, $ctx, $invoice, $stages);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not save.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

$router->post('/supplier/payments/schedule/collect', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_schedule_collect($db, $ctx, (int) ($_POST['schedule_id'] ?? 0), strtolower((string) ($_POST['method'] ?? 'cash')));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not record.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

$router->post('/supplier/payments/schedule/cancel', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_schedule_cancel($db, $ctx, (int) ($_POST['schedule_id'] ?? 0));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not cancel.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

// ---------------------------------------------------------------------------
// CHARGEBACKS
// ---------------------------------------------------------------------------
$router->post('/supplier/payments/chargeback', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_chargeback_open($db, $ctx, $invoice,
        (float) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['gateway_ref'] ?? ''));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not log.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

$router->post('/supplier/payments/chargeback/resolve', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_chargeback_resolve($db, $ctx, (int) ($_POST['chargeback_id'] ?? 0), strtolower((string) ($_POST['resolution'] ?? '')));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not resolve.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

// ---------------------------------------------------------------------------
// PAYMENT INTENTS (card preauth / payment link — record + HONEST STUB)
// ---------------------------------------------------------------------------
$router->post('/supplier/payments/intent', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_intent_create($db, $ctx, $invoice,
        strtolower((string) ($_POST['kind'] ?? 'link')), (float) ($_POST['amount'] ?? 0), (string) ($_POST['note'] ?? ''));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not create.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});

$router->post('/supplier/payments/intent/cancel', function () use ($SECURE, $db, $__spayx_back) {
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $ctx = _spayx_booking_ctx($db, $invoice);
    $res = spayx_intent_cancel($db, $ctx, (int) ($_POST['intent_id'] ?? 0));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not cancel.'];
    header('Location: ' . $__spayx_back($invoice)); exit;
});
