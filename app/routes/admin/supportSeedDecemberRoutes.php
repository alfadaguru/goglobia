<?php
// app/routes/admin/supportSeedDecemberRoutes.php
// ONE-CLICK "Seed December campaigns" — CAMPAIGNS ONLY. Inserts the December ad
// campaigns into gia_ad_campaigns so GIA is aware of them, when an admin CLICKS the
// button on the Ad Campaigns screen. Idempotent (skips a campaign whose name already
// exists), so re-clicking is a safe no-op.
//
// SCOPE: this ONLY registers ad campaigns (attribution + GIA awareness). It does NOT
// set umrah prices, publish departures, or create any discount — the owner was explicit
// ("don't touch the pricing — yours is just the campaign"). Umrah promo prices + publish
// stay a manual Admin > Umrah step; flights carry NO discount (ads are attribution only).
//
// Admin-authenticated + CSRF-guarded POST (NOT an open data-writing URL). Mirrors the
// campaigns/save gate exactly.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../../lib/support/campaigns.php'; // ensureAdCampaignSchema

$router->post(admin.'/support/campaigns/seed-december', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'edit')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Access Denied']);
        return;
    }

    header('Content-Type: application/json');

    $now = date('Y-m-d H:i:s');
    $ends = date('Y-m-d H:i:s', strtotime('+60 days'));
    $by = (string) ($_SESSION['user_id'] ?? '') ?: null;

    // Normalisers identical to the campaigns/save handler (supportCampaignsRoutes.php).
    $kw = static function (string $v): ?string {
        $v = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($v)));
        return $v !== '' ? substr($v, 0, 60) : null;
    };
    $route = static function (string $v): ?string {
        $v = strtoupper(trim($v));
        return preg_match('/^([A-Z]{3})-([A-Z]{3})$/', $v, $m) ? $m[1] . '-' . $m[2] : null;
    };

    // The campaign definitions (CAMPAIGNS ONLY — no prices).
    $defs = [
        // --- Umrah (2 campaigns). Price lives on the umrah tier, NOT here. ---
        ['platform' => 'meta', 'name' => 'Umrah 16 December', 'offer_product' => 'umrah',
         'offer_intent' => 'Umrah departure on 16 December', 'match_key' => 'umrah_16dec', 'match_keyword' => 'UMRAH16'],
        ['platform' => 'meta', 'name' => 'Umrah 25 December', 'offer_product' => 'umrah',
         'offer_intent' => 'Umrah departure on 25 December', 'match_key' => 'umrah_25dec', 'match_keyword' => 'UMRAH25'],
        // --- Domestic flights (8 directional). scope_route set; NO discount. ---
        ['platform' => 'meta', 'name' => 'Flights Lagos–Abuja', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Lagos to Abuja', 'match_key' => 'flt_los_abv', 'match_keyword' => 'LOSABUJA', 'scope_route' => 'LOS-ABV'],
        ['platform' => 'meta', 'name' => 'Flights Abuja–Lagos', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Abuja to Lagos', 'match_key' => 'flt_abv_los', 'match_keyword' => 'ABUJALOS', 'scope_route' => 'ABV-LOS'],
        ['platform' => 'meta', 'name' => 'Flights Kano–Abuja', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Kano to Abuja', 'match_key' => 'flt_kan_abv', 'match_keyword' => 'KANOABUJA', 'scope_route' => 'KAN-ABV'],
        ['platform' => 'meta', 'name' => 'Flights Abuja–Kano', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Abuja to Kano', 'match_key' => 'flt_abv_kan', 'match_keyword' => 'ABUJAKANO', 'scope_route' => 'ABV-KAN'],
        ['platform' => 'meta', 'name' => 'Flights Lagos–PH', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Lagos to Port Harcourt', 'match_key' => 'flt_los_phc', 'match_keyword' => 'LAGOSPH', 'scope_route' => 'LOS-PHC'],
        ['platform' => 'meta', 'name' => 'Flights PH–Lagos', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Port Harcourt to Lagos', 'match_key' => 'flt_phc_los', 'match_keyword' => 'PHLAGOS', 'scope_route' => 'PHC-LOS'],
        ['platform' => 'meta', 'name' => 'Flights Lagos–Kano', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Lagos to Kano', 'match_key' => 'flt_los_kan', 'match_keyword' => 'LAGOSKANO', 'scope_route' => 'LOS-KAN'],
        ['platform' => 'meta', 'name' => 'Flights Kano–Lagos', 'offer_product' => 'flights',
         'offer_intent' => 'Cheap domestic flights Kano to Lagos', 'match_key' => 'flt_kan_los', 'match_keyword' => 'KANOLAGOS', 'scope_route' => 'KAN-LOS'],
    ];

    $created = []; $skipped = [];
    foreach ($defs as $d) {
        try {
            if ($db->get('gia_ad_campaigns', ['id'], ['name' => $d['name']])) {
                $skipped[] = $d['name'];
                continue;
            }
            $db->insert('gia_ad_campaigns', [
                'platform'      => $d['platform'],
                'name'          => $d['name'],
                'offer_intent'  => $d['offer_intent'] ?? null,
                'offer_product' => $d['offer_product'],
                'scope_route'   => isset($d['scope_route']) ? $route($d['scope_route']) : null,
                'utm_source'    => 'facebook',
                'utm_campaign'  => $d['match_key'] ?? null,
                'utm_medium'    => 'paid_social',
                'match_key'     => $d['match_key'] ?? null,
                'match_keyword' => isset($d['match_keyword']) ? $kw($d['match_keyword']) : null,
                'active'        => 1,
                'starts_at'     => $now,
                'ends_at'       => $ends,
                'created_by'    => $by,
                'created_at'    => $now,
            ]);
            $created[] = $d['name'];
        } catch (\Throwable $e) {
            error_log('[seed-december] ' . $d['name'] . ': ' . $e->getMessage());
            $skipped[] = $d['name'] . ' (error)';
        }
    }

    echo json_encode([
        'ok'      => true,
        'created' => $created,
        'skipped' => $skipped,
        'message' => count($created) . ' campaign(s) created, ' . count($skipped) . ' already existed. '
                   . 'Reminder: set the Umrah tier prices (2,800,000 → 2,450,000) and PUBLISH the '
                   . '16 & 25 Dec departures in Admin → Umrah — the button does campaigns only, not pricing.',
    ]);
});
