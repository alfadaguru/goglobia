<?php
/**
 * Stuba Content Import API - UPDATED VERSION with Dynamic Environment Support
 * Handles hotel content data import from Stuba Content API with test/live environment switching
 * All data is fully dynamic with environment-based URL and credential selection
 */

global $router, $db;

// Test endpoint
$router->post('stays/stuba/content/test', function() {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'Router is working']);
    exit;
});

// Get Stuba database connection (separate database for content)
function getStubaDb() {
    global $db;

    // Get module configuration
    $module = @$db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'stuba',
        'type' => 'stays'
    ]);

    // If no separate database configured, use main database
    if (!$module || empty($module['host']) || empty($module['database'])) {
        return $db;
    }

    try {
        // Create new Medoo instance for Stuba content database
        $stubaDb = @new Medoo\Medoo([
            'type' => 'mysql',
            'host' => $module['host'],
            'database' => $module['database'],
            'username' => $module['username'],
            'password' => $module['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci'
        ]);

        return $stubaDb;
    } catch (Exception $e) {
        error_log("Stuba: Failed to connect to separate database, using main database. Error: " . $e->getMessage());
        return $db;
    }
}

/**
 * Get Stuba API configuration based on environment (test/live)
 * Returns both SOAP and Content API endpoints with appropriate credentials
 */
function getStubaApiConfig($mainDb) {
    // Get module configuration
    $module = $mainDb->get('modules', '*', [
        'name' => 'stuba',
        'type' => 'stays'
    ]);
    
    if (!$module) {
        throw new Exception('Stuba module not found');
    }

    $environment = ($module['dev_mode'] ?? 0) == 1 ? 'test' : 'production';
    
    if ($environment === 'test') {
        // Test environment configuration
        return [
            'environment' => 'test',
            // SOAP API (existing)
            'soap_endpoint' => 'https://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx',
            // Content API (new test environment)
            'content_base_url' => 'https://testcontent.stuba.com',
            // Test credentials from Stuba email
            'org' => $module['c1'] ?? '',
            'user' => $module['c2'] ?? '',
            'password' => $module['c3'] ?? '',
            'currency' => 'USD'
        ];
    } else {
        // Production environment configuration
        return [
            'environment' => 'production',
            // SOAP API (existing)
            'soap_endpoint' => 'https://api.stuba.com/RXLServices/ASMX/XmlService.asmx',
            // Content API (production)
            'content_base_url' => 'https://content.stuba.com',
            // Production credentials from database
            'org' => $module['c1'] ?? '',
            'user' => $module['c2'] ?? '',
            'password' => $module['c3'] ?? '',
            'currency' => 'USD'
        ];
    }
}

/**
 * Make Content API request with proper environment handling
 */
function makeContentApiRequest($endpoint, $requestBody, $mainDb, $timeout = 60) {
    $config = getStubaApiConfig($mainDb);

    $url = $config['content_base_url'] . $endpoint;
    
    // Add authority to request body
    $requestBody['Authority'] = [
        'Org' => $config['org'],
        'User' => $config['user'],
        'Password' => $config['password']
    ];
    
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if ($curlError) {
        throw new Exception('Content API request failed: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception('Content API returned HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
    }

    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON decode error: ' . json_last_error_msg());
    }

    if (!$data) {
        throw new Exception('Invalid API response format');
    }

    if (!$data['Success']) {
        throw new Exception('Content API returned error: ' . ($data['Message'] ?? 'Unknown error'));
    }

    return $data;
}

