<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Provided by supplierRbacExtRoutes.php: $tab, $departments, $deptSeeds, $staff,
  // $roleNames, $deptNames, $approvals, $approvalKeys, $auditRows, $reauthMethods,
  // $reauthOk. All already owner-scoped. Escape everything on output.
  $tab           = $tab ?? 'departments';
  $departments   = is_array($departments ?? null) ? $departments : [];
  $deptSeeds     = is_array($deptSeeds ?? null) ? $deptSeeds : [];
  $staff         = is_array($staff ?? null) ? $staff : [];
  $roleNames     = is_array($roleNames ?? null) ? $roleNames : [];
  $deptNames     = is_array($deptNames ?? null) ? $deptNames : [0 => '—'];
  $approvals     = is_array($approvals ?? null) ? $approvals : [];
  $approvalKeys  = is_array($approvalKeys ?? null) ? $approvalKeys : [];
  $auditRows     = is_array($auditRows ?? null) ? $auditRows : [];
  $reauthMethods = is_array($reauthMethods ?? null) ? $reauthMethods : [];
  $reauthOk      = !empty($reauthOk);
  $tabs = [
    'departments' => ['Departments', 'apartment'],
    'approvals'   => ['Approvals',   'approval'],
    'audit'       => ['Audit log',   'history'],
    'security'    => ['Step-up security', 'verified_user'],
  ];
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

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Access control</h1>
      <p class="text-sm text-gray-600">Departments, approval escalations, the staff audit trail, and step-up security.</p>
    </div>
    <div class="flex gap-2">
      <a href="<?= root ?>supplier/roles" class="text-sm text-blue-600 hover:underline">Roles</a>
      <span class="text-gray-300">&middot;</span>
      <a href="<?= root ?>supplier/staff" class="text-sm text-blue-600 hover:underline">Staff</a>
    </div>
  </div>

  <!-- Tabs -->
  <div class="border-b border-gray-200 flex gap-1 overflow-x-auto">
    <?php foreach ($tabs as $key => $meta): ?>
      <a href="<?= root ?>supplier/rbac?tab=<?= htmlspecialchars($key) ?>"
         class="px-4 py-2 text-sm font-medium border-b-2 whitespace-nowrap <?= $tab === $key ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
        <span class="material-symbols-outlined text-sm align-middle mr-1"><?= htmlspecialchars($meta[1]) ?></span><?= htmlspecialchars($meta[0]) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'departments'): ?>
    <!-- ================= DEPARTMENTS ================= -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-800 mb-3">Add a department</h2>
      <form action="<?= root ?>supplier/rbac/departments/save" method="POST" class="flex flex-col sm:flex-row gap-3 sm:items-end">
        <?= CSRF::tokenField() ?>
        <div class="form-control flex-1">
          <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
          <input type="text" name="name" required class="input" placeholder="e.g. Housekeeping">
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-600 mb-1">Code (optional)</label>
          <input type="text" name="code" class="input" placeholder="housekeeping">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
          <input type="checkbox" name="active" value="1" checked class="rounded"> Active
        </label>
        <button type="submit" class="btn emerald">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add</span>
        </button>
      </form>
      <?php if (!empty($deptSeeds)): ?>
        <p class="text-xs text-gray-500 mt-3">Common departments: <?= htmlspecialchars(implode(', ', array_map(fn($d) => $d['name'], $deptSeeds))) ?>.</p>
      <?php endif; ?>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Department</th>
            <th class="px-4 py-3 font-medium">Code</th>
            <th class="px-4 py-3 font-medium">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($departments)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No departments yet.</td></tr>
          <?php else: foreach ($departments as $d): ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($d['name'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($d['code'] ?? '') ?: '—' ?></td>
              <td class="px-4 py-3">
                <?php $on = (int) ($d['active'] ?? 0) === 1; ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $on ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $on ? 'Active' : 'Inactive' ?></span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Assign staff to departments -->
    <div class="card overflow-x-auto">
      <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-800">Assign staff to a department</h2></div>
      <table class="w-full text-sm mt-2">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Staff</th>
            <th class="px-4 py-3 font-medium">Role</th>
            <th class="px-4 py-3 font-medium">Department</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($staff)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No staff yet. Invite people from <a href="<?= root ?>supplier/staff" class="underline">Staff</a>.</td></tr>
          <?php else: foreach ($staff as $m): ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($m['invited_email'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($roleNames[(int) ($m['role_id'] ?? 0)] ?? '—') ?></td>
              <td class="px-4 py-3">
                <form action="<?= root ?>supplier/rbac/staff/department" method="POST" class="flex items-center gap-2">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="staff_id" value="<?= (int) ($m['id'] ?? 0) ?>">
                  <select name="department_id" class="select input py-1 text-sm">
                    <option value="0">— None —</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?= (int) $d['id'] ?>" <?= (int) ($m['department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="text-emerald-700 hover:underline text-xs font-medium">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'approvals'): ?>
    <!-- ================= APPROVAL ESCALATIONS ================= -->
    <div class="card p-4 bg-amber-50 border border-amber-200 text-xs text-amber-800">
      Escalations <strong>record a decision only</strong> — approving one does not itself move money or post to the ledger.
      The owning money flow (refund, discount, payout) must act on an approved decision and re-check its own limits.
    </div>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-800 mb-3">File an escalation</h2>
      <form action="<?= root ?>supplier/rbac/approvals/request" method="POST" class="grid sm:grid-cols-4 gap-3 sm:items-end">
        <?= CSRF::tokenField() ?>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
          <select name="limit_key" class="select input">
            <?php foreach ($approvalKeys as $k => $meta): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($meta['label'] ?? $k) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-600 mb-1">Amount</label>
          <input type="number" step="0.01" min="0" name="amount" class="input" placeholder="0.00">
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-600 mb-1">Reason (optional)</label>
          <input type="text" name="reason" class="input" placeholder="why this is needed">
        </div>
        <button type="submit" class="btn emerald">File request</button>
      </form>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Type</th>
            <th class="px-4 py-3 font-medium">Amount</th>
            <th class="px-4 py-3 font-medium">Requested by</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($approvals)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No escalations.</td></tr>
          <?php else: foreach ($approvals as $a): ?>
            <?php
              $st = $a['status'] ?? 'pending';
              $stColor = [
                'pending'   => 'bg-blue-50 text-blue-700',
                'approved'  => 'bg-green-50 text-green-700',
                'rejected'  => 'bg-red-50 text-red-700',
                'cancelled' => 'bg-gray-100 text-gray-500',
              ][$st] ?? 'bg-gray-100 text-gray-600';
              $keyLabel = $approvalKeys[$a['limit_key'] ?? '']['label'] ?? ($a['limit_key'] ?? '');
            ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($keyLabel) ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars(number_format((float) ($a['amount'] ?? 0), 2)) ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($a['requested_by'] ?? '') ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
              <td class="px-4 py-3">
                <?php if ($st === 'pending'): ?>
                  <form action="<?= root ?>supplier/rbac/approvals/decide" method="POST" class="flex items-center gap-2">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) ($a['id'] ?? 0) ?>">
                    <button type="submit" name="decision" value="approved" class="text-green-700 hover:underline text-xs font-medium">Approve</button>
                    <button type="submit" name="decision" value="rejected" class="text-red-600 hover:underline text-xs font-medium">Reject</button>
                  </form>
                <?php else: ?>
                  <span class="text-gray-400 text-xs"><?= htmlspecialchars($a['decided_by'] ?? '—') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'audit'): ?>
    <!-- ================= STAFF AUDIT LOG ================= -->
    <form action="<?= root ?>supplier/rbac" method="GET" class="card p-4 flex flex-col sm:flex-row gap-3 sm:items-end">
      <input type="hidden" name="tab" value="audit">
      <div class="form-control flex-1">
        <label class="block text-xs font-medium text-gray-600 mb-1">Filter by event (substring)</label>
        <input type="text" name="event" class="input" value="<?= htmlspecialchars($_GET['event'] ?? '') ?>" placeholder="e.g. reservation. or payout.">
      </div>
      <button type="submit" class="btn">Filter</button>
    </form>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">When</th>
            <th class="px-4 py-3 font-medium">Actor</th>
            <th class="px-4 py-3 font-medium">Event</th>
            <th class="px-4 py-3 font-medium">Subject</th>
            <th class="px-4 py-3 font-medium">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($auditRows)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No audit activity yet.</td></tr>
          <?php else: foreach ($auditRows as $row): ?>
            <tr>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($row['actor_user_id'] ?? '—') ?></td>
              <td class="px-4 py-3 text-gray-900"><code class="text-xs"><?= htmlspecialchars($row['event'] ?? '') ?></code></td>
              <td class="px-4 py-3 text-gray-500">
                <?php $sub = trim(($row['subject_type'] ?? '') . ' ' . ($row['subject_id'] ?? '')); ?>
                <?= htmlspecialchars($sub !== '' ? $sub : '—') ?>
              </td>
              <td class="px-4 py-3 text-gray-400 text-xs"><?= htmlspecialchars($row['ip'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Append-only trail, scoped to you and your staff. Showing the most recent entries.</p>

  <?php else: /* security / step-up */ ?>
    <!-- ================= STEP-UP SECURITY ================= -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined <?= $reauthOk ? 'text-green-600' : 'text-gray-400' ?>">verified_user</span>
        <div>
          <h2 class="text-sm font-semibold text-gray-800">Sensitive-action re-authentication</h2>
          <p class="text-xs text-gray-500">
            <?php if ($reauthOk): ?>
              Your identity is confirmed — sensitive actions are unlocked for a few minutes.
            <?php else: ?>
              Confirm your password to unlock sensitive actions for the next few minutes.
            <?php endif; ?>
          </p>
        </div>
      </div>

      <?php if (!$reauthOk): ?>
        <form action="<?= root ?>supplier/rbac/reauth" method="POST" class="flex flex-col sm:flex-row gap-3 sm:items-end max-w-md">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="return" value="/supplier/rbac?tab=security">
          <div class="form-control flex-1">
            <label class="block text-xs font-medium text-gray-600 mb-1">Your password</label>
            <input type="password" name="password" required class="input" autocomplete="current-password">
          </div>
          <button type="submit" class="btn emerald">Confirm</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card overflow-x-auto">
      <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-800">Available step-up methods</h2></div>
      <table class="w-full text-sm mt-2">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Method</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">Notes</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($reauthMethods as $mth): ?>
            <?php $connected = ($mth['status'] ?? '') === 'connected'; ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($mth['label'] ?? '') ?></td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $connected ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                  <?= $connected ? 'Connected' : 'Not connected' ?>
                </span>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($mth['note'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
