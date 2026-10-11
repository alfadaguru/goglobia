<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $leases      = (isset($leases) && is_array($leases)) ? $leases : [];
  $propMap     = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $positions   = (isset($positions) && is_array($positions)) ? $positions : [];
  $selected    = (isset($selected) && is_array($selected)) ? $selected : null;
  $schedule    = (isset($schedule) && is_array($schedule)) ? $schedule : [];
  $statement   = (isset($statement) && is_array($statement)) ? $statement : null;
  $inspections = (isset($inspections) && is_array($inspections)) ? $inspections : [];
  $billedStarts = (isset($billedStarts) && is_array($billedStarts)) ? $billedStarts : [];
  $canEdit     = !empty($canEdit);
  $base        = root . 'supplier/lease-billing';
  $methods     = function_exists('lease_ext_methods') ? lease_ext_methods() : ['cash'=>'Cash'];
  $inspKinds   = function_exists('lease_ext_inspection_kinds') ? lease_ext_inspection_kinds() : ['periodic'=>'Periodic'];
  $stColor     = ['active'=>'bg-green-50 text-green-700','ended'=>'bg-gray-100 text-gray-500','terminated'=>'bg-red-50 text-red-700'];
  $selId       = $selected ? (int) $selected['id'] : 0;
  $selCur      = $selected ? htmlspecialchars((string) ($selected['currency'] ?? 'USD')) : 'USD';
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Lease billing</h1>
    <p class="text-sm text-gray-600">Recurring rent schedule with escalation, arrears &amp; late fees, tenant receipts, move-in/out inspections and statements. Each rent charge, late fee and receipt posts a balanced journal entry to your books.</p>
  </div>

  <!-- LEASE OVERVIEW with live arrears -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-900">Leases</h2></div>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Tenant / unit</th>
        <th class="px-4 py-2 font-medium">Property</th>
        <th class="px-4 py-2 font-medium text-right">Rent</th>
        <th class="px-4 py-2 font-medium text-right">Balance</th>
        <th class="px-4 py-2 font-medium text-right">Arrears</th>
        <th class="px-4 py-2 font-medium">Status</th>
        <th class="px-4 py-2 font-medium"></th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($leases)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No leases. Create them under “Leases”.</td></tr>
        <?php else: foreach ($leases as $l): $lid = (int) $l['id']; $st = (string) $l['status']; $pos = $positions[$lid] ?? ['balance'=>0,'overdue'=>0,'days_overdue'=>0]; $cur = htmlspecialchars((string) $l['currency']); ?>
          <tr class="<?= $selId === $lid ? 'bg-violet-50/40' : '' ?>">
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $l['tenant_name']) ?><?php if (!empty($l['unit_label'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $l['unit_label']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $l['stay_id']] ?? ('#' . $l['stay_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $cur ?> <?= number_format((float) $l['rent_amount'], 2) ?>/<?= htmlspecialchars(substr((string) $l['rent_cycle'], 0, 2)) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) ($pos['balance'] ?? 0), 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= (float) ($pos['overdue'] ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' ?>"><?= number_format((float) ($pos['overdue'] ?? 0), 2) ?><?php if ((int) ($pos['days_overdue'] ?? 0) > 0): ?><span class="block text-[10px] font-normal"><?= (int) $pos['days_overdue'] ?>d</span><?php endif; ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td class="px-4 py-2"><a href="<?= $base ?>?lease=<?= $lid ?>" class="text-xs text-violet-600 hover:underline">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($selected !== null): $pos = $positions[$selId] ?? lease_ext_outstanding($db, $selId); ?>
  <!-- DRILL-DOWN for the selected lease -->
  <div class="card p-5 space-y-5">
    <div class="flex items-start justify-between flex-wrap gap-2">
      <div>
        <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars((string) $selected['tenant_name']) ?><?php if (!empty($selected['unit_label'])): ?> · <?= htmlspecialchars((string) $selected['unit_label']) ?><?php endif; ?></h2>
        <p class="text-xs text-gray-500"><?= $selCur ?> <?= number_format((float) $selected['rent_amount'], 2) ?> / <?= htmlspecialchars((string) $selected['rent_cycle']) ?>
          · term <?= htmlspecialchars((string) ($selected['start_date'] ?? '—')) ?><?= !empty($selected['end_date']) ? ' → ' . htmlspecialchars((string) $selected['end_date']) : '' ?></p>
      </div>
      <div class="text-right">
        <div class="text-xs text-gray-500">Outstanding balance</div>
        <div class="text-xl font-bold tabular-nums text-gray-900"><?= $selCur ?> <?= number_format((float) ($pos['balance'] ?? 0), 2) ?></div>
        <?php if ((float) ($pos['overdue'] ?? 0) > 0): ?><div class="text-xs text-red-600"><?= number_format((float) $pos['overdue'], 2) ?> overdue (<?= (int) ($pos['days_overdue'] ?? 0) ?>d)</div><?php endif; ?>
      </div>
    </div>

    <?php if ($canEdit): ?>
    <!-- Money actions -->
    <div class="flex flex-wrap items-end gap-4 border-t border-gray-100 pt-4">
      <form action="<?= $base ?>/run" method="POST" onsubmit="return confirm('Raise rent charges for every due period not yet billed? Each posts a journal entry.');">
        <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>">
        <button type="submit" class="btn emerald text-sm">Run rent billing</button>
      </form>
      <form action="<?= $base ?>/receive" method="POST" class="flex items-end gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>">
        <div><label class="block text-[11px] text-gray-500 mb-0.5">Receive payment</label>
          <input type="number" name="amount" step="0.01" min="0.01" max="<?= htmlspecialchars((string) max(0, (float) ($pos['balance'] ?? 0))) ?>" class="input text-sm w-28" placeholder="Amount" required></div>
        <select name="method" class="input text-sm"><?php foreach ($methods as $mk => $mv): ?><option value="<?= htmlspecialchars((string) $mk) ?>"><?= htmlspecialchars((string) $mv) ?></option><?php endforeach; ?></select>
        <input type="text" name="reference" class="input text-sm w-24" placeholder="Ref">
        <button type="submit" class="btn text-sm">Receipt</button>
      </form>
      <?php if (($selected['late_fee_mode'] ?? 'none') !== 'none' && (float) ($pos['overdue'] ?? 0) > 0): ?>
      <form action="<?= $base ?>/late-fee" method="POST" onsubmit="return confirm('Apply a late fee on the current arrears? This posts a journal entry.');">
        <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>">
        <button type="submit" class="btn text-sm text-red-600 border-red-200">Apply late fee</button>
      </form>
      <?php endif; ?>
      <form action="<?= $base ?>/send-statement" method="POST">
        <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>">
        <button type="submit" class="btn ghost text-sm">Send statement</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- BILLING SCHEDULE -->
    <div>
      <h3 class="text-sm font-semibold text-gray-900 mb-2">Billing schedule <span class="text-xs font-normal text-gray-400">(escalated rent per period; green = already billed)</span></h3>
      <div class="overflow-x-auto max-h-72 overflow-y-auto border border-gray-100 rounded">
        <table class="w-full text-sm">
          <thead class="sticky top-0 bg-white"><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-3 py-2 font-medium">Period</th><th class="px-3 py-2 font-medium">Due</th>
            <th class="px-3 py-2 font-medium text-right">Rent</th><th class="px-3 py-2 font-medium"></th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($schedule)): ?>
              <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">No periods due yet (check the lease start date).</td></tr>
            <?php else: foreach ($schedule as $p): $billed = !empty($billedStarts[$p['period_start']]); ?>
              <tr class="<?= $billed ? 'bg-green-50/40' : '' ?>">
                <td class="px-3 py-1.5 text-gray-700 text-xs"><?= htmlspecialchars($p['period_start']) ?> → <?= htmlspecialchars($p['period_end']) ?></td>
                <td class="px-3 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars($p['due_date']) ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums"><?= $selCur ?> <?= number_format((float) $p['amount'], 2) ?></td>
                <td class="px-3 py-1.5 text-right">
                  <?php if ($billed): ?><span class="text-[11px] text-green-600">Billed</span>
                  <?php elseif ($canEdit): ?>
                    <form action="<?= $base ?>/charge" method="POST" class="inline">
                      <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>"><input type="hidden" name="period_start" value="<?= htmlspecialchars($p['period_start']) ?>">
                      <button type="submit" class="text-[11px] text-violet-600 hover:underline">Charge</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TENANT STATEMENT -->
    <?php if ($statement !== null): ?>
    <div>
      <h3 class="text-sm font-semibold text-gray-900 mb-2">Tenant statement</h3>
      <div class="overflow-x-auto border border-gray-100 rounded">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Description</th>
            <th class="px-3 py-2 font-medium text-right">Charge</th><th class="px-3 py-2 font-medium text-right">Payment</th>
            <th class="px-3 py-2 font-medium text-right">Balance</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($statement['lines'])): ?>
              <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">No ledger activity yet.</td></tr>
            <?php else: foreach ($statement['lines'] as $ln): ?>
              <tr>
                <td class="px-3 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) $ln['date']) ?></td>
                <td class="px-3 py-1.5 text-gray-700 text-xs"><?= htmlspecialchars((string) $ln['description']) ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums"><?= (float) $ln['debit'] > 0 ? number_format((float) $ln['debit'], 2) : '' ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums text-green-700"><?= (float) $ln['credit'] > 0 ? number_format((float) $ln['credit'], 2) : '' ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums font-medium"><?= number_format((float) $ln['balance'], 2) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot><tr class="border-t border-gray-100 bg-gray-50 font-semibold">
            <td class="px-3 py-2 text-xs text-gray-600" colspan="2">Totals (<?= $selCur ?>)</td>
            <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($statement['totals']['charged'] ?? 0), 2) ?></td>
            <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($statement['totals']['paid'] ?? 0), 2) ?></td>
            <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($statement['totals']['balance'] ?? 0), 2) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- INSPECTIONS -->
    <div>
      <h3 class="text-sm font-semibold text-gray-900 mb-2">Condition inspections</h3>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/inspection" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-2 mb-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="lease_id" value="<?= $selId ?>">
        <select name="kind" class="input text-sm"><?php foreach ($inspKinds as $ik => $iv): ?><option value="<?= htmlspecialchars((string) $ik) ?>"><?= htmlspecialchars((string) $iv) ?></option><?php endforeach; ?></select>
        <input type="date" name="inspected_on" class="input text-sm" value="<?= date('Y-m-d') ?>">
        <input type="text" name="summary" class="input text-sm md:col-span-2" placeholder="Summary / notes">
        <input type="number" name="deposit_deduction" step="0.01" min="0" class="input text-sm" placeholder="Advisory deduction">
        <div class="md:col-span-5"><button type="submit" class="btn text-sm">Record inspection</button>
          <span class="text-[11px] text-gray-400 ml-2">An advisory deduction is for the report only — raise a deposit charge separately to move money.</span></div>
      </form>
      <?php endif; ?>
      <div class="overflow-x-auto border border-gray-100 rounded">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Type</th>
            <th class="px-3 py-2 font-medium">Summary</th><th class="px-3 py-2 font-medium">Inspector</th>
            <th class="px-3 py-2 font-medium text-right">Advisory ded.</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($inspections)): ?>
              <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">No inspections recorded.</td></tr>
            <?php else: foreach ($inspections as $ins): ?>
              <tr>
                <td class="px-3 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) $ins['inspected_on']) ?></td>
                <td class="px-3 py-1.5 text-xs"><?= htmlspecialchars((string) ($inspKinds[$ins['kind']] ?? $ins['kind'])) ?></td>
                <td class="px-3 py-1.5 text-gray-700 text-xs"><?= htmlspecialchars((string) ($ins['summary'] ?? '')) ?></td>
                <td class="px-3 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($ins['inspector'] ?? '')) ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums text-xs"><?= $ins['deposit_deduction'] !== null ? number_format((float) $ins['deposit_deduction'], 2) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
