<?php
/**
 * Inline SVG icon chips for PDF tickets (mPDF-safe).
 *
 * mPDF cannot render color emoji as font glyphs (luggage/backpack/clock show as
 * tofu). It CAN render simple inline <svg> (paths + solid fills; no filters/markers/
 * patterns). So the ticket uses these small, brand-coloured vector icons instead of
 * emoji — crisp at any size and on-brand.
 *
 * SECURITY: every SVG here is hand-authored + trusted. NEVER build an icon from
 * user input (mPDF has a known RCE vector via malicious SVG).
 *
 * ft_icon($name, $color, $px) returns an inline <svg> string. Known names:
 *   checked (suitcase), cabin (backpack), clock (duration/status), plane, pin.
 */

if (!function_exists('ft_icon')) {
    function ft_icon(string $name, string $color = '#0058e6', int $px = 13): string
    {
        // sanitise: only a hex colour + a small size are allowed through.
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) { $color = '#0058e6'; }
        $px = max(8, min(40, $px));
        $w = $px; $h = $px;
        $paths = [
            // 24x24 viewBox, single-colour paths (fill=currentColor via inline fill).
            'checked' => '<path fill="%C%" d="M9 3a2 2 0 0 0-2 2v1H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h.5v1h2v-1h9v1h2v-1H19a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-2V5a2 2 0 0 0-2-2H9Zm0 2h6v1H9V5ZM5 8h14v9H5V8Zm3 1.5v6h1.5v-6H8Zm6.5 0v6H16v-6h-1.5Z"/>',
            'cabin'   => '<path fill="%C%" d="M12 2a3 3 0 0 0-3 3v1.2A6 6 0 0 0 6 11v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-8a6 6 0 0 0-3-4.8V5a3 3 0 0 0-3-3Zm-1 3a1 1 0 0 1 2 0v.3a6 6 0 0 0-2 0V5Zm-3 9h8v5H8v-5Zm2-5h4a1 1 0 0 1 0 2h-4a1 1 0 0 1 0-2Z"/>',
            'clock'   => '<path fill="%C%" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 2a7 7 0 1 1 0 14 7 7 0 0 1 0-14Zm-1 2v6l5 3 .9-1.5L13 11.9V7h-2Z"/>',
            'plane'   => '<path fill="%C%" d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V18l-2 1.5V21l3.5-1 3.5 1v-1.5L13 18v-4.5L21 16Z"/>',
            'pin'     => '<path fill="%C%" d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7Zm0 4.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/>',
        ];
        $p = $paths[$name] ?? $paths['clock'];
        $p = str_replace('%C%', $color, $p);
        return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 24 24" '
             . 'style="vertical-align:middle;">' . $p . '</svg>';
    }
}
