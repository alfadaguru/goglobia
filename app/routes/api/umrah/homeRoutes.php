<?php
// ============================================================================
// FILE: app/routes/api/umrah/homeRoutes.php
// UMRAH API - HOME / SEARCH FORM DATA
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/umrah', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        // Resolve currency: query param > session > DB default > USD
        $selectedCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
        $currency = strtoupper($_GET['currency'] ?? $_SESSION['app_currency'] ?? $selectedCurrency['name'] ?? 'USD');

        $module = $db->get('modules', '*', ['name' => 'umrah']) ?: ['markup_b2c' => 0];

        // 1. Umrah Types
        $umrahTypes = $db->select('umrah_settings', ['id', 'setting_label', 'icon'], [
            'setting_type' => 'umrah_type',
            'status' => 1
        ]);
        $umrahTypeOptions = [['value' => 'any', 'label' => 'Any Type']];
        foreach ($umrahTypes as $type) {
            $umrahTypeOptions[] = ['value' => (string) $type['id'], 'label' => $type['setting_label'], 'icon' => $type['icon'] ?? ''];
        }

        // 2. Services
        $services = $db->select('umrah_settings', ['id', 'setting_label', 'icon'], [
            'setting_type' => ['service', 'hotel', 'flight', 'car'],
            'status' => 1,
            'ORDER' => ['setting_label' => 'ASC']
        ]);
        $serviceOptions = [];
        foreach ($services as $svc) {
            $serviceOptions[] = ['value' => (string) $svc['id'], 'label' => $svc['setting_label'], 'icon' => $svc['icon'] ?? ''];
        }

        // 3. Durations
        $durationOptions = [
            ['value' => 'any', 'label' => 'Any Duration'],
            ['value' => '1', 'label' => '1 Day'],
            ['value' => '2-3', 'label' => '2-3 Days'],
            ['value' => '4-7', 'label' => '4-7 Days'],
            ['value' => '8-14', 'label' => '8-14 Days'],
            ['value' => '15+', 'label' => '15+ Days'],
        ];

        // 4. Featured Packages
        $featuredRows = $db->select('umrah', '*', [
            'status' => 1,
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 6
        ]);

        $featured = [];
        foreach ($featuredRows as $u) {
            $image = '';
            if (!empty($u['img'])) {
                $decoded = json_decode($u['img'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $img = $decoded[0];
                    $url = is_array($img) ? ($img['url'] ?? '') : $img;
                    $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                    $image = (stripos($url, 'http') === 0) ? $url : str_replace('modules/', '', root) . $cleanUrl;
                }
            }

            $uCurrency = $u['currency'] ?: 'USD';
            $marked = MARKUP((float) $u['adult_price'], $module, $db, $uCurrency, $currency);
            $convertedPrice = round((float) ($marked['price'] ?? $u['adult_price']), 2);

            $featured[] = [
                'id' => $u['id'],
                'name' => $u['name'],
                'days' => (int) $u['days'],
                'stars' => (int) $u['stars'],
                'image' => $image,
                'price' => $convertedPrice,
                'actual_price' => round((float) ($marked['converted_base_price'] ?? $u['adult_price']), 2),
                'price_markup' => round((float) ($marked['markup'] ?? 0), 2),
                'currency' => $currency,
                'original_currency' => $uCurrency
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'umrah_type_options' => $umrahTypeOptions,
                'service_options' => $serviceOptions,
                'duration_options' => $durationOptions,
                'featured_packages' => $featured
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
