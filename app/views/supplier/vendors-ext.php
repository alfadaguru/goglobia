<?php @$SECURE or die('Access Denied!'); ?>
<?php
// MODULE 43 Vendors & contractors. Provided by supplierVendorsExtRoutes.php:
//   $vendors, $focus(int), $focusVendor|null, $contracts, $prices, $jobs, $invoices,
//   $docs, $reminders['docs'|'contracts'], $scorecard|null, $stockItems, $vendorNames,
//   $disb['connected'|'message'], $canEdit(bool).
$vendors     = (isset($vendors) && is_array($vendors)) ? $vendors : [];
$contracts   = (isset($contracts) && is_array($contracts)) ? $contracts : [];
$prices      = (isset($prices) && is_array($prices)) ? $prices : [];
$jobs        = (isset($jobs) && is_array($jobs)) ? $jobs : [];
$invoices    = (isset($invoices) && is_array($invoices)) ? $invoices : [];
$docs        = (isset($docs) && is_array($docs)) ? $docs : [];
$reminders   = (isset($reminders) && is_array($reminders)) ? $reminders : ['docs' => [], 'contracts' => []];
$stockItems  = (isset($stockItems) && is_array($stockItems)) ? $stockItems : [];
$vendorNames = (isset($vendorNames) && is_array($vendorNames)) ? $vendorNames : [];
$focus       = (int) ($focus ?? 0);
$focusVendor = $focusVendor ?? null;
$scorecard   = $scorecard ?? null;
$canEdit     = !empty($canEdit);
$disb        = (isset($disb) && is_array($disb)) ? $disb : ['connected' => false, 'message' => ''];
$base = root . 'supplier/vendors';
$cats = function_exists('vendor_categories') ? vendor_categories() : [];
$cStat = function_exists('vendor_contract_statuses') ? vendor_contract_statuses() : [];
$jStat = function_exists('vendor_job_statuses') ? vendor_job_statuses() : [];
$dTypes = function_exists('vendor_doc_types') ? vendor_doc_types() : [];
$metrics = function_exists('vendor_score_metrics') ? vendor_score_metrics() : [];
$stColor = ['draft'=>'bg-gray-100 text-gray-600','active'=>'bg-green-50 text-green-700','expired'=>'bg-amber-50 text-amber-700','terminated'=>'bg-red-50 text-red-700','assigned'=>'bg-blue-50 text-blue-700','in_progress'=>'bg-indigo-50 text-indigo-700','completed'=>'bg-green-50 text-green-700','cancelled'=>'bg-red-50 text-red-700','unpaid'=>'bg-amber-50 text-amber-700','paid'=>'bg-green-50 text-green-700'];
$today = date('Y-m-d');
$fmtDate = fn($d) => $d ? htmlspecialchars((string) $d) : '—';
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Vendors &amp; contractors</h1>
    <p class="text-sm text-gray-600">Categories, contracts, price agreements, job assignment, performance scoring, compliance documents and vendor invoices. Add new vendors on the <a href="<?= root ?>supplier/procurement" class="text-violet-600 hover:underline">procurement</a> page.</p>
  </div>

  <!-- Compliance reminders -->
  <?php if (!empty($reminders['docs']) || !empty($reminders['contracts'])): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">warning</span>
      <div class="text-sm">
        <?php if (!empty($reminders['docs'])): ?>
          <p><strong><?= count($reminders['docs']) ?></strong> compliance document(s) expiring/expired:
            <?= htmlspecialchars(implode(', ', array_map(fn($d) => ($d['vendor_name'] ?? '') . ' — ' . ($dTypes[$d['doc_type']] ?? $d['doc_type']) . ' (' . ($d['expiry_date'] ?? '') . ')', array_slice($reminders['docs'], 0, 8)))) ?>
          </p>
        <?php endif; ?>
        <?php if (!empty($reminders['contracts'])): ?>
          <p><strong><?= count($reminders['contracts']) ?></strong> contract(s) ending soon:
            <?= htmlspecialchars(implode(', ', array_map(fn($c) => ($c['vendor_name'] ?? '') . ' — ' . ($c['title'] ?? '') . ' (' . ($c['end_date'] ?? '') . ')', array_slice($reminders['contracts'], 0, 8)))) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Vendor picker -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Vendors</h2>
    <?php if (empty($vendors)): ?>
      <p class="text-sm text-gray-500">No vendors yet. Add one on the <a href="<?= root ?>supplier/procurement" class="text-violet-600 hover:underline">procurement</a> page.</p>
    <?php else: ?>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($vendors as $v): $vid = (int) $v['id']; $on = $vid === $focus; ?>
          <a href="<?= $base ?>?vendor=<?= $vid ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $on ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <?= htmlspecialchars((string) $v['name']) ?>
            <?php if (!empty($v['verified'])): ?><span class="material-symbols-outlined align-middle text-[14px]">verified</span><?php endif; ?>
            <?php if (!empty($v['category']) && isset($cats[$v['category']])): ?><span class="ml-1 text-[11px] opacity-70"><?= htmlspecialchars($cats[$v['category']]) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if ($focus > 0): ?><a href="<?= $base ?>" class="px-3 py-1.5 rounded-lg text-sm text-gray-500 hover:underline">Clear</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($focusVendor !== null): $vid = $focus; ?>
    <div class="flex items-baseline justify-between">
      <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars((string) $focusVendor['name']) ?></h2>
      <?php if ($scorecard && $scorecard['overall'] !== null): ?>
        <span class="text-sm text-gray-600">Overall score <strong class="tabular-nums"><?= number_format((float) $scorecard['overall'], 2) ?></strong>/5 · <?= (int) $scorecard['count'] ?> review(s)</span>
      <?php endif; ?>
    </div>

    <!-- Category -->
    <?php if ($canEdit): ?>
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Category</h3>
      <form action="<?= $base ?>/category" method="POST" class="flex flex-wrap items-end gap-2">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="vendor_id" value="<?= $vid ?>">
        <select name="category" class="input text-sm">
          <?php foreach ($cats as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>" <?= ($focusVendor['category'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn secondary text-sm">Set category</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Contracts -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Contracts</h3>
        <?php if (!empty($contracts)): ?>
          <ul class="text-sm divide-y divide-gray-50 mb-3">
            <?php foreach ($contracts as $c): ?>
              <li class="py-2">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-gray-800"><?= htmlspecialchars((string) $c['title']) ?></span>
                  <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$c['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($cStat[$c['status']] ?? $c['status']) ?></span>
                </div>
                <div class="text-xs text-gray-400 mt-0.5">
                  <?php if ((float) $c['value'] > 0): ?><?= htmlspecialchars((string) $c['currency']) ?> <?= number_format((float) $c['value'], 2) ?> · <?php endif; ?>
                  <?= $fmtDate($c['start_date']) ?> → <?= $fmtDate($c['end_date']) ?>
                  <?php if (!empty($c['auto_renew'])): ?> · auto-renew<?php endif; ?>
                  <?php if (!empty($c['end_date']) && $c['status'] === 'active' && $c['end_date'] < $today): ?><span class="text-red-600"> · expired</span><?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                  <form action="<?= $base ?>/contract/status" method="POST" class="mt-1 flex items-center gap-1">
                    <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>"><input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
                    <select name="status" class="input text-xs py-0.5"><?php foreach ($cStat as $k => $lbl): ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="text-xs text-violet-600 hover:underline">Update</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="text-sm text-gray-500 mb-3">No contracts.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/contract" method="POST" class="grid grid-cols-2 gap-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
          <input type="text" name="title" class="input text-sm col-span-2" placeholder="Contract title *" required>
          <input type="number" name="value" step="0.01" min="0" class="input text-sm" placeholder="Value">
          <input type="text" name="currency" maxlength="3" value="USD" class="input text-sm">
          <input type="date" name="start_date" class="input text-sm" title="Start">
          <input type="date" name="end_date" class="input text-sm" title="End">
          <label class="col-span-2 flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="auto_renew" value="1"> Auto-renew</label>
          <button type="submit" class="btn secondary text-sm col-span-2">Add contract</button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Price agreements -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Price agreements</h3>
        <?php if (!empty($prices)): ?>
          <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Item</th><th class="px-2 py-1.5 font-medium text-right">Unit price</th><th class="px-2 py-1.5 font-medium">Valid</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($prices as $p): ?><tr>
                <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $p['item_label']) ?> <span class="text-gray-400 text-xs">/<?= htmlspecialchars((string) $p['unit']) ?></span></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= htmlspecialchars((string) $p['currency']) ?> <?= number_format((float) $p['unit_price'], 2) ?></td>
                <td class="px-2 py-1.5 text-xs text-gray-400"><?= $fmtDate($p['valid_from']) ?>→<?= $fmtDate($p['valid_to']) ?></td>
                <?php if ($canEdit): ?><td class="px-2 py-1.5 text-right"><form action="<?= $base ?>/price/remove" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>"><input type="hidden" name="price_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="text-xs text-red-600 hover:underline">Retire</button></form></td><?php endif; ?>
              </tr><?php endforeach; ?>
            </tbody>
          </table></div>
        <?php else: ?><p class="text-sm text-gray-500 mb-3">No price agreements.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/price" method="POST" class="grid grid-cols-2 gap-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
          <select name="item_id" class="input text-sm col-span-2">
            <option value="0">— free-text item —</option>
            <?php foreach ($stockItems as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="item_label" class="input text-sm col-span-2" placeholder="Item label (if free-text)">
          <input type="number" name="unit_price" step="0.01" min="0" class="input text-sm" placeholder="Unit price *" required>
          <input type="text" name="unit" class="input text-sm" placeholder="unit" value="unit">
          <input type="date" name="valid_from" class="input text-sm" title="Valid from">
          <input type="date" name="valid_to" class="input text-sm" title="Valid to">
          <button type="submit" class="btn secondary text-sm col-span-2">Add price agreement</button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Jobs -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Job assignments</h3>
        <?php if (!empty($jobs)): ?>
          <ul class="text-sm divide-y divide-gray-50 mb-3">
            <?php foreach ($jobs as $j): ?>
              <li class="py-2">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-gray-800"><?= htmlspecialchars((string) $j['title']) ?></span>
                  <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$j['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($jStat[$j['status']] ?? $j['status']) ?></span>
                </div>
                <div class="text-xs text-gray-400 mt-0.5">
                  <?php if ((float) $j['est_cost'] > 0): ?>est <?= htmlspecialchars((string) $j['currency']) ?> <?= number_format((float) $j['est_cost'], 2) ?> · <?php endif; ?>
                  <?php if (!empty($j['due_date'])): ?>due <?= $fmtDate($j['due_date']) ?><?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                  <form action="<?= $base ?>/job/status" method="POST" class="mt-1 flex items-center gap-1">
                    <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>"><input type="hidden" name="job_id" value="<?= (int) $j['id'] ?>">
                    <select name="status" class="input text-xs py-0.5"><?php foreach ($jStat as $k => $lbl): ?><option value="<?= $k ?>" <?= $j['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="text-xs text-violet-600 hover:underline">Update</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="text-sm text-gray-500 mb-3">No jobs assigned.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/job" method="POST" class="grid grid-cols-2 gap-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
          <input type="text" name="title" class="input text-sm col-span-2" placeholder="Job title *" required>
          <input type="text" name="description" class="input text-sm col-span-2" placeholder="Description">
          <input type="number" name="est_cost" step="0.01" min="0" class="input text-sm" placeholder="Est. cost">
          <input type="date" name="due_date" class="input text-sm" title="Due">
          <button type="submit" class="btn secondary text-sm col-span-2">Assign job</button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Performance -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Performance</h3>
        <?php if ($scorecard && $scorecard['count'] > 0): ?>
          <ul class="text-sm space-y-1 mb-3">
            <?php foreach ($metrics as $k => $lbl): $avg = $scorecard['metrics'][$k] ?? null; ?>
              <li class="flex items-center justify-between"><span class="text-gray-600"><?= htmlspecialchars($lbl) ?></span><span class="tabular-nums text-gray-900"><?= $avg !== null ? number_format((float) $avg, 2) . '/5' : '—' ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="text-sm text-gray-500 mb-3">No reviews yet.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/score" method="POST" class="space-y-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
          <?php foreach ($metrics as $k => $lbl): ?>
            <div class="flex items-center justify-between gap-2">
              <label class="text-xs text-gray-600"><?= htmlspecialchars($lbl) ?></label>
              <select name="m_<?= htmlspecialchars($k) ?>" class="input text-xs py-0.5 w-20"><option value="0">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select>
            </div>
          <?php endforeach; ?>
          <input type="text" name="comment" class="input text-sm w-full" placeholder="Comment (optional)">
          <button type="submit" class="btn secondary text-sm w-full">Save review</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Compliance documents -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Compliance documents</h3>
      <?php if (!empty($docs)): ?>
        <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Type</th><th class="px-2 py-1.5 font-medium">Title</th><th class="px-2 py-1.5 font-medium">Reference</th><th class="px-2 py-1.5 font-medium">Expiry</th><th class="px-2 py-1.5 font-medium">File</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($docs as $d): $exp = (string) ($d['expiry_date'] ?? ''); $soon = $exp !== '' && $exp <= date('Y-m-d', strtotime('+60 days')); ?>
              <tr>
                <td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars($dTypes[$d['doc_type']] ?? $d['doc_type']) ?></td>
                <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $d['title']) ?></td>
                <td class="px-2 py-1.5 text-gray-400 text-xs"><?= htmlspecialchars((string) ($d['reference'] ?? '—')) ?></td>
                <td class="px-2 py-1.5 <?= $soon ? 'text-red-600 font-medium' : 'text-gray-500' ?>"><?= $fmtDate($exp) ?></td>
                <td class="px-2 py-1.5"><?php if (!empty($d['file_path'])): ?><a href="<?= root . htmlspecialchars((string) $d['file_path']) ?>" target="_blank" class="text-xs text-violet-600 hover:underline">view</a><?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><p class="text-sm text-gray-500 mb-3">No compliance documents.</p><?php endif; ?>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/doc" method="POST" enctype="multipart/form-data" class="grid grid-cols-2 md:grid-cols-4 gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
        <select name="doc_type" class="input text-sm"><?php foreach ($dTypes as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?></select>
        <input type="text" name="title" class="input text-sm" placeholder="Title">
        <input type="text" name="reference" class="input text-sm" placeholder="Reference #">
        <input type="date" name="expiry_date" class="input text-sm" title="Expiry">
        <input type="file" name="doc_file" accept="image/png,image/jpeg" class="input text-sm col-span-2 md:col-span-3">
        <button type="submit" class="btn secondary text-sm">Record</button>
        <p class="col-span-2 md:col-span-4 text-xs text-gray-400">File upload accepts images only (png/jpg, max 5MB); the reminder watches the expiry date. PDF storage is not wired yet — record the reference instead.</p>
      </form>
      <?php endif; ?>
    </div>

    <!-- Vendor invoices -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Invoices</h3>
      <?php if (!$disb['connected']): ?>
        <p class="text-xs text-gray-500 mb-3"><span class="material-symbols-outlined align-middle text-[14px]">info</span> <?= htmlspecialchars((string) $disb['message']) ?></p>
      <?php endif; ?>
      <?php if (!empty($invoices)): ?>
        <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Invoice #</th><th class="px-2 py-1.5 font-medium text-right">Amount</th><th class="px-2 py-1.5 font-medium">Due</th><th class="px-2 py-1.5 font-medium">Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($invoices as $iv): $st = (string) $iv['status']; ?>
              <tr>
                <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) ($iv['invoice_no'] ?? ('#' . $iv['id']))) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= htmlspecialchars((string) $iv['currency']) ?> <?= number_format((float) $iv['amount'], 2) ?></td>
                <td class="px-2 py-1.5 text-xs text-gray-400"><?= $fmtDate($iv['due_date']) ?></td>
                <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
                <?php if ($canEdit): ?><td class="px-2 py-1.5 text-right">
                  <?php if ($st === 'unpaid'): ?>
                    <form action="<?= $base ?>/invoice/pay" method="POST" class="inline" onsubmit="return confirm('Record this invoice as paid and post the settlement to the ledger? The bank transfer is manual.');">
                      <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>"><input type="hidden" name="invoice_id" value="<?= (int) $iv['id'] ?>"><input type="hidden" name="pay_method" value="bank">
                      <button type="submit" class="text-xs text-violet-600 hover:underline">Mark paid</button>
                    </form>
                  <?php else: ?><span class="text-xs text-gray-300">paid</span><?php endif; ?>
                </td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><p class="text-sm text-gray-500 mb-3">No invoices.</p><?php endif; ?>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/invoice" method="POST" class="grid grid-cols-2 md:grid-cols-4 gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="vendor_id" value="<?= $vid ?>">
        <input type="text" name="invoice_no" class="input text-sm" placeholder="Invoice #">
        <input type="number" name="amount" step="0.01" min="0.01" class="input text-sm" placeholder="Amount *" required>
        <input type="text" name="currency" maxlength="3" value="USD" class="input text-sm">
        <input type="date" name="due_date" class="input text-sm" title="Due">
        <button type="submit" class="btn secondary text-sm">Record invoice</button>
        <p class="col-span-2 md:col-span-4 text-xs text-gray-400">Recording an invoice tracks a payable. Marking it paid posts a balanced ledger entry (DR Accounts Payable / CR Cash). The actual bank transfer is done in your bank.</p>
      </form>
      <?php endif; ?>
    </div>

  <?php elseif (!empty($vendors)): ?>
    <div class="card p-5"><p class="text-sm text-gray-500">Select a vendor above to manage its contracts, price agreements, jobs, performance, compliance documents and invoices.</p></div>
  <?php endif; ?>
</div>
