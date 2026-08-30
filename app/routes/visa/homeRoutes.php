<?php
// app/routes/visa/homeRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET / - VISA HOME PAGE
$router->get('/visa', function () use ($SECURE, $db) {
    $title = T::visa ?? 'Visa';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/visa/home.php";
    require_once views."includes/footer.php";
});

// ================================ GET /visa/{from}/{to}/{date}/{type}/{speed}/{travelers} - VISA RESULTS
$router->get('/visa/([A-Za-z]{2})/([A-Za-z]{2})/([0-9]{2}-[0-9]{2}-[0-9]{4})/([a-z_]+)/([a-z_]+)/([0-9]+)', 
    function ($fromCountry, $toCountry, $entryDate, $visaType, $processingSpeed, $travelers) use ($SECURE, $db) {
    
    $travelers = max(1, min(10, (int)$travelers));

    // Validate and store search parameters in session
    $_SESSION['visa_search'] = [
        'from_country' => strtoupper($fromCountry),
        'to_country' => strtoupper($toCountry),
        'entry_date' => $entryDate,
        'visa_type' => $visaType,
        'processing_speed' => $processingSpeed,
        'travelers' => $travelers
    ];
    $_SESSION['visa_from_country'] = strtoupper($fromCountry);
    $_SESSION['visa_to_country'] = strtoupper($toCountry);
    $_SESSION['visa_travel_date'] = $entryDate;
    $_SESSION['visa_type'] = $visaType;
    $_SESSION['processing_speed'] = $processingSpeed;
    $_SESSION['visa_travelers'] = $travelers;

    // Get country names
    $fromCountryData = $db->get('countries', ['nicename'], ['iso' => strtoupper($fromCountry)]);
    $toCountryData = $db->get('countries', ['nicename'], ['iso' => strtoupper($toCountry)]);

    // Admin-configurable document requirements (Settings > Visa) — default optional,
    // matching the product's pre-existing behavior until an admin opts in.
    $visaDocSettings = $db->get('settings', ['visa_passport_required', 'visa_national_id_required'], ['id' => 1]);
    $visaPassportRequired = (($visaDocSettings['visa_passport_required'] ?? '0') === '1');
    $visaNationalIdRequired = (($visaDocSettings['visa_national_id_required'] ?? '0') === '1');

    $title = 'Visa Application - ' . ($fromCountryData['nicename'] ?? $fromCountry) . ' to ' . ($toCountryData['nicename'] ?? $toCountry);
    $description = 'Apply for visa from ' . ($fromCountryData['nicename'] ?? $fromCountry) . ' to ' . ($toCountryData['nicename'] ?? $toCountry);
    $header = false;
    $footer = false;

    require_once views."includes/header.php";
    require_once views."modules/visa/booking.php";
    require_once views."includes/footer.php";
});
