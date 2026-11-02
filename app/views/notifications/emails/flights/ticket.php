<?php
/**
 * FLIGHT TICKET — Itinerary Receipt / E-Ticket (NO PRICE).
 *
 * Rebuilt DIRECTLY from the owner's approved HTML design (the "GoGlobia Flight
 * E-Ticket — Design Preview"): same palette, type scale, spacing, boxed passenger
 * grid, segment cards with a blue airline dotmark + a drawn connector line + an
 * icon baggage/status foot, layover row, guidance box and footer. Converted to
 * mPDF-safe table layout (mPDF has no flexbox/grid/::before). Self-contained — it
 * does NOT use the shared _ticket_base; it renders the whole document so it matches
 * the design 1:1.
 *
 * Vars from GENERATE_BOOKING_PDF: $booking,$bookingData,$settings,$businessName,
 *   $businessEmail,$businessPhone,$siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,
 *   $currency,$language,$bookingDate, (+ $mpdf when available).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_icons.php';
require_once __DIR__ . '/../../../../lib/flight_ticket_helpers.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$db = $GLOBALS['db'] ?? null;
$mpdfInst = $mpdf ?? ($GLOBALS['mpdf'] ?? null);

// Agent/white-label brand accent (defaults to the design's brand blue).
$brand = '#0058e6';
if (!empty($agentBrandColors['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $agentBrandColors['primary'])) {
    $brand = (string) $agentBrandColors['primary'];
}

// Logo src resolved by GENERATE_BOOKING_PDF (var:pdflogo) — else platform PNG.
$pdfLogoSrcV = (string) ($pdfLogoSrc ?? '');
$logoSrc = $pdfLogoSrcV !== '' ? $pdfLogoSrcV
    : (!empty($wlPdfLogo) ? (string) $wlPdfLogo
       : (defined('uploads') && is_file(uploads . 'global/logo.png') ? uploads . 'global/logo.png' : ''));

$businessName  = $businessName ?: 'GoGlobia';
$siteUrl       = rtrim((string) ($siteUrl ?: 'https://goglobia.com'), '/');
$manageUrl     = $siteUrl . '/flights/booking/' . rawurlencode($invoiceId);

// ---- Passengers (ft_passenger_list drops the non-flyer payer key) ------------
$travellers = ft_passenger_list($booking);
$paxCount   = count($travellers);

// ---- Cabin + legs/segments --------------------------------------------------
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
    if (empty($legs) && (!empty($fd['departure_code']) || !empty($fd['from_airport']))) {
        $legs[] = ['label' => 'Flight', 'segments' => [[
            'airline' => $fd['airline'] ?? '', 'img' => $fd['img'] ?? ($fd['airline_code'] ?? ''), 'flight_no' => $fd['flight_no'] ?? ($fd['flight_number'] ?? ''),
            'class' => $fd['class'] ?? '', 'booking_class' => $fd['booking_class'] ?? '',
            'departure_code' => $fd['departure_code'] ?? ($fd['from_airport'] ?? ''), 'arrival_code' => $fd['arrival_code'] ?? ($fd['to_airport'] ?? ''),
            'departure_time' => $fd['departure_time'] ?? '', 'arrival_time' => $fd['arrival_time'] ?? '',
            'departure_date' => $fd['departure_date'] ?? '', 'arrival_date' => $fd['arrival_date'] ?? '',
            'departure_airport' => $fd['departure_airport'] ?? '', 'arrival_airport' => $fd['arrival_airport'] ?? '',
            'departure_iso' => $fd['departure_iso'] ?? '', 'arrival_iso' => $fd['arrival_iso'] ?? '',
        ]]];
    }
}
$segVal = function (array $s, array $keys, $default = '') {
    foreach ($keys as $k) { if (isset($s[$k]) && $s[$k] !== '') { return $s[$k]; } }
    return $default;
};
// Overall cabin for the ref-strip hint ("Economy · N passengers").
$cabinOverall = '';
if (!empty($legs[0]['segments'][0])) { $cabinOverall = (string) $segVal($legs[0]['segments'][0], ['class', 'cabin'], ''); }

// ---- A localized month/weekday is already in the stored date strings; emit as-is.
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    /* ===== palette + type (exact values from the approved design) ===== */
    body { font-family: inter, sans-serif; color: #0a1a3a; font-size: 10pt; line-height: 1.4; }
    table { border-collapse: collapse; }
    td { vertical-align: top; }

    /* header band */
    .top { border-bottom: 2.4pt solid <?= $brand ?>; padding-bottom: 12pt; margin-bottom: 4pt; }
    .top-tbl { width: 100%; } .top-tbl td { border: none; }
    .logo-img { height: 26pt; width: auto; max-width: 160pt; }
    .logo-txt { font-size: 18pt; font-weight: bold; color: <?= $brand ?>; letter-spacing: -0.3pt; }
    .logo-txt span { color: #0a1a3a; }
    .issuer { font-size: 7.6pt; color: #5b6b8c; line-height: 1.5; margin-top: 4pt; }
    .doctitle { font-size: 9.5pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; color: #0a1a3a; text-align: right; }
    .docsub { font-size: 7.6pt; color: #8793ad; letter-spacing: .4pt; text-align: right; margin-top: 2pt; }
    .chip { font-size: 8pt; font-weight: bold; letter-spacing: .2pt; padding: 3pt 10pt; border-radius: 8pt; }
    .chip-ok { color: #0b875b; background: #e8f6ef; }
    .chip-pend { color: #b4690e; background: #fdf3e3; }

    /* reference + QR strip */
    .refstrip { background: #f5f7fb; border: 0.5pt solid #e4e9f2; border-radius: 9pt; padding: 13pt 14pt; margin-top: 14pt; }
    .refstrip-tbl { width: 100%; } .refstrip-tbl td { border: none; }
    .lbl { font-size: 6.8pt; font-weight: bold; letter-spacing: .7pt; text-transform: uppercase; color: #5b6b8c; }
    .val { font-family: "dejavusansmono", monospace; font-size: 14pt; font-weight: bold; color: #0a1a3a; letter-spacing: .4pt; }
    .val-pending { font-size: 10pt; font-weight: bold; color: #b4690e; }
    .hint { font-size: 7pt; color: #8793ad; margin-top: 1pt; }
    .qr-cap { font-size: 6.5pt; color: #8793ad; text-align: center; line-height: 1.3; margin-top: 3pt; }
    .qrfallback { font-size: 8pt; font-weight: bold; color: #0a1a3a; border: 0.8pt solid #e4e9f2; padding: 5pt; text-align: center; }

    /* section headings */
    .sec-h-tbl { width: 100%; margin: 18pt 0 9pt; } .sec-h-tbl td { border: none; vertical-align: bottom; }
    .sec-t { font-size: 8.5pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; color: #0a1a3a; }
    /* the rule is a bottom-border ON THE CELL (an empty inner div has no height in
       mPDF and collapses) — a 1px line that fills the remaining width. */
    .sec-rule-cell { border-bottom: 0.7pt solid #e4e9f2; height: 9pt; }
    .leg { font-size: 7.5pt; font-weight: bold; letter-spacing: .5pt; color: #fff; background: <?= $brand ?>; border-radius: 4pt; padding: 2.5pt 8pt; }

    /* passenger cards (boxed, 2-up) */
    .pax-card { border: 0.6pt solid #e4e9f2; border-radius: 9pt; padding: 10pt 12pt; }
    .pax-card-tbl { width: 100%; } .pax-card-tbl td { border: none; }
    .pax-nm { font-size: 11pt; font-weight: bold; color: #0a1a3a; }
    .pax-meta { font-size: 8pt; color: #5b6b8c; margin-top: 2pt; }
    .pax-etk-k { font-size: 6.5pt; font-weight: bold; letter-spacing: .6pt; text-transform: uppercase; color: #8793ad; }
    .pax-etk-v { font-family: "dejavusansmono", monospace; font-size: 9.5pt; font-weight: bold; color: #0a1a3a; }
    .pax-etk-pend { font-size: 8pt; font-weight: bold; color: #b4690e; }

    /* segment card */
    .seg { border: 0.6pt solid #e4e9f2; border-radius: 10pt; margin-bottom: 9pt; }
    .seg-top { background: #f5f7fb; border-bottom: 0.5pt solid #e4e9f2; padding: 7pt 13pt; }
    .seg-top-tbl { width: 100%; } .seg-top-tbl td { border: none; vertical-align: middle; }
    .dotmark { width: 22pt; height: 22pt; background: <?= $brand ?>; color: #fff; font-size: 9pt; font-weight: bold; text-align: center; vertical-align: middle; border-radius: 5pt; letter-spacing: .3pt; }
    .seg-air-nm { font-size: 11pt; font-weight: bold; color: #0a1a3a; }
    .seg-fno { font-family: "dejavusansmono", monospace; font-size: 8.5pt; font-weight: bold; color: #5b6b8c; }
    .seg-cabin { font-size: 7.5pt; font-weight: bold; letter-spacing: .5pt; text-transform: uppercase; color: <?= $brand ?>; background: #e8f0ff; border-radius: 4pt; padding: 3pt 9pt; }
    .seg-body-tbl { width: 100%; padding: 2pt; } .seg-body-tbl td { border: none; vertical-align: middle; }
    .ep-code { font-size: 26pt; font-weight: bold; color: #0a1a3a; letter-spacing: -.5pt; line-height: 1; }
    .ep-time { font-size: 13pt; font-weight: bold; color: #0a1a3a; margin-top: 3pt; }
    .ep-date { font-size: 7.6pt; font-weight: bold; color: #8793ad; margin-top: 1pt; }
    .ep-city { font-size: 7.8pt; color: #5b6b8c; margin-top: 3pt; }
    .mid-dur { font-size: 7.5pt; font-weight: bold; color: #5b6b8c; letter-spacing: .3pt; margin-bottom: 2pt; }
    .mid-stops { font-size: 7pt; color: #8793ad; letter-spacing: .3pt; margin-top: 1pt; }
    .seg-foot { border-top: 0.5pt dashed #e4e9f2; padding: 8pt 13pt; background: #fcfdff; }
    .seg-foot-tbl td { border: none; vertical-align: middle; font-size: 7.8pt; color: #5b6b8c; }
    .seg-foot-tbl b { color: #0a1a3a; }
    .opby { font-size: 7.5pt; color: #b4690e; padding: 0 13pt 8pt; background: #fcfdff; }

    /* layover row */
    .layover { text-align: center; font-size: 7.8pt; color: #5b6b8c; margin: 0 0 9pt; }
    .layover b { color: #b4690e; font-weight: bold; }

    /* guidance */
    .guide { background: #eef4ff; border: 0.5pt solid #cfe0ff; border-left: 3pt solid <?= $brand ?>; border-radius: 9pt; padding: 13pt 15pt; margin-top: 18pt; }
    .guide-gt { font-size: 9pt; font-weight: bold; color: #104bcb; margin-bottom: 6pt; }
    .guide ul { margin: 0; padding-left: 13pt; }
    .guide li { font-size: 8pt; color: #28406b; line-height: 1.6; }
    .guide b { color: #104bcb; }

    /* footer */
    .foot { border-top: 0.5pt solid #e4e9f2; margin-top: 16pt; padding-top: 10pt; }
    .foot-tbl { width: 100%; } .foot-tbl td { border: none; }
    .foot-p { font-size: 6.8pt; color: #8793ad; line-height: 1.55; }
    .foot-issue { font-size: 6.8pt; color: #8793ad; text-align: right; line-height: 1.6; }
    .foot-issue b { color: #5b6b8c; }
</style>
</head>
<body>
    <!-- ===== Header ===== -->
    <div class="top"><table class="top-tbl"><tr>
        <td style="width:62%;">
            <?php if ($logoSrc !== ''): ?>
                <img src="<?= $e($logoSrc) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
            <?php else: ?>
                <div class="logo-txt">Go<span>Globia</span></div>
            <?php endif; ?>
            <div class="issuer">Issued by <?= $e($businessName) ?><?php if ($businessEmail): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?><?php if ($businessPhone): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?><br>IATA / NANTA / NCAA accredited</div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="doctitle">Itinerary Receipt</div>
            <div class="docsub">Electronic Ticket &middot; Passenger Copy</div>
            <div style="margin-top:8pt; text-align:right;">
                <?php if ($isTicketed): ?><span class="chip chip-ok">&#9679; Ticketed &middot; Confirmed</span>
                <?php else: ?><span class="chip chip-pend">&#9679; Reserved &middot; Ticket pending</span><?php endif; ?>
            </div>
        </td>
    </tr></table></div>

    <!-- ===== Reference strip + QR ===== -->
    <div class="refstrip"><table class="refstrip-tbl"><tr>
        <td style="width:28%;">
            <div class="lbl">Airline Booking Ref (PNR)</div>
            <?php if ($isTicketed): ?>
                <div class="val"><?= $e($pnr) ?></div>
                <div class="hint">Use this to check in with the airline</div>
            <?php else: ?>
                <div class="val-pending">Pending &mdash; issued on ticketing</div>
                <div class="hint">You&rsquo;ll receive it once the airline tickets your booking</div>
            <?php endif; ?>
        </td>
        <td style="width:27%;">
            <div class="lbl"><?= $e($businessName) ?> Booking ID</div>
            <div class="val" style="font-size:12pt;"><?= $e($invoiceId) ?></div>
            <div class="hint">Quote for support &amp; changes</div>
        </td>
        <td style="width:23%;">
            <div class="lbl">Issued</div>
            <div class="val" style="font-size:11pt; font-family:inter;"><?= $e($bookingDate) ?></div>
            <div class="hint"><?= $cabinOverall !== '' ? $e(ucfirst($cabinOverall)) . ' &middot; ' : '' ?><?= (int) $paxCount ?> passenger<?= $paxCount === 1 ? '' : 's' ?></div>
        </td>
        <td style="width:22%; text-align:center;">
            <?php if (class_exists('Mpdf\\QrCode\\QrCode')): ?>
                <barcode code="<?= $e($manageUrl) ?>" type="QR" error="M" disableborder="1" />
            <?php else: ?>
                <div class="qrfallback"><?= $e($invoiceId) ?></div>
            <?php endif; ?>
            <div class="qr-cap">Scan to<br>Manage Booking</div>
        </td>
    </tr></table></div>

    <!-- ===== Passengers (boxed, 2-up grid) ===== -->
    <table class="sec-h-tbl"><tr>
        <td style="width:1%; white-space:nowrap;"><span class="sec-t">Passengers</span></td>
        <td class="sec-rule-cell" style="width:99%; padding-left:9pt;"></td>
    </tr></table>
    <?php
    $renderPax = function (?array $pax) use ($e) {
        if ($pax === null) { return ''; }
        $fn = strtoupper(trim((string) ($pax['first_name'] ?? '')));
        $ln = strtoupper(trim((string) ($pax['last_name'] ?? '')));
        $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
        $name = trim($ln . ' / ' . $fn . ($ttl ? ' ' . $ttl : ''), ' /');
        $ptypeRaw = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
        $ptype = ['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant', 'ADULT' => 'Adult', 'CHILD' => 'Child', 'INFANT' => 'Infant'][$ptypeRaw] ?? ucfirst(strtolower($ptypeRaw));
        $nat = (string) ($pax['nationality_name'] ?? $pax['nationality'] ?? '');
        $etk = trim((string) ($pax['eticket'] ?? $pax['ticket_number'] ?? ''));
        $etkHtml = $etk !== '' ? '<span class="pax-etk-v">' . $e($etk) . '</span>' : '<span class="pax-etk-pend">On ticketing</span>';
        return '<div class="pax-card"><table class="pax-card-tbl"><tr>'
            . '<td><div class="pax-nm">' . $e($name ?: 'Passenger') . '</div>'
            . '<div class="pax-meta">' . $e($ptype) . ($nat ? ' &middot; ' . $e($nat) : '') . '</div></td>'
            . '<td style="text-align:right;"><div class="pax-etk-k">E-Ticket</div>' . $etkHtml . '</td>'
            . '</tr></table></div>';
    };
    foreach (array_chunk($travellers, 2) as $row): ?>
    <table style="width:100%; margin-bottom:9pt;"><tr>
        <td style="width:49%; border:none; vertical-align:top;"><?= $renderPax($row[0] ?? null) ?></td>
        <td style="width:2%; border:none;"></td>
        <td style="width:49%; border:none; vertical-align:top;"><?= isset($row[1]) ? $renderPax($row[1]) : '' ?></td>
    </tr></table>
    <?php endforeach; ?>

    <!-- ===== Legs + segments ===== -->
    <?php foreach ($legs as $leg):
        $segs = array_values($leg['segments']); if (empty($segs)) continue;
        $legSum = ft_leg_summary($segs);
        $fromAp = ft_airport_resolve($legSum['from'], $db, $segs[0]);
        $toAp   = ft_airport_resolve($legSum['to'], $db, $segs[count($segs) - 1]);
        $legFromCity = $fromAp['city'] ?: $legSum['from'];
        $legToCity   = $toAp['city'] ?: $legSum['to'];
    ?>
    <table class="sec-h-tbl"><tr>
        <td style="width:1%; white-space:nowrap;"><span class="leg"><?= $e(strtoupper($leg['label'])) ?></span></td>
        <td style="width:1%; white-space:nowrap; padding-left:9pt;"><span class="sec-t" style="text-transform:none; letter-spacing:0; font-size:9pt;"><?= $e($legFromCity) ?> &rarr; <?= $e($legToCity) ?></span></td>
        <td class="sec-rule-cell" style="width:98%; padding-left:9pt;"></td>
    </tr></table>
    <?php foreach ($segs as $si => $s):
        $al = ft_airline_resolve($s, $db, $mpdfInst);
        $cabin = $segVal($s, ['class', 'cabin'], '');
        $classCode = $segVal($s, ['booking_class', 'class_code'], '');
        $depCode = strtoupper((string) $segVal($s, ['departure_code', 'from_airport'], '--'));
        $arrCode = strtoupper((string) $segVal($s, ['arrival_code', 'to_airport'], '--'));
        $dAp = ft_airport_resolve($depCode, $db, $s); $aAp = ft_airport_resolve($arrCode, $db, $s);
        $depName = $dAp['name'] !== '' ? $dAp['name'] : (string) $segVal($s, ['departure_airport', 'from_airport_name'], '');
        $arrName = $aAp['name'] !== '' ? $aAp['name'] : (string) $segVal($s, ['arrival_airport', 'to_airport_name'], '');
        $depTerm = (string) $segVal($s, ['departure_terminal', 'dep_terminal'], '');
        if ($depTerm !== '') { $depName = trim($depName . ' &middot; Terminal ' . $depTerm); }
        $depTime = $segVal($s, ['departure_time'], ''); $arrTime = $segVal($s, ['arrival_time'], '');
        $depDate = $segVal($s, ['departure_date'], ''); $arrDate = $segVal($s, ['arrival_date'], '');
        $dur = (string) $segVal($s, ['duration_time', 'total_duration', 'duration'], '');
        if ($dur === '') { $sm = ft_minutes_between(ft_seg_dep($s), ft_seg_arr($s)); $dur = ft_fmt_duration($sm['minutes']); if ($dur !== '' && !$sm['exact']) { $dur = '~' . $dur; } }
        $bag = $segVal($s, ['baggage', 'included_baggage'], '');
        $cabinBag = $segVal($s, ['cabin_baggage'], '');
        $operatedBy = $segVal($s, ['operating_carrier', 'operating', 'operated_by'], '');
        $showOp = $operatedBy !== '' && stripos((string) $operatedBy, (string) $al['name']) === false && strtoupper((string) $operatedBy) !== strtoupper((string) $al['code']);
    ?>
    <div class="seg">
        <div class="seg-top"><table class="seg-top-tbl"><tr>
            <td style="width:26pt;">
                <?php /* Always the brand blue lettered badge (owner's design) — never an airline logo image. */ ?>
                <table><tr><td class="dotmark"><?= $e($al['monogram']) ?></td></tr></table>
            </td>
            <td style="padding-left:8pt;"><span class="seg-air-nm"><?= $e($al['name']) ?></span><?php if ($al['flight_no'] !== ''): ?> <span class="seg-fno"><?= $e($al['flight_no']) ?></span><?php endif; ?></td>
            <td style="text-align:right;"><?php if ($cabin !== ''): ?><span class="seg-cabin"><?= $e(ucfirst($cabin)) ?><?= $classCode ? ' &middot; ' . $e(strtoupper($classCode)) : '' ?></span><?php endif; ?></td>
        </tr></table></div>
        <div style="padding:14pt 16pt;"><table class="seg-body-tbl"><tr>
            <td style="width:38%;">
                <div class="ep-code"><?= $e($depCode) ?></div>
                <?php if ($depTime !== ''): ?><div class="ep-time"><?= $e($depTime) ?></div><?php endif; ?>
                <?php if ($depDate !== ''): ?><div class="ep-date"><?= $e($depDate) ?></div><?php endif; ?>
                <?php if ($depName !== ''): ?><div class="ep-city"><?= $depName ?></div><?php endif; ?>
            </td>
            <td style="width:24%; text-align:center;">
                <?php if ($dur !== ''): ?><div class="mid-dur"><?= $e($dur) ?></div><?php endif; ?>
                <div><?= ft_connector($brand, 118) ?></div>
                <div class="mid-stops">Non-stop</div>
            </td>
            <td style="width:38%; text-align:right;">
                <div class="ep-code"><?= $e($arrCode) ?></div>
                <?php if ($arrTime !== ''): ?><div class="ep-time"><?= $e($arrTime) ?></div><?php endif; ?>
                <?php if ($arrDate !== ''): ?><div class="ep-date"><?= $e($arrDate) ?></div><?php endif; ?>
                <?php if ($arrName !== ''): ?><div class="ep-city"><?= $e($arrName) ?></div><?php endif; ?>
            </td>
        </tr></table></div>
        <?php if ($showOp): ?><div class="opby">Operated by <?= $e($operatedBy) ?> on behalf of <?= $e($al['name']) ?></div><?php endif; ?>
        <div class="seg-foot"><table class="seg-foot-tbl"><tr>
            <?php if ($bag !== ''): ?><td style="width:34%;"><?= ft_icon('checked', $brand, 11) ?> Checked <b><?= $e($bag) ?></b></td><?php endif; ?>
            <?php if ($cabinBag !== ''): ?><td style="width:30%;"><?= ft_icon('cabin', $brand, 11) ?> Cabin <b><?= $e($cabinBag) ?></b></td><?php endif; ?>
            <td><?= ft_icon('clock', ($isTicketed ? '#0b875b' : '#b4690e'), 11) ?> Status <b><?= $isTicketed ? 'OK / Confirmed' : 'Reserved' ?></b></td>
        </tr></table></div>
    </div>
    <?php
        // Layover between connecting segments.
        if ($si < count($segs) - 1) {
            $lay = $legSum['layovers'][$si] ?? null;
            $layCode = $lay['code'] ?? $arrCode;
            $layAp = ft_airport_resolve((string) $layCode, $db, $s);
            $layWhere = $layAp['city'] !== '' ? $layAp['city'] : $layCode;
            $layDur = $lay && $lay['display'] !== '' ? ($lay['exact'] ? $lay['display'] : '~' . $lay['display']) : '';
            echo '<div class="layover">Connection at ' . $e($layWhere) . ($layCode && $layWhere !== $layCode ? ' (' . $e($layCode) . ')' : '')
                . ($layDur !== '' ? ' &middot; <b>' . $e($layDur) . ' layover</b>' : '') . ' &middot; terminal change</div>';
        }
    endforeach; endforeach; ?>

    <!-- ===== Guidance ===== -->
    <div class="guide">
        <div class="guide-gt"><?= $isTicketed ? 'Before you fly &mdash; this is your itinerary receipt, not a boarding pass' : 'What happens next' ?></div>
        <ul>
        <?php if ($isTicketed): ?>
            <li>Check in online with the operating airline using PNR <b><?= $e($pnr) ?></b>, or at the airport desk, to get your boarding pass.</li>
            <li>Bring a <b>printed or mobile copy</b> of this receipt and a <b>valid passport / government ID</b> matching the passenger name.</li>
            <li>Arrive at the airport <b>at least 2 hours</b> before domestic and <b>3 hours</b> before international departure.</li>
            <li>Baggage and cabin allowances are per passenger, per segment, as shown above.</li>
        <?php else: ?>
            <li>Your seats are <b>reserved</b>. Once the airline issues your ticket, we&rsquo;ll email an updated receipt with your <b>PNR</b> and <b>e-ticket numbers</b>.</li>
            <li>Keep your Booking ID <b><?= $e($invoiceId) ?></b> for any questions in the meantime.</li>
            <li>This document is an itinerary receipt, <b>not a boarding pass</b>. Online check-in opens after ticketing.</li>
        <?php endif; ?>
        </ul>
    </div>

    <!-- ===== Footer ===== -->
    <div class="foot"><table class="foot-tbl"><tr>
        <td style="width:64%;"><div class="foot-p">This document confirms your reservation<?= $isTicketed ? ' and ticketing' : '' ?>. Carriage is subject to the operating airline&rsquo;s Conditions of Carriage and fare rules. Fare and payment details are shown on your separate invoice/receipt. Schedules may change &mdash; reconfirm with the airline before travel.</div></td>
        <td style="width:36%;"><div class="foot-issue"><b><?= $e($businessName) ?></b><br><?php if ($businessEmail): ?><?= $e($businessEmail) ?><br><?php endif; ?>Generated <?= $e(date('d M Y, H:i')) ?></div></td>
    </tr></table></div>
</body>
</html>
