<?php
// app/views/admin/support/home.php — Customer Support landing page.
// Card grid linking to every sub-area; areas not yet built (later phases) are
// rendered as disabled "coming soon" tiles. See
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md §1 (sub-modules).
@$SECURE or die('Access Denied!');

// Each tile: icon, title, blurb, url (null = coming soon), cap (null = no gate).
// A tile with a cap the current staff lacks is hidden entirely.
$areas = [
    ['icon' => 'headset_mic',          'name' => 'Live Desk',   'blurb' => 'Multi-channel chat takeover inbox.',            'url' => null,                                 'cap' => 'chat.view'],
    ['icon' => 'confirmation_number',  'name' => 'Tickets',     'blurb' => 'Customer support tickets — reply & resolve.',   'url' => root.admin.'/support/tickets',        'cap' => 'tickets.view'],
    ['icon' => 'receipt_long',         'name' => 'Bookings',    'blurb' => 'Look up bookings, resend vouchers, notes.',     'url' => root.admin.'/bookings',               'cap' => 'bookings.view'],
    ['icon' => 'group',                'name' => 'Users',       'blurb' => 'Customers, agents & suppliers directory.',      'url' => null,                                 'cap' => 'users.view'],
    ['icon' => 'schedule',            'name' => 'Attendance',  'blurb' => 'Clock-in/out, shifts & presence.',             'url' => null,                                 'cap' => 'attendance.view_self'],
    ['icon' => 'campaign',             'name' => 'Campaigns',   'blurb' => 'Ad campaigns, attribution & conversion.',       'url' => null,                                 'cap' => 'campaigns.manage'],
    ['icon' => 'admin_panel_settings', 'name' => 'Roles',       'blurb' => 'Capability bundles for support staff.',         'url' => root.admin.'/support/roles',          'cap' => 'roles.manage'],
    ['icon' => 'badge',                'name' => 'Staff',       'blurb' => 'Provision employees & assign roles.',           'url' => root.admin.'/support/staff',          'cap' => 'staff.manage'],
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
    <p class="text-sm text-gray-500 mt-0.5">Live desk, tickets, bookings and your support team — all in one place.</p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($areas as $a): ?>
      <?php
        // Hide a tile the current staff is not permitted to see at all.
        if (!empty($a['cap']) && !support_can($db, $a['cap'])) { continue; }
        $live = !empty($a['url']);
      ?>
      <?php if ($live): ?>
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
      <?php else: ?>
        <div class="block border border-dashed border-gray-200 rounded-xl p-5 bg-gray-50 opacity-70 cursor-not-allowed">
          <div class="flex items-start gap-3">
            <span class="material-symbols-outlined !text-[28px] text-gray-400"><?= htmlspecialchars($a['icon']) ?></span>
            <div>
              <h3 class="text-base font-semibold text-gray-700 flex items-center gap-2">
                <?= htmlspecialchars($a['name']) ?>
                <span style="background:#f1f5f9;color:#475569;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">coming</span>
              </h3>
              <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars($a['blurb']) ?></p>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
