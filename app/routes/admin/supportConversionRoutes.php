<?php
// app/routes/admin/supportConversionRoutes.php
// Customer Support module — Phase 4: ad-campaign CONVERSION ANALYTICS (read-only
// report). Shows the ad -> chat -> HELD booking -> PAID booking funnel per
// registered ad campaign, over a chosen date window. Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§7 LOCKED owner decisions, P4).
//
// This is a pure report screen: it only reads. The funnel + counting live in
// ad_campaign_conversion_report() (app/lib/support/campaigns.php, loaded from
// config.php during bootstrap). Gate = ADMIN_AUTH() + support_can(campaigns.analytics).
@$SECURE or die('Access Denied!');

// ================================ CONVERSION REPORT (read-only)
$router->get(admin.'/support/conversion', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    // Self-heal the ad-campaign schema so a fresh install can open this page
    // before any campaign exists (the report returns [] / just the (none) bucket).
    if (function_exists('ensureAdCampaignSchema')) { ensureAdCampaignSchema($db); }

    // Capability gate: who may see conversion analytics. Admins resolve to the
    // wildcard '*' implicitly (see support_current_staff()). Non-permitted staff
    // bounce to the support home, matching supportRoutes.php.
    if (!function_exists('support_can') || !support_can($db, 'campaigns.analytics')) {
        header('Location: ' . root . admin . '/support');
        exit;
    }

    // ---- Date-range filter. Default: last 30 days (inclusive of today). -------
    // We accept DATE inputs (Y-m-d) from the form and expand them to the full
    // day window [from 00:00:00, to 23:59:59] that the report expects.
    $todayDate = date('Y-m-d');
    $fromDate  = (string) ($_GET['from'] ?? '');
    $toDate    = (string) ($_GET['to'] ?? '');

    // Validate the raw YYYY-MM-DD shape; fall back to the 30-day default on any
    // malformed / missing value so a bad query string never throws.
    $validDate = static function (string $d): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return false; }
        $ts = strtotime($d);
        return $ts !== false && date('Y-m-d', $ts) === $d;
    };
    if (!$validDate($fromDate)) { $fromDate = date('Y-m-d', strtotime('-29 days')); }
    if (!$validDate($toDate))   { $toDate   = $todayDate; }
    // If the admin inverts the range, swap so [from,to] is always ascending.
    if ($fromDate > $toDate) { [$fromDate, $toDate] = [$toDate, $fromDate]; }

    $from = $fromDate . ' 00:00:00';
    $to   = $toDate   . ' 23:59:59';

    // ---- Pull the funnel. Fail-soft: the helper returns [] on any DB error. ---
    $report = function_exists('ad_campaign_conversion_report')
        ? ad_campaign_conversion_report($db, $from, $to)
        : [];

    // ---- Totals row so the figures reconcile at a glance (incl. (none)). ------
    // Overall conversion rate is paid-stage-agnostic: it uses held/conversations,
    // the same ratio the per-row rate uses, computed on the summed totals.
    $totals = [
        'conversations' => 0,
        'held_bookings' => 0,
        'paid_bookings' => 0,
        'revenue'       => 0.0,
        'currencies'    => [], // currency => summed revenue (for a mixed-currency note)
    ];
    foreach ($report as $r) {
        $totals['conversations'] += (int) ($r['conversations'] ?? 0);
        $totals['held_bookings'] += (int) ($r['held_bookings'] ?? 0);
        $totals['paid_bookings'] += (int) ($r['paid_bookings'] ?? 0);
        $totals['revenue']       += (float) ($r['revenue'] ?? 0);
        $cur = trim((string) ($r['revenue_currency'] ?? ''));
        if ($cur !== '' && (float) ($r['revenue'] ?? 0) != 0.0) {
            $totals['currencies'][$cur] = ($totals['currencies'][$cur] ?? 0.0) + (float) $r['revenue'];
        }
    }

    $title = 'Conversion Analytics'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/conversion.php";
    require_once views."includes/footer.php";
});
