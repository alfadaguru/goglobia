<?php
// FILE: app/routes/api/themeRoutes.php
// Theme handling API route for mobile app

@$SECURE or die('Access Denied!');

require_once 'app/lib/ThemeManager.php';

// ---------------------------------------------------------------------------
// CORS — allow browser requests from any origin (static exports, dev servers)
// ---------------------------------------------------------------------------
// SECURITY (H6): allow-list only; never `*` with credentials (see globalApiRoutes).
$allowed_origins = ['http://localhost:3000', 'http://localhost:3001', 'http://localhost:8080', 'http://127.0.0.1:3000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Helper function to send JSON response and exit
if (!function_exists('sendThemeJsonResponse')) {
    function sendThemeJsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'success' => $success,
            'message' => $message
        ];
        if (!empty($data) || $success) {
            $response['data'] = $data;
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| API: GET ACTIVE THEME CONFIGURATION
|--------------------------------------------------------------------------
*/
$router->get('/api/theme/active', function () use ($db) {
    try {
        $activeThemeId = ThemeManager::getActiveTheme();
        $config = ThemeManager::loadTheme($activeThemeId);
        
        $themeName = is_array($config) && isset($config['name']) ? $config['name'] : ucfirst(str_replace('-', ' ', $activeThemeId));
        
        $data = [
            'theme_id' => $activeThemeId,
            'theme_name' => $themeName,
            'logo_url' => root . 'uploads/global/logo.png',
            'favicon_url' => root . 'uploads/global/favicon.png',
            'cover_url' => root . 'uploads/global/cover.png',
            'config' => $config
        ];
        
        sendThemeJsonResponse(true, 'Active theme retrieved successfully', $data);
    } catch (Throwable $e) {
        sendThemeJsonResponse(false, 'Failed to retrieve active theme: ' . $e->getMessage(), [], 500);
    }
});
