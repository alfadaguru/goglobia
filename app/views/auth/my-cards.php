<?php
// ============================================================================
// CUSTOMER PAYMENT METHODS PAGE — view / add / set-default / remove saved cards
// Provided by route: $dashboardData (incl. saved_cards), $user, $db
// Reuses the SAME savedCards() Alpine contract + /api/cards/* endpoints the
// dashboard uses (one source of truth; no duplicate money logic).
// ============================================================================
@$SECURE or die('Access Denied!');

$__cards   = $dashboardData['saved_cards'] ?? [];
$__isNgn   = strtoupper((string) ($dashboardData['display_currency'] ?? 'USD')) === 'NGN';
$__curr    = htmlspecialchars((string) ($dashboardData['display_currency'] ?? 'USD'));
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
                  <span class="material-symbols-outlined text-2xl text-slate-500">credit_card</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900"><?= T::payment_methods ?? 'Payment Methods' ?></h1>
                  <p class="text-gray-600 text-sm"><?= 'Manage the cards saved to your account for faster checkout.' ?></p>
               </div>
            </div>
         </div>

         <div class="px-4 lg:px-6 pb-8 max-w-2xl"
              x-data="savedCards(<?= htmlspecialchars(json_encode($__cards), ENT_QUOTES) ?>, <?= $__isNgn ? 'true' : 'false' ?>)">

            <!-- Saved card list -->
            <template x-if="cards.length">
               <div class="space-y-3 mb-6">
                  <template x-for="c in cards" :key="c.id">
                     <div class="flex items-center justify-between border border-slate-200 rounded-xl p-4 bg-white">
                        <div class="flex items-center gap-3">
                           <span class="material-symbols-outlined text-slate-400">credit_card</span>
                           <div>
                              <div class="font-medium text-slate-800 flex items-center gap-2">
                                 <span x-text="(c.brand||'Card').toUpperCase() + ' •••• ' + (c.last4||'')"></span>
                                 <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-700" x-show="c.is_default"><?= T::default ?? 'Default' ?></span>
                                 <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700" x-show="c.expired"><?= T::expired ?? 'Expired' ?></span>
                              </div>
                              <div class="text-xs text-slate-400">
                                 <span x-text="c.currency"></span> · <?= T::expires ?? 'Expires' ?> <span x-text="c.exp || '—'"></span>
                              </div>
                           </div>
                        </div>
                        <div class="flex items-center gap-2">
                           <button type="button" class="btn light btn-sm" x-show="!c.is_default" @click="setDefault(c.id)"><?= 'Make default' ?></button>
                           <button type="button" class="btn light btn-sm text-red-600 hover:bg-red-50" @click="removeCard(c.id)"><?= T::remove ?? 'Remove' ?></button>
                        </div>
                     </div>
                  </template>
               </div>
            </template>

            <template x-if="!cards.length && !adding">
               <div class="text-center py-12 text-slate-400 border border-dashed border-slate-200 rounded-xl mb-6">
                  <span class="material-symbols-outlined text-5xl mb-3 block">credit_card_off</span>
                  <p class="font-medium text-slate-500"><?= 'No saved cards yet' ?></p>
                  <p class="text-sm mt-1"><?= 'Add a card for faster checkout and one-tap wallet top-ups.' ?></p>
               </div>
            </template>

            <!-- Add card trigger -->
            <template x-if="!adding">
               <button type="button" class="btn primary inline-flex items-center gap-2" @click="startAdd()">
                  <span class="material-symbols-outlined text-xl">add</span> <?= 'Add a card' ?>
               </button>
            </template>

            <!-- Add card panel -->
            <div x-show="adding" x-cloak class="border border-slate-200 rounded-xl p-5 mt-2">
               <?php if ($__isNgn): ?>
                  <!-- NGN: Paystack has no zero-charge card save; a card is saved when a
                       top-up succeeds. Route the customer to the wallet top-up. -->
                  <p class="text-sm text-slate-600 mb-3"><?= 'To save a card in NGN, make a wallet top-up - your card is stored automatically on a successful payment.' ?></p>
                  <div class="flex gap-2">
                     <a href="<?= root ?>dashboard#wallet" class="btn primary inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl">account_balance_wallet</span> <?= 'Go to wallet top-up' ?>
                     </a>
                     <button type="button" class="btn light" @click="cancelAdd()"><?= T::cancel ?? 'Cancel' ?></button>
                  </div>
               <?php else: ?>
                  <label class="text-xs font-medium text-slate-600 block mb-2"><?= 'Card details' ?></label>
                  <div id="card-element" class="border border-slate-200 rounded-lg p-3 bg-white"></div>
                  <p class="text-sm text-red-600 mt-2" x-show="err" x-text="err"></p>
                  <div class="flex gap-2 mt-3">
                     <button type="button" class="btn primary inline-flex items-center gap-2" :disabled="busy" @click="saveCard()">
                        <span class="material-symbols-outlined text-xl" x-show="!busy">lock</span>
                        <span x-text="busy ? '<?= T::saving ?? 'Saving…' ?>' : '<?= 'Save card' ?>'"></span>
                     </button>
                     <button type="button" class="btn light" @click="cancelAdd()"><?= T::cancel ?? 'Cancel' ?></button>
                  </div>
                  <p class="text-[11px] text-slate-400 mt-3 flex items-center gap-1">
                     <span class="material-symbols-outlined text-sm">shield</span>
                     <?= 'Your card is tokenised and stored securely by the payment provider - we never see or keep the full number.' ?>
                  </p>
               <?php endif; ?>
            </div>
         </div>

      </div>
   </div>
