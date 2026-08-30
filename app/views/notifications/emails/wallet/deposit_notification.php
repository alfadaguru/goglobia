<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>

<?php
/**
 * UNIVERSAL DEPOSIT NOTIFICATION EMAIL TEMPLATE
 * 
 * Used for:
 * 1. Admin Notification: New Deposit Request
 * 2. User Notification: Deposit Approved
 * 3. User Notification: Deposit Rejected
 * 
 * Variables:
 * - $actionType: 'new_request', 'approved', 'rejected'
 * - $amount: Deposit amount
 * - $currency: Currency code
 * - $depositId: Transaction ID (TXN...)
 * - $transactionId: External Transaction ID (Ref...)
 * - $receiverName: Name of the recipient
 * - $userName: Name of the depositor (for admin emails)
 * - $details: Payment details/notes
 * - $paymentMethod: Payment gateway/method name
 */

// Define header text and icon based on action
switch ($actionType) {
    case 'new_request':
        $headerIcon = '💰';
        $headerText = 'NEW DEPOSIT REQUEST';
        $statusColor = '#3b82f6'; // Blue
        $statusText = 'PENDING APPROVAL';
        break;
    case 'approved':
        $headerIcon = '✅';
        $headerText = 'DEPOSIT APPROVED';
        $statusColor = '#22c55e'; // Green
        $statusText = 'APPROVED';
        break;
    case 'rejected':
        $headerIcon = '❌';
        $headerText = 'DEPOSIT REJECTED';
        $statusColor = '#ef4444'; // Red
        $statusText = 'REJECTED';
        break;
    default:
        $headerIcon = '💳';
        $headerText = 'WALLET UPDATE';
        $statusColor = '#3b82f6';
        $statusText = 'UPDATED';
}

// Consistent Blue Branding for Main Elements
$brandColor = '#3b82f6'; 
?>

<!-- Header Section -->
<div style="background: #eff6ff; border-left: 4px solid <?= $brandColor ?>; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #1e3a8a; margin-bottom: 4px;">
        <?= $headerIcon ?> <?= $headerText ?>
    </div>
    <div style="font-size: 13px; color: #1e40af;">
        <?php if ($actionType === 'new_request'): ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                A new deposit request of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> from <strong><?= htmlspecialchars($userName) ?></strong> has been submitted.
            <?php else: ?>
                Your deposit request of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> has been received and is pending approval.
            <?php endif; ?>
        <?php elseif ($actionType === 'approved'): ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                Deposit request of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> for <strong><?= htmlspecialchars($userName) ?></strong> has been marked as approved.
            <?php else: ?>
                Your deposit of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> has been successfully added to your wallet.
            <?php endif; ?>
        <?php else: ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                Deposit request of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> for <strong><?= htmlspecialchars($userName) ?></strong> has been rejected.
            <?php else: ?>
                Your deposit request of <strong><?= $currency ?> <?= number_format($amount, 2) ?></strong> has been rejected.
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($receiverName) ?></strong>,
    </div>
    
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?php if ($actionType === 'new_request'): ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                <strong><?= htmlspecialchars($userName) ?></strong> has requested to deposit funds into their wallet. Please review the details below.
            <?php else: ?>
                We have received your deposit request. Our team will review the transaction details and update your wallet balance shortly.
            <?php endif; ?>
        <?php elseif ($actionType === 'approved'): ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                The wallet balance for <strong><?= htmlspecialchars($userName) ?></strong> has been updated successfully.
            <?php else: ?>
                Great news! Your funds are now available in your wallet and can be used for bookings immediately.
            <?php endif; ?>
        <?php else: ?>
            <?php if (($recipientRole ?? 'user') === 'admin'): ?>
                You have rejected the deposit request from <strong><?= htmlspecialchars($userName) ?></strong>.
            <?php else: ?>
                Unfortunately, your deposit request could not be processed at this time. Please see details or contact support for assistance.
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Transaction Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: <?= $brandColor ?>; color: #ffffff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 15px; font-weight: 600;"><?= htmlspecialchars($depositId) ?></div>
            <div style="font-size: 12px; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 4px;">
                <?= $currency ?> <?= number_format($amount, 2) ?>
            </div>
        </div>
        
        <div style="padding: 16px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #718096; font-size: 13px; width: 40%;">Status</td>
                    <td style="padding: 8px 0; color: <?= $statusColor ?>; font-weight: 600; font-size: 13px;">
                        <?= $statusText ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #718096; font-size: 13px;">Ref / Transaction ID</td>
                    <td style="padding: 8px 0; color: #2d3748; font-weight: 500; font-size: 13px;">
                        <?= htmlspecialchars($transactionId ?? 'N/A') ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #718096; font-size: 13px;">Payment Method</td>
                    <td style="padding: 8px 0; color: #2d3748; font-weight: 500; font-size: 13px;">
                        <?= htmlspecialchars($paymentMethod ?? 'Manual') ?>
                    </td>
                </tr>
                <?php if (!empty($details)): ?>
                <tr>
                    <td style="padding: 8px 0; color: #718096; font-size: 13px; vertical-align: top;">Notes</td>
                    <td style="padding: 8px 0; color: #2d3748; font-size: 13px; line-height: 1.5;">
                        <?= nl2br(htmlspecialchars($details)) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <div style="background: #f8fafc; padding: 12px 16px; border-top: 1px solid #e2e8f0; text-align: center;">
            <div style="font-size: 12px; color: #718096;">
                Date: <?= date('d M Y, h:i A') ?>
            </div>
        </div>
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <?php if ($actionType === 'new_request'): ?>
            <a href="<?= root ?>admin/finance/deposit?id=<?= $depositId ?>" style="display: inline-block; background: <?= $brandColor ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
                Review Request
            </a>
        <?php else: ?>
            <a href="<?= root ?>deposit" style="display: inline-block; background: <?= $brandColor ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
                View Wallet
            </a>
        <?php endif; ?>
    </div>
</div>
