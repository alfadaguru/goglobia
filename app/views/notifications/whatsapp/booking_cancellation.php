Hello *<?= $customerName ?>*,

Cancellation Request Received ⚠️

Your request to cancel *<?= $moduleType ?>* booking #<?= $invoiceId ?> is being processed.

*Booking Detail:*
• Service: <?= htmlspecialchars($hotel_name ?? $tour_name ?? $car_name ?? ($airlineName . ' ' . $flightNumber)) ?>
• Scheduled Date: <?= $departure_date ?? $checkin ?? $start_date ?? 'N/A' ?>

*Refund Status:* Pending Review
We will notify you once the cancellation process is complete.

View status & details:
<?= $invoiceUrl ?>

Thank you,
*<?= $companyName ?>*
