<?php
// app/routes/admin/hotelsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
HOTELS ROUTES START
===================================================================*/

// ================================ GET /hotels - LIST ALL HOTELS
$router->get(admin.'/stays', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::hotels_management;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/stays.php";
    require_once views."includes/footer.php";
});

// ================================ GET /stays/add - ADD NEW STAY FORM
$router->get(admin.'/stays/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'add';

    // FETCH AMENITIES
    $amenities = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'stay_amenity', 'status' => 1]);

    // FETCH ACCOMMODATION TYPES
    $accommodation_types = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'accommodation', 'status' => 1]);

    // GET CURRENCIES
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    // META DATA
    $title = T::add_hotel;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/stay.php";
    require_once views."includes/footer.php";
});

// ================================ POST /stays/add - ADD NEW STAY
$router->post(admin.'/stays/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // FORM DATA VALIDATION
    $name = trim($_POST['hotel_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $user_id = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? $_POST['user_id'] : 0;
    
    $location = trim($_POST['location'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $stars = intval($_POST['stars'] ?? 0);
    $rating = floatval($_POST['rating'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'USD');
    $discount = intval($_POST['discount'] ?? 0);
    $stay_type = intval($_POST['stay_type'] ?? 0);

    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');

    $checkin_time = trim($_POST['checkin_time'] ?? '14:00:00');
    $checkout_time = trim($_POST['checkout_time'] ?? '12:00:00');
    $booking_age_requirement = intval($_POST['booking_age_requirement'] ?? 18);
    $refundable = isset($_POST['refundable']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;

    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');

    $amenity_ids = trim($_POST['amenity_ids'] ?? '[]');

    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $privacy_policy = trim($_POST['privacy_policy'] ?? '');

    // VALIDATION RULES
    $errors = [];

    if (empty($name)) {
        $errors[] = T::hotel_name_required;
    }

    if (empty($location)) {
        $errors[] = T::location_required;
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/hotels/add');
        return;
    }

    // HANDLE FILE UPLOAD FOR IMAGES
    $images = [];

    if (isset($_FILES['hotel_images']) && !empty($_FILES['hotel_images']['name'][0])) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/hotels/gallery/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $files = $_FILES['hotel_images'];
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // SECURITY: validate real MIME + safe extension (finfo).
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'hotel_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/hotels/gallery/' . $new_filename;

                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    // First image is default
                    $images[] = [
                        'url' => $image_url,
                        'default' => $i === 0
                    ];
                } else {
                    error_log("Hotel Add - Failed to upload: " . $files['name'][$i]);
                }
            } else {
                error_log("Hotel Add - Upload error for file $i: " . $files['error'][$i]);
            }
        }
    } else {
        // error_log("Hotel Add - No files uploaded");
    }

    // CREATE SLUG FROM NAME
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

    // CREATE LOCATION COORDINATES STRING
    $location_coords = '';
    if (!empty($latitude) && !empty($longitude)) {
        $location_coords = $latitude . ',' . $longitude;
    }

    $translations = [];

    // Get all translation arrays
    $name_translations = $_POST['name_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    $address_translations = $_POST['address_translations'] ?? [];
    $cancellation_policy_translations = $_POST['cancellation_policy_translations'] ?? [];
    $privacy_policy_translations = $_POST['privacy_policy_translations'] ?? [];

    // Get all unique language codes
    $all_lang_codes = array_unique(array_merge(
        array_keys($name_translations),
        array_keys($desc_translations),
        array_keys($address_translations),
        array_keys($cancellation_policy_translations),
        array_keys($privacy_policy_translations)
    ));

    // Restructure: Group by language code instead of by field
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];

        // Add name if exists
        if (!empty(trim($name_translations[$lang_code] ?? ''))) {
            $lang_data['name'] = trim($name_translations[$lang_code]);
        }

        // Add description if exists
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) {
            $lang_data['desc'] = trim($desc_translations[$lang_code]);
        }

        // Add address if exists
        if (!empty(trim($address_translations[$lang_code] ?? ''))) {
            $lang_data['address'] = trim($address_translations[$lang_code]);
        }

        // Add cancellation policy if exists
        if (!empty(trim($cancellation_policy_translations[$lang_code] ?? ''))) {
            $lang_data['cancellation_policy'] = trim($cancellation_policy_translations[$lang_code]);
        }

        // Add privacy policy if exists
        if (!empty(trim($privacy_policy_translations[$lang_code] ?? ''))) {
            $lang_data['privacy_policy'] = trim($privacy_policy_translations[$lang_code]);
        }

        // Only add this language if it has at least one translation
        if (!empty($lang_data)) {
            $translations[$lang_code] = $lang_data;
        }
    }

    // PREPARE DATA FOR INSERTION
    $hotel_data = [
        'user_id' => $user_id,
        'name' => $name,
        'desc' => !empty($description) ? $description : null,
        'slug' => $slug,
        'location' => $location,
        'location_coords' => !empty($location_coords) ? $location_coords : null,
        'address' => $address,
        'stars' => $stars > 0 ? $stars : null,
        'rating' => $rating > 0 ? $rating : null,
        'currency' => $currency,
        'discount' => $discount > 0 ? $discount : null,
        'stay_type' => $stay_type > 0 ? $stay_type : null,
        'email' => !empty($email) ? $email : null,
        'phone' => !empty($phone) ? $phone : null,
        'website' => !empty($website) ? $website : null,
        'checkin_time' => $checkin_time,
        'checkout_time' => $checkout_time,
        'booking_age_requirement' => $booking_age_requirement,
        'refundable' => $refundable,
        'featured' => $featured,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'cancellation_policy' => !empty($cancellation_policy) ? $cancellation_policy : null,
        'privacy_policy' => !empty($privacy_policy) ? $privacy_policy : null,
        'amenity_ids' => $amenity_ids,
        'img' => !empty($images) ? json_encode($images) : null,
        'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'translations' => !empty($translations) ? json_encode($translations) : null
    ];

    try {
        $result = $db->insert('stays', $hotel_data);

        if ($result) {
            $new_hotel_id = $db->id();

            $active_tab = trim($_POST['active_tab'] ?? 'general');

            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::hotel_added_successfully
            ];

            redirect(root . admin . '/stays/edit/' . $new_hotel_id . '#' . $active_tab);
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_add_hotel
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/stays/add');
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/stays/');
    }
});

