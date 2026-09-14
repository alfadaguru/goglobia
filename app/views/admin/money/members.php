<?php
// ADMIN — Members: tier assignments + loyalty balances + manual points adjust.
// Provided by app/routes/admin/moneyRoutes.php: $tiers, $agents, $customers, $defaultCurrency
@$SECURE or die('Access Denied!');

$tiers = $tiers ?? [];
$agents = $agents ?? [];
$customers = $customers ?? [];
$defaultCurrency = $defaultCurrency ?? 'USD';
$fmtName = function ($r) {
    $n = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    return $n !== '' ? $n : ($r['email'] ?? $r['user_id'] ?? '—');
};
?>

<div class="container my-4">

    <div class="mb-6">
        <h1 class="text-xl font-bold text-slate-800">Members</h1>
        <p class="text-sm text-slate-600 mt-1">Who is on which agent tier, everyone's loyalty balance, and a manual points award / deduction. Configure the tiers &amp; scheme on <a class="text-primary hover:underline" href="<?= root . admin ?>/finance/tiers">Tiers &amp; Loyalty</a>.</p>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Tier distribution tiles -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <?php foreach ($tiers as $t): ?>
            <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
                <div class="text-xs font-medium text-indigo-700 mb-1 flex items-center gap-1">
                    <span class="material-symbols-outlined text-base">workspace_premium</span> <?= htmlspecialchars($t['name']) ?>
                </div>
                <div class="text-2xl font-bold text-indigo-900 tabular-nums"><?= (int)$t['agent_count'] ?></div>
                <p class="text-[11px] text-indigo-600 mt-1">
                    <?= rtrim(rtrim(number_format((float)$t['discount_percent'], 2), '0'), '.') ?>% off ·
                    from <?= htmlspecialchars($defaultCurrency) ?> <?= number_format((float)$t['min_lifetime_topup'], 0) ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Manual points adjust -->
    <div class="card p-0 mb-6" x-data="{ open: false }">
        <div class="card-header cursor-pointer" @click="open = !open">
            <div><span class="card-header-icon text-[18px]">loyalty</span><h3>Award / deduct loyalty points</h3></div>
            <span class="material-symbols-outlined text-slate-500 transition-transform" :class="open ? 'rotate-180' : ''">keyboard_arrow_down</span>
        </div>
        <div class="card-body" x-show="open" x-collapse style="display:none">
            <form method="POST" action="<?= root . admin ?>/finance/members/points">
                <?= CSRF::tokenField() ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">User ID</label>
                        <input type="text" name="user_id" class="input" placeholder="user_id" required>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Points</label>
                        <input type="number" name="points" min="1" class="input" required>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Action</label>
                        <select name="action" class="select input">
                            <option value="award">Award (add)</option>
                            <option value="deduct">Deduct (remove)</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Note (optional)</label>
                        <input type="text" name="note" class="input" placeholder="reason">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn primary inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl">check</span> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Agents by tier -->
    <div class="card p-0 mb-6">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">badge</span><h3>Agents</h3></div></div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Agent</th>
                            <th class="px-4 py-3 font-medium">Tier</th>
                            <th class="px-4 py-3 font-medium text-right">Lifetime top-up</th>
                            <th class="px-4 py-3 font-medium text-right">Loyalty points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agents)): ?>
                            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No agents yet.</td></tr>
                        <?php else: foreach ($agents as $a): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-800"><?= htmlspecialchars($fmtName($a)) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)$a['user_id']) ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200"><?= htmlspecialchars((string)$a['tier_name']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= htmlspecialchars($defaultCurrency) ?> <?= number_format((float)$a['lifetime_topup'], 2) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium"><?= number_format((int)$a['loyalty_points']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Customers with points -->
    <div class="card p-0">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">loyalty</span><h3>Customers with loyalty points</h3></div></div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium text-right">Loyalty points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr><td colspan="2" class="px-4 py-8 text-center text-slate-500">No customers have earned points yet.</td></tr>
                        <?php else: foreach ($customers as $c): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-800"><?= htmlspecialchars($fmtName($c)) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)$c['user_id']) ?></div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium"><?= number_format((int)$c['loyalty_points']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
