<?php

// app/routes/admin/modulesRoutes.php
@$SECURE or die('Access Denied!');

$router->get(admin.'/settings/modules', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Modules';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules.php";
    require_once views."includes/footer.php";

});

$router->get(admin.'/settings/modules/list', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Modules List';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/list.php";
    require_once views."includes/footer.php";

});

// GET route for adding new module
$router->get(admin.'/settings/modules/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add Module';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/manage.php";
    require_once views."includes/footer.php";

});

// GET route for editing existing module
$router->get(admin.'/settings/modules/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit Module';
    $description = '';
    $header = true;
    $footer = true;

    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/manage.php";
    require_once views."includes/footer.php";

});

// POST route for adding new module
$router->post(admin.'/settings/modules/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_module') {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/modules/add');
        }

        try {
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'type' => trim($_POST['type'] ?? ''),
                'currency' => strtoupper(trim($_POST['currency'] ?? 'USD')),
                'order' => (int)($_POST['order'] ?? 0),
                'icon' => trim($_POST['icon'] ?? ''),
                'module_color' => trim($_POST['module_color'] ?? ''),
                'dev_mode' => $_POST['dev_mode'] ?? '0',
                'payment_mode' => $_POST['payment_mode'] ?? '0',
                'prn_type' => $_POST['prn_type'] ?? '0',
                'check_balance' => $_POST['check_balance'] ?? '1',
                'content_import' => $_POST['content_import'] ?? '0',
                'import_database' => (int)($_POST['import_database'] ?? 0),
                'logging_enabled' => (int)($_POST['logging_enabled'] ?? 1),
                'ancillaries_enabled' => (int)($_POST['ancillaries_enabled'] ?? 0),
                'emd_enabled' => (int)($_POST['emd_enabled'] ?? 0),
                'markup_type_b2c' => $_POST['markup_type_b2c'] ?? 'percentage',
                'markup_b2c' => (int)($_POST['markup_b2c'] ?? 0),
                'markup_type_b2b' => $_POST['markup_type_b2b'] ?? 'percentage',
                'markup_b2b' => $_POST['markup_b2b'] ?? '0',
                'tax_type' => $_POST['tax_type'] ?? 'percentage',
                'tax' => (int)($_POST['tax'] ?? 0),
                'status' => $_POST['status'] ?? '1',
                'active' => $_POST['active'] ?? '1',
                'host' => trim($_POST['host'] ?? ''),
                'database' => trim($_POST['database'] ?? ''),
                'username' => trim($_POST['username'] ?? ''),
                'password' => $_POST['password'] ?? '',
            ];

            for ($i = 1; $i <= 6; $i++) {
                $data['c' . $i] = $_POST['c' . $i] ?? '';
            }

            $errors = [];

            if (empty($data['name'])) {
                $errors[] = 'Module name is required';
            }

            $validTypes = ['flights','stays','tours','cars','bus','rail','cruises','visa','insurance','umrah','hajj','events','esim','ferries'];
            if (empty($data['type']) || !in_array($data['type'], $validTypes)) {
                $errors[] = 'Valid module type is required';
            }

            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            $duplicate = $db->get('modules', 'id', [
                'name' => $data['name'],
                'type' => $data['type']
            ]);
            if ($duplicate) {
                throw new Exception('A module with this name and type already exists');
            }

            $result = $db->insert('modules', $data);
            $insertId = $db->id();

            if ($result && $insertId) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => 'Module added successfully'
                ];
                redirect(root . admin . '/settings/modules/list');
            } else {
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                }
                throw new Exception('Failed to add module. Please check all fields and try again.');
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            redirect(root . admin . '/settings/modules/add');
        }
    }

    $title = 'Add Module';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/manage.php";
    require_once views."includes/footer.php";

});

