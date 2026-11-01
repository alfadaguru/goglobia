<?php
/**
 * BUS INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a bus booking. Builds a bus journey summary
 * (from city -> to city + date) + journey/passenger detail, then feeds the
 * premium _invoice_base.php: header band, bill-to, line-item price block
 * (Ticket Charges / Taxes & Fees / Total), payment status + method, notes.
 *
 * MONEY: preserves the exact current bus logic — first row ("Ticket Charges")
 * and the Total both use number_format((float)$booking['price_markup'],2);
 * "Taxes & Fees" uses number_format((float)$booking['tax'],2); displayed in
 * $booking['currency_markup']. We only DISPLAY (no re-pricing here).
 *
 * Vars: $booking,$bookingData,$settings,$businessName,$businessEmail,$businessPhone,
 *   $siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate, (+$mpdf if set).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Bus data (draft stored at booking time) --------------------------------
$trip       = $bookingData['trip'] ?? [];
$journeys   = $bookingData['journeys'] ?? [['type' => 'outbound', 'route_id' => $bookingData['route_id'] ?? 0, 'date' => $bookingData['date'] ?? '', 'trip' => $trip]];
$adultsCnt  = (int) ($booking['adults'] ?? 1);
$childsCnt  = (int) ($booking['childs'] ?? 0);
$pnr        = (string) ($booking['pnr'] ?? '');

$travellersData = $travellersData ?? null;

$fmtDate = function ($raw) {
    $raw = (string) $raw;
    if ($raw === '') { return ''; }
    $ts = DateTime::createFromFormat('d-m-Y', $raw);
    return $ts ? $ts->format('F d, Y') : $raw;
};

// ---- HERO: bus journey summary (from city -> to city + date) -----------------
$heroHtml = '';
$firstJourney = $journeys[0] ?? [];
$ht           = $firstJourney['trip'] ?? $trip;
$hOrigin      = (string) ($ht['origin'] ?? '');
$hDest        = (string) ($ht['destination'] ?? '');
$hDate        = $fmtDate($firstJourney['date'] ?? ($bookingData['date'] ?? ''));
$hDuration    = (string) ($ht['duration'] ?? '');
$roundTrip    = count($journeys) > 1;
if ($hOrigin !== '' || $hDest !== '') {
    ob_start(); ?>
    <div class="hero"><table><tr>
        <td style="width:38%;">
            <div class="port-city"><?= $e($hOrigin ?: '—') ?></div>
            <?php if ($hDate !== ''): ?><div class="port-apt"><?= $e($hDate) ?></div><?php endif; ?>
        </td>
        <td style="width:24%;" class="hero-mid">
            <?php if ($hDuration !== ''): ?><div class="hero-dur"><?= $e($hDuration) ?></div><?php endif; ?>
            <div class="hero-line"></div><div class="hero-plane">&#128652;</div>
            <div class="hero-stops"><?= $roundTrip ? 'Round trip' : 'One way' ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="port-city"><?= $e($hDest ?: '—') ?></div>
        </td>
    </tr></table></div>
    <?php
    $heroHtml = ob_get_clean();
}

// ---- BODY: journey detail + passengers --------------------------------------
ob_start();
?>
<div class="sec-h"><?= $e($T('bus_details', 'Bus Details')) ?></div>
<?php foreach ($journeys as $journey):
    $jt           = $journey['trip'] ?? [];
    $busName      = (string) ($jt['service_name'] ?? 'Bus Trip');
    $operator     = (string) ($jt['operator'] ?? '');
    $origin       = (string) ($jt['origin'] ?? '');
    $destination  = (string) ($jt['destination'] ?? '');
    $depTime      = (string) ($jt['departure_time'] ?? '');
    $arrTime      = (string) ($jt['arrival_time'] ?? '');
    $duration     = (string) ($jt['duration'] ?? '');
    $boarding     = (string) ($jt['boarding_point'] ?? ($jt['boarding'] ?? ''));
    $busType      = trim(((string) ($jt['bus_type'] ?? '')) . (!empty($jt['seat_class']) ? ' · ' . (string) $jt['seat_class'] : ''));
    $travelDate   = $fmtDate($journey['date'] ?? '');
    $journeyLabel = (($journey['type'] ?? 'outbound') === 'return') ? 'Return' : 'Outbound';
    $subline      = trim($operator . ($busType !== '' ? ' · ' . $busType : ''));
?>
<div class="card">
    <div class="card-top"><table><tr>
        <td style="width:70%;">
            <div class="card-title"><?= count($journeys) > 1 ? $e($journeyLabel) . ': ' : '' ?><?= $e($busName) ?></div>
            <?php if ($subline !== ''): ?><div class="card-sub"><?= $e($subline) ?></div><?php endif; ?>
        </td>
        <td style="width:30%; text-align:right;">
            <?php if (count($journeys) > 1): ?><span class="tag"><?= $e(strtoupper($journeyLabel)) ?></span><?php endif; ?>
        </td>
    </tr></table></div>
    <div class="card-body">
        <table>
            <tr>
                <td style="width:50%;">
                    <div class="k"><?= $e($T('departure', 'Departure')) ?></div>
                    <div class="v" style="font-size:10pt;"><?= $e($origin ?: '—') ?></div>
                    <div class="vs"><?= $e(trim($travelDate . ($depTime !== '' ? ' at ' . $depTime : ''))) ?></div>
                </td>
                <td style="width:50%; text-align:right;">
                    <div class="k"><?= $e($T('arrival', 'Arrival')) ?></div>
                    <div class="v" style="font-size:10pt;"><?= $e($destination ?: '—') ?></div>
                    <?php if ($arrTime !== ''): ?><div class="vs"><?= $e($arrTime) ?></div><?php endif; ?>
                </td>
            </tr>
        </table>
        <div class="vs" style="margin-top:8pt;">
            <?php if ($duration !== ''): ?><b><?= $e($duration) ?></b> <?= $e($T('journey', 'Journey')) ?> &middot; <?php endif; ?>
            <b><?= (int) $adultsCnt ?></b> <?= $adultsCnt > 1 ? $e($T('adults', 'Adults')) : $e($T('adult', 'Adult')) ?><?php
                if ($childsCnt > 0): ?>, <b><?= (int) $childsCnt ?></b> <?= $childsCnt > 1 ? $e($T('children', 'Children')) : $e($T('child', 'Child')) ?><?php endif; ?>
            <?php if ($pnr !== ''): ?> &middot; PNR: <b><?= $e($pnr) ?></b><?php endif; ?>
        </div>
        <?php if ($boarding !== ''): ?>
        <div class="vs" style="margin-top:5pt;"><span class="k"><?= $e($T('boarding_point', 'Boarding point')) ?>:</span> <b><?= $e($boarding) ?></b></div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="sec-h"><?= $e($T('lead_passenger', 'Lead Passenger')) ?></div>
<div class="infobox">
    <div class="inforow"><span class="infok"><?= $e($T('name', 'Name')) ?>:</span> <span class="infov"><?= $e(trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''))) ?></span></div>
    <div class="inforow"><span class="infok"><?= $e($T('email', 'Email')) ?>:</span> <span class="infov"><?= $e((string) ($booking['email'] ?? '')) ?></span></div>
    <?php if (!empty($booking['phone'])): ?>
    <div class="inforow"><span class="infok"><?= $e($T('phone', 'Phone')) ?>:</span> <span class="infov"><?= $e(trim((string) ($booking['phone_country_code'] ?? '') . ' ' . (string) $booking['phone'])) ?></span></div>
    <?php endif; ?>
</div>

<?php if (!empty($travellersData) && is_array($travellersData)):
    $paxRows = array_values(array_filter($travellersData, function ($tr) { return !empty($tr['first_name']); }));
    if (!empty($paxRows)):
?>
<div class="sec-h"><?= $e($T('passengers', 'Passengers')) ?><?= count($paxRows) > 1 ? ' &middot; ' . count($paxRows) : '' ?></div>
<table class="infotable">
<?php $n = 1; foreach ($paxRows as $tr): ?>
    <tr>
        <td style="width:8%;"><span class="vs"><?= (int) $n++ ?>.</span></td>
        <td style="width:62%;"><div class="v" style="font-size:9pt;"><?= $e(trim((string) ($tr['first_name'] ?? '') . ' ' . (string) ($tr['last_name'] ?? ''))) ?></div></td>
        <td style="width:30%; text-align:right;"><span class="vs"><?= (($tr['type'] ?? '') === 'child') ? $e($T('child', 'Child')) : $e($T('adult', 'Adult')) ?></span></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; endif; ?>

<?php if (!empty($trip['cancellation_policy'])): ?>
<div class="card" style="margin-top:10pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('cancellation_policy', 'Cancellation policy')) ?></div>
    <div class="vs"><?= nl2br($e((string) $trip['cancellation_policy'])) ?></div>
</div></div>
<?php endif; ?>
<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e((string) $booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (engine-converted display figures, like the other services) ----
// Ticket charges = subtotal (total minus tax), taxes, then the full total — all in
// the guest's display currency ($currency / $subtotalAmount / $taxAmount / $totalAmount
// are computed + converted by GENERATE_BOOKING_PDF). Keeps the "Ticket Charges" label.
$taxBase = (float) ($booking['tax'] ?? 0);
$priceRows = [];
$priceRows[] = ['label' => $T('ticket_charges', 'Ticket Charges'), 'value' => (string) ($subtotalAmount ?? number_format(max(0, (float) ($rawTotal ?? 0) - $taxBase), 2)), 'muted' => true];
if ($taxBase > 0 || (float) ($taxAmount ?? 0) > 0) {
    $priceRows[] = ['label' => $T('taxes_fees', 'Taxes & Fees'), 'value' => number_format((float) ($taxAmount ?? $taxBase), 2), 'muted' => true];
}

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Bus Booking',
    'paid' => $paid, 'statusText' => $statusText,
    'billToName' => trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')),
    'billToEmail' => (string) ($booking['email'] ?? ''),
    'billToPhone' => trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
    'heroHtml' => $heroHtml,
    'bodyHtml' => $bodyHtml,
    'currency' => $currency,
    'priceRows' => $priceRows,
    'totalLabel' => $T('total_amount', 'Total Amount'),
    'totalValue' => (string) ($totalAmount ?? number_format((float) ($rawTotal ?? 0), 2)),
    'paymentMethod' => (string) ($booking['payment_gateway'] ?? ''),
    'notesTitle' => $T('important_information', 'Important information'),
    'notesItems' => array_values(array_filter([
        $T('bus_arrive_early_notice', 'Arrive at the boarding point at least 30 minutes before departure.'),
        $T('bus_valid_id_notice', 'Carry a valid photo ID matching the lead passenger name.'),
        $T('bus_show_voucher_notice', 'Show this voucher (printed or digital) to the operator at boarding.'),
        $T('bus_luggage_notice', 'Luggage allowances and policies are set by the bus operator.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document is issued separately.'),
]);
