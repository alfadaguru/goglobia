<?php
if (!isset($SECURE)) die('Direct access not permitted');

// Prepare Bus Data (draft stored at booking time)
$trip       = $bookingData['trip'] ?? [];
$journeys   = $bookingData['journeys'] ?? [['type' => 'outbound', 'route_id' => $bookingData['route_id'] ?? 0, 'date' => $bookingData['date'] ?? '', 'trip' => $trip]];
$busName    = $trip['service_name'] ?? 'Bus Trip';
$operator   = $trip['operator'] ?? '';
$origin     = $trip['origin'] ?? '';
$destination = $trip['destination'] ?? '';
$depTime    = $trip['departure_time'] ?? '';
$arrTime    = $trip['arrival_time'] ?? '';
$duration   = $trip['duration'] ?? '';
$busType    = trim(($trip['bus_type'] ?? '') . (!empty($trip['seat_class']) ? ' · ' . $trip['seat_class'] : ''));
$travelDateRaw = $bookingData['date'] ?? '';
$travelTs   = $travelDateRaw ? (DateTime::createFromFormat('d-m-Y', $travelDateRaw) ?: null) : null;
$travelDate = $travelTs ? $travelTs->format('F d, Y') : $travelDateRaw;
$adultsCnt  = (int)($booking['adults'] ?? 1);
$childsCnt  = (int)($booking['childs'] ?? 0);
$pnr        = $booking['pnr'] ?? '';
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

        /* Trip Card */
        .trip-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .trip-header { background: #374151; color: #fff; padding: 8px 12px; }
        .trip-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .trip-type { font-size: 7pt; opacity: 0.9; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }
        .dates-time { font-size: 7pt; color: #4a5568; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

        /* Price Summary */
        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
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
                    <div class="ref-label">Booking Reference</div>
                    <div class="ref-number"><?= $pnr !== '' ? htmlspecialchars($pnr) : $invoiceId ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Bus Trip Card(s) -->
    <?php foreach ($journeys as $journey):
        $trip = $journey['trip'] ?? [];
        $busName = $trip['service_name'] ?? 'Bus Trip'; $operator = $trip['operator'] ?? '';
        $origin = $trip['origin'] ?? ''; $destination = $trip['destination'] ?? '';
        $depTime = $trip['departure_time'] ?? ''; $arrTime = $trip['arrival_time'] ?? ''; $duration = $trip['duration'] ?? '';
        $busType = trim(($trip['bus_type'] ?? '') . (!empty($trip['seat_class']) ? ' · ' . $trip['seat_class'] : ''));
        $travelDateRaw = $journey['date'] ?? '';
        $travelTs = $travelDateRaw ? (DateTime::createFromFormat('d-m-Y', $travelDateRaw) ?: null) : null;
        $travelDate = $travelTs ? $travelTs->format('F d, Y') : $travelDateRaw;
        $journeyLabel = ($journey['type'] ?? 'outbound') === 'return' ? 'Return' : 'Outbound';
    ?>
    <div class="trip-card">
        <div class="trip-header">
            <div class="trip-name"><?= count($journeys) > 1 ? $journeyLabel . ': ' : '' ?><?= htmlspecialchars($busName) ?></div>
            <div class="trip-type"><?= htmlspecialchars(trim($operator . ($busType !== '' ? ' · ' . $busType : ''))) ?></div>
        </div>

        <!-- Departure & Arrival -->
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Departure</div>
                    <div class="dates-value"><?= htmlspecialchars($origin) ?></div>
                    <div class="dates-time"><?= htmlspecialchars($travelDate) ?><?= $depTime !== '' ? ' at ' . htmlspecialchars($depTime) : '' ?></div>
                </td>
                <td>
                    <div class="dates-label">Arrival</div>
                    <div class="dates-value"><?= htmlspecialchars($destination) ?></div>
                    <div class="dates-time"><?= $arrTime !== '' ? htmlspecialchars($arrTime) : '' ?></div>
                </td>
            </tr>
        </table>

        <!-- Trip Info -->
        <div class="stay-info">
            <?php if ($duration !== ''): ?><strong><?= htmlspecialchars($duration) ?></strong> Journey · <?php endif; ?>
            <strong><?= $adultsCnt ?></strong> Adult<?= $adultsCnt > 1 ? 's' : '' ?><?= $childsCnt > 0 ? ', <strong>' . $childsCnt . '</strong> Child' . ($childsCnt > 1 ? 'ren' : '') : '' ?>
            <?php if ($pnr !== ''): ?> · PNR: <strong><?= htmlspecialchars($pnr) ?></strong><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Passenger Information -->
    <div class="section-title">Lead Passenger</div>
    <div class="guest-info">
        <div class="guest-row">
            <span class="guest-label">Name:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Email:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <?php if (!empty($booking['phone'])): ?>
        <div class="guest-row">
            <span class="guest-label">Phone:</span>
            <span class="guest-value"><?= htmlspecialchars(trim(($booking['phone_country_code'] ?? '') . ' ' . $booking['phone'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($travellersData) && is_array($travellersData)): ?>
    <div class="section-title">Passengers</div>
    <div class="guest-info">
        <?php $n = 1; foreach ($travellersData as $tr): if (empty($tr['first_name'])) continue; ?>
        <div class="guest-row">
            <span class="guest-label"><?= $n++ ?>.</span>
            <span class="guest-value"><?= htmlspecialchars(trim(($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? ''))) ?><?= ($tr['type'] ?? '') === 'child' ? ' (Child)' : '' ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pricing Summary -->
    <div class="section-title">Price Breakdown</div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Ticket Charges</td>
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format((float)$booking['price_markup'], 2) ?></td>
                </tr>
            </table>
        </div>

        <?php if ((float)($booking['tax'] ?? 0) > 0): ?>
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Taxes &amp; Fees</td>
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format((float)$booking['tax'], 2) ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <div class="price-total">
            <table>
                <tr>
                    <td class="price-label"><strong>Total Amount</strong></td>
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format((float)$booking['price_markup'], 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Payment Status -->
    <?php if (($booking['payment_status'] ?? '') === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ Payment Required</div>
        <div class="alert-text">
            Please complete your payment<?= !empty($booking['payment_gateway']) ? ' using <strong>' . htmlspecialchars($booking['payment_gateway']) . '</strong>' : '' ?> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="info-box">
        <div class="info-title">Special Requests</div>
        <div class="info-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Cancellation Policy -->
    <?php if (!empty($trip['cancellation_policy'])): ?>
    <div class="section-title">Cancellation Policy</div>
    <div class="info-box">
        <div class="info-text"><?= nl2br(htmlspecialchars($trip['cancellation_policy'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Important Information</div>
        <ul>
            <li>Arrive at the boarding point at least 30 minutes before departure</li>
            <li>Carry a valid photo ID matching the lead passenger name</li>
            <li>Show this voucher (printed or digital) to the operator at boarding</li>
            <li>Luggage allowances and policies are set by the bus operator</li>
        </ul>
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
