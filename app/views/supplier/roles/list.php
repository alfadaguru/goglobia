<?php @$SECURE or die('Access Denied!'); ?>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Roles &amp; Permissions</h1>
      <p class="text-sm text-gray-600">Define what your team members can do, and on which properties.</p>
    </div>
    <a href="<?= root ?>supplier/roles/add" class="btn emerald">
      <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Create role</span>
    </a>
  </div>

  <?php if (empty($roles)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">badge</span>
      <p class="mt-2 text-sm">No roles yet. Create a role (e.g. "Front Desk", "Housekeeping") then invite staff to it.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Role</th>
            <th class="px-4 py-3 font-medium">Property scope</th>
            <th class="px-4 py-3 font-medium">Staff</th>
            <th class="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($roles as $r): ?>
            <tr>
              <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars($r['name'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-600">
                <?= ($r['scope_type'] ?? 'all') === 'selected' ? 'Selected properties' : 'All properties' ?>
              </td>
              <td class="px-4 py-3 text-gray-600 tabular-nums"><?= (int) ($counts[(int) $r['id']] ?? 0) ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="<?= root ?>supplier/roles/edit/<?= (int) $r['id'] ?>" class="text-blue-600 hover:underline text-xs font-medium">Edit</a>
                  <form action="<?= root ?>supplier/roles/delete" method="POST" class="inline"
                        onsubmit="return confirm('Delete this role?');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="text-red-600 hover:underline text-xs font-medium">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
