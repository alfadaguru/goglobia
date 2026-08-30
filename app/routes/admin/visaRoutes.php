<?php
// app/routes/admin/visaRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
VISAS ROUTES START
===================================================================*/

// ================================ GET /visa - LIST ALL VISA
$router->get(admin.'/visa', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::visa_management ?? 'Visa Management';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/visa.php";
    require_once views."includes/footer.php";
});

// ================================ GET /visa/add - ADD NEW VISA FORM
$router->get(admin.'/visa/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $mode = 'add';

    // Fetch countries
    $countries = $db->select('countries', ['id', 'name', 'nicename'], ['status' => 1], ['ORDER' => ['nicename' => 'ASC']]);

    // Get currencies
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    // Fetch dynamic settings from visa_settings
    $processingSpeeds = $db->select('visa_settings', '*', ['setting_type' => 'processing_speed', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);
    $visaTypes = $db->select('visa_settings', '*', ['setting_type' => 'visa_type', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);
    $entryTypes = $db->select('visa_settings', '*', ['setting_type' => 'entry_type', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);

    $title = T::add_visa ?? 'Add Visa';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/manage-visa.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa/add - ADD NEW VISA
$router->post(admin.'/visa/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    // Form data validation
    $from_country_id = intval($_POST['from_country_id'] ?? 0);
    $to_country_id = intval($_POST['to_country_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '[]');
    $prices_json = $_POST['prices'] ?? '[]';
    $prices_data = json_decode($prices_json, true);

    $min_passport_validity_months = intval($_POST['min_passport_validity_months'] ?? 6);
    $allow_urgent_processing = isset($_POST['allow_urgent_processing']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;

    // Validation
    $errors = [];
    if ($from_country_id <= 0) $errors[] = T::from_country_required ?? 'From country is required';
    if ($to_country_id <= 0) $errors[] = T::to_country_required ?? 'To country is required';
    if (empty($prices_data)) $errors[] = T::pricing_variants_required ?? 'At least one pricing variant is required';

    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/add');
        return;
    }

    // Handle multiple image uploads
    $uploaded_images = [];
    if (isset($_FILES['visa_images']) && is_array($_FILES['visa_images']['name'])) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/visa/gallery/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_count = count($_FILES['visa_images']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['visa_images']['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['visa_images']['name'][$i], PATHINFO_EXTENSION);
                $new_filename = 'visa_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/visa/gallery/' . $new_filename;

                if (move_uploaded_file($_FILES['visa_images']['tmp_name'][$i], $upload_path)) {
                    $uploaded_images[] = [
                        'url' => $image_url,
                        'default' => ($i === 0) ? true : false // Boolean: true/false
                    ];
                }
            }
        }
    }

    // Convert images array to JSON
    $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;

    // First variant for backward compatibility
    $first_variant = $prices_data[0] ?? [];

    // Prepare data
    $visa_data = [
        'from_country_id' => $from_country_id,
        'to_country_id' => $to_country_id,
        'description' => !empty($description) ? $description : null,
        'requirements' => $requirements,
        'currency' => $first_variant['currency'] ?? 'USD',
        'status' => $status,
        'img' => $images_json,
        'prices' => $prices_json,
        'created_at' => date('Y-m-d H:i:s')
    ];

    try {
        $result = $db->insert('visa', $visa_data);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::visa_added_successfully ?? 'Visa added successfully'
            ];
            redirect(root . admin . '/visa');
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_add_visa ?? 'Failed to add visa'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/add');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/add');
    }
});

// ================================ GET /visa/edit/{id} - EDIT VISA FORM
$router->get(admin.'/visa/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $mode = 'edit';
    $visa_id = intval($id);



    $visa = $db->get('visa', '*', ['id' => $visa_id]);

    if (!$visa) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::visa_not_found ?? 'Visa not found'
        ];
        redirect(root . admin . '/visa');
        return;
    }

    // Fetch countries
    $countries = $db->select('countries', ['id', 'name', 'nicename'], ['status' => 1], ['ORDER' => ['nicename' => 'ASC']]);

    // Get currencies
    $currencies = $GLOBALS['currencies'] ?? ['USD' => 'USD'];

    // Fetch dynamic settings from visa_settings
    $processingSpeeds = $db->select('visa_settings', '*', ['setting_type' => 'processing_speed', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);
    $visaTypes = $db->select('visa_settings', '*', ['setting_type' => 'visa_type', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);
    $entryTypes = $db->select('visa_settings', '*', ['setting_type' => 'entry_type', 'status' => 1, 'ORDER' => ['display_order' => 'ASC']]);

    $title = T::edit_visa ?? 'Edit Visa';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/manage-visa.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa/edit/{id} - UPDATE VISA
$router->post(admin.'/visa/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $visa_id = intval($id);



    $visa = $db->get('visa', '*', ['id' => $visa_id]);

    if (!$visa) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::visa_not_found ?? 'Visa not found'
        ];
        redirect(root . admin . '/visa');
        return;
    }

    // Form data validation
    $from_country_id = intval($_POST['from_country_id'] ?? 0);
    $to_country_id = intval($_POST['to_country_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '[]');
    $prices_json = $_POST['prices'] ?? '[]';
    $prices_data = json_decode($prices_json, true);

    $min_passport_validity_months = intval($_POST['min_passport_validity_months'] ?? 6);
    $allow_urgent_processing = isset($_POST['allow_urgent_processing']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;

    // Validation
    $errors = [];
    if ($from_country_id <= 0) $errors[] = T::from_country_required ?? 'From country is required';
    if ($to_country_id <= 0) $errors[] = T::to_country_required ?? 'To country is required';
    if (empty($prices_data)) $errors[] = T::pricing_variants_required ?? 'At least one pricing variant is required';

    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/edit/' . $visa_id);
        return;
    }

    // ===== IMPROVED IMAGE HANDLING =====
    // Define project root early for all file operations
    $project_root = realpath(__DIR__ . '/../../../');
    $upload_dir = $project_root . '/uploads/visa/gallery/';

    // Parse existing images from database
    $existing_images = [];
    if (!empty($visa['img'])) {
        $existing_images = json_decode($visa['img'], true);
        if (!is_array($existing_images)) {
            $existing_images = [];
        }
    }

    // Get reordered images from POST
    $reordered_images = [];
    if (!empty($_POST['reordered_images'])) {
        $reordered_images = json_decode($_POST['reordered_images'], true);
        if (!is_array($reordered_images)) {
            $reordered_images = $existing_images;
        }
    } else {
        $reordered_images = $existing_images;
    }

    // Get images to delete from POST
    $images_to_delete = [];
    if (!empty($_POST['images_to_delete'])) {
        $images_to_delete = json_decode($_POST['images_to_delete'], true);
        if (!is_array($images_to_delete)) {
            $images_to_delete = [];
        }
    }

    // Get default image selection
    $default_image = $_POST['default_image'] ?? '';

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
                    error_log("Visa image deleted: " . $file_path);
                } else {
                    // Failed to delete - log error
                    error_log("Failed to delete visa image: " . $file_path);
                }
            } else {
                // File doesn't exist - log warning
                error_log("Visa image file not found: " . $file_path);
            }
        }

        // Remove deleted images from reordered array
        $reordered_images = array_filter($reordered_images, function($img) use ($images_to_delete) {
            return !in_array($img['url'], $images_to_delete);
        });
        $reordered_images = array_values($reordered_images); // Re-index
    }

    // Update default image setting (use boolean true/false)
    if (!empty($default_image)) {
        foreach ($reordered_images as &$img) {
            $img['default'] = ($img['url'] === $default_image) ? true : false;
        }
        unset($img);
    }

    // Handle new image uploads
    if (isset($_FILES['visa_images']) && is_array($_FILES['visa_images']['name'])) {
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_count = count($_FILES['visa_images']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['visa_images']['error'][$i] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['visa_images']['name'][$i], PATHINFO_EXTENSION);
                $new_filename = 'visa_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                $image_url = '/uploads/visa/gallery/' . $new_filename;

                if (move_uploaded_file($_FILES['visa_images']['tmp_name'][$i], $upload_path)) {
                    $reordered_images[] = [
                        'url' => $image_url,
                        'default' => (empty($reordered_images)) ? true : false // Boolean: true/false
                    ];
                }
            }
        }
    }

    // Ensure at least one image is marked as default
    if (!empty($reordered_images)) {
        $has_default = false;
        foreach ($reordered_images as $img) {
            if (!empty($img['default']) && $img['default'] === true) {
                $has_default = true;
                break;
            }
        }

        if (!$has_default) {
            $reordered_images[0]['default'] = true;
        }
    }

    // Convert images array to JSON
    $images_json = !empty($reordered_images) ? json_encode(array_values($reordered_images)) : null;

    // First variant for backward compatibility
    $first_variant = $prices_data[0] ?? [];

    // Prepare data
    $visa_data = [
        'from_country_id' => $from_country_id,
        'to_country_id' => $to_country_id,
        'description' => !empty($description) ? $description : null,
        'requirements' => $requirements,
        'currency' => $first_variant['currency'] ?? 'USD',
        'status' => $status,
        'img' => $images_json,
        'prices' => $prices_json,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        $result = $db->update('visa', $visa_data, ['id' => $visa_id]);

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::visa_updated_successfully ?? 'Visa updated successfully'
        ];
        redirect(root . admin . '/visa/edit/' . $visa_id);

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/edit/' . $visa_id);
    }
});

