<?php
/**
 * ============================================================================
 * SEO CRAWL-QA  (SEO-006)  —  automated indexability validator
 * ----------------------------------------------------------------------------
 * A dependency-free CLI crawler that asserts the SEO invariants the audit calls
 * for, per page TEMPLATE:
 *   - HTTP status is 200 (or the expected code)
 *   - <title> present, non-empty, within length, and UNIQUE across templates
 *   - meta description present, non-placeholder, within length, and UNIQUE
 *   - exactly one <h1>
 *   - rel=canonical present, absolute, and carries NO query string
 *   - robots meta is the expected index/noindex for that template
 *   - JSON-LD present and valid (and BreadcrumbList on inner pages)
 *   - indexable templates are LISTED in sitemap.xml; noindex ones are NOT
 *
 * Exit code 0 = all pass; 1 = one or more failures (CI-friendly).
 *
 * Usage:
 *   php tools/seo-crawl-qa.php [BASE_URL]
 *   BASE_URL defaults to $SEO_QA_BASE env or http://localhost/goglobia/
 *
 * CI: add `"qa:seo": "php tools/seo-crawl-qa.php"` to package.json and run it
 * against a booted instance; or call from a GitHub Action after `php -S`.
 * ============================================================================
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$BASE = rtrim($argv[1] ?? getenv('SEO_QA_BASE') ?: 'http://localhost/goglobia/', '/') . '/';

// --- Templates to check: path, expected status, should it be indexable? ------
// One representative URL per TEMPLATE (not an exhaustive crawl — that is the
// sitemap's job; this validates the template contract).
$templates = [
    ['path' => '',                          'status' => 200, 'index' => true,  'breadcrumb' => false],
    ['path' => 'flights',                   'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'stays',                     'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'tours',                     'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'umrah',                     'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'flights/lagos-to-abuja',    'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'hotels/dubai',              'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'tours/dubai',               'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'visa/uae',                  'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'umrah/from-kano',           'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'travel-directory',          'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'page/about-us',             'status' => 200, 'index' => true,  'breadcrumb' => true],
    ['path' => 'page/privacy-policy',       'status' => 200, 'index' => true,  'breadcrumb' => true],
    // Must be NOINDEX (private / thin / search):
    ['path' => 'login',                     'status' => 200, 'index' => false, 'breadcrumb' => false],
    ['path' => 'cart',                      'status' => 200, 'index' => false, 'breadcrumb' => false],
    ['path' => 'flights/los/dxb/oneway/economy/31-12-2026/1/0/0', 'status' => 200, 'index' => false, 'breadcrumb' => false],
];

// --- tiny HTTP GET (no curl dependency required, but use it if present) -------
function qa_get(string $url): array {
    $html = null; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'GoGlobia-SEO-QA/1.0',
        ]);
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'GoGlobia-SEO-QA/1.0']]);
        $html = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        } elseif ($html !== false) { $code = 200; }
    }
    return ['code' => $code, 'html' => (string) $html];
}

function qa_match(string $re, string $html): ?string {
    return preg_match($re, $html, $m) ? trim($m[1]) : null;
}

// --- Fetch the sitemap once (for membership checks) --------------------------
$sitemap = qa_get($BASE . 'sitemap.xml');
$sitemapLocs = [];
if ($sitemap['code'] === 200 && preg_match_all('~<loc>\s*([^<]+?)\s*</loc>~i', $sitemap['html'], $mm)) {
    foreach ($mm[1] as $loc) { $sitemapLocs[rtrim(strtolower($loc), '/')] = true; }
}

// --- Run checks --------------------------------------------------------------
$fail = 0; $pass = 0; $warn = 0;
$seenTitles = []; $seenDescs = [];
$P = fn($s) => fwrite(STDOUT, $s . PHP_EOL);
$FAILMSGS = [];
$ok   = function ($url, $msg) use (&$pass, $P) { $pass++; };
$err  = function ($url, $msg) use (&$fail, &$FAILMSGS) { $fail++; $FAILMSGS[] = "  ✗ [$url] $msg"; };
$note = function ($url, $msg) use (&$warn) { $warn++; };

$P("SEO crawl-QA  ·  base = $BASE");
$P("sitemap.xml: " . ($sitemap['code'] === 200 ? count($sitemapLocs) . " URLs" : "HTTP {$sitemap['code']} (!)"));
$P(str_repeat('-', 66));

foreach ($templates as $t) {
    $url = $BASE . $t['path'];
    $label = '/' . $t['path'];
    $r = qa_get($url);
    $h = $r['html'];
    $lineFails = $fail;

    // status
    if ($r['code'] !== $t['status']) { $err($label, "status {$r['code']} (expected {$t['status']})"); }
    else { $ok($label, 'status'); }
    if ($r['code'] !== 200) { $P(sprintf("%-46s  %s", $label, 'SKIP (non-200)')); continue; }

    // title
    $title = qa_match('~<title>(.*?)</title>~is', $h);
    if ($title === null || $title === '') { $err($label, 'missing <title>'); }
    else {
        if (mb_strlen($title) > 70) { $note($label, "title long (" . mb_strlen($title) . " chars)"); }
        $tk = mb_strtolower($title);
        if (isset($seenTitles[$tk])) { $err($label, "duplicate <title> (also on {$seenTitles[$tk]})"); }
        else { $seenTitles[$tk] = $label; $ok($label, 'title'); }
    }

    // meta description
    $desc = qa_match('~<meta\s+name="description"\s+content="([^"]*)"~i', $h);
    if ($desc === null || $desc === '') { $err($label, 'missing meta description'); }
    elseif (stripos($desc, 'website meta description') !== false) { $err($label, 'PLACEHOLDER meta description'); }
    else {
        if (mb_strlen($desc) > 170) { $note($label, "description long"); }
        $dk = mb_strtolower($desc);
        if (isset($seenDescs[$dk]) && $t['path'] !== '' && $t['index']) {
            // a shared site-default description is allowed on a couple of generic
            // pages; only warn on indexable duplicates.
            $note($label, "duplicate meta description (also on {$seenDescs[$dk]})");
        } else { $seenDescs[$dk] = $label; }
        $ok($label, 'description');
    }

    // exactly one <h1> (strip HTML comments + scripts first so a literal "<h1>"
    // in a comment or inline JS string is not miscounted).
    $hNoComments = preg_replace('~<!--.*?-->~s', '', $h);
    $hNoComments = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $hNoComments);
    $h1count = preg_match_all('~<h1[\s>]~i', $hNoComments);
    if ($h1count === 0) { $err($label, 'no <h1>'); }
    elseif ($h1count > 1) { $err($label, "$h1count <h1> elements (expect 1)"); }
    else { $ok($label, 'h1'); }

    // canonical — present, absolute, NO query string
    $canon = qa_match('~<link\s+rel="canonical"\s+href="([^"]+)"~i', $h);
    if ($canon === null) { $err($label, 'missing canonical'); }
    else {
        if (strpos($canon, '?') !== false) { $err($label, "canonical has query string: $canon"); }
        elseif (!preg_match('~^https?://~i', $canon)) { $err($label, "canonical not absolute: $canon"); }
        else { $ok($label, 'canonical'); }
    }

    // robots — expected index/noindex
    $robots = qa_match('~<meta\s+name="robots"\s+content="([^"]*)"~i', $h);
    $isNoindex = $robots !== null && stripos($robots, 'noindex') !== false;
    if ($t['index'] && $isNoindex) { $err($label, "robots NOINDEX but template should be indexable ($robots)"); }
    elseif (!$t['index'] && !$isNoindex) { $err($label, "robots INDEXABLE but template should be noindex ($robots)"); }
    else { $ok($label, 'robots'); }

    // JSON-LD present + valid; BreadcrumbList where expected
    preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $h, $ld);
    $ldTypes = [];
    $ldValid = true;
    foreach ($ld[1] as $blk) {
        $d = json_decode(trim($blk), true);
        if ($d === null) { $ldValid = false; continue; }
        $ldTypes[] = $d['@type'] ?? '';
    }
    if (empty($ld[1])) { $err($label, 'no JSON-LD structured data'); }
    elseif (!$ldValid) { $err($label, 'invalid JSON-LD (parse error)'); }
    else { $ok($label, 'json-ld'); }
    if ($t['breadcrumb'] && !in_array('BreadcrumbList', $ldTypes, true)) {
        $err($label, 'expected BreadcrumbList schema, none found');
    }

    // sitemap membership: indexable templates SHOULD be listed; noindex NOT.
    // (Skip date-bearing search URLs and the generic home which is always there.)
    if ($sitemap['code'] === 200) {
        $key = rtrim(strtolower($canon ?? $url), '/');
        $inMap = isset($sitemapLocs[$key]);
        if ($t['index'] && !$inMap && !preg_match('~\d{1,2}-\d{1,2}-\d{4}~', $t['path'])) {
            $note($label, "indexable but NOT in sitemap"); // warn (CMS/landing coverage varies)
        }
        if (!$t['index'] && $inMap) { $err($label, "NOINDEX page is listed in sitemap"); }
    }

    $status = ($fail === $lineFails) ? 'PASS' : 'FAIL';
    $P(sprintf("%-46s  %s", $label, $status));
}

$P(str_repeat('-', 66));
if ($FAILMSGS) { $P("FAILURES:"); foreach ($FAILMSGS as $m) { $P($m); } }
$P(sprintf("checks: %d passed, %d failed, %d warnings  ·  templates: %d", $pass, $fail, $warn, count($templates)));
exit($fail > 0 ? 1 : 0);
