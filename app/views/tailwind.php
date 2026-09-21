<?php
// app/views/tailwind.php
@$SECURE or die('Access Denied!');

// Load active theme
$activeThemeFile = __DIR__ . '/../../app/themes/active.json';
$activeThemeData = json_decode(file_get_contents($activeThemeFile), true);
$activeThemeName = $activeThemeData['active_theme'] ?? 'default';

// Load theme configuration
$themeFile = __DIR__ . '/../../app/themes/' . $activeThemeName . '.json';
if (!file_exists($themeFile)) {
    $themeFile = __DIR__ . '/../../app/themes/default.json';
}
$theme = json_decode(file_get_contents($themeFile), true);
?>

<!-- SITE FONT IS SERVED LOCALLY (Outfit @font-face IN assets/css/app.css,
     FILES IN assets/fonts/) — NO GOOGLE FONTS CDN FOR THE BODY FONT -->
<!-- Tailwind config moved to /tailwind.config.js (precompiled build). The JS
     __APP_TAILWIND_CONFIG__ object that the CDN JIT read at runtime is no longer
     emitted — the same tokens now drive the build. See docs/TAILWIND-BUILD.md. -->
<?php /* Former runtime Tailwind config — kept as a comment for provenance:
<script>
    window.__APP_TAILWIND_CONFIG__ = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Outfit', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                },
                colors: {
                    // Foreground colors
                    foreground: '#111827',

                    // Primary colors
                    primary: {
                        DEFAULT: '#3b82f6',
                        foreground: '#ffffff',
                        50: '#eff6ff',
                        100: '#dbeafe',
                        200: '#bfdbfe',
                        300: '#93c5fd',
                        400: '#60a5fa',
                        500: '#3b82f6',
                        600: '#2563eb',
                        700: '#1d4ed8',
                        800: '#1e40af',
                        900: '#1e3a8a',
                        950: '#172554'
                    },

                    // Secondary colors
                    secondary: {
                        DEFAULT: '#6b7280',
                        foreground: '#ffffff',
                    },

                    // Background colors
                    background: '#ffffff',

                    // Border colors
                    border: '#e5e7eb',

                    // Muted colors
                    muted: {
                        DEFAULT: '#f3f4f6',
                        foreground: '#6b7280',
                    },

                    // Accent colors
                    accent: {
                        DEFAULT: '#f3f4f6',
                        foreground: '#111827',
                    },

                    // Card colors
                    card: {
                        DEFAULT: '#ffffff',
                        foreground: '#111827'
                    },

                    // Custom brand colors
                    brand: {
                        light: '#ebf5ff',
                        DEFAULT: '#3b82f6',
                        dark: '#1e40af'
                    }
                },

                // Text colors shorthand
                textColor: {
                    foreground: '#111827',
                },

                // Background colors shorthand
                backgroundColor: {
                    background: '#ffffff',
                    secondary: '#6b7280',
                }
            }
        }
    };
</script>
*/ ?>

