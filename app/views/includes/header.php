<?php
// THEMES/DEFAULT/INCLUDES/HEADER.PHP
@$SECURE or die('Access Denied!');

// GET CURRENT LANGUAGE (COUNTRY ISO FROM DB DRIVES FLAGS)
$currentLang = $_SESSION['app_language'] ?? 'en';
$assetVersion = rawurlencode((string)($GLOBALS['app']['version'] ?? '1.0.0'));

// ADMIN SIDEBAR FIRST-PAINT STATE: THE SIDEBAR TOGGLE PERSISTS ITS STATE IN A
// COOKIE (SERVER-READABLE, UNLIKE localStorage) SO PHP RENDERS THE CORRECT BODY
// OFFSET AND RAIL WIDTH IN THE VERY FIRST PAINT — NO LAYOUT JUMP AT ANY POINT.
$isAdminSidebar   = (($_SESSION['user_role'] ?? '') === 'admin');
$sidebarExpanded  = $isAdminSidebar && (($_COOKIE['pt_sidebar'] ?? '') === 'expanded');
$sidebarBodyClass = $isAdminSidebar ? ($sidebarExpanded ? ' sidebar-open' : ' sidebar-closed') : '';
$headerPages = cms($db, "header", "headerfooter");

if (!function_exists('flagAssetPath')) {
  function flagAssetPath($flagCode) {
    static $availableFlags = null;

    if ($availableFlags === null) {
      $availableFlags = [];
      $flagsDir = __DIR__ . '/../../../assets/img/flags';
      foreach (glob($flagsDir . '/*.svg') as $flagFile) {
        $availableFlags[basename($flagFile, '.svg')] = true;
      }
    }

    $code = strtolower(trim((string)$flagCode));
    if ($code !== '' && isset($availableFlags[$code])) {
      return root . "assets/img/flags/{$code}.svg";
    }

    return root . 'assets/img/flags/xx.svg';
  }
}

// ORGANIZE HEADER PAGES: PARENTS WITH CHILDREN + STANDALONE PAGES
$header_menus = [];
$header_standalone = [];
$children_map = [];

// GROUP CHILDREN BY PARENT_ID
foreach ($headerPages as $page) {
    if ($page['parent_id']) {
        $children_map[$page['parent_id']][] = $page;
    }
}

// SEPARATE PARENTS WITH CHILDREN VS STANDALONE PAGES
foreach ($headerPages as $page) {
    if (!$page['parent_id']) {
        if (isset($children_map[$page['id']]) && !empty($children_map[$page['id']])) {
            // PARENT WITH CHILDREN - DROPDOWN MENU
            $header_menus[] = [
                'parent' => $page,
                'children' => $children_map[$page['id']]
            ];
        } else {
            // STANDALONE PAGE - SINGLE LINK
            $header_standalone[] = $page;
        }
    }
}

// ICONS FOR HEADER NAV ITEMS — DESIGN-SYSTEM STROKE SVGS (1.5 STROKE, currentColor),
// MATCHED BY PAGE SLUG/NAME WITH SENSIBLE DEFAULTS
if (!function_exists('headerNavIcon')) {
  function headerNavIcon($page, $isParent = false) {
    $key = strtolower(trim((string)(($page['slug_url'] ?? '') ?: ($page['page_name'] ?? ''))));
    $icons = [
      'grid'     => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"></rect><rect x="13.5" y="3.5" width="7" height="7" rx="2"></rect><rect x="3.5" y="13.5" width="7" height="7" rx="2"></rect><rect x="13.5" y="13.5" width="7" height="7" rx="2"></rect>',
      'building' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"></path><path d="M16 8h2a2 2 0 0 1 2 2v11"></path><path d="M3 21h18"></path><path d="M8 7h2"></path><path d="M8 11h2"></path><path d="M8 15h2"></path>',
      'article'  => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"></path><path d="M18 14h-8"></path><path d="M15 18h-5"></path><path d="M10 6h8v4h-8V6Z"></path>',
      'info'     => '<circle cx="12" cy="12" r="9.25"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
      'phone'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92Z"></path>',
      'headset'  => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm18 0h-3a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2Z"></path><path d="M21 14v-2a9 9 0 0 0-18 0v2"></path>',
      'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M16 13H8"></path><path d="M16 17H8"></path><path d="M10 9H8"></path>',
      'folder'   => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"></path>',
    ];
    $map = [
      'services' => 'grid',
      'company' => 'building', 'about' => 'info', 'about-us' => 'info',
      'blogs' => 'article', 'blog' => 'article', 'news' => 'article',
      'contact' => 'phone', 'contact-us' => 'phone',
      'support' => 'headset', 'help' => 'headset', 'faq' => 'info',
    ];
    $name = $map[$key] ?? ($isParent ? 'folder' : 'file');
    // ICON INHERITS THE NAV LINK TEXT COLOR (currentColor) SO ICON + LABEL STAY
    // ONE COLOR AND TRANSITION TOGETHER ON HOVER — MATCHES THE HOME.PHP TABS.
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">' . $icons[$name] . '</svg>';
  }
}

