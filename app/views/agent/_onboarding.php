<?php
// app/views/agent/_onboarding.php — reusable onboarding checklist fragment.
// Expects $onboarding (from agent_onboarding_state). Optional $onboardingCompact.
@$SECURE or die('Access Denied!');
if (empty($onboarding) || !empty($onboarding['complete'])) { return; }
$compact = !empty($onboardingCompact);
$next = $onboarding['next'] ?? null;
?>
<div class="rounded-xl border border-violet-200 bg-violet-50/60 p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="font-semibold text-slate-800">Finish setting up your agency</h2>
            <p class="text-xs text-slate-500"><?= (int)$onboarding['completed'] ?>/<?= (int)$onboarding['total'] ?> done<?= $next ? ' · Next: '.htmlspecialchars((string)$next['label']) : '' ?></p>
        </div>
        <?php if ($compact): ?><a href="<?= root ?>agent/onboarding" class="text-xs text-violet-700 font-semibold hover:underline">Open guide</a><?php endif; ?>
    </div>
    <div class="w-full h-2 rounded-full bg-violet-100 overflow-hidden mb-4">
        <div class="h-full bg-violet-600 transition-all" style="width: <?= (int)$onboarding['percent'] ?>%"></div>
    </div>
    <ul class="space-y-2">
        <?php foreach ($onboarding['steps'] as $i => $st): $done = !empty($st['done']); $isNext = $next && $st['key']===$next['key']; ?>
        <li class="flex items-center gap-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[13px] <?= $done?'bg-emerald-500 text-white':($isNext?'bg-violet-600 text-white':'bg-white border border-slate-300 text-slate-500') ?>">
                <?php if ($done): ?><span class="material-symbols-outlined text-[16px]">check</span><?php else: ?><?= (int)$i+1 ?><?php endif; ?>
            </span>
            <div class="flex-1">
                <span class="text-sm <?= $done?'text-slate-400 line-through':'text-slate-700' ?>"><?= htmlspecialchars((string)$st['label']) ?></span>
                <?php if (!$compact && !empty($st['hint'])): ?><span class="block text-[11px] text-slate-400"><?= htmlspecialchars((string)$st['hint']) ?></span><?php endif; ?>
            </div>
            <?php if (!$done && !empty($st['cta'])): ?>
            <a href="<?= htmlspecialchars((string)$st['cta']) ?>" class="text-xs px-3 py-1.5 rounded-lg <?= $isNext?'bg-violet-600 text-white hover:bg-violet-700':'border border-slate-300 text-slate-600 hover:bg-white' ?>">Do it</a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
