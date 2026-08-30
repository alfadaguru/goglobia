<?php
/**
 * ============================================================================
 * BOOKING VOUCHER PDF - Tour Receipt
 * ============================================================================
 * Professional booking receipt for Tours
 * Standardized design across all modules
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');

// Prepare Tour Data
$tourData = json_decode($booking['booking_data'] ?? '{}', true);
$tourName = $tourData['tour_name'] ?? $tourData['tour']['name'] ?? 'Tour Booking';
$location = $tourData['location'] ?? $tourData['tour']['location'] ?? '';
$startDate = $tourData['start_date'] ?? $tourData['date'] ?? '';
$formattedDate = !empty($startDate) ? date('D, M d, Y', strtotime($startDate)) : 'N/A';
$duration = $tourData['duration'] ?? '1 Day';
$adults = $booking['adults'] ?? $tourData['adults'] ?? 1;
$children = $booking['childs'] ?? $tourData['children'] ?? 0;
$infants = $booking['infants'] ?? $tourData['infants'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; font-weight: normal; }

        /* Header */
        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-contact div { margin-bottom: 2px; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        /* Status Badge */
        .status-badge { background: #f0fdf4; border-left: 3px solid #10b981; padding: 8px 12px; margin-bottom: 10px; }
        .status-badge-title { font-size: 9pt; font-weight: bold; color: #065f46; margin-bottom: 1px; }
        .status-badge-text { font-size: 7pt; color: #047857; }

        /* Card */
        .service-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .service-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .service-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .service-info { font-size: 7pt; opacity: 0.9; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }
        .dates-time { font-size: 7pt; color: #4a5568; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

        /* Price Summary */
        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
        .price-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 6px; }
        .price-row { margin-bottom: 4px; }
        .price-row table { border: none; }
        .price-row td { padding: 0; border: none; font-size: 7pt; }
        .price-label { color: #4a5568; }
        .price-value { text-align: right; color: #2d3748; font-weight: normal; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 9pt; font-weight: bold; }
        .price-total .price-value { font-size: 11pt; color: #2d3748; font-weight: bold; }

        /* Details */
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .info-block { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .info-row { margin-bottom: 3px; font-size: 7pt; }
        .info-label { color: #718096; display: inline-block; width: 80px; }
        .info-value { color: #2d3748; font-weight: normal; }

        /* Alert Boxes */
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .note-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .note-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .note-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

        /* Important Info */
        .important-info { background: #f7fafc; padding: 8px 10px; margin-top: 10px; }
        .important-info ul { margin: 4px 0 0 12px; padding: 0; }
        .important-info li { font-size: 6pt; color: #4a5568; margin-bottom: 2px; line-height: 1.3; }

        /* Footer */
        .footer { background: #f7fafc; padding: 8px 10px; margin-top: 10px; border-top: 1px solid #cbd5e0; text-align: center; }
        .footer-contact { font-size: 7pt; color: #4a5568; margin-bottom: 5px; }
        .footer-note { font-size: 6pt; color: #718096; line-height: 1.3; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <table>
            <tr>
                <td style="width: 33%;">
                    <img src="<?= uploads ?>global/logo.png" alt="<?= getBrandName($settings ?? null) ?>" class="logo-img">
                </td>
                <td style="width: 34%;" class="header-contact">
                    <?php if (!empty($settings['contact_email'])): ?>
                        <div><?= htmlspecialchars($settings['contact_email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['contact_phone'])): ?>
                        <div><?= htmlspecialchars($settings['contact_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="width: 33%;" class="header-ref">
                    <div class="ref-label">Booking Reference</div>
                    <div class="ref-number"><?= $invoiceId ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Payment Status (Unpaid Alert) -->
    <?php if ($booking['payment_status'] === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ Payment Required</div>
        <div class="alert-text">
            Please complete your payment using <strong><?= htmlspecialchars($booking['payment_gateway']) ?></strong> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Tour Information Card -->
    <div class="service-card">
        <div class="service-header">
            <div class="service-name"><?= htmlspecialchars($tourName) ?></div>
            <div class="service-info"><?= htmlspecialchars($location) ?></div>
        </div>

        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Start Date</div>
                    <div class="dates-value"><?= $formattedDate ?></div>
                </td>
                <td>
                    <div class="dates-label">Duration</div>
                    <div class="dates-value"><?= htmlspecialchars($duration) ?></div>
                </td>
            </tr>
        </table>

        <div class="stay-info">
            <strong><?= $adults ?> Adult<?= $adults > 1 ? 's' : '' ?></strong>
            <?php if ($children > 0): ?> • <strong><?= $children ?> Child<?= $children > 1 ? 'ren' : '' ?></strong><?php endif; ?>
            <?php if ($infants > 0): ?> • <strong><?= $infants ?> Infant<?= $infants > 1 ? 's' : '' ?></strong><?php endif; ?>
        </div>
    </div>

    <!-- Lead Guest Information -->
    <div class="section-title">Lead Guest Information</div>
    <div class="info-block">
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Email:</span>
            <span class="info-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Phone:</span>
            <span class="info-value"><?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span>
        </div>
        <?php if (!empty($bookingData['pickup_location'])): ?>
        <div class="info-row">
            <span class="info-label">Pickup:</span>
            <span class="info-value"><?= htmlspecialchars($bookingData['pickup_location']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Traveler List -->
    <?php if (!empty($tourData['guest'])): ?>
    <div class="section-title">Traveler List</div>
    <div class="info-block">
        <?php foreach ($tourData['guest'] as $i => $traveler): ?>
        <div class="info-row">
            <span class="info-label">Guest <?= $i + 1 ?>:</span>
            <span class="info-value">
                <?= htmlspecialchars(($traveler['name'] ?? 'Guest')) ?>
                <?php if (!empty($traveler['age'])): ?> (Age: <?= $traveler['age'] ?>)<?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Price Summary -->
    <div class="section-title">Price Summary</div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Subtotal</td>
                    <td class="price-value"><?= $currency ?> <?= $subtotalAmount ?></td>
                </tr>
            </table>
        </div>

        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Taxes & Fees</td>
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format($booking['tax'], 2) ?></td>
                </tr>
            </table>
        </div>

        <div class="price-total">
            <table>
                <tr>
                    <td class="price-label"><strong>Total Amount</strong></td>
                    <td class="price-value"><?= $currency ?> <?= $totalAmount ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="note-box">
        <div class="note-title">Special Requests</div>
        <div class="note-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Important Information</div>
        <ul>
            <li>Please arrive at the meeting point 15 minutes before departure</li>
            <li>Present this voucher (digital or printed) to the tour guide</li>
            <li>Tours operate rain or shine unless otherwise notified</li>
            <li>Comfortable walking shoes are recommended</li>
            <li>For any emergency during the tour, contact our support line</li>
        </ul>
    </div>

    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/tours/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-contact">
            Questions? Contact us at <?= $settings['email_sender_email'] ?? 'support@phptravels.com' ?>
            <?php if (!empty($settings['site_phone'])): ?> | <?= $settings['site_phone'] ?><?php endif; ?>
        </div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= getBrandName($settings ?? null) ?>. All rights reserved.<br>
            Booking Date: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'])) ?><br>
            This is an automated booking voucher. Please do not reply to this document.
        </div>
    </div>
</body>
</html>