// POST route for editing existing module
$router->post(admin.'/settings/modules/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $moduleId = (int)$id;

    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_module') {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/modules/edit/' . $moduleId);
        }

        try {
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'type' => trim($_POST['type'] ?? ''),
                'currency' => strtoupper(trim($_POST['currency'] ?? 'USD')),
                'order' => (int)($_POST['order'] ?? 0),
                'icon' => trim($_POST['icon'] ?? ''),
                'module_color' => trim($_POST['module_color'] ?? ''),
                'dev_mode' => $_POST['dev_mode'] ?? '0',
                'payment_mode' => $_POST['payment_mode'] ?? '0',
                'prn_type' => $_POST['prn_type'] ?? '0',
                'check_balance' => $_POST['check_balance'] ?? '1',
                'content_import' => $_POST['content_import'] ?? '0',
                'import_database' => (int)($_POST['import_database'] ?? 0),
                'logging_enabled' => (int)($_POST['logging_enabled'] ?? 1),
                'ancillaries_enabled' => (int)($_POST['ancillaries_enabled'] ?? 0),
                'emd_enabled' => (int)($_POST['emd_enabled'] ?? 0),
                'markup_type_b2c' => $_POST['markup_type_b2c'] ?? 'percentage',
                'markup_b2c' => (int)($_POST['markup_b2c'] ?? 0),
                'markup_type_b2b' => $_POST['markup_type_b2b'] ?? 'percentage',
                'markup_b2b' => $_POST['markup_b2b'] ?? '0',
                'tax_type' => $_POST['tax_type'] ?? 'percentage',
                'tax' => (int)($_POST['tax'] ?? 0),
                'status' => $_POST['status'] ?? '1',
                'active' => $_POST['active'] ?? '1',
                'host' => trim($_POST['host'] ?? ''),
                'database' => trim($_POST['database'] ?? ''),
                'username' => trim($_POST['username'] ?? ''),
                'password' => $_POST['password'] ?? '',
            ];

            for ($i = 1; $i <= 6; $i++) {
                $data['c' . $i] = $_POST['c' . $i] ?? '';
            }

            $errors = [];

            if (empty($data['name'])) {
                $errors[] = 'Module name is required';
            }

            $validTypes = ['flights','stays','tours','cars','bus','rail','cruises','visa','insurance','umrah','hajj','events','esim','ferries'];
            if (empty($data['type']) || !in_array($data['type'], $validTypes)) {
                $errors[] = 'Valid module type is required';
            }

            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            $duplicate = $db->get('modules', 'id', [
                'name' => $data['name'],
                'type' => $data['type'],
                'id[!]' => $moduleId
            ]);
            if ($duplicate) {
                throw new Exception('A module with this name and type already exists');
            }

            $result = $db->update('modules', $data, ['id' => $moduleId]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => 'Module updated successfully'
                ];
                redirect(root . admin . '/settings/modules/list');
            } else {
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                }
                throw new Exception('Failed to update module. Please check all fields and try again.');
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            redirect(root . admin . '/settings/modules/edit/' . $moduleId);
        }
    }

    $title = 'Edit Module';
    $description = '';
    $header = true;
    $footer = true;

    $_GET['id'] = $moduleId;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/manage.php";
    require_once views."includes/footer.php";

});

// Toggle module status route
$router->post(admin.'/settings/modules/toggle', function () use ($db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    $redirectHash = '';

    if (isset($_POST['module_id']) && isset($_POST['status'])) {
        $moduleId = (int)$_POST['module_id'];
        $status = (int)$_POST['status'];

        // Get module type for hash and reordering
        $module = $db->get('modules', ['type', 'id'], ['id' => $moduleId]);
        if ($module) {
            $redirectHash = '#' . $module['type'];

            // Update module status
            $result = $db->update('modules', [
                'status' => $status
            ], [
                'id' => $moduleId
            ]);

            if ($result !== false) {
                // Reorder all modules of this type
                $moduleType = $module['type'];

                // Get all modules of this type
                $typeModules = $db->select('modules', ['id', 'status', 'order'], [
                    'type' => $moduleType,
                    'ORDER' => [
                        'status' => 'DESC',  // Active first
                        'order' => 'ASC'     // Then by current order
                    ]
                ]);

                // Update order in database
                $newOrder = 1;
                foreach ($typeModules as $mod) {
                    $db->update('modules', [
                        'order' => $newOrder
                    ], [
                        'id' => $mod['id']
                    ]);
                    $newOrder++;
                }

                $_SESSION['message'] = [
                    'type' => 'success',
                    'key' => 'module_status_updated',
                    'text' => 'Module status updated successfully'
                ];
            } else {
                $_SESSION['message'] = [
                    'type' => 'error',
                    'key' => 'error_updating_module',
                    'text' => 'Failed to update module status'
                ];
            }
        }
    }

    // Redirect back to modules page with hash
    header('Location: ' . root . admin . '/settings/modules' . $redirectHash);
    exit;

});

// Module settings page
$router->get(admin.'/settings/modules/(\d+)', function ($moduleId) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    ensurePassportAiSchema($db);

    // Get module details
    $module = $db->get('modules', '*', ['id' => $moduleId]);
    $module['env'] = $module['dev_mode'] == '1' ? 'test' : 'live';
    if (!$module) {
        $_SESSION['message'] = [
            'type' => 'error',
            'key' => 'module_not_found',
            'text' => 'Module not found'
        ];
        header('Location: ' . root . admin . '/settings/modules');
        exit;
    }

    if (strtolower($module['name']) === 'hotelbeds') {
        $hotelbedsSettings = readHotelbedsSettings();
        $module['use_mtls'] = $hotelbedsSettings['use_mtls'] ?? 0;
        $module['allow_packaging_rates'] = $hotelbedsSettings['allow_packaging_rates'] ?? 0;
    }

    // Get currencies from database with country names
    $currencies = [];
    try {
        // Check if currencies table exists and get sample data
        $currenciesResult = $db->select('currencies', '*', ['LIMIT' => 1]);
        if (!empty($currenciesResult)) {
            // Check what columns exist in the first row
            $sampleCurrency = $currenciesResult[0];
            $hasStatusColumn = isset($sampleCurrency['status']);
            $hasCountryColumn = isset($sampleCurrency['country']);

            // Build the condition based on available columns
            $condition = [];
            if ($hasStatusColumn) {
                $condition['currencies.status'] = 1;
            }

            if ($hasCountryColumn) {
                // Join with countries table to get country nicename
                $currencies = $db->select('currencies', [
                    '[>]countries' => ['country' => 'iso']
                ], [
                    'currencies.id',
                    'currencies.name(currency_code)',
                    'currencies.country',
                    'currencies.rate',
                    'countries.nicename(country_name)'
                ], array_merge($condition, ['ORDER' => ['currencies.id' => 'ASC']]));
            } else {
                // Get currencies without country join
                $currencies = $db->select('currencies', '*', array_merge(['status' => 1], ['ORDER' => ['id' => 'ASC']]));
            }
        }
    } catch (Exception $e) {
        // If currencies table doesn't exist or error, leave array empty
        $currencies = [];
    }

    // META DATA
    $title = ucfirst($module['name']) . ' Settings';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules-settings.php";
    require_once views."includes/footer.php";

});

