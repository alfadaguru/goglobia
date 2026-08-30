<?php
// ============================================================================
// ToursBMS — DETAILS (POST /modules/tours/toursbms/details)
// Returns { success, data } — same contract as modules/tours/tours/details.php
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/details', function () use ($db) {
    @set_time_limit(60);
    if (session_status() === PHP_SESSION_ACTIVE) { $S = $_SESSION; session_write_close(); }
    else { $S = $_SESSION ?? []; }
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/api.php';

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $tourId = trim((string) ($input['tour_id'] ?? ''));
        if ($tourId === '') {
            echo json_encode(['success' => false, 'message' => 'tour_id is required']);
            exit;
        }

        [$productId, $schemeCode] = array_pad(explode('_', $tourId, 2), 2, '');

        $departureRaw = trim((string) ($input['departure_date'] ?? $input['start_date'] ?? ''));
        $departureYmd = _toursbms_normalize_date($departureRaw);

        $adults = (int) ($input['total_adults'] ?? $input['adults'] ?? ($S['tour_detail']['total_adults'] ?? 1));
        $children = (int) ($input['total_children'] ?? $input['children'] ?? ($S['tour_detail']['total_children'] ?? 0));
        if ($adults < 1) $adults = 1;
        if ($children < 0) $children = 0;

        $sessionCur = $S['app_currency'] ?? ($input['currency'] ?? 'USD');
        $cfg = _toursbms_cfg($db);
        $p = _toursbms_resolve_product($db, $productId, $schemeCode);

        // Live departure dates + prices
        $body = ['productID' => $productId, 'mode' => 0];
        if (!empty($p['product_code'])) $body['productCode'] = $p['product_code'];
        if ($schemeCode !== '') { $body['schemeCode'] = $schemeCode; $body['productClassify'] = 1; }

        $dateData = toursbms_call($db, '/openapi/v1/product/getProductDate', $body);
        $productCur = $dateData['settlementCurrency'] ?? ($p['settlement_currency'] ?: $cfg['currency']);

        $prices = _toursbms_extract_prices_from_dates((array) ($dateData['productDate'] ?? []), $departureYmd);
        if ($prices['adult'] === null) {
            throw new Exception('No available departure dates with pricing for this tour');
        }

        $pricing = _toursbms_price_breakdown(
            $db, $cfg['module'], $prices['adult'], $prices['child'] ?? 0,
            $adults, $children, $productCur, $sessionCur
        );

        $imgs = json_decode($p['images'] ?? '[]', true);
        if (!is_array($imgs) || empty($imgs)) {
            $imgs = _toursbms_parse_images($p['images'] ?? '');
        }
        $mainImage = $imgs[0] ?? '';

        $description = trim(strip_tags($p['special'] ?? '')) !== ''
            ? (string) $p['special']
            : ((string) ($p['sales_note'] ?? '') ?: (string) ($p['subtitle'] ?? ''));

        $days = max(1, (int) ($p['trip_day'] ?? 0));
        $nights = (int) ($p['night_day'] ?? 0);

        $productInfo = _toursbms_product_info_payload($db, $productId, $schemeCode, $p);
        $inclusions  = _toursbms_notice_items($productInfo, 0, 'check_circle');
        $exclusions  = _toursbms_notice_items($productInfo, 7, 'cancel');

        $cancelPolicy = _toursbms_format_notice_html(_toursbms_notice_content($productInfo, 3));
        if ($cancelPolicy === '') {
            $cancelPolicy = trim(strip_tags((string) ($p['sales_note'] ?? '')));
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id'                       => $tourId,
                'tour_id'                  => $tourId,
                'tour_name'                => $p['name'],
                'name'                     => $p['name'],
                'subtitle'                 => $p['subtitle'] ?? '',
                'description'              => $description,
                'short_description'        => mb_substr(strip_tags($description), 0, 160),
                'location'                 => $p['departure_region_name'] ?: ($p['destination_region_name'] ?? ''),
                'destination'              => $p['destination_region_name'] ?? '',
                'address'                  => '',
                'days'                     => $days,
                'nights'                   => $nights,
                'tour_type'                => $p['product_type_name'] ?: 'Tour',
                'tour_type_id'             => 0,
                'stars'                    => 5,
                'rating_average'           => 0,
                'rating_count'             => 0,
                'image'                    => $mainImage,
                'images'                   => $imgs,
                'currency'                 => $sessionCur,
                'original_currency'        => $productCur,
                'display_price'            => $pricing['display_price'],
                'display_price_per_adult'  => $pricing['display_price_per_adult'],
                'display_price_per_child'  => $pricing['display_price_per_child'],
                'actual_price'             => $pricing['actual_price'],
                'actual_price_per_adult'   => $pricing['actual_price_per_adult'],
                'actual_price_per_child'   => $pricing['actual_price_per_child'],
                'price_breakdown'          => $pricing['price_breakdown'],
                'base_adult_price'         => $prices['adult'],
                'base_child_price'         => $prices['child'] ?? round($prices['adult'] * 0.8, 2),
                'markup_total_tour_price'    => $pricing['markup_total_tour_price'],
                'markup_total_price_persons' => $pricing['markup_total_price_persons'],
                'markup_total_price_childrens' => $pricing['markup_total_price_childrens'],
                'actual_total_tour_price'    => $pricing['actual_total_tour_price'],
                'max_adults'               => 20,
                'max_children'             => 10,
                'current_adults'           => $adults,
                'current_children'         => $children,
                'inclusions'               => $inclusions,
                'exclusions'               => $exclusions,
                'amenities'                => [],
                'itinerary'                => [],
                'cancellation_policy'      => $cancelPolicy,
                'terms_conditions'         => '',
                'has_available_slots'      => $prices['seats'] > 0,
                'availability_message'     => $prices['seats'] > 0 ? '' : 'Limited availability',
                'supplier'                 => 'toursbms',
                'vehicle'                  => $p['vehicle'] ?? '',
                'airport_pickup'           => !empty($p['airport_pickup']),
                'airport_dropoff'          => !empty($p['airport_dropoff']),
                'departure_dates'          => array_values(array_filter(array_map(function ($d) {
                    if ((int) ($d['status'] ?? 0) !== 200) return null;
                    return ['date' => $d['date'] ?? '', 'seats' => (int) ($d['groupStock'] ?? 0)];
                }, (array) ($dateData['productDate'] ?? [])))),
                'search_params'            => [
                    'start_date' => $departureRaw,
                    'adults'     => $adults,
                    'children'   => $children,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        error_log('TOURSBMS DETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
});
