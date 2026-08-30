<?php

/**
 * Authentication Class
 * Handles user authentication and session management
 */
class auth
{
    /**
     * Check if user is logged in
     * @return bool
     */
    public static function isLoggedIn()
    {
        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if admin is logged in (for backend routes)
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            return true;
        }
        
        // Check if regular user is logged in (for frontend routes)
        if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if admin is logged in
     * @return bool
     */
    public static function isAdmin()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    }
    
    /**
     * Get current user ID
     * @return int|null
     */
    public static function getUserId()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (self::isAdmin()) {
            return $_SESSION['admin_id'] ?? null;
        } elseif (self::isLoggedIn()) {
            return $_SESSION['user_id'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Login user
     * @param int $userId
     * @param array $userData
     */
    public static function loginUser($userId, $userData = [])
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_data'] = $userData;
        $_SESSION['login_time'] = time();
    }
    
    /**
     * Login admin
     * @param int $adminId
     * @param array $adminData
     */
    public static function loginAdmin($adminId, $adminData = [])
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_data'] = $adminData;
        $_SESSION['login_time'] = time();
    }
    
    /**
     * Logout user/admin
     */
    public static function logout()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // Clear all session data
        session_unset();
        session_destroy();
        
        // Start new session
        session_start();
        session_regenerate_id(true);
    }
    
    /**
     * Require authentication (redirect if not logged in)
     * @param string $redirectUrl
     */
    public static function requireAuth($redirectUrl = '/admin/login')
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
    
    /**
     * Require admin authentication
     * @param string $redirectUrl
     */
    public static function requireAdmin($redirectUrl = '/admin/login')
    {
        if (!self::isAdmin()) {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

?>
