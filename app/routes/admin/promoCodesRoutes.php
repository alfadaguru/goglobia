<?php
// app/routes/admin/promoCodesRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
PROMO CODES ROUTES START
===================================================================*/

// ================================ GET /promo-codes - LIST ALL PROMO CODES
$router->get(admin.'/promo-codes', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::promo_codes_management;
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/promo-codes/promo-codes.php";
    require_once views."includes/footer.php";
});

// ================================ GET /promo-codes/add - ADD NEW PROMO CODE FORM
$router->get(admin.'/promo-codes/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'add';
    
    $title = T::add_promo_code;
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/promo-codes/manage-promo-code.php";
    require_once views."includes/footer.php";
});

// ================================ POST /promo-codes/add - ADD NEW PROMO CODE
$router->post(admin.'/promo-codes/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = floatval($_POST['discount_value'] ?? 0);
    $min_order_amount = !empty($_POST['min_order_amount']) ? floatval($_POST['min_order_amount']) : null;
    $max_discount_amount = !empty($_POST['max_discount_amount']) ? floatval($_POST['max_discount_amount']) : null;
    $usage_limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
    $per_user_limit = !empty($_POST['per_user_limit']) ? intval($_POST['per_user_limit']) : 1;
    $module = $_POST['module'] ?? 'all';
    $target_type = $_POST['target_type'] ?? 'all';
    $target_ids = !empty($_POST['target_ids']) ? json_encode(array_map('intval', (array)$_POST['target_ids'])) : null;
    $target_locations = !empty($_POST['target_locations']) ? json_encode(array_map('intval', (array)$_POST['target_locations'])) : null;
    $start_date = !empty($_POST['start_date']) ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : null;
    $end_date = !empty($_POST['end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null;
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Currency: use posted value or fall back to system default
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    if (empty($currency)) {
        $currency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';
    }
    
    // Reset targeting data when not applicable
    if ($target_type === 'all') {
        $target_ids = null;
        $target_locations = null;
    }
    if ($module === 'all') {
        $target_ids = null; // Can't target specific items across all modules
    }
    
    // Validation
    $errors = [];
    if (empty($code)) $errors[] = T::promo_code_required;
    if ($discount_value <= 0) $errors[] = T::discount_value_must_be_greater_than_0;
    if ($discount_type === 'percentage' && $discount_value > 100) $errors[] = T::percentage_cannot_exceed_100;
    if (!preg_match('/^[A-Z0-9_-]+$/', $code)) $errors[] = T::promo_code_invalid_chars;
    
    // Check for duplicate code
    $existing = $db->get('promo_codes', 'id', ['code' => $code]);
    if ($existing) $errors[] = T::promo_code_already_exists;
    
    // Validate dates
    if ($start_date && $end_date && strtotime($end_date) <= strtotime($start_date)) {
        $errors[] = T::end_date_must_be_after_start_date;
    }
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/promo-codes/add');
        return;
    }
    
    $promo_data = [
        'code' => $code,
        'description' => !empty($description) ? $description : null,
        'discount_type' => $discount_type,
        'discount_value' => $discount_value,
        'currency' => $currency,
        'min_order_amount' => $min_order_amount,
        'max_discount_amount' => $max_discount_amount,
        'usage_limit' => $usage_limit,
        'per_user_limit' => $per_user_limit,
        'module' => $module,
        'target_type' => $target_type,
        'target_ids' => $target_ids,
        'target_locations' => $target_locations,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->insert('promo_codes', $promo_data);
        if ($result) {
            $new_id = $db->id();
            $_SESSION['message'] = ['type' => 'success', 'text' => T::promo_code_created_successfully];
            redirect(root . admin . '/promo-codes/edit/' . $new_id);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_create_promo_code];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/promo-codes/add');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/promo-codes/');
    }
});

// ================================ GET /promo-codes/edit/{id} - EDIT PROMO CODE FORM
$router->get(admin.'/promo-codes/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'edit';
    $promo_id = intval($id);
    
    $promo = $db->get('promo_codes', '*', ['id' => $promo_id]);
    if (!$promo) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::promo_code_not_found];
        redirect(root . admin . '/promo-codes');
        return;
    }
    
    $title = T::edit_promo_code;
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/promo-codes/manage-promo-code.php";
    require_once views."includes/footer.php";
});

