<?php
/**
 * ============================================================================
 * SHARED TICKET BASE — premium price-free travel-document shell (mPDF-safe)
 * ============================================================================
 * Single source of truth for the ticket design system used by every service's
 * ticket.php (flights, stays, tours, cars, visa, umrah, rail, bus, ferries,
 * esim). Each service file prepares its data + a body HTML string, then calls
 * goglobia_ticket_render($cfg). ONE consistent, brand-aware, price-free design.
 *
 * mPDF constraints honoured: NO flexbox / grid — layout is table-based; colours
 * inlined (mPDF's CSS cascade is limited); gradients used only where mPDF
 * supports them (it renders linear-gradient on block backgrounds).
 *
 * $cfg keys:
 *   booking, bookingData, settings, businessName, businessEmail, businessPhone,
 *   siteUrl, wlPdfLogo, pdfLogoSrc, agentBrandColors  — from GENERATE_BOOKING_PDF
 *   docTitle      (string)  e.g. "Itinerary Receipt", "Hotel Voucher"
 *   docSub        (string)  small line under the doc title
 *   statusOk      (bool)    true => green "Confirmed" seal, false => amber "Pending"
 *   statusOkText  (string)  seal text when statusOk  (default "Confirmed")
 *   statusPendText(string)  seal text when !statusOk (default "Pending")
 *   heroHtml      (string)  OPTIONAL premium hero band (flights route) — '' => none
 *   refPrimaryLabel (string) e.g. "Airline Booking Ref (PNR)"
 *   refPrimaryValue (string|'') supplier/airline ref; '' => "Pending" styling
 *   refPrimaryHint  (string) small hint under the primary ref
 *   refPrimaryMono  (bool)  render the primary ref in monospace (PNR/e-ticket)
 *   managePath    (string)  QR deep-link path prefix, e.g. "flights/booking/"
 *   bodyHtml      (string)  the service-specific section(s), already escaped
 *   guidanceTitle (string)
 *   guidanceItems (array of HTML strings)
 *   footerNote    (string)  legal line (NO price)
 * ============================================================================
 */

