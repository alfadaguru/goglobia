<?php
/**
 * FLIGHT INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a flight booking (the no-price travel document is
 * flights/ticket.php). Builds a compact flight summary + passenger list, then feeds
 * the premium _invoice_base.php: header band, bill-to, line-item price block
 * (subtotal / extra services / taxes / total), payment status + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $rawTotal, $totalAmount, $taxAmount, $subtotalAmount, $currency, $statusText,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 *
 * Vars: $booking,$bookingData,$settings,$businessName,$businessEmail,$businessPhone,
 *   $siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate, (+$mpdf if set).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';
require_once __DIR__ . '/../../../../lib/flight_ticket_helpers.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$db = $GLOBALS['db'] ?? null;
$mpdfInst = $mpdf ?? ($GLOBALS['mpdf'] ?? null);
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Passengers -------------------------------------------------------------
$travellers = [];
if (!empty($booking['travellers'])) {
    $t = json_decode((string) $booking['travellers'], true);
    if (is_array($t)) {
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

// ---- Legs & segments (same extraction the ticket uses) ----------------------
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
            'departure_code' => $fd['departure_code'] ?? ($fd['from_airport'] ?? ''), 'arrival_code' => $fd['arrival_code'] ?? ($fd['to_airport'] ?? ''),
            'departure_time' => $fd['departure_time'] ?? '', 'arrival_time' => $fd['arrival_time'] ?? '',
            'departure_date' => $fd['departure_date'] ?? '', 'arrival_date' => $fd['arrival_date'] ?? '',
        ]]];
    }
}
$segVal = function (array $s, array $keys, $default = '') {
    foreach ($keys as $k) { if (isset($s[$k]) && $s[$k] !== '') { return $s[$k]; } }
    return $default;
};

// ---- HERO: route summary (true origin -> final destination of the first leg) ----
$heroHtml = '';
if (!empty($legs)) {
    $hl = $legs[0]['segments'];
    $sum = ft_leg_summary($hl);
    $dAp = ft_airport_resolve($sum['from'], $db, $hl[0]);
    $aAp = ft_airport_resolve($sum['to'], $db, $hl[count($hl) - 1]);
    $stops = (int) $sum['stops'];
    $stopText = $stops <= 0 ? 'Nonstop' : ($stops === 1 ? '1 stop' : $stops . ' stops');
    $tot = $sum['total_display']; if ($tot !== '' && !$sum['total_exact']) { $tot = '~' . $tot; }
    ob_start(); ?>
    <div class="hero"><table><tr>
        <td style="width:38%;">
            <div class="port-code"><?= $e($sum['from'] ?: '—') ?></div>
            <?php if ($dAp['city'] !== ''): ?><div class="port-city"><?= $e($dAp['city']) ?></div><?php endif; ?>
            <?php if ($dAp['name'] !== ''): ?><div class="port-apt"><?= $e($dAp['name']) ?></div><?php endif; ?>
        </td>
        <td style="width:24%;" class="hero-mid">
            <?php if ($tot !== ''): ?><div class="hero-dur"><?= $e($tot) ?></div><?php endif; ?>
            <div class="hero-line"></div><div class="hero-plane">&#9992;</div>
            <div class="hero-stops"><?= (count($legs) > 1 ? 'Round trip &middot; ' : '') . $stopText ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="port-code"><?= $e($sum['to'] ?: '—') ?></div>
            <?php if ($aAp['city'] !== ''): ?><div class="port-city"><?= $e($aAp['city']) ?></div><?php endif; ?>
            <?php if ($aAp['name'] !== ''): ?><div class="port-apt"><?= $e($aAp['name']) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <?php
    $heroHtml = ob_get_clean();
}

// ---- BODY: compact flight lines + passengers --------------------------------
ob_start();
?>
<?php if (!empty($legs)): ?>
<div class="sec-h"><?= $e($T('flight_details', 'Flight Details')) ?></div>
<table class="infotable">
<?php foreach ($legs as $leg): foreach ($leg['segments'] as $s):
    $al = ft_airline_resolve($s, $db, $mpdfInst);
    $depCode = strtoupper((string) $segVal($s, ['departure_code', 'from_airport'], ''));
    $arrCode = strtoupper((string) $segVal($s, ['arrival_code', 'to_airport'], ''));
    $dAp = ft_airport_resolve($depCode, $db, $s); $aAp = ft_airport_resolve($arrCode, $db, $s);
    $depTime = $segVal($s, ['departure_time'], ''); $depDate = $segVal($s, ['departure_date'], '');
    $cabin = $segVal($s, ['class', 'cabin'], '');
?>
    <tr>
        <td style="width:6%;"><?php if ($al['logo_src'] !== ''): ?><img src="<?= $e($al['logo_src']) ?>" style="width:20pt;height:20pt;" alt=""><?php endif; ?></td>
        <td style="width:40%;">
            <div class="v" style="font-size:9pt;"><?= $e($al['name']) ?><?php if ($al['flight_no'] !== ''): ?> <span class="vs"><?= $e($al['flight_no']) ?></span><?php endif; ?></div>
            <div class="vs"><?= $e(strtoupper((string) $leg['label'])) ?><?= $cabin ? ' &middot; ' . $e(ucfirst($cabin)) : '' ?></div>
        </td>
        <td style="width:34%;">
            <div class="v" style="font-size:9pt;"><?= $e($depCode) ?> &rarr; <?= $e($arrCode) ?></div>
            <div class="vs"><?= $e(trim(($dAp['city'] ?: $depCode) . ' to ' . ($aAp['city'] ?: $arrCode))) ?></div>
        </td>
        <td style="width:20%; text-align:right;">
            <?php if ($depDate !== ''): ?><div class="v" style="font-size:8.5pt;"><?= $e($depDate) ?></div><?php endif; ?>
            <?php if ($depTime !== ''): ?><div class="vs"><?= $e($depTime) ?></div><?php endif; ?>
        </td>
    </tr>
<?php endforeach; endforeach; ?>
</table>
<?php endif; ?>

<div class="sec-h"><?= $e($T('passengers', 'Passengers')) ?><?= count($travellers) > 1 ? ' &middot; ' . count($travellers) : '' ?></div>
<table class="infotable">
<?php foreach ($travellers as $pax):
    $nm = trim(strtoupper((string) ($pax['last_name'] ?? '')) . ' / ' . strtoupper((string) ($pax['first_name'] ?? '')), ' /');
    $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
    $ptypeRaw = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
    $ptype = ['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant'][$ptypeRaw] ?? ucfirst(strtolower($ptypeRaw));
?>
    <tr>
        <td style="width:60%;"><div class="v" style="font-size:9pt;"><?= $e($nm ?: 'Passenger') ?><?= $ttl ? ' <span class="vs">' . $e($ttl) . '</span>' : '' ?></div></td>
        <td style="width:40%; text-align:right;"><span class="vs"><?= $e($ptype) ?></span></td>
    </tr>
<?php endforeach; ?>
</table>
<?php
// Ancillaries note (seats/bags) — preserved from the old invoice.
$totalSeats = 0; $totalBags = 0;
$ancSeats = $bookingData['seat'] ?? ($bookingData['ancillary_data']['seats'] ?? []);
$ancBags  = (!empty($bookingData['baggage']) && isset($bookingData['baggage'][0]['passengerId'])) ? $bookingData['baggage'] : ($bookingData['ancillary_data']['baggage'] ?? []);
if (is_array($ancSeats)) { foreach ($ancSeats as $ss) { if (is_array($ss)) { $totalSeats += count($ss); } } }
if (is_array($ancBags)) { foreach ($ancBags as $bg) { if (isset($bg['quantity'])) { $totalBags += (int) $bg['quantity']; } } }
if ($totalSeats > 0 || $totalBags > 0):
?>
<div class="card" style="margin-top:10pt;"><div class="card-body">
    <div class="k" style="margin-bottom:4pt;">Extra services selected</div>
    <?php if ($totalSeats > 0): ?><div class="vs">&#10003; <b><?= (int) $totalSeats ?></b> seat<?= $totalSeats != 1 ? 's' : '' ?> selected</div><?php endif; ?>
    <?php if ($totalBags > 0): ?><div class="vs">&#10003; <b><?= (int) $totalBags ?></b> extra baggage item<?= $totalBags != 1 ? 's' : '' ?></div><?php endif; ?>
</div></div>
<?php endif; ?>
<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e($booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (same math as the old invoice; engine gave converted figures) ----
$ancBase = (float) ($bookingData['ancillary_data']['total_base'] ?? 0);
$taxBase = (float) ($booking['tax'] ?? 0);
$subtotal = (float) ($rawTotal ?? 0) - $ancBase - $taxBase;
$priceRows = [];
$priceRows[] = ['label' => $T('subtotal', 'Subtotal'), 'value' => number_format(max(0, $subtotal), 2), 'muted' => true];
if ($ancBase > 0) { $priceRows[] = ['label' => $T('extra_services', 'Extra services'), 'value' => number_format($ancBase, 2), 'muted' => true]; }
if ($taxBase > 0 || (float) ($taxAmount ?? 0) > 0) { $priceRows[] = ['label' => $T('taxes_fees', 'Taxes & fees'), 'value' => number_format((float) ($taxAmount ?? $taxBase), 2), 'muted' => true]; }

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Flight Booking',
    'paid' => $paid, 'statusText' => $statusText,
    'billToName' => trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')),
    'billToEmail' => (string) ($booking['email'] ?? ''),
    'billToPhone' => trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
    'heroHtml' => $heroHtml,
    'bodyHtml' => $bodyHtml,
    'currency' => $currency,
    'priceRows' => $priceRows,
    'totalLabel' => $T('total_amount', 'Total amount'),
    'totalValue' => (string) ($totalAmount ?? number_format((float) ($rawTotal ?? 0), 2)),
    'paymentMethod' => (string) ($booking['payment_gateway'] ?? ''),
    'notesTitle' => $T('important_information', 'Important information'),
    'notesItems' => array_values(array_filter([
        $T('flights_arrive_early_notice', 'Arrive at the airport at least 2–3 hours before departure.'),
        $T('flights_valid_id_notice', 'Valid photo identification is required for all passengers.'),
        $T('flights_not_boarding_pass_notice', 'This invoice is not a boarding pass — your e-ticket is a separate document.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document (e-ticket) is issued separately.'),
]);
