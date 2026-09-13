<?php
// ============================================================================
// FILE: app/routes/visa/bookingRoutes.php
// VISA BOOKING ROUTES - API endpoints for booking submission
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// POST: Save booking draft
// POST /api/visa/booking/save-draft
// ============================================================================
$router->post('/api/visa/booking/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['from_country'])) {
            throw new Exception('Invalid booking data');
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'hash' => $hash,
                'redirect_url' => root . 'visa/booking/' . $hash
            ]);
        } else {
            throw new Exception('Failed to save booking draft');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'visa',
                'message' => $e->getMessage()
            ],
            'raw_response' => null
        ]);
    }
    exit;
});

// ============================================================================
// POST: Submit final visa booking
// POST /api/visa/booking/submit
// ============================================================================
$router->post('/api/visa/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking data');
        }

        // VALIDATE CSRF TOKEN
        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validateToken($csrfToken)) {
            throw new Exception('Invalid security token. Please refresh and try again.');
        }

        $bookingHash = $input['booking_hash'];
        $guestDetails = $input['guest_details'] ?? [];
        $travelers = $input['travelers'] ?? [];
        $finalTotal = $input['final_total'] ?? 0;
        $subtotal = $input['subtotal'] ?? 0;
        $taxAmount = $input['tax_amount'] ?? 0;
        $currency = $input['currency'] ?? 'USD';
        $specialRequests = $input['special_requests'] ?? '';

        // VALIDATE TERMS ACCEPTANCE
        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Please accept the terms and conditions');
        }

        // GET ORIGINAL BOOKING DATA FROM TEMP TABLE
        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking) {
            throw new Exception('Booking session expired. Please start again.');
        }
        $bookingData = json_decode($booking['data'], true);

        // GENERATE 8-CHARACTER INVOICE ID
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // EXTRACT PRIMARY GUEST DETAILS
        $primaryGuest = $guestDetails['primary_guest'];
        
        // ==========================================================
        // VISA PRICING & INSERTION LOGIC (FIXED DATABASE PRICING)
        // ==========================================================
        // 1. Get Default Currency
        $defaultCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
        $defaultCurrency = $defaultCurrencyData['name'] ?? 'USD';
        
        // 2. Resolve Country IDs based on ISO codes from booking data
        $bFromIso = $bookingData['from_country'];
        $bToIso = $bookingData['to_country'];
        $bVisaType = $bookingData['visa_type'];
        $bSpeed = $bookingData['processing_speed'];
        
        $cFromId = $db->get('countries', 'id', ['iso' => $bFromIso]);
        $cToId = $db->get('countries', 'id', ['iso' => $bToIso]);
        
        // 3. Fetch Visa Info to get Fees
        $visaInfoRaw = $db->get('visa', '*', [
            'from_country_id' => $cFromId,
            'to_country_id' => $cToId,
            'status' => 1
        ]);
        
        $govtFeeOriginal = 0;   // "Visa Fee" -> price_original
        $serviceFeeOriginal = 0; // "Commission" -> commission
        $totalPriceOriginal = 0; // "Total Price" -> price_markup
        $visaOriginalCurrency = 'USD';
        
        if ($visaInfoRaw && !empty($visaInfoRaw['prices'])) {
            $pricesJson = json_decode($visaInfoRaw['prices'], true);
            if (is_array($pricesJson)) {
                foreach ($pricesJson as $variant) {
                    if ($variant['visa_type'] === $bVisaType && $variant['processing_speed'] === $bSpeed) {
                        $govtFeeOriginal = (float)$variant['govt_fee'];
                        $serviceFeeOriginal = (float)$variant['service_fee'];
                        $totalPriceOriginal = (float)$variant['total_price'];
                        $visaOriginalCurrency = $variant['currency'] ?: 'USD';
                        break;
                    }
                }
            }
        }
        
        // 4. Convert All Fees to Default Currency
        $costConversion = CURRENCY_CONVERT($govtFeeOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $govtFeeDefault = $costConversion['price'];
        
        $commConversion = CURRENCY_CONVERT($serviceFeeOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $serviceFeeDefault = $commConversion['price'];
        
        $totalConversion = CURRENCY_CONVERT($totalPriceOriginal, $db, $visaOriginalCurrency, $defaultCurrency);
        $totalPriceDefault = $totalConversion['price'];
        
        
        // Total Calculations based on Travelers Count
        $travelersCount = count($travelers);
        
        $finalPriceOriginal = $govtFeeDefault * $travelersCount;      // Total Cost (Visa Fees)
        $finalCommission = $serviceFeeDefault * $travelersCount;      // Total Commission
        $finalPriceMarkup = $totalPriceDefault * $travelersCount;     // Total Selling Price

        // NO TAX OR MARKUP FOR VISA - USE DIRECT PRICING (Legacy comment updated)
        $taxAmount = 0;

        // AGENT COMMISSION (docs/MONEY-WALLET-AUDIT.md §C.4c): visa has a fixed
        // customer price (govt + service fee), so we do NOT change what the
        // customer pays. Instead an AGENT earns a commission out of that margin:
        //   - agent b2b markup % of the selling total; OR
        //   - the full service fee if no b2b markup is configured.
        // The commission is capped at the available service-fee margin.
        // NOTE: unlike sell-price markup, the member-tier does NOT reduce this —
        // here b2b% is the agent's REWARD, not a cost, so a tier discount would
        // perversely shrink a higher-tier agent's earning (and could zero it when
        // the tier % exceeds the b2b %). Higher tiers instead earn a small BONUS.
        // Customers earn nothing (agent_earning = 0).
        $visaAgentEarning = 0.0;
        $visaBookerId = (string)($_SESSION['user_id'] ?? '');
        if ($visaBookerId !== '') {
            $vb = $db->get('users', ['role'], ['user_id' => $visaBookerId]);
            $visaIsAgent = (is_array($vb) && strtolower((string)($vb['role'] ?? '')) === 'agent')
                || strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            if ($visaIsAgent) {
                $visaModuleRow = $db->get('modules', ['markup_b2b', 'markup_type_b2b'], ['type' => 'visa']);
                $b2bVal  = (float)($visaModuleRow['markup_b2b'] ?? 0);
                $b2bType = strtolower((string)($visaModuleRow['markup_type_b2b'] ?? 'percentage'));
                if ($b2bVal > 0) {
                    if ($b2bType === 'percentage') {
                        // Tier adds a small earning BONUS (not a reduction).
                        if (!function_exists('agent_tier_discount_percent')) {
                            $walletLib = dirname(__DIR__, 3) . '/lib/wallet.php';
                            if (file_exists($walletLib)) { require_once $walletLib; }
                        }
                        $tierBonus = function_exists('agent_tier_discount_percent')
                            ? (float) agent_tier_discount_percent($db, $visaBookerId) : 0.0;
                        $effPct = $b2bVal + $tierBonus;
                        $visaAgentEarning = round($finalPriceMarkup * $effPct / 100, 2);
                    } else {
                        $visaAgentEarning = round($b2bVal * $travelersCount, 2);
                    }
                } else {
                    // No b2b markup set → agent earns the platform service fee.
                    $visaAgentEarning = round($finalCommission, 2);
                }
                // Never pay out more than the platform's own margin (service fee).
                $visaAgentEarning = max(0.0, min($visaAgentEarning, (float)$finalCommission));
            }
        }

        // GET COUNTRY NAMES
        $fromCountryData = $db->get('countries', 'nicename', ['iso' => $bookingData['from_country']]);
        $toCountryData = $db->get('countries', 'nicename', ['iso' => $bookingData['to_country']]);
        
        // GET VISA TYPE AND PROCESSING SPEED NAMES
        $visaTypeData = $db->get('visa_settings', 'name', ['value' => $bookingData['visa_type'], 'setting_type' => 'visa_type']);
        $processingSpeedData = $db->get('visa_settings', 'name', ['value' => $bookingData['processing_speed'], 'setting_type' => 'processing_speed']);

        // PREPARE BOOKING DATA JSON
        $completeBookingData = [
            'from_country' => $bookingData['from_country'],
            'from_country_name' => $fromCountryData ?? $bookingData['from_country'],
            'to_country' => $bookingData['to_country'],
            'to_country_name' => $toCountryData ?? $bookingData['to_country'],
            'visa_type' => $bookingData['visa_type'],
            'visa_type_name' => $visaTypeData ?? $bookingData['visa_type'],
            'processing_speed' => $bookingData['processing_speed'],
            'processing_speed_name' => $processingSpeedData ?? $bookingData['processing_speed'],
            'entry_date' => $bookingData['entry_date'],
            'travelers_count' => count($travelers),
            'travelers' => $travelers,
            'special_requests' => $specialRequests,
            'price_per_traveler' => $totalPriceDefault, // Store Unit Selling Price in Default Currency
            'currency' => $defaultCurrency,             // Store Default Currency
            'subtotal' => $finalPriceMarkup,            // Subtotal is the total selling price
            'tax_amount' => $taxAmount,
            'total_amount' => $finalPriceMarkup
        ];

        // CREATE USER IF GUEST CHECKOUT
        $userId = null;
        if (!empty($guestDetails['booking_type']) && $guestDetails['booking_type'] === 'guest') {
            // Check if user exists
            $existingUser = $db->get('users', 'user_id', ['email' => $primaryGuest['email']]);
            
            if ($existingUser) {
                $userId = $existingUser;
            } else {
                // Create new user
                $generatedUserId = 'USR' . strtoupper(substr(uniqid(), -8));
                $generatedPassword = bin2hex(random_bytes(8));
                
                $userInsert = $db->insert('users', [
                    'user_id' => $generatedUserId,
                    'first_name' => $primaryGuest['first_name'],
                    'last_name' => $primaryGuest['last_name'],
                    'email' => $primaryGuest['email'],
                    'phone' => $primaryGuest['phone'] ?? '',
                    'phone_country_code' => $primaryGuest['country_code'] ?? '',
                    'password' => password_hash($generatedPassword, PASSWORD_BCRYPT),
                    'status' => 'active',
                    'role' => 'user',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                if ($userInsert) {
                    $userId = $generatedUserId;
                }
            }
        } else {
            // Logged in user
            $userId = $_SESSION['user_id'] ?? null;
        }

        // INSERT BOOKING INTO DATABASE
        $bookingInsert = $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'user_id' => $userId,
            'module' => 'visa',
            'module_type' => 'visa',
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'first_name' => $primaryGuest['first_name'],
            'last_name' => $primaryGuest['last_name'],
            'email' => $primaryGuest['email'],
            'phone' => $primaryGuest['phone'] ?? '',
            'phone_country_code' => $primaryGuest['country_code'] ?? '',
            
            // KEY CURRENCY FIELDS UPDATE
            'currency_markup' => $defaultCurrency,
            
            // price_original = The COST price (Govt Fee / Visa Fee)
            'price_original' => $finalPriceOriginal, 
            
            // price_markup = The SELLING price (Total Price)
            'price_markup' => $finalPriceMarkup, 
            
            // commission = The Profit (Service Fee)
            'commission' => $finalCommission,

            // agent_earning = the agent's share of that margin (0 for customers)
            'agent_earning' => $visaAgentEarning,

            'tax' => $taxAmount,
            'tax_type' => $taxInfo['tax_type'] ?? 'fixed',
            'travellers' => json_encode($travelers),
            'child_ages' => '[]',
            'country' => $primaryGuest['country_code'] ?? '',
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

        // DELETE TEMPORARY BOOKING
        $db->delete('logs_bookings', ['hash' => $bookingHash]);

        // CLEAR SESSION
        unset($_SESSION['booking_data']);
        unset($_SESSION['booking_hash']);

        // GENERATE PDF VOUCHER
        $pdfPath = null;
        try {
            $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        } catch (Exception $e) {
            error_log('Visa PDF Generation failed: ' . $e->getMessage());
        }

        // SEND CONFIRMATION NOTIFICATIONS (Email, WhatsApp, SMS)
        try {
            NOTIFY::booking('visa', [
                'first_name' => $primaryGuest['first_name'],
                'last_name' => $primaryGuest['last_name'],
                'email' => $primaryGuest['email'],
                'phone' => $primaryGuest['phone'] ?? '',
                'country_code' => $primaryGuest['country_code'] ?? ''
            ], array_merge($completeBookingData, [
                'invoice_id' => $invoiceId,
                'booking_status' => 'pending',
                'payment_status' => 'unpaid',
                'amount' => $finalPriceMarkup,
                'currency' => $defaultCurrency
            ]), $pdfPath);
        } catch (Exception $e) {
            // Log error but don't fail booking
            error_log('Visa inquiry notifications failed: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Visa inquiry submitted successfully! Our team will contact you shortly.',
            'invoice_id' => $invoiceId,
            'redirect_url' => root . 'invoice/visa/' . $invoiceId
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'visa',
                'message' => $e->getMessage()
            ],
            'raw_response' => null
        ]);
    }
    exit;
});
