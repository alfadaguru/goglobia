<?php
// app/views/admin/employees/roles.php — company RBAC roles list (users_roles).
// Shows every users_roles row: name, slug, a system/custom badge and the
// granted-permission count. System roles (kind='system' + the legacy seed rows
// 1-5) and the all-access Admin role are protected from delete; the Admin role
// is also not editable (it is force-repaired to full access every boot). Gated
// by the route via admin_can('users_roles','edit').
@$SECURE or die('Access Denie'.'d!');

$roles = $db->select('users_roles', '*', ['ORDER' => ['id' => 'ASC']]) ?: [];

// Per-role employee count (so the list can disable Delete for in-use roles).
$staffCount = function ($roleId) use ($db): int {
    try { return (int) $db->count('users', ['role_id' => (int) $roleId]); }
    catch (\Throwable $e) { return 0; }
};

// Total number of grantable (area, action) pairs in the catalogue.
$catalogue = function_exists('admin_area_catalogue') ? admin_area_catalogue() : [];
$catalogueTotal = 0;
foreach ($catalogue as $acts) { $catalogueTotal += count($acts); }

// Count granted permissions in a role's JSON grid. A grant is the PRESENCE of an
// action key (value ''). The Admin role is "All".
$capCount = function ($role) use ($catalogueTotal): array {
    $isAdminRole = (strtolower((string) ($role['base_type'] ?? '')) === 'admin')
        || (strtolower((string) ($role['type_name'] ?? '')) === 'admin');
    if ($isAdminRole) { return [$catalogueTotal, true]; }
    $decoded = json_decode((string) ($role['permissions'] ?? ''), true);
    if (!is_array($decoded)) { return [0, false]; }
    $n = 0;
    foreach ($decoded as $actions) {
        if (is_array($actions)) { $n += count($actions); }
    }
    return [$n, false];
};
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

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Support Roles</h1>
      <p class="text-sm text-gray-500 mt-0.5">Capability bundles for support staff &middot; <?= count($roles) ?> role<?= count($roles) === 1 ? '' : 's' ?></p>
    </div>
    <a href="<?= root.admin ?>/employees/roles/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> New role
    </a>
  </div>

  <?php if (empty($roles)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">admin_panel_settings</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No roles yet</h3>
      <p class="text-sm text-gray-500 mt-1">The default roles will seed automatically — reload the page, or create a custom role.</p>
      <a href="<?= root.admin ?>/employees/roles/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">New role</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Role</th>
            <th class="px-4 py-3 font-semibold">Slug</th>
            <th class="px-4 py-3 font-semibold">Type</th>
            <th class="px-4 py-3 font-semibold text-right">Capabilities</th>
            <th class="px-4 py-3 font-semibold text-right">In use</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roles as $r): ?>
            <?php
              $isAdminRole = (strtolower((string) ($r['base_type'] ?? '')) === 'admin')
                  || (strtolower((string) ($r['type_name'] ?? '')) === 'admin');
              $isSystem = (strtolower((string) ($r['kind'] ?? '')) === 'system') || ((int) $r['id'] <= 5);
              [$caps, $isAll] = $capCount($r);
              $used = $staffCount((int) $r['id']);
            ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) $r['name']) ?></div>
              </td>
              <td class="px-4 py-3">
                <code class="text-xs text-gray-500"><?= htmlspecialchars((string) $r['slug']) ?></code>
              </td>
              <td class="px-4 py-3">
                <?php if ($isSystem): ?>
                  <span style="background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">System</span>
                <?php else: ?>
                  <span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">Custom</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                <?php if ($isAll): ?>
                  <span class="font-semibold text-gray-900">All</span>
                <?php else: ?>
                  <?= number_format($caps) ?>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-gray-500"><?= number_format($used) ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($isAdminRole): ?>
                  <span class="text-gray-400 font-medium" title="The Admin role is all-access and cannot be edited">All access</span>
                <?php else: ?>
                  <a href="<?= root.admin ?>/employees/roles/edit/<?= (int) $r['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= $isSystem ? 'View / edit' : 'Edit' ?></a>
                <?php endif; ?>
                <?php if (!$isSystem && !$isAdminRole): ?>
                  <form method="POST" action="<?= root.admin ?>/employees/roles/delete" class="inline ml-3"
                        onsubmit="return confirm('Delete this role? This cannot be undone.');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="text-red-600 hover:underline font-medium"<?= $used > 0 ? ' disabled title="Role is assigned to staff" style="opacity:.4;cursor:not-allowed"' : '' ?>>Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400 mt-3">System roles are the locked defaults (Admin, Support Lead, Support Agent, Marketing). You can edit their capabilities but not their slug or delete them.</p>
  <?php endif; ?>
</div>
