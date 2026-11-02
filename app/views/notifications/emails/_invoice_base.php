<?php
/**
 * ============================================================================
 * SHARED INVOICE BASE — premium financial document shell (mPDF-safe)
 * ============================================================================
 * The money document (priced INVOICE / RECEIPT) for every service. Mirrors the
 * premium look of _ticket_base.php (same palette + header band) but the HERO is
 * the PRICE BLOCK: a clean subtotal / tax / discount / total with a bold paid/due
 * status and the payment method — a serious, trustworthy receipt, not a flyer.
 *
 * Each service's booking_pdf.php computes its line rows + a small detail body,
 * then calls goglobia_invoice_render($cfg). ONE design, no duplicated chrome/CSS.
 * The financial figures are already computed + currency-converted by
 * GENERATE_BOOKING_PDF ($subtotalAmount/$taxAmount/$totalAmount/$currency/
 * $statusText) — the service just forwards them here.
 *
 * mPDF constraints: NO flexbox/grid — table layout; colours inlined.
 *
 * $cfg keys (all optional unless noted):
 *   booking, settings, businessName, businessEmail, businessPhone, siteUrl,
 *   wlPdfLogo, pdfLogoSrc, agentBrandColors, bookingDate   — from GENERATE_BOOKING_PDF
 *   docTitle      (string)  default "Invoice"
 *   docSub        (string)  small eyebrow line (e.g. "Flight Booking")
 *   paid          (bool)    true => green PAID seal, false => amber "Payment due"
 *   statusText    (string)  raw status word (Paid / Pending / Refunded …)
 *   billToName/billToEmail/billToPhone (string)  the customer the invoice is addressed to
 *   heroHtml      (string)  OPTIONAL service summary band (route / hotel / tour)
 *   bodyHtml      (string)  service detail section(s), already escaped
 *   currency      (string)  display currency code (e.g. "NGN")
 *   priceRows     (array)   [ ['label'=>.., 'value'=>.., 'muted'=>bool, 'minus'=>bool], … ]
 *                           the subtotal/ancillaries/tax/discount lines ABOVE the total
 *   totalLabel    (string)  default "Total"
 *   totalValue    (string)  formatted grand total (without currency; base adds it)
 *   paymentMethod (string)  gateway/method label
 *   paidValue, dueValue (string)  OPTIONAL amount paid / balance due (part-payment)
 *   notesTitle    (string)  e.g. "Important information"
 *   notesItems    (array)   HTML strings
 *   footerNote    (string)  legal line
 * ============================================================================
 */

require_once __DIR__ . '/_ticket_base.php'; // reuse goglobia_ticket_palette()

