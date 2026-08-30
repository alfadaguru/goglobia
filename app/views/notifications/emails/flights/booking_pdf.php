<?php
/**
 * ============================================================================
 * FLIGHT BOOKING VOUCHER PDF - Flight Ticket Receipt
 * ============================================================================
 * Professional booking receipt for Flights
 * Standardized design across all modules
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');

// Prepare Flight Data
$fd = $bookingData['flight_data'] ?? [];
$routes = $fd['routes'] ?? [];
$segments = !empty($routes) ? ($routes[0]['segments'] ?? []) : [];

// Robust extraction for Header title
if (!empty($segments)) {
    $first = $segments[0];
    $last = $segments[count($segments) - 1];
    $fromTitle = $first['from_airport'] ?? 'N/A';
    $toTitle = $last['to_airport'] ?? 'N/A';
    $formattedDate = date('D, M d, Y', strtotime($first['departure_datetime']));
    $airlineInfo = ($first['airline_name'] ?? 'Airline') . ' (' . ($first['flight_number'] ?? 'N/A') . ')';
} else {
    // Simple structure fallback
    $fromTitle = $fd['departure_code'] ?? $fd['departure_airport'] ?? 'N/A';
    $toTitle = $fd['arrival_code'] ?? $fd['arrival_airport'] ?? 'N/A';
    $formattedDate = !empty($fd['departure_date']) ? date('D, M d, Y', strtotime($fd['departure_date'])) : 'N/A';
    $airlineInfo = ($fd['airline'] ?? 'Airline') . ' (' . ($fd['flight_no'] ?? 'N/A') . ')';
}

$allSegments = [];
if (!empty($fd['segments']) && is_array($fd['segments'])) {
    $allSegments = array_merge($allSegments, $fd['segments']);
}
if (!empty($fd['returnSegments']) && is_array($fd['returnSegments'])) {
    $allSegments = array_merge($allSegments, $fd['returnSegments']);
}

$bookingDate = date('M d, Y', strtotime($booking['booking_date']));

// Extract Ancillary Baggage - Handle multiple data formats
$ancillaryBaggage = [];
if (!empty($bookingData['baggage']) && is_array($bookingData['baggage'])) {
    // Check if it's already an array of baggage objects
    if (isset($bookingData['baggage'][0]) && is_array($bookingData['baggage'][0]) && isset($bookingData['baggage'][0]['passengerId'])) {
        $ancillaryBaggage = $bookingData['baggage'];
    }
}
// Fallback to ancillary_data if available
if (empty($ancillaryBaggage) && !empty($bookingData['ancillary_data']['baggage'])) {
    $tempBags = $bookingData['ancillary_data']['baggage'];
    if (is_array($tempBags)) {
        // Convert object to array if needed
        $ancillaryBaggage = array_values($tempBags);
    }
}

// Extract Ancillary Seats - Handle multiple data formats
$ancillarySeats = [];
if (!empty($bookingData['seat']) && is_array($bookingData['seat'])) {
    $ancillarySeats = $bookingData['seat'];
} elseif (!empty($bookingData['ancillary_data']['seats'])) {
    $ancillarySeats = $bookingData['ancillary_data']['seats'];
}
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

        /* Card */
        .service-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .service-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .service-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .service-info { font-size: 7pt; opacity: 0.9; }

        /* Flight Segments */
        .segment { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
        .segment:last-child { border-bottom: none; }
        .segment-header { margin-bottom: 6px; }
        .airline-label { font-size: 8pt; color: #2d3748; font-weight: bold; }
        .flight-num { font-size: 7pt; color: #4a5568; margin-left: 5px; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }
        
        .flight-times td { width: 45%; padding: 0; border: none; vertical-align: top; }
        .arrow-col { width: 10%; text-align: center; padding-top: 10px; color: #cbd5e0; font-size: 12pt; }
        .time-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .time-value { font-size: 11pt; font-weight: bold; color: #2d3748; margin-bottom: 1px; }
        .apt-code { font-size: 8pt; color: #4a5568; margin-bottom: 1px; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

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

        /* Details */
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .info-block { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .info-row { margin-bottom: 3px; font-size: 7pt; }
        .info-label { color: #718096; display: inline-block; width: 100px; }
        .info-value { color: #2d3748; font-weight: normal; }

        /* Alert Boxes */
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .note-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .note-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .note-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

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
                    <?php if (!empty($businessEmail)): ?>
                        <div><?= htmlspecialchars($businessEmail) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($businessPhone)): ?>
                        <div><?= htmlspecialchars($businessPhone) ?></div>
                    <?php endif; ?>
                </td>
                <td style="width: 33%;" class="header-ref">
                    <div class="ref-label"><?= htmlspecialchars(translateTo('booking_reference', $language)) ?></div>
                    <div class="ref-number"><?= $invoiceId ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Payment Status (Unpaid Alert) -->
    <?php if ($booking['payment_status'] === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ <?= htmlspecialchars(translateTo('payment_required', $language)) ?></div>
        <div class="alert-text">
            <?= translateTo('flights_complete_payment_using', $language, ['%gateway%' => '<strong>' . htmlspecialchars($booking['payment_gateway']) . '</strong>']) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Flight Information Card -->
    <div class="service-card">
        <div class="service-header">
            <div class="service-name"><?= htmlspecialchars($fromTitle) ?> → <?= htmlspecialchars($toTitle) ?></div>
            <div class="service-info"><?= $airlineInfo ?></div>
        </div>

        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label"><?= htmlspecialchars(translateTo('departure_date', $language)) ?></div>
                    <div class="dates-value"><?= $formattedDate ?></div>
                </td>
                <td>
                    <div class="dates-label"><?= htmlspecialchars(translateTo('booking_id', $language)) ?></div>
                    <div class="dates-value">#<?= $invoiceId ?></div>
                </td>
            </tr>
        </table>

        <!-- Flight Detail Segments (if available) -->
        <?php foreach ($segments as $seg): 
            $depTime = date('H:i', strtotime($seg['departure_datetime']));
            $arrTime = date('H:i', strtotime($seg['arrival_datetime']));
        ?>
        <div class="segment">
            <div class="segment-header">
                <span class="airline-label"><?= htmlspecialchars($seg['airline_name']) ?></span>
                <span class="flight-num">| Flight <?= htmlspecialchars($seg['flight_number']) ?></span>
            </div>

            <table class="flight-times">
                <tr>
                    <td>
                        <div class="time-label"><?= htmlspecialchars(translateTo('departure', $language)) ?></div>
                        <div class="time-value"><?= $depTime ?></div>
                        <div class="apt-code"><?= htmlspecialchars($seg['from_airport']) ?></div>
                    </td>
                    <td class="arrow-col">→</td>
                    <td style="text-align: right;">
                        <div class="time-label"><?= htmlspecialchars(translateTo('arrival', $language)) ?></div>
                        <div class="time-value"><?= $arrTime ?></div>
                        <div class="apt-code"><?= htmlspecialchars($seg['to_airport']) ?></div>
                    </td>
                </tr>
            </table>
        </div>
        <?php endforeach; ?>

        <div class="stay-info">
            <strong><?= ($booking['adults'] ?? 1) ?> <?= htmlspecialchars(translateTo(($booking['adults'] ?? 1) > 1 ? 'adults' : 'adult', $language)) ?></strong>
            <?php if (($booking['childs'] ?? 0) > 0): ?> • <strong><?= $booking['childs'] ?> <?= htmlspecialchars(translateTo($booking['childs'] > 1 ? 'children' : 'child', $language)) ?></strong><?php endif; ?>
            <?php if (($booking['infants'] ?? 0) > 0): ?> • <strong><?= $booking['infants'] ?> <?= htmlspecialchars(translateTo($booking['infants'] > 1 ? 'infants' : 'infant', $language)) ?></strong><?php endif; ?>
        </div>
    </div>

    <!-- Passenger Details -->
    <div class="section-title"><?= htmlspecialchars(translateTo('passenger_details', $language)) ?> &amp; <?= htmlspecialchars(translateTo('ancillaries', $language)) ?></div>
    <div class="info-block">
        <?php 
        $passengers = $travellersData['passengers'] ?? $travellersData ?? [];
        if (!empty($passengers) && is_array($passengers)): 
            foreach ($passengers as $key => $p):
                if (!is_array($p)) continue;
                $pName = ($p['title'] ?? '') . ' ' . ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '');
                $pType = 'Adult';
                if (strpos($key, 'child_') === 0) $pType = 'Child';
                if (strpos($key, 'infant_') === 0) $pType = 'Infant';
        ?>
        <div class="info-row">
            <span class="info-label"><?= $pType ?>:</span>
            <span class="info-value"><?= htmlspecialchars(trim($pName)) ?></span>
        </div>
        
        <?php 
        $pId = $p['id'] ?? null; 
        if ($pId):
            // Find baggage for this passenger
            $pBaggage = [];
            if (!empty($ancillaryBaggage) && is_array($ancillaryBaggage)) {
                foreach ($ancillaryBaggage as $bag) {
                    if (isset($bag['passengerId']) && $bag['passengerId'] === $pId) {
                        $pBaggage[] = $bag;
                    }
                }
            }
            
            // Find seats for this passenger
            $pSeats = [];
            if (!empty($ancillarySeats) && is_array($ancillarySeats)) {
                foreach ($ancillarySeats as $sIdx => $seats) {
                    if (is_array($seats) && isset($seats[$pId])) {
                        $segmentInfo = $allSegments[$sIdx] ?? null;
                        $flightDesc = "Seg " . ($sIdx + 1);
                        if ($segmentInfo) {
                            $destination = $segmentInfo['arrival_code'] ?? '';
                            if ($destination) {
                                $flightDesc = "Flight to " . $destination;
                            }
                        }
                        $pSeats[] = "Seat " . htmlspecialchars($seats[$pId]['designator'] ?? '') . " (" . htmlspecialchars($flightDesc) . ")";
                    }
                }
            }

            // Display ancillaries if any exist
            if (!empty($pBaggage) || !empty($pSeats)):
        ?>
        <div style="margin-bottom: 5px;">
            <?php if (!empty($pSeats)): ?>
            <div style="font-size: 6.5pt; color: #1e40af; line-height: 1.2;">
                ✓ <strong>Seats:</strong> <?= implode(', ', $pSeats) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($pBaggage)): ?>
            <?php foreach ($pBaggage as $bag): ?>
            <div style="font-size: 6.5pt; color: #047857; line-height: 1.2;">
                ✓ <strong>Baggage:</strong> <?= (int)($bag['quantity'] ?? 1) ?>x <?= htmlspecialchars($bag['name'] ?? 'Extra Bag') ?> <?= !empty($bag['description']) ? '(' . htmlspecialchars($bag['description']) . ')' : '' ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php 
            endif;
        endif; 
        ?>

        <?php endforeach; endif; ?>
    </div>

    <!-- Contact Information -->
    <div class="section-title"><?= htmlspecialchars(translateTo('contact_information', $language)) ?></div>
    <div class="info-block">
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars(translateTo('primary_guest', $language)) ?>:</span>
            <span class="info-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars(translateTo('email', $language)) ?>:</span>
            <span class="info-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars(translateTo('phone', $language)) ?>:</span>
            <span class="info-value">+<?= htmlspecialchars($booking['phone_country_code']) ?> <?= htmlspecialchars($booking['phone']) ?></span>
        </div>
    </div>

    <!-- Price Summary -->
    <div class="section-title"><?= htmlspecialchars(translateTo('price_summary', $language)) ?></div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label"><?= htmlspecialchars(translateTo('subtotal', $language)) ?></td>
                    <td class="price-value"><?= $currency ?> <?= number_format($rawTotal - (float)($bookingData['ancillary_data']['total_base'] ?? 0) - (float)($booking['tax'] ?? 0), 2) ?></td>
                </tr>
            </table>
        </div>
        <?php
            $ancTotal = (float)($bookingData['ancillary_data']['total_base'] ?? 0);
            if ($ancTotal > 0):
        ?>
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label"><?= htmlspecialchars(translateTo('extra_services', $language)) ?></td>
                    <td class="price-value"><?= $currency ?> <?= number_format($ancTotal, 2) ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label"><?= htmlspecialchars(translateTo('taxes_fees', $language)) ?></td>
                    <td class="price-value"><?= $currency ?> <?= number_format($booking['tax'] ?? 0, 2) ?></td>
                </tr>
            </table>
        </div>
        <div class="price-total">
            <table>
                <tr>
                    <td class="price-label"><strong><?= htmlspecialchars(translateTo('total_amount', $language)) ?></strong></td>
                    <td class="price-value"><?= $currency ?> <?= $totalAmount ?></td>
                </tr>
            </table>
        </div>
        <div class="price-row" style="margin-top: 6px;">
            <table>
                <tr>
                    <td class="price-label"><?= htmlspecialchars(translateTo('payment_method', $language)) ?></td>
                    <td class="price-value"><?= htmlspecialchars($booking['payment_gateway'] ?: 'N/A') ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="note-box">
        <div class="note-title"><?= htmlspecialchars(translateTo('special_requests', $language)) ?></div>
        <div class="note-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Ancillaries Summary -->
    <?php 
    $totalSeats = 0;
    $totalBags = 0;
    if (!empty($ancillarySeats) && is_array($ancillarySeats)) {
        foreach ($ancillarySeats as $seats) {
            if (is_array($seats)) {
                $totalSeats += count($seats);
            }
        }
    }
    if (!empty($ancillaryBaggage) && is_array($ancillaryBaggage)) {
        foreach ($ancillaryBaggage as $bag) {
            if (isset($bag['quantity'])) {
                $totalBags += (int)$bag['quantity'];
            }
        }
    }
    if ($totalSeats > 0 || $totalBags > 0):
    ?>
    <div class="note-box" style="background: #f0fdf4; border-left: 3px solid #10b981; padding: 6px 8px; margin-bottom: 8px;">
        <div class="note-title" style="color: #065f46;">Ancillaries Selected</div>
        <div class="note-text" style="color: #047857;">
            <?php if ($totalSeats > 0): ?>
            ✓ <strong><?= $totalSeats ?> Seat<?= $totalSeats != 1 ? 's' : '' ?></strong> selected across passengers<br>
            <?php endif; ?>
            <?php if ($totalBags > 0): ?>
            ✓ <strong><?= $totalBags ?> Extra Baggage Item<?= $totalBags != 1 ? 's' : '' ?></strong> added<br>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;"><?= htmlspecialchars(translateTo('important_information', $language)) ?></div>
        <ul>
            <li><?= htmlspecialchars(translateTo('flights_arrive_early_notice', $language)) ?></li>
            <li><?= htmlspecialchars(translateTo('flights_valid_id_notice', $language)) ?></li>
            <li><?= htmlspecialchars(translateTo('flights_baggage_notice', $language)) ?></li>
            <li><?= htmlspecialchars(translateTo('flights_not_boarding_pass_notice', $language)) ?></li>
            <li><?= htmlspecialchars(translateTo('flights_status_updates_notice', $language)) ?></li>
        </ul>
    </div>

    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;"><?= htmlspecialchars(translateTo('scan_to_view_invoice', $language)) ?></p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/flights/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-contact">
            <?= htmlspecialchars(translateTo('questions_contact_us', $language)) ?> <?= htmlspecialchars($businessEmail) ?>
            <?php if (!empty($businessPhone)): ?> | <?= htmlspecialchars($businessPhone) ?><?php endif; ?>
        </div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= getBrandName($settings ?? null) ?>. All rights reserved.<br>
            <?= htmlspecialchars(translateTo('booking_date', $language)) ?>: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'])) ?><br>
            <?= htmlspecialchars(translateTo('automated_voucher_disclaimer', $language)) ?>
        </div>
    </div>
</body>
</html>
