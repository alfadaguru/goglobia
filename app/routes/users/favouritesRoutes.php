<?php
// FILE: app/routes/users/favourites.php
// User favourites route

@$SECURE or die('Access Denied!');

// ====================================
// FAVOURITES ROUTES
// ====================================

$router->get('/favourites', function () use ($SECURE,$db) {

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // CHECK AGENT AND REDRECTION IF NOT COMPLETED
    CHECK_AGENT($db);

    // Get user data and filter
    $user_id = $_SESSION['user_id'];

    // Get user data for sidebar
    $user = $db->get('favourites', '*', ['user_id' => $user_id]);

    // META DATA
    $title = "My Favourites";
    $description = "User favourites";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."auth/favourites.php";
    require_once views."includes/footer.php";

});
