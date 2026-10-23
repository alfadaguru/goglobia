<?php
// app/routes/mainRoutes.php
@$SECURE or die('Access Denied!');

$router->get('/', function () use ($SECURE,$db) {

// META DATA (SEO home title/description). Honour an admin-customised home_title;
// only upgrade the bare default to a descriptive, keyword-rich home title.
$__homeTitle = trim((string) ($GLOBALS['app']['home_title'] ?? ''));
$title = ($__homeTitle === '' || strcasecmp($__homeTitle, 'GoGlobia') === 0 || strcasecmp($__homeTitle, 'Goglobia') === 0)
    ? 'GoGlobia | Flights, Stays, Umrah & AI Travel Booking in Nigeria'
    : $__homeTitle;
$description = $GLOBALS['app']['meta_description'] ?? '';
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."home.php";
require_once views."includes/footer.php";

});