<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $sid = (int) ($stay['id'] ?? 0);
  $rows = (isset($auditRows) && is_array($auditRows)) ? $auditRows : [];
  $typeBadge = [
    'availability' => 'bg-blue-50 text-blue-700',
    'stop_sell'    => 'bg-amber-50 text-amber-700',
    'min_stay'     => 'bg-violet-50 text-violet-700',
    'owner_block'  => 'bg-red-50 text-red-700',
    'mixed'        => 'bg-gray-100 text-gray-600',
  ];
  $typeLabel = ['availability'=>'Availability','stop_sell'=>'Stop-sell','min_stay'=>'Min-stay','owner_block'=>'Owner block','mixed'=>'Mixed'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">Availability log</h1>
    <a href="<?= root ?>supplier/calendar?stay_id=<?= $sid ?>" class="text-sm text-blue-600 hover:underline">&larr; Calendar</a>
  </div>
  <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?> — every change to availability, stop-sell, min-stay and owner blocks, newest first.</p>

  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <th class="px-4 py-2">When</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Dates</th>
            <th class="px-4 py-2">Change</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">By</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No availability changes recorded yet.</td></tr>
          <?php else: foreach ($rows as $r): $t = (string) ($r['change_type'] ?? 'mixed'); ?>
            <tr class="border-b border-gray-50 last:border-0">
              <td class="px-4 py-2 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
              <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $typeBadge[$t] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($typeLabel[$t] ?? ucfirst($t)) ?></span></td>
              <td class="px-4 py-2 text-gray-600 whitespace-nowrap"><?= htmlspecialchars((string) ($r['date_from'] ?? '')) ?><?= ($r['date_from'] ?? '') !== ($r['date_to'] ?? '') ? ' → ' . htmlspecialchars((string) ($r['date_to'] ?? '')) : '' ?></td>
              <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) ($r['detail'] ?? '')) ?></td>
              <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($r['reason'] ?? '')) ?: '<span class="text-gray-300">—</span>' ?></td>
              <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars((string) ($r['actor'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
