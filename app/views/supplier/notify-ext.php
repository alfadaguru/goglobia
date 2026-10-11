<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Notifications & alerts EXTENSION view (PMS module 58). Surfaces four panes:
//   • Inbox        — the acting user's per-recipient notifications ($inbox,$unread)
//   • Preferences  — per-category channel opt-in for the acting user ($prefs)
//   • Alert rules  — WHEN event IF threshold THEN notify ($rules; owner-only $canConfigure)
//   • Webhooks     — outbound endpoints, INTERNAL record only ($endpoints,$webhookStatus)
// House style: precompiled Tailwind literal classes; htmlspecialchars everything.
$inbox          = (isset($inbox) && is_array($inbox)) ? $inbox : [];
$unread         = (int) ($unread ?? 0);
$prefs          = (isset($prefs) && is_array($prefs)) ? $prefs : [];
$rules          = (isset($rules) && is_array($rules)) ? $rules : [];
$endpoints      = (isset($endpoints) && is_array($endpoints)) ? $endpoints : [];
$recipients     = (isset($recipients) && is_array($recipients)) ? $recipients : [];
$eventCatalogue = (isset($eventCatalogue) && is_array($eventCatalogue)) ? $eventCatalogue : [];
$schedStatus    = (isset($schedStatus) && is_array($schedStatus)) ? $schedStatus : ['connected' => false, 'message' => ''];
$webhookStatus  = (isset($webhookStatus) && is_array($webhookStatus)) ? $webhookStatus : ['connected' => false, 'message' => ''];
$canConfigure   = !empty($canConfigure);
$base           = root . 'supplier/alerts';
$csrf           = class_exists('CSRF') ? CSRF::tokenField() : '';

$cats = function_exists('notify_categories') ? notify_categories() : [];
$sevs = function_exists('notify_severities') ? notify_severities() : [];
$chans = function_exists('notify_channels') ? notify_channels() : [];
$ops  = function_exists('notify_threshold_ops') ? notify_threshold_ops() : [];

