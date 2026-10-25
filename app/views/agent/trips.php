<?php
// app/views/agent/trips.php — trip plans list. Vars: $trips, $canEdit.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Trip Plans</h1>
             <p class="text-sm text-slate-500">Build multi-service itineraries and send branded proposals to clients.</p></div>
        <?php if (!empty($canEdit) && agent_can($db,'trips','add')): ?>
        <a href="<?= root ?>agent/trips/new" class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">New trip plan</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <?php if (empty($trips)): ?>
            <p class="px-5 py-8 text-sm text-slate-500 text-center">No trip plans yet. <a href="<?= root ?>agent/trips/new" class="text-violet-600 hover:underline">Build your first.</a></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Trip</th><th class="text-left px-5 py-2">Client</th><th class="text-left px-5 py-2">Status</th><th class="text-right px-5 py-2">Total</th><th class="px-5 py-2"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($trips as $t): $tot = json_decode((string)($t['totals_data']??'{}'),true) ?: []; ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2"><a href="<?= root ?>agent/trips/<?= htmlspecialchars((string)$t['ref']) ?>" class="text-violet-600 hover:underline font-medium"><?= htmlspecialchars((string)$t['title']) ?></a><span class="block text-[11px] text-slate-400 font-mono"><?= htmlspecialchars((string)$t['ref']) ?></span></td>
                        <td class="px-5 py-2 text-slate-600"><?= htmlspecialchars((string)($t['client_name']??'—')) ?></td>
                        <td class="px-5 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= in_array(($t['status']??''),['booked','accepted'],true)?'bg-emerald-50 text-emerald-700':(($t['status']??'')==='sent'?'bg-blue-50 text-blue-700':'bg-slate-100 text-slate-600') ?>"><?= htmlspecialchars((string)($t['status']??'')) ?></span></td>
                        <td class="px-5 py-2 text-right text-slate-700"><?= htmlspecialchars((string)($t['currency']??'')) ?> <?= $fmt($tot['total'] ?? 0) ?></td>
                        <td class="px-5 py-2 text-right"><a href="<?= root ?>agent/trips/<?= htmlspecialchars((string)$t['ref']) ?>" class="text-xs text-violet-600 hover:underline">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
