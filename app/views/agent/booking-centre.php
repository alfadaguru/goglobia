<?php
// app/views/agent/booking-centre.php — service picker hub.
// Vars: $services (filtered active), $agencyName, $pending.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Booking Centre</h1>
    <p class="text-sm text-slate-500 mb-5">Search and book for your clients — you're booking as <strong><?= htmlspecialchars((string)$agencyName) ?></strong>. Payment comes from your wallet.</p>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <?php if (!empty($pending)): ?>
    <div class="mb-5 px-4 py-3 rounded-lg bg-amber-50 text-amber-800 text-sm flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">hourglass_top</span>
        Your agency is pending approval — you can browse, but booking unlocks once an admin approves you.
    </div>
    <?php endif; ?>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($services as $s): ?>
        <a href="<?= root . htmlspecialchars((string)$s['url']) ?>" class="rounded-xl border border-slate-200 bg-white p-5 hover:shadow-sm hover:border-violet-300 transition flex items-center gap-4">
            <span class="inline-flex items-center justify-center w-11 h-11 rounded-lg bg-violet-50 text-violet-600">
                <span class="material-symbols-outlined"><?= htmlspecialchars((string)$s['icon']) ?></span>
            </span>
            <div>
                <p class="font-semibold text-slate-800"><?= htmlspecialchars((string)$s['label']) ?></p>
                <p class="text-[12px] text-slate-400">Search &amp; book</p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">
        <span class="material-symbols-outlined text-[18px] align-middle text-slate-400">info</span>
        After you complete a booking, open it under <a href="<?= root ?>agent/bookings" class="text-violet-600 hover:underline">Bookings</a> and pay from your wallet to confirm &amp; issue it.
    </div>
</div>
