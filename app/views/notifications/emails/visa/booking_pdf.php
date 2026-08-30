<?php
/**
 * ============================================================================
 * BOOKING VOUCHER PDF - Visa Inquiry
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; font-weight: normal; }

        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        .status-badge { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 8px 12px; margin-bottom: 10px; }
        .status-badge-title { font-size: 9pt; font-weight: bold; color: #1e40af; margin-bottom: 1px; }
        .status-badge-text { font-size: 7pt; color: #1e3a8a; }

        .visa-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .visa-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .visa-title { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .visa-subtitle { font-size: 7pt; opacity: 0.9; }

        table { width: 100%; border-collapse: collapse; }
        .details-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .details-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .details-value { font-size: 9pt; font-weight: normal; color: #2d3748; }

        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; margin-top: 10px; }
        .info-box { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; border: 1px solid #e2e8f0; }
        .info-row { margin-bottom: 3px; font-size: 7pt; }
        .info-label { color: #718096; display: inline-block; width: 80px; }
        .info-value { color: #2d3748; font-weight: normal; }

        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; background: #f8fafc; }
        .price-row td { padding: 2px 0; font-size: 7pt; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 10pt; font-weight: bold; color: #2d3748; }

        .footer { background: #f7fafc; padding: 8px 10px; margin-top: 20px; border-top: 1px solid #cbd5e0; text-align: center; }
        .footer-contact { font-size: 7pt; color: #4a5568; margin-bottom: 5px; }
        .footer-note { font-size: 6pt; color: #718096; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 33%;">
                    <img src="<?= uploads ?>global/logo.png" alt="Logo" class="logo-img">
                </td>
                <td style="width: 34%;" class="header-contact">
                    <div><?= htmlspecialchars($businessEmail) ?></div>
                    <div><?= htmlspecialchars($businessPhone) ?></div>
                </td>
                <td style="width: 33%;" class="header-ref">
                    <div class="ref-label">Inquiry Reference</div>
                    <div class="ref-number"><?= $invoiceId ?></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="status-badge">
        <div class="status-badge-title">INQUIRY RECEIVED</div>
        <div class="status-badge-text">Your visa inquiry has been received and is currently under review.</div>
    </div>

    <div class="visa-card">
        <div class="visa-header">
            <div class="visa-title"><?= htmlspecialchars($bookingData['visa_type_name'] ?? 'Visa Application') ?></div>
            <div class="visa-subtitle"><?= htmlspecialchars($bookingData['from_country_name']) ?> → <?= htmlspecialchars($bookingData['to_country_name']) ?></div>
        </div>
        <table class="details-table">
            <tr>
                <td>
                    <div class="details-label">Intended Entry Date</div>
                    <div class="details-value"><?= !empty($bookingData['entry_date']) ? date('D, M d, Y', strtotime($bookingData['entry_date'])) : 'To be decided' ?></div>
                </td>
                <td>
                    <div class="details-label">Processing Speed</div>
                    <div class="details-value"><?= htmlspecialchars($bookingData['processing_speed_name'] ?? 'Standard') ?></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Main Applicant</div>
    <div class="info-box">
        <div class="info-row"><span class="info-label">Name:</span> <span class="info-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Email:</span> <span class="info-value"><?= htmlspecialchars($booking['email']) ?></span></div>
        <div class="info-row"><span class="info-label">Phone:</span> <span class="info-value"><?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span></div>
    </div>

    <?php if (!empty($travellersData)): ?>
    <div class="section-title">Applicants Information</div>
    <div class="info-box" style="padding: 0; background: transparent; border: none;">
        <?php foreach ($travellersData as $index => $traveler): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px; margin-bottom: 8px;">
            <div style="font-weight: bold; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 5px; color: #2d3748;">
                Applicant <?= $index + 1 ?>: <?= htmlspecialchars(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? '')) ?>
            </div>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%; padding-bottom: 4px;">
                        <span style="color: #718096; font-size: 7pt; display: block;">Nationality:</span>
                        <span style="color: #2d3748;"><?= htmlspecialchars($traveler['nationality'] ?? $traveler['nationality_name'] ?? '-') ?></span>
                    </td>
                    <td style="width: 50%; padding-bottom: 4px;">
                         <span style="color: #718096; font-size: 7pt; display: block;">Date of Birth:</span>
                         <span style="color: #2d3748;"><?= !empty($traveler['dob']) ? htmlspecialchars($traveler['dob']) : '-' ?></span>
                    </td>
                </tr>
                <tr>
                    <td style="width: 50%;">
                        <span style="color: #718096; font-size: 7pt; display: block;">Passport Number:</span>
                        <span style="color: #2d3748;"><?= htmlspecialchars($traveler['passport_number'] ?? '-') ?></span>
                    </td>
                    <td style="width: 50%;">
                         <span style="color: #718096; font-size: 7pt; display: block;">Passport Expiry:</span>
                         <span style="color: #2d3748;"><?= htmlspecialchars($traveler['passport_expiry'] ?? $traveler['passport_expiry_date'] ?? '-') ?></span>
                    </td>
                </tr>
                <?php if (!empty($traveler['gender'])): ?>
                <tr>
                    <td colspan="2" style="padding-top: 4px;">
                        <span style="color: #718096; font-size: 7pt; display: block;">Gender:</span>
                        <span style="color: #2d3748;"><?= htmlspecialchars(ucfirst($traveler['gender'])) ?></span>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($totalAmount > 0): ?>
    <div class="section-title">Inquiry Summary</div>
    <div class="price-box">
        <table>
            <tr>
                <td>Price Per Person</td>
                <td style="text-align: right;"><?= $currency ?> <?= number_format($bookingData['price_per_traveler'] ?? $bookingData['price_per_person'] ?? $bookingData['price'] ?? 0, 2) ?></td>
            </tr>
            <tr>
                <td>Total Applicants</td>
                <td style="text-align: right;">× <?= $bookingData['travelers_count'] ?? count($bookingData['travelers'] ?? []) ?></td>
            </tr>

            <tr class="price-total">
                <td><strong>Total Amount</strong></td>
                <td style="text-align: right;"><strong><?= $currency ?> <?= $totalAmount ?></strong></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($bookingData['special_requests']) || !empty($booking['special_requests'])): ?>
    <div class="section-title">Special Requests</div>
    <div class="info-box" style="font-size: 7pt;">
        <?= nl2br(htmlspecialchars($bookingData['special_requests'] ?: $booking['special_requests'])) ?>
    </div>
    <?php endif; ?>
    
    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div>

    <div class="footer">
        <div class="footer-contact">Questions? Contact us at <?= htmlspecialchars($businessEmail) ?></div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= htmlspecialchars($businessName) ?>. All rights reserved.<br>
            Reference: <?= $invoiceId ?> | Date: <?= $bookingDate ?>
        </div>
    </div>
</body>
</html>
