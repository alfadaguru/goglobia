<?php
/**
 * eSIM TICKET — Activation Voucher (NO PRICE, white-label / GoGlobia-branded)
 * The customer's installable eSIM document: the activation QR (generated from the
 * LPA string so it works offline + reveals no supplier), the activation details
 * (SM-DP+ address, activation code, ICCID), and step-by-step install instructions
 * for iPhone + Android. Uses the shared _ticket_base chrome (brand header, ref
 * strip, footer). Never shows the supplier (Airalo) — branded as GoGlobia/eSIM.
 * Vars from GENERATE_BOOKING_PDF: $booking,$bookingData,$settings,$businessName,...
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');

// --- eSIM provisioning data (same keys the invoice page reads) --------------
$prov = (array) ($bookingData['airalo_provision'] ?? ($bookingData['provision'] ?? []));
$sims = $prov['sims'] ?? ($bookingData['airalo_sims'] ?? []);
$firstSim = (is_array($sims) && !empty($sims)) ? (array) reset($sims) : (array) ($prov['first_sim'] ?? []);

$iccid    = trim((string) ($prov['iccid'] ?? ($firstSim['iccid'] ?? '')));
$smdp     = trim((string) ($prov['smdp_address'] ?? ($firstSim['smdp_address'] ?? ($firstSim['lpa'] ?? ''))));
$matching = trim((string) ($prov['matching_id'] ?? ($firstSim['matching_id'] ?? '')));
$hostedQr = trim((string) ($prov['qrcode_url'] ?? ($firstSim['qrcode_url'] ?? ($firstSim['qr_code_url'] ?? ''))));

// manual_installation may carry an explicit activation code / lpa.
$manualRaw = $prov['manual_installation'] ?? ($firstSim['manual_installation'] ?? '');
$manual = is_array($manualRaw) ? $manualRaw : [];
$activationCode = trim((string) ($manual['code'] ?? ($manual['activation_code'] ?? ($manual['lpa'] ?? ''))));
if ($smdp === '' && isset($manual['smdp_address'])) { $smdp = trim((string) $manual['smdp_address']); }

// The canonical eSIM activation string (what a QR must encode): LPA:1$<smdp>$<matching>.
// Prefer an explicit activation code/lpa if Airalo gave one; else build from parts.
$lpa = '';
if ($activationCode !== '' && stripos($activationCode, 'LPA:') === 0) {
    $lpa = $activationCode;
} elseif ($smdp !== '' && $matching !== '') {
    $lpa = 'LPA:1$' . $smdp . '$' . $matching;
} elseif ($activationCode !== '') {
    $lpa = 'LPA:1$' . $smdp . '$' . $activationCode;
}

$ready = ($lpa !== '' || $hostedQr !== '' || $iccid !== '');

// Package facts (from booking_data; branded, no supplier).
$pkgName  = (string) ($bookingData['package_name'] ?? ($bookingData['selected_package']['name'] ?? 'eSIM Data Plan'));
$country  = (string) ($bookingData['country_name'] ?? ($bookingData['country'] ?? (is_array($bookingData['selected_package'] ?? null) ? ($bookingData['selected_package']['country'] ?? '') : '')));
if (is_array($country)) { $country = (string) ($country['name'] ?? $country['iso'] ?? ''); }
$dataAmount = (string) ($bookingData['data_amount'] ?? ($bookingData['selected_package']['data'] ?? ''));
$validity   = (string) ($bookingData['validity'] ?? ($bookingData['selected_package']['validity'] ?? ''));
$holder = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));

// QR block: generate from the LPA (offline, supplier-free) when mpdf/qrcode is
// present; else use the hosted image; else a text note.
if ($lpa !== '' && class_exists('Mpdf\\QrCode\\QrCode')) {
    $qrImg = '<barcode code="' . $e($lpa) . '" type="QR" error="M" disableborder="1" />';
} elseif ($hostedQr !== '') {
    $qrImg = '<img src="' . $e($hostedQr) . '" style="width:150pt;height:150pt;" alt="Activation QR">';
} else {
    $qrImg = '<div style="font-size:8.5pt;color:#64748b;border:0.8pt solid #e2e8f0;padding:8pt;">Your activation QR is being prepared. It will appear on your booking page shortly.</div>';
}

// ---- Body -------------------------------------------------------------------
ob_start();
?>
<div class="sec-h">Your eSIM</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($pkgName) ?><?php if ($country !== ''): ?> <span class="card-sub"><?= $e($country) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><span class="tag"><?= $ready ? 'READY TO INSTALL' : 'PROCESSING' ?></span></td>
    </tr></table></div>
    <div class="card-body">
        <table><tr>
            <?php if ($dataAmount !== ''): ?><td style="width:50%;"><div class="k">Data</div><div class="mid"><?= $e($dataAmount) ?></div></td><?php endif; ?>
            <?php if ($validity !== ''): ?><td style="width:50%; text-align:right;"><div class="k">Validity</div><div class="mid"><?= $e($validity) ?></div></td><?php endif; ?>
        </tr></table>
    </div>
</div>

<?php if ($ready): ?>
<div class="sec-h">Scan to install</div>
<table class="infotable"><tr>
    <td style="width:40%; text-align:center; vertical-align:middle; padding:8pt;">
        <?= $qrImg ?>
        <div class="vs" style="margin-top:5pt;">Scan with the phone's Camera<br>or from eSIM setup</div>
    </td>
    <td style="width:60%; vertical-align:top; padding:8pt;">
        <div class="k">Activation details (for manual setup)</div>
        <?php if ($smdp !== ''): ?><div style="margin-top:4pt;"><span class="vs">SM-DP+ Address</span><br><b style="font-size:8.5pt; word-break:break-all;"><?= $e($smdp) ?></b></div><?php endif; ?>
        <?php if ($matching !== '' || $activationCode !== ''): ?><div style="margin-top:4pt;"><span class="vs">Activation Code</span><br><b style="font-size:8.5pt; word-break:break-all;"><?= $e($matching !== '' ? $matching : $activationCode) ?></b></div><?php endif; ?>
        <?php if ($iccid !== ''): ?><div style="margin-top:4pt;"><span class="vs">ICCID</span><br><b style="font-size:8.5pt; word-break:break-all;"><?= $e($iccid) ?></b></div><?php endif; ?>
    </td>
</tr></table>
<?php endif; ?>

<div class="sec-h">How to install — iPhone (iOS)</div>
<table class="infotable"><tr><td><div class="vs">
    1. Connect to Wi-Fi.&nbsp; 2. Open <b>Settings &rarr; Cellular/Mobile Service &rarr; Add eSIM</b>.&nbsp;
    3. Choose <b>Use QR Code</b> and scan the code above (or tap <b>Enter Details Manually</b> and type the SM-DP+ address and activation code).&nbsp;
    4. Follow the prompts, label the plan (e.g. &ldquo;Travel&rdquo;), and when you arrive turn on <b>Data Roaming</b> for this eSIM line.
</div></td></tr></table>

<div class="sec-h">How to install — Android</div>
<table class="infotable"><tr><td><div class="vs">
    1. Connect to Wi-Fi.&nbsp; 2. Open <b>Settings &rarr; Network &amp; Internet &rarr; SIMs / Mobile network &rarr; Add eSIM</b> (wording varies by phone; on Samsung: <b>Connections &rarr; SIM manager &rarr; Add eSIM</b>).&nbsp;
    3. Tap <b>Scan QR code</b> and scan the code above (or enter the activation code manually).&nbsp;
    4. Download &amp; confirm the plan, then enable <b>Data roaming</b> for the eSIM when you reach your destination.
</div></td></tr></table>

<?php if ($holder !== ''): ?>
<div class="sec-h">Issued to</div>
<table class="infotable"><tr>
    <td style="width:64%;"><div class="v"><?= $e($holder) ?></div></td>
    <td style="width:36%; text-align:right;"><span class="k">Status&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $ready ? 'Ready' : 'Processing' ?></span></td>
</tr></table>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'pdfLogoSrc' => ($pdfLogoSrc ?? ''), 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'eSIM Voucher', 'docSub' => 'Activation & Installation',
    'statusOk' => $ready,
    'statusOkText' => 'Ready to install', 'statusPendText' => 'Processing',
    'refPrimaryLabel' => 'Order Reference',
    'refPrimaryValue' => (string) ($booking['pnr'] ?? ($prov['order_id'] ?? '')),
    'refPrimaryHint' => 'Quote this for support',
    'managePath' => 'esim/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'Good to know',
    'guidanceItems' => [
        'Install the eSIM while you have Wi-Fi or a working connection — ideally before you travel.',
        'Keep your usual SIM for calls and texts if you like; the eSIM handles data at your destination.',
        'Turn on Data Roaming for the eSIM line only after you arrive. Need help? Just reply to this message.',
    ],
    'footerNote' => 'This voucher activates your eSIM data plan. Keep the QR and activation details safe. Charges and plan terms appear on your separate invoice/receipt.',
]);
