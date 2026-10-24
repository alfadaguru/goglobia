<?php
// ============================================================================
// ADMIN — Popular Flight Routes manager (homepage "Popular flight routes" strip)
//
// CRUD over `flights_popular_routes`. The admin owns the list entirely: which
// routes show, their scope (local/international), tile image, order, and on/off.
// All handlers are ADMIN_AUTH()-gated; every state-changing POST is CSRF-guarded
// (CLAUDE.md §6/§10). From/To are picked via the existing local-airport search
// (admin/flights/search-airports), so a route always maps to a real airport.
// ============================================================================
@$SECURE or die('Access Denied!');

// ---- LIST / SCREEN --------------------------------------------------------
$router->get(admin.'/flights/popular-routes', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flights', 'view');
    if (function_exists('ensureFlightsPopularRoutesSchema')) {
        ensureFlightsPopularRoutesSchema($db);
    }
    $title = 'Popular Flight Routes';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . 'includes/header.php';
    require_once 'app/views/admin/flights/popular-routes.php';
    require_once views . 'includes/footer.php';
});

// ---- CREATE / UPDATE (single JSON endpoint) -------------------------------
$router->post(admin.'/flights/popular-routes/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flights', 'edit');
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');

    $id       = (int) ($_POST['id'] ?? 0);
    $fromCode = strtoupper(trim((string) ($_POST['from_code'] ?? '')));
    $toCode   = strtoupper(trim((string) ($_POST['to_code'] ?? '')));
    $fromLbl  = trim((string) ($_POST['from_label'] ?? ''));
    $toLbl    = trim((string) ($_POST['to_label'] ?? ''));
    $scope    = ($_POST['scope'] ?? 'international') === 'local' ? 'local' : 'international';
    $image    = trim((string) ($_POST['image'] ?? ''));
    $sort     = (int) ($_POST['sort_order'] ?? 0);
    $status   = isset($_POST['status']) && (string) $_POST['status'] === '1' ? 1 : 0;

    // Validate airport codes against the real airports table (no dead routes).
    if ($fromCode === '' || $toCode === '') {
        echo json_encode(['success' => false, 'message' => 'From and To airports are required.']); exit;
    }
    if ($fromCode === $toCode) {
        echo json_encode(['success' => false, 'message' => 'From and To cannot be the same airport.']); exit;
    }
    $haveFrom = (int) $db->count('flights_airports', '*', ['code' => $fromCode]);
    $haveTo   = (int) $db->count('flights_airports', '*', ['code' => $toCode]);
    if ($haveFrom < 1 || $haveTo < 1) {
        echo json_encode(['success' => false, 'message' => 'Unknown airport code — pick From/To from the suggestions.']); exit;
    }

    // Auto-fill labels from the airport city when the admin left them blank.
    if ($fromLbl === '') { $fromLbl = (string) ($db->get('flights_airports', 'city', ['code' => $fromCode]) ?: $fromCode); }
    if ($toLbl === '')   { $toLbl   = (string) ($db->get('flights_airports', 'city', ['code' => $toCode]) ?: $toCode); }

    // Guard the unique (from,to) pair against collisions with a DIFFERENT row.
    $dupe = $db->get('flights_popular_routes', ['id'], ['from_code' => $fromCode, 'to_code' => $toCode]);
    if ($dupe && (int) $dupe['id'] !== $id) {
        echo json_encode(['success' => false, 'message' => 'That route already exists.']); exit;
    }

    $data = [
        'from_code'  => $fromCode,
        'to_code'    => $toCode,
        'from_label' => $fromLbl,
        'to_label'   => $toLbl,
        'scope'      => $scope,
        'image'      => $image !== '' ? $image : null,
        'sort_order' => $sort,
        'status'     => $status,
    ];

    try {
        if ($id > 0) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $db->update('flights_popular_routes', $data, ['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Route updated.', 'id' => $id]);
        } else {
            $db->insert('flights_popular_routes', $data);
            echo json_encode(['success' => true, 'message' => 'Route added.', 'id' => (int) $db->id()]);
        }
    } catch (\Throwable $e) {
        error_log('popular-routes save: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not save the route.']);
    }
    exit;
});

// ---- TOGGLE STATUS --------------------------------------------------------
$router->post(admin.'/flights/popular-routes/toggle', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flights', 'edit');
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_POST['id'] ?? 0);
    $row = $id > 0 ? $db->get('flights_popular_routes', ['status'], ['id' => $id]) : null;
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Route not found.']); exit; }
    $new = ((int) $row['status'] === 1) ? 0 : 1;
    $db->update('flights_popular_routes', ['status' => $new, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    echo json_encode(['success' => true, 'status' => $new]);
    exit;
});

// ---- DELETE ---------------------------------------------------------------
$router->post(admin.'/flights/popular-routes/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flights', 'delete');
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['success' => false, 'message' => 'Invalid route.']); exit; }
    $db->delete('flights_popular_routes', ['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
});
