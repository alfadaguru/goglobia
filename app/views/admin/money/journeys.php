<?php
// ADMIN — Transaction Journeys (docs/MONEY-WALLET-AUDIT.md §A.2)
// Read-only viewer over the money spine. Renders a LIST or a DETAIL depending on
// $view, provided by app/routes/admin/moneyRoutes.php.
//   list:   $txns, $summary, $total, $page, $pages, filters ($fStatus,...,$q)
//   detail: $txn, $journey, $ledger, $ownerName
@$SECURE or die('Access Denied!');

$view = $view ?? 'list';

// ---- shared helpers (status pill + money formatting) ----
$statusPill = function (string $s): string {
    $map = [
        'success'   => ['Success',   'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'pending'   => ['Pending',   'bg-amber-50 text-amber-700 border-amber-200'],
        'sent'      => ['Sent',      'bg-sky-50 text-sky-700 border-sky-200'],
        'failed'    => ['Failed',    'bg-red-50 text-red-700 border-red-200'],
        'cancelled' => ['Cancelled', 'bg-slate-100 text-slate-600 border-slate-200'],
        'reversed'  => ['Reversed',  'bg-purple-50 text-purple-700 border-purple-200'],
    ];
    [$label, $cls] = $map[$s] ?? [ucfirst($s), 'bg-slate-100 text-slate-600 border-slate-200'];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $cls . '">' . htmlspecialchars($label) . '</span>';
};
$dirBadge = function (string $d): string {
    return $d === 'credit'
        ? '<span class="inline-flex items-center gap-1 text-emerald-700 font-medium"><span class="material-symbols-outlined text-[16px]">south_west</span>Credit</span>'
        : '<span class="inline-flex items-center gap-1 text-slate-700 font-medium"><span class="material-symbols-outlined text-[16px]">north_east</span>Debit</span>';
};
$money = fn($a, $c) => htmlspecialchars((string) $c) . ' ' . number_format((float) $a, 2);
?>

<div class="container my-4">

<?php if ($view === 'detail'): // ===================== DETAIL ===================== ?>

    <?php $txn = $txn ?? null; $journey = $journey ?? []; $ledger = $ledger ?? []; $ownerName = $ownerName ?? ''; ?>

    <div class="flex items-center gap-3 mb-6">
        <a href="<?= root . admin ?>/finance/journeys" class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-50">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1 class="text-xl font-bold text-slate-800">Transaction Journey</h1>
            <p class="text-sm text-slate-500 mt-0.5">The full life of one money movement.</p>
        </div>
    </div>

    <?php if (!$txn): ?>
        <div class="card p-8 text-center text-slate-500">
            <span class="material-symbols-outlined text-4xl mb-2 block">search_off</span>
            Transaction not found.
        </div>
    <?php else: ?>

        <!-- Transaction facts -->
        <div class="card p-0 mb-6">
            <div class="card-header flex items-center justify-between">
                <div>
                    <span class="card-header-icon text-[18px]">receipt_long</span>
                    <h3><code class="text-sm"><?= htmlspecialchars($txn['txn_ref']) ?></code></h3>
                </div>
                <?= $statusPill((string) $txn['status']) ?>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-y-4 gap-x-6 text-sm">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Amount</div>
                        <div class="text-lg font-bold text-slate-800 tabular-nums"><?= $money($txn['amount'], $txn['currency']) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Direction</div>
                        <div><?= $dirBadge((string) $txn['direction']) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Reason</div>
                        <div class="text-slate-700"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $txn['reason']))) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Method</div>
                        <div class="text-slate-700 capitalize"><?= htmlspecialchars((string) $txn['method']) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Actor</div>
                        <div class="text-slate-700 capitalize"><?= htmlspecialchars((string) ($txn['actor_kind'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">User</div>
                        <div class="text-slate-700"><?= htmlspecialchars($ownerName ?: ($txn['user_id'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Invoice</div>
                        <div class="text-slate-700"><?= htmlspecialchars((string) ($txn['invoice_id'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Provider Ref</div>
                        <div class="text-slate-700"><?= htmlspecialchars((string) ($txn['provider_trx_id'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Created</div>
                        <div class="text-slate-700"><?= htmlspecialchars((string) $txn['created_at']) ?></div>
                    </div>
                    <div class="col-span-2 md:col-span-3">
                        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Description</div>
                        <div class="text-slate-700"><?= htmlspecialchars((string) ($txn['description'] ?: '—')) ?></div>
                    </div>
                    <?php if (!empty($txn['error_message'])): ?>
                    <div class="col-span-2 md:col-span-4">
                        <div class="text-xs uppercase tracking-wide text-red-400 mb-1">Error</div>
                        <div class="text-red-700"><?= htmlspecialchars((string) $txn['error_message']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Journey timeline -->
        <div class="card p-0 mb-6">
            <div class="card-header">
                <div><span class="card-header-icon text-[18px]">timeline</span><h3>Journey</h3></div>
            </div>
            <div class="card-body">
                <?php if (empty($journey)): ?>
                    <p class="text-slate-500 text-sm">No journey entries recorded.</p>
                <?php else: ?>
                    <ol class="relative border-s-2 border-slate-200 ms-3">
                        <?php foreach ($journey as $j): ?>
                            <li class="ms-6 pb-6 last:pb-0">
                                <span class="absolute -start-[9px] flex items-center justify-center w-4 h-4 rounded-full ring-4 ring-white
                                    <?= $j['to_status'] === 'success' ? 'bg-emerald-500' : ($j['to_status'] === 'failed' || $j['to_status'] === 'cancelled' ? 'bg-red-500' : ($j['to_status'] === 'reversed' ? 'bg-purple-500' : 'bg-sky-400')) ?>"></span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php if (!empty($j['from_status'])): ?>
                                        <span class="text-xs text-slate-400"><?= htmlspecialchars((string) $j['from_status']) ?></span>
                                        <span class="material-symbols-outlined text-[14px] text-slate-300">arrow_forward</span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">born</span>
                                        <span class="material-symbols-outlined text-[14px] text-slate-300">arrow_forward</span>
                                    <?php endif; ?>
                                    <span class="font-semibold text-slate-800 text-sm"><?= htmlspecialchars((string) $j['to_status']) ?></span>
                                    <span class="text-xs text-slate-400 ms-auto"><?= htmlspecialchars((string) $j['created_at']) ?></span>
                                </div>
                                <?php if (!empty($j['note'])): ?>
                                    <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars((string) $j['note']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($j['context'])): ?>
                                    <details class="mt-1">
                                        <summary class="text-xs text-slate-400 cursor-pointer hover:text-slate-600">gateway payload</summary>
                                        <pre class="mt-1 text-xs bg-slate-50 border border-slate-200 rounded p-2 overflow-x-auto"><?= htmlspecialchars((string) $j['context']) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>

        <!-- Wallet ledger impact -->
        <div class="card p-0">
            <div class="card-header">
                <div><span class="card-header-icon text-[18px]">account_balance_wallet</span><h3>Wallet impact</h3></div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($ledger)): ?>
                    <p class="text-slate-500 text-sm p-4">No wallet movement — this transaction did not change a wallet balance (e.g. an external gateway payment funds the booking directly).</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500 border-b border-slate-200">
                                    <th class="px-4 py-2 font-medium">When</th>
                                    <th class="px-4 py-2 font-medium">Direction</th>
                                    <th class="px-4 py-2 font-medium">Reason</th>
                                    <th class="px-4 py-2 font-medium text-right">Amount</th>
                                    <th class="px-4 py-2 font-medium text-right">Balance after</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledger as $l): ?>
                                    <tr class="border-b border-slate-100">
                                        <td class="px-4 py-2 text-slate-500"><?= htmlspecialchars((string) $l['created_at']) ?></td>
                                        <td class="px-4 py-2"><?= $dirBadge((string) $l['direction']) ?></td>
                                        <td class="px-4 py-2 text-slate-600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $l['reason']))) ?></td>
                                        <td class="px-4 py-2 text-right tabular-nums"><?= $money($l['amount'], $l['currency']) ?></td>
                                        <td class="px-4 py-2 text-right tabular-nums font-semibold"><?= $money($l['balance_after'], $l['currency']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

<?php else: // ===================== LIST ===================== ?>

    <?php
    $txns = $txns ?? [];
    $summary = $summary ?? ['total' => 0, 'success' => 0, 'pending' => 0, 'failed' => 0];
    $total = $total ?? 0; $page = $page ?? 1; $pages = $pages ?? 1;
    $fStatus = $fStatus ?? ''; $fDirection = $fDirection ?? ''; $fReason = $fReason ?? ''; $fMethod = $fMethod ?? ''; $q = $q ?? '';
    // Preserve filters when paginating.
    $qs = fn($extra = []) => http_build_query(array_merge(array_filter([
        'status' => $fStatus, 'direction' => $fDirection, 'reason' => $fReason, 'method' => $fMethod, 'q' => $q,
    ]), $extra));
    ?>

    <div class="mb-6">
        <h1 class="text-xl font-bold text-slate-800">Transaction Journeys</h1>
        <p class="text-sm text-slate-600 mt-1">Every money movement on the spine — click one to trace its full life (pending → sent → success / failed / reversed) and its wallet impact.</p>
    </div>

    <!-- Summary tiles -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Matching</div>
            <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= number_format($summary['total']) ?></div>
        </div>
        <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
            <div class="text-xs font-medium text-emerald-700 mb-1">Success</div>
            <div class="text-2xl font-bold text-emerald-800 tabular-nums"><?= number_format($summary['success']) ?></div>
        </div>
        <div class="rounded-xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-4">
            <div class="text-xs font-medium text-amber-700 mb-1">In progress</div>
            <div class="text-2xl font-bold text-amber-800 tabular-nums"><?= number_format($summary['pending']) ?></div>
        </div>
        <div class="rounded-xl border border-red-100 bg-gradient-to-br from-red-50 to-white p-4">
            <div class="text-xs font-medium text-red-700 mb-1">Failed / cancelled</div>
            <div class="text-2xl font-bold text-red-800 tabular-nums"><?= number_format($summary['failed']) ?></div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="<?= root . admin ?>/finance/journeys" class="card p-4 mb-5">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="text-xs font-medium text-slate-600">Search</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ref, invoice, user, provider ref…" class="input w-full">
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">Status</label>
                <select name="status" class="select input w-full">
                    <option value="">All</option>
                    <?php foreach (['pending','sent','success','failed','cancelled','reversed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">Direction</label>
                <select name="direction" class="select input w-full">
                    <option value="">All</option>
                    <option value="credit" <?= $fDirection === 'credit' ? 'selected' : '' ?>>Credit</option>
                    <option value="debit" <?= $fDirection === 'debit' ? 'selected' : '' ?>>Debit</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">Method</label>
                <select name="method" class="select input w-full">
                    <option value="">All</option>
                    <?php foreach (['gateway','wallet','manual'] as $m): ?>
                        <option value="<?= $m ?>" <?= $fMethod === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn primary flex-1">Filter</button>
                <a href="<?= root . admin ?>/finance/journeys" class="btn secondary inline-flex items-center justify-center px-3" title="Reset">
                    <span class="material-symbols-outlined text-[18px]">restart_alt</span>
                </a>
            </div>
        </div>
    </form>

    <!-- List -->
    <div class="card p-0">
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Reference</th>
                            <th class="px-4 py-3 font-medium">When</th>
                            <th class="px-4 py-3 font-medium">Direction</th>
                            <th class="px-4 py-3 font-medium">Reason</th>
                            <th class="px-4 py-3 font-medium">Method</th>
                            <th class="px-4 py-3 font-medium text-right">Amount</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($txns)): ?>
                            <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                <span class="material-symbols-outlined text-3xl mb-1 block">receipt_long</span>
                                No transactions match these filters.
                            </td></tr>
                        <?php else: foreach ($txns as $t): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <code class="text-xs text-slate-700"><?= htmlspecialchars((string) $t['txn_ref']) ?></code>
                                    <?php if (!empty($t['invoice_id'])): ?>
                                        <div class="text-[11px] text-slate-400 mt-0.5">inv <?= htmlspecialchars((string) $t['invoice_id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string) $t['created_at']) ?></td>
                                <td class="px-4 py-3"><?= $dirBadge((string) $t['direction']) ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $t['reason']))) ?></td>
                                <td class="px-4 py-3 text-slate-600 capitalize"><?= htmlspecialchars((string) $t['method']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium"><?= $money($t['amount'], $t['currency']) ?></td>
                                <td class="px-4 py-3"><?= $statusPill((string) $t['status']) ?></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="<?= root . admin ?>/finance/journeys/<?= (int) $t['id'] ?>" class="text-primary hover:underline inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
        <div class="flex items-center justify-between mt-4 text-sm">
            <div class="text-slate-500">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> transactions</div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                    <a href="?<?= $qs(['page' => $page - 1]) ?>" class="btn secondary px-3 py-1.5">Previous</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a href="?<?= $qs(['page' => $page + 1]) ?>" class="btn primary px-3 py-1.5">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

</div>
