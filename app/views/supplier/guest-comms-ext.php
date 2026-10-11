<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 19 ext — templates, automations, send, response tracking.
  $templates   = (isset($templates) && is_array($templates)) ? $templates : [];
  $automations = (isset($automations) && is_array($automations)) ? $automations : [];
  $stats       = (isset($stats) && is_array($stats)) ? $stats : [];
  $propList    = (isset($propList) && is_array($propList)) ? $propList : [];
  $ready       = (isset($ready) && is_array($ready)) ? $ready : [];
  $triggers    = (isset($triggers) && is_array($triggers)) ? $triggers : [];
  $channels    = (isset($channels) && is_array($channels)) ? $channels : [];
  $canEdit     = !empty($canEdit);
  $base        = root . 'supplier/guest-comms';
  $chLabel     = ['any'=>'Any','email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS'];
  $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= $h($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Guest communication</h1>
      <p class="text-sm text-gray-600">Canned templates, automated messaging, and response tracking — one place per property.</p>
    </div>
    <a href="<?= $base === '' ? '#' : root . 'supplier/guest-messages' ?>" class="btn ghost text-sm">Message log</a>
  </div>

  <!-- RESPONSE TRACKING ROLLUP -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <?php
      $cards = [
        ['sent', 'Sent', 'send', 'text-emerald-600'],
        ['replied', 'Replied', 'reply', 'text-blue-600'],
        ['awaiting', 'Awaiting reply', 'schedule', 'text-amber-600'],
        ['skipped', 'Not delivered', 'block', 'text-gray-500'],
        ['failed', 'Failed', 'error', 'text-rose-600'],
      ];
      foreach ($cards as [$k, $lbl, $icon, $cls]):
    ?>
      <div class="card p-4">
        <div class="flex items-center gap-2 text-xs text-gray-500"><span class="material-symbols-outlined text-base <?= $cls ?>"><?= $icon ?></span><?= $h($lbl) ?></div>
        <div class="text-2xl font-bold text-gray-900 mt-1"><?= (int) ($stats[$k] ?? 0) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($propList)): ?>
    <div class="card p-8 text-center text-gray-400 text-sm">No properties yet — add a stay to start messaging guests.</div>
  <?php else: ?>

  <!-- CHANNEL READINESS (DB-GATED honesty) -->
  <div class="card p-4">
    <div class="text-sm font-semibold text-gray-800 mb-2">Delivery channels</div>
    <div class="flex flex-wrap gap-2">
      <?php foreach (['email'=>'Email','sms'=>'SMS','whatsapp'=>'WhatsApp'] as $ck => $cl): $on = !empty($ready[$ck]); ?>
        <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium <?= $on ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500' ?>">
          <span class="material-symbols-outlined text-sm"><?= $on ? 'check_circle' : 'cancel' ?></span><?= $h($cl) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-400 mt-2">Unconfigured channels still log the message but will not deliver it.</p>
  </div>

  <?php if ($canEdit): ?>
  <!-- SEND -->
  <form action="<?= $base ?>/send" method="POST" class="card p-5 space-y-3">
    <?= CSRF::tokenField() ?>
    <div class="text-sm font-semibold text-gray-800">Send a message</div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= $h($pn) ?></option><?php endforeach; ?></select>
      <select name="channel" class="input text-sm">
        <option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option><option value="note">Internal note</option>
      </select>
      <select name="template_id" class="input text-sm">
        <option value="0">No template</option>
        <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="guest_name" class="input text-sm" placeholder="Guest name">
      <input type="email" name="guest_email" class="input text-sm" placeholder="Guest email (email)">
      <input type="text" name="guest_phone" class="input text-sm" placeholder="Guest phone (SMS/WhatsApp)">
      <input type="text" name="invoice_id" class="input text-sm md:col-span-3" placeholder="Invoice (optional)">
      <input type="text" name="subject" class="input text-sm md:col-span-3" placeholder="Subject (email)">
    </div>
    <textarea name="body" rows="3" class="input text-sm w-full" placeholder="Message body (leave blank to use the selected template)"></textarea>
    <div><button type="submit" class="btn emerald text-sm">Send / log</button></div>
  </form>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- TEMPLATES -->
    <div class="card divide-y divide-gray-50">
      <div class="p-4 flex items-center justify-between"><div class="text-sm font-semibold text-gray-800">Canned templates</div></div>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/template/add" method="POST" class="p-4 space-y-2 bg-gray-50">
        <?= CSRF::tokenField() ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= $h($pn) ?></option><?php endforeach; ?></select>
          <select name="channel" class="input text-sm"><?php foreach ($channels as $o => $ol): ?><option value="<?= $h($o) ?>"><?= $h($ol) ?></option><?php endforeach; ?></select>
          <input type="text" name="name" class="input text-sm md:col-span-2" placeholder="Template name *" required>
          <input type="text" name="subject" class="input text-sm md:col-span-2" placeholder="Subject (email, optional)">
        </div>
        <textarea name="body" rows="2" class="input text-sm w-full" placeholder="Body — use {guest_name} {property} {invoice} *" required></textarea>
        <button type="submit" class="btn emerald text-sm">Save template</button>
      </form>
      <?php endif; ?>
      <?php if (empty($templates)): ?>
        <div class="p-8 text-center text-gray-400 text-sm">No templates yet.</div>
      <?php else: foreach ($templates as $t): ?>
        <div class="flex items-start gap-3 p-4">
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-gray-900"><?= $h($t['name']) ?> <span class="text-xs text-gray-400">· <?= $h($chLabel[(string) $t['channel']] ?? (string) $t['channel']) ?></span></div>
            <?php if (!empty($t['subject'])): ?><div class="text-xs text-gray-500"><?= $h($t['subject']) ?></div><?php endif; ?>
            <p class="text-sm text-gray-700 whitespace-pre-line mt-0.5 line-clamp-3"><?= $h($t['body']) ?></p>
          </div>
          <?php if ($canEdit): ?>
          <form action="<?= $base ?>/template/delete" method="POST" onsubmit="return confirm('Remove this template?');">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="stay_id" value="<?= (int) $t['stay_id'] ?>">
            <button type="submit" class="text-gray-400 hover:text-rose-600"><span class="material-symbols-outlined text-base">delete</span></button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- AUTOMATIONS -->
    <div class="card divide-y divide-gray-50">
      <div class="p-4 flex items-center justify-between"><div class="text-sm font-semibold text-gray-800">Automated messaging</div></div>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/automation/add" method="POST" class="p-4 space-y-2 bg-gray-50">
        <?= CSRF::tokenField() ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= $h($pn) ?></option><?php endforeach; ?></select>
          <select name="trigger_event" class="input text-sm" required><?php foreach ($triggers as $tk => $tl): ?><option value="<?= $h($tk) ?>"><?= $h($tl) ?></option><?php endforeach; ?></select>
          <select name="template_id" class="input text-sm" required>
            <option value="">Template…</option>
            <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $h($t['name']) ?></option><?php endforeach; ?>
          </select>
          <select name="channel" class="input text-sm"><option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select>
        </div>
        <button type="submit" class="btn emerald text-sm">Add rule</button>
        <p class="text-xs text-gray-400">Rules are stored and property-scoped. Dispatch runs from a cron trigger (DB-GATED — not auto-firing yet).</p>
      </form>
      <?php endif; ?>
      <?php if (empty($automations)): ?>
        <div class="p-8 text-center text-gray-400 text-sm">No automation rules yet.</div>
      <?php else: foreach ($automations as $a): $on = !empty($a['active']); ?>
        <div class="flex items-center gap-3 p-4">
          <span class="material-symbols-outlined text-base <?= $on ? 'text-emerald-500' : 'text-gray-300' ?>">bolt</span>
          <div class="flex-1 min-w-0">
            <div class="text-sm text-gray-900"><?= $h($triggers[(string) $a['trigger_event']] ?? (string) $a['trigger_event']) ?></div>
            <div class="text-xs text-gray-500"><?= $h($a['template_name'] ?? ('#' . ($a['template_id'] ?? ''))) ?> · <?= $h($a['channel']) ?></div>
          </div>
          <?php if ($canEdit): ?>
          <form action="<?= $base ?>/automation/toggle" method="POST">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>">
            <input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
            <button type="submit" class="text-xs <?= $on ? 'text-amber-600' : 'text-emerald-600' ?>"><?= $on ? 'Disable' : 'Enable' ?></button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>
