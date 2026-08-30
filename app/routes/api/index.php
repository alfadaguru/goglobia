<?php

// FILE: app/routes/api/index.php
// All API routes

@$SECURE or die('Access Denied!');

// ====================================
// LOGIN ROUTES
// ====================================

// ======================== INDEX
$router->get('/api', function() use ($db) {

    header("Content-Type: application/json");
    header('Access-Control-Allow-Origin: *');

    $respose = array ( "status"=>"true", "message"=>"Welcome to API server","database" => $db );
    echo json_encode($respose);

});