// ================================ GET /stays/edit/{id} - EDIT STAY FORM
$router->get(admin.'/stays/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'edit';

    $hotel_id = intval($id);

    // CHECK IF HOTEL EXISTS
    $hotel = $db->get('stays', '*', ['id' => $hotel_id]);

    if (!$hotel) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::hotel_not_found
        ];
        redirect(root . admin . '/stays');
        return;
    }

    // GET OWNER DETAILS
    $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $hotel['user_id']]);

    // GET ALL AMENITIES
    $amenities = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'stay_amenity', 'status' => 1]);

    // FETCH ACCOMMODATION TYPES
    $accommodation_types = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'accommodation', 'status' => 1]);

    // DECODE AMENITY IDS FROM JSON
    $selected_amenity_ids = !empty($hotel['amenity_ids']) ? json_decode($hotel['amenity_ids'], true) : [];
    if (!is_array($selected_amenity_ids)) {
        $selected_amenity_ids = [];
    }

    // EXTRACT COORDINATES
    $latitude = '';
    $longitude = '';
    if (!empty($hotel['location_coords'])) {
        $coords = explode(',', $hotel['location_coords']);
        if (count($coords) == 2) {
            $latitude = trim($coords[0]);
            $longitude = trim($coords[1]);
        }
    }

    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    $hotel_images = [];
    if (!empty($hotel['img'])) {
        $hotel_images = json_decode($hotel['img'], true);
        if (!is_array($hotel_images)) {
            $hotel_images = [];
        }
    }

    $name_translations = [];
    $desc_translations = [];
    $address_translations = [];
    $cancellation_policy_translations = [];
    $privacy_policy_translations = [];

    $translations = $hotel['translations'];
    if (!empty($hotel['translations'])) {
        $all_translations = json_decode($hotel['translations'], true);

        if (is_array($all_translations)) {
            // Loop through each language code and extract translations
            foreach ($all_translations as $lang_code => $translations_data) {
                // Extract name translation
                if (!empty($translations_data['name'])) {
                    $name_translations[$lang_code] = $translations_data['name'];
                }

                // Extract description translation
                if (!empty($translations_data['desc'])) {
                    $desc_translations[$lang_code] = $translations_data['desc'];
                }

                // Extract address translation
                if (!empty($translations_data['address'])) {
                    $address_translations[$lang_code] = $translations_data['address'];
                }

                // Extract cancellation policy translation
                if (!empty($translations_data['cancellation_policy'])) {
                    $cancellation_policy_translations[$lang_code] = $translations_data['cancellation_policy'];
                }

                // Extract privacy policy translation
                if (!empty($translations_data['privacy_policy'])) {
                    $privacy_policy_translations[$lang_code] = $translations_data['privacy_policy'];
                }
            }
        }
    }
    // META DATA
    $title = T::edit_hotel;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/stay.php";
    require_once views."includes/footer.php";
});

