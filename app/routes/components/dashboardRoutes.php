<?php
// FILE: app/routes/components/dashboard.php
// Components library dashboard and main redirect

@$SECURE or die('Access Denied!');

// ====================================
// MAIN COMPONENTS REDIRECT
// ====================================

$router->get('/components', function () use ($SECURE, $db) {
    header('Location: ' . root . 'components/dashboard');
    exit();
});

// ====================================
// COMPONENTS DASHBOARD
// ====================================

$router->get('/components/dashboard', function () use ($SECURE, $db) {
    $title = "Component Dashboard";
    $description = "Component library dashboard";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/dashboard.php";
    require_once views."includes/footer.php";
});
