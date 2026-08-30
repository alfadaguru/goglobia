<?php

$router->post('flights/travelport/creds', function() {

    $start_time = microtime(true);
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'travelport',
            'service' => 'flights',
            'provider' => 'Travelport+ JSON API',
            'api_version' => 'v1',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'test'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'REST'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = 'Starting Travelport+ JSON (REST) credential validation';

        // Get JSON credentials from POST data
        $client_id = trim($_POST['c1'] ?? '');      // Client ID
        $client_secret = trim($_POST['c2'] ?? '');  // Client Secret
        $username = trim($_POST['c3'] ?? '');       // Username
        $password = trim($_POST['c4'] ?? '');       // Password
        $branch_id = trim($_POST['c5'] ?? '');      // pv3 (Branch ID)
        $pcc = trim($_POST['c6'] ?? '');            // PCC
        $environment = trim($_POST['env'] ?? 'test');

        if (empty($client_id) || empty($client_secret)) {
            throw new Exception('Missing required credentials: Client ID and Client Secret are mandatory.');
        }

        // Determine OAuth2 Token endpoint
        // Use the specific auth URL provided by the user
        $auth_url = ($environment === 'production') 
            ? 'https://auth.travelport.net/oauth/token' 
            : 'https://auth.pp.travelport.net/oauth/token';

        $response['debug']['endpoint_used'] = $auth_url;
        $response['debug']['validation_steps'][] = 'Environment: ' . $environment;
        $response['metadata']['environment'] = $environment;

        // Step 1: OAuth2 Token Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $auth_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        // Body parameters (Sending in body for compatibility like Amadeus module)
        $post_fields = [
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret
        ];

        // Some Travelport+ flows require username/password in token request body
        if (!empty($username)) $post_fields['username'] = $username;
        if (!empty($password)) $post_fields['password'] = $password;

        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
        
        // Authorization Header: Basic [base64(client_id:client_secret)]
        // We include both Body and Header for maximum compatibility across Travelport regions
        $auth_hash = base64_encode($client_id . ':' . $client_secret);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth_hash,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response['debug']['api_response'] = $res;
        $data = json_decode($res, true);

        if ($http_code === 200 && !empty($data['access_token'])) {
            $response['success'] = true;
            $response['message'] = 'Travelport+ JSON API credentials validated successfully.';
            $response['debug']['validation_steps'][] = 'OAuth2 Token successfully generated.';
            
            // Basic data to return
            $response['data'] = [
                'token_type' => $data['token_type'] ?? 'Bearer',
                'expires_in' => $data['expires_in'] ?? 0,
                'scope' => $data['scope'] ?? 'all'
            ];

            // Optional: Connectivity Check with Search (can be implemented later)
            $response['debug']['validation_steps'][] = 'Authentication successful.';

        } else {
            $error_msg = $data['error_description'] ?? $data['message'] ?? $data['error'] ?? 'Authentication failed';
            $response['message'] = 'Authentication failed: ' . $error_msg;
            $response['debug']['validation_steps'][] = 'Failed to generate OAuth2 token. HTTP Code: ' . $http_code;
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    $end_time = microtime(true);
    $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

});