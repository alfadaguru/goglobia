<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $requested = (isset($requested) && is_array($requested)) ? $requested : [];
  $others    = (isset($others) && is_array($others)) ? $others : [];
  $statusChip = [
    'approved'  => 'bg-green-50 text-green-700',
    'rejected'  => 'bg-red-50 text-red-700',
    'suspended' => 'bg-red-50 text-red-700',
    'requested' => 'bg-amber-50 text-amber-700',
  ];
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">White-Label Site Requests</h1>
      <p class="text-sm text-gray-600">Approve hotels for their own branded booking site. Approving makes the branded site public.</p>
    </div>
    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold bg-amber-100 text-amber-700">
      <span class="material-symbols-outlined text-base">fact_check</span>
      <?= (int) count($requested) ?> awaiting review
    </span>
  </div>

  <!-- Awaiting review -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Awaiting review</h2>
    <?php if (empty($requested)): ?>
      <div class="card p-6 text-center text-gray-500">
        <span class="material-symbols-outlined text-4xl text-gray-300">inbox</span>
        <p class="mt-2 text-sm">No branded-site requests are awaiting review.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($requested as $r): $sid = (int) ($r['stay_id'] ?? 0); ?>
          <div class="card p-5" x-data="{ mode: '' }">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) ($r['stay_name'] ?? '')) ?: '(unnamed)' ?></div>
                <div class="text-sm text-gray-600 mt-1 flex items-center gap-1.5">
                  <span class="material-symbols-outlined text-base text-gray-400">location_on</span>
                  <?= htmlspecialchars((string) ($r['stay_location'] ?? '')) ?: 'No location' ?>
                </div>
                <?php $host = trim((string) ($r['custom_domain'] ?? '')) ?: trim((string) ($r['hostname'] ?? '')); ?>
                <?php if ($host !== ''): ?>
                  <div class="text-xs text-gray-500 mt-1 font-mono"><?= htmlspecialchars($host) ?></div>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button" class="btn emerald text-sm" @click="mode = (mode === 'approve' ? '' : 'approve')">Approve</button>
                <button type="button" class="btn secondary text-sm" @click="mode = (mode === 'reject' ? '' : 'reject')">Reject</button>
              </div>
            </div>

            <!-- decision forms (comment optional) -->
            <div x-show="mode !== ''" x-cloak class="mt-4 border-t border-gray-100 pt-4">
              <form action="<?= root . admin ?>/white-label-requests/review/<?= $sid ?>" method="POST" class="space-y-2">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="decision" :value="mode">
                <label class="block text-xs text-gray-600">Note to the hotel (optional)</label>
                <textarea name="comment" rows="2" maxlength="255" class="input text-sm" placeholder="Reason / guidance (shown to the supplier)"></textarea>
                <div class="flex items-center gap-2">
                  <button type="submit" class="btn emerald text-sm" x-show="mode === 'approve'">Confirm approve</button>
                  <button type="submit" class="btn secondary text-sm" x-show="mode === 'reject'">Confirm reject</button>
                  <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="mode = ''">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recently decided -->
  <?php if (!empty($others)): ?>
    <div>
      <h2 class="text-lg font-semibold text-gray-900 mb-3">Recently decided</h2>
      <div class="space-y-3">
        <?php foreach ($others as $r): $sid = (int) ($r['stay_id'] ?? 0); $st = (string) ($r['site_status'] ?? ''); ?>
          <div class="card p-4 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="font-medium text-gray-900 text-sm"><?= htmlspecialchars((string) ($r['stay_name'] ?? '')) ?></div>
              <?php $cmt = trim((string) ($r['site_review_comment'] ?? '')); if ($cmt !== ''): ?>
                <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($cmt) ?></div>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $statusChip[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
              <?php if ($st === 'approved'): ?>
                <form action="<?= root . admin ?>/white-label-requests/review/<?= $sid ?>" method="POST" onsubmit="return confirm('Suspend this branded site? It will stop serving publicly.');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="decision" value="suspend">
                  <button type="submit" class="text-xs text-red-600 hover:underline">Suspend</button>
                </form>
              <?php elseif (in_array($st, ['rejected', 'suspended'], true)): ?>
                <form action="<?= root . admin ?>/white-label-requests/review/<?= $sid ?>" method="POST">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="decision" value="approve">
                  <button type="submit" class="text-xs text-green-700 hover:underline">Approve</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>
<style>[x-cloak]{display:none!important}</style>
