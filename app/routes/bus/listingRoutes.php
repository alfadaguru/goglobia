<?php
// ============================================================================
// FILE: app/routes/bus/listingRoutes.php — BUS LISTING (RESULTS) PAGE
// URL (one-way): /bus/{origin}/{destination}/oneway/{date}/{adults}-{children}
// URL (return):  /bus/{origin}/{destination}/return/{date}/{returnDate}/{adults}-{children}
// NOTE: catch-all — must be registered AFTER '/bus/' home route.
// ============================================================================

$router->get('/bus/(.*)', function ($params) use ($SECURE, $db) {

    $parts = array_values(array_filter(explode('/', trim((string)$params, '/')), fn($s) => $s !== ''));

    // NEED AT LEAST: origin, destination, tripType, date, pax
    if (count($parts) < 5) {
        redirect(root . 'bus/');
        return;
    }

    $origin      = str_replace('-', ' ', $parts[0]);
    $destination = str_replace('-', ' ', $parts[1]);
    $tripType    = ($parts[2] === 'return') ? 'return' : 'oneway';

    if ($tripType === 'return') {
        // origin/dest/return/date/returnDate/pax
        $date       = $parts[3] ?? '';
        $returnDate = $parts[4] ?? '';
        $paxStr     = $parts[5] ?? '1-0';
    } else {
        // origin/dest/oneway/date/pax
        $date       = $parts[3] ?? '';
        $returnDate = '';
        $paxStr     = $parts[4] ?? '1-0';
    }

    $paxParts = explode('-', $paxStr);
    $adults   = max(1, (int)($paxParts[0] ?? 1));
    $children = max(0, (int)($paxParts[1] ?? 0));

    // PERSIST SEARCH IN SESSION (RE-FILLS THE SEARCH WIDGET)
    $_SESSION['bus_origin']          = ucwords($origin);
    $_SESSION['bus_origin_city']     = ucwords($origin);
    $_SESSION['bus_destination']     = ucwords($destination);
    $_SESSION['bus_destination_city'] = ucwords($destination);
    $_SESSION['bus_trip_type']       = $tripType;
    $_SESSION['bus_date']            = $date;
    $_SESSION['bus_return_date']     = $returnDate;
    $_SESSION['bus_adults']          = $adults;
    $_SESSION['bus_children']        = $children;

    // EXPOSE TO VIEW
    $busSearch = [
        'origin'      => ucwords($origin),
        'destination' => ucwords($destination),
        'trip_type'   => $tripType,
        'date'        => $date,
        'return_date' => $returnDate,
        'adults'      => $adults,
        'children'    => $children,
        'passengers'  => $adults + $children,
    ];

    // META
    $title = ucwords($origin) . ' → ' . ucwords($destination) . ' ' . (T::bus ?? 'Bus') . ' | ' . ($GLOBALS['app']['business_name'] ?? '');
    $description = '';

    require_once views . "includes/header.php";
    require_once views . "modules/bus/listing/bus.php";
    require_once views . "includes/footer.php";
});
