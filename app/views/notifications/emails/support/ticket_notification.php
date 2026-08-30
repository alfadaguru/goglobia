<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>

<?php
/**
 * UNIVERSAL TICKET NOTIFICATION EMAIL TEMPLATE
 * 
 * Used for all ticket-related notifications:
 * - Admin creates ticket and assigns to user
 * - Admin replies to ticket
 * - User creates new ticket
 * - User replies to ticket
 * 
 * Variables:
 * - $actionType: 'new_ticket' or 'new_reply'
 * - $ticketId: Ticket reference number
 * - $ticketSubject: Subject line of the ticket
 * - $senderName: Name of person who created/replied
 * - $senderRole: 'Admin' or 'Customer'
 * - $messagePreview: Preview of the message content (first 200 chars)
 * - $ticketUrl: Direct link to view ticket
 * - $receiverName: Name of the recipient
 */

$isNewTicket = ($actionType === 'new_ticket');
// Use consistent branding colors for all emails
$headerColor = '#3b82f6'; // Always Blue
$headerIcon = $isNewTicket ? '🎫' : '💬';
$headerText = $isNewTicket ? 'NEW SUPPORT TICKET' : 'NEW TICKET REPLY';
?>

<!-- All notifications use the same blue styling -->
<div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #1e3a8a; margin-bottom: 4px;"><?= $headerIcon ?> <?= $headerText ?></div>
    <?php if ($isNewTicket): ?>
    <div style="font-size: 13px; color: #1e40af;">A new support ticket has been <?= $senderRole === 'Admin' ? 'assigned to you' : 'created' ?>.</div>
    <?php else: ?>
    <div style="font-size: 13px; color: #1e40af;">You have received a new reply on your support ticket.</div>
    <?php endif; ?>
</div>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars($receiverName) ?></strong>,
    </div>
    
    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?php if ($isNewTicket): ?>
            <?php if ($senderRole === 'Admin'): ?>
                A support ticket has been created and assigned to you by <strong><?= htmlspecialchars($senderName) ?></strong>.
            <?php else: ?>
                A new support ticket <strong>#<?= htmlspecialchars($ticketId) ?></strong> has been created by <strong><?= htmlspecialchars($senderName) ?></strong>.
            <?php endif; ?>
        <?php else: ?>
            <strong><?= htmlspecialchars($senderName) ?></strong> (<?= htmlspecialchars($senderRole) ?>) has replied to ticket <strong>#<?= htmlspecialchars($ticketId) ?></strong>.
        <?php endif; ?>
    </div>

    <!-- Ticket Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: <?= $headerColor ?>; color: #ffffff; padding: 12px 16px;">
            <div style="font-size: 15px; font-weight: 600;">Ticket #<?= htmlspecialchars($ticketId) ?></div>
            <div style="font-size: 12px; opacity: 0.9;"><?= htmlspecialchars($ticketSubject) ?></div>
        </div>
        <div style="padding: 16px;">
            <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;">
                <?= $isNewTicket ? 'Description' : 'Reply Preview' ?>
            </div>
            <div style="font-size: 14px; color: #2d3748; line-height: 1.6; padding: 12px; background: #f8fafc; border-radius: 6px; border-left: 3px solid <?= $headerColor ?>;">
                <?= nl2br(htmlspecialchars($messagePreview)) ?>
                <?php if (strlen($messagePreview) >= 200): ?>
                    <span style="color: #718096;">...</span>
                <?php endif; ?>
            </div>
        </div>
        <table style="width: 100%; border-collapse: collapse; border-top: 1px solid #e2e8f0;">
            <tr>
                <td style="padding: 12px 16px; border-right: 1px solid #e2e8f0; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">From</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= htmlspecialchars($senderName) ?></div>
                    <div style="font-size: 12px; color: #718096;"><?= htmlspecialchars($senderRole) ?></div>
                </td>
                <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Date</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= date('D, M d, Y') ?></div>
                    <div style="font-size: 12px; color: #718096;"><?= date('h:i A') ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= $ticketUrl ?>" style="display: inline-block; background: <?= $headerColor ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Ticket
        </a>
    </div>

    <!-- Next Steps Box -->
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: #1e3a8a; line-height: 1.6;">
            <strong>Next Steps:</strong><br>
            <?php if ($isNewTicket): ?>
                Click the "View Ticket" button above to see the full details and respond to this ticket. Your prompt response will help us assist you better.
            <?php else: ?>
                Click the "View Ticket" button above to read the full reply and continue the conversation. We're here to help resolve your issue as quickly as possible.
            <?php endif; ?>
        </div>
    </div>
</div>
