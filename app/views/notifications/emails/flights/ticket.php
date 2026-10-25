<?php
/**
 * ============================================================================
 * FLIGHT TICKET — Itinerary Receipt / E-Ticket (NO PRICE)
 * ============================================================================
 * Premium, industry-standard itinerary receipt (IATA ITR conventions):
 *   - Price-free travel document (invoice carries price, payer-only).
 *   - Brand-aware: agent brand (if set) else GoGlobia; honours $wlPdfLogo +
 *     $agentBrandColors passed by GENERATE_BOOKING_PDF.
 *   - Dual labelled references: airline PNR vs GoGlobia Booking ID.
 *   - Per-segment timeline (big IATA codes, local times, duration, baggage,
 *     operated-by, Outbound/Return grouping).
 *   - QR -> Manage Booking (mpdf/qrcode if available, else text fallback).
 *     NOT a boarding pass / BCBP barcode.
 *   - Renders at BOTH lifecycle stages: ticketed (PNR set) and
 *     reserved-pending (pnr='' -> "Pending — issued on ticketing").
 *
 * Vars provided by GENERATE_BOOKING_PDF(): $booking, $bookingData, $settings,
 *   $businessName, $businessEmail, $businessPhone, $siteUrl, $wlPdfLogo,
 *   $agentBrandColors, $currency, $language, $bookingDate.
 * ============================================================================
 */
if (!isset($SECURE)) die('Direct access not permitted');