// Update module settings
$router->post(admin.'/settings/modules/(\d+)', function ($moduleId) use ($SECURE,$db) {

    // Handle AJAX updates for individual fields FIRST (before any output)
    if (isset($_POST['ajax_update'])) {
        // Turn off error reporting for clean JSON
        error_reporting(0);
        ini_set('display_errors', 0);

        // Clean any previous output and start fresh
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        // ADMIN AUTH CHECK for AJAX
        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            ob_clean();
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }

        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $moduleId = $_POST['module_id'] ?? $moduleId;

        $response = ['success' => false, 'message' => ''];

        try {
            // Get module details
            $module = $db->get('modules', '*', ['id' => $moduleId]);

            // Validate field and value
            if (in_array($field, ['status', 'dev_mode', 'ancillaries_enabled', 'emd_enabled']) && in_array($value, ['0', '1'])) {
                $updateData = [$field => (string)$value];

                $result = $db->update('modules', $updateData, ['id' => $moduleId]);

                if ($result !== false) {
                    $response['success'] = true;
                    $response['message'] = 'Setting updated successfully';
                } else {
                    $response['message'] = 'Failed to update database';
                    $response['previous_value'] = $module[$field];
                }
            } else {
                $response['message'] = 'Invalid field or value';
                $response['previous_value'] = $module[$field] ?? '';
            }
        } catch (Exception $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
            $response['previous_value'] = $module[$field] ?? '';
        }

        // Clean any unwanted output and ensure nothing was written
        $unwantedOutput = ob_get_contents();
        ob_clean();

        // Log any unwanted output for debugging (remove this later)
        if (!empty($unwantedOutput)) {
            error_log("Unwanted output before JSON: " . var_export($unwantedOutput, true));
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        echo json_encode($response);
        exit;
    }

    // ADMIN AUTH CHECK for regular requests
    ADMIN_AUTH();

    // Get module details first
    $module = $db->get('modules', '*', ['id' => $moduleId]);

    if (isset($_POST['save_settings'])) {
        $devModeValue = null;
        if (array_key_exists('dev_mode', $_POST)) {
            $devModeValue = (string)$_POST['dev_mode'];
        }

        $updateData = [
            'tax' => $_POST['tax'] ?? 0,
            'tax_type' => $_POST['tax_type'] ?? 'percentage',
            'markup_type_b2b' => $_POST['markup_type_b2b'] ?? 'percentage',
            'markup_b2b' => $_POST['markup_b2b'] ?? 0,
            'markup_type_b2c' => $_POST['markup_type_b2c'] ?? 'percentage',
            'markup_b2c' => $_POST['markup_b2c'] ?? 0,
            'currency' => $_POST['currency'] ?? 'USD',
            'dev_mode' => $devModeValue !== null ? $devModeValue : $module['dev_mode'],
            'payment_mode' => isset($_POST['payment_mode']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
            'status' => isset($_POST['hidden_status']) ? (int)$_POST['hidden_status'] : $module['status']
        ];

        // Save boolean feature flags for flight modules
        if ($module['type'] == 'flights') {
            if (isset($_POST['ancillaries_enabled'])) $updateData['ancillaries_enabled'] = $_POST['ancillaries_enabled'] ? 1 : 0;
            if (isset($_POST['emd_enabled'])) $updateData['emd_enabled'] = $_POST['emd_enabled'] ? 1 : 0;
        }

        // Add credential fields (c1-c6)
        for ($i = 1; $i <= 6; $i++) {
            $field = 'c' . $i;
            if (isset($_POST[$field])) {
                $updateData[$field] = $_POST[$field];
            }
        }

        // Add database configuration fields (separate content DB modules)
        if (in_array(strtolower($module['name']), ['hotelbeds', 'hotelston', 'stuba', 'agoda', 'ratehawk', 'tbo-holidays', 'wanderbeds', 'toursbms'])) {
            if (isset($_POST['host'])) {
                $updateData['host'] = $_POST['host'];
            }
            if (isset($_POST['database'])) {
                $updateData['database'] = $_POST['database'];
            }
            if (isset($_POST['username'])) {
                $updateData['username'] = $_POST['username'];
            }
            if (isset($_POST['password'])) {
                $updateData['password'] = $_POST['password'];
            }
        }

        if (strtolower($module['name']) === 'hotelbeds') {
            $useMtls = isset($_POST['use_mtls']) ? 1 : 0;
            updateHotelbedsSetting('use_mtls', $useMtls);
        }

        $result = $db->update('modules', $updateData, ['id' => $moduleId]);

        if ($result !== false) {
            $_SESSION['message'] = [
                'type' => 'success',
                'key' => 'module_settings_saved',
                'text' => 'Module settings updated successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'key' => 'error_saving_settings',
                'text' => 'Failed to update module settings'
            ];
        }
    }

    // Redirect back to settings page
    header('Location: ' . root . admin . '/settings/modules/' . $moduleId);
    exit;

});

// Rail train — station catalog import (module settings tab)
$router->post(admin . '/settings/modules/rail/import-stations', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json; charset=utf-8');
    require_once dirname(__DIR__, 3) . '/modules/rail/train/stations.php';
    try {
        echo json_encode(_train_import_stations($db));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

// API Test endpoint
$router->post(admin.'/settings/modules/(\d+)/test', function ($moduleId) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    // Get module details
    $module = $db->get('modules', '*', ['id' => $moduleId]);

    if (!$module) {
        echo json_encode(['success' => false, 'error' => 'Module not found']);
        exit;
    }

    // Get test credentials from POST
    $testCredentials = [];
    for ($i = 1; $i <= 6; $i++) {
        $field = 'c' . $i;
        if (isset($_POST[$field]) && !empty($_POST[$field])) {
            $testCredentials[$field] = $_POST[$field];
        }
    }

    // Test API connection based on module type and name
    $testResult = testModuleAPI($module, $testCredentials);

    echo json_encode($testResult);
    exit;

});

// API Testing function
function testModuleAPI($module, $credentials) {
    $moduleName = strtolower($module['name']);
    $moduleType = strtolower($module['type']);

    try {
        // Test based on module type and provider
        switch ($moduleType) {
            case 'hotels':
                return testHotelAPI($moduleName, $credentials, $module['dev_mode']);

            case 'flights':
                return testFlightAPI($moduleName, $credentials, $module['dev_mode']);

            case 'cars':
                return testCarAPI($moduleName, $credentials, $module['dev_mode']);

            case 'tours':
                return testTourAPI($moduleName, $credentials, $module['dev_mode']);

            case 'visa':
                return testVisaAPI($moduleName, $credentials, $module['dev_mode']);

            case 'rail':
                return testRailAPI($moduleName, $credentials, $module['dev_mode']);

            default:
                return testGenericAPI($moduleName, $credentials, $module['dev_mode']);
        }

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Test failed: ' . $e->getMessage(),
            'details' => ['exception' => $e->getTrace()]
        ];
    }
}

function testHotelAPI($provider, $credentials, $devMode) {
    switch ($provider) {
        case 'agoda':
            if (empty($credentials['c1'])) {
                return ['success' => false, 'error' => 'API Key is required'];
            }

            $url = $devMode ? 'https://affiliateapi7643.agoda.com/PartnerAPI/HotelApiService.svc/GetCityList'
                           : 'https://affiliateapi.agoda.com/PartnerAPI/HotelApiService.svc/GetCityList';

            $response = makeAPIRequest($url, [
                'apikey' => $credentials['c1'],
                'cid' => $credentials['c2'] ?? ''
            ]);

            return $response;

        case 'booking':
            if (empty($credentials['c1']) || empty($credentials['c2'])) {
                return ['success' => false, 'error' => 'Username and Password are required'];
            }

            return [
                'success' => true,
                'response' => ['message' => 'Booking.com credentials format validated', 'username' => $credentials['c1']]
            ];

        default:
            return ['success' => false, 'error' => 'Unknown hotel provider: ' . $provider];
    }
}

function testFlightAPI($provider, $credentials, $devMode) {
    switch ($provider) {
        case 'amadeus':
            if (empty($credentials['c1']) || empty($credentials['c2'])) {
                return ['success' => false, 'error' => 'API Key and Secret are required'];
            }

            $url = $devMode ? 'https://test.api.amadeus.com/v1/security/oauth2/token'
                           : 'https://api.amadeus.com/v1/security/oauth2/token';

            $response = makeAPIRequest($url, [
                'grant_type' => 'client_credentials',
                'client_id' => $credentials['c1'],
                'client_secret' => $credentials['c2']
            ], 'POST');

            return $response;

        default:
            return ['success' => false, 'error' => 'Unknown flight provider: ' . $provider];
    }
}

function testCarAPI($provider, $credentials, $devMode) {
    return [
        'success' => true,
        'response' => ['message' => 'Car API test not yet implemented for ' . $provider]
    ];
}

function testTourAPI($provider, $credentials, $devMode) {
    return [
        'success' => true,
        'response' => ['message' => 'Tour API test not yet implemented for ' . $provider]
    ];
}

function testVisaAPI($provider, $credentials, $devMode) {
    return [
        'success' => true,
        'response' => ['message' => 'Visa API test not yet implemented for ' . $provider]
    ];
}

function testRailAPI($provider, $credentials, $devMode) {
    if ($provider === 'train') {
        $apiKey = trim($credentials['c1'] ?? '');
        $baseUrl = trim($credentials['c2'] ?? '');

        if ($apiKey === '' || $baseUrl === '') {
            return [
                'success' => false,
                'error' => 'API Key and Base URL are required.'
            ];
        }

        // Make a test trainQuery request
        $url = rtrim($baseUrl, '/') . '/ticket/trainQuery';
        $payload = [
            'from_station_code' => 'IDPGA',
            'to_station_code' => 'IDTLA',
            'from_date' => time() + 7 * 86400, // 7 days from now
            'journey_type' => 3
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => 'Connection Failed: ' . $error
            ];
        }

        $decoded = json_decode($response, true);
        if ($httpCode === 200 && isset($decoded['code']) && ($decoded['code'] == 200 || $decoded['code'] == '200')) {
            return [
                'success' => true,
                'response' => [
                    'message' => 'Train Booking API is connected successfully! Server responded with 200 OK.',
                    'details' => $decoded
                ]
            ];
        } else {
            return [
                'success' => false,
                'error' => 'API returned error code ' . ($decoded['code'] ?? $httpCode) . ': ' . ($decoded['msg'] ?? 'Unknown error')
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'Unsupported Rail provider: ' . $provider
    ];
}

function testGenericAPI($provider, $credentials, $devMode) {
    return [
        'success' => true,
        'response' => [
            'message' => 'Generic API test for ' . $provider,
            'credentials_count' => count($credentials),
            'dev_mode' => $devMode
        ]
    ];
}

function makeAPIRequest($url, $data, $method = 'GET') {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'PHPTravels-API-Test/1.0'
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } else {
        if (!empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);


    if ($error) {
        return [
            'success' => false,
            'error' => 'CURL Error: ' . $error,
            'details' => ['http_code' => $httpCode]
        ];
    }

    if ($httpCode >= 400) {
        return [
            'success' => false,
            'error' => 'HTTP Error: ' . $httpCode,
            'details' => ['response' => $response]
        ];
    }

    $decoded = json_decode($response, true);

    return [
        'success' => true,
        'response' => $decoded ?: $response,
        'details' => ['http_code' => $httpCode]
    ];
}

// AJAX route for module field updates
$router->post(admin.'/settings/modules/ajax-update', function () use ($db) {

    // Clean output buffer and start fresh
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    // Disable profiler/debug for AJAX requests
    if (defined('PROFILER_ENABLED')) {
        define('PROFILER_ENABLED', false);
    }
    global $PROFILER_ENABLED;
    $PROFILER_ENABLED = false;

    // Set JSON headers
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Requested-With: XMLHttpRequest');

    try {
        // Validate request
        if (!isset($_POST['ajax_update']) || !isset($_POST['field']) || !isset($_POST['value']) || !isset($_POST['module_id'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            die();
        }

        $field = $_POST['field'];
        $value = $_POST['value'];
        $moduleId = (int)$_POST['module_id'];

        $response = ['success' => false, 'message' => ''];

        // Validate field and value
        if (in_array($field, ['status', 'dev_mode', 'ancillaries_enabled', 'emd_enabled']) && in_array($value, ['0', '1', 0, 1])) {
            // Get module details
            $module = $db->get('modules', '*', ['id' => $moduleId]);

            if ($module) {
                $updateData = [$field => (int)$value];
                $result = $db->update('modules', $updateData, ['id' => $moduleId]);

                if ($result !== false) {
                    $response['success'] = true;
                    $response['message'] = 'Setting updated successfully';
                } else {
                    $response['message'] = 'Failed to update database';
                    $response['previous_value'] = $module[$field];
                }
            } else {
                $response['message'] = 'Module not found';
            }
        } elseif ($field === 'currency' && is_string($value) && trim($value) !== '') {
            $module = $db->get('modules', '*', ['id' => $moduleId]);

            if ($module) {
                $currency = strtoupper(trim($value));
                $updateData = ['currency' => $currency];
                $result = $db->update('modules', $updateData, ['id' => $moduleId]);

                if ($result !== false) {
                    $response['success'] = true;
                    $response['message'] = 'Currency updated successfully';
                    $response['value'] = $currency;
                } else {
                    $response['message'] = 'Failed to update database';
                    $response['previous_value'] = $module['currency'] ?? 'USD';
                }
            } else {
                $response['message'] = 'Module not found';
            }
        } else {
            $response['message'] = 'Invalid field or value';
        }

    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    // Clean any unwanted output
    ob_clean();

    // Output clean JSON
    echo json_encode($response);

    // Force flush and exit
    if (ob_get_level()) {
        ob_end_flush();
    }

    // Force termination to prevent profiler output
    die();

});

// Test Database Connection endpoint
$router->post(admin.'/settings/modules/test-db', function () use ($db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Clean output buffer and set headers
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        // Validate request
        if (!isset($_POST['host']) || !isset($_POST['database']) || !isset($_POST['username'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit;
        }

        $host = $_POST['host'];
        $database = $_POST['database'];
        $username = $_POST['username'];
        $password = $_POST['password'] ?? '';

        // Try to connect to the database
        try {
            $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $testDb = new PDO($dsn, $username, $password, $options);

            // Test the connection by running a simple query
            $testDb->query("SELECT 1");

            // Get database info
            $stmt = $testDb->query("SELECT DATABASE() as db_name, VERSION() as version");
            $info = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => 'Connection successful! Database: ' . $info['db_name'] . ' (MySQL ' . $info['version'] . ')',
                'details' => [
                    'database' => $info['db_name'],
                    'version' => $info['version'],
                    'host' => $host
                ]
            ]);
            exit;

        } catch (PDOException $e) {

            // Parse error message for user-friendly response
            $errorMsg = $e->getMessage();
            $friendlyMsg = 'Connection failed: ';

            if (strpos($errorMsg, 'Access denied') !== false) {
                $friendlyMsg .= 'Invalid username or password';
            } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                $friendlyMsg .= 'Database does not exist. Please create it first.';
            } elseif (strpos($errorMsg, "Can't connect") !== false || strpos($errorMsg, 'Connection refused') !== false) {
                $friendlyMsg .= 'Cannot reach database server. Check host and port.';
            } else {
                $friendlyMsg .= $errorMsg;
            }

            echo json_encode([
                'success' => false,
                'message' => $friendlyMsg,
                'error_details' => $errorMsg
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }

    exit;
});

$router->post(admin.'/settings/modules/upload-certs', function () use ($db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    try {
        // Validate module ID
        if (!isset($_POST['module_id'])) {
            echo json_encode(['success' => false, 'message' => 'Module ID is required']);
            exit;
        }

        $moduleId = (int)$_POST['module_id'];

        // Get module
        $module = $db->get('modules', '*', ['id' => $moduleId]);
        if (!$module) {
            echo json_encode(['success' => false, 'message' => 'Module not found']);
            exit;
        }

        // Check if at least one file is provided
        $hasFiles = isset($_FILES['client_cert']) || isset($_FILES['client_key']) || isset($_FILES['ca_bundle']);
        if (!$hasFiles) {
            echo json_encode(['success' => false, 'message' => 'At least one certificate file is required']);
            exit;
        }

        // Define certs directory
        $certsDir = __DIR__ . '/../../../modules/stays/hotelbeds/certs/';

        // Create certs directory if it doesn't exist
        if (!file_exists($certsDir)) {
            if (!mkdir($certsDir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Failed to create certificates directory']);
                exit;
            }
        }

        // File configurations
        $files = [
            'client_cert' => ['name' => 'client.pem', 'allowed_ext' => 'pem', 'label' => 'Client Certificate'],
            'client_key' => ['name' => 'client.key', 'allowed_ext' => 'key', 'label' => 'Private Key'],
            'ca_bundle' => ['name' => 'ca_bundle.crt', 'allowed_ext' => 'crt', 'label' => 'CA Bundle']
        ];

        $uploadedFiles = [];
        $skippedFiles = [];

        // Process each file (only if provided)
        foreach ($files as $fileKey => $fileConfig) {
            // Skip if file not provided
            if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
                $skippedFiles[] = $fileConfig['label'];
                continue;
            }

            $file = $_FILES[$fileKey];

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => "Upload error for {$fileConfig['label']}"]);
                exit;
            }

            // Validate file extension
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($fileExt !== $fileConfig['allowed_ext']) {
                echo json_encode(['success' => false, 'message' => "{$fileConfig['label']} must be a .{$fileConfig['allowed_ext']} file"]);
                exit;
            }

            // Define destination path
            $destinationPath = $certsDir . $fileConfig['name'];

            // Delete old file if exists
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
                echo json_encode(['success' => false, 'message' => "Failed to save {$fileConfig['label']}"]);
                exit;
            }

            // Set proper permissions
            chmod($destinationPath, 0600);

            $uploadedFiles[] = $fileConfig['label'];
        }

        // Check if any files were actually uploaded
        if (empty($uploadedFiles)) {
            echo json_encode(['success' => false, 'message' => 'No files were uploaded']);
            exit;
        }

        // Update module to enable mTLS
        updateHotelbedsSetting('use_mtls', 1);

        // Build success message
        $uploadCount = count($uploadedFiles);
        if ($uploadCount === 1) {
            $message = $uploadedFiles[0] . ' uploaded successfully!';
        } elseif ($uploadCount === 2) {
            $message = $uploadedFiles[0] . ' and ' . $uploadedFiles[1] . ' uploaded successfully!';
        } else {
            $message = 'All certificates uploaded successfully!';
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'uploaded' => $uploadedFiles,
            'skipped' => $skippedFiles
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
});

// Test mTLS Certificates
$router->post(admin.'/settings/modules/test-mtls', function () use ($db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    try {
        // Validate module ID
        if (!isset($_POST['module_id'])) {
            echo json_encode(['status' => false, 'message' => 'Module ID is required']);
            exit;
        }

        $moduleId = (int)$_POST['module_id'];

        // Get module
        $module = $db->get('modules', '*', ['id' => $moduleId]);
        if (!$module || strtolower($module['name']) !== 'hotelbeds') {
            echo json_encode(['status' => false, 'message' => 'Invalid module']);
            exit;
        }

        // Get credentials
        $apiKey = $_POST['c1'] ?? $module['c1'] ?? '';
        $apiSecret = $_POST['c2'] ?? $module['c2'] ?? '';
        $environment = $_POST['env'] ?? ($module['dev_mode'] ? 'test' : 'live');

        if (empty($apiKey) || empty($apiSecret)) {
            echo json_encode(['status' => false, 'message' => 'API credentials are required']);
            exit;
        }

        // Define certificate paths
        $certDir = __DIR__ . '/../../../modules/stays/hotelbeds/certs/';
        $certFile = $certDir . 'client.pem';
        $keyFile = $certDir . 'client.key';
        $caFile = $certDir . 'ca_bundle.crt';

        // Check if certificates exist
        if (!file_exists($certFile) || !file_exists($keyFile) || !file_exists($caFile)) {
            echo json_encode([
                'status' => false,
                'message' => 'Certificates not found. Please upload all required files first.',
                'missing_files' => [
                    'client.pem' => !file_exists($certFile),
                    'client.key' => !file_exists($keyFile),
                    'ca_bundle.crt' => !file_exists($caFile)
                ]
            ]);
            exit;
        }

        // Determine API URL based on environment
        $url = $environment === 'test'
            ? 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0/status'
            : 'https://api-mtls.hotelbeds.com/hotel-api/1.0/status';

        // Generate X-Signature
        $xSignature = hash('sha256', $apiKey . $apiSecret . time());

        // Prepare headers
        $headers = [
            "Api-Key: $apiKey",
            "X-Signature: $xSignature",
            "Accept: application/json"
        ];

        // Initialize cURL
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_CAINFO => $caFile,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_VERBOSE => false
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);


        // Handle response
        if ($error) {
            echo json_encode([
                'status' => false,
                'message' => 'Certificate test failed: Connection error',
                'error' => $error,
                'error_details' => 'cURL error: ' . $error
            ]);
            exit;
        }

        if ($httpCode != 200) {
            $responseData = json_decode($response, true);
            echo json_encode([
                'status' => false,
                'message' => "Hotelbeds responded with HTTP $httpCode",
                'error_details' => "HTTP Code: $httpCode",
                'response' => $responseData ?? $response
            ]);
            exit;
        }

        // Success
        $responseData = json_decode($response, true);
        echo json_encode([
            'status' => true,
            'success' => true,
            'message' => 'Certificates are valid! Hotelbeds mTLS connection successful.',
            'response' => $responseData,
            'http_code' => $httpCode,
            'environment' => $environment
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'Test failed: ' . $e->getMessage(),
            'error_details' => $e->getTrace()
        ]);
        exit;
    }
});

$router->post(admin.'/settings/modules/hotelbeds/update-setting', function () use ($db) {

    // Clean output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        // Validate request
        if (!isset($_POST['module_id']) || !isset($_POST['setting_key']) || !isset($_POST['setting_value'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit;
        }

        $moduleId = (int)$_POST['module_id'];
        $settingKey = $_POST['setting_key'];
        $settingValue = $_POST['setting_value'];

        // Get module details and verify it's Hotelbeds
        $module = $db->get('modules', ['name', 'type'], ['id' => $moduleId]);

        if (!$module || strtolower($module['name']) !== 'hotelbeds') {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid module - this route is only for Hotelbeds']);
            exit;
        }

        // Validate setting key
        $allowedKeys = ['use_mtls', 'allow_packaging_rates'];
        if (!in_array($settingKey, $allowedKeys, true)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid setting key']);
            exit;
        }

        $settingValue = (int)$settingValue;

        // Update setting in Hotelbeds settings.json
        $result = updateHotelbedsSetting($settingKey, $settingValue);

        if ($result) {
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Hotelbeds setting updated successfully'
            ]);
        } else {
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Failed to write Hotelbeds settings file'
            ]);
        }

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }

    exit;
});

