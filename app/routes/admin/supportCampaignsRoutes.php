<?php
// app/routes/admin/supportCampaignsRoutes.php
// Customer Support module — Phase 4: GIA ad-campaign REGISTRY admin CRUD.
// One row per registered ad campaign / offer (Meta / TikTok / Google / other).
// A visitor arriving from a registered ad is attributed to the campaign so GIA
// answers campaign-aware, and the conversion funnel (ad -> chat -> held booking
// -> paid booking) is tracked per campaign. Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§2 data model, §7 LOCKED P4).
//
// Shape mirrors app/routes/admin/campaignsRoutes.php EXACTLY: list/create/edit/
// save/delete. Every handler is ADMIN_AUTH() + the admin_can($db,
// 'campaigns.manage') capability gate, CSRF on POST. The self-healing schema
// (gia_ad_campaigns + gia_conversations.campaign_id) lives in
// app/lib/support/campaigns.php (ensureAdCampaignSchema), loaded globally in
// config.php — called at the top of every handler here for defence in depth.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../../lib/support/campaigns.php'; // schema + attribution helpers

// The real bookable services the offer can scope to (offer_product). Kept in one
// place so the form options and the save-time whitelist can never drift apart.
if (!function_exists('support_campaign_offer_products')) {
    function support_campaign_offer_products(): array
    {
        return ['flights', 'stays', 'umrah', 'tours', 'cars', 'visa', 'esim', 'ferries', 'rail', 'bus'];
    }
}

// ================================ LIST
$router->get(admin.'/support/campaigns', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'view')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Ad Campaigns'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/campaigns.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/campaigns/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'New Ad Campaign'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/campaigns-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/support/campaigns/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Ad Campaign'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/campaigns-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (create/update)
$router->post(admin.'/support/campaigns/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));

    // platform + offer_product are whitelisted against their locked sets.
    $platform = strtolower(trim((string) ($_POST['platform'] ?? '')));
    if (!in_array($platform, ['meta', 'tiktok', 'google', 'other'], true)) { $platform = 'other'; }

    $offerProduct = strtolower(trim((string) ($_POST['offer_product'] ?? '')));
    if (!in_array($offerProduct, support_campaign_offer_products(), true)) { $offerProduct = null; }

    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Campaign name is required.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/campaigns');
        return;
    }

    // Optional window. A datetime-local value is normalised to Y-m-d H:i:s; blank
    // clears it (always-on). Invalid input is treated as "no value".
    $norm = static function ($raw): ?string {
        $raw = trim((string) $raw);
        if ($raw === '') { return null; }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    };

    $data = [
        'platform'      => $platform,
        'name'          => $name,
        'offer_intent'  => trim((string) ($_POST['offer_intent'] ?? '')) ?: null,
        'offer_product' => $offerProduct,
        'utm_source'    => trim((string) ($_POST['utm_source'] ?? '')) ?: null,
        'utm_campaign'  => trim((string) ($_POST['utm_campaign'] ?? '')) ?: null,
        'utm_medium'    => trim((string) ($_POST['utm_medium'] ?? '')) ?: null,
        'match_key'     => trim((string) ($_POST['match_key'] ?? '')) ?: null,
        // The word an ad tells people to send on a click-to-WhatsApp / messaging ad
        // (e.g. "UMRAHDEC"). Normalised: strip spaces/punctuation, uppercase, so the
        // channel matcher (ad_campaign_resolve_channel) can do a clean whole-word hit.
        'match_keyword' => (function ($v) {
            $v = strtoupper(trim((string) $v));
            $v = preg_replace('/[^A-Z0-9]/', '', $v);
            return $v !== '' ? substr($v, 0, 60) : null;
        })($_POST['match_keyword'] ?? ''),
        'active'        => !empty($_POST['active']) ? 1 : 0,
        'starts_at'     => $norm($_POST['starts_at'] ?? ''),
        'ends_at'       => $norm($_POST['ends_at'] ?? ''),
    ];

    try {
        if ($id > 0) {
            $existing = $db->get('gia_ad_campaigns', ['id'], ['id' => $id]);
            if (!$existing) { throw new Exception('Campaign not found'); }
            $db->update('gia_ad_campaigns', $data, ['id' => $id]);
        } else {
            $data['created_by'] = (string) ($_SESSION['user_id'] ?? '') ?: null;
            $db->insert('gia_ad_campaigns', $data);
            $id = (int) $db->id();
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Campaign saved.'];
        redirect(root . admin . '/support/campaigns/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/campaigns');
    }
});

// ================================ DELETE
$router->post(admin.'/support/campaigns/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureAdCampaignSchema($db);
    if (!admin_can($db, 'support_campaigns', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id > 0) {
            $db->delete('gia_ad_campaigns', ['id' => $id]);
            // Detach any conversations attributed to this campaign so the FK-less
            // join in the conversion report doesn't point at a deleted row.
            try { $db->update('gia_conversations', ['campaign_id' => null], ['campaign_id' => $id]); }
            catch (\Throwable $e) { /* column may not exist on legacy installs */ }
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Campaign deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/support/campaigns');
});
