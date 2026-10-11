<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $tickets = (isset($tickets) && is_array($tickets)) ? $tickets : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/service-tickets';
  $stColor = ['open'=>'bg-amber-50 text-amber-700','in_progress'=>'bg-blue-50 text-blue-700','resolved'=>'bg-green-50 text-green-700','closed'=>'bg-gray-100 text-gray-500'];
  $stLabel = ['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed'];
  $prColor = ['low'=>'text-gray-400','normal'=>'text-gray-600','high'=>'text-red-600'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Service &amp; complaints</h1><p class="text-sm text-gray-600">Log guest requests and complaints, assign them, and track to resolution.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <select name="category" class="input text-sm"><option value="request">Request</option><option value="complaint">Complaint</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select>
    <input type="text" name="subject" class="input text-sm" placeholder="Subject *" required>
    <select name="priority" class="input text-sm"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>
    <input type="text" name="guest_name" class="input text-sm" placeholder="Guest (optional)">
    <input type="text" name="invoice_id" class="input text-sm" placeholder="Invoice (optional)">
    <textarea name="detail" rows="2" class="input text-sm md:col-span-2" placeholder="Details"></textarea>
    <div class="md:col-span-2"><button type="submit" class="btn emerald text-sm">Create ticket</button></div>
  </form>
  <?php endif; ?>

  <div class="space-y-3">
    <?php if (empty($tickets)): ?>
      <div class="card p-8 text-center text-gray-400 text-sm">No tickets.</div>
    <?php else: foreach ($tickets as $t): $st = (string) $t['status']; ?>
      <div class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $t['subject']) ?></span>
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars($stLabel[$st] ?? ucfirst($st)) ?></span>
              <span class="text-xs <?= $prColor[(string) $t['priority']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $t['priority'])) ?></span>
            </div>
            <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(ucfirst((string) $t['category'])) ?> · <?= htmlspecialchars($propMap[(int) $t['stay_id']] ?? ('#' . $t['stay_id'])) ?><?= !empty($t['guest_name']) ? ' · ' . htmlspecialchars((string) $t['guest_name']) : '' ?></div>
            <?php if (!empty($t['detail'])): ?><p class="text-sm text-gray-700 mt-1 whitespace-pre-line"><?= htmlspecialchars((string) $t['detail']) ?></p><?php endif; ?>
            <?php if (!empty($t['resolution'])): ?><p class="text-xs text-green-700 mt-1">Resolution: <?= htmlspecialchars((string) $t['resolution']) ?></p><?php endif; ?>
            <?php if (!empty($t['assigned_to'])): ?><p class="text-xs text-gray-400 mt-0.5">Assigned: <?= htmlspecialchars((string) $t['assigned_to']) ?></p><?php endif; ?>
          </div>
        </div>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/update" method="POST" class="mt-2 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-2">
          <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $t['stay_id'] ?>">
          <select name="status" class="input text-xs"><?php foreach ($stLabel as $o => $ol): ?><option value="<?= $o ?>" <?= $o === $st ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
          <input type="text" name="assigned_to" value="<?= htmlspecialchars((string) ($t['assigned_to'] ?? '')) ?>" class="input text-xs w-28" placeholder="assign">
          <input type="text" name="resolution" value="<?= htmlspecialchars((string) ($t['resolution'] ?? '')) ?>" class="input text-xs flex-1 min-w-[160px]" placeholder="resolution">
          <button type="submit" class="btn secondary text-xs">Save</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
