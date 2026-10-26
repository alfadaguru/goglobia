<?php
@$SECURE or die('Access Denied!');
$flash = $_SESSION['message'] ?? null; unset($_SESSION['message']);
$lifeLabel = [
    'none'=>'Not requested','requested'=>'Requested','sandbox'=>'Sandbox',
    'certifying'=>'Certifying','live'=>'Live','rejected'=>'Rejected','suspended'=>'Suspended',
];
$lifeBadge = [
    'none'=>'badge-gray','requested'=>'badge-warning','sandbox'=>'badge-info',
    'certifying'=>'badge-warning','live'=>'badge-success','rejected'=>'badge-error','suspended'=>'badge-error',
];
$svcIcons = [
    'flights'=>'flight','stays'=>'hotel','cars'=>'directions_car','tours'=>'tour','visa'=>'badge',
    'umrah'=>'mosque','esim'=>'sim_card','bus'=>'directions_bus','ferries'=>'directions_boat','rail'=>'train',
];
$gatedIn = (agent_api_can_mint($db, $owner) !== '');
?>
<div class="px-4 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">API Console</h1>
  <p class="text-sm text-slate-500 mb-5">Each service is certified separately: request it, build against sandbox, then request live certification.</p>

  <?php require views . 'agent/api/_nav.php'; ?>

  <?php if ($flash): ?>
    <div class="alert-<?= $flash['type']==='error'?'error':'success' ?> mb-4"><p><?= htmlspecialchars($flash['text']) ?></p></div>
  <?php endif; ?>

  <?php if (!$gatedIn): ?>
    <div class="alert-warning mb-6"><p>First, <a class="text-violet-700 underline" href="<?=root?>api-access">request API access</a> for your agency. Once your account manager grants you in, you can request individual services here.</p></div>
  <?php endif; ?>

  <div class="grid md:grid-cols-2 gap-4">
    <?php foreach ($allSvcs as $svc => $label):
      $row  = $svcRows[$svc] ?? ['lifecycle'=>'none','fee_type'=>'percentage','fee_value'=>0,'enabled'=>0];
      $life = (string) ($row['lifecycle'] ?? 'none');
      $feeType = (string) ($row['fee_type'] ?? 'percentage');
      $feeVal  = (float) ($row['fee_value'] ?? 0);
      $feeTxt  = $feeType === 'flat' ? number_format($feeVal,2).' flat' : rtrim(rtrim((string)$feeVal,'0'),'.').'%';
    ?>
      <div class="card border p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($svcIcons[$svc] ?? 'api') ?></span>
            <div>
              <div class="font-semibold text-slate-900"><?= htmlspecialchars($label) ?></div>
              <div class="text-xs text-slate-400">Per-booking fee: <?= htmlspecialchars($life==='none'||$life==='requested'?'set on approval':$feeTxt) ?></div>
            </div>
          </div>
          <span class="badge <?= $lifeBadge[$life] ?? 'badge-gray' ?>"><?= htmlspecialchars($lifeLabel[$life] ?? $life) ?></span>
        </div>

        <?php if ($canManage): ?>
        <div class="mt-4 pt-3 border-t border-slate-100">
          <?php if ($life === 'none' || $life === 'rejected'): ?>
            <form method="POST" action="<?=root?>agent/api/service/request" class="space-y-2">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="service" value="<?= htmlspecialchars($svc) ?>">
              <input class="input input-sm w-full" name="use_case" placeholder="How will you use <?= htmlspecialchars($label) ?>? (optional)" maxlength="2000">
              <button class="btn btn-sm" <?= $gatedIn?'':'disabled' ?>>Request <?= htmlspecialchars($label) ?></button>
            </form>
          <?php elseif ($life === 'requested'): ?>
            <p class="text-sm text-slate-500">Requested — awaiting sandbox access from your account manager.</p>
          <?php elseif ($life === 'sandbox'): ?>
            <p class="text-sm text-slate-600 mb-2">✅ Sandbox is active. Build and test, then request live certification.</p>
            <form method="POST" action="<?=root?>agent/api/service/request-live">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="service" value="<?= htmlspecialchars($svc) ?>">
              <button class="btn btn-sm">Request live certification</button>
            </form>
          <?php elseif ($life === 'certifying'): ?>
            <p class="text-sm text-amber-600">Certifying — we're reviewing your sandbox integration for <?= htmlspecialchars($label) ?>.</p>
          <?php elseif ($life === 'live'): ?>
            <p class="text-sm text-emerald-600">✅ Live. Your live keys can call <?= htmlspecialchars($label) ?>.</p>
          <?php elseif ($life === 'suspended'): ?>
            <p class="text-sm text-rose-600">Suspended — contact support.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
