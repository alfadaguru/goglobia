<?php
// FILE: app/routes/users/travellersRoutes.php
// Customer "Saved Travellers" — a CRUD page over the EXISTING user_travellers
// store (app/lib/ai/gia_travellers.php, already used by GIA). Reuses
// gia_travellers_list/get/forget; adds an explicit add/edit that writes the full
// row directly (the GIA upsert only fills blanks, which is wrong for a user who
// wants to CORRECT a value). Everything is scoped to the session user.

@$SECURE or die('Access Denied!');

require_once 'app/lib/ai/gia_travellers.php';

// ---- GET /travellers : the page ----
$router->get('/travellers', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    $travellers = function_exists('gia_travellers_list') ? gia_travellers_list($db, (string) $user_id, 50) : [];

    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => strtoupper((string) ($user['currency'] ?? 'USD')),
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];
    $travellersData = ['travellers' => $travellers];

    $title       = 'Saved Travellers';
    $description = 'People you book for';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/travellers.php';
    require_once views . 'includes/footer.php';
});

// ---- POST /travellers/save : add or edit (JSON, CSRF-guarded) ----
$router->post('/travellers/save', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }
    if (function_exists('ensureGiaTravellersSchema')) { ensureGiaTravellersSchema($db); }

    $uid   = (string) $_SESSION['user_id'];
    $id    = (int) ($input['id'] ?? 0);
    $first = trim((string) ($input['first_name'] ?? ''));
    if ($first === '') { echo json_encode(['ok' => false, 'message' => 'First name is required']); exit; }

    // Normalise (bounded lengths; passport/dob optional). htmlspecialchars NOT
    // applied here — stored raw, escaped on output (manual-escaping convention).
    $dateOrNull = function ($v) {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    };
    $row = [
        'title'           => substr(trim((string) ($input['title'] ?? '')), 0, 16) ?: null,
        'first_name'      => substr($first, 0, 120),
        'middle_name'     => substr(trim((string) ($input['middle_name'] ?? '')), 0, 120) ?: null,
        'last_name'       => substr(trim((string) ($input['last_name'] ?? '')), 0, 120) ?: null,
        'gender'          => substr(trim((string) ($input['gender'] ?? '')), 0, 8) ?: null,
        'dob'             => $dateOrNull($input['dob'] ?? ''),
        'nationality'     => substr(trim((string) ($input['nationality'] ?? '')), 0, 80) ?: null,
        'passport_number' => substr(trim((string) ($input['passport_number'] ?? '')), 0, 64) ?: null,
        'passport_expiry' => $dateOrNull($input['passport_expiry'] ?? ''),
        'email'           => substr(trim((string) ($input['email'] ?? '')), 0, 255) ?: null,
        'phone'           => substr(trim((string) ($input['phone'] ?? '')), 0, 40) ?: null,
        'relationship'    => substr(trim((string) ($input['relationship'] ?? '')), 0, 40) ?: null,
        'is_self'         => !empty($input['is_self']) ? 1 : 0,
    ];

    try {
        if ($id > 0) {
            // EDIT — ownership-scoped: only touches a row that belongs to this user.
            $own = $db->get('user_travellers', ['id'], ['user_id' => $uid, 'id' => $id]);
            if (!$own) { http_response_code(404); echo json_encode(['ok' => false, 'message' => 'Traveller not found']); exit; }
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('user_travellers', $row, ['user_id' => $uid, 'id' => $id]);
        } else {
            // ADD — compute the same dedupe key the lib uses so it dovetails with GIA.
            $key = function_exists('gia_traveller_dedupe_key') ? gia_traveller_dedupe_key($row) : ('nm:' . strtolower($first));
            $existing = $db->get('user_travellers', ['id'], ['user_id' => $uid, 'dedupe_key' => $key]);
            if ($existing) {
                $row['updated_at'] = date('Y-m-d H:i:s');
                $db->update('user_travellers', $row, ['id' => (int) $existing['id']]);
            } else {
                $db->insert('user_travellers', array_merge($row, [
                    'user_id'      => $uid,
                    'dedupe_key'   => $key,
                    'source'       => 'manual',
                    'use_count'    => 1,
                    'last_used_at' => date('Y-m-d H:i:s'),
                    'created_at'   => date('Y-m-d H:i:s'),
                ]));
            }
        }
        $list = function_exists('gia_travellers_list') ? gia_travellers_list($db, $uid, 50) : [];
        echo json_encode(['ok' => true, 'travellers' => $list]);
    } catch (\Throwable $e) {
        error_log('TRAVELLERS_SAVE: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Could not save the traveller. Please check the details and try again.']);
    }
    exit;
});

// ---- POST /travellers/delete : remove one (JSON, CSRF-guarded) ----
$router->post('/travellers/delete', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }
    $uid = (string) $_SESSION['user_id'];
    $id  = (int) ($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'Missing traveller']); exit; }

    $okDel = function_exists('gia_travellers_forget') ? gia_travellers_forget($db, $uid, $id) : false;
    $list  = function_exists('gia_travellers_list') ? gia_travellers_list($db, $uid, 50) : [];
    echo json_encode(['ok' => (bool) $okDel, 'travellers' => $list]);
    exit;
});
