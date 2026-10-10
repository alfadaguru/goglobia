<?php
// FILE: app/routes/users/supplierReviewsRoutes.php
// Reviews (Phase 1 inc S31). Two surfaces:
//   PUBLIC guest capture — GET/POST /stay-review/{token} (no login; token binds the
//     invoice, and the submit re-validates the booking is post-stay + unreviewed).
//   OWNER moderation — GET /supplier/reviews + POST /supplier/reviews/status
//     (supplier_can('reservations', …)); publishing/hiding re-rolls stays.rating.

@$SECURE or die('Access Denied!');

// ---- PUBLIC: GET /stay-review/{token} — show the review form -----------------------
$router->get('/stay-review/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    $invoice = function_exists('review_token_decode') ? review_token_decode($token) : '';
    $elig = ($invoice !== '' && function_exists('review_eligible_booking')) ? review_eligible_booking($db, $invoice) : ['ok' => false];
    $property = null;
    if (!empty($elig['ok'])) {
        $property = $db->get('stays', ['id', 'name', 'location'], ['id' => (int) $elig['stay_id']]);
    }
    $reviewToken = $token;
    $title = 'Review your stay'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "stays/review.php";
    require_once views . "includes/footer.php";
});

// ---- PUBLIC: POST /stay-review/{token} — submit the review -------------------------
$router->post('/stay-review/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    CSRF::guard();
    $invoice = function_exists('review_token_decode') ? review_token_decode($token) : '';
    $back = root . 'stay-review/' . rawurlencode($token);
    if ($invoice === '' || !function_exists('review_submit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid review link.'];
        header('Location: ' . $back); exit;
    }
    $res = review_submit($db, $invoice, (int) ($_POST['rating'] ?? 0), (string) ($_POST['comment'] ?? ''));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// ---- OWNER: GET /supplier/reviews — moderation queue -------------------------------
$router->get('/supplier/reviews', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    if (!in_array($status, ['pending', 'published', 'hidden'], true)) { $status = ''; }
    $reviews = function_exists('review_list_for_org') ? review_list_for_org($db, $stayIds, $status) : [];
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Reviews'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reviews.php";
    require_once views . "includes/footer.php";
});

// ---- OWNER: POST /supplier/reviews/status — publish / hide -------------------------
$router->post('/supplier/reviews/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/reviews';
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    // The property must belong to this owner (defense in depth behind supplier_can).
    if (!supplier_can($db, 'reservations', 'edit', $stayId) || !$db->has('stays', ['id' => $stayId, 'user_id' => $owner])) {
        _supplier_stays_deny('Not your property.');
    }
    $ok = function_exists('review_set_status') && review_set_status($db, $reviewId, $stayId, $to);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Review updated; rating recomputed.' : 'Could not update the review.'];
    header('Location: ' . $back); exit;
});
