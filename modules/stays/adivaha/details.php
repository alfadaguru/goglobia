<?php
// path: modules/stays/adivaha/details.php
// Adivaha B2C Hotels — hotel content (description, photos, amenities, policies).
// POST stays/adivaha/details   (JSON body)
//
// Maps to Adivaha GET /hotels/details?hotel_id=<property_id>. The platform sends
// a provider-prefixed hotel_id ("adivaha_<property_id>"); we strip the prefix to
// the integer property_id Adivaha expects. Returns { success, data:{...} } in the
// shape the stays detail page reads (name/address/description/images[]/amenities/
// stars/lat/lng/policies/phone/email/website).

global $router;

$router->post('/stays/adivaha/details', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;

        $hotelId = (string) ($input['hotel_id'] ?? '');
        // Strip the "adivaha_" prefix added at search time → integer property_id.
        if (stripos($hotelId, 'adivaha_') === 0) {
            $hotelId = substr($hotelId, 8);
        }
        $hotelId = trim($hotelId);
        if ($hotelId === '' || !ctype_digit($hotelId)) {
            echo json_encode(['success' => false, 'message' => 'A valid hotel_id is required.']);
            exit;
        }

        $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['success' => false, 'message' => 'Adivaha hotels API credentials not configured.']);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        $base   = $credentials['base_url'];

        $url = $base . '/hotels/details?hotel_id=' . urlencode($hotelId);
        $res = adivahaHApiRequest('GET', $url, null, $bearer, [], 45);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $res = adivahaHApiRequest('GET', $url, null, $bearer, [], 45);
        }
        adivahaHLog('details', ['hotel_id' => $hotelId], $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data']) || empty($res['data']['hotel'])) {
            echo json_encode(['success' => false, 'message' => 'Could not load hotel details: ' . adivahaHExtractError($res)]);
            exit;
        }

        $h = $res['data']['hotel'];

        // Normalise images → a flat array of URL strings (the frontend accepts
        // either a URL string or a {url} object, but strings are simplest).
        $images = [];
        if (!empty($h['images']) && is_array($h['images'])) {
            foreach ($h['images'] as $img) {
                if (is_string($img)) { $images[] = $img; }
                elseif (is_array($img)) { $images[] = (string) ($img['url'] ?? $img['src'] ?? $img['image'] ?? ''); }
            }
            $images = array_values(array_filter($images, fn($u) => $u !== ''));
        }

        // Amenities → a flat string array (frontend shows chips).
        $amenities = [];
        if (!empty($h['amenities']) && is_array($h['amenities'])) {
            foreach ($h['amenities'] as $a) {
                if (is_string($a)) { $amenities[] = $a; }
                elseif (is_array($a)) { $amenities[] = (string) ($a['name'] ?? $a['label'] ?? $a['title'] ?? ''); }
            }
            $amenities = array_values(array_filter($amenities, fn($x) => $x !== ''));
        }

        $policies = is_array($h['policies'] ?? null) ? $h['policies'] : [];

        $data = [
            'id'          => (string) ($h['master_hotel_id'] ?? $hotelId),
            'hotel_id'    => 'adivaha_' . $hotelId,
            'name'        => (string) ($h['name'] ?? 'Hotel'),
            'description' => (string) ($h['description'] ?? ''),
            'address'     => (string) ($h['address'] ?? ''),
            'city'        => (string) ($h['city'] ?? ''),
            'country'     => (string) ($h['country_iso2'] ?? ''),
            'location'    => trim((string) (($h['city'] ?? '') . (!empty($h['country_iso2']) ? ', ' . $h['country_iso2'] : ''))),
            'stars'       => (int) ($h['stars'] ?? 0),
            'rating'      => (float) ($input['rating'] ?? 0),
            'latitude'    => $h['lat'] ?? null,
            'longitude'   => $h['lng'] ?? null,
            'images'      => $images,
            'image'       => $images[0] ?? '',
            'amenities'   => $amenities,
            'rooms'       => [], // fetched separately via /stays/adivaha/rooms
            'supplier'    => 'adivaha',
            'checkin'     => (string) ($input['checkin'] ?? ''),
            'checkout'    => (string) ($input['checkout'] ?? ''),
            'phone'       => (string) ($h['phone'] ?? ''),
            'email'       => (string) ($h['email'] ?? ''),
            'website'     => (string) ($h['website'] ?? ''),
            'check_in_time'  => (string) ($policies['check_in_time'] ?? $h['checkin_time'] ?? ''),
            'check_out_time' => (string) ($policies['check_out_time'] ?? $h['checkout_time'] ?? ''),
            'property_type'  => (string) ($h['property_type'] ?? 'Hotel'),
            'policies'       => $policies,
        ];

        echo json_encode(['success' => true, 'data' => $data]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL DETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred loading hotel details.']);
        exit;
    }
});
