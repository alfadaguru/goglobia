<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $base = root . 'supplier/ap';
  $ap = (isset($ap) && is_array($ap)) ? $ap : [];
  $ageing   = $ap['ageing']   ?? ['buckets' => [], 'total' => 0, 'rows' => [], 'currency' => 'USD'];
  $invoices = $ap['invoices'] ?? [];
  $vendors  = $ap['vendors']  ?? [];
  $gatewayConnected = !empty($ap['gateway_connected']);
  $statement = (isset($statement) && is_array($statement)) ? $statement : null;
  $cur = htmlspecialchars((string) ($ageing['currency'] ?? 'USD'));
  $vendorNames = []; foreach ($vendors as $vv) { $vendorNames[(int) $vv['id']] = (string) $vv['name']; }
  $bk = $ageing['buckets'] ?? [];
  $statusBadge = [
    'draft'    => 'bg-gray-100 text-gray-600',
    'approved' => 'bg-amber-100 text-amber-700',
    'paid'     => 'bg-green-100 text-green-700',
    'void'     => 'bg-gray-100 text-gray-400 line-through',
  ];
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <div class="flex items-start justify-between gap-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Accounts payable</h1>
      <p class="text-sm text-gray-600">Vendor invoices, approval, ageing, payment scheduling and recorded payments. Every approval and payment posts to your ledger.</p>
    </div>
    <a href="<?= root ?>supplier/procurement" class="text-sm text-gray-500 hover:text-gray-800 whitespace-nowrap inline-flex items-center gap-1"><span class="material-symbols-outlined text-base">inventory_2</span>Procurement</a>
  </div>

  <?php if (isset($_SESSION['message'])): $m = $_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="rounded-lg px-4 py-2.5 text-sm <?= ($m['type'] ?? '') === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700' ?>"><?= htmlspecialchars((string) ($m['text'] ?? '')) ?></div>
  <?php endif; ?>

  <!-- AP AGEING -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">AP ageing</h2>
      <span class="text-sm font-semibold text-gray-900 tabular-nums"><?= $cur ?> <?= number_format((float) ($ageing['total'] ?? 0), 2) ?> outstanding</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
      <?php
        $buckets = [
          'current'  => ['Current', 'text-gray-900'],
          'd1_30'    => ['1–30 days', 'text-amber-700'],
          'd31_60'   => ['31–60 days', 'text-amber-700'],
          'd61_90'   => ['61–90 days', 'text-orange-700'],
          'd90_plus' => ['90+ days', 'text-red-600'],
        ];
        foreach ($buckets as $k => $meta): ?>
        <div class="rounded-lg border border-gray-100 p-3">
          <div class="text-xs text-gray-500"><?= htmlspecialchars($meta[0]) ?></div>
          <div class="text-lg font-semibold tabular-nums <?= $meta[1] ?>"><?= number_format((float) ($bk[$k] ?? 0), 2) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($ageing['rows'])): ?>
      <div class="overflow-x-auto mt-4"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-1.5 font-medium">Invoice</th><th class="px-2 py-1.5 font-medium">Vendor</th>
          <th class="px-2 py-1.5 font-medium">Due</th><th class="px-2 py-1.5 font-medium text-right">Days over</th>
          <th class="px-2 py-1.5 font-medium text-right">Outstanding</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($ageing['rows'] as $r): ?>
          <tr>
            <td class="px-2 py-1.5 font-mono text-xs text-gray-700"><?= htmlspecialchars((string) $r['reference']) ?></td>
            <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $r['vendor']) ?></td>
            <td class="px-2 py-1.5 text-gray-500"><?= htmlspecialchars((string) $r['due_date']) ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums <?= (int) $r['days_over'] > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= (int) $r['days_over'] ?: '—' ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums"><?= htmlspecialchars((string) $r['currency']) ?> <?= number_format((float) $r['outstanding'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <p class="text-sm text-gray-500 mt-3">No outstanding approved invoices.</p>
    <?php endif; ?>
  </div>

  <!-- RECORD A NEW INVOICE -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Record a vendor invoice</h2>
    <?php if (empty($vendors)): ?>
      <p class="text-sm text-gray-500">Add a vendor in <a href="<?= root ?>supplier/procurement" class="text-blue-600 hover:underline">Procurement</a> first.</p>
    <?php else: ?>
    <form action="<?= $base ?>/invoice" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <select name="vendor_id" class="input text-sm" required>
        <option value="">Vendor *</option>
        <?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars((string) $v['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="number" name="amount" step="0.01" min="0.01" class="input text-sm w-28" placeholder="Amount *" required>
      <input type="text" name="currency" maxlength="3" value="USD" class="input text-sm w-16">
      <label class="text-xs text-gray-500">Issued<input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" class="input text-sm block"></label>
      <label class="text-xs text-gray-500">Due<input type="date" name="due_date" class="input text-sm block"></label>
      <input type="text" name="vendor_ref" class="input text-sm w-32" placeholder="Vendor ref #">
      <input type="text" name="memo" class="input text-sm w-40" placeholder="Memo">
      <button type="submit" class="btn secondary text-sm">Save draft</button>
    </form>
    <p class="text-xs text-gray-400 mt-1.5">A draft posts nothing. Approving it books DR Operating&nbsp;Expense / CR Accounts&nbsp;Payable. Payments book DR&nbsp;AP / CR&nbsp;Cash.</p>
    <?php endif; ?>
  </div>

  <!-- INVOICES -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Invoices</h2>
    <?php if (empty($invoices)): ?>
      <p class="text-sm text-gray-500">No vendor invoices yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-1.5 font-medium">Invoice</th><th class="px-2 py-1.5 font-medium">Vendor</th>
          <th class="px-2 py-1.5 font-medium">Due</th><th class="px-2 py-1.5 font-medium text-right">Amount</th>
          <th class="px-2 py-1.5 font-medium text-right">Outstanding</th><th class="px-2 py-1.5 font-medium">Status</th>
          <th class="px-2 py-1.5 font-medium">Actions</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($invoices as $inv): $st = (string) $inv['status']; $vn = $vendorNames[(int) $inv['vendor_id']] ?? ('#' . (int) $inv['vendor_id']); ?>
          <tr class="align-top">
            <td class="px-2 py-2">
              <div class="font-mono text-xs text-gray-700"><?= htmlspecialchars((string) $inv['reference']) ?></div>
              <?php if (!empty($inv['vendor_ref'])): ?><div class="text-xs text-gray-400">ref <?= htmlspecialchars((string) $inv['vendor_ref']) ?></div><?php endif; ?>
              <?php if (!empty($inv['memo'])): ?><div class="text-xs text-gray-400"><?= htmlspecialchars((string) $inv['memo']) ?></div><?php endif; ?>
            </td>
            <td class="px-2 py-2 text-gray-800"><a href="<?= $base ?>?statement=<?= (int) $inv['vendor_id'] ?>" class="hover:underline"><?= htmlspecialchars($vn) ?></a></td>
            <td class="px-2 py-2 text-gray-500">
              <?= htmlspecialchars((string) $inv['due_date']) ?>
              <?php if (!empty($inv['scheduled_pay_date'])): ?><div class="text-xs text-blue-600">pay <?= htmlspecialchars((string) $inv['scheduled_pay_date']) ?></div><?php endif; ?>
            </td>
            <td class="px-2 py-2 text-right tabular-nums"><?= htmlspecialchars((string) $inv['currency']) ?> <?= number_format((float) $inv['amount'], 2) ?></td>
            <td class="px-2 py-2 text-right tabular-nums <?= (float) $inv['outstanding'] > 0 ? 'text-gray-900' : 'text-gray-400' ?>"><?= number_format((float) $inv['outstanding'], 2) ?></td>
            <td class="px-2 py-2"><span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium <?= $statusBadge[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($st) ?></span></td>
            <td class="px-2 py-2">
              <div class="flex flex-col gap-1.5">
                <?php if ($st === 'draft'): ?>
                  <form action="<?= $base ?>/invoice/approve" method="POST" onsubmit="return confirm('Approve and post this invoice to the ledger?');">
                    <?= CSRF::tokenField() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                    <button type="submit" class="btn secondary text-xs">Approve &amp; post</button>
                  </form>
                  <form action="<?= $base ?>/invoice/void" method="POST" onsubmit="return confirm('Void this draft invoice?');">
                    <?= CSRF::tokenField() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                    <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Void</button>
                  </form>
                <?php elseif ($st === 'approved'): ?>
                  <form action="<?= $base ?>/invoice/pay" method="POST" class="flex items-end gap-1 flex-wrap">
                    <?= CSRF::tokenField() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                    <input type="number" name="amount" step="0.01" min="0.01" max="<?= htmlspecialchars((string) $inv['outstanding']) ?>" value="<?= htmlspecialchars((string) $inv['outstanding']) ?>" class="input text-xs w-20" required>
                    <select name="method" class="input text-xs w-20"><option value="manual">Manual</option><option value="bank">Bank</option><option value="cash">Cash</option></select>
                    <button type="submit" class="btn secondary text-xs">Pay</button>
                  </form>
                  <form action="<?= $base ?>/invoice/schedule" method="POST" class="flex items-end gap-1">
                    <?= CSRF::tokenField() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                    <input type="date" name="scheduled_pay_date" value="<?= htmlspecialchars((string) ($inv['scheduled_pay_date'] ?? '')) ?>" class="input text-xs w-32">
                    <button type="submit" class="text-xs text-gray-500 hover:text-gray-800">Schedule</button>
                  </form>
                  <form action="<?= $base ?>/invoice/disburse" method="POST">
                    <?= CSRF::tokenField() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>"><input type="hidden" name="amount" value="<?= htmlspecialchars((string) $inv['outstanding']) ?>">
                    <button type="submit" class="text-xs text-gray-300 hover:text-gray-500" title="Bank disbursement is not connected">Bank transfer<?= $gatewayConnected ? '' : ' (not connected)' ?></button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-gray-300">—</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <!-- VENDOR STATEMENT -->
  <?php if ($statement && !empty($statement['vendor'])): ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Statement — <?= htmlspecialchars((string) $statement['vendor']['name']) ?></h2>
      <span class="text-sm font-semibold text-gray-900 tabular-nums"><?= htmlspecialchars((string) $statement['currency']) ?> <?= number_format((float) $statement['closing'], 2) ?> balance</span>
    </div>
    <?php if (empty($statement['lines'])): ?>
      <p class="text-sm text-gray-500">No posted invoices or payments for this vendor.</p>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-1.5 font-medium">Date</th><th class="px-2 py-1.5 font-medium">Type</th>
          <th class="px-2 py-1.5 font-medium">Reference</th><th class="px-2 py-1.5 font-medium text-right">Invoiced</th>
          <th class="px-2 py-1.5 font-medium text-right">Paid</th><th class="px-2 py-1.5 font-medium text-right">Balance</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($statement['lines'] as $ln): ?>
          <tr>
            <td class="px-2 py-1.5 text-gray-500"><?= htmlspecialchars((string) $ln['date']) ?></td>
            <td class="px-2 py-1.5 text-gray-700"><?= htmlspecialchars((string) $ln['type']) ?></td>
            <td class="px-2 py-1.5 font-mono text-xs text-gray-600"><?= htmlspecialchars((string) $ln['reference']) ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums"><?= (float) $ln['debit'] > 0 ? number_format((float) $ln['debit'], 2) : '—' ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums text-green-700"><?= (float) $ln['credit'] > 0 ? number_format((float) $ln['credit'], 2) : '—' ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums font-medium"><?= number_format((float) $ln['balance'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="text-xs text-gray-400 mt-1">Running balance: invoices increase what you owe, payments reduce it.</p>
    <?php endif; ?>
    <a href="<?= $base ?>" class="text-xs text-gray-500 hover:text-gray-800 mt-2 inline-block">Close statement</a>
  </div>
  <?php endif; ?>

  <p class="text-xs text-gray-400">Accounts payable is whole-business money and is visible to the account owner only. Invoice approvals and payments post balanced entries to the general ledger (accounts 6000 / 2000 / 1000).</p>
</div>
