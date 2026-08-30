<?php
// ============================================================================
// FEATURED eSIM API
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/esim/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        // Fetch eSIM/Airalo module status to verify enabled
        $airaloModule = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$airaloModule) {
            echo json_encode([
                'success' => true,
                'message' => 'eSIM module is not enabled',
                'data' => [
                    'featured' => [],
                    'currency' => 'USD'
                ]
            ]);
            exit;
        }

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // Fetch featured packages/rules
        $featuredRules = $db->select('airalo_packages', [
            '[>]airalo_countries' => ['country' => 'iso']
        ], [
            'airalo_packages.id',
            'airalo_packages.country',
            'airalo_packages.package_type',
            'airalo_packages.commission_type',
            'airalo_packages.value',
            'airalo_countries.nicename(country_name)',
        ], [
            'airalo_packages.status' => 1,
            'airalo_packages.featured' => 1,
            'airalo_countries.status' => 1,
            'ORDER' => ['airalo_packages.id' => 'DESC'],
            'LIMIT' => 12,
        ]);

        $featuredItems = [];

        foreach ((array) $featuredRules as $rule) {
            if (count($featuredItems) >= 8) {
                break;
            }

            $iso = strtoupper((string) ($rule['country'] ?? ''));
            if ($iso === '') {
                continue;
            }

            $type = 'all';
            $countryName = (string) ($rule['country_name'] ?? $iso);
            $title = $countryName . ' eSIM';

            // Flags location logic
            $flagUrl = root . 'assets/img/flags/' . strtolower($iso) . '.svg';

            $featuredItems[] = [
                'id' => (int) ($rule['id'] ?? 0),
                'title' => $title,
                'country_iso' => $iso,
                'country_name' => $countryName,
                'package_type' => $type,
                'flag' => $flagUrl,
                'url' => root . 'esim/' . (int) $airaloModule['id'] . '/' . strtolower($iso) . '/' . $type . '/',
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => 'Featured eSIMs fetched successfully',
            'data' => [
                'featured' => $featuredItems,
                'currency' => $displayCurrency
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch featured eSIMs: ' . $e->getMessage()
        ]);
        exit;
    }
});