// ---- Brand (agent custom brand if set, else GoGlobia defaults) -------------
$brandPrimary = '#0058e6';
$brandDeep    = '#104bcb';
if (!empty($agentBrandColors['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $agentBrandColors['primary'])) {
    $brandPrimary = $agentBrandColors['primary'];
    $brandDeep    = $agentBrandColors['primary'];
}
$ink = '#0a1a3a'; $slate = '#5b6b8c'; $slate2 = '#8793ad';
$hair = '#e4e9f2'; $panel = '#f5f7fb';
$okC = '#0b875b'; $okBg = '#e8f6ef'; $pendC = '#b4690e'; $pendBg = '#fdf3e3';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

// ---- References -------------------------------------------------------------
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$manageUrl = rtrim((string) ($siteUrl ?: 'https://goglobia.com'), '/') . '/flights/booking/' . rawurlencode($invoiceId);

// ---- Passengers (travellers JSON; fall back to lead contact) ----------------
$travellers = [];
if (!empty($booking['travellers'])) {
    $t = json_decode((string) $booking['travellers'], true);
    if (is_array($t)) { $travellers = isset($t[0]) ? $t : array_values($t); }
}
if (empty($travellers)) {
    $travellers = [[
        'first_name' => $booking['first_name'] ?? '',
        'last_name'  => $booking['last_name'] ?? '',
        'type'       => 'ADT',
    ]];
}

// ---- Segments: handle every real shape --------------------------------------
// Real data has (a) flight_data.routes[].segments[], (b) flight_data.segments[]
// + returnSegments[], and (c) a flat flight_data. Group into labelled legs.
$fd = $bookingData['flight_data'] ?? $bookingData ?? [];
$legs = []; // [ ['label'=>'Outbound','segments'=>[...]], ... ]

if (!empty($fd['routes']) && is_array($fd['routes'])) {
    foreach ($fd['routes'] as $i => $route) {
        $segs = $route['segments'] ?? [];
        if ($segs) { $legs[] = ['label' => $i === 0 ? 'Outbound' : ($i === 1 ? 'Return' : 'Leg ' . ($i + 1)), 'segments' => $segs]; }
    }
} else {
    if (!empty($fd['segments']) && is_array($fd['segments'])) {
        $legs[] = ['label' => 'Outbound', 'segments' => $fd['segments']];
    }
    if (!empty($fd['returnSegments']) && is_array($fd['returnSegments'])) {
        $legs[] = ['label' => 'Return', 'segments' => $fd['returnSegments']];
    }
    if (empty($legs)) {
        // Flat single-segment fallback (oldest shape).
        $legs[] = ['label' => 'Flight', 'segments' => [[
            'airline'          => $fd['airline'] ?? ($fd['airline_name'] ?? 'Airline'),
            'flight_no'        => $fd['flight_no'] ?? ($fd['flight_number'] ?? ''),
            'class'            => $fd['class'] ?? '',
            'baggage'          => $fd['baggage'] ?? '',
            'cabin_baggage'    => $fd['cabin_baggage'] ?? '',
            'departure_code'   => $fd['departure_code'] ?? ($fd['from_airport'] ?? ''),
            'arrival_code'     => $fd['arrival_code'] ?? ($fd['to_airport'] ?? ''),
            'departure_airport'=> $fd['departure_airport'] ?? '',
            'arrival_airport'  => $fd['arrival_airport'] ?? '',
            'departure_time'   => $fd['departure_time'] ?? '',
            'arrival_time'     => $fd['arrival_time'] ?? '',
            'departure_date'   => $fd['departure_date'] ?? '',
            'arrival_date'     => $fd['arrival_date'] ?? '',
            'duration_time'    => $fd['duration_time'] ?? ($fd['total_duration'] ?? ''),
        ]]];
    }
}

// Per-segment field reader tolerant of both key styles.
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
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: inter, sans-serif; color: <?= $ink ?>; font-size: 9pt; line-height: 1.35; }
    .top { border-bottom: 2.2pt solid <?= $brandPrimary ?>; padding-bottom: 10pt; margin-bottom: 12pt; }
    .top table { width: 100%; border-collapse: collapse; }
    .top td { border: none; vertical-align: top; }
    .logo-img { height: 30pt; }
    .logo-txt { font-size: 17pt; font-weight: bold; color: <?= $brandPrimary ?>; }
    .logo-txt .k { color: <?= $ink ?>; }
    .issuer { font-size: 7.5pt; color: <?= $slate ?>; line-height: 1.45; margin-top: 2pt; }
    .doctitle { font-size: 9pt; font-weight: bold; letter-spacing: 1pt; color: <?= $ink ?>; text-align: right; }
    .docsub { font-size: 7pt; color: <?= $slate2 ?>; text-align: right; margin-top: 1pt; }
    .chip { font-size: 7.5pt; font-weight: bold; padding: 2.5pt 7pt; border-radius: 8pt; }
    .chip-ok { color: <?= $okC ?>; background: <?= $okBg ?>; }
    .chip-pend { color: <?= $pendC ?>; background: <?= $pendBg ?>; }
    .refstrip { background: <?= $panel ?>; border: 0.5pt solid <?= $hair ?>; border-radius: 6pt; padding: 9pt 10pt; margin-bottom: 12pt; }
    .refstrip table { width: 100%; border-collapse: collapse; }
    .refstrip td { border: none; vertical-align: top; }
    .lbl { font-size: 6.5pt; font-weight: bold; letter-spacing: .6pt; color: <?= $slate ?>; }
    .val { font-size: 13pt; font-weight: bold; color: <?= $ink ?>; }
    .val-pending { font-size: 9pt; font-weight: bold; color: <?= $pendC ?>; }
    .hint { font-size: 6.6pt; color: <?= $slate2 ?>; }
    .qrfallback { font-size: 9pt; font-weight: bold; color: <?= $ink ?>; border: 0.8pt solid <?= $hair ?>; padding: 5pt; text-align: center; }
    .sec-h { font-size: 7.5pt; font-weight: bold; letter-spacing: 1pt; color: <?= $ink ?>; border-bottom: 0.5pt solid <?= $hair ?>; padding-bottom: 3pt; margin: 11pt 0 7pt; }
    .leg { font-size: 7pt; font-weight: bold; color: #fff; background: <?= $brandPrimary ?>; padding: 1.5pt 6pt; border-radius: 3pt; }
    .pax { width: 100%; border-collapse: collapse; margin-bottom: 4pt; border: 0.5pt solid <?= $hair ?>; border-radius: 6pt; }
    .pax td { border-bottom: 0.5pt solid <?= $hair ?>; padding: 8pt 11pt; vertical-align: middle; }
    .pax tr:last-child td { border-bottom: none; }
    .nm { font-size: 10pt; font-weight: bold; color: <?= $ink ?>; }
    .pmeta { font-size: 7pt; color: <?= $slate ?>; }
    .etkk { font-size: 6.5pt; color: <?= $slate2 ?>; letter-spacing: .5pt; }
    .etk { font-size: 8pt; color: <?= $ink ?>; font-weight: bold; }
    .etk-pending { font-size: 7.5pt; color: <?= $pendC ?>; font-weight: bold; }
    .seg { border: 0.5pt solid <?= $hair ?>; border-radius: 7pt; margin-bottom: 8pt; }
    .segtop { background: <?= $panel ?>; padding: 6pt 10pt; }
    .segtop table { width: 100%; border-collapse: collapse; }
    .segtop td { border: none; }
    .air { font-size: 10pt; font-weight: bold; color: <?= $ink ?>; }
    .fno { font-size: 8pt; color: <?= $slate ?>; }
    .cabin { font-size: 7pt; font-weight: bold; color: <?= $brandPrimary ?>; background: #e8f0ff; padding: 2pt 6pt; border-radius: 3pt; }
    .segbody { padding: 11pt 12pt; }
    .segbody table { width: 100%; border-collapse: collapse; }
    .segbody td { border: none; vertical-align: top; }
    .code { font-size: 23pt; font-weight: bold; color: <?= $ink ?>; }
    .time { font-size: 12pt; font-weight: bold; color: <?= $ink ?>; }
    .city { font-size: 8pt; color: <?= $slate ?>; }
    .date { font-size: 7.5pt; color: <?= $slate2 ?>; font-weight: bold; }
    .dur { font-size: 7.5pt; font-weight: bold; color: <?= $slate ?>; text-align: center; }
    .stops { font-size: 7pt; color: <?= $slate2 ?>; text-align: center; }
    .segfoot { border-top: 0.5pt dashed <?= $hair ?>; padding: 6pt 12pt; font-size: 7.5pt; color: <?= $slate ?>; }
    .segfoot b { color: <?= $ink ?>; }
    .fic { font-size: 6.5pt; font-weight: bold; letter-spacing: .5pt; color: <?= $slate2 ?>; }
    .opby { font-size: 7pt; color: <?= $pendC ?>; padding: 0 12pt 6pt; }
    .guide { background: #eef4ff; border: 0.5pt solid #cfe0ff; border-left: 3pt solid <?= $brandPrimary ?>; border-radius: 6pt; padding: 10pt 12pt; margin-top: 12pt; }
    .gt { font-size: 8.5pt; font-weight: bold; color: <?= $brandDeep ?>; margin-bottom: 4pt; }
    .guide ul { margin: 0; padding-left: 11pt; }
    .guide li { font-size: 7.8pt; color: #28406b; line-height: 1.5; }
    .foot { border-top: 0.5pt solid <?= $hair ?>; margin-top: 12pt; padding-top: 8pt; }
    .foot table { width: 100%; border-collapse: collapse; }
    .foot td { font-size: 6.8pt; color: <?= $slate2 ?>; line-height: 1.5; border: none; vertical-align: top; }
</style>
</head>
<body>
    <!-- Header -->
    <div class="top"><table><tr>
        <td style="width:62%;">
            <?php if (!empty($wlPdfLogo)): ?>
                <img src="<?= $e($wlPdfLogo) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
            <?php else: ?>
                <div class="logo-txt"><?= $e($businessName ?: 'GoGlobia') ?></div>
            <?php endif; ?>
            <div class="issuer">
                Issued by <?= $e($businessName ?: 'GoGlobia') ?>
                <?php if (!empty($businessEmail)): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?>
                <?php if (!empty($businessPhone)): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?>
            </div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="doctitle">ITINERARY RECEIPT</div>
            <div class="docsub">Electronic Ticket &middot; Passenger Copy</div>
            <div style="margin-top:5pt;">
                <?php if ($isTicketed): ?>
                    <span class="chip chip-ok">&#9679; Ticketed &middot; Confirmed</span>
                <?php else: ?>
                    <span class="chip chip-pend">&#9679; Reserved &middot; Ticket pending</span>
                <?php endif; ?>
            </div>
        </td>
    </tr></table></div>

    <!-- Reference strip + QR -->
    <div class="refstrip"><table><tr>
        <td style="width:30%;">
            <div class="lbl">AIRLINE BOOKING REF (PNR)</div>
            <?php if ($isTicketed): ?>
                <div class="val"><?= $e($pnr) ?></div>
                <div class="hint">Use this to check in with the airline</div>
            <?php else: ?>
                <div class="val-pending">Pending &mdash; issued on ticketing</div>
                <div class="hint">You&rsquo;ll receive it once the airline tickets your booking</div>
            <?php endif; ?>
        </td>
        <td style="width:30%;">
            <div class="lbl"><?= $e(strtoupper($businessName ?: 'GoGlobia')) ?> BOOKING ID</div>
            <div class="val"><?= $e($invoiceId) ?></div>
            <div class="hint">Quote for support &amp; changes</div>
        </td>
        <td style="width:18%;">
            <div class="lbl">ISSUED</div>
            <div class="val" style="font-size:9pt;"><?= $e($bookingDate) ?></div>
        </td>
        <td style="width:22%; text-align:center;">
            <?php
            // QR -> Manage Booking. mpdf/qrcode on prod; graceful text fallback.
            if (class_exists('Mpdf\\QrCode\\QrCode')) {
                echo '<barcode code="' . $e($manageUrl) . '" type="QR" error="M" disableborder="1" />';
            } else {
                echo '<div class="qrfallback">' . $e($invoiceId) . '</div>';
            }
            ?>
            <div class="hint" style="text-align:center;">Scan to Manage Booking</div>
        </td>
    </tr></table></div>

    <!-- Passengers (one row each — mPDF-reliable bordered rows) -->
    <div class="sec-h">PASSENGERS</div>
    <table class="pax">
        <?php foreach ($travellers as $p):
            $fn = strtoupper(trim((string) ($p['first_name'] ?? '')));
            $ln = strtoupper(trim((string) ($p['last_name'] ?? '')));
            $ttl = strtoupper(trim((string) ($p['title'] ?? '')));
            $name = trim($ln . ' / ' . $fn . ($ttl ? ' ' . $ttl : ''), ' /');
            $ptype = strtoupper((string) ($p['type'] ?? $p['passenger_type'] ?? 'ADT'));
            $nat = (string) ($p['nationality_name'] ?? $p['nationality'] ?? '');
            $etk = trim((string) ($p['eticket'] ?? $p['ticket_number'] ?? ''));
        ?>
        <tr>
            <td style="width:64%;">
                <div class="nm"><?= $e($name ?: 'Passenger') ?></div>
                <div class="pmeta"><?= $e($ptype) ?><?= $nat ? ' &middot; ' . $e($nat) : '' ?></div>
            </td>
            <td style="width:36%; text-align:right;">
                <span class="etkk">E-TICKET&nbsp;&nbsp;</span>
                <?php if ($etk !== ''): ?>
                    <span class="etk"><?= $e($etk) ?></span>
                <?php else: ?>
                    <span class="etk-pending">On ticketing</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- Itinerary -->
    <?php foreach ($legs as $leg):
        $segs = $leg['segments'];
        if (empty($segs)) continue;
        $firstS = $segs[0];
        $lastS  = $segs[count($segs) - 1];
        $routeFrom = $segVal($firstS, ['departure_code', 'from_airport'], '');
        $routeTo   = $segVal($lastS, ['arrival_code', 'to_airport'], '');
    ?>
    <div class="sec-h">
        <span class="leg"><?= $e(strtoupper($leg['label'])) ?></span>
        &nbsp; <?= $e($routeFrom) ?> &rarr; <?= $e($routeTo) ?>
    </div>
    <?php foreach ($segs as $si => $s):
        $airline = $segVal($s, ['airline', 'airline_name'], 'Airline');
        $flightNo = $segVal($s, ['flight_no', 'flight_number'], '');
        $cabin = $segVal($s, ['class', 'cabin'], '');
        $classCode = $segVal($s, ['booking_class', 'class_code'], '');
        $operatedBy = $segVal($s, ['operating_carrier', 'operating', 'operated_by'], '');
        $depCode = $segVal($s, ['departure_code', 'from_airport'], '--');
        $arrCode = $segVal($s, ['arrival_code', 'to_airport'], '--');
        $depName = $segVal($s, ['departure_airport', 'from_airport_name'], '');
        $arrName = $segVal($s, ['arrival_airport', 'to_airport_name'], '');
        $depTime = $segVal($s, ['departure_time'], '');
        $arrTime = $segVal($s, ['arrival_time'], '');
        $depDate = $segVal($s, ['departure_date'], '');
        $arrDate = $segVal($s, ['arrival_date'], '');
        $dur = $segVal($s, ['duration_time', 'total_duration', 'duration'], '');
        $bag = $segVal($s, ['baggage', 'included_baggage'], '');
        $cabinBag = $segVal($s, ['cabin_baggage'], '');
        $showOp = $operatedBy !== '' && stripos((string) $operatedBy, (string) $airline) === false
                  && strtoupper((string) $operatedBy) !== strtoupper((string) $airline);
    ?>
    <div class="seg">
        <div class="segtop"><table><tr>
            <td class="air"><?= $e($airline) ?><?php if ($flightNo !== ''): ?> <span class="fno"><?= $e($airlineCode($airline) . ' ' . ltrim((string) $flightNo, '0')) ?></span><?php endif; ?></td>
            <td style="text-align:right;"><?php if ($cabin !== ''): ?><span class="cabin"><?= $e(strtoupper($cabin)) ?><?= $classCode ? ' &middot; ' . $e(strtoupper($classCode)) : '' ?></span><?php endif; ?></td>
        </tr></table></div>
        <div class="segbody"><table><tr>
            <td style="width:38%;">
                <div class="code"><?= $e($depCode) ?></div>
                <?php if ($depTime !== ''): ?><div class="time"><?= $e($depTime) ?></div><?php endif; ?>
                <?php if ($depDate !== ''): ?><div class="date"><?= $e($depDate) ?></div><?php endif; ?>
                <?php if ($depName !== ''): ?><div class="city"><?= $e($depName) ?></div><?php endif; ?>
            </td>
            <td style="width:24%;">
                <?php if ($dur !== ''): ?><div class="dur"><?= $e($dur) ?></div><?php endif; ?>
                <div class="stops">&#9992; <?= count($segs) > 1 ? $e((count($segs) - 1) . ' stop') : 'Direct' ?></div>
            </td>
            <td style="width:38%; text-align:right;">
                <div class="code"><?= $e($arrCode) ?></div>
                <?php if ($arrTime !== ''): ?><div class="time"><?= $e($arrTime) ?></div><?php endif; ?>
                <?php if ($arrDate !== ''): ?><div class="date"><?= $e($arrDate) ?></div><?php endif; ?>
                <?php if ($arrName !== ''): ?><div class="city"><?= $e($arrName) ?></div><?php endif; ?>
            </td>
        </tr></table></div>
        <?php if ($showOp): ?><div class="opby">Operated by <?= $e($operatedBy) ?></div><?php endif; ?>
        <div class="segfoot">
            <?php if ($bag !== ''): ?><span class="fic">CHECKED</span> <b><?= $e($bag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
            <?php if ($cabinBag !== ''): ?><span class="fic">CABIN</span> <b><?= $e($cabinBag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
            <span class="fic">STATUS</span> <b><?= $isTicketed ? 'OK / Confirmed' : 'Reserved' ?></b>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- Guidance -->
    <div class="guide">
        <?php if ($isTicketed): ?>
        <div class="gt">Before you fly &mdash; this is your itinerary receipt, not a boarding pass</div>
        <ul>
            <li>Check in online with the operating airline using PNR <b><?= $e($pnr) ?></b>, or at the airport desk, to get your boarding pass.</li>
            <li>Bring a printed or mobile copy of this receipt and a valid passport / ID matching the passenger name.</li>
            <li>Arrive at least <b>2 hours</b> (domestic) / <b>3 hours</b> (international) before departure.</li>
        </ul>
        <?php else: ?>
        <div class="gt">What happens next</div>
        <ul>
            <li>Your seats are <b>reserved</b>. Once the airline issues your ticket, we&rsquo;ll email an updated receipt with your <b>PNR</b> and <b>e-ticket numbers</b>.</li>
            <li>Keep your Booking ID <b><?= $e($invoiceId) ?></b> for any questions in the meantime.</li>
            <li>This document is an itinerary receipt, <b>not a boarding pass</b>. Online check-in opens after ticketing.</li>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="foot"><table><tr>
        <td style="width:64%;">This document confirms your reservation<?= $isTicketed ? ' and ticketing' : '' ?>. Carriage is subject to the operating airline&rsquo;s Conditions of Carriage and fare rules. Fare and payment details appear on your separate invoice/receipt. Schedules may change &mdash; reconfirm with the airline before travel.</td>
        <td style="width:36%; text-align:right;"><b><?= $e($businessName ?: 'GoGlobia') ?></b><br><?php if (!empty($businessEmail)): ?><?= $e($businessEmail) ?><br><?php endif; ?>Generated <?= $e(date('d M Y, H:i')) ?></td>
    </tr></table></div>
</body>
</html>
