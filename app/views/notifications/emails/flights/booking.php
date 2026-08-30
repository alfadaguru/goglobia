<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>
<?php $language = $language ?: 'en'; ?>

<?php if ($paymentStatus === 'paid'): ?>
<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #065f46; margin-bottom: 4px;">✅ <?= htmlspecialchars(translateTo('flights_payment_received_title', $language)) ?></div>
    <div style="font-size: 13px; color: #047857;"><?= htmlspecialchars(translateTo('flights_payment_received_desc', $language)) ?></div>
</div>
<?php else: ?>
<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #92400e; margin-bottom: 4px;">⌛ <?= htmlspecialchars(translateTo('flights_booking_received_title', $language)) ?></div>
    <div style="font-size: 13px; color: #b45309;"><?= htmlspecialchars(translateTo('flights_booking_received_desc', $language)) ?></div>
</div>
<?php endif; ?>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        <?= htmlspecialchars(translateTo('hello', $language)) ?> <strong><?= htmlspecialchars($firstName . ' ' . $lastName) ?></strong>,
    </div>

    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?= ($paymentStatus === 'paid')
            ? translateTo('flights_email_intro_paid', $language, ['%invoice%' => "<strong>#$invoiceId</strong>"])
            : translateTo('flights_email_intro_pending', $language, ['%invoice%' => "<strong>#$invoiceId</strong>"])
        ?>
    </div>

    <!-- Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #2d3748; color: #ffffff; padding: 12px 16px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 45%;">
                        <div style="font-size: 16px; font-weight: 700;"><?= htmlspecialchars($from) ?></div>
                    </td>
                    <td style="width: 10%; text-align: center;">
                        <span style="font-size: 16px;">✈️</span>
                    </td>
                    <td style="width: 45%; text-align: right;">
                        <div style="font-size: 16px; font-weight: 700;"><?= htmlspecialchars($to) ?></div>
                    </td>
                </tr>
            </table>
            <div style="font-size: 12px; opacity: 0.8; margin-top: 4px;"><?= date('l, M d, Y', strtotime($departure_date)) ?></div>
        </div>
        <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #4a5568;">
            <strong><?= htmlspecialchars($airlineName) ?></strong> (<?= htmlspecialchars($flightNumber) ?>)
        </div>
    </div>

    <!-- Payment Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;"><?= htmlspecialchars(translateTo('payment_summary', $language)) ?></div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;"><?= htmlspecialchars(($paymentStatus === 'paid') ? translateTo('amount_paid', $language) : translateTo('total_amount', $language)) ?></td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 600; text-align: right; font-size: 14px;">
                    <?= $currency ?> <?= number_format($finalTotal, 2) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;"><?= htmlspecialchars(translateTo('status', $language)) ?>:</td>
                <td style="padding: 8px 0; color: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; font-weight: 600; text-align: right; text-transform: uppercase; font-size: 14px;">
                    <?= htmlspecialchars(($paymentStatus === 'paid') ? translateTo('paid', $language) : translateTo('pending_payment', $language)) ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= $invoiceUrl ?>" style="display: inline-block; background: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            <?= htmlspecialchars(translateTo('view_invoice', $language)) ?>
        </a>
    </div>

    <!-- Next Steps Box -->
    <div style="background: <?= ($paymentStatus === 'paid') ? '#ecfdf5' : '#eff6ff' ?>; border: 1px solid <?= ($paymentStatus === 'paid') ? '#d1fae5' : '#bfdbfe' ?>; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: <?= ($paymentStatus === 'paid') ? '#065f46' : '#1e3a8a' ?>; line-height: 1.6;">
            <strong><?= htmlspecialchars(translateTo('next_steps', $language)) ?></strong><br>
            <?= ($paymentStatus === 'paid')
                ? htmlspecialchars(translateTo('flights_next_steps_paid', $language))
                : htmlspecialchars(translateTo('flights_next_steps_pending', $language))
            ?>
        </div>
    </div>
</div>
