<?php
// app/views/admin/support/staff.php — Employees list (users_roles consolidation).
// Employees ARE users with role in (admin, employee), joined to users_roles for
// the role name. Style mirrors app/views/admin/campaigns/campaigns.php.
@$SECURE or die('Access Denie'.'d!');

// users (role admin/employee) -> users_roles (role name). role_id -> users_roles.id;
// admins with no role_id still list (their role name falls back to "Admin").
$staff = $db->select('users', [
    '[>]users_roles' => ['role_id' => 'id'],
], [
    'users.user_id',
    'users.first_name',
    'users.last_name',
    'users.email',
    'users.role',
    'users.shift_group',
    'users.nudge_phone',
    'users.phone',
    'users.status',
    'users_roles.type_name (role_name)',
], [
    'users.role' => ['admin', 'employee'],
    'ORDER' => ['users.role' => 'ASC', 'users.first_name' => 'ASC'],
]) ?: [];

$statusBadge = function (string $s): string {
    $map = [
        'active'   => 'background:#dcfce7;color:#166534',
        'inactive' => 'background:#fee2e2;color:#991b1b',
        'pending'  => 'background:#fef9c3;color:#854d0e',
        'rejected' => 'background:#fee2e2;color:#991b1b',
    ];
    $style = $map[$s] ?? $map['active'];
    return '<span style="' . $style . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">' . htmlspecialchars($s) . '</span>';
};
?>

<div class="container my-4">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Employees</h1>
      <p class="text-sm text-gray-500 mt-0.5"><?= number_format(count($staff)) ?> employee<?= count($staff) === 1 ? '' : 's' ?> &middot; a user granted a role with admin-panel access</p>
    </div>
    <a href="<?= root.admin ?>/support/staff/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> Add employee
    </a>
  </div>

  <?php if (empty($staff)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">support_agent</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No support staff yet</h3>
      <p class="text-sm text-gray-500 mt-1">Add your first support employee by assigning an existing user a support role.</p>
      <a href="<?= root.admin ?>/support/staff/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add staff</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Staff</th>
            <th class="px-4 py-3 font-semibold">Role</th>
            <th class="px-4 py-3 font-semibold">Shift group</th>
            <th class="px-4 py-3 font-semibold">Phone</th>
            <th class="px-4 py-3 font-semibold">Status</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staff as $s): ?>
            <?php
              $fullName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
              $label    = $fullName !== '' ? $fullName : $s['user_id'];
              $isAdmin  = (($s['role'] ?? '') === 'admin');
              $roleName = $s['role_name'] ?: ($isAdmin ? 'Admin' : '—');
              $phone    = $s['nudge_phone'] ?: ($s['phone'] ?: '');
              $uid      = (string) $s['user_id'];
            ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) $label) ?>
                  <?php if ($isAdmin): ?><span style="background:#ede9fe;color:#5b21b6;font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:4px">ADMIN</span><?php endif; ?>
                </div>
                <div class="text-xs text-gray-500 truncate" style="max-width:320px"><?= htmlspecialchars((string) ($s['email'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string) $roleName) ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string) ($s['shift_group'] ?: '—')) ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string) ($phone ?: '—')) ?></td>
              <td class="px-4 py-3"><?= $statusBadge((string) ($s['status'] ?? 'active')) ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($isAdmin): ?>
                  <span class="text-gray-400" title="Manage admins in Users">Admin</span>
                <?php else: ?>
                  <a href="<?= root.admin ?>/support/staff/edit/<?= urlencode($uid) ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                  <form method="POST" action="<?= root.admin ?>/support/staff/delete" class="inline ml-3" onsubmit="return confirm('Remove this employee? This demotes them to a customer — it does not delete their account.');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($uid, ENT_QUOTES) ?>">
                    <button type="submit" class="text-red-600 hover:underline font-medium">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