// ================================ POST /visa/delete - DELETE VISA
$router->post(admin.'/visa/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $visa_id = intval($_POST['id'] ?? 0);

    if ($visa_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_visa_id ?? 'Invalid visa ID'
        ];
        redirect(root . admin . '/visa');
        return;
    }

    $visa = $db->get('visa', ['id', 'img'], ['id' => $visa_id]);

    if (!$visa) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::visa_not_found ?? 'Visa not found'
        ];
        redirect(root . admin . '/visa');
        return;
    }

    try {
        $project_root = realpath(__DIR__ . '/../../../');

        // Delete all images if exist
        if (!empty($visa['img'])) {
            $images = json_decode($visa['img'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    $image_url = is_array($img) ? ($img['url'] ?? '') : $img;
                    if (!empty($image_url)) {
                        // Clean the URL - remove leading slash if present
                        $clean_url = ltrim($image_url, '/');

                        // Construct full file path
                        $file_path = $project_root . '/' . $clean_url;

                        // Attempt to delete the file
                        if (file_exists($file_path)) {
                            if (@unlink($file_path)) {
                                error_log("Visa image deleted during visa deletion: " . $file_path);
                            } else {
                                error_log("Failed to delete visa image during visa deletion: " . $file_path);
                            }
                        }
                    }
                }
            }
        }

        // Delete visa from database
        $result = $db->delete('visa', ['id' => $visa_id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::deleted_successfully ?? 'Visa deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete ?? 'Failed to delete visa'
            ];
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa');
});

