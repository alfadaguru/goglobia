<?php
// app/routes/admin/blogRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
BLOGS ROUTES START
===================================================================*/

// ================================ GET /blogs - LIST ALL BLOGS
$router->get(admin.'/blogs', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::blogs_management ?? 'Blogs Management';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/blogs/blogs.php";
    require_once views."includes/footer.php";
});

// ================================ GET /blogs/add - ADD NEW BLOG FORM
$router->get(admin.'/blogs/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'add';
    
    // Get categories for dropdown
    $categories = $db->select('blog_categories', ['id', 'cat_name'], ['status' => 1]);
    
    $title = T::add_blog ?? 'Add Blog Post';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/blogs/manage-blog.php";
    require_once views."includes/footer.php";
});

// ================================ POST /blogs/add - ADD NEW BLOG
$router->post(admin.'/blogs/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();

    $post_title = trim($_POST['post_title'] ?? '');
    $post_desc = trim($_POST['post_desc'] ?? '');
    $post_category = intval($_POST['post_category'] ?? 0);
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;
    $published_at = !empty($_POST['published_at']) ? date('Y-m-d H:i:s', strtotime($_POST['published_at'])) : date('Y-m-d H:i:s');
    
    $errors = [];
    if (empty($post_title)) $errors[] = T::blog_title_required ?? 'Blog title is required';
    if (empty($post_desc)) $errors[] = T::blog_description_required ?? 'Blog description is required';
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/blogs/add');
        return;
    }
    
    // Generate slug
    $post_slug = generateUniqueSlug($post_title, 'blogs', 'post_slug', $db);
    
    // Handle featured image upload
    $post_img = '';
    if (isset($_FILES['post_img']) && $_FILES['post_img']['error'] === UPLOAD_ERR_OK) {
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/blogs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $file = $_FILES['post_img'];
        // SECURITY: validate real MIME + safe extension (finfo).
        $chk = secureUploadCheck($file, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
        if ($chk['ok']) {
            $new_filename = 'blog_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $chk['ext'];
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                @chmod($upload_path, 0644);
                $post_img = '/uploads/blogs/' . $new_filename;
            }
        }
    }
    
    // Handle translations
    $translations = [];
    $title_translations = $_POST['title_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    
    $all_lang_codes = array_unique(array_merge(
        array_keys($title_translations),
        array_keys($desc_translations)
    ));
    
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];
        if (!empty(trim($title_translations[$lang_code] ?? ''))) $lang_data['title'] = trim($title_translations[$lang_code]);
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) $lang_data['desc'] = trim($desc_translations[$lang_code]);
        if (!empty($lang_data)) $translations[$lang_code] = $lang_data;
    }
    
    $blog_data = [
        'post_title' => $post_title,
        'post_slug' => $post_slug,
        'post_desc' => $post_desc,
        'post_category' => $post_category > 0 ? $post_category : null,
        'post_img' => !empty($post_img) ? $post_img : null,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_description' => !empty($meta_description) ? $meta_description : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'featured' => $featured,
        'status' => $status,
        'published_at' => $published_at,
        'author_id' => $_SESSION['admin_id'] ?? null,
        'translations' => !empty($translations) ? json_encode($translations) : null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->insert('blogs', $blog_data);
        if ($result) {
            $new_blog_id = $db->id();
            $_SESSION['message'] = ['type' => 'success', 'text' => T::blog_added_successfully ?? 'Blog added successfully'];
            redirect(root . admin . '/blogs/edit/' . $new_blog_id);
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_add_blog ?? 'Failed to add blog'];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/blogs/add');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/blogs/');
    }
});

// ================================ GET /blogs/edit/{id} - EDIT BLOG FORM
$router->get(admin.'/blogs/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $mode = 'edit';
    $blog_id = intval($id);
    
    $blog = $db->get('blogs', '*', ['id' => $blog_id]);
    if (!$blog) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::blog_not_found ?? 'Blog not found'];
        redirect(root . admin . '/blogs');
        return;
    }
    
    $categories = $db->select('blog_categories', ['id', 'cat_name'], ['status' => 1]);
    
    // Decode translations
    $title_translations = [];
    $desc_translations = [];
    
    if (!empty($blog['translations'])) {
        $all_translations = json_decode($blog['translations'], true);
        if (is_array($all_translations)) {
            foreach ($all_translations as $lang_code => $translations_data) {
                if (!empty($translations_data['title'])) $title_translations[$lang_code] = $translations_data['title'];
                if (!empty($translations_data['desc'])) $desc_translations[$lang_code] = $translations_data['desc'];
            }
        }
    }
    
    $title = T::edit_blog ?? 'Edit Blog Post';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/blogs/manage-blog.php";
    require_once views."includes/footer.php";
});

