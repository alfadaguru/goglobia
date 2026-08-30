<?php
// ============================================================================
// FILE: app/routes/admin/busRoutes.php — ADMIN: BUS SERVICES, OPERATORS, ROUTES
// Local manual inventory (crud() lists + component forms + AJAX route modal).
// ============================================================================
@$SECURE or die('Access Denied!');

// HELPERS
if (!function_exists('busSettings')) {
    function busSettings($db, $type) {
        return $db->select('bus_settings', ['id', 'name'], ['setting_type' => $type, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: [];
    }
}
if (!function_exists('busDefaultCurrency')) {
    function busDefaultCurrency($db) {
        $c = $db->get('currencies', 'name', ['default' => '1']);
        return $c ?: 'USD';
    }
}
if (!function_exists('busUploadImage')) {
    // UPLOAD AN IMAGE TO {relDir} (e.g. 'uploads/bus'). RETURNS RELATIVE PATH OR NULL.
    function busUploadImage($fileKey, $relDir) {
        if (empty($_FILES[$fileKey]['tmp_name']) || !is_uploaded_file($_FILES[$fileKey]['tmp_name'])) return null;
        $f = $_FILES[$fileKey];
        if (($f['size'] ?? 0) > 5 * 1024 * 1024) return null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (strpos((string)$mime, 'image/') !== 0) return null;
        $relDir = trim($relDir, '/');
        $absDir = __DIR__ . '/../../../' . $relDir; // app/routes/admin → project root
        if (!is_dir($absDir)) @mkdir($absDir, 0755, true);
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $absDir . '/' . $name)) return null;
        return $relDir . '/' . $name;
    }
}
if (!function_exists('busRoutesForBus')) {
    // ROUTES LIST (NORMALIZED) FOR A BUS — USED BY MANAGE PAGE + AJAX RESPONSES
    function busRoutesForBus($db, $busId) {
        $rows = $db->select('bus_routes', '*', ['bus_id' => (int)$busId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        foreach ($rows as &$r) {
            $r['calendar_count'] = $db->count('bus_routes_calendar', ['route_id' => (int)$r['id']]);
        }
        return $rows;
    }
}
if (!function_exists('busBuildScheduleDates')) {
    // BUILD DATES (Y-m-d[]) FROM POSTED SCHEDULE
    function busBuildScheduleDates($post) {
        $toIso = function ($dmy) {
            $d = DateTime::createFromFormat('d-m-Y', trim((string)$dmy));
            return $d ? $d->format('Y-m-d') : null;
        };
        $type = $post['schedule_type'] ?? 'date';
        $dates = [];
        if ($type === 'range') {
            $from = $toIso($post['sched_from'] ?? ''); $to = $toIso($post['sched_to'] ?? '');
            if ($from && $to) {
                $cur = new DateTime($from); $end = new DateTime($to); $g = 0;
                while ($cur <= $end && $g < 730) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); $g++; }
            }
        } elseif ($type === 'weekday') {
            $wds = array_filter(array_map('intval', (array)($post['sched_weekday'] ?? [])));
            $h = 180; // FIXED HORIZON (~6 MONTHS)
            if ($wds) {
                $cur = new DateTime('today');
                for ($i = 0; $i < $h; $i++) { if (in_array((int)$cur->format('N'), $wds, true)) $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }
            }
        } elseif ($type === 'date') {
            $d = $toIso($post['sched_date'] ?? ''); if ($d) $dates[] = $d;
        }
        return $dates;
    }
}

// ============================ LIST BUS SERVICES
$router->get(admin . '/bus', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $title = (T::bus ?? 'Bus') . ' ' . (T::management ?? 'Management');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/buses.php";
    require_once views . "includes/footer.php";
});

// ============================ OPERATORS: LIST
$router->get(admin . '/bus/operators', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $title = (T::bus ?? 'Bus') . ' ' . (T::operators ?? 'Operators');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/operators.php";
    require_once views . "includes/footer.php";
});

