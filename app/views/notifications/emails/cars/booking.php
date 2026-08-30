<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>

<?php if ($paymentStatus === 'paid'): ?>
<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #065f46; margin-bottom: 4px;">✅ PAYMENT RECEIVED</div>
    <div style="font-size: 13px; color: #047857;">Your payment for the car rental has been successfully processed.</div>
</div>
<?php else: ?>
<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #92400e; margin-bottom: 4px;">⌛ BOOKING RECEIVED</div>
    <div style="font-size: 13px; color: #b45309;">Your car rental reservation request has been received and is currently pending payment.</div>
</div>
<?php endif; ?>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($first_name . ' ' . $last_name) ?></strong>,
    </div>
    
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?= ($paymentStatus === 'paid') 
            ? "We've received your payment for car rental booking <strong>#$invoice_id</strong>. Your reservation is now officially confirmed!" 
            : "Thank you for choosing us! We've received your car rental booking request <strong>#$invoice_id</strong>. Please complete your payment to secure your vehicle." 
        ?>
    </div>

    <!-- Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #2d3748; color: #ffffff; padding: 12px 16px;">
            <div style="font-size: 15px; font-weight: 600;">Car Rental Reservation</div>
            <div style="font-size: 12px; opacity: 0.9;">Booking ID: #<?= $invoice_id ?></div>
        </div>
        <div style="padding: 16px; font-size: 14px; color: #4a5568;">
            Thank you for booking with <strong><?= $companyName ?></strong>. Your reservation is currently being processed.
        </div>
    </div>

    <!-- Payment Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;">Payment Summary</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Amount Paid:</td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 600; text-align: right; font-size: 14px;">
                    <?= $currency ?> <?= number_format($amount, 2) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Status:</td>
                <td style="padding: 8px 0; color: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; font-weight: 600; text-align: right; text-transform: uppercase; font-size: 14px;">
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
                ? "Please keep this confirmation handy. Our representative will contact you regarding pickup instructions." 
                : "To confirm your car rental, please complete the payment using the button above. Your vehicle will be secured once payment is received." 
            ?>
        </div>
    </div>
</div>
