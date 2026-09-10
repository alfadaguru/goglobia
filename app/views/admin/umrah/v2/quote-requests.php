<?php
// UMRAH v2 admin — Customize quote-request inbox. Expects: $requests, $counts.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$csrf = $_SESSION['csrf_token'] ?? (class_exists('CSRF') ? CSRF::getToken() : '');
$statusBadge = [
  'new' => 'badge-warning', 'in_review' => 'badge-gray', 'quoted' => 'badge-success',
  'converted' => 'badge-success', 'closed' => 'badge-error',
];
$cur = $_GET['status'] ?? '';
?>
<div class="container py-6" x-data="umrahQuoteInbox()">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Customize Quote Requests</h1>
      <p class="text-sm text-slate-600 mt-1">Personalized Umrah requests from customers. Review and respond with a tailored quote.</p>
    </div>
    <a href="<?= root ?>admin/umrah-manager" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Manager</span></a>
  </div>

  <!-- Status filter tabs -->
  <div class="flex flex-wrap gap-2 mb-4">
    <a href="?" class="badge <?= $cur==='' ? 'badge-primary' : 'badge-gray' ?>">All</a>
    <?php foreach (['new','in_review','quoted','converted','closed'] as $s): ?>
      <a href="?status=<?= $s ?>" class="badge <?= $cur===$s ? 'badge-primary' : 'badge-gray' ?>">
        <?= ucfirst(str_replace('_',' ',$s)) ?> (<?= (int) ($counts[$s] ?? 0) ?>)
      </a>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Ref</th><th>Customer</th><th>Trip</th><th>Pax</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr><td colspan="7" class="text-slate-500">No quote requests<?= $cur ? ' with status “'.htmlspecialchars($cur).'”' : '' ?> yet.</td></tr>
          <?php else: foreach ($requests as $r):
            $ziy = json_decode((string)($r['ziyarah'] ?? ''), true) ?: [];
            $add = json_decode((string)($r['addons'] ?? ''), true) ?: [];
            $trip = [];
            if ($r['origin_city']) $trip[] = htmlspecialchars($r['origin_city']);
            if ($r['preferred_month']) $trip[] = htmlspecialchars($r['preferred_month']);
            if ($r['tier_code']) $trip[] = strtoupper(htmlspecialchars($r['tier_code']));
            if ($r['total_weeks']) $trip[] = (int)$r['total_weeks'].'wk';
          ?>
            <tr>
              <td class="font-mono text-xs"><?= htmlspecialchars($r['request_ref']) ?></td>
              <td>
                <div class="font-medium text-slate-900"><?= htmlspecialchars((string)$r['name']) ?></div>
                <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($r['email'] ?: $r['phone'])) ?></div>
              </td>
              <td class="text-xs text-slate-600"><?= implode(' · ', $trip) ?: '—' ?></td>
              <td><?= (int)$r['pax'] ?></td>
              <td><span class="badge <?= $statusBadge[$r['status']] ?? 'badge-gray' ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
              <td>
                <button type="button" class="btn outline btn-sm"
                  @click='open(<?= htmlspecialchars(json_encode([
                    "id" => (int)$r["id"], "ref" => $r["request_ref"], "name" => $r["name"],
                    "email" => $r["email"], "phone" => $r["phone"], "status" => $r["status"],
                    "origin_city" => $r["origin_city"], "preferred_month" => $r["preferred_month"],
                    "preferred_date" => $r["preferred_date"], "tier_code" => $r["tier_code"], "pax" => (int)$r["pax"],
                    "madinah_nights" => $r["madinah_nights"], "makkah_nights" => $r["makkah_nights"],
                    "total_weeks" => $r["total_weeks"], "ziyarah" => $ziy, "addons" => $add,
                    "notes" => $r["notes"], "staff_note" => $r["staff_note"],
                    "quote_amount" => $r["quote_amount"], "quote_currency" => $r["quote_currency"],
                  ], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)'>View</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- DETAIL / RESPOND DRAWER -->
  <div x-show="sel" x-cloak class="fixed inset-0 z-50 flex" @keydown.escape.window="sel=null">
    <div class="absolute inset-0 bg-black/40" @click="sel=null"></div>
    <div class="relative ml-auto w-full max-w-lg bg-white h-full overflow-y-auto shadow-xl p-6" x-show="sel">
      <template x-if="sel">
        <div>
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-slate-900">Request <span x-text="sel.ref"></span></h2>
            <button class="btn ghost btn-sm" @click="sel=null"><span class="material-symbols-outlined">close</span></button>
          </div>

          <div class="space-y-3 text-sm">
            <div><span class="text-slate-500">Customer:</span> <span class="font-medium" x-text="sel.name"></span>
              <span class="text-slate-500" x-text="' · ' + (sel.email || '') + (sel.phone ? ' · ' + sel.phone : '')"></span></div>
            <div class="grid grid-cols-2 gap-3">
              <div><span class="text-slate-500">City:</span> <span x-text="sel.origin_city || 'Flexible'"></span></div>
              <div><span class="text-slate-500">Month:</span> <span x-text="sel.preferred_month || (sel.preferred_date || 'Flexible')"></span></div>
              <div><span class="text-slate-500">Tier:</span> <span x-text="(sel.tier_code||'—').toUpperCase()"></span></div>
              <div><span class="text-slate-500">Pilgrims:</span> <span x-text="sel.pax"></span></div>
              <div><span class="text-slate-500">Length:</span> <span x-text="(sel.total_weeks? sel.total_weeks+' weeks':'—')"></span></div>
              <div><span class="text-slate-500">Nights:</span> <span x-text="(sel.madinah_nights||'?')+' Mad / '+(sel.makkah_nights||'?')+' Mak'"></span></div>
            </div>
            <div x-show="sel.ziyarah && sel.ziyarah.length"><span class="text-slate-500">Ziyarah:</span> <span x-text="(sel.ziyarah||[]).join(', ')"></span></div>
            <div x-show="sel.addons && sel.addons.length"><span class="text-slate-500">Extras:</span> <span x-text="(sel.addons||[]).join(', ')"></span></div>
            <div x-show="sel.notes"><span class="text-slate-500">Notes:</span> <span x-text="sel.notes"></span></div>
          </div>

          <hr class="my-4">
          <h3 class="font-semibold text-slate-900 mb-2">Respond</h3>
          <form @submit.prevent="submit()">
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select class="select w-full mb-3" x-model="form.status">
              <option value="new">New</option>
              <option value="in_review">In review</option>
              <option value="quoted">Quoted</option>
              <option value="converted">Converted</option>
              <option value="closed">Closed</option>
            </select>
            <div class="grid grid-cols-3 gap-2 mb-3">
              <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1">Quote amount</label>
                <input type="number" step="0.01" class="input w-full" x-model="form.quote_amount" placeholder="e.g. 3200000">
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                <input class="input w-full" x-model="form.quote_currency" placeholder="NGN">
              </div>
            </div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Internal note</label>
            <textarea rows="3" class="input w-full mb-3" x-model="form.staff_note" placeholder="Notes for the team (not shown to the customer)…"></textarea>
            <div class="flex gap-2">
              <button type="submit" class="btn" :disabled="busy" x-text="busy ? 'Saving…' : 'Save response'"></button>
              <span class="text-sm self-center" :class="okMsg?'text-green-600':'text-rose-600'" x-text="okMsg || errMsg"></span>
            </div>
          </form>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function umrahQuoteInbox() {
  return {
    sel: null, busy: false, okMsg: '', errMsg: '',
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    form: { status: 'in_review', quote_amount: '', quote_currency: 'NGN', staff_note: '' },
    open(r) {
      this.sel = r; this.okMsg=''; this.errMsg='';
      this.form = {
        status: r.status || 'in_review',
        quote_amount: r.quote_amount || '',
        quote_currency: r.quote_currency || 'NGN',
        staff_note: r.staff_note || ''
      };
    },
    async submit() {
      this.busy = true; this.okMsg=''; this.errMsg='';
      const body = new URLSearchParams({
        csrf_token: this.csrf, id: this.sel.id, status: this.form.status,
        quote_amount: this.form.quote_amount, quote_currency: this.form.quote_currency,
        staff_note: this.form.staff_note
      });
      try {
        const res = await fetch('<?= root ?>admin/umrah-manager/quote-requests/respond', { method: 'POST', body });
        const data = await res.json();
        if (data.success) { this.okMsg = 'Saved'; setTimeout(() => location.reload(), 700); }
        else { this.errMsg = data.message || 'Failed'; }
      } catch (e) { this.errMsg = 'Network error'; }
      this.busy = false;
    }
  };
}
</script>