<script>
// Global checkbox functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle clicks on custom checkbox elements
    document.addEventListener('click', function(e) {
        if (e.target.closest('.checkbox-custom')) {
            const customCheckbox = e.target.closest('.checkbox-custom');
            const container = customCheckbox.closest('.checkbox-container');
            const input = container.querySelector('.checkbox-input');

            if (input && !input.disabled) {
                input.checked = !input.checked;
                // Trigger change event for proper AlpineJS reactivity
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
});
</script>

<style>
:root {
    /* Typography */
    --font-family: '<?= $theme['typography']['font_family'] ?? 'DM Sans' ?>', sans-serif;
    --font-size-base: <?= $theme['typography']['font_size_base'] ?? '16' ?>px;
    --line-height: <?= $theme['typography']['line_height'] ?? '1.5' ?>;

    /* Header Variables */
    --header-height: <?= $theme['header']['height'] ?? '64' ?>px;
    --header-font-size: <?= $theme['header']['font_size'] ?? '14' ?>px;
    --header-background: <?= $theme['header']['background'] ?? '#ffffff' ?>;
    --header-text-color: <?= $theme['header']['text_color'] ?? '#1f2937' ?>;
    --header-link-color: <?= $theme['header']['link_color'] ?? '#3b82f6' ?>;
    --header-link-hover-color: <?= $theme['header']['link_hover_color'] ?? '#2563eb' ?>;
    --header-border-color: <?= $theme['header']['border_color'] ?? '#e5e7eb' ?>;
    --header-border-width: <?= $theme['header']['border_width'] ?? '1' ?>px;
    --header-padding-x: <?= $theme['header']['padding_x'] ?? '16' ?>px;
    --header-shadow: <?= $theme['header']['shadow'] ?? '0 1px 3px 0 rgb(0 0 0 / 0.1)' ?>;

    /* Footer Variables */
    --footer-font-size: <?= $theme['footer']['font_size'] ?? '14' ?>px;
    --footer-background: <?= $theme['footer']['background'] ?? '#1f2937' ?>;
    --footer-text-color: <?= $theme['footer']['text_color'] ?? '#9ca3af' ?>;
    --footer-heading-color: <?= $theme['footer']['heading_color'] ?? '#ffffff' ?>;
    --footer-link-color: <?= $theme['footer']['link_color'] ?? '#60a5fa' ?>;
    --footer-link-hover-color: <?= $theme['footer']['link_hover_color'] ?? '#93c5fd' ?>;
    --footer-border-color: <?= $theme['footer']['border_color'] ?? '#374151' ?>;
    --footer-border-width: <?= $theme['footer']['border_width'] ?? '1' ?>px;
    --footer-padding-y: <?= $theme['footer']['padding_y'] ?? '48' ?>px;
    --footer-padding-x: <?= $theme['footer']['padding_x'] ?? '16' ?>px;

    /* Button Variables */
    --btn-height: <?= $theme['button']['height'] ?? '40' ?>px;
    --btn-padding-x: <?= $theme['button']['padding_x'] ?? '24' ?>px;
    --btn-padding-y: <?= $theme['button']['padding_y'] ?? '10' ?>px;
    --btn-font-size: <?= $theme['button']['font_size'] ?? '14' ?>px;
    --btn-font-weight: <?= $theme['button']['font_weight'] ?? '500' ?>;
    --btn-border-radius: <?= $theme['button']['border_radius'] ?? '8' ?>px;
    --btn-border-width: <?= $theme['button']['border_width'] ?? '0' ?>px;
    --btn-background: <?= $theme['button']['background'] ?? '#3b82f6' ?>;
    --btn-background-hover: <?= $theme['button']['background_hover'] ?? '#2563eb' ?>;
    --btn-text-color: <?= $theme['button']['text_color'] ?? '#ffffff' ?>;
    --btn-border-color: <?= $theme['button']['border_color'] ?? '#3b82f6' ?>;

    /* Input Variables */
    --input-height: <?= $theme['input']['height'] ?? '40' ?>px;
    --input-padding-x: <?= $theme['input']['padding_x'] ?? '24' ?>px;
    --input-padding-y: <?= $theme['input']['padding_y'] ?? '10' ?>px;
    --input-font-size: <?= $theme['input']['font_size'] ?? '14' ?>px;
    --input-font-weight: <?= $theme['input']['font_weight'] ?? '400' ?>;
    --input-border-radius: <?= $theme['input']['border_radius'] ?? '8' ?>px;
    --input-border-width: <?= $theme['input']['border_width'] ?? '1' ?>px;
    --input-background: <?= $theme['input']['background'] ?? '#ffffff' ?>;
    --input-text-color: <?= $theme['input']['text_color'] ?? '#1f2937' ?>;
    --input-border-color: <?= $theme['input']['border_color'] ?? '#e5e7eb' ?>;
    --input-border-focus-color: <?= $theme['input']['border_focus_color'] ?? '#3b82f6' ?>;
    --input-placeholder-color: <?= $theme['input']['placeholder_color'] ?? '#9ca3af' ?>;

    /* Textarea Variables */
    --textarea-min-height: <?= $theme['textarea']['min_height'] ?? '80' ?>px;
    --textarea-padding-x: <?= $theme['textarea']['padding_x'] ?? '24' ?>px;
    --textarea-padding-y: <?= $theme['textarea']['padding_y'] ?? '12' ?>px;
    --textarea-font-size: <?= $theme['textarea']['font_size'] ?? '14' ?>px;
    --textarea-font-weight: <?= $theme['textarea']['font_weight'] ?? '400' ?>;
    --textarea-border-radius: <?= $theme['textarea']['border_radius'] ?? '8' ?>px;
    --textarea-border-width: <?= $theme['textarea']['border_width'] ?? '1' ?>px;
    --textarea-background: <?= $theme['textarea']['background'] ?? '#ffffff' ?>;
    --textarea-text-color: <?= $theme['textarea']['text_color'] ?? '#1f2937' ?>;
    --textarea-border-color: <?= $theme['textarea']['border_color'] ?? '#e5e7eb' ?>;
    --textarea-border-focus-color: <?= $theme['textarea']['border_focus_color'] ?? '#3b82f6' ?>;
    --textarea-placeholder-color: <?= $theme['textarea']['placeholder_color'] ?? '#9ca3af' ?>;

    /* Select Variables */
    --select-height: <?= $theme['select']['height'] ?? '40' ?>px;
    --select-padding-x: <?= $theme['select']['padding_x'] ?? '24' ?>px;
    --select-padding-y: <?= $theme['select']['padding_y'] ?? '10' ?>px;
    --select-font-size: <?= $theme['select']['font_size'] ?? '14' ?>px;
    --select-font-weight: <?= $theme['select']['font_weight'] ?? '400' ?>;
    --select-border-radius: <?= $theme['select']['border_radius'] ?? '8' ?>px;
    --select-border-width: <?= $theme['select']['border_width'] ?? '1' ?>px;
    --select-background: <?= $theme['select']['background'] ?? '#ffffff' ?>;
    --select-text-color: <?= $theme['select']['text_color'] ?? '#1f2937' ?>;
    --select-border-color: <?= $theme['select']['border_color'] ?? '#e5e7eb' ?>;
    --select-border-focus-color: <?= $theme['select']['border_focus_color'] ?? '#3b82f6' ?>;

    /* Card Variables */
    --card-padding: <?= $theme['card']['padding'] ?? '24' ?>px;
    --card-border-radius: <?= $theme['card']['border_radius'] ?? '8' ?>px;
    --card-border-width: <?= $theme['card']['border_width'] ?? '1' ?>px;
    --card-background: <?= $theme['card']['background'] ?? '#ffffff' ?>;
    --card-border-color: <?= $theme['card']['border_color'] ?? '#e5e7eb' ?>;
    --card-shadow: <?= $theme['card']['shadow'] ?? '0 1px 3px 0 rgb(0 0 0 / 0.1)' ?>;

    /* Checkbox Variables */
    --checkbox-size: <?= $theme['checkbox']['size'] ?? '18' ?>px;
    --checkbox-border-radius: <?= $theme['checkbox']['border_radius'] ?? '4' ?>px;
    --checkbox-border-width: <?= $theme['checkbox']['border_width'] ?? '2' ?>px;
    --checkbox-background: <?= $theme['checkbox']['background'] ?? '#ffffff' ?>;
    --checkbox-background-checked: <?= $theme['checkbox']['background_checked'] ?? '#3b82f6' ?>;
    --checkbox-border-color: <?= $theme['checkbox']['border_color'] ?? '#d1d5db' ?>;
    --checkbox-border-checked-color: <?= $theme['checkbox']['border_checked_color'] ?? '#3b82f6' ?>;
    --checkbox-checkmark-color: <?= $theme['checkbox']['checkmark_color'] ?? '#ffffff' ?>;

    /* Radio Variables */
    --radio-size: <?= $theme['radio']['size'] ?? '16' ?>px;
    --radio-border-width: <?= $theme['radio']['border_width'] ?? '1' ?>px;
    --radio-background: <?= $theme['radio']['background'] ?? '#ffffff' ?>;
    --radio-border-color: <?= $theme['radio']['border_color'] ?? '#e5e7eb' ?>;
    --radio-border-checked-color: <?= $theme['radio']['border_checked_color'] ?? '#3b82f6' ?>;
    --radio-dot-color: <?= $theme['radio']['dot_color'] ?? '#3b82f6' ?>;

    /* Color Variables */
    --color-primary: <?= $theme['colors']['primary'] ?? '#3b82f6' ?>;
    --color-secondary: <?= $theme['colors']['secondary'] ?? '#6b7280' ?>;
    --color-success: <?= $theme['colors']['success'] ?? '#10b981' ?>;
    --color-danger: <?= $theme['colors']['danger'] ?? '#ef4444' ?>;
    --color-warning: <?= $theme['colors']['warning'] ?? '#f59e0b' ?>;
    --color-info: <?= $theme['colors']['info'] ?? '#06b6d4' ?>;
    --color-text: <?= $theme['colors']['text'] ?? '#1f2937' ?>;
    --color-text-muted: <?= $theme['colors']['text_muted'] ?? '#6b7280' ?>;
    --color-background: <?= $theme['colors']['background'] ?? '#ffffff' ?>;
    --color-background-muted: <?= $theme['colors']['background_muted'] ?? '#f9fafb' ?>;
    --color-border: <?= $theme['colors']['border'] ?? '#e5e7eb' ?>;

    /* Legacy variables for backward compatibility */
    --radius: 0.5rem;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --transition-default: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    --color-brand: var(--color-primary);
}

/* Apply typography globally */
body {
    font-family: var(--font-family) !important;
    font-size: var(--font-size-base) !important;
    line-height: var(--line-height) !important;
    color: var(--color-text) !important;
}

/* Prevent collapsing/distortion of absolutely positioned SVGs in search inputs (Safari flexbox bug on iPhones) */
.field-box svg,
.field-box-segment svg,
.field-box-icon,
svg.field-box-icon,
svg.absolute[class*="left-3"],
svg.absolute.left-3,
.absolute.left-3 svg {
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    min-height: 20px !important;
    flex-shrink: 0 !important;
}

/* Ensure field boxes and segments prevent flex shrinking of HG/Material symbols and SVG icons */
.field-box svg,
.field-box-segment svg,
.field-box i,
.field-box-segment i,
.field-box span.material-symbols-outlined,
.field-box-segment span.material-symbols-outlined,
.field-box-icon,
svg.field-box-icon {
    flex-shrink: 0 !important;
}
</style>

<!-- The component @layer (formerly a <style type="text/tailwindcss"> block the
     CDN JIT compiled at runtime) is now PRECOMPILED into
     assets/css/tailwind.build.css. Source of record: assets/css/tailwind.src.css.
     See docs/TAILWIND-BUILD.md. -->
