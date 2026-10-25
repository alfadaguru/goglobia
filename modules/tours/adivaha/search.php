<?php
// path: modules/tours/adivaha/search.php
// Adivaha B2C Excursions — search (POST tours/adivaha/search).
//
// Flow (openapi-tours.yaml):
//   1. GET  /excursions/destinations?q=<text>   → resolve a 3-letter destination code
//   2. POST /excursions/search                  → synchronous federated offer list
// Each offer is normalised to the TOURS search contract the storefront consumes
// (see app/views/modules/tours/listing/tours.php normalizeTours()): a BARE JSON
// array, each item carrying display_price / display_price_per_person / actual_price
// / currency / name / location / images / inclusions, with the opaque booking
// handle stashed under `booking_data` (rides to the detail/checkout as original_data).
//
// PRICE: `sell_base` (base currency) is the supplier net we apply MARKUP() to; the
// customer price is MARKUP(sell_base) converted into the display currency.
// SUPPLIER HIDING: the supplier BRAND ("Adivaha Excursions") is NEVER placed in a
// customer-visible field — only the internal key `supplier => 'adivaha'` (which the
// storefront further masks behind an opaque URL token).

global $router;

$router->post('tours/adivaha/search', function () use ($db) {

    @set_time_limit(60);
    header('Content-Type: application/json');

    // Release the session lock so a slow supplier fan-out never blocks other tabs.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionSnapshot = $_SESSION;
        session_write_close();
    } else {
        $sessionSnapshot = [];
    }

    $credentials = getAdivahaExcursionCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') {
        echo json_encode([]); // supplier not configured → contribute nothing to the merge
        return;
    }
    $module = $credentials['module'];
    $base   = $credentials['base_url'];
    $bearer = adivahaXBearer($db, $credentials);
    if ($bearer === '') { echo json_encode([]); return; }

    // ---- Inputs (storefront tours search params) ---------------------------
    $destText   = trim((string) ($_POST['destination'] ?? $_POST['city'] ?? ''));
    $destCodeIn = strtoupper(trim((string) ($_POST['destination_code'] ?? '')));
    $startRaw   = trim((string) ($_POST['start_date'] ?? ''));
    $adults     = max(1, (int) ($_POST['adults'] ?? 1));
    $children   = max(0, (int) ($_POST['children'] ?? 0));
    $infants    = max(0, (int) ($_POST['infants'] ?? 0));
    $currency   = strtoupper((string) ($sessionSnapshot['app_currency'] ?? $_POST['currency'] ?? 'USD'));
    $page       = max(1, (int) ($_POST['page'] ?? 1));
    $perPage    = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));

    // activity_date is YYYY-MM-DD. The storefront sends DD-MM-YYYY (or similar).
    $activityDate = '';
    if ($startRaw !== '') {
        $ts = strtotime($startRaw);
        if ($ts === false && preg_match('#^(\d{2})-(\d{2})-(\d{4})$#', $startRaw, $m)) {
            $ts = strtotime($m[3] . '-' . $m[2] . '-' . $m[1]);
        }
        if ($ts !== false) $activityDate = date('Y-m-d', $ts);
    }
    if ($activityDate === '') $activityDate = date('Y-m-d', strtotime('+3 days'));

    try {
        // ---- 1. Resolve destination code ----------------------------------
        $destCode = $destCodeIn;
        if ($destCode === '' && $destText !== '') {
            $dres = adivahaXApiRequest('GET', $base . '/excursions/destinations?q=' . urlencode($destText) . '&limit=10', null, $bearer, [], 30);
            if ($dres['http_code'] === 401 && $credentials['partner_id'] !== '') {
                $bearer = adivahaXBearer($db, $credentials, true);
                $dres = adivahaXApiRequest('GET', $base . '/excursions/destinations?q=' . urlencode($destText) . '&limit=10', null, $bearer, [], 30);
            }
            adivahaXLog('destinations', ['q' => $destText], $dres['raw'], $dres['http_code'], $dres['curl_error']);
            $dlist = is_array($dres['data']['destinations'] ?? null) ? $dres['data']['destinations'] : [];
            foreach ($dlist as $d) {
                // Prefer an exact label/city match, else the first result.
                if (strcasecmp(trim((string) ($d['label'] ?? '')), $destText) === 0) { $destCode = strtoupper((string) ($d['code'] ?? '')); break; }
            }
            if ($destCode === '' && !empty($dlist)) {
                $destCode = strtoupper((string) ($dlist[0]['code'] ?? ''));
            }
        }
        if ($destCode === '') { echo json_encode([]); return; }

        // ---- 2. Search ----------------------------------------------------
        $searchBody = [
            'destination'   => $destCode,
            'activity_date' => $activityDate,
            'adults'        => $adults,
            'children'      => $children,
            'infants'       => $infants,
            'currency'      => $currency,
            'limit'         => 100,
        ];
        $url = $base . '/excursions/search?display_currency=' . urlencode($currency);
        $sres = adivahaXApiRequest('POST', $url, $searchBody, $bearer, [], 60);
        if ($sres['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaXBearer($db, $credentials, true);
            $sres = adivahaXApiRequest('POST', $url, $searchBody, $bearer, [], 60);
        }
        adivahaXLog('search', $searchBody, $sres['raw'], $sres['http_code'], $sres['curl_error']);

        if (!$sres['success'] || !is_array($sres['data'])) {
            echo json_encode([]); // fail soft — don't break the multi-supplier merge
            return;
        }

        $offers = is_array($sres['data']['offers'] ?? null) ? $sres['data']['offers'] : [];

        // ---- Normalise to the tours search contract -----------------------
        $formatted = [];
        foreach ($offers as $offer) {
            $offerId = (string) ($offer['id'] ?? '');
            if ($offerId === '') continue;

            $sellBase     = (float) ($offer['sell_base'] ?? $offer['sell'] ?? 0);
            $baseCurrency = strtoupper((string) ($offer['base_currency'] ?? $offer['currency'] ?? $currency));
            if ($sellBase <= 0) continue;

            // Actual (net, converted) and marked-up customer price, per the house pattern.
            $converted = CURRENCY_CONVERT($sellBase, $db, $baseCurrency, $currency);
            $markedUp  = MARKUP($sellBase, $module, $db, $baseCurrency, $currency);

            $meta       = is_array($offer['meta'] ?? null) ? $offer['meta'] : [];
            $pax        = max(1, $adults + $children);
            $perPerson  = (float) ($markedUp['price'] ?? 0);
            $actualPP   = (float) ($converted['price'] ?? 0);

            $image = (string) ($meta['image'] ?? '');
            $images = $image !== '' ? [$image] : [];

            $highlights = [];
            if (!empty($meta['highlights']) && is_array($meta['highlights'])) {
                foreach ($meta['highlights'] as $h) {
                    $h = trim((string) $h);
                    if ($h !== '') $highlights[] = ['name' => $h, 'icon' => 'check_circle'];
                }
            }

            $city    = (string) ($meta['city'] ?? $destText);
            $country = (string) ($meta['country'] ?? '');
            $location = trim($city . ($country !== '' ? ', ' . $country : ''));

            // Opaque booking handle — everything actions/issue.php + details.php need
            // to re-price and book. The supplier BRAND stays out of displayed fields.
            $bookingData = [
                'supplier'      => (string) ($offer['supplier_code'] ?? 'adivaha_excursions'),
                'offer_id'      => $offerId,
                'destination'   => $destCode,
                'activity_date' => $activityDate,
                'adults'        => $adults,
                'children'      => $children,
                'infants'       => $infants,
                'base_currency' => $baseCurrency,
                'sell_base'     => $sellBase,
                'currency'      => $currency,
                'amount'        => round($perPerson * $pax, 2),
                'actual_amount' => round($actualPP * $pax, 2),
            ];

            // Stash the handle so the fresh-nav detail page + issue can recover the
            // destination code (not present in the SEO URL) and re-price/book.
            adivahaXCacheOffer($db, $offerId, $bookingData);

            $formatted[] = [
                'tour_id'                  => 'adivaha_' . $offerId,
                'id'                       => 'adivaha_' . $offerId,
                'original_id'              => $offerId,
                'name'                     => (string) ($offer['title'] ?? 'Activity'),
                'slug'                     => '',
                'img'                      => $image,
                'image'                    => $image,
                'images'                   => $images,
                'location'                 => $location,
                'city'                     => $city,
                'country'                  => $country,
                'address'                  => $location,
                'description'              => (string) ($offer['subtitle'] ?? ($meta['category'] ?? '')),
                'days'                     => 1,
                'nights'                   => 0,
                'duration'                 => (string) ($meta['duration'] ?? ''),
                'tour_type'                => (string) ($meta['category'] ?? 'Activity'),
                'stars'                    => 0,
                'rating'                   => 0,
                'review_count'             => 0,
                // Pricing — customer price (marked up) vs net (converted).
                'currency'                 => $currency,
                'original_currency'        => $baseCurrency,
                'display_price'            => round($perPerson * $pax, 2),
                'display_price_per_person' => round($perPerson, 2),
                'price'                    => round($perPerson * $pax, 2),
                'actual_price'             => round($actualPP * $pax, 2),
                'actual_price_per_person'  => round($actualPP, 2),
                'base_adult_price'         => $sellBase,
                // Capacity / content
                'max_adults'               => (int) ($meta['max_pax'] ?? 0),
                'max_travelers'            => (int) ($meta['max_pax'] ?? 0),
                'max_children'             => 0,
                'inclusions'               => $highlights,
                'exclusions'               => [],
                'amenities'                => [],
                'free_cancellation'        => !empty($meta['is_refundable']),
                'cancel_summary'           => (string) ($offer['cancel_policy'] ?? ''),
                'has_available_slots'      => true,
                // Internal only — NOT rendered as a customer label.
                'supplier'                 => 'adivaha',
                'booking_data'             => $bookingData,
            ];
        }

        // Platform-side pagination of the fetched offers (supplier returns them all).
        $total      = count($formatted);
        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;
        $pageItems  = array_slice($formatted, $offset, $perPage);
        $hasMore    = $page < $totalPages;

        header('X-Total-Results: ' . $total);
        header('X-Total-Pages: ' . max(1, $totalPages));
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $perPage);
        header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

        echo json_encode(array_values($pageItems));
    } catch (Throwable $e) {
        error_log('Adivaha excursions search error: ' . $e->getMessage());
        echo json_encode([]);
    }
});
