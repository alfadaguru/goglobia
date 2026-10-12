<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
  <div class="max-w-lg w-full space-y-8">

    <!-- Back to the supplier overview -->
    <a href="<?= root ?>supplier-signup" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-primary">
      <span class="material-symbols-outlined !text-[18px]">arrow_back</span> Back to supplier overview
    </a>

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
    <div class="bg-gradient-to-r from-indigo-50 to-indigo-50 border border-violet-200 rounded-lg p-4 shadow-sm">
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
    <?php
      $svcOffered  = $supplierServices ?? [];
      $svcSelected = $_SESSION['supplier_form_data']['services'] ?? []; // [service => count]
      $preSelected = array_keys($svcSelected);
    ?>
    <div class="card max-w-3xl mx-auto overflow-hidden">
      <!-- Card header band -->
      <div class="bg-gradient-to-br from-primary to-indigo-600 px-6 sm:px-10 py-8 text-white">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 bg-white/10 backdrop-blur rounded-xl flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-3xl">add_business</span>
          </div>
          <div>
            <h2 class="text-2xl font-bold">Supplier application</h2>
            <p class="text-white/80 text-sm">Tell us about your business and the services you offer. Approval usually takes under 48 hours.</p>
          </div>
        </div>
      </div>

      <div class="card-body px-6 sm:px-10 py-8">
        <form action="<?= root ?>supplier-signup" method="POST"
          class="space-y-10"
          x-data="{ showPassword:false, showConfirmPassword:false, passwordsMatch:true, isSubmitting:false,
            selected: <?= htmlspecialchars(json_encode($preSelected), ENT_QUOTES) ?>,
            toggle(k){ const i=this.selected.indexOf(k); if(i>-1){ this.selected.splice(i,1); } else { this.selected.push(k); } },
            has(k){ return this.selected.includes(k); },
            get serviceCount(){ return this.selected.length; },
            checkPasswordMatch(){ const p=document.getElementById('password')?.value||''; const c=document.getElementById('confirm_password')?.value||''; this.passwordsMatch = p===c || c===''; } }"
          @submit="isSubmitting = true">

          <?= CSRF::tokenField() ?>

          <!-- ===== SECTION: Your details ===== -->
          <section class="space-y-5">
            <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
              <span class="material-symbols-outlined text-primary">person</span>
              <h3 class="text-base font-semibold text-gray-900">Your details</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div class="form-control">
                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First name *</label>
                <input type="text" id="first_name" name="first_name" required class="input" placeholder="First name"
                  value="<?= htmlspecialchars($_SESSION['supplier_form_data']['first_name'] ?? '') ?>">
              </div>
              <div class="form-control">
                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last name *</label>
                <input type="text" id="last_name" name="last_name" required class="input" placeholder="Last name"
                  value="<?= htmlspecialchars($_SESSION['supplier_form_data']['last_name'] ?? '') ?>">
              </div>
              <div class="form-control">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address *</label>
                <div class="input-group">
                  <span class="input-icon-left material-symbols-outlined">email</span>
                  <input type="email" id="email" name="email" required class="input-with-icon" placeholder="you@company.com"
                    value="<?= htmlspecialchars($_SESSION['supplier_form_data']['email'] ?? '') ?>">
                </div>
              </div>
              <div class="form-control">
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <div class="input-group">
                  <span class="input-icon-left material-symbols-outlined">call</span>
                  <input type="text" id="phone" name="phone" class="input-with-icon" placeholder="Phone number"
                    value="<?= htmlspecialchars($_SESSION['supplier_form_data']['phone'] ?? '') ?>">
                </div>
              </div>
            </div>
          </section>

          <!-- ===== SECTION: Your business ===== -->
          <section class="space-y-5">
            <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
              <span class="material-symbols-outlined text-primary">business</span>
              <h3 class="text-base font-semibold text-gray-900">Your business</h3>
            </div>
            <div class="form-control">
              <label for="company" class="block text-sm font-medium text-gray-700 mb-1">Company / business name</label>
              <div class="input-group">
                <span class="input-icon-left material-symbols-outlined">storefront</span>
                <input type="text" id="company" name="company" class="input-with-icon" placeholder="Your business name"
                  value="<?= htmlspecialchars($_SESSION['supplier_form_data']['company'] ?? '') ?>">
              </div>
            </div>
          </section>

          <!-- ===== SECTION: Services you offer (REQUIRED — at least one) ===== -->
          <?php if (!empty($svcOffered)): ?>
          <section class="space-y-4">
            <div class="flex items-center justify-between gap-2 pb-2 border-b border-gray-100">
              <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">category</span>
                <h3 class="text-base font-semibold text-gray-900">Services you offer *</h3>
              </div>
              <span class="text-xs font-medium px-2.5 py-1 rounded-full"
                    :class="serviceCount > 0 ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
                    x-text="serviceCount > 0 ? serviceCount + ' selected' : 'Select at least one'"></span>
            </div>
            <p class="text-sm text-gray-500">
              Pick everything you provide and how many of each you have. An administrator reviews and sets your approved quota.
              <a href="<?= root ?>supplier/services" target="_blank" rel="noopener" class="text-primary hover:underline">Learn about each service</a>.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <?php foreach ($svcOffered as $key => $meta): ?>
                <?php
                  $isChecked = array_key_exists($key, $svcSelected);
                  $countVal  = $isChecked ? (int) $svcSelected[$key] : 1;
                  $k = htmlspecialchars($key);
                ?>
                <div class="rounded-xl border-2 transition-colors p-3"
                     :class="has('<?= $k ?>') ? 'border-primary bg-primary/5' : 'border-gray-200 hover:border-gray-300'">
                  <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="services[]" value="<?= $k ?>" class="checkbox-input"
                           @change="toggle('<?= $k ?>')" <?= $isChecked ? 'checked' : '' ?>>
                    <span class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors"
                          :class="has('<?= $k ?>') ? 'bg-primary text-white' : 'bg-gray-100 text-gray-500'">
                      <span class="material-symbols-outlined"><?= htmlspecialchars($meta['icon']) ?></span>
                    </span>
                    <span class="flex-1 min-w-0">
                      <span class="block text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($meta['label']) ?></span>
                      <a href="<?= root ?>supplier/services/<?= $k ?>" target="_blank" rel="noopener"
                         @click.stop class="text-xs text-primary hover:underline">Read more</a>
                    </span>
                  </label>
                  <div class="flex items-center gap-2 mt-2" x-show="has('<?= $k ?>')" x-cloak style="padding-left:3.25rem;">
                    <label class="text-xs text-gray-500" for="count_<?= $k ?>">How many?</label>
                    <input type="number" min="1" max="500" id="count_<?= $k ?>"
                           name="service_count[<?= $k ?>]" value="<?= (int) $countVal ?>"
                           class="input w-24 text-sm py-1 px-2">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="text-xs text-amber-700 flex items-center gap-1" x-show="serviceCount === 0">
              <span class="material-symbols-outlined !text-[16px]">info</span> Select at least one service to continue.
            </p>
          </section>
          <?php endif; ?>

          <!-- ===== SECTION: Account & security ===== -->
          <section class="space-y-5">
            <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
              <span class="material-symbols-outlined text-primary">lock</span>
              <h3 class="text-base font-semibold text-gray-900">Account &amp; security</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

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
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm password *</label>
                <div class="input-group">
                  <input :type="showConfirmPassword ? 'text' : 'password'" id="confirm_password" name="confirm_password"
                    @input="checkPasswordMatch()" required minlength="6"
                    :class="!passwordsMatch ? 'border-red-300 focus-visible:border-red-500' : ''"
                    class="input-with-icon-right" placeholder="Re-enter your password" autocomplete="new-password">
                  <span @click="showConfirmPassword = !showConfirmPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showConfirmPassword ? 'visibility_off' : 'visibility'"></span>
                </div>
                <p class="mt-1 text-xs text-red-600" x-show="!passwordsMatch && document.getElementById('confirm_password')?.value !== ''">Passwords do not match.</p>
              </div>
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
          </section>

          <!-- Submit — blocked until at least one service is selected (when services exist) -->
          <?php $svcRequired = !empty($svcOffered); ?>
          <div class="card-actions pt-2">
            <button type="submit" class="btn emerald w-full relative"
              :disabled="isSubmitting <?= $svcRequired ? '|| serviceCount === 0' : '' ?>"
              :class="(<?= $svcRequired ? 'serviceCount === 0' : 'false' ?>) ? 'opacity-50 cursor-not-allowed' : ''"
              @click="if (<?= $svcRequired ? 'serviceCount === 0' : 'false' ?>) { $event.preventDefault(); }"
              x-bind:title="(<?= $svcRequired ? 'serviceCount === 0' : 'false' ?>) ? 'Select at least one service first' : ''">
              <span class="flex items-center justify-center gap-2" x-show="!isSubmitting">
                <span class="material-symbols-outlined text-sm">add_business</span>
                <span x-text="(<?= $svcRequired ? 'serviceCount === 0' : 'false' ?>) ? 'Select a service to continue' : 'Submit application'"></span>
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
