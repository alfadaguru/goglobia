<?php
// app/routes/admin/toursSettingsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
TOURS ROUTES START
===================================================================*/

// ================================ GET /tours - LIST ALL TOURS
$router->get(admin.'/tours', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::tours_management ?? 'Tours Management';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/tours/tours.php";
    require_once views."includes/footer.php";
});

// ================================ GET /tours/add - ADD NEW TOUR FORM
$router->get(admin.'/tours/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'add';
    
    $tour_types = $db->select('tours_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'tour_type', 'status' => 1]);
    $inclusions = $db->select('tours_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'inclusion', 'status' => 1]);
    $exclusions = $db->select('tours_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'exclusion', 'status' => 1]);
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];
    
    $title = T::add_tour ?? 'Add Tour';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/tours/manage-tours.php";
    require_once views."includes/footer.php";
});

// ================================ POST /tours/add - ADD NEW TOUR
$router->post(admin.'/tours/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    
    $name = trim($_POST['tour_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currency = trim($_POST['currency'] ?? 'USD');
    $adult_price = floatval($_POST['adult_price'] ?? 0);
    $child_price = floatval($_POST['child_price'] ?? 0);
    $infant_price = floatval($_POST['infant_price'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $max_adults = intval($_POST['max_adults'] ?? 1);
    $max_children = intval($_POST['max_children'] ?? 0);
    $max_infants = intval($_POST['max_infants'] ?? 0);
    $days = intval($_POST['days'] ?? 1);
    $nights = intval($_POST['nights'] ?? 0);
    $tour_type_id = intval($_POST['tour_type_id'] ?? 0);
    $stars = intval($_POST['stars'] ?? 0);
    $refundable = isset($_POST['refundable']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $inclusions = trim($_POST['inclusions'] ?? '[]');
    $exclusions = trim($_POST['exclusions'] ?? '[]');
    $amenities = trim($_POST['amenities'] ?? '[]');
    $itinerary = trim($_POST['itinerary'] ?? '[]');
    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    
    $errors = [];
    if (empty($name)) $errors[] = T::tour_name_required ?? 'Tour name is required';
    if (empty($location)) $errors[] = T::location_required ?? 'Location is required';
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/add');
        return;
    }
    
    // Handle itinerary activity images
    $itinerary_data = json_decode($itinerary, true);
    if (is_array($itinerary_data)) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/tours/itinerary/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        foreach ($itinerary_data as &$day) {
            if (!empty($day['activities']) && is_array($day['activities'])) {
                foreach ($day['activities'] as $act_idx => &$activity) {
                    // Initialize images array if not exists
                    if (!isset($activity['images'])) {
                        $activity['images'] = [];
                    }
                    
                    // Process each uploaded image for this activity
                    $img_idx = 0;
                    while (true) {
                        $file_key = 'activity_image_' . $day['day'] . '_' . $act_idx . '_' . $img_idx;
                        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
                            break; // No more files for this activity
                        }
                        
                        $ext = pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION);
                        $new_filename = 'activity_' . time() . '_' . uniqid() . '_' . $img_idx . '.' . $ext;
                        $upload_path = $upload_dir . $new_filename;
                        $image_url = '/uploads/tours/itinerary/' . $new_filename;
                        
                        if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_path)) {
                            $activity['images'][] = ['url' => $image_url];
                        }
                        
                        $img_idx++;
                    }
                }
            }
        }
        $itinerary = json_encode($itinerary_data);
    }
    
    $images = [];
    if (isset($_FILES['tour_images']) && !empty($_FILES['tour_images']['name'][0])) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/tours/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $files = $_FILES['tour_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $new_filename = 'tour_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/tours/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    $images[] = ['url' => $image_url, 'default' => $i === 0];
                }
            }
        }
    }
    
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    
    $translations = [];
    $name_translations = $_POST['name_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    $address_translations = $_POST['address_translations'] ?? [];
    $cancellation_policy_translations = $_POST['cancellation_policy_translations'] ?? [];
    $terms_conditions_translations = $_POST['terms_conditions_translations'] ?? [];
    
    $all_lang_codes = array_unique(array_merge(
        array_keys($name_translations),
        array_keys($desc_translations),
        array_keys($address_translations),
        array_keys($cancellation_policy_translations),
        array_keys($terms_conditions_translations)
    ));
    
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];
        if (!empty(trim($name_translations[$lang_code] ?? ''))) $lang_data['name'] = trim($name_translations[$lang_code]);
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) $lang_data['desc'] = trim($desc_translations[$lang_code]);
        if (!empty(trim($address_translations[$lang_code] ?? ''))) $lang_data['address'] = trim($address_translations[$lang_code]);
        if (!empty(trim($cancellation_policy_translations[$lang_code] ?? ''))) $lang_data['cancellation_policy'] = trim($cancellation_policy_translations[$lang_code]);
        if (!empty(trim($terms_conditions_translations[$lang_code] ?? ''))) $lang_data['terms_conditions'] = trim($terms_conditions_translations[$lang_code]);
        if (!empty($lang_data)) $translations[$lang_code] = $lang_data;
    }
    
    $tour_data = [
        'name' => $name,
        'description' => !empty($description) ? $description : null,
        'slug' => $slug,
        'location' => $location,
        'latitude' => !empty($latitude) ? $latitude : null,
        'longitude' => !empty($longitude) ? $longitude : null,
        'address' => $address,
        'currency' => $currency,
        'adult_price' => $adult_price,
        'child_price' => $child_price,
        'infant_price' => $infant_price,
        'discount_percentage' => $discount_percentage,
        'max_adults' => $max_adults,
        'max_children' => $max_children,
        'max_infants' => $max_infants,
        'days' => $days,
        'nights' => $nights,
        'tour_type_id' => $tour_type_id > 0 ? $tour_type_id : null,
        'stars' => $stars > 0 ? $stars : null,
        'email' => !empty($email) ? $email : null,
        'phone' => !empty($phone) ? $phone : null,
        'website' => !empty($website) ? $website : null,
        'refundable' => $refundable,
        'featured' => $featured,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_description' => !empty($meta_description) ? $meta_description : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'inclusions' => $inclusions,
        'exclusions' => $exclusions,
        'amenities' => $amenities,
        'itinerary' => $itinerary,
        'cancellation_policy' => !empty($cancellation_policy) ? $cancellation_policy : null,
        'terms_conditions' => !empty($terms_conditions) ? $terms_conditions : null,
        'img' => !empty($images) ? json_encode($images) : null,
        'user_id' => !empty($_POST['user_id']) ? $_POST['user_id'] : null,
        'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'translations' => !empty($translations) ? json_encode($translations) : null
    ];
    
    try {
        $result = $db->insert('tours', $tour_data);
        if ($result) {
            $new_tour_id = $db->id();
            $_SESSION['message'] = ['type' => 'success', 'text' => T::tour_added_successfully ?? 'Tour added successfully'];
            redirect(root . admin . '/tours/edit/' . $new_tour_id);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_add_tour ?? 'Failed to add tour'];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/add');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/');
    }
});

