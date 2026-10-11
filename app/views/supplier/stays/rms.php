<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $kpis = (isset($kpis) && is_array($kpis)) ? $kpis : [];
  $forecast = (isset($forecast) && is_array($forecast)) ? $forecast : [];
  $pace = (isset($pace) && is_array($pace)) ? $pace : [];
  $competitor = (string) ($competitor ?? 'not_connected');
  $stayId = (int) ($stayId ?? 0);
  $cur = htmlspecialchars((string) ($cur ?? 'USD'));
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Revenue management</h1><p class="text-sm text-gray-600">Performance KPIs, forward occupancy and booking pace for this property.</p></div>
    <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="btn secondary text-sm">‹ Property</a>
  </div>

  <!-- KPIs (last 30 closed days) -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">ADR</div><div class="text-xl font-bold tabular-nums"><?= $cur ?> <?= number_format((float) ($kpis['adr'] ?? 0), 2) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">RevPAR</div><div class="text-xl font-bold tabular-nums"><?= $cur ?> <?= number_format((float) ($kpis['revpar'] ?? 0), 2) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">TRevPAR</div><div class="text-xl font-bold tabular-nums"><?= $cur ?> <?= number_format((float) ($kpis['trevpar'] ?? 0), 2) ?></div><div class="text-xs text-gray-400">incl. F&amp;B</div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">GOPPAR</div><div class="text-xl font-bold tabular-nums"><?= $cur ?> <?= number_format((float) ($kpis['goppar'] ?? 0), 2) ?></div><div class="text-xs text-gray-400">gross op. profit / room</div></div>
  </div>
  <p class="text-xs text-gray-400 -mt-3">Based on the last 30 closed business days. GOPPAR uses recorded F&amp;B + room revenue; subtract operating expenses in Finance for a fuller figure.</p>

  <!-- Booking pace + lead time -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Bookings (30d)</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($pace['bookings'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Avg lead time</div><div class="text-2xl font-bold tabular-nums"><?= number_format((float) ($pace['avg_lead_days'] ?? 0), 1) ?> <span class="text-sm font-normal text-gray-400">days</span></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Pace</div><div class="text-2xl font-bold tabular-nums"><?= number_format((float) ($pace['pace_per_day'] ?? 0), 2) ?> <span class="text-sm font-normal text-gray-400">/day</span></div></div>
  </div>

  <!-- Forward occupancy forecast -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Forward occupancy (next 30 nights)</h2>
    <?php if (empty($forecast)): ?><p class="text-sm text-gray-400">No forward data (add physical rooms + take bookings).</p>
    <?php else: ?>
      <div class="flex items-end gap-0.5 h-32 overflow-x-auto">
        <?php foreach ($forecast as $f): $occ = $f['occupancy_pct']; $h = $occ === null ? 0 : min(100, (float) $occ); ?>
          <div class="flex flex-col items-center justify-end" style="min-width:14px" title="<?= htmlspecialchars((string) $f['date']) ?>: <?= $occ === null ? 'n/a' : $occ . '%' ?> (<?= (int) $f['committed'] ?>/<?= (int) $f['capacity'] ?>)">
            <div style="height: <?= (int) $h ?>%; width:10px; background-color: <?= $h >= 80 ? '#dc2626' : ($h >= 50 ? '#f59e0b' : '#7c3aed') ?>; border-radius:2px 2px 0 0;"></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-400 mt-2">Each bar is a night; height = committed occupancy. Red ≥80% (consider raising rates), amber ≥50%.</p>
    <?php endif; ?>
  </div>

  <!-- Automated yield + competitor (honest) -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-1">Automated yield rules</h2>
      <p class="text-xs text-gray-500 mb-2">Occupancy / LOS / lead-time pricing rules apply automatically at booking.</p>
      <a href="<?= root ?>supplier/stays/<?= $stayId ?>/rate-rules" class="btn secondary text-sm">Manage rate rules</a>
    </div>
    <div class="card p-5">
      <div class="flex items-center justify-between mb-1"><h2 class="text-sm font-semibold text-gray-900">Competitor rates</h2><span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700"><?= $competitor === 'connected' ? 'Connected' : 'Not connected' ?></span></div>
      <p class="text-xs text-gray-500">Competitor rate-shopping needs an external data provider configured by the platform. Not connected.</p>
    </div>
  </div>
</div>
