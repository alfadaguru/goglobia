<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Banquet Event Order (read-only, print-friendly). Var: $beo (from mice_beo_build).
$beo = (isset($beo) && is_array($beo)) ? $beo : [];
$ev  = (isset($beo['event']) && is_array($beo['event'])) ? $beo['event'] : [];
$tot = (isset($beo['totals']) && is_array($beo['totals'])) ? $beo['totals'] : ['charges' => 0, 'payments' => 0, 'balance' => 0];
$items = (isset($beo['items']) && is_array($beo['items'])) ? $beo['items'] : [];
$equip = (isset($beo['equipment']) && is_array($beo['equipment'])) ? $beo['equipment'] : [];
$staff = (isset($beo['staff']) && is_array($beo['staff'])) ? $beo['staff'] : [];
$contracts = (isset($beo['contracts']) && is_array($beo['contracts'])) ? $beo['contracts'] : [];
$cur = htmlspecialchars((string) ($ev['currency'] ?? 'USD'));
$eid = (int) ($ev['id'] ?? 0);
$sid = (int) ($ev['stay_id'] ?? 0);
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-5">
  <div class="flex items-center justify-between gap-3 print:hidden">
    <a href="<?= root ?>supplier/events/manage?stay_id=<?= $sid ?>&event_id=<?= $eid ?>" class="text-xs text-gray-500 hover:text-gray-700 inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">arrow_back</span> Back to event</a>
    <button type="button" onclick="window.print()" class="btn secondary text-xs inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">print</span> Print</button>
  </div>

  <div class="card p-6 space-y-5">
    <div class="border-b border-gray-100 pb-4">
      <div class="text-xs uppercase tracking-widest text-gray-400 font-semibold">Banquet Event Order</div>
      <h1 class="text-2xl font-bold text-gray-900 mt-1"><?= htmlspecialchars((string) ($ev['title'] ?? 'Event')) ?></h1>
      <div class="text-sm text-gray-500 font-mono"><?= htmlspecialchars((string) ($ev['reference'] ?? '')) ?></div>
    </div>

    <div class="grid grid-cols-2 gap-4 text-sm">
      <div><div class="text-xs text-gray-400">Property</div><div class="text-gray-800"><?= htmlspecialchars((string) ($beo['property_name'] ?? '')) ?></div></div>
      <div><div class="text-xs text-gray-400">Function space</div><div class="text-gray-800"><?= htmlspecialchars((string) ($beo['space_name'] ?? '')) ?></div></div>
      <div><div class="text-xs text-gray-400">Date</div><div class="text-gray-800"><?= htmlspecialchars((string) ($ev['event_date'] ?? '')) ?></div></div>
      <div><div class="text-xs text-gray-400">Guests</div><div class="text-gray-800"><?= (int) ($ev['pax'] ?? 0) ?> pax</div></div>
      <div><div class="text-xs text-gray-400">Client</div><div class="text-gray-800"><?= htmlspecialchars((string) ($ev['client_name'] ?? '—')) ?><?= !empty($ev['client_email']) ? ' · ' . htmlspecialchars((string) $ev['client_email']) : '' ?></div></div>
      <div><div class="text-xs text-gray-400">Status</div><div class="text-gray-800"><?= htmlspecialchars(ucfirst((string) ($ev['status'] ?? ''))) ?></div></div>
    </div>

    <!-- Folio -->
    <div>
      <h2 class="text-sm font-semibold text-gray-900 mb-2">Charges &amp; payments</h2>
      <?php if (empty($items)): ?>
        <p class="text-sm text-gray-500">No folio lines.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($items as $it): $isPay = in_array((string) $it['type'], ['payment', 'refund'], true); ?>
                <tr><td class="py-1.5 text-gray-700"><?= htmlspecialchars((string) ($it['description'] ?? '')) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars((string) $it['type']) ?>)</span></td>
                <td class="py-1.5 text-right tabular-nums <?= $isPay ? 'text-green-700' : '' ?>"><?= $isPay ? '−' : '' ?><?= number_format((float) $it['amount'], 2) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="border-t border-gray-200">
              <tr><td class="py-1.5 text-gray-600">Charges</td><td class="py-1.5 text-right font-semibold tabular-nums"><?= $cur ?> <?= number_format((float) $tot['charges'], 2) ?></td></tr>
              <tr><td class="py-1 text-gray-600">Paid</td><td class="py-1 text-right tabular-nums text-green-700"><?= number_format((float) $tot['payments'], 2) ?></td></tr>
              <tr><td class="py-1 text-gray-900 font-semibold">Balance</td><td class="py-1 text-right font-bold tabular-nums"><?= $cur ?> <?= number_format((float) $tot['balance'], 2) ?></td></tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Equipment -->
    <?php if (!empty($equip)): ?>
      <div>
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Equipment</h2>
        <ul class="text-sm text-gray-700 space-y-0.5"><?php foreach ($equip as $a): ?><li class="flex justify-between"><span><?= htmlspecialchars((string) $a['label']) ?> ×<?= (int) $a['qty'] ?></span><span class="tabular-nums"><?= number_format((float) $a['amount'], 2) ?></span></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <!-- Staffing -->
    <?php if (!empty($staff)): ?>
      <div>
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Staffing</h2>
        <ul class="text-sm text-gray-700 space-y-0.5"><?php foreach ($staff as $a): ?><li><?= htmlspecialchars((string) $a['label']) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <!-- Contracts -->
    <?php if (!empty($contracts)): ?>
      <div>
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Contracts</h2>
        <ul class="text-sm text-gray-700 space-y-0.5"><?php foreach ($contracts as $c): ?><li class="flex justify-between"><span class="font-mono text-xs"><?= htmlspecialchars((string) $c['reference']) ?> <span class="text-gray-400"><?= htmlspecialchars(ucfirst((string) $c['status'])) ?></span></span><span class="tabular-nums">deposit <?= number_format((float) $c['deposit_amount'], 2) ?></span></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  </div>
</div>