/*===================================================================
VISA SETTINGS ROUTES START
===================================================================*/

// ================================ GET /visa/settings - LIST ALL VISA SETTINGS
$router->get(admin.'/visa/settings', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::visa_settings_management ?? 'Visa Settings Management';
    $description = '';
    $header = true;
    $footer = true;

    // Get all active languages for translations
    $languages = $db->select('languages', ['lang_code', 'name'], ['status' => 1], ['ORDER' => ['name' => 'ASC']]);

    // Document requirement toggles
    $visaDocSettings = $db->get('settings', ['visa_passport_required', 'visa_national_id_required'], ['id' => 1]);

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa/settings/document-requirements - SAVE DOCUMENT REQUIREMENT TOGGLES
$router->post(admin.'/visa/settings/document-requirements', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_request ?? 'Invalid request. Please try again.'
        ];
        redirect(root . admin . '/visa/settings');
        return;
    }

    $db->update('settings', [
        'visa_passport_required' => isset($_POST['visa_passport_required']) ? '1' : '0',
        'visa_national_id_required' => isset($_POST['visa_national_id_required']) ? '1' : '0',
    ], ['id' => 1]);

    $_SESSION['message'] = [
        'type' => 'success',
        'text' => T::settings_saved ?? 'Settings saved successfully'
    ];
    redirect(root . admin . '/visa/settings');
});

// ================================ GET /visa/settings/edit/{id} - EDIT VISA SETTING
$router->get(admin.'/visa/settings/edit/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($id);
    $setting = $db->get('visa_settings', '*', ['id' => $id]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::setting_not_found ?? 'Setting not found'
        ];
        redirect(root . admin . '/visa/settings');
        return;
    }

    $isEdit = true;

    // Get all active languages for translations
    $languages = $db->select('languages', ['lang_code', 'name'], ['status' => 1], ['ORDER' => ['name' => 'ASC']]);

    $title = T::edit_setting ?? 'Edit Setting';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/settings.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa/settings/save - ADD/UPDATE VISA SETTING
