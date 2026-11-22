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
// The visa_type / processing_speed segments are admin-managed values from
// visa_settings.value. They are NOT restricted to [a-z_] at admin-save time — an
// operator can enter digits, hyphens or uppercase (e.g. 'e-visa', 'visa30',
// 'Business'). The old regex ([a-z_]+) rejected those, so the search redirect 404'd
// on every admin-added type that wasn't pure lowercase letters. Accept the full set
// of URL-safe slug characters [A-Za-z0-9_-] so the result page loads; the handler
// just stores the value in session and the booking page matches it against
// visa_settings.value exactly (so the original casing must be preserved).
$router->get('/visa/([A-Za-z]{2})/([A-Za-z]{2})/([0-9]{2}-[0-9]{2}-[0-9]{4})/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/([0-9]+)',
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
