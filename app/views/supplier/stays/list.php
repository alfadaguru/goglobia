<?php @$SECURE or die('Access Denied!'); ?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= $__x /* may contain safe inline markup from server */ ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">My Hotels</h1>
      <p class="text-sm text-gray-600">
        <?php $max = $quota['max'] ?? null; $used = (int) ($quota['used'] ?? 0); ?>
        <?php if ($max !== null): ?>
          Using <span class="font-semibold tabular-nums"><?= $used ?></span> of
          <span class="font-semibold tabular-nums"><?= (int) $max ?></span> approved propert<?= $max === 1 ? 'y' : 'ies' ?>.
        <?php elseif (!empty($quota['approved'])): ?>
          <span class="font-semibold tabular-nums"><?= $used ?></span> propert<?= $used === 1 ? 'y' : 'ies' ?>.
        <?php else: ?>
          Your stays service is awaiting approval.
        <?php endif; ?>
      </p>
    </div>
    <?php
      $canAdd = !empty($quota['approved']) && ($quota['max'] === null || $used < (int) $quota['max']);
    ?>
    <?php if ($canAdd): ?>
      <a href="<?= root ?>supplier/stays/add" class="btn emerald">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add property</span>
      </a>
    <?php else: ?>
      <button type="button" class="btn secondary" disabled title="Limit reached or not approved">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add property</span>
      </button>
    <?php endif; ?>
  </div>

  <?php if (empty($properties)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">hotel</span>
      <p class="mt-2 text-sm">You haven't created any properties yet.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Property</th>
            <th class="px-4 py-3 font-medium">Location</th>
            <th class="px-4 py-3 font-medium">Approval</th>
            <th class="px-4 py-3 font-medium">Live</th>
            <th class="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($properties as $p): ?>
            <?php
              $ls = $p['listing_status'] ?? 'draft';
              $lsColor = [
                'approved'  => 'bg-green-50 text-green-700',
                'submitted' => 'bg-blue-50 text-blue-700',
                'queried'   => 'bg-amber-50 text-amber-700',
                'rejected'  => 'bg-red-50 text-red-700',
                'draft'     => 'bg-gray-100 text-gray-600',
              ][$ls] ?? 'bg-gray-100 text-gray-600';
            ?>
            <tr>
              <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars($p['name'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($p['location'] ?? '') ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $lsColor ?>"><?= htmlspecialchars(ucfirst($ls)) ?></span></td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= (int) ($p['status'] ?? 0) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                  <?= (int) ($p['status'] ?? 0) === 1 ? 'Live' : 'Offline' ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="<?= root ?>supplier/stays/edit/<?= (int) $p['id'] ?>" class="text-blue-600 hover:underline text-xs font-medium">Edit</a>
                  <?php if (in_array($ls, ['draft','queried','rejected'], true)): ?>
                    <form action="<?= root ?>supplier/stays/submit/<?= (int) $p['id'] ?>" method="POST" class="inline">
                      <?= CSRF::tokenField() ?>
                      <button type="submit" class="text-emerald-700 hover:underline text-xs font-medium">Submit for approval</button>
                    </form>
                  <?php endif; ?>
                  <form action="<?= root ?>supplier/stays/delete" method="POST" class="inline"
                        onsubmit="return confirm('Delete this property? This cannot be undone.');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
