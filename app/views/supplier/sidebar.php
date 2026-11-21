<?php
// FILE: app/views/supplier/sidebar.php
// SUPPLIER SIDEBAR — CONTEXT-AWARE (inc S34 base; S35 per-property drill-in).
//
// Two modes:
//   TOP LEVEL (not inside a property): account items + the GRANTED services + "my
//     hotels" + team/finance (owner-only). This is the supplier's home nav.
//   INSIDE A PROPERTY (the path/query carries a stay id this owner owns): the sidebar
//     switches to THAT property's full PMS — rooms, reservations, folio/front-desk,
//     housekeeping, maintenance, F&B, events, night audit, reviews, site — every
//     cross-property page pre-scoped with ?stay_id={id} so it shows only this hotel.
//     Topped with the property name + a "‹ All properties" crumb.
//
// Gating unchanged: items use supplier_can(); owner-only areas hidden from staff.
// Self-guards: renders nothing unless supplier_acting_context() resolves.
@$SECURE or die('Access Denied!');

global $db;
$__ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
if ($__ctx === null || !empty($__ctx['is_admin'])) { return; }

$owner    = (string) $__ctx['owner'];
$isOwner  = !empty($__ctx['is_owner']);
$cur      = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$can = function ($module, $action, $stayId = null) use ($db) {
    return function_exists('supplier_can') ? supplier_can($db, $module, $action, $stayId) : true;
};
// Org-wide capability — mirrors the route gate (supplier_can_orgwide). A 'selected'-scope
// staffer is denied, so the nav we draw matches exactly what the route will let them reach.
$canOrg = function ($module, $action) use ($db) {
    return function_exists('supplier_can_orgwide') ? supplier_can_orgwide($db, $module, $action) : true;
};
$active = function (string $url) use ($cur) {
    // Compare path only; the property-scoped links differ by ?stay_id so match on path.
    $p = rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
    $q = parse_url($url, PHP_URL_QUERY);
    if ($p !== $cur && !($p !== '' && strpos($cur, $p . '/') === 0)) { return false; }
    // For query-scoped links, also require the stay_id to match the current one.
    if ($q && strpos($q, 'stay_id=') !== false) {
        parse_str($q, $qa); parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '', $ca);
        return ($qa['stay_id'] ?? null) == ($ca['stay_id'] ?? null);
    }
    return true;
};

// --- Detect the ACTIVE property (drill-in). Path forms:
//   /supplier/stays/{id}            /supplier/stays/{id}/...    (rooms, calendar)
//   /supplier/stays/edit/{id}       /supplier/stays/site/{id}
//   or ?stay_id={id} on a cross-property page,
//   or an explicit $supplierActiveStay set by a property SUB-page whose URL carries no
//   stay_id (e.g. /supplier/pos/outlet/{id}) — the page knows its stay and declares it so
//   the sidebar stays in property mode instead of reverting to the global one (report V2 #4).
$activeStay = 0;
if (!empty($GLOBALS['supplierActiveStay']) && (int) $GLOBALS['supplierActiveStay'] > 0) {
    $activeStay = (int) $GLOBALS['supplierActiveStay'];
} elseif (preg_match('#/supplier/stays/(?:edit/|site/)?(\d+)#', $cur, $m)) {
    $activeStay = (int) $m[1];
} elseif (!empty($_GET['stay_id'])) {
    $activeStay = (int) $_GET['stay_id'];
}
// Verify ACCESS — never render a property context the user can't access, but don't
// require 'hotels:view' specifically. A front-desk staffer legitimately has
// reservations/rooms access without property-editing rights; gating the WHOLE property
// sidebar on hotels:view hid Front desk / Room rack / Housekeeping etc. from them and
// left them stuck on the global sidebar even while standing on a property page (report
// V2 #2). Enter property mode if the user may view ANY property-scoped area; each sidebar
// item is still individually permission-gated below, so this only decides which MENU shows.
$stayName = '';
if ($activeStay > 0) {
    $canSeeProperty = $can('hotels', 'view', $activeStay)
        || $can('reservations', 'view', $activeStay)
        || $can('rooms', 'view', $activeStay)
        || $can('rates', 'view', $activeStay);
    if (!$canSeeProperty) { $activeStay = 0; }
    else {
        try { $s = $db->get('stays', ['name'], ['id' => $activeStay]); $stayName = (string) ($s['name'] ?? ''); }
        catch (\Throwable $e) { $activeStay = 0; }
    }
}

