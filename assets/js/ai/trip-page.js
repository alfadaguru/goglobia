/**
 * AI Trip Planner — wizard drawer (one pick per module, then next tab).
 * Kept separate under assets/js/ai for future AI features.
 */
window.aiTripPage = function (cfg) {
  cfg = cfg || {};
  const CART_KEY = 'ai_trip_cart_v1';
  const CART_Q_KEY = 'ai_trip_cart_q_v1';
  /** Set before leaving for booking so Back can reopen Review — not for a brand-new prompt. */
  const RESUME_KEY = 'ai_trip_resume_summary';
  /** Durable last-used departure city (no coordinates). */
  const DEPARTURE_KEY = 'ai_trip_departure_v1';
  /** Last-used guest nationality for hotel availability (ISO-2 only). */
  const NATIONALITY_KEY = 'ai_trip_nationality_v1';

  return {
    root: cfg.root || '/',
    currency: cfg.currency || 'USD',
    currencyRates: (cfg.currencyRates && typeof cfg.currencyRates === 'object') ? cfg.currencyRates : {},
    csrfToken: cfg.csrfToken || '',
    query: '',
    loading: false,
    error: '',
    sections: [],
    bookingIndex: null,
    loadingMsg: 'Searching with AI…',
    // Automatic departure context (separate from the free-text prompt)
    departureCity: '',
    departureCountry: '',
    departureAirport: '',
    departureSource: '',
    departureLoading: false,
    departureNeedsInput: false,
    departureLocationDenied: false,
    departureLocationRequired: false,
    departureLocationError: '',
    departureEditOpen: false,
    departureSearch: '',
    departureResults: [],
    departureSearchLoading: false,
    _departureReady: null,
    _departureWaitMs: 2500,
    _departureRerunDone: false,
    _pendingDepartureRerun: false,
    _searchedDepartureCity: '',
    _requireLiveGeo: false,
    suggestions: Array.isArray(cfg.suggestions) ? cfg.suggestions : [],
    /** Active module types AI Trip may search (from Admin → Modules). */
    enabledModules: Array.isArray(cfg.enabledModules)
      ? cfg.enabledModules.map((m) => String(m || '').toLowerCase()).filter(Boolean)
      : [],
    tourTypeOptions: Array.isArray(cfg.tourTypeOptions) && cfg.tourTypeOptions.length
      ? cfg.tourTypeOptions
      : [{ value: '', name: 'Any Type', icon: 'category' }],
    umrahDurationOptions: Array.isArray(cfg.umrahDurationOptions) && cfg.umrahDurationOptions.length
      ? cfg.umrahDurationOptions
      : [{ value: '', name: 'Any duration', icon: 'schedule' }],
    umrahTypeOptions: Array.isArray(cfg.umrahTypeOptions) && cfg.umrahTypeOptions.length
      ? cfg.umrahTypeOptions
      : [{ value: '', name: 'Any Type', icon: 'category' }],
    umrahServiceOptions: Array.isArray(cfg.umrahServiceOptions) ? cfg.umrahServiceOptions : [],
    umrahLocationOptions: Array.isArray(cfg.umrahLocationOptions) && cfg.umrahLocationOptions.length
      ? cfg.umrahLocationOptions
      : [{ value: '', name: 'Any location' }],
    stayCountries: Array.isArray(cfg.stayCountries) ? cfg.stayCountries : [],
    esimCountries: Array.isArray(cfg.esimCountries) ? cfg.esimCountries : [],
    nationalityIso: '',
    nationalitySource: '',
    stayNatOpen: false,
    stayNatQuery: '',
    stayNatFiltered: Array.isArray(cfg.stayCountries) ? cfg.stayCountries.slice() : [],
    _nationalityRerunDone: false,
    suggestionsPreview: 5,
    suggestionsMoreOpen: false,
    searchExpanded: true,
    showFinalSummary: false,
    /** Plane animation — only while loading is true. */
    searchAnim: false,
    /** Query snapshot when expand opened — resume anim on collapse if unchanged. */
    _queryAtExpand: '',
    selected: [],
    summaryOpen: false,
    openMods: {},
    drawerModule: '',
    bookingRedirecting: false,
    // Stay rooms loaded inline under each hotel card (keyed by hotel id)
    stayExpandedId: '',
    stayRoomsByHotel: {},
    stayRoomsLoadingId: '',
    stayRoomsErrorByHotel: {},
    stayBookHashByHotel: {},
    stayRoomQuantityByKey: {},
    continueHint: '',
    /** Modules the user chose to skip (still had results). */
    skippedModules: {},
    // Compact filters (aligned with normal flights/stays/tours listing)
    flightFilters: {
      stops: [],          // 0, 1, 2
      airlines: [],
      priceMin: null,
      priceMax: null,
      flightNumber: '',
      sort: 'price_low',
    },
    hotelFilters: {
      stars: [],          // 1-5
      nameSearch: '',
      priceMin: null,
      priceMax: null,
      sort: 'price_low',
    },
    tourFilters: {
      stars: [],          // 1-5
      nameSearch: '',
      priceMin: null,
      priceMax: null,
      tourType: '',       // tours_settings id / empty = any
      sort: 'price_low',
    },
    // Same ideas as normal cars listing filters
    carFilters: {
      nameSearch: '',
      priceMin: null,
      priceMax: null,
      carTypes: [],       // economy, compact, …
      transmission: [],   // automatic, manual
      suppliers: [],
      features: [],       // unlimited_mileage, air_conditioning, free_cancellation
      passengers: [],     // 2, 4, 5, 7 (min capacity)
      sort: 'price_low',
    },
    carTypeOptions: [
      { value: 'economy', name: 'Economy' },
      { value: 'compact', name: 'Compact' },
      { value: 'midsize', name: 'Midsize' },
      { value: 'fullsize', name: 'Fullsize' },
      { value: 'suv', name: 'SUV' },
      { value: 'luxury', name: 'Luxury' },
      { value: 'van', name: 'Van' },
      { value: 'convertible', name: 'Convertible' },
    ],
    carFeatureOptions: [
      { value: 'unlimited_mileage', name: 'Unlimited mileage' },
      { value: 'air_conditioning', name: 'Air conditioning' },
      { value: 'free_cancellation', name: 'Free cancellation' },
    ],
    carPassengerOptions: [2, 4, 5, 7],
    // Same ideas as normal bus listing filters
    busFilters: {
      nameSearch: '',
      priceMin: null,
      priceMax: null,
      operators: [],
      busTypes: [],
      seatClasses: [],
      timeSlots: [],
      amenities: [],
      refundableOnly: false,
      sort: 'price_low',
    },
    busTimeSlotOptions: [
      { id: 'morning', label: 'Morning', range: '05–12', from: 5, to: 12 },
      { id: 'afternoon', label: 'Afternoon', range: '12–17', from: 12, to: 17 },
      { id: 'evening', label: 'Evening', range: '17–21', from: 17, to: 21 },
      { id: 'night', label: 'Night', range: '21–05', from: 21, to: 29 },
    ],
    // Rail filters — options built from live card data only (no hardcoded seat catalogs)
    railFilters: {
      nameSearch: '',
      priceMin: null,
      priceMax: null,
      seatClasses: [],
      timeSlots: [],
      sort: 'price_low',
    },
    railTimeSlotOptions: [
      { id: 'morning', label: 'Morning', range: '05–12', from: 5, to: 12 },
      { id: 'afternoon', label: 'Afternoon', range: '12–17', from: 12, to: 17 },
      { id: 'evening', label: 'Evening', range: '17–21', from: 17, to: 21 },
      { id: 'night', label: 'Night', range: '21–05', from: 21, to: 29 },
    ],
    // Same ideas as normal /esim booking filters
    esimFilters: {
      nameSearch: '',
      duration: '',
      dataLimit: '',
      price: '',
      packageTypes: [],
      sort: 'price_low',
    },
    // Same ideas as normal Umrah search (Duration / Type / Services)
    umrahFilters: {
      destination: '',
      duration: '',
      umrahType: '',
      services: [],
      nameSearch: '',
      sort: 'price_low',
    },
    umrahRefreshing: false,
    expandedUmrahId: null,
    umrahDetailsById: {},
    umrahDetailsLoadingId: '',
    umrahDetailsErrorById: {},
    umrahDayOpenById: {},
    expandedTourId: null,
    tourDetailsById: {},
    tourDetailsLoadingId: '',
    tourDetailsErrorById: {},
    tourDayOpenById: {},
    filtersOpen: false,
    _msgs: [
      'Searching with AI…',
      'Understanding your travel request…',
      'Finding matching options for you…',
      'Gathering the best results…',
      'Almost ready…'
    ],
    _msgTimer: null,
    _searchInFlight: false,

    get animDisplayMsg() {
      if (this.error && !this.loading) return this.error;
      return this.loadingMsg;
    },

    init() {
      window.aiTripGenerate = () => this.generateFromBox();
      this._restoreNationality();
      this.filterStayNatCountries();
      // Resolve departure in parallel with query restore — search waits on the promise.
      this._departureReady = this.resolveDepartureContext();

      let fromPending = false;
      const pending = this._ssGet('ai_trip_pending_q');
      if (pending) {
        fromPending = true;
        this.query = pending;
        this._ssRemove('ai_trip_pending_q');
        this._ssSet('ai_trip_last_q', pending);
      } else {
        const last = this._ssGet('ai_trip_last_q');
        const legacy = (cfg.legacyQ || '').trim();
        this.query = last || legacy || '';
        if (legacy && window.history && window.history.replaceState) {
          window.history.replaceState({}, '', this.root + 'ai-trip');
        }
      }

      // Keep a single scroller: lock page scroll while suggestions modal is open
      this.$watch('suggestionsMoreOpen', (open) => this._syncPageScrollLock());
      this._syncPageScrollLock();

      const q = (this.query || '').trim();
      const cart = this._onePerModule(this._cartGet());
      const cartQ = (this._ssGet(CART_Q_KEY) || '').trim();
      const resume = this._ssGet(RESUME_KEY) === '1';
      const samePrompt = !!(cart.length && cartQ && cartQ === q);

      // New prompt from home (or any pending) must never reopen an old Review page
      if (fromPending) {
        if (!samePrompt || !resume) {
          this.clearSelected();
          this.showFinalSummary = false;
          if (q.length >= 10) {
            const blocked = this.preSearchBlockMessage(q);
            if (blocked) {
              this.error = blocked;
              this.loading = false;
              this.searchAnim = false;
              this.sections = [];
            } else {
              this._runSearch();
            }
          }
          return;
        }
      }

      // Back from booking: same prompt + resume flag → show Review with saved picks
      if (resume && samePrompt) {
        this.selected = cart;
        this.normalizeCartCurrencies();
        this._ssRemove(RESUME_KEY);
        this.showFinalSummary = true;
        this.searchExpanded = false;
        this.searchAnim = false;
        return;
      }

      // Stale cart for a different prompt — drop it
      if (cart.length && !samePrompt) {
        this.clearSelected();
      } else {
        this.selected = cart;
        this.normalizeCartCurrencies();
      }

      if (q.length >= 10) {
        const blocked = this.preSearchBlockMessage(q);
        if (blocked) {
          this.error = blocked;
          this.loading = false;
          this.searchAnim = false;
          this.sections = [];
        } else {
          this._runSearch();
        }
      }
    },

    /** Freeze background page scroll so only the drawer/modal scrolls. */
    _syncPageScrollLock() {
      const lock = !!this.suggestionsMoreOpen;
      const html = document.documentElement;
      const body = document.body;
      if (!html || !body) return;

      if (lock) {
        if (!html.classList.contains('ai-trip-scroll-lock')) {
          this._lockedScrollY = window.scrollY || window.pageYOffset || 0;
          html.classList.add('ai-trip-scroll-lock');
          body.classList.add('ai-trip-scroll-lock', 'ai-trip-scroll-lock-fixed');
          body.style.top = '-' + this._lockedScrollY + 'px';
        }
        return;
      }

      if (!html.classList.contains('ai-trip-scroll-lock')) return;
      const y = this._lockedScrollY || 0;
      html.classList.remove('ai-trip-scroll-lock');
      body.classList.remove('ai-trip-scroll-lock', 'ai-trip-scroll-lock-fixed');
      body.style.top = '';
      window.scrollTo(0, y);
    },

    // ── sessionStorage ───────────────────────────────────────────
    _ssGet(key) {
      try { return sessionStorage.getItem(key) || null; } catch (e) { return null; }
    },
    _ssSet(key, val) {
      try { sessionStorage.setItem(key, val); } catch (e) {}
    },
    _ssRemove(key) {
      try { sessionStorage.removeItem(key); } catch (e) {}
    },
    _cartGet() {
      try {
        const raw = sessionStorage.getItem(CART_KEY);
        const arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
      } catch (e) { return []; }
    },
    _cartSave() {
      try {
        sessionStorage.setItem(CART_KEY, JSON.stringify(this._plainList(this.selected)));
        const q = (this.query || '').trim();
        if (q && (this.selected || []).length) this._ssSet(CART_Q_KEY, q);
        else this._ssRemove(CART_Q_KEY);
      } catch (e) {}
    },
    /** Force a plain array clone (Alpine Proxy / Rocket Loader can break JSON.stringify). */
    _plainList(list) {
      const src = Array.isArray(list) ? list : [];
      const out = [];
      for (let i = 0; i < src.length; i++) {
        try {
          out.push(JSON.parse(JSON.stringify(src[i])));
        } catch (e) {
          // skip non-serializable entry
        }
      }
      return out;
    },
    /** Slim cart rows for save-draft — live CF/nginx often rejects huge room_data payloads. */
    _slimBookingItem(it) {
      if (!it || typeof it !== 'object') return null;
      const mod = String(it.module || it.kind || '').toLowerCase();
      let params = (it.params && typeof it.params === 'object') ? { ...it.params } : {};
      let item = (it.item && typeof it.item === 'object') ? it.item : {};

      if (mod === 'stays' || mod === 'stay' || mod === 'hotels' || mod === 'hotel') {
        const roomsSrc = Array.isArray(it.selected_rooms)
          ? it.selected_rooms
          : (Array.isArray(item.selected_rooms) ? item.selected_rooms : []);
        const selectedRooms = roomsSrc.map((r) => ({
          room_id: r.room_id || '',
          room_name: r.room_name || '',
          quantity: Math.max(1, Number(r.quantity) || 1),
          option_index: r.option_index,
          rate_key: r.rate_key || '',
          board_name: r.board_name || '',
          breakfast_included: r.breakfast_included,
          refundable: r.refundable,
          total_price: r.total_price,
          original_price: r.original_price ?? r.room_option?.original_price ?? r.room_option?.base_price ?? 0,
          base_price: r.base_price ?? r.room_option?.base_price ?? r.room_option?.original_price ?? 0,
          currency: r.currency || '',
          image: r.image || '',
          // keep option needed for booking, drop bulky room_data blobs
          room_option: r.room_option || null,
        }));
        item = {
          id: item.id || item.hotel_id || '',
          hotel_id: item.hotel_id || item.id || '',
          name: item.name || '',
          image: item.image || '',
          location: item.location || '',
          price: item.price,
          currency: item.currency || '',
          supplier: item.supplier || it.supplier || '',
          checkin: item.checkin || params.checkin || '',
          checkout: item.checkout || params.checkout || '',
          nationality: item.nationality || params.nationality || '',
          rooms: item.rooms || params.rooms || '1',
          adults: item.adults || params.adults || '1',
          children: item.children || params.children || '0',
          book_hash: item.book_hash || '',
          redirect: item.redirect || '',
          chain: item.chain || '',
          selected_rooms: selectedRooms,
          selected_room: selectedRooms[0] || item.selected_room || null,
        };
      } else if (mod === 'flights' || mod === 'flight') {
        // Keep flight raw for ticket issue; strip unrelated noise.
        // Preserve returnFlight / isMultiCity so booking summary can show all legs.
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        const returnFlight = item.returnFlight || raw.returnFlight || null;
        const isMultiCity = !!(item.isMultiCity || raw.isMultiCity || raw.type === 'multicity'
          || (Array.isArray(item.segments) && item.segments.length > 1 && !returnFlight && !item.isRoundTrip));
        const isRoundTrip = !isMultiCity && !!(item.isRoundTrip || raw.isRoundTrip || returnFlight);
        const segments = Array.isArray(item.segments)
          ? item.segments
          : (Array.isArray(raw.segments) ? raw.segments : null);
        item = {
          kind: 'flight',
          supplier: item.supplier || it.supplier || '',
          airline: item.airline || '',
          airlineName: item.airlineName || '',
          flight_no: item.flight_no || '',
          img: item.img || '',
          departure_time: item.departure_time || '',
          departure_code: item.departure_code || '',
          departure_date: item.departure_date || '',
          arrival_time: item.arrival_time || '',
          arrival_code: item.arrival_code || '',
          arrival_date: item.arrival_date || '',
          duration_time: item.duration_time || '',
          stops: item.stops,
          class: item.class || '',
          currency: item.currency || '',
          price: item.price,
          actual_price: item.actual_price ?? raw.actual_price ?? 0,
          isRoundTrip,
          isMultiCity,
          returnFlight: isMultiCity ? null : returnFlight,
          returnStops: isMultiCity ? 0 : (item.returnStops ?? raw.returnStops ?? 0),
          returnDuration: isMultiCity ? '' : (item.returnDuration || raw.returnDuration || (returnFlight?.duration_time || '')),
          segments: segments || undefined,
          raw: {
            ...raw,
            isMultiCity,
            isRoundTrip,
            type: isMultiCity ? 'multicity' : (raw.type || (isRoundTrip ? 'return' : 'oneway')),
            segments: segments || raw.segments,
            returnFlight: isMultiCity ? null : (raw.returnFlight || returnFlight),
          },
        };
      } else if (mod === 'tours' || mod === 'tour') {
        item = {
          kind: 'tour',
          id: item.id || item.tour_id || '',
          tour_id: item.tour_id || item.id || '',
          name: item.name || '',
          image: item.image || '',
          location: item.location || '',
          price: item.price,
          actual_price: item.actual_price ?? item.raw?.actual_price ?? 0,
          actual_price_per_adult: item.actual_price_per_adult ?? item.raw?.actual_price_per_adult ?? 0,
          actual_price_per_child: item.actual_price_per_child ?? item.raw?.actual_price_per_child ?? 0,
          price_per_adult: item.price_per_adult || item.price_per_person || 0,
          price_per_child: item.price_per_child || 0,
          price_per_person: item.price_per_person || item.price_per_adult || 0,
          currency: item.currency || '',
          supplier: item.supplier || it.supplier || '',
          days: item.days || '',
          nights: item.nights || '',
          duration_formatted: item.duration_formatted || '',
          stars: item.stars || 0,
          rating: item.rating || 0,
          tour_type: item.tour_type || '',
          start_date: item.start_date || params.start_date || '',
          adults: item.adults || params.adults || '1',
          children: item.children || params.children || '0',
          max_adults: item.max_adults || 10,
          max_children: item.max_children || 6,
          inclusions: Array.isArray(item.inclusions) ? item.inclusions.slice(0, 20) : [],
          exclusions: Array.isArray(item.exclusions) ? item.exclusions.slice(0, 20) : [],
          description: item.description || '',
        };
        params = {
          ...params,
          adults: item.adults,
          children: item.children,
          start_date: item.start_date || params.start_date || '',
          duration: item.days || params.duration || '1',
        };
      } else if (mod === 'cars' || mod === 'car') {
        const raw = item.raw && typeof item.raw === 'object'
          ? item.raw
          : (item._raw && typeof item._raw === 'object' ? item._raw : item);
        item = {
          kind: 'car',
          id: item.id || item.car_id || '',
          car_id: item.car_id || item.original_id || item.id || '',
          name: item.name || '',
          image: item.image || item.img || '',
          price: item.price,
          price_per_day: item.price_per_day || item.display_price_per_day || 0,
          currency: item.currency || '',
          supplier: item.supplier || it.supplier || '',
          vendor: item.vendor || '',
          vendor_code: item.vendor_code || '',
          transmission: item.transmission || '',
          fuel_type: item.fuel_type || '',
          passengers: item.passengers || 4,
          baggage: item.baggage || item.bags || 2,
          doors: item.doors || 4,
          category: item.category || item.car_type || '',
          rental_days: item.rental_days || 1,
          free_cancellation: !!item.free_cancellation,
          unlimited_mileage: !!item.unlimited_mileage,
          pickup_location: item.pickup_location || params.pickup_location || '',
          dropoff_location: item.dropoff_location || params.dropoff_location || '',
          reference_id: item.reference_id || raw.reference_id || '',
          pickup_datetime: item.pickup_datetime || raw.pickup_datetime || '',
          dropoff_datetime: item.dropoff_datetime || raw.dropoff_datetime || '',
          actual_price: item.actual_price || raw.actual_price || 0,
          raw: raw,
        };
        params = {
          ...params,
          service_type: params.service_type || 'rental',
          pickup_location: item.pickup_location || params.pickup_location || '',
          dropoff_location: item.dropoff_location || params.dropoff_location || '',
          pickup_code: params.pickup_code || '',
          dropoff_code: params.dropoff_code || params.pickup_code || '',
          pickup_date: params.pickup_date || '',
          return_date: params.return_date || params.dropoff_date || '',
          dropoff_date: params.dropoff_date || params.return_date || '',
          pickup_time: params.pickup_time || '10:00',
          dropoff_time: params.dropoff_time || params.return_time || '10:00',
          driver_age: params.driver_age || '30',
        };
      } else if (mod === 'bus' || mod === 'buses') {
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        const ret = item.return_trip && typeof item.return_trip === 'object' ? item.return_trip : null;
        item = {
          kind: 'bus',
          id: item.id || String(item.route_id || ''),
          route_id: item.route_id || 0,
          name: item.name || item.service_name || item.operator || 'Bus',
          service_name: item.service_name || '',
          operator: item.operator || '',
          bus_type: item.bus_type || '',
          seat_class: item.seat_class || '',
          origin: item.origin || params.origin || '',
          destination: item.destination || params.destination || '',
          departure_time: item.departure_time || '',
          arrival_time: item.arrival_time || '',
          duration: item.duration || '',
          date: item.date || params.date || '',
          seats_available: item.seats_available || 0,
          adult_price: item.adult_price || item.price || 0,
          child_price: item.child_price || 0,
          price: item.price,
          currency: item.currency || '',
          supplier: item.supplier || it.supplier || 'bus',
          image: item.image || item.img || '',
          refundable: !!item.refundable,
          amenities: Array.isArray(item.amenities) ? item.amenities.slice(0, 20) : [],
          return_trip: ret,
          trip_type: ret ? 'return' : (params.trip_type || 'oneway'),
          adults: item.adults || params.adults || '1',
          children: item.children || params.children || '0',
          raw: raw,
        };
        params = {
          ...params,
          origin: item.origin || params.origin || '',
          destination: item.destination || params.destination || '',
          date: item.date || params.date || '',
          return_date: params.return_date || '',
          trip_type: item.trip_type || params.trip_type || 'oneway',
          adults: String(item.adults || params.adults || '1'),
          children: String(item.children || params.children || '0'),
          passengers: String(
            (parseInt(item.adults || params.adults || 1, 10) || 1)
            + (parseInt(item.children || params.children || 0, 10) || 0)
          ),
        };
      } else if (mod === 'esim' || mod === 'e-sim') {
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        item = {
          kind: 'esim',
          id: item.id || raw.id || '',
          name: item.name || item.title || raw.title || 'eSIM',
          title: item.title || raw.title || item.name || 'eSIM',
          country: item.country || params.country_name || params.country || '',
          country_iso: item.country_iso || params.country || '',
          data_limit: item.data_limit || raw.data_limit || '',
          duration: item.duration || raw.duration || '',
          package_type: item.package_type || raw.package_type || params.package_type || 'local',
          base_price: item.base_price != null ? item.base_price : (raw.base_price || 0),
          price: item.price != null ? item.price : raw.price,
          actual_price: item.actual_price != null ? item.actual_price : (raw.actual_price || 0),
          currency: item.currency || raw.currency || params.currency || '',
          supplier: item.supplier || it.supplier || 'airalo',
          module_id: item.module_id || params.module_id || '',
          commission_type: item.commission_type || raw.commission_type || '',
          commission_value: item.commission_value != null ? item.commission_value : (raw.commission_value || 0),
          raw: raw,
        };
        params = {
          ...params,
          module_id: String(item.module_id || params.module_id || ''),
          country: String(item.country_iso || params.country || '').toUpperCase(),
          country_name: item.country || params.country_name || '',
          // Keep the search filter used to load packages (usually "all").
          // Overwriting with the package's local/global type caused revalidation
          // to query a narrower feed and miss the selected package.
          search_package_type: String(params.search_package_type || params.package_type || 'all').toLowerCase(),
          package_type: String(params.search_package_type || params.package_type || 'all').toLowerCase(),
          item_package_type: String(item.package_type || raw.package_type || 'local').toLowerCase(),
        };
      } else if (mod === 'visa') {
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        item = {
          kind: 'visa',
          id: item.id || raw.id || '',
          visa_id: item.visa_id || raw.visa_id || 0,
          name: item.name || item.title || raw.title || 'Visa',
          title: item.title || raw.title || item.name || 'Visa',
          subtitle: item.subtitle || raw.subtitle || '',
          from_country: item.from_country || params.from_country || '',
          from_country_name: item.from_country_name || params.from_country_name || '',
          to_country: item.to_country || params.to_country || '',
          to_country_name: item.to_country_name || params.to_country_name || '',
          visa_type: item.visa_type || params.visa_type || 'tourist',
          visa_type_name: item.visa_type_name || '',
          processing_speed: item.processing_speed || params.processing_speed || 'standard',
          processing_speed_name: item.processing_speed_name || '',
          entry_date: item.entry_date || params.entry_date || params.travel_date || '',
          travelers: item.travelers || params.travelers || '1',
          duration_days: item.duration_days || 0,
          govt_fee: item.govt_fee != null ? item.govt_fee : (raw.govt_fee || 0),
          service_fee: item.service_fee != null ? item.service_fee : (raw.service_fee || 0),
          price_per_person: item.price_per_person != null ? item.price_per_person : (raw.price_per_person || 0),
          price: item.price != null ? item.price : raw.price,
          currency: item.currency || raw.currency || params.currency || '',
          is_inquiry_only: !!(item.is_inquiry_only || raw.is_inquiry_only),
          supplier: item.supplier || it.supplier || 'visa',
          image: item.image || raw.image || '',
          listing_url: item.listing_url || raw.listing_url || '',
          requirements: Array.isArray(item.requirements) ? item.requirements.slice(0, 20) : [],
          raw: raw,
        };
        params = {
          ...params,
          from_country: String(item.from_country || params.from_country || '').toUpperCase(),
          from_country_name: item.from_country_name || params.from_country_name || '',
          to_country: String(item.to_country || params.to_country || '').toUpperCase(),
          to_country_name: item.to_country_name || params.to_country_name || '',
          entry_date: item.entry_date || params.entry_date || '',
          travel_date: item.entry_date || params.travel_date || params.entry_date || '',
          visa_type: item.visa_type || params.visa_type || 'tourist',
          processing_speed: item.processing_speed || params.processing_speed || 'standard',
          travelers: String(item.travelers || params.travelers || '1'),
        };
      } else if (mod === 'umrah') {
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        item = {
          kind: 'umrah',
          id: item.id || raw.id || '',
          umrah_id: item.umrah_id || raw.umrah_id || Number(item.id || raw.id || 0) || 0,
          name: item.name || item.title || raw.title || 'Umrah',
          title: item.title || raw.title || item.name || 'Umrah',
          subtitle: item.subtitle || raw.subtitle || '',
          location: item.location || raw.location || params.destination || '',
          days: item.days != null ? item.days : (raw.days || 0),
          nights: item.nights != null ? item.nights : (raw.nights || 0),
          umrah_type: item.umrah_type || raw.umrah_type || '',
          umrah_type_id: item.umrah_type_id || raw.umrah_type_id || 0,
          start_date: item.start_date || params.start_date || '',
          adults: item.adults != null ? item.adults : (params.adults || 0),
          children: item.children != null ? item.children : (params.children || 0),
          infants: item.infants != null ? item.infants : (params.infants || 0),
          max_adults: item.max_adults != null ? item.max_adults : (raw.max_adults || 0),
          max_children: item.max_children != null ? item.max_children : (raw.max_children || 0),
          max_infants: item.max_infants != null ? item.max_infants : (raw.max_infants || 0),
          adult_price: item.adult_price != null ? item.adult_price : (raw.adult_price || 0),
          child_price: item.child_price != null ? item.child_price : (raw.child_price || 0),
          infant_price: item.infant_price != null ? item.infant_price : (raw.infant_price || 0),
          price: item.price != null ? item.price : raw.price,
          actual_price: item.actual_price != null ? item.actual_price : (raw.actual_price || 0),
          currency: item.currency || raw.currency || params.currency || '',
          supplier: item.supplier || it.supplier || 'umrah',
          image: item.image || raw.image || '',
          slug: item.slug || raw.slug || '',
          raw: raw,
        };
        params = {
          ...params,
          destination: item.location || params.destination || '',
          start_date: item.start_date || params.start_date || '',
          adults: String(item.adults ?? params.adults ?? '0'),
          children: String(item.children ?? params.children ?? '0'),
          infants: String(item.infants ?? params.infants ?? '0'),
        };
      } else if (mod === 'rail' || mod === 'train' || mod === 'trains') {
        const raw = item.raw && typeof item.raw === 'object' ? item.raw : item;
        const detail = item.detail && typeof item.detail === 'object' ? item.detail : (raw.detail || {});
        item = {
          kind: 'rail',
          id: item.id || raw.id || '',
          name: item.name || item.title || raw.title || 'Train',
          title: item.title || raw.title || item.name || 'Train',
          subtitle: item.subtitle || raw.subtitle || '',
          train_no: item.train_no || item.traffic_no || raw.train_no || '',
          traffic_no: item.traffic_no || item.train_no || raw.traffic_no || '',
          seat_class: item.seat_class || raw.seat_class || '',
          seat_class_label: item.seat_class_label || raw.seat_class_label || '',
          from_station_code: item.from_station_code || params.from_station_code || '',
          to_station_code: item.to_station_code || params.to_station_code || '',
          from_station_name: item.from_station_name || item.origin || params.from_station_name || '',
          to_station_name: item.to_station_name || item.destination || params.to_station_name || '',
          origin: item.origin || item.from_station_name || '',
          destination: item.destination || item.to_station_name || '',
          departure_time: item.departure_time || '',
          arrival_time: item.arrival_time || '',
          duration: item.duration || '',
          date: item.date || item.travel_date || params.travel_date || '',
          journey_type: item.journey_type || params.journey_type || '',
          journey_type_label: item.journey_type_label || '',
          adults: item.adults != null ? item.adults : (params.adults || 1),
          children: item.children != null ? item.children : (params.children || 0),
          infants: item.infants != null ? item.infants : (params.infants || 0),
          billable_passengers: item.billable_passengers || 0,
          seats_available: item.seats_available || 0,
          price_per_seat: item.price_per_seat != null ? item.price_per_seat : 0,
          price_base: item.price_base != null ? item.price_base : (item.price_total_limit || 0),
          price_total_limit: item.price_total_limit != null ? item.price_total_limit : (item.price_base || 0),
          price: item.price != null ? item.price : raw.price,
          currency: item.currency || raw.currency || params.currency || '',
          supplier: item.supplier || it.supplier || 'train',
          detail: detail,
          raw: raw,
        };
        params = {
          ...params,
          journey_type: String(item.journey_type || params.journey_type || ''),
          from_station: item.from_station_code || params.from_station || '',
          to_station: item.to_station_code || params.to_station || '',
          from_station_code: item.from_station_code || params.from_station_code || '',
          to_station_code: item.to_station_code || params.to_station_code || '',
          from_station_name: item.from_station_name || params.from_station_name || '',
          to_station_name: item.to_station_name || params.to_station_name || '',
          travel_date: item.date || params.travel_date || '',
          adults: String(item.adults ?? params.adults ?? '1'),
          children: String(item.children ?? params.children ?? '0'),
          infants: String(item.infants ?? params.infants ?? '0'),
          seat_class: item.seat_class || params.seat_class || '',
          traffic_no: item.traffic_no || params.traffic_no || '',
        };
      } else if (mod === 'ferries' || mod === 'ferry') {
        // Keep the site-shaped selected_sailing / return_sailing — Kikoto draft + issue need them.
        const detail = item.detail && typeof item.detail === 'object' ? item.detail : {};
        item = {
          kind: 'ferries',
          id: item.id || '',
          name: item.name || item.title || 'Ferry',
          title: item.title || item.name || 'Ferry',
          subtitle: item.subtitle || '',
          supplier: item.supplier || it.supplier || 'kikoto',
          company: item.company || '',
          company_id: item.company_id || 0,
          ship_name: item.ship_name || '',
          origin: item.origin || params.departure_port_name || '',
          destination: item.destination || params.destination_port_name || '',
          departure_port_id: item.departure_port_id || params.departure_port_id || 0,
          destination_port_id: item.destination_port_id || params.destination_port_id || 0,
          departure_datetime: item.departure_datetime || '',
          arrival_datetime: item.arrival_datetime || '',
          departure_time: item.departure_time || '',
          arrival_time: item.arrival_time || '',
          duration: item.duration || '',
          duration_minutes: item.duration_minutes || 0,
          date: item.date || params.date || '',
          return_date: item.return_date || params.return_date || '',
          trip_type: item.trip_type || params.trip_type || 'oneway',
          accommodation_id: item.accommodation_id || '',
          accommodation_title: item.accommodation_title || '',
          accommodation_code: item.accommodation_code || '',
          adults: item.adults != null ? item.adults : (params.adults || 1),
          children: item.children != null ? item.children : (params.children || 0),
          infant: item.infant != null ? item.infant : (params.infant || 0),
          vehicles: item.vehicles != null ? item.vehicles : (params.vehicles || 0),
          vehicle_type: item.vehicle_type || params.vehicle_type || '',
          pets: item.pets != null ? item.pets : (params.pets || 0),
          pet_type: item.pet_type || params.pet_type || '',
          bonuses: Array.isArray(item.bonuses) ? item.bonuses : [],
          price: item.price,
          original_price: item.original_price ?? item.base_price ?? 0,
          base_price: item.base_price ?? item.original_price ?? 0,
          currency: item.currency || params.currency || '',
          detail: detail,
        };
        params = {
          ...params,
          departure_port_id: String(item.departure_port_id || ''),
          destination_port_id: String(item.destination_port_id || ''),
          departure_port_name: item.origin || '',
          destination_port_name: item.destination || '',
          date: item.date || '',
          return_date: item.return_date || '',
          trip_type: item.trip_type || 'oneway',
          adults: String(item.adults ?? '1'),
          children: String(item.children ?? '0'),
          infant: String(item.infant ?? '0'),
          vehicles: String(item.vehicles ?? '0'),
          vehicle_type: item.vehicle_type || '',
          pets: String(item.pets ?? '0'),
          pet_type: item.pet_type || '',
          bonuses: Array.isArray(item.bonuses) ? item.bonuses : [],
        };
      }

      let normalizedMod = mod;
      if (mod === 'hotels' || mod === 'hotel') normalizedMod = 'stays';
      if (mod === 'flight') normalizedMod = 'flights';
      if (mod === 'tour') normalizedMod = 'tours';
      if (mod === 'car') normalizedMod = 'cars';
      if (mod === 'buses') normalizedMod = 'bus';
      if (mod === 'e-sim') normalizedMod = 'esim';
      if (mod === 'train' || mod === 'trains') normalizedMod = 'rail';
      if (mod === 'ferry') normalizedMod = 'ferries';

      return {
        key: it.key || '',
        module: normalizedMod,
        kind: it.kind || mod,
        title: it.title || '',
        subtitle: it.subtitle || '',
        price: Number(item.price != null ? item.price : it.price) || 0,
        currency: it.currency || this.currency,
        supplier: it.supplier || '',
        image: it.image || '',
        href: it.href || '',
        params: params,
        item: item,
        selected_rooms: Array.isArray(it.selected_rooms) ? it.selected_rooms.map((r) => ({
          room_id: r.room_id || '',
          room_name: r.room_name || '',
          quantity: Math.max(1, Number(r.quantity) || 1),
          option_index: r.option_index,
          rate_key: r.rate_key || '',
          board_name: r.board_name || '',
          total_price: r.total_price,
          original_price: r.original_price ?? r.room_option?.original_price ?? r.room_option?.base_price ?? 0,
          base_price: r.base_price ?? r.room_option?.base_price ?? r.room_option?.original_price ?? 0,
          currency: r.currency || '',
          image: r.image || '',
          room_option: r.room_option || null,
        })) : undefined,
      };
    },
    _bookingItemsPayload() {
      let list = this._plainList(this.selected);
      if (!list.length) {
        list = this._plainList(this._cartGet());
        if (list.length) {
          // Rehydrate UI if memory was emptied but cart still has items (bfcache / back nav)
          this.selected = this._onePerModule(list);
        }
      }
      
      const orderedMods = this.orderedModules;
      list.sort((a, b) => {
        const idxA = orderedMods.indexOf(a.module);
        const idxB = orderedMods.indexOf(b.module);
        const aVal = idxA !== -1 ? idxA : 999;
        const bVal = idxB !== -1 ? idxB : 999;
        return aVal - bVal;
      });

      return list.map((it) => this._slimBookingItem(it)).filter(Boolean);
    },

    // ── Selection (one item per module — wizard) ─────────────────
    /** Keep cart / summary / booking in the same order as AI sections (prompt order). */
    _sortBySectionOrder(list) {
      const src = Array.isArray(list) ? list : [];
      const order = (this.sections || []).map((s) => s.module).filter(Boolean);
      if (!order.length) return src.slice();
      return src.slice().sort((a, b) => {
        const ia = order.indexOf(a?.module);
        const ib = order.indexOf(b?.module);
        return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
      });
    },
    _onePerModule(arr) {
      const out = [];
      const seen = {};
      (Array.isArray(arr) ? arr : []).forEach((x) => {
        const m = x?.module || 'other';
        if (seen[m]) return;
        seen[m] = true;
        out.push(x);
      });
      return this._sortBySectionOrder(out);
    },
    itemKey(section, item, iIdx) {
      const mod = section?.module || item?.kind || 'item';
      const sup = item?.supplier || '';
      // Flights often share flight_no across schedules — include times + index
      if (mod === 'flights' || item?.kind === 'flight') {
        const multiKey = (item?.isMultiCity || item?.raw?.isMultiCity || item?.raw?.type === 'multicity')
          ? this._multiCityDedupKey(item)
          : '';
        return [
          mod,
          sup,
          multiKey || item?.flight_no || '',
          item?.departure_code || '',
          item?.arrival_code || '',
          item?.departure_date || '',
          item?.departure_time || '',
          item?.arrival_time || '',
          item?.price ?? '',
          iIdx
        ].join('::');
      }
      const id = item?.id || item?.name || iIdx;
      // Cars: stable id already includes supplier+index from _normCars — avoid filter-index drift
      if (mod === 'cars' || item?.kind === 'car') {
        return [
          'cars',
          sup,
          item?.id || '',
          item?.reference_id || '',
          item?.name || '',
          item?.price ?? ''
        ].join('::');
      }
      if (mod === 'bus' || item?.kind === 'bus') {
        return [
          'bus',
          sup,
          item?.route_id || item?.id || '',
          item?.return_trip?.route_id || '',
          item?.departure_time || '',
          item?.date || '',
          item?.price ?? ''
        ].join('::');
      }
      if (mod === 'rail' || mod === 'train' || item?.kind === 'rail') {
        return [
          'rail',
          sup,
          item?.traffic_no || item?.train_no || item?.id || '',
          item?.seat_class || '',
          item?.from_station_code || '',
          item?.to_station_code || '',
          item?.date || '',
          item?.price ?? ''
        ].join('::');
      }
      if (mod === 'ferries' || item?.kind === 'ferries') {
        return [
          'ferries',
          sup,
          item?.company_id || '',
          item?.departure_port_id || '',
          item?.destination_port_id || '',
          item?.departure_datetime || '',
          item?.accommodation_id || item?.selected_ferry_acc_id || '',
          item?.price ?? ''
        ].join('::');
      }
      return mod + '::' + String(sup) + '::' + String(id) + '::' + String(iIdx);
    },
    selectionForModule(mod) {
      return (this.selected || []).find((x) => x.module === mod) || null;
    },
    isSelected(key) {
      return (this.selected || []).some((x) => x.key === key);
    },
    isItemSelected(section, item, iIdx) {
      const key = this.itemKey(section, item, iIdx);
      if (this.isSelected(key)) return true;
      // Fallback for older cart keys: only match if unique fields agree
      const sel = this.selectionForModule(section?.module);
      if (!sel || !item) return false;
      if (section.module === 'flights') {
        const si = sel.item || {};
        return String(si.flight_no || '') === String(item.flight_no || '')
          && String(si.departure_time || '') === String(item.departure_time || '')
          && String(si.arrival_time || '') === String(item.arrival_time || '')
          && String(si.departure_date || '') === String(item.departure_date || '')
          && String(si.supplier || '') === String(item.supplier || '');
      }
      if (section.module === 'rail') {
        const seat = this.selectedRailSeat(item);
        return String(sel.item?.id || '') === String(item.id || '')
          && String(sel.item?.seat_class || '') === this.railSeatCode(seat)
          && String(sel.supplier || '') === String(item.supplier || '');
      }
      if (section.module === 'ferries') {
        const acc = this.selectedFerryAcc(item);
        return String(sel.item?.departure_datetime || '') === String(item.departure_datetime || '')
          && String(sel.item?.company_id || '') === String(item.company_id || '')
          && String(sel.item?.accommodation_id || '') === String(acc?.id || '');
      }
      return String(sel.item?.id || '') === String(item.id || '')
        && String(sel.supplier || '') === String(item.supplier || '');
    },
    moduleComplete(mod) {
      if (this.selectionForModule(mod)) return true;
      if (mod && this.skippedModules && this.skippedModules[mod]) return true;
      // No inventory for this step → auto-skip (do not block the trip)
      return this.moduleIsUnavailable(mod);
    },
    sectionForModule(mod) {
      return (this.sections || []).find((s) => s.module === mod) || null;
    },
    /** Search finished with zero items — selection cannot be required. */
    moduleIsUnavailable(modOrSection) {
      const s = (modOrSection && typeof modOrSection === 'object')
        ? modOrSection
        : this.sectionForModule(modOrSection);
      if (!s) return false;
      if (s.loading) return false;
      if (this.stayNeedsNationality(s)) return false;
      if (this.esimNeedsCountry(s)) return false;
      return !((s.items || []).length);
    },
    /** Has live results and still needs a pick. */
    moduleNeedsSelection(modOrSection) {
      const s = (modOrSection && typeof modOrSection === 'object')
        ? modOrSection
        : this.sectionForModule(modOrSection);
      if (!s || s.loading) return false;
      if (s.module && this.skippedModules && this.skippedModules[s.module]) return false;
      return (s.items || []).length > 0 && !this.selectionForModule(s.module);
    },
    /**
     * "Continue without X" only makes sense when the trip still has another
     * selectable step or at least one cart item — not for a sole empty search.
     */
    canContinueWithoutModule(mod) {
      if (this._bookingItemsPayload().length > 0) return true;
      return (this.sections || []).some(
        (s) => s.module !== mod && this.moduleNeedsSelection(s)
      );
    },
    skipEmptyModule(mod) {
      const s = this.sectionForModule(mod);
      if (!s || !this.moduleIsUnavailable(s)) return;
      this.continueHint = '';
      if (!this.canContinueWithoutModule(mod)) {
        this._toastWarn(
          'No ' + this.moduleLabel(mod).toLowerCase()
          + ' found. Try different dates or search on home.'
        );
        return;
      }
      if (!this.goToNextIncompleteTab(mod) && this.wizardComplete) {
        if (this._bookingItemsPayload().length) {
          this.$nextTick(() => this.drawerContinue());
        }
      }
    },
    goToNextIncompleteTab(currentMod) {
      const mods = (this.sections || []).map((s) => s.module).filter(Boolean);
      const idx = mods.indexOf(currentMod);
      const from = idx >= 0 ? idx + 1 : 0;
      for (let i = from; i < mods.length; i++) {
        if (!this.moduleComplete(mods[i])) {
          this.setDrawerModule(mods[i]);
          return true;
        }
      }
      for (let i = 0; i < from; i++) {
        if (!this.moduleComplete(mods[i])) {
          this.setDrawerModule(mods[i]);
          return true;
        }
      }
      return false;
    },
    get wizardStepsDone() {
      return (this.sections || []).filter((s) => this.moduleComplete(s.module)).length;
    },
    /** Pick one option for this module (replaces any previous). Click again to unselect. */
    selectItem(section, item, iIdx) {
      if (!section || !item) return;
      if (section.module === 'stays') {
        this.toggleStayHotel(section, item);
        return;
      }
      // Same card already in cart → unselect (do not auto-advance)
      if (this.isItemSelected(section, item, iIdx)) {
        this.clearModuleSelection(section.module);
        this.continueHint = '';
        return;
      }
      if (section.module === 'tours') {
        // Ensure prompt traveler counts + live total are on the item before save
        if (item.adults == null || item.adults === '') {
          item.adults = String(this.tourAdultsCount(item, section));
        }
        if (item.children == null || item.children === '') {
          item.children = String(this.tourChildrenCount(item, section));
        }
        item.price = this.tourTotalPrice(item, section);
        this._setModuleSelection(section, item, iIdx);
        const sel = this.selectionForModule('tours');
        if (sel) {
          if (!sel.params || typeof sel.params !== 'object') sel.params = {};
          sel.params.adults = item.adults;
          sel.params.children = item.children;
          sel.price = item.price;
          this._cartSave();
        }
        this.continueHint = '';
        if ((this.sections || []).length === 1) {
          this.$nextTick(() => this.drawerContinue());
          return;
        }
        this.goToNextDrawerTab(section.module);
        if (this.wizardComplete) {
          this.$nextTick(() => this.drawerContinue());
        }
        return;
      }
      this._setModuleSelection(section, item, iIdx);
      this.continueHint = '';
      // Overnight / next-day arrivals: hotel check-in follows flight arrival day
      if (section.module === 'flights') {
        this._syncStayDatesFromSelectedFlight(item);
      }
      // Single-module search → select and go straight to review
      if ((this.sections || []).length === 1) {
        this.$nextTick(() => this.drawerContinue());
        return;
      }
      this.goToNextDrawerTab(section.module);
      // Last module of a multi-step trip → auto continue
      if (this.wizardComplete) {
        this.$nextTick(() => this.drawerContinue());
      }
    },
    clearModuleSelection(mod) {
      if (!mod) return;
      this.selected = (this.selected || []).filter((x) => x.module !== mod);
      if (mod === 'stays') {
        this.stayExpandedId = '';
        this.stayRoomQuantityByKey = {};
      }
      this._cartSave();
    },
    _clearModuleSkip(mod) {
      if (!mod || !this.skippedModules || !this.skippedModules[mod]) return;
      const next = Object.assign({}, this.skippedModules);
      delete next[mod];
      this.skippedModules = next;
    },
    _markModulesSkipped(mods) {
      const next = Object.assign({}, this.skippedModules || {});
      (mods || []).forEach((mod) => {
        if (mod) next[mod] = true;
      });
      this.skippedModules = next;
    },
    /** Button label: Select / Unselect (and module-specific defaults). */
    selectActionLabel(section, item, iIdx, selectText) {
      if (this.isItemSelected(section, item, iIdx)) return 'Unselect';
      return selectText || 'Select';
    },
    _setModuleSelection(section, item, iIdx, extra) {
      const key = this.itemKey(section, item, iIdx);
      const mod = section.module || '';
      let image = item.image || '';
      if (!image && (mod === 'flights' || item.kind === 'flight')) {
        image = this.airlineLogo(item) || '';
      }
      const entry = {
        key,
        module: section.module,
        kind: item.kind || section.module,
        title: item.name || ((item.airlineName || item.airline || 'Flight') + ' ' + (item.flight_no || '')),
        subtitle: this._itemSubtitle(section, item),
        price: this.convertToSessionCurrency(
          (mod === 'visa' && item.is_inquiry_only) ? 0 : item.price,
          item.currency || this.currency
        ),
        currency: this.currency,
        supplier: item.supplier || '',
        image,
        href: this.itemDetailHref(item, section),
        item: JSON.parse(JSON.stringify(item)),
        params: JSON.parse(JSON.stringify(section.params || {})),
        is_inquiry_only: !!(item.is_inquiry_only),
        ...(extra || {})
      };
      // extra.price must also be normalized to session currency (stays passes raw total)
      if (extra && extra.price != null) {
        entry.price = this.convertToSessionCurrency(extra.price, extra.currency || item.currency || this.currency);
        entry.currency = this.currency;
      }
      if (mod === 'visa' && (item.is_inquiry_only || entry.is_inquiry_only)) {
        entry.price = 0;
        entry.is_inquiry_only = true;
      }
      // Keep original amount on item for audit, but cart line uses session currency
      if (entry.item && typeof entry.item === 'object') {
        entry.item.price = entry.price;
        entry.item.currency = entry.currency;
        entry.item.price_original = Number(item.price) || 0;
        entry.item.currency_original = item.currency || this.currency;
        entry.item.is_inquiry_only = !!entry.is_inquiry_only;
      }
      this.selected = this._onePerModule(
        (this.selected || []).filter((x) => x.module !== section.module).concat([entry])
      );
      this._clearModuleSkip(mod);
      this._cartSave();
    },
    /** Switch results tab and always jump scroll to the start of results. */
    setDrawerModule(mod) {
      if (!mod) {
        this.$nextTick(() => this.scrollResultsTop());
        return;
      }
      // Returning to a skipped tab re-requires a selection for that step
      this._clearModuleSkip(mod);
      if (this.drawerModule === mod) {
        this.$nextTick(() => this.scrollResultsTop());
        return;
      }
      this.drawerModule = mod;
      if (mod !== 'stays') {
        this.stayNatOpen = false;
        this.stayNatQuery = '';
      }
      if (mod === 'umrah') {
        const sec = (this.sections || []).find((s) => s.module === 'umrah');
        if (sec) this.syncUmrahFiltersFromSection(sec);
      }
      this.$nextTick(() => this.scrollResultsTop());
    },
    /** Always open the next tab in order after a selection. */
    goToNextDrawerTab(currentMod) {
      const mods = (this.sections || []).map((s) => s.module).filter(Boolean);
      const idx = mods.indexOf(currentMod);
      if (idx < 0 || idx >= mods.length - 1) return false;
      this.setDrawerModule(mods[idx + 1]);
      return true;
    },
    scrollResultsTop() {
      try {
        const panel = this.$refs?.resultsPanel || document.getElementById('ai-trip-results');
        const scroller = this.$refs?.drawerScroll;
        if (scroller) scroller.scrollTop = 0;

        const jump = () => {
          if (!panel) return;
          // Page scrolls the list (drawerScroll has no overflow) — reset to panel top.
          const header = document.querySelector('header.sticky, header.fixed, .navbar, .site-header, #header');
          const hPos = header ? getComputedStyle(header).position : '';
          const offset = (header && (hPos === 'fixed' || hPos === 'sticky'))
            ? (header.offsetHeight || 0) + 8
            : 12;
          const top = Math.max(0, panel.getBoundingClientRect().top + window.pageYOffset - offset);
          window.scrollTo({ top, behavior: 'smooth' });
        };

        // Wait for Alpine x-show tab content to reflow before measuring.
        requestAnimationFrame(() => {
          requestAnimationFrame(jump);
        });
      } catch (e) {}
    },
    /** @deprecated alias — keep callers working */
    advanceToNextModule(currentMod) {
      return this.goToNextDrawerTab(currentMod);
    },
    get wizardComplete() {
      if (!this.sections.length) return this.selectedCount > 0;
      // Every module either selected or empty (skipped)
      return this.sections.every((s) => this.moduleComplete(s.module));
    },
    get nextIncompleteLabel() {
      const s = (this.sections || []).find((x) => this.moduleNeedsSelection(x))
        || (this.sections || []).find((x) => !this.moduleComplete(x.module));
      return s ? this.moduleLabel(s.module) : '';
    },
    hotelCacheKey(hotel) {
      return String(hotel?.id || '') + '|' + String(hotel?.supplier || '');
    },
    stayRoomsFor(hotel) {
      return this.stayRoomsByHotel[this.hotelCacheKey(hotel)] || [];
    },
    stayRoomsErrorFor(hotel) {
      return this.stayRoomsErrorByHotel[this.hotelCacheKey(hotel)] || '';
    },
    isStayExpanded(hotel) {
      return this.stayExpandedId === this.hotelCacheKey(hotel);
    },
    isStayRoomsLoading(hotel) {
      return this.stayRoomsLoadingId === this.hotelCacheKey(hotel);
    },
    /** Full room cards and every rate option, aligned with normal Stay details. */
    stayRoomCards(hotel) {
      const rooms = this.stayRoomsFor(hotel);
      return rooms.map((room, rIdx) => {
        const opts = Array.isArray(room.options) && room.options.length
          ? room.options
          : [{ total_price: room.price || room.total_price || 0, currency: room.currency }];
        const nights = this.stayNightsCount(
          hotel.checkin,
          hotel.checkout,
          hotel.nights
        );
        const rates = opts.map((option, oIdx) => {
          let perNight = Number(option.price_per_night) || 0;
          let total = Number(option.total_price) || 0;
          const hotelTotal = Number(hotel.price) || 0;
          // If only price_per_night is present but it equals the stay total, do not
          // multiply by nights again (that inflated "From" / rate totals).
          if (total <= 0 && perNight > 0) {
            if (nights > 1 && hotelTotal > 0 && Math.abs(perNight - hotelTotal) < 0.02) {
              total = perNight;
              perNight = Math.round((total / nights) * 100) / 100;
            } else {
              total = perNight * nights;
            }
          }
          if (total <= 0) total = Number(room.price || room.total_price || hotel.price) || 0;
          if (perNight <= 0 && total > 0 && nights > 0) {
            perNight = Math.round((total / nights) * 100) / 100;
          } else if (
            perNight > 0
            && total > 0
            && nights > 1
            && Math.abs(perNight - total) < 0.02
          ) {
            perNight = Math.round((total / nights) * 100) / 100;
          }
          return {
            key: (room.room_id || room.room_type_id || rIdx) + '-' + oIdx,
            option,
            oIdx,
            price: total,
            price_per_night: perNight,
            nights,
            currency: option.currency || room.currency || hotel.currency || this.currency,
          };
        });
        return {
          key: String(room.room_id || room.room_type_id || rIdx),
          room,
          rates,
          image: this.roomImage(room) || hotel.image || (this.root + 'uploads/no_img.jpg'),
          label: room.room_name || 'Room',
          amenities: this.stayRoomAmenityLabels(room),
        };
      });
    },
    stayRoomAmenityLabels(room, limit) {
      const raw = Array.isArray(room?.amenities) ? room.amenities : [];
      const labels = raw.map((amenity) => {
        if (typeof amenity === 'string') return amenity.trim();
        return String(amenity?.name || amenity?.title || '').trim();
      }).filter(Boolean);
      return limit == null ? labels : labels.slice(0, Math.max(0, Number(limit) || 0));
    },
    stayHotelAmenityLabels(hotel, limit) {
      const raw = Array.isArray(hotel?.amenities)
        ? hotel.amenities
        : (Array.isArray(hotel?.raw?.amenities) ? hotel.raw.amenities : []);
      const labels = raw.map((amenity) => {
        if (typeof amenity === 'string') return amenity.trim();
        return String(amenity?.name || amenity?.title || amenity?.label || '').trim();
      }).filter(Boolean);
      const n = limit == null ? 4 : Math.max(0, Number(limit) || 0);
      return labels.slice(0, n);
    },
    stayHotelImages(hotel) {
      const imgs = [];
      if (Array.isArray(hotel?.images)) {
        hotel.images.forEach((img) => {
          const s = String(img || '').trim();
          if (s && !imgs.includes(s)) imgs.push(s);
        });
      }
      const main = String(hotel?.image || '').trim();
      if (main && !imgs.includes(main)) imgs.unshift(main);
      return imgs;
    },
    staySelectedRoomNames(sel) {
      const rooms = Array.isArray(sel?.selected_rooms)
        ? sel.selected_rooms
        : (Array.isArray(sel?.item?.selected_rooms) ? sel.item.selected_rooms : []);
      return rooms.map((r) => String(r.room_name || r.name || 'Room').trim()).filter(Boolean);
    },
    stayRoomOptionKey(hotel, room, optIndex) {
      return this.hotelCacheKey(hotel) + '|'
        + String(room?.room_id || room?.room_type_id || '') + '|' + String(optIndex);
    },
    stayRoomOptionQuantity(hotel, room, optIndex, option) {
      const key = this.stayRoomOptionKey(hotel, room, optIndex);
      const selected = this.staySelectedRooms().find((entry) =>
        String(entry.room_id || '') === String(room?.room_id || room?.room_type_id || '')
        && Number(entry.option_index) === Number(optIndex)
      );
      if (selected) return Math.max(0, Number(selected.quantity) || 0);
      if (Object.prototype.hasOwnProperty.call(this.stayRoomQuantityByKey, key)) {
        return Math.max(0, Number(this.stayRoomQuantityByKey[key]) || 0);
      }
      return 0;
    },
    stayRoomQuantityRange(option) {
      const max = Math.max(1, Math.min(20, Number(option?.available_quantity) || 1));
      return Array.from({ length: max + 1 }, (_, idx) => idx);
    },
    stayCurrencySymbol(currency) {
      return String(currency || this.currency || 'USD') + ' ';
    },
    isStayOptionRefundable(option) {
      return Number(option?.refundable) === 1;
    },
    isStayOptionCancellationFree(option) {
      return Number(option?.cancellation_free) === 1;
    },
    isStayHotelbedsMultiRoom(section, hotel) {
      return String(hotel?.supplier || '').toLowerCase() === 'hotelbeds'
        && this.stayRoomsNeeded(section) > 1;
    },
    formatStayOccupancy(occupancy) {
      const adults = Number(occupancy?.adults || 0);
      const children = Number(occupancy?.children || 0);
      const childAges = Array.isArray(occupancy?.child_ages) ? occupancy.child_ages : [];
      const labels = [`${adults} ${adults === 1 ? 'adult' : 'adults'}`];
      if (children > 0) {
        const ageText = childAges.length
          ? ` (age${childAges.length > 1 ? 's' : ''} ${childAges.join(', ')})`
          : '';
        labels.push(`${children} ${children === 1 ? 'child' : 'children'}${ageText}`);
      }
      return labels.join(', ');
    },
    isStayOccupancySelected(hotel, option, occupancyIndex) {
      const sel = this.selectionForModule('stays');
      if (!sel?.item
        || String(sel.item.id || '') !== String(hotel?.id || '')
        || String(sel.item.supplier || '') !== String(hotel?.supplier || '')) {
        return false;
      }
      const rateKey = String(option?.rate_key || '');
      return this.staySelectedRooms().some((entry) =>
        Number(entry.searched_room_index) === Number(occupancyIndex)
        && String(entry.rate_key || entry.room_option?.rate_key || '') === rateKey
      );
    },
    selectStayOccupancyOption(section, hotel, room, option, optIndex, occupancyIndex) {
      if (!(option?.matching_occupancy_indexes || []).includes(occupancyIndex)) {
        this._toastWarn('This rate does not match the selected room occupancy.');
        return;
      }
      const existing = this.selectionForModule('stays');
      let rooms = [];
      const sameHotel = existing
        && String(existing.item?.id || '') === String(hotel.id || '')
        && String(existing.item?.supplier || '') === String(hotel.supplier || '');
      if (sameHotel) {
        rooms = this.staySelectedRooms().map((r) => JSON.parse(JSON.stringify(r)));
      }
      const occIdx = Number(occupancyIndex);
      const matchIdx = rooms.findIndex((r) => Number(r.searched_room_index) === occIdx);
      if (matchIdx >= 0
        && String(rooms[matchIdx].rate_key || rooms[matchIdx].room_option?.rate_key || '')
          === String(option?.rate_key || '')) {
        rooms.splice(matchIdx, 1);
      } else {
        const nights = this.stayNightsCount(hotel.checkin, hotel.checkout, hotel.nights);
        let unitPrice = Number(option.total_price) || 0;
        const perNight = Number(option.price_per_night) || 0;
        if (unitPrice <= 0 && perNight > 0) unitPrice = perNight * nights;
        if (unitPrice <= 0) unitPrice = Number(room.price || room.total_price || hotel.price) || 0;
        if (matchIdx >= 0) rooms.splice(matchIdx, 1);
        rooms.push({
          room_id: room.room_id || room.room_type_id || '',
          room_name: room.room_name || '',
          quantity: 1,
          option_index: optIndex,
          searched_room_index: occIdx,
          rate_key: option.rate_key || '',
          board_name: option.board_name || '',
          breakfast_included: option.breakfast_included,
          refundable: option.refundable,
          total_price: Math.round(unitPrice * 100) / 100,
          unit_total_price: unitPrice,
          currency: option.currency || hotel.currency || this.currency,
          image: this.roomImage(room) || hotel.image || '',
          cancellation_policies: option.cancellation_policies || [],
          room_option: JSON.parse(JSON.stringify(option)),
          room_data: JSON.parse(JSON.stringify(room))
        });
      }
      if (!rooms.length) {
        this.selected = (this.selected || []).filter((x) => x.module !== 'stays');
        this._cartSave();
        return;
      }
      const key = this.hotelCacheKey(hotel);
      const totalPrice = rooms.reduce((sum, r) => sum + (Number(r.total_price) || 0), 0);
      const roomItem = {
        ...hotel,
        kind: 'stay',
        name: hotel.name || 'Stay',
        price: totalPrice,
        currency: rooms[0].currency || hotel.currency || this.currency,
        selected_room: rooms[0],
        selected_rooms: rooms,
        book_hash: this.stayBookHashByHotel[key] || '',
        room_option: rooms[0].room_option || null,
        room_data: rooms[0].room_data || null
      };
      const names = rooms.map((r) => r.room_name || 'Room').filter(Boolean);
      this._setModuleSelection(section, roomItem, 0, {
        title: (hotel.name || 'Stay') + ' · ' + rooms.length + (rooms.length === 1 ? ' room' : ' rooms'),
        subtitle: (hotel.location || '') + (names.length ? ' · ' + names.join(', ') : ''),
        price: totalPrice,
        image: hotel.image || rooms[0].image || '',
        selected_rooms: rooms
      });
    },
    setStayRoomOptionQuantity(section, hotel, room, option, optIndex, value) {
      const key = this.stayRoomOptionKey(hotel, room, optIndex);
      const max = Math.max(1, Number(option?.available_quantity) || 1);
      const quantity = Math.min(max, Math.max(0, Number(value) || 0));
      this.stayRoomQuantityByKey = { ...this.stayRoomQuantityByKey, [key]: quantity };
      if (quantity === 0) {
        if (this.isStayRoomSelected(hotel, room, optIndex)) {
          this.selectStayRoom(section, hotel, room, option, optIndex);
        }
        return;
      }
      if (this.isStayRoomSelected(hotel, room, optIndex)) {
        this.selectStayRoom(section, hotel, room, option, optIndex);
        this.selectStayRoom(section, hotel, room, option, optIndex);
      }
    },
    async toggleStayHotel(section, hotel) {
      const key = this.hotelCacheKey(hotel);
      if (this.stayExpandedId === key) {
        this.stayExpandedId = '';
        return;
      }
      this.stayExpandedId = key;
      if (this.stayRoomsByHotel[key]) return;
      await this.loadStayRooms(section, hotel);
    },
    async loadStayRooms(section, hotel) {
      const key = this.hotelCacheKey(hotel);
      this.stayRoomsLoadingId = key;
      this.stayRoomsErrorByHotel = { ...this.stayRoomsErrorByHotel, [key]: '' };
      try {
        const sup = String(hotel.supplier || '').toLowerCase();
        const params = section.params || {};
        const nationality = String(hotel.nationality || params.nationality || this.nationalityIso || '').trim().toUpperCase();
        if (!/^[A-Z]{2}$/.test(nationality)) {
          this.stayRoomsErrorByHotel = {
            ...this.stayRoomsErrorByHotel,
            [key]: 'Select your nationality to see rooms available to you.'
          };
          this.stayRoomsByHotel = { ...this.stayRoomsByHotel, [key]: [] };
          if (this.stayRoomsLoadingId === key) this.stayRoomsLoadingId = '';
          return;
        }
        const payload = {
          hotel_id: hotel.id,
          supplier: hotel.supplier,
          checkin: hotel.checkin || params.checkin || '',
          checkout: hotel.checkout || params.checkout || '',
          destination: hotel.location || params.destination || '',
          adults: parseInt(hotel.adults || params.adults || 1, 10) || 1,
          children: parseInt(hotel.children || params.children || 0, 10) || 0,
          rooms: parseInt(hotel.rooms || params.rooms || 1, 10) || 1,
          nationality,
          currency: hotel.currency || params.currency || this.currency
        };
        const roomsDataRaw = params.rooms_data;
        if (roomsDataRaw != null && roomsDataRaw !== '') {
          payload.rooms_data = typeof roomsDataRaw === 'string'
            ? roomsDataRaw
            : JSON.stringify(roomsDataRaw);
        }
        if (hotel.chain && hotel.chain !== '_') payload.hotel_chain = hotel.chain;
        const r = await fetch(this.root + 'modules/stays/' + encodeURIComponent(sup) + '/rooms', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await r.json().catch(() => null);
        if (!data || !data.success) {
          this.stayRoomsErrorByHotel = {
            ...this.stayRoomsErrorByHotel,
            [key]: (data && data.message) || 'Could not load rooms for this hotel.'
          };
          this.stayRoomsByHotel = { ...this.stayRoomsByHotel, [key]: [] };
        } else {
          const rooms = Array.isArray(data.data?.rooms) ? data.data.rooms : [];
          this.stayRoomsByHotel = { ...this.stayRoomsByHotel, [key]: rooms };
          this.stayBookHashByHotel = {
            ...this.stayBookHashByHotel,
            [key]: data.data?.book_hash || ''
          };
          if (!rooms.length) {
            this.stayRoomsErrorByHotel = {
              ...this.stayRoomsErrorByHotel,
              [key]: 'No rooms available for these dates.'
            };
          }
        }
      } catch (e) {
        this.stayRoomsErrorByHotel = {
          ...this.stayRoomsErrorByHotel,
          [key]: 'Room search failed. Please try another hotel.'
        };
        this.stayRoomsByHotel = { ...this.stayRoomsByHotel, [key]: [] };
      } finally {
        if (this.stayRoomsLoadingId === key) this.stayRoomsLoadingId = '';
      }
    },
    roomImages(room) {
      if (!room) return [];
      if (Array.isArray(room.room_images) && room.room_images.length) return room.room_images.filter(Boolean);
      if (room.room_main_image) return [room.room_main_image];
      if (room.image) return [room.image];
      return [];
    },
    roomImage(room) {
      const imgs = this.roomImages(room);
      return imgs[0] || '';
    },
    staySelectedRooms() {
      const sel = this.selectionForModule('stays');
      if (!sel) return [];
      if (Array.isArray(sel.selected_rooms) && sel.selected_rooms.length) return sel.selected_rooms;
      if (sel.item?.selected_room) return [sel.item.selected_room];
      return [];
    },
    stayRoomsNeeded(section) {
      const n = parseInt(section?.params?.rooms || 1, 10);
      return Number.isFinite(n) && n > 0 ? n : 1;
    },
    staySelectedCount() {
      const staySection = (this.sections || []).find((s) => s.module === 'stays');
      const sel = this.selectionForModule('stays');
      if (staySection && sel?.item && this.isStayHotelbedsMultiRoom(staySection, sel.item)) {
        return this.staySelectedRooms().filter((r) => r.searched_room_index != null).length;
      }
      return this.staySelectedRooms().reduce(
        (sum, room) => sum + Math.max(0, Number(room.quantity) || 0),
        0
      );
    },
    staySelectedCountForHotel(hotel) {
      const selection = this.selectionForModule('stays');
      if (!selection?.item
        || String(selection.item.id || '') !== String(hotel?.id || '')
        || String(selection.item.supplier || '') !== String(hotel?.supplier || '')) {
        return 0;
      }
      return this.staySelectedCount();
    },
    isStayRoomSelected(hotel, room, oIdx) {
      const sel = this.selectionForModule('stays');
      if (!sel || !sel.item) return false;
      if (String(sel.item.id || '') !== String(hotel.id || '')
        || String(sel.item.supplier || '') !== String(hotel.supplier || '')) {
        return false;
      }
      const rid = String(room.room_id || room.room_type_id || '');
      return this.staySelectedRooms().some((sr) =>
        String(sr.room_id || '') === rid && Number(sr.option_index) === Number(oIdx)
      );
    },
    /** Toggle room into multi-select (same hotel). Does not auto-advance. */
    selectStayRoom(section, hotel, room, option, optIndex) {
      if (!section || !hotel || !room || !option) return;
      if (this.isStayHotelbedsMultiRoom(section, hotel)) return;
      const key = this.hotelCacheKey(hotel);
      const nights = this.stayNightsCount(hotel.checkin, hotel.checkout, hotel.nights);
      const perNight = Number(option.price_per_night) || 0;
      let unitPrice = Number(option.total_price) || 0;
      if (unitPrice <= 0 && perNight > 0) {
        const hotelTotal = Number(hotel.price) || 0;
        // price_per_night sometimes already is the stay total — don't multiply again
        if (nights > 1 && hotelTotal > 0 && Math.abs(perNight - hotelTotal) < 0.02) {
          unitPrice = perNight;
        } else {
          unitPrice = perNight * nights;
        }
      }
      if (unitPrice <= 0) {
        unitPrice = Number(room.price || room.total_price || hotel.price) || 0;
      }
      let quantity = this.stayRoomOptionQuantity(hotel, room, optIndex, option);
      const matchIdxPre = this.staySelectedRooms().findIndex((r) =>
        String(r.room_id || '') === String(room.room_id || room.room_type_id || '')
        && Number(r.option_index) === Number(optIndex)
      );
      if (matchIdxPre >= 0) {
        this.stayRoomQuantityByKey = {
          ...this.stayRoomQuantityByKey,
          [this.stayRoomOptionKey(hotel, room, optIndex)]: 0
        };
      } else if (quantity <= 0) {
        quantity = 1;
        this.stayRoomQuantityByKey = {
          ...this.stayRoomQuantityByKey,
          [this.stayRoomOptionKey(hotel, room, optIndex)]: quantity
        };
      }
      const price = Math.round((unitPrice * quantity + Number.EPSILON) * 100) / 100;
      const roomSel = {
        room_id: room.room_id || room.room_type_id || '',
        room_name: room.room_name || '',
        quantity,
        option_index: optIndex,
        rate_key: option.rate_key || '',
        board_name: option.board_name || '',
        breakfast_included: option.breakfast_included,
        refundable: option.refundable,
        total_price: price,
        unit_total_price: unitPrice,
        currency: option.currency || hotel.currency || this.currency,
        image: this.roomImage(room) || hotel.image || '',
        cancellation_policies: option.cancellation_policies || [],
        room_option: JSON.parse(JSON.stringify(option)),
        room_data: JSON.parse(JSON.stringify(room))
      };

      const existing = this.selectionForModule('stays');
      let rooms = [];
      const sameHotel = existing
        && String(existing.item?.id || '') === String(hotel.id || '')
        && String(existing.item?.supplier || '') === String(hotel.supplier || '');
      if (sameHotel) {
        rooms = this.staySelectedRooms().map((r) => JSON.parse(JSON.stringify(r)));
      }

      const rid = String(roomSel.room_id);
      const matchIdx = rooms.findIndex((r) =>
        String(r.room_id || '') === rid && Number(r.option_index) === Number(optIndex)
      );
      if (matchIdx >= 0) rooms.splice(matchIdx, 1);
      else rooms.push(roomSel);

      if (!rooms.length) {
        this.selected = (this.selected || []).filter((x) => x.module !== 'stays');
        this._cartSave();
        return;
      }

      const totalPrice = rooms.reduce((sum, r) => sum + (Number(r.total_price) || 0), 0);
      const n = rooms.reduce((sum, selectedRoom) =>
        sum + Math.max(1, Number(selectedRoom.quantity) || 1), 0);
      const roomItem = {
        ...hotel,
        kind: 'stay',
        name: hotel.name || 'Stay',
        price: totalPrice,
        currency: rooms[0].currency || hotel.currency || this.currency,
        selected_room: rooms[0],
        selected_rooms: rooms,
        book_hash: this.stayBookHashByHotel[key] || '',
        room_option: rooms[0].room_option || null,
        room_data: rooms[0].room_data || null
      };
      const names = rooms.map((r) => r.room_name || 'Room').filter(Boolean);
      this._setModuleSelection(section, roomItem, 0, {
        title: (hotel.name || 'Stay') + ' · ' + n + (n === 1 ? ' room' : ' rooms'),
        subtitle: (hotel.location || '') + (names.length ? ' · ' + names.join(', ') : ''),
        price: totalPrice,
        image: hotel.image || rooms[0].image || '',
        selected_rooms: rooms
      });
      this.continueHint = '';
      // Hotels allow multi-room picks — do not auto-jump to summary.
      // User finishes via the fixed Continue bar when ready.
    },
    _toastWarn(message) {
      if (typeof vt !== 'undefined' && typeof vt.warn === 'function') {
        vt.warn(message);
        return;
      }
      if (typeof vt !== 'undefined' && typeof vt.error === 'function') {
        vt.error(message);
        return;
      }
      try { alert(message); } catch (e) {}
    },
    _stayRoomCountOk() {
      const staySection = (this.sections || []).find((s) =>
        s.module === 'stays' && Array.isArray(s.items) && s.items.length
      );
      if (!staySection || !this.selectionForModule('stays')) return true;
      return this.staySelectedCount() === this.stayRoomsNeeded(staySection);
    },
    _openFinalSummary() {
      this.showFinalSummary = true;
      this.$nextTick(() => {
        try {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (e) {}
      });

      if (typeof confetti === 'function') {
        const duration = 1000;
        const end = Date.now() + duration;

        const frame = () => {
          confetti({
            particleCount: 2,
            angle: 60,
            spread: 15,
            origin: { x: 0 },
            colors: ['#0058E6', '#3D7EF0', '#E8F0FC']
          });
          confetti({
            particleCount: 2,
            angle: 120,
            spread: 15,
            origin: { x: 1 },
            colors: ['#0058E6', '#3D7EF0', '#E8F0FC']
          });

          if (Date.now() < end) {
            requestAnimationFrame(frame);
          }
        };
        frame();
      }
    },
    /** Continue → review summary (requires every module with results to be selected). */
    async drawerContinue() {
      if (this.bookingRedirecting) return;
      this.continueHint = '';

      if (!this.wizardComplete) {
        const next = (this.sections || []).find((s) => this.moduleNeedsSelection(s))
          || (this.sections || []).find((s) => !this.moduleComplete(s.module));
        const label = next ? this.moduleLabel(next.module) : 'all steps';
        this._toastWarn('Please select a ' + label.toLowerCase() + ' before continuing.');
        if (next && next.module) {
          this.setDrawerModule(next.module);
        }
        return;
      }

      if (!this._stayRoomCountOk()) {
        const staySection = (this.sections || []).find((s) => s.module === 'stays');
        const required = staySection ? this.stayRoomsNeeded(staySection) : 1;
        this._toastWarn('Please select exactly ' + required
          + (required === 1 ? ' room' : ' rooms') + ' for this stay.');
        this.setDrawerModule('stays');
        return;
      }
      if (!this._bookingItemsPayload().length) {
        this._toastWarn('Please select at least one item to continue.');
        return;
      }
      this._openFinalSummary();
    },
    /** Skip the current (or next incomplete) module and move to the next step. */
    drawerSkipModule() {
      if (this.bookingRedirecting) return;
      this.continueHint = '';

      if (!this._bookingItemsPayload().length) {
        this._toastWarn('Please select at least one item before skipping.');
        return;
      }

      let toSkip = '';
      if (this.drawerModule && this.moduleNeedsSelection(this.drawerModule)) {
        toSkip = this.drawerModule;
      } else {
        const next = (this.sections || []).find((s) => this.moduleNeedsSelection(s));
        toSkip = next ? next.module : '';
      }
      if (!toSkip) {
        if (this.wizardComplete) {
          if (!this._stayRoomCountOk()) {
            this.setDrawerModule('stays');
            this._toastWarn('Please finish your stay room selection first.');
            return;
          }
          this._openFinalSummary();
        }
        return;
      }

      // If skipping stays after a partial room pick, require rooms to match or clear stay
      if (toSkip === 'stays' && this.selectionForModule('stays') && !this._stayRoomCountOk()) {
        this._toastWarn('Please finish selecting rooms for this stay, or unselect the hotel first.');
        this.setDrawerModule('stays');
        return;
      }

      this._markModulesSkipped([toSkip]);

      if (this.goToNextIncompleteTab(toSkip)) {
        return;
      }

      if (!this._stayRoomCountOk()) {
        this.setDrawerModule('stays');
        this._toastWarn('Please finish your stay room selection first.');
        return;
      }
      this._openFinalSummary();
    },
    get drawerContinueLabel() {
      if (this.bookingRedirecting) return 'Preparing summary…';
      if (!this.wizardComplete && this.nextIncompleteLabel) {
        return 'Select ' + this.nextIncompleteLabel;
      }
      return 'Continue';
    },
    get showDrawerContinue() {
      // Show bar once any pick exists so user sees progress + validation
      return this.selectedCount > 0;
    },
    get showSkipModule() {
      return this.selectedCount > 0
        && !this.bookingRedirecting
        && (this.sections || []).some((s) => this.moduleNeedsSelection(s));
    },
    get canContinueToSummary() {
      return this.wizardComplete && this._bookingItemsPayload().length > 0 && !this.bookingRedirecting;
    },
    removeSelected(key) {
      this.selected = this.selected.filter((x) => x.key !== key);
      this._cartSave();
    },
    clearSelected() {
      this.selected = [];
      this.skippedModules = {};
      this.stayExpandedId = '';
      this.stayRoomsByHotel = {};
      this.stayRoomsLoadingId = '';
      this.stayRoomsErrorByHotel = {};
      this.stayBookHashByHotel = {};
      this.stayRoomQuantityByKey = {};
      this._cartSave();
      this._ssRemove(RESUME_KEY);
    },
    _itemSubtitle(section, item) {
      if (section.module === 'flights') {
        if (item.isMultiCity || item.raw?.isMultiCity || item.raw?.type === 'multicity'
          || (section.params && section.params.type === 'multicity')) {
          const slices = this.flightMultiCitySlices(item);
          if (slices.length) {
            const bits = slices.map((slice) => {
              const s = this.flightSliceSummary(slice);
              return (s.first?.departure_code || '') + '→' + (s.last?.arrival_code || '');
            }).filter(Boolean);
            return 'Multi-city · ' + bits.join(' · ');
          }
          return 'Multi-city · ' + (item.departure_code || '') + ' → ' + (item.arrival_code || '');
        }
        const ret = item.returnFlight;
        if (ret || item.isRoundTrip || (section.params && section.params.type === 'return')) {
          const out = (item.departure_code || '') + ' → ' + (item.arrival_code || '');
          const back = ret
            ? ((ret.departure_code || item.arrival_code || '') + ' → ' + (ret.arrival_code || item.departure_code || ''))
            : 'return';
          const dates = [
            item.departure_date || section.params?.departure_date || '',
            ret?.departure_date || section.params?.return_date || ''
          ].filter(Boolean).join(' · ');
          return 'Round-trip · ' + out + ' / ' + back + (dates ? ' · ' + dates : '');
        }
        return (item.departure_code || '') + ' → ' + (item.arrival_code || '') +
          (item.departure_date ? ' · ' + item.departure_date : '');
      }
      if (section.module === 'cars') {
        const pickup = item.pickup_location || section.params?.pickup_location || '';
        const dropoff = item.dropoff_location || section.params?.dropoff_location || pickup;
        const dates = [
          section.params?.pickup_date || '',
          section.params?.return_date || section.params?.dropoff_date || ''
        ].filter(Boolean).join(' → ');
        const bits = [];
        if (pickup) bits.push(pickup === dropoff ? pickup : (pickup + ' → ' + dropoff));
        if (dates) bits.push(dates);
        if (item.vendor) bits.push(item.vendor);
        return bits.join(' · ') || 'Car rental';
      }
      if (section.module === 'bus') {
        const bits = [];
        const route = (item.origin || section.params?.origin || '')
          + ((item.origin || section.params?.origin) && (item.destination || section.params?.destination) ? ' → ' : '')
          + (item.destination || section.params?.destination || '');
        if (route) bits.push(route);
        const busDate = this.busTravelDate(item, section);
        if (busDate) bits.push(busDate);
        if (item.departure_time) bits.push(item.departure_time);
        if (item.operator) bits.push(item.operator);
        if (item.return_trip) bits.push('Round-trip');
        return bits.join(' · ') || 'Bus';
      }
      if (section.module === 'esim') {
        const bits = [];
        if (item.data_limit) bits.push(item.data_limit);
        if (item.duration) bits.push(item.duration);
        if (item.package_type) bits.push(String(item.package_type));
        if (item.country || section.params?.country_name) {
          bits.push(item.country || section.params.country_name);
        }
        return bits.join(' · ') || 'eSIM';
      }
      if (section.module === 'visa') {
        const bits = [];
        const from = item.from_country_name || item.from_country || section.params?.from_country_name || '';
        const to = item.to_country_name || item.to_country || section.params?.to_country_name || '';
        if (from && to) bits.push(from + ' → ' + to);
        if (item.visa_type_name || item.visa_type) bits.push(item.visa_type_name || item.visa_type);
        if (item.processing_speed_name || item.processing_speed) bits.push(item.processing_speed_name || item.processing_speed);
        if (item.entry_date) bits.push(item.entry_date);
        if (item.is_inquiry_only) bits.push('Inquiry');
        return bits.join(' · ') || 'Visa application';
      }
      if (section.module === 'umrah') {
        const bits = [];
        if (item.location || section.params?.destination) {
          const dest = item.location || section.params.destination;
          if (dest && String(dest).toLowerCase() !== 'any') bits.push(dest);
        }
        if (item.days) bits.push(item.days + ' days');
        if (item.umrah_type) bits.push(item.umrah_type);
        if (item.start_date || section.params?.start_date) bits.push(item.start_date || section.params.start_date);
        const pax = this.umrahTravelerLabel(item, section);
        if (pax) bits.push(pax);
        return bits.join(' · ') || 'Umrah package';
      }
      return item.location || section.module || '';
    },
    get selectedCount() {
      return (this.selected || []).length;
    },
    convertToSessionCurrency(amount, fromCurrency) {
      const n = Number(amount) || 0;
      const from = String(fromCurrency || this.currency || 'USD').toUpperCase();
      const to = String(this.currency || 'USD').toUpperCase();
      if (!n || from === to) return n;
      const rates = this.currencyRates || {};
      const fromRate = Number(rates[from]) || 0;
      const toRate = Number(rates[to]) || 0;
      if (fromRate <= 0 || toRate <= 0) return n;
      return Math.round(((n * (toRate / fromRate)) + Number.EPSILON) * 100) / 100;
    },
    isVisaSelection(sel) {
      const mod = String(sel?.module || sel?.kind || '').toLowerCase();
      return mod === 'visa' || mod === 'visas';
    },
    /** Online-payable trip total — visa is never charged online (priced catalog or inquiry). */
    get selectedPayableTotal() {
      const sum = (this.selected || []).reduce((s, x) => {
        if (this.isVisaSelection(x)) return s;
        return s + this.convertToSessionCurrency(x.price, x.currency || this.currency);
      }, 0);
      return Math.round((sum + Number.EPSILON) * 100) / 100;
    },
    get selectedTotal() {
      // Keep alias for older templates; payable is the meaningful checkout total.
      return this.selectedPayableTotal;
    },
    get hasVisaInquiryInCart() {
      return (this.selected || []).some((x) => {
        if (!this.isVisaSelection(x)) return false;
        const item = x.item || {};
        return !!(x.is_inquiry_only || item.is_inquiry_only || !(Number(x.price) > 0));
      });
    },
    get hasVisaInCart() {
      return (this.selected || []).some((x) => this.isVisaSelection(x));
    },
    normalizeCartCurrencies() {
      let changed = false;
      (this.selected || []).forEach((sel) => {
        if (!sel || typeof sel !== 'object') return;
        if (this.isVisaSelection(sel) && (sel.is_inquiry_only || sel.item?.is_inquiry_only)) {
          if (Number(sel.price) !== 0) {
            sel.price = 0;
            if (sel.item) sel.item.price = 0;
            changed = true;
          }
        }
        const from = String(sel.currency || this.currency || 'USD').toUpperCase();
        const to = String(this.currency || 'USD').toUpperCase();
        if (from !== to && Number(sel.price) > 0) {
          sel.price = this.convertToSessionCurrency(sel.price, from);
          sel.currency = to;
          if (sel.item && typeof sel.item === 'object') {
            sel.item.price = sel.price;
            sel.item.currency = to;
          }
          changed = true;
        }
      });
      if (changed) this._cartSave();
    },
    formatMoney(value) {
      const n = Number(value);
      if (!Number.isFinite(n)) return '—';
      return (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);
    },

    // ── Filters (flights / hotels) ───────────────────────────────
    resetFlightFilters() {
      this.flightFilters = {
        stops: [],
        airlines: [],
        priceMin: null,
        priceMax: null,
        flightNumber: '',
        sort: 'price_low',
      };
    },
    resetHotelFilters() {
      this.hotelFilters = {
        stars: [],
        nameSearch: '',
        priceMin: null,
        priceMax: null,
        sort: 'price_low',
      };
    },
    resetTourFilters() {
      this.tourFilters = {
        stars: [],
        nameSearch: '',
        priceMin: null,
        priceMax: null,
        tourType: '',
        sort: 'price_low',
      };
    },
    resetCarFilters() {
      this.carFilters = {
        nameSearch: '',
        priceMin: null,
        priceMax: null,
        carTypes: [],
        transmission: [],
        suppliers: [],
        features: [],
        passengers: [],
        sort: 'price_low',
      };
    },
    resetBusFilters() {
      this.busFilters = {
        nameSearch: '',
        priceMin: null,
        priceMax: null,
        operators: [],
        busTypes: [],
        seatClasses: [],
        timeSlots: [],
        amenities: [],
        refundableOnly: false,
        sort: 'price_low',
      };
    },
    resetRailFilters() {
      this.railFilters = {
        nameSearch: '',
        priceMin: null,
        priceMax: null,
        seatClasses: [],
        timeSlots: [],
        sort: 'price_low',
      };
    },
    resetEsimFilters() {
      this.esimFilters = {
        nameSearch: '',
        duration: '',
        dataLimit: '',
        price: '',
        packageTypes: [],
        sort: 'price_low',
      };
    },
    resetUmrahFilters() {
      this.umrahFilters = {
        destination: '',
        duration: '',
        umrahType: '',
        services: [],
        nameSearch: '',
        sort: 'price_low',
      };
    },
    syncUmrahFiltersFromSection(section) {
      const p = (section && section.params) || {};
      const dest = String(p.destination || '').trim();
      const dur = String(p.duration || '').trim();
      const typ = String(p.umrah_type || '').trim();
      const svcRaw = String(p.services || '').trim();
      const services = (!svcRaw || svcRaw.toLowerCase() === 'any')
        ? []
        : svcRaw.split(',').map((x) => String(x).trim()).filter(Boolean);
      this.umrahFilters = {
        ...(this.umrahFilters || {}),
        destination: (!dest || dest.toLowerCase() === 'any') ? '' : dest,
        duration: (!dur || dur.toLowerCase() === 'any') ? '' : dur,
        umrahType: (!typ || typ.toLowerCase() === 'any') ? '' : typ,
        services,
        nameSearch: this.umrahFilters?.nameSearch || '',
        sort: this.umrahFilters?.sort || 'price_low',
      };
      this.mergeUmrahLocationsFromItems(section?.items || []);
    },
    /** Location filter choices (catalog labels with a real value). */
    umrahLocationChoices() {
      return (this.umrahLocationOptions || []).filter((o) => o && String(o.value || '').trim() !== '');
    },
    /** Fill Location dropdown from package cards when cfg options were empty. */
    mergeUmrahLocationsFromItems(items) {
      const existing = Array.isArray(this.umrahLocationOptions) ? this.umrahLocationOptions : [];
      const seen = {};
      const out = [];
      const push = (value, name) => {
        const v = String(value || '').trim();
        if (!v || v.toLowerCase() === 'any') return;
        const key = v.toLowerCase();
        if (seen[key]) return;
        seen[key] = true;
        out.push({ value: v, name: name || v });
      };
      existing.forEach((opt) => {
        if (!opt) return;
        if (!String(opt.value || '').trim()) {
          out.unshift({ value: '', name: opt.name || 'Any location' });
          return;
        }
        push(opt.value, opt.name);
      });
      (items || []).forEach((it) => push(it?.location || it?.raw?.location || ''));
      if (!out.some((o) => !String(o.value || '').trim())) {
        out.unshift({ value: '', name: 'Any location' });
      }
      out.sort((a, b) => {
        if (!a.value) return -1;
        if (!b.value) return 1;
        return String(a.name).localeCompare(String(b.name));
      });
      this.umrahLocationOptions = out;
    },
    toggleUmrahService(id) {
      const sid = String(id || '');
      if (!sid) return;
      const f = this.umrahFilters || {};
      const arr = Array.isArray(f.services) ? [...f.services] : [];
      const i = arr.indexOf(sid);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(sid);
      this.umrahFilters = { ...f, services: arr };
    },
    async applyUmrahFilters(section) {
      if (!section || section.module !== 'umrah') return;
      const f = this.umrahFilters || {};
      const services = Array.isArray(f.services) ? f.services.filter(Boolean) : [];
      section.params = {
        ...(section.params || {}),
        destination: f.destination ? String(f.destination) : 'any',
        duration: f.duration ? String(f.duration) : 'any',
        umrah_type: f.umrahType ? String(f.umrahType) : 'any',
        services: services.length ? services.join(',') : 'any',
      };
      this.umrahRefreshing = true;
      section.loading = true;
      section.error = '';
      section.emptyMessage = '';
      section.emptyDetail = '';
      try {
        const packed = await this._umrah(section);
        section.items = packed.items || [];
        this.mergeUmrahLocationsFromItems(section.items);
        if (!section.items.length) this._setEmptyState(section);
        // Drop selection if the selected package disappeared after refine
        const sel = (this.selected || []).find((x) => x.module === 'umrah');
        if (sel && sel.item) {
          const sid = String(sel.item.umrah_id || sel.item.id || '');
          const still = (section.items || []).some(
            (it) => String(it.umrah_id || it.id || '') === sid
          );
          if (!still) {
            this.selected = (this.selected || []).filter((x) => x.module !== 'umrah');
          }
        }
      } catch (e) {
        section.error = 'Something went wrong';
        section.emptyMessage = 'Something went wrong while searching';
        section.emptyDetail = 'Please try again in a moment.';
      } finally {
        section.loading = false;
        this.umrahRefreshing = false;
      }
    },
    async clearUmrahFilters(section) {
      this.resetUmrahFilters();
      if (section) await this.applyUmrahFilters(section);
    },
    toggleEsimPackageType(type) {
      const t = String(type || '').toLowerCase();
      if (!t) return;
      const f = this.esimFilters || {};
      const arr = Array.isArray(f.packageTypes) ? [...f.packageTypes] : [];
      const i = arr.indexOf(t);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(t);
      this.esimFilters = { ...f, packageTypes: arr };
    },
    tourTypeFilterLabel() {
      const v = String(this.tourFilters?.tourType || '');
      const opt = (this.tourTypeOptions || []).find((t) => String(t.value) === v);
      return opt?.name || 'Any Type';
    },
    toggleFlightStop(stop) {
      const n = Number(stop);
      const arr = this.flightFilters.stops || [];
      const i = arr.indexOf(n);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(n);
      this.flightFilters.stops = [...arr];
    },
    toggleFlightAirline(code) {
      const c = String(code || '');
      if (!c) return;
      const arr = this.flightFilters.airlines || [];
      const i = arr.indexOf(c);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(c);
      this.flightFilters.airlines = [...arr];
    },
    toggleHotelStar(star) {
      const n = Number(star);
      const arr = this.hotelFilters.stars || [];
      const i = arr.indexOf(n);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(n);
      this.hotelFilters.stars = [...arr];
    },
    toggleTourStar(star) {
      const n = Number(star);
      const arr = this.tourFilters.stars || [];
      const i = arr.indexOf(n);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(n);
      this.tourFilters.stars = [...arr];
    },
    toggleCarFilter(key, value) {
      const f = this.carFilters || {};
      const arr = Array.isArray(f[key]) ? [...f[key]] : [];
      const raw = (key === 'passengers') ? Number(value) : String(value);
      const i = arr.findIndex((x) => String(x) === String(raw));
      if (i >= 0) arr.splice(i, 1);
      else arr.push(raw);
      this.carFilters = { ...f, [key]: arr };
    },
    toggleBusFilter(key, value) {
      const f = this.busFilters || {};
      const arr = Array.isArray(f[key]) ? [...f[key]] : [];
      const raw = String(value);
      const i = arr.indexOf(raw);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(raw);
      this.busFilters = { ...f, [key]: arr };
    },
    toggleRailFilter(key, value) {
      const f = this.railFilters || {};
      const arr = Array.isArray(f[key]) ? [...f[key]] : [];
      const raw = String(value);
      const i = arr.indexOf(raw);
      if (i >= 0) arr.splice(i, 1);
      else arr.push(raw);
      this.railFilters = { ...f, [key]: arr };
    },
    carSupplierOptions(section) {
      const set = new Set();
      (section?.items || []).forEach((it) => {
        const s = String(it.supplier || '').trim();
        if (s) set.add(s);
      });
      return Array.from(set).sort((a, b) => a.localeCompare(b));
    },
    flightAirlineOptions(section) {
      const map = {};
      (section?.items || []).forEach((it) => {
        const code = String(it.airline || it.img || '').toUpperCase();
        if (!code) return;
        if (!map[code]) {
          map[code] = {
            code,
            name: it.airlineName || it.airline || code,
            count: 0,
          };
        }
        map[code].count += 1;
      });
      return Object.values(map).sort((a, b) => a.name.localeCompare(b.name));
    },
    sectionPriceBounds(section) {
      const prices = (section?.items || [])
        .map((it) => Number(it.price) || 0)
        .filter((p) => p > 0);
      if (!prices.length) return { min: 0, max: 0 };
      return {
        min: Math.floor(Math.min(...prices)),
        max: Math.ceil(Math.max(...prices)),
      };
    },
    filteredSectionItems(section) {
      if (!section) return [];
      const items = Array.isArray(section.items) ? section.items.slice() : [];
      if (section.module === 'flights') return this._filterFlights(items);
      if (section.module === 'stays') return this._filterHotels(items);
      if (section.module === 'tours') return this._filterTours(items);
      if (section.module === 'cars') return this._filterCars(items);
      if (section.module === 'bus') return this._filterBuses(items);
      if (section.module === 'rail') return this._filterRails(items);
      if (section.module === 'esim') return this._filterEsims(items);
      if (section.module === 'umrah') return this._filterUmrahs(items);
      return items;
    },
    _filterFlights(items) {
      const f = this.flightFilters || {};
      let list = items.filter((it) => {
        const stops = Math.min(2, Math.max(0, Number(it.stops) || 0));
        if ((f.stops || []).length && !f.stops.includes(stops)) return false;
        if ((f.airlines || []).length) {
          const code = String(it.airline || it.img || '').toUpperCase();
          if (!f.airlines.includes(code)) return false;
        }
        const price = Number(it.price) || 0;
        if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
        if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
        const q = String(f.flightNumber || '').trim().toLowerCase();
        if (q) {
          const no = String(it.flight_no || '').toLowerCase();
          if (!no.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'duration') {
          return this._durationMinutes(a.duration_time) - this._durationMinutes(b.duration_time);
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    _filterHotels(items) {
      const f = this.hotelFilters || {};
      let list = items.filter((it) => {
        if ((f.stars || []).length) {
          const stars = Math.round(Number(it.stars || it.star_rating || 0));
          if (!f.stars.includes(stars)) return false;
        }
        const price = Number(it.price) || 0;
        if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
        if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const name = String(it.name || '').toLowerCase();
          if (!name.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'stars') {
          return (Number(b.stars || b.star_rating) || 0) - (Number(a.stars || a.star_rating) || 0);
        }
        if (sort === 'rating') {
          return (Number(b.rating || b.guest_rating) || 0) - (Number(a.rating || a.guest_rating) || 0);
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    _filterCars(items) {
      const f = this.carFilters || {};
      let list = items.filter((it) => {
        const price = Number(it.price) || 0;
        if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
        if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;

        if ((f.carTypes || []).length) {
          const cat = String(it.category || it.car_type || '').toLowerCase();
          if (!f.carTypes.some((t) => cat.includes(String(t).toLowerCase()))) return false;
        }

        if ((f.transmission || []).length) {
          const trans = String(it.transmission || '').toLowerCase();
          if (!f.transmission.some((t) => trans.includes(String(t).toLowerCase()))) return false;
        }

        if ((f.suppliers || []).length) {
          const sup = String(it.supplier || '');
          if (!f.suppliers.includes(sup)) return false;
        }

        if ((f.features || []).length) {
          if (f.features.includes('unlimited_mileage') && !it.unlimited_mileage) return false;
          if (f.features.includes('air_conditioning') && !it.air_conditioning) return false;
          if (f.features.includes('free_cancellation') && !it.free_cancellation) return false;
        }

        if ((f.passengers || []).length) {
          const seats = Number(it.passengers) || 0;
          if (!f.passengers.some((cap) => seats >= Number(cap))) return false;
        }

        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const name = String(it.name || '').toLowerCase();
          const vendor = String(it.vendor || '').toLowerCase();
          const cat = String(it.category || '').toLowerCase();
          if (!name.includes(q) && !vendor.includes(q) && !cat.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'name_asc') return String(a.name || '').localeCompare(String(b.name || ''));
        if (sort === 'name_desc') return String(b.name || '').localeCompare(String(a.name || ''));
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    _busTimeSlotIds(hhmm) {
      const h = parseInt(String(hhmm || '').split(':')[0], 10);
      if (Number.isNaN(h)) return [];
      return (this.busTimeSlotOptions || []).filter((s) => (
        s.to <= 24 ? (h >= s.from && h < s.to) : (h >= s.from || h < (s.to - 24))
      )).map((s) => s.id);
    },
    _filterBuses(items) {
      const f = this.busFilters || {};
      let list = items.filter((it) => {
        const price = Number(it.price) || 0;
        if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
        if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
        if ((f.operators || []).length && !f.operators.includes(String(it.operator || ''))) return false;
        if ((f.busTypes || []).length && !f.busTypes.includes(String(it.bus_type || ''))) return false;
        if ((f.seatClasses || []).length && !f.seatClasses.includes(String(it.seat_class || ''))) return false;
        if ((f.amenities || []).length) {
          const am = Array.isArray(it.amenities) ? it.amenities : [];
          if (!f.amenities.every((a) => am.includes(a))) return false;
        }
        if (f.refundableOnly && !it.refundable) return false;
        if ((f.timeSlots || []).length) {
          const slots = this._busTimeSlotIds(it.departure_time);
          if (!slots.some((s) => f.timeSlots.includes(s))) return false;
        }
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const hay = [
            it.name, it.service_name, it.operator, it.bus_type, it.seat_class, it.origin, it.destination
          ].map((x) => String(x || '').toLowerCase()).join(' ');
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'departure') {
          return String(a.departure_time || '').localeCompare(String(b.departure_time || ''));
        }
        if (sort === 'duration') {
          return String(a.duration || '').localeCompare(String(b.duration || ''));
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    railFacetOptions(section, field) {
      const set = new Set();
      (section?.items || []).forEach((it) => {
        if (field === 'seat_class') {
          this.railSeatOptions(it, false).forEach((seat) => {
            const label = this.railSeatLabel(seat);
            if (label) set.add(label);
          });
          return;
        }
        const v = String(it[field] || '').trim();
        if (v) set.add(v);
      });
      return Array.from(set).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    },
    _railTimeSlotIds(hhmm) {
      const h = parseInt(String(hhmm || '').split(':')[0], 10);
      if (Number.isNaN(h)) return [];
      return (this.railTimeSlotOptions || []).filter((s) => (
        s.to <= 24 ? (h >= s.from && h < s.to) : (h >= s.from || h < (s.to - 24))
      )).map((s) => s.id);
    },
    railSeatCode(seat) {
      return String(seat?.seat_class || seat?.seat_type || '').trim();
    },
    railSeatLabel(seat) {
      return String(seat?.seat_class_label || seat?.seat_name_english
        || seat?.seat_name || this.railSeatCode(seat)).trim();
    },
    railSeatOptions(item, availableOnly = true) {
      const seats = Array.isArray(item?.seats) ? item.seats : [];
      return seats.filter((seat) => {
        if (!this.railSeatCode(seat)) return false;
        if (availableOnly && Number(seat.seats_available ?? seat.numbs ?? seat.seats ?? 0) <= 0) return false;
        return true;
      });
    },
    selectedRailSeat(item) {
      const seats = this.railSeatOptions(item);
      if (!seats.length) return null;
      const selectedCode = String(item?.selected_rail_seat_class || '');
      return seats.find((seat) => this.railSeatCode(seat) === selectedCode) || seats[0];
    },
    selectRailSeat(item, seat) {
      if (!item || !seat || Number(seat.seats_available ?? seat.numbs ?? seat.seats ?? 0) <= 0) return;
      item.selected_rail_seat_class = this.railSeatCode(seat);
    },
    railSeatIsSelected(item, seat) {
      const selected = this.selectedRailSeat(item);
      return !!selected && this.railSeatCode(selected) === this.railSeatCode(seat);
    },
    railLowestPrice(item) {
      const prices = this.railSeatOptions(item).map((seat) => Number(seat.unit_price ?? seat.price) || 0).filter((price) => price > 0);
      return prices.length ? Math.min(...prices) : 0;
    },
    railHighestPrice(item) {
      const prices = this.railSeatOptions(item).map((seat) => Number(seat.unit_price ?? seat.price) || 0).filter((price) => price > 0);
      return prices.length ? Math.max(...prices) : 0;
    },
    railSelectedCard(item, seat) {
      if (!item || !seat) return null;
      const seatClass = this.railSeatCode(seat);
      const seatLabel = this.railSeatLabel(seat);
      return {
        ...item,
        name: [item.train_no, seatLabel].filter(Boolean).join(' '),
        subtitle: [seatLabel, item.departure_time].filter(Boolean).join(' · '),
        seat_class: seatClass,
        seat_class_label: seatLabel,
        seats_available: Number(seat.seats_available ?? seat.numbs ?? seat.seats ?? 0),
        price_per_seat: Number(seat.price_per_seat ?? seat.unit_price ?? 0),
        price_base: Number(seat.price_base ?? seat.price_total_limit ?? 0),
        price_total_limit: Number(seat.price_total_limit ?? seat.price_base ?? 0),
        price: Number(seat.price) || 0,
        detail: {
          journey_type: item.journey_type,
          traffic_no: item.traffic_no || item.train_no,
          seat_class: seatClass,
          seat_class_label: seatLabel,
          from_station_code: item.from_station_code,
          to_station_code: item.to_station_code,
          from_station_name: item.from_station_name,
          to_station_name: item.to_station_name,
          from_date_time: item.raw?.from_date_time || 0,
          to_date_time: item.raw?.to_date_time || 0,
          price_total_limit: Number(seat.price_total_limit ?? seat.price_base ?? 0),
          price_total_limit_original: Number(seat.price_total_limit ?? seat.price_base ?? 0),
          price_display: Number(seat.price) || 0,
          train: item.raw,
          seat: seat.raw || seat,
        },
      };
    },
    selectRailItem(section, item, iIdx) {
      const seat = this.selectedRailSeat(item);
      const card = this.railSelectedCard(item, seat);
      if (card) this.selectItem(section, card, iIdx);
    },

    // ---------- Ferries (Kikoto) ----------
    ferryTimeLabel(dt) {
      if (!dt) return '';
      const d = new Date(String(dt).replace(' ', 'T'));
      if (isNaN(d.getTime())) return String(dt);
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },
    ferryDateLabel(dt) {
      if (!dt) return '';
      const d = new Date(String(dt).replace(' ', 'T'));
      if (isNaN(d.getTime())) return String(dt);
      return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    },
    ferryDurationLabel(mins) {
      const m = Number(mins) || 0;
      if (!m) return '';
      const h = Math.floor(m / 60);
      return h ? (h + 'h ' + (m % 60) + 'm') : (m + 'm');
    },
    /** Kikoto quotes the real passenger-mix total in total_price; price is the base rate. */
    ferryAccPrice(acc) {
      return typeof acc?.total_price === 'number' ? acc.total_price : (Number(acc?.price) || 0);
    },
    ferryAccOptions(sailing) {
      const accs = Array.isArray(sailing?.accommodations) ? sailing.accommodations : [];
      return accs.filter((a) => a && (a.id !== undefined && a.id !== null)).map((a) => ({
        id: a.id,
        code: a.code || '',
        type: a.type || '',
        title: a.title || a.type || a.code || 'Standard',
        subtitle: a.subtitle || '',
        description: a.description || '',
        price: this.ferryAccPrice(a),
        base_price: Number(a.price) || 0,
        original_price: Number(a.original_price ?? a.price) || 0,
        currency: a.currency || this.currency,
        raw: a,
      }));
    },
    selectedFerryAcc(item) {
      const accs = Array.isArray(item?.accommodations) && item.accommodations.length
        ? item.accommodations
        : this.ferryAccOptions(item?.raw);
      if (!accs.length) return null;
      const wanted = String(item?.selected_ferry_acc_id || '');
      return accs.find((a) => String(a.id) === wanted) || accs[0];
    },
    selectFerryAcc(item, acc) {
      if (!item || !acc) return;
      item.selected_ferry_acc_id = String(acc.id);
      const ret = Number(item.return_accommodation?.price) || 0;
      item.price = Math.round(((Number(acc.price) || 0) + ret) * 100) / 100;
    },
    ferryAccIsSelected(item, acc) {
      const selected = this.selectedFerryAcc(item);
      return !!selected && String(selected.id) === String(acc?.id);
    },
    ferryServices(item) {
      const sailing = item?.raw || {};
      const svc = sailing.services || {};
      const companySvc = sailing.shipping_company?.services || {};
      const route = item?.route_services || {};
      return {
        passengers: svc.passengers ?? companySvc.passengers ?? route.passengers ?? true,
        vehicles: svc.vehicles ?? companySvc.vehicles ?? route.vehicles ?? null,
        pets: svc.pets ?? companySvc.pets ?? route.pets ?? null,
        check_in: svc.check_in ?? companySvc.check_in ?? null,
      };
    },
    /** Site-shaped passenger / vehicle / pet references (same ticket_type ids as the listing). */
    ferryPassengerRefs(adults, children, infant) {
      const refs = [];
      let id = 1;
      for (let i = 0; i < Math.max(1, Number(adults) || 1); i++) refs.push({ id: id++, ticket_type_id: 10, passenger_category: 'adult' });
      for (let i = 0; i < (Number(children) || 0); i++) refs.push({ id: id++, ticket_type_id: 11, passenger_category: 'child' });
      for (let i = 0; i < (Number(infant) || 0); i++) refs.push({ id: id++, ticket_type_id: 13, passenger_category: 'infant' });
      return refs;
    },
    ferryVehicleRefs(count) {
      const refs = [];
      for (let i = 1; i <= (Number(count) || 0); i++) refs.push({ id: i, ticket_type_id: 14, passenger_id: 1 });
      return refs;
    },
    ferryPetRefs(count) {
      const refs = [];
      for (let i = 1; i <= (Number(count) || 0); i++) refs.push({ id: i, ticket_type_id: 0, passenger_id: 1 });
      return refs;
    },
    /** Site-shaped leg sailing (ferriesBuildLegSailing) — one accommodation, passenger refs inside. */
    ferryLegSailing(sailing, acc, passengers, bonusId) {
      if (!sailing || !acc) return null;
      return {
        departure_port_id: sailing.departure_port_id,
        destination_port_id: sailing.destination_port_id,
        shipping_company_id: sailing.shipping_company_id,
        departure_datetime: sailing.departure_datetime,
        arrival_datetime: sailing.arrival_datetime,
        ship_name: sailing.ship_name || '',
        shipping_company: sailing.shipping_company || {},
        duration_minutes: sailing.duration_minutes || 0,
        accommodations: [{
          id: acc.id,
          type: acc.type,
          title: acc.title,
          subtitle: acc.subtitle || '',
          description: acc.description || '',
          code: acc.code,
          price: acc.base_price ?? acc.price,
          original_price: acc.original_price || acc.base_price || acc.price,
          passengers: passengers.map((p) => {
            const row = { id: p.id };
            if (bonusId > 0) row.bonuses = [{ bonus_id: bonusId }];
            return row;
          }),
        }],
      };
    },
    ferrySelectedCard(item, acc) {
      if (!item || !acc) return null;
      const passengers = this.ferryPassengerRefs(item.adults, item.children, item.infant);
      const vehicles = this.ferryVehicleRefs(item.vehicles);
      const pets = this.ferryPetRefs(item.pets);
      const bonusId = parseInt((item.bonuses || [])[0], 10) || 0;
      const selectedSailing = this.ferryLegSailing(item.raw, acc, passengers, bonusId);
      const retAcc = item.return_accommodation || null;
      const returnSailing = retAcc ? this.ferryLegSailing(item.return_sailing, retAcc, passengers, bonusId) : null;
      const total = Math.round(((Number(acc.price) || 0) + (Number(retAcc?.price) || 0)) * 100) / 100;
      return {
        ...item,
        name: [item.company, item.ship_name, acc.title].filter(Boolean).join(' · '),
        subtitle: [acc.title, item.departure_time].filter(Boolean).join(' · '),
        accommodation_id: acc.id,
        accommodation_title: acc.title,
        accommodation_code: acc.code || '',
        selected_ferry_acc_id: String(acc.id),
        price: total,
        detail: {
          supplier: item.supplier || 'kikoto',
          departure_port_id: item.departure_port_id,
          destination_port_id: item.destination_port_id,
          departure_port_name: item.origin,
          destination_port_name: item.destination,
          date: item.date,
          return_date: returnSailing ? item.return_date : '',
          trip_type: returnSailing ? 'return' : 'oneway',
          company: item.company,
          ship_name: item.ship_name,
          departure_datetime: item.departure_datetime,
          arrival_datetime: item.arrival_datetime,
          duration_minutes: item.duration_minutes,
          accommodation_id: acc.id,
          accommodation_title: acc.title,
          accommodation_code: acc.code || '',
          accommodation_price: Number(acc.price) || 0,
          return_accommodation_title: retAcc?.title || '',
          return_accommodation_price: Number(retAcc?.price) || 0,
          return_departure_datetime: item.return_sailing?.departure_datetime || '',
          return_arrival_datetime: item.return_sailing?.arrival_datetime || '',
          selected_sailing: selectedSailing,
          return_sailing: returnSailing,
          passengers,
          vehicles,
          pets,
          vehicle_type_hint: item.vehicles > 0 ? (item.vehicle_type || 'car') : '',
          pet_type_hint: item.pets > 0 ? (item.pet_type || 'carrier') : '',
          bonuses: bonusId > 0 ? [bonusId] : [],
          adults: Number(item.adults) || 1,
          children: Number(item.children) || 0,
          infant: Number(item.infant) || 0,
          revalidated_price: Number(acc.base_price ?? acc.price) || 0,
          price_display: total,
          currency: item.currency || this.currency,
        },
      };
    },
    selectFerryItem(section, item, iIdx) {
      const acc = this.selectedFerryAcc(item);
      const card = this.ferrySelectedCard(item, acc);
      if (card && card.detail) {
        const ids = (card.detail.bonuses || []).map((id) => parseInt(id, 10)).filter((id) => id > 0);
        const catalog = Array.isArray(section?.ferryBonuses) ? section.ferryBonuses : [];
        card.detail.bonus_details = catalog.filter((b) => ids.includes(parseInt(b.id, 10) || 0));
      }
      if (card) this.selectItem(section, card, iIdx);
    },
    ferryVehicleTypes() {
      // Same keys/labels as ferries-search.php (Tourism = car)
      return [
        { value: 'car', label: 'Tourism', icon: 'directions_car' },
        { value: 'van', label: 'Van', icon: 'airport_shuttle' },
        { value: 'motorcycle', label: 'Motorcycle', icon: 'two_wheeler' },
        { value: 'moped', label: 'Moped', icon: 'moped' },
        { value: 'bicycle', label: 'Bicycle', icon: 'pedal_bike' },
      ];
    },
    ferryPetTypes() {
      return [
        { value: 'carrier', label: 'Carrier', icon: 'luggage' },
        { value: 'medium_cage', label: 'Medium cage', icon: 'crib' },
        { value: 'large_cage', label: 'Large cage', icon: 'crib' },
      ];
    },
    ferryRouteSupportsVehicles(section) {
      const svc = section?.params?.route_services;
      if (svc && typeof svc === 'object' && Object.prototype.hasOwnProperty.call(svc, 'vehicles')) {
        return !!svc.vehicles;
      }
      return true;
    },
    ferryRouteSupportsPets(section) {
      const svc = section?.params?.route_services;
      if (svc && typeof svc === 'object' && Object.prototype.hasOwnProperty.call(svc, 'pets')) {
        return !!svc.pets;
      }
      return true;
    },
    /** Kikoto type=discount (+ security-forces etc.) — excludes large-family / residence. */
    ferryDiscountBonuses(section) {
      return (Array.isArray(section?.ferryBonuses) ? section.ferryBonuses : [])
        .filter((b) => !['large-family', 'residence'].includes(String(b.type || '').toLowerCase()));
    },
    ferryLargeFamilyBonuses(section) {
      return (Array.isArray(section?.ferryBonuses) ? section.ferryBonuses : [])
        .filter((b) => String(b.type || '').toLowerCase() === 'large-family');
    },
    ferryResidenceBonuses(section) {
      return (Array.isArray(section?.ferryBonuses) ? section.ferryBonuses : [])
        .filter((b) => String(b.type || '').toLowerCase() === 'residence');
    },
    ferrySelectedBonus(section) {
      return String((section?.params?.bonuses || [])[0] || '');
    },
    ferrySelectedBonusType(section) {
      const id = parseInt(this.ferrySelectedBonus(section), 10) || 0;
      if (!id) return '';
      const row = (section?.ferryBonuses || []).find((b) => parseInt(b.id, 10) === id);
      return String(row?.type || '').toLowerCase();
    },
    ferryVehicleLabel(section) {
      const n = Number(section?.params?.vehicles || 0);
      if (!n) return 'Vehicles';
      const key = String(section?.params?.vehicle_type || 'car');
      const match = this.ferryVehicleTypes().find((v) => v.value === key);
      return match ? match.label : 'Vehicles';
    },
    ferryPetLabel(section) {
      const n = Number(section?.params?.pets || 0);
      if (!n) return 'Pets';
      const key = String(section?.params?.pet_type || 'carrier');
      const match = this.ferryPetTypes().find((v) => v.value === key);
      return match ? match.label : 'Pets';
    },
    ferryBonusLabel(section, bucket) {
      const id = parseInt(this.ferrySelectedBonus(section), 10) || 0;
      const type = this.ferrySelectedBonusType(section);
      const defaults = {
        discount: 'Bonus',
        'large-family': 'Large family',
        residence: 'Residence',
      };
      if (!id) return defaults[bucket] || 'Bonus';
      if (bucket === 'discount' && (type === 'large-family' || type === 'residence')) {
        return defaults.discount;
      }
      if (bucket === 'large-family' && type !== 'large-family') return defaults['large-family'];
      if (bucket === 'residence' && type !== 'residence') return defaults.residence;
      const row = (section?.ferryBonuses || []).find((b) => parseInt(b.id, 10) === id);
      return row?.name || defaults[bucket] || 'Bonus';
    },
    ferryExtrasOpen(section, which) {
      return String(section?._ferryExtrasOpen || '') === which;
    },
    async toggleFerryExtras(section, which) {
      if (!section || section.module !== 'ferries') return;
      const next = this.ferryExtrasOpen(section, which) ? '' : which;
      section._ferryExtrasOpen = next;
      if (['bonus', 'large-family', 'residence'].includes(next)) {
        await this._loadFerryBonuses(section, true);
      }
    },
    /** Pick a vehicle type (1 vehicle) or clear — same as site search. */
    async selectFerryVehicleType(section, key) {
      if (!section) return;
      if (!key) {
        await this.setFerryExtra(section, 'vehicles', '0');
        return;
      }
      const params = Object.assign({}, section.params || {});
      params.vehicles = '1';
      params.vehicle_type = String(key);
      section.params = params;
      await this._reloadFerrySection(section);
    },
    async selectFerryPetType(section, key) {
      if (!section) return;
      if (!key) {
        await this.setFerryExtra(section, 'pets', '0');
        return;
      }
      const params = Object.assign({}, section.params || {});
      params.pets = '1';
      params.pet_type = String(key);
      section.params = params;
      await this._reloadFerrySection(section);
    },
    async chooseFerryBonus(section, bonus) {
      if (!section) return;
      const id = bonus ? (parseInt(bonus.id, 10) || 0) : 0;
      section._ferryExtrasOpen = '';
      await this.setFerryExtra(section, 'bonus', id > 0 ? String(id) : '');
    },
    async _reloadFerrySection(section) {
      const idx = this.sections.indexOf(section);
      if (idx < 0) return;
      this.selected = (this.selected || []).filter((x) => x.module !== 'ferries');
      this._cartSave();
      await this._loadSection(idx);
    },
    /** Extras (vehicles / pets / bonus) change the Kikoto quote, so re-search the section. */
    async setFerryExtra(section, key, value) {
      if (!section || section.module !== 'ferries') return;
      const params = Object.assign({}, section.params || {});
      if (key === 'vehicles' || key === 'pets') {
        const n = Math.max(0, Math.min(4, parseInt(value, 10) || 0));
        params[key] = String(n);
        if (key === 'vehicles') params.vehicle_type = n > 0 ? (params.vehicle_type || 'car') : '';
        if (key === 'pets') params.pet_type = n > 0 ? (params.pet_type || 'carrier') : '';
      } else if (key === 'vehicle_type' || key === 'pet_type') {
        params[key] = String(value || '');
      } else if (key === 'bonus') {
        const id = parseInt(value, 10) || 0;
        params.bonuses = id > 0 ? [id] : [];
      } else {
        return;
      }
      section.params = params;
      await this._reloadFerrySection(section);
    },
    /**
     * Kikoto bonuses (discount / large-family / residence / …).
     * Same endpoint as the website search widget.
     */
    async _loadFerryBonuses(section, force = false) {
      if (!section || section.module !== 'ferries') return;
      const dep = parseInt(section.params?.departure_port_id, 10) || 0;
      const dest = parseInt(section.params?.destination_port_id, 10) || 0;
      if (!dep) return;
      const cacheKey = dep + ':' + dest;
      if (!force && section._ferryBonusesKey === cacheKey && Array.isArray(section.ferryBonuses)) {
        return;
      }
      if (section._ferryBonusesLoading) return;
      section._ferryBonusesLoading = true;
      try {
        const r = await fetch(this.root + 'api/ferries/bonuses', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            departure_port_id: dep,
            destination_port_id: dest,
            lang: 'en',
          }),
        });
        const json = await r.json().catch(() => null);
        const payload = json?.data;
        // Website accepts either a bare array or { bonuses: [...] }
        section.ferryBonuses = Array.isArray(payload)
          ? payload
          : (Array.isArray(payload?.bonuses) ? payload.bonuses : []);
        section._ferryBonusesKey = cacheKey;
      } catch (e) {
        section.ferryBonuses = [];
      } finally {
        section._ferryBonusesLoading = false;
      }
    },
    ferryBonusBuckets(section) {
      // Kept for any leftover callers; UI now uses typed helpers above.
      return [
        { key: 'discount', label: 'Bonus', items: this.ferryDiscountBonuses(section) },
        { key: 'large-family', label: 'Large family', items: this.ferryLargeFamilyBonuses(section) },
        { key: 'residence', label: 'Residence', items: this.ferryResidenceBonuses(section) },
      ].filter((b) => b.items.length);
    },
    _filterRails(items) {
      const f = this.railFilters || {};
      let list = items.filter((it) => {
        const matchingSeats = this.railSeatOptions(it).filter((seat) => {
          const price = Number(seat.unit_price ?? seat.price) || 0;
          if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
          if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
          if ((f.seatClasses || []).length && !f.seatClasses.includes(this.railSeatLabel(seat))) return false;
          return true;
        });
        if (!matchingSeats.length) return false;
        if ((f.timeSlots || []).length) {
          const ids = this._railTimeSlotIds(it.departure_time);
          if (!ids.some((id) => f.timeSlots.includes(id))) return false;
        }
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const hay = [
            it.train_no, it.traffic_no,
            it.origin, it.destination, it.from_station_name, it.to_station_name, it.title,
            ...this.railSeatOptions(it, false).map((seat) => this.railSeatLabel(seat)),
          ].map((x) => String(x || '').toLowerCase()).join(' ');
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return this.railHighestPrice(b) - this.railHighestPrice(a);
        if (sort === 'departure') {
          return String(a.departure_time || '').localeCompare(String(b.departure_time || ''));
        }
        if (sort === 'duration') {
          return (Number(a.run_time_mins) || 0) - (Number(b.run_time_mins) || 0);
        }
        return this.railLowestPrice(a) - this.railLowestPrice(b);
      });
      return list;
    },
    esimFacetOptions(section, field) {
      const set = new Set();
      (section?.items || []).forEach((it) => {
        const v = String(it[field] || '').trim();
        if (v) set.add(v);
      });
      if (field === 'price') {
        return Array.from(set).map(Number).filter((n) => n > 0).sort((a, b) => a - b);
      }
      return Array.from(set).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    },
    _filterEsims(items) {
      const f = this.esimFilters || {};
      let list = items.filter((it) => {
        if (f.duration && String(it.duration || '') !== String(f.duration)) return false;
        if (f.dataLimit && String(it.data_limit || '') !== String(f.dataLimit)) return false;
        if (f.price !== '' && f.price != null && Number(it.price) !== Number(f.price)) return false;
        if ((f.packageTypes || []).length) {
          const pt = String(it.package_type || 'local').toLowerCase();
          if (!f.packageTypes.map((x) => String(x).toLowerCase()).includes(pt)) return false;
        }
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const hay = [it.name, it.title, it.country, it.data_limit, it.duration, it.package_type]
            .map((x) => String(x || '').toLowerCase()).join(' ');
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'data') {
          return String(a.data_limit || '').localeCompare(String(b.data_limit || ''), undefined, { numeric: true });
        }
        if (sort === 'duration') {
          return String(a.duration || '').localeCompare(String(b.duration || ''), undefined, { numeric: true });
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    /** Name/sort only — Duration / Type / Services re-fetch via applyUmrahFilters (same as normal search). */
    _filterUmrahs(items) {
      const f = this.umrahFilters || {};
      let list = items.filter((it) => {
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const hay = [it.name, it.title, it.subtitle, it.location, it.umrah_type]
            .map((x) => String(x || '').toLowerCase()).join(' ');
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'duration') {
          return (Number(a.days) || 0) - (Number(b.days) || 0);
        }
        if (sort === 'name') {
          return String(a.name || a.title || '').localeCompare(String(b.name || b.title || ''));
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    busFacetOptions(section, field) {
      const set = new Set();
      (section?.items || []).forEach((it) => {
        if (field === 'amenities') {
          (Array.isArray(it.amenities) ? it.amenities : []).forEach((a) => {
            if (a) set.add(String(a));
          });
          return;
        }
        const v = String(it[field] || '').trim();
        if (v) set.add(v);
      });
      return Array.from(set).sort((a, b) => a.localeCompare(b));
    },
    _filterTours(items) {
      const f = this.tourFilters || {};
      let list = items.filter((it) => {
        if ((f.stars || []).length) {
          const stars = Math.round(Number(it.stars || it.star_rating || 0));
          if (!f.stars.includes(stars)) return false;
        }
        if (f.tourType) {
          const want = String(f.tourType).toLowerCase().trim();
          const typeId = String(it.tour_type_id || '').toLowerCase();
          const typeName = String(it.tour_type || '').toLowerCase().trim();
          const typeSlug = typeName.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
          const opt = (this.tourTypeOptions || []).find((t) => String(t.value) === String(f.tourType));
          const optSlug = String(opt?.slug || '').toLowerCase();
          const optName = String(opt?.name || '').toLowerCase();
          const match = want === typeId
            || (optName && typeName === optName)
            || (optSlug && (typeSlug === optSlug || typeName.includes(optSlug.replace(/-/g, ' '))))
            || (typeName && (typeName.includes(want) || typeSlug === want));
          if (!match) return false;
        }
        const price = Number(it.price) || 0;
        if (f.priceMin != null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
        if (f.priceMax != null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
        const q = String(f.nameSearch || '').trim().toLowerCase();
        if (q) {
          const name = String(it.name || '').toLowerCase();
          const loc = String(it.location || '').toLowerCase();
          if (!name.includes(q) && !loc.includes(q)) return false;
        }
        return true;
      });
      const sort = f.sort || 'price_low';
      list.sort((a, b) => {
        if (sort === 'price_high') return (b.price || 0) - (a.price || 0);
        if (sort === 'stars') {
          return (Number(b.stars || b.star_rating) || 0) - (Number(a.stars || a.star_rating) || 0);
        }
        if (sort === 'duration') {
          return this._tourDays(a) - this._tourDays(b);
        }
        return (a.price || 0) - (b.price || 0);
      });
      return list;
    },
    _parsePaxFromQuery(q) {
      const text = String(q || '');
      let adults = null;
      let children = null;
      let rooms = null;
      const a = text.match(/\b(\d+)\s*adults?\b/i);
      if (a) adults = Math.max(1, parseInt(a[1], 10) || 1);
      else {
        const p = text.match(/\bfor\s+(\d+)\s*(?:people|persons?|pax|travellers?|travelers?)\b/i);
        if (p) adults = Math.max(1, parseInt(p[1], 10) || 1);
      }
      const c = text.match(/\b(\d+)\s*(?:children?s?|kids?)\b/i);
      if (c) children = Math.max(0, parseInt(c[1], 10) || 0);
      const r = text.match(/\b(\d+)\s*rooms?\b/i);
      if (r) rooms = Math.max(1, Math.min(5, parseInt(r[1], 10) || 1));
      const childAges = this._parseChildAgesFromQuery(text, children != null ? children : 0);
      return { adults, children, rooms, child_ages: childAges };
    },
    _parseChildAgesFromQuery(text, children) {
      if (!children) return [];
      let ages = [];
      const aged = text.match(/\b(?:aged?|ages)\s+((?:\d{1,2}\s*(?:,|and|&)\s*)*\d{1,2})\b/i);
      if (aged) ages = (aged[1].match(/\d{1,2}/g) || []).map((n) => parseInt(n, 10));
      if (!ages.length) {
        const olds = [...text.matchAll(/\b(\d{1,2})\s*[- ]?\s*(?:year|yr)s?\s*[- ]?\s*old\b/gi)];
        ages = olds.map((m) => parseInt(m[1], 10));
      }
      if (!ages.length) {
        const afterKids = text.match(/\b(?:children?s?|kids?)\b[^\d]{0,24}((?:\d{1,2}\s*(?:,|and|&)\s*)+\d{1,2})/i);
        if (afterKids) ages = (afterKids[1].match(/\d{1,2}/g) || []).map((n) => parseInt(n, 10));
      }
      return ages.filter((n) => n >= 1 && n <= 17).slice(0, children);
    },
    _splitStayRoomsData(adults, children, rooms, ages) {
      adults = Math.max(1, parseInt(adults, 10) || 1);
      children = Math.max(0, parseInt(children, 10) || 0);
      rooms = Math.max(1, Math.min(5, parseInt(rooms, 10) || 1));
      if (rooms > adults) rooms = adults;
      const list = Array.isArray(ages) ? ages : [];
      const data = Array.from({ length: rooms }, () => ({
        adults: 0, children: 0, childAges: [], children_ages: []
      }));
      for (let i = 0; i < adults; i++) data[i % rooms].adults += 1;
      for (let i = 0; i < children; i++) {
        const idx = i % rooms;
        data[idx].children += 1;
        const age = parseInt(list[i], 10);
        if (Number.isFinite(age) && age >= 1 && age <= 17) {
          data[idx].childAges.push(age);
          data[idx].children_ages.push(age);
        }
      }
      data.forEach((room) => { if (room.adults < 1) room.adults = 1; });
      return data;
    },
    _applyPromptPaxToSections(sections) {
      const pax = this._parsePaxFromQuery(this.query);
      return (sections || []).map((s) => {
        const params = { ...(s.params || {}) };
        if (pax.adults != null) {
          params.adults = String(pax.adults);
          if (params.travelers) {
            const ch = pax.children != null ? pax.children : (parseInt(params.children || 0, 10) || 0);
            params.travelers = pax.adults + '-' + ch;
          }
        }
        if (pax.children != null) {
          params.children = String(pax.children);
          if (params.travelers || pax.adults != null) {
            const ad = pax.adults != null ? pax.adults : (parseInt(params.adults || 1, 10) || 1);
            params.travelers = ad + '-' + pax.children;
          }
        }
        if (s.module === 'stays') {
          if (pax.rooms != null) params.rooms = String(pax.rooms);
          const adultsN = parseInt(params.adults || 1, 10) || 1;
          const childrenN = parseInt(params.children || 0, 10) || 0;
          let roomsN = parseInt(params.rooms || 1, 10) || 1;
          if (roomsN > adultsN) roomsN = adultsN;
          params.rooms = String(roomsN);
          const ages = Array.isArray(pax.child_ages) ? pax.child_ages : [];
          params.child_ages = ages;
          params.rooms_data = JSON.stringify(this._splitStayRoomsData(adultsN, childrenN, roomsN, ages));
        }
        return { ...s, params };
      });
    },
    _durationMinutes(v) {
      const s = String(v || '');
      const hm = s.match(/(\d+)\s*h(?:ours?)?\s*(\d+)?/i);
      if (hm) return (parseInt(hm[1], 10) || 0) * 60 + (parseInt(hm[2], 10) || 0);
      const m = s.match(/(\d+)\s*m/i);
      if (m) return parseInt(m[1], 10) || 0;
      return 0;
    },
    activeFilterCount(module) {
      if (module === 'flights') {
        const f = this.flightFilters || {};
        return (f.stops?.length || 0)
          + (f.airlines?.length || 0)
          + (f.flightNumber ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'stays') {
        const f = this.hotelFilters || {};
        return (f.stars?.length || 0)
          + (f.nameSearch ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'tours') {
        const f = this.tourFilters || {};
        return (f.stars?.length || 0)
          + (f.nameSearch ? 1 : 0)
          + (f.tourType ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'cars') {
        const f = this.carFilters || {};
        return (f.carTypes?.length || 0)
          + (f.transmission?.length || 0)
          + (f.suppliers?.length || 0)
          + (f.features?.length || 0)
          + (f.passengers?.length || 0)
          + (f.nameSearch ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'bus') {
        const f = this.busFilters || {};
        return (f.operators?.length || 0)
          + (f.busTypes?.length || 0)
          + (f.seatClasses?.length || 0)
          + (f.timeSlots?.length || 0)
          + (f.amenities?.length || 0)
          + (f.refundableOnly ? 1 : 0)
          + (f.nameSearch ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'rail') {
        const f = this.railFilters || {};
        return (f.seatClasses?.length || 0)
          + (f.timeSlots?.length || 0)
          + (f.nameSearch ? 1 : 0)
          + ((f.priceMin != null && f.priceMin !== '') || (f.priceMax != null && f.priceMax !== '') ? 1 : 0);
      }
      if (module === 'esim') {
        const f = this.esimFilters || {};
        return (f.packageTypes?.length || 0)
          + (f.duration ? 1 : 0)
          + (f.dataLimit ? 1 : 0)
          + (f.price !== '' && f.price != null ? 1 : 0)
          + (f.nameSearch ? 1 : 0);
      }
      if (module === 'umrah') {
        const f = this.umrahFilters || {};
        return (f.destination ? 1 : 0)
          + (f.duration ? 1 : 0)
          + (f.umrahType ? 1 : 0)
          + (f.services?.length || 0)
          + (f.nameSearch ? 1 : 0);
      }
      return 0;
    },
    get selectedByModule() {
      const groups = {};
      (this.selected || []).forEach((x) => {
        const m = x.module || 'other';
        if (!groups[m]) groups[m] = [];
        groups[m].push(x);
      });
      return groups;
    },
    get orderedModules() {
      const mods = [];
      const groups = this.selectedByModule;
      // Add modules in the order they appear in the AI sections
      (this.sections || []).forEach(s => {
        if (s.module && groups[s.module]) {
          mods.push(s.module);
        }
      });
      // Add any modules that might not be in sections but have selected items
      Object.keys(groups).forEach(m => {
        if (!mods.includes(m)) mods.push(m);
      });
      return mods;
    },
    /** Detail chips for review summary rows. */
    summaryMeta(sel) {
      if (!sel) return [];
      const mod = String(sel.module || sel.kind || '').toLowerCase();
      const item = sel.item || {};
      const chips = [];
      if (mod === 'flights' || mod === 'flight') {
        if (item.flight_no) chips.push('Flight ' + item.flight_no);
        if (item.class) chips.push(this.formatCabinClass(item.class));
        if (item.duration_time) chips.push(item.duration_time);
        chips.push(this.stopsLabel(item.stops));
        if (item.cabin_baggage) chips.push('Cabin ' + this.formatBaggageLabel(item.cabin_baggage));
        if (item.baggage) chips.push('Bag ' + this.formatBaggageLabel(item.baggage));
        if (item.returnFlight || item.isRoundTrip) chips.push('Round-trip');
        if (item.isMultiCity || item.raw?.isMultiCity || item.raw?.type === 'multicity') chips.push('Multi-city');
        chips.push(item.refundable ? 'Refundable' : 'Non-refundable');
      } else if (mod === 'stays' || mod === 'stay' || mod === 'hotels') {
        const stars = this.stayStars(item);
        if (stars) chips.push(stars + '★');
        const rating = this.stayGuestRating(item);
        if (rating) chips.push('Rated ' + rating);
        const dates = this.stayDatesLabel(item, sel);
        if (dates) chips.push(dates);
        const nights = this.stayNightsFor(item, sel);
        if (nights) chips.push(nights + (nights === 1 ? ' night' : ' nights'));
        const rooms = Array.isArray(sel.selected_rooms) ? sel.selected_rooms.length
          : (Array.isArray(item.selected_rooms) ? item.selected_rooms.length : 0);
        if (rooms) chips.push(rooms + (rooms === 1 ? ' room' : ' rooms'));
        const perNight = this.stayPricePerNight(item, sel);
        if (perNight > 0) {
          chips.push((sel.currency || this.currency) + ' ' + this.formatMoney(perNight) + '/night');
        }
      } else if (mod === 'tours' || mod === 'tour') {
        const stars = this.tourStars(item);
        if (stars) chips.push(stars + '★');
        const dur = this.tourDurationLabel(item);
        if (dur) chips.push(dur);
        if (item.start_date) chips.push(this.formatTravelDate(item.start_date));
        if (item.location) chips.push(item.location);
        if (item.tour_type) chips.push(item.tour_type);
        const a = parseInt(item.adults || sel.params?.adults || 1, 10) || 1;
        const c = parseInt(item.children || sel.params?.children || 0, 10) || 0;
        chips.push(a + (a === 1 ? ' adult' : ' adults') + (c > 0 ? ', ' + c + (c === 1 ? ' child' : ' children') : ''));
      } else if (mod === 'cars' || mod === 'car') {
        const pickup = item.pickup_location || sel.params?.pickup_location || '';
        const dropoff = item.dropoff_location || sel.params?.dropoff_location || '';
        if (pickup) chips.push(pickup === dropoff || !dropoff ? pickup : (pickup + ' → ' + dropoff));
        if (sel.params?.pickup_date) chips.push(this.formatTravelDate(sel.params.pickup_date));
        if (item.transmission) chips.push(item.transmission);
        if (item.passengers) chips.push(item.passengers + ' seats');
        if (item.vendor) chips.push(item.vendor);
      } else if (mod === 'bus' || mod === 'buses') {
        if (item.origin && item.destination) chips.push(item.origin + ' → ' + item.destination);
        const busDate = this.busTravelDate(item, sel);
        if (busDate) chips.push(busDate);
        if (item.departure_time) chips.push(item.departure_time);
        if (item.operator) chips.push(item.operator);
        if (item.seat_class) chips.push(item.seat_class);
        if (item.bus_type) chips.push(item.bus_type);
        if (item.return_trip) chips.push('Round-trip');
        if (item.refundable) chips.push('Refundable');
      } else if (mod === 'rail' || mod === 'train') {
        if (item.origin && item.destination) chips.push(item.origin + ' → ' + item.destination);
        if (item.train_no || item.traffic_no) chips.push(item.train_no || item.traffic_no);
        if (item.seat_class_label || item.seat_class) chips.push(item.seat_class_label || item.seat_class);
        if (item.departure_time) chips.push(item.departure_time);
        if (item.date) chips.push(this.formatTravelDate(item.date));
        if (item.journey_type_label) chips.push(item.journey_type_label);
      } else if (mod === 'ferries' || mod === 'ferry') {
        if (item.origin && item.destination) chips.push(item.origin + ' → ' + item.destination);
        if (item.company) chips.push(item.company);
        if (item.accommodation_title) chips.push(item.accommodation_title);
        if (item.departure_time) chips.push(item.departure_time);
        if (item.date) chips.push(this.formatTravelDate(item.date));
        if (Number(item.vehicles) > 0) chips.push(item.vehicles + ' × ' + (item.vehicle_type || 'vehicle'));
        if (Number(item.pets) > 0) chips.push(item.pets + ' × pet');
      } else if (mod === 'esim' || mod === 'e-sim') {
        if (item.country) chips.push(item.country);
        if (item.data_limit) chips.push(item.data_limit);
        if (item.duration) chips.push(item.duration);
        if (item.package_type) chips.push(String(item.package_type));
      } else if (mod === 'visa') {
        const from = item.from_country_name || item.from_country || '';
        const to = item.to_country_name || item.to_country || '';
        if (from && to) chips.push(from + ' → ' + to);
        if (item.visa_type_name || item.visa_type) chips.push(item.visa_type_name || item.visa_type);
        if (item.processing_speed_name || item.processing_speed) chips.push(item.processing_speed_name || item.processing_speed);
        if (item.entry_date) chips.push(item.entry_date);
        if (item.is_inquiry_only) chips.push('Inquiry');
        else if (item.price_per_person > 0) chips.push('Catalog price');
      } else if (mod === 'umrah') {
        if (item.location) chips.push(item.location);
        if (item.days) chips.push(item.days + ' days');
        if (item.umrah_type) chips.push(item.umrah_type);
        if (item.start_date) chips.push(item.start_date);
        const pax = this.umrahTravelerLabel(item);
        if (pax) chips.push(pax);
      }
      return chips.filter(Boolean).slice(0, 6);
    },
    moduleLabel(m) {
      const map = { flights: 'Flights', stays: 'Hotels', tours: 'Tours', cars: 'Cars', bus: 'Bus', rail: 'Rail', ferries: 'Ferries', esim: 'eSIM', visa: 'Visa', umrah: 'Umrah' };
      return map[m] || (String(m || 'Item').charAt(0).toUpperCase() + String(m || '').slice(1));
    },
    moduleIcon(m) {
      const map = { flights: 'flight', stays: 'hotel', tours: 'tour', cars: 'directions_car', bus: 'directions_bus', rail: 'train', ferries: 'directions_boat', esim: 'sim_card', visa: 'passport', umrah: 'mosque' };
      return map[m] || 'travel_explore';
    },
    /** Home page tab hash for this AI module (e.g. stays → #stays). */
    homeTabForModule(mod) {
      const m = String(mod || '').toLowerCase();
      if (m === 'hotels' || m === 'hotel' || m === 'stay') return 'stays';
      if (m === 'flight') return 'flights';
      if (m === 'tour') return 'tours';
      if (m === 'car') return 'cars';
      if (m === 'buses') return 'bus';
      if (m === 'train' || m === 'trains') return 'rail';
      if (m === 'ferry' || m === 'ferries') return 'ferries';
      if (m === 'e-sim') return 'esim';
      return m || 'flights';
    },
    homeSearchUrl(section) {
      const tab = this.homeTabForModule(section && section.module);
      const base = String(this.root || '/').replace(/\/?$/, '/');
      return base + '#' + tab;
    },
    homeSearchCta(section) {
      return 'Search ' + this.moduleLabel(section && section.module) + ' on home';
    },
    /** Format d-m-Y / Y-m-d / Date-ish strings for drawer display. */
    formatTravelDate(value) {
      const raw = String(value || '').trim();
      if (!raw) return '';
      let ts = NaN;
      const dmY = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
      const yMd = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (dmY) ts = Date.parse(dmY[3] + '-' + dmY[2] + '-' + dmY[1] + 'T12:00:00');
      else if (yMd) ts = Date.parse(yMd[1] + '-' + yMd[2] + '-' + yMd[3] + 'T12:00:00');
      else ts = Date.parse(raw);
      if (!Number.isFinite(ts)) return raw;
      try {
        return new Date(ts).toLocaleDateString(undefined, {
          weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
        });
      } catch (e) {
        return raw;
      }
    },
    /** Bus travel date (outbound + optional return), from item or section search params. */
    busTravelDate(item, section) {
      const outRaw = this.busOutboundDateRaw(item, section);
      const out = this.formatTravelDate(outRaw);
      const retRaw = this.busReturnDateRaw(item, section);
      const ret = (item && (item.return_trip || item.trip_type === 'return') && retRaw)
        ? this.formatTravelDate(retRaw)
        : '';
      if (out && ret && ret !== out) return out + ' – ' + ret;
      return out || ret || '';
    },
    busOutboundDateRaw(item, section) {
      return String(
        (item && (item.date || item.date_display || item.raw?.date_display || item.raw?.date))
        || (section && section.params && section.params.date)
        || ''
      ).trim();
    },
    busReturnDateRaw(item, section) {
      return String(
        (item && item.return_trip && (item.return_trip.date_display || item.return_trip.date))
        || (item && item.return_date)
        || (section && section.params && section.params.return_date)
        || ''
      ).trim();
    },
    /** Prefer d-m-Y under cities (same as /bus listing); fall back to formatted. */
    busCardDate(raw) {
      const s = String(raw || '').trim();
      if (!s) return '';
      if (/^\d{2}-\d{2}-\d{4}$/.test(s)) return s;
      const yMd = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (yMd) return yMd[3] + '-' + yMd[2] + '-' + yMd[1];
      return this.formatTravelDate(s) || s;
    },
    /** Outbound (+ return) legs for AI bus cards — mirrors /bus listing. */
    busCardLegs(item, section) {
      if (!item) return [];
      const outDate = this.busCardDate(this.busOutboundDateRaw(item, section));
      const legs = [{
        type: 'Outbound',
        departure_time: item.departure_time || '',
        arrival_time: item.arrival_time || '',
        origin: item.origin || section?.params?.origin || '',
        destination: item.destination || section?.params?.destination || '',
        duration: item.duration || '',
        seats_available: Number(item.seats_available || item.raw?.seats_available || 0),
        date: outDate,
        service_name: item.name || item.service_name || item.operator || 'Bus',
        operator: item.operator || '',
        bus_type: item.bus_type || '',
        seat_class: item.seat_class || '',
        image: item.image || '',
      }];
      const ret = item.return_trip && typeof item.return_trip === 'object' ? item.return_trip : null;
      if (ret) {
        legs.push({
          type: 'Return',
          departure_time: ret.departure_time || '',
          arrival_time: ret.arrival_time || '',
          origin: ret.origin || item.destination || section?.params?.destination || '',
          destination: ret.destination || item.origin || section?.params?.origin || '',
          duration: ret.duration || '',
          seats_available: Number(ret.seats_available || 0),
          date: this.busCardDate(ret.date_display || ret.date || this.busReturnDateRaw(item, section)),
          service_name: ret.service_name || ret.operator || item.name || 'Bus',
          operator: ret.operator || item.operator || '',
          bus_type: ret.bus_type || item.bus_type || '',
          seat_class: ret.seat_class || item.seat_class || '',
          image: ret.img || ret.image || item.image || '',
        });
      }
      return legs;
    },
    flightDepartureDate(item, section) {
      return this.formatTravelDate(
        (item && (item.departure_date || item.raw?.departure_date)) ||
        (section && section.params && section.params.departure_date) ||
        ''
      );
    },
    flightArrivalDate(item, section) {
      return this.formatTravelDate(
        (item && (item.arrival_date || item.raw?.arrival_date)) ||
        (section && section.params && section.params.departure_date) ||
        ''
      );
    },
    /** Return-leg arrival date (often next calendar day for overnight flights). */
    flightReturnArrivalDate(item, section) {
      const ret = item && item.returnFlight;
      if (!ret) return '';
      const explicit = ret.arrival_date || '';
      if (explicit) return this.formatTravelDate(explicit);
      const depDate = ret.departure_date
        || (section && section.params && section.params.return_date)
        || '';
      const inferred = this._inferArrivalDate(depDate, ret.departure_time, ret.arrival_time);
      return this.formatTravelDate(inferred || depDate);
    },
    /** If arrival clock is earlier than departure clock, treat as next day. */
    _inferArrivalDate(depDate, depTime, arrTime) {
      if (!depDate) return '';
      const dep = this._parseStayDate(depDate);
      if (!dep) return depDate;
      const toMins = (t) => {
        const s = String(t || '').trim().toLowerCase();
        if (!s) return null;
        // 03:26 am / 11:13 pm
        let m = s.match(/^(\d{1,2}):(\d{2})\s*(am|pm)?$/i);
        if (m) {
          let h = parseInt(m[1], 10);
          const min = parseInt(m[2], 10) || 0;
          const ap = (m[3] || '').toLowerCase();
          if (ap === 'pm' && h < 12) h += 12;
          if (ap === 'am' && h === 12) h = 0;
          return h * 60 + min;
        }
        // 03:26 / 23:13
        m = s.match(/^(\d{1,2}):(\d{2})$/);
        if (m) return (parseInt(m[1], 10) || 0) * 60 + (parseInt(m[2], 10) || 0);
        return null;
      };
      const dMins = toMins(depTime);
      const aMins = toMins(arrTime);
      const out = new Date(dep.getTime());
      if (dMins != null && aMins != null && aMins < dMins) {
        out.setDate(out.getDate() + 1);
      }
      return this._formatStayDate(out);
    },
    /**
     * Same as normal stays: show real hotel check-in and check-out dates
     * (checkout is the morning guests leave, not the last night slept).
     */
    stayDatesLabel(item, section) {
      const cinRaw = (item && item.checkin)
        || (section && section.params && section.params.checkin)
        || '';
      const coutRaw = (item && item.checkout)
        || (section && section.params && section.params.checkout)
        || '';
      const cin = this.formatTravelDate(cinRaw);
      const cout = this.formatTravelDate(coutRaw);
      if (cin && cout) return cin + ' → ' + cout;
      return cin || cout;
    },
    stayNightsCount(checkin, checkout, fallback) {
      const parse = (v) => this._parseStayDate(v);
      const a = parse(checkin);
      const b = parse(checkout);
      // Prefer real date span (hotel nights = checkout − checkin). Use UTC calendar
      // days so DST cannot turn a 4-night stay into 5.
      if (a && b && b > a) {
        const utcA = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate());
        const utcB = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate());
        const days = Math.round((utcB - utcA) / 86400000);
        if (days > 0) return days;
      }
      const fb = parseInt(fallback, 10);
      if (Number.isFinite(fb) && fb > 0) return fb;
      return 1;
    },
    /** Nights for a stay card: section search dates win over supplier item.nights. */
    stayNightsFor(item, section) {
      const cin = (section && section.params && section.params.checkin)
        || (item && item.checkin) || '';
      const cout = (section && section.params && section.params.checkout)
        || (item && item.checkout) || '';
      const fb = (section && section.params && (section.params.duration_days || section.params.nights))
        || (item && item.nights) || 1;
      return this.stayNightsCount(cin, cout, fb);
    },
    _parseStayDate(v) {
      if (!v) return null;
      const s = String(v).trim();
      let m = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
      if (m) return new Date(+m[3], +m[2] - 1, +m[1]);
      m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
      if (m) return new Date(+m[1], +m[2] - 1, +m[3]);
      // "Mon, 27 Jul 2026" / "27 Jul 2026" — parse parts to avoid UTC Date.parse shifts
      m = s.match(/(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+(\d{4})/i);
      if (m) {
        const months = { jan:0,feb:1,mar:2,apr:3,may:4,jun:5,jul:6,aug:7,sep:8,oct:9,nov:10,dec:11 };
        const mi = months[String(m[2]).slice(0, 3).toLowerCase()];
        if (mi !== undefined) return new Date(+m[3], mi, +m[1]);
      }
      const t = Date.parse(s);
      return Number.isFinite(t) ? new Date(t) : null;
    },
    _formatStayDate(d) {
      if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
      const dd = String(d.getDate()).padStart(2, '0');
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const yyyy = d.getFullYear();
      return dd + '-' + mm + '-' + yyyy;
    },
    /**
     * Hotel check-in must be the flight ARRIVAL day at destination
     * (overnight flights often depart day N and land day N+1).
     */
    _syncStayDatesFromSelectedFlight(flight) {
      if (!flight) return;
      const staysIdx = (this.sections || []).findIndex((s) => s.module === 'stays');
      if (staysIdx < 0) return;
      const stays = this.sections[staysIdx];
      const params = stays.params || {};
      // Multi-city: hotel check-in follows the FINAL slice arrival at destination.
      let arrivalRaw = flight.arrival_date || flight.raw?.arrival_date || '';
      let departureRaw = flight.departure_date || flight.raw?.departure_date || params.checkin || '';
      if (flight.isMultiCity || flight.raw?.isMultiCity || flight.raw?.type === 'multicity') {
        const slices = Array.isArray(flight.segments)
          ? flight.segments
          : (Array.isArray(flight.raw?.segments) ? flight.raw.segments : []);
        if (slices.length) {
          const lastSlice = slices[slices.length - 1];
          const firstSlice = slices[0];
          if (Array.isArray(lastSlice) && lastSlice.length) {
            const lastSeg = lastSlice[lastSlice.length - 1];
            arrivalRaw = lastSeg?.arrival_date || arrivalRaw;
          }
          if (Array.isArray(firstSlice) && firstSlice.length) {
            departureRaw = firstSlice[0]?.departure_date || departureRaw;
          }
        }
      }
      const arrival = this._parseStayDate(arrivalRaw);
      const departure = this._parseStayDate(departureRaw);
      const checkinDate = arrival || departure;
      if (!checkinDate) return;

      const nights = this.stayNightsCount(
        params.checkin,
        params.checkout,
        params.duration_days || params.nights || 1
      );
      // Keep the stay length from check-in→check-out (same as normal stays).
      // Do not re-inflate from a stale duration_days (e.g. inclusive "3 days").
      const useNights = nights > 0 ? nights : 1;
      const newIn = this._formatStayDate(checkinDate);
      const outDate = new Date(checkinDate.getFullYear(), checkinDate.getMonth(), checkinDate.getDate() + useNights);
      const newOut = this._formatStayDate(outDate);
      if (String(params.checkin || '') === newIn && String(params.checkout || '') === newOut) {
        return;
      }

      stays.params = Object.assign({}, params, {
        checkin: newIn,
        checkout: newOut,
        nights: useNights,
        duration_days: useNights,
      });
      // Rebuild listing_url if present
      if (stays.listing_url && typeof stays.listing_url === 'string') {
        const parts = stays.listing_url.split('/');
        // stays/{slug}/{checkin}/{checkout}/...
        if (parts.length >= 4 && parts[0] === 'stays') {
          parts[2] = newIn;
          parts[3] = newOut;
          stays.listing_url = parts.join('/');
        }
      }

      // Previous hotel rates were for the old nights — clear pick and reload
      this.selected = (this.selected || []).filter((x) => x.module !== 'stays');
      this._cartSave();
      stays.loading = true;
      stays.items = [];
      this._loadSection(staysIdx);
    },
    stayPricePerNight(item, section) {
      if (!item) return 0;
      const nights = this.stayNightsFor(item, section);
      const total = Number(item.price) || 0;
      let direct = Number(item.price_per_night) || 0;
      // Stay total mistakenly stored as price_per_night (e.g. RateHawk search)
      if (direct > 0 && total > 0 && nights > 1 && Math.abs(direct - total) < 0.02) {
        direct = Math.round(((total / nights) + Number.EPSILON) * 100) / 100;
      }
      if (direct > 0) return Math.round((direct + Number.EPSILON) * 100) / 100;
      if (total > 0 && nights > 0) {
        return Math.round(((total / nights) + Number.EPSILON) * 100) / 100;
      }
      return 0;
    },
    stayStars(item) {
      const n = parseInt(item?.stars || item?.star_rating || 0, 10) || 0;
      return Math.max(0, Math.min(5, n));
    },
    stayGuestRating(item) {
      const n = parseFloat(item?.rating || 0) || 0;
      return n > 0 ? Math.round(n * 10) / 10 : 0;
    },
    tourDateLabel(item, section) {
      return this.formatTravelDate(
        (item && item.start_date) || (section && section.params && section.params.start_date) || ''
      );
    },
    tourStars(item) {
      const n = parseInt(item?.stars || item?.star_rating || 0, 10) || 0;
      return Math.max(0, Math.min(5, n));
    },
    tourGuestRating(item) {
      const n = parseFloat(item?.rating || 0) || 0;
      return n > 0 ? Math.round(n * 10) / 10 : 0;
    },
    _tourDays(item) {
      const raw = item?.days ?? item?.duration ?? item?.nights ?? 0;
      const n = parseInt(String(raw).replace(/[^\d]/g, ''), 10);
      return Number.isFinite(n) && n > 0 ? n : 0;
    },
    tourDurationLabel(item) {
      if (!item) return '';
      if (item.duration_formatted) return String(item.duration_formatted);
      const days = this._tourDays(item);
      if (days > 0) return days === 1 ? '1 day' : days + ' days';
      if (item.nights) {
        const n = parseInt(item.nights, 10) || 0;
        if (n > 0) return n === 1 ? '1 night' : n + ' nights';
      }
      return '';
    },
    tourInclusionLabels(item, limit) {
      const list = Array.isArray(item?.inclusions) ? item.inclusions : [];
      const names = list.map((inc) => {
        if (typeof inc === 'string') return inc.trim();
        return String(inc?.name || inc?.title || '').trim();
      }).filter(Boolean);
      const max = limit == null ? names.length : Math.max(0, Number(limit) || 0);
      return names.slice(0, max);
    },
    tourExclusionLabels(item, limit) {
      const list = Array.isArray(item?.exclusions) ? item.exclusions : [];
      const names = list.map((exc) => {
        if (typeof exc === 'string') return exc.trim();
        return String(exc?.name || exc?.title || '').trim();
      }).filter(Boolean);
      const max = limit == null ? names.length : Math.max(0, Number(limit) || 0);
      return names.slice(0, max);
    },
    tourCacheKey(item) {
      const id = String(item?.tour_id || item?.id || '');
      const supplier = String(item?.supplier || 'tours').toLowerCase();
      return id ? supplier + '::' + id : '';
    },
    isTourExpanded(item) {
      return !!(item && this.expandedTourId === this.tourCacheKey(item));
    },
    tourDetailsFor(item) {
      const key = this.tourCacheKey(item);
      return key ? (this.tourDetailsById[key] || null) : null;
    },
    isTourDetailsLoading(item) {
      return this.tourDetailsLoadingId === this.tourCacheKey(item);
    },
    tourDetailsError(item) {
      const key = this.tourCacheKey(item);
      return key ? (this.tourDetailsErrorById[key] || '') : '';
    },
    _tourPlainText(value) {
      return String(value || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>/gi, '\n')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n\s*\n+/g, '\n')
        .trim();
    },
    tourDescription(item) {
      const detail = this.tourDetailsFor(item);
      return this._tourPlainText(detail?.description || item?.description || '');
    },
    tourItinerary(item) {
      const detail = this.tourDetailsFor(item);
      if (Array.isArray(detail?.itinerary) && detail.itinerary.length) return detail.itinerary;
      return Array.isArray(item?.itinerary) ? item.itinerary : [];
    },
    tourCancellationPolicy(item) {
      const detail = this.tourDetailsFor(item);
      return this._tourPlainText(
        detail?.cancellation_policy || detail?.cancellationPolicy
        || item?.cancellation_policy || item?.cancellationPolicy || ''
      );
    },
    tourTermsConditions(item) {
      const detail = this.tourDetailsFor(item);
      return this._tourPlainText(
        detail?.terms_conditions || detail?.termsConditions || detail?.terms
        || item?.terms_conditions || item?.termsConditions || item?.terms || ''
      );
    },
    isTourDayOpen(item, dayIdx) {
      const key = this.tourCacheKey(item);
      return !!(this.tourDayOpenById[key] && this.tourDayOpenById[key][dayIdx]);
    },
    toggleTourDay(item, dayIdx) {
      const key = this.tourCacheKey(item);
      if (!key) return;
      const openDays = { ...(this.tourDayOpenById[key] || {}) };
      openDays[dayIdx] = !openDays[dayIdx];
      this.tourDayOpenById = { ...this.tourDayOpenById, [key]: openDays };
    },
    tourItineraryCoordinates(item) {
      const detail = this.tourDetailsFor(item) || item || {};
      const points = this.tourItinerary(item).map((day) => ({
        lat: Number(day?.latitude ?? day?.lat ?? day?.location_latitude),
        lng: Number(day?.longitude ?? day?.lng ?? day?.lon ?? day?.location_longitude),
      })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng)
        && Math.abs(point.lat) <= 90 && Math.abs(point.lng) <= 180);
      if (!points.length) {
        const lat = Number(detail.latitude ?? detail.lat);
        const lng = Number(detail.longitude ?? detail.lng ?? detail.lon);
        if (Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
          points.push({ lat, lng });
        }
      }
      return points;
    },
    tourItineraryMapUrl(item) {
      const points = this.tourItineraryCoordinates(item);
      if (!points.length) return '';
      const lats = points.map((point) => point.lat);
      const lngs = points.map((point) => point.lng);
      let minLat = Math.min(...lats);
      let maxLat = Math.max(...lats);
      let minLng = Math.min(...lngs);
      let maxLng = Math.max(...lngs);
      const latPad = Math.max(0.02, (maxLat - minLat) * 0.15);
      const lngPad = Math.max(0.02, (maxLng - minLng) * 0.15);
      minLat -= latPad; maxLat += latPad;
      minLng -= lngPad; maxLng += lngPad;
      const marker = points[0];
      return 'https://www.openstreetmap.org/export/embed.html?bbox='
        + encodeURIComponent([minLng, minLat, maxLng, maxLat].join(','))
        + '&layer=mapnik&marker=' + encodeURIComponent(marker.lat + ',' + marker.lng);
    },
    async toggleTourDetails(item, section) {
      if (!item) return;
      const key = this.tourCacheKey(item);
      if (!key) return;
      if (this.expandedTourId === key) {
        this.expandedTourId = null;
        return;
      }
      this.expandedTourId = key;
      if (!this.tourDetailsById[key]) {
        await this.loadTourDetails(item, section);
      }
      if (this.tourItinerary(item).length
        && !(this.tourDayOpenById[key] && Object.keys(this.tourDayOpenById[key]).length)) {
        this.tourDayOpenById = { ...this.tourDayOpenById, [key]: { 0: true } };
      }
    },
    async loadTourDetails(item, section) {
      const key = this.tourCacheKey(item);
      if (!key || this.tourDetailsById[key]) return;
      const supplier = String(item?.supplier || 'tours').toLowerCase();
      this.tourDetailsLoadingId = key;
      this.tourDetailsErrorById = { ...this.tourDetailsErrorById, [key]: '' };
      try {
        const startDate = String(item?.start_date || section?.params?.start_date || '');
        const adults = this.tourAdultsCount(item, section);
        const children = this.tourChildrenCount(item, section);
        const response = await fetch(this.root + 'modules/tours/' + encodeURIComponent(supplier) + '/details', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            tour_id: item.tour_id || item.id,
            supplier,
            departure_date: startDate,
            start_date: startDate,
            duration: item.days || section?.params?.duration || '',
            total_adults: adults,
            total_children: children,
            adults,
            children,
            currency: this.currency || item.currency || '',
          }),
        });
        const json = await response.json().catch(() => null);
        if (!response.ok || !json || !json.success || !json.data) {
          throw new Error(json?.message || 'Failed to load tour details.');
        }
        this.tourDetailsById = { ...this.tourDetailsById, [key]: json.data };
        ['inclusions', 'exclusions', 'itinerary'].forEach((field) => {
          if (Array.isArray(json.data[field]) && json.data[field].length) item[field] = json.data[field];
        });
        ['description', 'cancellation_policy', 'terms_conditions', 'latitude', 'longitude', 'address'].forEach((field) => {
          if (json.data[field] !== undefined && json.data[field] !== null && json.data[field] !== '') {
            item[field] = json.data[field];
          }
        });
      } catch (error) {
        this.tourDetailsErrorById = {
          ...this.tourDetailsErrorById,
          [key]: error?.message || 'Failed to load tour details.',
        };
      } finally {
        if (this.tourDetailsLoadingId === key) this.tourDetailsLoadingId = '';
      }
    },
    umrahCacheKey(item) {
      return String(item?.umrah_id || item?.id || '');
    },
    isUmrahExpanded(item) {
      return !!(item && this.expandedUmrahId && String(this.expandedUmrahId) === this.umrahCacheKey(item));
    },
    umrahDetailsFor(item) {
      const key = this.umrahCacheKey(item);
      return key ? (this.umrahDetailsById[key] || null) : null;
    },
    isUmrahDetailsLoading(item) {
      return this.umrahDetailsLoadingId === this.umrahCacheKey(item);
    },
    umrahDetailsError(item) {
      const key = this.umrahCacheKey(item);
      return key ? (this.umrahDetailsErrorById[key] || '') : '';
    },
    umrahInclusionLabels(item, limit) {
      const detail = this.umrahDetailsFor(item);
      const raw = (detail && Array.isArray(detail.inclusions) && detail.inclusions.length)
        ? detail.inclusions
        : (Array.isArray(item?.inclusions) ? item.inclusions : []);
      const names = raw.map((inc) => {
        if (typeof inc === 'string') return inc.trim();
        return String(inc?.name || inc?.title || '').trim();
      }).filter(Boolean);
      const max = limit == null ? names.length : Math.max(0, Number(limit) || 0);
      return names.slice(0, max);
    },
    /** "1 Adult · 1 Child · 1 Infant" from item / section params (prompt counts). */
    umrahTravelerLabel(item, section) {
      const p = (section && section.params) || {};
      const adults = Number(item?.adults ?? p.adults ?? 0) || 0;
      const children = Number(item?.children ?? p.children ?? 0) || 0;
      const infants = Number(item?.infants ?? p.infants ?? 0) || 0;
      const bits = [];
      if (adults > 0) bits.push(adults + ' Adult' + (adults === 1 ? '' : 's'));
      if (children > 0) bits.push(children + ' Child' + (children === 1 ? '' : 'ren'));
      if (infants > 0) bits.push(infants + ' Infant' + (infants === 1 ? '' : 's'));
      return bits.join(' · ');
    },
    umrahItinerary(item) {
      const detail = this.umrahDetailsFor(item);
      if (detail && Array.isArray(detail.itinerary)) return detail.itinerary;
      return Array.isArray(item?.itinerary) ? item.itinerary : [];
    },
    umrahDescription(item) {
      const detail = this.umrahDetailsFor(item);
      const fromDetail = String(detail?.description || '').trim();
      if (fromDetail) return fromDetail.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      return String(item?.description || '').trim();
    },
    isUmrahDayOpen(item, dayIdx) {
      const key = this.umrahCacheKey(item);
      const map = this.umrahDayOpenById[key];
      return !!(map && map[dayIdx]);
    },
    toggleUmrahDay(item, dayIdx) {
      const key = this.umrahCacheKey(item);
      if (!key) return;
      const prev = { ...(this.umrahDayOpenById[key] || {}) };
      prev[dayIdx] = !prev[dayIdx];
      this.umrahDayOpenById = { ...this.umrahDayOpenById, [key]: prev };
    },
    async toggleUmrahDetails(item, section) {
      if (!item) return;
      const id = this.umrahCacheKey(item);
      if (!id) return;
      if (this.expandedUmrahId === id) {
        this.expandedUmrahId = null;
        return;
      }
      this.expandedUmrahId = id;
      if (!this.umrahDetailsById[id]) {
        await this.loadUmrahDetails(item, section);
      }
      // Open first itinerary day by default once loaded
      const itin = this.umrahItinerary(item);
      if (itin.length && !(this.umrahDayOpenById[id] && Object.keys(this.umrahDayOpenById[id]).length)) {
        this.umrahDayOpenById = { ...this.umrahDayOpenById, [id]: { 0: true } };
      }
    },
    async loadUmrahDetails(item, section) {
      const id = this.umrahCacheKey(item);
      if (!id || this.umrahDetailsById[id]) return;
      this.umrahDetailsLoadingId = id;
      this.umrahDetailsErrorById = { ...this.umrahDetailsErrorById, [id]: '' };
      try {
        const startDate = String(
          item.start_date || section?.params?.start_date || section?.params?.start_date_ymd || ''
        );
        const r = await fetch(this.root + 'modules/umrah/umrah/details', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            umrah_id: Number(item.umrah_id || id) || 0,
            start_date: startDate,
            departure_date: startDate,
            currency: this.currency || item.currency || '',
            supplier: 'umrah',
          }),
        });
        const json = await r.json().catch(() => null);
        if (!r.ok || !json || !json.success || !json.data) {
          throw new Error(json?.message || 'Failed to load package details.');
        }
        this.umrahDetailsById = { ...this.umrahDetailsById, [id]: json.data };
        if (Array.isArray(json.data.inclusions) && json.data.inclusions.length) {
          item.inclusions = json.data.inclusions;
        }
        if (json.data.description) {
          item.description = String(json.data.description).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        }
      } catch (e) {
        this.umrahDetailsErrorById = {
          ...this.umrahDetailsErrorById,
          [id]: e?.message || 'Failed to load package details.',
        };
      } finally {
        if (this.umrahDetailsLoadingId === id) this.umrahDetailsLoadingId = '';
      }
    },
    tourPricePerAdult(item) {
      const n = parseFloat(item?.price_per_adult || item?.price_per_person || 0) || 0;
      return n > 0 ? Math.round((n + Number.EPSILON) * 100) / 100 : 0;
    },
    tourPricePerChild(item) {
      const n = parseFloat(item?.price_per_child || 0) || 0;
      return n > 0 ? Math.round((n + Number.EPSILON) * 100) / 100 : 0;
    },
    tourAdultsCount(item, section) {
      const fromItem = parseInt(item?.adults, 10);
      if (Number.isFinite(fromItem) && fromItem > 0) return fromItem;
      const fromSection = parseInt(section?.params?.adults, 10);
      return Number.isFinite(fromSection) && fromSection > 0 ? fromSection : 1;
    },
    tourChildrenCount(item, section) {
      const fromItem = parseInt(item?.children, 10);
      if (Number.isFinite(fromItem) && fromItem >= 0) return fromItem;
      const fromSection = parseInt(section?.params?.children, 10);
      return Number.isFinite(fromSection) && fromSection >= 0 ? fromSection : 0;
    },
    tourTravelerRange(max, min) {
      const lo = Math.max(0, Number(min) || 0);
      const hi = Math.max(lo, Number(max) || lo);
      const out = [];
      for (let i = lo; i <= hi; i++) out.push(i);
      return out;
    },
    tourTotalPrice(item, section) {
      if (!item) return 0;
      const adults = this.tourAdultsCount(item, section);
      const children = this.tourChildrenCount(item, section);
      const adultPrice = this.tourPricePerAdult(item);
      const childPrice = this.tourPricePerChild(item);
      if (adultPrice > 0) {
        return Math.round(((adults * adultPrice) + (children * childPrice) + Number.EPSILON) * 100) / 100;
      }
      return Math.round(((Number(item.price) || 0) + Number.EPSILON) * 100) / 100;
    },
    setTourAdults(section, item, value) {
      if (!item) return;
      const max = Math.max(1, parseInt(item.max_adults || 10, 10) || 10);
      const n = Math.max(1, Math.min(max, parseInt(value, 10) || 1));
      item.adults = String(n);
      this._syncTourTravelerPrice(section, item);
    },
    setTourChildren(section, item, value) {
      if (!item) return;
      const max = Math.max(0, parseInt(item.max_children || 6, 10) || 6);
      const n = Math.max(0, Math.min(max, parseInt(value, 10) || 0));
      item.children = String(n);
      this._syncTourTravelerPrice(section, item);
    },
    _syncTourTravelerPrice(section, item) {
      if (!item) return;
      const total = this.tourTotalPrice(item, section);
      item.price = total;
      const sel = this.selectionForModule(section?.module || 'tours');
      if (!sel || !sel.item) return;
      if (String(sel.item.id || '') !== String(item.id || '')) return;
      if (String(sel.supplier || '') !== String(item.supplier || '')) return;
      sel.item.adults = item.adults;
      sel.item.children = item.children;
      sel.item.price = total;
      sel.item.price_per_adult = item.price_per_adult;
      sel.item.price_per_child = item.price_per_child;
      sel.price = total;
      if (!sel.params || typeof sel.params !== 'object') sel.params = {};
      sel.params.adults = item.adults;
      sel.params.children = item.children;
      this._cartSave();
    },
    airlineLogo(item) {
      if (!item) return '';
      const code = String(item.img || item.airline || '').trim();
      if (!code || code === 'undefined' || code === 'null') return '';
      return 'https://pics.avs.io/200/200/' + encodeURIComponent(code) + '@2x.png';
    },
    /** Image for review/summary cards (flights use airline logo from code). */
    selectionImage(sel) {
      if (!sel) return '';
      if (sel.image) return sel.image;
      const mod = String(sel.module || sel.kind || '').toLowerCase();
      if (mod === 'flights' || mod === 'flight') {
        return this.airlineLogo(sel.item || sel) || '';
      }
      return (sel.item && sel.item.image) || '';
    },
    /** Focus results panel (replaces old side drawer). */
    openDrawer(mod) {
      this.showFinalSummary = false;
      // After Back from booking, sections may be empty — refresh results then show panel
      if (!(this.sections || []).length && (this.query || '').trim().length >= 10) {
        this._runSearch({ keepSelections: true });
        return;
      }
      if (mod) {
        this.setDrawerModule(mod);
        return;
      }
      if (!this.drawerModule && this.sections.length) {
        this.drawerModule = this.sections[0].module || '';
      }
      const next = (this.sections || []).find((s) => !this.moduleComplete(s.module));
      if (next) {
        this.setDrawerModule(next.module);
        return;
      }
      // Back on a previously skipped tab → require selection again
      if (this.drawerModule) this._clearModuleSkip(this.drawerModule);
      this.$nextTick(() => this.scrollResultsTop());
    },
    async goToBooking() {
      if (this.bookingRedirecting) return;
      if (!this.wizardComplete) {
        const next = (this.sections || []).find((s) => this.moduleNeedsSelection(s))
          || (this.sections || []).find((s) => !this.moduleComplete(s.module));
        const label = next ? this.moduleLabel(next.module) : 'all steps';
        this._toastWarn('Please select a ' + label.toLowerCase() + ' before continuing to booking.');
        this.showFinalSummary = false;
        if (next && next.module) this.setDrawerModule(next.module);
        return;
      }
      const items = this._bookingItemsPayload();
      if (!items.length) {
        this._toastWarn('No trip items selected.');
        return;
      }
      this._cartSave();
      this._ssSet(RESUME_KEY, '1');
      this.bookingRedirecting = true;
      try {
        const body = JSON.stringify({
          type: 'ai_trip',
          query: this.query || '',
          currency: this.currency,
          items: items,
          module_order: (this.sections || []).map((s) => s.module).filter(Boolean),
          total: items.reduce((sum, x) => {
            const mod = String(x.module || x.kind || '').toLowerCase();
            if (mod === 'visa' || mod === 'visas') return sum;
            return sum + this.convertToSessionCurrency(x.price, x.currency || this.currency);
          }, 0),
          created_at: new Date().toISOString()
        });
        const res = await fetch(this.root + 'api/ai/trip/save-draft', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          credentials: 'same-origin',
          body: body
        });
        const data = await res.json().catch(() => null);
        if (data && data.success && data.hash) {
          window.location.href = this.root + 'ai-trip/booking/' + data.hash;
          return;
        }
        alert((data && data.message) || 'Could not prepare booking. Please try again.');
      } catch (e) {
        alert('Could not prepare booking. Please try again.');
      } finally {
        this.bookingRedirecting = false;
      }
    },

    formatSuggestionChip(label) {
      return String(label || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/gi, (_, icon, hex) => {
          const color = hex ? '#' + hex : '#0058E6';
          return '<span class="material-symbols-outlined text-sm align-middle shrink-0" style="color:' + color + '">' + icon + '</span>';
        });
    },
    previewSuggestions() {
      const list = Array.isArray(this.suggestions) ? this.suggestions : [];
      return list.slice(0, this.suggestionsPreview || 5);
    },
    moreSuggestions() {
      const list = Array.isArray(this.suggestions) ? this.suggestions : [];
      return list.slice(this.suggestionsPreview || 5);
    },
    hasMoreSuggestions() {
      return this.moreSuggestions().length > 0;
    },
    suggestionIconToken(s) {
      const label = String((s && s.label) || '');
      const m = label.match(/^:[a-z0-9_]+:(?:#[0-9A-Fa-f]{3,8}:)?/i);
      return m ? m[0] : ':auto_awesome:#0058E6:';
    },
    applySuggestion(s) {
      if (!s) return;
      this.query = String(s.query || s.label || '').trim();
      this.error = '';
      this.searchExpanded = true;
      this.suggestionsMoreOpen = false;
    },

    // ── Departure context (geolocation / last-used / manual) ─────
    departureIndicatorLabel() {
      if (this.departureLoading) return 'Detecting departure…';
      if (this.departureLocationDenied) return 'Location unavailable';
      if (this.departureCity) return 'Departing from ' + this.departureCity;
      return 'Add departure city';
    },

    openDepartureEditor() {
      this.departureEditOpen = true;
      this.departureSearch = this.departureCity || '';
      this.departureResults = [];
      if ((this.departureSearch || '').trim().length >= 2) {
        this.searchDepartureAirports();
      }
    },

    async resolveDepartureContext() {
      this.departureLoading = true;
      this.departureLocationDenied = false;
      this.departureLocationError = '';
      try {
        const permission = await this._geolocationPermission();
        // Only a granted permission resolves fast enough to hold the search back.
        this._departureWaitMs = permission === 'granted' ? 4000 : (permission === 'denied' ? 0 : 1200);
        
        if (permission === 'denied') {
          this.departureLocationDenied = true;
          this.departureLocationError = 'Turn on location in your browser settings. We use it as your departure city.';
        }
        
        this._watchGeolocationPermission();
        const geo = permission === 'denied' ? null : await this._detectDepartureFromGeolocation();
        if (geo && geo.city) {
          this._setDeparture(geo.city, geo.country || '', geo.airport_code || '', 'geolocation', true);
          this._applyNationalityFromGeo(geo);
          return;
        }
        if (this._requireLiveGeo) {
          this.departureNeedsInput = true;
          return;
        }
        const last = this._loadLastDeparture();
        if (last && last.city) {
          // Skip stale geo leftovers where the "city" is only a random IATA (e.g. ADV)
          const cityUp = String(last.city || '').trim().toUpperCase();
          const airportUp = String(last.airport || '').trim().toUpperCase();
          const codeOnly = /^[A-Z]{3}$/.test(cityUp)
            && (!airportUp || airportUp === cityUp);
          if (!codeOnly) {
            this._setDeparture(last.city, last.country || '', last.airport || '', 'last_used', false);
            return;
          }
        }
        const session = (cfg.sessionDeparture && typeof cfg.sessionDeparture === 'object')
          ? cfg.sessionDeparture
          : {};
        const sessAirport = String(session.airport || '').trim().toUpperCase();
        const sessCity = String(session.city || '').trim();
        if (sessCity || /^[A-Z]{3}$/.test(sessAirport)) {
          this._setDeparture(
            sessCity || sessAirport,
            '',
            /^[A-Z]{3}$/.test(sessAirport) ? sessAirport : '',
            'session',
            false
          );
          return;
        }
        this.departureNeedsInput = true;
      } finally {
        this.departureLoading = false;
        this._maybeRerunForDeparture();
      }
    },

    _geolocationPermission() {
      return new Promise((resolve) => {
        if (!navigator.geolocation) {
          resolve('denied');
          return;
        }
        if (!navigator.permissions || !navigator.permissions.query) {
          resolve('unknown');
          return;
        }
        navigator.permissions.query({ name: 'geolocation' })
          .then((status) => resolve(status && status.state ? status.state : 'unknown'))
          .catch(() => resolve('unknown'));
      });
    },

    /** Permission can be granted from browser UI after the first search ran. */
    _watchGeolocationPermission() {
      if (this._geoPermissionWatched) return;
      this._geoPermissionWatched = true;
      if (!navigator.permissions || !navigator.permissions.query) return;
      navigator.permissions.query({ name: 'geolocation' }).then((status) => {
        if (!status || typeof status.addEventListener !== 'function') return;
        status.addEventListener('change', () => {
          if (status.state !== 'granted') return;
          if (this.departureSource === 'manual') return;
          this._departureReady = this.resolveDepartureContext();
        });
      }).catch(() => {});
    },

    _detectDepartureFromGeolocation() {
      return new Promise((resolve) => {
        if (!navigator.geolocation) {
          resolve(null);
          return;
        }
        let settled = false;
        const finish = (value) => {
          if (settled) return;
          settled = true;
          resolve(value);
        };
        // Generous window: the permission prompt can stay open for a while and the
        // search no longer blocks on this promise.
        const timer = setTimeout(() => finish(null), 12000);
        navigator.geolocation.getCurrentPosition(
          async (pos) => {
            clearTimeout(timer);
            try {
              const lat = pos?.coords?.latitude;
              const lng = pos?.coords?.longitude;
              if (typeof lat !== 'number' || typeof lng !== 'number') {
                finish(null);
                return;
              }
              const res = await fetch(this.root + 'api/ai/reverse-geocode', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-Token': this.csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                  csrf_token: this.csrfToken || '',
                  lat,
                  lng,
                }),
              });
              const json = await res.json().catch(() => null);
              if (json && json.success && json.data && json.data.city) {
                finish(json.data);
                return;
              }
            } catch (e) { /* ignore */ }
            finish(null);
          },
          () => {
            clearTimeout(timer);
            if (!this.departureLocationDenied) {
              this.departureLocationDenied = true;
              this.departureLocationError = 'Turn on location in your browser settings. We use it as your departure city.';
            }
            finish(null);
          },
          { enableHighAccuracy: false, timeout: 11000, maximumAge: 600000 }
        );
      });
    },

    _loadLastDeparture() {
      try {
        const raw = localStorage.getItem(DEPARTURE_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || typeof data !== 'object') return null;
        const city = String(data.city || '').trim();
        if (!city) return null;
        return {
          city,
          country: String(data.country || '').trim(),
          airport: String(data.airport || '').trim().toUpperCase(),
        };
      } catch (e) {
        return null;
      }
    },

    _persistDeparture() {
      try {
        if (!this.departureCity) {
          localStorage.removeItem(DEPARTURE_KEY);
          return;
        }
        localStorage.setItem(DEPARTURE_KEY, JSON.stringify({
          city: this.departureCity,
          country: this.departureCountry || '',
          airport: this.departureAirport || '',
          // No coordinates — privacy
          saved_at: new Date().toISOString(),
        }));
      } catch (e) { /* ignore quota / private mode */ }
    },

    _setDeparture(city, country, airport, source, persist) {
      this.departureCity = String(city || '').trim();
      this.departureCountry = String(country || '').trim();
      this.departureAirport = String(airport || '').trim().toUpperCase();
      this.departureSource = String(source || '').trim();
      this.departureNeedsInput = !this.departureCity;
      if (this.departureCity) {
        this.departureLocationDenied = false;
      }
      if (persist !== false && this.departureCity) {
        this._persistDeparture();
      }
    },

    /** True when the last search ran without the departure we now have. */
    _shouldRerunForDeparture() {
      if (this._departureRerunDone) return false;
      if (!this.departureCity) return false;
      // Only rescue a search that ran with no departure at all.
      if (this._searchedDepartureCity) return false;
      if ((this.query || '').trim().length < 10) return false;
      const flight = (this.sections || []).find((s) => s.module === 'flights');
      if (!flight) return false;
      const p = flight.params || {};
      // Only when the prompt itself never named an origin.
      return p.needs_departure === '1' || p.needs_departure === true || !flight.searchable;
    },

    _maybeRerunForDeparture() {
      if (this.loading) {
        this._pendingDepartureRerun = true;
        return;
      }
      this._pendingDepartureRerun = false;
      if (this.departureLocationRequired && this._hasLiveGeoDeparture() && (this.query || '').trim().length >= 10) {
        this.departureLocationRequired = false;
        this.departureLocationDenied = false;
        this._runSearch();
        return;
      }
      if (!this._shouldRerunForDeparture()) {
        this._maybeApplyGeoNationalityToStays();
        return;
      }
      this._departureRerunDone = true;
      this._runSearch({ keepSelections: true });
    },

    stayNeedsNationality(section) {
      const s = section && typeof section === 'object'
        ? section
        : this.sectionForModule('stays');
      if (!s || s.module !== 'stays') return false;
      const p = s.params || {};
      return p.nationality_required === '1' || p.nationality_required === true
        || !String(p.nationality || this.nationalityIso || '').trim();
    },
    stayNationalityIso(section) {
      const fromSection = String((section && section.params && section.params.nationality) || '').trim().toUpperCase();
      if (/^[A-Z]{2}$/.test(fromSection)) return fromSection;
      return String(this.nationalityIso || '').toUpperCase();
    },
    stayNationalitySource(section) {
      const fromSection = String((section && section.params && section.params.nationality_source) || '').trim();
      return fromSection || this.nationalitySource || '';
    },
    stayCountryName(iso) {
      const code = String(iso || '').toUpperCase();
      const row = (this.stayCountries || []).find((c) => String(c.iso || '').toUpperCase() === code);
      return row ? (row.nicename || code) : code;
    },
    stayNationalityLabel(section) {
      const iso = this.stayNationalityIso(section);
      const name = this.stayCountryName(iso);
      if (iso && name) return name;
      return 'Select nationality';
    },
    filterStayNatCountries() {
      const query = String(this.stayNatQuery || '').toLowerCase().trim();
      const list = Array.isArray(this.stayCountries) ? this.stayCountries : [];
      if (!query) {
        this.stayNatFiltered = list.slice();
        return;
      }
      this.stayNatFiltered = list.filter((c) => {
        const name = String(c.nicename || '').toLowerCase();
        const iso = String(c.iso || '').toLowerCase();
        return name.indexOf(query) !== -1 || iso.indexOf(query) !== -1;
      });
    },
    closeStayNatPanel() {
      this.stayNatOpen = false;
      this.stayNatQuery = '';
      this.filterStayNatCountries();
    },
    toggleStayNatPanel() {
      this.stayNatOpen = !this.stayNatOpen;
      if (this.stayNatOpen) {
        this.stayNatQuery = '';
        this.filterStayNatCountries();
        this.$nextTick(() => {
          const input = document.getElementById('ai_nat_q');
          if (input) input.focus();
        });
      } else {
        this.closeStayNatPanel();
      }
    },
    selectStayNatCountry(country) {
      const iso = String((country && country.iso) || '').toUpperCase();
      this.closeStayNatPanel();
      if (iso) this.setStayNationality(iso, 'manual');
    },
    _isoFromCountryLabel(value) {
      const raw = String(value || '').trim();
      if (!raw) return '';
      const upper = raw.toUpperCase();
      if (/^[A-Z]{2}$/.test(upper)) {
        if (!(this.stayCountries || []).length) return upper;
        const hit = (this.stayCountries || []).find((c) => String(c.iso || '').toUpperCase() === upper);
        return hit ? upper : '';
      }
      const aliases = {
        UAE: 'AE', UK: 'GB', USA: 'US', KSA: 'SA', HOLLAND: 'NL', ENGLAND: 'GB',
      };
      const compact = upper.replace(/[^A-Z]/g, '');
      if (aliases[compact]) return aliases[compact];
      const lower = raw.toLowerCase();
      const byName = (this.stayCountries || []).find((c) => String(c.nicename || '').toLowerCase() === lower);
      return byName ? String(byName.iso || '').toUpperCase() : '';
    },
    _restoreNationality() {
      const last = this._loadLastNationality();
      if (last && last.iso) {
        this.nationalityIso = last.iso;
        this.nationalitySource = last.source || 'last_used';
        return;
      }
      const sess = String(cfg.sessionNationality || '').trim().toUpperCase();
      if (/^[A-Z]{2}$/.test(sess)) {
        this.nationalityIso = sess;
        this.nationalitySource = 'session';
      }
    },
    _loadLastNationality() {
      try {
        const raw = localStorage.getItem(NATIONALITY_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || typeof data !== 'object') return null;
        const iso = this._isoFromCountryLabel(data.iso || '');
        if (!iso) return null;
        return { iso, source: String(data.source || 'last_used') };
      } catch (e) {
        return null;
      }
    },
    _persistNationality() {
      try {
        if (!this.nationalityIso || !/^[A-Z]{2}$/.test(this.nationalityIso)) {
          localStorage.removeItem(NATIONALITY_KEY);
          return;
        }
        localStorage.setItem(NATIONALITY_KEY, JSON.stringify({
          iso: this.nationalityIso,
          source: this.nationalitySource || '',
          saved_at: new Date().toISOString(),
        }));
      } catch (e) { /* ignore quota / private mode */ }
    },
    _applyNationalityFromGeo(geo) {
      if (!geo || this.nationalitySource === 'manual') return;
      const iso = this._isoFromCountryLabel(geo.country_code || '')
        || this._isoFromCountryLabel(geo.country || '');
      if (!iso) return;
      this.nationalityIso = iso;
      this.nationalitySource = 'geolocation';
      this._persistNationality();
      this._maybeApplyGeoNationalityToStays();
    },
    _maybeApplyGeoNationalityToStays() {
      if (this.loading || this._nationalityRerunDone) return;
      if (this.nationalitySource === 'manual') return;
      const iso = String(this.nationalityIso || '').toUpperCase();
      if (!/^[A-Z]{2}$/.test(iso)) return;
      const stays = (this.sections || []).find((s) => s.module === 'stays');
      if (!stays || !this.stayNeedsNationality(stays)) return;
      this._nationalityRerunDone = true;
      this.setStayNationality(iso, this.nationalitySource || 'geolocation');
    },
    async setStayNationality(iso, source) {
      const code = this._isoFromCountryLabel(iso);
      if (!code) return;
      this.nationalityIso = code;
      this.nationalitySource = source || 'manual';
      this._persistNationality();
      const stays = (this.sections || []).find((s) => s.module === 'stays');
      if (!stays) return;
      const params = Object.assign({}, stays.params || {}, {
        nationality: code,
        nationality_source: this.nationalitySource,
        nationality_required: '0',
      });
      stays.params = params;
      stays.searchable = Array.isArray(stays.suppliers) && stays.suppliers.length > 0;
      if (stays.listing_url && typeof stays.listing_url === 'string') {
        const parts = stays.listing_url.split('/');
        if (parts[0] === 'stays' && parts.length >= 5) {
          parts[4] = code;
          stays.listing_url = parts.join('/');
        } else if (parts[0] === 'stays' && parts.length <= 2) {
          const dest = encodeURIComponent(String(params.destination_code || params.destination || 'hotel').toLowerCase().replace(/\s+/g, '-'));
          const rooms = String(params.rooms || '1');
          stays.listing_url = 'stays/' + dest + '/' + (params.checkin || '') + '/' + (params.checkout || '')
            + '/' + code + '/' + rooms + '/' + rooms + '-0';
        }
      }
      this.selected = (this.selected || []).filter((x) => x.module !== 'stays');
      this._cartSave();
      this.stayRoomsByHotel = {};
      this.stayExpandedId = '';
      stays.items = [];
      stays.error = '';
      stays.emptyMessage = '';
      const idx = this.sections.indexOf(stays);
      if (idx >= 0) await this._loadSection(idx);
    },

    esimCountryIso(section) {
      return String((section && section.params && section.params.country) || '').trim().toUpperCase();
    },
    esimPackageType(section) {
      const t = String((section && section.params && section.params.package_type) || 'all').trim().toLowerCase();
      return (t === 'local' || t === 'global' || t === 'all') ? t : 'all';
    },
    esimNeedsCountry(section) {
      if (!section || section.module !== 'esim') return false;
      const moduleId = String((section.params && section.params.module_id) || '').trim();
      if (!moduleId) return false;
      return !/^[A-Z]{2}$/.test(this.esimCountryIso(section));
    },
    esimCountryName(iso) {
      const code = String(iso || '').toUpperCase();
      const row = (this.esimCountries || []).find((c) => String(c.iso || '').toUpperCase() === code);
      return row ? (row.nicename || code) : code;
    },
    async setEsimCountry(iso) {
      const code = String(iso || '').trim().toUpperCase();
      const esim = (this.sections || []).find((s) => s.module === 'esim');
      if (!esim) return;
      if (code && !/^[A-Z]{2}$/.test(code)) return;
      const name = code ? this.esimCountryName(code) : '';
      const packageType = this.esimPackageType(esim);
      const moduleId = String((esim.params && esim.params.module_id) || '').trim();
      const params = Object.assign({}, esim.params || {}, {
        country: code,
        country_name: name,
        package_type: packageType,
        search_package_type: packageType,
      });
      esim.params = params;
      esim.searchable = !!(moduleId && code);
      esim.listing_url = esim.searchable
        ? ('esim/' + moduleId + '/' + code.toLowerCase() + '/' + packageType + '/')
        : 'esim';
      esim.title = name ? ('eSIM — ' + name) : 'eSIM';
      esim.subtitle = esim.searchable
        ? ('Live Airalo packages for ' + name + ' (' + packageType.toUpperCase() + ').')
        : 'Pick a country below to load eSIM packages.';
      this.selected = (this.selected || []).filter((x) => x.module !== 'esim');
      this._cartSave();
      esim.items = [];
      esim.error = '';
      esim.emptyMessage = '';
      esim.emptyDetail = '';
      const idx = this.sections.indexOf(esim);
      if (idx >= 0) await this._loadSection(idx);
    },
    async setEsimPackageType(type) {
      const t = String(type || 'all').trim().toLowerCase();
      const packageType = (t === 'local' || t === 'global' || t === 'all') ? t : 'all';
      const esim = (this.sections || []).find((s) => s.module === 'esim');
      if (!esim) return;
      const code = this.esimCountryIso(esim);
      const name = code ? this.esimCountryName(code) : String((esim.params && esim.params.country_name) || '');
      const moduleId = String((esim.params && esim.params.module_id) || '').trim();
      const params = Object.assign({}, esim.params || {}, {
        package_type: packageType,
        search_package_type: packageType,
        country: code,
        country_name: name || code,
      });
      esim.params = params;
      esim.searchable = !!(moduleId && code);
      if (esim.searchable) {
        esim.listing_url = 'esim/' + moduleId + '/' + code.toLowerCase() + '/' + packageType + '/';
        esim.subtitle = 'Live Airalo packages for ' + (name || code) + ' (' + packageType.toUpperCase() + ').';
      }
      this.selected = (this.selected || []).filter((x) => x.module !== 'esim');
      this._cartSave();
      esim.items = [];
      esim.error = '';
      esim.emptyMessage = '';
      const idx = this.sections.indexOf(esim);
      if (idx >= 0) await this._loadSection(idx);
    },

    _syncNationalityFromSections() {
      const stays = (this.sections || []).find((s) => s.module === 'stays');
      if (!stays) return;
      const iso = String((stays.params && stays.params.nationality) || '').trim().toUpperCase();
      const source = String((stays.params && stays.params.nationality_source) || '').trim();
      if (!/^[A-Z]{2}$/.test(iso)) return;
      if (this.nationalitySource === 'manual' && this.nationalityIso) return;
      this.nationalityIso = iso;
      this.nationalitySource = source || this.nationalitySource || 'session';
      this._persistNationality();
    },

    _applyDepartureMeta(meta) {
      if (!meta || typeof meta !== 'object') {
        // Fallback: read from flight section params
        const flight = (this.sections || []).find((s) => s.module === 'flights');
        const p = flight?.params || {};
        if (p.origin_city || p.origin) {
          this._setDeparture(
            p.origin_city || p.origin,
            '',
            p.origin || this.departureAirport,
            this.departureSource || 'explicit',
            true
          );
        }
        this.departureNeedsInput = !!(p.needs_departure === '1' || p.needs_departure === true)
          || (!this.departureCity && !!flight && !flight.searchable);
        if (this.departureNeedsInput) {
          this.searchExpanded = true;
        }
        return;
      }
      if (meta.city) {
        this._setDeparture(
          meta.city,
          this.departureCountry,
          meta.airport || this.departureAirport,
          meta.source || this.departureSource || 'explicit',
          true
        );
      }
      this.departureNeedsInput = !!meta.needs_departure && !this.departureCity;
      if (this.departureNeedsInput) {
        this.searchExpanded = true;
        this.departureEditOpen = true;
      }
    },

    async searchDepartureAirports() {
      const query = (this.departureSearch || '').trim();
      if (query.length < 2) {
        this.departureResults = [];
        return;
      }
      this.departureSearchLoading = true;
      try {
        const formData = new FormData();
        formData.append('query', query);
        const res = await fetch(this.root + 'flights-airport-suggestion', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json().catch(() => []);
        this.departureResults = Array.isArray(data) ? data.slice(0, 12) : [];
      } catch (e) {
        this.departureResults = [];
      } finally {
        this.departureSearchLoading = false;
      }
    },

    selectDepartureAirport(airport) {
      if (!airport || typeof airport !== 'object') return;
      const city = String(airport.cityname || airport.city || airport.name || '').trim();
      const code = String(airport.id || airport.ap || airport.code || '').trim().toUpperCase();
      const country = String(airport.countryname || airport.country || '').trim();
      if (!city && !code) return;
      this._setDeparture(city || code, country, /^[A-Z]{3}$/.test(code) ? code : '', 'manual', true);
      this.departureEditOpen = false;
      this.departureSearch = this.departureCity;
      this.departureResults = [];
      // Rerun with the same prompt so origin-dependent modules stay consistent.
      const q = (this.query || '').trim();
      if (q.length >= 10) {
        this.clearSelected();
        this._runSearch();
      }
    },

    clearDeparture() {
      this._setDeparture('', '', '', '', false);
      this.departureNeedsInput = true;
      this.departureSearch = '';
      this.departureResults = [];
      try { localStorage.removeItem(DEPARTURE_KEY); } catch (e) {}
    },

    // ── Search ───────────────────────────────────────────────────
    /** Module keywords — keep in sync with aiSearchService::detectModuleKeywords. */
    _moduleKeywordMap() {
      return {
        flights: ['flight', 'flights', 'fly', 'airfare', 'airline', 'airport'],
        stays: ['hotel', 'hotels', 'stay', 'stays', 'resort', 'apartment', 'hostel'],
        cars: ['car', 'cars', 'rent a car', 'rental', 'vehicle'],
        tours: ['tour', 'tours', 'activity', 'activities', 'excursion'],
        visa: ['visa', 'visas'],
        umrah: ['umrah', 'hajj'],
        esim: ['esim', 'e-sim', 'e sim', 'sim card', 'data sim', 'travel sim', 'tourist sim', 'data plan', 'data package', 'mobile data', 'roaming'],
        cruises: ['cruise', 'cruises'],
        ferries: ['ferry', 'ferries'],
        bus: ['bus', 'buses', 'coach'],
        rail: ['rail', 'train', 'trains', 'railway', 'whoosh'],
      };
    },
    moduleLabelShort(mod) {
      const map = {
        flights: 'Flights', stays: 'Hotels', cars: 'Cars', tours: 'Tours',
        visa: 'Visa', umrah: 'Umrah', esim: 'eSIM', cruises: 'Cruises',
        ferries: 'Ferries', bus: 'Bus', rail: 'Trains',
      };
      const m = String(mod || '').toLowerCase();
      return map[m] || (m ? (m.charAt(0).toUpperCase() + m.slice(1)) : 'Travel');
    },
    /**
     * Flight prompts need Arrival To. Departure can come from geolocation.
     */
    missingFlightRouteMessage(q) {
      if (!this._isFlightAsk(q)) return '';
      if (this._promptHasFlightArrival(q)) return '';
      return 'Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).';
    },
    _isFlightAsk(q) {
      const text = String(q || '').trim();
      if (!text) return false;
      const requested = this.detectModulesInQuery(text);
      return requested.indexOf('flights') !== -1
        || /\b(round[\s-]?trip|one[\s-]?way|airfare|departure\s+date|return\s+date)\b/i.test(text);
    },
    _isFillerRoutePlace(place) {
      return /^(today|tomorrow|now|me|you|us|look|looking|find|get|show|give|need|want|book|search|please|cheap|cheapest|best|good|a|an|the|flights?|fly|hotels?|stays?|tours?|cars?|tickets?)$/i.test(String(place || '').trim());
    },
    _flightRouteFlags(q) {
      const text = String(q || '').trim();
      const empty = { hasFrom: false, hasTo: false };
      if (!text) return empty;
      const city = '([A-Za-z][A-Za-z\\-]{1,24}(?:\\s+[A-Za-z][A-Za-z\\-]{1,24}){0,2})';
      try {
        const pairRe = new RegExp('\\b' + city + '\\s+to\\s+' + city + '\\b', 'i');
        const pairMatch = text.match(pairRe);
        const left = pairMatch && pairMatch[1] ? String(pairMatch[1]).trim() : '';
        const right = pairMatch && pairMatch[2] ? String(pairMatch[2]).trim() : '';
        const realPair = !!left && !!right
          && !this._isFillerRoutePlace(left)
          && !this._isFillerRoutePlace(right);
        const iata = /\b[A-Z]{3}\s*(?:-|–|to|→)\s*[A-Z]{3}\b/;
        const fromMatch = text.match(new RegExp('\\bfrom\\s+' + city, 'i'));
        const fromPlace = fromMatch && fromMatch[1] ? String(fromMatch[1]).trim() : '';
        const fromOk = (fromPlace !== '' && !this._isFillerRoutePlace(fromPlace))
          || realPair
          || iata.test(text);
        const toMatch = text.match(new RegExp('\\bto\\s+' + city, 'i'));
        const toPlace = toMatch && toMatch[1] ? String(toMatch[1]).trim() : '';
        const cityBeforeFlight = this._cityBeforeFlightWord(text);
        const cityAfterFlight = this._cityAfterFlightPrep(text);
        const toOk = (toPlace !== '' && !this._isFillerRoutePlace(toPlace))
          || realPair
          || iata.test(text)
          || !!cityBeforeFlight
          || !!cityAfterFlight
          || new RegExp('\\b' + city + '\\s+(?:round[\\s-]?trip|one[\\s-]?way)\\b', 'i').test(text);
        return { hasFrom: fromOk, hasTo: toOk };
      } catch (e) {
        return empty;
      }
    },
    /** "flight in Dubai" / "flights for Paris" / "fly to London" */
    _cityAfterFlightPrep(q) {
      const city = '([A-Za-z][A-Za-z\\-]{1,24}(?:\\s+[A-Za-z][A-Za-z\\-]{1,24}){0,2})';
      try {
        const m = String(q || '').match(
          new RegExp('\\b(?:flights?|fly|airfare)\\s+(?:to|for|in)\\s+' + city + '\\b', 'i')
        );
        const place = m && m[1] ? String(m[1]).trim() : '';
        if (!place || this._isFillerRoutePlace(place)) return '';
        if (/^(today|tomorrow|tonight|now|tickets?)$/i.test(place)) return '';
        return place;
      } catch (e) {
        return '';
      }
    },
    _cityBeforeFlightWord(q) {
      const stops = ['find','look','looking','get','getting','show','give','see','check','me','a','an','the','cheap','cheapest','need','want','book','search','please','for','from','with','and','or','my','our','some','any','best','good','last','minute','deal','deals','offer','offers','option','options','available','ticket','tickets','tomorrow','today','tonight','now','next','this','that','round','trip','one','way','flight','flights','fly','airfare'];
      const words = String(q || '').toLowerCase().replace(/[^a-z0-9\s-]/g, ' ').split(/\s+/).filter(Boolean);
      let flightAt = -1;
      for (let i = 0; i < words.length; i++) {
        if (words[i] === 'flight' || words[i] === 'flights' || words[i] === 'fly') {
          flightAt = i;
          break;
        }
      }
      if (flightAt < 1) return '';
      for (let i = flightAt - 1; i >= 0; i--) {
        const w = words[i];
        if (w.length >= 3 && stops.indexOf(w) === -1) return w;
      }
      return '';
    },
    _hasLiveGeoDeparture() {
      return this.departureSource === 'geolocation' && !!(this.departureCity || '').trim();
    },
    _promptHasFlightArrival(q) {
      return this._flightRouteFlags(q).hasTo;
    },
    _promptHasFlightOrigin(q) {
      return this._flightRouteFlags(q).hasFrom;
    },
    /** Destination-only flight prompt — origin must come from system location. */
    flightNeedsDetectedDeparture(q) {
      return this._isFlightAsk(q) && this._promptHasFlightArrival(q) && !this._promptHasFlightOrigin(q);
    },
    _locationRequiredMessage() {
      return 'Turn on location in your browser settings, then tap Enable Location. We use it as your departure city.';
    },
    _showLocationRequired() {
      this.loading = false;
      this.searchAnim = false;
      this._searchInFlight = false;
      this.sections = [];
      this.searchExpanded = true;
      this.departureNeedsInput = true;
      this.departureLocationRequired = true;
      this.departureLocationDenied = true;
      this.departureLocationError = this._locationRequiredMessage();
      this.error = '';
      clearInterval(this._msgTimer);
      this._msgTimer = null;
    },
    async retryLocationForFlights() {
      this.departureLocationDenied = false;
      this._requireLiveGeo = true;
      this._departureReady = this.resolveDepartureContext();
      try {
        await this._departureReady;
      } catch (e) { /* ignore */ }
      if (this._hasLiveGeoDeparture()) {
        this.departureLocationRequired = false;
        this.departureLocationDenied = false;
        this._runSearch();
        return;
      }
      this._showLocationRequired();
    },
    preSearchBlockMessage(q) {
      return this.disabledModulesOnlyMessage(q)
        || this.missingFlightRouteMessage(q)
        || this.missingStayDestinationMessage(q);
    },
    missingStayDestinationMessage(q) {
      if (!this._isStayAsk(q)) return '';
      if (this._promptHasStayDestination(q)) return '';
      return 'Please mention a hotel destination in your prompt (for example, hotels in Dubai).';
    },
    _isStayAsk(q) {
      const text = String(q || '').trim();
      if (!text) return false;
      const requested = this.detectModulesInQuery(text);
      return requested.indexOf('stays') !== -1;
    },
    _promptHasStayDestination(q) {
      const text = String(q || '').trim();
      if (!text) return false;
      if (this._promptHasFlightArrival(q)) return true;
      if (this._cityAfterStayPrep(text)) return true;
      if (this._cityBeforeStayWord(text)) return true;
      const city = '([A-Za-z][A-Za-z\\-]{1,24}(?:\\s+[A-Za-z][A-Za-z\\-]{1,24}){0,2})';
      try {
        const m = text.match(new RegExp('\\b(?:in|at|near|to)\\s+' + city + '\\b', 'i'));
        const place = m && m[1] ? String(m[1]).trim() : '';
        return !!(place && !this._isFillerRoutePlace(place)
          && !/^(today|tomorrow|tonight|now|tickets?|room|rooms)$/i.test(place));
      } catch (e) {
        return false;
      }
    },
    _cityAfterStayPrep(q) {
      const city = '([A-Za-z][A-Za-z\\-]{1,24}(?:\\s+[A-Za-z][A-Za-z\\-]{1,24}){0,2})';
      try {
        const m = String(q || '').match(
          new RegExp('\\b(?:hotels?|stays?|stay|resort|hostel|apartment)\\s+(?:in|at|near|around|for|to)\\s+' + city + '\\b', 'i')
        );
        const place = m && m[1] ? String(m[1]).trim() : '';
        if (!place || this._isFillerRoutePlace(place)) return '';
        if (/^(today|tomorrow|tonight|now|tickets?|room|rooms)$/i.test(place)) return '';
        return place;
      } catch (e) {
        return '';
      }
    },
    _cityBeforeStayWord(q) {
      const stops = ['find','look','looking','get','getting','show','give','see','check','me','a','an','the','cheap','cheapest','need','want','book','search','please','for','from','with','and','or','my','our','some','any','best','good','last','minute','deal','deals','offer','offers','option','options','available','tomorrow','today','tonight','now','next','this','that','hotel','hotels','stay','stays','resort','hostel','apartment','room','rooms'];
      const words = String(q || '').toLowerCase().replace(/[^a-z0-9\s-]/g, ' ').split(/\s+/).filter(Boolean);
      let stayAt = -1;
      for (let i = 0; i < words.length; i++) {
        if (['hotel','hotels','stay','stays','resort','hostel','apartment'].indexOf(words[i]) !== -1) {
          stayAt = i;
          break;
        }
      }
      if (stayAt < 1) return '';
      for (let i = stayAt - 1; i >= 0; i--) {
        const w = words[i];
        if (w.length >= 3 && stops.indexOf(w) === -1) return w;
      }
      return '';
    },
    /** Modules named in the prompt (any known type — enabled or not). */
    detectModulesInQuery(q) {
      const text = String(q || '').toLowerCase().replace(/\s+/g, ' ').trim();
      if (!text) return [];
      const padded = ' ' + text + ' ';
      const map = this._moduleKeywordMap();
      const found = [];
      Object.keys(map).forEach((mod) => {
        const hit = (map[mod] || []).some((word) => {
          const w = String(word || '').toLowerCase().trim();
          if (!w) return false;
          if (padded.indexOf(' ' + w + ' ') !== -1) return true;
          try {
            return new RegExp('\\b' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i').test(text);
          } catch (e) {
            return text.indexOf(w) !== -1;
          }
        });
        if (hit) found.push(mod);
      });
      return found;
    },
    /**
     * When the prompt only asks for module(s) that are inactive, block before
     * loading / calling the AI search API.
     * @returns {string} error message or ''
     */
    disabledModulesOnlyMessage(q) {
      const enabled = (Array.isArray(this.enabledModules) ? this.enabledModules : [])
        .map((m) => String(m || '').toLowerCase()).filter(Boolean);
      const requested = this.detectModulesInQuery(q)
        .map((m) => String(m || '').toLowerCase()).filter(Boolean);
      if (!requested.length) return '';
      const allowed = requested.filter((m) => enabled.indexOf(m) !== -1);
      if (allowed.length) return '';
      const labels = requested.map((m) => this.moduleLabelShort(m)).filter(Boolean);
      const avail = enabled.map((m) => this.moduleLabelShort(m)).filter(Boolean);
      return this._formatUnavailableModuleMessage(labels, avail);
    },
    _formatUnavailableModuleMessage(labels, avail) {
      const names = Array.isArray(labels) ? labels.filter(Boolean) : [];
      const available = Array.isArray(avail) ? avail.filter(Boolean) : [];
      let msg = names.length === 1
        ? (names[0] + ' is not available for AI Trip right now.')
        : 'That travel option is not available for AI Trip right now.';
      if (available.length) {
        const examples = available.slice(0, 3);
        msg += ' Try ' + examples.join(', ')
          + (available.length > 3 ? ', or another shown on top' : '')
          + '.';
      } else {
        msg += ' Please try another travel option.';
      }
      return msg;
    },

    generateFromBox() {
      const ta = document.getElementById('ai-trip-q');
      if (ta) this.query = String(ta.value || '');
      this.loading = false;
      this._searchInFlight = false;
      this.searchAnim = false;
      this.generate();
    },
    async generate() {
      const q = (this.query || '').trim();
      if (q.length < 10) { this.error = 'Please enter at least 10 characters.'; return; }
      this.loading = false;
      this._searchInFlight = false;
      this.searchAnim = false;
      const blocked = this.preSearchBlockMessage(q);
      if (blocked) {
        this.error = blocked;
        this.searchExpanded = true;
        this.sections = [];
        return;
      }
      this.error = '';
      this._ssSet('ai_trip_last_q', q);
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', this.root + 'ai-trip');
      }
      // New prompt → drop previous trip picks / Review page
      this.showFinalSummary = false;
      this._departureRerunDone = false;
      this.clearSelected();
      this._runSearch();
    },

    async _runSearch(opts) {
      opts = opts || {};
      const keepSelections = !!opts.keepSelections;
      const q = (this.query || '').trim();
      if (q.length < 10) { this.error = 'Please enter at least 10 characters.'; return; }
      if (this._searchInFlight) return;
      const blocked = this.preSearchBlockMessage(q);
      if (blocked) {
        this.error = blocked;
        this.loading = false;
        this.searchAnim = false;
        this._searchInFlight = false;
        this.sections = [];
        clearInterval(this._msgTimer);
        this._msgTimer = null;
        return;
      }
      this._searchInFlight = true;
      this.error = '';
      this.showFinalSummary = false;
      this.continueHint = '';
      this.skippedModules = {};
      if (this.flightNeedsDetectedDeparture(q)) {
        this._requireLiveGeo = true;
        try {
          this._departureReady = this.resolveDepartureContext();
          this.loadingMsg = 'Detecting your location…';
          this.loading = true;
          this.searchExpanded = true;
          await this._departureReady;
        } catch (e) { /* continue to the location check */ }
        if (!this._hasLiveGeoDeparture()) {
          this._showLocationRequired();
          return;
        }
        this.departureLocationRequired = false;
        this.departureLocationDenied = false;
      } else {
        this._requireLiveGeo = false;
      }
      this.resetFlightFilters();
      this.resetHotelFilters();
      this.resetTourFilters();
      this.resetCarFilters();
      this.resetBusFilters();
      this.resetRailFilters();
      this.resetEsimFilters();
      this.resetUmrahFilters();
      this.expandedUmrahId = null;
      this.umrahDetailsById = {};
      this.umrahDetailsLoadingId = '';
      this.umrahDetailsErrorById = {};
      this.umrahDayOpenById = {};
      this.expandedTourId = null;
      this.tourDetailsById = {};
      this.tourDetailsLoadingId = '';
      this.tourDetailsErrorById = {};
      this.tourDayOpenById = {};
      this.filtersOpen = false;
      this.sections = [];
      this._nationalityRerunDone = false;
      this.bookingIndex = null;
      this.loading = true;
      this.searchExpanded = false;
      this.searchAnim = true;
      // Keep viewport steady while the search form collapses (no jump down/center)
      const holdY = window.scrollY || window.pageYOffset || 0;
      this.$nextTick(() => {
        try { window.scrollTo(0, holdY); } catch (e) {}
      });
      let i = 0;
      this.loadingMsg = this._msgs[0];
      this._msgTimer = setInterval(() => {
        i = (i + 1) % this._msgs.length;
        this.loadingMsg = this._msgs[i];
      }, 2000);
      try {
        // Wait for geolocation / last-used so destination-only prompts can use a departure.
        try {
          if (this._departureReady && typeof this._departureReady.then === 'function') {
            const waitMs = this.flightNeedsDetectedDeparture(q)
              ? 15000
              : (typeof this._departureWaitMs === 'number' ? this._departureWaitMs : 2500);
            if (waitMs > 0) {
              await Promise.race([
                this._departureReady,
                new Promise((resolve) => setTimeout(resolve, waitMs)),
              ]);
            }
          }
        } catch (e) { /* fall through with whatever we have */ }
        if (this.flightNeedsDetectedDeparture(q) && !this._hasLiveGeoDeparture()) {
          this._showLocationRequired();
          return;
        }

        const params = new URLSearchParams();
        params.set('q', q);
        this._searchedDepartureCity = (this.departureCity || '').trim();
        if ((this.departureCity || '').trim()) {
          params.set('departure_city', this.departureCity.trim());
        }
        if ((this.departureCountry || '').trim()) {
          params.set('departure_country', this.departureCountry.trim());
        }
        if ((this.departureAirport || '').trim()) {
          params.set('departure_airport', String(this.departureAirport).trim().toUpperCase());
        }
        if ((this.departureSource || '').trim()) {
          params.set('departure_source', this.departureSource.trim());
        }
        const nat = String(this.nationalityIso || '').trim().toUpperCase();
        if (/^[A-Z]{2}$/.test(nat)) {
          params.set('nationality', nat);
          if ((this.nationalitySource || '').trim()) {
            params.set('nationality_source', this.nationalitySource.trim());
          }
        }

        const res = await fetch(this.root + 'api/ai/search?' + params.toString());
        const json = await res.json().catch(() => null);
        if (!json || !json.status) {
          if ((json && json.error_code) === 'AI_DEPARTURE_REQUIRED') {
            this._showLocationRequired();
            return;
          }
          this.loading = false;
          this.searchAnim = false;
          this.searchExpanded = true;
          this.sections = [];
          this.error = json?.message || 'AI search failed.';
          return;
        }
        const searches = Array.isArray(json.searches) ? json.searches : [];
        this.sections = this._applyPromptPaxToSections(searches.map((s) => ({
          module: s.module || '',
          badge: s.badge || '',
          title: s.title || '',
          subtitle: s.subtitle || '',
          searchable: !!s.searchable,
          suppliers: s.suppliers || [],
          params: s.params || {},
          listing_url: s.listing_url || '',
          items_limit: s.items_limit || 8,
          serverEmptyMessage: s.empty_message || '',
          serverEmptyDetail: s.empty_detail || '',
          loading: !!s.searchable,
          items: [],
          error: '',
          emptyMessage: '',
          emptyDetail: '',
          open: true,
        })));
        // Sync indicator from effective departure (explicit prompt wins server-side).
        this._applyDepartureMeta(json.departure || null);
        this._syncNationalityFromSections();
        if (keepSelections) {
          const cart = this._cartGet();
          if (cart.length) {
            this.selected = this._sortBySectionOrder(this._onePerModule(cart));
          } else {
            this.selected = this._sortBySectionOrder(this._onePerModule(this.selected));
          }
          this._cartSave();
        } else {
          // Fresh search — do not pull an old cart back into this prompt
          this.selected = this._sortBySectionOrder(this._onePerModule(this.selected));
          this._cartSave();
        }
        if (this.sections.length) {
          await this._loadAll();
          if (keepSelections) {
            const cartAfter = this._cartGet();
            if (cartAfter.length) {
              this.selected = this._sortBySectionOrder(this._onePerModule(cartAfter));
              this._cartSave();
            }
          }
          this.drawerModule = this.sections[0].module || '';
          // Do not auto-scroll the page when results arrive — stay where the user is
        }
      } catch (e) {
        this.error = 'AI search is temporarily unavailable.';
      } finally {
        this.loading = false;
        this.searchAnim = false;
        this._searchInFlight = false;
        clearInterval(this._msgTimer);
        this._msgTimer = null;
        // Location may have landed after this search started — refresh once with it.
        this._maybeRerunForDeparture();
      }
    },

    openModifySearch() {
      this._queryAtExpand = (this.query || '').trim();
      this.searchExpanded = true;
      this.searchAnim = false;
      clearInterval(this._msgTimer);
      this._msgTimer = null;
    },

    collapseSearch() {
      this.searchExpanded = false;
      const qNow = (this.query || '').trim();
      const edited = qNow !== (this._queryAtExpand || '');
      // Only resume searching animation while a search is actually running
      if (!edited && this.loading) {
        this.searchAnim = true;
        if (!this._msgTimer) {
          let i = 0;
          this.loadingMsg = this._msgs[0];
          this._msgTimer = setInterval(() => {
            i = (i + 1) % this._msgs.length;
            this.loadingMsg = this._msgs[i];
          }, 2000);
        }
      } else {
        this.searchAnim = false;
        clearInterval(this._msgTimer);
        this._msgTimer = null;
      }
    },

    async _loadAll() {
      await Promise.all(this.sections.map((_, idx) => this._loadSection(idx)));
    },

    _setEmptyState(s) {
      const kind = ({ flights: 'flights', stays: 'hotels', tours: 'tours', cars: 'cars', bus: 'buses', rail: 'trains', ferries: 'ferry sailings', esim: 'eSIM packages', visa: 'visa options', umrah: 'Umrah packages' })[s.module] || 'results';
      const p = s.params || {};
      const place = (p.from_country && p.to_country)
        ? ((p.from_country_name || p.from_country) + ' → ' + (p.to_country_name || p.to_country))
        : ((p.departure_port_name && p.destination_port_name)
        ? (p.departure_port_name + ' → ' + p.destination_port_name)
        : ((p.origin && p.destination)
        ? (p.origin + ' → ' + p.destination)
        : (p.pickup_location || p.destination || p.city || p.location || p.country_name || '')));
      s.emptyMessage = place
        ? `No ${kind} found for ${place}`
        : `No ${kind} found`;
      s.emptyDetail = s.module === 'umrah'
        ? 'Try another catalog location or start date. Duration and type must be active in Umrah settings.'
        : (s.module === 'ferries'
          ? 'Try another date, or reduce the vehicles / pets you asked for. Some crossings do not carry them.'
          : 'Try different dates or another destination.');
      s.error = s.emptyMessage;
    },

    async _loadSection(idx) {
      const s = this.sections[idx];
      if (!s) return;
      s.loading = true;
      s.items = [];
      s.error = '';
      s.emptyMessage = '';
      s.emptyDetail = '';
      if (!s.searchable || !s.suppliers.length) {
        s.loading = false;
        if (this.stayNeedsNationality(s)) {
          s.emptyMessage = s.serverEmptyMessage || 'Select your nationality to see hotels available to you.';
          s.emptyDetail = s.serverEmptyDetail || 'Hotel availability depends on guest nationality.';
          s.error = '';
          return;
        }
        if (this.esimNeedsCountry(s)) {
          s.emptyMessage = s.serverEmptyMessage || 'Which country do you need the eSIM for?';
          s.emptyDetail = s.serverEmptyDetail || 'Select a country above to load Airalo packages.';
          s.error = '';
          return;
        }
        const kind = ({ flights: 'flights', stays: 'hotels', tours: 'tours', cars: 'cars', bus: 'buses', rail: 'trains', ferries: 'ferry sailings', esim: 'eSIM packages', umrah: 'Umrah packages' })[s.module] || 'options';
        s.emptyMessage = s.serverEmptyMessage || `We couldn't find any ${kind} right now`;
        s.emptyDetail = s.serverEmptyDetail || 'Please try again later, or change your dates or destination.';
        s.error = s.emptyMessage;
        this._afterSectionEmpty(s);
        return;
      }
      try {
        let packed = { items: [], emptySuppliers: [] };
        if (s.module === 'flights') packed = await this._flights(s);
        else if (s.module === 'tours') packed = await this._tours(s);
        else if (s.module === 'stays') packed = await this._stays(s);
        else if (s.module === 'cars') packed = await this._cars(s);
        else if (s.module === 'bus') packed = await this._bus(s);
        else if (s.module === 'rail') packed = await this._rail(s);
        else if (s.module === 'ferries') packed = await this._ferries(s);
        else if (s.module === 'esim') packed = await this._esim(s);
        else if (s.module === 'visa') packed = await this._visa(s);
        else if (s.module === 'umrah') packed = await this._umrah(s);
        s.items = packed.items || [];
        s.emptySuppliers = Array.isArray(packed.emptySuppliers) ? packed.emptySuppliers : [];
        const configuredSuppliers = [...new Set((s.suppliers || []).filter(Boolean))];
        const emptySupplierSet = new Set(s.emptySuppliers);
        s.successfulSupplierCount = Math.max(
          0,
          configuredSuppliers.filter((supplier) => !emptySupplierSet.has(supplier)).length
        );
        if (s.module === 'umrah') this.syncUmrahFiltersFromSection(s);
        if (!s.items.length) {
          if (this.stayNeedsNationality(s)) {
            s.emptyMessage = s.serverEmptyMessage || 'Select your nationality to see hotels available to you.';
            s.emptyDetail = s.serverEmptyDetail || 'Hotel availability depends on guest nationality.';
            s.error = '';
          } else if (this.esimNeedsCountry(s)) {
            s.emptyMessage = s.serverEmptyMessage || 'Which country do you need the eSIM for?';
            s.emptyDetail = s.serverEmptyDetail || 'Select a country above to load Airalo packages.';
            s.error = '';
          } else {
            this._setEmptyState(s);
          }
        }
      } catch (e) {
        s.error = 'Something went wrong';
        s.emptyMessage = 'Something went wrong while searching';
        s.emptyDetail = 'Please try again in a moment.';
      } finally {
        s.loading = false;
        if (!((s.items || []).length)) this._afterSectionEmpty(s);
      }
    },
    _afterSectionEmpty(s) {
      if (!s || !this.moduleIsUnavailable(s)) return;
      // If user is on this empty tab, nudge to next incomplete (or finish)
      if (this.drawerModule === s.module && this.selectedCount > 0) {
        this.$nextTick(() => {
          if (!this.goToNextIncompleteTab(s.module) && this.wizardComplete && this._bookingItemsPayload().length) {
            // stay on empty tab so they see skip message; don't auto-open summary
          }
        });
      }
    },

    _logFlightPriceAudit(supplier, params, rawRows, normalizedRows) {
      if (new URLSearchParams(window.location.search).get('price_audit') !== '1') return;
      const raw = Array.isArray(rawRows) ? rawRows : [];
      const normalized = Array.isArray(normalizedRows) ? normalizedRows : [];
      console.groupCollapsed(`[Flight Price Audit][ai-trip] ${supplier}: ${normalized.length} offers`);
      console.info('search_params', JSON.parse(JSON.stringify(params || {})));
      console.table(normalized.map((flight, index) => ({
        supplier,
        airline: flight.airline || '',
        flight_no: flight.flight_no || '',
        route: `${flight.departure_code || ''}-${flight.arrival_code || ''}`,
        departure: `${flight.departure_date || ''} ${flight.departure_time || ''}`.trim(),
        raw_outer_price: raw[index]?.price ?? null,
        raw_segment_price: raw[index]?.segments?.[0]?.[0]?.price ?? null,
        normalized_price: flight.price ?? null,
        currency: flight.currency || raw[index]?.currency || '',
      })));
      console.groupEnd();
    },

    async _flights(s) {
      const suppliers = [...new Set((s.suppliers || []).filter(Boolean))];
      const all = await Promise.all(suppliers.map(async (sup) => {
        try {
          const f = new FormData();
          Object.entries(s.params || {}).forEach(([k, v]) => {
            if (v != null) f.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
          });
          const r = await fetch(this.root + 'modules/flights/' + sup + '/search', { method: 'POST', body: f });
          const t = await r.text();
          let p = null;
          try { p = JSON.parse(t); } catch (e) {
            const a = t.indexOf('['), b = t.lastIndexOf(']');
            if (a > -1 && b > a) p = JSON.parse(t.slice(a, b + 1));
          }
          let rows = [];
          if (Array.isArray(p)) rows = p;
          else if (p?.data && Array.isArray(p.data)) rows = p.data;
          else if (p?.flights && Array.isArray(p.flights)) rows = p.flights;
          const items = this._normFlights(rows, sup, s.params);
          this._logFlightPriceAudit(sup, s.params, rows, items);
          return { supplier: sup, items };
        } catch (e) { return { supplier: sup, items: [] }; }
      }));
      const emptySuppliers = all.filter((r) => !(r.items && r.items.length)).map((r) => r.supplier);
      // Match the normal flight listing: collapse the same schedule returned
      // by multiple suppliers and retain the cheapest offer. Do NOT slice —
      // the site keeps every unique offer (paginated at 50/page); AI drawer scrolls.
      const uniqueFlights = new Map();
      const isMultiCitySearch = String(s.params?.type || '').toLowerCase() === 'multicity';
      all.flatMap((r) => r.items || []).filter(Boolean).forEach((flight) => {
        const key = isMultiCitySearch || flight.isMultiCity
          ? this._multiCityDedupKey(flight)
          : `${flight.airline}-${flight.flight_no}-${flight.departure_code}-${flight.arrival_code}-${flight.departure_time}-${flight.arrival_time}`;
        const current = uniqueFlights.get(key);
        if (!current || Number(flight.price || 0) < Number(current.price || 0)) {
          uniqueFlights.set(key, flight);
        }
      });
      const m = Array.from(uniqueFlights.values());
      m.sort((a, b) => (a.price || 0) - (b.price || 0));
      if (new URLSearchParams(window.location.search).get('price_audit') === '1') {
        console.info('[Flight Price Audit][ai-trip] deduplication', {
          supplier_offers: all.reduce((sum, result) => sum + (result.items?.length || 0), 0),
          unique_offers: m.length,
          removed_duplicates: all.reduce((sum, result) => sum + (result.items?.length || 0), 0) - m.length,
          winners: m.map((flight) => ({
            supplier: flight.supplier,
            airline: flight.airline,
            flight_no: flight.flight_no,
            route: `${flight.departure_code || ''}-${flight.arrival_code || ''}`,
            departure_time: flight.departure_time,
            arrival_time: flight.arrival_time,
            price: flight.price,
            currency: flight.currency,
          })),
        });
      }
      return { items: m, emptySuppliers };
    },

    _multiCityDedupKey(flight) {
      const slices = Array.isArray(flight?.segments)
        ? flight.segments
        : (Array.isArray(flight?.raw?.segments) ? flight.raw.segments : []);
      if (!Array.isArray(slices) || !slices.length) {
        return [
          flight?.airline || '',
          flight?.flight_no || '',
          flight?.departure_code || '',
          flight?.arrival_code || '',
          flight?.departure_date || '',
          flight?.departure_time || '',
          flight?.arrival_time || '',
        ].join('-');
      }
      return slices.map((slice) => {
        if (!Array.isArray(slice) || !slice.length) return '';
        const first = slice[0] || {};
        const last = slice[slice.length - 1] || {};
        return [
          first.airline || '',
          first.flight_no || '',
          first.departure_code || '',
          last.arrival_code || '',
          first.departure_date || '',
          first.departure_time || '',
          last.arrival_time || '',
        ].join('-');
      }).join('|');
    },

    _normFlights(data, sup, params) {
      if (!Array.isArray(data)) return [];
      const searchType = String(params?.type || '').toLowerCase();
      const isMultiCitySearch = searchType === 'multicity';
      const isReturnSearch = searchType === 'return'
        || !!(params?.return_date && String(params.return_date).trim());
      return data.map((f) => {
        if (!f.segments || !f.segments[0] || !f.segments[0][0]) return null;

        if (isMultiCitySearch) {
          const slices = f.segments;
          const firstSlice = slices[0];
          const lastSlice = slices[slices.length - 1];
          const fi = firstSlice[0];
          const la = lastSlice[lastSlice.length - 1];
          const rp = parseFloat(f.price || fi.price || 0);
          const totalStops = slices.reduce((acc, slice) => {
            if (!Array.isArray(slice) || slice.length < 1) return acc;
            return acc + Math.max(0, slice.length - 1);
          }, 0);
          return {
            kind: 'flight', supplier: sup,
            airline: fi.airline || '', airlineName: fi.airlineName || fi.airline || '',
            flight_no: fi.flight_no || '', img: fi.img || fi.airline || '',
            departure_time: fi.departure_time || '', departure_code: fi.departure_code || params.origin || '',
            departure_airport: fi.departure_airport || '',
            departure_date: fi.departure_date || params.departure_date || '',
            arrival_time: la.arrival_time || '', arrival_code: la.arrival_code || params.destination || '',
            arrival_airport: la.arrival_airport || '',
            arrival_date: la.arrival_date
              || this._inferArrivalDate(
                fi.departure_date || params.departure_date || '',
                fi.departure_time || '',
                la.arrival_time || ''
              )
              || '',
            duration_time: fi.duration_time || fi.total_duration || '',
            stops: totalStops,
            class: fi.class || params.class || 'economy',
            cabin_baggage: fi.cabin_baggage || la.cabin_baggage || '',
            baggage: fi.baggage || la.baggage || '',
            refundable: !!(f.refundable ?? fi.refundable ?? la.refundable),
            fare_rules: f.fare_rules || fi.fare_rules || '',
            currency: fi.currency || params.currency || this.currency,
            price: Math.round(rp * 100) / 100,
            isMultiCity: true,
            isRoundTrip: false,
            returnFlight: null,
            returnStops: 0,
            returnDuration: '',
            segments: slices,
            outboundSegments: firstSlice,
            returnSegments: [],
            raw: {
              ...fi,
              supplier: sup,
              price: Math.round(rp * 100) / 100,
              segments: slices,
              outboundSegments: firstSlice,
              returnSegments: [],
              returnFlight: null,
              isRoundTrip: false,
              isMultiCity: true,
              stops: totalStops,
              arrival_code: la.arrival_code,
              arrival_time: la.arrival_time,
              arrival_airport: la.arrival_airport,
              arrival_date: la.arrival_date,
              cabin_baggage: fi.cabin_baggage || la.cabin_baggage || '',
              baggage: fi.baggage || la.baggage || '',
              refundable: !!(f.refundable ?? fi.refundable ?? la.refundable),
              fare_rules: f.fare_rules || fi.fare_rules || '',
              class: fi.class || params.class || 'economy',
              type: 'multicity',
              return_date: '',
              routes: params?.routes || [],
            }
          };
        }

        const ob = f.segments[0];
        const rb = Array.isArray(f.segments[1]) ? f.segments[1] : [];
        const fi = ob[0];
        const la = ob[ob.length - 1];
        const rp = parseFloat(f.price || fi.price || 0);

        let returnFlight = null;
        let returnStops = 0;
        let returnDuration = '';
        if (rb.length > 0) {
          const rf = rb[0];
          const rl = rb[rb.length - 1];
          returnFlight = {
            airline: rf.airline || '',
            airlineName: rf.airlineName || rf.airline || '',
            flight_no: rf.flight_no || '',
            departure_code: rf.departure_code || '',
            departure_time: rf.departure_time || '',
            departure_date: rf.departure_date || params.return_date || '',
            arrival_code: rl.arrival_code || '',
            arrival_time: rl.arrival_time || '',
            arrival_date: rl.arrival_date
              || this._inferArrivalDate(
                rf.departure_date || params.return_date || '',
                rf.departure_time || '',
                rl.arrival_time || ''
              )
              || params.return_date
              || '',
            duration_time: rf.duration_time || '',
            class: rf.class || params.class || 'economy',
          };
          returnStops = Math.max(0, rb.length - 1);
          returnDuration = rf.duration_time || '';
        }

        return {
          kind: 'flight', supplier: sup,
          airline: fi.airline || '', airlineName: fi.airlineName || fi.airline || '',
          flight_no: fi.flight_no || '', img: fi.img || fi.airline || '',
          departure_time: fi.departure_time || '', departure_code: fi.departure_code || params.origin || '',
          departure_airport: fi.departure_airport || '',
          departure_date: fi.departure_date || params.departure_date || '',
          arrival_time: la.arrival_time || '', arrival_code: la.arrival_code || params.destination || '',
          arrival_airport: la.arrival_airport || '',
          arrival_date: la.arrival_date || params.departure_date || '',
          duration_time: fi.duration_time || fi.total_duration || '',
          stops: Math.max(0, ob.length - 1),
          class: fi.class || params.class || 'economy',
          cabin_baggage: fi.cabin_baggage || la.cabin_baggage || '',
          baggage: fi.baggage || la.baggage || '',
          refundable: !!(f.refundable ?? fi.refundable ?? la.refundable),
          fare_rules: f.fare_rules || fi.fare_rules || '',
          currency: fi.currency || params.currency || this.currency,
          price: Math.round(rp * 100) / 100,
          isRoundTrip: rb.length > 0 || isReturnSearch,
          isMultiCity: false,
          returnFlight,
          returnStops,
          returnDuration,
          outboundSegments: ob,
          returnSegments: rb,
          raw: {
            ...fi,
            supplier: sup,
            price: Math.round(rp * 100) / 100,
            segments: f.segments,
            outboundSegments: ob,
            returnSegments: rb,
            returnFlight,
            isRoundTrip: rb.length > 0,
            isMultiCity: false,
            stops: Math.max(0, ob.length - 1),
            arrival_code: la.arrival_code,
            arrival_time: la.arrival_time,
            arrival_airport: la.arrival_airport,
            arrival_date: la.arrival_date,
            cabin_baggage: fi.cabin_baggage || la.cabin_baggage || '',
            baggage: fi.baggage || la.baggage || '',
            refundable: !!(f.refundable ?? fi.refundable ?? la.refundable),
            fare_rules: f.fare_rules || fi.fare_rules || '',
            class: fi.class || params.class || 'economy',
            type: params?.type || 'oneway',
            return_date: params?.return_date || '',
          }
        };
      }).filter(Boolean);
    },

    async _tours(s) {
      const suppliers = [...new Set((s.suppliers || []).filter(Boolean))];
      const all = await Promise.all(suppliers.map(async (sup) => {
        try {
          const f = new FormData();
          Object.entries(s.params || {}).forEach(([k, v]) => {
            if (v != null) f.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
          });
          // High page size so AI Trip is not capped below the normal listing
          f.append('page', '1'); f.append('per_page', '200');
          const r = await fetch(this.root + 'modules/tours/' + sup + '/search', { method: 'POST', body: f });
          if (!r.ok) return { supplier: sup, items: [] };
          const j = await r.json().catch(() => null);
          let rows = [];
          if (Array.isArray(j)) rows = j;
          else if (j?.tours) rows = j.tours;
          else if (j?.results) rows = j.results;
          const items = rows.map((t, i) => {
            const promptPax = this._parsePaxFromQuery(this.query);
            const adultsN = Math.max(1, parseInt(
              (promptPax.adults != null ? promptPax.adults : null)
              ?? s.params.adults
              ?? t.current_adults
              ?? 1,
              10
            ) || 1);
            const childrenN = Math.max(0, parseInt(
              (promptPax.children != null ? promptPax.children : null)
              ?? s.params.children
              ?? t.current_children
              ?? 0,
              10
            ) || 0);
            const calc = t.actual_price_details?.with_markup?.calculation || {};
            let pricePerAdult = parseFloat(String(
              t.display_price_per_adult
              || calc.adult_unit_price
              || t.display_price_per_person
              || t.price_per_person
              || 0
            ).replace(/,/g, '')) || 0;
            let pricePerChild = parseFloat(String(
              t.display_price_per_child
              || calc.child_unit_price
              || 0
            ).replace(/,/g, '')) || 0;
            let totalPrice = parseFloat(String(t.display_price || t.price || 0).replace(/,/g, '')) || 0;
            // Fallback unit prices when supplier only returns a total / from-price
            if (pricePerAdult <= 0 && totalPrice > 0) {
              if (Number(calc.adult_unit_price) > 0) {
                pricePerAdult = parseFloat(calc.adult_unit_price) || 0;
              } else if (Number(calc.adults_count) === adultsN && adultsN > 1 && childrenN === 0) {
                pricePerAdult = Math.round((totalPrice / adultsN) * 100) / 100;
              } else {
                // Viator-style per-person from price
                pricePerAdult = totalPrice;
              }
            }
            if (pricePerChild <= 0 && pricePerAdult > 0) {
              const baseA = parseFloat(t.base_adult_price) || 0;
              const baseC = parseFloat(t.base_child_price) || 0;
              if (baseA > 0 && baseC > 0) {
                pricePerChild = Math.round((pricePerAdult * (baseC / baseA)) * 100) / 100;
              }
            }
            if (pricePerAdult > 0) {
              totalPrice = Math.round(((adultsN * pricePerAdult) + (childrenN * pricePerChild)) * 100) / 100;
            }
            const stars = parseInt(t.stars || t.star_rating || 0, 10) || 0;
            const rating = parseFloat(t.rating || t.guest_rating || t.review_score || 0) || 0;
            const images = Array.isArray(t.images) ? t.images.filter(Boolean) : [];
            const image = t.img || t.image || images[0] || '';
            const inclusions = Array.isArray(t.inclusions) ? t.inclusions : [];
            const exclusions = Array.isArray(t.exclusions) ? t.exclusions : [];
            let description = String(t.description || t.desc || t.overview || '').trim();
            if (description.length > 600) description = description.slice(0, 600) + '…';
            return {
              kind: 'tour',
              supplier: sup,
              id: t.tour_id || t.id || (sup + '-' + i),
              name: t.name || 'Tour',
              image,
              images,
              location: t.location || t.city || s.params.destination || '',
              price: totalPrice,
              price_per_person: pricePerAdult > 0 ? pricePerAdult : totalPrice,
              price_per_adult: pricePerAdult > 0 ? pricePerAdult : totalPrice,
              price_per_child: pricePerChild,
              currency: t.currency || s.params.currency || this.currency,
              days: t.days || t.duration || s.params.duration || '1',
              nights: t.nights || '',
              duration_formatted: t.duration_formatted || '',
              stars,
              rating,
              tour_type: t.tour_type || t.type || '',
              tour_type_id: t.tour_type_id || 0,
              inclusions,
              exclusions,
              description,
              itinerary: Array.isArray(t.itinerary) ? t.itinerary : [],
              cancellation_policy: t.cancellation_policy || t.cancellationPolicy || '',
              terms_conditions: t.terms_conditions || t.termsConditions || t.terms || '',
              latitude: t.latitude ?? t.lat ?? null,
              longitude: t.longitude ?? t.lng ?? t.lon ?? null,
              address: t.address || '',
              start_date: s.params.start_date || t.start_date || '',
              adults: String(adultsN),
              children: String(childrenN),
              max_adults: Math.max(1, parseInt(t.max_adults || 10, 10) || 10),
              max_children: Math.max(0, parseInt(t.max_children || 6, 10) || 6),
            };
          });
          return { supplier: sup, items };
        } catch (e) { return { supplier: sup, items: [] }; }
      }));
      const emptySuppliers = all.filter((r) => !(r.items && r.items.length)).map((r) => r.supplier);
      const m = all.flatMap((r) => r.items || []).filter(Boolean);
      m.sort((a, b) => (a.price || 0) - (b.price || 0));
      return { items: m, emptySuppliers };
    },

    async _stays(s) {
      const nat = String((s.params && s.params.nationality) || this.nationalityIso || '').trim().toUpperCase();
      if (!/^[A-Z]{2}$/.test(nat)) {
        s.params = Object.assign({}, s.params || {}, { nationality_required: '1', nationality: '' });
        return { items: [], emptySuppliers: [] };
      }
      if (s.params) s.params.nationality = nat;
      const all = await Promise.all(s.suppliers.map(async (sup) => {
        try {
          const f = new FormData();
          Object.entries(s.params || {}).forEach(([k, v]) => {
            if (v != null) f.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
          });
          // Match stays listing name-search page size (100), not the AI-only 10/40 caps
          f.append('page', '1'); f.append('per_page', '100');
          const r = await fetch(this.root + 'modules/stays/' + String(sup).toLowerCase() + '/search', { method: 'POST', body: f });
          if (!r.ok) return { supplier: sup, items: [] };
          const t = await r.text();
          let j = null;
          try { j = JSON.parse(t); } catch (e) { return { supplier: sup, items: [] }; }
          let rows = [];
          if (Array.isArray(j)) rows = j;
          else if (j?.hotels) rows = j.hotels;
          else if (j?.data) rows = j.data;
          else if (j?.results) rows = j.results;
          const items = rows.map((h, i) => {
            // Always use search params for stay length — supplier nights is often wrong
            const checkin = s.params.checkin || h.checkin || '';
            const checkout = s.params.checkout || h.checkout || '';
            const nights = this.stayNightsCount(
              checkin,
              checkout,
              s.params.duration_days || s.params.nights || h.nights
            );
            // Same field priority as normal stays listing (stays.php), plus display_* first
            // for suppliers that document those as the frontend prices.
            const totalPrice = parseFloat(String(
              h.display_price || h.price || h.actual_price || h.min_price || h.total_price || 0
            ).replace(/,/g, '')) || 0;
            let perNight = parseFloat(String(
              h.display_price_per_night || h.actual_price_per_night || h.price_per_night || 0
            ).replace(/,/g, '')) || 0;
            // RateHawk/Booking search often set price_per_night === stay total.
            // Normal details/rooms divide by nights; listing "From" must be nightly too.
            if (
              perNight > 0
              && totalPrice > 0
              && nights > 1
              && Math.abs(perNight - totalPrice) < 0.02
            ) {
              perNight = Math.round((totalPrice / nights) * 100) / 100;
            } else if (perNight <= 0 && totalPrice > 0 && nights > 0) {
              perNight = Math.round((totalPrice / nights) * 100) / 100;
            }
            const stars = parseInt(h.stars || h.star_rating || h.hotel_stars || 0, 10) || 0;
            const rating = parseFloat(h.rating || h.guest_rating || h.review_score || 0) || 0;
            return {
              kind: 'stay', supplier: sup, id: h.hotel_id || h.id || (sup + '-' + i),
              name: h.name || h.hotel_name || 'Stay',
              image: h.img || h.image || h.thumbnail || '',
              location: h.location || h.city || s.params.destination || '',
              price: totalPrice,
              price_per_night: perNight,
              nights,
              stars,
              rating,
              currency: h.currency || s.params.currency || this.currency,
              chain: h.chain || h.hotel_chain || '_', redirect: h.redirect || '',
              checkin, checkout,
              nationality: s.params.nationality || '', rooms: s.params.rooms || '1',
              adults: s.params.adults || '1', children: s.params.children || '0'
            };
          });
          return { supplier: sup, items };
        } catch (e) { return { supplier: sup, items: [] }; }
      }));
      const emptySuppliers = all.filter((r) => !(r.items && r.items.length)).map((r) => r.supplier);
      const m = all.flatMap((r) => r.items || []).filter(Boolean);
      m.sort((a, b) => (a.price || 0) - (b.price || 0));
      return { items: m, emptySuppliers };
    },

    async _cars(s) {
      const all = await Promise.all(s.suppliers.map(async (sup) => {
        try {
          const f = new FormData();
          Object.entries(s.params || {}).forEach(([k, v]) => {
            if (v != null) f.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
          });
          // Alias date keys some suppliers expect
          if (s.params?.return_date && !s.params?.dropoff_date) {
            f.append('dropoff_date', s.params.return_date);
          }
          if (s.params?.dropoff_time && !s.params?.return_time) {
            f.append('return_time', s.params.dropoff_time);
          }
          const r = await fetch(this.root + 'modules/cars/' + encodeURIComponent(sup) + '/search', {
            method: 'POST',
            body: f
          });
          if (!r.ok) return { supplier: sup, items: [] };
          const t = await r.text();
          let p = null;
          try { p = JSON.parse(t); } catch (e) {
            const a = t.indexOf('['), b = t.lastIndexOf(']');
            if (a > -1 && b > a) p = JSON.parse(t.slice(a, b + 1));
          }
          let rows = [];
          if (Array.isArray(p)) rows = p;
          else if (p?.data && Array.isArray(p.data)) rows = p.data;
          else if (p?.results && Array.isArray(p.results)) rows = p.results;
          else if (p?.cars && Array.isArray(p.cars)) rows = p.cars;
          return { supplier: sup, items: this._normCars(rows, sup, s.params) };
        } catch (e) { return { supplier: sup, items: [] }; }
      }));
      const emptySuppliers = all.filter((r) => !(r.items && r.items.length)).map((r) => r.supplier);
      let m = all.flatMap((r) => r.items || []).filter(Boolean);
      // Prefer bookable quotes (skip pure affiliate redirects without reference)
      m = m.filter((c) => {
        if (String(c.supplier || '').toLowerCase() === 'kiwitaxi') return false;
        if (c.booking_url && !c.reference_id && String(c.supplier || '').toLowerCase() !== 'cars') {
          return false;
        }
        return true;
      });
      m.sort((a, b) => (a.price || 0) - (b.price || 0));
      return { items: m, emptySuppliers };
    },

    _normCars(data, sup, params) {
      if (!Array.isArray(data)) return [];
      return data.map((car, index) => {
        const displayPrice = parseFloat(String(
          car.display_price || car.price || car.total_price || 0
        ).replace(/,/g, '')) || 0;
        const pricePerDay = parseFloat(String(
          car.display_price_per_day || car.price_per_day || car.actual_price_per_day || displayPrice
        ).replace(/,/g, '')) || displayPrice;
        const id = String(car.vehicle_id || car.id || index) + '-' + sup + '-' + index;
        return {
          kind: 'car',
          supplier: car.supplier || sup,
          id,
          original_id: car.vehicle_id || car.id || '',
          car_id: car.vehicle_id || car.id || car.car_id || '',
          name: car.name || car.vehicle_name || 'Car',
          image: car.image || car.img || '',
          category: car.category || car.car_type || car.vehicle_class || 'standard',
          car_type: car.category || car.car_type || car.vehicle_class || 'standard',
          vendor: car.vendor || car.vendor_name || sup,
          vendor_code: car.vendor_code || '',
          transmission: car.transmission || 'Automatic',
          fuel_type: car.fuel_type || '',
          passengers: parseInt(car.passengers || car.max_passengers || 4, 10) || 4,
          baggage: parseInt(car.baggage || car.bags || car.luggage || 2, 10) || 2,
          doors: parseInt(car.doors || car.number_of_doors || 4, 10) || 4,
          price: Math.round(displayPrice * 100) / 100,
          price_per_day: Math.round(pricePerDay * 100) / 100,
          display_price: displayPrice,
          actual_price: parseFloat(car.actual_price || displayPrice) || displayPrice,
          currency: car.currency || params?.currency || this.currency,
          rental_days: parseInt(car.rental_days || 1, 10) || 1,
          free_cancellation: !!car.free_cancellation,
          unlimited_mileage: !!car.unlimited_mileage,
          air_conditioning: !!(car.air_conditioning ?? car.air_con ?? car.ac),
          fuel_policy: car.fuel_policy || '',
          pickup_location: car.pickup_location || params?.pickup_location || '',
          dropoff_location: car.dropoff_location || params?.dropoff_location || '',
          pickup_datetime: car.pickup_datetime || '',
          dropoff_datetime: car.dropoff_datetime || '',
          reference_id: car.reference_id || '',
          booking_url: car.booking_url || car.url || '',
          raw: car,
        };
      }).filter(Boolean);
    },

    async _bus(s) {
      const p = s.params || {};
      const adults = Math.max(1, parseInt(p.adults || 1, 10) || 1);
      const children = Math.max(0, parseInt(p.children || 0, 10) || 0);
      const passengers = Math.max(1, parseInt(p.passengers || (adults + children), 10) || (adults + children));
      const tripType = String(p.trip_type || 'oneway').toLowerCase() === 'return' ? 'return' : 'oneway';
      const fetchTrips = async (origin, destination, date) => {
        const body = new URLSearchParams({
          origin: String(origin || ''),
          destination: String(destination || ''),
          date: String(date || ''),
          passengers: String(passengers),
        });
        const r = await fetch(this.root + 'api/bus/listing', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body,
        });
        if (!r.ok) return [];
        const json = await r.json().catch(() => null);
        return Array.isArray(json?.trips) ? json.trips : [];
      };
      try {
        const outbound = await fetchTrips(p.origin, p.destination, p.date);
        let trips = outbound;
        if (tripType === 'return' && p.return_date) {
          const returns = await fetchTrips(p.destination, p.origin, p.return_date);
          trips = outbound.flatMap((out) => returns.map((ret) => {
            const outTotal = (adults * Number(out.adult_price || out.price || 0))
              + (children * Number(out.child_price || 0));
            const retTotal = (adults * Number(ret.adult_price || ret.price || 0))
              + (children * Number(ret.child_price || 0));
            return {
              ...out,
              pair_id: String(out.route_id) + '-' + String(ret.route_id),
              return_trip: ret,
              price: Math.round((outTotal + retTotal) * 100) / 100,
              seats_available: Math.min(
                Number(out.seats_available || 0),
                Number(ret.seats_available || 0)
              ),
              refundable: Boolean(out.refundable && ret.refundable),
            };
          }));
        } else {
          trips = outbound.map((t) => {
            const total = (adults * Number(t.adult_price || t.price || 0))
              + (children * Number(t.child_price || 0));
            return { ...t, price: Math.round(total * 100) / 100 };
          });
        }
        const items = this._normBuses(trips, p, adults, children);
        items.sort((a, b) => (a.price || 0) - (b.price || 0));
        return { items, emptySuppliers: items.length ? [] : ['bus'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['bus'] };
      }
    },

    _normBuses(data, params, adults, children) {
      if (!Array.isArray(data)) return [];
      const a = Math.max(1, parseInt(adults || params?.adults || 1, 10) || 1);
      const c = Math.max(0, parseInt(children || params?.children || 0, 10) || 0);
      return data.map((t, index) => {
        const ret = t.return_trip && typeof t.return_trip === 'object' ? t.return_trip : null;
        const price = Number(t.price) || 0;
        const id = String(t.pair_id || t.route_id || index) + '-bus-' + index;
        const name = t.service_name || t.operator || 'Bus';
        return {
          kind: 'bus',
          supplier: 'bus',
          id,
          route_id: t.route_id || 0,
          name,
          service_name: t.service_name || '',
          operator: t.operator || '',
          bus_type: t.bus_type || '',
          seat_class: t.seat_class || '',
          origin: t.origin || params?.origin || '',
          destination: t.destination || params?.destination || '',
          departure_time: t.departure_time || '',
          arrival_time: t.arrival_time || '',
          duration: t.duration || '',
          date: params?.date || t.date_display || t.date || '',
          seats_available: Number(t.seats_available || 0),
          adult_price: Number(t.adult_price || t.price || 0),
          child_price: Number(t.child_price || 0),
          price: Math.round(price * 100) / 100,
          currency: t.currency || params?.currency || this.currency,
          image: t.img || t.image || '',
          refundable: !!t.refundable,
          amenities: Array.isArray(t.amenities) ? t.amenities : [],
          return_trip: ret,
          trip_type: ret ? 'return' : (params?.trip_type || 'oneway'),
          adults: String(a),
          children: String(c),
          raw: t,
        };
      }).filter(Boolean);
    },

    /** Live trains via the normal web Rail listing endpoint (not mobile api/rail). */
    async _rail(s) {
      const p = s.params || {};
      const from = String(p.from_station || p.from_station_code || p.origin || '').trim();
      const to = String(p.to_station || p.to_station_code || p.destination || '').trim();
      if (!from || !to) {
        return { items: [], emptySuppliers: ['train'] };
      }
      try {
        const body = {
          journey_type: parseInt(p.journey_type, 10) || 0,
          from_station_code: from,
          to_station_code: to,
          from_date: String(p.travel_date || p.date || ''),
          adults: Math.max(1, parseInt(p.adults || 1, 10) || 1),
          children: Math.max(0, parseInt(p.children || 0, 10) || 0),
          infants: Math.max(0, parseInt(p.infants || 0, 10) || 0),
        };
        if (Array.isArray(p.child_ages)) body.child_ages = p.child_ages;
        if (Array.isArray(p.infant_ages)) body.infant_ages = p.infant_ages;
        const r = await fetch(this.root + 'ticket/trainQuery', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        if (!r.ok) return { items: [], emptySuppliers: ['train'] };
        const json = await r.json().catch(() => null);
        const rows = [
          json?.data?.data?.data,
          json?.data?.data,
          json?.data,
        ].find(Array.isArray) || [];
        const items = this._normRails(rows, p);
        return { items, emptySuppliers: items.length ? [] : ['train'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['train'] };
      }
    },

    _normRails(data, params) {
      if (!Array.isArray(data)) return [];
      const timeLabel = (value) => {
        const numeric = Number(value);
        if (numeric > 0) {
          const date = new Date(numeric > 20000000000 ? numeric : numeric * 1000);
          return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], {
            hour: '2-digit', minute: '2-digit', hour12: false,
          });
        }
        return String(value || '').slice(0, 5);
      };
      const cards = [];
      data.forEach((train, trainIndex) => {
        const billable = Math.max(1, Number(train.billable_passengers || 1));
        const seats = (Array.isArray(train?.seats) ? train.seats : []).map((seat) => {
          const available = Number(seat?.numbs ?? seat?.seats ?? 0);
          const seatClass = String(seat?.seat_class || seat?.seat_type || '').trim();
          if (!seatClass) return null;
          const displayTotal = Number(seat.price_total ?? (Number(seat.price || 0) * billable)) || 0;
          const supplierUnit = Number(seat.supplier_order_price
            ?? seat.original_price ?? seat.minPrice ?? seat.price ?? 0);
          const supplierTotal = Number(seat.price_total_limit ?? (supplierUnit * billable)) || 0;
          const seatLabel = seat.seat_class_label || seat.seat_name_english || seat.seat_name || seatClass;
          return {
            seat_class: seatClass,
            seat_class_label: seatLabel,
            seats_available: available,
            numbs: available,
            unit_price: Number(seat.price || 0),
            price_per_seat: Number(seat.price || 0),
            price_base: supplierTotal,
            price_total_limit: supplierTotal,
            price: Math.round(displayTotal * 100) / 100,
            raw: seat,
          };
        }).filter(Boolean);
        const availableSeats = seats.filter((seat) => Number(seat.seats_available) > 0);
        if (!availableSeats.length) return;

        const trainNo = train.train_no || train.traffic_no || '';
        const fromCode = train.from_station_code || params?.from_station_code || '';
        const toCode = train.to_station_code || params?.to_station_code || '';
        const fromName = train.from_station_name || train.from_station_english || params?.from_station_name || fromCode;
        const toName = train.to_station_name || train.to_station_english || params?.to_station_name || toCode;
        const runMins = Number(train.run_time || train.run_time_mins || 0);
        const defaultSeat = availableSeats[0];

        cards.push({
          kind: 'rail',
          supplier: 'train',
          id: [trainNo, fromCode, toCode, trainIndex].join('-'),
          name: trainNo,
          title: [trainNo, fromName + ' → ' + toName].filter(Boolean).join(' '),
          subtitle: timeLabel(train.from_date_time || train.from_time),
          train_no: trainNo,
          traffic_no: trainNo,
          train_type_label: String(train.train_type_label || ''),
          is_quiet_carriage: Number(train.is_quiet_carriage || 0),
          sale_flag: train.sale_flag,
          from_station_code: fromCode,
          to_station_code: toCode,
          from_station_name: fromName,
          to_station_name: toName,
          origin: fromName,
          destination: toName,
          departure_time: timeLabel(train.from_date_time || train.from_time),
          arrival_time: timeLabel(train.to_date_time || train.to_time),
          duration: runMins > 0
            ? Math.floor(runMins / 60) + 'h ' + String(runMins % 60).padStart(2, '0') + 'm'
            : '',
          run_time_mins: runMins,
          date: params?.travel_date || '',
          journey_type: train.journey_type || params?.journey_type || '',
          journey_type_label: '',
          adults: params?.adults || 1,
          children: params?.children || 0,
          infants: params?.infants || 0,
          billable_passengers: billable,
          seats,
          selected_rail_seat_class: defaultSeat.seat_class,
          price: defaultSeat.price,
          currency: params?.currency || this.currency,
          raw: train,
        });
      });
      return cards;
    },

    /** Live Kikoto sailings via the same web endpoint the ferries listing uses. */
    async _ferries(s) {
      const p = s.params || {};
      const depPort = parseInt(p.departure_port_id, 10) || 0;
      const destPort = parseInt(p.destination_port_id, 10) || 0;
      const date = String(p.date || '').trim();
      if (!depPort || !destPort || !date) {
        return { items: [], emptySuppliers: ['kikoto'] };
      }
      const adults = Math.max(1, parseInt(p.adults || 1, 10) || 1);
      const children = Math.max(0, parseInt(p.children || 0, 10) || 0);
      const infant = Math.max(0, parseInt(p.infant || p.infants || 0, 10) || 0);
      const vehicles = Math.max(0, parseInt(p.vehicles || 0, 10) || 0);
      const pets = Math.max(0, parseInt(p.pets || 0, 10) || 0);
      const tripType = String(p.trip_type || 'oneway').toLowerCase() === 'return' ? 'return' : 'oneway';
      const returnDate = String(p.return_date || '').trim();
      const bonuses = (Array.isArray(p.bonuses) ? p.bonuses : [])
        .map((b) => parseInt(b, 10) || 0).filter((b) => b > 0);
      this._loadFerryBonuses(s, true);
      try {
        const body = {
          departure_port_id: depPort,
          destination_port_id: destPort,
          date,
          trip_type: tripType === 'return' && returnDate ? 'return' : 'oneway',
          return_date: returnDate,
          adults,
          children,
          infant,
          passengers: this.ferryPassengerRefs(adults, children, infant),
          vehicles: this.ferryVehicleRefs(vehicles),
          pets: this.ferryPetRefs(pets),
          bonuses,
          vehicle_type_hint: vehicles > 0 ? String(p.vehicle_type || '') : '',
          pet_type_hint: pets > 0 ? String(p.pet_type || '') : '',
          currency: p.currency || this.currency,
          lang: 'en',
        };
        const r = await fetch(this.root + 'api/ferries/search', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        if (!r.ok) return { items: [], emptySuppliers: ['kikoto'] };
        const json = await r.json().catch(() => null);
        if (!json || !json.success) return { items: [], emptySuppliers: ['kikoto'] };
        const outbound = Array.isArray(json.data?.outbound) ? json.data.outbound : [];
        const returns = Array.isArray(json.data?.return) ? json.data.return : [];
        const items = this._normFerries(outbound, returns, p);
        items.sort((a, b) => (a.price || 0) - (b.price || 0));
        return { items, emptySuppliers: items.length ? [] : ['kikoto'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['kikoto'] };
      }
    },

    /**
     * One card per outbound sailing. Round trips pair each outbound with the cheapest
     * return leg, exactly like the site's combined round-trip card.
     */
    _normFerries(outbound, returns, params) {
      if (!Array.isArray(outbound)) return [];
      const wantReturn = String(params?.trip_type || 'oneway').toLowerCase() === 'return'
        && Array.isArray(returns) && returns.length > 0;
      const cheapestReturn = wantReturn
        ? returns.slice().sort((a, b) => this._ferryMinAccPrice(a) - this._ferryMinAccPrice(b))[0]
        : null;
      const cards = [];
      outbound.forEach((sailing, index) => {
        if (!sailing || typeof sailing !== 'object') return;
        const accommodations = this.ferryAccOptions(sailing);
        if (!accommodations.length) return;
        const cheapest = accommodations.slice().sort((a, b) => (a.price || 0) - (b.price || 0))[0];
        const company = sailing.shipping_company || {};
        const retAccs = cheapestReturn ? this.ferryAccOptions(cheapestReturn) : [];
        const retAcc = retAccs.length
          ? retAccs.slice().sort((a, b) => (a.price || 0) - (b.price || 0))[0]
          : null;
        cards.push({
          kind: 'ferries',
          supplier: sailing._supplier || 'kikoto',
          id: [company.id || '', sailing.departure_datetime || '', index].join('-'),
          name: [company.name, sailing.ship_name].filter(Boolean).join(' · ') || 'Ferry',
          title: [company.name, sailing.ship_name].filter(Boolean).join(' · ') || 'Ferry',
          subtitle: this.ferryTimeLabel(sailing.departure_datetime),
          company_id: company.id || sailing.shipping_company_id || 0,
          company: company.name || '',
          ship_name: sailing.ship_name || '',
          departure_port_id: sailing.departure_port_id || params?.departure_port_id || 0,
          destination_port_id: sailing.destination_port_id || params?.destination_port_id || 0,
          origin: params?.departure_port_name || '',
          destination: params?.destination_port_name || '',
          departure_datetime: sailing.departure_datetime || '',
          arrival_datetime: sailing.arrival_datetime || '',
          departure_time: this.ferryTimeLabel(sailing.departure_datetime),
          arrival_time: this.ferryTimeLabel(sailing.arrival_datetime),
          duration_minutes: Number(sailing.duration_minutes || 0),
          duration: this.ferryDurationLabel(sailing.duration_minutes),
          date: params?.date || '',
          return_date: params?.return_date || '',
          trip_type: retAcc ? 'return' : 'oneway',
          services: sailing.services || {},
          route_services: params?.route_services || {},
          accommodations,
          selected_ferry_acc_id: cheapest ? String(cheapest.id) : '',
          adults: params?.adults || 1,
          children: params?.children || 0,
          infant: params?.infant || 0,
          vehicles: params?.vehicles || 0,
          vehicle_type: params?.vehicle_type || '',
          pets: params?.pets || 0,
          pet_type: params?.pet_type || '',
          bonuses: Array.isArray(params?.bonuses) ? params.bonuses : [],
          return_sailing: cheapestReturn || null,
          return_accommodation: retAcc || null,
          price: Math.round(((cheapest?.price || 0) + (retAcc?.price || 0)) * 100) / 100,
          currency: cheapest?.currency || params?.currency || this.currency,
          raw: sailing,
        });
      });
      return cards;
    },

    _ferryMinAccPrice(sailing) {
      const accs = this.ferryAccOptions(sailing);
      if (!accs.length) return Number.MAX_SAFE_INTEGER;
      return Math.min(...accs.map((a) => Number(a.price) || 0));
    },

    async _esim(s) {
      const p = s.params || {};
      const moduleId = String(p.module_id || '').trim();
      const country = String(p.country || '').trim().toLowerCase();
      const packageType = String(p.package_type || 'all').trim().toLowerCase() || 'all';
      if (!moduleId || !country || country.length !== 2) {
        return { items: [], emptySuppliers: ['airalo'] };
      }
      try {
        const url = this.root + 'esim/' + encodeURIComponent(moduleId) + '/'
          + encodeURIComponent(country) + '/' + encodeURIComponent(packageType) + '/packages';
        const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) return { items: [], emptySuppliers: ['airalo'] };
        const json = await r.json().catch(() => null);
        const packages = Array.isArray(json?.packages) ? json.packages : [];
        const items = this._normEsims(packages, p);
        items.sort((a, b) => (a.price || 0) - (b.price || 0));
        return { items, emptySuppliers: items.length ? [] : ['airalo'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['airalo'] };
      }
    },

    _normEsims(data, params) {
      if (!Array.isArray(data)) return [];
      const countryIso = String(params?.country || '').toUpperCase();
      const countryName = params?.country_name || countryIso;
      const moduleId = String(params?.module_id || '');
      return data.map((pkg, index) => {
        const id = String(pkg.id || ('esim-' + index));
        const title = pkg.title || 'eSIM Package';
        return {
          kind: 'esim',
          supplier: 'airalo',
          id,
          name: title,
          title,
          country: pkg.country || countryName,
          country_iso: countryIso,
          data_limit: pkg.data_limit || '',
          duration: pkg.duration || '',
          package_type: pkg.package_type || 'local',
          base_price: Number(pkg.base_price || 0),
          price: Math.round((Number(pkg.price) || 0) * 100) / 100,
          currency: pkg.currency || params?.currency || this.currency,
          module_id: moduleId,
          commission_type: pkg.commission_type || '',
          commission_value: Number(pkg.commission_value || 0),
          image: '',
          raw: pkg,
        };
      }).filter(Boolean);
    },

    async _visa(s) {
      const p = s.params || {};
      const from = String(p.from_country || '').trim().toUpperCase();
      const to = String(p.to_country || '').trim().toUpperCase();
      if (!from || !to || from.length !== 2 || to.length !== 2 || from === to) {
        return { items: [], emptySuppliers: ['visa'] };
      }
      try {
        const body = {
          csrf_token: this.csrfToken || '',
          from_country: from,
          to_country: to,
          travel_date: String(p.travel_date || p.entry_date || ''),
          travelers: Math.max(1, parseInt(p.travelers || 1, 10) || 1),
          visa_type: String(p.visa_type || 'tourist').toLowerCase(),
          processing_speed: String(p.processing_speed || 'standard').toLowerCase(),
          currency: this.currency || '',
        };
        const r = await fetch(this.root + 'api/ai/visa/listing', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': this.csrfToken || '',
          },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        if (!r.ok) return { items: [], emptySuppliers: ['visa'] };
        const json = await r.json().catch(() => null);
        const cards = Array.isArray(json?.data?.cards) ? json.data.cards : [];
        const items = this._normVisas(cards, p);
        return { items, emptySuppliers: items.length ? [] : ['visa'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['visa'] };
      }
    },

    /** Local packages via web POST /api/umrah/listing (app/routes/umrah — CSRF). */
    async _umrah(s) {
      const p = s.params || {};
      let dest = String(p.destination || '').trim();
      if (!dest || dest.toLowerCase() === 'any') {
        dest = 'any';
      }
      try {
        const body = {
          csrf_token: this.csrfToken || '',
          destination: dest,
          start_date: String(p.start_date || ''),
          duration: String(p.duration || 'any'),
          umrah_type: String(p.umrah_type || 'any'),
          services: String(p.services || 'any'),
          adults: parseInt(p.adults ?? 0, 10) || 0,
          children: parseInt(p.children ?? 0, 10) || 0,
          infants: parseInt(p.infants ?? 0, 10) || 0,
          // 0 = return all packages (no AI truncation)
          items_limit: 0,
          currency: this.currency || '',
        };
        const r = await fetch(this.root + 'api/umrah/listing', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': this.csrfToken || '',
          },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        });
        if (!r.ok) return { items: [], emptySuppliers: ['umrah'] };
        const json = await r.json().catch(() => null);
        const cards = Array.isArray(json?.data?.cards) ? json.data.cards : [];
        const items = this._normUmrahs(cards, p);
        return { items, emptySuppliers: items.length ? [] : ['umrah'] };
      } catch (e) {
        return { items: [], emptySuppliers: ['umrah'] };
      }
    },

    _normUmrahs(data, params) {
      if (!Array.isArray(data)) return [];
      const pAdults = Number(params?.adults ?? 0) || 0;
      const pChildren = Number(params?.children ?? 0) || 0;
      const pInfants = Number(params?.infants ?? 0) || 0;
      return data.map((card, index) => {
        if (!card || typeof card !== 'object') return null;
        let adults = Number(card.adults ?? 0) || 0;
        let children = Number(card.children ?? 0) || 0;
        let infants = Number(card.infants ?? 0) || 0;
        // Prefer prompt counts when listing returned 0 (e.g. empty request)
        if (adults <= 0 && pAdults > 0) adults = pAdults;
        if (children <= 0 && pChildren > 0) children = pChildren;
        if (infants <= 0 && pInfants > 0) infants = pInfants;
        return {
          kind: 'umrah',
          supplier: 'umrah',
          id: String(card.id || ('umrah-' + index)),
          umrah_id: Number(card.umrah_id || card.id || 0),
          name: card.name || card.title || 'Umrah',
          title: card.title || card.name || 'Umrah',
          subtitle: card.subtitle || '',
          location: card.location || ((params?.destination && String(params.destination).toLowerCase() !== 'any') ? params.destination : ''),
          days: Number(card.days || 0),
          nights: Number(card.nights || 0),
          umrah_type: card.umrah_type || '',
          umrah_type_id: Number(card.umrah_type_id || 0),
          start_date: card.start_date || params?.start_date || '',
          adults,
          children,
          infants,
          max_adults: Number(card.max_adults ?? 0),
          max_children: Number(card.max_children ?? 0),
          max_infants: Number(card.max_infants ?? 0),
          adult_price: Number(card.adult_price || 0),
          child_price: Number(card.child_price || 0),
          infant_price: Number(card.infant_price || 0),
          price: Number(card.price || 0),
          currency: this.currency || params?.currency || card.currency || 'USD',
          image: card.image || '',
          slug: card.slug || '',
          description: card.description || '',
          inclusions: Array.isArray(card.inclusions) ? card.inclusions : [],
          services: Array.isArray(card.services) ? card.services : [],
          itinerary: Array.isArray(card.itinerary) ? card.itinerary : [],
          raw: card,
        };
      }).filter(Boolean);
    },

    _normVisas(data, params) {
      if (!Array.isArray(data)) return [];
      return data.map((card, index) => {
        if (!card || typeof card !== 'object') return null;
        const inquiry = !!card.is_inquiry_only;
        return {
          kind: 'visa',
          supplier: 'visa',
          id: String(card.id || ('visa-' + index)),
          visa_id: Number(card.visa_id || 0),
          name: card.name || card.title || 'Visa',
          title: card.title || card.name || 'Visa',
          subtitle: card.subtitle || '',
          from_country: card.from_country || params?.from_country || '',
          from_country_name: card.from_country_name || params?.from_country_name || '',
          to_country: card.to_country || params?.to_country || '',
          to_country_name: card.to_country_name || params?.to_country_name || '',
          visa_type: card.visa_type || params?.visa_type || 'tourist',
          visa_type_name: card.visa_type_name || '',
          processing_speed: card.processing_speed || params?.processing_speed || 'standard',
          processing_speed_name: card.processing_speed_name || '',
          processing_speed_desc: card.processing_speed_desc || '',
          entry_date: card.entry_date || params?.entry_date || params?.travel_date || '',
          entry_date_assumed: String(params?.entry_date_assumed || '') === '1',
          travelers: Number(card.travelers || params?.travelers || 1),
          duration_days: Number(card.duration_days || 0),
          govt_fee: Number(card.govt_fee || 0),
          service_fee: Number(card.service_fee || 0),
          price_per_person: Number(card.price_per_person || 0),
          price: inquiry ? 0 : Number(card.price || 0),
          // Always stamp trip currency — never leave inquiry as a random session code (EGP)
          currency: this.currency || params?.currency || card.currency || 'USD',
          is_inquiry_only: inquiry,
          visa_found: !!card.visa_found,
          image: card.image || '',
          listing_url: card.listing_url || '',
          requirements: Array.isArray(card.requirements) ? card.requirements : [],
          raw: card,
        };
      }).filter(Boolean);
    },

    slugify(t) {
      return String(t || 'item').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
    },
    stopsLabel(n) {
      n = Number(n) || 0;
      return n <= 0 ? 'Direct' : (n === 1 ? '1 Stop' : (n + ' Stops'));
    },
    formatCabinClass(c) {
      if (!c) return 'Economy Class';
      let s = String(c).replace(/_/g, ' ').trim();
      if (!s) return 'Economy Class';
      s = s.replace(/\b\w/g, (ch) => ch.toUpperCase());
      if (!/\bclass\b/i.test(s)) s += ' Class';
      return s;
    },
    formatBaggageLabel(v) {
      if (v === null || v === undefined || v === '' || v === '0' || v === 0) return '—';
      return String(v);
    },
    flightOutboundSegments(item) {
      if (!item) return [];
      if (Array.isArray(item.outboundSegments) && item.outboundSegments.length) return item.outboundSegments;
      if (Array.isArray(item.raw?.outboundSegments) && item.raw.outboundSegments.length) return item.raw.outboundSegments;
      const segs = item.raw?.segments;
      if (Array.isArray(segs) && Array.isArray(segs[0])) return segs[0];
      return [];
    },
    flightReturnSegments(item) {
      if (!item) return [];
      if (item.isMultiCity || item.raw?.isMultiCity || item.raw?.type === 'multicity') return [];
      if (Array.isArray(item.returnSegments) && item.returnSegments.length) return item.returnSegments;
      if (Array.isArray(item.raw?.returnSegments) && item.raw.returnSegments.length) return item.raw.returnSegments;
      const segs = item.raw?.segments;
      if (Array.isArray(segs) && Array.isArray(segs[1])) return segs[1];
      return [];
    },
    flightMultiCitySlices(item) {
      if (!item) return [];
      if (!(item.isMultiCity || item.raw?.isMultiCity || item.raw?.type === 'multicity')) return [];
      if (Array.isArray(item.segments) && item.segments.length) return item.segments;
      if (Array.isArray(item.raw?.segments) && item.raw.segments.length) return item.raw.segments;
      return [];
    },
    flightSliceSummary(slice) {
      if (!Array.isArray(slice) || !slice.length) {
        return { first: null, last: null, stops: 0, airline: '', flight_no: '', duration: '' };
      }
      const first = slice[0] || {};
      const last = slice[slice.length - 1] || {};
      return {
        first,
        last,
        stops: Math.max(0, slice.length - 1),
        airline: first.airlineName || first.airline || '',
        flight_no: first.flight_no || '',
        duration: first.duration_time || first.total_duration || '',
      };
    },
    segmentAirlineLogo(seg) {
      if (!seg) return '';
      const code = String(seg.img || seg.airline || '').trim();
      if (!code || code === 'undefined' || code === 'null') return '';
      return 'https://pics.avs.io/200/200/' + encodeURIComponent(code) + '@2x.png';
    },
    listingHref(s) {
      if (!s?.listing_url) return '';
      return this.root + s.listing_url.replace(/^\//, '');
    },
    itemDetailHref(item, section) {
      if (!item) return '#';
      if (item.kind === 'tour') {
        const sl = this.slugify(item.name);
        const ad = item.adults || (section.params?.adults) || '1';
        const ch = item.children || (section.params?.children) || '0';
        const st = item.start_date || (section.params?.start_date) || '';
        const du = item.days || (section.params?.duration) || '1';
        return this.root + 'tour/' + sl + '/' + encodeURIComponent(item.id) + '/' +
          String(item.supplier || '').toLowerCase() + '/' + st + '/' + du + '/' + ad + '-' + ch;
      }
      if (item.kind === 'stay') {
        if (item.redirect) return item.redirect;
        const sl = this.slugify(item.name);
        const ro = (item.adults || '1') + '-' + (item.children || '0');
        return this.root + 'stay/' + sl + '/' + encodeURIComponent(item.id) + '/' +
          String(item.supplier || '').toLowerCase() + '/' + encodeURIComponent(item.chain || '_') + '/' +
          (item.checkin || '') + '/' + (item.checkout || '') + '/' + (item.nationality || '') + '/' +
          (item.rooms || '1') + '/' + ro;
      }
      return this.listingHref(section) || '#';
    },

    // bookFlight kept for optional deep-link; primary path is package booking
    async bookFlight(item, section, key) {
      if (!item || this.bookingIndex !== null) return;
      this.bookingIndex = key;
      try {
        const r = await fetch(this.root + 'api/flight/booking/save-draft', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            flight_data: item.raw || item,
            search_params: section.params || {},
            type: 'flight',
            created_at: new Date().toISOString()
          })
        });
        const d = await r.json();
        if (d.success) window.location.href = this.root + 'flights/booking/' + d.hash;
        else {
          this.bookingIndex = null;
          alert(d.message || 'Failed to save booking.');
        }
      } catch (e) {
        this.bookingIndex = null;
        alert('An error occurred. Please try again.');
      }
    }
  };
};

document.addEventListener('click', function (e) {
  var t = e.target;
  if (!t || !t.closest || !t.closest('#ai-trip-search-btn')) return;
  if (typeof window.aiTripGenerate === 'function') {
    e.preventDefault();
    window.aiTripGenerate();
  }
}, true);

