<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Housekeeping EXTENSION view (PMS module 24). Two modes:
//   • task board  — $tasks, $physRooms, $propMap, $canEdit, $stayFilter, $date
//   • productivity — $reportMode = true, $report, $from, $to
// Followed house style: precompiled Tailwind literal classes, htmlspecialchars everything.
$propMap    = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$stayFilter = (int) ($stayFilter ?? 0);
$canEdit    = !empty($canEdit);
$reportMode = !empty($reportMode);
$base       = root . 'supplier/housekeeping/tasks';
$prodBase   = root . 'supplier/housekeeping/productivity';

$typeLabels = function_exists('hk_task_types') ? hk_task_types() : [];
$statusLabels = function_exists('hk_task_statuses') ? hk_task_statuses() : [];
$statusColor = [
    'pending'        => 'bg-gray-100 text-gray-600',
    'in_progress'    => 'bg-amber-50 text-amber-700',
    'done'           => 'bg-blue-50 text-blue-700',
    'dnd'            => 'bg-purple-50 text-purple-700',
    'refused'        => 'bg-orange-50 text-orange-700',
    'inspected_pass' => 'bg-green-50 text-green-700',
    'inspected_fail' => 'bg-red-50 text-red-700',
];
// Attendant action buttons per current status (mirror hk_task_transition_ok).
$nextOf = [
    'pending'        => ['in_progress' => 'Start', 'dnd' => 'DND', 'refused' => 'Refused'],
    'in_progress'    => ['done' => 'Mark done', 'dnd' => 'DND', 'refused' => 'Refused', 'pending' => 'Reset'],
    'dnd'            => ['pending' => 'Clear DND', 'in_progress' => 'Start'],
    'refused'        => ['pending' => 'Re-queue', 'in_progress' => 'Start'],
    'inspected_fail' => ['in_progress' => 'Rework'],
];
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
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
      <h1 class="text-2xl font-bold text-gray-900"><?= $reportMode ? 'Housekeeping productivity' : 'Housekeeping tasks' ?></h1>
      <p class="text-sm text-gray-600">
        <?= $reportMode ? 'Attendant output and inspection pass-rate over a date range.' : 'Daily cleaning tasks, checklists and supervisor inspection. Departure &amp; stayover tasks are generated automatically.' ?>
      </p>
    </div>
    <div class="flex items-center gap-2 text-sm">
      <a href="<?= $base ?><?= $stayFilter > 0 ? '?stay_id=' . $stayFilter : '' ?>" class="px-3 py-1.5 rounded <?= !$reportMode ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700' ?>">Board</a>
      <a href="<?= $prodBase ?><?= $stayFilter > 0 ? '?stay_id=' . $stayFilter : '' ?>" class="px-3 py-1.5 rounded <?= $reportMode ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700' ?>">Productivity</a>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" action="<?= $reportMode ? $prodBase : $base ?>" class="card p-4 flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-xs text-gray-500 mb-1">Property</label>
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">Select a property…</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('Property #' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($reportMode): ?>
      <div>
        <label class="block text-xs text-gray-500 mb-1">From</label>
        <input type="date" name="from" value="<?= htmlspecialchars((string) ($from ?? '')) ?>" class="input text-sm">
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">To</label>
        <input type="date" name="to" value="<?= htmlspecialchars((string) ($to ?? '')) ?>" class="input text-sm">
      </div>
    <?php else: ?>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Business date</label>
        <input type="date" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>" class="input text-sm">
      </div>
    <?php endif; ?>
    <button type="submit" class="px-4 py-2 rounded bg-gray-900 text-white text-sm">Apply</button>
  </form>

  <?php if ($stayFilter <= 0): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">apartment</span>
      <p class="mt-2 text-sm">Select a property to view <?= $reportMode ? 'its productivity report' : 'its housekeeping tasks' ?>.</p>
    </div>

  <?php elseif ($reportMode): ?>
    <?php $rep = (isset($report) && is_array($report)) ? $report : ['rows' => [], 'totals' => [], 'dnd' => 0, 'refused' => 0, 'open' => 0]; ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="card p-4"><p class="text-xs text-gray-500">Completed tasks</p><p class="text-2xl font-bold text-gray-900"><?= (int) ($rep['totals']['tasks'] ?? 0) ?></p></div>
      <div class="card p-4"><p class="text-xs text-gray-500">Avg minutes/task</p><p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($rep['totals']['avg_minutes'] ?? 0)) ?></p></div>
      <div class="card p-4"><p class="text-xs text-gray-500">Inspection pass-rate</p><p class="text-2xl font-bold text-gray-900"><?= $rep['totals']['pass_rate'] === null ? '—' : htmlspecialchars((string) $rep['totals']['pass_rate']) . '%' ?></p></div>
      <div class="card p-4"><p class="text-xs text-gray-500">DND / refused / open</p><p class="text-2xl font-bold text-gray-900"><?= (int) $rep['dnd'] ?> / <?= (int) $rep['refused'] ?> / <?= (int) $rep['open'] ?></p></div>
    </div>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Attendant</th><th class="px-4 py-3 font-medium">Completed</th>
          <th class="px-4 py-3 font-medium">Avg min</th><th class="px-4 py-3 font-medium">Inspected</th>
          <th class="px-4 py-3 font-medium">Passed</th><th class="px-4 py-3 font-medium">Pass-rate</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($rep['rows'])): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No completed tasks in this range.</td></tr>
          <?php else: foreach ($rep['rows'] as $row): ?>
            <tr>
              <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars((string) $row['attendant']) ?></td>
              <td class="px-4 py-3"><?= (int) $row['tasks'] ?></td>
              <td class="px-4 py-3"><?= htmlspecialchars((string) $row['avg_minutes']) ?></td>
              <td class="px-4 py-3"><?= (int) $row['inspected'] ?></td>
              <td class="px-4 py-3"><?= (int) $row['passed'] ?></td>
              <td class="px-4 py-3"><?= $row['pass_rate'] === null ? '—' : htmlspecialchars((string) $row['pass_rate']) . '%' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <?php $tasks = (isset($tasks) && is_array($tasks)) ? $tasks : []; $physRooms = (isset($physRooms) && is_array($physRooms)) ? $physRooms : []; ?>
    <!-- Add-task + generate toolbar -->
    <?php if ($canEdit): ?>
      <div class="card p-4 flex flex-wrap items-end gap-4">
        <form action="<?= $base ?>/add" method="POST" class="flex flex-wrap items-end gap-2">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
          <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Room</label>
            <select name="physical_room_id" class="input text-sm" required>
              <option value="">Room…</option>
              <?php foreach ($physRooms as $pr): ?>
                <option value="<?= (int) $pr['id'] ?>">#<?= htmlspecialchars((string) $pr['room_number']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Type</label>
            <select name="task_type" class="input text-sm" required>
              <?php foreach ($typeLabels as $k => $lbl): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Attendant</label>
            <input type="text" name="attendant" class="input text-sm w-32" placeholder="optional">
          </div>
          <button type="submit" class="px-4 py-2 rounded bg-violet-600 text-white text-sm">Add task</button>
        </form>
        <form action="<?= $base ?>/generate" method="POST" class="ml-auto">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
          <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
          <button type="submit" class="px-4 py-2 rounded bg-gray-100 text-gray-700 text-sm">Regenerate day</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (empty($tasks)): ?>
      <div class="card p-8 text-center text-gray-500">
        <span class="material-symbols-outlined text-5xl text-gray-300">cleaning_services</span>
        <p class="mt-2 text-sm">No housekeeping tasks for this date. Departure &amp; stayover tasks generate automatically when reservations exist.</p>
      </div>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($tasks as $t): ?>
          <?php $st = (string) $t['status']; ?>
          <div class="card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-3">
                <span class="font-semibold text-gray-900">Room #<?= htmlspecialchars((string) $t['room_number']) ?></span>
                <span class="text-xs text-gray-500"><?= htmlspecialchars((string) ($typeLabels[$t['task_type']] ?? $t['task_type'])) ?></span>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $statusColor[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars((string) ($statusLabels[$st] ?? $st)) ?></span>
                <?php if (($t['priority'] ?? '') === 'high'): ?><span class="px-2 py-0.5 rounded text-xs bg-red-50 text-red-700">High</span><?php endif; ?>
              </div>
              <div class="text-xs text-gray-500">
                <?= $t['checklist_done'] ?>/<?= $t['checklist_total'] ?> checks
                <?php if ((int) ($t['minutes'] ?? 0) > 0): ?> · <?= (int) $t['minutes'] ?> min<?php endif; ?>
                <?php if (!empty($t['attendant'])): ?> · <?= htmlspecialchars((string) $t['attendant']) ?><?php endif; ?>
              </div>
            </div>

            <?php if ($st === 'inspected_fail' && !empty($t['inspection_fail_reason'])): ?>
              <p class="mt-2 text-xs text-red-600">Failed inspection: <?= htmlspecialchars((string) $t['inspection_fail_reason']) ?></p>
            <?php endif; ?>

            <!-- Checklist -->
            <?php if (!empty($t['checklist_items'])): ?>
              <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                <?php foreach ($t['checklist_items'] as $item): ?>
                  <?php $done = !empty($item['done']); ?>
                  <form action="<?= $base ?>/checklist" method="POST" class="flex items-center gap-2">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="item_key" value="<?= htmlspecialchars((string) ($item['key'] ?? '')) ?>">
                    <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
                    <button type="submit" class="flex items-center gap-2 text-xs <?= $done ? 'text-green-700' : 'text-gray-600' ?>" <?= $canEdit ? '' : 'disabled' ?>>
                      <span class="material-symbols-outlined text-base"><?= $done ? 'check_box' : 'check_box_outline_blank' ?></span>
                      <span class="<?= $done ? 'line-through' : '' ?>"><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></span>
                    </button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
              <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                <!-- Lifecycle buttons -->
                <?php foreach (($nextOf[$st] ?? []) as $to => $lbl): ?>
                  <form action="<?= $base ?>/status" method="POST" class="inline">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
                    <button type="submit" class="px-3 py-1 rounded bg-gray-100 text-gray-700 text-xs hover:bg-gray-200"><?= htmlspecialchars($lbl) ?></button>
                  </form>
                <?php endforeach; ?>

                <!-- Attendant inline -->
                <form action="<?= $base ?>/assign" method="POST" class="flex items-center gap-1 ml-auto">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                  <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
                  <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                  <input type="text" name="attendant" value="<?= htmlspecialchars((string) ($t['attendant'] ?? '')) ?>" placeholder="attendant" class="input text-xs w-28">
                  <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
                </form>
              </div>

              <!-- Supervisor inspection (only when done) -->
              <?php if ($st === 'done'): ?>
                <div class="mt-3 flex flex-wrap items-center gap-2 bg-blue-50 rounded p-2">
                  <span class="text-xs text-blue-700 font-medium">Supervisor inspection:</span>
                  <form action="<?= $base ?>/inspect" method="POST" class="inline">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="result" value="pass">
                    <button type="submit" class="px-3 py-1 rounded bg-green-600 text-white text-xs">Pass → inspected</button>
                  </form>
                  <form action="<?= $base ?>/inspect" method="POST" class="flex items-center gap-1">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars((string) ($date ?? '')) ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="result" value="fail">
                    <input type="text" name="fail_reason" placeholder="fail reason" class="input text-xs w-40">
                    <button type="submit" class="px-3 py-1 rounded bg-red-600 text-white text-xs">Fail</button>
                  </form>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
