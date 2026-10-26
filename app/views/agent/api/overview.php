<?php
@$SECURE or die('Access Denied!');
$statusLabels = [
    'none'=>'Not started','requested'=>'Under review','sandbox'=>'Sandbox active',
    'certifying'=>'Certifying','live'=>'Live','rejected'=>'Rejected','suspended'=>'Suspended',
];
$statusBadge = [
    'none'=>'badge-gray','requested'=>'badge-warning','sandbox'=>'badge-info',
    'certifying'=>'badge-warning','live'=>'badge-success','rejected'=>'badge-error','suspended'=>'badge-error',
];
$flash = $_SESSION['message'] ?? null; unset($_SESSION['message']);
?>
<div class="px-4 py-6 max-w-6xl mx-auto">
  <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">API Console</h1>
      <p class="text-sm text-slate-500 mt-1">Integrate Goglobia services into your own systems — keys, services, webhooks and logs.</p>
    </div>
    <span class="badge <?= $statusBadge[$accessStatus] ?? 'badge-gray' ?>"><?= htmlspecialchars($statusLabels[$accessStatus] ?? $accessStatus) ?></span>
  </div>

  <?php require views . 'agent/api/_nav.php'; ?>

  <?php if ($flash): ?>
    <div class="alert-<?= $flash['type']==='error'?'error':'success' ?> mb-4"><p><?= htmlspecialchars($flash['text']) ?></p></div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="card border p-4">
      <div class="text-xs text-slate-500 uppercase tracking-wide">Wallet (credits)</div>
      <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($walletBalance, 2) ?></div>
      <p class="text-xs text-slate-400 mt-1">API bookings are paid from this.</p>
    </div>
    <div class="card border p-4">
      <div class="text-xs text-slate-500 uppercase tracking-wide">Active keys</div>
      <div class="text-2xl font-bold text-slate-900 mt-1"><?= (int)$activeKeys ?></div>
      <p class="text-xs text-slate-400 mt-1"><a class="text-violet-600" href="<?=root?>agent/api/keys">Manage keys →</a></p>
    </div>
    <div class="card border p-4">
      <div class="text-xs text-slate-500 uppercase tracking-wide">Services live</div>
      <div class="text-2xl font-bold text-slate-900 mt-1"><?= (int)$liveCount ?><span class="text-sm text-slate-400 font-normal"> / <?= count($allSvcs) ?></span></div>
      <p class="text-xs text-slate-400 mt-1"><?= (int)$sandboxCount ?> in sandbox</p>
    </div>
    <div class="card border p-4">
      <div class="text-xs text-slate-500 uppercase tracking-wide">Requests logged</div>
      <div class="text-2xl font-bold text-slate-900 mt-1"><?= (int)$usageCount ?></div>
      <p class="text-xs text-slate-400 mt-1"><a class="text-violet-600" href="<?=root?>agent/api/logs">View logs →</a></p>
    </div>
  </div>

  <!-- ACCESS STATE + NEXT STEP -->
  <div class="section mb-6">
    <div class="section-header"><h2>Access status</h2></div>
    <div class="p-4">
      <?php if ($accessStatus === 'none' || $accessStatus === 'rejected'): ?>
        <p class="text-sm text-slate-600 mb-3">The API is a paid, certified integration. Request access to get started — we'll reach out, then you build in sandbox before going live.</p>
        <a href="<?=root?>api-access" class="btn">Request API access</a>
      <?php elseif ($accessStatus === 'requested'): ?>
        <p class="text-sm text-slate-600">Your access request is <strong>under review</strong>. Our team will reach out by email. Once you're in, request the individual services you need on the <a class="text-violet-600" href="<?=root?>agent/api/services">Services</a> tab.</p>
      <?php elseif ($accessStatus === 'suspended'): ?>
        <p class="text-sm text-rose-600">Your API access is <strong>suspended</strong>. Please contact support.</p>
      <?php else: ?>
        <p class="text-sm text-slate-600">You're in (<strong><?= htmlspecialchars($statusLabels[$accessStatus]) ?></strong>). Next: enable the services you need under <a class="text-violet-600" href="<?=root?>agent/api/services">Services</a>, create a key under <a class="text-violet-600" href="<?=root?>agent/api/keys">Keys</a>, and point a <a class="text-violet-600" href="<?=root?>agent/api/webhooks">Webhook</a> at your system.</p>
      <?php endif; ?>
      <?php if (empty($agentApiHost)): ?>
        <div class="alert-info mt-4"><p>The API host is not configured yet. You can set everything up now; calls activate once your account manager enables the host.</p></div>
      <?php else: ?>
        <div class="alert-info mt-4"><p>Base URL: <code>https://<?= htmlspecialchars($agentApiHost) ?>/api</code> — send your key in the <code>X-Agent-Key</code> header.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
