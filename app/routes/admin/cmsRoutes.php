<?php

// app/routes/admin/cmsRoutes.php
@$SECURE or die('Access Denied!');

// ================================ CMS PAGES
$router->get(admin.'/cms/pages', function () use ($SECURE,$db) {

// ADMIN AUTH CHECK
ADMIN_AUTH();

// META DATA
$title = 'CMS Pages';
$description = '';
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once "app/views/admin/cms/pages.php";
require_once views."includes/footer.php";

});

// ================================ CMS ADD PAGE
$router->get(admin.'/cms/pages/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add CMS Page';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/cms/pages-manage.php";
    require_once views."includes/footer.php";

});

// ================================ CMS EDIT PAGE
$router->get(admin.'/cms/pages/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit CMS Page';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/cms/pages-manage.php";
    require_once views."includes/footer.php";

});

// ================================ CMS MANAGE PAGE - POST (Add/Update)
$router->post(admin.'/cms/pages/manage', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // CSRF Token Validation
    CSRF::verifyRequest();

    try {
        // Check if it's update or insert
        $isUpdate = !empty($_POST['id']);
        $id = $isUpdate ? (int)$_POST['id'] : null;

        // Validate required fields
        if (empty($_POST['page_name'])) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::page_name_required ?? 'Page name is required'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/cms/pages');
        }

        // Prepare data
        $data = [
            'page_name' => trim($_POST['page_name']),
            'slug_url' => trim($_POST['slug_url']),
            'content' => $_POST['content'] ?? '',
            'position' => $_POST['position'] ?? 'header',
            'order' => (int)($_POST['order'] ?? 0),
            'parent_id' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
            'status' => isset($_POST['status']) ? (int)$_POST['status'] : 1
        ];

        // Check if slug already exists
        if ($isUpdate) {
            // For update: exclude current page from duplicate check
            $existing = $db->get('cms', 'id', [
                'AND' => [
                    'slug_url' => $data['slug_url'],
                    'id[!]' => $id
                ]
            ]);

            // Check if page exists
            $page = $db->get('cms', '*', ['id' => $id]);
            if (!$page) {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::page_not_found ?? 'Page not found'
                ];
                redirect(root . admin . '/cms/pages');
            }

            // Update database
            $db->update('cms', $data, ['id' => $id]);
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::page_updated_successfully ?? 'Page updated successfully'
            ];

        } else {
            // For insert: check if slug exists
            $existing = $db->get('cms', 'id', ['slug_url' => $data['slug_url']]);

            // Insert into database
            $db->insert('cms', $data);
            
            // Get the newly inserted ID
            $id = $db->id();
            
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::page_added_successfully ?? 'Page added successfully'
            ];
        }

        // Redirect with hash
        $hash = $_POST['active_tab'] ?? 'details';
        redirect(root . admin . '/cms/pages/edit/' . $id . '#' . $hash);

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/cms/pages');
    }
});

// ================================ CMS IMAGE UPLOAD
$router->post(admin.'/cms/upload-image', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    try {
        if (!isset($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error');
        }

        $file = $_FILES['upload'];
        // SECURITY: validate real MIME (finfo). SVG is intentionally excluded —
        // it can carry scripts (stored XSS); raster images only.
        $chk = secureUploadCheck($file, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
        if (!$chk['ok']) {
            throw new Exception($chk['error'] ?? 'Invalid file type. Only JPG, PNG, GIF, WEBP images are allowed.');
        }

        // Generate unique filename
        $filename = 'cms_' . bin2hex(random_bytes(8)) . '.' . $chk['ext'];

        // Upload directory
        $uploadDir = 'uploads/cms/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploadPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to move uploaded file');
        }
        @chmod($uploadPath, 0644);

        // Return CKEditor expected response
        echo json_encode([
            'url' => root . $uploadPath
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'error' => [
                'message' => $e->getMessage()
            ]
        ]);
    }
    exit;
});

// ================================ GET /cms/pages/translate/:id
$router->get(admin.'/cms/pages/translate/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    
    // Get the CMS page - use unique variable name
    $cmsPage = $db->get('cms', '*', ['id' => $id]);
    
    if (!$cmsPage) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::page_not_found ?? 'Page not found'
        ];
        header('Location: ' . root . admin . '/cms/pages');
        exit;
    }
    
    // Parse existing translations
    $page_name_translations = [];
    if (!empty($cmsPage['page_name_translations'])) {
        $page_name_translations = json_decode($cmsPage['page_name_translations'], true) ?? [];
    }
    
    $content_translations = [];
    if (!empty($cmsPage['content_translations'])) {
        $content_translations = json_decode($cmsPage['content_translations'], true) ?? [];
    }
    
    // Get available languages (exclude English)
    $available_languages = [];
    $raw_languages = $GLOBALS['languages'] ?? [];
    foreach ($raw_languages as $lang) {
        if (isset($lang['lang_code']) && $lang['lang_code'] !== 'en') {
            $available_languages[$lang['lang_code']] = $lang;
        }
    }
    
    // If no languages configured, use defaults
    if (empty($available_languages)) {
        $available_languages = [
            'ar' => ['lang_code' => 'ar', 'name' => 'العربية'],
            'fr' => ['lang_code' => 'fr', 'name' => 'Français'],
            'es' => ['lang_code' => 'es', 'name' => 'Español'],
            'de' => ['lang_code' => 'de', 'name' => 'Deutsch'],
        ];
    }

    $title = (T::translate_page ?? 'Translate Page') . ': ' . $cmsPage['page_name'];
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views . "includes/header.php";
    require_once "app/views/admin/cms/translate.php";
    require_once views . "includes/footer.php";
});

