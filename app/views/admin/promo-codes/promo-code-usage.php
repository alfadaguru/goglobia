<?php
// app/views/admin/promo-codes/promo-code-usage.php
// Per-redemption drill-down for one promo code (the detail behind used_count).
// Reads the promo_code_usage ledger. $promo is set by the route.
@$SECURE or die('Access Denied!');

$pid = (int) ($promo['id'] ?? 0);

// Redemptions, newest first.
$rows = $db->select('promo_code_usage', '*', [
    'promo_id' => $pid,
    'ORDER'    => ['used_at' => 'DESC'],
]) ?: [];

// Totals (sum discount per currency — codes can be redeemed in mixed currencies).
$byCurrency = [];
foreach ($rows as $r) {
    $cur = strtoupper((string) ($r['currency'] ?: '—'));
    $byCurrency[$cur] = ($byCurrency[$cur] ?? 0) + (float) $r['discount_amount'];
}
$count = count($rows);

// Resolve a friendly name for each user_id (varchar user_id column) once.
$names = [];
$ids = array_values(array_filter(array_unique(array_map(fn($r) => (string) ($r['user_id'] ?? ''), $rows))));
if (!empty($ids)) {
    foreach ($db->select('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $ids]) ?: [] as $u) {
        $names[(string) $u['user_id']] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: (string) ($u['email'] ?? '');
    }
}
?>

<div class="container my-4" style="max-width:1000px">
  <div class="flex items-center gap-2 mb-4">
    <a href="<?= root.admin ?>/promo-codes" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900">Redemptions</h1>
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-indigo-50 border border-indigo-200 ml-1">
      <span class="material-symbols-outlined text-indigo-600 text-sm">confirmation_number</span>
      <span class="font-mono font-semibold text-indigo-700 text-sm"><?= htmlspecialchars((string) ($promo['code'] ?? '')) ?></span>
    </span>
  </div>

  <!-- Summary cards -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
    <div class="rounded-xl border border-gray-100 bg-white p-3 text-center">
      <div class="text-xl font-bold tabular-nums text-gray-900"><?= number_format($count) ?></div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-0.5">Redemptions</div>
    </div>
    <div class="rounded-xl border border-gray-100 bg-white p-3 text-center">
      <div class="text-xl font-bold tabular-nums text-gray-900">
        <?= $promo['usage_limit'] ? number_format((int) $promo['usage_limit']) : '∞' ?>
      </div>
      <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-0.5">Limit</div>
    </div>
    <?php
    // Show up to two currency totals in the remaining cards.
    $curList = $byCurrency; $shown = 0;
    foreach ($curList as $cur => $sum):
      if ($shown >= 2) break; $shown++;
    ?>
      <div class="rounded-xl border border-gray-100 bg-white p-3 text-center">
        <div class="text-xl font-bold tabular-nums text-green-700"><?= htmlspecialchars($cur) ?> <?= number_format($sum, 2) ?></div>
        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-0.5">Discount given</div>
      </div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
      <div class="rounded-xl border border-gray-100 bg-white p-3 text-center">
        <div class="text-xl font-bold tabular-nums text-gray-300">—</div>
        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-0.5">Discount given</div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($count === 0): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">confirmation_number</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No redemptions yet</h3>
      <p class="text-sm text-gray-500 mt-1">This code hasn't been used on any booking.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Customer</th>
            <th class="px-4 py-3 font-semibold">Booking</th>
            <th class="px-4 py-3 font-semibold">Service</th>
            <th class="px-4 py-3 font-semibold text-right">Discount</th>
            <th class="px-4 py-3 font-semibold">When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $uref = (string) ($r['user_id'] ?? '');
            $who  = $names[$uref] ?? '';
            if ($who === '') {
                // Guest or unresolved — show the stored user_ref (email or guest:invoice).
                $ur = (string) ($r['user_ref'] ?? '');
                $who = str_starts_with($ur, 'guest:') ? 'Guest' : ($ur !== '' ? $ur : '—');
            }
          ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-800"><?= htmlspecialchars($who) ?></td>
              <td class="px-4 py-3 font-mono text-xs text-gray-600"><?= htmlspecialchars((string) ($r['invoice_id'] ?? '')) ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars(ucfirst((string) ($r['module'] ?: '—'))) ?></td>
              <td class="px-4 py-3 text-right tabular-nums font-semibold text-green-700">
                <?= htmlspecialchars(strtoupper((string) ($r['currency'] ?: ''))) ?> <?= number_format((float) $r['discount_amount'], 2) ?>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($r['used_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
