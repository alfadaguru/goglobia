<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>
<?php
/**
 * CREDIT PAYMENT REMINDER EMAIL TEMPLATE
 *
 * Variables:
 * - $receiverName: Agent's full name
 * - $amount_due:   Amount they owe (in credits)
 * - $currency:     Default currency
 * - $days_used:    How many days since first credit usage
 * - $payment_days: Their allowed credit payment days
 * - $days_overdue: Days overdue (days_used - payment_days)
 * - $credit_limit: Total credits assigned
 * - $used_credits: Credits they have consumed
 * - $first_usage_date: Date of first credit usage
 * - $due_date:     Payment deadline date
 */

$brandColor    = '#dc2626'; // Red — payment urgency
$accentColor   = '#1e40af'; // Blue accent
$daysOverdue   = intval($days_overdue ?? 0);
$urgencyLabel  = $daysOverdue <= 0  ? 'PAYMENT DUE TODAY'
               : ($daysOverdue <= 3  ? 'PAYMENT OVERDUE'
               : 'URGENT: PAYMENT REQUIRED');
?>

<!-- Alert Banner -->
<div style="background: <?= $brandColor ?>; padding: 16px 32px; text-align: center;">
    <div style="color: #fff; font-size: 13px; font-weight: 700; letter-spacing: 1px;">
        ⚠️ <?= $urgencyLabel ?>
    </div>
</div>

<!-- Header Info -->
<div style="background: #fef2f2; border-left: 4px solid <?= $brandColor ?>; padding: 20px 32px;">
    <div style="font-size: 14px; font-weight: 600; color: #991b1b; margin-bottom: 4px;">
        💳 CREDIT PAYMENT REMINDER
    </div>
    <div style="font-size: 13px; color: #b91c1c;">
        Your credit balance is overdue. Please make your payment as soon as possible to avoid service disruption.
    </div>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 16px;">
        Hello <strong><?= htmlspecialchars($receiverName) ?></strong>,
    </div>

    <div style="font-size: 14px; color: #4a5568; margin-bottom: 28px; line-height: 1.7;">
        This is a reminder that your credit payment is <?= $daysOverdue > 0 ? "<strong>{$daysOverdue} day(s) overdue</strong>" : "<strong>due today</strong>" ?>.
        You used <strong><?= number_format($used_credits) ?> credits</strong> on <strong><?= date('d M Y', strtotime($first_usage_date)) ?></strong>,
        and your agreed payment window was <strong><?= $payment_days ?> days</strong>.
        Please settle the outstanding amount at your earliest convenience.
    </div>

    <!-- Summary Card -->
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: <?= $brandColor ?>; color: #fff; padding: 14px 20px;">
            <div style="font-size: 15px; font-weight: 600;">Payment Summary</div>
        </div>
        <div style="padding: 20px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #718096; font-size: 13px; width: 45%;">Credits Allocated</td>
                    <td style="padding: 10px 0; color: #2d3748; font-weight: 600; font-size: 13px;"><?= number_format($credit_limit) ?> Credits</td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #718096; font-size: 13px;">Credits Used</td>
                    <td style="padding: 10px 0; color: #e53e3e; font-weight: 600; font-size: 13px;"><?= number_format($used_credits) ?> Credits</td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #718096; font-size: 13px;">First Usage Date</td>
                    <td style="padding: 10px 0; color: #2d3748; font-size: 13px;"><?= date('d M Y, h:i A', strtotime($first_usage_date)) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px 0; color: #718096; font-size: 13px;">Payment Deadline</td>
                    <td style="padding: 10px 0; color: <?= $daysOverdue > 0 ? '#e53e3e' : '#d97706' ?>; font-weight: 600; font-size: 13px;"><?= date('d M Y', strtotime($due_date)) ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px 0; color: #718096; font-size: 14px; font-weight: 600;">Amount Due</td>
                    <td style="padding: 12px 0; color: <?= $brandColor ?>; font-weight: 800; font-size: 16px;"><?= $currency ?> <?= number_format($amount_due, 2) ?></td>
                </tr>
            </table>
        </div>
        <?php if ($daysOverdue > 0): ?>
        <div style="background: #fef2f2; padding: 12px 20px; border-top: 1px solid #fecaca;">
            <div style="font-size: 12px; color: #991b1b; text-align: center; font-weight: 600;">
                ⚠️ This payment is <strong><?= $daysOverdue ?> day(s) past its due date</strong>. Please pay immediately.
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- CTA -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= root ?>dashboard" style="display: inline-block; background: <?= $brandColor ?>; color: #ffffff; padding: 14px 40px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 700;">
            Contact Us to Pay Now
        </a>
    </div>

    <div style="font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.6; border-top: 1px solid #e2e8f0; padding-top: 20px;">
        If you have already made this payment, please ignore this reminder or contact our support team with your payment reference.
    </div>
</div>
