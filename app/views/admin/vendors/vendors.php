<?php @$SECURE or die('Access Denied!'); ?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <!-- Flash message -->
  <?php if (!empty($_SESSION['message'])): ?>
    <?php
      $__m = $_SESSION['message'];
      $__type = is_array($__m) ? ($__m['type'] ?? 'info') : 'info';
      $__text = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m;
    ?>
    <div class="<?= $__type === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__type === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__text) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Vendors</h1>
      <p class="text-sm text-gray-600">Review vendor applications and manage approved suppliers.</p>
    </div>
    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold bg-amber-100 text-amber-700">
      <span class="material-symbols-outlined text-base">hourglass_top</span>
      <?= (int) count($pending) ?> pending
    </span>
  </div>

  <!-- Pending queue -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Pending applications</h2>
    <?php if (empty($pending)): ?>
      <div class="card p-6 text-center text-gray-500">
        <span class="material-symbols-outlined text-4xl text-gray-300">inbox</span>
        <p class="mt-2 text-sm">No vendor applications are waiting for review.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($pending as $v): ?>
          <?php
            $vName = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
            $vid   = htmlspecialchars((string) ($v['user_id'] ?? ''));
          ?>
          <div class="card p-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($vName) ?: '(no name)' ?></div>
                <div class="text-sm text-gray-600 space-y-0.5 mt-1">
                  <?php if (!empty($v['title'])): ?>
                    <div class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-gray-400">business</span><?= htmlspecialchars($v['title']) ?></div>
                  <?php endif; ?>
                  <div class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-gray-400">email</span><?= htmlspecialchars($v['email'] ?? '') ?></div>
                  <?php if (!empty($v['phone'])): ?>
                    <div class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm text-gray-400">call</span><?= htmlspecialchars($v['phone'] ?? '') ?></div>
                  <?php endif; ?>
                  <div class="flex items-center gap-1.5 text-gray-400"><span class="material-symbols-outlined text-sm">schedule</span>Applied <?= htmlspecialchars(substr((string) ($v['created_at'] ?? ''), 0, 16)) ?></div>
                </div>
              </div>

              <div class="flex items-center gap-2 shrink-0"
                   x-data="{ rejecting: false }">
                <!-- Approve -->
                <form action="<?= root . admin ?>/vendors/approve/<?= $vid ?>" method="POST">
                  <?= CSRF::tokenField() ?>
                  <button type="submit" class="btn emerald">
                    <span class="flex items-center gap-1.5">
                      <span class="material-symbols-outlined text-sm">check_circle</span> Approve
                    </span>
                  </button>
                </form>

                <!-- Reject (reveals a reason field) -->
                <button type="button" class="btn secondary" @click="rejecting = !rejecting">
                  <span class="flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">cancel</span> Reject
                  </span>
                </button>

                <div x-show="rejecting" x-cloak
                     class="absolute mt-2 right-0 z-10 card p-4 w-72 shadow-lg"
                     style="position:absolute">
                  <form action="<?= root . admin ?>/vendors/reject/<?= $vid ?>" method="POST" class="space-y-2">
                    <?= CSRF::tokenField() ?>
                    <label class="block text-xs font-medium text-gray-600">Reason (shown to the vendor)</label>
                    <textarea name="reason" rows="3" maxlength="255" class="input w-full text-sm"
                              placeholder="Optional — e.g. incomplete business details"></textarea>
                    <button type="submit" class="btn red w-full">
                      <span class="flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">cancel</span> Confirm rejection
                      </span>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Processed vendors -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">All vendors</h2>
    <?php if (empty($others)): ?>
      <div class="card p-6 text-center text-gray-500 text-sm">No approved or rejected vendors yet.</div>
    <?php else: ?>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="px-4 py-3 font-medium">Name</th>
              <th class="px-4 py-3 font-medium">Company</th>
              <th class="px-4 py-3 font-medium">Email</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($others as $v): ?>
              <?php $vid = htmlspecialchars((string) ($v['user_id'] ?? '')); ?>
              <tr>
                <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars(trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''))) ?></td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($v['title'] ?? '') ?: '—' ?></td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($v['email'] ?? '') ?></td>
                <td class="px-4 py-3">
                  <?php $st = $v['status'] ?? ''; ?>
                  <span class="px-2 py-0.5 rounded text-xs font-medium
                    <?= $st === 'active' ? 'bg-green-50 text-green-700' : ($st === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600') ?>">
                    <?= $st === 'active' ? 'Approved' : ucfirst((string) $st) ?>
                  </span>
                </td>
                <td class="px-4 py-3">
                  <?php if ($st !== 'active'): ?>
                    <form action="<?= root . admin ?>/vendors/approve/<?= $vid ?>" method="POST" class="inline">
                      <?= CSRF::tokenField() ?>
                      <button type="submit" class="text-green-700 hover:underline text-xs font-medium">Approve</button>
                    </form>
                  <?php else: ?>
                    <span class="text-gray-400 text-xs">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>[x-cloak]{display:none!important}</style>
