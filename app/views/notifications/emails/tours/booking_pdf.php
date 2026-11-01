<?php
/**
 * TOUR INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a tour booking (the no-price travel document is
 * tours/ticket.php). Builds a compact tour summary + guest details, then feeds
 * the premium _invoice_base.php: header band, bill-to, line-item price block
 * (subtotal / taxes & fees / total), payment status + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $rawTotal, $totalAmount, $taxAmount, $subtotalAmount, $currency, $statusText,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 *
 * Vars: $booking,$bookingData,$settings,$businessName,$businessEmail,$businessPhone,
 *   $siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate.
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

// ---- Tour data (engine decodes booking_data into $bookingData) ---------------
$tourName = (string) ($bookingData['tour_name'] ?? $bookingData['tour']['name'] ?? $bookingData['destination'] ?? 'Tour Booking');
$location = (string) ($bookingData['location'] ?? $bookingData['tour']['location'] ?? '');

$startRaw = (string) ($bookingData['start_date'] ?? $bookingData['date'] ?? $bookingData['travel_date'] ?? '');
// Tours store DD-MM-YYYY (e.g. 12-11-2026 = 12 Nov). Parse explicitly so the
// day/month are never swapped by strtotime's US interpretation.
$formattedDate = '';
if ($startRaw !== '') {
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $startRaw, $m)) {
        $formattedDate = date('D, d M Y', mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]));
    } else {
        $t = strtotime($startRaw);
        $formattedDate = $t ? date('D, d M Y', $t) : $startRaw;
    }
}
$duration = (string) ($bookingData['duration'] ?? '1 Day');
$tourType = (string) ($bookingData['tour_type'] ?? '');
$pickup   = (string) ($bookingData['pickup_location'] ?? '');
$adults   = (int) ($booking['adults'] ?? $bookingData['adults'] ?? 1);
$children = (int) ($booking['childs'] ?? $bookingData['children'] ?? 0);
$infants  = (int) ($booking['infants'] ?? $bookingData['infants'] ?? 0);

$paxText = ($adults ?: 1) . ' adult' . (($adults ?: 1) > 1 ? 's' : '')
    . ($children > 0 ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '')
    . ($infants > 0 ? ', ' . $infants . ' infant' . ($infants > 1 ? 's' : '') : '');

// ---- HERO: tour summary (name + location + date) ----------------------------
ob_start(); ?>
<div class="hero"><table><tr>
    <td style="width:66%;">
        <div class="port-city" style="font-size:20pt; line-height:1.1;"><?= $e($tourName) ?></div>
        <?php if ($location !== ''): ?><div class="port-apt" style="font-size:9pt; margin-top:4pt;"><?= $e($location) ?></div><?php endif; ?>
    </td>
    <td style="width:34%; text-align:right;">
        <?php if ($tourType !== ''): ?><div style="margin-bottom:5pt;"><span class="tag"><?= $e(strtoupper($tourType)) ?></span></div><?php endif; ?>
        <div class="hero-stops"><?= $e($T('start_date', 'Start Date')) ?></div>
        <div class="port-city" style="font-size:11pt;"><?= $e($formattedDate ?: 'N/A') ?></div>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: tour detail + lead guest + travellers ----------------------------
ob_start();
?>
<div class="sec-h"><?= $e($T('tour_details', 'Tour Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('start_date', 'Start Date')) ?></div>
            <div class="v"><?= $e($formattedDate ?: 'N/A') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k"><?= $e($T('duration', 'Duration')) ?></div>
            <div class="v"><?= $e($duration ?: '—') ?></div>
        </td>
    </tr>
    <tr>
        <?php if ($pickup !== ''): ?>
        <td style="width:50%;">
            <div class="k"><?= $e($T('pickup', 'Pickup')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($pickup) ?></div>
        </td>
        <td style="width:50%; text-align:right;">
        <?php else: ?>
        <td colspan="2">
        <?php endif; ?>
            <div class="k"><?= $e($T('guests', 'Guests')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($paxText) ?></div>
        </td>
    </tr>
</table>

<div class="sec-h"><?= $e($T('lead_guest', 'Lead Guest')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:60%;">
            <div class="v"><?= $e(trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')) ?: 'Guest') ?></div>
            <div class="vs"><?= $e((string) ($booking['email'] ?? '')) ?></div>
        </td>
        <td style="width:40%; text-align:right;">
            <?php $leadPhone = trim((string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')); ?>
            <?php if ($leadPhone !== ''): ?><div class="vs"><?= $e($leadPhone) ?></div><?php endif; ?>
        </td>
    </tr>
</table>

<?php $guests = (!empty($bookingData['guest']) && is_array($bookingData['guest'])) ? $bookingData['guest'] : []; ?>
<?php if (!empty($guests)): ?>
<div class="sec-h"><?= $e($T('traveller_list', 'Traveller List')) ?><?= count($guests) > 0 ? ' &middot; ' . count($guests) : '' ?></div>
<table class="infotable">
<?php foreach ($guests as $i => $traveler):
    $gName = (string) ($traveler['name'] ?? ('Guest ' . ($i + 1)));
    $gAge  = $traveler['age'] ?? '';
?>
    <tr>
        <td style="width:70%;"><div class="v" style="font-size:9pt;"><?= $e($gName) ?></div></td>
        <td style="width:30%; text-align:right;"><?php if ($gAge !== '' && $gAge !== null): ?><span class="vs"><?= $e($T('age', 'Age')) ?> <?= $e($gAge) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e($booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (EXACT money logic preserved from the old invoice) -----------
// Old invoice: Subtotal = $currency $subtotalAmount; Taxes & Fees = number_format($booking['tax'],2);
// Total = $currency $totalAmount. Engine already currency-converted these figures.
$priceRows = [];
$priceRows[] = ['label' => $T('subtotal', 'Subtotal'), 'value' => (string) ($subtotalAmount ?? ''), 'muted' => true];
$priceRows[] = ['label' => $T('taxes_fees', 'Taxes & Fees'), 'value' => number_format((float) ($booking['tax'] ?? 0), 2), 'muted' => true];

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Tour Booking',
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
        $T('tours_meeting_point_notice', 'Please arrive at the meeting point at least 15 minutes before departure.'),
        $T('tours_present_voucher_notice', 'Present your tour voucher (digital or printed) to the tour guide.'),
        $T('tours_rain_or_shine_notice', 'Tours operate rain or shine unless otherwise notified.'),
        $T('tours_comfortable_shoes_notice', 'Comfortable walking shoes are recommended.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your tour voucher is issued separately.'),
]);
