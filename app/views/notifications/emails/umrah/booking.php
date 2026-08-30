<?php
if (!isset($SECURE)) die('Direct access not permitted');

$umrahName = $umrahName ?? $umrah_name ?? 'Umrah Package';
$umrahLocation = $umrahLocation ?? $umrah_location ?? $location ?? '';
?>

<?php if ($paymentStatus === 'paid'): ?>
<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #065f46; margin-bottom: 4px;">PAYMENT RECEIVED</div>
    <div style="font-size: 13px; color: #047857;">Your payment has been successfully processed and your Umrah booking is confirmed.</div>
</div>
<?php else: ?>
<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #92400e; margin-bottom: 4px;">⌛ BOOKING RECEIVED</div>
    <div style="font-size: 13px; color: #b45309;">Your Umrah booking request has been received. Please complete payment to confirm your reservation.</div>
</div>
<?php endif; ?>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($firstName . ' ' . $lastName) ?></strong>,
    </div>
    
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?= ($paymentStatus === 'paid') 
            ? "We've received your payment for Umrah booking <strong>#$invoiceId</strong>. Your package is now officially confirmed!" 
            : "Thank you for choosing us! We've received your Umrah booking request <strong>#$invoiceId</strong>. Please complete your payment to secure your reservation." 
        ?>
    </div>

    <!-- Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #2d3748; color: #ffffff; padding: 12px 16px;">
            <div style="font-size: 15px; font-weight: 600;"><?= htmlspecialchars($umrahName) ?></div>
            <div style="font-size: 12px; opacity: 0.8;"><?= htmlspecialchars($umrahLocation) ?></div>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 12px 16px; border-right: 1px solid #e2e8f0; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Start Date</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= !empty($startDate) ? date('D, M d, Y', strtotime($startDate)) : 'N/A' ?></div>
                </td>
                <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Duration</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= htmlspecialchars($duration ?? 'N/A') ?></div>
                </td>
            </tr>
        </table>
        <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #4a5568;">
            For <?= $adults ?> Adults<?= $children > 0 ? ", $children Children" : "" ?>
        </div>
    </div>

    <!-- Payment Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;">Payment Summary</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #718096;"><?= ($paymentStatus === 'paid') ? 'Amount Paid:' : 'Total Amount:' ?></td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 600; text-align: right;">
                    <?= $currency ?> <?= number_format($finalTotal, 2) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #718096;">Status:</td>
                <td style="padding: 8px 0; color: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; font-weight: 600; text-align: right; text-transform: uppercase;">
                    <?= ($paymentStatus === 'paid') ? 'Paid' : 'Pending Payment' ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= $invoiceUrl ?>" style="display: inline-block; background: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Invoice
        </a>
    </div>

    <!-- Next Steps Box -->
    <div style="background: <?= ($paymentStatus === 'paid') ? '#ecfdf5' : '#eff6ff' ?>; border: 1px solid <?= ($paymentStatus === 'paid') ? '#d1fae5' : '#bfdbfe' ?>; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: <?= ($paymentStatus === 'paid') ? '#065f46' : '#1e3a8a' ?>; line-height: 1.6;">
            <strong>Next Steps:</strong><br>
            <?= ($paymentStatus === 'paid') 
                ? "Please keep this confirmation handy. You can download your vouchers from the link above. We wish you a blessed Umrah journey!" 
                : "To confirm your reservation, please complete the payment using the button above. Your booking will be secured once payment is received." 
            ?>
        </div>
    </div>
</div>
