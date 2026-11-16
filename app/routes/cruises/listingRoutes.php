<?php
// ============================================================================
// FILE: app/routes/cruises/listingRoutes.php
// CRUISES SEARCH RESULTS (own-inventory, admin-managed — Phase 1).
// The cruises search form (app/views/modules/cruises/cruises-search.php) submits a
// GET to /cruises with query params: destination, departure_port, departure_date,
// cruise_length, cruise_line, room_type, adults, children, guests.
// This route reads those, queries the `cruises` table server-side, and renders the
// results page. Booking/payment is a later phase.
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/cruises', function () use ($SECURE, $db) {

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (function_exists('ensureCruisesSchema')) {
        ensureCruisesSchema($db);
    }

    // --- Read the search params (all optional; cruise search is browse-friendly). ---
    $destination   = trim((string) ($_GET['destination'] ?? ''));
    $departurePort = trim((string) ($_GET['departure_port'] ?? ''));
    $departureDate = trim((string) ($_GET['departure_date'] ?? ''));
    $cruiseLength  = trim((string) ($_GET['cruise_length'] ?? ''));   // nights, e.g. "7" or "any"
    $cruiseLine    = trim((string) ($_GET['cruise_line'] ?? ''));     // e.g. "any" or a line
    $roomType      = trim((string) ($_GET['room_type'] ?? ''));
    $adults        = max(1, (int) ($_GET['adults'] ?? 1));
    $children      = max(0, (int) ($_GET['children'] ?? 0));
    $totalGuests   = max(1, (int) ($_GET['guests'] ?? ($adults + $children)));

    // Remember for the form's "Modify" state.
    $_SESSION['cruise_destination']   = $destination;
    $_SESSION['cruise_departure_port'] = $departurePort;
    $_SESSION['cruise_departure_date'] = $departureDate;
    $_SESSION['cruise_length']        = $cruiseLength;
    $_SESSION['cruise_line']          = $cruiseLine;
    $_SESSION['cruise_room_type']     = $roomType;
    $_SESSION['cruise_adults']        = $adults;
    $_SESSION['cruise_children']      = $children;
    $_SESSION['cruise_guests']        = $totalGuests;

    // --- Build the query. Only live listings that can seat the party. ---
    $where = ['status' => 1, 'max_guests[>=]' => $totalGuests];

    // Destination is a free-text label on the cruise; match loosely (Medoo LIKE —
    // wrap %..% ourselves per the known Medoo underscore quirk).
    if ($destination !== '' && strtolower($destination) !== 'any') {
        $where['destination[~]'] = '%' . $destination . '%';
    }
    if ($departurePort !== '') {
        $where['departure_port[~]'] = '%' . $departurePort . '%';
    }
    // cruise_length = exact nights when numeric (ignore "any"/empty).
    if ($cruiseLength !== '' && strtolower($cruiseLength) !== 'any' && ctype_digit($cruiseLength)) {
        $where['nights'] = (int) $cruiseLength;
    }
    if ($cruiseLine !== '' && strtolower($cruiseLine) !== 'any') {
        $where['cruise_line[~]'] = '%' . $cruiseLine . '%';
    }
    if ($roomType !== '' && strtolower($roomType) !== 'any') {
        $where['room_type'] = $roomType;
    }

    $where['ORDER'] = ['featured' => 'DESC', 'order' => 'ASC', 'price' => 'ASC', 'id' => 'DESC'];
    $where['LIMIT'] = 60;

    $cruises = [];
    try {
        $cruises = $db->select('cruises', [
            'id', 'name', 'slug', 'cruise_line', 'ship_name', 'destination', 'departure_port',
            'nights', 'departure_date', 'room_type', 'currency', 'price', 'child_price',
            'max_guests', 'stars', 'img', 'refundable', 'featured',
        ], $where) ?: [];
    } catch (\Throwable $e) {
        error_log('cruises listing query: ' . $e->getMessage());
        $cruises = [];
    }

    // Apply B2C markup (+ tax) per the house helper, same as other own-inventory
    // services, so the displayed price is what the customer pays. Convert from the
    // cruise's stored currency to the visitor's session currency.
    $sessionCurrency = strtoupper((string) ($_SESSION['app_currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'NGN')));
    foreach ($cruises as &$c) {
        $base = (float) ($c['price'] ?? 0);
        $from = strtoupper((string) ($c['currency'] ?? 'NGN'));
        $c['display_price'] = $base;
        $c['display_currency'] = $from;
        if (function_exists('calculateCompletePrice')) {
            try {
                $priced = calculateCompletePrice($base, 'cruises', $db, $from, $sessionCurrency);
                if (is_array($priced) && isset($priced['final_total'])) {
                    $c['display_price'] = (float) $priced['final_total'];
                    $c['display_currency'] = $sessionCurrency;
                }
            } catch (\Throwable $e) { /* fall through to raw price */ }
        }
    }
    unset($c);

    $title = T::search . ' ' . (defined('T::cruises') ? T::cruises : 'Cruises') . ' - ' . ($GLOBALS['app']['home_title'] ?? '');
    $description = 'Find and book cruises at great prices';

    require_once views . 'includes/header.php';
    require_once views . 'modules/cruises/listing/cruises.php';
    require_once views . 'includes/footer.php';
});
