<?php @$SECURE or die('Access Denied!'); ?>
<?php
// G1 — WHITE-LABEL BOOKING CHROME (head). Renders the hotel's own document shell + a slim
// branded nav around the PLATFORM booking view (stay detail / booking / invoice), so the guest
// stays on the hotel's site through checkout. NO platform header (no services nav / account /
// cart). Loads the SAME compiled Tailwind build the platform booking view expects, plus the
// hotel's design tokens + fonts. Requires the tenant context to be set (wl_booking_tenant()).
// This is presentation only — the booking view's own money/engine behaviour is unchanged.

$__wlT   = function_exists('wl_tenant') ? wl_tenant() : [];
$__stay  = is_array($__wlT['stay'] ?? null) ? $__wlT['stay'] : [];
$__theme = is_array($__wlT['theme'] ?? null) ? $__wlT['theme'] : [];
$__design = function_exists('wl_design') ? wl_design($__theme) : ['primary' => '#14110c', 'accent' => '#b08d57', 'display_css' => 'Georgia,serif', 'body_css' => 'system-ui,sans-serif'];
$__name  = (string) ($__wlT['name'] ?? ($__stay['name'] ?? 'Hotel'));
$__logo  = trim((string) ($__wlT['logo'] ?? ($__stay['logo'] ?? '')));
$__slug  = (string) ($__wlT['slug'] ?? ($__stay['slug'] ?? ''));
$__rootUrl = defined('root') ? root : '/';
$__home  = function_exists('wl_home_url') ? wl_home_url($__slug) : $__rootUrl;
$__primary = $__design['primary']; $__accent = $__design['accent'];
$__inkSoft = function_exists('wl_hex_mix') ? wl_hex_mix($__primary, '#ffffff', 0.38) : '#555';
$__paper = function_exists('wl_hex_mix') ? wl_hex_mix('#ffffff', $__accent, 0.04) : '#fff';
$__assetV = rawurlencode((string) ($GLOBALS['app']['version'] ?? '1.0.0'));
$__favicon = trim((string) ($__theme['favicon_url'] ?? ''));
$__fontsHref = function_exists('wl_google_fonts_href') ? wl_google_fonts_href($__design) : '';
$__pageTitle = (isset($title) && $title !== '') ? $title : $__name;
?>
<!doctype html>
<html lang="<?= htmlspecialchars((isset($wlLang) && $wlLang) ? $wlLang : 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($__pageTitle) ?></title>
  <meta name="robots" content="noindex">
  <?php if ($__favicon !== ''): ?><link rel="icon" href="<?= htmlspecialchars(function_exists('wl_asset_url') ? wl_asset_url($__favicon) : ($__rootUrl . ltrim($__favicon, '/'))) ?>">
  <?php elseif ($__logo !== ''): ?><link rel="icon" href="<?= htmlspecialchars(function_exists('wl_asset_url') ? wl_asset_url($__logo) : ($__rootUrl . ltrim($__logo, '/'))) ?>"><?php endif; ?>
  <script>document.documentElement.classList.add('js');</script>
  <link rel="stylesheet" href="<?= htmlspecialchars($__rootUrl) ?>assets/css/tailwind.build.css?v=<?= $__assetV ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($__rootUrl) ?>assets/css/app.css?v=<?= $__assetV ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
  <?php if ($__fontsHref !== ''): ?><link href="<?= htmlspecialchars($__fontsHref) ?>" rel="stylesheet"><?php endif; ?>
  <!-- Same core JS the platform booking view relies on (so the form/date/search behave identically). -->
  <script defer src="<?= htmlspecialchars($__rootUrl) ?>assets/js/app.js?v=<?= $__assetV ?>"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script defer src="https://code.jquery.com/jquery-3.7.1.min.js" data-cfasync="false"></script>
  <script defer src="<?= htmlspecialchars($__rootUrl) ?>assets/js/datepicker.js?v=<?= $__assetV ?>"></script>
  <style>
    :root{
      --wl-primary: <?= htmlspecialchars($__primary) ?>; --wl-accent: <?= htmlspecialchars($__accent) ?>;
      --wl-ink: <?= htmlspecialchars($__primary) ?>; --wl-ink-soft: <?= htmlspecialchars($__inkSoft) ?>;
      --wl-paper: <?= htmlspecialchars($__paper) ?>; --wl-display: <?= $__design['display_css'] ?>; --wl-body: <?= $__design['body_css'] ?>;
    }
    body{background:var(--wl-paper)!important}
    .wl-bk-nav{display:flex;align-items:center;justify-content:space-between;padding:16px clamp(16px,4vw,40px);background:var(--wl-primary);color:#fff}
    .wl-bk-nav a.brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-size:1.3rem;color:#fff;text-decoration:none}
    .wl-bk-nav a.brand img{height:34px;width:auto;max-width:150px;object-fit:contain}
    .wl-bk-nav .back{font-size:.8rem;letter-spacing:.08em;color:#fff;opacity:.85;text-decoration:none}
    .wl-bk-nav .back:hover{opacity:1}
    .wl-bk-foot{background:var(--wl-primary);color:#fff;text-align:center;padding:28px 16px;font-size:.82rem;opacity:.85;font-family:var(--wl-body)}
    .wl-bk-foot a{color:#fff}
  </style>
</head>
<body>
<header class="wl-bk-nav">
  <a href="<?= htmlspecialchars($__home) ?>" class="brand">
    <?php if ($__logo !== ''): ?><img src="<?= htmlspecialchars(function_exists('wl_asset_url') ? wl_asset_url($__logo) : ($__rootUrl . ltrim($__logo, '/'))) ?>" alt="<?= htmlspecialchars($__name) ?>"><?php endif; ?>
    <span><?= htmlspecialchars($__name) ?></span>
  </a>
  <a href="<?= htmlspecialchars($__home) ?>" class="back">&larr; Back to site</a>
</header>