// ============================ OPERATORS: MANAGE (ADD + EDIT)
$router->get(admin . '/bus/operators/manage', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $mode = 'add'; $op = [];
    $title = (T::add ?? 'Add') . ' ' . (T::operator ?? 'Operator');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/operator.php";
    require_once views . "includes/footer.php";
});
$router->get(admin . '/bus/operators/manage/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    $op = $db->get('bus_operators', '*', ['id' => (int)$id]);
    if (!$op) { redirect(root . admin . '/bus/operators'); return; }
    $mode = 'edit';
    $title = (T::edit ?? 'Edit') . ' ' . (T::operator ?? 'Operator');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/operator.php";
    require_once views . "includes/footer.php";
});

// ============================ OPERATORS: SAVE
$router->post(admin . '/bus/operators/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid form submission'; redirect(root . admin . '/bus/operators'); return;
    }
    $id = (int)($_POST['id'] ?? 0);
    $data = [
        'operator_name' => trim($_POST['operator_name'] ?? ''),
        'company_name'  => trim($_POST['company_name'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'phone'         => trim($_POST['phone'] ?? ''),
        'markup_type_b2b' => ($_POST['markup_type_b2b'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage',
        'markup_b2b'      => (float)($_POST['markup_b2b'] ?? 0),
        'markup_type_b2c' => ($_POST['markup_type_b2c'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage',
        'markup_b2c'      => (float)($_POST['markup_b2c'] ?? 0),
        'status'        => ($_POST['status'] ?? '1') === '1' ? '1' : '0',
    ];
    $opImg = busUploadImage('img', 'uploads/bus/operators');
    if ($opImg) { $data['img'] = $opImg; }
    if ($data['operator_name'] === '' && $data['company_name'] === '') {
        $_SESSION['error'] = (T::name ?? 'Name') . ' ' . (T::is ?? 'is') . ' ' . (T::required ?? 'required');
        redirect(root . admin . '/bus/operators/manage' . ($id ? '/' . $id : '')); return;
    }
    try {
        if ($id) { $db->update('bus_operators', $data, ['id' => $id]); $_SESSION['success'] = (T::operator ?? 'Operator') . ' ' . (T::updated ?? 'updated'); }
        else { $db->insert('bus_operators', $data); $_SESSION['success'] = (T::operator ?? 'Operator') . ' ' . (T::created ?? 'created'); }
    } catch (\Throwable $e) { error_log('BUS_OP_SAVE: ' . $e->getMessage()); $_SESSION['error'] = (T::failed ?? 'Failed'); }
    redirect(root . admin . '/bus/operators');
});

// ============================ OPERATORS: DELETE
$router->get(admin . '/bus/operators/delete/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    try { $db->delete('bus_operators', ['id' => (int)$id]); $_SESSION['success'] = (T::operator ?? 'Operator') . ' ' . (T::deleted ?? 'deleted'); }
    catch (\Throwable $e) { $_SESSION['error'] = (T::failed ?? 'Failed'); }
    redirect(root . admin . '/bus/operators');
});

// ============================ BUS: MANAGE (ADD + EDIT ON ONE PAGE)
$router->get(admin . '/bus/manage', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $mode = 'add'; $bus = []; $routes = [];
    $busTypes = busSettings($db, 'bus_type');
    $amenities = busSettings($db, 'amenity');
    $seatClasses = busSettings($db, 'seat_class');
    $operators = $db->select('bus_operators', ['id', 'operator_name', 'company_name'], ['status' => '1', 'ORDER' => ['company_name' => 'ASC']]) ?: [];
    $defaultCurrency = busDefaultCurrency($db);
    $title = (T::add ?? 'Add') . ' ' . (T::bus ?? 'Bus');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/manage.php";
    require_once views . "includes/footer.php";
});
$router->get(admin . '/bus/manage/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    $bus = $db->get('bus', '*', ['id' => (int)$id]);
    if (!$bus) { redirect(root . admin . '/bus'); return; }
    $mode = 'edit';
    $routes = busRoutesForBus($db, (int)$id);
    $busTypes = busSettings($db, 'bus_type');
    $amenities = busSettings($db, 'amenity');
    $seatClasses = busSettings($db, 'seat_class');
    $operators = $db->select('bus_operators', ['id', 'operator_name', 'company_name'], ['status' => '1', 'ORDER' => ['company_name' => 'ASC']]) ?: [];
    $defaultCurrency = busDefaultCurrency($db);
    $title = (T::edit ?? 'Edit') . ' ' . (T::bus ?? 'Bus');
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/bus/manage.php";
    require_once views . "includes/footer.php";
});

// LEGACY → MANAGE
$router->get(admin . '/bus/([0-9]+)/routes', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH(); redirect(root . admin . '/bus/manage/' . (int)$id);
});

