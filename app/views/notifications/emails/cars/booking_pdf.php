<?php
/**
 * CAR RENTAL INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a car-rental booking. Builds a compact vehicle
 * summary (hero: pickup -> dropoff) + rental detail body, then feeds the premium
 * _invoice_base.php: header band, bill-to, line-item price block (rental charges /
 * taxes / total), payment status + method, notes.
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

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Car data (same extraction the old voucher used) ------------------------
$carData       = $bookingData['car_data'] ?? [];
$searchParams  = $bookingData['search_params'] ?? [];

$carName        = $carData['name'] ?? $bookingData['car_name'] ?? 'Car Rental';
$pickupLocation = $searchParams['pickup_location'] ?? $bookingData['pickup_location'] ?? '';
$dropoffLocation= $searchParams['dropoff_location'] ?? $bookingData['dropoff_location'] ?? '';
$pickupDate     = $searchParams['date'] ?? $bookingData['pickup_date'] ?? '';
$dropoffDate    = $searchParams['return_date'] ?? $bookingData['dropoff_date'] ?? '';

$formattedPickup  = !empty($pickupDate)  ? date('D, M d, Y \a\t H:i', strtotime($pickupDate))  : 'N/A';
$formattedDropoff = !empty($dropoffDate) ? date('D, M d, Y \a\t H:i', strtotime($dropoffDate)) : 'N/A';

// Rental duration (whole days, min 1) — unchanged from the old voucher.
$pickupTs  = strtotime($pickupDate);
$dropoffTs = strtotime($dropoffDate);
$days = ($pickupTs && $dropoffTs) ? ceil(($dropoffTs - $pickupTs) / 86400) : 1;
if ($days < 1) $days = 1;

$vehicleClass = $carData['type'] ?? $carData['class'] ?? $carData['category'] ?? 'Rental Vehicle';

// ---- HERO: vehicle name + pickup -> dropoff ---------------------------------
ob_start();
?>
<div class="hero"><table><tr>
    <td style="width:100%; text-align:center;">
        <div class="mid" style="margin-bottom:2pt;"><?= $e($carName) ?></div>
        <div class="vs"><?= $e($vehicleClass) ?></div>
    </td>
</tr></table>
<table style="width:100%; border-collapse:collapse; margin-top:10pt;"><tr>
    <td style="width:42%;">
        <div class="k"><?= $e($T('pickup', 'Pick-up')) ?></div>
        <div class="v" style="font-size:10pt;"><?= $e($formattedPickup) ?></div>
        <?php if ($pickupLocation !== ''): ?><div class="vs"><?= $e($pickupLocation) ?></div><?php endif; ?>
    </td>
    <td style="width:16%; text-align:center; vertical-align:middle;">
        <div class="hero-line"></div><div class="hero-plane">&rarr;</div>
        <div class="hero-stops"><?= (int) $days ?> <?= $e($days > 1 ? $T('days', 'Days') : $T('day', 'Day')) ?></div>
    </td>
    <td style="width:42%; text-align:right;">
        <div class="k"><?= $e($T('dropoff', 'Drop-off')) ?></div>
        <div class="v" style="font-size:10pt;"><?= $e($formattedDropoff) ?></div>
        <?php if ($dropoffLocation !== ''): ?><div class="vs"><?= $e($dropoffLocation) ?></div><?php endif; ?>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: renter/driver + rental detail ------------------------------------
ob_start();
?>
<div class="sec-h"><?= $e($T('vehicle_details', 'Vehicle Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('vehicle', 'Vehicle')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($carName) ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k"><?= $e($T('rental_duration', 'Rental Duration')) ?></div>
            <div class="v" style="font-size:9pt;"><?= (int) $days ?> <?= $e($days > 1 ? $T('days', 'Days') : $T('day', 'Day')) ?></div>
        </td>
    </tr>
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('pickup_location', 'Pick-up Location')) ?></div>
            <div class="vs"><?= $e($pickupLocation !== '' ? $pickupLocation : 'N/A') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k"><?= $e($T('dropoff_location', 'Drop-off Location')) ?></div>
            <div class="vs"><?= $e($dropoffLocation !== '' ? $dropoffLocation : 'N/A') ?></div>
        </td>
    </tr>
</table>

<div class="sec-h"><?= $e($T('driver_information', 'Driver Information')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:100%;">
            <div class="v" style="font-size:9pt;"><?= $e(trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''))) ?></div>
            <div class="vs"><?= $e(trim(implode(' · ', array_filter([
                (string) ($booking['email'] ?? ''),
                trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
            ])))) ?></div>
        </td>
    </tr>
</table>
<?php if (!empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e($booking['special_requests'])) ?></div>
</div></div>
<?php endif; ?>
<?php if (!empty($carData['cancellation_policy'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('cancellation_policy', 'Cancellation policy')) ?></div>
    <div class="vs"><?= nl2br($e($carData['cancellation_policy'])) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows -------------------------------------------------------------
// MONEY: display the engine-converted figures (same underlying money as the old
// voucher's price_original/tax/price_markup, converted to the guest's display
// currency by GENERATE_BOOKING_PDF). Label stays "Rental Charges" (car convention).
$taxConverted = (float) ($taxAmount ?? 0);
$priceRows = [];
$priceRows[] = ['label' => $T('rental_charges', 'Rental Charges'), 'value' => (string) ($subtotalAmount ?? number_format(max(0, (float) ($rawTotal ?? 0) - (float) ($taxAmountRaw ?? 0)), 2)), 'muted' => true];
if ($taxConverted > 0) {
    $priceRows[] = ['label' => $T('taxes_fees', 'Taxes & Fees'), 'value' => number_format($taxConverted, 2), 'muted' => true];
}

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Car Rental Booking',
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
        $T('cars_license_notice', "Valid driver's license and credit card required at pickup."),
        $T('cars_main_driver_notice', 'The main driver must be present at pickup.'),
        $T('cars_inspect_notice', 'Inspect the vehicle for existing damage before leaving the lot.'),
        $T('cars_fuel_notice', 'Return the vehicle with the same fuel level as at pickup.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your rental voucher is issued separately.'),
]);
