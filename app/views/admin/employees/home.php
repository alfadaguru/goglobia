<?php
// app/views/admin/employees/home.php — Employees section landing page.
// The ONE place to manage GoGlobia's own internal staff: employees, roles &
// permissions, attendance, shifts, targets and the activity log. Each tile is
// gated by admin_can($area,$action); a tile the current role lacks is hidden.
// (This is company staff only — NOT travel-agency or supplier staff.)
@$SECURE or die('Access Denied!');

$areas = [
    ['icon' => 'badge',                'name' => 'Staff',        'blurb' => 'Your employees — add people, assign roles, set shift group & nudge phone.', 'url' => root.admin.'/employees/staff',      'area' => 'employees',          'action' => 'edit'],
    ['icon' => 'admin_panel_settings', 'name' => 'Roles',        'blurb' => 'Granular permission bundles — control exactly what each role can access.',   'url' => root.admin.'/employees/roles',      'area' => 'users_roles',        'action' => 'edit'],
    ['icon' => 'schedule',             'name' => 'Attendance',   'blurb' => 'Clock-in/out, worked hours & who is online now.',                           'url' => root.admin.'/employees/attendance', 'area' => 'support_attendance', 'action' => 'view'],
    ['icon' => 'calendar_month',       'name' => 'Shifts',       'blurb' => 'Shift roster — assign who works when.',                                      'url' => root.admin.'/employees/shifts',     'area' => 'support_shifts',     'action' => 'view'],
    ['icon' => 'target',               'name' => 'Targets',      'blurb' => 'Performance goals per staffer, GIA & team (held / paid / revenue).',         'url' => root.admin.'/employees/targets',    'area' => 'support_targets',    'action' => 'view'],
    ['icon' => 'history',              'name' => 'Activity log', 'blurb' => 'Every gated action & page view — who did what, when, allowed or denied.',    'url' => root.admin.'/employees/activity',   'area' => 'employees',          'action' => 'view'],
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
    <h1 class="text-xl font-bold text-gray-900">Employees</h1>
    <p class="text-sm text-gray-500 mt-0.5">Manage your team — people, roles & permissions, attendance, shifts, targets and activity, all in one place.</p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($areas as $a):
      // Hide a tile the current user has no permission for (full admin sees all).
      if (!empty($a['area']) && function_exists('admin_can') && !admin_can($db, $a['area'], $a['action'] ?? 'view')) {
        continue;
      }
    ?>
      <a href="<?= htmlspecialchars($a['url']) ?>"
         class="block border border-gray-100 rounded-xl p-5 bg-white hover:shadow-md hover:border-blue-200 transition">
        <div class="flex items-center gap-3 mb-2">
          <span class="material-symbols-outlined !text-[26px] text-blue-600"><?= htmlspecialchars($a['icon']) ?></span>
          <div class="text-base font-semibold text-gray-900"><?= htmlspecialchars($a['name']) ?></div>
        </div>
        <p class="text-sm text-gray-500 leading-snug"><?= htmlspecialchars($a['blurb']) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</div>