$sevColor = [
    'info'     => 'bg-blue-50 text-blue-700',
    'success'  => 'bg-green-50 text-green-700',
    'warning'  => 'bg-amber-50 text-amber-700',
    'critical' => 'bg-red-50 text-red-700',
];
// Flatten the event catalogue into a flat list for the rule <select>.
$flatEvents = [];
foreach ($eventCatalogue as $grp => $names) { foreach ((array) $names as $n) { $flatEvents[] = (string) $n; } }
$curStatus = (string) ($_GET['status'] ?? '');
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
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
      <h1 class="text-2xl font-bold text-gray-900">Notifications &amp; alerts</h1>
      <p class="text-sm text-gray-600">Your alert inbox, per-type delivery preferences, and the alert rules that turn platform events into targeted notifications.</p>
    </div>
    <div class="text-sm">
      <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full <?= $unread > 0 ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600' ?>">
        <span class="material-symbols-outlined text-base">notifications</span><?= (int) $unread ?> unread
      </span>
    </div>
  </div>

  <!-- Scheduler honesty banner -->
  <?php if (empty($schedStatus['connected'])): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      <div class="flex items-start gap-2">
        <span class="material-symbols-outlined text-amber-600">schedule</span>
        <div>
          <p class="font-medium">Alert scheduler not wired</p>
          <p class="mt-0.5"><?= htmlspecialchars((string) ($schedStatus['message'] ?? '')) ?></p>
          <?php if (!empty($schedStatus['tick_command'])): ?>
            <p class="mt-1 font-mono text-xs text-amber-700"><?= htmlspecialchars((string) $schedStatus['tick_command']) ?></p>
          <?php endif; ?>
          <?php if ($canConfigure): ?>
            <form method="POST" action="<?= $base ?>/tick" class="mt-2">
              <?= $csrf ?>
              <button type="submit" class="px-3 py-1.5 rounded bg-amber-600 text-white text-xs">Run one pass now</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ===================== INBOX ===================== -->
  <div class="card p-5 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <h2 class="text-lg font-semibold text-gray-900">Inbox</h2>
      <div class="flex items-center gap-2 text-sm">
        <a href="<?= $base ?>" class="px-3 py-1.5 rounded <?= $curStatus === '' ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700' ?>">Active</a>
        <a href="<?= $base ?>?status=unread" class="px-3 py-1.5 rounded <?= $curStatus === 'unread' ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700' ?>">Unread</a>
        <a href="<?= $base ?>?status=archived" class="px-3 py-1.5 rounded <?= $curStatus === 'archived' ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-700' ?>">Archived</a>
        <?php if ($unread > 0): ?>
          <form method="POST" action="<?= $base ?>/mark-all-read"><?= $csrf ?><button type="submit" class="px-3 py-1.5 rounded bg-gray-100 text-gray-700">Mark all read</button></form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($inbox)): ?>
      <p class="text-sm text-gray-500 py-6 text-center">No notifications in this view.</p>
    <?php else: ?>
      <div class="divide-y divide-gray-100">
        <?php foreach ($inbox as $n): ?>
          <?php
            $nStatus = (string) ($n['status'] ?? 'read');
            $nSev = (string) ($n['severity'] ?? 'info');
            $nCat = (string) ($n['category'] ?? 'system');
          ?>
          <div class="py-3 flex items-start justify-between gap-3 <?= $nStatus === 'unread' ? 'bg-violet-50/40 -mx-5 px-5' : '' ?>">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs px-2 py-0.5 rounded-full <?= $sevColor[$nSev] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($sevs[$nSev] ?? $nSev) ?></span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= htmlspecialchars($cats[$nCat]['label'] ?? $nCat) ?></span>
                <?php if ($nStatus === 'unread'): ?><span class="w-2 h-2 rounded-full bg-violet-600 inline-block"></span><?php endif; ?>
              </div>
              <p class="mt-1 text-sm font-medium text-gray-900"><?= htmlspecialchars((string) ($n['title'] ?? '')) ?></p>
              <?php if (!empty($n['body'])): ?><p class="text-sm text-gray-600"><?= htmlspecialchars((string) $n['body']) ?></p><?php endif; ?>
              <p class="mt-0.5 text-xs text-gray-400"><?= htmlspecialchars((string) ($n['event'] ?? '')) ?> · <?= htmlspecialchars((string) ($n['created_at'] ?? '')) ?></p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <?php if ($nStatus === 'unread'): ?>
                <form method="POST" action="<?= $base ?>/mark"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="status" value="read"><button type="submit" class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700" title="Mark read">Read</button></form>
              <?php else: ?>
                <form method="POST" action="<?= $base ?>/mark"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="status" value="unread"><button type="submit" class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700" title="Mark unread">Unread</button></form>
              <?php endif; ?>
              <?php if ($nStatus !== 'archived'): ?>
                <form method="POST" action="<?= $base ?>/mark"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="status" value="archived"><button type="submit" class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-500" title="Archive">Archive</button></form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===================== PREFERENCES ===================== -->
  <div class="card p-5 space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-gray-900">Your delivery preferences</h2>
      <p class="text-sm text-gray-600">Choose which channels you receive for each category. In-app stays on for Approval &amp; System alerts. These apply to your account only.</p>
    </div>
    <form method="POST" action="<?= $base ?>/prefs" class="space-y-3">
      <?= $csrf ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="py-2 pr-3">Category</th>
              <th class="py-2 px-3 text-center">In-app</th>
              <th class="py-2 px-3 text-center">Email</th>
              <th class="py-2 px-3 text-center">Webhook</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cats as $cat => $def): ?>
              <?php
                $p = $prefs[$cat] ?? ['inapp' => true, 'email' => false, 'webhook' => false];
                $lockInapp = in_array($cat, ['approval', 'system'], true);
              ?>
              <tr class="border-b border-gray-50">
                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars($def['label'] ?? $cat) ?></td>
                <td class="py-2 px-3 text-center">
                  <input type="checkbox" name="pref[<?= htmlspecialchars($cat) ?>][inapp]" value="1" <?= !empty($p['inapp']) ? 'checked' : '' ?> <?= $lockInapp ? 'disabled' : '' ?>>
                  <?php if ($lockInapp): ?><input type="hidden" name="pref[<?= htmlspecialchars($cat) ?>][inapp]" value="1"><?php endif; ?>
                </td>
                <td class="py-2 px-3 text-center"><input type="checkbox" name="pref[<?= htmlspecialchars($cat) ?>][email]" value="1" <?= !empty($p['email']) ? 'checked' : '' ?>></td>
                <td class="py-2 px-3 text-center"><input type="checkbox" name="pref[<?= htmlspecialchars($cat) ?>][webhook]" value="1" <?= !empty($p['webhook']) ? 'checked' : '' ?>></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end"><button type="submit" class="px-4 py-2 rounded bg-violet-600 text-white text-sm">Save preferences</button></div>
    </form>
    <p class="text-xs text-gray-400">Email is delivered only when the platform has a matching template/provider configured. Webhook delivery is recorded but not yet sent — see below.</p>
  </div>

  <!-- ===================== ALERT RULES ===================== -->
  <div class="card p-5 space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-gray-900">Alert rules</h2>
      <p class="text-sm text-gray-600">When a platform event fires and an optional numeric threshold is met, notify the chosen recipients. Rules fire on the next scheduler pass.</p>
    </div>

    <?php if (empty($rules)): ?>
      <p class="text-sm text-gray-500">No alert rules yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="py-2 pr-3">Name</th><th class="py-2 px-3">Event</th><th class="py-2 px-3">Threshold</th>
              <th class="py-2 px-3">Recipient</th><th class="py-2 px-3">Channels</th><th class="py-2 px-3">State</th><th class="py-2 pl-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rules as $r): ?>
              <?php
                $rActive = (int) ($r['active'] ?? 0) === 1;
                $thr = trim((string) ($r['threshold_field'] ?? ''));
                $thrLabel = $thr !== '' ? ($thr . ' ' . ($ops[(string) ($r['threshold_op'] ?? '')] ?? '') . ' ' . htmlspecialchars((string) ($r['threshold_value'] ?? ''))) : '—';
                $rRecip = (string) ($r['recipient'] ?? 'all');
                $recipLabel = ($rRecip === 'all' || $rRecip === '') ? 'Everyone' : ($recipients[$rRecip] ?? $rRecip);
              ?>
              <tr class="border-b border-gray-50">
                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
                <td class="py-2 px-3 font-mono text-xs text-gray-600"><?= htmlspecialchars((string) ($r['event'] ?? '')) ?></td>
                <td class="py-2 px-3 text-gray-600"><?= htmlspecialchars($thrLabel) ?></td>
                <td class="py-2 px-3 text-gray-600"><?= htmlspecialchars((string) $recipLabel) ?></td>
                <td class="py-2 px-3 text-gray-600"><?= htmlspecialchars((string) ($r['channels'] ?? '')) ?></td>
                <td class="py-2 px-3"><span class="text-xs px-2 py-0.5 rounded-full <?= $rActive ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $rActive ? 'Active' : 'Off' ?></span></td>
                <td class="py-2 pl-3 text-right whitespace-nowrap">
                  <?php if ($canConfigure): ?>
                    <form method="POST" action="<?= $base ?>/rule/toggle" class="inline"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="active" value="<?= $rActive ? '0' : '1' ?>"><button type="submit" class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700"><?= $rActive ? 'Disable' : 'Enable' ?></button></form>
                    <form method="POST" action="<?= $base ?>/rule/delete" class="inline" onsubmit="return confirm('Delete this rule?');"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="text-xs px-2 py-1 rounded bg-red-50 text-red-700">Delete</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canConfigure): ?>
      <details class="rounded-lg border border-gray-200 p-4">
        <summary class="cursor-pointer text-sm font-medium text-gray-800">+ New alert rule</summary>
        <form method="POST" action="<?= $base ?>/rule" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?= $csrf ?>
          <label class="text-sm"><span class="block text-gray-600 mb-1">Name</span><input type="text" name="name" required class="w-full border border-gray-300 rounded px-3 py-2" placeholder="e.g. Big refund alert"></label>
          <label class="text-sm"><span class="block text-gray-600 mb-1">Event</span>
            <select name="event" class="w-full border border-gray-300 rounded px-3 py-2">
              <?php foreach ($flatEvents as $ev): ?><option value="<?= htmlspecialchars($ev) ?>"><?= htmlspecialchars($ev) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm"><span class="block text-gray-600 mb-1">Severity</span>
            <select name="severity" class="w-full border border-gray-300 rounded px-3 py-2">
              <?php foreach ($sevs as $k => $v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm"><span class="block text-gray-600 mb-1">Recipient</span>
            <select name="recipient" class="w-full border border-gray-300 rounded px-3 py-2">
              <option value="all">Everyone (owner + staff)</option>
              <?php foreach ($recipients as $rid => $rlabel): ?><option value="<?= htmlspecialchars((string) $rid) ?>"><?= htmlspecialchars((string) $rlabel) ?></option><?php endforeach; ?>
            </select>
          </label>
          <div class="sm:col-span-2 grid grid-cols-3 gap-3">
            <label class="text-sm"><span class="block text-gray-600 mb-1">Threshold field</span><input type="text" name="threshold_field" class="w-full border border-gray-300 rounded px-3 py-2" placeholder="e.g. amount (optional)"></label>
            <label class="text-sm"><span class="block text-gray-600 mb-1">Op</span>
              <select name="threshold_op" class="w-full border border-gray-300 rounded px-3 py-2">
                <option value="">—</option>
                <?php foreach ($ops as $k => $v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="text-sm"><span class="block text-gray-600 mb-1">Value</span><input type="number" step="any" name="threshold_value" class="w-full border border-gray-300 rounded px-3 py-2"></label>
          </div>
          <div class="sm:col-span-2">
            <span class="block text-sm text-gray-600 mb-1">Channels</span>
            <div class="flex items-center gap-4 text-sm">
              <?php foreach ($chans as $ck => $cv): ?>
                <label class="inline-flex items-center gap-1"><input type="checkbox" name="channels[]" value="<?= htmlspecialchars($ck) ?>" <?= $ck === 'inapp' ? 'checked' : '' ?>><?= htmlspecialchars($cv) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="text-sm sm:col-span-2"><span class="block text-gray-600 mb-1">Email template (optional)</span><input type="text" name="email_template" class="w-full border border-gray-300 rounded px-3 py-2" placeholder="platform template name — email is skipped if blank/unconfigured"></label>
          <div class="sm:col-span-2 flex justify-end"><button type="submit" class="px-4 py-2 rounded bg-violet-600 text-white text-sm">Create rule</button></div>
        </form>
      </details>
    <?php endif; ?>
  </div>

  <!-- ===================== OUTBOUND WEBHOOKS ===================== -->
  <div class="card p-5 space-y-4">
    <div>
      <h2 class="text-lg font-semibold text-gray-900">Outbound webhooks</h2>
      <p class="text-sm text-gray-600">Register an endpoint to receive alert payloads. Endpoints are recorded now; delivery ships in a later increment.</p>
    </div>

    <?php if (empty($webhookStatus['connected'])): ?>
      <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
        <span class="material-symbols-outlined text-gray-400 align-middle">link_off</span>
        <?= htmlspecialchars((string) ($webhookStatus['message'] ?? 'Not connected.')) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($endpoints)): ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-3">Label</th><th class="py-2 px-3">URL</th><th class="py-2 px-3">State</th><th class="py-2 pl-3"></th></tr></thead>
          <tbody>
            <?php foreach ($endpoints as $ep): ?>
              <tr class="border-b border-gray-50">
                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars((string) ($ep['label'] ?? '')) ?></td>
                <td class="py-2 px-3 font-mono text-xs text-gray-600 break-all"><?= htmlspecialchars((string) ($ep['url'] ?? '')) ?></td>
                <td class="py-2 px-3"><span class="text-xs px-2 py-0.5 rounded-full <?= (int) ($ep['active'] ?? 0) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= (int) ($ep['active'] ?? 0) === 1 ? 'Active' : 'Off' ?></span></td>
                <td class="py-2 pl-3 text-right">
                  <?php if ($canConfigure): ?>
                    <form method="POST" action="<?= $base ?>/webhook/delete" class="inline" onsubmit="return confirm('Delete this endpoint?');"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $ep['id'] ?>"><button type="submit" class="text-xs px-2 py-1 rounded bg-red-50 text-red-700">Delete</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canConfigure): ?>
      <form method="POST" action="<?= $base ?>/webhook" class="flex flex-col sm:flex-row gap-3 sm:items-end">
        <?= $csrf ?>
        <label class="text-sm flex-1"><span class="block text-gray-600 mb-1">Label</span><input type="text" name="label" class="w-full border border-gray-300 rounded px-3 py-2" placeholder="e.g. Ops Slack bridge"></label>
        <label class="text-sm flex-[2]"><span class="block text-gray-600 mb-1">Endpoint URL</span><input type="url" name="url" required class="w-full border border-gray-300 rounded px-3 py-2" placeholder="https://..."></label>
        <button type="submit" class="px-4 py-2 rounded bg-violet-600 text-white text-sm">Add endpoint</button>
      </form>
    <?php endif; ?>
  </div>
</div>
