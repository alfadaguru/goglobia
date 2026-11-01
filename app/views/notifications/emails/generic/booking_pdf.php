<?php
/**
 * GENERIC INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The FALLBACK priced money document for services WITHOUT a bespoke template.
 * GENERATE_BOOKING_PDF routes module_type 'esim' and 'insurance' here (all other
 * 9 services keep their own template). It MUST stay SERVICE-AGNOSTIC — it never
 * assumes flight/hotel/route fields; it renders whatever generic booking fields
 * are present (service name, reference, status, date, confirmation #, and, when
 * supplied, a package/plan + location block + traveller list), then feeds the
 * premium _invoice_base.php: header band, bill-to, price block, status + method.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $rawTotal, $totalAmount, $taxAmount, $subtotalAmount, $currency, $statusText,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 *
 * Vars: $booking,$bookingData,$travellersData,$settings,$businessName,$businessEmail,
 *   $businessPhone,$siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate,$docType, (+$mpdf if set).
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

// Service name — human label for whatever module routed here (esim / insurance / …).
$moduleType = ucfirst(strtolower((string) ($booking['module_type'] ?? 'Booking')));

// Optional generic product descriptors (present on eSIM; harmless when absent).
$packageName = trim((string) ($bookingData['package_name'] ?? $bookingData['plan_name'] ?? ''));
$country     = trim((string) ($bookingData['country'] ?? ''));
$dataAmount  = trim((string) ($bookingData['data'] ?? ''));
$validity    = trim((string) ($bookingData['validity'] ?? $bookingData['validity_days'] ?? ''));

// Flatten any traveller/passenger list for display (service-agnostic).
$pax = [];
$src = $travellersData['travelers'] ?? $travellersData ?? [];
if (is_array($src)) {
    foreach ($src as $v) {
        if (is_array($v) && trim((string) ($v['first_name'] ?? '')) !== '') { $pax[] = $v; }
    }
}

// ---- BODY: generic booking summary + optional product + travellers -----------
ob_start();
?>
<div class="sec-h"><?= $e($T('booking_details', 'Booking Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;"><div class="k"><?= $e($T('service', 'Service')) ?></div><div class="v" style="font-size:9pt;"><?= $e($moduleType) ?></div></td>
        <td style="width:50%; text-align:right;"><div class="k"><?= $e($T('reference', 'Reference')) ?></div><div class="v" style="font-size:9pt;"><?= $e($invoiceId) ?></div></td>
    </tr>
    <tr>
        <td style="width:50%;"><div class="k"><?= $e($T('status', 'Status')) ?></div><div class="vs"><?= $e(ucfirst((string) ($booking['booking_status'] ?? '—'))) ?></div></td>
        <td style="width:50%; text-align:right;"><div class="k"><?= $e($T('date', 'Date')) ?></div><div class="vs"><?= $e((string) ($booking['created_at'] ?? date('Y-m-d'))) ?></div></td>
    </tr>
    <?php if (!empty($booking['pnr'])): ?>
    <tr>
        <td colspan="2"><div class="k"><?= $e($T('confirmation', 'Confirmation #')) ?></div><div class="v" style="font-size:9pt;"><?= $e((string) $booking['pnr']) ?></div></td>
    </tr>
    <?php endif; ?>
</table>

<?php if ($packageName !== '' || $country !== '' || $dataAmount !== '' || $validity !== ''): ?>
<div class="card" style="margin-top:10pt;"><div class="card-body">
    <?php if ($packageName !== ''): ?><div class="v" style="margin-bottom:3pt;"><?= $e($packageName) ?></div><?php endif; ?>
    <?php if ($country !== ''): ?><div class="vs"><?= $e($T('coverage', 'Coverage')) ?>: <b><?= $e($country) ?></b></div><?php endif; ?>
    <?php if ($dataAmount !== ''): ?><div class="vs"><?= $e($T('data', 'Data')) ?>: <b><?= $e($dataAmount) ?></b></div><?php endif; ?>
    <?php if ($validity !== ''): ?><div class="vs"><?= $e($T('validity', 'Validity')) ?>: <b><?= $e($validity) ?></b></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if (!empty($pax)): ?>
<div class="sec-h"><?= $e($T('travellers', 'Travellers')) ?><?= count($pax) > 1 ? ' &middot; ' . count($pax) : '' ?></div>
<table class="infotable">
<?php foreach ($pax as $p):
    $nm = trim(((string) ($p['title'] ?? '')) . ' ' . ((string) ($p['first_name'] ?? '')) . ' ' . ((string) ($p['last_name'] ?? '')));
?>
    <tr>
        <td style="width:60%;"><div class="v" style="font-size:9pt;"><?= $e($nm ?: 'Traveller') ?></div></td>
        <td style="width:40%; text-align:right;"><span class="vs"><?= $e((string) ($p['nationality'] ?? '')) ?></span></td>
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

// ---- Price rows (engine gave converted figures; display only, no re-pricing) ----
$priceRows = [];
$priceRows[] = ['label' => $T('subtotal', 'Subtotal'), 'value' => (string) ($subtotalAmount ?? number_format(0, 2)), 'muted' => true];
if ((float) ($taxAmount ?? 0) > 0) {
    $priceRows[] = ['label' => $T('taxes_fees', 'Taxes & fees'), 'value' => number_format((float) $taxAmount, 2), 'muted' => true];
}

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Booking',
    'paid' => $paid, 'statusText' => $statusText,
    'billToName' => trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')),
    'billToEmail' => (string) ($booking['email'] ?? ''),
    'billToPhone' => trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
    'heroHtml' => '',
    'bodyHtml' => $bodyHtml,
    'currency' => $currency,
    'priceRows' => $priceRows,
    'totalLabel' => $T('total_amount', 'Total amount'),
    'totalValue' => (string) ($totalAmount ?? number_format((float) ($rawTotal ?? 0), 2)),
    'paymentMethod' => (string) ($booking['payment_gateway'] ?? ''),
    'notesTitle' => $T('important_information', 'Important information'),
    'notesItems' => array_values(array_filter([
        $T('generic_keep_record_notice', 'Please keep this invoice for your records.'),
        $T('generic_separate_document_notice', 'This invoice reflects the amount for your booking; your travel/service document is issued separately.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature.'),
]);
