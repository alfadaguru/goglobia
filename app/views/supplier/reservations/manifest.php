<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $rows = (isset($rows) && is_array($rows)) ? $rows : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $which = (string) ($which ?? 'arrivals');
  $date = (string) ($date ?? date('Y-m-d'));
  $stayFilter = (int) ($stayFilter ?? 0);
  $name = fn($r) => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) ($r['invoice_id'] ?? '');
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-5">
  <div class="flex items-center justify-between flex-wrap gap-3 no-print">
    <div><h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(ucfirst($which)) ?> manifest</h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars($date) ?> · <?= count($rows) ?> <?= htmlspecialchars($which) ?></p></div>
    <div class="flex items-end gap-2">
      <form method="GET" action="<?= root ?>supplier/manifest" class="flex items-end gap-2">
        <?php if ($stayFilter > 0): ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>"><?php endif; ?>
        <div><label class="block text-xs text-gray-500 mb-1">Type</label><select name="which" class="input text-sm"><option value="arrivals" <?= $which === 'arrivals' ? 'selected' : '' ?>>Arrivals</option><option value="departures" <?= $which === 'departures' ? 'selected' : '' ?>>Departures</option></select></div>
        <div><label class="block text-xs text-gray-500 mb-1">Date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="input text-sm"></div>
        <button type="submit" class="btn secondary text-sm">Go</button>
      </form>
      <button onclick="window.print()" class="btn secondary text-sm">Print</button>
    </div>
  </div>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Guest</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Pax</th><th class="px-4 py-2 font-medium">Dates</th><th class="px-4 py-2 font-medium">Ref</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($rows)): ?><tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No <?= htmlspecialchars($which) ?> on <?= htmlspecialchars($date) ?>.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars($name($r)) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($propMap[(int) ($r['_hotel_id'] ?? 0)] ?? '')) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= (int) ($r['adults'] ?? 0) ?>A<?= (int) ($r['childs'] ?? 0) > 0 ? ' ' . (int) $r['childs'] . 'C' : '' ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($r['_checkin'] ?? '')) ?> → <?= htmlspecialchars((string) ($r['_checkout'] ?? '')) ?></td>
            <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars((string) ($r['invoice_id'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
