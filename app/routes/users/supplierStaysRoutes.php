<?php
// FILE: app/routes/users/supplierStaysRoutes.php
// Owner-scoped stays management for suppliers — the self-service mirror of the
// admin stays CRUD (app/routes/admin/staysRoutes.php), with THREE hard rules on
// every write (see docs/supplier/ Phase-1 plan):
//   1. the owner user_id is FORCED to the acting supplier's owner (never $_POST);
//   2. every mutation is authorized by supplier_can($db,$module,$action,$stayId)
//      — ownership (stays.user_id === owner), plus role/scope once staff lands;
//   3. property creation is QUOTA-gated against supplier_services.max_listings.
// New supplier-created properties start listing_status='draft' (NOT live) and
// go through per-listing approval (increment 7) before they can sell.
//
// Auth: SUPPLIER_AUTH() gates the whole area to role=supplier (staff access is
// added in increment 5 via SUPPLIER_OR_STAFF_AUTH). Admins are allowed through
// supplier_can()'s bypass but normally use the admin screens.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_stays_owner')) {
    /** The acting supplier's owner user_id (string), or null if not a supplier. */
    function _supplier_stays_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null) { return null; }
        // Admin has no single owner; the supplier area is owner-centric, so an admin
        // hitting these routes is sent to the admin screens instead.
        if (!empty($ctx['is_admin'])) { return null; }
        return (string) $ctx['owner'];
    }
}

if (!function_exists('_supplier_stays_deny')) {
    /** Uniform deny: flash + redirect to the supplier stays list. */
    function _supplier_stays_deny(string $msg = 'You are not authorised to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'supplier/stays');
        exit;
    }
}

// ============================================================================
// LIST — GET /supplier/stays  (only the acting supplier's own properties)
// ============================================================================
$router->get('/supplier/stays', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    // Owner-scoped list — NEVER unfiltered. This is the "my hotels" view.
    $properties = $db->select('stays',
        ['id', 'name', 'location', 'stars', 'status', 'listing_status', 'img', 'created_at'],
        ['user_id' => $owner, 'ORDER' => ['id' => 'DESC']]
    ) ?: [];

    // Quota context for the "Add property" affordance.
    $quota = supplier_service_quota($db, $owner, 'stays');

    $title = 'My Hotels';
    $description = 'Manage your properties';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/list.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ADD FORM — GET /supplier/stays/add
// ============================================================================
$router->get('/supplier/stays/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    // Quota gate (soft, on the form): block the form if already at the cap.
    $quota = supplier_service_quota($db, $owner, 'stays');
    if (!$quota['approved']) {
        _supplier_stays_deny('Your stays service is not approved yet.');
    }
    if ($quota['max'] !== null && $quota['used'] >= $quota['max']) {
        _supplier_stays_deny('You have reached your approved limit of ' . (int) $quota['max'] . ' propert' . ($quota['max'] === 1 ? 'y' : 'ies') . '. Contact the administrator to increase it.');
    }

    $isEdit = false;
    $mode = 'add';
    $stay = null;
    $title = 'Add Property';
    $description = 'Create a new property';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/form.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ADD SUBMIT — POST /supplier/stays/add  (owner forced + quota enforced)
// ============================================================================
$router->post('/supplier/stays/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    if (!supplier_can($db, 'hotels', 'add')) {
        _supplier_stays_deny();
    }

    $name = trim($_POST['hotel_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $location === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Name and location are required.'];
        header('Location: ' . root . 'supplier/stays/add');
        exit;
    }

    // HARD QUOTA GATE — count owned properties inside the write path. (A locked
    // re-count like umrah_traveller_add is used once holds/transactions exist;
    // for a create this COUNT is sufficient and race windows only risk one extra.)
    $quota = supplier_service_quota($db, $owner, 'stays');
    if (!$quota['approved']) {
        _supplier_stays_deny('Your stays service is not approved yet.');
    }
    if ($quota['max'] !== null && $quota['used'] >= $quota['max']) {
        _supplier_stays_deny('You have reached your approved property limit.');
    }

    // Gallery upload — identical secure path as admin (secureUploadCheck).
    $images = [];
    if (isset($_FILES['hotel_images']) && !empty($_FILES['hotel_images']['name'][0])) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/hotels/gallery/';
        if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0755, true); }
        $files = $_FILES['hotel_images'];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { continue; }
            $chk = secureUploadCheck(
                ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
            );
            if (!$chk['ok']) { continue; }
            $fn = 'hotel_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
            if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $fn)) {
                @chmod($upload_dir . $fn, 0644);
                $images[] = ['url' => '/uploads/hotels/gallery/' . $fn, 'default' => $i === 0];
            }
        }
    }

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    $lat = trim($_POST['latitude'] ?? ''); $lng = trim($_POST['longitude'] ?? '');

    $data = [
        'user_id'         => $owner,                                   // FORCED to owner
        'name'            => $name,
        'desc'            => trim($_POST['description'] ?? '') ?: null,
        'slug'            => $slug,
        'location'        => $location,
        'location_coords' => ($lat !== '' && $lng !== '') ? ($lat . ',' . $lng) : null,
        'address'         => trim($_POST['address'] ?? ''),
        'stars'           => ($v = intval($_POST['stars'] ?? 0)) > 0 ? $v : null,
        'currency'        => trim($_POST['currency'] ?? 'USD'),
        'discount'        => ($v = intval($_POST['discount'] ?? 0)) > 0 ? $v : null,
        'email'           => trim($_POST['email'] ?? '') ?: null,
        'phone'           => trim($_POST['phone'] ?? '') ?: null,
        'website'         => trim($_POST['website'] ?? '') ?: null,
        'checkin_time'    => trim($_POST['checkin_time'] ?? '14:00:00'),
        'checkout_time'   => trim($_POST['checkout_time'] ?? '12:00:00'),
        'refundable'      => isset($_POST['refundable']) ? 1 : 0,
        'amenity_ids'     => trim($_POST['amenity_ids'] ?? '[]'),
        'cancellation_policy' => trim($_POST['cancellation_policy'] ?? '') ?: null,
        'img'             => !empty($images) ? json_encode($images) : null,
        // Supplier-created listings start OFFLINE + DRAFT: not live (status 0) and
        // pending per-listing approval (listing_status draft). Admin create used 1;
        // suppliers must be reviewed first.
        'status'          => 0,
        'listing_status'  => 'draft',
        'created_at'      => date('Y-m-d H:i:s'),
    ];

    try {
        $ok = $db->insert('stays', $data);
        $newId = $db->id();
        if ($ok && $newId) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Property created as a draft. Configure rooms & rates, then submit for approval.'];
            header('Location: ' . root . 'supplier/stays/edit/' . (int) $newId);
            exit;
        }
    } catch (\Throwable $e) {
        error_log('supplier stays add: ' . $e->getMessage());
    }
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not create the property.'];
    header('Location: ' . root . 'supplier/stays/add');
    exit;
});

