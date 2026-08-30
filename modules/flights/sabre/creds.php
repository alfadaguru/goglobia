<?php

global $router;

// Test Sabre services
function testSabreServices($base_endpoint, $access_token) {
    $services = [
        // Core Flight Services
        ['name' => 'Flight Search', 'endpoint' => '/v4/offers/shop', 'method' => 'POST', 'category' => 'Core'],
        ['name' => 'Flight Booking', 'endpoint' => '/v2/passengers/create', 'method' => 'POST', 'category' => 'Core'],
        ['name' => 'Fare Quotes', 'endpoint' => '/v1/shop/flights/fares', 'method' => 'POST', 'category' => 'Core'],
        ['name' => 'Flight Status', 'endpoint' => '/v1/flight/status', 'method' => 'GET', 'category' => 'Core'],
        
        // Seat & Ancillary Services
        ['name' => 'Seat Maps', 'endpoint' => '/v1/book/flights/seatmaps', 'method' => 'GET', 'category' => 'Ancillary'],
        ['name' => 'Seat Selection', 'endpoint' => '/v2.0.0/book/flights/seats', 'method' => 'POST', 'category' => 'Ancillary'],
        ['name' => 'Ancillary Offers', 'endpoint' => '/v1/offers/shop/ancillaries', 'method' => 'POST', 'category' => 'Ancillary'],
        ['name' => 'Baggage Allowance', 'endpoint' => '/v1/trip/baggage', 'method' => 'GET', 'category' => 'Ancillary'],
        ['name' => 'Baggage Purchase', 'endpoint' => '/v1/book/ancillaries/baggage', 'method' => 'POST', 'category' => 'Ancillary'],
        ['name' => 'Meal Selection', 'endpoint' => '/v1/book/ancillaries/meals', 'method' => 'POST', 'category' => 'Ancillary'],
        ['name' => 'Special Services (SSR)', 'endpoint' => '/v1/book/ancillaries/ssr', 'method' => 'POST', 'category' => 'Ancillary'],
        
        // Information & Utilities
        ['name' => 'Airport Information', 'endpoint' => '/v1/lists/utilities/airports', 'method' => 'GET', 'category' => 'Information'],
        ['name' => 'Aircraft Information', 'endpoint' => '/v1/lists/utilities/aircraft/equipment', 'method' => 'GET', 'category' => 'Information'],
        ['name' => 'Airline Information', 'endpoint' => '/v1/lists/utilities/airlines', 'method' => 'GET', 'category' => 'Information'],
        ['name' => 'City Pairs', 'endpoint' => '/v1/lists/utilities/geography/citypairs', 'method' => 'GET', 'category' => 'Information'],
        
        // Traveler & Loyalty
        ['name' => 'Traveler Profiles', 'endpoint' => '/v1/trip/profile', 'method' => 'GET', 'category' => 'Traveler'],
        ['name' => 'Loyalty Programs', 'endpoint' => '/v1/trip/loyalty', 'method' => 'GET', 'category' => 'Traveler'],
        
        // Booking Management
        ['name' => 'Retrieve Booking', 'endpoint' => '/v1/trip/orders/getBooking', 'method' => 'POST', 'category' => 'Management'],
        ['name' => 'Cancel Booking', 'endpoint' => '/v1/trip/orders/cancelBooking', 'method' => 'POST', 'category' => 'Management'],
        ['name' => 'Modify Booking', 'endpoint' => '/v1/trip/orders/modifyBooking', 'method' => 'POST', 'category' => 'Management'],
        
        // Payment & Ticketing
        ['name' => 'Payment Processing', 'endpoint' => '/v1/trip/orders/payment', 'method' => 'POST', 'category' => 'Payment'],
        ['name' => 'Ticket Exchange', 'endpoint' => '/v1/trip/orders/exchange', 'method' => 'POST', 'category' => 'Payment']
    ];
    
    $available = [];
    foreach ($services as $service) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint . $service['endpoint'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $service['method'],
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOBODY => ($service['method'] === 'GET')
        ]);
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code > 0) {
            $service['status'] = ($http_code === 200 || $http_code === 201) ? 'ready' : 'available';
            $available[] = $service;
        }
    }
    
    return $available;
}

