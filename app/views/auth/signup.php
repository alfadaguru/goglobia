<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
  <div class="max-w-lg w-full space-y-8">

    <!-- Error Messages -->
    <?php if (isset($_SESSION['signup_error'])): ?>
      <div class="alert-error">
        <span class="material-symbols-outlined">error</span>
        <p class="text-sm">
          <?php if ($_SESSION['signup_error'] === 'empty'): ?>
            <?= T::error_empty_fields ?>
          <?php elseif ($_SESSION['signup_error'] === 'invalid_email'): ?>
            <?= T::error_invalid_email ?>
          <?php elseif ($_SESSION['signup_error'] === 'email_exists'): ?>
            <?= T::error_email_exists ?>
          <?php elseif ($_SESSION['signup_error'] === 'password_mismatch'): ?>
            <?= T::error_password_mismatch ?>
          <?php elseif ($_SESSION['signup_error'] === 'password_weak'): ?>
            <?= T::error_password_weak ?>
          <?php elseif ($_SESSION['signup_error'] === 'terms_required'): ?>
            <?= T::error_terms_required ?>
          <?php elseif ($_SESSION['signup_error'] === 'csrf'): ?>
            <?= T::error_csrf_token ?>
          <?php elseif ($_SESSION['signup_error'] === 'captcha_missing'): ?>
            Security check missing. Please try again.
          <?php elseif ($_SESSION['signup_error'] === 'captcha_expired'): ?>
            Security check expired. Please refresh and try again.
          <?php elseif ($_SESSION['signup_error'] === 'captcha_invalid'): ?>
            Please enter a valid number for the security check.
          <?php elseif ($_SESSION['signup_error'] === 'captcha_wrong'): ?>
            Incorrect answer to security question. Please try again.
          <?php elseif ($_SESSION['signup_error'] === 'invalid_request'): ?>
            Invalid request. Please try again.
          <?php else: ?>
            <?= T::error_registration_failed ?>
          <?php endif; ?>
        </p>
      </div>
      <?php unset($_SESSION['signup_error']); ?>
    <?php endif; ?>

    <!-- Success Messages -->
    <?php if (isset($_SESSION['signup_success'])): ?>
      <div class="alert-success">
        <span class="material-symbols-outlined">check_circle</span>
        <p class="text-sm">
          <?php if ($_SESSION['signup_success'] === 'registered'): ?>
            <?= T::success_registration ?>
          <?php else: ?>
            <?= T::success_account_created ?>
          <?php endif; ?>
        </p>
      </div>
      <?php unset($_SESSION['signup_success']); ?>
    <?php endif; ?>

    <!-- Agent Signup Notice -->
    <?php if (isset($isAgent) && $isAgent): ?>
      <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 shadow-sm">
        <div class="flex items-start gap-3">
          <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-blue-600 text-2xl">business_center</span>
          </div>
          <div class="flex-1">
            <h3 class="text-lg font-bold text-gray-900 mb-1"><?= T::agent_registration ?? 'Agent Registration' ?></h3>
            <p class="text-sm text-gray-700 leading-relaxed">
              <?= T::agent_signup_notice ?? 'You are registering as a travel agent. After approval, you will have access to wholesale rates and agent dashboard.' ?>
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Signup Card -->
    <div class="card max-w-lg p-5">
      <div class=" text-center block h-[150px] py-5">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="material-symbols-outlined text-2xl text-green-600">person_add</span>
        </div>
        <h2 class="text-2xl font-bold text-gray-900"><?= T::create_account ?></h2>
        <p class="text-gray-600 text-sm"><?= T::signup_description ?></p>
      </div>

      <div class="card-body">
        <!-- Form -->
        <form action="<?=root?>signup" method="POST"
          class="space-y-6"
          x-data="{
            showPassword: false,
            showConfirmPassword: false,
            password: '',
            confirmPassword: '',
            passwordsMatch: true,
            isSubmitting: false,
            passwordStrength: 0,
            passwordStrengthText: '<?= T::enter_password ?>',
            passwordStrengthColor: 'text-gray-500',
            userInteracted: false,
            checkPasswordStrength(value) {
              // Only proceed if user has actually typed or interacted
              if (!this.userInteracted) {
                return;
              }

              // If empty or just whitespace, show default state
              if (!value || value.trim().length === 0) {
                this.passwordStrength = 0;
                this.passwordStrengthText = '<?= T::enter_password ?>';
                this.passwordStrengthColor = 'text-gray-500';
                return;
              }

              let strength = 0;
              if (value.length >= 6) strength++;
              if (value.length >= 8) strength++;
              if (/[a-z]/.test(value) && /[A-Z]/.test(value)) strength++;
              if (/[0-9]/.test(value)) strength++;
              if (/[^a-zA-Z0-9]/.test(value)) strength++;

              this.passwordStrength = strength;
              if (strength <= 2) {
                this.passwordStrengthText = '<?= T::weak ?>';
                this.passwordStrengthColor = 'text-red-600';
              } else if (strength === 3) {
                this.passwordStrengthText = '<?= T::fair ?>';
                this.passwordStrengthColor = 'text-yellow-600';
              } else if (strength === 4) {
                this.passwordStrengthText = '<?= T::good ?>';
                this.passwordStrengthColor = 'text-blue-600';
              } else {
                this.passwordStrengthText = '<?= T::strong ?>';
                this.passwordStrengthColor = 'text-green-600';
              }
            },
            checkPasswordMatch() {
              const pwd = document.getElementById('password')?.value || '';
              const cpwd = document.getElementById('confirm_password')?.value || '';
              this.passwordsMatch = pwd === cpwd || cpwd === '';
            }
          }"
          @submit="isSubmitting = true">

          <?= CSRF::tokenField() ?>

          <!-- Hidden User Type Field -->
          <input type="hidden" name="user_type" value="<?= isset($isAgent) && $isAgent ? 'agent' : 'customer' ?>">

          <!-- Name Fields -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1"><?= T::first_name ?> *</label>
              <input type="text" id="first_name" name="first_name" required
                class="input"
                placeholder="<?= T::first_name ?>"
                value="<?= htmlspecialchars($_SESSION['form_data']['first_name'] ?? '') ?>">
            </div>

            <div class="form-control">
              <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1"><?= T::last_name ?> *</label>
              <input type="text" id="last_name" name="last_name" required
                class="input"
                placeholder="<?= T::last_name ?>"
                value="<?= htmlspecialchars($_SESSION['form_data']['last_name'] ?? '') ?>">
            </div>
          </div>

          <!-- Email -->
          <div class="form-control">
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1"><?= T::email_address ?> *</label>
            <div class="input-group">
              <span class="input-icon-left material-symbols-outlined">email</span>
              <input type="email" id="email" name="email" required
                class="input-with-icon"
                placeholder="<?= T::email_address ?>"
                value="<?= htmlspecialchars($_SESSION['form_data']['email'] ?? '') ?>">
            </div>
          </div>

          <!-- Password -->
          <div class="form-control">
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1"><?= T::password ?> *</label>
            <div class="input-group">
              <input :type="showPassword ? 'text' : 'password'" id="password" name="password"
                @input="userInteracted = true; checkPasswordStrength($event.target.value);"
                @keydown="userInteracted = true;"
                @paste="userInteracted = true;"
                required minlength="6"
                class="input-with-icon-right"
                placeholder="<?= T::password ?>"
                autocomplete="new-password">
              <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
            </div>

            <!-- Password Strength Indicator - Always Visible -->
            <div class="mt-2">
              <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600"><?= T::password_strength ?>:</p>
                <p class="text-xs font-semibold" :class="passwordStrengthColor" x-text="passwordStrengthText"></p>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5">
                <div class="h-1.5 rounded-full transition-all duration-300"
                     :class="{
                       'bg-gray-300': passwordStrength === 0,
                       'bg-red-500': passwordStrength > 0 && passwordStrength <= 2,
                       'bg-yellow-500': passwordStrength === 3,
                       'bg-blue-500': passwordStrength === 4,
                       'bg-green-500': passwordStrength === 5
                     }"
                     :style="'width: ' + (passwordStrength === 0 ? 0 : passwordStrength * 20) + '%'"></div>
              </div>
            </div>

            <p class="mt-1 text-xs text-gray-500"><?= T::password_requirements ?></p>
          </div>

          <!-- Confirm Password -->
          <div class="form-control">
            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1"><?= T::confirm_password ?> *</label>
            <div class="input-group">
              <input :type="showConfirmPassword ? 'text' : 'password'" id="confirm_password" name="confirm_password"
                @input="checkPasswordMatch()"
                required minlength="6"
                :class="!passwordsMatch ? 'border-red-300 focus-visible:border-red-500' : ''"
                class="input-with-icon-right"
                placeholder="<?= T::confirm_password ?>"
                autocomplete="new-password">
              <span @click="showConfirmPassword = !showConfirmPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showConfirmPassword ? 'visibility_off' : 'visibility'"></span>
            </div>
            <p class="mt-1 text-xs text-red-600" x-show="!passwordsMatch && document.getElementById('confirm_password')?.value !== ''"><?= T::error_password_mismatch ?></p>
          </div>

          <!-- CAPTCHA Security Check -->
          <?php
          $captchaData = $_SESSION['captcha_data'] ?? Captcha::generate();
          echo Captcha::renderField($captchaData);
          ?>

          <!-- Terms Checkbox -->
          <div class="form-control">
            <div class="flex items-start gap-3">
              <div class="checkbox-container mt-0.5">
                <input id="terms" name="terms" type="checkbox" required class="checkbox-input">
                <div class="checkbox-custom">
                  <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
              </div>
              <label for="terms" class="cursor-pointer text-sm text-gray-700 leading-relaxed flex-wrap">
                <?= T::i_agree_to_the ?>
                <a href="<?=root?>page/terms-of-use" class="text-blue-600 hover:underline font-medium"><?= T::terms_of_service ?></a>
                <?= T::and ?>
                <a href="<?=root?>page/privacy-policy" class="text-blue-600 hover:underline font-medium"><?= T::privacy_policy ?></a>
              </label>
            </div>
          </div>

          <!-- Submit Button -->
          <div class="card-actions">
            <button type="submit"
              class="btn emerald w-full relative"
              :disabled="isSubmitting">
              <span class="flex items-center justify-center gap-2" x-show="!isSubmitting">
                <span class="material-symbols-outlined text-sm">person_add</span>
                <?= T::create_account ?>
              </span>
              <span class="flex items-center justify-center gap-2" x-show="isSubmitting" x-cloak>
                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <?= T::creating_account ?>
              </span>
            </button>
          </div>
        </form>

        <div class="mt-6 pt-3 border-t border-gray-200 text-center">
          <p class="text-sm text-gray-600">
            <?= T::already_have_account ?>
            <a href="<?=root?>login" class="text-blue-600 hover:underline"><?= T::login ?></a>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(() => $('#first_name').focus());
</script>

<style>[x-cloak]{display:none!important}</style>

<?php
if (isset($_SESSION['form_data'])) unset($_SESSION['form_data']);
?>