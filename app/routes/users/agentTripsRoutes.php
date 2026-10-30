<?php
// ============================================================================
// app/routes/users/agentTripsRoutes.php — Agent ERP P6b: trip plans + proposals.
// A named, saved, multi-service itinerary an agent builds for a client, then
// shares as a BRANDED proposal (public link). No such saved artifact exists
// elsewhere (cart/GIA are transient). Owner-scoped; agent_can('trips', …).
//
//   GET  /agent/trips              → list
//   GET  /agent/trips/new          → create (name + client)
//   GET  /agent/trips/{ref}        → builder (add/remove service lines)
//   POST /agent/trips/save         → create/update plan meta
//   POST /agent/trips/{ref}/lines  → replace the line items (JSON from the form)
//   POST /agent/trips/{ref}/send   → mark sent + mint client token
//   POST /agent/trips/delete       → delete a plan
//   GET  /trip/{ref}               → PUBLIC branded proposal (token-gated)
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_agent_trips_ctx')) {
    function _agent_trips_ctx($db): array
    {
        AGENT_OR_STAFF_AUTH($db);
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        return [$ctx, (string) $ctx['owner']];
    }
}
if (!function_exists('_agent_trips_deny')) {
    function _agent_trips_deny(string $msg = 'You do not have access to trip plans.'): void
    {
        $_SESSION['message'] = ['type'=>'error','text'=>$msg];
        header('Location: ' . root . 'agent/trips'); exit;
    }
}
if (!function_exists('_agent_trip_totals')) {
    /** Compute a plan total from its line items. */
    function _agent_trip_totals(array $items): array
    {
        $total = 0.0;
        foreach ($items as $it) {
            $qty = max(1, (int)($it['qty'] ?? 1));
            $total += round((float)($it['price'] ?? 0) * $qty, 2);
        }
        return ['total' => round($total, 2)];
    }
}

