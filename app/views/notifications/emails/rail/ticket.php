<?php
/**
 * RAIL TICKET — Train Ticket (NO PRICE). Shared ticket base.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $pnr !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$journey = $bookingData['journey'][0] ?? ($bookingData['journey'] ?? []);
$fromTs = (int) ($journey['from_date_time'] ?? 0);
$toTs = (int) ($journey['to_date_time'] ?? 0);
$fromCode = trim((string) ($journey['from_station_code'] ?? ''));
$toCode = trim((string) ($journey['to_station_code'] ?? ''));
$fromStation = trim((string) ($journey['from_station_english'] ?? $fromCode));
$toStation = trim((string) ($journey['to_station_english'] ?? $toCode));
$depDate = $fromTs > 0 ? date('D, d M Y', $fromTs) : '';
$depTime = $fromTs > 0 ? date('H:i', $fromTs) : '';
$arrDate = $toTs > 0 ? date('D, d M Y', $toTs) : $depDate;
$arrTime = $toTs > 0 ? date('H:i', $toTs) : '';
$runMin = max(0, (int) ($journey['run_time'] ?? 0));
$dur = $runMin > 0 ? intdiv($runMin, 60) . 'h ' . ($runMin % 60) . 'm' : '';
$trafficNo = trim((string) ($journey['traffic_no'] ?? ''));
$seat = trim((string) ($journey['seat_name'] ?? $journey['seat_class'] ?? ''));

$paxName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<div class="sec-h"><span class="leg">TRAIN</span> &nbsp; <?= $e($fromCode ?: $fromStation) ?> &rarr; <?= $e($toCode ?: $toStation) ?></div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title">Rail Service<?php if ($trafficNo !== ''): ?> <span class="card-sub"><?= $e($trafficNo) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><?php if ($seat !== ''): ?><span class="tag"><?= $e(strtoupper($seat)) ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:38%;">
            <div class="big"><?= $e($fromCode ?: '—') ?></div>
            <?php if ($depTime !== ''): ?><div class="mid"><?= $e($depTime) ?></div><?php endif; ?>
            <?php if ($depDate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($depDate) ?></div><?php endif; ?>
            <?php if ($fromStation !== '' && $fromStation !== $fromCode): ?><div class="vs"><?= $e($fromStation) ?></div><?php endif; ?>
        </td>
        <td style="width:24%; text-align:center;">
            <?php if ($dur !== ''): ?><div class="k"><?= $e($dur) ?></div><?php endif; ?>
            <div class="vs">Direct</div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="big"><?= $e($toCode ?: '—') ?></div>
            <?php if ($arrTime !== ''): ?><div class="mid"><?= $e($arrTime) ?></div><?php endif; ?>
            <?php if ($arrDate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($arrDate) ?></div><?php endif; ?>
            <?php if ($toStation !== '' && $toStation !== $toCode): ?><div class="vs"><?= $e($toStation) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="cardfoot"><span class="fic">PASSENGER</span> <b><?= $e($paxName ?: 'Passenger') ?></b> &nbsp;&middot;&nbsp; <span class="fic">STATUS</span> <b><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></b></div>
</div>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Train Ticket', 'docSub' => 'Rail Booking · Passenger Copy',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Rail Booking Ref', 'refPrimaryValue' => $pnr,
    'refPrimaryHint' => 'Show this at the station / gate',
    'managePath' => 'rail/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'Before you travel',
    'guidanceItems' => [
        'Arrive at the station at least 30 minutes before departure to collect or validate your ticket if required.',
        'Carry a printed or mobile copy of this ticket and a valid photo ID matching the passenger name.',
        'Boarding usually closes a few minutes before departure — be at the platform in good time.',
    ],
    'footerNote' => 'This ticket confirms your rail booking. Prices and payment details appear on your separate invoice/receipt. The rail operator\'s conditions of carriage apply.',
]);
