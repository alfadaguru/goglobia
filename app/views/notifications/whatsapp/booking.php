Hello *<?= $customerName ?>*,

Your *<?= $moduleType ?>* booking is <?= ($payment_status === 'paid') ? '*Confirmed* 🎊 ✅' : '*Received* ✅' ?>

*Reference:* #<?= $invoiceId ?>

*Booking Details:*
• Service: <?= htmlspecialchars($hotel_name ?? $tour_name ?? $car_name ?? ($module === 'visa' ? ($to_country_name . ' Visa') : ($airlineName . ' ' . $flightNumber))) ?>
• Date: <?= $departure_date ?? $checkin ?? $start_date ?? $entry_date ?? date('d-M-Y') ?>
<?php if ($module === 'visa'): ?>
• Visa Type: <?= $visa_type_name ?> (<?= $processing_speed_name ?>)
<?php endif; ?>
<?php if (!empty($from) && !empty($to)): ?>
• Route: <?= $from ?> to <?= $to ?>
<?php endif; ?>
<?php if (!empty($room_type)): ?>
• Room: <?= $room_type ?> (<?= $nights ?> Nights)
<?php endif; ?>
• Guests: <?= $adults ?> Adults<?= $children > 0 ? ", $children Children" : "" ?>

*Financial Summary:*
• Total Amount: *<?= $currency ?> <?= number_format($amount, 2) ?>*
• Payment Status: *<?= ucfirst($payment_status) ?>*

<?php if ($payment_status !== 'paid'): ?>
*Action Required:* Please complete your payment to secure your booking.
<?php endif; ?>

View Details & Documents:
<?= $invoiceUrl ?>

Thank you for choosing *<?= $companyName ?>*!
<?php if (!empty($supportPhone)): ?>Support: <?= $supportPhone ?><?php endif; ?>