// ---- GET /agent/trips -------------------------------------------------------
$router->get('/agent/trips', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    if (!agent_can($db, 'trips', 'view')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to trip plans.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $trips = [];
    try {
        $trips = $db->select('agent_trip_plans',
            ['id','ref','title','client_name','status','currency','totals_data','created_at'],
            ['owner_user_id' => $owner, 'ORDER' => ['id'=>'DESC'], 'LIMIT' => 200]) ?: [];
    } catch (\Throwable $e) { error_log('agent/trips list: ' . $e->getMessage()); }
    $canEdit = agent_can($db, 'trips', 'add') || agent_can($db, 'trips', 'edit');

    $title = 'Trip Plans';
    require_once views."agent/layout-top.php";
    require_once views."agent/trips.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/trips/new ---------------------------------------------------
$router->get('/agent/trips/new', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    if (!agent_can($db, 'trips', 'add')) { _agent_trips_deny('You cannot create trip plans.'); }
    $clients = [];
    try { $clients = $db->select('agent_clients', ['id','first_name','last_name','email'], ['owner_user_id'=>$owner,'ORDER'=>['id'=>'DESC'],'LIMIT'=>200]) ?: []; } catch (\Throwable $e) {}
    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $title = 'New trip plan';
    require_once views."agent/layout-top.php";
    require_once views."agent/trip-new.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/trips/save  (create plan meta) ----------------------------
$router->post('/agent/trips/save', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'trips', 'add')) { _agent_trips_deny('You cannot create trip plans.'); }
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') { _agent_trips_deny('Give the trip a title.'); }

    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $clientEmail = trim((string)($_POST['client_email'] ?? ''));
    if ($clientId) {
        try { $c = $db->get('agent_clients', ['first_name','last_name','email'], ['id'=>$clientId,'owner_user_id'=>$owner]);
              if ($c) { $clientName = trim(($c['first_name']??'').' '.($c['last_name']??'')); $clientEmail = (string)($c['email']??$clientEmail); } }
        catch (\Throwable $e) {}
    }
    // Unique ref (retry on the rare collision).
    $ref = 'TRP' . strtoupper(bin2hex(random_bytes(5)));
    $retry = 0;
    while ($retry < 5 && $db->has('agent_trip_plans', ['ref'=>$ref])) { $ref = 'TRP' . strtoupper(bin2hex(random_bytes(5))); $retry++; }

    try {
        $db->insert('agent_trip_plans', [
            'ref' => $ref, 'owner_user_id' => $owner, 'created_by' => (string)$ctx['actor'],
            'client_id' => $clientId, 'client_name' => substr($clientName,0,191), 'client_email' => substr($clientEmail,0,255),
            'title' => substr($title,0,191), 'status' => 'draft',
            'items_data' => json_encode([]), 'totals_data' => json_encode(['total'=>0]),
            'currency' => function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN',
            'notes' => trim((string)($_POST['notes'] ?? '')), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { error_log('agent trips save: ' . $e->getMessage()); _agent_trips_deny('Could not create the trip plan.'); }
    header('Location: ' . root . 'agent/trips/' . $ref);
    exit;
});

// ---- GET /agent/trips/{ref}  (builder) -------------------------------------
$router->get('/agent/trips/(TRP[A-Z0-9]+)', function ($ref) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    if (!agent_can($db, 'trips', 'view')) { _agent_trips_deny(); }
    $trip = $db->get('agent_trip_plans', '*', ['ref' => $ref, 'owner_user_id' => $owner]);
    if (!$trip) { _agent_trips_deny('Trip plan not found.'); }
    $items = json_decode((string)($trip['items_data'] ?? '[]'), true) ?: [];
    $totals = json_decode((string)($trip['totals_data'] ?? '{}'), true) ?: ['total'=>0];
    $canEdit = agent_can($db, 'trips', 'edit') || agent_can($db, 'trips', 'add');
    $canSend = agent_can($db, 'trips', 'send');
    $shareUrl = !empty($trip['client_token']) ? (root . 'trip/' . $ref . '?token=' . urlencode((string)$trip['client_token'])) : '';
    $services = ['flights','stays','tours','cars','umrah','visa','bus','rail','ferries','esim','insurance'];

    $title = 'Trip: ' . (string)$trip['title'];
    require_once views."agent/layout-top.php";
    require_once views."agent/trip-builder.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/trips/{ref}/lines  (replace items) ------------------------
$router->post('/agent/trips/(TRP[A-Z0-9]+)/lines', function ($ref) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'trips', 'edit') && !agent_can($db, 'trips', 'add')) { _agent_trips_deny(); }
    $trip = $db->get('agent_trip_plans', ['id'], ['ref' => $ref, 'owner_user_id' => $owner]);
    if (!$trip) { _agent_trips_deny('Trip plan not found.'); }

    // Rebuild the item list from parallel POST arrays.
    $svc = (array)($_POST['line_service'] ?? []);
    $ttl = (array)($_POST['line_title'] ?? []);
    $qty = (array)($_POST['line_qty'] ?? []);
    $prc = (array)($_POST['line_price'] ?? []);
    $items = [];
    foreach ($ttl as $i => $t) {
        $t = trim((string)$t);
        if ($t === '') { continue; }
        $items[] = [
            'service' => substr(trim((string)($svc[$i] ?? '')), 0, 20),
            'title'   => substr($t, 0, 191),
            'qty'     => max(1, (int)($qty[$i] ?? 1)),
            'price'   => max(0, (float)($prc[$i] ?? 0)),
        ];
    }
    $totals = _agent_trip_totals($items);
    try {
        $db->update('agent_trip_plans',
            ['items_data'=>json_encode($items), 'totals_data'=>json_encode($totals),
             'status'=>'quoted', 'updated_at'=>date('Y-m-d H:i:s')],
            ['ref'=>$ref, 'owner_user_id'=>$owner]);
        $_SESSION['message'] = ['type'=>'success','text'=>'Trip saved.'];
    } catch (\Throwable $e) { error_log('agent trips lines: ' . $e->getMessage()); _agent_trips_deny('Could not save the trip.'); }
    header('Location: ' . root . 'agent/trips/' . $ref);
    exit;
});

// ---- POST /agent/trips/{ref}/send  (mint client token, mark sent) ----------
$router->post('/agent/trips/(TRP[A-Z0-9]+)/send', function ($ref) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'trips', 'send')) { _agent_trips_deny('You cannot send proposals.'); }
    $trip = $db->get('agent_trip_plans', '*', ['ref' => $ref, 'owner_user_id' => $owner]);
    if (!$trip) { _agent_trips_deny('Trip plan not found.'); }

    $token = (string)($trip['client_token'] ?? '');
    if ($token === '') { $token = bin2hex(random_bytes(20)); }
    $msg = trim((string)($_POST['client_message'] ?? ''));
    try {
        $db->update('agent_trip_plans',
            ['client_token'=>$token, 'client_message'=>$msg, 'status'=>'sent',
             'sent_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')],
            ['ref'=>$ref, 'owner_user_id'=>$owner]);
    } catch (\Throwable $e) { error_log('agent trips send: ' . $e->getMessage()); _agent_trips_deny('Could not prepare the proposal.'); }

    // Email the client the branded proposal link (best-effort).
    $email = (string)($trip['client_email'] ?? '');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('SENDEMAIL')) {
        try {
            $ab = function_exists('agent_booking_brand') ? agent_booking_brand($db, ['agency_id'=>$owner]) : null;
            $brand = $ab['companyName'] ?? ($GLOBALS['app']['business_name'] ?? 'Your travel agent');
            $link = root . 'trip/' . $ref . '?token=' . urlencode($token);
            SENDEMAIL($email, (string)($trip['client_name'] ?: $email), 'Your travel proposal: ' . (string)$trip['title'],
                '<p>Hello,</p><p>'.htmlspecialchars($brand).' has prepared a travel proposal for you'
                .(($msg!=='')?': </p><p><em>'.htmlspecialchars($msg).'</em>':'.').'</p>'
                .'<p><a href="'.$link.'">View your proposal</a></p>', null);
        } catch (\Throwable $e) { error_log('agent trips send email: ' . $e->getMessage()); }
    }
    $_SESSION['message'] = ['type'=>'success','text'=>'Proposal ready to share.'];
    header('Location: ' . root . 'agent/trips/' . $ref);
    exit;
});

