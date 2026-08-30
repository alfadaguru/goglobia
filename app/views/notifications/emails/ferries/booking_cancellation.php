<?php
// app/views/notifications/emails/ferries/booking_cancellation.php
@$SECURE or die('Access Denied!');
?>

<div style="background: #fff1f2; border-left: 4px solid #e11d48; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #881337; margin-bottom: 4px;">⚠ CANCELLATION REQUEST</div>
    <div style="font-size: 13px; color: #9f1239;">Your request to cancel the ferry booking has been received.</div>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($booking['first_name'] . ' ' . ($booking['last_name'] ?? '')) ?></strong>,
    </div>

    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        We have received your cancellation request for ferry booking <strong>#<?= $booking['invoice_id'] ?></strong>. This request is now being reviewed by our customer support team.
    </div>

    <!-- Booking Status Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;">Booking Status</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Reference #:</td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 500; text-align: right; font-size: 14px;"><?= $booking['invoice_id'] ?></td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Current Status:</td>
                <td style="padding: 8px 0; color: #e11d48; font-weight: 600; text-align: right; text-transform: uppercase; font-size: 14px;">Cancellation Pending</td>
            </tr>
        </table>
    </div>

    <p style="color: #4a5568; font-size: 14px; margin-bottom: 24px; line-height: 1.6;">
        Our team will process your request in accordance with the ferry operator's cancellation policy. We will notify you once the cancellation has been finalized and any applicable refunds have been calculated.
    </p>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= root ?>invoice/ferries/<?= $booking['invoice_id'] ?>" style="display: inline-block; background: #e11d48; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Booking Status
        </a>
    </div>

    <!-- Warning Box -->
    <div style="background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: #9d174d; line-height: 1.6;">
            <strong>Please Note:</strong><br>
            If you did not initiate this request or would like to withdraw it, please contact our support team immediately.
        </div>
    </div>
</div>
