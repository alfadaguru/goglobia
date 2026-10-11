<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $handovers = (isset($handovers) && is_array($handovers)) ? $handovers : [];
  $wakeups = (isset($wakeups) && is_array($wakeups)) ? $wakeups : [];
  $stayId = (int) ($stayId ?? 0);
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/stays/shift';
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Shift &amp; wake-ups</h1><p class="text-sm text-gray-600">Shift handover log and scheduled wake-up calls for this property.</p></div>
    <a href="<?= root ?>supplier/front-desk?stay_id=<?= $stayId ?>" class="btn secondary text-sm">Front desk</a>
  </div>

  <!-- WAKE-UP CALLS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Wake-up calls</h2>
    <?php if (empty($wakeups)): ?><p class="text-sm text-gray-400 mb-3">None scheduled. Add one from a reservation's detail page.</p>
    <?php else: ?>
      <table class="w-full text-sm mb-3">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">When</th><th class="px-2 py-2 font-medium">Guest</th><th class="px-2 py-2 font-medium">Note</th><th class="px-2 py-2 font-medium">Status</th><th class="px-2 py-2"></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($wakeups as $w): $st = (string) $w['status']; ?>
            <tr class="<?= $st !== 'scheduled' ? 'opacity-50' : '' ?>">
              <td class="px-2 py-1.5 tabular-nums text-xs"><?= htmlspecialchars(substr((string) $w['wake_at'], 0, 16)) ?></td>
              <td class="px-2 py-1.5 text-gray-600 text-xs"><?= htmlspecialchars((string) ($w['invoice_id'] ?? '')) ?></td>
              <td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($w['note'] ?? '')) ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs <?= $st === 'done' ? 'bg-green-50 text-green-700' : ($st === 'cancelled' ? 'bg-gray-100 text-gray-500' : 'bg-amber-50 text-amber-700') ?>"><?= htmlspecialchars($st) ?></span></td>
              <td class="px-2 py-1.5 text-right">
                <?php if ($canEdit && $st === 'scheduled'): ?>
                  <?php foreach (['done' => 'Mark done', 'cancelled' => 'Cancel'] as $s => $lbl): ?>
                    <form action="<?= $base ?>/wakeup-status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="wakeup_id" value="<?= (int) $w['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button class="text-xs text-violet-600 hover:underline mr-2"><?= $lbl ?></button></form>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- SHIFT HANDOVER -->
  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Log a shift handover</h2>
    <form action="<?= $base ?>/handover" method="POST" class="grid grid-cols-2 md:grid-cols-4 gap-2 items-end">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div><label class="block text-xs text-gray-600 mb-1">Shift</label><select name="shift" class="input text-sm"><option value="morning">Morning</option><option value="evening">Evening</option><option value="night">Night</option></select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" name="handover_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Cash in drawer</label><input type="number" step="0.01" name="cash_balance" class="input text-sm"></div>
      <div><button type="submit" class="btn secondary text-sm w-full">Log</button></div>
      <div class="col-span-2 md:col-span-4"><label class="block text-xs text-gray-600 mb-1">Notes</label><textarea name="notes" rows="2" class="input text-sm" placeholder="Pending issues, VIP arrivals, maintenance, cash notes…"></textarea></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Handover history</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium">Shift</th><th class="px-4 py-2 font-medium">Cash</th><th class="px-4 py-2 font-medium">Notes</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($handovers)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No handovers logged.</td></tr>
        <?php else: foreach ($handovers as $h): ?>
          <tr>
            <td class="px-4 py-2 text-gray-700 text-xs tabular-nums"><?= htmlspecialchars((string) $h['handover_date']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $h['shift'])) ?></td>
            <td class="px-4 py-2 tabular-nums text-xs"><?= $h['cash_balance'] !== null ? number_format((float) $h['cash_balance'], 2) : '—' ?></td>
            <td class="px-4 py-2 text-gray-700 whitespace-pre-line text-xs"><?= htmlspecialchars((string) ($h['notes'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