</div>

<!-- Stripe.js + the shared savedCards() component (identical contract to the dashboard). -->
<script src="https://js.stripe.com/v3/"></script>
<script>
function savedCards(initial, isNgn){
   const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
   const ROOT = '<?= root ?>';
   return {
      cards: initial || [], isNgn: !!isNgn, adding:false, busy:false, err:'',
      _stripe:null, _elements:null, _card:null, _clientSecret:'', _setupId:'',
      _post(url, body){
         return fetch(ROOT+url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},credentials:'same-origin',body:JSON.stringify(body||{})}).then(r=>r.json());
      },
      async startAdd(){
         this.err=''; this.adding=true;
         if(this.isNgn){ this.busy=false; return; }
         this.busy=true;
         try{
            const d = await this._post('api/cards/setup');
            if(!d.success){ this.err=d.message||'Could not start.'; this.adding=false; this.busy=false; return; }
            this._clientSecret=d.client_secret; this._setupId=d.setup_intent_id;
            this._stripe = Stripe(d.publishable_key);
            this._elements = this._stripe.elements();
            this._card = this._elements.create('card');
            this.$nextTick(()=>{ this._card.mount('#card-element'); this.busy=false; });
         }catch(e){ this.err='Network error.'; this.adding=false; this.busy=false; }
      },
      async saveCard(){
         if(!this._card) return; this.busy=true; this.err='';
         const {error,setupIntent} = await this._stripe.confirmCardSetup(this._clientSecret,{payment_method:{card:this._card}});
         if(error){ this.err=error.message||'Card was declined.'; this.busy=false; return; }
         const d = await this._post('api/cards/save',{setup_intent_id:setupIntent.id});
         if(d.success){ this.cards=d.cards; this.cancelAdd(); } else { this.err=d.message||'Could not save.'; this.busy=false; }
      },
      cancelAdd(){ try{this._card&&this._card.unmount();}catch(e){}; this.adding=false; this.busy=false; this.err=''; this._card=null; },
      async setDefault(id){ const d=await this._post('api/cards/'+id+'/default'); if(d.success) this.cards=d.cards; },
      async removeCard(id){ if(!confirm('<?= 'Remove this card?' ?>'))return; const d=await this._post('api/cards/'+id+'/delete'); if(d.success) this.cards=d.cards; },
   };
}
</script>