// ================================ POST /promo-codes/edit/{id} - UPDATE PROMO CODE
$router->post(admin.'/promo-codes/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $promo_id = intval($id);
    
    $promo = $db->get('promo_codes', '*', ['id' => $promo_id]);
    if (!$promo) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::promo_code_not_found];
        redirect(root . admin . '/promo-codes');
        return;
    }
    
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = floatval($_POST['discount_value'] ?? 0);
    $min_order_amount = !empty($_POST['min_order_amount']) ? floatval($_POST['min_order_amount']) : null;
    $max_discount_amount = !empty($_POST['max_discount_amount']) ? floatval($_POST['max_discount_amount']) : null;
    $usage_limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
    $per_user_limit = !empty($_POST['per_user_limit']) ? intval($_POST['per_user_limit']) : 1;
    $module = $_POST['module'] ?? 'all';
    $target_type = $_POST['target_type'] ?? 'all';
    $target_ids = !empty($_POST['target_ids']) ? json_encode(array_map('intval', (array)$_POST['target_ids'])) : null;
    $target_locations = !empty($_POST['target_locations']) ? json_encode(array_map('intval', (array)$_POST['target_locations'])) : null;
    $start_date = !empty($_POST['start_date']) ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : null;
    $end_date = !empty($_POST['end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null;
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Currency: use posted value or fall back to system default
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    if (empty($currency)) {
        $currency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';
    }
    
    // Reset targeting data when not applicable
    if ($target_type === 'all') {
        $target_ids = null;
        $target_locations = null;
    }
    if ($module === 'all') {
        $target_ids = null;
    }
    
    // Validation
    $errors = [];
    if (empty($code)) $errors[] = T::promo_code_required;
    if ($discount_value <= 0) $errors[] = T::discount_value_must_be_greater_than_0;
    if ($discount_type === 'percentage' && $discount_value > 100) $errors[] = T::percentage_cannot_exceed_100;
    if (!preg_match('/^[A-Z0-9_-]+$/', $code)) $errors[] = T::promo_code_invalid_chars;
    
    // Check for duplicate code (excluding current)
    $existing = $db->get('promo_codes', 'id', ['code' => $code, 'id[!]' => $promo_id]);
    if ($existing) $errors[] = T::promo_code_already_exists;
    
    // Validate dates
    if ($start_date && $end_date && strtotime($end_date) <= strtotime($start_date)) {
        $errors[] = T::end_date_must_be_after_start_date;
    }
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/promo-codes/edit/' . $promo_id);
        return;
    }
    
    $promo_data = [
        'code' => $code,
        'description' => !empty($description) ? $description : null,
        'discount_type' => $discount_type,
        'discount_value' => $discount_value,
        'currency' => $currency,
        'min_order_amount' => $min_order_amount,
        'max_discount_amount' => $max_discount_amount,
        'usage_limit' => $usage_limit,
        'per_user_limit' => $per_user_limit,
        'module' => $module,
        'target_type' => $target_type,
        'target_ids' => $target_ids,
        'target_locations' => $target_locations,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->update('promo_codes', $promo_data, ['id' => $promo_id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::promo_code_updated_successfully];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    redirect(root . admin . '/promo-codes/edit/' . $promo_id);
});

// ================================ POST /promo-codes/delete - DELETE PROMO CODE
$router->post(admin.'/promo-codes/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $promo_id = intval($_POST['id'] ?? 0);
    
    if ($promo_id <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::invalid_promo_code_id];
        redirect(root . admin . '/promo-codes');
        return;
    }
    
    $promo = $db->get('promo_codes', ['id'], ['id' => $promo_id]);
    if (!$promo) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::promo_code_not_found];
        redirect(root . admin . '/promo-codes');
        return;
    }
    
    try {
        $result = $db->delete('promo_codes', ['id' => $promo_id]);
        if ($result) {
            $_SESSION['message'] = ['type' => 'success', 'text' => T::promo_code_deleted_successfully];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_delete_promo_code];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    redirect(root . admin . '/promo-codes');
});

// ================================ POST /promo-codes/generate - GENERATE RANDOM CODE (AJAX)
$router->post(admin.'/promo-codes/generate', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    
    $prefix = strtoupper(trim($_POST['prefix'] ?? ''));
    $length = intval($_POST['length'] ?? 8);
    $length = max(4, min(20, $length));
    
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = $prefix;
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    // Make sure it's unique
    $attempts = 0;
    while ($db->get('promo_codes', 'id', ['code' => $code]) && $attempts < 10) {
        $code = $prefix;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $attempts++;
    }
    
    echo json_encode(['success' => true, 'code' => $code]);
});

/*===================================================================
PROMO CODES ROUTES END
===================================================================*/

