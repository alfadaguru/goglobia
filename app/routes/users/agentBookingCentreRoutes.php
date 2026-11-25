<?php
// ============================================================================
// app/routes/users/agentBookingCentreRoutes.php — Agent ERP P3: Booking Centre
//
// Agents book any of the 11 services for their passengers by REUSING each
// service's existing search + booking flow (which already applies B2B markup via
// MARKUP() and captures travellers). The booking lands user_id=agent,
// payment_status='unpaid'. The agent then settles it from the WALLET here — the
// only payment path agents have (payment-gateway.php:126 enforces wallet-only).
//
//   GET  /agent/booking-centre        → hub: pick a service to search & book
//   GET  /agent/bookings              → the agency's bookings (list)
//   GET  /agent/booking/{inv}/pay     → wallet-settle confirm screen
//   POST /agent/booking/{inv}/pay     → debit wallet, attribute, mark paid, issue
//
// Settlement reuses the proven money primitive agent_api_charge_wallet()
// (functions.php:7305 → wallet_spend, idempotent) and the issue loopback
// bm_fire_supplier_issue() (booking_manager.php:88). Self-markup (M10/P5) layers
// a client price on top; until P5 the client price == what the agent pays.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('agent_bc_services')) {
    /** The 11 bookable services + display meta, filtered to those active in modules. */
    function agent_bc_services($db): array
    {
        $all = [
            ['key'=>'flights',  'label'=>'Flights',       'icon'=>'flight',          'url'=>'flights'],
            ['key'=>'stays',    'label'=>'Hotels / Stays','icon'=>'hotel',           'url'=>'stays'],
            ['key'=>'tours',    'label'=>'Tours',         'icon'=>'tour',            'url'=>'tours'],
            ['key'=>'cars',     'label'=>'Car rental',    'icon'=>'directions_car',  'url'=>'cars'],
            ['key'=>'umrah',    'label'=>'Umrah',         'icon'=>'mosque',          'url'=>'umrah'],
            ['key'=>'visa',     'label'=>'Visa',          'icon'=>'badge',           'url'=>'visa'],
            ['key'=>'bus',      'label'=>'Bus',           'icon'=>'directions_bus',  'url'=>'bus'],
            ['key'=>'rail',     'label'=>'Rail',          'icon'=>'train',           'url'=>'rail'],
            ['key'=>'ferries',  'label'=>'Ferries',       'icon'=>'directions_boat', 'url'=>'ferries'],
            ['key'=>'esim',     'label'=>'eSIM',          'icon'=>'sim_card',        'url'=>'esim'],
            ['key'=>'insurance','label'=>'Insurance',     'icon'=>'health_and_safety','url'=>'insurance'],
        ];
        // Active-module filter: a module row with active='1' status='1'. 'stays'
        // maps to module type 'stays' (a.k.a hotels). If the modules table can't
        // be read, show all (fail-open for the hub — booking itself is gated later).
        $active = null;
        try {
            $rows = $db->select('modules', ['type'], ['active' => '1', 'status' => '1']) ?: [];
            $active = [];
            foreach ($rows as $r) { $active[strtolower((string)$r['type'])] = true; }
        } catch (\Throwable $e) { $active = null; }
        if ($active === null) { return $all; }
        return array_values(array_filter($all, fn($s) => isset($active[$s['key']]) || ($s['key']==='stays' && isset($active['hotels']))));
    }
}

// ---- GET /agent/booking-centre ---------------------------------------------
$router->get('/agent/booking-centre', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'bookings', 'add')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have permission to create bookings.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    // Pending agencies cannot transact yet.
    $pending = false;
    if (!empty($ctx['is_owner']) && empty($ctx['owner_active'])) { $pending = true; }

    $services = agent_bc_services($db);
    // Agency name for the "booking as …" banner.
    $agencyName = 'your agency';
    try { $ag = $db->get('agencies', 'agency_name', ['user_id' => (string)$ctx['owner']]); if ($ag) { $agencyName = (string)$ag; } } catch (\Throwable $e) {}

    $title = 'Booking Centre';
    require_once views."agent/layout-top.php";
    require_once views."agent/booking-centre.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/bookings ----------------------------------------------------
$router->get('/agent/bookings', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'bookings', 'view')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to bookings.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $owner = (string) $ctx['owner'];
    $scope = ['OR' => ['agency_id' => $owner, 'user_id' => $owner]];
    $bookings = [];
    try {
        $bookings = $db->select('bookings',
            ['invoice_id','module_type','module','booking_status','payment_status','price_markup',
             'agent_client_price','agent_earning','agent_client_margin','currency_markup','first_name','last_name','created_at'],
            array_merge($scope, ['ORDER'=>['id'=>'DESC'], 'LIMIT'=>200])) ?: [];
    } catch (\Throwable $e) { error_log('agent/bookings: ' . $e->getMessage()); }

    $title = 'Bookings';
    require_once views."agent/layout-top.php";
    require_once views."agent/bookings.php";
    require_once views."agent/layout-bottom.php";
});

// Shared loader: fetch an UNPAID booking that belongs to this agency (or was made
// by the agent before agency_id existed — match user_id too). Returns [booking,ctx]
// or redirects. $need = required capability action.
if (!function_exists('_agent_booking_for_settle')) {
    function _agent_booking_for_settle($db, string $invoiceId): array
    {
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        $owner = (string) $ctx['owner'];
        $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$b) {
            $_SESSION['message'] = ['type'=>'error','text'=>'Booking not found.'];
            header('Location: ' . root . 'agent/bookings'); exit;
        }
        // Ownership: the booking must belong to THIS AGENCY — by agency_id, or by
        // user_id === the agency OWNER (covers legacy bookings made before
        // agency_id existed). Scope strictly to the agency; do NOT also accept
        // "created by the current actor", which let a user who is active staff of
        // two agencies settle/refund one agency's booking while acting as the
        // other → charging/refunding the WRONG agency's wallet (audit HIGH).
        $ownBy = (string) ($b['agency_id'] ?? '');
        $byUser = (string) ($b['user_id'] ?? '');
        if ($ownBy !== $owner && $byUser !== $owner) {
            $_SESSION['message'] = ['type'=>'error','text'=>'That booking does not belong to your agency.'];
            header('Location: ' . root . 'agent/bookings'); exit;
        }
        return [$b, $ctx];
    }
}

