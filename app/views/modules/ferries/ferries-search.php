<?php
// ============================================================================
// FERRIES SEARCH WIDGET
// ============================================================================
// Renders port-to-port search form used on the home page and listing page.
// Matches the flights search widget design exactly:
//   - Segmented pill trip-type toggle (one-way / round-trip)
//   - field-box field-box-split date container with individual hidden toggles
//   - input-dropdown passengers with +/- counters
//   - Custom jQuery datepicker (FerriesDeparture / FerriesReturn classes)
// ISO dates stored in hidden inputs so URL is always yyyy-mm-dd.
// ============================================================================
@$SECURE or die('Access Denied!');

$ferriesModule  = $db->get('modules', ['id', 'status'], ['type' => 'ferries', 'active' => '1', 'status' => '1']);
$ferriesEnabled = !empty($ferriesModule);
$searchParams   = $_SESSION['ferries_search'] ?? [];

// Resolve selected bonus label so the field shows "Youth card" not "Bonus #15" after search
require_once dirname(__DIR__, 4) . '/modules/ferries/kikoto/api.php';
$preselectedBonusId = (int)(array_values(array_filter(array_map('intval', (array)($searchParams['bonuses'] ?? []))))[0] ?? 0);
$preselectedBonusName = '';
$preselectedBonusType = '';
if ($preselectedBonusId > 0 && function_exists('_kikoto_resolve_bonus_labels')) {
    $resolvedBonus = _kikoto_resolve_bonus_labels([$preselectedBonusId]);
    $preselectedBonusName = (string)($resolvedBonus[0]['name'] ?? '');
    $preselectedBonusType = (string)($resolvedBonus[0]['type'] ?? '');
}

// PRE-FILL VALUES FROM SESSION
$isoDefault       = date('Y-m-d', strtotime('+1 day'));
$isoReturnDefault = date('Y-m-d', strtotime('+2 days'));
$isoStored        = $searchParams['date']        ?? $isoDefault;
$isoReturnStored  = $searchParams['return_date'] ?? $isoReturnDefault;
$tripTypeStored   = $searchParams['trip_type']   ?? 'oneway';
$isRoundTrip      = ($tripTypeStored === 'return');

if (!function_exists('_ferries_to_display')) {
    function _ferries_to_display(string $isoDate): string {
        $ts = strtotime($isoDate);
        return $ts ? date('d-m-Y', $ts) : date('d-m-Y', strtotime('+1 day'));
    }
}
$displayDep    = _ferries_to_display($isoStored);
$displayReturn = _ferries_to_display($isoReturnStored);
?>

