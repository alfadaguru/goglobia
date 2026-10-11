<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $perf = (isset($perf) && is_array($perf)) ? $perf : [];
  $L = $perf['listings'] ?? ['total'=>0,'live'=>0,'pending'=>0,'draft'=>0,'rejected'=>0];
  $earn = $perf['earnings'] ?? [];
  $comm = $perf['commission_pct'] ?? null;
  $quota = $perf['quota'] ?? ['max'=>null,'used'=>0,'approved'=>false];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Marketplace</h1>
    <p class="text-sm text-gray-600">How your listings are performing on the GoGlobia marketplace, your earnings, and the platform commission you're on.</p>
  </div>

  <!-- Listings scorecard -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Listings</div><div class="text-2xl font-bold tabular-nums"><?= (int) $L['total'] ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Live</div><div class="text-2xl font-bold tabular-nums text-green-700"><?= (int) $L['live'] ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">In review</div><div class="text-2xl font-bold tabular-nums text-amber-700"><?= (int) $L['pending'] ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Draft</div><div class="text-2xl font-bold tabular-nums text-gray-500"><?= (int) $L['draft'] ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Paid bookings</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($perf['bookings_paid'] ?? 0) ?></div></div>
  </div>

  <!-- Commission + quota -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Platform commission &amp; quota</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
      <div><div class="text-xs text-gray-500">Commission on each booking</div><div class="text-lg font-bold"><?= $comm !== null ? htmlspecialchars((string) (float) $comm) . '%' : 'Standard' ?></div><div class="text-xs text-gray-400">Set by the platform at approval.</div></div>
      <div><div class="text-xs text-gray-500">Property quota</div><div class="text-lg font-bold tabular-nums"><?= (int) $quota['used'] ?><?= $quota['max'] !== null ? ' / ' . (int) $quota['max'] : '' ?></div><div class="text-xs text-gray-400">Properties created vs allowed.</div></div>
      <div><div class="text-xs text-gray-500">Status</div><div class="text-lg font-bold <?= !empty($quota['approved']) ? 'text-green-700' : 'text-amber-700' ?>"><?= !empty($quota['approved']) ? 'Approved' : 'Pending' ?></div></div>
    </div>
  </div>

  <!-- Earnings by currency -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Earnings</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Currency</th><th class="px-4 py-2 font-medium text-right">Pending</th><th class="px-4 py-2 font-medium text-right">Available</th><th class="px-4 py-2 font-medium text-right">Paid out</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($earn)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No earnings yet.</td></tr>
        <?php else: foreach ($earn as $cur => $e): ?>
          <tr>
            <td class="px-4 py-2 font-medium"><?= htmlspecialchars((string) $cur) ?></td>
            <td class="px-4 py-2 text-right tabular-nums text-amber-700"><?= number_format((float) ($e['pending'] ?? 0), 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums text-green-700"><?= number_format((float) ($e['available'] ?? 0), 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums text-gray-600"><?= number_format((float) ($e['paid'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <p class="text-xs text-gray-400 px-4 pb-3 pt-1">Available earnings can be withdrawn from <a href="<?= root ?>supplier/payouts" class="text-violet-600 hover:underline">Payouts</a>.</p>
  </div>
</div>
