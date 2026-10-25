<?php
// app/views/admin/agent-api/index.php — admin queue for the agent API paid service.
// Vars: $rows (agent_api_access rows enriched with agent/agency/key counts).
@$SECURE or die('Access Denied!');
$badge = function($s){
    $m = ['requested'=>'bg-amber-50 text-amber-700','sandbox'=>'bg-blue-50 text-blue-700','certifying'=>'bg-indigo-50 text-indigo-700','live'=>'bg-emerald-50 text-emerald-700','rejected'=>'bg-red-50 text-red-700','suspended'=>'bg-slate-200 text-slate-600','none'=>'bg-slate-100 text-slate-500'];
    return $m[$s] ?? 'bg-slate-100 text-slate-500';
};
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Agent API Access</h1>
    <p class="text-sm text-slate-500 mb-5">Paid API service: request → sandbox → certify → live. You advance each stage; agents can only mint keys for the environment you've granted.</p>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <?php $active = array_filter($rows, fn($r)=> ($r['status']??'none')!=='none'); ?>
    <?php if (empty($active)): ?>
        <p class="text-sm text-slate-400">No API access requests yet.</p>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($active as $r): $s = (string)($r['status']??'none'); ?>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-slate-800"><?= htmlspecialchars((string)($r['agency_name'] ?: ($r['agent'] ?? $r['owner_user_id']))) ?></span>
                        <span class="px-2 py-0.5 rounded-full text-[11px] <?= $badge($s) ?>"><?= htmlspecialchars($s) ?></span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars((string)($r['agent_email'] ?? '')) ?> · sandbox keys: <?= (int)($r['sandbox_keys']??0) ?> · live keys: <?= (int)($r['live_keys']??0) ?></p>
                    <?php if (!empty($r['use_case'])): ?><p class="text-xs text-slate-600 mt-2 max-w-2xl"><strong>Use case:</strong> <?= htmlspecialchars((string)$r['use_case']) ?></p><?php endif; ?>
                    <?php if (!empty($r['expected_volume'])): ?><p class="text-[11px] text-slate-400">Expected volume: <?= htmlspecialchars((string)$r['expected_volume']) ?></p><?php endif; ?>
                    <?php if (!empty($r['admin_note'])): ?><p class="text-[11px] text-slate-400 mt-1">Note: <?= htmlspecialchars((string)$r['admin_note']) ?></p><?php endif; ?>
                </div>
                <div class="text-[11px] text-slate-400 text-right">
                    <?php if (!empty($r['requested_at'])): ?><div>requested <?= htmlspecialchars((string)$r['requested_at']) ?></div><?php endif; ?>
                    <?php if (!empty($r['sandbox_granted_at'])): ?><div>sandbox <?= htmlspecialchars((string)$r['sandbox_granted_at']) ?></div><?php endif; ?>
                    <?php if (!empty($r['live_granted_at'])): ?><div>live <?= htmlspecialchars((string)$r['live_granted_at']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mt-3">
                <?php
                // Offer actions valid from the current state.
                $actions = [];
                if (in_array($s, ['requested','certifying','suspended'], true)) $actions[] = ['grant_sandbox','Grant sandbox','bg-blue-600'];
                if (in_array($s, ['certifying','sandbox','suspended'], true))   $actions[] = ['grant_live','Grant live','bg-emerald-600'];
                if (in_array($s, ['requested','certifying'], true))             $actions[] = ['reject','Reject','bg-red-600'];
                if (in_array($s, ['sandbox','certifying','live'], true))        $actions[] = ['suspend','Suspend','bg-slate-600'];
                foreach ($actions as [$act,$lbl,$col]):
                ?>
                <form method="post" action="<?= root.admin ?>/agent-api/act" class="inline" onsubmit="this.admin_note.value=prompt('Optional note to the agency:')||'';">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="owner_user_id" value="<?= htmlspecialchars((string)$r['owner_user_id']) ?>">
                    <input type="hidden" name="action" value="<?= htmlspecialchars($act) ?>">
                    <input type="hidden" name="admin_note" value="">
                    <button class="px-3 py-1.5 rounded-lg text-white text-xs font-semibold <?= $col ?> hover:opacity-90"><?= htmlspecialchars($lbl) ?></button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
