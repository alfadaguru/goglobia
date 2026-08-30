<?php
// FILE: app/routes/components/form-inputs.php
// Form input component routes (text, textarea, checkbox, radio, switch)

@$SECURE or die('Access Denied!');

// ====================================
// INPUT COMPONENTS
// ====================================

$router->get('/components/input', function () use ($SECURE, $db) {
    $title = "Input Components";
    $description = "Input field components and variations";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/input.php";
    require_once views."includes/footer.php";
});

// ====================================
// TEXTAREA COMPONENTS
// ====================================

$router->get('/components/textarea', function () use ($SECURE, $db) {
    $title = "Textarea Components";
    $description = "Textarea field components with various features";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/textarea.php";
    require_once views."includes/footer.php";
});

// ====================================
// CHECKBOX COMPONENTS
// ====================================

$router->get('/components/checkbox', function () use ($SECURE, $db) {
    $title = "Checkbox Components";
    $description = "Checkbox field components with various styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/checkbox.php";
    require_once views."includes/footer.php";
});

// ====================================
// RADIO COMPONENTS
// ====================================

$router->get('/components/radio', function () use ($SECURE, $db) {
    $title = "Radio Components";
    $description = "Radio button components with various styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/radio.php";
    require_once views."includes/footer.php";
});

// ====================================
// SWITCH COMPONENTS
// ====================================

$router->get('/components/switch', function () use ($SECURE, $db) {
    $title = "Switch Components";
    $description = "Toggle switch components with various styles and states";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/switch.php";
    require_once views."includes/footer.php";
});
