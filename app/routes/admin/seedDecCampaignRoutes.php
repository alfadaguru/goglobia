<?php
// app/routes/admin/seedDecCampaignRoutes.php
// ONE-SHOT SEEDER for the December campaign (owner-requested). Creates, through the
// REAL tables/engines (no hardcoded shortcuts), everything GIA needs so the ads work:
//   1. a package template + a 'standard' comfort tier (only if missing),
//   2. two umrah departures — 16 & 25 December (current year) — each with tiers priced
//      REGULAR 2,800,000 / PROMO 2,450,000 (promo_active), then PUBLISHED,
//   3. a flights promo in the Discounts engine (service=flights),
//   4. two gia_ad_campaigns rows (umrah + flights) with WhatsApp keywords.
// Idempotent + re-runnable: every step checks for an existing row first and skips it,
// so running twice never duplicates or doubles a price. ADMIN-gated. Reuses the admin's
// own umrahV2CreateDeparture() so departures are built exactly like the manager does.
//
// RUN ONCE, logged in as an admin:  GET /admin/seed-dec-campaign?confirm=yes
// (Delete this file, or just leave it — re-running is safe.)
@$SECURE or die('Access Denied!');

$router->get(admin . '/seed-dec-campaign', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'umrah', 'edit'); // needs umrah-edit; admins have all

    header('Content-Type: text/plain; charset=utf-8');
    $log = [];
    $say = function (string $m) use (&$log) { $log[] = $m; };

    if (($_GET['confirm'] ?? '') !== 'yes') {
        echo "DECEMBER CAMPAIGN SEEDER\n\n"
           . "This will (idempotently) create/publish:\n"
           . "  • Umrah departures 16 & 25 December — Regular NGN 2,800,000 / Promo NGN 2,450,000\n"
           . "  • A flights promo (Discounts: service=flights)\n"
           . "  • Two GIA ad campaigns (umrah + flights) with WhatsApp keywords\n\n"
           . "It is safe to run more than once (existing rows are skipped).\n\n"
           . "To proceed, reload with:  ?confirm=yes\n";
        return;
    }

    $now  = date('Y-m-d H:i:s');
    $year = (int) date('Y');
    // "16 December" / "25 December" of the CURRENT year (runs in 2026 => 2026-12-16/25).
    $dates = [sprintf('%04d-12-16', $year), sprintf('%04d-12-25', $year)];
    $REGULAR = 2800000.00;
    $PROMO   = 2450000.00;

    // ---------------------------------------------------------------------------
    // 0) Load the umrah admin helpers (umrahV2CreateDeparture lives in umrahV2Routes,
    //    which is already loaded on an /admin/* request — but require defensively).
    // ---------------------------------------------------------------------------
    if (!function_exists('umrahV2CreateDeparture')) {
        $f = dirname(__DIR__) . '/admin/umrahV2Routes.php';
        if (is_file($f)) { require_once $f; }
    }

    // ===========================================================================
    // 1) PACKAGE TEMPLATE + STANDARD TIER (create only if missing)
    // ===========================================================================
    try {
        if (function_exists('ensureUmrahV2Schema')) { ensureUmrahV2Schema($db); }
    } catch (\Throwable $e) { /* schema may self-heal elsewhere */ }

    // 1a. A standard comfort tier must exist (umrahV2CreateDeparture falls back to it).
    $stdTier = $db->get('umrah_tiers', ['id'], ['code' => 'standard']);
    if (!$stdTier) {
        $db->insert('umrah_tiers', [
            'code' => 'standard', 'name' => 'Standard', 'public_label' => 'Standard',
            'sort_order' => 0, 'default_occupancy' => 4, 'room_sharing' => 'Quad sharing',
            'bookable' => 1, 'status' => 1, 'archived' => 0, 'created_at' => $now,
        ]);
        $stdTierId = (int) $db->id();
        $say("Created 'standard' comfort tier (id {$stdTierId}).");
    } else {
        $stdTierId = (int) $stdTier['id'];
        $say("Standard tier already exists (id {$stdTierId}).");
    }

    // 1b. A package template to attach departures to.
    $tpl = $db->get('umrah_package_templates', ['id'], ['code' => 'DEC-PROMO']);
    if (!$tpl) {
        $db->insert('umrah_package_templates', [
            'code' => 'DEC-PROMO', 'slug' => 'december-umrah-' . $year,
            'name' => 'December Umrah ' . $year,
            'season' => 'normal', 'marketing_duration' => '14 days',
            'madinah_nights' => 5, 'makkah_nights' => 7, 'status' => 1, 'archived' => 0,
            'created_at' => $now,
        ]);
        $templateId = (int) $db->id();
        $say("Created package template 'December Umrah {$year}' (id {$templateId}).");
    } else {
        $templateId = (int) $tpl['id'];
        $say("Package template already exists (id {$templateId}).");
    }

    // ===========================================================================
    // 2) THE TWO DEPARTURES — create (reusing the admin helper) + publish
    // ===========================================================================
    foreach ($dates as $dep) {
        $ret = date('Y-m-d', strtotime($dep . ' +14 days'));
        // Reuse the admin's own creator (seeds ALL comfort tiers with the price +
        // promo_active). It refuses a duplicate code, so re-runs are safe.
        if (function_exists('umrahV2CreateDeparture')) {
            $r = umrahV2CreateDeparture($db, $templateId, $dep, $ret, 50, $stdTierId, $REGULAR, $PROMO);
            if (!empty($r['success'])) {
                $say("Created departure {$dep} (regular " . number_format($REGULAR) . " / promo " . number_format($PROMO) . ").");
            } else {
                $say("Departure {$dep} already existed (" . ($r['message'] ?? 'skip') . ").");
            }
        } else {
            $say("ERROR: umrahV2CreateDeparture() unavailable — cannot create {$dep}.");
            continue;
        }

        // PUBLISH: find the departure by its deterministic code prefix for this date and
        // flip it + its tiers live (mirrors admin status=published). Also RE-ASSERT the
        // standard tier's promo price so a re-run fixes a wrong price without duplicating.
        $depRow = $db->get('umrah_departures', ['id', 'status'], [
            'departure_date' => $dep, 'template_id' => $templateId, 'ORDER' => ['id' => 'ASC'],
        ]);
        if ($depRow) {
            $depId = (int) $depRow['id'];
            $db->update('umrah_departures', ['status' => 'published', 'updated_at' => $now], ['id' => $depId]);
            $db->update('umrah_departure_tiers', ['status' => 'active', 'updated_at' => $now], ['departure_id' => $depId]);
            // Re-assert the promo on the standard tier (idempotent price correction).
            $db->update('umrah_departure_tiers',
                ['regular_price' => $REGULAR, 'promo_price' => $PROMO, 'promo_active' => 1, 'currency' => 'NGN', 'updated_at' => $now],
                ['departure_id' => $depId, 'tier_id' => $stdTierId]);
            $say("Published departure {$dep} (id {$depId}) + activated its tiers.");
        } else {
            $say("WARN: could not locate departure {$dep} to publish.");
        }
    }

    // ===========================================================================
    // 3) FLIGHTS PROMO — Discounts engine (service=flights). GIA surfaces this on a
    //    locked fare via flights_select. Idempotent on a stable name.
    // ===========================================================================
    try {
        if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }
        $discName = 'Domestic Flight Launch Promo';
        $existing = $db->get('discounts', ['id'], ['name' => $discName]);
        if (!$existing) {
            $db->insert('discounts', [
                'name'            => $discName,
                'description'     => 'Launch promo on flights (service scope). Edit value/dates in Admin > Discounts.',
                'discount_type'   => 'percentage',
                'discount_value'  => 10.00, // 10% — EDIT to your real promo in Admin > Discounts
                'scope_type'      => 'service',
                'scope_module'    => 'flights',
                'currency'        => 'NGN',
                'margin_floor_pct'=> 0.00,
                'priority'        => 10,
                'stackable'       => 0,
                'start_date'      => $now,
                'end_date'        => date('Y-m-d H:i:s', strtotime('+60 days')),
                'status'          => 1,
                'created_at'      => $now,
            ]);
            $say("Created flights promo discount '{$discName}' (10% — ADJUST value/dates in Admin > Discounts).");
        } else {
            $say("Flights promo discount already exists (id " . (int) $existing['id'] . ") — left as-is.");
        }
    } catch (\Throwable $e) {
        $say("WARN flights discount: " . $e->getMessage());
    }

    // ===========================================================================
    // 4) GIA AD CAMPAIGNS — umrah + flights, with WhatsApp keywords. Idempotent on name.
    // ===========================================================================
    try {
        if (function_exists('ensureAdCampaignSchema')) { ensureAdCampaignSchema($db); }
        $campaigns = [
            [
                'platform' => 'meta', 'name' => 'Umrah Dec 16 & 25',
                'offer_intent' => 'Umrah departures on 16 & 25 December — Regular NGN 2,800,000, promo NGN 2,450,000',
                'offer_product' => 'umrah', 'utm_campaign' => 'umrah_dec', 'match_key' => 'umrah_dec',
                'match_keyword' => 'UMRAHDEC', 'utm_source' => 'facebook',
            ],
            [
                'platform' => 'meta', 'name' => 'Domestic Flights Launch Promo',
                'offer_intent' => 'Cheaper domestic Nigeria flights (Abuja–Lagos and more) on a launch promo',
                'offer_product' => 'flights', 'utm_campaign' => 'flights_promo', 'match_key' => 'flights_promo',
                'match_keyword' => 'CHEAPFLY', 'utm_source' => 'facebook',
            ],
        ];
        foreach ($campaigns as $c) {
            if ($db->get('gia_ad_campaigns', ['id'], ['name' => $c['name']])) {
                $say("Campaign '{$c['name']}' already exists — left as-is.");
                continue;
            }
            $db->insert('gia_ad_campaigns', array_merge($c, [
                'utm_medium' => 'paid_social', 'active' => 1,
                'starts_at'  => $now, 'ends_at' => date('Y-m-d H:i:s', strtotime('+60 days')),
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null, 'created_at' => $now,
            ]));
            $say("Created ad campaign '{$c['name']}' (keyword {$c['match_keyword']}).");
        }
    } catch (\Throwable $e) {
        $say("WARN ad campaigns: " . $e->getMessage());
    }

    // ---------------------------------------------------------------------------
    echo "DECEMBER CAMPAIGN SEEDER — DONE\n\n";
    echo implode("\n", $log) . "\n\n";
    echo "NEXT:\n"
       . "  • Verify in Admin > Umrah (both departures PUBLISHED, price shows 2.8M→2.45M).\n"
       . "  • Adjust the flights promo value/dates in Admin > Discounts if 10% isn't right.\n"
       . "  • Review the two campaigns in Admin > Support > Ad Campaigns.\n"
       . "  • Schedule the crons (docs/CRON.md) + confirm WhatsApp/email providers.\n"
       . "  • Use the 'Test pre/post comms' button in Settings > Notifications to confirm delivery.\n"
       . "  • Test GIA: say 'book umrah for 16 December' and 'I saw your UMRAHDEC offer'.\n";
});
