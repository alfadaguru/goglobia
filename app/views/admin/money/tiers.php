<?php
// ADMIN — Agent Member Tiers + Loyalty scheme (docs/MONEY-WALLET-AUDIT.md §C.4 steps 4 & 5)
// Provided by app/routes/admin/moneyRoutes.php: $tiers, $settings, $defaultCurrency
@$SECURE or die('Access Denied!');

$tiers           = $tiers ?? [];
$settings        = $settings ?? [];
$defaultCurrency = $defaultCurrency ?? 'USD';

$loyaltyEnabled  = (int)($settings['loyalty_enabled'] ?? 0) === 1;
$earnCustomer    = (float)($settings['loyalty_earn_customer'] ?? 0.01);
$earnAgent       = (float)($settings['loyalty_earn_agent'] ?? 0.005);
$redeemValue     = (float)($settings['loyalty_redeem_value'] ?? 1.0);
?>

<div class="container my-4" x-data="{
        showForm: false,
        editing: null,
        blank: { id: 0, code: '', name: '', sort_order: 0, min_lifetime_topup: 0, discount_percent: 0, active: '1' },
        form: {},
        openAdd() { this.form = Object.assign({}, this.blank); this.editing = null; this.showForm = true; },
        openEdit(t) { this.form = Object.assign({}, t); this.editing = t.id; this.showForm = true; },
        async del(id) {
            if (!confirm('Delete this tier? Agents on it will be recomputed on their next top-up.')) return;
            const r = await fetch('<?= root . admin ?>/finance/tiers/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>' },
                body: JSON.stringify({ id: id, csrf_token: '<?= CSRF::getToken() ?>' })
            });
            const j = await r.json();
            if (j.success) { location.reload(); } else { alert(j.message || 'Delete failed'); }
        }
    }">

    <!-- Page header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Agent Tiers &amp; Loyalty</h1>
            <p class="text-sm text-slate-600 mt-1">
                Reward agents for lifetime wallet top-ups with a better markup, and award loyalty points to customers and agents on paid bookings.
            </p>
        </div>
        <button type="button" class="btn primary inline-flex items-center gap-2" @click="openAdd()">
            <span class="material-symbols-outlined text-xl">add</span> Add Tier
        </button>
    </div>

    <!-- Flash message -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- ================= AGENT MEMBER TIERS ================= -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon text-[18px]">workspace_premium</span>
                <h3>Agent Member Tiers</h3>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Order</th>
                            <th class="px-4 py-3 font-medium">Tier</th>
                            <th class="px-4 py-3 font-medium">Code</th>
                            <th class="px-4 py-3 font-medium text-right">Min lifetime top-up (<?= htmlspecialchars($defaultCurrency) ?>)</th>
                            <th class="px-4 py-3 font-medium text-right">Markup discount</th>
                            <th class="px-4 py-3 font-medium text-center">Status</th>
                            <th class="px-4 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tiers)): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No tiers defined yet.</td></tr>
                        <?php else: foreach ($tiers as $t): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-500"><?= (int)$t['sort_order'] ?></td>
                                <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars($t['name']) ?></td>
                                <td class="px-4 py-3 text-slate-500"><code><?= htmlspecialchars($t['code']) ?></code></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= number_format((float)$t['min_lifetime_topup'], 2) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= rtrim(rtrim(number_format((float)$t['discount_percent'], 2), '0'), '.') ?>%</td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ((int)$t['active'] === 1): ?>
                                        <span class="badge success">Active</span>
                                    <?php else: ?>
                                        <span class="badge">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" class="text-slate-500 hover:text-primary p-1" title="Edit"
                                            @click='openEdit(<?= json_encode([
                                                "id" => (int)$t["id"], "code" => $t["code"], "name" => $t["name"],
                                                "sort_order" => (int)$t["sort_order"],
                                                "min_lifetime_topup" => (float)$t["min_lifetime_topup"],
                                                "discount_percent" => (float)$t["discount_percent"],
                                                "active" => (string)((int)$t["active"]),
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <span class="material-symbols-outlined text-lg">edit</span>
                                    </button>
                                    <button type="button" class="text-slate-500 hover:text-red-600 p-1" title="Delete"
                                            @click="del(<?= (int)$t['id'] ?>)">
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================= LOYALTY SCHEME ================= -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon text-[18px]">loyalty</span>
                <h3>Loyalty Points</h3>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= root . admin ?>/finance/loyalty/save">
                <?= CSRF::tokenField() ?>
                <div class="flex items-center gap-3 mb-5">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="loyalty_enabled" value="1" class="checkbox" <?= $loyaltyEnabled ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-slate-700">Enable loyalty points</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Customer earn rate</label>
                        <input type="number" step="0.0001" min="0" name="loyalty_earn_customer" class="input"
                               value="<?= htmlspecialchars(rtrim(rtrim(number_format($earnCustomer, 4, '.', ''), '0'), '.') ?: '0') ?>">
                        <span class="text-xs text-slate-500">Points earned per 1 <?= htmlspecialchars($defaultCurrency) ?> a customer pays (e.g. 0.01 = 1 pt per 100).</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Agent earn rate</label>
                        <input type="number" step="0.0001" min="0" name="loyalty_earn_agent" class="input"
                               value="<?= htmlspecialchars(rtrim(rtrim(number_format($earnAgent, 4, '.', ''), '0'), '.') ?: '0') ?>">
                        <span class="text-xs text-slate-500">Points earned per 1 <?= htmlspecialchars($defaultCurrency) ?> an agent pays.</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Redeem value</label>
                        <input type="number" step="0.0001" min="0" name="loyalty_redeem_value" class="input"
                               value="<?= htmlspecialchars(rtrim(rtrim(number_format($redeemValue, 4, '.', ''), '0'), '.') ?: '0') ?>">
                        <span class="text-xs text-slate-500"><?= htmlspecialchars($defaultCurrency) ?> credited to the wallet per 1 point redeemed.</span>
                    </div>
                </div>

                <div class="mt-5">
                    <button type="submit" class="btn primary inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl">save</span> Save Loyalty Scheme
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= ADD / EDIT TIER MODAL ================= -->
    <div x-show="showForm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(15,23,42,.45)" @click.self="showForm = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg" @keydown.escape.window="showForm = false">
            <form method="POST" action="<?= root . admin ?>/finance/tiers/save">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="id" :value="form.id">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-semibold text-slate-800" x-text="editing ? 'Edit Tier' : 'Add Tier'"></h3>
                    <button type="button" class="text-slate-400 hover:text-slate-700" @click="showForm = false">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Name</label>
                        <input type="text" name="name" class="input" x-model="form.name" required placeholder="e.g. Gold">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Code</label>
                        <input type="text" name="code" class="input" x-model="form.code" required placeholder="e.g. gold"
                               pattern="[a-z0-9_]{2,32}" title="2–32 chars: a–z, 0–9, underscore">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Sort order</label>
                        <input type="number" name="sort_order" class="input" x-model="form.sort_order" step="1">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Status</label>
                        <select name="active" class="select input" x-model="form.active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Min lifetime top-up (<?= htmlspecialchars($defaultCurrency) ?>)</label>
                        <input type="number" name="min_lifetime_topup" class="input" x-model="form.min_lifetime_topup" step="0.01" min="0">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Markup discount (%)</label>
                        <input type="number" name="discount_percent" class="input" x-model="form.discount_percent" step="0.01" min="0" max="100">
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-200">
                    <button type="button" class="btn secondary" @click="showForm = false">Cancel</button>
                    <button type="submit" class="btn primary">Save Tier</button>
                </div>
            </form>
        </div>
    </div>
</div>
