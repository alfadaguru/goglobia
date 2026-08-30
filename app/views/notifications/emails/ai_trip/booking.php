<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>
<?php
$packageItems = is_array($items ?? null) ? $items : [];
$isPaid = strtolower((string)($paymentStatus ?? 'unpaid')) === 'paid';
?>

<div style="background: <?= $isPaid ? '#ecfdf5' : '#fffbeb' ?>; border-left: 4px solid <?= $isPaid ? '#10b981' : '#f59e0b' ?>; padding: 20px 32px;">
    <div style="font-size: 14px; font-weight: 600; color: <?= $isPaid ? '#065f46' : '#92400e' ?>;">
        <?= $isPaid ? 'AI TRIP CONFIRMED' : 'AI TRIP INVOICE' ?>
    </div>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 18px;">
        Hello <strong><?= htmlspecialchars(trim((string)$firstName . ' ' . (string)$lastName)) ?></strong>,
    </div>
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 24px; line-height: 1.6;">
        Here is your AI Trip package invoice <strong>#<?= htmlspecialchars((string)$invoiceId) ?></strong>.
    </div>

    <?php if ($packageItems !== []): ?>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 24px; overflow: hidden;">
        <div style="background: #2d3748; color: #fff; padding: 12px 16px; font-size: 14px; font-weight: 600;">Package items</div>
        <table style="width: 100%; border-collapse: collapse;">
            <?php foreach ($packageItems as $item): ?>
                <?php if (!is_array($item)) continue; ?>
                <tr>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #2d3748;">
                        <strong><?= htmlspecialchars((string)($item['title'] ?? ucfirst((string)($item['module'] ?? 'Trip item')))) ?></strong>
                        <?php if (!empty($item['subtitle'])): ?>
                            <div style="font-size: 12px; color: #718096; margin-top: 3px;"><?= htmlspecialchars((string)$item['subtitle']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0; text-align: right; white-space: nowrap; color: #2d3748;">
                        <?= htmlspecialchars((string)($item['currency'] ?? $currency)) ?>
                        <?= number_format((float)($item['price'] ?? 0), 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="color: #718096;">Total</td>
                <td style="text-align: right; color: #2d3748; font-weight: 600;">
                    <?= htmlspecialchars((string)$currency) ?> <?= number_format((float)$finalTotal, 2) ?>
                </td>
            </tr>
        </table>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="<?= htmlspecialchars((string)$invoiceUrl) ?>" style="display: inline-block; background: #2563eb; color: #fff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Invoice
        </a>
    </div>
</div>