if (!function_exists('goglobia_ticket_palette')) {
    function goglobia_ticket_palette($agentBrandColors)
    {
        $primary = '#0A4FE6';
        if (!empty($agentBrandColors['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $agentBrandColors['primary'])) {
            $primary = (string) $agentBrandColors['primary'];
        }
        return [
            'primary' => $primary,
            'ink'     => '#0B1B2B',   // deep navy-black
            'ink2'    => '#13294A',   // header band mid
            'slate'   => '#64748B',
            'slate2'  => '#94A3B8',
            'hair'    => '#DCE3EF',
            'panel'   => '#F5F7FB',
            'sky'     => '#EAF1FF',
            'okC'     => '#0B875B', 'okBg' => '#E8F6EF', 'okBd' => '#BFE8D4',
            'pendC'   => '#B4690E', 'pendBg' => '#FDF3E3', 'pendBd' => '#F0D9A8',
            'paper'   => '#FBFCFE',
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
        $heroHtml        = (string) ($cfg['heroHtml'] ?? '');
        $refPrimaryLabel = (string) ($cfg['refPrimaryLabel'] ?? 'Booking Reference');
        $refPrimaryValue = trim((string) ($cfg['refPrimaryValue'] ?? ''));
        $refPrimaryHint  = (string) ($cfg['refPrimaryHint'] ?? '');
        $refPrimaryMono  = !empty($cfg['refPrimaryMono']);
        $managePath      = (string) ($cfg['managePath'] ?? 'booking/');
        $manageUrl       = $siteUrl . '/' . ltrim($managePath, '/') . rawurlencode($invoiceId);

        $bodyHtml        = (string) ($cfg['bodyHtml'] ?? '');
        $guidanceTitle   = (string) ($cfg['guidanceTitle'] ?? '');
        $guidanceItems   = (array) ($cfg['guidanceItems'] ?? []);
        $footerNote      = (string) ($cfg['footerNote'] ?? 'This document confirms your booking. Fare and payment details appear on your separate invoice/receipt.');

        // Status accent (seal + pending ref) follows the lifecycle.
        $sealC  = $statusOk ? $p['okC']  : $p['pendC'];
        $sealBg = $statusOk ? $p['okBg'] : $p['pendBg'];
        $sealBd = $statusOk ? $p['okBd'] : $p['pendBd'];
        $sealTx = $statusOk ? $statusOkText : $statusPendText;
        // Header band gradient: brand/navy when confirmed, warm amber when pending.
        $bandGrad = $statusOk
            ? 'linear-gradient(115deg, ' . $p['ink'] . ' 0%, ' . $p['ink2'] . ' 55%, ' . $p['primary'] . ' 165%)'
            : 'linear-gradient(115deg, #1b1205 0%, #3a2a10 55%, ' . $p['pendC'] . ' 170%)';

        // QR — mpdf/qrcode on prod; text fallback (booking id) otherwise.
        if (class_exists('Mpdf\\QrCode\\QrCode')) {
            $qrBlock = '<barcode code="' . $e($manageUrl) . '" type="QR" error="M" disableborder="1" />';
        } else {
            $qrBlock = '<div class="qrfallback">' . $e($invoiceId) . '</div>';
        }

        // Logo: resolved once by GENERATE_BOOKING_PDF (var:pdflogo) or a path.
        $__pdfLogoSrc = (string) ($cfg['pdfLogoSrc'] ?? '');
        $__logoSrc = ($__pdfLogoSrc !== '')
                   ? $__pdfLogoSrc
                   : (($wlPdfLogo !== '') ? $wlPdfLogo
                      : (defined('uploads') && is_file(uploads . 'global/logo.png') ? uploads . 'global/logo.png' : ''));

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: inter, sans-serif; color: <?= $p['ink'] ?>; font-size: 9pt; line-height: 1.4; }
    h1,h2,h3,p,table { margin: 0; }
    td { vertical-align: top; }

    /* ---------- HEADER BAND (premium dark gradient) ---------- */
    .band { background: <?= $bandGrad ?>; color: #ffffff; padding: 15pt 20pt; border-radius: 9pt; }
    .band table { width: 100%; border-collapse: collapse; }
    .band td { border: none; }
    .logo-img { height: 24pt; width: auto; max-width: 150pt; }
    .logo-txt { font-size: 17pt; font-weight: bold; color: #ffffff; }
    .eyebrow { font-size: 6.5pt; font-weight: bold; letter-spacing: 2.4pt; color: <?= $statusOk ? '#9DB8EA' : '#E5C79A' ?>; text-transform: uppercase; }
    .wm { font-size: 15pt; font-weight: bold; color: #fff; margin-top: 1pt; }
    .issuer { font-size: 7pt; color: #c7d4ea; margin-top: 4pt; }
    .doctitle { font-size: 9pt; font-weight: bold; letter-spacing: 1.6pt; text-transform: uppercase; text-align: right; color: #fff; }
    .docsub { font-size: 6.8pt; color: #b9c7e2; text-align: right; margin-top: 1pt; }
    .seal { font-size: 7.5pt; font-weight: bold; color: <?= $sealC ?>; background: #ffffff; padding: 3pt 9pt; border-radius: 9pt; }

    /* ---------- HERO (optional, flights route) ---------- */
    .hero { padding: 16pt 6pt 10pt; }
    .hero table { width: 100%; border-collapse: collapse; }
    .hero td { border: none; }
    .port-code { font-size: 38pt; font-weight: bold; color: <?= $p['ink'] ?>; letter-spacing: -1pt; line-height: 1; }
    .port-city { font-size: 11pt; font-weight: bold; color: <?= $p['ink'] ?>; margin-top: 3pt; }
    .port-apt  { font-size: 8pt; color: <?= $p['slate'] ?>; }
    .port-time { font-size: 13pt; font-weight: bold; color: <?= $p['ink'] ?>; margin-top: 6pt; }
    .port-date { font-size: 7.5pt; font-weight: bold; color: <?= $p['slate'] ?>; text-transform: uppercase; }
    .hero-mid { text-align: center; }
    .hero-dur { font-size: 8pt; font-weight: bold; color: <?= $statusOk ? $p['primary'] : $p['pendC'] ?>; }
    .hero-line { border-bottom: 0.8pt dotted <?= $p['slate2'] ?>; margin: 5pt 4pt; }
    .hero-plane { font-size: 13pt; color: <?= $statusOk ? $p['primary'] : $p['pendC'] ?>; }
    .hero-stops { font-size: 7.5pt; font-weight: bold; color: <?= $p['slate2'] ?>; text-transform: uppercase; letter-spacing: .5pt; }

    /* ---------- REFERENCE RAIL ---------- */
    .rail { background: <?= $p['panel'] ?>; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 7pt; padding: 9pt 6pt; margin-top: 10pt; }
    .rail table { width: 100%; border-collapse: collapse; }
    .rail td { border: none; padding: 0 9pt; }
    .lbl { font-size: 6pt; font-weight: bold; letter-spacing: .8pt; color: <?= $p['slate'] ?>; text-transform: uppercase; }
    .val { font-size: 13pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .val-mono { font-size: 13pt; font-weight: bold; color: <?= $p['primary'] ?>; letter-spacing: 1.2pt; }
    .val-pending { font-size: 9pt; font-weight: bold; color: <?= $p['pendC'] ?>; }
    .hint { font-size: 6.4pt; color: <?= $p['slate2'] ?>; }
    .qrfallback { font-size: 8pt; font-weight: bold; color: <?= $p['ink'] ?>; border: 0.8pt solid <?= $p['hair'] ?>; padding: 4pt; text-align: center; }

    /* ---------- SECTION + BODY PRIMITIVES (used by service bodies) ---------- */
    .sec-h { font-size: 7.5pt; font-weight: bold; letter-spacing: 1.4pt; color: <?= $p['ink'] ?>; border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding-bottom: 3pt; margin: 13pt 0 7pt; text-transform: uppercase; }
    .leg { font-size: 7pt; font-weight: bold; color: #fff; background: <?= $statusOk ? $p['primary'] : $p['pendC'] ?>; padding: 2.5pt 9pt; border-radius: 3pt; white-space: nowrap; }
    .legsub { font-size: 8.5pt; font-weight: bold; color: <?= $p['slate'] ?>; }
    .legtot { font-size: 8pt; font-weight: bold; color: <?= $p['slate'] ?>; }
    .card { border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 8pt; margin-bottom: 7pt; }
    .card-top { background: <?= $p['panel'] ?>; padding: 7pt 11pt; border-bottom: 0.5pt solid <?= $p['hair'] ?>; }
    .card-top table { width: 100%; border-collapse: collapse; } .card-top td { border: none; }
    .al-logo { width: 26pt; height: 26pt; }
    .al-mono { width: 26pt; height: 26pt; background: <?= $p['sky'] ?>; border: 0.5pt solid #cfe0ff; border-radius: 6pt; color: <?= $p['primary'] ?>; font-size: 8.5pt; font-weight: bold; text-align: center; }
    .al-name { font-size: 10pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .al-fl { font-size: 7.5pt; color: <?= $p['slate'] ?>; font-weight: bold; }
    .cabin { font-size: 7pt; font-weight: bold; color: <?= $p['primary'] ?>; background: <?= $p['sky'] ?>; padding: 2.5pt 8pt; border-radius: 9pt; text-transform: uppercase; }
    .card-body { padding: 11pt 12pt; }
    .card-body table { width: 100%; border-collapse: collapse; } .card-body td { border: none; }
    .seg-code { font-size: 20pt; font-weight: bold; color: <?= $p['ink'] ?>; line-height: 1; }
    .seg-time { font-size: 11pt; font-weight: bold; color: <?= $p['ink'] ?>; margin-top: 4pt; }
    .seg-date { font-size: 7.5pt; color: <?= $p['slate'] ?>; }
    .seg-apt  { font-size: 7.5pt; color: <?= $p['slate'] ?>; margin-top: 3pt; }
    .seg-mid { text-align: center; }
    .seg-mid .d { font-size: 8pt; font-weight: bold; color: <?= $p['slate'] ?>; }
    .seg-mid .l { border-bottom: 0.7pt solid <?= $p['hair'] ?>; margin: 4pt 2pt; }
    .seg-mid .s { font-size: 7pt; font-weight: bold; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .cardfoot { border-top: 0.5pt dashed <?= $p['hair'] ?>; padding: 6pt 12pt; font-size: 7.5pt; color: <?= $p['slate'] ?>; }
    .fic { font-size: 6.2pt; font-weight: bold; letter-spacing: .4pt; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .chip { font-size: 7.5pt; color: <?= $p['ink'] ?>; }
    .chip b { color: <?= $p['ink'] ?>; }
    .chip-ok { color: <?= $p['okC'] ?>; font-weight: bold; }
    .chip-pend { color: <?= $p['pendC'] ?>; font-weight: bold; }
    /* layover strip between connecting segments */
    .layover { margin: 0 0 7pt 18pt; }
    .laytag { font-size: 7.5pt; font-weight: bold; color: <?= $p['pendC'] ?>; background: <?= $p['pendBg'] ?>; border: 0.5pt solid <?= $p['pendBd'] ?>; padding: 4pt 10pt; border-radius: 7pt; }
    /* passenger + generic info table */
    .infotable { width: 100%; border-collapse: collapse; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 6pt; }
    .infotable td { border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding: 8pt 11pt; }
    .infotable tr:last-child td { border-bottom: none; }
    .k { font-size: 6.4pt; font-weight: bold; letter-spacing: .5pt; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .v { font-size: 10pt; color: <?= $p['ink'] ?>; font-weight: bold; }
    .vs { font-size: 8pt; color: <?= $p['slate'] ?>; }
    .mid { font-size: 11pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .big { font-size: 18pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .tag { font-size: 7pt; font-weight: bold; color: <?= $p['primary'] ?>; background: <?= $p['sky'] ?>; padding: 2pt 6pt; border-radius: 3pt; }
    .card-title { font-size: 10pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .card-sub { font-size: 8pt; color: <?= $p['slate'] ?>; }

    /* ---------- GUIDANCE + FOOTER ---------- */
    .guide { background: <?= $statusOk ? '#EEF4FF' : $p['pendBg'] ?>; border: 0.5pt solid <?= $statusOk ? '#CFE0FF' : $p['pendBd'] ?>; border-left: 3pt solid <?= $statusOk ? $p['primary'] : $p['pendC'] ?>; border-radius: 7pt; padding: 10pt 12pt; margin-top: 12pt; }
    .gt { font-size: 8.5pt; font-weight: bold; color: <?= $statusOk ? $p['ink2'] : '#6B4410' ?>; margin-bottom: 4pt; }
    .guide ul { margin: 0; padding-left: 11pt; }
    .guide li { font-size: 7.8pt; color: <?= $statusOk ? '#28406B' : '#5A4524' ?>; line-height: 1.55; }
    .foot { border-top: 0.5pt solid <?= $p['hair'] ?>; margin-top: 12pt; padding-top: 8pt; }
    .foot table { width: 100%; border-collapse: collapse; } .foot td { border: none; }
    .foot-lgl { font-size: 6.6pt; color: <?= $p['slate2'] ?>; line-height: 1.5; }
    .foot-iss { font-size: 7pt; color: <?= $p['slate'] ?>; text-align: right; }
    .foot-iss b { color: <?= $p['ink'] ?>; font-size: 8.5pt; }
</style>
</head>
<body>
    <!-- Header band -->
    <div class="band"><table><tr>
        <td style="width:62%;">
            <?php if ($__logoSrc !== ''): ?>
                <div class="eyebrow"><?= $e($docSub) ?></div>
                <img src="<?= $e($__logoSrc) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
            <?php else: ?>
                <div class="eyebrow"><?= $e($docSub) ?></div>
                <div class="wm"><?= $e($businessName) ?></div>
            <?php endif; ?>
            <div class="issuer">Issued by <?= $e($businessName) ?><?php if ($businessEmail !== ''): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?><?php if ($businessPhone !== ''): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="doctitle"><?= $e($docTitle) ?></div>
            <div style="margin-top:7pt; text-align:right;"><span class="seal">&#9679; <?= $e($sealTx) ?></span></div>
        </td>
    </tr></table></div>

    <!-- Optional premium hero (flights route) -->
    <?= $heroHtml ?>

    <!-- Reference rail -->
    <div class="rail"><table><tr>
        <td style="width:28%;">
            <div class="lbl"><?= $e($refPrimaryLabel) ?></div>
            <?php if ($refPrimaryValue !== ''): ?>
                <div class="<?= $refPrimaryMono ? 'val-mono' : 'val' ?>"><?= $e($refPrimaryValue) ?></div>
                <?php if ($refPrimaryHint !== ''): ?><div class="hint"><?= $e($refPrimaryHint) ?></div><?php endif; ?>
            <?php else: ?>
                <div class="val-pending">Pending</div>
                <div class="hint">Issued once confirmed</div>
            <?php endif; ?>
        </td>
        <td style="width:28%;">
            <div class="lbl"><?= $e($businessName) ?> Booking</div>
            <div class="val"><?= $e($invoiceId) ?></div>
            <div class="hint">Quote for support &amp; changes</div>
        </td>
        <td style="width:22%;">
            <div class="lbl">Issued</div>
            <div class="val" style="font-size:9pt;"><?= $e($bookingDate) ?></div>
            <div class="hint">Passenger copy</div>
        </td>
        <td style="width:22%; text-align:center;">
            <?= $qrBlock ?>
            <div class="hint" style="text-align:center;">Scan to manage</div>
        </td>
    </tr></table></div>

    <!-- Service-specific body -->
    <?= $bodyHtml ?>

    <!-- Guidance -->
    <?php if ($guidanceTitle !== '' || !empty($guidanceItems)): ?>
    <div class="guide">
        <?php if ($guidanceTitle !== ''): ?><div class="gt"><?= $e($guidanceTitle) ?></div><?php endif; ?>
        <?php if (!empty($guidanceItems)): ?>
        <ul><?php foreach ($guidanceItems as $gi): ?><li><?= $gi /* pre-built HTML */ ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="foot"><table><tr>
        <td style="width:64%;"><div class="foot-lgl"><?= $e($footerNote) ?></div></td>
        <td style="width:36%;"><div class="foot-iss"><b><?= $e($businessName) ?></b><br><?php if ($businessEmail !== ''): ?><?= $e($businessEmail) ?><br><?php endif; ?>Generated <?= $e(date('d M Y, H:i')) ?></div></td>
    </tr></table></div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
