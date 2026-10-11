<?php
// FILE: app/routes/users/supplierReviewsExtRoutes.php
// MODULE 23 (Reputation & reviews) — DEEPEN routes. Sits beside supplierReviewsRoutes.php
// (which it does NOT touch). Adds:
//   PUBLIC   GET/POST /stay-survey/{token}         — structured guest survey (NPS + per
//            -category sub-ratings + comment); same tokened eligibility as a star review.
//   OWNER    GET  /supplier/reputation             — reputation dashboard (rollup + NPS +
//            sentiment + department scorecard + recent surveys + internal QA).
//   OWNER    POST /supplier/reputation/internal    — add an internal (private) QA rating.
//   OWNER    POST /supplier/reputation/suggest     — AI/template reply suggestion (JSON).
// Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can('reservations',…)
// (reviews are owned by the reservations capability in the base module). Property writes
// re-check ownership (IDOR guard). No money/availability touched here.

@$SECURE or die('Access Denied!');

// ---- PUBLIC: GET /stay-survey/{token} — show the survey form -----------------------
$router->get('/stay-survey/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    $invoice = function_exists('review_token_decode') ? review_token_decode($token) : '';
    $elig = ($invoice !== '' && function_exists('survey_eligible')) ? survey_eligible($db, $invoice) : ['ok' => false];
    $property = null;
    if (!empty($elig['ok'])) {
        $property = $db->get('stays', ['id', 'name', 'location'], ['id' => (int) $elig['stay_id']]);
    }
    $surveyToken = $token;
    $surveyCategories = function_exists('survey_categories') ? survey_categories() : [];
    $title = 'Guest satisfaction survey'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reviews-ext.php";
    require_once views . "includes/footer.php";
});

// ---- PUBLIC: POST /stay-survey/{token} — submit the survey -------------------------
$router->post('/stay-survey/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    CSRF::guard();
    $invoice = function_exists('review_token_decode') ? review_token_decode($token) : '';
    $back = root . 'stay-survey/' . rawurlencode($token);
    if ($invoice === '' || !function_exists('survey_submit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid survey link.'];
        header('Location: ' . $back); exit;
    }
    $scores = [];
    $raw = $_POST['scores'] ?? [];
    if (is_array($raw)) { foreach ($raw as $k => $v) { $scores[(string) $k] = (int) $v; } }
    $res = survey_submit($db, $invoice, (int) ($_POST['nps'] ?? 0), $scores, (string) ($_POST['comment'] ?? ''));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// ---- OWNER: GET /supplier/reputation — reputation dashboard ------------------------
$router->get('/supplier/reputation', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    // Property drill-in: a ?stay_id scopes the dashboard to one owned property.
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    $scopeIds = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? [$stayFilter] : $stayIds;

    $dash     = function_exists('reputation_dashboard') ? reputation_dashboard($db, $scopeIds) : [];
    $surveys  = function_exists('survey_list_for_org') ? survey_list_for_org($db, $scopeIds, 50) : [];
    $internal = function_exists('internal_rating_list') ? internal_rating_list($db, $scopeIds, 50) : [];
    $canEdit  = supplier_can($db, 'reservations', 'edit');

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }

    $title = 'Reputation'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reputation.php";
    require_once views . "includes/footer.php";
});

// ---- OWNER: POST /supplier/reputation/internal — add an internal QA rating ---------
$router->post('/supplier/reputation/internal', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/reputation';
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    // The property must belong to this owner (defense in depth behind supplier_can).
    if (!supplier_can($db, 'reservations', 'edit', $stayId) || !$db->has('stays', ['id' => $stayId, 'user_id' => $owner])) {
        _supplier_stays_deny('Not your property.');
    }
    $ctx = supplier_acting_context($db);
    $by  = is_array($ctx) ? (string) ($ctx['actor'] ?? '') : '';
    $ok  = function_exists('internal_rating_add') && internal_rating_add(
        $db, $stayId, (string) ($_POST['invoice_id'] ?? ''), (int) ($_POST['rating'] ?? 3),
        (string) ($_POST['department'] ?? ''), (string) ($_POST['note'] ?? ''), $by
    );
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Internal rating recorded.' : 'Could not record the rating.'];
    header('Location: ' . $back . ($stayId > 0 ? '?stay_id=' . $stayId : '')); exit;
});

// ---- OWNER: POST /supplier/reputation/suggest — AI/template reply suggestion (JSON) -
$router->post('/supplier/reputation/suggest', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    header('Content-Type: application/json');
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Unauthorised']); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { echo json_encode(['ok' => false, 'message' => 'Not authorised']); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    // Scope the property name to an owned property only (no cross-tenant leak).
    $propName = '';
    if ($stayId > 0 && $db->has('stays', ['id' => $stayId, 'user_id' => $owner])) {
        $p = $db->get('stays', ['name'], ['id' => $stayId]);
        $propName = (string) ($p['name'] ?? '');
    }
    $sug = function_exists('review_ai_reply_suggestion')
        ? review_ai_reply_suggestion($db, (int) ($_POST['rating'] ?? 5), (string) ($_POST['comment'] ?? ''), $propName)
        : ['text' => '', 'source' => 'template'];
    echo json_encode(['ok' => true, 'text' => $sug['text'], 'source' => $sug['source']]);
    exit;
});
