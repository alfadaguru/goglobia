<?php
// ============================================================================
// FILE: app/routes/stays/home.php
// ============================================================================

// STAYS HOME PAGE
$router->get('/stays/', function () use ($SECURE,$db) {

    // META DATA (SEO: unique per-template title + description)
    $title = 'Hotels & Stays — Book Accommodation Worldwide';
    $description = 'Find and book hotels, apartments and stays in Nigeria and worldwide — Lagos, Abuja, Dubai, London and more. Compare real rates, see amenities and cancellation terms, and book securely with GoGlobia.';
    require_once views."includes/header.php";
    require_once views."modules/stays/index.php";
    require_once views."includes/footer.php";

});
