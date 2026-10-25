<?php
// app/views/agent/staff-accept.php — public token-gated invite accept form.
// Vars: $invite (null if invalid/expired), $existingAccount, $token (from GET).
@$SECURE or die('Access Denied!');
$token = $_GET['token'] ?? '';
?>
<div class="max-w-md mx-auto px-4 py-10">
    <?php if (empty($invite)): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 text-center">
            <span class="material-symbols-outlined text-red-500 text-4xl">link_off</span>
            <h1 class="text-lg font-bold text-slate-800 mt-2">Invitation invalid or expired</h1>
            <p class="text-sm text-slate-500 mt-1">Ask the agency to send a new invitation.</p>
            <a href="<?= root ?>login" class="inline-block mt-4 px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold">Go to login</a>
        </div>
    <?php elseif (!empty($existingAccount)): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">
            <span class="material-symbols-outlined text-amber-500 text-4xl">account_circle</span>
            <h1 class="text-lg font-bold text-slate-800 mt-2">Sign in to accept</h1>
            <p class="text-sm text-slate-500 mt-1">An account already exists for <strong><?= htmlspecialchars((string)$invite['invited_email']) ?></strong>. Please sign in to that account first, then open this invitation link again.</p>
            <a href="<?= root ?>login" class="inline-block mt-4 px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold">Sign in</a>
        </div>
    <?php else: ?>
        <h1 class="text-xl font-bold text-slate-800 mb-1">Accept your invitation</h1>
        <p class="text-sm text-slate-500 mb-5">Set a password to join the agency team.</p>
        <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
        <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= root ?>agent/staff/accept" class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars((string)$token) ?>">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-[11px] uppercase text-slate-400 mb-1">First name</label><input name="first_name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Last name</label><input name="last_name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            </div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Email</label><input value="<?= htmlspecialchars((string)$invite['invited_email']) ?>" disabled class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Password</label><input type="password" name="password" required minlength="6" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Confirm password</label><input type="password" name="confirm_password" required minlength="6" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <button class="w-full px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Accept & create account</button>
        </form>
    <?php endif; ?>
</div>
