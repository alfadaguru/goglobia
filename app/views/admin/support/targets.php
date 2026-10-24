<?php
// app/views/admin/support/targets.php — agent/GIA/team conversion targets + live progress.
// Mirrors app/views/admin/support/campaigns.php styling.
@$SECURE or die('Access Denied!');

$targets = function_exists('support_targets_all') ? support_targets_all($db) : [];
$canSet  = function_exists('support_can') && support_can($db, 'targets.set');
$csrf    = (class_exists('CSRF') && method_exists('CSRF', 'getToken')) ? CSRF::getToken() : '';

$scopeBadge = function (string $s): string {
    $map = [
        'staff' => 'background:#dbeafe;color:#1e40af',
        'gia'   => 'background:#ede9fe;color:#6d28d9',
        'team'  => 'background:#dcfce7;color:#166534',
    ];
    $label = $s === 'gia' ? 'GIA' : ucfirst($s);
    return '<span style="' . ($map[$s] ?? $map['staff']) . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">' . htmlspecialchars($label) . '</span>';
};
$fmtActual = function ($metric, $v) {
    if ($metric === 'revenue') { return number_format((float) $v, 2); }
    return (int) $v;
};
?>
<div class="container my-4">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Targets &amp; Performance</h1>
      <p class="text-sm text-gray-500 mt-0.5">Set conversion goals per agent, for GIA itself, or the whole team &mdash; e.g. 100 Umrah seats this month. Progress is live from real chats &rarr; held &rarr; paid.</p>
    </div>
    <?php if ($canSet): ?>
      <a href="<?= root.admin ?>/support/targets/create"
         class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
        <span class="material-symbols-outlined !text-[18px]">add</span> New target
      </a>
    <?php endif; ?>
  </div>

  <?php if (empty($targets)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">target</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No targets yet</h3>
      <p class="text-sm text-gray-500 mt-1">Create a target to track conversions against a goal.</p>
    </div>
  <?php else: ?>
    <div class="grid gap-4 md:grid-cols-2">
      <?php foreach ($targets as $t):
        $pct = (float) ($t['pct'] ?? 0);
        $barPct = max(0, min(100, $pct));
        $barColor = $pct >= 100 ? '#16a34a' : ($pct >= 50 ? '#2563eb' : '#f59e0b');
        $win = $t['window'] ?? ['', ''];
      ?>
        <div class="border border-gray-100 rounded-xl p-4 bg-white">
          <div class="flex items-start justify-between gap-2">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <?= $scopeBadge((string) ($t['scope'] ?? 'staff')) ?>
                <span class="font-semibold text-gray-900"><?= htmlspecialchars((string) ($t['who'] ?? '')) ?></span>
              </div>
              <div class="text-sm text-gray-600">
                <?= htmlspecialchars($t['label'] !== '' ? (string) $t['label'] : ('Convert ' . (int) $t['target_value'] . ' ' . ($t['product'] !== '' ? $t['product'] : 'any') . ' (' . $t['metric'] . ')')) ?>
              </div>
              <div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($win[0] . ' → ' . $win[1]) ?> · <?= htmlspecialchars((string) $t['metric']) ?></div>
            </div>
            <?php if ($canSet): ?>
              <div class="flex items-center gap-1 shrink-0">
                <a href="<?= root.admin ?>/support/targets/edit/<?= (int) $t['id'] ?>" class="text-gray-400 hover:text-blue-600" title="Edit"><span class="material-symbols-outlined !text-[18px]">edit</span></a>
                <form method="post" action="<?= root.admin ?>/support/targets/delete" onsubmit="return confirm('Delete this target?')" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="text-gray-400 hover:text-red-600" title="Delete"><span class="material-symbols-outlined !text-[18px]">delete</span></button>
                </form>
              </div>
            <?php endif; ?>
          </div>
          <div class="mt-3">
            <div class="flex items-baseline justify-between text-sm">
              <span class="font-bold text-gray-900"><?= $fmtActual($t['metric'], $t['actual']) ?></span>
              <span class="text-gray-400">of <?= (int) $t['target_value'] ?></span>
            </div>
            <div style="height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin-top:6px">
              <div style="height:100%;width:<?= $barPct ?>%;background:<?= $barColor ?>;border-radius:999px;transition:width .3s"></div>
            </div>
            <div class="text-xs text-gray-500 mt-1"><?= $pct ?>%</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
