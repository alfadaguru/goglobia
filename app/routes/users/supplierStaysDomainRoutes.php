<?php
/**
 * SUPPLIER STAYS DOMAIN ROUTES
 * 
 * Allows suppliers to generate temporary domains without admin setup.
 */

@$SECURE or die('Access Denied!');

// Load temp domain function
require_once 'app/lib/supplier_temp_domains.php';

// POST /supplier/stays/{id}/generate-temp-domain
// Generate a temporary domain for immediate use
$router->post('/supplier/stays/([0-9]+)/generate-temp-domain', function ($stayId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    
    header('Content-Type: application/json');
    
    try {
        $stayId = (int) $stayId;
        
        // Verify supplier owns this stay
        $stay = $db->get('stays', ['id', 'org_id', 'slug'], ['id' => $stayId]);
        if (!$stay) {
            throw new Exception('Property not found');
        }
        
        // Verify permission
        if (!user_can_edit_org($db, $stay['org_id'])) {
            throw new Exception('Permission denied');
        }
        
        $orgId = (int) $stay['org_id'];
        $slug = (string) $stay['slug'];
        
        // Check if temp domain already exists
        $site = $db->get('stays_site', ['temp_domain'], ['stay_id' => $stayId]);
        if ($site && !empty($site['temp_domain'])) {
            // Already has one, return it
            return [
                'ok' => true,
                'message' => 'Temporary domain already generated',
                'domain' => $site['temp_domain'],
            ];
        }
        
        // Generate new temp domain
        $result = supplier_generate_temp_domain($db, $orgId, $stayId, $slug);
        
        if ($result['ok']) {
            echo json_encode([
                'ok' => true,
                'message' => 'Temporary domain generated successfully',
                'domain' => $result['domain'],
                'url' => 'https://' . $result['domain'],
                'status' => 'active',
                'type' => 'temporary',
            ]);
        } else {
            throw new Exception($result['message']);
        }
        
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});

