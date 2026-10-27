<?php
/**
 * UMRAH TICKET — Pilgrim Travel Document (NO PRICE). Shared ticket base.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$ref = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $ref !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$umrahName = (string) ($bookingData['umrah_name'] ?? 'Umrah Package');
$location = (string) ($bookingData['umrah_location'] ?? '');
$startRaw = (string) ($bookingData['start_date'] ?? '');
$startDate = '';
if ($startRaw !== '') {
    $t = strtotime($startRaw);
    $startDate = $t ? date('D, d M Y', $t) : $startRaw;
}
$duration = (string) ($bookingData['duration'] ?? '');
$adults = (int) ($booking['adults'] ?? $bookingData['total_adults'] ?? 0);
$children = (int) ($booking['childs'] ?? $bookingData['total_children'] ?? 0);

$flights = is_array($bookingData['flights'] ?? null) ? array_filter($bookingData['flights'], 'is_array') : [];
$stays = is_array($bookingData['stays'] ?? null) ? array_filter($bookingData['stays'], 'is_array') : [];
$transfers = is_array($bookingData['transfers'] ?? null) ? array_filter($bookingData['transfers'], 'is_array') : [];

$pilgrimName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));
$nationality = (string) ($booking['nationality'] ?? '');

ob_start();
?>
<div class="sec-h">Umrah Package</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($umrahName) ?><?php if ($location !== ''): ?> <span class="card-sub"><?= $e($location) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><?php if ($duration !== ''): ?><span class="tag"><?= $e(strtoupper($duration)) ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:50%;">
            <div class="k">Departure</div>
            <div class="mid"><?= $e($startDate ?: '—') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k">Pilgrims</div>
            <div class="mid"><?= $adults ?: 1 ?> adult<?= ($adults ?: 1) > 1 ? 's' : '' ?><?= $children > 0 ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?></div>
        </td>
    </tr></table></div>
    <div class="cardfoot">
        <?php if (!empty($flights)): ?><span class="fic">FLIGHTS</span> <b><?= count($flights) ?></b> &nbsp;&middot;&nbsp;<?php endif; ?>
        <?php if (!empty($stays)): ?><span class="fic">HOTELS</span> <b><?= count($stays) ?></b> &nbsp;&middot;&nbsp;<?php endif; ?>
        <?php if (!empty($transfers)): ?><span class="fic">TRANSFERS</span> <b><?= count($transfers) ?></b> &nbsp;&middot;&nbsp;<?php endif; ?>
        <span class="fic">STATUS</span> <b><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></b>
    </div>
</div>

<div class="sec-h">Lead Pilgrim</div>
<table class="infotable"><tr>
    <td style="width:64%;">
        <div class="v"><?= $e($pilgrimName ?: 'Pilgrim') ?></div>
        <?php if ($nationality !== ''): ?><div class="vs"><?= $e($nationality) ?></div><?php endif; ?>
    </td>
    <td style="width:36%; text-align:right;"><span class="k">Status&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></span></td>
</tr></table>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Umrah Voucher', 'docSub' => 'Pilgrimage Package · Confirmation',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Umrah Booking Ref', 'refPrimaryValue' => $ref,
    'refPrimaryHint' => 'Quote this to your travel coordinator',
    'managePath' => 'umrah/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'Before you travel',
    'guidanceItems' => [
        'Your coordinator will share your flight itinerary, hotel vouchers and transfer details as each is confirmed.',
        'Ensure your passport is valid for at least 6 months and your Umrah visa and vaccinations are in order.',
        'Keep this voucher and a valid passport with you throughout your journey.',
    ],
    'footerNote' => 'This voucher confirms your Umrah package booking. Prices and payment details appear on your separate invoice/receipt. Package terms apply.',
]);
