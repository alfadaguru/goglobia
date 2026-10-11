<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $messages = (isset($messages) && is_array($messages)) ? $messages : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/guest-messages';
  $chLabel = ['note'=>'Note','email'=>'Email','phone'=>'Phone','whatsapp'=>'WhatsApp','sms'=>'SMS'];
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Guest messages</h1><p class="text-sm text-gray-600">A log of guest communications across channels — a single history per property.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <select name="channel" class="input text-sm"><?php foreach ($chLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
    <select name="direction" class="input text-sm"><option value="out">Outgoing</option><option value="in">Incoming</option></select>
    <input type="text" name="invoice_id" class="input text-sm" placeholder="Invoice (optional)">
    <input type="email" name="guest_email" class="input text-sm" placeholder="Guest email (optional)">
    <textarea name="body" rows="2" class="input text-sm md:col-span-2" placeholder="Message *" required></textarea>
    <div class="md:col-span-2"><button type="submit" class="btn emerald text-sm">Log message</button></div>
  </form>
  <?php endif; ?>

  <div class="card divide-y divide-gray-50">
    <?php if (empty($messages)): ?>
      <div class="p-8 text-center text-gray-400 text-sm">No messages logged.</div>
    <?php else: foreach ($messages as $m): $in = (string) ($m['direction'] ?? 'out') === 'in'; ?>
      <div class="flex items-start gap-3 p-4">
        <span class="material-symbols-outlined text-base mt-0.5 <?= $in ? 'text-blue-500' : 'text-violet-500' ?>"><?= $in ? 'call_received' : 'send' ?></span>
        <div class="flex-1 min-w-0">
          <div class="text-xs text-gray-500"><?= htmlspecialchars($chLabel[(string) $m['channel']] ?? (string) $m['channel']) ?> · <?= $in ? 'incoming' : 'outgoing' ?> · <?= htmlspecialchars($propMap[(int) $m['stay_id']] ?? ('#' . $m['stay_id'])) ?><?= !empty($m['guest_email']) ? ' · ' . htmlspecialchars((string) $m['guest_email']) : '' ?></div>
          <p class="text-sm text-gray-800 whitespace-pre-line mt-0.5"><?= htmlspecialchars((string) $m['body']) ?></p>
        </div>
        <div class="text-xs text-gray-400 whitespace-nowrap"><?= htmlspecialchars(substr((string) $m['created_at'], 0, 16)) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
