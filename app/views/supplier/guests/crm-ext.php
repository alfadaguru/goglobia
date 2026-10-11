<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Guest CRM — segmentation + lifetime value + tags + duplicate detection (PMS #18 ext).
// Vars: $rows (segment-tagged guests, GLV-ranked), $counts, $catalogue (tags), $dupes.
$rows      = (isset($rows) && is_array($rows)) ? $rows : [];
$counts    = (isset($counts) && is_array($counts)) ? $counts : [];
$catalogue = (isset($catalogue) && is_array($catalogue)) ? $catalogue : [];
$dupes     = (isset($dupes) && is_array($dupes)) ? $dupes : [];
$seg       = trim((string) ($_GET['segment'] ?? ''));
$tag       = strtolower(trim((string) ($_GET['tag'] ?? '')));
$base      = root . 'supplier/guests-crm';
$guestBase = root . 'supplier/guests';
$segMeta = [
  'vip'    => ['label' => 'VIP',        'tone' => 'bg-amber-100 text-amber-700'],
  'high'   => ['label' => 'High value', 'tone' => 'bg-violet-100 text-violet-700'],
  'lapsed' => ['label' => 'Lapsed',     'tone' => 'bg-rose-100 text-rose-700'],
  'repeat' => ['label' => 'Repeat',     'tone' => 'bg-emerald-100 text-emerald-700'],
  'new'    => ['label' => 'New',        'tone' => 'bg-sky-100 text-sky-700'],
];
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Guest CRM</h1>
      <p class="text-sm text-gray-600">Segments, lifetime value, tags and likely duplicates across all your properties.</p>
    </div>
    <a href="<?= htmlspecialchars($guestBase) ?>" class="btn secondary text-sm">All guests</a>
  </div>

  <!-- Segment summary chips (click to filter) -->
  <div class="flex flex-wrap gap-2">
    <a href="<?= htmlspecialchars($base) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $seg === '' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">All</a>
    <?php foreach ($segMeta as $k => $m): ?>
      <?php $qs = $base . '?segment=' . rawurlencode($k) . ($tag !== '' ? '&tag=' . rawurlencode($tag) : ''); ?>
      <a href="<?= htmlspecialchars($qs) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium flex items-center gap-1.5 <?= $seg === $k ? 'ring-2 ring-gray-900 ' . $m['tone'] : $m['tone'] . ' hover:opacity-80' ?>">
        <?= htmlspecialchars($m['label']) ?>
        <span class="px-1.5 py-0.5 rounded bg-white/60 text-[11px] tabular-nums"><?= (int) ($counts[$k] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Tag filter -->
  <?php if (!empty($catalogue)): ?>
  <div class="card p-4">
    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Tags</h2>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($catalogue as $t): ?>
        <?php $tk = (string) $t['tag']; $qs = $base . '?tag=' . rawurlencode($tk) . ($seg !== '' ? '&segment=' . rawurlencode($seg) : ''); ?>
        <a href="<?= htmlspecialchars($qs) ?>" class="px-2.5 py-1 rounded-full text-xs font-medium <?= $tag === $tk ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
          <?= htmlspecialchars($tk) ?> <span class="opacity-60">(<?= (int) $t['count'] ?>)</span>
        </a>
      <?php endforeach; ?>
      <?php if ($tag !== ''): ?><a href="<?= htmlspecialchars($base . ($seg !== '' ? '?segment=' . rawurlencode($seg) : '')) ?>" class="px-2.5 py-1 text-xs text-gray-500 hover:underline">Clear tag</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Duplicate detection -->
  <?php if (!empty($dupes)): ?>
  <div class="card p-5 border-l-4 border-amber-400">
    <h2 class="text-sm font-semibold text-gray-900 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-amber-500 text-base">join_inner</span> Possible duplicate guests</h2>
    <p class="text-xs text-gray-500 mb-3">Same person booking under more than one email — matched by phone or name. Review before merging manually.</p>
    <div class="space-y-3">
      <?php foreach ($dupes as $grp): ?>
        <div class="rounded-lg border border-gray-100 p-3">
          <div class="text-xs text-gray-500 mb-2">Matched by <span class="font-medium text-gray-700"><?= htmlspecialchars((string) $grp['match']) ?></span>: <span class="font-mono"><?= htmlspecialchars((string) $grp['value']) ?></span></div>
          <div class="flex flex-wrap gap-2">
            <?php foreach (($grp['members'] ?? []) as $m): ?>
              <a href="<?= htmlspecialchars($guestBase . '/' . ($m['token'] ?? '')) ?>" class="px-2.5 py-1.5 rounded-lg bg-gray-50 hover:bg-gray-100 text-xs">
                <span class="font-medium text-gray-900"><?= htmlspecialchars($m['name'] ?: $m['email']) ?></span>
                <span class="text-gray-400 block"><?= htmlspecialchars((string) $m['email']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ranked guest table -->
  <?php if (empty($rows)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">groups</span>
      <p class="mt-2 text-sm">No guests match this view.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Guest</th>
          <th class="px-4 py-3 font-medium">Segment</th>
          <th class="px-4 py-3 font-medium">Tags</th>
          <th class="px-4 py-3 font-medium">Stays</th>
          <th class="px-4 py-3 font-medium">Lifetime value</th>
          <th class="px-4 py-3 font-medium">Last stay</th>
          <th class="px-4 py-3 font-medium"></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($rows as $g): ?>
            <?php $s = $g['_segment'] ?? ['label' => 'New', 'tone' => 'sky']; $tone = $segMeta[$s['key'] ?? 'new']['tone'] ?? 'bg-sky-100 text-sky-700'; ?>
            <tr>
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($g['name'] ?: $g['email']) ?></div>
                <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $g['email']) ?></div>
              </td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-[11px] font-semibold <?= $tone ?>"><?= htmlspecialchars((string) $s['label']) ?></span></td>
              <td class="px-4 py-3">
                <?php foreach (array_slice($g['_tags'] ?? [], 0, 4) as $t): ?><span class="inline-block px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] mr-1"><?= htmlspecialchars((string) $t) ?></span><?php endforeach; ?>
              </td>
              <td class="px-4 py-3 tabular-nums"><?= (int) $g['stays'] ?></td>
              <td class="px-4 py-3 tabular-nums font-medium"><?= htmlspecialchars((string) $g['currency']) ?> <?= number_format((float) ($g['_glv'] ?? 0), 2) ?></td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $g['last_seen'], 0, 10)) ?></td>
              <td class="px-4 py-3"><a href="<?= htmlspecialchars($guestBase . '/' . $g['token'] . '/360') ?>" class="text-blue-600 hover:underline text-xs font-medium">360 view</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Segments and lifetime value are derived from paid, non-cancelled stays across the most recent bookings.</p>
  <?php endif; ?>
</div>
