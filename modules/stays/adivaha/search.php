<?php
// path: modules/stays/adivaha/search.php
// Adivaha B2C Hotels — search (batched → sync bridge) + offer normalisation.
// POST stays/adivaha/search
//
// Adivaha hotel search is BATCHED: POST /hotels/search returns trace_id + status
// (in_progress|complete) + the hotels priced so far; you poll GET
// /hotels/search-poll?trace_id=... until status == complete. The platform calls
// each stays supplier's /search synchronously and pages the results, so this
// file hides the batching: it starts the search (with wait_seconds so the first
// answer already carries a batch), polls up to a server-side deadline for more,
// then returns the normalised hotels in the platform's wrapped shape
// { status:'success', results:[...], total, page, per_page, total_pages, has_more }.
// Each result hotel carries the fields normalizeHotels() reads (hotel_id/name/
// image/price/currency/room_options/...), with MARKUP() applied.

global $router;

$router->post('stays/adivaha/search', function () use ($db) {

    @set_time_limit(120);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (connection_aborted()) { exit; }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    try {
        $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'stays']);
        if (!$module) {
            echo json_encode(['status' => 'error', 'message' => 'Adivaha hotels module not configured', 'results' => []]);
            exit;
        }
        $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Adivaha hotels API key not configured.', 'results' => []]);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        if ($bearer === '') {
            echo json_encode(['status' => 'error', 'message' => 'Adivaha authentication unavailable.', 'results' => []]);
            exit;
        }
        $base = $credentials['base_url'];

        // ---- Inputs (same contract as every stays provider) -----------------
        // The platform sends `destination` (a city NAME, e.g. "New Delhi") and a
        // `destination_code` from the stay-search autocomplete — but that code is
        // the platform's, NOT Adivaha's. Adivaha's /hotels/search needs a code
        // from ITS OWN /hotels/destinations, so we resolve it below by name.
        $destName    = trim((string) ($_POST['destination'] ?? $_POST['city'] ?? ''));
        $destCodeIn  = trim((string) ($_POST['destination_code'] ?? ''));
        $checkinRaw  = trim((string) ($_POST['checkin'] ?? ''));
        $checkoutRaw = trim((string) ($_POST['checkout'] ?? ''));
        $rooms       = max(1, (int) ($_POST['rooms'] ?? 1));
        $adults      = max(1, (int) ($_POST['adults'] ?? 2));
        $children    = max(0, (int) ($_POST['children'] ?? $_POST['childs'] ?? 0));
        $nationality = trim((string) ($_POST['nationality'] ?? ''));
        $currency    = strtoupper((string) ($_POST['currency'] ?? 'USD'));
        $page        = max(1, (int) ($_POST['page'] ?? 1));
        $perPage     = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));

        if (($destName === '' && $destCodeIn === '') || $checkinRaw === '' || $checkoutRaw === '') {
            echo json_encode(['status' => 'error', 'message' => 'destination, checkin and checkout are required.', 'results' => []]);
            exit;
        }

        // Dates → YYYY-MM-DD; check_out must be after check_in.
        $ciTs = strtotime($checkinRaw);
        $coTs = strtotime($checkoutRaw);
        if ($ciTs === false || $coTs === false || $coTs <= $ciTs) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid check-in / check-out dates.', 'results' => []]);
            exit;
        }
        $checkIn  = date('Y-m-d', $ciTs);
        $checkOut = date('Y-m-d', $coTs);
        $nights   = max(1, (int) round(($coTs - $ciTs) / 86400));

        // child_ages: explicit list if provided, else synthesise from children.
        $childAges = [];
        if (!empty($_POST['child_ages'])) {
            $raw = $_POST['child_ages'];
            if (is_string($raw)) { $raw = json_decode($raw, true); }
            if (is_array($raw)) { foreach ($raw as $a) { $childAges[] = max(0, min(17, (int) $a)); } }
        }
        if (empty($childAges) && $children > 0) {
            for ($i = 0; $i < $children; $i++) { $childAges[] = 8; }
        }

        // ---- Resolve Adivaha's destination CODE from the city name ----------
        // Adivaha /hotels/search needs a code from ITS /hotels/destinations. We
        // look it up by the city name; prefer an exact (case-insensitive) match
        // on the destination name, else take the first result. If the platform
        // already passed something that looks like an Adivaha code we still verify
        // it resolves — otherwise search would 400.
        $destination = '';
        $lookup = $destName !== '' ? $destName : $destCodeIn;
        if ($lookup !== '') {
            $dres = adivahaHApiRequest('GET', $base . '/hotels/destinations?q=' . urlencode($lookup) . '&limit=10', null, $bearer, [], 25);
            if ($dres['http_code'] === 401 && $credentials['partner_id'] !== '') {
                $bearer = adivahaHBearer($db, $credentials, true);
                $dres = adivahaHApiRequest('GET', $base . '/hotels/destinations?q=' . urlencode($lookup) . '&limit=10', null, $bearer, [], 25);
            }
            $dlist = (is_array($dres['data'] ?? null) && is_array($dres['data']['destinations'] ?? null)) ? $dres['data']['destinations'] : [];
            if (!empty($dlist)) {
                $wanted = strtolower($destName !== '' ? $destName : $destCodeIn);
                foreach ($dlist as $d) {
                    if (!is_array($d)) continue;
                    if (strtolower((string) ($d['name'] ?? '')) === $wanted || strtolower((string) ($d['code'] ?? '')) === $wanted) {
                        $destination = (string) ($d['code'] ?? ''); break;
                    }
                }
                if ($destination === '') { $destination = (string) ($dlist[0]['code'] ?? ''); }
            }
        }
        if ($destination === '') {
            // No Adivaha destination matched → no hotels to return (not an error;
            // the destination simply isn't in Adivaha's bookable set).
            adivahaHLog('destinations/none', ['q' => $lookup], '', 200, 'no match');
            header('X-Total-Results: 0');
            echo json_encode(['status' => 'success', 'results' => [], 'total' => 0, 'destination_total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0, 'has_more' => false, 'search_complete' => true]);
            exit;
        }

        // ---- Build the Adivaha HotelSearchRequest ----------------------------
        $searchBody = [
            'destination' => $destination,
            'check_in'    => $checkIn,
            'check_out'   => $checkOut,
            'rooms'       => $rooms,
            'adults'      => $adults,
            'currency'    => $currency,
            // give the first answer a real batch so page 1 is populated
            'wait_seconds' => 20,
            'limit'       => 100,
            'offset'      => 0,
        ];
        if (!empty($childAges)) $searchBody['child_ages'] = $childAges;
        if ($nationality !== '') $searchBody['nationality'] = $nationality;

        // ---- Step 1: POST /hotels/search -> trace_id + first batch -----------
        $url = $base . '/hotels/search?display_currency=' . urlencode($currency);
        $startRes = adivahaHApiRequest('POST', $url, $searchBody, $bearer, [], 60);
        if ($startRes['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $startRes = adivahaHApiRequest('POST', $url, $searchBody, $bearer, [], 60);
        }
        adivahaHLog('search', $searchBody, $startRes['raw'], $startRes['http_code'], $startRes['curl_error']);

        if (!$startRes['success'] || !is_array($startRes['data'])) {
            // A 400 can be an invalid/too-long stay — surface the supplier message.
            echo json_encode(['status' => 'error', 'message' => 'Adivaha hotel search failed: ' . adivahaHExtractError($startRes), 'results' => []]);
            exit;
        }

        $poll     = $startRes['data'];
        $traceId  = (string) ($poll['trace_id'] ?? '');
        $statusV  = (string) ($poll['status'] ?? 'complete');
        $offers   = is_array($poll['offers'] ?? null) ? $poll['offers'] : [];

        // ---- Step 2: poll until complete (bounded) ---------------------------
        if ($statusV !== 'complete' && $traceId !== '') {
            $deadline = microtime(true) + 45; // hard cap under set_time_limit(120)
            while (microtime(true) < $deadline && $statusV !== 'complete') {
                if (connection_aborted()) { exit; }
                $pollUrl = $base . '/hotels/search-poll?trace_id=' . urlencode($traceId)
                    . '&limit=100&offset=0&wait_seconds=15&display_currency=' . urlencode($currency);
                $pr = adivahaHApiRequest('GET', $pollUrl, null, $bearer, [], 30);
                if ($pr['success'] && is_array($pr['data'])) {
                    $poll    = $pr['data'];
                    $statusV = (string) ($poll['status'] ?? 'complete');
                    if (is_array($poll['offers'] ?? null) && count($poll['offers']) > 0) {
                        $offers = $poll['offers'];
                    }
                } else {
                    break; // transient poll failure — use what we have
                }
            }
        }

        // ---- Step 3: normalise offers into the platform hotel shape ----------
        $formatted = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $meta = is_array($offer['meta'] ?? null) ? $offer['meta'] : [];

            $propertyId = $meta['property_id'] ?? null;
            if ($propertyId === null) continue; // need a hotel id to detail/book

            // sell_base = our cost (wallet debited sell+tax), in base currency.
            // Mark it up for the customer + convert to the display currency.
            $sellBase     = (float) ($offer['sell_base'] ?? $offer['sell'] ?? 0);
            $baseCurrency = strtoupper((string) ($offer['base_currency'] ?? $offer['currency'] ?? $currency));
            if ($sellBase <= 0) continue;

            $converted = CURRENCY_CONVERT($sellBase, $db, $baseCurrency, $currency);
            $markedUp  = MARKUP($sellBase, $module, $db, $baseCurrency, $currency);
            if (!is_array($converted) || !isset($converted['price'])) $converted = ['price' => $sellBase];
            if (!is_array($markedUp)  || !isset($markedUp['price']))  $markedUp  = ['price' => $sellBase];

            $totalPrice = (float) $markedUp['price'];
            $perNight   = $nights > 0 ? round($totalPrice / $nights, 2) : $totalPrice;

            $images = [];
            if (!empty($meta['hero_image'])) $images[] = (string) $meta['hero_image'];

            $formatted[] = [
                'hotel_id'        => 'adivaha_' . $propertyId, // provider-prefixed for uniqueness
                'id'              => 'adivaha_' . $propertyId,
                'original_id'     => (string) $propertyId,
                'name'            => (string) ($meta['property_name'] ?? $offer['title'] ?? 'Hotel'),
                'stars'           => (int) ($meta['stars'] ?? 0),
                'star_rating'     => (int) ($meta['stars'] ?? 0),
                'rating'          => 0,
                'address'         => (string) ($meta['address'] ?? ''),
                'city'            => (string) ($meta['city'] ?? ''),
                'country'         => strtoupper((string) ($meta['country_iso2'] ?? '')),
                'location'        => trim((string) (($meta['city'] ?? '') . (!empty($meta['country_iso2']) ? ', ' . $meta['country_iso2'] : ''))),
                'latitude'        => $meta['lat'] ?? null,
                'longitude'       => $meta['lng'] ?? null,
                'images'          => $images,
                'image'           => $images[0] ?? '',
                'img'             => $images[0] ?? '',
                'price'           => round($totalPrice, 2),
                'display_price'   => round($totalPrice, 2),
                'price_per_night' => $perNight,
                'display_price_per_night' => $perNight,
                'actual_price'    => round((float) $converted['price'], 2),
                'original_price'  => round((float) $converted['price'], 2),
                'currency'        => $currency,
                'original_currency' => $baseCurrency,
                'nights'          => $nights,
                'free_cancellation'  => !empty($meta['refundable']),
                'breakfast_included' => (stripos((string) ($meta['board'] ?? $meta['meal_plan'] ?? ''), 'breakfast') !== false),
                'meal'            => (string) ($meta['board'] ?? $meta['meal_plan'] ?? ''),
                'cancel_summary'  => (string) ($meta['cancel_summary'] ?? $offer['cancel_policy'] ?? ''),
                'accommodation_type' => 'Hotel',
                // room_options are loaded on demand via /stays/adivaha/rooms; the
                // search offer is the cheapest rate only. We carry the offer handle
                // so rooms/offer/book can reference this exact offer.
                'room_options'    => [],
                'has_available_rooms' => true,
                'supplier'        => 'adivaha',
                'supplier_name'   => (string) ($offer['supplier_name'] ?? 'Adivaha Hotels'),
                // Opaque booking handle for this hotel's cheapest offer — passed to
                // offer/book; rooms.php returns per-rate handles for specific rooms.
                'booking_data'    => [
                    'supplier'       => (string) ($offer['supplier_code'] ?? 'adivaha_hotels'),
                    'offer_id'       => (string) ($offer['id'] ?? ''),
                    'property_id'    => (int) $propertyId,
                    'destination'    => $destination,
                    'check_in'       => $checkIn,
                    'check_out'      => $checkOut,
                    'rooms'          => $rooms,
                    'adults'         => $adults,
                    'child_ages'     => $childAges,
                    'nationality'    => $nationality,
                    'base_currency'  => $baseCurrency,
                    'sell_base'      => $sellBase,
                    'amount'         => $totalPrice,
                    'actual_amount'  => (float) $converted['price'],
                ],
                'color'           => (string) ($module['module_color'] ?? '#1f9d8b'),
            ];
        }

        // ---- Page the normalised list (platform paginates per supplier) ------
        $total     = count($formatted);
        $offsetIdx = ($page - 1) * $perPage;
        $pageItems = array_slice($formatted, $offsetIdx, $perPage);
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        // has_more is driven purely by the page math over the offers we actually
        // have. Each stays page call re-runs the whole /hotels/search (stateless),
        // so signalling "more" just because Adivaha hadn't reported `complete`
        // within the deadline would make the frontend re-run the entire search
        // for page 2 with nothing new to show (redundant supplier + wallet-quote
        // churn). Only promise more pages when more offers exist to page into.
        $hasMore   = ($page < $totalPages);

        // Pagination hint headers (same as other live suppliers).
        header('X-Total-Results: ' . $total);
        header('X-Destination-Total: ' . $total);
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $perPage);
        header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

        echo json_encode([
            'status'            => 'success',
            'results'           => array_values($pageItems),
            'total'             => $total,
            'destination_total' => $total,
            'page'              => $page,
            'per_page'          => $perPage,
            'total_pages'       => $totalPages,
            'has_more'          => $hasMore,
            'search_complete'   => ($statusV === 'complete'),
            'currency'          => $currency,
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL SEARCH ERROR: ' . $e->getMessage());
        if (!headers_sent()) { header('Content-Type: application/json'); }
        echo json_encode(['status' => 'error', 'message' => 'Adivaha hotel search failed. Please try again.', 'results' => []]);
        exit;
    }
});
