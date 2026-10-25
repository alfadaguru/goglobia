<?php
// app/views/admin/employees/staff.php — Employees list (users_roles consolidation).
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
    <a href="<?= root.admin ?>/employees/staff/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> Add employee
    </a>
  </div>

  <?php if (empty($staff)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">support_agent</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No support staff yet</h3>
      <p class="text-sm text-gray-500 mt-1">Add your first support employee by assigning an existing user a support role.</p>
      <a href="<?= root.admin ?>/employees/staff/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add staff</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Staff</th>
            <th class="px-4 py-3 font-semibold">Role</th>
            <th class="px-4 py-3 font-semibold">Shift group</th>
            <th class="px-4 py-3 font-semibold">WhatsApp</th>
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
              <td class="px-4 py-3 text-gray-700">
                <?php if ($phone !== ''): ?>
                  <span class="inline-flex items-center gap-1.5">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="#25D366" aria-hidden="true" style="flex:none"><path d="M19.05 4.91A9.82 9.82 0 0 0 12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.02zM12.05 20.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.24-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.69 8.24-8.23 8.24zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28z"/></svg>
                    <?= htmlspecialchars((string) $phone) ?>
                  </span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="px-4 py-3"><?= $statusBadge((string) ($s['status'] ?? 'active')) ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($isAdmin): ?>
                  <span class="text-gray-400" title="Manage admins in Users">Admin</span>
                <?php else: ?>
                  <a href="<?= root.admin ?>/employees/staff/edit/<?= urlencode($uid) ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                  <form method="POST" action="<?= root.admin ?>/employees/staff/delete" class="inline ml-3" onsubmit="return confirm('Remove this employee? This demotes them to a customer — it does not delete their account.');">
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
