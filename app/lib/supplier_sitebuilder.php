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