// ---- POST /agent/trips/delete ----------------------------------------------
$router->post('/agent/trips/delete', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_trips_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'trips', 'edit') && !agent_can($db, 'trips', 'add')) { _agent_trips_deny(); }
    $ref = (string)($_POST['ref'] ?? '');
    $own = $ref !== '' ? $db->get('agent_trip_plans', ['id'], ['ref'=>$ref, 'owner_user_id'=>$owner]) : null;
    if (!$own) { _agent_trips_deny('Trip plan not found.'); }
    try { $db->delete('agent_trip_plans', ['ref'=>$ref, 'owner_user_id'=>$owner]);
          $_SESSION['message'] = ['type'=>'success','text'=>'Trip plan deleted.']; }
    catch (\Throwable $e) { error_log('agent trips delete: ' . $e->getMessage()); _agent_trips_deny('Could not delete.'); }
    header('Location: ' . root . 'agent/trips');
    exit;
});

// ---- GET /trip/{ref}  (PUBLIC branded proposal, token-gated) ---------------
$router->get('/trip/(TRP[A-Z0-9]+)', function ($ref) use ($SECURE, $db) {
    $token = (string)($_GET['token'] ?? '');
    $trip = $token !== '' ? $db->get('agent_trip_plans', '*', ['ref' => $ref, 'client_token' => $token]) : null;
    // Enforce expiry: a proposal link is not valid forever (audit). If expires_at
    // is set and in the past, treat the link as expired (render nothing/expired).
    if ($trip && !empty($trip['expires_at'])) {
        $exp = strtotime((string)$trip['expires_at']);
        if ($exp !== false && $exp < time()) { $trip = null; }
    }
    // Build the agency brand for the public page (logo/colours/contact).
    $brand = null;
    if ($trip && function_exists('agent_booking_brand')) {
        $brand = agent_booking_brand($db, ['agency_id' => (string)$trip['owner_user_id']]);
    }
    $items = $trip ? (json_decode((string)($trip['items_data'] ?? '[]'), true) ?: []) : [];
    $totals = $trip ? (json_decode((string)($trip['totals_data'] ?? '{}'), true) ?: ['total'=>0]) : ['total'=>0];

    // Standalone branded page (no portal chrome).
    require_once views."agent/trip-proposal.php";
    exit;
});
