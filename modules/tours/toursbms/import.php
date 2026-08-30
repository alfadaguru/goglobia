<?php
// ============================================================================
// ToursBMS — CONTENT IMPORT (product catalogue, types, regions) into our DB.
//   POST /modules/tours/toursbms/import   { mode: 'full' | 'incremental' }
// Prices/availability are NOT stored here (fetched live at search — see search.php).
// Admin-only. Long-running: designed to be called from the admin Content tab / cron.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_toursbms_upsert_types')) {
    // $db = main DB (API creds); $mdb = module content DB (catalogue tables).
    function _toursbms_upsert_types($db, $mdb, $categoryType = 1)
    {
        $data = toursbms_call($db, '/openapi/v1/product/getProductType', ['categoryType' => $categoryType]);
        $list = is_array($data) ? $data : [];
        foreach ($list as $t) {
            $code = (string) ($t['productType'] ?? '');
            if ($code === '') continue;
            $row = ['product_type' => $code, 'category_type' => (int) ($t['categoryType'] ?? $categoryType),
                    'product_type_name' => (string) ($t['productTypeName'] ?? '')];
            if ($mdb->has('types', ['product_type' => $code])) {
                $mdb->update('types', $row, ['product_type' => $code]);
            } else {
                $mdb->insert('types', $row);
            }
        }
        return count($list);
    }
}

if (!function_exists('_toursbms_upsert_regions')) {
    function _toursbms_upsert_regions($db, $mdb, $regionType = 1)
    {
        $data = toursbms_call($db, '/openapi/v1/product/getRegionInfo', ['regionType' => $regionType]);
        $list = is_array($data) ? ($data['list'] ?? $data) : [];
        $n = 0;
        foreach ((array) $list as $r) {
            $code = (string) ($r['regionCode'] ?? $r['code'] ?? '');
            if ($code === '') continue;
            $name = (string) ($r['regionName'] ?? $r['name'] ?? '');
            // Best-effort map to our locations (city name match). locations lives in
            // the main v10 DB, so this lookup uses $db, not the module content DB.
            $loc = $name !== '' ? $db->get('locations', ['id'], ['city' => $name]) : null;
            $row = [
                'region_code' => $code, 'region_name' => $name,
                'region_type' => (int) ($r['regionType'] ?? $regionType),
                'parent_code' => (string) ($r['parentCode'] ?? ''),
                'location_id' => $loc['id'] ?? null,
                'raw' => json_encode($r, JSON_UNESCAPED_UNICODE),
            ];
            if ($mdb->has('regions', ['region_code' => $code])) {
                $mdb->update('regions', $row, ['region_code' => $code]);
            } else {
                $mdb->insert('regions', $row);
            }
            $n++;
        }
        return $n;
    }
}

if (!function_exists('_toursbms_store_product')) {
    /** Fetch getProductInfo for a product and upsert into the module products table. */
    function _toursbms_store_product($db, $mdb, $productID, $schemeCode = '', $language = 3)
    {
        $body = ['productID' => $productID, 'language' => $language];
        if ($schemeCode !== '') $body['schemeCode'] = $schemeCode;
        $info = toursbms_call($db, '/openapi/v1/product/getProductInfo', $body);
        if (!is_array($info)) return false;

        $regJson = function ($v) {
            $d = is_string($v) ? json_decode($v, true) : $v;
            return is_array($d) ? $d : [];
        };
        $dep = $regJson($info['departureCity'] ?? '');
        $dst = $regJson($info['destinationCity'] ?? '');
        $imgs = array_values(array_filter(explode('|', (string) ($info['productImgUrl'] ?? ''))));

        $row = [
            'product_id'   => (string) ($info['productID'] ?? $productID),
            'product_code' => (string) ($info['productCode'] ?? ''),
            'scheme_code'  => $schemeCode,
            'category_type' => (int) ($info['categoryType'] ?? 1),
            'product_type' => (string) ($info['productType'] ?? ''),
            'product_type_name' => (string) ($info['productTypeName'] ?? ''),
            'product_form' => (int) ($info['productForm'] ?? 0),
            'name'         => (string) ($info['productName'] ?? ''),
            'subtitle'     => (string) ($info['subtitleName'] ?? ''),
            'trip_day'     => (int) ($info['tripDay'] ?? 0),
            'night_day'    => (int) ($info['nightDay'] ?? 0),
            'departure_region_code' => (string) ($dep['regionCode'] ?? ''),
            'departure_region_name' => (string) ($dep['regionName'] ?? ''),
            'destination_region_code' => (string) ($dst['regionCode'] ?? ''),
            'destination_region_name' => (string) ($dst['regionName'] ?? ''),
            'scenic'       => json_encode($regJson($info['Destination'] ?? ''), JSON_UNESCAPED_UNICODE),
            'vehicle'      => (string) ($info['Vehicle'] ?? ''),
            'images'       => json_encode($imgs, JSON_UNESCAPED_UNICODE),
            'special'      => (string) ($info['productSpecial'] ?? ''),
            'sales_note'   => (string) ($info['salesNote'] ?? ''),
            'settlement_currency' => (string) ($info['settlementCurrencyNum'] ?? ''),
            'airport_pickup'  => !empty($info['airport_pick_up']) ? 1 : 0,
            'airport_dropoff' => !empty($info['airport_drop_off']) ? 1 : 0,
            'advance_day'  => (int) ($info['advanceDay'] ?? 0),
            'language'     => (string) ($info['language'] ?? ''),
            'status'       => (int) ($info['productStatus'] ?? 200) >= 200 && (int) ($info['productStatus'] ?? 0) !== 300 ? 1 : 0,
            'publish_time' => (string) ($info['publishTime'] ?? ''),
            'update_time'  => (string) ($info['updateTime'] ?? ''),
            'raw'          => json_encode($info, JSON_UNESCAPED_UNICODE),
        ];
        $where = ['product_id' => $row['product_id'], 'scheme_code' => $schemeCode];
        if ($mdb->has('products', $where)) {
            $mdb->update('products', $row, $where);
        } else {
            $mdb->insert('products', $row);
        }
        return true;
    }
}

