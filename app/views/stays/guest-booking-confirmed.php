<?php
// FILE: app/views/stays/guest-booking-confirmed.php
// Booking confirmation page shown after successful guest checkout

if (!isset($booking) || !is_array($booking)) {
    echo '<div class="container mt-5"><div class="alert alert-danger">Booking not found</div></div>';
    exit;
}
?>

<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body text-center py-5">
                    <h1 class="mb-3">Booking Confirmed!</h1>
                    <p class="lead text-muted mb-4">Your booking has been successfully created</p>

                    <div class="alert alert-info">
                        <strong>Confirmation email sent to:</strong> <?php echo htmlspecialchars($booking['guest_email']); ?>
                    </div>

                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <p class="text-muted mb-1">Confirmation Number</p>
                            <h4 class="mb-0"><?php echo htmlspecialchars($booking['booking_reference']); ?></h4>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                        <a href="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug'] ?? ''); ?>/my-booking" class="btn btn-primary">
                            View Your Booking
                        </a>
                        <a href="<?php echo root; ?>" class="btn btn-outline-secondary">
                            Return Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
