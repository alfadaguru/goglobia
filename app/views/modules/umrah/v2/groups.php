<?php
// UMRAH v2 — agent group dashboard (Phase C). Expects: $groups, $umrahCsrf.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$statusBadge = [
  'draft' => 'badge-gray', 'pending' => 'badge-warning', 'paid' => 'badge-success',
  'submitted' => 'badge-success', 'processing' => 'badge-warning',
  'confirmed' => 'badge-success', 'cancelled' => 'badge-error',
];
?>
<div class="bg-slate-50 min-h-screen">
  <div class="container py-8 max-w-4xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">My Umrah groups</h1>
        <p class="text-sm text-slate-500">Create groups, add pilgrims, submit &amp; pay from your wallet.</p>
      </div>
      <a href="<?= root ?>umrah/search" class="btn"><span class="material-symbols-outlined text-[18px]">add</span> New group from a package</a>
    </div>

    <?php if (empty($groups)): ?>
      <div class="card border text-center py-10">
        <span class="material-symbols-outlined text-4xl text-slate-300">groups</span>
        <p class="text-slate-600 mt-2">No groups yet. Open a package and choose <strong>Create a group</strong>.</p>
        <a href="<?= root ?>umrah/search" class="btn mt-4">Browse packages</a>
      </div>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($groups as $g): ?>
          <a href="<?= root ?>umrah/groups/<?= (int) $g['id'] ?>" class="card border flex items-center justify-between gap-4 hover:shadow-md transition-all">
            <div class="min-w-0">
              <div class="font-semibold text-slate-900"><?= htmlspecialchars($g['name'] ?: ('Group ' . $g['group_ref'])) ?></div>
              <div class="text-xs text-slate-500 font-mono"><?= htmlspecialchars($g['group_ref']) ?></div>
              <div class="text-sm text-slate-600 mt-0.5">
                <?= strtoupper(htmlspecialchars((string) $g['tier_code'])) ?> ·
                <?= (int) $g['pax_count'] ?> pilgrim<?= (int) $g['pax_count'] === 1 ? '' : 's' ?> ·
                <?= $fmt($g['total_price']) ?>
              </div>
            </div>
            <div class="text-right shrink-0">
              <span class="badge <?= $statusBadge[$g['status']] ?? 'badge-gray' ?>"><?= ucfirst(htmlspecialchars($g['status'])) ?></span>
              <?php if ($g['status'] === 'submitted' || $g['status'] === 'processing' || $g['status'] === 'confirmed'): ?>
                <div class="text-[11px] text-slate-500 mt-1">Visa: <?= htmlspecialchars($g['visa_status']) ?></div>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
