<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 46 — Task & workflow board. Reads: $tasks, $analytics, $templates,
  // $recurrences, $rules, $schedStatus, $eventCatalogue, $propList, $staffList,
  // $canEdit, $canApprove, $filters. All output htmlspecialchars-escaped. No money.
  $tasks       = (isset($tasks) && is_array($tasks)) ? $tasks : [];
  $analytics   = (isset($analytics) && is_array($analytics)) ? $analytics : [];
  $templates   = (isset($templates) && is_array($templates)) ? $templates : [];
  $recurrences = (isset($recurrences) && is_array($recurrences)) ? $recurrences : [];
  $rules       = (isset($rules) && is_array($rules)) ? $rules : [];
  $schedStatus = (isset($schedStatus) && is_array($schedStatus)) ? $schedStatus : ['connected' => false, 'message' => ''];
  $eventCat    = (isset($eventCatalogue) && is_array($eventCatalogue)) ? $eventCatalogue : [];
  $propList    = (isset($propList) && is_array($propList)) ? $propList : [];
  $staffList   = (isset($staffList) && is_array($staffList)) ? $staffList : [];
  $filters     = (isset($filters) && is_array($filters)) ? $filters : [];
  $canEdit     = !empty($canEdit);
  $canApprove  = !empty($canApprove);
  $base        = root . 'supplier/workflow';
  $h = fn($v) => htmlspecialchars((string) $v);

  $statuses   = function_exists('task_statuses') ? task_statuses() : [];
  $priorities = function_exists('task_priorities') ? task_priorities() : [];
  $categories = function_exists('task_categories') ? task_categories() : [];
  $cadences   = function_exists('task_recurrence_cadences') ? task_recurrence_cadences() : [];
  $apStates   = function_exists('task_approval_states') ? task_approval_states() : [];

  $stColor = [
    'open'=>'bg-amber-50 text-amber-700','in_progress'=>'bg-blue-50 text-blue-700',
    'blocked'=>'bg-red-50 text-red-700','done'=>'bg-green-50 text-green-700','cancelled'=>'bg-gray-100 text-gray-500',
  ];
  $prColor = ['low'=>'text-gray-400','normal'=>'text-gray-600','high'=>'text-orange-600','urgent'=>'text-red-600 font-semibold'];
  $tmplName = []; foreach ($templates as $t) { $tmplName[(int) $t['id']] = $t['name']; }
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= $h($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Task &amp; workflow</h1>
      <p class="text-sm text-gray-600">A shared task board, templates, recurring tasks, approvals, and event-driven automations for your team.</p>
    </div>
  </div>

  <!-- ANALYTICS ------------------------------------------------------------- -->
  <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
    <?php
      $cards = [
        ['Open',           (int) ($analytics['by_status']['open'] ?? 0),        'text-amber-700'],
        ['In progress',    (int) ($analytics['by_status']['in_progress'] ?? 0), 'text-blue-700'],
        ['Overdue',        (int) ($analytics['overdue'] ?? 0),                  'text-red-600'],
        ['Awaiting approval',(int) ($analytics['pending_approval'] ?? 0),       'text-purple-700'],
        ['Done (7d)',      (int) ($analytics['done_7d'] ?? 0),                  'text-green-700'],
        ['Total',          (int) ($analytics['total'] ?? 0),                   'text-gray-700'],
      ];
      foreach ($cards as $c): ?>
      <div class="card p-4">
        <div class="text-xs text-gray-500"><?= $h($c[0]) ?></div>
        <div class="text-2xl font-bold <?= $c[2] ?>"><?= $c[1] ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- SCHEDULER STATUS (honest) --------------------------------------------- -->
  <div class="card p-4 border <?= !empty($schedStatus['connected']) ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' ?>">
    <div class="flex items-start gap-2">
      <span class="material-symbols-outlined text-[20px] <?= !empty($schedStatus['connected']) ? 'text-green-600' : 'text-amber-600' ?>"><?= !empty($schedStatus['connected']) ? 'cloud_done' : 'schedule' ?></span>
      <div class="text-sm">
        <div class="font-medium text-gray-900">Automation scheduler: <?= !empty($schedStatus['connected']) ? 'running' : 'not scheduled' ?></div>
        <p class="text-xs text-gray-600 mt-0.5"><?= $h($schedStatus['message'] ?? '') ?></p>
        <?php if (!empty($schedStatus['tick_command'])): ?>
          <p class="text-xs text-gray-500 mt-1">Cron: <code class="px-1 bg-white/70 rounded border border-gray-200"><?= $h($schedStatus['tick_command']) ?></code></p>
        <?php endif; ?>
        <?php if (!empty($schedStatus['last_recurrence']) || isset($schedStatus['last_event_id'])): ?>
          <p class="text-xs text-gray-400 mt-1">Last recurrence: <?= $h($schedStatus['last_recurrence'] ?? '—') ?> · last event processed #<?= (int) ($schedStatus['last_event_id'] ?? 0) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/tick" method="POST" class="ml-auto shrink-0">
        <?= CSRF::tokenField() ?>
        <button type="submit" class="btn-ghost text-xs">Run one pass now</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- NEW TASK -------------------------------------------------------------- -->
  <?php if ($canEdit): ?>
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">New task</h2>
    <form action="<?= $base ?>/task" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
      <?= CSRF::tokenField() ?>
      <input type="text" name="title" class="input text-sm md:col-span-2" placeholder="Task title *" required>
      <select name="priority" class="input text-sm">
        <?php foreach ($priorities as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="category" class="input text-sm">
        <?php foreach ($categories as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="assignee_id" class="input text-sm">
        <option value="">Unassigned</option>
        <?php foreach ($staffList as $sid => $lbl): ?><option value="<?= $h($sid) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="stay_id" class="input text-sm">
        <option value="">No property</option>
        <?php foreach ($propList as $pid => $pname): ?><option value="<?= (int) $pid ?>"><?= $h($pname) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="due_date" class="input text-sm">
      <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="requires_approval" value="1"> Requires approval</label>
      <textarea name="body" class="input text-sm md:col-span-4" rows="2" placeholder="Details (optional)"></textarea>
      <div class="md:col-span-4"><button type="submit" class="btn emerald text-sm">Create task</button></div>
    </form>
  </div>
  <?php endif; ?>

  <!-- FILTERS --------------------------------------------------------------- -->
  <form method="GET" action="<?= $base ?>" class="flex flex-wrap items-end gap-2">
    <select name="status" class="input text-xs">
      <option value="">All statuses</option>
      <?php foreach ($statuses as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
    </select>
    <select name="priority" class="input text-xs">
      <option value="">All priorities</option>
      <?php foreach ($priorities as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= ($filters['priority'] ?? '') === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
    </select>
    <select name="category" class="input text-xs">
      <option value="">All categories</option>
      <?php foreach ($categories as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= ($filters['category'] ?? '') === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
    </select>
    <label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" name="overdue" value="1" <?= !empty($filters['overdue']) ? 'checked' : '' ?>> Overdue only</label>
    <button type="submit" class="btn-ghost text-xs">Filter</button>
    <a href="<?= $base ?>" class="text-xs text-gray-400 hover:underline">Reset</a>
  </form>

  <!-- TASK BOARD ------------------------------------------------------------ -->
  <div class="space-y-3">
    <?php if (empty($tasks)): ?>
      <div class="card p-6 text-center text-gray-400 text-sm">No tasks match. Create one above or let an automation create them.</div>
    <?php else: foreach ($tasks as $t):
      $id = (int) $t['id']; $st = (string) $t['status']; $pr = (string) $t['priority'];
      $overdue = !empty($t['due_date']) && !in_array($st, ['done', 'cancelled'], true) && $t['due_date'] < date('Y-m-d');
      $ap = (string) ($t['approval_state'] ?? 'none');
    ?>
      <div class="card p-5 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-semibold text-gray-900 flex items-center gap-2 flex-wrap">
              <span class="<?= $prColor[$pr] ?? '' ?>"><?= $h($t['title']) ?></span>
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= $h($statuses[$st] ?? $st) ?></span>
              <?php if ($overdue): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-700">Overdue</span><?php endif; ?>
              <?php if ((int) ($t['requires_approval'] ?? 0) === 1): ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700"><?= $h($apStates[$ap] ?? $ap) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-xs text-gray-500 mt-0.5">
              <?= $h($priorities[$pr] ?? $pr) ?> · <?= $h($categories[(string) $t['category']] ?? $t['category']) ?>
              <?php if (!empty($t['assignee_id'])): ?> · <?= $h($staffList[(string) $t['assignee_id']] ?? (string) $t['assignee_id']) ?><?php endif; ?>
              <?php if (!empty($t['stay_id'])): ?> · <?= $h($propList[(int) $t['stay_id']] ?? ('#' . $t['stay_id'])) ?><?php endif; ?>
              <?php if (!empty($t['due_date'])): ?> · due <?= $h($t['due_date']) ?><?php endif; ?>
              <?php if (!empty($t['source']) && $t['source'] !== 'manual'): ?> · via <?= $h($t['source']) ?><?php endif; ?>
            </div>
            <?php if (!empty($t['body'])): ?><p class="text-sm text-gray-700 mt-1 whitespace-pre-line"><?= $h($t['body']) ?></p><?php endif; ?>
          </div>
          <a href="<?= $base ?>/task/<?= $id ?>/trail" target="_blank" rel="noopener" class="text-xs text-gray-400 hover:text-violet-600 hover:underline shrink-0">Activity</a>
        </div>

        <?php if ($canEdit && !in_array($st, ['done', 'cancelled'], true)): ?>
        <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-50">
          <!-- status transitions -->
          <form action="<?= $base ?>/task/status" method="POST" class="flex items-center gap-1">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <select name="status" class="input text-xs">
              <?php foreach ($statuses as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $k === $st ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn-ghost text-xs">Set status</button>
          </form>

          <!-- approval -->
          <?php if ((int) ($t['requires_approval'] ?? 0) === 1 && $ap === 'pending' && $canApprove): ?>
          <form action="<?= $base ?>/task/approval" method="POST" class="flex items-center gap-1">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="note" class="input text-xs" placeholder="Note (optional)">
            <button type="submit" name="decision" value="approved" class="text-xs text-green-600 hover:underline">Approve</button>
            <button type="submit" name="decision" value="rejected" class="text-xs text-red-600 hover:underline">Reject</button>
          </form>
          <?php endif; ?>

          <!-- comment -->
          <form action="<?= $base ?>/task/comment" method="POST" class="flex items-center gap-1 flex-1 min-w-[180px]">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="body" class="input text-xs flex-1" placeholder="Add a comment">
            <button type="submit" class="btn-ghost text-xs">Comment</button>
          </form>
        </div>

        <!-- inline edit -->
        <details class="pt-1">
          <summary class="text-xs text-gray-400 cursor-pointer hover:text-violet-600">Edit task</summary>
          <form action="<?= $base ?>/task/edit" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="title" class="input text-xs md:col-span-2" value="<?= $h($t['title']) ?>">
            <select name="priority" class="input text-xs">
              <?php foreach ($priorities as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $k === $pr ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
            </select>
            <select name="assignee_id" class="input text-xs">
              <option value="">Unassigned</option>
              <?php foreach ($staffList as $sid => $lbl): ?><option value="<?= $h($sid) ?>" <?= (string) ($t['assignee_id'] ?? '') === (string) $sid ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="due_date" class="input text-xs" value="<?= $h($t['due_date'] ?? '') ?>">
            <button type="submit" class="btn-ghost text-xs">Save</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- TEMPLATES ------------------------------------------------------------- -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Task templates</h2>
    <p class="text-xs text-gray-500">Reusable task definitions. Spawn one on demand, or drive a recurring schedule / automation from it.</p>
    <?php if (!empty($templates)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Task</th><th class="px-3 py-2 font-medium">Priority</th><th class="px-3 py-2 font-medium">Due +days</th><?php if ($canEdit): ?><th class="px-3 py-2 font-medium">Actions</th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($templates as $tp): ?>
            <tr>
              <td class="px-3 py-2 text-gray-900"><?= $h($tp['name']) ?><?php if ((int) ($tp['requires_approval'] ?? 0) === 1): ?> <span class="text-xs text-purple-600">(approval)</span><?php endif; ?></td>
              <td class="px-3 py-2 text-gray-600 text-xs"><?= $h($tp['title']) ?></td>
              <td class="px-3 py-2 text-gray-500 text-xs"><?= $h($priorities[(string) $tp['priority']] ?? $tp['priority']) ?></td>
              <td class="px-3 py-2 text-gray-500 text-xs"><?= (int) ($tp['due_offset_days'] ?? 0) ?></td>
              <?php if ($canEdit): ?>
              <td class="px-3 py-2">
                <div class="flex items-center gap-2">
                  <form action="<?= $base ?>/template/spawn" method="POST">
                    <?= CSRF::tokenField() ?><input type="hidden" name="template_id" value="<?= (int) $tp['id'] ?>">
                    <button type="submit" class="text-xs text-violet-600 hover:underline">Spawn now</button>
                  </form>
                  <form action="<?= $base ?>/template/delete" method="POST" onsubmit="return confirm('Delete this template?');">
                    <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $tp['id'] ?>">
                    <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
                  </form>
                </div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/template" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2 pt-2 border-t border-gray-50">
      <?= CSRF::tokenField() ?>
      <input type="text" name="name" class="input text-xs" placeholder="Template name *">
      <input type="text" name="title" class="input text-xs md:col-span-2" placeholder="Task title *">
      <select name="priority" class="input text-xs">
        <?php foreach ($priorities as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="category" class="input text-xs">
        <?php foreach ($categories as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <input type="number" name="due_offset_days" class="input text-xs" min="0" max="3650" placeholder="Due in N days">
      <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="requires_approval" value="1"> Requires approval</label>
      <input type="text" name="body" class="input text-xs md:col-span-4" placeholder="Default details (optional)">
      <div class="md:col-span-4"><button type="submit" class="btn-ghost text-xs">Save template</button></div>
    </form>
    <?php endif; ?>
  </div>

  <!-- RECURRING TASKS ------------------------------------------------------- -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Recurring tasks</h2>
    <p class="text-xs text-gray-500">Generate a task from a template on a cadence. Runs when the scheduler ticks (see banner above).</p>
    <?php if (!empty($recurrences)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Template</th><th class="px-3 py-2 font-medium">Cadence</th><th class="px-3 py-2 font-medium">Last run</th><th class="px-3 py-2 font-medium">Active</th><?php if ($canEdit): ?><th class="px-3 py-2 font-medium"></th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($recurrences as $rc): ?>
            <tr>
              <td class="px-3 py-2 text-gray-900 text-xs"><?= $h($tmplName[(int) $rc['template_id']] ?? ('#' . $rc['template_id'])) ?></td>
              <td class="px-3 py-2 text-gray-600 text-xs"><?= $h($cadences[(string) $rc['cadence']] ?? $rc['cadence']) ?></td>
              <td class="px-3 py-2 text-gray-500 text-xs"><?= $h($rc['last_run_at'] ?? '—') ?></td>
              <td class="px-3 py-2 text-xs"><?= (int) ($rc['active'] ?? 0) === 1 ? '<span class="text-green-600">On</span>' : '<span class="text-gray-400">Off</span>' ?></td>
              <?php if ($canEdit): ?>
              <td class="px-3 py-2">
                <form action="<?= $base ?>/recurrence/toggle" method="POST">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $rc['id'] ?>">
                  <input type="hidden" name="active" value="<?= (int) ($rc['active'] ?? 0) === 1 ? '0' : '1' ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline"><?= (int) ($rc['active'] ?? 0) === 1 ? 'Pause' : 'Resume' ?></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($canEdit && !empty($templates)): ?>
    <form action="<?= $base ?>/recurrence" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2 pt-2 border-t border-gray-50">
      <?= CSRF::tokenField() ?>
      <select name="template_id" class="input text-xs">
        <?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= $h($tp['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="cadence" class="input text-xs">
        <?php foreach ($cadences as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="assignee_id" class="input text-xs">
        <option value="">Unassigned</option>
        <?php foreach ($staffList as $sid => $lbl): ?><option value="<?= $h($sid) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <div><button type="submit" class="btn-ghost text-xs">Add schedule</button></div>
    </form>
    <?php elseif ($canEdit): ?>
      <p class="text-xs text-gray-400">Create a template first to schedule recurring tasks.</p>
    <?php endif; ?>
  </div>

  <!-- AUTOMATION RULES (WHEN / IF / THEN) ----------------------------------- -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Automations <span class="text-xs font-normal text-gray-400">WHEN an event happens · IF conditions match · THEN create a task</span></h2>
    <p class="text-xs text-gray-500">Rules react to platform events (e.g. <code>payment.received</code>, <code>reservation.cancelled</code>). They fire when the scheduler ticks — near-real-time, not instant.</p>
    <?php if (!empty($rules)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Rule</th><th class="px-3 py-2 font-medium">When (event)</th><th class="px-3 py-2 font-medium">Then</th><th class="px-3 py-2 font-medium">Active</th><?php if ($canApprove && $canEdit): ?><th class="px-3 py-2 font-medium"></th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($rules as $r):
            $cfg = []; if (!empty($r['action_config'])) { $dd = json_decode((string) $r['action_config'], true); if (is_array($dd)) { $cfg = $dd; } }
          ?>
            <tr>
              <td class="px-3 py-2 text-gray-900 text-xs"><?= $h($r['name']) ?></td>
              <td class="px-3 py-2 text-gray-600 text-xs"><code><?= $h($r['event']) ?></code></td>
              <td class="px-3 py-2 text-gray-500 text-xs">Create task: <?= $h($cfg['title'] ?? '—') ?></td>
              <td class="px-3 py-2 text-xs"><?= (int) ($r['active'] ?? 0) === 1 ? '<span class="text-green-600">On</span>' : '<span class="text-gray-400">Off</span>' ?></td>
              <?php if ($canApprove && $canEdit): ?>
              <td class="px-3 py-2">
                <form action="<?= $base ?>/rule/toggle" method="POST">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="active" value="<?= (int) ($r['active'] ?? 0) === 1 ? '0' : '1' ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline"><?= (int) ($r['active'] ?? 0) === 1 ? 'Pause' : 'Resume' ?></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($canEdit && $canApprove): ?>
    <form action="<?= $base ?>/rule" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-2 pt-2 border-t border-gray-50">
      <?= CSRF::tokenField() ?>
      <input type="text" name="name" class="input text-xs" placeholder="Rule name *">
      <select name="event" class="input text-xs">
        <?php foreach ($eventCat as $grp => $events): ?>
          <optgroup label="<?= $h($grp) ?>">
            <?php foreach ($events as $ev): ?><option value="<?= $h($ev) ?>"><?= $h($ev) ?></option><?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <input type="text" name="action_config[title]" class="input text-xs md:col-span-2" placeholder="Task title to create *">
      <select name="action_config[priority]" class="input text-xs">
        <?php foreach ($priorities as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <select name="action_config[category]" class="input text-xs">
        <?php foreach ($categories as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
      </select>
      <input type="number" name="action_config[due_offset_days]" class="input text-xs" min="0" max="3650" placeholder="Due in N days">
      <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="action_config[requires_approval]" value="1"> Needs approval</label>
      <div class="md:col-span-4"><button type="submit" class="btn-ghost text-xs">Save automation</button></div>
    </form>
    <?php else: ?>
      <p class="text-xs text-gray-400">Authoring automations requires operations + finance edit rights.</p>
    <?php endif; ?>
  </div>
</div>
