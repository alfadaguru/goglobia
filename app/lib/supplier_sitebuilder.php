<?php
// FILE: app/lib/supplier_sitebuilder.php
// MODULE 13 (direct booking engine & website builder → 360). The site-builder layer:
// branding (accent colour + tagline), a promotion banner, custom-page content blocks,
// SEO meta, and an analytics tracking id — all stored in stays_site.theme (JSON). The
// live room search / checkout / guest payments are ALREADY served by the branded page +
// the main price-integrity booking flow (brandedSiteRoutes.php); this adds the
// presentation layer around them. Multilingual + multi-currency come from the platform
// header switchers the branded page inherits. NOT runtime-verified.
//
// SECURITY: custom text is stored raw but ALWAYS rendered through htmlspecialchars by the
// view (sitebuilder_text) — we never emit supplier HTML. The accent colour is validated
// to a hex pattern; the analytics id to an alnum token. No script injection surface.

if (!function_exists('sitebuilder_decode')) {
    /** Decode stays_site.theme JSON into the canonical site-builder shape (safe defaults). */
    function sitebuilder_decode(?string $themeJson): array
    {
        $d = json_decode((string) $themeJson, true);
        if (!is_array($d)) { $d = []; }
        return [
            'accent'      => (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['accent'] ?? '')) ? $d['accent'] : '#7c3aed'),
            'tagline'     => (string) ($d['tagline'] ?? ''),
            'banner'      => (string) ($d['banner'] ?? ''),         // promotion banner text
            'banner_on'   => !empty($d['banner_on']),
            'about'       => (string) ($d['about'] ?? ''),          // custom "about" page block
            'meta_title'  => (string) ($d['meta_title'] ?? ''),     // SEO
            'meta_desc'   => (string) ($d['meta_desc'] ?? ''),
            'analytics_id'=> (preg_match('/^[A-Za-z0-9\-]{0,40}$/', (string) ($d['analytics_id'] ?? '')) ? (string) ($d['analytics_id'] ?? '') : ''),
            // Per-property social links (stored in the theme JSON — no new column).
            // Each value is a URL the supplier pastes; empty = not shown.
            'social'      => sitebuilder_social_clean(is_array($d['social'] ?? null) ? $d['social'] : []),
        ];
    }
}

if (!function_exists('sitebuilder_social_platforms')) {
    /** The social platforms a hotel can link (key => [label, icon-ish]). Single source. */
    function sitebuilder_social_platforms(): array
    {
        return [
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
            'twitter'   => 'X / Twitter',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'whatsapp'  => 'WhatsApp',
        ];
    }
}

