<?php
/**
 * TOURS TICKET — Tour Voucher (NO PRICE)
 * Premium activity/tour voucher. Uses the shared ticket base for chrome.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');

$confRef = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $confRef !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$tourName = (string) ($bookingData['tour_name'] ?? $bookingData['destination'] ?? 'Your Tour');
$startRaw = (string) ($bookingData['start_date'] ?? $bookingData['travel_date'] ?? '');
// Tours store DD-MM-YYYY (e.g. 12-11-2026 = 12 Nov). Parse explicitly so the
// day/month are never swapped by strtotime's US interpretation.
$startDate = '';
if ($startRaw !== '') {
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $startRaw, $m)) {
        $startDate = date('D, d M Y', mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]));
    } else {
        $t = strtotime($startRaw);
        $startDate = $t ? date('D, d M Y', $t) : $startRaw;
    }
}
$duration = (string) ($bookingData['duration'] ?? '');
$tourType = (string) ($bookingData['tour_type'] ?? '');
$pickup = (string) ($bookingData['pickup_location'] ?? '');
$adults = (int) ($booking['adults'] ?? $bookingData['adults'] ?? 0);
$children = (int) ($booking['childs'] ?? $bookingData['children'] ?? 0);
$infants = (int) ($booking['infants'] ?? 0);

$guestName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<div class="sec-h">Tour</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($tourName) ?></td>
        <td style="text-align:right;"><?php if ($tourType !== ''): ?><span class="tag"><?= $e(strtoupper($tourType)) ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:50%;">
            <div class="k">Start Date</div>
            <div class="mid"><?= $e($startDate ?: '—') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k">Duration</div>
            <div class="mid"><?= $e($duration ?: '—') ?></div>
        </td>
    </tr></table></div>
    <div class="cardfoot">
        <?php if ($pickup !== ''): ?><span class="fic">PICKUP</span> <b><?= $e($pickup) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <span class="fic">GUESTS</span> <b><?= $adults ?: 1 ?> adult<?= ($adults ?: 1) > 1 ? 's' : '' ?><?= $children > 0 ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?><?= $infants > 0 ? ', ' . $infants . ' infant' . ($infants > 1 ? 's' : '') : '' ?></b>
    </div>
</div>

<div class="sec-h">Lead Guest</div>
<table class="infotable"><tr>
    <td style="width:64%;"><div class="v"><?= $e($guestName ?: 'Guest') ?></div></td>
    <td style="width:36%; text-align:right;"><span class="k">Status&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></span></td>
</tr></table>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Tour Voucher', 'docSub' => 'Activity Confirmation',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Tour Confirmation No.',
    'refPrimaryValue' => $confRef,
    'refPrimaryHint' => 'Present this to your tour operator',
    'managePath' => 'tours/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'On the day',
    'guidanceItems' => [
        'Present this voucher (printed or on your phone) and a valid photo ID to your tour operator at the meeting point.',
        'Arrive at the pickup/meeting point at least 15 minutes before the scheduled start time.',
        'Wear comfortable clothing suitable for the activity and weather; bring any items noted in your tour details.',
    ],
    'footerNote' => 'This voucher confirms your tour booking. Prices and payment details appear on your separate invoice/receipt. The operator\'s own terms and conditions apply.',
]);