// Shared: issue a paid agent booking with RECOVERY — try to issue, RETRY ONCE on
// failure, and if it still fails AUTO-REFUND the agency wallet + cancel the
// booking so the agent is never left paid-with-no-ticket. Used by the pay handler
// (post-charge) and the manual "retry issue" action. Returns:
//   ['issued'=>bool, 'refunded'=>bool, 'message'=>string]
// Safe to call repeatedly: issue.php is idempotent on PNR, and wallet_refund is
// idempotent on idempotency_key (AGT-REFUND-{inv}) so money never double-moves.
if (!function_exists('_agent_issue_with_recovery')) {
    function _agent_issue_with_recovery($db, string $invoiceId, string $service, string $module, string $owner, float $agentCost, string $currency): array
    {
        // Own-inventory / static services have no supplier issue endpoint → the
        // booking is simply confirmed (no supplier call, nothing to recover).
        if (!function_exists('bm_module_has_issue_endpoint') || !function_exists('bm_fire_supplier_issue')
            || $service === '' || $module === '' || !bm_module_has_issue_endpoint($service, $module)) {
            try { $db->update('bookings', ['booking_status'=>'confirmed'], ['invoice_id'=>$invoiceId]); } catch (\Throwable $e) {}
            return ['issued'=>true, 'refunded'=>false, 'message'=>'Booking confirmed.'];
        }

        // Attempt the supplier issue, RETRY ONCE on failure (covers a transient
        // glitch / timeout; issue.php is idempotent so a retry never double-books).
        $issued = false; $lastMsg = '';
        for ($attempt = 1; $attempt <= 2 && !$issued; $attempt++) {
            // Before a RETRY, re-read the booking: a prior attempt may have reached
            // the supplier and booked/ticketed even though our loopback timed out
            // (false negative). If a PNR or a supplier reference now exists, or the
            // booking is already confirmed, DO NOT fire again — that could double-
            // book a supplier that isn't idempotent (audit). (Adivaha is already
            // protected by a stable Idempotency-Key, but this guards every module.)
            if ($attempt > 1) {
                try {
                    $chk = $db->get('bookings', ['pnr','booking_status','booking_data'], ['invoice_id'=>$invoiceId]);
                    $hasPnr = $chk && trim((string)($chk['pnr'] ?? '')) !== '';
                    $isConfirmed = $chk && in_array(strtolower((string)($chk['booking_status'] ?? '')), ['confirmed','issued'], true);
                    $bd = $chk ? json_decode((string)($chk['booking_data'] ?? ''), true) : null;
                    $hasSupplierRef = is_array($bd) && (
                        trim((string)($bd['supplier_ref'] ?? '')) !== '' || trim((string)($bd['order_id'] ?? '')) !== ''
                    );
                    if ($hasPnr || $isConfirmed) {
                        $issued = true; $lastMsg = 'Already issued (confirmed on re-check).';
                        break;
                    }
                    if ($hasSupplierRef) {
                        // Supplier created the booking but we have no PNR yet — treat
                        // as a real supplier booking; do NOT re-fire. Leave pending
                        // for the supplier to confirm / admin to reconcile.
                        $lastMsg = 'Supplier booking exists (awaiting ticketing); not re-fired.';
                        break;
                    }
                } catch (\Throwable $e) { error_log("agent issue recheck $invoiceId: " . $e->getMessage()); }
            }
            try {
                $res = bm_fire_supplier_issue($db, $invoiceId, $service, $module);
                $issued = !empty($res['ok']) || !empty($res['status']);
                $lastMsg = (string) ($res['message'] ?? '');
            } catch (\Throwable $e) {
                $lastMsg = $e->getMessage();
                error_log("agent issue attempt $attempt $invoiceId: " . $e->getMessage());
            }
        }

        if ($issued) {
            return ['issued'=>true, 'refunded'=>false, 'message'=>'Ticket issued.'];
        }

        // Issue failed after a retry → AUTO-REFUND to the agency wallet + cancel,
        // so the agent is made whole instead of stuck paid+pending. Idempotent via
        // AGT-REFUND-{inv} (the same key the manual refund uses, so the two never
        // double-refund the same booking). Only refund a genuine WALLET payment.
        $refunded = false;
        $refundAmt = 0.0;
        try {
            $b = $db->get('bookings', ['payment_status','payment_gateway','price_markup','currency_markup'], ['invoice_id'=>$invoiceId]);
            $paidFromWallet = $b && ($b['payment_gateway'] ?? '') === 'Wallet' && ($b['payment_status'] ?? '') === 'paid';
            if ($paidFromWallet && function_exists('wallet_refund')) {
                // Refund the FULL amount that was charged = booking + the SAME
                // per-service fee agent_api_charge_wallet debited (it charged
                // bookingAmount + agent_api_service_fee). Refunding price_markup
                // alone stranded the fee on every refund (audit HIGH). Recompute
                // the identical fee so the refund equals the actual debit.
                $bookingAmt = round((float) ($b['price_markup'] ?? $agentCost), 2);
                $fee = function_exists('agent_api_service_fee')
                    ? (float) agent_api_service_fee($db, $owner, $service ?: 'booking', $bookingAmt) : 0.0;
                $refundAmt = round($bookingAmt + $fee, 2);
                $cur = (string) ($b['currency_markup'] ?? $currency);
                if ($refundAmt > 0) {
                    $r = wallet_refund($db, $owner, $refundAmt, $cur, [
                        'reason' => 'refund',
                        'invoice_id' => $invoiceId,
                        'ref_type' => 'invoice',
                        'ref_id' => $invoiceId,
                        'idempotency_key' => 'AGT-REFUND-' . $invoiceId,
                        'note' => 'Auto-refund (booking+fee): supplier issue failed for ' . $invoiceId,
                    ]);
                    $refunded = !empty($r['ok']);
                }
            }
        } catch (\Throwable $e) { error_log('agent auto-refund '.$invoiceId.': '.$e->getMessage()); }

        if ($refunded) {
            try { $db->update('bookings', [
                'booking_status' => 'cancelled',
                'payment_status' => 'refunded',
                'refund_amount'  => round($refundAmt, 2),
                'refund_status'  => 'refunded',
                'cancelled_at'   => date('Y-m-d H:i:s'),
            ], ['invoice_id'=>$invoiceId]); } catch (\Throwable $e) {}
        }

        return [
            'issued'   => false,
            'refunded' => $refunded,
            'message'  => $refunded
                ? ('Ticket could not be issued (' . ($lastMsg ?: 'supplier declined') . ') — your payment was refunded to your wallet.')
                : ('Ticket could not be issued (' . ($lastMsg ?: 'supplier declined') . '). It is paid and pending — retry or request a refund.'),
        ];
    }
}

