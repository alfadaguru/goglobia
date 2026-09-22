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

  // Classes built by string CONCATENATION — the content scanner matches
  // plain-text substrings, so it cannot see a class whose color is assembled
  // from a variable. A full Phase-3 sweep of app/ + modules/ found exactly ONE
  // such site: app/views/admin/promo-codes/promo-codes.php:77 builds
  // `bg-{$color}-500` where $color ∈ {red, yellow, green} (a usage-meter bar).
  // Everything else — including the ticket/status/priority colour MAPS — stores
  // FULL literal class strings ('bg-blue-100 text-blue-800'), which the scanner
  // already picks up, so they are deliberately NOT listed here. Keep this exact:
  // over-safelisting ships unused CSS.
  safelist: [
    'bg-red-500',
    'bg-yellow-500',
    'bg-green-500',
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
