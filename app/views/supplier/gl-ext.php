<?php @$SECURE or die('Access Denied!'); ?>
<?php
// General ledger (PMS #34 ext). Data from supplierGlExtRoutes.php:
//   $tab, $accounts, $costCenters, $periods, $journal, $entry, $cashflow, $budget, $audit.
$tab        = (string) ($tab ?? 'journal');
$accounts   = (isset($accounts) && is_array($accounts)) ? $accounts : [];
$costCenters= (isset($costCenters) && is_array($costCenters)) ? $costCenters : [];
$periods    = (isset($periods) && is_array($periods)) ? $periods : [];
$journal    = (isset($journal) && is_array($journal)) ? $journal : ['rows'=>[],'count'=>0];
$entry      = (isset($entry) && is_array($entry)) ? $entry : null;
$cashflow   = (isset($cashflow) && is_array($cashflow)) ? $cashflow : null;
$budget     = (isset($budget) && is_array($budget)) ? $budget : null;
$audit      = (isset($audit) && is_array($audit)) ? $audit : ['rows'=>[]];
$base       = root . 'supplier/finance/ledger';
$budgetPid  = (int) ($GLOBALS['__glx_budget_pid'] ?? 0);
$tabs = [
  'journal'  => ['Journal', 'receipt_long'],
  'periods'  => ['Periods', 'event_available'],
  'centres'  => ['Cost centres', 'donut_small'],
  'cashflow' => ['Cash flow', 'water_drop'],
  'budget'   => ['Budget vs actual', 'query_stats'],
  'audit'    => ['Audit trail', 'history'],
];
$navTab = $tab === 'entry' ? 'journal' : $tab;
$fmt = fn($n) => number_format((float) $n, 2);
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
      <h1 class="text-2xl font-bold text-gray-900">General ledger</h1>
      <p class="text-sm text-gray-600">Manual journals, period close, cost centres, cash flow, budget and audit — all on your double-entry books. <a href="<?= root ?>supplier/finance" class="text-violet-600 hover:underline">Trial balance &amp; statements &rarr;</a></p>
    </div>
  </div>

  <!-- Tabs -->
  <div class="flex flex-wrap gap-1 border-b border-gray-200">
    <?php foreach ($tabs as $k => $t): ?>
      <a href="<?= $base ?>?tab=<?= $k ?>" class="px-3 py-2 text-sm font-medium rounded-t-md border-b-2 <?= $navTab === $k ? 'border-violet-600 text-violet-700' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
        <span class="material-symbols-outlined align-middle text-base mr-1"><?= $t[1] ?></span><?= htmlspecialchars($t[0]) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'journal'): ?>
  <!-- ========================= JOURNAL ========================= -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Post a manual journal entry</h2>
    <?php if (empty($accounts)): ?>
      <p class="text-sm text-gray-500">No accounts yet. The default chart of accounts seeds automatically on first use.</p>
    <?php else: ?>
    <form method="post" action="<?= $base ?>/journal" class="space-y-3" id="glx-journal-form">
      <?= CSRF::tokenField() ?>
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <label class="text-sm">Date<input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" class="input w-full mt-1" required></label>
        <label class="text-sm sm:col-span-2">Memo<input type="text" name="memo" maxlength="255" class="input w-full mt-1" placeholder="Description of the entry"></label>
        <label class="text-sm">Cost centre
          <select name="cost_center_id" class="input w-full mt-1">
            <option value="0">—</option>
            <?php foreach ($costCenters as $c): if (empty($c['active'])) continue; ?>
              <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['code'] . ' · ' . $c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-2 py-1.5 font-medium">Account</th><th class="px-2 py-1.5 font-medium text-right">Debit</th><th class="px-2 py-1.5 font-medium text-right">Credit</th><th class="px-2 py-1.5 font-medium">Line memo</th>
          </tr></thead>
          <tbody>
            <?php for ($i = 0; $i < 4; $i++): ?>
            <tr>
              <td class="px-2 py-1.5">
                <select name="account_id[]" class="input w-full">
                  <option value="0">—</option>
                  <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' · ' . $a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" name="debit[]" class="input w-28 text-right"></td>
              <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" name="credit[]" class="input w-28 text-right"></td>
              <td class="px-2 py-1.5"><input type="text" name="line_memo[]" maxlength="255" class="input w-full"></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-gray-400">Debits must equal credits — an unbalanced entry is refused by the poster. Each line is debit <em>or</em> credit, not both. Postings into a closed period are rejected.</p>
      <button type="submit" class="btn-primary">Post entry</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card overflow-x-auto">
    <div class="flex items-center justify-between px-4 pt-4">
      <h2 class="text-sm font-semibold text-gray-900">Journal entries</h2>
      <span class="text-xs text-gray-400"><?= (int) $journal['count'] ?> total</span>
    </div>
    <form method="get" action="<?= $base ?>" class="flex flex-wrap items-end gap-2 px-4 pt-2 text-sm">
      <input type="hidden" name="tab" value="journal">
      <label>From<input type="date" name="from" value="<?= htmlspecialchars((string) ($_GET['from'] ?? '')) ?>" class="input mt-1 block"></label>
      <label>To<input type="date" name="to" value="<?= htmlspecialchars((string) ($_GET['to'] ?? '')) ?>" class="input mt-1 block"></label>
      <button class="btn-secondary">Filter</button>
    </form>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">#</th><th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium">Memo</th>
        <th class="px-4 py-2 font-medium">Source</th><th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium"></th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($journal['rows'])): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No journal entries.</td></tr>
        <?php else: foreach ($journal['rows'] as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 tabular-nums"><?= (int) $r['id'] ?></td>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) $r['entry_date']) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) ($r['memo'] ?? '')) ?><?php if (!empty($r['reversed_by'])): ?> <span class="text-xs text-red-500">(reversed)</span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($r['source'] ?? '')) ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= $fmt($r['amount']) ?></td>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="<?= $base ?>?tab=entry&id=<?= (int) $r['id'] ?>" class="text-violet-600 hover:underline text-xs">View</a>
              <?php if (empty($r['reversed_by'])): ?>
              <form method="post" action="<?= $base ?>/journal/reverse" class="inline" onsubmit="return confirm('Post a reversing entry for #<?= (int) $r['id'] ?>?');">
                <?= CSRF::tokenField() ?><input type="hidden" name="entry_id" value="<?= (int) $r['id'] ?>">
                <button class="text-red-600 hover:underline text-xs ml-2">Reverse</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'entry' && $entry !== null): ?>
  <!-- ========================= ENTRY DRILL-DOWN ========================= -->
  <?php $h = $entry['header']; ?>
  <div class="card p-5 space-y-3">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-900">Journal entry #<?= (int) $h['id'] ?></h2>
      <a href="<?= $base ?>?tab=journal" class="text-xs text-violet-600 hover:underline">&larr; Back to journal</a>
    </div>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div><dt class="text-gray-400 text-xs">Date</dt><dd><?= htmlspecialchars((string) $h['entry_date']) ?></dd></div>
      <div><dt class="text-gray-400 text-xs">Source</dt><dd><?= htmlspecialchars((string) ($h['source'] ?? '')) ?></dd></div>
      <div><dt class="text-gray-400 text-xs">Reference</dt><dd><?= htmlspecialchars((string) ($h['reference'] ?? '—')) ?></dd></div>
      <div><dt class="text-gray-400 text-xs">Posted by</dt><dd><?= htmlspecialchars((string) ($h['posted_by'] ?? '—')) ?></dd></div>
    </dl>
    <?php if (!empty($h['memo'])): ?><p class="text-sm text-gray-700"><?= htmlspecialchars((string) $h['memo']) ?></p><?php endif; ?>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-2 py-1.5 font-medium">Account</th><th class="px-2 py-1.5 font-medium text-right">Debit</th><th class="px-2 py-1.5 font-medium text-right">Credit</th><th class="px-2 py-1.5 font-medium">Memo</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php $td = 0; $tc = 0; foreach ($entry['lines'] as $l): $td += (float) $l['debit']; $tc += (float) $l['credit']; ?>
          <tr>
            <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) ($l['acct_code'] ?? '') . ' · ' . (string) ($l['acct_name'] ?? '')) ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums"><?= (float) $l['debit'] > 0 ? $fmt($l['debit']) : '' ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums"><?= (float) $l['credit'] > 0 ? $fmt($l['credit']) : '' ?></td>
            <td class="px-2 py-1.5 text-gray-500"><?= htmlspecialchars((string) ($l['memo'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="font-semibold border-t border-gray-200"><td class="px-2 py-1.5">Totals</td><td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($td) ?></td><td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($tc) ?></td><td></td></tr>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'periods'): ?>
  <!-- ========================= PERIODS ========================= -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Create a fiscal period</h2>
    <form method="post" action="<?= $base ?>/period" class="flex flex-wrap items-end gap-3 text-sm">
      <?= CSRF::tokenField() ?>
      <label>Name<input type="text" name="name" maxlength="60" class="input mt-1 block" placeholder="e.g. Oct 2026" required></label>
      <label>Start<input type="date" name="start_date" class="input mt-1 block" required></label>
      <label>End<input type="date" name="end_date" class="input mt-1 block" required></label>
      <button class="btn-primary">Create</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">Closing a period refuses any new or reversing entry dated inside it. Ranges may not overlap.</p>
  </div>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Period</th><th class="px-4 py-2 font-medium">Range</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium text-right">Action</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($periods)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No periods defined — posting is unrestricted until you create one.</td></tr>
        <?php else: foreach ($periods as $p): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $p['name']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) $p['start_date']) ?> &rarr; <?= htmlspecialchars((string) $p['end_date']) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs <?= $p['status'] === 'open' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars((string) $p['status']) ?></span></td>
            <td class="px-4 py-2 text-right">
              <?php if ($p['status'] === 'open'): ?>
                <form method="post" action="<?= $base ?>/period/close" class="inline" onsubmit="return confirm('Close this period? No further entries may be dated within it.');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="period_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn-secondary text-xs">Close</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= $base ?>/period/reopen" class="inline" onsubmit="return confirm('Reopen this period?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="period_id" value="<?= (int) $p['id'] ?>">
                  <button class="text-violet-600 hover:underline text-xs">Reopen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'centres'): ?>
  <!-- ========================= COST CENTRES ========================= -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a cost centre</h2>
    <form method="post" action="<?= $base ?>/centre" class="flex flex-wrap items-end gap-3 text-sm">
      <?= CSRF::tokenField() ?>
      <label>Code<input type="text" name="code" maxlength="20" class="input mt-1 block uppercase" placeholder="ROOMS" required></label>
      <label class="grow">Name<input type="text" name="name" maxlength="120" class="input mt-1 block w-full" placeholder="Rooms division" required></label>
      <button class="btn-primary">Add</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">Tag manual journal entries with a cost centre for departmental analysis.</p>
  </div>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Code</th><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium text-right">Action</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($costCenters)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No cost centres yet.</td></tr>
        <?php else: foreach ($costCenters as $c): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 tabular-nums"><?= htmlspecialchars((string) $c['code']) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $c['name']) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs <?= !empty($c['active']) ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= !empty($c['active']) ? 'active' : 'inactive' ?></span></td>
            <td class="px-4 py-2 text-right">
              <form method="post" action="<?= $base ?>/centre/toggle" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="active" value="<?= !empty($c['active']) ? '0' : '1' ?>">
                <button class="text-violet-600 hover:underline text-xs"><?= !empty($c['active']) ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'cashflow'): ?>
  <!-- ========================= CASH FLOW ========================= -->
  <?php $cf = $cashflow ?: ['opening'=>0,'closing'=>0,'net'=>0,'operating'=>0,'investing'=>0,'financing'=>0,'rows'=>[]]; ?>
  <form method="get" action="<?= $base ?>" class="card p-4 flex flex-wrap items-end gap-3 text-sm">
    <input type="hidden" name="tab" value="cashflow">
    <label>From<input type="date" name="from" value="<?= htmlspecialchars((string) ($_GET['from'] ?? date('Y-m-01'))) ?>" class="input mt-1 block"></label>
    <label>To<input type="date" name="to" value="<?= htmlspecialchars((string) ($_GET['to'] ?? date('Y-m-d'))) ?>" class="input mt-1 block"></label>
    <button class="btn-secondary">Recalculate</button>
  </form>
  <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    <?php foreach ([['Opening cash','opening'],['Operating','operating'],['Investing','investing'],['Financing','financing'],['Closing cash','closing']] as $cell): ?>
      <div class="card p-4"><div class="text-xs text-gray-400"><?= $cell[0] ?></div><div class="text-lg font-bold tabular-nums <?= (float) $cf[$cell[1]] < 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= $fmt($cf[$cell[1]]) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-gray-400">Direct-method view over movement on the Cash/Bank account(s). Net change (<?= $fmt($cf['net']) ?>) = operating + investing + financing. Classification uses the dominant counter-leg of each cash-touching entry.</p>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium">Entry</th><th class="px-4 py-2 font-medium">Memo</th><th class="px-4 py-2 font-medium">Class</th><th class="px-4 py-2 font-medium text-right">Cash delta</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($cf['rows'])): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No cash movements in this window.</td></tr>
        <?php else: foreach ($cf['rows'] as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) $r['date']) ?></td>
            <td class="px-4 py-2 text-gray-500"><a href="<?= $base ?>?tab=entry&id=<?= (int) $r['entry_id'] ?>" class="text-violet-600 hover:underline">#<?= (int) $r['entry_id'] ?></a></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['memo']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) $r['class']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= (float) $r['delta'] < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $fmt($r['delta']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'budget'): ?>
  <!-- ========================= BUDGET vs ACTUAL ========================= -->
  <form method="get" action="<?= $base ?>" class="card p-4 flex flex-wrap items-end gap-3 text-sm">
    <input type="hidden" name="tab" value="budget">
    <label>Period
      <select name="period_id" class="input mt-1 block" onchange="this.form.submit()">
        <?php if (empty($periods)): ?><option>No periods</option><?php endif; ?>
        <?php foreach ($periods as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $budgetPid === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if ($budgetPid > 0 && !empty($accounts)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Set a budget line</h2>
    <form method="post" action="<?= $base ?>/budget" class="flex flex-wrap items-end gap-3 text-sm">
      <?= CSRF::tokenField() ?><input type="hidden" name="period_id" value="<?= $budgetPid ?>">
      <label>Account<select name="account_id" class="input mt-1 block" required>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label>Cost centre<select name="cost_center_id" class="input mt-1 block"><option value="0">—</option>
        <?php foreach ($costCenters as $c): if (empty($c['active'])) continue; ?><option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['code']) ?></option><?php endforeach; ?>
      </select></label>
      <label>Amount<input type="number" step="0.01" min="0" name="amount" class="input mt-1 block w-32 text-right" required></label>
      <button class="btn-primary">Save</button>
    </form>
  </div>
  <?php endif; ?>

  <?php $bvRows = $budget['rows'] ?? []; $bvTot = $budget['totals'] ?? ['budget'=>0,'actual'=>0,'variance'=>0]; ?>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Code</th><th class="px-4 py-2 font-medium">Account</th><th class="px-4 py-2 font-medium text-right">Budget</th><th class="px-4 py-2 font-medium text-right">Actual</th><th class="px-4 py-2 font-medium text-right">Variance</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($bvRows)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No budget lines for this period yet.</td></tr>
        <?php else: foreach ($bvRows as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 tabular-nums"><?= htmlspecialchars((string) $r['code']) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['name']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $fmt($r['budget']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $fmt($r['actual']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= (float) $r['variance'] < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $fmt($r['variance']) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="font-semibold border-t border-gray-200"><td class="px-4 py-2" colspan="2">Totals</td><td class="px-4 py-2 text-right tabular-nums"><?= $fmt($bvTot['budget']) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= $fmt($bvTot['actual']) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= $fmt($bvTot['variance']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'audit'): ?>
  <!-- ========================= AUDIT TRAIL ========================= -->
  <form method="get" action="<?= $base ?>" class="card p-4 flex flex-wrap items-end gap-3 text-sm">
    <input type="hidden" name="tab" value="audit">
    <label>Event contains<input type="text" name="event" value="<?= htmlspecialchars((string) ($_GET['event'] ?? '')) ?>" class="input mt-1 block" placeholder="gl."></label>
    <label>From<input type="date" name="from" value="<?= htmlspecialchars((string) ($_GET['from'] ?? '')) ?>" class="input mt-1 block"></label>
    <label>To<input type="date" name="to" value="<?= htmlspecialchars((string) ($_GET['to'] ?? '')) ?>" class="input mt-1 block"></label>
    <button class="btn-secondary">Filter</button>
  </form>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium">Event</th><th class="px-4 py-2 font-medium">Subject</th><th class="px-4 py-2 font-medium">Actor</th><th class="px-4 py-2 font-medium">IP</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($audit['rows'])): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No audit events.</td></tr>
        <?php else: foreach ($audit['rows'] as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string) $r['created_at']) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['event']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($r['subject_type'] ?? '') . ($r['subject_id'] ? ' #' . $r['subject_id'] : '')) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($r['actor_user_id'] ?? '—')) ?></td>
            <td class="px-4 py-2 text-gray-400 tabular-nums"><?= htmlspecialchars((string) ($r['ip'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
