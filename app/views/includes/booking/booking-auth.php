<?php
// ============================================================================
// BOOKING AUTHENTICATION COMPONENT - REUSABLE FOR ALL MODULES
// ============================================================================
// HANDLES USER AUTHENTICATION FOR BOOKING FLOW
// SUPPORTS: LOGGED-IN USERS, GUEST CHECKOUT, AND LOGIN BEFORE BOOKING
// USAGE: INCLUDE THIS IN BOOKING PAGES (STAYS, FLIGHTS, TOURS, CARS, ETC.)
// ============================================================================

@$SECURE or die('Access Denied!');

// ============================================================================
// CHECK IF USER IS ALREADY LOGGED IN
// ============================================================================
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

if ($isUserLoggedIn) {
    // FETCH USER DETAILS FROM DATABASE
    $loggedInUser = $db->get('users', [
        'id',
        'user_id',
        'title',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_country_code'
    ], ['user_id' => $_SESSION['user_id']]);

    // IF USER NOT FOUND, CLEAR SESSION
    if (!$loggedInUser) {
        $isUserLoggedIn = false;
        unset($_SESSION['user_id']);
    }
}

// Normalize phone country code to ISO for the country-code select.
// Users may have ISO (e.g. PK) from admin/profile, or phonecode (e.g. 92) from signup.
$userPhoneCountryIso = '';
if ($isUserLoggedIn && !empty($loggedInUser['phone_country_code'])) {
    $rawPhoneCountry = trim((string) $loggedInUser['phone_country_code']);
    if (preg_match('/^[A-Za-z]{2}$/', $rawPhoneCountry)) {
        $userPhoneCountryIso = strtoupper($rawPhoneCountry);
    } elseif (!empty($countries) && is_array($countries)) {
        $digits = preg_replace('/\D/', '', $rawPhoneCountry);
        foreach ($countries as $country) {
            if ((string) ($country['phonecode'] ?? '') === (string) $digits) {
                $userPhoneCountryIso = strtoupper((string) ($country['iso'] ?? ''));
                break;
            }
        }
    }
    if ($userPhoneCountryIso !== '') {
        $loggedInUser['phone_country_code'] = $userPhoneCountryIso;
    }
}

// ============================================================================
// INITIALIZE BOOKING TYPE (GUEST OR LOGIN)
// ============================================================================
$isGuestBookingEnabled = ($GLOBALS['app']['guest_booking'] ?? '1') != '0';
$bookingType = ($isUserLoggedIn) ? 'logged_in' : ($isGuestBookingEnabled ? 'guest' : 'login'); // DEFAULT TO GUEST IF ENABLED AND NOT LOGGED IN
?>

<!-- ALPINE.JS X-CLOAK STYLE -->
<style>
[x-cloak] { display: none !important; }
</style>

