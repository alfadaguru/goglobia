<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Property hub (inc S35) — the drill-in landing for one property. $stay, $hub (counts).
// The sidebar (keyed off the stay id in the path) shows this property's full PMS.
$stay = (isset($stay) && is_array($stay)) ? $stay : [];
$hub  = (isset($hub) && is_array($hub)) ? $hub : [];
$sid  = (int) ($stay['id'] ?? 0);
$q    = '?stay_id=' . $sid;
$ls   = (string) ($stay['listing_status'] ?? 'draft');
$lsColor = ['approved'=>'bg-green-50 text-green-700','submitted'=>'bg-blue-50 text-blue-700','queried'=>'bg-amber-50 text-amber-700','rejected'=>'bg-red-50 text-red-700','draft'=>'bg-gray-100 text-gray-600'][$ls] ?? 'bg-gray-100 text-gray-600';
// Property PMS tiles (shown per permission in the sidebar; here we show the full set).
$tiles = [
  ['Rooms & rates', 'bed',               'supplier/stays/' . $sid . '/rooms'],
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
        <?= htmlspecialchars((string) ($stay['location'] ?? '')) ?>
      </p>
    </div>
    <div class="flex items-center gap-2">
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= $lsColor ?>"><?= htmlspecialchars(ucfirst($ls)) ?></span>
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= (int) ($stay['status'] ?? 0) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= (int) ($stay['status'] ?? 0) === 1 ? 'Live' : 'Offline' ?></span>
    </div>
  </div>

  <!-- Quick stats -->
  <div class="grid grid-cols-3 gap-3">
    <div class="card p-4"><div class="text-xs text-gray-500">Room types</div><div class="text-2xl font-bold text-gray-900 tabular-nums"><?= (int) ($hub['rooms'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Physical rooms</div><div class="text-2xl font-bold text-gray-900 tabular-nums"><?= (int) ($hub['physical_rooms'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Open work orders</div><div class="text-2xl font-bold text-gray-900 tabular-nums"><?= (int) ($hub['open_work_orders'] ?? 0) ?></div></div>
  </div>

  <!-- PMS tiles -->
  <div>
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Manage this property</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <?php foreach ($tiles as [$label, $icon, $url]): ?>
        <a href="<?= root . htmlspecialchars($url) ?>" class="card p-4 flex items-center gap-3 hover:border-violet-300 transition">
          <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-violet-600"><?= $icon ?></span></span>
          <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($label) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
