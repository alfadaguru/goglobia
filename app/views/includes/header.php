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
// SUPPLIER SIDEBAR (inc S34): a persistent left nav for the supplier area, shown to a
// supplier owner OR an active supplier-staff member (never admin — admin has its own).
// supplier_acting_context() returns non-null for exactly those users (null for admin/
// everyone else). Fixed-width (always "open"); no collapse rail.
$isSupplierSidebar = false;
if (!$isAdminSidebar && function_exists('supplier_acting_context')) {
    try {
        $__sctx = supplier_acting_context($db);
        $isSupplierSidebar = ($__sctx !== null && empty($__sctx['is_admin']));
    } catch (\Throwable $e) { $isSupplierSidebar = false; }
}
$sidebarExpanded  = $isAdminSidebar && (($_COOKIE['pt_sidebar'] ?? '') === 'expanded');
$sidebarBodyClass = $isAdminSidebar ? ($sidebarExpanded ? ' sidebar-open' : ' sidebar-closed')
                   : ($isSupplierSidebar ? ' supplier-sidebar-open' : '');
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
// NAV ORDER MIRRORS THE HOMEPAGE SEARCH TABS (home.php): same filtering (skip
// empty/invalid module types) and the SAME sort — active-count per type DESC,
// then type alphabetical — so the top-bar services and the hero search tabs
// always appear in an identical order. Keep this block in sync with home.php.
$enabledModules = array_values(array_filter($GLOBALS['modules'] ?? [], function ($m) {
    $type = strtolower(trim((string)($m['type'] ?? '')));
    return $type !== '' && (bool) preg_match('/^[a-z]+$/', $type);
}));
$processedServices = [];
if (!empty($enabledModules)) {
    // Group by normalised type, carrying a per-type module COUNT for the sort.
    $grouped = array_reduce($enabledModules, function($c, $m) {
        $type = strtolower(trim((string)$m['type']));
        $c[$type] = $c[$type] ?? ['type' => $type, 'name' => $type, 'order' => $m['order'], 'icon' => $m['icon'] ?? '', 'count' => 0];
        if (empty($c[$type]['icon']) && !empty($m['icon'])) $c[$type]['icon'] = $m['icon'];
        $c[$type]['count']++;
        return $c;
    }, []);
    $processedServices = array_values($grouped);
    // SERVICE ORDER — identical to home.php's search tabs (keep in sync): a fixed
    // OTA arrangement (Flights -> Stays -> Tours -> Cars -> Visa -> rest), then the
    // admin `order` field as tiebreaker, then alphabetical. Defined here guarded
    // because the header renders before home.php.
    // NOTE (merge of feat/supplier-payouts-live-toggle): that branch carried an
    // OLDER count-based sort whose stated goal was "mirror the homepage tabs".
    // main already achieves that goal — home.php was updated to this same fixed
    // OTA order — so we keep main's newer ordering (taking the branch's version
    // would REVERT the alignment it wanted). Branch nav change is a no-op here.
    if (!function_exists('goglobiaServicePriority')) {
        function goglobiaServicePriority($type) {
            static $rank = [
                'flights' => 1, 'stays' => 2, 'tours' => 3, 'cars' => 4, 'visa' => 5,
                'umrah' => 6, 'cruises' => 7, 'rail' => 8, 'bus' => 9, 'ferries' => 10,
                'esim' => 11, 'insurance' => 12,
            ];
            return $rank[strtolower((string)$type)] ?? 999;
        }
    }
    usort($processedServices, function($a, $b) {
        $pa = goglobiaServicePriority($a['type']);
        $pb = goglobiaServicePriority($b['type']);
        if ($pa !== $pb) return $pa <=> $pb;
        $oa = (int)($a['order'] ?? 0); $ob = (int)($b['order'] ?? 0);
        if ($oa !== $ob) return $oa <=> $ob;
        return strcmp((string)$a['type'], (string)$b['type']);
    });

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

// NAV LAYOUT (P1): promote services into the top bar instead of hiding them all
// in one "Services" dropdown. Show the first N services inline (by admin `order`),
// fold the rest + CMS menus (About/Contact/Blog) into a single "More" dropdown.
// Reuses $processedServices as-is — no new data. Inline count is intentionally
// modest so the bar never overflows on common laptop widths.
$HEADER_INLINE_SERVICES = 6;
$inlineServices   = array_slice($processedServices, 0, $HEADER_INLINE_SERVICES);
$overflowServices = array_slice($processedServices, $HEADER_INLINE_SERVICES);

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

// WHITE-LABEL: resolve tenant flag here (BEFORE <head> renders) so the branded
// favicon/chrome block at the top of <head> can read it. It is re-read later in
// the SEO block (idempotent); defining it early avoids an "undefined variable"
// notice on every page when wl_is_tenant() isn't yet evaluated.
$wlTenant = function_exists('wl_is_tenant') && wl_is_tenant();
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['app_language'] ?? 'en' ?>" dir="<?= $_SESSION['app_language_dir'] ?? 'ltr' ?>">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?=CSRF::getToken()?>">
    <?php if ($wlTenant):
      // WHITE-LABEL Phase 2b: the hotel's own favicon + chrome colours override the platform's.
      $__wlTheme2 = (array) wl_tenant('theme');
      $__wlFav = trim((string) ($__wlTheme2['favicon_url'] ?? ''));
      $__wlHdr = trim((string) ($__wlTheme2['header_bg'] ?? ''));
      $__wlFtr = trim((string) ($__wlTheme2['footer_bg'] ?? ''));
    ?>
      <?php if ($__wlFav !== ''): ?>
        <link rel="shortcut icon" href="<?= htmlspecialchars(rtrim(root, '/') . '/' . ltrim($__wlFav, '/')) ?>">
        <link rel="icon" href="<?= htmlspecialchars(rtrim(root, '/') . '/' . ltrim($__wlFav, '/')) ?>">
      <?php else: ?>
        <link rel="shortcut icon" href="<?=versionedAssetUrl('uploads/global/favicon.png')?>">
      <?php endif; ?>
      <?php if ($__wlHdr !== '' || $__wlFtr !== ''): ?>
        <style>
          <?php if ($__wlHdr !== ''): ?>:root{--header-background: <?= htmlspecialchars($__wlHdr) ?>;}<?php endif; ?>
          <?php if ($__wlFtr !== ''): ?>:root{--footer-background: <?= htmlspecialchars($__wlFtr) ?>;}<?php endif; ?>
        </style>
      <?php endif; ?>
    <?php else: ?>
    <link rel="shortcut icon" href="<?=versionedAssetUrl('uploads/global/favicon.png')?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://code.jquery.com">

    <script>document.documentElement.classList.add('js');</script>

    <!-- CRITICAL FIRST-PAINT CSS (OVERLAY-ONLY LOADER, NEVER HIDE BODY) -->
    <style>
      /* Hide Alpine-cloaked elements until Alpine initializes (prevents flash). */
      [x-cloak] { display: none !important; }
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
      <?php if ($isSupplierSidebar): ?>
      /* SUPPLIER SIDEBAR LAYOUT (inc S34) — fixed 240px rail on desktop; off-canvas on
         mobile (toggled by a button in the supplier sidebar via the #supplier-nav checkbox). */
      @media (min-width: 1024px) {
        body.supplier-sidebar-open { padding-left: 240px; }
      }
      @media (max-width: 1023px) {
        body.supplier-sidebar-open { padding-left: 0; }
      }
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
    $seoBrand       = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['home_title'] ?? 'GoGlobia');
    $seoTitle       = (isset($title) && trim((string)$title) !== '') ? $title : ($GLOBALS['app']['home_title'] ?? $seoBrand);
    $seoKeywords    = (isset($keywords) && trim((string)$keywords) !== '') ? $keywords : ($GLOBALS['app']['site_keywords'] ?? '');

    // META DESCRIPTION (SEO-META): a per-page $description wins; otherwise fall
    // back to the site setting. Then reject the shipped PLACEHOLDER ("Website
    // meta description") and empties from ANY source — several routes pass the
    // raw settings value (which still holds that placeholder) as $description,
    // so the guard must run on the FINAL resolved value, not only the fallback.
    $__realDefaultDesc = 'GoGlobia — book flights, hotels, tours, Umrah packages and more in one place, '
        . 'with secure payments and 24/7 support. A Nigerian travel company you can trust.';
    $seoDescription = (isset($description) && trim((string)$description) !== '')
        ? trim((string)$description)
        : trim((string) ($GLOBALS['app']['meta_description'] ?? ''));
    if ($seoDescription === '' || stripos($seoDescription, 'website meta description') !== false) {
        $seoDescription = $__realDefaultDesc;
    }

    // TITLE TEMPLATE: a page-specific $title gets the brand appended
    // ("Flights | GoGlobia") unless it already names the brand; the home title
    // (from settings) is left as-is.
    if (isset($title) && trim((string)$title) !== '' && stripos($seoTitle, (string) ($GLOBALS['app']['business_name'] ?? 'GoGlobia')) === false
        && stripos($seoTitle, 'goglobia') === false) {
        $seoTitle = rtrim(trim((string)$title), ' -|') . ' | GoGlobia';
    }

    // WHITE-LABEL (Phase 1): on a hotel's own branded site, identity in <head> is the
    // HOTEL's — brand name, OG image (hotel hero/logo), per-hotel SEO meta from the theme.
    $wlTenant = function_exists('wl_is_tenant') && wl_is_tenant();
    $wlAuthor = $GLOBALS['app']['business_name'] ?? '';
    $wlOgImage = $ogImage ?? versionedAssetUrl('uploads/global/cover.png');
    if ($wlTenant) {
        $wlName  = (string) wl_tenant('name');
        $wlTheme = (array) wl_tenant('theme');
        $wlStay  = (array) wl_tenant('stay');
        if ($wlName !== '') { $seoBrand = $wlName; $wlAuthor = $wlName; }
        if (!empty($wlTheme['meta_title']) && (!isset($title) || trim((string)$title) === '')) { $seoTitle = (string) $wlTheme['meta_title']; }
        if (!empty($wlTheme['meta_desc'])) { $seoDescription = (string) $wlTheme['meta_desc']; }
        // OG image: hotel logo, else first gallery image, else keep platform default.
        $wlLogo = trim((string) wl_tenant('logo'));
        if ($wlLogo !== '') {
            $wlOgImage = (defined('root') ? rtrim(root, '/') . '/' : '') . ltrim($wlLogo, '/');
        } elseif (!empty($wlStay['img'])) {
            $__g = json_decode((string) $wlStay['img'], true) ?: [];
            $__h = '';
            foreach ($__g as $__im) { if (!empty($__im['default']) && !empty($__im['url'])) { $__h = $__im['url']; break; } }
            if ($__h === '' && !empty($__g[0]['url'])) { $__h = $__g[0]['url']; }
            if ($__h !== '') { $wlOgImage = (defined('root') ? rtrim(root, '/') . '/' : '') . ltrim($__h, '/'); }
        }
    }
    ?>
    <?php
    // CANONICAL URL (SEO-003): a self-canonical must point at the CLEAN URL —
    // never the request's query string. The old fallback used REQUEST_URI, so
    // cache-busters and tracking params (?v=…, ?amp=1, utm_*) leaked into the
    // canonical (e.g. .../flights?v=28898), which defeats consolidation. Build
    // it from the PATH only; a route may still set $canonical explicitly when it
    // needs a different target. (Host-level www/non-www normalisation is a 301
    // at the server/.htaccess layer — that is the other half of SEO-003.)
    if (!isset($canonicalUrl)) {
        if (isset($canonical) && trim((string) $canonical) !== '') {
            $canonicalUrl = (string) $canonical;
        } else {
            $__cScheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                ? 'https' : 'http';
            $__cPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'); // drop query string
            $canonicalUrl = $__cScheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $__cPath;
        }
    }
    ?>
    <title><?= htmlspecialchars($seoTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta name="author" content="<?= htmlspecialchars((string) $wlAuthor, ENT_QUOTES, 'UTF-8') ?>">
    <?php
    // ROBOTS / INDEXATION POLICY (SEO-008). A route can set $robots explicitly;
    // otherwise decide by URL. NOINDEX (but still FOLLOW, so link equity flows
    // and Google can READ the directive — never robots.txt-block these) for:
    //  - account / auth / wallet / booking-management / checkout / invoices
    //  - search-result permutations (any non-whitelisted path carrying a query
    //    string: ?date=, ?v=, sort/filter/currency params, etc.)
    //  - admin
    // Evergreen service, destination, route, package, guide, CMS and blog pages
    // stay indexable.
    if (!isset($robots)) {
        $__rPath  = strtolower(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
        $__rBase  = defined('root') ? parse_url((string) root, PHP_URL_PATH) : '';
        $__rRel   = $__rBase && strpos($__rPath, strtolower($__rBase)) === 0
            ? '/' . ltrim(substr($__rPath, strlen($__rBase)), '/') : $__rPath;
        $__rQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');

        // Private / thin / transactional path prefixes → noindex.
        $__noindexPrefixes = [
            '/login', '/signup', '/register', '/logout', '/forgot-password', '/reset-password',
            '/verify-email', '/verify', '/otp', '/account', '/profile', '/dashboard',
            '/wallet', '/bookings', '/my-trips', '/cart', '/checkout', '/invoice/',
            '/payment', '/pay/', '/order', '/orders', '/confirmation', '/admin/', '/supplier/',
            '/agent/', '/license', '/install',
        ];
        $__isNoindex = false;
        foreach ($__noindexPrefixes as $__p) {
            if ($__rRel === rtrim($__p, '/') || strpos($__rRel, $__p) === 0) { $__isNoindex = true; break; }
        }

        $__searchPaths = ['/flights', '/stays', '/tours', '/cars', '/visa', '/esim',
                          '/bus', '/rail', '/ferries', '/cruises', '/umrah', '/hotels'];

        // (a) Search-result / filter permutations via QUERY STRING on a service
        // path → noindex (the clean landing is canonical). Ignore framework/noise
        // params: `url` (the .htaccess rewrite target on EVERY page) and `v`.
        if (!$__isNoindex && $__rQuery !== '') {
            parse_str($__rQuery, $__qp);
            unset($__qp['v'], $__qp['url']);
            if (!empty($__qp)) {
                foreach ($__searchPaths as $__sp) {
                    if (strpos($__rRel, $__sp) === 0) { $__isNoindex = true; break; }
                }
            }
        }

        // (b) Deep PATH-based search RESULT URLs (dated, volatile, thin) →
        // noindex. Flights/stays results encode a date + pax in the path, e.g.
        // /flights/los/dxb/oneway/economy/24-10-2026/1/0/0 or
        // /stays/dubai/14-11-2026/18-11-2026/NG/1/2-0. A clean service landing
        // like /flights (<=1 segment after the service) stays indexable; a URL
        // carrying a DD-MM-YYYY date segment is a specific search and is not.
        if (!$__isNoindex && preg_match('~/\d{1,2}-\d{1,2}-\d{4}(/|$)~', $__rRel)) {
            foreach ($__searchPaths as $__sp) {
                if (strpos($__rRel, $__sp) === 0) { $__isNoindex = true; break; }
            }
        }
        $robots = $__isNoindex ? 'noindex, follow' : 'index, follow';
    }
    ?>
    <meta name="robots" content="<?= htmlspecialchars($robots) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($wlOgImage) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars((string) $seoBrand, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type" content="<?= htmlspecialchars($ogType ?? 'website') ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($_SESSION['app_language'] ?? 'en') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($wlOgImage) ?>">

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
        // Organization typed as TravelAgency (factual + a Google-supported type).
        $orgLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'TravelAgency',
            'name'     => (string)($GLOBALS['app']['business_name'] ?? 'GoGlobia'),
        ];
        if ($ldSiteUrl !== '')                              $orgLd['url']   = $ldSiteUrl;
        if ($ldSiteUrl !== '')                              $orgLd['logo']  = $ldSiteUrl . '/uploads/global/logo.png';
        if (!empty($GLOBALS['app']['contact_phone']))       $orgLd['telephone'] = (string)$GLOBALS['app']['contact_phone'];
        if (!empty($GLOBALS['app']['contact_email']))       $orgLd['email']     = (string)$GLOBALS['app']['contact_email'];
        if (!empty($GLOBALS['app']['address'])) {
            $orgLd['address'] = [
                '@type'         => 'PostalAddress',
                'streetAddress' => (string)$GLOBALS['app']['address'],
                'addressCountry'=> 'NG',
            ];
        }
        // Social profiles (sameAs) — only real, configured URLs (no invented ones).
        $__social = json_decode((string)($GLOBALS['app']['social_media'] ?? ''), true);
        if (is_array($__social)) {
            $__sameAs = [];
            foreach (['facebook','twitter','instagram','linkedin','youtube'] as $__sk) {
                $__u = trim((string)($__social[$__sk] ?? ''));
                if ($__u !== '' && stripos($__u, 'http') === 0) { $__sameAs[] = $__u; }
            }
            if ($__sameAs) { $orgLd['sameAs'] = array_values($__sameAs); }
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
        // BreadcrumbList (SEO-007) — on non-home pages, built from the URL path so
        // navigational pages are eligible for breadcrumb rich results. Only on
        // indexable pages (no point on noindexed private/search URLs). Labels are
        // humanised from the path segment (slug → "Title Case").
        $breadcrumbLd = null;
        $__bcPath = isset($__rRel) ? $__rRel : strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $__bcSegs = array_values(array_filter(explode('/', trim((string)$__bcPath, '/')), 'strlen'));
        $__bcIndexable = (($robots ?? '') === '' || stripos((string)($robots ?? ''), 'noindex') === false);
        if ($ldSiteUrl !== '' && $__bcIndexable && !empty($__bcSegs)
            && !preg_match('~\d{1,2}-\d{1,2}-\d{4}~', $__bcPath)) {   // skip dated result URLs
            $__items = [[
                '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $ldSiteUrl . '/',
            ]];
            $__acc = $ldSiteUrl; $__pos = 2; $__cap = 0;
            foreach ($__bcSegs as $__seg) {
                if ($__cap++ >= 4) break;                 // keep breadcrumbs shallow
                if ($__seg === 'page') continue;          // /page/ is a routing prefix, not a crumb
                $__acc .= '/' . rawurlencode($__seg);
                $__label = ucwords(str_replace(['-', '_'], ' ', $__seg));
                $__items[] = ['@type' => 'ListItem', 'position' => $__pos++, 'name' => $__label, 'item' => $__acc];
            }
            if (count($__items) > 1) {
                $breadcrumbLd = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'BreadcrumbList',
                    'itemListElement' => $__items,
                ];
            }
        }
        ?>
    <script type="application/ld+json"><?= json_encode($orgLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/ld+json"><?= json_encode($siteLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php if ($breadcrumbLd): ?>
    <script type="application/ld+json"><?= json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
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
    <header class="sticky top-0 z-50 w-full transition-shadow duration-300" :class="scrolled ? 'shadow-md' : 'shadow-sm'" x-data="{ mobileMenuOpen: false, scrolled: (typeof window !== 'undefined' && window.scrollY > 10), parts: {}, loadPart(name) { if (!this.parts[name]) fetch('<?=root?>partials/' + name).then(r => r.ok ? r.text() : '').then(t => { if (t) this.parts[name] = t }) } }" x-init="scrolled = window.scrollY > 10" @scroll.window="scrolled = window.scrollY > 10" style="background-color: var(--header-background); color: var(--header-text-color); font-size: var(--header-font-size); border-bottom: var(--header-border-width)px solid var(--header-border-color); min-height: var(--header-height)px; padding-left: var(--header-padding-x)px; padding-right: var(--header-padding-x)px; box-shadow: var(--header-shadow);">
      <div class="container mx-auto">
        <div class="flex items-center justify-between transition-all duration-300 ease-out" :class="scrolled ? 'h-24' : 'h-28'">
          <!-- LOGO + NAVIGATION -->
          <?php $__wl = function_exists('wl_is_tenant') && wl_is_tenant(); ?>
          <div class="flex items-center gap-4">
            <?php if ($__wl):
              $__wlLogo = trim((string) wl_tenant('logo'));
              $__wlName = (string) wl_tenant('name'); ?>
            <a href="<?= root ?>" class="flex items-center gap-2">
              <?php if ($__wlLogo !== ''): ?>
                <img src="<?= htmlspecialchars(rtrim(root, '/') . '/' . ltrim($__wlLogo, '/')) ?>" alt="<?= htmlspecialchars($__wlName) ?>" class="h-8 w-auto max-w-xs object-contain" decoding="async">
              <?php else: ?>
                <span class="text-lg font-bold text-gray-900"><?= htmlspecialchars($__wlName) ?: 'Home' ?></span>
              <?php endif; ?>
            </a>
            <?php else: ?>
            <a href="<?=root?>" class="flex items-center shrink-0">
              <!-- WIDE logo lockup (4:1). Sized by HEIGHT via inline style (the
                   precompiled build lacks lg:h-* variants); width flows from the
                   aspect ratio so it reads as a full brand wordmark like the OTAs.
                   Gentle shrink on scroll. -->
              <img src="<?=versionedAssetUrl('uploads/global/logo.png')?>" alt="<?=$GLOBALS['app']['business_name']?>"
                   class="w-auto object-contain transition-all duration-300 ease-out"
                   :style="scrolled ? 'height:46px' : 'height:54px'"
                   style="height:54px" width="280" height="70" decoding="async">
            </a>
            <?php endif; ?>

            <!-- DESKTOP NAVIGATION -->
            <nav class="hidden lg:flex items-center gap-0.5 font-medium">
            <!-- INLINE SERVICES (platform only — hidden on a hotel's own site).
                 First N services promoted into the bar; the rest live in "More". -->
            <?php if (!$__wl && !empty($inlineServices)): ?>
              <?php foreach ($inlineServices as $svc): ?>
              <a href="<?= $svc['href'] ?>" class="nav-link flex items-center gap-1.5 px-3 py-2 rounded-full font-medium hover:bg-gray-50 whitespace-nowrap">
                <span class="material-symbols-outlined !text-[18px] text-gray-500"><?= htmlspecialchars($svc['icon']) ?></span>
                <span><?= htmlspecialchars(ucwords($svc['label'])) ?></span>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>

            <!-- MORE ▾ : overflow services + CMS menus (About/Contact/Blog). Only
                 rendered when there's something to put in it. -->
            <?php
            // Partner registration links (Agent / Supplier) live in the "More" menu,
            // not on the Sign Up button. Only shown when the operator enables them.
            $__showAgentReg    = (($GLOBALS['app']['user_registration'] ?? '1') != '0') && (($GLOBALS['app']['agent_registration'] ?? 0) != 0);
            $__showSupplierReg = (($GLOBALS['app']['user_registration'] ?? '1') != '0') && (($GLOBALS['app']['supplier_registration'] ?? '0') === '1');
            $__hasPartnerLinks = !$__wl && ($__showAgentReg || $__showSupplierReg);
            $__hasMore = (!$__wl && !empty($overflowServices)) || !empty($header_menus) || !empty($header_standalone) || $__hasPartnerLinks;
            if ($__hasMore):
            ?>
            <div class="relative group">
                <button class="nav-link flex items-center gap-1.5 px-3 py-2 rounded-full hover:bg-gray-50">
                  <span class="material-symbols-outlined !text-[18px] text-gray-500">apps</span>
                  <span class="font-medium"><?= ucfirst(T::more) ?></span>
                  <span class="material-symbols-outlined !text-[18px] transition-transform group-hover:rotate-180">expand_more</span>
                </button>
                <!-- INVISIBLE BRIDGE TO PREVENT DROPDOWN CLOSE -->
                <div class="absolute top-full right-0 h-2 w-full pointer-events-none group-hover:pointer-events-auto"></div>
                <div class="absolute top-full right-0 mt-1 w-64 rounded-md border border-gray-200 bg-white p-1 shadow-lg opacity-0 invisible translate-y-[-8px] transition-all duration-150 ease-out group-hover:opacity-100 group-hover:visible group-hover:translate-y-0 max-h-[70vh] overflow-y-auto">

                  <!-- Overflow services -->
                  <?php if (!$__wl && !empty($overflowServices)): ?>
                    <?php foreach ($overflowServices as $svc): ?>
                    <a href="<?= $svc['href'] ?>" class="flex items-center gap-2 px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors">
                      <span class="material-symbols-outlined !text-[18px] text-gray-500"><?= htmlspecialchars($svc['icon']) ?></span>
                      <span><?= htmlspecialchars(ucwords($svc['label'])) ?></span>
                    </a>
                    <?php endforeach; ?>
                  <?php endif; ?>

                  <!-- Divider before corporate pages, only if both groups exist -->
                  <?php if ((!$__wl && !empty($overflowServices)) && (!empty($header_menus) || !empty($header_standalone))): ?>
                    <div class="my-1 border-t border-gray-100"></div>
                  <?php endif; ?>

                  <!-- CMS parent menus (render children as a small labelled group) -->
                  <?php if (!empty($header_menus)): foreach ($header_menus as $menu): ?>
                    <div class="px-2 pt-1.5 pb-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                      <?= htmlspecialchars(getTranslatedPageName($menu['parent'], $currentLang)) ?>
                    </div>
                    <?php foreach ($menu['children'] as $child):
                      $href = !empty($child['external_url'])
                        ? htmlspecialchars($child['external_url'])
                        : root . 'page/' . htmlspecialchars($child['slug_url']); ?>
                      <a href="<?= $href ?>" class="block px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors"
                        <?= !empty($child['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <?= htmlspecialchars(getTranslatedPageName($child, $currentLang)) ?>
                      </a>
                    <?php endforeach; ?>
                  <?php endforeach; endif; ?>

                  <!-- CMS standalone pages (About / Contact / Blog) -->
                  <?php if (!empty($header_standalone)): foreach ($header_standalone as $page):
                    $href = !empty($page['external_url'])
                      ? htmlspecialchars($page['external_url'])
                      : root . 'page/' . htmlspecialchars($page['slug_url']); ?>
                    <a href="<?= $href ?>" class="flex items-center gap-2 px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors"
                      <?= !empty($page['external_url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                      <?= headerNavIcon($page) ?>
                      <span><?= htmlspecialchars(getTranslatedPageName($page, $currentLang)) ?></span>
                    </a>
                  <?php endforeach; endif; ?>

                  <!-- PARTNER REGISTRATION (Agent / Supplier) — moved off the Sign Up button -->
                  <?php if ($__hasPartnerLinks): ?>
                    <?php if ((!$__wl && !empty($overflowServices)) || !empty($header_menus) || !empty($header_standalone)): ?>
                    <div class="my-1 border-t border-gray-100"></div>
                    <?php endif; ?>
                    <div class="px-2 pt-1.5 pb-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Partner with us</div>
                    <?php if ($__showAgentReg): ?>
                    <a href="<?=root?>agent-signup" class="flex items-center gap-2 px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors">
                      <span class="material-symbols-outlined !text-[18px] text-gray-500">business_center</span>
                      <span><?= T::agent ?> <?= T::signup ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($__showSupplierReg): ?>
                    <a href="<?=root?>supplier-signup" class="flex items-center gap-2 px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors">
                      <span class="material-symbols-outlined !text-[18px] text-gray-500">storefront</span>
                      <span>Become a Supplier</span>
                    </a>
                    <?php endif; ?>
                  <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>
            </nav>
          </div>

          <!-- CTA BUTTONS -->
          <div class="flex items-center gap-2">
            <?php if ($__wl): ?>
            <!-- WHITE-LABEL: hotel's own site — no platform controls. A single "Book" CTA. -->
            <?php $__wlSlug = (string) wl_tenant('slug'); ?>
            <a href="<?= root ?><?= $__wlSlug !== '' ? 'book/' . htmlspecialchars($__wlSlug) : '' ?>#rooms" class="hidden sm:inline-flex btn emerald !rounded-full">
              <span class="material-symbols-outlined !text-[18px]">hotel</span>
              <span>Book now</span>
            </a>
            <?php else: ?>
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
            <!-- SIGN UP IS A PLAIN CUSTOMER BUTTON — goes straight to customer signup.
                 Agent / Supplier registration live in the "More" menu (and the mobile
                 menu) under "Partner with us", so this button stays simple. -->
            <a href="<?=root?>signup" class="inline-flex btn !rounded-full">
              <span class="material-symbols-outlined !text-[18px]">person_add</span>
              <span><?= T::signup ?></span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            </div><!-- END DESKTOP CONTROL GROUP -->
            <?php endif; /* $__wl */ ?>

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
           x-effect="document.body.style.overflow = mobileMenuOpen ? 'hidden' : ''"
           @keydown.escape.window="mobileMenuOpen = false"
           class="lg:hidden fixed left-0 right-0 bg-white border-b border-gray-200 shadow-2xl z-40 overflow-y-auto"
           :style="(scrolled ? 'top:96px;max-height:calc(100vh - 96px)' : 'top:112px;max-height:calc(100vh - 112px)')"
           style="top:112px;max-height:calc(100vh - 112px)">
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
            <!-- MOBILE AUTH — LOGIN + a PLAIN customer SIGN UP button on one row.
                 Agent / Supplier registration are a separate "Partner with us" group
                 below, so Sign Up stays a simple customer action. -->
            <?php $showSignup = ($GLOBALS['app']['user_registration'] ?? '1') != '0'; ?>
            <?php $mShowAgentReg    = $showSignup && (($GLOBALS['app']['agent_registration'] ?? 0) != 0); ?>
            <?php $mShowSupplierReg = $showSignup && (($GLOBALS['app']['supplier_registration'] ?? '0') === '1'); ?>
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
              <a href="<?=root?>signup" class="btn light justify-center">
                <span class="material-symbols-outlined !text-[18px]">person_add</span>
                <span><?= T::signup ?></span>
              </a>
              <?php endif; ?>
            </div>

            <?php if ($mShowAgentReg || $mShowSupplierReg): ?>
            <!-- PARTNER WITH US (Agent / Supplier) — on the menu, off the Sign Up button -->
            <div class="pt-1">
              <div class="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Partner with us</div>
              <?php if ($mShowAgentReg): ?>
              <a href="<?=root?>agent-signup" class="flex items-center gap-2 px-3 py-2.5 text-sm font-medium text-gray-700 border border-gray-200 hover:bg-gray-50 rounded-lg transition-colors">
                <span class="material-symbols-outlined !text-[18px] text-gray-500">business_center</span>
                <span><?= T::agent ?> <?= T::signup ?></span>
              </a>
              <?php endif; ?>
              <?php if ($mShowSupplierReg): ?>
              <a href="<?=root?>supplier-signup" class="flex items-center gap-2 px-3 py-2.5 mt-2 text-sm font-medium text-gray-700 border border-gray-200 hover:bg-gray-50 rounded-lg transition-colors">
                <span class="material-symbols-outlined !text-[18px] text-gray-500">storefront</span>
                <span>Become a Supplier</span>
              </a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </header>
    <?php } ?>

    <?php
    include "app/views/admin/sidebar.php";
    // Supplier sidebar (inc S34) — rendered only for supplier owners/staff (the file
    // self-guards on supplier_acting_context too).
    if ($isSupplierSidebar) { include "app/views/supplier/sidebar.php"; }
    ?>