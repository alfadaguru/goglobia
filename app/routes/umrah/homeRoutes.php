<?php
// app/routes/umrah/homeRoutes.php

$router->get('/umrah/', function () use ($SECURE,$db) {
    $title = T::umrah ?? 'Umrah';
    $description = '';
    require_once views."includes/header.php";
    require_once views."modules/umrah/index.php";
    require_once views."includes/footer.php";
});
