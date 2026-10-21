<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin GIA Channels panel. Expects (from giaRoutes): $channels (normalized
// per-channel config) and $webhooks (channel => inbound URL). Admin has full
// control: enable/disable, credentials, the webhook URL + verify token to paste
// into each provider console, and a Test button. All values escaped at output.
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = class_exists('CSRF') ? CSRF::getToken() : '';
$wa = $channels['whatsapp'] ?? [];
$fb = $channels['messenger'] ?? [];
$tg = $channels['telegram'] ?? [];
?>
<div class="container mx-auto px-4 py-4" id="gia-channels"
     data-csrf="<?php echo $e($csrf); ?>" data-base="<?php echo $e(root . admin); ?>">

  <div class="mb-4 flex items-center gap-2">
    <i class="material-symbols-outlined text-blue-600">hub</i>
    <div class="flex-1">
      <h1 class="text-xl font-bold text-gray-900">GIA Channels</h1>
      <p class="text-sm text-gray-600">Let GIA chat with travelers on WhatsApp, Messenger and Telegram. You control each one.</p>
    </div>
  </div>

  <div id="gia-ch-flash" class="hidden mb-3 rounded-lg px-4 py-2 text-sm"></div>

  <form id="gia-ch-form" class="space-y-4">

    <?php
    // Reusable field renderer.
    $field = function (string $name, string $label, string $val, string $type = 'text', string $hint = '') use ($e) {
        $id = 'f_' . preg_replace('/[^a-z0-9]/i', '_', $name);
        echo '<label class="block">';
        echo '<span class="text-xs font-semibold text-gray-600">' . $e($label) . '</span>';
        echo '<input type="' . $e($type) . '" name="' . $e($name) . '" id="' . $e($id) . '" value="' . $e($val) . '" '
           . 'class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" autocomplete="off">';
        if ($hint !== '') { echo '<span class="text-[11px] text-gray-400">' . $e($hint) . '</span>'; }
        echo '</label>';
    };
    // Reusable read-only "copy this" row (webhook URL / verify token).
    $copyRow = function (string $label, string $val) use ($e) {
        if ($val === '') { return; }
        echo '<div class="mt-1">';
        echo '<span class="text-xs font-semibold text-gray-600">' . $e($label) . '</span>';
        echo '<div class="mt-1 flex items-center gap-2">';
        echo '<input type="text" readonly value="' . $e($val) . '" class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-700">';
        echo '<button type="button" class="gia-copy text-xs font-semibold px-2 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" data-copy="' . $e($val) . '">Copy</button>';
        echo '</div></div>';
    };
    // Reusable channel card header with enable toggle + test button.
    $cardHead = function (string $chan, string $title, string $icon, bool $enabled) use ($e) {
        echo '<div class="flex items-center gap-3 mb-3">';
        echo '<i class="material-symbols-outlined text-blue-600">' . $e($icon) . '</i>';
        echo '<h2 class="flex-1 text-base font-bold text-gray-900">' . $e($title) . '</h2>';
        echo '<button type="button" class="gia-ch-test text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" data-channel="' . $e($chan) . '">Test</button>';
        echo '<label class="inline-flex items-center gap-2 cursor-pointer">';
        echo '<input type="checkbox" name="gia_channels[' . $e($chan) . '][enabled]" value="1" ' . ($enabled ? 'checked' : '') . ' class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">';
        echo '<span class="text-xs font-semibold ' . ($enabled ? 'text-green-700' : 'text-gray-500') . '">Enabled</span>';
        echo '</label></div>';
    };
    ?>

    <!-- WHATSAPP -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
      <?php $cardHead('whatsapp', 'WhatsApp', 'chat', !empty($wa['enabled'])); ?>
      <div class="grid md:grid-cols-2 gap-3">
        <label class="block">
          <span class="text-xs font-semibold text-gray-600">Provider mode</span>
          <select name="gia_channels[whatsapp][mode]" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="greenapi" <?php echo ($wa['mode'] ?? '') === 'greenapi' ? 'selected' : ''; ?>>Green API (uses Settings → WhatsApp credentials)</option>
            <option value="meta" <?php echo ($wa['mode'] ?? '') === 'meta' ? 'selected' : ''; ?>>Meta Cloud API (official)</option>
          </select>
          <span class="text-[11px] text-gray-400">Green API is the quickest to go live. Meta adds templates/buttons later.</span>
        </label>
        <?php $field('gia_channels[whatsapp][webhook_secret]', 'Webhook secret (Green API)', (string)($wa['webhook_secret'] ?? ''), 'text', 'A secret you choose; appended as ?secret=… to the webhook URL.'); ?>
        <?php $field('gia_channels[whatsapp][meta_phone_number_id]', 'Meta phone number ID', (string)($wa['meta_phone_number_id'] ?? '')); ?>
        <?php $field('gia_channels[whatsapp][meta_access_token]', 'Meta access token', (string)($wa['meta_access_token'] ?? ''), 'password'); ?>
        <?php $field('gia_channels[whatsapp][meta_verify_token]', 'Meta verify token', (string)($wa['meta_verify_token'] ?? '')); ?>
        <?php $field('gia_channels[whatsapp][meta_app_secret]', 'Meta app secret', (string)($wa['meta_app_secret'] ?? ''), 'password'); ?>
      </div>
      <?php
        $waUrl = $webhooks['whatsapp'] ?? '';
        $waShown = ($wa['mode'] ?? 'greenapi') === 'greenapi' && !empty($wa['webhook_secret'])
            ? $waUrl . '?secret=' . rawurlencode((string) $wa['webhook_secret'])
            : $waUrl;
        $copyRow('Webhook URL (paste into your provider console)', $waShown);
      ?>
    </div>

    <!-- MESSENGER -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
      <?php $cardHead('messenger', 'Facebook Messenger', 'forum', !empty($fb['enabled'])); ?>
      <div class="grid md:grid-cols-2 gap-3">
        <?php $field('gia_channels[messenger][page_id]', 'Page ID', (string)($fb['page_id'] ?? '')); ?>
        <?php $field('gia_channels[messenger][page_token]', 'Page access token', (string)($fb['page_token'] ?? ''), 'password'); ?>
        <?php $field('gia_channels[messenger][app_secret]', 'App secret', (string)($fb['app_secret'] ?? ''), 'password'); ?>
        <?php $field('gia_channels[messenger][verify_token]', 'Verify token (you choose)', (string)($fb['verify_token'] ?? '')); ?>
      </div>
      <?php $copyRow('Webhook URL (Meta App → Messenger → Webhooks)', $webhooks['messenger'] ?? ''); ?>
      <?php $copyRow('Verify token (paste the same value into Meta)', (string)($fb['verify_token'] ?? '')); ?>
    </div>

    <!-- TELEGRAM -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
      <?php $cardHead('telegram', 'Telegram', 'send', !empty($tg['enabled'])); ?>
      <div class="grid md:grid-cols-2 gap-3">
        <?php $field('gia_channels[telegram][bot_token]', 'Bot token (from BotFather)', (string)($tg['bot_token'] ?? ''), 'password'); ?>
        <?php $field('gia_channels[telegram][webhook_secret]', 'Webhook secret token', (string)($tg['webhook_secret'] ?? ''), 'text', 'Set the same value when you call setWebhook.'); ?>
      </div>
      <?php $copyRow('Webhook URL (use in Telegram setWebhook)', $webhooks['telegram'] ?? ''); ?>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" id="gia-ch-save" class="inline-flex items-center gap-1 text-sm font-semibold px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
        <i class="material-symbols-outlined text-[18px]">save</i> Save channels
      </button>
      <span class="text-xs text-gray-500">GIA must also be enabled (Settings → AI) for channels to respond.</span>
    </div>
  </form>
</div>

<script>
(function(){
  var root = document.getElementById('gia-channels');
  if(!root) return;
  var csrf = root.getAttribute('data-csrf') || '';
  var base = root.getAttribute('data-base') || '';
  var flash = document.getElementById('gia-ch-flash');
  function showFlash(ok, msg){
    flash.className = 'mb-3 rounded-lg px-4 py-2 text-sm ' + (ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
    flash.textContent = msg; flash.classList.remove('hidden');
  }
  // Copy buttons
  root.querySelectorAll('.gia-copy').forEach(function(b){
    b.addEventListener('click', function(){
      var v = b.getAttribute('data-copy') || '';
      navigator.clipboard && navigator.clipboard.writeText(v);
      var t = b.textContent; b.textContent = 'Copied'; setTimeout(function(){ b.textContent = t; }, 1200);
    });
  });
  // Save
  document.getElementById('gia-ch-form').addEventListener('submit', function(ev){
    ev.preventDefault();
    var fd = new FormData(ev.target);
    fetch(base + '/gia/channels/save', { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ showFlash(!!(d && d.status), (d && d.status) ? 'Channels saved.' : 'Could not save. Check your inputs and try again.'); })
      .catch(function(){ showFlash(false, 'Network error while saving.'); });
  });
  // Test
  root.querySelectorAll('.gia-ch-test').forEach(function(b){
    b.addEventListener('click', function(){
      var ch = b.getAttribute('data-channel'); var orig = b.textContent; b.textContent = 'Testing…'; b.disabled = true;
      var fd = new FormData(); fd.append('channel', ch);
      fetch(base + '/gia/channels/test', { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){ showFlash(!!(d && d.status), (ch.charAt(0).toUpperCase()+ch.slice(1)) + ': ' + ((d && d.message) || (d && d.status ? 'OK' : 'Failed'))); })
        .catch(function(){ showFlash(false, 'Network error during test.'); })
        .finally(function(){ b.textContent = orig; b.disabled = false; });
    });
  });
})();
</script>
