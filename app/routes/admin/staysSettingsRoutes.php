<?php
// app/routes/admin/hotelSettingsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
HOTEL SETTINGS ROUTES (Generic for amenities, boards, accommodations, etc.)
===================================================================*/

// ================================ GET /hotels/settings - LIST SETTINGS
$router->get(admin.'/stays/settings', function () use ($SECURE,$db) {

    ADMIN_AUTH();

    $title = T::stays_settings ?? 'Stays Settings';
    $description = "";
    $header = true;
    $footer = true;

    $languages = $GLOBALS['languages'];

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/settings.php";
    require_once views."includes/footer.php";
});

// ================================ GET /stays/settings/edit/{id} - EDIT SETTING
$router->get(admin.'/stays/settings/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();

    $settingId = intval($id);
    $setting = $db->get('stays_settings', '*', ['id' => $settingId]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::setting_not_found ?? 'Setting not found'
        ];

        // Preserve type parameter if available
        $type = $_GET['type'] ?? 'stay_amenity';
        redirect(root . admin . '/stays/settings?type=' . urlencode($type));
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
    $title = T::edit_setting ?? 'Edit Setting';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/stays/settings.php"; // Same file
    require_once views."includes/footer.php";
});

// ================================ POST /hotels/settings/save - SAVE SETTING
$router->post(admin.'/stays/settings/save', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    $setting_id = intval($_POST['id'] ?? 0);
    $isEdit = $setting_id > 0;

    // FORM DATA VALIDATION
    $name = trim($_POST['name'] ?? '');
    $setting_type = trim($_POST['setting_type'] ?? '');
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

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

    if (empty($name)) {
        $errors[] = T::name_required ?? 'Name is required';
    }

    if (empty($setting_type)) {
        $errors[] = T::setting_type_required ?? 'Setting type is required';
    }

    // CHECK FOR DUPLICATE NAME
    $duplicate_check = [
        'name' => $name,
        'setting_type' => $setting_type
    ];
    if ($isEdit) {
        $duplicate_check['id[!]'] = $setting_id;
    }
    $existing = $db->get('stays_settings', 'id', $duplicate_check);
    if ($existing) {
        $errors[] = T::name_already_exists ?? 'This name already exists';
    }

    // IF VALIDATION ERRORS, SHOW THEM
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/stays/settings?type=' . urlencode($setting_type));
        return;
    }

    // PREPARE DATA
    $setting_data = [
        'setting_type' => $setting_type,
        'name' => $name,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
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
            $result = $db->update('stays_settings', $setting_data, ['id' => $setting_id]);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::setting_updated_successfully ?? 'Setting updated successfully'
            ];
        } else {
            // INSERT NEW SETTING
            $setting_data['created_at'] = date('Y-m-d H:i:s');
            $result = $db->insert('stays_settings', $setting_data);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::setting_added_successfully ?? 'Setting added successfully'
            ];
        }

        // ⭐ PRESERVE THE TAB BY REDIRECTING WITH TYPE PARAMETER
        redirect(root . admin . '/stays/settings?type=' . urlencode($setting_type));

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/hotels/settings?type=' . urlencode($setting_type));
    }
});

// ================================ POST /stays/settings/delete - DELETE SETTING
$router->post(admin.'/stays/settings/delete', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    $setting_id = intval($_POST['id'] ?? 0);

    if ($setting_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_setting_id ?? 'Invalid setting ID'
        ];
        redirect(root . admin . '/stays/settings');
        return;
    }

    // ⭐ GET SETTING TYPE BEFORE DELETING (to preserve tab)
    $setting = $db->get('stays_settings', '*', ['id' => $setting_id]);

    if (!$setting) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::setting_not_found ?? 'Setting not found'
        ];
        redirect(root . admin . '/stays/settings');
        return;
    }

    // Store the type before deletion
    $setting_type = $setting['setting_type'] ?? 'stay_amenity';

    try {
        // DELETE SETTING
        $result = $db->delete('stays_settings', ['id' => $setting_id]);

        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::setting_deleted_successfully ?? 'Setting deleted successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_setting ?? 'Failed to delete setting'
            ];
        }

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }

    // ⭐ PRESERVE THE TAB BY REDIRECTING WITH TYPE PARAMETER
    redirect(root . admin . '/stays/settings?type=' . urlencode($setting_type));
});

/*===================================================================
STAYS SETTINGS ROUTES END
===================================================================*/