// ============================================================================
// EDIT FORM — GET /supplier/stays/edit/{id}
// ============================================================================
$router->get('/supplier/stays/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    // OWNERSHIP gate — supplier_can resolves owner + verifies stays.user_id.
    if (!supplier_can($db, 'hotels', 'view', (int) $id)) {
        _supplier_stays_deny('That property is not yours.');
    }

    $stay = $db->get('stays', '*', ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }

    $isEdit = true;
    $mode = 'edit';
    $title = 'Edit Property';
    $description = 'Edit ' . ($stay['name'] ?? 'property');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/form.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// EDIT SUBMIT — POST /supplier/stays/edit/{id}
// ============================================================================
$router->post('/supplier/stays/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) {
        _supplier_stays_deny('That property is not yours.');
    }

    $name = trim($_POST['hotel_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $location === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Name and location are required.'];
        header('Location: ' . root . 'supplier/stays/edit/' . (int) $id);
        exit;
    }
    $lat = trim($_POST['latitude'] ?? ''); $lng = trim($_POST['longitude'] ?? '');

    // NOTE: user_id is NOT in the update set — ownership can never be reassigned
    // by a supplier. A material edit could reset listing_status to 'submitted'
    // for re-review; Phase-1 keeps the existing listing_status (approval in inc 7).
    $data = [
        'name'            => $name,
        'desc'            => trim($_POST['description'] ?? '') ?: null,
        'slug'            => strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name))),
        'location'        => $location,
        'location_coords' => ($lat !== '' && $lng !== '') ? ($lat . ',' . $lng) : null,
        'address'         => trim($_POST['address'] ?? ''),
        'stars'           => ($v = intval($_POST['stars'] ?? 0)) > 0 ? $v : null,
        'currency'        => trim($_POST['currency'] ?? 'USD'),
        'discount'        => ($v = intval($_POST['discount'] ?? 0)) > 0 ? $v : null,
        'email'           => trim($_POST['email'] ?? '') ?: null,
        'phone'           => trim($_POST['phone'] ?? '') ?: null,
        'website'         => trim($_POST['website'] ?? '') ?: null,
        'checkin_time'    => trim($_POST['checkin_time'] ?? '14:00:00'),
        'checkout_time'   => trim($_POST['checkout_time'] ?? '12:00:00'),
        'refundable'      => isset($_POST['refundable']) ? 1 : 0,
        'amenity_ids'     => trim($_POST['amenity_ids'] ?? '[]'),
        'cancellation_policy' => trim($_POST['cancellation_policy'] ?? '') ?: null,
        'updated_at'      => date('Y-m-d H:i:s'),
    ];

    try {
        // Scope the UPDATE by both id AND owner — defense in depth behind supplier_can.
        $db->update('stays', $data, ['id' => (int) $id, 'user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Property updated.'];
    } catch (\Throwable $e) {
        error_log('supplier stays edit: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save changes.'];
    }
    header('Location: ' . root . 'supplier/stays/edit/' . (int) $id);
    exit;
});

