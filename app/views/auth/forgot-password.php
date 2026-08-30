<?php @$SECURE or die('Access Denied!'); ?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
    <div class="max-w-md w-full space-y-8">
        <!-- Error/Success Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert-error">
                <span class="material-symbols-outlined">error</span>
                <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert-success">
                <span class="material-symbols-outlined">check_circle</span>
                <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Forgot Password Form -->
        <div class="card p-0">
            <div class="card-body">
                <div class="my-4 text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-2xl text-blue-600">lock_reset</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= T::forgot_password ?></h2>
                    <p class="text-gray-600"><?= T::reset_instructions ?></p>
                </div>

                <form action="<?=root?>forgot-password" method="POST" class="space-y-6 forgot-password-form" id="forgot-password-form">

                    <?= CSRF::tokenField() ?>

                    <div class="form-control">
                        <label for="email" class="text-sm font-medium text-gray-700 mb-2"><?= T::email_address ?></label>
                        <div class="input-group">
                            <span class="input-icon-left material-symbols-outlined">email</span>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                class="input-with-icon"
                                placeholder="<?= T::email_address ?>"
                                value="<?= htmlspecialchars($_SESSION['form_data']['email'] ?? '') ?>"
                            >
                        </div>
                    </div>

                    <div class="card-actions">
                        <button type="submit" class="btn w-full">
                            <span class="flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-sm">send</span>
                                <?= T::send_instructions ?>
                            </span>
                        </button>
                    </div>
                </form>

                <div class="mt-4 pt-3 border-t border-gray-200 text-center space-y-4">
                    <p class="text-sm text-gray-600"><?= T::remember_your_password ?></p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <a href="<?=root?>login" class="btn light">
                            <span class="material-symbols-outlined text-sm">arrow_back</span>
                            <?= T::back_to_login ?>
                        </a>

                        <a href="<?=root?>signup" class="btn emerald">
                            <span class="material-symbols-outlined text-sm">person_add</span>
                            <?= T::create_account ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Focus on email field
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('email').focus();
});
</script>

<?php
// Clear form data after displaying
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>