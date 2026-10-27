<?php
/**
 * BUS TICKET — Bus Ticket (NO PRICE). Shared ticket base.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $pnr !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$trip = $bookingData['trip'] ?? [];
$journeys = $bookingData['journeys'] ?? [['trip' => $trip, 'date' => $bookingData['date'] ?? '']];
$paxName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<?php foreach ($journeys as $ji => $jn):
    $t = $jn['trip'] ?? $trip;
    $busName = (string) ($t['service_name'] ?? 'Bus Service');
    $operator = (string) ($t['operator'] ?? '');
    $origin = (string) ($t['origin'] ?? '');
    $destination = (string) ($t['destination'] ?? '');
    $depTime = (string) ($t['departure_time'] ?? '');
    $arrTime = (string) ($t['arrival_time'] ?? '');
    $dur = (string) ($t['duration'] ?? '');
    $busType = trim((string) ($t['bus_type'] ?? '') . (!empty($t['seat_class']) ? ' · ' . $t['seat_class'] : ''));
    $jdate = (string) ($jn['date'] ?? $bookingData['date'] ?? '');
    $label = count($journeys) > 1 ? (($jn['type'] ?? '') === 'return' || $ji === 1 ? 'Return' : 'Outbound') : 'Journey';
?>
<div class="sec-h"><span class="leg"><?= $e(strtoupper($label)) ?></span> &nbsp; <?= $e($origin) ?> &rarr; <?= $e($destination) ?></div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($busName) ?><?php if ($operator !== ''): ?> <span class="card-sub"><?= $e($operator) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><?php if ($busType !== ''): ?><span class="tag"><?= $e(strtoupper($busType)) ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:40%;">
            <div class="mid"><?= $e($origin ?: '—') ?></div>
            <?php if ($depTime !== ''): ?><div class="v" style="font-size:12pt;"><?= $e($depTime) ?></div><?php endif; ?>
            <?php if ($jdate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($jdate) ?></div><?php endif; ?>
        </td>
        <td style="width:20%; text-align:center;">
            <?php if ($dur !== ''): ?><div class="k"><?= $e($dur) ?></div><?php endif; ?>
            <div class="vs">&rarr;</div>
        </td>
        <td style="width:40%; text-align:right;">
            <div class="mid"><?= $e($destination ?: '—') ?></div>
            <?php if ($arrTime !== ''): ?><div class="v" style="font-size:12pt;"><?= $e($arrTime) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="cardfoot"><span class="fic">PASSENGER</span> <b><?= $e($paxName ?: 'Passenger') ?></b> &nbsp;&middot;&nbsp; <span class="fic">STATUS</span> <b><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></b></div>
</div>
<?php endforeach; ?>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Bus Ticket', 'docSub' => 'Coach Booking · Passenger Copy',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Bus Booking Ref', 'refPrimaryValue' => $pnr,
    'refPrimaryHint' => 'Show this to the operator when boarding',
    'managePath' => 'bus/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'Before you travel',
    'guidanceItems' => [
        'Arrive at the departure point at least 20 minutes before the scheduled time.',
        'Carry a printed or mobile copy of this ticket and a valid photo ID matching the passenger name.',
        'Boarding may close a few minutes before departure — be at the terminal in good time.',
    ],
    'footerNote' => 'This ticket confirms your bus booking. Prices and payment details appear on your separate invoice/receipt. The operator\'s conditions of carriage apply.',
]);
