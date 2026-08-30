<?php
// ============================================================================
// FILE: app/routes/api/tours/bookingRoutes.php
// MOBILE TOUR BOOKING API (NO FIELD REMOVED)
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';


// ============================================================================
// GET: TOUR BOOKING DATA BY HASH
// GET /api/tours/booking/{hash}
// ============================================================================
$router->get('/api/tours/booking/([a-f0-9]{16})', function ($hash) use ($db) {

    header('Content-Type: application/json');

    try {
        // Resolve JWT token if passed
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headersLower = [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                $headersLower[$hKey] = $v;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $authHeader = $headersLower['authorization'] ?? '';
        $token = '';
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
        }
        if (empty($token)) {
            $token = $headersLower['token'] ?? $headersLower['jwt'] ?? $headersLower['x-access-token'] ?? '';
        }
        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                  ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                  ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // Fetch booking draft
        $booking = $db->get('logs_bookings', ['hash', 'data'], [
            'hash' => $hash
        ]);

        if (!$booking || empty($booking['data'])) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        // Decode booking data
        $bookingData = json_decode($booking['data'], true);

        if (!$bookingData) {
            throw new Exception('Invalid booking data');
        }

        // Reapply markup dynamically for response
        if (!empty($bookingData) && is_array($bookingData)) {
            $supplier = $bookingData['supplier'] ?? 'tours';
            $draftCurrency = $bookingData['currency'] ?? 'USD';
            $displayCurrency = strtoupper(trim((string)($_GET['currency'] ?? $draftCurrency)));

            $actualPricePerPerson = (float)($bookingData['actual_price_per_person'] ?? 0);
            $actualPricePerChild = (float)($bookingData['actual_price_per_child'] ?? 0);

            if ($actualPricePerPerson > 0 && function_exists('MARKUP')) {
                $toursModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'tours', 'status' => '1']);
                if (!$toursModule && $supplier !== 'tours') {
                    $toursModule = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => '1']);
                }
                $markedPerson = MARKUP($actualPricePerPerson, $toursModule ?: null, $db, $draftCurrency, $displayCurrency);
                if (!empty($markedPerson['price']) && $markedPerson['price'] > 0) {
                    $bookingData['markup_price_per_person'] = round((float)$markedPerson['price'], 2);
                    if (isset($markedPerson['converted_base_price'])) {
                        $bookingData['actual_price_per_person'] = round((float)$markedPerson['converted_base_price'], 2);
                    }
                }
            }
            if ($actualPricePerChild > 0 && function_exists('MARKUP')) {
                $toursModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'tours', 'status' => '1']);
                if (!$toursModule && $supplier !== 'tours') {
                    $toursModule = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => '1']);
                }
                $markedChild = MARKUP($actualPricePerChild, $toursModule ?: null, $db, $draftCurrency, $displayCurrency);
                if (!empty($markedChild['price']) && $markedChild['price'] > 0) {
                    $bookingData['markup_price_per_child'] = round((float)$markedChild['price'], 2);
                    if (isset($markedChild['converted_base_price'])) {
                        $bookingData['actual_price_per_child'] = round((float)$markedChild['converted_base_price'], 2);
                    }
                }
            }

            // Recalculate totals
            $adults = (int)($bookingData['total_adults'] ?? 1);
            $children = (int)($bookingData['total_children'] ?? 0);
            
            $bookingData['actual_total_price_persons'] = round(($bookingData['actual_price_per_person'] ?? 0) * $adults, 2);
            $bookingData['actual_total_price_childrens'] = round(($bookingData['actual_price_per_child'] ?? 0) * $children, 2);
            $bookingData['actual_total_tour_price'] = round($bookingData['actual_total_price_persons'] + $bookingData['actual_total_price_childrens'], 2);

            $bookingData['markup_total_price_persons'] = round(($bookingData['markup_price_per_person'] ?? 0) * $adults, 2);
            $bookingData['markup_total_price_childrens'] = round(($bookingData['markup_price_per_child'] ?? 0) * $children, 2);
            $bookingData['markup_total_tour_price'] = round($bookingData['markup_total_price_persons'] + $bookingData['markup_total_price_childrens'], 2);

            $bookingData['currency'] = $displayCurrency;
        }

        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'booking_data' => $bookingData
        ]);

    } catch (Exception $e) {

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

// ============================================================================
// POST: SAVE DRAFT
// POST /api/tours/booking/draft
// ============================================================================
$router->post('/api/tours/booking/draft', function () use ($db) {

    header('Content-Type: application/json');

    try {

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            throw new Exception('Invalid booking data');
        }

        // --------------------------------------------------
        // RESOLVE LOGGED-IN USER / AGENT FROM JWT TOKEN
        // --------------------------------------------------
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headersLower = [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                $headersLower[$hKey] = $v;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $authHeader = $headersLower['authorization'] ?? '';
        $token = '';
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
        }

        if (empty($token)) {
            $token = $headersLower['token'] ?? $headersLower['jwt'] ?? $headersLower['x-access-token'] ?? '';
        }
        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                  ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                  ?? $input['token'] ?? $input['access_token'] ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        $tourIdInput = $input['tour_id'] ?? ($input['id'] ?? '');
        $startDateInput = $input['start_date'] ?? ($input['departure_date'] ?? ($input['date'] ?? ''));
        if ($tourIdInput === '' || $startDateInput === '') {
            throw new Exception('Missing required fields: tour_id/id, start_date');
        }

        $adultsInput = $input['total_adults'] ?? ($input['adults'] ?? 1);
        $childrenInput = $input['total_children'] ?? ($input['children'] ?? 0);
        $adults = max(1, (int)$adultsInput);
        $children = max(0, (int)$childrenInput);
        $supplier = trim((string)($input['supplier'] ?? 'tours'));
        $startDateRaw = trim((string)$startDateInput);
        $displayCurrency = strtoupper(trim((string)($input['currency'] ?? 'USD')));

        if ($adults < 1 || $adults > 20) {
            throw new Exception('Adults must be between 1 and 20');
        }
        if ($children < 0 || $children > 20) {
            throw new Exception('Children must be between 0 and 20');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $supplier)) {
            throw new Exception('Invalid supplier');
        }

        $startDateObj = DateTime::createFromFormat('Y-m-d', $startDateRaw);
        if (!$startDateObj) {
            $startDateObj = DateTime::createFromFormat('d-m-Y', $startDateRaw);
        }
        if (!$startDateObj) {
            throw new Exception('Invalid start_date format. Use YYYY-MM-DD or DD-MM-YYYY');
        }
        $startDate = $startDateObj->format('Y-m-d');

        $validCurrency = $db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => 1]);
        if (!$validCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }

        // ============================================================
        // EXTERNAL SUPPLIERS
        // Works for viator, tiqets, and ANY future external supplier!
        // ============================================================
        
        if ($supplier !== 'tours') {

            $tourId = $tourIdInput;
            $tourName = trim((string)($input['tour_name'] ?? ($input['name'] ?? '')));
            $tourImage = trim((string)($input['tour_image'] ?? ($input['img'] ?? ($input['image'] ?? ''))));
            $tourLocation = trim((string)($input['tour_location'] ?? ($input['location'] ?? '')));

            $actualPricePerPerson = (float)($input['actual_price_per_person'] ?? ($input['actual_price'] ?? ($input['base_adult_price'] ?? 0)));
            $actualPricePerChild = (float)($input['actual_price_per_child'] ?? ($input['base_child_price'] ?? ($actualPricePerPerson * 0.8)));
            $displayPricePerPerson = (float)($input['display_price_per_person'] ?? ($input['display_price'] ?? 0));
            $displayPricePerChild = (float)($input['display_price_per_child'] ?? ($displayPricePerPerson * 0.8));

            if ($displayPricePerPerson <= 0 && $actualPricePerPerson <= 0) {
                throw new Exception('Price data is required for external supplier tours');
            }
            if ($tourName === '') {
                throw new Exception('Tour name is required');
            }

            $toursModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'tours', 'status' => '1']);
            if (!$toursModule && $supplier !== 'tours') {
                $toursModule = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => '1']);
            }

            if ($actualPricePerPerson > 0 && function_exists('MARKUP')) {
                $markedPerson = MARKUP($actualPricePerPerson, $toursModule ?: null, $db, $displayCurrency, $displayCurrency);
                if (!empty($markedPerson['price']) && $markedPerson['price'] > 0) {
                    $displayPricePerPerson = (float)$markedPerson['price'];
                }
            }
            if ($actualPricePerChild > 0 && function_exists('MARKUP')) {
                $markedChild = MARKUP($actualPricePerChild, $toursModule ?: null, $db, $displayCurrency, $displayCurrency);
                if (!empty($markedChild['price']) && $markedChild['price'] > 0) {
                    $displayPricePerChild = (float)$markedChild['price'];
                }
            }

            $tourImages = $input['tour_images'] ?? ($input['images'] ?? []);
            $duration = trim((string)($input['duration'] ?? ($input['duration_formatted'] ?? '')));

            $bookingDraftData = [
                'tour_id'                       => $tourId,
                'tour_name'                     => $tourName,
                'supplier'                      => $supplier,
                'start_date'                    => $startDate,
                'duration'                      => $duration,
                'total_adults'                  => $adults,
                'total_children'                => $children,
                'currency'                      => $displayCurrency,
                'tour_image'                    => $tourImage,
                'tour_images'                   => $tourImages,
                'tour_location'                 => $tourLocation,
                'actual_price_per_person'        => round($actualPricePerPerson, 2),
                'actual_total_price_persons'     => round($actualPricePerPerson * $adults, 2),
                'actual_price_per_child'         => round($actualPricePerChild, 2),
                'actual_total_price_childrens'   => round($actualPricePerChild * $children, 2),
                'actual_total_tour_price'        => round(($actualPricePerPerson * $adults) + ($actualPricePerChild * $children), 2),
                'markup_price_per_person'        => round($displayPricePerPerson, 2),
                'markup_total_price_persons'     => round($displayPricePerPerson * $adults, 2),
                'markup_price_per_child'         => round($displayPricePerChild, 2),
                'markup_total_price_childrens'   => round($displayPricePerChild * $children, 2),
                'markup_total_tour_price'        => round(($displayPricePerPerson * $adults) + ($displayPricePerChild * $children), 2),
            ];

        } else {
            // ============================================================
            // LOCAL TOURS (supplier = 'tours')
            // ============================================================
            $tourId = (int)$tourIdInput;

            if ($tourId <= 0) {
                throw new Exception('Invalid tour_id');
            }

            $tour = $db->get('tours', '*', ['id' => $tourId, 'status' => 1]);
            if (!$tour) {
                throw new Exception('Tour not found or unavailable');
            }

            $maxAdults = (int)($tour['max_adults'] ?? 0);
            $maxChildren = (int)($tour['max_children'] ?? 0);
            if ($maxAdults > 0 && $adults > $maxAdults) {
                throw new Exception('Adults exceed tour maximum limit');
            }
            if ($maxChildren > 0 && $children > $maxChildren) {
                throw new Exception('Children exceed tour maximum limit');
            }

            $toursModule = $db->get('modules', '*', [
                'name' => 'tours',
                'type' => 'tours',
                'status' => 1
            ]);
            if (!$toursModule) {
                $toursModule = $db->get('modules', '*', ['name' => 'tours', 'status' => 1]);
            }

            $tourCurrency = strtoupper((string)($tour['currency'] ?? 'USD'));

            $adultBasePerPerson = (float)($tour['adult_price'] ?? 0);
            $adultBaseSrc = $tourCurrency;

            $childBasePerPerson = (float)($tour['child_price'] ?? 0);
            $childBaseSrc = $tourCurrency;

            $adultDisplay = $adultBasePerPerson;
            if ($adultBasePerPerson > 0 && function_exists('MARKUP')) {
                $markedPerson = MARKUP($adultBasePerPerson, $toursModule ?: null, $db, $adultBaseSrc, $displayCurrency);
                if (!empty($markedPerson['price']) && $markedPerson['price'] > 0) {
                    $adultDisplay = (float)$markedPerson['price'];
                    if (isset($markedPerson['converted_base_price'])) {
                        $adultBasePerPerson = (float)$markedPerson['converted_base_price'];
                    }
                }
            }

            $childDisplay = $childBasePerPerson;
            if ($childBasePerPerson > 0 && function_exists('MARKUP')) {
                $markedChild = MARKUP($childBasePerPerson, $toursModule ?: null, $db, $childBaseSrc, $displayCurrency);
                if (!empty($markedChild['price']) && $markedChild['price'] > 0) {
                    $childDisplay = (float)$markedChild['price'];
                    if (isset($markedChild['converted_base_price'])) {
                        $childBasePerPerson = (float)$markedChild['converted_base_price'];
                    }
                }
            }

            $adultSubtotalBase = $adultBasePerPerson * $adults;
            $childSubtotalBase = $childBasePerPerson * $children;
            $totalBase = $adultSubtotalBase + $childSubtotalBase;

            $totalDisplay = ($adultDisplay * $adults) + ($childDisplay * $children);

            $duration = trim((string)($input['duration'] ?? ''));
            if ($duration === '') {
                $nights = (int)($tour['nights'] ?? 0);
                $days = (int)($tour['days'] ?? 0);
                $duration = $nights . ' Nights - ' . $days . ' Days';
            }

            $baseUrl = rtrim(root, '/');
            $tourImages = [];
            $tourImage = '';
            $gallery = json_decode((string)($tour['img'] ?? '[]'), true);
            if (is_array($gallery)) {
                foreach ($gallery as $img) {
                    $imgPath = '';
                    $isDefault = false;

                    if (is_array($img)) {
                        $imgPath = trim((string)($img['url'] ?? ''));
                        $isDefault = !empty($img['default']);
                    } elseif (is_string($img)) {
                        $imgPath = trim($img);
                    }

                    if ($imgPath === '') {
                        continue;
                    }

                    $fullUrl = (stripos($imgPath, 'http://') === 0 || stripos($imgPath, 'https://') === 0)
                        ? $imgPath
                        : $baseUrl . $imgPath;

                    $tourImages[] = ['url' => $fullUrl];

                    if ($tourImage === '' && $isDefault) {
                        $tourImage = $fullUrl;
                    }
                }
            }
            if ($tourImage === '' && !empty($tourImages[0]['url'])) {
                $tourImage = $tourImages[0]['url'];
            }

            $bookingDraftData = [
                'tour_id' => $tourId,
                'tour_name' => (string)($tour['name'] ?? ''),
                'supplier' => $supplier,
                'start_date' => $startDate,
                'duration' => $duration,
                'total_adults' => $adults,
                'total_children' => $children,
                'currency' => $displayCurrency,
                'tour_image' => $tourImage,
                'tour_images' => $tourImages,
                'tour_location' => (string)($tour['location'] ?? ''),
                'actual_price_per_person' => round($adultBasePerPerson, 2),
                'actual_total_price_persons' => round($adultSubtotalBase, 2),
                'actual_price_per_child' => round($childBasePerPerson, 2),
                'actual_total_price_childrens' => round($childSubtotalBase, 2),
                'actual_total_tour_price' => round($totalBase, 2),
                'markup_price_per_person' => round($adultDisplay, 2),
                'markup_total_price_persons' => round($adultDisplay * $adults, 2),
                'markup_price_per_child' => round($childDisplay, 2),
                'markup_total_price_childrens' => round($childDisplay * $children, 2),
                'markup_total_tour_price' => round($totalDisplay, 2)
            ];
        }

        $hash = bin2hex(random_bytes(8));
        if (strlen($hash) !== 16) {
            throw new Exception('Failed to generate valid booking hash');
        }

        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($bookingDraftData),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            throw new Exception('Failed to save booking data');
        }

        triggerWebhook('tours/booking', 'tours.booking.draft_created', [
            'booking_hash' => $hash,
            'tour_id' => $bookingDraftData['tour_id'] ?? null,
            'tour_name' => $bookingDraftData['tour_name'] ?? '',
            'currency' => $bookingDraftData['currency'] ?? 'USD',
            'total_amount' => $bookingDraftData['markup_total_tour_price'] ?? 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => null
        ]);

        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'booking_data' => $bookingDraftData,
            'message' => 'Booking draft saved successfully'
        ]);

    } catch (Exception $e) {

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

// ============================================================================
// POST: SUBMIT TOUR BOOKING
// POST /api/tours/booking/submit
// ============================================================================
$router->post('/api/tours/booking/submit', function () use ($db) {

    header('Content-Type: application/json');

    try {
        $userId = null;
        $userData = null;

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        // --------------------------------------------------
        // RESOLVE LOGGED-IN USER / AGENT FROM JWT TOKEN
        // --------------------------------------------------
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headersLower = [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                $headersLower[$hKey] = $v;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $authHeader = $headersLower['authorization'] ?? '';
        $token = '';
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
        }

        if (empty($token)) {
            $token = $headersLower['token']
                  ?? $headersLower['jwt']
                  ?? $headersLower['x-access-token']
                  ?? '';
        }

        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                  ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                  ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = $tokenData['user_id'];
                    $whereClause = ['user_id' => (string)$userId];
                    if (is_numeric($userId)) {
                        $whereClause = [
                            'OR' => [
                                'user_id' => (string)$userId,
                                'id'      => (int)$userId
                            ]
                        ];
                    }
                    $userData = $db->get('users', '*', $whereClause);
                    if ($userData) {
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = $userData['role'] ?? null;
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // Prefer booking_hash; keep hash as a backward-compatible alias for older clients.
        $bookingHashInput = $input['booking_hash'] ?? ($input['hash'] ?? '');
        if ($bookingHashInput === '') {
            throw new Exception('Invalid booking submission');
        }

        $bookingHash = trim((string)$bookingHashInput);
        if (!preg_match('/^[a-f0-9]{16}$/', $bookingHash)) {
            throw new Exception('Invalid booking hash');
        }

        $guestDetails = $input['guest_details'] ?? [];
        if (!is_array($guestDetails)) {
            throw new Exception('Invalid guest_details payload');
        }
        $baseCurrency = strtoupper(trim((string)($input['base_currency'] ?? 'USD')));
        // booking_type may be sent inside guest_details or at the top level.
        $bookingType = trim((string)($guestDetails['booking_type'] ?? ($input['booking_type'] ?? 'guest')));

        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Terms and conditions must be accepted');
        }

        $primaryGuest = $guestDetails['primary_guest'] ?? [];
        if (!is_array($primaryGuest)) {
            throw new Exception('Primary guest details are required');
        }

        $firstName = htmlspecialchars(strip_tags(trim((string)($primaryGuest['first_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars(strip_tags(trim((string)($primaryGuest['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $email = filter_var(trim((string)($primaryGuest['email'] ?? '')), FILTER_SANITIZE_EMAIL);
        $phone = preg_replace('/[^0-9+\-\s]/', '', (string)($primaryGuest['phone'] ?? ''));
        $countryCode = trim((string)($primaryGuest['country_code'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Please fill in all required guest details');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking || empty($booking['data'])) {
            throw new Exception('Booking not found');
        }

        $bookingData = json_decode($booking['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        // ============================================================================
        // RE-EVALUATE AND ENFORCE MARKUP SERVER-SIDE
        // ============================================================================
        $supplier = $bookingData['supplier'] ?? 'tours';
        $displayCurrency = $bookingData['currency'] ?? 'USD';

        $actualPricePerPerson = (float)($bookingData['actual_price_per_person'] ?? 0);
        $actualPricePerChild = (float)($bookingData['actual_price_per_child'] ?? 0);
        $markupPricePerPerson = $actualPricePerPerson;
        $markupPricePerChild = $actualPricePerChild;

        $toursModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'tours', 'status' => '1']);
        if (!$toursModule && $supplier !== 'tours') {
            $toursModule = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => '1']);
        }

        if ($actualPricePerPerson > 0 && function_exists('MARKUP')) {
            $markedPerson = MARKUP($actualPricePerPerson, $toursModule ?: null, $db, $displayCurrency, $displayCurrency);
            if (!empty($markedPerson['price']) && $markedPerson['price'] > 0) {
                $markupPricePerPerson = (float)$markedPerson['price'];
            }
        }
        if ($actualPricePerChild > 0 && function_exists('MARKUP')) {
            $markedChild = MARKUP($actualPricePerChild, $toursModule ?: null, $db, $displayCurrency, $displayCurrency);
            if (!empty($markedChild['price']) && $markedChild['price'] > 0) {
                $markupPricePerChild = (float)$markedChild['price'];
            }
        }

        $totalAdults = (int)($bookingData['total_adults'] ?? 1);
        $totalChildren = (int)($bookingData['total_children'] ?? 0);

        $actualPrice = round(($actualPricePerPerson * $totalAdults) + ($actualPricePerChild * $totalChildren), 2);
        $markupPrice = round(($markupPricePerPerson * $totalAdults) + ($markupPricePerChild * $totalChildren), 2);

        // Update bookingData to reflect server-side computed rates
        $bookingData['markup_price_per_person'] = round($markupPricePerPerson, 2);
        $bookingData['markup_price_per_child'] = round($markupPricePerChild, 2);
        $bookingData['markup_total_price_persons'] = round($markupPricePerPerson * $totalAdults, 2);
        $bookingData['markup_total_price_childrens'] = round($markupPricePerChild * $totalChildren, 2);
        $bookingData['markup_total_tour_price'] = $markupPrice;

        $subtotal = $markupPrice;
        $taxAmount = (float)($input['tax_amount'] ?? 0);

        // Always calculate pricing from draft data and backend tax rules.
        $taxInfo = calculateTax($markupPrice, 'tours', $db);
        $calculatedTaxAmount = (float)($taxInfo['tax_amount'] ?? 0);
        
        if ($taxAmount == 0) {
            $taxAmount = $calculatedTaxAmount;
        }

        $finalTotalWithTax = $markupPrice + $taxAmount;

        $currencyRow = $db->get('currencies', 'name', ['name' => $baseCurrency, 'status' => 1]);
        if (!$currencyRow) {
            throw new Exception('Unsupported base currency');
        }

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        // --------------------------------------------------
        // PROMO CODE HANDLING
        // --------------------------------------------------
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData = null;
        if (!empty($promoCodeStr) && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code' => $promoData['code'],
                    'discount_type' => $promoData['discount_type'],
                    'discount_value' => floatval($promoData['discount_value']),
                    'discount_amount' => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description' => $promoData['description'],
                    'module' => $promoData['module']
                ]);
            }
        }

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotalWithTax = round($finalTotalWithTax - $promoDiscount, 2);
        }

        $baseCurrency = resolveBaseCurrency($db, $baseCurrency, $bookingData['currency'] ?? null);
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotalWithTax, $baseCurrency, $displayCurrency);
        $bookingData['base_currency'] = $baseCurrency;
        $bookingData['display_currency'] = $displayCurrency;
        $bookingData['conversion_rate'] = $conversionRate;
        $bookingData['final_total_display'] = $displayFinalTotal;
        $bookingData['markup_total_tour_price_display'] = convertCurrencyAmount($db, $markupPrice, $baseCurrency, $displayCurrency);
        $bookingData['tax_amount_display'] = convertCurrencyAmount($db, $taxAmount, $baseCurrency, $displayCurrency);

        $nationalityData = $db->get('countries', 'nicename', ['iso' => $bookingData['nationality'] ?? '']);
        $nationalityName = $nationalityData ?: ($bookingData['nationality'] ?? '');

        // ============================================================================
        // AGENT DETECTION & EARNING
        // ============================================================================
        $isAgent = false;
        if (!empty($userData)) {
            $isAgent = ($userData['role'] ?? '') === 'agent';
        }

        $commission = max(0, round($markupPrice - $actualPrice, 2));
        $agentEarning = $isAgent ? $commission : 0;

        $travellersData = [
            'primary_guest' => [
                'title' => trim((string)($primaryGuest['title'] ?? '')),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country_code' => $countryCode
            ],
            'travelers' => $guestDetails['travelers'] ?? ($guestDetails['passengers'] ?? []),
            'booking_for_someone_else' => $guestDetails['booking_for_someone_else'] ?? false,
            'booking_type' => $bookingType
        ];

        if ($userId) {
            $userData = $db->get('users', '*', ['user_id' => $userId]);
        } else {
            $existingUser = $db->get('users', '*', ['email' => $email]);
            if ($existingUser) {
                $userId = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                $newUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $generatedPassword = bin2hex(random_bytes(4));
                $created = $db->insert('users', [
                    'user_id' => $newUserId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => password_hash($generatedPassword, PASSWORD_DEFAULT),
                    'phone' => $phone,
                    'phone_country_code' => $countryCode,
                    'status' => 'active',
                    'role' => 'user',
                    'created_at' => date('Y-m-d H:i:s'),
                    'email_verified' => 0
                ]);
                if ($created) {
                    $userId = $newUserId;
                    $userData = $db->get('users', '*', ['user_id' => $newUserId]);
                }
            }
        }

        // Accept selected_payment as id, name, or type and normalize it to gateway id.
        $paymentGatewayInput = trim((string)($guestDetails['selected_payment'] ?? ''));
        $paymentGateway = '';
        if ($paymentGatewayInput !== '') {
            $gateway = null;

            if (ctype_digit($paymentGatewayInput)) {
                $gateway = $db->get('payment_gateways', ['id'], [
                    'id' => (int)$paymentGatewayInput,
                    'status' => 1
                ]);
            }

            if (!$gateway) {
                $gateway = $db->get('payment_gateways', ['id'], [
                    'name' => $paymentGatewayInput,
                    'status' => 1
                ]);
            }

            if (!$gateway) {
                $gateway = $db->get('payment_gateways', ['id'], [
                    'type' => $paymentGatewayInput,
                    'status' => 1
                ]);
            }

            if ($gateway && !empty($gateway['id'])) {
                $paymentGateway = (string)$gateway['id'];
            }
        }

        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_date' => date('Y-m-d H:i:s'),
            'booking_status' => 'pending',
            'price_original' => $actualPrice,
            'price_markup' => $finalTotalWithTax,
            'agent_earning' => $agentEarning,
            'tax' => $taxAmount,
            'tax_type' => $taxInfo['tax_type'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'address' => '',
            'phone_country_code' => getPhoneCode($countryCode, $db),
            'phone' => $phone,
            'country' => $countryCode,
            'adults' => $totalAdults,
            'infants' => 0,
            'childs' => $totalChildren,
            'child_ages' => json_encode([]),
            'currency_markup' => $bookingData['currency'] ?? $baseCurrency,
            'cancellation_request' => 0,
            'cancellation_status' => 0,
            'booking_data' => json_encode($bookingData),
            'payment_status' => 'unpaid',
            'transaction_id' => null,
            'user_id' => $userId,
            'user_data' => $userData ? json_encode($userData) : null,
            'travellers' => json_encode($travellersData),
            'nationality' => $nationalityName,
            'payment_gateway' => $paymentGateway,
            'module_type' => 'tours',
            'pnr' => null,
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commission,
            'module' => $bookingData['supplier'] ?? 'tours',
            'special_requests' => $guestDetails['special_requests'] ?? null,
            'promo_codes' => $promoCodeJson
        ]);

        $bookingResult = $db->id();

        if (!$bookingResult) {
            throw new Exception('Failed to save tour booking');
        }

        // Record promo code usage
        if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
            $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
        }

        triggerWebhook('tours/booking', 'tours.booking.confirmed', [
            'booking_id' => $bookingResult,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'customer_name' => $firstName . ' ' . $lastName,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'tour_name' => $bookingData['tour_name'] ?? '',
            'tour_location' => $bookingData['tour_location'] ?? '',
            'start_date' => $bookingData['start_date'] ?? '',
            'duration' => $bookingData['duration'] ?? '',
            'total_amount' => $displayFinalTotal,
            'currency' => $displayCurrency,
            'adults' => $totalAdults,
            'children' => $totalChildren,
            'payment_gateway' => $paymentGateway,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $db->delete('logs_bookings', ['hash' => $bookingHash]);

        echo json_encode([
            'success' => true,
            'message' => 'Tour booking confirmed successfully',
            'booking_id' => $invoiceId,
            'invoice_id' => $invoiceId,
            'amount' => $displayFinalTotal,
            'amount_base' => $finalTotalWithTax,
            'currency' => $displayCurrency,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'redirect_url' => root . 'invoice/tours/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
            'countdown' => true
        ]);

    } catch (Exception $e) {

        triggerWebhook('tours/booking', 'tours.booking.failed', [
            'error_message' => $e->getMessage(),
            'booking_data' => $input ?? [],
            'user_id' => null,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
