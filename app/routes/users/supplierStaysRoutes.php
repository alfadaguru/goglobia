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

if (!function_exists('_supplier_stay_amenities')) {
    /** The active property-level amenities (setting_type='stay_amenity' — the
     *  canonical value the admin form and the public detail page use). Read-only;
     *  returns [] on any error so the form just renders without the picker. */
    function _supplier_stay_amenities($db): array
    {
        try {
            return $db->select('stays_settings', ['id', 'name'],
                ['setting_type' => 'stay_amenity', 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('_supplier_stay_amenities: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('_supplier_stays_currency')) {
    /**
     * Resolve the submitted currency to one of the system's ENABLED currencies.
     * Prevents a hand-crafted POST from storing an unsupported code. Falls back to
     * the system default (first in $GLOBALS['currencies'], ordered default DESC),
     * then 'USD'. Returns the 3-char currency code to store on stays.currency.
     */
    function _supplier_stays_currency(?string $submitted): string
    {
        $submitted = strtoupper(trim((string) $submitted));
        $list = (isset($GLOBALS['currencies']) && is_array($GLOBALS['currencies'])) ? $GLOBALS['currencies'] : [];
        $codes = [];
        foreach ($list as $c) {
            $code = strtoupper((string) ($c['name'] ?? ''));
            if ($code !== '') { $codes[] = $code; }
        }
        if ($submitted !== '' && in_array($submitted, $codes, true)) { return $submitted; }
        return $codes[0] ?? 'USD'; // system default (default DESC) or USD
    }
}

if (!function_exists('_supplier_stays_col_exists')) {
    /**
     * True if a column exists on a table. Cached per request. Used so a property
     * INSERT/UPDATE never references a column the live DB hasn't migrated yet (e.g.
     * stays.logo on an install where the ALTER couldn't run) — which would otherwise
     * throw and bounce the user back to the form with no saved property.
     */
    function _supplier_stays_col_exists($db, string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (isset($cache[$key])) { return $cache[$key]; }
        try {
            $row = $db->query("SHOW COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "` LIKE " . $db->quote($col))->fetch();
            return $cache[$key] = (bool) $row;
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('_supplier_stays_post_too_large')) {
    /**
     * Detect a POST that PHP discarded because it exceeded post_max_size: the request
     * method is POST and Content-Length > 0, yet $_POST AND $_FILES are both empty.
     * In that case our CSRF token + all fields are gone, so we must report the real
     * cause (too many / too large photos) instead of a misleading "name required".
     */
    function _supplier_stays_post_too_large(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { return false; }
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $len > 0 && empty($_POST) && empty($_FILES);
    }
}

if (!function_exists('_supplier_stays_validate_image')) {
    /**
     * Validate ONE uploaded image slice against the app limits and return a specific,
     * human error ('' = valid). $maxBytes / $exts are the app-level caps (see the
     * SUPPLIER_IMG_* constants) — deliberately under PHP's own limits. Covers every
     * PHP upload-error code with a plain-English reason, plus the size/type/content
     * checks (the latter via secureUploadCheck once the slice is known uploadable).
     *
     * @param array  $slice ['name','tmp_name','size','error'] for a single file
     * @param string $label what to call it in messages (e.g. the filename, or 'Logo')
     * @return string empty if valid; otherwise the reason to show the user
     */
    function _supplier_stays_validate_image(array $slice, int $maxBytes, array $exts, string $label): string
    {
        $name = trim((string) ($slice['name'] ?? ''));
        $disp = $name !== '' ? '“' . $name . '”' : $label;
        $err  = (int) ($slice['error'] ?? UPLOAD_ERR_NO_FILE);

        switch ($err) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $disp . ' is larger than the ' . supplier_human_bytes($maxBytes) . ' limit.';
            case UPLOAD_ERR_PARTIAL:
                return $disp . ' only uploaded partially — please try again.';
            case UPLOAD_ERR_NO_FILE:
                return ''; // empty slot — not an error
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                return $disp . ' could not be saved on the server — please try again.';
            default:
                return $disp . ' could not be uploaded (error ' . $err . ').';
        }

        $size = (int) ($slice['size'] ?? 0);
        if ($size <= 0)        { return $disp . ' is empty.'; }
        if ($size > $maxBytes) { return $disp . ' is ' . supplier_human_bytes($size) . ' — the limit is ' . supplier_human_bytes($maxBytes) . ' per image.'; }

        // Deep check: real MIME, extension match, no embedded PHP (polyglot).
        $chk = secureUploadCheck(
            ['name' => $name, 'tmp_name' => $slice['tmp_name'] ?? '', 'size' => $size, 'error' => UPLOAD_ERR_OK],
            $exts, $maxBytes
        );
        if (!$chk['ok']) {
            $reason = (string) ($chk['error'] ?? 'invalid image');
            if (stripos($reason, 'type') !== false || stripos($reason, 'content') !== false) {
                return $disp . ' is not a supported image — use ' . strtoupper(implode('/', $exts)) . '.';
            }
            return $disp . ': ' . $reason;
        }
        return '';
    }
}

if (!function_exists('_supplier_stays_process_gallery')) {
    /**
     * Validate + store a batch of images from a $_FILES[$field] multi-upload, applying
     * the app limits (per-file size/type, max count) with a SPECIFIC message per
     * rejected file — nothing is silently skipped. Valid files are moved into
     * uploads/hotels/$subdir/, resized and thumbnailed.
     * Returns ['images'=>[{url,thumb,default}], 'errors'=>[string]].
     *
     * @param bool   $markFirstDefault flag the first stored image as default (create)
     * @param string $field  the $_FILES key (default 'hotel_images'; rooms use 'room_images')
     * @param string $subdir subfolder under uploads/hotels/ (default 'gallery')
     * @param string $prefix stored-filename prefix (default 'hotel')
     */
    function _supplier_stays_process_gallery(bool $markFirstDefault, string $field = 'hotel_images', string $subdir = 'gallery', string $prefix = 'hotel'): array
    {
        $images = []; $errors = [];
        if (empty($_FILES[$field]['name'][0])) { return ['images' => $images, 'errors' => $errors]; }

        $files = $_FILES[$field];
        $count = count($files['name']);
        $maxCount = defined('SUPPLIER_IMG_MAX_COUNT') ? SUPPLIER_IMG_MAX_COUNT : 10;
        $maxBytes = function_exists('supplier_effective_img_max') ? supplier_effective_img_max() : (defined('SUPPLIER_IMG_MAX_BYTES') ? SUPPLIER_IMG_MAX_BYTES : (5 * 1024 * 1024));
        $exts = array_filter(array_map('trim', explode(',', defined('SUPPLIER_IMG_EXTS') ? SUPPLIER_IMG_EXTS : 'jpg,jpeg,png,webp,gif')));

        // How many non-empty slots were actually submitted?
        $submitted = 0;
        for ($i = 0; $i < $count; $i++) { if (!empty($files['name'][$i])) { $submitted++; } }
        if ($submitted > $maxCount) {
            $errors[] = 'You selected ' . $submitted . ' photos, but you can upload at most ' . $maxCount . ' per batch. Remove ' . ($submitted - $maxCount) . ' and try again — you can add more after saving.';
            return ['images' => $images, 'errors' => $errors]; // reject the whole batch so the user chooses which to keep
        }

        $subdir = preg_replace('/[^a-z0-9_]/', '', strtolower($subdir)) ?: 'gallery';
        $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) ?: 'img';
        $project_root = realpath(__DIR__ . '/../../../');
        $urlBase = '/uploads/hotels/' . $subdir . '/';
        $upload_dir = $project_root . $urlBase;
        if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0755, true); }

        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i])) { continue; } // empty slot
            $slice = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => $files['error'][$i]];
            $msg = _supplier_stays_validate_image($slice, $maxBytes, $exts, 'A photo');
            if ($msg !== '') { $errors[] = $msg; continue; }

            $ext = strtolower((string) pathinfo((string) $files['name'][$i], PATHINFO_EXTENSION));
            $fn = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $fn)) {
                @chmod($upload_dir . $fn, 0644);
                $proc = function_exists('supplier_image_process')
                    ? supplier_image_process($upload_dir . $fn, $urlBase . $fn)
                    : ['url' => $urlBase . $fn, 'thumb' => $urlBase . $fn];
                $images[] = ['url' => $proc['url'], 'thumb' => $proc['thumb'] ?? $proc['url'], 'default' => false];
            } else {
                $errors[] = '“' . $files['name'][$i] . '” could not be saved — please try again.';
            }
        }
        if ($markFirstDefault && !empty($images)) { $images[0]['default'] = true; }
        return ['images' => $images, 'errors' => $errors];
    }
}

if (!function_exists('_supplier_stays_handle_logo')) {
    /**
     * Handle the optional property-logo upload (POST field `hotel_logo`). Validates
     * against the app logo limits with a specific error message, moves into
     * /uploads/hotels/logos/, then downscales it. Returns [url|null, error-string].
     * On no upload returns [$existing, ''] so an edit that doesn't re-upload keeps the
     * current logo. On a rejected upload returns [$existing, '<specific reason>'].
     */
    function _supplier_stays_handle_logo($db, ?string $existing): array
    {
        if (empty($_FILES['hotel_logo']['name']) || ($_FILES['hotel_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [$existing, '']; // nothing uploaded this request
        }
        $maxBytes = function_exists('supplier_effective_logo_max') ? supplier_effective_logo_max() : (defined('SUPPLIER_LOGO_MAX_BYTES') ? SUPPLIER_LOGO_MAX_BYTES : (2 * 1024 * 1024));
        $exts = array_filter(array_map('trim', explode(',', defined('SUPPLIER_LOGO_EXTS') ? SUPPLIER_LOGO_EXTS : 'jpg,jpeg,png,webp')));
        $f = $_FILES['hotel_logo'];
        $slice = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'] ?? '', 'size' => $f['size'] ?? 0, 'error' => $f['error'] ?? UPLOAD_ERR_NO_FILE];
        $msg = _supplier_stays_validate_image($slice, $maxBytes, $exts, 'Logo');
        if ($msg !== '') { return [$existing, 'Logo: ' . $msg]; }

        $project_root = realpath(__DIR__ . '/../../../');
        if ($project_root === false) { return [$existing, '']; }
        $dir = $project_root . '/uploads/hotels/logos/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        $fn = 'logo_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $fn)) { return [$existing, 'Logo could not be saved — please try again.']; }
        @chmod($dir . $fn, 0644);
        $url = '/uploads/hotels/logos/' . $fn;
        if (function_exists('supplier_image_process')) {
            $proc = supplier_image_process($dir . $fn, $url);
            $url = $proc['url'] ?? $url;
        }
        return [$url, ''];
    }
}

if (!function_exists('_supplier_stays_handle_theme_image')) {
    /**
     * G7 — handle an optional theme-image upload (hero / favicon) for the white-label site.
     * Same hardened path as the logo: validate (MIME+ext+size), move into /uploads/hotels/site/,
     * process. $field = the $_FILES key; $prefix = filename prefix; $exts = allowed extensions
     * (favicon also allows .ico). Returns [url|null, error-string]; [null,''] when nothing uploaded.
     */
    function _supplier_stays_handle_theme_image(string $field, string $prefix, array $exts): array
    {
        if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, '']; // nothing uploaded
        }
        $maxBytes = 2 * 1024 * 1024;
        $f = $_FILES[$field];
        $slice = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'] ?? '', 'size' => $f['size'] ?? 0, 'error' => $f['error'] ?? UPLOAD_ERR_NO_FILE];
        $label = ucfirst($prefix);
        if (function_exists('_supplier_stays_validate_image')) {
            // .ico can't be validated as a raster image; skip the raster validator for ico.
            $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'ico') {
                $msg = _supplier_stays_validate_image($slice, $maxBytes, array_filter($exts, fn($e) => $e !== 'ico'), $label);
                if ($msg !== '') { return [null, $label . ': ' . $msg]; }
            } else {
                if (($f['size'] ?? 0) > $maxBytes) { return [null, $label . ': file is too large.']; }
            }
        }
        $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { return [null, $label . ': unsupported file type.']; }
        $project_root = realpath(__DIR__ . '/../../../');
        if ($project_root === false) { return [null, '']; }
        $dir = $project_root . '/uploads/hotels/site/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $fn = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $fn)) { return [null, $label . ' could not be saved — please try again.']; }
        @chmod($dir . $fn, 0644);
        $url = '/uploads/hotels/site/' . $fn;
        // Hero benefits from downscale; favicon/ico kept as-is.
        if ($prefix === 'hero' && $ext !== 'ico' && function_exists('supplier_image_process')) {
            $proc = supplier_image_process($dir . $fn, $url);
            $url = $proc['url'] ?? $url;
        }
        return [$url, ''];
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
    $amenities = _supplier_stay_amenities($db);
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
    // If PHP dropped the whole POST for exceeding post_max_size, the CSRF token and all
    // fields are gone — report the true cause (photos too large) BEFORE CSRF::guard,
    // which would otherwise 403 with a misleading message.
    if (_supplier_stays_post_too_large()) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your photos were too large to upload in one go. Each image must be within the server limit (' . ini_get('upload_max_filesize') . ' each, ' . ini_get('post_max_size') . ' total). Add fewer or smaller photos and try again.'];
        header('Location: ' . root . 'supplier/stays/add');
        exit;
    }
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

    // Gallery + logo: validate against the APP limits with specific per-file messages.
    // If ANY image is rejected, stop and show exactly why — don't create a property
    // minus the photos the user expected on it.
    $gallery = _supplier_stays_process_gallery(true);     // mark first as default (create)
    [$logoUrl, $logoErr] = _supplier_stays_handle_logo($db, null);
    $uploadErrors = $gallery['errors'];
    if ($logoErr !== '') { $uploadErrors[] = $logoErr; }
    if (!empty($uploadErrors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode(' ', $uploadErrors)];
        header('Location: ' . root . 'supplier/stays/add');
        exit;
    }
    $images = $gallery['images'];

    // UNIQUE slug across all stays — two same-named hotels must not share a slug (that
    // would collide their /book/{slug} page and their <slug>.<root> branded hostname).
    $slug = function_exists('stays_unique_slug')
        ? stays_unique_slug($db, $name, null)
        : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    $lat = trim($_POST['latitude'] ?? ''); $lng = trim($_POST['longitude'] ?? '');

    // Hierarchy seam (inc S14): ensure the owner's org exists and stamp it on the new
    // property. Non-fatal — a 0/absent org just leaves the column NULL; the live path
    // never reads org_id. accommodation_type is validated against the known models.
    $orgId = function_exists('supplier_org_ensure') ? supplier_org_ensure($db, $owner) : 0;
    $accTypes = function_exists('supplier_accommodation_types') ? supplier_accommodation_types() : [];
    $accType = strtolower(trim((string) ($_POST['accommodation_type'] ?? 'hotel')));
    if (!isset($accTypes[$accType])) { $accType = 'hotel'; }

    $data = [
        'user_id'         => $owner,                                   // FORCED to owner
        'org_id'          => $orgId > 0 ? $orgId : null,               // hierarchy seam
        'accommodation_type' => $accType,
        'cleaning_fee'    => ($_POST['cleaning_fee'] ?? '') !== '' ? max(0, round((float) $_POST['cleaning_fee'], 2)) : null,
        'security_deposit'=> ($_POST['security_deposit'] ?? '') !== '' ? max(0, round((float) $_POST['security_deposit'], 2)) : null,
        'name'            => $name,
        'desc'            => trim($_POST['description'] ?? '') ?: null,
        'slug'            => $slug,
        'location'        => $location,
        'location_coords' => ($lat !== '' && $lng !== '') ? ($lat . ',' . $lng) : null,
        'address'         => trim($_POST['address'] ?? ''),
        'stars'           => ($v = intval($_POST['stars'] ?? 0)) > 0 ? $v : null,
        'currency'        => _supplier_stays_currency($_POST['currency'] ?? null),
        'discount'        => ($v = intval($_POST['discount'] ?? 0)) > 0 ? $v : null,
        'email'           => trim($_POST['email'] ?? '') ?: null,
        'phone'           => trim($_POST['phone'] ?? '') ?: null,
        'website'         => trim($_POST['website'] ?? '') ?: null,
        'checkin_time'    => trim($_POST['checkin_time'] ?? '14:00:00'),
        'checkout_time'   => trim($_POST['checkout_time'] ?? '12:00:00'),
        'refundable'      => isset($_POST['refundable']) ? 1 : 0,
        'amenity_ids'     => trim($_POST['amenity_ids'] ?? '[]'),
        'cancellation_policy' => trim($_POST['cancellation_policy'] ?? '') ?: null,
        'house_rules'     => function_exists('house_rules_normalize') ? house_rules_normalize($_POST) : null,
        'timezone'        => (function_exists('property_timezone_valid') && property_timezone_valid((string) ($_POST['timezone'] ?? ''))) ? (trim((string) ($_POST['timezone'] ?? '')) ?: null) : null,
        'img'             => !empty($images) ? json_encode($images) : null,
        // Supplier-created listings start OFFLINE + DRAFT: not live (status 0) and
        // pending per-listing approval (listing_status draft). Admin create used 1;
        // suppliers must be reviewed first.
        'status'          => 0,
        'listing_status'  => 'draft',
        'created_at'      => date('Y-m-d H:i:s'),
    ];
    // Only write stays.logo when the column exists — on an install where the ALTER
    // hasn't run, including it would throw and block property creation entirely.
    if ($logoUrl !== null && _supplier_stays_col_exists($db, 'stays', 'logo')) {
        $data['logo'] = $logoUrl;
    }

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
// PROPERTY HUB — GET /supplier/stays/{id}  (inc S35 — the per-property drill-in)
// The landing when you "enter" a property: a quick overview + the context for the
// property-scoped sidebar (which keys off this stay id in the path). Fully anchored
// regex (^…$) so it never shadows /add or the /{id}/rooms etc. routes.
// ============================================================================
$router->get('/supplier/stays/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view', (int) $id)) {
        _supplier_stays_deny('That property is not yours.');
    }
    $stay = $db->get('stays', '*', ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }

    // Light overview counts for the hub (defensive — never fatal).
    $hub = ['rooms' => 0, 'reservations' => 0, 'physical_rooms' => 0, 'open_work_orders' => 0];
    try { $hub['rooms'] = (int) $db->count('stays_rooms', ['stay_id' => (int) $id]); } catch (\Throwable $e) {}
    try { $hub['physical_rooms'] = (int) $db->count('stays_physical_rooms', ['stay_id' => (int) $id, 'active' => 1]); } catch (\Throwable $e) {}
    try { $hub['open_work_orders'] = (int) $db->count('stays_work_orders', ['stay_id' => (int) $id, 'status' => 'open']); } catch (\Throwable $e) {}

    // ---- GUIDED SETUP STATE (per property) — real data, never faked. Drives the
    // "Set up your property" checklist on the hub so the supplier always sees what's
    // done and what's next, in order. Each step: done + count + a short status line.
    $sidI = (int) $id;
    $buildingsN = 0; $floorsN = 0; $sellableRooms = 0; $photosN = 0;
    try { $buildingsN = function_exists('building_list') ? count(building_list($db, $sidI)) : (int) $db->count('stays_buildings', ['stay_id' => $sidI]); } catch (\Throwable $e) {}
    try { $floorsN = function_exists('floor_list') ? count(floor_list($db, $sidI)) : (int) $db->count('stays_floors', ['stay_id' => $sidI]); } catch (\Throwable $e) {}
    try {
        // "Sellable" = an active room with an active, priced rate (mirrors the submit guard).
        $rrows = $db->select('stays_rooms', ['room_options'], ['stay_id' => $sidI, 'status' => 1]) ?: [];
        foreach ($rrows as $rr) {
            $oo = json_decode((string) ($rr['room_options'] ?? ''), true);
            if (!is_array($oo)) { continue; }
            foreach ($oo as $o) { if ((int) ($o['status'] ?? 0) === 1 && (float) ($o['price'] ?? 0) > 0) { $sellableRooms++; break; } }
        }
    } catch (\Throwable $e) {}
    try { $imgs = json_decode((string) ($stay['img'] ?? ''), true); $photosN = is_array($imgs) ? count($imgs) : 0; } catch (\Throwable $e) {}

    $basicsDone = trim((string) ($stay['name'] ?? '')) !== '' && trim((string) ($stay['location'] ?? '')) !== '';
    $ls = (string) ($stay['listing_status'] ?? 'draft');
    $setup = [
        ['n' => 1, 'key' => 'basics',   'label' => 'Property basics',    'icon' => 'apartment',
         'done' => $basicsDone, 'status' => $basicsDone ? 'Name & location set' : 'Add name, location, description',
         'url' => root . 'supplier/stays/edit/' . $sidI, 'cta' => 'Edit basics'],
        ['n' => 2, 'key' => 'structure', 'label' => 'Buildings & floors', 'icon' => 'domain',
         'done' => $buildingsN > 0, 'status' => $buildingsN > 0 ? ($buildingsN . ' building(s), ' . $floorsN . ' floor(s)') : 'Optional — add structure for larger properties',
         'optional' => true, 'url' => root . 'supplier/stays/' . $sidI . '/buildings', 'cta' => 'Manage structure'],
        ['n' => 3, 'key' => 'rooms',    'label' => 'Rooms & rates',      'icon' => 'bed',
         'done' => $sellableRooms > 0, 'status' => $sellableRooms > 0 ? ($sellableRooms . ' sellable room type(s)') : 'Add a room type with a priced rate',
         'url' => root . 'supplier/stays/' . $sidI . '/rooms', 'cta' => 'Manage rooms & rates'],
        ['n' => 4, 'key' => 'physical', 'label' => 'Physical rooms',     'icon' => 'meeting_room',
         'done' => (int) $hub['physical_rooms'] > 0, 'status' => (int) $hub['physical_rooms'] > 0 ? ((int) $hub['physical_rooms'] . ' room(s) numbered') : 'Optional — number individual units for front desk & housekeeping',
         'optional' => true, 'url' => root . 'supplier/stays/' . $sidI . '/rooms', 'cta' => 'Add physical rooms'],
        ['n' => 5, 'key' => 'photos',   'label' => 'Photos & logo',      'icon' => 'photo_library',
         'done' => $photosN > 0, 'status' => $photosN > 0 ? ($photosN . ' photo(s) uploaded') : 'Add photos so guests can see the property',
         'url' => root . 'supplier/stays/edit/' . $sidI . '#media', 'cta' => 'Add photos'],
        ['n' => 6, 'key' => 'golive',   'label' => 'Submit for approval', 'icon' => 'rocket_launch',
         'done' => in_array($ls, ['submitted', 'approved'], true),
         'status' => ['draft' => 'Ready? Submit for admin review', 'submitted' => 'Submitted — awaiting admin review', 'queried' => 'Admin has a query — see your email', 'rejected' => 'Not approved — review the note', 'approved' => 'Approved & live'][$ls] ?? 'Submit for admin review',
         'url' => root . 'supplier/stays/edit/' . $sidI, 'cta' => $ls === 'approved' ? 'View property' : 'Review & submit'],
    ];
    $setupDone = 0; $setupTotal = 0;
    foreach ($setup as $st) { if (empty($st['optional'])) { $setupTotal++; if (!empty($st['done'])) { $setupDone++; } } }

    $title = ($stay['name'] ?? 'Property'); $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/property-hub.php";
    require_once views . "includes/footer.php";
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
    $amenities = _supplier_stay_amenities($db);
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
    if (_supplier_stays_post_too_large()) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your photos were too large to upload in one go. Each image must be within the server limit (' . ini_get('upload_max_filesize') . ' each, ' . ini_get('post_max_size') . ' total). Add fewer or smaller photos and try again.'];
        header('Location: ' . root . 'supplier/stays/edit/' . (int) $id);
        exit;
    }
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

    // Accommodation type (inc S14) — validated against the known operating models.
    $accTypes = function_exists('supplier_accommodation_types') ? supplier_accommodation_types() : [];
    $accType = strtolower(trim((string) ($_POST['accommodation_type'] ?? 'hotel')));
    if (!isset($accTypes[$accType])) { $accType = 'hotel'; }

    // --------------------------------------------------------------------------
    // GALLERY MANAGEMENT: delete selected existing images, set the default, and
    // append new uploads. The old edit flow could only APPEND — existing images
    // could never be removed or re-defaulted. We rebuild the img JSON here and put
    // it in the update set. Files for deleted images are unlinked best-effort.
    // --------------------------------------------------------------------------
    $galleryChanged = false;
    $existing = $db->get('stays', ['img'], ['id' => (int) $id, 'user_id' => $owner]);
    $gallery = [];
    if ($existing && !empty($existing['img'])) {
        $decoded = json_decode((string) $existing['img'], true);
        if (is_array($decoded)) { $gallery = array_values($decoded); }
    }
    // Resolve the chosen default by its URL NOW, against the ORIGINAL indices the form
    // rendered — because we re-index below after deletes, so a post-delete positional
    // index would point at the wrong image. URL match is stable across re-indexing.
    $defaultUrl = '';
    if (isset($_POST['img_default'])) {
        $di = (int) $_POST['img_default'];
        if (isset($gallery[$di]['url'])) { $defaultUrl = (string) $gallery[$di]['url']; }
    }
    $defaultChosen = isset($_POST['img_default']);
    // 1) Deletes — img_delete[] carries the ORIGINAL integer indices to remove.
    $toDelete = array_map('intval', (array) ($_POST['img_delete'] ?? []));
    if (!empty($toDelete)) {
        $projectRoot = realpath(__DIR__ . '/../../../');
        $kept = [];
        foreach ($gallery as $idx => $im) {
            if (in_array((int) $idx, $toDelete, true)) {
                $galleryChanged = true;
                $url = (string) ($im['url'] ?? '');
                // Only unlink inside our uploads dir (never follow an arbitrary path).
                if ($url !== '' && strpos($url, '/uploads/hotels/') === 0 && $projectRoot) {
                    $abs = $projectRoot . $url;
                    if (is_file($abs)) { @unlink($abs); }
                }
                continue;
            }
            $kept[] = $im;
        }
        $gallery = array_values($kept);
    }
    // 2) New uploads — validate against the app limits (specific per-file messages),
    //    append the valid ones. Any rejections are surfaced after the save so the
    //    supplier's other edits (deletes, text) are not lost.
    $uploadErrors = [];
    $newBatch = _supplier_stays_process_gallery(false); // don't auto-default (edit decides below)
    if (!empty($newBatch['images'])) {
        foreach ($newBatch['images'] as $im) { $gallery[] = $im; }
        $galleryChanged = true;
    }
    if (!empty($newBatch['errors'])) { $uploadErrors = $newBatch['errors']; }
    // 3) Default image — flag by the URL resolved above (survives the re-indexing).
    //    If the chosen default was itself deleted, or none chosen, fall back to first.
    if (!empty($gallery)) {
        $haveDefault = false;
        foreach ($gallery as $idx => &$im) {
            $isDef = ($defaultUrl !== '' && (string) ($im['url'] ?? '') === $defaultUrl);
            $im['default'] = $isDef;
            if ($isDef) { $haveDefault = true; }
        }
        unset($im);
        if (!$haveDefault) { $gallery[0]['default'] = true; } // never leave zero defaults
        if ($defaultChosen) { $galleryChanged = true; }
    }

    // NOTE: user_id is NOT in the update set — ownership can never be reassigned
    // by a supplier. A material edit could reset listing_status to 'submitted'
    // for re-review; Phase-1 keeps the existing listing_status (approval in inc 7).
    // UNIQUE slug across stays, excluding THIS property (a no-op rename keeps its slug;
    // a rename that collides with another hotel gets a -2 suffix instead of clashing).
    $newSlug = function_exists('stays_unique_slug')
        ? stays_unique_slug($db, $name, (int) $id)
        : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    $data = [
        'name'            => $name,
        'accommodation_type' => $accType,
        'cleaning_fee'    => ($_POST['cleaning_fee'] ?? '') !== '' ? max(0, round((float) $_POST['cleaning_fee'], 2)) : null,
        'security_deposit'=> ($_POST['security_deposit'] ?? '') !== '' ? max(0, round((float) $_POST['security_deposit'], 2)) : null,
        'desc'            => trim($_POST['description'] ?? '') ?: null,
        'slug'            => $newSlug,
        'location'        => $location,
        'location_coords' => ($lat !== '' && $lng !== '') ? ($lat . ',' . $lng) : null,
        'address'         => trim($_POST['address'] ?? ''),
        'stars'           => ($v = intval($_POST['stars'] ?? 0)) > 0 ? $v : null,
        'currency'        => _supplier_stays_currency($_POST['currency'] ?? null),
        'discount'        => ($v = intval($_POST['discount'] ?? 0)) > 0 ? $v : null,
        'email'           => trim($_POST['email'] ?? '') ?: null,
        'phone'           => trim($_POST['phone'] ?? '') ?: null,
        'website'         => trim($_POST['website'] ?? '') ?: null,
        'checkin_time'    => trim($_POST['checkin_time'] ?? '14:00:00'),
        'checkout_time'   => trim($_POST['checkout_time'] ?? '12:00:00'),
        'refundable'      => isset($_POST['refundable']) ? 1 : 0,
        'amenity_ids'     => trim($_POST['amenity_ids'] ?? '[]'),
        'cancellation_policy' => trim($_POST['cancellation_policy'] ?? '') ?: null,
        'house_rules'     => function_exists('house_rules_normalize') ? house_rules_normalize($_POST) : null,
        'timezone'        => (function_exists('property_timezone_valid') && property_timezone_valid((string) ($_POST['timezone'] ?? ''))) ? (trim((string) ($_POST['timezone'] ?? '')) ?: null) : null,
        'updated_at'      => date('Y-m-d H:i:s'),
    ];
    // Only rewrite img when the gallery actually changed (delete/add/default), so a
    // plain text edit never touches the stored images.
    if ($galleryChanged) {
        $data['img'] = !empty($gallery) ? json_encode(array_values($gallery)) : null;
    }
    // LOGO — only touch stays.logo when a new logo file was uploaded this request;
    // otherwise leave the column as-is (a text edit must not clear the logo). Guarded
    // by the column check so an un-migrated install never throws here.
    if (_supplier_stays_col_exists($db, 'stays', 'logo')) {
        $existingLogo = $db->get('stays', 'logo', ['id' => (int) $id, 'user_id' => $owner]);
        $exLogo = is_string($existingLogo) ? $existingLogo : null;
        [$newLogo, $logoErr] = _supplier_stays_handle_logo($db, $exLogo);
        if ($logoErr !== '') { $uploadErrors[] = $logoErr; }
        if ($newLogo !== $exLogo) { $data['logo'] = $newLogo; }
    }

    try {
        // Scope the UPDATE by both id AND owner — defense in depth behind supplier_can.
        $db->update('stays', $data, ['id' => (int) $id, 'user_id' => $owner]);
        // Keep the branded <slug>.<root> hostname in lockstep with the (possibly renamed)
        // slug — otherwise the old host keeps resolving to this property and the new slug
        // resolves to nothing. No-op when the slug/host is unchanged or the feature is off.
        if (function_exists('supplier_site_sync_hostname')) {
            supplier_site_sync_hostname($db, (int) $id, $newSlug);
        }
        if (!empty($uploadErrors)) {
            // Text/deletes saved, but some uploads were rejected — say both, clearly.
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Property saved, but some images were not added: ' . implode(' ', $uploadErrors)];
        } else {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Property updated.'];
        }
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

    // SELLABILITY GUARD: don't let an empty/unsellable listing go to admin review.
    // Require at least one ACTIVE room with at least one ACTIVE rate option priced > 0.
    $sellable = false;
    try {
        $rooms = $db->select('stays_rooms', ['id', 'room_options'], ['stay_id' => (int) $id, 'status' => 1]) ?: [];
        foreach ($rooms as $r) {
            $opts = json_decode((string) ($r['room_options'] ?? ''), true);
            if (!is_array($opts)) { continue; }
            foreach ($opts as $o) {
                if ((int) ($o['status'] ?? 0) === 1 && (float) ($o['price'] ?? 0) > 0) { $sellable = true; break 2; }
            }
        }
    } catch (\Throwable $e) { error_log('submit sellability check: ' . $e->getMessage()); }
    if (!$sellable) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Add at least one active room with a priced, active rate before submitting for approval.'];
        header('Location: ' . root . 'supplier/stays/' . (int) $id . '/rooms');
        exit;
    }

    try {
        $db->update('stays',
            ['listing_status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => (int) $id, 'user_id' => $owner]
        );
        // Event bus (inc S17): audit + optional dispatch. Org resolved for scope.
        if (function_exists('emit_event')) {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, (int) $id) : 0;
            emit_event($db, 'property.submitted', ['stay_id' => (int) $id],
                'stays', (int) $id, $orgId > 0 ? $orgId : null);
        }
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
    // Select * so a not-yet-migrated room_name column never breaks the query; the view
    // reads room_name defensively.
    $rooms = $db->select('stays_rooms', '*',
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

    // Property structure for the physical-room manager: buildings, floors (grouped by
    // building), and the existing physical rooms (with their structure labels).
    $buildings = function_exists('building_list') ? building_list($db, (int) $id) : [];
    $floorsByBuilding = [];
    if (function_exists('floor_list')) {
        foreach (floor_list($db, (int) $id) as $fl) { $floorsByBuilding[(int) $fl['building_id']][] = $fl; }
    }
    $physicalRooms = [];
    try {
        $physicalRooms = $db->select('stays_physical_rooms',
            ['id', 'room_id', 'room_number', 'floor', 'floor_id', 'building_id', 'wing', 'hk_status', 'active'],
            ['stay_id' => (int) $id, 'ORDER' => ['building_id' => 'ASC', 'room_number' => 'ASC']]) ?: [];
    } catch (\Throwable $e) { error_log('rooms physical list: ' . $e->getMessage()); }

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
    // Room photos through the hardened helper (app limits + resize + specific errors).
    // Flag the first as default only when the room has none yet.
    $roomBatch = _supplier_stays_process_gallery(empty($roomImages), 'room_images', 'rooms', 'room');
    if (!empty($roomBatch['images'])) {
        foreach ($roomBatch['images'] as $im) { $roomImages[] = $im; }
    }
    // Don't lose the room save over a rejected photo — append the reasons to the result.
    $roomImgErrSuffix = !empty($roomBatch['errors']) ? ' Some photos were not added: ' . implode(' ', $roomBatch['errors']) : '';

    $data = [
        'stay_id'      => $stayId,                         // FORCED to this property
        'room_type_id' => $roomTypeId,
        'amenities'    => json_encode($amenities),
        'status'       => $status,
        'room_images'  => !empty($roomImages) ? json_encode($roomImages) : null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];
    // Guest-facing display name (optional) — only write when the column exists, so an
    // un-migrated DB never errors. Empty display name falls back to the category name.
    if (function_exists('_supplier_stays_col_exists') && _supplier_stays_col_exists($db, 'stays_rooms', 'room_name')) {
        $rn = trim((string) ($_POST['room_name'] ?? ''));
        $data['room_name'] = $rn !== '' ? mb_substr($rn, 0, 120) : null;
    }
    // WHITE-LABEL Phase 2b — rich room fields for the branded site. Each column-guarded so an
    // un-migrated DB never errors; all optional.
    $ce = function_exists('_supplier_stays_col_exists');
    if ($ce && _supplier_stays_col_exists($db, 'stays_rooms', 'description')) {
        $rd = trim((string) ($_POST['room_description'] ?? ''));
        $data['description'] = $rd !== '' ? mb_substr($rd, 0, 2000) : null;
    }
    if ($ce && _supplier_stays_col_exists($db, 'stays_rooms', 'size_sqm')) {
        $sz = trim((string) ($_POST['room_size_sqm'] ?? ''));
        $data['size_sqm'] = ($sz !== '' && is_numeric($sz)) ? max(0, min(99999.99, round((float) $sz, 2))) : null;
    }
    if ($ce && _supplier_stays_col_exists($db, 'stays_rooms', 'bed_config')) {
        $data['bed_config'] = function_exists('wl_bed_config_normalize') ? wl_bed_config_normalize($_POST['beds'] ?? null) : null;
    }
    if ($ce && _supplier_stays_col_exists($db, 'stays_rooms', 'view_type')) {
        $vt = strtolower(trim((string) ($_POST['room_view_type'] ?? '')));
        // Accept a known key, or 'none'/'' -> null. (Open-ended but length-capped.)
        $data['view_type'] = ($vt === '' || $vt === 'none') ? null : mb_substr(preg_replace('/[^a-z0-9_-]/', '', $vt), 0, 40);
    }

    try {
        if ($isEdit) {
            // Preserve room_options (managed by the options endpoints, not here).
            $db->update('stays_rooms', $data, ['id' => $roomId, 'stay_id' => $stayId]);
            $_SESSION['message'] = ['type' => $roomImgErrSuffix ? 'error' : 'success', 'text' => 'Room updated.' . $roomImgErrSuffix];
        } else {
            $data['room_options'] = null;
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('stays_rooms', $data);
            $_SESSION['message'] = ['type' => $roomImgErrSuffix ? 'error' : 'success', 'text' => 'Room added. Now add a rate/option so it can sell.' . $roomImgErrSuffix];
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

    // board_id must be a real 'board' taxonomy id (0 = none). Reject an arbitrary FK
    // so public pages / rate-plan joins never dereference a bogus board.
    if ($fields['board_id'] > 0 && !$db->has('stays_settings', ['id' => $fields['board_id'], 'setting_type' => 'board'])) {
        $fields['board_id'] = 0;
    }

    // A rate cannot be ACTIVE while unsellable (no price or no stock) — otherwise it
    // shows "active" to the supplier but the hold engine rejects every booking. Force it
    // off and tell them, keeping the UI and the sell path consistent (matches the submit
    // sellability guard which requires an active rate priced > 0).
    $rateUnsellable = ($fields['status'] === 1 && ($fields['price'] <= 0 || $fields['available_quantity'] <= 0));
    if ($rateUnsellable) { $fields['status'] = 0; }

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
        if ($rateUnsellable) {
            $msg .= ' Note: it was saved as INACTIVE because it has no ' . ($fields['price'] <= 0 ? 'price' : 'availability') . ' — set that, then activate it.';
            $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        } else {
            $_SESSION['message'] = ['type' => 'success', 'text' => $msg];
        }
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
    // Full row for the translations editor (needs the existing translations JSON).
    $stayFull = $db->get('stays', '*', ['id' => (int) $id]) ?: $stay;

    // Ensure a stays_site row exists AND its branded <slug>.<root> hostname matches the
    // current slug (collision-safe, self-healing). Also repairs any row whose hostname
    // drifted from the slug before this sync existed. Custom-domain fields are untouched.
    if (function_exists('supplier_site_sync_hostname')) {
        supplier_site_sync_hostname($db, (int) $id, (string) ($stay['slug'] ?? ''));
    }
    $site = $db->get('stays_site', '*', ['stay_id' => (int) $id]);

    $brandedRoot = supplier_site_branded_root();
    // Custom branded-site pages (About/Contact/Policies/FAQ…) — owner editor sees drafts too.
    $sitePages = function_exists('sitepages_list') ? sitepages_list($db, (int) $id, false) : [];
    $title = 'Site & Domain';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/site.php";
    require_once views . "includes/footer.php";
});

// GET /supplier/stays/site/{id}/preview — render the property's branded booking
// page (owner-scoped). Host-based serving on the branded subdomain / verified custom
// domain is live via supplier_site_maybe_serve() + the TLS edge (deploy/Caddyfile).
$router->get('/supplier/stays/site/([0-9]+)/preview', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }

    $stay = $db->get('stays', '*', ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $site = $db->get('stays_site', '*', ['stay_id' => (int) $id]);
    $stay['_slug'] = (string) ($stay['slug'] ?? '');

    // Rooms (full columns for the rich template) + stable option ids.
    $rooms = $db->select('stays_rooms', '*', ['stay_id' => (int) $id, 'status' => 1]) ?: [];
    if (function_exists('stays_room_option_ids')) {
        foreach ($rooms as &$__r) { $__r['_options'] = stays_room_option_ids($db, (int) $__r['id'], (int) $id); }
        unset($__r);
    }
    // Room-type + amenity name maps (same as the public render).
    $roomTypeNames = [];
    $roomAmenityNames = [];
    try {
        foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type', 'status' => 1]) ?: [] as $rt) { $roomTypeNames[(int) $rt['id']] = (string) ($rt['name'] ?? ''); }
        foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_amenity', 'status' => 1]) ?: [] as $ra) { $roomAmenityNames[(int) $ra['id']] = (string) ($ra['name'] ?? ''); }
    } catch (\Throwable $e) {}
    $sitePages = function_exists('sitepages_list') ? sitepages_list($db, (int) $id, true) : [];
    $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode(is_array($site) ? ($site['theme'] ?? null) : null) : [];

    // G6 — LIVE PREVIEW override: ?template=X renders a DIFFERENT template with the hotel's real
    // data WITHOUT saving, so the supplier can compare templates before committing. Sanitized to a
    // known template id; anything else is ignored (keeps the saved template).
    $__pv = trim((string) ($_GET['template'] ?? ''));
    if ($__pv !== '' && function_exists('wl_template_valid') && wl_template_valid($__pv)) {
        $theme['template'] = $__pv;
    }

    // Owner preview = the EXACT standalone template the public will see (no platform chrome).
    require views . "supplier/stays/site/_layout.php";
});

// POST /supplier/stays/site-theme — save the site-builder theme (MODULE 13). Distinct
// literal path so it never collides with the numeric-id /site/{id} domain route.
$router->post('/supplier/stays/site-theme', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/stays/site/' . $stayId;
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // G7 — HERO + FAVICON uploads. Process any uploaded files (hardened path), then inject the
    // resulting /uploads/ URLs into $_POST so sitebuilder_normalize persists hero_url/favicon_url.
    // When nothing is uploaded, carry the EXISTING value forward (so a plain save doesn't clear it).
    $uploadErr = [];
    $curTheme = [];
    if (function_exists('sitebuilder_decode')) {
        $siteRow = $db->get('stays_site', ['theme'], ['stay_id' => $stayId]);
        $curTheme = sitebuilder_decode(is_array($siteRow) ? ($siteRow['theme'] ?? null) : null);
    }
    if (function_exists('_supplier_stays_handle_theme_image')) {
        [$heroUrl, $heroErr] = _supplier_stays_handle_theme_image('hero_image', 'hero', ['jpg', 'jpeg', 'png', 'webp']);
        if ($heroErr !== '') { $uploadErr[] = $heroErr; }
        $_POST['hero_url'] = $heroUrl !== null ? $heroUrl : (string) ($curTheme['hero_url'] ?? '');

        [$favUrl, $favErr] = _supplier_stays_handle_theme_image('favicon_image', 'favicon', ['png', 'ico', 'jpg', 'jpeg', 'webp']);
        if ($favErr !== '') { $uploadErr[] = $favErr; }
        $_POST['favicon_url'] = $favUrl !== null ? $favUrl : (string) ($curTheme['favicon_url'] ?? '');
    }

    $ok = function_exists('sitebuilder_save') && sitebuilder_save($db, $stayId, $_POST);
    if (!empty($uploadErr)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Design saved, but some images were not added: ' . implode(' ', $uploadErr)];
    } else {
        $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Site design saved.' : 'Could not save.'];
    }
    header('Location: ' . $back); exit;
});

// POST /supplier/stays/site-request — request admin activation of the white-label site
// (Phase 0). Owner-scoped + CSRF. Moves site_status none/rejected -> requested.
$router->post('/supplier/stays/site-request', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/stays/site/' . $stayId;
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('supplier_site_request')
        ? supplier_site_request($db, $stayId)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Error'];
    header('Location: ' . $back); exit;
});

// POST /supplier/stays/site-translations — save per-language translations (Phase 4).
// Read-merge-write so it never clobbers languages/fields set elsewhere (e.g. admin). Owner +
// CSRF gated. Shape matches the admin writer: stays.translations = {lang:{field:value}}.
$router->post('/supplier/stays/site-translations', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/stays/site/' . $stayId;
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $fields = function_exists('wl_lang_fields') ? wl_lang_fields() : ['name', 'desc', 'address', 'cancellation_policy', 'privacy_policy'];
    $incomingJson = function_exists('wl_stay_translations_normalize') ? wl_stay_translations_normalize($_POST, $fields) : null;
    $incoming = $incomingJson ? (json_decode($incomingJson, true) ?: []) : [];

    try {
        // Merge: start from what exists, overlay the submitted languages. A submitted language
        // with no non-empty fields removes that language (supplier cleared it).
        $existing = [];
        $cur = $db->get('stays', ['translations'], ['id' => $stayId]);
        if ($cur && !empty($cur['translations'])) { $existing = json_decode((string) $cur['translations'], true) ?: []; }
        // Only the languages the form offered (non-en enabled) are under supplier control here.
        $offered = [];
        foreach (($GLOBALS['languages'] ?? []) as $lg) { $c = strtolower((string) ($lg['lang_code'] ?? '')); if ($c !== '' && $c !== 'en') { $offered[$c] = true; } }
        foreach (array_keys($offered) as $lc) {
            if (!empty($incoming[$lc])) { $existing[$lc] = $incoming[$lc]; }
            else { unset($existing[$lc]); } // cleared
        }
        $db->update('stays', ['translations' => !empty($existing) ? json_encode($existing) : null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $stayId, 'user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Translations saved.'];
    } catch (\Throwable $e) {
        error_log('supplier site-translations: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save translations.'];
    }
    header('Location: ' . $back); exit;
});

// POST /supplier/stays/site-page — create/update a custom branded-site page (MODULE 13).
// Literal path (no numeric collision with /site/{id}); stay_id carried in the body.
$router->post('/supplier/stays/site-page', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/stays/site/' . $stayId;
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('sitepages_save')
        ? sitepages_save($db, $stayId, $_POST)
        : ['ok' => false, 'message' => 'Pages unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Error'];
    header('Location: ' . $back); exit;
});

// POST /supplier/stays/site-page/delete — remove a custom page (scoped to the property).
$router->post('/supplier/stays/site-page/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $pageId = (int) ($_POST['page_id'] ?? 0);
    $back = root . 'supplier/stays/site/' . $stayId;
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('sitepages_delete') && sitepages_delete($db, $stayId, $pageId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Page deleted.' : 'Could not delete.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/stays/site/{id}/book — take a DIRECT / walk-in booking (inc S27).
// Desk action (supplier_can reservations edit). Server-priced + pooled-inventory hold.
$router->post('/supplier/stays/site/([0-9]+)/book', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('That property is not yours.'); }
    $back = root . 'supplier/stays/site/' . $stayId . '/preview';

    if (!function_exists('direct_booking_create')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Direct booking unavailable.'];
        header('Location: ' . $back); exit;
    }
    $res = direct_booking_create($db, $stayId,
        (int) ($_POST['room_id'] ?? 0), (int) ($_POST['option_id'] ?? 0),
        (string) ($_POST['checkin'] ?? ''), (string) ($_POST['checkout'] ?? ''),
        [
            'first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '',
            'email' => $_POST['email'] ?? '', 'phone' => $_POST['phone'] ?? '',
            'adults' => (int) ($_POST['adults'] ?? 1), 'childs' => (int) ($_POST['childs'] ?? 0),
            'special_requests' => $_POST['special_requests'] ?? '',
        ],
        max(1, (int) ($_POST['qty'] ?? 1)));
    if (!empty($res['ok'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Direct booking created (' . htmlspecialchars((string) $res['invoice_id']) . '). Manage it under Reservations.'];
        header('Location: ' . root . 'supplier/reservations/' . rawurlencode((string) $res['invoice_id'])); exit;
    }
    $_SESSION['message'] = ['type' => 'error', 'text' => $res['message'] ?? 'Could not create the booking.'];
    header('Location: ' . $back); exit;
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

    // IDEMPOTENCY GUARD: re-saving the SAME domain that is already verified/active must
    // NOT knock it back to 'pending' with a fresh token — that would take a live HTTPS
    // site offline until re-verification, for no reason (e.g. a double-click, or editing
    // an unrelated field and resubmitting). Only a genuine CHANGE (or a first set / reset
    // from a not-yet-verified state) mints a new token and resets to 'pending'.
    $current = $db->get('stays_site', ['custom_domain', 'domain_status'], ['stay_id' => (int) $id]);
    $sameDomain  = $current && strtolower((string) ($current['custom_domain'] ?? '')) === $domain;
    $alreadyLive = in_array((string) ($current['domain_status'] ?? 'none'), ['verified', 'active'], true);
    if ($sameDomain && $alreadyLive) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'That domain is already set up and verified — no changes made.'];
        header('Location: ' . $back); exit;
    }

    // Record the request: status 'pending' + a verification token. DNS verification runs
    // next (Verify now); TLS issuance is the edge step (deploy/Caddyfile). This stores the
    // intent + the TXT value the supplier must publish.
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

// POST /supplier/stays/site/{id}/verify-domain — check the hotel's DNS and flip
// pending→verified when the TXT token + CNAME/A record are present. Polling-friendly.
$router->post('/supplier/stays/site/([0-9]+)/verify-domain', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $back = root . 'supplier/stays/site/' . (int) $id;

    $operatorIp = trim((string) ($GLOBALS['app']['supplier_site_ip'] ?? ''));
    $res = function_exists('supplier_site_verify_domain')
        ? supplier_site_verify_domain($db, (int) $id, $operatorIp)
        : ['ok' => false, 'message' => 'Verification unavailable.'];
    // If DNS just verified, opportunistically probe HTTPS — the edge may already have a
    // cert (a first visit triggers on-demand issuance). If live, this flips verified→active
    // in one step so the supplier sees "Live" without a second button press.
    if (!empty($res['ok']) && ($res['status'] ?? '') === 'verified' && function_exists('supplier_site_activate_domain')) {
        $act = supplier_site_activate_domain($db, (int) $id);
        if (!empty($act['ok']) && ($act['status'] ?? '') === 'active') {
            $res = $act;
        }
    }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not verify.'];
    header('Location: ' . $back);
    exit;
});

// POST /supplier/stays/site/{id}/activate-domain — probe real HTTPS and flip
// verified→active once the edge's certificate is live. Polling-friendly (idempotent).
$router->post('/supplier/stays/site/([0-9]+)/activate-domain', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'edit', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $back = root . 'supplier/stays/site/' . (int) $id;

    $res = function_exists('supplier_site_activate_domain')
        ? supplier_site_activate_domain($db, (int) $id)
        : ['ok' => false, 'message' => 'Activation unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not activate.'];
    header('Location: ' . $back);
    exit;
});

// ============================================================================
// MODULE 02 — BUILDINGS / BLOCKS / WINGS registry (property structure)
// GET  /supplier/stays/{id}/buildings      — manage a property's buildings
// POST /supplier/stays/buildings/add|update|delete
// Owner/staff gated + supplier_can('hotels', …) on the property.
// ============================================================================
$router->get('/supplier/stays/([0-9]+)/buildings', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $buildings = function_exists('building_list') ? building_list($db, (int) $id) : [];
    // Floors grouped by building_id for the hierarchy view.
    $floorsByBuilding = [];
    if (function_exists('floor_list')) {
        foreach (floor_list($db, (int) $id) as $fl) {
            $floorsByBuilding[(int) $fl['building_id']][] = $fl;
        }
    }
    $canEdit = supplier_can($db, 'hotels', 'edit', (int) $id);
    $stayId = (int) $id;
    $title = ($stay['name'] ?? 'Property') . ' — Buildings'; $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/buildings.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/stays/buildings/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    $ok = function_exists('building_add') && building_add($db, $org, $stayId, $_POST) > 0;
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Building added.' : 'Could not add (name required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/buildings/update', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('building_update') && building_update($db, $stayId, (int) ($_POST['building_id'] ?? 0), $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Building updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/buildings/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('building_delete') && building_delete($db, $stayId, (int) ($_POST['building_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Building deleted (floors + room links removed).' : 'Could not delete.'];
    header('Location: ' . $back); exit;
});

// --- FLOORS (first-class levels under a building) --------------------------------
$router->post('/supplier/stays/floors/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    $ok = function_exists('floor_add') && floor_add($db, $org, $stayId, (int) ($_POST['building_id'] ?? 0), $_POST) > 0;
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Floor added.' : 'Could not add floor (name + building required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/floors/update', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('floor_update') && floor_update($db, $stayId, (int) ($_POST['floor_id'] ?? 0), $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Floor updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/floors/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/buildings';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('floor_delete') && floor_delete($db, $stayId, (int) ($_POST['floor_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Floor deleted (room links removed).' : 'Could not delete.'];
    header('Location: ' . $back); exit;
});
