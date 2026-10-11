<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Workforce (PMS #44 ext). Data from supplierHrExtRoutes.php:
// $employees, $departments, $positions, $shifts, $attendance, $timesheets,
// $tasks, $kpis, $training, $payruns, $analytics, $device,
// $empNames[id], $deptNames[id], $propList[id].
$employees   = (isset($employees) && is_array($employees)) ? $employees : [];
$departments = (isset($departments) && is_array($departments)) ? $departments : [];
$positions   = (isset($positions) && is_array($positions)) ? $positions : [];
$shifts      = (isset($shifts) && is_array($shifts)) ? $shifts : [];
$attendance  = (isset($attendance) && is_array($attendance)) ? $attendance : [];
$timesheets  = (isset($timesheets) && is_array($timesheets)) ? $timesheets : [];
$tasks       = (isset($tasks) && is_array($tasks)) ? $tasks : [];
$kpis        = (isset($kpis) && is_array($kpis)) ? $kpis : [];
$training    = (isset($training) && is_array($training)) ? $training : [];
$payruns     = (isset($payruns) && is_array($payruns)) ? $payruns : [];
$analytics   = (isset($analytics) && is_array($analytics)) ? $analytics : [];
$device      = (isset($device) && is_array($device)) ? $device : ['connected' => false, 'message' => ''];
$empNames    = (isset($empNames) && is_array($empNames)) ? $empNames : [];
$deptNames   = (isset($deptNames) && is_array($deptNames)) ? $deptNames : [];
$propList    = (isset($propList) && is_array($propList)) ? $propList : [];
$base = root . 'supplier/hr/workforce';
$tkColor = ['open'=>'bg-amber-50 text-amber-700','in_progress'=>'bg-blue-50 text-blue-700','done'=>'bg-green-50 text-green-700'];
$tkLabel = ['open'=>'Open','in_progress'=>'In progress','done'=>'Done'];
$prColor = ['draft'=>'bg-gray-100 text-gray-600','posted'=>'bg-green-50 text-green-700','exported'=>'bg-violet-50 text-violet-700'];
$tsColor = ['draft'=>'bg-gray-100 text-gray-600','approved'=>'bg-green-50 text-green-700'];
$empSel = function ($name, $required = true) use ($empNames) {
    $h = '<select name="' . $name . '" class="input text-sm"' . ($required ? ' required' : '') . '>';
    if (!$required) { $h .= '<option value="">— employee —</option>'; }
    foreach ($empNames as $id => $n) { $h .= '<option value="' . (int) $id . '">' . htmlspecialchars((string) $n) . '</option>'; }
    return $h . '</select>';
};
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
      <h1 class="text-2xl font-bold text-gray-900">Workforce</h1>
      <p class="text-sm text-gray-600">Departments &amp; positions, shifts, attendance, timesheets, tasks, performance, training and payroll. <a href="<?= root ?>supplier/hr" class="text-violet-600 hover:underline">Employees &amp; leave &rarr;</a></p>
    </div>
  </div>

  <?php if (empty($employees)): ?>
    <div class="card p-6 text-center text-sm text-gray-500">Add employees under <a href="<?= root ?>supplier/hr" class="text-violet-600 hover:underline">Employees &amp; leave</a> first, then schedule, track and pay them here.</div>
  <?php endif; ?>

  <!-- KPI strip -->
  <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
    <?php
      $stat = function ($label, $val, $icon) {
        echo '<div class="card p-4"><div class="flex items-center gap-2 text-gray-400"><span class="material-symbols-outlined text-[18px]">' . $icon . '</span><span class="text-xs">' . htmlspecialchars($label) . '</span></div><div class="text-xl font-bold text-gray-900 mt-1">' . htmlspecialchars((string) $val) . '</div></div>';
      };
      $stat('Headcount', $analytics['headcount'] ?? 0, 'groups');
      $stat('On shift today', $analytics['on_shift_today'] ?? 0, 'today');
      $stat('Open tasks', $analytics['open_tasks'] ?? 0, 'task_alt');
      $stat('Avg KPI', ($analytics['avg_kpi_score'] ?? 0), 'trending_up');
      $stat('Certs expiring', $analytics['training_expiring'] ?? 0, 'school');
      $stat('Payroll MTD', number_format((float) ($analytics['payroll_mtd'] ?? 0), 2), 'payments');
    ?>
  </div>

  <!-- Departments & positions -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Departments</h2>
      <form action="<?= $base ?>/department" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
        <?= CSRF::tokenField() ?>
        <input type="text" name="name" class="input text-sm" placeholder="Department name *" required>
        <input type="text" name="code" class="input text-sm w-24" placeholder="Code">
        <button type="submit" class="btn secondary text-sm">Add</button>
      </form>
      <?php if (!empty($departments)): ?>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($departments as $d): ?>
            <span class="px-2 py-1 rounded bg-gray-50 text-xs text-gray-700"><?= htmlspecialchars((string) $d['name']) ?><?php if (!empty($d['code'])): ?> <span class="text-gray-400"><?= htmlspecialchars((string) $d['code']) ?></span><?php endif; ?></span>
          <?php endforeach; ?>
        </div>
      <?php else: ?><p class="text-xs text-gray-400">No departments yet.</p><?php endif; ?>
    </div>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Positions</h2>
      <form action="<?= $base ?>/position" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
        <?= CSRF::tokenField() ?>
        <input type="text" name="title" class="input text-sm" placeholder="Position title *" required>
        <select name="department_id" class="input text-sm"><option value="">— dept —</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars((string) $d['name']) ?></option><?php endforeach; ?></select>
        <input type="number" step="0.01" min="0" name="default_pay_rate" class="input text-sm w-28" placeholder="Pay rate">
        <button type="submit" class="btn secondary text-sm">Add</button>
      </form>
      <?php if (!empty($positions)): ?>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($positions as $p): ?>
              <tr>
                <td class="py-1.5 text-gray-800"><?= htmlspecialchars((string) $p['title']) ?></td>
                <td class="py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($deptNames[(int) ($p['department_id'] ?? 0)] ?? '—')) ?></td>
                <td class="py-1.5 text-gray-600 text-xs text-right"><?= $p['default_pay_rate'] !== null ? htmlspecialchars(number_format((float) $p['default_pay_rate'], 2)) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?><p class="text-xs text-gray-400">No positions yet.</p><?php endif; ?>
    </div>
  </div>

  <?php if (!empty($employees)): ?>
  <!-- Shifts + Attendance -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Shift scheduling</h2>
      <form action="<?= $base ?>/shift" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <input type="date" name="shift_date" class="input text-sm" required>
        <input type="time" name="start_time" class="input text-sm" required>
        <input type="time" name="end_time" class="input text-sm" required>
        <?php if (!empty($propList)): ?><select name="stay_id" class="input text-sm"><option value="">All properties</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select><?php endif; ?>
        <button type="submit" class="btn secondary text-sm">Schedule</button>
      </form>
      <?php if (!empty($shifts)): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-1.5 font-medium">Employee</th><th class="py-1.5 font-medium">Date</th><th class="py-1.5 font-medium">Time</th><th class="py-1.5 font-medium">Hrs</th><th></th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($shifts as $s): ?>
              <tr>
                <td class="py-1.5 text-gray-800"><?= htmlspecialchars((string) ($empNames[(int) $s['employee_id']] ?? ('#' . $s['employee_id']))) ?></td>
                <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars((string) $s['shift_date']) ?></td>
                <td class="py-1.5 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $s['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $s['end_time'], 0, 5)) ?></td>
                <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars(number_format((float) $s['hours'], 2)) ?></td>
                <td class="py-1.5 text-right"><form action="<?= $base ?>/shift/delete" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="text-xs text-gray-400 hover:underline">Remove</button></form></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php else: ?><p class="text-xs text-gray-400">No shifts scheduled.</p><?php endif; ?>
    </div>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-1">Attendance</h2>
      <p class="text-xs text-gray-400 mb-3"><span class="material-symbols-outlined text-[14px] align-middle">sensors_off</span> <?= htmlspecialchars((string) ($device['message'] ?? 'No time-clock device connected.')) ?></p>
      <form action="<?= $base ?>/clock-in" method="POST" class="flex flex-wrap items-end gap-2 mb-2">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <button type="submit" class="btn emerald text-sm">Clock in</button>
      </form>
      <form action="<?= $base ?>/clock-out" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <button type="submit" class="btn secondary text-sm">Clock out</button>
      </form>
      <?php if (!empty($attendance)): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-1.5 font-medium">Employee</th><th class="py-1.5 font-medium">In</th><th class="py-1.5 font-medium">Out</th><th class="py-1.5 font-medium">Hrs</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach (array_slice($attendance, 0, 25) as $a): ?>
              <tr>
                <td class="py-1.5 text-gray-800"><?= htmlspecialchars((string) ($empNames[(int) $a['employee_id']] ?? ('#' . $a['employee_id']))) ?></td>
                <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars((string) $a['clock_in']) ?></td>
                <td class="py-1.5 text-gray-500 text-xs"><?= $a['clock_out'] ? htmlspecialchars((string) $a['clock_out']) : '<span class="text-amber-600">open</span>' ?></td>
                <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars(number_format((float) $a['worked_hours'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php else: ?><p class="text-xs text-gray-400">No attendance yet.</p><?php endif; ?>
    </div>
  </div>

  <!-- Timesheets -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Timesheets <span class="text-xs font-normal text-gray-400">— the input to payroll; approve before running a payrun</span></h2>
    <form action="<?= $base ?>/timesheet" method="POST" class="flex flex-wrap items-end gap-2 my-3">
      <?= CSRF::tokenField() ?>
      <?= $empSel('employee_id') ?>
      <label class="text-xs text-gray-500">Period start <input type="date" name="period_start" class="input text-sm block" required></label>
      <label class="text-xs text-gray-500">Period end <input type="date" name="period_end" class="input text-sm block" required></label>
      <input type="number" step="0.01" min="0" name="hours" class="input text-sm w-24" placeholder="Hours">
      <input type="number" step="0.01" min="0" name="hourly_rate" class="input text-sm w-28" placeholder="Rate (opt)">
      <button type="submit" class="btn secondary text-sm">Save timesheet</button>
    </form>
    <?php if (!empty($timesheets)): ?>
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-1.5 font-medium">Employee</th><th class="py-1.5 font-medium">Period</th><th class="py-1.5 font-medium">Hrs</th><th class="py-1.5 font-medium">Rate</th><th class="py-1.5 font-medium">Gross</th><th class="py-1.5 font-medium">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($timesheets as $t): $st = (string) $t['status']; $locked = !empty($t['payrun_id']); ?>
            <tr>
              <td class="py-1.5 text-gray-800"><?= htmlspecialchars((string) ($empNames[(int) $t['employee_id']] ?? ('#' . $t['employee_id']))) ?></td>
              <td class="py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) $t['period_start']) ?> → <?= htmlspecialchars((string) $t['period_end']) ?></td>
              <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars(number_format((float) $t['hours'], 2)) ?></td>
              <td class="py-1.5 text-gray-600 text-xs"><?= htmlspecialchars(number_format((float) $t['hourly_rate'], 2)) ?></td>
              <td class="py-1.5 text-gray-900 text-xs font-medium"><?= htmlspecialchars(number_format((float) $t['hours'] * (float) $t['hourly_rate'], 2)) ?></td>
              <td class="py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $tsColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span><?php if ($locked): ?><span class="text-xs text-violet-500"> · in payrun</span><?php endif; ?></td>
              <td class="py-1.5 text-right space-x-2">
                <?php if (!$locked): ?>
                  <form action="<?= $base ?>/timesheet/fill" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="text-xs text-gray-500 hover:underline" title="Set hours from attendance in this period">Fill from attendance</button></form>
                  <form action="<?= $base ?>/timesheet/status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="status" value="<?= $st === 'approved' ? 'draft' : 'approved' ?>"><button class="text-xs text-violet-600 hover:underline"><?= $st === 'approved' ? 'Unapprove' : 'Approve' ?></button></form>
                <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?><p class="text-xs text-gray-400">No timesheets yet.</p><?php endif; ?>
  </div>

  <!-- Payroll -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Payroll</h2>
    <p class="text-xs text-gray-400 mb-3">Building a payrun pulls every <strong>approved, unpaid</strong> timesheet in the period and snapshots gross = hours × rate. Posting writes a balanced ledger accrual (DR Operating Expense / CR Accounts Payable). Export produces a CSV only — <strong>no money is disbursed</strong> (bank/payroll-provider push is not connected).</p>
    <form action="<?= $base ?>/payrun" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <label class="text-xs text-gray-500">Period start <input type="date" name="period_start" class="input text-sm block" required></label>
      <label class="text-xs text-gray-500">Period end <input type="date" name="period_end" class="input text-sm block" required></label>
      <input type="text" name="currency" class="input text-sm w-20" placeholder="CUR" maxlength="3">
      <button type="submit" class="btn emerald text-sm">Build payrun</button>
    </form>
    <?php if (!empty($payruns)): ?>
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-1.5 font-medium">Run</th><th class="py-1.5 font-medium">Period</th><th class="py-1.5 font-medium">Gross</th><th class="py-1.5 font-medium">Status</th><th class="py-1.5 font-medium">GL</th><th></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($payruns as $r): $rs = (string) $r['status']; ?>
            <tr>
              <td class="py-1.5 text-gray-800">PR-<?= (int) $r['id'] ?></td>
              <td class="py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) $r['period_start']) ?> → <?= htmlspecialchars((string) $r['period_end']) ?></td>
              <td class="py-1.5 text-gray-900 text-xs font-medium"><?= htmlspecialchars(number_format((float) $r['gross_total'], 2)) ?> <?= htmlspecialchars((string) ($r['currency'] ?? '')) ?></td>
              <td class="py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $prColor[$rs] ?? '' ?>"><?= htmlspecialchars(ucfirst($rs)) ?></span></td>
              <td class="py-1.5 text-gray-500 text-xs"><?= !empty($r['gl_entry_id']) ? '#' . (int) $r['gl_entry_id'] : '—' ?></td>
              <td class="py-1.5 text-right space-x-2">
                <?php if ($rs === 'draft'): ?>
                  <form action="<?= $base ?>/payrun/post" method="POST" class="inline" onsubmit="return confirm('Post this payrun to the ledger?');"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-violet-600 hover:underline">Post to ledger</button></form>
                <?php else: ?>
                  <form action="<?= $base ?>/payrun/export" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-violet-600 hover:underline">Export CSV</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?><p class="text-xs text-gray-400">No payruns yet.</p><?php endif; ?>
  </div>

  <!-- Tasks + KPIs + Training -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Task assignment</h2>
      <form action="<?= $base ?>/task" method="POST" class="space-y-2 mb-3">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <input type="text" name="title" class="input text-sm w-full" placeholder="Task *" required>
        <input type="date" name="due_date" class="input text-sm w-full">
        <button type="submit" class="btn secondary text-sm w-full">Assign</button>
      </form>
      <?php foreach ($tasks as $t): $ts = (string) $t['status']; ?>
        <div class="flex items-center justify-between gap-2 py-1.5 border-t border-gray-50">
          <div class="min-w-0"><div class="text-sm text-gray-800 truncate"><?= htmlspecialchars((string) $t['title']) ?></div><div class="text-xs text-gray-400"><?= htmlspecialchars((string) ($empNames[(int) $t['employee_id']] ?? '')) ?><?php if (!empty($t['due_date'])): ?> · due <?= htmlspecialchars((string) $t['due_date']) ?><?php endif; ?></div></div>
          <form action="<?= $base ?>/task/status" method="POST" class="flex items-center gap-1"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><select name="status" class="input text-xs"><?php foreach ($tkLabel as $o => $ol): ?><option value="<?= $o ?>" <?= $o === $ts ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select><button class="text-xs text-violet-600 hover:underline">Set</button></form>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Performance (KPI)</h2>
      <form action="<?= $base ?>/kpi" method="POST" class="space-y-2 mb-3">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <input type="text" name="period" class="input text-sm w-full" placeholder="Period e.g. 2026-Q3 *" required>
        <input type="number" step="0.01" min="0" max="100" name="score" class="input text-sm w-full" placeholder="Score 0–100">
        <input type="text" name="note" class="input text-sm w-full" placeholder="Note">
        <button type="submit" class="btn secondary text-sm w-full">Record review</button>
      </form>
      <?php foreach (array_slice($kpis, 0, 10) as $k): ?>
        <div class="flex items-center justify-between gap-2 py-1.5 border-t border-gray-50 text-sm">
          <span class="text-gray-800 truncate"><?= htmlspecialchars((string) ($empNames[(int) $k['employee_id']] ?? '')) ?> <span class="text-gray-400 text-xs"><?= htmlspecialchars((string) $k['period']) ?></span></span>
          <span class="font-medium text-gray-900"><?= htmlspecialchars(number_format((float) $k['score'], 1)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Training &amp; certifications</h2>
      <form action="<?= $base ?>/training" method="POST" class="space-y-2 mb-3">
        <?= CSRF::tokenField() ?>
        <?= $empSel('employee_id') ?>
        <input type="text" name="course" class="input text-sm w-full" placeholder="Course / certification *" required>
        <input type="text" name="provider" class="input text-sm w-full" placeholder="Provider">
        <label class="text-xs text-gray-500 block">Completed <input type="date" name="completed_on" class="input text-sm w-full"></label>
        <label class="text-xs text-gray-500 block">Expires <input type="date" name="expires_on" class="input text-sm w-full"></label>
        <button type="submit" class="btn secondary text-sm w-full">Record</button>
      </form>
      <?php $today = date('Y-m-d'); foreach (array_slice($training, 0, 12) as $tr): $exp = (string) ($tr['expires_on'] ?? ''); $expired = $exp !== '' && $exp < $today; ?>
        <div class="py-1.5 border-t border-gray-50 text-sm">
          <div class="text-gray-800 truncate"><?= htmlspecialchars((string) $tr['course']) ?></div>
          <div class="text-xs text-gray-400"><?= htmlspecialchars((string) ($empNames[(int) $tr['employee_id']] ?? '')) ?><?php if ($exp !== ''): ?> · <span class="<?= $expired ? 'text-red-500' : 'text-gray-400' ?>">expires <?= htmlspecialchars($exp) ?></span><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
