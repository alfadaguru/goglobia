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
            // --- WHITE-LABEL Phase 2b: presentation extensions (all in the theme JSON) ---
            // Section toggles default to TRUE so a hotel sees its own content without opting
            // in; they only hide a section when a supplier explicitly turns it off (the key is
            // then stored as 0). isset() distinguishes "never set" (show) from "set to off".
            'show_reviews'  => !array_key_exists('show_reviews', $d)  ? true : !empty($d['show_reviews']),
            'show_offers'   => !array_key_exists('show_offers', $d)   ? true : !empty($d['show_offers']),
            'show_map'      => !array_key_exists('show_map', $d)      ? true : !empty($d['show_map']),
            // Hero image: an explicit URL override (else the gallery default is used).
            'hero_url'      => (preg_match('#^/?uploads/[^\s<>"]{1,250}$#i', (string) ($d['hero_url'] ?? '')) ? (string) $d['hero_url'] : ''),
            // Favicon (shown in the browser tab on the tenant site).
            'favicon_url'   => (preg_match('#^/?uploads/[^\s<>"]{1,250}$#i', (string) ($d['favicon_url'] ?? '')) ? (string) $d['favicon_url'] : ''),
            // Header/footer colours (hex). Default to transparent/neutral = inherit platform.
            'header_bg'     => (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['header_bg'] ?? '')) ? (string) $d['header_bg'] : ''),
            'footer_bg'     => (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['footer_bg'] ?? '')) ? (string) $d['footer_bg'] : ''),
            // Default content language for the branded site (Phase 4). 2–3 lowercase letters.
            'default_language' => (preg_match('/^[a-z]{2,3}$/', (string) ($d['default_language'] ?? '')) ? (string) $d['default_language'] : ''),
            // --- TEMPLATE SYSTEM: a full standalone site theme the hotel chooses + configures ---
            // NB: compute each value with ?? FIRST, then validate — never read $d[key] in the
            // ternary true-branch (that re-reads an undefined key when the validator passes on
            // empty, which it does for these optional fields -> an Undefined-key warning).
            'template'      => ((function_exists('wl_template_valid') && wl_template_valid($__t = (string) ($d['template'] ?? ''))) ? $__t : 'grand'),
            'color_primary' => (preg_match('/^#[0-9a-fA-F]{6}$/', $__cp = (string) ($d['color_primary'] ?? '')) ? $__cp : ''),
            'color_accent'  => (preg_match('/^#[0-9a-fA-F]{6}$/', $__ca = (string) ($d['color_accent'] ?? '')) ? $__ca : ''),
            'font_display'  => ((function_exists('wl_font_valid') && wl_font_valid($__fd = (string) ($d['font_display'] ?? ''))) ? $__fd : ''),
            'font_body'     => ((function_exists('wl_font_valid') && wl_font_valid($__fb = (string) ($d['font_body'] ?? ''))) ? $__fb : ''),
            'hero_headline' => substr((string) ($d['hero_headline'] ?? ''), 0, 160),
            'hero_subtext'  => substr((string) ($d['hero_subtext'] ?? ''), 0, 280),
            'hero_cta'      => substr((string) ($d['hero_cta'] ?? ''), 0, 40),
            // Ordered + toggled sections. Cleaned to the known set; empty => template default.
            'sections'      => (function_exists('wl_sections_clean') ? wl_sections_clean($d['sections'] ?? null) : []),
            // Optional per-section nav label overrides {sectionKey: label}.
            'nav_labels'    => (function_exists('wl_nav_labels_clean') ? wl_nav_labels_clean($d['nav_labels'] ?? null) : []),
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

        // Section toggles. When the form posts (prefs_submitted marker present), an unchecked
        // box sends nothing -> store 0. Without the marker (a non-form/partial caller), keep
        // them ON so we never silently hide a hotel's content.
        $prefsSubmitted = array_key_exists('prefs_submitted', $in);
        $boolPref = function ($key) use ($in, $prefsSubmitted) {
            if ($prefsSubmitted) { return !empty($in[$key]) ? 1 : 0; }
            return array_key_exists($key, $in) ? (!empty($in[$key]) ? 1 : 0) : 1;
        };
        $hex = function ($key) use ($in) {
            $v = (string) ($in[$key] ?? '');
            return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '';
        };
        $upath = function ($key) use ($in) {
            $v = (string) ($in[$key] ?? '');
            return preg_match('#^/?uploads/[^\s<>"]{1,250}$#i', $v) ? $v : '';
        };
        $lang = (string) ($in['default_language'] ?? '');
        if (!preg_match('/^[a-z]{2,3}$/', $lang)) { $lang = ''; }

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
            'show_reviews' => $boolPref('show_reviews'),
            'show_offers'  => $boolPref('show_offers'),
            'show_map'     => $boolPref('show_map'),
            'hero_url'     => $upath('hero_url'),
            'favicon_url'  => $upath('favicon_url'),
            'header_bg'    => $hex('header_bg'),
            'footer_bg'    => $hex('footer_bg'),
            'default_language' => $lang,
            // --- TEMPLATE SYSTEM ---
            'template'      => (function_exists('wl_template_valid') && wl_template_valid((string) ($in['template'] ?? '')) ? (string) $in['template'] : 'grand'),
            'color_primary' => $hex('color_primary'),
            'color_accent'  => $hex('color_accent'),
            'font_display'  => (function_exists('wl_font_valid') && wl_font_valid((string) ($in['font_display'] ?? '')) ? (string) ($in['font_display'] ?? '') : ''),
            'font_body'     => (function_exists('wl_font_valid') && wl_font_valid((string) ($in['font_body'] ?? '')) ? (string) ($in['font_body'] ?? '') : ''),
            'hero_headline' => substr(trim((string) ($in['hero_headline'] ?? '')), 0, 160),
            'hero_subtext'  => substr(trim((string) ($in['hero_subtext'] ?? '')), 0, 280),
            'hero_cta'      => substr(trim((string) ($in['hero_cta'] ?? '')), 0, 40),
            'sections'      => (function_exists('wl_sections_clean') ? wl_sections_clean($in['sections'] ?? null) : []),
            'nav_labels'    => (function_exists('wl_nav_labels_clean') ? wl_nav_labels_clean($in['nav_labels'] ?? null) : []),
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
