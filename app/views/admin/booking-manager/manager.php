<?php
// path: app/views/admin/booking-manager/manager.php
// Admin Booking Manager console (flights + hotels). Alpine.js + Tailwind.
// Data comes from $GLOBALS['bmData'] set by bookingManagerRoutes.php.
@$SECURE or die('Access Denied!');

$bm   = $GLOBALS['bmData'] ?? [];
$csrf = $_SESSION['csrf_token'] ?? '';
$svc  = $bm['service'] ?? 'flights';          // 'flights' | 'hotels'
$isHotel = ($svc === 'hotels');
?>
<div class="container py-6" x-data="bookingMgr()" x-init="init()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900"><?= ucfirst($svc) ?> Booking Manager</h1>
      <p class="text-sm text-slate-600 mt-1">Book for a walk-in customer — search a live supplier, or record a booking made elsewhere. Take payment by virtual account or bank transfer.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="<?= root . admin ?>/booking-manager/verify" class="btn outline"><span class="material-symbols-outlined">verified</span><span>Verify payments</span></a>
      <a href="<?= root . admin ?>/booking-manager/<?= $svc ?>" class="btn outline"><span class="material-symbols-outlined">refresh</span><span>Reset</span></a>
    </div>
  </div>

  <!-- Mode toggle -->
  <div class="flex gap-2 mb-5">
    <button class="btn" :class="mode==='manual' ? 'primary' : 'outline'" @click="mode='manual'">
      <span class="material-symbols-outlined">edit_note</span><span>Manual / offline booking</span>
    </button>
    <button class="btn" :class="mode==='live' ? 'primary' : 'outline'" @click="mode='live'" x-show="suppliers.length">
      <span class="material-symbols-outlined">travel_explore</span><span>Live supplier search</span>
    </button>
  </div>

  <div class="grid lg:grid-cols-3 gap-5">
    <!-- LEFT: the working form (mode-dependent) -->
    <div class="lg:col-span-2 space-y-5">

      <!-- ============ LIVE MODE: supplier search ============ -->
      <div class="card p-4" x-show="mode==='live'">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">travel_explore</span>Search a live supplier</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">Supplier</span>
            <select class="select input" x-model="live.supplier">
              <option value="">Choose…</option>
              <template x-for="s in suppliers" :key="s"><option :value="s" x-text="s"></option></template>
            </select>
          </label>
          <?php if (!$isHotel): ?>
          <label class="form-control"><span class="form-label">Trip type</span>
            <select class="select input" x-model="live.type">
              <option value="oneway">One way</option><option value="return">Round trip</option>
            </select>
          </label>
          <label class="form-control"><span class="form-label">From (IATA)</span><input class="input" x-model="live.origin" placeholder="DEL"></label>
          <label class="form-control"><span class="form-label">To (IATA)</span><input class="input" x-model="live.destination" placeholder="BOM"></label>
          <label class="form-control"><span class="form-label">Depart</span><input type="date" class="input" x-model="live.departure_date"></label>
          <label class="form-control" x-show="live.type==='return'"><span class="form-label">Return</span><input type="date" class="input" x-model="live.return_date"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="live.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="live.children"></label>
          <label class="form-control"><span class="form-label">Cabin</span>
            <select class="select input" x-model="live.class"><option value="economy">Economy</option><option value="business">Business</option><option value="first">First</option></select>
          </label>
          <?php else: ?>
          <label class="form-control"><span class="form-label">Destination (city/code)</span><input class="input" x-model="live.destination" placeholder="DEL"></label>
          <label class="form-control"><span class="form-label">Check-in</span><input type="date" class="input" x-model="live.checkin"></label>
          <label class="form-control"><span class="form-label">Check-out</span><input type="date" class="input" x-model="live.checkout"></label>
          <label class="form-control"><span class="form-label">Rooms</span><input type="number" min="1" class="input" x-model.number="live.rooms"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="live.adults"></label>
          <?php endif; ?>
          <label class="form-control"><span class="form-label">Currency</span><input class="input" x-model="live.currency" :placeholder="baseCurrency"></label>
        </div>
        <button class="btn primary mt-3" @click="runSearch()" :disabled="searching">
          <span class="material-symbols-outlined" x-show="!searching">search</span>
          <span class="material-symbols-outlined animate-spin" x-show="searching">progress_activity</span>
          <span x-text="searching ? 'Searching…' : 'Search'"></span>
        </button>

        <!-- Live results -->
        <div class="mt-4 space-y-2" x-show="results.length">
          <template x-for="(r,idx) in results" :key="idx">
            <div class="border border-slate-200 rounded-lg p-3 flex items-center justify-between gap-3"
                 :class="selectedOffer===idx ? 'ring-2 ring-emerald-500 bg-emerald-50' : ''">
              <div class="min-w-0">
                <div class="font-medium text-slate-800 text-sm truncate" x-text="r.label"></div>
                <div class="text-xs text-slate-500" x-text="r.sub"></div>
              </div>
              <div class="text-right shrink-0">
                <div class="font-semibold tabular-nums" x-text="r.price_label"></div>
                <button class="btn sm mt-1" :class="selectedOffer===idx ? 'primary' : 'outline'" @click="selectOffer(idx)" x-text="selectedOffer===idx ? 'Selected' : 'Select'"></button>
              </div>
            </div>
          </template>
        </div>
        <p class="text-xs text-slate-400 mt-2" x-show="!results.length && searched && !searching">No live offers for those criteria.</p>
      </div>

      <!-- ============ MANUAL MODE: typed booking ============ -->
      <div class="card p-4" x-show="mode==='manual'">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">edit_note</span>Booking details (booked elsewhere)</h2>
        <?php if (!$isHotel): ?>
        <!-- flight segments -->
        <div class="space-y-3">
          <template x-for="(seg,i) in manual.segments" :key="i">
            <div class="grid sm:grid-cols-3 gap-2 border border-slate-200 rounded-lg p-3">
              <label class="form-control"><span class="form-label">Airline</span><input class="input" x-model="seg.airline" placeholder="Air Peace"></label>
              <label class="form-control"><span class="form-label">Flight #</span><input class="input" x-model="seg.flight_number" placeholder="P47123"></label>
              <label class="form-control"><span class="form-label">Airline code</span><input class="input" x-model="seg.airline_code" placeholder="P4"></label>
              <label class="form-control"><span class="form-label">From (IATA)</span><input class="input" x-model="seg.from" placeholder="LOS"></label>
              <label class="form-control"><span class="form-label">To (IATA)</span><input class="input" x-model="seg.to" placeholder="ABV"></label>
              <div></div>
              <label class="form-control"><span class="form-label">Departs</span><input type="datetime-local" class="input" x-model="seg.depart"></label>
              <label class="form-control"><span class="form-label">Arrives</span><input type="datetime-local" class="input" x-model="seg.arrive"></label>
              <div class="flex items-end"><button class="btn outline sm" @click="manual.segments.splice(i,1)" x-show="manual.segments.length>1"><span class="material-symbols-outlined">delete</span></button></div>
            </div>
          </template>
          <button class="btn outline sm" @click="addSegment()"><span class="material-symbols-outlined">add</span><span>Add segment</span></button>
          <label class="form-control"><span class="form-label">PNR / Booking reference</span><input class="input" x-model="manual.pnr" placeholder="ABC123"></label>
        </div>
        <?php else: ?>
        <!-- hotel manual -->
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">Hotel name</span><input class="input" x-model="manual.hotel_name"></label>
          <label class="form-control"><span class="form-label">Stars</span><input type="number" min="0" max="5" class="input" x-model.number="manual.hotel_stars"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Address</span><input class="input" x-model="manual.hotel_address"></label>
          <label class="form-control"><span class="form-label">City</span><input class="input" x-model="manual.hotel_city"></label>
          <label class="form-control"><span class="form-label">Country</span><input class="input" x-model="manual.hotel_country"></label>
          <label class="form-control"><span class="form-label">Check-in</span><input type="date" class="input" x-model="manual.checkin"></label>
          <label class="form-control"><span class="form-label">Check-out</span><input type="date" class="input" x-model="manual.checkout"></label>
          <label class="form-control"><span class="form-label">Room type</span><input class="input" x-model="manual.room_type"></label>
          <label class="form-control"><span class="form-label">Board</span><input class="input" x-model="manual.board_type" placeholder="Breakfast"></label>
          <label class="form-control"><span class="form-label">Rooms</span><input type="number" min="1" class="input" x-model.number="manual.rooms"></label>
          <label class="form-control"><span class="form-label">Nights</span><input type="number" min="1" class="input" x-model.number="manual.nights"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Confirmation # / PNR</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php endif; ?>
        <label class="form-control mt-3"><span class="form-label">Attach original slip / e-ticket (optional)</span>
          <input type="file" class="input" accept=".pdf,.png,.jpg,.jpeg,.webp" x-ref="manualDoc"></label>
      </div>

      <!-- ============ CUSTOMER + PASSENGERS (both modes) ============ -->
      <div class="card p-4">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">person</span>Customer</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">First name *</span><input class="input" x-model="cust.first_name"></label>
          <label class="form-control"><span class="form-label">Last name *</span><input class="input" x-model="cust.last_name"></label>
          <label class="form-control"><span class="form-label">Email *</span><input type="email" class="input" x-model="cust.email"></label>
          <label class="form-control"><span class="form-label">Phone</span><input class="input" x-model="cust.phone"></label>
        </div>

        <div class="flex items-center justify-between mt-4 mb-2">
          <h3 class="text-sm font-semibold text-slate-700">Passengers / guests</h3>
          <div class="flex gap-2">
            <button class="btn outline sm" @click="addPax('adult')"><span class="material-symbols-outlined">add</span>Adult</button>
            <button class="btn outline sm" @click="addPax('child')">+ Child</button>
          </div>
        </div>
        <p class="text-xs text-slate-500 mb-2" x-show="!isInternational">Domestic trip — name &amp; contact are enough; passport is not collected.</p>
        <p class="text-xs text-amber-600 mb-2" x-show="isInternational">International trip — passport details recommended for ticketing.</p>
        <div class="space-y-2">
          <template x-for="(p,i) in pax" :key="i">
            <div class="grid sm:grid-cols-4 gap-2 items-end border border-slate-200 rounded-lg p-2">
              <label class="form-control"><span class="form-label" x-text="p.type==='child'?'Child':'Adult'"></span>
                <select class="select input" x-model="p.title"><option>Mr</option><option>Mrs</option><option>Ms</option><option>Miss</option><option>Mstr</option></select></label>
              <label class="form-control"><span class="form-label">First</span><input class="input" x-model="p.first_name"></label>
              <label class="form-control"><span class="form-label">Last</span><input class="input" x-model="p.last_name"></label>
              <div class="flex gap-1 items-end">
                <template x-if="isInternational">
                  <input class="input" x-model="p.passport_number" placeholder="Passport #">
                </template>
                <button class="btn outline sm" @click="pax.splice(i,1)" x-show="pax.length>1"><span class="material-symbols-outlined">delete</span></button>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- ============ PRICE ============ -->
      <div class="card p-4">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">payments</span>Price</h2>
        <div class="grid sm:grid-cols-3 gap-3">
          <label class="form-control"><span class="form-label">Selling price *</span><input type="number" min="0" step="0.01" class="input" x-model.number="price.sell"></label>
          <label class="form-control"><span class="form-label">Net cost (optional)</span><input type="number" min="0" step="0.01" class="input" x-model.number="price.cost"></label>
          <label class="form-control"><span class="form-label">Currency</span><input class="input" x-model="price.currency" :placeholder="baseCurrency"></label>
        </div>
        <button class="btn primary mt-4 w-full" @click="createBooking()" :disabled="creating || created">
          <span class="material-symbols-outlined" x-show="!creating">check_circle</span>
          <span class="material-symbols-outlined animate-spin" x-show="creating">progress_activity</span>
          <span x-text="created ? 'Booking created ✓' : (creating ? 'Creating…' : 'Create booking')"></span>
        </button>
      </div>
    </div>

    <!-- RIGHT: payment step + recent -->
    <div class="space-y-5">
      <div class="card p-4" x-show="created">
        <h2 class="text-base font-semibold text-slate-800 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-lg">account_balance_wallet</span>Take payment</h2>
        <p class="text-xs text-slate-500 mb-3">Invoice <span class="font-mono" x-text="invoiceId"></span> · <span x-text="price.currency||baseCurrency"></span> <span class="tabular-nums" x-text="Number(price.sell).toLocaleString()"></span></p>

        <div class="space-y-2">
          <button class="btn outline w-full justify-start" @click="payDva()" :disabled="paying">
            <span class="material-symbols-outlined">qr_code_2</span><span>Generate virtual account (bank transfer)</span>
          </button>
          <button class="btn outline w-full justify-start" @click="payManual()" :disabled="paying">
            <span class="material-symbols-outlined">account_balance</span><span>Manual bank transfer to our account</span>
          </button>
          <button class="btn outline w-full justify-start" @click="payOffline()" :disabled="paying" x-show="mode==='manual'">
            <span class="material-symbols-outlined">done_all</span><span>Mark paid (settled offline)</span>
          </button>
        </div>

        <!-- DVA result -->
        <div class="mt-4 p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm" x-show="dva">
          <div class="font-semibold text-slate-800 mb-1">Virtual account</div>
          <div class="text-slate-600">Bank: <span class="font-medium" x-text="dva?.bank_name"></span></div>
          <div class="text-slate-600">Account: <span class="font-mono font-medium" x-text="dva?.account_number"></span></div>
          <div class="text-slate-600">Name: <span x-text="dva?.account_name"></span></div>
          <div class="text-xs text-slate-400 mt-1">Confirms automatically when the transfer lands.</div>
        </div>

        <!-- Manual transfer / company account -->
        <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm" x-show="manualPay">
          <template x-if="companyBank">
            <div>
              <div class="font-semibold text-amber-800 mb-1">Company account (give to customer)</div>
              <div class="text-amber-700">Bank addr: <span x-text="companyBank?.bank_address"></span></div>
              <div class="text-amber-700">Account name: <span x-text="companyBank?.account_holder"></span></div>
              <div class="text-amber-700">Account #: <span class="font-mono" x-text="companyBank?.account_number"></span></div>
              <div class="text-amber-700" x-show="companyBank?.iban_swift">IBAN/SWIFT: <span class="font-mono" x-text="companyBank?.iban_swift"></span></div>
              <div class="text-xs text-amber-600 mt-2">After the transfer lands, verify it on the <a class="underline" :href="base+'admin/booking-manager/verify'">verification page</a> to confirm the booking.</div>
            </div>
          </template>
          <template x-if="!companyBank">
            <div class="text-amber-700">Manual transfer recorded. A senior admin will confirm the company account and verify the payment. This booking is <strong>awaiting verification</strong>.</div>
          </template>
        </div>

        <div class="mt-3 text-sm" :class="settleMsgOk ? 'text-emerald-700' : 'text-red-600'" x-show="settleMsg" x-text="settleMsg"></div>
      </div>

      <!-- Recent admin bookings -->
      <div class="card p-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-2">Recent office bookings</h2>
        <div class="space-y-1 max-h-96 overflow-y-auto">
          <?php foreach (($bm['recent'] ?? []) as $r): ?>
            <div class="text-xs flex items-center justify-between gap-2 border-b border-slate-100 py-1">
              <div class="min-w-0">
                <div class="font-mono text-slate-700"><?= htmlspecialchars($r['invoice_id']) ?></div>
                <div class="text-slate-500 truncate"><?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?> · <?= htmlspecialchars($r['module'] ?? '') ?></div>
              </div>
              <div class="text-right shrink-0">
                <span class="badge <?= ($r['payment_status'] ?? '') === 'paid' ? 'badge-success' : (($r['payment_status'] ?? '') === 'awaiting_verification' ? 'badge-gray' : 'badge-error') ?>"><?= htmlspecialchars($r['payment_status'] ?? '') ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($bm['recent'])): ?><p class="text-xs text-slate-400">No office bookings yet.</p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function bookingMgr() {
  return {
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    base: '<?= root ?>',
    service: '<?= $svc ?>',
    serviceType: '<?= $isHotel ? 'stays' : 'flights' ?>',
    isHotel: <?= $isHotel ? 'true' : 'false' ?>,
    suppliers: <?= json_encode($bm['suppliers'] ?? []) ?>,
    baseCurrency: '<?= htmlspecialchars($bm['base_currency'] ?? 'NGN') ?>',
    companyBank: <?= json_encode($bm['company_bank'] ?? null) ?>,
    isSenior: <?= !empty($bm['is_senior']) ? 'true' : 'false' ?>,

    mode: 'manual',
    live: { supplier:'', type:'oneway', origin:'', destination:'', departure_date:'', return_date:'', adults:1, children:0, class:'economy', rooms:1, checkin:'', checkout:'', currency:'' },
    manual: { segments:[{airline:'',flight_number:'',airline_code:'',from:'',to:'',depart:'',arrive:''}], pnr:'', hotel_name:'', hotel_stars:0, hotel_address:'', hotel_city:'', hotel_country:'', checkin:'', checkout:'', room_type:'', board_type:'', rooms:1, nights:1 },
    cust: { first_name:'', last_name:'', email:'', phone:'' },
    pax: [{ type:'adult', title:'Mr', first_name:'', last_name:'', passport_number:'' }],
    price: { sell:0, cost:0, currency:'' },

    searching:false, searched:false, results:[], selectedOffer:null, selectedRaw:null,
    creating:false, created:false, invoiceId:'', clientToken:'',
    paying:false, dva:null, manualPay:false, settleMsg:'', settleMsgOk:false,

    init(){ this.clientToken = (Date.now().toString(36) + Math.random().toString(36).slice(2,8)); },

    get isInternational(){
      // Flights only: different origin/destination countries can't be known
      // client-side from IATA alone, so default to DOMESTIC unless the staff
      // flag it. Simple heuristic: international checkbox-free — treat as domestic
      // for manual; for live we rely on the supplier. Keep passport optional.
      return false;
    },

    addSegment(){ this.manual.segments.push({airline:'',flight_number:'',airline_code:'',from:'',to:'',depart:'',arrive:''}); },
    addPax(t){ this.pax.push({ type:t, title: t==='child'?'Mstr':'Mr', first_name:'', last_name:'', passport_number:'' }); },

    form(o){ const f=new FormData(); f.append('csrf_token',this.csrf); for(const k in o){ let v=o[k]; if(v===null||v===undefined) v=''; if(typeof v==='object') v=JSON.stringify(v); f.append(k,v);} return f; },

    async runSearch(){
      if(!this.live.supplier){ alert('Choose a supplier'); return; }
      this.searching=true; this.searched=false; this.results=[];
      try{
        const p = Object.assign({ service:this.service, supplier:this.live.supplier }, this.live);
        if(!p.currency) p.currency=this.baseCurrency;
        const res = await fetch(this.base+'admin/booking-manager/search',{method:'POST',body:this.form(p)});
        const data = await res.json();
        this.results = this.normalize(data);
      }catch(e){ alert('Search failed'); }
      this.searching=false; this.searched=true;
    },
    normalize(data){
      // Accept both wrapped {results:[...]} (hotels) and bare flight arrays.
      let list = Array.isArray(data) ? data : (data.results || data.offers || []);
      if(!Array.isArray(list)) list=[];
      return list.map(it=>{
        if(this.isHotel){
          return { label: it.name||'Hotel', sub: (it.stars?it.stars+'★ · ':'')+(it.location||''), price_label:(it.currency||'')+' '+Number(it.price||it.display_price||0).toLocaleString(), raw: it };
        }
        const seg = (it.segments && it.segments[0] && it.segments[0][0]) ? it.segments[0][0] : it;
        return { label:(seg.airline||'')+' '+(seg.flight_no||''), sub:(seg.departure_code||seg.departure_airport||'')+' → '+(seg.arrival_code||seg.arrival_airport||''), price_label:(seg.currency||'')+' '+Number(it.price||seg.price||0).toLocaleString(), raw: it };
      });
    },
    selectOffer(i){ this.selectedOffer=i; this.selectedRaw=this.results[i].raw;
      // prefill price from the offer
      const r=this.results[i].raw; const seg=(r.segments&&r.segments[0]&&r.segments[0][0])?r.segments[0][0]:r;
      this.price.sell = Number(r.price||seg.price||r.display_price||0)||this.price.sell;
      this.price.currency = seg.currency||r.currency||this.price.currency;
    },

    buildPassengers(){
      return this.pax.filter(p=>p.first_name&&p.last_name).map(p=>({ type:p.type, title:p.title, first_name:p.first_name, last_name:p.last_name, passport_number:p.passport_number||'' }));
    },

    async createBooking(){
      if(!this.cust.first_name||!this.cust.last_name||!this.cust.email){ alert('Customer first/last name and email are required'); return; }
      if(!(this.price.sell>0)){ alert('Enter a selling price'); return; }
      if(this.mode==='live' && this.selectedRaw===null){ alert('Select a supplier offer first'); return; }
      this.creating=true;
      try{
        const payload = {
          mode:this.mode, service:this.service, client_token:this.clientToken,
          first_name:this.cust.first_name, last_name:this.cust.last_name, email:this.cust.email, phone:this.cust.phone,
          price:this.price.sell, cost_price:this.price.cost||this.price.sell, currency:this.price.currency||this.baseCurrency,
          passengers: JSON.stringify(this.buildPassengers()),
          pnr: this.manual.pnr||'',
        };
        if(this.mode==='live'){
          payload.supplier=this.live.supplier;
          const r=this.selectedRaw; const seg=(r.segments&&r.segments[0]&&r.segments[0][0])?r.segments[0][0]:r;
          payload.offer_booking_data = JSON.stringify(seg.booking_data || r.booking_data || r);
          if(this.isHotel){ payload.selected_rooms = JSON.stringify(r.room_options||[]); }
        } else {
          if(this.isHotel){ Object.assign(payload,{hotel_name:this.manual.hotel_name,hotel_stars:this.manual.hotel_stars,hotel_address:this.manual.hotel_address,hotel_city:this.manual.hotel_city,hotel_country:this.manual.hotel_country,checkin:this.manual.checkin,checkout:this.manual.checkout,room_type:this.manual.room_type,board_type:this.manual.board_type,rooms:this.manual.rooms,nights:this.manual.nights}); }
          else { payload.segments = JSON.stringify(this.manual.segments); }
        }
        // multipart to allow the slip upload
        const f=this.form(payload);
        const file=this.$refs.manualDoc; if(this.mode==='manual'&&file&&file.files&&file.files[0]) f.append('manual_document',file.files[0]);
        const res=await fetch(this.base+'admin/booking-manager/create',{method:'POST',body:f});
        const data=await res.json();
        if(data.status){ this.created=true; this.invoiceId=data.invoice_id; }
        else alert(data.message||'Could not create the booking');
      }catch(e){ alert('Create failed'); }
      this.creating=false;
    },

    async payDva(){ await this.pay('dva'); },
    async payManual(){ await this.pay('manual_transfer'); },
    async payOffline(){ if(!confirm('Mark this booking paid (settled offline)?')) return; await this.pay('mark_paid_offline'); },
    async pay(method){
      this.paying=true; this.settleMsg='';
      try{
        const res=await fetch(this.base+'admin/booking-manager/pay',{method:'POST',body:this.form({invoice_id:this.invoiceId,method})});
        const data=await res.json();
        if(method==='dva'){ this.dva=data.dva||null; this.manualPay=false; }
        else if(method==='manual_transfer'){ this.manualPay=true; this.dva=null; this.companyBank=data.company_bank||this.companyBank; }
        this.settleMsgOk=!!data.status; this.settleMsg=data.message||'';
      }catch(e){ this.settleMsgOk=false; this.settleMsg='Payment step failed'; }
      this.paying=false;
    },
  };
}
</script>
