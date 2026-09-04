<?php
// ============================================================================
// TRAIN BOOKING (RAIL) — MODULE GATEWAY ROUTING
// ============================================================================
// Registers router endpoints on the modules API gateway ($router).
// Proxies incoming requests directly to the Train Booking API using credentials.
// ============================================================================

@$SECURE or die('Access Denied!');

require_once __DIR__ . '/search.php';

global $router;

// ----------------------------------------------------------------------------
// SEARCH SCHEDULES
// ----------------------------------------------------------------------------
$router->post('rail/train/trainQuery', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/trainQuery', $body, ['db' => $db, 'retries' => 2]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// INTERMEDIATE STOPS
// ----------------------------------------------------------------------------
$router->post('rail/train/trainWayQuery', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/trainWayQuery', $body, ['db' => $db, 'retries' => 2]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// CREATE BOOKING (ORDER)
// ----------------------------------------------------------------------------
$router->post('rail/train/order', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $body = _train_normalize_order_payload(is_array($body) ? $body : [], $db);
        $res  = _train_request('/ticket/order', $body, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// POLL ORDER STATUS
// ----------------------------------------------------------------------------
$router->post('rail/train/orderResultData', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        // Supplier docs: poll by supplier main_order_id (MN...), NOT cus_main_order_id.
        $mainOrderId = trim((string)($body['main_order_id'] ?? ''));
        if ($mainOrderId === '') {
            http_response_code(422);
            echo json_encode(['code' => 422, 'msg' => 'main_order_id is required', 'data' => null]);
            return;
        }
        $res = _train_request('/ticket/orderResultData', ['main_order_id' => $mainOrderId], ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// CANCEL ORDER
// ----------------------------------------------------------------------------
$router->post('rail/train/orderCancel', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($body['main_order_id']) && !empty($body['cus_main_order_id'])) {
            $body['main_order_id'] = $body['cus_main_order_id'];
        }
        if (empty($body['cus_main_order_id']) && !empty($body['main_order_id'])) {
            $body['cus_main_order_id'] = $body['main_order_id'];
        }
        $res  = _train_request('/ticket/orderCancel', $body, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// REQUEST RESCHEDULE
// ----------------------------------------------------------------------------
$router->post('rail/train/orderChange', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/orderChange', $body, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// CHECK RESCHEDULE STATUS
// ----------------------------------------------------------------------------
$router->post('rail/train/changeResultData', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/changeResultData', $body, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// REQUEST REFUND
// ----------------------------------------------------------------------------
$router->post('rail/train/orderRefund', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/orderRefund', $body, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// CHECK REFUND STATUS
// ----------------------------------------------------------------------------
$router->post('rail/train/refundResultData', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res  = _train_request('/ticket/refundResultData', $body, ['db' => $db]);

        // When the supplier CONFIRMS the ticket refund, reverse the customer's
        // charge via the payment gateway (rail refunds are async — this is the
        // confirmation point, not the request point in actions/refund.php). Only
        // acts on a clearly-successful refund result and an identifiable booking.
        $resData = json_decode((string) ($res['raw'] ?? ''), true);
        $refundConfirmed = is_array($resData)
            && (int) ($resData['code'] ?? -1) === 0
            && (
                stripos(json_encode($resData['data'] ?? []), 'refund_success') !== false
                || (($resData['data']['refund_status'] ?? '') === 'success')
                || (($resData['data']['status'] ?? '') === 'refunded')
            );
        $invoiceForRefund = $body['invoice_id'] ?? ($resData['data']['invoice_id'] ?? '');
        if ($refundConfirmed && $invoiceForRefund !== '') {
            $rbk = $db->get('bookings', '*', ['invoice_id' => $invoiceForRefund]);
            if ($rbk && ($rbk['payment_status'] ?? '') !== 'refunded') {
                require_once dirname(__DIR__, 3) . '/app/lib/payment-gateway.php';
                $railGwRefund = function_exists('refund_gateway_payment')
                    ? refund_gateway_payment($db, $rbk, null, 'Rail ticket refund')
                    : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'payment_status' => ($railGwRefund['status'] === 'refunded') ? 'refunded' : ($rbk['payment_status'] ?? 'paid'),
                    'cancellation_status' => 1,
                    'cancellation_response' => json_encode(['supplier' => $resData['data'] ?? null, 'gateway_refund' => $railGwRefund]),
                ], ['invoice_id' => $invoiceForRefund]);
            }
        }

        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
});

// ----------------------------------------------------------------------------
// BOOKING LIFECYCLE ACTIONS — issue / cancel / refund
// ----------------------------------------------------------------------------
include "actions/issue.php";
include "actions/cancel.php";
include "actions/refund.php";

// ----------------------------------------------------------------------------
// TEST API CREDENTIALS (USED BY ADMIN CONFIGURATION SCREEN)
// ----------------------------------------------------------------------------
$router->post('rail/train/creds', function () use ($db) {
    $start = microtime(true);
    header('Content-Type: application/json; charset=utf-8');

    // RESPONSE SKELETON
    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'          => 'train',
            'service'         => 'rail',
            'provider'        => 'Train Booking API',
            'api_version'     => 'v2',
            'timestamp'       => date('c'),
            'response_time_ms'=> 0,
            'environment'     => 'sandbox',
        ],
        'debug' => [
            'validation_steps' => [],
            'endpoint_used'    => null,
            'api_response'     => null,
        ],
    ];

    try {
        $response['debug']['validation_steps'][] = 'Loading Train module config';

        // Read input credentials from POST
        $apiKey = trim($_POST['c1'] ?? '');
        $baseUrl = trim($_POST['c2'] ?? '');
        $devMode = ($_POST['dev_mode'] ?? '0') == '1';

        $response['metadata']['environment'] = $devMode ? 'development' : 'production';

        if ($apiKey === '' || $baseUrl === '') {
            throw new Exception('API Key (c1) and Base URL (c2) are required. Please fill them in.');
        }

        $endpointUrl = rtrim($baseUrl, '/') . '/ticket/trainQuery';
        $response['debug']['endpoint_used'] = $endpointUrl;
        $response['debug']['validation_steps'][] = 'Calling POST /ticket/trainQuery to validate API Key';

        // Dummy payload for testing connection
        $payload = [
            'from_station_code' => 'IDPGA',
            'to_station_code' => 'IDTLA',
            'from_date' => time() + 7 * 86400, // 7 days from now
            'journey_type' => 3
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpointUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apiKey: ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $response['debug']['api_response'] = $raw;

        if ($err) {
            throw new Exception('Connection failed: ' . $err);
        }

        $decoded = json_decode($raw, true);
        if ($status === 200 && isset($decoded['code']) && ($decoded['code'] == 200 || $decoded['code'] == '200')) {
            $response['success'] = true;
            $response['message'] = 'Train Booking API credentials validated successfully.';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'token_type' => 'apiKey',
                    'token_preview' => substr($apiKey, 0, 4) . '...',
                ],
                'api_details' => [
                    'provider' => 'Train Booking API',
                    'api_version' => 'v2',
                    'endpoint' => $baseUrl,
                    'environment' => $devMode ? 'development' : 'production',
                    'supported_services' => [
                        'trainQuery',
                        'trainWayQuery',
                        'order',
                        'orderResultData',
                        'orderCancel',
                        'orderChange',
                        'changeResultData',
                        'orderRefund',
                        'refundResultData'
                    ]
                ]
            ];
            $response['debug']['validation_steps'][] = 'API key validated successfully';
        } else {
            $response['message'] = 'Train API authentication failed: ' . ($decoded['msg'] ?? 'Endpoint returned status code ' . $status);
            $response['data'] = [
                'error_type' => 'authentication_error',
                'http_status' => $status,
                'error' => $decoded['msg'] ?? 'Unable to authenticate',
                'response' => $decoded,
            ];
            $response['debug']['validation_steps'][] = 'Authentication failed';
        }

    } catch (Throwable $e) {
        $response['message'] = $e->getMessage();
        $response['debug']['validation_steps'][] = 'Exception: ' . $e->getMessage();
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