// ============================================================================
// DELETE — POST /supplier/stays/delete
// ============================================================================
$router->post('/supplier/stays/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id || !supplier_can($db, 'hotels', 'delete', $id)) {
        _supplier_stays_deny('That property is not yours.');
    }

    try {
        // Delete gallery files (owner-scoped fetch).
        $stay = $db->get('stays', ['img'], ['id' => $id, 'user_id' => $owner]);
        if ($stay && !empty($stay['img'])) {
            $imgs = json_decode($stay['img'], true);
            if (is_array($imgs)) {
                $root = realpath(__DIR__ . '/../../../');
                foreach ($imgs as $im) {
                    $p = $root . '/' . ltrim((string) ($im['url'] ?? ''), '/');
                    if (is_file($p)) { @unlink($p); }
                }
            }
        }
        // Scope the DELETE by owner too (defense in depth).
        $db->delete('stays', ['id' => $id, 'user_id' => $owner]);
        // Clean child rows the supplier owns for this property.
        $db->delete('stays_rooms', ['stay_id' => $id]);
        $db->delete('stays_inventory', ['stay_id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Property deleted.'];
    } catch (\Throwable $e) {
        error_log('supplier stays delete: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete the property.'];
    }
    header('Location: ' . root . 'supplier/stays');
    exit;
});

// ============================================================================
// SUBMIT FOR APPROVAL — POST /supplier/stays/submit/{id}
// (draft/queried/rejected → submitted; the admin review is increment 7)
// ============================================================================
$router->post('/supplier/stays/submit/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) {
        _supplier_stays_deny('That property is not yours.');
    }
    try {
        $db->update('stays',
            ['listing_status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => (int) $id, 'user_id' => $owner]
        );
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Submitted for approval.'];
    } catch (\Throwable $e) {
        error_log('supplier stays submit: ' . $e->getMessage());
    }
    header('Location: ' . root . 'supplier/stays/edit/' . (int) $id);
    exit;
});

// ============================================================================
// ROOMS, OPTIONS & CALENDAR  (Phase 1 inc S10 — supplier self-management)
// ----------------------------------------------------------------------------
// Owner-scoped mirror of the admin room/option/calendar CRUD, gated by
// supplier_can($db,'rooms'|'rates',$action,$stayId). The IDOR guard fires on the
// property id EVERY TIME (supplier_can re-checks stays.user_id === owner), and
// every room/option/calendar write re-scopes its DB query by stay_id (and, where
// the property is fetched, by user_id). Options are addressed by the STABLE
// option_id (stays_room_option_ids) — NEVER by positional index — so pricing and
// inventory keyed on option_id stay correct across reorder/delete (see
// app/lib/stays_inventory.php). Server-rendered + form-based by design (simpler
// and safer than cloning the admin AJAX/option_index flow).
// ============================================================================

if (!function_exists('_supplier_room_taxonomy')) {
    /** Global room_type + board taxonomy from stays_settings (id => name). */
    function _supplier_room_taxonomy($db): array
    {
        $map = ['room_type' => [], 'board' => []];
        foreach (['room_type', 'board'] as $type) {
            try {
                $rows = $db->select('stays_settings', ['id', 'name', 'translations'],
                    ['setting_type' => $type]) ?: [];
            } catch (\Throwable $e) { $rows = []; }
            foreach ($rows as $r) {
                $n = $r['name'] ?? '';
                if ($n === '' && !empty($r['translations'])) {
                    $t = json_decode((string) $r['translations'], true);
                    $n = is_array($t) ? ($t['en'] ?? reset($t)) : '';
                }
                $map[$type][(int) $r['id']] = $n !== '' ? $n : ($type . ' #' . $r['id']);
            }
        }
        return $map;
    }
}

