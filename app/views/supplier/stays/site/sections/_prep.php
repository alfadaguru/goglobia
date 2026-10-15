<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Shared data prep for the white-label section partials. Idempotent (guarded by $__wlPrepped)
// so templates can require it once; runs in the layout/template scope. Prepares escaped-safe
// structures the sections render. All raw values stay raw here; the sections escape on output.
if (empty($__wlPrepped)):
    $__wlPrepped = true;

    $wlCur = htmlspecialchars((string) ($s['currency'] ?? 'USD'));
    $wlSid = (int) ($s['id'] ?? 0);
    $wlSlug = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', (string) ($s['slug'] ?? ''))));
    $wlLangLocal = $wlLang ?? 'en';

    // --- ROOMS: normalize for cards (title, photos, specs, price, amenities, description) ---
    $wlRooms = [];
    foreach ((isset($rooms) && is_array($rooms) ? $rooms : []) as $room) {
        $opts = is_array($room['_options'] ?? null) ? $room['_options'] : (json_decode((string) ($room['room_options'] ?? ''), true) ?: []);
        $min = null;
        foreach ($opts as $o) { if (isset($o['price']) && ($min === null || $o['price'] < $min)) { $min = (float) $o['price']; } }
        $rtNames = (isset($roomTypeNames) && is_array($roomTypeNames)) ? $roomTypeNames : [];
        $title = trim((string) ($room['room_name'] ?? ''));
        if ($title === '') { $title = $rtNames[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . (int) $room['id']); }
        $desc = trim((string) ($room['description'] ?? ''));
        if (function_exists('wl_stay_field') && $wlLangLocal !== 'en' && !empty($room['translations'])) {
            $tn = wl_stay_field($room, 'room_name', $wlLangLocal); if (trim((string) $tn) !== '') { $title = $tn; }
            $td = wl_stay_field($room, 'description', $wlLangLocal); if (trim((string) $td) !== '') { $desc = $td; }
        }
        $gal = function_exists('wl_gallery') ? wl_gallery($room['room_images'] ?? null) : [];
        $specs = [];
        $sz = ($room['size_sqm'] ?? '') !== '' ? (float) $room['size_sqm'] : 0.0;
        if ($sz > 0) { $specs[] = rtrim(rtrim(number_format($sz, 2), '0'), '.') . ' m²'; }
        $beds = function_exists('wl_bed_config_label') ? wl_bed_config_label($room['bed_config'] ?? null) : '';
        if ($beds !== '') { $specs[] = $beds; }
        $view = function_exists('wl_view_label') ? wl_view_label($room['view_type'] ?? null) : '';
        if ($view !== '') { $specs[] = $view; }
        $amNames = (isset($roomAmenityNames) && is_array($roomAmenityNames)) ? $roomAmenityNames : [];
        $amens = [];
        $amIds = json_decode((string) ($room['amenities'] ?? '[]'), true);
        if (is_array($amIds)) { foreach ($amIds as $ai) { $n = $amNames[(int) $ai] ?? ''; if ($n !== '') { $amens[] = $n; } } }
        $wlRooms[] = [
            'id' => (int) $room['id'], 'title' => $title, 'desc' => $desc,
            'gallery' => $gal, 'hero' => ($gal[0]['url'] ?? ''), 'specs' => $specs,
            'min' => $min, 'amenities' => $amens, 'options' => $opts,
        ];
    }

    // --- GALLERY: property images (hero already chosen separately for the big hero). ---
    $wlGallery = function_exists('wl_gallery') ? wl_gallery($s['img'] ?? null) : [];

    // --- REVIEWS (published) + aggregate. ---
    $wlShowReviews = !isset($theme['show_reviews']) || !empty($theme['show_reviews']);
    $wlReviews = ($wlShowReviews && $wlSid > 0 && !empty($db) && function_exists('review_list_for_org')) ? review_list_for_org($db, [$wlSid], 'published', 9) : [];
    $wlAvg = (float) ($s['rating'] ?? 0);
    $wlRatingCount = (int) ($s['rating_count'] ?? 0);

    // --- OFFERS (active packages). ---
    $wlShowOffers = !isset($theme['show_offers']) || !empty($theme['show_offers']);
    $wlOffers = ($wlShowOffers && $wlSid > 0 && !empty($db) && function_exists('package_list')) ? package_list($db, [$wlSid], 12) : [];
    $wlOffers = array_values(array_filter(is_array($wlOffers) ? $wlOffers : [], fn($p) => (int) ($p['active'] ?? 0) === 1));

    // --- MAP coords. ---
    $wlShowMap = !isset($theme['show_map']) || !empty($theme['show_map']);
    $wlCoords = ($wlShowMap && function_exists('wl_coords')) ? wl_coords($s['location_coords'] ?? null) : null;

    // --- Hero copy (theme overrides, else sensible defaults from the stay). ---
    $wlHeroHeadline = trim((string) ($theme['hero_headline'] ?? '')) ?: (string) ($s['name'] ?? 'Welcome');
    $wlHeroSub = trim((string) ($theme['hero_subtext'] ?? '')) ?: trim((string) ($s['desc'] ?? ''));
    $wlHeroCta = trim((string) ($theme['hero_cta'] ?? '')) ?: 'Check availability';

    // --- Amenities (property-level) names. ---
    $wlAmenities = [];
    $rawAmen = $s['amenity_ids'] ?? '';
    if (!empty($rawAmen) && !empty($db)) {
        $ids = json_decode((string) $rawAmen, true);
        if (!is_array($ids)) { $ids = array_filter(array_map('intval', array_map('trim', explode(',', trim((string) $rawAmen, '[]'))))); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
            try { foreach ($db->select('stays_settings', ['name'], ['id' => $ids, 'setting_type' => 'stay_amenity', 'status' => 1]) ?: [] as $a) { $n = trim((string) ($a['name'] ?? '')); if ($n !== '') { $wlAmenities[] = $n; } } } catch (\Throwable $e) {}
        }
    }

    // --- Default guest nationality for the search deep-link. ---
    // The stays detail page forces a "select nationality" modal when nationality is
    // NULL/invalid, so we must send a VALID ISO. There is no country column on `stays`,
    // but the hotel's trading currency reliably implies its country — map it via
    // currencies.country (ISO-2). Fall back to a safe valid default so the guest is
    // never blocked. They can still change nationality on the detail page.
    $wlNationality = 'US';
    $wlCurCode = strtoupper(trim((string) ($s['currency'] ?? '')));
    if ($wlCurCode !== '' && !empty($db)) {
        try {
            $wlCurRow = $db->get('currencies', ['country'], ['name' => $wlCurCode]);
            $wlCountryIso = strtoupper(trim((string) ($wlCurRow['country'] ?? '')));
            if ($wlCountryIso !== '' && $db->has('countries', ['iso' => $wlCountryIso])) {
                $wlNationality = $wlCountryIso;
            }
        } catch (\Throwable $e) { /* keep default */ }
    }

    // --- Which sections will ACTUALLY render (so the nav never links to an empty
    //     section). Each flag mirrors the guard inside the matching sections/*.php. ---
    $wlAboutText = trim((string) ($theme['about'] ?? '')) ?: trim((string) ($s['desc'] ?? ''));
    $wlRenderable = [
        'about'    => $wlAboutText !== '',
        'rooms'    => !empty($wlRooms),
        'gallery'  => count($wlGallery) >= 2,
        'reviews'  => $wlShowReviews && (!empty($wlReviews) || ($wlAvg > 0 && $wlRatingCount > 0)),
        'offers'   => !empty($wlOffers),
        'location' => $wlCoords !== null,
        'contact'  => true, // contact/footer always renders
    ];
    // A section not in the map (unknown/custom) is assumed renderable.
    $wlSectionRenders = function ($key) use ($wlRenderable) {
        return !array_key_exists($key, $wlRenderable) || $wlRenderable[$key] === true;
    };

    // --- Booking deep-link builder (Phase 3) for the search widget. ---
    $wlBookBase = $rootUrl . 'stay/' . $wlSlug . '/' . $wlSid . '/hotels/_/';
endif;
?>
