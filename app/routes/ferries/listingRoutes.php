<?php
// ============================================================================
// FERRIES — PUBLIC LISTING ROUTE
// ============================================================================
// One-way:    GET /ferries/{dep}/{dest}/{date}/{adults}/{children}/{infant}
// Round-trip: GET /ferries/{dep}/{dest}/{date}/{return_date}/{adults}/{children}/{infant}
// Optional:   ?vehicles=N&pets=N&bonuses=1,2
// Legacy (no infant):
// One-way:    GET /ferries/{dep}/{dest}/{date}/{adults}/{children}
// Round-trip: GET /ferries/{dep}/{dest}/{date}/{return_date}/{adults}/{children}
// ============================================================================

@$SECURE or die('Access Denied!');

function _ferries_port_name(int $portId): string {
    if (!$portId) return '';
    $cache = defined('root_path') ? root_path . 'app/cache/kikoto_ports_en.json'
           : dirname(__DIR__, 2) . '/cache/kikoto_ports_en.json';
    if (!file_exists($cache)) return '';
    $ports = json_decode(file_get_contents($cache), true) ?: [];
    foreach ($ports as $p) {
        if ((int)($p['id'] ?? 0) === $portId) return $p['name'] ?? '';
    }
    return '';
}

function _ferries_extra_search_params(): array {
    $bonusIds = [];
    $rawBonuses = $_GET['bonuses'] ?? '';
    if (is_array($rawBonuses)) {
        foreach ($rawBonuses as $b) {
            $id = (int)$b;
            if ($id > 0) $bonusIds[] = $id;
        }
    } elseif (is_string($rawBonuses) && $rawBonuses !== '') {
        foreach (explode(',', $rawBonuses) as $part) {
            $id = (int)trim($part);
            if ($id > 0) $bonusIds[] = $id;
        }
    }
    $vehicleType = trim((string)($_GET['vehicle_type'] ?? ''));
    $validVehicleTypes = ['car', 'van', 'motorcycle', 'moped', 'bicycle'];
    if (!in_array($vehicleType, $validVehicleTypes, true)) {
        $vehicleType = '';
    }
    $petType = trim((string)($_GET['pet_type'] ?? ''));
    $validPetTypes = ['carrier', 'medium_cage', 'large_cage'];
    if (!in_array($petType, $validPetTypes, true)) {
        $petType = '';
    }
    return [
        'vehicles'     => max(0, min(4, (int)($_GET['vehicles'] ?? 0))),
        'vehicle_type' => $vehicleType,
        'pets'         => max(0, min(4, (int)($_GET['pets'] ?? 0))),
        'pet_type'     => $petType,
        'bonuses'      => array_values(array_unique($bonusIds)),
    ];
}

function _ferries_render_listing(array $session): void {
    global $db, $SECURE;
    $_SESSION['ferries_search'] = array_merge($session, _ferries_extra_search_params());
    $title = T::ferries . ' ' . $GLOBALS['app']['home_title'];
    require_once views . 'includes/header.php';
    require_once views . 'modules/ferries/listing/ferries.php';
    require_once views . 'includes/footer.php';
}

function _ferries_base_session(int $depPortId, int $destPortId, string $date): array {
    return [
        'departure_port_id'     => $depPortId,
        'destination_port_id'   => $destPortId,
        'departure_port_name'   => _ferries_port_name($depPortId),
        'destination_port_name' => _ferries_port_name($destPortId),
        'date'                  => $date,
    ];
}

// ROUND-TRIP WITH INFANT — 7 segments
$router->get('/ferries/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)', function (
    $depPortId, $destPortId, $date, $returnDate, $adults, $children, $infant
) use ($SECURE, $db) {

    $depPortId  = (int)$depPortId;
    $destPortId = (int)$destPortId;
    $date       = preg_replace('/[^0-9\-]/', '', $date);
    $returnDate = preg_replace('/[^0-9\-]/', '', $returnDate);
    $adults     = max(1, (int)$adults);
    $children   = max(0, (int)$children);
    $infant     = max(0, (int)$infant);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        header('Location: ' . root . 'ferries'); exit;
    }

    _ferries_render_listing(array_merge(_ferries_base_session($depPortId, $destPortId, $date), [
        'return_date' => $returnDate,
        'trip_type'   => 'return',
        'adults'      => $adults,
        'children'    => $children,
        'infant'      => $infant,
    ]));
});

// 6 SEGMENTS — disambiguate one-way+infant vs legacy round-trip by checking segment 4
$router->get('/ferries/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)', function (
    $depPortId, $destPortId, $date, $seg4, $seg5, $seg6
) use ($SECURE, $db) {

    $depPortId  = (int)$depPortId;
    $destPortId = (int)$destPortId;
    $date       = preg_replace('/[^0-9\-]/', '', $date);
    $seg4Clean  = preg_replace('/[^0-9\-]/', '', $seg4);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        header('Location: ' . root . 'ferries'); exit;
    }

    // Segment 4 is a date → legacy round-trip (no infant in URL)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $seg4Clean)) {
        $returnDate = $seg4Clean;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            header('Location: ' . root . 'ferries'); exit;
        }

        _ferries_render_listing(array_merge(_ferries_base_session($depPortId, $destPortId, $date), [
            'return_date' => $returnDate,
            'trip_type'   => 'return',
            'adults'      => max(1, (int)$seg5),
            'children'    => max(0, (int)$seg6),
            'infant'      => 0,
        ]));
        return;
    }

    // Segment 4 is numeric → one-way with infant
    _ferries_render_listing(array_merge(_ferries_base_session($depPortId, $destPortId, $date), [
        'return_date' => '',
        'trip_type'   => 'oneway',
        'adults'      => max(1, (int)$seg4),
        'children'    => max(0, (int)$seg5),
        'infant'      => max(0, (int)$seg6),
    ]));
});

// LEGACY ONE-WAY — 5 segments (no infant)
$router->get('/ferries/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)', function (
    $depPortId, $destPortId, $date, $adults, $children
) use ($SECURE, $db) {

    $depPortId  = (int)$depPortId;
    $destPortId = (int)$destPortId;
    $date       = preg_replace('/[^0-9\-]/', '', $date);
    $adults     = max(1, (int)$adults);
    $children   = max(0, (int)$children);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        header('Location: ' . root . 'ferries'); exit;
    }

    _ferries_render_listing(array_merge(_ferries_base_session($depPortId, $destPortId, $date), [
        'return_date' => '',
        'trip_type'   => 'oneway',
        'adults'      => $adults,
        'children'    => $children,
        'infant'      => 0,
    ]));
});
