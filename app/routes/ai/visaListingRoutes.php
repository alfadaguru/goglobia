<?php
// ============================================================================
// FILE: app/routes/ai/visaListingRoutes.php
// AI Trip only — confirm card(s) for Visa (normal website has no visa listing page).
// Mirrors normal /visa search → booking: one priced or inquiry card for the
// From→To + type + speed + travelers selection.
// POST /api/ai/visa/listing  (CSRF required)
// NOT mobile app/routes/api/visa/
//
// The card-building core now lives in app/lib/visa/listing.php (visaListingCards)
// so GIA's search tool and this endpoint share ONE source of truth.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/api/ai/visa/listing', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            $input = [];
        }

        $csrf = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        // Keep the endpoint's explicit input 400s; the card build itself is shared.
        $fromCountry = strtoupper(trim((string)($input['from_country'] ?? '')));
        $toCountry = strtoupper(trim((string)($input['to_country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $fromCountry) || !preg_match('/^[A-Z]{2}$/', $toCountry)) {
            throw new Exception('from_country and to_country must be 2-letter ISO codes');
        }
        if ($fromCountry === $toCountry) {
            throw new Exception('From and To countries cannot be the same');
        }

        require_once dirname(__DIR__, 2) . '/lib/visa/listing.php';
        $built = visaListingCards($input, $db);
        if (($built['cards'] ?? []) === []) {
            throw new Exception('Country not found or not active');
        }
        $card = $built['cards'][0];

        echo json_encode([
            'success' => true,
            'message' => !empty($card['is_inquiry_only'])
                ? 'Visa inquiry option ready.'
                : 'Visa catalog price ready.',
            'data' => [
                'search_params' => [
                    'from_country' => $card['from_country'],
                    'to_country' => $card['to_country'],
                    'travel_date' => $card['entry_date'],
                    'visa_type' => $card['visa_type'],
                    'processing_speed' => $card['processing_speed'],
                    'travelers' => $card['travelers'],
                ],
                'cards' => [$card],
            ],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});
