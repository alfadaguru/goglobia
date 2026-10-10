<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Guest CRM list (inc S29). $guests (profiles), $vip[email=>true], $propMap.
$guests  = (isset($guests) && is_array($guests)) ? $guests : [];
$vip     = (isset($vip) && is_array($vip)) ? $vip : [];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$search  = trim((string) ($_GET['q'] ?? ''));
$base = root . 'supplier/guests';
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

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Guests</h1>
      <p class="text-sm text-gray-600">Everyone who has booked with your properties, aggregated by email.</p>
    </div>
    <form method="GET" action="<?= $base ?>" class="flex items-center gap-2">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="input text-sm" placeholder="Search name or email">
      <button type="submit" class="btn secondary text-sm">Search</button>
      <?php if ($search !== ''): ?><a href="<?= $base ?>" class="text-sm text-gray-500 hover:underline">Clear</a><?php endif; ?>
    </form>
  </div>

  <?php
    $loyaltyCfg = (isset($loyaltyCfg) && is_array($loyaltyCfg)) ? $loyaltyCfg : ['enabled' => false, 'points_per_currency' => 1.0];
    $isOwner = !empty($isOwner);
  ?>
  <?php if ($isOwner): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">loyalty</span> Loyalty programme</h2>
    <p class="text-xs text-gray-500 mb-3">When enabled, guests earn points automatically on each paid stay (at checkout).</p>
    <form action="<?= $base ?>/loyalty/config" method="POST" class="flex flex-wrap items-end gap-3">
      <?= CSRF::tokenField() ?>
      <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="enabled" value="1" class="checkbox-input" <?= !empty($loyaltyCfg['enabled']) ? 'checked' : '' ?>> Enable loyalty</label>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Points per 1.00 spent</label><input type="number" name="points_per_currency" min="0" step="0.0001" value="<?= htmlspecialchars((string) (float) $loyaltyCfg['points_per_currency']) ?>" class="input text-sm w-28"></div>
      <button type="submit" class="btn secondary text-sm">Save</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (empty($guests)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">groups</span>
      <p class="mt-2 text-sm">No guests<?= $search !== '' ? ' match that search' : ' yet' ?>.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Guest</th><th class="px-4 py-3 font-medium">Stays</th>
          <th class="px-4 py-3 font-medium">Total spent</th><th class="px-4 py-3 font-medium">Properties</th>
          <th class="px-4 py-3 font-medium">Last stay</th><th class="px-4 py-3 font-medium"></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($guests as $g): ?>
            <tr>
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900 flex items-center gap-1.5">
                  <?= htmlspecialchars($g['name'] ?: $g['email']) ?>
                  <?php if (!empty($vip[$g['email']])): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">VIP</span><?php endif; ?>
                </div>
                <div class="text-xs text-gray-500"><?= htmlspecialchars($g['email']) ?></div>
              </td>
              <td class="px-4 py-3 tabular-nums"><?= (int) $g['stays'] ?><?php if ((int) $g['cancelled'] > 0): ?> <span class="text-xs text-gray-400">(<?= (int) $g['cancelled'] ?> cxl)</span><?php endif; ?></td>
              <td class="px-4 py-3 tabular-nums font-medium"><?= htmlspecialchars((string) $g['currency']) ?> <?= number_format((float) $g['total_spent'], 2) ?></td>
              <td class="px-4 py-3 tabular-nums"><?= count($g['properties']) ?></td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $g['last_seen'], 0, 10)) ?></td>
              <td class="px-4 py-3"><a href="<?= $base ?>/<?= htmlspecialchars($g['token']) ?>" class="text-blue-600 hover:underline text-xs font-medium">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Guests are matched by email across up to the most recent 5,000 bookings.</p>
  <?php endif; ?>
</div>