// ROUTES TABLE ONLY (HTML) — FOR AJAX PARTIAL REFRESH AFTER MODAL SAVE
$router->get(admin . '/bus/([0-9]+)/routes-table', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    $b = $db->get('bus', ['id', 'currency'], ['id' => (int)$id]);
    if (!$b) { echo ''; return; }
    $curr = $b['currency'] ?: busDefaultCurrency($db);
    require "app/views/admin/bus/_routes_table.php";
});

// ============================ BUS: SAVE (operator_id, default currency, no rating)
$router->post(admin . '/bus/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid form submission'; redirect(root . admin . '/bus'); return;
    }
    $id = (int)($_POST['id'] ?? 0);
    $amenityIds = array_values(array_filter(array_map('intval', (array)($_POST['amenity_ids'] ?? []))));
    $operatorId = (int)($_POST['operator_id'] ?? 0);
    $operatorName = '';
    if ($operatorId) {
        $opRow = $db->get('bus_operators', ['company_name', 'operator_name'], ['id' => $operatorId]);
        $operatorName = $opRow['company_name'] ?: ($opRow['operator_name'] ?? '');
    }

    $data = [
        'name'        => trim($_POST['name'] ?? ''),
        'operator'    => $operatorName,
        'operator_id' => $operatorId ?: null,
        'bus_type'    => trim($_POST['bus_type'] ?? ''),
        'amenity_ids' => json_encode($amenityIds),
        'currency'    => busDefaultCurrency($db),
        'refundable'  => ($_POST['refundable'] ?? '1') === '1' ? '1' : '0',
        'featured'    => ($_POST['featured'] ?? '0') === '1' ? '1' : '0',
        'status'      => ($_POST['status'] ?? '1') === '1' ? '1' : '0',
        'cancellation_policy' => trim($_POST['cancellation_policy'] ?? ''),
    ];
    $busImg = busUploadImage('img', 'uploads/bus');
    if ($busImg) { $data['img'] = $busImg; }
    if ($data['name'] === '') {
        $_SESSION['error'] = (T::name ?? 'Name') . ' ' . (T::is ?? 'is') . ' ' . (T::required ?? 'required');
        redirect(root . admin . '/bus/manage' . ($id ? '/' . $id : '')); return;
    }
    $data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['name']));
    try {
        if ($id) { $db->update('bus', $data, ['id' => $id]); $_SESSION['success'] = (T::bus ?? 'Bus') . ' ' . (T::updated ?? 'updated'); }
        else { $data['user_id'] = $_SESSION['user_id'] ?? 0; $db->insert('bus', $data); $id = (int)$db->id(); $_SESSION['success'] = (T::bus ?? 'Bus') . ' ' . (T::created ?? 'created'); }
    } catch (\Throwable $e) { error_log('BUS_SAVE: ' . $e->getMessage()); $_SESSION['error'] = (T::failed ?? 'Failed') . ': ' . $e->getMessage(); }
    redirect(root . admin . '/bus/manage/' . $id);
});

// ============================ BUS: DELETE (+ ROUTES + CALENDAR)
$router->get(admin . '/bus/delete/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    $id = (int)$id;
    try {
        $db->delete('bus_routes_calendar', ['bus_id' => $id]);
        $db->delete('bus_routes', ['bus_id' => $id]);
        $db->delete('bus', ['id' => $id]);
        $_SESSION['success'] = (T::bus ?? 'Bus') . ' ' . (T::deleted ?? 'deleted');
    } catch (\Throwable $e) { error_log('BUS_DELETE: ' . $e->getMessage()); $_SESSION['error'] = (T::failed ?? 'Failed'); }
    redirect(root . admin . '/bus');
});

