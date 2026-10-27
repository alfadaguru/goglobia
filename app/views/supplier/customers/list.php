<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Customer Book — list + search + type filter (Front-desk overhaul Phase 1).
// $contacts (rows), $counts (per-type), $types (type=>label), $canEdit.
$contacts = (isset($contacts) && is_array($contacts)) ? $contacts : [];
$counts   = (isset($counts) && is_array($counts)) ? $counts : ['all' => 0];
$types    = (isset($types) && is_array($types)) ? $types : [];
$canEdit  = !empty($canEdit);
$q        = trim((string) ($_GET['q'] ?? ''));
$activeType = (string) ($_GET['type'] ?? '');
$base = root . 'supplier/customers';
$typeBadge = function ($t) {
    switch ($t) {
        case 'company': return 'bg-blue-100 text-blue-700';
        case 'ngo':     return 'bg-emerald-100 text-emerald-700';
        case 'agent':   return 'bg-violet-100 text-violet-700';
        default:        return 'bg-gray-100 text-gray-600';
    }
};
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
      <p class="text-sm text-gray-600">Your guests, companies and NGOs — one book, searchable by phone, name or email.</p>
    </div>
    <?php if ($canEdit): ?>
      <a href="<?= $base ?>/new" class="btn primary text-sm inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-base">person_add</span> New customer</a>
    <?php endif; ?>
  </div>

  <form method="GET" action="<?= $base ?>" class="flex flex-wrap items-center gap-2">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="input text-sm flex-1 min-w-[200px]" placeholder="Search by phone, name or email">
    <?php if ($activeType !== ''): ?><input type="hidden" name="type" value="<?= htmlspecialchars($activeType) ?>"><?php endif; ?>
    <button type="submit" class="btn secondary text-sm">Search</button>
    <?php if ($q !== ''): ?><a href="<?= $base . ($activeType !== '' ? '?type=' . urlencode($activeType) : '') ?>" class="text-sm text-gray-500 hover:underline">Clear</a><?php endif; ?>
  </form>

  <?php // P2b: possible-duplicate groups with a one-click merge (pick which to keep). ?>
  <?php $dupes = (isset($dupes) && is_array($dupes)) ? $dupes : []; ?>
  <?php if ($canEdit && !empty($dupes)): ?>
  <div class="card p-5 border-l-4 border-amber-400">
    <h2 class="text-sm font-semibold text-gray-900 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-amber-500 text-base">join_inner</span> Possible duplicate customers</h2>
    <p class="text-xs text-gray-500 mb-3">Same phone or name across more than one record. Choose which to keep; the other is merged in (its wallet balance &amp; history move to the one you keep).</p>
    <div class="space-y-3">
      <?php foreach ($dupes as $gi => $g): $members = $g['members'] ?? []; if (count($members) < 2) continue; ?>
        <form action="<?= $base ?>/merge" method="POST" class="rounded-lg bg-amber-50/60 p-3"
              onsubmit="return confirm('Merge these records into the one you selected? This can’t be undone.');">
          <?= CSRF::tokenField() ?>
          <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
            <?php foreach ($members as $mi => $m): ?>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="radio" name="survivor_id" value="<?= (int) $m['id'] ?>" <?= $mi === 0 ? 'checked' : '' ?> required>
                <span><strong><?= htmlspecialchars((string) $m['display_name']) ?></strong>
                  <span class="text-xs text-gray-500"><?= htmlspecialchars((string) ($m['phone'] ?? '')) ?><?= !empty($m['email']) ? ' · ' . htmlspecialchars((string) $m['email']) : '' ?> · <?= htmlspecialchars(ucfirst((string) $m['type'])) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php // Send the whole group's ids; the server merges every member EXCEPT the
                // chosen survivor into it (so any radio pick is correct, incl. 3+ groups). ?>
          <input type="hidden" name="group_ids" value='<?= htmlspecialchars(json_encode(array_map(fn($x) => (int) $x['id'], $members)), ENT_QUOTES) ?>'>
          <div class="mt-2"><button type="submit" class="btn secondary text-xs">Keep selected &amp; merge the rest</button>
            <span class="text-[11px] text-gray-400 ml-2">Keeps the one you pick; fills its blank fields from the other.</span></div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
    // Type filter chips (preserve the current search q).
    $chip = function ($type, $label, $n) use ($base, $q, $activeType) {
        $qs = [];
        if ($type !== '') { $qs['type'] = $type; }
        if ($q !== '') { $qs['q'] = $q; }
        $href = $base . (empty($qs) ? '' : '?' . http_build_query($qs));
        $on = ($activeType === $type);
        $cls = $on ? 'bg-violet-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-violet-300';
        return '<a href="' . htmlspecialchars($href) . '" class="px-3 py-1 rounded-full text-xs font-medium ' . $cls . '">' . htmlspecialchars($label) . ' <span class="opacity-70">' . (int) $n . '</span></a>';
    };
  ?>
  <div class="flex flex-wrap gap-2">
    <?= $chip('', 'All', $counts['all'] ?? 0) ?>
    <?php foreach ($types as $tk => $tl): ?>
      <?= $chip($tk, $tl, $counts[$tk] ?? 0) ?>
    <?php endforeach; ?>
  </div>

  <?php if (empty($contacts)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">contacts</span>
      <p class="mt-2 text-sm">No customers<?= ($q !== '' || $activeType !== '') ? ' match that filter' : ' yet' ?>.</p>
      <?php if ($canEdit && $q === '' && $activeType === ''): ?><p class="mt-1 text-xs">Add your first with <strong>New customer</strong>, or they appear as guests book.</p><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Name</th>
          <th class="px-4 py-3 font-medium">Type</th>
          <th class="px-4 py-3 font-medium">Phone</th>
          <th class="px-4 py-3 font-medium">Email</th>
          <th class="px-4 py-3 font-medium">Status</th>
          <th class="px-4 py-3 font-medium"></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($contacts as $c): ?>
            <tr>
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900 flex items-center gap-1.5">
                  <?= htmlspecialchars((string) $c['display_name']) ?>
                  <?php if (!empty($c['tax_exempt'])): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-700">Tax-exempt</span><?php endif; ?>
                </div>
              </td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $typeBadge((string) $c['type']) ?>"><?= htmlspecialchars($types[$c['type']] ?? ucfirst((string) $c['type'])) ?></span></td>
              <td class="px-4 py-3 text-gray-700 tabular-nums"><?= htmlspecialchars((string) ($c['phone'] ?? '')) ?></td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($c['email'] ?? '')) ?></td>
              <td class="px-4 py-3"><?php if (($c['status'] ?? 'active') === 'suspended'): ?><span class="text-xs text-red-500">Suspended</span><?php else: ?><span class="text-xs text-emerald-600">Active</span><?php endif; ?></td>
              <td class="px-4 py-3"><a href="<?= $base ?>/<?= (int) $c['id'] ?>" class="text-blue-600 hover:underline text-xs font-medium">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
