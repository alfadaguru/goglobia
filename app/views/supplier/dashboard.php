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

  <!-- Go-live checklist (inc S13) — compact; renders only while incomplete -->
  <?php $onboardingCompact = true; require views . "supplier/_onboarding.php"; ?>

  <!-- Earnings summary (inc S18) — read-only; payouts are a later increment -->
  <?php $earn = (isset($earnings) && is_array($earnings)) ? $earnings : []; ?>
  <?php if (!empty($earn)): ?>
  <div class="card p-5">
    <h2 class="text-lg font-semibold text-gray-900 mb-1 flex items-center gap-2">
      <span class="material-symbols-outlined text-violet-600">payments</span> Earnings
    </h2>
    <p class="text-xs text-gray-500 mb-4">Your net earnings from paid bookings. Payouts to your bank are coming soon.</p>
    <div class="space-y-4">
      <?php foreach ($earn as $cur => $v): ?>
        <div>
          <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?= htmlspecialchars((string) $cur) ?></div>
          <div class="grid grid-cols-3 gap-3">
            <div class="rounded-lg bg-amber-50 p-3">
              <div class="text-xs text-amber-700">Pending</div>
              <div class="text-lg font-bold text-amber-800 tabular-nums"><?= number_format((float) ($v['pending'] ?? 0), 2) ?></div>
            </div>
            <div class="rounded-lg bg-green-50 p-3">
              <div class="text-xs text-green-700">Available</div>
              <div class="text-lg font-bold text-green-800 tabular-nums"><?= number_format((float) ($v['available'] ?? 0), 2) ?></div>
            </div>
            <div class="rounded-lg bg-gray-50 p-3">
              <div class="text-xs text-gray-500">Paid out</div>
              <div class="text-lg font-bold text-gray-700 tabular-nums"><?= number_format((float) ($v['paid'] ?? 0), 2) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

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

  <!-- Manage links -->
  <div class="flex flex-wrap gap-3">
    <a href="<?= root ?>supplier/stays" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">hotel</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">My Hotels</span>
        <span class="block text-xs text-gray-500">Create &amp; manage your properties</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/reservations" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">receipt_long</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Reservations</span>
        <span class="block text-xs text-gray-500">Bookings for your properties</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/housekeeping" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">cleaning_services</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Housekeeping</span>
        <span class="block text-xs text-gray-500">Room status &amp; cleaning</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/maintenance" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">build</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Maintenance</span>
        <span class="block text-xs text-gray-500">Work orders &amp; out-of-order</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/pos" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">restaurant</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Restaurants &amp; POS</span>
        <span class="block text-xs text-gray-500">Outlets, menus &amp; charge-to-room</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/night-audit" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">nightlight</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Night audit</span>
        <span class="block text-xs text-gray-500">Daily close &amp; occupancy</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/insights" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">insights</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Insights</span>
        <span class="block text-xs text-gray-500">Performance at a glance</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/guests" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">groups</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Guests</span>
        <span class="block text-xs text-gray-500">Profiles &amp; stay history</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/reviews" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">reviews</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Reviews</span>
        <span class="block text-xs text-gray-500">Moderate &amp; set your rating</span>
      </span>
    </a>
    <?php if (empty($viewingAsStaff)): // owner-only management links ?>
    <a href="<?= root ?>supplier/roles" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">badge</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Roles &amp; Permissions</span>
        <span class="block text-xs text-gray-500">Define what your team can do</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/staff" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">group</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Staff</span>
        <span class="block text-xs text-gray-500">Invite &amp; manage your team</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/payouts" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">account_balance</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Payouts</span>
        <span class="block text-xs text-gray-500">Withdraw your earnings</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/owners" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">real_estate_agent</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Property owners</span>
        <span class="block text-xs text-gray-500">Owner statements (managed apartments)</span>
      </span>
    </a>
    <a href="<?= root ?>supplier/procurement" class="card px-4 py-3 flex items-center gap-3 hover:border-violet-300 transition">
      <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <span class="material-symbols-outlined text-violet-600">inventory_2</span>
      </span>
      <span>
        <span class="block text-sm font-semibold text-gray-900">Procurement</span>
        <span class="block text-xs text-gray-500">Vendors, stock &amp; purchase orders</span>
      </span>
    </a>
    <?php endif; ?>
  </div>

  <?php if (!empty($viewingAsStaff)): ?>
    <div class="card p-3 text-xs text-gray-500 flex items-center gap-2">
      <span class="material-symbols-outlined text-sm text-gray-400">visibility</span>
      You are signed in as a team member. What you can do is set by your assigned role.
    </div>
  <?php endif; ?>

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
