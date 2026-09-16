<?php
// app/routes/admin/cmsMenuRoutes.php
@$SECURE or die('Access Denied!');

// ============================================
// HELPER FUNCTION: Get page name (no translation)
// ============================================
if (!function_exists('getTranslatedPageName')) {
    function getTranslatedPageName($item, $lang = null) {
        return $item['page_name'];
    }
}

// ================================ GET /cms/menus
$router->get(admin.'/cms/menus', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    
    $all_pages = $db->select('cms', '*', [
        'ORDER' => ['order' => 'ASC', 'page_name' => 'ASC']
    ]);
    
    // Renamed variables to avoid conflict with header.php/footer.php
    $cms_header_menu = $db->select('cms', '*', [
        'position' => 'header',
        'status' => '1',
        'ORDER' => ['order' => 'ASC', 'page_name' => 'ASC']
    ]);
    
    $cms_footer_menu = $db->select('cms', '*', [
        'position' => 'footer',
        'status' => '1',
        'ORDER' => ['order' => 'ASC', 'page_name' => 'ASC']
    ]);
    
    $cms_headerfooter_menu = $db->select('cms', '*', [
        'position' => 'headerfooter',
        'status' => '1',
        'ORDER' => ['order' => 'ASC', 'page_name' => 'ASC']
    ]);
    
    $available_pages = $db->select('cms', '*', [
        'OR' => [
            'status' => '0',
            'position' => '',
            'position' => null
        ],
        'ORDER' => ['page_name' => 'ASC']
    ]); 
    
    $title = T::menus_management ?? 'Menus Management';
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/cms/menus.php";
    require_once views."includes/footer.php";
});

// ================================ POST /cms/menus/add-to-menu
$router->post(admin.'/cms/menus/add-to-menu', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $page_id = $_POST['page_id'] ?? 0;
    $position = $_POST['position'] ?? 'header';
    
    try {
        $max_order = $db->max('cms', 'order', ['position' => $position]) ?? 0;
        
        $db->update('cms', [
            'position' => $position,
            'order' => $max_order + 1,
            'parent_id' => 0,
            'status' => '1'
        ], ['id' => $page_id]);
        
        echo json_encode(['success' => true, 'message' => T::page_added_to_menu ?? 'Page added to menu']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_add_to_menu ?? 'Failed to add page to menu']);
    }
});

// ================================ POST /cms/menus/move-menu-item
$router->post(admin.'/cms/menus/move-menu-item', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    
    $page_id = $_POST['page_id'] ?? 0;
    $position = $_POST['position'] ?? 'header';
    
    try {
        $max_order = $db->max('cms', 'order', ['position' => $position]) ?? 0;
        
        $db->update('cms', [
            'position' => $position,
            'order' => $max_order + 1,
            'parent_id' => 0
        ], ['id' => $page_id]);
        
        echo json_encode(['success' => true, 'message' => T::page_moved_to_menu ?? 'Page moved to menu']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_move_menu ?? 'Failed to move page']);
    }
});

// ================================ POST /cms/menus/remove-from-menu
$router->post(admin.'/cms/menus/remove-from-menu', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $page_id = $_POST['page_id'] ?? 0;
    
    try {
        $db->update('cms', [
            'position' => '',
            'order' => NULL,
            'parent_id' => 0
        ], ['id' => $page_id]);
        
        echo json_encode(['success' => true, 'message' => T::page_removed_from_menu ?? 'Page removed from menu']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_remove_from_menu ?? 'Failed to remove from menu']);
    }
});