<script>
function ferriesSearchData() {
    return {
        // LAZY-LOADED DATA — nothing fetched until user opens departure dropdown
        ports: [], routes: [],
        portsLoaded:  false,   // TRUE after first successful fetch
        portsLoading: false,   // spinner inside departure dropdown
        isSearching:  false,
        showAlert: false, alertMessage: '',

        // FORM VALUES — pre-filled from session (port IDs for pre-selected state)
        depPortId:  <?= (int)($searchParams['departure_port_id']   ?? 0) ?>,
        destPortId: <?= (int)($searchParams['destination_port_id'] ?? 0) ?>,
        depPortName:  '<?= addslashes($searchParams['departure_port_name']   ?? '') ?>',
        destPortName: '<?= addslashes($searchParams['destination_port_name'] ?? '') ?>',
        tripType:   '<?= htmlspecialchars($tripTypeStored) ?>',
        adults:     <?= (int)($searchParams['adults']   ?? 1) ?>,
        children:   <?= (int)($searchParams['children'] ?? 0) ?>,
        infant:     <?= (int)($searchParams['infant']   ?? 0) ?>,
        vehicles:   <?= (int)($searchParams['vehicles'] ?? 0) ?>,
        vehicleType: <?= json_encode((string)($searchParams['vehicle_type'] ?? '')) ?>,
        pets:       <?= (int)($searchParams['pets']     ?? 0) ?>,
        petType:    <?= json_encode((string)($searchParams['pet_type'] ?? '')) ?>,
        selectedBonusId: <?= (int)$preselectedBonusId ?>,
        selectedBonusName: <?= json_encode($preselectedBonusName) ?>,
        selectedBonusType: <?= json_encode($preselectedBonusType) ?>,
        bonuses: [],
        bonusesLoaded: false,
        bonusesLoading: false,

        // VEHICLE TYPE OPTIONS — search-time preference; the operator's real ticket
        // types are shown again (and take precedence) on the booking form itself.
        vehicleTypes: [
            { key: 'car',        label: '<?= T::tourism ?? 'Tourism' ?>',   icon: 'directions_car' },
            { key: 'van',        label: '<?= T::van ?? 'Van' ?>',          icon: 'airport_shuttle' },
            { key: 'motorcycle', label: '<?= T::motorcycle ?? 'Motorcycle' ?>', icon: 'two_wheeler' },
            { key: 'moped',      label: '<?= T::moped ?? 'Moped' ?>',       icon: 'moped' },
            { key: 'bicycle',    label: '<?= T::bicycle ?? 'Bicycle' ?>',   icon: 'pedal_bike' },
        ],

        // PET CAGE TYPE OPTIONS — same search-time preference pattern as vehicles
        petTypes: [
            { key: 'carrier',     label: '<?= T::carrier ?? 'Carrier' ?>',      icon: 'luggage' },
            { key: 'medium_cage', label: '<?= T::medium_cage ?? 'Medium cage' ?>', icon: 'crib' },
            { key: 'large_cage',  label: '<?= T::large_cage ?? 'Large cage' ?>',  icon: 'crib' },
        ],

        // DROPDOWN OPEN STATES
        depOpen: false, destOpen: false, passOpen: false, vehOpen: false, petOpen: false,
        bonusOpen: false, largeFamilyOpen: false, residenceOpen: false,
        depSearch: '', destSearch: '',

        get selectedRoute() {
            if (!this.depPortId || !this.destPortId || !this.routes.length) return null;
            return this.routes.find(r =>
                r.departure_port_id === this.depPortId &&
                r.destination_port_id === this.destPortId) || null;
        },
        get routeSupportsVehicles() {
            const svc = this.selectedRoute?.services;
            return svc ? !!svc.vehicles : true;
        },
        get routeSupportsPets() {
            const svc = this.selectedRoute?.services;
            return svc ? !!svc.pets : true;
        },

        // PASSENGER TEXT — adults/children/infants only; vehicles/pets/bonuses have their own boxes
        getPassengerText() {
            const parts = [];
            if (this.adults   > 0) parts.push(this.adults   + ' Adult'  + (this.adults   > 1 ? 's'   : ''));
            if (this.children > 0) parts.push(this.children + ' Child'  + (this.children > 1 ? 'ren' : ''));
            if (this.infant   > 0) parts.push(this.infant   + ' Infant' + (this.infant   > 1 ? 's'   : ''));
            return parts.join(', ') || '1 Adult';
        },
        increment(type) {
            if (type === 'adults'   && this.adults   < 9) this.adults++;
            if (type === 'children' && this.children < 8) this.children++;
            if (type === 'infant'   && this.infant   < 9) this.infant++;
        },
        decrement(type) {
            if (type === 'adults'   && this.adults   > 1) this.adults--;
            if (type === 'children' && this.children > 0) this.children--;
            if (type === 'infant'   && this.infant   > 0) this.infant--;
        },

        getVehicleText() {
            if (this.vehicles) {
                const match = this.vehicleTypes.find(v => v.key === this.vehicleType);
                if (match) return match.label;
            }
            return '<?= T::vehicles ?? 'Vehicles' ?>';
        },
        noVehicle() {
            this.vehicles = 0;
            this.vehicleType = '';
        },
        selectVehicleType(key) {
            this.vehicleType = key;
            this.vehicles = 1;
        },
        getPetsText() {
            if (this.pets) {
                const match = this.petTypes.find(p => p.key === this.petType);
                if (match) return match.label;
            }
            return '<?= T::pets ?? 'Pets' ?>';
        },
        noPet() {
            this.pets = 0;
            this.petType = '';
        },
        selectPetType(key) {
            this.petType = key;
            this.pets = 1;
        },

        // BONUS / DISCOUNT — generic discount types only (excludes large-family and residence,
        // which get their own dedicated fields below since the API tags them by `type`).
        get discountBonuses() {
            return (this.bonuses || []).filter(b => !['large-family', 'residence'].includes(b.type || ''));
        },
        // LARGE FAMILY — a distinct discount category, same underlying single-bonus selection
        get largeFamilyBonuses() {
            return (this.bonuses || []).filter(b => (b.type || '') === 'large-family');
        },
        // RESIDENCE — e.g. "Resident in the Balearic Islands" / "Resident in Ceuta"
        get residenceBonuses() {
            return (this.bonuses || []).filter(b => (b.type || '') === 'residence');
        },

        getDiscountText() {
            if (this.selectedBonusId && !['large-family', 'residence'].includes(this.selectedBonusType)) {
                return this.selectedBonusName || ('<?= T::bonus ?? 'Bonus' ?> #' + this.selectedBonusId);
            }
            return '<?= T::bonus ?? 'Bonus' ?>';
        },
        getLargeFamilyText() {
            if (this.selectedBonusId && this.selectedBonusType === 'large-family') {
                return this.selectedBonusName || ('<?= T::bonus ?? 'Bonus' ?> #' + this.selectedBonusId);
            }
            return '<?= T::large_family ?? 'Large Family' ?>';
        },
        getResidenceText() {
            if (this.selectedBonusId && this.selectedBonusType === 'residence') {
                return this.selectedBonusName || ('<?= T::bonus ?? 'Bonus' ?> #' + this.selectedBonusId);
            }
            return '<?= T::residence ?? 'Residence' ?>';
        },
        // Opens one of the vehicle/pet/bonus/large-family/residence boxes, closing the others.
        async openBox(which) {
            this.depOpen = false; this.destOpen = false; this.passOpen = false;
            this.vehOpen = which === 'vehicles' ? !this.vehOpen : false;
            this.petOpen = which === 'pets' ? !this.petOpen : false;
            this.bonusOpen = which === 'bonus' ? !this.bonusOpen : false;
            this.largeFamilyOpen = which === 'largeFamily' ? !this.largeFamilyOpen : false;
            this.residenceOpen = which === 'residence' ? !this.residenceOpen : false;
            if ((which === 'bonus' || which === 'largeFamily' || which === 'residence')
                && (this.bonusOpen || this.largeFamilyOpen || this.residenceOpen)) {
                if (!this.depPortId) {
                    this.alertMessage = '<?= T::select_departure_port ?? 'Please select departure port first' ?>';
                    this.showAlert = true;
                    setTimeout(() => this.showAlert = false, 3000);
                    this.bonusOpen = false;
                    this.largeFamilyOpen = false;
                    this.residenceOpen = false;
                    return;
                }
                await this.loadBonuses();
            }
        },

        async loadBonuses() {
            if (this.bonusesLoaded || this.bonusesLoading) return;
            this.bonusesLoading = true;
            try {
                const res = await fetch('<?= root ?>api/ferries/bonuses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        lang: 'en',
                        departure_port_id: this.depPortId || 0,
                        destination_port_id: this.destPortId || 0,
                    }),
                });
                const json = await res.json();
                const payload = json.data;
                this.bonuses = Array.isArray(payload)
                    ? payload
                    : (Array.isArray(payload?.bonuses) ? payload.bonuses : []);
                this.bonusesLoaded = true;
                // Refresh label/type if we already have a selected id
                const id = parseInt(this.selectedBonusId, 10) || 0;
                if (id) {
                    const match = (this.bonuses || []).find(b => parseInt(b.id, 10) === id);
                    if (match) {
                        this.selectedBonusName = match.name || this.selectedBonusName;
                        this.selectedBonusType = match.type || this.selectedBonusType;
                    }
                }
            } catch (e) {
                this.bonuses = [];
            } finally {
                this.bonusesLoading = false;
            }
        },

        // Immediate select — no separate "Apply" step, matches the vehicle/pet box pattern
        chooseBonus(b) {
            this.selectedBonusId = b ? parseInt(b.id, 10) || 0 : 0;
            this.selectedBonusName = b ? (b.name || '') : '';
            this.selectedBonusType = b ? (b.type || '') : '';
            this.bonusOpen = false;
            this.largeFamilyOpen = false;
            this.residenceOpen = false;
        },

        // RESOLVED PORT NAME — uses session name until ports load, then resolves from data
        getPortName(id) {
            if (!id) return '';
            if (this.ports.length) {
                const p = this.ports.find(p => p.id === id);
                return p ? p.name : (id === this.depPortId ? this.depPortName : this.destPortName);
            }
            return id === this.depPortId ? this.depPortName : this.destPortName;
        },

        // FILTERED DEPARTURE PORTS
        get filteredDepPorts() {
            const q = (this.depSearch || '').toLowerCase();
            return this.ports.filter(p =>
                p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q));
        },

        // FILTERED DESTINATION PORTS — only valid routes from selected departure
        get filteredDestPorts() {
            if (!this.depPortId || !this.ports.length) return [];
            const validIds = this.routes
                .filter(r => r.departure_port_id === this.depPortId)
                .map(r => r.destination_port_id);
            const q = (this.destSearch || '').toLowerCase();
            return this.ports.filter(p =>
                validIds.includes(p.id) &&
                (p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q)));
        },

        // LAZY FETCH — called on first departure dropdown open only
        async loadPorts() {
            if (this.portsLoaded || this.portsLoading) return;
            this.portsLoading = true;
            try {
                const [pr, rr] = await Promise.all([
                    fetch('<?= root ?>api/ferries/ports',  { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' }),
                    fetch('<?= root ?>api/ferries/routes', { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' }),
                ]);
                const pd = await pr.json(), rd = await rr.json();
                this.ports  = pd.data  || [];
                this.routes = rd.data  || [];
                this.portsLoaded = true;
            } catch(e) {
                this.alertMessage = 'Could not load ports. Please try again.';
                this.showAlert = true;
                setTimeout(() => this.showAlert = false, 4000);
            } finally {
                this.portsLoading = false;
            }
        },

        closeAllBoxes() {
            this.destOpen = false;
            this.passOpen = false;
            this.vehOpen = false;
            this.petOpen = false;
            this.bonusOpen = false;
            this.largeFamilyOpen = false;
            this.residenceOpen = false;
        },

        // OPEN DEPARTURE DROPDOWN — triggers lazy load
        async openDep() {
            const wasOpen = this.depOpen;
            this.closeAllBoxes();
            this.depOpen = !wasOpen;
            if (this.depOpen) await this.loadPorts();
        },

        // OPEN DESTINATION DROPDOWN — only after departure selected
        async openDest() {
            if (!this.depPortId) return; // silently ignore until dep is chosen
            const wasOpen = this.destOpen;
            this.closeAllBoxes();
            this.depOpen = false;
            this.destOpen = !wasOpen;
            if (this.destOpen) await this.loadPorts(); // no-op if already loaded
        },

        // SELECT DEPARTURE PORT — clear destination, close dropdown
        selectDep(p) {
            this.depPortId   = p.id;
            this.depPortName = p.name;
            this.destPortId  = 0;
            this.destPortName = '';
            this.depOpen  = false;
            this.depSearch = '';
            this.bonusesLoaded = false;
            this.bonuses = [];
            this.selectedBonusId = 0;
            this.selectedBonusName = '';
            this.selectedBonusType = '';
            // Auto-open destination after a tick
            this.$nextTick(() => { this.destOpen = true; });
        },

        // SELECT DESTINATION PORT
        selectDest(p) {
            this.destPortId   = p.id;
            this.destPortName = p.name;
            this.destOpen  = false;
            this.destSearch = '';
            this.bonusesLoaded = false;
            this.bonuses = [];
            // Destination affects which residence bonuses apply — clear prior pick
            this.selectedBonusId = 0;
            this.selectedBonusName = '';
            this.selectedBonusType = '';
            if (!this.routeSupportsVehicles) this.vehicles = 0;
            if (!this.routeSupportsPets) this.pets = 0;
        },

        // SWAP PORTS
        swapPorts() {
            if (!this.depPortId || !this.destPortId) return;
            const ok = !this.portsLoaded || this.routes.some(r =>
                r.departure_port_id === this.destPortId && r.destination_port_id === this.depPortId);
            if (!ok) {
                this.alertMessage = '<?= T::no_reverse_route ?? "Reverse route not available" ?>';
                this.showAlert = true; setTimeout(() => this.showAlert = false, 3000); return;
            }
            [this.depPortId,   this.destPortId]   = [this.destPortId,   this.depPortId];
            [this.depPortName, this.destPortName] = [this.destPortName, this.depPortName];
        },

        // SWITCH TRIP TYPE — mirrors flights selectType()
        setTripType(type) {
            this.tripType = type;
            const arrow  = document.getElementById('ferries_return_arrow');
            const picker = document.getElementById('ferries_return_date_picker');
            const show   = type === 'return';
            if (arrow)  arrow.style.display  = show ? 'flex' : 'none';
            if (picker) picker.style.display = show ? 'flex' : 'none';
        },

        // INIT — restore trip type; resolve bonus label if already selected from URL/session
        init() {
            this.setTripType(this.tripType);
            if (this.selectedBonusId && this.depPortId && !this.selectedBonusName) {
                this.loadBonuses();
            }
        },

        // VALIDATE + REDIRECT
        // ISO dates are kept in hidden inputs updated by datepicker changeDate callbacks
        search() {
            this.showAlert = false;
            if (!this.depPortId)  { this.alertMessage = '<?= T::select_departure_port ?? "Please select departure port" ?>'; this.showAlert = true; return; }
            if (!this.destPortId) { this.alertMessage = '<?= T::select_arrival_port ?? "Please select arrival port" ?>'; this.showAlert = true; return; }
            const isoDate = document.getElementById('ferries_dep_iso')?.value || '';
            if (!isoDate) { this.alertMessage = '<?= T::select_date ?? "Please select a date" ?>'; this.showAlert = true; return; }
            const extras = new URLSearchParams();
            if (this.vehicles > 0) extras.set('vehicles', this.vehicles);
            if (this.vehicles > 0 && this.vehicleType) extras.set('vehicle_type', this.vehicleType);
            if (this.pets > 0) extras.set('pets', this.pets);
            if (this.pets > 0 && this.petType) extras.set('pet_type', this.petType);
            if (parseInt(this.selectedBonusId, 10) > 0) extras.set('bonuses', String(parseInt(this.selectedBonusId, 10)));
            const qs = extras.toString() ? `?${extras.toString()}` : '';

            if (this.tripType === 'return') {
                const isoRet = document.getElementById('ferries_ret_iso')?.value || '';
                if (!isoRet) { this.alertMessage = '<?= T::select_return_date ?? "Please select a return date" ?>'; this.showAlert = true; return; }
                this.isSearching = true;
                window.location.href = `<?= root ?>ferries/${this.depPortId}/${this.destPortId}/${isoDate}/${isoRet}/${this.adults}/${this.children}/${this.infant}${qs}`;
                return;
            }
            this.isSearching = true;
            window.location.href = `<?= root ?>ferries/${this.depPortId}/${this.destPortId}/${isoDate}/${this.adults}/${this.children}/${this.infant}${qs}`;
        },
    };
}
</script>

<?php if (!$ferriesEnabled): ?>
<div class="alert alert-error">
    <span class="material-symbols-outlined">warning</span>
    <p>Ferry bookings are currently unavailable. Please try again later.</p>
</div>
<?php else: ?>

<!-- HIDDEN ISO DATE STORAGE (updated by datepicker changeDate in datepicker.js) -->
<input type="hidden" id="ferries_dep_iso" value="<?= htmlspecialchars($isoStored) ?>">
<input type="hidden" id="ferries_ret_iso" value="<?= htmlspecialchars($isoReturnStored) ?>">

<div x-data="ferriesSearchData()" x-init="init()">

    <!-- ALERT -->
    <div x-show="showAlert" x-transition class="alert alert-error mb-4" style="display:none">
        <span class="material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form @submit.prevent="search()" class="space-y-4">

        <!-- ROW 1: Trip type pills + small Vehicle/Pet/Bonus/Large-Family pills (cars-style) -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div x-data class="inline-flex items-center bg-[#f2f4f7] rounded-md p-1">
                <button type="button" @click="setTripType('oneway')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="tripType==='oneway' ? 'bg-white text-[#101828] shadow-sm border border-[#d8dce3]' : 'text-[#475467] hover:text-[#101828]'">
                    <span class="material-symbols-outlined text-base hidden sm:flex">trending_flat</span>
                    <span><?= T::one_way ?? 'One Way' ?></span>
                </button>
                <button type="button" @click="setTripType('return')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="tripType==='return' ? 'bg-white text-[#101828] shadow-sm border border-[#d8dce3]' : 'text-[#475467] hover:text-[#101828]'">
                    <span class="material-symbols-outlined text-base hidden sm:flex">sync_alt</span>
                    <span><?= T::round_trip ?? 'Round Trip' ?></span>
                </button>
            </div>

            <!-- SMALL PILLS: Vehicles / Pets / Bonus-Discount / Large Family -->
            <div class="flex flex-wrap items-center gap-2">

                <!-- VEHICLES -->
                <div class="input-dropdown" x-show="routeSupportsVehicles" @click.away="vehOpen=false">
                    <div @click="openBox('vehicles')" class="inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                        <span class="material-symbols-outlined text-base">directions_car</span>
                        <span x-text="getVehicleText()"></span>
                        <span class="material-symbols-outlined text-base transition-transform" :class="vehOpen ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content" :class="vehOpen ? 'show' : ''" style="min-width:320px;">
                        <div class="p-4">
                            <label class="flex items-center gap-2 mb-4 cursor-pointer" @click.stop="noVehicle()">
                                <input type="radio" name="ferry_vehicle_none" class="border-slate-300 text-[#1570ef] focus:ring-[#1570ef]" :checked="!vehicles" @change="noVehicle()">
                                <span class="text-sm font-medium text-slate-800"><?= T::travel_without_vehicle ?? 'I travel without a vehicle' ?></span>
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="v in vehicleTypes" :key="v.key">
                                    <div class="flex flex-col items-center justify-center gap-1.5 p-3 rounded-lg border cursor-pointer hover:border-[#1570ef] transition-colors"
                                         :class="vehicles && vehicleType === v.key ? 'border-[#1570ef] ring-1 ring-[#1570ef]' : 'border-gray-200'"
                                         @click.stop="selectVehicleType(v.key)">
                                        <span class="material-symbols-outlined text-2xl" :class="vehicles && vehicleType === v.key ? 'text-[#1570ef]' : 'text-slate-700'" x-text="v.icon"></span>
                                        <span class="text-xs font-medium text-center" :class="vehicles && vehicleType === v.key ? 'text-[#1570ef]' : 'text-slate-700'" x-text="v.label"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PETS -->
                <div class="input-dropdown" x-show="routeSupportsPets" @click.away="petOpen=false">
                    <div @click="openBox('pets')" class="inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                        <span class="material-symbols-outlined text-base">pets</span>
                        <span x-text="getPetsText()"></span>
                        <span class="material-symbols-outlined text-base transition-transform" :class="petOpen ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content" :class="petOpen ? 'show' : ''" style="min-width:320px;">
                        <div class="p-4">
                            <label class="flex items-center gap-2 mb-4 cursor-pointer" @click.stop="noPet()">
                                <input type="radio" name="ferry_pet_none" class="border-slate-300 text-[#1570ef] focus:ring-[#1570ef]" :checked="!pets" @change="noPet()">
                                <span class="text-sm font-medium text-slate-800"><?= T::travel_without_pet ?? 'I travel without a pet' ?></span>
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="p in petTypes" :key="p.key">
                                    <div class="flex flex-col items-center justify-center gap-1.5 p-3 rounded-lg border cursor-pointer hover:border-[#1570ef] transition-colors"
                                         :class="pets && petType === p.key ? 'border-[#1570ef] ring-1 ring-[#1570ef]' : 'border-gray-200'"
                                         @click.stop="selectPetType(p.key)">
                                        <span class="material-symbols-outlined text-2xl" :class="pets && petType === p.key ? 'text-[#1570ef]' : 'text-slate-700'" x-text="p.icon"></span>
                                        <span class="text-xs font-medium text-center" :class="pets && petType === p.key ? 'text-[#1570ef]' : 'text-slate-700'" x-text="p.label"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BONUS -->
                <div class="input-dropdown" @click.away="bonusOpen=false">
                    <div @click="openBox('bonus')" class="inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                        <span class="material-symbols-outlined text-base">sell</span>
                        <span x-text="getDiscountText()"></span>
                        <span class="material-symbols-outlined text-base transition-transform" :class="bonusOpen ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content left-auto right-0" :class="bonusOpen ? 'show' : ''" style="min-width:280px;">
                        <div x-show="bonusesLoading" class="flex items-center justify-center gap-2 py-6 text-sm text-slate-500">
                            <span class="material-symbols-outlined animate-spin text-blue-500">progress_activity</span>
                            <?= T::loading ?? 'Loading...' ?>
                        </div>
                        <template x-if="!bonusesLoading">
                            <div>
                                <div class="mx-4 mt-3 mb-1 p-3 rounded-lg bg-blue-50 border border-blue-100 flex items-start gap-2">
                                    <span class="material-symbols-outlined text-blue-500 flex-shrink-0" style="font-size:18px">info</span>
                                    <div>
                                        <div class="text-xs font-semibold text-slate-800"><?= T::important_information ?? 'Important information' ?></div>
                                        <div class="text-xs text-slate-600 mt-0.5"><?= T::discount_applies_to_all_passengers ?? 'All passengers must be subject to the same discount, otherwise separate bookings must be made.' ?></div>
                                    </div>
                                </div>
                                <div class="input-dropdown-item" @click="chooseBonus(null)">
                                    <span class="font-semibold text-slate-900" :class="!selectedBonusId ? 'text-[#1570ef]' : ''"><?= T::none ?? 'None' ?></span>
                                </div>
                                <template x-for="b in discountBonuses" :key="b.id">
                                    <div class="input-dropdown-item flex-col items-start gap-0.5" @click="chooseBonus(b)">
                                        <span class="font-semibold" :class="parseInt(selectedBonusId,10)===parseInt(b.id,10) ? 'text-[#1570ef]' : 'text-slate-900'" x-text="b.name"></span>
                                        <span class="text-xs text-slate-500 font-normal" x-show="b.description" x-text="b.description"></span>
                                    </div>
                                </template>
                                <div x-show="!discountBonuses.length" class="px-4 py-4 text-center text-xs text-slate-400">
                                    <?= T::no_bonuses_available ?? 'No bonus types available for this route' ?>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- LARGE FAMILY -->
                <div class="input-dropdown" @click.away="largeFamilyOpen=false">
                    <div @click="openBox('largeFamily')" class="inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                        <span class="material-symbols-outlined text-base">diversity_3</span>
                        <span x-text="getLargeFamilyText()"></span>
                        <span class="material-symbols-outlined text-base transition-transform" :class="largeFamilyOpen ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content left-auto right-0" :class="largeFamilyOpen ? 'show' : ''" style="min-width:340px;">
                        <div class="px-4 py-3 border-b border-slate-100">
                            <div class="text-sm font-bold text-slate-900"><?= T::select_discount_type ?? 'Select a type of discount' ?></div>
                        </div>
                        <div x-show="bonusesLoading" class="flex items-center justify-center gap-2 py-6 text-sm text-slate-500">
                            <span class="material-symbols-outlined animate-spin text-blue-500">progress_activity</span>
                            <?= T::loading ?? 'Loading...' ?>
                        </div>
                        <template x-if="!bonusesLoading">
                            <div>
                                <div class="mx-4 mt-3 mb-1 p-3 rounded-lg bg-blue-50 border border-blue-100 flex items-start gap-2">
                                    <span class="material-symbols-outlined text-blue-500 flex-shrink-0" style="font-size:18px">info</span>
                                    <div>
                                        <div class="text-xs font-semibold text-slate-800"><?= T::important_information ?? 'Important information' ?></div>
                                        <div class="text-xs text-slate-600 mt-0.5"><?= T::large_family_discount_notice ?? 'All passengers must be subject to the same large family discount, otherwise separate bookings must be made.' ?></div>
                                    </div>
                                </div>
                                <div class="input-dropdown-item" @click="chooseBonus(null)">
                                    <span class="font-semibold text-slate-900" :class="!selectedBonusId ? 'text-[#1570ef]' : ''"><?= T::no_discount ?? 'No discount' ?></span>
                                </div>
                                <template x-for="b in largeFamilyBonuses" :key="b.id">
                                    <div class="input-dropdown-item" @click="chooseBonus(b)">
                                        <span class="font-semibold" :class="parseInt(selectedBonusId,10)===parseInt(b.id,10) ? 'text-[#1570ef]' : 'text-slate-900'" x-text="b.name"></span>
                                    </div>
                                </template>
                                <div x-show="!largeFamilyBonuses.length" class="px-4 py-4 text-center text-xs text-slate-400">
                                    <?= T::no_bonuses_available ?? 'No bonus types available for this route' ?>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- RESIDENCE — e.g. "Resident in the Balearic Islands" / "Resident in Ceuta" -->
                <div class="input-dropdown" @click.away="residenceOpen=false">
                    <div @click="openBox('residence')" class="inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                        <span class="material-symbols-outlined text-base">home_pin</span>
                        <span x-text="getResidenceText()"></span>
                        <span class="material-symbols-outlined text-base transition-transform" :class="residenceOpen ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content left-auto right-0" :class="residenceOpen ? 'show' : ''" style="min-width:340px;">
                        <div class="px-4 py-3 border-b border-slate-100">
                            <div class="text-sm font-bold text-slate-900"><?= T::select_discount_type ?? 'Select a type of discount' ?></div>
                        </div>
                        <div x-show="bonusesLoading" class="flex items-center justify-center gap-2 py-6 text-sm text-slate-500">
                            <span class="material-symbols-outlined animate-spin text-blue-500">progress_activity</span>
                            <?= T::loading ?? 'Loading...' ?>
                        </div>
                        <template x-if="!bonusesLoading">
                            <div>
                                <div class="mx-4 mt-3 mb-1 p-3 rounded-lg bg-blue-50 border border-blue-100 flex items-start gap-2">
                                    <span class="material-symbols-outlined text-blue-500 flex-shrink-0" style="font-size:18px">info</span>
                                    <div>
                                        <div class="text-xs font-semibold text-slate-800"><?= T::important_information ?? 'Important information' ?></div>
                                        <div class="text-xs text-slate-600 mt-0.5"><?= T::discount_applies_to_all_passengers ?? 'All passengers must be subject to the same discount, otherwise separate bookings must be made.' ?></div>
                                    </div>
                                </div>
                                <div class="input-dropdown-item" @click="chooseBonus(null)">
                                    <span class="font-semibold text-slate-900" :class="!selectedBonusId ? 'text-[#1570ef]' : ''"><?= T::no_discount ?? 'No discount' ?></span>
                                </div>
                                <template x-for="b in residenceBonuses" :key="b.id">
                                    <div class="input-dropdown-item" @click="chooseBonus(b)">
                                        <span class="font-semibold" :class="parseInt(selectedBonusId,10)===parseInt(b.id,10) ? 'text-[#1570ef]' : 'text-slate-900'" x-text="b.name"></span>
                                    </div>
                                </template>
                                <div x-show="!residenceBonuses.length" class="px-4 py-4 text-center text-xs text-slate-400">
                                    <?= T::no_bonuses_available ?? 'No bonus types available for this route' ?>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

            </div>
        </div>

        <!-- MAIN SEARCH ROW -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.1fr_1.1fr_1.4fr_1fr_60px]">

            <!-- DEPARTURE PORT -->
            <div class="relative" @click.away="depOpen=false; depSearch=''">

                <!-- TRIGGER — border becomes rounded-b-none when open so panel connects flush -->
                <div @click="openDep()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="depOpen
                        ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                        : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">directions_boat</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::departure_port ?? 'From' ?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!depPortId"><?= T::select_departure ?? 'Select departure' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="depPortId" x-text="getPortName(depPortId)"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                        style="font-size:20px" :class="depOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL — absolute, left/right/bottom border only (border-t-0), rounded-b only -->
                <div x-show="depOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-lg max-h-[300px] overflow-y-auto"
                    style="top:100%">

                    <!-- LOADING -->
                    <div x-show="portsLoading" class="px-4 py-5 flex items-center justify-center gap-2 text-sm text-slate-500">
                        <span class="material-symbols-outlined text-base animate-spin text-blue-500">progress_activity</span>
                        <?= T::loading_ports ?? 'Loading ports...' ?>
                    </div>

                    <!-- SEARCH + LIST -->
                    <template x-if="!portsLoading && portsLoaded">
                        <div>
                            <div class="px-3 pt-3 pb-2 sticky top-0 bg-white z-10">
                                <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                    <input type="text" x-model="depSearch" @click.stop
                                        placeholder="<?= T::search_port ?? 'Search port...' ?>"
                                        class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                                </div>
                            </div>
                            <div class="pb-1">
                                <template x-for="p in filteredDepPorts" :key="p.id">
                                    <div class="input-dropdown-item" @click="selectDep(p)">
                                        <span class="material-symbols-outlined text-blue-400 flex-shrink-0" style="font-size:18px">anchor</span>
                                        <span x-text="p.name"></span>
                                        <span class="ml-auto text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded" x-text="p.code"></span>
                                    </div>
                                </template>
                                <div x-show="filteredDepPorts.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                                    <?= T::no_ports_found ?? 'No ports found' ?>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- DESTINATION PORT — same pattern -->
            <div class="relative" @click.away="destOpen=false; destSearch=''" :class="!depPortId?'opacity-60':''">

                <!-- TRIGGER -->
                <div @click="openDest()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="destOpen
                        ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                        : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::arrival_port ?? 'To' ?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!destPortId"><?= T::select_destination ?? 'Select destination' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="destPortId" x-text="getPortName(destPortId)"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                        style="font-size:20px" :class="destOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL -->
                <div x-show="destOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-lg max-h-[300px] overflow-y-auto"
                    style="top:100%">

                    <div x-show="!depPortId" class="px-4 py-5 text-center text-sm text-slate-400">
                        <?= T::select_departure_first ?? 'Select departure port first' ?>
                    </div>
                    <div x-show="depPortId && portsLoading" class="px-4 py-5 flex items-center justify-center gap-2 text-sm text-slate-500">
                        <span class="material-symbols-outlined text-base animate-spin text-blue-500">progress_activity</span>
                        <?= T::loading ?? 'Loading...' ?>
                    </div>
                    <template x-if="depPortId && !portsLoading">
                        <div>
                            <div class="px-3 pt-3 pb-2 sticky top-0 bg-white z-10">
                                <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                    <input type="text" x-model="destSearch" @click.stop
                                        placeholder="<?= T::search_port ?? 'Search port...' ?>"
                                        class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                                </div>
                            </div>
                            <div class="pb-1">
                                <template x-for="p in filteredDestPorts" :key="p.id">
                                    <div class="input-dropdown-item" @click="selectDest(p)">
                                        <span class="material-symbols-outlined text-blue-400 flex-shrink-0" style="font-size:18px">anchor</span>
                                        <span x-text="p.name"></span>
                                        <span class="ml-auto text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded" x-text="p.code"></span>
                                    </div>
                                </template>
                                <div x-show="filteredDestPorts.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                                    <?= T::no_destinations_available ?? 'No destinations available' ?>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- DATES: field-box-split with individual hidden return elements (same pattern as flights) -->
            <div id="ferries_date_container" class="field-box field-box-split">

                <!-- DEPARTURE DATE -->
                <div @click="document.querySelector('.FerriesDeparture').focus()" class="field-box-segment">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::sailing_date ?? 'Departure Date' ?></label>
                        <input type="text"
                            class="FerriesDeparture field-box-input cursor-pointer"
                            value="<?= htmlspecialchars($displayDep) ?>"
                            readonly>
                    </div>
                </div>

                <!-- ARROW DIVIDER (shown when round-trip restored from session) -->
                <div id="ferries_return_arrow" class="field-box-divider" style="display:<?= $isRoundTrip ? 'flex' : 'none' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </div>

                <!-- RETURN DATE (shown when round-trip restored from session) -->
                <div id="ferries_return_date_picker" @click="document.querySelector('.FerriesReturn').focus()" class="field-box-segment pl-9" style="display:<?= $isRoundTrip ? 'flex' : 'none' ?>">
                    <span class="field-box-icon material-symbols-outlined !left-1">event_repeat</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::return_date ?? 'Return Date' ?></label>
                        <input type="text"
                            class="FerriesReturn field-box-input cursor-pointer"
                            value="<?= htmlspecialchars($displayReturn) ?>"
                            readonly>
                    </div>
                </div>

            </div>

            <!-- PASSENGERS DROPDOWN -->
            <div class="relative" @click.away="passOpen=false">

                <!-- TRIGGER -->
                <div @click="const w=passOpen; closeAllBoxes(); depOpen=false; passOpen=!w"
                    class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="passOpen
                        ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                        : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::passengers ?? 'Passengers' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getPassengerText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                        style="font-size:20px" :class="passOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL -->
                <div x-show="passOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-lg p-3 max-h-[340px] overflow-y-auto"
                    style="top:100%">

                    <!-- ADULTS -->
                    <div class="flex items-center justify-between p-3 rounded-xl mb-1">
                        <div>
                            <div class="text-[13px] font-bold text-slate-900"><?= T::adults ?? 'Adults' ?></div>
                            <div class="text-[11px] text-slate-500">14 <?= T::years_and_over ?? 'years and over' ?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('adults')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="adults<=1?'opacity-40 cursor-not-allowed':''" :disabled="adults<=1">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="adults" class="w-8 text-center text-sm font-bold text-slate-900"></span>
                            <button type="button" @click="increment('adults')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="adults>=9?'opacity-40 cursor-not-allowed':''" :disabled="adults>=9">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- CHILDREN -->
                    <div class="flex items-center justify-between p-3 rounded-xl mb-1">
                        <div>
                            <div class="text-[13px] font-bold text-slate-900"><?= T::children ?? 'Children' ?></div>
                            <div class="text-[11px] text-slate-500">1 – 13 <?= T::years ?? 'years' ?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('children')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="children<=0?'opacity-40 cursor-not-allowed':''" :disabled="children<=0">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="children" class="w-8 text-center text-sm font-bold text-slate-900"></span>
                            <button type="button" @click="increment('children')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="children>=8?'opacity-40 cursor-not-allowed':''" :disabled="children>=8">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- INFANTS -->
                    <div class="flex items-center justify-between p-3 rounded-xl mb-1">
                        <div>
                            <div class="text-[13px] font-bold text-slate-900"><?= T::infants ?? 'Infants' ?></div>
                            <div class="text-[11px] text-slate-500">0 – 1 <?= T::years ?? 'years' ?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('infant')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="infant<=0?'opacity-40 cursor-not-allowed':''" :disabled="infant<=0">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="infant" class="w-8 text-center text-sm font-bold text-slate-900"></span>
                            <button type="button" @click="increment('infant')"
                                class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm"
                                :class="infant>=9?'opacity-40 cursor-not-allowed':''" :disabled="infant>=9">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- SEARCH BUTTON -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1">
                <button type="submit" :disabled="isSearching"
                    class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0"
                    title="<?= T::search_ferries ?? 'Search Ferries' ?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>

        </div><!-- end grid -->
    </form>
</div>

<?php endif; ?>