<!-- ============================================================================ -->
<!-- BOOKING AUTHENTICATION CARD -->
<!-- ============================================================================ -->
<div class="card p-0 mb-3" 
     x-data="bookingAuth()" 
     x-init="initUserData(<?= $isUserLoggedIn ? 'true' : 'false' ?>, <?= htmlspecialchars(json_encode($loggedInUser), ENT_QUOTES, 'UTF-8') ?>)">
    <div class="card-header cursor-pointer" @click="guestCollapsed = !guestCollapsed">
        <div>
            <span class="card-header-icon text-[18px]">person</span>
            <h3><?= T::guest ?? 'Guest' ?> <?= T::details ?? 'Details' ?></h3>
        </div>
        <div class="flex items-center gap-2">
            <?php if (!$isUserLoggedIn): ?>
            <span class="text-xs text-gray-500"><?= T::booking_as ?? 'Booking as' ?>:</span>
            <span class="text-xs font-semibold text-blue-600" x-text="bookingType === 'guest' ? 'Guest' : 'Member'"></span>
            <?php endif; ?>
            <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="guestCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
        </div>
    </div>

    <div class="card-body" x-show="!guestCollapsed" x-collapse
        <?php if (!$isUserLoggedIn): ?>
        <!-- ============================================================================ -->
        <!-- BOOKING TYPE SELECTION (GUEST OR LOGIN) -->
        <!-- ============================================================================ -->
        <div class="mb-6 p-4 bg-slate-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- GUEST BOOKING OPTION -->
                <?php if ($isGuestBookingEnabled): ?>
                <div class="radio-item">
                    <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md"
                         :class="bookingType === 'guest' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                         @click="bookingType = 'guest'">
                        <div class="flex items-start gap-3">
                            <div class="radio-container mt-1">
                                <input type="radio"
                                       id="booking_guest"
                                       name="booking_type"
                                       value="guest"
                                       class="radio-input"
                                       x-model="bookingType"
                                       checked>
                                <div class="radio-custom"
                                     :class="bookingType === 'guest' ? 'border-blue-500' : 'border-gray-300'">
                                    <div class="radio-dot"
                                         :class="bookingType === 'guest' ? '!bg-blue-500' : ''"></div>
                                </div>
                            </div>
                            <div class="flex-1">
                                <label for="booking_guest" class="cursor-pointer font-semibold text-gray-900 text-base flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">person_outline</span>
                                    <?= T::guest_booking ?? 'Guest Booking' ?>
                                </label>
                                <p class="text-xs text-gray-600 mt-1"><?= T::guest_booking_desc ?? 'Book without an account. We\'ll create one for you.' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- LOGIN BOOKING OPTION -->
                <div class="radio-item">
                    <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md"
                         :class="bookingType === 'login' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                         @click="bookingType = 'login'">
                        <div class="flex items-start gap-3">
                            <div class="radio-container mt-1">
                                <input type="radio"
                                       id="booking_login"
                                       name="booking_type"
                                       value="login"
                                       class="radio-input"
                                       x-model="bookingType">
                                <div class="radio-custom"
                                     :class="bookingType === 'login' ? 'border-blue-500' : 'border-gray-300'">
                                    <div class="radio-dot"
                                         :class="bookingType === 'login' ? '!bg-blue-500' : ''"></div>
                                </div>
                            </div>
                            <div class="flex-1">
                                <label for="booking_login" class="cursor-pointer font-semibold text-gray-900 text-base flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">login</span>
                                    <?= T::login_booking ?? 'Member Booking' ?>
                                </label>
                                <p class="text-xs text-gray-600 mt-1"><?= T::login_booking_desc ?? 'Login to your account for faster checkout.' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================================ -->
        <!-- QUICK LOGIN FORM (SHOWN WHEN LOGIN OPTION SELECTED) -->
        <!-- ============================================================================ -->
        <div x-show="bookingType === 'login'"
             x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform -translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">

            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label><?= T::email ?? 'Email' ?></label>
                        <input type="email"
                               id="quick_login_email"
                               class="input"
                               placeholder="<?= T::enter_email ?? 'Enter your email' ?>">
                    </div>

                    <div class="form-control">
                        <label><?= T::password ?? 'Password' ?></label>
                        <input type="password"
                               id="quick_login_password"
                               class="input"
                               placeholder="<?= T::enter_password ?? 'Enter your password' ?>">
                    </div>
                </div>

                <div class="flex flex-col gap-3 mt-4">
                    <div class="flex items-center justify-between">
                        <a href="<?= root ?>forgot-password"
                           class="text-xs text-blue-600 hover:text-blue-700"
                           target="_blank">
                            <?= T::forgot_password ?? 'Forgot Password?' ?>
                        </a>
                        <button type="button"
                                class="btn btn-sm"
                                @click.prevent="quickLogin()"
                                :disabled="isLoggingIn">
                            <span x-show="!isLoggingIn" class="material-symbols-outlined">login</span>
                            <span x-show="isLoggingIn" class="material-symbols-outlined animate-spin">progress_activity</span>
                            <span x-text="isLoggingIn ? '<?= T::logging_in ?? 'Logging in...' ?>' : '<?= T::login ?? 'Login' ?>'"></span>
                        </button>
                    </div>

                    <p class="text-xs text-gray-600 dark:text-gray-400 text-center">
                        <?= T::no_account ?? 'Don\'t have an account?' ?>
                        <a href="<?= root ?>signup" class="text-blue-600 hover:text-blue-700" target="_blank">
                            <?= T::sign_up ?? 'Sign up' ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================================ -->
        <!-- PRIMARY CONTACT INFORMATION FORM -->
        <!-- ============================================================================ -->
        <div <?php if (!$isUserLoggedIn): ?>x-show="bookingType === 'guest'"
             x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"<?php endif; ?>>

            <?php if ($isUserLoggedIn): ?>
            <!-- SHOW LOGGED IN USER INFO -->
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-green-600">check_circle</span>
                        <div>
                            <p class="text-sm font-semibold text-green-800 dark:text-green-300">
                                <?= T::logged_in_as ?? 'Logged in as' ?>: <?= htmlspecialchars($loggedInUser['first_name'] . ' ' . $loggedInUser['last_name']) ?>
                            </p>
                            <p class="text-xs text-green-700 dark:text-green-400">
                                <?= htmlspecialchars($loggedInUser['email']) ?>
                            </p>
                        </div>
                    </div>
                    <a href="<?= root ?>logout"
                       class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-900 bg-white hover:bg-gray-100 border border-gray-300 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-base">logout</span>
                        <span><?= T::logout ?? 'Logout' ?></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- GUEST FORM FIELDS -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="form-control">
                    <label><?= T::title ?? 'Title' ?></label>
                    <select x-model="primary_guest.title"
                            class="select"
                            <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                            :disabled="isUserLoggedIn && !booking_for_someone_else">
                        <option value="" disabled selected><?= T::select ?? 'Select' ?></option>
                        <option value="Mr" <?php if ($isUserLoggedIn && $loggedInUser['title'] == 'Mr'): ?>selected<?php endif; ?>>Mr</option>
                        <option value="Mrs" <?php if ($isUserLoggedIn && $loggedInUser['title'] == 'Mrs'): ?>selected<?php endif; ?>>Mrs</option>
                        <option value="Ms" <?php if ($isUserLoggedIn && $loggedInUser['title'] == 'Ms'): ?>selected<?php endif; ?>>Ms</option>
                        <option value="Dr" <?php if ($isUserLoggedIn && $loggedInUser['title'] == 'Dr'): ?>selected<?php endif; ?>>Dr</option>
                    </select>
                </div>

                <div class="form-control">
                    <label><?= T::first_name ?? 'First Name' ?></label>
                    <input type="text"
                           x-model="primary_guest.first_name"
                           @input="primary_guest.first_name = primary_guest.first_name.replace(/[0-9]/g, '')"
                           class="input"
                           pattern="[^0-9]*"
                           title="Letters only, no numbers"
                           <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                           :disabled="isUserLoggedIn && !booking_for_someone_else"
                           placeholder="<?= T::enter_first_name ?? 'Enter first name' ?>">
                </div>

                <div class="form-control">
                    <label><?= T::last_name ?? 'Last Name' ?></label>
                    <input type="text"
                           x-model="primary_guest.last_name"
                           @input="primary_guest.last_name = primary_guest.last_name.replace(/[0-9]/g, '')"
                           class="input"
                           pattern="[^0-9]*"
                           title="Letters only, no numbers"
                           <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                           :disabled="isUserLoggedIn && !booking_for_someone_else"
                           placeholder="<?= T::enter_last_name ?? 'Enter last name' ?>">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-control">
                    <label><?= T::email ?? 'Email' ?></label>
                    <input type="email"
                           x-model="primary_guest.email"
                           class="input"
                           <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                           :disabled="isUserLoggedIn && !booking_for_someone_else"
                           placeholder="<?= T::enter_email ?? 'Enter email address' ?>">
                </div>

                <div class="form-control">
                    <label><?= T::country_code ?? 'Country Code' ?></label>
                    <select x-model="primary_guest.country_code"
                            class="select"
                            <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                            :disabled="isUserLoggedIn && !booking_for_someone_else">
                        <?php foreach ($countries as $country): ?>
                            <option value="<?= htmlspecialchars($country['iso']) ?>"
                                <?= ($userPhoneCountryIso !== '' && $userPhoneCountryIso === $country['iso']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($country['iso']) ?> +<?= htmlspecialchars($country['phonecode']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-control">
                    <label><?= T::phone ?? 'Phone' ?></label>
                    <input type="tel"
                           x-model="primary_guest.phone"
                           @input="sanitizePhone()"
                           class="input"
                           :maxlength="phoneMaxLength()"
                           inputmode="numeric"
                           <?php if (!$isUserLoggedIn): ?>:required="bookingType === 'guest'"<?php else: ?>required<?php endif; ?>
                           :disabled="isUserLoggedIn && !booking_for_someone_else"
                           placeholder="<?= T::enter_phone ?? 'Enter phone number' ?>">
                </div>
            </div>

            <!-- BOOKING FOR SOMEONE ELSE CHECKBOX -->
            <div class="mt-6 pt-3 border-t border-gray-200 dark:border-gray-700">
                <div class="checkbox-item flex items-center gap-2">

                    <div class="checkbox-container">
                        <input type="checkbox"
                        id="booking_for_someone_else"
                        x-model="booking_for_someone_else"
                        class="checkbox-input">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>

                    <label for="booking_for_someone_else" class="checkbox-label leading-3">
                        <span class="text-sm font-medium"><?= T::booking_for_someone_else ?? 'I\'m booking for someone else' ?></span>
                        <span class="text-xs text-gray-600 block">
                            <?= T::booking_for_someone_else_desc ?? 'The primary contact is different from the lead traveler' ?>
                        </span>
                    </label>
                </div>
            </div>

            <!-- HIDDEN FIELD TO TRACK BOOKING TYPE -->
            <input type="hidden" name="booking_type" :value="bookingType">
            <?php if ($isUserLoggedIn): ?>
            <input type="hidden" name="user_id" value="<?= $loggedInUser['user_id'] ?>">
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Phone length rules from countries.min_length / max_length (cap at bookings.phone = 15).
if (!isset($phoneRulesByIso) || !is_array($phoneRulesByIso)) {
    $phoneRulesByIso = [];
    $__phoneDbMax = 15;
    foreach (($countries ?? []) as $__row) {
        $__iso = strtoupper((string)($__row['iso'] ?? ''));
        if ($__iso === '') {
            continue;
        }
        $__min = (int)($__row['min_length'] ?? 0);
        $__max = (int)($__row['max_length'] ?? 0);
        if ($__min < 1) {
            $__min = 6;
        }
        if ($__max < 1) {
            $__max = $__phoneDbMax;
        }
        $__max = min($__max, $__phoneDbMax);
        if ($__min > $__max) {
            $__min = $__max;
        }
        $phoneRulesByIso[$__iso] = ['min' => $__min, 'max' => $__max];
    }
}
?>

<script>
// ============================================================================
// ALPINE.JS BOOKING AUTH COMPONENT
// ============================================================================
function bookingAuth() {
    return {
        guestCollapsed: false,
        bookingType: '<?= $bookingType ?>',
        isLoggingIn: false,
        isUserLoggedIn: false,
        booking_for_someone_else: false,
        phoneRulesByIso: <?= json_encode($phoneRulesByIso, JSON_UNESCAPED_UNICODE) ?>,
        phoneDbMax: 15,
        primary_guest: {
            title: '',
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            country_code: ''
        },

        phoneRules() {
            const iso = String(this.primary_guest.country_code || '').toUpperCase();
            const rules = (this.phoneRulesByIso && this.phoneRulesByIso[iso])
                ? this.phoneRulesByIso[iso]
                : { min: 6, max: this.phoneDbMax };
            return {
                min: Math.max(1, parseInt(rules.min, 10) || 6),
                max: Math.min(this.phoneDbMax, parseInt(rules.max, 10) || this.phoneDbMax)
            };
        },

        phoneMaxLength() {
            return this.phoneRules().max;
        },

        sanitizePhone() {
            const max = this.phoneMaxLength();
            this.primary_guest.phone = String(this.primary_guest.phone || '')
                .replace(/\D/g, '')
                .slice(0, max);
        },

        initUserData(isLoggedIn, userData) {
            this.isUserLoggedIn = isLoggedIn;
            if (isLoggedIn && userData) {
                this.bookingType = 'logged_in';
                this.primary_guest.title = userData.title || '';
                this.primary_guest.first_name = userData.first_name || '';
                this.primary_guest.last_name = userData.last_name || '';
                this.primary_guest.email = userData.email || '';
                this.primary_guest.phone = userData.phone || '';
                this.primary_guest.country_code = userData.phone_country_code || '';
            }

            this.$watch('primary_guest.country_code', () => {
                this.sanitizePhone();
            });

            this.$watch('primary_guest', (value) => {
                this.$dispatch('guest-updated', { primary_guest: value, booking_for_someone_else: this.booking_for_someone_else });
            }, { deep: true });

            this.$watch('booking_for_someone_else', (value) => {
                this.$dispatch('guest-updated', { primary_guest: this.primary_guest, booking_for_someone_else: value });
            });

            this.$nextTick(() => {
                this.$dispatch('guest-updated', { primary_guest: this.primary_guest, booking_for_someone_else: this.booking_for_someone_else });
            });
        },

        /**
         * Full-page POST login (same pattern as stays/cars). Avoids stuck spinner when
         * AJAX sets the session but never completes the reload path.
         */
        quickLogin() {
            if (this.isLoggingIn) return;
            const emailEl = document.getElementById('quick_login_email');
            const passwordEl = document.getElementById('quick_login_password');
            const email = ((emailEl && emailEl.value) || '').trim();
            const password = ((passwordEl && passwordEl.value) || '').trim();

            if (!email || !password) {
                if (typeof showToast === 'function') {
                    showToast('<?= T::please_enter_email_password ?? 'Please enter email and password' ?>', 'error');
                } else {
                    alert('<?= T::please_enter_email_password ?? 'Please enter email and password' ?>');
                }
                return;
            }

            this.isLoggingIn = true;

            const csrfEl = document.querySelector('input[name="csrf_token"]')
                || document.querySelector('meta[name="csrf-token"]');
            const csrf = csrfEl
                ? (csrfEl.value || csrfEl.getAttribute('content') || '')
                : '';

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= root ?>api/booking/quick-login';
            form.style.display = 'none';

            const fields = {
                csrf_token: csrf,
                email: email,
                password: password,
                redirect_to: window.location.href
            };
            Object.keys(fields).forEach((name) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = fields[name];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        }
    };
}

<?php if ($isUserLoggedIn && $loggedInUser): ?>
document.addEventListener('alpine:init', () => {
    setTimeout(() => {
        if (typeof window.bookingFormData !== 'undefined') {
            window.bookingFormData.primary_guest.first_name = '<?= addslashes($loggedInUser['first_name']) ?>';
            window.bookingFormData.primary_guest.last_name = '<?= addslashes($loggedInUser['last_name']) ?>';
            window.bookingFormData.primary_guest.email = '<?= addslashes($loggedInUser['email']) ?>';
            window.bookingFormData.primary_guest.phone = '<?= addslashes($loggedInUser['phone'] ?? '') ?>';
            window.bookingFormData.primary_guest.country_code = '<?= addslashes($loggedInUser['phone_country_code'] ?? '') ?>';
        }
    }, 100);
});
<?php endif; ?>
</script>
