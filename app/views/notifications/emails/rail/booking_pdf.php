<?php
if (!isset($SECURE)) {
    die('Direct access not permitted');
}

$journey = $bookingData['journey'][0] ?? [];
$fromTs = (int)($journey['from_date_time'] ?? 0);
$toTs = (int)($journey['to_date_time'] ?? 0);
$fromCode = trim((string)($journey['from_station_code'] ?? ''));
$toCode = trim((string)($journey['to_station_code'] ?? ''));
if (!function_exists('_train_station_label') && isset($db)) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
}
if (isset($db) && function_exists('_train_station_label')) {
    $fromStation = $fromCode !== '' ? _train_station_label($db, $fromCode) : '';
    $toStation = $toCode !== '' ? _train_station_label($db, $toCode) : '';
} else {
    $fromStation = trim((string)($journey['from_station_english'] ?? $fromCode));
    $toStation = trim((string)($journey['to_station_english'] ?? $toCode));
}
if ($fromStation === '' && $fromCode !== '') {
    $fromStation = $fromCode;
}
if ($toStation === '' && $toCode !== '') {
    $toStation = $toCode;
}
$fromTime = $fromTs > 0 ? date('H:i', $fromTs) : '';
$toTime = $toTs > 0 ? date('H:i', $toTs) : '';
$departureDate = $fromTs > 0 ? date('F d, Y', $fromTs) : date('F d, Y', strtotime($booking['booking_date'] ?? 'now'));
$arrivalDate = $toTs > 0 ? date('F d, Y', $toTs) : $departureDate;
$runTimeMinutes = max(0, (int)($journey['run_time'] ?? 0));
if ($runTimeMinutes <= 0 && $fromTs > 0 && $toTs > $fromTs) {
    $runTimeMinutes = (int) round(($toTs - $fromTs) / 60);
}
$durationLabel = '';
if ($runTimeMinutes > 0) {
    $hours = intdiv($runTimeMinutes, 60);
    $mins = $runTimeMinutes % 60;
    $durationLabel = $hours > 0 ? $hours . 'h ' . $mins . 'm' : $mins . 'm';
}
$trafficNo = trim((string)($journey['traffic_no'] ?? ''));
$seatName = trim((string)($journey['seat_name'] ?? $journey['seat_class'] ?? ''));
$pnr = trim((string)($booking['pnr'] ?? ''));
$adultsCnt = (int)($booking['adults'] ?? 1);
$childsCnt = (int)($booking['childs'] ?? 0);

$bookingResponse = json_decode($booking['booking_response'] ?? '{}', true) ?: [];
$passengers = is_array($travellersData) ? $travellersData : [];
if ($passengers === [] && !empty($bookingData['passengers']) && is_array($bookingData['passengers'])) {
    $passengers = $bookingData['passengers'];
}

$rspPassengers = $bookingResponse['data']['journey'][0]['passengers']
    ?? $bookingResponse['journey'][0]['passengers']
    ?? $bookingResponse['poll']['journey'][0]['passengers']
    ?? [];
