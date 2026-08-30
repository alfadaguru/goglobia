<?php
// path: modules/flights/travelport/farerules.php
// Travelport+ JSON REST API Fare Rules route
// POST flights/travelport/farerules

global $router;

$router->post('flights/travelport/farerules', function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    try {
        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        $module = $db->get('modules', '*', ['name' => 'travelport']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Travelport module not configured']);
            exit;
        }

        $accessGroup = $module['c5'];
        $pcc = travelport_normalize_pcc($module['c6'] ?? '');

        $tokenResult = travelport_get_token($module);
        $token = is_array($tokenResult) ? ($tokenResult['token'] ?? '') : (string) $tokenResult;
        if (empty($token)) {
            echo json_encode(['status' => false, 'message' => 'Failed to obtain access token']);
            exit;
        }

        $searchId   = $request_data['search_id']   ?? $request_data['reservation_identifier'] ?? '';
        $offeringId = $request_data['offering_id'] ?? $request_data['offer_id'] ?? '';
        $productId  = $request_data['product_id']  ?? '';
        $pnr        = $request_data['pnr']         ?? '';

        if (empty($searchId) && empty($pnr)) {
            echo json_encode(['status' => false, 'message' => 'search_id or pnr parameter is required']);
            exit;
        }

        // Build Travelport GET URL for structured farerules
        $baseUrl = 'https://api.pp.travelport.net/11';
        
        if (!empty($offeringId) && !empty($searchId)) {
            // Pre-booking search catalog fare rules
            $params = [
                'catalogProductOfferingsIdentifier' => $searchId,
                'catalogProductOfferingID'          => $offeringId,
                'fareRuleType'                      => 'Structured',
                'fareRuleCategories'                => json_encode(['Penalties', 'AdvanceReservationsTicketing'])
            ];
            if (!empty($productId)) {
                $params['productIDs'] = is_array($productId) ? json_encode($productId) : json_encode([$productId]);
            }
            $apiUrl = "$baseUrl/air/farerule/farerules/fromcatalogproductofferings?" . http_build_query($params);
        } else {
            // Post-booking PNR fare rules
            $resId = !empty($pnr) ? $pnr : $searchId;
            $params = [
                'reservationIdentifier' => $resId,
                'fareRuleType'          => 'Structured',
                'fareRuleCategories'    => json_encode(['Penalties', 'AdvanceReservationsTicketing'])
            ];
            $apiUrl = "$baseUrl/air/farerule/farerules/fromreservation?" . http_build_query($params);
        }
        $headers = [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            "TVP-PCC-Core: $pcc",
            "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup",
            'Accept-Encoding: gzip, deflate',
            'Content-Version: 11',
            'TraceId: TraceID_' . uniqid()
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $apiRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = json_decode($apiRes, true);

        // Normalize structured rules for frontend display
        $rulesStructured = [];
        $rulesHtml = '';

        $rulesList = $responseData['FareRuleResponse']['FareRule'] 
            ?? $responseData['FareRules'] 
            ?? $responseData['FareRuleInfo'] 
            ?? [];

        if (empty($rulesList) && !empty($responseData['FareRuleGroup'])) {
            $rulesList = $responseData['FareRuleGroup'];
        }

        if (!empty($rulesList) && is_array($rulesList)) {
            foreach ($rulesList as $rule) {
                $category = $rule['category'] ?? $rule['Category'] ?? $rule['fareRuleCategory'] ?? 'General Rules';
                $text = $rule['text'] ?? $rule['Text'] ?? $rule['ruleText'] ?? $rule['RuleText'] ?? '';
                if (is_array($text)) $text = implode("\n", $text);

                if (!empty($text)) {
                    $rulesStructured[] = [
                        'category' => $category,
                        'text' => $text
                    ];

                    $rulesHtml .= '<div class="fare-rule-block mb-4 p-3 bg-gray-50 rounded-lg border border-gray-100">';
                    $rulesHtml .= '<h4 class="font-bold text-gray-900 mb-1 text-sm">' . htmlspecialchars((string)$category) . '</h4>';
                    $rulesHtml .= '<pre class="text-xs text-gray-600 leading-relaxed font-sans" style="white-space:pre-wrap;">' . htmlspecialchars((string)$text) . '</pre>';
                    $rulesHtml .= '</div>';
                }
            }
        }

        if (empty($rulesHtml)) {
            $rulesHtml = '<div class="p-4 bg-blue-50 text-blue-800 rounded-lg text-sm"><p class="font-semibold">Standard Fare Rules Apply:</p><ul class="list-disc ml-5 mt-1 text-xs space-y-1"><li>Ticket cancellation & date changes subject to airline fee policy.</li><li>Non-refundable after flight departure.</li><li>Name changes are not permitted.</li></ul></div>';
        }

        echo json_encode([
            'status'  => true,
            'message' => 'Fare rules retrieved successfully',
            'data'    => [
                'rules'      => $rulesStructured,
                'rules_html' => $rulesHtml
            ],
            'response' => $responseData ?? $apiRes
        ]);

    } catch (Throwable $e) {
        error_log('TRAVELPORT FARERULES ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while fetching fare rules: ' . $e->getMessage()]);
    }
});