// =============================================================================
// AIRALO PACKAGES CRUD - pricing rules per country/package type
// =============================================================================

$airaloModule = $db->get('modules', ['id'], ['name' => 'airalo', 'type' => 'esim', 'ORDER' => ['id' => 'ASC']]);
$airaloSettingsUrl = root . admin . '/settings/modules#esim';
if (!empty($airaloModule['id'])) {
    $airaloSettingsUrl = root . admin . '/settings/modules/' . (int) $airaloModule['id'] . '#database';
}

// Legacy Airalo countries route now points to the unified country/package CRUD page.
$router->get(admin.'/settings/modules/airalo-countries', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    global $airaloSettingsUrl;
    header('Location: ' . $airaloSettingsUrl);
    exit;
});

// Bulk enable/disable for airalo_countries.
$router->post(admin.'/settings/modules/airalo-countries/bulk-status', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $ids = $input['ids'] ?? [];
    if (is_string($ids)) { $ids = array_filter(explode(',', $ids)); }
    $ids = array_values(array_filter(array_map('intval', (array) $ids)));
    $status = (int) (!empty($input['status']) ? 1 : 0);

    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No records selected']);
        exit;
    }

    $db->update('airalo_countries', ['status' => $status], ['id' => $ids]);
    echo json_encode([
        'status'   => 'success',
        'updated'  => count($ids),
        'newValue' => $status,
    ]);
    exit;
});

