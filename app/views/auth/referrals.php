<?php
// app/views/auth/referrals.php — customer "Refer & Earn" page.
@$SECURE or die('Access Denied!');
$d   = $referralData ?? [];
$cfg = $d['cfg'] ?? ['enabled' => false];
$ccy = htmlspecialchars((string) ($cfg['currency'] ?? 'NGN'));
$referrerAmt = number_format((float) ($cfg['referrer_amount'] ?? 0), 0);
$refereeAmt  = number_format((float) ($cfg['referee_amount'] ?? 0), 0);
$code = htmlspecialchars((string) ($d['code'] ?? ''));
$link = htmlspecialchars((string) ($d['link'] ?? ''));
$statusChip = function (string $s): string {
    $map = ['pending'=>'bg-gray-100 text-gray-600','qualified'=>'bg-blue-100 text-blue-700','hold'=>'bg-amber-100 text-amber-800','rewarded'=>'bg-green-100 text-green-800','reversed'=>'bg-red-100 text-red-700'];
    $label = ['pending'=>'Invited','qualified'=>'Booked','hold'=>'Reward pending','rewarded'=>'Rewarded','reversed'=>'Cancelled'];
    $k = strtolower($s);
    return '<span class="inline-flex px-2 py-0.5 text-xs rounded-full '.($map[$k]??'bg-gray-100 text-gray-600').'">'.htmlspecialchars($label[$k]??ucfirst($s)).'</span>';
};
?>
<div class="container my-6 max-w-3xl mx-auto px-4" x-data="{ copied:false, copy(t){ navigator.clipboard.writeText(t).then(()=>{ this.copied=true; setTimeout(()=>this.copied=false,1800); }); } }">

<?php if (empty($cfg['enabled'])): ?>
    <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
        <span class="material-symbols-outlined text-gray-300 text-5xl">share</span>
        <h1 class="text-lg font-bold text-gray-800 mt-2">Refer &amp; Earn is coming soon</h1>
        <p class="text-sm text-gray-500 mt-1">Our referral program isn't live yet. Check back soon to start earning wallet credit for inviting friends.</p>
    </div>
<?php else: ?>

    <!-- Hero -->
    <div class="rounded-2xl p-6 text-white mb-5" style="background:linear-gradient(120deg,#172554,#2563eb)">
        <div class="flex items-center gap-2 text-blue-200 text-xs font-bold uppercase tracking-wider">
            <span class="material-symbols-outlined !text-[18px]">redeem</span> Refer &amp; Earn
        </div>
        <h1 class="text-2xl font-extrabold mt-1">Give <?= $ccy ?> <?= $refereeAmt ?>, get <?= $ccy ?> <?= $referrerAmt ?></h1>
        <p class="text-blue-100 text-sm mt-1">
            Share your link. Your friend gets <strong><?= $ccy ?> <?= $refereeAmt ?></strong> off their first booking,
            and you get <strong><?= $ccy ?> <?= $referrerAmt ?></strong> in wallet credit once they travel.
        </p>
    </div>

    <!-- Code + share -->
    <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Your referral code</label>
        <div class="flex items-center gap-2 mb-4">
            <div class="flex-1 font-mono text-lg font-extrabold tracking-wider text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-4 py-2.5"><?= $code ?></div>
            <button type="button" @click="copy('<?= $code ?>')" class="btn-outline px-3 py-2.5 rounded-lg border border-gray-200 text-sm font-semibold inline-flex items-center gap-1.5 hover:bg-gray-50">
                <span class="material-symbols-outlined !text-[18px]">content_copy</span> Copy
            </button>
        </div>

        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Your invite link</label>
        <div class="flex items-center gap-2">
            <div class="flex-1 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 truncate"><?= $link ?></div>
            <button type="button" @click="copy('<?= $link ?>')" class="btn px-3 py-2.5 rounded-lg text-sm font-semibold inline-flex items-center gap-1.5">
                <span class="material-symbols-outlined !text-[18px]" x-text="copied ? 'check' : 'content_copy'"></span>
                <span x-text="copied ? 'Copied!' : 'Copy link'"></span>
            </button>
        </div>

        <div class="flex flex-wrap gap-2 mt-4">
            <a href="https://wa.me/?text=<?= rawurlencode('Book your next trip on GoGlobia and get '.$ccy.' '.$refereeAmt.' off your first booking with my link: '.($d['link'] ?? '')) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700">
                <span class="material-symbols-outlined !text-[18px]">chat</span> WhatsApp
            </a>
            <a href="mailto:?subject=<?= rawurlencode('Get '.$ccy.' '.$refereeAmt.' off GoGlobia') ?>&body=<?= rawurlencode('Use my link to get '.$ccy.' '.$refereeAmt.' off your first GoGlobia booking: '.($d['link'] ?? '')) ?>"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <span class="material-symbols-outlined !text-[18px]">mail</span> Email
            </a>
        </div>
    </div>

    <!-- Earnings -->
    <div class="grid grid-cols-2 gap-3 mb-5">
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Pending</div>
            <div class="text-xl font-extrabold text-amber-800 mt-1 tabular-nums"><?= $ccy ?> <?= number_format((float) ($d['pending'] ?? 0), 2) ?></div>
            <div class="text-[11px] text-amber-600/80 mt-0.5">Awaiting your friends' trips</div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-2xl p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-green-700">Earned</div>
            <div class="text-xl font-extrabold text-green-800 mt-1 tabular-nums"><?= $ccy ?> <?= number_format((float) ($d['earned'] ?? 0), 2) ?></div>
            <div class="text-[11px] text-green-600/80 mt-0.5">Credited to your wallet</div>
        </div>
    </div>

    <!-- How it works -->
    <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-5">
        <h2 class="text-sm font-bold text-gray-900 mb-3">How it works</h2>
        <ol class="space-y-2 text-sm text-gray-600">
            <li class="flex gap-2"><span class="material-symbols-outlined text-blue-600 !text-[18px]">share</span> Share your link with friends.</li>
            <li class="flex gap-2"><span class="material-symbols-outlined text-blue-600 !text-[18px]">person_add</span> They sign up and get <strong><?= $ccy ?> <?= $refereeAmt ?></strong> off their first booking.</li>
            <li class="flex gap-2"><span class="material-symbols-outlined text-blue-600 !text-[18px]">flight_takeoff</span> When they complete that first booking, you earn <strong><?= $ccy ?> <?= $referrerAmt ?></strong>.</li>
            <li class="flex gap-2"><span class="material-symbols-outlined text-blue-600 !text-[18px]">account_balance_wallet</span> Your reward lands in your wallet after a short confirmation period.</li>
        </ol>
    </div>

    <!-- History -->
    <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <h2 class="text-sm font-bold text-gray-900 mb-3">Your invites</h2>
        <?php $rows = $d['referrals'] ?? []; if (empty($rows)): ?>
            <p class="text-sm text-gray-400 py-3 text-center">No invites yet — share your link to get started!</p>
        <?php else: ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($rows as $r): ?>
                    <div class="flex items-center justify-between py-2.5">
                        <div class="text-sm text-gray-600">A friend joined with your code</div>
                        <div class="flex items-center gap-3">
                            <?= $statusChip((string) ($r['status'] ?? '')) ?>
                            <span class="text-xs text-gray-400"><?= htmlspecialchars(date('d M Y', strtotime((string) ($r['created_at'] ?? 'now')))) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>
</div>
