<?php
/**
 * CSRF Token Security Class
 * Provides CSRF protection for all forms
 */
class CSRF {

    /**
     * Generate CSRF token
     */
    public static function generateToken() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();

        return $token;
    }

    /**
     * Get current CSRF token
     */
    public static function getToken() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return self::generateToken();
        }

        // Token expires after 1 hour
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            return self::generateToken();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token
     */
    public static function validateToken($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }

        // Check if token expired
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
            return false;
        }

        // Compare tokens
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate HTML input field for CSRF token
     */
    public static function tokenField() {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Verify request has valid CSRF token
     */
    public static function verifyRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (!self::validateToken($token)) {
                http_response_code(403);
                die('CSRF token validation failed');
            }
        }
        return true;
    }

    /**
     * Pull the request's CSRF token from any of the transports the frontend uses:
     * the `csrf_token` POST field (classic forms), the `X-CSRF-TOKEN` header
     * (fetch/XHR — attached globally by assets/js/app.js), or a `csrf_token`
     * key inside a JSON request body (JSON endpoints).
     */
    public static function tokenFromRequest() {
        if (!empty($_POST['csrf_token'])) {
            return (string) $_POST['csrf_token'];
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($header !== '') {
            return (string) $header;
        }
        // JSON body (application/json) — parse once and cache on the request.
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ctype, 'application/json') !== false) {
            static $jsonCache = null;
            if ($jsonCache === null) {
                $raw = file_get_contents('php://input');
                $decoded = json_decode((string) $raw, true);
                $jsonCache = is_array($decoded) ? $decoded : [];
            }
            if (!empty($jsonCache['csrf_token'])) {
                return (string) $jsonCache['csrf_token'];
            }
        }
        return '';
    }

    /**
     * Fail-closed CSRF guard for state-changing admin POST handlers.
     *
     * Validates the token from any supported transport (see tokenFromRequest()).
     * On failure it responds correctly for the request type — a JSON 403 for
     * AJAX/JSON callers (so the frontend can surface the error) or a plain 403
     * for classic form posts — then exits. Only enforces on POST/PUT/PATCH/DELETE;
     * a GET is a no-op so it is safe to call unconditionally at the top of a route.
     */
    public static function guard() {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }
        if (self::validateToken(self::tokenFromRequest())) {
            return true;
        }

        http_response_code(403);
        // Treat as AJAX/JSON when the caller asks for JSON, sends JSON, or is an
        // XHR/fetch (which our interceptor marks via the X-CSRF-TOKEN header path).
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || stripos($accept, 'application/json') !== false
            || stripos($ctype, 'application/json') !== false
            || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
        } else {
            echo 'CSRF token validation failed';
        }
        exit;
    }
}
?>