// Sync the airalo_countries catalog from Airalo's live package feed.
// Airalo has no /v2/countries endpoint, so the list is derived by paging the
// LOCAL /v2/packages feed (see airalo_sync_countries). New countries land
// DISABLED; existing rows keep their status. Admin-authed + CSRF-guarded.
$router->post(admin.'/settings/modules/airalo-countries/sync', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json');

    require_once dirname(__DIR__, 3) . '/modules/esim/airalo/api.php';
    if (!function_exists('airalo_sync_countries')) {
        echo json_encode(['status' => 'error', 'message' => 'eSIM sync unavailable']);
        exit;
    }

    $result = airalo_sync_countries($db);
    if (empty($result['ok'])) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Sync failed: ' . (string) ($result['error'] ?? 'Airalo API unreachable. Check the eSIM module credentials.'),
        ]);
        exit;
    }

    echo json_encode([
        'status'    => 'success',
        'message'   => sprintf(
            'Synced %d countries from Airalo (%d new, %d updated). New countries are disabled — enable the ones you sell.',
            (int) $result['fetched'], (int) $result['inserted'], (int) $result['updated']
        ),
        'pages'     => (int) $result['pages'],
        'fetched'   => (int) $result['fetched'],
        'inserted'  => (int) $result['inserted'],
        'updated'   => (int) $result['updated'],
        'total_now' => (int) $result['total_now'],
    ]);
    exit;
});

