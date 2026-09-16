<?php
// app/routes/admin/umrahRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
UMRAH ROUTES START
===================================================================*/

// ================================ GET /umrah - LIST ALL UMRAH PACKAGES
$router->get(admin.'/umrah', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::umrah_management ?? 'Umrah Management';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/umrah/umrah.php";
    require_once views."includes/footer.php";
});

// ================================ GET /umrah/add - ADD NEW UMRAH FORM
$router->get(admin.'/umrah/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'add';

    $umrah_types = $db->select('umrah_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'umrah_type', 'status' => 1]);
    $services   = $db->select('umrah_settings', ['id', 'setting_label', 'setting_type', 'icon', 'translations'], ['setting_type' => ['service', 'hotel', 'flight', 'car'], 'status' => 1]);
    $amenities  = $db->select('umrah_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];
    $selected_services = [];
    $selected_amenities  = [];

    $title = T::add_umrah ?? 'Add Umrah';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/umrah/manage-umrah.php";
    require_once views."includes/footer.php";
});

// ================================ POST /umrah/add - ADD NEW UMRAH PACKAGE
$router->post(admin.'/umrah/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::verifyRequest(); // form posts a CSRF token (manage-umrah.php)

    $name = trim($_POST['umrah_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
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
    $umrah_type_id = intval($_POST['umrah_type_id'] ?? 0);
    $featured = isset($_POST['featured']) ? 1 : 0;
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $amenities = is_array($_POST['amenities'] ?? null) ? json_encode($_POST['amenities']) : trim($_POST['amenities'] ?? '[]');
    $itinerary = trim($_POST['itinerary'] ?? '[]');
    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $related_umrah = trim($_POST['related_umrah'] ?? '[]');
    $services = is_array($_POST['services'] ?? null) ? json_encode($_POST['services']) : trim($_POST['services'] ?? '[]');
    $destination = trim($_POST['destination'] ?? ($_POST['origin'] ?? ''));
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $stars = intval($_POST['stars'] ?? 0);
    $refundable = isset($_POST['refundable']) ? 1 : 0;
    $suggested_umrah = trim($_POST['suggested_umrah'] ?? '[]');

    $errors = [];
    if (empty($name)) $errors[] = T::umrah_name_required ?? 'Umrah package name is required';

    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/add');
        return;
    }

    // Define project root once for all file operations
    $project_root = realpath(__DIR__ . '/../../../');

    // Handle itinerary activity images
    $itinerary_data = json_decode($itinerary, true);
    if (is_array($itinerary_data)) {
        $upload_dir = $project_root . '/uploads/umrah/itinerary/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        foreach ($itinerary_data as &$day) {
            if (!empty($day['activities']) && is_array($day['activities'])) {
                foreach ($day['activities'] as $act_idx => &$activity) {
                    if (!isset($activity['images'])) {
                        $activity['images'] = [];
                    }
                    $img_idx = 0;
                    while (true) {
                        $file_key = 'activity_image_' . $day['day'] . '_' . $act_idx . '_' . $img_idx;
                        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
                            break;
                        }
                        $chk = secureUploadCheck($_FILES[$file_key], ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
                        if (!$chk['ok']) { $img_idx++; continue; }
                        $new_filename = 'activity_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $img_idx . '.' . $chk['ext'];
                        $upload_path = $upload_dir . $new_filename;
                        $image_url = '/uploads/umrah/itinerary/' . $new_filename;
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
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

    // Handle translations
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

    // Handle main gallery images
    $images = [];
    if (isset($_FILES['umrah_images']) && !empty($_FILES['umrah_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/umrah/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $files = $_FILES['umrah_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'umrah_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/umrah/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    $images[] = ['url' => $image_url, 'default' => $i === 0];
                }
            }
        }
    }

    // Clear service data if corresponding service type is not selected
    $selected_ids = json_decode($services, true);
    if (!is_array($selected_ids)) $selected_ids = [];
    $all_services_settings = $db->select('umrah_settings', ['id', 'setting_type'], ['setting_type' => ['hotel', 'flight', 'car']]);
    $type_map = [];
    foreach ($all_services_settings as $s) { $type_map[$s['id']] = $s['setting_type']; }
    $selected_types = [];
    foreach ($selected_ids as $sid) { if (isset($type_map[$sid])) { $selected_types[] = $type_map[$sid]; } }

    $final_flights_data = $_POST['flights_data'] ?? '[]';
    $final_stays_data = $_POST['stays_data'] ?? '[]';
    $final_transfers_data = $_POST['transfers_data'] ?? '[]';

    // Cleanup flights data
    $flights_objs = json_decode($final_flights_data, true);
    if (is_array($flights_objs)) {
        foreach ($flights_objs as &$flight) {
            $cleanup_seg = function(&$seg) {
                unset($seg['showFromDropdown']);
                unset($seg['showToDropdown']);
                unset($seg['showAirlineDropdown']);
                unset($seg['searchingFrom']);
                unset($seg['searchingTo']);
                unset($seg['searchingAirlines']);
                unset($seg['fromResults']);
                unset($seg['toResults']);
                unset($seg['airlineResults']);
                unset($seg['fromSearch']);
                unset($seg['toSearch']);
                unset($seg['airlineSearch']);
            };

            if (isset($flight['segments']) && is_array($flight['segments'])) {
                foreach ($flight['segments'] as &$segment) {
                    $cleanup_seg($segment);
                }
            }
            if (isset($flight['returnSegments']) && is_array($flight['returnSegments'])) {
                foreach ($flight['returnSegments'] as &$segment) {
                    $cleanup_seg($segment);
                }
            }
        }
        $final_flights_data = json_encode($flights_objs);
    }

    // Cleanup and Process transfers data (including images)
    $transfers_objs = json_decode($final_transfers_data, true);
    if (is_array($transfers_objs)) {
        foreach ($transfers_objs as $t_idx => &$trans) {
            // 1. Handle New Uploads
            $trans_file_key = "transfer_images_$t_idx";
            if (isset($_FILES[$trans_file_key])) {
                $files = $_FILES[$trans_file_key];
                $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];
                
                for ($i = 0; $i < count($name_array); $i++) {
                    if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                        // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                        $new_filename = 'transfer_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                        $upload_dir = $project_root . '/uploads/umrah/transfer/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                            if (!isset($trans['images'])) $trans['images'] = [];
                            $trans['images'][] = ['url' => '/uploads/umrah/transfer/' . $new_filename];
                        }
                    }
                }
            }

            // 2. Handle Deletions
            if (isset($trans['imagesToDelete']) && is_array($trans['imagesToDelete']) && !empty($trans['imagesToDelete'])) {
                foreach ($trans['imagesToDelete'] as $url) {
                    $clean_url = ltrim($url, '/');
                    $file_path = $project_root . '/' . $clean_url;
                    if (file_exists($file_path)) @unlink($file_path);
                }
                if (isset($trans['images'])) {
                    $trans['images'] = array_values(array_filter($trans['images'], function($img) use ($trans) {
                        return !in_array($img['url'], $trans['imagesToDelete']);
                    }));
                }
            }

            // 3. Handle Defaults
            if (isset($trans['images']) && is_array($trans['images'])) {
                foreach ($trans['images'] as &$img) {
                    $img['default'] = (isset($trans['defaultImage']) && $img['url'] === $trans['defaultImage']);
                }
            }

            // 4. Cleanup UI-only properties
            unset($trans['show_from_dropdown']);
            unset($trans['show_to_dropdown']);
            unset($trans['searching_from']);
            unset($trans['searching_to']);
            unset($trans['from_results']);
            unset($trans['to_results']);
            unset($trans['activeTab']);
            unset($trans['previewImages']);
            unset($trans['imagesToDelete']);
            unset($trans['defaultImage']);
        }
        $final_transfers_data = json_encode($transfers_objs);
    }

    // Process Stay and Room images
    $stays_objs = json_decode($final_stays_data, true);
    if (is_array($stays_objs)) {
        foreach ($stays_objs as $s_idx => &$stay) {
            // 1. Stay Main Images
            $stay_file_key = "stay_images_$s_idx";
            if (isset($_FILES[$stay_file_key])) {
                $files = $_FILES[$stay_file_key];
                $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];
                
                for ($i = 0; $i < count($name_array); $i++) {
                    if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                        // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                        $new_filename = 'stay_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                        $upload_dir = $project_root . '/uploads/umrah/stays/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                            if (!isset($stay['images'])) $stay['images'] = [];
                            $stay['images'][] = ['url' => '/uploads/umrah/stays/' . $new_filename];
                        }
                    }
                }
            }

            // 2. Handle Room Deletions (Stay Level for flattened UI)
            if (isset($stay['roomImagesToDelete']) && is_array($stay['roomImagesToDelete']) && !empty($stay['roomImagesToDelete'])) {
                if (isset($stay['roomImages'])) {
                    $stay['roomImages'] = array_values(array_filter($stay['roomImages'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['roomImagesToDelete']);
                    }));
                }
                // Also check rooms[0] if exists
                if (isset($stay['rooms'][0]['images'])) {
                    $stay['rooms'][0]['images'] = array_values(array_filter($stay['rooms'][0]['images'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['roomImagesToDelete']);
                    }));
                }
            }

            // 3. Handle Stay Main Images Deletions
            if (isset($stay['imagesToDelete']) && is_array($stay['imagesToDelete']) && !empty($stay['imagesToDelete'])) {
                if (isset($stay['images'])) {
                    $stay['images'] = array_values(array_filter($stay['images'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['imagesToDelete']);
                    }));
                }
            }

            // 4. Room Images for flattened UI (Room index 0) or Nested Rooms
            if (isset($stay['room_type']) && !isset($stay['rooms'][0])) {
                if (!isset($stay['rooms'])) $stay['rooms'] = [];
                $stay['rooms'][] = ['type' => $stay['room_type'], 'occupancy' => $stay['room_occupancy'] ?? '', 'images' => $stay['roomImages'] ?? [], 'imagesToDelete' => $stay['roomImagesToDelete'] ?? []];
            }

            if (isset($stay['rooms']) && is_array($stay['rooms'])) {
                foreach ($stay['rooms'] as $r_idx => &$room) {
                    $room_file_key = "room_images_{$s_idx}_{$r_idx}";
                    if (isset($_FILES[$room_file_key])) {
                        $files = $_FILES[$room_file_key];
                        $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                        $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                        $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];

                        for ($i = 0; $i < count($name_array); $i++) {
                            if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                                // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                                $new_filename = 'room_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                                $upload_dir = $project_root . '/uploads/umrah/rooms/';
                                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                                if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                                    if (!isset($room['images'])) $room['images'] = [];
                                    $room['images'][] = ['url' => '/uploads/umrah/rooms/' . $new_filename];
                                }
                            }
                        }
                    }
                    
                    // Nested Room Deletions
                    if (isset($room['imagesToDelete']) && is_array($room['imagesToDelete']) && !empty($room['imagesToDelete'])) {
                        if (isset($room['images'])) {
                            $room['images'] = array_values(array_filter($room['images'], function($img) use ($room) {
                                return !in_array($img['url'], $room['imagesToDelete']);
                            }));
                        }
                    }

                    // Room Defaults
                    if (isset($room['images']) && is_array($room['images'])) {
                        foreach ($room['images'] as &$img) {
                            $img['default'] = (isset($room['defaultImage']) && $img['url'] === $room['defaultImage']);
                        }
                    }

                    unset($room['previewImages']);
                    unset($room['imagesToDelete']);
                    unset($room['defaultImage']);
                }
            }

            // Stay Defaults
            if (isset($stay['images']) && is_array($stay['images'])) {
                foreach ($stay['images'] as &$img) {
                    $img['default'] = (isset($stay['defaultImage']) && $img['url'] === $stay['defaultImage']);
                }
            }

            // Cleanup Stay temporary properties
            unset($stay['imagesToDelete']);
            unset($stay['roomImagesToDelete']);
            unset($stay['previewImages']);
            unset($stay['roomPreviewImages']);
            unset($stay['defaultImage']);
            unset($stay['roomDefaultImage']);
            unset($stay['locationResults']);
            unset($stay['searchingLocations']);
            unset($stay['showLocationDropdown']);
            unset($stay['locationSearch']);
        }
        $final_stays_data = json_encode($stays_objs);
    }

    if (!in_array('flight', $selected_types)) $final_flights_data = '[]';
    if (!in_array('hotel', $selected_types)) $final_stays_data = '[]';
    if (!in_array('car', $selected_types)) $final_transfers_data = '[]';

    $umrah_data = [
        'name'                  => $name,
        'description'           => !empty($description) ? $description : null,
        'slug'                  => $slug,
        'location'              => $destination,
        'latitude'              => !empty($latitude) ? $latitude : null,
        'longitude'             => !empty($longitude) ? $longitude : null,
        'address'               => $address,
        'currency'              => $currency,
        'adult_price'           => $adult_price,
        'child_price'           => $child_price,
        'infant_price'          => $infant_price,
        'discount_percentage'   => $discount_percentage,
        'max_adults'            => $max_adults,
        'max_children'          => $max_children,
        'max_infants'          => $max_infants,
        'days'                 => $days,
        'nights'               => $nights,
        'umrah_type_id'        => $umrah_type_id > 0 ? $umrah_type_id : null,
        'stars'                => $stars > 0 ? $stars : null,
        'email'                => !empty($email) ? $email : null,
        'phone'                => !empty($phone) ? $phone : null,
        'website'              => !empty($website) ? $website : null,
        'refundable'           => $refundable,
        'featured'             => $featured,
        'meta_title'           => !empty($meta_title) ? $meta_title : null,
        'meta_description'     => !empty($meta_description) ? $meta_description : null,
        'meta_keywords'        => !empty($meta_keywords) ? $meta_keywords : null,
        'amenities'            => $amenities,
        'itinerary'            => $itinerary,
        'cancellation_policy'  => !empty($cancellation_policy) ? $cancellation_policy : null,
        'terms_conditions'     => !empty($terms_conditions) ? $terms_conditions : null,
        'supplier_id'          => $supplier_id > 0 ? $supplier_id : null,
        'related_umrah'        => $related_umrah,
        'suggested_umrah'      => $suggested_umrah,
        'services'             => $services,
        'flights_data'         => $final_flights_data,
        'stays_data'           => $final_stays_data,
        'travelings_data'      => $final_transfers_data, // Database column remains 'travelings_data'
        'user_id'              => !empty($_POST['user_id']) ? $_POST['user_id'] : null,
        'img'                  => json_encode($images),
        'status'               => 1,
        'created_at'           => date('Y-m-d H:i:s'),
        'translations'         => !empty($translations) ? json_encode($translations) : null
    ];

    // Manual ID Generation Workaround (Missing AUTO_INCREMENT)
    $max_id = $db->max('umrah', 'id');
    $umrah_data['id'] = ((int)($max_id ?? 0)) + 1;

    try {
        $result = $db->insert('umrah', $umrah_data);
        if ($result) {
            $new_id = $db->id() ?: $umrah_data['id'];
            $_SESSION['message'] = ['type' => 'success', 'text' => T::umrah_added_successfully ?? 'Umrah package added successfully'];
            redirect(root . admin . '/umrah/edit/' . $new_id);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_add_umrah ?? 'Failed to add Umrah package'];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/add');
        }
    } catch (Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/');
    }
});

