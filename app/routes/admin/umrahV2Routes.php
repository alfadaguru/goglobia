<?php
// app/routes/admin/umrahV2Routes.php
// UMRAH REDESIGN — admin (docs/UMRAH-PHASE1-BUILD-PLAN.md Step 8). Manage the
// new departure/tier model without a code release: departures dashboard,
// create/clone/bulk-create, set price/promo/capacity, publish/close, bookings +
// receivables. Distinct path prefix (admin/umrah-manager) so it does NOT collide
// with the legacy admin/umrah package CRUD. ADMIN_AUTH + CSRF on every mutation.
@$SECURE or die('Access Denied!');

if (!function_exists('umrahV2AdminJson')) {
    function umrahV2AdminJson($p, int $code = 200): void
    {
        if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json'); }
        echo json_encode($p, JSON_UNESCAPED_SLASHES); exit;
    }
}
if (!function_exists('umrahV2AdminCsrfOk')) {
    function umrahV2AdminCsrfOk(): bool { return class_exists('CSRF') && CSRF::validateToken($_POST['csrf_token'] ?? ''); }
}

// ---- DASHBOARD PAGE: GET admin/umrah-manager ----------------------------
$router->get(admin.'/umrah-manager', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    $departures = $db->select('umrah_departures', '*', ['ORDER' => ['departure_date' => 'ASC']]) ?: [];
    // Enrich each departure with tier price + load factor.
    $rows = [];
    foreach ($departures as $d) {
        $dt = $db->get('umrah_departure_tiers', '*', ['departure_id' => $d['id'], 'ORDER' => ['id' => 'ASC']]);
        $cap = ($dt && function_exists('umrah_capacity_for')) ? umrah_capacity_for($db, (int) $dt['id']) : ['capacity' => (int) $d['capacity'], 'confirmed' => 0, 'active_holds' => 0, 'remaining' => (int) $d['capacity']];
        $confirmedPax = (int) $db->sum('umrah_bookings', 'pax', ['departure_id' => $d['id'], 'booking_status' => ['confirmed', 'completed']]);
        $revenue = (float) $db->sum('umrah_bookings', 'total_price', ['departure_id' => $d['id'], 'booking_status' => ['confirmed', 'completed']]);
        $collected = (float) $db->sum('umrah_bookings', 'amount_paid', ['departure_id' => $d['id']]);
        $rows[] = [
            'd' => $d,
            'dt' => $dt,
            'cap' => $cap,
            'confirmed_pax' => $confirmedPax,
            'revenue' => $revenue,
            'collected' => $collected,
        ];
    }
    $templates = $db->select('umrah_package_templates', ['id', 'code', 'name', 'slug'], ['status' => 1]) ?: [];
    $tiers = $db->select('umrah_tiers', ['id', 'code', 'public_label'], ['status' => 1, 'ORDER' => ['sort_order' => 'ASC']]) ?: [];

    // Receivables: due in 7 days + overdue.
    $now = date('Y-m-d H:i:s');
    $in7 = date('Y-m-d H:i:s', time() + 7 * 86400);
    $dueSoon = (float) $db->sum('umrah_installments', 'amount', ['status' => 'pending', 'due_at[<>]' => [$now, $in7]]);
    $overdue = (float) $db->sum('umrah_installments', 'amount', ['status' => ['pending', 'overdue'], 'due_at[<]' => $now]);

    $title = 'Umrah Manager'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/umrah/v2/manager.php';
    require_once views . 'includes/footer.php';
});

// ---- CREATE DEPARTURE: POST admin/umrah-manager/departures/create -------
$router->post(admin.'/umrah-manager/departures/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }

    $templateId = (int) ($_POST['template_id'] ?? 0);
    $depDate = trim($_POST['departure_date'] ?? '');
    $retDate = trim($_POST['return_date'] ?? '');
    $capacity = (int) ($_POST['capacity'] ?? 50);
    $tierId = (int) ($_POST['tier_id'] ?? 0);
    $regular = (float) ($_POST['regular_price'] ?? 0);
    $promo = (float) ($_POST['promo_price'] ?? 0);
    if ($templateId <= 0 || $depDate === '') { umrahV2AdminJson(['success' => false, 'message' => 'Template and departure date are required']); }

    $res = umrahV2CreateDeparture($db, $templateId, $depDate, $retDate, $capacity, $tierId, $regular, $promo);
    umrahV2AdminJson($res, $res['success'] ? 200 : 422);
});

