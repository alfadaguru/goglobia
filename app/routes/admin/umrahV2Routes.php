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
if (!function_exists('umrahV2AdminDeleteLocalImage')) {
    /**
     * Delete an uploaded departure image FILE from disk when it lives inside our
     * own uploads/umrah/departures/ folder. Ignores external URLs (e.g. seeded
     * stock links) and anything outside that folder — never touches arbitrary
     * paths. Best-effort; a missing file is not an error.
     */
    function umrahV2AdminDeleteLocalImage(string $url): void
    {
        $url = trim($url);
        if ($url === '') { return; }
        $needle = 'uploads/umrah/departures/';
        $pos = strpos($url, $needle);
        if ($pos === false) { return; } // external / not ours
        $basename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        // Whitelist the filename shape we generate to avoid any traversal.
        if (!preg_match('/^dep-\d+-(hero|gallery)-[a-f0-9]{8}\.png$/', $basename)) { return; }
        $path = rtrim(uploads, '/') . '/umrah/departures/' . $basename;
        if (is_file($path)) { @unlink($path); }
    }
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

// ---- IMAGES: upload a hero or gallery image for a departure -------------
// POST admin/umrah-manager/departures/images  (multipart: departure_id, slot, image)
// slot = 'hero' | 'gallery'. Stores under uploads/umrah/departures/ via the
// secure handleFileUpload (MIME + size + php-injection checked, converted to
// PNG). Updates umrah_departures.hero_image / gallery (JSON array).
$router->post(admin.'/umrah-manager/departures/images', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $depId = (int) ($_POST['departure_id'] ?? 0);
    $slot  = ($_POST['slot'] ?? 'gallery') === 'hero' ? 'hero' : 'gallery';
    $dep = $depId > 0 ? $db->get('umrah_departures', ['id', 'hero_image', 'gallery'], ['id' => $depId]) : null;
    if (!$dep) { umrahV2AdminJson(['success' => false, 'message' => 'Departure not found'], 404); }
    if (!isset($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        umrahV2AdminJson(['success' => false, 'message' => 'No image uploaded'], 422);
    }

    $dir = rtrim(uploads, '/') . '/umrah/departures/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    // Unique filename (handleFileUpload converts to PNG). random_bytes → no clobber.
    $fname = 'dep-' . $depId . '-' . $slot . '-' . bin2hex(random_bytes(4)) . '.png';
    $target = $dir . $fname;
    $res = handleFileUpload('image', $target, ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'], 6 * 1024 * 1024, true);
    if (empty($res['success'])) { umrahV2AdminJson(['success' => false, 'message' => $res['error'] ?? 'Upload failed'], 422); }

    // Public URL (relative to app root). root already ends with '/'.
    $url = root . 'uploads/umrah/departures/' . $fname;

    if ($slot === 'hero') {
        // Replace hero; remove the previous hero FILE if it lived in our folder.
        $old = (string) ($dep['hero_image'] ?? '');
        umrahV2AdminDeleteLocalImage($old);
        $db->update('umrah_departures', ['hero_image' => $url, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $depId]);
    } else {
        $gallery = json_decode((string) ($dep['gallery'] ?? ''), true);
        if (!is_array($gallery)) { $gallery = []; }
        $gallery[] = $url;
        $db->update('umrah_departures', ['gallery' => json_encode(array_values($gallery)), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $depId]);
    }
    umrahV2AdminJson(['success' => true, 'url' => $url, 'slot' => $slot]);
});

// ---- IMAGES: delete a departure image (hero or one gallery entry) -------
$router->post(admin.'/umrah-manager/departures/images/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $depId = (int) ($_POST['departure_id'] ?? 0);
    $slot  = ($_POST['slot'] ?? 'gallery') === 'hero' ? 'hero' : 'gallery';
    $url   = trim((string) ($_POST['url'] ?? ''));
    $dep = $depId > 0 ? $db->get('umrah_departures', ['id', 'hero_image', 'gallery'], ['id' => $depId]) : null;
    if (!$dep) { umrahV2AdminJson(['success' => false, 'message' => 'Departure not found'], 404); }

    if ($slot === 'hero') {
        umrahV2AdminDeleteLocalImage((string) ($dep['hero_image'] ?? ''));
        $db->update('umrah_departures', ['hero_image' => '', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $depId]);
    } else {
        $gallery = json_decode((string) ($dep['gallery'] ?? ''), true);
        if (!is_array($gallery)) { $gallery = []; }
        $gallery = array_values(array_filter($gallery, fn($g) => (string) $g !== $url));
        umrahV2AdminDeleteLocalImage($url);
        $db->update('umrah_departures', ['gallery' => json_encode($gallery), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $depId]);
    }
    umrahV2AdminJson(['success' => true]);
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
    // Audit M4: reject negative money / capacity.
    if (($capacity !== null && $capacity < 0) || ($regular !== null && $regular < 0) || ($promo !== null && $promo < 0)) {
        umrahV2AdminJson(['success' => false, 'message' => 'Prices and capacity cannot be negative']);
    }

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

// ---- SET ORIGIN CITY: POST admin/umrah-manager/departures/origin --------
$router->post(admin.'/umrah-manager/departures/origin', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $id = (int) ($_POST['departure_id'] ?? 0);
    $city = trim((string) ($_POST['origin_city'] ?? ''));
    if ($id <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Departure required']); }
    $db->update('umrah_departures', ['origin_city' => ($city !== '' ? $city : null), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_departure', (string) $id, 'origin_city', null, ['origin_city' => $city]); }
    umrahV2AdminJson(['success' => true, 'message' => 'Departure city updated']);
});

// ---- PER-TIER PRICING (B2C + B2B): POST .../departures/tier-pricing ------
// Update ONE departure-tier: B2C regular/promo, B2B net/promo, capacity, status.
$router->post(admin.'/umrah-manager/departures/tier-pricing', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $dtId = (int) ($_POST['departure_tier_id'] ?? 0);
    if ($dtId <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Departure-tier required']); }
    $dt = $db->get('umrah_departure_tiers', '*', ['id' => $dtId]);
    if (!$dt) { umrahV2AdminJson(['success' => false, 'message' => 'Departure-tier not found']); }

    $u = ['updated_at' => date('Y-m-d H:i:s')];
    // Nullable price fields: empty string clears (NULL); numeric sets.
    $priceField = function (string $key) {
        if (!isset($_POST[$key])) { return '__skip__'; }
        $v = trim((string) $_POST[$key]);
        return ($v === '') ? null : (float) $v;
    };
    foreach (['regular_price', 'promo_price', 'b2b_net_price', 'b2b_promo_price'] as $f) {
        $val = $priceField($f);
        if ($val !== '__skip__') {
            if ($val !== null && $val < 0) { // audit M4: no negative money
                umrahV2AdminJson(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $f)) . ' cannot be negative']);
            }
            $u[$f] = $val;
        }
    }
    if (array_key_exists('promo_price', $u)) { $u['promo_active'] = ($u['promo_price'] !== null && $u['promo_price'] > 0) ? 1 : 0; }
    if (isset($_POST['tier_capacity']) && trim((string) $_POST['tier_capacity']) !== '') {
        $cap = (int) $_POST['tier_capacity'];
        $confirmed = (int) $db->sum('umrah_bookings', 'pax', ['departure_tier_id' => $dtId, 'booking_status' => ['confirmed', 'completed']]);
        if ($cap < $confirmed) { umrahV2AdminJson(['success' => false, 'message' => "Tier capacity cannot be below confirmed pax ($confirmed)."]); }
        $u['tier_capacity'] = $cap;
    }
    if (isset($_POST['status']) && in_array($_POST['status'], ['draft', 'active', 'hidden', 'sold_out'], true)) {
        // Audit (low): don't hide/draft a tier that still has confirmed pilgrims
        // (it would vanish from resolve/inventory while bookings reference it).
        if (in_array($_POST['status'], ['draft', 'hidden'], true)) {
            $confirmedOnTier = (int) $db->sum('umrah_bookings', 'pax', ['departure_tier_id' => $dtId, 'booking_status' => ['confirmed', 'completed']]);
            if ($confirmedOnTier > 0) {
                umrahV2AdminJson(['success' => false, 'message' => "Cannot set this tier to '{$_POST['status']}' — it has {$confirmedOnTier} confirmed pilgrim(s). Use 'sold_out' to stop new sales."]);
            }
        }
        $u['status'] = $_POST['status'];
    }
    $db->update('umrah_departure_tiers', $u, ['id' => $dtId]);
    if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_departure_tier', (string) $dtId, 'tier_pricing_update', null, $u); }
    umrahV2AdminJson(['success' => true, 'message' => 'Tier pricing updated']);
});

// ---- QUOTE-REQUEST INBOX: GET admin/umrah-manager/quote-requests --------
$router->get(admin.'/umrah-manager/quote-requests', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $status = trim((string) ($_GET['status'] ?? ''));
    $where = ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 300];
    if (in_array($status, ['new', 'in_review', 'quoted', 'converted', 'closed'], true)) { $where['status'] = $status; }
    $requests = $db->select('umrah_quote_requests', '*', $where) ?: [];
    $counts = [];
    foreach (['new', 'in_review', 'quoted', 'converted', 'closed'] as $s) {
        $counts[$s] = (int) $db->count('umrah_quote_requests', ['status' => $s]);
    }
    $title = 'Umrah Quote Requests'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/umrah/v2/quote-requests.php';
    require_once views . 'includes/footer.php';
});