// ================================ POST /blogs/edit/{id} - UPDATE BLOG
$router->post(admin.'/blogs/edit/(.*)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $blog_id = intval($id);
    
    $blog = $db->get('blogs', '*', ['id' => $blog_id]);
    if (!$blog) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::blog_not_found ?? 'Blog not found'];
        redirect(root . admin . '/blogs');
        return;
    }
    
    $post_title = trim($_POST['post_title'] ?? '');
    $post_desc = trim($_POST['post_desc'] ?? '');
    $post_category = intval($_POST['post_category'] ?? 0);
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;
    $published_at = !empty($_POST['published_at']) ? date('Y-m-d H:i:s', strtotime($_POST['published_at'])) : date('Y-m-d H:i:s');
    
    $errors = [];
    if (empty($post_title)) $errors[] = T::blog_title_required ?? 'Blog title is required';
    if (empty($post_desc)) $errors[] = T::blog_description_required ?? 'Blog description is required';
    
    if (!empty($errors)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => implode('<br>', $errors)];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/blogs/edit/' . $blog_id);
        return;
    }
    
    // Generate slug if title changed or if the current slug is empty/invalid
    $post_slug = $blog['post_slug'];
    if ($post_title !== $blog['post_title'] || empty($post_slug) || preg_match('/^-+$/', $post_slug)) {
        $post_slug = generateUniqueSlug($post_title, 'blogs', 'post_slug', $db, $blog_id);
    }
    
    // Handle featured image upload
    $post_img = $blog['post_img'];
    $delete_old_image = isset($_POST['delete_old_image']) ? 1 : 0;
    
    if ($delete_old_image && !empty($post_img)) {
        $project_root = realpath(__DIR__ . '/../../../');
        $clean_url = ltrim($post_img, '/');
        $file_path = $project_root . '/' . $clean_url;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        $post_img = '';
    }
    
    if (isset($_FILES['post_img']) && $_FILES['post_img']['error'] === UPLOAD_ERR_OK) {
        // Delete old image if exists
        if (!empty($blog['post_img'])) {
            $project_root = realpath(__DIR__ . '/../../../');
            $clean_url = ltrim($blog['post_img'], '/');
            $file_path = $project_root . '/' . $clean_url;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
        
        $project_root = realpath(__DIR__ . '/../../../');
        $upload_dir = $project_root . '/uploads/blogs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $file = $_FILES['post_img'];
        // SECURITY: validate real MIME + safe extension (finfo).
        $chk = secureUploadCheck($file, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 5 * 1024 * 1024);
        if ($chk['ok']) {
            $new_filename = 'blog_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $chk['ext'];
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                @chmod($upload_path, 0644);
                $post_img = '/uploads/blogs/' . $new_filename;
            }
        }
    }
    
    // Handle translations
    $translations = [];
    $title_translations = $_POST['title_translations'] ?? [];
    $desc_translations = $_POST['desc_translations'] ?? [];
    
    $all_lang_codes = array_unique(array_merge(
        array_keys($title_translations),
        array_keys($desc_translations)
    ));
    
    foreach ($all_lang_codes as $lang_code) {
        $lang_data = [];
        if (!empty(trim($title_translations[$lang_code] ?? ''))) $lang_data['title'] = trim($title_translations[$lang_code]);
        if (!empty(trim($desc_translations[$lang_code] ?? ''))) $lang_data['desc'] = trim($desc_translations[$lang_code]);
        if (!empty($lang_data)) $translations[$lang_code] = $lang_data;
    }
    
    $blog_data = [
        'post_title' => $post_title,
        'post_slug' => $post_slug,
        'post_desc' => $post_desc,
        'post_category' => $post_category > 0 ? $post_category : null,
        'post_img' => !empty($post_img) ? $post_img : null,
        'meta_title' => !empty($meta_title) ? $meta_title : null,
        'meta_description' => !empty($meta_description) ? $meta_description : null,
        'meta_keywords' => !empty($meta_keywords) ? $meta_keywords : null,
        'featured' => $featured,
        'status' => $status,
        'published_at' => $published_at,
        'translations' => !empty($translations) ? json_encode($translations) : null,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->update('blogs', $blog_data, ['id' => $blog_id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => T::blog_updated_successfully ?? 'Blog updated successfully'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    $active_tab = trim($_POST['active_tab'] ?? 'general');
    redirect(root . admin . '/blogs/edit/' . $blog_id . '#' . $active_tab);
});

// ================================ POST /blogs/delete - DELETE BLOG
$router->post(admin.'/blogs/delete', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $blog_id = intval($_POST['id'] ?? 0);
    
    if ($blog_id <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::invalid_blog_id ?? 'Invalid blog ID'];
        redirect(root . admin . '/blogs');
        return;
    }
    
    $blog = $db->get('blogs', ['id', 'post_img'], ['id' => $blog_id]);
    if (!$blog) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::blog_not_found ?? 'Blog not found'];
        redirect(root . admin . '/blogs');
        return;
    }
    
    try {
        // Delete featured image if exists
        if (!empty($blog['post_img'])) {
            $project_root = realpath(__DIR__ . '/../../../');
            $clean_url = ltrim($blog['post_img'], '/');
            $file_path = $project_root . '/' . $clean_url;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
        
        $result = $db->delete('blogs', ['id' => $blog_id]);
        if ($result) {
            $_SESSION['message'] = ['type' => 'success', 'text' => T::deleted_successfully ?? 'Deleted successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => T::failed_to_delete_blog ?? 'Failed to delete blog'];
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => T::database_error . ': ' . $e->getMessage()];
    }
    
    redirect(root . admin . '/blogs');
});

