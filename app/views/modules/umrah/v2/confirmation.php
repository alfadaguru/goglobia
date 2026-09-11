<?php
// UMRAH v2 — booking confirmation / trip view (docs §8/§23 minimal dashboard).
// Expects: $ub (umrah_bookings row + decoded snapshot), $installments.
@$SECURE or die('Access Denied!');
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$snap = $ub['snapshot'] ?? [];
$dep = $snap['departure'] ?? [];
$confirmed = ($ub['booking_status'] === 'confirmed');
$nextDue = null;
foreach ($installments as $i) { if (in_array($i['status'], ['pending','overdue'], true)) { $nextDue = $i; break; } }
?>
<div class="bg-white">
  <div class="container py-8 max-w-3xl">

    <div class="card border">
      <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-3xl <?= $confirmed ? 'text-green-600' : 'text-amber-500' ?>">
          <?= $confirmed ? 'check_circle' : 'schedule' ?>
        </span>
        <div>
          <h1 class="text-xl font-bold text-gray-900"><?= $confirmed ? 'Booking Confirmed' : 'Booking Created' ?></h1>
          <p class="text-sm text-gray-600">Reference: <span class="font-mono font-semibold"><?= htmlspecialchars($ub['booking_ref']) ?></span></p>
        </div>
      </div>

      <?php if ($confirmed): ?>
        <div class="alert-success mt-4"><span class="material-icon material-symbols-outlined">lock</span>
          <p>Your qualifying payment has <strong>locked the package price</strong> for this booking.</p></div>
      <?php else: ?>
        <div class="alert-warning mt-4"><span class="material-icon material-symbols-outlined">payments</span>
          <p>Your seat is held. Complete the payment below to confirm and lock your price.</p></div>
      <?php endif; ?>

      <div class="grid grid-cols-2 gap-4 mt-5 text-sm">
        <div><div class="text-gray-500">Departure</div><div class="font-semibold"><?= htmlspecialchars(($dep['departure_date'] ?? '') . ' → ' . ($dep['return_date'] ?? '')) ?></div></div>
        <div><div class="text-gray-500">Package</div><div class="font-semibold"><?= htmlspecialchars($snap['tier_code'] ?? 'standard') ?></div></div>
        <div><div class="text-gray-500">Travellers</div><div class="font-semibold"><?= (int) $ub['pax'] ?></div></div>
        <div><div class="text-gray-500">Total</div><div class="font-semibold"><?= $fmt($ub['total_price']) ?></div></div>
        <div><div class="text-gray-500">Paid</div><div class="font-semibold"><?= $fmt($ub['amount_paid']) ?></div></div>
        <div><div class="text-gray-500">Balance</div><div class="font-semibold"><?= $fmt($ub['balance']) ?></div></div>
      </div>
    </div>

    <!-- Payment schedule -->
    <div class="section mt-6">
      <div class="section-header"><h2>Payment schedule</h2></div>
      <div class="table-container">
        <table class="table">
          <thead><tr><th>#</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($installments as $i): ?>
              <tr>
                <td><?= (int) $i['seq'] ?></td>
                <td><?= $fmt($i['amount']) ?> <span class="text-xs text-gray-400">(<?= rtrim(rtrim((string)$i['percent'],'0'),'.') ?>%)</span></td>
                <td class="text-sm text-gray-600"><?= $i['due_at'] ? htmlspecialchars(date('d M Y', strtotime($i['due_at']))) : 'At booking' ?></td>
                <td><span class="badge <?= $i['status']==='paid' ? 'badge-success' : ($i['status']==='overdue' ? 'badge-error' : 'badge-gray') ?>"><?= htmlspecialchars($i['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($nextDue): ?>
        <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
          <div class="text-sm text-gray-700">Next payment: <strong><?= $fmt($nextDue['amount']) ?></strong></div>
          <a href="<?= root ?>invoice/umrah/<?= htmlspecialchars($ub['invoice_id']) ?>" class="btn">Pay now</a>
        </div>
      <?php endif; ?>
    </div>

    <!-- PILGRIM DETAILS + DOCUMENTS (seamless, in-flow) -->
    <?php
    $pax = (int) $ub['pax'];
    $travellers = $travellers ?? [];
    $docsByTraveller = $docsByTraveller ?? [];
    $countries = $countries ?? [];
    // Build slot list = existing travellers padded to pax (empty slots for new).
    $slots = [];
    for ($i = 0; $i < $pax; $i++) {
        $t = $travellers[$i] ?? null;
        $slots[] = [
            'id'          => $t['id'] ?? 0,
            'is_lead'     => $t ? (int) $t['is_lead'] : ($i === 0 ? 1 : 0),
            'title'       => $t['title'] ?? '',
            'first_name'  => $t['first_name'] ?? '',
            'last_name'   => $t['last_name'] ?? '',
            'gender'      => $t['gender'] ?? '',
            'dob'         => $t['dob'] ?? '',
            'nationality' => $t['nationality'] ?? '',
            'passport_number' => $t['passport_number'] ?? '',
            'passport_expiry' => $t['passport_expiry'] ?? '',
            'doc_status'  => $t['doc_status'] ?? 'not_started',
            'docs'        => $t ? ($docsByTraveller[(int) $t['id']] ?? []) : [],
        ];
    }
    $countryJs = array_map(fn($c) => ['iso' => $c['iso'], 'name' => $c['nicename']], $countries);
    ?>
    <div class="section" x-data="umrahPilgrims(
        <?= htmlspecialchars(json_encode($slots), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($countryJs), ENT_QUOTES) ?>
      )">
      <div class="section-header"><h2>Pilgrim details &amp; documents</h2></div>
      <p class="text-sm text-gray-600 mb-4">Complete each pilgrim's details and upload their passport. Gender, date of birth and passport are required for the Umrah visa.</p>

      <template x-for="(p, idx) in slots" :key="idx">
        <div class="card border mb-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-900">
              <span x-text="p.is_lead ? 'Lead pilgrim' : ('Pilgrim ' + (idx+1))"></span>
            </h3>
            <span class="badge"
              :class="p.doc_status==='verified' ? 'badge-success' : (p.doc_status==='ready' ? 'badge-gray' : (p.doc_status==='action_required' ? 'badge-error' : 'badge-warning'))"
              x-text="p.doc_status.replace(/_/g,' ')"></span>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <select class="select" x-model="p.title">
              <option value="">Title</option><option>Mr</option><option>Mrs</option><option>Ms</option><option>Miss</option><option>Dr</option>
            </select>
            <input class="input" placeholder="First name (as passport)" x-model="p.first_name">
            <input class="input" placeholder="Last name (as passport)" x-model="p.last_name">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
            <select class="select" x-model="p.gender">
              <option value="">Gender *</option><option value="male">Male</option><option value="female">Female</option>
            </select>
            <div>
              <label class="block text-[11px] text-gray-500 mb-0.5">Date of birth *</label>
              <input type="date" class="input w-full" x-model="p.dob">
            </div>
            <select class="select" x-model="p.nationality">
              <option value="">Nationality *</option>
              <template x-for="c in countries" :key="c.iso"><option :value="c.iso" x-text="c.name"></option></template>
            </select>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <input class="input" placeholder="Passport number *" x-model="p.passport_number">
            <div>
              <label class="block text-[11px] text-gray-500 mb-0.5">Passport expiry *</label>
              <input type="date" class="input w-full" x-model="p.passport_expiry">
            </div>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-sm" :disabled="p.saving" @click="savePilgrim(idx)"
              x-text="p.saving ? 'Saving…' : (p.id ? 'Update details' : 'Save details')"></button>

            <!-- Passport upload (enabled once the pilgrim row exists) -->
            <label class="btn btn-sm outline cursor-pointer" :class="!p.id ? 'opacity-50 pointer-events-none' : ''">
              <span class="material-symbols-outlined text-[18px]">upload_file</span>
              <span x-text="p.uploading ? 'Uploading…' : 'Upload passport'"></span>
              <input type="file" class="hidden" accept="image/jpeg,image/png,application/pdf" @change="uploadDoc(idx, $event)">
            </label>

            <span class="text-xs" :class="p.msgOk ? 'text-green-600' : 'text-rose-600'" x-text="p.msg"></span>
          </div>

          <!-- Uploaded docs -->
          <div class="mt-2 flex flex-wrap gap-2" x-show="p.docs && p.docs.length">
            <template x-for="d in p.docs" :key="d.id">
              <span class="text-[11px] rounded-full px-2 py-0.5"
                :class="d.verify_status==='verified' ? 'bg-green-100 text-green-700' : (d.verify_status==='rejected' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700')">
                <span x-text="d.doc_type || 'passport'"></span> · <span x-text="d.verify_status"></span>
              </span>
            </template>
          </div>
        </div>
      </template>
    </div>

    <!-- Next steps -->
    <div class="section">
      <div class="section-header"><h2>Next steps</h2></div>
      <p class="text-sm text-gray-700">Complete the pilgrim details above and upload passports now, or return anytime from <a class="text-primary" href="<?= root ?>bookings">My bookings</a>. Your visa is processed once details, documents and the qualifying payment are in.</p>
      <div class="mt-4 flex flex-wrap gap-3">
        <a href="<?= root ?>bookings" class="btn outline">My bookings</a>
        <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn outline">Chat on WhatsApp</a>
      </div>
    </div>

  </div>
</div>

<script>
function umrahPilgrims(slots, countries) {
  return {
    slots: (slots || []).map(s => ({ ...s, saving:false, uploading:false, msg:'', msgOk:false })),
    countries: countries || [],
    csrf: '<?= htmlspecialchars($umrahCsrf ?? '', ENT_QUOTES) ?>',
    bookingRef: '<?= htmlspecialchars($ub['booking_ref'], ENT_QUOTES) ?>',
    apiBase: '<?= root ?>api/v1/umrah',
    validate(p) {
      if (!p.first_name || !p.last_name) return 'First and last name are required';
      if (!p.gender) return 'Gender is required';
      if (!p.dob) return 'Date of birth is required';
      if (!p.nationality) return 'Nationality is required';
      if (!p.passport_number) return 'Passport number is required';
      if (!p.passport_expiry) return 'Passport expiry is required';
      return '';
    },
    async savePilgrim(idx) {
      const p = this.slots[idx];
      const err = this.validate(p);
      if (err) { p.msg = err; p.msgOk = false; return; }
      p.saving = true; p.msg = '';
      try {
        const body = {
          csrf_token: this.csrf, id: p.id || 0, is_lead: p.is_lead ? 1 : 0,
          title: p.title, first_name: p.first_name, last_name: p.last_name,
          gender: p.gender, dob: p.dob, nationality: p.nationality,
          passport_number: p.passport_number, passport_expiry: p.passport_expiry
        };
        const r = await fetch(this.apiBase + '/bookings/' + this.bookingRef + '/travellers', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
          body: JSON.stringify(body)
        });
        const j = await r.json();
        if (j.success) { p.id = j.traveller_id; p.doc_status = 'ready'; p.msg = 'Saved'; p.msgOk = true; }
        else { p.msg = j.message || 'Could not save'; p.msgOk = false; }
      } catch (e) { p.msg = 'Network error'; p.msgOk = false; }
      p.saving = false;
    },
    async uploadDoc(idx, ev) {
      const p = this.slots[idx];
      const file = ev.target.files && ev.target.files[0];
      if (!file || !p.id) return;
      p.uploading = true; p.msg = '';
      try {
        const fd = new FormData();
        fd.append('csrf_token', this.csrf);
        fd.append('doc_type', 'passport');
        fd.append('file', file);
        const r = await fetch(this.apiBase + '/travellers/' + p.id + '/documents', {
          method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf }, body: fd
        });
        const j = await r.json();
        if (j.success) { (p.docs = p.docs || []).push({ id: j.document_id, doc_type: 'passport', verify_status: 'pending' }); p.msg = 'Passport uploaded'; p.msgOk = true; }
        else { p.msg = j.message || 'Upload failed'; p.msgOk = false; }
      } catch (e) { p.msg = 'Upload error'; p.msgOk = false; }
      p.uploading = false;
      ev.target.value = '';
    }
  };
}
</script>
