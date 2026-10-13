<?php
// FILE: app/views/stays/guest-checkout.php
// Guest checkout form - no account creation required

if (!isset($stay) || !is_array($stay)) {
    echo '<div class="container mt-5"><div class="alert alert-danger">Hotel not found</div></div>';
    exit;
}
?>

<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="mb-4">Complete Your Booking</h1>

            <form method="POST" action="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug']); ?>/checkout-process" id="checkoutForm">
                <!-- Booking Summary -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Booking Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="text-muted mb-1">Check-in</p>
                                <h6><?php echo htmlspecialchars($_GET['checkin'] ?? ''); ?></h6>
                            </div>
                            <div class="col-md-6">
                                <p class="text-muted mb-1">Check-out</p>
                                <h6><?php echo htmlspecialchars($_GET['checkout'] ?? ''); ?></h6>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="text-muted mb-1">Room</p>
                                <h6><?php echo htmlspecialchars($_GET['room_name'] ?? ''); ?></h6>
                            </div>
                            <div class="col-md-6">
                                <p class="text-muted mb-1">Total</p>
                                <h6 class="text-success"><?php echo htmlspecialchars($_GET['currency'] ?? 'USD'); ?> <?php echo number_format((float)($_GET['price'] ?? 0), 2); ?></h6>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Guest Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Your Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="firstName" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="firstName" name="first_name" required maxlength="100" placeholder="John">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="lastName" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="lastName" name="last_name" required maxlength="100" placeholder="Doe">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required maxlength="120" placeholder="john@example.com">
                                <small class="form-text text-muted">We'll send your confirmation and check-in details here</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone" maxlength="20" placeholder="+1 (555) 123-4567">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancellation Policy -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Cancellation Policy</h5>
                    </div>
                    <div class="card-body">
                        <p>Free cancellation up to 48 hours before check-in.</p>
                    </div>
                </div>

                <!-- Terms Agreement -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="agreeTerms" name="agree_terms" required>
                            <label class="form-check-label" for="agreeTerms">
                                I agree to the cancellation policy and terms of booking
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Hidden Fields -->
                <input type="hidden" name="checkin_date" value="<?php echo htmlspecialchars($_GET['checkin'] ?? ''); ?>">
                <input type="hidden" name="checkout_date" value="<?php echo htmlspecialchars($_GET['checkout'] ?? ''); ?>">
                <input type="hidden" name="total_price" value="<?php echo htmlspecialchars($_GET['price'] ?? 0); ?>">
                <input type="hidden" name="currency" value="<?php echo htmlspecialchars($_GET['currency'] ?? 'USD'); ?>">

                <!-- Submit -->
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-lock"></i> Proceed to Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
