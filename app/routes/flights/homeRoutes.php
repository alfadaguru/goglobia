<?php
// ============================================================================
// FILE: app/routes/flights/home.php
// ============================================================================

// FLIGHTS HOME PAGE
$router->get('/flights', function () use ($SECURE,$db) {

// META DATA (SEO: unique per-template title + description — header appends " | GoGlobia")
$title = 'Flights — Book Domestic & International Flights';
$description = 'Search and book cheap domestic and international flights from Nigeria — Lagos, Abuja, Kano and more. Compare live fares from trusted airlines, pay securely, and get 24/7 support with GoGlobia.';
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."modules/flights/index.php";
require_once views."includes/footer.php";

});
