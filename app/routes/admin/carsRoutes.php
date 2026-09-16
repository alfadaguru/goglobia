<?php
// app/routes/admin/carsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
CARS ROUTES START
===================================================================*/

// ================================ GET /cars - LIST ALL CARS
$router->get(admin.'/cars', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::cars_management ?? 'Cars Management';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/cars/cars.php";
    require_once views."includes/footer.php";
});

// ================================ GET /cars/add - ADD NEW CAR FORM
$router->get(admin.'/cars/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'add';

    $car_types = $db->select('cars_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'car_type', 'status' => 1]);
    $amenities = $db->select('cars_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    $title = T::add_car ?? 'Add Car';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/cars/manage-cars.php";
    require_once views."includes/footer.php";
});

// ================================ POST /cars/add - ADD NEW CAR
$router->post(admin.'/cars/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();

    $name = trim($_POST['car_name'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = intval($_POST['year'] ?? date('Y'));
    $car_type_id = intval($_POST['car_type_id'] ?? 0);
    $service_type = in_array($_POST['service_type'] ?? '', ['rental', 'transfer']) ? $_POST['service_type'] : 'rental';
    $transmission = trim($_POST['transmission'] ?? 'Automatic');
    $fuel_type = trim($_POST['fuel_type'] ?? '');
    $doors = intval($_POST['doors'] ?? 4);
    $passengers = intval($_POST['passengers'] ?? 5);
    $baggage = intval($_POST['baggage'] ?? 2);
    $currency = trim($_POST['currency'] ?? 'USD');
    $status = isset($_POST['status']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;
    $is_refundable = isset($_POST['is_refundable']) ? 1 : 0;

    $errors = [];
    if (empty($name)) $errors[] = T::car_name_required ?? 'Car name is required';
    if (empty($brand)) $errors[] = T::brand_required ?? 'Brand is required';

    if (!empty($errors)) {
        $active_tab = $_POST['active_tab'] ?? 'general';
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect(root . admin . '/cars/add?tab=' . $active_tab);
        return;
    }

    $images = [];
    if (isset($_FILES['car_images']) && !empty($_FILES['car_images']['name'][0])) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/cars/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $files = $_FILES['car_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // SECURITY: validate real MIME + safe extension (finfo).
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'car_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/cars/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    $images[] = ['url' => $image_url, 'default' => $i === 0];
                }
            }
        }
    }

    $amenity_ids = $_POST['amenity_ids'] ?? [];
    $amenities = json_encode($amenity_ids);

    $routes_json = $_POST['routes'] ?? '[]';

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name . '-' . $brand . '-' . $model)));

    $car_data = [
        'name' => $name,
        'slug' => $slug,
        'brand' => $brand,
        'model' => $model,
        'year' => $year,
        'car_type_id' => $car_type_id > 0 ? $car_type_id : null,
        'transmission' => $transmission,
        'fuel_type' => $fuel_type,
        'doors' => $doors,
        'passengers' => $passengers,
        'baggage' => $baggage,
        'currency' => $currency,
        'img' => !empty($images) ? json_encode($images) : null,
        'status' => $status,
        'amenities' => $amenities,
        'routes' => $routes_json,
        'user_id' => !empty($_POST['user_id']) ? $_POST['user_id'] : null,
        'created_at' => date('Y-m-d H:i:s')
    ];

    try {
        $result = $db->insert('cars', $car_data);
        if ($result) {
            $new_car_id = $db->id();

            // Routes are already saved in the 'cars' table as JSON in the 'routes' column.
            // Synchronization to 'cars_routes' table is removed.

            $active_tab = $_POST['active_tab'] ?? 'general';
            $_SESSION['message'] = ['type' => 'success', 'text' => T::car_added_successfully ?? 'Car added successfully'];
            redirect(root . admin . '/cars/edit/' . $new_car_id . '?tab=' . $active_tab);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to add car'];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/cars/add');
        }
    } catch (Exception $e) {
        $active_tab = $_POST['active_tab'] ?? 'general';
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
        redirect(root . admin . '/cars/add?tab=' . $active_tab);
    }
});

// ================================ GET /cars/edit/{id} - EDIT CAR FORM
$router->get(admin.'/cars/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'edit';
    $car_id = intval($id);

    $car = $db->get('cars', '*', ['id' => $car_id]);
    if (!$car) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::car_not_found ?? 'Car not found'];
        redirect(root . admin . '/cars');
        return;
    }

    $owner = null;
    if (!empty($car['user_id'])) {
        $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $car['user_id']]);
    }

    $car_types = $db->select('cars_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'car_type', 'status' => 1]);
    $amenities = $db->select('cars_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    $selected_amenities = !empty($car['amenities']) ? json_decode($car['amenities'], true) : [];
    if (!is_array($selected_amenities)) $selected_amenities = [];

    $car_images = [];
    if (!empty($car['img'])) {
        $car_images = json_decode($car['img'], true);
        if (!is_array($car_images)) $car_images = [];
    }

    $title = T::edit_car ?? 'Edit Car';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/cars/manage-cars.php";
    require_once views."includes/footer.php";
});

