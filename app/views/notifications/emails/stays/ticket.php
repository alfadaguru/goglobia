<?php
/**
 * STAYS TICKET — Hotel Voucher (NO PRICE)
 * Premium accommodation voucher. Uses the shared ticket base for chrome;
 * builds the hotel / stay body here. Shows the HOTEL identity (never the
 * supplier/channel). Vars from GENERATE_BOOKING_PDF.
 */
if (!isset($SECURE)) die('Direct access not permitted');
require_once __DIR__ . '/../_ticket_base.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoiceId = (string) ($booking['invoice_id'] ?? '');

// Supplier confirmation ref: pnr, else a reference in booking_response.
$confRef = trim((string) ($booking['pnr'] ?? ''));
if ($confRef === '' && !empty($booking['booking_response'])) {
    $br = json_decode((string) $booking['booking_response'], true);
    if (is_array($br)) {
        $confRef = trim((string) ($br['confirmation_number'] ?? $br['reference'] ?? $br['booking_reference'] ?? $br['supplierReference'] ?? ''));
    }
}
$isConfirmed = $confRef !== '' || strtolower((string) ($booking['booking_status'] ?? '')) === 'confirmed';

// Hotel identity (the HOTEL, not the channel/supplier).
$hotelName = (string) ($bookingData['hotel_name'] ?? 'Your Hotel');
$stars = (int) ($bookingData['hotel_stars'] ?? 0);
$hotelAddress = trim((string) ($bookingData['hotel_address'] ?? ''));
if ($hotelAddress === '') {
    $hotelAddress = trim(implode(', ', array_filter([
        $bookingData['hotel_street'] ?? ($bookingData['address'] ?? ''),
        $bookingData['hotel_postal_code'] ?? '',
        $bookingData['hotel_city'] ?? ($bookingData['city'] ?? ''),
        $bookingData['hotel_country'] ?? ($bookingData['country'] ?? ''),
    ], static function ($x) { return trim((string) $x) !== ''; })));
}

$checkinRaw = (string) ($bookingData['checkin'] ?? '');
$checkoutRaw = (string) ($bookingData['checkout'] ?? '');
$checkin = $checkinRaw !== '' ? date('D, d M Y', strtotime($checkinRaw)) : '';
$checkout = $checkoutRaw !== '' ? date('D, d M Y', strtotime($checkoutRaw)) : '';
$nights = (int) ($bookingData['nights'] ?? 0);
if ($nights === 0 && $checkinRaw && $checkoutRaw) {
    $nights = max(0, (int) ceil((strtotime($checkoutRaw) - strtotime($checkinRaw)) / 86400));
}
$adults = (int) ($booking['adults'] ?? $bookingData['adults'] ?? 0);
$children = (int) ($booking['childs'] ?? $bookingData['children'] ?? 0);

// Rooms (reuse the selected_rooms contract).
$rooms = [];
if (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
    foreach ($bookingData['selected_rooms'] as $r) {
        $rooms[] = [
            'name' => (string) ($r['room_name'] ?? $bookingData['room_type'] ?? 'Standard Room'),
            'qty'  => (int) ($r['quantity'] ?? 1),
            'board'=> (string) ($r['option']['board_name'] ?? $bookingData['board_type'] ?? 'Room Only'),
        ];
    }
}
if (empty($rooms)) {
    $rooms[] = ['name' => (string) ($bookingData['room_type'] ?? 'Standard Room'), 'qty' => 1, 'board' => (string) ($bookingData['board_type'] ?? 'Room Only')];
}

// Lead guest.
$guestName = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));
$cancellation = (string) ($bookingData['cancellation_policy'] ?? '');