// ================================ POST /cms/menus/create-menu-item
$router->post(admin.'/cms/menus/create-menu-item', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $position = $_POST['position'] ?? 'header';
    $target = $_POST['target'] ?? '_self';
    $link_type = $_POST['link_type'] ?? 'menu_item';
    $parent_id = intval($_POST['parent_id'] ?? 0);
    
    if (empty($title) || empty($url)) {
        echo json_encode(['success' => false, 'message' => T::title_url_required ?? 'Title and URL are required']);
        return;
    }
    
    try {
        $max_order = 0;
        if ($parent_id > 0) {
            $max_order_result = $db->max('cms', 'order', [
                'position' => $position,
                'parent_id' => $parent_id
            ]);
            $max_order = $max_order_result !== null ? (int)$max_order_result : 0;
        } else {
            $max_order_result = $db->max('cms', 'order', [
                'position' => $position,
                'OR' => [
                    'parent_id' => 0,
                    'parent_id' => null
                ]
            ]);
            $max_order = $max_order_result !== null ? (int)$max_order_result : 0;
        }
        
        $data = [
            'page_name' => $title,
            'slug_url' => $url,
            'position' => $position,
            'order' => $max_order + 1,
            'parent_id' => $parent_id,
            'status' => '1',
            'removable' => '1',
            'target' => $target,
            'link_type' => $link_type,
            'external_url' => $url
        ];
        
        $db->insert('cms', $data);
        
        echo json_encode(['success' => true, 'message' => T::menu_item_added ?? 'Menu item added successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_add_menu_item ?? 'Failed to add menu item: ' . $e->getMessage()]);
    }
});

// Backward compatibility route
$router->post(admin.'/cms/menus/create-custom-link', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $position = $_POST['position'] ?? 'header';
    $target = $_POST['target'] ?? '_self';
    $link_type = $_POST['link_type'] ?? 'menu_item';
    $parent_id = intval($_POST['parent_id'] ?? 0);
    
    if (empty($title) || empty($url)) {
        echo json_encode(['success' => false, 'message' => T::title_url_required ?? 'Title and URL are required']);
        return;
    }
    
    try {
        $max_order = 0;
        if ($parent_id > 0) {
            $max_order_result = $db->max('cms', 'order', [
                'position' => $position,
                'parent_id' => $parent_id
            ]);
            $max_order = $max_order_result !== null ? (int)$max_order_result : 0;
        } else {
            $max_order_result = $db->max('cms', 'order', [
                'position' => $position,
                'OR' => [
                    'parent_id' => 0,
                    'parent_id' => null
                ]
            ]);
            $max_order = $max_order_result !== null ? (int)$max_order_result : 0;
        }
        
        $data = [
            'page_name' => $title,
            'slug_url' => $url,
            'position' => $position,
            'order' => $max_order + 1,
            'parent_id' => $parent_id,
            'status' => '1',
            'removable' => '1',
            'target' => $target,
            'link_type' => $link_type,
            'external_url' => $url
        ];
        
        $db->insert('cms', $data);
        
        echo json_encode(['success' => true, 'message' => T::menu_item_added ?? 'Menu item added successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_add_menu_item ?? 'Failed to add menu item: ' . $e->getMessage()]);
    }
});

// ================================ GET /cms/menus/get-item/:id
$router->get(admin.'/cms/menus/get-item/(\d+)', function ($id) use ($SECURE,$db) {
    ADMIN_AUTH();
    
    try {
        $item = $db->get('cms', '*', ['id' => $id]);
        
        if ($item) {
            echo json_encode(['success' => true, 'data' => $item]);
        } else {
            echo json_encode(['success' => false, 'message' => T::menu_item_not_found ?? 'Menu item not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => T::failed_to_load_menu_item ?? 'Failed to load menu item: ' . $e->getMessage()]);
    }
});

// ================================ POST /cms/menus/update-item
$router->post(admin.'/cms/menus/update-item', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $id = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $position = $_POST['position'] ?? '';
    $target = $_POST['target'] ?? '_self';
    $link_type = $_POST['link_type'] ?? 'page';
    $parent_id = intval($_POST['parent_id'] ?? 0);
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => T::id_required ?? 'ID is required']);
        return;
    }
    
    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => T::title_required ?? 'Title is required']);
        return;
    }
    
    if (empty($url)) {
        echo json_encode(['success' => false, 'message' => T::url_required ?? 'URL is required']);
        return;
    }
    
    try {
        $existing = $db->get('cms', '*', ['id' => $id]);
        
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => T::menu_item_not_found ?? 'Menu item not found']);
            return;
        }
        
        $data = [
            'page_name' => $title,
            'slug_url' => $url,
            'target' => $target,
            'parent_id' => $parent_id,
            'link_type' => $link_type
        ];
        
        if (!empty($position)) {
            $data['position'] = $position;
        }
        
        if (in_array($link_type, ['external', 'custom', 'menu_item'])) {
            $data['external_url'] = $url;
        } else {
            $data['external_url'] = '';
        }
        
        $db->update('cms', $data, ['id' => $id]);
        
        echo json_encode([
            'success' => true, 
            'message' => T::menu_item_updated ?? 'Menu item updated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log('Menu update error: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => T::failed_to_update_menu_item ?? 'Failed to update menu item: ' . $e->getMessage()
        ]);
    }
});