// ================================ GET /umrah/edit/{id} - EDIT UMRAH FORM
$router->get(admin.'/umrah/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'edit';
    $umrah_id = intval($id);

    $umrah = $db->get('umrah', '*', ['id' => $umrah_id]);
    if (!$umrah) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::umrah_not_found ?? 'Umrah package not found'];
        redirect(root . admin . '/umrah');
        return;
    }

    $owner = null;
    if (!empty($umrah['user_id'])) {
        $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $umrah['user_id']]);
    }

    $umrah_types = $db->select('umrah_settings', ['id', 'setting_label', 'translations'], ['setting_type' => 'umrah_type', 'status' => 1]);
    $services    = $db->select('umrah_settings', ['id', 'setting_label', 'setting_type', 'icon', 'translations'], ['setting_type' => ['service', 'hotel', 'flight', 'car'], 'status' => 1]);
    $amenities   = $db->select('umrah_settings', ['id', 'setting_label', 'icon', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
    $currencies  = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    $selected_services = !empty($umrah['services']) ? json_decode($umrah['services'], true) : [];
    if (!is_array($selected_services)) $selected_services = [];

    $selected_amenities  = !empty($umrah['amenities']) ? json_decode($umrah['amenities'], true) : [];
    if (!is_array($selected_amenities))  $selected_amenities = [];


    $umrah_images = [];
    if (!empty($umrah['img'])) {
        $umrah_images = json_decode($umrah['img'], true);
        if (!is_array($umrah_images)) $umrah_images = [];
    }

    $name_translations = [];
    $desc_translations = [];
    $cancellation_policy_translations = [];
    $terms_conditions_translations = [];

    if (!empty($umrah['translations'])) {
        $all_translations = json_decode($umrah['translations'], true);
        if (is_array($all_translations)) {
            foreach ($all_translations as $lang_code => $translations_data) {
                if (!empty($translations_data['name']))                $name_translations[$lang_code]                = $translations_data['name'];
                if (!empty($translations_data['desc']))                $desc_translations[$lang_code]                = $translations_data['desc'];
                if (!empty($translations_data['cancellation_policy'])) $cancellation_policy_translations[$lang_code] = $translations_data['cancellation_policy'];
                if (!empty($translations_data['terms_conditions']))    $terms_conditions_translations[$lang_code]    = $translations_data['terms_conditions'];
            }
        }
    }

    $itinerary_data = [];
    if (!empty($umrah['itinerary'])) {
        $itinerary_data = json_decode($umrah['itinerary'], true);
        if (!is_array($itinerary_data)) $itinerary_data = [];
    }

    $title = (defined('T::edit_umrah') ? T::edit_umrah : 'Edit Umrah Package');
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/umrah/manage-umrah.php";
    require_once views."includes/footer.php";
});

