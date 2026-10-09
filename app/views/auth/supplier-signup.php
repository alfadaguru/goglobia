<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
  <div class="max-w-lg w-full space-y-8">

    <!-- Error Messages -->
    <?php if (isset($_SESSION['supplier_signup_error'])): ?>
      <div class="alert-error">
        <span class="material-symbols-outlined">error</span>
        <p class="text-sm">
          <?php
          switch ($_SESSION['supplier_signup_error']) {
              case 'empty':            echo 'Please fill in all required fields.'; break;
              case 'invalid_email':    echo 'Please enter a valid email address.'; break;
              case 'email_exists':     echo 'An account with that email already exists.'; break;
              case 'password_mismatch':echo 'Passwords do not match.'; break;
              case 'password_weak':    echo 'Password must be at least 6 characters.'; break;
              case 'terms_required':   echo 'You must agree to the terms to continue.'; break;
              case 'no_service':       echo 'Please select at least one service you offer.'; break;
              case 'csrf':             echo 'Your session expired. Please try again.'; break;
              case 'captcha_missing':  echo 'Security check missing. Please try again.'; break;
              case 'captcha_expired':  echo 'Security check expired. Please refresh and try again.'; break;
              case 'captcha_invalid':  echo 'Please enter a valid number for the security check.'; break;
              case 'captcha_wrong':    echo 'Incorrect answer to the security question. Please try again.'; break;
              default:                 echo 'Registration could not be completed. Please try again.';
          }
          ?>
        </p>
      </div>
      <?php unset($_SESSION['supplier_signup_error']); ?>
    <?php endif; ?>

    <!-- Supplier notice -->
    <div class="bg-gradient-to-r from-violet-50 to-indigo-50 border border-violet-200 rounded-lg p-4 shadow-sm">
      <div class="flex items-start gap-3">
        <div class="w-12 h-12 rounded-full bg-violet-100 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-outlined text-violet-600 text-2xl">storefront</span>
        </div>
        <div class="flex-1">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Supplier Registration</h3>
          <p class="text-sm text-gray-700 leading-relaxed">
            Register as a supplier to list your services. Applications are
            <strong>reviewed by our team</strong>; you'll be able to sign in once your
            account is approved.
          </p>
        </div>
      </div>
    </div>

    <!-- Signup Card -->
    <div class="card max-w-lg p-5">
      <div class="text-center block py-5">
        <div class="w-16 h-16 bg-violet-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="material-symbols-outlined text-2xl text-violet-600">add_business</span>
        </div>
        <h2 class="text-2xl font-bold text-gray-900">Become a Supplier</h2>
        <p class="text-gray-600 text-sm">Create your supplier account</p>
      </div>

      <div class="card-body">
        <form action="<?= root ?>supplier-signup" method="POST"
          class="space-y-6"
          x-data="{ showPassword:false, showConfirmPassword:false, passwordsMatch:true, isSubmitting:false,
            checkPasswordMatch(){ const p=document.getElementById('password')?.value||''; const c=document.getElementById('confirm_password')?.value||''; this.passwordsMatch = p===c || c===''; } }"
          @submit="isSubmitting = true">

          <?= CSRF::tokenField() ?>

          <!-- Name -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
              <input type="text" id="first_name" name="first_name" required class="input"
                placeholder="First name"
                value="<?= htmlspecialchars($_SESSION['supplier_form_data']['first_name'] ?? '') ?>">
            </div>
            <div class="form-control">
              <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
              <input type="text" id="last_name" name="last_name" required class="input"
                placeholder="Last name"
                value="<?= htmlspecialchars($_SESSION['supplier_form_data']['last_name'] ?? '') ?>">
            </div>
          </div>

          <!-- Company -->
          <div class="form-control">
            <label for="company" class="block text-sm font-medium text-gray-700 mb-1">Company / Business Name</label>
            <div class="input-group">
              <span class="input-icon-left material-symbols-outlined">business</span>
              <input type="text" id="company" name="company" class="input-with-icon"
                placeholder="Your business name"
                value="<?= htmlspecialchars($_SESSION['supplier_form_data']['company'] ?? '') ?>">
            </div>
          </div>

          <!-- Services offered + counts -->
          <?php
          $svcOffered  = $supplierServices ?? [];
          $svcSelected = $_SESSION['supplier_form_data']['services'] ?? []; // [service => count]
          ?>
          <?php if (!empty($svcOffered)): ?>
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Services you offer *</label>
            <p class="text-xs text-gray-500 mb-2">
              Pick the services you provide and how many of each you have. An administrator reviews and approves these.
              <a href="<?= root ?>supplier/services" target="_blank" rel="noopener" class="text-violet-600 hover:underline">Learn about each service</a>.
            </p>
            <div class="space-y-2">
              <?php foreach ($svcOffered as $key => $meta): ?>
                <?php
                  $isChecked = array_key_exists($key, $svcSelected);
                  $countVal  = $isChecked ? (int) $svcSelected[$key] : 1;
                ?>
                <div class="flex items-center gap-3 border border-gray-200 rounded-lg p-2.5"
                     x-data="{ on: <?= $isChecked ? 'true' : 'false' ?> }">
                  <label class="flex items-center gap-2 flex-1 cursor-pointer">
                    <input type="checkbox" name="services[]" value="<?= htmlspecialchars($key) ?>"
                           class="checkbox-input" x-model="on" <?= $isChecked ? 'checked' : '' ?>>
                    <span class="material-symbols-outlined text-violet-600 text-xl"><?= htmlspecialchars($meta['icon']) ?></span>
                    <span class="text-sm text-gray-800"><?= htmlspecialchars($meta['label']) ?></span>
                  </label>
                  <a href="<?= root ?>supplier/services/<?= htmlspecialchars($key) ?>" target="_blank" rel="noopener"
                     class="text-xs text-violet-600 hover:underline flex-shrink-0" title="Read more about <?= htmlspecialchars($meta['label']) ?>">Read more</a>
                  <div class="flex items-center gap-1.5" x-show="on">
                    <label class="text-xs text-gray-500" for="count_<?= htmlspecialchars($key) ?>">How many?</label>
                    <input type="number" min="1" max="500" id="count_<?= htmlspecialchars($key) ?>"
                           name="service_count[<?= htmlspecialchars($key) ?>]"
                           value="<?= (int) $countVal ?>"
                           class="input w-20 text-sm py-1 px-2">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Email -->
          <div class="form-control">
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
            <div class="input-group">
              <span class="input-icon-left material-symbols-outlined">email</span>
              <input type="email" id="email" name="email" required class="input-with-icon"
                placeholder="you@company.com"
                value="<?= htmlspecialchars($_SESSION['supplier_form_data']['email'] ?? '') ?>">
            </div>
          </div>

          <!-- Phone -->
          <div class="form-control">
            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <div class="input-group">
              <span class="input-icon-left material-symbols-outlined">call</span>
              <input type="text" id="phone" name="phone" class="input-with-icon"
                placeholder="Phone number"
                value="<?= htmlspecialchars($_SESSION['supplier_form_data']['phone'] ?? '') ?>">
            </div>
          </div>

          <!-- Password -->
          <div class="form-control">
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
            <div class="input-group">
              <input :type="showPassword ? 'text' : 'password'" id="password" name="password"
                required minlength="6" class="input-with-icon-right"
                placeholder="Create a password" autocomplete="new-password">
              <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
            </div>
            <p class="mt-1 text-xs text-gray-500">At least 6 characters.</p>
          </div>

          <!-- Confirm Password -->
          <div class="form-control">
            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
            <div class="input-group">
              <input :type="showConfirmPassword ? 'text' : 'password'" id="confirm_password" name="confirm_password"
                @input="checkPasswordMatch()" required minlength="6"
                :class="!passwordsMatch ? 'border-red-300 focus-visible:border-red-500' : ''"
                class="input-with-icon-right" placeholder="Re-enter your password" autocomplete="new-password">
              <span @click="showConfirmPassword = !showConfirmPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showConfirmPassword ? 'visibility_off' : 'visibility'"></span>
            </div>
            <p class="mt-1 text-xs text-red-600" x-show="!passwordsMatch && document.getElementById('confirm_password')?.value !== ''">Passwords do not match.</p>
          </div>

          <!-- CAPTCHA -->
          <?php
          $captchaData = $_SESSION['captcha_data'] ?? Captcha::generate();
          echo Captcha::renderField($captchaData);
          ?>

          <!-- Terms -->
          <div class="form-control">
            <div class="flex items-start gap-3">
              <div class="checkbox-container mt-0.5">
                <input id="terms" name="terms" type="checkbox" required class="checkbox-input">
                <div class="checkbox-custom">
                  <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
              </div>
              <label for="terms" class="cursor-pointer text-sm text-gray-700 leading-relaxed flex-wrap">
                I agree to the
                <a href="<?= root ?>page/terms-of-use" class="text-blue-600 hover:underline font-medium">Terms of Service</a>
                and
                <a href="<?= root ?>page/privacy-policy" class="text-blue-600 hover:underline font-medium">Privacy Policy</a>
              </label>
            </div>
          </div>

          <!-- Submit -->
          <div class="card-actions">
            <button type="submit" class="btn emerald w-full relative" :disabled="isSubmitting">
              <span class="flex items-center justify-center gap-2" x-show="!isSubmitting">
                <span class="material-symbols-outlined text-sm">add_business</span>
                Submit Application
              </span>
              <span class="flex items-center justify-center gap-2" x-show="isSubmitting" x-cloak>
                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Submitting…
              </span>
            </button>
          </div>
        </form>

        <div class="mt-6 pt-3 border-t border-gray-200 text-center">
          <p class="text-sm text-gray-600">
            Already have an account?
            <a href="<?= root ?>login" class="text-blue-600 hover:underline">Sign in</a>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>$(document).ready(() => $('#first_name').focus());</script>
<style>[x-cloak]{display:none!important}</style>

<?php
// Clear the repopulation data now the form has rendered, so stale values don't
// pre-fill the form on a later unrelated visit (matches signup.php's cleanup).
if (isset($_SESSION['supplier_form_data'])) unset($_SESSION['supplier_form_data']);
?>
