<?php
// app/lib/demo-warning-helper.php
// Helper functions for managing demo system warning

/**
 * Check if demo warning should be shown
 * Returns true if:
 * - Domain is phptravels.net or localhost
 * - User hasn't acknowledged the warning (or 7 days have passed since acknowledgement)
 * 
 * @return bool
 */
function shouldShowDemoWarning() {
    // Check if user already acknowledged in session
    if (isset($_SESSION['demo_warning_acknowledged']) && $_SESSION['demo_warning_acknowledged'] === true) {
        // Check if 7 days have passed since acknowledgement
        if (isset($_SESSION['demo_warning_acknowledged_at'])) {
            $acknowledgedTime = strtotime($_SESSION['demo_warning_acknowledged_at']);
            $currentTime = time();
            $daysPassed = ($currentTime - $acknowledgedTime) / (3600 * 24);
            
            // If less than 7 days have passed, don't show the warning
            if ($daysPassed < 7) {
                return false;
            }
            
            // If 7 days have passed, reset and show warning again
            resetDemoWarning();
        } else {
            return false;
        }
    }

    // Check if on demo domain
    $isDemoDomain = in_array($_SERVER['HTTP_HOST'], [
        'phptravels.net',
        'www.phptravels.net'
    ]);

    return $isDemoDomain;
}

/**
 * Mark demo warning as acknowledged in session
 * This prevents the warning from showing again in the current session
 * 
 * @return bool
 */
function acknowledgeDemoWarning() {
    $_SESSION['demo_warning_acknowledged'] = true;
    $_SESSION['demo_warning_acknowledged_at'] = date('Y-m-d H:i:s');
    return true;
}

/**
 * Reset demo warning for current user
 * Forces the warning to show again on next page load
 * Useful for testing or if user wants to see the warning again
 * 
 * @return bool
 */
function resetDemoWarning() {
    unset($_SESSION['demo_warning_acknowledged']);
    unset($_SESSION['demo_warning_acknowledged_at']);
    return true;
}

/**
 * Get demo warning acknowledgement status
 * 
 * @return array
 */
function getDemoWarningStatus() {
    return [
        'acknowledged' => isset($_SESSION['demo_warning_acknowledged']) ? (bool)$_SESSION['demo_warning_acknowledged'] : false,
        'acknowledged_at' => $_SESSION['demo_warning_acknowledged_at'] ?? null,
        'should_show' => shouldShowDemoWarning()
    ];
}

/**
 * Check if current domain is a demo domain
 * 
 * @return bool
 */
function isDemoDomain() {
    return in_array($_SERVER['HTTP_HOST'], [
        'phptravels.net',
        'www.phptravels.net'
    ]);
}
