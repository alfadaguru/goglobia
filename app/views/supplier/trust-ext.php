<?php @$SECURE or die('Access Denied!'); ?>
<?php
// CLIENT-MONEY TRUST (PMS #50 deepening). Passed from supplierTrustExtRoutes.php:
//   $balances  (trust_balances shape: parties[], totals[cur=>amt]),
//   $bonds     (trust_bond_list rows), $recon (trust_reconciliation shape),
//   $audit     (trust_audit_trail rows), $feed (trust_bank_feed_status).
// Read-only lists + money forms. Owner-only screen. Builds on /supplier/trust (base
// free-text ledger) — this screen adds structured bonds, management-fee postings,
// and the three-way trust reconciliation.
$balances = (isset($balances) && is_array($balances)) ? $balances : ['parties' => [], 'totals' => [], 'total' => 0];
$bonds    = (isset($bonds) && is_array($bonds)) ? $bonds : [];
$recon    = (isset($recon) && is_array($recon)) ? $recon : ['rows' => [], 'any_shortfall' => false];
$audit    = (isset($audit) && is_array($audit)) ? $audit : [];
$feed     = (isset($feed) && is_array($feed)) ? $feed : ['connected' => false, 'message' => ''];
$balTotals = (isset($balances['totals']) && is_array($balances['totals'])) ? $balances['totals'] : [];
$base = root . 'supplier/trust-ext';
$n = fn($v) => number_format((float) $v, 2);
$defCur = htmlspecialchars(strtoupper((string) ($GLOBALS['app']['default_currency'] ?? 'USD')));
$kinds = function_exists('trust_bond_kinds') ? trust_bond_kinds() : ['security' => 'Security', 'advance' => 'Advance', 'owner_liability' => 'Owner liability'];
$bondStatuses = function_exists('trust_bond_statuses') ? trust_bond_statuses() : [];
$statusClass = ['held' => 'bg-blue-50 text-blue-700', 'refunded' => 'bg-gray-100 text-gray-600', 'forfeited' => 'bg-amber-50 text-amber-700', 'partial' => 'bg-indigo-50 text-indigo-700'];
$verdictClass = ['reconciled' => 'bg-green-50 text-green-700', 'surplus' => 'bg-blue-50 text-blue-700', 'shortfall' => 'bg-red-50 text-red-700'];
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

  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/trust" class="hover:underline">Trust ledger</a>
      <span class="mx-1">/</span><span class="text-gray-700">Client-money trust</span>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900">Client-money trust</h1>
    <p class="text-sm text-gray-500 mt-1">Guest bonds &amp; deposits, owner liabilities, management-fee postings and trust-account reconciliation. Client money is held separately per currency and never netted against your own funds.</p>
  </div>

  <!-- RECONCILIATION (three-way: book liability vs. recorded trust-bank balance) -->
  <div class="card p-4 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <h2 class="text-sm font-semibold text-gray-900">Trust reconciliation</h2>
      <?php if (!empty($recon['any_shortfall'])): ?>
        <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded bg-red-50 text-red-700"><span class="material-symbols-outlined text-sm">warning</span>Client-money shortfall</span>
      <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="py-2 pr-3 font-medium">Currency</th>
          <th class="py-2 px-3 font-medium text-right">Book liability</th>
          <th class="py-2 px-3 font-medium text-right">Trust bank</th>
          <th class="py-2 px-3 font-medium text-right">Variance</th>
          <th class="py-2 px-3 font-medium">Status</th>
          <th class="py-2 pl-3 font-medium">As of</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($recon['rows'])): ?>
            <tr><td colspan="6" class="py-6 text-center text-gray-400">No client money held yet.</td></tr>
          <?php else: foreach ($recon['rows'] as $row): $v = (string) ($row['verdict'] ?? ''); ?>
            <tr>
              <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars((string) $row['currency']) ?></td>
              <td class="py-2 px-3 text-right tabular-nums"><?= $n($row['book'] ?? 0) ?></td>
              <td class="py-2 px-3 text-right tabular-nums"><?= empty($row['bank_recorded']) ? '<span class="text-gray-400">—</span>' : $n($row['bank'] ?? 0) ?></td>
              <td class="py-2 px-3 text-right tabular-nums <?= ((float) ($row['variance'] ?? 0)) < 0 ? 'text-red-600' : 'text-gray-700' ?>"><?= $n($row['variance'] ?? 0) ?></td>
              <td class="py-2 px-3"><span class="inline-block text-xs font-medium px-2 py-0.5 rounded <?= $verdictClass[$v] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst($v)) ?></span></td>
              <td class="py-2 pl-3 text-gray-400 text-xs"><?= htmlspecialchars((string) ($row['as_of'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Book liability = total client money the ledger says you hold (per currency). Trust bank = the balance you record below from your segregated client account. A negative variance (bank &lt; book) means client money is missing and must be corrected.</p>

    <!-- Record the trust-bank statement balance (reconciliation "bank side") -->
    <form action="<?= $base ?>/bank" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Currency</label><input type="text" name="currency" maxlength="3" class="input text-sm w-20 uppercase" value="<?= $defCur ?>" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Trust-bank balance</label><input type="number" name="balance" step="0.01" class="input text-sm w-32" placeholder="0.00" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">As of</label><input type="date" name="as_of" value="<?= date('Y-m-d') ?>" class="input text-sm"></div>
      <div class="form-control flex-1 min-w-[8rem]"><label class="block text-xs text-gray-600 mb-1">Note</label><input type="text" name="note" class="input text-sm w-full" placeholder="e.g. statement ref"></div>
      <button type="submit" class="btn secondary text-sm">Record bank balance</button>
    </form>
    <?php if (empty($feed['connected'])): ?>
      <p class="text-xs text-amber-600 flex items-center gap-1"><span class="material-symbols-outlined text-sm">link_off</span><?= htmlspecialchars((string) ($feed['message'] ?? 'No live trust-bank feed connected.')) ?></p>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <!-- Balances held (per party x currency) -->
    <div class="card p-4">
      <h2 class="text-sm font-semibold text-gray-900 mb-2">Balances held</h2>
      <?php if (empty($balances['parties'])): ?>
        <p class="text-sm text-gray-500">Nothing held.</p>
      <?php else: ?>
        <ul class="text-sm divide-y divide-gray-50">
          <?php foreach ($balances['parties'] as $b): $cur = htmlspecialchars((string) ($b['currency'] ?? 'USD')); ?>
            <li class="py-1.5 flex justify-between"><span class="text-gray-700"><?= htmlspecialchars((string) $b['party']) ?></span><span class="tabular-nums <?= (float) $b['balance'] < 0 ? 'text-red-600' : '' ?>"><?= $cur ?> <?= $n($b['balance']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="border-t border-gray-100 pt-1.5 mt-1.5 text-sm font-semibold space-y-0.5">
          <?php foreach ($balTotals as $cur => $amt): ?>
            <div class="flex justify-between"><span>Total held (<?= htmlspecialchars((string) $cur) ?>)</span><span class="tabular-nums"><?= htmlspecialchars((string) $cur) ?> <?= $n($amt) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Management-fee posting (pulls fee OUT of held trust, books revenue) -->
    <div class="card p-4">
      <h2 class="text-sm font-semibold text-gray-900 mb-1">Charge management fee</h2>
      <p class="text-xs text-gray-500 mb-3">Takes your fee out of the money held for an owner and books it as revenue. Clamped to what is actually held.</p>
      <form action="<?= $base ?>/fee" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <input type="text" name="party" class="input text-sm w-full" placeholder="Owner / party *" required>
        <div class="flex gap-2">
          <input type="number" name="amount" step="0.01" class="input text-sm w-32" placeholder="fee amount" required>
          <input type="text" name="currency" maxlength="3" class="input text-sm w-20 uppercase" value="<?= $defCur ?>">
        </div>
        <input type="text" name="reference" class="input text-sm w-full" placeholder="Reference (optional)">
        <input type="text" name="memo" class="input text-sm w-full" placeholder="Memo" value="Management fee">
        <button type="submit" class="btn emerald text-sm">Charge fee</button>
      </form>
    </div>
  </div>

  <!-- Structured bonds / deposits -->
  <div class="card p-4 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Bonds &amp; deposits</h2>
    <form action="<?= $base ?>/bond/create" method="POST" class="flex flex-wrap items-end gap-2 border-b border-gray-100 pb-3">
      <?= CSRF::tokenField() ?>
      <div class="form-control flex-1 min-w-[10rem]"><label class="block text-xs text-gray-600 mb-1">Party</label><input type="text" name="party" class="input text-sm w-full" placeholder="Guest / owner *" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Kind</label>
        <select name="kind" class="input text-sm"><?php foreach ($kinds as $k => $lbl): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $lbl) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Amount</label><input type="number" name="amount" step="0.01" class="input text-sm w-28" placeholder="0.00" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Cur</label><input type="text" name="currency" maxlength="3" class="input text-sm w-16 uppercase" value="<?= $defCur ?>"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Reference</label><input type="text" name="reference" class="input text-sm w-32" placeholder="ref"></div>
      <button type="submit" class="btn primary text-sm">Hold bond</button>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="py-2 pr-3 font-medium">Party</th>
          <th class="py-2 px-3 font-medium">Kind</th>
          <th class="py-2 px-3 font-medium text-right">Held</th>
          <th class="py-2 px-3 font-medium text-right">Released</th>
          <th class="py-2 px-3 font-medium">Status</th>
          <th class="py-2 pl-3 font-medium">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($bonds)): ?>
            <tr><td colspan="6" class="py-6 text-center text-gray-400">No bonds recorded.</td></tr>
          <?php else: foreach ($bonds as $bd):
            $held = (float) ($bd['amount_held'] ?? 0); $rel = (float) ($bd['amount_released'] ?? 0);
            $remaining = round($held - $rel, 2); $stt = (string) ($bd['status'] ?? 'held');
            $cur = htmlspecialchars((string) ($bd['currency'] ?? 'USD'));
          ?>
            <tr>
              <td class="py-2 pr-3 text-gray-800"><?= htmlspecialchars((string) $bd['party']) ?></td>
              <td class="py-2 px-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($kinds[$bd['kind'] ?? ''] ?? ($bd['kind'] ?? ''))) ?></td>
              <td class="py-2 px-3 text-right tabular-nums"><?= $cur ?> <?= $n($held) ?></td>
              <td class="py-2 px-3 text-right tabular-nums text-gray-500"><?= $n($rel) ?></td>
              <td class="py-2 px-3"><span class="inline-block text-xs font-medium px-2 py-0.5 rounded <?= $statusClass[$stt] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars((string) ($bondStatuses[$stt] ?? ucfirst($stt))) ?></span></td>
              <td class="py-2 pl-3">
                <?php if ($remaining > 0.009): ?>
                  <form action="<?= $base ?>/bond/release" method="POST" class="flex items-center gap-1">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="bond_id" value="<?= (int) $bd['id'] ?>">
                    <input type="number" name="amount" step="0.01" max="<?= htmlspecialchars((string) $remaining) ?>" value="<?= htmlspecialchars((string) $remaining) ?>" class="input text-xs w-20">
                    <select name="mode" class="input text-xs"><option value="refund">Refund</option><option value="forfeit">Forfeit</option></select>
                    <button type="submit" class="btn secondary text-xs">Release</button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-gray-400">settled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400"><span class="font-medium">Refund</span> returns the money to the party (leaves the trust account). <span class="font-medium">Forfeit</span> keeps the money and books it as revenue (GL 2200 → 4900).</p>
  </div>

  <!-- Client-fund audit trail -->
  <div class="card p-4 space-y-2">
    <h2 class="text-sm font-semibold text-gray-900">Client-fund audit trail</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="py-2 pr-3 font-medium">When</th>
          <th class="py-2 px-3 font-medium">Event</th>
          <th class="py-2 px-3 font-medium">Actor</th>
          <th class="py-2 pl-3 font-medium">Detail</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($audit)): ?>
            <tr><td colspan="4" class="py-6 text-center text-gray-400">No client-money events yet.</td></tr>
          <?php else: foreach ($audit as $a):
            $meta = is_string($a['meta'] ?? null) ? json_decode((string) $a['meta'], true) : null;
            $detail = is_array($meta) ? implode(' · ', array_map(fn($k, $v) => $k . '=' . (is_scalar($v) ? $v : json_encode($v)), array_keys($meta), array_values($meta))) : '';
          ?>
            <tr>
              <td class="py-2 pr-3 text-gray-400 text-xs whitespace-nowrap"><?= htmlspecialchars(substr((string) ($a['created_at'] ?? ''), 0, 16)) ?></td>
              <td class="py-2 px-3 text-gray-700 text-xs font-medium"><?= htmlspecialchars((string) ($a['event'] ?? '')) ?></td>
              <td class="py-2 px-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($a['actor_user_id'] ?? '—')) ?></td>
              <td class="py-2 pl-3 text-gray-500 text-xs"><?= htmlspecialchars($detail) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
