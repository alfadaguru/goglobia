<?php
// app/routes/admin/locationsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
LOCATIONS ROUTES START
===================================================================*/

// ================================ GET / locations - LIST LOCATIONS
$router->get(admin.'/settings/(locations)', function ($route) use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = ''.T::locations_management ?? 'Locations Management';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/settings/locations.php";
    require_once views."includes/footer.php";
});

// ================================ LOCATIONS MANAGE (ADD/EDIT) - UNIFIED ROUTE
$locationsManageHandler = function ($action, $id = null) use ($SECURE,$db) {
    ADMIN_AUTH();

    $locationId = $id ? (int)$id : 0;
    $isEdit = $locationId > 0;

    // Handle POST submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_location') {
        try {
            // Validate CSRF token
            if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
                throw new Exception(T::invalid_csrf ?? 'Invalid security token');
            }

            // Check if location exists for edit mode
            if ($isEdit) {
                $existingLocation = $db->get('locations', '*', ['id' => $locationId]);
                if (!$existingLocation) {
                    throw new Exception(T::location_not_found ?? 'Location not found');
                }
            }

            // Validate required fields
            $city = trim($_POST['city'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $country_code = trim($_POST['country_code'] ?? '');

            if (empty($city)) {
                throw new Exception(T::city_required ?? 'City name is required');
            }

            if (empty($country)) {
                throw new Exception(T::country_required ?? 'Country is required');
            }

            if (empty($country_code)) {
                throw new Exception(T::country_code_required ?? 'Country code is required');
            }

            if (strlen($city) > 255) {
                throw new Exception(T::city_too_long ?? 'City name is too long (max 255 characters)');
            }

            // Check for duplicate location
            $duplicateCondition = $isEdit 
                ? ['AND' => ['city' => $city, 'country' => $country, 'id[!]' => $locationId]]
                : ['city' => $city, 'country' => $country];
            
            if ($db->get('locations', ['id'], $duplicateCondition)) {
                throw new Exception(T::location_already_exists ?? 'This location already exists');
            }

            // Validate coordinates if provided
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');

            if (!empty($latitude) && !is_numeric($latitude)) {
                throw new Exception(T::invalid_latitude ?? 'Invalid latitude value');
            }

            if (!empty($longitude) && !is_numeric($longitude)) {
                throw new Exception(T::invalid_longitude ?? 'Invalid longitude value');
            }

            // Prepare location data
            $locationData = [
                'city' => $city,
                'country' => $country,
                'country_code' => $country_code,
                'latitude' => !empty($latitude) ? $latitude : null,
                'longitude' => !empty($longitude) ? $longitude : null,
                'status' => $_POST['status'] ?? '1'
            ];

            // Insert or update
            $result = $isEdit 
                ? $db->update('locations', $locationData, ['id' => $locationId])
                : $db->insert('locations', $locationData);

            if ($result) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => $isEdit 
                        ? (T::location_updated_successfully ?? 'Location updated successfully')
                        : (T::location_added_successfully ?? 'Location added successfully')
                ];
                redirect($isEdit 
                    ? root . admin . '/settings/locations/edit/' . $locationId
                    : root . admin . '/settings/locations'
                );
            } else {
                throw new Exception($isEdit 
                    ? (T::failed_to_update_location ?? 'Failed to update location')
                    : (T::failed_to_add_location ?? 'Failed to add location')
                );
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
        }
    }

    // Render view
    $title = $isEdit ? (T::edit_location ?? 'Edit Location') : (T::add_location ?? 'Add Location');
    $header = true;
    $footer = true;
    
    if ($isEdit) {
        $_GET['id'] = $locationId;
    }
    
    require_once views."includes/header.php";
    require_once "app/views/admin/settings/locations-manage.php";
    require_once views."includes/footer.php";
};

// Register GET and POST routes with same handler
$router->get(admin.'/settings/locations/(add|edit)(?:/(.*))?', $locationsManageHandler);
$router->post(admin.'/settings/locations/(add|edit)(?:/(.*))?', $locationsManageHandler);