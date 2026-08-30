<?php
global $db;

// ===========================================================================
// HELPER: Get Duffel API token from DB
// ===========================================================================
function getDuffelToken($db)
{
    $module = $db->get('modules', ['c1'], ['name' => 'duffel', 'type' => 'flights']);
    return $module['c1'] ?? '';
}

// ===========================================================================
// POST /flights/duffel/seat-map
// Returns fully transformed seat map with flat_layout + flat_elements per row
// so frontend can render a perfect grid like Duffel's own UI
// ===========================================================================
$router->post('/flights/duffel/seat-map', function () use ($db) {
    header('Content-Type: application/json');

    $offer_id = $_POST['offer_id'] ?? '';
    if (!$offer_id) {
        echo json_encode(['status' => false, 'message' => 'Offer ID missing']);
        exit;
    }

    $token = getDuffelToken($db);

    $ch = curl_init("https://api.duffel.com/air/seat_maps?offer_id=" . urlencode($offer_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Duffel-Version: v2',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = json_decode($raw, true);

    if ($httpCode !== 200 || empty($response['data'])) {
        echo json_encode(['status' => false, 'message' => 'No seat map available', 'data' => []]);
        exit;
    }

    // =========================================================================
    // Transform Duffel response into a flat grid structure
    // =========================================================================
    $segments = [];
    foreach ($response['data'] as $seg) {
        $cabins = [];
        foreach ($seg['cabins'] ?? [] as $cabin) {
            $allRows = $cabin['rows'] ?? [];

            // ---- Build flat_layout (column headers) from first row ----
            // Creates: [{ type: 'header', label: 'A' }, { type: 'aisle', label: '' }, { type: 'header', label: 'D' }, ...]
            $flatLayout = [];
            $firstRow = $allRows[0] ?? null;
            if ($firstRow) {
                foreach ($firstRow['sections'] ?? [] as $sIdx => $section) {
                    if ($sIdx > 0) {
                        // Aisle separator between sections
                        $flatLayout[] = ['type' => 'aisle', 'label' => ''];
                    }
                    foreach ($section['elements'] ?? [] as $el) {
                        $elType = $el['type'] ?? 'empty';
                        if ($elType === 'seat' && !empty($el['designator'])) {
                            $flatLayout[] = [
                                'type' => 'header',
                                'label' => preg_replace('/[0-9]+/', '', $el['designator']),
                            ];
                        } else {
                            // Non-seat elements still need a column header cell for alignment
                            $flatLayout[] = ['type' => 'spacer', 'label' => ''];
                        }
                    }
                }
            }

            // ---- Transform each row into flat_elements ----
            $rows = [];
            foreach ($allRows as $rowIdx => $row) {
                // Extract row number from first seat designator in this row
                $rowNum = null;
                foreach ($row['sections'] ?? [] as $section) {
                    foreach ($section['elements'] ?? [] as $el) {
                        if (($el['type'] ?? '') === 'seat' && !empty($el['designator'])) {
                            preg_match('/\d+/', $el['designator'], $m);
                            if (!empty($m[0]))
                                $rowNum = (int) $m[0];
                            break 2;
                        }
                    }
                }
                if ($rowNum === null)
                    $rowNum = $rowIdx + 1;

                // Build flat_elements: all elements from all sections + aisle markers between sections
                $flatElements = [];
                foreach ($row['sections'] ?? [] as $sIdx => $section) {
                    if ($sIdx > 0) {
                        // Aisle marker with row number (goes between seat sections)
                        $flatElements[] = [
                            'type' => 'aisle',
                            'row_number' => $rowNum,
                        ];
                    }
                    foreach ($section['elements'] ?? [] as $el) {
                        $flatElements[] = [
                            'type' => $el['type'] ?? 'empty',
                            'designator' => $el['designator'] ?? null,
                            'available_services' => $el['available_services'] ?? [],
                            'disclosures' => $el['disclosures'] ?? [],
                        ];
                    }
                }

                $rows[] = [
                    'row_number' => $rowNum,
                    'flat_elements' => $flatElements,
                ];
            }

            $cabins[] = [
                'cabin_class' => $cabin['cabin_class'] ?? 'economy',
                'flat_layout' => $flatLayout,
                'rows' => $rows,
            ];
        }

        $segments[] = [
            'segment_id' => $seg['segment_id'] ?? '',
            'cabins' => $cabins,
        ];
    }

    echo json_encode(['status' => true, 'data' => $segments]);
    exit;
});

// ===========================================================================
// POST /flights/duffel/ancillaries
// Returns passengers list + baggage available_services
// ===========================================================================
$router->post('/flights/duffel/ancillaries', function () use ($db) {
    header('Content-Type: application/json');

    $offer_id = $_POST['offer_id'] ?? '';
    if (!$offer_id) {
        echo json_encode(['status' => false, 'message' => 'Offer ID missing']);
        exit;
    }

    $token = getDuffelToken($db);

    $ch = curl_init("https://api.duffel.com/air/offers/" . urlencode($offer_id) . "?return_available_services=true");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Duffel-Version: v2',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = json_decode($raw, true);

    if ($httpCode !== 200 || empty($response['data'])) {
        echo json_encode(['status' => false, 'message' => 'Could not load ancillaries', 'data' => null]);
        exit;
    }

    $offerData = $response['data'];

    // Only expose baggage-type services
    $baggageServices = array_values(array_filter(
        $offerData['available_services'] ?? [],
        fn($s) => ($s['type'] ?? '') === 'baggage'
    ));

    echo json_encode([
        'status' => true,
        'data' => [
            'passengers' => $offerData['passengers'] ?? [],
            'available_services' => $baggageServices,
        ],
    ]);
    exit;
});
