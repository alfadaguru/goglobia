<?php
// path: modules/tours/adivaha/details.php
// Adivaha B2C Excursions — detail (POST tours/adivaha/details).
//
// The storefront detail page is a FRESH navigation carrying only the opaque
// tour_id (= 'adivaha_<offer_id>') + dates + travellers. We recover the full
// offer handle (destination code, supplier_code, base figures) from the search
// cache (adivahaXLookupOffer), then re-price via POST /excursions/offer to read
// the authoritative sell + tax, and return the tours detail contract the view
// consumes (price_breakdown + a booking_data handle that rides to checkout).
//
// Excursion pricing is PER-OFFER (one `sell` for the party as searched), so the
// per-person figures are the party total divided by the pax count — honest and
// consistent with search.php.

global $router;

$router->post('tours/adivaha/details', function () use ($db) {

    @set_time_limit(60);
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $tourId    = (string) ($input['tour_id'] ?? '');
    $adults    = max(1, (int) ($input['total_adults'] ?? $input['adults'] ?? 1));
    $children  = max(0, (int) ($input['total_children'] ?? $input['children'] ?? 0));
    $departure = (string) ($input['departure_date'] ?? '');
    $currency  = strtoupper((string) ($_SESSION['app_currency'] ?? $input['currency'] ?? 'USD'));

    if ($tourId === '') {
        echo json_encode(['success' => false, 'message' => 'Missing tour reference.']);
        return;
    }

    $credentials = getAdivahaExcursionCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') {
        echo json_encode(['success' => false, 'message' => 'Excursions supplier is not configured.']);
        return;
    }
    $module = $credentials['module'];
    $base   = $credentials['base_url'];
    $bearer = adivahaXBearer($db, $credentials);

    // Recover the search handle (destination is NOT in the detail URL).
    $handle = adivahaXLookupOffer($db, $tourId);
    if (empty($handle)) {
        echo json_encode(['success' => false, 'message' => 'This activity is no longer available. Please search again.']);
        return;
    }

    $offerId      = (string) ($handle['offer_id'] ?? '');
    $supplierCode = (string) ($handle['supplier'] ?? 'adivaha_excursions');
    $destination  = (string) ($handle['destination'] ?? '');
    $activityDate = (string) ($handle['activity_date'] ?? '');
    if ($departure !== '') {
        $ts = strtotime($departure);
        if ($ts === false && preg_match('#^(\d{2})-(\d{2})-(\d{4})$#', $departure, $m)) {
            $ts = strtotime($m[3] . '-' . $m[2] . '-' . $m[1]);
        }
        if ($ts !== false) $activityDate = date('Y-m-d', $ts);
    }
    $baseCurrency = strtoupper((string) ($handle['base_currency'] ?? $currency));
    $pax          = max(1, $adults + $children);

    try {
        // Re-price against the supplier (authoritative sell + tax).
        $offerBody = [
            'supplier'      => $supplierCode,
            'offer_id'      => $offerId,
            'destination'   => $destination,
            'activity_date' => $activityDate,
            'adults'        => $adults,
            'children'      => $children,
            'currency'      => $baseCurrency,
        ];
        $url = $base . '/excursions/offer?display_currency=' . urlencode($currency);
        $ores = adivahaXApiRequest('POST', $url, $offerBody, $bearer, [], 60);
        if ($ores['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaXBearer($db, $credentials, true);
            $ores = adivahaXApiRequest('POST', $url, $offerBody, $bearer, [], 60);
        }
        adivahaXLog('offer(detail)', $offerBody, $ores['raw'], $ores['http_code'], $ores['curl_error']);

        $offer = is_array($ores['data']['offer'] ?? null) ? $ores['data']['offer'] : [];
        $meta  = is_array($offer['meta'] ?? null) ? $offer['meta'] : [];

        // Authoritative net in base currency. Prefer the live reprice; fall back to
        // the cached search figure so the page still renders if reprice is momentarily
        // unavailable (the BOOKING step re-prices fail-closed regardless).
        $sellBase = (float) ($offer['sell_base'] ?? $offer['sell'] ?? $handle['sell_base'] ?? 0);
        if ($sellBase <= 0) $sellBase = (float) ($handle['sell_base'] ?? 0);

        // Per-offer price → per-person split.
        $perPersonNet = $sellBase / $pax;
        $convertedPP  = CURRENCY_CONVERT($perPersonNet, $db, $baseCurrency, $currency);
        $markedUpPP   = MARKUP($perPersonNet, $module, $db, $baseCurrency, $currency);
        $markedUpBase = MARKUP($perPersonNet, $module, $db, $baseCurrency, $baseCurrency);

        $ppDisplay      = (float) ($markedUpPP['price'] ?? 0);
        $ppDisplayBase  = (float) ($markedUpBase['price'] ?? 0);
        $ppActual       = (float) ($convertedPP['price'] ?? 0);
        $displayTotal   = round($ppDisplay * $pax, 2);
        $actualTotal    = round($ppActual * $pax, 2);

        $price_breakdown = [
            'adults' => [
                'count'                           => $adults,
                'base_price_per_person'           => round($perPersonNet, 2),
                'converted_price_per_person'      => round($ppActual, 2),
                'marked_up_price_per_person'      => round($ppDisplay, 2),
                'marked_up_price_per_person_base' => round($ppDisplayBase, 2),
                'subtotal_base'                   => round($perPersonNet * $adults, 2),
                'subtotal_converted'              => round($ppActual * $adults, 2),
                'subtotal_marked_up'              => round($ppDisplay * $adults, 2),
                'subtotal_marked_up_base'         => round($ppDisplayBase * $adults, 2),
            ],
            'children' => [
                'count'                           => $children,
                'base_price_per_person'           => round($perPersonNet, 2),
                'converted_price_per_person'      => round($ppActual, 2),
                'marked_up_price_per_person'      => round($ppDisplay, 2),
                'marked_up_price_per_person_base' => round($ppDisplayBase, 2),
                'subtotal_base'                   => round($perPersonNet * $children, 2),
                'subtotal_converted'              => round($ppActual * $children, 2),
                'subtotal_marked_up'              => round($ppDisplay * $children, 2),
                'subtotal_marked_up_base'         => round($ppDisplayBase * $children, 2),
            ],
            'summary' => [
                'total_base_price'            => round($sellBase, 2),
                'total_converted_price'       => $actualTotal,
                'total_marked_up_price'       => $displayTotal,
                'total_marked_up_price_base'  => round($ppDisplayBase * $pax, 2),
            ],
        ];

        $image  = (string) ($meta['image'] ?? '');
        $images = $image !== '' ? [['url' => $image]] : [];
        $city    = (string) ($meta['city'] ?? '');
        $country = (string) ($meta['country'] ?? '');
        $location = trim($city . ($country !== '' ? ', ' . $country : ''));

        $highlights = [];
        if (!empty($meta['highlights']) && is_array($meta['highlights'])) {
            foreach ($meta['highlights'] as $h) {
                $h = trim((string) $h);
                if ($h !== '') $highlights[] = ['name' => $h, 'icon' => 'check_circle'];
            }
        }

        // Tax (from the reprice), passed through for the checkout summary.
        $tax = is_array($ores['data']['tax'] ?? null) ? $ores['data']['tax'] : [];

        // Refreshed handle that must ride to checkout → bookings.booking_data → issue.
        $bookingHandle = [
            'supplier'      => $supplierCode,
            'offer_id'      => $offerId,
            'destination'   => $destination,
            'activity_date' => $activityDate,
            'adults'        => $adults,
            'children'      => $children,
            'infants'       => (int) ($handle['infants'] ?? 0),
            'base_currency' => $baseCurrency,
            'sell_base'     => $sellBase,
            'currency'      => $currency,
            'amount'        => $displayTotal,
            'actual_amount' => $actualTotal,
        ];
        // keep the cache fresh with the re-priced figure
        adivahaXCacheOffer($db, $offerId, $bookingHandle);

        echo json_encode([
            'success' => true,
            'data' => [
                'id'                     => $tourId,
                'tour_id'                => $tourId,
                'name'                   => (string) ($offer['title'] ?? 'Activity'),
                'tour_name'              => (string) ($offer['title'] ?? 'Activity'),
                'description'            => (string) ($offer['subtitle'] ?? ($meta['category'] ?? '')),
                'location'               => $location,
                'address'                => $location,
                'latitude'               => null,
                'longitude'              => null,
                'days'                   => 1,
                'nights'                 => 0,
                'tour_type'              => (string) ($meta['category'] ?? 'Activity'),
                'stars'                  => 0,
                'rating_average'         => 0.0,
                'rating_count'           => 0,
                'image'                  => $image,
                'images'                 => $images,
                'currency'               => $currency,
                'original_currency'      => $baseCurrency,
                'display_price'          => $displayTotal,
                'display_price_per_adult'=> round($ppDisplay, 2),
                'display_price_per_child'=> round($ppDisplay, 2),
                'actual_price'           => $actualTotal,
                'actual_price_per_adult' => round($ppActual, 2),
                'actual_price_per_child' => round($ppActual, 2),
                'actual_total_tour_price'=> round($sellBase, 2),
                'markup_total_tour_price'=> $displayTotal,
                'price_breakdown'        => $price_breakdown,
                'base_adult_price'       => round($perPersonNet, 2),
                'base_child_price'       => round($perPersonNet, 2),
                'max_adults'             => (int) ($meta['max_pax'] ?? 0),
                'max_children'           => 0,
                'current_adults'         => $adults,
                'current_children'       => $children,
                'inclusions'             => $highlights,
                'exclusions'             => [],
                'amenities'              => [],
                'itinerary'              => [],
                'cancellation_policy'    => (string) ($offer['cancel_policy'] ?? ''),
                'tax'                    => $tax,
                // The opaque handle checkout must forward (see tour detail bookNow()).
                'booking_data'           => $bookingHandle,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('Adivaha excursions detail error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not load this activity. Please try again.']);
    }
});
