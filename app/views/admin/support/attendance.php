<?php
// app/views/admin/support/attendance.php — Attendance + presence.
// Three sections: (1) "Who is online now" team board (support_who_online, only
// for attendance.view_all); (2) a per-staff daily attendance table across the
// chosen date window (support_attendance_range); (3) clock-in/out buttons for
// the current staffer. A 45s heartbeat runs ONLY while the tab is visible — a
// hidden tab stops beating, so after 5 min of no beats the presence clock
// pauses (§7 idle-pause). Mirrors the Tailwind + material-symbols look of
// desk.php; POSTs ride the global X-CSRF-TOKEN interceptor (app.js) plus an
// explicit token (belt-and-braces), via a data-csrf attribute.
//
// Vars provided by the route: $online (array), $report (array), $from, $to,
// $staffId (int), $amClockedIn (bool), $viewAll (bool).
@$SECURE or die('Access Denied!');

$attBase  = root . admin . '/support';
$csrfTok  = class_exists('CSRF') ? CSRF::getToken() : '';

/** Seconds → "Hh Mm" (or "Mm" under an hour, "0m" when zero). */
if (!function_exists('support_fmt_duration')) {
    function support_fmt_duration(int $s): string
    {
        if ($s <= 0) { return '0m'; }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        if ($h > 0) { return $h . 'h ' . $m . 'm'; }
        return $m . 'm';
    }
}
?>