// ---------------------------------------------------------------------------
// ROOMS LIST — GET /supplier/stays/{id}/rooms
// ---------------------------------------------------------------------------
$router->get('/supplier/stays/([0-9]+)/rooms', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view', (int) $id)) {
        _supplier_stays_deny('That property is not yours.');
    }

    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => (int) $id, 'user_id' => $owner]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }

    // Rooms for this property (owner already proven by supplier_can + scoped fetch).
    $rooms = $db->select('stays_rooms',
        ['id', 'room_type_id', 'room_images', 'amenities', 'status', 'room_options'],
        ['stay_id' => (int) $id, 'ORDER' => ['id' => 'ASC']]) ?: [];

    // Ensure every option carries a stable option_id (idempotent; persists lazily).
    foreach ($rooms as &$__r) {
        $__r['_options'] = stays_room_option_ids($db, (int) $__r['id'], (int) $id);
    }
    unset($__r);

    $taxonomy = _supplier_room_taxonomy($db);
    $canEdit  = supplier_can($db, 'rooms', 'edit', (int) $id);
    $canAddRoom    = supplier_can($db, 'rooms', 'add', (int) $id);
    $canDeleteRoom = supplier_can($db, 'rooms', 'delete', (int) $id);

    $title = 'Rooms — ' . ($stay['name'] ?? 'Property');
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/rooms.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// ROOM SAVE (add/edit) — POST /supplier/stays/{id}/rooms/save
// ---------------------------------------------------------------------------
$router->post('/supplier/stays/([0-9]+)/rooms/save', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId  = (int) $id;
    $roomId  = (int) ($_POST['room_id'] ?? 0);
    $isEdit  = $roomId > 0;
    $action  = $isEdit ? 'edit' : 'add';
    if (!supplier_can($db, 'rooms', $action, $stayId)) {
        _supplier_stays_deny('You are not authorised to manage this property\'s rooms.');
    }

    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    $roomTypeId = (int) ($_POST['room_type_id'] ?? 0);
    $status     = isset($_POST['room_status']) ? 1 : 0;
    if ($roomTypeId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Please choose a room type.'];
        header('Location: ' . $back); exit;
    }

    // Amenities: accept a checkbox array of ints, store as a JSON int array (the
    // same shape admin stores). Never trust arbitrary values.
    $amenities = [];
    if (is_array($_POST['amenities'] ?? null)) {
        foreach ($_POST['amenities'] as $a) {
            $a = (int) $a;
            if ($a > 0) { $amenities[] = $a; }
        }
    }

    // Image uploads — same secure path as the property gallery (secureUploadCheck).
    $existing = null;
    if ($isEdit) {
        $existing = $db->get('stays_rooms', '*', ['id' => $roomId, 'stay_id' => $stayId]);
        if (!$existing) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
            header('Location: ' . $back); exit;
        }
    }
    $roomImages = [];
    if ($isEdit && !empty($existing['room_images'])) {
        $decoded = json_decode((string) $existing['room_images'], true);
        if (is_array($decoded)) { $roomImages = $decoded; }
    }
    if (isset($_FILES['room_images']) && !empty($_FILES['room_images']['name'][0])) {
        $projectRoot = realpath(__DIR__ . '/../../../');
        $uploadDir = $projectRoot . '/uploads/hotels/rooms/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
        $files = $_FILES['room_images'];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { continue; }
            $chk = secureUploadCheck(
                ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
            );
            if (!$chk['ok']) { continue; }
            $fn = 'room_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
            if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $fn)) {
                @chmod($uploadDir . $fn, 0644);
                $roomImages[] = ['url' => '/uploads/hotels/rooms/' . $fn, 'default' => empty($roomImages)];
            }
        }
    }

    $data = [
        'stay_id'      => $stayId,                         // FORCED to this property
        'room_type_id' => $roomTypeId,
        'amenities'    => json_encode($amenities),
        'status'       => $status,
        'room_images'  => !empty($roomImages) ? json_encode($roomImages) : null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    try {
        if ($isEdit) {
            // Preserve room_options (managed by the options endpoints, not here).
            $db->update('stays_rooms', $data, ['id' => $roomId, 'stay_id' => $stayId]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Room updated.'];
        } else {
            $data['room_options'] = null;
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('stays_rooms', $data);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Room added. Now add a rate/option so it can sell.'];
        }
    } catch (\Throwable $e) {
        error_log('supplier room save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the room.'];
    }
    header('Location: ' . $back);
    exit;
});