$router->post(admin.'/visa/settings/save', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $setting_type = trim($_POST['setting_type'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $display_order = intval($_POST['display_order'] ?? 0);
    $status = intval($_POST['status'] ?? 1);

    // Validation
    $errors = [];
    if (empty($setting_type)) $errors[] = T::setting_type_required ?? 'Setting type is required';
    if (empty($name)) $errors[] = T::name_required ?? 'Name is required';
    if (empty($value)) $errors[] = T::value_required ?? 'Value is required';

    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/settings');
        return;
    }

    // Handle translations
    $translations = [];
    if (isset($_POST['translations']) && is_array($_POST['translations'])) {
        foreach ($_POST['translations'] as $lang_code => $translation) {
            $translation = trim($translation);
            if (!empty($translation)) {
                $translations[$lang_code] = $translation;
            }
        }
    }
    $translations_json = !empty($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : null;

    // Prepare data
    $setting_data = [
        'setting_type' => $setting_type,
        'name' => $name,
        'value' => $value,
        'icon' => !empty($icon) ? $icon : null,
        'description' => !empty($description) ? $description : null,
        'translations' => $translations_json,
        'display_order' => $display_order,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        if ($id > 0) {
            // Update existing setting
            $result = $db->update('visa_settings', $setting_data, ['id' => $id]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::updated_successfully ?? 'Setting updated successfully'
                ];
            } else {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::failed_to_update ?? 'Failed to update setting'
                ];
            }
        } else {
            // Insert new setting
            $setting_data['created_at'] = date('Y-m-d H:i:s');
            $result = $db->insert('visa_settings', $setting_data);

            if ($result) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::added_successfully ?? 'Setting added successfully'
                ];
            } else {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::failed_to_add ?? 'Failed to add setting'
                ];
            }
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa/settings?type=' . urlencode($setting_type));
});

// ================================ POST /visa/settings/delete - DELETE VISA SETTING
$router->post(admin.'/visa/settings/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_id ?? 'Invalid ID'
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/settings');
        return;
    }

    // Get setting to preserve setting_type for redirect
    $setting = $db->get('visa_settings', ['setting_type'], ['id' => $id]);
    $setting_type = $setting['setting_type'] ?? 'visa_type';

    try {
        $result = $db->delete('visa_settings', ['id' => $id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::deleted_successfully ?? 'Setting deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete ?? 'Failed to delete setting'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa/settings?type=' . urlencode($setting_type));
});

/*===================================================================
VISA SETTINGS ROUTES END
===================================================================*/

/*===================================================================
VISA BOOKINGS ROUTES START
===================================================================*/

// ================================ GET /visa-bookings - LIST ALL VISA BOOKINGS
$router->get(admin.'/visa-bookings', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::visa_bookings ?? 'Visa Bookings';
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/visa/bookings.php";
    require_once views."includes/footer.php";
});

// ================================ GET /visa-bookings/view/{id} - VIEW VISA BOOKING DETAILS
$router->get(admin.'/visa-bookings/view/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($id);
    $booking = $db->get('visa_bookings', '*', ['id' => $id]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::booking_not_found ?? 'Booking not found'
        ];
        redirect(root . admin . '/visa-bookings');
        return;
    }

    $title = T::booking_details ?? 'Booking Details';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/booking-details.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa-bookings/update-status - UPDATE BOOKING STATUS
$router->post(admin.'/visa-bookings/update-status', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($_POST['id'] ?? 0);
    $booking_status = trim($_POST['booking_status'] ?? '');
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    if ($id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_id ?? 'Invalid ID'
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa-bookings');
        return;
    }

    $update_data = [
        'booking_status' => $booking_status,
        'admin_notes' => !empty($admin_notes) ? $admin_notes : null,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if ($booking_status === 'rejected' && !empty($rejection_reason)) {
        $update_data['rejection_reason'] = $rejection_reason;
    }

    if ($booking_status === 'processing') {
        $update_data['processed_at'] = date('Y-m-d H:i:s');
    }

    if ($booking_status === 'completed') {
        $update_data['completed_at'] = date('Y-m-d H:i:s');
    }

    try {
        $result = $db->update('visa_bookings', $update_data, ['id' => $id]);

        if ($result !== false) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::booking_updated_successfully ?? 'Booking status updated successfully'
            ];
            
            // TODO: Send email notification to customer about status change
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_update ?? 'Failed to update booking'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa-bookings/view/' . $id);
});