// ================================ POST /cms/menus/delete-item
$router->post(admin.'/cms/menus/delete-item', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $id = intval($_POST['id'] ?? 0);
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => T::id_required ?? 'ID is required']);
        return;
    }
    
    try {
        $item = $db->get('cms', ['id', 'removable', 'page_name'], ['id' => $id]);
        
        if (!$item) {
            echo json_encode(['success' => false, 'message' => T::menu_item_not_found ?? 'Menu item not found']);
            return;
        }
        
        if (isset($item['removable']) && $item['removable'] == '0') {
            echo json_encode(['success' => false, 'message' => T::menu_item_not_removable ?? 'This menu item cannot be removed']);
            return;
        }
        
        $childIds = getChildMenuItems($db, $id);
        $allIds = array_merge([$id], $childIds);
        
        foreach ($allIds as $itemId) {
            $db->update('cms', ['status' => '0'], ['id' => $itemId]);
        }
        
        $childCount = count($childIds);
        $message = T::menu_item_disabled ?? 'Menu item disabled successfully';
        if ($childCount > 0) {
            $message .= " ({$childCount} " . (T::child_items_also_disabled ?? 'child items also disabled') . ")";
        }
        
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        error_log('Menu delete error: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => T::failed_to_delete_menu_item ?? 'Failed to delete menu item: ' . $e->getMessage()
        ]);
    }
});

// ================================ POST /cms/menus/restore-item
$router->post(admin.'/cms/menus/restore-item', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $id = intval($_POST['id'] ?? 0);
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => T::id_required ?? 'ID is required']);
        return;
    }
    
    try {
        $item = $db->get('cms', ['id', 'page_name'], ['id' => $id]);
        
        if (!$item) {
            echo json_encode(['success' => false, 'message' => T::menu_item_not_found ?? 'Menu item not found']);
            return;
        }
        
        $db->update('cms', ['status' => '1'], ['id' => $id]);
        
        echo json_encode([
            'success' => true, 
            'message' => T::menu_item_restored ?? 'Menu item restored successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => T::failed_to_restore_menu_item ?? 'Failed to restore menu item: ' . $e->getMessage()
        ]);
    }
});

// Helper function
if (!function_exists('getChildMenuItems')) {
    function getChildMenuItems($db, $parentId) {
        $childIds = [];
        $children = $db->select('cms', 'id', ['parent_id' => $parentId]);
        
        if (is_array($children)) {
            foreach ($children as $childId) {
                $id = is_array($childId) ? $childId['id'] : $childId;
                $childIds[] = $id;
                $grandChildren = getChildMenuItems($db, $id);
                $childIds = array_merge($childIds, $grandChildren);
            }
        }
        
        return $childIds;
    }
}

// ================================ POST /cms/menus/update-structure
$router->post(admin.'/cms/menus/update-structure', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    
    $menu_data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($menu_data)) {
        echo json_encode(['success' => false, 'message' => T::invalid_menu_data ?? 'Invalid menu data']);
        return;
    }
    
    try {
        $db->pdo->beginTransaction();
        
        foreach ($menu_data as $item) {
            $updateData = [
                'parent_id' => $item['parent_id'] ?? 0,
                'order' => $item['order_index'] ?? 0,
                'position' => $item['position'] ?? 'header'
            ];
            
            $db->update('cms', $updateData, ['id' => $item['id']]);
        }
        
        $db->pdo->commit();
        
        echo json_encode(['success' => true, 'message' => T::menu_structure_updated ?? 'Menu structure updated successfully']);
    } catch (Exception $e) {
        $db->pdo->rollBack();
        error_log('Menu structure update error: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => T::failed_to_update_menu ?? 'Failed to update menu structure: ' . $e->getMessage()
        ]);
    }
});

// ================================ GET /cms/menus/disabled-items
$router->get(admin.'/cms/menus/disabled-items', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    
    try {
        $disabled_items = $db->select('cms', '*', [
            'status' => '0',
            'ORDER' => ['page_name' => 'ASC']
        ]);
        
        echo json_encode([
            'success' => true, 
            'data' => $disabled_items ?? [],
            'count' => count($disabled_items ?? [])
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to load disabled items: ' . $e->getMessage()
        ]);
    }
});