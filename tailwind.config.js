/** @type {import('tailwindcss').Config} */
// ============================================================================
// PRECOMPILED TAILWIND CONFIG (spike — Phase 1-2)
// 1:1 port of window.__APP_TAILWIND_CONFIG__ from app/views/tailwind.php, so a
// build-time compile produces exactly what the CDN JIT produced at runtime.
// Only the color TOKENS live here; the theme-driven :root CSS variables stay in
// tailwind.php (they are runtime-dynamic per active theme and are unaffected).
// ============================================================================
module.exports = {
  darkMode: 'class',

  // Content scan — every source that emits Tailwind classes. Ternaries with
  // literal class strings are detected here as plain-text substrings; only
  // classes assembled by string concatenation need the safelist below.
  content: [
    './app/views/**/*.php',
    './modules/**/*.php',
    './app/**/*.php',
  ],

  // Classes built by concatenation (e.g. "bg-' . $x) that the content scanner
  // cannot see as literals. Keep this MINIMAL — over-safelisting bloats output.
  // Found in the Phase-3 sweep: app/views/admin/promo-codes/promo-codes.php:77.
  // (Spike seed; the full visual-regression pass may add a few more.)
  safelist: [
    'bg-green-500', 'bg-red-500', 'bg-blue-500', 'bg-amber-500',
    'bg-purple-500', 'bg-orange-500',
    { pattern: /^(bg|text|border)-(blue|green|red|amber|slate|gray)-(50|100|500|600|700|800)$/ },
  ],

  theme: {
    extend: {
      fontFamily: {
        sans: ['Outfit', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        foreground: '#111827',
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
          950: '#172554',
        },
        secondary: {
          DEFAULT: '#6b7280',
          foreground: '#ffffff',
        },
        background: '#ffffff',
        border: '#e5e7eb',
        muted: {
          DEFAULT: '#f3f4f6',
          foreground: '#6b7280',
        },
        accent: {
          DEFAULT: '#f3f4f6',
          foreground: '#111827',
        },
        card: {
          DEFAULT: '#ffffff',
          foreground: '#111827',
        },
        brand: {
          light: '#ebf5ff',
          DEFAULT: '#3b82f6',
          dark: '#1e40af',
        },
      },
      textColor: {
        foreground: '#111827',
      },
      backgroundColor: {
        background: '#ffffff',
        secondary: '#6b7280',
      },
    },
  },

  plugins: [],
};
