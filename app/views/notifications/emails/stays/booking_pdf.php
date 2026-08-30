<?php
/**
 * ============================================================================
 * BOOKING VOUCHER PDF - Hotel Receipt
 * ============================================================================
 * Professional booking receipt - Agoda/Booking.com style
 * Sleek, lightweight, compact design
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');

$supplierEmail = $bookingData['hotel_email'] ?? ($bookingData['email'] ?? '');
$supplierPhone = $bookingData['hotel_phone_number'] ?? ($bookingData['phone_number'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; font-weight: normal; }

        /* Header */
        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-contact div { margin-bottom: 2px; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        /* Status Badge */
        .status-badge { background: #f0fdf4; border-left: 3px solid #10b981; padding: 8px 12px; margin-bottom: 10px; }
        .status-badge-title { font-size: 9pt; font-weight: bold; color: #065f46; margin-bottom: 1px; }
        .status-badge-text { font-size: 7pt; color: #047857; }

        /* Booking Reference */
        .booking-ref { background: #f7fafc; border: 1px solid #cbd5e0; padding: 8px; text-align: center; margin-bottom: 10px; }
        .booking-ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .booking-ref-number { font-size: 14pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        /* Hotel Card */
        .hotel-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .hotel-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .hotel-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .hotel-address { font-size: 7pt; opacity: 0.9; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }
        .dates-time { font-size: 7pt; color: #4a5568; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

        .room-details { padding: 6px 10px; border-top: 1px solid #e2e8f0; }
        .room-label { font-size: 6pt; color: #718096; margin-bottom: 2px; }
        .room-type { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 2px; }
        .room-board { font-size: 7pt; color: #4a5568; }

        /* Price Summary */
        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
        .price-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 6px; }
        .price-row { margin-bottom: 4px; }
        .price-row table { border: none; }
        .price-row td { padding: 0; border: none; font-size: 7pt; }
        .price-label { color: #4a5568; }
        .price-value { text-align: right; color: #2d3748; font-weight: normal; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 9pt; font-weight: bold; }
        .price-total .price-value { font-size: 11pt; color: #2d3748; font-weight: bold; }

        /* Guest Details */
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .guest-info { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .guest-row { margin-bottom: 3px; font-size: 7pt; }
        .guest-label { color: #718096; display: inline-block; width: 60px; }
        .guest-value { color: #2d3748; font-weight: normal; }

        /* Alert Boxes */
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .info-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .info-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .info-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

        /* Important Info */
        .important-info { background: #f7fafc; padding: 8px 10px; margin-top: 10px; }
        .important-info ul { margin: 4px 0 0 12px; padding: 0; }
        .important-info li { font-size: 6pt; color: #4a5568; margin-bottom: 2px; line-height: 1.3; }

        /* Footer */
        .footer { background: #f7fafc; padding: 8px 10px; margin-top: 10px; border-top: 1px solid #cbd5e0; text-align: center; }
        .footer-contact { font-size: 7pt; color: #4a5568; margin-bottom: 5px; }
        .footer-note { font-size: 6pt; color: #718096; line-height: 1.3; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <table>
            <tr>
                <td style="width: 33%;">
                    <img src="<?= uploads ?>global/logo.png" alt="<?= getBrandName($settings ?? null) ?>" class="logo-img">
                </td>
                <td style="width: 34%;" class="header-contact">
                    <?php if (!empty($settings['contact_email'])): ?>
                        <div><?= htmlspecialchars($settings['contact_email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['contact_phone'])): ?>
                        <div><?= htmlspecialchars($settings['contact_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="width: 33%;" class="header-ref">
                    <div class="ref-label">Invoice ID</div>
                    <div class="ref-number"><?= $invoiceId ?></div>
                    <?php if (!empty($booking['pnr'])): ?>
                        <div class="ref-label" style="margin-top: 5px;">Booking Reference</div>
                        <div class="ref-number" style="font-size: 11pt;"><?= $booking['pnr'] ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Payment Status (Unpaid Alert) -->
    <?php if ($booking['payment_status'] === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ Payment Required</div>
        <div class="alert-text">
            Please complete your payment using <strong><?= htmlspecialchars($booking['payment_gateway']) ?></strong> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Status Badge -->
    <!-- <div class="status-badge">
        <div class="status-badge-title">✓ BOOKING RECEIVED</div>
        <div class="status-badge-text">Your reservation has been received and is being processed</div>
    </div> -->

    <!-- Hotel & Room Information Card -->
    <div class="hotel-card">
        <!-- Hotel Information Section -->
        <div class="hotel-header">
            <div class="hotel-name"><?= htmlspecialchars($bookingData['hotel_name']) ?></div>
            <div class="hotel-address">
                <div>
                    <?= str_repeat('★', $bookingData['hotel_stars'] ?? 3) ?>
                </div>
                <div>
                    Address:
                    <?php
                    $pdfHotelAddress = trim((string) ($bookingData['hotel_address'] ?? ''));
                    if ($pdfHotelAddress === '') {
                        $pdfHotelAddress = trim(implode(', ', array_filter([
                            $bookingData['hotel_street'] ?? $bookingData['address'] ?? '',
                            $bookingData['hotel_postal_code'] ?? '',
                            $bookingData['hotel_city'] ?? $bookingData['city'] ?? '',
                            $bookingData['hotel_country'] ?? $bookingData['country'] ?? '',
                        ], static function ($part) {
                            return $part !== null && trim((string) $part) !== '';
                        })));
                    }
                    ?>
                    <?php if ($pdfHotelAddress !== ''): ?>
                        <?= htmlspecialchars($pdfHotelAddress) ?>
                    <?php endif; ?>
                </div>
                
            </div>
            <?php if (!empty($supplierEmail) || !empty($supplierPhone)): ?>
            <div class="hotel-address" style="margin-top: 3px;">
                <?php if (!empty($supplierEmail)): ?>
                    Email: <?= htmlspecialchars($supplierEmail) ?>
                <?php endif; ?>
                <?php if (!empty($supplierEmail) && !empty($supplierPhone)): ?> | <?php endif; ?>
                <?php if (!empty($supplierPhone)): ?>
                    Phone: <?= htmlspecialchars($supplierPhone) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Dates -->
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Check-in</div>
                    <div class="dates-value"><?= date('D, M d, Y', strtotime($bookingData['checkin'])) ?></div>
                    <div class="dates-time">From 14:00</div>
                </td>
                <td>
                    <div class="dates-label">Check-out</div>
                    <div class="dates-value"><?= date('D, M d, Y', strtotime($bookingData['checkout'])) ?></div>
                    <div class="dates-time">Until 12:00</div>
                </td>
            </tr>
        </table>

        <!-- Stay Info -->
        <div class="stay-info">
            <strong><?= $bookingData['nights'] ?? 1 ?> night<?= ($bookingData['nights'] ?? 1) > 1 ? 's' : '' ?></strong>
            •
            <strong><?= ($booking['adults'] ?? $bookingData['adults'] ?? 1) ?>
                adult<?= (($booking['adults'] ?? $bookingData['adults'] ?? 1) > 1) ? 's' : '' ?></strong><?php if (($booking['childs'] ?? $bookingData['children'] ?? 0) > 0): ?>
                • <strong><?= ($booking['childs'] ?? $bookingData['children'] ?? 0) ?>
                    child<?= (($booking['childs'] ?? $bookingData['children'] ?? 0) > 1) ? 'ren' : '' ?></strong><?php endif; ?>
            <?php if (!empty($booking['pnr'])): ?>
                <div style="margin-top: 5px; color: #2d3748;">
                    <strong>Booking Reference:</strong> <?= $booking['pnr'] ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Room Details Section -->
        <div style="background: #edf2f7; padding: 8px 10px; border-top: 2px solid #cbd5e0; margin-top: 0;">
            <div
                style="font-size: 8pt; font-weight: bold; color: #2d3748; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                Selected Rooms</div>
            <?php if (!empty($bookingData['selected_rooms'])): ?>
                <?php foreach ($bookingData['selected_rooms'] as $room): ?>
                    <div style="margin-bottom: 8px;">
                        <div class="room-type" style="margin-bottom: 1px; font-weight: bold;">
                            <?= htmlspecialchars($room['room_name'] ?? $bookingData['room_type'] ?? 'Standard Room') ?>
                            <span style="font-size: 7pt; font-weight: normal; color: #4a5568;">×<?= $room['quantity'] ?></span>
                        </div>
                        <div class="room-board" style="font-size: 7pt; color: #4a5568;">
                            <?= htmlspecialchars($room['option']['board_name'] ?? $bookingData['board_type'] ?? 'Room Only') ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="room-type" style="margin-bottom: 2px; font-weight: bold;">
                    <?= htmlspecialchars($bookingData['room_type'] ?? 'Standard Room') ?></div>
                <div class="room-board" style="font-size: 7pt; color: #4a5568;">
                    <?= htmlspecialchars($bookingData['board_type'] ?? 'Room Only') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Guest Details -->
    <div class="section-title">Guest Information</div>
    <div class="guest-info">
        <div class="guest-row">
            <span class="guest-label">Name:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Email:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Phone:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Nationality:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['nationality']) ?></span>
        </div>
    </div>

    <!-- Travelers -->
    <?php if (!empty($travellersData['travelers'])): ?>
        <div class="section-title">Traveler Details</div>
        <div class="guest-info">
            <?php
            foreach ($travellersData['travelers'] as $roomKey => $roomTravelers):
                // Extract room number from key (e.g., "room_0" -> 1, "room_1" -> 2)
                $roomNum = ((int) filter_var($roomKey, FILTER_SANITIZE_NUMBER_INT)) + 1;
                $travelerCount = 1; // Reset counter for each room
                if (is_array($roomTravelers)):
                    foreach ($roomTravelers as $travelerType => $traveler):
                        if (is_array($traveler) && !empty($traveler['first_name'])):
                            // Extract traveler type (adult/child)
                            $type = strpos($travelerType, 'adult') !== false ? 'Adult' : 'Child';
                            ?>
                            <div class="guest-row">
                                <span class="guest-label">Room <?= $roomNum ?>                     <?= $travelerCount++ ?>:</span>
                                <span class="guest-value">
                                    <?= htmlspecialchars(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? '')) ?>
                                    <?php if (!empty($traveler['age'])): ?> (Age: <?= htmlspecialchars($traveler['age']) ?>)<?php endif; ?>
                                </span>
                            </div>
                            <?php
                        endif;
                    endforeach;
                endif;
            endforeach;
            ?>
        </div>
    <?php endif; ?>

    <!-- Price Summary -->
    <div class="section-title">Price Summary</div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Subtotal</td>
                    <td class="price-value"><?= $currency ?> <?= $subtotalAmount ?></td>
                </tr>
            </table>
        </div>

        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Taxes & Fees</td>
                    <td class="price-value"><?= $currency ?> <?= number_format((float) ($pdfTaxAmount ?? $booking['tax'] ?? 0), 2) ?></td>
                </tr>
            </table>
        </div>

        <div class="price-total">
            <table>
                <tr>
                    <td class="price-label"><strong>Total Amount</strong></td>
                    <td class="price-value"><?= $currency ?> <?= $totalAmount ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Important hotel notes (Hotelbeds rate comments shown as Important Information) -->
    <?php 
    $importantNotes = [];
    if (isset($booking['pnr']) && !empty($booking['pnr'])) {
        $bookingResp = is_string($booking['booking_response'] ?? null) ? json_decode($booking['booking_response']) : ($booking['booking_response'] ?? null);
        if ($bookingResp && isset($bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments)) {
            $importantNotes[] = $bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments;
        }
    } 
    
    if (empty($importantNotes) && !empty($bookingData['selected_rooms'])) {
        foreach ($bookingData['selected_rooms'] as $room) {
            if (!empty($room['option']['rate_comments'])) {
                $importantNotes[] = $room['option']['rate_comments'];
            }
        }
    }
    $importantNotes = array_unique(array_filter($importantNotes));
    ?>
    <?php if (!empty($importantNotes)): ?>
    <div class="section-title" style="border: none; margin-bottom: 2px;">Important Information</div>
    <div style="padding-bottom: 8px;">
        <?php foreach($importantNotes as $note): ?>
            <div style="margin-bottom: 4px; font-size: 7pt; color: #4a5568;"><?= nl2br(htmlspecialchars($note)) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <!-- Accommodation Type -->
    <?php $acc_type = $bookingData['accommodation_type'] ?? 'Hotel'; ?>
    <div class="section-title" style="border: none; margin-bottom: 2px;">
        <div style="color: #4a5568; margin-bottom: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px;">Accommodation Type: <span style="color: #4a5568; margin-bottom: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px;"><?= htmlspecialchars($acc_type) ?></span></div>
    </div>

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="section-title" style="border: none; margin-bottom: 2px;">Special Requests</div>
    <div class="info-box" style="margin-bottom: 15px;">
        <div class="info-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Cancellation Policy (amounts in user's display currency, e.g. PKR) -->
    <?php
    $cancellationPolicies = [];
    if (!empty($bookingData['cancellation_policy'])) {
        $cancellationPolicies[] = $bookingData['cancellation_policy'];
    }

    // Policy amounts on the draft are in booking/base currency (currency_markup).
    // Convert to the same display currency the guest used (session / booking display).
    $pdfBookingCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? $currency ?? 'USD')));
    $pdfDisplayCurrency = strtoupper(trim((string) (
        $_SESSION['app_currency']
        ?? $bookingData['display_currency']
        ?? $pdfBookingCurrency
    )));
    $pdfPolicyRate = 1.0;
    if (
        $pdfDisplayCurrency !== ''
        && $pdfBookingCurrency !== ''
        && $pdfDisplayCurrency !== $pdfBookingCurrency
        && function_exists('getCurrencyConversionRate')
        && isset($db)
    ) {
        $pdfPolicyRate = (float) getCurrencyConversionRate($db, $pdfBookingCurrency, $pdfDisplayCurrency);
        if ($pdfPolicyRate <= 0) {
            $pdfPolicyRate = 1.0;
            $pdfDisplayCurrency = $pdfBookingCurrency;
        }
    } elseif ($pdfDisplayCurrency === '') {
        $pdfDisplayCurrency = $pdfBookingCurrency;
    }

    if (!empty($bookingData['selected_rooms'])) {
        foreach ($bookingData['selected_rooms'] as $room) {
            $policyList = $room['option']['cancellation_policies'] ?? [];
            $roomPolicyText = '';
            if (!empty($policyList) && is_array($policyList) && function_exists('staysFormatCancellationPolicyText')) {
                $convertedPolicies = [];
                foreach ($policyList as $policyRow) {
                    if (!is_array($policyRow)) {
                        continue;
                    }
                    $convertedPolicies[] = [
                        'amount' => round(((float) ($policyRow['amount'] ?? 0)) * $pdfPolicyRate, 2),
                        'from' => $policyRow['from'] ?? '',
                    ];
                }
                $roomPolicyText = staysFormatCancellationPolicyText($convertedPolicies, $pdfDisplayCurrency);
            }
            if ($roomPolicyText === '' && !empty($room['option']['cancellation_text'])) {
                $roomPolicyText = $room['option']['cancellation_text'];
            }
            if ($roomPolicyText !== '') {
                $cancellationPolicies[] = $roomPolicyText;
            }
        }
    }
    $cancellationPolicies = array_unique(array_filter($cancellationPolicies));
    ?>
    <?php if (!empty($cancellationPolicies)): ?>
    <div class="section-title" style="border: none; margin-bottom: 2px; color: #92400e;">Cancellation Policy</div>
    <div style="background: #fef9c3; border: 1px solid #fef08a; border-radius: 4px; padding: 8px; margin-bottom: 15px;">
        <?php foreach($cancellationPolicies as $policy): ?>
            <div style="font-size: 7pt; color: #92400e; margin-bottom: 3px;"><?= nl2br(htmlspecialchars($policy)) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Excluded Taxes -->
    <?php 
    $excludedTaxes = [];
    if (!empty($bookingData['selected_rooms'])) {
        foreach ($bookingData['selected_rooms'] as $room) {
            if (!empty($room['option']['excluded_taxes'])) {
                foreach ($room['option']['excluded_taxes'] as $tax) {
                    $excludedTaxes[] = $tax;
                }
            }
        }
    }
    ?>
    <?php if (!empty($excludedTaxes)): ?>
    <div class="section-title" style="border: none; margin-bottom: 2px; color: #b91c1c;">Excluded Taxes (Payable at hotel)</div>
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 8px; margin-bottom: 15px;">
        <table style="width: 100%; border: none;">
        <?php foreach($excludedTaxes as $tax): ?>
            <?php
            $taxFromCurrency = strtoupper(trim((string) ($tax['clientCurrency'] ?? $booking['currency_markup'] ?? 'USD')));
            $taxAmountBase = (float) ($tax['clientAmount'] ?? 0);
            $taxRate = 1.0;
            if (
                isset($pdfDisplayCurrency, $pdfDisplayRate)
                && $taxFromCurrency !== ''
                && strtoupper((string) $pdfDisplayCurrency) !== $taxFromCurrency
                && function_exists('getCurrencyConversionRate')
                && isset($db)
            ) {
                $taxRate = (float) getCurrencyConversionRate($db, $taxFromCurrency, $pdfDisplayCurrency);
                if ($taxRate <= 0) {
                    $taxRate = 1.0;
                }
            } elseif (isset($pdfDisplayRate) && $taxFromCurrency === strtoupper((string) ($booking['currency_markup'] ?? ''))) {
                $taxRate = (float) $pdfDisplayRate;
            }
            $taxDisplayCurrency = $pdfDisplayCurrency ?? $taxFromCurrency;
            $taxDisplayAmount = round($taxAmountBase * $taxRate, 2);
            ?>
            <tr>
                <td style="padding: 2px 0; border: none; text-align: left; font-size: 7pt; color: #7f1d1d;"><?= htmlspecialchars($tax['subType'] ?? 'Tax') ?></td>
                <td style="padding: 2px 0; border: none; text-align: right; font-size: 7pt; color: #7f1d1d; font-weight: bold;"><?= htmlspecialchars($taxDisplayCurrency) ?> <?= number_format($taxDisplayAmount, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- Guest checklist -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Guest Checklist</div>
        <ul>
            <li>Please bring your booking reference number and valid ID</li>
            <li>Check-in time is from 14:00 and check-out is until 12:00</li>
            <li>Cancellation policy applies as per hotel terms</li>
            <li>This is your booking voucher - please keep it for your records</li>
            <li>Present this document at the hotel reception upon arrival</li>
        </ul>
    </div>

    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-contact">
            Questions? Contact us at <?= $settings['email_sender_email'] ?? 'support@phptravels.com' ?>
            <?php if (!empty($settings['site_phone'])): ?> | <?= $settings['site_phone'] ?><?php endif; ?>
        </div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= getBrandName($settings ?? null) ?>. All rights reserved.<br>
            Booking Date: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'])) ?><br>
            This is an automated booking voucher. Please do not reply to this document.
        </div>
    </div>
</body>
</html>
