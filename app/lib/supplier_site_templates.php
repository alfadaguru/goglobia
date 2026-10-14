<?php
// FILE: app/lib/supplier_site_templates.php
// WHITE-LABEL TEMPLATE SYSTEM — the registry of standalone site templates a hotel can choose,
// the allow-lists (fonts, sections) and validators that keep the theme JSON safe, and the
// CSS-variable builder that turns a hotel's config into a design system. Pure functions; all
// output is escaped by the layout/templates. Guarded with function_exists (safe re-include).

if (!function_exists('wl_templates')) {
    /**
     * The template registry. Each entry: label, blurb, the font pairing it ships with, its
     * default section order, and default accent — so a hotel that picks a template and changes
     * nothing still gets a complete, considered design.
     */
    function wl_templates(): array
    {
        return [
            'grand' => [
                'label'   => 'Grand',
                'blurb'   => 'Classic five-star luxury — a cinematic hero, serif display, and large room features.',
                'display' => 'Cormorant Garamond',
                'body'    => 'Jost',
                'accent'  => '#b08d57',
                'primary' => '#14110c',
                'sections'=> ['hero', 'search', 'about', 'rooms', 'gallery', 'reviews', 'offers', 'location', 'contact'],
            ],
            'minimal' => [
                'label'   => 'Minimal',
                'blurb'   => 'Quiet luxury — restraint, whitespace, and precise typography. Lets the photography speak.',
                'display' => 'Fraunces',
                'body'    => 'Inter',
                'accent'  => '#9a8c78',
                'primary' => '#1b1b1a',
                'sections'=> ['hero', 'about', 'rooms', 'search', 'gallery', 'reviews', 'location', 'contact'],
            ],
            'resort' => [
                'label'   => 'Resort',
                'blurb'   => 'Warm and coastal — relaxed, sun-lit, made for leisure escapes and longer stays.',
                'display' => 'Playfair Display',
                'body'    => 'Mulish',
                'accent'  => '#c97b4a',
                'primary' => '#143642',
                'sections'=> ['hero', 'search', 'rooms', 'offers', 'gallery', 'about', 'reviews', 'location', 'contact'],
            ],
            'modern' => [
                'label'   => 'Modern',
                'blurb'   => 'Conversion-forward — a hero search widget, price cards, and bold CTAs to win direct bookings.',
                'display' => 'Sora',
                'body'    => 'Inter',
                'accent'  => '#2563eb',
                'primary' => '#0f172a',
                'sections'=> ['hero', 'search', 'rooms', 'offers', 'reviews', 'gallery', 'location', 'contact'],
            ],
            'urban' => [
                'label'   => 'Urban',
                'blurb'   => 'Sleek city hotel — sharp, confident, business-ready, with a dark refined palette.',
                'display' => 'Space Grotesk',
                'body'    => 'Inter',
                'accent'  => '#d4b483',
                'primary' => '#17171a',
                'sections'=> ['hero', 'search', 'rooms', 'about', 'gallery', 'reviews', 'offers', 'location', 'contact'],
            ],
        ];
    }
}

if (!function_exists('wl_template_valid')) {
    function wl_template_valid(string $t): bool { return array_key_exists($t, wl_templates()); }
}

if (!function_exists('wl_template')) {
    /** The registry entry for a template key (falls back to 'grand'). */
    function wl_template(string $t): array
    {
        $all = wl_templates();
        return $all[$t] ?? $all['grand'];
    }
}

if (!function_exists('wl_fonts')) {
    /**
     * The allow-list of fonts a hotel may choose, name => [stack, google-family-or-null].
     * Only these can be set (prevents arbitrary CSS/URL injection via font names). System
     * stacks have a null Google family (nothing loaded).
     */
    function wl_fonts(): array
    {
        return [
            'Cormorant Garamond' => ['"Cormorant Garamond",Georgia,serif', 'Cormorant+Garamond:wght@400;500;600'],
            'Fraunces'           => ['"Fraunces",Georgia,serif', 'Fraunces:opsz,wght@9..144,400;9..144,500'],
            'Playfair Display'   => ['"Playfair Display",Georgia,serif', 'Playfair+Display:wght@400;500;600'],
            'Space Grotesk'      => ['"Space Grotesk",system-ui,sans-serif', 'Space+Grotesk:wght@400;500;700'],
            'Sora'               => ['"Sora",system-ui,sans-serif', 'Sora:wght@300;400;600;700'],
            'Jost'               => ['"Jost",system-ui,sans-serif', 'Jost:wght@300;400;500'],
            'Inter'              => ['"Inter",system-ui,sans-serif', 'Inter:wght@300;400;500;600'],
            'Mulish'             => ['"Mulish",system-ui,sans-serif', 'Mulish:wght@300;400;600;700'],
            'Montserrat'         => ['"Montserrat",system-ui,sans-serif', 'Montserrat:wght@300;400;600'],
            'Lora'               => ['"Lora",Georgia,serif', 'Lora:wght@400;500;600'],
            'System'             => ['-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif', null],
        ];
    }
}

if (!function_exists('wl_font_valid')) {
    function wl_font_valid(string $f): bool { return $f === '' || array_key_exists($f, wl_fonts()); }
}

if (!function_exists('wl_font_stack')) {
    /** CSS stack for a font name (or a serif/sans fallback when unknown/empty). */
    function wl_font_stack(string $name, string $fallback = 'sans'): string
    {
        $fonts = wl_fonts();
        if (isset($fonts[$name])) { return $fonts[$name][0]; }
        return $fallback === 'serif' ? 'Georgia,"Times New Roman",serif' : '-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';
    }
}