// ---------------------------------------------------------------------------
// ROOM DELETE — POST /supplier/stays/{id}/rooms/delete
// ---------------------------------------------------------------------------
$router->post('/supplier/stays/([0-9]+)/rooms/delete', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) $id;
    $roomId = (int) ($_POST['room_id'] ?? 0);
    if (!$roomId || !supplier_can($db, 'rooms', 'delete', $stayId)) {
        _supplier_stays_deny('You are not authorised to delete this room.');
    }
    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    try {
        // Room must belong to THIS property (defense in depth behind supplier_can).
        $room = $db->get('stays_rooms', ['id', 'room_images'], ['id' => $roomId, 'stay_id' => $stayId]);
        if (!$room) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
            header('Location: ' . $back); exit;
        }
        // Remove room image files.
        if (!empty($room['room_images'])) {
            $imgs = json_decode((string) $room['room_images'], true);
            if (is_array($imgs)) {
                $rootPath = realpath(__DIR__ . '/../../../');
                foreach ($imgs as $im) {
                    $p = $rootPath . '/' . ltrim((string) ($im['url'] ?? ''), '/');
                    if (is_file($p)) { @unlink($p); }
                }
            }
        }
        $db->delete('stays_rooms', ['id' => $roomId, 'stay_id' => $stayId]);
        // Clean dependent availability/rate rows for this room.
        $db->delete('stays_inventory', ['stay_id' => $stayId, 'room_id' => $roomId]);
        $db->delete('stays_rooms_calendar', ['stay_id' => $stayId, 'room_id' => $roomId]);
        // Soft-remove the normalized rate-plan mirror for the whole room (inc S12).
        // The room row is already gone, so sync can't re-read it — deactivate directly.
        try { $db->update('stays_rate_plans', ['active' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId, 'room_id' => $roomId]); } catch (\Throwable $e) { error_log('rate-plan room-delete sync: ' . $e->getMessage()); }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Room deleted.'];
    } catch (\Throwable $e) {
        error_log('supplier room delete: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete the room.'];
    }
    header('Location: ' . $back);
    exit;
});

// ---------------------------------------------------------------------------
// OPTION SAVE (add/edit) — POST /supplier/stays/{id}/rooms/{roomId}/options/save
// Options are addressed by the STABLE option_id (0/empty = add a new one).
// ---------------------------------------------------------------------------
$router->post('/supplier/stays/([0-9]+)/rooms/([0-9]+)/options/save', function ($id, $roomId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) $id;
    $roomId = (int) $roomId;
    // Editing an option is a room edit; gate on 'rooms' edit (options live in the room).
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to manage this property\'s rates.');
    }
    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    // The room must belong to this property.
    $room = $db->get('stays_rooms', ['id', 'room_options'], ['id' => $roomId, 'stay_id' => $stayId]);
    if (!$room) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
        header('Location: ' . $back); exit;
    }

    // Load options WITH stable ids assigned.
    $options  = stays_room_option_ids($db, $roomId, $stayId);
    $optionId = (int) ($_POST['option_id'] ?? 0);

    $fields = [
        'max_adults'          => max(1, (int) ($_POST['max_adults'] ?? 2)),
        'max_children'        => max(0, (int) ($_POST['max_children'] ?? 0)),
        'price'               => round((float) ($_POST['price'] ?? 0), 2),
        'discount_percentage' => max(0, min(100, (float) ($_POST['discount_percentage'] ?? 0))),
        'extra_bed_available' => isset($_POST['extra_bed_available']) ? 1 : 0,
        'extra_bed_charge'    => round((float) ($_POST['extra_bed_charge'] ?? 0), 2),
        'breakfast_included'  => isset($_POST['breakfast_included']) ? 1 : 0,
        'cancellation_free'   => isset($_POST['cancellation_free']) ? 1 : 0,
        'refundable'          => isset($_POST['refundable']) ? 1 : 0,
        'available_quantity'  => max(0, (int) ($_POST['available_quantity'] ?? 1)),
        'status'              => isset($_POST['status']) ? 1 : 0,
        'board_id'            => (int) ($_POST['board_id'] ?? 0),
    ];
    if ($fields['price'] < 0) { $fields['price'] = 0; }

    // Find the target option by STABLE option_id (not position).
    $foundIndex = -1;
    if ($optionId > 0) {
        foreach ($options as $i => $o) {
            if ((int) ($o['option_id'] ?? 0) === $optionId) { $foundIndex = $i; break; }
        }
    }

    try {
        if ($foundIndex >= 0) {
            // Update in place — preserve the stable option_id.
            $options[$foundIndex] = array_merge($options[$foundIndex], $fields, [
                'option_id' => $optionId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $msg = 'Rate updated.';
        } else {
            // Add a new option with the next monotonic stable id.
            $maxId = 0;
            foreach ($options as $o) { $maxId = max($maxId, (int) ($o['option_id'] ?? 0)); }
            $fields['option_id'] = $maxId + 1;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $options[] = $fields;
            $msg = 'Rate added.';
        }
        $db->update('stays_rooms',
            ['room_options' => json_encode(array_values($options)), 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $roomId, 'stay_id' => $stayId]);
        // Mirror into the normalized rate-plan tables (inc S12). Non-fatal; the live
        // path still reads room_options — this only keeps the relational mirror current.
        if (function_exists('stays_rate_plan_sync')) { stays_rate_plan_sync($db, $stayId, $roomId); }
        $_SESSION['message'] = ['type' => 'success', 'text' => $msg];
    } catch (\Throwable $e) {
        error_log('supplier option save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the rate.'];
    }
    header('Location: ' . $back);
    exit;
});

