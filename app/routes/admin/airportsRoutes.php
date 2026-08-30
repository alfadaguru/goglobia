<?php
// app/routes/admin/airportsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
AIRPORTS ROUTES START
===================================================================*/

// ================================ GET /flights-airports - LIST ALL AIRPORTS
$router->get(admin.'/flights-airports', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Airports Management';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flights-airports.php";
    require_once views."includes/footer.php";
});

// ================================ GET /flights-airports/add - ADD NEW AIRPORT FORM
$router->get(admin.'/flights-airports/add', function () use ($SECURE,$db) {
    
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'add';

    // META DATA
    $title = 'Add New Airport';
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airports.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights-airports/add - ADD NEW AIRPORT
$router->post(admin.'/flights-airports/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // FORM DATA VALIDATION
    $airport = trim($_POST['airport'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $late = trim($_POST['late'] ?? '');
    $long = trim($_POST['long'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $type = trim($_POST['type'] ?? 'airport');
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

    // VALIDATION RULES
    $errors = [];
    
    if (empty($airport)) {
        $errors[] = 'Airport name is required';
    }
    
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    
    if (empty($country)) {
        $errors[] = 'Country is required';
    }
    
    if (empty($code)) {
        $errors[] = 'IATA code is required';
    }
    
    // Check for duplicate IATA code
    if (!empty($code)) {
        $existing = $db->get('flights_airports', 'id', ['code' => $code]);
        if ($existing) {
            $errors[] = 'IATA code already exists';
        }
    }
    
    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airports/add');
        return;
    }
    
    // PREPARE DATA FOR INSERTION
    $airport_data = [
        'airport' => $airport,
        'city' => $city,
        'country' => $country,
        'code' => $code,
        'late' => !empty($late) ? $late : null,
        'long' => !empty($long) ? $long : null,
        'region' => !empty($region) ? $region : null,
        'type' => $type,
        'status' => $status
    ];
    
    try {
        $result = $db->insert('flights_airports', $airport_data);
        
        if ($result) {
            $new_airport_id = $db->id();
            
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'Airport added successfully'
            ];
            
            redirect(root . admin . '/flights-airports/add/' . $new_airport_id);
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Failed to add airport'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airports/add');
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Database error: ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airports/add');
    }
});

// ================================ GET /flights-airports/edit/{id} - EDIT AIRPORT FORM
$router->get(admin.'/flights-airports/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'edit';

    $airport_id = intval($id);
    
    // CHECK IF AIRPORT EXISTS
    $airport = $db->get('flights_airports', '*', ['id' => $airport_id]);
    
    if (!$airport) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Airport not found'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    // META DATA
    $title = 'Edit Airport';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airports.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights-airports/edit/{id} - UPDATE AIRPORT
$router->post(admin.'/flights-airports/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $airport_id = intval($id);
    
    // CHECK IF AIRPORT EXISTS
    $airport = $db->get('flights_airports', '*', ['id' => $airport_id]);
    
    if (!$airport) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Airport not found'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    // FORM DATA VALIDATION
    $airport_name = trim($_POST['airport'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $late = trim($_POST['late'] ?? '');
    $long = trim($_POST['long'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $type = trim($_POST['type'] ?? 'airport');
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
    
    // VALIDATION RULES
    $errors = [];
    
    if (empty($airport_name)) {
        $errors[] = 'Airport name is required';
    }
    
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    
    if (empty($country)) {
        $errors[] = 'Country is required';
    }
    
    if (empty($code)) {
        $errors[] = 'IATA code is required';
    }
    
    // Check for duplicate IATA code
    if (!empty($code)) {
        $existing = $db->get('flights_airports', 'id', [
            'code' => $code,
            'id[!]' => $airport_id
        ]);
        if ($existing) {
            $errors[] = 'IATA code already exists';
        }
    }
    
    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airports/edit/' . $airport_id);
        return;
    }
    
    // PREPARE DATA FOR UPDATE
    $airport_data = [
        'airport' => $airport_name,
        'city' => $city,
        'country' => $country,
        'code' => $code,
        'late' => !empty($late) ? $late : null,
        'long' => !empty($long) ? $long : null,
        'region' => !empty($region) ? $region : null,
        'type' => $type,
        'status' => $status
    ];
    
    try {
        // UPDATE AIRPORT TABLE
        $result = $db->update('flights_airports', $airport_data, ['id' => $airport_id]);
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Airport updated successfully'
        ];
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    redirect(root . admin . '/flights-airports/edit/' . $airport_id);
});

// ================================ POST /flights-airports/delete - DELETE AIRPORT
$router->post(admin.'/flights-airports/delete', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $airport_id = intval($_POST['id'] ?? 0);
    
    if ($airport_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invalid airport ID'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    // CHECK IF AIRPORT EXISTS
    $airport = $db->get('flights_airports', ['id'], ['id' => $airport_id]);
    
    if (!$airport) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Airport not found'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    // Check if airport is used in flights (origin or destination)
    $used_as_origin = $db->get('flights', 'id', ['from_airport_id' => $airport_id]);
    $used_as_destination = $db->get('flights', 'id', ['to_airport_id' => $airport_id]);
    
    if ($used_as_origin || $used_as_destination) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Cannot delete airport. It is being used in flights.'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    try {
        // DELETE AIRPORT FROM DATABASE
        $result = $db->delete('flights_airports', ['id' => $airport_id]);
        
        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'Airport deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Failed to delete airport'
            ];
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    redirect(root . admin . '/flights-airports');
});

// ================================ GET /flights-airports/view/{id} - VIEW AIRPORT DETAILS
$router->get(admin.'/flights-airports/view/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'view';

    $airport_id = intval($id);
    
    // FETCH AIRPORT DETAILS
    $airport = $db->get('flights_airports', '*', ['id' => $airport_id]);
    
    if (empty($airport)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Airport not found'
        ];
        redirect(root . admin . '/flights-airports');
        return;
    }
    
    // META DATA
    $title = 'View Airport: ' . $airport['airport'];
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airports.php";
    require_once views."includes/footer.php";
});

/*===================================================================
AIRPORTS ROUTES END
===================================================================*/