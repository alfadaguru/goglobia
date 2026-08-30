<?php
// ============================================================================
// ToursBMS — SEARCH (POST /modules/tours/toursbms/search)
// Returns a plain JSON array (same contract as modules/tours/tours/search.php).
// Uses local imported catalogue when available; falls back to live getProductList.
// Prices / availability always from live getProductDate.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/search', function () use ($db) {
    @set_time_limit(60);
    if (session_status() === PHP_SESSION_ACTIVE) { $S = $_SESSION; session_write_close(); }
    else { $S = $_SESSION ?? []; }
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/api.php';

    try {
        $destination = trim((string) ($_POST['destination'] ?? ''));
        $startDate   = _toursbms_normalize_date($_POST['start_date'] ?? '');
        $adults      = max(1, (int) ($_POST['adults'] ?? 1));
        $children    = max(0, (int) ($_POST['children'] ?? 0));
        $tourType    = trim((string) ($_POST['tour_type'] ?? ''));
        $page        = max(1, (int) ($_POST['page'] ?? 1));
        $perPage     = min(50, max(1, (int) ($_POST['per_page'] ?? 20)));
        $sessionCur  = $S['app_currency'] ?? ($_POST['currency'] ?? 'USD');

        $cfg = _toursbms_cfg($db);

        $products = [];
        $total = 0;
        $totalPages = 1;
        $usedLive = false;

        // ---- Try local imported catalogue ------------------------------------
        try {
            $mdb = _toursbms_db($db);

            $regionCodes = [];
            if ($destination !== '') {
                $locId = (int) ($db->get('locations', ['id'], ['city' => $destination])['id'] ?? 0);
                $regs = $mdb->select('regions', ['region_code'], [
                    'OR' => [
                        'region_name' => $destination,
                        'location_id' => $locId,
                    ],
                ]) ?: [];
                foreach ($regs as $r) $regionCodes[] = $r['region_code'];
            }

            $where = ['status' => 1];
            if ($destination !== '') {
                $or = [
                    'name[~]'                    => $destination,
                    'departure_region_name[~]'   => $destination,
                    'destination_region_name[~]' => $destination,
                ];
                if ($regionCodes) {
                    $or['departure_region_code']  = $regionCodes;
                    $or['destination_region_code'] = $regionCodes;
                }
                $where['OR'] = $or;
            }
            if ($tourType !== '' && preg_match('/^PC\d+$/i', $tourType)) {
                $where['product_type'] = $tourType;
            }

            $total = (int) $mdb->count('products', $where);
            $where['LIMIT'] = [($page - 1) * $perPage, $perPage];
            $where['ORDER'] = ['update_time' => 'DESC'];
            $products = $mdb->select('products', '*', $where) ?: [];
            $totalPages = max(1, (int) ceil(($total ?: 0) / $perPage));
        } catch (\Throwable $e) {
            // Module DB not configured or empty — fall through to live API
            error_log('TOURSBMS SEARCH local DB: ' . $e->getMessage());
        }

        // ---- Live API fallback (no import / no local matches) ------------------
        if (empty($products)) {
            $live = _toursbms_fetch_live_products($db, $destination, $page, $perPage);
            $products = $live['rows'];
            $total = $live['total'];
            $totalPages = $live['total_pages'];
            $usedLive = true;
        }

        // ---- Enrich with live prices -----------------------------------------
        $tours = [];
        foreach ($products as $p) {
            try {
                $tour = _toursbms_build_tour_result($db, $cfg, $p, $startDate, $destination, $sessionCur);
                if ($tour) $tours[] = $tour;
            } catch (\Throwable $e) {
                error_log('TOURSBMS getProductDate skip ' . ($p['product_id'] ?? '') . ': ' . $e->getMessage());
            }
        }

        // If destination filter yielded local rows but none had prices, retry live
        if (!$usedLive && empty($tours) && $destination !== '') {
            $live = _toursbms_fetch_live_products($db, $destination, $page, $perPage);
            foreach ($live['rows'] as $p) {
                try {
                    $tour = _toursbms_build_tour_result($db, $cfg, $p, $startDate, $destination, $sessionCur);
                    if ($tour) $tours[] = $tour;
                } catch (\Throwable $e) {
                    error_log('TOURSBMS live retry skip: ' . $e->getMessage());
                }
            }
            if ($tours) {
                $total = $live['total'];
                $totalPages = $live['total_pages'];
            }
        }

        header('X-Total-Results: ' . count($tours));
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $perPage);
        header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));

        echo json_encode($tours, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        error_log('TOURSBMS SEARCH ERROR: ' . $e->getMessage());
        header('X-Total-Results: 0');
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }
});
