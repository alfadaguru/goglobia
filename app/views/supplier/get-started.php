<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier get-started wizard (inc S13). Expects $onboarding + $supplier in scope.
$ob = $onboarding ?? null;
$name = trim((string) (($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? '')));
$complete = is_array($ob) && !empty($ob['complete']);
?>

<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a>
      <span class="mx-1">/</span><span class="text-gray-700">Get started</span>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900">Welcome<?= $name !== '' ? ', ' . htmlspecialchars($name) : '' ?></h1>
    <p class="text-sm text-gray-600">Follow these steps to get your first property selling on the marketplace.</p>
  </div>

  <?php if ($complete): ?>
    <div class="card p-8 text-center">
      <span class="material-symbols-outlined text-5xl text-green-500">task_alt</span>
      <h2 class="mt-2 text-lg font-bold text-gray-900">You're all set!</h2>
      <p class="mt-1 text-sm text-gray-600">Your property is live. Manage it any time from your hotels and reservations.</p>
      <div class="mt-4 flex items-center justify-center gap-3">
        <a href="<?= root ?>supplier/stays" class="btn emerald text-sm">My hotels</a>
        <a href="<?= root ?>supplier/reservations" class="btn secondary text-sm">Reservations</a>
      </div>
    </div>
  <?php else: ?>
    <?php require views . "supplier/_onboarding.php"; // full (non-compact) checklist ?>

    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Helpful links</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <a href="<?= root ?>supplier/stays" class="flex items-center gap-2 text-gray-700 hover:text-violet-700">
          <span class="material-symbols-outlined text-violet-600 text-base">hotel</span> My hotels
        </a>
        <a href="<?= root ?>supplier/reservations" class="flex items-center gap-2 text-gray-700 hover:text-violet-700">
          <span class="material-symbols-outlined text-violet-600 text-base">receipt_long</span> Reservations
        </a>
        <a href="<?= root ?>supplier/roles" class="flex items-center gap-2 text-gray-700 hover:text-violet-700">
          <span class="material-symbols-outlined text-violet-600 text-base">badge</span> Roles &amp; permissions
        </a>
        <a href="<?= root ?>supplier/staff" class="flex items-center gap-2 text-gray-700 hover:text-violet-700">
          <span class="material-symbols-outlined text-violet-600 text-base">group</span> Staff
        </a>
      </div>
    </div>
  <?php endif; ?>
</div>
