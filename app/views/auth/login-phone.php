<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
  <div class="max-w-md w-full space-y-6">

    <div class="text-center">
      <span class="material-symbols-outlined text-4xl text-primary">forum</span>
      <h1 class="mt-2 text-2xl font-bold text-gray-900">Log in with your phone</h1>
      <p class="mt-1 text-sm text-gray-600">We'll send a one-time code to your WhatsApp.</p>
    </div>

    <?php if (!empty($_SESSION['login_error'])): ?>
      <div class="alert-error">
        <span class="material-symbols-outlined">error</span>
        <p class="text-sm">
          <?php
            $le = $_SESSION['login_error'];
            if ($le === 'empty') { echo 'Please enter your phone number.'; }
            elseif ($le === 'ambiguous_phone') { echo 'That number is linked to more than one account. Please log in with your email.'; }
            elseif ($le === 'locked') { echo 'Too many attempts. Please try again later.'; }
            elseif ($le === 'csrf') { echo 'Security check failed. Please try again.'; }
            else { echo htmlspecialchars($_SESSION['verify_phone_error'] ?? 'Could not log you in. Please try again.'); }
          ?>
        </p>
      </div>
      <?php unset($_SESSION['login_error'], $_SESSION['verify_phone_error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['verify_phone_success'])): ?>
      <div class="alert-success">
        <span class="material-symbols-outlined">check_circle</span>
        <p class="text-sm"><?= htmlspecialchars($_SESSION['verify_phone_success']) ?></p>
      </div>
      <?php unset($_SESSION['verify_phone_success']); ?>
    <?php endif; ?>

    <?php if (($phoneStep ?? 'enter') !== 'code'): ?>
      <!-- Step 1: enter phone -->
      <form method="POST" action="<?= root ?>login/phone" class="space-y-4">
        <?= CSRF::tokenField() ?>
        <div class="form-control">
          <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone number</label>
          <div class="input-group">
            <span class="input-icon-left material-symbols-outlined">call</span>
            <input type="tel" id="phone" name="phone" required
              class="input-with-icon"
              placeholder="e.g. +234 803 000 0000"
              value="<?= htmlspecialchars($_SESSION['phone_login_identifier'] ?? '') ?>">
          </div>
        </div>
        <button type="submit" class="btn emerald w-full">Send code</button>
      </form>
    <?php else: ?>
      <!-- Step 2: enter the OTP -->
      <form method="POST" action="<?= root ?>login/phone/verify" class="space-y-4">
        <?= CSRF::tokenField() ?>
        <div class="form-control">
          <label for="otp" class="block text-sm font-medium text-gray-700 mb-1">Enter the 6-digit code</label>
          <input type="text" id="otp" name="otp" required
            inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
            class="input text-center tracking-[0.5em] text-lg" placeholder="______">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-600">
          <input type="checkbox" name="remember_me" value="1" class="rounded"> Keep me signed in
        </label>
        <button type="submit" class="btn emerald w-full">Verify &amp; log in</button>
      </form>
      <form method="POST" action="<?= root ?>login/phone" class="text-center">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="phone" value="<?= htmlspecialchars($_SESSION['phone_login_identifier'] ?? '') ?>">
        <button type="submit" class="text-sm text-primary hover:underline">Resend code</button>
      </form>
    <?php endif; ?>

    <p class="text-center text-xs text-gray-500">
      <a href="<?= root ?>login" class="hover:underline">Log in with email &amp; password instead</a>
    </p>

  </div>
</div>
<?php $_SESSION['phone_login_step'] = ($phoneStep ?? 'enter') === 'code' ? 'code' : 'enter'; ?>