$router->get(admin.'/settings/modules/airalo-packages', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    global $airaloSettingsUrl;
    header('Location: ' . $airaloSettingsUrl);
    exit;
});

// Country-level package editor: one country -> multiple package rules.
$router->route(['GET','POST'], admin.'/settings/modules/airalo-packages/edit/([A-Za-z]{2})', function ($countryIso) use ($SECURE,$db) {
    ADMIN_AUTH();
    global $airaloSettingsUrl;

    $countryIso = strtoupper(trim((string) $countryIso));
    $country = $db->get('airalo_countries', ['iso','nicename','status'], ['iso' => $countryIso]);
    if (!$country || (int) ($country['status'] ?? 0) !== 1) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Country is disabled or not found.'];
        header('Location: ' . $airaloSettingsUrl);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rows = $_POST['rows'] ?? [];
        $submittedIds = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $payload = [
                    'country' => $countryIso,
                    'package_type' => in_array(($row['package_type'] ?? ''), ['all','global','local'], true) ? $row['package_type'] : 'all',
                    'commission_type' => in_array(($row['commission_type'] ?? ''), ['percentage','fixed'], true) ? $row['commission_type'] : 'percentage',
                    'value' => (float) ($row['value'] ?? 0),
                    'featured' => !empty($row['featured']) ? 1 : 0,
                    'status' => !empty($row['status']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $existing = $db->get('airalo_packages', ['id', 'country'], ['id' => $id]);
                    if ($existing && strtoupper((string) ($existing['country'] ?? '')) === $countryIso) {
                        $db->update('airalo_packages', $payload, ['id' => $id]);
                        $submittedIds[] = $id;
                    }
                } else {
                    $db->insert('airalo_packages', $payload);
                    $newId = (int) $db->id();
                    if ($newId > 0) {
                        $submittedIds[] = $newId;
                    }
                }
            }
        }

        // Remove rules deleted from the UI for this country.
        if (!empty($submittedIds)) {
            $db->delete('airalo_packages', [
                'country' => $countryIso,
                'id[!]' => $submittedIds,
            ]);
        } else {
            $db->delete('airalo_packages', ['country' => $countryIso]);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Country package rules updated.'];
        header('Location: ' . $airaloSettingsUrl);
        exit;
    }

    $packages = $db->select('airalo_packages', '*', [
        'country' => $countryIso,
        'ORDER' => ['id' => 'ASC'],
    ]);

    $title = 'Edit Airalo Packages - ' . ($country['nicename'] ?? $countryIso);
    $description = '';
    $header = true;
    $footer = true;
    $backUrl = $airaloSettingsUrl;
    require_once views."includes/header.php";
    require_once "app/views/admin/settings/modules/airalo-packages-country-edit.php";
    require_once views."includes/footer.php";
});

