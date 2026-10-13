<?php
// ============================================================================
// STAYS SITE DEFAULTS
// Fallback domain generation when admin hasn't configured branded domain
// ============================================================================

/**
 * Get effective branded domain root (with fallbacks)
 * 
 * Priority:
 * 1. Admin-configured supplier_site_domain
 * 2. Temporary domain platform (goglobia.app by default)
 * 3. Current request domain (yourdomain.com)
 * 
 * This ensures suppliers ALWAYS get a working domain, even if admin
 * hasn't configured the branded domain root yet.
 * 
 * @return string Domain root (e.g., 'yourdomain.com' or 'goglobia.app')
 */
if (!function_exists('stays_get_effective_domain_root')) {
    function stays_get_effective_domain_root(): string {
        
        // Priority 1: Admin-configured branded domain root
        $adminConfigured = trim((string) ($GLOBALS['app']['supplier_site_domain'] ?? ''));
        if ($adminConfigured !== '') {
            return $adminConfigured;
        }
        
        // Priority 2: Fallback to platform default (goglobia.app or similar)
        $platformDefault = trim((string) ($GLOBALS['app']['supplier_temp_domain'] ?? 'goglobia.app'));
        if ($platformDefault !== '') {
            return $platformDefault;
        }
        
        // Priority 3: Use current request domain as fallback
        $requestHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (strpos($requestHost, ':') !== false) {
            $requestHost = substr($requestHost, 0, strpos($requestHost, ':'));
        }
        
        return $requestHost;
    }
}

/**
 * Generate default hostname for a property (auto-generating if needed)
 * 
 * When supplier views "Site & Domain" page, this generates the hostname
 * that will be shown. Uses effective domain root, not admin setting.
 * 
 * @param string $slug Property slug
 * @return string Hostname like 'mybusiness.goglobia.app' or 'mybusiness.yourdomain.com'
 */
if (!function_exists('stays_generate_default_hostname')) {
    function stays_generate_default_hostname(string $slug): string {
        
        $root = stays_get_effective_domain_root();
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $slug), '-'));
        
        if ($root === '' || $slug === '') {
            return '';
        }
        
        return $slug . '.' . $root;
    }
}