// ================================ GET /promo-codes/search-items - SEARCH ITEMS FOR TARGETING (AJAX)
$router->get(admin.'/promo-codes/search-items', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    
    $module = $_GET['module'] ?? '';
    $search = trim($_GET['q'] ?? '');
    $type = $_GET['type'] ?? 'items'; // 'items' or 'locations'
    $results = [];
    
    if ($type === 'locations') {
        // Search locations (works for any module)
        $where = ['status' => '1', 'LIMIT' => 20];
        if (!empty($search)) {
            $where['OR'] = [
                'city[~]' => $search,
                'country[~]' => $search
            ];
        }
        $locations = $db->select('locations', ['id', 'city', 'country', 'country_code'], $where);
        foreach ($locations as $loc) {
            $results[] = [
                'id' => $loc['id'],
                'text' => $loc['city'] . ', ' . $loc['country'],
                'extra' => strtoupper($loc['country_code'] ?? '')
            ];
        }
    } else {
        // Search module-specific items
        switch ($module) {
            case 'stays':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'name[~]' => $search,
                        'location[~]' => $search
                    ];
                }
                $items = $db->select('stays', ['id', 'name', 'location', 'stars'], $where);
                foreach ($items as $item) {
                    $stars = $item['stars'] ? str_repeat('★', $item['stars']) : '';
                    $results[] = [
                        'id' => $item['id'],
                        'text' => $item['name'],
                        'extra' => trim($item['location'] . ' ' . $stars)
                    ];
                }
                break;
                
            case 'flights':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'flights.flight_number[~]' => $search,
                        'a.name[~]' => $search
                    ];
                }
                $items = $db->select('flights', [
                    '[>]flights_airlines(a)' => ['airline_id' => 'id'],
                    '[>]flights_airports(dep)' => ['from_airport_id' => 'id'],
                    '[>]flights_airports(arr)' => ['to_airport_id' => 'id']
                ], [
                    'flights.id',
                    'flights.flight_number',
                    'a.name(airline_name)',
                    'dep.iata_code(from_code)',
                    'dep.city(from_city)',
                    'arr.iata_code(to_code)',
                    'arr.city(to_city)'
                ], $where);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => ($item['airline_name'] ?? '') . ' ' . $item['flight_number'],
                        'extra' => ($item['from_code'] ?? $item['from_city'] ?? '') . ' → ' . ($item['to_code'] ?? $item['to_city'] ?? '')
                    ];
                }
                break;
                
            case 'tours':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'name[~]' => $search,
                        'location[~]' => $search
                    ];
                }
                $items = $db->select('tours', ['id', 'name', 'location', 'days', 'nights'], $where);
                foreach ($items as $item) {
                    $duration = '';
                    if ($item['days']) $duration = $item['days'] . 'D';
                    if ($item['nights']) $duration .= '/' . $item['nights'] . 'N';
                    $results[] = [
                        'id' => $item['id'],
                        'text' => $item['name'],
                        'extra' => trim(($item['location'] ?? '') . ($duration ? ' · ' . $duration : ''))
                    ];
                }
                break;
                
            case 'cars':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'name[~]' => $search,
                        'brand[~]' => $search,
                        'model[~]' => $search
                    ];
                }
                $items = $db->select('cars', ['id', 'name', 'brand', 'model', 'service_type'], $where);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => $item['name'],
                        'extra' => ucfirst($item['service_type'] ?? '') . ($item['brand'] ? ' · ' . $item['brand'] : '')
                    ];
                }
                break;
                
            case 'visa':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'fc.name[~]' => $search,
                        'tc.name[~]' => $search
                    ];
                }
                $items = $db->select('visa', [
                    '[>]countries(fc)' => ['from_country_id' => 'id'],
                    '[>]countries(tc)' => ['to_country_id' => 'id']
                ], [
                    'visa.id',
                    'fc.name(from_country)',
                    'tc.name(to_country)'
                ], $where);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => ($item['from_country'] ?? 'N/A') . ' → ' . ($item['to_country'] ?? 'N/A'),
                        'extra' => 'Visa'
                    ];
                }
                break;
                
            case 'umrah':
                $where = ['LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'name[~]' => $search,
                        'location[~]' => $search
                    ];
                }
                $items = $db->select('umrah', ['id', 'name', 'location', 'days', 'nights'], $where);
                foreach ($items as $item) {
                    $duration = '';
                    if ($item['days']) $duration = $item['days'] . 'D';
                    if ($item['nights']) $duration .= '/' . $item['nights'] . 'N';
                    $results[] = [
                        'id' => $item['id'],
                        'text' => $item['name'],
                        'extra' => trim(($item['location'] ?? '') . ($duration ? ' · ' . $duration : ''))
                    ];
                }
                break;
            case 'esim':
                $where = ['status' => 1, 'LIMIT' => 20];
                if (!empty($search)) {
                    $where['OR'] = [
                        'nicename[~]' => $search,
                        'nicename[~]' => $search,
                        'iso[~]' => $search
                    ];
                }
                $items = $db->select('airalo_countries', ['id', 'nicename', 'iso'], $where);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => $item['nicename'],
                        'extra' => 'eSIM Country (' . $item['iso'] . ')'
                    ];
                }
                break;
            case 'rail':
                break;
        }
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
});

