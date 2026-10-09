<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 bg-gray-50">
  <div class="max-w-md w-full space-y-6">

    <?php if (!empty($_SESSION['message'])): ?>
      <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
      <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
        <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
        <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
      </div>
      <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if (empty($invite) || !is_array($invite)): ?>
      <div class="card p-6 text-center">
        <span class="material-symbols-outlined text-5xl text-red-300">link_off</span>
        <h1 class="text-xl font-bold text-gray-900 mt-2">Invitation not valid</h1>
        <p class="text-sm text-gray-600 mt-1">This invitation link is invalid or has expired. Please ask the supplier to send a new one.</p>
        <a href="<?= root ?>login" class="btn secondary mt-4">Go to sign in</a>
      </div>
    <?php else: ?>
      <div class="card p-6">
        <div class="text-center mb-5">
          <div class="w-14 h-14 bg-violet-100 rounded-full flex items-center justify-center mx-auto mb-3">
            <span class="material-symbols-outlined text-2xl text-violet-600">group_add</span>
          </div>
          <h1 class="text-xl font-bold text-gray-900">Accept your invitation</h1>
          <p class="text-sm text-gray-600 mt-1">Set a password for <strong><?= htmlspecialchars($invite['invited_email'] ?? '') ?></strong> to join the team.</p>
        </div>

        <form action="<?= root ?>supplier/staff/accept" method="POST" class="space-y-4">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">

          <div class="grid grid-cols-2 gap-3">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">First name</label>
              <input type="text" name="first_name" required class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Last name</label>
              <input type="text" name="last_name" required class="input">
            </div>
          </div>

          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" required minlength="6" class="input" autocomplete="new-password">
            <p class="mt-1 text-xs text-gray-500">At least 6 characters.</p>
          </div>
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm password</label>
            <input type="password" name="confirm_password" required minlength="6" class="input" autocomplete="new-password">
          </div>

          <button type="submit" class="btn emerald w-full">
            <span class="flex items-center justify-center gap-1.5"><span class="material-symbols-outlined text-sm">check</span> Accept &amp; create account</span>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
