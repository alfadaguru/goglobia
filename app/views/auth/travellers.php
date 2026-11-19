<?php
// ============================================================================
// CUSTOMER SAVED TRAVELLERS PAGE — list / add / edit / delete
// Provided by route: $travellersData, $dashboardData, $user, $db
// Backend: app/lib/ai/gia_travellers.php (user_travellers table).
// ============================================================================
@$SECURE or die('Access Denied!');

$__travellers = $travellersData['travellers'] ?? [];
?>

<div class="bg" x-data="savedTravellers(<?= htmlspecialchars(json_encode($__travellers), ENT_QUOTES) ?>)">
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
                  <span class="material-symbols-outlined text-2xl text-slate-500">group</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900">Saved Travellers</h1>
                  <p class="text-gray-600 text-sm">People you book for — saved once, re-used at checkout.</p>
               </div>
            </div>
            <button type="button" class="btn primary inline-flex items-center gap-2" @click="openAdd()">
               <span class="material-symbols-outlined text-xl">person_add</span> Add traveller
            </button>
         </div>

         <div class="px-4 lg:px-6 pb-8">
            <!-- Empty state -->
            <template x-if="!list.length">
               <div class="text-center py-16 text-slate-400 border border-dashed border-slate-200 rounded-xl">
                  <span class="material-symbols-outlined text-5xl mb-3 block">groups</span>
                  <p class="font-medium text-slate-500">No saved travellers yet</p>
                  <p class="text-sm mt-1">Add the people you travel with to skip re-typing their details every booking.</p>
               </div>
            </template>

            <!-- Traveller cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
               <template x-for="t in list" :key="t.id">
                  <div class="border border-slate-200 rounded-xl p-4 bg-white flex items-start justify-between gap-3">
                     <div class="min-w-0">
                        <div class="font-semibold text-slate-800 flex items-center gap-2">
                           <span x-text="[t.title, t.first_name, t.middle_name, t.last_name].filter(Boolean).join(' ')"></span>
                           <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-700" x-show="t.is_self == 1">You</span>
                        </div>
                        <div class="text-xs text-slate-500 mt-1 space-y-0.5">
                           <div x-show="t.nationality"><span class="text-slate-400">Nationality:</span> <span x-text="t.nationality"></span></div>
                           <div x-show="t.passport_number"><span class="text-slate-400">Passport:</span> <span x-text="maskPassport(t.passport_number)"></span></div>
                           <div x-show="t.dob"><span class="text-slate-400">DOB:</span> <span x-text="t.dob"></span></div>
                           <div x-show="t.email" class="truncate"><span class="text-slate-400">Email:</span> <span x-text="t.email"></span></div>
                        </div>
                     </div>
                     <div class="flex flex-col gap-1 shrink-0">
                        <button type="button" class="btn light btn-sm" @click="openEdit(t)">Edit</button>
                        <button type="button" class="btn light btn-sm text-red-600 hover:bg-red-50" @click="remove(t)">Remove</button>
                     </div>
                  </div>
               </template>
            </div>
         </div>

      </div>
   </div>

   <!-- Add / edit modal -->
   <div x-show="modal" x-cloak class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-4" style="display:none">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" @click.outside="modal=false">
         <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg text-gray-900" x-text="form.id ? 'Edit traveller' : 'Add traveller'"></h3>
            <button type="button" @click="modal=false" class="text-slate-400 hover:text-slate-600"><span class="material-symbols-outlined">close</span></button>
         </div>
         <div class="grid grid-cols-2 gap-3">
            <div>
               <label class="text-xs font-medium text-slate-600">Title</label>
               <select x-model="form.title" class="input w-full">
                  <option value="">—</option><option>Mr</option><option>Mrs</option><option>Ms</option><option>Dr</option><option>Master</option><option>Miss</option>
               </select>
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Gender</label>
               <select x-model="form.gender" class="input w-full">
                  <option value="">—</option><option value="male">Male</option><option value="female">Female</option>
               </select>
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">First name *</label>
               <input type="text" x-model="form.first_name" class="input w-full" maxlength="120">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Last name</label>
               <input type="text" x-model="form.last_name" class="input w-full" maxlength="120">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Date of birth</label>
               <input type="date" x-model="form.dob" class="input w-full">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Nationality</label>
               <input type="text" x-model="form.nationality" class="input w-full" maxlength="80" placeholder="e.g. Nigerian">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Passport number</label>
               <input type="text" x-model="form.passport_number" class="input w-full" maxlength="64">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Passport expiry</label>
               <input type="date" x-model="form.passport_expiry" class="input w-full">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Email</label>
               <input type="email" x-model="form.email" class="input w-full" maxlength="255">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Phone</label>
               <input type="text" x-model="form.phone" class="input w-full" maxlength="40">
            </div>
            <div>
               <label class="text-xs font-medium text-slate-600">Relationship</label>
               <input type="text" x-model="form.relationship" class="input w-full" maxlength="40" placeholder="e.g. Spouse, Child">
            </div>
            <div class="flex items-end">
               <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" x-model="form.is_self"> This is me
               </label>
            </div>
         </div>
         <p class="text-sm text-red-600 mt-3" x-show="err" x-text="err"></p>
         <div class="flex justify-end gap-2 mt-5">
            <button type="button" class="btn light" @click="modal=false">Cancel</button>
            <button type="button" class="btn primary inline-flex items-center gap-2" :disabled="busy || !form.first_name.trim()" @click="save()">
               <span class="material-symbols-outlined text-lg" x-show="!busy">save</span>
               <span x-text="busy ? 'Saving…' : 'Save traveller'"></span>
            </button>
         </div>
      </div>
   </div>
