<?php @$SECURE or die('Access Denied!'); ?>
<?php
// FILE: _page.php — STANDALONE custom-page layout. Same document shell + design tokens as
// _layout.php, but renders a single supplier custom page (title + body, escaped) inside a
// simple branded nav + the shared contact footer. Route prepares $stay, $page, $site, $theme,
// $sitePages. No platform header/footer.
$s      = (isset($stay) && is_array($stay)) ? $stay : [];
$page   = (isset($page) && is_array($page)) ? $page : [];
$theme  = (isset($theme) && is_array($theme)) ? $theme : (function_exists('sitebuilder_decode') ? sitebuilder_decode(is_array($site ?? null) ? ($site['theme'] ?? null) : null) : []);
$design = function_exists('wl_design') ? wl_design($theme) : ['primary' => '#14110c', 'accent' => '#b08d57', 'display_css' => 'Georgia,serif', 'body_css' => 'system-ui,sans-serif'];
$sections = function_exists('wl_resolved_sections') ? wl_resolved_sections($theme) : ['contact'];

$wlLang = function_exists('wl_active_lang') ? wl_active_lang($theme) : 'en';
$isRtl  = function_exists('wl_lang_is_rtl') && wl_lang_is_rtl($wlLang);
$primary = $design['primary']; $accent = $design['accent'];
$paper   = function_exists('wl_hex_mix') ? wl_hex_mix('#ffffff', $accent, 0.05) : '#fff';
$inkSoft = function_exists('wl_hex_mix') ? wl_hex_mix($primary, '#ffffff', 0.38) : '#555';
$siteName = trim((string) ($s['name'] ?? 'Hotel'));
$rootUrl = defined('root') ? root : '/';
$bslug = (string) ($s['_slug'] ?? ($s['slug'] ?? ''));
$homeUrl = function_exists('wl_home_url') ? wl_home_url($bslug) : $rootUrl;
$favicon = trim((string) ($theme['favicon_url'] ?? ''));
$fontsHref = function_exists('wl_google_fonts_href') ? wl_google_fonts_href($design) : '';
$assetV  = rawurlencode((string) ($GLOBALS['app']['version'] ?? '1.0.0'));
$logo = trim((string) ($s['logo'] ?? ''));
?>
<!doctype html>
<html lang="<?= htmlspecialchars($wlLang) ?>"<?= $isRtl ? ' dir="rtl"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars((string) ($page['title'] ?? 'Page')) ?> — <?= htmlspecialchars($siteName) ?></title>
  <?php if ($favicon !== ''): ?><link rel="icon" href="<?= htmlspecialchars(wl_asset_url($favicon)) ?>"><?php elseif ($logo !== ''): ?><link rel="icon" href="<?= htmlspecialchars(wl_asset_url($logo)) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($rootUrl) ?>assets/css/tailwind.build.css?v=<?= $assetV ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
  <?php if ($fontsHref !== ''): ?><link href="<?= htmlspecialchars($fontsHref) ?>" rel="stylesheet"><?php endif; ?>
  <style>
    :root{--wl-primary:<?= htmlspecialchars($primary) ?>;--wl-accent:<?= htmlspecialchars($accent) ?>;--wl-ink:<?= htmlspecialchars($primary) ?>;--wl-ink-soft:<?= htmlspecialchars($inkSoft) ?>;--wl-paper:<?= htmlspecialchars($paper) ?>;--wl-line:rgba(0,0,0,.12);--wl-display:<?= $design['display_css'] ?>;--wl-body:<?= $design['body_css'] ?>}
    body.wl-site{margin:0;background:var(--wl-paper);color:var(--wl-ink);font-family:var(--wl-body);line-height:1.7}
    .wl-site h1,.wl-site h2,.wl-site h3,.wl-site .wl-display{font-family:var(--wl-display)}
    .wl-eyebrow{font-size:.72rem;letter-spacing:.3em;text-transform:uppercase;color:var(--wl-accent)}
    .wl-wrap{max-width:1180px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
    .wl-btn{display:inline-flex;align-items:center;gap:.5rem;font-size:.78rem;letter-spacing:.16em;text-transform:uppercase;padding:13px 24px;border:1px solid var(--wl-accent);color:var(--wl-accent);background:transparent;transition:.3s}
    .wl-btn:hover{background:var(--wl-accent);color:#fff}
    .pg-nav{display:flex;align-items:center;justify-content:space-between;padding:20px clamp(20px,5vw,48px);border-bottom:1px solid var(--wl-line)}
    .pg-brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-size:1.4rem;color:var(--wl-ink)}
    .pg-brand img{height:34px;max-width:150px;object-fit:contain}
    .pg-pages{display:flex;gap:22px;font-size:.78rem;letter-spacing:.08em;color:var(--wl-ink-soft);flex-wrap:wrap}
    .pg-pages a{color:var(--wl-ink-soft)}.pg-pages a:hover{color:var(--wl-ink)}
    .pg-body{max-width:780px;margin:0 auto;padding:clamp(48px,7vw,90px) clamp(20px,4vw,40px)}
    .pg-body h1{font-size:clamp(2rem,4vw,3rem);font-weight:500;margin:0 0 8px}
    .pg-content{color:var(--wl-ink);line-height:1.8;white-space:pre-line;margin-top:24px}
    [x-cloak]{display:none!important}
  </style>
</head>
<body class="wl-site">
  <header class="pg-nav">
    <a href="<?= htmlspecialchars($homeUrl) ?>" class="pg-brand"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?><?= htmlspecialchars($siteName) ?></a>
    <nav class="pg-pages">
      <a href="<?= htmlspecialchars($homeUrl) ?>">Home</a>
      <?php foreach ((isset($sitePages) && is_array($sitePages) ? $sitePages : []) as $pg): ?>
        <a href="<?= htmlspecialchars(function_exists('wl_page_url') ? wl_page_url($bslug, (string) ($pg['slug'] ?? '')) : '#') ?>"><?= htmlspecialchars((string) ($pg['title'] ?? '')) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <article class="pg-body">
    <p class="wl-eyebrow"><?= htmlspecialchars($siteName) ?></p>
    <h1><?= htmlspecialchars((string) ($page['title'] ?? '')) ?></h1>
    <div class="pg-content"><?= nl2br(htmlspecialchars((string) ($page['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
    <div style="margin-top:36px"><a href="<?= htmlspecialchars($homeUrl) ?>" class="wl-btn">&larr; Back to home</a></div>
  </article>

  <?php
    // Reuse the shared contact/footer section for a consistent hotel footer.
    $wlSid = (int) ($s['id'] ?? 0);
    $contactFile = __DIR__ . '/sections/contact.php';
    if (is_file($contactFile)) { require $contactFile; }
  ?>
</body>
</html>
