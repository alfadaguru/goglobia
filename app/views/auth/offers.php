<?php
// ============================================================================
// CUSTOMER OFFERS PAGE — check a promo code + browse advertised offers
// Provided by route: $offersData, $dashboardData, $user, $db
// ============================================================================
@$SECURE or die('Access Denied!');

$fmtDiscount = static function ($row) {
    $type = strtolower((string) ($row['discount_type'] ?? ''));
    $val  = (float) ($row['discount_value'] ?? 0);
    if ($type === 'percentage') { return rtrim(rtrim(number_format($val, 2), '0'), '.') . '% off'; }
    $cur = htmlspecialchars((string) ($row['currency'] ?? ''));
    return $cur . ' ' . number_format($val, 2) . ' off';
};
?>

<div class="bg" x-data="{ sidebarCollapsed: window.innerWidth < 1024 }">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views . 'auth/sidebar.php'; ?>
      </div>

      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- Header -->
         <div class="flex items-center justify-between mb-6 px-4 lg:px-6">
            <div class="flex items-center gap-4">
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg text-slate-500">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg">
                  <span class="material-symbols-outlined text-2xl text-slate-500">local_offer</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900"><?= 'Offers' ?></h1>
                  <p class="text-gray-600 text-sm"><?= 'Check a promo code or browse current offers. Valid codes apply automatically at checkout.' ?></p>
               </div>
            </div>
         </div>

         <!-- Check a code -->
         <div class="px-4 lg:px-6 mb-6 max-w-xl" x-data="offersCheck()">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
               <label class="text-xs font-medium text-slate-600 block mb-2"><?= 'Have a promo code?' ?></label>
               <div class="flex flex-wrap items-center gap-3">
                  <input type="text" x-model="code" @keydown.enter="check()" class="input flex-1 min-w-[180px] uppercase" placeholder="<?= T::enter_code ?? 'Enter code' ?>">
                  <button type="button" class="btn primary inline-flex items-center gap-2" :disabled="busy || !code.trim()" @click="check()">
                     <span class="material-symbols-outlined text-xl" x-show="!busy">check</span>
                     <span x-text="busy ? '<?= T::checking ?? 'Checking…' ?>' : '<?= 'Check' ?>'"></span>
                  </button>
               </div>
               <p class="text-sm mt-3 flex items-center gap-1" :class="ok ? 'text-emerald-600' : 'text-red-600'" x-show="msg">
                  <span class="material-symbols-outlined text-base" x-text="ok ? 'check_circle' : 'error'"></span>
                  <span x-text="msg"></span>
               </p>
            </div>
         </div>

         <!-- Advertised offers -->
         <div class="px-4 lg:px-6 pb-8">
            <h3 class="font-semibold text-gray-900 mb-3"><?= 'Current offers' ?></h3>
            <?php if (empty($offersData['offers'])): ?>
               <div class="text-center py-12 text-slate-400">
                  <span class="material-symbols-outlined text-5xl mb-3 block">sell</span>
                  <p class="font-medium text-slate-500"><?= 'No offers right now' ?></p>
                  <p class="text-sm mt-1"><?= 'Check back soon - new deals are added regularly.' ?></p>
               </div>
            <?php else: ?>
               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <?php foreach ($offersData['offers'] as $o): ?>
                     <div class="border border-slate-200 rounded-xl p-5 bg-gradient-to-br from-amber-50 to-white">
                        <div class="flex items-start justify-between gap-3">
                           <div>
                              <div class="text-lg font-bold text-amber-800"><?= htmlspecialchars($fmtDiscount($o)) ?></div>
                              <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars((string) ($o['description'] ?? '')) ?></p>
                           </div>
                           <span class="material-symbols-outlined text-amber-400">local_offer</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                           <span class="font-mono text-sm font-bold bg-white border border-amber-200 rounded px-2 py-1 text-amber-900"><?= htmlspecialchars((string) ($o['code'] ?? '')) ?></span>
                           <?php if (!empty($o['end_date'])): ?>
                              <span class="text-xs text-slate-400"><?= 'Until' ?> <?= htmlspecialchars(date('d M Y', strtotime((string) $o['end_date']))) ?></span>
                           <?php endif; ?>
                        </div>
                        <?php if (!empty($o['min_order_amount']) && (float) $o['min_order_amount'] > 0): ?>
                           <p class="text-[11px] text-slate-400 mt-2"><?= 'Min. spend' ?> <?= htmlspecialchars((string) ($o['currency'] ?: $offersData['currency'])) ?> <?= number_format((float) $o['min_order_amount'], 2) ?></p>
                        <?php endif; ?>
                     </div>
                  <?php endforeach; ?>
               </div>
            <?php endif; ?>
         </div>

      </div>
   </div>
</div>

<script>
function offersCheck() {
   return {
      code: '', busy: false, ok: false, msg: '',
      async check() {
         const code = this.code.trim();
         if (!code) return;
         this.busy = true; this.msg = '';
         try {
            const res = await fetch('<?= root ?>offers/check', {
               method: 'POST',
               headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>' },
               body: JSON.stringify({ code: code, csrf_token: '<?= CSRF::getToken() ?>' })
            });
            const data = await res.json();
            this.ok = !!data.ok;
            this.msg = data.message || (data.ok ? 'Valid.' : 'Not valid.');
         } catch (e) {
            this.ok = false;
            this.msg = '<?= 'Could not check the code. Please try again.' ?>';
         } finally {
            this.busy = false;
         }
      }
   };
}
</script>
