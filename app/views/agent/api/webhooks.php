<?php
@$SECURE or die('Access Denied!');
$flash = $_SESSION['message'] ?? null; unset($_SESSION['message']);
?>
<div class="px-4 py-6 max-w-6xl mx-auto" x-data="{showForm:false}">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-1">
    <h1 class="text-2xl font-semibold text-slate-900">API Console</h1>
    <?php if ($canManage && $canMint !== ''): ?><button class="btn" @click="showForm=!showForm"><span class="material-symbols-outlined">add</span><span>New webhook</span></button><?php endif; ?>
  </div>
  <p class="text-sm text-slate-500 mb-5">We POST events to your HTTPS endpoint, signed with HMAC-SHA256 in <code>X-Goglobia-Signature</code>. Verify it with your signing secret.</p>

  <?php require views . 'agent/api/_nav.php'; ?>

  <?php if ($flash): ?>
    <div class="alert-<?= $flash['type']==='error'?'error':'success' ?> mb-4"><p><?= htmlspecialchars($flash['text']) ?></p></div>
  <?php endif; ?>

  <?php if (!empty($freshSecret)): ?>
    <div class="alert-warning mb-6" x-data="{s:'<?= htmlspecialchars($freshSecret, ENT_QUOTES) ?>'}">
      <div class="flex-1">
        <p class="font-semibold mb-1">Signing secret (shown once):</p>
        <div class="flex items-center gap-2">
          <code class="bg-white/70 px-3 py-2 rounded-lg text-sm break-all flex-1" x-text="s"></code>
          <button type="button" class="btn btn-sm" @click="navigator.clipboard.writeText(s)">Copy</button>
        </div>
        <p class="text-xs mt-2">Store it now — used to verify our signature. It cannot be shown again.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($canMint === ''): ?>
    <div class="alert-info mb-6"><p>Webhooks become available once your API access is approved. Head to the <a class="text-violet-700 underline" href="<?=root?>agent/api">Overview</a> to request access — we'll reach out, then you can register endpoints here.</p></div>
  <?php endif; ?>

  <!-- NEW WEBHOOK FORM -->
  <?php if ($canManage && $canMint !== ''): ?>
  <div class="section mb-6" x-show="showForm" x-cloak>
    <div class="section-header"><h2>Add a webhook</h2></div>
    <form method="POST" action="<?=root?>agent/api/webhooks/save" class="p-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <div class="grid md:grid-cols-3 gap-3">
        <div class="form-control md:col-span-2"><label>Endpoint URL (https)</label><input class="input" name="url" placeholder="https://example.com/goglobia/webhook" required></div>
        <div class="form-control"><label>Environment</label><select class="input" name="env"><option value="sandbox">Sandbox</option><?php if ($canMint === 'live'): ?><option value="live">Live</option><?php endif; ?></select></div>
      </div>
      <div class="form-control mt-3">
        <label>Events (none selected = all)</label>
        <div class="flex flex-wrap gap-3 mt-1">
          <?php foreach ($eventList as $ev): ?>
            <label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="events[]" value="<?= htmlspecialchars($ev) ?>"> <?= htmlspecialchars($ev) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt-4"><button class="btn">Create webhook</button></div>
    </form>
  </div>
  <?php endif; ?>

  <!-- EXISTING WEBHOOKS -->
  <div class="section mb-6">
    <div class="section-header"><h2>Your webhooks</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>URL</th><th>Env</th><th>Events</th><th>Last delivery</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($hooks)): ?>
            <tr><td colspan="6" class="text-slate-400">No webhooks yet.</td></tr>
          <?php else: foreach ($hooks as $h):
            $evs = trim((string)($h['events'] ?? '')); $evTxt = $evs === '' ? 'All events' : $evs;
          ?>
            <tr>
              <td class="text-xs break-all max-w-[260px]"><?= htmlspecialchars($h['url']) ?></td>
              <td><span class="badge <?= ($h['env']??'sandbox')==='live'?'badge-success':'badge-info' ?>"><?= htmlspecialchars($h['env'] ?? 'sandbox') ?></span></td>
              <td class="text-xs text-slate-500 max-w-[220px] break-words"><?= htmlspecialchars($evTxt) ?></td>
              <td class="text-xs text-slate-500">
                <?= htmlspecialchars($h['last_delivery_at'] ?? '') ?: 'Never' ?>
                <?php if (!empty($h['last_status_code'])): ?><span class="badge badge-sm <?= ((int)$h['last_status_code']>=200 && (int)$h['last_status_code']<300)?'badge-success':'badge-error' ?>"><?= (int)$h['last_status_code'] ?></span><?php endif; ?>
              </td>
              <td><span class="badge <?= ($h['status']??'active')==='active'?'badge-success':'badge-gray' ?>"><?= htmlspecialchars($h['status'] ?? 'active') ?></span></td>
              <td>
                <?php if ($canManage): $isActive = (string)($h['status'] ?? 'active') === 'active'; ?>
                <div class="flex flex-wrap gap-1.5">
                  <form method="POST" action="<?=root?>agent/api/webhooks/test">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-sm outline" title="Send a signed test event">Test</button>
                  </form>
                  <form method="POST" action="<?=root?>agent/api/webhooks/toggle">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-sm outline"><?= $isActive ? 'Pause' : 'Resume' ?></button>
                  </form>
                  <form method="POST" action="<?=root?>agent/api/webhooks/rotate" onsubmit="return confirm('Rotate the signing secret? The old secret stops verifying immediately.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-sm outline" title="Issue a new signing secret">Rotate secret</button>
                  </form>
                  <form method="POST" action="<?=root?>agent/api/webhooks/delete" onsubmit="return confirm('Delete this webhook?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-sm rose">Delete</button>
                  </form>
                </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RECENT DELIVERIES -->
  <div class="section">
    <div class="section-header"><h2>Recent deliveries</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Event</th><th>Webhook #</th><th>Code</th><th>Delivered</th><th>Attempt</th><th>When</th></tr></thead>
        <tbody>
          <?php if (empty($deliveries)): ?>
            <tr><td colspan="6" class="text-slate-400">No deliveries yet.</td></tr>
          <?php else: foreach ($deliveries as $d): ?>
            <tr>
              <td class="text-xs"><?= htmlspecialchars($d['event'] ?? '') ?></td>
              <td class="text-xs text-slate-500">#<?= (int)$d['webhook_id'] ?></td>
              <td><span class="badge badge-sm <?= ((int)$d['response_code']>=200 && (int)$d['response_code']<300)?'badge-success':'badge-error' ?>"><?= (int)$d['response_code'] ?></span></td>
              <td><?= !empty($d['delivered']) ? '✅' : '❌' ?></td>
              <td class="text-xs text-slate-500"><?= (int)$d['attempt'] ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($d['created_at'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
