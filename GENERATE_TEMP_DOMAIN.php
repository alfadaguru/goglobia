<?php
/**
 * TEMPORARY DOMAIN GENERATION FUNCTION
 * 
 * Allow suppliers to generate their own temporary branded domain
 * WITHOUT waiting for admin to set the platform's branded domain root.
 * 
 * This uses a platform-provided temporary domain that works immediately.
 */

if (!function_exists('supplier_generate_temp_domain')) {
    /**
     * Generate a unique temporary subdomain for a supplier's property.
     * Format: {org_id}-{property_slug}-{random}.goglobia.app (or configured temp domain)
     * 
     * @param object $db Database connection
     * @param int $orgId Supplier organization ID
     * @param int $stayId Property ID
     * @param string $slug Property slug
     * @return array ['ok' => bool, 'message' => str, 'domain' => 'xxx.goglobia.app' or null]
     */
    function supplier_generate_temp_domain($db, int $orgId, int $stayId, string $slug): array
    {
        try {
            // Temp domain root (platform-controlled, always available)
            $tempDomainRoot = trim((string) ($GLOBALS['app']['supplier_temp_domain'] ?? 'goglobia.app'));
            if ($tempDomainRoot === '') {
                $tempDomainRoot = 'goglobia.app';
            }
            
            // Sanitize slug
            $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $slug), '-'));
            if ($slug === '') {
                return ['ok' => false, 'message' => 'Invalid property slug'];
            }
            
            // Generate unique subdomain: {org}-{slug}-{random}
            $random = substr(bin2hex(random_bytes(4)), 0, 8);
            $tempDomain = sprintf('%d-%s-%s.%s', $orgId, $slug, $random, $tempDomainRoot);
            
            // Verify it doesn't already exist
            $existing = $db->get('stays_site', ['id'], ['temp_domain' => $tempDomain]);
            if ($existing) {
                // Collision (extremely rare), retry
                return supplier_generate_temp_domain($db, $orgId, $stayId, $slug);
            }
            
            // Get stays_site record
            $site = $db->get('stays_site', ['id'], ['stay_id' => $stayId]);
            if (!$site) {
                // Create it if doesn't exist
                $db->insert('stays_site', [
                    'stay_id' => $stayId,
                    'domain_status' => 'none',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $site = $db->get('stays_site', ['id'], ['stay_id' => $stayId]);
            }
            
            // Update with temp domain
            $db->update('stays_site', [
                'temp_domain' => $tempDomain,
                'temp_domain_created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['stay_id' => $stayId]);
            
            // Log action
            error_log("Temporary domain generated: $tempDomain for stay $stayId (org $orgId)");
            
            return [
                'ok' => true,
                'message' => 'Temporary domain generated successfully',
                'domain' => $tempDomain,
            ];
            
        } catch (\Throwable $e) {
            error_log('supplier_generate_temp_domain: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error generating temporary domain'];
        }
    }
}

if (!function_exists('supplier_get_active_domain')) {
    /**
     * Get the currently active domain for a property (in priority order):
     * 1. Custom domain (if verified/active)
     * 2. Branded hostname (if admin set platform domain root)
     * 3. Temporary domain (if supplier generated one)
     * 4. Fallback: /book/{slug} link
     * 
     * @param array $site stays_site record
     * @return array ['domain' => 'xxx.com', 'type' => 'custom|branded|temporary|fallback']
     */
    function supplier_get_active_domain(array $site): array
    {
        $custom = (string) ($site['custom_domain'] ?? '');
        $customStatus = (string) ($site['domain_status'] ?? 'none');
        $hostname = (string) ($site['hostname'] ?? '');
        $tempDomain = (string) ($site['temp_domain'] ?? '');
        
        // Priority 1: Custom domain (if verified or active)
        if ($custom !== '' && in_array($customStatus, ['verified', 'active'])) {
            return ['domain' => $custom, 'type' => 'custom', 'icon' => 'check_circle'];
        }
        
        // Priority 2: Branded hostname (if set)
        if ($hostname !== '') {
            return ['domain' => $hostname, 'type' => 'branded', 'icon' => 'star'];
        }
        
        // Priority 3: Temporary domain (if generated)
        if ($tempDomain !== '') {
            return ['domain' => $tempDomain, 'type' => 'temporary', 'icon' => 'schedule'];
        }
        
        // Priority 4: Fallback (no domain at all)
        return ['domain' => null, 'type' => 'fallback', 'icon' => 'help'];
    }
}

