<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Provided by supplierPlatformopsExtRoutes.php (all owner-scoped; escape on output):
  // $tab, $privacyRequests, $sessionInfo, $authEvents, $sessionCaps,
  // $configKeys, $configValues, $configLog, $infra, $canManage.
  $tab             = $tab ?? 'privacy';
  $privacyRequests = is_array($privacyRequests ?? null) ? $privacyRequests : [];
  $sessionInfo     = is_array($sessionInfo ?? null) ? $sessionInfo : [];
  $authEvents      = is_array($authEvents ?? null) ? $authEvents : [];
  $sessionCaps     = is_array($sessionCaps ?? null) ? $sessionCaps : [];
  $configKeys      = is_array($configKeys ?? null) ? $configKeys : [];
  $configValues    = is_array($configValues ?? null) ? $configValues : [];
  $configLog       = is_array($configLog ?? null) ? $configLog : [];
  $infra           = is_array($infra ?? null) ? $infra : [];
  $canManage       = !empty($canManage);

  $tabs = [
    'privacy'  => ['Data privacy',     'shield_person'],
    'sessions' => ['Session security', 'devices'],
    'config'   => ['Configuration',    'tune'],
    'infra'    => ['Continuity',       'cloud_done'],
  ];
  $statusBadge = function (string $s): array {
    switch ($s) {
      case 'connected':      return ['bg-green-50 text-green-700', 'Connected'];
      case 'admin_managed':  return ['bg-blue-50 text-blue-700', 'Admin managed'];
      case 'managed_by_host':return ['bg-indigo-50 text-indigo-700', 'Managed by host'];
      case 'not_connected':  return ['bg-gray-100 text-gray-500', 'Not connected'];
      default:               return ['bg-gray-100 text-gray-600', ucfirst($s)];
    }
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

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Security &amp; platform operations</h1>
      <p class="text-sm text-gray-600">Guest data-privacy requests, your session security, platform configuration, and continuity status.</p>
    </div>
    <a href="<?= root ?>supplier/rbac" class="text-sm text-blue-600 hover:underline">Access control</a>
  </div>

  <!-- Tabs -->
  <div class="border-b border-gray-200 flex gap-1 overflow-x-auto">
    <?php foreach ($tabs as $key => $meta): ?>
      <a href="<?= root ?>supplier/platform-ops?tab=<?= htmlspecialchars($key) ?>"
         class="px-4 py-2 text-sm font-medium border-b-2 whitespace-nowrap <?= $tab === $key ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
        <span class="material-symbols-outlined text-sm align-middle mr-1"><?= htmlspecialchars($meta[1]) ?></span><?= htmlspecialchars($meta[0]) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'privacy'): ?>
    <!-- ================= DATA PRIVACY (DSAR) ================= -->
    <div class="card p-4 bg-amber-50 border border-amber-200 text-xs text-amber-800">
      Export gathers everything <strong>you</strong> hold about a guest (profile, consent, stay history, messages).
      Erasure redacts the guest's stored profile PII only — <strong>bookings, invoices and ledger entries are kept</strong>
      under accounting-retention rules and are never deleted here.
    </div>

    <div class="grid md:grid-cols-2 gap-5">
      <!-- Export -->
      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-800 mb-1">Export a guest's data</h2>
        <p class="text-xs text-gray-500 mb-3">Right to access / portability. Downloads a JSON file.</p>
        <form action="<?= root ?>supplier/platform-ops/privacy/export" method="POST" class="space-y-3">
          <?= CSRF::tokenField() ?>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-600 mb-1">Guest email</label>
            <input type="email" name="email" required class="input" placeholder="guest@example.com" <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <button type="submit" class="btn emerald" <?= $canManage ? '' : 'disabled' ?>>
            <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">download</span> Export JSON</span>
          </button>
        </form>
      </div>

      <!-- Erase -->
      <div class="card p-5 border-red-100">
        <h2 class="text-sm font-semibold text-gray-800 mb-1">Erase a guest's profile</h2>
        <p class="text-xs text-gray-500 mb-3">Right to erasure. Irreversible. Requires a password step-up and typing <strong>ERASE</strong>.</p>
        <form action="<?= root ?>supplier/platform-ops/privacy/erase" method="POST" class="space-y-3">
          <?= CSRF::tokenField() ?>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-600 mb-1">Guest email</label>
            <input type="email" name="email" required class="input" placeholder="guest@example.com" <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-600 mb-1">Type ERASE to confirm</label>
            <input type="text" name="confirm" class="input" placeholder="ERASE" <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <button type="submit" class="btn bg-red-600 text-white hover:bg-red-700" <?= $canManage ? '' : 'disabled' ?>>
            <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">delete_forever</span> Erase profile</span>
          </button>
        </form>
      </div>
    </div>

    <!-- Request ledger -->
    <div class="card overflow-x-auto">
      <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-800">Recent data-subject requests</h2></div>
      <table class="w-full text-sm mt-2">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">When</th>
            <th class="px-4 py-3 font-medium">Guest</th>
            <th class="px-4 py-3 font-medium">Type</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">By</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($privacyRequests)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No requests yet.</td></tr>
          <?php else: foreach ($privacyRequests as $r): ?>
            <tr>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($r['subject_email'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars(ucfirst($r['kind'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <?php $st = $r['status'] ?? 'open'; $stc = $st === 'fulfilled' ? 'bg-green-50 text-green-700' : ($st === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700'); ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stc ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
              </td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($r['requested_by'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'sessions'): ?>
    <!-- ================= SESSION SECURITY ================= -->
    <div class="grid md:grid-cols-2 gap-5">
      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-800 mb-3">This session</h2>
        <dl class="text-sm space-y-2">
          <div class="flex justify-between"><dt class="text-gray-500">Fingerprint</dt><dd class="font-mono text-gray-800"><?= htmlspecialchars($sessionInfo['session_fingerprint'] ?? '') ?></dd></div>
          <div class="flex justify-between"><dt class="text-gray-500">IP</dt><dd class="text-gray-800"><?= htmlspecialchars($sessionInfo['ip'] ?? '') ?></dd></div>
          <div class="flex justify-between gap-4"><dt class="text-gray-500 shrink-0">Device</dt><dd class="text-gray-800 text-right break-all"><?= htmlspecialchars($sessionInfo['user_agent'] ?? '') ?: '—' ?></dd></div>
        </dl>
        <form action="<?= root ?>supplier/platform-ops/session/rotate" method="POST" class="mt-4">
          <?= CSRF::tokenField() ?>
          <button type="submit" class="btn emerald">
            <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">autorenew</span> Rotate my session id</span>
          </button>
        </form>
      </div>

      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-800 mb-3">Capabilities</h2>
        <ul class="space-y-2 text-sm">
          <?php foreach ($sessionCaps as $cap): ?>
            <?php [$cls, $lbl] = $statusBadge($cap['status'] ?? ''); ?>
            <li class="flex items-start justify-between gap-3">
              <span class="text-gray-700"><?= htmlspecialchars($cap['label'] ?? '') ?>
                <span class="block text-xs text-gray-400"><?= htmlspecialchars($cap['note'] ?? '') ?></span>
              </span>
              <span class="px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-800">Your recent authentication activity</h2></div>
      <table class="w-full text-sm mt-2">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">When</th>
            <th class="px-4 py-3 font-medium">Event</th>
            <th class="px-4 py-3 font-medium">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($authEvents)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No recent authentication events.</td></tr>
          <?php else: foreach ($authEvents as $e): ?>
            <tr>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($e['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($e['event'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($e['ip'] ?? '') ?: '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($tab === 'config'): ?>
    <!-- ================= CONFIGURATION MANAGEMENT ================= -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-800 mb-3">Platform-ops settings</h2>
      <?php if (!$canManage): ?>
        <p class="text-xs text-gray-500 mb-3">You have read-only access to these settings.</p>
      <?php endif; ?>
      <div class="space-y-4">
        <?php foreach ($configKeys as $k => $meta): ?>
          <form action="<?= root ?>supplier/platform-ops/config/save" method="POST" class="flex flex-col sm:flex-row gap-3 sm:items-end border-b border-gray-50 pb-4">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
            <div class="form-control flex-1">
              <label class="block text-xs font-medium text-gray-600 mb-1"><?= htmlspecialchars($meta['label'] ?? $k) ?></label>
              <input type="<?= ($meta['type'] ?? 'string') === 'email' ? 'email' : (($meta['type'] ?? '') === 'int' ? 'number' : 'text') ?>"
                     name="value" class="input"
                     value="<?= htmlspecialchars($configValues[$k] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>>
              <span class="block text-xs text-gray-400 mt-1"><?= htmlspecialchars($meta['hint'] ?? '') ?></span>
            </div>
            <?php if ($canManage): ?>
              <button type="submit" class="btn emerald">Save</button>
            <?php endif; ?>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-800">Change log</h2></div>
      <table class="w-full text-sm mt-2">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">When</th>
            <th class="px-4 py-3 font-medium">Setting</th>
            <th class="px-4 py-3 font-medium">Old</th>
            <th class="px-4 py-3 font-medium">New</th>
            <th class="px-4 py-3 font-medium">By</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($configLog)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No changes recorded.</td></tr>
          <?php else: foreach ($configLog as $c): ?>
            <tr>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($c['created_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($configKeys[$c['setting_key'] ?? '']['label'] ?? ($c['setting_key'] ?? '')) ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($c['old_value'] ?? '') ?: '—' ?></td>
              <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($c['new_value'] ?? '') ?: '—' ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($c['changed_by'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <!-- ================= CONTINUITY / INFRA (honest status) ================= -->
    <div class="card p-4 bg-blue-50 border border-blue-200 text-xs text-blue-800">
      These are infrastructure-level concerns handled outside the application (by your host/ops team).
      This page reports their status honestly — it does not claim live control the app does not have.
    </div>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Area</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">Note</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($infra as $row): ?>
            <?php [$cls, $lbl] = $statusBadge($row['status'] ?? ''); ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($row['label'] ?? ($row['area'] ?? '')) ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($row['note'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