</div>

<script>
function savedTravellers(initial) {
   return {
      sidebarCollapsed: window.innerWidth < 1024,
      list: Array.isArray(initial) ? initial : [],
      modal: false, busy: false, err: '',
      form: {},
      blank() { return { id: 0, title:'', first_name:'', middle_name:'', last_name:'', gender:'', dob:'', nationality:'', passport_number:'', passport_expiry:'', email:'', phone:'', relationship:'', is_self:false }; },
      maskPassport(p) { p = String(p||''); return p.length > 4 ? ('••••' + p.slice(-4)) : p; },
      openAdd() { this.form = this.blank(); this.err=''; this.modal = true; },
      openEdit(t) {
         this.form = Object.assign(this.blank(), {
            id: t.id, title: t.title||'', first_name: t.first_name||'', middle_name: t.middle_name||'',
            last_name: t.last_name||'', gender: t.gender||'', dob: t.dob||'', nationality: t.nationality||'',
            passport_number: t.passport_number||'', passport_expiry: t.passport_expiry||'',
            email: t.email||'', phone: t.phone||'', relationship: t.relationship||'', is_self: t.is_self == 1
         });
         this.err=''; this.modal = true;
      },
      async save() {
         if (!this.form.first_name.trim()) return;
         this.busy = true; this.err='';
         try {
            const res = await fetch('<?= root ?>travellers/save', {
               method:'POST',
               headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= CSRF::getToken() ?>'},
               body: JSON.stringify(Object.assign({}, this.form, { csrf_token: '<?= CSRF::getToken() ?>' }))
            });
            const data = await res.json();
            if (data.ok) { this.list = data.travellers || []; this.modal = false; }
            else { this.err = data.message || 'Could not save.'; }
         } catch(e) { this.err = 'Network error. Please try again.'; }
         finally { this.busy = false; }
      },
      async remove(t) {
         if (!confirm('Remove ' + (t.first_name||'this traveller') + '?')) return;
         try {
            const res = await fetch('<?= root ?>travellers/delete', {
               method:'POST',
               headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= CSRF::getToken() ?>'},
               body: JSON.stringify({ id: t.id, csrf_token: '<?= CSRF::getToken() ?>' })
            });
            const data = await res.json();
            if (data.ok) this.list = data.travellers || [];
         } catch(e) { /* leave list as-is on network error */ }
      }
   };
}
</script>
