<?php
// FILE: app/lib/supplier_site_render.php
// WHITE-LABEL Phase 2b render helpers — shaping stored data for the branded hotel site.
// Pure functions (no I/O except $db reads passed in); all OUTPUT is escaped by the views, so
// these return raw values. Guarded with function_exists so a double-include is safe.

if (!function_exists('wl_bed_types')) {
    /** Canonical bed types (key => label). view_type analog for beds. */
    function wl_bed_types(): array
    {
        return [
            'single'   => 'Single',
            'double'   => 'Double',
            'twin'     => 'Twin',
            'queen'    => 'Queen',
            'king'     => 'King',
            'bunk'     => 'Bunk',
            'sofa_bed' => 'Sofa bed',
        ];
    }
}

if (!function_exists('wl_view_types')) {
    /** Room view options (key => label). Open-ended: an unknown stored value still displays
     *  via ucfirst fallback, but the supplier form offers this set. */
    function wl_view_types(): array
    {
        return [
            'none'      => 'No specific view',
            'street'    => 'Street view',
            'garden'    => 'Garden view',
            'pool'      => 'Pool view',
            'courtyard' => 'Courtyard view',
            'city'      => 'City view',
            'sea'       => 'Sea view',
            'ocean'     => 'Ocean view',
            'mountain'  => 'Mountain view',
            'lake'      => 'Lake view',
        ];
    }
}

if (!function_exists('wl_bed_config_normalize')) {
    /**
     * Normalize posted bed rows into the stored shape {"beds":[{type,qty,size}]}.
     * $in is $_POST['beds'] expected as a list of ['type'=>,'qty'=>,'size'=>]. Invalid rows
     * are dropped; unknown types coerce to 'double'; qty clamped 1..20; size capped 40 chars.
     * Returns a JSON string ready to store (or null when there are no valid beds).
     */
    function wl_bed_config_normalize($in): ?string
    {
        if (!is_array($in)) { return null; }
        $types = wl_bed_types();
        $beds = [];
        foreach ($in as $row) {
            if (!is_array($row)) { continue; }
            $type = (string) ($row['type'] ?? '');
            if (!isset($types[$type])) {
                // tolerate a label; else default
                $type = isset($types[strtolower($type)]) ? strtolower($type) : 'double';
            }
            $qty = (int) ($row['qty'] ?? 1);
            if ($qty < 1) { continue; } // a 0-qty row means "removed"
            $qty = min(20, $qty);
            $size = substr(trim((string) ($row['size'] ?? '')), 0, 40);
            $beds[] = ['type' => $type, 'qty' => $qty, 'size' => $size !== '' ? $size : null];
        }
        if (!$beds) { return null; }
        return json_encode(['beds' => $beds]);
    }
}

if (!function_exists('wl_bed_config_label')) {
    /** Human string for a stored bed_config JSON, e.g. "1 Queen · 2 Single". '' if empty. */
    function wl_bed_config_label(?string $json): string
    {
        $d = json_decode((string) $json, true);
        $beds = (is_array($d) && isset($d['beds']) && is_array($d['beds'])) ? $d['beds'] : [];
        if (!$beds) { return ''; }
        $types = wl_bed_types();
        $parts = [];
        foreach ($beds as $b) {
            $t = (string) ($b['type'] ?? '');
            $label = $types[$t] ?? ucfirst($t ?: 'Bed');
            $qty = max(1, (int) ($b['qty'] ?? 1));
            $parts[] = ($qty > 1 ? $qty . ' ' : '') . $label;
        }
        return implode(' · ', $parts);
    }
}

if (!function_exists('wl_view_label')) {
    /** Display label for a stored view_type (known or arbitrary). '' for none/empty. */
    function wl_view_label(?string $view): string
    {
        $view = trim((string) $view);
        if ($view === '' || $view === 'none') { return ''; }
        $all = wl_view_types();
        return $all[$view] ?? (ucfirst(str_replace(['_', '-'], ' ', $view)) . ' view');
    }
}

if (!function_exists('wl_gallery')) {
    /**
     * Decode an image JSON column (stays.img or stays_rooms.room_images) into a clean list of
     * ['url'=>, 'thumb'=>, 'default'=>bool, 'caption'=>string]. Tolerates the historical shape
     * {url,thumb,default} AND an optional new 'caption'. Drops entries with no url.
     */
    function wl_gallery(?string $json): array
    {
        $d = json_decode((string) $json, true);
        if (!is_array($d)) { return []; }
        $out = [];
        foreach ($d as $g) {
            if (!is_array($g)) { continue; }
            $url = trim((string) ($g['url'] ?? ''));
            if ($url === '') { continue; }
            $out[] = [
                'url'     => $url,
                'thumb'   => trim((string) ($g['thumb'] ?? '')) ?: $url,
                'default' => !empty($g['default']),
                'caption' => trim((string) ($g['caption'] ?? '')),
            ];
        }
        return $out;
    }
}

if (!function_exists('wl_hero_url')) {
    /** The hero image url from an image JSON: the default-flagged one, else the first. ''. */
    function wl_hero_url(?string $json): string
    {
        $g = wl_gallery($json);
        foreach ($g as $img) { if ($img['default']) { return $img['url']; } }
        return $g[0]['url'] ?? '';
    }
}