// ================================ POST /umrah/edit/{id} - UPDATE UMRAH PACKAGE
$router->post(admin.'/umrah/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::verifyRequest(); // form posts a CSRF token (manage-umrah.php)
    $umrah_id = intval($id);

    $umrah = $db->get('umrah', '*', ['id' => $umrah_id]);
    if (!$umrah) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::umrah_not_found ?? 'Umrah package not found'];
        redirect(root . admin . '/umrah');
        return;
    }

    $name               = trim($_POST['umrah_name'] ?? '');
    $description        = trim($_POST['description'] ?? '');
    $currency           = trim($_POST['currency'] ?? 'USD');
    $adult_price        = floatval($_POST['adult_price'] ?? 0);
    $child_price        = floatval($_POST['child_price'] ?? 0);
    $infant_price       = floatval($_POST['infant_price'] ?? 0);
    $discount_percentage= floatval($_POST['discount_percentage'] ?? 0);
    $max_adults         = intval($_POST['max_adults'] ?? 1);
    $max_children       = intval($_POST['max_children'] ?? 0);
    $max_infants        = intval($_POST['max_infants'] ?? 0);
    $days               = intval($_POST['days'] ?? 1);
    $nights             = intval($_POST['nights'] ?? 0);
    $umrah_type_id      = intval($_POST['umrah_type_id'] ?? 0);
    $featured           = isset($_POST['featured']) ? 1 : 0;
    $email              = trim($_POST['email'] ?? '');
    $phone              = trim($_POST['phone'] ?? '');
    $website            = trim($_POST['website'] ?? '');
    $meta_title         = trim($_POST['meta_title'] ?? '');
    $meta_description   = trim($_POST['meta_description'] ?? '');
    $meta_keywords      = trim($_POST['meta_keywords'] ?? '');
    $amenities          = is_array($_POST['amenities'] ?? null) ? json_encode($_POST['amenities']) : trim($_POST['amenities'] ?? '[]');
    $itinerary          = trim($_POST['itinerary'] ?? '[]');
    $cancellation_policy = trim($_POST['cancellation_policy'] ?? '');
    $terms_conditions   = trim($_POST['terms_conditions'] ?? '');
    $supplier_id        = intval($_POST['supplier_id'] ?? 0);
    $related_umrah     = trim($_POST['related_umrah'] ?? '[]');
    $suggested_umrah   = trim($_POST['suggested_umrah'] ?? '[]');
    $services_post     = is_array($_POST['services'] ?? null) ? json_encode($_POST['services']) : trim($_POST['services'] ?? '[]');
    $destination       = trim($_POST['destination'] ?? ($_POST['origin'] ?? ''));
    $latitude          = trim($_POST['latitude'] ?? '');
    $longitude         = trim($_POST['longitude'] ?? '');
    $address           = trim($_POST['address'] ?? '');
    $stars             = intval($_POST['stars'] ?? 0);
    $refundable        = isset($_POST['refundable']) ? 1 : 0;

    $errors = [];
    if (empty($name))     $errors[] = T::umrah_name_required ?? 'Umrah package name is required';

    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/edit/' . $umrah_id);
        return;
    }

    $project_root = realpath(__DIR__ . '/../../../');

    // Handle itinerary activity images
    $itinerary_data = json_decode($itinerary, true);
    if (is_array($itinerary_data)) {
        $upload_dir = $project_root . '/uploads/umrah/itinerary/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        foreach ($itinerary_data as &$day) {
            if (!empty($day['activities']) && is_array($day['activities'])) {
                foreach ($day['activities'] as $act_idx => &$activity) {
                    if (!isset($activity['images'])) {
                        $activity['images'] = [];
                    }
                    $existing_images = array_filter($activity['images'], function($img) {
                        return !isset($img['isNew']) || !$img['isNew'];
                    });
                    $activity['images'] = array_values($existing_images);

                    $img_idx = 0;
                    while (true) {
                        $file_key = 'activity_image_' . $day['day'] . '_' . $act_idx . '_' . $img_idx;
                        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
                            break;
                        }
                        $chk = secureUploadCheck($_FILES[$file_key], ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
                        if (!$chk['ok']) { $img_idx++; continue; }
                        $new_filename = 'activity_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $img_idx . '.' . $chk['ext'];
                        $upload_path = $upload_dir . $new_filename;
                        $image_url = '/uploads/umrah/itinerary/' . $new_filename;
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

    // Image deletion logic
    $existing_images = [];
    if (!empty($umrah['img'])) {
        $existing_images = json_decode($umrah['img'], true);
        if (!is_array($existing_images)) $existing_images = [];
    }

    $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true);
    if (!is_array($images_to_delete)) $images_to_delete = [];

    if (!empty($images_to_delete)) {
        foreach ($images_to_delete as $image_url) {
            $clean_url = ltrim($image_url, '/');
            $file_path = $project_root . '/' . $clean_url;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
    }

    $reordered_images = json_decode($_POST['reordered_images'] ?? '[]', true);
    $images = !empty($reordered_images) ? $reordered_images : $existing_images;
    if (!is_array($images)) $images = [];

    if (!empty($images_to_delete)) {
        $images = array_values(array_filter($images, function($img) use ($images_to_delete) {
            return !in_array($img['url'], $images_to_delete);
        }));
    }

    $has_default = false;
    foreach ($images as $img) {
        if (!empty($img['default'])) { $has_default = true; break; }
    }

    // New image uploads
    if (isset($_FILES['umrah_images']) && !empty($_FILES['umrah_images']['name'][0])) {
        $upload_dir = $project_root . '/uploads/umrah/gallery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $files = $_FILES['umrah_images'];
        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // SECURITY: validate real MIME + derive a SAFE extension (never the
                // user filename) before writing to the web-served
                // /uploads/umrah/gallery/ dir.
                $chk = secureUploadCheck(
                    ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => UPLOAD_ERR_OK],
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024
                );
                if (!$chk['ok']) { continue; }
                $new_filename = 'umrah_' . time() . '_' . bin2hex(random_bytes(6)) . '_' . $i . '.' . $chk['ext'];
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/umrah/gallery/' . $new_filename;
                if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                    @chmod($upload_path, 0644);
                    $images[] = ['url' => $image_url, 'default' => ($i === 0 && !$has_default)];
                }
            }
        }
    }

    // Default image selection
    $default_image_url = trim($_POST['default_image'] ?? '');
    if (!empty($default_image_url)) {
        foreach ($images as &$img) $img['default'] = false;
        unset($img);
        foreach ($images as &$img) {
            if ($img['url'] === $default_image_url) { $img['default'] = true; break; }
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

    // Clear service data if corresponding service type is not selected
    $selected_ids_edit = json_decode($services_post, true);
    if (!is_array($selected_ids_edit)) $selected_ids_edit = [];
    $all_services_settings_edit = $db->select('umrah_settings', ['id', 'setting_type'], ['setting_type' => ['hotel', 'flight', 'car']]);
    $type_map_edit = [];
    foreach ($all_services_settings_edit as $s) { $type_map_edit[$s['id']] = $s['setting_type']; }
    $selected_types_edit = [];
    foreach ($selected_ids_edit as $sid) { if (isset($type_map_edit[$sid])) { $selected_types_edit[] = $type_map_edit[$sid]; } }

    $final_flights_data_edit = $_POST['flights_data'] ?? '[]';
    $final_stays_data_edit = $_POST['stays_data'] ?? '[]';
    $final_transfers_data_edit = $_POST['transfers_data'] ?? '[]';

    // Process transfer images for edit
    $transfers_objs_edit = json_decode($final_transfers_data_edit, true);
    if (is_array($transfers_objs_edit)) {
        foreach ($transfers_objs_edit as $t_idx => &$trans) {
            // 1. Handle New Uploads
            $trans_file_key = "transfer_images_$t_idx";
            if (isset($_FILES[$trans_file_key])) {
                $files = $_FILES[$trans_file_key];
                $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];
                
                for ($i = 0; $i < count($name_array); $i++) {
                    if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                        // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                        $new_filename = 'transfer_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                        $upload_dir = $project_root . '/uploads/umrah/transfer/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                            if (!isset($trans['images'])) $trans['images'] = [];
                            $trans['images'][] = ['url' => '/uploads/umrah/transfer/' . $new_filename];
                        }
                    }
                }
            }

            // 2. Handle Deletions
            if (isset($trans['imagesToDelete']) && is_array($trans['imagesToDelete']) && !empty($trans['imagesToDelete'])) {
                foreach ($trans['imagesToDelete'] as $url) {
                    $clean_url = ltrim($url, '/');
                    $file_path = $project_root . '/' . $clean_url;
                    if (file_exists($file_path)) @unlink($file_path);
                }
                if (isset($trans['images'])) {
                    $trans['images'] = array_values(array_filter($trans['images'], function($img) use ($trans) {
                        return !in_array($img['url'], $trans['imagesToDelete']);
                    }));
                }
            }

            // 3. Handle Defaults
            if (isset($trans['images']) && is_array($trans['images'])) {
                foreach ($trans['images'] as &$img) {
                    $img['default'] = (isset($trans['defaultImage']) && $img['url'] === $trans['defaultImage']);
                }
            }

            // 4. Cleanup UI-only properties
            unset($trans['activeTab']);
            unset($trans['previewImages']);
            unset($trans['imagesToDelete']);
            unset($trans['defaultImage']);
        }
        $final_transfers_data_edit = json_encode($transfers_objs_edit);
    }

    // Process Stay and Room images
    $stays_objs_edit = json_decode($final_stays_data_edit, true);
    if (is_array($stays_objs_edit)) {
        foreach ($stays_objs_edit as $s_idx => &$stay) {
            // 1. Stay Main Images
            $stay_file_key = "stay_images_$s_idx";
            if (isset($_FILES[$stay_file_key])) {
                $files = $_FILES[$stay_file_key];
                $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];
                
                for ($i = 0; $i < count($name_array); $i++) {
                    if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                        // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                        $new_filename = 'stay_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                        $upload_dir = $project_root . '/uploads/umrah/stays/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                            if (!isset($stay['images'])) $stay['images'] = [];
                            $stay['images'][] = ['url' => '/uploads/umrah/stays/' . $new_filename];
                        }
                    }
                }
            }

            // 2. Handle Room Deletions (Stay Level for flattened UI)
            if (isset($stay['roomImagesToDelete']) && is_array($stay['roomImagesToDelete']) && !empty($stay['roomImagesToDelete'])) {
                if (isset($stay['roomImages'])) {
                    $stay['roomImages'] = array_values(array_filter($stay['roomImages'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['roomImagesToDelete']);
                    }));
                }
                // Also check rooms[0] if exists
                if (isset($stay['rooms'][0]['images'])) {
                    $stay['rooms'][0]['images'] = array_values(array_filter($stay['rooms'][0]['images'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['roomImagesToDelete']);
                    }));
                }
            }

            // 3. Handle Stay Main Images Deletions
            if (isset($stay['imagesToDelete']) && is_array($stay['imagesToDelete']) && !empty($stay['imagesToDelete'])) {
                if (isset($stay['images'])) {
                    $stay['images'] = array_values(array_filter($stay['images'], function($img) use ($stay) {
                        return !in_array($img['url'], $stay['imagesToDelete']);
                    }));
                }
            }

            // 4. Room Images for flattened UI (Room index 0) or Nested Rooms
            if (isset($stay['room_type']) && !isset($stay['rooms'][0])) {
                if (!isset($stay['rooms'])) $stay['rooms'] = [];
                $stay['rooms'][] = ['type' => $stay['room_type'], 'occupancy' => $stay['room_occupancy'] ?? '', 'images' => $stay['roomImages'] ?? [], 'imagesToDelete' => $stay['roomImagesToDelete'] ?? []];
            }

            if (isset($stay['rooms']) && is_array($stay['rooms'])) {
                foreach ($stay['rooms'] as $r_idx => &$room) {
                    $room_file_key = "room_images_{$s_idx}_{$r_idx}";
                    if (isset($_FILES[$room_file_key])) {
                        $files = $_FILES[$room_file_key];
                        $name_array = is_array($files['name']) ? $files['name'] : [$files['name']];
                        $tmp_array = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                        $error_array = is_array($files['error']) ? $files['error'] : [$files['error']];

                        for ($i = 0; $i < count($name_array); $i++) {
                            if ($error_array[$i] === UPLOAD_ERR_OK && !empty($name_array[$i])) {
                                // SECURITY: validate real MIME + derive a SAFE extension
                        // (never the user filename). Was $ext=pathinfo(name), so an
                        // admin could upload shell.php into a web-served /uploads/
                        // umrah/ dir (RCE where PHP exec isn't blocked; stored-XSS
                        // regardless). Skip anything that isn't a real image.
                        $umImgChk = function_exists('secureImageFileCheck')
                            ? secureImageFileCheck($tmp_array[$i], (int) @filesize($tmp_array[$i]))
                            : ['ok' => false];
                        if (empty($umImgChk['ok'])) { continue; }
                        $ext = $umImgChk['ext'];
                                $new_filename = 'room_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                                $upload_dir = $project_root . '/uploads/umrah/rooms/';
                                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                                if (move_uploaded_file($tmp_array[$i], $upload_dir . $new_filename)) {
                                    if (!isset($room['images'])) $room['images'] = [];
                                    $room['images'][] = ['url' => '/uploads/umrah/rooms/' . $new_filename];
                                }
                            }
                        }
                    }
                    
                    // Nested Room Deletions
                    if (isset($room['imagesToDelete']) && is_array($room['imagesToDelete']) && !empty($room['imagesToDelete'])) {
                        if (isset($room['images'])) {
                            $room['images'] = array_values(array_filter($room['images'], function($img) use ($room) {
                                return !in_array($img['url'], $room['imagesToDelete']);
                            }));
                        }
                    }

                    // Room Defaults
                    if (isset($room['images']) && is_array($room['images'])) {
                        foreach ($room['images'] as &$img) {
                            $img['default'] = (isset($room['defaultImage']) && $img['url'] === $room['defaultImage']);
                        }
                    }

                    unset($room['previewImages']);
                    unset($room['imagesToDelete']);
                    unset($room['defaultImage']);
                }
            }
            
            // Stay Defaults
            if (isset($stay['images']) && is_array($stay['images'])) {
                foreach ($stay['images'] as &$img) {
                    $img['default'] = (isset($stay['defaultImage']) && $img['url'] === $stay['defaultImage']);
                }
            }

            // Final Cleanup for Stay
            unset($stay['imagesToDelete']);
            unset($stay['roomImagesToDelete']);
            unset($stay['previewImages']);
            unset($stay['roomPreviewImages']);
            unset($stay['defaultImage']);
            unset($stay['roomDefaultImage']);
            unset($stay['locationResults']);
            unset($stay['searchingLocations']);
            unset($stay['showLocationDropdown']);
            unset($stay['locationSearch']);
        }
        $final_stays_data_edit = json_encode($stays_objs_edit);
    }

    // Cleanup flights data
    $flights_objs_edit = json_decode($final_flights_data_edit, true);
    if (is_array($flights_objs_edit)) {
        foreach ($flights_objs_edit as &$flight) {
            $cleanup_seg = function(&$seg) {
                unset($seg['showFromDropdown']);
                unset($seg['showToDropdown']);
                unset($seg['showAirlineDropdown']);
                unset($seg['searchingFrom']);
                unset($seg['searchingTo']);
                unset($seg['searchingAirlines']);
                unset($seg['fromResults']);
                unset($seg['toResults']);
                unset($seg['airlineResults']);
                unset($seg['fromSearch']);
                unset($seg['toSearch']);
                unset($seg['airlineSearch']);
            };

            if (isset($flight['segments']) && is_array($flight['segments'])) {
                foreach ($flight['segments'] as &$segment) {
                    $cleanup_seg($segment);
                }
            }
            if (isset($flight['returnSegments']) && is_array($flight['returnSegments'])) {
                foreach ($flight['returnSegments'] as &$segment) {
                    $cleanup_seg($segment);
                }
            }
        }
        $final_flights_data_edit = json_encode($flights_objs_edit);
    }

    if (!in_array('flight', $selected_types_edit)) $final_flights_data_edit = '[]';
    if (!in_array('hotel', $selected_types_edit)) $final_stays_data_edit = '[]';
    if (!in_array('car', $selected_types_edit)) $final_transfers_data_edit = '[]';

    $umrah_data = [
        'name'                  => $name,
        'description'           => !empty($description) ? $description : null,
        'slug'                  => $slug,
        'location'              => $destination,
        'latitude'              => !empty($latitude) ? $latitude : null,
        'longitude'             => !empty($longitude) ? $longitude : null,
        'address'               => $address,
        'currency'              => $currency,
        'adult_price'           => $adult_price,
        'child_price'           => $child_price,
        'infant_price'          => $infant_price,
        'discount_percentage'   => $discount_percentage,
        'max_adults'            => $max_adults,
        'max_children'          => $max_children,
        'max_infants'           => $max_infants,
        'days'                  => $days,
        'nights'                => $nights,
        'umrah_type_id'         => $umrah_type_id > 0 ? $umrah_type_id : null,
        'stars'                 => $stars > 0 ? $stars : null,
        'email'                 => !empty($email) ? $email : null,
        'phone'                 => !empty($phone) ? $phone : null,
        'website'               => !empty($website) ? $website : null,
        'refundable'            => $refundable,
        'featured'              => $featured,
        'meta_title'            => !empty($meta_title) ? $meta_title : null,
        'meta_description'      => !empty($meta_description) ? $meta_description : null,
        'meta_keywords'         => !empty($meta_keywords) ? $meta_keywords : null,
        'amenities'             => $amenities,
        'itinerary'             => $itinerary,
        'cancellation_policy'   => !empty($cancellation_policy) ? $cancellation_policy : null,
        'terms_conditions'      => !empty($terms_conditions) ? $terms_conditions : null,
        'supplier_id'           => $supplier_id > 0 ? $supplier_id : null,
        'related_umrah'         => $related_umrah,
        'suggested_umrah'       => $suggested_umrah,
        'services'              => $services_post,
        'flights_data'          => $final_flights_data_edit,
        'stays_data'            => $final_stays_data_edit,
        'travelings_data'       => $final_transfers_data_edit,
        'img'                   => !empty($images) ? json_encode($images) : null,
        'user_id'               => !empty($_POST['user_id']) ? $_POST['user_id'] : null,
        'updated_at'            => date('Y-m-d H:i:s'),
        'translations'          => !empty($translations) ? json_encode($translations) : null
    ];

    try {
        $db->update('umrah', $umrah_data, ['id' => $umrah_id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::umrah_updated_successfully ?? 'Umrah package updated successfully'];
    } catch (Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()];
    }

    $active_tab = trim($_POST['active_tab'] ?? 'general');
    redirect(root . admin . '/umrah/edit/' . $umrah_id . '#' . $active_tab);
});

