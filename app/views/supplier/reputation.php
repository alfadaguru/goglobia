<?php @$SECURE or die('Access Denied!'); ?>
<?php
// MODULE 23 deepen — OWNER reputation dashboard. Consumes $dash (reputation_dashboard),
// $surveys, $internal, $propMap, $canEdit. Property drill-in via ?stay_id.
$dash     = (isset($dash) && is_array($dash)) ? $dash : [];
$surveys  = (isset($surveys) && is_array($surveys)) ? $surveys : [];
$internal = (isset($internal) && is_array($internal)) ? $internal : [];
$propMap  = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit  = !empty($canEdit);
$base     = root . 'supplier/reputation';
$stayFilter = (int) ($_GET['stay_id'] ?? 0);

$npsVal = $dash['nps'] ?? null;
$npsColor = $npsVal === null ? 'text-gray-400' : ($npsVal >= 50 ? 'text-green-600' : ($npsVal >= 0 ? 'text-amber-600' : 'text-red-600'));
$sent = $dash['sentiment'] ?? ['positive' => 0, 'neutral' => 0, 'negative' => 0];
$sentTotal = max(1, (int) $sent['positive'] + (int) $sent['neutral'] + (int) $sent['negative']);
$depts = $dash['departments'] ?? [];
$deptColor = function ($s) {
    if ($s === null) { return 'bg-gray-100 text-gray-400'; }
    if ($s >= 4.0) { return 'bg-green-50 text-green-700'; }
    if ($s >= 3.0) { return 'bg-amber-50 text-amber-700'; }
    return 'bg-red-50 text-red-700';
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
      <h1 class="text-2xl font-bold text-gray-900">Reputation</h1>
      <p class="text-sm text-gray-600">Guest satisfaction, NPS, sentiment and department performance across your properties.</p>
    </div>
    <?php if (count($propMap) > 1): ?>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $pid => $pname): ?>
          <option value="<?= (int) $pid ?>" <?= $stayFilter === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <!-- KPI cards -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="card p-4">
      <div class="text-xs text-gray-500 uppercase tracking-wide">Public rating</div>
      <div class="mt-1 text-2xl font-bold text-gray-900"><?= $dash['review_avg'] !== null ? number_format((float) $dash['review_avg'], 2) : '—' ?><span class="text-base font-normal text-amber-400"> ★</span></div>
      <div class="text-xs text-gray-500 mt-0.5"><?= (int) ($dash['review_n'] ?? 0) ?> published review<?= (int) ($dash['review_n'] ?? 0) === 1 ? '' : 's' ?></div>
    </div>
    <div class="card p-4">
      <div class="text-xs text-gray-500 uppercase tracking-wide">NPS</div>
      <div class="mt-1 text-2xl font-bold <?= $npsColor ?>"><?= $npsVal === null ? '—' : (int) $npsVal ?></div>
      <div class="text-xs text-gray-500 mt-0.5"><?= (int) ($dash['nps_n'] ?? 0) ?> survey response<?= (int) ($dash['nps_n'] ?? 0) === 1 ? '' : 's' ?></div>
    </div>
    <div class="card p-4">
      <div class="text-xs text-gray-500 uppercase tracking-wide">Promoters</div>
      <div class="mt-1 text-2xl font-bold text-green-600"><?= (int) ($dash['promoters'] ?? 0) ?></div>
      <div class="text-xs text-gray-500 mt-0.5"><?= (int) ($dash['detractors'] ?? 0) ?> detractor<?= (int) ($dash['detractors'] ?? 0) === 1 ? '' : 's' ?></div>
    </div>
    <div class="card p-4">
      <div class="text-xs text-gray-500 uppercase tracking-wide">Sentiment</div>
      <div class="mt-2 flex h-3 w-full overflow-hidden rounded bg-gray-100">
        <div class="bg-green-400" style="width: <?= round((int) $sent['positive'] / $sentTotal * 100) ?>%"></div>
        <div class="bg-gray-300" style="width: <?= round((int) $sent['neutral'] / $sentTotal * 100) ?>%"></div>
        <div class="bg-red-400" style="width: <?= round((int) $sent['negative'] / $sentTotal * 100) ?>%"></div>
      </div>
      <div class="text-xs text-gray-500 mt-1"><?= (int) $sent['positive'] ?>+ / <?= (int) $sent['neutral'] ?>○ / <?= (int) $sent['negative'] ?>−</div>
    </div>
  </div>

  <!-- Department performance -->
  <div class="card p-5">
    <h2 class="text-lg font-bold text-gray-900 mb-3">Department performance</h2>
    <?php if (empty($depts)): ?>
      <p class="text-sm text-gray-500">No department data yet.</p>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($depts as $d): ?>
          <div class="flex items-center justify-between rounded border border-gray-100 p-3">
            <div>
              <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $d['label']) ?></div>
              <div class="text-xs text-gray-500"><?= (int) $d['guest_n'] ?> guest · <?= (int) $d['internal_n'] ?> internal</div>
            </div>
            <span class="px-2.5 py-1 rounded text-sm font-semibold <?= $deptColor($d['score']) ?>">
              <?= $d['score'] !== null ? number_format((float) $d['score'], 2) : '—' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent surveys -->
  <div class="card p-5">
    <h2 class="text-lg font-bold text-gray-900 mb-3">Recent surveys</h2>
    <?php if (empty($surveys)): ?>
      <p class="text-sm text-gray-500">No surveys yet. Surveys are collected from your post-stay guest links.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-100">
              <th class="py-2 pr-3">Guest</th><th class="py-2 pr-3">Property</th><th class="py-2 pr-3">NPS</th>
              <th class="py-2 pr-3">Sentiment</th><th class="py-2 pr-3">Comment</th><th class="py-2">Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($surveys as $s): $sl = (string) ($s['sentiment'] ?? 'neutral');
              $sc = $sl === 'positive' ? 'text-green-600' : ($sl === 'negative' ? 'text-red-600' : 'text-gray-500'); ?>
              <tr class="border-b border-gray-50">
                <td class="py-2 pr-3 text-gray-900"><?= htmlspecialchars((string) ($s['guest_name'] ?? 'Guest')) ?: 'Guest' ?></td>
                <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars($propMap[(int) $s['stay_id']] ?? ('#' . $s['stay_id'])) ?></td>
                <td class="py-2 pr-3 font-medium"><?= $s['nps'] !== null && $s['nps'] !== '' ? (int) $s['nps'] : '—' ?></td>
                <td class="py-2 pr-3 <?= $sc ?>"><?= htmlspecialchars(ucfirst($sl)) ?></td>
                <td class="py-2 pr-3 text-gray-700 max-w-xs truncate"><?= htmlspecialchars((string) ($s['comment'] ?? '')) ?></td>
                <td class="py-2 text-gray-500 whitespace-nowrap"><?= htmlspecialchars(substr((string) $s['created_at'], 0, 10)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Internal QA ratings -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-bold text-gray-900">Internal QA ratings</h2>
      <span class="text-xs text-gray-400">Private — never shown to guests</span>
    </div>

    <?php if ($canEdit && $stayFilter > 0): ?>
      <form action="<?= $base ?>/internal" method="POST" class="flex flex-wrap items-end gap-2 mb-4 pb-4 border-b border-gray-100">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="stay_id" value="<?= (int) $stayFilter ?>">
        <div>
          <label class="block text-xs text-gray-500 mb-0.5">Rating</label>
          <select name="rating" class="input text-sm">
            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-0.5">Department</label>
          <select name="department" class="input text-sm">
            <option value="housekeeping">Housekeeping</option>
            <option value="front_office">Front office</option>
            <option value="f_and_b">Food &amp; beverage</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </div>
        <div class="flex-1 min-w-[180px]">
          <label class="block text-xs text-gray-500 mb-0.5">Note</label>
          <input type="text" name="note" class="input text-sm w-full" placeholder="Internal QA note (optional)">
        </div>
        <button type="submit" class="btn secondary text-xs">Add rating</button>
      </form>
    <?php elseif ($canEdit): ?>
      <p class="text-xs text-gray-400 mb-3">Select a single property above to add an internal rating.</p>
    <?php endif; ?>

    <?php if (empty($internal)): ?>
      <p class="text-sm text-gray-500">No internal ratings recorded.</p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($internal as $n): ?>
          <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-50 pb-2">
            <div>
              <span class="text-amber-400"><?= str_repeat('★', (int) $n['rating']) ?><span class="text-gray-200"><?= str_repeat('★', 5 - (int) $n['rating']) ?></span></span>
              <span class="ml-2 text-gray-600"><?= htmlspecialchars($propMap[(int) $n['stay_id']] ?? ('#' . $n['stay_id'])) ?></span>
              <?php if (!empty($n['department'])): ?><span class="ml-1 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600"><?= htmlspecialchars(function_exists('survey_department_label') ? survey_department_label((string) $n['department']) : (string) $n['department']) ?></span><?php endif; ?>
              <?php if (!empty($n['note'])): ?><p class="text-gray-700 mt-1"><?= htmlspecialchars((string) $n['note']) ?></p><?php endif; ?>
            </div>
            <span class="text-xs text-gray-400 whitespace-nowrap"><?= htmlspecialchars(substr((string) $n['created_at'], 0, 10)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
