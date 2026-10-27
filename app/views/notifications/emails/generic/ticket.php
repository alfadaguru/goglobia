<?php
/**
 * GENERIC TICKET — eSIM / digital-product voucher (NO PRICE). Shared base.
 * Used for esim + insurance (the services that share the generic PDF). Shows
 * the package/country and activation reference (QR/ICCID if the supplier
 * returned one on the invoice page; this doc stays price-free).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$moduleType = strtolower((string) ($booking['module_type'] ?? 'esim'));
$isEsim = $moduleType === 'esim';

// Activation / provisioning references (Airalo provision, if present).
$prov = $bookingData['airalo_provision'] ?? ($bookingData['provision'] ?? []);
$sims = $prov['sims'] ?? [];
$firstSim = is_array($sims) && !empty($sims) ? reset($sims) : [];
$iccid = trim((string) ($firstSim['iccid'] ?? ($prov['iccid'] ?? '')));
$ref = trim((string) ($booking['pnr'] ?? ($prov['order_id'] ?? $prov['reference'] ?? '')));
// For an eSIM, "Active / Ready" means a REAL eSIM exists — an ICCID or an order/
// activation reference. Being merely 'paid' does NOT make an eSIM ready (a manual
// eSIM awaiting provisioning, or a paid-but-not-yet-issued live one, is still
// "Processing"). For other products, paid = confirmed as before.
$hasEsimCredential = ($ref !== '' || $iccid !== '');
$isConfirmed = $isEsim
    ? $hasEsimCredential
    : ($ref !== '' || $iccid !== '' || strtolower((string) ($booking['payment_status'] ?? '')) === 'paid');

$packageName = (string) ($bookingData['package_name'] ?? ($bookingData['title'] ?? ($isEsim ? 'eSIM Package' : 'Travel Product')));
$country = (string) ($bookingData['country'] ?? '');
$dataAmount = (string) ($bookingData['data_amount'] ?? ($bookingData['data'] ?? ''));
$validity = (string) ($bookingData['validity'] ?? ($bookingData['days'] ?? ''));

$holderName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

ob_start();
?>
<div class="sec-h"><?= $isEsim ? 'eSIM' : 'Product' ?></div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($packageName) ?></td>
        <td style="text-align:right;"><?php if ($country !== ''): ?><span class="tag"><?= $e(strtoupper($country)) ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:50%;">
            <div class="k">Data</div>
            <div class="mid"><?= $e($dataAmount ?: '—') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k">Validity</div>
            <div class="mid"><?= $e($validity ?: '—') ?></div>
        </td>
    </tr></table></div>
    <div class="cardfoot">
        <?php if ($iccid !== ''): ?><span class="fic">ICCID</span> <b><?= $e($iccid) ?></b> &nbsp;&middot;&nbsp;<?php endif; ?>
        <span class="fic">STATUS</span> <b><?= $isConfirmed ? 'Active / Ready' : 'Processing' ?></b>
    </div>
</div>

<div class="sec-h">Holder</div>
<table class="infotable"><tr>
    <td style="width:64%;"><div class="v"><?= $e($holderName ?: 'Customer') ?></div></td>
    <td style="width:36%; text-align:right;"><span class="k">Booking&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $e($invoiceId) ?></span></td>
</tr></table>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => $isEsim ? 'eSIM Voucher' : 'Booking Voucher',
    'docSub' => $isEsim ? 'Digital SIM · Activation' : 'Confirmation',
    'statusOk' => $isConfirmed, 'statusOkText' => $isEsim ? 'Ready to activate' : 'Confirmed', 'statusPendText' => 'Processing',
    'refPrimaryLabel' => $isEsim ? 'Activation Ref' : 'Booking Ref',
    'refPrimaryValue' => $ref,
    'refPrimaryHint' => $isEsim ? 'Use this to install your eSIM' : 'Quote for support',
    'managePath' => ($isEsim ? 'esim/booking/' : 'booking/'),
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => $isEsim ? 'Installing your eSIM' : 'Good to know',
    'guidanceItems' => $isEsim ? [
        'Open your booking page on the device you want to install the eSIM on, then scan the activation QR or follow the manual install steps shown there.',
        'Install while you have Wi-Fi or a working connection; activate data roaming for the eSIM when you arrive.',
        'Keep your primary SIM for calls/texts if you wish — the eSIM handles data for your destination.',
    ] : [
        'Keep this voucher for your records and quote your Booking ID for any support.',
    ],
    'footerNote' => 'This voucher confirms your booking. Prices and payment details appear on your separate invoice/receipt.',
]);
