<?php
ob_start();

// ==================================================
// APPLICATION ENTRY POINT
// ==================================================
// Main routing file that handles all HTTP requests,
// loads global data, and dispatches to appropriate
// route handlers with error handling.
// ==================================================

// ==================================================
// NAMESPACE IMPORTS
// ==================================================

use AppRouter\Router;

// ==================================================
// LOAD CONFIGURATION
// ==================================================

require_once 'config.php';

// ==================================================
// LIGHTWEIGHT APPLICATION CACHE (APCu + file fallback)
// ==================================================

if (!function_exists('appCacheRemember')) {
    function appCacheRemember($key, $ttl, callable $resolver) {
        // Disabled by default. Enable explicitly via APP_DB_CACHE_ENABLED=1 in .env
        if (empty($GLOBALS['APP_DB_CACHE_ENABLED'])) {
            return $resolver();
        }

        $cacheKey = 'v10:' . $key;

        // Fast path: APCu in-memory cache
        if (function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled')) {
            $success = false;
            $cached = apcu_fetch($cacheKey, $success);
            if ($success) {
                return $cached;
            }

            $value = $resolver();
            apcu_store($cacheKey, $value, (int)$ttl);
            return $value;
        }

        // Fallback: small file cache
        $cacheDir = __DIR__ . '/app/cache/runtime';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.cache';
        if (is_file($cacheFile)) {
            $age = time() - (int)@filemtime($cacheFile);
            if ($age >= 0 && $age <= (int)$ttl) {
                $raw = @file_get_contents($cacheFile);
                if ($raw !== false) {
                    $decoded = @unserialize($raw);
                    if ($decoded !== false || $raw === serialize(false)) {
                        return $decoded;
                    }
                }
            }
        }

        $value = $resolver();
        @file_put_contents($cacheFile, serialize($value), LOCK_EX);
        return $value;
    }
}

// ==================================================
// LOAD GLOBAL APPLICATION DATA
// ==================================================

$GLOBALS['app'] = appCacheRemember('settings:id:1', 30, function () use ($db) {
    return $db->get("settings", "*", ["id" => 1]);
});

// Self-healing schema: add settings.user_restriction on installs that predate it.
// Runs every request but costs nothing once the column exists (see the function).
ensureUserRestrictionSchema($db);

// Load active languages (default first) with country name from countries table
$GLOBALS['languages'] = appCacheRemember('languages:active', 60, function () use ($db) {
    return $db->select("languages", [
        "[>]countries" => ["country" => "iso"]
    ], [
        "languages.id",
        "languages.name",
        "languages.lang_code",
        "languages.country",
        "languages.type",
        "languages.default",
        "languages.status",
        "countries.nicename(country_name)"
    ], [
        "languages.status" => "1",
        "ORDER" => ["languages.default" => "DESC"]
    ]);
});

// Load active currencies with country information
$GLOBALS['currencies'] = appCacheRemember('currencies:active', 60, function () use ($db) {
    return $db->select("currencies", [
        "[>]countries" => ["country" => "iso"]
    ], [
        "currencies.id",
        "currencies.name",
        "currencies.country",
        "currencies.rate",
        "currencies.default",
        "countries.nicename(country_name)"
    ], [
        "currencies.status" => "1",
        "ORDER" => ["currencies.default" => "DESC"]
    ]);
});

// Load active modules in display order
$GLOBALS['modules'] = appCacheRemember('modules:active', 60, function () use ($db) {
    return $db->select('modules', '*', ['status' => 1, 'active' => 1, 'ORDER' => ['order']]);
});

// ==================================================
// SESSION DEFAULTS INITIALIZATION
// ==================================================

// Set default language on first visit
if (!isset($_SESSION['app_language']) && !empty($GLOBALS['languages'])) {
    $default = $GLOBALS['languages'][0]; // First is default (ORDER BY default DESC)
    $_SESSION['app_language'] = $default['language_code'];
    $_SESSION['app_language_dir'] = $default['type'];
    $_SESSION['app_language_name'] = $default['name'];
}

