<?php
// ============================================================================
// CUSTOMER LOYALTY PAGE — balance, redeem-to-wallet, points ledger
// Provided by route: $loyaltyData, $dashboardData, $user, $db
// ============================================================================
@$SECURE or die('Access Denied!');

$reasonLabels = [
    'booking'           => 'Earned on booking',
    'earn'              => 'Points earned',
    'convert_to_wallet' => 'Converted to wallet',
    'redeem'            => 'Redeemed',
    'convert_rollback'  => 'Conversion reversed',
    'reverse'           => 'Reversed (refund)',
    'adjust'            => 'Adjustment',
];
$prettyReason = static function ($r) use ($reasonLabels) {
    $r = strtolower(trim((string) $r));
    return $r === '' ? 'Activity' : ($reasonLabels[$r] ?? ucwords(str_replace(['_', '-'], ' ', $r)));
};
$redeemValue = (float) $loyaltyData['redeem_value'];
$points      = (int) $loyaltyData['points'];
?>

<div class="bg" x-data="loyaltyPage({ points: <?= $points ?>, redeemValue: <?= json_encode($redeemValue) ?>, currency: <?= json_encode($loyaltyData['currency']) ?> })">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views . 'auth/sidebar.php'; ?>
      </div>

      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- Header -->
         <div class="flex items-center justify-between mb-6 px-4 lg:px-6">
            <div class="flex items-center gap-4">
               <button @click="$dispatch('open-sidebar')" class="lg:hidden flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg text-slate-500">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg">
                  <span class="material-symbols-outlined text-2xl text-slate-500">redeem</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900"><?= 'Loyalty' ?></h1>
                  <p class="text-gray-600 text-sm"><?= 'Earn points on every booking, redeem them for wallet credit.' ?></p>
               </div>
            </div>
         </div>

         <!-- Balance + redeem -->
         <div class="px-4 lg:px-6 mb-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Balance card -->
            <div class="rounded-xl border border-slate-200 bg-gradient-to-br from-indigo-50 to-white p-5">
               <div class="text-xs font-medium text-indigo-500 mb-1 flex items-center gap-1">
                  <span class="material-symbols-outlined text-base">stars</span> <?= T::loyalty_points ?? 'Loyalty Points' ?>
               </div>
               <div class="text-3xl font-bold text-slate-800 tabular-nums" x-text="points.toLocaleString()"></div>
               <div class="text-sm text-slate-500 mt-1">
                  <?= 'Worth' ?>
                  <span class="font-semibold text-indigo-600"><?= htmlspecialchars($loyaltyData['currency']) ?> <span x-text="worth()"></span></span>
                  <?= 'in wallet credit' ?>
               </div>
            </div>

            <!-- Redeem card -->
            <div class="rounded-xl border border-slate-200 bg-white p-5">
               <div class="text-xs font-medium text-slate-500 mb-2 flex items-center gap-1">
                  <span class="material-symbols-outlined text-base">account_balance_wallet</span> <?= 'Redeem to wallet' ?>
               </div>
               <?php if ($points <= 0 || $redeemValue <= 0): ?>
                  <p class="text-sm text-slate-400 py-3"><?= 'Earn points on your next booking to start redeeming.' ?></p>
               <?php else: ?>
                  <div class="flex flex-wrap items-end gap-3">
                     <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600"><?= 'Points to redeem' ?></label>
                        <input type="number" min="1" :max="points" step="1" x-model.number="redeem" class="input w-40" placeholder="0">
                     </div>
                     <button type="button" class="btn primary inline-flex items-center gap-2" :disabled="busy || redeem < 1 || redeem > points" @click="doRedeem()">
                        <span class="material-symbols-outlined text-xl" x-show="!busy">swap_horiz</span>
                        <span x-text="busy ? '<?= T::processing ?? 'Processing…' ?>' : '<?= 'Redeem' ?>'"></span>
                     </button>
                  </div>
                  <p class="text-[11px] text-slate-400 mt-2" x-show="redeem > 0">
                     ≈ <?= htmlspecialchars($loyaltyData['currency']) ?> <span x-text="(redeem * redeemValue).toFixed(2)"></span> <?= 'wallet credit' ?>
                  </p>
                  <p class="text-sm mt-2" :class="msgOk ? 'text-emerald-600' : 'text-red-600'" x-show="msg" x-text="msg"></p>
               <?php endif; ?>
            </div>
         </div>

         <!-- Ledger -->
         <div class="px-4 lg:px-6 pb-8">
            <h3 class="font-semibold text-gray-900 mb-3"><?= 'Points history' ?></h3>
            <?php if (empty($loyaltyData['history'])): ?>
               <div class="text-center py-12 text-slate-400">
                  <span class="material-symbols-outlined text-5xl mb-3 block">history</span>
                  <p class="font-medium text-slate-500"><?= 'No points activity yet' ?></p>
               </div>
            <?php else: ?>
               <div class="overflow-x-auto border border-slate-200 rounded-lg">
                  <table class="w-full text-sm" style="min-width:560px">
                     <thead>
                        <tr class="bg-slate-50 text-left text-slate-500 text-xs uppercase tracking-wide">
                           <th class="py-3 px-4 font-semibold"><?= T::date ?? 'Date' ?></th>
                           <th class="py-3 px-4 font-semibold"><?= T::activity ?? 'Activity' ?></th>
                           <th class="py-3 px-4 font-semibold text-right"><?= 'Points' ?></th>
                           <th class="py-3 px-4 font-semibold text-right"><?= T::balance ?? 'Balance' ?></th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($loyaltyData['history'] as $h):
                           $isEarn = strtolower((string) ($h['direction'] ?? '')) === 'earn';
                           $pts = (int) ($h['points'] ?? 0);
                        ?>
                        <tr class="hover:bg-slate-50/60">
                           <td class="py-3 px-4 text-slate-600 whitespace-nowrap tabular-nums"><?= htmlspecialchars(date('d M Y', strtotime((string) ($h['created_at'] ?? 'now')))) ?></td>
                           <td class="py-3 px-4">
                              <div class="font-medium text-slate-800"><?= htmlspecialchars($prettyReason($h['reason'] ?? '')) ?></div>
                              <?php if (!empty($h['note'])): ?><div class="text-xs text-slate-400"><?= htmlspecialchars((string) $h['note']) ?></div><?php endif; ?>
                           </td>
                           <td class="py-3 px-4 text-right tabular-nums font-semibold <?= $isEarn ? 'text-emerald-600' : 'text-slate-700' ?>">
                              <?= $isEarn ? '+' : '−' ?><?= number_format($pts) ?>
                           </td>
                           <td class="py-3 px-4 text-right tabular-nums text-slate-500"><?= number_format((int) ($h['balance_after'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
               <?php if ($loyaltyData['total_pages'] > 1): ?>
                  <div class="flex items-center justify-between mt-4 text-sm">
                     <span class="text-slate-500"><?= T::page ?? 'Page' ?> <?= (int) $loyaltyData['page'] ?> / <?= (int) $loyaltyData['total_pages'] ?></span>
                     <div class="flex gap-2">
                        <?php if ($loyaltyData['page'] > 1): ?><a href="<?= root ?>loyalty?page=<?= $loyaltyData['page'] - 1 ?>" class="btn light btn-sm"><?= T::previous ?? 'Previous' ?></a><?php endif; ?>
                        <?php if ($loyaltyData['page'] < $loyaltyData['total_pages']): ?><a href="<?= root ?>loyalty?page=<?= $loyaltyData['page'] + 1 ?>" class="btn light btn-sm"><?= T::next ?? 'Next' ?></a><?php endif; ?>
                     </div>
                  </div>
               <?php endif; ?>
            <?php endif; ?>
         </div>

      </div>
   </div>
</div>

<script>
function loyaltyPage(init) {
   return {
      points: init.points,
      redeemValue: init.redeemValue,
      currency: init.currency,
      redeem: '',
      busy: false,
      msg: '',
      msgOk: false,
      worth() { return (this.points * this.redeemValue).toFixed(2); },
      async doRedeem() {
         const pts = parseInt(this.redeem, 10);
         if (!pts || pts < 1 || pts > this.points) { return; }
         this.busy = true; this.msg = '';
         try {
            const res = await fetch('<?= root ?>loyalty/redeem', {
               method: 'POST',
               headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>' },
               body: JSON.stringify({ points: pts, csrf_token: '<?= CSRF::getToken() ?>' })
            });
            const data = await res.json();
            if (data.success) {
               this.points = parseInt(data.points_left, 10);
               this.redeem = '';
               this.msgOk = true;
               this.msg = '<?= 'Redeemed to your wallet.' ?>';
            } else {
               this.msgOk = false;
               this.msg = data.message || '<?= 'Could not redeem. Please try again.' ?>';
            }
         } catch (e) {
            this.msgOk = false;
            this.msg = '<?= 'Could not redeem. Please try again.' ?>';
         } finally {
            this.busy = false;
         }
      }
   };
}
</script>
