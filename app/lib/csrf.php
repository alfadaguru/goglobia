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
}
?>