<?php
// ============================================================================
// FILE: app/routes/cruises/detailRoutes.php
// CRUISE DETAIL PAGE (own-inventory — Phase 1). /cruise/{slug}
// Shows one cruise's full detail. Booking is a later phase; for now the detail
// page surfaces a "Book" CTA that is disabled/"coming soon" until wired.
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/cruise/([a-z0-9\-]+)', function ($slug) use ($SECURE, $db) {

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (function_exists('ensureCruisesSchema')) {
        ensureCruisesSchema($db);
    }

    $slug = trim((string) $slug);
    $cruise = null;
    try {
        $cruise = $db->get('cruises', '*', ['slug' => $slug, 'status' => 1]);
    } catch (\Throwable $e) {
        error_log('cruise detail query: ' . $e->getMessage());
    }

    if (!$cruise) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That cruise is no longer available.'];
        header('Location: ' . root . 'cruises');
        exit;
    }

    // Price for display (markup + tax + currency), same helper as the listing.
    $sessionCurrency = strtoupper((string) ($_SESSION['app_currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'NGN')));
    $base = (float) ($cruise['price'] ?? 0);
    $from = strtoupper((string) ($cruise['currency'] ?? 'NGN'));
    $cruise['display_price'] = $base;
    $cruise['display_currency'] = $from;
    if (function_exists('calculateCompletePrice')) {
        try {
            $priced = calculateCompletePrice($base, 'cruises', $db, $from, $sessionCurrency);
            if (is_array($priced) && isset($priced['final_total'])) {
                $cruise['display_price'] = (float) $priced['final_total'];
                $cruise['display_currency'] = $sessionCurrency;
            }
        } catch (\Throwable $e) { /* keep raw */ }
    }

    $title = ($cruise['name'] ?? 'Cruise') . ' - ' . ($GLOBALS['app']['home_title'] ?? '');
    $description = mb_substr(strip_tags((string) ($cruise['description'] ?? '')), 0, 160);

    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/detail/cruise.php';
    require_once views . 'includes/footer.php';
});