// ---- QUOTE-REQUEST RESPOND: POST admin/umrah-manager/quote-requests/respond
$router->post(admin.'/umrah-manager/quote-requests/respond', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $id = (int) ($_POST['id'] ?? 0);
    $status = trim((string) ($_POST['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['new', 'in_review', 'quoted', 'converted', 'closed'], true)) {
        umrahV2AdminJson(['success' => false, 'message' => 'Invalid request']);
    }
    $req = $db->get('umrah_quote_requests', '*', ['id' => $id]);
    if (!$req) { umrahV2AdminJson(['success' => false, 'message' => 'Request not found']); }
    $u = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if (isset($_POST['staff_note'])) { $u['staff_note'] = trim((string) $_POST['staff_note']) ?: null; }
    if (isset($_POST['quote_amount']) && trim((string) $_POST['quote_amount']) !== '') {
        $u['quote_amount'] = (float) $_POST['quote_amount'];
        $u['quote_currency'] = trim((string) ($_POST['quote_currency'] ?? 'NGN')) ?: 'NGN';
    }
    if ($status === 'quoted' && empty($req['quoted_at'])) { $u['quoted_at'] = date('Y-m-d H:i:s'); }
    $u['handled_by'] = (string) ($_SESSION['user_id'] ?? '') ?: null;
    $db->update('umrah_quote_requests', $u, ['id' => $id]);
    if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_quote_request', (string) $req['request_ref'], 'respond_' . $status, null, $u); }
    umrahV2AdminJson(['success' => true, 'message' => 'Request updated']);
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

// ---- OPERATIONS PAGE: GET admin/umrah-manager/operations/{departureId} --
// Visa / ticket / rooming batch view for a departure's travellers.
$router->get(admin.'/umrah-manager/operations/([0-9]+)', function ($departureId) use ($SECURE, $db) {
    ADMIN_AUTH();
    $departure = $db->get('umrah_departures', '*', ['id' => (int) $departureId]);
    if (!$departure) { header('Location: ' . root . 'admin/umrah-manager'); exit; }
    // All travellers on confirmed/held bookings for this departure.
    $bookings = $db->select('umrah_bookings', ['id', 'booking_ref'], ['departure_id' => (int) $departureId]) ?: [];
    $bIds = array_map(fn($b) => (int) $b['id'], $bookings);
    $refById = []; foreach ($bookings as $b) { $refById[(int) $b['id']] = $b['booking_ref']; }
    $travellers = $bIds ? ($db->select('umrah_booking_travellers', '*', ['umrah_booking_id' => $bIds, 'ORDER' => ['id' => 'ASC']]) ?: []) : [];
    // Documents per traveller (audit H6) so the ops view can list + open them.
    $tIds = array_map(fn($t) => (int) $t['id'], $travellers);
    $documentsByTraveller = [];
    if ($tIds) {
        $docs = $db->select('umrah_documents', ['id', 'traveller_id', 'doc_type', 'original_name', 'mime', 'verify_status', 'created_at'], ['traveller_id' => $tIds, 'ORDER' => ['id' => 'DESC']]) ?: [];
        foreach ($docs as $d) { $documentsByTraveller[(int) $d['traveller_id']][] = $d; }
    }
    $hotels = $db->select('umrah_hotels', '*', ['status' => 1]) ?: [];
    $allocations = $db->select('umrah_hotel_allocations', '*', ['departure_id' => (int) $departureId]) ?: [];
    $transport = $db->select('umrah_transport_allocations', '*', ['departure_id' => (int) $departureId]) ?: [];

    $title = 'Umrah Operations'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/umrah/v2/operations.php';
    require_once views . 'includes/footer.php';
});

// ---- SET TRAVELLER STATUS: POST .../operations/traveller-status ---------
$router->post(admin.'/umrah-manager/operations/traveller-status', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $tid = (int) ($_POST['traveller_id'] ?? 0);
    $domain = trim($_POST['domain'] ?? '');
    $value = trim($_POST['value'] ?? '');
    if ($tid <= 0 || !function_exists('umrah_traveller_set_status')) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid request']); }
    // Validate the status transition FIRST (audit low): only persist PNR/eticket
    // once the transition is accepted, so a rejected transition can't leave
    // stale PNR data on the traveller.
    $r = umrah_traveller_set_status($db, $tid, $domain, $value);
    if (!empty($r['ok']) && $domain === 'ticket' && isset($_POST['pnr'])) {
        $db->update('umrah_booking_travellers', ['pnr' => trim($_POST['pnr']), 'eticket' => trim($_POST['eticket'] ?? '')], ['id' => $tid]);
    }
    umrahV2AdminJson($r, $r['ok'] ? 200 : 422);
});

// ---- VERIFY DOCUMENT: POST .../operations/verify-document --------------
$router->post(admin.'/umrah-manager/operations/verify-document', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $docId = (int) ($_POST['document_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    // Map the UI verbs to the service's canonical enum values.
    $decisionMap = ['verify' => 'verified', 'approve' => 'verified', 'verified' => 'verified', 'reject' => 'rejected', 'rejected' => 'rejected'];
    $decision = $decisionMap[$decision] ?? $decision;
    if ($docId <= 0 || !function_exists('umrah_document_verify')) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid request']); }
    $r = umrah_document_verify($db, $docId, $decision, $_SESSION['user_id'] ?? 'admin', $_POST['note'] ?? null);
    umrahV2AdminJson($r, !empty($r['ok']) ? 200 : 422);
});

// ---- SERVE DOCUMENT (audit H6): GET admin/umrah-manager/document/{id} ---
// Streams an uploaded passport/ID to an authenticated admin. The files live
// under uploads/umrah/documents/ behind a Deny-all .htaccess; this is the ONLY
// authorised read path. Validates the resolved path stays inside that dir
// (no traversal) and serves inline with the stored MIME.
$router->get(admin.'/umrah-manager/document/([0-9]+)', function ($docId) use ($SECURE, $db) {
    ADMIN_AUTH();
    $doc = $db->get('umrah_documents', ['file_path', 'mime', 'original_name'], ['id' => (int) $docId]);
    if (!$doc || empty($doc['file_path'])) { http_response_code(404); die('Document not found'); }

    $root = defined('uploads') ? dirname(rtrim(uploads, '/')) : dirname(__DIR__, 3);
    $docsDir = realpath($root . '/uploads/umrah/documents');
    $full = realpath($root . '/' . ltrim((string) $doc['file_path'], '/'));
    // Path-traversal guard: the resolved file MUST sit inside the umrah docs dir.
    if ($docsDir === false || $full === false || strpos($full, $docsDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        http_response_code(404); die('Document not available');
    }
    $mime = (string) ($doc['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) { $mime = 'application/octet-stream'; }
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($doc['original_name'] ?? ('document-' . (int) $docId)));
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
});

// ---- HOTEL / TRANSPORT ALLOCATION: POST .../operations/allocate --------
$router->post(admin.'/umrah-manager/operations/allocate', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $kind = trim($_POST['kind'] ?? '');
    $depId = (int) ($_POST['departure_id'] ?? 0);
    if ($depId <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Departure required']); }
    $now = date('Y-m-d H:i:s');
    if ($kind === 'hotel') {
        // Create hotel if a new name is given, else use hotel_id.
        $hotelId = (int) ($_POST['hotel_id'] ?? 0);
        if ($hotelId <= 0 && trim($_POST['hotel_name'] ?? '') !== '') {
            $db->insert('umrah_hotels', ['name' => trim($_POST['hotel_name']), 'city' => in_array($_POST['city'] ?? '', ['makkah','madinah','other'], true) ? $_POST['city'] : 'makkah', 'supplier' => $_POST['supplier'] ?? null, 'category' => $_POST['category'] ?? null, 'status' => 1, 'created_at' => $now]);
            $hotelId = (int) $db->id();
        }
        if ($hotelId <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Hotel required']); }
        $db->insert('umrah_hotel_allocations', [
            'departure_id' => $depId, 'hotel_id' => $hotelId,
            'city' => in_array($_POST['city'] ?? '', ['makkah','madinah','other'], true) ? $_POST['city'] : 'makkah',
            'nights' => (int) ($_POST['nights'] ?? 0), 'rooms' => (int) ($_POST['rooms'] ?? 0), 'beds' => (int) ($_POST['beds'] ?? 0),
            'room_type' => $_POST['room_type'] ?? null, 'cost' => isset($_POST['cost']) ? (float) $_POST['cost'] : null,
            'confirmation_ref' => $_POST['confirmation_ref'] ?? null, 'status' => 'planned', 'created_at' => $now,
        ]);
        umrahV2AdminJson(['success' => true, 'message' => 'Hotel allocated']);
    } elseif ($kind === 'transport') {
        $db->insert('umrah_transport_allocations', [
            'departure_id' => $depId, 'route' => trim($_POST['route'] ?? 'Transfer'),
            'vehicle_type' => $_POST['vehicle_type'] ?? null, 'vehicle_capacity' => isset($_POST['vehicle_capacity']) ? (int) $_POST['vehicle_capacity'] : null,
            'supplier' => $_POST['supplier'] ?? null, 'cost' => isset($_POST['cost']) ? (float) $_POST['cost'] : null,
            'group_number' => $_POST['group_number'] ?? null, 'status' => 'planned', 'created_at' => $now,
        ]);
        umrahV2AdminJson(['success' => true, 'message' => 'Transport allocated']);
    }
    umrahV2AdminJson(['success' => false, 'message' => 'Unknown allocation kind']);
});

// ---- ROOM ASSIGN: POST .../operations/assign-room ----------------------
$router->post(admin.'/umrah-manager/operations/assign-room', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $allocId = (int) ($_POST['hotel_allocation_id'] ?? 0);
    $travellerId = (int) ($_POST['traveller_id'] ?? 0);
    $roomRef = trim($_POST['room_ref'] ?? '');
    if ($allocId <= 0 || $travellerId <= 0) { umrahV2AdminJson(['success' => false, 'message' => 'Allocation and traveller required']); }

    // Audit (low): reject a duplicate assignment of the SAME traveller (no
    // unique index on traveller_id) so a pilgrim can't end up in two rooms.
    $existing = $db->get('umrah_room_assignments', ['id', 'room_ref'], ['hotel_allocation_id' => $allocId, 'traveller_id' => $travellerId]);
    if ($existing) { umrahV2AdminJson(['success' => false, 'message' => 'This pilgrim is already assigned a room in this allocation']); }

    $newG = $db->get('umrah_booking_travellers', ['gender'], ['id' => $travellerId])['gender'] ?? null;
    if ($roomRef !== '') {
        $mates = $db->select('umrah_room_assignments', ['traveller_id'], ['hotel_allocation_id' => $allocId, 'room_ref' => $roomRef]) ?: [];
        // Audit (gender-null): require a known gender before placing into an
        // OCCUPIED room (can't verify parity otherwise).
        if ($mates && !$newG) {
            umrahV2AdminJson(['success' => false, 'message' => "Set this pilgrim's gender before assigning them to a shared room"]);
        }
        foreach ($mates as $m) {
            $g = $db->get('umrah_booking_travellers', ['gender'], ['id' => (int) $m['traveller_id']])['gender'] ?? null;
            // A mate with unknown gender is also unsafe to mix against.
            if (!$g) { umrahV2AdminJson(['success' => false, 'message' => 'Room has an occupant with unset gender — resolve it first']); }
            if ($newG && $newG !== $g) { umrahV2AdminJson(['success' => false, 'message' => 'Gender conflict: room already has a ' . $g . ' occupant']); }
        }
        // Audit (low): don't exceed the allocation's beds-per-room.
        $alloc = $db->get('umrah_hotel_allocations', ['beds'], ['id' => $allocId]);
        $beds = (int) ($alloc['beds'] ?? 0);
        if ($beds > 0) {
            $occupied = (int) $db->count('umrah_room_assignments', ['hotel_allocation_id' => $allocId, 'room_ref' => $roomRef]);
            if ($occupied >= $beds) { umrahV2AdminJson(['success' => false, 'message' => "Room is full ($beds bed(s))"]); }
        }
    }
    $db->insert('umrah_room_assignments', ['hotel_allocation_id' => $allocId, 'room_ref' => $roomRef ?: null, 'traveller_id' => $travellerId, 'state' => 'assigned', 'created_at' => date('Y-m-d H:i:s')]);
    $db->update('umrah_booking_travellers', ['rooming_status' => 'assigned', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $travellerId]);
    umrahV2AdminJson(['success' => true, 'message' => 'Room assigned']);
});

// ---- AGENT GROUPS ADMIN (Phase C): list + status/visa + Nusuk export ----
$router->get(admin.'/umrah-manager/groups', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $status = trim((string) ($_GET['status'] ?? ''));
    $where = ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 300];
    if (in_array($status, ['draft','pending','paid','submitted','processing','confirmed','cancelled'], true)) { $where['status'] = $status; }
    $groups = $db->select('umrah_groups', '*', $where) ?: [];
    // Attach agent name + departure label for the list.
    foreach ($groups as &$g) {
        $u = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $g['agent_user_id']]);
        $g['agent_name'] = $u ? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : $g['agent_user_id'];
        $dep = $db->get('umrah_departures', ['origin_city', 'departure_date'], ['id' => (int) $g['departure_id']]);
        $g['dep_label'] = $dep ? (($dep['origin_city'] ?? '') . ' ' . date('d M Y', strtotime($dep['departure_date']))) : '';
    }
    unset($g);
    $counts = [];
    foreach (['draft','pending','paid','submitted','processing','confirmed','cancelled'] as $s) { $counts[$s] = (int) $db->count('umrah_groups', ['status' => $s]); }
    $title = 'Umrah Groups'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/umrah/v2/groups.php';
    require_once views . 'includes/footer.php';
});

// Admin: drive a group's status + visa_status (full flexibility).
$router->post(admin.'/umrah-manager/groups/status', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!umrahV2AdminCsrfOk()) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid form submission']); }
    $gid = (int) ($_POST['group_id'] ?? 0);
    if ($gid <= 0 || !function_exists('umrah_group_set_status')) { umrahV2AdminJson(['success' => false, 'message' => 'Invalid request']); }
    $r = umrah_group_set_status($db, $gid, trim((string) ($_POST['status'] ?? '')), $_POST['visa_status'] ?? null);
    umrahV2AdminJson($r['ok'] ? ['success' => true, 'message' => 'Group updated'] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// Nusuk manifest export (CSV). Columns are admin-configurable via
// settings.umrah_nusuk_columns (comma-separated field keys); a sensible
// default is used when unset. Scope: ?group=ID (a group) or ?departure=ID
// (all travellers on that departure).
$router->get(admin.'/umrah-manager/manifest', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $groupId = (int) ($_GET['group'] ?? 0);
    $departureId = (int) ($_GET['departure'] ?? 0);

    // Resolve the traveller rows for the requested scope.
    $rows = [];
    if ($groupId > 0) {
        $g = $db->get('umrah_groups', ['group_ref', 'umrah_booking_id'], ['id' => $groupId]);
        if (!$g) { http_response_code(404); die('Group not found'); }
        $scopeName = $g['group_ref'];
        if (!empty($g['umrah_booking_id'])) {
            $rows = $db->select('umrah_booking_travellers', '*', ['umrah_booking_id' => (int) $g['umrah_booking_id'], 'ORDER' => ['id' => 'ASC']]) ?: [];
        } else {
            // Not yet materialized — export the staged members.
            $rows = $db->select('umrah_group_members', '*', ['group_id' => $groupId, 'ORDER' => ['id' => 'ASC']]) ?: [];
        }
    } elseif ($departureId > 0) {
        $bookings = $db->select('umrah_bookings', ['id'], ['departure_id' => $departureId]) ?: [];
        $bIds = array_map(fn($b) => (int) $b['id'], $bookings);
        $rows = $bIds ? ($db->select('umrah_booking_travellers', '*', ['umrah_booking_id' => $bIds, 'ORDER' => ['id' => 'ASC']]) ?: []) : [];
        $dep = $db->get('umrah_departures', ['code'], ['id' => $departureId]);
        $scopeName = $dep['code'] ?? ('departure-' . $departureId);
    } else {
        http_response_code(422); die('Specify ?group=ID or ?departure=ID');
    }

    // Column set (admin-configurable). Keys map to traveller/member fields.
    $default = ['passport_number', 'first_name', 'middle_name', 'last_name', 'gender', 'dob', 'nationality', 'passport_issue', 'passport_expiry', 'mobile', 'email'];
    $cfg = $db->get('settings', ['umrah_nusuk_columns']);
    $cols = $default;
    if ($cfg && !empty($cfg['umrah_nusuk_columns'])) {
        $parsed = array_values(array_filter(array_map('trim', explode(',', (string) $cfg['umrah_nusuk_columns']))));
        if ($parsed) { $cols = $parsed; }
    }
    $headers = [
        'passport_number' => 'Passport Number', 'first_name' => 'First Name', 'middle_name' => 'Middle Name',
        'last_name' => 'Last Name', 'gender' => 'Gender', 'dob' => 'Date of Birth', 'nationality' => 'Nationality',
        'passport_issue' => 'Passport Issue', 'passport_expiry' => 'Passport Expiry', 'mobile' => 'Mobile', 'email' => 'Email',
    ];

    // Stream CSV.
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nusuk-manifest-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $scopeName) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map(fn($c) => $headers[$c] ?? ucwords(str_replace('_', ' ', $c)), $cols));
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) { $line[] = (string) ($r[$c] ?? ''); }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
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
