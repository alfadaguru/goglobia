<?php
// FILE: app/routes/components/cards-ui.php
// Card and UI element component routes

@$SECURE or die('Access Denied!');

// ====================================
// CARDS COMPONENTS
// ====================================

$router->get('/components/cards', function () use ($SECURE, $db) {
    $title = "Card Components";
    $description = "Card layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/cards.php";
    require_once views."includes/footer.php";
});

// ====================================
// LINKS COMPONENTS
// ====================================

$router->get('/components/links', function () use ($SECURE, $db) {
    $title = "links Components";
    $description = "Links layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/links.php";
    require_once views."includes/footer.php";
});
