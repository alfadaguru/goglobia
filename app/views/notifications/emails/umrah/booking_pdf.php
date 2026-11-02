<?php
/**
 * UMRAH INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for an Umrah package booking. Builds a package summary
 * hero + full detail body (flights, accommodation w/ star ratings, transfers, lead
 * guest, additional travellers, special requests), then feeds the premium
 * _invoice_base.php: header band, bill-to, line-item price block (subtotal / taxes /
 * total), payment status + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed in:
 * $rawTotal, $totalAmount, $taxAmount ($pdfTaxAmount), $subtotalAmount, $currency,
 * $booking['payment_gateway'], $booking['payment_status']. We only DISPLAY them
 * (no re-pricing here). The tax LINE preserves the old template's exact figure:
 * number_format($booking['tax'], 2) — unchanged.
 *
 * NOTE on part-payment: the previous Umrah receipt showed a flat Subtotal / Taxes /
 * Total only — it did NOT display any deposit / amount-paid / balance-due or
 * installment schedule (those live in the umrah booking engine + web views, not in
 * this PDF). So there is no paid/due split to carry over; the money shown here is
 * identical to before. If the booking row ever carries amount_paid/balance, wire the
 * base's paidValue/dueValue keys — they already render "Paid X · Balance due Y".
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';
require_once __DIR__ . '/../_ticket_icons.php'; // ft_icon() — SVG chips (no emoji boxes)

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Umrah package summary --------------------------------------------------
$umrahName   = $bookingData['umrah_name'] ?? 'Umrah Package';
$location    = $bookingData['umrah_location'] ?? '';
$startDate   = $bookingData['start_date'] ?? '';
$formattedDate = !empty($startDate) ? date('D, M d, Y', strtotime($startDate)) : 'N/A';
$duration    = $bookingData['duration'] ?? '';
$adults      = (int) ($booking['adults'] ?? $bookingData['total_adults'] ?? 1);
$children    = (int) ($booking['childs'] ?? $bookingData['total_children'] ?? 0);

// Flatten travellers for display (skip adult_1 = primary guest).
$flatTravelers = [];
$travelersSource = $travellersData['travelers'] ?? [];
if (!empty($travelersSource) && is_array($travelersSource)) {
    foreach ($travelersSource as $key => $value) {
        if (is_array($value) && !isset($value['first_name'])) {
            foreach ($value as $paxKey => $pax) {
                if ($paxKey === 'adult_1') continue;
                if (is_array($pax) && !empty(trim($pax['first_name'] ?? ''))) {
                    $flatTravelers[] = $pax;
                }
            }
        } elseif ($key !== 'adult_1' && is_array($value) && !empty(trim($value['first_name'] ?? ''))) {
            $flatTravelers[] = $value;
        }
    }
}

// ---- HERO: package name + departure + pax -----------------------------------
ob_start();
?>
<div class="hero"><table><tr>
    <td style="width:60%;">
        <div class="port-city" style="font-size:16pt;"><?= $e($umrahName) ?></div>
        <?php if ($location !== ''): ?><div class="port-apt" style="font-size:8.5pt;"><?= ft_icon('pin','#0b875b',10) ?> <?= $e($location) ?></div><?php endif; ?>
    </td>
    <td style="width:20%; text-align:center;">
        <div class="k"><?= $e($T('departure', 'Departure')) ?></div>
        <div class="v" style="font-size:9pt; margin-top:2pt;"><?= $e($formattedDate) ?></div>
        <?php if ($duration !== ''): ?><div class="vs" style="margin-top:2pt;"><?= $e($duration) ?></div><?php endif; ?>
    </td>
    <td style="width:20%; text-align:right;">
        <div class="k"><?= $e($T('pilgrims', 'Pilgrims')) ?></div>
        <div class="v" style="font-size:9pt; margin-top:2pt;">
            <?= (int) $adults ?> <?= $e($adults === 1 ? $T('adult', 'Adult') : $T('adults', 'Adults')) ?>
        </div>
        <?php if ($children > 0): ?><div class="vs" style="margin-top:2pt;"><?= (int) $children ?> <?= $e($children === 1 ? $T('child', 'Child') : $T('children', 'Children')) ?></div><?php endif; ?>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: flights, accommodation, transfers, guests ------------------------
ob_start();
?>
<?php
// ---- FLIGHTS ----
$flights = $bookingData['flights'] ?? [];
if (!empty($flights) && is_array($flights)) {
    $flights = array_filter($flights, function ($f) {
        if (!is_array($f)) return false;
        $segs = $f['segments'] ?? [$f];
        foreach ($segs as $s) {
            if (!empty($s['airline']) || !empty($s['flight_no']) || !empty($s['departure_airport'])) return true;
        }
        return false;
    });
}
if (!empty($flights)): ?>
<div class="sec-h"><?= $e($T('flight_details', 'Flight Details')) ?></div>
<table class="infotable">
<?php foreach ($flights as $flight):
    $segments = $flight['segments'] ?? [$flight];
    foreach ($segments as $seg): ?>
    <tr>
        <td style="width:40%;">
            <div class="v" style="font-size:9pt;"><?= $e($seg['airline'] ?? '') ?><?php if (!empty($seg['flight_no'])): ?> <span class="vs"><?= $e($seg['flight_no']) ?></span><?php endif; ?></div>
            <div class="vs"><?= $e($T('outbound', 'Outbound')) ?></div>
        </td>
        <td style="width:34%;"><div class="v" style="font-size:9pt;"><?= $e($seg['departure_airport'] ?? '') ?> &rarr; <?= $e($seg['arrival_airport'] ?? '') ?></div></td>
        <td style="width:26%; text-align:right;">
            <?php if (!empty($seg['departure_date'])): ?><div class="v" style="font-size:8.5pt;"><?= $e($seg['departure_date']) ?></div><?php endif; ?>
            <?php if (!empty($seg['departure_time'])): ?><div class="vs"><?= $e($seg['departure_time']) ?></div><?php endif; ?>
        </td>
    </tr>
    <?php endforeach;
    $returnSegments = $flight['returnSegments'] ?? [];
    if (!empty($returnSegments)):
        foreach ($returnSegments as $retSeg): ?>
    <tr>
        <td style="width:40%;">
            <div class="v" style="font-size:9pt;"><?= $e($retSeg['airline'] ?? '') ?><?php if (!empty($retSeg['flight_no'])): ?> <span class="vs"><?= $e($retSeg['flight_no']) ?></span><?php endif; ?></div>
            <div class="vs"><?= $e($T('return', 'Return')) ?></div>
        </td>
        <td style="width:34%;"><div class="v" style="font-size:9pt;"><?= $e($retSeg['departure_airport'] ?? '') ?> &rarr; <?= $e($retSeg['arrival_airport'] ?? '') ?></div></td>
        <td style="width:26%; text-align:right;">
            <?php if (!empty($retSeg['departure_date'])): ?><div class="v" style="font-size:8.5pt;"><?= $e($retSeg['departure_date']) ?></div><?php endif; ?>
            <?php if (!empty($retSeg['departure_time'])): ?><div class="vs"><?= $e($retSeg['departure_time']) ?></div><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; endif; ?>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php
// ---- STAYS / ACCOMMODATION (with star ratings + rooms) ----
$stays = $bookingData['stays'] ?? [];
if (!empty($stays) && is_array($stays)) {
    $stays = array_filter($stays, function ($s) {
        return is_array($s) && (!empty($s['hotel_name']) || !empty($s['location']));
    });
}
if (!empty($stays)): ?>
<div class="sec-h"><?= $e($T('accommodation', 'Accommodation')) ?></div>
<?php foreach ($stays as $stay): ?>
<div class="card"><div class="card-body">
    <div class="v" style="font-size:10pt;"><?= $e($stay['hotel_name'] ?? 'Hotel') ?></div>
    <?php if (!empty($stay['location'])): ?><div class="vs" style="margin-top:2pt;"><?= ft_icon('pin','#0b875b',10) ?> <?= $e($stay['location']) ?></div><?php endif; ?>
    <?php if (!empty($stay['stars'])): ?>
    <div class="vs" style="margin-top:2pt;">
        <?php for ($i = 1; $i <= 5; $i++): ?><?= $i <= (int) $stay['stars'] ? '&#9733;' : '&#9734;' ?><?php endfor; ?>
        (<?= $e(number_format((float) $stay['stars'], 1)) ?>)
    </div>
    <?php endif; ?>
    <?php if (!empty($stay['rooms']) && is_array($stay['rooms'])): ?>
    <div class="vs" style="margin-top:2pt;">
        <?php foreach ($stay['rooms'] as $room): ?>
            <span class="tag"><?= $e($room['type'] ?? $room['name'] ?? $room['room_type'] ?? 'Room') ?><?php
                $occ = $room['occupancy'] ?? $room['room_occupancy'] ?? '';
                if (!empty($occ)): ?> (<?= $e($occ) ?>)<?php endif; ?></span>&nbsp;
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($stay['notes'])): ?><div class="vs" style="margin-top:2pt; font-style:italic;">&#9432; <?= $e($stay['notes']) ?></div><?php endif; ?>
    <?php if (!empty($stay['check_in']) || !empty($stay['check_out'])): ?>
    <table style="margin-top:5pt;"><tr>
        <td style="width:50%;"><div class="k"><?= $e($T('check_in', 'Check-in')) ?></div><div class="vs"><?= $e($stay['check_in'] ?? '---') ?></div></td>
        <td style="width:50%;"><div class="k"><?= $e($T('check_out', 'Check-out')) ?></div><div class="vs"><?= $e($stay['check_out'] ?? '---') ?></div></td>
    </tr></table>
    <?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php endif; ?>

<?php
// ---- TRANSFERS ----
$transfers = $bookingData['transfers'] ?? [];
if (!empty($transfers) && is_array($transfers)) {
    $transfers = array_filter($transfers, function ($t) { return is_array($t); });
}
if (!empty($transfers)): ?>
<div class="sec-h"><?= $e($T('transfers', 'Transfers')) ?></div>
<?php foreach ($transfers as $transfer):
    $transferDate = $transfer['date'] ?? '';
    $transferDateOnly = ''; $transferTimeOnly = '';
    if (!empty($transferDate)) {
        if (strpos($transferDate, 'T') !== false) {
            list($transferDateOnly, $transferTimeOnly) = explode('T', $transferDate);
        } else {
            $transferDateOnly = $transferDate;
        }
    }
?>
<div class="card"><div class="card-body">
    <div class="v" style="font-size:10pt;"><?= $e($transfer['type'] ?? 'Transfer') ?></div>
    <div class="k" style="margin-top:1pt;"><?= $e($T('vehicle_service', 'Vehicle Service')) ?></div>
    <table style="margin-top:4pt;"><tr>
        <td style="width:45%;"><div class="k"><?= $e($T('pickup', 'Pickup')) ?></div><div class="vs"><?= ft_icon('pin','#0b875b',10) ?> <?= $e($transfer['from'] ?? $transfer['pickup'] ?? '---') ?></div></td>
        <td style="width:10%; text-align:center; vertical-align:middle;"><span class="vs">&rarr;</span></td>
        <td style="width:45%;"><div class="k"><?= $e($T('dropoff', 'Dropoff')) ?></div><div class="vs"><?= ft_icon('pin','#0b875b',10) ?> <?= $e($transfer['to'] ?? $transfer['dropoff'] ?? '---') ?></div></td>
    </tr></table>
    <?php if (!empty($transferDateOnly) || !empty($transferTimeOnly)): ?>
    <table style="margin-top:4pt;"><tr>
        <td style="width:50%;"><div class="k"><?= $e($T('date', 'Date')) ?></div><div class="vs"><?= $e($transferDateOnly ?: '---') ?></div></td>
        <td style="width:50%;"><div class="k"><?= $e($T('time', 'Time')) ?></div><div class="vs"><?= $e($transferTimeOnly ?: '---') ?></div></td>
    </tr></table>
    <?php endif; ?>
    <?php if (!empty($transfer['notes'])): ?><div class="vs" style="margin-top:3pt; font-style:italic;">&#9432; <?= $e($transfer['notes']) ?></div><?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Lead Guest -->
<div class="sec-h"><?= $e($T('lead_guest_information', 'Lead Guest Information')) ?></div>
<div class="infobox">
    <div class="inforow"><span class="infok"><?= $e($T('name', 'Name')) ?>: </span><span class="infov"><?= $e(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?></span></div>
    <div class="inforow"><span class="infok"><?= $e($T('email', 'Email')) ?>: </span><span class="infov"><?= $e($booking['email'] ?? '') ?></span></div>
    <div class="inforow"><span class="infok"><?= $e($T('phone', 'Phone')) ?>: </span><span class="infov"><?= $e(trim(($booking['phone_country_code'] ?? '') . ' ' . ($booking['phone'] ?? ''))) ?></span></div>
    <?php if (!empty($booking['nationality'])): ?>
    <div class="inforow"><span class="infok"><?= $e($T('nationality', 'Nationality')) ?>: </span><span class="infov"><?= $e($booking['nationality']) ?></span></div>
    <?php endif; ?>
</div>

<!-- Additional Travellers -->
<?php if (!empty($flatTravelers)): ?>
<div class="sec-h"><?= $e($T('additional_travelers', 'Additional Travelers')) ?></div>
<table class="infotable">
<?php foreach ($flatTravelers as $i => $traveler): ?>
    <tr>
        <td style="width:30%;"><span class="vs"><?= $e($T('traveler', 'Traveler')) ?> <?= $i + 1 ?></span></td>
        <td style="width:70%;"><div class="v" style="font-size:9pt;"><?= $e(trim(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? ''))) ?></div></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special Requests')) ?></div>
    <div class="vs"><?= nl2br($e($booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (EXACT old-template math; engine gave converted figures) -----
// Old umrah receipt: Subtotal = $subtotalAmount; Taxes & Fees = number_format($booking['tax'], 2);
// Total = $totalAmount. Preserved verbatim.
$priceRows = [
    ['label' => $T('subtotal', 'Subtotal'), 'value' => (string) ($subtotalAmount ?? '0.00'), 'muted' => true],
    ['label' => $T('taxes_fees', 'Taxes & Fees'), 'value' => number_format((float) ($booking['tax'] ?? 0), 2), 'muted' => true],
];

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => $T('umrah_booking', 'Umrah Booking'),
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
    'notesTitle' => $T('important_information', 'Important Information'),
    'notesItems' => array_values(array_filter([
        $T('umrah_passport_notice', 'Please ensure your passport is valid for at least 6 months.'),
        $T('umrah_present_voucher_notice', 'Present this voucher (digital or printed) at the airport and hotel.'),
        $T('umrah_arrive_early_notice', 'Verify your flight details and arrive at the airport at least 3 hours before departure.'),
        $T('umrah_checkin_times_notice', 'Check hotel check-in and check-out times.'),
        $T('umrah_emergency_notice', 'For any emergency, contact our 24/7 support line.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document is issued separately.'),
]);
