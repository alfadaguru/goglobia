<?php
// ============================================================================
// app/routes/api/users/deviceRoutes.php — mobile push device registration.
// The app calls these on login / FCM-token refresh / logout so the server has a
// token to push to. Auth = the same Bearer-JWT the other /api/users/* routes use.
//   POST /api/devices/register    body: { device_token, platform?, app_version? }
//   POST /api/devices/unregister  body: { device_token }
// ============================================================================
@$SECURE or die('Access Denied!');

/** Shared: resolve the Bearer-JWT user → users.user_id (string), or emit 401+exit. */
if (!function_exists('_api_device_user')) {
    function _api_device_user($db): string
    {
        header('Content-Type: application/json');
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $m)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required']); exit;
        }
        $tokenData = JWT::verify($m[1]);
        if (!$tokenData || empty($tokenData['user_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']); exit;
        }
        // The JWT carries users.id (numeric PK); user_devices keys on users.user_id (string).
        $u = $db->get('users', ['user_id'], ['id' => (int) $tokenData['user_id']]);
        if (!$u || empty($u['user_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'User not found']); exit;
        }
        return (string) $u['user_id'];
    }
}

if (!function_exists('_api_device_input')) {
    function _api_device_input(): array
    {
        $raw = file_get_contents('php://input');
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) { $body = $_POST; }
        return is_array($body) ? $body : [];
    }
}

// ---- POST /api/devices/register --------------------------------------------
$router->post('/api/devices/register', function () use ($db) {
    $userId = _api_device_user($db);
    $in = _api_device_input();
    $token = trim((string) ($in['device_token'] ?? ''));
    $platform = strtolower(trim((string) ($in['platform'] ?? 'unknown')));
    $appVersion = isset($in['app_version']) ? substr(trim((string) $in['app_version']), 0, 40) : null;

    if ($token === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'device_token is required']); exit;
    }
    $ok = function_exists('push_register_device')
        ? push_register_device($db, $userId, $token, $platform, $appVersion)
        : false;

    http_response_code($ok ? 200 : 500);
    echo json_encode($ok
        ? ['status' => 'success', 'message' => 'Device registered for push notifications']
        : ['status' => 'error', 'message' => 'Could not register device']);
    exit;
});

// ---- POST /api/devices/unregister ------------------------------------------
$router->post('/api/devices/unregister', function () use ($db) {
    // Still require auth so a token can't be de-registered by a stranger guessing it.
    _api_device_user($db);
    $in = _api_device_input();
    $token = trim((string) ($in['device_token'] ?? ''));
    if ($token === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'device_token is required']); exit;
    }
    $ok = function_exists('push_unregister_device') ? push_unregister_device($db, $token) : false;
    echo json_encode($ok
        ? ['status' => 'success', 'message' => 'Device unregistered']
        : ['status' => 'error', 'message' => 'Could not unregister device']);
    exit;
});
