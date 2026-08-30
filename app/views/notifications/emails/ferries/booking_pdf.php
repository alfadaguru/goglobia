<?php
if (!isset($SECURE)) die('Direct access not permitted');

require_once __DIR__ . '/../../../../../modules/ferries/kikoto/api.php';

// Prepare Ferries Data
$confirmed = $bookingData['confirmed'] ?? [];
$ferriesPorts = [];
$portsCache = __DIR__ . '/../../../../cache/kikoto_ports_en.json';
if (file_exists($portsCache)) {
    $ferriesPorts = json_decode(file_get_contents($portsCache), true) ?: [];
}
$portName = function(int $id) use ($ferriesPorts): string {
    foreach ($ferriesPorts as $p) { if ((int)($p['id'] ?? 0) === $id) return $p['name'] ?? ''; }
    return 'Port ' . $id;
};

if (!empty($confirmed['sailings'])) {
    $sailings = $confirmed['sailings'];
} else {
    $draft = $bookingData['draft']['selected_sailing'] ?? [];
    $sailings = !empty($draft) ? [$draft] : [];
    if (!empty($bookingData['draft']['return_sailing'])) $sailings[] = $bookingData['draft']['return_sailing'];
}

$locators  = $bookingData['locators'] ?? [];
$reference = $bookingData['reference'] ?? $booking['pnr'] ?? '';
$locator   = implode(', ', $locators) ?: ($booking['pnr'] ?? '');

$pdfVehicles = $bookingData['vehicles'] ?? $bookingData['draft']['vehicles'] ?? [];
$pdfPets     = $bookingData['pets']     ?? $bookingData['draft']['pets']     ?? [];
if (!is_array($pdfVehicles)) $pdfVehicles = [];
if (!is_array($pdfPets))     $pdfPets     = [];

$pdfBonusIds = _kikoto_normalize_bonus_ids(
    $bookingData['bonuses'] ?? $bookingData['draft']['bonuses'] ?? []
);
if (empty($pdfBonusIds) && !empty($bookingData['draft']) && is_array($bookingData['draft'])) {
    $pdfBonusIds = _kikoto_extract_bonuses_from_draft($bookingData['draft']);
}
$pdfBonusDetails = $bookingData['bonus_details'] ?? $bookingData['draft']['bonus_details'] ?? [];
$pdfBonuses = _kikoto_resolve_bonus_labels($pdfBonusIds, is_array($pdfBonusDetails) ? $pdfBonusDetails : []);
$pdfCoupon = trim((string)($bookingData['coupon'] ?? $bookingData['draft']['coupon'] ?? ''));
$pdfHasBonusOrCoupon = !empty($pdfBonuses) || $pdfCoupon !== '';

$ticketTypesById = [];
foreach (($bookingData['draft']['selected_sailing']['shipping_company']['ticket_types'] ?? []) as $tt) {
    if (is_array($tt) && isset($tt['id'])) {
        $ticketTypesById[(int)$tt['id']] = $tt;
    }
}
$ticketLabel = function (int $typeId) use ($ticketTypesById): string {
    if ($typeId <= 0) return '';
    $tt = $ticketTypesById[$typeId] ?? null;
    if (!$tt) return (string)$typeId;
    $name = trim((string)($tt['name'] ?? ''));
    $desc = trim((string)($tt['description'] ?? ''));
    return $desc !== '' ? ($name . ' — ' . $desc) : ($name !== '' ? $name : (string)$typeId);
};

$pdfTravellers = is_array($travellersData) && array_is_list($travellersData) ? $travellersData : [];
$pdfCategoryCounts = ['adult' => 0, 'child' => 0, 'infant' => 0];
$pdfPassengerHeading = function (array $t) use (&$pdfCategoryCounts, $ticketTypesById): string {
    return _kikoto_passenger_heading($t, $pdfCategoryCounts, $ticketTypesById);
};
$pdfLinkedPassengerName = function (int $passengerId) use ($pdfTravellers): string {
    return _kikoto_resolve_traveller_name($passengerId, $pdfTravellers);
};

