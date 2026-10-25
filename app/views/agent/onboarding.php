<?php
// app/views/agent/onboarding.php — full-page setup wizard (uses _onboarding.php).
// Vars: $onboarding.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-3xl mx-auto px-4 py-8">
    <nav class="text-xs text-slate-400 mb-3"><a href="<?= root ?>agent/dashboard" class="hover:underline">Dashboard</a> / Get started</nav>
    <h1 class="text-2xl font-bold text-slate-800 mb-1">Get your agency ready</h1>
    <p class="text-sm text-slate-500 mb-6">A few steps and you can start booking for your clients.</p>

    <?php if (!empty($onboarding['complete'])): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
            <span class="material-symbols-outlined text-emerald-600 text-4xl">task_alt</span>
            <h2 class="text-lg font-bold text-slate-800 mt-2">You're all set!</h2>
            <p class="text-sm text-slate-500 mt-1">Your agency is ready to trade.</p>
            <div class="flex flex-wrap gap-3 justify-center mt-4">
                <a href="<?= root ?>agent/booking-centre" class="px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">New booking</a>
                <a href="<?= root ?>agent/dashboard" class="px-4 py-2.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50">Go to dashboard</a>
            </div>
        </div>
    <?php else: require views."agent/_onboarding.php"; endif; ?>
</div>
