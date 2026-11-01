<?php
/**
 * FERRY INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a ferry booking. Builds a ferry crossing summary
 * (hero: from port -> to port + date) + sailing segments / passengers / vehicles /
 * pets / bonuses detail body, then feeds the premium _invoice_base.php: header band,
 * bill-to, line-item price block (subtotal / taxes / total), payment status + method,
 * notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $subtotalAmount, $totalAmount, $taxAmount, $currency, $rawTotal, $booking,
 * $bookingData, $travellersData, etc. We only DISPLAY them (no re-pricing here).
 * The ferry price summary preserves the exact prior logic:
 *   Subtotal        = $subtotalAmount
 *   Taxes & Fees    = number_format((float)($booking['tax'] ?? 0), 2)
 *   Total           = $totalAmount
 *   Payment Method  = $booking['payment_gateway']
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';
require_once __DIR__ . '/../../../../../modules/ferries/kikoto/api.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Ferries data extraction (unchanged from the prior invoice) --------------
$confirmed = $bookingData['confirmed'] ?? [];
$ferriesPorts = [];
$portsCache = __DIR__ . '/../../../../cache/kikoto_ports_en.json';
if (file_exists($portsCache)) {
    $ferriesPorts = json_decode(file_get_contents($portsCache), true) ?: [];
}
$portName = function (int $id) use ($ferriesPorts): string {
    foreach ($ferriesPorts as $p) { if ((int) ($p['id'] ?? 0) === $id) return $p['name'] ?? ''; }
    return 'Port ' . $id;
};

if (!empty($confirmed['sailings'])) {
    $sailings = $confirmed['sailings'];
} else {
    $draft = $bookingData['draft']['selected_sailing'] ?? [];
    $sailings = !empty($draft) ? [$draft] : [];
    if (!empty($bookingData['draft']['return_sailing'])) $sailings[] = $bookingData['draft']['return_sailing'];
}

$locators  = $bookingData['locators'] ?? [];
$reference = $bookingData['reference'] ?? $booking['pnr'] ?? '';
$locator   = implode(', ', $locators) ?: ($booking['pnr'] ?? '');

$pdfVehicles = $bookingData['vehicles'] ?? $bookingData['draft']['vehicles'] ?? [];
$pdfPets     = $bookingData['pets']     ?? $bookingData['draft']['pets']     ?? [];
if (!is_array($pdfVehicles)) $pdfVehicles = [];
if (!is_array($pdfPets))     $pdfPets     = [];

$pdfBonusIds = _kikoto_normalize_bonus_ids(
    $bookingData['bonuses'] ?? $bookingData['draft']['bonuses'] ?? []
);
if (empty($pdfBonusIds) && !empty($bookingData['draft']) && is_array($bookingData['draft'])) {
    $pdfBonusIds = _kikoto_extract_bonuses_from_draft($bookingData['draft']);
}
$pdfBonusDetails = $bookingData['bonus_details'] ?? $bookingData['draft']['bonus_details'] ?? [];
$pdfBonuses = _kikoto_resolve_bonus_labels($pdfBonusIds, is_array($pdfBonusDetails) ? $pdfBonusDetails : []);
$pdfCoupon = trim((string) ($bookingData['coupon'] ?? $bookingData['draft']['coupon'] ?? ''));
$pdfHasBonusOrCoupon = !empty($pdfBonuses) || $pdfCoupon !== '';

$ticketTypesById = [];
foreach (($bookingData['draft']['selected_sailing']['shipping_company']['ticket_types'] ?? []) as $tt) {
    if (is_array($tt) && isset($tt['id'])) {
        $ticketTypesById[(int) $tt['id']] = $tt;
    }
}
$ticketLabel = function (int $typeId) use ($ticketTypesById): string {
    if ($typeId <= 0) return '';
    $tt = $ticketTypesById[$typeId] ?? null;
    if (!$tt) return (string) $typeId;
    $name = trim((string) ($tt['name'] ?? ''));
    $desc = trim((string) ($tt['description'] ?? ''));
    return $desc !== '' ? ($name . ' — ' . $desc) : ($name !== '' ? $name : (string) $typeId);
};

$pdfTravellers = (isset($travellersData) && is_array($travellersData) && array_is_list($travellersData)) ? $travellersData : [];
$pdfCategoryCounts = ['adult' => 0, 'child' => 0, 'infant' => 0];
$pdfPassengerHeading = function (array $t) use (&$pdfCategoryCounts, $ticketTypesById): string {
    return _kikoto_passenger_heading($t, $pdfCategoryCounts, $ticketTypesById);
};
$pdfLinkedPassengerName = function (int $passengerId) use ($pdfTravellers): string {
    return _kikoto_resolve_traveller_name($passengerId, $pdfTravellers);
};

$firstSailing = $sailings[0] ?? [];
$depPort = $portName((int) ($firstSailing['departure_port_id'] ?? 0));
$arrPort = $portName((int) ($firstSailing['arrival_port_id'] ?? $firstSailing['destination_port_id'] ?? 0));
$depDT   = $firstSailing['departure_datetime'] ?? '';
$formattedDate = $depDT ? date('D, M d, Y', strtotime($depDT)) : 'N/A';
$isRoundTrip = count($sailings) > 1;

// ---- HERO: ferry crossing (from port -> to port + date) ---------------------
ob_start();
?>
<div class="hero"><table><tr>
    <td style="width:40%;">
        <div class="port-city" style="font-size:16pt;"><?= $e($depPort ?: '—') ?></div>
        <div class="port-apt"><?= $e($T('departure', 'Departure')) ?></div>
    </td>
    <td style="width:20%;" class="hero-mid">
        <?php if ($formattedDate !== '' && $formattedDate !== 'N/A'): ?><div class="hero-dur"><?= $e($formattedDate) ?></div><?php endif; ?>
        <div class="hero-line"></div><div class="hero-plane">&#9973;</div>
        <div class="hero-stops"><?= $isRoundTrip ? $e($T('round_trip', 'Round trip')) : $e($T('one_way', 'One way')) ?></div>
    </td>
    <td style="width:40%; text-align:right;">
        <div class="port-city" style="font-size:16pt;"><?= $e($arrPort ?: '—') ?></div>
        <div class="port-apt"><?= $e($T('arrival', 'Arrival')) ?></div>
    </td>
</tr></table></div>
<?php
$heroHtml = ob_get_clean();

// ---- BODY: sailing segments + passengers + vehicles + pets + bonuses ---------
ob_start();
?>
<div class="sec-h"><?= $e($T('ferry_details', 'Ferry Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('crossing', 'Crossing')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($depPort) ?> &rarr; <?= $e($arrPort) ?></div>
            <div class="vs"><?= $e($formattedDate) ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <?php if ($locator !== ''): ?><div class="k"><?= $e($T('locator', 'Locator')) ?></div><div class="v" style="font-size:9pt;"><?= $e($locator) ?></div><?php endif; ?>
            <?php if ($reference !== ''): ?><div class="vs"><?= $e($T('reference', 'Ref')) ?>: <?= $e($reference) ?></div><?php endif; ?>
        </td>
    </tr>
</table>

<?php if (!empty($sailings)): ?>
<div class="sec-h"><?= $e($T('sailings', 'Sailings')) ?></div>
<table class="infotable">
<?php foreach ($sailings as $i => $s):
    $sDepDT  = $s['departure_datetime'] ?? '';
    $sArrDT  = $s['arrival_datetime']   ?? '';
    $sDepTime = $sDepDT ? date('H:i', strtotime($sDepDT)) : '';
    $sArrTime = $sArrDT ? date('H:i', strtotime($sArrDT)) : '';
    $sDepDate = $sDepDT ? date('M d, Y', strtotime($sDepDT)) : '';
    $sDepPort = $portName((int) ($s['departure_port_id'] ?? 0));
    $sArrPort = $portName((int) ($s['arrival_port_id'] ?? $s['destination_port_id'] ?? 0));
    $acc      = $s['accommodations'][0] ?? [];
    $sl       = $locators[$i] ?? '';
    $legLabel = $i === 0 ? $T('outbound', 'Outbound') : $T('return', 'Return');
?>
    <tr>
        <td style="width:34%;">
            <div class="v" style="font-size:9pt;"><?= $e(strtoupper((string) $legLabel)) ?><?= !empty($acc['title']) ? ' <span class="vs">' . $e($acc['title']) . '</span>' : '' ?></div>
            <?php if ($sl !== ''): ?><div class="vs"><?= $e($T('locator', 'Locator')) ?>: <?= $e($sl) ?></div><?php endif; ?>
        </td>
        <td style="width:46%;">
            <div class="v" style="font-size:9pt;"><?= $e($sDepPort) ?> &rarr; <?= $e($sArrPort) ?></div>
            <div class="vs"><?= $e($sDepTime) . ($sArrTime !== '' ? ' &rarr; ' . $e($sArrTime) : '') ?></div>
        </td>
        <td style="width:20%; text-align:right;">
            <?php if ($sDepDate !== ''): ?><div class="v" style="font-size:8.5pt;"><?= $e($sDepDate) ?></div><?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php
// Occupancy summary line (adults/children/vehicles/pets/bonuses/coupon).
$occBits = [];
$adults = (int) ($booking['adults'] ?? 1);
$occBits[] = $adults . ' ' . ($adults > 1 ? $T('adults', 'Adults') : $T('adult', 'Adult'));
$childs = (int) ($booking['childs'] ?? 0);
if ($childs > 0) { $occBits[] = $childs . ' ' . ($childs > 1 ? $T('children', 'Children') : $T('child', 'Child')); }
if (count($pdfVehicles) > 0) { $occBits[] = count($pdfVehicles) . ' ' . (count($pdfVehicles) > 1 ? $T('vehicles', 'Vehicles') : $T('vehicle', 'Vehicle')); }
if (count($pdfPets) > 0) { $occBits[] = count($pdfPets) . ' ' . (count($pdfPets) > 1 ? $T('pets', 'Pets') : $T('pet', 'Pet')); }
if (count($pdfBonuses) > 0) { $occBits[] = count($pdfBonuses) . ' ' . (count($pdfBonuses) > 1 ? $T('bonuses', 'Bonuses') : $T('bonus', 'Bonus')); }
if ($pdfCoupon !== '') { $occBits[] = $T('coupon', 'Coupon'); }
?>
<?php if (!empty($occBits)): ?>
<div class="card" style="margin-top:8pt;"><div class="card-body">
    <div class="vs"><?= $e(implode(' &middot; ', $occBits)) ?></div>
</div></div>
<?php endif; ?>

<?php if (!empty($pdfTravellers)): ?>
<div class="sec-h"><?= $e($T('passengers', 'Passengers')) ?><?= count($pdfTravellers) > 1 ? ' &middot; ' . count($pdfTravellers) : '' ?></div>
<table class="infotable">
<?php foreach ($pdfTravellers as $px):
    $pName = _kikoto_passenger_full_name($px);
    $pHeading = $pdfPassengerHeading($px);
    $pMeta = array_filter([
        !empty($px['nationality']) ? $px['nationality'] : '',
        !empty($px['identity_number']) ? (ucfirst($px['identity_type'] ?? 'Passport') . ': ' . $px['identity_number']) : '',
        !empty($px['birthdate']) ? ($T('dob', 'DOB') . ' ' . $px['birthdate']) : '',
    ]);
?>
    <tr>
        <td style="width:60%;">
            <div class="v" style="font-size:9pt;"><?= $e($pName ?: 'Passenger') ?></div>
            <?php if (!empty($pMeta)): ?><div class="vs"><?= $e(implode(' &middot; ', $pMeta)) ?></div><?php endif; ?>
        </td>
        <td style="width:40%; text-align:right;"><span class="vs"><?= $e($pHeading) ?></span></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($pdfVehicles)): ?>
<div class="sec-h"><?= $e($T('vehicles', 'Vehicles')) ?></div>
<table class="infotable">
<?php foreach ($pdfVehicles as $vi => $v):
    $vMeta = array_filter([
        !empty($v['license_plate']) ? ($T('license_plate', 'Plate') . ': ' . $v['license_plate']) : '',
        !empty($v['brand']) ? $v['brand'] : '',
        !empty($v['passenger_id']) ? ($T('driver', 'Driver') . ': ' . ($pdfLinkedPassengerName((int) $v['passenger_id']) ?: ('Passenger #' . (int) $v['passenger_id']))) : '',
    ]);
?>
    <tr>
        <td style="width:40%;"><div class="v" style="font-size:9pt;"><?= $e($T('vehicle', 'Vehicle')) ?> <?= $vi + 1 ?></div><div class="vs"><?= $e($ticketLabel((int) ($v['ticket_type_id'] ?? 0))) ?></div></td>
        <td style="width:60%; text-align:right;"><?php if (!empty($vMeta)): ?><span class="vs"><?= $e(implode(' &middot; ', $vMeta)) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($pdfPets)): ?>
<div class="sec-h"><?= $e($T('pets', 'Pets')) ?></div>
<table class="infotable">
<?php foreach ($pdfPets as $pi => $pet):
    $petMeta = array_filter([
        !empty($pet['name']) ? $pet['name'] : '',
        !empty($pet['passenger_id']) ? ($T('owner', 'Owner') . ': ' . ($pdfLinkedPassengerName((int) $pet['passenger_id']) ?: ('Passenger #' . (int) $pet['passenger_id']))) : '',
    ]);
?>
    <tr>
        <td style="width:40%;"><div class="v" style="font-size:9pt;"><?= $e($T('pet', 'Pet')) ?> <?= $pi + 1 ?></div><div class="vs"><?= $e($ticketLabel((int) ($pet['ticket_type_id'] ?? 0))) ?></div></td>
        <td style="width:60%; text-align:right;"><?php if (!empty($petMeta)): ?><span class="vs"><?= $e(implode(' &middot; ', $petMeta)) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($pdfHasBonusOrCoupon): ?>
<div class="sec-h"><?= $e($T('bonus_discount', 'Bonus / Discount')) ?></div>
<table class="infotable">
<?php foreach ($pdfBonuses as $bonus):
    $bMeta = array_filter([
        !empty($bonus['type']) ? ucfirst((string) $bonus['type']) : '',
        !empty($bonus['description']) ? $bonus['description'] : '',
    ]);
?>
    <tr>
        <td style="width:40%;"><div class="v" style="font-size:9pt;"><?= $e((string) ($bonus['name'] ?? ('#' . (int) ($bonus['id'] ?? 0)))) ?></div></td>
        <td style="width:60%; text-align:right;"><?php if (!empty($bMeta)): ?><span class="vs"><?= $e(implode(' &middot; ', $bMeta)) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pdfCoupon !== ''): ?>
    <tr>
        <td style="width:40%;"><div class="v" style="font-size:9pt;"><?= $e($T('coupon', 'Coupon')) ?></div></td>
        <td style="width:60%; text-align:right;"><span class="vs"><?= $e($pdfCoupon) ?></span></td>
    </tr>
<?php endif; ?>
</table>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (EXACT prior logic; engine gave converted figures) ----------
// Prior ferries summary: Subtotal = $subtotalAmount, Taxes & Fees =
// number_format((float)($booking['tax'] ?? 0), 2), Total = $totalAmount,
// Payment Method = $booking['payment_gateway'].
$taxDisplay = number_format((float) ($booking['tax'] ?? 0), 2);
$priceRows = [];
$priceRows[] = ['label' => $T('subtotal', 'Subtotal'), 'value' => (string) ($subtotalAmount ?? ''), 'muted' => true];
$priceRows[] = ['label' => $T('taxes_fees', 'Taxes & fees'), 'value' => $taxDisplay, 'muted' => true];

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Ferry Booking',
    'paid' => $paid, 'statusText' => $statusText,
    'billToName' => trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')),
    'billToEmail' => (string) ($booking['email'] ?? ''),
    'billToPhone' => trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
    'heroHtml' => $heroHtml,
    'bodyHtml' => $bodyHtml,
    'currency' => $currency,
    'priceRows' => $priceRows,
    'totalLabel' => $T('total_amount', 'Total amount'),
    'totalValue' => (string) ($totalAmount ?? ''),
    'paymentMethod' => (string) ($booking['payment_gateway'] ?? ''),
    'notesTitle' => $T('important_information', 'Important information'),
    'notesItems' => array_values(array_filter([
        $T('ferries_arrive_early_notice', 'Please arrive at the port at least 1 hour before departure.'),
        $T('ferries_valid_id_notice', 'Valid photo identification is required for all passengers.'),
        $T('ferries_present_confirmation_notice', 'This booking confirmation must be presented at check-in.'),
        $T('ferries_operator_status_notice', 'For sailing status updates, contact the ferry operator directly.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document is issued separately.'),
]);