// ---- GET /agent/booking/{inv}/pay  (confirm) -------------------------------
$router->get('/agent/booking/([A-Za-z0-9]+)/pay', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    if (!agent_can($db, 'bookings', 'add')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId);

    // TERMINAL STATE GUARD (AG-M2): don't render a pay screen for a booking that is
    // cancelled/voided/refunded — it must not be chargeable (see the POST handler).
    if (in_array(strtolower((string)($booking['booking_status'] ?? '')), ['cancelled','voided','refunded'], true)) {
        $_SESSION['message'] = ['type'=>'error','text'=>'This booking was '
            . strtolower((string)$booking['booking_status']) . ' and can no longer be paid.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }

    $owner = (string) $ctx['owner'];
    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $balance = function_exists('wallet_balance') ? (float) wallet_balance($db, $owner, $defaultCurrency) : 0.0;
    $creditLimit = (float) ($db->get('users', 'credit_limits', ['user_id'=>$owner]) ?: 0);

    // What the agent PAYS us = price_markup (net + our B2B markup), in base currency.
    $agentCost = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
    $alreadyPaid = (($booking['payment_status'] ?? '') === 'paid');

    $title = 'Pay for booking';
    require_once views."agent/layout-top.php";
    require_once views."agent/booking-pay.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/booking/{inv}/client-invoice  (AG-G5) ----------------------
// Download the agency's branded invoice TO ITS CLIENT, priced at the client price
// (agent cost + the agency's self-markup), NOT the agent's B2B cost. Owner-scoped;
// reuses GENERATE_BOOKING_PDF('client_invoice') which swaps the price + applies the
// agency brand. Agents hand this to the traveller; the agent's own cost stays
// confidential.
$router->get('/agent/booking/([A-Za-z0-9]+)/client-invoice', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    if (!agent_can($db, 'bookings', 'view')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId); // ownership guard (agency-scoped)

    // Only meaningful once the booking is paid (there's a real sale to invoice).
    if (($booking['payment_status'] ?? '') !== 'paid') {
        $_SESSION['message'] = ['type'=>'error','text'=>'The client invoice is available once the booking is paid.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    if (!function_exists('GENERATE_BOOKING_PDF')) { require_once 'app/lib/functions.php'; }
    $pdfPath = GENERATE_BOOKING_PDF((string)$invoiceId, 'client_invoice');
    if (!$pdfPath || !is_file($pdfPath)) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Could not generate the client invoice. Please try again.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    if (function_exists('agent_audit_log')) {
        agent_audit_log($db, (string)$ctx['owner'], (string)$ctx['actor'], 'booking.client_invoice', (string)$invoiceId);
    }
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="client-invoice-' . preg_replace('/[^A-Za-z0-9_\-]/','_', (string)$invoiceId) . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('X-Content-Type-Options: nosniff');
    readfile($pdfPath);
    exit;
});

// ---- GET /agent/booking/{inv}/client-credit-note  (AG-G5b) -----------------
// The refund-side companion to the client invoice: the agency's branded CREDIT
// NOTE to its client for a cancelled/refunded booking, at the client price.
// Owner-scoped; only for a refunded/cancelled booking.
$router->get('/agent/booking/([A-Za-z0-9]+)/client-credit-note', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    if (!agent_can($db, 'bookings', 'view')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId); // ownership guard (agency-scoped)

    // Only for a refunded/cancelled booking (there must be a refund to credit).
    $__ps = strtolower((string)($booking['payment_status'] ?? ''));
    $__bs = strtolower((string)($booking['booking_status'] ?? ''));
    if ($__ps !== 'refunded' && $__bs !== 'cancelled') {
        $_SESSION['message'] = ['type'=>'error','text'=>'A credit note is available once the booking is cancelled or refunded.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    if (!function_exists('GENERATE_BOOKING_PDF')) { require_once 'app/lib/functions.php'; }
    $pdfPath = GENERATE_BOOKING_PDF((string)$invoiceId, 'client_credit_note');
    if (!$pdfPath || !is_file($pdfPath)) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Could not generate the credit note. Please try again.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    if (function_exists('agent_audit_log')) {
        agent_audit_log($db, (string)$ctx['owner'], (string)$ctx['actor'], 'booking.client_credit_note', (string)$invoiceId);
    }
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="credit-note-' . preg_replace('/[^A-Za-z0-9_\-]/','_', (string)$invoiceId) . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('X-Content-Type-Options: nosniff');
    readfile($pdfPath);
    exit;
});

// ---- POST /agent/booking/{inv}/pay  (settle from wallet) -------------------
$router->post('/agent/booking/([A-Za-z0-9]+)/pay', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!agent_can($db, 'bookings', 'add')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId);
    $owner = (string) $ctx['owner'];
    $actor = (string) $ctx['actor'];
    $service = (string) ($booking['module_type'] ?? '');
    $back = root . 'agent/booking/' . urlencode((string)$invoiceId) . '/pay';

    // Idempotent: if already paid, just go to the booking.
    if (($booking['payment_status'] ?? '') === 'paid') {
        $_SESSION['message'] = ['type'=>'success','text'=>'This booking is already paid.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    // TERMINAL STATE GUARD (AG-M2): a booking that is already cancelled/voided/
    // refunded must NOT be chargeable. Without this, the pay handler only checked
    // payment_status, so a cancelled-but-unpaid booking could be paid from the
    // wallet — and for an own-inventory/no-issue-endpoint service it would then be
    // silently resurrected to 'confirmed' + 'paid' by _agent_issue_with_recovery
    // (no supplier call, no recovery/refund). Reject before charging a dead booking.
    if (in_array(strtolower((string)($booking['booking_status'] ?? '')), ['cancelled','voided','refunded'], true)) {
        $_SESSION['message'] = ['type'=>'error','text'=>'This booking was '
            . strtolower((string)$booking['booking_status']) . ' and can no longer be paid. Please create a new booking.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    // Pending agency cannot transact.
    if (!empty($ctx['is_owner']) && empty($ctx['owner_active'])) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Your agency is pending approval.'];
        header('Location: ' . $back); exit;
    }

    $agentCost = round((float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0), 2);
    $currency = (string) ($booking['currency_markup'] ?? (function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN'));

    // AG-G4b: resolve the agency's CRM client by the traveller EMAIL (the booking
    // flows capture email, not a client link) so per-client markup + client
    // reporting work. Scoped to THIS agency's clients. Null when no match.
    $agentClientId = null;
    $__bEmail = trim((string) ($booking['email'] ?? ''));
    if ($__bEmail !== '') {
        try {
            $__c = $db->get('agent_clients', ['id'], ['owner_user_id' => $owner, 'email' => $__bEmail]);
            if ($__c && !empty($__c['id'])) { $agentClientId = (int) $__c['id']; }
        } catch (\Throwable $e) { error_log('agent settle client-resolve: ' . $e->getMessage()); }
    }

    // Attribute the booking to the agency + which staff booked it (+ the resolved
    // CRM client), BEFORE charging, so reporting/ownership are correct even if a
    // later step is retried.
    try {
        $db->update('bookings', [
            'agency_id'          => $owner,
            'booked_by_staff_id' => $actor,
            'booking_channel'    => 'agent',
            'agent_client_id'    => $agentClientId,
        ], ['invoice_id' => $invoiceId]);
    } catch (\Throwable $e) { error_log('agent settle attribute: ' . $e->getMessage()); }

    // Agency self-markup (P5/M10): client price = agent cost + agency markup.
    // Resolution order: per-client > per-service > default, then min-margin floor.
    // Stored for the branded invoice + agent margin reporting. Agent still PAYS
    // $agentCost to us; the client price is what their branded invoice shows.
    if (function_exists('agent_apply_self_markup')) {
        try { agent_apply_self_markup($db, $owner, (string)$invoiceId, $agentCost, $currency, $service, $agentClientId); }
        catch (\Throwable $e) { error_log('agent self-markup: ' . $e->getMessage()); }
    }

    // Charge the wallet (idempotent per invoice+service; draws on credit line).
    if (!function_exists('agent_api_charge_wallet')) { require_once 'app/lib/functions.php'; }
    $charge = agent_api_charge_wallet($db, $owner, $service ?: 'booking', $agentCost, (string)$invoiceId, $currency);
    if (empty($charge['ok'])) {
        $_SESSION['message'] = ['type'=>'error','text'=>
            ($charge['message'] ?? 'Insufficient wallet balance.')
            . (isset($charge['required'],$charge['balance']) ? ' (need '.$currency.' '.number_format((float)$charge['required'],2).', balance '.$currency.' '.number_format((float)$charge['balance'],2).')' : '')];
        header('Location: ' . $back); exit;
    }

    // Mark paid-from-wallet. Keep the supplier issue loopback for LIVE bookings
    // (an own-inventory/static service with no issue endpoint is just confirmed).
    try {
        $db->update('bookings', [
            'payment_status'  => 'paid',
            'payment_gateway' => 'Wallet',
            'paid_at'         => date('Y-m-d H:i:s'),
            'transaction_id'  => 'AGT-' . $invoiceId,
        ], ['invoice_id' => $invoiceId]);
    } catch (\Throwable $e) { error_log('agent settle mark-paid: ' . $e->getMessage()); }

    // Fire the supplier issue with RECOVERY: retry once, then AUTO-REFUND + cancel
    // if it still fails — so the agent is never left paid-with-no-ticket.
    $module = (string) ($booking['module'] ?? '');
    $rec = _agent_issue_with_recovery($db, (string)$invoiceId, $service, $module, $owner, $agentCost, $currency);
    $issued = !empty($rec['issued']);

    // NOTIFY THE CUSTOMER — email + WhatsApp with the (agent-branded) ticket. This
    // was MISSING: an agent booking settled from the wallet left the traveller with
    // no confirmation at all. bm_notify_paid sends to bookings.email + phone and
    // generates the branded voucher (agent brand applied inside GENERATE_BOOKING_PDF).
    // Fire when the booking actually reached the customer: issued (live supplier),
    // OR confirmed with no issue step (own-inventory/manual/static service). Never
    // notify a failed+refunded one (there's no ticket). Best-effort.
    if (function_exists('bm_notify_paid')) {
        $hasIssueEndpoint = function_exists('bm_module_has_issue_endpoint')
            && bm_module_has_issue_endpoint($service, $module);
        if ($issued || !$hasIssueEndpoint) {
            try { bm_notify_paid($db, (string) $invoiceId); }
            catch (\Throwable $e) { error_log('agent settle notify: ' . $e->getMessage()); }
        }
    }

    if (function_exists('agent_audit_log')) {
        agent_audit_log($db, $owner, $actor, 'booking.pay',
            $service . ' ' . $invoiceId . ' ' . $currency . ' ' . $agentCost
            . ($issued ? ' issued' : (!empty($rec['refunded']) ? ' issue-failed+auto-refunded' : ' issue-failed+pending')));
    }

    // GAP-4 (comms): notify the AGENCY OWNER in-app of their OWN booking outcome.
    // Previously only the CUSTOMER was emailed; the agent had no record in their bell.
    // Reuses notify_user (self-healing, idempotent per dedupe_key, best-effort). One
    // notification per outcome state so a retry can't double-post. Owner-scoped so
    // both owner + staff see agency activity in the owner's feed.
    if (function_exists('notify_user')) {
        $__amt = strtoupper($currency) . ' ' . number_format((float)$agentCost, 2);
        if ($issued) {
            notify_user($db, $owner, [
                'type' => 'booking_paid', 'icon' => 'check_circle',
                'title' => 'Booking issued — ' . $invoiceId,
                'body'  => ucfirst($service) . ' paid from wallet (' . $__amt . ') and ticket issued.',
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                'dedupe_key' => 'agent_booking_issued_' . $invoiceId,
            ]);
        } elseif (!empty($rec['refunded'])) {
            notify_user($db, $owner, [
                'type' => 'booking_issue_failed', 'icon' => 'error',
                'title' => 'Booking auto-refunded — ' . $invoiceId,
                'body'  => ucfirst($service) . ' could not be issued; ' . $__amt . ' was refunded to your wallet.',
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                'dedupe_key' => 'agent_booking_autorefund_' . $invoiceId,
            ]);
        } else {
            notify_user($db, $owner, [
                'type' => 'booking_pending', 'icon' => 'hourglass_top',
                'title' => 'Booking paid, awaiting ticket — ' . $invoiceId,
                'body'  => ucfirst($service) . ' paid (' . $__amt . ') but issuing is still pending. Retry issue from Bookings.',
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                'dedupe_key' => 'agent_booking_pending_' . $invoiceId,
            ]);
        }
    }

    // Honest message: issued, or auto-refunded, or paid+pending.
    $_SESSION['message'] = [
        'type' => (!$issued && empty($rec['refunded'])) ? 'error' : ($issued ? 'success' : 'warning'),
        'text' => $issued
            ? ('Booking paid from wallet and issued. Invoice ' . $invoiceId . '.')
            : ($rec['message'] ?? ('Booking paid. Invoice ' . $invoiceId . '.')),
    ];
    header('Location: ' . root . 'agent/bookings');
    exit;
});

// ============================================================================
// P7a — BOOKING MANAGEMENT: resend voucher / cancel / refund-to-wallet.
// Agent-scoped (ownership guard) wrappers over the proven admin lifecycle
// toolkit. A wallet-paid booking refunds back to the AGENCY WALLET (idempotent),
// since that is how the agent paid. JSON endpoint for the bookings list.
//   POST /agent/booking/{inv}/action   body: action=resend|cancel|refund
// ============================================================================
$router->post('/agent/booking/([A-Za-z0-9]+)/action', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    header('Content-Type: application/json');
    $input = $_POST;
    if (!CSRF::validateToken($input['csrf_token'] ?? ($input['_token'] ?? ''))) {
        echo json_encode(['status'=>false,'message'=>'Invalid or expired form token.']); exit;
    }
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { echo json_encode(['status'=>false,'message'=>'Not allowed.']); exit; }
    $owner = (string) $ctx['owner'];
    $actor = (string) $ctx['actor'];
    $action = (string) ($input['action'] ?? '');

    // Ownership: the booking must belong to THIS AGENCY (agency_id, or user_id ===
    // the agency OWNER for legacy bookings). NOT "created by the current actor" —
    // that let a dual-agency staffer act on the wrong agency's booking (audit HIGH).
    $b = $db->get('bookings', '*', ['invoice_id' => (string)$invoiceId]);
    if (!$b) { echo json_encode(['status'=>false,'message'=>'Booking not found.']); exit; }
    $ownBy = (string)($b['agency_id'] ?? ''); $byUser = (string)($b['user_id'] ?? '');
    if ($ownBy !== $owner && $byUser !== $owner) {
        echo json_encode(['status'=>false,'message'=>'That booking does not belong to your agency.']); exit;
    }

    $moduleType = (string) ($b['module_type'] ?? '');
    $module     = (string) ($b['module'] ?? '');

    // ---- RETRY ISSUE (paid + pending → re-fire supplier issue) -------------
    // One-click recovery for a booking that was paid but whose ticket did not
    // issue. Reuses _agent_issue_with_recovery: retries, and if it STILL fails,
    // auto-refunds the wallet + cancels (so a retry can never leave money stuck).
    if ($action === 'retry_issue') {
        if (!agent_can($db, 'bookings', 'add')) { echo json_encode(['status'=>false,'message'=>'No permission.']); exit; }
        if (($b['payment_status'] ?? '') !== 'paid') {
            echo json_encode(['status'=>false,'message'=>'Only a paid booking can be re-issued.']); exit;
        }
        if (in_array(($b['booking_status'] ?? ''), ['confirmed','issued','cancelled'], true)) {
            echo json_encode(['status'=>false,'message'=>'This booking is already ' . ($b['booking_status'] ?? '') . '.']); exit;
        }
        $agentCost = round((float) ($b['price_markup'] ?? $b['price_original'] ?? 0), 2);
        $currency  = (string) ($b['currency_markup'] ?? (function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN'));
        $rec = _agent_issue_with_recovery($db, (string)$invoiceId, $moduleType, $module, $owner, $agentCost, $currency);
        if (function_exists('agent_audit_log')) {
            agent_audit_log($db, $owner, $actor, 'booking.retry_issue',
                (string)$invoiceId . (!empty($rec['issued']) ? ' issued' : (!empty($rec['refunded']) ? ' failed+auto-refunded' : ' failed+pending')));
        }
        echo json_encode([
            'status'   => !empty($rec['issued']) || !empty($rec['refunded']),
            'issued'   => !empty($rec['issued']),
            'refunded' => !empty($rec['refunded']),
            'message'  => $rec['message'] ?? 'Retry attempted.',
        ]);
        exit;
    }

    // ---- RESEND VOUCHER (reuses GENERATE_BOOKING_PDF + NOTIFY::resend) ------
    if ($action === 'resend') {
        if (!agent_can($db, 'bookings', 'view')) { echo json_encode(['status'=>false,'message'=>'No permission.']); exit; }
        // STATE GUARD: only resend a voucher for a PAID + ISSUED/CONFIRMED booking.
        // Resending for an unpaid/pending booking would email the traveller a
        // "ticket" for a booking that was never issued = a false confirmation (audit).
        if (($b['payment_status'] ?? '') !== 'paid'
            || !in_array(strtolower((string)($b['booking_status'] ?? '')), ['confirmed','issued','completed'], true)) {
            echo json_encode(['status'=>false,'message'=>'The voucher can only be resent once the booking is paid and the ticket is issued.']); exit;
        }
        try {
            // Resend the VOUCHER = the TRAVELLER's no-price TICKET, not the invoice.
            $pdfPath = function_exists('GENERATE_BOOKING_PDF') ? GENERATE_BOOKING_PDF((string)$invoiceId, 'ticket') : null;
            if (class_exists('NOTIFY')) {
                NOTIFY::resend($module ?: $moduleType, $b, [
                    'invoice_id'  => (string)$invoiceId,
                    'amount'      => $b['price_markup'] ?? '',
                    'currency'    => $b['currency_markup'] ?? ($b['currency'] ?? ''),
                    'module_type' => ucfirst($moduleType),
                ], $pdfPath);
            }
            if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, $actor, 'booking.resend', (string)$invoiceId); }
            echo json_encode(['status'=>true,'message'=>'Voucher re-sent'.(!empty($b['email'])?' to '.$b['email']:'').'.']);
        } catch (\Throwable $e) {
            error_log('agent resend ' . $invoiceId . ': ' . $e->getMessage());
            echo json_encode(['status'=>false,'message'=>'Could not re-send the voucher.']);
        }
        exit;
    }

    // ---- CANCEL (+ optional refund to wallet) ------------------------------
    if ($action === 'cancel' || $action === 'refund') {
        if (!agent_can($db, 'bookings', ($action === 'refund' ? 'refund' : 'cancel'))) {
            echo json_encode(['status'=>false,'message'=>'No permission.']); exit;
        }
        if (in_array(($b['booking_status'] ?? ''), ['cancelled'], true) && $action === 'cancel') {
            echo json_encode(['status'=>false,'message'=>'Booking is already cancelled.']); exit;
        }

        // 1) Run the supplier cancel where a module handler exists (release the
        //    supplier-side booking). Non-fatal: a static/own-inventory service has
        //    no cancel file — we still mark it cancelled + refund.
        $basePath = dirname(__DIR__, 3);
        $cancelFile = $basePath . '/modules/' . $moduleType . '/' . $module . '/actions/cancel.php';
        $supplierCancelled = null; // null = not attempted
        if ($moduleType !== '' && $module !== '' && is_file($cancelFile)) {
            // AG-M3: give the included module cancel.php ONLY the inputs it expects
            // (invoice_id + module_type), not the agent's whole POST body (csrf_token,
            // action, …). Swap $_POST to a minimal, server-controlled array and
            // RESTORE the original afterwards, so a module file that reads arbitrary
            // $_POST keys cannot inherit attacker-influenced values from this request.
            $__savedPost = $_POST;
            $_POST = ['invoice_id' => (string)$invoiceId, 'module_type' => $moduleType];
            ob_start();
            try { include $cancelFile; ob_end_clean(); $supplierCancelled = true; }
            catch (\Throwable $e) { ob_end_clean(); $supplierCancelled = false; error_log('agent cancel supplier '.$invoiceId.': '.$e->getMessage()); }
            $_POST = $__savedPost; // restore the handler's original request body
        }

        // 2) Mark the booking cancelled (the supplier handler may already have).
        try { $db->update('bookings', ['booking_status'=>'cancelled','cancelled_at'=>date('Y-m-d H:i:s')], ['invoice_id'=>(string)$invoiceId]); }
        catch (\Throwable $e) { error_log('agent cancel mark '.$invoiceId.': '.$e->getMessage()); }

        // 2b) Reverse any own-inventory supplier earning. On payment an own-inventory
        //      stay/tour/car accrues a supplier_earnings row (pending/available); if we
        //      cancel without voiding it, the supplier could still be paid out for a
        //      booking we are cancelling/refunding — money drifts (refund + payout for
        //      the same booking). Idempotent, and it refuses to void a row already
        //      reserved for a payout (see supplier_earning_void_for_booking). A no-op
        //      for aggregator modules (flights/bus) which never accrue an earning.
        if (function_exists('supplier_earning_void_for_booking')) {
            try { supplier_earning_void_for_booking($db, (string)$invoiceId); }
            catch (\Throwable $e) { error_log('agent cancel earning-void '.$invoiceId.': '.$e->getMessage()); }
        }

        // 3) Refund to the AGENCY WALLET when requested AND the booking was paid
        //    FROM THE WALLET. Idempotent per invoice (so a repeat click never
        //    double-refunds). Refund the FULL amount charged = price_markup + the
        //    SAME per-service fee agent_api_charge_wallet debited (recomputed), not
        //    price_markup alone — else the fee is stranded (audit HIGH).
        //    The payment_gateway==='Wallet' assertion is REQUIRED: without it a
        //    booking marked 'paid' by any non-wallet means (admin mark-paid, a
        //    legacy/mixed-gateway settlement) would mint wallet credit that was
        //    never debited. The agent settle path stamps payment_gateway='Wallet'
        //    (this file, line ~208), so a genuine wallet-paid booking matches.
        //    Shares the SAME idempotency key as auto-refund so the two never
        //    double-refund the same invoice.
        $refunded = false;
        $paidFromWallet = strcasecmp((string) ($b['payment_gateway'] ?? ''), 'Wallet') === 0;
        if ($action === 'refund' && ($b['payment_status'] ?? '') === 'paid' && $paidFromWallet) {
            $bookingAmt = round((float)($b['price_markup'] ?? 0), 2);
            $fee = function_exists('agent_api_service_fee')
                ? (float) agent_api_service_fee($db, $owner, $moduleType ?: 'booking', $bookingAmt) : 0.0;
            $amount = round($bookingAmt + $fee, 2);
            $currency = (string)($b['currency_markup'] ?? (function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN'));
            if ($amount > 0 && function_exists('wallet_refund')) {
                $r = wallet_refund($db, $owner, $amount, $currency, [
                    'reason' => 'refund',
                    'invoice_id' => (string)$invoiceId,
                    'ref_type' => 'invoice',
                    'ref_id' => (string)$invoiceId,
                    'idempotency_key' => 'AGT-REFUND-' . $invoiceId,
                    'note' => 'Agent booking refund (booking+fee) ' . $invoiceId,
                ]);
                $refunded = !empty($r['ok']);
                if ($refunded) {
                    try { $db->update('bookings', ['payment_status'=>'refunded','refund_amount'=>$amount,'refund_status'=>'refunded','refund_requested_at'=>date('Y-m-d H:i:s')], ['invoice_id'=>(string)$invoiceId]); }
                    catch (\Throwable $e) { error_log('agent refund mark '.$invoiceId.': '.$e->getMessage()); }
                }
            }
        }

        if (function_exists('agent_audit_log')) {
            agent_audit_log($db, $owner, $actor, 'booking.' . $action, (string)$invoiceId . ($refunded ? ' refunded-to-wallet' : ''));
        }
        // GAP-4 (comms): notify the agency of the cancel/refund outcome.
        if (function_exists('notify_user')) {
            notify_user($db, $owner, [
                'type' => $refunded ? 'booking_refunded' : 'booking_cancelled',
                'icon' => $refunded ? 'currency_exchange' : 'cancel',
                'title' => ($refunded ? 'Booking refunded — ' : 'Booking cancelled — ') . $invoiceId,
                'body'  => $refunded
                    ? 'The booking was cancelled and the amount charged was refunded to your wallet.'
                    : 'The booking was cancelled. No wallet refund was due.',
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                // State-based key: cancel and refund are distinct outcomes, each once.
                'dedupe_key' => ($refunded ? 'agent_booking_refunded_' : 'agent_booking_cancelled_') . $invoiceId,
            ]);
        }
        $msg = 'Booking cancelled.';
        if ($action === 'refund') { $msg = $refunded ? 'Booking cancelled and refunded to your wallet.' : 'Booking cancelled. No wallet refund was due.'; }
        echo json_encode(['status'=>true,'message'=>$msg,'refunded'=>$refunded]);
        exit;
    }

    // ---- CHANGE / RESCHEDULE (fare difference +/- a change fee) -------------
    // Agent-side post-booking amendment. Reuses the SAME money model as the admin
    // Booking Manager change, but refunds route to the AGENCY WALLET (agent bookings
    // are wallet-paid) via bm_apply_agent_booking_change. A fare INCREASE records an
    // amount due the agent collects from the wallet via the normal Pay flow; a fare
    // DECREASE (net of the retained change fee) is credited back to the wallet.
    // Idempotent on the server-derived adjustment ref. Gated on bookings.edit.
    if ($action === 'change') {
        if (!agent_can($db, 'bookings', 'edit')) { echo json_encode(['status'=>false,'message'=>'No permission.']); exit; }
        // A finalised booking can't be amended (mirror admin finality guard).
        if (in_array(strtolower((string)($b['booking_status'] ?? '')), ['cancelled','voided','refunded'], true)) {
            echo json_encode(['status'=>false,'message'=>'A '.strtolower((string)$b['booking_status']).' booking cannot be changed.']); exit;
        }
        if (!function_exists('bm_apply_agent_booking_change')) {
            echo json_encode(['status'=>false,'message'=>'Change engine unavailable.']); exit;
        }
        // Server reads the signed fare difference (new − old) and the change fee.
        $fareDiff  = (float) ($input['fare_difference'] ?? 0);
        $changeFee = max(0.0, (float) ($input['change_fee'] ?? 0));
        if (abs($fareDiff) < 0.001 && $changeFee <= 0.0) {
            echo json_encode(['status'=>false,'message'=>'Enter a fare difference and/or a change fee.']); exit;
        }
        $reason = trim((string) ($input['reason'] ?? 'Agent booking change'));
        $money = bm_apply_agent_booking_change($db, (string)$invoiceId, $owner, $fareDiff, $changeFee,
            $reason !== '' ? $reason : 'Agent booking change', ['actor' => $actor]);

        // Re-derive the client price/margin off the NEW agent cost so the agent's
        // client invoice stays correct after the change (recomputes from scratch —
        // not cumulative). Uses the booking's live price_markup (post-adjustment).
        if (!empty($money['ok']) && function_exists('agent_apply_self_markup')) {
            try {
                $nb = $db->get('bookings', ['price_markup','currency_markup','module_type','agent_client_id'], ['invoice_id'=>(string)$invoiceId]);
                if ($nb) {
                    agent_apply_self_markup($db, $owner, (string)$invoiceId,
                        round((float)($nb['price_markup'] ?? 0), 2),
                        (string)($nb['currency_markup'] ?? 'NGN'),
                        (string)($nb['module_type'] ?? ''),
                        isset($nb['agent_client_id']) ? (int)$nb['agent_client_id'] : null);
                }
            } catch (\Throwable $e) { error_log('agent change self-markup '.$invoiceId.': '.$e->getMessage()); }
        }

        if (function_exists('agent_audit_log')) {
            agent_audit_log($db, $owner, $actor, 'booking.change',
                (string)$invoiceId.' '.($money['direction'] ?? 'none').' '.(string)($money['amount'] ?? 0));
        }
        // GAP-4 (comms): notify on a successful change. A booking can be amended more
        // than once, so the dedupe_key includes the direction+amount of THIS change
        // (a true re-submit of the same adjustment is idempotent; a new amendment posts
        // a fresh notification). Only notify when the money actually applied.
        if (!empty($money['ok']) && function_exists('notify_user')) {
            $__dir = (string)($money['direction'] ?? 'none');
            $__amt = round((float)($money['amount'] ?? 0), 2);
            $__due = round((float)($money['payment_due'] ?? 0), 2);
            $__ref = round((float)($money['refunded'] ?? 0), 2);
            $__body = $__due > 0
                ? ('A fare difference leaves ' . number_format($__due, 2) . ' due — pay it from Bookings.')
                : ($__ref > 0 ? ('A credit of ' . number_format($__ref, 2) . ' was returned to your wallet.')
                              : 'The booking amendment was applied.');
            notify_user($db, $owner, [
                'type' => 'booking_changed', 'icon' => 'edit_calendar',
                'title' => 'Booking changed — ' . $invoiceId,
                'body'  => $__body,
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                'dedupe_key' => 'agent_booking_change_' . $invoiceId . '_' . $__dir . '_' . $__amt,
            ]);
        }
        echo json_encode([
            'status'      => !empty($money['ok']),
            'direction'   => $money['direction'] ?? 'none',
            'amount'      => $money['amount'] ?? 0,
            'payment_due' => $money['payment_due'] ?? 0,
            'refunded'    => $money['refunded'] ?? 0,
            'message'     => $money['message'] ?? 'Change applied.',
        ]);
        exit;
    }

    // ---- MARK NO-SHOW (status only — NO money movement) --------------------
    // Mirrors the admin mark_no_show: stamps booking_status='no_show' + meta so the
    // agent can later start a rebook (Change flow). Idempotent; gated on bookings.edit.
    if ($action === 'mark_no_show') {
        if (!agent_can($db, 'bookings', 'edit')) { echo json_encode(['status'=>false,'message'=>'No permission.']); exit; }
        $cur = strtolower((string)($b['booking_status'] ?? ''));
        if (in_array($cur, ['cancelled','voided','no_show'], true)) {
            echo json_encode(['status'=>false,'message'=>'This booking is already '.$cur.'.']); exit;
        }
        try {
            $bd = json_decode((string)($b['booking_data'] ?? '{}'), true); if (!is_array($bd)) { $bd = []; }
            if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
            $bd['meta']['no_show'] = ['by' => $actor, 'at' => date('Y-m-d H:i:s'), 'via' => 'agent'];
            $db->update('bookings', ['booking_status'=>'no_show', 'booking_data'=>json_encode($bd)], ['invoice_id'=>(string)$invoiceId]);
        } catch (\Throwable $e) {
            error_log('agent no_show '.$invoiceId.': '.$e->getMessage());
            echo json_encode(['status'=>false,'message'=>'Could not mark no-show.']); exit;
        }
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, $actor, 'booking.no_show', (string)$invoiceId); }
        if (function_exists('notify_user')) {
            notify_user($db, $owner, [
                'type' => 'booking_no_show', 'icon' => 'person_off',
                'title' => 'Booking marked no-show — ' . $invoiceId,
                'body'  => 'You can start a rebook from the change action on this booking.',
                'url'   => root . 'agent/bookings', 'ref_type' => 'booking', 'ref_id' => (string)$invoiceId,
                'dedupe_key' => 'agent_booking_noshow_' . $invoiceId,
            ]);
        }
        echo json_encode(['status'=>true,'message'=>'Booking marked no-show. You can start a rebook from the change action.']);
        exit;
    }

    echo json_encode(['status'=>false,'message'=>'Unknown action.']);
    exit;
});
