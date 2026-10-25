<?php
/**
 * ============================================================================
 * GENERIC BOOKING VOUCHER PDF — used by services without a bespoke template
 * (esim, ferries, insurance). Agent-brand aware: honours $wlPdfLogo (agency
 * logo), $businessName/$businessEmail/$businessPhone (already swapped to the
 * agency by GENERATE_BOOKING_PDF's agent_booking_brand hook), and the optional
 * $agentBrandColors['primary'] for the header accent.
 * Vars provided by GENERATE_BOOKING_PDF: $booking, $bookingData, $travellersData,
 * $settings, $businessName, $businessEmail, $businessPhone, $wlPdfLogo,
 * $invoiceId, $agentBrandColors (nullable).
 * ============================================================================
 */
if (!isset($SECURE)) die('Direct access not permitted');

$moduleType = ucfirst(strtolower((string)($booking['module_type'] ?? 'Booking')));
$primary = '#2d3748';
if (!empty($agentBrandColors['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$agentBrandColors['primary'])) {
    $primary = (string) $agentBrandColors['primary'];
}
$ccy  = htmlspecialchars((string)($booking['currency_markup'] ?? ''));
$fmt  = fn($n) => number_format((float)$n, 2);
// Client price (agency self-markup) if present, else what was charged.
$displayTotal = (float)($booking['agent_client_price'] ?? 0) > 0
    ? (float)$booking['agent_client_price']
    : (float)($booking['price_markup'] ?? $booking['price_original'] ?? 0);

// Logo: agency logo (absolute URL via $wlPdfLogo) else platform file.
$logoSrc = !empty($wlPdfLogo) ? $wlPdfLogo : (($_SERVER['DOCUMENT_ROOT'] ?? '') . '/uploads/global/logo.png');

// Flatten any traveller/passenger list for display.
$pax = [];
$src = $travellersData['travelers'] ?? $travellersData ?? [];
if (is_array($src)) {
    foreach ($src as $k => $v) {
        if (is_array($v) && !empty(trim((string)($v['first_name'] ?? '')))) {
            $pax[] = $v;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size:9pt; color:#1a202c; line-height:1.4; }
        .header { padding:12px 15px; margin-bottom:14px; border-bottom:2px solid <?= htmlspecialchars($primary) ?>; }
        .header table { width:100%; border:none; } .header td { border:none; padding:0; vertical-align:middle; }
        .logo-img { height:34px; width:auto; }
        .biz { font-size:12pt; font-weight:bold; color: <?= htmlspecialchars($primary) ?>; }
        .contact { font-size:7pt; color:#4a5568; } .contact div { margin-bottom:1px; }
        .ref { text-align:right; } .ref-label { font-size:7pt; color:#718096; text-transform:uppercase; }
        .ref-number { font-size:13pt; font-weight:bold; color: <?= htmlspecialchars($primary) ?>; }
        .title { font-size:11pt; font-weight:bold; color:#2d3748; margin:0 15px 8px; }
        .section { margin:0 15px 10px; }
        .section-title { font-size:9pt; font-weight:bold; color:#2d3748; border-bottom:1px solid #cbd5e0; padding-bottom:3px; margin-bottom:5px; }
        .row { font-size:8pt; margin-bottom:3px; } .label { color:#718096; display:inline-block; width:120px; }
        table.items { width:calc(100% - 30px); margin:0 15px 10px; border-collapse:collapse; }
        table.items th { background:#f7fafc; font-size:7pt; text-transform:uppercase; color:#718096; text-align:left; padding:5px 8px; border-bottom:1px solid #e2e8f0; }
        table.items td { font-size:8pt; padding:5px 8px; border-bottom:1px solid #f1f5f9; }
        .total { text-align:right; font-size:11pt; font-weight:bold; color: <?= htmlspecialchars($primary) ?>; margin:4px 15px 10px; }
        .footer { background:#f7fafc; padding:8px 10px; margin:12px 15px 0; border-top:1px solid #cbd5e0; text-align:center; }
        .footer-contact { font-size:7pt; color:#4a5568; margin-bottom:4px; }
        .footer-note { font-size:6pt; color:#718096; }
        .badge { display:inline-block; font-size:7pt; padding:2px 8px; border-radius:10px; }
        .paid { background:#d1fae5; color:#065f46; } .unpaid { background:#fef3c7; color:#92400e; }
    </style>
</head>
<body>
    <div class="header">
        <table><tr>
            <td style="width:40%;">
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars((string)$businessName) ?>" class="logo-img"><br>
                <span class="biz"><?= htmlspecialchars((string)$businessName) ?></span>
            </td>
            <td style="width:33%;" class="contact">
                <?php if (!empty($businessEmail)): ?><div><?= htmlspecialchars((string)$businessEmail) ?></div><?php endif; ?>
                <?php if (!empty($businessPhone)): ?><div><?= htmlspecialchars((string)$businessPhone) ?></div><?php endif; ?>
                <?php if (!empty($agentBrandColors['address'])): ?><div><?= htmlspecialchars((string)$agentBrandColors['address']) ?></div><?php endif; ?>
            </td>
            <td style="width:27%;" class="ref">
                <div class="ref-label">Booking Ref</div>
                <div class="ref-number"><?= htmlspecialchars((string)$invoiceId) ?></div>
                <div style="margin-top:4px;"><span class="badge <?= ($booking['payment_status'] ?? '')==='paid'?'paid':'unpaid' ?>"><?= htmlspecialchars((string)($booking['payment_status'] ?? '')) ?></span></div>
            </td>
        </tr></table>
    </div>

    <div class="title"><?= htmlspecialchars($moduleType) ?> Booking Confirmation</div>

    <div class="section">
        <div class="section-title">Booking details</div>
        <div class="row"><span class="label">Service</span><?= htmlspecialchars($moduleType) ?></div>
        <div class="row"><span class="label">Reference</span><?= htmlspecialchars((string)$invoiceId) ?></div>
        <div class="row"><span class="label">Status</span><?= htmlspecialchars((string)($booking['booking_status'] ?? '')) ?></div>
        <div class="row"><span class="label">Date</span><?= htmlspecialchars((string)($booking['created_at'] ?? date('Y-m-d'))) ?></div>
        <?php if (!empty($booking['pnr'])): ?><div class="row"><span class="label">Confirmation #</span><?= htmlspecialchars((string)$booking['pnr']) ?></div><?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">Lead contact</div>
        <div class="row"><span class="label">Name</span><?= htmlspecialchars(trim(((string)($booking['first_name']??'')).' '.((string)($booking['last_name']??'')))) ?></div>
        <?php if (!empty($booking['email'])): ?><div class="row"><span class="label">Email</span><?= htmlspecialchars((string)$booking['email']) ?></div><?php endif; ?>
        <?php if (!empty($booking['phone'])): ?><div class="row"><span class="label">Phone</span><?= htmlspecialchars((string)$booking['phone']) ?></div><?php endif; ?>
    </div>

    <?php if (!empty($pax)): ?>
    <table class="items">
        <tr><th>Traveller</th><th>Nationality</th><th>Document</th></tr>
        <?php foreach ($pax as $p): ?>
        <tr>
            <td><?= htmlspecialchars(trim(((string)($p['title']??'')).' '.((string)($p['first_name']??'')).' '.((string)($p['last_name']??'')))) ?></td>
            <td><?= htmlspecialchars((string)($p['nationality'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)($p['passport_number'] ?? '—')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (($docType ?? 'invoice') !== 'ticket'): /* price hidden on no-price ticket */ ?>
    <div class="total"><?= $ccy ?> <?= $fmt($displayTotal) ?></div>
    <?php endif; ?>

    <div class="footer">
        <div class="footer-contact">
            <?= htmlspecialchars((string)$businessName) ?><?php if (!empty($businessEmail)): ?> · <?= htmlspecialchars((string)$businessEmail) ?><?php endif; ?><?php if (!empty($businessPhone)): ?> · <?= htmlspecialchars((string)$businessPhone) ?><?php endif; ?>
        </div>
        <div class="footer-note">This is your booking confirmation. Please keep it for your records.</div>
    </div>
</body>
</html>
