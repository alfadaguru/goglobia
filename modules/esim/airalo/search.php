<?php

global $router;

$router->post('esim/airalo/search', function() use ($db) {
    @set_time_limit(60);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Get module configuration
        $module = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim'
        ]);

        if (!$module || !$module['status']) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Airalo module is not enabled',
                'data' => null
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        // Get search parameters
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
        
        $country = trim((string) ($input['country'] ?? ''));
        $type = trim((string) ($input['type'] ?? 'global'));
        $limit = (int) ($input['limit'] ?? 100);
        $page = (int) ($input['page'] ?? 1);
        $language = trim((string) ($input['language'] ?? 'en'));
        $environment = (!empty($module['dev_mode']) && (string)$module['dev_mode'] === '1') ? 'sandbox' : 'production';

        // Validate inputs
        if ($limit < 1 || $limit > 500) {
            $limit = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        // Build query parameters for Airalo
        $queryParams = [
            'limit' => $limit,
            'page' => $page,
        ];

        if ($country !== '') {
            $queryParams['filter[country]'] = $country;
        }
        if ($type !== '' && $type !== 'global') {
            $queryParams['filter[type]'] = $type;
        }

        // Fetch packages from Airalo
        $packagesRes = _airalo_request_with_token($db, 'GET', '/v2/packages', [
            'env' => $environment,
            'query' => $queryParams,
            'accept_language' => $language,
            'timeout' => 45,
        ]);

        if (!$packagesRes['ok']) {
            http_response_code($packagesRes['status'] > 0 ? $packagesRes['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $packagesRes['error'] ?? 'Failed to fetch eSIM packages',
                'data' => $packagesRes['data'] ?? null
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        $packages = $packagesRes['data']['data'] ?? [];
        $meta = $packagesRes['data']['meta'] ?? [];

        // Format results for frontend
        $results = [];
        if (is_array($packages)) {
            foreach ($packages as $pkg) {
                $results[] = [
                    'id' => $pkg['id'] ?? null,
                    'package_id' => $pkg['package_id'] ?? null,
                    'title' => $pkg['title'] ?? null,
                    'description' => $pkg['description'] ?? null,
                    'short_title' => $pkg['short_title'] ?? null,
                    'slug' => $pkg['slug'] ?? null,
                    'package_type' => $pkg['package_type'] ?? null,
                    'country' => $pkg['country'] ?? null,
                    'regions' => $pkg['regions'] ?? [],
                    'price' => $pkg['price'] ?? null,
                    'retail_price' => $pkg['retail_price'] ?? null,
                    'operator' => $pkg['operator'] ?? null,
                    'duration' => $pkg['duration'] ?? null,
                    'data_limit' => $pkg['data_limit'] ?? null,
                    'data_speed' => $pkg['data_speed'] ?? null,
                    'status' => $pkg['status'] ?? 'available',
                    'image_url' => $pkg['image_url'] ?? null,
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'eSIM packages fetched successfully',
            'data' => $results,
            'pagination' => [
                'current_page' => $meta['current_page'] ?? $page,
                'total_pages' => $meta['total_pages'] ?? 1,
                'per_page' => $meta['per_page'] ?? $limit,
                'total' => $meta['total'] ?? count($results),
            ],
            'meta' => [
                'module' => 'airalo',
                'service' => 'esim',
                'environment' => $environment,
                'api_version' => 'v2',
                'timestamp' => date('c'),
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Search error: ' . $e->getMessage(),
            'data' => null
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
});
