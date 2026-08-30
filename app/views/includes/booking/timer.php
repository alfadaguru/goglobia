<?php
// ============================================================================
// BOOKING COUNTDOWN TIMER COMPONENT - REUSABLE
// ============================================================================
// DISPLAYS A COUNTDOWN TIMER FOR BOOKING SESSIONS
// REQUIRES:
//   - $bookingCreatedAt: Booking creation timestamp
//   - $moduleType: Module type (flights, stays, tours, cars)
// ============================================================================

@$SECURE or die('Access Denied!');

// Ensure database connection exists
if (!isset($db)) {
    die('Database connection not available');
}

// Get booking expiry time from settings (in minutes) - Direct DB query
$expiryMinutes = $db->get('settings', 'booking_expiry_time', []);
$timeLimit = ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0) ? ($expiryMinutes * 60) : 600; // Default 10 minutes

// Calculate time remaining
$timeElapsed = time() - strtotime($bookingCreatedAt);
$timeRemaining = max(0, $timeLimit - $timeElapsed);

// Set redirect URL based on module type (generic for all modules)
$redirectUrl = root . ($moduleType ?? '');

// Generate unique timer ID to avoid conflicts
$timerUniqueId = 'timer_' . uniqid();
?>

<script>
// ============================================================================
// BOOKING COUNTDOWN TIMER ALPINE.JS COMPONENT
// ============================================================================
// DEFINE FUNCTION BEFORE ALPINE COMPONENT USES IT
// ============================================================================
if (typeof window.bookingTimer_<?= $timerUniqueId ?> === 'undefined') {
    window.bookingTimer_<?= $timerUniqueId ?> = function() {
        return {
            timeLeft: <?= $timeRemaining ?>,  // TIME REMAINING FROM DATABASE
            timer: null,
            showExpiredModal: false,

            init() {
                // Check if already expired
                if (this.timeLeft <= 0) {
                    this.showExpiredModal = true;
                    return;
                }
                this.startTimer();
            },

            startTimer() {
                this.timer = setInterval(() => {
                    this.timeLeft--;

                    if (this.timeLeft <= 0) {
                        clearInterval(this.timer);
                        this.showExpiredModal = true;
                    }
                }, 1000);
            },

            formatTime(seconds) {
                const minutes = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${minutes}:${secs.toString().padStart(2, '0')}`;
            },

            goBackHome() {
                window.location.href = '<?= $redirectUrl ?>';
            },

            destroy() {
                if (this.timer) {
                    clearInterval(this.timer);
                }
            }
        }
    }
}
</script>

<!-- ========================================== -->
<!-- BOOKING COUNTDOWN TIMER CARD               -->
<!-- ========================================== -->
<div class="card p-0 mb-3" x-data="bookingTimer_<?= $timerUniqueId ?>()">
    <div class="card-header">
        <div>
            <span class="card-header-icon">timer</span>
            <h3><?= T::session ?> <?= T::timer ?></h3>
        </div>
    </div>
    <div class="card-body">
        <div class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-red-600 dark:text-red-400">schedule</span>
                <span class="text-sm font-medium text-red-800 dark:text-red-200"><?= T::session ?> <?= T::expires ?> <?= T::in ?>:</span>
            </div>
            <div class="text-lg font-bold text-red-600 dark:text-red-400" x-text="formatTime(timeLeft)"></div>
        </div>
        <p class="text-xs text-red-600 dark:text-red-400 mt-2 text-center" x-show="timeLeft <= 60">
            <?= T::complete ?> <?= T::your ?> <?= T::booking ?> <?= T::quickly ?> <?= T::to ?> <?= T::avoid ?> <?= T::session ?> <?= T::timeout ?>!
        </p>
    </div>

    <!-- ========================================== -->
    <!-- TIME EXPIRED MODAL                         -->
    <!-- ========================================== -->
    <div x-show="showExpiredModal"
         x-cloak
         class="fixed inset-0 z-[9999] flex items-center justify-center bg-black bg-opacity-50"
         style="display: none;">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-8 max-w-md mx-4 text-center">
            <div class="mb-4">
                <span class="material-symbols-outlined text-red-500" style="font-size: 64px;">schedule</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2"><?= T::time ?> <?= T::expired ?>!</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                <?= T::your ?> <?= T::booking ?> <?= T::session ?> <?= T::has ?> <?= T::expired ?>. <?= T::please ?> <?= T::search ?> <?= T::again ?>.
            </p>
            <button @click="goBackHome()"
                    class="btn">
                <?= T::go ?> <?= T::back ?> & <?= T::search ?> <?= T::again ?>
            </button>
        </div>
    </div>
</div>
