<?php
// ============================================================================
// FILE: app/views/agent/sidebar.php — Travel-Agent ERP portal left nav.
// Mirrors app/views/supplier/sidebar.php: a persistent 240px rail (off-canvas on
// mobile via the #agent-nav checkbox), shown to an agency owner or active staff.
// Self-guards on agent_acting_context(); every item gated by agent_can().
// ============================================================================
@$SECURE or die('Access Denied!');

$__actx = function_exists('agent_acting_context') ? agent_acting_context($db) : null;
if ($__actx === null || !empty($__actx['is_admin'])) { return; } // not an agent/staff → render nothing

$owner    = (string) $__actx['owner'];
$isOwner  = !empty($__actx['is_owner']);
$can      = function (string $m, string $a) use ($db) { return agent_can($db, $m, $a); };
$curPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active   = function (string $p) use ($curPath) {
    return ($curPath === $p || strpos($curPath, $p) === 0)
        ? 'bg-violet-50 text-violet-700 font-semibold' : 'text-slate-600 hover:bg-slate-50';
};

// Agency display name (for the rail header).
$agencyName = 'My Agency';
try {
    $ag = $db->get('agencies', ['agency_name','logo'], ['user_id' => $owner]);
    if ($ag && !empty($ag['agency_name'])) { $agencyName = (string) $ag['agency_name']; }
} catch (\Throwable $e) {}

// Nav model — [label, icon, url, show?]. Icons = Material Symbols names.
$nav = [
    ['Dashboard',      'dashboard',             'agent/dashboard',      true],
    ['Booking Centre', 'add_shopping_cart',     'agent/booking-centre', $can('bookings','add')],
    ['Bookings',       'confirmation_number',   'agent/bookings',       $can('bookings','view')],
    ['Trip Plans',     'map',                   'agent/trips',          $can('trips','view')],
    ['Clients',        'contacts',              'agent/clients',        $can('clients','view')],
    ['Wallet',         'account_balance_wallet','agent/wallet',         $can('wallet','view')],
    ['Reports',        'bar_chart',             'agent/reports',        $can('reports','view')],
    ['Branding',       'palette',               'agent/branding',       $can('branding','edit')],
    ['Staff & Roles',  'group',                 'agent/staff',          ($isOwner || $can('staff','view'))],
    ['API Access',     'key',                   'api-access',           $can('api','view')],
    ['Activity log',   'history',               'agent/activity',       $isOwner],
];
?>
<input type="checkbox" id="agent-nav" class="peer hidden">
<label for="agent-nav" class="lg:hidden fixed top-3 left-3 z-[60] inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white shadow border border-slate-200 cursor-pointer">
    <span class="material-symbols-outlined text-slate-700">menu</span>
</label>
<aside class="fixed top-0 left-0 z-50 h-screen w-[240px] bg-white border-r border-slate-200 flex flex-col
              -translate-x-full peer-checked:translate-x-0 lg:translate-x-0 transition-transform">
    <div class="px-4 py-4 border-b border-slate-100">
        <a href="<?= root ?>agent/dashboard" class="flex items-center gap-2">
            <span class="material-symbols-outlined text-violet-600">travel_explore</span>
            <span class="font-bold text-slate-800 truncate"><?= htmlspecialchars($agencyName) ?></span>
        </a>
        <p class="text-[11px] text-slate-400 mt-0.5">Agent workspace</p>
    </div>
    <nav class="flex-1 overflow-y-auto py-2">
        <?php foreach ($nav as [$label, $icon, $url, $show]): ?>
            <?php if (!$show) { continue; } ?>
            <a href="<?= root . $url ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm rounded-lg mx-2 <?= $active('/' . $url) ?>">
                <span class="material-symbols-outlined text-[20px]"><?= htmlspecialchars($icon) ?></span>
                <span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php if (empty($isOwner)): ?>
    <div class="px-4 py-2 text-[11px] text-slate-500 border-t border-slate-100">
        Signed in as a team member — what you can do is set by your role.
    </div>
    <?php endif; ?>
    <div class="px-2 py-3 border-t border-slate-100">
        <a href="<?= root ?>profile" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 rounded-lg">
            <span class="material-symbols-outlined text-[20px]">person</span><span>Profile</span>
        </a>
        <a href="<?= root ?>logout" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 rounded-lg">
            <span class="material-symbols-outlined text-[20px]">logout</span><span>Sign out</span>
        </a>
    </div>
</aside>
<label for="agent-nav" class="lg:hidden fixed inset-0 z-40 bg-black/30 hidden peer-checked:block cursor-pointer"></label>