/*===================================================================
BLOGS ROUTES END
===================================================================*/

/*===================================================================
BLOG CATEGORIES ROUTES START
===================================================================*/

// ================================ GET /blogs/categories - LIST CATEGORIES
$router->get(admin.'/blogs/categories', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    $title = T::blog_categories_management ?? 'Blog Categories Management';
    $description = '';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/blogs/categories.php";
    require_once views."includes/footer.php";
});

// ================================ GET /blogs/categories/get/{id} - GET CATEGORY DATA (AJAX)
$router->get(admin.'/blogs/categories/get/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    $category = $db->get('blog_categories', '*', ['id' => intval($id)]);
    if ($category) {
        echo json_encode(['success' => true, 'data' => $category]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
    }
});

// ================================ POST /blogs/categories/add - ADD CATEGORY (AJAX)
$router->post(admin.'/blogs/categories/add', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();

    $cat_name = trim($_POST['cat_name'] ?? '');
    $cat_slug = trim($_POST['cat_slug'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (empty($cat_name)) {
        echo json_encode(['success' => false, 'message' => T::category_name_required ?? 'Category name is required']);
        return;
    }
    
    $cat_slug = generateUniqueSlug(empty($cat_slug) ? $cat_name : $cat_slug, 'blog_categories', 'cat_slug', $db);
    
    $category_data = [
        'cat_name' => $cat_name,
        'cat_slug' => $cat_slug,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->insert('blog_categories', $category_data);
        if ($result) {
            echo json_encode(['success' => true, 'message' => T::category_added_successfully ?? 'Category added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => T::failed_to_add_category ?? 'Failed to add category']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::database_error . ': ' . $e->getMessage()]);
    }
});

// ================================ POST /blogs/categories/update/{id} - UPDATE CATEGORY (AJAX)
$router->post(admin.'/blogs/categories/update/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $cat_id = intval($id);
    
    $cat_name = trim($_POST['cat_name'] ?? '');
    $cat_slug = trim($_POST['cat_slug'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (empty($cat_name)) {
        echo json_encode(['success' => false, 'message' => T::category_name_required ?? 'Category name is required']);
        return;
    }
    
    $cat_slug = generateUniqueSlug(empty($cat_slug) ? $cat_name : $cat_slug, 'blog_categories', 'cat_slug', $db, $cat_id);
    
    $category_data = [
        'cat_name' => $cat_name,
        'cat_slug' => $cat_slug,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $result = $db->update('blog_categories', $category_data, ['id' => $cat_id]);
        if ($result) {
            echo json_encode(['success' => true, 'message' => T::category_updated_successfully ?? 'Category updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => T::failed_to_update_category ?? 'Failed to update category']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::database_error . ': ' . $e->getMessage()]);
    }
});

// ================================ POST /blogs/categories/delete/{id} - DELETE CATEGORY (AJAX)
$router->post(admin.'/blogs/categories/delete/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    $cat_id = intval($id);
    
    if ($cat_id <= 0) {
        echo json_encode(['success' => false, 'message' => T::invalid_category_id ?? 'Invalid category ID']);
        return;
    }
    
    // Check if category has blogs
    $blog_count = $db->count('blogs', ['post_category' => $cat_id]);
    if ($blog_count > 0) {
        echo json_encode(['success' => false, 'message' => T::cannot_delete_category_with_blogs ?? 'Cannot delete category that has blogs. Please reassign or delete blogs first.']);
        return;
    }
    
    try {
        $result = $db->delete('blog_categories', ['id' => $cat_id]);
        if ($result) {
            echo json_encode(['success' => true, 'message' => T::deleted_successfully ?? 'Deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => T::failed_to_delete_category ?? 'Failed to delete category']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::database_error . ': ' . $e->getMessage()]);
    }
});

/*===================================================================
BLOG CATEGORIES ROUTES END
===================================================================*/