<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Smart locks & access control (PMS #52 ext). Data from supplierLocksExtRoutes.php:
// $locks, $credsByLock[lockId][], $accessLog, $analytics, $adapter, $propMap,
// $physRooms[stayId][], $canEdit, $stayFilter.
$locks       = (isset($locks) && is_array($locks)) ? $locks : [];
$credsByLock = (isset($credsByLock) && is_array($credsByLock)) ? $credsByLock : [];
$accessLog   = (isset($accessLog) && is_array($accessLog)) ? $accessLog : [];
$analytics   = (isset($analytics) && is_array($analytics)) ? $analytics : [];
$adapter     = (isset($adapter) && is_array($adapter)) ? $adapter : ['connected' => false, 'message' => ''];
$propMap     = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$physRooms   = (isset($physRooms) && is_array($physRooms)) ? $physRooms : [];
$canEdit     = !empty($canEdit);
$stayFilter  = (int) ($stayFilter ?? 0);
$base = root . 'supplier/access/locks';
$pickRooms = $stayFilter > 0 ? ($physRooms[$stayFilter] ?? []) : [];
$kindLabel = function_exists('locks_kinds') ? locks_kinds() : [];
$credTypeLabel = function_exists('locks_credential_types') ? locks_credential_types() : [];
$csColor = ['pending'=>'bg-amber-50 text-amber-700','active'=>'bg-green-50 text-green-700','revoked'=>'bg-gray-100 text-gray-500','expired'=>'bg-rose-50 text-rose-700'];
$roomLabel = [];
foreach ($physRooms as $sid => $rows) { foreach ($rows as $r) { $roomLabel[(int)$r['id']] = (string)$r['room_number']; } }
// Locks scoped to the active single property (for credential pickers).
$lockOpts = array_filter($locks, fn($l) => (!empty($l['active'])) && ($stayFilter <= 0 || (int)$l['stay_id'] === $stayFilter));
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
      <h1 class="text-2xl font-bold text-gray-900">Smart locks &amp; access control</h1>
      <p class="text-sm text-gray-600">Lock registry, room mapping, PIN/mobile credential issue &amp; revoke, and access log.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- HONEST ADAPTER NOTICE -->
  <div class="card p-4 flex items-start gap-3 border-l-4 border-amber-300">
    <span class="material-symbols-outlined text-amber-500">link_off</span>
    <div>
      <div class="text-sm font-semibold text-gray-900">Device adapter: not connected</div>
      <p class="text-xs text-gray-600"><?= htmlspecialchars((string) ($adapter['message'] ?? 'No smart-lock vendor adapter is connected.')) ?></p>
    </div>
  </div>

  <!-- ANALYTICS -->
  <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
    <?php
      $kpis = [
        ['Locks', (int)($analytics['locks'] ?? 0), 'bg-indigo-50 text-indigo-700'],
        ['Active', (int)($analytics['active_locks'] ?? 0), 'bg-green-50 text-green-700'],
        ['Rooms mapped', (int)($analytics['rooms_mapped'] ?? 0), 'bg-blue-50 text-blue-700'],
        ['Active creds', (int)($analytics['active_creds'] ?? 0), 'bg-violet-50 text-violet-700'],
        ['Pending', (int)($analytics['pending_creds'] ?? 0), 'bg-amber-50 text-amber-700'],
        ['Revoked 30d', (int)($analytics['revoked_30d'] ?? 0), 'bg-gray-100 text-gray-600'],
        ['Expiring 48h', (int)($analytics['expiring_48h'] ?? 0), 'bg-rose-50 text-rose-700'],
        ['Low battery', (int)($analytics['low_battery'] ?? 0), 'bg-red-50 text-red-700'],
      ];
      foreach ($kpis as $k): ?>
      <div class="card p-4">
        <div class="text-xs text-gray-500"><?= htmlspecialchars($k[0]) ?></div>
        <div class="mt-1 inline-flex px-2 py-0.5 rounded text-lg font-bold <?= $k[2] ?>"><?= htmlspecialchars((string) $k[1]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($stayFilter <= 0): ?>
    <div class="card p-4 text-sm text-gray-500">Select a single property above to register a lock, issue credentials or log an access event.</div>
  <?php endif; ?>

  <!-- CREATE FORMS (single-property only, so pickers are unambiguous) -->
  <?php if ($canEdit && $stayFilter > 0): ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Register a lock -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Register a lock</h2>
      <form action="<?= $base ?>/register" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <input type="text" name="name" class="input text-sm" placeholder="Lock name * (e.g. Front door / Room 101)" required>
        <div class="flex flex-wrap gap-2">
          <select name="kind" class="input text-sm">
            <?php foreach ($kindLabel as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
          </select>
          <select name="physical_room_id" class="input text-sm">
            <option value="0">— shared / no room —</option>
            <?php foreach ($pickRooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= htmlspecialchars((string) $r['room_number']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-wrap gap-2">
          <input type="text" name="provider" class="input text-sm w-36" placeholder="Provider (e.g. Nuki)">
          <input type="text" name="external_ref" class="input text-sm w-40" placeholder="Device id (vendor ref)">
          <div><label class="block text-xs text-gray-600 mb-1">Battery %</label><input type="number" min="0" max="100" name="battery_pct" class="input text-sm w-24"></div>
        </div>
        <input type="text" name="location_label" class="input text-sm" placeholder="Location label (e.g. Lobby entrance)">
        <button type="submit" class="btn secondary text-sm">Register lock</button>
      </form>
    </div>

    <!-- Issue a credential -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Issue a credential</h2>
      <?php if (empty($lockOpts)): ?>
        <p class="text-sm text-gray-500">Register a lock first.</p>
      <?php else: ?>
      <p class="text-xs text-gray-500 mb-2">Leave the PIN blank to auto-generate. Issuing records the credential; it does not program the device (no adapter).</p>
      <form action="<?= $base ?>/credential/issue" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <select name="lock_id" class="input text-sm w-full">
          <?php foreach ($lockOpts as $l): ?><option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars((string) $l['name']) ?></option><?php endforeach; ?>
        </select>
        <div class="flex flex-wrap gap-2">
          <select name="cred_type" class="input text-sm">
            <?php foreach ($credTypeLabel as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="label" class="input text-sm flex-1" placeholder="Label (e.g. Guest / Cleaner)">
        </div>
        <div class="flex flex-wrap gap-2">
          <input type="text" name="pin_code" class="input text-sm w-32" placeholder="PIN (optional)" inputmode="numeric">
          <input type="text" name="mobile_handle" class="input text-sm flex-1" placeholder="Mobile invite target (if mobile)">
        </div>
        <div class="flex flex-wrap gap-2">
          <div><label class="block text-xs text-gray-600 mb-1">Valid from</label><input type="datetime-local" name="valid_from" class="input text-sm"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Valid until</label><input type="datetime-local" name="valid_until" class="input text-sm"></div>
        </div>
        <button type="submit" class="btn secondary text-sm">Issue credential</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Log a manual access event -->
  <?php if (!empty($lockOpts)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Log a manual access event</h2>
    <p class="text-xs text-gray-500 mb-2">Manual entry — there is no live device feed until an adapter is connected.</p>
    <form action="<?= $base ?>/event" method="POST" class="flex flex-wrap items-end gap-2" style="display:flex">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
      <select name="lock_id" class="input text-sm">
        <?php foreach ($lockOpts as $l): ?><option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars((string) $l['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="event" class="input text-sm">
        <option value="unlock">Unlock</option>
        <option value="denied">Access denied</option>
        <option value="battery_low">Battery low</option>
        <option value="tamper">Tamper</option>
        <option value="note">Note</option>
      </select>
      <input type="text" name="detail" class="input text-sm flex-1" placeholder="Detail (optional)">
      <button type="submit" class="btn secondary text-sm">Log event</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- LOCK REGISTRY with credentials -->
  <?php if (!empty($locks)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Lock registry &amp; credentials</h2>
    <div class="space-y-3">
      <?php foreach ($locks as $lk): $lid = (int) $lk['id']; $sid = (int) $lk['stay_id']; ?>
        <div class="border border-gray-100 rounded-lg p-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $lk['name']) ?></span>
              <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600"><?= htmlspecialchars($kindLabel[(string)$lk['kind']] ?? (string)$lk['kind']) ?></span>
              <?php if (!empty($lk['physical_room_id'])): ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">Room #<?= htmlspecialchars($roomLabel[(int)$lk['physical_room_id']] ?? (string)$lk['physical_room_id']) ?></span>
              <?php else: ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700">Shared door</span>
              <?php endif; ?>
              <?php if (!empty($lk['provider'])): ?><span class="text-xs text-gray-500"><?= htmlspecialchars((string) $lk['provider']) ?></span><?php endif; ?>
              <?php if ($lk['battery_pct'] !== null && $lk['battery_pct'] !== ''): ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= ((int)$lk['battery_pct'] < 20) ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600' ?>"><?= (int) $lk['battery_pct'] ?>% batt</span>
              <?php endif; ?>
              <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Device: unknown</span>
              <?php if (empty($lk['active'])): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-600">Inactive</span><?php endif; ?>
            </div>
            <?php if ($canEdit): ?>
            <form action="<?= $base ?>/update" method="POST" class="inline" style="display:inline">
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="lock_id" value="<?= $lid ?>">
              <input type="hidden" name="active" value="<?= empty($lk['active']) ? '1' : '0' ?>">
              <button type="submit" class="text-xs text-violet-600 hover:underline"><?= empty($lk['active']) ? 'Reactivate' : 'Deactivate' ?></button>
            </form>
            <?php endif; ?>
          </div>

          <!-- Credentials on this lock -->
          <?php $creds = $credsByLock[$lid] ?? []; ?>
          <?php if (!empty($creds)): ?>
            <div class="mt-2 overflow-x-auto"><table class="w-full text-xs">
              <thead><tr class="text-gray-400 text-left"><th class="px-2 py-1">Type</th><th class="px-2 py-1">Label</th><th class="px-2 py-1">Secret</th><th class="px-2 py-1">Window</th><th class="px-2 py-1">Status</th><th class="px-2 py-1"></th></tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($creds as $c): ?>
                  <tr>
                    <td class="px-2 py-1 text-gray-500"><?= htmlspecialchars($credTypeLabel[(string)$c['cred_type']] ?? (string)$c['cred_type']) ?></td>
                    <td class="px-2 py-1 text-gray-700"><?= htmlspecialchars((string) ($c['label'] ?? '')) ?></td>
                    <td class="px-2 py-1 font-mono text-gray-700"><?= htmlspecialchars((string) ($c['pin_code'] ?? $c['mobile_handle'] ?? '—')) ?></td>
                    <td class="px-2 py-1 text-gray-500"><?= htmlspecialchars(($c['valid_from'] ?? '') ?: '—') ?> &rarr; <?= htmlspecialchars(($c['valid_until'] ?? '') ?: '—') ?></td>
                    <td class="px-2 py-1"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $csColor[(string)$c['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst((string) $c['status'])) ?></span></td>
                    <td class="px-2 py-1 text-right">
                      <?php if ($canEdit && in_array((string)$c['status'], ['active','pending'], true)): ?>
                      <form action="<?= $base ?>/credential/revoke" method="POST" class="inline" style="display:inline">
                        <?= CSRF::tokenField() ?>
                        <input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="credential_id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="text-xs text-red-600 hover:underline">Revoke</button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php else: ?>
            <p class="mt-2 text-xs text-gray-400">No credentials issued.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
    <div class="card p-6 text-center text-sm text-gray-500">No locks registered yet.</div>
  <?php endif; ?>

  <!-- ACCESS LOG -->
  <?php if (!empty($accessLog)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Access log <span class="text-xs font-normal text-gray-400">(manual &amp; system events — no live device feed)</span></h2>
    <div class="overflow-x-auto"><table class="w-full text-xs">
      <thead><tr class="text-gray-400 text-left"><th class="px-2 py-1">When</th><th class="px-2 py-1">Event</th><th class="px-2 py-1">Source</th><th class="px-2 py-1">Detail</th><th class="px-2 py-1">Actor</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($accessLog as $ev): ?>
          <tr>
            <td class="px-2 py-1 text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string) $ev['created_at']) ?></td>
            <td class="px-2 py-1 text-gray-700"><?= htmlspecialchars((string) $ev['event']) ?></td>
            <td class="px-2 py-1 text-gray-400"><?= htmlspecialchars((string) $ev['source']) ?></td>
            <td class="px-2 py-1 text-gray-600"><?= htmlspecialchars((string) ($ev['detail'] ?? '')) ?></td>
            <td class="px-2 py-1 text-gray-400"><?= htmlspecialchars((string) ($ev['actor'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>
