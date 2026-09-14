<?php
// ============================================================================
// FILE: app/routes/api/visa/bookingRoutes.php
// VISA BOOKING API
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ============================================================================
// GET: Get booking draft by hash
// GET /api/visa/booking/{hash}
// ============================================================================
$router->get('/api/visas/booking/([a-f0-9]{16})', function ($hash) use ($db) {

    header('Content-Type: application/json');

    try {

        $booking = $db->get('logs_bookings', ['hash', 'data', 'created_at'], [
            'hash' => $hash
        ]);

        if (!$booking || empty($booking['data'])) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Booking draft not found'
            ]);
            exit;
        }

        $bookingData = json_decode($booking['data'], true);

        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'booking_data' => $bookingData,
            'created_at' => $booking['created_at']
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
// POST: Save visa booking draft
// POST /api/visa/booking/draft
// ============================================================================
$router->post('/api/visas/booking/draft', function () use ($db) {

    header('Content-Type: application/json');

    try {

        // --------------------------------------------------
        // PARSE INPUT
        // --------------------------------------------------
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (empty($input)) {
            throw new Exception('No input data provided');
        }

        // --------------------------------------------------
        // EXTRACT & VALIDATE PARAMETERS
        // --------------------------------------------------
        $fromCountry = strtoupper(trim($input['from_country'] ?? ''));
        $toCountry = strtoupper(trim($input['to_country'] ?? ''));
        $entryDate = trim($input['entry_date'] ?? ($input['travel_date'] ?? ''));
        $visaType = strtolower(trim($input['visa_type'] ?? ''));
        $processingSpeed = strtolower(trim($input['processing_speed'] ?? ''));
        $travelers = max(1, min(10, (int) ($input['travelers'] ?? 1)));

        // From Country
        if ($fromCountry === '' || !preg_match('/^[A-Z]{2}$/', $fromCountry)) {
            throw new Exception('Invalid from_country. Use 2-letter ISO code (e.g., PK)');
        }

        // To Country
        if ($toCountry === '' || !preg_match('/^[A-Z]{2}$/', $toCountry)) {
            throw new Exception('Invalid to_country. Use 2-letter ISO code (e.g., TR)');
        }

        // Countries must be different
        if ($fromCountry === $toCountry) {
            throw new Exception('From and To countries cannot be the same');
        }

        // Entry Date
        if ($entryDate === '') {
            throw new Exception('Entry date is required');
        }
        $validDate = DateTime::createFromFormat('d-m-Y', $entryDate)
            ?: DateTime::createFromFormat('Y-m-d', $entryDate);
        if (!$validDate) {
            throw new Exception('Invalid date format. Use DD-MM-YYYY or YYYY-MM-DD');
        }
        $normalizedDate = $validDate->format('d-m-Y');

        // Visa Type
        if ($visaType === '') {
            throw new Exception('Visa type is required');
        }

        // Processing Speed
        if ($processingSpeed === '') {
            throw new Exception('Processing speed is required');
        }

        // --------------------------------------------------
        // VALIDATE COUNTRIES EXIST IN DATABASE
        // --------------------------------------------------
        $fromCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $fromCountry,
            'status' => 'active'
        ]);
        if (!$fromCountryData) {
            throw new Exception("From country ($fromCountry) not found or not active");
        }

        $toCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $toCountry,
            'status' => 'active'
        ]);
        if (!$toCountryData) {
            throw new Exception("To country ($toCountry) not found or not active");
        }

        // --------------------------------------------------
        // VALIDATE VISA TYPE & PROCESSING SPEED IN SETTINGS
        // --------------------------------------------------
        $visaTypeSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
            'value' => $visaType,
            'setting_type' => 'visa_type',
            'status' => 1
        ]);
        if (!$visaTypeSetting) {
            throw new Exception("Invalid visa type: $visaType");
        }

        $processingSpeedSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
            'value' => $processingSpeed,
            'setting_type' => 'processing_speed',
            'status' => 1
        ]);
        if (!$processingSpeedSetting) {
            throw new Exception("Invalid processing speed: $processingSpeed");
        }

        // --------------------------------------------------
        // FETCH VISA RECORD FROM DATABASE
        // --------------------------------------------------
        $visaRecord = $db->get('visa', '*', [
            'from_country_id' => $fromCountryData['id'],
            'to_country_id' => $toCountryData['id'],
            'status' => 1
        ]);

        $govtFee = 0;
        $serviceFee = 0;
        $totalPricePerPerson = 0;
        $visaCurrency = 'USD';
        $entryType = '';
        $durationDays = 0;
        $isInquiryOnly = true;

        if ($visaRecord && !empty($visaRecord['prices'])) {
            $pricesData = json_decode($visaRecord['prices'], true);
            if (is_array($pricesData)) {
                foreach ($pricesData as $variant) {
                    if (
                        ($variant['visa_type'] ?? '') === $visaType
                        && ($variant['processing_speed'] ?? '') === $processingSpeed
                    ) {
                        $govtFee = (float) ($variant['govt_fee'] ?? 0);
                        $serviceFee = (float) ($variant['service_fee'] ?? 0);
                        $totalPricePerPerson = (float) ($variant['total_price'] ?? 0);
                        $visaCurrency = !empty($variant['currency']) ? $variant['currency'] : 'USD';
                        $entryType = $variant['entry_type'] ?? '';
                        $durationDays = ($variant['duration_days'] ?? 0);
                        $isInquiryOnly = ($totalPricePerPerson <= 0);
                        break;
                    }
                }
            }
        }

        // --------------------------------------------------
        // CURRENCY CONVERSION
        // --------------------------------------------------
        $defaultCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
        $targetCurrency = $defaultCurrencyData['name'] ?? 'USD';

        $displayGovtFee = $govtFee;
        $displayServiceFee = $serviceFee;
        $displayPricePerPerson = $totalPricePerPerson;

        if ($visaCurrency !== $targetCurrency && $totalPricePerPerson > 0) {
            $convGovt = CURRENCY_CONVERT($govtFee, $db, $visaCurrency, $targetCurrency);
            $convService = CURRENCY_CONVERT($serviceFee, $db, $visaCurrency, $targetCurrency);
            $convTotal = CURRENCY_CONVERT($totalPricePerPerson, $db, $visaCurrency, $targetCurrency);

            $displayGovtFee = round($convGovt['price'], 2);
            $displayServiceFee = round($convService['price'], 2);
            $displayPricePerPerson = round($convTotal['price'], 2);
        }

        $displayTotalPrice = round($displayPricePerPerson * $travelers, 2);

        // --------------------------------------------------
        // ENTRY TYPE NAME
        // --------------------------------------------------
        $entryTypeData = null;
        if (!empty($entryType)) {
            $entryTypeData = $db->get('visa_settings', ['value', 'name', 'icon'], [
                'value' => $entryType,
                'setting_type' => 'entry_type',
                'status' => 1
            ]);
        }

        // --------------------------------------------------
        // PARSE REQUIREMENTS
        // --------------------------------------------------
        $requirements = [];
        if (!empty($visaRecord['requirements'])) {
            $reqDecoded = json_decode($visaRecord['requirements'], true);
            if (is_array($reqDecoded)) {
                $requirements = array_values(array_filter($reqDecoded, function ($r) {
                    return !empty(trim($r));
                }));
            }
        }

        // --------------------------------------------------
        // BUILD DRAFT DATA
        // --------------------------------------------------
        $bookingDraftData = [
            'from_country' => $fromCountry,
            'to_country' => $toCountry,
            'entry_date' => $normalizedDate,
            'visa_type' => $visaType,
            'processing_speed' => $processingSpeed,
            'travelers' => $travelers,
            'price_per_person' => $displayPricePerPerson,
            'total_amount' => $displayTotalPrice,
            'govt_fee' => $displayGovtFee,
            'service_fee' => $displayServiceFee,
            'currency' => $targetCurrency
        ];

        // --------------------------------------------------
        // STORE DRAFT
        // --------------------------------------------------
        $hash = bin2hex(random_bytes(8));
        if (strlen($hash) !== 16) {
            throw new Exception('Failed to generate valid booking hash');
        }

        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($bookingDraftData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new Exception('Failed to save booking draft');
        }

        // --------------------------------------------------
        // RESPONSE
        // --------------------------------------------------
        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'message' => 'Visa booking draft saved successfully',
            'summary' => [
                'from_country' => $fromCountryData['nicename'],
                'to_country' => $toCountryData['nicename'],
                'visa_type' => $visaTypeSetting['name'],
                'processing_speed' => $processingSpeedSetting['name'],
                'entry_date' => $normalizedDate,
                'travelers' => $travelers,
                'is_inquiry_only' => $isInquiryOnly,
                'inquiry_title' => T::visa_inquiry ?? 'Visa Inquiry',
                'inquiry_notice' => T::no_payment_required ?? 'No payment required now. Our team will contact you with final pricing and next steps.',
                'price_on_request_title' => T::price_on_request ?? 'Price on Request',
                'price_on_request_notice' => T::team_will_provide_quote ?? 'Our team will provide you with a detailed quote based on your inquiry',
                'pricing' => [
                    'govt_fee' => $displayGovtFee,
                    'service_fee' => $displayServiceFee,
                    'price_per_person' => $displayPricePerPerson,
                    'total_price' => $displayTotalPrice,
                    'currency' => $targetCurrency
                ]
            ]
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
// POST: Submit final visa booking
// POST /api/visa/booking/submit
// ============================================================================
$router->post('/api/visas/booking/submit', function () use ($db) {

    header('Content-Type: application/json');

    try {

        // --------------------------------------------------
        // OPTIONAL JWT AUTH
        // --------------------------------------------------
        $userId = null;
        $userData = null;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization']
            ?? $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? '';

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $tokenData = JWT::verify($matches[1]);
            if ($tokenData && !empty($tokenData['user_id'])) {
                $userId = $tokenData['user_id'];
                $userData = $db->get('users', '*', ['user_id' => $userId]);
            }
        }

        // --------------------------------------------------
        // PARSE INPUT
        // --------------------------------------------------
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $bookingHash = trim(($input['booking_hash'] ?? ($input['hash'] ?? '')));
        if ($bookingHash === '') {
            throw new Exception('booking_hash is required');
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $bookingHash)) {
            throw new Exception('Invalid booking hash format');
        }

        // --------------------------------------------------
        // VALIDATE GUEST DETAILS
        // --------------------------------------------------
        $guestDetails = $input['guest_details'] ?? [];
        if (!is_array($guestDetails)) {
            throw new Exception('guest_details is required');
        }

        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Terms and conditions must be accepted');
        }

        $primaryGuest = $guestDetails['primary_guest'] ?? [];
        if (!is_array($primaryGuest)) {
            throw new Exception('primary_guest details are required');
        }

        $firstName = htmlspecialchars(strip_tags(trim(($primaryGuest['first_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars(strip_tags(trim(($primaryGuest['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $email = filter_var(trim(($primaryGuest['email'] ?? '')), FILTER_SANITIZE_EMAIL);
        $phone = preg_replace('/[^0-9+\-\s]/', '', ($primaryGuest['phone'] ?? ''));
        $countryCode = trim(($primaryGuest['country_code'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Please fill in all required guest details (first_name, last_name, email)');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address format');
        }

        // --------------------------------------------------
        // VALIDATE TRAVELERS
        // --------------------------------------------------
        $travelers = $input['travelers'] ?? [];
        if (!is_array($travelers) || count($travelers) < 1) {
            throw new Exception('At least one traveler is required');
        }

        // Admin-configurable document requirements (Settings > Visa). Defaults to
        // optional/optional, matching the product's pre-existing behavior, until an
        // admin opts in. This is the AUTHORITATIVE check — the frontend mirrors it
        // for UX only and cannot be relied on (JS can be disabled/bypassed).
        $visaDocSettings = $db->get('settings', ['visa_passport_required', 'visa_national_id_required'], ['id' => 1]);
        $visaPassportRequired = (($visaDocSettings['visa_passport_required'] ?? '0') === '1');
        $visaNationalIdRequired = (($visaDocSettings['visa_national_id_required'] ?? '0') === '1');

        foreach ($travelers as $i => $traveler) {
            $idx = $i + 1;
            if (empty($traveler['title'])) {
                throw new Exception("Title is required for traveler $idx");
            }
            if (empty($traveler['first_name'])) {
                throw new Exception("First name is required for traveler $idx");
            }
            if (empty($traveler['last_name'])) {
                throw new Exception("Last name is required for traveler $idx");
            }
            if (empty($traveler['passport_number'])) {
                throw new Exception("Passport number is required for traveler $idx");
            }
            if (empty($traveler['nationality'])) {
                throw new Exception("Nationality is required for traveler $idx");
            }
            if (empty($traveler['date_of_birth'])) {
                throw new Exception("Date of birth is required for traveler $idx");
            }
            if (empty($traveler['passport_expiry'])) {
                throw new Exception("Passport expiry is required for traveler $idx");
            }
            if ($visaPassportRequired && empty($traveler['passport_copy'])) {
                throw new Exception("Passport copy is required for traveler $idx");
            }
            if ($visaNationalIdRequired && (empty($traveler['national_id_front_copy']) || empty($traveler['national_id_back_copy']))) {
                throw new Exception("National ID (front and back) is required for traveler $idx");
            }
        }

        $specialRequests = trim(($input['special_requests'] ?? ''));

        // --------------------------------------------------
        // GET DRAFT FROM DATABASE
        // --------------------------------------------------
        $draft = $db->get('logs_bookings', ['data', 'created_at'], ['hash' => $bookingHash]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Booking draft not found or expired. Please create a new draft.');
        }

        $bookingData = json_decode($draft['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        // --------------------------------------------------
        // RE-VALIDATE PRICING FROM DATABASE
        // --------------------------------------------------
        $bFromIso = $bookingData['from_country'];
        $bToIso = $bookingData['to_country'];
        $bVisaType = $bookingData['visa_type'];
        $bSpeed = $bookingData['processing_speed'];
        $travelersCount = count($travelers);

        $cFromId = $db->get('countries', 'id', ['iso' => $bFromIso]);
        $cToId = $db->get('countries', 'id', ['iso' => $bToIso]);

        // Default Currency
        $defaultCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
        $defaultCurrency = $defaultCurrencyData['name'] ?? 'USD';

        // Fetch visa record
        $visaInfoRaw = $db->get('visa', '*', [
            'from_country_id' => $cFromId,
            'to_country_id' => $cToId,
            'status' => 1
        ]);

        $govtFeeOriginal = 0;
        $serviceFeeOriginal = 0;
        $totalPriceOriginal = 0;
        $visaOriginalCurrency = 'USD';

        if ($visaInfoRaw && !empty($visaInfoRaw['prices'])) {
            $pricesJson = json_decode($visaInfoRaw['prices'], true);
            if (is_array($pricesJson)) {
                foreach ($pricesJson as $variant) {
                    if ($variant['visa_type'] === $bVisaType && $variant['processing_speed'] === $bSpeed) {
                        $govtFeeOriginal = (float) $variant['govt_fee'];
                        $serviceFeeOriginal = (float) $variant['service_fee'];
                        $totalPriceOriginal = (float) $variant['total_price'];
                        $visaOriginalCurrency = $variant['currency'] ?: 'USD';
                        break;
                    }
                }
            }
        }

        // Convert to default currency
        $costConversion = CURRENCY_CONVERT($govtFeeOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $govtFeeDefault = $costConversion['price'];

        $commConversion = CURRENCY_CONVERT($serviceFeeOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $serviceFeeDefault = $commConversion['price'];

        $totalConversion = CURRENCY_CONVERT($totalPriceOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $totalPriceDefault = $totalConversion['price'];

        // Total calculations
        $finalPriceOriginal = $govtFeeDefault * $travelersCount;
        $finalCommission = $serviceFeeDefault * $travelersCount;
        $finalPriceMarkup = $totalPriceDefault * $travelersCount;
        $taxAmount = 0;

        // AGENT COMMISSION (docs/MONEY-WALLET-AUDIT.md §C.4c) — mirrors the web
        // visa path: customer price unchanged; an agent earns b2b markup % of the
        // selling total (the member-tier adds a small BONUS, never a reduction —
        // here b2b% is the agent's reward, not a cost), capped at the service-fee
        // margin, or the full service fee if no b2b markup is set.
        $visaAgentEarning = 0.0;
        $visaIsAgent = is_array($userData) && strtolower((string)($userData['role'] ?? '')) === 'agent';
        if ($visaIsAgent && !empty($userId)) {
            $visaModuleRow = $db->get('modules', ['markup_b2b', 'markup_type_b2b'], ['type' => 'visa']);
            $b2bVal  = (float)($visaModuleRow['markup_b2b'] ?? 0);
            $b2bType = strtolower((string)($visaModuleRow['markup_type_b2b'] ?? 'percentage'));
            if ($b2bVal > 0) {
                if ($b2bType === 'percentage') {
                    if (!function_exists('agent_tier_discount_percent')) {
                        $walletLib = dirname(__DIR__, 4) . '/app/lib/wallet.php';
                        if (file_exists($walletLib)) { require_once $walletLib; }
                    }
                    $tierBonus = function_exists('agent_tier_discount_percent')
                        ? (float) agent_tier_discount_percent($db, (string)$userId) : 0.0;
                    $effPct = $b2bVal + $tierBonus;
                    $visaAgentEarning = round($finalPriceMarkup * $effPct / 100, 2);
                } else {
                    $visaAgentEarning = round($b2bVal * $travelersCount, 2);
                }
            } else {
                $visaAgentEarning = round($finalCommission, 2);
            }
            $visaAgentEarning = max(0.0, min($visaAgentEarning, (float)$finalCommission));
        }

        // --------------------------------------------------
        // GET COUNTRY / SETTING NAMES
        // --------------------------------------------------
        $fromCountryName = $db->get('countries', 'nicename', ['iso' => $bFromIso]);
        $toCountryName = $db->get('countries', 'nicename', ['iso' => $bToIso]);
        $visaTypeName = $db->get('visa_settings', 'name', ['value' => $bVisaType, 'setting_type' => 'visa_type']);
        $processingSpeedName = $db->get('visa_settings', 'name', ['value' => $bSpeed, 'setting_type' => 'processing_speed']);

        // --------------------------------------------------
        // PREPARE COMPLETE BOOKING DATA
        // --------------------------------------------------
        $completeBookingData = [
            'from_country' => $bFromIso,
            'from_country_name' => $fromCountryName ?? $bFromIso,
            'to_country' => $bToIso,
            'to_country_name' => $toCountryName ?? $bToIso,
            'visa_type' => $bVisaType,
            'visa_type_name' => $visaTypeName ?? $bVisaType,
            'processing_speed' => $bSpeed,
            'processing_speed_name' => $processingSpeedName ?? $bSpeed,
            'entry_date' => $bookingData['entry_date'],
            'travelers_count' => $travelersCount,
            'travelers' => $travelers,
            'special_requests' => $specialRequests,
            'price_per_traveler' => $totalPriceDefault,
            'currency' => $defaultCurrency,
            'subtotal' => $finalPriceMarkup,
            'tax_amount' => $taxAmount,
            'total_amount' => $finalPriceMarkup
        ];

        $baseCurrency = resolveBaseCurrency($db, $defaultCurrency);
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalPriceMarkup, $baseCurrency, $displayCurrency);
        $completeBookingData['base_currency'] = $baseCurrency;
        $completeBookingData['display_currency'] = $displayCurrency;
        $completeBookingData['conversion_rate'] = $conversionRate;
        $completeBookingData['final_total_display'] = $displayFinalTotal;
        $completeBookingData['total_amount_display'] = $displayFinalTotal;

        // --------------------------------------------------
        // USER RESOLUTION
        // --------------------------------------------------
        if (!$userId) {
            $existingUser = $db->get('users', '*', ['email' => $email]);

            if ($existingUser) {
                $userId = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                // Auto-create new user
                $generatedUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $generatedPassword = bin2hex(random_bytes(4));

                $db->insert('users', [
                    'user_id' => $generatedUserId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_country_code' => $countryCode,
                    'password' => password_hash($generatedPassword, PASSWORD_DEFAULT),
                    'status' => 'active',
                    'role' => 'user',
                    'email_verified' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $userId = $generatedUserId;
                $userData = $db->get('users', '*', ['user_id' => $generatedUserId]);
            }
        }

        // --------------------------------------------------
        // GENERATE INVOICE ID
        // --------------------------------------------------
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // --------------------------------------------------
        // INSERT BOOKING INTO DATABASE
        // --------------------------------------------------
        $bookingInsert = $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'user_id' => $userId,
            'module' => 'visa',
            'module_type' => 'visa',
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'phone_country_code' => $countryCode,
            'currency_markup' => $defaultCurrency,
            'price_original' => $finalPriceOriginal,
            'price_markup' => $finalPriceMarkup,
            'commission' => $finalCommission,
            'agent_earning' => $visaAgentEarning,
            'tax' => $taxAmount,
            'tax_type' => 'fixed',
            'travellers' => json_encode($travelers),
            'child_ages' => '[]',
            'country' => $countryCode,
            'address' => '',
            'booking_data' => json_encode($completeBookingData),
            'special_requests' => $specialRequests,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d')
        ]);

        if (!$bookingInsert) {
            throw new Exception('Failed to create booking. Please try again.');
        }

        // AGENT API — wallet settlement (no-op unless agent-API request).
        $visaBookingId = $db->id();
        agent_api_settle_booking($db, 'visa', $visaBookingId, $invoiceId, (float) $finalPriceMarkup);

        // --------------------------------------------------
        // DELETE TEMPORARY DRAFT
        // --------------------------------------------------
        $db->delete('logs_bookings', ['hash' => $bookingHash]);

        // --------------------------------------------------
        // GENERATE PDF
        // --------------------------------------------------
        $pdfPath = null;
        try {
            $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        } catch (Exception $e) {
            error_log('Visa PDF Generation failed (mobile): ' . $e->getMessage());
        }

        // --------------------------------------------------
        // SEND NOTIFICATIONS (Email, WhatsApp, SMS)
        // --------------------------------------------------
        try {
            NOTIFY::booking('visa', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country_code' => $countryCode
            ], array_merge($completeBookingData, [
                    'invoice_id' => $invoiceId,
                    'booking_status' => 'pending',
                    'payment_status' => 'unpaid',
                    'amount' => $displayFinalTotal,
                    'currency' => $displayCurrency
                ]), $pdfPath);
        } catch (Exception $e) {
            error_log('Visa notifications failed' . $e->getMessage());
        }

        // --------------------------------------------------
        // RESPONSE
        // --------------------------------------------------
        // Let the (possibly guest) session that created this invoice view it —
        // otherwise enforceInvoiceAccess() bounces them to /login on their own
        // fresh invoice (see grantInvoiceSessionOwnership()).
        if (function_exists('grantInvoiceSessionOwnership')) {
            grantInvoiceSessionOwnership($invoiceId);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Visa inquiry submitted successfully! Our team will contact you shortly.',
            'invoice_id' => $invoiceId,
            'amount' => $displayFinalTotal,
            'amount_base' => $finalPriceMarkup,
            'currency' => $displayCurrency,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'redirect_url' => root . 'invoice/visa/' . $invoiceId . '?currency=' . urlencode($displayCurrency)
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

