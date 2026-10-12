<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Property hub — the drill-in landing for one property. Now a GUIDED SETUP surface:
// a "Set up your property" checklist with real per-step completion state ($setup,
// computed in the route), plus the operational PMS tiles for a live property.
$stay  = (isset($stay) && is_array($stay)) ? $stay : [];
$hub   = (isset($hub) && is_array($hub)) ? $hub : [];
$setup = (isset($setup) && is_array($setup)) ? $setup : [];
$setupDone  = (int) ($setupDone ?? 0);
$setupTotal = (int) ($setupTotal ?? 0);
$sid  = (int) ($stay['id'] ?? 0);
$q    = '?stay_id=' . $sid;
$ls   = (string) ($stay['listing_status'] ?? 'draft');
$lsColor = ['approved'=>'bg-green-50 text-green-700','submitted'=>'bg-blue-50 text-blue-700','queried'=>'bg-amber-50 text-amber-700','rejected'=>'bg-red-50 text-red-700','draft'=>'bg-gray-100 text-gray-600'][$ls] ?? 'bg-gray-100 text-gray-600';
$isLive = (int) ($stay['status'] ?? 0) === 1 && $ls === 'approved';
$pct = $setupTotal > 0 ? (int) round(($setupDone / $setupTotal) * 100) : 0;

// Operational PMS tiles — shown once the property is set up / live.
$tiles = [
  ['Reservations',  'receipt_long',      'supplier/reservations' . $q],
  ['Housekeeping',  'cleaning_services', 'supplier/housekeeping' . $q],
  ['Maintenance',   'build',             'supplier/maintenance' . $q],
  ['F&B / POS',     'restaurant',        'supplier/pos'],
  ['Events',        'celebration',       'supplier/events' . $q],
  ['Night audit',   'nightlight',        'supplier/night-audit' . $q],
  ['Reviews',       'reviews',           'supplier/reviews' . $q],
  ['Site & domain', 'public',            'supplier/stays/site/' . $sid],
];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <nav class="text-xs text-gray-500">
    <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a>
    <span class="mx-1">/</span><span class="text-gray-700"><?= htmlspecialchars((string) ($stay['name'] ?? 'Property')) ?></span>
  </nav>

  <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($stay['name'] ?? 'Property')) ?></h1>
      <p class="text-sm text-gray-600 flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base text-gray-400">location_on</span>
        <?= htmlspecialchars((string) ($stay['location'] ?? '')) ?: 'No location yet' ?>
      </p>
    </div>
    <div class="flex items-center gap-2">
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= $lsColor ?>"><?= htmlspecialchars(ucfirst($ls)) ?></span>
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= $isLive ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $isLive ? 'Live' : 'Offline' ?></span>
    </div>
  </div>

  <!-- ============ GUIDED SETUP ============ -->
  <div class="card p-5 sm:p-6 space-y-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-gray-900">Set up your property</h2>
        <p class="text-xs text-gray-500">Work through these steps. Optional ones can be skipped.</p>
      </div>
      <div class="text-right flex-shrink-0">
        <div class="text-sm font-semibold text-gray-900 tabular-nums"><?= $setupDone ?>/<?= $setupTotal ?> done</div>
        <div class="text-xs text-gray-500"><?= $pct ?>% complete</div>
      </div>
    </div>

    <!-- progress bar -->
    <div class="h-1.5 w-full rounded-full bg-gray-100 overflow-hidden" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
      <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: <?= $pct ?>%"></div>
    </div>

    <!-- steps -->
    <ol class="space-y-2.5">
      <?php foreach ($setup as $st):
        $done = !empty($st['done']); $optional = !empty($st['optional']);
      ?>
        <li class="flex items-center gap-3 rounded-lg border <?= $done ? 'border-emerald-100 bg-emerald-50' : 'border-gray-200' ?> p-3">
          <!-- number / tick -->
          <span class="flex-none w-7 h-7 rounded-full grid place-items-center text-xs font-semibold
            <?= $done ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500' ?>">
            <?php if ($done): ?><span class="material-symbols-outlined text-base">check</span><?php else: ?><?= (int) $st['n'] ?><?php endif; ?>
          </span>
          <!-- icon -->
          <span class="flex-none w-9 h-9 rounded-lg bg-violet-50 hidden sm:grid place-items-center">
            <span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($st['icon']) ?></span>
          </span>
          <!-- label + status -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($st['label']) ?></span>
              <?php if ($optional): ?><span class="text-xs uppercase tracking-wide text-gray-400 border border-gray-200 rounded px-1.5 py-0.5">Optional</span><?php endif; ?>
            </div>
            <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($st['status']) ?></div>
          </div>
          <!-- action -->
          <a href="<?= htmlspecialchars($st['url']) ?>" class="flex-none text-sm font-medium <?= $done ? 'text-gray-500 hover:text-gray-700' : 'text-violet-600 hover:underline' ?>">
            <?= htmlspecialchars($st['cta']) ?> &rarr;
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <!-- ============ OPERATIONS (once live-ish) ============ -->
  <?php if ($isLive || (int) ($hub['rooms'] ?? 0) > 0): ?>
  <div>
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Day-to-day operations</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <?php foreach ($tiles as [$label, $icon, $url]): ?>
        <a href="<?= root . htmlspecialchars($url) ?>" class="card p-4 flex items-center gap-3 hover:border-violet-300 transition">
          <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-violet-600"><?= $icon ?></span></span>
          <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($label) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
