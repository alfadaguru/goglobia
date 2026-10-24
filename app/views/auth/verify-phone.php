<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
  <div class="max-w-md w-full space-y-6">

    <div class="text-center">
      <span class="material-symbols-outlined text-4xl text-primary">sms</span>
      <h1 class="mt-2 text-2xl font-bold text-gray-900"><?= T::verify_phone ?? 'Verify your phone' ?></h1>
      <p class="mt-1 text-sm text-gray-600">
        We sent a 6-digit code by WhatsApp to <strong><?= htmlspecialchars($maskedPhone ?? '') ?></strong>.
        Enter it below to confirm your number.
      </p>
    </div>

    <?php if (!empty($_SESSION['verify_phone_error'])): ?>
      <div class="alert-error">
        <span class="material-symbols-outlined">error</span>
        <p class="text-sm"><?= htmlspecialchars($_SESSION['verify_phone_error']) ?></p>
      </div>
      <?php unset($_SESSION['verify_phone_error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['verify_phone_success'])): ?>
      <div class="alert-success">
        <span class="material-symbols-outlined">check_circle</span>
        <p class="text-sm"><?= htmlspecialchars($_SESSION['verify_phone_success']) ?></p>
      </div>
      <?php unset($_SESSION['verify_phone_success']); ?>
    <?php endif; ?>

    <form method="POST" action="<?= root ?>verify-phone" class="space-y-4">
      <?= CSRF::tokenField() ?>
      <div class="form-control">
        <label for="otp" class="block text-sm font-medium text-gray-700 mb-1">Verification code</label>
        <input type="text" id="otp" name="otp" required
          inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
          class="input text-center tracking-[0.5em] text-lg"
          placeholder="______">
      </div>
      <button type="submit" class="btn emerald w-full">Verify phone</button>
    </form>

    <form method="POST" action="<?= root ?>verify-phone/resend" class="text-center">
      <?= CSRF::tokenField() ?>
      <button type="submit" class="text-sm text-primary hover:underline">Didn't get a code? Resend</button>
    </form>

    <p class="text-center text-xs text-gray-500">
      <a href="<?= root ?><?= isset($_SESSION['user_id']) ? 'dashboard' : 'login' ?>" class="hover:underline">Skip for now</a>
    </p>

  </div>
</div>
