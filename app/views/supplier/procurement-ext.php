<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Procurement — EXTENDED (docs 01b module 41). Inputs from supplierProcurementExtRoutes:
//   $vendors, $items, $vendorNames, $requisitions, $rfqs, $contracts, $budget,
//   $openPos, $compare (?rfq=), $poDetail (?po=).
$vendors      = (isset($vendors) && is_array($vendors)) ? $vendors : [];
$items        = (isset($items) && is_array($items)) ? $items : [];
$vendorNames  = (isset($vendorNames) && is_array($vendorNames)) ? $vendorNames : [];
$requisitions = (isset($requisitions) && is_array($requisitions)) ? $requisitions : [];
$rfqs         = (isset($rfqs) && is_array($rfqs)) ? $rfqs : [];
$contracts    = (isset($contracts) && is_array($contracts)) ? $contracts : [];
$budget       = (isset($budget) && is_array($budget)) ? $budget : [];
$openPos      = (isset($openPos) && is_array($openPos)) ? $openPos : [];
$compare      = (isset($compare) && is_array($compare)) ? $compare : null;
$poDetail     = (isset($poDetail) && is_array($poDetail)) ? $poDetail : null;
$base = root . 'supplier/procurement/ext';
$fmt  = fn($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
$reqColor = ['requested'=>'bg-amber-50 text-amber-700','approved'=>'bg-green-50 text-green-700','rejected'=>'bg-red-50 text-red-700','converted'=>'bg-blue-50 text-blue-700'];
$rfqColor = ['open'=>'bg-amber-50 text-amber-700','awarded'=>'bg-green-50 text-green-700','closed'=>'bg-gray-100 text-gray-600'];
$flagColor = ['ok'=>'bg-green-50 text-green-700','expiring'=>'bg-amber-50 text-amber-700','expired'=>'bg-red-50 text-red-700','over_cap'=>'bg-red-50 text-red-700'];
$thisMonth = date('Y-m');
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

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Procurement — extended</h1>
      <p class="text-sm text-gray-600">Requisitions &amp; approval, RFQ compare, partial deliveries, returns, three-way match, contracts, and budget. Spend is always computed server-side and posted to the ledger when goods are received.</p>
    </div>
    <a href="<?= root ?>supplier/procurement" class="btn secondary text-sm whitespace-nowrap">Vendors &amp; stock</a>
  </div>

  <!-- ============ BUDGET ============ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Budget control <span class="text-gray-400 font-normal">· <?= htmlspecialchars((string) ($budget['period'] ?? $thisMonth)) ?></span></h2>
    <?php if (!empty($budget) && (float) ($budget['budget'] ?? 0) > 0): ?>
      <?php $bUsedPct = min(100, (int) round(($budget['committed'] / max(0.01, $budget['budget'])) * 100)); ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm">
        <div><div class="text-gray-500 text-xs">Budget</div><div class="font-semibold tabular-nums"><?= htmlspecialchars((string) $budget['currency']) ?> <?= number_format((float) $budget['budget'], 2) ?></div></div>
        <div><div class="text-gray-500 text-xs">Committed</div><div class="font-semibold tabular-nums"><?= number_format((float) $budget['committed'], 2) ?></div></div>
        <div><div class="text-gray-500 text-xs">Actual (received)</div><div class="font-semibold tabular-nums"><?= number_format((float) $budget['actual'], 2) ?></div></div>
        <div><div class="text-gray-500 text-xs">Remaining</div><div class="font-semibold tabular-nums <?= !empty($budget['over']) ? 'text-red-600' : 'text-green-700' ?>"><?= number_format((float) $budget['remaining'], 2) ?></div></div>
      </div>
      <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mb-1"><div class="h-2 <?= !empty($budget['over']) ? 'bg-red-500' : 'bg-violet-500' ?>" style="width: <?= $bUsedPct ?>%"></div></div>
      <?php if (!empty($budget['over'])): ?><p class="text-xs text-red-600">Over budget — committed spend exceeds the cap for this period.</p><?php endif; ?>
    <?php else: ?>
      <p class="text-sm text-gray-500 mb-3">No budget set for this period. PO creation is never hard-blocked — the budget is advisory.</p>
    <?php endif; ?>
    <form action="<?= $base ?>/budget" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <input type="text" name="period" value="<?= htmlspecialchars((string) ($budget['period'] ?? $thisMonth)) ?>" pattern="\d{4}-\d{2}" class="input text-sm w-28" placeholder="YYYY-MM" required>
      <input type="number" name="amount" step="0.01" min="0" class="input text-sm w-32" placeholder="budget cap" required>
      <input type="text" name="currency" maxlength="3" value="<?= htmlspecialchars((string) ($budget['currency'] ?? 'USD')) ?>" class="input text-sm w-20">
      <button type="submit" class="btn secondary text-sm">Save budget</button>
    </form>
  </div>

  <!-- ============ REQUISITIONS ============ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Purchase requisitions</h2>
    <?php if (!empty($requisitions)): ?>
      <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Ref</th><th class="px-2 py-1.5 font-medium">Title</th><th class="px-2 py-1.5 font-medium text-right">Est.</th><th class="px-2 py-1.5 font-medium">Vendor</th><th class="px-2 py-1.5 font-medium">Status</th><th class="px-2 py-1.5 font-medium">Actions</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($requisitions as $r): $rst = (string) $r['status']; ?>
            <tr>
              <td class="px-2 py-1.5 font-mono text-xs text-gray-600"><?= htmlspecialchars((string) $r['reference']) ?></td>
              <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $r['title']) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums text-gray-600"><?= htmlspecialchars((string) $r['currency']) ?> <?= number_format((float) $r['estimated_cost'], 2) ?></td>
              <td class="px-2 py-1.5 text-gray-600"><?= $r['vendor_id'] ? htmlspecialchars($vendorNames[(int) $r['vendor_id']] ?? ('#' . $r['vendor_id'])) : '<span class="text-gray-300">—</span>' ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $reqColor[$rst] ?? '' ?>"><?= htmlspecialchars(ucfirst($rst)) ?></span></td>
              <td class="px-2 py-1.5">
                <?php if ($rst === 'requested'): ?>
                  <form action="<?= $base ?>/requisition/decide" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="approved"><button class="text-xs text-green-700 hover:underline">Approve</button></form>
                  <span class="text-gray-200">·</span>
                  <form action="<?= $base ?>/requisition/decide" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="text-xs text-red-600 hover:underline">Reject</button></form>
                <?php elseif ($rst === 'approved' && $r['vendor_id']): ?>
                  <form action="<?= $base ?>/requisition/to-po" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-violet-600 hover:underline">Convert to PO</button></form>
                <?php elseif ($rst === 'converted' && $r['po_id']): ?>
                  <span class="text-xs text-gray-400">PO #<?= (int) $r['po_id'] ?></span>
                <?php else: ?><span class="text-gray-300 text-xs">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <form action="<?= $base ?>/requisition" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <input type="text" name="title" class="input text-sm w-40" placeholder="What to buy *" required>
      <input type="number" name="estimated_cost" step="0.01" min="0" class="input text-sm w-28" placeholder="est. cost">
      <select name="vendor_id" class="input text-sm"><option value="0">(pick vendor later)</option><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars((string) $v['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="justification" class="input text-sm w-40" placeholder="justification">
      <button type="submit" class="btn secondary text-sm">Raise requisition</button>
    </form>
  </div>

  <!-- ============ RFQ ============ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Requests for quotation (RFQ)</h2>
    <?php if (!empty($rfqs)): ?>
      <ul class="text-sm divide-y divide-gray-50 mb-3">
        <?php foreach ($rfqs as $rf): $rfst = (string) $rf['status']; ?>
          <li class="py-1.5 flex items-center justify-between gap-2">
            <span class="text-gray-800"><span class="font-mono text-xs text-gray-500"><?= htmlspecialchars((string) $rf['reference']) ?></span> · <?= htmlspecialchars((string) $rf['item_name']) ?> × <?= $fmt($rf['qty']) ?> <span class="text-gray-400 text-xs">(<?= (int) $rf['quote_count'] ?> quotes)</span></span>
            <span class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $rfqColor[$rfst] ?? '' ?>"><?= htmlspecialchars(ucfirst($rfst)) ?></span>
              <a href="<?= $base ?>?rfq=<?= (int) $rf['id'] ?>" class="text-xs text-violet-600 hover:underline">Compare</a>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form action="<?= $base ?>/rfq" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <select name="item_id" class="input text-sm" required><option value="">Item *</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?></select>
      <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-24" placeholder="qty *" required>
      <input type="date" name="needed_by" class="input text-sm">
      <button type="submit" class="btn secondary text-sm">Open RFQ</button>
    </form>

    <?php if ($compare && !empty($compare['rfq'])): $rfq = $compare['rfq']; $quotes = $compare['quotes'] ?? []; ?>
      <div class="border-t border-gray-100 pt-3 mt-3">
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Quote comparison — <?= htmlspecialchars((string) $rfq['reference']) ?> (<?= htmlspecialchars((string) $rfq['item_name']) ?> × <?= $fmt($rfq['qty']) ?>)</h3>
        <?php if (!empty($quotes)): ?>
          <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Vendor</th><th class="px-2 py-1.5 font-medium text-right">Unit</th><th class="px-2 py-1.5 font-medium text-right">Line total</th><th class="px-2 py-1.5 font-medium text-right">Lead days</th><th class="px-2 py-1.5 font-medium"></th></tr></thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($quotes as $q): ?>
                <tr class="<?= !empty($q['is_lowest']) ? 'bg-green-50/50' : '' ?>">
                  <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $q['vendor_name']) ?><?php if (!empty($q['vendor_verified'])): ?> <span class="text-green-700 text-xs">✓</span><?php endif; ?><?php if (!empty($q['is_lowest'])): ?> <span class="ml-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-green-100 text-green-800">lowest</span><?php endif; ?></td>
                  <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $q['unit_price'], 2) ?></td>
                  <td class="px-2 py-1.5 text-right tabular-nums font-semibold"><?= number_format((float) $q['line_total'], 2) ?></td>
                  <td class="px-2 py-1.5 text-right tabular-nums text-gray-500"><?= (int) $q['lead_days'] ?: '—' ?></td>
                  <td class="px-2 py-1.5 text-right">
                    <?php if ($rfq['status'] === 'open'): ?>
                      <form action="<?= $base ?>/rfq/award" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="rfq_id" value="<?= (int) $rfq['id'] ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><button class="text-xs text-violet-600 hover:underline">Award</button></form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php else: ?>
          <p class="text-sm text-gray-500 mb-3">No quotes yet.</p>
        <?php endif; ?>

        <?php if ($rfq['status'] === 'open'): ?>
          <form action="<?= $base ?>/rfq/quote" method="POST" class="flex flex-wrap items-end gap-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="rfq_id" value="<?= (int) $rfq['id'] ?>">
            <select name="vendor_id" class="input text-sm" required><option value="">Vendor *</option><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars((string) $v['name']) ?></option><?php endforeach; ?></select>
            <input type="number" name="unit_price" step="0.01" min="0" class="input text-sm w-28" placeholder="unit price *" required>
            <input type="number" name="lead_days" min="0" class="input text-sm w-24" placeholder="lead days">
            <button type="submit" class="btn secondary text-sm">Record quote</button>
          </form>
          <form action="<?= $base ?>/rfq/dispatch" method="POST" class="inline mt-2"><?= CSRF::tokenField() ?><input type="hidden" name="rfq_id" value="<?= (int) $rfq['id'] ?>"><button class="text-xs text-gray-400 hover:underline" title="Not connected — records quotes manually">Send to vendors (not connected)</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ PO DELIVERY / RETURN / MATCH ============ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Deliveries, returns &amp; invoice matching</h2>
    <?php if (!empty($openPos) || $poDetail): ?>
      <div class="flex flex-wrap gap-2 mb-3">
        <?php foreach ($openPos as $p): ?>
          <a href="<?= $base ?>?po=<?= (int) $p['id'] ?>" class="px-2.5 py-1 rounded-lg border text-xs <?= ($poDetail && (int) $poDetail['id'] === (int) $p['id']) ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50' ?>"><?= htmlspecialchars((string) $p['reference']) ?> · <?= htmlspecialchars((string) $p['status']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No purchase orders are awaiting delivery. Place a PO in <a href="<?= root ?>supplier/procurement" class="text-violet-600 hover:underline">Procurement</a> first, or award an RFQ above.</p>
    <?php endif; ?>

    <?php if ($poDetail): $lines = $poDetail['lines'] ?? []; $grns = $poDetail['grns'] ?? []; $match = $poDetail['match'] ?? []; $pid = (int) $poDetail['id']; ?>
      <!-- Partial receive -->
      <?php $hasOutstanding = false; foreach ($lines as $l) { if (($l['outstanding'] ?? 0) > 0) { $hasOutstanding = true; break; } } ?>
      <?php if ($hasOutstanding): ?>
        <form action="<?= $base ?>/po/receive-partial" method="POST" class="border border-gray-100 rounded-lg p-3 mb-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>">
          <h3 class="text-xs font-semibold text-gray-700 mb-2">Receive a delivery (quantities clamp to outstanding; the ledger is posted for the value received)</h3>
          <table class="w-full text-sm mb-2">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1 font-medium">Item</th><th class="px-2 py-1 font-medium text-right">Ordered</th><th class="px-2 py-1 font-medium text-right">Received</th><th class="px-2 py-1 font-medium text-right">Outstanding</th><th class="px-2 py-1 font-medium text-right">Receive now</th></tr></thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($lines as $l): ?>
                <tr>
                  <td class="px-2 py-1 text-gray-800"><?= htmlspecialchars((string) $l['name']) ?></td>
                  <td class="px-2 py-1 text-right tabular-nums"><?= $fmt($l['qty']) ?></td>
                  <td class="px-2 py-1 text-right tabular-nums text-gray-500"><?= $fmt($l['received_qty']) ?></td>
                  <td class="px-2 py-1 text-right tabular-nums font-medium"><?= $fmt($l['outstanding']) ?></td>
                  <td class="px-2 py-1 text-right"><?php if (($l['outstanding'] ?? 0) > 0): ?><input type="number" name="deliveries[<?= (int) $l['line_id'] ?>]" step="0.001" min="0" max="<?= (float) $l['outstanding'] ?>" class="input text-sm w-24 text-right" placeholder="0"><?php else: ?><span class="text-gray-300 text-xs">done</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <button type="submit" class="btn secondary text-sm">Receive delivery</button>
        </form>
      <?php endif; ?>

      <!-- Return to vendor -->
      <?php $received = array_filter($lines, fn($l) => ($l['received_qty'] ?? 0) > 0); ?>
      <?php if (!empty($received)): ?>
        <form action="<?= $base ?>/po/return" method="POST" class="flex flex-wrap items-end gap-2 border border-gray-100 rounded-lg p-3 mb-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>">
          <span class="text-xs font-semibold text-gray-700 w-full">Return to vendor (debit note — reverses stock + ledger)</span>
          <select name="line_id" class="input text-sm" required><?php foreach ($received as $l): ?><option value="<?= (int) $l['line_id'] ?>"><?= htmlspecialchars((string) $l['name']) ?> (recd <?= $fmt($l['received_qty']) ?>)</option><?php endforeach; ?></select>
          <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-24" placeholder="qty" required>
          <input type="text" name="reason" class="input text-sm w-40" placeholder="reason">
          <button type="submit" class="btn secondary text-sm">Return</button>
        </form>
      <?php endif; ?>

      <!-- GRN history -->
      <?php if (!empty($grns)): ?>
        <div class="text-xs text-gray-500 mb-3">Deliveries logged: <?php foreach ($grns as $g): ?><span class="inline-block mr-2"><span class="font-mono"><?= htmlspecialchars((string) $g['reference']) ?></span> <?= htmlspecialchars((string) $g['currency']) ?> <?= number_format((float) $g['value'], 2) ?></span><?php endforeach; ?></div>
      <?php endif; ?>

      <!-- Three-way match -->
      <?php if (!empty($match)): ?>
        <div class="border border-gray-100 rounded-lg p-3">
          <h3 class="text-xs font-semibold text-gray-700 mb-2">Three-way match <span class="ml-1 px-2 py-0.5 rounded text-[11px] font-medium <?= !empty($match['matched']) ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?>"><?= !empty($match['matched']) ? 'Matched' : 'Review' ?></span></h3>
          <div class="grid grid-cols-3 gap-3 text-sm mb-2">
            <div><div class="text-gray-500 text-xs">PO</div><div class="font-semibold tabular-nums"><?= number_format((float) $match['po_value'], 2) ?></div></div>
            <div><div class="text-gray-500 text-xs">Received (net)</div><div class="font-semibold tabular-nums"><?= number_format((float) $match['received_value'], 2) ?></div></div>
            <div><div class="text-gray-500 text-xs">Invoiced</div><div class="font-semibold tabular-nums"><?= number_format((float) $match['invoiced_value'], 2) ?></div></div>
          </div>
          <?php if (!empty($match['flags'])): ?>
            <ul class="text-xs text-amber-700 list-disc ml-4 mb-2"><?php foreach ($match['flags'] as $f): ?><li><?= htmlspecialchars((string) $f) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <form action="<?= $base ?>/po/invoice" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>">
            <input type="text" name="invoice_no" class="input text-sm w-32" placeholder="invoice #">
            <input type="number" name="amount" step="0.01" min="0" class="input text-sm w-28" placeholder="invoice amount *" required>
            <input type="date" name="invoice_date" class="input text-sm">
            <button type="submit" class="btn secondary text-sm">Record invoice</button>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ============ CONTRACTS ============ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Vendor contracts</h2>
    <?php if (!empty($contracts)): ?>
      <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Vendor</th><th class="px-2 py-1.5 font-medium">Title</th><th class="px-2 py-1.5 font-medium">Term</th><th class="px-2 py-1.5 font-medium text-right">Cap</th><th class="px-2 py-1.5 font-medium text-right">Spend</th><th class="px-2 py-1.5 font-medium">State</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($contracts as $c): $cf = (string) ($c['flag'] ?? 'ok'); ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $c['vendor_name']) ?></td>
              <td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) $c['title']) ?></td>
              <td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) $c['start_date']) ?><?= !empty($c['end_date']) ? ' → ' . htmlspecialchars((string) $c['end_date']) : '' ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums text-gray-500"><?= (float) $c['spend_cap'] > 0 ? number_format((float) $c['spend_cap'], 2) : '—' ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) ($c['spend_to_date'] ?? 0), 2) ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $flagColor[$cf] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(str_replace('_', ' ', $cf)) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <form action="<?= $base ?>/contract" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <select name="vendor_id" class="input text-sm" required><option value="">Vendor *</option><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars((string) $v['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="title" class="input text-sm w-36" placeholder="Title *" required>
      <input type="date" name="start_date" class="input text-sm" required>
      <input type="date" name="end_date" class="input text-sm">
      <input type="number" name="spend_cap" step="0.01" min="0" class="input text-sm w-28" placeholder="spend cap">
      <button type="submit" class="btn secondary text-sm">Add contract</button>
    </form>
  </div>
</div>
