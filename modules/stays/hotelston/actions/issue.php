<?php
// ============================================================================
// HOTELSTON BOOKING — HotelServiceV2 / bookHotel
// ENDPOINT: POST /stays/hotelston/issue
// ============================================================================

$router->post('stays/hotelston/issue', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'hotelston']);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        $existingPnr = trim((string)($booking['pnr'] ?? ''));
        if ($existingPnr !== '') {
            ob_clean();
            echo json_encode([
                'status'            => true,
                'Prn'               => $existingPnr,
                'booking_reference' => $existingPnr,
                'reference'         => $existingPnr,
                'success'           => true,
                'message'           => 'Hotelston booking already confirmed',
                'response_error'    => '',
                'invoice_id'        => $invoiceId,
                'data'              => [
                    'invoice_id'         => $invoiceId,
                    'supplier_reference' => $existingPnr,
                    'pnr'                => $existingPnr,
                ],
            ]);
            exit;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking data');
        }

        $module = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Hotelston module is not configured');
        }

        $isDev = in_array((string)($module['dev_mode'] ?? '0'), ['1', 'true', 'yes', 'on', 'test'], true);
        $env   = $isDev ? 'test' : 'production';
        $hotelEndpoint = $isDev
            ? 'http://dev.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/'
            : 'http://www.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/';

        $email    = $module['c1'];
        $password = $module['c2'];
        $profile  = trim((string)($module['c3'] ?? '0'));
        if ($profile === '' || (ctype_digit($profile) && (int)$profile > 999)) {
            $profile = '0';
        }

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

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

        $hotelId     = (int)($bookingData['hotel_id'] ?? $booking['hotel_id'] ?? 0);
        $checkin     = $formatDate($bookingData['checkin'] ?? '');
        $checkout    = $formatDate($bookingData['checkout'] ?? '');
        $nationality = strtoupper(trim($bookingData['nationality'] ?? 'US'));
        $currencyRaw = strtoupper(trim($bookingData['currency'] ?? ($_SESSION['app_currency'] ?? 'EUR')));
        $currency    = in_array($currencyRaw, ['EUR', 'LTL'], true) ? $currencyRaw : 'EUR';
        $selectedRooms = $bookingData['selected_rooms'] ?? [];
        $roomsData = is_array($bookingData['rooms_data'] ?? null) ? $bookingData['rooms_data'] : [];

        if (!$hotelId || !$checkin || !$checkout || empty($selectedRooms)) {
            throw new Exception('Incomplete booking data for Hotelston');
        }

        // Contact person + guest list
        $travellers = json_decode($booking['travellers'] ?? '{}', true);
        if (!is_array($travellers)) {
            $travellers = [];
        }
        $storedUserData = json_decode($booking['user_data'] ?? '{}', true);
        if (!is_array($storedUserData)) {
            $storedUserData = [];
        }

        $primaryGuest = $travellers['primary_guest'] ?? $storedUserData['primary_guest'] ?? [];
        $guestList    = $travellers['travelers'] ?? $bookingData['guests'] ?? $bookingData['guest'] ?? [];

        $contactFirst = trim($primaryGuest['first_name'] ?? $booking['first_name'] ?? '');
        $contactLast  = trim($primaryGuest['last_name'] ?? $booking['last_name'] ?? '');
        $contactEmail = trim($primaryGuest['email'] ?? $booking['email'] ?? '');
        $contactPhone = trim($primaryGuest['phone'] ?? $booking['phone'] ?? '');
        if ($contactFirst === '' || $contactLast === '' || $contactEmail === '' || $contactPhone === '') {
            throw new Exception('Contact person details are required');
        }

        $normalizeTitle = static function ($title): string {
            $title = strtoupper(trim((string)$title));
            return in_array($title, ['MR', 'MRS', 'MS', 'MISS'], true) ? $title : 'MR';
        };
        $contactTitle = $normalizeTitle($primaryGuest['title'] ?? $primaryGuest['gender'] ?? 'MR');

        // Inline SOAP helper for hotel service
        $soapCall = static function ($operation, $body) use ($hotelEndpoint) {
            $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
                . 'xmlns:xsd="http://request.v2.hotelservice.ws.hotelston.com/xsd" '
                . 'xmlns:xsd1="http://types.v2.hotelservice.ws.hotelston.com/xsd">'
                . '<soap:Header/><soap:Body>' . $body . '</soap:Body></soap:Envelope>';
            $ch = curl_init($hotelEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $envelope,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_HTTPHEADER     => [
                    'SOAPAction: application/soap+xml; charset=utf-8',
                    'Content-Type: urn:' . $operation,
                ],
            ]);
            $response  = curl_exec($ch);
            $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($response === false || $curlError !== '') {
                throw new RuntimeException('Hotelston ' . $operation . ' HTTP error: ' . ($curlError ?: 'empty response'));
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException('Hotelston ' . $operation . ' HTTP ' . $httpCode);
            }
            return (string)$response;
        };

        // Extract rooms from checkAvailability / search response XML
        $extractRoomsFromXml = static function ($xml, $hotelId) {
            $rooms = [];
            if (!preg_match_all('/<(?:[\w]+:)?hotel\b([^>]*)>(.*?)<\/(?:[\w]+:)?hotel>/is', $xml, $hotels, PREG_SET_ORDER)) {
                return $rooms;
            }
            $targetInner = null;
            $firstInner = null;
            foreach ($hotels as $hm) {
                $hid = 0;
                if (preg_match('/(?:[\w]+:)?id="(\d+)"/i', $hm[1], $m)) {
                    $hid = (int)$m[1];
                }
                if ($firstInner === null) {
                    $firstInner = $hm[2];
                }
                if ($hid === $hotelId) {
                    $targetInner = $hm[2];
                    break;
                }
            }
            $inner = $targetInner ?? $firstInner;
            if ($inner === null) {
                return $rooms;
            }
            if (!preg_match_all('/<(?:[\w]+:)?room\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?room>)/is', $inner, $roomMatches, PREG_SET_ORDER)) {
                return $rooms;
            }
            foreach ($roomMatches as $roomMatch) {
                $roomAttrs = $roomMatch[1];
                $roomInner = $roomMatch[2] ?? '';
                $room = (object)[];
                foreach (['id', 'seqNo', 'price'] as $field) {
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
                // Cancellation + remarks
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
                    $room->cancellationPolicy = $policy;
                }
                $rooms[] = $room;
            }
            return $rooms;
        };

        // Build booking rooms — CheckAvailability first for each
        $bookRooms = [];
        $confirmedTerms = [];
        $guestIndex = 0;
        foreach ($selectedRooms as $index => $selected) {
            $option = $selected['option'] ?? $selected;
            $parts  = explode('|', (string)($option['rate_key'] ?? ''));
            $roomId      = $parts[1] ?? ($option['room_id'] ?? '');
            $roomTypeId  = $parts[2] ?? ($option['room_type_id'] ?? '');
            $boardTypeId = (int)($parts[3] ?? ($option['board_type_id'] ?? 0));
            $seqNo       = (int)($parts[4] ?? $index);

            if ($roomId === '' || $roomTypeId === '' || $boardTypeId <= 0) {
                throw new Exception('Selected room is missing Hotelston rate details. Please re-select the room and try again.');
            }

            $roomConfig = $roomsData[$index] ?? ['adults' => 2, 'children' => 0];
            $adultCount = max(1, (int)($roomConfig['adults'] ?? 2));
            $rawChildAges = is_array($roomConfig['childAges'] ?? null) ? $roomConfig['childAges'] : [];
            $childAges = [];
            foreach ($rawChildAges as $age) {
                $age = (int)$age;
                $childAges[] = ($age >= 0 && $age <= 17) ? $age : 7;
            }
            $childCount = min(3, max(count($childAges), (int)($roomConfig['children'] ?? 0)));
            while (count($childAges) < $childCount) {
                $childAges[] = 7;
            }
            $childAges = array_slice($childAges, 0, $childCount);

            // Build room criteria XML (child ages included)
            $buildCheckRoomXml = static function ($rid, $rtype, $btype, $seq, $adults, $ages) use ($xmlAttr) {
                $xml = '<xsd1:room xsd1:adults="' . (int)$adults . '" xsd1:children="' . count($ages) . '" xsd1:seqNo="' . (int)$seq . '">';
                foreach ($ages as $age) {
                    $xml .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                }
                return $xml
                    . '<xsd1:roomId>' . $xmlAttr($rid) . '</xsd1:roomId>'
                    . '<xsd1:boardTypeId>' . (int)$btype . '</xsd1:boardTypeId>'
                    . '<xsd1:roomTypeId>' . $xmlAttr($rtype) . '</xsd1:roomTypeId>'
                    . '</xsd1:room>';
            };

            $runCheckAvailability = static function ($rid, $rtype, $btype, $seq) use (
                $soapCall, $buildCheckRoomXml, $xmlAttr, $email, $password, $profile,
                $checkin, $checkout, $nationality, $hotelId, $adultCount, $childAges
            ) {
                $checkBody = '<xsd:CheckAvailabilityRequest>'
                    . '<xsd1:loginDetails'
                    . ' xsd1:email="' . $xmlAttr($email) . '"'
                    . ' xsd1:password="' . $xmlAttr($password) . '"'
                    . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
                    . '<xsd:criteria>'
                    . '<xsd1:checkIn>' . $xmlAttr($checkin) . '</xsd1:checkIn>'
                    . '<xsd1:checkOut>' . $xmlAttr($checkout) . '</xsd1:checkOut>'
                    . '<xsd1:clientNationality>' . $xmlAttr($nationality) . '</xsd1:clientNationality>'
                    . '<xsd1:hotelId>' . $hotelId . '</xsd1:hotelId>'
                    . $buildCheckRoomXml($rid, $rtype, $btype, $seq, $adultCount, $childAges)
                    . '</xsd:criteria></xsd:CheckAvailabilityRequest>';
                return $soapCall('checkAvailability', $checkBody);
            };

            $pickLiveRoom = static function ($rooms, $rtype, $btype) {
                foreach ($rooms as $r) {
                    $typeId  = (string)($r->roomType->id ?? '');
                    $boardId = (int)($r->boardType->id ?? 0);
                    if ($typeId === (string)$rtype && $boardId === (int)$btype && (float)($r->price ?? 0) > 0) {
                        return $r;
                    }
                }
                foreach ($rooms as $r) {
                    if ((float)($r->price ?? 0) > 0) {
                        return $r;
                    }
                }
                return null;
            };

            $errorFromXml = static function ($xml, $fallback = 'Hotelston request failed') {
                $msg = $fallback;
                $code = '';
                if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $xml, $m)) {
                    $msg = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }
                if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"/i', $xml, $m)) {
                    $code = $m[1];
                }
                return trim(($code !== '' ? "[$code] " : '') . $msg);
            };

            $isStaleRoomError = static function ($xml) use ($errorFromXml) {
                $msg = strtolower($errorFromXml($xml, ''));
                $code = '';
                if (preg_match('/\[(\d+)\]/', $errorFromXml($xml, ''), $m)) {
                    $code = $m[1];
                }
                if (in_array($code, ['1009', '1011', '510', '511', '1006', '1000', '501'], true)) {
                    return true;
                }
                return strpos($msg, 'room id is not valid') !== false
                    || strpos($msg, 'room type id is not valid') !== false
                    || strpos($msg, 'unknown room') !== false
                    || strpos($msg, 'unknown room type') !== false
                    || strpos($msg, 'no offers found') !== false
                    || strpos($msg, 'cachedproviderhotelroom') !== false
                    || strpos($msg, 'redisroomtype') !== false
                    || strpos($msg, 'getpid()') !== false;
            };

            $runSearchHotels = static function ($seq) use (
                $soapCall, $xmlAttr, $email, $password, $profile,
                $checkin, $checkout, $nationality, $hotelId, $adultCount, $childAges
            ) {
                $searchBody = '<xsd:SearchHotelsRequest>'
                    . '<xsd1:loginDetails'
                    . ' xsd1:email="' . $xmlAttr($email) . '"'
                    . ' xsd1:password="' . $xmlAttr($password) . '"'
                    . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
                    . '<xsd:criteria>'
                    . '<xsd1:checkIn>' . $xmlAttr($checkin) . '</xsd1:checkIn>'
                    . '<xsd1:checkOut>' . $xmlAttr($checkout) . '</xsd1:checkOut>'
                    . '<xsd1:clientNationality>' . $xmlAttr($nationality) . '</xsd1:clientNationality>'
                    . '<xsd1:hotelSelector><xsd1:hotelIds><xsd1:hotelId>' . $hotelId . '</xsd1:hotelId></xsd1:hotelIds></xsd1:hotelSelector>'
                    . '<xsd1:room xsd1:adults="' . $adultCount . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . (int)$seq . '"';
                if (empty($childAges)) {
                    $searchBody .= '/>';
                } else {
                    $searchBody .= '>';
                    foreach ($childAges as $age) {
                        $searchBody .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                    }
                    $searchBody .= '</xsd1:room>';
                }
                $searchBody .= '</xsd:criteria></xsd:SearchHotelsRequest>';
                return $soapCall('searchHotels', $searchBody);
            };

            $rankSearchRooms = static function ($rooms, $wantType, $wantBoard, $wantName) {
                $scored = [];
                $wantName = strtolower(trim((string)$wantName));
                foreach ($rooms as $room) {
                    if ((float)($room->price ?? 0) <= 0 || empty($room->id)) {
                        continue;
                    }
                    $typeId  = (string)($room->roomType->id ?? '');
                    $boardId = (int)($room->boardType->id ?? 0);
                    $name    = strtolower(trim((string)($room->roomType->hotelstonName ?? $room->roomType->name ?? '')));
                    $score   = 10;
                    if ($typeId === (string)$wantType && $boardId === (int)$wantBoard) {
                        $score = 100;
                    } elseif ($wantName !== '' && $name !== '' && $name === $wantName && $boardId === (int)$wantBoard) {
                        $score = 80;
                    } elseif ($boardId === (int)$wantBoard) {
                        $score = 50;
                    }
                    $scored[] = ['score' => $score, 'room' => $room];
                }
                usort($scored, static function ($a, $b) {
                    return $b['score'] <=> $a['score'];
                });
                return array_map(static function ($entry) {
                    return $entry['room'];
                }, $scored);
            };

            $wantRoomName = trim((string)($selected['room_name'] ?? $option['room_name'] ?? ''));

            // Always resolve from a live search — stored room IDs expire between search and payment.
            $searchXml = $runSearchHotels($seqNo);
            if (preg_match('/<(?:[\w]+:)?error\b/i', $searchXml)) {
                throw new Exception($errorFromXml($searchXml, 'Hotelston search failed'));
            }
            $searchRooms = $extractRoomsFromXml($searchXml, $hotelId);
            $candidates  = $rankSearchRooms($searchRooms, $roomTypeId, $boardTypeId, $wantRoomName);
            if (empty($candidates)) {
                throw new Exception('No live rooms returned for this hotel. Please search again.');
            }

            $liveRoom  = null;
            $lastError = '';
            $triedIds  = [];
            foreach (array_slice($candidates, 0, 12) as $candidate) {
                $candId    = (string)($candidate->id ?? '');
                $candType  = (string)($candidate->roomType->id ?? '');
                $candBoard = (int)($candidate->boardType->id ?? 0);
                if ($candId === '' || $candType === '' || $candBoard <= 0 || isset($triedIds[$candId])) {
                    continue;
                }
                $triedIds[$candId] = true;

                $checkXml = $runCheckAvailability($candId, $candType, $candBoard, $seqNo);
                if (preg_match('/<(?:[\w]+:)?error\b/i', $checkXml)) {
                    $lastError = $errorFromXml($checkXml);
                    if ($isStaleRoomError($checkXml)) {
                        continue;
                    }
                    throw new Exception($lastError);
                }

                $liveRooms = $extractRoomsFromXml($checkXml, $hotelId);
                $matched   = $pickLiveRoom($liveRooms, $candType, $candBoard);
                if (!$matched || (float)($matched->price ?? 0) <= 0) {
                    continue;
                }
                $liveRoom = $matched;
                break;
            }

            if (!$liveRoom) {
                throw new Exception(
                    'Selected room is no longer bookable on Hotelston. Please search again and choose another room.'
                    . ($lastError !== '' ? ' ' . $lastError : '')
                );
            }

            $roomId      = (string)($liveRoom->id ?? '');
            $roomTypeId  = (string)($liveRoom->roomType->id ?? $roomTypeId);
            $boardTypeId = (int)($liveRoom->boardType->id ?? $boardTypeId);
            $price       = (float)($liveRoom->price ?? 0);
            if ($roomId === '' || $price <= 0) {
                throw new Exception('Selected room price is no longer available');
            }

            $confirmedTerms[] = [
                'seqNo'              => $seqNo,
                'room_id'            => $roomId,
                'price'              => $price,
                'cancellationPolicy' => $liveRoom->cancellationPolicy ?? null,
                'bookingRemarks'     => $liveRoom->bookingRemarks ?? null,
                'keyInformation'     => $liveRoom->keyInformation ?? null,
            ];

            $adults = [];
            for ($a = 0; $a < $adultCount; $a++) {
                $guest = $guestList[$guestIndex] ?? $guestList[0] ?? $primaryGuest;
                $adults[] = [
                    'title'     => $normalizeTitle($guest['title'] ?? $guest['gender'] ?? $contactTitle),
                    'firstname' => $guest['first_name'] ?? $contactFirst,
                    'lastname'  => $guest['last_name'] ?? $contactLast,
                ];
                $guestIndex++;
            }

            $children = [];
            foreach ($childAges as $age) {
                $guest = $guestList[$guestIndex] ?? [];
                $children[] = [
                    'firstname' => $guest['first_name'] ?? 'Child',
                    'lastname'  => $guest['last_name'] ?? $contactLast,
                    'age'       => (int)$age,
                ];
                $guestIndex++;
            }

            $roomEntry = [
                'roomId'      => $roomId,
                'roomTypeId'  => (string)$roomTypeId,
                'boardTypeId' => $boardTypeId,
                'price'       => $price,
                'seqNo'       => $seqNo,
                'adult'       => $adults,
            ];
            if (!empty($children)) {
                $roomEntry['child'] = $children;
            }
            $bookRooms[] = $roomEntry;
        }

        // POST-PAYMENT PRICE RECONCILIATION (§8.1(2) fix): sum the LIVE Hotelston
        // room prices from checkAvailability and compare to what the customer paid
        // before bookHotel. Abort + flag if the total rose beyond tolerance instead
        // of silently booking at the higher price.
        if (function_exists('reconcilePostPaymentPrice')) {
            $hsLiveTotal = 0.0;
            foreach ($bookRooms as $r) { $hsLiveTotal += (float) ($r['price'] ?? 0); }
            if ($hsLiveTotal > 0) {
                $hsPriceCheck = reconcilePostPaymentPrice($db, $booking, $hsLiveTotal, (string) $currency);
                if (empty($hsPriceCheck['ok'])) {
                    while (ob_get_level()) { ob_end_clean(); }
                    echo json_encode([
                        'status'  => false,
                        'success' => false,
                        'message' => 'Booking held for review: ' . $hsPriceCheck['reason'],
                        'price_review' => $hsPriceCheck,
                    ], JSON_UNESCAPED_SLASHES);
                    return;
                }
            }
        }

        // Build bookHotel XML
        $buildBookXml = static function ($rooms) use ($xmlAttr, $email, $password, $profile, $currency, $hotelId, $checkin, $checkout, $invoiceId, $env, $nationality, $contactTitle, $contactFirst, $contactLast, $contactEmail, $contactPhone) {
            $xml = '<xsd:BookHotelRequest>'
                . '<xsd1:loginDetails'
                . ' xsd1:email="' . $xmlAttr($email) . '"'
                . ' xsd1:password="' . $xmlAttr($password) . '"'
                . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
                . '<xsd1:currency>' . $xmlAttr($currency) . '</xsd1:currency>'
                . '<xsd:hotelId>' . $hotelId . '</xsd:hotelId>'
                . '<xsd:checkIn>' . $xmlAttr($checkin) . '</xsd:checkIn>'
                . '<xsd:checkOut>' . $xmlAttr($checkout) . '</xsd:checkOut>'
                . '<xsd:agentReferenceNumber>' . $xmlAttr($invoiceId) . '</xsd:agentReferenceNumber>';
            if ($env === 'test') {
                $xml .= '<xsd:testBooking>true</xsd:testBooking>';
            }
            $xml .= '<xsd:clientNationality>' . $xmlAttr($nationality) . '</xsd:clientNationality>'
                . '<xsd:contactPerson'
                . ' xsd1:title="' . $xmlAttr($contactTitle) . '"'
                . ' xsd1:firstname="' . $xmlAttr($contactFirst) . '"'
                . ' xsd1:lastname="' . $xmlAttr($contactLast) . '"'
                . ' xsd:email="' . $xmlAttr($contactEmail) . '"'
                . ' xsd:phone="' . $xmlAttr($contactPhone) . '"/>';
            foreach ($rooms as $room) {
                $xml .= '<xsd:room xsd:seqNo="' . (int)($room['seqNo'] ?? 0) . '">'
                    . '<xsd:roomId>' . $xmlAttr($room['roomId'] ?? '') . '</xsd:roomId>'
                    . '<xsd:roomTypeId>' . $xmlAttr($room['roomTypeId'] ?? '') . '</xsd:roomTypeId>'
                    . '<xsd:boardTypeId>' . (int)($room['boardTypeId'] ?? 0) . '</xsd:boardTypeId>'
                    . '<xsd:price>' . (float)($room['price'] ?? 0) . '</xsd:price>';
                foreach ($room['adult'] ?? [] as $adult) {
                    $xml .= '<xsd:adult'
                        . ' xsd1:title="' . $xmlAttr($adult['title'] ?? 'MR') . '"'
                        . ' xsd1:firstname="' . $xmlAttr($adult['firstname'] ?? '') . '"'
                        . ' xsd1:lastname="' . $xmlAttr($adult['lastname'] ?? '') . '"/>';
                }
                foreach ($room['child'] ?? [] as $child) {
                    $xml .= '<xsd:child'
                        . ' xsd1:firstname="' . $xmlAttr($child['firstname'] ?? 'Child') . '"'
                        . ' xsd1:lastname="' . $xmlAttr($child['lastname'] ?? '') . '"'
                        . ' xsd1:age="' . (int)($child['age'] ?? 1) . '"/>';
                }
                $xml .= '</xsd:room>';
            }
            return $xml . '</xsd:BookHotelRequest>';
        };

        $isBookingRef = static function ($value) {
            $value = trim((string)$value);
            return $value !== '' && preg_match('/^[A-Z]{1,4}\d{5,}$/i', $value) === 1;
        };

        $refFromText = static function ($text) use ($isBookingRef) {
            if ($text === '') {
                return '';
            }
            if (preg_match('/already used for booking\s+([A-Z0-9]+)/i', $text, $matches)) {
                $ref = trim($matches[1]);
                if ($isBookingRef($ref)) {
                    return $ref;
                }
            }
            if (preg_match('/\b(TF\d{5,})\b/i', $text, $matches)) {
                return strtoupper(trim($matches[1]));
            }
            return '';
        };

        $refFromXml = static function ($xml) use ($isBookingRef, $refFromText) {
            if ($xml === '') {
                return '';
            }
            if (preg_match_all('/<(?:[\w]+:)?bookingReference[^>]*>([^<]+)<\/(?:[\w]+:)?bookingReference>/i', $xml, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $ref = trim($candidate);
                    if ($isBookingRef($ref)) {
                        return $ref;
                    }
                }
            }
            if (preg_match_all('/(?:[\w]+:)?bookingReference="([^"]+)"/i', $xml, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $ref = trim($candidate);
                    if ($isBookingRef($ref)) {
                        return $ref;
                    }
                }
            }
            return $refFromText($xml);
        };

        // Book
        $bookBody = $buildBookXml($bookRooms);
        $responseXml = $soapCall('bookHotel', $bookBody);

        $success = false;
        $supplierStatus = '';
        $trackingId = '';
        $errorCode = '';
        $errorMsg = '';
        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(true|1)\s*</i', $responseXml)) {
            $success = true;
        }
        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(false|0)\s*</i', $responseXml)) {
            $success = false;
        }
        if (preg_match('/<(?:[\w]+:)?status[^>]*>\s*([^<]+)\s*</i', $responseXml, $m)) {
            $supplierStatus = strtoupper(trim($m[1]));
        }
        if (preg_match('/<(?:[\w]+:)?trackingId[^>]*>\s*([^<]+)\s*</i', $responseXml, $m)) {
            $trackingId = trim($m[1]);
        }
        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseXml, $m)) {
            $errorCode = $m[1];
            $errorMsg  = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        }

        $pnr = $refFromXml($responseXml);
        if ($pnr === '' && $errorMsg !== '') {
            $pnr = $refFromText($errorMsg);
        }

        // Recover PNR via retries (error 527 embeds the reference)
        if ($pnr === '') {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($attempt > 0) {
                    usleep(750000);
                }
                try {
                    $retryXml = $soapCall('bookHotel', $bookBody);
                    $pnr = $refFromXml($retryXml);
                    if ($pnr !== '') {
                        break;
                    }
                    if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $retryXml, $em)) {
                        $pnr = $refFromText(html_entity_decode($em[1], ENT_QUOTES, 'UTF-8'));
                        if ($pnr !== '') {
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    $pnr = $refFromText($e->getMessage());
                    if ($pnr !== '') {
                        break;
                    }
                }
            }
        }

        if ($pnr === '') {
            if ($errorCode !== '' && $errorCode !== '527') {
                throw new Exception(trim(($errorCode ? "[$errorCode] " : '') . ($errorMsg ?: 'Booking failed')));
            }
            if (!$success && $errorCode === '') {
                throw new Exception('Booking failed');
            }
            error_log('Hotelston bookHotel missing PNR | invoice=' . $invoiceId . ' | trackingId=' . $trackingId);
            throw new Exception(
                'Hotelston did not return a booking reference'
                . ($supplierStatus !== '' ? " (status: {$supplierStatus})" : '')
                . ($trackingId !== '' ? " (trackingId: {$trackingId})" : '')
            );
        }

        $supplierConfirmed = (strtoupper(trim($supplierStatus)) === 'CONFIRMED');
        if (!$supplierConfirmed && $supplierStatus === '' && $success) {
            $supplierConfirmed = true;
            $supplierStatus    = 'CONFIRMED';
        }
        if ($supplierStatus === '') {
            $supplierStatus = $supplierConfirmed ? 'CONFIRMED' : 'UNKNOWN';
        }

        $db->update('bookings', [
            'booking_status'        => 'confirmed',
            'pnr'                   => $pnr,
            'booking_response'      => json_encode([
                'status'             => $supplierStatus,
                'supplier_confirmed' => $supplierConfirmed,
                'booking_details'    => ['bookingReference' => $pnr],
                'tracking_id'        => $trackingId ?: null,
                'availability_terms' => $confirmedTerms,
                'raw_success'        => $success,
            ]),
            'error_response'        => null,
            'booking_payment_issue' => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        ob_clean();
        echo json_encode([
            'status'            => true,
            'Prn'               => $pnr,
            'booking_reference' => $pnr,
            'reference'         => $pnr,
            'success'           => true,
            'message'           => 'Hotelston booking confirmed',
            'response_error'    => '',
            'invoice_id'        => $invoiceId,
            'data'              => [
                'invoice_id'         => $invoiceId,
                'supplier_reference' => $pnr,
                'pnr'                => $pnr,
                'status'             => $supplierStatus,
                'supplier_confirmed' => $supplierConfirmed,
            ],
        ]);
    } catch (Throwable $e) {
        if (isset($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode(['error' => $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')]),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'status'            => false,
            'Prn'               => '',
            'booking_reference' => '',
            'reference'         => '',
            'success'           => false,
            'message'           => $e->getMessage(),
            'response_error'    => $e->getMessage(),
        ]);
    }
});
