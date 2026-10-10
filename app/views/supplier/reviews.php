<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Owner review moderation (inc S31). $reviews, $propMap, $canEdit, status filter.
$reviews = (isset($reviews) && is_array($reviews)) ? $reviews : [];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit = !empty($canEdit);
$status  = strtolower((string) ($_GET['status'] ?? ''));
$base = root . 'supplier/reviews';
$stColor = ['pending' => 'bg-amber-50 text-amber-700', 'published' => 'bg-green-50 text-green-700', 'hidden' => 'bg-gray-100 text-gray-500'];
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
      <h1 class="text-2xl font-bold text-gray-900">Reviews</h1>
      <p class="text-sm text-gray-600">Moderate guest reviews. Published reviews set your public rating.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="status" class="input text-sm" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (['pending', 'published', 'hidden'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($reviews)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">reviews</span>
      <p class="mt-2 text-sm">No reviews<?= $status !== '' ? ' with that status' : ' yet' ?>.</p>
    </div>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($reviews as $r): $st = (string) $r['status']; ?>
        <div class="card p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="flex items-center gap-2">
                <span class="text-amber-400"><?= str_repeat('★', (int) $r['rating']) ?><span class="text-gray-200"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span>
                <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) ($r['guest_name'] ?? 'Guest')) ?: 'Guest' ?></span>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
              </div>
              <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?> · <?= htmlspecialchars(substr((string) $r['created_at'], 0, 10)) ?></div>
              <?php if (!empty($r['comment'])): ?><p class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?= htmlspecialchars((string) $r['comment']) ?></p><?php endif; ?>
            </div>
            <?php if ($canEdit): ?>
              <div class="flex flex-col gap-1.5 flex-shrink-0 text-xs">
                <?php foreach ([['published', 'Publish'], ['hidden', 'Hide'], ['pending', 'Unpublish']] as $opt): if ($opt[0] === $st) continue; ?>
                  <form action="<?= $base ?>/status" method="POST">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="stay_id" value="<?= (int) $r['stay_id'] ?>">
                    <input type="hidden" name="to" value="<?= $opt[0] ?>">
                    <button type="submit" class="text-violet-600 hover:underline"><?= $opt[1] ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
