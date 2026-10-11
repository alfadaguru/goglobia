<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $employees = (isset($employees) && is_array($employees)) ? $employees : [];
  $leave = (isset($leave) && is_array($leave)) ? $leave : [];
  $empNames = (isset($empNames) && is_array($empNames)) ? $empNames : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $base = root . 'supplier/hr';
  $esColor = ['active'=>'bg-green-50 text-green-700','on_leave'=>'bg-amber-50 text-amber-700','terminated'=>'bg-gray-100 text-gray-500'];
  $esLabel = ['active'=>'Active','on_leave'=>'On leave','terminated'=>'Terminated'];
  $lsColor = ['requested'=>'bg-amber-50 text-amber-700','approved'=>'bg-green-50 text-green-700','rejected'=>'bg-gray-100 text-gray-500'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">HR &amp; workforce</h1><p class="text-sm text-gray-600">Your staff register and leave. (Separate from login users under Staff &amp; roles.)</p></div>

  <!-- Add employee -->
  <form action="<?= $base ?>/employee" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <input type="text" name="name" class="input text-sm" placeholder="Name *" required>
    <input type="text" name="role_title" class="input text-sm" placeholder="Role / title">
    <input type="text" name="department" class="input text-sm" placeholder="Department">
    <select name="employment_type" class="input text-sm"><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="casual">Casual</option><option value="contract">Contract</option></select>
    <input type="number" name="pay_rate" step="0.01" min="0" class="input text-sm" placeholder="Pay rate">
    <input type="date" name="started_on" class="input text-sm">
    <?php if (!empty($propList)): ?>
    <select name="stay_id" class="input text-sm"><option value="">All properties</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add employee</button></div>
  </form>

  <!-- Employees -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Employees</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Role</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Set</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($employees)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No employees.</td></tr>
        <?php else: foreach ($employees as $e): $es = (string) $e['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $e['name']) ?><?php if (!empty($e['department'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $e['department']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($e['role_title'] ?? '')) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(str_replace('_', ' ', (string) $e['employment_type'])) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $esColor[$es] ?? '' ?>"><?= htmlspecialchars($esLabel[$es] ?? ucfirst($es)) ?></span></td>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/employee/status" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <select name="status" class="input text-xs"><?php foreach ($esLabel as $o => $ol): ?><option value="<?= $o ?>" <?= $o === $es ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
                <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Leave -->
  <?php if (!empty($employees)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Leave</h2>
    <form action="<?= $base ?>/leave" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="employee_id" class="input text-sm" required><?php foreach ($empNames as $eid => $en): ?><option value="<?= (int) $eid ?>"><?= htmlspecialchars((string) $en) ?></option><?php endforeach; ?></select>
      <select name="kind" class="input text-sm"><option value="annual">Annual</option><option value="sick">Sick</option><option value="unpaid">Unpaid</option><option value="other">Other</option></select>
      <input type="date" name="from_date" class="input text-sm" required>
      <input type="date" name="to_date" class="input text-sm" required>
      <button type="submit" class="btn secondary text-sm">Record leave</button>
    </form>
    <?php if (!empty($leave)): ?>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Employee</th><th class="px-2 py-1.5 font-medium">Kind</th><th class="px-2 py-1.5 font-medium">Dates</th><th class="px-2 py-1.5 font-medium">Status</th><th class="px-2 py-1.5 font-medium">Set</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($leave as $l): $ls = (string) $l['status']; ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars($empNames[(int) $l['employee_id']] ?? ('#' . $l['employee_id'])) ?></td>
              <td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $l['kind'])) ?></td>
              <td class="px-2 py-1.5 text-gray-600 text-xs"><?= htmlspecialchars((string) $l['from_date']) ?> → <?= htmlspecialchars((string) $l['to_date']) ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $lsColor[$ls] ?? '' ?>"><?= htmlspecialchars(ucfirst($ls)) ?></span></td>
              <td class="px-2 py-1.5">
                <?php if ($ls === 'requested'): ?>
                  <form action="<?= $base ?>/leave/status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="status" value="approved"><button class="text-xs text-violet-600 hover:underline">Approve</button></form>
                  <form action="<?= $base ?>/leave/status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="status" value="rejected"><button class="text-xs text-gray-400 hover:underline">Reject</button></form>
                <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