$router->post('tours/toursbms/import', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/api.php';

    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }

    @set_time_limit(0);
    $mode = strtolower(trim((string) ($_POST['mode'] ?? 'full')));

    try {
        $mdb = _toursbms_db($db); // module content DB (auto-creates tables)

        $types = _toursbms_upsert_types($db, $mdb, 1);
        $regions = _toursbms_upsert_regions($db, $mdb, 1);

        $imported = 0;
        if ($mode === 'incremental') {
            // Fetch the change log since the last sync, then re-fetch affected products.
            $last = $mdb->get('sync_log', ['last_note_time'],
                ['ORDER' => ['id' => 'DESC']]);
            $start = $last['last_note_time'] ?? date('Y-m-d H:i:s', strtotime('-7 days'));
            $end   = date('Y-m-d H:i:s');
            $log = toursbms_call($db, '/openapi/v1/product/getProductUpdates', [
                'noteTimeStart' => $start, 'noteTimeEnd' => $end,
            ]);
            foreach ((array) ($log ?: []) as $ev) {
                $pid = (string) ($ev['productID'] ?? '');
                if ($pid === '') continue;
                $ut = (int) ($ev['updateType'] ?? 0);
                if ($ut === 2 || $ut === 35) {            // delisted
                    $mdb->update('products', ['status' => 0], ['product_id' => $pid]);
                } else {                                   // listed / info change → re-fetch
                    if (_toursbms_store_product($db, $mdb, $pid)) $imported++;
                }
            }
            $mdb->insert('sync_log', [
                'sync_type' => 'incremental', 'last_note_time' => $end, 'products' => $imported,
                'message' => 'Processed ' . count((array) ($log ?: [])) . ' events',
            ]);
        } else {
            // FULL: page through getProductList, store each product's details.
            $page = 1; $pageSize = 20; $guard = 0;
            do {
                $data = toursbms_call($db, '/openapi/v1/product/getProductList', [
                    'language' => 3,
                    'pager' => ['pageIndex' => $page, 'pageSize' => $pageSize],
                ]);
                $list = (array) ($data['list'] ?? []);
                foreach ($list as $p) {
                    $pid = (string) ($p['productID'] ?? '');
                    if ($pid === '') continue;
                    $schemes = (array) ($p['schemeList'] ?? []);
                    if ($schemes) {
                        foreach ($schemes as $s) {
                            if (_toursbms_store_product($db, $mdb, $pid, (string) ($s['schemeCode'] ?? ''))) $imported++;
                        }
                    } else {
                        if (_toursbms_store_product($db, $mdb, $pid)) $imported++;
                    }
                }
                $hasNext = !empty($data['pager']['hasNextPage']);
                $page++;
            } while ($hasNext && ++$guard < 500);

            $mdb->insert('sync_log', [
                'sync_type' => 'full', 'last_note_time' => date('Y-m-d H:i:s'),
                'products' => $imported, 'message' => 'Full import',
            ]);
        }

        echo json_encode([
            'success' => true, 'status' => true,
            'message' => "Import complete ($mode): $imported products, $regions regions, $types types.",
            'data' => compact('imported', 'regions', 'types'),
        ]);
        exit;
    } catch (\Throwable $e) {
        error_log('TOURSBMS IMPORT ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'status' => false, 'message' => $e->getMessage()]);
        exit;
    }
});
