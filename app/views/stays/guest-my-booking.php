<?php
// FILE: app/views/stays/guest-my-booking.php
// Guest booking retrieval and management page - 3-step flow

if (!isset($stay) || !is_array($stay)) {
    echo '<div class="container mt-5"><div class="alert alert-danger">Hotel not found</div></div>';
    exit;
}

$verifyStep = isset($_GET['verify']) && $_GET['verify'] == '1';
$verifiedBookingId = $_SESSION['verified_booking_id'] ?? null;
$hasBooking = !empty($booking);
?>

<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="mb-4 text-center">Manage Your Booking</h1>

            <?php if (!$verifyStep && !$hasBooking): ?>
            <!-- STEP 1: Find Booking by Email -->
            <div class="card">
                <div class="card-body p-5">
                    <h5 class="card-title mb-4">Find Your Booking</h5>
                    <p class="text-muted mb-4">Enter the email address used for your booking to retrieve it.</p>

                    <form method="POST" action="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug']); ?>/request-otp">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control form-control-lg" id="email" name="email" required placeholder="you@example.com" maxlength="120">
                            <small class="form-text text-muted">Use the same email you provided when booking</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-envelope"></i> Send Verification Code
                        </button>
                    </form>

                    <hr class="my-4">
                    <p class="text-muted text-center mb-0">
                        <small>A 6-digit verification code will be sent to your email. It expires in 10 minutes.</small>
                    </p>
                </div>
            </div>

            <?php elseif ($verifyStep && !$hasBooking): ?>
            <!-- STEP 2: OTP Verification -->
            <div class="card">
                <div class="card-body p-5">
                    <h5 class="card-title mb-4">Enter Verification Code</h5>
                    <p class="text-muted mb-4">We sent a 6-digit code to your email.</p>

                    <form method="POST" action="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug']); ?>/verify-booking">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>" required readonly>
                        </div>

                        <div class="mb-3">
                            <label for="otp" class="form-label">Verification Code</label>
                            <input type="text" class="form-control form-control-lg text-center" id="otp" name="otp" 
                                inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required 
                                style="font-size: 1.5rem; letter-spacing: 0.5rem;">
                            <small class="form-text text-muted">Enter the 6-digit code from your email</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-check"></i> Verify & View Booking
                        </button>
                    </form>

                    <hr class="my-4">
                    <div class="text-center">
                        <small class="text-muted">Didn't receive the code?</small><br>
                        <a href="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug']); ?>/my-booking" class="small">Back to email entry</a>
                    </div>
                </div>
            </div>

            <?php elseif ($hasBooking): ?>
            <!-- STEP 3: Booking Details -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0">Booking Confirmation</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Confirmation Number</p>
                            <h5 class="mb-0" style="font-family: monospace;">
                                <?php echo htmlspecialchars($booking['booking_reference']); ?>
                            </h5>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <p class="text-muted mb-1">Status</p>
                            <div class="mb-0">
                                <span class="badge bg-success p-2">Confirmed</span>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Guest Name</p>
                            <p class="mb-3">
                                <?php echo htmlspecialchars($booking['guest_first_name'] . ' ' . $booking['guest_last_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Email</p>
                            <p class="mb-3">
                                <a href="mailto:<?php echo htmlspecialchars($booking['guest_email']); ?>">
                                    <?php echo htmlspecialchars($booking['guest_email']); ?>
                                </a>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Check-in</p>
                            <p class="mb-3">
                                <strong><?php echo htmlspecialchars($booking['checkin_date']); ?></strong>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Check-out</p>
                            <p class="mb-3">
                                <strong><?php echo htmlspecialchars($booking['checkout_date']); ?></strong>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1">Total Amount</p>
                            <p class="mb-3">
                                <strong class="text-success">
                                    <?php echo htmlspecialchars($booking['currency'] ?? 'USD'); ?> 
                                    <?php echo number_format($booking['total_price'], 2); ?>
                                </strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="<?php echo root; ?>book/<?php echo htmlspecialchars($stay['slug']); ?>/my-booking" class="btn btn-link">
                    Check Another Booking
                </a>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.container { max-width: 600px; }
.card { border: none; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); }
.card-header { background-color: #f8f9fa; }
</style>
