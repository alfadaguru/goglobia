<?php
/**
 * ============================================================================
 * BOOKING VOUCHER PDF - Umrah Receipt
 * ============================================================================
 * Professional booking receipt for Umrah packages
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');

// Prepare Umrah Data
$umrahName = $bookingData['umrah_name'] ?? 'Umrah Package';
$location = $bookingData['umrah_location'] ?? '';
$startDate = $bookingData['start_date'] ?? '';
$formattedDate = !empty($startDate) ? date('D, M d, Y', strtotime($startDate)) : 'N/A';
$duration = $bookingData['duration'] ?? '';
$adults = $booking['adults'] ?? $bookingData['total_adults'] ?? 1;
$children = $booking['childs'] ?? $bookingData['total_children'] ?? 0;

// Flatten travelers for display (skip adult_1 = primary guest)
$flatTravelers = [];
$travelersSource = $travellersData['travelers'] ?? [];
if (!empty($travelersSource) && is_array($travelersSource)) {
    foreach ($travelersSource as $key => $value) {
        if (is_array($value) && !isset($value['first_name'])) {
            foreach ($value as $paxKey => $pax) {
                if ($paxKey === 'adult_1') continue;
                if (is_array($pax) && !empty(trim($pax['first_name'] ?? ''))) {
                    $flatTravelers[] = $pax;
                }
            }
        } elseif ($key !== 'adult_1' && is_array($value) && !empty(trim($value['first_name'] ?? ''))) {
            $flatTravelers[] = $value;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; font-weight: normal; }

        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-contact div { margin-bottom: 2px; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        .service-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .service-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .service-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .service-info { font-size: 7pt; opacity: 0.9; }

        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
        .price-row { margin-bottom: 4px; }
        .price-row table { border: none; }
        .price-row td { padding: 0; border: none; font-size: 7pt; }
        .price-label { color: #4a5568; }
        .price-value { text-align: right; color: #2d3748; font-weight: normal; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 9pt; font-weight: bold; }
        .price-total .price-value { font-size: 11pt; color: #2d3748; font-weight: bold; }

        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .info-block { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .info-row { margin-bottom: 3px; font-size: 7pt; }
        .info-label { color: #718096; display: inline-block; width: 80px; }
        .info-value { color: #2d3748; font-weight: normal; }

        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .note-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .note-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .note-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

        .sub-card { border: 1px solid #e2e8f0; margin-bottom: 6px; padding: 6px 10px; background: #f7fafc; }
        .sub-card-title { font-size: 8pt; font-weight: bold; color: #2d3748; margin-bottom: 4px; }
        .sub-card-row { font-size: 7pt; color: #4a5568; margin-bottom: 2px; }

        .important-info { background: #f7fafc; padding: 8px 10px; margin-top: 10px; }
        .important-info ul { margin: 4px 0 0 12px; padding: 0; }
        .important-info li { font-size: 6pt; color: #4a5568; margin-bottom: 2px; line-height: 1.3; }

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
                    <img src="<?= $_SERVER['DOCUMENT_ROOT'] ?>/v10/uploads/global/logo.png" alt="<?= getBrandName($settings ?? null) ?>" class="logo-img">
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
                    <div class="ref-label">Booking Reference</div>
                    <div class="ref-number"><?= $invoiceId ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Payment Status -->
    <?php if ($booking['payment_status'] === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ Payment Required</div>
        <div class="alert-text">
            Please complete your payment using <strong><?= htmlspecialchars($booking['payment_gateway'] ?? '') ?></strong> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Umrah Package Card -->
    <div class="service-card">
        <div class="service-header">
            <div class="service-name"><?= htmlspecialchars($umrahName) ?></div>
            <div class="service-info"><?= htmlspecialchars($location) ?></div>
        </div>

        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Start Date</div>
                    <div class="dates-value"><?= $formattedDate ?></div>
                </td>
                <td>
                    <div class="dates-label">Duration</div>
                    <div class="dates-value"><?= htmlspecialchars($duration) ?></div>
                </td>
            </tr>
        </table>

        <div class="stay-info">
            <strong><?= $adults ?> Adult<?= $adults > 1 ? 's' : '' ?></strong>
            <?php if ($children > 0): ?> • <strong><?= $children ?> Child<?= $children > 1 ? 'ren' : '' ?></strong><?php endif; ?>
        </div>
    </div>

    <?php
    // ========================================================================
    // FLIGHTS SECTION
    // ========================================================================
    $flights = $bookingData['flights'] ?? [];
    if (!empty($flights) && is_array($flights)):
        $flights = array_filter($flights, function($f) {
            if (!is_array($f)) return false;
            $segs = $f['segments'] ?? [$f];
            foreach ($segs as $s) {
                if (!empty($s['airline']) || !empty($s['flight_no']) || !empty($s['departure_airport'])) return true;
            }
            return false;
        });
    ?>
    <?php if (!empty($flights)): ?>
    <div class="section-title">Flight Details</div>
    <?php foreach ($flights as $flight):
        $segments = $flight['segments'] ?? [$flight];
    ?>
    <div class="sub-card">
        <?php foreach ($segments as $seg): ?>
        <div class="sub-card-row">
            <strong><?= htmlspecialchars($seg['airline'] ?? '') ?></strong> <?= htmlspecialchars($seg['flight_no'] ?? '') ?>
            &nbsp;|&nbsp;
            <?= htmlspecialchars($seg['departure_airport'] ?? '') ?> → <?= htmlspecialchars($seg['arrival_airport'] ?? '') ?>
            &nbsp;|&nbsp;
            <?= htmlspecialchars($seg['departure_date'] ?? '') ?> <?= htmlspecialchars($seg['departure_time'] ?? '') ?>
        </div>
        <?php endforeach; ?>

        <?php
        $returnSegments = $flight['returnSegments'] ?? [];
        if (!empty($returnSegments)):
            foreach ($returnSegments as $retSeg):
        ?>
        <div class="sub-card-row">
            <strong><?= htmlspecialchars($retSeg['airline'] ?? '') ?></strong> <?= htmlspecialchars($retSeg['flight_no'] ?? '') ?>
            &nbsp;|&nbsp;
            <?= htmlspecialchars($retSeg['departure_airport'] ?? '') ?> → <?= htmlspecialchars($retSeg['arrival_airport'] ?? '') ?>
            &nbsp;|&nbsp;
            <?= htmlspecialchars($retSeg['departure_date'] ?? '') ?> <?= htmlspecialchars($retSeg['departure_time'] ?? '') ?>
            (Return)
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    // ========================================================================
    // STAYS / HOTEL SECTION
    // ========================================================================
    $stays = $bookingData['stays'] ?? [];
    if (!empty($stays) && is_array($stays)):
        $stays = array_filter($stays, function($s) {
            return is_array($s) && (!empty($s['hotel_name']) || !empty($s['location']));
        });
    ?>
    <?php if (!empty($stays)): ?>
    <div class="section-title">Accommodation</div>
    <?php foreach ($stays as $stay):
        $stayImg = '';
        if (!empty($stay['images']) && is_array($stay['images'])) {
            $stayImg = $stay['images'][0]['url'] ?? '';
        }
        if (empty($stayImg) && !empty($bookingData['umrah_image'])) {
            $stayImg = $bookingData['umrah_image'];
        }
    ?>
    <div class="service-card" style="margin-bottom: 6px;">
        <table style="border: none;">
            <tr>
                <?php if (!empty($stayImg)): ?>
                <td style="width: 30%; border: none; padding: 0; vertical-align: top;">
                    <img src="<?= htmlspecialchars($stayImg) ?>" alt="<?= htmlspecialchars($stay['hotel_name'] ?? 'Hotel') ?>" style="width: 100%; height: auto; max-height: 120px; object-fit: cover; display: block;" />
                </td>
                <?php endif; ?>
                <td style="border: none; padding: 8px 12px; vertical-align: top;">
                    <div style="font-size: 10pt; font-weight: bold; color: #2d3748; margin-bottom: 3px;">
                        <?= htmlspecialchars($stay['hotel_name'] ?? 'Hotel') ?>
                    </div>
                    <?php if (!empty($stay['location'])): ?>
                    <div style="font-size: 7pt; color: #4a5568; margin-bottom: 3px;">&#128205; <?= htmlspecialchars($stay['location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($stay['stars'])): ?>
                    <div style="font-size: 8pt; color: #f59e0b; margin-bottom: 3px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?><?= $i <= intval($stay['stars']) ? '&#9733;' : '&#9734;' ?><?php endfor; ?>
                        <span style="font-size: 7pt; color: #718096;">(<?= number_format(floatval($stay['stars']), 1) ?>)</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($stay['rooms']) && is_array($stay['rooms'])): ?>
                    <div style="font-size: 7pt; color: #2b6cb0; margin-bottom: 3px;">
                        <?php foreach ($stay['rooms'] as $rIdx => $room): ?>
                            <span style="background: #ebf8ff; padding: 1px 5px; border-radius: 3px; margin-right: 3px;">
                                <?= htmlspecialchars($room['type'] ?? $room['name'] ?? $room['room_type'] ?? 'Room') ?>
                                <?php if (!empty($room['occupancy'] ?? $room['room_occupancy'] ?? '')): ?>
                                    (<?= htmlspecialchars($room['occupancy'] ?? $room['room_occupancy']) ?>)
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($stay['notes'])): ?>
                    <div style="font-size: 6pt; color: #4a5568; font-style: italic; margin-bottom: 3px;">&#9432; <?= htmlspecialchars($stay['notes']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php if (!empty($stay['check_in']) || !empty($stay['check_out'])): ?>
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Check-in</div>
                    <div class="dates-value"><?= htmlspecialchars($stay['check_in'] ?? '---') ?></div>
                </td>
                <td>
                    <div class="dates-label">Check-out</div>
                    <div class="dates-value"><?= htmlspecialchars($stay['check_out'] ?? '---') ?></div>
                </td>
            </tr>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    // ========================================================================
    // TRANSFERS SECTION
    // ========================================================================
    $transfers = $bookingData['transfers'] ?? [];
    if (!empty($transfers) && is_array($transfers)):
        $transfers = array_filter($transfers, function($t) { return is_array($t); });
    ?>
    <?php if (!empty($transfers)): ?>
    <div class="section-title">Transfers</div>
    <?php foreach ($transfers as $transfer):
        $transferImg = '';
        if (!empty($transfer['images']) && is_array($transfer['images'])) {
            $transferImg = $transfer['images'][0]['url'] ?? '';
        }
        $transferDate = $transfer['date'] ?? '';
        $transferDateOnly = '';
        $transferTimeOnly = '';
        if (!empty($transferDate)) {
            if (strpos($transferDate, 'T') !== false) {
                list($transferDateOnly, $transferTimeOnly) = explode('T', $transferDate);
            } else {
                $transferDateOnly = $transferDate;
            }
        }
    ?>
    <div class="service-card" style="margin-bottom: 6px;">
        <table style="border: none;">
            <tr>
                <?php if (!empty($transferImg)): ?>
                <td style="width: 30%; border: none; padding: 0; vertical-align: top;">
                    <img src="<?= htmlspecialchars($transferImg) ?>" alt="<?= htmlspecialchars($transfer['type'] ?? 'Transfer') ?>" style="width: 100%; height: auto; max-height: 120px; object-fit: cover; display: block;" />
                </td>
                <?php endif; ?>
                <td style="border: none; padding: 8px 12px; vertical-align: top;">
                    <div style="font-size: 10pt; font-weight: bold; color: #2d3748; margin-bottom: 2px;">
                        <?= htmlspecialchars($transfer['type'] ?? 'Transfer') ?>
                    </div>
                    <div style="font-size: 6pt; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">Vehicle Service</div>

                    <!-- Pickup / Dropoff -->
                    <div style="margin-bottom: 4px;">
                        <table style="border: none;">
                            <tr>
                                <td style="border: none; padding: 2px 0; width: 45%;">
                                    <span style="font-size: 6pt; color: #718096; text-transform: uppercase;">Pickup</span><br>
                                    <span style="font-size: 7pt; color: #2b6cb0; font-weight: bold;">&#128205; <?= htmlspecialchars($transfer['from'] ?? $transfer['pickup'] ?? '---') ?></span>
                                </td>
                                <td style="border: none; padding: 2px 4px; width: 10%; text-align: center; vertical-align: middle;">
                                    <span style="font-size: 9pt; color: #a0aec0;">&rarr;</span>
                                </td>
                                <td style="border: none; padding: 2px 0; width: 45%;">
                                    <span style="font-size: 6pt; color: #718096; text-transform: uppercase;">Dropoff</span><br>
                                    <span style="font-size: 7pt; color: #2b6cb0; font-weight: bold;">&#128205; <?= htmlspecialchars($transfer['to'] ?? $transfer['dropoff'] ?? '---') ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php if (!empty($transfer['notes'])): ?>
                    <div style="font-size: 6pt; color: #4a5568; font-style: italic; margin-top: 3px;">&#9432; <?= htmlspecialchars($transfer['notes']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php if (!empty($transferDateOnly) || !empty($transferTimeOnly)): ?>
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Date</div>
                    <div class="dates-value"><?= htmlspecialchars($transferDateOnly ?: '---') ?></div>
                </td>
                <td>
                    <div class="dates-label">Time</div>
                    <div class="dates-value"><?= htmlspecialchars($transferTimeOnly ?: '---') ?></div>
                </td>
            </tr>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Lead Guest Information -->
    <div class="section-title">Lead Guest Information</div>
    <div class="info-block">
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Email:</span>
            <span class="info-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Phone:</span>
            <span class="info-value"><?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span>
        </div>
        <?php if (!empty($booking['nationality'])): ?>
        <div class="info-row">
            <span class="info-label">Nationality:</span>
            <span class="info-value"><?= htmlspecialchars($booking['nationality']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Additional Travelers -->
    <?php if (!empty($flatTravelers)): ?>
    <div class="section-title">Additional Travelers</div>
    <div class="info-block">
        <?php foreach ($flatTravelers as $i => $traveler): ?>
        <div class="info-row">
            <span class="info-label">Traveler <?= $i + 1 ?>:</span>
            <span class="info-value">
                <?= htmlspecialchars(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? '')) ?>
            </span>
        </div>
        <?php endforeach; ?>
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
                    <td class="price-value"><?= $currency ?> <?= number_format($booking['tax'], 2) ?></td>
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

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="note-box">
        <div class="note-title">Special Requests</div>
        <div class="note-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Important Information</div>
        <ul>
            <li>Please ensure your passport is valid for at least 6 months</li>
            <li>Present this voucher (digital or printed) at the airport and hotel</li>
            <li>Verify your flight details and arrive at the airport at least 3 hours before departure</li>
            <li>Check hotel check-in and check-out times</li>
            <li>For any emergency, contact our 24/7 support line</li>
        </ul>
    </div>

    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/umrah/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
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
