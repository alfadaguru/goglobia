<?php
// app/views/admin/stays/calendar.php
@$SECURE or die('Access Denied!');

$hotelName     = htmlspecialchars($hotel['name'] ?? 'Hotel');
$hotelCurrency = htmlspecialchars($hotel['currency'] ?? 'USD');
$hotelId       = intval($hotel['id'] ?? 0);
?>

<div
    x-data="staysCalendar()"
    x-init="init()"
    class="flex flex-col bg-gray-50"
    style="min-height: calc(100vh - 60px);"
>

    <!-- ===== BREADCRUMB ===== -->
    <div class="bg-white border-b border-gray-200 px-5 py-3 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="<?= root . admin ?>/stays" class="hover:text-blue-600 transition-colors">Hotels</a>
            <span class="material-symbols-outlined text-base leading-none text-gray-300">chevron_right</span>
            <a href="<?= root . admin ?>/stays/edit/<?= $hotelId ?>" class="hover:text-blue-600 transition-colors"><?= $hotelName ?></a>
            <span class="material-symbols-outlined text-base leading-none text-gray-300">chevron_right</span>
            <span class="text-gray-800 font-medium">Calendar</span>
        </div>
        <span class="text-xs bg-blue-50 text-blue-700 border border-blue-200 rounded-full px-3 py-1 font-medium">
            Hotel #<?= $hotelId ?>
        </span>
    </div>

    <!-- ===== TAB BAR ===== -->
    <div class="bg-white border-b border-gray-200 px-5 flex-shrink-0">
        <div class="flex items-center overflow-x-auto">
            <template x-for="tab in tabs" :key="tab.key">
                <button
                    @click="activeTab = tab.key"
                    :class="activeTab === tab.key
                        ? 'border-b-2 border-blue-600 text-blue-600 font-semibold'
                        : 'text-gray-500 hover:text-gray-800 border-b-2 border-transparent'"
                    class="px-4 py-3 text-sm whitespace-nowrap transition-colors"
                    x-text="tab.label"
                ></button>
            </template>
        </div>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="bg-white border-b border-gray-200 px-5 py-2.5 flex flex-wrap items-center gap-3 flex-shrink-0">

        <!-- Source -->
        <select x-model="selectedSource"
            class="border border-gray-300 rounded-full text-xs px-3 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="PMS">PMS</option>
            <option value="OTA">OTA</option>
            <option value="Direct">Direct</option>
            <option value="All">All Sources</option>
        </select>

        <!-- Room Type Filter -->
        <select x-model="selectedRoomType"
            class="border border-gray-300 rounded-full text-xs px-3 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="all">All Room Types</option>
            <template x-for="room in rooms" :key="room.id">
                <option :value="room.id" x-text="room.name"></option>
            </template>
        </select>

        <!-- Month Navigation -->
        <div class="flex items-center gap-1 border border-gray-300 rounded-full overflow-hidden">
            <button @click="prevMonth()"
                class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-100 transition-colors flex items-center">
                <span class="material-symbols-outlined leading-none" style="font-size:16px;">chevron_left</span>
            </button>
            <input type="month"
                x-model="monthInput"
                @change="onMonthInputChange()"
                class="border-0 text-xs text-gray-700 px-1 py-1.5 focus:outline-none bg-transparent w-28 text-center" />
            <button @click="nextMonth()"
                class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-100 transition-colors flex items-center">
                <span class="material-symbols-outlined leading-none" style="font-size:16px;">chevron_right</span>
            </button>
        </div>

        <!-- Today -->
        <button @click="goToday()"
            class="border border-gray-300 rounded-full text-xs px-3 py-1.5 text-gray-700 hover:bg-gray-50 transition-colors">
            Today
        </button>

        <!-- Rates sub-radio -->
        <template x-if="activeTab === 'rates'">
            <div class="flex items-center gap-3 border-l border-gray-200 pl-3 ml-1">
                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                    <input type="radio" name="rateType" value="base" x-model="activeRateType" class="accent-blue-600"> Base Rates
                </label>
                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                    <input type="radio" name="rateType" value="extra_adult" x-model="activeRateType" class="accent-blue-600"> Extra Adult
                </label>
                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                    <input type="radio" name="rateType" value="extra_child" x-model="activeRateType" class="accent-blue-600"> Extra Child
                </label>
            </div>
        </template>

        <div class="flex-1"></div>

        <!-- Currency / Tax Info -->
        <span class="text-xs text-gray-400 flex items-center gap-1">
            <span class="material-symbols-outlined leading-none" style="font-size:13px;">info</span>
            Rates in <strong class="text-gray-600 mx-0.5"><?= $hotelCurrency ?></strong> &bull;
            <span x-text="taxInclusive ? 'Tax Inclusive' : 'Tax Exclusive'" class="text-gray-600 font-medium ml-0.5"></span>
        </span>

        <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer whitespace-nowrap">
            <input type="checkbox" x-model="hideDerived" class="w-3.5 h-3.5 accent-blue-600 rounded">
            Hide Derived Rate Plans
        </label>

        <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer whitespace-nowrap">
            <input type="checkbox" x-model="taxInclusive" class="w-3.5 h-3.5 accent-blue-600 rounded">
            Tax Inclusive
        </label>

        <button disabled
            class="flex items-center gap-1.5 bg-blue-50 text-blue-300 text-xs px-4 py-1.5 rounded-full cursor-not-allowed border border-blue-200">
            <span class="material-symbols-outlined leading-none" style="font-size:13px;">save</span> Save
        </button>

        <button class="w-7 h-7 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-gray-50">
            <span class="material-symbols-outlined leading-none" style="font-size:15px;">more_vert</span>
        </button>
    </div>

    <!-- ===== CALENDAR GRID ===== -->
    <div class="flex-1 overflow-auto" style="max-height: calc(100vh - 175px);">
        <table class="border-collapse text-xs" style="width: max-content; min-width: 100%;">

            <!-- DATE HEADER — sticky top -->
            <thead>
                <tr>
                    <th class="sticky left-0 top-0 z-40 bg-gray-100 border border-gray-300 text-left px-3 py-2 font-semibold text-gray-600"
                        style="min-width:220px; width:220px; position: sticky; left:0; top:0;">
                        Room / Rate Plan
                    </th>
                    <template x-for="(d, i) in dates" :key="i">
                        <th
                            :class="d.isToday
                                ? 'bg-blue-600 text-white border-blue-600'
                                : d.isWeekend
                                    ? 'bg-amber-50 border-amber-200 text-gray-700'
                                    : 'bg-gray-100 border-gray-300 text-gray-700'"
                            class="border text-center py-1.5 px-0.5 font-medium sticky top-0 z-30"
                            style="min-width:62px; width:62px;">
                            <div class="font-normal leading-tight" x-text="d.dayName" style="font-size:9px;"></div>
                            <div class="font-bold leading-tight text-sm" x-text="d.dayNum"></div>
                            <div class="font-normal leading-tight" x-text="d.month" style="font-size:9px;"></div>
                        </th>
                    </template>
                </tr>
            </thead>

            <!-- ROOM GROUPS — one <tbody> per room -->
            <template x-for="room in filteredRooms" :key="room.id">
                <tbody>

                    <!-- Room header row -->
                    <tr class="border-t-2 border-gray-400">
                        <td class="sticky left-0 z-20 bg-gray-100 border border-gray-300 px-3 py-2 font-semibold text-gray-800"
                            style="min-width:220px; width:220px;">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-gray-500 leading-none" style="font-size:15px;">bed</span>
                                <span x-text="room.name" class="truncate"></span>
                            </div>
                        </td>
                        <template x-for="(d, di) in dates" :key="di">
                            <td
                                :class="d.isToday
                                    ? 'bg-blue-50 border-blue-200'
                                    : d.isWeekend
                                        ? 'bg-amber-50 border-amber-200'
                                        : 'bg-gray-50 border-gray-200'"
                                class="border text-center py-1.5 font-semibold text-gray-700"
                                x-text="room.availability">
                            </td>
                        </template>
                    </tr>

                    <!-- Rate plan rows -->
                    <template x-for="plan in room.ratePlans" :key="plan.id">
                        <tr
                            x-show="!hideDerived || !plan.isDerived"
                            :class="plan.isDerived ? 'bg-purple-50/40 hover:bg-purple-50' : 'bg-white hover:bg-blue-50/30'"
                            class="transition-colors">

                            <!-- Plan name (sticky left) -->
                            <td class="sticky left-0 z-20 border border-gray-200 px-2 py-1"
                                :class="plan.isDerived ? 'bg-purple-50/60' : 'bg-white'"
                                style="min-width:220px; width:220px;">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-px h-4 bg-gray-300 ml-3 flex-shrink-0"></span>
                                    <span class="truncate text-gray-700 text-xs"
                                        :class="plan.isDerived ? 'text-purple-700' : ''"
                                        x-text="plan.name"></span>
                                    <template x-if="plan.isDerived">
                                        <span class="ml-0.5 flex-shrink-0 text-[9px] bg-purple-100 text-purple-600 rounded px-1 py-px font-semibold">D</span>
                                    </template>
                                    <div class="ml-auto flex items-center gap-0.5 flex-shrink-0">
                                        <button class="text-gray-300 hover:text-gray-500">
                                            <span class="material-symbols-outlined leading-none" style="font-size:12px;">info</span>
                                        </button>
                                        <button class="text-gray-300 hover:text-gray-500">
                                            <span class="material-symbols-outlined leading-none" style="font-size:12px;">content_copy</span>
                                        </button>
                                    </div>
                                </div>
                            </td>

                            <!-- Date cells -->
                            <template x-for="(d, di) in dates" :key="di">
                                <td
                                    :class="d.isToday
                                        ? 'bg-blue-50/60 border-blue-200'
                                        : d.isWeekend
                                            ? 'bg-amber-50/50 border-amber-100'
                                            : 'border-gray-100'"
                                    class="border p-0.5"
                                    style="min-width:62px; width:62px;">

                                    <!-- INVENTORY -->
                                    <template x-if="activeTab === 'inventory'">
                                        <input type="number" :value="room.availability" min="0"
                                            class="w-full text-center text-xs py-1 border border-transparent hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-300 rounded bg-transparent" />
                                    </template>

                                    <!-- RATES -->
                                    <template x-if="activeTab === 'rates'">
                                        <input type="text"
                                            :value="getRateValue(plan, d)"
                                            :class="getRateValue(plan, d) == '0' ? 'text-red-400' : 'text-gray-800'"
                                            class="w-full text-center text-xs py-1 border border-transparent hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-300 rounded bg-transparent" />
                                    </template>

                                    <!-- MIN NIGHTS -->
                                    <template x-if="activeTab === 'min_nights'">
                                        <input type="number" :value="plan.minNights" min="1"
                                            class="w-full text-center text-xs py-1 border border-transparent hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-300 rounded bg-transparent" />
                                    </template>

                                    <!-- MAX NIGHTS -->
                                    <template x-if="activeTab === 'max_nights'">
                                        <input type="number" :value="plan.maxNights" min="1"
                                            class="w-full text-center text-xs py-1 border border-transparent hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-300 rounded bg-transparent" />
                                    </template>

                                    <!-- STOPSELLS -->
                                    <template x-if="activeTab === 'stopsells'">
                                        <button
                                            @click="toggleCell(room.id, plan.id, di, 'stopsells')"
                                            :class="getToggle(room.id, plan.id, di, 'stopsells')
                                                ? 'bg-red-100 text-red-600 border-red-300'
                                                : 'bg-green-100 text-green-700 border-green-300'"
                                            class="w-full rounded border text-[10px] font-medium py-1 transition-colors leading-none"
                                            x-text="getToggle(room.id, plan.id, di, 'stopsells') ? 'Blocked' : 'Open'">
                                        </button>
                                    </template>

                                    <!-- COA -->
                                    <template x-if="activeTab === 'coa'">
                                        <button
                                            @click="toggleCell(room.id, plan.id, di, 'coa')"
                                            :class="getToggle(room.id, plan.id, di, 'coa')
                                                ? 'bg-orange-100 text-orange-600 border-orange-300'
                                                : 'bg-green-100 text-green-700 border-green-300'"
                                            class="w-full rounded border text-[10px] font-medium py-1 transition-colors leading-none"
                                            x-text="getToggle(room.id, plan.id, di, 'coa') ? 'Closed' : 'Open'">
                                        </button>
                                    </template>

                                    <!-- COD -->
                                    <template x-if="activeTab === 'cod'">
                                        <button
                                            @click="toggleCell(room.id, plan.id, di, 'cod')"
                                            :class="getToggle(room.id, plan.id, di, 'cod')
                                                ? 'bg-orange-100 text-orange-600 border-orange-300'
                                                : 'bg-green-100 text-green-700 border-green-300'"
                                            class="w-full rounded border text-[10px] font-medium py-1 transition-colors leading-none"
                                            x-text="getToggle(room.id, plan.id, di, 'cod') ? 'Closed' : 'Open'">
                                        </button>
                                    </template>

                                </td>
                            </template>
                            <!-- end date cells -->

                        </tr>
                    </template>
                    <!-- end rate plan rows -->

                </tbody>
            </template>
            <!-- end room groups -->

        </table>
    </div>
    <!-- end calendar grid -->

