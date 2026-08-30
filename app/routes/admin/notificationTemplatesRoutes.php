<?php
// app/routes/admin/notificationRoutes.php
@$SECURE or die('Access Denied!');

// ================================ NOTIFICATION TEMPLATES LIST
$router->get(admin.'/notification-templates', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Notification Templates';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/notification-templates/templates.php";
    require_once views."includes/footer.php";

});

// ================================ NOTIFICATION TEMPLATE EDIT
$router->get(admin.'/notification-templates/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit Notification Template';
    $description = '';
    $header = true;
    $footer = true;

    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/notification-templates/templates-manage.php";
    require_once views."includes/footer.php";

});

// ================================ NOTIFICATION TEMPLATE MANAGE - POST (Update)
$router->post(admin.'/notification-templates/manage', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // CSRF Token Validation
    CSRF::verifyRequest();

    try {
        $id = (int)$_POST['id'];
        $templateType = $_POST['type'] ?? 'email'; 

        if (empty($_POST['subject'])) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Subject is required'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/notification-templates');
        }

        $data = [
            'subject' => trim($_POST['subject']),
            'body' => $_POST['body'] ?? '',
            'status' => isset($_POST['status']) ? (int)$_POST['status'] : 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $template = $db->get('notification_templates', '*', ['id' => $id]);
        if (!$template) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Template not found'
            ];
            redirect(root . admin . '/notification-templates');
        }

        $db->update('notification_templates', $data, ['id' => $id]);
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Template updated successfully'
        ];

        // Redirect to same tab with hash
        $redirectUrl = root . admin . '/notification-templates#' . $templateType;
        redirect($redirectUrl);

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/notification-templates');
    }
});

// ================================ NOTIFICATION TEMPLATE STATUS TOGGLE
$router->post(admin.'/notification-templates/toggle-status', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // CSRF Token Validation
    CSRF::verifyRequest();

    try {
        if (empty($_POST['id'])) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Template ID is required'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/notification-templates');
        }

        $id = (int)$_POST['id'];
        $newStatus = isset($_POST['status']) ? (int)$_POST['status'] : 0;

        $template = $db->get('notification_templates', '*', ['id' => $id]);
        if (!$template) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Template not found'
            ];
            redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/notification-templates');
        }

        $db->update('notification_templates', [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Template ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully'
        ];

        // Redirect to same tab with hash
        $templateType = $template['type'];
        $redirectUrl = root . admin . '/notification-templates#' . $templateType;
        redirect($redirectUrl);

    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => $e->getMessage()
        ];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/notification-templates');
    }
});