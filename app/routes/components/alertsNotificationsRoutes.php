<?php
// FILE: app/routes/components/alerts-notifications.php
// Alert and notification component routes

@$SECURE or die('Access Denied!');

// ====================================
// ALERTS COMPONENTS
// ====================================

$router->get('/components/alerts', function () use ($SECURE, $db) {
    $title = "Alert Components";
    $description = "Alert message components and examples";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/alerts.php";
    require_once views."includes/footer.php";
});

// ====================================
// NOTIFICATIONS COMPONENTS
// ====================================

$router->get('/components/notifications', function () use ($SECURE, $db) {
    $title = "Notification Components";
    $description = "Toast notifications and alert system components";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/notifications.php";
    require_once views."includes/footer.php";
});
