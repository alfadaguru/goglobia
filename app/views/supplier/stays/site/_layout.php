<?php @$SECURE or die('Access Denied!'); ?>
<?php
// FILE: app/views/supplier/stays/site/_layout.php
// WHITE-LABEL STANDALONE LAYOUT — the complete document for a hotel's own branded site. This
// does NOT include the platform header/footer; the template owns the entire page (nav, hero,
// sections, footer). The route prepares the data ($stay, $rooms, $roomTypeNames,
// $roomAmenityNames, $site, $sitePages) and sets $wlTemplateMode = true before requiring this.
//
// Design: resolved from the hotel's theme via wl_design() into CSS custom properties, so every
// template is driven by the same tokens. Assets: the SAME compiled Tailwind build + Material
// Symbols the platform uses, plus the two chosen Google fonts (allow-listed). All supplier text
// is escaped by the sections; nothing here emits raw supplier HTML.

$s       = (isset($stay) && is_array($stay)) ? $stay : [];
$theme   = (isset($theme) && is_array($theme)) ? $theme : (function_exists('sitebuilder_decode') ? sitebuilder_decode(is_array($site ?? null) ? ($site['theme'] ?? null) : null) : []);
$design  = function_exists('wl_design') ? wl_design($theme) : ['template' => 'grand', 'primary' => '#14110c', 'accent' => '#b08d57', 'display_css' => 'Georgia,serif', 'body_css' => 'system-ui,sans-serif'];
$sections = function_exists('wl_resolved_sections') ? wl_resolved_sections($theme) : ['hero', 'rooms', 'contact'];
$tplKey  = $design['template'];
$tplFile = __DIR__ . '/templates/' . preg_replace('/[^a-z]/', '', $tplKey) . '.php';
if (!is_file($tplFile)) { $tplFile = __DIR__ . '/templates/grand.php'; }

// Active content language (Phase 4) + RTL.
$wlLang  = function_exists('wl_active_lang') ? wl_active_lang($theme) : 'en';
$isRtl   = function_exists('wl_lang_is_rtl') && wl_lang_is_rtl($wlLang);

// Translate the top-level stay fields for this language (fallback to base columns).
if (function_exists('wl_stay_field') && $wlLang !== 'en' && !empty($s['translations'])) {
    foreach (['name', 'desc', 'address', 'cancellation_policy'] as $__f) { $s[$__f] = wl_stay_field($s, $__f, $wlLang); }
}

// Derived design tokens (soft + line tones) from primary/accent.
$primary = $design['primary'];
$accent  = $design['accent'];
$paper   = function_exists('wl_hex_mix') ? wl_hex_mix('#ffffff', $accent, 0.05) : '#ffffff';
$ink     = $primary;
$inkSoft = function_exists('wl_hex_mix') ? wl_hex_mix($primary, '#ffffff', 0.38) : '#555';

// SEO/branding — the HOTEL's.
$siteName = trim((string) ($s['name'] ?? 'Hotel'));
$metaTitle = trim((string) ($theme['meta_title'] ?? '')) ?: $siteName;
$metaDesc  = trim((string) ($theme['meta_desc'] ?? '')) ?: trim((string) ($s['desc'] ?? ''));
$favicon   = trim((string) ($theme['favicon_url'] ?? ''));
$fontsHref = function_exists('wl_google_fonts_href') ? wl_google_fonts_href($design) : '';
$assetV    = rawurlencode((string) ($GLOBALS['app']['version'] ?? '1.0.0'));
$rootUrl   = defined('root') ? root : '/';

// Hero image: explicit theme hero_url, else gallery default.
$heroImg = trim((string) ($theme['hero_url'] ?? ''));
if ($heroImg === '' && function_exists('wl_hero_url')) { $heroImg = wl_hero_url($s['img'] ?? null); }
$heroAbs = $heroImg !== '' ? wl_asset_url($heroImg) : '';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($wlLang) ?>"<?= $isRtl ? ' dir="rtl"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($metaTitle) ?></title>
  <?php if ($metaDesc !== ''): ?><meta name="description" content="<?= htmlspecialchars($metaDesc) ?>"><?php endif; ?>
  <meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
  <?php if ($metaDesc !== ''): ?><meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>"><?php endif; ?>
  <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
  <meta property="og:type" content="website">
  <?php if ($heroAbs !== ''): ?><meta property="og:image" content="<?= htmlspecialchars($heroAbs) ?>"><?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <?php if ($favicon !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars(wl_asset_url($favicon)) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(wl_asset_url($favicon)) ?>">
  <?php elseif (!empty($s['logo'])): ?>
    <link rel="icon" href="<?= htmlspecialchars(wl_asset_url((string) $s['logo'])) ?>">
  <?php endif; ?>

  <!-- Same compiled build + icon font the platform uses (classes guaranteed to exist). -->
  <link rel="stylesheet" href="<?= htmlspecialchars($rootUrl) ?>assets/css/tailwind.build.css?v=<?= $assetV ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
  <?php if ($fontsHref !== ''): ?><link href="<?= htmlspecialchars($fontsHref) ?>" rel="stylesheet"><?php endif; ?>

  <style>
    :root{
      --wl-primary: <?= htmlspecialchars($primary) ?>;
      --wl-accent: <?= htmlspecialchars($accent) ?>;
      --wl-ink: <?= htmlspecialchars($ink) ?>;
      --wl-ink-soft: <?= htmlspecialchars($inkSoft) ?>;
      --wl-paper: <?= htmlspecialchars($paper) ?>;
      --wl-line: rgba(0,0,0,.12);
      --wl-display: <?= $design['display_css'] ?>;
      --wl-body: <?= $design['body_css'] ?>;
    }
    html{scroll-behavior:smooth}
    body.wl-site{margin:0;background:var(--wl-paper);color:var(--wl-ink);font-family:var(--wl-body);line-height:1.7;-webkit-font-smoothing:antialiased}
    .wl-site h1,.wl-site h2,.wl-site h3,.wl-site .wl-display{font-family:var(--wl-display)}
    .wl-site .material-symbols-outlined{font-variation-settings:'opsz' 24}
    .wl-eyebrow{font-size:.72rem;letter-spacing:.3em;text-transform:uppercase;color:var(--wl-accent)}
    .wl-wrap{max-width:1180px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
    .wl-btn{display:inline-flex;align-items:center;gap:.5rem;font-size:.78rem;letter-spacing:.16em;text-transform:uppercase;padding:14px 26px;border:1px solid var(--wl-accent);color:var(--wl-accent);background:transparent;transition:.3s;cursor:pointer}
    .wl-btn:hover{background:var(--wl-accent);color:#fff}
    .wl-btn-solid{background:var(--wl-primary);border-color:var(--wl-primary);color:#fff}
    .wl-btn-solid:hover{background:var(--wl-accent);border-color:var(--wl-accent)}
    [x-cloak]{display:none!important}
  </style>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <?php
    $analytics = trim((string) ($theme['analytics_id'] ?? ''));
    if ($analytics !== '' && preg_match('/^[A-Za-z0-9\-]{1,40}$/', $analytics)):
  ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($analytics) ?>"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= htmlspecialchars($analytics) ?>');</script>
  <?php endif; ?>
</head>
<body class="wl-site">
<?php
  // The template owns the whole page. It has access to all the prepared data + $design,
  // $sections, $accent, $primary, $wlLang, $siteName, $heroAbs, and the shared section
  // partials under sections/ (via wl_section()).
  require $tplFile;
?>
</body>
</html>