// ================================ POST /cms/pages/translate/:id
$router->post(admin.'/cms/pages/translate/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    
    // CSRF Token Validation
    CSRF::verifyRequest();
    
    // Check if this is an AJAX request
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    
    // Get the CMS page
    $page = $db->get('cms', '*', ['id' => $id]);
    
    if (!$page) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => T::page_not_found ?? 'Page not found'
            ]);
            exit;
        }
        
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::page_not_found ?? 'Page not found'
        ];
        redirect(root . admin . '/cms/pages');
    }
    
    // Check if this is a delete request
    if (isset($_POST['action']) && $_POST['action'] === 'delete_translation') {
        $lang_to_delete = $_POST['lang_code'] ?? '';
        
        if (!empty($lang_to_delete)) {
            try {
                // Get existing translations
                $existing_page_names = [];
                if (!empty($page['page_name_translations'])) {
                    $existing_page_names = json_decode($page['page_name_translations'], true) ?? [];
                }
                
                $existing_contents = [];
                if (!empty($page['content_translations'])) {
                    $existing_contents = json_decode($page['content_translations'], true) ?? [];
                }
                
                // Remove the specified language
                unset($existing_page_names[$lang_to_delete]);
                unset($existing_contents[$lang_to_delete]);
                
                // Update database
                $db->update('cms', [
                    'page_name_translations' => !empty($existing_page_names) ? json_encode($existing_page_names, JSON_UNESCAPED_UNICODE) : null,
                    'content_translations' => !empty($existing_contents) ? json_encode($existing_contents, JSON_UNESCAPED_UNICODE) : null
                ], ['id' => $id]);
                
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => T::translation_deleted ?? 'Translation deleted successfully'
                    ]);
                    exit;
                }
                
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::translation_deleted ?? 'Translation deleted successfully'
                ];
                
            } catch (Exception $e) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => T::failed_delete_translation ?? 'Failed to delete translation'
                    ]);
                    exit;
                }
                
                $_SESSION['message'] = [
                    'type' => 'error',
                    'text' => T::failed_delete_translation ?? 'Failed to delete translation'
                ];
            }
            
            // Redirect back to edit page with translations tab
            redirect(root . admin . '/cms/pages/edit/' . $id . '#translations:' . $lang_to_delete);
        }
    }
    
    // Normal save translations
    $page_name_translations = $_POST['page_name_translations'] ?? [];
    $content_translations = $_POST['content_translations'] ?? [];
    
    // Determine which language tab to return to (first submitted or first available)
    $active_lang = '';
    foreach ($page_name_translations as $lang => $value) {
        if (!empty(trim($value))) {
            $active_lang = $lang;
            break;
        }
    }
    if (empty($active_lang)) {
        foreach ($content_translations as $lang => $value) {
            if (!empty(trim($value))) {
                $active_lang = $lang;
                break;
            }
        }
    }
    // Fallback to first available language
    if (empty($active_lang)) {
        $available_languages = $GLOBALS['languages'] ?? [];
        foreach ($available_languages as $lang_data) {
            if (isset($lang_data['lang_code']) && $lang_data['lang_code'] !== 'en') {
                $active_lang = $lang_data['lang_code'];
                break;
            }
        }
    }
    if (empty($active_lang)) {
        $active_lang = 'ar'; // Ultimate fallback
    }
    
    try {
        // Get existing translations to merge (preserve translations not in current form)
        $existing_page_names = [];
        if (!empty($page['page_name_translations'])) {
            $existing_page_names = json_decode($page['page_name_translations'], true) ?? [];
        }
        
        $existing_contents = [];
        if (!empty($page['content_translations'])) {
            $existing_contents = json_decode($page['content_translations'], true) ?? [];
        }
        
        // Filter and merge translations - also track updated languages
        $filtered_page_names = $existing_page_names;
        $updated_languages = [];
        
        foreach ($page_name_translations as $lang => $value) {
            if (!empty(trim($value))) {
                $filtered_page_names[$lang] = trim($value);
                $updated_languages[$lang] = true;
            } else {
                unset($filtered_page_names[$lang]);
            }
        }
        
        $filtered_contents = $existing_contents;
        foreach ($content_translations as $lang => $value) {
            if (!empty(trim($value))) {
                $filtered_contents[$lang] = trim($value);
                $updated_languages[$lang] = true;
            } else {
                unset($filtered_contents[$lang]);
            }
        }
        
        // Update database
        $db->update('cms', [
            'page_name_translations' => !empty($filtered_page_names) ? json_encode($filtered_page_names, JSON_UNESCAPED_UNICODE) : null,
            'content_translations' => !empty($filtered_contents) ? json_encode($filtered_contents, JSON_UNESCAPED_UNICODE) : null
        ], ['id' => $id]);
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => T::translations_saved ?? 'Translations saved successfully',
                'updated_languages' => array_keys($updated_languages)
            ]);
            exit;
        }
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::translations_saved ?? 'Translations saved successfully'
        ];
        
        // Redirect back to edit page with translations tab and active language
        redirect(root . admin . '/cms/pages/edit/' . $id . '#translations:' . $active_lang);
        
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => T::failed_save_translations ?? 'Failed to save translations'
            ]);
            exit;
        }
        
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::failed_save_translations ?? 'Failed to save translations'
        ];
        
        // Redirect back to edit page with translations tab
        redirect(root . admin . '/cms/pages/edit/' . $id . '#translations:' . $active_lang);
    }
});