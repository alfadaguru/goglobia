<?php
// ============================================================================
// HOTELSTON HOTEL DETAILS — StaticDataServiceV2 / getHotelDetails
// ENDPOINT: POST /stays/hotelston/details
// ============================================================================

$router->post('stays/hotelston/details', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $hotelId = (int)($input['hotel_id'] ?? $input['id'] ?? 0);
        if (!$hotelId) {
            throw new Exception('hotel_id is required');
        }

        $module = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Hotelston module is not configured');
        }

        $isDev = in_array((string)($module['dev_mode'] ?? '0'), ['1', 'true', 'yes', 'on', 'test'], true);
        $endpoint = $isDev
            ? 'https://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/'
            : 'https://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/';

        $email    = $module['c1'];
        $password = $module['c2'];
        $profile  = trim((string)($module['c3'] ?? '0'));
        if ($profile === '' || (ctype_digit($profile) && (int)$profile > 999)) {
            $profile = '0';
        }

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $body = '<xsd:HotelDetailsRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($email) . '"'
            . ' xsd1:password="' . $xmlAttr($password) . '"'
            . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
            . '<xsd:hotelId>' . $hotelId . '</xsd:hotelId>'
            . '</xsd:HotelDetailsRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
            . 'xmlns:xsd="http://request.v2.staticdataservice.ws.hotelston.com/xsd" '
            . 'xmlns:xsd1="http://types.v2.staticdataservice.ws.hotelston.com/xsd">'
            . '<soap:Header/><soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'SOAPAction: application/soap+xml; charset=utf-8',
                'Content-Type: urn:getHotelDetails',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new Exception('Hotelston HTTP error: ' . ($curlError ?: 'empty response'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Hotelston HTTP {$httpCode}");
        }

        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(false|0)\s*</i', $responseBody)) {
            $message = 'Failed to load hotel details';
            if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseBody, $m)) {
                $message = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            throw new Exception($message);
        }

        if (!preg_match('/<(?:[\w]+:)?hotel\b([^>]*)>(.*?)<\/(?:[\w]+:)?hotel>/is', $responseBody, $hm)) {
            throw new Exception('Hotel not found');
        }

        $attrs = $hm[1];
        $inner = $hm[2];

        $readAttr = static function ($a, $name) {
            if (preg_match('/(?:[\w]+:)?' . preg_quote($name, '/') . '="([^"]*)"/i', $a, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            return '';
        };
        $readElement = static function ($xml, $name) {
            if (preg_match('/<(?:[\w]+:)?' . preg_quote($name, '/') . '\b[^>]*>\s*(.*?)\s*<\/(?:[\w]+:)?' . preg_quote($name, '/') . '>/is', $xml, $m)) {
                return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
            }
            return '';
        };

        $hid = $readAttr($attrs, 'id') ?: (string)$hotelId;
        $name = $readAttr($attrs, 'name');

        $images = [];
        if (preg_match_all('/<(?:[\w]+:)?image\b[^>]*\b(?:[\w]+:)?url="([^"]+)"/i', $inner, $im)) {
            foreach ($im[1] as $url) {
                $images[] = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            }
        }

        $features = [];
        if (preg_match_all('/<(?:[\w]+:)?feature\b([^>]*)\/>/i', $inner, $fm)) {
            foreach ($fm[1] as $featAttrs) {
                $features[] = [
                    'id'   => $readAttr($featAttrs, 'id') ?: null,
                    'name' => $readAttr($featAttrs, 'name'),
                ];
            }
        }

        $description = '';
        if (preg_match_all('/<(?:[\w]+:)?description\b([^>]*)>\s*(?:<!\[CDATA\[(.*?)\]\]>|(.*?))\s*<\/(?:[\w]+:)?description>/is', $inner, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $desc) {
                $lang = $readAttr($desc[1], 'lang');
                $content = $desc[2] !== '' ? $desc[2] : ($desc[3] ?? '');
                if (strtolower($lang) === 'en' || $description === '') {
                    $description = $content;
                    if (strtolower($lang) === 'en') {
                        break;
                    }
                }
            }
        }

        $addressAttrs = '';
        if (preg_match('/<(?:[\w]+:)?address\b([^>]*)>/i', $inner, $am)) {
            $addressAttrs = $am[1];
        }
        $street1     = $readAttr($addressAttrs, 'street1');
        $street2     = $readAttr($addressAttrs, 'street2');
        $cityName    = $readAttr($addressAttrs, 'city');
        $countryName = $readAttr($addressAttrs, 'country');
        $zip         = $readAttr($addressAttrs, 'zip');
        $addressLine = trim($street1 . ' ' . $street2);

        $latitude = null;
        $longitude = null;
        if (preg_match('/<(?:[\w]+:)?coordinates\b([^>]*)\/>/i', $inner, $cm)) {
            $lat = $readAttr($cm[1], 'latitude');
            $lng = $readAttr($cm[1], 'longitude');
            $latitude  = $lat !== '' ? $lat : null;
            $longitude = $lng !== '' ? $lng : null;
        }

        $starRating = $readElement($inner, 'starRating');
        $stars = (int)$starRating;
        $amenities = array_values(array_filter(array_map(static fn($f) => $f['name'] ?? '', $features)));

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'          => (string)$hid,
                'hotel_id'    => (string)$hid,
                'name'        => $name,
                'description' => $description,
                'images'      => $images,
                'image'       => $images[0] ?? '',
                'features'    => $features,
                'amenities'   => $amenities,
                'address'     => $addressLine,
                'location'    => trim($cityName . ($countryName ? ', ' . $countryName : ''), ', '),
                'city'        => $cityName,
                'country'     => $countryName,
                'zip'         => $zip,
                'latitude'    => $latitude,
                'longitude'   => $longitude,
                'stars'       => $stars,
                'rating'      => (float)($starRating !== '' ? $starRating : $stars),
                'star_rating' => $starRating !== '' ? $starRating : null,
                'check_in'    => $readElement($inner, 'checkIn'),
                'check_out'   => $readElement($inner, 'checkOut'),
                'phone'       => $readElement($inner, 'phone'),
                'email'       => $readElement($inner, 'email'),
                'website'     => $readElement($inner, 'website'),
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