if (!function_exists('sitebuilder_social_clean')) {
    /** Keep only known platforms with a plausible http(s) URL (or tel/wa link). */
    function sitebuilder_social_clean(array $in): array
    {
        $out = [];
        foreach (sitebuilder_social_platforms() as $key => $_label) {
            $v = trim((string) ($in[$key] ?? ''));
            if ($v === '') { continue; }
            // Accept http(s) URLs only (length-capped). Anything else is dropped.
            if (preg_match('#^https?://[^\s<>"]{3,250}$#i', $v)) {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('sitebuilder_normalize')) {
    /** Build the stored theme JSON from a POST array (validated + length-capped). */
    function sitebuilder_normalize(array $in): string
    {
        $accent = (string) ($in['accent'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) { $accent = '#7c3aed'; }
        $analytics = (string) ($in['analytics_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9\-]{0,40}$/', $analytics)) { $analytics = ''; }
        return json_encode([
            'accent'       => $accent,
            'tagline'      => substr(trim((string) ($in['tagline'] ?? '')), 0, 160),
            'banner'       => substr(trim((string) ($in['banner'] ?? '')), 0, 240),
            'banner_on'    => !empty($in['banner_on']),
            'about'        => substr(trim((string) ($in['about'] ?? '')), 0, 4000),
            'meta_title'   => substr(trim((string) ($in['meta_title'] ?? '')), 0, 120),
            'meta_desc'    => substr(trim((string) ($in['meta_desc'] ?? '')), 0, 300),
            'analytics_id' => $analytics,
            'social'       => sitebuilder_social_clean(is_array($in['social'] ?? null) ? $in['social'] : []),
        ]);
    }
}

if (!function_exists('sitebuilder_save')) {
    /** Persist the theme for a property (caller authorized the stay). Returns bool. */
    function sitebuilder_save($db, int $stayId, array $in): bool
    {
        if ($stayId <= 0) { return false; }
        try {
            $json = sitebuilder_normalize($in);
            if ($db->has('stays_site', ['stay_id' => $stayId])) {
                $db->update('stays_site', ['theme' => $json, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', ['stay_id' => $stayId, 'theme' => $json, 'created_at' => date('Y-m-d H:i:s')]);
            }
            return true;
        } catch (\Throwable $e) { error_log('sitebuilder_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('sitebuilder_accent_safe')) {
    /** The accent colour, guaranteed safe to interpolate into a style attribute. */
    function sitebuilder_accent_safe(array $theme): string
    {
        $a = (string) ($theme['accent'] ?? '#7c3aed');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $a) ? $a : '#7c3aed';
    }
}

if (!function_exists('sitebuilder_decode_extended')) {
    function sitebuilder_decode_extended(?string $themeJson): array
    {
        $d = json_decode((string) $themeJson, true);
        if (!is_array($d)) { $d = []; }
        $social = (isset($d['social_media']) && is_array($d['social_media'])) ? $d['social_media'] : [];
        $sections = (isset($d['show_sections']) && is_array($d['show_sections'])) ? $d['show_sections'] : [];
        return [
            'accent' => (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['accent'] ?? '')) ? $d['accent'] : '#7c3aed'),
            'tagline' => (string) ($d['tagline'] ?? ''),
            'banner' => (string) ($d['banner'] ?? ''),
            'banner_on' => !empty($d['banner_on']),
            'about' => (string) ($d['about'] ?? ''),
            'meta_title' => (string) ($d['meta_title'] ?? ''),
            'meta_desc' => (string) ($d['meta_desc'] ?? ''),
            'analytics_id' => (preg_match('/^[A-Za-z0-9\-]{0,40}$/', (string) ($d['analytics_id'] ?? '')) ? (string) ($d['analytics_id'] ?? '') : ''),
            'social_media' => ['facebook' => (string) ($social['facebook'] ?? ''), 'instagram' => (string) ($social['instagram'] ?? ''), 'tiktok' => (string) ($social['tiktok'] ?? ''), 'twitter' => (string) ($social['twitter'] ?? ''), 'linkedin' => (string) ($social['linkedin'] ?? ''), 'youtube' => (string) ($social['youtube'] ?? ''), 'whatsapp' => (string) ($social['whatsapp'] ?? '')],
            'show_sections' => ['hero' => isset($sections['hero']) ? (bool) $sections['hero'] : true, 'gallery' => isset($sections['gallery']) ? (bool) $sections['gallery'] : true, 'about' => isset($sections['about']) ? (bool) $sections['about'] : true, 'amenities' => isset($sections['amenities']) ? (bool) $sections['amenities'] : true, 'rules' => isset($sections['rules']) ? (bool) $sections['rules'] : true, 'policies' => isset($sections['policies']) ? (bool) $sections['policies'] : true, 'rooms' => isset($sections['rooms']) ? (bool) $sections['rooms'] : true, 'contact' => isset($sections['contact']) ? (bool) $sections['contact'] : true, 'social' => isset($sections['social']) ? (bool) $sections['social'] : true, 'footer' => isset($sections['footer']) ? (bool) $sections['footer'] : true],
            'gallery_style' => in_array((string) ($d['gallery_style'] ?? 'grid'), ['grid', 'carousel', 'masonry']) ? (string) $d['gallery_style'] : 'grid',
            'show_contact' => isset($d['show_contact']) ? (bool) $d['show_contact'] : true,
            'contact_phone' => (string) ($d['contact_phone'] ?? ''),
            'contact_email' => (string) ($d['contact_email'] ?? ''),
        ];
    }
}

if (!function_exists('sitebuilder_normalize_extended')) {
    function sitebuilder_normalize_extended(array $in): string
    {
        $accent = (string) ($in['accent'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) { $accent = '#7c3aed'; }
        $analytics = (string) ($in['analytics_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9\-]{0,40}$/', $analytics)) { $analytics = ''; }
        $social = [];
        foreach (['facebook', 'instagram', 'tiktok', 'twitter', 'linkedin', 'youtube', 'whatsapp'] as $platform) {
            $url = trim((string) ($in['social_' . $platform] ?? ''));
            if ($url !== '' && strlen($url) <= 255) { $social[$platform] = $url; }
        }
        $sections = [];
        foreach (['hero', 'gallery', 'about', 'amenities', 'rules', 'policies', 'rooms', 'contact', 'social', 'footer'] as $section) {
            $sections[$section] = !empty($in['show_' . $section]);
        }
        $galleryStyle = (string) ($in['gallery_style'] ?? 'grid');
        if (!in_array($galleryStyle, ['grid', 'carousel', 'masonry'])) { $galleryStyle = 'grid'; }
        return json_encode(['accent' => $accent, 'tagline' => substr(trim((string) ($in['tagline'] ?? '')), 0, 160), 'banner' => substr(trim((string) ($in['banner'] ?? '')), 0, 240), 'banner_on' => !empty($in['banner_on']), 'about' => substr(trim((string) ($in['about'] ?? '')), 0, 4000), 'meta_title' => substr(trim((string) ($in['meta_title'] ?? '')), 0, 120), 'meta_desc' => substr(trim((string) ($in['meta_desc'] ?? '')), 0, 300), 'analytics_id' => $analytics, 'social_media' => $social, 'show_sections' => $sections, 'gallery_style' => $galleryStyle, 'show_contact' => !empty($in['show_contact']), 'contact_phone' => substr(trim((string) ($in['contact_phone'] ?? '')), 0, 20), 'contact_email' => substr(trim((string) ($in['contact_email'] ?? '')), 0, 120)]);
    }
}

if (!function_exists('sitebuilder_save_extended')) {
    function sitebuilder_save_extended($db, int $stayId, array $in): bool
    {
        if ($stayId <= 0) { return false; }
        try {
            $json = sitebuilder_normalize_extended($in);
            if ($db->has('stays_site', ['stay_id' => $stayId])) {
                $db->update('stays_site', ['theme' => $json, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', ['stay_id' => $stayId, 'theme' => $json, 'created_at' => date('Y-m-d H:i:s')]);
            }
            $stayUpdate = ['updated_at' => date('Y-m-d H:i:s')];
            $social = [];
            foreach (['facebook', 'instagram', 'tiktok', 'twitter', 'linkedin', 'youtube', 'whatsapp'] as $platform) {
                $url = trim((string) ($in['social_' . $platform] ?? ''));
                if ($url !== '') { $social[$platform] = $url; }
            }
            if (!empty($social)) { $stayUpdate['social_media'] = json_encode($social); }
            if (!empty($in['booking_phone'])) { $stayUpdate['booking_phone'] = substr(trim((string) $in['booking_phone']), 0, 20); }
            if (!empty($in['booking_email'])) { $stayUpdate['booking_email'] = substr(trim((string) $in['booking_email']), 0, 120); }
            $stayUpdate['show_contact'] = !empty($in['show_contact']) ? 1 : 0;
            $stayUpdate['show_amenities'] = !empty($in['show_amenities']) ? 1 : 0;
            $stayUpdate['show_policies'] = !empty($in['show_policies']) ? 1 : 0;
            $galleryStyle = (string) ($in['gallery_style'] ?? 'grid');
            if (in_array($galleryStyle, ['grid', 'carousel', 'masonry'])) { $stayUpdate['gallery_style'] = $galleryStyle; }
            $db->update('stays', $stayUpdate, ['id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('sitebuilder_save_extended: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('website_builder_get_config')) {
    function website_builder_get_config($db, int $stayId): array
    {
        if ($stayId <= 0 || !$db) { return []; }
        try {
            $stay = $db->get('stays', '*', ['id' => $stayId]);
            if (!$stay) { return []; }
            $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
            if (!$site) { $site = []; }
            $theme = function_exists('sitebuilder_decode_extended') ? sitebuilder_decode_extended($site['theme'] ?? null) : [];
            $amenities = [];
            try {
                $amenityIds = json_decode((string) ($stay['amenity_ids'] ?? '[]'), true) ?: [];
                if (!empty($amenityIds)) {
                    $rows = $db->select('stays_settings', ['id', 'name'], ['setting_type' => 'stay_amenity', 'id' => $amenityIds]);
                    foreach ($rows as $row) {
                        $amenities[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
                    }
                }
            } catch (\Throwable $e) {}
            $socialMedia = [];
            if (!empty($stay['social_media'])) {
                $parsed = json_decode((string) $stay['social_media'], true);
                if (is_array($parsed)) { $socialMedia = $parsed; }
            }
            if (empty($socialMedia) && !empty($theme['social_media'])) { $socialMedia = $theme['social_media']; }
            return [
                'property' => ['id' => (int) $stay['id'], 'name' => (string) ($stay['name'] ?? ''), 'location' => (string) ($stay['location'] ?? ''), 'address' => (string) ($stay['address'] ?? ''), 'phone' => (string) ($stay['phone'] ?? ''), 'email' => (string) ($stay['email'] ?? ''), 'website' => (string) ($stay['website'] ?? ''), 'logo' => (string) ($stay['logo'] ?? ''), 'stars' => (int) ($stay['stars'] ?? 0), 'description' => (string) ($stay['desc'] ?? '')],
                'contact' => ['booking_phone' => (string) ($stay['booking_phone'] ?? ''), 'booking_email' => (string) ($stay['booking_email'] ?? ''), 'show_contact' => (int) ($stay['show_contact'] ?? 1) === 1],
                'social_media' => $socialMedia,
                'display' => ['show_contact' => (int) ($stay['show_contact'] ?? 1) === 1, 'show_amenities' => (int) ($stay['show_amenities'] ?? 1) === 1, 'show_policies' => (int) ($stay['show_policies'] ?? 1) === 1, 'gallery_style' => (string) ($stay['gallery_style'] ?? 'grid')],
                'branding' => ['accent' => $theme['accent'] ?? '#7c3aed', 'tagline' => $theme['tagline'] ?? '', 'banner' => $theme['banner'] ?? '', 'banner_on' => $theme['banner_on'] ?? false],
                'content' => ['about' => $theme['about'] ?? ''],
                'seo' => ['meta_title' => $theme['meta_title'] ?? '', 'meta_desc' => $theme['meta_desc'] ?? '', 'analytics_id' => $theme['analytics_id'] ?? ''],
                'sections' => $theme['show_sections'] ?? [],
                'amenities' => $amenities,
                'policies' => ['house_rules' => (string) ($stay['house_rules'] ?? ''), 'cancellation_policy' => (string) ($stay['cancellation_policy'] ?? ''), 'checkin_time' => (string) ($stay['checkin_time'] ?? '14:00:00'), 'checkout_time' => (string) ($stay['checkout_time'] ?? '12:00:00')],
            ];
        } catch (\Throwable $e) {
            error_log('website_builder_get_config: ' . $e->getMessage());
            return [];
        }
    }
}

