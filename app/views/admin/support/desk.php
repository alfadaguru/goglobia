<?php
// app/views/admin/support/desk.php — Live Chat Desk.
// Two-pane multi-channel takeover inbox: a filterable conversation queue on the
// left, the live transcript + reply composer on the right. Driven entirely by
// the JSON endpoints in app/routes/admin/supportDeskRoutes.php via short polls.
// Mirrors the Tailwind + material-symbols look of home.php / staff.php.
// POSTs ride the global X-CSRF-TOKEN interceptor (app.js) using the csrf-token
// meta; we also pass the token explicitly as a belt-and-braces measure.
@$SECURE or die('Access Denied!');

$deskBase = root . admin . '/support/desk';
?>

<div class="container my-4">
  <style>
    /* Desktop (two-pane side-by-side): both panes share ONE viewport-bound height
       so they align and the page body does not grow — each pane scrolls inside.
       Raw CSS (not an arbitrary Tailwind class) so it works without a CSS rebuild. */
    @media (min-width: 1024px) {
      #deskGrid .desk-pane { height: calc(100vh - 11rem) !important; }
    }
  </style>
  <div class="mb-4 flex items-center justify-between gap-3 flex-wrap">
    <div>
      <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-600">headset_mic</span>
        Live Desk
      </h1>
      <p class="text-sm text-gray-500 mt-0.5">Take over live GIA conversations across the web widget, WhatsApp, Telegram and Messenger.</p>
    </div>
    <a href="<?= htmlspecialchars(root . admin . '/support') ?>" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined !text-[18px]">arrow_back</span> Support home
    </a>
  </div>

  <!-- A BOUNDED two-pane desk: the whole panel is sized to the viewport so it
       behaves like an inbox — each pane scrolls INTERNALLY and the PAGE itself
       does not grow/double-scroll. On mobile the panes stack with sensible
       per-pane heights. (Was min-height:70vh, which let the panes grow unbounded
       and produced an uncontrollable double scroll.) Heights use inline style so
       they work regardless of the Tailwind build state. The lg: viewport-bound
       height is applied via a data attribute + tiny script below (one media query)
       so no arbitrary Tailwind class is required. -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" id="deskGrid">

    <!-- QUEUE -->
    <div class="lg:col-span-1 border border-gray-100 rounded-xl bg-white flex flex-col overflow-hidden desk-pane" style="height:55vh">
      <div class="p-3 border-b border-gray-100">
        <select id="deskFilter" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white">
          <option value="all">All conversations</option>
          <option value="needs_human">Needs a human</option>
          <option value="assigned">Assigned to me</option>
          <option value="unassigned">Unassigned</option>
          <option value="channel:web">Channel · Web</option>
          <option value="channel:whatsapp">Channel · WhatsApp</option>
          <option value="channel:telegram">Channel · Telegram</option>
          <option value="channel:messenger">Channel · Messenger</option>
        </select>
      </div>
      <div id="deskQueue" class="flex-1 overflow-y-auto divide-y divide-gray-50">
        <div class="p-4 text-sm text-gray-400">Loading…</div>
      </div>
    </div>

    <!-- CONVERSATION -->
    <div class="lg:col-span-2 border border-gray-100 rounded-xl bg-white flex flex-col overflow-hidden desk-pane" style="height:70vh">
      <div id="deskHeader" class="p-3 border-b border-gray-100 flex items-center justify-between gap-2 hidden">
        <div class="min-w-0">
          <div id="deskConvTitle" class="text-sm font-semibold text-gray-900 truncate">Conversation</div>
          <div id="deskConvState" class="text-xs text-gray-500 mt-0.5"></div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button id="deskTakeBtn" type="button"
                  class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
            Take over
          </button>
        </div>
      </div>

      <div id="deskThread" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50">
        <div class="h-full flex items-center justify-center text-sm text-gray-400">
          Select a conversation to view the transcript.
        </div>
      </div>

      <div id="deskComposer" class="p-3 border-t border-gray-100 hidden">
        <form id="deskReplyForm" class="flex items-end gap-2">
          <textarea id="deskReplyText" rows="2" placeholder="Type a reply to the customer…"
                    class="flex-1 text-sm border border-gray-200 rounded-lg px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-blue-200"></textarea>
          <button type="submit"
                  class="text-sm font-semibold px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 flex items-center gap-1">
            <span class="material-symbols-outlined !text-[18px]">send</span> Send
          </button>
        </form>
        <div id="deskReplyNote" class="text-xs text-gray-400 mt-1">Sending a reply pauses GIA and hands the chat to you.</div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var BASE  = <?= json_encode($deskBase) ?>;
  var CSRF  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  var state = {
    activeId: 0,
    lastMsgId: 0,
    staffActive: 0,
    assignedToMe: false,
    queueTimer: null,
    msgTimer: null
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  function channelBadge(ch) {
    ch = (ch || 'web').toLowerCase();
    var map = { web:'#3b82f6', whatsapp:'#16a34a', telegram:'#0ea5e9', messenger:'#6366f1' };
    var color = map[ch] || '#64748b';
    return '<span style="background:' + color + '1a;color:' + color +
      ';font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em">' +
      esc(ch) + '</span>';
  }

  function post(path, data) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    body.append('csrf_token', CSRF);
    return fetch(BASE + path, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  function getJSON(path) {
    return fetch(path, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
  }

  // ---- QUEUE -----------------------------------------------------------------
  function renderQueue(rows) {
    var el = document.getElementById('deskQueue');
    if (!rows || !rows.length) {
      el.innerHTML = '<div class="p-4 text-sm text-gray-400">No conversations.</div>';
      return;
    }
    el.innerHTML = rows.map(function (c) {
      var active = (parseInt(c.id, 10) === state.activeId);
      var needs = parseInt(c.needs_human, 10) === 1;
      return '' +
        '<button type="button" data-id="' + esc(c.id) + '" data-channel="' + esc(c.channel || 'web') + '" ' +
        'class="deskQueueItem w-full text-left px-3 py-3 hover:bg-blue-50 transition ' + (active ? 'bg-blue-50' : '') + '">' +
          '<div class="flex items-center justify-between gap-2">' +
            channelBadge(c.channel) +
            (needs ? '<span class="material-symbols-outlined !text-[16px] text-amber-500" title="Needs a human">pan_tool</span>' : '<span></span>') +
          '</div>' +
          '<div class="text-sm text-gray-800 mt-1 truncate">' + esc(c.last_message || '(no messages yet)') + '</div>' +
          '<div class="text-[11px] text-gray-400 mt-0.5 flex items-center justify-between">' +
            '<span>' + esc(c.channel_user_id || c.user_id || ('#' + c.id)) + '</span>' +
            '<span>' + esc(c.last_at || '') + '</span>' +
          '</div>' +
        '</button>';
    }).join('');

    Array.prototype.forEach.call(el.querySelectorAll('.deskQueueItem'), function (btn) {
      btn.addEventListener('click', function () {
        openConversation(parseInt(btn.getAttribute('data-id'), 10), btn.getAttribute('data-channel'));
      });
    });
  }

  function loadQueue() {
    var filter = document.getElementById('deskFilter').value;
    getJSON(BASE + '/queue?filter=' + encodeURIComponent(filter))
      .then(function (res) { if (res && res.status) { renderQueue(res.conversations); } })
      .catch(function () {});
  }

  // ---- THREAD ----------------------------------------------------------------
  function appendMessages(msgs) {
    var thread = document.getElementById('deskThread');
    var html = msgs.map(function (m) {
      var isUser = (m.role === 'user');
      var isStaff = parseInt(m.is_staff, 10) === 1;
      var side = isUser ? 'justify-start' : 'justify-end';
      var bubble = isUser
        ? 'bg-white border border-gray-200 text-gray-800'
        : (isStaff ? 'bg-emerald-600 text-white' : 'bg-blue-600 text-white');
      var who = isUser ? 'Customer' : (isStaff ? 'You / staff' : 'GIA');
      return '' +
        '<div class="flex ' + side + '">' +
          '<div class="max-w-[80%]">' +
            '<div class="text-[10px] text-gray-400 mb-0.5 ' + (isUser ? 'text-left' : 'text-right') + '">' + esc(who) + '</div>' +
            '<div class="rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap ' + bubble + '">' + esc(m.text) + '</div>' +
          '</div>' +
        '</div>';
    }).join('');

    if (state.lastMsgId === 0) { thread.innerHTML = html || '<div class="h-full flex items-center justify-center text-sm text-gray-400">No messages yet.</div>'; }
    else if (html) { thread.insertAdjacentHTML('beforeend', html); }

    msgs.forEach(function (m) {
      var mid = parseInt(m.id, 10);
      if (mid > state.lastMsgId) { state.lastMsgId = mid; }
    });
    thread.scrollTop = thread.scrollHeight;
  }

  function reflectState(res) {
    state.staffActive = parseInt(res.staff_active, 10) === 1;
    var stateEl = document.getElementById('deskConvState');
    var takeBtn = document.getElementById('deskTakeBtn');
    var parts = [channelBadge(res.channel)];
    if (res.needs_human) { parts.push('<span class="text-amber-600">needs a human</span>'); }
    parts.push(state.staffActive ? '<span class="text-emerald-600 font-semibold">you / staff are handling this</span>' : '<span class="text-gray-500">GIA is handling this</span>');
    stateEl.innerHTML = parts.join(' · ');

    if (state.staffActive) {
      takeBtn.textContent = 'Release to GIA';
      takeBtn.className = 'text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200';
      takeBtn.setAttribute('data-mode', 'release');
    } else {
      takeBtn.textContent = 'Take over';
      takeBtn.className = 'text-xs font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700';
      takeBtn.setAttribute('data-mode', 'take');
    }
  }

  function loadMessages() {
    if (!state.activeId) { return; }
    getJSON(BASE + '/messages?id=' + state.activeId + '&since=' + state.lastMsgId)
      .then(function (res) {
        if (!res || !res.status) { return; }
        appendMessages(res.messages || []);
        reflectState(res);
      })
      .catch(function () {});
  }

  function openConversation(id, channel) {
    state.activeId = id;
    state.lastMsgId = 0;
    document.getElementById('deskHeader').classList.remove('hidden');
    document.getElementById('deskComposer').classList.remove('hidden');
    document.getElementById('deskConvTitle').textContent = 'Conversation #' + id;
    document.getElementById('deskThread').innerHTML = '<div class="h-full flex items-center justify-center text-sm text-gray-400">Loading…</div>';
    loadMessages();
    loadQueue(); // re-highlight the active item
  }

  // ---- ACTIONS ---------------------------------------------------------------
  document.getElementById('deskTakeBtn').addEventListener('click', function () {
    if (!state.activeId) { return; }
    var mode = this.getAttribute('data-mode') || 'take';
    post('/takeover', { id: state.activeId, mode: mode })
      .then(function (res) {
        if (res && res.status) { loadMessages(); loadQueue(); }
        else { alert((res && res.message) || 'Could not update the conversation.'); }
      });
  });

  document.getElementById('deskReplyForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!state.activeId) { return; }
    var ta = document.getElementById('deskReplyText');
    var text = ta.value.trim();
    if (!text) { return; }
    ta.value = '';
    post('/reply', { id: state.activeId, text: text })
      .then(function (res) {
        if (res && res.status) { loadMessages(); loadQueue(); }
        else { ta.value = text; alert((res && res.message) || 'Could not send.'); }
      });
  });

  document.getElementById('deskFilter').addEventListener('change', loadQueue);

  // ---- POLLING ---------------------------------------------------------------
  loadQueue();
  state.queueTimer = setInterval(loadQueue, 7000);
  state.msgTimer = setInterval(loadMessages, 4000);
})();
</script>
