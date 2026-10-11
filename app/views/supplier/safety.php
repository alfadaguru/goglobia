<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $incidents = (isset($incidents) && is_array($incidents)) ? $incidents : [];
  $visitors = (isset($visitors) && is_array($visitors)) ? $visitors : [];
  $documents = (isset($documents) && is_array($documents)) ? $documents : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/safety';
  $sevColor = ['low'=>'text-gray-500','medium'=>'text-amber-600','high'=>'text-red-600'];
  $isColor = ['open'=>'bg-amber-50 text-amber-700','investigating'=>'bg-blue-50 text-blue-700','closed'=>'bg-green-50 text-green-700'];
  $propSel = function() use ($propList) { $o=''; foreach($propList as $sid=>$pn){ $o.='<option value="'.(int)$sid.'">'.htmlspecialchars((string)$pn).'</option>'; } return $o; };
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Safety &amp; compliance</h1><p class="text-sm text-gray-600">Incident log, visitor register, and compliance documents.</p></div>

  <!-- INCIDENTS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Incidents</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/incident" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <select name="kind" class="input text-xs"><option value="safety">Safety</option><option value="security">Security</option><option value="medical">Medical</option><option value="damage">Damage</option><option value="other">Other</option></select>
      <select name="severity" class="input text-xs"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
      <input type="text" name="summary" class="input text-xs flex-1 min-w-[160px]" placeholder="Summary *" required>
      <button type="submit" class="btn secondary text-xs">Log incident</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($incidents)): ?>
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($incidents as $i): $st = (string) $i['status']; ?>
          <tr>
            <td class="px-2 py-1.5"><span class="<?= $sevColor[(string) $i['severity']] ?? '' ?> text-xs font-semibold uppercase mr-1"><?= htmlspecialchars((string) $i['severity']) ?></span><?= htmlspecialchars((string) $i['summary']) ?><div class="text-xs text-gray-400"><?= htmlspecialchars(ucfirst((string) $i['kind'])) ?> · <?= htmlspecialchars($propMap[(int) $i['stay_id']] ?? ('#' . $i['stay_id'])) ?></div></td>
            <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $isColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-2 py-1.5 text-right">
              <form action="<?= $base ?>/incident/status" method="POST" class="inline flex items-center gap-1 justify-end">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $i['stay_id'] ?>">
                <select name="status" class="input text-xs"><?php foreach (['open','investigating','closed'] as $o): ?><option value="<?= $o ?>" <?= $o === $st ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?></select>
                <button class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="text-sm text-gray-400">No incidents.</p><?php endif; ?>
  </div>

  <!-- VISITORS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Visitor register</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/visitor" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <input type="text" name="visitor_name" class="input text-xs" placeholder="Visitor *" required>
      <input type="text" name="visiting" class="input text-xs" placeholder="Visiting (guest/room)">
      <input type="text" name="purpose" class="input text-xs" placeholder="Purpose">
      <button type="submit" class="btn secondary text-xs">Check in</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($visitors)): ?>
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($visitors as $v): $out = $v['checked_out_at'] !== null; ?>
          <tr>
            <td class="px-2 py-1.5"><?= htmlspecialchars((string) $v['visitor_name']) ?><?php if (!empty($v['visiting'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $v['visiting']) ?></span><?php endif; ?></td>
            <td class="px-2 py-1.5 text-xs text-gray-500">in <?= htmlspecialchars(substr((string) ($v['checked_in_at'] ?? ''), 11, 5)) ?><?= $out ? ' · out ' . htmlspecialchars(substr((string) $v['checked_out_at'], 11, 5)) : '' ?></td>
            <?php if ($canEdit): ?>
            <td class="px-2 py-1.5 text-right">
              <?php if (!$out): ?>
                <form action="<?= $base ?>/visitor/checkout" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $v['stay_id'] ?>"><button class="text-xs text-violet-600 hover:underline">Check out</button></form>
              <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="text-sm text-gray-400">No visitors.</p><?php endif; ?>
  </div>

  <!-- DOCUMENTS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Compliance documents</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/document" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <select name="doc_type" class="input text-xs"><option value="licence">Licence</option><option value="insurance">Insurance</option><option value="certificate">Certificate</option><option value="contract">Contract</option><option value="other">Other</option></select>
      <input type="text" name="title" class="input text-xs" placeholder="Title *" required>
      <input type="text" name="reference" class="input text-xs w-28" placeholder="Reference">
      <input type="date" name="expires_on" class="input text-xs">
      <button type="submit" class="btn secondary text-xs">Add</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($documents)): ?>
    <table class="w-full text-sm">
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($documents as $d): $exp = (string) ($d['expires_on'] ?? ''); $expiringSoon = $exp !== '' && $exp < date('Y-m-d', strtotime('+30 days')); ?>
          <tr>
            <td class="px-2 py-1.5"><?= htmlspecialchars((string) $d['title']) ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars(ucfirst((string) $d['doc_type'])) ?></span></td>
            <td class="px-2 py-1.5 text-xs <?= $expiringSoon ? 'text-red-600 font-medium' : 'text-gray-500' ?>"><?= $exp !== '' ? 'expires ' . htmlspecialchars($exp) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="text-sm text-gray-400">No documents.</p><?php endif; ?>
  </div>
</div>
