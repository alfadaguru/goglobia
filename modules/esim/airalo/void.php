<?php
/**
 * AIRALO eSIM VOID HANDLER
 * Endpoint: POST /esim/airalo/void (via modules API gateway)
 *
 * An eSIM has no separate "void" operation distinct from cancellation, and
 * Airalo has no programmatic cancel/void API for an issued order. This endpoint
 * exists so the admin lifecycle surface is uniform; it delegates to the same
 * logic as /esim/airalo/cancel (which safely releases a not-yet-issued booking
 * and records a manual-support request for an issued one). Previously an admin
 * "Void" click hit a non-existent route (404).
 */

@$SECURE or die('Access Denied!');

require_once __DIR__ . '/cancel.php'; // defines airaloHandleCancel()

global $router;
if (isset($router) && is_object($router) && method_exists($router, 'post')) {
    $router->post('esim/airalo/void', function () use ($db) {
        if (function_exists('airaloHandleCancel')) {
            airaloHandleCancel($db);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => false, 'success' => false, 'message' => 'Void handler unavailable'], JSON_UNESCAPED_SLASHES);
    });
}
