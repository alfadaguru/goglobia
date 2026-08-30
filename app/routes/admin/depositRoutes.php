<?php
// app/routes/admin/depositRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
DEPOSIT ROUTES START
===================================================================*/

// ================================ GET /deposits - LIST ALL DEPOSITS
$router->get(admin.'/finance/deposit', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Deposits Management';

    require_once views."includes/header.php";
    require_once "app/views/admin/finance/deposit.php";
    require_once views."includes/footer.php";

});