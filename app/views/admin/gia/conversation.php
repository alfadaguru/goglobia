<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin GIA conversation drill-down. Expects: $conv, $messages, $events, $lead.
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="container mx-auto px-4 py-4">

  <div class="mb-4 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <i class="material-symbols-outlined text-blue-600">forum</i>
      <div>
        <h1 class="text-xl font-bold text-gray-900">Conversation #<?php echo (int)$conv['id']; ?></h1>
        <p class="text-sm text-gray-600">
          <?php echo $e(ucfirst((string)($conv['context'] ?: 'general'))); ?> ·
          <?php echo $e($conv['surface'] ?? ''); ?> ·
          started <?php echo $e($conv['started_at'] ?? ''); ?>
        </p>
      </div>
    </div>
    <a href="<?php echo root.admin; ?>/gia" class="text-sm text-blue-600 hover:underline">&larr; Back to GIA reports</a>
  </div>

  <!-- Who + rollups -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Messages</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['msg_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Searches</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['search_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Bookings</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['booking_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Revenue</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo (float)($conv['revenue'] ?? 0) > 0 ? $e($conv['revenue_currency'] ?: '') . ' ' . number_format((float)$conv['revenue'], 2) : '—'; ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Tokens (in/out)</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['token_in'] ?? 0)); ?> / <?php echo number_format((int)($conv['token_out'] ?? 0)); ?></p>
    </div>
  </div>

  <?php if ($lead): ?>
    <div class="card p-0 mb-4">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">contact_mail</span> Lead</div></div>
      <div class="card-body text-sm text-gray-700">
        <span class="font-semibold"><?php echo $e($lead['name'] ?: '—'); ?></span>
        &nbsp;·&nbsp; <?php echo $e($lead['email'] ?: ''); ?>
        <?php if (!empty($lead['phone'])): ?>&nbsp;·&nbsp; <?php echo $e($lead['phone']); ?><?php endif; ?>
        &nbsp;·&nbsp; <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700"><?php echo $e(ucfirst((string)$lead['status'])); ?></span>
        <?php if (!empty($lead['user_id'])): ?>&nbsp;·&nbsp; <span class="text-xs text-gray-500">user: <?php echo $e($lead['user_id']); ?></span><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php
    $giaCsrf = class_exists('CSRF') ? CSRF::getToken() : '';
    $staffActive = (int) ($conv['staff_active'] ?? 0) === 1;
  ?>
  <!-- Human handoff controls -->
  <div class="card p-0 mb-4" id="gia-handoff" data-csrf="<?php echo $e($giaCsrf); ?>" data-base="<?php echo $e(root.admin); ?>" data-id="<?php echo (int)$conv['id']; ?>">
    <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">support_agent</span> Live handoff</div></div>
    <div class="card-body">
      <p class="text-xs text-gray-600 mb-2">
        <?php if ($staffActive): ?>
          <span class="text-emerald-700 font-semibold">You have taken over this chat.</span> GIA is paused; your replies go straight to the traveller.
        <?php else: ?>
          GIA is handling this chat. Take over to reply as a human (GIA pauses until you hand back).
        <?php endif; ?>
      </p>
      <div class="flex gap-2 mb-2">
        <button type="button" id="gia-take" class="text-xs font-semibold px-3 py-1.5 rounded-lg text-white" style="background:#0058E6" <?php echo $staffActive ? 'style="display:none"' : ''; ?>>Take over</button>
        <button type="button" id="gia-release" class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700" <?php echo $staffActive ? '' : 'style="display:none"'; ?>>Hand back to GIA</button>
      </div>
      <div class="flex gap-2">
        <textarea id="gia-staff-text" rows="2" placeholder="Type a reply to the traveller…" class="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
        <button type="button" id="gia-send" class="shrink-0 px-4 rounded-lg text-white font-semibold text-sm" style="background:#16a34a">Send</button>
      </div>
      <p class="text-[11px] text-gray-400 mt-1">Sending a reply automatically takes over the chat. The traveller sees it within a few seconds.</p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Transcript -->
    <div class="card p-0 lg:col-span-2">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">chat</span> Transcript</div></div>
      <div class="card-body space-y-2" style="max-height:70vh; overflow-y:auto">
        <?php if (empty($messages)): ?>
          <p class="text-sm text-gray-400">No messages recorded.</p>
        <?php else: foreach ($messages as $m): $isUser = ($m['role'] ?? '') === 'user'; ?>
          <div class="flex <?php echo $isUser ? 'justify-end' : 'justify-start'; ?>">
            <div class="max-w-[80%] px-3 py-2 rounded-2xl text-sm <?php echo $isUser ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-800'; ?>">
              <div class="whitespace-pre-wrap"><?php echo nl2br($e($m['text'] ?? '')); ?></div>
              <div class="text-[10px] mt-1 <?php echo $isUser ? 'text-blue-100' : 'text-gray-400'; ?>"><?php echo $e($m['created_at'] ?? ''); ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Events timeline -->
    <div class="card p-0">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">timeline</span> Events</div></div>
      <div class="card-body" style="max-height:70vh; overflow-y:auto">
        <?php if (empty($events)): ?>
          <p class="text-sm text-gray-400">No events.</p>
        <?php else: foreach ($events as $ev): ?>
          <div class="flex items-start gap-2 py-1.5 border-b last:border-0">
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-700"><?php echo $e($ev['type'] ?? ''); ?></span>
            <div class="text-xs text-gray-600 flex-1">
              <?php if (!empty($ev['module'])): ?><span class="capitalize font-medium"><?php echo $e($ev['module']); ?></span> <?php endif; ?>
              <?php if (($ev['amount'] ?? null) !== null && (float)$ev['amount'] > 0): ?>
                <span class="text-gray-800"><?php echo $e($ev['currency'] ?: ''); ?> <?php echo number_format((float)$ev['amount'], 2); ?></span>
              <?php endif; ?>
              <?php if (!empty($ev['ref'])): ?><div class="text-[10px] text-gray-400 truncate">ref: <?php echo $e($ev['ref']); ?></div><?php endif; ?>
              <div class="text-[10px] text-gray-400"><?php echo $e($ev['created_at'] ?? ''); ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
(function(){
  var box = document.getElementById('gia-handoff');
  if(!box) return;
  var csrf = box.getAttribute('data-csrf') || '';
  var base = box.getAttribute('data-base') || '';
  var id = box.getAttribute('data-id') || '';
  function post(path, extra){
    var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('id', id);
    Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(base + path, { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: fd })
      .then(function(r){ return r.json().catch(function(){ return {}; }); });
  }
  var take = document.getElementById('gia-take');
  var rel = document.getElementById('gia-release');
  var send = document.getElementById('gia-send');
  var txt = document.getElementById('gia-staff-text');
  if(take) take.addEventListener('click', function(){ post('/gia/conversation/handoff', {mode:'take'}).then(function(d){ if(d&&d.status) location.reload(); }); });
  if(rel) rel.addEventListener('click', function(){ post('/gia/conversation/handoff', {mode:'release'}).then(function(d){ if(d&&d.status) location.reload(); }); });
  if(send) send.addEventListener('click', function(){
    var t = (txt && txt.value || '').trim();
    if(!t){ return; }
    send.disabled = true;
    post('/gia/conversation/staff-reply', {text:t}).then(function(d){
      send.disabled = false;
      if(d && d.status){ location.reload(); } else { alert((d&&d.message)||'Could not send.'); }
    });
  });
})();
</script>
