<?php
/**
 * FLIGHT TICKET — Itinerary Receipt / E-Ticket (NO PRICE) — premium, data-correct.
 *
 * Builds a boarding-pass-style e-ticket on the shared ticket base:
 *  - a HERO route band (true origin -> final destination, total journey time, stops);
 *  - per-segment cards with the REAL airline logo + correct IATA code + full airport
 *    names (via flight_ticket_helpers — no "--"/"Airline" placeholders, no code
 *    guessed from the airline name);
 *  - a layover strip between connecting segments (exact from raw ISO timestamps when
 *    present, local-time fallback otherwise);
 *  - one passenger row per traveller, each with their own e-ticket number;
 *  - both lifecycle states: ticketed (PNR + e-tickets, green seal) and reserved
 *    (amber "ticket pending").
 *
 * Vars from GENERATE_BOOKING_PDF: $booking,$bookingData,$settings,$businessName,
 *   $businessEmail,$businessPhone,$siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,
 *   $currency,$language,$bookingDate,  and $mpdf (the live mPDF instance, if passed).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';
require_once __DIR__ . '/../../../../lib/flight_ticket_helpers.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$db = $GLOBALS['db'] ?? null;
$mpdfInst = $mpdf ?? ($GLOBALS['mpdf'] ?? null); // for airline-logo imageVars registration

// ---- Passengers -------------------------------------------------------------
$travellers = [];
if (!empty($booking['travellers'])) {
    $t = json_decode((string) $booking['travellers'], true);
    if (is_array($t)) {
        // Accept {primary_guest:..,others:[..]} OR a flat list OR an assoc map.
        if (isset($t['primary_guest']) || isset($t['passengers'])) {
            if (isset($t['primary_guest']) && is_array($t['primary_guest'])) { $travellers[] = $t['primary_guest']; }
            foreach ((array) ($t['passengers'] ?? $t['others'] ?? []) as $px) { if (is_array($px)) { $travellers[] = $px; } }
        } else {
            $travellers = isset($t[0]) ? $t : array_values($t);
        }
    }
}
if (empty($travellers)) {
    $travellers = [['first_name' => $booking['first_name'] ?? '', 'last_name' => $booking['last_name'] ?? '', 'type' => 'ADT']];
}

// ---- Legs & segments: every real shape (routes[].segments / segments+returnSegments / flat) ----
$fd = $bookingData['flight_data'] ?? $bookingData ?? [];
$legs = [];
if (!empty($fd['routes']) && is_array($fd['routes'])) {
    foreach ($fd['routes'] as $i => $route) {
        $segs = $route['segments'] ?? [];
        if ($segs) { $legs[] = ['label' => $i === 0 ? 'Outbound' : ($i === 1 ? 'Return' : 'Leg ' . ($i + 1)), 'segments' => array_values($segs)]; }
    }
} else {
    if (!empty($fd['segments']) && is_array($fd['segments'])) { $legs[] = ['label' => 'Outbound', 'segments' => array_values($fd['segments'])]; }
    if (!empty($fd['returnSegments']) && is_array($fd['returnSegments'])) { $legs[] = ['label' => 'Return', 'segments' => array_values($fd['returnSegments'])]; }
    if (empty($legs)) {
        // A single flat flight (no nested segments) — synthesise one segment.
        $legs[] = ['label' => 'Flight', 'segments' => [[
            'airline' => $fd['airline'] ?? ($fd['airline_name'] ?? ''), 'img' => $fd['img'] ?? ($fd['airline_code'] ?? ''),
            'flight_no' => $fd['flight_no'] ?? ($fd['flight_number'] ?? ''),
            'class' => $fd['class'] ?? '', 'baggage' => $fd['baggage'] ?? '', 'cabin_baggage' => $fd['cabin_baggage'] ?? '',
            'departure_code' => $fd['departure_code'] ?? ($fd['from_airport'] ?? ''),
            'arrival_code' => $fd['arrival_code'] ?? ($fd['to_airport'] ?? ''),
            'departure_airport' => $fd['departure_airport'] ?? '', 'arrival_airport' => $fd['arrival_airport'] ?? '',
            'departure_time' => $fd['departure_time'] ?? '', 'arrival_time' => $fd['arrival_time'] ?? '',
            'departure_date' => $fd['departure_date'] ?? '', 'arrival_date' => $fd['arrival_date'] ?? '',
            'departure_iso' => $fd['departure_iso'] ?? '', 'arrival_iso' => $fd['arrival_iso'] ?? '',
            'duration_time' => $fd['duration_time'] ?? ($fd['total_duration'] ?? ''),
        ]]];
    }
}
$segVal = function (array $s, array $keys, $default = '') {
    foreach ($keys as $k) { if (isset($s[$k]) && $s[$k] !== '') { return $s[$k]; } }
    return $default;
};

// ---- HERO: the whole journey at a glance (first leg drives the top band) -----
$heroLeg = $legs[0]['segments'] ?? [];
$heroSum = ft_leg_summary($heroLeg);
$depAp = ft_airport_resolve($heroSum['from'], $db, $heroLeg[0] ?? []);
$arrAp = ft_airport_resolve($heroSum['to'], $db, $heroLeg[count($heroLeg) - 1] ?? []);
$heroDepTime = $segVal($heroLeg[0] ?? [], ['departure_time'], '');
$heroDepDate = $segVal($heroLeg[0] ?? [], ['departure_date'], '');
$heroArrTime = $segVal($heroLeg[count($heroLeg) - 1] ?? [], ['arrival_time'], '');
$heroArrDate = $segVal($heroLeg[count($heroLeg) - 1] ?? [], ['arrival_date'], '');
$stops = (int) $heroSum['stops'];
$stopText = $stops <= 0 ? 'Nonstop' : ($stops === 1 ? '1 stop' : $stops . ' stops');
if ($stops > 0 && !empty($heroSum['layovers'])) {
    $codes = array_values(array_filter(array_map(fn($l) => $l['code'] ?? '', $heroSum['layovers'])));
    if ($codes) { $stopText .= ' &middot; ' . implode(', ', array_map($e, $codes)); }
}
$totalDisp = $heroSum['total_display'];
if ($totalDisp !== '' && !$heroSum['total_exact']) { $totalDisp = '~' . $totalDisp; }
// Multi-leg (round trip): there are 2+ legs; the hero shows the outbound, but say so.
$isRoundish = count($legs) > 1;

ob_start();
?>
<div class="hero"><table><tr>
    <td style="width:37%;">
        <div class="port-code"><?= $e($heroSum['from'] ?: '—') ?></div>
        <?php if ($depAp['city'] !== ''): ?><div class="port-city"><?= $e($depAp['city']) ?></div><?php endif; ?>
        <?php if ($depAp['name'] !== ''): ?><div class="port-apt"><?= $e($depAp['name']) ?></div><?php endif; ?>
        <?php if ($heroDepTime !== ''): ?><div class="port-time"><?= $e($heroDepTime) ?></div><?php endif; ?>
        <?php if ($heroDepDate !== ''): ?><div class="port-date"><?= $e($heroDepDate) ?></div><?php endif; ?>
    </td>
    <td style="width:26%;" class="hero-mid">
        <?php if ($totalDisp !== ''): ?><div class="hero-dur"><?= $e($totalDisp) ?> total</div><?php endif; ?>
        <div class="hero-line"></div>
        <div class="hero-plane">&#9992;</div>
        <div class="hero-stops"><?= ($isRoundish ? 'Round trip &middot; ' : '') . $stopText ?></div>
    </td>
    <td style="width:37%; text-align:right;">
        <div class="port-code"><?= $e($heroSum['to'] ?: '—') ?></div>
        <?php if ($arrAp['city'] !== ''): ?><div class="port-city"><?= $e($arrAp['city']) ?></div><?php endif; ?>
        <?php if ($arrAp['name'] !== ''): ?><div class="port-apt"><?= $e($arrAp['name']) ?></div><?php endif; ?>
        <?php if ($heroArrTime !== ''): ?><div class="port-time"><?= $e($heroArrTime) ?></div><?php endif; ?>
        <?php if ($heroArrDate !== ''): ?><div class="port-date"><?= $e($heroArrDate) ?></div><?php endif; ?>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: passengers + per-leg segment cards + layovers --------------------
ob_start();
?>
<div class="sec-h">Passengers<?= count($travellers) > 1 ? ' &middot; ' . count($travellers) : '' ?></div>
<table class="infotable">
    <?php foreach ($travellers as $pax):
        $fn = strtoupper(trim((string) ($pax['first_name'] ?? '')));
        $ln = strtoupper(trim((string) ($pax['last_name'] ?? '')));
        $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
        $name = trim($ln . ' / ' . $fn, ' /');
        $ptypeRaw = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
        $ptype = ['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant', 'ADULT' => 'Adult', 'CHILD' => 'Child', 'INFANT' => 'Infant'][$ptypeRaw] ?? ucfirst(strtolower($ptypeRaw));
        $nat = (string) ($pax['nationality_name'] ?? $pax['nationality'] ?? '');
        $etk = trim((string) ($pax['eticket'] ?? $pax['ticket_number'] ?? ''));
    ?>
    <tr>
        <td style="width:62%;">
            <div class="v"><?= $e($name ?: 'Passenger') ?><?= $ttl ? ' <span class="vs">' . $e($ttl) . '</span>' : '' ?></div>
            <div class="vs"><?= $e($ptype) ?><?= $nat ? ' &middot; ' . $e($nat) : '' ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <span class="k">E-Ticket&nbsp;&nbsp;</span>
            <?php if ($etk !== ''): ?><span class="v" style="font-size:8.5pt;"><?= $e($etk) ?></span>
            <?php else: ?><span class="chip-pend" style="font-size:7.5pt;">On ticketing</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php foreach ($legs as $leg):
    $segs = $leg['segments']; if (empty($segs)) continue;
    $legSum = ft_leg_summary($segs);
    $legTot = $legSum['total_display'];
    if ($legTot !== '' && !$legSum['total_exact']) { $legTot = '~' . $legTot; }
    $legStops = (int) $legSum['stops'];
    $legStopText = $legStops <= 0 ? 'Nonstop' : ($legStops === 1 ? '1 stop' : $legStops . ' stops');
    $fromAp = ft_airport_resolve($legSum['from'], $db, $segs[0]);
    $toAp = ft_airport_resolve($legSum['to'], $db, $segs[count($segs) - 1]);
?>
<div class="sec-h" style="border:none; padding:0; margin:14pt 0 8pt;">
    <table style="width:100%; border-collapse:collapse;"><tr>
        <td style="border:none; width:66%;"><span class="leg"><?= $e(strtoupper($leg['label'])) ?></span>
            &nbsp;&nbsp;<span class="legsub"><?= $e(($fromAp['city'] ?: $legSum['from'])) ?> &rarr; <?= $e(($toAp['city'] ?: $legSum['to'])) ?></span></td>
        <td style="border:none; width:34%; text-align:right;"><span class="legtot"><?= $legTot !== '' ? $e($legTot) . ' &middot; ' : '' ?><?= $legStopText ?></span></td>
    </tr></table>
</div>
<?php foreach ($segs as $si => $s):
    $al = ft_airline_resolve($s, $db, $mpdfInst);
    $cabin = $segVal($s, ['class', 'cabin'], '');
    $classCode = $segVal($s, ['booking_class', 'class_code'], '');
    $operatedBy = $segVal($s, ['operating_carrier', 'operating', 'operated_by'], '');
    $depCode = strtoupper((string) $segVal($s, ['departure_code', 'from_airport'], ''));
    $arrCode = strtoupper((string) $segVal($s, ['arrival_code', 'to_airport'], ''));
    $dAp = ft_airport_resolve($depCode, $db, $s);
    $aAp = ft_airport_resolve($arrCode, $db, $s);
    $depTime = $segVal($s, ['departure_time'], ''); $arrTime = $segVal($s, ['arrival_time'], '');
    $depDate = $segVal($s, ['departure_date'], ''); $arrDate = $segVal($s, ['arrival_date'], '');
    // Segment duration: supplier-provided display, else computed from this segment.
    $segDur = (string) $segVal($s, ['duration_time', 'total_duration', 'duration'], '');
    if ($segDur === '') {
        $sm = ft_minutes_between(ft_seg_dep($s), ft_seg_arr($s));
        $segDur = ft_fmt_duration($sm['minutes']); if ($segDur !== '' && !$sm['exact']) { $segDur = '~' . $segDur; }
    }
    $bag = $segVal($s, ['baggage', 'included_baggage'], '');
    $cabinBag = $segVal($s, ['cabin_baggage'], '');
    $showOp = $operatedBy !== '' && stripos((string) $operatedBy, (string) $al['name']) === false
              && strtoupper((string) $operatedBy) !== strtoupper((string) $al['code']);
    // airport display line: full name + city when we resolved it, else bare code.
    $dLine = $dAp['name'] !== '' ? ($dAp['name'] . ($dAp['city'] ? ', ' . $dAp['city'] : '')) : '';
    $aLine = $aAp['name'] !== '' ? ($aAp['name'] . ($aAp['city'] ? ', ' . $aAp['city'] : '')) : '';
?>
<div class="card">
    <div class="card-top"><table><tr>
        <td style="width:7%;">
            <?php if ($al['logo_src'] !== ''): ?>
                <img src="<?= $e($al['logo_src']) ?>" class="al-logo" alt="<?= $e($al['name']) ?>">
            <?php else: ?>
                <div class="al-mono"><?= $e($al['monogram']) ?></div>
            <?php endif; ?>
        </td>
        <td style="width:63%; padding-left:8pt;">
            <div class="al-name"><?= $e($al['name']) ?></div>
            <?php if ($al['flight_no'] !== ''): ?><div class="al-fl"><?= $e($al['flight_no']) ?></div><?php endif; ?>
        </td>
        <td style="width:30%; text-align:right;">
            <?php if ($cabin !== ''): ?><span class="cabin"><?= $e(strtoupper($cabin)) ?><?= $classCode ? ' &middot; ' . $e(strtoupper($classCode)) : '' ?></span><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:38%;">
            <div class="seg-code"><?= $e($depCode ?: '—') ?></div>
            <?php if ($depTime !== ''): ?><div class="seg-time"><?= $e($depTime) ?></div><?php endif; ?>
            <?php if ($depDate !== ''): ?><div class="seg-date"><?= $e($depDate) ?></div><?php endif; ?>
            <?php if ($dLine !== ''): ?><div class="seg-apt"><?= $e($dLine) ?></div><?php endif; ?>
        </td>
        <td style="width:24%;" class="seg-mid">
            <?php if ($segDur !== ''): ?><div class="d"><?= $e($segDur) ?></div><?php endif; ?>
            <div class="l"></div>
            <div class="s">Direct</div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="seg-code"><?= $e($arrCode ?: '—') ?></div>
            <?php if ($arrTime !== ''): ?><div class="seg-time"><?= $e($arrTime) ?></div><?php endif; ?>
            <?php if ($arrDate !== ''): ?><div class="seg-date"><?= $e($arrDate) ?></div><?php endif; ?>
            <?php if ($aLine !== ''): ?><div class="seg-apt"><?= $e($aLine) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <?php if ($showOp): ?><div class="vs" style="padding:0 12pt 6pt;"><span class="chip-pend">Operated by <?= $e($operatedBy) ?></span></div><?php endif; ?>
    <div class="cardfoot">
        <?php if ($bag !== ''): ?><span class="fic">Checked</span> <b class="chip"><?= $e($bag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <?php if ($cabinBag !== ''): ?><span class="fic">Cabin</span> <b class="chip"><?= $e($cabinBag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <span class="fic">Status</span> <?php if ($isTicketed): ?><span class="chip-ok">Confirmed</span><?php else: ?><span class="chip-pend">On ticketing</span><?php endif; ?>
    </div>
</div>
<?php
    // LAYOVER strip — the connection AFTER this segment (not after the last one).
    if ($si < count($segs) - 1) {
        $lay = $legSum['layovers'][$si] ?? null;
        $layCode = $lay['code'] ?? $arrCode;
        $layAp = ft_airport_resolve((string) $layCode, $db, $s);
        $layWhere = $layAp['city'] !== '' ? $layAp['city'] : $layCode;
        $layDur = $lay && $lay['display'] !== '' ? ($lay['exact'] ? $lay['display'] : '~' . $lay['display']) : '';
        ?>
        <div class="layover"><span class="laytag">&#9203; <?= $layDur !== '' ? $e($layDur) . ' layover' : 'Connection' ?> in <?= $e($layWhere) ?><?= $layCode && $layWhere !== $layCode ? ' (' . $e($layCode) . ')' : '' ?> &middot; change of aircraft</span></div>
        <?php
    }
endforeach; endforeach; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Render through the shared base ----------------------------------------
echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Itinerary Receipt', 'docSub' => 'Electronic Ticket',
    'statusOk' => $isTicketed,
    'statusOkText' => 'Ticketed · Confirmed', 'statusPendText' => 'Reserved · Ticket pending',
    'heroHtml' => $heroHtml,
    'refPrimaryLabel' => 'Airline Ref (PNR)',
    'refPrimaryValue' => $pnr,
    'refPrimaryMono' => true,
    'refPrimaryHint' => 'Use this to check in with the airline',
    'managePath' => 'flights/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => $isTicketed ? 'Before you fly — this is your itinerary receipt, not a boarding pass' : 'What happens next',
    'guidanceItems' => $isTicketed ? array_values(array_filter([
        'Check in online with the operating airline using PNR <b>' . $e($pnr) . '</b>, or at the airport desk, to get your boarding pass.',
        ($stops > 0 ? 'You have a connection' . (!empty($heroSum['layovers'][0]['display']) ? ' with a <b>' . $e($heroSum['layovers'][0]['display']) . '</b> layover' : '') . ' — follow <b>Transfer / Connecting flights</b> signs; you may need to re-clear security and hold any required transit visa.' : ''),
        'Bring a printed or mobile copy of this receipt and a valid passport / ID matching the passenger name.',
        'Arrive at least <b>2 hours</b> (domestic) / <b>3 hours</b> (international) before departure.',
    ])) : [
        'Your seats are <b>reserved</b>. Once the airline issues your ticket, we&rsquo;ll email an updated receipt with your <b>PNR</b> and <b>e-ticket numbers</b>.',
        'Keep your Booking ID <b>' . $e($invoiceId) . '</b> for any questions in the meantime.',
        'This document is an itinerary receipt, <b>not a boarding pass</b>. Online check-in opens after ticketing.',
    ],
    'footerNote' => 'This document confirms your reservation' . ($isTicketed ? ' and ticketing' : '') . '. Carriage is subject to the operating airline\'s Conditions of Carriage and fare rules. Fare and payment details appear on your separate invoice/receipt. Schedules may change — reconfirm with the airline before travel.',
]);