<div class="container my-4" data-csrf="<?= htmlspecialchars($csrfTok) ?>" id="attRoot">
  <div class="mb-4 flex items-center justify-between gap-3 flex-wrap">
    <div>
      <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-600">schedule</span>
        Attendance
      </h1>
      <p class="text-sm text-gray-500 mt-0.5">Clock in/out, live presence and worked-time per staff member.</p>
    </div>
    <a href="<?= htmlspecialchars(root . admin . '/support') ?>" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined !text-[18px]">arrow_back</span> Support home
    </a>
  </div>

  <!-- MY CLOCK -->
  <?php if ($staffId > 0): ?>
  <div class="mb-5 border border-gray-100 rounded-xl bg-white p-4 flex items-center justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
      <span id="clockDot" class="inline-block w-3 h-3 rounded-full <?= $amClockedIn ? 'bg-emerald-500' : 'bg-gray-300' ?>"></span>
      <div>
        <div class="text-sm font-semibold text-gray-900">Your clock</div>
        <div id="clockState" class="text-xs text-gray-500 mt-0.5">
          <?= $amClockedIn ? 'You are clocked in.' : 'You are clocked out.' ?>
        </div>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button id="clockInBtn" type="button"
              class="text-sm font-semibold px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-40 flex items-center gap-1"
              <?= $amClockedIn ? 'disabled' : '' ?>>
        <span class="material-symbols-outlined !text-[18px]">login</span> Clock in
      </button>
      <button id="clockOutBtn" type="button"
              class="text-sm font-semibold px-4 py-2 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 disabled:opacity-40 flex items-center gap-1"
              <?= $amClockedIn ? '' : 'disabled' ?>>
        <span class="material-symbols-outlined !text-[18px]">logout</span> Clock out
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- WHO IS ONLINE NOW -->
  <?php if ($viewAll): ?>
  <div class="mb-5 border border-gray-100 rounded-xl bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
      <span class="material-symbols-outlined !text-[20px] text-emerald-600">sensors</span>
      <h2 class="text-sm font-semibold text-gray-900">Who is online now</h2>
      <span class="text-xs text-gray-400">(beating within the last 5 min)</span>
    </div>
    <div class="p-4">
      <?php if (empty($online)): ?>
        <div class="text-sm text-gray-400">No one is online right now.</div>
      <?php else: ?>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($online as $o):
            $isIdle = ((string) ($o['status'] ?? 'online')) === 'idle';
            $dot    = $isIdle ? 'bg-amber-400' : 'bg-emerald-500';
          ?>
            <div class="flex items-center gap-2 border border-gray-100 rounded-full pl-2 pr-3 py-1.5 bg-gray-50">
              <span class="inline-block w-2.5 h-2.5 rounded-full <?= $dot ?>"></span>
              <span class="text-sm text-gray-800"><?= htmlspecialchars((string) $o['display_name']) ?></span>
              <?php if (!empty($o['clocked_in'])): ?>
                <span class="material-symbols-outlined !text-[15px] text-emerald-600" title="Clocked in">verified</span>
              <?php else: ?>
                <span class="text-[10px] font-semibold text-amber-600 uppercase tracking-wide">not clocked in</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- DAILY ATTENDANCE TABLE -->
  <div class="border border-gray-100 rounded-xl bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined !text-[20px] text-blue-600">calendar_month</span>
        Daily attendance
      </h2>
      <form method="get" action="<?= htmlspecialchars($attBase . '/attendance') ?>" class="flex items-center gap-2">
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"
               class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white">
        <span class="text-gray-400 text-sm">→</span>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"
               class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white">
        <button type="submit" class="text-sm font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Apply</button>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr class="text-left">
            <th class="px-4 py-2 font-medium">Staff</th>
            <th class="px-4 py-2 font-medium">Date</th>
            <th class="px-4 py-2 font-medium">First in</th>
            <th class="px-4 py-2 font-medium">Last out</th>
            <th class="px-4 py-2 font-medium">Worked</th>
            <th class="px-4 py-2 font-medium">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php
          $anyRows = false;
          foreach ($report as $r):
            $name = htmlspecialchars((string) $r['display_name']);
            if (empty($r['days'])):
          ?>
            <tr class="text-gray-500">
              <td class="px-4 py-2 font-medium text-gray-800"><?= $name ?><?= $r['is_me'] ? ' <span class="text-[10px] text-blue-600">(you)</span>' : '' ?></td>
              <td class="px-4 py-2 text-gray-400" colspan="5">No attendance in this window.</td>
            </tr>
          <?php
            else:
              foreach ($r['days'] as $i => $d):
                $anyRows = true;
                $open   = !empty($d['open']);
                $online = !empty($d['online']);
          ?>
            <tr>
              <td class="px-4 py-2 font-medium text-gray-800">
                <?= $i === 0 ? ($name . ($r['is_me'] ? ' <span class="text-[10px] text-blue-600">(you)</span>' : '')) : '' ?>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) $d['date']) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= $d['clock_in'] ? htmlspecialchars(substr((string) $d['clock_in'], 11, 5)) : '—' ?></td>
              <td class="px-4 py-2 text-gray-600"><?= $d['clock_out'] ? htmlspecialchars(substr((string) $d['clock_out'], 11, 5)) : '—' ?></td>
              <td class="px-4 py-2 font-semibold text-gray-900"><?= htmlspecialchars(support_fmt_duration((int) $d['worked_seconds'])) ?></td>
              <td class="px-4 py-2">
                <?php if ($open && $online): ?>
                  <span class="text-[11px] font-semibold text-emerald-600 inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span> Online</span>
                <?php elseif ($open): ?>
                  <span class="text-[11px] font-semibold text-amber-600 inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span> Clocked in · idle</span>
                <?php else: ?>
                  <span class="text-[11px] text-gray-400">Closed</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php
              endforeach;
              if (count($r['days']) > 1):
          ?>
            <tr class="bg-gray-50/60">
              <td class="px-4 py-1.5"></td>
              <td class="px-4 py-1.5 text-xs text-gray-500" colspan="3">Total (<?= htmlspecialchars($name) ?>)</td>
              <td class="px-4 py-1.5 text-xs font-bold text-gray-900"><?= htmlspecialchars(support_fmt_duration((int) $r['total_seconds'])) ?></td>
              <td class="px-4 py-1.5"></td>
            </tr>
          <?php
              endif;
            endif;
          endforeach;
          if (empty($report)):
          ?>
            <tr><td class="px-4 py-6 text-center text-gray-400" colspan="6">No staff in scope.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var BASE = <?= json_encode($attBase) ?>;
  var CSRF = (document.getElementById('attRoot') || {}).dataset
    ? document.getElementById('attRoot').dataset.csrf
    : ((document.querySelector('meta[name="csrf-token"]') || {}).content || '');
  var STAFF_ID = <?= (int) $staffId ?>;

  // Per-tab session id for presence (opaque — the server just stores it).
  var SESSION_ID = (function () {
    try {
      var k = 'support_hb_sid';
      var v = sessionStorage.getItem(k);
      if (!v) {
        v = (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
        sessionStorage.setItem(k, v);
      }
      return v;
    } catch (e) {
      return (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
    }
  })();

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

  // ---- HEARTBEAT: beat every 45s, but ONLY while the tab is visible+focused.
  // A hidden tab stops beating -> after 5 min of silence the server pauses the
  // presence clock (§7 idle-pause). We also fire one beat immediately on load
  // and whenever the tab becomes visible again.
  function beat() {
    if (!STAFF_ID) { return; }
    if (document.visibilityState !== 'visible') { return; }
    post('/heartbeat', { session_id: SESSION_ID }).catch(function () {});
  }

  if (STAFF_ID) {
    beat();
    setInterval(beat, 45000);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') { beat(); }
    });
  }

  // ---- CLOCK IN / OUT --------------------------------------------------------
  function setClockUi(clockedIn) {
    var dot = document.getElementById('clockDot');
    var st  = document.getElementById('clockState');
    var inB = document.getElementById('clockInBtn');
    var outB = document.getElementById('clockOutBtn');
    if (dot) { dot.className = 'inline-block w-3 h-3 rounded-full ' + (clockedIn ? 'bg-emerald-500' : 'bg-gray-300'); }
    if (st)  { st.textContent = clockedIn ? 'You are clocked in.' : 'You are clocked out.'; }
    if (inB)  { inB.disabled = clockedIn; }
    if (outB) { outB.disabled = !clockedIn; }
  }

  function clock(mode) {
    post('/clock', { mode: mode })
      .then(function (res) {
        if (res && res.ok) {
          setClockUi(mode === 'in');
          // Reload so the day table + online board reflect the new state.
          setTimeout(function () { window.location.reload(); }, 400);
        } else {
          alert((res && res.message) || 'Could not update your clock.');
        }
      })
      .catch(function () { alert('Could not update your clock.'); });
  }

  var inBtn  = document.getElementById('clockInBtn');
  var outBtn = document.getElementById('clockOutBtn');
  if (inBtn)  { inBtn.addEventListener('click',  function () { clock('in'); }); }
  if (outBtn) { outBtn.addEventListener('click', function () { clock('out'); }); }
})();
</script>