// ================================ POST /cars/edit/{id} - UPDATE CAR
$router->post(admin.'/cars/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $car_id = intval($id);


    $car = $db->get('cars', '*', ['id' => $car_id]);
    if (!$car) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::car_not_found ?? 'Car not found'];
        redirect(root . admin . '/cars');
        return;
    }

    $name = trim($_POST['car_name'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = intval($_POST['year'] ?? date('Y'));
    $car_type_id = intval($_POST['car_type_id'] ?? 0);
    $service_type = in_array($_POST['service_type'] ?? '', ['rental', 'transfer']) ? $_POST['service_type'] : 'rental';
    $transmission = trim($_POST['transmission'] ?? 'Automatic');
    $fuel_type = trim($_POST['fuel_type'] ?? '');
    $doors = intval($_POST['doors'] ?? 4);
    $passengers = intval($_POST['passengers'] ?? 5);
    $baggage = intval($_POST['baggage'] ?? 2);
    $currency = trim($_POST['currency'] ?? 'USD');
    $status = isset($_POST['status']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;
    $is_refundable = isset($_POST['is_refundable']) ? 1 : 0;

    $errors = [];
    if (empty($name)) $errors[] = T::car_name_required ?? 'Car name is required';

    if (!empty($errors)) {
        $active_tab = $_POST['active_tab'] ?? 'general';
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect(root . admin . '/cars/edit/' . $car_id . '?tab=' . $active_tab);
        return;
    }

    $project_root = realpath(__DIR__ . '/../../../');

    // Handle image deletion
    $existing_images = !empty($car['img']) ? json_decode($car['img'], true) : [];
    $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true);
    if (is_array($images_to_delete) && !empty($images_to_delete)) {
        foreach ($images_to_delete as $url) {
            $clean_url = ltrim($url, '/');
            $file_path = $project_root . '/' . $clean_url;
            if (file_exists($file_path)) @unlink($file_path);
        }
        $existing_images = array_values(array_filter($existing_images, function($img) use ($images_to_delete) {
            return !in_array($img['url'], $images_to_delete);
        }));
    }

    // Handle new images
    $images = $existing_images;
    if (isset($_FILES['car_images']) && !empty($_FILES['car_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/cars/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $files = $_FILES['car_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // SECURITY: validate real MIME + derive a SAFE extension (never the
                // user filename). The car-add handler already did this; this edit
                // handler took the extension straight from $_FILES['name'], so an
                // admin could upload e.g. shell.php into the web-served
                // /uploads/cars/gallery/ dir (RCE if PHP execution isn't blocked
                // there / on non-Apache servers, and stored-XSS regardless).
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'car_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/cars/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    $images[] = ['url' => $image_url, 'default' => empty($images)];
                }
            }
        }
    }

    $amenity_ids = $_POST['amenity_ids'] ?? [];
    $amenities = json_encode($amenity_ids);

    $routes_json = $_POST['routes'] ?? '[]';

    $car_data = [
        'name' => $name,
        'brand' => $brand,
        'model' => $model,
        'year' => $year,
        'car_type_id' => $car_type_id > 0 ? $car_type_id : null,
        'service_type' => $service_type,
        'transmission' => $transmission,
        'fuel_type' => $fuel_type,
        'doors' => $doors,
        'passengers' => $passengers,
        'baggage' => $baggage,
        'currency' => $currency,
        'img' => !empty($images) ? json_encode($images) : null,
        'status' => $status,
        'featured' => $featured,
        'is_refundable' => $is_refundable,
        'amenities' => $amenities,
        'routes' => $routes_json,
        'user_id' => array_key_exists('user_id', $_POST) ? (!empty($_POST['user_id']) ? $_POST['user_id'] : null) : $car['user_id'],
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        $db->update('cars', $car_data, ['id' => $car_id]);

        // Routes are already updated in the 'cars' table as JSON in the 'routes' column.
        // Synchronization to 'cars_routes' table is removed.

        $_SESSION['message'] = ['type' => 'success', 'text' => T::car_updated_successfully ?? 'Car updated successfully'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }

    $active_tab = $_POST['active_tab'] ?? 'general';
    redirect(root . admin . '/cars/edit/' . $car_id . '?tab=' . $active_tab);
});

