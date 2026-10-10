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
//   or ?stay_id={id} on a cross-property page.
$activeStay = 0;
if (preg_match('#/supplier/stays/(?:edit/|site/)?(\d+)#', $cur, $m)) {
    $activeStay = (int) $m[1];
} elseif (!empty($_GET['stay_id'])) {
    $activeStay = (int) $_GET['stay_id'];
}
// Verify ownership — never render a property context the user can't access.
$stayName = '';
if ($activeStay > 0) {
    if (!$can('hotels', 'view', $activeStay)) { $activeStay = 0; }
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
    $pmItems = [
        ['name' => 'Overview',      'icon' => 'dashboard',        'url' => 'supplier/stays/' . $sid, 'show' => true],
        ['name' => 'Rooms & rates', 'icon' => 'bed',              'url' => 'supplier/stays/' . $sid . '/rooms', 'show' => $can('rooms', 'view', $sid)],
        ['name' => 'Reservations',  'icon' => 'receipt_long',     'url' => 'supplier/reservations' . $q, 'show' => $can('reservations', 'view', $sid)],
        ['name' => 'Housekeeping',  'icon' => 'cleaning_services','url' => 'supplier/housekeeping' . $q, 'show' => $can('rooms', 'view', $sid)],
        ['name' => 'Maintenance',   'icon' => 'build',            'url' => 'supplier/maintenance' . $q, 'show' => $can('rooms', 'edit', $sid)],
        ['name' => 'F&B / POS',     'icon' => 'restaurant',       'url' => 'supplier/pos', 'show' => $can('reservations', 'edit', $sid)],
        ['name' => 'Events',        'icon' => 'celebration',      'url' => 'supplier/events' . $q, 'show' => $can('reservations', 'edit', $sid)],
        ['name' => 'Night audit',   'icon' => 'nightlight',       'url' => 'supplier/night-audit' . $q, 'show' => $can('reservations', 'edit', $sid)],
        ['name' => 'Reviews',       'icon' => 'reviews',          'url' => 'supplier/reviews' . $q, 'show' => $can('reservations', 'view', $sid)],
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
        ['name' => 'Insights',  'icon' => 'insights',   'url' => 'supplier/insights',  'show' => $can('reservations', 'view')],
    ]];

    // Service entry points — only GRANTED services. Stays → the hotels list (drill-in).
    $svcItems = [];
    if (isset($granted['stays'])) {
        $svcItems[] = ['name' => 'Hotels / Stays', 'icon' => 'hotel', 'url' => 'supplier/stays', 'show' => $can('hotels', 'view')];
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
    if ($can('reservations', 'view')) { $crmItems[] = ['name' => 'Guests', 'icon' => 'groups', 'url' => 'supplier/guests', 'show' => true]; }
    if (!empty($crmItems)) { $groups[] = ['label' => 'CRM', 'items' => $crmItems]; }

    if ($isOwner) {
        $groups[] = ['label' => 'Finance & stores', 'items' => [
            ['name' => 'Payouts',         'icon' => 'account_balance',  'url' => 'supplier/payouts', 'show' => true],
            ['name' => 'Property owners', 'icon' => 'real_estate_agent','url' => 'supplier/owners', 'show' => true],
            ['name' => 'Procurement',     'icon' => 'inventory_2',      'url' => 'supplier/procurement', 'show' => true],
        ]];
        $groups[] = ['label' => 'Team', 'items' => [
            ['name' => 'Staff', 'icon' => 'group', 'url' => 'supplier/staff', 'show' => true],
            ['name' => 'Roles', 'icon' => 'badge', 'url' => 'supplier/roles', 'show' => true],
        ]];
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
  <nav class="flex-1 min-h-0 overflow-y-auto py-2">
    <?php foreach ($groups as $g): ?>
      <?php
        $items = array_values(array_filter($g['items'], fn($it) => !empty($it['show'])));
        if (empty($items)) { continue; }
      ?>
      <?php if (!empty($g['label'])): ?>
        <div class="px-4 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= htmlspecialchars($g['label']) ?></div>
      <?php endif; ?>
      <?php foreach ($items as $it): $on = $active(root . $it['url']); ?>
        <a href="<?= root . htmlspecialchars($it['url']) ?>"
           class="flex items-center gap-3 px-4 py-2 text-sm transition-colors <?= $on ? 'bg-violet-50 text-violet-700 font-medium border-r-2 border-violet-600' : 'text-gray-600 hover:bg-gray-50' ?>">
          <span class="material-symbols-outlined text-[20px] <?= $on ? 'text-violet-600' : 'text-gray-400' ?>"><?= htmlspecialchars($it['icon']) ?></span>
          <span><?= htmlspecialchars($it['name']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <!-- Footer -->
  <div class="border-t border-gray-100 p-2 flex-shrink-0">
    <a href="<?= root ?>profile" class="flex items-center gap-3 px-2 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded-lg"><span class="material-symbols-outlined text-[20px] text-gray-400">person</span> Profile</a>
    <a href="<?= root ?>logout" class="flex items-center gap-3 px-2 py-2 text-sm text-red-500 hover:bg-red-50 rounded-lg"><span class="material-symbols-outlined text-[20px]">logout</span> Sign out</a>
  </div>
</aside>
