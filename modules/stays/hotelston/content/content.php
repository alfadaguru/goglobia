<?php

ob_start();

global $router, $db;

// ── StaticDataServiceV2 / getHotelDetails (raw SOAP for admin validate) ───────

if (!function_exists('hotelston_content_validate_creds')) {
    function hotelston_content_validate_creds(
        string $email,
        string $password,
        string $profile,
        int $hotelId,
        $environment = 'production'
    ): array {
        $profile = trim((string)$profile);
        if ($profile === '' || $profile === '0') {
            $profile = '0';
        } elseif (ctype_digit($profile) && (int)$profile > 999) {
            $profile = '0';
        }
        $login = ['email' => $email, 'password' => $password, 'profile' => $profile];

        $isDev = in_array(strtolower((string)$environment), ['test', 'dev', 'development'], true);
        $endpoints = $isDev
            ? ['static_endpoint' => 'http://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/']
            : ['static_endpoint' => 'http://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/'];

        $xmlAttr = static function (string $value): string {
            return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $body = '<xsd:HotelDetailsRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($login['email']) . '"'
            . ' xsd1:password="' . $xmlAttr($login['password']) . '"'
            . ' xsd1:profile="' . $xmlAttr($login['profile']) . '"/>'
            . '<xsd:hotelId>' . (int)$hotelId . '</xsd:hotelId>'
            . '</xsd:HotelDetailsRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
            . 'xmlns:xsd="http://request.v2.staticdataservice.ws.hotelston.com/xsd" '
            . 'xmlns:xsd1="http://types.v2.staticdataservice.ws.hotelston.com/xsd">'
            . '<soap:Header/><soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $ch = curl_init($endpoints['static_endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
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
            throw new RuntimeException('Hotelston HTTP error: ' . ($curlError ?: 'empty response'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Hotelston HTTP $httpCode");
        }

        $parsed = ['success' => false, 'hotel' => null, 'error' => null];
        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(true|1)\s*</i', $responseBody)) {
            $parsed['success'] = true;
        }
        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseBody, $m)) {
            $parsed['error'] = ['code' => $m[1], 'message' => html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')];
            $parsed['success'] = false;
        }
        if (preg_match('/<(?:[\w]+:)?hotel\b([^>]*)>/i', $responseBody, $hotelTag)) {
            $attrs = $hotelTag[1];
            $hid = null;
            $hname = '';
            if (preg_match('/(?:[\w]+:)?id="(\d+)"/i', $attrs, $m)) {
                $hid = $m[1];
            }
            if (preg_match('/(?:[\w]+:)?name="([^"]*)"/i', $attrs, $m)) {
                $hname = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            if ($hid !== null) {
                $parsed['hotel'] = ['id' => $hid, 'name' => $hname];
                if ($parsed['error'] === null) {
                    $parsed['success'] = true;
                }
            }
        }
        if ($parsed['hotel'] === null && $parsed['success'] && preg_match('/<(?:[\w]+:)?hotel\b/i', $responseBody)) {
            $parsed['hotel'] = ['id' => '', 'name' => 'Hotel'];
        }

        return $parsed;
    }
}

// Function to get Hotelston database connection
function getHotelstonDb() {
    global $db;
    
    // Get module configuration
    $module = $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelston',
        'type' => 'stays'
    ]);
    
    // If no separate database configured, use main database
    if (!$module || empty($module['host']) || empty($module['database'])) {
        return $db;
    }
    
    try {
        return new Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $module['host'],
            'database'  => $module['database'],
            'username'  => $module['username'],
            'password'  => $module['password'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    } catch (Exception $e) {
        error_log("Hotelston DB connection failed: " . $e->getMessage() . ". Falling back to main database.");
        return $db;
    }
}

