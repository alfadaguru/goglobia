<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Reusable go-live checklist fragment (inc S13). Expects $onboarding =
// supplier_onboarding_state(...) in scope, and optional $onboardingCompact (bool)
// to render the dashboard's condensed variant. Renders nothing if state is missing
// or already complete (nothing left to nudge).
$ob = $onboarding ?? null;
if (!is_array($ob) || empty($ob['steps']) || !empty($ob['complete'])) { return; }
$compact = !empty($onboardingCompact);
$pct = (int) ($ob['percent'] ?? 0);
$done = (int) ($ob['completed'] ?? 0);
$total = (int) ($ob['total'] ?? 0);
$next = $ob['next'] ?? null;
?>
<div class="card p-5">
  <div class="flex items-start justify-between gap-3 mb-4">
    <div>
      <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-violet-600">rocket_launch</span> Get your first listing live
      </h2>
      <p class="text-sm text-gray-600">
        <span class="font-semibold tabular-nums"><?= $done ?></span> of
        <span class="font-semibold tabular-nums"><?= $total ?></span> steps done.
        <?php if ($next): ?>Next: <span class="font-medium text-gray-800"><?= htmlspecialchars($next['label']) ?></span>.<?php endif; ?>
      </p>
    </div>
    <?php if ($compact): ?>
      <a href="<?= root ?>supplier/get-started" class="text-sm text-violet-600 hover:underline flex-shrink-0">Open guide</a>
    <?php endif; ?>
  </div>

  <!-- Progress meter -->
  <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden mb-4" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full transition-all" style="width: <?= $pct ?>%; background-color:#7c3aed;"></div>
  </div>

  <ol class="space-y-3">
    <?php foreach ($ob['steps'] as $i => $s): ?>
      <?php
        $isDone = !empty($s['done']);
        $isNext = $next && $s['key'] === $next['key'];
      ?>
      <li class="flex items-start gap-3">
        <span class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-semibold
          <?= $isDone ? 'bg-green-100 text-green-700' : ($isNext ? 'text-white' : 'bg-gray-100 text-gray-500') ?>"
          <?= $isNext ? 'style="background-color:#7c3aed;"' : '' ?>>
          <?php if ($isDone): ?><span class="material-symbols-outlined text-sm">check</span><?php else: ?><?= $i + 1 ?><?php endif; ?>
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium <?= $isDone ? 'text-gray-500 line-through' : 'text-gray-900' ?>"><?= htmlspecialchars($s['label']) ?></span>
            <?php if ($isNext): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-violet-50 text-violet-700 uppercase tracking-wide">Next</span><?php endif; ?>
          </div>
          <?php if (!$compact): ?>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($s['hint']) ?></p>
          <?php endif; ?>
          <?php if (!$isDone && !empty($s['cta_url'])): ?>
            <a href="<?= htmlspecialchars($s['cta_url']) ?>" class="inline-flex items-center gap-1 mt-1.5 text-xs font-semibold text-violet-600 hover:underline">
              <?= htmlspecialchars($s['cta_label'] ?? 'Continue') ?> <span class="material-symbols-outlined text-sm">arrow_forward</span>
            </a>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
</div>