if (!function_exists('wl_sections_all')) {
    /** All renderable section keys (the universe a template/config can enable + order). */
    function wl_sections_all(): array
    {
        return ['hero', 'search', 'about', 'rooms', 'gallery', 'offers', 'reviews', 'location', 'contact'];
    }
}

if (!function_exists('wl_sections_clean')) {
    /**
     * Clean a posted/stored section list to the known set, de-duped, order preserved. Returns
     * [] when nothing valid (the caller then uses the template default). 'hero' is implicit and
     * always first if present.
     */
    function wl_sections_clean($in): array
    {
        if (is_string($in)) { $in = array_filter(array_map('trim', explode(',', $in))); }
        if (!is_array($in)) { return []; }
        $allowed = wl_sections_all();
        $out = [];
        foreach ($in as $s) {
            $s = strtolower(trim((string) $s));
            if (in_array($s, $allowed, true) && !in_array($s, $out, true)) { $out[] = $s; }
        }
        return $out;
    }
}

if (!function_exists('wl_nav_labels_clean')) {
    /** Clean per-section nav label overrides {sectionKey: label}; drop unknown keys, cap len. */
    function wl_nav_labels_clean($in): array
    {
        if (!is_array($in)) { return []; }
        $allowed = wl_sections_all();
        $out = [];
        foreach ($in as $k => $v) {
            $k = strtolower(trim((string) $k));
            $v = trim((string) $v);
            if (in_array($k, $allowed, true) && $v !== '') { $out[$k] = mb_substr($v, 0, 30); }
        }
        return $out;
    }
}

if (!function_exists('wl_resolved_sections')) {
    /** The section order to actually render for a decoded theme: the hotel's custom order if
     *  set, else the chosen template's default. Always a non-empty list including 'hero'. */
    function wl_resolved_sections(array $theme): array
    {
        $tpl = wl_template((string) ($theme['template'] ?? 'grand'));
        $secs = (!empty($theme['sections']) && is_array($theme['sections'])) ? $theme['sections'] : $tpl['sections'];
        $secs = wl_sections_clean($secs);
        if (empty($secs)) { $secs = $tpl['sections']; }
        if (!in_array('hero', $secs, true)) { array_unshift($secs, 'hero'); }
        return $secs;
    }
}

if (!function_exists('wl_design')) {
    /**
     * Resolve the final design tokens for a hotel: template defaults overlaid by the hotel's
     * explicit colour/font choices. Returns a flat array the layout turns into CSS vars.
     */
    function wl_design(array $theme): array
    {
        $tplKey = (string) ($theme['template'] ?? 'grand');
        $tpl = wl_template($tplKey);
        $primary = !empty($theme['color_primary']) ? $theme['color_primary'] : $tpl['primary'];
        // Accent priority: the explicit new color_accent wins; else the TEMPLATE's accent.
        // The legacy 'accent' key is NOT used here — sitebuilder_decode always fills it with
        // the old platform default (#7c3aed), so trusting it would force every template purple.
        // A hotel that wants a custom accent sets color_accent (the configurator writes it).
        $accent  = !empty($theme['color_accent']) ? $theme['color_accent'] : $tpl['accent'];
        $display = !empty($theme['font_display']) ? $theme['font_display'] : $tpl['display'];
        $body    = !empty($theme['font_body']) ? $theme['font_body'] : $tpl['body'];
        return [
            'template'    => $tplKey,
            'primary'     => $primary,
            'accent'      => $accent,
            'display'     => $display,
            'body'        => $body,
            'display_css' => wl_font_stack($display, 'serif'),
            'body_css'    => wl_font_stack($body, 'sans'),
        ];
    }
}

if (!function_exists('wl_google_fonts_href')) {
    /** The single Google Fonts <link> href for the two chosen fonts (or '' if both system). */
    function wl_google_fonts_href(array $design): string
    {
        $fonts = wl_fonts();
        $fams = [];
        foreach ([$design['display'] ?? '', $design['body'] ?? ''] as $name) {
            if (isset($fonts[$name]) && $fonts[$name][1] !== null) { $fams[$fonts[$name][1]] = true; }
        }
        if (empty($fams)) { return ''; }
        return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', array_keys($fams)) . '&display=swap';
    }
}

if (!function_exists('wl_asset_url')) {
    /** Resolve an image/asset path for output: an already-absolute URL (http/https/data/
     *  protocol-relative) is returned as-is; a relative path is prefixed with the site root.
     *  Hotel uploads are relative (/uploads/...) but a supplier may paste an absolute URL. */
    function wl_asset_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') { return ''; }
        if (preg_match('#^(https?:)?//#i', $path) || strpos($path, 'data:') === 0) { return $path; }
        $root = defined('root') ? root : '/';
        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wl_hex_mix')) {
    /** Mix two hex colours by $t in [0,1]. Used to derive soft/line tones from primary/accent. */
    function wl_hex_mix(string $a, string $b, float $t): string
    {
        $pa = sscanf($a, "#%02x%02x%02x");
        $pb = sscanf($b, "#%02x%02x%02x");
        if (!$pa || !$pb) { return $a; }
        $r = (int) round($pa[0] + ($pb[0] - $pa[0]) * $t);
        $g = (int) round($pa[1] + ($pb[1] - $pa[1]) * $t);
        $bl = (int) round($pa[2] + ($pb[2] - $pa[2]) * $t);
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $bl)));
    }
}