// ================================ POST /visa-bookings/delete - DELETE VISA BOOKING
$router->post(admin.'/visa-bookings/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_id ?? 'Invalid ID'
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa-bookings');
        return;
    }

    try {
        $result = $db->delete('visa_bookings', ['id' => $id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::deleted_successfully ?? 'Booking deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete ?? 'Failed to delete booking'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa-bookings');
});

/*===================================================================
VISA BOOKINGS ROUTES END
===================================================================*/

/*===================================================================
VISA PRICES ROUTES START
===================================================================*/

// ================================ GET /visa/prices/add - ADD NEW PRICE
$router->get(admin.'/visa/prices/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::add_new_price ?? 'Add New Price';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/price-form.php";
    require_once views."includes/footer.php";
});

// ================================ GET /visa/prices/edit/{id} - EDIT PRICE
$router->get(admin.'/visa/prices/edit/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $price = $db->get('visa_prices', '*', ['id' => $id]);

    if (!$price) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::price_not_found ?? 'Price not found'
        ];
        redirect(root . admin . '/visa/settings?type=prices');
        return;
    }

    $title = T::edit_price ?? 'Edit Price';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/visa/price-form.php";
    require_once views."includes/footer.php";
});

// ================================ POST /visa/prices/save - SAVE PRICE (ADD/UPDATE)
$router->post(admin.'/visa/prices/save', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($_POST['id'] ?? 0);
    $from_country = strtoupper(trim($_POST['from_country'] ?? ''));
    $to_country = strtoupper(trim($_POST['to_country'] ?? ''));
    $visa_type = trim($_POST['visa_type'] ?? '');
    $processing_speed = trim($_POST['processing_speed'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'USD');
    $status = isset($_POST['status']) ? 1 : 0;

    // Validation
    $errors = [];
    if (empty($from_country)) $errors[] = T::from_country_required ?? 'From country is required';
    if (empty($to_country)) $errors[] = T::to_country_required ?? 'To country is required';
    if (empty($visa_type)) $errors[] = T::visa_type_required ?? 'Visa type is required';
    if (empty($processing_speed)) $errors[] = T::processing_speed_required ?? 'Processing speed is required';
    if ($price <= 0) $errors[] = T::price_must_be_greater_than_zero ?? 'Price must be greater than zero';

    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/prices/add');
        return;
    }

    $priceData = [
        'from_country' => $from_country,
        'to_country' => $to_country,
        'visa_type' => $visa_type,
        'processing_speed' => $processing_speed,
        'price' => $price,
        'currency' => $currency,
        'status' => $status
    ];

    try {
        if ($id > 0) {
            // Update existing price
            // Check for duplicate (excluding current record)
            $existing = $db->get('visa_prices', 'id', [
                'from_country' => $from_country,
                'to_country' => $to_country,
                'visa_type' => $visa_type,
                'processing_speed' => $processing_speed,
                'id[!]' => $id
            ]);

            if ($existing) {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::price_combination_already_exists ?? 'This price combination already exists'
                ];
                redirect($_SERVER['HTTP_REFERER']);
                return;
            }

            $result = $db->update('visa_prices', $priceData, ['id' => $id]);
            $message = T::price_updated_successfully ?? 'Price updated successfully';
        } else {
            // Add new price
            // Check for duplicate
            $existing = $db->get('visa_prices', 'id', [
                'from_country' => $from_country,
                'to_country' => $to_country,
                'visa_type' => $visa_type,
                'processing_speed' => $processing_speed
            ]);

            if ($existing) {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::price_combination_already_exists ?? 'This price combination already exists'
                ];
                redirect($_SERVER['HTTP_REFERER']);
                return;
            }

            $result = $db->insert('visa_prices', $priceData);
            $message = T::price_added_successfully ?? 'Price added successfully';
        }

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => $message
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_save ?? 'Failed to save price'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa/settings?type=prices');
});

// ================================ POST /visa/prices/delete - DELETE PRICE
$router->post(admin.'/visa/prices/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_id ?? 'Invalid ID'
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/visa/settings?type=prices');
        return;
    }

    try {
        $result = $db->delete('visa_prices', ['id' => $id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::price_deleted_successfully ?? 'Price deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete ?? 'Failed to delete price'
            ];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => (T::database_error ?? 'Database error') . ': ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/visa/settings?type=prices');
});

/*===================================================================
VISA PRICES ROUTES END
===================================================================*/

/*===================================================================
VISAS ROUTES END
===================================================================*/
