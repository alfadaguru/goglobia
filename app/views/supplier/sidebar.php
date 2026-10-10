<?php
// FILE: app/views/supplier/sidebar.php
// SUPPLIER SIDEBAR (inc S34). A persistent, flat, grouped left nav for the supplier
// area — shown to a supplier OWNER or active STAFF (never admin). Items are gated:
//   - service groups appear ONLY for services the owner was GRANTED (approved);
//   - owner-only areas (Payouts, Property owners, Procurement, Roles, Staff) are
//     hidden from staff;
//   - operational items use supplier_can() so the nav never shows a denied link.
// Self-guards: renders nothing unless supplier_acting_context() resolves.
@$SECURE or die('Access Denied!');

global $db;
$__ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
if ($__ctx === null || !empty($__ctx['is_admin'])) { return; }

$owner    = (string) $__ctx['owner'];
$isOwner  = !empty($__ctx['is_owner']);
$granted  = function_exists('supplier_granted_services') ? supplier_granted_services($db, $owner, true) : [];
$can = function ($module, $action) use ($db) {
    return function_exists('supplier_can') ? supplier_can($db, $module, $action) : true;
};
$cur = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$active = function (string $path) use ($cur) {
    $p = rtrim(parse_url(root . ltrim($path, '/'), PHP_URL_PATH) ?? '', '/');
    return ($cur === $p || ($p !== '' && strpos($cur, $p . '/') === 0));
};
// Supplier display name.
$supName = '';
try { $u = $db->get('users', ['first_name', 'last_name', 'title'], ['user_id' => $owner]); if ($u) { $supName = trim(($u['title'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); } } catch (\Throwable $e) {}

// Build the grouped menu from real grants + permissions.
$groups = [];

// --- Overview (always) ---
$groups[] = ['label' => null, 'items' => [
    ['name' => 'Dashboard', 'icon' => 'dashboard', 'url' => 'supplier/dashboard'],
    ['name' => 'Insights',  'icon' => 'insights',   'url' => 'supplier/insights',  'show' => $can('reservations', 'view')],
]];

// --- Stays (only if granted stays) ---
if (isset($granted['stays'])) {
    $stayItems = [];
    if ($can('hotels', 'view'))       { $stayItems[] = ['name' => 'My hotels',      'icon' => 'hotel',        'url' => 'supplier/stays']; }
    if ($can('reservations', 'view')) { $stayItems[] = ['name' => 'Reservations',   'icon' => 'receipt_long', 'url' => 'supplier/reservations']; }
    if ($can('rooms', 'view'))        { $stayItems[] = ['name' => 'Housekeeping',   'icon' => 'cleaning_services', 'url' => 'supplier/housekeeping']; }
    if ($can('rooms', 'edit'))        { $stayItems[] = ['name' => 'Maintenance',    'icon' => 'build',        'url' => 'supplier/maintenance']; }
    if ($can('reservations', 'edit')) { $stayItems[] = ['name' => 'F&B / POS',      'icon' => 'restaurant',   'url' => 'supplier/pos']; }
    if ($can('reservations', 'edit')) { $stayItems[] = ['name' => 'Events',         'icon' => 'celebration',  'url' => 'supplier/events']; }
    if ($can('reservations', 'edit')) { $stayItems[] = ['name' => 'Night audit',    'icon' => 'nightlight',   'url' => 'supplier/night-audit']; }
    if ($can('reservations', 'view')) { $stayItems[] = ['name' => 'Guests',         'icon' => 'groups',       'url' => 'supplier/guests']; }
    if ($can('reservations', 'view')) { $stayItems[] = ['name' => 'Reviews',        'icon' => 'reviews',      'url' => 'supplier/reviews']; }
    if (!empty($stayItems)) { $groups[] = ['label' => 'Stays', 'items' => $stayItems]; }
}

// --- Finance (OWNER only) ---
if ($isOwner) {
    $finItems = [
        ['name' => 'Payouts',          'icon' => 'account_balance',  'url' => 'supplier/payouts'],
        ['name' => 'Property owners',  'icon' => 'real_estate_agent','url' => 'supplier/owners'],
        ['name' => 'Procurement',      'icon' => 'inventory_2',      'url' => 'supplier/procurement'],
    ];
    $groups[] = ['label' => 'Finance & stores', 'items' => $finItems];
}

// --- Team (OWNER only) ---
if ($isOwner) {
    $groups[] = ['label' => 'Team', 'items' => [
        ['name' => 'Staff',  'icon' => 'group', 'url' => 'supplier/staff'],
        ['name' => 'Roles',  'icon' => 'badge', 'url' => 'supplier/roles'],
    ]];
}

// --- Account (always) ---
$acctItems = [
    ['name' => 'Get started', 'icon' => 'rocket_launch', 'url' => 'supplier/get-started'],
];
if ($isOwner) { $acctItems[] = ['name' => 'Request services / quota', 'icon' => 'add_business', 'url' => 'supplier/request-access']; }
$groups[] = ['label' => 'Account', 'items' => $acctItems];
?>
<input type="checkbox" id="supplier-nav" class="peer hidden">
<label for="supplier-nav" class="lg:hidden fixed top-3 left-3 z-[60] w-10 h-10 rounded-lg bg-white shadow border border-gray-200 flex items-center justify-center cursor-pointer">
  <span class="material-symbols-outlined text-gray-700">menu</span>
</label>
<label for="supplier-nav" class="lg:hidden fixed inset-0 z-[55] bg-black/40 hidden peer-checked:block"></label>

<aside class="fixed top-0 left-0 z-[58] h-screen w-[240px] bg-white border-r border-gray-200 flex flex-col
              -translate-x-full peer-checked:translate-x-0 lg:translate-x-0 transition-transform">
  <!-- Brand -->
  <a href="<?= root ?>supplier/dashboard" class="flex items-center gap-2 px-4 h-14 border-b border-gray-100 flex-shrink-0">
    <span class="w-8 h-8 rounded-lg bg-violet-100 flex items-center justify-center"><span class="material-symbols-outlined text-violet-600 text-xl">storefront</span></span>
    <span class="min-w-0">
      <span class="block text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($supName !== '' ? $supName : 'Supplier') ?></span>
      <span class="block text-[11px] text-gray-400"><?= $isOwner ? 'Owner' : 'Team member' ?></span>
    </span>
  </a>

  <!-- Nav -->
  <nav class="flex-1 min-h-0 overflow-y-auto py-2">
    <?php foreach ($groups as $g): ?>
      <?php
        $items = array_values(array_filter($g['items'], fn($it) => !array_key_exists('show', $it) || $it['show']));
        if (empty($items)) { continue; }
      ?>
      <?php if (!empty($g['label'])): ?>
        <div class="px-4 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= htmlspecialchars($g['label']) ?></div>
      <?php endif; ?>
      <?php foreach ($items as $it): $on = $active($it['url']); ?>
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
