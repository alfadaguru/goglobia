<?php
/**
 * STAYS / HOTEL INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a hotel/stay booking (the no-price travel document is
 * stays/ticket.php). Builds a hotel summary hero + room/guest/dates detail body, then
 * feeds the premium _invoice_base.php: header band, bill-to, line-item price block
 * (subtotal / taxes / total), payment status + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed in:
 * $rawTotal, $totalAmount, $taxAmount, $pdfTaxAmount, $subtotalAmount, $currency,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 *
 * Vars: $booking,$bookingData,$travellersData,$settings,$businessName,$businessEmail,
 *   $businessPhone,$siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$pdfTaxAmount,$subtotalAmount,$bookingDate,$invoiceId.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$db = $GLOBALS['db'] ?? null;
$invoiceId = (string) ($booking['invoice_id'] ?? ($invoiceId ?? ''));
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Hotel basics -----------------------------------------------------------
$hotelName   = (string) ($bookingData['hotel_name'] ?? 'Hotel');
$hotelStars  = (int) ($bookingData['hotel_stars'] ?? 0);
$hotelCity   = trim((string) ($bookingData['hotel_city'] ?? $bookingData['city'] ?? ''));
$supplierEmail = $bookingData['hotel_email'] ?? ($bookingData['email'] ?? '');
$supplierPhone = $bookingData['hotel_phone_number'] ?? ($bookingData['phone_number'] ?? '');

$hotelAddress = trim((string) ($bookingData['hotel_address'] ?? ''));
if ($hotelAddress === '') {
    $hotelAddress = trim(implode(', ', array_filter([
        $bookingData['hotel_street'] ?? $bookingData['address'] ?? '',
        $bookingData['hotel_postal_code'] ?? '',
        $bookingData['hotel_city'] ?? $bookingData['city'] ?? '',
        $bookingData['hotel_country'] ?? $bookingData['country'] ?? '',
    ], static function ($part) { return $part !== null && trim((string) $part) !== ''; })));
}

$checkin  = $bookingData['checkin'] ?? '';
$checkout = $bookingData['checkout'] ?? '';
$nights   = (int) ($bookingData['nights'] ?? 1);
$adults   = (int) ($booking['adults'] ?? $bookingData['adults'] ?? 1);
$children = (int) ($booking['childs'] ?? $bookingData['children'] ?? 0);

// ---- HERO: hotel headline + city + stay window ------------------------------
ob_start();
?>
<div class="hero"><table><tr>
    <td style="width:66%;">
        <div class="mid" style="font-size:16pt; line-height:1.15;"><?= $e($hotelName) ?></div>
        <?php if ($hotelStars > 0): ?><div class="hero-dur" style="margin-top:3pt;"><?= str_repeat('&#9733;', max(1, min(5, $hotelStars))) ?></div><?php endif; ?>
        <?php if ($hotelCity !== ''): ?><div class="port-city" style="font-size:9pt;"><?= $e($hotelCity) ?></div><?php endif; ?>
        <?php if ($hotelAddress !== ''): ?><div class="port-apt"><?= $e($hotelAddress) ?></div><?php endif; ?>
    </td>
    <td style="width:34%; text-align:right;">
        <?php if ($checkin !== ''): ?>
        <div class="k"><?= $e($T('check_in', 'Check-in')) ?></div>
        <div class="v" style="font-size:10pt;"><?= $e(date('D, M d, Y', strtotime($checkin))) ?></div>
        <?php endif; ?>
        <?php if ($checkout !== ''): ?>
        <div class="k" style="margin-top:6pt;"><?= $e($T('check_out', 'Check-out')) ?></div>
        <div class="v" style="font-size:10pt;"><?= $e(date('D, M d, Y', strtotime($checkout))) ?></div>
        <?php endif; ?>
        <div class="vs" style="margin-top:6pt;"><b><?= (int) $nights ?></b> <?= $nights != 1 ? $e($T('nights', 'nights')) : $e($T('night', 'night')) ?></div>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: stay details, rooms, guest(s), travellers, policies --------------
ob_start();
?>
<!-- Stay summary -->
<div class="sec-h"><?= $e($T('stay_details', 'Stay Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('check_in', 'Check-in')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $checkin !== '' ? $e(date('D, M d, Y', strtotime($checkin))) : '&mdash;' ?></div>
            <div class="vs"><?= $e($T('from', 'From')) ?> 14:00</div>
        </td>
        <td style="width:50%;">
            <div class="k"><?= $e($T('check_out', 'Check-out')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $checkout !== '' ? $e(date('D, M d, Y', strtotime($checkout))) : '&mdash;' ?></div>
            <div class="vs"><?= $e($T('until', 'Until')) ?> 12:00</div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="vs">
                <b><?= (int) $nights ?></b> <?= $nights != 1 ? $e($T('nights', 'nights')) : $e($T('night', 'night')) ?>
                &middot; <b><?= (int) $adults ?></b> <?= $adults != 1 ? $e($T('adults', 'adults')) : $e($T('adult', 'adult')) ?>
                <?php if ($children > 0): ?>&middot; <b><?= (int) $children ?></b> <?= $children != 1 ? $e($T('children', 'children')) : $e($T('child', 'child')) ?><?php endif; ?>
                <?php if (!empty($booking['pnr'])): ?>&middot; <?= $e($T('booking_reference', 'Booking Reference')) ?>: <b><?= $e($booking['pnr']) ?></b><?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<!-- Rooms -->
<div class="sec-h"><?= $e($T('selected_rooms', 'Selected Rooms')) ?></div>
<table class="infotable">
<?php if (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])): ?>
    <?php foreach ($bookingData['selected_rooms'] as $room): ?>
    <tr>
        <td style="width:70%;">
            <div class="v" style="font-size:9pt;"><?= $e($room['room_name'] ?? $bookingData['room_type'] ?? 'Standard Room') ?></div>
            <div class="vs"><?= $e($room['option']['board_name'] ?? $bookingData['board_type'] ?? 'Room Only') ?></div>
        </td>
        <td style="width:30%; text-align:right;"><span class="vs">&times; <b><?= (int) ($room['quantity'] ?? 1) ?></b></span></td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td style="width:70%;">
            <div class="v" style="font-size:9pt;"><?= $e($bookingData['room_type'] ?? 'Standard Room') ?></div>
            <div class="vs"><?= $e($bookingData['board_type'] ?? 'Room Only') ?></div>
        </td>
        <td style="width:30%; text-align:right;"><span class="vs">&times; <b>1</b></span></td>
    </tr>
<?php endif; ?>
</table>

<!-- Lead guest -->
<div class="sec-h"><?= $e($T('guest_information', 'Guest Information')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:60%;">
            <div class="v" style="font-size:9pt;"><?= $e(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?></div>
            <div class="vs"><?= $e($booking['email'] ?? '') ?></div>
        </td>
        <td style="width:40%; text-align:right;">
            <?php $leadPhone = trim(($booking['phone_country_code'] ?? '') . ' ' . ($booking['phone'] ?? '')); ?>
            <?php if ($leadPhone !== ''): ?><div class="vs"><?= $e($leadPhone) ?></div><?php endif; ?>
            <?php if (!empty($booking['nationality'])): ?><div class="vs"><?= $e($booking['nationality']) ?></div><?php endif; ?>
        </td>
    </tr>
</table>

<!-- Travellers (per-room, if provided) -->
<?php if (!empty($travellersData['travelers']) && is_array($travellersData['travelers'])): ?>
<div class="sec-h"><?= $e($T('traveler_details', 'Traveler Details')) ?></div>
<table class="infotable">
<?php
foreach ($travellersData['travelers'] as $roomKey => $roomTravelers):
    $roomNum = ((int) filter_var((string) $roomKey, FILTER_SANITIZE_NUMBER_INT)) + 1;
    $travelerCount = 1;
    if (is_array($roomTravelers)):
        foreach ($roomTravelers as $travelerType => $traveler):
            if (is_array($traveler) && !empty($traveler['first_name'])):
                $type = strpos((string) $travelerType, 'adult') !== false ? ($T('adult', 'Adult')) : ($T('child', 'Child'));
                $nm = trim(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? ''));
?>
    <tr>
        <td style="width:30%;"><span class="vs"><?= $e($T('room', 'Room')) ?> <?= (int) $roomNum ?> &middot; <?= $e($type) ?></span></td>
        <td style="width:70%; text-align:right;">
            <div class="v" style="font-size:9pt;"><?= $e($nm) ?><?php if (!empty($traveler['age'])): ?> <span class="vs">(<?= $e($T('age', 'Age')) ?>: <?= $e($traveler['age']) ?>)</span><?php endif; ?></div>
        </td>
    </tr>
<?php
            endif;
        endforeach;
    endif;
endforeach;
?>
</table>
<?php endif; ?>

<?php if (!empty($supplierEmail) || !empty($supplierPhone)): ?>
<div class="card" style="margin-top:10pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('property_contact', 'Property contact')) ?></div>
    <?php if (!empty($supplierEmail)): ?><div class="vs"><?= $e($supplierEmail) ?></div><?php endif; ?>
    <?php if (!empty($supplierPhone)): ?><div class="vs"><?= $e($supplierPhone) ?></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e($booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>

<?php
// ---- Cancellation policy (amounts in the guest's display currency) -----------
$cancellationPolicies = [];
if (!empty($bookingData['cancellation_policy'])) {
    $cancellationPolicies[] = $bookingData['cancellation_policy'];
}
$pdfBookingCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? $currency ?? 'USD')));
$pdfDisplayCurrency = strtoupper(trim((string) (
    $_SESSION['app_currency']
    ?? $bookingData['display_currency']
    ?? $pdfBookingCurrency
)));
$pdfPolicyRate = 1.0;
if (
    $pdfDisplayCurrency !== ''
    && $pdfBookingCurrency !== ''
    && $pdfDisplayCurrency !== $pdfBookingCurrency
    && function_exists('getCurrencyConversionRate')
    && isset($db)
) {
    $pdfPolicyRate = (float) getCurrencyConversionRate($db, $pdfBookingCurrency, $pdfDisplayCurrency);
    if ($pdfPolicyRate <= 0) {
        $pdfPolicyRate = 1.0;
        $pdfDisplayCurrency = $pdfBookingCurrency;
    }
} elseif ($pdfDisplayCurrency === '') {
    $pdfDisplayCurrency = $pdfBookingCurrency;
}
if (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        $policyList = $room['option']['cancellation_policies'] ?? [];
        $roomPolicyText = '';
        if (!empty($policyList) && is_array($policyList) && function_exists('staysFormatCancellationPolicyText')) {
            $convertedPolicies = [];
            foreach ($policyList as $policyRow) {
                if (!is_array($policyRow)) { continue; }
                $convertedPolicies[] = [
                    'amount' => round(((float) ($policyRow['amount'] ?? 0)) * $pdfPolicyRate, 2),
                    'from' => $policyRow['from'] ?? '',
                ];
            }
            $roomPolicyText = staysFormatCancellationPolicyText($convertedPolicies, $pdfDisplayCurrency);
        }
        if ($roomPolicyText === '' && !empty($room['option']['cancellation_text'])) {
            $roomPolicyText = $room['option']['cancellation_text'];
        }
        if ($roomPolicyText !== '') {
            $cancellationPolicies[] = $roomPolicyText;
        }
    }
}
$cancellationPolicies = array_unique(array_filter($cancellationPolicies));
?>
<?php if (!empty($cancellationPolicies)): ?>
<div class="sec-h"><?= $e($T('cancellation_policy', 'Cancellation Policy')) ?></div>
<div class="card"><div class="card-body">
    <?php foreach ($cancellationPolicies as $policy): ?>
        <div class="vs" style="margin-bottom:3pt;"><?= nl2br($e($policy)) ?></div>
    <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php
// ---- Excluded taxes (payable at hotel) — monetary ---------------------------
$excludedTaxes = [];
if (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        if (!empty($room['option']['excluded_taxes'])) {
            foreach ($room['option']['excluded_taxes'] as $tax) {
                $excludedTaxes[] = $tax;
            }
        }
    }
}
?>
<?php if (!empty($excludedTaxes)): ?>
<div class="sec-h"><?= $e($T('excluded_taxes', 'Excluded Taxes (Payable at hotel)')) ?></div>
<table class="infotable">
<?php foreach ($excludedTaxes as $tax):
    $taxFromCurrency = strtoupper(trim((string) ($tax['clientCurrency'] ?? $booking['currency_markup'] ?? 'USD')));
    $taxAmountBase = (float) ($tax['clientAmount'] ?? 0);
    $taxRate = 1.0;
    if (
        $taxFromCurrency !== ''
        && $pdfDisplayCurrency !== ''
        && $pdfDisplayCurrency !== $taxFromCurrency
        && function_exists('getCurrencyConversionRate')
        && isset($db)
    ) {
        $taxRate = (float) getCurrencyConversionRate($db, $taxFromCurrency, $pdfDisplayCurrency);
        if ($taxRate <= 0) { $taxRate = 1.0; }
    }
    $taxDisplayCurrency = $pdfDisplayCurrency !== '' ? $pdfDisplayCurrency : $taxFromCurrency;
    $taxDisplayAmount = round($taxAmountBase * $taxRate, 2);
?>
    <tr>
        <td style="width:60%;"><span class="vs"><?= $e($tax['subType'] ?? 'Tax') ?></span></td>
        <td style="width:40%; text-align:right;"><div class="v" style="font-size:9pt;"><?= $e($taxDisplayCurrency) ?> <?= $e(number_format($taxDisplayAmount, 2)) ?></div></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (same math the old stays invoice used; engine gave converted figures) ----
// Old summary: Subtotal ($subtotalAmount) / Taxes & Fees (number_format($pdfTaxAmount ?? $booking['tax'], 2)) / Total ($totalAmount).
$priceRows = [];
$priceRows[] = ['label' => $T('subtotal', 'Subtotal'), 'value' => (string) ($subtotalAmount ?? '0.00'), 'muted' => true];
$priceRows[] = ['label' => $T('taxes_fees', 'Taxes & Fees'), 'value' => number_format((float) ($pdfTaxAmount ?? $booking['tax'] ?? 0), 2), 'muted' => true];

// ---- Important information (Hotelbeds rate comments) -------------------------
$importantNotes = [];
if (!empty($booking['pnr'])) {
    $bookingResp = is_string($booking['booking_response'] ?? null) ? json_decode($booking['booking_response']) : ($booking['booking_response'] ?? null);
    if ($bookingResp && isset($bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments)) {
        $importantNotes[] = $bookingResp->booking->hotel->rooms[0]->rates[0]->rateComments;
    }
}
if (empty($importantNotes) && !empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $room) {
        if (!empty($room['option']['rate_comments'])) {
            $importantNotes[] = $room['option']['rate_comments'];
        }
    }
}
$importantNotes = array_values(array_unique(array_filter($importantNotes)));

$notesItems = [];
foreach ($importantNotes as $note) { $notesItems[] = nl2br($e($note)); }
// Standard guest checklist items (always shown), after any property rate comments.
$notesItems[] = $e($T('stays_bring_ref_notice', 'Please bring your booking reference number and a valid photo ID at check-in.'));
$notesItems[] = $e($T('stays_checkin_times_notice', 'Check-in is from 14:00 and check-out is until 12:00 (local hotel time).'));
$notesItems[] = $e($T('stays_present_voucher_notice', 'Present this document at the hotel reception upon arrival.'));

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Hotel Booking',
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
    'notesItems' => $notesItems,
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document (voucher) is issued separately.'),
]);