// ============================================================
// SHARED SERVICES LOGIC (PRE-PROCESS FOR ALL DEVICES)
// ============================================================
$enabledModules = $GLOBALS['modules'] ?? [];
$processedServices = [];
if (!empty($enabledModules)) {
    $grouped = array_reduce($enabledModules, function($c, $m) {
        $c[$m['type']] = $c[$m['type']] ?? ['type' => $m['type'], 'name' => $m['type'], 'order' => $m['order'], 'icon' => $m['icon'] ?? ''];
        return $c;
    }, []);
    $processedServices = array_values($grouped);
    usort($processedServices, fn($a, $b) => $a['order'] <=> $b['order']);

    // MATERIAL SYMBOL FALLBACKS PER MODULE TYPE — same icons the hero search
    // tabs show when a module row has no icon of its own.
    $serviceIconMap = [
        'flights' => 'flight_takeoff',
        'stays'   => 'hotel',
        'cars'    => 'directions_car',
        'tours'   => 'explore',
        'visa'    => 'approval',
        'cruises' => 'directions_boat',
        'umrah'   => 'mosque',
        'rail'    => 'train',
        'bus'     => 'directions_bus',
        'esim'    => 'sim_card',
        'insurance' => 'health_and_safety',
    ];

    // PRE-CALCULATE DISPLAY NAMES AND LINKS TO AVOID REPETITIVE LOGIC IN LOOPS
    foreach ($processedServices as &$svc) {
        $mName = $svc['type'];

        // Module icon is a Material Symbol name (like the hero search tabs).
        // Supplier logo filenames are admin-only — fall back to the type map.
        $svcIcon = trim((string)($svc['icon'] ?? ''));
        if ($svcIcon === '' || preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $svcIcon)) {
            $svcIcon = $serviceIconMap[$mName] ?? 'confirmation_number';
        }
        $svc['icon'] = $svcIcon;
        $svc['label'] = ucfirst($mName);
        if ($mName == 'flights') $svc['label'] = ucfirst(T::flights);
        elseif ($mName == 'stays') $svc['label'] = ucfirst(T::stays);
        elseif ($mName == 'cars') $svc['label'] = ucfirst(T::cars);
        elseif ($mName == 'visa') $svc['label'] = ucfirst(T::visa);
        elseif ($mName == 'tours') $svc['label'] = ucfirst(T::tours);
        elseif ($mName == 'cruises') $svc['label'] = ucfirst(T::cruises);
        elseif ($mName == 'umrah') $svc['label'] = ucfirst(T::umrah);
        elseif ($mName == 'rail') $svc['label'] = ucfirst(T::rail ?? 'Rail');
        elseif ($mName == 'insurance') $svc['label'] = 'Compensation';

        $svc['href'] = root . htmlspecialchars($mName);
    }
    unset($svc); // BREAK REFERENCE
}

// SHARED CURRENCY LOGIC (step 3): priority is
//   1. MANUAL choice (user switched currency; app_currency_changed=true) — always wins
//   2. GEO currency (visitor's country -> enabled currency, detected once/session)
//   3. DB default currency
//   4. first currency
$currencies = $GLOBALS['currencies'] ?? [];
$sessionCurrencyCode = !empty($_SESSION['app_currency_changed'])
  ? strtoupper(trim((string)($_SESSION['app_currency'] ?? '')))
  : '';

// Geo currency only matters when the user has NOT manually chosen one.
// detectGeoCurrency() resolves its own DB handle from the global if needed and
// fails safe (returns '') — so this never breaks the page even if $db is not in
// this include's local scope.
$geoCurrencyCode = '';
if ($sessionCurrencyCode === '' && function_exists('detectGeoCurrency')) {
    $geoDbHandle = ($db ?? null) instanceof \Medoo\Medoo ? $db : ($GLOBALS['db'] ?? null);
    $geoCurrencyCode = strtoupper(trim((string) detectGeoCurrency($geoDbHandle)));
}

$sessionCurrencyRow = null;
$geoCurrencyRow = null;
$defaultCurrencyRow = null;

foreach ($currencies as $curr) {
    $currencyCode = strtoupper((string)($curr['name'] ?? ''));
    if ($currencyCode === '') {
        continue;
    }

    if ($sessionCurrencyCode !== '' && $currencyCode === $sessionCurrencyCode) {
        $sessionCurrencyRow = $curr;
    }

    if ($geoCurrencyCode !== '' && $currencyCode === $geoCurrencyCode) {
        $geoCurrencyRow = $curr;
    }

    if ($defaultCurrencyRow === null && (string)($curr['default'] ?? '0') === '1') {
        $defaultCurrencyRow = $curr;
    }
}

$activeCurrencyRow = $sessionCurrencyRow ?? $geoCurrencyRow ?? $defaultCurrencyRow ?? ($currencies[0] ?? null);
$activeCurrencyCode = strtoupper((string)($activeCurrencyRow['name'] ?? 'USD'));
$activeCurrencyFlag = !empty($activeCurrencyRow['country']) ? strtolower($activeCurrencyRow['country']) : 'xx';

