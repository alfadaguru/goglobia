<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin GIA Channels panel — tabbed (WhatsApp / Telegram / Messenger). Expects
// (from giaRoutes): $channels (normalized per-channel config) and $webhooks
// (channel => inbound URL). One <form> wraps all tabs so the existing
// /gia/channels/save handler (reads gia_channels[<ch>][<field>]) is unchanged.
// Admin has full control: enable toggle, credentials, webhook URL + secret to
// paste into each provider console, one-click connect, and a Test button.
// All values escaped at output.
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = class_exists('CSRF') ? CSRF::getToken() : '';
$wa = $channels['whatsapp'] ?? [];
$fb = $channels['messenger'] ?? [];
$tg = $channels['telegram'] ?? [];

// Per-channel "configured enough to receive" heuristic (for the status pill).
$waMode = ($wa['mode'] ?? 'greenapi');
$waConfigured = $waMode === 'greenapi'
    ? (trim((string)($wa['webhook_secret'] ?? '')) !== '')
    : (trim((string)($wa['meta_access_token'] ?? '')) !== '' && trim((string)($wa['meta_app_secret'] ?? '')) !== '');
$fbConfigured = trim((string)($fb['page_token'] ?? '')) !== '' && trim((string)($fb['app_secret'] ?? '')) !== '' && trim((string)($fb['verify_token'] ?? '')) !== '';
$tgConfigured = trim((string)($tg['bot_token'] ?? '')) !== '';

// Status pill: Live (enabled + configured) / Not connected (configured, off) / Off.
$statusPill = function (bool $enabled, bool $configured) {
    if ($enabled && $configured) { return ['Live', 'bg-green-100 text-green-700']; }
    if ($enabled && !$configured) { return ['Needs setup', 'bg-amber-100 text-amber-700']; }
    return ['Off', 'bg-gray-100 text-gray-500'];
};
$tabs = [
    'whatsapp'  => ['WhatsApp', 'chat', !empty($wa['enabled']), $waConfigured],
    'telegram'  => ['Telegram', 'send', !empty($tg['enabled']), $tgConfigured],
    'messenger' => ['Messenger', 'forum', !empty($fb['enabled']), $fbConfigured],
];