// Validate credentials endpoint
$router->post('hotels/hotelston/validate', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $email = $_POST['c1'] ?? '';
        $password = $_POST['c2'] ?? '';
        $profile = $_POST['c3'] ?? '0';
        $environment = $_POST['env'] ?? 'production';
        
        if (empty($email) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required'
            ]);
            exit;
        }
        
        $parsed = hotelston_content_validate_creds(
            $email,
            $password,
            $profile,
            50881305,
            $environment === 'test' ? 'test' : 'production'
        );

        if ($parsed['success'] && !empty($parsed['hotel'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Credentials are valid and have API access',
                'hotel'   => $parsed['hotel']['name'] ?? null,
            ]);
        } elseif (!empty($parsed['error'])) {
            echo json_encode([
                'success' => false,
                'message' => '[' . ($parsed['error']['code'] ?? '?') . '] ' . ($parsed['error']['message'] ?? 'API error'),
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'API responded but returned no data - check credentials match your Postman request',
            ]);
        }
        
    } catch (\RuntimeException $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
});

// Get import statistics
$router->get('hotels/hotelston/stats', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    try {
        $stats = [
            'total_hotels' => $hotelstonDb->count('hotelston_hotels'),
            'total_destinations' => $hotelstonDb->count('hotelston_destinations'),
            'total_countries' => $hotelstonDb->count('hotelston_countries'),
            'last_import' => $hotelstonDb->get('hotelston_import_log', ['started_at', 'status'], [
                'ORDER' => ['id' => 'DESC']
            ])
        ];

        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
});

// Start content import
$router->post('hotels/hotelston/content_import', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    try {
        $mode = $_POST['mode'] ?? 'update';
        $email = $_POST['c1'] ?? '';
        $password = $_POST['c2'] ?? '';
        $profile = $_POST['c3'] ?? '0';
        $environment = $_POST['env'] ?? 'production';

        if (empty($email) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required'
            ]);
            exit;
        }

        // Create database schema if it doesn't exist
        try {
            createHotelstonSchema($hotelstonDb);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create database schema: ' . $e->getMessage() . '. Please ensure the database exists and has proper permissions.'
            ]);
            exit;
        }

        // If fresh mode, truncate all tables
        if ($mode === 'fresh') {
            try {
                truncateHotelstonTables($hotelstonDb);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to truncate tables: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        // Store import state in database
        $importState = [
            'mode' => $mode,
            'email' => $email,
            'password' => $password,
            'profile' => $profile,
            'environment' => $environment,
            'current_chunk' => 0,
            'total_chunks' => 1, // Will process sample hotels (can be updated later for full API)
            'processed' => 0,
            'total' => 15, // Sample hotel count
            'start_time' => time(),
            'logs' => json_encode([])
        ];

        $hotelstonDb->insert('hotelston_import_log', [
            'mode' => $mode,
            'status' => 'in_progress',
            'import_state' => json_encode($importState),
            'started_at' => $hotelstonDb->raw('NOW()')
        ]);
        
        $importId = $hotelstonDb->id();

        echo json_encode([
            'success' => true,
            'message' => 'Import initialized successfully',
            'import_id' => $importId
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});

// Progress endpoint
$router->get('hotels/hotelston/progress', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    $importLog = $hotelstonDb->get('hotelston_import_log', '*', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if (!$importLog) {
        echo json_encode([
            'success' => false,
            'error' => 'No import in progress'
        ]);
        exit;
    }

    $import = json_decode($importLog['import_state'], true);
    if (!$import) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid import state'
        ]);
        exit;
    }

    $progress = ($import['processed'] / max($import['total'], 1)) * 100;
    $elapsed = time() - $import['start_time'];
    $rate = $elapsed > 0 ? $import['processed'] / $elapsed : 0;
    $remaining = $rate > 0 ? ($import['total'] - $import['processed']) / $rate : 0;

    echo json_encode([
        'success' => true,
        'status' => $importLog['status'],
        'current_operation' => $import['current_operation'] ?? 'Processing...',
        'progress' => round($progress, 2),
        'processed' => $import['processed'],
        'total' => $import['total'],
        'chunk' => $import['current_chunk'],
        'total_chunks' => $import['total_chunks'],
        'elapsed_time' => $elapsed,
        'estimated_remaining' => round($remaining),
        'logs' => json_decode($import['logs'], true) ?? []
    ]);

    exit;
});

