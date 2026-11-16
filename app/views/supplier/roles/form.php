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

    <!-- Permission matrix — grouped by menu area (supplier_role_modules_groups()).
         Every grantable key in $mods is rendered exactly once; the checkbox name/checked
         logic is unchanged, so the save handler is untouched. One row renderer is reused for
         every group (no duplicated markup). -->
    <div>
      <h2 class="text-sm font-semibold text-gray-800 mb-1">Permissions</h2>
      <p class="text-xs text-gray-500 mb-3">Tick exactly the areas this role may use. Each area below is a real menu screen — grant only what the staffer needs.</p>
      <?php
        // Row renderer — identical markup for every area, called per group below.
        $__permRow = function (string $modKey, array $modMeta) use ($perms) {
            $modPerm = $perms[$modKey] ?? [];
            echo '<tr><td class="px-3 py-2 text-gray-800">' . htmlspecialchars($modMeta['label'] ?? $modKey) . '</td>';
            foreach (['view', 'add', 'edit', 'delete', 'approve'] as $act) {
                echo '<td class="px-3 py-2 text-center">';
                if (in_array($act, ($modMeta['actions'] ?? []), true)) {
                    echo '<input type="checkbox" name="permissions[' . htmlspecialchars($modKey) . '][' . $act . ']" class="checkbox-input"'
                        . (array_key_exists($act, $modPerm) ? ' checked' : '') . '>';
                } else {
                    echo '<span class="text-gray-300">—</span>';
                }
                echo '</td>';
            }
            echo '</tr>';
        };
        // The grouping (keys → sections). Fall back to a single "All permissions" group if the
        // grouping helper is unavailable, so nothing is ever hidden.
        $__groups = function_exists('supplier_role_modules_groups') ? supplier_role_modules_groups() : [];
        if (empty($__groups)) { $__groups = ['Permissions' => array_keys($mods)]; }
        // Any catalogue key not named in a group still gets rendered (defensive — never drop a key).
        $__shown = [];
      ?>
      <div class="space-y-5">
        <?php foreach ($__groups as $__sectionLabel => $__keys): ?>
          <?php
            // Only render keys that actually exist in $mods (the live catalogue).
            $__sectionKeys = array_values(array_filter($__keys, static fn($k) => isset($mods[$k])));
            if (empty($__sectionKeys)) { continue; }
          ?>
          <div>
            <h3 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1"><?= htmlspecialchars($__sectionLabel) ?></h3>
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-200">
                    <th class="px-3 py-2 font-medium">Area</th>
                    <th class="px-3 py-2 font-medium text-center">View</th>
                    <th class="px-3 py-2 font-medium text-center">Add</th>
                    <th class="px-3 py-2 font-medium text-center">Edit / create</th>
                    <th class="px-3 py-2 font-medium text-center">Delete</th>
                    <th class="px-3 py-2 font-medium text-center">Approve</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php foreach ($__sectionKeys as $__k): ?>
                    <?php $__shown[$__k] = true; $__permRow($__k, $mods[$__k]); ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>

        <?php // Safety net: render any catalogue key the grouping didn't include, so no grantable area is ever hidden. ?>
        <?php $__leftover = array_diff(array_keys($mods), array_keys($__shown)); ?>
        <?php if (!empty($__leftover)): ?>
          <div>
            <h3 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Other</h3>
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-200">
                    <th class="px-3 py-2 font-medium">Area</th>
                    <th class="px-3 py-2 font-medium text-center">View</th>
                    <th class="px-3 py-2 font-medium text-center">Add</th>
                    <th class="px-3 py-2 font-medium text-center">Edit / create</th>
                    <th class="px-3 py-2 font-medium text-center">Delete</th>
                    <th class="px-3 py-2 font-medium text-center">Approve</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php foreach ($__leftover as $__k): ?><?php $__permRow($__k, $mods[$__k]); ?><?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        <span class="font-medium">Edit / create</span> is what lets staff <span class="font-medium">create</span> records (walk-ins, guests, customers, entries) — not just edit existing ones. Grant both <span class="font-medium">View</span> and <span class="font-medium">Edit / create</span> for a working role.
      </p>
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

    <!-- Approval limits (inc S16) -->
    <?php
      $lk = (isset($limitKeys) && is_array($limitKeys)) ? $limitKeys : [];
      $lv = (isset($roleLimits) && is_array($roleLimits)) ? $roleLimits : [];
    ?>
    <?php
      // Split the catalogue: only keys with an actual auto-enforcement point are shown as
      // editable caps. The rest (escalation-only) are listed as a note so the owner isn't
      // misled into thinking they gate an action that doesn't exist (RBAC audit 2026-11-15).
      $enforcedKeys = array_filter($lk, static fn($m) => !empty($m['enforced']));
      $escalationOnly = array_filter($lk, static fn($m) => empty($m['enforced']));
    ?>
    <?php if (!empty($enforcedKeys)): ?>
    <div class="border-t border-gray-100 pt-4">
      <h2 class="text-sm font-semibold text-gray-800 mb-1">Approval limits <span class="text-gray-400 font-normal">(optional)</span></h2>
      <p class="text-xs text-gray-500 mb-3">Caps for staff in this role. Leave blank to require escalation to an owner; type <code>unlimited</code> for no cap. Owners are never capped.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($enforcedKeys as $key => $meta): ?>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">
              <?= htmlspecialchars($meta['label'] ?? $key) ?>
              <span class="text-gray-400">(<?= ($meta['unit'] ?? '') === 'percent' ? '%' : 'amount' ?>)</span>
            </label>
            <input type="text" name="limits[<?= htmlspecialchars($key) ?>]" class="input text-sm"
                   value="<?= htmlspecialchars((string) ($lv[$key] ?? '')) ?>"
                   placeholder="blank = escalate · unlimited = no cap">
            <?php if (!empty($meta['hint'])): ?><p class="mt-1 text-[11px] text-gray-400"><?= htmlspecialchars($meta['hint']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($escalationOnly)): ?>
        <p class="mt-3 text-[11px] text-gray-400">
          Other approval types (<?= htmlspecialchars(implode(', ', array_map(static fn($m) => $m['label'] ?? '', $escalationOnly))) ?>)
          are handled as manual requests in the <a href="<?= root ?>supplier/rbac?tab=approvals" class="underline">approvals queue</a> — there is no automatic action to cap yet.
        </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
      <a href="<?= root ?>supplier/roles" class="btn secondary">Cancel</a>
      <button type="submit" class="btn emerald">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">save</span> <?= $isEdit ? 'Save role' : 'Create role' ?></span>
      </button>
    </div>
  </form>
</div>
<style>[x-cloak]{display:none!important}</style>
