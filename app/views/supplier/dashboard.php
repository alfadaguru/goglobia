<?php @$SECURE or die('Access Denied!'); ?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Supplier Dashboard</h1>
      <p class="text-sm text-gray-600">
        Welcome back, <?= htmlspecialchars(trim(($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? ''))) ?>.
      </p>
    </div>
    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold
      <?= ($supplier['status'] ?? '') === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
      <span class="material-symbols-outlined text-base">
        <?= ($supplier['status'] ?? '') === 'active' ? 'verified' : 'hourglass_top' ?>
      </span>
      <?= ($supplier['status'] ?? '') === 'active' ? 'Approved' : ucfirst((string) ($supplier['status'] ?? 'unknown')) ?>
    </span>
  </div>

  <!-- Profile card -->
  <div class="card p-5">
    <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-violet-600">badge</span> Your Profile
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Company</span>
        <span class="font-medium text-gray-900"><?= htmlspecialchars($supplier['title'] ?? '—') ?: '—' ?></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Email</span>
        <span class="font-medium text-gray-900"><?= htmlspecialchars($supplier['email'] ?? '—') ?></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Phone</span>
        <span class="font-medium text-gray-900"><?= htmlspecialchars($supplier['phone'] ?? '—') ?: '—' ?></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-500">Member since</span>
        <span class="font-medium text-gray-900"><?= htmlspecialchars(substr((string) ($supplier['created_at'] ?? ''), 0, 10)) ?></span>
      </div>
    </div>
  </div>

  <!-- Inventory overview -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
      <span class="material-symbols-outlined text-violet-600">inventory_2</span> Your Services
      <span class="text-sm font-normal text-gray-500">(<?= (int) $totalInventory ?> total)</span>
    </h2>

    <?php if ((int) $totalInventory === 0): ?>
      <div class="card p-6 text-center text-gray-500">
        <span class="material-symbols-outlined text-4xl text-gray-300">inbox</span>
        <p class="mt-2 text-sm">No services are assigned to your account yet.
          <?php if (($supplier['status'] ?? '') !== 'active'): ?>
            Once your account is approved, any services linked to you will appear here.
          <?php else: ?>
            Contact the administrator to have your inventory linked.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        <?php foreach ($inventory as $info): ?>
          <div class="card p-4">
            <div class="flex items-center gap-3">
              <div class="w-11 h-11 rounded-lg bg-violet-50 flex items-center justify-center">
                <span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($info['icon']) ?></span>
              </div>
              <div>
                <div class="text-xs text-gray-500 uppercase font-medium"><?= htmlspecialchars($info['label']) ?></div>
                <div class="text-xl font-bold text-gray-900 tabular-nums"><?= (int) $info['count'] ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php foreach ($inventory as $info): ?>
        <?php if (!empty($info['items'])): ?>
          <div class="card p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
              <span class="material-symbols-outlined text-base text-gray-400"><?= htmlspecialchars($info['icon']) ?></span>
              Recent <?= htmlspecialchars($info['label']) ?>
            </h3>
            <div class="divide-y divide-gray-100">
              <?php foreach ($info['items'] as $item): ?>
                <div class="flex items-center justify-between py-2 text-sm">
                  <span class="text-gray-800">
                    <?= htmlspecialchars($item['name'] ?? ($info['label'] . ' #' . ($item['id'] ?? '?'))) ?>
                  </span>
                  <span class="px-2 py-0.5 rounded text-xs font-medium
                    <?= (int) ($item['status'] ?? 0) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                    <?= (int) ($item['status'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <p class="text-xs text-gray-400">
    Read-only overview. Self-service listing management is coming soon.
  </p>
</div>