// ================================ POST /umrah/delete - DELETE UMRAH PACKAGE
$router->post(admin.'/umrah/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::verifyRequest(); // state-changing delete — require CSRF token
    $umrah_id = intval($_POST['id'] ?? 0);

    if ($umrah_id <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::invalid_umrah_id ?? 'Invalid Umrah ID'];
        redirect(root . admin . '/umrah');
        return;
    }

    $umrah = $db->get('umrah', ['id', 'img', 'itinerary', 'travelings_data', 'stays_data'], ['id' => $umrah_id]);
    if (!$umrah) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::umrah_not_found ?? 'Umrah package not found'];
        redirect(root . admin . '/umrah');
        return;
    }

    try {
        $project_root = realpath(__DIR__ . '/../../../');

        if (!empty($umrah['img'])) {
            $images = json_decode($umrah['img'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (!empty($img['url'])) {
                        $clean_url = ltrim($img['url'], '/');
                        $file_path = $project_root . '/' . $clean_url;
                        if (file_exists($file_path)) @unlink($file_path);
                    }
                }
            }
        }

        if (!empty($umrah['itinerary'])) {
            $itinerary = json_decode($umrah['itinerary'], true);
            if (is_array($itinerary)) {
                foreach ($itinerary as $day) {
                    if (!empty($day['activities']) && is_array($day['activities'])) {
                        foreach ($day['activities'] as $activity) {
                            if (!empty($activity['images']) && is_array($activity['images'])) {
                                foreach ($activity['images'] as $img) {
                                    if (!empty($img['url'])) {
                                        $clean_url = ltrim($img['url'], '/');
                                        $file_path = $project_root . '/' . $clean_url;
                                        if (file_exists($file_path)) @unlink($file_path);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($umrah['travelings_data'])) {
            $travelings = json_decode($umrah['travelings_data'], true);
            if (is_array($travelings)) {
                foreach ($travelings as $trav) {
                    if (!empty($trav['images']) && is_array($trav['images'])) {
                        foreach ($trav['images'] as $img) {
                            if (!empty($img['url'])) {
                                $clean_url = ltrim($img['url'], '/');
                                $file_path = $project_root . '/' . $clean_url;
                                if (file_exists($file_path)) @unlink($file_path);
                            }
                        }
                    }
                }
            }
        }

        if (!empty($umrah['stays_data'])) {
            $stays = json_decode($umrah['stays_data'], true);
            if (is_array($stays)) {
                foreach ($stays as $stay) {
                    if (!empty($stay['images']) && is_array($stay['images'])) {
                        foreach ($stay['images'] as $img) {
                            if (!empty($img['url'])) {
                                $clean_url = ltrim($img['url'], '/');
                                $file_path = $project_root . '/' . $clean_url;
                                if (file_exists($file_path)) @unlink($file_path);
                            }
                        }
                    }
                    if (!empty($stay['rooms']) && is_array($stay['rooms'])) {
                        foreach ($stay['rooms'] as $room) {
                            if (!empty($room['images']) && is_array($room['images'])) {
                                foreach ($room['images'] as $img) {
                                    if (!empty($img['url'])) {
                                        $clean_url = ltrim($img['url'], '/');
                                        $file_path = $project_root . '/' . $clean_url;
                                        if (file_exists($file_path)) @unlink($file_path);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $result = $db->delete('umrah', ['id' => $umrah_id]);
        if ($result) {
            $_SESSION['message'] = ['type' => 'success', 'text' => T::deleted_successfully ?? 'Deleted successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_delete_umrah ?? 'Failed to delete Umrah package'];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()];
    }

    redirect(root . admin . '/umrah');
});

// ================================ POST /umrah/search-users - AJAX USER SEARCH
$router->post(admin.'/umrah/search-users', function () use ($SECURE,$db) {
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
                    'email[~]'      => $search,
                    'first_name[~]' => $search,
                    'last_name[~]'  => $search
                ]
            ],
            'LIMIT' => 10
        ]
    );

    echo json_encode(['success' => true, 'users' => $users]);
});


/*===================================================================
UMRAH ROUTES END
===================================================================*/

/*===================================================================
UMRAH SETTINGS ROUTES START
===================================================================*/

// ================================ GET /umrah/settings - LIST SETTINGS
$router->get(admin.'/umrah/settings', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::umrah_settings_management ?? 'Umrah Settings Management';
    $description = '';
    $header = true;
    $footer = true;

    $languages = $GLOBALS['languages'];

    require_once views."includes/header.php";
    require_once "app/views/admin/umrah/umrah-settings.php";
    require_once views."includes/footer.php";
});

// ================================ GET /umrah/settings/edit/{id} - EDIT SETTING
$router->get(admin.'/umrah/settings/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $settingId = intval($id);
    $setting = $db->get('umrah_settings', '*', ['id' => $settingId]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::setting_not_found ?? 'Setting not found'
        ];
        $type = $_GET['type'] ?? 'umrah_type';
        redirect(root . admin . '/umrah/settings?type=' . urlencode($type));
        return;
    }

    $translations = [];
    if (!empty($setting['translations'])) {
        $translations = json_decode($setting['translations'], true);
        if (!is_array($translations)) $translations = [];
    }

    $languages = $GLOBALS['languages'];
    $title = T::edit_umrah_setting ?? 'Edit Umrah Setting';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/umrah/umrah-settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /umrah/settings/save - SAVE SETTING
$router->post(admin.'/umrah/settings/save', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::verifyRequest(); // form posts a CSRF token (umrah-settings.php)

    $setting_id = intval($_POST['id'] ?? 0);
    $isEdit = $setting_id > 0;

    $setting_label = trim($_POST['setting_label'] ?? '');
    $setting_type  = trim($_POST['setting_type']  ?? '');
    $icon          = trim($_POST['icon']          ?? '');
    $metadata      = trim($_POST['metadata']      ?? '');

    $translations = [];
    if (isset($_POST['translations']) && is_array($_POST['translations'])) {
        foreach ($_POST['translations'] as $lang_code => $translation) {
            if (!empty(trim($translation))) {
                $translations[$lang_code] = trim($translation);
            }
        }
    }

    $errors = [];

    if (empty($setting_label)) {
        $errors[] = T::setting_label_required ?? 'Setting label is required';
    }

    if (empty($setting_type)) {
        $errors[] = T::setting_type_required ?? 'Setting type is required';
    }

    $allowed_types = ['umrah_type', 'amenity', 'tag', 'global_config', 'service', 'hotel', 'flight', 'car', 'room_type', 'duration'];
    if (!empty($setting_type) && !in_array($setting_type, $allowed_types)) {
        $errors[] = T::invalid_setting_type ?? 'Invalid setting type';
    }

    if ($setting_type === 'duration' && empty($errors)) {
        $minDays = (int)($_POST['min_days'] ?? 0);
        $maxRaw = trim((string)($_POST['max_days'] ?? ''));
        $maxDays = $maxRaw === '' ? null : (int)$maxRaw;
        $code = trim((string)($_POST['duration_code'] ?? ''));
        if ($minDays < 1) {
            $errors[] = 'Minimum days must be at least 1';
        } elseif ($maxDays !== null && $maxDays < $minDays) {
            $errors[] = 'Maximum days must be ≥ minimum days';
        } else {
            $meta = ['min_days' => $minDays];
            if ($maxDays !== null) {
                $meta['max_days'] = $maxDays;
            }
            if ($code !== '') {
                $meta['code'] = $code;
            }
            $metadata = json_encode($meta);
        }
    }

    $duplicate_check = [
        'setting_type'  => $setting_type,
        'setting_label' => $setting_label
    ];
    if ($isEdit) {
        $duplicate_check['id[!]'] = $setting_id;
    }
    $existing = $db->get('umrah_settings', 'id', $duplicate_check);
    if ($existing) {
        $errors[] = T::setting_already_exists ?? 'This setting already exists';
    }

    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/settings?type=' . urlencode($setting_type));
        return;
    }

    $setting_data = [
        'setting_type'  => $setting_type,
        'setting_label' => $setting_label,
        'icon'          => !empty($icon)     ? $icon     : null,
        'metadata'      => !empty($metadata) ? $metadata : null,
        'translations'  => !empty($translations) ? json_encode($translations) : null,
    ];

    try {
        if ($isEdit) {
            $setting_data['updated_at'] = date('Y-m-d H:i:s');
            $db->update('umrah_settings', $setting_data, ['id' => $setting_id]);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::umrah_setting_updated_success ?? 'Umrah setting updated successfully'
            ];
        } else {
            $max_id = $db->max('umrah_settings', 'id');
            $setting_data['id'] = ($max_id ?? 0) + 1;

            $setting_data['created_at'] = date('Y-m-d H:i:s');
            $setting_data['updated_at'] = date('Y-m-d H:i:s');
            $db->insert('umrah_settings', $setting_data);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::umrah_setting_added_success ?? 'Umrah setting added successfully'
            ];
        }
        redirect(root . admin . '/umrah/settings?type=' . urlencode($setting_type));
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/umrah/settings?type=' . urlencode($setting_type));
    }
});

// ================================ POST /umrah/settings/delete - DELETE SETTING
$router->post(admin.'/umrah/settings/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::verifyRequest(); // state-changing delete — require CSRF token

    $setting_id = intval($_POST['id'] ?? 0);

    if ($setting_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_setting_id ?? 'Invalid setting ID'
        ];
        redirect(root . admin . '/umrah/settings');
        return;
    }

    $setting = $db->get('umrah_settings', '*', ['id' => $setting_id]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::umrah_setting_not_found ?? 'Umrah setting not found'
        ];
        redirect(root . admin . '/umrah/settings');
        return;
    }

    $setting_type = $setting['setting_type'] ?? 'umrah_type';

    try {
        $result = $db->delete('umrah_settings', ['id' => $setting_id]);
        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::umrah_setting_deleted_success ?? 'Umrah setting deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_umrah_setting ?? 'Failed to delete Umrah setting'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/umrah/settings?type=' . urlencode($setting_type));
});

/*===================================================================
UMRAH SETTINGS ROUTES END
===================================================================*/

// ================================ POST /umrah/search-users - AJAX USER SEARCH
$router->post(admin."/umrah/search-users", function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header("Content-Type: application/json");
 
    $search = trim($_POST["search"] ?? "");
    if (empty($search)) {
        echo json_encode(["success" => false, "users" => []]);
        return;
    }
 
    $users = $db->select("users",
        ["user_id", "first_name", "last_name", "email"],
        [
            "AND" => [
                "status" => "active",
                "OR" => [
                    "email[~]" => $search,
                    "first_name[~]" => $search,
                    "last_name[~]" => $search
                ]
            ],
            "LIMIT" => 10
        ]
    );
 
    echo json_encode(["success" => true, "users" => $users]);
});
 
// ================================ POST /umrah/search-locations - AJAX LOCATION SEARCH
$router->post(admin."/umrah/search-locations", function () use ($SECURE,$db) {
    ADMIN_AUTH();
    header("Content-Type: application/json");
    
    $search = trim($_POST["search"] ?? "");
    if (empty($search)) {
        echo json_encode(["success" => false, "locations" => []]);
        return;
    }
    
    $locations = $db->select("locations",
        ["id", "city", "country", "country_code", "latitude", "longitude"],
        [
            "AND" => [
                "status" => "1",
                "OR" => [
                    "city[~]" => $search,
                    "country[~]" => $search,
                    "country_code[~]" => $search
                ]
            ],
            "LIMIT" => 10
        ]
    );
    
    echo json_encode(["success" => true, "locations" => $locations]);
});
