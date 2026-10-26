<?php
/**
 * ============================================================================
 * SHARED TICKET BASE — premium price-free travel-document shell
 * ============================================================================
 * Single source of truth for the ticket design system used by every service's
 * ticket.php (flights, stays, tours, cars, visa, umrah, rail, bus, ferries,
 * esim). Each service file prepares its data + a body HTML string, then calls
 * goglobia_ticket_render($cfg). This keeps ONE consistent, brand-aware,
 * price-free design across all services (no duplicated chrome/CSS).
 *
 * $cfg keys:
 *   booking, bookingData, settings, businessName, businessEmail, businessPhone,
 *   siteUrl, wlPdfLogo, agentBrandColors   — passed through from GENERATE_BOOKING_PDF
 *   docTitle      (string)  e.g. "Hotel Voucher", "Itinerary Receipt"
 *   docSub        (string)  e.g. "Accommodation Confirmation"
 *   statusOk      (bool)    true => green "Confirmed" chip, false => amber "Pending"
 *   statusOkText  (string)  chip text when statusOk  (default "Confirmed")
 *   statusPendText(string)  chip text when !statusOk (default "Pending")
 *   refPrimaryLabel (string) e.g. "Hotel Confirmation No."
 *   refPrimaryValue (string|'') the supplier/airline ref; '' => "Pending" styling
 *   refPrimaryHint  (string) small hint under the primary ref
 *   managePath    (string)  e.g. "stays/booking/"  (QR deep-link path prefix)
 *   bodyHtml      (string)  the service-specific section(s), already escaped
 *   guidanceTitle (string)
 *   guidanceItems (array of HTML strings)
 *   footerNote    (string)  legal line (NO price)
 * ============================================================================
 */

