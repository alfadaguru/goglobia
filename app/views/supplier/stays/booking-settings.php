<?php
// FILE: app/views/supplier/stays/booking-settings.php
// Supplier configuration for guest booking system

if (!isset($stay) || !is_array($stay)) {
    echo '<div class="alert alert-danger">Hotel not found</div>';
    exit;
}

if (!isset($settings) || !is_array($settings)) {
    $settings = [];
}
?>

<div class="container mt-5 mb-5">
    <div class="page-header mb-4">
        <h1>Booking Settings</h1>
        <p class="text-muted">Configure guest booking options for <?php echo htmlspecialchars($stay['name']); ?></p>
    </div>

    <form method="POST" action="<?php echo root; ?>supplier/stays/booking-settings-save" class="needs-validation">
        <input type="hidden" name="stay_id" value="<?php echo (int)$stay['id']; ?>">

        <!-- Enable/Disable Guest Bookings -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Guest Bookings</h5>
            </div>
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allowGuestBookings" name="allow_guest_bookings" value="1"
                        <?php echo !empty($settings['allow_guest_bookings']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="allowGuestBookings">
                        <strong>Allow guests to book without creating an account</strong>
                    </label>
                </div>
                <p class="text-muted mt-2 mb-0">
                    When enabled, guests can complete bookings by entering their name, email, and payment information.
                </p>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Contact Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="contactEmail" class="form-label">Contact Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="contactEmail" name="guest_contact_email" 
                        value="<?php echo htmlspecialchars($settings['guest_contact_email'] ?? ''); ?>" 
                        required maxlength="120" placeholder="bookings@yourhotel.com">
                </div>

                <div class="mb-3">
                    <label for="contactPhone" class="form-label">Contact Phone <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" id="contactPhone" name="guest_contact_phone" 
                        value="<?php echo htmlspecialchars($settings['guest_contact_phone'] ?? ''); ?>" 
                        required maxlength="20" placeholder="+1 (555) 123-4567">
                </div>
            </div>
        </div>

        <!-- Check-in Instructions -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Check-in Instructions</h5>
            </div>
            <div class="card-body">
                <label for="checkinInstructions" class="form-label">Instructions for Guests</label>
                <textarea class="form-control" id="checkinInstructions" name="checkin_instructions" 
                    rows="4" maxlength="2000" placeholder="Tell guests how to check in..."><?php echo htmlspecialchars($settings['checkin_instructions'] ?? ''); ?></textarea>
                <small class="form-text text-muted"><span id="charCount">0</span>/2000 characters</small>
            </div>
        </div>

        <!-- Cancellation Policy -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Cancellation Policy</h5>
            </div>
            <div class="card-body">
                <label for="cancellationPolicy" class="form-label">Policy Text</label>
                <textarea class="form-control" id="cancellationPolicy" name="cancellation_policy_text" 
                    rows="4" maxlength="2000" placeholder="Describe your cancellation policy..."><?php echo htmlspecialchars($settings['cancellation_policy_text'] ?? ''); ?></textarea>
                <small class="form-text text-muted"><span id="policyCount">0</span>/2000 characters</small>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="d-grid gap-2 d-sm-flex justify-content-sm-end">
            <a href="<?php echo root; ?>supplier/stays/site/<?php echo (int)$stay['id']; ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<script>
const checkinInstructions = document.getElementById('checkinInstructions');
const charCount = document.getElementById('charCount');
if (checkinInstructions && charCount) {
    checkinInstructions.addEventListener('input', function() {
        charCount.textContent = this.value.length;
    });
    charCount.textContent = checkinInstructions.value.length;
}

const cancellationPolicy = document.getElementById('cancellationPolicy');
const policyCount = document.getElementById('policyCount');
if (cancellationPolicy && policyCount) {
    cancellationPolicy.addEventListener('input', function() {
        policyCount.textContent = this.value.length;
    });
    policyCount.textContent = cancellationPolicy.value.length;
}
</script>
