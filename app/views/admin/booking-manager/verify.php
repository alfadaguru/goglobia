<?php
// path: app/views/admin/booking-manager/verify.php
// Payment verification queue — senior admins (admin_level >= 1) confirm manual
// bank transfers. Verifying settles the booking via bm_settle_booking_payment
// (marks paid + issues a live booking / confirms a manual one).
@$SECURE or die('Access Denied!');

$v    = $GLOBALS['bmVerify'] ?? ['is_senior' => false, 'pending' => []];
$csrf = $_SESSION['csrf_token'] ?? '';
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
          <th class="px-4 py-3 font-medium">Customer</th>
          <th class="px-4 py-3 font-medium">Service</th>
          <th class="px-4 py-3 font-medium">Amount</th>
          <th class="px-4 py-3 font-medium">Created by</th>
          <th class="px-4 py-3 font-medium">Bank ref</th>
          <th class="px-4 py-3 font-medium">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (empty($v['pending'])): ?>
            <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">Nothing awaiting verification.</td></tr>
          <?php else: foreach ($v['pending'] as $b): $inv = $b['invoice_id']; ?>
            <tr x-show="!done['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
              <td class="px-4 py-3 font-mono text-slate-700"><?= htmlspecialchars($inv) ?></td>
              <td class="px-4 py-3"><?= htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?><br><span class="text-xs text-slate-400"><?= htmlspecialchars($b['email'] ?? '') ?></span></td>
              <td class="px-4 py-3"><?= htmlspecialchars(ucfirst((string) ($b['module_type'] ?? ''))) ?> · <?= htmlspecialchars((string) ($b['module'] ?? '')) ?></td>
              <td class="px-4 py-3 font-semibold tabular-nums"><?= htmlspecialchars((string) ($b['currency_markup'] ?? '')) ?> <?= number_format((float) ($b['price_markup'] ?? 0), 2) ?></td>
              <td class="px-4 py-3 text-xs text-slate-500"><?= htmlspecialchars((string) ($b['created_by_admin'] ?? '')) ?></td>
              <td class="px-4 py-3"><input class="input input-sm w-32" x-model="refs['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']" placeholder="transfer ref"></td>
              <td class="px-4 py-3">
                <button class="btn primary sm" @click="verify('<?= htmlspecialchars($inv, ENT_QUOTES) ?>')" :disabled="busy['<?= htmlspecialchars($inv, ENT_QUOTES) ?>']">
                  <span class="material-symbols-outlined">verified</span><span>Verify &amp; confirm</span>
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-sm mt-3" :class="msgOk?'text-emerald-700':'text-red-600'" x-show="msg" x-text="msg"></p>
  <?php endif; ?>
</div>

<script>
function bmVerify(){
  return {
    csrf:'<?= htmlspecialchars($csrf, ENT_QUOTES) ?>', base:'<?= root ?>',
    refs:{}, busy:{}, done:{}, msg:'', msgOk:false,
    async verify(inv){
      if(!confirm('Confirm you have received this bank transfer? This marks the booking paid'+'\n'+'and issues the ticket for live bookings.')) return;
      this.busy[inv]=true; this.msg='';
      try{
        const f=new FormData(); f.append('csrf_token',this.csrf); f.append('invoice_id',inv); f.append('transaction_id',this.refs[inv]||'');
        const res=await fetch(this.base+'admin/booking-manager/verify',{method:'POST',body:f});
        const data=await res.json();
        this.msgOk=!!data.status; this.msg=(data.settle&&data.settle.message)||data.message||'';
        if(data.status){ this.done[inv]=true; }
      }catch(e){ this.msgOk=false; this.msg='Verification failed'; }
      this.busy[inv]=false;
    }
  };
}
</script>
