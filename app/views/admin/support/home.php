<?php
// app/views/admin/support/home.php — Customer Support landing page.
// Live operational dashboard (counters + target progress + leaderboard, all from
// $supportDash set by the route) over a card grid of the support sub-areas.
// See docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md §1 (sub-modules), §9 (dashboard).
@$SECURE or die('Access Denied!');

// Each tile: icon, title, blurb, url, area+action (the RBAC gate). A tile the
// current user lacks permission for is hidden. Support is OPERATIONAL only —
// staff management (Attendance / Shifts / Targets / Roles / Staff / Activity)
// moved to the dedicated Employees section (/admin/employees). These tiles are
// the day-to-day support desk surfaces.
$areas = [
    ['icon' => 'headset_mic',          'name' => 'Live Desk',   'blurb' => 'Multi-channel chat takeover inbox.',            'url' => root.admin.'/support/desk',           'area' => 'support_desk',       'action' => 'view'],
    ['icon' => 'confirmation_number',  'name' => 'Tickets',     'blurb' => 'Customer support tickets — reply & resolve.',   'url' => root.admin.'/support/tickets',        'area' => 'support_tickets',    'action' => 'view'],
    ['icon' => 'receipt_long',         'name' => 'Bookings',    'blurb' => 'Look up bookings, resend vouchers, notes.',     'url' => root.admin.'/bookings',               'area' => 'bookings',           'action' => 'view'],
    ['icon' => 'group',                'name' => 'Users',       'blurb' => 'Customers, agents & suppliers directory.',      'url' => root.admin.'/support/users',          'area' => 'users',              'action' => 'view'],
    ['icon' => 'campaign',             'name' => 'Campaigns',   'blurb' => 'Ad campaigns, attribution & conversion.',       'url' => root.admin.'/support/campaigns',      'area' => 'support_campaigns',  'action' => 'edit'],
];
?>

<div class="container my-4">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="mb-5">
    <h1 class="text-xl font-bold text-gray-900">Customer Support</h1>
    <p class="text-sm text-gray-500 mt-0.5">Live chat desk, tickets, bookings lookup and campaign conversion — your day-to-day support surfaces.</p>
  </div>

  <?php if (!empty($supportDash)): $d = $supportDash;
    $counter = function ($label, $val, $icon, $color) {
      $show = $val === null ? '—' : (is_float($val) ? number_format($val, 0) : $val);
      return '<div class="border border-gray-100 rounded-xl p-4 bg-white flex items-center gap-3">'
        . '<span class="material-symbols-outlined !text-[26px]" style="color:' . $color . '">' . $icon . '</span>'
        . '<div><div class="text-xl font-bold text-gray-900">' . htmlspecialchars((string) $show) . '</div>'
        . '<div class="text-xs text-gray-500">' . htmlspecialchars($label) . '</div></div></div>';
    };
  ?>
    <!-- Live counters -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <?= $counter('Agents online', $d['agents_online'], 'wifi_tethering', '#16a34a') ?>
      <?= $counter('Chats need a human', $d['needs_human'], 'support_agent', '#dc2626') ?>
      <?= $counter('Held today', $d['today_held'], 'shopping_cart', '#2563eb') ?>
      <?= $counter('Paid today', $d['today_paid'], 'paid', '#7c3aed') ?>
      <?= $counter('Revenue today', $d['today_revenue'], 'trending_up', '#d97706') ?>
    </div>

    <?php if (!empty($d['can_targets']) && !empty($d['targets'])): ?>
      <div class="mb-5">
        <div class="flex items-center justify-between mb-2">
          <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Target progress</h2>
          <a href="<?= root.admin ?>/employees/targets" class="text-xs text-blue-600 hover:underline">All targets →</a>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
          <?php foreach ($d['targets'] as $t): $pct = (float) ($t['pct'] ?? 0); $bar = max(0, min(100, $pct));
            $bc = $pct >= 100 ? '#16a34a' : ($pct >= 50 ? '#2563eb' : '#f59e0b'); ?>
            <div class="border border-gray-100 rounded-xl p-3 bg-white">
              <div class="flex items-center justify-between text-sm">
                <span class="font-semibold text-gray-800"><?= htmlspecialchars((string) ($t['who'] ?? '')) ?></span>
                <span class="text-gray-500"><?= htmlspecialchars((string) ($t['metric'] === 'revenue' ? number_format((float) $t['actual'], 0) : (int) $t['actual'])) ?>/<?= (int) $t['target_value'] ?></span>
              </div>
              <div style="height:6px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin-top:6px">
                <div style="height:100%;width:<?= $bar ?>%;background:<?= $bc ?>;border-radius:999px"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($d['can_board']) && !empty($d['leaderboard'])): ?>
      <div class="mb-5">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-2">Leaderboard · this month</h2>
        <div class="overflow-x-auto border border-gray-100 rounded-xl">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
              <th class="px-4 py-2 font-semibold">Agent</th>
              <th class="px-4 py-2 font-semibold text-right">Chats</th>
              <th class="px-4 py-2 font-semibold text-right">Held</th>
              <th class="px-4 py-2 font-semibold text-right">Paid</th>
              <th class="px-4 py-2 font-semibold text-right">Revenue</th>
              <th class="px-4 py-2 font-semibold text-right">Hours</th>
            </tr></thead>
            <tbody>
              <?php foreach ($d['leaderboard'] as $r): ?>
                <tr class="border-b border-gray-50 last:border-0">
                  <td class="px-4 py-2 font-medium text-gray-800"><?= htmlspecialchars((string) $r['name']) ?><?= !empty($r['is_gia']) ? ' <span style="background:#ede9fe;color:#6d28d9;font-size:9px;font-weight:700;padding:1px 6px;border-radius:999px">AI</span>' : '' ?></td>
                  <td class="px-4 py-2 text-right"><?= (int) $r['chats_handled'] ?></td>
                  <td class="px-4 py-2 text-right"><?= (int) $r['held'] ?></td>
                  <td class="px-4 py-2 text-right font-semibold"><?= (int) $r['paid'] ?></td>
                  <td class="px-4 py-2 text-right"><?= number_format((float) $r['revenue'], 0) ?></td>
                  <td class="px-4 py-2 text-right text-gray-500"><?= $r['attendance_hours'] === null ? '—' : htmlspecialchars((string) $r['attendance_hours']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($areas as $a): ?>
      <?php
        // Hide a tile the current user is not permitted to see at all. (admin_can is
        // loaded globally in config.php; guard defensively so a missing lib can
        // never fatal the whole page.)
        if (!empty($a['area']) && function_exists('admin_can') && !admin_can($db, $a['area'], $a['action'] ?? 'view')) { continue; }
      ?>
      <a href="<?= htmlspecialchars($a['url']) ?>"
         class="block border border-gray-100 rounded-xl p-5 bg-white hover:shadow-md hover:border-blue-200 transition">
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined !text-[28px] text-blue-600"><?= htmlspecialchars($a['icon']) ?></span>
          <div>
            <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($a['name']) ?></h3>
            <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars($a['blurb']) ?></p>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
