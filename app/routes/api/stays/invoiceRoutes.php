<?php
// ============================================================================
// FILE: app/routes/api/stays/invoice.php
// STAYS INVOICE API 
// ============================================================================

@$SECURE or die('Access Denied!');

/*
|--------------------------------------------------------------------------
| PAYMENT GATEWAYS LIST 
|--------------------------------------------------------------------------
*/
$router->get('/api/payment/gateways', function () use ($db) {

    header('Content-Type: application/json');

    try {

        $baseUrl = rtrim(root, '/');

        $gateways = $db->select('payment_gateways', [
            'id',
            'name',
            'type'
        ], [
            'status' => 1,
            'active' => 1,
            'ORDER' => ['default' => 'DESC', 'name' => 'ASC']
        ]);

        $logoMap = [
            'stripe'         => 'stripe.png',
            'paypal'         => 'paypal.png',
            'razorpay'       => 'razorpay.png',
            'credit card'    => 'creditcard.png',
            'pay later'      => 'paylater.png',
            'bank transfer'  => 'bank-transfer.png',
            'wire transfer'  => 'bank-transfer.png',
            'wallet balance' => 'wallet-balance.png',
            'cashfree'       => 'cashfree.png',
            'coinsbuy'       => 'coinsbuy.png',
            'credits'        => 'credits.png',
            'fawaterak'      => 'fawaterak.png',
            'flutterwave'    => 'flutterwave.png',
            'mpesa'          => 'mpesa.png',
            'paystack'       => 'paystack.png',
            'xmoney'         => 'creditcard.png'
        ];

        $response = [];

        foreach ($gateways as $gateway) {

            // normalize gateway name
            $nameKey = strtolower(trim($gateway['name']));

            $logoFile = $logoMap[$nameKey] ?? 'creditcard.png'; 
            $logoUrl  = $baseUrl . '/uploads/gateways/' . $logoFile;

            $response[] = [
                'id'   => $gateway['id'],
                'name' => $gateway['name'],
                'type' => $gateway['type'],
                'logo' => $logoUrl
            ];
        }

        echo json_encode([
            'success' => true,
            'gateways' => $response
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to load payment gateways'
        ]);
    }
});