// Set default currency on first visit
if (!isset($_SESSION['app_currency']) && !empty($GLOBALS['currencies'])) {
    $default = $GLOBALS['currencies'][0]; // First is default (ORDER BY default DESC)
    $_SESSION['app_currency'] = $default['name'];
    $_SESSION['app_currency_rate'] = $default['rate'];
    $_SESSION['app_currency_country'] = $default['country'];
    $_SESSION['app_currency_country_name'] = $default['country_name'];
    $_SESSION['app_currency_changed'] = false;
}

// Flag session as a legitimate browser client visiting the web frontend (only on HTML page loads, not API/module requests)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($requestUri, '/api/') === false && strpos($requestUri, '/modules/') === false) {
    $_SESSION['is_web_client'] = true;
}

// ==================================================
// HELPER FUNCTIONS
// ==================================================

/**
 * Get CMS pages by position and type
 * @param object $db Database instance
 * @param string $position Page position
 * @param string $type Page type
 * @return array CMS pages
 */
function cms($db,$position,$type) {
    // Fetch every active CMS row once per request, then filter by position in PHP.
    // The header and footer both call this (with "header" and "footer"), which used
    // to fire two near-identical queries; caching makes it a single query.
    static $all = null;
    if ($all === null) {
        $all = $db->select("cms", [
            "id",
            "page_name",
            "slug_url",
            "content",
            "parent_id",
            "external_url",
            "order",
            "page_name_translations",
            "position"
        ], [
            "status" => "1",
            "ORDER" => ["parent_id" => "ASC", "order" => "ASC"]
        ]) ?: [];
    }

    // Equivalent to the old `position[~] => [$position, $type]` (LIKE %..% OR).
    $out = [];
    foreach ($all as $row) {
        $pos = (string)($row['position'] ?? '');
        if (($position !== '' && stripos($pos, $position) !== false) ||
            ($type !== '' && stripos($pos, $type) !== false)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Get translated page name based on current language
 * @param array $item Page item with translations
 * @param string|null $lang Language code (defaults to session language)
 * @return string Translated page name or default
 */
if (!function_exists('getTranslatedPageName')) {
    function getTranslatedPageName($item, $lang = null) {
        $lang = $lang ?? $_SESSION['app_language'] ?? 'en';

        // If English, return default page_name
        if ($lang === 'en') {
            return $item['page_name'];
        }

        // Check if translations exist in JSON
        if (!empty($item['page_name_translations'])) {
            $translations = is_array($item['page_name_translations'])
                ? $item['page_name_translations']
                : json_decode($item['page_name_translations'], true);

            if (isset($translations[$lang]) && !empty($translations[$lang])) {
                return $translations[$lang];
            }
        }

        // Fallback to default page_name (English)
        return $item['page_name'];
    }
}

// ==================================================
// ROUTER INITIALIZATION
// ==================================================

// Configure 404 error handler
$router = new Router(function ($method, $path, $statusCode) use ($SECURE,$db) {
    http_response_code($statusCode);

    // META DATA
    $title = $GLOBALS['app']['home_title'];
    $description = $GLOBALS['app']['meta_description'];

    require_once views."includes/header.php";
    require_once views."404.php";
    require_once views."includes/footer.php";

});

// ==================================================
// USER RESTRICTION GATE
// ==================================================
// When settings.user_restriction = '1', guests are sent to the login page for
// every non-allow-listed route. No-op when the setting is '0' (the default).

enforceUserRestriction();

// ==================================================
// LOAD APPLICATION ROUTES
// ==================================================

require_once 'app/routes/_routes.php';

// ==================================================
// ROUTE DISPATCH & ERROR HANDLING
// ==================================================

try {
    // Dispatch the router to handle the current request
    $router->dispatchGlobal();
    ob_end_flush();
} catch (Throwable $e) {
    // Clean all output buffers on error to ensure clean Whoops display
    while (ob_get_level()) {
        ob_end_clean();
    }
    // Re-throw exception for Whoops to handle
    throw $e;
}