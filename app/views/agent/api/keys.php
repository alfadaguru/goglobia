<?php
@$SECURE or die('Access Denied!');
$flash = $_SESSION['message'] ?? null; unset($_SESSION['message']);
?>
<div class="px-4 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">API Console</h1>
  <p class="text-sm text-slate-500 mb-5">Create a key per integration. Keys are shown once; we store only a hash. Restrict a key to specific IPs for safety.</p>

  <?php require views . 'agent/api/_nav.php'; ?>

  <?php if ($flash): ?>
    <div class="alert-<?= $flash['type']==='error'?'error':'success' ?> mb-4"><p><?= htmlspecialchars($flash['text']) ?></p></div>
  <?php endif; ?>

  <?php if (!empty($freshKey)): ?>
    <div class="alert-warning mb-6" x-data="{k:'<?= htmlspecialchars($freshKey, ENT_QUOTES) ?>'}">
      <div class="flex-1">
        <p class="font-semibold mb-1">Your new API key (shown once):</p>
        <div class="flex items-center gap-2">
          <code class="bg-white/70 px-3 py-2 rounded-lg text-sm break-all flex-1" x-text="k"></code>
          <button type="button" class="btn btn-sm" @click="navigator.clipboard.writeText(k)">Copy</button>
        </div>
        <p class="text-xs mt-2">Store it securely now — it cannot be shown again.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($canMint === ''): ?>
    <div class="alert-info mb-6"><p>You can create keys once your API access is granted. Head to the <a class="text-violet-700 underline" href="<?=root?>agent/api">Overview</a> to request access.</p></div>
  <?php elseif ($canManage): ?>
    <div class="section mb-6">
      <div class="section-header"><h2>Create a key</h2></div>
      <form method="POST" action="<?=root?>api-access/generate" class="p-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="grid md:grid-cols-3 gap-3">
          <div class="form-control">
            <label>Environment</label>
            <select class="input" name="env">
              <option value="sandbox">Sandbox</option>
              <?php if ($canMint === 'live'): ?><option value="live">Live</option><?php endif; ?>
            </select>
          </div>
          <div class="form-control"><label>Label</label><input class="input" name="label" placeholder="e.g. Production server" maxlength="255"></div>
          <div class="form-control"><label>IP allow-list (optional)</label><input class="input" name="ip_allowlist" placeholder="Comma-separated IPs, blank = any"></div>
        </div>
        <div class="mt-4"><button class="btn">Generate key</button></div>
      </form>
    </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-header"><h2>Your keys</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Key</th><th>Env</th><th>Label</th><th>IPs</th><th>Status</th><th>Last used</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($keys)): ?>
            <tr><td colspan="7" class="text-slate-400">No API keys yet.</td></tr>
          <?php else: foreach ($keys as $k):
            $active = (string)$k['status'] === 'active';
          ?>
            <tr>
              <td><code class="text-xs"><?= htmlspecialchars($k['key_prefix']) ?>…</code></td>
              <td><span class="badge <?= ($k['env']??'sandbox')==='live'?'badge-success':'badge-info' ?>"><?= htmlspecialchars($k['env'] ?? 'sandbox') ?></span></td>
              <td><?= htmlspecialchars($k['label'] ?? '') ?: '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($k['ip_allowlist'] ?? '') ?: 'Any' ?></td>
              <td><?php if($active): ?><span class="badge badge-success">Active</span><?php else: ?><span class="badge badge-error">Revoked</span><?php endif; ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($k['last_used_at'] ?? '') ?: 'Never' ?></td>
              <td>
                <?php if ($active && $canManage): ?>
                <form method="POST" action="<?=root?>api-access/revoke" onsubmit="return confirm('Revoke this key? Apps using it stop working immediately.');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                  <button class="btn btn-sm rose">Revoke</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
