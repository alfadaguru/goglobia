<?php
// ============================================================================
// HOTELSTON CREDENTIALS VALIDATION — StaticDataServiceV2 / getHotelDetails
// ENDPOINT: POST /stays/hotelston/creds
// ============================================================================

global $router;

$router->post('stays/hotelston/creds', function () {
    $start = microtime(true);

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'      => 'hotelston',
            'service'     => 'hotels',
            'provider'    => 'Hotelston',
            'api_version' => 'V2',
            'timestamp'   => date('c'),
        ],
    ];

    try {
        $email       = trim($_POST['c1'] ?? '');
        $password    = trim($_POST['c2'] ?? '');
        $rawProfile  = trim($_POST['c3'] ?? '0');
        $environment = (trim($_POST['env'] ?? 'production') === 'test') ? 'test' : 'production';

        $profile = $rawProfile;
        if ($profile === '' || $profile === '0') {
            $profile = '0';
        } elseif (ctype_digit($profile) && (int)$profile > 999) {
            $profile = '0';
        }

        $response['metadata']['environment'] = $environment;

        if ($email === '' || $password === '') {
            $missing = [];
            if ($email === '') {
                $missing[] = 'Email (c1)';
            }
            if ($password === '') {
                $missing[] = 'Password (c2)';
            }
            throw new Exception('Missing required credentials: ' . implode(', ', $missing));
        }

        if ($rawProfile !== '' && $rawProfile !== $profile) {
            throw new Exception(
                "Invalid Profile value '{$rawProfile}'. Hotelston profile must be 0 (default). "
                . 'Put your Email in c1, Password in c2, and 0 in c3.'
            );
        }

        $endpoint = $environment === 'test'
            ? 'https://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/'
            : 'https://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/';

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $body = '<xsd:HotelDetailsRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($email) . '"'
            . ' xsd1:password="' . $xmlAttr($password) . '"'
            . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
            . '<xsd:hotelId>50881305</xsd:hotelId>'
            . '</xsd:HotelDetailsRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
            . 'xmlns:xsd="http://request.v2.staticdataservice.ws.hotelston.com/xsd" '
            . 'xmlns:xsd1="http://types.v2.staticdataservice.ws.hotelston.com/xsd">'
            . '<soap:Header/><soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'SOAPAction: application/soap+xml; charset=utf-8',
                'Content-Type: urn:getHotelDetails',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new Exception('Hotelston HTTP error: ' . ($curlError ?: 'empty response'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Hotelston HTTP {$httpCode}");
        }

        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseBody, $m)) {
            throw new Exception('[' . $m[1] . '] ' . html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        }

        $hotelName = '';
        if (preg_match('/<(?:[\w]+:)?hotel\b([^>]*)>/i', $responseBody, $hotelTag)) {
            if (preg_match('/(?:[\w]+:)?name="([^"]*)"/i', $hotelTag[1], $nm)) {
                $hotelName = html_entity_decode($nm[1], ENT_QUOTES, 'UTF-8');
            }
        }

        if ($hotelName === '' && !preg_match('/<(?:[\w]+:)?success[^>]*>\s*(true|1)\s*</i', $responseBody)) {
            throw new Exception('API responded but returned no hotel data — check credentials');
        }

        $response['success'] = true;
        $response['message'] = 'Hotelston credentials validated successfully';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication'    => [
                'status'      => 'authenticated',
                'auth_type'   => 'SOAP 1.2 HTTP (Hotelston V2)',
                'environment' => $environment,
                'email'       => $email,
                'profile_id'  => $profile,
            ],
            'test_result' => [
                'test_type'         => 'getHotelDetails',
                'test_hotel_id'     => '50881305',
                'hotel_name'        => $hotelName,
                'credentials_valid' => true,
            ],
        ];
    } catch (Throwable $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        $response['data'] = [
            'error_type'        => 'credential_validation_failed',
            'error_description' => $e->getMessage(),
        ];
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);

    ob_clean();
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