// ---- Body -------------------------------------------------------------------
ob_start();
?>
<div class="sec-h">Accommodation</div>
<div class="card">
    <div class="card-top"><table><tr>
        <td class="card-title"><?= $e($hotelName) ?><?php if ($stars > 0): ?> <span class="card-sub"><?= str_repeat('&#9733;', max(1, min(5, $stars))) ?></span><?php endif; ?></td>
        <td style="text-align:right;"><?php if ($nights > 0): ?><span class="tag"><?= $e($nights) ?> NIGHT<?= $nights > 1 ? 'S' : '' ?></span><?php endif; ?></td>
    </tr></table></div>
    <div class="card-body">
        <?php if ($hotelAddress !== ''): ?><div class="vs" style="margin-bottom:9pt;"><?= $e($hotelAddress) ?></div><?php endif; ?>
        <table><tr>
            <td style="width:50%;">
                <div class="k">Check-in</div>
                <div class="mid"><?= $e($checkin ?: '—') ?></div>
                <div class="vs">From 14:00</div>
            </td>
            <td style="width:50%; text-align:right;">
                <div class="k">Check-out</div>
                <div class="mid"><?= $e($checkout ?: '—') ?></div>
                <div class="vs">Until 12:00</div>
            </td>
        </tr></table>
    </div>
    <div class="cardfoot">
        <?php foreach ($rooms as $rm): ?>
            <span class="fic">ROOM</span> <b><?= $e($rm['name']) ?><?= $rm['qty'] > 1 ? ' &times; ' . (int) $rm['qty'] : '' ?></b>
            &nbsp;&middot;&nbsp; <span class="fic">BOARD</span> <b><?= $e($rm['board']) ?></b><br>
        <?php endforeach; ?>
        <span class="fic">GUESTS</span> <b><?= $adults ?: 1 ?> adult<?= ($adults ?: 1) > 1 ? 's' : '' ?><?= $children > 0 ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?></b>
    </div>
</div>

<div class="sec-h">Lead Guest</div>
<table class="infotable"><tr>
    <td style="width:64%;"><div class="v"><?= $e($guestName ?: 'Guest') ?></div></td>
    <td style="width:36%; text-align:right;"><span class="k">Status&nbsp;&nbsp;</span><span class="v" style="font-size:8pt;"><?= $isConfirmed ? 'Confirmed' : 'Reserved' ?></span></td>
</tr></table>

<?php if ($cancellation !== ''): ?>
<div class="sec-h">Cancellation Policy</div>
<table class="infotable"><tr><td><div class="vs"><?= $e($cancellation) ?></div></td></tr></table>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();

echo goglobia_ticket_render([
    'booking' => $booking, 'bookingData' => $bookingData, 'settings' => $settings,
    'businessName' => $businessName, 'businessEmail' => $businessEmail, 'businessPhone' => $businessPhone,
    'siteUrl' => $siteUrl, 'wlPdfLogo' => $wlPdfLogo, 'agentBrandColors' => $agentBrandColors,
    'bookingDate' => $bookingDate,
    'docTitle' => 'Hotel Voucher', 'docSub' => 'Accommodation Confirmation',
    'statusOk' => $isConfirmed,
    'statusOkText' => 'Confirmed', 'statusPendText' => 'Reserved',
    'refPrimaryLabel' => 'Hotel Confirmation No.',
    'refPrimaryValue' => $confRef,
    'refPrimaryHint' => 'Present this at hotel check-in',
    'managePath' => 'stays/booking/',
    'bodyHtml' => $bodyHtml,
    'guidanceTitle' => 'At check-in',
    'guidanceItems' => [
        'Present this voucher and a valid photo ID / passport for the lead guest at the hotel reception.',
        'Check-in is normally from 14:00 and check-out until 12:00 — contact the hotel directly for early check-in or late check-out.',
        'The hotel may request a refundable security deposit or pre-authorisation on a payment card at check-in.',
    ],
    'footerNote' => 'This voucher confirms your accommodation booking. Rates and payment details appear on your separate invoice/receipt. The hotel\'s own terms and any taxes payable locally apply.',
]);