// ---------------------------------------------------------------------------
// OPTION DELETE — POST /supplier/stays/{id}/rooms/{roomId}/options/delete
// Addressed by STABLE option_id.
// ---------------------------------------------------------------------------
$router->post('/supplier/stays/([0-9]+)/rooms/([0-9]+)/options/delete', function ($id, $roomId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId   = (int) $id;
    $roomId   = (int) $roomId;
    $optionId = (int) ($_POST['option_id'] ?? 0);
    if (!$optionId || !supplier_can($db, 'rooms', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to manage this property\'s rates.');
    }
    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    $room = $db->get('stays_rooms', ['id'], ['id' => $roomId, 'stay_id' => $stayId]);
    if (!$room) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
        header('Location: ' . $back); exit;
    }

    try {
        $options = stays_room_option_ids($db, $roomId, $stayId);
        $kept = [];
        $removed = false;
        foreach ($options as $o) {
            if ((int) ($o['option_id'] ?? 0) === $optionId) { $removed = true; continue; }
            $kept[] = $o;
        }
        if ($removed) {
            $db->update('stays_rooms',
                ['room_options' => json_encode(array_values($kept)), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $roomId, 'stay_id' => $stayId]);
            // Drop availability/rate rows keyed on this stable option_id.
            $db->delete('stays_inventory', ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId]);
            $db->delete('stays_rooms_calendar', ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId]);
            // Re-sync the normalized mirror (soft-removes the deleted option's plan).
            if (function_exists('stays_rate_plan_sync')) { stays_rate_plan_sync($db, $stayId, $roomId); }
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Rate deleted.'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Rate not found.'];
        }
    } catch (\Throwable $e) {
        error_log('supplier option delete: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete the rate.'];
    }
    header('Location: ' . $back);
    exit;
});

// ---------------------------------------------------------------------------
// CALENDAR VIEW — GET /supplier/stays/{id}/rooms/{roomId}/calendar
// Per-date price + availability for one room's options (one month at a time).
// ---------------------------------------------------------------------------
$router->get('/supplier/stays/([0-9]+)/rooms/([0-9]+)/calendar', function ($id, $roomId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) $id;
    $roomId = (int) $roomId;
    if (!supplier_can($db, 'rates', 'view', $stayId)) {
        _supplier_stays_deny('You are not authorised to view this property\'s rates.');
    }
    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId, 'user_id' => $owner]);
    $room = $db->get('stays_rooms', ['id', 'room_type_id'], ['id' => $roomId, 'stay_id' => $stayId]);
    if (!$stay || !$room) { _supplier_stays_deny('Room not found for this property.'); }

    $year  = (int) ($_GET['year']  ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    if ($month < 1 || $month > 12) { $month = (int) date('n'); }
    if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));

    $options = stays_room_option_ids($db, $roomId, $stayId);

    // Existing per-date prices for the month (keyed option_id|date).
    $prices = [];
    try {
        $rows = $db->select('stays_rooms_calendar', ['option_id', 'date', 'price'],
            ['stay_id' => $stayId, 'room_id' => $roomId, 'date[>=]' => $monthStart, 'date[<=]' => $monthEnd]) ?: [];
        foreach ($rows as $r) { $prices[(int) $r['option_id'] . '|' . $r['date']] = (float) $r['price']; }
    } catch (\Throwable $e) { error_log('supplier calendar prices: ' . $e->getMessage()); }

    // Existing per-date availability counts for the month (keyed option_id|date).
    $invCounts = [];
    try {
        $rows = $db->select('stays_inventory', ['option_id', 'date', 'available_count', 'closed'],
            ['stay_id' => $stayId, 'room_id' => $roomId, 'date[>=]' => $monthStart, 'date[<=]' => $monthEnd]) ?: [];
        foreach ($rows as $r) {
            $invCounts[(int) $r['option_id'] . '|' . $r['date']] = [
                'count' => $r['available_count'] === null ? null : (int) $r['available_count'],
                'closed' => (int) ($r['closed'] ?? 0),
            ];
        }
    } catch (\Throwable $e) { error_log('supplier calendar inv: ' . $e->getMessage()); }

    $taxonomy = _supplier_room_taxonomy($db);
    $canEdit  = supplier_can($db, 'rates', 'edit', $stayId);

    $title = 'Rates & availability';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/calendar.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// CALENDAR SAVE — POST /supplier/stays/{id}/rooms/{roomId}/calendar/save
