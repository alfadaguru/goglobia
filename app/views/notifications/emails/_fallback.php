<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>
<?php
// NC-7 — LAST-RESORT booking email body. Used ONLY when no per-service template
// (emails/<module>/booking.php etc.) resolves, so a confirmation is NEVER silently
// dropped. Self-contained + defensive: every value is optional and escaped.
// Honours $hide_price (the customer copy is rendered with hide_price=1).
$__inv   = htmlspecialchars((string) ($invoice_id ?? ($invoiceId ?? '')));
$__name  = htmlspecialchars((string) ($first_name ?? ($customerName ?? ($name ?? 'there'))));
$__mod   = htmlspecialchars((string) ($module_type ?? ($moduleType ?? ($module ?? 'booking'))));
$__ccy   = htmlspecialchars((string) ($currency ?? ($currency_markup ?? '')));
$__amt   = $amount ?? ($price_markup ?? ($total ?? null));
$__paid  = (string) ($paymentStatus ?? ($payment_status ?? '')) === 'paid';
$__hide  = !empty($hide_price);
?>
<div style="padding: 24px 32px; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1f2937;">
    <p style="font-size: 15px; margin: 0 0 12px;">Hello <?= $__name ?>,</p>
    <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px;">
        Your <?= $__mod ?> booking<?= $__inv !== '' ? ' <strong>#' . $__inv . '</strong>' : '' ?>
        has been <?= $__paid ? 'confirmed and paid' : 'received' ?>.
        <?= $__paid ? 'Thank you.' : 'We are processing it and will update you shortly.' ?>
    </p>
    <?php if (!$__hide && $__amt !== null && $__amt !== ''): ?>
    <p style="font-size: 14px; margin: 0 0 16px;">Amount: <strong><?= $__ccy ?> <?= htmlspecialchars((string) number_format((float) $__amt, 2)) ?></strong></p>
    <?php endif; ?>
    <?php if ($__inv !== ''): ?>
    <p style="font-size: 13px; margin: 0;">
        You can view your booking details here:
        <a href="<?= htmlspecialchars(rtrim((string) (defined('root') ? root : '/'), '/') . '/invoice/' . $__mod . '/' . $__inv) ?>" style="color:#2563eb;">View booking</a>
    </p>
    <?php endif; ?>
</div>
