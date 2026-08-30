<?php
/**
 * NOTIFY Library - Simplified Booking Notifications
 * 
 * Sends notifications directly to customers and admin users
 * without checking database notification preferences.
 * 
 * Usage:
 * NOTIFY::booking($module, $customerData, $bookingData, $pdfPath);
 * NOTIFY::payment($module, $customerData, $bookingData, $pdfPath);
 * NOTIFY::cancellation($module, $customerData, $bookingData);
 */

class NOTIFY {

    /**
     * Outcome of the most recent send()/sendEmailNotifications() call, e.g.
     * ['email' => ['attempted' => true, 'customer_sent' => bool, 'admins_found' => int, 'admins_sent' => int]].
     * Lets callers (webhook handlers) know whether the notification actually
     * went out instead of assuming success just because no exception was thrown.
     */
    private static $lastResult = [];

    /**
     * Result of the most recent notification send, for callers that need to
     * know whether it actually succeeded (e.g. before logging a webhook as
     * successful).
     */
    public static function getLastResult() {
        return self::$lastResult;
    }

    /**
     * Send booking confirmation notification
     *
     * @param string $module Module name (tours, stays, flights, cars)
     * @param array $customerData Customer information (email, phone, first_name, last_name, country_code)
     * @param array $bookingData Booking details (invoice_id, amount, currency, etc.)
     * @param string|null $pdfPath Optional PDF attachment path
     * @return array Result of the send, see getLastResult()
     */
    public static function booking($module, $customerData, $bookingData, $pdfPath = null) {
        return self::send($module, 'booking', $customerData, $bookingData, $pdfPath);
    }
    
    /**
     * Send payment confirmation notification
     * 
     * @param string $module Module name (tours, stays, flights, cars)
     * @param array $customerData Customer information
     * @param array $bookingData Booking details
     * @param string|null $pdfPath Optional PDF attachment path
     */
    public static function payment($module, $customerData, $bookingData, $pdfPath = null) {
        return self::send($module, 'booking_payment', $customerData, $bookingData, $pdfPath);
    }
    
    /**
     * Resend invoice notification (customer only)
     * 
     * @param string $module Module name
     * @param array $customerData Customer information
     * @param array $bookingData Booking details
     * @param string|null $pdfPath Optional PDF attachment path
     */
    public static function resend($module, $customerData, $bookingData, $pdfPath = null) {
        return self::send($module, 'resend', $customerData, $bookingData, $pdfPath);
    }

    /**
     * Send cancellation request notification
     * 
     * @param string $module Module name (tours, stays, flights, cars)
     * @param array $customerData Customer information
     * @param array $bookingData Booking details
     */
    public static function cancellation($module, $customerData, $bookingData) {
        return self::send($module, 'booking_cancellation', $customerData, $bookingData, null);
    }

