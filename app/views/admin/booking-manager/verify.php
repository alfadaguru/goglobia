<?php
// path: app/views/admin/booking-manager/verify.php
// Payment verification queue — senior admins (admin_level >= 1) confirm manual
// bank transfers. Verifying settles the booking via bm_settle_booking_payment
// (marks paid + issues a live booking / confirms a manual one).
@$SECURE or die('Access Denied!');

$v        = $GLOBALS['bmVerify'] ?? ['is_senior' => false, 'pending' => [], 'stranded' => []];
$stranded = $v['stranded'] ?? [];
$csrf     = $_SESSION['csrf_token'] ?? '';

// Passenger full name(s) from a booking's travellers JSON — the people FLYING
// (distinct from the booker/customer in first_name+last_name). Returns a short
// list string like "Ada Obi, Chidi Obi" (or '' when there are no named pax).
if (!function_exists('bmTravellerNames')) {
    function bmTravellerNames($travellersJson): array
    {
        $t = is_array($travellersJson) ? $travellersJson : json_decode((string) $travellersJson, true);
        if (!is_array($t)) return [];
        $names = [];
        foreach ($t as $k => $p) {
            if (!is_array($p) || !preg_match('/^(adult|child|infant)_\d+$/', (string) $k)) continue;
            $n = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? ''));
            if ($n !== '') $names[] = $n;
        }
        return $names;
    }
}
?>
<div class="container py-6" x-data="bmVerify()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Payment Verification</h1>
      <p class="text-sm text-slate-600 mt-1">Confirm manual bank transfers. Verifying a booking marks it paid and issues the ticket (for live supplier bookings).</p>
    </div>
    <a href="<?= root . admin ?>/booking-manager/flights" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Booking Manager</span></a>
  </div>

  <?php if (empty($v['is_senior'])): ?>
    <div class="card p-6 text-center">
      <span class="material-symbols-outlined text-4xl text-amber-500">lock</span>
      <p class="mt-2 text-slate-700 font-medium">Senior admin access required</p>
      <p class="text-sm text-slate-500">Only an admin or super-admin can verify manual payments. Ask a senior admin to confirm the transfer.</p>
    </div>
  <?php else: ?>
    <div class="card table-container overflow-x-auto">
      <table class="table w-full text-sm">
        <thead><tr class="text-left text-slate-500 border-b border-slate-100">
          <th class="px-4 py-3 font-medium">Invoice</th>
          <th class="px-4 py-3 font-medium">Customer / Passengers</th>
          <th class="px-4 py-3 font-medium">Service</th>
          <th class="px-4 py-3 font-medium">Amount due</th>
          <th class="px-4 py-3 font-medium">Created by</th>
          <th class="px-4 py-3 font-medium">Bank ref</th>
          <th class="px-4 py-3 font-medium">Amount received</th>
          <th class="px-4 py-3 font-medium">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (empty($v['pending'])): ?>
            <tr><td colspan="8" class="px-4 py-6 text-center text-slate-400">Nothing awaiting verification.</td></tr>
          <?php else: foreach ($v['pending'] as $b): $inv = $b['invoice_id']; $due = (float) ($b['price_markup'] ?? 0); ?>
            <tr x-show="!done['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
              <td class="px-4 py-3 font-mono text-slate-700"><?= htmlspecialchars($inv) ?></td>
              <td class="px-4 py-3">
                <?= htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?>
                <br><span class="text-xs text-slate-400"><?= htmlspecialchars($b['email'] ?? '') ?></span>
                <?php $pax = bmTravellerNames($b['travellers'] ?? ''); if (!empty($pax)): ?>
                <div class="text-xs text-slate-500 mt-1"><span class="material-symbols-outlined align-middle" style="font-size:13px;">group</span> <?= htmlspecialchars(implode(', ', $pax)) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3"><?= htmlspecialchars(ucfirst((string) ($b['module_type'] ?? ''))) ?> · <?= htmlspecialchars((string) ($b['module'] ?? '')) ?></td>
              <td class="px-4 py-3 font-semibold tabular-nums"><?= htmlspecialchars((string) ($b['currency_markup'] ?? '')) ?> <?= number_format($due, 2) ?></td>
              <td class="px-4 py-3 text-xs text-slate-500"><?= htmlspecialchars((string) ($b['created_by_admin'] ?? '')) ?></td>
              <td class="px-4 py-3"><input class="input input-sm w-32" x-model="refs['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']" placeholder="transfer ref"></td>
              <td class="px-4 py-3"><input type="number" step="0.01" min="0" class="input input-sm w-28 tabular-nums" x-model="amts['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']" :placeholder="'<?= number_format($due, 2, '.', '') ?>'"></td>
              <td class="px-4 py-3">
                <button class="btn primary sm" @click="verify('<?= htmlspecialchars($inv, ENT_QUOTES) ?>', <?= htmlspecialchars((string) $due, ENT_QUOTES) ?>)" :disabled="busy['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
                  <span class="material-symbols-outlined">verified</span><span>Verify &amp; confirm</span>
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-sm mt-3" :class="msgOk?'text-emerald-700':'text-red-600'" x-show="msg" x-text="msg"></p>

    <!-- Paid-but-unissued live bookings (issue failed after payment) -->
    <?php if (!empty($stranded)): ?>
      <div class="mt-8">
        <h2 class="text-lg font-semibold text-red-700 flex items-center gap-2"><span class="material-symbols-outlined">error</span>Paid — not issued</h2>
        <p class="text-sm text-slate-600 mt-1 mb-3">These live bookings were paid but the supplier could not issue a ticket. Retry the issue, or refund the customer.</p>
        <div class="card table-container overflow-x-auto">
          <table class="table w-full text-sm">
            <thead><tr class="text-left text-slate-500 border-b border-slate-100">
              <th class="px-4 py-3 font-medium">Invoice</th>
              <th class="px-4 py-3 font-medium">Customer / Passengers</th>
              <th class="px-4 py-3 font-medium">Service</th>
              <th class="px-4 py-3 font-medium">Amount</th>
              <th class="px-4 py-3 font-medium">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($stranded as $b): $inv = $b['invoice_id'];
                    // Find real passengers (adult_/child_/infant_) that are MISSING a dob,
                    // so the senior can supply it before retrying (the #1 issue-failure).
                    $trav = json_decode((string) ($b['travellers'] ?? '[]'), true);
                    $needDob = [];
                    if (is_array($trav)) {
                        foreach ($trav as $pk => $pv) {
                            if (!is_array($pv) || !preg_match('/^(adult|child|infant)_\d+$/', (string) $pk)) { continue; }
                            $hasDob = trim((string) ($pv['dob'] ?? $pv['date_of_birth'] ?? '')) !== '';
                            if (!$hasDob && strpos((string) $pk, 'infant_') !== 0) {
                                $needDob[$pk] = trim((string) ($pv['first_name'] ?? '') . ' ' . (string) ($pv['last_name'] ?? '')) ?: $pk;
                            }
                        }
                    }
                    $isFlight = strtolower((string) ($b['module_type'] ?? '')) === 'flights';
              ?>
                <tr x-show="!done['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
                  <td class="px-4 py-3 font-mono text-slate-700"><?= htmlspecialchars($inv) ?></td>
                  <td class="px-4 py-3">
                    <?= htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?>
                    <br><span class="text-xs text-slate-400"><?= htmlspecialchars($b['email'] ?? '') ?></span>
                    <?php $spax = bmTravellerNames($b['travellers'] ?? ''); if (!empty($spax)): ?>
                    <div class="text-xs text-slate-500 mt-1"><span class="material-symbols-outlined align-middle" style="font-size:13px;">group</span> <?= htmlspecialchars(implode(', ', $spax)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3"><?= htmlspecialchars(ucfirst((string) ($b['module_type'] ?? ''))) ?> · <?= htmlspecialchars((string) ($b['module'] ?? '')) ?></td>
                  <td class="px-4 py-3 font-semibold tabular-nums"><?= htmlspecialchars((string) ($b['currency_markup'] ?? '')) ?> <?= number_format((float) ($b['price_markup'] ?? 0), 2) ?></td>
                  <td class="px-4 py-3">
                    <?php if ($isFlight && !empty($needDob)): ?>
                    <div class="mb-2 space-y-1">
                      <p class="text-[11px] text-red-600">Missing date of birth — the airline needs it to issue:</p>
                      <?php foreach ($needDob as $pk => $pname): ?>
                      <label class="flex items-center gap-2 text-xs">
                        <span class="text-slate-600 w-32 truncate"><?= htmlspecialchars($pname) ?></span>
                        <input type="date" class="input input-sm" x-model="dobs['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']['<?= htmlspecialchars($pk, ENT_QUOTES) ?>']">
                      </label>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <button class="btn outline sm" @click="retry('<?= htmlspecialchars($inv, ENT_QUOTES) ?>')" :disabled="busy['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
                      <span class="material-symbols-outlined">refresh</span><span>Retry issue</span>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function bmVerify(){
  return {
    csrf:'<?= htmlspecialchars($csrf, ENT_QUOTES) ?>', base:'<?= root ?>',
    refs:{}, amts:{}, busy:{}, done:{}, msg:'', msgOk:false,
    // Per-invoice DOB entries the senior can fill in for a stranded booking that
    // failed to issue for lack of a date of birth. Seeded so x-model has a target.
    dobs:{ <?php foreach (($stranded ?? []) as $b): $inv = $b['invoice_id'];
             $trav = json_decode((string) ($b['travellers'] ?? '[]'), true); $keys=[];
             if (is_array($trav)) { foreach ($trav as $pk=>$pv){ if (is_array($pv) && preg_match('/^(adult|child)_\d+$/',(string)$pk)) { $keys[]=$pk; } } }
             if ($keys): ?>'<?= htmlspecialchars($inv, ENT_QUOTES) ?>':{<?php foreach ($keys as $k): ?>'<?= htmlspecialchars($k, ENT_QUOTES) ?>':'',<?php endforeach; ?>},<?php endif; endforeach; ?> },
    async verify(inv, due){
      // Amount received: blank = "full amount due" (the input placeholder shows it).
      const received = (this.amts[inv]===undefined||this.amts[inv]===null||(''+this.amts[inv]).trim()==='')
                       ? null : Number(this.amts[inv]);
      let confirmShort = false;
      if(received!==null && Number.isFinite(due) && received < (due - 0.01)){
        // Short transfer — make the senior own it explicitly, don't silently record full payment.
        if(!confirm('The amount received ('+received.toFixed(2)+') is LESS than the amount due ('+Number(due).toFixed(2)+').\n'
                    +'Record this booking as PAID IN FULL anyway? (The shortfall will NOT be collected automatically.)')) return;
        confirmShort = true;
      } else {
        if(!confirm('Confirm you have received this bank transfer? This marks the booking paid'+'\n'+'and issues the ticket for live bookings.')) return;
      }
      this.busy[inv]=true; this.msg='';
      try{
        const f=new FormData(); f.append('csrf_token',this.csrf); f.append('invoice_id',inv); f.append('transaction_id',this.refs[inv]||'');
        if(received!==null){ f.append('amount_received',received); }
        if(confirmShort){ f.append('confirm_short','1'); }
        const res=await fetch(this.base+'admin/booking-manager/verify',{method:'POST',body:f});
        const data=await res.json();
        this.msgOk=!!data.status; this.msg=(data.settle&&data.settle.message)||data.message||'';
        if(data.status){ this.done[inv]=true; }
      }catch(e){ this.msgOk=false; this.msg='Verification failed'; }
      this.busy[inv]=false;
    },
    async retry(inv){
      if(!confirm('Retry issuing this paid booking at the supplier?')) return;
      this.busy[inv]=true; this.msg='';
      try{
        const f=new FormData(); f.append('csrf_token',this.csrf); f.append('invoice_id',inv);
        // If the senior entered any missing DOBs for this booking, send them so the
        // backend patches the travellers before re-firing the supplier issue.
        const dob=this.dobs[inv];
        if(dob && Object.keys(dob).some(k=>(dob[k]||'').trim())){ f.append('traveller_dobs', JSON.stringify(dob)); }
        const res=await fetch(this.base+'admin/booking-manager/retry-issue',{method:'POST',body:f});
        const data=await res.json();
        this.msgOk=!!data.status; this.msg=(data.settle&&data.settle.message)||data.message||'';
        if(data.status){ this.done[inv]=true; }
      }catch(e){ this.msgOk=false; this.msg='Retry failed'; }
      this.busy[inv]=false;
    }
  };
}
</script>
