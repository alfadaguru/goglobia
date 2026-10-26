Hello *<?= $customerName ?>*,

Your *<?= $moduleType ?>* booking is <?= ($payment_status === 'paid') ? '*Confirmed* 🎊 ✅' : '*Received* ✅' ?>

*Reference:* #<?= $invoiceId ?>

<?php
    // Build a human service line that survives a MANUAL / off-system booking
    // (e.g. recorded via the Booking Manager), where the live flight/hotel fields
    // are blank. Fall back to the service name + reference instead of an empty line.
    $svcParts = array_filter([
        trim((string) ($airlineName ?? '')),
        trim((string) ($flightNumber ?? '')),
    ], static function ($x) { return $x !== ''; });
    $flightLabel = $svcParts ? implode(' ', $svcParts) : '';
    $serviceLine = $hotel_name ?? $tour_name ?? $car_name
        ?? ($module === 'visa' ? (trim((string) ($to_country_name ?? '')) . ' Visa') : $flightLabel);
    $serviceLine = trim((string) $serviceLine);
    if ($serviceLine === '') { $serviceLine = ucfirst((string) $module) . ' booking'; }

    $dateLine = trim((string) ($departure_date ?? $checkin ?? $start_date ?? $entry_date ?? ''));
?>
*Booking Details:*
• Service: <?= htmlspecialchars($serviceLine) ?><?= "\n" ?>
<?php if ($dateLine !== ''): ?>
• Date: <?= htmlspecialchars($dateLine) ?><?= "\n" ?>
<?php endif; ?>
<?php if ($module === 'visa'): ?>
• Visa Type: <?= $visa_type_name ?> (<?= $processing_speed_name ?>)<?= "\n" ?>
<?php endif; ?>
<?php if (!empty($from) && !empty($to)): ?>
• Route: <?= $from ?> to <?= $to ?><?= "\n" ?>
<?php endif; ?>
<?php if (!empty($room_type)): ?>
• Room: <?= $room_type ?> (<?= $nights ?> Nights)<?= "\n" ?>
<?php endif; ?>
• Guests: <?= $adults ?> Adults<?= $children > 0 ? ", $children Children" : "" ?>

<?php if (empty($hide_price)): ?>
<?php /* Internal (owner/admin) copy keeps the money line + the invoice link.
         The TRAVELLER copy ($hide_price=1) is price-free and points to email. */ ?>
*Financial Summary:*
• Total Amount: *<?= $currency ?> <?= number_format($amount, 2) ?>*
• Payment Status: *<?= ucfirst($payment_status) ?>*

<?php if ($payment_status !== 'paid'): ?>
*Action Required:* Please complete your payment to secure your booking.
<?php endif; ?>

View Details & Documents:
<?= $invoiceUrl ?>
<?php else: ?>
<?php if ($payment_status === 'paid'): ?>
🎫 Your travel ticket has been sent to your email. Please keep it for your journey.
<?php else: ?>
*Action Required:* Please complete your payment to secure your booking — your ticket is emailed once payment is received.
<?php endif; ?>
<?php endif; ?>

Thank you for choosing *<?= $companyName ?>*!
<?php if (!empty($supportPhone)): ?>Support: <?= $supportPhone ?><?php endif; ?>
