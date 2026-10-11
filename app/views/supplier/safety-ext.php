<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $incidents   = (isset($incidents) && is_array($incidents)) ? $incidents : [];
  $evidence    = (isset($evidence) && is_array($evidence)) ? $evidence : [];
  $shifts      = (isset($shifts) && is_array($shifts)) ? $shifts : [];
  $procedures  = (isset($procedures) && is_array($procedures)) ? $procedures : [];
  $inspections = (isset($inspections) && is_array($inspections)) ? $inspections : [];
  $damage      = (isset($damage) && is_array($damage)) ? $damage : [];
  $propMap     = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList    = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit     = !empty($canEdit);
  $base = root . 'supplier/safety/ext';
  $propName = function($sid) use ($propMap) { return htmlspecialchars($propMap[(int) $sid] ?? ('#' . (int) $sid)); };
  $propSel = function() use ($propList) { $o=''; foreach($propList as $sid=>$pn){ $o.='<option value="'.(int)$sid.'">'.htmlspecialchars((string)$pn).'</option>'; } return $o; };
  // open incidents, for the evidence/escalate/close picker
  $openIncidents = array_values(array_filter($incidents, function($i){ return ($i['status'] ?? '') !== 'closed'; }));
  $incSel = function() use ($openIncidents, $propMap) { $o=''; foreach($openIncidents as $i){ $o.='<option value="'.(int)$i['id'].'" data-stay="'.(int)$i['stay_id'].'">#'.(int)$i['id'].' '.htmlspecialchars(substr((string)$i['summary'],0,40)).'</option>'; } return $o; };
  $sevColor = ['low'=>'text-gray-500','medium'=>'text-amber-600','high'=>'text-red-600'];
  $resColor = ['pass'=>'bg-green-50 text-green-700','conditional'=>'bg-amber-50 text-amber-700','fail'=>'bg-red-50 text-red-700'];
  $dmgColor = ['open'=>'bg-amber-50 text-amber-700','assessed'=>'bg-blue-50 text-blue-700','resolved'=>'bg-green-50 text-green-700','waived'=>'bg-gray-100 text-gray-600'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Safety console</h1><p class="text-sm text-gray-600">Incident evidence &amp; escalation, security shifts, emergency procedures, inspections and damage claims.</p></div>
    <a href="<?= root ?>supplier/safety" class="text-xs text-violet-600 hover:underline">&larr; Incident &amp; visitor log</a>
  </div>

  <!-- INCIDENT EVIDENCE / ESCALATION / CLOSURE -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Open incidents &mdash; evidence, escalation &amp; closure</h2>
    <?php if (empty($openIncidents)): ?>
      <p class="text-sm text-gray-400">No open incidents. Log one on the <a href="<?= root ?>supplier/safety" class="text-violet-600 hover:underline">incident log</a>.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($openIncidents as $i): $ic = (int) ($evidence[(int) $i['id']] ?? 0); ?>
            <tr>
              <td class="px-2 py-1.5">
                <span class="<?= $sevColor[(string) $i['severity']] ?? '' ?> text-xs font-semibold uppercase mr-1"><?= htmlspecialchars((string) $i['severity']) ?></span>
                #<?= (int) $i['id'] ?> <?= htmlspecialchars((string) $i['summary']) ?>
                <div class="text-xs text-gray-400"><?= $propName($i['stay_id']) ?> · <?= htmlspecialchars(ucfirst((string) $i['status'])) ?><?php if (!empty($i['escalated'])): ?> · <span class="text-red-600">escalated&rarr;<?= htmlspecialchars((string) ($i['escalated_to'] ?? '')) ?></span><?php endif; ?> · <?= (int) $ic ?> evidence</div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($canEdit): ?>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
        <!-- evidence -->
        <form action="<?= $base ?>/evidence" method="POST" enctype="multipart/form-data" class="border border-gray-100 rounded-lg p-3 space-y-2">
          <?= CSRF::tokenField() ?>
          <div class="text-xs font-semibold text-gray-700">Attach evidence</div>
          <select name="incident_id" class="input text-xs w-full js-inc"><?= $incSel() ?></select>
          <input type="hidden" name="stay_id" class="js-stay" value="">
          <select name="evidence_type" class="input text-xs w-full"><option value="photo">Photo</option><option value="document">Document</option><option value="video">Video</option><option value="note">Note</option></select>
          <input type="file" name="evidence_file" class="input text-xs w-full">
          <input type="text" name="note" class="input text-xs w-full" placeholder="Note">
          <button class="btn secondary text-xs w-full">Attach</button>
        </form>
        <!-- escalate -->
        <form action="<?= $base ?>/escalate" method="POST" class="border border-gray-100 rounded-lg p-3 space-y-2">
          <?= CSRF::tokenField() ?>
          <div class="text-xs font-semibold text-gray-700">Escalate</div>
          <select name="incident_id" class="input text-xs w-full js-inc"><?= $incSel() ?></select>
          <input type="hidden" name="stay_id" class="js-stay" value="">
          <input type="text" name="escalated_to" class="input text-xs w-full" placeholder="To (manager / police) *" required>
          <input type="text" name="escalation_note" class="input text-xs w-full" placeholder="Note">
          <button class="btn secondary text-xs w-full">Escalate</button>
        </form>
        <!-- close -->
        <form action="<?= $base ?>/close" method="POST" class="border border-gray-100 rounded-lg p-3 space-y-2">
          <?= CSRF::tokenField() ?>
          <div class="text-xs font-semibold text-gray-700">Close</div>
          <select name="incident_id" class="input text-xs w-full js-inc"><?= $incSel() ?></select>
          <input type="hidden" name="stay_id" class="js-stay" value="">
          <textarea name="resolution" class="input text-xs w-full" rows="2" placeholder="Resolution (required) *" required></textarea>
          <button class="btn secondary text-xs w-full">Close incident</button>
        </form>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- SECURITY SHIFT LOG -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Security shift log</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/shift/open" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <input type="text" name="officer" class="input text-xs" placeholder="Officer *" required>
      <input type="text" name="post" class="input text-xs" placeholder="Post (gate/lobby)">
      <button class="btn secondary text-xs">Start shift</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($shifts)): ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($shifts as $s): $open = $s['ended_at'] === null; ?>
          <tr>
            <td class="px-2 py-1.5"><?= htmlspecialchars((string) $s['officer']) ?><?php if (!empty($s['post'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $s['post']) ?></span><?php endif; ?><div class="text-xs text-gray-400"><?= $propName($s['stay_id']) ?></div></td>
            <td class="px-2 py-1.5 text-xs text-gray-500"><?= htmlspecialchars(substr((string) ($s['started_at'] ?? ''), 5, 11)) ?><?= $open ? '' : ' → ' . htmlspecialchars(substr((string) $s['ended_at'], 11, 5)) ?><?php if (!empty($s['handover'])): ?><div class="text-gray-400">↝ <?= htmlspecialchars(substr((string) $s['handover'], 0, 80)) ?></div><?php endif; ?></td>
            <td class="px-2 py-1.5 text-right">
              <?php if ($open && $canEdit): ?>
                <form action="<?= $base ?>/shift/close" method="POST" class="inline-flex items-center gap-1">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $s['stay_id'] ?>">
                  <input type="text" name="handover" class="input text-xs w-36" placeholder="Handover note">
                  <button class="text-xs text-violet-600 hover:underline">End</button>
                </form>
              <?php elseif ($open): ?><span class="px-2 py-0.5 rounded text-xs bg-green-50 text-green-700">on duty</span>
              <?php else: ?><span class="text-xs text-gray-300">ended</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?><p class="text-sm text-gray-400">No shifts logged.</p><?php endif; ?>
  </div>

  <!-- EMERGENCY PROCEDURES -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Emergency procedures</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/procedure" method="POST" class="space-y-2 mb-3">
      <?= CSRF::tokenField() ?>
      <div class="flex flex-wrap items-end gap-2">
        <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
        <select name="kind" class="input text-xs"><option value="fire">Fire</option><option value="medical">Medical</option><option value="evacuation">Evacuation</option><option value="security">Security</option><option value="natural">Natural</option><option value="other">Other</option></select>
        <input type="text" name="title" class="input text-xs flex-1 min-w-[160px]" placeholder="Title *" required>
        <input type="text" name="assembly_point" class="input text-xs" placeholder="Assembly point">
      </div>
      <textarea name="steps" class="input text-xs w-full" rows="2" placeholder="Steps"></textarea>
      <div class="flex gap-2"><input type="text" name="contacts" class="input text-xs flex-1" placeholder="Emergency contacts"><button class="btn secondary text-xs">Save procedure</button></div>
    </form>
    <?php endif; ?>
    <?php if (!empty($procedures)): ?>
    <div class="space-y-2">
      <?php foreach ($procedures as $p): ?>
        <div class="border border-gray-100 rounded-lg p-3">
          <div class="text-sm font-medium text-gray-900"><span class="text-xs uppercase text-violet-600 mr-1"><?= htmlspecialchars((string) $p['kind']) ?></span><?= htmlspecialchars((string) $p['title']) ?> <span class="text-xs text-gray-400">· <?= $propName($p['stay_id']) ?></span></div>
          <?php if (!empty($p['steps'])): ?><div class="text-xs text-gray-600 mt-1 whitespace-pre-line"><?= htmlspecialchars((string) $p['steps']) ?></div><?php endif; ?>
          <div class="text-xs text-gray-400 mt-1"><?php if (!empty($p['assembly_point'])): ?>Assembly: <?= htmlspecialchars((string) $p['assembly_point']) ?><?php endif; ?><?php if (!empty($p['contacts'])): ?> · <?= htmlspecialchars((string) $p['contacts']) ?><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="text-sm text-gray-400">No procedures documented.</p><?php endif; ?>
  </div>

  <!-- FIRE / SAFETY INSPECTIONS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Fire &amp; safety inspections</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/inspection" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <select name="inspection_type" class="input text-xs"><option value="fire">Fire</option><option value="electrical">Electrical</option><option value="gas">Gas</option><option value="water">Water</option><option value="general">General</option><option value="other">Other</option></select>
      <select name="result" class="input text-xs"><option value="pass">Pass</option><option value="conditional">Conditional</option><option value="fail">Fail</option></select>
      <input type="date" name="inspected_on" class="input text-xs" value="<?= date('Y-m-d') ?>">
      <input type="text" name="inspector" class="input text-xs" placeholder="Inspector">
      <label class="text-xs text-gray-500">Next due <input type="date" name="next_due" class="input text-xs"></label>
      <button class="btn secondary text-xs">Record</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($inspections)): ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($inspections as $ins): $nd = (string) ($ins['next_due'] ?? ''); $due = $nd !== '' && $nd < date('Y-m-d', strtotime('+30 days')); ?>
          <tr>
            <td class="px-2 py-1.5"><?= htmlspecialchars(ucfirst((string) $ins['inspection_type'])) ?><div class="text-xs text-gray-400"><?= $propName($ins['stay_id']) ?> · <?= htmlspecialchars((string) $ins['inspected_on']) ?><?php if (!empty($ins['inspector'])): ?> · <?= htmlspecialchars((string) $ins['inspector']) ?><?php endif; ?></div></td>
            <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $resColor[(string) $ins['result']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $ins['result'])) ?></span></td>
            <td class="px-2 py-1.5 text-xs <?= $due ? 'text-red-600 font-medium' : 'text-gray-500' ?>"><?= $nd !== '' ? 'next ' . htmlspecialchars($nd) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?><p class="text-sm text-gray-400">No inspections recorded.</p><?php endif; ?>
  </div>

  <!-- DAMAGE CLAIMS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Damage claims</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/damage" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <input type="text" name="item" class="input text-xs flex-1 min-w-[140px]" placeholder="Item damaged *" required>
      <input type="text" name="location" class="input text-xs" placeholder="Location/room">
      <input type="text" name="invoice_id" class="input text-xs w-36" placeholder="Booking ref (to bill)">
      <input type="number" step="0.01" min="0" name="est_cost" class="input text-xs w-24" placeholder="Est.">
      <button class="btn secondary text-xs">Log claim</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($damage)): ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($damage as $c): $st = (string) $c['status']; $billed = (float) ($c['billed_amount'] ?? 0); ?>
          <tr>
            <td class="px-2 py-1.5"><?= htmlspecialchars((string) $c['item']) ?><div class="text-xs text-gray-400"><?= $propName($c['stay_id']) ?><?php if (!empty($c['location'])): ?> · <?= htmlspecialchars((string) $c['location']) ?><?php endif; ?><?php if (!empty($c['invoice_id'])): ?> · booking <?= htmlspecialchars((string) $c['invoice_id']) ?><?php endif; ?></div></td>
            <td class="px-2 py-1.5 text-xs text-gray-500">est <?= number_format((float) ($c['est_cost'] ?? 0), 2) ?><?php if ($billed > 0): ?><div class="text-green-600">billed <?= number_format($billed, 2) ?></div><?php endif; ?></td>
            <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $dmgColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-2 py-1.5 text-right">
              <div class="inline-flex flex-wrap items-center gap-1 justify-end">
                <form action="<?= $base ?>/damage/status" method="POST" class="inline-flex items-center gap-1">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>">
                  <select name="status" class="input text-xs"><?php foreach (['open','assessed','resolved','waived'] as $o): ?><option value="<?= $o ?>" <?= $o === $st ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?></select>
                  <button class="text-xs text-violet-600 hover:underline">Save</button>
                </form>
                <?php if ($billed <= 0 && !empty($c['invoice_id'])): ?>
                <form action="<?= $base ?>/damage/bill" method="POST" class="inline-flex items-center gap-1" onsubmit="return confirm('Bill this amount to the guest folio?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>">
                  <input type="number" step="0.01" min="0.01" name="amount" class="input text-xs w-20" placeholder="Amt" value="<?= htmlspecialchars((string) number_format((float) ($c['est_cost'] ?? 0), 2, '.', '')) ?>">
                  <button class="text-xs text-red-600 hover:underline">Bill folio</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="text-xs text-gray-400 mt-2">Billing posts a single charge line to the guest's open folio (relieved to the ledger at checkout). Requires a linked booking ref with an open folio.</p>
    <?php else: ?><p class="text-sm text-gray-400">No damage claims.</p><?php endif; ?>
  </div>
</div>

<script>
// Keep the hidden stay_id in sync with the chosen incident (evidence/escalate/close forms).
(function(){
  document.querySelectorAll('.js-inc').forEach(function(sel){
    var sync = function(){
      var opt = sel.options[sel.selectedIndex];
      var stay = opt ? opt.getAttribute('data-stay') : '';
      var form = sel.closest('form');
      if (form){ var h = form.querySelector('.js-stay'); if (h){ h.value = stay || ''; } }
    };
    sel.addEventListener('change', sync); sync();
  });
})();
</script>