// ---- BULK CREATE 12th/28th: POST admin/umrah-manager/departures/bulk ----
$router->post(admin.'/umrah-manager/departures/bulk', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $months = $_POST['months'] ?? ''; // comma-separated YYYY-MM
    $capacity = (int) ($_POST['capacity'] ?? 50);
    $tierId = (int) ($_POST['tier_id'] ?? 0);
    $regular = (float) ($_POST['regular_price'] ?? 2800000);
    $promo = (float) ($_POST['promo_price'] ?? 2490000);
    $durationDays = (int) ($_POST['duration_days'] ?? 14);
    if ($templateId <= 0 || $months === '') { umrahV2AdminJson(['success' => false, 'message' => 'Template and months required']); }

    $created = 0; $errors = [];
    foreach (array_filter(array_map('trim', explode(',', $months))) as $ym) {
        foreach (['12', '28'] as $day) {
            $dep = $ym . '-' . $day;
            if (!strtotime($dep)) { $errors[] = "Invalid month $ym"; continue; }
            $ret = date('Y-m-d', strtotime($dep . ' +' . $durationDays . ' days'));
            $r = umrahV2CreateDeparture($db, $templateId, $dep, $ret, $capacity, $tierId, $regular, $promo);
            if (!empty($r['success'])) { $created++; } else { $errors[] = $r['message'] ?? 'error'; }
        }
    }
    umrahV2AdminJson(['success' => true, 'created' => $created, 'errors' => $errors]);
});

// ---- CLONE DEPARTURE: POST admin/umrah-manager/departures/clone ---------
$router->post(admin.'/umrah-manager/departures/clone', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $srcId = (int) ($_POST['departure_id'] ?? 0);
    $newDate = trim($_POST['departure_date'] ?? '');
    if ($srcId <= 0 || $newDate === '') { umrahV2AdminJson(['success' => false, 'message' => 'Source departure and new date required']); }
    $src = $db->get('umrah_departures', '*', ['id' => $srcId]);
    if (!$src) { umrahV2AdminJson(['success' => false, 'message' => 'Source not found']); }
    $srcDt = $db->get('umrah_departure_tiers', '*', ['departure_id' => $srcId, 'ORDER' => ['id' => 'ASC']]);
    $durationDays = $src['return_date'] ? max(1, (int) round((strtotime($src['return_date']) - strtotime($src['departure_date'])) / 86400)) : 14;
    $ret = date('Y-m-d', strtotime($newDate . ' +' . $durationDays . ' days'));
    $r = umrahV2CreateDeparture($db, (int) $src['template_id'], $newDate, $ret, (int) $src['capacity'],
        $srcDt ? (int) $srcDt['tier_id'] : 0, $srcDt ? (float) $srcDt['regular_price'] : 0, $srcDt ? (float) $srcDt['promo_price'] : 0);
    umrahV2AdminJson($r, $r['success'] ? 200 : 422);
});

// ---- PUBLISH / CLOSE / DRAFT: POST admin/umrah-manager/departures/status
$router->post(admin.'/umrah-manager/departures/status', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $id = (int) ($_POST['departure_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if ($id <= 0 || !in_array($status, ['draft', 'published', 'closed'], true)) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid request']); }

    // Publish validation: require a priced, active-able standard tier.
    if ($status === 'published') {
        $dt = $db->get('umrah_departure_tiers', '*', ['departure_id' => $id]);
        $priced = $dt && function_exists('umrah_price_resolve') ? umrah_price_resolve($db, $dt) : ['unit' => 0];
        if (!$dt || $priced['unit'] <= 0) {
            umrahV2AdminJson(['success' => false, 'message' => 'Cannot publish: the departure has no priced tier. Set a price first.']);
        }
        // Activate the tier when the departure is published.
        $db->update('umrah_departure_tiers', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], ['departure_id' => $id]);
    }
    $db->update('umrah_departures', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_departure', (string) $id, 'status_' . $status, null, ['status' => $status]); }
    umrahV2AdminJson(['success' => true, 'message' => 'Departure ' . $status]);
});

