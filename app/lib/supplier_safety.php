<?php
// FILE: app/lib/supplier_safety.php
// Packages (#17) + Incident/Visitor/Document registers (#52-55, the buildable
// safety & compliance parts — smart-locks/IoT is a stubbed external edge elsewhere).
// Per-property; caller authorizes the stay. NOT runtime-verified — logic + php -l.

// --- PACKAGES (#17) ---------------------------------------------------------
if (!function_exists('package_add')) {
    function package_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_packages', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 191),
                'description' => substr(trim((string) ($d['description'] ?? '')), 0, 500) ?: null,
                'inclusions' => substr(trim((string) ($d['inclusions'] ?? '')), 0, 1000) ?: null,
                'price' => max(0, round((float) ($d['price'] ?? 0), 2)),
                'nights' => ($d['nights'] ?? '') !== '' ? max(0, (int) $d['nights']) : null,
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('package_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('package_set_active')) {
    function package_set_active($db, int $id, int $stayId, bool $active): bool
    {
        try {
            if (!$db->has('stays_packages', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_packages', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('package_set_active: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('package_list')) {
    function package_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_packages', '*', ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('package_list: ' . $e->getMessage()); return []; }
    }
}

// --- INCIDENTS (#52-55) -----------------------------------------------------
if (!function_exists('incident_add')) {
    function incident_add($db, int $stayId, array $d): int
    {
        $summary = trim((string) ($d['summary'] ?? ''));
        if ($stayId <= 0 || $summary === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind = in_array(($d['kind'] ?? ''), ['safety','security','medical','damage','other'], true) ? $d['kind'] : 'other';
            $sev = in_array(($d['severity'] ?? ''), ['low','medium','high'], true) ? $d['severity'] : 'low';
            $occ = trim((string) ($d['occurred_at'] ?? ''));
            $db->insert('stays_incidents', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId, 'kind' => $kind,
                'occurred_at' => $occ !== '' ? $occ : null,
                'location' => substr(trim((string) ($d['location'] ?? '')), 0, 120) ?: null,
                'summary' => substr($summary, 0, 191),
                'detail' => trim((string) ($d['detail'] ?? '')) ?: null,
                'severity' => $sev, 'status' => 'open',
                'reported_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('incident_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('incident_set_status')) {
    function incident_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!in_array($status, ['open','investigating','closed'], true)) { return false; }
        try {
            if (!$db->has('stays_incidents', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_incidents', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('incident_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('incident_list')) {
    function incident_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_incidents', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('incident_list: ' . $e->getMessage()); return []; }
    }
}

// --- VISITORS (#52-55) ------------------------------------------------------
if (!function_exists('visitor_add')) {
    function visitor_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['visitor_name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_visitors', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'visitor_name' => substr($name, 0, 120),
                'visiting' => substr(trim((string) ($d['visiting'] ?? '')), 0, 120) ?: null,
                'purpose' => substr(trim((string) ($d['purpose'] ?? '')), 0, 120) ?: null,
                'checked_in_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('visitor_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('visitor_checkout')) {
    function visitor_checkout($db, int $id, int $stayId): bool
    {
        try {
            $v = $db->get('stays_visitors', ['id', 'checked_out_at'], ['id' => $id, 'stay_id' => $stayId]);
            if (!$v || $v['checked_out_at'] !== null) { return false; }
            $db->update('stays_visitors', ['checked_out_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('visitor_checkout: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('visitor_list')) {
    function visitor_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_visitors', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('visitor_list: ' . $e->getMessage()); return []; }
    }
}

// --- DOCUMENTS (#52-55) -----------------------------------------------------
if (!function_exists('document_add')) {
    function document_add($db, int $stayId, array $d): int
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($stayId <= 0 || $title === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $type = in_array(($d['doc_type'] ?? ''), ['licence','insurance','certificate','contract','other'], true) ? $d['doc_type'] : 'other';
            $exp = trim((string) ($d['expires_on'] ?? ''));
            $db->insert('stays_documents', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'title' => substr($title, 0, 191), 'doc_type' => $type,
                'reference' => substr(trim((string) ($d['reference'] ?? '')), 0, 120) ?: null,
                'expires_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null,
                'notes' => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('document_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('document_list')) {
    function document_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_documents', '*', ['stay_id' => $stayIds, 'ORDER' => ['expires_on' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('document_list: ' . $e->getMessage()); return []; }
    }
}