// ================================ POST /stays/edit/{id} - UPDATE STAY
$router->post(admin.'/stays/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $hotel_id = intval($id);

    // CHECK IF HOTEL EXISTS
    $hotel = $db->get('stays', '*', ['id' => $hotel_id]);

    if (!$hotel) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::hotel_not_found
        ];
        redirect(root . admin . '/stays');
        return;
    }

    // FORM DATA VALIDATION
    $name = trim($_POST['hotel_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (isset($_POST['user_id'])) {
        $user_id = ($_POST['user_id'] !== '') ? $_POST['user_id'] : 0;
    } else {
        $user_id = $hotel['user_id'];
    }
    
    // Ensure user_id is integer
    $user_id = $user_id !== '' ? $user_id : 0;
    
    $location = trim($_POST['location'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $stars = intval($_POST['stars'] ?? 0);
    $rating = floatval($_POST['rating'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'USD');
    $discount = intval($_POST['discount'] ?? 0);
    $stay_type = intval($_POST['stay_type'] ?? 0);

    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');

    $checkin_time = trim($_POST['checkin_time'] ?? '14:00:00');
    $checkout_time = trim($_POST['checkout_time'] ?? '12:00:00');
    $booking_age_requirement = intval($_POST['booking_age_requirement'] ?? 18);
    $refundable = isset($_POST['refundable']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;

    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');

    $amenity_ids = trim($_POST['amenity_ids'] ?? '[]');

    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $privacy_policy = trim($_POST['privacy_policy'] ?? '');

    // VALIDATION RULES
    $errors = [];

    if (empty($name)) {
        $errors[] = T::hotel_name_required;
    }

    if (empty($location)) {
        $errors[] = T::location_required;
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/stays/edit/' . $hotel_id);
        return;
    }

    // ===== IMPROVED IMAGE HANDLING =====
    // Define project root early for all file operations
    $project_root = realpath(__DIR__ . '/../../../');

    // Get reordered images from form (if drag-drop was used)
    $reordered_images = json_decode($_POST['reordered_images'] ?? '[]', true);

    // Get existing images from database (img column) or use reordered
    $images = !empty($reordered_images) ? $reordered_images : (!empty($hotel['img']) ? json_decode($hotel['img'], true) : []);
    if (!is_array($images)) {
        $images = [];
    }

    // Get images marked for deletion
    $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true);
    if (!is_array($images_to_delete)) {
        $images_to_delete = [];
    }

    // Get selected default image URL
    $default_image_url = trim($_POST['default_image'] ?? '');

    // Delete physical files FIRST before updating database
    if (!empty($images_to_delete)) {
        foreach ($images_to_delete as $image_url) {
            // Clean the URL - remove leading slash if present
            $clean_url = ltrim($image_url, '/');
            
            // Construct full file path
            $file_path = $project_root . '/' . $clean_url;
            
            // Attempt to delete the file
            if (file_exists($file_path)) {
                if (@unlink($file_path)) {
                    error_log("Hotel image deleted: " . $file_path);
                } else {
                    error_log("Failed to delete hotel image: " . $file_path);
                }
            } else {
                error_log("Hotel image file not found: " . $file_path);
            }
        }

        // Filter out deleted images
        $images = array_values(array_filter($images, function($img) use ($images_to_delete) {
            return !in_array($img['url'], $images_to_delete);
        }));
    }

    // Check if there's already a default image
    $has_default = false;
    foreach ($images as $img) {
        if (!empty($img['default'])) {
            $has_default = true;
            break;
        }
    }

    // HANDLE NEW IMAGE UPLOADS
    if (isset($_FILES['hotel_images']) && !empty($_FILES['hotel_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/hotels/gallery/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
            error_log("Created directory: $upload_dir");
        }

        $files = $_FILES['hotel_images'];
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // SECURITY: validate real MIME + safe extension (finfo).
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'hotel_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/hotels/gallery/' . $new_filename;

                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    // First uploaded image is default ONLY if no existing default
                    $images[] = [
                        'url' => $image_url,
                        'default' => ($i === 0 && !$has_default)
                    ];
                }
            }
        }
    }

    // Handle manual default selection
    if (!empty($default_image_url)) {
        // Reset all defaults
        foreach ($images as &$img) {
            $img['default'] = false;
        }
        unset($img);

        // Set selected image as default
        foreach ($images as &$img) {
            if ($img['url'] === $default_image_url) {
                $img['default'] = true;
                break;
            }
        }
        unset($img);
    }

    // CREATE SLUG FROM NAME
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

    // CREATE LOCATION COORDINATES STRING
    $location_coords = '';
    if (!empty($latitude) && !empty($longitude)) {
        $location_coords = $latitude . ',' . $longitude;
    }

    $translations = [];

    // Get all translation arrays
    $name_translations = $_POST['name_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    $address_translations = $_POST['address_translations'] ?? [];
    $cancellation_policy_translations = $_POST['cancellation_policy_translations'] ?? [];
    $privacy_policy_translations = $_POST['privacy_policy_translations'] ?? [];

    // Get all unique language codes
    $all_lang_codes = array_unique(array_merge(
        array_keys($name_translations),
        array_keys($desc_translations),
        array_keys($address_translations),
        array_keys($cancellation_policy_translations),
        array_keys($privacy_policy_translations)
    ));

    // Restructure: Group by language code instead of by field
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];

        // Add name if exists
        if (!empty(trim($name_translations[$lang_code] ?? ''))) {
            $lang_data['name'] = trim($name_translations[$lang_code]);
        }

        // Add description if exists
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) {
            $lang_data['desc'] = trim($desc_translations[$lang_code]);
        }

        // Add address if exists
        if (!empty(trim($address_translations[$lang_code] ?? ''))) {
            $lang_data['address'] = trim($address_translations[$lang_code]);
        }

        // Add cancellation policy if exists
        if (!empty(trim($cancellation_policy_translations[$lang_code] ?? ''))) {
            $lang_data['cancellation_policy'] = trim($cancellation_policy_translations[$lang_code]);
        }

        // Add privacy policy if exists
        if (!empty(trim($privacy_policy_translations[$lang_code] ?? ''))) {
            $lang_data['privacy_policy'] = trim($privacy_policy_translations[$lang_code]);
        }

        // Only add this language if it has at least one translation
        if (!empty($lang_data)) {
            $translations[$lang_code] = $lang_data;
        }
    }

    // PREPARE DATA FOR UPDATE
    $hotel_data = [
        'user_id' => ($user_id > 0) ? $user_id : null,
        'name' => $name,
        'desc' => !empty($description) ? $description : null,
        'slug' => $slug,
        'location' => $location,
        'location_coords' => !empty($location_coords) ? $location_coords : null,
        'address' => $address,
        'stars' => $stars > 0 ? $stars : null,
        'rating' => $rating > 0 ? $rating : null,
        'currency' => $currency,
        'discount' => $discount > 0 ? $discount : null,
        'stay_type' => $stay_type > 0 ? $stay_type : null,
        'email' => !empty($email) ? $email : null,
        'phone' => !empty($phone) ? $phone : null,
        'website' => !empty($website) ? $website : null,
        'checkin_time' => $checkin_time,
        'checkout_time' => $checkout_time,
        'booking_age_requirement' => $booking_age_requirement,
        'refundable' => $refundable,
        'featured' => $featured,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'cancellation_policy' => !empty($cancellation_policy) ? $cancellation_policy : null,
        'privacy_policy' => !empty($privacy_policy) ? $privacy_policy : null,
        'amenity_ids' => $amenity_ids,
        'img' => !empty($images) ? json_encode($images) : null,
        'updated_at' => date('Y-m-d H:i:s'),
        'translations' => !empty($translations) ? json_encode($translations) : null
    ];

    try {
        // UPDATE STAYS TABLE
        $result = $db->update('stays', $hotel_data, ['id' => $hotel_id]);

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::hotel_updated_successfully
        ];

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    // Get active tab for redirect
    $active_tab = trim($_POST['active_tab'] ?? 'general');
    redirect(root . admin . '/stays/edit/' . $hotel_id . '#' . $active_tab);
});

