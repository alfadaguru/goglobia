<?php
/**
 * RAIL INVOICE / RECEIPT (WITH PRICE) — premium, on the shared invoice base.
 *
 * The priced money document for a rail booking. Builds a train-journey summary
 * (from station -> to station + date) + passenger list, then feeds the premium
 * _invoice_base.php: header band, bill-to, line-item price block, payment status
 * + method, notes.
 *
 * Financials are computed + currency-converted by GENERATE_BOOKING_PDF and passed
 * in: $rawTotal, $totalAmount, $taxAmount, $subtotalAmount, $currency, $statusText,
 * $booking['payment_gateway'], etc. We only DISPLAY them (no re-pricing here).
 *
 * MONEY NOTE: the rail price summary mirrors the legacy template exactly — a single
 * "Ticket Total" row using number_format((float)$booking['price_markup'], 2) and the
 * grand total using the same value. Do not change this math.
 *
 * Vars: $booking,$bookingData,$settings,$businessName,$businessEmail,$businessPhone,
 *   $siteUrl,$wlPdfLogo,$pdfLogoSrc,$agentBrandColors,$currency,$language,
 *   $rawTotal,$totalAmount,$taxAmount,$subtotalAmount,$bookingDate,$travellersData.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_invoice_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$db = $GLOBALS['db'] ?? null;
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback !== '' ? $fallback : ucwords(str_replace('_', ' ', $key));
};

// ---- Journey extraction (same as the legacy rail template) -------------------
$journey = $bookingData['journey'][0] ?? [];
$fromTs = (int)($journey['from_date_time'] ?? 0);
$toTs = (int)($journey['to_date_time'] ?? 0);
$fromCode = trim((string)($journey['from_station_code'] ?? ''));
$toCode = trim((string)($journey['to_station_code'] ?? ''));
if (!function_exists('_train_station_label') && $db !== null) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
}
if ($db !== null && function_exists('_train_station_label')) {
    $fromStation = $fromCode !== '' ? _train_station_label($db, $fromCode) : '';
    $toStation = $toCode !== '' ? _train_station_label($db, $toCode) : '';
} else {
    $fromStation = trim((string)($journey['from_station_english'] ?? $fromCode));
    $toStation = trim((string)($journey['to_station_english'] ?? $toCode));
}
if ($fromStation === '' && $fromCode !== '') { $fromStation = $fromCode; }
if ($toStation === '' && $toCode !== '') { $toStation = $toCode; }

$fromTime = $fromTs > 0 ? date('H:i', $fromTs) : '';
$toTime = $toTs > 0 ? date('H:i', $toTs) : '';
$departureDate = $fromTs > 0 ? date('F d, Y', $fromTs) : date('F d, Y', strtotime($booking['booking_date'] ?? 'now'));
$arrivalDate = $toTs > 0 ? date('F d, Y', $toTs) : $departureDate;

$runTimeMinutes = max(0, (int)($journey['run_time'] ?? 0));
if ($runTimeMinutes <= 0 && $fromTs > 0 && $toTs > $fromTs) {
    $runTimeMinutes = (int) round(($toTs - $fromTs) / 60);
}
$durationLabel = '';
if ($runTimeMinutes > 0) {
    $hours = intdiv($runTimeMinutes, 60);
    $mins = $runTimeMinutes % 60;
    $durationLabel = $hours > 0 ? $hours . 'h ' . $mins . 'm' : $mins . 'm';
}

$trafficNo = trim((string)($journey['traffic_no'] ?? ''));
$seatName = trim((string)($journey['seat_name'] ?? $journey['seat_class'] ?? ''));
$pnr = trim((string)($booking['pnr'] ?? ''));
$adultsCnt = (int)($booking['adults'] ?? 1);
$childsCnt = (int)($booking['childs'] ?? 0);

// ---- Passengers (+ coach/seat enrichment from the supplier response) ---------
$bookingResponse = json_decode($booking['booking_response'] ?? '{}', true) ?: [];
$passengers = (isset($travellersData) && is_array($travellersData)) ? $travellersData : [];
if ($passengers === [] && !empty($bookingData['passengers']) && is_array($bookingData['passengers'])) {
    $passengers = $bookingData['passengers'];
}
$rspPassengers = $bookingResponse['data']['journey'][0]['passengers']
    ?? $bookingResponse['journey'][0]['passengers']
    ?? $bookingResponse['poll']['journey'][0]['passengers']
    ?? [];
if (!is_array($rspPassengers)) { $rspPassengers = []; }

// ---- HERO: train journey (from station -> to station + date) -----------------
$heroHtml = '';
if ($fromStation !== '' || $toStation !== '') {
    ob_start(); ?>
    <div class="hero"><table><tr>
        <td style="width:38%;">
            <div class="port-code" style="font-size:16pt;"><?= $e($fromStation ?: '—') ?></div>
            <?php if ($departureDate !== ''): ?><div class="port-city"><?= $e($departureDate) ?></div><?php endif; ?>
            <?php if ($fromTime !== ''): ?><div class="port-apt"><?= $e($T('departure', 'Departure')) ?> <?= $e($fromTime) ?></div><?php endif; ?>
        </td>
        <td style="width:24%;" class="hero-mid">
            <?php if ($durationLabel !== ''): ?><div class="hero-dur"><?= $e($durationLabel) ?></div><?php endif; ?>
            <div class="hero-line"></div><div class="hero-plane" style="font-size:11pt;">&#8594;</div>
            <div class="hero-stops"><?= $trafficNo !== '' ? 'Train ' . $e($trafficNo) : $e($T('rail', 'Rail')) ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="port-code" style="font-size:16pt;"><?= $e($toStation ?: '—') ?></div>
            <?php if ($arrivalDate !== ''): ?><div class="port-city"><?= $e($arrivalDate) ?></div><?php endif; ?>
            <?php if ($toTime !== ''): ?><div class="port-apt"><?= $e($T('arrival', 'Arrival')) ?> <?= $e($toTime) ?></div><?php endif; ?>
        </td>
    </tr></table></div>
    <?php
    $heroHtml = ob_get_clean();
}

// ---- BODY: train detail + passengers ----------------------------------------
ob_start();
?>
<div class="sec-h"><?= $e($T('train_details', 'Train Details')) ?></div>
<table class="infotable">
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('train', 'Train')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $trafficNo !== '' ? 'Train ' . $e($trafficNo) : $e($T('rail_ticket', 'Rail ticket')) ?></div>
            <?php if ($seatName !== ''): ?><div class="vs"><?= $e($seatName) ?></div><?php endif; ?>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k"><?= $e($T('passengers', 'Passengers')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $adultsCnt ?> <?= $e($T('adult', 'Adult')) ?><?= $adultsCnt !== 1 ? 's' : '' ?><?= $childsCnt > 0 ? ', ' . $childsCnt . ' ' . $e($T('child', 'Child')) . ($childsCnt !== 1 ? 'ren' : '') : '' ?></div>
            <?php if ($pnr !== ''): ?><div class="vs">PNR: <?= $e($pnr) ?></div><?php endif; ?>
        </td>
    </tr>
    <tr>
        <td style="width:50%;">
            <div class="k"><?= $e($T('departure', 'Departure')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($fromStation ?: $fromCode) ?></div>
            <div class="vs"><?= $e($departureDate) ?><?= $fromTime !== '' ? ' &middot; ' . $e($fromTime) : '' ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k"><?= $e($T('arrival', 'Arrival')) ?></div>
            <div class="v" style="font-size:9pt;"><?= $e($toStation ?: $toCode) ?></div>
            <div class="vs"><?= $e($arrivalDate) ?><?= $toTime !== '' ? ' &middot; ' . $e($toTime) : '' ?></div>
        </td>
    </tr>
</table>

<?php
// Collect displayable passengers (name required), with coach/seat enrichment.
$paxRows = [];
foreach ($passengers as $tr) {
    if (!is_array($tr)) { continue; }
    $name = trim((string)($tr['passenger_first_name'] ?? $tr['first_name'] ?? '') . ' ' . ($tr['passenger_last_name'] ?? $tr['last_name'] ?? ''));
    if ($name === '') { continue; }
    $cardNo = trim((string)($tr['passenger_card_no'] ?? $tr['identity_number'] ?? ''));
    $seatLine = '';
    foreach ($rspPassengers as $rp) {
        if (!is_array($rp)) { continue; }
        if ($cardNo !== '' && trim((string)($rp['passenger_card_no'] ?? '')) === $cardNo) {
            $coach = trim((string)($rp['train_coach_no'] ?? ''));
            $seat = trim((string)($rp['train_seat_no'] ?? ''));
            if ($coach !== '' || $seat !== '') {
                $seatLine = ($coach !== '' ? 'Coach ' . $coach : '') . ($seat !== '' ? ($coach !== '' ? ', Seat ' : 'Seat ') . $seat : '');
            }
            break;
        }
    }
    $paxRows[] = ['name' => $name, 'seat' => $seatLine];
}
if ($paxRows !== []):
?>
<div class="sec-h"><?= $e($T('passengers', 'Passengers')) ?><?= count($paxRows) > 1 ? ' &middot; ' . count($paxRows) : '' ?></div>
<table class="infotable">
<?php foreach ($paxRows as $idx => $px): ?>
    <tr>
        <td style="width:60%;"><div class="v" style="font-size:9pt;"><?= (int)$idx + 1 ?>. <?= $e($px['name']) ?></div></td>
        <td style="width:40%; text-align:right;"><?php if ($px['seat'] !== ''): ?><span class="vs"><?= $e($px['seat']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

// ---- Price rows (engine-converted display total, like the other services) ----
// Rail shows a single "Ticket Total" line; use the guest-currency total the engine
// computed ($totalAmount / $currency), not the raw base-currency price_markup.
$ticketTotal = (string) ($totalAmount ?? number_format((float) ($booking['price_markup'] ?? 0), 2));
$priceRows = [];
$priceRows[] = ['label' => $T('ticket_total', 'Ticket Total'), 'value' => $ticketTotal, 'muted' => true];

$paid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';
$statusText = ucfirst((string) ($booking['payment_status'] ?? ($paid ? 'Paid' : 'Pending')));

echo goglobia_invoice_render([
    'booking' => $booking, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Invoice', 'docSub' => 'Rail Booking',
    'paid' => $paid, 'statusText' => $statusText,
    'billToName' => trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? '')),
    'billToEmail' => (string) ($booking['email'] ?? ''),
    'billToPhone' => trim('+' . (string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? '')),
    'heroHtml' => $heroHtml,
    'bodyHtml' => $bodyHtml,
    'currency' => $currency,
    'priceRows' => $priceRows,
    'totalLabel' => $T('total_amount', 'Total Amount'),
    'totalValue' => $ticketTotal,
    'paymentMethod' => (string) ($booking['payment_gateway'] ?? ''),
    'notesTitle' => $T('important_information', 'Important information'),
    'notesItems' => array_values(array_filter([
        $T('rail_arrive_early_notice', 'Arrive at the station at least 30–45 minutes before departure.'),
        $T('rail_valid_id_notice', 'Carry valid photo identification for all passengers while travelling.'),
        $T('rail_not_boarding_pass_notice', 'This invoice is not a travel ticket — your rail ticket is a separate document.'),
    ])),
    'footerNote' => $T('automated_voucher_disclaimer', 'This is a computer-generated invoice and is valid without a signature. Your travel document (rail ticket) is issued separately.'),
]);