// ================================ GET /tours/edit/{id} - EDIT TOUR FORM
$router->get(admin.'/tours/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'edit';
    $tour_id = intval($id);
    
    $tour = $db->get('tours', '*', ['id' => $tour_id]);
    if (!$tour) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::tour_not_found ?? 'Tour not found'];
        redirect(root . admin . '/tours');
        return;
    }

    $owner = null;
    if (!empty($tour['user_id'])) {
        $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $tour['user_id']]);
    }
    
    $tour_types = $db->select('tours_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'tour_type', 'status' => 1]);
    $inclusions = $db->select('tours_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'inclusion', 'status' => 1]);
    $exclusions = $db->select('tours_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'exclusion', 'status' => 1]);
    $amenities = $db->select('tours_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];
    
    $selected_inclusions = !empty($tour['inclusions']) ? json_decode($tour['inclusions'], true) : [];
    $selected_exclusions = !empty($tour['exclusions']) ? json_decode($tour['exclusions'], true) : [];
    $selected_amenities = !empty($tour['amenities']) ? json_decode($tour['amenities'], true) : [];
    if (!is_array($selected_inclusions)) $selected_inclusions = [];
    if (!is_array($selected_exclusions)) $selected_exclusions = [];
    if (!is_array($selected_amenities)) $selected_amenities = [];
    
    $latitude = $tour['latitude'] ?? '';
    $longitude = $tour['longitude'] ?? '';
    
    $tour_images = [];
    if (!empty($tour['img'])) {
        $tour_images = json_decode($tour['img'], true);
        if (!is_array($tour_images)) $tour_images = [];
    }
    
    $name_translations = [];
    $desc_translations = [];
    $address_translations = [];
    $cancellation_policy_translations = [];
    $terms_conditions_translations = [];
    
    if (!empty($tour['translations'])) {
        $all_translations = json_decode($tour['translations'], true);
        if (is_array($all_translations)) {
            foreach ($all_translations as $lang_code => $translations_data) {
                if (!empty($translations_data['name'])) $name_translations[$lang_code] = $translations_data['name'];
                if (!empty($translations_data['desc'])) $desc_translations[$lang_code] = $translations_data['desc'];
                if (!empty($translations_data['address'])) $address_translations[$lang_code] = $translations_data['address'];
                if (!empty($translations_data['cancellation_policy'])) $cancellation_policy_translations[$lang_code] = $translations_data['cancellation_policy'];
                if (!empty($translations_data['terms_conditions'])) $terms_conditions_translations[$lang_code] = $translations_data['terms_conditions'];
            }
        }
    }
    
    $itinerary_data = [];
    if (!empty($tour['itinerary'])) {
        $itinerary_data = json_decode($tour['itinerary'], true);
        if (!is_array($itinerary_data)) $itinerary_data = [];
    }
    
    $title = T::edit_tour ?? 'Edit Tour';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/tours/manage-tours.php";
    require_once views."includes/footer.php";
});

// ================================ POST /tours/edit/{id} - UPDATE TOUR
$router->post(admin.'/tours/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $tour_id = intval($id);
    
    $tour = $db->get('tours', '*', ['id' => $tour_id]);
    if (!$tour) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::tour_not_found ?? 'Tour not found'];
        redirect(root . admin . '/tours');
        return;
    }
    
    $name = trim($_POST['tour_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currency = trim($_POST['currency'] ?? 'USD');
    $adult_price = floatval($_POST['adult_price'] ?? 0);
    $child_price = floatval($_POST['child_price'] ?? 0);
    $infant_price = floatval($_POST['infant_price'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $max_adults = intval($_POST['max_adults'] ?? 1);
    $max_children = intval($_POST['max_children'] ?? 0);
    $max_infants = intval($_POST['max_infants'] ?? 0);
    $days = intval($_POST['days'] ?? 1);
    $nights = intval($_POST['nights'] ?? 0);
    $tour_type_id = intval($_POST['tour_type_id'] ?? 0);
    $stars = intval($_POST['stars'] ?? 0);
    $refundable = isset($_POST['refundable']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $inclusions = trim($_POST['inclusions'] ?? '[]');
    $exclusions = trim($_POST['exclusions'] ?? '[]');
    $amenities = trim($_POST['amenities'] ?? '[]');
    $itinerary = trim($_POST['itinerary'] ?? '[]');
    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    
    $errors = [];
    if (empty($name)) $errors[] = T::tour_name_required ?? 'Tour name is required';
    if (empty($location)) $errors[] = T::location_required ?? 'Location is required';
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/edit/' . $tour_id);
        return;
    }
    
    // Define project root early for all file operations
    $project_root = realpath(__DIR__ . '/../../../');
    
    // Handle itinerary activity images
    $itinerary_data = json_decode($itinerary, true);
    if (is_array($itinerary_data)) {
        $upload_dir = $project_root . '/uploads/tours/itinerary/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        foreach ($itinerary_data as &$day) {
            if (!empty($day['activities']) && is_array($day['activities'])) {
                foreach ($day['activities'] as $act_idx => &$activity) {
                    // Ensure images array exists
                    if (!isset($activity['images'])) {
                        $activity['images'] = [];
                    }
                    
                    // Keep only existing images (filter out 'isNew' flagged images)
                    $existing_images = array_filter($activity['images'], function($img) {
                        return !isset($img['isNew']) || !$img['isNew'];
                    });
                    $activity['images'] = array_values($existing_images);
                    
                    // Process each uploaded image for this activity
                    $img_idx = 0;
                    while (true) {
                        $file_key = 'activity_image_' . $day['day'] . '_' . $act_idx . '_' . $img_idx;
                        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
                            break;
                        }
                        
                        $ext = pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION);
                        $new_filename = 'activity_' . time() . '_' . uniqid() . '_' . $img_idx . '.' . $ext;
                        $upload_path = $upload_dir . $new_filename;
                        $image_url = '/uploads/tours/itinerary/' . $new_filename;
                        
                        if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_path)) {
                            $activity['images'][] = ['url' => $image_url];
                        }
                        
                        $img_idx++;
                    }
                }
            }
        }
        $itinerary = json_encode($itinerary_data);
    }
    
    // ===== IMPROVED IMAGE DELETION LOGIC =====
    
    // Get existing images from database
    $existing_images = [];
    if (!empty($tour['img'])) {
        $existing_images = json_decode($tour['img'], true);
        if (!is_array($existing_images)) $existing_images = [];
    }
    
    // Get images to delete from POST
    $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true);
    if (!is_array($images_to_delete)) $images_to_delete = [];
    
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
                    // Successfully deleted - log for debugging if needed
                    error_log("Tour image deleted: " . $file_path);
                } else {
                    // Failed to delete - log error
                    error_log("Failed to delete tour image: " . $file_path);
                }
            } else {
                // File doesn't exist - log warning
                error_log("Tour image file not found: " . $file_path);
            }
        }
    }
    
    // Get reordered images or use existing
    $reordered_images = json_decode($_POST['reordered_images'] ?? '[]', true);
    $images = !empty($reordered_images) ? $reordered_images : $existing_images;
    if (!is_array($images)) $images = [];
    
    // Remove deleted images from the array
    if (!empty($images_to_delete)) {
        $images = array_values(array_filter($images, function($img) use ($images_to_delete) {
            return !in_array($img['url'], $images_to_delete);
        }));
    }
    
    // Check if there's a default image
    $has_default = false;
    foreach ($images as $img) {
        if (!empty($img['default'])) {
            $has_default = true;
            break;
        }
    }
    
    // Handle new image uploads
    if (isset($_FILES['tour_images']) && !empty($_FILES['tour_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/tours/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $files = $_FILES['tour_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $new_filename = 'tour_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/tours/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    $images[] = ['url' => $image_url, 'default' => ($i === 0 && !$has_default)];
                }
            }
        }
    }
    
    // Handle default image selection
    $default_image_url = trim($_POST['default_image'] ?? '');
    if (!empty($default_image_url)) {
        foreach ($images as &$img) $img['default'] = false;
        unset($img);
        foreach ($images as &$img) {
            if ($img['url'] === $default_image_url) {
                $img['default'] = true;
                break;
            }
        }
        unset($img);
    }
    
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    
    $translations = [];
    $name_translations = $_POST['name_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    $address_translations = $_POST['address_translations'] ?? [];
    $cancellation_policy_translations = $_POST['cancellation_policy_translations'] ?? [];
    $terms_conditions_translations = $_POST['terms_conditions_translations'] ?? [];
    
    $all_lang_codes = array_unique(array_merge(
        array_keys($name_translations),
        array_keys($desc_translations),
        array_keys($address_translations),
        array_keys($cancellation_policy_translations),
        array_keys($terms_conditions_translations)
    ));
    
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];
        if (!empty(trim($name_translations[$lang_code] ?? ''))) $lang_data['name'] = trim($name_translations[$lang_code]);
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) $lang_data['desc'] = trim($desc_translations[$lang_code]);
        if (!empty(trim($address_translations[$lang_code] ?? ''))) $lang_data['address'] = trim($address_translations[$lang_code]);
        if (!empty(trim($cancellation_policy_translations[$lang_code] ?? ''))) $lang_data['cancellation_policy'] = trim($cancellation_policy_translations[$lang_code]);
        if (!empty(trim($terms_conditions_translations[$lang_code] ?? ''))) $lang_data['terms_conditions'] = trim($terms_conditions_translations[$lang_code]);
        if (!empty($lang_data)) $translations[$lang_code] = $lang_data;
    }
    
    $tour_data = [
        'name' => $name,
        'description' => !empty($description) ? $description : null,
        'slug' => $slug,
        'location' => $location,
        'latitude' => !empty($latitude) ? $latitude : null,
        'longitude' => !empty($longitude) ? $longitude : null,
        'address' => $address,
        'currency' => $currency,
        'adult_price' => $adult_price,
        'child_price' => $child_price,
        'infant_price' => $infant_price,
        'discount_percentage' => $discount_percentage,
        'max_adults' => $max_adults,
        'max_children' => $max_children,
        'max_infants' => $max_infants,
        'days' => $days,
        'nights' => $nights,
        'tour_type_id' => $tour_type_id > 0 ? $tour_type_id : null,
        'stars' => $stars > 0 ? $stars : null,
        'email' => !empty($email) ? $email : null,
        'phone' => !empty($phone) ? $phone : null,
        'website' => !empty($website) ? $website : null,
        'refundable' => $refundable,
        'featured' => $featured,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_description' => !empty($meta_description) ? $meta_description : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'inclusions' => $inclusions,
        'exclusions' => $exclusions,
        'amenities' => $amenities,
        'itinerary' => $itinerary,
        'cancellation_policy' => !empty($cancellation_policy) ? $cancellation_policy : null,
        'terms_conditions' => !empty($terms_conditions) ? $terms_conditions : null,
        'img' => !empty($images) ? json_encode($images) : null,
        'user_id' => !empty($_POST['user_id']) ? $_POST['user_id'] : null,
        'updated_at' => date('Y-m-d H:i:s'),
        'translations' => !empty($translations) ? json_encode($translations) : null
    ];
    
    try {
        $result = $db->update('tours', $tour_data, ['id' => $tour_id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::tour_updated_successfully ?? 'Tour updated successfully'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    $active_tab = trim($_POST['active_tab'] ?? 'general');
    redirect(root . admin . '/tours/edit/' . $tour_id . '#' . $active_tab);
});

// ================================ POST /tours/delete - DELETE TOUR
$router->post(admin.'/tours/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $tour_id = intval($_POST['id'] ?? 0);
    
    if ($tour_id <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::invalid_tour_id ?? 'Invalid tour ID'];
        redirect(root . admin . '/tours');
        return;
    }
    
    $tour = $db->get('tours', ['id', 'img', 'itinerary'], ['id' => $tour_id]);
    if (!$tour) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::tour_not_found ?? 'Tour not found'];
        redirect(root . admin . '/tours');
        return;
    }
    
    try {
        $project_root = realpath(__DIR__ . '/../../../');
        
        // Delete gallery images
        if (!empty($tour['img'])) {
            $images = json_decode($tour['img'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (!empty($img['url'])) {
                        $clean_url = ltrim($img['url'], '/');
                        $file_path = $project_root . '/' . $clean_url;
                        if (file_exists($file_path)) {
                            @unlink($file_path);
                        }
                    }
                }
            }
        }
        
        // Delete itinerary activity images
        if (!empty($tour['itinerary'])) {
            $itinerary = json_decode($tour['itinerary'], true);
            if (is_array($itinerary)) {
                foreach ($itinerary as $day) {
                    if (!empty($day['activities']) && is_array($day['activities'])) {
                        foreach ($day['activities'] as $activity) {
                            // Loop through images array
                            if (!empty($activity['images']) && is_array($activity['images'])) {
                                foreach ($activity['images'] as $img) {
                                    if (!empty($img['url'])) {
                                        $clean_url = ltrim($img['url'], '/');
                                        $file_path = $project_root . '/' . $clean_url;
                                        if (file_exists($file_path)) {
                                            @unlink($file_path);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        $result = $db->delete('tours', ['id' => $tour_id]);
        if ($result) {
            $_SESSION['message'] = ['type' => 'success', 'text' => T::deleted_successfully ?? 'Deleted successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_delete_tour ?? 'Failed to delete tour'];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    redirect(root . admin . '/tours');
});

// ================================ POST /tours/search-users - AJAX USER SEARCH
$router->post(admin.'/tours/search-users', function () use ($SECURE,$db) {
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

// ================================ POST /tours/search-locations - AJAX LOCATION SEARCH
$router->post(admin.'/tours/search-locations', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    
    $search = trim($_POST['search'] ?? '');
    if (empty($search)) {
        echo json_encode(['success' => false, 'locations' => []]);
        return;
    }
    
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

/*===================================================================
TOURS ROUTES END
===================================================================*/

/*===================================================================
TOURS SETTINGS ROUTES START
===================================================================*/

// ================================ GET /tours/settings - LIST SETTINGS
$router->get(admin.'/tours/settings', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::tours_settings_management ?? 'Tours Settings Management';
    $description = '';
    $header = true;
    $footer = true;

    $languages = $GLOBALS['languages'];

    require_once views."includes/header.php";
    require_once "app/views/admin/tours/tours-settings.php";
    require_once views."includes/footer.php";
});

// ================================ GET /tours/settings/edit/{id} - EDIT SETTING
$router->get(admin.'/tours/settings/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $settingId = intval($id);
    $setting = $db->get('tours_settings', '*', ['id' => $settingId]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::setting_not_found ?? 'Setting not found'
        ];

        // Preserve type parameter if available
        $type = $_GET['type'] ?? 'tour_type';
        redirect(root . admin . '/tours/settings?type=' . urlencode($type));
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

    $languages = $GLOBALS['languages'];
    $title = T::edit_tours_setting ?? 'Edit Tours Setting';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/tours/tours-settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /tours/settings/save - SAVE SETTING
$router->post(admin.'/tours/settings/save', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $setting_id = intval($_POST['id'] ?? 0);
    $isEdit = $setting_id > 0;

    // FORM DATA VALIDATION
    $setting_label = trim($_POST['setting_label'] ?? '');
    $setting_type = trim($_POST['setting_type'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $metadata = trim($_POST['metadata'] ?? '');

    // GET TRANSLATIONS (optional)
    $translations = [];
    if (isset($_POST['translations']) && is_array($_POST['translations'])) {
        foreach ($_POST['translations'] as $lang_code => $translation) {
            if (!empty(trim($translation))) {
                $translations[$lang_code] = trim($translation);
            }
        }
    }

    // VALIDATION RULES
    $errors = [];

    if (empty($setting_label)) {
        $errors[] = T::setting_label_required ?? 'Setting label is required';
    }

    if (empty($setting_type)) {
        $errors[] = T::setting_type_required ?? 'Setting type is required';
    }

    // Validate setting_type is one of the allowed enum values
    $allowed_types = ['tour_type', 'inclusion', 'exclusion', 'amenity', 'payment_method', 'tag', 'global_config'];
    if (!in_array($setting_type, $allowed_types)) {
        $errors[] = T::invalid_setting_type ?? 'Invalid setting type';
    }

    // CHECK FOR DUPLICATE LABEL IN SAME TYPE
    $duplicate_check = [
        'setting_type' => $setting_type,
        'setting_label' => $setting_label
    ];
    if ($isEdit) {
        $duplicate_check['id[!]'] = $setting_id;
    }
    $existing = $db->get('tours_settings', 'id', $duplicate_check);
    if ($existing) {
        $errors[] = T::setting_already_exists ?? 'This setting already exists';
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/settings?type=' . urlencode($setting_type));
        return;
    }

    // PREPARE DATA
    $setting_data = [
        'setting_type' => $setting_type,
        'setting_label' => $setting_label,
        'icon' => !empty($icon) ? $icon : null,
        'metadata' => !empty($metadata) ? $metadata : null
    ];

    // Only include translations if they exist
    if (!empty($translations)) {
        $setting_data['translations'] = json_encode($translations);
    } else {
        $setting_data['translations'] = null;
    }

    try {
        if ($isEdit) {
            // UPDATE SETTING
            $setting_data['updated_at'] = date('Y-m-d H:i:s');
            $result = $db->update('tours_settings', $setting_data, ['id' => $setting_id]);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::tours_setting_updated_success ?? 'Tours setting updated successfully'
            ];
        } else {
            // INSERT NEW SETTING
            $setting_data['created_at'] = date('Y-m-d H:i:s');
            $setting_data['updated_at'] = date('Y-m-d H:i:s');
            $result = $db->insert('tours_settings', $setting_data);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::tours_setting_added_success ?? 'Tours setting added successfully'
            ];
        }

        // PRESERVE THE TAB BY REDIRECTING WITH TYPE PARAMETER
        redirect(root . admin . '/tours/settings?type=' . urlencode($setting_type));

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/tours/settings?type=' . urlencode($setting_type));
    }
});

// ================================ POST /tours/settings/delete - DELETE SETTING
$router->post(admin.'/tours/settings/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $setting_id = intval($_POST['id'] ?? 0);

    if ($setting_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_setting_id ?? 'Invalid setting ID'
        ];
        redirect(root . admin . '/tours/settings');
        return;
    }

    // GET SETTING TYPE BEFORE DELETING (to preserve tab)
    $setting = $db->get('tours_settings', '*', ['id' => $setting_id]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::tours_setting_not_found ?? 'Tours setting not found'
        ];
        redirect(root . admin . '/tours/settings');
        return;
    }

    // Store the type before deletion
    $setting_type = $setting['setting_type'] ?? 'tour_type';

    try {
        // DELETE SETTING
        $result = $db->delete('tours_settings', ['id' => $setting_id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::tours_setting_deleted_success ?? 'Tours setting deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_tours_setting ?? 'Failed to delete tours setting'
            ];
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    // PRESERVE THE TAB BY REDIRECTING WITH TYPE PARAMETER
    redirect(root . admin . '/tours/settings?type=' . urlencode($setting_type));
});

/*===================================================================
TOURS SETTINGS ROUTES END
===================================================================*/