// ================================ POST /stays/delete - DELETE STAY
$router->post(admin.'/stays/delete', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $hotel_id = intval($_POST['id'] ?? 0);

    if ($hotel_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_hotel_id
        ];
        redirect(root . admin . '/stays');
        return;
    }

    // CHECK IF HOTEL EXISTS
    $hotel = $db->get('stays', ['id', 'img'], ['id' => $hotel_id]);

    if (!$hotel) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::hotel_not_found
        ];
        redirect(root . admin . '/stays');
        return;
    }

    try {
        $project_root = realpath(__DIR__ . '/../../../');
        
        // DELETE IMAGES IF EXISTS
        if (!empty($hotel['img'])) {
            $images = json_decode($hotel['img'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (!empty($img['url'])) {
                        // Clean the URL - remove leading slash if present
                        $clean_url = ltrim($img['url'], '/');
                        
                        // Construct full file path
                        $file_path = $project_root . '/' . $clean_url;
                        
                        // Attempt to delete the file
                        if (file_exists($file_path)) {
                            if (@unlink($file_path)) {
                                error_log("Hotel image deleted during hotel deletion: " . $file_path);
                            } else {
                                error_log("Failed to delete hotel image during hotel deletion: " . $file_path);
                            }
                        }
                    }
                }
            }
        }

        // DELETE HOTEL FROM DATABASE
        $result = $db->delete('stays', ['id' => $hotel_id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::deleted_successfully
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_hotel
            ];
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/hotels');
});

// ================================ GET /hotels/view/{id} - VIEW HOTEL DETAILS
$router->get(admin.'/stays/view/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'view';

    $hotel_id = intval($id);

    // FETCH HOTEL DETAILS
    $hotel = $db->get('hotels', '*', ['id' => $hotel_id]);

    if (empty($hotel)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::hotel_not_found
        ];
        redirect(root . admin . '/hotels');
        return;
    }

    // GET OWNER DETAILS
    $owner = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $hotel['user_id']]);

    // GET SELECTED AMENITIES
    $selected_amenity_ids = !empty($hotel['amenity_ids']) ? json_decode($hotel['amenity_ids'], true) : [];
    if (!is_array($selected_amenity_ids)) {
        $selected_amenity_ids = [];
    }

    // FETCH AMENITIES LIST
    $amenities_list = [];
    if (!empty($selected_amenity_ids)) {
        $amenities_list = $db->select('hotels_settings', ['id', 'name', 'translations'], ['id' => $selected_amenity_ids, 'setting_type' => 'hotel_amenity', 'status' => 1]);
    }

    // EXTRACT COORDINATES
    $latitude = '';
    $longitude = '';
    if (!empty($hotel['location_coords'])) {
        $coords = explode(',', $hotel['location_coords']);
        if (count($coords) == 2) {
            $latitude = trim($coords[0]);
            $longitude = trim($coords[1]);
        }
    }

    // META DATA
    $title = T::view_hotel . ': ' . $hotel['name'];
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/hotel.php";
    require_once views."includes/footer.php";
});

// ================================ POST /stays/search-users - AJAX USER SEARCH
$router->post(admin.'/stays/search-users', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    $search = trim($_POST['search'] ?? '');

    if (empty($search)) {
        echo json_encode(['success' => false, 'users' => []]);
        return;
    }

    // Search users by email, first_name, or last_name
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

// ================================ POST /hotels/search-locations - AJAX LOCATION SEARCH
$router->post(admin.'/stays/search-locations', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    $search = trim($_POST['search'] ?? '');

    if (empty($search)) {
        echo json_encode(['success' => false, 'locations' => []]);
        return;
    }

    // Search locations by city, country, or country_code
    $locations = $db->select('locations',
        ['id', 'city', 'country', 'country_code', 'latitude', 'longitude'],
        [
            'AND' => [
                'status' => '1',
                'OR' => [
                    'city[~]' => $search,
                    'country[~]' => $search,
                    'country_code[~]' => $search
                ]
            ],
            'LIMIT' => 10
        ]
    );

    echo json_encode(['success' => true, 'locations' => $locations]);
});

// ================================ GET /stays/calendar/{id}/rates - RATES JSON FOR A MONTH
$router->get(admin.'/stays/calendar/(.*)/rates', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $hotel_id = intval($id);
    $year     = intval($_GET['year']  ?? date('Y'));
    $month    = intval($_GET['month'] ?? date('n'));

    if ($month < 1 || $month > 12 || $year < 2000) {
        echo json_encode(['success' => false, 'rates' => []]);
        return;
    }

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));

    $rows = $db->select('stays_rooms_calendar',
        ['room_id', 'option_id', 'date', 'price'],
        ['stay_id' => $hotel_id, 'date[>=]' => $start, 'date[<=]' => $end]
    );

    $rates = [];
    foreach ($rows as $row) {
        $rates[$row['room_id'] . '_' . $row['option_id'] . '_' . $row['date']] = floatval($row['price']);
    }

    echo json_encode(['success' => true, 'rates' => $rates]);
});

