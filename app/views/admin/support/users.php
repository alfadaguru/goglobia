<?php
// app/views/admin/support/users.php — Users Directory (sub-module 5).
// Vars from the route: $tabs, $tab, $counts, $q, $users.
@$SECURE or die('Access Denie'.'d!');

$roleBadge = function (string $role): string {
    $map = [
        'customer' => 'background:#eff6ff;color:#1e40af',
        'user'     => 'background:#eff6ff;color:#1e40af',
        'agent'    => 'background:#f0fdf4;color:#166534',
        'supplier' => 'background:#fef3c7;color:#92400e',
    ];
    $style = $map[$role] ?? 'background:#f1f5f9;color:#475569';
    return '<span style="' . $style . ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:capitalize">' . htmlspecialchars($role) . '</span>';
};
$statusBadge = function (string $s): string {
    $map = ['active' => '#16a34a', 'inactive' => '#dc2626', 'pending' => '#ca8a04', 'rejected' => '#dc2626'];
    $c = $map[$s] ?? '#64748b';
    return '<span style="color:' . $c . ';font-size:12px;font-weight:600;text-transform:capitalize">' . htmlspecialchars($s) . '</span>';
};
?>

<div class="container my-4">
  <div class="mb-5">
    <h1 class="text-xl font-bold text-gray-900">Users Directory</h1>
    <p class="text-sm text-gray-500 mt-0.5">Customers, agents &amp; suppliers — look up anyone to see their bookings &amp; tickets.</p>
  </div>

  <!-- Tabs -->
  <div class="flex items-center gap-2 mb-4 border-b border-gray-100">
    <?php foreach ($tabs as $k => $def): ?>
      <a href="<?= root.admin ?>/support/users?tab=<?= urlencode($k) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
         class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition <?= $tab === $k ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
        <?= htmlspecialchars($def['label']) ?>
        <span class="ml-1 text-xs text-gray-400">(<?= number_format((int) ($counts[$k] ?? 0)) ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="GET" action="<?= root.admin ?>/support/users" class="mb-4 flex items-center gap-2">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES) ?>">
    <div class="relative flex-1 max-w-md">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" style="font-size:18px">search</span>
      <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search name, email, phone or ID…"
             class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Search</button>
    <?php if ($q !== ''): ?>
      <a href="<?= root.admin ?>/support/users?tab=<?= urlencode($tab) ?>" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($users)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">group</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No <?= htmlspecialchars($tabs[$tab]['label']) ?> found</h3>
      <p class="text-sm text-gray-500 mt-1"><?= $q !== '' ? 'Try a different search.' : 'There are no users in this group yet.' ?></p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Name</th>
            <th class="px-4 py-3 font-semibold">Contact</th>
            <th class="px-4 py-3 font-semibold">Role</th>
            <th class="px-4 py-3 font-semibold">Status</th>
            <th class="px-4 py-3 font-semibold">Joined</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $name = trim(((string) ($u['first_name'] ?? '')) . ' ' . ((string) ($u['last_name'] ?? '')));
              if ($name === '') { $name = (string) $u['user_id']; }
            ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3">
                <a href="<?= root.admin ?>/support/users/<?= urlencode((string) $u['user_id']) ?>" class="font-semibold text-gray-900 hover:text-blue-600"><?= htmlspecialchars($name) ?></a>
                <?php if (!empty($u['country'])): ?><div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars((string) $u['country']) ?></div><?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-600">
                <div class="truncate" style="max-width:240px"><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></div>
                <?php if (!empty($u['phone'])): ?><div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars((string) $u['phone']) ?></div><?php endif; ?>
              </td>
              <td class="px-4 py-3"><?= $roleBadge(strtolower((string) ($u['role'] ?? ''))) ?></td>
              <td class="px-4 py-3"><?= $statusBadge((string) ($u['status'] ?? 'active')) ?></td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= !empty($u['created_at']) ? htmlspecialchars(date('M d, Y', strtotime((string) $u['created_at']))) : '—' ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a href="<?= root.admin ?>/support/users/<?= urlencode((string) $u['user_id']) ?>" class="text-blue-600 hover:underline font-medium">View profile</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400 mt-3">Showing up to 100 results. Use search to narrow down.</p>
  <?php endif; ?>
</div>
