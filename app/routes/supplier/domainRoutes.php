<?php
@$SECURE or die('Access Denied!');
require_once 'app/lib/stays_booking_functions.php';
require_once 'app/lib/stays_site_defaults.php';

// POST /supplier/stays/{id}/generate-temp-domain
$router->post('/supplier/stays/([0-9]+)/generate-temp-domain', function ($stayId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    header('Content-Type: application/json');
    
    try {
        $stayId = (int) $stayId;
        $stay = $db->get('stays', ['id', 'org_id', 'slug'], ['id' => $stayId]);
        
        if (!$stay) throw new Exception('Property not found');
        if (!user_can_edit_org($db, $stay['org_id'])) throw new Exception('Permission denied');
        
        $site = $db->get('stays_site', ['temp_domain'], ['stay_id' => $stayId]);
        if ($site && !empty($site['temp_domain'])) {
            echo json_encode(['ok' => true, 'domain' => $site['temp_domain']]);
            exit;
        }
        
        $random = substr(bin2hex(random_bytes(4)), 0, 8);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $stay['slug']), '-'));
        $tempDomain = $stay['org_id'] . '-' . $slug . '-' . $random . '.goglobia.app';
        
        if ($db->has('stays_site', ['stay_id' => $stayId])) {
            $db->update('stays_site', ['temp_domain' => $tempDomain, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
        } else {
            $db->insert('stays_site', ['stay_id' => $stayId, 'temp_domain' => $tempDomain, 'domain_status' => 'none', 'created_at' => date('Y-m-d H:i:s')]);
        }
        
        echo json_encode(['ok' => true, 'domain' => $tempDomain]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
