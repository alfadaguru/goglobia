<?php
// UMRAH v2 admin — operations (visa/ticket/rooming/docs/hotels/transport).
// Expects: $departure, $travellers, $refById, $hotels, $allocations, $transport.
@$SECURE or die('Access Denied!');
$csrf = $_SESSION['csrf_token'] ?? '';
$base = root . 'admin/umrah-manager/operations';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
?>
<div class="container py-6" x-data="umrahOps()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Operations — <?= htmlspecialchars($departure['code']) ?></h1>
      <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars(date('d M Y', strtotime($departure['departure_date']))) ?> → <?= htmlspecialchars(date('d M Y', strtotime($departure['return_date']))) ?></p>
    </div>
    <a href="<?= root ?>admin/umrah-manager" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Manager</span></a>
  </div>

  <!-- Travellers: visa / ticket / rooming batch -->
  <div class="section">
    <div class="section-header"><h2>Travellers — visa / ticket / rooming</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Booking</th><th>Name</th><th>Docs</th><th>Visa</th><th>Ticket</th><th>Rooming</th></tr></thead>
        <tbody>
          <?php if (empty($travellers)): ?>
            <tr><td colspan="6" class="text-slate-500">No travellers added yet for this departure.</td></tr>
          <?php else: foreach ($travellers as $t):
            $sel = function($domain,$cur,$opts) use ($t,$csrf,$base) {
              $out = '<select class="select" style="height:34px" onchange="umrahOpsSet('.$t['id'].",'".$domain."',this.value)\">";
              foreach ($opts as $o) { $out .= '<option value="'.$o.'"'.($cur===$o?' selected':'').'>'.$o.'</option>'; }
              return $out.'</select>';
            }; ?>
            <tr>
              <td class="font-mono text-xs"><?= htmlspecialchars($refById[$t['umrah_booking_id']] ?? '') ?></td>
              <td><?= htmlspecialchars(trim(($t['first_name'] ?? '').' '.($t['last_name'] ?? ''))) ?: '<span class="text-slate-400">—</span>' ?></td>
              <td>
                <span class="badge <?= $t['doc_status']==='verified'?'badge-success':($t['doc_status']==='action_required'?'badge-error':'badge-gray') ?>"><?= htmlspecialchars($t['doc_status']) ?></span>
                <?php $tdocs = $documentsByTraveller[(int)$t['id']] ?? []; ?>
                <?php if ($tdocs): ?>
                  <div class="mt-1 space-y-1">
                    <?php foreach ($tdocs as $d): ?>
                      <div class="flex items-center gap-2 text-xs">
                        <a href="<?= root . admin ?>/umrah-manager/document/<?= (int)$d['id'] ?>" target="_blank" rel="noopener" class="text-primary underline">
                          <?= htmlspecialchars($d['doc_type'] ?: 'document') ?>
                        </a>
                        <span class="badge <?= $d['verify_status']==='verified'?'badge-success':($d['verify_status']==='rejected'?'badge-error':'badge-warning') ?>"><?= htmlspecialchars($d['verify_status']) ?></span>
                        <?php if ($d['verify_status'] === 'pending'): ?>
                          <button type="button" class="text-green-600 hover:underline" onclick="umrahOpsVerifyDoc(<?= (int)$d['id'] ?>,'verify')">verify</button>
                          <button type="button" class="text-rose-600 hover:underline" onclick="umrahOpsVerifyDoc(<?= (int)$d['id'] ?>,'reject')">reject</button>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="text-xs text-slate-400 mt-1">no documents</div>
                <?php endif; ?>
              </td>
              <td><?= $sel('visa',$t['visa_status'],['not_started','ready_to_submit','submitted','approved','rejected','action_required']) ?></td>
              <td><?= $sel('ticket',$t['ticket_status'],['not_started','reserved','ticketed','changed','cancelled']) ?></td>
              <td><?= $sel('rooming',$t['rooming_status'],['unassigned','requested','assigned','confirmed']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-500 mt-2" x-text="msg"></p>
  </div>

  <!-- Hotels allocation -->
  <div class="section">
    <div class="section-header flex items-center justify-between"><div><h2>Hotels</h2></div>
      <button class="btn btn-sm" @click="showHotel=!showHotel">Add hotel allocation</button></div>
    <div x-show="showHotel" x-cloak class="mb-4 p-4 rounded-xl border border-slate-200 bg-slate-50">
      <div class="form-grid">
        <div class="form-control"><label>Hotel name</label><input class="input" x-model="hotel.hotel_name"></div>
        <div class="form-control"><label>City</label><select class="select" x-model="hotel.city"><option value="madinah">Madinah</option><option value="makkah">Makkah</option></select></div>
        <div class="form-control"><label>Nights</label><input type="number" class="input" x-model.number="hotel.nights"></div>
        <div class="form-control"><label>Rooms</label><input type="number" class="input" x-model.number="hotel.rooms"></div>
        <div class="form-control"><label>Beds</label><input type="number" class="input" x-model.number="hotel.beds"></div>
        <div class="form-control"><label>Confirmation ref</label><input class="input" x-model="hotel.confirmation_ref"></div>
      </div>
      <div class="mt-3"><button class="btn" @click="allocateHotel()">Save hotel</button></div>
    </div>
    <div class="table-container">
      <table class="table"><thead><tr><th>City</th><th>Hotel</th><th>Nights</th><th>Rooms</th><th>Beds</th><th>Ref</th></tr></thead>
      <tbody>
        <?php if (empty($allocations)): ?><tr><td colspan="6" class="text-slate-500">No hotels allocated.</td></tr>
        <?php else: foreach ($allocations as $a): $h=null; foreach($hotels as $hh){if((int)$hh['id']===(int)$a['hotel_id'])$h=$hh;} ?>
          <tr><td class="capitalize"><?= htmlspecialchars($a['city']) ?></td><td><?= htmlspecialchars($h['name'] ?? ('#'.$a['hotel_id'])) ?></td><td><?= (int)$a['nights'] ?></td><td><?= (int)$a['rooms'] ?></td><td><?= (int)$a['beds'] ?></td><td class="text-xs"><?= htmlspecialchars((string)$a['confirmation_ref']) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table>
    </div>
  </div>

  <!-- Transport allocation -->
  <div class="section">
    <div class="section-header flex items-center justify-between"><div><h2>Ground transport</h2></div>
      <button class="btn btn-sm" @click="showTr=!showTr">Add transport</button></div>
    <div x-show="showTr" x-cloak class="mb-4 p-4 rounded-xl border border-slate-200 bg-slate-50">
      <div class="form-grid">
        <div class="form-control"><label>Route</label><input class="input" x-model="tr.route" placeholder="Airport → Madinah"></div>
        <div class="form-control"><label>Vehicle type</label><input class="input" x-model="tr.vehicle_type"></div>
        <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="tr.vehicle_capacity"></div>
        <div class="form-control"><label>Supplier</label><input class="input" x-model="tr.supplier"></div>
      </div>
      <div class="mt-3"><button class="btn" @click="allocateTransport()">Save transport</button></div>
    </div>
    <div class="table-container">
      <table class="table"><thead><tr><th>Route</th><th>Vehicle</th><th>Cap</th><th>Supplier</th></tr></thead>
      <tbody>
        <?php if (empty($transport)): ?><tr><td colspan="4" class="text-slate-500">No transport allocated.</td></tr>
        <?php else: foreach ($transport as $tr): ?><tr><td><?= htmlspecialchars($tr['route']) ?></td><td><?= htmlspecialchars((string)$tr['vehicle_type']) ?></td><td><?= (int)$tr['vehicle_capacity'] ?></td><td><?= htmlspecialchars((string)$tr['supplier']) ?></td></tr><?php endforeach; endif; ?>
      </tbody></table>
    </div>
  </div>
</div>

<script>
function umrahOps(){ return {
  csrf:'<?= htmlspecialchars($csrf, ENT_QUOTES) ?>', base:'<?= $base ?>', msg:'', showHotel:false, showTr:false,
  hotel:{ departure_id:<?= (int)$departure['id'] ?>, kind:'hotel', hotel_name:'', city:'madinah', nights:4, rooms:0, beds:0, confirmation_ref:'' },
  tr:{ departure_id:<?= (int)$departure['id'] ?>, kind:'transport', route:'', vehicle_type:'', vehicle_capacity:0, supplier:'' },
  form(o){ const f=new FormData(); f.append('csrf_token',this.csrf); for(const k in o) f.append(k,o[k]); return f; },
  async post(u,o){ const r=await fetch(u,{method:'POST',body:this.form(o)}); return r.json(); },
  async allocateHotel(){ const j=await this.post(this.base+'/allocate',this.hotel); this.msg=j.message||''; if(j.success) location.reload(); },
  async allocateTransport(){ const j=await this.post(this.base+'/allocate',this.tr); this.msg=j.message||''; if(j.success) location.reload(); }
}; }
async function umrahOpsSet(tid, domain, value){
  const f=new FormData(); f.append('csrf_token','<?= htmlspecialchars($csrf, ENT_QUOTES) ?>'); f.append('traveller_id',tid); f.append('domain',domain); f.append('value',value);
  const r=await fetch('<?= $base ?>/traveller-status',{method:'POST',body:f}); const j=await r.json();
  if(!j.success) alert(j.message||'Failed');
}
async function umrahOpsVerifyDoc(docId, decision){
  if(!confirm(decision==='reject'?'Reject this document?':'Mark this document verified?')) return;
  const f=new FormData(); f.append('csrf_token','<?= htmlspecialchars($csrf, ENT_QUOTES) ?>'); f.append('document_id',docId); f.append('decision',decision);
  const r=await fetch('<?= $base ?>/verify-document',{method:'POST',body:f}); const j=await r.json();
  if(j.success) location.reload(); else alert(j.message||'Failed');
}
</script>
