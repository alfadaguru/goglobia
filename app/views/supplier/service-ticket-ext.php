<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Vars from supplierServiceExtRoutes.php GET handler.
  $ticket   = (isset($ticket) && is_array($ticket)) ? $ticket : [];
  $ext      = (isset($ext) && is_array($ext)) ? $ext : [];
  $events   = (isset($events) && is_array($events)) ? $events : [];
  $recovery = (isset($recovery) && is_array($recovery)) ? $recovery : [];
  $slaState = isset($slaState) ? (string) $slaState : 'none';
  $propName = isset($propName) ? (string) $propName : '';
  $canEdit  = !empty($canEdit);
  $tid      = (int) ($ticket['id'] ?? 0);
  $sid      = (int) ($ticket['stay_id'] ?? 0);
  $status   = (string) ($ticket['status'] ?? 'open');
  $base     = root . 'supplier/service-tickets';

  $depts   = function_exists('svc_departments') ? svc_departments() : [];
  $kinds   = function_exists('svc_recovery_kinds') ? svc_recovery_kinds() : [];
  $stLabel = ['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed'];
  $slaBadge = [
    'met'      => ['SLA met',     'bg-green-50 text-green-700'],
    'breached' => ['SLA breached','bg-red-50 text-red-700'],
    'at_risk'  => ['SLA at risk', 'bg-amber-50 text-amber-700'],
    'on_track' => ['On track',    'bg-blue-50 text-blue-700'],
    'none'     => ['No SLA',      'bg-gray-100 text-gray-500'],
  ];
  $evLabel = ['route'=>'Routed','first_response'=>'First response','escalate'=>'Escalated','recovery'=>'Service recovery','confirm'=>'Confirmed','feedback'=>'Feedback'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <a href="<?= $base ?>" class="text-xs text-gray-500 hover:text-gray-700">&larr; All tickets</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-1"><?= htmlspecialchars((string) ($ticket['subject'] ?? ('Ticket #' . $tid))) ?></h1>
      <p class="text-sm text-gray-600">
        <?= htmlspecialchars(ucfirst((string) ($ticket['category'] ?? ''))) ?> ·
        <?= htmlspecialchars($propName) ?> ·
        <?= htmlspecialchars(ucfirst((string) ($ticket['priority'] ?? 'normal'))) ?> priority
      </p>
    </div>
    <div class="flex flex-col items-end gap-1">
      <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600"><?= htmlspecialchars($stLabel[$status] ?? ucfirst($status)) ?></span>
      <?php $sb = $slaBadge[$slaState] ?? $slaBadge['none']; ?>
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= $sb[1] ?>"><?= htmlspecialchars($sb[0]) ?></span>
      <?php if ((int) ($ext['esc_level'] ?? 0) > 0): ?>
        <span class="px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-700">Escalation L<?= (int) $ext['esc_level'] ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($ticket['detail'])): ?>
    <div class="card p-4"><p class="text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars((string) $ticket['detail']) ?></p></div>
  <?php endif; ?>

  <!-- Routing / SLA snapshot -->
  <div class="card p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
    <div><div class="text-xs text-gray-400">Department</div><div class="text-gray-900"><?= htmlspecialchars($depts[(string) ($ext['department'] ?? '')] ?? '—') ?></div></div>
    <div><div class="text-xs text-gray-400">Assignee</div><div class="text-gray-900"><?= htmlspecialchars((string) ($ext['assignee'] ?? '—') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-400">SLA due</div><div class="text-gray-900"><?= htmlspecialchars((string) ($ext['sla_due_at'] ?? '—') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-400">First response</div><div class="text-gray-900"><?= htmlspecialchars((string) ($ext['first_response_at'] ?? '—') ?: '—') ?></div></div>
  </div>

  <?php if ($canEdit): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <!-- Route / assign -->
    <form action="<?= $base ?>/route" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
      <h2 class="text-sm font-semibold text-gray-900">Route &amp; assign</h2>
      <select name="department" class="input text-sm w-full">
        <option value="">— Department —</option>
        <?php foreach ($depts as $k => $v): ?><option value="<?= htmlspecialchars((string) $k) ?>" <?= (($ext['department'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars((string) $v) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="assignee" value="<?= htmlspecialchars((string) ($ext['assignee'] ?? '')) ?>" class="input text-sm w-full" placeholder="Assignee (staff name)">
      <p class="text-xs text-gray-400">SLA recomputes from the ticket priority on save.</p>
      <button type="submit" class="btn emerald text-sm">Save routing</button>
    </form>

    <!-- SLA actions / escalation -->
    <div class="card p-5 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900">Response &amp; escalation</h2>
      <form action="<?= $base ?>/respond" method="POST">
        <?= CSRF::tokenField() ?><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
        <button type="submit" class="btn secondary text-sm w-full" <?= !empty($ext['first_response_at']) ? 'disabled' : '' ?>>
          <?= !empty($ext['first_response_at']) ? 'First response recorded' : 'Record first response' ?>
        </button>
      </form>
      <form action="<?= $base ?>/escalate" method="POST" class="space-y-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
        <input type="text" name="reason" class="input text-sm w-full" placeholder="Escalation reason">
        <button type="submit" class="btn secondary text-sm w-full">Escalate (L<?= min(3, (int) ($ext['esc_level'] ?? 0) + 1) ?>)</button>
      </form>
    </div>

    <!-- Service recovery -->
    <form action="<?= $base ?>/recovery" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
      <h2 class="text-sm font-semibold text-gray-900">Service recovery</h2>
      <select name="kind" class="input text-sm w-full"><?php foreach ($kinds as $k => $v): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $v) ?></option><?php endforeach; ?></select>
      <input type="number" step="0.01" min="0" name="amount" class="input text-sm w-full" placeholder="Amount">
      <input type="text" name="note" class="input text-sm w-full" placeholder="Note (optional)">
      <p class="text-xs text-gray-400">A <strong>Folio credit</strong> posts a credit note to the guest folio when the ticket is linked to an own-inventory booking invoice. Other kinds are recorded only.</p>
      <button type="submit" class="btn emerald text-sm">Record recovery</button>
    </form>

    <!-- Completion + feedback -->
    <div class="card p-5 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900">Completion &amp; feedback</h2>
      <form action="<?= $base ?>/confirm" method="POST" class="flex items-center gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
        <select name="by" class="input text-sm"><option value="guest">Guest</option><option value="staff">Staff</option></select>
        <button type="submit" class="btn secondary text-sm">Confirm completion</button>
      </form>
      <?php if (!empty($ext['confirmed_at'])): ?><p class="text-xs text-green-700">Confirmed by <?= htmlspecialchars((string) ($ext['confirmed_by'] ?? '')) ?> at <?= htmlspecialchars((string) $ext['confirmed_at']) ?></p><?php endif; ?>
      <form action="<?= $base ?>/feedback" method="POST" class="flex items-center gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="stay_id" value="<?= $sid ?>">
        <select name="rating" class="input text-sm"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= ((int) ($ext['sat_rating'] ?? 0) === $i) ? 'selected' : '' ?>><?= $i ?>/5</option><?php endfor; ?></select>
        <input type="text" name="comment" value="<?= htmlspecialchars((string) ($ext['sat_comment'] ?? '')) ?>" class="input text-sm flex-1" placeholder="Comment">
        <button type="submit" class="btn secondary text-sm">Save</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recovery history -->
  <?php if (!empty($recovery)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Recovery history</h2>
    <div class="space-y-1 text-sm">
      <?php foreach ($recovery as $r): ?>
        <div class="flex items-center justify-between border-b border-gray-50 py-1">
          <span class="text-gray-700"><?= htmlspecialchars($kinds[(string) ($r['kind'] ?? '')] ?? (string) ($r['kind'] ?? '')) ?><?= !empty($r['note']) ? ' — ' . htmlspecialchars((string) $r['note']) : '' ?></span>
          <span class="text-gray-900"><?= htmlspecialchars(number_format((float) ($r['amount'] ?? 0), 2)) ?><?= !empty($r['posted']) ? ' · posted' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Activity log -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Activity</h2>
    <?php if (empty($events)): ?>
      <p class="text-sm text-gray-400">No activity yet.</p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($events as $e): ?>
          <div class="flex items-start gap-2 text-sm">
            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 shrink-0"><?= htmlspecialchars($evLabel[(string) ($e['event'] ?? '')] ?? (string) ($e['event'] ?? '')) ?></span>
            <div>
              <span class="text-gray-700"><?= htmlspecialchars((string) ($e['detail'] ?? '')) ?></span>
              <span class="text-xs text-gray-400 block"><?= htmlspecialchars((string) ($e['created_at'] ?? '')) ?><?= !empty($e['actor']) ? ' · ' . htmlspecialchars((string) $e['actor']) : '' ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