if (!function_exists('goglobia_ticket_palette')) {
    function goglobia_ticket_palette($agentBrandColors)
    {
        $primary = '#0058e6';
        if (!empty($agentBrandColors['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $agentBrandColors['primary'])) {
            $primary = (string) $agentBrandColors['primary'];
        }
        return [
            'primary' => $primary,
            'deep'    => $primary,
            'ink'     => '#0a1a3a',
            'slate'   => '#5b6b8c',
            'slate2'  => '#8793ad',
            'hair'    => '#e4e9f2',
            'panel'   => '#f5f7fb',
            'okC'     => '#0b875b', 'okBg' => '#e8f6ef',
            'pendC'   => '#b4690e', 'pendBg' => '#fdf3e3',
        ];
    }
}

if (!function_exists('goglobia_ticket_render')) {
    function goglobia_ticket_render(array $cfg)
    {
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $p = goglobia_ticket_palette($cfg['agentBrandColors'] ?? null);

        $businessName  = (string) ($cfg['businessName'] ?: 'GoGlobia');
        $businessEmail = (string) ($cfg['businessEmail'] ?? '');
        $businessPhone = (string) ($cfg['businessPhone'] ?? '');
        $wlPdfLogo     = (string) ($cfg['wlPdfLogo'] ?? '');
        $siteUrl       = rtrim((string) ($cfg['siteUrl'] ?: 'https://goglobia.com'), '/');

        $booking    = $cfg['booking'] ?? [];
        $invoiceId  = (string) ($booking['invoice_id'] ?? '');
        $bookingDate= (string) ($cfg['bookingDate'] ?? '');

        $statusOk        = !empty($cfg['statusOk']);
        $statusOkText    = (string) ($cfg['statusOkText'] ?? 'Confirmed');
        $statusPendText  = (string) ($cfg['statusPendText'] ?? 'Pending');
        $docTitle        = (string) ($cfg['docTitle'] ?? 'Travel Document');
        $docSub          = (string) ($cfg['docSub'] ?? 'Passenger Copy');
        $refPrimaryLabel = (string) ($cfg['refPrimaryLabel'] ?? 'Booking Reference');
        $refPrimaryValue = trim((string) ($cfg['refPrimaryValue'] ?? ''));
        $refPrimaryHint  = (string) ($cfg['refPrimaryHint'] ?? '');
        $managePath      = (string) ($cfg['managePath'] ?? 'booking/');
        $manageUrl       = $siteUrl . '/' . ltrim($managePath, '/') . rawurlencode($invoiceId);

        $bodyHtml        = (string) ($cfg['bodyHtml'] ?? '');
        $guidanceTitle   = (string) ($cfg['guidanceTitle'] ?? '');
        $guidanceItems   = (array) ($cfg['guidanceItems'] ?? []);
        $footerNote      = (string) ($cfg['footerNote'] ?? 'This document confirms your booking. Fare and payment details appear on your separate invoice/receipt.');

        // QR — mpdf/qrcode on prod; text fallback (booking id) otherwise.
        if (class_exists('Mpdf\\QrCode\\QrCode')) {
            $qrBlock = '<barcode code="' . $e($manageUrl) . '" type="QR" error="M" disableborder="1" />';
        } else {
            $qrBlock = '<div class="qrfallback">' . $e($invoiceId) . '</div>';
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: inter, sans-serif; color: <?= $p['ink'] ?>; font-size: 9pt; line-height: 1.35; }
    .top { border-bottom: 2.2pt solid <?= $p['primary'] ?>; padding-bottom: 10pt; margin-bottom: 12pt; }
    .top table { width: 100%; border-collapse: collapse; }
    .top td { border: none; vertical-align: top; }
    .logo-img { height: 30pt; }
    .logo-txt { font-size: 17pt; font-weight: bold; color: <?= $p['primary'] ?>; }
    .issuer { font-size: 7.5pt; color: <?= $p['slate'] ?>; line-height: 1.45; margin-top: 2pt; }
    .doctitle { font-size: 9pt; font-weight: bold; letter-spacing: 1pt; color: <?= $p['ink'] ?>; text-align: right; text-transform: uppercase; }
    .docsub { font-size: 7pt; color: <?= $p['slate2'] ?>; text-align: right; margin-top: 1pt; }
    .chip { font-size: 7.5pt; font-weight: bold; padding: 2.5pt 7pt; border-radius: 8pt; }
    .chip-ok { color: <?= $p['okC'] ?>; background: <?= $p['okBg'] ?>; }
    .chip-pend { color: <?= $p['pendC'] ?>; background: <?= $p['pendBg'] ?>; }
    .refstrip { background: <?= $p['panel'] ?>; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 6pt; padding: 9pt 10pt; margin-bottom: 12pt; }
    .refstrip table { width: 100%; border-collapse: collapse; }
    .refstrip td { border: none; vertical-align: top; }
    .lbl { font-size: 6.5pt; font-weight: bold; letter-spacing: .6pt; color: <?= $p['slate'] ?>; text-transform: uppercase; }
    .val { font-size: 13pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .val-pending { font-size: 9pt; font-weight: bold; color: <?= $p['pendC'] ?>; }
    .hint { font-size: 6.6pt; color: <?= $p['slate2'] ?>; }
    .qrfallback { font-size: 9pt; font-weight: bold; color: <?= $p['ink'] ?>; border: 0.8pt solid <?= $p['hair'] ?>; padding: 5pt; text-align: center; }
    .sec-h { font-size: 7.5pt; font-weight: bold; letter-spacing: 1pt; color: <?= $p['ink'] ?>; border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding-bottom: 3pt; margin: 11pt 0 7pt; text-transform: uppercase; }
    .leg { font-size: 7pt; font-weight: bold; color: #fff; background: <?= $p['primary'] ?>; padding: 1.5pt 6pt; border-radius: 3pt; }
    .card { border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 7pt; margin-bottom: 8pt; }
    .card-top { background: <?= $p['panel'] ?>; padding: 6pt 10pt; }
    .card-top table { width: 100%; border-collapse: collapse; }
    .card-top td { border: none; }
    .card-title { font-size: 10pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .card-sub { font-size: 8pt; color: <?= $p['slate'] ?>; }
    .tag { font-size: 7pt; font-weight: bold; color: <?= $p['primary'] ?>; background: #e8f0ff; padding: 2pt 6pt; border-radius: 3pt; }
    .card-body { padding: 11pt 12pt; }
    .card-body table { width: 100%; border-collapse: collapse; }
    .card-body td { border: none; vertical-align: top; }
    .big { font-size: 20pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .mid { font-size: 12pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .k { font-size: 6.5pt; font-weight: bold; letter-spacing: .5pt; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .v { font-size: 10pt; color: <?= $p['ink'] ?>; font-weight: bold; }
    .vs { font-size: 8.5pt; color: <?= $p['slate'] ?>; }
    .cardfoot { border-top: 0.5pt dashed <?= $p['hair'] ?>; padding: 6pt 12pt; font-size: 7.5pt; color: <?= $p['slate'] ?>; }
    .cardfoot b { color: <?= $p['ink'] ?>; }
    .fic { font-size: 6.5pt; font-weight: bold; letter-spacing: .5pt; color: <?= $p['slate2'] ?>; }
    .infotable { width: 100%; border-collapse: collapse; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 6pt; }
    .infotable td { border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding: 8pt 11pt; vertical-align: top; }
    .infotable tr:last-child td { border-bottom: none; }
    .guide { background: #eef4ff; border: 0.5pt solid #cfe0ff; border-left: 3pt solid <?= $p['primary'] ?>; border-radius: 6pt; padding: 10pt 12pt; margin-top: 12pt; }
    .gt { font-size: 8.5pt; font-weight: bold; color: <?= $p['deep'] ?>; margin-bottom: 4pt; }
    .guide ul { margin: 0; padding-left: 11pt; }
    .guide li { font-size: 7.8pt; color: #28406b; line-height: 1.5; }
    .foot { border-top: 0.5pt solid <?= $p['hair'] ?>; margin-top: 12pt; padding-top: 8pt; }
    .foot table { width: 100%; border-collapse: collapse; }
    .foot td { font-size: 6.8pt; color: <?= $p['slate2'] ?>; line-height: 1.5; border: none; vertical-align: top; }
</style>
</head>
<body>
    <!-- Header -->
    <div class="top"><table><tr>
        <td style="width:62%;">
            <?php if ($wlPdfLogo !== ''): ?>
                <img src="<?= $e($wlPdfLogo) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
            <?php else: ?>
                <div class="logo-txt"><?= $e($businessName) ?></div>
            <?php endif; ?>
            <div class="issuer">
                Issued by <?= $e($businessName) ?>
                <?php if ($businessEmail !== ''): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?>
                <?php if ($businessPhone !== ''): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?>
            </div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="doctitle"><?= $e($docTitle) ?></div>
            <div class="docsub"><?= $e($docSub) ?></div>
            <div style="margin-top:5pt;">
                <?php if ($statusOk): ?>
                    <span class="chip chip-ok">&#9679; <?= $e($statusOkText) ?></span>
                <?php else: ?>
                    <span class="chip chip-pend">&#9679; <?= $e($statusPendText) ?></span>
                <?php endif; ?>
            </div>
        </td>
    </tr></table></div>

    <!-- Reference strip + QR -->
    <div class="refstrip"><table><tr>
        <td style="width:30%;">
            <div class="lbl"><?= $e($refPrimaryLabel) ?></div>
            <?php if ($refPrimaryValue !== ''): ?>
                <div class="val"><?= $e($refPrimaryValue) ?></div>
                <?php if ($refPrimaryHint !== ''): ?><div class="hint"><?= $e($refPrimaryHint) ?></div><?php endif; ?>
            <?php else: ?>
                <div class="val-pending">Pending &mdash; issued on confirmation</div>
                <div class="hint">You&rsquo;ll receive it once your booking is confirmed</div>
            <?php endif; ?>
        </td>
        <td style="width:30%;">
            <div class="lbl"><?= $e($businessName) ?> Booking ID</div>
            <div class="val"><?= $e($invoiceId) ?></div>
            <div class="hint">Quote for support &amp; changes</div>
        </td>
        <td style="width:18%;">
            <div class="lbl">Issued</div>
            <div class="val" style="font-size:9pt;"><?= $e($bookingDate) ?></div>
        </td>
        <td style="width:22%; text-align:center;">
            <?= $qrBlock ?>
            <div class="hint" style="text-align:center;">Scan to Manage Booking</div>
        </td>
    </tr></table></div>

    <!-- Service-specific body -->
    <?= $bodyHtml ?>

    <!-- Guidance -->
    <?php if ($guidanceTitle !== '' || !empty($guidanceItems)): ?>
    <div class="guide">
        <?php if ($guidanceTitle !== ''): ?><div class="gt"><?= $e($guidanceTitle) ?></div><?php endif; ?>
        <?php if (!empty($guidanceItems)): ?>
        <ul>
            <?php foreach ($guidanceItems as $gi): ?><li><?= $gi /* pre-built HTML */ ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="foot"><table><tr>
        <td style="width:64%;"><?= $e($footerNote) ?></td>
        <td style="width:36%; text-align:right;"><b><?= $e($businessName) ?></b><br><?php if ($businessEmail !== ''): ?><?= $e($businessEmail) ?><br><?php endif; ?>Generated <?= $e(date('d M Y, H:i')) ?></td>
    </tr></table></div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
