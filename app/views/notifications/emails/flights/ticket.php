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
require_once __DIR__ . '/../_ticket_icons.php';
require_once __DIR__ . '/../../../../lib/flight_ticket_helpers.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$db = $GLOBALS['db'] ?? null;
$mpdfInst = $mpdf ?? ($GLOBALS['mpdf'] ?? null); // for airline-logo imageVars registration

// ---- Passengers -------------------------------------------------------------
// The real passenger list (the people who fly) — resolved by ft_passenger_list(),
// which DROPS the booking's payer/contact (primary_guest) so the customer name is
// never shown as a passenger, and derives the type from the adult_N/child_N/infant_N
// key. The logic lives in the stable helper file (not inline here) so it can't be
// silently regressed in this template. See app/lib/flight_ticket_helpers.php.
$travellers = ft_passenger_list($booking);

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

// ---- Build the body HTML ----------------------------------------------------
ob_start();
?>
<style>
    /* Flight-ticket specifics layered on the shared base (matches the approved design). */
    /* dotmark badge: a TABLE CELL (not inline-block, which mPDF sizes unreliably) so
       the colored rounded square + centered letters render correctly. */
    .dotmark { width:22pt; height:22pt; background:#0058e6; color:#fff; font-size:9pt;
               font-weight:bold; border-radius:5pt; text-align:center; vertical-align:middle; letter-spacing:.5pt; }
    .pax-card { border:0.6pt solid #e4e9f2; border-radius:9pt; padding:9pt 11pt; }
    .pax-nm { font-size:11pt; font-weight:bold; color:#0a1a3a; }
    .pax-meta { font-size:8pt; color:#5b6b8c; margin-top:1pt; }
    .pax-etk-k { font-size:6.5pt; font-weight:bold; letter-spacing:.5pt; text-transform:uppercase; color:#8793ad; }
    .pax-etk-v { font-size:9pt; font-weight:bold; color:#0a1a3a; }
    .pax-etk-pend { font-size:8pt; font-weight:bold; color:#b4690e; }
    .cardfoot b { color:#0a1a3a; }
</style>
<div class="sec-h"><span class="leg-lbl">Passengers</span><?= count($travellers) > 1 ? ' &middot; ' . count($travellers) : '' ?></div>
<?php
// 2-up grid of boxed passenger cards (mPDF: a table, 2 cells per row, with a
// spacer column so the two cards don't touch).
$paxRows = array_chunk($travellers, 2);
$renderPax = function (?array $pax) use ($e) {
    if ($pax === null) { return '<div style="height:1px;"></div>'; }
    $fn = strtoupper(trim((string) ($pax['first_name'] ?? '')));
    $ln = strtoupper(trim((string) ($pax['last_name'] ?? '')));
    $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
    $name = trim($ln . ' / ' . $fn . ($ttl ? ' ' . $ttl : ''), ' /');
    $ptypeRaw = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
    $ptype = ['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant', 'ADULT' => 'Adult', 'CHILD' => 'Child', 'INFANT' => 'Infant'][$ptypeRaw] ?? ucfirst(strtolower($ptypeRaw));
    $nat = (string) ($pax['nationality_name'] ?? $pax['nationality'] ?? '');
    $etk = trim((string) ($pax['eticket'] ?? $pax['ticket_number'] ?? ''));
    $etkHtml = $etk !== ''
        ? '<span class="pax-etk-v">' . $e($etk) . '</span>'
        : '<span class="pax-etk-pend">On ticketing</span>';
    return '<div class="pax-card"><table style="width:100%;border-collapse:collapse;"><tr>'
        . '<td style="border:none;"><div class="pax-nm">' . $e($name ?: 'Passenger') . '</div>'
        . '<div class="pax-meta">' . $e($ptype) . ($nat ? ' &middot; ' . $e($nat) : '') . '</div></td>'
        . '<td style="border:none;text-align:right;vertical-align:top;"><div class="pax-etk-k">E-Ticket</div>' . $etkHtml . '</td>'
        . '</tr></table></div>';
};
?>
<table style="width:100%;border-collapse:collapse;">
<?php foreach ($paxRows as $row): ?>
    <tr>
        <td style="width:49%;border:none;vertical-align:top;"><?= $renderPax($row[0] ?? null) ?></td>
        <td style="width:2%;border:none;"></td>
        <td style="width:49%;border:none;vertical-align:top;"><?= $renderPax($row[1] ?? null) ?></td>
    </tr>
<?php endforeach; ?>
</table>

<?php foreach ($legs as $leg):
    $segs = array_values($leg['segments']); if (empty($segs)) continue;
    // Real origin->destination cities for the leg header + a layover summary.
    $legSum  = ft_leg_summary($segs);
    $fromAp  = ft_airport_resolve($legSum['from'], $db, $segs[0]);
    $toAp    = ft_airport_resolve($legSum['to'], $db, $segs[count($segs) - 1]);
    $legStops = (int) $legSum['stops'];
    $legStopText = $legStops <= 0 ? 'Direct' : ($legStops === 1 ? '1 stop' : $legStops . ' stops');
    $legTot = $legSum['total_display']; if ($legTot !== '' && !$legSum['total_exact']) { $legTot = '~' . $legTot; }
?>
<div class="sec-h"><span class="leg"><?= $e(strtoupper($leg['label'])) ?></span> &nbsp; <?= $e(($fromAp['city'] ?: $legSum['from'])) ?> &rarr; <?= $e(($toAp['city'] ?: $legSum['to'])) ?><?php if ($legTot !== '' || $legStops > 0): ?> &nbsp;<span style="font-weight:normal;color:#8793ad;"><?= $legTot !== '' ? $e($legTot) . ' &middot; ' : '' ?><?= $legStopText ?></span><?php endif; ?></div>
<?php foreach ($segs as $si => $s):
    // REAL airline name + IATA code + logo (fixes the old substr-of-name guess that
    // turned "British Airways" into "BR" and "Air Peace" into "AI").
    $al = ft_airline_resolve($s, $db, $mpdfInst);
    $cabin = $segVal($s, ['class', 'cabin'], '');
    $classCode = $segVal($s, ['booking_class', 'class_code'], '');
    $operatedBy = $segVal($s, ['operating_carrier', 'operating', 'operated_by'], '');
    $depCode = strtoupper((string) $segVal($s, ['departure_code', 'from_airport'], '--'));
    $arrCode = strtoupper((string) $segVal($s, ['arrival_code', 'to_airport'], '--'));
    $dAp = ft_airport_resolve($depCode, $db, $s);
    $aAp = ft_airport_resolve($arrCode, $db, $s);
    $depName = $dAp['name'] !== '' ? $dAp['name'] : (string) $segVal($s, ['departure_airport', 'from_airport_name'], '');
    $arrName = $aAp['name'] !== '' ? $aAp['name'] : (string) $segVal($s, ['arrival_airport', 'to_airport_name'], '');
    $depTime = $segVal($s, ['departure_time'], ''); $arrTime = $segVal($s, ['arrival_time'], '');
    $depDate = $segVal($s, ['departure_date'], ''); $arrDate = $segVal($s, ['arrival_date'], '');
    // Per-segment duration (this flight only); a single segment is always Direct.
    $dur = (string) $segVal($s, ['duration_time', 'total_duration', 'duration'], '');
    if ($dur === '') {
        $sm = ft_minutes_between(ft_seg_dep($s), ft_seg_arr($s));
        $dur = ft_fmt_duration($sm['minutes']); if ($dur !== '' && !$sm['exact']) { $dur = '~' . $dur; }
    }
    $bag = $segVal($s, ['baggage', 'included_baggage'], '');
    $cabinBag = $segVal($s, ['cabin_baggage'], '');
    $showOp = $operatedBy !== '' && stripos((string) $operatedBy, (string) $al['name']) === false
              && strtoupper((string) $operatedBy) !== strtoupper((string) $al['code']);
?>
<div class="card">
    <div class="card-top"><table><tr>
        <td style="width:30pt;">
            <?php
            // Prefer a LOCAL airline logo (crisp, registered via imageVars as
            // var:airline_XX). Fall back to the brand "DA"-style dotmark badge for
            // everything else — a remote avs.io logo renders blurry/wrong in mPDF, so
            // we DON'T use it here; the clean lettered badge matches the design.
            $useLocalLogo = ($al['logo_src'] !== '' && strpos($al['logo_src'], 'var:airline_') === 0);
            ?>
            <?php if ($useLocalLogo): ?>
                <img src="<?= $e($al['logo_src']) ?>" style="width:22pt;height:22pt;" alt="">
            <?php else: ?>
                <table style="border-collapse:collapse;"><tr><td class="dotmark"><?= $e($al['monogram']) ?></td></tr></table>
            <?php endif; ?>
        </td>
        <td class="card-title" style="padding-left:8pt;"><?= $e($al['name']) ?><?php if ($al['flight_no'] !== ''): ?> <span class="card-sub"><?= $e($al['flight_no']) ?></span><?php endif; ?></td>
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
            <?php if ($dur !== ''): ?><div class="k" style="margin-bottom:2pt;"><?= $e($dur) ?></div><?php endif; ?>
            <div><?= ft_connector('#0058e6', 118) ?></div>
            <div class="vs" style="margin-top:1pt;">Non-stop</div>
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
        <?php if ($bag !== ''): ?><?= ft_icon('checked', '#0058e6', 12) ?> <span class="fic">Checked</span> <b><?= $e($bag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <?php if ($cabinBag !== ''): ?><?= ft_icon('cabin', '#0058e6', 12) ?> <span class="fic">Cabin</span> <b><?= $e($cabinBag) ?></b> &nbsp;&nbsp;&nbsp;<?php endif; ?>
        <?= ft_icon('clock', ($isTicketed ? '#0b875b' : '#b4690e'), 12) ?> <span class="fic">Status</span> <b><?= $isTicketed ? 'OK / Confirmed' : 'Reserved' ?></b>
    </div>
</div>
<?php
    // Layover line between connecting segments (real connection time from the data).
    if ($si < count($segs) - 1) {
        $lay = $legSum['layovers'][$si] ?? null;
        $layCode = $lay['code'] ?? $arrCode;
        $layAp = ft_airport_resolve((string) $layCode, $db, $s);
        $layWhere = $layAp['city'] !== '' ? $layAp['city'] : $layCode;
        $layDur = $lay && $lay['display'] !== '' ? ($lay['exact'] ? $lay['display'] : '~' . $lay['display']) : '';
        ?>
        <div class="vs" style="padding:2pt 0 6pt 2pt;color:#b4690e;"><?= ft_icon('clock', '#b4690e', 11) ?> <b><?= $layDur !== '' ? $e($layDur) . ' layover' : 'Connection' ?></b> in <?= $e($layWhere) ?><?= $layCode && $layWhere !== $layCode ? ' (' . $e($layCode) . ')' : '' ?></div>
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
