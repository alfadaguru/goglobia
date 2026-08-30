<?php
// ============================================================================
// HOTELSTON ROOMS — HotelServiceV2 / searchHotels (live room listing)
// ENDPOINT: POST /stays/hotelston/rooms
// ============================================================================

$router->post('stays/hotelston/rooms', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $formatDate = static function ($date) {
            $date = trim((string)$date);
            if ($date === '') {
                return $date;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $date;
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m)) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            $ts = strtotime($date);
            return $ts ? date('Y-m-d', $ts) : $date;
        };

        $hotelId     = (int)($input['hotel_id'] ?? 0);
        $checkin     = $formatDate($input['checkin'] ?? '');
        $checkout    = $formatDate($input['checkout'] ?? '');
        $nationality = strtoupper(trim($input['nationality'] ?? 'US'));
        $rooms       = max(1, (int)($input['rooms'] ?? 1));
        $adults      = max(1, (int)($input['adults'] ?? 2));
        $children    = (int)($input['children'] ?? 0);
        $currency    = $input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');

        $roomsData = $input['rooms_data'] ?? [];
        if (is_string($roomsData)) {
            $roomsData = json_decode($roomsData, true);
        }
        if (!is_array($roomsData)) {
            $roomsData = [];
        }
        if (empty($roomsData) && is_array($input['rooms'] ?? null) && isset($input['rooms'][0])) {
            $roomsData = $input['rooms'];
            $rooms = max(1, count($roomsData));
        }

        if (!$hotelId || !$checkin || !$checkout) {
            throw new Exception('hotel_id, checkin and checkout are required');
        }

        $checkinObj  = DateTime::createFromFormat('Y-m-d', $checkin);
        $checkoutObj = DateTime::createFromFormat('Y-m-d', $checkout);
        if (!$checkinObj || !$checkoutObj) {
            throw new Exception('Invalid date format');
        }
        $nights = max(1, (int)$checkinObj->diff($checkoutObj)->days);

        $module = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Hotelston module is not configured');
        }

        $isDev = in_array((string)($module['dev_mode'] ?? '0'), ['1', 'true', 'yes', 'on', 'test'], true);
        $hotelEndpoint = $isDev
            ? 'http://dev.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/'
            : 'http://www.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/';
        $staticEndpoint = $isDev
            ? 'http://dev.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/'
            : 'http://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap12Endpoint/';

        $email    = $module['c1'];
        $password = $module['c2'];
        $profile  = trim((string)($module['c3'] ?? '0'));
        if ($profile === '' || (ctype_digit($profile) && (int)$profile > 999)) {
            $profile = '0';
        }

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $hotelstonDb = $db;
        if (!empty($module['database'])) {
            try {
                $hotelstonDb = new Medoo\Medoo([
                    'type'      => 'mysql',
                    'host'      => $module['host'] ?? 'localhost',
                    'database'  => $module['database'],
                    'username'  => $module['username'] ?? 'root',
                    'password'  => $module['password'] ?? '',
                    'charset'   => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ]);
            } catch (Throwable $e) {
                $hotelstonDb = $db;
            }
        }

        // Resolve destination
        $destinationName = trim((string)($input['destination'] ?? $input['city'] ?? ''));
        $destinationId = 0;
        if ($hotelstonDb) {
            try {
                $row = $hotelstonDb->get('hotelston_hotels', ['city_id', 'city'], ['hotel_id' => $hotelId]);
                if ($row && !empty($row['city_id'])) {
                    $destinationId = (int)$row['city_id'];
                }
                if ($destinationName === '' && !empty($row['city'])) {
                    $destinationName = (string)$row['city'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        if (!$destinationId && $destinationName === '' && session_status() === PHP_SESSION_ACTIVE) {
            $destinationName = trim((string)($_SESSION['stay_detail']['destination'] ?? $_SESSION['hotel_destination'] ?? ''));
        }

        if (!$destinationId && $destinationName !== '') {
            // Try DB lookup
            $variants = [$destinationName];
            $formatted = ucwords(strtolower(str_replace(['-', '_'], ' ', $destinationName)));
            $variants[] = $formatted;
            foreach ($variants as $name) {
                try {
                    $row = $hotelstonDb->get('hotelston_hotels', ['city_id'], [
                        'city[~]' => $name, 'city_id[!]' => null, 'city_id[>]' => 0, 'ORDER' => ['id' => 'ASC'],
                    ]);
                    if (!empty($row['city_id'])) {
                        $destinationId = (int)$row['city_id'];
                        break;
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            // API fallback
            if (!$destinationId) {
                $destBody = '<xsd:DestinationListRequest>'
                    . '<xsd1:loginDetails'
                    . ' xsd1:email="' . $xmlAttr($email) . '"'
                    . ' xsd1:password="' . $xmlAttr($password) . '"'
                    . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
                    . '</xsd:DestinationListRequest>';
                $destEnvelope = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
                    . 'xmlns:xsd="http://request.v2.staticdataservice.ws.hotelston.com/xsd" '
                    . 'xmlns:xsd1="http://types.v2.staticdataservice.ws.hotelston.com/xsd">'
                    . '<soap:Header/><soap:Body>' . $destBody . '</soap:Body></soap:Envelope>';
                $ch = curl_init($staticEndpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $destEnvelope,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 120,
                    CURLOPT_HTTPHEADER => [
                        'SOAPAction: application/soap+xml; charset=utf-8',
                        'Content-Type: urn:getDestinationList',
                    ],
                ]);
                $destXml = curl_exec($ch);
                curl_close($ch);
                if ($destXml) {
                    $needle = strtolower(trim(preg_replace('/\s+/', ' ', $destinationName)));
                    $needle = str_replace([' & ', ' and '], ' ', $needle);
                    if (preg_match_all('/<(?:[\w]+:)?city\b([^>]*)>/i', $destXml, $cityMatches)) {
                        foreach ($cityMatches[1] as $attrs) {
                            $cityId = 0;
                            $cityName = '';
                            if (preg_match('/(?:[\w]+:)?id="(\d+)"/i', $attrs, $m)) {
                                $cityId = (int)$m[1];
                            }
                            if (preg_match('/(?:[\w]+:)?name="([^"]*)"/i', $attrs, $m)) {
                                $cityName = strtolower(trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
                                $cityName = str_replace([' & ', ' and '], ' ', preg_replace('/\s+/', ' ', $cityName));
                            }
                            if ($cityId > 0 && ($cityName === $needle || str_contains($cityName, $needle) || str_contains($needle, $cityName))) {
                                $destinationId = $cityId;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (!$destinationId) {
            throw new Exception('Unable to resolve destination for this hotel');
        }

        // Build room criteria
        $roomCriteriaXml = '';
        if (!empty($roomsData)) {
            foreach ($roomsData as $index => $room) {
                $rAdults   = max(1, (int)($room['adults'] ?? 2));
                $rChildren = max(0, (int)($room['children'] ?? 0));
                $childAges = [];
                if (!empty($room['childAges']) && is_array($room['childAges'])) {
                    foreach ($room['childAges'] as $age) {
                        $age = (int)$age;
                        $childAges[] = ($age >= 0 && $age <= 17) ? $age : 7;
                    }
                    $rChildren = count($childAges);
                }
                $rChildren = min(3, $rChildren);
                while (count($childAges) < $rChildren) {
                    $childAges[] = 7;
                }
                $childAges = array_slice($childAges, 0, $rChildren);

                $roomCriteriaXml .= '<xsd1:room xsd1:adults="' . $rAdults . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . $index . '"';
                if (empty($childAges)) {
                    $roomCriteriaXml .= '/>';
                } else {
                    $roomCriteriaXml .= '>';
                    foreach ($childAges as $age) {
                        $roomCriteriaXml .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                    }
                    $roomCriteriaXml .= '</xsd1:room>';
                }
            }
        } else {
            $adultsPerRoom = max(1, (int)ceil($adults / $rooms));
            for ($i = 0; $i < $rooms; $i++) {
                $rChildren = ($i === 0 ? min(3, $children) : 0);
                $childAges = [];
                while (count($childAges) < $rChildren) {
                    $childAges[] = 7;
                }
                $roomCriteriaXml .= '<xsd1:room xsd1:adults="' . $adultsPerRoom . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . $i . '"';
                if (empty($childAges)) {
                    $roomCriteriaXml .= '/>';
                } else {
                    $roomCriteriaXml .= '>';
                    foreach ($childAges as $age) {
                        $roomCriteriaXml .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                    }
                    $roomCriteriaXml .= '</xsd1:room>';
                }
            }
        }

        $searchBody = '<xsd:SearchHotelsRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($email) . '"'
            . ' xsd1:password="' . $xmlAttr($password) . '"'
            . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
            . '<xsd:criteria>'
            . '<xsd1:checkIn>' . $xmlAttr($checkin) . '</xsd1:checkIn>'
            . '<xsd1:checkOut>' . $xmlAttr($checkout) . '</xsd1:checkOut>'
            . '<xsd1:clientNationality>' . $xmlAttr($nationality) . '</xsd1:clientNationality>'
            . '<xsd1:hotelSelector>'
            . '<xsd1:destinationId>' . (int)$destinationId . '</xsd1:destinationId>'
            . '</xsd1:hotelSelector>'
            . $roomCriteriaXml
            . '</xsd:criteria></xsd:SearchHotelsRequest>';

        $searchEnvelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
            . 'xmlns:xsd="http://request.v2.hotelservice.ws.hotelston.com/xsd" '
            . 'xmlns:xsd1="http://types.v2.hotelservice.ws.hotelston.com/xsd">'
            . '<soap:Header/><soap:Body>' . $searchBody . '</soap:Body></soap:Envelope>';

        $ch = curl_init($hotelEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $searchEnvelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'SOAPAction: application/soap+xml; charset=utf-8',
                'Content-Type: urn:searchHotels',
            ],
        ]);
        $apiXml    = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($apiXml === false || $curlError !== '') {
            throw new Exception('Hotelston HTTP error: ' . ($curlError ?: 'empty response'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Hotelston HTTP {$httpCode}");
        }

        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $apiXml, $m)) {
            throw new Exception(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        $trackingId = null;
        if (preg_match('/<(?:[\w]+:)?trackingId[^>]*>\s*([^<]+)\s*</i', $apiXml, $m)) {
            $trackingId = trim($m[1]);
        }

        // Parse hotels and find matching one
        $hotel = null;
        $firstHotel = null;
        if (preg_match_all('/<(?:[\w]+:)?hotel\b([^>]*)>(.*?)<\/(?:[\w]+:)?hotel>/is', $apiXml, $hotels, PREG_SET_ORDER)) {
            foreach ($hotels as $hotelMatch) {
                $attrs = $hotelMatch[1];
                $inner = $hotelMatch[2];
                $parsed = (object)['channel' => [], 'id' => 0, 'name' => ''];
                if (preg_match('/(?:[\w]+:)?id="(\d+)"/i', $attrs, $m)) {
                    $parsed->id = (int)$m[1];
                }
                if (preg_match('/(?:[\w]+:)?name="([^"]*)"/i', $attrs, $m)) {
                    $parsed->name = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }

                $channels = [];
                if (preg_match_all('/<(?:[\w]+:)?channel\b([^>]*)>(.*?)<\/(?:[\w]+:)?channel>/is', $inner, $channelMatches, PREG_SET_ORDER)) {
                    foreach ($channelMatches as $channelMatch) {
                        $channel = (object)['room' => []];
                        if (preg_match_all('/<(?:[\w]+:)?room\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?room>)/is', $channelMatch[2], $roomMatches, PREG_SET_ORDER)) {
                            foreach ($roomMatches as $roomMatch) {
                                $roomAttrs = $roomMatch[1];
                                $roomInner = $roomMatch[2] ?? '';
                                $room = (object)[];
                                foreach (['id', 'seqNo', 'price', 'specialOffer'] as $field) {
                                    if (!preg_match('/(?:[\w]+:)?' . preg_quote($field, '/') . '="([^"]*)"/i', $roomAttrs, $m)) {
                                        continue;
                                    }
                                    if ($field === 'price') {
                                        $room->price = (float)$m[1];
                                    } elseif ($field === 'seqNo') {
                                        $room->seqNo = (int)$m[1];
                                    } else {
                                        $room->$field = $m[1];
                                    }
                                }
                                if (preg_match('/<(?:[\w]+:)?boardType\b([^>]*)\/>/i', $roomInner, $m)) {
                                    $room->boardType = (object)[];
                                    foreach (['id', 'name'] as $field) {
                                        if (preg_match('/(?:[\w]+:)?' . preg_quote($field, '/') . '="([^"]*)"/i', $m[1], $fm)) {
                                            $room->boardType->$field = html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8');
                                        }
                                    }
                                }
                                if (preg_match('/<(?:[\w]+:)?roomType\b([^>]*)\/>/i', $roomInner, $m)) {
                                    $room->roomType = (object)[];
                                    foreach (['id', 'name', 'hotelstonName'] as $field) {
                                        if (preg_match('/(?:[\w]+:)?' . preg_quote($field, '/') . '="([^"]*)"/i', $m[1], $fm)) {
                                            $room->roomType->$field = html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8');
                                        }
                                    }
                                }

                                $policy = null;
                                $policyXml = $roomInner;
                                if (preg_match('/<(?:[\w]+:)?bookingTerms\b[^>]*>(.*?)<\/(?:[\w]+:)?bookingTerms>/is', $roomInner, $terms)) {
                                    $policyXml = $terms[1];
                                    if (preg_match('/<(?:[\w]+:)?bookingRemarks\b[^>]*>(.*?)<\/(?:[\w]+:)?bookingRemarks>/is', $terms[1], $rm)) {
                                        $raw = trim($rm[1]);
                                        if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', $raw, $cd)) {
                                            $raw = $cd[1];
                                        }
                                        $room->bookingRemarks = html_entity_decode(trim($raw), ENT_QUOTES, 'UTF-8');
                                    }
                                    if (preg_match('/<(?:[\w]+:)?keyInformation\b[^>]*>(.*?)<\/(?:[\w]+:)?keyInformation>/is', $terms[1], $rm)) {
                                        $raw = trim($rm[1]);
                                        if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', $raw, $cd)) {
                                            $raw = $cd[1];
                                        }
                                        $room->keyInformation = html_entity_decode(trim($raw), ENT_QUOTES, 'UTF-8');
                                    }
                                }
                                if (preg_match('/<(?:[\w]+:)?cancellationPolicy\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?cancellationPolicy>)/is', $policyXml, $pm)) {
                                    $policy = (object)['type' => null, 'cxlDate' => null, 'rules' => []];
                                    if (preg_match('/(?:[\w]+:)?type="([^"]*)"/i', $pm[1], $m)) {
                                        $policy->type = $m[1];
                                    }
                                    if (preg_match('/(?:[\w]+:)?cxlDate="([^"]*)"/i', $pm[1], $m)) {
                                        $policy->cxlDate = $m[1];
                                    }
                                    if (preg_match_all('/<(?:[\w]+:)?cancellationRule\b([^>]*)\/?>/i', $pm[2] ?? '', $rules, PREG_SET_ORDER)) {
                                        foreach ($rules as $rule) {
                                            $entry = (object)['deadline' => null, 'penaltyPercent' => null];
                                            if (preg_match('/(?:[\w]+:)?deadline="([^"]*)"/i', $rule[1], $m)) {
                                                $entry->deadline = $m[1];
                                            }
                                            if (preg_match('/(?:[\w]+:)?penaltyPercent="([^"]*)"/i', $rule[1], $m)) {
                                                $entry->penaltyPercent = (float)$m[1];
                                            }
                                            $policy->rules[] = $entry;
                                        }
                                    }
                                }
                                $room->cancellationPolicy = $policy;
                                $channel->room[] = $room;
                            }
                        }
                        $channels[] = $channel;
                    }
                }
                $parsed->channel = $channels;
                if ($firstHotel === null) {
                    $firstHotel = $parsed;
                }
                if ($parsed->id === $hotelId) {
                    $hotel = $parsed;
                    break;
                }
            }
        }

        if (!$hotel) {
            $hotel = $firstHotel;
        }
        if (!$hotel) {
            throw new Exception('No availability returned for this hotel');
        }

        // Build room options
        $hotelRooms = [];
        foreach ((is_array($hotel->channel ?? null) ? $hotel->channel : []) as $channel) {
            foreach ((is_array($channel->room ?? null) ? $channel->room : (isset($channel->room) ? [$channel->room] : [])) as $room) {
                $hotelRooms[] = $room;
            }
        }

        $grouped = [];
        foreach ($hotelRooms as $room) {
            $roomTypeId = (string)($room->roomType->id ?? '');
            $roomName   = $room->roomType->hotelstonName ?? $room->roomType->name ?? 'Room';
            $boardId    = (string)($room->boardType->id ?? '');
            $boardName  = $room->boardType->name ?? 'Room Only';
            $netPrice   = (float)($room->price ?? 0);
            if ($netPrice <= 0) {
                continue;
            }
            $optPricePerNight = round($netPrice / max(1, $nights), 2);
            $optTotalPrice    = round($netPrice, 2);
            if (function_exists('MARKUP')) {
                $marked = MARKUP($optPricePerNight, $module, $db, $currency, $currency);
                $optPricePerNight = $marked['price'] ?? $optPricePerNight;
                $optTotalPrice    = round($optPricePerNight * $nights * $rooms, 2);
            }

            $groupKey = $roomTypeId ?: md5($roomName);
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'room_id' => $groupKey, 'room_type_id' => $roomTypeId, 'room_name' => $roomName,
                    'room_images' => [], 'room_main_image' => '', 'amenities' => [], 'options' => [],
                    'min_price_per_night' => $optPricePerNight, 'max_adults' => 2, 'max_children' => 0,
                ];
            }

            $policy = $room->cancellationPolicy ?? null;
            $refundable = false;
            if (is_object($policy)) {
                $type = strtoupper((string)($policy->type ?? ''));
                if ($type !== 'FULL' && $type !== 'RQ') {
                    $now = time();
                    if (!empty($policy->cxlDate) && strtotime((string)$policy->cxlDate) > $now) {
                        $refundable = true;
                    } else {
                        foreach ((is_array($policy->rules ?? null) ? $policy->rules : []) as $rule) {
                            $penalty  = $rule->penaltyPercent ?? null;
                            $deadline = trim((string)($rule->deadline ?? ''));
                            if ($penalty !== null && (float)$penalty <= 0 && $deadline !== '' && strtotime($deadline) > $now) {
                                $refundable = true;
                                break;
                            }
                        }
                    }
                }
            }

            $cancellationText = '';
            if (is_object($policy)) {
                $type  = strtoupper((string)($policy->type ?? ''));
                $rules = is_array($policy->rules ?? null) ? $policy->rules : [];
                if ($type === 'FULL' && empty($rules)) {
                    $cancellationText = 'Non-refundable. Cancellation incurs a 100% penalty.';
                } elseif ($type === 'RQ') {
                    $cancellationText = 'Cancellation on request. Conditions confirmed by the supplier.';
                } else {
                    $parts = [];
                    foreach ($rules as $rule) {
                        $deadline = trim((string)($rule->deadline ?? ''));
                        $penalty  = $rule->penaltyPercent ?? null;
                        if ($deadline === '' && $penalty === null) {
                            continue;
                        }
                        $when = $deadline !== '' ? date('d M Y H:i', strtotime($deadline)) : '';
                        if ($penalty !== null && (float)$penalty <= 0) {
                            $parts[] = $when !== '' ? "Free cancellation until {$when}" : 'Free cancellation';
                        } else {
                            $pct = $penalty !== null ? rtrim(rtrim(number_format((float)$penalty, 2), '0'), '.') . '%' : '';
                            $parts[] = $when !== '' ? "From {$when}: {$pct} penalty" : "{$pct} penalty";
                        }
                    }
                    if (!empty($policy->cxlDate) && empty($parts)) {
                        $parts[] = 'Free cancellation until ' . date('d M Y H:i', strtotime((string)$policy->cxlDate));
                    }
                    $cancellationText = $parts ? implode('. ', $parts) . '.' : '';
                }
            }

            $messages = [];
            foreach (['Booking Remarks' => $room->bookingRemarks ?? '', 'Key Information' => $room->keyInformation ?? ''] as $label => $raw) {
                $raw = trim((string)$raw);
                if ($raw === '') {
                    continue;
                }
                $html = $raw;
                if (strip_tags($raw) === $raw) {
                    $html = nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
                }
                $messages[] = [
                    'type' => $label,
                    'html' => $html,
                    'text' => trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')),
                ];
            }

            $option = [
                'option_index' => count($grouped[$groupKey]['options']),
                'price_per_night' => $optPricePerNight, 'total_price' => $optTotalPrice, 'base_price' => $netPrice,
                'supplier_net_price' => $netPrice,
                'currency' => $currency, 'board_id' => $boardId, 'board_name' => $boardName,
                'room_id' => (string)($room->id ?? ''), 'room_type_id' => $roomTypeId, 'board_type_id' => $boardId,
                'refundable' => $refundable ? 1 : 0, 'cancellation_free' => $refundable ? 1 : 0,
                'breakfast_included' => stripos($boardName, 'breakfast') !== false ? 1 : 0,
                'cancellation_policy' => $policy,
                'cancellation_policies' => $policy->rules ?? [],
                'cancellation_text' => $cancellationText,
                'booking_remarks' => $room->bookingRemarks ?? '',
                'key_information' => $room->keyInformation ?? '',
                'rate_key' => implode('|', [$hotel->id ?? '', $room->id ?? '', $roomTypeId, $boardId, $room->seqNo ?? 0]),
            ];
            if (!empty($messages)) {
                $option['additional_notes'] = ['all_messages' => $messages];
            }
            $grouped[$groupKey]['options'][] = $option;
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'hotel_id'    => (string)$hotelId,
                'nights'      => $nights,
                'currency'    => $currency,
                'tracking_id' => $trackingId,
                'rooms'       => array_values($grouped),
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