// POST /modules/stays/stuba/content/validate - Validate API credentials for both SOAP and Content APIs
$router->post('stays/stuba/content/validate', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $config = getStubaApiConfig($db);
        
        $validationResults = [
            'soap_api' => ['success' => false, 'message' => ''],
            'content_api' => ['success' => false, 'message' => ''],
            'environment' => $config['environment']
        ];

        // Test SOAP API (RegionSearch)
        try {
            $xml_request = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <RegionSearch xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
      <xiRequest>
        <Authority>
          <Org>' . htmlspecialchars($config['org'], ENT_XML1, 'UTF-8') . '</Org>
          <User>' . htmlspecialchars($config['user'], ENT_XML1, 'UTF-8') . '</User>
          <Password>' . htmlspecialchars($config['password'], ENT_XML1, 'UTF-8') . '</Password>
          <Currency>' . htmlspecialchars($config['currency'], ENT_XML1, 'UTF-8') . '</Currency>
          <Version>1.28</Version>
        </Authority>
        <QueryText>London</QueryText>
      </xiRequest>
    </RegionSearch>
  </soap:Body>
</soap:Envelope>';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $config['soap_endpoint'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xml_request,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/RegionSearch"',
                    'Content-Length: ' . strlen($xml_request)
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($http_code === 200 && stripos($response, 'soap:Fault') === false) {
                $validationResults['soap_api'] = [
                    'success' => true,
                    'message' => 'SOAP API credentials validated successfully'
                ];
            } else {
                throw new Exception('SOAP API validation failed');
            }
        } catch (Exception $e) {
            $validationResults['soap_api'] = [
                'success' => false,
                'message' => 'SOAP API: ' . $e->getMessage()
            ];
        }

        // Test Content API (getAllCountries)
        try {
            $data = makeContentApiRequest('/webapi/staticData/getAllCountries', [], $db, 30);
            
            if (isset($data['Data']) && is_array($data['Data']) && count($data['Data']) > 0) {
                $validationResults['content_api'] = [
                    'success' => true,
                    'message' => 'Content API credentials validated successfully (' . count($data['Data']) . ' countries found)'
                ];
            } else {
                throw new Exception('Content API returned empty or invalid data');
            }
        } catch (Exception $e) {
            $validationResults['content_api'] = [
                'success' => false,
                'message' => 'Content API: ' . $e->getMessage()
            ];
        }

        // Overall success
        $overall_success = $validationResults['soap_api']['success'] && $validationResults['content_api']['success'];
        
        echo json_encode([
            'success' => $overall_success,
            'environment' => $config['environment'],
            'results' => $validationResults,
            'message' => $overall_success 
                ? "Both APIs validated successfully in {$config['environment']} environment"
                : "Some API validations failed in {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// GET /modules/stays/stuba/content/environment - Get current environment info
$router->get('stays/stuba/content/environment', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $config = getStubaApiConfig($db);
        
        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'soap_endpoint' => $config['soap_endpoint'],
            'content_base_url' => $config['content_base_url'],
            'org' => $config['org'],
            'user' => $config['user'],
            'currency' => $config['currency']
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// GET /modules/stays/stuba/content/stats - Get import statistics
$router->get('stays/stuba/content/stats', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        $config = getStubaApiConfig($db);

        // Get statistics
        $stats = [
            'total_hotels' => 0,
            'total_regions' => 0,
            'total_countries' => 0,
            'last_sync' => null,
            'environment' => $config['environment'],
            'database_size' => 0
        ];

        // Check if tables exist
        $tables_exist = false;
        try {
            $stats['total_hotels'] = $stubaDb->count('stuba_hotels');
            $stats['total_regions'] = $stubaDb->count('stuba_regions');
            $stats['total_countries'] = $stubaDb->count('stuba_countries');
            $tables_exist = true;
        } catch (Exception $e) {
            // Tables don't exist yet
            $tables_exist = false;
        }

        // Get last import info
        if ($tables_exist) {
            $last_import = $stubaDb->get('stuba_import_log', '*', [
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1
            ]);

            if ($last_import) {
                $stats['last_sync'] = $last_import['completed_at'] ?? $last_import['started_at'];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// POST /modules/stays/stuba/content/import-countries - Import all countries from Content API
$router->post('stays/stuba/content/import-countries', function() use ($db) {
    while (@ob_get_level()) { @ob_end_clean(); }
    @ob_start();
    @ini_set('display_errors', '0');
    @error_reporting(0);
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        createStubaTables($stubaDb);
        
        $config = getStubaApiConfig($db);
        
        // Call Content API to get all countries
        $data = makeContentApiRequest('/webapi/staticData/getAllCountries', [], $db);

        // Insert countries into database
        $imported = 0;
        $errors = [];

        foreach ($data['Data'] as $country) {
            try {
                $existing = $stubaDb->get('stuba_countries', 'id', ['region_id' => $country['RegionId']]);
                
                $countryData = [
                    'region_name' => $country['RegionName'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if ($existing) {
                    $stubaDb->update('stuba_countries', $countryData, ['region_id' => $country['RegionId']]);
                } else {
                    $countryData['region_id'] = $country['RegionId'];
                    $countryData['created_at'] = date('Y-m-d H:i:s');
                    $stubaDb->insert('stuba_countries', $countryData);
                    $imported++;
                }
            } catch (Exception $e) {
                $errors[] = $country['RegionName'] . ': ' . $e->getMessage();
            }
        }

        $message = "Imported {$imported} countries from {$config['environment']} environment";
        if (!empty($errors) && count($errors) < 10) {
            $message .= ' (Errors: ' . implode(', ', array_slice($errors, 0, 3)) . ')';
        }

        @ob_end_clean();
        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'countries_imported' => $imported,
            'total_in_api' => count($data['Data']),
            'error_count' => count($errors),
            'sample_errors' => array_slice($errors, 0, 3),
            'message' => $message
        ]);
    } catch (Exception $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// POST /modules/stays/stuba/content/regions-by-countries - Import regions by countries
$router->post('stays/stuba/content/regions-by-countries', function() use ($db) {
    while (@ob_get_level()) { @ob_end_clean(); }
    @ob_start();
    @ini_set('display_errors', '0');
    @error_reporting(0);
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        createStubaTables($stubaDb);
        
        $config = getStubaApiConfig($db);

        // Get batch parameters
        $batchSize = (int)($_POST['batch_size'] ?? 10);
        $offset = (int)($_POST['offset'] ?? 0);

        // Get all countries from database
        $totalCountries = $stubaDb->count('stuba_countries');
        
        if ($totalCountries == 0) {
            throw new Exception('No countries found in database. Please import countries first.');
        }

        // Get current batch of countries
        $countries = $stubaDb->select('stuba_countries', 
            ['region_id', 'region_name'], 
            [
                'LIMIT' => [$offset, $batchSize],
                'ORDER' => ['id' => 'ASC']
            ]
        );

        if (empty($countries)) {
            @ob_end_clean();
            echo json_encode([
                'success' => true,
                'environment' => $config['environment'],
                'regions_imported' => 0,
                'countries_processed' => 0,
                'total_countries' => $totalCountries,
                'is_complete' => true,
                'message' => 'All countries processed'
            ]);
            exit;
        }

        $totalRegions = 0;
        $countriesProcessed = 0;
        $errors = [];

        // Process each country
        foreach ($countries as $country) {
            $countryRegionId = $country['region_id'];
            $countryName = $country['region_name'];


            try {
                // Request body for regions
                $requestBody = ['RegionId' => $countryRegionId];
                
                $data = makeContentApiRequest('/webapi/staticData/getAllSearchRegionsByCountry', $requestBody, $db);

                // Process regions for this country
                $regions = $data['Data'] ?? [];
                $regionsImported = 0;

                foreach ($regions as $region) {
                    try {
                        $regionData = [
                            'region_id' => $region['CityId'],
                            'region_name' => $region['CityName'],
                            'country' => $countryName,
                            'country_region_id' => $countryRegionId,
                            'created_at' => date('Y-m-d H:i:s')
                        ];

                        // Add optional fields
                        if (isset($region['RegionType'])) $regionData['region_type'] = $region['RegionType'];
                        if (isset($region['Type'])) $regionData['region_type'] = $region['Type'];
                        if (isset($region['Latitude'])) $regionData['latitude'] = $region['Latitude'];
                        if (isset($region['Longitude'])) $regionData['longitude'] = $region['Longitude'];
                        if (isset($region['CountryCode'])) $regionData['country_code'] = $region['CountryCode'];

                        $existing = $stubaDb->get('stuba_regions', 'id', [
                            'region_id' => $region['CityId']
                        ]);

                        if ($existing) {
                            unset($regionData['created_at']);
                            $regionData['updated_at'] = date('Y-m-d H:i:s');
                            $stubaDb->update('stuba_regions', $regionData, [
                                'region_id' => $region['CityId']
                            ]);
                        } else {
                            $result = $stubaDb->insert('stuba_regions', $regionData);
                            if ($result) {
                                $regionsImported++;
                            }
                        }

                    } catch (Exception $e) {
                        error_log("Stuba: Error inserting region {$region['CityName']}: " . $e->getMessage());
                        continue;
                    }
                }

                $totalRegions += $regionsImported;
                $countriesProcessed++;


                // Small delay to avoid rate limiting
                usleep(500000); // 0.5 second delay

            } catch (Exception $e) {
                error_log("Stuba: Error processing {$countryName}: " . $e->getMessage());
                $errors[] = "{$countryName}: {$e->getMessage()}";
                continue;
            }
        }

        // Calculate progress
        $nextOffset = $offset + $batchSize;
        $isComplete = $nextOffset >= $totalCountries;
        $progressPercent = $totalCountries > 0 ? round(($nextOffset / $totalCountries) * 100, 1) : 0;
        if ($progressPercent > 100) $progressPercent = 100;

        @ob_end_clean();
        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'regions_imported' => $totalRegions,
            'countries_processed' => $countriesProcessed,
            'total_countries' => $totalCountries,
            'progress_percent' => $progressPercent,
            'next_offset' => $nextOffset,
            'is_complete' => $isComplete,
            'error_count' => count($errors),
            'sample_errors' => array_slice($errors, 0, 5),
            'message' => "Processed {$countriesProcessed} countries, imported {$totalRegions} regions from {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// POST /modules/stays/stuba/content/import-hotels-batch - Import hotels for all regions in batches
$router->post('stays/stuba/content/import-hotels-batch', function() use ($db) {
    while (@ob_get_level()) { @ob_end_clean(); }
    @ob_start();
    @ini_set('display_errors', '0');
    @error_reporting(0);
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        $config = getStubaApiConfig($db);

        // Get batch parameters
        $batchSize = (int)($_POST['batch_size'] ?? 10);
        $offset = (int)($_POST['offset'] ?? 0);

        // Get all REGIONS from database (not countries)
        $totalRegions = $stubaDb->count('stuba_regions');
        
        if ($totalRegions == 0) {
            throw new Exception('No regions found in database. Please import regions first.');
        }

        // Get current batch of regions
        $regions = $stubaDb->select('stuba_regions', 
            ['id', 'region_id', 'region_name', 'country', 'country_region_id'], 
            [
                'LIMIT' => [$offset, $batchSize],
                'ORDER' => ['id' => 'ASC']
            ]
        );

        if (empty($regions)) {
            @ob_end_clean();
            echo json_encode([
                'success' => true,
                'environment' => $config['environment'],
                'hotels_imported' => 0,
                'regions_processed' => 0,
                'total_regions' => $totalRegions,
                'is_complete' => true,
                'message' => 'All regions processed'
            ]);
            exit;
        }

        $totalHotels = 0;
        $regionsProcessed = 0;
        $errors = [];

        // Process each region
        foreach ($regions as $region) {
            $regionId = $region['region_id'];
            $regionName = $region['region_name'];
            $country = $region['country'];
            
            $countryCode = $region['country_region_id'];


            try {
                // Request body for hotels by region
                $requestBody = ['RegionId' => $regionId];
                
                $data = makeContentApiRequest('/webapi/staticData/getAllHotelsListBySearchRegion', $requestBody, $db, 120);

                // Process hotels for this region
                $hotels = $data['Data'] ?? [];
                $hotelsImported = 0;
                
                foreach ($hotels as $hotel) {
                    try {
                        // Prepare hotel data
                        $insertData = [
                            'hotel_id' => $hotel['Id'],
                            'hotel_name' => $hotel['HotelName'],
                            'region_id' => $regionId,
                            'city_id' => $regionId,
                            'city' => $regionName,
                            'country' => $country,
                            'country_code' => $countryCode,
                            'hotel_type' => 'Hotel',
                            'is_active' => 1,
                            'is_bookable' => 1,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        // Add optional fields if present
                        if (isset($hotel['Rating'])) $insertData['rating'] = $hotel['Rating'];
                        if (isset($hotel['Latitude'])) $insertData['latitude'] = $hotel['Latitude'];
                        if (isset($hotel['Longitude'])) $insertData['longitude'] = $hotel['Longitude'];

                        // Insert or update hotel
                        $existing = $stubaDb->get('stuba_hotels', 'id', ['hotel_id' => $hotel['Id']]);
                        
                        if ($existing) {
                            // Update existing hotel
                            unset($insertData['created_at']);
                            $insertData['updated_at'] = date('Y-m-d H:i:s');
                            $stubaDb->update('stuba_hotels', $insertData, ['hotel_id' => $hotel['Id']]);
                        } else {
                            // Insert new hotel
                            $result = $stubaDb->insert('stuba_hotels', $insertData);
                            if ($result) {
                                $hotelsImported++;
                            }
                        }

                    } catch (Exception $e) {
                        error_log("Stuba: Error inserting hotel {$hotel['HotelName']}: " . $e->getMessage());
                        continue;
                    }
                }

                $totalHotels += $hotelsImported;
                $regionsProcessed++;

                // Update region with hotel count
                try {
                    $stubaDb->update('stuba_regions', [
                        'total_hotels' => $hotelsImported,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $region['id']]);
                } catch (Exception $e) {
                    error_log("Stuba: Error updating region: " . $e->getMessage());
                }

                error_log("Stuba: Imported {$hotelsImported} hotels for region {$regionName}");

                // Small delay to avoid rate limiting
                usleep(500000); // 0.5 second delay

            } catch (Exception $e) {
                error_log("Stuba: Error processing region {$regionName}: " . $e->getMessage());
                $errors[] = "{$regionName}: {$e->getMessage()}";
                continue;
            }
        }

        // Calculate progress
        $nextOffset = $offset + $batchSize;
        $isComplete = $nextOffset >= $totalRegions;
        $progressPercent = $totalRegions > 0 ? round(($nextOffset / $totalRegions) * 100, 1) : 0;
        if ($progressPercent > 100) $progressPercent = 100;

        @ob_end_clean();
        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'hotels_imported' => $totalHotels,
            'regions_processed' => $regionsProcessed,
            'total_regions' => $totalRegions,
            'progress_percent' => $progressPercent,
            'next_offset' => $nextOffset,
            'is_complete' => $isComplete,
            'error_count' => count($errors),
            'sample_errors' => array_slice($errors, 0, 5),
            'message' => "Processed {$regionsProcessed} regions, imported {$totalHotels} hotels from {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// POST /modules/stays/stuba/content/enrich-hotels - Fetch complete hotel details
$router->post('stays/stuba/content/enrich-hotels', function() use ($db) {
    ob_start();
    header('Content-Type: application/json');

    try {
        $batch_size = (int)($_POST['batch_size'] ?? 100);
        $offset = (int)($_POST['offset'] ?? 0);

        $config = getStubaApiConfig($db);
        $stubaDb = getStubaDb();

        $hotels = $stubaDb->select('stuba_hotels', ['id', 'hotel_id', 'hotel_name'], [
            'LIMIT' => [$offset, $batch_size],
            'ORDER' => ['id' => 'ASC']
        ]);

        $totalHotels = $stubaDb->count('stuba_hotels');

        if (empty($hotels)) {
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'environment' => $config['environment'],
                'processed' => 0,
                'images_added' => 0,
                'amenities_added' => 0,
                'total_hotels' => $totalHotels,
                'next_offset' => $offset,
                'is_complete' => true,
                'message' => $totalHotels == 0 ? 'No hotels found' : 'All hotels enriched'
            ]);
            return;
        }

        $hotelIds = array_column($hotels, 'hotel_id');

        // Request body for hotel details
        $requestBody = ['HotelIds' => $hotelIds];
        
        $data = makeContentApiRequest('/webapi/staticData/getAllHotelsDetailsByHotelIds', $requestBody, $db, 120);

        $hotelDetails = $data['Data'] ?? [];

        $stats = [
            'processed' => 0,
            'images_added' => 0,
            'amenities_added' => 0
        ];

        $hotelMap = [];
        foreach ($hotels as $h) {
            $hotelMap[$h['hotel_id']] = $h;
            $hotelMap[(string)$h['hotel_id']] = $h;
            $hotelMap[(int)$h['hotel_id']] = $h;
        }

        foreach ($hotelDetails as $hotelData) {
            if (!isset($hotelData['HotelElement'])) {
                continue;
            }

            $hotelDetail = $hotelData['HotelElement'];

            if (!isset($hotelDetail['Id'])) {
                continue;
            }

            $apiHotelId = $hotelDetail['Id'];
            $dbHotel = $hotelMap[$apiHotelId] ?? $hotelMap[(string)$apiHotelId] ?? $hotelMap[(int)$apiHotelId] ?? null;

            if (!$dbHotel) {
                continue;
            }

            $hotelId = $dbHotel['hotel_id'];

            try {
                $updateData = [];

                // Extract description
                if (isset($hotelDetail['Description']) && is_array($hotelDetail['Description'])) {
                    foreach ($hotelDetail['Description'] as $desc) {
                        if (isset($desc['Type']) && in_array($desc['Type'], ['PropertyInformation', 'SurroundingArea']) && !empty($desc['Text'])) {
                            $updateData['description'] = $desc['Text'];
                            $updateData['short_description'] = mb_substr($desc['Text'], 0, 500);
                            break;
                        }
                    }
                }

                // Extract address information
                if (isset($hotelDetail['Address']) && is_array($hotelDetail['Address'])) {
                    $addr = $hotelDetail['Address'];
                    if (!empty($addr['Address1'])) {
                        $fullAddr = trim($addr['Address1']);
                        if (!empty($addr['Address2'])) $fullAddr .= ', ' . trim($addr['Address2']);
                        $updateData['address'] = $fullAddr;
                    }
                    if (!empty($addr['City'])) $updateData['city'] = $addr['City'];
                    if (!empty($addr['Zip'])) $updateData['postal_code'] = $addr['Zip'];
                    if (!empty($addr['Tel'])) $updateData['phone'] = $addr['Tel'];
                    if (!empty($addr['Country'])) $updateData['country'] = $addr['Country'];
                }

                // Extract region information
                // if (isset($hotelDetail['Region']) && is_array($hotelDetail['Region'])) {
                //     if (!empty($hotelDetail['Region']['Name'])) $updateData['city'] = $hotelDetail['Region']['Name'];
                //     if (!empty($hotelDetail['Region']['CityId'])) $updateData['city_id'] = $hotelDetail['Region']['CityId'];
                // }

                // Extract rating/stars
                if (isset($hotelDetail['Rating']['Score'])) $updateData['stars'] = $hotelDetail['Rating']['Score'];
                if (isset($hotelDetail['Stars'])) $updateData['stars'] = $hotelDetail['Stars'];

                // Extract coordinates
                if (isset($hotelDetail['GeneralInfo']) && is_array($hotelDetail['GeneralInfo'])) {
                    if (isset($hotelDetail['GeneralInfo']['Latitude'])) $updateData['latitude'] = $hotelDetail['GeneralInfo']['Latitude'];
                    if (isset($hotelDetail['GeneralInfo']['Longitude'])) $updateData['longitude'] = $hotelDetail['GeneralInfo']['Longitude'];
                }

                $updateData['last_sync'] = date('Y-m-d H:i:s');

                if (!empty($updateData)) {
                    $stubaDb->update('stuba_hotels', $updateData, ['hotel_id' => $hotelId]);
                }

                // Process hotel images
                if (isset($hotelDetail['Photo']) && is_array($hotelDetail['Photo'])) {
                    $imageOrder = 0;
                    foreach ($hotelDetail['Photo'] as $photo) {
                        if (!empty($photo['Url'])) {
                            $imageUrl = $config['content_base_url'] . '/' . ltrim($photo['Url'], '/');

                            try {
                                $stubaDb->insert('stuba_hotel_images', [
                                    'hotel_id' => $hotelId,
                                    'image_url' => $imageUrl,
                                    'image_type' => 'photo',
                                    'image_order' => $imageOrder,
                                    'is_primary' => $imageOrder === 0 ? 1 : 0
                                ]);
                                $imageOrder++;
                                $stats['images_added']++;
                            } catch (Exception $e) {
                                continue;
                            }
                        }
                    }
                }

                // Process amenities
                if (isset($hotelDetail['Amenities']) && is_array($hotelDetail['Amenities'])) {
                    foreach ($hotelDetail['Amenities'] as $amenityData) {
                        if (!empty($amenityData['Code'])) {
                            $code = $amenityData['Code'];
                            $amenity = $stubaDb->get('stuba_amenities', 'id', ['amenity_id' => $code]);

                            if ($amenity) {
                                try {
                                    $stubaDb->insert('stuba_hotel_amenities', [
                                        'hotel_id' => $hotelId,
                                        'amenity_id' => $amenity,
                                        'is_free' => 1
                                    ]);
                                    $stats['amenities_added']++;
                                } catch (Exception $e) {
                                    continue;
                                }
                            }
                        }
                    }
                }

                $stats['processed']++;

            } catch (Exception $e) {
                error_log("Error enriching hotel $hotelId: " . $e->getMessage());
            }
        }

        $nextOffset = $offset + $batch_size;
        $isComplete = $nextOffset >= $totalHotels;
        $progressPercent = $totalHotels > 0 ? round(($nextOffset / $totalHotels) * 100, 1) : 0;
        if ($progressPercent > 100) $progressPercent = 100;

        ob_end_clean();

        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'processed' => $stats['processed'],
            'images_added' => $stats['images_added'],
            'amenities_added' => $stats['amenities_added'],
            'total_hotels' => $totalHotels,
            'progress_percent' => $progressPercent,
            'next_offset' => $nextOffset,
            'is_complete' => $isComplete,
            'message' => "Enriched {$stats['processed']} hotels (+{$stats['images_added']} images, +{$stats['amenities_added']} amenities) from {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        ob_end_clean();
        error_log("Stuba hotel enrichment error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// Legacy endpoints for backward compatibility with SOAP API integration
// These maintain your existing functionality while adding environment awareness

// POST /modules/stays/stuba/content_import - Initialize import (Legacy - now environment aware)
$router->post('stays/stuba/content_import', function() use ($db) {
    @ini_set('display_errors', '0');
    @error_reporting(0);
    while (@ob_get_level()) { @ob_end_clean(); }
    @ob_start();
    @header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        // **ADD THIS: Check if this is a completion request**
        if (isset($_POST['complete']) && $_POST['complete'] == '1') {
            $stubaDb->update('stuba_import_log', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'hotels_imported' => $_POST['hotels_imported'] ?? 0,
                'images_imported' => $_POST['images_imported'] ?? 0
            ], [
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1
            ]);
            
            @ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Import marked as complete']);
            exit;
        }
        $mode = $_POST['mode'] ?? 'fresh';
        $config = getStubaApiConfig($db);
        

        if (!$stubaDb) {
            throw new Exception('Failed to get database connection');
        }

        createStubaTables($stubaDb);

        if ($mode === 'fresh') {
            try {
                $stubaDb->query('TRUNCATE TABLE stuba_hotels');
                $stubaDb->query('TRUNCATE TABLE stuba_hotel_images');
                $stubaDb->query('TRUNCATE TABLE stuba_hotel_amenities');
                $stubaDb->query('TRUNCATE TABLE stuba_regions');
                $stubaDb->query('TRUNCATE TABLE stuba_countries');
            } catch (Exception $e) {
                // Tables might not exist yet, ignore
            }
        }

        try {
            $stubaDb->insert('stuba_import_log', [
                'started_at' => date('Y-m-d H:i:s'),
                'mode' => $mode,
                'status' => 'processing',
                'import_type' => 'legacy_soap',
                'total_chunks' => 0,
                'processed_chunks' => 0
            ]);

            $import_id = $stubaDb->id();
        } catch (Exception $e) {
            $import_id = 1;
        }

        @ob_end_clean();

        echo json_encode([
            'success' => true,
            'import_id' => $import_id,
            'mode' => $mode,
            'environment' => $config['environment'],
            'message' => "Import initialized successfully in {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// Continue with other existing endpoints (GET progress, POST cancel, POST process)
// Adding environment awareness to each...

// GET /modules/stays/stuba/content/progress - Get import progress
$router->get('stays/stuba/content/progress', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        $config = getStubaApiConfig($db);

        $import = $stubaDb->get('stuba_import_log', '*', [
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 1
        ]);

        if (!$import) {
            echo json_encode([
                'success' => false,
                'message' => 'No import in progress'
            ]);
            return;
        }

        $progress = [
            'status' => $import['status'],
            'total_chunks' => $import['total_chunks'] ?? 0,
            'processed_chunks' => $import['processed_chunks'] ?? 0,
            'records_processed' => $import['records_processed'] ?? 0,
            'started_at' => $import['started_at'],
            'completed_at' => $import['completed_at'],
            'error' => $import['error'],
            'environment' => $config['environment']
        ];

        echo json_encode([
            'success' => true,
            'data' => $progress
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// POST /modules/stays/stuba/content/cancel - Cancel import
$router->post('stays/stuba/content/cancel', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $stubaDb = getStubaDb();
        $config = getStubaApiConfig($db);

        $stubaDb->update('stuba_import_log', [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ], [
            'status' => 'processing',
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 1
        ]);

        echo json_encode([
            'success' => true,
            'environment' => $config['environment'],
            'message' => "Import cancelled in {$config['environment']} environment"
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

// Create tables function (unchanged)
function createStubaTables($stubaDb) {
    $oldErrorReporting = error_reporting();
    error_reporting(E_ERROR);

    try {
        @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_hotels` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `hotel_id` VARCHAR(100) UNIQUE NOT NULL,
        `hotel_name` VARCHAR(255) NOT NULL,
        `region_id` VARCHAR(100),
        `city_id` VARCHAR(100),
        `city` VARCHAR(100),
        `country` VARCHAR(100),
        `country_code` VARCHAR(10),
        `address` TEXT,
        `postal_code` VARCHAR(20),
        `latitude` DECIMAL(10,7),
        `longitude` DECIMAL(10,7),
        `hotel_type` VARCHAR(50) DEFAULT 'Hotel',
        `stars` INT DEFAULT 0,
        `rating` DECIMAL(3,1) DEFAULT 0.0,
        `category` VARCHAR(50),
        `phone` VARCHAR(50),
        `email` VARCHAR(255),
        `website` VARCHAR(255),
        `fax` VARCHAR(50),
        `checkin_time` VARCHAR(10) DEFAULT '14:00',
        `checkout_time` VARCHAR(10) DEFAULT '11:00',
        `min_checkin_age` INT DEFAULT 18,
        `description` TEXT,
        `short_description` VARCHAR(500),
        `amenities` TEXT,
        `policies` TEXT,
        `total_rooms` INT DEFAULT 0,
        `room_types` TEXT,
        `base_price` DECIMAL(10,2) DEFAULT 0.00,
        `currency` VARCHAR(10) DEFAULT 'USD',
        `is_active` TINYINT DEFAULT 1,
        `is_bookable` TINYINT DEFAULT 1,
        `last_sync` DATETIME,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_hotel_id` (`hotel_id`),
        INDEX `idx_region` (`region_id`),
        INDEX `idx_city_id` (`city_id`),
        INDEX `idx_city` (`city`),
        INDEX `idx_country` (`country`),
        INDEX `idx_stars` (`stars`),
        INDEX `idx_rating` (`rating`),
        INDEX `idx_active` (`is_active`),
        INDEX `idx_type` (`hotel_type`),
        INDEX `idx_location` (`latitude`, `longitude`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_hotel_images` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `hotel_id` VARCHAR(100) NOT NULL,
        `image_url` TEXT NOT NULL,
        `image_type` VARCHAR(50) DEFAULT 'photo',
        `image_order` INT DEFAULT 0,
        `is_primary` TINYINT DEFAULT 0,
        `width` INT,
        `height` INT,
        `caption` VARCHAR(255),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_hotel_id` (`hotel_id`),
        INDEX `idx_primary` (`is_primary`),
        INDEX `idx_type` (`image_type`),
        INDEX `idx_order` (`image_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_amenities` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `amenity_id` VARCHAR(50) UNIQUE NOT NULL,
        `amenity_name` VARCHAR(255) NOT NULL,
        `category` VARCHAR(50),
        `icon` VARCHAR(50),
        `is_popular` TINYINT DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_amenity_id` (`amenity_id`),
        INDEX `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert default amenities
    $defaultAmenities = [
        ['wifi', 'Free WiFi', 'internet', 'wifi', 1],
        ['parking', 'Free Parking', 'parking', 'local_parking', 1],
        ['pool', 'Swimming Pool', 'recreation', 'pool', 1],
        ['gym', 'Fitness Center', 'recreation', 'fitness_center', 1],
        ['restaurant', 'Restaurant', 'dining', 'restaurant', 1],
        ['spa', 'Spa', 'wellness', 'spa', 1],
        ['breakfast', 'Breakfast', 'dining', 'free_breakfast', 1],
        ['ac', 'Air Conditioning', 'room', 'ac_unit', 0],
        ['tv', 'TV', 'room', 'tv', 0]
    ];

    foreach ($defaultAmenities as $amenity) {
        try {
            $stubaDb->query("INSERT IGNORE INTO `stuba_amenities`
                (`amenity_id`, `amenity_name`, `category`, `icon`, `is_popular`)
                VALUES (
                    " . $stubaDb->quote($amenity[0]) . ",
                    " . $stubaDb->quote($amenity[1]) . ",
                    " . $stubaDb->quote($amenity[2]) . ",
                    " . $stubaDb->quote($amenity[3]) . ",
                    " . (int)$amenity[4] . "
                )");
        } catch (Exception $e) {
            // Ignore
        }
    }

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_hotel_amenities` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `hotel_id` VARCHAR(100) NOT NULL,
        `amenity_id` VARCHAR(50) NOT NULL,
        `is_free` TINYINT DEFAULT 1,
        `notes` VARCHAR(255),
        UNIQUE KEY `unique_hotel_amenity` (`hotel_id`, `amenity_id`),
        INDEX `idx_hotel_id` (`hotel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_regions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `region_id` VARCHAR(100) UNIQUE NOT NULL,
        `region_name` VARCHAR(255) NOT NULL,
        `region_type` VARCHAR(50),
        `country` VARCHAR(100),
        `country_region_id` VARCHAR(100),
        `country_code` VARCHAR(10),
        `parent_region_id` VARCHAR(100),
        `latitude` DECIMAL(10,7),
        `longitude` DECIMAL(10,7),
        `total_hotels` INT DEFAULT 0,
        `is_popular` TINYINT DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_region_id` (`region_id`),
        INDEX `idx_country` (`country`),
        INDEX `idx_popular` (`is_popular`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_countries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `region_id` VARCHAR(50) UNIQUE NOT NULL,
        `region_name` VARCHAR(255) NOT NULL,
        `code` VARCHAR(10),
        `continent` VARCHAR(50),
        `total_hotels` INT DEFAULT 0,
        `is_active` TINYINT DEFAULT 1,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_region_id` (`region_id`),
        INDEX `idx_region_name` (`region_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_room_types` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `hotel_id` VARCHAR(100) NOT NULL,
        `room_id` VARCHAR(100) NOT NULL,
        `room_name` VARCHAR(255) NOT NULL,
        `description` TEXT,
        `max_occupancy` INT DEFAULT 2,
        `size_sqm` DECIMAL(8,2),
        `bed_type` VARCHAR(100),
        `amenities` TEXT,
        `base_price` DECIMAL(10,2) DEFAULT 0.00,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_room` (`hotel_id`, `room_id`),
        INDEX `idx_hotel_id` (`hotel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$stubaDb->query("CREATE TABLE IF NOT EXISTS `stuba_import_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `import_type` VARCHAR(50) DEFAULT 'full',
        `started_at` DATETIME NOT NULL,
        `completed_at` DATETIME,
        `mode` VARCHAR(20),
        `status` VARCHAR(20),
        `total_chunks` INT DEFAULT 0,
        `processed_chunks` INT DEFAULT 0,
        `records_processed` INT DEFAULT 0,
        `hotels_imported` INT DEFAULT 0,
        `images_imported` INT DEFAULT 0,
        `errors_count` INT DEFAULT 0,
        `error` TEXT,
        INDEX `idx_status` (`status`),
        INDEX `idx_type` (`import_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    } finally {
        error_reporting($oldErrorReporting);
    }
}