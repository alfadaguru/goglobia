<?php
// FILE: app/routes/api/users/favouritesRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// FAVOURITES API  (wishlist — save / list / toggle)
// ====================================
// One canonical table: `favourites` (British). Each row: user_id + module +
// item_id + a `data` JSON blob (title/url/image/price/currency for display).
// Save-path works for BOTH the website (session) and the mobile app (JWT bearer).

if (!function_exists('fav_resolve_user')) {
    /**
     * Resolve the acting user for favourites: website session FIRST, then a
     * mobile JWT bearer token. Returns the string user_id or '' if neither.
     */
    function fav_resolve_user($db): string
    {
        if (!empty($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $m)) {
            require_once 'app/lib/jwt.php';
            $tokenData = JWT::verify($m[1]);
            if ($tokenData && !empty($tokenData['user_id'])) {
                return (string) $tokenData['user_id'];
            }
        }
        return '';
    }
}

if (!function_exists('fav_list_for')) {
    /** Return the user's saved favourites, newest first, with decoded display data. */
    function fav_list_for($db, string $userId): array
    {
        $rows = $db->select('favourites', '*', ['user_id' => $userId, 'ORDER' => ['id' => 'DESC']]);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $f) {
                $data = json_decode((string) ($f['data'] ?? ''), true);
                $out[] = [
                    'id'      => $f['id'] ?? '',
                    'module'  => $f['module'] ?? '',
                    'item_id' => $f['item_id'] ?? '',
                    'data'    => is_array($data) ? $data : [],
                    'date'    => $f['created_at'] ?? '',
                ];
            }
        }
        return $out;
    }
}

// LIST — session OR JWT. (Kept as POST for mobile back-compat; also accessible
// to the website now that it accepts the session.)
$router->post('/api/users/favourites', function () use ($db) {
    header('Content-Type: application/json');

    $user_id = fav_resolve_user($db);
    if ($user_id === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Favourites retrieved',
        'data'    => fav_list_for($db, $user_id),
    ]);
    exit;
});

// TOGGLE — save if absent, remove if present. The ONE write path (closes the
// gap where nothing could ever add a favourite). Session OR JWT; CSRF-guarded
// for the website's session (the mobile JWT path is the auth proof itself).
$router->post('/api/users/favourites/toggle', function () use ($db) {
    header('Content-Type: application/json');

    $user_id = fav_resolve_user($db);
    if ($user_id === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    // CSRF only for the website-session path (no bearer). Mobile presents a JWT.
    $hasBearer = (bool) preg_match('/Bearer\s\S+/', (string) (
        (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '') : '')
    ));
    if (!$hasBearer && class_exists('CSRF')) {
        $token = $_POST['csrf_token'] ?? '';
        if ($token === '') { $token = CSRF::tokenFromRequest(); }
        if (!CSRF::validateToken($token)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
            exit;
        }
    }

    $input  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = $_POST; }
    $module  = strtolower(trim((string) ($input['module'] ?? '')));
    $itemId  = trim((string) ($input['item_id'] ?? ''));
    $payload = is_array($input['data'] ?? null) ? $input['data'] : [];

    if ($module === '' || $itemId === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'module and item_id are required']);
        exit;
    }

    $existing = $db->get('favourites', ['id'], [
        'user_id' => $user_id, 'module' => $module, 'item_id' => $itemId,
    ]);

    if ($existing) {
        $db->delete('favourites', ['id' => (int) $existing['id']]);
        echo json_encode([
            'status' => 'success', 'saved' => false, 'message' => 'Removed from favourites',
            'count'  => (int) $db->count('favourites', ['user_id' => $user_id]),
        ]);
        exit;
    }

    try {
        $db->insert('favourites', [
            'user_id'    => $user_id,
            'module'     => $module,
            'item_id'    => $itemId,
            'data'       => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        // Unique-key race (double-click) → treat as already saved, not an error.
        error_log('favourites toggle insert: ' . $e->getMessage());
    }

    echo json_encode([
        'status' => 'success', 'saved' => true, 'message' => 'Saved to favourites',
        'count'  => (int) $db->count('favourites', ['user_id' => $user_id]),
    ]);
    exit;
});
