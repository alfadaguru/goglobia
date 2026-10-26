<?php
/**
 * VISA TICKET — Visa Application Document (NO PRICE). Shared ticket base.
 * A visa booking is an application/record, not a travel credential — the doc
 * reflects that (status, applicants, route, processing speed).
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$ref = trim((string) ($booking['pnr'] ?? ''));
$isConfirmed = $ref !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

$visaType = (string) ($bookingData['visa_type_name'] ?? 'Visa Application');
$fromCountry = (string) ($bookingData['from_country_name'] ?? '');
$toCountry = (string) ($bookingData['to_country_name'] ?? '');
$entryRaw = (string) ($bookingData['entry_date'] ?? '');
$entryDate = $entryRaw !== '' ? date('D, d M Y', strtotime($entryRaw)) : 'To be decided';
$processing = (string) ($bookingData['processing_speed_name'] ?? 'Standard');

$travelers = $bookingData['travelers'] ?? [];
if (empty($travelers)) {
    $travelers = [['first_name' => $booking['first_name'] ?? '', 'last_name' => $booking['last_name'] ?? '']];
}

ob_start();
?>
<div class="sec-h">Application</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($visaType) ?></td>
        <td style="text-align:right;"><span class="tag"><?= $e(strtoupper($processing)) ?></span></td>
    </tr></table></div>
    <div class="card-body"><table><tr>
        <td style="width:50%;">
            <div class="k">Route</div>
            <div class="mid"><?= $e($fromCountry ?: '—') ?> &rarr; <?= $e($toCountry ?: '—') ?></div>
        </td>
        <td style="width:50%; text-align:right;">
            <div class="k">Intended Entry</div>
            <div class="mid"><?= $e($entryDate) ?></div>
        </td>
    </tr></table></div>
    <div class="cardfoot"><span class="fic">APPLICANTS</span> <b><?= count($travelers) ?></b> &nbsp;&middot;&nbsp; <span class="fic">STATUS</span> <b><?= $isConfirmed ? 'Submitted' : 'In progress' ?></b></div>
</div>

<div class="sec-h">Applicants</div>
<table class="infotable">
    <?php foreach ($travelers as $tv):
        $fn = trim((string) ($tv['first_name'] ?? ''));
        $ln = trim((string) ($tv['last_name'] ?? ''));
        $pp = trim((string) ($tv['passport_number'] ?? $tv['passport'] ?? ''));
        $nat = (string) ($tv['nationality_name'] ?? $tv['nationality'] ?? '');
    ?>
    <tr>
        <td style="width:64%;">
            <div class="v"><?= $e(trim($fn . ' ' . $ln) ?: 'Applicant') ?></div>
            <?php if ($nat !== ''): ?><div class="vs"><?= $e($nat) ?></div><?php endif; ?>
        </td>
        <td style="width:36%; text-align:right;">
            <?php if ($pp !== ''): ?><span class="k">Passport&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $e($pp) ?></span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Visa Application', 'docSub' => 'Application Record',
    'statusOk' => $isConfirmed, 'statusOkText' => 'Submitted', 'statusPendText' => 'In progress',
    'refPrimaryLabel' => 'Application Ref', 'refPrimaryValue' => $ref,
    'refPrimaryHint' => 'Quote this in all correspondence',
    'managePath' => 'visa/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'What happens next',
    'guidanceItems' => [
        'Our team will review your application and contact you for any documents or biometrics the embassy requires.',
        'Keep your passport valid for at least 6 months beyond your intended travel date.',
        'This document is a record of your visa application — it is <b>not</b> a visa or a travel authorisation by itself.',
    ],
    'footerNote' => 'This document records your visa application. Prices and payment details appear on your separate invoice/receipt. Visa issuance is at the sole discretion of the issuing authority.',
]);