$router->post('flights/sabre/creds', function() {
    $start_time = microtime(true);
    
    // Get credentials
    $pcc = trim($_POST['c1'] ?? '');
    $epr = trim($_POST['c2'] ?? '');
    $domain = trim($_POST['c3'] ?? '');
    $password = trim($_POST['c4'] ?? '');
    $environment = trim($_POST['env'] ?? 'test');
    
    try {
        // Validate required fields
        if (empty($pcc) || empty($epr) || empty($domain) || empty($password)) {
            throw new Exception('Missing required credentials');
        }
        
        // Check for test values
        if (in_array('test', [$pcc, $epr, $domain, $password])) {
            throw new Exception('Test credentials not allowed');
        }
        
        // Build endpoint
        $base_endpoint = ($environment === 'test') 
            ? 'https://api.cert.platform.sabre.com'
            : 'https://api.platform.sabre.com';
        
        // Create V1 credentials
        $v1 = "V1:{$epr}:{$pcc}:{$domain}";
        $b_v1 = base64_encode($v1);
        $b_pwd = base64_encode($password);
        $auth = base64_encode($b_v1 . ':' . $b_pwd);
        
        // Make API call
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint . '/v2/auth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Network error: ' . curl_error($ch));
        }
        
        $data = json_decode($response, true);
        
        if ($http_code === 200 && isset($data['access_token'])) {
            $access_token = $data['access_token'];
            
            // Test available services
            $services = testSabreServices($base_endpoint, $access_token);
            
            // Group by category
            $grouped = [];
            foreach ($services as $svc) {
                $grouped[$svc['category']][] = $svc;
            }
            
            // Format terminal output in clean style
            $output = "\n";
            $output .= "📋 Available Services on Your Account:\n";
            $output .= "Account: PCC {$pcc} (" . ucfirst($environment) . " Environment)\n\n";
            
            // Core Services
            $output .= "Core Services (" . count($grouped['Core'] ?? []) . "):\n";
            foreach ($grouped['Core'] ?? [] as $svc) {
                $output .= "  • {$svc['name']}\n";
            }
            $output .= "\n";
            
            // Ancillary Services
            $output .= "Ancillary Services (" . count($grouped['Ancillary'] ?? []) . "):\n";
            $icons = [
                'Seat Maps' => '✈️',
                'Seat Selection' => '✈️',
                'Ancillary Offers' => '🎁',
                'Baggage Allowance' => '🧳',
                'Baggage Purchase' => '🧳',
                'Meal Selection' => '🍽️',
                'Special Services (SSR)' => '♿'
            ];
            foreach ($grouped['Ancillary'] ?? [] as $svc) {
                $icon = $icons[$svc['name']] ?? '•';
                $extra = ($svc['name'] === 'Special Services (SSR)') ? ' (wheelchair, extra legroom, etc)' : '';
                $output .= "  {$icon} {$svc['name']}{$extra}\n";
            }
            $output .= "\n";
            
            // Information Services
            $output .= "Information (" . count($grouped['Information'] ?? []) . "):\n";
            foreach ($grouped['Information'] ?? [] as $svc) {
                $status = ($svc['status'] === 'ready') ? ' (ready)' : '';
                $output .= "  • {$svc['name']}{$status}\n";
            }
            $output .= "\n";
            
            // Traveler Services
            $output .= "Traveler (" . count($grouped['Traveler'] ?? []) . "):\n";
            foreach ($grouped['Traveler'] ?? [] as $svc) {
                $output .= "  • {$svc['name']}\n";
            }
            $output .= "\n";
            
            // Management Services
            $output .= "Management (" . count($grouped['Management'] ?? []) . "):\n";
            foreach ($grouped['Management'] ?? [] as $svc) {
                $output .= "  • {$svc['name']}\n";
            }
            $output .= "\n";
            
            // Payment Services
            $output .= "Payment (" . count($grouped['Payment'] ?? []) . "):\n";
            foreach ($grouped['Payment'] ?? [] as $svc) {
                $output .= "  • {$svc['name']}\n";
            }
            $output .= "\n";
            
            // Summary
            $output .= "Total: " . count($services) . " services available - You can search flights, book, select seats, buy baggage, order meals, and manage complete bookings!\n";
            
            echo json_encode([
                'success' => true,
                'message' => 'API CONNECTION ESTABLISHED',
                'output' => $output,
                'account' => [
                    'pcc' => $pcc,
                    'environment' => $environment
                ],
                'services' => $grouped,
                'summary' => [
                    'total' => count($services),
                    'core' => count($grouped['Core'] ?? []),
                    'ancillary' => count($grouped['Ancillary'] ?? []),
                    'information' => count($grouped['Information'] ?? []),
                    'traveler' => count($grouped['Traveler'] ?? []),
                    'management' => count($grouped['Management'] ?? []),
                    'payment' => count($grouped['Payment'] ?? [])
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // API error
            $error = $data['error_description'] ?? $data['error'] ?? 'Authentication failed';
            throw new Exception("HTTP {$http_code}: {$error}");
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => [
                'error_type' => 'authentication_error',
                'environment' => $environment ?? 'test'
            ],
            'metadata' => [
                'response_time_ms' => round((microtime(true) - $start_time) * 1000, 2),
                'timestamp' => date('c')
            ]
        ]);
    }
});