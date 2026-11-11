<?php
// ============================================================================
// FILE: app/routes/umrah/listingRoutes.php
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// WEB AJAX — package cards for AI Trip (and similar).
// POST /api/umrah/listing  (CSRF required)
// NOT mobile app/routes/api/umrah/
// ============================================================================
$router->post('/api/umrah/listing', function () use ($SECURE, $db) {
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

        // Card-building logic lives in a reusable helper so GIA's search tool can
        // obtain the exact same real, priced cards without an internal HTTP call.
        require_once dirname(__DIR__, 2) . '/lib/umrah/listing.php';
        $built = umrahListingCards($input, $db);
        $cards = $built['cards'];

        echo json_encode([
            'success' => true,
            'message' => count($cards) ? 'Umrah packages ready.' : 'No Umrah packages match this search.',
            'data' => ['cards' => $cards],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// v1→v2: the legacy GET /umrah/(.*) catch-all and GET /umrah/listing routes were
// removed. They were shadowed by the v2 routes in umrahV2Routes.php (loaded first
// in _routes.php) and rendered the retired v1 listing views (listing/umrah.php +
// umrah-items/umrah-filters, which read the legacy `umrah` product table). The v2
// storefront (/umrah, /umrah/search, /umrah/packages/*) fully replaces them. Only
// the POST /api/umrah/listing endpoint above (v2, used by GIA/AI Trip) remains.
