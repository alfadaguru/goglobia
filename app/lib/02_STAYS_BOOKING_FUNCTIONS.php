<?php
// ============================================================================
// FILE: app/lib/stays_booking_functions.php
// WHITE-LABEL BOOKING INTEGRATION FUNCTIONS
// ============================================================================
// Complete library of functions for white-label booking lifecycle:
// - Creation (guest submits form)
// - Payment processing (webhook → revenue → payout)
// - Status transitions (pending → confirmed → checked-in → cancelled)
// - Audit logging and notifications
//
// All functions are defensive (validate input, handle errors, log failures).
// Functions return arrays with ['ok' => bool, 'message' => string, 'data' => mixed].
// ============================================================================

// ============================================================================
// UTILITY: Generate booking reference (guest-facing)
// ============================================================================
if (!function_exists('stays_booking_generate_reference')) {
    /**
     * Generate a unique guest-facing booking reference.
     * Format: BK-{8-character uniqid}
     * Example: BK-a1b2c3d4
     */
    function stays_booking_generate_reference(): string
    {
        return 'BK-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}

// ============================================================================
// UTILITY: Get current IP address (for audit logging)
// ============================================================================
if (!function_exists('stays_booking_get_ip')) {
    function stays_booking_get_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
}

// ============================================================================
// UTILITY: Log booking audit event
// ============================================================================
if (!function_exists('stays_booking_audit_log')) {
    /**
     * Log an audit event for a white-label booking.
     *
     * @param object $db Database connection
     * @param int $staysBookingId stays_bookings.id
     * @param string $action Action name (created, payment_received, etc.)
     * @param string|null $actor Actor type (guest, admin, system)
     * @param string|null $actorId Actor identifier (user_id, email)
     * @param array|null $details JSON-serializable details
     * @return bool Success
     */
    function stays_booking_audit_log(
        $db,
        int $staysBookingId,
        string $action,
        ?string $actor = 'system',
        ?string $actorId = null,
        ?array $details = null
    ): bool {
        try {
            $ip = stays_booking_get_ip();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $db->insert('stays_booking_audit_log', [
                'stays_booking_id' => $staysBookingId,
                'action' => substr($action, 0, 100),
                'actor' => substr($actor ?? 'system', 0, 50),
                'actor_id' => substr($actorId ?? '', 0, 155),
                'details' => $details ? json_encode($details) : null,
                'ip_address' => $ip,
                'user_agent' => substr($ua, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            error_log('stays_booking_audit_log: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// CORE: Create a white-label booking (guest submits form)
// ============================================================================
if (!function_exists('stays_booking_create')) {
    /**
     * Create a new white-label booking from guest submission.
     *
     * Input fields (validated):
     * - org_id: supplier organization ID
     * - stay_id: hotel/property ID
     * - room_id: selected room
     * - option_id: room rate/option
     * - check_in_date: YYYY-MM-DD
     * - check_out_date: YYYY-MM-DD
     * - guest_email: email
     * - guest_phone: optional
     * - guest_name_first: first name
     * - guest_name_last: last name
     * - guest_special_requests: optional
     * - guest_country: optional
     * - guest_address: optional
     * - guests_count: int
     * - total_price: decimal
     * - currency: char(3)
     *
     * @param object $db Database connection
     * @param array $input Guest-submitted booking data
     * @return array ['ok' => bool, 'message' => string, 'data' => stays_booking_id or null]
     */
    function stays_booking_create($db, array $input): array
    {
        try {
            // Validate required fields
            $required = ['org_id', 'stay_id', 'room_id', 'check_in_date', 'check_out_date',
                        'guest_email', 'guest_name_first', 'total_price', 'currency'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return ['ok' => false, 'message' => "Missing required field: $field"];
                }
            }

            $orgId = (int) $input['org_id'];
            $stayId = (int) $input['stay_id'];
            $roomId = (int) $input['room_id'];
            $optionId = isset($input['option_id']) ? (int) $input['option_id'] : null;

            // Validate dates
            $checkIn = $input['check_in_date'];
            $checkOut = $input['check_out_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
                return ['ok' => false, 'message' => 'Invalid date format (use YYYY-MM-DD)'];
            }

            $checkInTs = strtotime($checkIn);
            $checkOutTs = strtotime($checkOut);
            if (!$checkInTs || !$checkOutTs || $checkInTs >= $checkOutTs) {
                return ['ok' => false, 'message' => 'Check-out must be after check-in'];
            }

            $nights = (int) (($checkOutTs - $checkInTs) / 86400);
            if ($nights <= 0 || $nights > 365) {
                return ['ok' => false, 'message' => 'Stay must be 1-365 nights'];
            }

            // Validate email
            $email = trim((string) $input['guest_email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'message' => 'Invalid email address'];
            }

            // Validate price
            $price = (float) $input['total_price'];
            if ($price <= 0) {
                return ['ok' => false, 'message' => 'Total price must be positive'];
            }

            // Generate reference
            $reference = stays_booking_generate_reference();
            while ($db->has('stays_bookings', ['reference' => $reference])) {
                $reference = stays_booking_generate_reference();
            }

            // Prepare insert data
            $insertData = [
                'org_id' => $orgId,
                'stay_id' => $stayId,
                'room_id' => $roomId,
                'option_id' => $optionId,
                'reference' => $reference,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'number_of_nights' => $nights,
                'guest_email' => substr($email, 0, 191),
                'guest_phone' => substr(trim((string) ($input['guest_phone'] ?? '')), 0, 40),
                'guest_name_first' => substr(trim((string) $input['guest_name_first']), 0, 100),
                'guest_name_last' => substr(trim((string) ($input['guest_name_last'] ?? '')), 0, 100),
                'guest_special_requests' => substr((string) ($input['guest_special_requests'] ?? ''), 0, 1000),
                'guest_country' => substr((string) ($input['guest_country'] ?? ''), 0, 100),
                'guest_address' => substr((string) ($input['guest_address'] ?? ''), 0, 255),
                'guests_count' => max(1, (int) ($input['guests_count'] ?? 1)),
                'total_price' => round($price, 2),
                'currency' => strtoupper(substr((string) $input['currency'], 0, 3)),
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            // Insert into database
            $id = $db->insert('stays_bookings', $insertData);
            if (!$id) {
                throw new Exception('Failed to insert booking record');
            }

            // Log audit event
            stays_booking_audit_log($db, (int) $id, 'created', 'guest', $email, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_price' => $price,
                'nights' => $nights,
            ]);

            return [
                'ok' => true,
                'message' => 'Booking created successfully',
                'data' => [
                    'stays_booking_id' => (int) $id,
                    'reference' => $reference,
                    'email' => $email,
                ]
            ];

        } catch (\Throwable $e) {
            error_log('stays_booking_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error creating booking: ' . $e->getMessage()];
        }
    }
}

// ============================================================================
// INTEGRATION: Calculate revenue split after payment
// ============================================================================
if (!function_exists('stays_booking_calculate_revenue')) {
    /**
     * Calculate revenue split for a booking after payment is confirmed.
     *
     * Flow:
     * 1. Receive gross amount (what guest paid)
     * 2. Deduct platform fee (2.5% by default)
     * 3. Deduct payment processor fee (Paystack ~2%, Stripe ~2.9%+0.30, etc)
     * 4. Calculate net (what supplier gets paid)
     * 5. Store in booking_revenue
     * 6. Create supplier payout request
     *
     * @param object $db Database connection
     * @param int $bookingId Main bookings.id
     * @param float $grossAmount Amount charged to guest
     * @param string $currency Currency code
     * @param string $paymentMethod Payment gateway (paystack|stripe|adyen)
     * @param string|null $gatewayTransactionId External transaction ID
     * @return array ['ok' => bool, 'message' => str, 'data' => revenue record or null]
     */
    function stays_booking_calculate_revenue(
        $db,
        int $bookingId,
        float $grossAmount,
        string $currency = 'USD',
        string $paymentMethod = 'paystack',
        ?string $gatewayTransactionId = null
    ): array {
        try {
            // Fetch booking to get supplier org_id
            $booking = $db->get('bookings', ['id', 'supplier_org_id', 'email', 'price_original'],
                ['id' => $bookingId]);
            if (!$booking) {
                return ['ok' => false, 'message' => 'Booking not found'];
            }

            $orgId = (int) ($booking['supplier_org_id'] ?? 0);
            if ($orgId <= 0) {
                return ['ok' => false, 'message' => 'Supplier org_id not set on booking'];
            }

            // Check if revenue already recorded (idempotency)
            $existing = $db->get('booking_revenue', ['id'], ['booking_id' => $bookingId]);
            if ($existing) {
                return ['ok' => true, 'message' => 'Revenue already calculated (idempotent)',
                        'data' => (int) $existing['id']];
            }

            // Calculate fees
            $platformFeePct = 2.50;  // TODO: Make configurable per org
            $processorFeePct = 2.00; // TODO: Make configurable per gateway

            $platformFeeAmount = round($grossAmount * ($platformFeePct / 100), 2);
            $processorFeeAmount = round($grossAmount * ($processorFeePct / 100), 2);
            $netAmount = round($grossAmount - $platformFeeAmount - $processorFeeAmount, 2);

            // Ensure net doesn't go negative (shouldn't happen with reasonable fees)
            if ($netAmount < 0) {
                return ['ok' => false, 'message' => 'Calculated net amount is negative (check fee percentages)'];
            }

            // Insert revenue record
            $revenueId = $db->insert('booking_revenue', [
                'booking_id' => $bookingId,
                'org_id' => $orgId,
                'gross_amount' => round($grossAmount, 2),
                'currency' => strtoupper(substr($currency, 0, 3)),
                'platform_fee_pct' => $platformFeePct,
                'platform_fee_amount' => $platformFeeAmount,
                'processor_fee_pct' => $processorFeePct,
                'processor_fee_amount' => $processorFeeAmount,
                'net_amount' => $netAmount,
                'status' => 'calculated',
                'payment_method' => substr($paymentMethod, 0, 50),
                'payment_gateway_id' => substr($gatewayTransactionId ?? '', 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$revenueId) {
                throw new Exception('Failed to insert revenue record');
            }

            return [
                'ok' => true,
                'message' => 'Revenue calculated successfully',
                'data' => [
                    'revenue_id' => (int) $revenueId,
                    'gross' => round($grossAmount, 2),
                    'platform_fee' => $platformFeeAmount,
                    'processor_fee' => $processorFeeAmount,
                    'net' => $netAmount,
                    'currency' => strtoupper(substr($currency, 0, 3)),
                ]
            ];

        } catch (\Throwable $e) {
            error_log('stays_booking_calculate_revenue: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error calculating revenue: ' . $e->getMessage()];
        }
    }
}

// ============================================================================
// INTEGRATION: Create supplier payout request
// ============================================================================
if (!function_exists('stays_booking_schedule_payout')) {
    /**
     * Create a supplier payout request after revenue is calculated.
     *
     * This function:
     * 1. Fetches the booking_revenue record
     * 2. Gets the supplier user's bank details
     * 3. Creates a supplier_payouts record (status = 'requested')
     * 4. Stores payout_id back in booking_revenue
     * 5. Returns the payout ID
     *
     * @param object $db Database connection
     * @param int $revenueId booking_revenue.id
     * @return array ['ok' => bool, 'message' => str, 'data' => payout_id or null]
     */
    function stays_booking_schedule_payout($db, int $revenueId): array
    {
        try {
            // Fetch revenue record
            $revenue = $db->get('booking_revenue', '*', ['id' => $revenueId]);
            if (!$revenue) {
                return ['ok' => false, 'message' => 'Revenue record not found'];
            }

            $orgId = (int) $revenue['org_id'];
            $netAmount = (float) $revenue['net_amount'];
            $currency = (string) $revenue['currency'];

            // Get organization owner (supplier)
            $org = $db->get('organizations', ['owner_user_id'], ['id' => $orgId]);
            if (!$org) {
                return ['ok' => false, 'message' => 'Organization not found'];
            }

            $ownerUserId = (string) $org['owner_user_id'];
            if (!$ownerUserId) {
                return ['ok' => false, 'message' => 'Organization has no owner'];
            }

            // Fetch supplier's bank details
            $user = $db->get('users', 
                ['payout_bank_code', 'payout_account_number', 'payout_account_name', 'payout_recipient_code'],
                ['user_id' => $ownerUserId]
            );

            if (!$user) {
                return ['ok' => false, 'message' => 'Supplier user not found'];
            }

            // Check if supplier has bank details
            if (!$user['payout_bank_code'] || !$user['payout_account_number']) {
                return ['ok' => false, 
                        'message' => 'Supplier has not configured bank details for payouts'];
            }

            // Generate unique payout reference
            $reference = 'SP-' . bin2hex(random_bytes(6));
            while ($db->has('supplier_payouts', ['reference' => $reference])) {
                $reference = 'SP-' . bin2hex(random_bytes(6));
            }

            // Create payout request (will be approved by admin later)
            $payoutId = $db->insert('supplier_payouts', [
                'org_id' => $orgId,
                'owner_user_id' => $ownerUserId,
                'currency' => $currency,
                'amount' => round($netAmount, 2),
                'state' => 'requested',
                'bank_code' => $user['payout_bank_code'],
                'account_number' => $user['payout_account_number'],
                'account_name' => $user['payout_account_name'],
                'recipient_code' => $user['payout_recipient_code'],
                'reference' => $reference,
                'requested_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$payoutId) {
                throw new Exception('Failed to create payout record');
            }

            // Update revenue record with payout_id
            $db->update('booking_revenue', 
                ['scheduled_payout_id' => (int) $payoutId, 'status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s')],
                ['id' => $revenueId]
            );

            return [
                'ok' => true,
                'message' => 'Payout scheduled successfully',
                'data' => [
                    'payout_id' => (int) $payoutId,
                    'reference' => $reference,
                    'amount' => round($netAmount, 2),
                    'status' => 'requested',
                ]
            ];

        } catch (\Throwable $e) {
            error_log('stays_booking_schedule_payout: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error scheduling payout: ' . $e->getMessage()];
        }
    }
}

// ============================================================================
// QUERY: Get booking by reference (for guest retrieval)
// ============================================================================
if (!function_exists('stays_booking_get_by_reference')) {
    /**
     * Fetch a white-label booking by its guest-facing reference.
     * Used when guest wants to check their booking status.
     *
     * @param object $db Database connection
     * @param string $reference Booking reference (BK-xxxxx)
     * @return array|null Booking record or null
     */
    function stays_booking_get_by_reference($db, string $reference): ?array
    {
        try {
            $ref = substr(trim($reference), 0, 100);
            $booking = $db->get('stays_bookings', '*', ['reference' => $ref]);
            return $booking ?: null;
        } catch (\Throwable $e) {
            error_log('stays_booking_get_by_reference: ' . $e->getMessage());
            return null;
        }
    }
}

// ============================================================================
// QUERY: Get bookings by supplier
// ============================================================================
if (!function_exists('stays_booking_list_by_org')) {
    /**
     * List all white-label bookings for a supplier (pagination support).
     *
     * @param object $db Database connection
     * @param int $orgId Organization ID
     * @param array $filters Optional: ['status' => 'pending', 'email' => '...', 'stay_id' => ...]
     * @param int $page Pagination page (1-based)
     * @param int $perPage Results per page
     * @return array ['ok' => bool, 'message' => str, 'data' => records or null, 'pagination' => {...}]
     */
    function stays_booking_list_by_org($db, int $orgId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        try {
            $where = ['org_id' => $orgId];

            // Apply filters
            if (!empty($filters['status'])) {
                $where['status'] = $filters['status'];
            }
            if (!empty($filters['email'])) {
                $where['guest_email[~]'] = '%' . $filters['email'] . '%';
            }
            if (!empty($filters['stay_id'])) {
                $where['stay_id'] = (int) $filters['stay_id'];
            }

            // Count total
            $total = $db->count('stays_bookings', $where);

            // Fetch paginated results
            $limit = max(1, (int) $perPage);
            $offset = max(0, ($page - 1) * $limit);

            $bookings = $db->select('stays_bookings', '*', [
                ...​$where,
                'ORDER' => ['created_at' => 'DESC'],
                'LIMIT' => [$offset, $limit],
            ]) ?: [];

            $totalPages = ceil($total / $limit);

            return [
                'ok' => true,
                'message' => 'Bookings retrieved successfully',
                'data' => $bookings,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $limit,
                    'total_pages' => $totalPages,
                ]
            ];

        } catch (\Throwable $e) {
            error_log('stays_booking_list_by_org: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error fetching bookings'];
        }
    }
}

// ============================================================================
// STATUS: Update booking status
// ============================================================================
if (!function_exists('stays_booking_update_status')) {
    /**
     * Update booking status and log the change.
     *
     * Valid transitions:
     * - pending → confirmed (when payment received)
     * - confirmed → checked_in
     * - checked_in → checked_out
     * - any → cancelled
     *
     * @param object $db Database connection
     * @param int $staysBookingId stays_bookings.id
     * @param string $newStatus New status
     * @param string|null $actor Actor performing change
     * @param string|null $reason Change reason
     * @return array ['ok' => bool, 'message' => str]
     */
    function stays_booking_update_status(
        $db,
        int $staysBookingId,
        string $newStatus,
        ?string $actor = 'system',
        ?string $reason = null
    ): array {
        try {
            $validStatuses = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];
            $newStatus = strtolower(trim($newStatus));

            if (!in_array($newStatus, $validStatuses)) {
                return ['ok' => false, 'message' => "Invalid status: $newStatus"];
            }

            $booking = $db->get('stays_bookings', ['status'], ['id' => $staysBookingId]);
            if (!$booking) {
                return ['ok' => false, 'message' => 'Booking not found'];
            }

            // Update based on status
            $updateData = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];

            if ($newStatus === 'confirmed') {
                $updateData['booking_confirmed_at'] = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'checked_in') {
                $updateData['checked_in_at'] = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'checked_out') {
                $updateData['checked_out_at'] = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'cancelled') {
                $updateData['cancelled_at'] = date('Y-m-d H:i:s');
                $updateData['cancellation_reason'] = substr($reason ?? '', 0, 255);
            }

            $db->update('stays_bookings', $updateData, ['id' => $staysBookingId]);

            // Log audit event
            stays_booking_audit_log($db, $staysBookingId, "status_changed_to_$newStatus", $actor, null, [
                'from_status' => $booking['status'],
                'to_status' => $newStatus,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'message' => "Status updated to $newStatus"];

        } catch (\Throwable $e) {
            error_log('stays_booking_update_status: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error updating status'];
        }
    }
}

// ============================================================================
// END OF FILE
// ============================================================================

