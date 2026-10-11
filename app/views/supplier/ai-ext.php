<?php @$SECURE or die('Access Denied!'); ?>
<?php
// MODULE 57 deepen — OWNER AI & automation dashboard. Consumes: $forecast, $cancel,
// $themes, $summary, $concierge, $briefs, $propMap, $canEdit. Property drill-in via
// ?stay_id. Everything here is REAL-without-an-LLM except the concierge panel, which is
// an HONEST stub (shows connection status; never fakes a model reply).
$forecast  = (isset($forecast) && is_array($forecast)) ? $forecast : [];
$cancel    = (isset($cancel) && is_array($cancel)) ? $cancel : [];
$themes    = (isset($themes) && is_array($themes)) ? $themes : [];
$summary   = (isset($summary) && is_array($summary)) ? $summary : ['lines' => [], 'source' => 'rule-based', 'generated_at' => ''];
$concierge = (isset($concierge) && is_array($concierge)) ? $concierge : ['connected' => false, 'can_generate' => false, 'reason' => '', 'provider' => ''];
$briefs    = (isset($briefs) && is_array($briefs)) ? $briefs : [];
$propMap   = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit   = !empty($canEdit);
$base      = root . 'supplier/ai-ext';
$stayFilter = (int) ($_GET['stay_id'] ?? 0);

