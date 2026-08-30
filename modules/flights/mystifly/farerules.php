<?php
// path: modules/flights/mystifly/farerules.php
// Mystifly fare rules route
// POST flights/mystifly/farerules

global $router;

$router->post('flights/mystifly/farerules', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $fareSourceCode = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ?? ''
        );

        if (empty($fareSourceCode)) {
            echo json_encode(['status' => false, 'message' => 'FareSourceCode is required']);
            exit;
        }

        $credentials = getMystiflyCredentials($db);
        $debugMode   = $credentials['debug'] ?? false;

        $result = mystiflyApiRequest($db, 'api/v1/FlightFareRules', [
            'FareSourceCode' => $fareSourceCode,
            'Target'         => (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test',
        ]);

        if (!$result['success']) {
            echo json_encode([
                'status'  => false,
                'message' => 'Failed to retrieve fare rules.',
                'data'    => null,
            ]);
            exit;
        }

        $data       = $result['data'] ?? [];
        $statusCode = $data['Status']['Code'] ?? '';

        if ($statusCode !== '001') {
            echo json_encode([
                'status'  => false,
                'message' => $data['Status']['Message'] ?? 'Fare rules not available.',
                'data'    => null,
            ]);
            exit;
        }

        $fareRules = $data['FareRules'] ?? [];

        // Normalize rules into a structured array and also build an HTML string
        $rulesStructured = [];
        $rulesHtml       = '';

        foreach ($fareRules as $rule) {
            $category   = $rule['Category'] ?? '';
            $ruleText   = $rule['Rules'] ?? '';
            $airlineCode = $rule['Airline'] ?? '';

            $rulesStructured[] = [
                'airline'  => $airlineCode,
                'category' => $category,
                'text'     => $ruleText,
            ];

            $rulesHtml .= '<div class="fare-rule-block">';
            if ($category) $rulesHtml .= '<h4>' . htmlspecialchars($category) . '</h4>';
            if ($ruleText) $rulesHtml .= '<pre style="white-space:pre-wrap;">' . htmlspecialchars($ruleText) . '</pre>';
            $rulesHtml .= '</div>';
        }

        $response = [
            'status'  => true,
            'message' => 'Fare rules retrieved successfully.',
            'data'    => [
                'fare_source_code' => $fareSourceCode,
                'rules'            => $rulesStructured,
                'rules_html'       => $rulesHtml ?: '<p>No fare rules available for this fare.</p>',
            ],
        ];

        if ($debugMode) {
            $response['raw_response'] = $result['data'];
        }

        echo json_encode($response);

    } catch (Throwable $e) {
        error_log('MYSTIFLY FARERULES ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.']);
    }
});
