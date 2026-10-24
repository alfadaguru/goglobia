<?php
// app/views/admin/support/shifts.php — Shift roster for a date.
// Who works today, their start/end window, a clocked-in badge and the GIA
// nudge_count. Date is picked via ?date=YYYY-MM-DD (default today). Rows come
// from support_shifts_for($db,$date) (app/lib/support/shifts.php). Style mirrors
// app/views/admin/support/staff.php.
@$SECURE or die('Access Denied!');

$date = trim((string) ($_GET['date'] ?? ''));
if ($date === '' || strtotime($date) === false) { $date = date('Y-m-d'); }
$date = date('Y-m-d', strtotime($date));

$shifts  = support_shifts_for($db, $date);
$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$fmtTime = function ($t): string {
    $t = (string) $t;
    if ($t === '' || strtotime($t) === false) { return '—'; }
    return date('g:i A', strtotime($t));
};
?>

<div class="container my-4">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Shift Roster</h1>
      <p class="text-sm text-gray-500 mt-0.5"><?= number_format(count($shifts)) ?> shift<?= count($shifts) === 1 ? '' : 's' ?> on <?= htmlspecialchars(date('D, j M Y', strtotime($date))) ?></p>
    </div>
    <a href="<?= root.admin ?>/support/shifts/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> Assign shift
    </a>
  </div>

  <!-- DATE NAVIGATOR -->
  <form method="GET" action="<?= root.admin ?>/support/shifts" class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/support/shifts?date=<?= urlencode($prevDay) ?>"
       class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Previous day">
      <span class="material-symbols-outlined !text-[18px]">chevron_left</span>
    </a>
    <input type="date" name="date" value="<?= htmlspecialchars($date) ?>"
           onchange="this.form.submit()" class="input" style="max-width:180px">
    <a href="<?= root.admin ?>/support/shifts?date=<?= urlencode($nextDay) ?>"
       class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Next day">
      <span class="material-symbols-outlined !text-[18px]">chevron_right</span>
    </a>
    <a href="<?= root.admin ?>/support/shifts?date=<?= urlencode(date('Y-m-d')) ?>"
       class="text-sm text-blue-600 hover:underline font-medium ml-2">Today</a>
  </form>

  <?php if (empty($shifts)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">event_busy</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No shifts on this day</h3>
      <p class="text-sm text-gray-500 mt-1">Assign a staff member to a shift to build the roster for <?= htmlspecialchars(date('j M Y', strtotime($date))) ?>.</p>
      <a href="<?= root.admin ?>/support/shifts/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Assign shift</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Staff</th>
            <th class="px-4 py-3 font-semibold">Shift window</th>
            <th class="px-4 py-3 font-semibold">Attendance</th>
            <th class="px-4 py-3 font-semibold">Nudges</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($shifts as $s): ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) $s['name']) ?></div>
                <?php if (!empty($s['phone'])): ?>
                  <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $s['phone']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-700">
                <?= $fmtTime($s['start_time']) ?> &ndash; <?= $fmtTime($s['end_time']) ?>
              </td>
              <td class="px-4 py-3">
                <?php if (!empty($s['clocked_in'])): ?>
                  <span style="background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">
                    <?= !empty($s['online']) ? 'Online' : 'Clocked in' ?>
                  </span>
                <?php else: ?>
                  <span style="background:#fee2e2;color:#991b1b;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">
                    Not clocked in
                  </span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-700">
                <?php $nc = (int) $s['nudge_count']; ?>
                <span title="<?= $s['last_nudge_at'] ? 'Last nudge: ' . htmlspecialchars((string) $s['last_nudge_at']) : 'No nudges sent' ?>">
                  <?= $nc ?> / 3
                </span>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a href="<?= root.admin ?>/support/shifts/edit/<?= (int) $s['id'] ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                <form method="POST" action="<?= root.admin ?>/support/shifts/delete" class="inline ml-3" onsubmit="return confirm('Remove this shift?');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                  <button type="submit" class="text-red-600 hover:underline font-medium">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
