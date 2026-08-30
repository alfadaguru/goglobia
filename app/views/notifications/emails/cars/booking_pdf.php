<?php
/**
 * ============================================================================
 * BOOKING VOUCHER PDF - Car Rental Receipt
 * ============================================================================
 * Professional booking receipt for Car Rentals
 * Sleek, lightweight, compact design
 * ============================================================================
 */

if (!isset($SECURE)) die('Direct access not permitted');

// Prepare Car Data
$bookingData = json_decode($booking['booking_data'] ?? '{}', true);
$carData = $bookingData['car_data'] ?? [];
$searchParams = $bookingData['search_params'] ?? [];

$carName = $carData['name'] ?? $bookingData['car_name'] ?? 'Car Rental';
$pickupLocation = $searchParams['pickup_location'] ?? $bookingData['pickup_location'] ?? 'N/A';
$dropoffLocation = $searchParams['dropoff_location'] ?? $bookingData['dropoff_location'] ?? 'N/A';
$pickupDate = $searchParams['date'] ?? $bookingData['pickup_date'] ?? '';
$dropoffDate = $searchParams['return_date'] ?? $bookingData['dropoff_date'] ?? '';

$formattedPickup = !empty($pickupDate) ? date('D, M d, Y \a\t H:i', strtotime($pickupDate)) : 'N/A';
$formattedDropoff = !empty($dropoffDate) ? date('D, M d, Y \a\t H:i', strtotime($dropoffDate)) : 'N/A';

// Calculate Duration
$pickupTs = strtotime($pickupDate);
$dropoffTs = strtotime($dropoffDate);
$days = ($pickupTs && $dropoffTs) ? ceil(($dropoffTs - $pickupTs) / 86400) : 1;
if ($days < 1) $days = 1;
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

        /* Car Card */
        .car-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .car-header { background: #374151; color: #fff; padding: 8px 12px; }
        .car-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .car-type { font-size: 7pt; opacity: 0.9; }

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

        /* Guest Details */
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .guest-info { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .guest-row { margin-bottom: 3px; font-size: 7pt; }
        .guest-label { color: #718096; display: inline-block; width: 60px; }
        .guest-value { color: #2d3748; font-weight: normal; }

        /* Alert Boxes */
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .info-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .info-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .info-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

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

    <!-- Car Information Card -->
    <div class="car-card">
        <!-- Car Header -->
        <div class="car-header">
            <div class="car-name"><?= htmlspecialchars($carName) ?></div>
            <div class="car-type">Rental Vehicle</div>
        </div>

        <!-- Dates & Duration -->
        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Pick-up</div>
                    <div class="dates-value"><?= $formattedPickup ?></div>
                    <div class="dates-time"><?= htmlspecialchars($pickupLocation) ?></div>
                </td>
                <td>
                    <div class="dates-label">Drop-off</div>
                    <div class="dates-value"><?= $formattedDropoff ?></div>
                    <div class="dates-time"><?= htmlspecialchars($dropoffLocation) ?></div>
                </td>
            </tr>
        </table>

        <!-- Rental Info -->
        <div class="stay-info">
            <strong><?= $days ?> Day<?= $days > 1 ? 's' : '' ?></strong> Rental Duration
        </div>
    </div>

    <!-- Driver Information -->
    <div class="section-title">Driver Information</div>
    <div class="guest-info">
        <div class="guest-row">
            <span class="guest-label">Name:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Email:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="guest-row">
            <span class="guest-label">Phone:</span>
            <span class="guest-value"><?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span>
        </div>
    </div>

    <!-- Pricing Summary -->
    <div class="section-title">Price Breakdown</div>
    <div class="price-box">
        <div class="price-row">
            <table>
                <tr>
                    <td class="price-label">Rental Charges</td>
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format($booking['price_original'], 2) ?></td>
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
                    <td class="price-value"><?= $booking['currency_markup'] ?> <?= number_format($booking['price_markup'], 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Payment Status -->
    <?php if ($booking['payment_status'] === 'unpaid'): ?>
    <div class="alert-box">
        <div class="alert-title">⚠ Payment Required</div>
        <div class="alert-text">
            Please complete your payment using <strong><?= htmlspecialchars($booking['payment_gateway']) ?></strong> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Special Requests -->
    <?php if (!empty($booking['special_requests'])): ?>
    <div class="info-box">
        <div class="info-title">Special Requests</div>
        <div class="info-text"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Cancellation Policy -->
    <?php if (!empty($carData['cancellation_policy'])): ?>
    <div class="section-title">Cancellation Policy</div>
    <div class="info-box">
        <div class="info-text"><?= nl2br(htmlspecialchars($carData['cancellation_policy'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Important Information</div>
        <ul>
            <li>Valid driver's license and credit card required at pickup</li>
            <li>Main driver must be present at pickup</li>
            <li>Check vehicle for existing damage before leaving the lot</li>
            <li>Return vehicle with the same fuel level as pickup</li>
        </ul>
    </div>

    <!-- QR Code -->
    <!-- <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/cars/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div> -->

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
