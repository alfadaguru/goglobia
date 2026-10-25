<?php
// app/views/admin/employees/shifts-manage.php — assign / edit a support shift.
// Pick a support staff member, a date and start/end times. Posts to
// admin/employees/shifts/save (support_shift_upsert — one shift per staff+date).
// Style mirrors app/views/admin/employees/staff-manage.php.
@$SECURE or die('Access Denied!');

$id = (int) ($_GET['id'] ?? 0);

// Edit: load the existing shift row so the form is pre-filled + the staff is
// locked (the upsert is keyed on staff+date so changing staff mid-edit would
// create a different row rather than move this one).
$shift = $id > 0 ? $db->get('support_shifts', '*', ['id' => $id]) : null;

// Staff dropdown — employees (users with role admin/employee). The staff key is
// users.user_id (VARCHAR) after the RBAC consolidation (S3).
$staffRows = $db->select('users', [
    'user_id', 'first_name', 'last_name', 'email', 'shift_group',
], [
    'role' => ['admin', 'employee'],
    'status' => 'active',
    'ORDER' => ['first_name' => 'ASC', 'last_name' => 'ASC'],
]) ?: [];

$staffLabel = function (array $r): string {
    $label = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
    if ($label === '') { $label = (string) ($r['email'] ?? $r['user_id']); }
    if (!empty($r['shift_group'])) { $label .= ' — ' . $r['shift_group']; }
    return $label;
};

// Pre-fill values.
$curStaff = $shift ? (string) $shift['staff_id'] : '';
$curDate  = $shift && !empty($shift['shift_date']) ? date('Y-m-d', strtotime($shift['shift_date'])) : (trim((string) ($_GET['date'] ?? '')) !== '' && strtotime((string) $_GET['date']) !== false ? date('Y-m-d', strtotime((string) $_GET['date'])) : date('Y-m-d'));
$curStart = $shift && !empty($shift['start_time']) ? substr((string) $shift['start_time'], 0, 5) : '';
$curEnd   = $shift && !empty($shift['end_time'])   ? substr((string) $shift['end_time'], 0, 5)   : '';
?>

<div class="min-h-screen bg-slate-50">

  <!-- PAGE HEADER -->
  <div class="bg-white border-b border-slate-200 px-6 py-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 max-w-3xl mx-auto">
      <div>
        <h1 class="text-2xl font-bold text-slate-900"><?= $id > 0 ? 'Edit Shift' : 'Assign Shift' ?></h1>
        <p class="text-sm text-slate-500 mt-1">Schedule a support staff member for a shift window.</p>
      </div>
      <a href="<?= root . admin ?>/employees/shifts"
         class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
        <span class="material-symbols-outlined text-lg">arrow_back</span>
        Back to roster
      </a>
    </div>
  </div>

  <div class="p-6">
    <div class="max-w-3xl mx-auto">

      <?php if (isset($_SESSION['message'])): ?>
        <div class="mb-5 flex items-center gap-2 p-3 rounded-lg"
             style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
          <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
          <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
      <?php endif; ?>

      <?php if (empty($staffRows)): ?>
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-8 text-center">
          <span class="material-symbols-outlined !text-[48px] text-slate-300">group_off</span>
          <h3 class="mt-3 text-base font-semibold text-slate-700">No active support staff</h3>
          <p class="text-sm text-slate-500 mt-1">Add a support staff member first, then come back to assign shifts.</p>
          <a href="<?= root . admin ?>/employees/staff/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add staff</a>
        </div>
      <?php else: ?>

      <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
        <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4">
          <h2 class="text-lg font-semibold text-white flex items-center gap-2">
            <span class="material-symbols-outlined">calendar_month</span>
            Shift details
          </h2>
        </div>

        <form action="<?= root . admin ?>/employees/shifts/save" method="POST" class="p-6 space-y-6">

          <!-- STAFF -->
          <div>
            <label for="staff_id" class="block text-sm font-medium text-slate-700 mb-2">
              Staff member <span class="text-red-500">*</span>
            </label>
            <?php if ($id > 0): ?>
              <!-- On edit the shift's staff is locked (the row is keyed on staff+date). -->
              <select id="staff_id" name="staff_id" required class="select w-full" disabled>
                <?php foreach ($staffRows as $r): ?>
                  <option value="<?= htmlspecialchars((string) $r['user_id'], ENT_QUOTES) ?>" <?= $curStaff === (string) $r['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($staffLabel($r)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string) $curStaff, ENT_QUOTES) ?>">
              <p class="text-xs text-slate-500 mt-1">The staff member cannot be changed on an existing shift.</p>
            <?php else: ?>
              <select id="staff_id" name="staff_id" required class="select w-full">
                <option value="">— select a staff member —</option>
                <?php foreach ($staffRows as $r): ?>
                  <option value="<?= htmlspecialchars((string) $r['user_id'], ENT_QUOTES) ?>" <?= $curStaff === (string) $r['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($staffLabel($r)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>

          <!-- DATE -->
          <div>
            <label for="shift_date" class="block text-sm font-medium text-slate-700 mb-2">
              Date <span class="text-red-500">*</span>
            </label>
            <input type="date" id="shift_date" name="shift_date" required
                   value="<?= htmlspecialchars($curDate) ?>" class="input w-full" style="max-width:220px">
          </div>

          <!-- TIMES -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="start_time" class="block text-sm font-medium text-slate-700 mb-2">Start time</label>
              <input type="time" id="start_time" name="start_time"
                     value="<?= htmlspecialchars($curStart) ?>" class="input w-full">
            </div>
            <div>
              <label for="end_time" class="block text-sm font-medium text-slate-700 mb-2">End time</label>
              <input type="time" id="end_time" name="end_time"
                     value="<?= htmlspecialchars($curEnd) ?>" class="input w-full">
              <p class="text-xs text-slate-500 mt-1">Leave blank for an open-ended shift.</p>
            </div>
          </div>

          <!-- ACTIONS -->
          <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-200">
            <a href="<?= root . admin ?>/employees/shifts"
               class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
              <span class="material-symbols-outlined text-lg">cancel</span>
              Cancel
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm">
              <span class="material-symbols-outlined text-lg">save</span>
              <?= $id > 0 ? 'Save changes' : 'Assign shift' ?>
            </button>
          </div>

          <?= CSRF::tokenField() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
        </form>
      </div>

      <?php endif; ?>

    </div>
  </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
