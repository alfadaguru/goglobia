<?php
// app/views/admin/agents/index.php — admin travel-agent approval queue.
// Vars: $pending, $others, $agencyByUser.
@$SECURE or die('Access Denied!');
$name = fn($u) => trim(((string)($u['first_name']??'')).' '.((string)($u['last_name']??''))) ?: '—';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Travel Agents</h1>
    <p class="text-sm text-slate-500 mb-5">Approve new agencies before they can trade, fund a wallet, or issue branded documents.</p>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <h2 class="font-semibold text-slate-700 mb-2">Pending approval (<?= count($pending) ?>)</h2>
    <?php if (empty($pending)): ?>
        <p class="text-sm text-slate-400 mb-6">No pending agent applications.</p>
    <?php else: ?>
    <div class="grid md:grid-cols-2 gap-4 mb-8">
        <?php foreach ($pending as $u): $ag = $agencyByUser[(string)$u['user_id']] ?? null; ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4">
            <div class="flex items-start justify-between">
                <div>
                    <p class="font-semibold text-slate-800"><?= htmlspecialchars($name($u)) ?></p>
                    <p class="text-xs text-slate-500"><?= htmlspecialchars((string)($u['email']??'')) ?> · <?= htmlspecialchars((string)($u['phone']??'')) ?></p>
                    <?php if ($ag): ?><p class="text-xs text-slate-600 mt-1"><strong><?= htmlspecialchars((string)($ag['agency_name']??'')) ?></strong> · Lic <?= htmlspecialchars((string)($ag['license_number']??'—')) ?> · <?= htmlspecialchars((string)($ag['country']??'')) ?></p><?php endif; ?>
                </div>
                <span class="text-[11px] text-slate-400"><?= htmlspecialchars((string)($u['created_at']??'')) ?></span>
            </div>
            <div class="flex gap-2 mt-3">
                <form method="post" action="<?= root.admin ?>/agents/approve/<?= urlencode((string)$u['user_id']) ?>">
                    <?= CSRF::tokenField() ?>
                    <button class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700">Approve</button>
                </form>
                <form method="post" action="<?= root.admin ?>/agents/reject/<?= urlencode((string)$u['user_id']) ?>" onsubmit="this.reason.value=prompt('Reason for rejection (optional):')||'';">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="reason" value="">
                    <button class="px-3 py-1.5 rounded-lg border border-red-300 text-red-700 text-xs font-semibold hover:bg-red-50">Reject</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="font-semibold text-slate-700 mb-2">All agents</h2>
    <div class="rounded-xl border border-slate-200 bg-white overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                <tr><th class="text-left px-5 py-2">Name</th><th class="text-left px-5 py-2">Email</th><th class="text-left px-5 py-2">Agency</th><th class="text-left px-5 py-2">Status</th><th class="text-left px-5 py-2">Joined</th></tr>
            </thead>
            <tbody>
            <?php foreach ($others as $u): $ag = $agencyByUser[(string)$u['user_id']] ?? null; ?>
                <tr class="border-t border-slate-50">
                    <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars($name($u)) ?></td>
                    <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($u['email']??'')) ?></td>
                    <td class="px-5 py-2 text-slate-600"><?= htmlspecialchars((string)($ag['agency_name']??'—')) ?></td>
                    <td class="px-5 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= ($u['status']??'')==='active'?'bg-emerald-50 text-emerald-700':(($u['status']??'')==='rejected'?'bg-red-50 text-red-700':'bg-slate-100 text-slate-600') ?>"><?= htmlspecialchars((string)($u['status']??'')) ?></span></td>
                    <td class="px-5 py-2 text-slate-400"><?= htmlspecialchars((string)($u['created_at']??'')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($others)): ?><tr><td colspan="5" class="px-5 py-6 text-center text-slate-400">No agents yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