$confColor = function ($c) {
    return $c === 'moderate' ? 'text-green-600' : ($c === 'low' ? 'text-amber-600' : 'text-gray-400');
};
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">AI &amp; automation</h1>
      <p class="text-sm text-gray-600">Forecasts, cancellation patterns and guest sentiment computed from <span class="font-medium">your own data</span> — plus the status of any connected AI provider.</p>
    </div>
    <?php if (count($propMap) > 1): ?>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $pid => $pname): ?>
          <option value="<?= (int) $pid ?>" <?= $stayFilter === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <!-- Honesty banner -->
  <div class="card p-4 bg-blue-50 border border-blue-100">
    <div class="flex items-start gap-3">
      <span class="material-symbols-outlined text-blue-600">info</span>
      <p class="text-sm text-blue-900">The analytics below are <span class="font-semibold">deterministic statistics</span> over your reservations, night audits and surveys — not a large-language-model. Free-text drafting (the concierge panel) only works when your administrator has connected an AI provider.</p>
    </div>
  </div>

  <!-- Rule-based operational summary -->
  <div class="card p-5">
    <div class="flex items-center justify-between gap-2 mb-3">
      <h2 class="text-lg font-semibold text-gray-900">Operational brief</h2>
      <span class="text-xs text-gray-400">rule-based · <?= htmlspecialchars((string) ($summary['generated_at'] ?? '')) ?></span>
    </div>
    <ul class="space-y-2">
      <?php foreach (($summary['lines'] ?? []) as $ln): ?>
        <li class="flex items-start gap-2 text-sm text-gray-700">
          <span class="material-symbols-outlined text-gray-400 text-base leading-5">chevron_right</span>
          <span><?= htmlspecialchars((string) $ln) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canEdit): ?>
    <form method="POST" action="<?= $base ?>/save-brief" class="mt-4 flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="stay_id" value="<?= (int) $stayFilter ?>">
      <div>
        <label class="block text-xs text-gray-500 mb-1">Save this brief as</label>
        <input type="text" name="title" class="input text-sm" placeholder="Operational brief <?= date('Y-m-d') ?>">
      </div>
      <button type="submit" class="btn btn-secondary text-sm">
        <span class="material-symbols-outlined text-base">bookmark_add</span> Save brief
      </button>
    </form>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Occupancy forecast -->
    <div class="card p-5">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Occupancy forecast</h2>
      <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars((string) ($forecast['message'] ?? '')) ?></p>
      <?php if (!empty($forecast['ok'])): ?>
        <div class="flex items-center gap-6 mb-3">
          <div>
            <div class="text-xs text-gray-500 uppercase tracking-wide">Baseline</div>
            <div class="text-2xl font-bold text-gray-900"><?= (float) ($forecast['baseline_pct'] ?? 0) ?><span class="text-base font-normal text-gray-400">%</span></div>
          </div>
          <div>
            <div class="text-xs text-gray-500 uppercase tracking-wide">Trend</div>
            <div class="text-2xl font-bold <?= ((float) ($forecast['trend_pct_per_day'] ?? 0)) >= 0 ? 'text-green-600' : 'text-red-600' ?>">
              <?= ((float) ($forecast['trend_pct_per_day'] ?? 0)) >= 0 ? '+' : '' ?><?= (float) ($forecast['trend_pct_per_day'] ?? 0) ?><span class="text-sm font-normal text-gray-400">pt/day</span>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500 uppercase tracking-wide">Confidence</div>
            <div class="text-base font-semibold <?= $confColor((string) ($forecast['confidence'] ?? 'none')) ?>"><?= htmlspecialchars((string) ($forecast['confidence'] ?? 'none')) ?></div>
            <div class="text-xs text-gray-400"><?= (int) ($forecast['sample'] ?? 0) ?> days history</div>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="py-1.5 pr-2 font-medium">Date</th><th class="py-1.5 pr-2 font-medium">Day</th><th class="py-1.5 font-medium text-right">Projected occ.</th>
            </tr></thead>
            <tbody>
              <?php foreach (array_slice($forecast['days'] ?? [], 0, 14) as $d): ?>
                <tr class="border-b border-gray-50">
                  <td class="py-1.5 pr-2 text-gray-700"><?= htmlspecialchars((string) ($d['date'] ?? '')) ?></td>
                  <td class="py-1.5 pr-2 text-gray-500"><?= htmlspecialchars((string) ($d['dow'] ?? '')) ?></td>
                  <td class="py-1.5 text-right font-medium text-gray-900"><?= (float) ($d['forecast_pct'] ?? 0) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-sm text-gray-500 py-6 text-center">No forecast yet.</div>
      <?php endif; ?>
    </div>

    <!-- Cancellation statistics -->
    <div class="card p-5">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Cancellation patterns</h2>
      <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars((string) ($cancel['message'] ?? '')) ?></p>
      <div class="flex items-center gap-6 mb-3">
        <div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Cancel rate</div>
          <div class="text-2xl font-bold <?= ((float) ($cancel['rate_pct'] ?? 0)) >= 20 ? 'text-red-600' : 'text-gray-900' ?>"><?= (float) ($cancel['rate_pct'] ?? 0) ?>%</div>
        </div>
        <div>
          <div class="text-xs text-gray-500 uppercase tracking-wide">Cancelled</div>
          <div class="text-2xl font-bold text-gray-900"><?= (int) ($cancel['cancelled'] ?? 0) ?><span class="text-base font-normal text-gray-400"> / <?= (int) ($cancel['total'] ?? 0) ?></span></div>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="py-1.5 pr-2 font-medium">Lead time</th><th class="py-1.5 pr-2 font-medium text-right">Bookings</th><th class="py-1.5 font-medium text-right">Cancelled</th>
          </tr></thead>
          <tbody>
            <?php foreach (($cancel['by_lead'] ?? []) as $bucket => $bv): ?>
              <?php $hi = isset($cancel['high_risk_buckets'][$bucket]); ?>
              <tr class="border-b border-gray-50 <?= $hi ? 'bg-red-50' : '' ?>">
                <td class="py-1.5 pr-2 text-gray-700"><?= htmlspecialchars((string) $bucket) ?><?= $hi ? ' <span class="text-xs text-red-600">(high)</span>' : '' ?></td>
                <td class="py-1.5 pr-2 text-right text-gray-500"><?= (int) ($bv['n'] ?? 0) ?></td>
                <td class="py-1.5 text-right font-medium text-gray-900"><?= (int) ($bv['cancelled'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Review themes -->
  <div class="card p-5">
    <h2 class="text-lg font-semibold text-gray-900 mb-1">Guest feedback themes</h2>
    <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars((string) ($themes['message'] ?? '')) ?></p>
    <?php if (!empty($themes['ok'])): ?>
      <?php $s = $themes['sentiment'] ?? ['positive' => 0, 'neutral' => 0, 'negative' => 0]; ?>
      <div class="flex items-center gap-4 mb-4 text-sm">
        <span class="inline-flex items-center gap-1 text-green-700"><span class="material-symbols-outlined text-base">sentiment_satisfied</span><?= (int) $s['positive'] ?> positive</span>
        <span class="inline-flex items-center gap-1 text-gray-500"><span class="material-symbols-outlined text-base">sentiment_neutral</span><?= (int) $s['neutral'] ?> neutral</span>
        <span class="inline-flex items-center gap-1 text-red-700"><span class="material-symbols-outlined text-base">sentiment_dissatisfied</span><?= (int) $s['negative'] ?> negative</span>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Most praised</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach (($themes['top_positive'] ?? []) as $w => $c): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-green-50 text-green-700 text-xs"><?= htmlspecialchars((string) $w) ?> <span class="text-green-500">×<?= (int) $c ?></span></span>
            <?php endforeach; ?>
            <?php if (empty($themes['top_positive'])): ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
          </div>
        </div>
        <div>
          <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Most flagged</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach (($themes['top_negative'] ?? []) as $w => $c): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-50 text-red-700 text-xs"><?= htmlspecialchars((string) $w) ?> <span class="text-red-500">×<?= (int) $c ?></span></span>
            <?php endforeach; ?>
            <?php if (empty($themes['top_negative'])): ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="text-sm text-gray-500 py-4">No survey comments yet.</div>
    <?php endif; ?>
  </div>

  <!-- Concierge (honest stub) -->
  <div class="card p-5">
    <div class="flex items-center justify-between gap-2 mb-1">
      <h2 class="text-lg font-semibold text-gray-900">AI concierge drafting</h2>
      <?php if (!empty($concierge['can_generate'])): ?>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700 text-xs"><span class="material-symbols-outlined text-sm">bolt</span>connected</span>
      <?php else: ?>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-xs"><span class="material-symbols-outlined text-sm">power_off</span>not connected</span>
      <?php endif; ?>
    </div>
    <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars((string) ($concierge['reason'] ?? '')) ?></p>

    <?php if ($canEdit): ?>
    <div class="space-y-2">
      <input id="ai-ext-intent" type="text" class="input text-sm w-full" placeholder="What should the message say? (e.g. welcome a returning VIP guest)">
      <textarea id="ai-ext-context" class="input text-sm w-full" rows="2" placeholder="Optional context (guest name, room, dates)"></textarea>
      <div class="flex items-center gap-2">
        <button id="ai-ext-draft-btn" type="button" class="btn btn-primary text-sm" <?= empty($concierge['can_generate']) ? 'disabled' : '' ?>>
          <span class="material-symbols-outlined text-base">auto_awesome</span> Draft message
        </button>
        <span id="ai-ext-draft-status" class="text-xs text-gray-400"></span>
      </div>
      <textarea id="ai-ext-draft-out" class="input text-sm w-full hidden" rows="4" readonly></textarea>
    </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">You do not have permission to use drafting.</p>
    <?php endif; ?>
  </div>

  <!-- Saved briefs -->
  <?php if (!empty($briefs)): ?>
  <div class="card p-5">
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Saved briefs</h2>
    <div class="space-y-3">
      <?php foreach ($briefs as $b): ?>
        <div class="border border-gray-100 rounded p-3">
          <div class="flex items-center justify-between gap-2">
            <div class="font-medium text-gray-800 text-sm"><?= htmlspecialchars((string) ($b['title'] ?? '')) ?></div>
            <div class="text-xs text-gray-400"><?= htmlspecialchars((string) ($b['created_at'] ?? '')) ?> · <?= htmlspecialchars((string) ($b['source'] ?? '')) ?></div>
          </div>
          <ul class="mt-1.5 space-y-1">
            <?php foreach (array_slice((array) ($b['lines'] ?? []), 0, 6) as $ln): ?>
              <li class="text-xs text-gray-600">• <?= htmlspecialchars((string) $ln) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var btn = document.getElementById('ai-ext-draft-btn');
  if (!btn) { return; }
  var out = document.getElementById('ai-ext-draft-out');
  var status = document.getElementById('ai-ext-draft-status');
  var token = <?= json_encode(CSRF::getToken()) ?>;
  btn.addEventListener('click', function () {
    var intent = (document.getElementById('ai-ext-intent').value || '').trim();
    var context = (document.getElementById('ai-ext-context').value || '').trim();
    if (!intent) { status.textContent = 'Enter what the message should say.'; return; }
    btn.disabled = true; status.textContent = 'Drafting…';
    var body = new URLSearchParams();
    body.append('csrf_token', token);
    body.append('intent', intent);
    body.append('context', context);
    fetch(<?= json_encode(root . 'supplier/ai-ext/concierge') ?>, {
      method: 'POST', headers: { 'X-CSRF-TOKEN': token }, body: body, credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (j) {
      btn.disabled = false;
      if (j && j.ok && j.text) {
        out.value = j.text; out.classList.remove('hidden'); status.textContent = 'Drafted by AI provider.';
      } else {
        out.classList.add('hidden'); status.textContent = (j && j.reason) ? j.reason : 'Not available.';
      }
    }).catch(function () { btn.disabled = false; status.textContent = 'Request failed.'; });
  });
})();
</script>
