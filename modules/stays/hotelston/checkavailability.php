<?php
// ============================================================================
// HOTELSTON CHECK AVAILABILITY — HotelServiceV2 / checkAvailability
// ENDPOINT: POST /stays/hotelston/checkavailability
// ----------------------------------------------------------------------------
// Runs CheckAvailability for each selected room so cancellation policy and
// booking remarks are available before the guest opens the booking page.
// ============================================================================

$router->post('stays/hotelston/checkavailability', function () use ($db) {
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
        $selectedRooms = $input['selected_rooms'] ?? [];
        $roomsData   = $input['rooms_data'] ?? [];

        if (is_string($roomsData)) {
            $roomsData = json_decode($roomsData, true);
        }
        if (!is_array($roomsData)) {
            $roomsData = [];
        }
        if (!is_array($selectedRooms) || empty($selectedRooms)) {
            throw new Exception('selected_rooms is required');
        }
        if (!$hotelId || !$checkin || !$checkout) {
            throw new Exception('hotel_id, checkin and checkout are required');
        }

        $module = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Hotelston module is not configured');
        }

        $isDev = in_array((string)($module['dev_mode'] ?? '0'), ['1', 'true', 'yes', 'on', 'test'], true);
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

        foreach ($selectedRooms as $i => &$selectedRoom) {
            $opt = &$selectedRoom['option'];
            if (!is_array($opt)) {
                unset($opt);
                continue;
            }

            $parts       = explode('|', (string)($opt['rate_key'] ?? ''));
            $roomId      = $parts[1] ?? ($opt['room_id'] ?? '');
            $roomTypeId  = $parts[2] ?? ($opt['room_type_id'] ?? '');
            $boardTypeId = (int)($parts[3] ?? ($opt['board_type_id'] ?? 0));
            $seqNo       = (int)($parts[4] ?? $i);

            if ($roomId === '' || $roomTypeId === '' || $boardTypeId <= 0) {
                unset($opt);
                continue;
            }

            $cfg       = $roomsData[$i] ?? ['adults' => 2, 'children' => 0];
            $adults    = max(1, (int)($cfg['adults'] ?? 2));
            $rawAges   = is_array($cfg['childAges'] ?? null) ? $cfg['childAges'] : [];
            $childAges = [];
            foreach ($rawAges as $age) {
                $age = (int)$age;
                $childAges[] = ($age >= 0 && $age <= 17) ? $age : 7;
            }
            $childCount = min(3, max(count($childAges), (int)($cfg['children'] ?? 0)));
            while (count($childAges) < $childCount) {
                $childAges[] = 7;
            }
            $childAges = array_slice($childAges, 0, $childCount);

            try {
                $checkRoomXml = '<xsd1:room xsd1:adults="' . $adults . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . $seqNo . '">';
                foreach ($childAges as $age) {
                    $checkRoomXml .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                }
                $checkRoomXml .= '<xsd1:roomId>' . $xmlAttr($roomId) . '</xsd1:roomId>'
                    . '<xsd1:boardTypeId>' . $boardTypeId . '</xsd1:boardTypeId>'
                    . '<xsd1:roomTypeId>' . $xmlAttr($roomTypeId) . '</xsd1:roomTypeId>'
                    . '</xsd1:room>';

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
                    . $checkRoomXml
                    . '</xsd:criteria></xsd:CheckAvailabilityRequest>';

                $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
                    . 'xmlns:xsd="http://request.v2.hotelservice.ws.hotelston.com/xsd" '
                    . 'xmlns:xsd1="http://types.v2.hotelservice.ws.hotelston.com/xsd">'
                    . '<soap:Header/><soap:Body>' . $checkBody . '</soap:Body></soap:Envelope>';

                $ch = curl_init($hotelEndpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $envelope,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_HTTPHEADER     => [
                        'SOAPAction: application/soap+xml; charset=utf-8',
                        'Content-Type: urn:checkAvailability',
                    ],
                ]);
                $checkXml  = curl_exec($ch);
                $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($checkXml === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
                    unset($opt);
                    continue;
                }

                $checkErrorMsg = '';
                $checkErrorCode = '';
                if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $checkXml, $em)) {
                    $checkErrorMsg = html_entity_decode($em[1], ENT_QUOTES, 'UTF-8');
                }
                if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"/i', $checkXml, $ec)) {
                    $checkErrorCode = $ec[1];
                }
                $checkHay = strtolower($checkErrorCode . ' ' . $checkErrorMsg);
                $isStaleRoom = in_array($checkErrorCode, ['1009', '1011', '510', '511', '1006', '1000', '501'], true)
                    || strpos($checkHay, 'room id is not valid') !== false
                    || strpos($checkHay, 'room type id is not valid') !== false
                    || strpos($checkHay, 'unknown room') !== false
                    || strpos($checkHay, 'unknown room type') !== false
                    || strpos($checkHay, 'no offers found') !== false
                    || strpos($checkHay, 'cachedproviderhotelroom') !== false
                    || strpos($checkHay, 'redisroomtype') !== false
                    || strpos($checkHay, 'getpid()') !== false;

                $wantRoomName = trim((string)($selectedRoom['room_name'] ?? ''));
                $roomInner = '';

                // Stale/invalid room — refresh via searchHotels and try ranked candidates.
                if ($isStaleRoom || preg_match('/<(?:[\w]+:)?error\b/i', $checkXml)) {
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
                        . '<xsd1:room xsd1:adults="' . $adults . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . $seqNo . '"';
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
                    $searchXml = curl_exec($ch);
                    curl_close($ch);

                    $freshId = '';
                    $freshType = (string)$roomTypeId;
                    $freshBoard = $boardTypeId;
                    $freshPrice = 0.0;
                    $roomInner = '';
                    $matchedCandidate = false;

                    if (is_string($searchXml) && preg_match_all('/<(?:[\w]+:)?room\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?room>)/is', $searchXml, $freshMatches, PREG_SET_ORDER)) {
                        $ranked = [];
                        foreach ($freshMatches as $fm) {
                            $fa = $fm[1];
                            $fi = $fm[2] ?? '';
                            $fid = '';
                            $ftype = '';
                            $fboard = 0;
                            $fprice = 0.0;
                            $fname = '';
                            if (preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $fa, $m)) {
                                $fid = $m[1];
                            }
                            if (preg_match('/(?:[\w]+:)?price="([^"]*)"/i', $fa, $m)) {
                                $fprice = (float)$m[1];
                            }
                            if (preg_match('/<(?:[\w]+:)?roomType\b([^>]*)\/>/i', $fi, $tm)) {
                                if (preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $tm[1], $m)) {
                                    $ftype = $m[1];
                                }
                                if (preg_match('/(?:[\w]+:)?name="([^"]*)"/i', $tm[1], $m)) {
                                    $fname = strtolower(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                                }
                            }
                            if (preg_match('/<(?:[\w]+:)?boardType\b([^>]*)\/>/i', $fi, $bm)
                                && preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $bm[1], $m)) {
                                $fboard = (int)$m[1];
                            }
                            if ($fid === '' || $ftype === '' || $fboard <= 0 || $fprice <= 0) {
                                continue;
                            }
                            $score = 10;
                            if ($ftype === (string)$roomTypeId && $fboard === $boardTypeId) {
                                $score = 100;
                            } elseif ($wantRoomName !== '' && $fname !== '' && $fname === strtolower($wantRoomName) && $fboard === $boardTypeId) {
                                $score = 80;
                            } elseif ($fboard === $boardTypeId) {
                                $score = 50;
                            }
                            $ranked[] = ['score' => $score, 'id' => $fid, 'type' => $ftype, 'board' => $fboard, 'inner' => $fi, 'attrs' => $fa];
                        }
                        usort($ranked, static function ($a, $b) {
                            return $b['score'] <=> $a['score'];
                        });

                        foreach (array_slice($ranked, 0, 12) as $candidate) {
                            $checkRoomXml = '<xsd1:room xsd1:adults="' . $adults . '" xsd1:children="' . count($childAges) . '" xsd1:seqNo="' . $seqNo . '">';
                            foreach ($childAges as $age) {
                                $checkRoomXml .= '<xsd1:childAge>' . (int)$age . '</xsd1:childAge>';
                            }
                            $checkRoomXml .= '<xsd1:roomId>' . $xmlAttr($candidate['id']) . '</xsd1:roomId>'
                                . '<xsd1:boardTypeId>' . $candidate['board'] . '</xsd1:boardTypeId>'
                                . '<xsd1:roomTypeId>' . $xmlAttr($candidate['type']) . '</xsd1:roomTypeId>'
                                . '</xsd1:room>';

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
                                . $checkRoomXml
                                . '</xsd:criteria></xsd:CheckAvailabilityRequest>';

                            $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
                                . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
                                . 'xmlns:xsd="http://request.v2.hotelservice.ws.hotelston.com/xsd" '
                                . 'xmlns:xsd1="http://types.v2.hotelservice.ws.hotelston.com/xsd">'
                                . '<soap:Header/><soap:Body>' . $checkBody . '</soap:Body></soap:Envelope>';

                            $ch = curl_init($hotelEndpoint);
                            curl_setopt_array($ch, [
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => $envelope,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => false,
                                CURLOPT_TIMEOUT        => 60,
                                CURLOPT_HTTPHEADER     => [
                                    'SOAPAction: application/soap+xml; charset=utf-8',
                                    'Content-Type: urn:checkAvailability',
                                ],
                            ]);
                            $candidateCheckXml = curl_exec($ch);
                            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $curlError = curl_error($ch);
                            curl_close($ch);

                            if ($candidateCheckXml === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
                                continue;
                            }
                            if (preg_match('/<(?:[\w]+:)?error\b/i', $candidateCheckXml)) {
                                continue;
                            }
                            if (!preg_match('/<(?:[\w]+:)?room\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?room>)/is', $candidateCheckXml, $okRoom)) {
                                continue;
                            }

                            $freshId = $candidate['id'];
                            $freshType = $candidate['type'];
                            $freshBoard = $candidate['board'];
                            $roomInner = $okRoom[2] ?? '';
                            if (preg_match('/(?:[\w]+:)?price="([^"]*)"/i', $okRoom[1], $rpm)) {
                                $freshPrice = (float)$rpm[1];
                            }
                            $matchedCandidate = true;
                            break;
                        }
                    }

                    if (!$matchedCandidate) {
                        throw new Exception(
                            'Selected room is not bookable on Hotelston right now. Please search again and choose another room.'
                        );
                    }

                    $roomId = $freshId;
                    $roomTypeId = $freshType;
                    $boardTypeId = $freshBoard;
                    $opt['room_id'] = $roomId;
                    $opt['room_type_id'] = $roomTypeId;
                    $opt['board_type_id'] = (string)$boardTypeId;
                    if ($freshPrice > 0) {
                        $opt['supplier_net_price'] = $freshPrice;
                        $opt['base_price'] = $freshPrice;
                    }
                    if (!empty($opt['rate_key'])) {
                        $rk = explode('|', (string)$opt['rate_key']);
                        $opt['rate_key'] = implode('|', [
                            $rk[0] ?? $hotelId,
                            $roomId,
                            $roomTypeId,
                            $boardTypeId,
                            $rk[4] ?? $seqNo,
                        ]);
                    }

                    if ($roomInner === '') {
                        throw new Exception(
                            'Selected room is not bookable on Hotelston (invalid room ID). Please search again and choose another room.'
                        );
                    }
                } elseif (preg_match('/<(?:[\w]+:)?error\b/i', $checkXml)) {
                    throw new Exception(
                        trim(($checkErrorCode !== '' ? "[$checkErrorCode] " : '') . ($checkErrorMsg ?: 'Hotelston availability check failed'))
                    );
                }

                if ($roomInner === '') {
                    if (preg_match_all('/<(?:[\w]+:)?room\b([^>]*)(?:\/>|>(.*?)<\/(?:[\w]+:)?room>)/is', $checkXml, $roomMatches, PREG_SET_ORDER)) {
                    foreach ($roomMatches as $rm) {
                        $ri = $rm[2] ?? '';
                        $typeId = '';
                        $boardId = 0;
                        if (preg_match('/<(?:[\w]+:)?roomType\b([^>]*)\/>/i', $ri, $tm)
                            && preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $tm[1], $tim)) {
                            $typeId = $tim[1];
                        }
                        if (preg_match('/<(?:[\w]+:)?boardType\b([^>]*)\/>/i', $ri, $bm)
                            && preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $bm[1], $bim)) {
                            $boardId = (int)$bim[1];
                        }
                        if ($typeId === (string)$roomTypeId && $boardId === $boardTypeId) {
                            $roomInner = $ri;
                            if (preg_match('/(?:[\w]+:)?id="([^"]*)"/i', $rm[1], $ridm)) {
                                $opt['room_id'] = $ridm[1];
                            }
                            if (preg_match('/(?:[\w]+:)?price="([^"]*)"/i', $rm[1], $rpm)) {
                                $livePrice = (float)$rpm[1];
                                if ($livePrice > 0) {
                                    $opt['supplier_net_price'] = $livePrice;
                                    $opt['base_price'] = $livePrice;
                                }
                            }
                            break;
                        }
                        if ($roomInner === '' && $ri !== '') {
                            $roomInner = $ri;
                        }
                    }
                    }
                }

                if ($roomInner === '') {
                    throw new Exception(
                        'Selected room is not bookable on Hotelston right now. Please search again and choose another room.'
                    );
                }

                $policy = null;
                $policyXml = $roomInner;
                $bookingRemarks = '';
                $keyInformation = '';

                if (preg_match('/<(?:[\w]+:)?bookingTerms\b[^>]*>(.*?)<\/(?:[\w]+:)?bookingTerms>/is', $roomInner, $terms)) {
                    $policyXml = $terms[1];
                    if (preg_match('/<(?:[\w]+:)?bookingRemarks\b[^>]*>(.*?)<\/(?:[\w]+:)?bookingRemarks>/is', $terms[1], $brm)) {
                        $raw = trim($brm[1]);
                        if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', $raw, $cd)) {
                            $raw = $cd[1];
                        }
                        $bookingRemarks = html_entity_decode(trim($raw), ENT_QUOTES, 'UTF-8');
                    }
                    if (preg_match('/<(?:[\w]+:)?keyInformation\b[^>]*>(.*?)<\/(?:[\w]+:)?keyInformation>/is', $terms[1], $kim)) {
                        $raw = trim($kim[1]);
                        if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', $raw, $cd)) {
                            $raw = $cd[1];
                        }
                        $keyInformation = html_entity_decode(trim($raw), ENT_QUOTES, 'UTF-8');
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
                foreach (['Booking Remarks' => $bookingRemarks, 'Key Information' => $keyInformation] as $label => $raw) {
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

                $opt['cancellation_policy']   = $policy;
                $opt['cancellation_policies'] = is_object($policy) ? ($policy->rules ?? []) : [];
                $opt['cancellation_text']     = $cancellationText ?: ($opt['cancellation_text'] ?? '');
                $opt['refundable']            = $refundable ? 1 : 0;
                $opt['cancellation_free']     = $opt['refundable'];
                $opt['booking_remarks']       = $bookingRemarks;
                $opt['key_information']       = $keyInformation;
                if (!empty($messages)) {
                    $opt['additional_notes'] = ['all_messages' => $messages];
                }
            } catch (Throwable $e) {
                throw $e;
            }

            unset($opt);
        }
        unset($selectedRoom);

        echo json_encode([
            'success' => true,
            'data'    => [
                'selected_rooms' => $selectedRooms,
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