if (!function_exists('goglobia_invoice_render')) {
    function goglobia_invoice_render(array $cfg)
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

        $paid        = !empty($cfg['paid']);
        $statusText  = (string) ($cfg['statusText'] ?? ($paid ? 'Paid' : 'Pending'));
        $docTitle    = (string) ($cfg['docTitle'] ?? 'Invoice');
        $docSub      = (string) ($cfg['docSub'] ?? 'Booking Receipt');
        $heroHtml    = (string) ($cfg['heroHtml'] ?? '');
        $bodyHtml    = (string) ($cfg['bodyHtml'] ?? '');

        $billToName  = trim((string) ($cfg['billToName'] ?? ''));
        $billToEmail = trim((string) ($cfg['billToEmail'] ?? ''));
        $billToPhone = trim((string) ($cfg['billToPhone'] ?? ''));

        $currency    = strtoupper(trim((string) ($cfg['currency'] ?? '')));
        $priceRows   = is_array($cfg['priceRows'] ?? null) ? $cfg['priceRows'] : [];
        $totalLabel  = (string) ($cfg['totalLabel'] ?? 'Total');
        $totalValue  = (string) ($cfg['totalValue'] ?? '0.00');
        $paymentMethod = trim((string) ($cfg['paymentMethod'] ?? ''));
        $paidValue   = isset($cfg['paidValue']) ? (string) $cfg['paidValue'] : '';
        $dueValue    = isset($cfg['dueValue']) ? (string) $cfg['dueValue'] : '';

        $notesTitle  = (string) ($cfg['notesTitle'] ?? '');
        $notesItems  = (array) ($cfg['notesItems'] ?? []);
        $footerNote  = (string) ($cfg['footerNote'] ?? 'Thank you for booking with ' . $businessName . '. This invoice reflects the amount for your booking; your travel document is issued separately.');

        $money = function ($v) use ($currency, $e) {
            $v = trim((string) $v);
            return ($currency !== '' ? $e($currency) . ' ' : '') . $e($v);
        };

        // Status accent: green when paid, amber when due.
        $sealC  = $paid ? $p['okC']  : $p['pendC'];
        $sealBg = $paid ? $p['okBg'] : $p['pendBg'];
        $bandGrad = 'linear-gradient(115deg, ' . $p['ink'] . ' 0%, ' . $p['ink2'] . ' 55%, ' . $p['primary'] . ' 165%)';

        // Logo (resolved once by GENERATE_BOOKING_PDF).
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

    /* header — clean light header with a brand rule (matches the ticket). mPDF
       renders CSS gradients with a harsh white blowout, so we do NOT use a dark
       gradient band here (it made the logo + issuer text invisible). */
    .band { border-bottom: 2.2pt solid <?= $p['primary'] ?>; padding: 0 0 10pt; margin-bottom: 4pt; }
    .band table { width: 100%; border-collapse: collapse; } .band td { border: none; }
    .logo-img { height: 26pt; width: auto; max-width: 150pt; }
    .eyebrow { font-size: 6.5pt; font-weight: bold; letter-spacing: 2.4pt; color: <?= $p['slate'] ?>; text-transform: uppercase; }
    .wm { font-size: 17pt; font-weight: bold; color: <?= $p['primary'] ?>; margin-top: 1pt; }
    .issuer { font-size: 7pt; color: <?= $p['slate'] ?>; margin-top: 4pt; }
    .doctitle { font-size: 10pt; font-weight: bold; letter-spacing: 1.6pt; text-transform: uppercase; text-align: right; color: <?= $p['ink'] ?>; }
    .seal { font-size: 7.5pt; font-weight: bold; color: <?= $sealC ?>; background: <?= $sealBg ?>; padding: 3pt 10pt; border-radius: 9pt; }

    /* meta strip: invoice no / date / bill-to */
    .meta { margin-top: 10pt; }
    .meta table { width: 100%; border-collapse: collapse; } .meta td { border: none; padding: 0 9pt; }
    .lbl { font-size: 6pt; font-weight: bold; letter-spacing: .8pt; color: <?= $p['slate'] ?>; text-transform: uppercase; }
    .val { font-size: 11pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .valsm { font-size: 8.5pt; color: <?= $p['ink'] ?>; }
    .valsm b { color: <?= $p['ink'] ?>; }

    .sec-h { font-size: 7.5pt; font-weight: bold; letter-spacing: 1.4pt; color: <?= $p['ink'] ?>; border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding-bottom: 3pt; margin: 14pt 0 8pt; text-transform: uppercase; }

    /* line-items + price block */
    .items { width: 100%; border-collapse: collapse; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 8pt; }
    .items td { border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding: 8pt 12pt; font-size: 8.5pt; }
    .items tr:last-child td { border-bottom: none; }
    .items .lab { color: <?= $p['slate'] ?>; }
    .items .lab b { color: <?= $p['ink'] ?>; }
    .items .amt { text-align: right; color: <?= $p['ink'] ?>; font-weight: bold; }
    .items .amt-muted { text-align: right; color: <?= $p['slate'] ?>; }

    .total { background: <?= $p['panel'] ?>; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 8pt; margin-top: 8pt; padding: 11pt 14pt; }
    .total table { width: 100%; border-collapse: collapse; } .total td { border: none; }
    .total-lab { font-size: 8pt; font-weight: bold; letter-spacing: .8pt; text-transform: uppercase; color: <?= $p['slate'] ?>; }
    .total-val { font-size: 18pt; font-weight: bold; color: <?= $p['ink'] ?>; text-align: right; }
    .paybadge { font-size: 7.5pt; font-weight: bold; color: <?= $sealC ?>; background: <?= $sealBg ?>; padding: 3pt 9pt; border-radius: 9pt; }
    .paysplit { font-size: 7.5pt; color: <?= $p['slate'] ?>; }
    .paysplit b { color: <?= $p['ink'] ?>; }

    /* generic info block reuse */
    .infobox { background: <?= $p['panel'] ?>; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 7pt; padding: 10pt 12pt; }
    .inforow { font-size: 8pt; margin-bottom: 3pt; }
    .infok { color: <?= $p['slate'] ?>; }
    .infov { color: <?= $p['ink'] ?>; font-weight: bold; }

    /* hero (optional service summary) + primitives shared with ticket look */
    .hero { padding: 14pt 6pt 8pt; }
    .hero table { width: 100%; border-collapse: collapse; } .hero td { border: none; }
    .port-code { font-size: 30pt; font-weight: bold; color: <?= $p['ink'] ?>; letter-spacing: -.5pt; line-height: 1; }
    .port-city { font-size: 10pt; font-weight: bold; color: <?= $p['ink'] ?>; margin-top: 3pt; }
    .port-apt  { font-size: 7.5pt; color: <?= $p['slate'] ?>; }
    .hero-mid { text-align: center; }
    .hero-dur { font-size: 7.5pt; font-weight: bold; color: <?= $p['primary'] ?>; }
    .hero-line { border-bottom: 0.8pt dotted <?= $p['slate2'] ?>; margin: 5pt 4pt; }
    .hero-plane { font-size: 12pt; color: <?= $p['primary'] ?>; }
    .hero-stops { font-size: 7pt; font-weight: bold; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .card { border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 8pt; margin-bottom: 7pt; }
    .card-top { background: <?= $p['panel'] ?>; padding: 7pt 11pt; border-bottom: 0.5pt solid <?= $p['hair'] ?>; }
    .card-top table { width: 100%; border-collapse: collapse; } .card-top td { border: none; }
    .card-title { font-size: 10pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .card-sub { font-size: 8pt; color: <?= $p['slate'] ?>; }
    .card-body { padding: 10pt 12pt; }
    .card-body table { width: 100%; border-collapse: collapse; } .card-body td { border: none; }
    .k { font-size: 6.4pt; font-weight: bold; letter-spacing: .5pt; color: <?= $p['slate2'] ?>; text-transform: uppercase; }
    .v { font-size: 10pt; color: <?= $p['ink'] ?>; font-weight: bold; }
    .vs { font-size: 8pt; color: <?= $p['slate'] ?>; }
    .big { font-size: 18pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .mid { font-size: 11pt; font-weight: bold; color: <?= $p['ink'] ?>; }
    .tag { font-size: 7pt; font-weight: bold; color: <?= $p['primary'] ?>; background: <?= $p['sky'] ?>; padding: 2pt 6pt; border-radius: 3pt; }
    .infotable { width: 100%; border-collapse: collapse; border: 0.5pt solid <?= $p['hair'] ?>; border-radius: 6pt; }
    .infotable td { border-bottom: 0.5pt solid <?= $p['hair'] ?>; padding: 8pt 11pt; }
    .infotable tr:last-child td { border-bottom: none; }

    /* notes + footer */
    .guide { background: #EEF4FF; border: 0.5pt solid #CFE0FF; border-left: 3pt solid <?= $p['primary'] ?>; border-radius: 7pt; padding: 10pt 12pt; margin-top: 12pt; }
    .gt { font-size: 8.5pt; font-weight: bold; color: <?= $p['ink2'] ?>; margin-bottom: 4pt; }
    .guide ul { margin: 0; padding-left: 11pt; } .guide li { font-size: 7.6pt; color: #28406B; line-height: 1.5; }
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
            <div class="eyebrow"><?= $e($docSub) ?></div>
            <?php if ($__logoSrc !== ''): ?>
                <img src="<?= $e($__logoSrc) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
            <?php else: ?>
                <div class="wm"><?= $e($businessName) ?></div>
            <?php endif; ?>
            <div class="issuer">Issued by <?= $e($businessName) ?><?php if ($businessEmail !== ''): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?><?php if ($businessPhone !== ''): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?></div>
        </td>
        <td style="width:38%; text-align:right;">
            <div class="doctitle"><?= $e($docTitle) ?></div>
            <div style="margin-top:7pt; text-align:right;"><span class="seal">&#9679; <?= $e($statusText) ?></span></div>
        </td>
    </tr></table></div>

    <!-- Meta: invoice no / date / bill-to -->
    <div class="meta"><table><tr>
        <td style="width:26%;">
            <div class="lbl"><?= $e($businessName) ?> Invoice</div>
            <div class="val"><?= $e($invoiceId) ?></div>
        </td>
        <td style="width:22%;">
            <div class="lbl">Issued</div>
            <div class="valsm" style="font-weight:bold;"><?= $e($bookingDate) ?></div>
        </td>
        <td style="width:52%; text-align:right;">
            <div class="lbl">Billed to</div>
            <?php if ($billToName !== ''): ?><div class="valsm"><b><?= $e($billToName) ?></b></div><?php endif; ?>
            <div class="valsm" style="color:<?= $p['slate'] ?>; font-size:7.5pt;"><?php
                $bits = array_filter([$billToEmail, $billToPhone]);
                echo $e(implode(' · ', $bits));
            ?></div>
        </td>
    </tr></table></div>

    <!-- Optional service summary hero -->
    <?= $heroHtml ?>

    <!-- Service detail body -->
    <?= $bodyHtml ?>

    <!-- Price block (the money hero) -->
    <div class="sec-h">Payment Summary</div>
    <?php if (!empty($priceRows)): ?>
    <table class="items">
        <?php foreach ($priceRows as $row):
            if (!is_array($row)) { continue; }
            $rl = (string) ($row['label'] ?? '');
            $rv = (string) ($row['value'] ?? '');
            $muted = !empty($row['muted']);
            $minus = !empty($row['minus']);
            // 'plain' => a non-monetary cell (a count/qty/note like "× 2") — rendered
            // as-is WITHOUT the currency prefix. Otherwise the value is a money amount.
            $plain = !empty($row['plain']);
            $cell = $plain ? $e($rv) : $money($rv);
        ?>
        <tr>
            <td class="lab"<?= $muted ? '' : ' style="font-weight:bold;"' ?>><?php if (!$muted): ?><b><?= $e($rl) ?></b><?php else: ?><?= $e($rl) ?><?php endif; ?></td>
            <td class="<?= $muted ? 'amt-muted' : 'amt' ?>"><?= $minus ? '&minus; ' : '' ?><?= $cell ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div class="total"><table><tr>
        <td style="width:50%;">
            <div class="total-lab"><?= $e($totalLabel) ?></div>
            <div style="margin-top:6pt;"><span class="paybadge">&#9679; <?= $e($statusText) ?></span></div>
            <?php if ($paymentMethod !== ''): ?><div class="paysplit" style="margin-top:5pt;">Paid via <b><?= $e($paymentMethod) ?></b></div><?php endif; ?>
            <?php if ($paidValue !== '' || $dueValue !== ''): ?>
                <div class="paysplit" style="margin-top:3pt;">
                    <?php if ($paidValue !== ''): ?>Paid <b><?= $money($paidValue) ?></b><?php endif; ?>
                    <?php if ($dueValue !== ''): ?>&nbsp;&middot;&nbsp;Balance due <b><?= $money($dueValue) ?></b><?php endif; ?>
                </div>
            <?php endif; ?>
        </td>
        <td style="width:50%; text-align:right; vertical-align:middle;">
            <div class="total-val"><?= $money($totalValue) ?></div>
        </td>
    </tr></table></div>

    <!-- Notes -->
    <?php if ($notesTitle !== '' || !empty($notesItems)): ?>
    <div class="guide">
        <?php if ($notesTitle !== ''): ?><div class="gt"><?= $e($notesTitle) ?></div><?php endif; ?>
        <?php if (!empty($notesItems)): ?><ul><?php foreach ($notesItems as $ni): ?><li><?= $ni /* pre-built HTML */ ?></li><?php endforeach; ?></ul><?php endif; ?>
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
