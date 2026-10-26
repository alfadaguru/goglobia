<?php
/**
 * FERRIES TICKET — Ferry Ticket (NO PRICE). Shared ticket base.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');

// Ferry supplier ref: pnr, else booking_data.reference / locators.
$ref = trim((string) ($booking['pnr'] ?? ''));
if ($ref === '') { $ref = trim((string) ($bookingData['reference'] ?? '')); }
if ($ref === '' && !empty($bookingData['locators']) && is_array($bookingData['locators'])) {
    $ref = trim((string) reset($bookingData['locators']));
}
$isConfirmed = !empty($bookingData['confirmed']) || $ref !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$draft = $bookingData['draft'] ?? [];
$origin = (string) ($draft['from_name'] ?? $draft['origin'] ?? $draft['departure_port'] ?? '');
$destination = (string) ($draft['to_name'] ?? $draft['destination'] ?? $draft['arrival_port'] ?? '');
$sailDate = (string) ($draft['date'] ?? $draft['departure_date'] ?? '');
$depTime = (string) ($draft['departure_time'] ?? $draft['time'] ?? '');
$adults = (int) ($booking['adults'] ?? 0);
$children = (int) ($booking['childs'] ?? 0);
$vehicles = is_array($bookingData['vehicles'] ?? null) ? count($bookingData['vehicles']) : 0;
$pets = is_array($bookingData['pets'] ?? null) ? count($bookingData['pets']) : 0;

$paxName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<div class="sec-h"><span class="leg">CROSSING</span> <?php if ($origin !== '' || $destination !== ''): ?>&nbsp; <?= $e($origin) ?> &rarr; <?= $e($destination) ?><?php endif; ?></div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title">Ferry Crossing</td>
        <td style="text-align:right;"><?php if ($vehicles > 0): ?><span class="tag"><?= $vehicles ?> VEHICLE<?= $vehicles > 1 ? 'S' : '' ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:40%;">
            <div class="mid"><?= $e($origin ?: 'Departure port') ?></div>
            <?php if ($depTime !== ''): ?><div class="v" style="font-size:12pt;"><?= $e($depTime) ?></div><?php endif; ?>
            <?php if ($sailDate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($sailDate) ?></div><?php endif; ?>
        </td>
        <td style="width:20%; text-align:center;"><div class="vs">&rarr;</div></td>
        <td style="width:40%; text-align:right;">
            <div class="mid"><?= $e($destination ?: 'Arrival port') ?></div>
        </td>
    </tr></table></div>
    <div class="cardfoot">
        <span class="fic">PASSENGERS</span> <b><?= $adults ?: 1 ?> adult<?= ($adults ?: 1) > 1 ? 's' : '' ?><?= $children > 0 ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?></b>
        <?php if ($vehicles > 0): ?> &nbsp;&middot;&nbsp; <span class="fic">VEHICLES</span> <b><?= $vehicles ?></b><?php endif; ?>
        <?php if ($pets > 0): ?> &nbsp;&middot;&nbsp; <span class="fic">PETS</span> <b><?= $pets ?></b><?php endif; ?>
    </div>
</div>

<div class="sec-h">Lead Passenger</div>
<table class="infotable"><tr>
    <td style="width:64%;"><div class="v"><?= $e($paxName ?: 'Passenger') ?></div></td>
    <td style="width:36%; text-align:right;"><span class="k">Status&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></span></td>
</tr></table>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Ferry Ticket', 'docSub' => 'Crossing Booking · Passenger Copy',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Ferry Booking Ref', 'refPrimaryValue' => $ref,
    'refPrimaryHint' => 'Show this at the port check-in',
    'managePath' => 'ferries/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'At the port',
    'guidanceItems' => [
        'Arrive at the port check-in at least 45 minutes before departure (earlier if travelling with a vehicle).',
        'Carry a printed or mobile copy of this ticket and a valid photo ID / passport for each passenger.',
        'Follow the operator&rsquo;s boarding instructions for foot passengers and vehicles.',
    ],
    'footerNote' => 'This ticket confirms your ferry booking. Prices and payment details appear on your separate invoice/receipt. The operator\'s conditions of carriage apply.',
]);