// ---- SET PRICE / CAPACITY: POST admin/umrah-manager/departures/pricing --
$router->post(admin.'/umrah-manager/departures/pricing', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $id = (int) ($_POST['departure_id'] ?? 0);
    $capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : null;
    $regular = isset($_POST['regular_price']) ? (float) $_POST['regular_price'] : null;
    $promo = isset($_POST['promo_price']) ? (float) $_POST['promo_price'] : null;
    if ($id <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Departure required']); }

    if ($capacity !== null) {
        // Guard: do not drop capacity below confirmed pax.
        $confirmed = (int) $db->sum('umrah_bookings', 'pax', ['departure_id' => $id, 'booking_status' => ['confirmed', 'completed']]);
        if ($capacity < $confirmed) { umrahV2AdminJson(['success' => false, 'message' => "Capacity cannot be below confirmed pax ($confirmed)."]); }
        $db->update('umrah_departures', ['capacity' => $capacity, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }
    $dtUpdate = [];
    if ($regular !== null) { $dtUpdate['regular_price'] = $regular; }
    if ($promo !== null) { $dtUpdate['promo_price'] = $promo; $dtUpdate['promo_active'] = $promo > 0 ? 1 : 0; }
    if ($capacity !== null) { $dtUpdate['tier_capacity'] = $capacity; }
    if ($dtUpdate) {
        $dtUpdate['updated_at'] = date('Y-m-d H:i:s');
        $db->update('umrah_departure_tiers', $dtUpdate, ['departure_id' => $id]);
    }
    if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_departure', (string) $id, 'pricing_update', null, ['capacity' => $capacity, 'regular' => $regular, 'promo' => $promo]); }
    umrahV2AdminJson(['success' => true, 'message' => 'Updated']);
});

// ---- BOOKINGS LIST: GET admin/umrah-manager/bookings -------------------
$router->get(admin.'/umrah-manager/bookings', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $bookings = $db->select('umrah_bookings', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
    $title = 'Umrah Bookings'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/umrah/v2/bookings.php';
    require_once views . 'includes/footer.php';
});

// ---- shared: create a departure + its departure-tier row ---------------
if (!function_exists('umrahV2CreateDeparture')) {
    function umrahV2CreateDeparture($db, int $templateId, string $depDate, string $retDate, int $capacity, int $tierId, float $regular, float $promo): array
    {
        $tpl = $db->get('umrah_package_templates', ['id', 'code'], ['id' => $templateId]);
        if (!$tpl) { return ['success' => false, 'message' => 'Template not found']; }
        if ($tierId <= 0) {
            $std = $db->get('umrah_tiers', ['id'], ['code' => 'standard']);
            $tierId = $std ? (int) $std['id'] : 0;
        }
        if ($retDate === '' && strtotime($depDate)) { $retDate = date('Y-m-d', strtotime($depDate . ' +14 days')); }
        $tierCode = $db->get('umrah_tiers', ['code'], ['id' => $tierId])['code'] ?? 'std';
        $code = 'UMR-' . date('Ymd', strtotime($depDate)) . '-' . strtoupper(substr($tierCode, 0, 3));
        // Avoid duplicate code (same date+tier).
        if ($db->get('umrah_departures', 'id', ['code' => $code])) {
            return ['success' => false, 'message' => "Departure already exists for {$depDate}"];
        }
        $now = date('Y-m-d H:i:s');
        $db->insert('umrah_departures', [
            'template_id' => $templateId, 'code' => $code,
            'departure_date' => $depDate, 'return_date' => $retDate,
            'month_bucket' => date('F Y', strtotime($depDate)),
            'capacity' => $capacity, 'low_stock_threshold' => 10,
            'display_inventory_count' => 0, 'status' => 'draft', 'created_at' => $now,
        ]);
        $depId = (int) $db->id();
        if ($tierId > 0) {
            $db->insert('umrah_departure_tiers', [
                'departure_id' => $depId, 'tier_id' => $tierId,
                'regular_price' => $regular ?: null, 'promo_price' => $promo ?: null,
                'promo_active' => $promo > 0 ? 1 : 0, 'currency' => 'NGN',
                'tier_capacity' => $capacity, 'booking_mode' => 'instant', 'status' => 'draft',
                'created_at' => $now,
            ]);
        }
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_departure', (string) $depId, 'created', null, ['code' => $code, 'date' => $depDate]); }
        return ['success' => true, 'departure_id' => $depId, 'code' => $code];
    }
}