// ================================ POST /stays/calendar/{id}/save - SAVE RATES TO DB
$router->post(admin.'/stays/calendar/(.*)/save', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $hotel_id = intval($id);
    $body     = json_decode(file_get_contents('php://input'), true);

    if (!isset($body['rates']) || !is_array($body['rates'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid payload']);
        return;
    }

    $saved = 0;
    $sql   = "INSERT INTO `stays_rooms_calendar` (`stay_id`,`room_id`,`option_id`,`date`,`price`)
              VALUES (?,?,?,?,?)
              ON DUPLICATE KEY UPDATE `price`=VALUES(`price`), `updated_at`=CURRENT_TIMESTAMP";
    $stmt  = $db->pdo->prepare($sql);

    foreach ($body['rates'] as $key => $price) {
        // key format: "roomId_optionId_YYYY-MM-DD"
        if (!preg_match('/^(\d+)_(\d+)_(\d{4}-\d{2}-\d{2})$/', (string)$key, $m)) continue;
        $room_id   = intval($m[1]);
        $option_id = intval($m[2]);
        $date      = $m[3];
        $price_val = round(floatval($price), 2);
        if ($room_id <= 0 || $option_id <= 0) continue;

        $stmt->execute([$hotel_id, $room_id, $option_id, $date, $price_val]);
        $saved++;
    }

    echo json_encode(['success' => true, 'saved' => $saved]);
});

// ================================ GET /stays/calendar/{id} - RATES CALENDAR VIEW
// AdminStaysCalendarController → index($hotel_id)
// Route name: admin.stays.calendar
$router->get(admin.'/stays/calendar/(.*)', function ($id) use ($SECURE, $db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $hotel_id = intval($id);

    // CHECK IF HOTEL EXISTS
    $hotel = $db->get('stays', ['id', 'name', 'location', 'currency'], ['id' => $hotel_id]);

    if (!$hotel) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::hotel_not_found ?? 'Hotel not found'
        ];
        redirect(root . admin . '/stays');
        return;
    }

    // ── FETCH ROOMS (all rooms for this hotel, regardless of status) ─────
    $roomsRaw = $db->select('stays_rooms', ['id', 'room_type_id', 'room_options'], [
        'stay_id' => $hotel_id
    ]);

    // Room type name map
    $roomTypeRows = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'room_type']);
    $roomTypeMap  = [];
    foreach ($roomTypeRows as $rt) {
        $n = $rt['name'] ?? '';
        if (empty($n) && !empty($rt['translations'])) {
            $t = json_decode($rt['translations'], true);
            $n = is_array($t) ? ($t['en'] ?? reset($t)) : '';
        }
        $roomTypeMap[$rt['id']] = $n ?: 'Room #' . $rt['id'];
    }

    // Board name map
    $boardRows = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'board']);
    $boardMap  = [];
    foreach ($boardRows as $b) {
        $n = $b['name'] ?? '';
        if (empty($n) && !empty($b['translations'])) {
            $t = json_decode($b['translations'], true);
            $n = is_array($t) ? ($t['en'] ?? reset($t)) : '';
        }
        $boardMap[$b['id']] = $n ?: 'Board #' . $b['id'];
    }

    // Build calendar rooms
    $calendarRooms = [];
    foreach ($roomsRaw as $room) {
        $roomName = $roomTypeMap[$room['room_type_id']] ?? 'Room #' . $room['id'];
        $options  = [];
        if (!empty($room['room_options'])) {
            $decoded = json_decode($room['room_options'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $idx => $opt) {
                    if (!empty($opt['board_id']) && isset($boardMap[$opt['board_id']])) {
                        $optName = $boardMap[$opt['board_id']];
                    } elseif (!empty($opt['breakfast_included'])) {
                        $optName = 'Bed & Breakfast';
                    } else {
                        $optName = 'Room Only';
                    }
                    if (!empty($opt['max_adults'])) {
                        $optName .= ' (' . intval($opt['max_adults']) . ' Adults)';
                    }
                    $options[] = [
                        'id'    => $idx + 1,
                        'name'  => $optName,
                        'price' => floatval($opt['price'] ?? 0),
                    ];
                }
            }
        }
        if (empty($options)) {
            $options[] = ['id' => 1, 'name' => 'Standard', 'price' => 0];
        }
        $calendarRooms[] = [
            'id'      => intval($room['id']),
            'name'    => $roomName,
            'options' => $options,
        ];
    }

    // ── CREATE TABLE IF NOT EXISTS stays_rooms_calendar ─────────────────
    try {
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS `stays_rooms_calendar` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `stay_id`    INT UNSIGNED NOT NULL,
            `room_id`    INT UNSIGNED NOT NULL,
            `option_id`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `date`       DATE NOT NULL,
            `price`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_stay_room_opt_date` (`stay_id`,`room_id`,`option_id`,`date`),
            INDEX `idx_stay_date` (`stay_id`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* already exists */ }

    // ── LOAD EXISTING RATES FOR CURRENT MONTH ───────────────────────────
    $curYear  = intval(date('Y'));
    $curMonth = intval(date('n'));
    $rStart   = sprintf('%04d-%02d-01', $curYear, $curMonth);
    $rEnd     = date('Y-m-t', strtotime($rStart));

    $rateRows = $db->select('stays_rooms_calendar',
        ['room_id', 'option_id', 'date', 'price'],
        ['stay_id' => $hotel_id, 'date[>=]' => $rStart, 'date[<=]' => $rEnd]
    );

    $existingRates = [];
    foreach ($rateRows as $r) {
        $existingRates[$r['room_id'] . '_' . $r['option_id'] . '_' . $r['date']] = floatval($r['price']);
    }

    // META DATA
    $title = 'Rates Calendar: ' . htmlspecialchars($hotel['name']);
    $description = '';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/stays/calendar/index.php";
    require_once views . "includes/footer.php";
});

/*===================================================================
HOTELS ROUTES END
===================================================================*/


/*===================================================================
ROOMS ROUTES START
===================================================================*/

// LIST ROOMS
$router->get(admin.'/stays/rooms/(.*)', function ($hotel_id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $hotel_id = intval($hotel_id);
    $hotel = $db->get('hotels', ['id', 'name'], ['id' => $hotel_id]);
    if (!$hotel) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::hotel_not_found];
        redirect(root . admin . '/hotels');
        return;
    }
    $title = 'Rooms Management - ' . $hotel['name'];
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/stays/rooms.php";
    require_once views."includes/footer.php";
});

// SAVE ROOM (ADD/EDIT)
$router->post(admin.'/stays/rooms/save', function () use ($SECURE,$db) {
    // Set JSON header at the very beginning
    header('Content-Type: application/json');

    // Clear any output buffers
    if (ob_get_level()) ob_clean();

    ADMIN_AUTH();

    $room_id = intval($_POST['room_id'] ?? 0);
    $hotel_id = intval($_POST['hotel_id'] ?? 0);
    $room_type_id = intval($_POST['room_type_id'] ?? 0);
    $status = isset($_POST['room_status']) ? 1 : 0;
    $amenities = trim($_POST['amenities'] ?? '[]');
    $isEdit = $room_id > 0;

    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    // VALIDATION
    $errors = [];
    if ($hotel_id <= 0) $errors[] = T::invalid_hotel_id ?? 'Invalid hotel ID';
    if ($room_type_id <= 0) $errors[] = T::please_select_room_type ?? 'Please select a room type';

    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
        $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit;
        }

        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/hotels/rooms/' . $hotel_id);
        return;
    }

    // Define project root early
    $project_root = realpath(__DIR__ . '/../../../');
    $room_images = [];

    if ($isEdit) {
        // Verify room belongs to hotel
        $existing_room = $db->get('stays_rooms', '*', [
            'id' => $room_id,
            'stay_id' => $hotel_id
        ]);

        if (!$existing_room) {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::room_not_found ?? 'Room not found or does not belong to this hotel'];

            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => T::room_not_found ?? 'Room not found or does not belong to this hotel']);
                exit;
            }

            redirect(root . admin . '/stays/rooms/' . $hotel_id);
            return;
        }

        $reordered_images = json_decode($_POST['reordered_images'] ?? '[]', true);
        $room_images = !empty($reordered_images) ? $reordered_images : (!empty($existing_room['room_images']) ? json_decode($existing_room['room_images'], true) : []);
        if (!is_array($room_images)) $room_images = [];

        $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true);
        if (!is_array($images_to_delete)) $images_to_delete = [];

        // Delete physical files FIRST
        if (!empty($images_to_delete)) {
            foreach ($images_to_delete as $image_url) {
                // Clean the URL - remove leading slash if present
                $clean_url = ltrim($image_url, '/');
                
                // Construct full file path
                $file_path = $project_root . '/' . $clean_url;
                
                // Attempt to delete the file
                if (file_exists($file_path)) {
                    if (@unlink($file_path)) {
                        error_log("Room image deleted: " . $file_path);
                    } else {
                        error_log("Failed to delete room image: " . $file_path);
                    }
                } else {
                    error_log("Room image file not found: " . $file_path);
                }
            }
            
            $room_images = array_values(array_filter($room_images, function($img) use ($images_to_delete) {
                return !in_array($img['url'], $images_to_delete);
            }));
        }

        $has_default = false;
        foreach ($room_images as $img) {
            if (!empty($img['default'])) {
                $has_default = true;
                break;
            }
        }
    } else {
        $has_default = false;
    }

    // HANDLE NEW IMAGE UPLOADS
    if (isset($_FILES['room_images']) && !empty($_FILES['room_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/hotels/rooms/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $files = $_FILES['room_images'];
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $new_filename = 'room_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/hotels/rooms/' . $new_filename;

                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    $room_images[] = ['url' => $image_url, 'default' => ($i === 0 && !$has_default)];
                }
            } else {
                error_log("Upload error for file $i: " . $files['error'][$i]);
            }
        }
    } else {
    }

    // HANDLE DEFAULT IMAGE SELECTION
    $default_image_url = trim($_POST['default_image'] ?? '');
    if (!empty($default_image_url)) {
        foreach ($room_images as &$img) $img['default'] = false;
        unset($img);
        foreach ($room_images as &$img) {
            if ($img['url'] === $default_image_url) {
                $img['default'] = true;
                break;
            }
        }
        unset($img);
    }

    // PREPARE DATA
    $room_data = [
        'stay_id' => $hotel_id,
        'room_type_id' => $room_type_id,
        'room_images' => !empty($room_images) ? json_encode($room_images) : null,
        'status' => $status,
        'amenities' => $amenities,
        'room_options' => $isEdit ? ($existing_room['room_options'] ?? null) : null,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        if ($isEdit) {
            // UPDATE ROOM
            $db->update('stays_rooms', $room_data, [
                'id' => $room_id,
                'stay_id' => $hotel_id
            ]);

            $success_message = T::room_updated_successfully ?? 'Room updated successfully';
            $_SESSION['message'] = ['type' => 'success', 'text' => $success_message];

            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'message' => $success_message,
                    'room_id' => $room_id,
                    'images_uploaded' => count($room_images)
                ]);
                exit;
            }

        } else {
            // INSERT NEW ROOM
            $room_data['created_at'] = date('Y-m-d H:i:s');
            $result = $db->insert('stays_rooms', $room_data);
            $new_room_id = $db->id();

            $success_message = T::room_added_successfully ?? 'Room added successfully';
            $_SESSION['message'] = ['type' => 'success', 'text' => $success_message];

            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'message' => $success_message,
                    'room_id' => $new_room_id,
                    'images_uploaded' => count($room_images)
                ]);
                exit;
            }
        }

        // Only redirect if not AJAX
        if (!$isAjax) {
            redirect(root . admin . '/stays/rooms/' . $hotel_id);
        }

    } catch (Exception $e) {
        $error_message = (T::database_error ?? 'Database error') . ': ' . $e->getMessage();
        $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

        error_log("Room save error: " . $e->getMessage());

        if ($isAjax) {
            echo json_encode([
                'success' => false,
                'message' => $error_message
            ]);
            exit;
        }

        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/hotels/rooms/' . $hotel_id);
    }
});

// GET ROOM DATA (AJAX only - for editing)
$router->post(admin.'/stays/rooms/get-room-data', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $room_id = intval($_POST['room_id'] ?? 0);
    $hotel_id = intval($_POST['hotel_id'] ?? 0);

    if ($room_id <= 0) {
        echo json_encode(['success' => false, 'message' => T::invalid_room_id ?? 'Invalid room ID']);
        return;
    }

    // Fetch room with hotel_id verification
    $room = $db->get('stays_rooms', '*', [
        'id' => $room_id,
        'stay_id' => $hotel_id
    ]);

    if (!$room) {
        echo json_encode(['success' => false, 'message' => T::room_not_found ?? 'Room not found or does not belong to this hotel']);
        return;
    }

    // Parse images
    $images = !empty($room['room_images']) ? json_decode($room['room_images'], true) : [];
    if (!is_array($images)) $images = [];

    // Get default image
    $default_image = '';
    if (!empty($images)) {
        $default = array_values(array_filter($images, fn($img) => !empty($img['default'])));
        $default_image = !empty($default) ? $default[0]['url'] : ($images[0]['url'] ?? '');
    }

    // Parse options
    $options = !empty($room['room_options']) ? json_decode($room['room_options'], true) : [];
    if (!is_array($options)) $options = [];

    // Parse amenities
    $amenities = !empty($room['amenities']) ? json_decode($room['amenities'], true) : [];
    if (!is_array($amenities)) $amenities = [];

    // Keep translations as JSON string (frontend will decode)
    $translations = $room['translations'] ?? '{}';

    echo json_encode([
        'success' => true,
        'room' => [
            'id' => $room['id'],
            'stay_id' => $room['stay_id'],
            'room_type_id' => $room['room_type_id'],
            'status' => $room['status'],
            'images' => $images,
            'default_image' => $default_image,
            'options' => $options,
            'amenities' => $amenities,
            'translations' => $translations
        ]
    ]);
});

// DELETE ROOM
$router->post(admin.'/stays/rooms/delete', function () use ($SECURE,$db) {
    // Set JSON header at the very beginning
    header('Content-Type: application/json');

    // Disable any output buffering that might add extra content
    if (ob_get_level()) ob_clean();

    ADMIN_AUTH();

    $room_id = intval($_POST['room_id'] ?? 0);
    $hotel_id = intval($_POST['hotel_id'] ?? 0);

    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if ($room_id <= 0) {
        $error_message = T::invalid_room_id ?? 'Invalid room ID';
        $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit;
        }

        redirect(root . admin . '/hotels/rooms/' . $hotel_id);
        return;
    }

    try {
        $room = $db->get('stays_rooms', '*', ['id' => $room_id]);

        if (!$room) {
            $error_message = T::room_not_found ?? 'Room not found';
            $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

            if ($isAjax) {
                echo json_encode(['success' => false, 'message' => $error_message]);
                exit;
            }

            redirect(root . admin . '/hotels/rooms/' . $hotel_id);
            return;
        }

        $project_root = realpath(__DIR__ . '/../../../');
        
        // DELETE IMAGES IF EXISTS
        if (!empty($room['room_images'])) {
            $images = json_decode($room['room_images'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (isset($img['url'])) {
                        // Clean the URL - remove leading slash if present
                        $clean_url = ltrim($img['url'], '/');
                        
                        // Construct full file path
                        $file_path = $project_root . '/' . $clean_url;
                        
                        // Attempt to delete the file
                        if (file_exists($file_path)) {
                            if (@unlink($file_path)) {
                                error_log("Room image deleted during room deletion: " . $file_path);
                            } else {
                                error_log("Failed to delete room image during room deletion: " . $file_path);
                            }
                        }
                    }
                }
            }
        }

        // DELETE ROOM FROM DATABASE
        $result = $db->delete('stays_rooms', ['id' => $room_id]);

        if ($result) {
            $success_message = T::room_deleted_successfully ?? 'Room deleted successfully';
            $_SESSION['message'] = ['type' => 'success', 'text' => $success_message];

            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'message' => $success_message
                ]);
                exit;
            }
        } else {
            $error_message = T::failed_to_delete_room ?? 'Failed to delete room';
            $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

            if ($isAjax) {
                echo json_encode([
                    'success' => false,
                    'message' => $error_message
                ]);
                exit;
            }
        }

    } catch (Exception $e) {
        $error_message = (T::database_error ?? 'Database error') . ': ' . $e->getMessage();
        $_SESSION['message'] = ['type' => 'error', 'text' => $error_message];

        if ($isAjax) {
            echo json_encode([
                'success' => false,
                'message' => $error_message
            ]);
            exit;
        }
    }

    // Only redirect if not AJAX
    if (!$isAjax) {
        redirect(root . admin . '/stays/rooms/' . $hotel_id);
    }
});

// SAVE ROOM OPTION
$router->post('/admin/stays/rooms/options/save', function() use ($db) {
    // ADMIN AUTH CHECK — this writes hotel room-option/pricing data. It was
    // guarded ONLY by an X-Requested-With header check (trivially forgeable, not
    // authentication), so an unauthenticated request could modify any hotel's
    // room options. Every sibling handler in this file calls ADMIN_AUTH(); this
    // one (and its /delete pair) were missed.
    ADMIN_AUTH();
    // Check if it's AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => 'Invalid request']));
    }

    $room_id = intval($_POST['room_id'] ?? 0);
    $hotel_id = intval($_POST['hotel_id'] ?? 0);
    $option_index = intval($_POST['option_index'] ?? -1);

    if ($room_id === 0 || $hotel_id === 0) {
        die(json_encode(['success' => false, 'message' => 'Invalid room or hotel ID']));
    }

    try {
        // Get existing room data
        $room = $db->get('stays_rooms', '*', ['id' => $room_id, 'stay_id' => $hotel_id]);
        if (!$room) {
            die(json_encode(['success' => false, 'message' => 'Room not found']));
        }

        // Parse existing room options
        $room_options = [];
        if (!empty($room['room_options'])) {
            $room_options = json_decode($room['room_options'], true) ?? [];
        }

        // Prepare new option data with SINGLE PRICE and REFUNDABLE
        $new_option = [
            'max_adults' => intval($_POST['max_adults'] ?? 2),
            'max_children' => intval($_POST['max_children'] ?? 0),
            'price' => floatval($_POST['price'] ?? 0),
            'discount_percentage' => floatval($_POST['discount_percentage'] ?? 0),
            'extra_bed_available' => isset($_POST['extra_bed_available']) ? 1 : 0,
            'extra_bed_charge' => floatval($_POST['extra_bed_charge'] ?? 0),
            'breakfast_included' => isset($_POST['breakfast_included']) ? 1 : 0,
            'cancellation_free' => isset($_POST['cancellation_free']) ? 1 : 0,
            'refundable' => isset($_POST['refundable']) ? 1 : 0,
            'available_quantity' => intval($_POST['available_quantity'] ?? 1),
            'status' => isset($_POST['status']) ? 1 : 0,
            'board_id' => intval($_POST['board_id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add or update option
        if ($option_index >= 0 && isset($room_options[$option_index])) {
            // Update existing option
            $room_options[$option_index] = array_merge($room_options[$option_index], $new_option);
            $room_options[$option_index]['updated_at'] = date('Y-m-d H:i:s');
        } else {
            // Add new option
            $room_options[] = $new_option;
        }

        // Save to database
        $db->update('stays_rooms', [
            'room_options' => json_encode($room_options),
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $room_id,
            'stay_id' => $hotel_id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Room option saved successfully',
            'option' => $new_option,
            'option_index' => $option_index >= 0 ? $option_index : count($room_options) - 1
        ]);

    } catch (Exception $e) {
        error_log('Room option save error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save room option: ' . $e->getMessage()
        ]);
    }
});

// DELETE ROOM OPTION
$router->post('/admin/stays/rooms/options/delete', function() use ($db) {

    // ADMIN AUTH CHECK — deletes a hotel room option. Was guarded only by a
    // forgeable X-Requested-With header (not authentication). See the /save pair.
    ADMIN_AUTH();
    // Check if it's AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => 'Invalid request']));
    }

    $room_id = intval($_POST['room_id'] ?? 0);
    $hotel_id = intval($_POST['hotel_id'] ?? 0);
    $option_index = intval($_POST['option_index'] ?? -1);

    if ($room_id === 0 || $hotel_id === 0 || $option_index < 0) {
        die(json_encode(['success' => false, 'message' => 'Invalid parameters']));
    }

    try {
        // Get existing room data
        $room = $db->get('stays_rooms', '*', ['id' => $room_id, 'stay_id' => $hotel_id]);
        if (!$room) {
            die(json_encode(['success' => false, 'message' => 'Room not found']));
        }

        // Parse existing room options
        $room_options = [];
        if (!empty($room['room_options'])) {
            $room_options = json_decode($room['room_options'], true) ?? [];
        }

        // Remove the option
        if (isset($room_options[$option_index])) {
            array_splice($room_options, $option_index, 1);

            // Save to database
            $db->update('stays_rooms', [
                'room_options' => json_encode($room_options),
                'updated_at' => date('Y-m-d H:i:s')
            ], [
                'id' => $room_id,
                'stay_id' => $hotel_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Room option deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Option not found'
            ]);
        }

    } catch (Exception $e) {
        error_log('Room option delete error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete room option: ' . $e->getMessage()
        ]);
    }
});

/*===================================================================
ROOMS ROUTES END
===================================================================*/