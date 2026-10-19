<?php
// ============================================================================
// FILE: app/lib/stays/listing.php
// Reusable local-inventory Stays card builder for GIA.
//
// The public stays listing lives in the MOBILE REST API
// (app/routes/api/stays/listingRoutes.php, GET /api/stays/.../...) which is
// behind the API-key gate and also fires a search webhook. GIA must not depend
// on that HTTP route or trigger its side effects, so this extracts ONLY the
// read-only local-inventory lookup + card assembly (the same `stays` +
// `stays_rooms` query, same min-price logic, same approved-listing filter) into
// a plain function GIA can call directly with $db.
//
// Card shape is normalized to the GIA result-card contract (name / subtitle /
// price / currency / image / slug) so it feeds modelFacingOptions() and the
// rich-card UI exactly like umrah. starting_price is preserved verbatim from the
// source (raw room_options.price — NO markup is applied in the source listing,
// so none is applied here).
// ============================================================================

if (!function_exists('staysListingCards')) {
    /**
     * Build real, local, approved stays cards for a destination.
     *
     * @param array $input Keys: destination (city/location, required to match),
     *                     currency (display only), items_limit (0 = default cap).
     * @param object $db   Medoo instance.
     * @return array{cards: array<int,array<string,mixed>>}
     */
    function staysListingCards(array $input, $db): array
    {
        $destination = trim((string)($input['destination'] ?? ''));
        if ($destination === '' || strtolower($destination) === 'any') {
            // Stays needs a place to be meaningful; no blind "all hotels" dump.
            return ['cards' => []];
        }
        $displayCurrency = strtoupper(trim((string)($input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'))));
        if ($displayCurrency === '') {
            $displayCurrency = 'USD';
        }
        $rawLimit = (int)($input['items_limit'] ?? 0);
        $limit = $rawLimit <= 0 ? 6 : max(1, min(50, $rawLimit));

        $destinationForSearch = str_replace('-', ' ', $destination);

        // Public search shows only LIVE + APPROVED listings (same filter as the
        // listing endpoint). status=1 = owner on/off; listing_status=approved =
        // admin moderation gate.
        $hotels = $db->select('stays', [
            'id', 'img', 'name', 'slug', 'location', 'location_coords', 'stars', 'rating', 'currency'
        ], [
            'location[~]' => '%' . $destinationForSearch . '%',
            'status' => 1,
            'listing_status' => 'approved',
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => $limit,
        ]) ?: [];

        $cards = [];
        foreach ($hotels as $hotel) {
            // Minimum price across all this hotel's room options (verbatim logic).
            $minPrice = null;
            $rooms = $db->select('stays_rooms', ['room_options'], [
                'stay_id' => $hotel['id'],
                'status' => 1,
            ]) ?: [];
            foreach ($rooms as $r) {
                $options = json_decode((string)($r['room_options'] ?? ''), true);
                if (is_array($options)) {
                    foreach ($options as $opt) {
                        if (isset($opt['price'])) {
                            $currentPrice = (float)$opt['price'];
                            if ($minPrice === null || $currentPrice < $minPrice) {
                                $minPrice = $currentPrice;
                            }
                        }
                    }
                }
            }

            // Primary image (same resolution as the listing endpoint).
            $image = '';
            $imgDecoded = json_decode((string)($hotel['img'] ?? ''), true);
            if (is_array($imgDecoded)) {
                foreach ($imgDecoded as $img) {
                    if (!empty($img['url'])) {
                        $image = rtrim(root, '/') . '/' . ltrim((string)$img['url'], '/');
                        break;
                    }
                }
            }

            $stars = (int)($hotel['stars'] ?? 0);
            $loc = (string)($hotel['location'] ?? '');
            $hotelCurrency = (string)($hotel['currency'] ?? 'USD');

            $cards[] = [
                'id'         => (string)$hotel['id'],
                'kind'       => 'stays',
                'supplier'   => 'stays',
                'name'       => (string)($hotel['name'] ?? 'Hotel'),
                'title'      => (string)($hotel['name'] ?? 'Hotel'),
                'subtitle'   => trim($loc . ($stars > 0 ? ' · ' . $stars . '-star' : '')),
                'location'   => $loc,
                'stars'      => $stars,
                'rating'     => (float)($hotel['rating'] ?? 0),
                // Price is "from" (cheapest room option), raw — NO markup, matching
                // the source listing's starting_price exactly.
                'price'      => $minPrice !== null ? round($minPrice, 2) : null,
                'from_price' => $minPrice !== null ? round($minPrice, 2) : null,
                // Source listing does not convert currency here; keep the hotel's own.
                'currency'   => $hotelCurrency !== '' ? $hotelCurrency : $displayCurrency,
                'image'      => $image,
                'slug'       => (string)($hotel['slug'] ?? ''),
            ];
        }

        return ['cards' => $cards];
    }
}
