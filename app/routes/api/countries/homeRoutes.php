<?php
// FILE: app/routes/api/countries/home.php
// COUNTRIES API - List all countries

@$SECURE or die('Access Denied!');

// ============================================================================
// GET: List all countries
// GET /api/countries
// ============================================================================
$router->get('/api/countries', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {

        // ================= BASE URL AUTO DETECT =================
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'];
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $flagBase = $scheme . '://' . $host . $base . '/assets/img/flags_sqaure/';

        $countries = $db->select('countries', '*', [
            'ORDER' => ['nicename' => 'ASC']
        ]);

        // Add flag URL
        foreach ($countries as &$country) {
            
            $country['flag_url'] = $flagBase . strtolower($country['iso']) . '.svg';
        }

        echo json_encode([
            'success' => true,
            'data'    => $countries
        ]);

    } catch (Exception $e) {

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
