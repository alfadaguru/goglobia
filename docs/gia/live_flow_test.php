<?php
// LIVE flow test — drives the REAL GIA engine functions against the REAL local
// MySQL (same Medoo connection the app builds). Not mocks. Prints what each step
// returns so we see exactly what works and what breaks. Run:
//   /Applications/XAMPP/xamppfiles/bin/php-cli (or php) docs/gia/live_flow_test.php
// READ-ONLY intent on search/tiers; book steps DO write a held row (we note ids).

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../../vendor/autoload.php';

use Medoo\Medoo;
$env = parse_ini_file(__DIR__ . '/../../.env');
$db = new Medoo([
    'type' => 'mysql', 'host' => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_DATABASE'], 'username' => trim($env['DB_USERNAME'], '"'),
    'password' => $env['DB_PASSWORD'] ?? '', 'charset' => 'utf8mb4',
]);
if (!defined('root')) { define('root', 'http://localhost/goglobia/'); }
date_default_timezone_set($env['TIMEZONE'] ?? 'UTC');

// Load the shared engines the GIA code calls, then the GIA code itself.
$base = __DIR__ . '/../../';
foreach ([
    'app/lib/functions.php',
    'app/lib/umrah/services.php', 'app/lib/umrah/operations.php',
    'app/lib/stays_inventory.php',
    'app/lib/ai/gia_umrah.php', 'app/lib/ai/gia_flights.php', 'app/lib/ai/gia_stays.php',
] as $f) { if (is_file($base . $f)) { require_once $base . $f; } }

function hr($t){ echo "\n========== $t ==========\n"; }
function show($label,$v){ echo "  $label: " . (is_scalar($v)?$v:json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) . "\n"; }

// ====================== UMRAH ======================
hr('UMRAH');
if (function_exists('App\\lib\\ai\\gia_umrah_search')) {
    $r = \App\lib\ai\gia_umrah_search($db, ['city'=>'', 'pax'=>1]);
    show('search ok', !empty($r['ok']) ? 'yes' : 'no');
    show('departure cards', count($r['cards'] ?? []));
    if (!empty($r['cards'])) {
        $d = $r['cards'][0];
        show('first departure', ($d['title'] ?? '') . ' | departure_id=' . ($d['departure_id'] ?? '?') . ' price=' . ($d['price'] ?? '?') . ' ' . ($d['currency'] ?? ''));
        // tiers for the first departure
        $depId = (int)($d['departure_id'] ?? 0);
        $t = \App\lib\ai\gia_umrah_tiers($db, $depId, 1, '');
        show('tiers ok', !empty($t['ok']) ? 'yes' : 'no');
        show('tiers returned', count($t['tiers'] ?? []));
        if (!empty($t['tiers'])) {
            $t0 = $t['tiers'][0];
            show('first tier', ($t0['tier_label'] ?? '') . ' | departure_tier_id=' . ($t0['departure_tier_id'] ?? '?') . ' price=' . ($t0['price'] ?? '?') . ' ' . ($t0['currency'] ?? ''));
        } else { show('tiers message', $t['message'] ?? ''); }
    } else { show('search message', $r['message'] ?? ''); }
} else { show('ERROR', 'gia_umrah_search not loaded'); }

// ====================== FLIGHTS ======================
hr('FLIGHTS');
if (function_exists('App\\lib\\ai\\gia_flights_active_suppliers')) {
    $sup = \App\lib\ai\gia_flights_active_suppliers($db);
    show('active flight suppliers (status=1,active=1,has search.php)', $sup);
    // A real search (will actually hit the supplier gateways via loopback — may be slow/fail without creds)
    $r = \App\lib\ai\gia_flights_search($db, ['origin'=>'LOS','destination'=>'ABV','departure_date'=>date('Y-m-d', strtotime('+3 days')),'adults'=>1,'currency'=>'USD']);
    show('search ok', !empty($r['ok']) ? 'yes' : 'no');
    show('offers returned', count($r['offers'] ?? []));
    show('message', $r['message'] ?? '');
    if (!empty($r['offers'])) { $o=$r['offers'][0]; show('first offer', ($o['title']??'').' '.($o['price']??'?').' '.($o['currency']??'').' supplier='.($o['supplier']??'')); }
} else { show('ERROR', 'gia_flights_active_suppliers not loaded'); }

// ====================== STAYS ======================
hr('STAYS');
if (function_exists('App\\lib\\ai\\gia_stays_search')) {
    $r = \App\lib\ai\gia_stays_search($db, ['city'=>'']);
    show('search ok', !empty($r['ok']) ? 'yes' : 'no');
    show('property cards', count($r['cards'] ?? []));
    show('message', $r['message'] ?? '');
    if (!empty($r['cards'])) {
        $d = $r['cards'][0];
        show('first property', ($d['title'] ?? '') . ' | stay_id=' . ($d['stay_id'] ?? '?'));
        $ci = date('Y-m-d', strtotime('+2 days')); $co = date('Y-m-d', strtotime('+4 days'));
        $rooms = \App\lib\ai\gia_stays_rooms($db, (int)($d['stay_id'] ?? 0), $ci, $co, 2);
        show('rooms ok', !empty($rooms['ok']) ? 'yes' : 'no');
        show('rooms returned', count($rooms['rooms'] ?? []));
        if (!empty($rooms['rooms'])) { $rm=$rooms['rooms'][0]; show('first room', ($rm['room_name']??'').' ref='.($rm['ref']??'').' price='.($rm['price']??'?').' '.($rm['currency']??'')); }
        else { show('rooms message', $rooms['message'] ?? ''); }
    }
} else { show('ERROR', 'gia_stays_search not loaded'); }

echo "\n========== DONE ==========\n";
