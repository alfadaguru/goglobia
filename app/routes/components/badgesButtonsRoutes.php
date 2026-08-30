<?php
// FILE: app/routes/components/badges-buttons.php
// Badge, button, and progress component routes

@$SECURE or die('Access Denied!');

// ====================================
// BADGES COMPONENTS
// ====================================

$router->get('/components/badges', function () use ($SECURE, $db) {
    $title = "Badge Components";
    $description = "Label and badge components with various styles and states";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/badges.php";
    require_once views."includes/footer.php";
});

// ====================================
// BUTTONS COMPONENTS
// ====================================

$router->get('/components/buttons', function () use ($SECURE, $db) {
    $title = "Button Components";
    $description = "Button styles and variations";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/buttons.php";
    require_once views."includes/footer.php";
});

// ====================================
// PROGRESS COMPONENTS
// ====================================

$router->get('/components/progress', function () use ($SECURE, $db) {
    $title = "Progress Components";
    $description = "Progress bars, indicators, and loading components";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/progress.php";
    require_once views."includes/footer.php";
});