// Supplier display name.
$supName = '';
try { $u = $db->get('users', ['first_name', 'last_name', 'title'], ['user_id' => $owner]); if ($u) { $supName = trim(($u['title'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); } } catch (\Throwable $e) {}

$groups = [];

if ($activeStay > 0) {
    // ===== INSIDE A PROPERTY: that hotel's full PMS =====
    $sid = $activeStay;
    $q = '?stay_id=' . $sid;
    // Order mirrors the Roles-page group sequence so the owner reads the same mental model
    // in both places: Front desk & reservations → Guests & CRM → Rooms & operations →
    // Rates & distribution → Property & tenancy (Step 6 of the permission redesign).
    $pmItems = [
        ['name' => 'Overview',      'icon' => 'dashboard',        'url' => 'supplier/stays/' . $sid, 'show' => true],

        // --- Front desk & reservations ---
        ['name' => 'Front desk',    'icon' => 'concierge',        'url' => 'supplier/front-desk' . $q, 'show' => $can('reservations', 'view', $sid)],
        ['name' => 'Reservations',  'icon' => 'receipt_long',     'url' => 'supplier/reservations' . $q, 'show' => $can('reservations', 'view', $sid)],
        ['name' => 'Room rack',     'icon' => 'grid_view',        'url' => 'supplier/stays/' . $sid . '/tape-chart', 'show' => $can('reservations', 'view', $sid)],
        ['name' => 'Shift & wake-ups','icon' => 'alarm',           'url' => 'supplier/stays/' . $sid . '/shift', 'show' => $can('reservations', 'view', $sid)],
        ['name' => 'Groups & blocks','icon' => 'groups_3',        'url' => 'supplier/groups' . $q, 'show' => $can('groups', 'view', $sid)],
        ['name' => 'Night audit',   'icon' => 'nightlight',       'url' => 'supplier/night-audit' . $q, 'show' => $can('nightaudit', 'view', $sid)],
        ['name' => 'F&B / POS',     'icon' => 'restaurant',       'url' => 'supplier/pos' . $q, 'show' => $can('pos', 'view', $sid)],
        ['name' => 'Recipes',       'icon' => 'menu_book',        'url' => 'supplier/recipes' . $q, 'show' => $can('recipes', 'view', $sid)],
        ['name' => 'Kitchen & food cost','icon' => 'soup_kitchen', 'url' => 'supplier/kitchen' . $q, 'show' => $can('kitchen', 'view', $sid)],
        ['name' => 'Spa & wellness','icon' => 'self_improvement',  'url' => 'supplier/spa' . $q, 'show' => $can('spa', 'view', $sid)],
        ['name' => 'Services',      'icon' => 'spa',              'url' => 'supplier/ancillary-services' . $q, 'show' => $can('services', 'view', $sid)],
        ['name' => 'Resources',     'icon' => 'local_shipping',    'url' => 'supplier/resources' . $q, 'show' => $can('resources', 'view', $sid)],
        ['name' => 'Events',        'icon' => 'celebration',      'url' => 'supplier/events' . $q, 'show' => $can('events', 'view', $sid)],

        // --- Guests & CRM (property-level reviews/reputation) ---
        ['name' => 'Reviews',       'icon' => 'reviews',          'url' => 'supplier/reviews' . $q, 'show' => $can('reviews', 'view', $sid)],
        ['name' => 'Reputation',    'icon' => 'stars',            'url' => 'supplier/reputation' . $q, 'show' => $can('reputation', 'view', $sid)],

        // --- Rooms & operations ---
        ['name' => 'Housekeeping',  'icon' => 'cleaning_services','url' => 'supplier/housekeeping' . $q, 'show' => $can('housekeeping', 'view', $sid)],
        ['name' => 'Maintenance',   'icon' => 'build',            'url' => 'supplier/maintenance' . $q, 'show' => $can('maintenance', 'view', $sid)],
        ['name' => 'Engineering',   'icon' => 'engineering',       'url' => 'supplier/maintenance/engineering' . $q, 'show' => $can('maintenance', 'edit', $sid)],
        ['name' => 'Laundry',       'icon' => 'local_laundry_service','url' => 'supplier/laundry' . $q, 'show' => $can('laundry', 'view', $sid)],
        ['name' => 'Minibar',       'icon' => 'liquor',           'url' => 'supplier/minibar' . $q, 'show' => $can('minibar', 'view', $sid)],
        ['name' => 'Lost & found',  'icon' => 'inventory',        'url' => 'supplier/lost-found' . $q, 'show' => $can('lostfound', 'view', $sid)],
        ['name' => 'Assets',        'icon' => 'chair',            'url' => 'supplier/assets' . $q, 'show' => $can('assets', 'view', $sid)],
        ['name' => 'Safety & docs', 'icon' => 'health_and_safety','url' => 'supplier/safety' . $q, 'show' => $can('safety', 'view', $sid)],

        // --- Rates & distribution ---
        ['name' => 'Rooms & rates', 'icon' => 'bed',              'url' => 'supplier/stays/' . $sid . '/rooms', 'show' => $can('rooms', 'view', $sid)],
        ['name' => 'Rate rules',    'icon' => 'tune',             'url' => 'supplier/stays/' . $sid . '/rate-rules', 'show' => $can('rates', 'view', $sid)],
        ['name' => 'Calendar',      'icon' => 'calendar_month',   'url' => 'supplier/calendar' . $q, 'show' => $can('calendar', 'view', $sid)],
        ['name' => 'Channels',      'icon' => 'hub',              'url' => 'supplier/stays/' . $sid . '/channels', 'show' => $can('channels', 'view', $sid)],
        ['name' => 'Revenue mgmt',  'icon' => 'trending_up',      'url' => 'supplier/stays/' . $sid . '/rms', 'show' => $can('rms', 'view', $sid)],
        ['name' => 'Packages',      'icon' => 'redeem',           'url' => 'supplier/packages' . $q, 'show' => $can('packages', 'view', $sid)],

        // --- Property & tenancy ---
        ['name' => 'Buildings',     'icon' => 'apartment',        'url' => 'supplier/stays/' . $sid . '/buildings', 'show' => $can('hotels', 'view', $sid)],
        ['name' => 'Leases',        'icon' => 'home_work',        'url' => 'supplier/leases' . $q, 'show' => $can('leases', 'view', $sid)],
        ['name' => 'Utilities',     'icon' => 'bolt',             'url' => 'supplier/utilities' . $q, 'show' => $can('leases', 'view', $sid)],
        ['name' => 'Site & domain', 'icon' => 'public',           'url' => 'supplier/stays/site/' . $sid, 'show' => $can('hotels', 'edit', $sid)],
        ['name' => 'Edit property', 'icon' => 'edit',             'url' => 'supplier/stays/edit/' . $sid, 'show' => $can('hotels', 'edit', $sid)],
    ];
    $groups[] = ['label' => null, 'items' => [['name' => 'All properties', 'icon' => 'arrow_back', 'url' => 'supplier/stays', 'show' => true]]];
    $groups[] = ['label' => 'Property', 'items' => $pmItems];
} else {
    // ===== TOP LEVEL: account + granted services =====
    $granted = function_exists('supplier_granted_services') ? supplier_granted_services($db, $owner, true) : [];

    $groups[] = ['label' => null, 'items' => [
        ['name' => 'Dashboard', 'icon' => 'dashboard', 'url' => 'supplier/dashboard', 'show' => true],
        ['name' => 'Insights',  'icon' => 'insights',   'url' => 'supplier/insights',  'show' => $can('bi', 'view')],
        ['name' => 'Portfolio', 'icon' => 'domain',     'url' => 'supplier/chain', 'show' => $isOwner],
        ['name' => 'Marketplace','icon' => 'storefront', 'url' => 'supplier/marketplace', 'show' => $canOrg('marketplace', 'view')],
        ['name' => 'Activity',  'icon' => 'notifications', 'url' => 'supplier/activity', 'show' => true],
    ]];

    // Service entry points — only GRANTED services. Stays → the hotels list (drill-in).
    // "Hotels / Stays" is the drill-in to each property's PMS, so it must be visible to any
    // staffer who can work INSIDE a property — not only those with hotels:view. A front-desk
    // staffer granted reservations (but not hotels:view) otherwise had NO top-level path to
    // reach a property → their front desk (Report 11.20 #1a). Mirror the property-mode entry
    // rule (reservations/rooms/rates view all open a property) so the link appears whenever
    // the drill-in would actually work.
    $canEnterProperty = $can('hotels', 'view') || $can('reservations', 'view')
        || $can('rooms', 'view') || $can('rates', 'view');
    $svcItems = [];
    if (isset($granted['stays'])) {
        $svcItems[] = ['name' => 'Hotels / Stays', 'icon' => 'hotel', 'url' => 'supplier/stays', 'show' => $canEnterProperty];
        // Direct Front desk shortcut for reservations staff. /supplier/front-desk auto-scopes
        // to the staffer's single property (route lines 243-246), so this lands them straight
        // on their desk without first hunting through the properties list.
        $svcItems[] = ['name' => 'Front desk', 'icon' => 'concierge', 'url' => 'supplier/front-desk', 'show' => $can('reservations', 'view')];
    }
    // (flights/tours/cars/bus listing surfaces are admin-managed today; shown as the
    // public service pages for now — a granted non-stays service still appears so the
    // supplier can see it's active.)
    foreach (['flights' => 'flight', 'tours' => 'tour', 'cars' => 'directions_car', 'bus' => 'directions_bus'] as $svc => $icon) {
        if (isset($granted[$svc])) {
            $svcItems[] = ['name' => $granted[$svc]['label'], 'icon' => $icon, 'url' => 'supplier/services/' . $svc, 'show' => true];
        }
    }
    if (!empty($svcItems)) { $groups[] = ['label' => 'My services', 'items' => $svcItems]; }

    // Guest CRM is org-wide (across properties) → stays top-level.
    $crmItems = [];
    if ($canOrg('customers', 'view')) { $crmItems[] = ['name' => 'Customers', 'icon' => 'contacts', 'url' => 'supplier/customers', 'show' => true]; }
    if ($canOrg('guests', 'view')) { $crmItems[] = ['name' => 'Guests', 'icon' => 'groups', 'url' => 'supplier/guests', 'show' => true]; }
    if ($canOrg('guestcrm', 'view')) { $crmItems[] = ['name' => 'Guest CRM', 'icon' => 'insights', 'url' => 'supplier/guests-crm', 'show' => true]; }
    if ($can('guestservice', 'view')) { $crmItems[] = ['name' => 'Service & complaints', 'icon' => 'support_agent', 'url' => 'supplier/service-tickets', 'show' => true]; }
    if ($can('guestservice', 'view')) { $crmItems[] = ['name' => 'Guest messages', 'icon' => 'forum', 'url' => 'supplier/guest-messages', 'show' => true]; }
    if ($canOrg('journey', 'view')) { $crmItems[] = ['name' => 'Guest journey', 'icon' => 'waving_hand', 'url' => 'supplier/journey', 'show' => true]; }
    if ($canOrg('loyalty', 'view')) { $crmItems[] = ['name' => 'Loyalty', 'icon' => 'loyalty', 'url' => 'supplier/loyalty', 'show' => true]; }
    if (!empty($crmItems)) { $groups[] = ['label' => 'Guests & CRM', 'items' => $crmItems]; }

    // Back-office groups — now PERMISSION-gated, not owner-only, so a delegated staffer
    // (e.g. an Accountant with 'finance', a procurement officer, an HR role) sees the nav
    // they can actually use. Each item's `show` is its real permission; truly owner-only
    // money-out / admin items keep $isOwner. Empty groups are dropped below.
    // Per-area nav gating — each item's `show` now mirrors the EXACT per-area key its
    // route enforces (Step 5 of the permission redesign). A staffer granted a single
    // area (e.g. 'ar:view') sees exactly that one menu item. Org-wide routes use
    // $canOrg (denies 'selected'-scope staff, matching supplier_can_orgwide); property
    // routes use $can. Truly owner-only money-OUT / credential items keep $isOwner.
    $canHr   = $canOrg('hr', 'view');
    $backGroups = [
        ['label' => 'Finance & accounting', 'items' => [
            ['name' => 'Finance',         'icon' => 'account_balance_wallet', 'url' => 'supplier/finance', 'show' => $canOrg('gl', 'view')],
            // GL + Treasury routes require EDIT (not view) — gate their nav on edit so a
            // view-only staffer isn't shown a link that bounces (RBAC audit 2026-11-15).
            ['name' => 'General ledger',  'icon' => 'receipt_long',     'url' => 'supplier/finance/ledger', 'show' => $canOrg('gl', 'edit')],
            ['name' => 'Accounts receivable','icon' => 'request_quote', 'url' => 'supplier/ar', 'show' => $canOrg('ar', 'view')],
            ['name' => 'Accounts payable','icon' => 'payments',         'url' => 'supplier/ap', 'show' => $canOrg('ap', 'view')],
            ['name' => 'Treasury',        'icon' => 'account_balance',  'url' => 'supplier/treasury', 'show' => $canOrg('treasury', 'edit')],
            ['name' => 'Tax & fiscal',    'icon' => 'percent',          'url' => 'supplier/tax', 'show' => $canOrg('tax', 'view')],
            ['name' => 'Payments & deposits','icon' => 'credit_card',   'url' => 'supplier/payments', 'show' => $canOrg('payments', 'view')],
            // Cashier is a per-user till shift — a front-desk/reservations duty, not org finance.
            ['name' => 'Cashier',         'icon' => 'point_of_sale',    'url' => 'supplier/cashier', 'show' => $can('cashier', 'view')],
            // Money-OUT stays owner-only.
            ['name' => 'Payouts & wallet','icon' => 'wallet',           'url' => 'supplier/payouts', 'show' => $isOwner],
            ['name' => 'Settlement',      'icon' => 'account_balance',  'url' => 'supplier/settlement', 'show' => $canOrg('settlement', 'view')],
        ]],
        ['label' => 'Procurement & stores', 'items' => [
            ['name' => 'Procurement',     'icon' => 'inventory_2',      'url' => 'supplier/procurement', 'show' => $canOrg('procurement', 'view')],
            ['name' => 'Stores & inventory','icon' => 'warehouse',      'url' => 'supplier/stores', 'show' => $canOrg('stores', 'view')],
            ['name' => 'Vendors',         'icon' => 'handshake',        'url' => 'supplier/vendors', 'show' => $canOrg('vendors', 'view')],
        ]],
        ['label' => 'Owners & tenancy', 'items' => [
            ['name' => 'Corporate & agents','icon' => 'corporate_fare', 'url' => 'supplier/corporate', 'show' => $canOrg('corporate', 'view')],
            ['name' => 'Property owners', 'icon' => 'real_estate_agent','url' => 'supplier/owners', 'show' => $canOrg('owneracct', 'view')],
            ['name' => 'Owner accounting','icon' => 'account_balance_wallet','url' => 'supplier/owners/manage', 'show' => $canOrg('owneracct', 'view')],
            // Lease billing posts rent money → requires leases:edit (route gate).
            ['name' => 'Lease billing',   'icon' => 'home_work',        'url' => 'supplier/lease-billing', 'show' => $can('leases', 'edit')],
            // Trust/client-money is gated with the corporate key (shares _corp_org).
            ['name' => 'Client-money trust','icon' => 'savings',        'url' => 'supplier/trust', 'show' => $canOrg('corporate', 'view')],
            ['name' => 'Apartments',      'icon' => 'apartment',        'url' => 'supplier/apartment/manage', 'show' => $can('apartments', 'view')],
        ]],
        ['label' => 'People', 'items' => [
            // Staff membership vs role/permission system are now distinct areas.
            ['name' => 'Staff', 'icon' => 'group', 'url' => 'supplier/staff', 'show' => $canOrg('staff', 'view')],
            ['name' => 'Roles', 'icon' => 'badge', 'url' => 'supplier/roles', 'show' => $canOrg('rbac', 'view')],
            ['name' => 'Access control', 'icon' => 'admin_panel_settings', 'url' => 'supplier/rbac', 'show' => $canOrg('rbac', 'view')],
            ['name' => 'HR & workforce', 'icon' => 'badge', 'url' => 'supplier/hr', 'show' => $canHr],
        ]],
        ['label' => 'Intelligence & platform', 'items' => [
            // Tasks & workflow sits here to match the Roles-page "Intelligence & platform" group.
            ['name' => 'Tasks & workflow', 'icon' => 'checklist', 'url' => 'supplier/workflow', 'show' => $canOrg('workflow', 'view')],
            ['name' => 'Executive BI',    'icon' => 'leaderboard',      'url' => 'supplier/insights/executive', 'show' => $canOrg('bi', 'view')],
            ['name' => 'AI & automation',  'icon' => 'auto_awesome',    'url' => 'supplier/ai-ext', 'show' => $can('ai', 'view')],
            ['name' => 'Alerts',          'icon' => 'notifications_active','url' => 'supplier/alerts', 'show' => $canOrg('alerts', 'view')],
            ['name' => 'Compliance',      'icon' => 'verified_user',    'url' => 'supplier/compliance', 'show' => $can('compliance', 'view')],
            ['name' => 'Smart locks',     'icon' => 'lock',             'url' => 'supplier/access/locks', 'show' => $can('locks', 'view')],
            ['name' => 'Smart building',  'icon' => 'sensors',          'url' => 'supplier/iot', 'show' => $can('iot', 'view')],
            // API keys/webhook secrets are credential management → owner-only (not a delegation).
            ['name' => 'API & webhooks',  'icon' => 'api',              'url' => 'supplier/api', 'show' => $isOwner],
            // Data migration touches property + opening-balance imports → owner-only.
            ['name' => 'Data migration',  'icon' => 'upload_file',      'url' => 'supplier/migration', 'show' => $isOwner],
            ['name' => 'Security & ops',  'icon' => 'security',         'url' => 'supplier/platform-ops', 'show' => $canOrg('platformops', 'view')],
            ['name' => 'Integrations', 'icon' => 'hub', 'url' => 'supplier/integrations', 'show' => $isOwner],
            ['name' => 'Growth & brands', 'icon' => 'domain', 'url' => 'supplier/growth', 'show' => $isOwner],
        ]],
    ];
    // Only add a group that has at least one visible item (so a finance-only staffer
    // doesn't see an empty "Procurement" header, etc.).
    foreach ($backGroups as $__bg) {
        foreach ($__bg['items'] as $__it) { if (!empty($__it['show'])) { $groups[] = $__bg; break; } }
    }

    $acctItems = [['name' => 'Get started', 'icon' => 'rocket_launch', 'url' => 'supplier/get-started', 'show' => true]];
    if ($isOwner) { $acctItems[] = ['name' => 'Request services / quota', 'icon' => 'add_business', 'url' => 'supplier/request-access', 'show' => true]; }
    $groups[] = ['label' => 'Account', 'items' => $acctItems];
}
?>
<input type="checkbox" id="supplier-nav" class="peer hidden">
<label for="supplier-nav" class="lg:hidden fixed top-3 left-3 z-[60] w-10 h-10 rounded-lg bg-white shadow border border-gray-200 flex items-center justify-center cursor-pointer">
  <span class="material-symbols-outlined text-gray-700">menu</span>
</label>
<label for="supplier-nav" class="lg:hidden fixed inset-0 z-[55] bg-black/40 hidden peer-checked:block"></label>

<aside class="fixed top-0 left-0 z-[58] h-screen w-[240px] bg-white border-r border-gray-200 flex flex-col
              -translate-x-full peer-checked:translate-x-0 lg:translate-x-0 transition-transform">
  <!-- Brand / property header -->
  <?php if ($activeStay > 0): ?>
    <div class="px-4 h-14 border-b border-gray-100 flex items-center gap-2 flex-shrink-0 bg-violet-50">
      <span class="material-symbols-outlined text-violet-600 text-xl">hotel</span>
      <span class="min-w-0">
        <span class="block text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($stayName !== '' ? $stayName : ('Property #' . $activeStay)) ?></span>
        <span class="block text-[11px] text-violet-500">Property workspace</span>
      </span>
    </div>
  <?php else: ?>
    <a href="<?= root ?>supplier/dashboard" class="flex items-center gap-2 px-4 h-14 border-b border-gray-100 flex-shrink-0">
      <span class="w-8 h-8 rounded-lg bg-violet-100 flex items-center justify-center"><span class="material-symbols-outlined text-violet-600 text-xl">storefront</span></span>
      <span class="min-w-0">
        <span class="block text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($supName !== '' ? $supName : 'Supplier') ?></span>
        <span class="block text-[11px] text-gray-400"><?= $isOwner ? 'Owner' : 'Team member' ?></span>
      </span>
    </a>
  <?php endif; ?>

  <!-- Nav -->
  <nav id="supplier-nav-scroll" class="flex-1 min-h-0 overflow-y-auto py-2">
    <?php foreach ($groups as $g): ?>
      <?php
        // NOTE: use a nav-local variable name ($navItems), NOT $items — the sidebar is
        // include'd (via header.php) AFTER operational routes set their own $items
        // (e.g. laundry/minibar/procurement lists). Reusing $items here clobbered the
        // route's data with nav rows, 500ing those views on $it['status']/['qty'] (UAT).
        $navItems = array_values(array_filter($g['items'], fn($it) => !empty($it['show'])));
        if (empty($navItems)) { continue; }
      ?>
      <?php if (!empty($g['label'])): ?>
        <div class="px-4 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= htmlspecialchars($g['label']) ?></div>
      <?php endif; ?>
      <?php foreach ($navItems as $it): $on = $active(root . $it['url']); ?>
        <a href="<?= root . htmlspecialchars($it['url']) ?>"
           class="flex items-center gap-3 px-4 py-2 text-sm transition-colors <?= $on ? 'bg-violet-50 text-violet-700 font-medium border-r-2 border-violet-600' : 'text-gray-600 hover:bg-gray-50' ?>">
          <span class="material-symbols-outlined text-[20px] <?= $on ? 'text-violet-600' : 'text-gray-400' ?>"><?= htmlspecialchars($it['icon']) ?></span>
          <span><?= htmlspecialchars($it['name']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <!-- Footer -->
  <div class="border-t border-gray-100 p-2 flex-shrink-0" id="supplier-nav-footer">
    <a href="<?= root ?>profile" class="flex items-center gap-3 px-2 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded-lg"><span class="material-symbols-outlined text-[20px] text-gray-400">person</span> Profile</a>
    <a href="<?= root ?>logout?t=<?= urlencode(class_exists('CSRF') ? CSRF::getToken() : '') ?>" class="flex items-center gap-3 px-2 py-2 text-sm text-red-500 hover:bg-red-50 rounded-lg"><span class="material-symbols-outlined text-[20px]">logout</span> Sign out</a>
  </div>
</aside>
<script>
// Keep the supplier sidebar's scroll position across full-page navigations. Each page load
// re-renders the sidebar at scrollTop=0, so a staffer who scrolled down to a link lost their
// place and had to re-scroll on every click (Report 11.20 #2). We stash scrollTop in
// sessionStorage on navigate/unload and restore it before paint on the next load.
(function () {
  var nav = document.getElementById('supplier-nav-scroll');
  if (!nav) { return; }
  var KEY = 'supplierNavScroll';
  // Restore first — do it synchronously so there's no visible jump.
  try {
    var saved = sessionStorage.getItem(KEY);
    if (saved !== null) { nav.scrollTop = parseInt(saved, 10) || 0; }
  } catch (e) {}
  // Save on any outbound click within the sidebar (covers both nav + footer links) and as a
  // belt-and-braces on pagehide (back/forward, reloads).
  function save() { try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) {} }
  nav.addEventListener('click', save);
  var footer = document.getElementById('supplier-nav-footer');
  if (footer) { footer.addEventListener('click', save); }
  window.addEventListener('pagehide', save);
})();
</script>
