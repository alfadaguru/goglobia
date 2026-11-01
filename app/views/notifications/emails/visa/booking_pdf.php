<?php
/**
 * VISA INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a visa inquiry/application. Builds a compact visa
 * summary (visa type + country + entry date) + applicant list, then feeds the
 * premium _invoice_base.php: header band, bill-to, line-item price block
 * (price-per-person × applicants / total), payment status + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $rawTotal, $totalAmount, $taxAmount, $subtotalAmount, $currency, $statusText,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 * NOTE: the previous visa template was a simple document — it showed Price Per
 * Person × Total Applicants and a "Total Amount" of $totalAmount, with NO tax
 * line. That exact money display is preserved below.
 *
 * Visa uses $bookingData['entry_date'] (no checkin/checkout/nights) — the engine
 * sets $checkinDate from entry_date for visa; we display entry_date directly.
 *
 * Vars: $booking,$bookingData,$settings,$businessName,$businessEmail,$businessPhone,
 *   $siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate,$travellersData.
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

// ---- Applicants -------------------------------------------------------------
$applicants = (!empty($travellersData) && is_array($travellersData)) ? $travellersData : [];
$applicantCount = $bookingData['travelers_count'] ?? count($bookingData['travelers'] ?? ($applicants ?: []));

// ---- HERO: visa summary (visa type · from → to · entry date) -----------------
$visaType   = (string) ($bookingData['visa_type_name'] ?? 'Visa Application');
$fromName   = (string) ($bookingData['from_country_name'] ?? '');
$toName     = (string) ($bookingData['to_country_name'] ?? '');
$entryDate  = !empty($bookingData['entry_date']) ? date('D, M d, Y', strtotime((string) $bookingData['entry_date'])) : 'To be decided';
$processing = (string) ($bookingData['processing_speed_name'] ?? 'Standard');

ob_start(); ?>
<div class="hero"><table><tr>
    <td style="width:62%;">
        <div class="k"><?= $e($T('visa_type', 'Visa Type')) ?></div>
        <div class="mid" style="margin-top:2pt;"><?= $e($visaType) ?></div>
        <?php if ($fromName !== '' || $toName !== ''): ?>
        <div class="vs" style="margin-top:4pt;"><?= $e($fromName ?: '—') ?> &rarr; <?= $e($toName ?: '—') ?></div>
        <?php endif; ?>
    </td>
    <td style="width:38%; text-align:right;">
        <div class="k"><?= $e($T('entry_date', 'Intended Entry Date')) ?></div>
        <div class="v" style="margin-top:2pt;"><?= $e($entryDate) ?></div>
        <div class="vs" style="margin-top:4pt;"><?= $e($T('processing', 'Processing')) ?>: <b><?= $e($processing) ?></b></div>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: main applicant + applicants information --------------------------
ob_start();
?>
<div class="sec-h"><?= $e($T('main_applicant', 'Main Applicant')) ?></div>
<div class="infobox">
    <div class="inforow"><span class="infok"><?= $e($T('name', 'Name')) ?>:</span> <span class="infov"><?= $e(trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''))) ?></span></div>
    <div class="inforow"><span class="infok"><?= $e($T('email', 'Email')) ?>:</span> <span class="infov"><?= $e((string) ($booking['email'] ?? '')) ?></span></div>
    <div class="inforow"><span class="infok"><?= $e($T('phone', 'Phone')) ?>:</span> <span class="infov"><?= $e(trim((string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? ''))) ?></span></div>
</div>

<?php if (!empty($applicants)): ?>
<div class="sec-h"><?= $e($T('applicants_information', 'Applicants Information')) ?><?= count($applicants) > 1 ? ' &middot; ' . count($applicants) : '' ?></div>
<?php foreach ($applicants as $index => $traveler):
    $apName = trim((string) ($traveler['title'] ?? '') . ' ' . (string) ($traveler['first_name'] ?? '') . ' ' . (string) ($traveler['last_name'] ?? ''));
?>
<div class="card">
    <div class="card-top"><table><tr>
        <td><span class="card-title"><?= $e($T('applicant', 'Applicant')) ?> <?= (int) $index + 1 ?></span><?= $apName !== '' ? ' <span class="card-sub">' . $e($apName) . '</span>' : '' ?></td>
    </tr></table></div>
    <div class="card-body"><table>
        <tr>
            <td style="width:50%;">
                <div class="k"><?= $e($T('nationality', 'Nationality')) ?></div>
                <div class="vs"><?= $e((string) ($traveler['nationality'] ?? $traveler['nationality_name'] ?? '-')) ?></div>
            </td>
            <td style="width:50%;">
                <div class="k"><?= $e($T('date_of_birth', 'Date of Birth')) ?></div>
                <div class="vs"><?= !empty($traveler['dob']) ? $e((string) $traveler['dob']) : '-' ?></div>
            </td>
        </tr>
        <tr>
            <td style="width:50%; padding-top:6pt;">
                <div class="k"><?= $e($T('passport_number', 'Passport Number')) ?></div>
                <div class="vs"><?= $e((string) ($traveler['passport_number'] ?? '-')) ?></div>
            </td>
            <td style="width:50%; padding-top:6pt;">
                <div class="k"><?= $e($T('passport_expiry', 'Passport Expiry')) ?></div>
                <div class="vs"><?= $e((string) ($traveler['passport_expiry'] ?? $traveler['passport_expiry_date'] ?? '-')) ?></div>
            </td>
        </tr>
        <?php if (!empty($traveler['gender'])): ?>
        <tr>
            <td colspan="2" style="padding-top:6pt;">
                <div class="k"><?= $e($T('gender', 'Gender')) ?></div>
                <div class="vs"><?= $e(ucfirst((string) $traveler['gender'])) ?></div>
            </td>
        </tr>
        <?php endif; ?>
    </table></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($bookingData['special_requests']) || !empty($booking['special_requests'])): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="k" style="margin-bottom:3pt;"><?= $e($T('special_requests', 'Special requests')) ?></div>
    <div class="vs"><?= nl2br($e((string) ($bookingData['special_requests'] ?: $booking['special_requests']))) ?></div>
</div></div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (preserve EXACT old visa display: per-person × applicants) ----
// Old template showed, only when $totalAmount > 0:
//   "Price Per Person"  => $currency . number_format(price_per_traveler|price_per_person|price, 2)
//   "Total Applicants"  => "× {travelers_count}"
//   "Total Amount"      => $currency . $totalAmount   (NO tax line)
$pricePerPerson = $bookingData['price_per_traveler'] ?? $bookingData['price_per_person'] ?? $bookingData['price'] ?? 0;
$priceRows = [];
if ((float) ($totalAmount ?? 0) > 0) {
    $priceRows[] = ['label' => $T('price_per_person', 'Price Per Person'), 'value' => number_format((float) $pricePerPerson, 2), 'muted' => true];
    $priceRows[] = ['label' => $T('total_applicants', 'Total Applicants'), 'value' => '× ' . (int) $applicantCount, 'muted' => true, 'plain' => true];
}

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Visa Application',
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
        $T('visa_inquiry_review_notice', 'Your visa inquiry has been received and is currently under review.'),
        $T('visa_valid_passport_notice', 'A valid passport is required for all applicants.'),
        $T('visa_processing_notice', 'Processing times vary by destination and are not guaranteed.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your visa documents are issued separately once your application is approved.'),
]);
