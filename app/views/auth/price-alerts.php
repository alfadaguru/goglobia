<?php
// ============================================================================
// CUSTOMER PRICE ALERTS PAGE — list / add / delete watches
// Provided by route: $alertsData, $dashboardData, $user, $db
// ============================================================================
@$SECURE or die('Access Denied!');

$__alerts    = $alertsData['alerts'] ?? [];
$__watchable = $alertsData['watchable'] ?? [];
$__ccy       = htmlspecialchars((string) ($alertsData['currency'] ?? 'USD'));

$statusChip = static function ($s) {
    switch (strtolower((string) $s)) {
        case 'triggered': return ['bg-emerald-100 text-emerald-800', 'Price dropped'];
        case 'paused':    return ['bg-slate-100 text-slate-600', 'Paused'];
        default:          return ['bg-blue-100 text-blue-700', 'Watching'];
    }
};
?>

<div class="bg" x-data="priceAlerts(<?= htmlspecialchars(json_encode($__alerts), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($__watchable), ENT_QUOTES) ?>, <?= json_encode($alertsData['currency'] ?? 'USD') ?>)">
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
                  <span class="material-symbols-outlined text-2xl text-slate-500">notifications_active</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900">Price Alerts</h1>
                  <p class="text-gray-600 text-sm">Watch a trip and we'll notify you when the price drops.</p>
               </div>
            </div>
            <button type="button" class="btn primary inline-flex items-center gap-2" @click="openAdd()" x-show="Object.keys(watchable).length">
               <span class="material-symbols-outlined text-xl">add_alert</span> Add a watch
            </button>
         </div>

         <div class="px-4 lg:px-6 pb-8">
            <template x-if="!list.length">
               <div class="text-center py-16 text-slate-400 border border-dashed border-slate-200 rounded-xl">
                  <span class="material-symbols-outlined text-5xl mb-3 block">query_stats</span>
                  <p class="font-medium text-slate-500">No price alerts yet</p>
                  <p class="text-sm mt-1">Add a watch for a flight, stay or trip — we'll ping your notifications when it gets cheaper.</p>
               </div>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
               <template x-for="a in list" :key="a.id">
                  <div class="border border-slate-200 rounded-xl p-4 bg-white">
                     <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                           <div class="text-xs uppercase tracking-wide text-slate-400 font-semibold" x-text="a.module"></div>
                           <div class="font-semibold text-slate-800 mt-0.5 truncate" x-text="a.label"></div>
                        </div>
                        <span class="text-[10px] px-2 py-0.5 rounded font-semibold shrink-0"
                              :class="a.status === 'triggered' ? 'bg-emerald-100 text-emerald-800' : (a.status === 'paused' ? 'bg-slate-100 text-slate-600' : 'bg-blue-100 text-blue-700')"
                              x-text="a.status === 'triggered' ? 'Price dropped' : (a.status === 'paused' ? 'Paused' : 'Watching')"></span>
                     </div>
                     <div class="flex items-end justify-between mt-3">
                        <div class="text-sm">
                           <div class="text-slate-500">You saw <span class="font-semibold text-slate-700 tabular-nums" x-text="a.currency + ' ' + Number(a.baseline_price).toFixed(2)"></span></div>
                           <div class="text-slate-500" x-show="a.target_price">Notify at or below <span class="font-semibold text-emerald-700 tabular-nums" x-text="a.currency + ' ' + Number(a.target_price).toFixed(2)"></span></div>
                           <div class="text-xs text-slate-400 mt-0.5" x-show="a.last_price">Last checked: <span x-text="a.currency + ' ' + Number(a.last_price).toFixed(2)"></span></div>
                        </div>
                        <button type="button" class="btn light btn-sm text-red-600 hover:bg-red-50" @click="remove(a)">Remove</button>
                     </div>
                  </div>
               </template>
            </div>

            <p class="text-[11px] text-slate-400 mt-5 flex items-start gap-1 max-w-2xl">
               <span class="material-symbols-outlined text-sm mt-0.5">info</span>
               <span>We check watched prices periodically and notify you (in your bell + email) the first time a price reaches your target or drops below what you saw. Live availability still applies at booking.</span>
            </p>
         </div>

      </div>
   </div>

   <!-- Add watch modal -->
   <div x-show="modal" x-cloak class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-4" style="display:none">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6" @click.outside="modal=false">
         <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg text-gray-900">Add a price watch</h3>
            <button type="button" @click="modal=false" class="text-slate-400 hover:text-slate-600"><span class="material-symbols-outlined">close</span></button>
         </div>
         <div class="space-y-3">
            <div>
               <label class="text-xs font-medium text-slate-600">What are you watching?</label>
               <select x-model="form.module" class="input w-full">
                  <option value="">Choose a service…</option>
                  <template x-for="(lbl, key) in watchable" :key="key">
                     <option :value="key" x-text="lbl"></option>
                  </template>
               </select>
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Describe it</label>
               <input type="text" x-model="form.label" class="input w-full" maxlength="255" placeholder="e.g. Lagos → London, 12–19 Dec">
            </div>
            <div class="grid grid-cols-2 gap-3">
               <div>
                  <label class="text-xs font-medium text-slate-600">Price you saw (<?= $__ccy ?>)</label>
                  <input type="number" min="0.01" step="0.01" x-model.number="form.baseline_price" class="input w-full" placeholder="0.00">
               </div>
               <div>
                  <label class="text-xs font-medium text-slate-600">Notify me at or below (optional)</label>
                  <input type="number" min="0.01" step="0.01" x-model.number="form.target_price" class="input w-full" placeholder="optional">
               </div>
            </div>
            <p class="text-sm text-red-600" x-show="err" x-text="err"></p>
            <div class="flex justify-end gap-2 pt-1">
               <button type="button" class="btn light" @click="modal=false">Cancel</button>
               <button type="button" class="btn primary inline-flex items-center gap-2" :disabled="busy || !form.module || !form.label.trim() || !(form.baseline_price > 0)" @click="save()">
                  <span class="material-symbols-outlined text-lg" x-show="!busy">add_alert</span>
                  <span x-text="busy ? 'Saving…' : 'Start watching'"></span>
               </button>
            </div>
         </div>
      </div>
   </div>