// ---- shared field renderers --------------------------------------------------
$field = function (string $name, string $label, string $val, string $type = 'text', string $hint = '', array $attrs = []) use ($e) {
    $id = 'f_' . preg_replace('/[^a-z0-9]/i', '_', $name);
    $extra = '';
    foreach ($attrs as $k => $v) { $extra .= ' ' . $e($k) . '="' . $e($v) . '"'; }
    echo '<label class="block">';
    echo '<span class="text-xs font-semibold text-gray-700">' . $e($label) . '</span>';
    echo '<input type="' . $e($type) . '" name="' . $e($name) . '" id="' . $e($id) . '" value="' . $e($val) . '" '
       . 'class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" autocomplete="off"' . $extra . '>';
    if ($hint !== '') { echo '<span class="mt-0.5 block text-[11px] text-gray-400">' . $e($hint) . '</span>'; }
    echo '</label>';
};
$copyRow = function (string $label, string $val) use ($e) {
    if ($val === '') { return; }
    echo '<div class="mt-1">';
    echo '<span class="text-xs font-semibold text-gray-700">' . $e($label) . '</span>';
    echo '<div class="mt-1 flex items-center gap-2">';
    echo '<input type="text" readonly value="' . $e($val) . '" class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-700">';
    echo '<button type="button" class="gia-copy shrink-0 text-xs font-semibold px-3 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" data-copy="' . $e($val) . '"><i class="material-symbols-outlined text-[15px] align-[-3px]">content_copy</i> Copy</button>';
    echo '</div></div>';
};
// Secret field with a one-click Generate button that fills a strong random value.
$secretField = function (string $name, string $label, string $val, string $hint = '') use ($e) {
    $id = 'f_' . preg_replace('/[^a-z0-9]/i', '_', $name);
    echo '<label class="block">';
    echo '<span class="text-xs font-semibold text-gray-700">' . $e($label) . '</span>';
    echo '<div class="mt-1 flex items-center gap-2">';
    echo '<input type="text" name="' . $e($name) . '" id="' . $e($id) . '" value="' . $e($val) . '" '
       . 'class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500" autocomplete="off">';
    echo '<button type="button" class="gia-gen shrink-0 inline-flex items-center gap-1 text-xs font-semibold px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" data-target="' . $e($id) . '"><i class="material-symbols-outlined text-[15px] align-[-3px]">casino</i> Generate</button>';
    echo '</div>';
    if ($hint !== '') { echo '<span class="mt-0.5 block text-[11px] text-gray-400">' . $e($hint) . '</span>'; }
    echo '</label>';
};
// Enable toggle shown inside each panel header row.
$enableToggle = function (string $chan, bool $enabled) use ($e) {
    echo '<label class="inline-flex items-center gap-2 cursor-pointer select-none">';
    echo '<input type="checkbox" name="gia_channels[' . $e($chan) . '][enabled]" value="1" ' . ($enabled ? 'checked' : '') . ' class="gia-enable rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-chan="' . $e($chan) . '">';
    echo '<span class="text-sm font-semibold text-gray-700">Enabled</span>';
    echo '</label>';
};
?>
<div class="container mx-auto px-4 py-4" id="gia-channels"
     data-csrf="<?php echo $e($csrf); ?>" data-base="<?php echo $e(root . admin); ?>">

  <div class="mb-4 flex items-center gap-3">
    <i class="material-symbols-outlined text-blue-600 text-[28px]">hub</i>
    <div class="flex-1">
      <h1 class="text-xl font-bold text-gray-900">GIA Channels</h1>
      <p class="text-sm text-gray-600">Let GIA chat with travelers on WhatsApp, Telegram and Messenger — and turn conversations into bookings. Configure each channel below.</p>
    </div>
  </div>

  <!-- GIA-enabled reminder banner -->
  <div class="mb-3 flex items-start gap-2 rounded-lg bg-blue-50 border border-blue-100 px-4 py-2.5 text-[13px] text-blue-800">
    <i class="material-symbols-outlined text-[18px] mt-px">info</i>
    <span>Messages only get a reply when <b>GIA is enabled</b> (Settings → AI) <b>and</b> the channel below is switched on. A channel that is off silently ignores every incoming message.</span>
  </div>

  <div id="gia-ch-flash" class="hidden mb-3 rounded-lg px-4 py-2 text-sm"></div>

  <!-- ===== Tab strip ===== -->
  <div class="flex items-center gap-1 border-b border-gray-200 mb-4" role="tablist" aria-label="GIA channels">
    <?php foreach ($tabs as $key => [$label, $icon, $enabled, $configured]):
        [$pillText, $pillClass] = $statusPill($enabled, $configured); ?>
      <button type="button" role="tab" class="gia-tab group relative flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-800 focus:outline-none"
              data-tab="<?php echo $e($key); ?>" aria-selected="false">
        <i class="material-symbols-outlined text-[19px]"><?php echo $e($icon); ?></i>
        <span><?php echo $e($label); ?></span>
        <span class="gia-tab-pill ml-1 rounded-full px-2 py-0.5 text-[10px] font-bold <?php echo $e($pillClass); ?>"><?php echo $e($pillText); ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <form id="gia-ch-form">

    <!-- ========================= WHATSAPP ========================= -->
    <section class="gia-panel" data-panel="whatsapp">
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 border-b border-gray-100">
          <i class="material-symbols-outlined text-green-600">chat</i>
          <h2 class="flex-1 text-base font-bold text-gray-900">WhatsApp</h2>
          <button type="button" class="gia-ch-test text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" data-channel="whatsapp">Test connection</button>
          <?php $enableToggle('whatsapp', !empty($wa['enabled'])); ?>
        </div>
        <div class="p-5 space-y-4">

          <label class="block max-w-md">
            <span class="text-xs font-semibold text-gray-700">Provider</span>
            <select name="gia_channels[whatsapp][mode]" id="wa-mode" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
              <option value="greenapi" <?php echo $waMode === 'greenapi' ? 'selected' : ''; ?>>Green API — quickest, uses your Settings → WhatsApp credentials</option>
              <option value="meta" <?php echo $waMode === 'meta' ? 'selected' : ''; ?>>Meta Cloud API — official, adds templates/buttons</option>
            </select>
          </label>

          <!-- Green API fields -->
          <div class="gia-wa-greenapi space-y-4" <?php echo $waMode !== 'greenapi' ? 'hidden' : ''; ?>>
            <div class="rounded-lg bg-green-50 border border-green-100 px-4 py-2.5 text-[12.5px] text-green-800">
              <b>How to connect (2 steps):</b> 1) Type any <b>webhook secret</b> below and click <b>Save channels</b>. 2) Click <b>Connect Green API</b> — we point your Green API instance's incoming messages at GIA automatically. Sending uses the Green API credentials in <b>Settings → WhatsApp</b>.
            </div>
            <div class="grid md:grid-cols-2 gap-4">
              <?php $secretField('gia_channels[whatsapp][webhook_secret]', 'Webhook secret', (string)($wa['webhook_secret'] ?? ''), 'Click Generate for a strong random value, or type your own. It authenticates Green API\'s calls to us (appended as ?secret=… on the URL). Save, then Connect.'); ?>
            </div>
            <label class="flex items-start gap-3 rounded-lg border border-gray-200 px-4 py-3 cursor-pointer hover:bg-gray-50">
              <input type="checkbox" name="gia_channels[whatsapp][greenapi_interactive_buttons]" value="1"
                     <?php echo !empty($wa['greenapi_interactive_buttons']) ? 'checked' : ''; ?>
                     class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-[12.5px] text-gray-700">
                <b>Tappable reply buttons (beta)</b> — show choices of 3 or fewer as tap buttons instead of a numbered list.
                <span class="text-gray-500">Green API's buttons are beta and may be unreliable; longer lists always stay numbered, and GIA falls back to numbered text if a button send fails. Leave off if replies misbehave.</span>
              </span>
            </label>
            <?php
              $waUrl = $webhooks['whatsapp'] ?? '';
              $waShown = !empty($wa['webhook_secret']) ? $waUrl . '?secret=' . rawurlencode((string) $wa['webhook_secret']) : $waUrl;
              $copyRow('Webhook URL (auto-registered by the button below; shown for reference)', $waShown);
            ?>
            <div class="flex items-center gap-3 flex-wrap pt-1">
              <button type="button" id="gia-ga-webhook"
                      class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60">
                <i class="material-symbols-outlined text-[18px]">link</i> Connect Green API
              </button>
              <span class="text-[11px] text-gray-500">One click — no trip to the Green API console. Save the secret first.</span>
            </div>
          </div>

          <!-- Meta Cloud API fields -->
          <div class="gia-wa-meta space-y-4" <?php echo $waMode !== 'meta' ? 'hidden' : ''; ?>>
            <div class="rounded-lg bg-blue-50 border border-blue-100 px-4 py-2.5 text-[12.5px] text-blue-800">
              <b>How to connect:</b> In your Meta App → WhatsApp → Configuration, set the <b>Callback URL</b> and <b>Verify token</b> below, then subscribe to <code>messages</code>.
            </div>
            <div class="grid md:grid-cols-2 gap-4">
              <?php $field('gia_channels[whatsapp][meta_phone_number_id]', 'Phone number ID', (string)($wa['meta_phone_number_id'] ?? '')); ?>
              <?php $field('gia_channels[whatsapp][meta_access_token]', 'Access token', (string)($wa['meta_access_token'] ?? ''), 'password'); ?>
              <?php $field('gia_channels[whatsapp][meta_verify_token]', 'Verify token (you choose)', (string)($wa['meta_verify_token'] ?? '')); ?>
              <?php $field('gia_channels[whatsapp][meta_app_secret]', 'App secret', (string)($wa['meta_app_secret'] ?? ''), 'password'); ?>
            </div>
            <?php $copyRow('Callback URL (paste into Meta)', $webhooks['whatsapp'] ?? ''); ?>
            <?php $copyRow('Verify token (paste the same value into Meta)', (string)($wa['meta_verify_token'] ?? '')); ?>
          </div>

        </div>
      </div>
    </section>

    <!-- ========================= TELEGRAM ========================= -->
    <section class="gia-panel" data-panel="telegram" hidden>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 border-b border-gray-100">
          <i class="material-symbols-outlined text-sky-500">send</i>
          <h2 class="flex-1 text-base font-bold text-gray-900">Telegram</h2>
          <button type="button" class="gia-ch-test text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" data-channel="telegram">Test connection</button>
          <?php $enableToggle('telegram', !empty($tg['enabled'])); ?>
        </div>
        <div class="p-5 space-y-4">
          <div class="rounded-lg bg-sky-50 border border-sky-100 px-4 py-2.5 text-[12.5px] text-sky-800">
            <b>How to connect:</b> 1) Create a bot with <b>@BotFather</b> and paste its <b>bot token</b>. 2) Type any <b>webhook secret</b>. 3) <b>Save</b>, then click <b>Connect Telegram</b> — we register the webhook for you.
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <?php $field('gia_channels[telegram][bot_token]', 'Bot token (from BotFather)', (string)($tg['bot_token'] ?? ''), 'password'); ?>
            <?php $secretField('gia_channels[telegram][webhook_secret]', 'Webhook secret', (string)($tg['webhook_secret'] ?? ''), 'Click Generate for a strong random value, or type your own. Telegram echoes it back on every update so we can trust it.'); ?>
          </div>
          <?php $copyRow('Webhook URL (auto-registered by the button below; shown for reference)', $webhooks['telegram'] ?? ''); ?>
          <div class="flex items-center gap-3 flex-wrap pt-1">
            <button type="button" id="gia-tg-webhook"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-lg bg-sky-600 text-white hover:bg-sky-700 disabled:opacity-60">
              <i class="material-symbols-outlined text-[18px]">link</i> Connect Telegram
            </button>
            <span class="text-[11px] text-gray-500">One click — registers the webhook via Telegram setWebhook. Save the token + secret first.</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ========================= MESSENGER ========================= -->
    <section class="gia-panel" data-panel="messenger" hidden>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 border-b border-gray-100">
          <i class="material-symbols-outlined text-indigo-500">forum</i>
          <h2 class="flex-1 text-base font-bold text-gray-900">Facebook Messenger</h2>
          <button type="button" class="gia-ch-test text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" data-channel="messenger">Test connection</button>
          <?php $enableToggle('messenger', !empty($fb['enabled'])); ?>
        </div>
        <div class="p-5 space-y-4">
          <div class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-2.5 text-[12.5px] text-indigo-800">
            <b>How to connect:</b> In your Meta App → Messenger → Settings, add the <b>Callback URL</b> and <b>Verify token</b> below, subscribe to <code>messages</code>, then paste your Page token &amp; App secret here and <b>Save</b>.
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <?php $field('gia_channels[messenger][page_id]', 'Page ID', (string)($fb['page_id'] ?? '')); ?>
            <?php $field('gia_channels[messenger][page_token]', 'Page access token', (string)($fb['page_token'] ?? ''), 'password'); ?>
            <?php $field('gia_channels[messenger][app_secret]', 'App secret', (string)($fb['app_secret'] ?? ''), 'password'); ?>
            <?php $field('gia_channels[messenger][verify_token]', 'Verify token (you choose)', (string)($fb['verify_token'] ?? '')); ?>
          </div>
          <?php $copyRow('Callback URL (paste into Meta)', $webhooks['messenger'] ?? ''); ?>
          <?php $copyRow('Verify token (paste the same value into Meta)', (string)($fb['verify_token'] ?? '')); ?>
        </div>
      </div>
    </section>

    <div class="mt-5 flex items-center gap-3">
      <button type="submit" id="gia-ch-save" class="inline-flex items-center gap-1.5 text-sm font-semibold px-5 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
        <i class="material-symbols-outlined text-[18px]">save</i> Save channels
      </button>
      <span class="text-xs text-gray-500">Saving applies every tab at once.</span>
    </div>
  </form>

  <!-- ===== Per-service booking expiry ===== -->
  <div class="mt-8">
    <div class="flex items-center gap-3 mb-2">
      <i class="material-symbols-outlined text-gray-500">timer</i>
      <h2 class="flex-1 text-base font-bold text-gray-900">Booking expiry per service</h2>
    </div>
    <p class="text-[12px] text-gray-500 mb-3">When GIA creates a booking, it holds for this many minutes while the traveller pays (bank transfer or card). If unpaid, it is auto-cancelled and any late payment is auto-refunded. Default is 20 minutes. Set a different window per service if you need to.</p>
    <form id="gia-expiry-form">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <?php foreach (($serviceExpiry ?? []) as $svc => $mins): ?>
          <label class="block">
            <span class="block text-[11px] font-semibold text-gray-600 mb-1 capitalize"><?php echo $e($svc); ?></span>
            <span class="relative inline-flex items-center w-full">
              <input type="number" min="1" max="1440" name="gia_service_expiry[<?php echo $e($svc); ?>]"
                     value="<?php echo (int) $mins; ?>"
                     class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm">
              <span class="ml-2 text-[11px] text-gray-400">min</span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="flex items-center gap-3 mt-4">
        <button type="submit" class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
          <i class="material-symbols-outlined text-[16px] align-[-3px]">save</i> Save expiry
        </button>
        <span id="gia-expiry-msg" class="text-xs text-gray-500"></span>
      </div>
    </form>
  </div>

  <!-- ===== Recent webhook activity ===== -->
  <div class="mt-8">
    <div class="flex items-center gap-3 mb-2">
      <i class="material-symbols-outlined text-gray-500">receipt_long</i>
      <h2 class="flex-1 text-base font-bold text-gray-900">Recent webhook activity</h2>
      <select id="gia-log-filter" class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs">
        <option value="">All channels</option>
        <option value="whatsapp">WhatsApp</option>
        <option value="telegram">Telegram</option>
        <option value="messenger">Messenger</option>
      </select>
      <button type="button" id="gia-log-refresh" class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50">
        <i class="material-symbols-outlined text-[15px] align-[-3px]">refresh</i> Refresh
      </button>
      <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer select-none">
        <input type="checkbox" id="gia-log-auto" class="rounded border-gray-300 text-blue-600"> Auto
      </label>
    </div>
    <p class="text-[12px] text-gray-500 mb-2">Every inbound message hitting GIA is logged here with what happened to it. <b>ok</b> = delivered to GIA · <b>bad_secret</b> = the webhook secret didn't match · <b>disabled</b> = the channel is off · <b>ignored</b> = not a message we act on.</p>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase tracking-wide">
          <tr>
            <th class="text-left font-semibold px-3 py-2">Time</th>
            <th class="text-left font-semibold px-3 py-2">Channel</th>
            <th class="text-left font-semibold px-3 py-2">Outcome</th>
            <th class="text-left font-semibold px-3 py-2">From</th>
            <th class="text-left font-semibold px-3 py-2">Message / event</th>
          </tr>
        </thead>
        <tbody id="gia-log-body">
          <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
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
    flash.scrollIntoView({behavior:'smooth', block:'nearest'});
  }

  // ---- Tabs ----
  var tabs = root.querySelectorAll('.gia-tab');
  var panels = root.querySelectorAll('.gia-panel');
  var ACTIVE = ['border-blue-600','text-blue-700'];
  function selectTab(key){
    tabs.forEach(function(t){
      var on = t.getAttribute('data-tab') === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      ACTIVE.forEach(function(c){ t.classList.toggle(c, on); });
    });
    panels.forEach(function(p){ p.hidden = p.getAttribute('data-panel') !== key; });
    try { history.replaceState(null, '', '#' + key); } catch(e){}
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ selectTab(t.getAttribute('data-tab')); }); });
  var initial = (location.hash || '').replace('#','');
  selectTab(['whatsapp','telegram','messenger'].indexOf(initial) >= 0 ? initial : 'whatsapp');

  // ---- WhatsApp provider-mode toggle (show the relevant field group) ----
  var waMode = document.getElementById('wa-mode');
  function applyWaMode(){
    var m = waMode ? waMode.value : 'greenapi';
    root.querySelectorAll('.gia-wa-greenapi').forEach(function(el){ el.hidden = (m !== 'greenapi'); });
    root.querySelectorAll('.gia-wa-meta').forEach(function(el){ el.hidden = (m !== 'meta'); });
  }
  if (waMode){ waMode.addEventListener('change', applyWaMode); applyWaMode(); }

  // ---- Generate secret ----
  function makeSecret(){
    var bytes = new Uint8Array(24);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var s = '';
    for (var i=0;i<bytes.length;i++){ s += ('0' + bytes[i].toString(16)).slice(-2); }
    return 'gia_' + s; // ~52 chars, URL-safe (hex)
  }
  root.querySelectorAll('.gia-gen').forEach(function(b){
    b.addEventListener('click', function(){
      var inp = document.getElementById(b.getAttribute('data-target'));
      if(!inp) return;
      inp.value = makeSecret();
      inp.dispatchEvent(new Event('input', {bubbles:true}));
      var orig = b.innerHTML; b.textContent = 'Generated'; setTimeout(function(){ b.innerHTML = orig; }, 1200);
      showFlash(true, 'Secret generated. Click Save channels, then Connect.');
    });
  });

  // ---- Copy buttons ----
  root.querySelectorAll('.gia-copy').forEach(function(b){
    b.addEventListener('click', function(){
      var v = b.getAttribute('data-copy') || '';
      if(navigator.clipboard){ navigator.clipboard.writeText(v); }
      var t = b.innerHTML; b.textContent = 'Copied'; setTimeout(function(){ b.innerHTML = t; }, 1200);
    });
  });

  // ---- Save ----
  document.getElementById('gia-ch-form').addEventListener('submit', function(ev){
    ev.preventDefault();
    var btn = document.getElementById('gia-ch-save'); var orig = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData(ev.target);
    fetch(base + '/gia/channels/save', { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ showFlash(!!(d && d.status), (d && d.status) ? 'Channels saved. Reload to refresh the status pills.' : 'Could not save. Check your inputs and try again.'); })
      .catch(function(){ showFlash(false, 'Network error while saving.'); })
      .finally(function(){ btn.disabled = false; btn.innerHTML = orig; });
  });

  // ---- Save per-service booking expiry ----
  var expForm = document.getElementById('gia-expiry-form');
  if (expForm) {
    expForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      var msg = document.getElementById('gia-expiry-msg'); if (msg) msg.textContent = 'Saving…';
      fetch(base + '/gia/expiry/save', { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: new FormData(ev.target) })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (msg) msg.textContent = (d && d.status) ? 'Saved.' : 'Could not save.'; })
        .catch(function(){ if (msg) msg.textContent = 'Network error.'; });
    });
  }

  // ---- Test ----
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

  // ---- One-click webhook connect (Green API + Telegram) ----
  function wireConnect(btnId, endpoint){
    var btn = document.getElementById(btnId);
    if(!btn) return;
    btn.addEventListener('click', function(){
      var orig = btn.innerHTML; btn.textContent = 'Connecting…'; btn.disabled = true;
      fetch(base + endpoint, { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body: new FormData() })
        .then(function(r){ return r.json(); })
        .then(function(d){ showFlash(!!(d && d.status), (d && d.message) || (d && d.status ? 'Connected.' : 'Failed.')); })
        .catch(function(){ showFlash(false, 'Network error.'); })
        .finally(function(){ btn.innerHTML = orig; btn.disabled = false; });
    });
  }
  wireConnect('gia-ga-webhook', '/gia/channels/greenapi-webhook');
  wireConnect('gia-tg-webhook', '/gia/channels/telegram-webhook');

  // ---- Webhook activity log ----
  var logBody = document.getElementById('gia-log-body');
  var logFilter = document.getElementById('gia-log-filter');
  var logAuto = document.getElementById('gia-log-auto');
  var autoTimer = null;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function pill(outcome){
    var map = { ok:'bg-green-100 text-green-700', bad_secret:'bg-red-100 text-red-700', secret_required:'bg-red-100 text-red-700',
                bad_signature:'bg-red-100 text-red-700', disabled:'bg-gray-200 text-gray-600', ignored:'bg-amber-100 text-amber-700',
                error:'bg-red-100 text-red-700' };
    var cls = map[outcome] || 'bg-gray-100 text-gray-600';
    return '<span class="rounded-full px-2 py-0.5 text-[10px] font-bold '+cls+'">'+esc(outcome)+'</span>';
  }
  function chanIcon(ch){ return ({whatsapp:'chat',telegram:'send',messenger:'forum'})[ch] || 'public'; }
  function loadLogs(){
    var ch = logFilter ? logFilter.value : '';
    fetch(base + '/gia/channels/logs' + (ch ? ('?channel='+encodeURIComponent(ch)) : ''), { headers:{'X-CSRF-TOKEN':csrf} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        var rows = (d && d.rows) || [];
        if(!rows.length){ logBody.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No webhooks received yet. Send a message to your connected number and click Refresh.</td></tr>'; return; }
        logBody.innerHTML = rows.map(function(r){
          var msg = r.text_preview ? esc(r.text_preview) : (r.event_type ? '<span class="text-gray-400">'+esc(r.event_type)+'</span>' : (r.kind ? esc(r.kind) : '—'));
          return '<tr class="border-t border-gray-100">'
            + '<td class="px-3 py-2 whitespace-nowrap text-gray-500 text-[12px]">'+esc(r.created_at)+'</td>'
            + '<td class="px-3 py-2"><span class="inline-flex items-center gap-1"><i class="material-symbols-outlined text-[15px] text-gray-400">'+chanIcon(r.channel)+'</i>'+esc(r.channel)+'</span></td>'
            + '<td class="px-3 py-2">'+pill(r.outcome)+'</td>'
            + '<td class="px-3 py-2 text-gray-600 text-[12px] whitespace-nowrap">'+(r.sender?esc(r.sender):'—')+'</td>'
            + '<td class="px-3 py-2 text-gray-700">'+msg+'</td>'
            + '</tr>';
        }).join('');
      })
      .catch(function(){ logBody.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-red-500">Could not load logs.</td></tr>'; });
  }
  var rBtn = document.getElementById('gia-log-refresh');
  if(rBtn) rBtn.addEventListener('click', loadLogs);
  if(logFilter) logFilter.addEventListener('change', loadLogs);
  if(logAuto) logAuto.addEventListener('change', function(){
    if(logAuto.checked){ loadLogs(); autoTimer = setInterval(loadLogs, 5000); }
    else if(autoTimer){ clearInterval(autoTimer); autoTimer = null; }
  });
  loadLogs();
})();
</script>
