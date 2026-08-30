<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Check if locked to disable form
$isLocked = isset($_SESSION['login_error']) && $_SESSION['login_error'] === 'locked';
$lockUntil = $isLocked ? ($_SESSION['lock_until'] ?? null) : null;
$unlockTimeMs = $lockUntil ? (strtotime($lockUntil) * 1000) : null;
$attemptsRemaining = $_SESSION['attempts_remaining'] ?? null;
$unverifiedUserId = $_SESSION['unverified_user_id'] ?? null;
$licenseBlocked = !empty($_SESSION['lic_err']);
?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
    <div class="max-w-md w-full space-y-8">
        <!-- Success Messages (non-license) -->
        <?php if (isset($_SESSION['success']) && !str_contains($_SESSION['success'], 'Validating')): ?>
            <div class="alert-success">
                <span class="material-symbols-outlined">check_circle</span>
                <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Error Messages -->
        <?php /* LICENSE BLOCK — ALL HTML GENERATED INSIDE _lic_block() IN app/lib/lic.php */ ?>
        <?= _lic_block() ?>
        <?php if (isset($_SESSION['login_error'])): ?>
            <div class="alert-error">
                <span class="material-symbols-outlined">error</span>
                <div>
                    <?php if ($_SESSION['login_error'] === 'empty'): ?>
                        <p><?= T::error_empty_fields ?></p>
                    <?php elseif ($_SESSION['login_error'] === 'invalid'): ?>
                        <div class="space-y-2">
                            <p><?= T::error_invalid_credentials ?></p>
                            <?php if ($attemptsRemaining): ?>
                                <p class="text-sm">
                                    <strong><?= $attemptsRemaining ?></strong> <?= T::attempts_remaining ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($_SESSION['login_error'] === 'banned'): ?>
                        <p><?= T::error_account_banned ?></p>
                    <?php elseif ($_SESSION['login_error'] === 'email_not_verified'): ?>
                        <div class="space-y-3">
                            <p><?= T::error_email_not_verified ?></p>
                            <?php if ($unverifiedUserId): ?>
                                <form action="<?=root?>resend-verification" method="POST">
                                    <?= CSRF::tokenField() ?>
                                    <input type="hidden" name="user_id" value="<?= $unverifiedUserId ?>">
                                    <button type="submit" class="btn btn-sm cyan">
                                        <span class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-sm">send</span>
                                            <?= T::resend_verification ?>
                                        </span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($_SESSION['login_error'] === 'csrf'): ?>
                        <p><?= T::error_csrf_token ?></p>
                    <?php elseif ($_SESSION['login_error'] === 'locked'): ?>
                        <div class="space-y-2">
                            <p><?= T::error_account_locked ?></p>
                            <?php if ($unlockTimeMs): ?>
                                <div class="space-y-1">
                                    <p class="text-sm"><?= T::try_again_in ?></p>
                                    <div class="font-mono text-lg font-semibold" id="countdown-timer">--:--</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p><?= T::error_authentication_required ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                unset($_SESSION['login_error']);
                unset($_SESSION['lock_until']);
                unset($_SESSION['attempts_remaining']);
                unset($_SESSION['unverified_user_id']);
            ?>
        <?php endif; ?>

        <!-- Login Success Messages -->
        <?php if (isset($_SESSION['login_success'])): ?>
            <div class="alert-success">
                <span class="material-symbols-outlined">check_circle</span>
                <p>
                    <?php if ($_SESSION['login_success'] === 'reset'): ?>
                        <?= T::success_password_reset ?>
                    <?php elseif ($_SESSION['login_success'] === 'logout'): ?>
                        <?= T::success_logout ?>
                    <?php elseif ($_SESSION['login_success'] === 'verified'): ?>
                        <?= T::email_verified_successfully ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php unset($_SESSION['login_success']); ?>
        <?php endif; ?>

        <?php if (!$licenseBlocked): ?>
        <!-- Login Form -->
        <div class="card p-5">
            <div class="card-body">
                <div class="my-4 text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-2xl text-blue-600">lock</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-0">
                        <?= str_contains($_SERVER['REQUEST_URI'], '/admin/') ? T::admin_portal : T::welcome_back ?>
                    </h2>
                    <p class="text-gray-600">
                        <?= str_contains($_SERVER['REQUEST_URI'], '/admin/') ? T::admin_signin_description : T::signin_description ?>
                    </p>
                </div>

                <form action="<?=root?>login" method="POST" class="space-y-6 login-form" id="login-form"
                      x-data="{ isSubmitting: false }"
                      @submit="isSubmitting = true">

                    <?= CSRF::tokenField() ?>
                    <?php
                    $loginRedirectField = (string)($_GET['redirect'] ?? ($_SESSION['login_redirect'] ?? ''));
                    if ($loginRedirectField !== ''):
                    ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($loginRedirectField, ENT_QUOTES) ?>">
                    <?php endif; ?>

                    <div class="form-control">
                        <label for="email" class="text-sm font-medium text-gray-700 mb-2">
                            <?= str_contains($_SERVER['REQUEST_URI'], '/admin/') ? T::admin_email : T::email_address ?>
                        </label>
                        <div class="input-group">
                            <span class="input-icon-left material-symbols-outlined">email</span>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                class="input-with-icon"
                                placeholder="<?= T::email_address ?>"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            >
                        </div>
                    </div>

                    <div class="form-control">
                        <label for="password" class="text-sm font-medium text-gray-700 mb-2"><?= T::password ?></label>
                        <div class="input-group">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                class="input-with-icon-right"
                                placeholder="<?= T::password ?>"
                            >
                            <span class="input-icon-right material-symbols-outlined cursor-pointer text-gray-400" id="toggle-password">visibility</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input
                                    id="remember_me"
                                    name="remember_me"
                                    type="checkbox"
                                    class="checkbox-input"
                                >
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label for="remember_me" class="cursor-pointer text-sm text-gray-900">
                                <?= T::remember_me ?>
                            </label>
                        </div>

                        <?php if (!str_contains($_SERVER['REQUEST_URI'], '/admin/')): ?>
                        <a href="<?=root?>forgot-password" class="btn-link text-sm"><?= T::forgot_password ?></a>
                        <?php endif; ?>
                    </div>

                    <div class="card-actions">
                        <button type="submit" class="btn w-full" :disabled="isSubmitting">
                            <span class="flex items-center justify-center gap-2" x-show="!isSubmitting">
                                <span class="material-symbols-outlined text-sm">login</span>
                                <?= str_contains($_SERVER['REQUEST_URI'], '/admin/') ? T::signin_admin_portal : T::signin_account ?>
                            </span>
                            <span class="flex items-center justify-center gap-2" x-show="isSubmitting" x-cloak>
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <?= T::signing_in ?>
                            </span>
                        </button>
                    </div>

                    <?php if (str_contains($_SERVER['REQUEST_URI'], '/admin/')): ?>
                    <div class="alert-info">
                        <span class="material-symbols-outlined">info</span>
                        <p>
                            <strong><?= T::default_credentials ?>:</strong> admin@example.com / password
                        </p>
                    </div>
                    <?php endif; ?>
                </form>

                <?php if (!str_contains($_SERVER['REQUEST_URI'], '/admin/')): ?>
                <div class="mt-4 pt-3 border-t border-gray-200 text-center">
                    <p class="text-sm text-gray-600">
                        <?= T::no_account ?>
                        <a href='<?=root?>signup' class="btn-link"><?= T::signup ?></a>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Simple JavaScript for password toggle
const togglePasswordBtn = document.getElementById('toggle-password');
if (togglePasswordBtn) {
    togglePasswordBtn.addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const icon = this;
        if (!passwordInput) return;

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            passwordInput.type = 'password';
            icon.textContent = 'visibility';
        }
    });
}

// Countdown timer for locked accounts
<?php if ($isLocked && $unlockTimeMs): ?>
function updateCountdown() {
    const now = Date.now();
    const diff = <?= $unlockTimeMs ?> - now;
    const timerElement = document.getElementById('countdown-timer');

    if (diff <= 0) {
        timerElement.textContent = '00:00';
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    } else {
        const mins = Math.floor(diff / 60000);
        const secs = Math.floor((diff % 60000) / 1000);
        timerElement.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        setTimeout(updateCountdown, 1000);
    }
}
updateCountdown();
<?php endif; ?>

// Focus on email field
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('email');
    if (emailInput) emailInput.focus();
});
</script>

<style>[x-cloak]{display:none!important}</style>