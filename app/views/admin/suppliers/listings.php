<?php @$SECURE or die('Access Denied!'); ?>

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
      <h1 class="text-2xl font-bold text-gray-900">Supplier Listings</h1>
      <p class="text-sm text-gray-600">Review properties suppliers have submitted. Approving makes a listing live.</p>
    </div>
    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold bg-blue-100 text-blue-700">
      <span class="material-symbols-outlined text-base">fact_check</span>
      <?= (int) count($submitted) ?> awaiting review
    </span>
  </div>

  <!-- Submitted queue -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Awaiting review</h2>
    <?php if (empty($submitted)): ?>
      <div class="card p-6 text-center text-gray-500">
        <span class="material-symbols-outlined text-4xl text-gray-300">inbox</span>
        <p class="mt-2 text-sm">No listings are awaiting review.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($submitted as $l): ?>
          <div class="card p-5" x-data="{ mode: '' }">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($l['name'] ?? '') ?: '(unnamed)' ?></div>
                <div class="text-sm text-gray-600 mt-1 flex items-center gap-1.5">
                  <span class="material-symbols-outlined text-sm text-gray-400">location_on</span><?= htmlspecialchars($l['location'] ?? '') ?>
                </div>
                <div class="text-xs text-gray-400 mt-1">Submitted <?= htmlspecialchars(substr((string) ($l['created_at'] ?? ''), 0, 16)) ?></div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <!-- Approve -->
                <form action="<?= root . admin ?>/supplier-listings/review/<?= (int) $l['id'] ?>" method="POST">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="decision" value="approve">
                  <button type="submit" class="btn emerald">
                    <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">check_circle</span> Approve</span>
                  </button>
                </form>
                <button type="button" class="btn secondary" @click="mode = (mode === 'query' ? '' : 'query')">Query</button>
                <button type="button" class="btn secondary" @click="mode = (mode === 'reject' ? '' : 'reject')">Reject</button>
              </div>
            </div>
            <!-- Query / reject with comment -->
            <div x-show="mode !== ''" x-cloak class="mt-3 border-t border-gray-100 pt-3">
              <form action="<?= root . admin ?>/supplier-listings/review/<?= (int) $l['id'] ?>" method="POST" class="flex flex-col sm:flex-row gap-2 sm:items-end">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="decision" :value="mode">
                <div class="form-control flex-1">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Note to the supplier (optional)</label>
                  <input type="text" name="comment" maxlength="255" class="input" placeholder="e.g. add clearer photos / fix the cancellation policy">
                </div>
                <button type="submit" class="btn" :class="mode === 'reject' ? 'red' : ''"
                        x-text="mode === 'reject' ? 'Confirm rejection' : 'Send query'"></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recently decided -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Recently decided</h2>
    <?php if (empty($others)): ?>
      <div class="card p-6 text-center text-gray-500 text-sm">Nothing decided yet.</div>
    <?php else: ?>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="px-4 py-3 font-medium">Property</th>
              <th class="px-4 py-3 font-medium">Location</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium">Note</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($others as $l): ?>
              <?php
                $ls = $l['listing_status'] ?? '';
                $c = ['approved' => 'bg-green-50 text-green-700', 'queried' => 'bg-amber-50 text-amber-700', 'rejected' => 'bg-red-50 text-red-700'][$ls] ?? 'bg-gray-100 text-gray-600';
              ?>
              <tr>
                <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($l['name'] ?? '') ?></td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($l['location'] ?? '') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $c ?>"><?= htmlspecialchars(ucfirst($ls)) ?></span></td>
                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($l['review_comment'] ?? '') ?: '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>[x-cloak]{display:none!important}</style>