if (!is_array($rspPassengers)) {
    $rspPassengers = [];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; }
        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; }
        .trip-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .trip-header { background: #4338ca; color: #fff; padding: 8px 12px; }
        .trip-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .trip-type { font-size: 7pt; opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; color: #2d3748; margin-bottom: 1px; }
        .dates-time { font-size: 7pt; color: #4a5568; }
        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .guest-info { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .guest-row { margin-bottom: 3px; font-size: 7pt; }
        .guest-label { color: #718096; display: inline-block; width: 60px; }
        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
        .price-row td { padding: 0; border: none; font-size: 7pt; }
        .price-label { color: #4a5568; }
        .price-value { text-align: right; color: #2d3748; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 9pt; font-weight: bold; }
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; font-size: 7pt; color: #92400e; }
        .footer { background: #f7fafc; padding: 8px 10px; margin-top: 10px; border-top: 1px solid #cbd5e0; text-align: center; }
        .footer-contact { font-size: 7pt; color: #4a5568; margin-bottom: 5px; }
        .footer-note { font-size: 6pt; color: #718096; line-height: 1.3; }
    </style>
</head>
<body>
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
                    <div class="ref-label">Invoice / PNR</div>
                    <div class="ref-number"><?= $pnr !== '' ? htmlspecialchars($pnr) : htmlspecialchars($invoiceId) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="trip-card">
        <div class="trip-header">
            <div class="trip-name">Train <?= htmlspecialchars($trafficNo) ?></div>
            <div class="trip-type"><?= $seatName !== '' ? htmlspecialchars($seatName) : 'Rail ticket' ?></div>
        </div>
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Departure</div>
                    <div class="dates-value"><?= htmlspecialchars($fromStation) ?></div>
                    <div class="dates-time"><?= htmlspecialchars($departureDate) ?><?= $fromTime !== '' ? ' at ' . htmlspecialchars($fromTime) : '' ?></div>
                </td>
                <td>
                    <div class="dates-label">Arrival</div>
                    <div class="dates-value"><?= htmlspecialchars($toStation) ?></div>
                    <div class="dates-time"><?= htmlspecialchars($arrivalDate) ?><?= $toTime !== '' ? ' at ' . htmlspecialchars($toTime) : '' ?></div>
                </td>
            </tr>
        </table>
        <div class="stay-info">
            <?php if ($durationLabel !== ''): ?><strong><?= htmlspecialchars($durationLabel) ?></strong> · <?php endif; ?>
            <strong><?= $adultsCnt ?></strong> Adult<?= $adultsCnt !== 1 ? 's' : '' ?><?= $childsCnt > 0 ? ', <strong>' . $childsCnt . '</strong> Child' . ($childsCnt !== 1 ? 'ren' : '') : '' ?>
            <?php if ($pnr !== ''): ?> · PNR: <strong><?= htmlspecialchars($pnr) ?></strong><?php endif; ?>
        </div>
    </div>

    <div class="section-title">Contact</div>
    <div class="guest-info">
        <div class="guest-row"><span class="guest-label">Name:</span><span class="guest-value"><?= htmlspecialchars(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?></span></div>
        <div class="guest-row"><span class="guest-label">Email:</span><span class="guest-value"><?= htmlspecialchars($booking['email'] ?? '') ?></span></div>
        <?php if (!empty($booking['phone'])): ?>
        <div class="guest-row"><span class="guest-label">Phone:</span><span class="guest-value"><?= htmlspecialchars(trim(($booking['phone_country_code'] ?? '') . ' ' . $booking['phone'])) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($passengers !== []): ?>
    <div class="section-title">Passengers</div>
    <div class="guest-info">
        <?php foreach ($passengers as $idx => $tr):
            if (!is_array($tr)) {
                continue;
            }
            $name = trim((string)($tr['passenger_first_name'] ?? $tr['first_name'] ?? '') . ' ' . ($tr['passenger_last_name'] ?? $tr['last_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $cardNo = trim((string)($tr['passenger_card_no'] ?? $tr['identity_number'] ?? ''));
            $seatLine = '';
            foreach ($rspPassengers as $rp) {
                if (!is_array($rp)) {
                    continue;
                }
                if ($cardNo !== '' && trim((string)($rp['passenger_card_no'] ?? '')) === $cardNo) {
                    $coach = trim((string)($rp['train_coach_no'] ?? ''));
                    $seat = trim((string)($rp['train_seat_no'] ?? ''));
                    if ($coach !== '' || $seat !== '') {
                        $seatLine = ' — ' . ($coach !== '' ? 'Coach ' . $coach : '') . ($seat !== '' ? ($coach !== '' ? ', Seat ' : 'Seat ') . $seat : '');
                    }
                    break;
                }
            }
        ?>
        <div class="guest-row">
            <span class="guest-label"><?= (int)$idx + 1 ?>.</span>
            <span class="guest-value"><?= htmlspecialchars($name) ?><?= htmlspecialchars($seatLine) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Price Breakdown</div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Ticket Total</td>
                    <td class="price-value"><?= htmlspecialchars($currency) ?> <?= number_format((float)($booking['price_markup'] ?? 0), 2) ?></td>
                </tr>
            </table>
        </div>
        <div class="price-total">
            <table>
                <tr>
                    <td class="price-label"><strong>Total Amount</strong></td>
                    <td class="price-value"><?= htmlspecialchars($currency) ?> <?= number_format((float)($booking['price_markup'] ?? 0), 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if (($booking['payment_status'] ?? '') !== 'paid'): ?>
    <div class="alert-box">Payment is pending. Please complete payment to confirm this booking.</div>
    <?php endif; ?>

    <div class="footer">
        <div class="footer-contact">
            Questions? Contact us at <?= $settings['email_sender_email'] ?? 'support@phptravels.com' ?>
            <?php if (!empty($settings['site_phone'])): ?> | <?= $settings['site_phone'] ?><?php endif; ?>
        </div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= getBrandName($settings ?? null) ?>. All rights reserved.<br>
            Invoice #<?= htmlspecialchars($invoiceId) ?> · Generated <?= date('M d, Y H:i') ?><br>
            This is an automated booking voucher. Please do not reply to this document.
        </div>
    </div>
</body>
</html>
