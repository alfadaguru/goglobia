<?php
// FILE: app/routes/components/form-selects.php
// Form select component routes (select, ajax-search, datepicker)

@$SECURE or die('Access Denied!');

// ====================================
// SELECT COMPONENTS
// ====================================

$router->get('/components/select', function () use ($SECURE, $db) {
    $title = "Select Components";
    $description = "Select field components and dropdown variations";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/select.php";
    require_once views."includes/footer.php";
});

// ====================================
// AJAX SEARCH COMPONENTS
// ====================================

$router->get('/components/ajax-search', function () use ($SECURE, $db) {
    $title = "AJAX Search Components";
    $description = "Searchable select components with AJAX data loading for flights and travel";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/ajax-search.php";
    require_once views."includes/footer.php";
});

// ====================================
// DATEPICKER COMPONENTS
// ====================================

$router->get('/components/datepicker', function () use ($SECURE, $db) {
    $title = "Datepicker Components";
    $description = "Interactive date picker components with single and range selection";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/datepicker.php";
    require_once views."includes/footer.php";
});
