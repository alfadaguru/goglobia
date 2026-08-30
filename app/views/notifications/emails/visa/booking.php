<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>

<div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #1e40af; margin-bottom: 4px;">📑 VISA INQUIRY RECEIVED</div>
    <div style="font-size: 13px; color: #1e4d8c;">Your visa inquiry has been received and is currently being reviewed by our experts.</div>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($firstName . ' ' . $lastName) ?></strong>,
    </div>
    
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        Thank you for choosing us for your visa assistance! We've received your visa inquiry <strong>#$invoiceId</strong>. Our team will review your application details and contact you shortly with the next steps and payment instructions.
    </div>

    <!-- Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #3b82f6; color: #ffffff; padding: 12px 16px;">
            <div style="font-size: 15px; font-weight: 600;">Visa Inquiry Details</div>
            <div style="font-size: 12px; opacity: 0.9;">🌍 For travel to your destination</div>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 12px 16px; border-right: 1px solid #e2e8f0; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Invoice ID</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;">#<?= htmlspecialchars($invoiceId) ?></div>
                </td>
                <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Status</div>
                    <div style="font-size: 14px; font-weight: 600; color: #3b82f6;">INQUIRY</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= $invoiceUrl ?>" style="display: inline-block; background: #3b82f6; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Inquiry Details
        </a>
    </div>

    <!-- Next Steps Box -->
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: #1e3a8a; line-height: 1.6;">
            <strong>What happens next?</strong><br>
            1. Our visa specialists will review your uploaded documents.<br>
            2. We will contact you via email or phone if any additional information is needed.<br>
            3. Once the review is complete, we will send you the payment instructions to proceed with the application.
        </div>
    </div>
</div>
