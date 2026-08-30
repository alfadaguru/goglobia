<?php
// app/views/admin/stays/calendar/index.php
@$SECURE or die('Access Denied!');

$hotelName     = htmlspecialchars($hotel['name'] ?? 'Hotel');
$hotelCurrency = htmlspecialchars($hotel['currency'] ?? 'USD');
$hotelId       = intval($hotel['id'] ?? 0);
$displayName   = $hotelName ?: 'Hotel #' . $hotelId;
$roomsJson     = json_encode($calendarRooms ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$existingRatesJson = json_encode($existingRates ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$calendarApiBase   = root . admin . '/stays/calendar/' . $hotelId;
?>
<script>
const phpRooms = <?= $roomsJson ?>;
const phpRates = <?= $existingRatesJson ?>;
const calendarApiBase = '<?= $calendarApiBase ?>';
</script>

<div
    x-data="ratesCalendar()"
    x-init="init()"
    class="flex flex-col bg-gray-50"
    style="height: calc(100vh - 60px);"
>

    <!-- ===== BREADCRUMB ===== -->
    <div class="bg-white border-b border-gray-200 px-5 py-3 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-1.5 text-sm text-gray-500">
            <a href="<?= root . admin ?>/stays" class="hover:text-blue-600 transition-colors">Hotels</a>
            <span class="material-symbols-outlined text-base leading-none text-gray-300">chevron_right</span>
            <a href="<?= root . admin ?>/stays/edit/<?= $hotelId ?>" class="hover:text-blue-600 transition-colors truncate max-w-[200px]"><?= $displayName ?></a>
            <span class="material-symbols-outlined text-base leading-none text-gray-300">chevron_right</span>
            <span class="text-gray-800 font-medium">Rates Calendar</span>
        </div>
        <span class="text-xs bg-blue-50 text-blue-700 border border-blue-200 rounded-full px-3 py-1 font-medium">
            Hotel #<?= $hotelId ?>
        </span>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="bg-white border-b border-gray-200 px-5 py-2.5 flex flex-wrap items-center gap-3 flex-shrink-0">

        <!-- Room type filter -->
        <select x-model="selectedRoom"
            class="border border-gray-300 rounded-full text-xs px-3 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="all">All Room Types</option>
            <template x-for="room in rooms" :key="room.id">
                <option :value="String(room.id)" x-text="room.name"></option>
            </template>
        </select>

        <!-- Month navigation -->
        <div class="flex items-center gap-1 border border-gray-300 rounded-full overflow-hidden">
            <button @click="prevMonth()" type="button"
                class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-100 transition-colors flex items-center">
                <span class="material-symbols-outlined leading-none" style="font-size:16px;">chevron_left</span>
            </button>
            <input type="month"
                x-model="monthInput"
                @change="onMonthInputChange()"
                class="border-0 text-xs text-gray-700 px-1 py-1.5 focus:outline-none bg-transparent w-28 text-center" />
            <button @click="nextMonth()" type="button"
                class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-100 transition-colors flex items-center">
                <span class="material-symbols-outlined leading-none" style="font-size:16px;">chevron_right</span>
            </button>
        </div>

        <!-- Today -->
        <button @click="goToday()" type="button"
            class="border border-gray-300 rounded-full text-xs px-3 py-1.5 text-gray-700 hover:bg-gray-50 transition-colors">
            Today
        </button>

        <!-- selection count indicator -->
        <div x-show="selectedCount > 0"
            class="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5 text-xs text-blue-700">
            <span class="material-symbols-outlined leading-none" style="font-size:14px;">check_box</span>
            <span x-text="selectedCount + ' cell' + (selectedCount !== 1 ? 's' : '') + ' selected'"></span>
            <button @click="clearSelection()" type="button" class="ml-1 text-blue-400 hover:text-blue-700">
                <span class="material-symbols-outlined leading-none" style="font-size:14px;">close</span>
            </button>
        </div>

        <div class="flex-1"></div>

        <span class="text-xs text-gray-400">
            Rates in <strong class="text-gray-600 mx-0.5"><?= $hotelCurrency ?></strong>
        </span>

        <button type="button" @click="saveRates()" :disabled="saving"
            class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-70 text-white text-xs px-4 py-1.5 rounded-lg transition-colors">
            <span class="material-symbols-outlined leading-none" style="font-size:13px;" x-text="saving ? 'hourglass_empty' : 'save'"></span>
            <span x-text="saving ? 'Saving\u2026' : (dirtyCount > 0 ? 'Save Changes (' + dirtyCount + ')' : 'Save Changes')"></span>
        </button>

    </div>

    <!-- ===== DATE RANGE PRICING PANEL ===== -->
    <div class="bg-white border-b border-gray-200 px-5 py-2.5 flex flex-wrap items-center gap-3 flex-shrink-0">
        <span class="material-symbols-outlined text-blue-500 leading-none flex-shrink-0" style="font-size:16px;">date_range</span>
        <span class="text-xs font-semibold text-gray-700 flex-shrink-0">Bulk Date Range:</span>

        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500">From</label>
            <input type="date" x-model="rangeFrom"
                class="border border-gray-300 rounded text-xs px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
            <label class="text-xs text-gray-500">To</label>
            <input type="date" x-model="rangeTo"
                class="border border-gray-300 rounded text-xs px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
        </div>

        <select x-model="rangeRoom" @change="rangePlan = 'all'"
            class="border border-gray-300 rounded text-xs px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400">
            <option value="all">All Rooms</option>
            <template x-for="room in rooms" :key="room.id">
                <option :value="String(room.id)" x-text="room.name"></option>
            </template>
        </select>

        <div x-show="rangeRoom !== 'all'" style="display:none;">
            <select x-model="rangePlan"
                class="border border-gray-300 rounded text-xs px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400">
                <option value="all">All Plans</option>
                <template x-for="opt in rangePlanOptions" :key="opt.id">
                    <option :value="String(opt.id)" x-text="opt.name"></option>
                </template>
            </select>
        </div>

        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500"><?= $hotelCurrency ?></label>
            <input type="number" min="0" x-model.number="rangePrice" @keydown.enter="applyRange()"
                placeholder="Price"
                class="border border-gray-300 rounded text-xs px-2 py-1 w-24 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
        </div>

        <button @click="applyRange()" type="button"
            :disabled="!rangeFrom || !rangeTo || rangePrice === ''"
            class="text-xs px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex-shrink-0">
            Apply to Range
        </button>

        <span class="text-[10px] text-gray-400 italic ml-auto hidden sm:block">Select a date range, room, plan and price — click Apply to fill all matching cells</span>
    </div>

    <!-- ===== BULK PRICE BAR (shown when cells are selected) ===== -->
    <div x-show="selectedCount > 0"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="bg-blue-600 text-white px-5 py-2.5 flex items-center gap-4 flex-shrink-0">

        <span class="material-symbols-outlined leading-none text-blue-200" style="font-size:18px;">edit</span>
        <span class="text-xs font-semibold">
            <span x-text="selectedCount"></span> cell<span x-show="selectedCount !== 1">s</span> selected
        </span>

        <div class="w-px h-5 bg-blue-500"></div>

        <label class="text-xs text-blue-200">New price:</label>
        <input type="number" min="0"
            x-model.number="bulkPrice"
            @keydown.enter="applyBulk()"
            placeholder="e.g. 150"
            class="w-28 text-sm text-center border border-blue-400 bg-blue-700 text-white placeholder-blue-400 rounded-lg px-3 py-1 focus:outline-none focus:ring-1 focus:ring-blue-300"
        />

        <button @click="applyBulk()" type="button"
            class="text-xs px-4 py-1.5 rounded-lg bg-white text-blue-600 font-semibold hover:bg-blue-50 transition-colors">Apply</button>

        <button @click="clearSelection()" type="button"
            class="text-xs px-3 py-1.5 rounded-lg border border-blue-400 text-blue-200 hover:bg-blue-700 transition-colors">Cancel</button>

        <span class="text-[10px] text-blue-300 ml-2 hidden sm:block">Tip: click cells to select • click column header to select full day</span>

    </div>

    <!-- ===== CALENDAR TABLE ===== -->
    <div class="flex-1 overflow-auto">
        <table class="border-collapse text-xs" style="width: max-content; min-width: 100%;">

            <!-- DATE HEADER — sticky top -->
            <thead>
                <tr>
                    <th class="sticky left-0 top-0 z-40 bg-gray-100 border border-gray-300 text-left px-3 py-2 font-semibold text-gray-600"
                        style="min-width:200px; width:200px;">
                        Room / Option
                    </th>
                    <template x-for="(d, i) in dates" :key="i">
                        <th @click="selectColumn(d.dateStr)"
                            :class="isColumnFullySelected(d.dateStr)
                                ? 'bg-blue-700 text-white border-blue-600'
                                : isColumnSelected(d.dateStr)
                                    ? 'bg-blue-100 border-blue-300 text-blue-800'
                                    : d.isToday
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-gray-100 border-gray-300 text-gray-700 hover:bg-blue-50'"
                            class="border text-center py-1.5 px-0.5 font-medium sticky top-0 z-30 cursor-pointer select-none transition-colors"
                            style="min-width:62px; width:62px;">
                            <div class="font-normal leading-tight text-[9px]" x-text="d.dayName"></div>
                            <div class="font-bold leading-tight text-sm" x-text="d.dayNum"></div>
                        </th>
                    </template>
                </tr>
            </thead>

            <!-- ROOM GROUPS — one <tbody> per room -->
            <template x-for="room in filteredRooms" :key="room.id">
                <tbody>

                    <!-- Room header row (name divider, no grid lines on date cells) -->
                    <tr class="border-t-2 border-gray-300">
                        <td class="sticky left-0 z-20 bg-gray-100 border border-gray-300 px-3 py-1.5 font-semibold text-gray-800"
                            style="min-width:200px; width:200px;">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-gray-500 leading-none" style="font-size:14px;">bed</span>
                                <span x-text="room.name" class="truncate text-xs"></span>
                            </div>
                        </td>
                        <template x-for="(d, di) in dates" :key="di">
                            <td :class="d.isToday ? 'bg-blue-50' : 'bg-gray-100'"
                                class="transition-colors"></td>
                        </template>
                    </tr>

                    <!-- Option rows -->
                    <template x-for="opt in room.options" :key="opt.id">
                        <tr class="bg-white hover:bg-blue-50/20 transition-colors">

                            <td class="sticky left-0 z-20 bg-white border border-gray-200 p-0"
                                style="min-width:200px; width:200px;">
                                <div class="flex items-start gap-2 px-2 py-1">
                                    <input type="checkbox"
                                        :checked="isRowSelected(room.id, opt.id)"
                                        @click.stop="toggleRow(room.id, opt.id)"
                                        class="mt-0.5 w-3.5 h-3.5 flex-shrink-0 cursor-pointer accent-blue-600"
                                        title="Select all days in this row"
                                    />
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="w-px h-3.5 bg-gray-300 flex-shrink-0"></span>
                                        <span x-text="opt.name" class="text-gray-700 truncate text-xs"></span>
                                    </div>
                                </div>
                            </td>

                            <template x-for="(d, di) in dates" :key="di">
                                <td @click="toggleCell(room.id, opt.id, d.dateStr)"
                                    :class="isCellSelected(room.id, opt.id, d.dateStr)
                                        ? 'bg-blue-50 border-blue-400'
                                        : d.isToday
                                            ? 'bg-blue-50/40 border-blue-100'
                                            : 'border-gray-100 hover:border-gray-300'"
                                    class="border p-0 align-middle transition-colors cursor-pointer relative"
                                    style="min-width:62px;">

                                    <!-- selection tick -->
                                    <span x-show="isCellSelected(room.id, opt.id, d.dateStr)"
                                        @click.stop="toggleCell(room.id, opt.id, d.dateStr)"
                                        class="absolute top-0.5 right-0.5 w-4 h-4 bg-blue-500 rounded-sm flex items-center justify-center cursor-pointer z-10">
                                        <span class="text-white leading-none" style="font-size:8px;">&#10003;</span>
                                    </span>

                                    <input
                                        @click.stop
                                        @input="markDirty(room.id, opt.id, d.dateStr)"
                                        type="number" min="0"
                                        x-model.number="rates[room.id + '_' + opt.id + '_' + d.dateStr]"
                                        :class="isDirty(room.id, opt.id, d.dateStr) ? 'text-blue-700 font-semibold' : 'text-gray-600'"
                                        class="w-full text-center text-[10px] py-1.5 border-0 bg-transparent focus:outline-none"
                                        :title="opt.name"
                                        autocomplete="off"
                                    />

                                </td>
                            </template>

                        </tr>
                    </template>

                </tbody>
            </template>

        </table>
    </div>

    <!-- ===== LEGEND ===== -->
    <div class="bg-white border-t border-gray-200 px-5 py-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 flex-shrink-0">
        <span class="font-medium text-gray-600">Select:</span>
        <span>Click a <strong class="text-gray-600">cell</strong> to select it — tick the <strong class="text-gray-600">row checkbox</strong> to select the full row — click a <strong class="text-gray-600">column header</strong> to select the full day — then set a bulk price or use the date range bar above.</span>
        <span class="flex items-center gap-1.5 border-l border-gray-200 pl-3"><span class="w-2.5 h-2.5 rounded bg-blue-600 flex-shrink-0"></span> Today</span>
        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-blue-100 border border-blue-400 flex-shrink-0"></span> Selected</span>
    </div>


</div>

<script>
function ratesCalendar() {
    return {

        /* ── core state ─────────────────────────────────────── */
        currentYear:  0,
        currentMonth: 0,
        monthInput:   '',
        selectedRoom: 'all',
        dates:        [],
        rates:        {},   // "roomId_optionId_YYYY-MM-DD" → number
        saving:       false,

        /* ── dirty tracking (only explicitly changed cells) ─── */
        dirtyKeys: {},      // keys the user has actually edited

        /* ── cell selection state ────────────────────────────── */
        selectedCells: {},
        bulkPrice:     '',

        /* ── date range pricing state ───────────────────────── */
        rangeFrom:  '',
        rangeTo:    '',
        rangeRoom:  'all',
        rangePlan:  'all',
        rangePrice: '',

        /* ── rooms (from PHP/DB) ────────────────────────────── */
        rooms: (typeof phpRooms !== 'undefined' && Array.isArray(phpRooms)) ? phpRooms : [],
        baseRates: {},

        /* ── computed ───────────────────────────────────────── */
        get filteredRooms() {
            return this.selectedRoom === 'all'
                ? this.rooms
                : this.rooms.filter(r => String(r.id) === String(this.selectedRoom));
        },

        get selectedCount() {
            return Object.values(this.selectedCells).filter(Boolean).length;
        },

        get dirtyCount() {
            return Object.keys(this.dirtyKeys).length;
        },

        get rangePlanOptions() {
            if (this.rangeRoom === 'all') return [];
            const room = this.rooms.find(r => String(r.id) === String(this.rangeRoom));
            return room ? room.options : [];
        },

        /* ── dirty helpers ──────────────────────────────────── */
        markDirty(roomId, optionId, dateStr) {
            const k = roomId + '_' + optionId + '_' + dateStr;
            this.dirtyKeys = { ...this.dirtyKeys, [k]: true };
        },

        isDirty(roomId, optionId, dateStr) {
            return !!this.dirtyKeys[roomId + '_' + optionId + '_' + dateStr];
        },

        /* ── cell / row / column selection ─────────────────── */
        isCellSelected(roomId, optionId, dateStr) {
            return !!this.selectedCells[roomId + '_' + optionId + '_' + dateStr];
        },

        isRowSelected(roomId, optionId) {
            return this.dates.some(d => !!this.selectedCells[roomId + '_' + optionId + '_' + d.dateStr]);
        },

        isColumnSelected(dateStr) {
            return this.filteredRooms.some(room =>
                room.options.some(opt => !!this.selectedCells[room.id + '_' + opt.id + '_' + dateStr])
            );
        },

        isColumnFullySelected(dateStr) {
            return this.filteredRooms.length > 0 && this.filteredRooms.every(room =>
                room.options.every(opt => !!this.selectedCells[room.id + '_' + opt.id + '_' + dateStr])
            );
        },

        toggleCell(roomId, optionId, dateStr) {
            const k = roomId + '_' + optionId + '_' + dateStr;
            this.selectedCells = { ...this.selectedCells, [k]: !this.selectedCells[k] };
        },

        toggleRow(roomId, optionId) {
            const hasAny = this.dates.some(d => !!this.selectedCells[roomId + '_' + optionId + '_' + d.dateStr]);
            const next  = { ...this.selectedCells };
            this.dates.forEach(d => {
                next[roomId + '_' + optionId + '_' + d.dateStr] = !hasAny;
            });
            this.selectedCells = next;
        },

        selectColumn(dateStr) {
            const fully = this.isColumnFullySelected(dateStr);
            const next  = { ...this.selectedCells };
            this.filteredRooms.forEach(room => {
                room.options.forEach(opt => {
                    next[room.id + '_' + opt.id + '_' + dateStr] = !fully;
                });
            });
            this.selectedCells = next;
        },

        clearSelection() {
            this.selectedCells = {};
            this.bulkPrice = '';
        },

        /* apply bulkPrice to selected cells — marks them dirty */
        applyBulk() {
            const val = parseFloat(this.bulkPrice);
            if (isNaN(val) || val < 0) return;
            const nextRates = { ...this.rates };
            const nextDirty = { ...this.dirtyKeys };
            Object.entries(this.selectedCells).forEach(([k, selected]) => {
                if (selected) { nextRates[k] = val; nextDirty[k] = true; }
            });
            this.rates     = nextRates;
            this.dirtyKeys = nextDirty;
            this.clearSelection();
        },

        /* apply rangePrice across ALL dates in range — marks them dirty
           Works across month boundaries (not limited to visible month) */
        applyRange() {
            const price = parseFloat(this.rangePrice);
            if (isNaN(price) || price < 0 || !this.rangeFrom || !this.rangeTo) return;
            const from = new Date(this.rangeFrom + 'T00:00:00');
            const to   = new Date(this.rangeTo   + 'T00:00:00');
            if (from > to) return;

            const nextRates = { ...this.rates };
            const nextDirty = { ...this.dirtyKeys };
            let cur = new Date(from);
            while (cur <= to) {
                const ds = cur.getFullYear() + '-' +
                           String(cur.getMonth() + 1).padStart(2, '0') + '-' +
                           String(cur.getDate()).padStart(2, '0');
                this.rooms.forEach(room => {
                    if (this.rangeRoom !== 'all' && String(room.id) !== String(this.rangeRoom)) return;
                    room.options.forEach(opt => {
                        if (this.rangePlan !== 'all' && String(opt.id) !== String(this.rangePlan)) return;
                        const k = room.id + '_' + opt.id + '_' + ds;
                        nextRates[k] = price;
                        nextDirty[k] = true;
                    });
                });
                cur.setDate(cur.getDate() + 1);
            }
            this.rates     = nextRates;
            this.dirtyKeys = nextDirty;
            this.rangePrice = '';
        },

        /* ── base rate: exact room option price (no markup) ─── */
        computeBaseRate(roomId, optionId) {
            return this.baseRates[roomId + '_' + optionId] || 0;
        },

        /* ── date generation + rate population ──────────────── */
        generateDates() {
            this.dates = [];
            const dayNames    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            const today       = new Date();
            const todayStr    = today.toDateString();
            const todayStart  = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
            const mPad        = String(this.currentMonth + 1).padStart(2, '0');

            for (let d = 1; d <= daysInMonth; d++) {
                const date    = new Date(this.currentYear, this.currentMonth, d);
                const dateStr = `${this.currentYear}-${mPad}-${String(d).padStart(2, '0')}`;
                this.dates.push({
                    dayNum:    d,
                    dateStr:   dateStr,
                    dayName:   dayNames[date.getDay()],
                    isToday:   date.toDateString() === todayStr,
                    isWeekend: date.getDay() === 0 || date.getDay() === 6,
                    isPast:    date < todayStart
                });
            }

            this.monthInput = `${this.currentYear}-${mPad}`;

            /* seed display values: use DB rate if saved, otherwise room option base price.
               Seeded values are NOT marked dirty — they won't be saved unless explicitly changed. */
            const seedPatch = {};
            this.dates.forEach(d => {
                this.rooms.forEach(room => {
                    room.options.forEach(opt => {
                        const k = room.id + '_' + opt.id + '_' + d.dateStr;
                        if (!(k in this.rates)) {
                            seedPatch[k] = this.computeBaseRate(room.id, opt.id);
                        }
                    });
                });
            });
            if (Object.keys(seedPatch).length) {
                this.rates = { ...this.rates, ...seedPatch };
            }
        },

        /* ── fetch saved rates from DB for a month ──────────── */
        async fetchMonthRates(year, month) {
            try {
                const resp = await fetch(
                    `${calendarApiBase}/rates?year=${year}&month=${month + 1}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );
                if (!resp.ok) return;
                const data = await resp.json();
                if (data.success && data.rates && Object.keys(data.rates).length) {
                    /* DB rates override seeded values — still not dirty (not user-edited) */
                    this.rates = { ...this.rates, ...data.rates };
                }
            } catch (e) { /* fail silently */ }
        },

        /* ── navigation ─────────────────────────────────────── */
        async prevMonth() {
            if (this.currentMonth === 0) { this.currentYear--; this.currentMonth = 11; }
            else { this.currentMonth--; }
            this.clearSelection();
            await this.fetchMonthRates(this.currentYear, this.currentMonth);
            this.generateDates();
        },

        async nextMonth() {
            if (this.currentMonth === 11) { this.currentYear++; this.currentMonth = 0; }
            else { this.currentMonth++; }
            this.clearSelection();
            await this.fetchMonthRates(this.currentYear, this.currentMonth);
            this.generateDates();
        },

        async goToday() {
            const t = new Date();
            this.currentYear  = t.getFullYear();
            this.currentMonth = t.getMonth();
            this.clearSelection();
            await this.fetchMonthRates(this.currentYear, this.currentMonth);
            this.generateDates();
        },

        async onMonthInputChange() {
            const parts = this.monthInput.split('-');
            if (parts.length === 2) {
                this.currentYear  = parseInt(parts[0]);
                this.currentMonth = parseInt(parts[1]) - 1;
                this.clearSelection();
                await this.fetchMonthRates(this.currentYear, this.currentMonth);
                this.generateDates();
            }
        },

        /* ── save ONLY dirty (explicitly changed) rates to DB ── */
        async saveRates() {
            if (!this.dirtyCount) {
                this._flash('No changes to save', 'gray');
                return;
            }
            this.saving = true;
            /* build payload of only the edited keys */
            const payload = {};
            Object.keys(this.dirtyKeys).forEach(k => {
                if (this.rates[k] !== undefined) payload[k] = this.rates[k];
            });
            try {
                const resp = await fetch(`${calendarApiBase}/save`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ rates: payload })
                });
                const data = await resp.json();
                if (data.success) {
                    this.dirtyKeys = {};   /* clear — all dirty rates are now persisted */
                    this._flash('Saved ' + data.saved + ' rate' + (data.saved !== 1 ? 's' : '') + ' ✓', 'green');
                } else {
                    this._flash('Save failed: ' + (data.message || 'Unknown error'), 'red');
                }
            } catch (e) {
                this._flash('Network error — rates not saved', 'red');
            }
            this.saving = false;
        },

        _flash(msg, color) {
            const colors = { green: 'bg-green-600', red: 'bg-red-600', gray: 'bg-gray-500' };
            const el = document.createElement('div');
            el.className = `fixed bottom-5 right-5 z-50 text-white text-xs font-medium px-4 py-2.5 rounded-lg shadow-xl ${colors[color] || 'bg-gray-600'}`;
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3500);
        },

        /* ── init ───────────────────────────────────────────── */
        init() {
            this.rooms.forEach(room => {
                room.options.forEach(opt => {
                    this.baseRates[room.id + '_' + opt.id] = opt.price || 0;
                });
            });

            /* load DB-saved rates for the current month (pre-fetched by PHP) */
            if (typeof phpRates !== 'undefined' && phpRates && typeof phpRates === 'object') {
                this.rates = { ...this.rates, ...phpRates };
            }

            const t = new Date();
            this.currentYear  = t.getFullYear();
            this.currentMonth = t.getMonth();
            this.generateDates();
        }

    };
}
</script>
