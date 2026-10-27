<?php
/**
 * FLIGHT TICKET — Itinerary Receipt / E-Ticket (NO PRICE)
 * Premium IATA itinerary-receipt. Uses the shared ticket base for the brand
 * header, dual-reference strip + QR, guidance and footer; builds the flight
 * itinerary body here. Renders at both lifecycle stages (ticketed vs reserved).
 * Vars from GENERATE_BOOKING_PDF: $booking,$bookingData,$settings,$businessName,
 *   $businessEmail,$businessPhone,$siteUrl,$wlPdfLogo,$agentBrandColors,
 *   $currency,$language,$bookingDate.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$invoiceId = (string) ($booking['invoice_id'] ?? '');

// ---- Passengers -------------------------------------------------------------
$travellers = [];
if (!empty($booking['travellers'])) {
    $t = json_decode((string) $booking['travellers'], true);
    if (is_array($t)) { $travellers = isset($t[0]) ? $t : array_values($t); }
}
if (empty($travellers)) {
    $travellers = [['first_name' => $booking['first_name'] ?? '', 'last_name' => $booking['last_name'] ?? '', 'type' => 'ADT']];
}

// ---- Segments: every real shape (routes[].segments / segments+returnSegments / flat) ----
$fd = $bookingData['flight_data'] ?? $bookingData ?? [];
$legs = [];
if (!empty($fd['routes']) && is_array($fd['routes'])) {
    foreach ($fd['routes'] as $i => $route) {
        $segs = $route['segments'] ?? [];
        if ($segs) { $legs[] = ['label' => $i === 0 ? 'Outbound' : ($i === 1 ? 'Return' : 'Leg ' . ($i + 1)), 'segments' => $segs]; }
    }
} else {
    if (!empty($fd['segments']) && is_array($fd['segments'])) { $legs[] = ['label' => 'Outbound', 'segments' => $fd['segments']]; }
    if (!empty($fd['returnSegments']) && is_array($fd['returnSegments'])) { $legs[] = ['label' => 'Return', 'segments' => $fd['returnSegments']]; }
    if (empty($legs)) {
        $legs[] = ['label' => 'Flight', 'segments' => [[
            'airline' => $fd['airline'] ?? ($fd['airline_name'] ?? 'Airline'),
            'flight_no' => $fd['flight_no'] ?? ($fd['flight_number'] ?? ''),
            'class' => $fd['class'] ?? '', 'baggage' => $fd['baggage'] ?? '', 'cabin_baggage' => $fd['cabin_baggage'] ?? '',
            'departure_code' => $fd['departure_code'] ?? ($fd['from_airport'] ?? ''),
            'arrival_code' => $fd['arrival_code'] ?? ($fd['to_airport'] ?? ''),
            'departure_airport' => $fd['departure_airport'] ?? '', 'arrival_airport' => $fd['arrival_airport'] ?? '',
            'departure_time' => $fd['departure_time'] ?? '', 'arrival_time' => $fd['arrival_time'] ?? '',
            'departure_date' => $fd['departure_date'] ?? '', 'arrival_date' => $fd['arrival_date'] ?? '',
            'duration_time' => $fd['duration_time'] ?? ($fd['total_duration'] ?? ''),
        ]]];
    }
}
$segVal = function (array $s, array $keys, $default = '') {
    foreach ($keys as $k) { if (isset($s[$k]) && $s[$k] !== '') { return $s[$k]; } }
    return $default;
};
$airlineCode = function ($name) {
    $name = trim((string) $name);
    if ($name === '') return '--';
    $parts = preg_split('/\s+/', $name);
    return strtoupper(substr($parts[0], 0, 2));
};

// ---- Build the body HTML ----------------------------------------------------
ob_start();
?>
<div class="sec-h">Passengers</div>
<table class="infotable">
    <?php foreach ($travellers as $pax):
        $fn = strtoupper(trim((string) ($pax['first_name'] ?? '')));
        $ln = strtoupper(trim((string) ($pax['last_name'] ?? '')));
        $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
        $name = trim($ln . ' / ' . $fn . ($ttl ? ' ' . $ttl : ''), ' /');
        $ptype = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
        $nat = (string) ($pax['nationality_name'] ?? $pax['nationality'] ?? '');
        $etk = trim((string) ($pax['eticket'] ?? $pax['ticket_number'] ?? ''));
    ?>
    <tr>
        <td style="width:64%;">
            <div class="v"><?= $e($name ?: 'Passenger') ?></div>
            <div class="vs"><?= $e($ptype) ?><?= $nat ? ' &middot; ' . $e($nat) : '' ?></div>
        </td>
        <td style="width:36%; text-align:right;">
            <span class="k">E-Ticket&nbsp;&nbsp;</span>
            <?php if ($etk !== ''): ?><span class="v" style="font-size:8pt;"><?= $e($etk) ?></span>
            <?php else: ?><span style="font-size:7.5pt;font-weight:bold;color:#b4690e;">On ticketing</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php foreach ($legs as $leg):
    $segs = $leg['segments']; if (empty($segs)) continue;
    $routeFrom = $segVal($segs[0], ['departure_code', 'from_airport'], '');
    $routeTo = $segVal($segs[count($segs) - 1], ['arrival_code', 'to_airport'], '');
?>
<div class="sec-h"><span class="leg"><?= $e(strtoupper($leg['label'])) ?></span> &nbsp; <?= $e($routeFrom) ?> &rarr; <?= $e($routeTo) ?></div>
<?php foreach ($segs as $s):
    $airline = $segVal($s, ['airline', 'airline_name'], 'Airline');
    $flightNo = $segVal($s, ['flight_no', 'flight_number'], '');
    $cabin = $segVal($s, ['class', 'cabin'], '');
    $classCode = $segVal($s, ['booking_class', 'class_code'], '');
    $operatedBy = $segVal($s, ['operating_carrier', 'operating', 'operated_by'], '');
    $depCode = $segVal($s, ['departure_code', 'from_airport'], '--');
    $arrCode = $segVal($s, ['arrival_code', 'to_airport'], '--');
    $depName = $segVal($s, ['departure_airport', 'from_airport_name'], '');
    $arrName = $segVal($s, ['arrival_airport', 'to_airport_name'], '');
    $depTime = $segVal($s, ['departure_time'], ''); $arrTime = $segVal($s, ['arrival_time'], '');
    $depDate = $segVal($s, ['departure_date'], ''); $arrDate = $segVal($s, ['arrival_date'], '');
    $dur = $segVal($s, ['duration_time', 'total_duration', 'duration'], '');
    $bag = $segVal($s, ['baggage', 'included_baggage'], '');
    $cabinBag = $segVal($s, ['cabin_baggage'], '');
    $showOp = $operatedBy !== '' && stripos((string) $operatedBy, (string) $airline) === false
              && strtoupper((string) $operatedBy) !== strtoupper((string) $airline);
?>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($airline) ?><?php if ($flightNo !== ''): ?> <span class="card-sub"><?= $e($airlineCode($airline) . ' ' . ltrim((string) $flightNo, '0')) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><?php if ($cabin !== ''): ?><span class="tag"><?= $e(strtoupper($cabin)) ?><?= $classCode ? ' &middot; ' . $e(strtoupper($classCode)) : '' ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:38%;">
            <div class="big"><?= $e($depCode) ?></div>
            <?php if ($depTime !== ''): ?><div class="mid"><?= $e($depTime) ?></div><?php endif; ?>
            <?php if ($depDate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($depDate) ?></div><?php endif; ?>
            <?php if ($depName !== ''): ?><div class="vs"><?= $e($depName) ?></div><?php endif; ?>
        </td>
        <td style="width:24%; text-align:center;">
            <?php if ($dur !== ''): ?><div class="k"><?= $e($dur) ?></div><?php endif; ?>
            <div class="vs">&#9992; <?= count($segs) > 1 ? $e((count($segs) - 1) . ' stop') : 'Direct' ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="big"><?= $e($arrCode) ?></div>
            <?php if ($arrTime !== ''): ?><div class="mid"><?= $e($arrTime) ?></div><?php endif; ?>
            <?php if ($arrDate !== ''): ?><div class="k" style="margin-top:2pt;"><?= $e($arrDate) ?></div><?php endif; ?>
            <?php if ($arrName !== ''): ?><div class="vs"><?= $e($arrName) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <?php if ($showOp): ?><div class="vs" style="padding:0 12pt 6pt;color:#b4690e;">Operated by <?= $e($operatedBy) ?></div><?php endif; ?>
    <div class="cardfoot">
        <?php if ($bag !== ''): ?><span class="fic">CHECKED</span> <b><?= $e($bag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <?php if ($cabinBag !== ''): ?><span class="fic">CABIN</span> <b><?= $e($cabinBag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <span class="fic">STATUS</span> <b><?= $isTicketed ? 'OK / Confirmed' : 'Reserved' ?></b>
    </div>
</div>
<?php endforeach; endforeach; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Render through the shared base ----------------------------------------
echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Itinerary Receipt', 'docSub' => 'Electronic Ticket · Passenger Copy',
    'statusOk' => $isTicketed,
    'statusOkText' => 'Ticketed · Confirmed', 'statusPendText' => 'Reserved · Ticket pending',
    'refPrimaryLabel' => 'Airline Booking Ref (PNR)',
    'refPrimaryValue' => $pnr,
    'refPrimaryHint' => 'Use this to check in with the airline',
    'managePath' => 'flights/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => $isTicketed ? 'Before you fly — this is your itinerary receipt, not a boarding pass' : 'What happens next',
    'guidanceItems' => $isTicketed ? [
        'Check in online with the operating airline using PNR <b>' . $e($pnr) . '</b>, or at the airport desk, to get your boarding pass.',
        'Bring a printed or mobile copy of this receipt and a valid passport / ID matching the passenger name.',
        'Arrive at least <b>2 hours</b> (domestic) / <b>3 hours</b> (international) before departure.',
    ] : [
        'Your seats are <b>reserved</b>. Once the airline issues your ticket, we&rsquo;ll email an updated receipt with your <b>PNR</b> and <b>e-ticket numbers</b>.',
        'Keep your Booking ID <b>' . $e($invoiceId) . '</b> for any questions in the meantime.',
        'This document is an itinerary receipt, <b>not a boarding pass</b>. Online check-in opens after ticketing.',
    ],
    'footerNote' => 'This document confirms your reservation' . ($isTicketed ? ' and ticketing' : '') . '. Carriage is subject to the operating airline\'s Conditions of Carriage and fare rules. Fare and payment details appear on your separate invoice/receipt. Schedules may change — reconfirm with the airline before travel.',
]);
