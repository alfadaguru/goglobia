<?php
// app/views/agent/activity.php — agency activity log. Vars: $rows, $names.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-4xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Activity log</h1>
    <p class="text-sm text-slate-500 mb-5">A trail of actions taken across your agency.</p>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <?php if (empty($rows)): ?>
            <p class="px-5 py-8 text-sm text-slate-500 text-center">No activity recorded yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">When</th><th class="text-left px-5 py-2">Who</th><th class="text-left px-5 py-2">Action</th><th class="text-left px-5 py-2">Detail</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): $a = (string)($r['actor_user_id']??''); ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)($r['created_at']??'')) ?></td>
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars((string)($names[$a] ?? $a ?: '—')) ?></td>
                        <td class="px-5 py-2"><span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px] font-mono"><?= htmlspecialchars((string)($r['action']??'')) ?></span></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($r['detail']??'')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
