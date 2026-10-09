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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
    SUPPLIER_AUTH();
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