// ============================================================================
// MULTI-LANGUAGE (Phase 4) — read path over stays.translations / stays_rooms.translations.
// Stored shape (same as the admin writer): { lang_code : { field : value } }. Written by the
// admin AND (now) the supplier editor; read here so the branded site can present translated
// content. Falls back gracefully to the base column when a translation is missing.
// ============================================================================

if (!function_exists('wl_lang_fields')) {
    /** Stay-level translatable fields (match the admin writer) + room-level. */
    function wl_lang_fields(): array { return ['name', 'desc', 'address', 'cancellation_policy', 'privacy_policy']; }
}
if (!function_exists('wl_room_lang_fields')) {
    function wl_room_lang_fields(): array { return ['room_name', 'description']; }
}

if (!function_exists('wl_active_lang')) {
    /**
     * The active content language for a branded render. Priority: explicit ?lang= (sanitized),
     * then the session UI language, then the site's theme default_language, then 'en'.
     * $theme is the decoded stays_site theme (may carry 'default_language').
     */
    function wl_active_lang(array $theme = []): string
    {
        $pick = function ($v) {
            // lowercase FIRST, then strip — otherwise 'FR' / 'EN-US' lose their letters.
            $v = preg_replace('/[^a-z]/', '', strtolower((string) $v));
            return (strlen($v) >= 2 && strlen($v) <= 3) ? substr($v, 0, 3) : '';
        };
        $q = $pick($_GET['lang'] ?? '');
        if ($q !== '') { return $q; }
        $sess = $pick($_SESSION['app_language'] ?? '');
        if ($sess !== '') { return $sess; }
        $def = $pick($theme['default_language'] ?? '');
        if ($def !== '') { return $def; }
        return 'en';
    }
}

if (!function_exists('wl_translations_decode')) {
    /** Decode a translations JSON column into {lang:{field:value}} (safe). */
    function wl_translations_decode(?string $json): array
    {
        $d = json_decode((string) $json, true);
        return is_array($d) ? $d : [];
    }
}

if (!function_exists('wl_stay_field')) {
    /**
     * Resolve a translatable field for a row ($stay or $room array) in $lang, falling back to
     * the row's base column when the translation is absent/empty. $transKey is the column name
     * holding the translations JSON ('translations' for both stays + stays_rooms).
     */
    function wl_stay_field(array $row, string $field, string $lang, string $transKey = 'translations')
    {
        $lang = preg_replace('/[^a-z]/', '', strtolower($lang));
        if ($lang !== '' && $lang !== 'en') {
            $t = wl_translations_decode($row[$transKey] ?? null);
            if (!empty($t[$lang][$field]) && is_string($t[$lang][$field])) {
                return $t[$lang][$field];
            }
        }
        return $row[$field] ?? '';
    }
}

if (!function_exists('wl_stay_translations_normalize')) {
    /**
     * Build a stays.translations JSON string from a supplier POST, in the SAME shape the admin
     * writer uses ({lang:{field:value}}). Reads name/desc/address/cancellation_policy/
     * privacy_policy per language from field_translations[lang] arrays. Only non-empty values
     * are kept; a language with no values is dropped. Returns null when nothing to store.
     * $fields lets rooms reuse this with their own field set.
     */
    function wl_stay_translations_normalize(array $in, array $fields): ?string
    {
        // Collect the union of language codes across all *_translations arrays.
        $langs = [];
        foreach ($fields as $f) {
            $arr = $in[$f . '_translations'] ?? null;
            if (is_array($arr)) { foreach (array_keys($arr) as $lc) { $langs[strtolower(preg_replace('/[^a-z]/', '', (string) $lc))] = true; } }
        }
        $out = [];
        foreach (array_keys($langs) as $lc) {
            if ($lc === '' || strlen($lc) < 2 || strlen($lc) > 3 || $lc === 'en') { continue; } // 'en' = base column
            $row = [];
            foreach ($fields as $f) {
                $v = trim((string) ($in[$f . '_translations'][$lc] ?? ''));
                if ($v !== '') { $row[$f] = mb_substr($v, 0, 4000); }
            }
            if ($row) { $out[$lc] = $row; }
        }
        return $out ? json_encode($out) : null;
    }
}

if (!function_exists('wl_lang_is_rtl')) {
    /** RTL languages (for dir="rtl"). */
    function wl_lang_is_rtl(string $lang): bool
    {
        return in_array(strtolower($lang), ['ar', 'he', 'fa', 'ur'], true);
    }
}

if (!function_exists('wl_coords')) {
    /** Parse stays.location_coords 'lat,lng' into [lat,lng] floats, or null if invalid. */
    function wl_coords(?string $coords): ?array
    {
        $coords = trim((string) $coords);
        if ($coords === '' || strpos($coords, ',') === false) { return null; }
        [$lat, $lng] = array_pad(explode(',', $coords, 2), 2, '');
        $lat = trim($lat); $lng = trim($lng);
        if (!is_numeric($lat) || !is_numeric($lng)) { return null; }
        $lat = (float) $lat; $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { return null; }
        return [$lat, $lng];
    }
}