// Cancel import
$router->post('hotels/hotelston/cancel', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    ob_clean();
    header('Content-Type: application/json');

    $importLog = $hotelstonDb->get('hotelston_import_log', 'id', [
        'status' => 'in_progress',
        'ORDER' => ['id' => 'DESC']
    ]);

    if ($importLog) {
        $hotelstonDb->update('hotelston_import_log', [
            'status' => 'cancelled',
            'updated_at' => $hotelstonDb->raw('NOW()')
        ], ['id' => $importLog]);
    }

    echo json_encode(['success' => true]);
    exit;
});

// Process chunk endpoint
$router->post('hotels/hotelston/process', function() use ($db) {
    $hotelstonDb = getHotelstonDb();
    try {
        ob_clean();
        header('Content-Type: application/json');

        $importLog = $hotelstonDb->get('hotelston_import_log', '*', [
            'status' => 'in_progress',
            'ORDER' => ['id' => 'DESC']
        ]);

        if (!$importLog) {
            echo json_encode([
                'success' => false,
                'error' => 'No import session found'
            ]);
            exit;
        }

        $import = json_decode($importLog['import_state'], true);
        if (!$import) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid import state'
            ]);
            exit;
        }
        
        try {
            $currentChunk = $import['current_chunk'];
            
            $import['current_operation'] = "Processing chunk " . ($currentChunk + 1);
            $logs = json_decode($import['logs'], true) ?? [];
            if ($currentChunk % 10 == 0) {
                $logs[] = "[" . date('H:i:s') . "] Processing chunk " . ($currentChunk + 1);
            }
            $import['logs'] = json_encode($logs);
            
            $hotelstonDb->update('hotelston_import_log', [
                'import_state' => json_encode($import),
                'updated_at' => $hotelstonDb->raw('NOW()')
            ], ['id' => $importLog['id']]);
            
            // Import chunk
            $result = importHotelstonChunk(
                $hotelstonDb,
                $import['email'],
                $import['password'],
                $import['profile'],
                $currentChunk,
                $import['mode'],
                $import['environment']
            );
            
            if (!$result) {
                echo json_encode([
                    'success' => false,
                    'completed' => true,
                    'error' => 'Import chunk function returned null/false'
                ]);
                exit;
            }
            
            if ($result['success']) {
                $import['current_chunk']++;
                $import['processed'] += $result['records_processed'];
                if ($import['current_chunk'] % 10 == 0 || $result['is_last_chunk']) {
                    $logs[] = "[" . date('H:i:s') . "] Processed " . $result['records_processed'] . " records (Total: " . $import['processed'] . ")";
                }
                $import['logs'] = json_encode($logs);
                
                $isComplete = $result['is_last_chunk'] || $import['current_chunk'] >= $import['total_chunks'];
                
                if ($isComplete) {
                    $import['current_operation'] = 'Import completed successfully';
                    
                    $hotelstonDb->update('hotelston_import_log', [
                        'status' => 'completed',
                        'hotels_imported' => $import['processed'],
                        'completed_at' => $hotelstonDb->raw('NOW()'),
                        'updated_at' => $hotelstonDb->raw('NOW()'),
                        'import_state' => json_encode($import)
                    ], ['id' => $importLog['id']]);
                    
                    echo json_encode([
                        'success' => true,
                        'completed' => true,
                        'message' => 'Import completed successfully',
                        'total_processed' => $import['processed']
                    ]);
                } else {
                    $hotelstonDb->update('hotelston_import_log', [
                        'import_state' => json_encode($import),
                        'updated_at' => $hotelstonDb->raw('NOW()')
                    ], ['id' => $importLog['id']]);
                    
                    echo json_encode([
                        'success' => true,
                        'completed' => false,
                        'continue' => true,
                        'message' => 'Chunk processed, continuing...'
                    ]);
                }
            } else {
                // STOP IMPORT IMMEDIATELY ON ERROR
                $import['current_operation'] = 'Import failed: ' . $result['error'];
                $logs[] = "[" . date('H:i:s') . "] [ERROR] Fatal error: " . $result['error'];
                $import['logs'] = json_encode($logs);
                
                $hotelstonDb->update('hotelston_import_log', [
                    'status' => 'failed',
                    'error_message' => $result['error'],
                    'import_state' => json_encode($import),
                    'updated_at' => $hotelstonDb->raw('NOW()')
                ], ['id' => $importLog['id']]);
                
                echo json_encode([
                    'success' => false,
                    'completed' => true,
                    'error' => $result['error']
                ]);
                exit;
            }
            
        } catch (Exception $e) {
            if (isset($importLog['id'])) {
                $hotelstonDb->update('hotelston_import_log', [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => $hotelstonDb->raw('NOW()')
                ], ['id' => $importLog['id']]);
            }
            
            echo json_encode([
                'success' => false,
                'completed' => true,
                'error' => $e->getMessage()
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'completed' => true,
            'error' => 'Fatal error: ' . $e->getMessage()
        ]);
        exit;
    }
    
    exit;
});

