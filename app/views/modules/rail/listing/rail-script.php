<?php
@$SECURE or die('Access Denied!'); ?>

<!-- app/views/modules/rail/listing/rail-script.php -->
<script>
function railListing() {
    return {
        loading: true,
        trains: [],
        filteredTrains: [],
        searchError: '',
        sortBy: 'price_low',
        showMobileFilters: false,
        itemsPerPage: 25,
        visibleCount: 25,
        loadingMore: false,
        showNoMoreMessage: false,
        scrollListenerAttached: false,
        allResultsShown: false,
        bookingTrainNo: null,
        
        from: '<?= $from ?>',
        to: '<?= $to ?>',
        routeFromName: <?= json_encode(_train_station_english_only($fromName), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        routeToName: <?= json_encode(_train_station_english_only($toName), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        date: '<?= $date ?>',
        journeyType: <?= $journeyType ?>,
        adults: <?= $adults ?>,
        children: <?= $children ?>,
        infants: <?= $infants ?>,
        childAges: <?= json_encode($childAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        infantAges: <?= json_encode($infantAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        bounds: { min: 0, max: 0 },
        filters: { priceMin: 0, priceMax: 0 },
        filtersOpen: { price: true, seatClass: true },
        selectedSeatClasses: [],
        availableSeatClasses: [],
        seatClassCatalog: <?= json_encode($seatClassCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        selectedSeats: {},
        currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>',
        seatClassMap: <?= json_encode($trainSeatClassMap) ?>,
        stationEnglishMap: <?= json_encode($stationEnglishMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        seatCode(seat) {
            return String(seat?.seat_class || seat?.seat_type || '').toUpperCase();
        },

        seatName(seat) {
            const code = this.seatCode(seat);
            const eng = String(seat?.seat_name_english || '').trim();
            if (code === 'W' && (!eng || eng.toLowerCase() === 'unknown')) {
                return this.seatClassMap['W'] || 'Standing / No seat';
            }
            return eng || seat.seat_name || seat.seat_class_label || this.seatClassMap[code] || code;
        },

        trainTypeLabel(trainNo) {
            const prefix = String(trainNo || '').charAt(0).toUpperCase();
            const labels = {
                G: '<?= addslashes(T::train_type_g) ?>',
                D: '<?= addslashes(T::train_type_d) ?>',
                C: '<?= addslashes(T::train_type_c) ?>',
                S: '<?= addslashes(T::train_type_s) ?>',
            };
            return labels[prefix] || '<?= addslashes(T::train) ?>';
        },

        isOnSale(train) {
            return train?.sale_flag !== false && train?.sale_flag !== 0 && String(train?.sale_flag) !== 'false';
        },

        isQuietCarriage(train) {
            return Number(train?.is_quiet_carriage) === 1;
        },

        isSoldOut(train) {
            const seats = this.getFilteredSeats(train?.seats || []);
            const pool = seats.length ? seats : (train?.seats || []);
            if (!pool.length) return true;
            return pool.every(s => Number(s.numbs) <= 0);
        },

        canBook(train) {
            if (!this.isOnSale(train)) return false;
            const seats = this.getFilteredSeats(train?.seats || []);
            if (!seats.length) return false;
            return seats.some(s => Number(s.numbs) > 0);
        },

        isBooking(train) {
            if (!train || this.bookingTrainNo === null) return false;
            return String(train.train_no || '') === String(this.bookingTrainNo);
        },

        bookButtonLabel(train) {
            if (this.isBooking(train)) {
                return '<?= addslashes(T::processing) ?>';
            }
            if (!this.isOnSale(train)) {
                return '<?= addslashes(T::opens_soon) ?>';
            }
            if (this.isSoldOut(train)) {
                return '<?= addslashes(T::sold_out) ?>';
            }
            return '<?= addslashes(T::book_now) ?>';
        },

        formatSaleOpens(saleTime) {
            const ts = Number(saleTime);
            if (!ts) return '';
            return new Date(ts * 1000).toLocaleString([], {
                dateStyle: 'medium',
                timeStyle: 'short',
            });
        },

        getLowestPrice(train) {
            const price = this.getMinVisiblePrice(train);
            return price >= 999999 ? null : price;
        },

        getHighestPrice(train) {
            const seats = this.getFilteredSeats(train?.seats || []);
            if (!seats.length) return 0;
            return Math.max(...seats.map(s => parseFloat(s.price) || 0));
        },

        englishStationName(code) {
            const key = String(code || '').toUpperCase();
            if (!key) return '';
            return this.stripChineseLabel(this.stationEnglishMap[key] || key);
        },

        stripChineseLabel(label) {
            return String(label || '')
                .replace(/[\u4E00-\u9FFF\u3400-\u4DBF\uF900-\uFAFF]+/g, '')
                .replace(/\(\s*\)/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        },

        normalizeTrainRow(train) {
            const fromCode = String(train.from_station_code || this.from || '').toUpperCase();
            const toCode = String(train.to_station_code || this.to || '').toUpperCase();
            const fromEnglish = this.stripChineseLabel(train.from_station_english || '');
            const toEnglish = this.stripChineseLabel(train.to_station_english || '');
            return {
                ...train,
                from_station_name: fromEnglish || this.englishStationName(fromCode),
                to_station_name: toEnglish || this.englishStationName(toCode),
            };
        },

        syncRouteStationNames() {
            if (!this.trains.length) return;
            const row = this.trains[0];
            const fromLabel = this.stripChineseLabel(row.from_station_name || '');
            const toLabel = this.stripChineseLabel(row.to_station_name || '');
            if (fromLabel && this.routeFromName === this.from) {
                this.routeFromName = fromLabel;
            }
            if (toLabel && this.routeToName === this.to) {
                this.routeToName = toLabel;
            }
        },

        load() {
            this.loading = true;
            this.searchError = '';
            this.trains = [];
            this.filteredTrains = [];
            this.resetVisibleResults();
            this.loadingMore = false;

            const unixDate = <?= (int)$dateUnix ?>;
            const payload = {
                from_station_code: this.from,
                to_station_code: this.to,
                from_date: unixDate,
                journey_type: this.journeyType,
                adults: this.adults,
                children: this.children,
                infants: this.infants,
                child_ages: this.childAges,
                infant_ages: this.infantAges,
                passenger_metrics: this.encodePassengerMetrics(),
            };

            fetch('<?= root ?>ticket/trainQuery', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                const rows = this.extractTrainRows(res);
                if (rows.length) {
                    this.searchError = '';
                    this.trains = rows.map(t => this.normalizeTrainRow(t));
                    this.syncRouteStationNames();
                    this.buildSeatClassFilters();
                    this.buildFacets();
                    this.applyFilters();
                } else if (res && res.code != null && !this.isApiSuccess(res.code)) {
                    this.searchError = res.msg_en || res.msg || res.message || 'Could not load train schedules.';
                } else {
                    this.searchError = '';
                }
                this.loading = false;
                this.initPriceSlider();
                this.attachScrollListener();
            })
            .catch(err => {
                console.error(err);
                this.searchError = 'Network error while loading trains.';
                this.loading = false;
            });
        },

        isApiSuccess(code) {
            return code === 200 || code === '200';
        },

        isArrayList(arr) {
            if (!Array.isArray(arr)) return false;
            for (let i = 0; i < arr.length; i++) {
                if (!(i in arr)) return false;
            }
            return true;
        },

        extractTrainRows(res) {
            if (!res || typeof res !== 'object') return [];
            const candidates = [
                res?.data?.data?.data,
                res?.data?.data,
                res?.data,
            ];
            for (const candidate of candidates) {
                if (!Array.isArray(candidate)) continue;
                if (candidate.length === 0) continue;
                if (this.isArrayList(candidate)) return candidate;
                if (candidate.data && Array.isArray(candidate.data) && this.isArrayList(candidate.data)) {
                    return candidate.data;
                }
            }
            return [];
        },

        buildSeatClassFilters() {
            const present = new Set();
            this.trains.forEach(t => {
                (t.seats || []).forEach(s => {
                    const code = String(s.seat_class || s.seat_type || '').toUpperCase();
                    if (code) present.add(code);
                });
            });

            this.availableSeatClasses = this.seatClassCatalog.filter(item => present.has(item.code));
            this.selectedSeatClasses = this.availableSeatClasses.map(item => item.code);
        },

        toggleSeatClass(code) {
            code = String(code || '').toUpperCase();
            if (this.selectedSeatClasses.includes(code)) {
                this.selectedSeatClasses = this.selectedSeatClasses.filter(c => c !== code);
            } else {
                this.selectedSeatClasses = [...this.selectedSeatClasses, code];
            }
            this.applyFilters();
        },

        getSeatClassCount(code) {
            code = String(code || '').toUpperCase();
            let count = 0;
            this.trains.forEach(t => {
                (t.seats || []).forEach(s => {
                    if (String(s.seat_class || s.seat_type || '').toUpperCase() === code) {
                        count++;
                    }
                });
            });
            return count;
        },

        buildFacets() {
            const prices = this.trains.flatMap(t => (t.seats || []).map(s => Number(s.price)));
            this.bounds.min = prices.length ? Math.floor(Math.min(...prices) * 100) / 100 : 0;
            this.bounds.max = prices.length ? Math.ceil(Math.max(...prices) * 100) / 100 : 1000;
            this.filters.priceMin = this.bounds.min;
            this.filters.priceMax = this.bounds.max;
        },

        initPriceSlider() {
            const el = document.getElementById('priceSlider');
            if (!el || typeof noUiSlider === 'undefined' || this.bounds.max <= this.bounds.min) {
                if (typeof noUiSlider === 'undefined') setTimeout(() => this.initPriceSlider(), 400);
                return;
            }
            if (el.noUiSlider) el.noUiSlider.destroy();
            noUiSlider.create(el, {
                start: [this.filters.priceMin, this.filters.priceMax],
                connect: true,
                range: { min: this.bounds.min, max: this.bounds.max },
                step: this.bounds.max <= 50 ? 0.01 : 1,
                format: { to: v => Math.round(v * 100) / 100, from: v => Number(v) }
            });
            el.noUiSlider.on('update', (vals) => {
                this.filters.priceMin = parseFloat(vals[0]);
                this.filters.priceMax = parseFloat(vals[1]);
            });
            el.noUiSlider.on('change', () => this.applyFilters());
        },

        getFilteredSeats(seats) {
            if (!Array.isArray(seats) || this.selectedSeatClasses.length === 0) {
                return [];
            }

            const allowed = new Set(this.selectedSeatClasses.map(c => String(c).toUpperCase()));

            return seats.filter(s => {
                const code = String(s.seat_class || s.seat_type || '').toUpperCase();
                if (!allowed.has(code)) return false;

                const price = Number(s.price);
                return price >= this.filters.priceMin && price <= this.filters.priceMax;
            });
        },

        applyFilters() {
            this.filteredTrains = this.trains.filter(t => {
                const visibleSeats = this.getFilteredSeats(t.seats);
                return visibleSeats.length > 0;
            }).sort((a, b) => {
                switch (this.sortBy) {
                    case 'price_high':
                        return this.getMinVisiblePrice(b) - this.getMinVisiblePrice(a);
                    case 'duration':
                        return this.getTrainDurationMinutes(a) - this.getTrainDurationMinutes(b);
                    case 'departure':
                        return this.getTrainDepartureSortTime(a) - this.getTrainDepartureSortTime(b);
                    case 'price_low':
                    default:
                        return this.getMinVisiblePrice(a) - this.getMinVisiblePrice(b);
                }
            });
            this.resetVisibleResults();
        },

        getMinVisiblePrice(train) {
            const seats = this.getFilteredSeats(train?.seats || []);
            if (!seats.length) return 999999;
            return Math.min(...seats.map(s => Number(s.price) || 0));
        },

        getTrainDurationMinutes(train) {
            if (train?.run_time) return Number(train.run_time);
            if (train?.to_date_time && train?.from_date_time) {
                return (Number(train.to_date_time) - Number(train.from_date_time)) / 60;
            }
            return 0;
        },

        getTrainDepartureSortTime(train) {
            const val = train?.from_date_time || train?.from_time;
            if (!val) return 0;
            if (!isNaN(val)) return Number(val);
            if (String(val).includes('-') || String(val).includes(':')) {
                const parsed = Date.parse(val);
                if (!isNaN(parsed)) return parsed;
            }
            if (String(val).length === 4 && !isNaN(val)) {
                return Number(val);
            }
            return 0;
        },

        resetVisibleResults() {
            this.visibleCount = this.itemsPerPage;
            this.allResultsShown = false;
            this.showNoMoreMessage = false;
        },

        displayedTrains() {
            return this.filteredTrains.slice(0, this.visibleCount);
        },

        hasMoreTrains() {
            return this.visibleCount < this.filteredTrains.length;
        },

        attachScrollListener() {
            if (this.scrollListenerAttached) return;

            const scrollHandler = () => {
                if (this.loading || this.loadingMore || !this.hasMoreTrains()) return;

                const scrollPosition = window.innerHeight + window.scrollY;
                const pageHeight = document.documentElement.scrollHeight;
                if (scrollPosition >= pageHeight - 500) {
                    this.loadMoreTrains();
                }
            };

            window.addEventListener('scroll', scrollHandler, { passive: true });
            this.scrollListenerAttached = true;
        },

        async loadMoreTrains() {
            if (this.loadingMore || !this.hasMoreTrains()) {
                if (!this.hasMoreTrains() && this.visibleCount > this.itemsPerPage && !this.allResultsShown) {
                    this.allResultsShown = true;
                    this.showNoMoreMessage = true;
                    setTimeout(() => { this.showNoMoreMessage = false; }, 5000);
                }
                return;
            }

            this.loadingMore = true;
            await new Promise(resolve => setTimeout(resolve, 350));
            this.visibleCount = Math.min(this.visibleCount + this.itemsPerPage, this.filteredTrains.length);
            this.loadingMore = false;

            if (!this.hasMoreTrains() && this.visibleCount > this.itemsPerPage) {
                this.allResultsShown = true;
                this.showNoMoreMessage = true;
                setTimeout(() => { this.showNoMoreMessage = false; }, 5000);
            }
        },

        resetFilters() {
            this.filters = { priceMin: this.bounds.min, priceMax: this.bounds.max };
            this.selectedSeatClasses = this.availableSeatClasses.map(item => item.code);
            this.sortBy = 'price_low';
            const el = document.getElementById('priceSlider');
            if (el && el.noUiSlider) el.noUiSlider.set([this.bounds.min, this.bounds.max]);
            this.applyFilters();
        },

        formatTime(timeVal) {
            if (!timeVal) return '';
            if (!isNaN(timeVal) && String(timeVal).length >= 10) {
                return new Date(timeVal * 1000).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                });
            }
            if (timeVal.length === 4) {
                return timeVal.substring(0, 2) + ':' + timeVal.substring(2, 4);
            }
            return timeVal;
        },

        formatDuration(runTime) {
            if (!runTime) return '';
            const mins = parseInt(runTime);
            if (isNaN(mins)) return runTime;
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return h > 0 ? `${h}h ${m}m` : `${m}m`;
        },

        bookSelectedSeat(train) {
            if (this.bookingTrainNo || !this.canBook(train)) return;
            const filteredSeats = this.getFilteredSeats(train.seats).filter(s => Number(s.numbs) > 0);
            if (filteredSeats.length === 0) return;
            let seat = this.selectedSeats[train.train_no];
            if (!seat || Number(seat.numbs) <= 0) {
                seat = filteredSeats[0];
            }
            this.selectSeat(train, seat);
        },

        getSearchPassengers() {
            const el = document.getElementById('rail-search-widget');
            if (el && typeof Alpine !== 'undefined' && typeof Alpine.$data === 'function') {
                try {
                    const data = Alpine.$data(el);
                    if (data && typeof data.adults !== 'undefined') {
                        const adults = Math.max(1, parseInt(data.adults, 10) || 1);
                        const children = Math.max(0, parseInt(data.children, 10) || 0);
                        const infants = Math.max(0, parseInt(data.infants, 10) || 0);
                        let childAges = Array.isArray(data.childAges) ? data.childAges.map(a => parseInt(a, 10) || 0) : [];
                        let infantAges = Array.isArray(data.infantAges) ? data.infantAges.map(a => parseInt(a, 10) || 0) : [];
                        const policy = (data.regionPolicies && data.regionPolicies[data.journeyType]) || {};
                        const childFallback = parseInt(policy.child_metric_default, 10) || 6;
                        const infantFallback = parseInt(policy.infant_metric_default, 10) || 1;
                        const childMin = parseInt(policy.child_metric_min, 10) || 0;
                        const childMax = parseInt(policy.child_metric_max, 10) || 13;
                        const infantMin = parseInt(policy.infant_metric_min, 10) || 0;
                        const infantMax = parseInt(policy.infant_metric_max, 10) || 5;
                        childAges = childAges.map(a => Math.min(childMax, Math.max(childMin, parseInt(a, 10) || childFallback)));
                        infantAges = infantAges.map(a => Math.min(infantMax, Math.max(infantMin, parseInt(a, 10) || infantFallback)));
                        if (childAges.length > children) childAges = childAges.slice(0, children);
                        if (infantAges.length > infants) infantAges = infantAges.slice(0, infants);
                        while (childAges.length < children) childAges.push(childFallback);
                        while (infantAges.length < infants) infantAges.push(infantFallback);
                        return { adults, children, infants, childAges, infantAges };
                    }
                } catch (e) {
                    console.warn('Rail search passenger sync failed', e);
                }
            }
            return {
                adults: this.adults,
                children: this.children,
                infants: this.infants,
                childAges: Array.isArray(this.childAges) ? this.childAges.slice(0) : [],
                infantAges: Array.isArray(this.infantAges) ? this.infantAges.slice(0) : [],
            };
        },

        encodePassengerMetrics() {
            const childPart = this.children > 0 ? this.childAges.map(v => parseInt(v, 10) || 0).join('-') : '0';
            const infantPart = this.infants > 0 ? this.infantAges.map(v => parseInt(v, 10) || 0).join('-') : '0';
            if (childPart === '0' && infantPart === '0') return '0';
            if (infantPart === '0') return childPart;
            if (childPart === '0') return '~' + infantPart;
            return childPart + '~' + infantPart;
        },

        encodePassengerMetricsFromPax(pax) {
            const childPart = pax.children > 0 ? pax.childAges.map(v => parseInt(v, 10) || 0).join('-') : '0';
            const infantPart = pax.infants > 0 ? pax.infantAges.map(v => parseInt(v, 10) || 0).join('-') : '0';
            if (childPart === '0' && infantPart === '0') return '0';
            if (infantPart === '0') return childPart;
            if (childPart === '0') return '~' + infantPart;
            return childPart + '~' + infantPart;
        },

        selectSeat(train, seat) {
            if (this.bookingTrainNo) return;
            this.bookingTrainNo = String(train?.train_no || '');

            const pax = this.getSearchPassengers();
            const params = new URLSearchParams({
                train_no: train.train_no,
                train_type: this.trainTypeLabel(train.train_no),
                from_code: this.from,
                to_code: this.to,
                from_station: train.from_station_name,
                to_station: train.to_station_name,
                from_time: this.formatTime(train.from_date_time || train.from_time),
                to_time: this.formatTime(train.to_date_time || train.to_time),
                from_date_time: train.from_date_time || '',
                to_date_time: train.to_date_time || '',
                run_time: train.run_time || '',
                date: this.date,
                journey_type: this.journeyType,
                adults: pax.adults,
                children: pax.children,
                infants: pax.infants,
                child_ages: pax.children > 0 ? pax.childAges.join('-') : '0',
                infant_ages: pax.infants > 0 ? pax.infantAges.join('-') : '0',
                passenger_metrics: this.encodePassengerMetricsFromPax(pax),
                seat_class: seat.seat_class || seat.seat_type,
                seat_name: this.seatName(seat),
                price: seat.price,
                price_original: seat.supplier_order_price ?? seat.original_price ?? seat.minPrice ?? seat.price
            });

            window.location.href = '<?= root ?>rail/booking?' + params.toString();
        }
    };
}
</script>