$firstSailing = $sailings[0] ?? [];
$depPort = $portName((int)($firstSailing['departure_port_id'] ?? 0));
$arrPort = $portName((int)($firstSailing['arrival_port_id'] ?? $firstSailing['destination_port_id'] ?? 0));
$depDT   = $firstSailing['departure_datetime'] ?? '';
$formattedDate = $depDT ? date('D, M d, Y', strtotime($depDT)) : 'N/A';

$bookingDate = date('M d, Y', strtotime($booking['booking_date'] ?? $booking['created_at']));
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

        /* Sailing Segments */
        .segment { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
        .segment:last-child { border-bottom: none; }
        .segment-header { margin-bottom: 6px; }
        .airline-label { font-size: 8pt; color: #2d3748; font-weight: bold; }
        .flight-num { font-size: 7pt; color: #4a5568; margin-left: 5px; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }

        .flight-times td { width: 45%; padding: 0; border: none; vertical-align: top; }
        .arrow-col { width: 10%; text-align: center; padding-top: 10px; color: #cbd5e0; font-size: 12pt; }
        .time-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .time-value { font-size: 11pt; font-weight: bold; color: #2d3748; margin-bottom: 1px; }
        .apt-code { font-size: 8pt; color: #4a5568; margin-bottom: 1px; }

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
        .info-label { color: #718096; display: inline-block; width: 100px; }
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
                    <?php if (!empty($businessEmail)): ?>
                        <div><?= htmlspecialchars($businessEmail) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($businessPhone)): ?>
                        <div><?= htmlspecialchars($businessPhone) ?></div>
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
            Please complete your payment using <strong><?= htmlspecialchars($booking['payment_gateway'] ?? '') ?></strong> to confirm your reservation.
        </div>
    </div>
    <?php endif; ?>

    <!-- Ferry Information Card -->
    <div class="service-card">
        <div class="service-header">
            <div class="service-name"><?= htmlspecialchars($depPort) ?> → <?= htmlspecialchars($arrPort) ?></div>
            <div class="service-info">Ferry Booking<?= $locator ? ' · Locator: ' . htmlspecialchars($locator) : '' ?><?= $reference ? ' · Ref: ' . htmlspecialchars($reference) : '' ?></div>
        </div>

        <table class="dates-table">
            <tr>
                <td>
                    <div class="dates-label">Departure Date</div>
                    <div class="dates-value"><?= $formattedDate ?></div>
                </td>
                <td>
                    <div class="dates-label">Booking ID</div>
                    <div class="dates-value">#<?= $invoiceId ?></div>
                </td>
            </tr>
        </table>

        <!-- Sailing Segments -->
        <?php foreach ($sailings as $i => $s):
            $sDepDT  = $s['departure_datetime'] ?? '';
            $sArrDT  = $s['arrival_datetime']   ?? '';
            $sDepTime = $sDepDT ? date('H:i', strtotime($sDepDT)) : '';
            $sArrTime = $sArrDT ? date('H:i', strtotime($sArrDT)) : '';
            $sDepPort = $portName((int)($s['departure_port_id'] ?? 0));
            $sArrPort = $portName((int)($s['arrival_port_id'] ?? $s['destination_port_id'] ?? 0));
            $acc      = $s['accommodations'][0] ?? [];
            $sl       = $locators[$i] ?? '';
        ?>
        <div class="segment">
            <div class="segment-header">
                <span class="airline-label"><?= $i === 0 ? 'Outbound' : 'Return' ?> Sailing</span>
                <?php if ($sl): ?><span class="flight-num">| Locator: <?= htmlspecialchars($sl) ?></span><?php endif; ?>
                <?php if (!empty($acc['title'])): ?><span class="flight-num">| <?= htmlspecialchars($acc['title']) ?></span><?php endif; ?>
            </div>

            <table class="flight-times">
                <tr>
                    <td>
                        <div class="time-label">Departure</div>
                        <div class="time-value"><?= htmlspecialchars($sDepTime) ?></div>
                        <div class="apt-code"><?= htmlspecialchars($sDepPort) ?></div>
                    </td>
                    <td class="arrow-col">→</td>
                    <td style="text-align: right;">
                        <div class="time-label">Arrival</div>
                        <div class="time-value"><?= htmlspecialchars($sArrTime) ?></div>
                        <div class="apt-code"><?= htmlspecialchars($sArrPort) ?></div>
                    </td>
                </tr>
            </table>
        </div>
        <?php endforeach; ?>

        <div class="stay-info">
            <strong><?= ($booking['adults'] ?? 1) ?> Adult<?= ($booking['adults'] ?? 1) > 1 ? 's' : '' ?></strong>
            <?php if (($booking['childs'] ?? 0) > 0): ?> • <strong><?= $booking['childs'] ?> Child<?= $booking['childs'] > 1 ? 'ren' : '' ?></strong><?php endif; ?>
            <?php if (count($pdfVehicles) > 0): ?> • <strong><?= count($pdfVehicles) ?> Vehicle<?= count($pdfVehicles) > 1 ? 's' : '' ?></strong><?php endif; ?>
            <?php if (count($pdfPets) > 0): ?> • <strong><?= count($pdfPets) ?> Pet<?= count($pdfPets) > 1 ? 's' : '' ?></strong><?php endif; ?>
            <?php if (count($pdfBonuses) > 0): ?> • <strong><?= count($pdfBonuses) ?> Bonus<?= count($pdfBonuses) > 1 ? 'es' : '' ?></strong><?php endif; ?>
            <?php if ($pdfCoupon !== ''): ?> • <strong>Coupon</strong><?php endif; ?>
        </div>
    </div>

    <!-- Passenger Details -->
    <div class="section-title">Passenger Details</div>
    <div class="info-block">
        <?php
        foreach ($pdfTravellers as $p):
            $pName = _kikoto_passenger_full_name($p);
            $pHeading = $pdfPassengerHeading($p);
        ?>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars($pHeading) ?>:</span>
            <span class="info-value"><?= htmlspecialchars($pName) ?></span>
        </div>
        <?php if (!empty($p['nationality'])): ?>
        <div class="info-row">
            <span class="info-label">Nationality:</span>
            <span class="info-value"><?= htmlspecialchars($p['nationality']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($p['identity_number'])): ?>
        <div class="info-row">
            <span class="info-label"><?= htmlspecialchars(ucfirst($p['identity_type'] ?? 'Passport')) ?>:</span>
            <span class="info-value"><?= htmlspecialchars($p['identity_number']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($p['birthdate'])): ?>
        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">Date of Birth:</span>
            <span class="info-value"><?= htmlspecialchars($p['birthdate']) ?></span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($pdfVehicles)): ?>
    <div class="section-title">Vehicles</div>
    <div class="info-block">
        <?php foreach ($pdfVehicles as $vi => $v): ?>
        <div class="info-row">
            <span class="info-label">Vehicle <?= $vi + 1 ?>:</span>
            <span class="info-value"><?= htmlspecialchars($ticketLabel((int)($v['ticket_type_id'] ?? 0))) ?></span>
        </div>
        <?php if (!empty($v['license_plate'])): ?>
        <div class="info-row">
            <span class="info-label">License plate:</span>
            <span class="info-value"><?= htmlspecialchars((string)$v['license_plate']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($v['brand'])): ?>
        <div class="info-row">
            <span class="info-label">Brand:</span>
            <span class="info-value"><?= htmlspecialchars((string)$v['brand']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($v['passenger_id'])): ?>
        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">Driver:</span>
            <span class="info-value"><?= htmlspecialchars($pdfLinkedPassengerName((int)$v['passenger_id']) ?: ('Passenger #' . (int)$v['passenger_id'])) ?></span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pdfPets)): ?>
    <div class="section-title">Pets</div>
    <div class="info-block">
        <?php foreach ($pdfPets as $pi => $pet): ?>
        <div class="info-row">
            <span class="info-label">Pet <?= $pi + 1 ?>:</span>
            <span class="info-value"><?= htmlspecialchars($ticketLabel((int)($pet['ticket_type_id'] ?? 0))) ?></span>
        </div>
        <?php if (!empty($pet['name'])): ?>
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value"><?= htmlspecialchars((string)$pet['name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($pet['passenger_id'])): ?>
        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">Owner:</span>
            <span class="info-value"><?= htmlspecialchars($pdfLinkedPassengerName((int)$pet['passenger_id']) ?: ('Passenger #' . (int)$pet['passenger_id'])) ?></span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($pdfHasBonusOrCoupon): ?>
    <div class="section-title">Bonus / Discount</div>
    <div class="info-block">
        <?php foreach ($pdfBonuses as $bonus): ?>
        <div class="info-row">
            <span class="info-label">Bonus:</span>
            <span class="info-value"><?= htmlspecialchars((string)($bonus['name'] ?? ('#' . (int)($bonus['id'] ?? 0)))) ?></span>
        </div>
        <?php if (!empty($bonus['type'])): ?>
        <div class="info-row">
            <span class="info-label">Type:</span>
            <span class="info-value"><?= htmlspecialchars(ucfirst((string)$bonus['type'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($bonus['description'])): ?>
        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">Notes:</span>
            <span class="info-value"><?= htmlspecialchars((string)$bonus['description']) ?></span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($pdfCoupon !== ''): ?>
        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">Coupon:</span>
            <span class="info-value"><?= htmlspecialchars($pdfCoupon) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Contact Information -->
    <div class="section-title">Contact Information</div>
    <div class="info-block">
        <div class="info-row">
            <span class="info-label">Primary Guest:</span>
            <span class="info-value"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Email:</span>
            <span class="info-value"><?= htmlspecialchars($booking['email']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Phone:</span>
            <span class="info-value">+<?= htmlspecialchars($booking['phone_country_code']) ?> <?= htmlspecialchars($booking['phone']) ?></span>
        </div>
    </div>

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
                    <td class="price-value"><?= $currency ?> <?= number_format((float)($booking['tax'] ?? 0), 2) ?></td>
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
        <div class="price-row" style="margin-top: 6px;">
            <table>
                <tr>
                    <td class="price-label">Payment Method</td>
                    <td class="price-value"><?= htmlspecialchars($booking['payment_gateway'] ?: 'N/A') ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Important Information -->
    <div class="important-info">
        <div class="section-title" style="border: none; margin-bottom: 5px;">Important Information</div>
        <ul>
            <li>Please arrive at the port at least 1 hour before departure</li>
            <li>Valid photo identification is required for all passengers</li>
            <li>This booking confirmation must be presented at check-in</li>
            <li>For sailing status updates, contact the ferry operator directly</li>
        </ul>
    </div>

    <!-- QR Code -->
    <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0 0 8px 0; font-size: 8.5pt; color: #718096; font-weight: 600;">Scan to View Invoice Online</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(root . 'invoice/ferries/' . $invoiceId) ?>" alt="Invoice QR Code" style="width: 120px; height: 120px;" />
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-contact">
            Questions? Contact us at <?= htmlspecialchars($businessEmail) ?>
            <?php if (!empty($businessPhone)): ?> | <?= htmlspecialchars($businessPhone) ?><?php endif; ?>
        </div>
        <div class="footer-note">
            © <?= date('Y') ?> <?= getBrandName($settings ?? null) ?>. All rights reserved.<br>
            Booking Date: <?= date('M d, Y \a\t g:i A', strtotime($booking['booking_date'] ?? $booking['created_at'])) ?><br>
            This is an automated booking voucher. Please do not reply to this document.
        </div>
    </div>
</body>
</html>
