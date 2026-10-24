<?php
// app/views/admin/support/roles.php — Support RBAC roles list.
// Shows every support_roles row: name, slug, a system/custom badge and the
// capability count. System roles (the 4 LOCKED defaults) are read-only for
// slug/is_system but their caps may still be edited; custom roles can be deleted
// (blocked server-side if any staff uses them). Gated by the route via
// support_can('roles.manage').
@$SECURE or die('Access Denied!');

$roles = $db->select('support_roles', '*', ['ORDER' => ['is_system' => 'DESC', 'name' => 'ASC']]) ?: [];

// Per-role staff count (so the list can hint which custom roles are in use and
// disable their Delete). A plain count() per role keeps to the codebase's Medoo
// usage — the support_roles table is tiny (a handful of rows).
$staffCount = function ($roleId) use ($db): int {
    try { return (int) $db->count('support_staff', ['role_id' => (int) $roleId]); }
    catch (\Throwable $e) { return 0; }
};

// Count caps in a role's JSON permissions. '*' (admin) is "all caps".
$catalogue = function_exists('support_capability_catalogue') ? support_capability_catalogue() : [];
$capCount = function ($permsJson) use ($catalogue): array {
    $decoded = json_decode((string) $permsJson, true);
    if (!is_array($decoded)) { return [0, false]; }
    if (in_array('*', $decoded, true)) { return [count($catalogue), true]; }
    return [count(array_intersect($catalogue, $decoded)), false];
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
    <a href="<?= root.admin ?>/support/roles/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> New role
    </a>
  </div>

  <?php if (empty($roles)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">admin_panel_settings</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No roles yet</h3>
      <p class="text-sm text-gray-500 mt-1">The default roles will seed automatically — reload the page, or create a custom role.</p>
      <a href="<?= root.admin ?>/support/roles/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">New role</a>
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
              $isSystem = (int) $r['is_system'] === 1;
              [$caps, $isAll] = $capCount($r['permissions'] ?? '');
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
                <a href="<?= root.admin ?>/support/roles/edit/<?= (int) $r['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= $isSystem ? 'View / edit caps' : 'Edit' ?></a>
                <?php if (!$isSystem): ?>
                  <form method="POST" action="<?= root.admin ?>/support/roles/delete" class="inline ml-3"
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