// ================================ POST /cars/delete - DELETE CAR
$router->post(admin.'/cars/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $car_id = intval($_POST['id'] ?? 0);

    $car = $db->get('cars', ['id', 'img'], ['id' => $car_id]);
    if ($car) {
        $project_root = realpath(__DIR__ . '/../../../');
        if (!empty($car['img'])) {
            $images = json_decode($car['img'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    $clean_url = ltrim($img['url'], '/');
                    $file_path = $project_root . '/' . $clean_url;
                    if (file_exists($file_path)) @unlink($file_path);
                }
            }
        }
        $db->delete('cars', ['id' => $car_id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::deleted_successfully ?? 'Deleted successfully'];
    }
    redirect(root . admin . '/cars');
});

// ================================ GET /cars/settings - LIST SETTINGS
$router->get(admin.'/cars/settings', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::cars_settings ?? 'Cars Settings';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/cars/cars-settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /cars/settings/save - SAVE SETTING
$router->post(admin.'/cars/settings/save', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = intval($_POST['id'] ?? 0);

    // FORM DATA VALIDATION
    $setting_type = trim($_POST['setting_type'] ?? '');
    $setting_label = trim($_POST['setting_label'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;

    // GET TRANSLATIONS
    $translations = [];
    if (isset($_POST['translations']) && is_array($_POST['translations'])) {
        foreach ($_POST['translations'] as $lang_code => $translation) {
            if (!empty(trim($translation))) {
                $translations[$lang_code] = trim($translation);
            }
        }
    }

    $data = [
        'setting_type' => $setting_type,
        'setting_label' => $setting_label,
        'icon' => $icon,
        'status' => $status,
        'translations' => !empty($translations) ? json_encode($translations) : null
    ];

    if ($id > 0) {
        $db->update('cars_settings', $data, ['id' => $id]);
    } else {
        $db->insert('cars_settings', $data);
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => T::settings_saved ?? 'Settings saved'];
    redirect(root . admin . '/cars/settings?type=' . $data['setting_type']);
});

// ================================ GET /cars/settings/edit/{id} - EDIT SETTING FORM
$router->get(admin.'/cars/settings/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $settingId = intval($id);
    $setting = $db->get('cars_settings', '*', ['id' => $settingId]);

    if (!$setting) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::setting_not_found ?? 'Setting not found'];
        $type = $_GET['type'] ?? 'car_type';
        redirect(root . admin . '/cars/settings?type=' . urlencode($type));
        return;
    }

    // Decode translations
    $translations = [];
    if (!empty($setting['translations'])) {
        $translations = json_decode($setting['translations'], true);
        if (!is_array($translations)) {
            $translations = [];
        }
    }

    $title = T::edit_cars_setting ?? 'Edit Cars Setting';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/cars/cars-settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /cars/settings/delete - DELETE SETTING
$router->post(admin.'/cars/settings/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->delete('cars_settings', ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::deleted_successfully ?? 'Deleted successfully'];
    }
    redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/cars/settings');
});

// ================================ AJAX /cars-suggestion
$router->post(admin.'/cars-suggestion', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $query = trim($_POST['query'] ?? '');

    $where = ['status' => 1, 'LIMIT' => 10, 'ORDER' => ['brand' => 'ASC', 'model' => 'ASC']];
    if (!empty($query)) {
        $where['OR'] = [
            'name[~]' => $query,
            'brand[~]' => $query,
            'model[~]' => $query
        ];
    }

    try {
        $cars = $db->select('cars', [
            'id',
            'name',
            'brand',
            'model',
            'year'
        ], $where);

        $results = [];
        foreach ($cars as $car) {
            $results[] = [
                'id' => $car['id'],
                'name' => $car['name'],
                'brand' => $car['brand'],
                'model' => $car['model'],
                'year' => $car['year'],
                'display' => $car['brand'] . ' ' . $car['model'] . ' (' . $car['name'] . ')'
            ];
        }

        echo json_encode([
            'success' => true,
            'results' => $results
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'results' => []
        ]);
    }
    exit;
});

// ================================ POST /cars/search-users - AJAX USER SEARCH
$router->post(admin.'/cars/search-users', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $search = trim($_POST['search'] ?? '');
    if (empty($search)) {
        echo json_encode(['success' => false, 'users' => []]);
        return;
    }

    $users = $db->select('users',
        ['user_id', 'first_name', 'last_name', 'email'],
        [
            'AND' => [
                'status' => 'active',
                'OR' => [
                    'email[~]' => $search,
                    'first_name[~]' => $search,
                    'last_name[~]' => $search
                ]
            ],
            'LIMIT' => 10
        ]
    );

    echo json_encode(['success' => true, 'users' => $users]);
});

/*===================================================================
CARS ROUTES END
===================================================================*/
