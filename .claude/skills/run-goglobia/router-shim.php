<?php
// Router shim for PHP's built-in server (`php -S`) to emulate the app's
// .htaccess front controller:  RewriteRule ^(.+)$ index.php?url=$1 [QSA,L]
//
// Usage (from the repo root):
//   php -S 127.0.0.1:8123 .claude/skills/run-goglobia/router-shim.php
//
// Real existing files (assets/css/js/images) are served directly; everything
// else is routed to index.php with $_GET['url'] set, exactly as Apache would.
$docroot = dirname(__DIR__, 3); // repo root (…/htdocs/goglobia)

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$path = ltrim($uri, '/');
$full = $docroot . '/' . $path;

// Never serve secrets/config as static files.
if ($path !== '' && is_file($full) && !preg_match('#(^|/)(\.env|config\.php)$#', $path)) {
    return false; // let the built-in server stream the static asset
}

$_GET['url']               = $path;
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['SCRIPT_FILENAME']= $docroot . '/index.php';
chdir($docroot);
require $docroot . '/index.php';
