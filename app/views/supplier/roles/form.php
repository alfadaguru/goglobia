<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $isEdit    = !empty($isEdit);
  $r         = (isset($role) && is_array($role)) ? $role : [];
  $perms     = (isset($rolePerms) && is_array($rolePerms)) ? $rolePerms : [];
  $mods      = (isset($modules) && is_array($modules)) ? $modules : [];
  $props     = (isset($properties) && is_array($properties)) ? $properties : [];
  $scoped    = (isset($scopedIds) && is_array($scopedIds)) ? $scopedIds : [];
  $scopeType = $r['scope_type'] ?? 'all';
?>

<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Edit Role' : 'Create Role' ?></h1>
    <a href="<?= root ?>supplier/roles" class="text-sm text-blue-600 hover:underline">&larr; Back to roles</a>
  </div>

  <form action="<?= root ?>supplier/roles/save" method="POST" class="card p-6 space-y-6"
        x-data="{ scope: '<?= htmlspecialchars($scopeType) ?>' }">
    <?= CSRF::tokenField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>"><?php endif; ?>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Role name *</label>
      <input type="text" name="name" required class="input" placeholder="e.g. Front Desk"
             value="<?= htmlspecialchars($r['name'] ?? '') ?>">
    </div>

    <!-- Permission matrix -->
    <div>
      <h2 class="text-sm font-semibold text-gray-800 mb-2">Permissions</h2>
      <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-200">
              <th class="px-3 py-2 font-medium">Area</th>
              <th class="px-3 py-2 font-medium text-center">View</th>
              <th class="px-3 py-2 font-medium text-center">Add</th>
              <th class="px-3 py-2 font-medium text-center">Edit</th>
              <th class="px-3 py-2 font-medium text-center">Delete</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($mods as $modKey => $modMeta): ?>
              <?php $modPerm = $perms[$modKey] ?? []; ?>
              <tr>
                <td class="px-3 py-2 text-gray-800"><?= htmlspecialchars($modMeta['label']) ?></td>
                <?php foreach (['view', 'add', 'edit', 'delete'] as $act): ?>
                  <td class="px-3 py-2 text-center">
                    <?php if (in_array($act, $modMeta['actions'], true)): ?>
                      <input type="checkbox"
                             name="permissions[<?= htmlspecialchars($modKey) ?>][<?= $act ?>]"
                             class="checkbox-input"
                             <?= array_key_exists($act, $modPerm) ? 'checked' : '' ?>>
                    <?php else: ?>
                      <span class="text-gray-300">—</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Property scope -->
    <div>
      <h2 class="text-sm font-semibold text-gray-800 mb-2">Property scope</h2>
      <div class="space-y-2">
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="radio" name="scope_type" value="all" x-model="scope"> All my properties
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="radio" name="scope_type" value="selected" x-model="scope"> Selected properties only
        </label>
      </div>

      <div x-show="scope === 'selected'" x-cloak class="mt-3 border border-gray-200 rounded-lg p-3 space-y-1.5">
        <?php if (empty($props)): ?>
          <p class="text-xs text-gray-500">You have no properties yet. Create one first, then scope a role to it.</p>
        <?php else: ?>
          <?php foreach ($props as $p): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="properties[]" value="<?= (int) $p['id'] ?>" class="checkbox-input"
                     <?= in_array((int) $p['id'], $scoped, true) ? 'checked' : '' ?>>
              <?= htmlspecialchars($p['name'] ?? ('Property #' . (int) $p['id'])) ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
      <a href="<?= root ?>supplier/roles" class="btn secondary">Cancel</a>
      <button type="submit" class="btn emerald">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">save</span> <?= $isEdit ? 'Save role' : 'Create role' ?></span>
      </button>
    </div>
  </form>
</div>
<style>[x-cloak]{display:none!important}</style>