    /**
     * Send deposit-related notifications (User Deposit Submission, Admin Approval/Rejection)
     * 
     * @param string $actionType 'new_request', 'approved', 'rejected'
     * @param array $depositData Deposit record details
     * @param array $userData User information
     */
    public static function deposit($actionType, $depositData, $userData) {
        global $db;
        
        try {
            // Get settings
            $settings = $db->get('settings', '*');
            
            // Prepare template data
            $amount = $depositData['amount'] ?? 0;
            $currency = $depositData['currency'] ?? 'USD';
            $depositId = $depositData['id'] ?? '';
            $transactionId = $depositData['transaction_id'] ?? '';
            $paymentMethod = $depositData['payment_method'] ?? 'Manual';
            $details = $depositData['details'] ?? '';
            
            $userName = ($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '');
            $userEmail = $userData['email'] ?? '';
            
            $templateData = [
                'SECURE' => true,
                'actionType' => $actionType,
                'amount' => $amount,
                'currency' => $currency,
                'depositId' => $depositId,
                'invoiceId' => $depositId,
                'transactionId' => $transactionId,
                'paymentMethod' => $paymentMethod,
                'details' => $details,
                'userName' => $userName,
                'receiverName' => $userName, // Default receiver is the user
                'root' => root
            ];

            // Determine recipients and subjects
            $recipients = []; // Array of ['email' => '', 'name' => '', 'subject' => '']
            
            // 1. Always notify the specific user who made the deposit
            $userSubject = "";
            if ($actionType === 'new_request') {
                $userSubject = "Deposit Request Received - $depositId";
            } elseif ($actionType === 'approved') {
                $userSubject = "Deposit Approved - $depositId";
            } elseif ($actionType === 'rejected') {
                $userSubject = "Deposit Rejected - $depositId";
            }

            if (!empty($userEmail)) {
                $recipients[] = [
                    'email' => $userEmail,
                    'name' => $userName,
                    'subject' => $userSubject,
                    'receiverName' => $userName,
                    'recipientRole' => 'user'
                ];
            }

            // 2. Always notify all active administrators/superadmins
            $adminSubject = "";
            if ($actionType === 'new_request') {
                $adminSubject = "[ADMIN] New Deposit Request - $depositId from $userName";
            } elseif ($actionType === 'approved') {
                $adminSubject = "[ADMIN] Deposit Approved - $depositId for $userName";
            } elseif ($actionType === 'rejected') {
                $adminSubject = "[ADMIN] Deposit Rejected - $depositId for $userName";
            }

            $admins = $db->select('users', ['email', 'first_name', 'last_name'], [
                'role' => ['admin', 'superadmin'],
                'status' => 'active'
            ]);
            
            foreach ($admins as $admin) {
                // Avoid duplicate email if admin is also the user (rare but possible in dev)
                if ($admin['email'] === $userEmail) continue;

                $recipients[] = [
                    'email' => $admin['email'],
                    'name' => $admin['first_name'] . ' ' . $admin['last_name'],
                    'subject' => $adminSubject,
                    'receiverName' => $admin['first_name'] . ' ' . $admin['last_name'],
                    'recipientRole' => 'admin'
                ];
            }

            // Render Email Body
            $templateFile = __DIR__ . "/../views/notifications/emails/wallet/deposit_notification.php";
            if (!file_exists($templateFile)) {
                error_log("NOTIFY_DEPOSIT_ERROR: Template not found at $templateFile");
                return;
            }

            foreach ($recipients as $recipient) {
                if (empty($recipient['email'])) continue;

                ob_start();
                $SECURE = true;
                // Merge general data with recipient-specific receiverName and role
                $data = $templateData;
                $data['receiverName'] = $recipient['receiverName'];
                $data['recipientRole'] = $recipient['recipientRole'];
                extract($data);
                
                include __DIR__ . '/../views/notifications/emails/header.php';
                include $templateFile;
                include __DIR__ . '/../views/notifications/emails/footer.php';
                $emailBody = ob_get_clean();
                
                $sent = SENDEMAIL($recipient['email'], $recipient['name'], $recipient['subject'], $emailBody);
            }

        } catch (\Throwable $e) {
            error_log("NOTIFY_DEPOSIT_EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }


    
    
    /**
     * Internal method to send notifications
     * Sends to customer and all admin users directly
     */
    private static function send($module, $event, $customerData, $bookingData, $pdfPath = null) {
        global $db;

        self::$lastResult = ['email' => ['attempted' => false, 'customer_sent' => false, 'admins_found' => 0, 'admins_sent' => 0]];

        // Standardize module names for template paths and lookups
        $module = strtolower($module);
        if ($module === 'hotels') { $module = 'stays'; }
        if ($module === 'amadeus') { $module = 'flights'; }
        
        try {
            // 1. PREPARE CUSTOMER DATA
            $customerName = ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '');
            $customerEmail = $customerData['email'] ?? '';
            $customerPhone = $customerData['phone'] ?? '';
            $countryCode = $customerData['country_code'] ?? $customerData['phone_country_code'] ?? '';
            
            // Format phone number
            $numericCode = self::getPhoneCode($countryCode, $db);
            $formattedPhone = self::formatPhoneNumber($customerPhone, $numericCode);
            
            // 2. PREPARE BOOKING DATA
            $invoiceId = $bookingData['invoice_id'] ?? '';
            $amount = $bookingData['amount'] ?? $bookingData['final_total'] ?? 0;
            $currency = $bookingData['currency'] ?? 'USD';
            $moduleType = ucfirst($bookingData['module_type'] ?? $module);

            // Customer's language at the time they booked (persisted on the
            // bookings row) — falls back to English for bookings placed
            // before this column existed, or if not yet resolvable.
            $bookingLanguage = 'en';
            if (!empty($invoiceId)) {
                $storedLanguage = $db->get('bookings', 'language', ['invoice_id' => $invoiceId]);
                if (!empty($storedLanguage)) {
                    $bookingLanguage = $storedLanguage;
                }
            }
            
            // Initialize all potential template variables with null defaults
            $templateVariables = [
                'hotel_name', 'hotelName', 'hotel_address', 'hotelAddress', 'hotel_stars', 'hotelStars',
                'tour_name', 'tourName', 'tour_location', 'tourLocation', 'duration',
                'car_name', 'carName', 'location', 'pickup_date',
                'airline_name', 'airlineName', 'flight_number', 'flightNumber', 'from', 'to',
                'room_type', 'roomType', 'nights', 'nightsCount', 'departure_date', 'checkin', 'checkOut', 'startDate',
                'from_country', 'to_country', 'from_country_name', 'to_country_name', 'visa_type', 'visa_type_name', 'processing_speed', 'processing_speed_name', 'entry_date',
                'adults', 'children', 'infants', 'special_requests', 'specialRequests', 'supportPhone'
            ];
            $templateData = array_fill_keys($templateVariables, null);
            
            // Get settings
            $settings = $db->get('settings', '*');
            
            // Prepare template data by merging defaults with actual data
            $templateData = array_merge($templateData, $customerData, $bookingData);
            $templateData['settings'] = $settings; // Ensure settings are available in templates
            $templateData['language'] = $bookingLanguage;
            $companyName = $settings['business_name'] ?? 'PHPTRAVELS';
            $templateData['companyName'] = $companyName;
            $templateData['invoiceUrl'] = root . 'invoice/' . $module . '/' . $invoiceId;
            $templateData['supportEmail'] = $settings['contact_email'] ?? 'support@phptravels.com';
            $templateData['supportPhone'] = $settings['contact_phone'] ?? '';
            $templateData['customerName'] = $customerName;
            $templateData['firstName'] = $customerData['first_name'] ?? '';
            $templateData['lastName'] = $customerData['last_name'] ?? '';
            $templateData['finalTotal'] = $amount;
            $templateData['amount'] = $amount;
            $templateData['currency'] = $currency;
            $templateData['moduleType'] = $templateData['module_type'] = $moduleType;
            $templateData['invoiceId'] = $templateData['invoice_id'] = $invoiceId;
            
            // ============================================================
            // 11. STANDARDIZED VARIABLE MAPPING (for template compatibility)
            // ============================================================
            $templateData['paymentStatus'] = $templateData['payment_status'] ?? 'pending';
            $templateData['finalTotal'] = $templateData['amount'] ?? $amount;
            $templateData['firstName'] = $templateData['first_name'] ?? '';
            $templateData['lastName'] = $templateData['last_name'] ?? '';
            $templateData['customerEmail'] = $customerEmail;
            $templateData['customerPhone'] = $formattedPhone;
            $templateData['pdfPath'] = $pdfPath;
            $templateData['bookingDate'] = $templateData['booking_date'] ?? date('Y-m-d H:i:s');
            $templateData['moduleType'] = $moduleType;

            // AUTO-RESOLVE OWNER ID IF MISSING
            if (empty($templateData['user_id'])) {
                try {
                    $bData = json_decode($db->get('bookings', 'booking_data', ['invoice_id' => $invoiceId]) ?? '{}', true);
                    $itemId = $bData['hotel_id'] ?? $bData['tour_id'] ?? $bData['flight_id'] ?? $bData['car_id'] ?? $bData['id'] ?? $bData['car_data']['id'] ?? $bData['car_data']['car_id'] ?? $bData['car_data']['supplier_id'] ?? 0;

                    
                    // DEEPER RESOLUTION FOR FLIGHTS
                    if ($module === 'flights' && $itemId === 0) {
                        $itemId = $bData['flight_data']['booking_data']['flight_id'] ?? $bData['flight_data']['id'] ?? $bData['flight_data']['flight_id'] ?? 0;
                    }


                    if ($itemId > 0) {
                        $table = ($module === 'stays') ? 'stays' : (($module === 'tours') ? 'tours' : (($module === 'flights') ? 'flights' : (($module === 'cars') ? 'cars' : '')));
                        if (!empty($table)) {
                            $item = $db->get($table, ['user_id'], ['id' => $itemId]);
                            if ($item && !empty($item['user_id'])) {
                                $templateData['user_id'] = $item['user_id'];
                            } else {
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("NOTIFY_OWNER_RESOLUTION_ERROR: " . $e->getMessage());
                }
            } else {
                error_log("DEBUG_NOTIFY [Invoice: $invoiceId]: user_id already providing: " . $templateData['user_id']);
            }

            // Module-specific data mapping
            if ($module === 'stays' || $module === 'stay' || $module === 'hotels') {
                $hName = $bookingData['hotel_name'] ?? $bookingData['hotelName'] ?? '';
                $templateData['hotel_name'] = $templateData['hotelName'] = $hName;
                
                $hAddr = $bookingData['hotel_address'] ?? $bookingData['hotelAddress'] ?? '';
                $templateData['hotel_address'] = $templateData['hotelAddress'] = $hAddr;
                
                $hStars = $bookingData['hotel_stars'] ?? $bookingData['hotelStars'] ?? 3;
                $templateData['hotel_stars'] = $templateData['hotelStars'] = $hStars;
                
                $rooms = $bookingData['selected_rooms'] ?? $bookingData['selectedRooms'] ?? [];
                $templateData['selected_rooms'] = $templateData['selectedRooms'] = $rooms;
                
                $rType = $bookingData['room_type'] ?? $bookingData['roomType'] ?? 'Standard Room';
                $templateData['room_type'] = $templateData['roomType'] = $rType;
                
                $bType = $bookingData['board_type'] ?? $bookingData['boardType'] ?? 'Room Only';
                $templateData['board_type'] = $templateData['boardType'] = $bType;
                
                $sReq = $bookingData['special_requests'] ?? $bookingData['specialRequests'] ?? '';
                $templateData['special_requests'] = $templateData['specialRequests'] = $sReq;
            } elseif ($module === 'tours' || $module === 'tour') {
                $tName = $bookingData['tour_name'] ?? $bookingData['tourName'] ?? '';
                $templateData['tour_name'] = $templateData['tourName'] = $tName;
                
                $tLoc = $bookingData['tour_location'] ?? $bookingData['tourLocation'] ?? $bookingData['location'] ?? '';
                $templateData['tour_location'] = $templateData['tourLocation'] = $templateData['location'] = $tLoc;
                
                $sDate = $bookingData['start_date'] ?? $bookingData['startDate'] ?? '';
                $templateData['start_date'] = $templateData['startDate'] = $sDate;
                
                $duration = $bookingData['duration'] ?? ($bookingData['days'] ?? 1) . ' Days / ' . ($bookingData['nights'] ?? 0) . ' Nights';
                $templateData['duration'] = $duration;

                $sReq = $bookingData['special_requests'] ?? $bookingData['specialRequests'] ?? '';
                $templateData['special_requests'] = $templateData['specialRequests'] = $sReq;

                // Pass inclusions/highlights
                $templateData['inclusions'] = $bookingData['inclusions'] ?? [];
                $templateData['highlights'] = $bookingData['highlights'] ?? [];
            } elseif ($module === 'flights') {
                // Flights specific data mapping
                $flightData = $bookingData['flight_data'] ?? [];
                if (is_string($flightData)) $flightData = json_decode($flightData, true);

                $from = $bookingData['from'] ?? $flightData['from'] ?? '';
                $to = $bookingData['to'] ?? $flightData['to'] ?? '';
                $templateData['from'] = $from;
                $templateData['to'] = $to;
                
                $depDate = $bookingData['departure_date'] ?? $bookingData['date'] ?? $flightData['departure_date'] ?? '';
                $templateData['departure_date'] = $depDate;

                $flightNumber = $bookingData['flight_number'] ?? $flightData['flight_number'] ?? '';
                $airlineName = $bookingData['airline_name'] ?? $flightData['airline_name'] ?? '';
                $templateData['flight_number'] = $templateData['flightNumber'] = $flightNumber;
                $templateData['airline_name'] = $templateData['airlineName'] = $airlineName;
                
                // If booking_data exists in the data, preserve it
                if (isset($bookingData['booking_data'])) {
                    $templateData['booking_data'] = $bookingData['booking_data'];
                }
            } elseif ($module === 'cars') {
                $cName = $bookingData['car_name'] ?? $bookingData['carName'] ?? $bookingData['name'] ?? '';
                $templateData['car_name'] = $templateData['carName'] = $cName;
                $templateData['location'] = $bookingData['location'] ?? $bookingData['pickup_location'] ?? '';
                $templateData['pickup_date'] = $bookingData['pickup_date'] ?? $bookingData['checkin'] ?? '';
                $templateData['return_date'] = $bookingData['return_date'] ?? $bookingData['checkout'] ?? '';
            } elseif ($module === 'visa') {
                $templateData['from_country_name'] = $bookingData['from_country_name'] ?? $bookingData['from_country'] ?? '';
                $templateData['to_country_name'] = $bookingData['to_country_name'] ?? $bookingData['to_country'] ?? '';
                $templateData['visa_type_name'] = $bookingData['visa_type_name'] ?? $bookingData['visa_type'] ?? '';
                $templateData['processing_speed_name'] = $bookingData['processing_speed_name'] ?? $bookingData['processing_speed'] ?? '';
                $templateData['entry_date'] = $bookingData['entry_date'] ?? '';
            }
            
            // Global aliases & naming standardization
            $pStatus = $bookingData['payment_status'] ?? $bookingData['paymentStatus'] ?? 'unpaid';
            $templateData['payment_status'] = $templateData['paymentStatus'] = $pStatus;
            
            $pMethod = $bookingData['payment_method'] ?? $bookingData['paymentMethod'] ?? 'N/A';
            $templateData['payment_method'] = $templateData['paymentMethod'] = $pMethod;

            // Ensure common date/pax variables are available globally for mobile templates
            $templateData['checkin'] = $templateData['checkIn'] = $templateData['checkin'] ?? $templateData['start_date'] ?? $templateData['departure_date'] ?? '';
            $templateData['checkout'] = $templateData['checkOut'] = $templateData['checkout'] ?? '';
            $templateData['nights'] = $templateData['nights_count'] = $templateData['nights'] ?? 0;
            $templateData['adults'] = $templateData['adults_count'] = $templateData['adults'] ?? 0;
            $templateData['children'] = $templateData['childs'] = $templateData['children'] = $templateData['children'] ?? 0;
            
            if (!isset($templateData['discount'])) $templateData['discount'] = 0;
            if (!isset($templateData['tax'])) $templateData['tax'] = $bookingData['tax_amount'] ?? $bookingData['tax'] ?? 0;
            if (!isset($templateData['subtotal'])) $templateData['subtotal'] = $bookingData['subtotal'] ?? ($amount - ($templateData['tax'] ?? 0));
            
            $bDate = $bookingData['booking_date'] ?? $bookingData['bookingDate'] ?? date('Y-m-d H:i:s');
            $templateData['booking_date'] = $templateData['bookingDate'] = $bDate;
            
            // 3. CHECK NOTIFICATION PREFERENCES
            $emailConfig = json_decode($settings['email_providers_config'] ?? '{}', true);
            $whatsappConfig = json_decode($settings['whatsapp_providers_config'] ?? '{}', true);
            $smsConfig = json_decode($settings['sms_providers_config'] ?? '{}', true);

            $enableWhatsApp = $whatsappConfig['booking_enabled'] ?? true;
            $enableSms = $smsConfig['booking_enabled'] ?? true;

            // 4. SEND NOTIFICATIONS WITH INDIVIDUAL ERROR HANDLING
            
            // Email
            try {
                self::$lastResult['email'] = self::sendEmailNotifications($module, $event, $customerName, $customerEmail, $templateData, $invoiceId, $moduleType, $pdfPath, $db);
            } catch (\Throwable $e) {
                error_log("NOTIFY EMAIL ERROR: " . $e->getMessage());
            }
            
            // WhatsApp (Skip if resend event)
            if ($enableWhatsApp && $event !== 'resend') {
                try {
                    self::sendWhatsAppNotifications($module, $event, $customerName, $formattedPhone, $templateData, $invoiceId, $moduleType, $amount, $currency, $settings, $db);
                } catch (\Throwable $e) {
                    error_log("NOTIFY WHATSAPP ERROR: " . $e->getMessage());
                }
            }
            
            // SMS (Skip if resend event)
            if ($enableSms && $event !== 'resend') {
                try {
                    self::sendSmsNotifications($module, $event, $customerName, $formattedPhone, $templateData, $invoiceId, $moduleType, $amount, $currency, $settings, $db);
                } catch (\Throwable $e) {
                    error_log("NOTIFY SMS ERROR: " . $e->getMessage());
                }
            }
            
        } catch (\Throwable $e) {
            error_log("NOTIFY EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        }

        return self::$lastResult;
    }

    /**
     * Send email notifications to customer and admins
     *
     * @return array ['attempted' => bool, 'customer_sent' => bool, 'admins_found' => int, 'admins_sent' => int]
     */
    private static function sendEmailNotifications($module, $event, $customerName, $customerEmail, $templateData, $invoiceId, $moduleType, $pdfPath, $db) {
        $result = ['attempted' => true, 'customer_sent' => false, 'admins_found' => 0, 'admins_sent' => 0];

        // Prepare email subject — translated into the customer's booking-time
        // language (see NOTIFY::send()); falls back to English automatically
        // if that language or key isn't available (see translateTo()).
        $lang = $templateData['language'] ?? 'en';
        $subject = "$moduleType " . translateTo('notification', $lang) . " - #$invoiceId";
        if ($event === 'booking') {
            $subject = translateTo('booking_received', $lang) . " - #$invoiceId";
        } elseif ($event === 'booking_payment') {
            $subject = translateTo('booking_confirmed', $lang) . " - #$invoiceId";
        } elseif ($event === 'booking_cancellation') {
            $subject = translateTo('cancellation_request', $lang) . " - #$invoiceId";
        } elseif ($event === 'resend') {
            $subject = translateTo('resend_invoice', $lang) . " - #$invoiceId";
        }
        
        // Determine template path (Unified Structure)
        $templateFile = __DIR__ . "/../views/notifications/emails/$module/$event.php";
        
        // Consolidate 'booking', 'booking_payment', and 'resend' into 'booking.php'
        // This ensures all modules (including 3rd party) use the professional standardized template
        if ($event === 'booking' || $event === 'booking_payment' || $event === 'resend') {
            $templateFile = __DIR__ . "/../views/notifications/emails/$module/booking.php";
        }
        
        if (file_exists($templateFile)) {
            ob_start();
            $SECURE = true;
            
            // Extract data for template
            $booking = $templateData;
            extract($templateData);
            
            include __DIR__ . '/../views/notifications/emails/header.php';
            include $templateFile;
            include __DIR__ . '/../views/notifications/emails/footer.php';
            $emailBody = ob_get_clean();

            
            // 1. Send to Customer
            try {
                if (!empty($customerEmail)) {
                    $sent = SENDEMAIL($customerEmail, $customerName, $subject, $emailBody, $pdfPath);
                    if ($sent) {
                        $result['customer_sent'] = true;
                    } else {
                        error_log("NOTIFY_ERROR: Failed to send email to Customer: $customerEmail (Subject: $subject)");
                    }
                } else {
                }
            } catch (\Throwable $e) {
                error_log("NOTIFY_EXCEPTION (Customer): " . $e->getMessage());
            }

            // IF RESEND, DO NOT SEND TO OWNER OR ADMIN
            if ($event === 'resend') {
                return $result;
            }
            
            // 2. Send to Service Owner (user who created the tour/hotel/flight)
            try {
                $serviceOwnerId = $templateData['user_id'] ?? null;
                if (!empty($serviceOwnerId)) {
                    $serviceOwner = $db->get('users', ['email', 'first_name', 'last_name', 'status'], [
                        'user_id' => $serviceOwnerId
                    ]);
                    
                    if ($serviceOwner && !empty($serviceOwner['email'])) {
                        $status = strtolower($serviceOwner['status'] ?? '');
                        if ($status == 'active' || $status == '1') {
                            $prefix = ($event === 'booking_cancellation') ? "[SUPPLIER] Cancellation" : "[SUPPLIER] New Booking";
                            $ownerSubject = "$prefix - " . $subject;
                            $sent = SENDEMAIL($serviceOwner['email'], $serviceOwner['first_name'] . ' ' . $serviceOwner['last_name'], $ownerSubject, $emailBody, $pdfPath);
                            if ($sent) {
                            } else {
                            }
                        } else {
                        }
                    } else {
                    }
                } else {
                }
            } catch (\Throwable $e) {
                error_log("NOTIFY_EXCEPTION (Owner): " . $e->getMessage());
            }
            
            // 3. Send booking notification to admin(s). If a dedicated
            // "Booking Notification Email" is configured (Settings > Notifications),
            // use that single address; otherwise fall back to every admin/superadmin
            // user account (the pre-existing behavior).
            try {
                $bookingNotificationEmail = trim((string)($templateData['settings']['booking_notification_email'] ?? ''));
                $prefix = ($event === 'booking_cancellation') ? "[ADMIN] Cancellation" : "[ADMIN] New Booking";
                $adminSubject = "$prefix - " . $subject;

                if ($bookingNotificationEmail !== '') {
                    $result['admins_found'] = 1;
                    $sent = SENDEMAIL($bookingNotificationEmail, 'Booking Notifications', $adminSubject, $emailBody, $pdfPath);
                    if ($sent) {
                        $result['admins_sent'] = 1;
                    } else {
                        error_log("NOTIFY_ERROR: Failed to send email to configured Booking Notification Email: $bookingNotificationEmail");
                    }
                } else {
                    $admins = $db->select('users', ['email', 'first_name', 'last_name', 'role', 'status'], [
                        'role' => ['admin', 'superadmin']
                    ]);

                    $adminsFound = 0;
                    $adminsSent = 0;
                    foreach ($admins as $admin) {
                        try {
                            $status = strtolower($admin['status'] ?? '');
                            if ($status == 'active' || $status == '1') {
                                if (!empty($admin['email'])) {
                                    $adminsFound++;
                                    $sent = SENDEMAIL($admin['email'], $admin['first_name'] . ' ' . $admin['last_name'], $adminSubject, $emailBody, $pdfPath);
                                    if ($sent) {
                                        $adminsSent++;
                                    } else {
                                        error_log("NOTIFY_ERROR: Failed to send email to Admin ({$admin['role']}): " . $admin['email']);
                                    }
                                }
                            }
                        } catch (\Throwable $adminEx) {
                            error_log("NOTIFY_EXCEPTION (Single Admin): " . $adminEx->getMessage());
                        }
                    }
                    $result['admins_found'] = $adminsFound;
                    $result['admins_sent'] = $adminsSent;
                    error_log("NOTIFY_INFO: Email notification attempted for $adminsFound active admins.");
                }
            } catch (\Throwable $e) {
                error_log("NOTIFY_EXCEPTION (Admins Block): " . $e->getMessage());
            }
        } else {
            error_log("NOTIFY ERROR: Email template not found at $templateFile");
            $result['attempted'] = false;
        }

        return $result;
    }
    
    /**
     * Send WhatsApp notifications to customer and admins
     */
    private static function sendWhatsAppNotifications($module, $event, $customerName, $formattedPhone, $templateData, $invoiceId, $moduleType, $amount, $currency, $settings, $db) {
        // Load professional message from template file
        $msg = self::loadMessageTemplate($module, $event, 'whatsapp', $templateData);
        
        if (empty($msg)) {
            // Fallback to legacy construction if file missing
            $msg = "Hello $customerName, your $moduleType booking #$invoiceId status is " . ($templateData['payment_status'] ?? 'pending') . ". View: " . root . "invoice/$module/$invoiceId";
        }
        
        // Send to Customer
        if (!empty($formattedPhone)) {
            SENDWHATSAPP($formattedPhone, $customerName, $msg);
        }
        
        // Send to Service Owner (user who created the tour/hotel/flight)
        $serviceOwnerId = $templateData['user_id'] ?? null;
        if (!empty($serviceOwnerId)) {
            $serviceOwner = $db->get('users', ['phone', 'phone_country_code', 'first_name', 'last_name', 'status'], [
                'user_id' => $serviceOwnerId
            ]);
            
            if ($serviceOwner && !empty($serviceOwner['phone'])) {
                $ownerNumeric = self::getPhoneCode($serviceOwner['phone_country_code'] ?? '', $db);
                $ownerPhone = self::formatPhoneNumber($serviceOwner['phone'], $ownerNumeric);
                $eventLabel = ($event === 'booking_cancellation') ? "Cancellation Request" : "New Booking";
                $ownerMsg = "[SUPPLIER] $eventLabel for $moduleType #$invoiceId\n\n$msg";
                
                SENDWHATSAPP($ownerPhone, $serviceOwner['first_name'], $ownerMsg);
            }
        }
        
        // Send to ALL Admin Users
        $admins = $db->select('users', ['phone', 'phone_country_code', 'first_name', 'last_name'], [
            'role' => ['admin', 'superadmin'],
            'status' => 'active'
        ]);
        
        foreach ($admins as $admin) {
            if (empty($admin['phone'])) continue;
            
            $adminNumeric = self::getPhoneCode($admin['phone_country_code'] ?? '', $db);
            $adminPhone = self::formatPhoneNumber($admin['phone'], $adminNumeric);
            $eventLabel = ($event === 'booking_cancellation') ? "Cancellation Request" : "New Booking";
            $adminMsg = "[ADMIN] $eventLabel for $moduleType #$invoiceId\n\n$msg";
            
            SENDWHATSAPP($adminPhone, $admin['first_name'], $adminMsg);
        }
    }
    
    /**
     * Send SMS notifications to customer and admins
     */
    private static function sendSmsNotifications($module, $event, $customerName, $formattedPhone, $templateData, $invoiceId, $moduleType, $amount, $currency, $settings, $db) {
        // Load professional message from template file
        $msg = self::loadMessageTemplate($module, $event, 'sms', $templateData);
        
        if (empty($msg)) {
            // Fallback to legacy construction
            $msg = "Hello $customerName, booking #$invoiceId confirmed. Amount: $currency $amount. " . root . "invoice/$module/$invoiceId";
        }
        
        // Send to Customer
        if (!empty($formattedPhone)) {
            SENDSMS($formattedPhone, $customerName, $msg);
        }
        
        // Send to Service Owner (user who created the tour/hotel/flight)
        $serviceOwnerId = $templateData['user_id'] ?? null;
        if (!empty($serviceOwnerId)) {
            $serviceOwner = $db->get('users', ['phone', 'phone_country_code', 'first_name', 'last_name', 'status'], [
                'user_id' => $serviceOwnerId
            ]);
            
            if ($serviceOwner && !empty($serviceOwner['phone'])) {
                $ownerNumeric = self::getPhoneCode($serviceOwner['phone_country_code'] ?? '', $db);
                $ownerPhone = self::formatPhoneNumber($serviceOwner['phone'], $ownerNumeric);
                $eventLabel = ($event === 'booking_cancellation') ? "Cancellation Request" : "New Booking";
                $ownerMsg = "[SUPPLIER] $eventLabel for $moduleType #$invoiceId\n\n$msg";
                
                SENDSMS($ownerPhone, $serviceOwner['first_name'], $ownerMsg);
            }
        }
        
        // Send to ALL Admin Users
        $admins = $db->select('users', ['phone', 'phone_country_code', 'first_name', 'last_name'], [
            'role' => ['admin', 'superadmin'],
            'status' => 'active'
        ]);
        
        foreach ($admins as $admin) {
            if (empty($admin['phone'])) continue;
            
            $adminNumeric = self::getPhoneCode($admin['phone_country_code'] ?? '', $db);
            $adminPhone = self::formatPhoneNumber($admin['phone'], $adminNumeric);
            $eventLabel = ($event === 'booking_cancellation') ? "Cancellation Request" : "New Booking";
            $adminMsg = "[ADMIN] $eventLabel for $moduleType #$invoiceId\n\n$msg";
            
            SENDSMS($adminPhone, $admin['first_name'], $adminMsg);
        }
    }
    
    /**
     * Get phone code from ISO or numeric code
     */
    private static function getPhoneCode($isoOrCode, $db) {
        if (empty($isoOrCode)) return '';
        
        // Clean to see if it's numeric
        $clean = preg_replace('/[^\d]/', '', $isoOrCode);
        
        // If it's exactly 2 letters, it's likely an ISO code
        if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
            $country = $db->get('countries', ['phonecode'], ['iso' => strtoupper($isoOrCode)]);
            return preg_replace('/[^\d]/', '', $country['phonecode'] ?? '');
        }
        
        // If it's already numeric, return the cleaned version
        if (!empty($clean)) {
            return $clean;
        }
        
        return '';
    }
    
    /**
     * Format phone number with country code
     */
    private static function formatPhoneNumber($phone, $countryCode) {
        $phone = preg_replace('/[^\d]/', '', $phone);
        $countryCode = preg_replace('/[^\d]/', '', $countryCode);
        $phone = ltrim($phone, '0');
        
        if (strpos($phone, $countryCode) === 0) {
            return $phone;
        }
        
        return $countryCode . $phone;
    }

    /**
     * Send notification to a specific vendor/owner
     * 
     * @param string $module Module name
     * @param array $vendorData Vendor information (email, phone, name)
     * @param array $bookingData Booking details
     */
    public static function vendor($module, $vendorData, $bookingData) {
        self::sendVendor($module, 'booking', $vendorData, $bookingData);
    }
    
    /**
     * Send payment notification to a specific vendor/owner
     * 
     * @param string $module Module name
     * @param array $vendorData Vendor information
     * @param array $bookingData Booking details
     */
    public static function vendorPayment($module, $vendorData, $bookingData) {
        self::sendVendor($module, 'booking_payment', $vendorData, $bookingData);
    }
    
    
    /**
     * Internal method to send vendor notifications
     * Sends ONLY to the specified vendor/owner
     */
    private static function sendVendor($module, $event, $vendorData, $bookingData) {
        global $db;
        
        try {
            // 1. PREPARE VENDOR DATA
            $vendorName = ($vendorData['first_name'] ?? '') . ' ' . ($vendorData['last_name'] ?? '');
            $vendorEmail = $vendorData['email'] ?? '';
            $vendorPhone = $vendorData['phone'] ?? '';
            $countryCode = $vendorData['country_code'] ?? $vendorData['phone_country_code'] ?? '';
            
            // Format phone number
            $numericCode = self::getPhoneCode($countryCode, $db);
            $formattedPhone = self::formatPhoneNumber($vendorPhone, $numericCode);
            
            // 2. PREPARE BOOKING DATA
            $invoiceId = $bookingData['invoice_id'] ?? '';
            $amount = $bookingData['amount'] ?? 0;
            $currency = $bookingData['currency'] ?? 'USD';
            $moduleType = ucfirst($bookingData['module_type'] ?? $module);
            
            // Get settings
            $settings = $db->get('settings', '*');
            
            // Prepare template data (Same as customer)
            $templateData = array_merge($vendorData, $bookingData);
            $templateData['invoiceUrl'] = root . 'invoice/' . $module . '/' . $invoiceId;
            $templateData['companyName'] = $settings['business_name'] ?? 'PHPTRAVELS';
            $templateData['supportEmail'] = $settings['contact_email'] ?? 'support@phptravels.com';
            $templateData['supportPhone'] = $settings['contact_phone'] ?? '';
            $templateData['customerName'] = $vendorName; // Addressee is vendor
            $templateData['firstName'] = $vendorData['first_name'] ?? '';
            $templateData['lastName'] = $vendorData['last_name'] ?? '';
            $templateData['finalTotal'] = $amount;
            $templateData['invoiceId'] = $invoiceId;
            
            // Module mapping (Copied from send method)
            if ($module === 'stays') {
                $hName = $bookingData['hotel_name'] ?? $bookingData['hotelName'] ?? '';
                $templateData['hotel_name'] = $templateData['hotelName'] = $hName;
                $templateData['hotel_address'] = $bookingData['hotel_address'] ?? $bookingData['hotelAddress'] ?? '';
                $templateData['hotel_stars'] = $bookingData['hotel_stars'] ?? $bookingData['hotelStars'] ?? 3;
                $templateData['selected_rooms'] = $bookingData['selected_rooms'] ?? $bookingData['selectedRooms'] ?? [];
                $templateData['room_type'] = $bookingData['room_type'] ?? $bookingData['roomType'] ?? 'Standard Room';
                $templateData['board_type'] = $bookingData['board_type'] ?? $bookingData['boardType'] ?? 'Room Only';
                $templateData['special_requests'] = $bookingData['special_requests'] ?? $bookingData['specialRequests'] ?? '';
            } elseif ($module === 'flights') {
                $templateData['from'] = $bookingData['from'] ?? '';
                $templateData['to'] = $bookingData['to'] ?? '';
                $templateData['departure_date'] = $bookingData['departure_date'] ?? $bookingData['date'] ?? '';
                $templateData['flight_number'] = $bookingData['flight_number'] ?? '';
                $templateData['airline_name'] = $bookingData['airline_name'] ?? '';
            }
            
            // Global aliases (Fix for undefined variables)
            $pStatus = $bookingData['payment_status'] ?? $bookingData['paymentStatus'] ?? 'unpaid';
            $templateData['payment_status'] = $templateData['paymentStatus'] = $pStatus;
            
            $pMethod = $bookingData['payment_method'] ?? $bookingData['paymentMethod'] ?? 'N/A';
            $templateData['payment_method'] = $templateData['paymentMethod'] = $pMethod;

            // Common data
            $templateData['booking_date'] = $bookingData['booking_date'] ?? date('Y-m-d H:i:s');
            
            // Fix: Add defaults for template variables
            if (!isset($templateData['discount'])) $templateData['discount'] = 0;
            if (!isset($templateData['tax'])) $templateData['tax'] = $bookingData['tax_amount'] ?? $bookingData['tax'] ?? 0;
            if (!isset($templateData['subtotal'])) $templateData['subtotal'] = $bookingData['subtotal'] ?? ($amount - ($templateData['tax'] ?? 0));
            
            // 3. CHECK NOTIFICATION PREFERENCES
            $emailConfig = json_decode($settings['email_providers_config'] ?? '{}', true);
            $whatsappConfig = json_decode($settings['whatsapp_providers_config'] ?? '{}', true);
            $smsConfig = json_decode($settings['sms_providers_config'] ?? '{}', true);

            $enableWhatsApp = $whatsappConfig['booking_enabled'] ?? true;
            $enableSms = $smsConfig['booking_enabled'] ?? true;

            // 4. SEND EMAIL
            // Prepare email subject
            $subject = "New $moduleType Booking - #$invoiceId";
            if ($event === 'booking_payment') {
                $subject = "Payment Confirmed - #$invoiceId";
            }
            
            // Use same template as customer for now, but addressed to vendor
            // Determine template path (New structure)
            $templateFile = __DIR__ . "/../views/notifications/emails/$module/$event.php";
            
            // Force booking.php for primary vendor notification events as well
            if ($event === 'booking' || $event === 'booking_payment') {
                $templateFile = __DIR__ . "/../views/notifications/emails/$module/booking.php";
            }
            
            if (file_exists($templateFile)) {
                ob_start();
                $SECURE = true;
                $booking = $templateData;
                extract($templateData);
                include __DIR__ . '/../views/notifications/emails/header.php';
                include $templateFile;
                include __DIR__ . '/../views/notifications/emails/footer.php';
                $emailBody = ob_get_clean();
                
                // Send to Vendor ONLY
                if (!empty($vendorEmail)) {
                    SENDEMAIL($vendorEmail, $vendorName, $subject, $emailBody, null);
                }
            }
            
            // 5. SEND WHATSAPP/SMS
            // Message construction from professional templates
            $msg_whatsapp = self::loadMessageTemplate($module, $event, 'whatsapp', $templateData);
            if (empty($msg_whatsapp)) {
                 $msg_whatsapp = "Hello $vendorName, new $moduleType booking #$invoiceId received. Amount: $currency " . number_format($amount, 2);
            }
            
            $msg_sms = self::loadMessageTemplate($module, $event, 'sms', $templateData);
            if (empty($msg_sms)) {
                 $msg_sms = "Hello $vendorName, new $moduleType booking #$invoiceId. Amount: $currency $amount";
            }
            
            if (!empty($formattedPhone)) {
                if ($enableWhatsApp) {
                    SENDWHATSAPP($formattedPhone, $vendorName, $msg_whatsapp);
                }
                if ($enableSms) {
                    SENDSMS($formattedPhone, $vendorName, $msg_sms);
                }
            }
            
        } catch (\Throwable $e) {
            error_log("NOTIFY VENDOR EXCEPTION: " . $e->getMessage());
        }
    }

    /**
     * Load and process message template from file
     */
    private static function loadMessageTemplate($module, $event, $type, $data) {
        $file = __DIR__ . "/../views/notifications/$type/$event.php";
        
        // Consolidate 'booking' and 'booking_payment' into 'booking.php'
        if ($event === 'booking' || $event === 'booking_payment') {
            $file = __DIR__ . "/../views/notifications/$type/booking.php";
        }

        if (file_exists($file)) {
            ob_start();
            extract($data);
            include $file;
            return ob_get_clean();
        }

        return '';
    }
}