// ================================ GET /promo-codes/get-items - GET ITEMS BY IDS (AJAX)
$router->get(admin.'/promo-codes/get-items', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    
    $module = $_GET['module'] ?? '';
    $ids = !empty($_GET['ids']) ? array_map('intval', explode(',', $_GET['ids'])) : [];
    $type = $_GET['type'] ?? 'items';
    $results = [];
    
    if (empty($ids)) {
        echo json_encode(['success' => true, 'results' => []]);
        return;
    }
    
    if ($type === 'locations') {
        $locations = $db->select('locations', ['id', 'city', 'country', 'country_code'], ['id' => $ids]);
        foreach ($locations as $loc) {
            $results[] = [
                'id' => $loc['id'],
                'text' => $loc['city'] . ', ' . $loc['country'],
                'extra' => strtoupper($loc['country_code'] ?? '')
            ];
        }
    } else {
        switch ($module) {
            case 'stays':
                $items = $db->select('stays', ['id', 'name', 'location'], ['id' => $ids]);
                foreach ($items as $item) {
                    $results[] = ['id' => $item['id'], 'text' => $item['name'], 'extra' => $item['location'] ?? ''];
                }
                break;
            case 'flights':
                $items = $db->select('flights', [
                    '[>]flights_airlines(a)' => ['airline_id' => 'id'],
                    '[>]flights_airports(dep)' => ['from_airport_id' => 'id'],
                    '[>]flights_airports(arr)' => ['to_airport_id' => 'id']
                ], [
                    'flights.id', 'flights.flight_number', 'a.name(airline_name)',
                    'dep.iata_code(from_code)', 'arr.iata_code(to_code)'
                ], ['flights.id' => $ids]);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => ($item['airline_name'] ?? '') . ' ' . $item['flight_number'],
                        'extra' => ($item['from_code'] ?? '') . ' → ' . ($item['to_code'] ?? '')
                    ];
                }
                break;
            case 'tours':
                $items = $db->select('tours', ['id', 'name', 'location'], ['id' => $ids]);
                foreach ($items as $item) {
                    $results[] = ['id' => $item['id'], 'text' => $item['name'], 'extra' => $item['location'] ?? ''];
                }
                break;
            case 'cars':
                $items = $db->select('cars', ['id', 'name', 'brand', 'service_type'], ['id' => $ids]);
                foreach ($items as $item) {
                    $results[] = ['id' => $item['id'], 'text' => $item['name'], 'extra' => ucfirst($item['service_type'] ?? '')];
                }
                break;
            case 'visa':
                $items = $db->select('visa', [
                    '[>]countries(fc)' => ['from_country_id' => 'id'],
                    '[>]countries(tc)' => ['to_country_id' => 'id']
                ], ['visa.id', 'fc.name(from_country)', 'tc.name(to_country)'], ['visa.id' => $ids]);
                foreach ($items as $item) {
                    $results[] = [
                        'id' => $item['id'],
                        'text' => ($item['from_country'] ?? '') . ' → ' . ($item['to_country'] ?? ''),
                        'extra' => 'Visa'
                    ];
                }
                break;
            case 'umrah':
                $items = $db->select('umrah', ['id', 'name', 'location'], ['id' => $ids]);
                foreach ($items as $item) {
                    $results[] = ['id' => $item['id'], 'text' => $item['name'], 'extra' => $item['location'] ?? ''];
                }
                break;
            case 'esim':
                $items = $db->select('airalo_countries', ['id', 'nicename', 'iso'], ['id' => $ids]);
                foreach ($items as $item) {
                    $results[] = ['id' => $item['id'], 'text' => $item['nicename'], 'extra' => 'eSIM Country (' . $item['iso'] . ')'];
                }
                break;
            case 'rail':
                break;
        }
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
});
