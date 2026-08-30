<?php
// ============================================================================
// ADMIN REPORTS ROUTES
// ============================================================================
@$SECURE or die('Access Denied!');

// Booking Logs
$router->get(admin.'/reports/booking-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Booking Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all booking logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/booking-logs.php";
    require_once views."includes/footer.php";
});

// Search Logs
$router->get(admin.'/reports/search-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Search Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all search logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/search-logs.php";
    require_once views."includes/footer.php";
});

// Webhook Logs
$router->get(admin.'/reports/webhook-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Webhook Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all webhook execution logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/webhook-logs.php";
    require_once views."includes/footer.php";
});

// Booking Reports
$router->get(admin.'/reports/bookings(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Booking Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View booking analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/bookings.php";
    require_once views."includes/footer.php";
});

// Users Reports
$router->get(admin.'/reports/users(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Users Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View user analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/users.php";
    require_once views."includes/footer.php";
});

// transactions Reports
$router->get(admin.'/reports/transactions(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Transactions Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View transaction analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/transactions-logs.php";
    require_once views."includes/footer.php";
});

// Deposit Reports
$router->get(admin.'/reports/deposit(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Deposit Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View deposit analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/deposit.php";
    require_once views."includes/footer.php";
});
