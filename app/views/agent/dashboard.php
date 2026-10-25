<?php
// app/views/agent/dashboard.php — Agent ERP P1 workspace home.
// Vars: $owner,$viewingAsStaff,$pending,$balance,$creditLimit,$totalBookings,
// $paidEarning,$recent,$tier,$staffCount,$onboarding,$defaultCurrency.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
$ccy = htmlspecialchars((string)($defaultCurrency ?? 'NGN'));
$can = fn($m,$a) => agent_can($db, $m, $a);
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Welcome<?= !empty($owner['first_name']) ? ', '.htmlspecialchars((string)$owner['first_name']) : '' ?></h1>
            <p class="text-sm text-slate-500">Your agency workspace.</p>
        </div>
        <?php if (!empty($tier)): ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 text-amber-700 text-xs font-semibold">
            <span class="material-symbols-outlined text-[16px]">workspace_premium</span>
            <?= htmlspecialchars((string)$tier['name']) ?> · <?= $fmt($tier['discount_percent']) ?>% better rate
        </span>
        <?php endif; ?>
    </div>

    <?php if (!empty($pending)): ?>
    <div class="mb-5 px-4 py-3 rounded-lg bg-amber-50 text-amber-800 text-sm flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">hourglass_top</span>
        Your agency is <strong>pending admin approval</strong>. You can set up your profile and branding now; booking & funding unlock once approved.
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <?php // Low-balance nudge: spendable (wallet + credit line) is at/near zero.
    if (empty($pending) && ((float)$balance + (float)$creditLimit) <= 0): ?>
    <div class="mb-5 px-4 py-3 rounded-lg bg-amber-50 text-amber-800 text-sm flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
        Your wallet is empty. <a href="<?= root ?>agent/wallet" class="underline font-semibold ml-1">Fund your wallet</a> to start booking.
    </div>
    <?php endif; ?>

    <!-- Onboarding checklist (until complete) -->
    <?php if (empty($onboarding['complete'])): require views."agent/_onboarding.php"; endif; ?>

    <!-- KPI cards -->
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <a href="<?= root ?>agent/wallet" class="rounded-xl border border-slate-200 bg-white p-4 hover:shadow-sm transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Wallet</p>
            <p class="text-xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($balance) ?></p>
            <p class="text-[11px] text-slate-400 mt-1">+ <?= $ccy ?> <?= $fmt($creditLimit) ?> credit</p>
        </a>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Earnings (paid)</p>
            <p class="text-xl font-bold text-emerald-700 mt-1"><?= $ccy ?> <?= $fmt($paidEarning) ?></p>
        </div>
        <a href="<?= root ?>agent/bookings" class="rounded-xl border border-slate-200 bg-white p-4 hover:shadow-sm transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Bookings</p>
            <p class="text-xl font-bold text-slate-800 mt-1"><?= (int)$totalBookings ?></p>
        </a>
        <?php if (empty($viewingAsStaff)): ?>
        <a href="<?= root ?>agent/staff" class="rounded-xl border border-slate-200 bg-white p-4 hover:shadow-sm transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Active staff</p>
            <p class="text-xl font-bold text-slate-800 mt-1"><?= (int)$staffCount ?></p>
        </a>
        <?php endif; ?>
    </div>

    <!-- Quick actions -->
    <div class="flex flex-wrap gap-3 mb-6">
        <?php if ($can('bookings','add')): ?><a href="<?= root ?>agent/booking-centre" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700"><span class="material-symbols-outlined text-[18px]">add_shopping_cart</span> New booking</a><?php endif; ?>
        <?php if ($can('trips','add')): ?><a href="<?= root ?>agent/trips" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50"><span class="material-symbols-outlined text-[18px]">map</span> Build a trip plan</a><?php endif; ?>
        <?php if ($can('clients','add')): ?><a href="<?= root ?>agent/clients" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50"><span class="material-symbols-outlined text-[18px]">person_add</span> Add client</a><?php endif; ?>
    </div>

    <!-- Recent bookings -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Recent bookings</h2>
            <a href="<?= root ?>agent/bookings" class="text-xs text-violet-600 hover:underline">View all</a>
        </div>
        <?php if (empty($recent)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No bookings yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Invoice</th><th class="text-left px-5 py-2">Service</th><th class="text-left px-5 py-2">Status</th><th class="text-left px-5 py-2">Payment</th><th class="text-right px-5 py-2">Amount</th><th class="text-left px-5 py-2">Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $b): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 font-mono text-slate-700"><?= htmlspecialchars((string)($b['invoice_id']??'')) ?></td>
                        <td class="px-5 py-2 capitalize text-slate-600"><?= htmlspecialchars((string)($b['module_type']??'')) ?></td>
                        <td class="px-5 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= ($b['booking_status']??'')==='confirmed'?'bg-emerald-50 text-emerald-700':(($b['booking_status']??'')==='cancelled'?'bg-red-50 text-red-700':'bg-slate-100 text-slate-600') ?>"><?= htmlspecialchars((string)($b['booking_status']??'')) ?></span></td>
                        <td class="px-5 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= ($b['payment_status']??'')==='paid'?'bg-emerald-50 text-emerald-700':'bg-amber-50 text-amber-700' ?>"><?= htmlspecialchars((string)($b['payment_status']??'')) ?></span></td>
                        <td class="px-5 py-2 text-right text-slate-700"><?= htmlspecialchars((string)($b['currency_markup']??$ccy)) ?> <?= $fmt($b['price_markup']??0) ?></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($b['created_at']??'')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
