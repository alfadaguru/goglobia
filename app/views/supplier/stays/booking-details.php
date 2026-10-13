<?php
// FILE: app/views/supplier/stays/booking-details.php
// Detailed view and management of a single guest booking

if (!isset($booking) || !is_array($booking)) {
    echo '<div class="alert alert-danger">Booking not found</div>';
    exit;
}

if (!isset($stay) || !is_array($stay)) {
    echo '<div class="alert alert-danger">Invalid stay data</div>';
    exit;
}
?>

<div class="container mt-5 mb-5">
    <div class="page-header mb-4">
        <h1>Booking Details</h1>
        <p class="text-muted"><?php echo htmlspecialchars($booking['booking_reference'] ?? ''); ?></p>
    </div>

    <div class="card">
        <div class="card-body">
            <p><strong>Guest Name:</strong> <?php echo htmlspecialchars($booking['guest_first_name'] . ' ' . $booking['guest_last_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($booking['guest_email']); ?></p>
            <p><strong>Check-in:</strong> <?php echo htmlspecialchars($booking['checkin_date']); ?></p>
            <p><strong>Check-out:</strong> <?php echo htmlspecialchars($booking['checkout_date']); ?></p>
            <p><strong>Total:</strong> <?php echo htmlspecialchars($booking['currency']); ?> <?php echo number_format($booking['total_price'], 2); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($booking['booking_status']); ?></p>
        </div>
    </div>
</div>