// Helper function to import hotels chunk (raw cURL SOAP — no shared functions.php)
function importHotelstonChunk($db, $email, $password, $profile, $chunkNumber, $mode, $environment = 'production') {
    try {
        $sampleHotelIds = [
            '55341196', '55341197', '55341198', '55341199', '55341200',
            '55341201', '55341202', '55341203', '55341204', '55341205',
            '55341206', '55341207', '55341208', '55341209', '55341210'
        ];

        $startIdx = $chunkNumber * 15;
        $hotelIdsToProcess = array_slice($sampleHotelIds, $startIdx, 15);

        if (empty($hotelIdsToProcess)) {
            return [
                'success' => true,
                'records_processed' => 0,
                'is_last_chunk' => true
            ];
        }

        $profile = trim((string)$profile);
        if ($profile === '' || $profile === '0' || (ctype_digit($profile) && (int)$profile > 999)) {
            $profile = '0';
        }

        $isDev = in_array(strtolower((string)$environment), ['test', 'dev', 'development'], true);
        $endpoint = $isDev
            ? 'http://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/'
            : 'http://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/';

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $hotelId = (int)$hotelIdsToProcess[0];
        $body = '<xsd:HotelDetailsRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($email) . '"'
            . ' xsd1:password="' . $xmlAttr($password) . '"'
            . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
            . '<xsd:hotelId>' . $hotelId . '</xsd:hotelId>'
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 120,
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
            return ['success' => false, 'error' => 'Hotelston HTTP error: ' . ($curlError ?: 'empty response')];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "Hotelston HTTP {$httpCode}"];
        }

        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseBody, $em)) {
            $errorMsg = html_entity_decode($em[1], ENT_QUOTES, 'UTF-8');
            if (strpos($errorMsg, 'Web services are not enabled') !== false) {
                $errorMsg = 'Static Data Service is not enabled for your account. Please contact api@hotelston.com to request access. Your account currently only has Booking Service access.';
            }
            return ['success' => false, 'error' => 'API Error: ' . $errorMsg];
        }

        if (!preg_match('/<(?:[\w]+:)?hotel\b([^>]*)>(.*?)<\/(?:[\w]+:)?hotel>/is', $responseBody, $hm)) {
            return ['success' => false, 'error' => 'No hotel data returned from API'];
        }

        $attrs = $hm[1];
        $inner = $hm[2];
        $readAttr = static function ($a, $name) {
            if (preg_match('/(?:[\w]+:)?' . preg_quote($name, '/') . '="([^"]*)"/i', $a, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            return '';
        };

        $addressAttrs = '';
        if (preg_match('/<(?:[\w]+:)?address\b([^>]*)>/i', $inner, $am)) {
            $addressAttrs = $am[1];
        }

        $lat = null;
        $lng = null;
        if (preg_match('/<(?:[\w]+:)?coordinates\b([^>]*)\/>/i', $inner, $cm)) {
            $latVal = $readAttr($cm[1], 'latitude');
            $lngVal = $readAttr($cm[1], 'longitude');
            $lat = $latVal !== '' ? $latVal : null;
            $lng = $lngVal !== '' ? $lngVal : null;
        }

        $starRating = '';
        if (preg_match('/<(?:[\w]+:)?starRating\b[^>]*>\s*(.*?)\s*<\/(?:[\w]+:)?starRating>/is', $inner, $sm)) {
            $starRating = trim(strip_tags($sm[1]));
        }
        $description = '';
        if (preg_match('/<(?:[\w]+:)?description\b[^>]*>\s*(?:<!\[CDATA\[(.*?)\]\]>|(.*?))\s*<\/(?:[\w]+:)?description>/is', $inner, $dm)) {
            $description = $dm[1] !== '' ? $dm[1] : ($dm[2] ?? '');
        }

        $hotelData = [
            'hotel_id'    => $readAttr($attrs, 'id') ?: (string)$hotelId,
            'name'        => $readAttr($attrs, 'name'),
            'address'     => trim($readAttr($addressAttrs, 'street1') . ' ' . $readAttr($addressAttrs, 'street2')),
            'city'        => $readAttr($addressAttrs, 'city'),
            'country'     => $readAttr($addressAttrs, 'country'),
            'postal_code' => $readAttr($addressAttrs, 'zip'),
            'latitude'    => $lat,
            'longitude'   => $lng,
            'rating'      => $starRating !== '' ? $starRating : 0,
            'description' => $description,
            'updated_at'  => $db->raw('NOW()'),
        ];

        $recordsProcessed = 0;
        if (!empty($hotelData['hotel_id'])) {
            if ($mode === 'fresh') {
                try {
                    $db->insert('hotelston_hotels', $hotelData);
                    $recordsProcessed++;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        error_log("Hotelston insert error: " . $e->getMessage());
                        throw $e;
                    }
                }
            } else {
                $existing = $db->get('hotelston_hotels', 'id', ['hotel_id' => $hotelData['hotel_id']]);
                if ($existing) {
                    $db->update('hotelston_hotels', $hotelData, ['hotel_id' => $hotelData['hotel_id']]);
                } else {
                    $hotelData['created_at'] = $db->raw('NOW()');
                    $db->insert('hotelston_hotels', $hotelData);
                }
                $recordsProcessed++;
            }
        }

        $isLastChunk = count($hotelIdsToProcess) < 15 || $startIdx + 15 >= count($sampleHotelIds);

        return [
            'success' => true,
            'records_processed' => $recordsProcessed,
            'is_last_chunk' => $isLastChunk
        ];

    } catch (Exception $e) {
        error_log("Hotelston import error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Helper function to create database schema
function createHotelstonSchema($db) {
    // Hotels table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_hotels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hotel_id VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        address VARCHAR(255),
        city VARCHAR(100),
        country VARCHAR(100),
        postal_code VARCHAR(20),
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        rating DECIMAL(2, 1),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hotel_id (hotel_id),
        INDEX idx_city (city),
        INDEX idx_country (country)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Countries table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_countries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Destinations table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_destinations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        country_code VARCHAR(10),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_country (country_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Import log table
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_import_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mode ENUM('fresh', 'update') NOT NULL,
        status ENUM('in_progress', 'completed', 'failed', 'cancelled') DEFAULT 'in_progress',
        hotels_imported INT DEFAULT 0,
        destinations_imported INT DEFAULT 0,
        countries_imported INT DEFAULT 0,
        import_state TEXT,
        error_message TEXT,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Helper function to truncate tables
function truncateHotelstonTables($db) {
    $tables = [
        'hotelston_hotels',
        'hotelston_countries',
        'hotelston_destinations'
    ];

    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        $db->query("TRUNCATE TABLE $table");
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
}