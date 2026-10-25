<?php
// app/views/agent/booking-pay.php — wallet-settle confirm for one booking.
// Vars: $booking, $balance, $creditLimit, $agentCost, $alreadyPaid, $defaultCurrency.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
$ccy = htmlspecialchars((string)($booking['currency_markup'] ?? $defaultCurrency ?? 'NGN'));
$spendable = (float)$balance + (float)$creditLimit;
$short = $agentCost > $spendable;
$inv = (string)($booking['invoice_id'] ?? '');
?>
<div class="max-w-xl mx-auto px-4 py-8">
    <nav class="text-xs text-slate-400 mb-3"><a href="<?= root ?>agent/bookings" class="hover:underline">Bookings</a> / Pay</nav>
    <h1 class="text-xl font-bold text-slate-800 mb-1">Pay for booking</h1>
    <p class="text-sm text-slate-500 mb-5">Invoice <span class="font-mono"><?= htmlspecialchars($inv) ?></span> · <span class="capitalize"><?= htmlspecialchars((string)($booking['module_type']??'')) ?></span></p>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <?php if (!empty($alreadyPaid)): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
            <span class="material-symbols-outlined text-emerald-600 text-4xl">task_alt</span>
            <h2 class="text-lg font-bold text-slate-800 mt-2">Already paid</h2>
            <a href="<?= root ?>agent/bookings" class="inline-block mt-3 px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold">Back to bookings</a>
        </div>
    <?php else: ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
        <div class="flex justify-between text-sm"><span class="text-slate-500">You pay (from wallet)</span><span class="font-bold text-slate-800"><?= $ccy ?> <?= $fmt($agentCost) ?></span></div>
        <hr class="border-slate-100">
        <div class="flex justify-between text-sm"><span class="text-slate-500">Wallet balance</span><span class="text-slate-700"><?= $ccy ?> <?= $fmt($balance) ?></span></div>
        <div class="flex justify-between text-sm"><span class="text-slate-500">Credit line</span><span class="text-slate-700"><?= $ccy ?> <?= $fmt($creditLimit) ?></span></div>
        <div class="flex justify-between text-sm"><span class="text-slate-500">Spendable</span><span class="font-semibold <?= $short?'text-red-600':'text-emerald-700' ?>"><?= $ccy ?> <?= $fmt($spendable) ?></span></div>

        <?php if ($short): ?>
            <div class="px-3 py-2 rounded-lg bg-red-50 text-red-700 text-sm">Not enough wallet balance + credit line. <a href="<?= root ?>agent/wallet" class="underline font-semibold">Top up your wallet</a> and try again.</div>
        <?php else: ?>
            <form method="post" action="<?= root ?>agent/booking/<?= urlencode($inv) ?>/pay" onsubmit="this.querySelector('button').disabled=true;">
                <?= CSRF::tokenField() ?>
                <button class="w-full mt-2 px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Pay <?= $ccy ?> <?= $fmt($agentCost) ?> from wallet</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
