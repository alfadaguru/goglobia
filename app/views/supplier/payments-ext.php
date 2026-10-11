<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Payments & deposits (PMS #39 ext). Data from supplierPaymentsExtRoutes.php:
// $summary, $chargebacks, $propMap, $invoice, $booking, $schedule, $intents, $trail,
// $bkChargebacks, $gatewayConnected, $canEdit.
$summary        = (isset($summary) && is_array($summary)) ? $summary : [];
$chargebacks    = (isset($chargebacks) && is_array($chargebacks)) ? $chargebacks : [];
$propMap        = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$invoice        = (string) ($invoice ?? '');
$booking        = (isset($booking) && is_array($booking)) ? $booking : null;
$schedule       = (isset($schedule) && is_array($schedule)) ? $schedule : [];
$intents        = (isset($intents) && is_array($intents)) ? $intents : [];
$trail          = (isset($trail) && is_array($trail)) ? $trail : [];
$bkChargebacks  = (isset($bkChargebacks) && is_array($bkChargebacks)) ? $bkChargebacks : [];
$gatewayConnected = !empty($gatewayConnected);
$canEdit        = !empty($canEdit);
$base           = root . 'supplier/payments';
$fmt = fn($n) => htmlspecialchars(number_format((float) $n, 2));
$stColor = ['pending'=>'bg-amber-50 text-amber-700','collected'=>'bg-green-50 text-green-700','cancelled'=>'bg-gray-100 text-gray-500'];
$cbColor = ['open'=>'bg-red-50 text-red-700','resolved'=>'bg-gray-100 text-gray-600'];
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

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Payments &amp; deposits</h1>
      <p class="text-sm text-gray-600">Staged deposit schedules, security-deposit holds, chargeback log and payment audit trail. Posts to the property ledger. <a href="<?= root ?>supplier/finance" class="text-violet-600 hover:underline">Finance overview &rarr;</a></p>
    </div>
  </div>

  <!-- KPIs -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
    <?php
      $kpis = [
        ['Scheduled pending', $fmt($summary['sched_pending'] ?? 0), 'bg-amber-50 text-amber-700'],
        ['Deposits collected', $fmt($summary['sched_collected'] ?? 0), 'bg-green-50 text-green-700'],
        ['Open chargebacks', (int) ($summary['cb_open'] ?? 0), 'bg-red-50 text-red-700'],
        ['Disputed amount', $fmt($summary['cb_amount'] ?? 0), 'bg-rose-50 text-rose-700'],
        ['Open requests', (int) ($summary['intents_open'] ?? 0), 'bg-indigo-50 text-indigo-700'],
      ];
      foreach ($kpis as $k): ?>
      <div class="card p-4">
        <div class="text-xs text-gray-500"><?= htmlspecialchars($k[0]) ?></div>
        <div class="mt-1 inline-flex px-2 py-0.5 rounded text-lg font-bold <?= $k[2] ?>"><?= htmlspecialchars((string) $k[1]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Gateway reality notice -->
  <div class="card p-4 flex items-start gap-3 bg-amber-50 border border-amber-100">
    <span class="material-symbols-outlined text-amber-600">info</span>
    <div class="text-sm text-amber-800">
      <strong>Desk-collection mode.</strong> Deposit stages and chargebacks are recorded here and posted to the property ledger as money handled at the property. Supplier-initiated card charges, pre-authorization holds and sending payment links through a gateway are <strong>not connected</strong> in this build &mdash; those actions store a record only (no card is charged or held).
    </div>
  </div>

  <!-- Booking picker -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Open a booking's payment workspace</h2>
    <form method="GET" action="<?= $base ?>" class="flex flex-wrap items-end gap-2">
      <div class="flex-1 min-w-[220px]">
        <label class="block text-xs text-gray-600 mb-1">Booking invoice ID</label>
        <input type="text" name="invoice" value="<?= htmlspecialchars($invoice) ?>" class="input text-sm w-full" placeholder="e.g. INV-xxxxxxxx" required>
      </div>
      <button type="submit" class="btn secondary text-sm">Open</button>
    </form>
  </div>

  <?php if ($booking): ?>
    <?php $cur = htmlspecialchars((string) ($booking['currency'] ?? 'USD')); ?>
    <div class="card p-5">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-base font-semibold text-gray-900">Booking <?= htmlspecialchars((string) $booking['invoice_id']) ?></h2>
          <p class="text-xs text-gray-500">
            <?= htmlspecialchars($propMap[(int) $booking['stay_id']] ?? ('Property #' . (int) $booking['stay_id'])) ?>
            &middot; Total <?= $cur ?> <?= $fmt($booking['total'] ?? 0) ?>
            &middot; <?= !empty($booking['paid']) ? '<span class="text-green-700">Paid</span>' : '<span class="text-amber-700">Balance due</span>' ?>
          </p>
        </div>
      </div>
    </div>

    <!-- DEPOSIT SCHEDULE -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Deposit schedule</h3>
      <?php if (!empty($schedule)): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 border-b">
              <th class="py-2 pr-3">#</th><th class="py-2 pr-3">Stage</th><th class="py-2 pr-3">Amount</th>
              <th class="py-2 pr-3">Due</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Method</th><th class="py-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($schedule as $s): ?>
              <tr class="border-b last:border-0">
                <td class="py-2 pr-3 text-gray-500"><?= (int) $s['seq'] ?></td>
                <td class="py-2 pr-3"><?= htmlspecialchars((string) ($s['label'] ?? '')) ?></td>
                <td class="py-2 pr-3 font-medium"><?= htmlspecialchars((string) ($s['currency'] ?? $cur)) ?> <?= $fmt($s['amount']) ?></td>
                <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($s['due_date'] ?? '—')) ?></td>
                <td class="py-2 pr-3"><span class="inline-flex px-2 py-0.5 rounded text-xs <?= $stColor[$s['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars((string) $s['status']) ?></span></td>
                <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($s['method'] ?? '—')) ?></td>
                <td class="py-2 text-right">
                  <?php if ($canEdit && $s['status'] === 'pending'): ?>
                    <form action="<?= $base ?>/schedule/collect" method="POST" class="inline-flex items-center gap-1" style="display:inline-flex">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
                      <input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
                      <select name="method" class="input text-xs w-24"><option value="cash">Cash</option><option value="card">Card</option><option value="bank">Bank</option><option value="wallet">Wallet</option><option value="other">Other</option></select>
                      <button type="submit" class="btn secondary text-xs">Collect</button>
                    </form>
                    <form action="<?= $base ?>/schedule/cancel" method="POST" class="inline" style="display:inline" onsubmit="return confirm('Cancel this stage?')">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
                      <input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
                      <button type="submit" class="text-xs text-red-600 hover:underline ml-1">Cancel</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500 mb-3">No schedule yet. Add stages below (amounts must not exceed the booking total).</p>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/schedule" method="POST" class="mt-4 space-y-2" style="display:block">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
        <p class="text-xs text-gray-500">Set / replace pending stages. Collected stages are kept.</p>
        <div id="spayx-stages" class="space-y-2">
          <?php for ($i = 0; $i < 3; $i++): ?>
          <div class="flex flex-wrap gap-2">
            <input type="text" name="label[]" class="input text-sm w-40" placeholder="Stage label (e.g. Deposit)">
            <input type="number" step="0.01" min="0" name="amount[]" class="input text-sm w-32" placeholder="Amount">
            <input type="date" name="due_date[]" class="input text-sm">
          </div>
          <?php endfor; ?>
        </div>
        <button type="submit" class="btn secondary text-sm">Save schedule</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- CHARGEBACKS (this booking) -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Chargebacks &amp; disputes</h3>
      <?php if (!empty($bkChargebacks)): ?>
        <div class="overflow-x-auto mb-3">
          <table class="min-w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 border-b">
              <th class="py-2 pr-3">Amount</th><th class="py-2 pr-3">Reason</th><th class="py-2 pr-3">Gateway ref</th>
              <th class="py-2 pr-3">Status</th><th class="py-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($bkChargebacks as $cb): ?>
              <tr class="border-b last:border-0">
                <td class="py-2 pr-3 font-medium"><?= htmlspecialchars((string) ($cb['currency'] ?? $cur)) ?> <?= $fmt($cb['amount']) ?></td>
                <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($cb['reason'] ?? '—')) ?></td>
                <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars((string) ($cb['gateway_ref'] ?? '—')) ?></td>
                <td class="py-2 pr-3">
                  <span class="inline-flex px-2 py-0.5 rounded text-xs <?= $cbColor[$cb['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars((string) $cb['status']) ?><?= !empty($cb['resolution']) ? ' · ' . htmlspecialchars((string) $cb['resolution']) : '' ?></span>
                </td>
                <td class="py-2 text-right">
                  <?php if ($canEdit && $cb['status'] === 'open'): ?>
                    <form action="<?= $base ?>/chargeback/resolve" method="POST" class="inline-flex items-center gap-1" style="display:inline-flex">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
                      <input type="hidden" name="chargeback_id" value="<?= (int) $cb['id'] ?>">
                      <select name="resolution" class="input text-xs w-28"><option value="won">Won (reverse)</option><option value="lost">Lost</option><option value="accepted">Accepted</option></select>
                      <button type="submit" class="btn secondary text-xs">Resolve</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500 mb-3">No chargebacks logged for this booking.</p>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/chargeback" method="POST" class="flex flex-wrap items-end gap-2" style="display:flex">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
        <div><label class="block text-xs text-gray-600 mb-1">Amount *</label><input type="number" step="0.01" min="0" name="amount" class="input text-sm w-32" required></div>
        <div class="flex-1 min-w-[160px]"><label class="block text-xs text-gray-600 mb-1">Reason</label><input type="text" name="reason" class="input text-sm w-full" placeholder="e.g. fraud / not recognised"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Gateway ref</label><input type="text" name="gateway_ref" class="input text-sm w-36"></div>
        <button type="submit" class="btn secondary text-sm">Log chargeback</button>
      </form>
      <p class="text-xs text-gray-400 mt-1">Posts a balanced reversal to the ledger (operating expense &harr; cash). Resolving as <em>Won</em> reverses it.</p>
      <?php endif; ?>
    </div>

    <!-- PAYMENT INTENTS (preauth / link) -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Pre-authorizations &amp; payment links</h3>
      <?php if (!empty($intents)): ?>
        <div class="overflow-x-auto mb-3">
          <table class="min-w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 border-b">
              <th class="py-2 pr-3">Kind</th><th class="py-2 pr-3">Amount</th><th class="py-2 pr-3">Status</th>
              <th class="py-2 pr-3">Note</th><th class="py-2 pr-3">Created</th><th class="py-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($intents as $it): ?>
              <tr class="border-b last:border-0">
                <td class="py-2 pr-3"><?= $it['kind'] === 'preauth' ? 'Pre-auth hold' : 'Payment link' ?></td>
                <td class="py-2 pr-3 font-medium"><?= htmlspecialchars((string) ($it['currency'] ?? $cur)) ?> <?= $fmt($it['amount']) ?></td>
                <td class="py-2 pr-3"><span class="inline-flex px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600"><?= htmlspecialchars((string) $it['status']) ?></span></td>
                <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($it['note'] ?? '—')) ?></td>
                <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars((string) ($it['created_at'] ?? '')) ?></td>
                <td class="py-2 text-right">
                  <?php if ($canEdit && !in_array($it['status'], ['cancelled', 'captured'], true)): ?>
                    <form action="<?= $base ?>/intent/cancel" method="POST" class="inline" style="display:inline" onsubmit="return confirm('Cancel this request?')">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
                      <input type="hidden" name="intent_id" value="<?= (int) $it['id'] ?>">
                      <button type="submit" class="text-xs text-red-600 hover:underline">Cancel</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500 mb-3">No pre-auth or payment-link requests on this booking.</p>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/intent" method="POST" class="flex flex-wrap items-end gap-2" style="display:flex">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string) $booking['invoice_id']) ?>">
        <div><label class="block text-xs text-gray-600 mb-1">Kind</label><select name="kind" class="input text-sm"><option value="link">Payment link</option><option value="preauth">Pre-auth hold</option></select></div>
        <div><label class="block text-xs text-gray-600 mb-1">Amount *</label><input type="number" step="0.01" min="0" name="amount" class="input text-sm w-32" required></div>
        <div class="flex-1 min-w-[160px]"><label class="block text-xs text-gray-600 mb-1">Note</label><input type="text" name="note" class="input text-sm w-full"></div>
        <button type="submit" class="btn secondary text-sm">Create request</button>
      </form>
      <p class="text-xs text-gray-400 mt-1">Record only &mdash; gateway not connected, so nothing is charged or held.</p>
      <?php endif; ?>
    </div>

    <!-- AUDIT TRAIL -->
    <div class="card p-5">
      <h3 class="text-sm font-semibold text-gray-900 mb-3">Payment audit trail</h3>
      <?php if (!empty($trail)): ?>
        <ul class="space-y-2">
          <?php foreach ($trail as $ev): ?>
            <li class="flex items-start gap-3 text-sm">
              <span class="material-symbols-outlined text-gray-400 text-base">receipt_long</span>
              <div>
                <span class="font-medium text-gray-800"><?= htmlspecialchars((string) $ev['event']) ?></span>
                <span class="text-gray-400 text-xs"><?= htmlspecialchars((string) ($ev['created_at'] ?? '')) ?> &middot; <?= htmlspecialchars((string) ($ev['actor_user_id'] ?? '')) ?></span>
                <?php $meta = json_decode((string) ($ev['meta'] ?? ''), true); if (is_array($meta) && $meta): ?>
                  <div class="text-xs text-gray-500"><?= htmlspecialchars(implode(' · ', array_map(fn($k, $v) => $k . '=' . (is_scalar($v) ? $v : json_encode($v)), array_keys($meta), array_values($meta)))) ?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-sm text-gray-500">No payment events recorded for this booking yet.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- SCOPE-WIDE CHARGEBACK LOG -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">All chargebacks (across your properties)</h2>
    <?php if (!empty($chargebacks)): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead><tr class="text-left text-xs text-gray-500 border-b">
            <th class="py-2 pr-3">Property</th><th class="py-2 pr-3">Booking</th><th class="py-2 pr-3">Amount</th>
            <th class="py-2 pr-3">Reason</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Opened</th>
          </tr></thead>
          <tbody>
          <?php foreach ($chargebacks as $cb): ?>
            <tr class="border-b last:border-0">
              <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars($propMap[(int) ($cb['stay_id'] ?? 0)] ?? ('#' . (int) ($cb['stay_id'] ?? 0))) ?></td>
              <td class="py-2 pr-3"><a href="<?= $base ?>?invoice=<?= urlencode((string) $cb['invoice_id']) ?>" class="text-violet-600 hover:underline"><?= htmlspecialchars((string) $cb['invoice_id']) ?></a></td>
              <td class="py-2 pr-3 font-medium"><?= htmlspecialchars((string) ($cb['currency'] ?? 'USD')) ?> <?= $fmt($cb['amount']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($cb['reason'] ?? '—')) ?></td>
              <td class="py-2 pr-3"><span class="inline-flex px-2 py-0.5 rounded text-xs <?= $cbColor[$cb['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars((string) $cb['status']) ?></span></td>
              <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars((string) ($cb['opened_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No chargebacks logged across your properties.</p>
    <?php endif; ?>
  </div>
</div>
