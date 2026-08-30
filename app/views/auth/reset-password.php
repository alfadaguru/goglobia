<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
    <div class="max-w-md w-full space-y-8">
        <!-- Error Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert-error">
                <span class="material-symbols-outlined">error</span>
                <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Reset Password Form -->
        <div class="card">
            <div class="card-body">
                <div class="my-4 text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-2xl text-green-600">key</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= T::reset_password ?></h2>
                    <p class="text-gray-600"><?= T::set_new_password ?></p>
                </div>

                <form action="<?=root?>reset-password" method="POST" class="space-y-6 reset-password-form" id="reset-password-form"
                      x-data="{
                        showPassword: false,
                        showConfirmPassword: false,
                        isSubmitting: false,
                        passwordStrength: 0,
                        passwordStrengthText: '<?= T::enter_password ?>',
                        passwordStrengthColor: 'text-gray-500',
                        userInteracted: false,
                        checkPasswordStrength(value) {
                          if (!this.userInteracted) return;

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
                          return pwd === cpwd || cpwd === '';
                        }
                      }"
                      @submit="isSubmitting = true">

                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">

                    <div class="form-control">
                        <label for="password" class="text-sm font-medium text-gray-700 mb-2"><?= T::password ?> *</label>
                        <div class="input-group">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                id="password"
                                name="password"
                                required
                                class="input-with-icon-right"
                                placeholder="<?= T::password ?>"
                                minlength="6"
                                @input="userInteracted = true; checkPasswordStrength($event.target.value);"
                                @keydown="userInteracted = true;"
                                @paste="userInteracted = true;"
                                autocomplete="new-password"
                            >
                            <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                        </div>

                        <!-- Password Strength Indicator -->
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

                        <p class="mt-2 text-xs text-gray-500"><?= T::password_requirements ?></p>
                    </div>

                    <div class="form-control">
                        <label for="confirm_password" class="text-sm font-medium text-gray-700 mb-2"><?= T::confirm_password ?> *</label>
                        <div class="input-group">
                            <input
                                :type="showConfirmPassword ? 'text' : 'password'"
                                id="confirm_password"
                                name="confirm_password"
                                required
                                class="input-with-icon-right"
                                placeholder="<?= T::confirm_password ?>"
                                minlength="6"
                                @input="checkPasswordMatch()"
                                autocomplete="new-password"
                                :class="!checkPasswordMatch() && document.getElementById('confirm_password')?.value !== '' ? 'border-red-300' : ''"
                            >
                            <span @click="showConfirmPassword = !showConfirmPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showConfirmPassword ? 'visibility_off' : 'visibility'"></span>
                        </div>
                        <p class="mt-2 text-xs text-red-600" x-show="!checkPasswordMatch() && document.getElementById('confirm_password')?.value !== ''" x-cloak>
                            <?= T::error_password_mismatch ?>
                        </p>
                    </div>

                    <div class="card-actions">
                        <button type="submit" class="btn emerald w-full" :disabled="isSubmitting">
                            <span class="flex items-center justify-center gap-2" x-show="!isSubmitting">
                                <span class="material-symbols-outlined text-sm">lock_reset</span>
                                <?= T::update_password ?>
                            </span>
                            <span class="flex items-center justify-center gap-2" x-show="isSubmitting" x-cloak>
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <?= T::updating_password ?>
                            </span>
                        </button>
                    </div>
                </form>

                <div class="mt-4 pt-3 border-t border-gray-200 text-center space-y-4">
                    <p class="text-sm text-gray-600"><?= T::remember_your_password ?></p>
                    <a href="<?=root?>login" class="btn outline w-full justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">arrow_back</span>
                        <?= T::back_to_login ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>[x-cloak]{display:none!important}</style>

<script>
// Focus on password field
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('password').focus();
});
</script>