</div>

<script>
function staysCalendar() {
    return {

        // ── state ──────────────────────────────────────────────
        activeTab:       'rates',
        activeRateType:  'base',
        hideDerived:     false,
        taxInclusive:    true,
        selectedSource:  'PMS',
        selectedRoomType:'all',
        monthInput:      '',
        currentYear:     0,
        currentMonth:    0,   // 0-based
        dates:           [],
        toggleStates:    {},

        // ── tabs ──────────────────────────────────────────────
        tabs: [
            { key: 'inventory',  label: 'Inventory' },
            { key: 'rates',      label: 'Rates' },
            { key: 'min_nights', label: 'Minimum Nights' },
            { key: 'max_nights', label: 'Maximum Nights' },
            { key: 'stopsells',  label: 'Stopsells' },
            { key: 'coa',        label: 'COA' },
            { key: 'cod',        label: 'COD' },
        ],

        // ── room data ────────────────────────────────────────
        rooms: [
            {
                id: 1, name: 'Deluxe Ocean View', availability: 2,
                ratePlans: [
                    { id: 101, name: 'Room Only',              isDerived: false, rate: 3402000,  extraAdult: 120000, extraChild: 60000,  minNights: 1,  maxNights: 30 },
                    { id: 102, name: 'Bed & Breakfast',        isDerived: false, rate: 986090,   extraAdult: 150000, extraChild: 75000,  minNights: 1,  maxNights: 30 },
                    { id: 103, name: 'Monthly',                isDerived: false, rate: 11340000, extraAdult: 500000, extraChild: 250000, minNights: 28, maxNights: 35 },
                    { id: 104, name: 'Non-refundable',         isDerived: false, rate: 567000,   extraAdult: 100000, extraChild: 50000,  minNights: 1,  maxNights: 30 },
                    { id: 105, name: 'DOV Derived',            isDerived: true,  rate: 540000,   extraAdult: 100000, extraChild: 50000,  minNights: 1,  maxNights: 30 },
                ]
            },
            {
                id: 2, name: 'Deluxe Room', availability: 99,
                ratePlans: [
                    { id: 201, name: 'Room Only',              isDerived: false, rate: 3061800,  extraAdult: 120000, extraChild: 60000,  minNights: 1,  maxNights: 30 },
                    { id: 202, name: 'Bed & Breakfast',        isDerived: false, rate: 986090,   extraAdult: 150000, extraChild: 75000,  minNights: 1,  maxNights: 30 },
                    { id: 203, name: 'Monthly',                isDerived: false, rate: 11340000, extraAdult: 500000, extraChild: 250000, minNights: 28, maxNights: 35 },
                    { id: 204, name: 'Non-refundable',         isDerived: false, rate: 0,        extraAdult: 0,      extraChild: 0,      minNights: 1,  maxNights: 30 },
                    { id: 205, name: 'DR Non-refundable (Derived)', isDerived: true, rate: 820000, extraAdult: 100000, extraChild: 50000, minNights: 1, maxNights: 30 },
                ]
            },
            {
                id: 3, name: 'Standard Room', availability: 5,
                ratePlans: [
                    { id: 301, name: 'Room Only',              isDerived: false, rate: 2100000,  extraAdult: 80000,  extraChild: 40000,  minNights: 1,  maxNights: 30 },
                    { id: 302, name: 'Bed & Breakfast',        isDerived: false, rate: 2450000,  extraAdult: 95000,  extraChild: 47000,  minNights: 1,  maxNights: 30 },
                    { id: 303, name: 'STD Non-refundable (Derived)', isDerived: true, rate: 1890000, extraAdult: 80000, extraChild: 40000, minNights: 1, maxNights: 30 },
                ]
            },
            {
                id: 4, name: 'Family Suite', availability: 3,
                ratePlans: [
                    { id: 401, name: 'Room Only',              isDerived: false, rate: 5200000,  extraAdult: 200000, extraChild: 100000, minNights: 1,  maxNights: 30 },
                    { id: 402, name: 'All Inclusive',          isDerived: false, rate: 6800000,  extraAdult: 250000, extraChild: 125000, minNights: 2,  maxNights: 30 },
                    { id: 403, name: 'Monthly',                isDerived: false, rate: 18000000, extraAdult: 600000, extraChild: 300000, minNights: 28, maxNights: 35 },
                    { id: 404, name: 'FS Non-refundable (Derived)', isDerived: true, rate: 4680000, extraAdult: 200000, extraChild: 100000, minNights: 1, maxNights: 30 },
                ]
            }
        ],

        // ── computed ─────────────────────────────────────────
        get filteredRooms() {
            if (this.selectedRoomType === 'all') return this.rooms;
            return this.rooms.filter(r => String(r.id) === String(this.selectedRoomType));
        },

        // ── date generation (full month) ─────────────────────
        generateDates() {
            this.dates = [];
            const dayNames   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const today      = new Date();
            const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
            for (let i = 1; i <= daysInMonth; i++) {
                const d = new Date(this.currentYear, this.currentMonth, i);
                this.dates.push({
                    date:      d,
                    dayName:   dayNames[d.getDay()],
                    dayNum:    i,
                    month:     monthNames[this.currentMonth],
                    isWeekend: d.getDay() === 0 || d.getDay() === 6,
                    isToday:   d.getFullYear() === today.getFullYear()
                                && d.getMonth() === today.getMonth()
                                && d.getDate()  === today.getDate(),
                });
            }
            const m = String(this.currentMonth + 1).padStart(2, '0');
            this.monthInput = `${this.currentYear}-${m}`;
        },

        prevMonth() {
            if (this.currentMonth === 0) { this.currentYear--;  this.currentMonth = 11; }
            else                         { this.currentMonth--; }
            this.generateDates();
        },

        nextMonth() {
            if (this.currentMonth === 11) { this.currentYear++;  this.currentMonth = 0; }
            else                          { this.currentMonth++; }
            this.generateDates();
        },

        goToday() {
            const t = new Date();
            this.currentYear  = t.getFullYear();
            this.currentMonth = t.getMonth();
            this.generateDates();
        },

        onMonthInputChange() {
            const parts = this.monthInput.split('-');
            if (parts.length === 2) {
                this.currentYear  = parseInt(parts[0]);
                this.currentMonth = parseInt(parts[1]) - 1;
                this.generateDates();
            }
        },

        // ── rate value ───────────────────────────────────────
        getRateValue(plan, d) {
            let base = this.activeRateType === 'base'
                ? plan.rate
                : this.activeRateType === 'extra_adult'
                    ? plan.extraAdult
                    : plan.extraChild;
            // slight weekend bump for realism
            if (d && d.isWeekend && base > 0) base = Math.round(base * 1.1);
            return base === 0 ? '0' : base.toLocaleString();
        },

        // ── toggle cells ─────────────────────────────────────
        getToggle(roomId, planId, dateIndex, tab) {
            return this.toggleStates[`${roomId}_${planId}_${dateIndex}_${tab}`] ?? false;
        },

        toggleCell(roomId, planId, dateIndex, tab) {
            const k = `${roomId}_${planId}_${dateIndex}_${tab}`;
            this.toggleStates[k] = !this.getToggle(roomId, planId, dateIndex, tab);
        },

        // ── init ─────────────────────────────────────────────
        init() {
            const t = new Date();
            this.currentYear  = t.getFullYear();
            this.currentMonth = t.getMonth();
            this.generateDates();
        }
    };
}
</script>
