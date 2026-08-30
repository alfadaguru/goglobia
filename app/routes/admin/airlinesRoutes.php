<?php
// app/routes/admin/airlinesRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
AIRLINES ROUTES START
===================================================================*/

// ================================ GET /flights-airlines - LIST ALL AIRLINES
$router->get(admin.'/flights-airlines', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::airlines_management;
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flights-airlines.php";
    require_once views."includes/footer.php";
});

// ================================ GET /flights-airlines/add - ADD NEW AIRLINE FORM
$router->get(admin.'/flights-airlines/add', function () use ($SECURE,$db) {
    
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'add';

    // META DATA
    $title = T::add_new_airline;
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airlines.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights-airlines/add - ADD NEW AIRLINE
$router->post(admin.'/flights-airlines/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // FORM DATA VALIDATION
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $iata = trim($_POST['iata'] ?? '');
    $sign = trim($_POST['sign'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

    // VALIDATION RULES
    $errors = [];
    
    if (empty($name)) {
        $errors[] = T::airline_name_required;
    }
    
    if (empty($country)) {
        $errors[] = T::country_required;
    }
    
    // Check for duplicate IATA code if provided
    if (!empty($iata)) {
        $existing = $db->get('flights_airlines', 'id', ['iata' => $iata]);
        if ($existing) {
            $errors[] = T::iata_already_exists;
        }
    }
    
    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airlines/add');
        return;
    }
    
    // PREPARE DATA FOR INSERTION
    $airline_data = [
        'name' => $name,
        'code' => !empty($code) ? $code : null,
        'iata' => !empty($iata) ? $iata : null,
        'sign' => !empty($sign) ? $sign : null,
        'country' => $country,
        'status' => $status
    ];
    
    try {
        $result = $db->insert('flights_airlines', $airline_data);
        
        if ($result) {
            $new_airline_id = $db->id();
            
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::airline_added_successfully
            ];
            
            redirect(root . admin . '/flights-airlines/add/' . $new_airline_id);
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_add_airline
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airlines/add');
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airlines/add');
    }
});

// ================================ GET /flights-airlines/edit/{id} - EDIT AIRLINE FORM
$router->get(admin.'/flights-airlines/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'edit';

    $airline_id = intval($id);
    
    // CHECK IF AIRLINE EXISTS
    $airline = $db->get('flights_airlines', '*', ['id' => $airline_id]);
    
    if (!$airline) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::airline_not_found
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    // META DATA
    $title = T::edit_airline;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airlines.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights-airlines/edit/{id} - UPDATE AIRLINE
$router->post(admin.'/flights-airlines/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $airline_id = intval($id);
    
    // CHECK IF AIRLINE EXISTS
    $airline = $db->get('flights_airlines', '*', ['id' => $airline_id]);
    
    if (!$airline) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::airline_not_found
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    // FORM DATA VALIDATION
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $iata = trim($_POST['iata'] ?? '');
    $sign = trim($_POST['sign'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
    
    // VALIDATION RULES
    $errors = [];
    
    if (empty($name)) {
        $errors[] = T::airline_name_required;
    }
    
    if (empty($country)) {
        $errors[] = T::country_required;
    }
    
    // Check for duplicate IATA code if provided
    if (!empty($iata)) {
        $existing = $db->get('flights_airlines', 'id', [
            'iata' => $iata,
            'id[!]' => $airline_id
        ]);
        if ($existing) {
            $errors[] = T::iata_already_exists;
        }
    }
    
    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights-airlines/edit/' . $airline_id);
        return;
    }
    
    // PREPARE DATA FOR UPDATE
    $airline_data = [
        'name' => $name,
        'code' => !empty($code) ? $code : null,
        'iata' => !empty($iata) ? $iata : null,
        'sign' => !empty($sign) ? $sign : null,
        'country' => $country,
        'status' => $status
    ];
    
    try {
        // UPDATE AIRLINE TABLE
        $result = $db->update('flights_airlines', $airline_data, ['id' => $airline_id]);
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::airline_updated_successfully
        ];
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }
    
    redirect(root . admin . '/flights-airlines/edit/' . $airline_id);
});

// ================================ POST /flights-airlines/delete - DELETE AIRLINE
$router->post(admin.'/flights-airlines/delete', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $airline_id = intval($_POST['id'] ?? 0);
    
    if ($airline_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_airline_id
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    // CHECK IF AIRLINE EXISTS
    $airline = $db->get('flights_airlines', ['id'], ['id' => $airline_id]);
    
    if (!$airline) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::airline_not_found
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    // Check if airline is used in flights
    $used_in_flights = $db->get('flights', 'id', ['airline_id' => $airline_id]);
    
    if ($used_in_flights) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::cannot_delete_airline_in_use
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    try {
        // DELETE AIRLINE FROM DATABASE
        $result = $db->delete('flights_airlines', ['id' => $airline_id]);
        
        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::airline_deleted_successfully
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_airline
            ];
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }
    
    redirect(root . admin . '/flights-airlines');
});

// ================================ GET /flights-airlines/view/{id} - VIEW AIRLINE DETAILS
$router->get(admin.'/flights-airlines/view/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'view';

    $airline_id = intval($id);
    
    // FETCH AIRLINE DETAILS
    $airline = $db->get('flights_airlines', '*', ['id' => $airline_id]);
    
    if (empty($airline)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::airline_not_found
        ];
        redirect(root . admin . '/flights-airlines');
        return;
    }
    
    // META DATA
    $title = T::view_airline . ': ' . $airline['name'];
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/manage-flight-airlines.php";
    require_once views."includes/footer.php";
});

/*===================================================================
AIRLINES ROUTES END
===================================================================*/