// ============================ ROUTE: AJAX SAVE (MODAL — RETURNS JSON)
$router->post(admin . '/bus/routes/ajax-save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            throw new Exception('Invalid form submission');
        }
        $busId = (int)($_POST['bus_id'] ?? 0);
        $routeId = (int)($_POST['route_id'] ?? 0);
        if (!$busId) throw new Exception('Missing bus');

        $adults = max(0, (int)($_POST['adults'] ?? 0));
        $children = max(0, (int)($_POST['children'] ?? 0));
        $total = max(1, $adults + $children);
        $adultPrice = (float)($_POST['adult_price'] ?? 0);
        $childPrice = (float)($_POST['child_price'] ?? 0);

        $base = [
            'bus_id'         => $busId,
            'origin'         => trim($_POST['origin'] ?? ''),
            'destination'    => trim($_POST['destination'] ?? ''),
            'duration'       => trim($_POST['duration'] ?? ''),
            'seat_class'     => trim($_POST['seat_class'] ?? ''),
            'adults'         => $adults,
            'children'       => $children,
            'total_seats'    => $total,
            'adult_price'    => $adultPrice,
            'child_price'    => $childPrice,
            'base_price'     => $adultPrice,
            'status'         => ($_POST['status'] ?? '1') === '1' ? '1' : '0',
        ];
        if ($base['origin'] === '' || $base['destination'] === '') throw new Exception('Origin & destination required');

        // DEPARTURE TIMES (multiple) + DURATION-DERIVED ARRIVAL
        $times = array_values(array_filter(array_map('trim', explode(',', $_POST['departure_times'] ?? '')), fn($t) => $t !== ''));
        if (empty($times)) { $legacy = trim($_POST['departure_time'] ?? ''); if ($legacy !== '') $times = [$legacy]; }
        if (empty($times)) throw new Exception('Add at least one departure time');
        $times = array_values(array_unique($times));
        sort($times);
        $durMin = max(0, (int)($_POST['duration_minutes'] ?? 0));
        $addMinutes = function ($hhmm, $m) {
            $p = explode(':', $hhmm);
            $t = ((int)($p[0] ?? 0)) * 60 + ((int)($p[1] ?? 0)) + $m;
            $t %= 1440; if ($t < 0) $t += 1440;
            return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
        };

        $dates = busBuildScheduleDates($_POST);
        $targetIds = [];

        // On edit: reuse the existing row for the first time; extra times become new routes.
        $queue = $times;
        if ($routeId) {
            $first = array_shift($queue);
            $db->update('bus_routes', array_merge($base, ['departure_time' => $first, 'arrival_time' => $addMinutes($first, $durMin)]), ['id' => $routeId]);
            $targetIds[] = $routeId;
        }
        foreach ($queue as $tm) {
            $db->insert('bus_routes', array_merge($base, ['departure_time' => $tm, 'arrival_time' => $addMinutes($tm, $durMin)]));
            $targetIds[] = (int)$db->id();
        }

        if (!empty($dates)) {
            foreach ($targetIds as $rid) {
                $db->delete('bus_routes_calendar', ['route_id' => $rid]);
                foreach ($dates as $d) {
                    $db->insert('bus_routes_calendar', ['bus_id' => $busId, 'route_id' => $rid, 'date' => $d, 'price' => $adultPrice, 'seats_available' => $total]);
                }
            }
        } elseif ($routeId) {
            // Keep existing scheduled dates aligned when only the route fare is edited.
            $db->update('bus_routes_calendar', ['price' => $adultPrice], ['route_id' => $routeId]);
        }
        echo json_encode(['success' => true, 'routes' => busRoutesForBus($db, $busId), 'created' => count($targetIds), 'dates' => count($dates), 'csrf' => CSRF::getToken()]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_ROUTE_AJAX_SAVE: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit(0);
    }
});

// ============================ ROUTE: AJAX DELETE (RETURNS JSON)
$router->post(admin . '/bus/routes/ajax-delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) throw new Exception('Invalid form submission');
        $routeId = (int)($_POST['route_id'] ?? 0);
        $busId = (int)($_POST['bus_id'] ?? 0);
        if (!$routeId) throw new Exception('Missing route');
        $db->delete('bus_routes_calendar', ['route_id' => $routeId]);
        $db->delete('bus_routes', ['id' => $routeId]);
        echo json_encode(['success' => true, 'routes' => busRoutesForBus($db, $busId), 'csrf' => CSRF::getToken()]); exit(0);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit(0);
    }
});
