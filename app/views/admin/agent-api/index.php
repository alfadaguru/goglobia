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
            <?php if (!empty($r['paid_at'])): ?>
            <p class="text-[12px] text-emerald-600 mt-2">Access fee paid<?= !empty($r['fee_amount']) ? ' ('.htmlspecialchars((string)($r['fee_currency']??'')).' '.number_format((float)$r['fee_amount'],2).')' : '' ?> · <?= htmlspecialchars((string)$r['paid_at']) ?></p>
            <?php elseif ($s === 'approved'): ?>
            <p class="text-[12px] text-amber-600 mt-2">Approved — awaiting the agency's payment of <?= htmlspecialchars((string)($r['fee_currency']??'NGN')) ?> <?= number_format((float)($r['fee_amount']??0),2) ?>.</p>
            <?php endif; ?>

            <?php if ($s === 'requested'): ?>
            <!-- APPROVE with a fee: moves requested → approved; agent then pays. -->
            <form method="post" action="<?= root.admin ?>/agent-api/act" class="flex flex-wrap items-end gap-2 mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="owner_user_id" value="<?= htmlspecialchars((string)$r['owner_user_id']) ?>">
                <input type="hidden" name="action" value="approve">
                <div><label class="block text-[10px] uppercase text-slate-400 mb-0.5">Access fee</label>
                    <input type="number" name="fee_amount" min="0" step="0.01" value="0" class="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"></div>
                <div><label class="block text-[10px] uppercase text-slate-400 mb-0.5">Currency</label>
                    <input type="text" name="fee_currency" value="NGN" maxlength="10" class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"></div>
                <input type="text" name="admin_note" placeholder="Note (optional)" class="flex-1 min-w-[140px] rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                <button class="px-3 py-1.5 rounded-lg text-white text-xs font-semibold bg-violet-600 hover:opacity-90">Approve + set fee</button>
            </form>
            <?php endif; ?>

            <div class="flex flex-wrap gap-2 mt-3">
                <?php
                // Offer actions valid from the current state.
                $actions = [];
                if (in_array($s, ['requested','approved','certifying','suspended'], true)) $actions[] = ['grant_sandbox','Grant sandbox (skip fee)','bg-blue-600'];
                if (in_array($s, ['certifying','sandbox','suspended'], true))   $actions[] = ['grant_live','Grant live','bg-emerald-600'];
                if (in_array($s, ['requested','approved','certifying'], true))   $actions[] = ['reject','Reject','bg-red-600'];
                if (in_array($s, ['approved','sandbox','certifying','live'], true)) $actions[] = ['suspend','Suspend','bg-slate-600'];
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

            <?php // Per-service certification + live sign-off (gated on a passed cert run). ?>
            <?php if (!empty($r['services'])): ?>
            <div class="mt-4 pt-3 border-t border-slate-100">
                <p class="text-[11px] uppercase text-slate-400 mb-2">Services — certification</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($r['services'] as $svcInfo):
                        $svcLife = (string)$svcInfo['lifecycle'];
                        $svcPassed = (int)$svcInfo['cert_passed'] === 1;
                        $svcBadge = ['sandbox'=>'bg-blue-50 text-blue-700','certifying'=>'bg-indigo-50 text-indigo-700','live'=>'bg-emerald-50 text-emerald-700','rejected'=>'bg-red-50 text-red-700','suspended'=>'bg-slate-200 text-slate-600'][$svcLife] ?? 'bg-slate-100 text-slate-500';
                    ?>
                    <div class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white">
                        <span class="text-xs font-medium text-slate-700 capitalize"><?= htmlspecialchars((string)$svcInfo['service']) ?></span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded <?= $svcBadge ?>"><?= htmlspecialchars($svcLife) ?></span>
                        <?php if ($svcLife === 'certifying'): ?>
                            <?php if ($svcPassed): ?>
                            <form method="post" action="<?= root.admin ?>/users/api-services/lifecycle" class="inline sign-live-form" data-svc="<?= htmlspecialchars((string)$svcInfo['service']) ?>" data-owner="<?= htmlspecialchars((string)$r['owner_user_id']) ?>">
                                <button type="button" class="px-2 py-0.5 rounded bg-emerald-600 text-white text-[11px] font-semibold hover:opacity-90" onclick="signLive(this)">Sign live ✓</button>
                            </form>
                            <?php else: ?>
                            <span class="text-[10px] text-amber-600" title="The agent must pass the cert checklist first">cert pending</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <script>
        // Sign a service live via the JSON lifecycle endpoint (the server re-checks
        // the cert-passed gate — this button only appears when it already passed).
        async function signLive(btn) {
            const form = btn.closest('.sign-live-form');
            if (!confirm('Sign ' + form.dataset.svc + ' LIVE for this agency?')) return;
            const fd = new FormData();
            fd.append('csrf_token', '<?= htmlspecialchars(CSRF::getToken(), ENT_QUOTES) ?>');
            fd.append('user_id', form.dataset.owner);
            fd.append('service', form.dataset.svc);
            fd.append('lifecycle', 'live');
            btn.disabled = true;
            try {
                const res = await fetch(form.action, { method:'POST', body: fd });
                const j = await res.json();
                alert(j.message || (j.success ? 'Signed live.' : 'Failed.'));
                if (j.success) location.reload();
                else btn.disabled = false;
            } catch (e) { alert('Request failed.'); btn.disabled = false; }
        }
        </script>
    </div>
    <?php endif; ?>
</div>