</div>

<script>
function priceAlerts(initial, watchable, ccy) {
   return {
      sidebarCollapsed: window.innerWidth < 1024,
      list: Array.isArray(initial) ? initial : [],
      watchable: watchable || {},
      ccy: ccy || 'USD',
      modal: false, busy: false, err: '',
      form: {},
      blank() { return { module:'', label:'', baseline_price:'', target_price:'' }; },
      openAdd() { this.form = this.blank(); this.err=''; this.modal = true; },
      async save() {
         if (!this.form.module || !this.form.label.trim() || !(this.form.baseline_price > 0)) return;
         if (this.form.target_price && this.form.target_price >= this.form.baseline_price) {
            this.err = 'Your target should be below the price you saw.'; return;
         }
         this.busy = true; this.err='';
         try {
            const res = await fetch('<?= root ?>price-alerts/create', {
               method:'POST',
               headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= CSRF::getToken() ?>'},
               body: JSON.stringify(Object.assign({ currency: this.ccy, csrf_token: '<?= CSRF::getToken() ?>' }, this.form))
            });
            const data = await res.json();
            if (data.ok) { this.list = data.alerts || []; this.modal = false; }
            else { this.err = data.message || 'Could not save.'; }
         } catch(e) { this.err = 'Network error. Please try again.'; }
         finally { this.busy = false; }
      },
      async remove(a) {
         if (!confirm('Stop watching "' + (a.label||'this') + '"?')) return;
         try {
            const res = await fetch('<?= root ?>price-alerts/delete', {
               method:'POST',
               headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= CSRF::getToken() ?>'},
               body: JSON.stringify({ id: a.id, csrf_token: '<?= CSRF::getToken() ?>' })
            });
            const data = await res.json();
            if (data.ok) this.list = data.alerts || [];
         } catch(e) {}
      }
   };
}
</script>
