<?php
// FILE: app/routes/users/supplierAiExtRoutes.php
// MODULE 57 (AI & intelligent automation) — DEEPEN routes. Beside the admin AI config
// (untouched). Adds OWNER/STAFF surfaces that are REAL without an LLM, plus an honest
// concierge stub:
//   OWNER GET  /supplier/ai-ext               — intelligence dashboard (forecast +
//             cancellation stats + review themes + rule-based ops summary + concierge
//             connection status). Property drill-in via ?stay_id.
//   OWNER POST /supplier/ai-ext/forecast      — (JSON) occupancy forecast for one owned
//             property + horizon.
//   OWNER POST /supplier/ai-ext/save-brief    — persist the rule-based ops summary (DB-GATED).
//   OWNER POST /supplier/ai-ext/concierge     — (JSON) attempt a concierge draft; returns
//             connected=false honestly when no provider is configured.
// Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can('reservations',…). No
// money/GL/availability writes. The only write is the text-only saved brief.

@$SECURE or die('Access Denied!');

// ---- OWNER: GET /supplier/ai-ext — intelligence dashboard --------------------------
$router->get('/supplier/ai-ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    $scopeIds = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? [$stayFilter] : $stayIds;
    $focusStay = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? $stayFilter : (int) ($stayIds[0] ?? 0);

    $forecast  = function_exists('ai_ext_occupancy_forecast') ? ai_ext_occupancy_forecast($db, $focusStay, 14, 90) : [];
    $cancel    = function_exists('ai_ext_cancellation_risk') ? ai_ext_cancellation_risk($db, $scopeIds) : [];
    $themes    = function_exists('ai_ext_review_themes') ? ai_ext_review_themes($db, $scopeIds) : [];
    $summary   = function_exists('ai_ext_ops_summary') ? ai_ext_ops_summary($db, $owner, $scopeIds, $focusStay) : ['lines' => [], 'source' => 'rule-based', 'generated_at' => ''];
    $concierge = function_exists('ai_ext_concierge_status') ? ai_ext_concierge_status($db) : ['connected' => false, 'can_generate' => false, 'reason' => 'Unavailable.', 'provider' => ''];

    $orgId = ($focusStay > 0 && function_exists('supplier_property_org')) ? supplier_property_org($db, $focusStay) : 0;
    $briefs = ($orgId > 0 && function_exists('ai_ext_brief_list')) ? ai_ext_brief_list($db, $orgId, 10) : [];

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'AI & automation'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/ai-ext.php";
    require_once views . "includes/footer.php";
});

// ---- OWNER: POST /supplier/ai-ext/forecast — occupancy forecast (JSON) -------------
$router->post('/supplier/ai-ext/forecast', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    header('Content-Type: application/json');
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Unauthorised']); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    // Property must belong to this owner (IDOR guard behind supplier_can).
    if (!supplier_can($db, 'reservations', 'view', $stayId) || !$db->has('stays', ['id' => $stayId, 'user_id' => $owner])) {
        echo json_encode(['ok' => false, 'message' => 'Not your property']); exit;
    }
    $horizon = (int) ($_POST['horizon'] ?? 14);
    $fc = function_exists('ai_ext_occupancy_forecast') ? ai_ext_occupancy_forecast($db, $stayId, $horizon, 90) : ['ok' => false, 'message' => 'Unavailable'];
    echo json_encode($fc);
    exit;
});

// ---- OWNER: POST /supplier/ai-ext/save-brief — persist the rule-based summary ------
$router->post('/supplier/ai-ext/save-brief', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/ai-ext';
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_POST['stay_id'] ?? 0);
    $scopeIds = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? [$stayFilter] : $stayIds;
    $focusStay = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? $stayFilter : (int) ($stayIds[0] ?? 0);

    // Org is derived from an OWNED property only — never from client input.
    $orgId = ($focusStay > 0 && function_exists('supplier_property_org')) ? supplier_property_org($db, $focusStay) : 0;
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No property/organisation to attach the brief to.'];
        header('Location: ' . $back . ($stayFilter > 0 ? '?stay_id=' . $stayFilter : '')); exit;
    }

    // Recompute the summary server-side — we do NOT trust any client-supplied text.
    $summary = function_exists('ai_ext_ops_summary') ? ai_ext_ops_summary($db, $owner, $scopeIds, $focusStay) : ['lines' => [], 'source' => 'rule-based'];
    $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
    $by  = is_array($ctx) ? (string) ($ctx['actor'] ?? '') : '';
    $res = function_exists('ai_ext_brief_save')
        ? ai_ext_brief_save($db, $orgId, (string) ($_POST['title'] ?? ''), $summary, $by)
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back . ($stayFilter > 0 ? '?stay_id=' . $stayFilter : '')); exit;
});

// ---- OWNER: POST /supplier/ai-ext/concierge — honest concierge draft (JSON) --------
$router->post('/supplier/ai-ext/concierge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    header('Content-Type: application/json');
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Unauthorised']); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { echo json_encode(['ok' => false, 'message' => 'Not authorised']); exit; }

    $draft = function_exists('ai_ext_concierge_draft')
        ? ai_ext_concierge_draft($db, (string) ($_POST['intent'] ?? ''), (string) ($_POST['context'] ?? ''))
        : ['ok' => false, 'text' => '', 'source' => 'unavailable', 'reason' => 'Unavailable'];
    echo json_encode($draft);
    exit;
});
