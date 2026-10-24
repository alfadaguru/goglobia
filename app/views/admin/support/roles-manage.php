<?php
// app/views/admin/support/roles-manage.php — create/edit a support role.
// A checkbox grid of the full capability catalogue (the single source of truth is
// support_capability_catalogue() in app/lib/support/rbac.php). Editing a SYSTEM
// role is allowed for its capabilities, but the name is editable while the slug +
// is_system are LOCKED (§7); the Admin role is always-all and its grid is locked.
// Gated by the route via support_can('roles.manage'); POST -> /support/roles/save.
@$SECURE or die('Access Denied!');

$rid = (int) ($_GET['id'] ?? 0);
$r = $rid > 0 ? $db->get('support_roles', '*', ['id' => $rid]) : null;
if ($rid > 0 && !$r) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Role not found.'];
    redirect(root . admin . '/support/roles');
    return;
}

$isSystem = $r && (int) $r['is_system'] === 1;
$isAdmin  = $isSystem && ($r['slug'] ?? '') === 'admin'; // always-all, locked grid

// Current caps for the checkbox state. '*' (admin) implies every cap ticked.
$current = [];
if ($r && !empty($r['permissions'])) {
    $decoded = json_decode((string) $r['permissions'], true);
    if (is_array($decoded)) { $current = $decoded; }
}
$hasAll = in_array('*', $current, true);

$catalogue = function_exists('support_capability_catalogue') ? support_capability_catalogue() : [];

// Human labels + grouping for the grid (keys are the exact cap slugs from the
// catalogue; any slug not listed falls under "Other").
$capLabels = [
    'chat.view'            => 'View live chats',
    'chat.takeover'        => 'Take over / release a chat',
    'chat.assign'          => 'Assign / reassign chats',
    'tickets.view'         => 'View tickets',
    'tickets.manage'       => 'Reply / manage tickets',
    'bookings.view'        => 'View bookings',
    'bookings.notes'       => 'Add booking internal notes',
    'bookings.resend'      => 'Resend voucher / invoice',
    'bookings.money'       => 'Money actions (refund / cancel / void)',
    'users.view'           => 'View users directory',
    'attendance.view_self' => 'View own attendance',
    'attendance.view_all'  => 'View team attendance',
    'shifts.manage'        => 'Manage shifts / roster',
    'campaigns.manage'     => 'Manage ad campaigns',
    'campaigns.analytics'  => 'View campaign analytics',
    'targets.view'         => 'View targets',
    'targets.set'          => 'Set targets',
    'roles.manage'         => 'Manage support roles',
    'staff.manage'         => 'Manage support staff',
    'dashboard.view'       => 'View support dashboard',
];
$capGroups = [
    'Live chat'     => ['chat.view', 'chat.takeover', 'chat.assign'],
    'Tickets'       => ['tickets.view', 'tickets.manage'],
    'Bookings'      => ['bookings.view', 'bookings.notes', 'bookings.resend', 'bookings.money'],
    'Users'         => ['users.view'],
    'Attendance'    => ['attendance.view_self', 'attendance.view_all', 'shifts.manage'],
    'Campaigns'     => ['campaigns.manage', 'campaigns.analytics'],
    'Targets'       => ['targets.view', 'targets.set'],
    'Administration'=> ['roles.manage', 'staff.manage', 'dashboard.view'],
];
// Fold any catalogue cap not explicitly grouped into an "Other" bucket so the
// grid always covers the complete catalogue even if rbac.php adds new caps.
$grouped = [];
foreach ($capGroups as $g) { foreach ($g as $c) { $grouped[$c] = true; } }
$other = array_values(array_diff($catalogue, array_keys($grouped)));
if (!empty($other)) { $capGroups['Other'] = $other; }

$v = function ($k, $d = '') use ($r) { return htmlspecialchars((string) ($r[$k] ?? $d), ENT_QUOTES); };
?>

<div class="container my-4" style="max-width:900px">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/support/roles" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $rid > 0 ? 'Edit role' : 'New role' ?></h1>
    <?php if ($isSystem): ?>
      <span class="ml-2 text-xs font-bold uppercase tracking-wide px-2 py-0.5 rounded-full" style="background:#ede9fe;color:#5b21b6">System</span>
    <?php endif; ?>
  </div>

  <form method="POST" action="<?= root.admin ?>/support/roles/save">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="id" value="<?= $rid ?>">

    <div class="rounded-xl border border-gray-100 p-4 bg-gray-50 mb-5">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Role name</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Slug <span class="text-gray-400 font-normal">(auto)</span></label>
          <input type="text" value="<?= $isSystem ? $v('slug') : '' ?>" readonly disabled
                 placeholder="<?= $rid > 0 ? '' : 'derived from the name on save' ?>"
                 class="w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2.5 text-sm text-gray-500">
          <?php if ($isSystem): ?>
            <p class="text-xs text-gray-500 mt-1">System role — slug is locked.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="rounded-xl border border-gray-100 p-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-gray-900">Capabilities</h3>
        <?php if (!$isAdmin): ?>
          <div class="flex items-center gap-3 text-xs">
            <button type="button" class="text-blue-600 hover:underline" onclick="document.querySelectorAll('.cap-cb').forEach(c=>c.checked=true)">Select all</button>
            <button type="button" class="text-gray-500 hover:underline" onclick="document.querySelectorAll('.cap-cb').forEach(c=>c.checked=false)">Clear all</button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
        <div class="rounded-lg p-3 text-sm" style="background:#ecfdf5;color:#065f46">
          The <strong>Admin</strong> role always has <strong>all capabilities</strong> and cannot be narrowed.
        </div>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
          <?php foreach ($capGroups as $groupName => $caps): ?>
            <?php if (empty($caps)) continue; ?>
            <div>
              <div class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-2"><?= htmlspecialchars($groupName) ?></div>
              <?php foreach ($caps as $cap): ?>
                <?php $checked = $hasAll || in_array($cap, $current, true); ?>
                <label class="flex items-start gap-2 mb-2 cursor-pointer">
                  <input type="checkbox" class="cap-cb mt-0.5" name="permissions[]" value="<?= htmlspecialchars($cap, ENT_QUOTES) ?>" <?= $checked ? 'checked' : '' ?>>
                  <span class="text-sm text-gray-800"><?= htmlspecialchars($capLabels[$cap] ?? $cap) ?>
                    <span class="block text-[11px] text-gray-400"><code><?= htmlspecialchars($cap) ?></code></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-5 flex items-center gap-3">
      <button type="submit" class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-black transition">
        <?= $rid > 0 ? 'Save changes' : 'Create role' ?>
      </button>
      <a href="<?= root.admin ?>/support/roles" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
    </div>
  </form>
</div>
