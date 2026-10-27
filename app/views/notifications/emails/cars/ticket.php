<?php
/**
 * CARS TICKET — Car Rental Voucher (NO PRICE)
 * Premium car-hire voucher. Uses the shared ticket base for chrome.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');

$confRef = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $confRef !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$carName = (string) ($bookingData['car_name'] ?? ($bookingData['car_data']['name'] ?? 'Your Vehicle'));
$transmission = (string) ($bookingData['transmission'] ?? ($bookingData['car_data']['transmission'] ?? ''));
$vehicleType = (string) ($bookingData['vehicle_type'] ?? '');
$pickupLoc = (string) ($bookingData['pickup_location'] ?? '');
$dropoffLoc = (string) ($bookingData['dropoff_location'] ?? $pickupLoc);
$pickupDate = (string) ($bookingData['pickup_date'] ?? '');
$pickupTime = (string) ($bookingData['pickup_time'] ?? '');
$dropoffDate = (string) ($bookingData['dropoff_date'] ?? '');
$dropoffTime = (string) ($bookingData['dropoff_time'] ?? '');
$driverAge = (int) ($bookingData['driver_age'] ?? 0);

$fmtDate = function ($d) {
    $d = trim((string) $d);
    if ($d === '') return '';
    // Cars store DD-MM-YYYY (e.g. 20-11-2026). Parse explicitly, else fall back.
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $d, $m)) {
        $t = mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
        return $t ? date('D, d M Y', $t) : $d;
    }
    $t = strtotime($d);
    return $t ? date('D, d M Y', $t) : $d;
};
$pickupDateF = $fmtDate($pickupDate);
$dropoffDateF = $fmtDate($dropoffDate);

$driverName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<div class="sec-h">Vehicle</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($carName) ?></td>
        <td style="text-align:right;">
            <?php if ($transmission !== '' && strtolower($transmission) !== 'any'): ?><span class="tag"><?= $e(strtoupper($transmission)) ?></span><?php endif; ?>
            <?php if ($vehicleType !== '' && strtolower($vehicleType) !== 'any'): ?><span class="tag"><?= $e(strtoupper($vehicleType)) ?></span><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:50%;">
            <div class="k">Pick-up</div>
            <div class="mid"><?= $e($pickupDateF ?: '—') ?><?= $pickupTime ? ' &middot; ' . $e($pickupTime) : '' ?></div>
            <?php if ($pickupLoc !== ''): ?><div class="vs"><?= $e($pickupLoc) ?></div><?php endif; ?>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k">Drop-off</div>
            <div class="mid"><?= $e($dropoffDateF ?: '—') ?><?= $dropoffTime ? ' &middot; ' . $e($dropoffTime) : '' ?></div>
            <?php if ($dropoffLoc !== ''): ?><div class="vs"><?= $e($dropoffLoc) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="cardfoot">
        <span class="fic">DRIVER</span> <b><?= $e($driverName ?: 'Main driver') ?></b>
        <?php if ($driverAge > 0): ?> &nbsp;&middot;&nbsp; <span class="fic">AGE</span> <b><?= $driverAge ?></b><?php endif; ?>
    </div>
</div>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Car Rental Voucher', 'docSub' => 'Vehicle Confirmation',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Rental Confirmation No.',
    'refPrimaryValue' => $confRef,
    'refPrimaryHint' => 'Present this at the rental desk',
    'managePath' => 'cars/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'At the rental desk',
    'guidanceItems' => [
        'Bring this voucher, the main driver&rsquo;s valid driving licence, passport / ID, and the payment card in the driver&rsquo;s name.',
        'The rental company will place a refundable deposit / pre-authorisation on the card to cover the excess and fuel.',
        'Inspect the vehicle for existing damage before you drive off and ensure it is noted on the rental agreement.',
    ],
    'footerNote' => 'This voucher confirms your car rental. Prices and payment details appear on your separate invoice/receipt. The rental company\'s terms, insurance and local charges apply.',
]);
