<?php
// ============================================================================
// STAYS HOST-BASED ROUTING
// Routes requests to custom domains and branded subdomains to correct property
// ============================================================================

/**
 * Resolve Host header to stay_id
 * Called before main routing to handle:
 * - Custom domains: mybusiness.com → stay_id
 * - Branded subdomains: mybusiness.yourdomain.com → stay_id
 * - Main domain: yourdomain.com → normal routing (no override)
 * 
 * @param object $db Database connection
 * @param string $host Host header value
 * @return int|null stay_id or null if not a white-label domain
 */
if (!function_exists('stays_resolve_host_to_id')) {
    function stays_resolve_host_to_id($db, string $host): ?int {
        
        $host = strtolower(trim($host));
        if ($host === '') { 
            return null; 
        }
        
        // Remove port if present
        if (strpos($host, ':') !== false) {
            $host = substr($host, 0, strpos($host, ':'));
        }
        
        try {
            // Priority 1: Check custom domains (fully verified/active only)
            $custom = $db->get('stays_site', ['stay_id'], 
                ['custom_domain' => $host, 'domain_status' => ['verified', 'active']]);
            
            if ($custom) {
                return (int) $custom['stay_id'];
            }
            
            // Priority 2: Check branded hostnames (if configured)
            $branded = $db->get('stays_site', ['stay_id'], 
                ['hostname' => $host]);
            
            if ($branded) {
                return (int) $branded['stay_id'];
            }
            
            // Priority 3: Check temporary domains
            $temp = $db->get('stays_site', ['stay_id'], 
                ['temp_domain' => $host]);
            
            if ($temp) {
                return (int) $temp['stay_id'];
            }
            
        } catch (\Throwable $e) {
            error_log('stays_resolve_host_to_id error: ' . $e->getMessage());
        }
        
        return null;
    }
}

/**
 * Get the stay's domain information (which domain is active)
 * 
 * @param object $db Database connection
 * @param int $stayId Property ID
 * @return array ['domain' => string, 'type' => 'custom|branded|temporary']
 */
if (!function_exists('stays_get_active_domain')) {
    function stays_get_active_domain($db, int $stayId): ?array {
        
        try {
            $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
            if (!$site) {
                return null;
            }
            
            // Priority 1: Active custom domain
            if (!empty($site['custom_domain']) && in_array($site['domain_status'] ?? '', ['verified', 'active'])) {
                return [
                    'domain' => $site['custom_domain'],
                    'type' => 'custom',
                    'verified' => $site['domain_status'] === 'active',
                ];
            }
            
            // Priority 2: Branded hostname (if set)
            if (!empty($site['hostname'])) {
                return [
                    'domain' => $site['hostname'],
                    'type' => 'branded',
                    'verified' => true,
                ];
            }
            
            // Priority 3: Temporary domain (if generated)
            if (!empty($site['temp_domain'])) {
                return [
                    'domain' => $site['temp_domain'],
                    'type' => 'temporary',
                    'verified' => true,
                ];
            }
            
        } catch (\Throwable $e) {
            error_log('stays_get_active_domain error: ' . $e->getMessage());
        }
        
        return null;
    }
}

/**
 * Helper: Check if this is the main platform domain
 * Returns true if Host matches the main application domain
 * 
 * @param string $host Host header value
 * @param string $mainDomain Main platform domain (e.g., yourdomain.com)
 * @return bool
 */
if (!function_exists('stays_is_main_domain')) {
    function stays_is_main_domain(string $host, string $mainDomain = ''): bool {
        
        if ($mainDomain === '') {
            // Extract from current request
            $mainDomain = $_SERVER['HTTP_HOST'] ?? '';
            if (strpos($mainDomain, ':') !== false) {
                $mainDomain = substr($mainDomain, 0, strpos($mainDomain, ':'));
            }
        }
        
        // Remove port and normalize
        $host = strtolower(trim($host));
        $mainDomain = strtolower(trim($mainDomain));
        
        if (strpos($host, ':') !== false) {
            $host = substr($host, 0, strpos($host, ':'));
        }
        
        return $host === $mainDomain;
    }
}