// Saves per-(option_id,date) price and availability count. option_id must be a
// stable id that EXISTS on this room (validated), so inventory/rate rows always
// key to a real option.
// ---------------------------------------------------------------------------
$router->post('/supplier/stays/([0-9]+)/rooms/([0-9]+)/calendar/save', function ($id, $roomId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) $id;
    $roomId = (int) $roomId;
    if (!supplier_can($db, 'rates', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to edit this property\'s rates.');
    }

    $year  = (int) ($_POST['year']  ?? date('Y'));
    $month = (int) ($_POST['month'] ?? date('n'));
    $back  = root . 'supplier/stays/' . $stayId . '/rooms/' . $roomId . '/calendar?year=' . $year . '&month=' . $month;

    $room = $db->get('stays_rooms', ['id'], ['id' => $roomId, 'stay_id' => $stayId]);
    if (!$room) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
        header('Location: ' . root . 'supplier/stays/' . $stayId . '/rooms'); exit;
    }

    // The set of stable option_ids that actually exist on this room (whitelist).
    $options = stays_room_option_ids($db, $roomId, $stayId);
    $validOptionIds = [];
    foreach ($options as $o) { $validOptionIds[(int) ($o['option_id'] ?? 0)] = true; }

    // Payloads: prices[optionId][YYYY-MM-DD] and avail[optionId][YYYY-MM-DD],
    // closed[optionId][YYYY-MM-DD]. Only keys for valid option_ids + real dates
    // are written. A blank price/availability means "leave as default" (skip).
    $prices = is_array($_POST['prices'] ?? null) ? $_POST['prices'] : [];
    $avail  = is_array($_POST['avail'] ?? null)  ? $_POST['avail']  : [];
    $closed = is_array($_POST['closed'] ?? null) ? $_POST['closed'] : [];

    $now = date('Y-m-d H:i:s');
    $savedPrices = 0; $savedInv = 0;

    try {
        // Prices → stays_rooms_calendar (upsert via Medoo: update then insert).
        foreach ($prices as $oid => $byDate) {
            $oid = (int) $oid;
            if (empty($validOptionIds[$oid]) || !is_array($byDate)) { continue; }
            foreach ($byDate as $date => $val) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { continue; }
                if ($val === '' || $val === null) { continue; }
                $price = round((float) $val, 2);
                if ($price < 0) { $price = 0; }
                $exists = $db->has('stays_rooms_calendar',
                    ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid, 'date' => $date]);
                if ($exists) {
                    $db->update('stays_rooms_calendar', ['price' => $price, 'updated_at' => $now],
                        ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid, 'date' => $date]);
                } else {
                    $db->insert('stays_rooms_calendar', [
                        'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid,
                        'date' => $date, 'price' => $price, 'created_at' => $now,
                    ]);
                }
                $savedPrices++;
            }
        }

        // Availability counts + closed flag → stays_inventory (upsert).
        $dateKeys = [];
        foreach ([$avail, $closed] as $src) {
            foreach ($src as $oid => $byDate) {
                if (!is_array($byDate)) { continue; }
                foreach ($byDate as $date => $_v) { $dateKeys[(int) $oid . '|' . $date] = [(int) $oid, (string) $date]; }
            }
        }
        foreach ($dateKeys as $pair) {
            [$oid, $date] = $pair;
            if (empty($validOptionIds[$oid])) { continue; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
            $cntRaw = $avail[$oid][$date] ?? '';
            $isClosed = !empty($closed[$oid][$date]) ? 1 : 0;
            // If nothing to set for this cell, skip.
            if ($cntRaw === '' && $isClosed === 0) { continue; }
            $count = ($cntRaw === '' || $cntRaw === null) ? null : max(0, (int) $cntRaw);
            $exists = $db->has('stays_inventory',
                ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid, 'date' => $date]);
            if ($exists) {
                $upd = ['closed' => $isClosed, 'updated_at' => $now];
                if ($count !== null) { $upd['available_count'] = $count; }
                $db->update('stays_inventory', $upd,
                    ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid, 'date' => $date]);
            } else {
                $db->insert('stays_inventory', [
                    'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $oid, 'date' => $date,
                    'available_count' => $count !== null ? $count : 0, 'closed' => $isClosed, 'created_at' => $now,
                ]);
            }
            $savedInv++;
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => "Saved {$savedPrices} price(s) and {$savedInv} availability cell(s)."];
    } catch (\Throwable $e) {
        error_log('supplier calendar save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the calendar.'];
    }
    header('Location: ' . $back);
    exit;
});