if (!empty($activeCurrencyRow)) {
    $_SESSION['app_currency'] = $activeCurrencyCode;
    $_SESSION['app_currency_rate'] = $activeCurrencyRow['rate'] ?? ($_SESSION['app_currency_rate'] ?? 1);
    $_SESSION['app_currency_country'] = $activeCurrencyRow['country'] ?? ($_SESSION['app_currency_country'] ?? '');
    $_SESSION['app_currency_country_name'] = $activeCurrencyRow['country_name'] ?? ($_SESSION['app_currency_country_name'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['app_language'] ?? 'en' ?>" dir="<?= $_SESSION['app_language_dir'] ?? 'ltr' ?>">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?=CSRF::getToken()?>">
    <link rel="shortcut icon" href="<?=versionedAssetUrl('uploads/global/favicon.png')?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://code.jquery.com">

    <script>document.documentElement.classList.add('js');</script>

    <!-- CRITICAL FIRST-PAINT CSS (OVERLAY-ONLY LOADER, NEVER HIDE BODY) -->
    <style>
      /* LOCK SCROLL ONLY WHILE THE LOADER OVERLAY IS VISIBLE (AUTO-RELEASES ON FADE) */
      html:has(.page-loader:not(.hide)) { overflow: hidden; }
      .page-loader { position: fixed; inset: 0; background: #fff; z-index: 999999; display: flex; align-items: center; justify-content: center; opacity: 1; transition: opacity .18s ease-out; will-change: opacity; }
      .page-loader.hide { opacity: 0; pointer-events: none; }
      /* SUBTLE, SMOOTH SCROLLBAR ONCE THE PAGE IS INTERACTIVE */
      html { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
      ::-webkit-scrollbar { width: 10px; height: 10px; }
      ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; border: 2px solid transparent; background-clip: content-box; transition: background .2s ease; }
      ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }
      ::-webkit-scrollbar-track { background: transparent; }
      /* HEADER NAV — THEME-AWARE HOVER WITHOUT INLINE JS */
      .nav-link { color: var(--header-link-color); transition: color .2s ease, background-color .2s ease; }
      .nav-link:hover { color: var(--header-link-hover-color); }
      .nav-link svg { stroke: currentColor; transition: stroke .2s ease; }
      <?php if ($isAdminSidebar): ?>
      /* ADMIN SIDEBAR LAYOUT — CRITICAL: THE BODY CLASS IS PRINTED SERVER-SIDE,
         SO THE CONTENT OFFSET IS CORRECT FROM THE VERY FIRST PAINT */
      @media (min-width: 1024px) {
        body.sidebar-open { padding-left: 240px; }
        body.sidebar-closed { padding-left: 64px; }
        body.sidebar-open header,
        body.sidebar-closed header { transition: none !important; }
      }
      @media (max-width: 1023px) {
        body.sidebar-open, body.sidebar-closed { padding-left: 0; }
      }
      /* MUST MATCH THE RAIL EXACTLY (transition-all duration-200 ease-linear = 0.2s linear) */
      body { transition: padding-left 0.2s linear; }
      <?php endif; ?>
    </style>

    <?php require_once "app/views/tailwind.php"; ?>

    <!-- Tailwind is now PRECOMPILED at build time (npm run build:css →
         assets/css/tailwind.build.css) instead of compiled in the browser via
         cdn.tailwindcss.com. This eliminates the runtime-compile flash (the
         blue-button/black-text race behind PRs #100/#101) and stops shipping the
         ~120KB compiler to every visitor. The theme-driven :root CSS variables
         still come from tailwind.php above. See docs/TAILWIND-BUILD.md. -->
    <link rel="stylesheet" href="<?=versionedAssetUrl('assets/css/tailwind.build.css')?>">

    <script defer src="<?=versionedAssetUrl('assets/js/app.js')?>"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://code.jquery.com/jquery-3.7.1.min.js" data-cfasync="false"></script>
    <link rel="stylesheet" href="<?=versionedAssetUrl('assets/css/app.css')?>">

    <!-- META -->
    <?php
    // SEO fallbacks: ~half of the route files never set $title/$description, so
    // those pages would otherwise render an EMPTY <title>/description (bad for
    // SEO). Fall back to the global site values from settings ($GLOBALS['app'])
    // — verified fields: home_title, meta_description, site_keywords.
    $seoBrand       = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['home_title'] ?? 'Goglobia');
    $seoTitle       = (isset($title) && trim((string)$title) !== '') ? $title : ($GLOBALS['app']['home_title'] ?? $seoBrand);
    $seoDescription = (isset($description) && trim((string)$description) !== '') ? $description : ($GLOBALS['app']['meta_description'] ?? '');
    $seoKeywords    = (isset($keywords) && trim((string)$keywords) !== '') ? $keywords : ($GLOBALS['app']['site_keywords'] ?? '');
    ?>
    <title><?= htmlspecialchars($seoTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonical ?? ((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])) ?>">
    <meta name="author" content="<?= htmlspecialchars((string) ($GLOBALS['app']['business_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="<?= htmlspecialchars($robots ?? 'index, follow') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical ?? ((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage ?? versionedAssetUrl('uploads/global/cover.png')) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars((string) ($GLOBALS['app']['business_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type" content="<?= htmlspecialchars($ogType ?? 'website') ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($_SESSION['app_language'] ?? 'en') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage ?? versionedAssetUrl('uploads/global/cover.png')) ?>">

    <?php
    // -----------------------------------------------------------------------
    // STRUCTURED DATA (JSON-LD) — Organization + WebSite.
    // Built ONLY from verified settings fields; each optional field is included
    // only when non-empty so we never emit a broken/empty value. `sameAs`
    // (social profiles) is intentionally omitted: the settings table has no
    // social-profile columns, and inventing URLs would be wrong. Emitted once,
    // sitewide, on the public frontend.
    // -----------------------------------------------------------------------
    if (!$isAdminSidebar) {
        $ldSiteUrl = rtrim((string)($GLOBALS['app']['site_url'] ?? (defined('root') ? root : '')), '/');
        $orgLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => (string)($GLOBALS['app']['business_name'] ?? 'Goglobia'),
        ];
        if ($ldSiteUrl !== '')                              $orgLd['url']   = $ldSiteUrl;
        if ($ldSiteUrl !== '')                              $orgLd['logo']  = $ldSiteUrl . '/uploads/global/logo.png';
        if (!empty($GLOBALS['app']['contact_phone']))       $orgLd['telephone'] = (string)$GLOBALS['app']['contact_phone'];
        if (!empty($GLOBALS['app']['contact_email']))       $orgLd['email']     = (string)$GLOBALS['app']['contact_email'];
        if (!empty($GLOBALS['app']['address'])) {
            $orgLd['address'] = [
                '@type'         => 'PostalAddress',
                'streetAddress' => (string)$GLOBALS['app']['address'],
            ];
        }

        $siteLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => (string)($GLOBALS['app']['business_name'] ?? 'Goglobia'),
        ];
        if ($ldSiteUrl !== '') {
            $siteLd['url'] = $ldSiteUrl;
            // Sitelinks search box → the site's flights search entry point.
            $siteLd['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $ldSiteUrl . '/flights?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }
        ?>
    <script type="application/ld+json"><?= json_encode($orgLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/ld+json"><?= json_encode($siteLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php } ?>

    <!-- PWA -->
    <link rel="manifest" href="<?=root?>manifest.webmanifest">
    <meta name="theme-color" content="#3b82f6">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Goglobia">
    <link rel="apple-touch-icon" href="<?=root?>assets/pwa/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?=root?>assets/pwa/icon-192.png">
    <script>
      // Register the service worker at the APP BASE PATH (root may be a subpath
      // like /goglobia/). The SW URL is absolute same-origin; the scope is
      // derived from that URL's PATH (not a full URL) so it is unambiguous and
      // correct whether the app runs at a subpath (local) or the domain root
      // (prod). A SW can only control a scope at or below its own path, so this
      // always matches. Deferred to load so it never blocks first paint.
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          try {
            var swUrl = '<?=root?>sw.js';
            var scopePath = new URL(swUrl, location.href).pathname.replace(/sw\.js$/, '');
            navigator.serviceWorker.register(swUrl, { scope: scopePath })
              .catch(function (e) { /* non-fatal: PWA is a progressive enhancement */ });
          } catch (e) { /* non-fatal */ }
        });
      }
    </script>

  </head>
  <body class="bg-background text-foreground<?= $sidebarBodyClass ?>">

    <?php
    // Flash set by a route that had to correct the request (e.g. a hand-typed
    // stay URL longer than the portal's maximum stay length).
    if (!empty($_SESSION['stays_search_notice'])) {
        $staysSearchNotice = $_SESSION['stays_search_notice'];
        unset($_SESSION['stays_search_notice']);
        if ($staysSearchNotice === 'max_stay_nights') {
            $staysNoticeText = str_replace(
                '{nights}',
                (string) (function_exists('staysMaxStayNights') ? staysMaxStayNights() : 30),
                T::max_stay_nights_exceeded
            );
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.vt && typeof window.vt.warn === 'function') {
                        window.vt.warn(<?= json_encode($staysNoticeText) ?>);
                    }
                });
            </script>
            <?php
        }
    }
    ?>

    <!-- PAGE LOADER (RENDERED IMMEDIATELY, FADES OUT BY APP.JS ONCE FULLY LOADED) -->
    <!-- <div id="page-loader" class="page-loader">
      <?php // include __DIR__ . '/loader.php'; ?>
    </div> -->

    <?php if (($header ?? true) !== false) { ?>
    <!-- parts/loadPart: DROPDOWN CONTENT (CURRENCIES/LANGUAGES) IS FETCHED FROM
         partials/* ONLY WHEN A DROPDOWN IS FIRST OPENED — KEEPS FIRST-PAINT DOM SMALL -->
    <header class="sticky top-0 z-50 w-full shadow-sm" x-data="{ mobileMenuOpen: false, parts: {}, loadPart(name) { if (!this.parts[name]) fetch('<?=root?>partials/' + name).then(r => r.ok ? r.text() : '').then(t => { if (t) this.parts[name] = t }) } }" style="background-color: var(--header-background); color: var(--header-text-color); font-size: var(--header-font-size); border-bottom: var(--header-border-width)px solid var(--header-border-color); height: var(--header-height)px; padding-left: var(--header-padding-x)px; padding-right: var(--header-padding-x)px; box-shadow: var(--header-shadow);">
      <div class="container mx-auto">
        <div class="flex h-16 items-center justify-between">
          <!-- LOGO + NAVIGATION -->
          <div class="flex items-center gap-4">
            <a href="<?=root?>" class="flex items-center">
              <img src="<?=versionedAssetUrl('uploads/global/logo.png')?>" alt="<?=$GLOBALS['app']['business_name']?>" class="h-8 w-auto" width="160" height="40" decoding="async">
            </a>

            <!-- DESKTOP NAVIGATION -->
            <nav class="hidden lg:flex items-center font-medium">
            <!-- DYNAMIC SERVICES DROPDOWN -->
            <?php if (!empty($processedServices)): ?>
            <div class="relative group">
                <button class="nav-link flex items-center gap-1.5 px-4 py-2 rounded-full hover:bg-gray-50">
                  <?= headerNavIcon(['slug_url' => 'services']) ?>
                  <span class="font-medium"><?= ucfirst(T::services) ?></span>
                  <span class="material-symbols-outlined !text-[18px] transition-transform group-hover:rotate-180">expand_more</span>
                </button>
                <!-- INVISIBLE BRIDGE TO PREVENT DROPDOWN CLOSE -->
                <div class="absolute top-full left-0 h-2 w-full pointer-events-none group-hover:pointer-events-auto"></div>
                <div class="absolute top-full left-0 mt-1 w-56 rounded-md border border-gray-200 bg-white p-1 shadow-lg opacity-0 invisible translate-y-[-8px] transition-all duration-150 ease-out group-hover:opacity-100 group-hover:visible group-hover:translate-y-0">
                  <?php foreach ($processedServices as $svc): ?>
                  <a href="<?= $svc['href'] ?>" class="flex items-center gap-2 px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors">
                    <span class="material-symbols-outlined !text-[18px] text-gray-500"><?= htmlspecialchars($svc['icon']) ?></span>
                    <span><?= ucwords($svc['label']) ?> <?= T::booking ?></span>
                  </a>
                  <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- DYNAMIC CMS DROPDOWNS (PARENTS WITH CHILDREN) -->
            <?php if (!empty($header_menus)): ?>
              <?php foreach ($header_menus as $menu): ?>
              <div class="relative group">
                <button class="nav-link flex items-center gap-1.5 px-4 py-2 rounded-full hover:bg-gray-50">
                  <?= headerNavIcon($menu['parent'], true) ?>
                  <span class="font-medium"><?= htmlspecialchars(getTranslatedPageName($menu['parent'], $currentLang)) ?></span>
                  <span class="material-symbols-outlined !text-[18px] transition-transform group-hover:rotate-180">expand_more</span>
                </button>
                <!-- INVISIBLE BRIDGE TO PREVENT DROPDOWN CLOSE -->
                <div class="absolute top-full left-0 h-2 w-full pointer-events-none group-hover:pointer-events-auto"></div>
                <div class="absolute top-full left-0 mt-1 w-56 rounded-md border border-gray-200 bg-white p-1 shadow-lg opacity-0 invisible translate-y-[-8px] transition-all duration-150 ease-out group-hover:opacity-100 group-hover:visible group-hover:translate-y-0">
                  <?php foreach ($menu['children'] as $child): ?>
                  <?php
                  $href = !empty($child['external_url'])
                    ? htmlspecialchars($child['external_url'])
                    : root . 'page/' . htmlspecialchars($child['slug_url']);
                  ?>
                  <a href="<?= $href ?>" class="block px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors"
                    <?= !empty($child['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <?= htmlspecialchars(getTranslatedPageName($child, $currentLang)) ?>
                  </a>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <!-- DYNAMIC CMS STANDALONE LINKS -->
            <?php if (!empty($header_standalone)): ?>
              <?php foreach ($header_standalone as $page): ?>
              <?php
              $href = !empty($page['external_url'])
                ? htmlspecialchars($page['external_url'])
                : root . 'page/' . htmlspecialchars($page['slug_url']);
              ?>
              <a href="<?= $href ?>" class="nav-link flex items-center gap-1.5 px-4 py-2 rounded-full font-medium hover:bg-gray-50"
                <?= !empty($page['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?= headerNavIcon($page) ?>
                <span><?= htmlspecialchars(getTranslatedPageName($page, $currentLang)) ?></span>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
            </nav>
          </div>

          <!-- CTA BUTTONS -->
          <div class="flex items-center gap-2">
            <!-- DESKTOP CONTROL GROUP — ALL CONTROLS IN ONE ROUNDED-FULL PILL BAR -->
            <div class="hidden lg:flex items-center gap-1 p-1 bg-white border border-gray-200 rounded-full shadow-sm">
            <!-- COMBINED CURRENCY + LANGUAGE SWITCHER - DESKTOP ONLY -->
            <?php
            $showLang = ($GLOBALS['app']['multi_language'] ?? '1') != '0';
            $showCurr = ($GLOBALS['app']['multi_currency'] ?? '1') != '0';
            if ($showLang || $showCurr):
              // CURRENT LANGUAGE
              $currentLangCode = $_SESSION['app_language'] ?? 'en';
              $currentLangName = 'English';
              $currentLangFlag = 'xx';
              foreach ($GLOBALS['languages'] as $lang) {
                if ($lang['lang_code'] === $currentLangCode) {
                  $currentLangName = $lang['name'];
                  $currentLangFlag = !empty($lang['country']) ? strtolower($lang['country']) : 'xx';
                  break;
                }
              }
              // CURRENT CURRENCY
              $currentCurrency = $activeCurrencyCode;
              $currentCurrencyFlag = $activeCurrencyFlag;
            ?>
            <div class="relative"
                 x-data="{ open: false, tab: '<?= $showCurr ? 'currency' : 'language' ?>' }"
                 @click.away="open = false"
                 @keydown.escape.window="open = false">
              <div class="flex items-center gap-1 text-sm font-medium text-gray-700 rounded-full">
                <?php if ($showCurr): ?>
                <button type="button"
                        @click="loadPart('currencies'); if(open && tab==='currency'){ open=false } else { tab='currency'; open=true }"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full hover:bg-gray-100 transition-colors"
                        :class="open && tab==='currency' ? 'bg-gray-100' : ''">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-700">
                    <g opacity="0.4">
                      <path d="M9.5 13.7502C9.5 14.7202 10.25 15.5002 11.17 15.5002H13.05C13.85 15.5002 14.5 14.8202 14.5 13.9702C14.5 13.0602 14.1 12.7302 13.51 12.5202L10.5 11.4702C9.91 11.2602 9.51001 10.9402 9.51001 10.0202C9.51001 9.18023 10.16 8.49023 10.96 8.49023H12.84C13.76 8.49023 14.51 9.27023 14.51 10.2402" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                      <path d="M12 7.5V16.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </g>
                    <path d="M22 12C22 17.52 17.52 22 12 22C6.48 22 2 17.52 2 12C2 6.48 6.48 2 12 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M22 6V2H18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M17 7L22 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                  <span class="font-semibold"><?= htmlspecialchars($currentCurrency) ?></span>
                </button>
                <?php endif; ?>
                <?php if ($showLang && $showCurr): ?>
                <span class="w-px h-4 bg-gray-200"></span>
                <?php endif; ?>
                <?php if ($showLang): ?>
                <button type="button"
                        @click="loadPart('languages'); if(open && tab==='language'){ open=false } else { tab='language'; open=true }"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full hover:bg-gray-100 transition-colors"
                        :class="open && tab==='language' ? 'bg-gray-100' : ''">
                  <img src="<?= root . 'assets/img/flags/' . $currentLangFlag ?>.svg" alt="<?= htmlspecialchars($currentLangName) ?> flag" class="w-5 h-5 object-cover rounded-full border border-gray-200" style="background: #f3f4f6;" loading="lazy">
                  <span class="font-semibold uppercase"><?= htmlspecialchars($currentLangCode) ?></span>
                </button>
                <?php endif; ?>
              </div>

              <div x-show="open"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-2"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-100 translate-y-0"
                   x-transition:leave-end="opacity-0 -translate-y-2"
                   class="absolute top-full left-0 mt-2 w-72 rounded-2xl border border-gray-200 bg-white shadow-xl z-50 overflow-hidden"
                   style="display:none;">

                <?php if ($showCurr && $showLang): ?>
                <!-- TABS -->
                <div class="flex items-center gap-1 p-1.5 border-b border-gray-100 bg-gray-50">
                  <button @click="tab = 'currency'; loadPart('currencies')"
                          class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold uppercase tracking-wide rounded-lg transition-all"
                          :class="tab === 'currency' ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                    <span class="material-symbols-outlined !text-[16px]">payments</span>
                    <span><?= T::currency ?? 'Currency' ?></span>
                  </button>
                  <button @click="tab = 'language'; loadPart('languages')"
                          class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold uppercase tracking-wide rounded-lg transition-all"
                          :class="tab === 'language' ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                    <span class="material-symbols-outlined !text-[16px]">translate</span>
                    <span><?= T::language ?? 'Language' ?></span>
                  </button>
                </div>
                <?php else: ?>
                <!-- SINGLE LABEL HEADER -->
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                  <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                    <span class="material-symbols-outlined !text-[16px]"><?= $showCurr ? 'paid' : 'translate' ?></span>
                    <span><?= $showCurr ? (T::currency ?? 'Currency') : (T::language ?? 'Language') ?></span>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($showCurr): ?>
                <!-- CURRENCY LIST (LAZY: FETCHED FROM partials/currencies ON FIRST OPEN) -->
                <div x-show="tab === 'currency'" class="max-h-[420px] overflow-y-auto p-2">
                  <div x-show="!parts.currencies" class="flex items-center justify-center py-10">
                    <svg class="animate-spin text-primary" width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                  </div>
                  <div x-html="parts.currencies"></div>
                </div>
                <?php endif; ?>

                <?php if ($showLang): ?>
                <!-- LANGUAGE LIST (LAZY: FETCHED FROM partials/languages ON FIRST OPEN) -->
                <div x-show="tab === 'language'" class="max-h-[420px] overflow-y-auto p-2"<?= $showCurr ? ' style="display:none;"' : '' ?>>
                  <div x-show="!parts.languages" class="flex items-center justify-center py-10">
                    <svg class="animate-spin text-primary" width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                  </div>
                  <div x-html="parts.languages"></div>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- SEPARATOR BETWEEN SWITCHER AND AUTH -->
            <?php if ($showLang || $showCurr): ?>
            <span class="w-px h-5 bg-gray-200 mx-0.5"></span>
            <?php endif; ?>

            <?php // CART ICON + COUNT BADGE (general cart: tours/visa/esim) — step 6b
                  $__cartCount = (is_array($_SESSION['cart'] ?? null) ? count($_SESSION['cart']) : 0); ?>
            <a href="<?= root ?>cart" class="relative flex items-center px-3 py-2 text-gray-700 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-all duration-200" title="Your cart" aria-label="Your cart"
               x-data="{ count: <?= $__cartCount ?> }"
               @cart:updated.window="count = ($event.detail && typeof $event.detail.count !== 'undefined') ? $event.detail.count : count">
              <span class="material-symbols-outlined !text-[20px]">shopping_cart</span>
              <span x-show="count > 0" x-cloak class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-primary text-white text-[11px] font-bold leading-[18px] text-center" x-text="count"></span>
            </a>

            <?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
            <!-- USER DROPDOWN - DESKTOP ONLY -->
            <div class="relative group">
              <button class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-all duration-200">
                <span class="material-symbols-outlined !text-[20px]">account_circle</span>
                <span class="hidden lg:inline font-semibold"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                <span class="material-symbols-outlined !text-[16px]">expand_more</span>
              </button>
              <!-- INVISIBLE BRIDGE TO PREVENT DROPDOWN CLOSE -->
              <div class="absolute top-full right-0 h-2 w-full pointer-events-none group-hover:pointer-events-auto"></div>
              <div class="absolute top-full right-0 mt-2 w-64 rounded-2xl border border-gray-200 bg-white shadow-xl opacity-0 invisible translate-y-[-8px] transition-all duration-200 ease-out group-hover:opacity-100 group-hover:visible group-hover:translate-y-0 z-50 overflow-hidden">
                <div class="p-3 bg-primary/5 border-b border-gray-200">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary !text-[32px]">account_circle</span>
                    <div>
                      <p class="text-sm font-bold text-gray-900"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></p>
                      <p class="text-xs text-gray-600"><?= htmlspecialchars($_SESSION['user_role'] ?? 'Member') ?></p>
                    </div>
                  </div>
                </div>
                <div class="p-2">
                  <a href="<?=root?>dashboard" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">dashboard</span>
                    <span class="font-medium"><?= T::dashboard ?></span>
                  </a>
                  <a href="<?=root?>profile" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">person</span>
                    <span class="font-medium"><?= T::profile ?></span>
                  </a>
                  <a href="<?=root?>bookings" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">calendar_month</span>
                    <span class="font-medium"><?= T::my_bookings ?></span>
                  </a>
                  <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                  <a href="<?=root?>admin/settings" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">settings</span>
                    <span class="font-medium"><?= T::settings ?></span>
                  </a>
                  <a href="<?=root?>profile#change-password" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">password</span>
                    <span class="font-medium"><?= T::change_password ?></span>
                  </a>
                  <?php endif; ?>
                  <div class="h-px bg-gray-200 my-2"></div>
                  <a href="<?=root?>logout" class="flex items-center gap-3 px-4 py-3 text-sm text-red-600 hover:text-red-700 hover:bg-red-50 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px]">logout</span>
                    <span class="font-medium"><?= T::logout ?></span>
                  </a>
                </div>
              </div>
            </div>
            <?php else: ?>
            <!-- AUTH BUTTONS — LOGIN IS A PLAIN SEGMENT; SIGNUP IS THE TRAILING ROUNDED BUTTON -->
            <?php
            $showSignupDesktop = ($GLOBALS['app']['user_registration'] ?? '1') != '0';
            $loginHref = root . 'login';
            ?>
            <a href="<?= htmlspecialchars($loginHref) ?>" class="<?= $showSignupDesktop
                ? 'inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-700 rounded-full hover:bg-gray-100 transition-colors'
                : 'inline-flex btn !rounded-full' ?>">
              <span class="material-symbols-outlined !text-[18px]">login</span>
              <span><?= T::login ?></span>
            </a>

            <?php if ($showSignupDesktop): ?>
            <?php if (($GLOBALS['app']['agent_registration'] ?? 0) != 0): ?>
            <!-- SIGNUP DROPDOWN (WHEN AGENT REGISTRATION ENABLED) -->
            <div class="relative group">
              <button class="btn !rounded-full flex items-center gap-1.5">
                <span class="material-symbols-outlined !text-[18px]">person_add</span>
                <span><?= T::signup ?></span>
                <span class="material-symbols-outlined !text-[16px] transition-transform group-hover:rotate-180">expand_more</span>
              </button>
              <!-- INVISIBLE BRIDGE TO PREVENT DROPDOWN CLOSE -->
              <div class="absolute top-full right-0 h-2 w-full pointer-events-none group-hover:pointer-events-auto"></div>
              <div class="absolute top-full right-0 mt-2 w-56 rounded-2xl border border-gray-200 bg-white shadow-xl opacity-0 invisible translate-y-[-8px] transition-all duration-200 ease-out group-hover:opacity-100 group-hover:visible group-hover:translate-y-0 z-50 overflow-hidden">
                <div class="p-2">
                  <a href="<?=root?>signup" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px] text-primary">person</span>
                    <span class="font-medium"><?= T::customer ?? 'Customer' ?> <?= T::signup ?></span>
                  </a>
                  <a href="<?=root?>agent-signup" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:text-primary hover:bg-primary/5 rounded-xl transition-all duration-150">
                    <span class="material-symbols-outlined !text-[18px] text-primary">business_center</span>
                    <span class="font-medium"><?= T::agent ?> <?= T::signup ?></span>
                  </a>
                </div>
              </div>
            </div>
            <?php else: ?>
            <!-- SIMPLE SIGNUP BUTTON (WHEN AGENT REGISTRATION DISABLED) -->
            <a href="<?=root?>signup" class="inline-flex btn !rounded-full">
              <span class="material-symbols-outlined !text-[18px]">person_add</span>
              <span><?= T::signup ?></span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
            </div><!-- END DESKTOP CONTROL GROUP -->

            <!-- MOBILE MENU BUTTON -->
            <button @click="mobileMenuOpen = !mobileMenuOpen" class="lg:hidden inline-flex items-center justify-center p-2 text-gray-700 hover:text-gray-900 hover:bg-gray-50 rounded-full border border-gray-200 transition-all duration-200">
              <span class="material-symbols-outlined !text-[24px]" x-text="mobileMenuOpen ? 'close' : 'menu'">menu</span>
            </button>
          </div>
        </div>
      </div>

      <!-- MOBILE MENU -->
      <div x-show="mobileMenuOpen"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 -translate-y-2"
           x-transition:enter-end="opacity-100 translate-y-0"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="opacity-100 translate-y-0"
           x-transition:leave-end="opacity-0 -translate-y-2"
           @click.away="mobileMenuOpen = false"
           class="lg:hidden fixed top-16 left-0 right-0 bg-white border-b border-gray-200 shadow-2xl z-40 max-h-[calc(100vh-64px)] overflow-y-auto">
        <div class="px-4 py-6 space-y-4" x-data="{
          openDropdowns: {},
          toggleDropdown(id) {
            this.openDropdowns[id] = !this.openDropdowns[id];
          }
        }">
          <!-- DYNAMIC SERVICES DROPDOWN MOBILE -->
          <?php if (!empty($processedServices)): ?>
          <div class="space-y-2">
            <button @click="toggleDropdown('services')" class="w-full flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-900 hover:bg-gray-50 rounded-xl transition-colors border border-gray-200">
              <span class="flex items-center gap-2">
                <?= headerNavIcon(['slug_url' => 'services']) ?>
                <span><?= ucfirst(T::services) ?></span>
              </span>
              <span class="material-symbols-outlined transition-transform" :class="openDropdowns['services'] ? 'rotate-180' : ''">expand_more</span>
            </button>
            <div x-show="openDropdowns['services']"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="pl-4 space-y-2 mt-2">
              <?php foreach ($processedServices as $svc): ?>
              <a href="<?= $svc['href'] ?>" class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-xl transition-colors">
                <span class="material-symbols-outlined !text-[18px]"><?= htmlspecialchars($svc['icon']) ?></span>
                <span><?= $svc['label'] ?> <?= T::booking ?></span>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <!-- DYNAMIC CMS DROPDOWNS (PARENTS WITH CHILDREN) -->
          <?php if (!empty($header_menus)): ?>
            <?php foreach ($header_menus as $index => $menu): ?>
            <div class="space-y-2">
              <button @click="toggleDropdown('cms<?= $index ?>')" class="w-full flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-900 hover:bg-gray-50 rounded-xl transition-colors border border-gray-200">
                <span class="flex items-center gap-2">
                  <?= headerNavIcon($menu['parent'], true) ?>
                  <span><?= htmlspecialchars(getTranslatedPageName($menu['parent'], $currentLang)) ?></span>
                </span>
                <span class="material-symbols-outlined transition-transform" :class="openDropdowns['cms<?= $index ?>'] ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div x-show="openDropdowns['cms<?= $index ?>']"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-100 translate-y-0"
                   x-transition:leave-end="opacity-0 -translate-y-1"
                   class="pl-4 space-y-2 mt-2">
                <?php foreach ($menu['children'] as $child): ?>
                <?php
                $href = !empty($child['external_url'])
                  ? htmlspecialchars($child['external_url'])
                  : root . 'page/' . htmlspecialchars($child['slug_url']);
                ?>
                <a href="<?= $href ?>" class="block px-4 py-2.5 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-xl transition-colors"
                  <?= !empty($child['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                  <?= htmlspecialchars(getTranslatedPageName($child, $currentLang)) ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- DYNAMIC CMS STANDALONE LINKS -->
          <?php if (!empty($header_standalone)): ?>
            <?php foreach ($header_standalone as $page): ?>
            <?php
            $href = !empty($page['external_url'])
              ? htmlspecialchars($page['external_url'])
              : root . 'page/' . htmlspecialchars($page['slug_url']);
            ?>
            <a href="<?= $href ?>" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-lg transition-colors"
              <?= !empty($page['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
              <?= headerNavIcon($page) ?>
              <span><?= htmlspecialchars(getTranslatedPageName($page, $currentLang)) ?></span>
            </a>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- MOBILE SETTINGS SECTION -->
          <div class="pt-4 border-t border-gray-200 space-y-3">
            <!-- LANGUAGE + CURRENCY ON ONE ROW -->
            <div class="grid grid-cols-2 gap-2">
            <!-- LANGUAGE SWITCHER MOBILE -->
            <?php if (($GLOBALS['app']['multi_language'] ?? '1') != '0'): ?>
            <div class="space-y-2">
              <button @click="toggleDropdown('mobileLang'); loadPart('languages')" class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-gray-900 border border-gray-200 hover:bg-gray-50 rounded-lg transition-colors">
                <span class="flex items-center gap-2 min-w-0">
                  <?php
                  $currentLangFlag = 'xx';
                  $currentLangName = 'English';
                  foreach ($GLOBALS['languages'] as $lang) {
                    if ($lang['lang_code'] === ($_SESSION['app_language'] ?? 'en')) {
                      $currentLangName = $lang['name'];
                      $currentLangFlag = !empty($lang['country']) ? strtolower($lang['country']) : 'xx';
                      break;
                    }
                  }
                  ?>
                  <img src="<?= root . 'assets/img/flags/' . $currentLangFlag ?>.svg" alt="<?= htmlspecialchars($currentLangName) ?> flag" class="w-5 h-5 object-cover rounded-full border border-gray-200 shadow-sm flex-shrink-0" style="background: #f3f4f6;" loading="lazy">
                  <span class="truncate"><?= htmlspecialchars($currentLangName) ?></span>
                </span>
                <span class="material-symbols-outlined transition-transform" :class="openDropdowns['mobileLang'] ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div x-show="openDropdowns['mobileLang']"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-100 translate-y-0"
                   x-transition:leave-end="opacity-0 -translate-y-1"
                   class="space-y-1 max-h-[260px] overflow-y-auto">
                <!-- LAZY: FETCHED FROM partials/languages ON FIRST OPEN -->
                <div x-show="!parts.languages" class="flex items-center justify-center py-6">
                  <svg class="animate-spin text-primary" width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </div>
                <div x-html="parts.languages"></div>
              </div>
            </div>
            <?php endif; ?>


            <!-- CURRENCY SWITCHER MOBILE -->
            <?php if (($GLOBALS['app']['multi_currency'] ?? '1') != '0'): ?>
            <div class="space-y-2">
              <button @click="toggleDropdown('mobileCurr'); loadPart('currencies')" class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-gray-900 border border-gray-200 hover:bg-gray-50 rounded-lg transition-colors">
                <span class="flex items-center gap-2 min-w-0">
                  <?php
                  $currentCurrencyName = $activeCurrencyCode;
                  $currentCurrencyFlag = $activeCurrencyFlag;
                  ?>
                  <img src="<?= root . 'assets/img/flags/' . $currentCurrencyFlag ?>.svg" alt="<?= htmlspecialchars($currentCurrencyName) ?> flag" class="w-5 h-5 object-cover rounded-full border border-gray-200 shadow-sm flex-shrink-0" style="background: #f3f4f6;" loading="lazy">
                  <span class="truncate"><?= htmlspecialchars($currentCurrencyName) ?></span>
                </span>
                <span class="material-symbols-outlined transition-transform" :class="openDropdowns['mobileCurr'] ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div x-show="openDropdowns['mobileCurr']"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-100 translate-y-0"
                   x-transition:leave-end="opacity-0 -translate-y-1"
                   class="space-y-1 max-h-[260px] overflow-y-auto">
                <!-- LAZY: FETCHED FROM partials/currencies ON FIRST OPEN -->
                <div x-show="!parts.currencies" class="flex items-center justify-center py-6">
                  <svg class="animate-spin text-primary" width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </div>
                <div x-html="parts.currencies"></div>
              </div>
            </div>
            <?php endif; ?>
            </div><!-- END LANGUAGE + CURRENCY ROW -->


            <?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
            <!-- USER MENU MOBILE -->
            <div class="space-y-2">
              <button @click="toggleDropdown('mobileUser')" class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined !text-[18px]">account_circle</span>
                  <span><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                </span>
                <span class="material-symbols-outlined transition-transform" :class="openDropdowns['mobileUser'] ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div x-show="openDropdowns['mobileUser']"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 -translate-y-1"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-100 translate-y-0"
                   x-transition:leave-end="opacity-0 -translate-y-1"
                   class="pl-6 space-y-1">
                <a href="<?=root?>dashboard" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">dashboard</span>
                  <span><?= T::dashboard ?></span>
                </a>
                <a href="<?=root?>profile" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">person</span>
                  <span><?= T::profile ?></span>
                </a>
                <a href="<?=root?>bookings" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">calendar_month</span>
                  <span><?= T::my_bookings ?></span>
                </a>
                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <a href="<?=root?>admin/settings" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">settings</span>
                  <span><?= T::settings ?></span>
                </a>
                <a href="<?=root?>profile#change-password" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">password</span>
                  <span><?= T::change_password ?></span>
                </a>
                <?php endif; ?>
                <div class="h-px bg-gray-200 my-1"></div>
                <a href="<?=root?>logout" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors">
                  <span class="material-symbols-outlined !text-[16px]">logout</span>
                  <span><?= T::logout ?></span>
                </a>
              </div>
            </div>
            <?php else: ?>
            <!-- MOBILE AUTH BUTTONS — LOGIN + SIGNUP ON ONE ROW, TEXT CENTERED -->
            <?php $showSignup = ($GLOBALS['app']['user_registration'] ?? '1') != '0'; ?>
            <?php $agentReg = ($GLOBALS['app']['agent_registration'] ?? 0) != 0; ?>
            <?php
            if (!isset($loginHref)) {
                $loginHref = root . 'login';
            }
            ?>
            <div class="grid grid-cols-2 gap-2">
              <a href="<?= htmlspecialchars($loginHref) ?>" class="btn justify-center <?= $showSignup ? '' : 'col-span-2' ?>">
                <span class="material-symbols-outlined !text-[18px]">login</span>
                <span><?= T::login ?></span>
              </a>

              <?php if ($showSignup): ?>
                <?php if ($agentReg): ?>
                <!-- SIGNUP DROPDOWN -->
                <div class="space-y-2 relative">
                  <button @click="toggleDropdown('mobileSignup')" class="btn light w-full justify-center">
                    <span class="material-symbols-outlined !text-[18px]">person_add</span>
                    <span><?= T::signup ?></span>
                    <span class="material-symbols-outlined !text-[16px] transition-transform" :class="openDropdowns['mobileSignup'] ? 'rotate-180' : ''">expand_more</span>
                  </button>
                  <div x-show="openDropdowns['mobileSignup']"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="opacity-0 -translate-y-1"
                       x-transition:enter-end="opacity-100 translate-y-0"
                       x-transition:leave="transition ease-in duration-150"
                       x-transition:leave-start="opacity-100 translate-y-0"
                       x-transition:leave-end="opacity-0 -translate-y-1"
                       class="space-y-1 pt-1">
                    <a href="<?=root?>signup" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                      <span class="material-symbols-outlined !text-[16px]">person</span>
                      <span><?= T::customer ?? 'Customer' ?> <?= T::signup ?></span>
                    </a>
                    <a href="<?=root?>agent-signup" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-primary/5 rounded-lg transition-colors">
                      <span class="material-symbols-outlined !text-[16px]">business_center</span>
                      <span><?= T::agent ?> <?= T::signup ?></span>
                    </a>
                  </div>
                </div>
                <?php else: ?>
                <!-- SIMPLE SIGNUP BUTTON -->
                <a href="<?=root?>signup" class="btn light justify-center">
                  <span class="material-symbols-outlined !text-[18px]">person_add</span>
                  <span><?= T::signup ?></span>
                </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </header>
    <?php } ?>

    <?php
    include "app/views/admin/sidebar.php";
    ?>