// ============================================================================
// BRANDED SITE & DOMAIN (Phase 1 inc 8 — foundation)
// ============================================================================

// GET /supplier/stays/site/{id} — view/manage a property's branded site + domain.
$router->get('/supplier/stays/site/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }

    $stay = $db->get('stays', ['id', 'name', 'slug'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }

    // Ensure a stays_site row exists with a default branded hostname (if a branded
    // root is configured). Idempotent.
    $site = $db->get('stays_site', '*', ['stay_id' => (int) $id]);
    $defaultHost = supplier_site_default_hostname((string) ($stay['slug'] ?? ''));
    if (!$site) {
        try {
            $db->insert('stays_site', [
                'stay_id'   => (int) $id,
                'hostname'  => $defaultHost !== '' ? $defaultHost : null,
                'domain_status' => 'none',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('supplier stays site create: ' . $e->getMessage()); }
        $site = $db->get('stays_site', '*', ['stay_id' => (int) $id]);
    }

    $brandedRoot = supplier_site_branded_root();
    $title = 'Site & Domain';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/site.php";
    require_once views . "includes/footer.php";
});

// GET /supplier/stays/site/{id}/preview — render the property's branded booking
// page (owner-scoped). Demonstrates the branded site; host-based serving on the
// custom domain is the deferred edge step.
$router->get('/supplier/stays/site/([0-9]+)/preview', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }

    $stay = $db->get('stays', '*', ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $site = $db->get('stays_site', '*', ['stay_id' => (int) $id]);

    // Rooms for the branded page (owner's own inventory).
    $rooms = $db->select('stays_rooms', ['id', 'room_type_id', 'room_images', 'room_options'],
        ['stay_id' => (int) $id, 'status' => 1]) ?: [];

    $title = ($stay['name'] ?? 'Property') . ' — Booking';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/branded-preview.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/stays/site/{id} — request a custom domain (CNAME) for a property.
$router->post('/supplier/stays/site/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }

    // Normalize the requested custom domain (host only, lowercase, no scheme/path).
    $raw = trim((string) ($_POST['custom_domain'] ?? ''));
    $domain = strtolower($raw);
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = explode('/', $domain)[0];
    $domain = trim($domain);

    $back = root . 'supplier/stays/site/' . (int) $id;

    if ($domain === '') {
        // Clearing the custom domain.
        try {
            $db->update('stays_site',
                ['custom_domain' => null, 'domain_status' => 'none', 'verify_token' => null, 'updated_at' => date('Y-m-d H:i:s')],
                ['stay_id' => (int) $id]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Custom domain removed.'];
        } catch (\Throwable $e) { error_log('supplier site clear: ' . $e->getMessage()); }
        header('Location: ' . $back); exit;
    }

    // Basic hostname validation.
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Please enter a valid domain, e.g. book.yourhotel.com'];
        header('Location: ' . $back); exit;
    }
    // Uniqueness: a domain can't be claimed by two properties.
    $claimed = $db->get('stays_site', ['stay_id'], ['custom_domain' => $domain]);
    if ($claimed && (int) $claimed['stay_id'] !== (int) $id) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That domain is already in use.'];
        header('Location: ' . $back); exit;
    }

    // Record the request: status 'pending' + a verification token. Actual DNS
    // verification + TLS issuance is the edge/infra step (docs §7.2) — this stores
    // the intent + the TXT value the supplier must publish.
    $token = 'goglobia-verify=' . bin2hex(random_bytes(16));
    try {
        $db->update('stays_site', [
            'custom_domain' => $domain,
            'domain_status' => 'pending',
            'verify_token'  => $token,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['stay_id' => (int) $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Custom domain saved. Add the DNS records shown, then verification completes it.'];
    } catch (\Throwable $e) {
        error_log('supplier site domain: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the domain.'];
    }
    header('Location: ' . $back);
    exit;
});
