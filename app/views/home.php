<?php

// themes/default/home.php
@$SECURE or die('Access Denied!');

// IF THE WEBSITE IS OFFLINE
if($GLOBALS['app']['site_offline'] == 1){
   include "offline.php";
   exit;
   die;
}

// SKIP MODULES WITH EMPTY/INVALID TYPE — OTHERWISE HOMEPAGE CREATES A BLANK
// TAB (E.G. eSIM WITH TYPE="") WHOSE SEARCH PARTIAL 404s AND SPINS FOREVER.
$modules = array_values(array_filter($GLOBALS['modules'] ?? [], function ($m) {
    $type = strtolower(trim((string)($m['type'] ?? '')));
    return $type !== '' && (bool) preg_match('/^[a-z]+$/', $type);
}));
$grouped = array_reduce($modules, function($c, $m) {
    $type = strtolower(trim((string)$m['type']));
    $c[$type] = $c[$type] ?? ['type' => $type, 'name' => $type, 'order' => $m['order'], 'icon' => $m['icon'] ?? '', 'modules' => []];
    if (empty($c[$type]['icon']) && !empty($m['icon'])) $c[$type]['icon'] = $m['icon'];
    $c[$type]['modules'][] = $m;
    return $c;
}, []);

$mods = array_values($grouped);
// Sort by active count descending
usort($mods, function($a, $b) {
    $aActive = count($a['modules']);
    $bActive = count($b['modules']);
    if ($bActive !== $aActive) {
        return $bActive - $aActive; // Most active first
    }

   // Stable alphabetical fallback when counts are equal
   return strcmp((string)$a['type'], (string)$b['type']);
});

// ICON MAPPING FOR MODULES
$iconMap = [
    'flights' => 'hgi-airplane-01',
    'stays' => 'hgi-bed-single-01',
    'cars' => 'hgi-car-01',
    'tours' => 'hgi-maps',
    'visa' => 'hgi-passport',
    'cruises' => 'hgi-ferry-boat',
    'esim' => 'hgi-smartphone-02'
];

$initialTabKey = $mods[array_key_first($mods)]['type'] ?? '';

// Build a JS-safe list of valid tab types so URL-hash sync can validate input.
$validTabTypes = array_values(array_map(fn($m) => $m['type'], $mods));

// Home AI search (only when Trip Planner is enabled in settings)
$aiSearchEnabled = function_exists('aiTripIsEnabled') ? aiTripIsEnabled($GLOBALS['db'] ?? null) : false;
if ($aiSearchEnabled) {
    $validTabTypes[] = 'ai';
}

$aiSuggestionsData = function_exists('aiSuggestionsForSearch')
    ? aiSuggestionsForSearch($GLOBALS['db'] ?? null, 0, 20)
    : [];

$aiEnabledModuleTypes = ($aiSearchEnabled && function_exists('aiTripEnabledModuleTypes'))
    ? array_values(aiTripEnabledModuleTypes($GLOBALS['db'] ?? null))
    : [];
?>

<div class="hero xl:min-h-[500px] lg:min-h-[460px] sm:min-h-[420px] min-h-0 h-auto relative flex items-center py-24 md:py-12  flex-col justify-center">
   <!-- Dark gradient overlay - transparent top to dark bottom -->
   <div class="absolute inset-0 z-[2] bg-gradient-to-b from-transparent via-black/60 to-black/80"></div>

   <style>
   @keyframes hero-kenburns {
     0%   { transform: scale(1);    }
     50%  { transform: scale(1.08); }
     100% { transform: scale(1);    }
   }
   .hero-bg-img {
     animation: hero-kenburns 20s ease-in-out infinite;
     will-change: transform;
     transform-origin: center center;
   }
   [x-cloak] { display: none !important; }
   .ai-suggestion-chip { max-width: 16rem; }
   @media (min-width: 640px) { .ai-suggestion-chip { max-width: 15rem; } }
   @media (min-width: 1024px) { .ai-suggestion-chip { max-width: 16rem; } }
   </style>
   <div class="absolute inset-0 overflow-hidden bg-slate-900">
     <img id="heroBg" alt="Hero" data-tab="hero-tab-1" width="" height="" decoding="async" data-nimg="1"
          class="hero-bg-img w-full h-full object-cover opacity-0 transition-opacity duration-700 ease-out"
          style="color:transparent" sizes="(max-width: 768px) 100vw, 1335px"
          data-src="<?=versionedAssetUrl('uploads/global/cover.png')?>"
          x-data
          x-init="let el = $el, reveal = () => el.classList.remove('opacity-0');
                  el.addEventListener('load', reveal, { once: true });
                  el.src = el.dataset.src;
                  if (el.complete && el.naturalWidth) reveal()">
   </div>

   <?php if (!empty($mods)): ?>
   <!-- HIDDEN PROBE: LETS waitTailwindCdn DETECT WHEN PLAY CDN FINISHED @apply (field-box) -->
   <div id="tw-cdn-ready-probe" class="field-box" aria-hidden="true"
        style="position:absolute;width:0;height:0;overflow:hidden;opacity:0;pointer-events:none;left:0;top:0"></div>
   <div class="container relative z-10 xl:pb-12 md:pb-8 pb-6 md:translate-y-4 translate-y-2"
        x-data='{
            activeTab: <?= json_encode($initialTabKey) ?>,
            validTabs: <?= json_encode($validTabTypes) ?>,
            searchLoaded: {},
            searchReq: {},
            aiSearchEnabled: <?= $aiSearchEnabled ? 'true' : 'false' ?>,
            aiQuery: "",
            aiError: "",
            aiLoading: false,
            aiRoot: <?= json_encode(root) ?>,
            aiEnabledModules: <?= json_encode($aiEnabledModuleTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
            aiSuggestions: <?= json_encode($aiSuggestionsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
            aiSuggestionsPreview: 5,
            aiMoreOpen: false,
            syncFromHash() {
                // ACCEPT BOTH #stays AND #/stays
                const h = (window.location.hash || "").replace(/^#\/?/, "").toLowerCase();
                if (h && this.validTabs.includes(h)) { this.activeTab = h; }
            },
            // SAFARI: PLAY CDN BUILDS CSS ASYNC. LAZY HTML MUST STAY HIDDEN UNTIL
            // @apply COMPONENTS (field-box) AND NEW UTILITIES ARE IN THE STYLESHEET.
            waitTailwindCdn(root) {
                return new Promise(resolve => {
                    const start = performance.now();
                    const ready = () => {
                        if (!window.tailwind) return false;
                        const probe = (root && root.querySelector)
                            ? (root.querySelector(".field-box") || root.querySelector(".field-box-icon") || root.querySelector(".absolute.left-3"))
                            : document.getElementById("tw-cdn-ready-probe");
                        if (!probe) return false;
                        const cs = getComputedStyle(probe);
                        if (probe.classList.contains("field-box") && cs.display === "flex") return true;
                        if (cs.position === "absolute") return true;
                        return false;
                    };
                    const tick = () => {
                        if (ready() || performance.now() - start > 4000) { resolve(); return; }
                        requestAnimationFrame(tick);
                    };
                    requestAnimationFrame(tick);
                });
            },
            async loadSearch(t) {
                if (!t || t === "ai") return;
                if (!this.validTabs.includes(t) || this.searchLoaded[t] || this.searchReq[t]) return;
                this.searchReq[t] = true;
                try {
                    const el = document.getElementById("search-panel-" + t);
                    if (!el) { this.searchLoaded[t] = true; return; }
                    const wrap = el.parentElement;
                    // LOCK SPINNER HEIGHT — FORM STAYS INVISIBLE UNTIL CDN STYLES APPLY
                    wrap.style.height = wrap.offsetHeight + "px";
                    wrap.style.overflow = "hidden";
                    el.style.visibility = "hidden";
                    el.style.opacity = "0";

                    // WAIT FOR INITIAL PLAY-CDN BUILD (SAFARI IS OFTEN SLOWER THAN CHROME)
                    await this.waitTailwindCdn(null);

                    const html = await fetch("<?=root?>partials/search/" + t).then(r => r.ok ? r.text() : "");
                    if (!html) {
                        el.innerHTML = "<p class=\"text-sm text-red-600 text-center py-6\">Search form could not be loaded. Please refresh.</p>";
                        el.style.visibility = "visible";
                        el.style.opacity = "1";
                        wrap.style.height = "";
                        wrap.style.overflow = "";
                        this.searchLoaded[t] = true;
                        return;
                    }
                    el.innerHTML = html;
                    el.querySelectorAll("script").forEach(old => {
                        const s = document.createElement("script");
                        // SKIP type: CLOUDFLARE ROCKET LOADER REWRITES IT ON THE
                        // LIVE SITE, WHICH WOULD STOP THE BROWSER EXECUTING THE
                        // SCRIPT. ALWAYS RUN AS PLAIN JS, OUTSIDE ROCKET LOADER.
                        for (const a of old.attributes) {
                            if (a.name === "type") continue;
                            s.setAttribute(a.name, a.value);
                        }
                        s.setAttribute("data-cfasync", "false");
                        s.textContent = old.textContent;
                        old.replaceWith(s);
                    });
                    // RE-RUN DATEPICKER SO IT PICKS UP INJECTED DATE FIELDS
                    const dp = document.createElement("script");
                    dp.src = "<?=root?>assets/js/datepicker.js?v=<?= file_exists(__DIR__ . '/../../assets/js/datepicker.js') ? filemtime(__DIR__ . '/../../assets/js/datepicker.js') : time() ?>";
                    dp.setAttribute("data-cfasync", "false");
                    document.body.appendChild(dp);

                    if (window.Alpine && typeof window.Alpine.initTree === "function") {
                        window.Alpine.initTree(el);
                    }

                    // WAIT FOR CDN MUTATIONOBSERVER / JIT TO STYLE THE INJECTED FORM
                    await this.waitTailwindCdn(el);

                    const from = wrap.offsetHeight;
                    this.searchLoaded[t] = true;
                    this.$nextTick(() => {
                        // MEASURE FULL HEIGHT WHILE STILL INVISIBLE, THEN ANIMATE OPEN
                        el.style.visibility = "visible";
                        el.style.opacity = "0";
                        wrap.style.height = "auto";
                        const to = wrap.scrollHeight;
                        wrap.style.height = from + "px";
                        void wrap.offsetHeight;
                        requestAnimationFrame(() => {
                            wrap.style.transition = "height .35s ease";
                            wrap.style.height = to + "px";
                            el.style.opacity = "1";
                            setTimeout(() => {
                                wrap.style.height = "";
                                wrap.style.overflow = "";
                                wrap.style.transition = "";
                            }, 400);
                        });
                    });
                } finally {
                    if (!this.searchLoaded[t]) this.searchReq[t] = false;
                }
            },
            animateTabSwitch() {
                const box = this.$refs.panels;
                if (!box) return;
                const from = box.offsetHeight;
                this.$nextTick(() => {
                    const to = box.offsetHeight;
                    if (Math.abs(to - from) < 2) return;
                    box.style.height = from + "px";
                    box.style.overflow = "hidden";
                    box.style.transition = "height .3s ease";
                    void box.offsetHeight;
                    box.style.height = to + "px";
                    setTimeout(() => {
                        box.style.height = "";
                        box.style.overflow = "";
                        box.style.transition = "";
                    }, 330);
                });
            },
            applyAiSuggestion(s) {
                if (!s) return;
                // Insert full plain query (or full label) into the search box
                const q = String(s.query || s.label || "").trim();
                this.aiQuery = q;
                this.aiError = "";
                this.aiMoreOpen = false;
                this.$nextTick(() => {
                    this.syncAiInputHtml();
                    if (this.$refs.aiSearchInput) this.$refs.aiSearchInput.focus();
                    this.animateTabSwitch();
                });
            },
            previewAiSuggestions() {
                const list = Array.isArray(this.aiSuggestions) ? this.aiSuggestions : [];
                return list.slice(0, this.aiSuggestionsPreview || 5);
            },
            moreAiSuggestions() {
                const list = Array.isArray(this.aiSuggestions) ? this.aiSuggestions : [];
                return list.slice(this.aiSuggestionsPreview || 5);
            },
            hasMoreAiSuggestions() {
                return this.moreAiSuggestions().length > 0;
            },
            aiSuggestionIconToken(s) {
                const label = String((s && s.label) || "");
                const m = label.match(/^:[a-z0-9_]+:(?:#[0-9A-Fa-f]{3,8}:)?/i);
                return m ? m[0] : ":auto_awesome:#0058E6:";
            },
            formatSuggestionLabel(label) {
                return String(label || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/gi, (_, icon, hex) => {
                        const color = hex ? "#" + hex : "#0058E6";
                        return `<span class="material-symbols-outlined text-base align-middle mx-0.5 shrink-0" style="color:${color}">${icon}</span>`;
                    });
            },
            syncAiInputHtml() {
                const el = this.aiSearchBox();
                if (!el) return;
                el.innerHTML = this.formatSuggestionLabel(this.aiQuery || "");
            },
            aiSearchBox() {
                return this.$refs.aiSearchInput || document.getElementById("ai-home-q");
            },
            onAiInput() {
                const el = this.aiSearchBox();
                if (!el) return;
                this.aiQuery = this.serializeAiInput(el);
            },
            serializeAiInput(root) {
                let out = "";
                const walk = (node) => {
                    if (!node) return;
                    if (node.nodeType === 3) {
                        out += node.textContent || "";
                        return;
                    }
                    if (node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains("material-symbols-outlined")) {
                        const name = (node.textContent || "").trim().replace(/[^a-z0-9_]/gi, "");
                        if (!name) return;
                        const color = String(node.style && node.style.color || "").trim();
                        let hex = "";
                        const m = color.match(/#([0-9A-Fa-f]{3,8})/);
                        if (m) hex = m[1];
                        else if (/^rgb/i.test(color)) {
                            const nums = color.match(/\d+/g);
                            if (nums && nums.length >= 3) {
                                hex = nums.slice(0, 3).map((n) => Number(n).toString(16).padStart(2, "0")).join("");
                            }
                        }
                        out += hex ? (":" + name + ":#" + hex + ":") : (":" + name + ":");
                        return;
                    }
                    if (node.tagName === "BR") {
                        out += "\n";
                        return;
                    }
                    Array.from(node.childNodes || []).forEach(walk);
                };
                Array.from(root.childNodes || []).forEach(walk);
                return out.replace(/\u00a0/g, " ");
            },
            plainAiQuery() {
                return String(this.aiQuery || "")
                    .replace(/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/gi, " ")
                    .replace(/\s+/g, " ")
                    .trim();
            },
            _aiModuleKeywordMap() {
                return {
                    flights: ["flight", "flights", "fly", "airfare", "airline", "airport"],
                    stays: ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"],
                    cars: ["car", "cars", "rent a car", "rental", "vehicle"],
                    tours: ["tour", "tours", "activity", "activities", "excursion"],
                    visa: ["visa", "visas"],
                    umrah: ["umrah", "hajj"],
                    esim: ["esim", "e-sim", "e sim", "sim card", "data sim", "travel sim", "tourist sim", "data plan", "data package", "mobile data", "roaming"],
                    cruises: ["cruise", "cruises"],
                    ferries: ["ferry", "ferries"],
                    bus: ["bus", "buses", "coach"],
                    rail: ["rail", "train", "trains", "railway", "whoosh"]
                };
            },
            _aiModuleLabel(mod) {
                const map = {
                    flights: "Flights", stays: "Hotels", cars: "Cars", tours: "Tours",
                    visa: "Visa", umrah: "Umrah", esim: "eSIM", cruises: "Cruises",
                    ferries: "Ferries", bus: "Bus", rail: "Trains"
                };
                const m = String(mod || "").toLowerCase();
                return map[m] || (m ? (m.charAt(0).toUpperCase() + m.slice(1)) : "Travel");
            },
            detectAiModulesInQuery(q) {
                const text = String(q || "").toLowerCase();
                if (!text) return [];
                const map = this._aiModuleKeywordMap();
                const found = [];
                Object.keys(map).forEach((mod) => {
                    const hit = (map[mod] || []).some((word) => {
                        const w = String(word || "").toLowerCase();
                        if (!w) return false;
                        try {
                            return new RegExp("\\b" + w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "\\b", "i").test(text);
                        } catch (e) {
                            return text.indexOf(w) !== -1;
                        }
                    });
                    if (hit) found.push(mod);
                });
                return found;
            },
            disabledAiModulesOnlyMessage(q) {
                const enabled = (Array.isArray(this.aiEnabledModules) ? this.aiEnabledModules : [])
                    .map((m) => String(m || "").toLowerCase()).filter(Boolean);
                const requested = this.detectAiModulesInQuery(q);
                if (!requested.length) return "";
                const allowed = requested.filter((m) => enabled.indexOf(m) !== -1);
                if (allowed.length) return "";
                const labels = requested.map((m) => this._aiModuleLabel(m)).filter(Boolean);
                const avail = enabled.map((m) => this._aiModuleLabel(m)).filter(Boolean);
                let msg = labels.length === 1
                    ? (labels[0] + " is not available for AI Trip right now.")
                    : "That travel option is not available for AI Trip right now.";
                if (avail.length) {
                    const examples = avail.slice(0, 3);
                    msg += " Try " + examples.join(", ")
                        + (avail.length > 3 ? ", or another shown on top" : "")
                        + ".";
                } else {
                    msg += " Please try another travel option.";
                }
                return msg;
            },
            missingFlightRouteMessage(q) {
                if (!this._isAiFlightAsk(q)) return "";
                if (this._aiPromptHasArrival(q)) return "";
                return "Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).";
            },
            _isAiFlightAsk(q) {
                const text = String(q || "").trim().toLowerCase();
                if (!text) return false;
                const requested = this.detectAiModulesInQuery(text);
                return requested.indexOf("flights") !== -1
                    || text.indexOf("airfare") !== -1
                    || text.indexOf("round trip") !== -1
                    || text.indexOf("round-trip") !== -1
                    || text.indexOf("one way") !== -1
                    || text.indexOf("one-way") !== -1;
            },
            _aiPromptHasArrival(q) {
                const text = String(q || "").trim().toLowerCase();
                if (!text) return false;
                if (text.indexOf(" to ") !== -1 || text.indexOf("to ") === 0) return true;
                if (/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+[a-z]/i.test(text)) {
                    const m = text.match(/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
                    const place = m && m[1] ? String(m[1]).trim() : "";
                    const filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|flights?|fly)$/i;
                    if (place && !filler.test(place)) return true;
                }
                return this._aiCityBeforeFlight(text) !== "";
            },
            _aiCityBeforeFlight(q) {
                const text = String(q || "").trim().toLowerCase();
                const stops = ["find","look","looking","get","getting","show","give","see","check","me","a","an","the","cheap","cheapest","need","want","book","search","please","for","from","with","and","or","my","our","some","any","best","good","last","minute","deal","deals","offer","offers","option","options","available","ticket","tickets","tomorrow","today","tonight","now","next","this","that","round","trip","one","way","flight","flights","fly","airfare"];
                const words = text.replace(/[^a-z0-9\s-]/g, " ").split(/\s+/).filter(Boolean);
                let flightAt = -1;
                for (let i = 0; i < words.length; i++) {
                    if (words[i] === "flight" || words[i] === "flights" || words[i] === "fly") {
                        flightAt = i;
                        break;
                    }
                }
                if (flightAt < 1) return "";
                for (let i = flightAt - 1; i >= 0; i--) {
                    const w = words[i];
                    if (w.length >= 3 && stops.indexOf(w) === -1) return w;
                }
                return "";
            },
            runAiSearch() {
                if (window.homeAiSearch && typeof window.homeAiSearch.submit === "function") {
                    window.homeAiSearch.submit();
                }
            }
        }'
        x-init='
            syncFromHash();
            if (activeTab !== "ai") loadSearch(activeTab);
            $watch("activeTab", v => {
                animateTabSwitch();
                if (v !== "ai") loadSearch(v);
                if (v && history.replaceState) {
                    history.replaceState(null, "", "#" + v);
                }
                if (v === "ai") {
                    $nextTick(() => $refs.aiSearchInput && $refs.aiSearchInput.focus());
                }
            });
            window.addEventListener("hashchange", () => syncFromHash());
        '>

      <!-- Hero Text -->
      <div class="mb-5 md:mb-6 text-start opacity-100 transition-opacity duration-500">
         <h1 class="text-white text-2xl sm:text-3xl lg:text-3xl font-bold mb-3 leading-tight" style="text-shadow: 0 2px 8px rgba(0,0,0,0.4)"><?=T::hero_text1?></h1>
         <p class="text-white/85 text-base sm:text-lg max-w-3xl" style="text-shadow: 0 1px 4px rgba(0,0,0,0.3)"><?=T::hero_text2?></p>
      </div>

      <div class="bg-white backdrop-blur-xl rounded-2xl border border-white/50 shadow-2xl relative">
         <!-- DYNAMIC TAB BUTTONS FROM MODULES -->
         <nav class="flex border-b border-gray-200 overflow-y-hidden overflow-x-auto xl:overflow-x-visible md:[&::-webkit-scrollbar]:hidden rounded-t-2xl bg-white" style="scrollbar-width: auto;" role="tablist" aria-orientation="horizontal">
            <style>
               @media (min-width: 768px) {
                  nav { scrollbar-width: none; -ms-overflow-style: none; }
               }
            </style>
            <?php foreach ($mods as $key => $module):
               // Type-tab icons: Material Symbol name (e.g. "hotel") OR type fallback map.
               // Supplier logos like "wanderbeds.png" are for admin only — never dump into material-symbols.
                $hgiIcon = $iconMap[$module['type']] ?? 'hgi-ticket-01';
                $moduleIcon = !empty($module['icon']) ? trim((string)$module['icon']) : null;
                $isImageIcon = $moduleIcon && (bool) preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $moduleIcon);
                if ($isImageIcon) {
                    $moduleIcon = null;
                }

                $moduleName = $module['name'];
                if ($moduleName == 'flights') $moduleName = T::flights;
                elseif ($moduleName == 'stays') $moduleName = T::stays;
                elseif ($moduleName == 'cars') $moduleName = T::cars;
                elseif ($moduleName == 'visa') $moduleName = T::visa;
                elseif ($moduleName == 'tours') $moduleName = T::tours;
                elseif ($moduleName == 'umrah') $moduleName = T::umrah;
                elseif ($moduleName == 'cruises') $moduleName = T::cruises;
                elseif ($moduleName == 'esim') $moduleName = 'eSIM';
            ?>
                <button type="button"
                    @click="activeTab = '<?= $module['type'] ?>'"
                  class="py-4 px-2 md:px-3 lg:px-4 flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-300 border-b-2 -mb-px flex-shrink-0"
                    :class="activeTab === '<?= $module['type'] ?>'
                        ? 'bg-primary/10 text-primary border-primary'
                        : 'bg-transparent text-gray-600 border-transparent hover:text-gray-900 hover:bg-gray-100/50'"
                    role="tab">
               <?php if ($moduleIcon): ?>
                  <span class="material-symbols-outlined font-medium text-xl"><?= htmlspecialchars($moduleIcon) ?></span>
               <?php else: ?>
                  <i class="hgi hgi-stroke <?= $hgiIcon ?> text-xl"></i>
               <?php endif; ?>
               <span class="text-xs md:text-sm font-semibold capitalize"><?= $moduleName ?></span>
            </button>
            <?php endforeach; ?>

            <?php if ($aiSearchEnabled): ?>
            <button type="button"
                @click="activeTab = 'ai'"
                class="ml-auto sticky right-0 z-[1] py-4 px-2 md:px-3 lg:px-4 flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-300 border-b-2 -mb-px flex-shrink-0"
                :class="activeTab === 'ai'
                    ? 'bg-primary/10 text-primary border-primary'
                    : 'bg-transparent text-gray-600 border-transparent hover:text-gray-900 hover:bg-gray-100/50'"
                role="tab">
               <span class="material-symbols-outlined font-medium text-xl">auto_awesome</span>
               <span class="text-xs md:text-sm font-semibold">AI Trip Planner</span>
            </button>
            <?php endif; ?>
         </nav>

         <!-- DYNAMIC TAB CONTENT FROM MODULES — x-ref USED TO ANIMATE HEIGHT ON TAB SWITCH -->
         <div class="relative rounded-b-2xl" x-ref="panels">
            <?php foreach ($mods as $key => $module): ?>
              <?php $isInitial = ($module['type'] === $initialTabKey); ?>
              <div x-show="activeTab === '<?= $module['type'] ?>'"
                 x-transition:enter="transition-opacity duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="transition-all duration-200"
                 style="display: <?= $isInitial ? 'block' : 'none' ?>;"
                 :style="activeTab === '<?= $module['type'] ?>' ? 'display: block;' : 'display: none;'"
                 role="tabpanel">
               <!-- DEFAULT HEIGHT = ONE FIELD ROW (LIKE TOURS/STAYS); GROWS SMOOTHLY
                    TO THE FORM'S REAL HEIGHT ONCE LOADED (ANIMATED IN loadSearch) -->
               <div class="w-full p-4 md:p-6 relative min-h-[90px] md:min-h-[106px]">
                  <!-- LOADER FIRST — FORM STAYS HIDDEN UNTIL FETCH + TAILWIND CDN READY -->
                  <div x-show="!searchLoaded['<?= $module['type'] ?>']"
                       x-transition:leave="transition-opacity duration-300"
                       x-transition:leave-start="opacity-100"
                       x-transition:leave-end="opacity-0"
                       class="absolute inset-0 flex items-center justify-center z-10 bg-white rounded-b-2xl">
                     <svg class="animate-spin text-primary" width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                  </div>
                  <div id="search-panel-<?= $module['type'] ?>" style="opacity:0;visibility:hidden;transition:opacity .35s ease"></div>
               </div>
            </div>
            <?php endforeach; ?>

            <?php if ($aiSearchEnabled): ?>
            <div x-show="activeTab === 'ai'"
               x-transition:enter="transition-opacity duration-200"
               x-transition:enter-start="opacity-0"
               x-transition:enter-end="opacity-100"
               x-transition:leave="transition-opacity duration-200"
               x-transition:leave-start="opacity-100"
               x-transition:leave-end="opacity-0"
               class="transition-all duration-200"
               style="display: none;"
               :style="activeTab === 'ai' ? 'display: block;' : 'display: none;'"
               role="tabpanel">
               <div class="w-full p-4 md:p-6">
                  <div class="rounded-2xl border border-primary/30 bg-white p-4 md:p-5">
                     <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-primary">auto_awesome</span>
                        <h3 class="text-base md:text-lg font-semibold text-gray-900">AI Trip Planner</h3>
                     </div>

                     <div class="relative">
                        <div
                           id="ai-home-q"
                           x-ref="aiSearchInput"
                           class="w-full rounded-xl border-0 bg-transparent px-0 py-1 text-sm md:text-base leading-relaxed text-gray-700 focus:outline-none focus:ring-0 min-h-[110px] empty:before:content-[attr(data-placeholder)] empty:before:text-gray-400"
                           contenteditable="true"
                           role="textbox"
                           data-placeholder="Describe the trip you need… (e.g. Round trip Dubai to Paris in October for 2 adults, beach resort stay)"
                           @input="onAiInput()"
                        ></div>
                     </div>

                     <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 border-t border-primary/10 pt-3">
                        <span class="inline-flex items-center gap-1">
                           <span class="material-symbols-outlined text-sm">info</span>
                           Minimum 10 characters for better recommendations
                        </span>
                        <span id="ai-home-chars">0 chars</span>
                     </div>
                  </div>

                  <div class="mt-4 flex items-center gap-2" x-show="previewAiSuggestions().length" x-cloak>
                     <div class="flex items-center gap-2 min-w-0 flex-1 overflow-x-auto scrollbar-none flex-nowrap">
                        <span class="text-sm text-gray-500 shrink-0">Try these:</span>
                        <template x-for="(s, i) in previewAiSuggestions()" :key="'ai-prev-'+i">
                           <button type="button"
                              class="ai-suggestion-chip inline-flex items-center shrink-0 rounded-full border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-700 hover:border-primary/40 hover:bg-primary/5 transition-colors"
                              :title="s.query || s.label"
                              @click.stop.prevent="applyAiSuggestion(s)">
                              <span class="inline-flex items-center gap-1 min-w-0 truncate"
                                    x-html="formatSuggestionLabel(s.chip || s.label)"></span>
                           </button>
                        </template>
                     </div>
                     <button type="button"
                        x-show="hasMoreAiSuggestions()"
                        x-cloak
                        class="inline-flex items-center gap-1.5 shrink-0 rounded-full border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/15 hover:border-primary/40 transition-colors"
                        @click.stop.prevent="aiMoreOpen = true">
                        <span class="material-symbols-outlined text-base">apps</span>
                        Try more
                     </button>
                  </div>

                  <!-- Try more suggestions modal -->
                  <div x-show="aiMoreOpen"
                       x-cloak
                       class="fixed inset-0 z-[120] flex items-end sm:items-center justify-center p-0 sm:p-4"
                       style="display: none;"
                       role="dialog"
                       aria-modal="true"
                       aria-labelledby="ai-more-title"
                       @keydown.escape.window="aiMoreOpen = false">
                     <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]"
                          @click="aiMoreOpen = false"></div>
                     <div class="relative w-full sm:max-w-lg md:max-w-xl max-h-[85vh] sm:max-h-[80vh] flex flex-col rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl border border-primary/20 overflow-hidden"
                          x-transition:enter="transition ease-out duration-200"
                          x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-95"
                          x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                          x-transition:leave="transition ease-in duration-150"
                          x-transition:leave-start="opacity-100"
                          x-transition:leave-end="opacity-0 translate-y-2"
                          @click.stop>
                        <div class="shrink-0 px-5 pt-5 pb-4 border-b border-primary/10 bg-gradient-to-br from-primary/10 via-white to-white">
                           <div class="flex items-start justify-between gap-3">
                              <div class="flex items-start gap-3 min-w-0">
                                 <div class="w-10 h-10 rounded-xl bg-primary text-primary-foreground flex items-center justify-center shrink-0 shadow-sm">
                                    <span class="material-symbols-outlined text-xl">auto_awesome</span>
                                 </div>
                                 <div class="min-w-0">
                                    <h3 id="ai-more-title" class="text-base font-semibold text-slate-900">More trip ideas</h3>
                                    <p class="text-xs text-slate-500 mt-0.5">Pick one to fill the AI search box</p>
                                 </div>
                              </div>
                              <button type="button"
                                      class="w-9 h-9 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 inline-flex items-center justify-center shrink-0"
                                      @click="aiMoreOpen = false"
                                      aria-label="Close">
                                 <span class="material-symbols-outlined text-xl">close</span>
                              </button>
                           </div>
                        </div>
                        <div class="flex-1 overflow-y-auto overscroll-contain p-4 sm:p-5 space-y-2">
                           <template x-for="(s, i) in moreAiSuggestions()" :key="'ai-more-'+i">
                              <button type="button"
                                      class="w-full text-left group rounded-xl border border-slate-200 bg-white hover:border-primary/40 hover:bg-primary/5 px-3.5 py-3 transition-all"
                                      @click="applyAiSuggestion(s)">
                                 <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary group-hover:bg-white border border-primary/20 shrink-0"
                                          x-html="formatSuggestionLabel(aiSuggestionIconToken(s))"></span>
                                    <span class="min-w-0 flex-1 text-sm text-slate-700 leading-snug"
                                          x-text="s.query || s.label"></span>
                                    <span class="material-symbols-outlined text-slate-300 group-hover:text-primary text-lg shrink-0 mt-0.5">arrow_forward</span>
                                 </div>
                              </button>
                           </template>
                        </div>
                        <div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50/80 flex items-center justify-between gap-2">
                           <span class="text-xs text-slate-500" x-text="moreAiSuggestions().length + ' more ideas'"></span>
                           <button type="button" class="btn light text-xs py-1.5 px-3" @click="aiMoreOpen = false">Close</button>
                        </div>
                     </div>
                  </div>

                  <div class="mt-4 relative z-30">
                     <button type="button"
                        id="ai-home-search-btn"
                        class="btn relative z-30"
                        onclick="if(window.homeAiSearch){window.homeAiSearch.submit();}return false;">
                        <span class="material-symbols-outlined text-base">auto_awesome</span>
                        <span>Search with AI</span>
                     </button>
                  </div>

                  <p id="ai-home-error" class="mt-3 text-sm text-red-600 min-h-[1.25rem]"></p>
               </div>
            </div>
            <?php endif; ?>
         </div>
      </div>
   </div>
   <?php if (!empty($aiSearchEnabled)): ?>
   <script data-cfasync="false">
   window.homeAiSearch = (function () {
      var root = <?= json_encode(root) ?>;
      var enabledModules = <?= json_encode(array_values(array_map('strtolower', $aiEnabledModuleTypes ?? [])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
      if (!Array.isArray(enabledModules)) enabledModules = [];
      var moduleKeywords = {
         flights: ["flight", "flights", "fly", "airfare", "airline", "airport"],
         stays: ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"],
         cars: ["car", "cars", "rent a car", "rental", "vehicle"],
         tours: ["tour", "tours", "activity", "activities", "excursion"],
         visa: ["visa", "visas"],
         umrah: ["umrah", "hajj"],
         esim: ["esim", "e-sim", "e sim", "sim card", "data sim", "travel sim", "tourist sim", "data plan", "data package", "mobile data", "roaming"],
         cruises: ["cruise", "cruises"],
         ferries: ["ferry", "ferries"],
         bus: ["bus", "buses", "coach"],
         rail: ["rail", "train", "trains", "railway", "whoosh"]
      };
      var moduleLabels = {
         flights: "Flights", stays: "Hotels", cars: "Cars", tours: "Tours",
         visa: "Visa", umrah: "Umrah", esim: "eSIM", cruises: "Cruises",
         ferries: "Ferries", bus: "Bus", rail: "Trains"
      };
      var flightWords = ["flight", "flights", "fly", "airfare", "airline", "airport"];
      var stops = ["find","look","looking","get","getting","show","give","see","check","me","a","an","the","cheap","cheapest","need","want","book","search","please","for","from","with","and","or","my","our","some","any","best","good","last","minute","deal","deals","offer","offers","option","options","available","ticket","tickets","tomorrow","today","tonight","now","next","this","that","round","trip","one","way","flight","flights","fly","airfare"];
      function readQuery() {
         var el = document.getElementById("ai-home-q");
         return String(el && (el.innerText || el.textContent) || "").replace(/\s+/g, " ").trim();
      }
      function setError(msg) {
         var p = document.getElementById("ai-home-error");
         if (p) p.textContent = msg || "";
      }
      function updateChars() {
         var n = document.getElementById("ai-home-chars");
         if (n) n.textContent = readQuery().length + " chars";
      }
      function hasWord(text, word) {
         return (" " + text + " ").indexOf(" " + word + " ") !== -1;
      }
      function moduleLabel(mod) {
         var m = String(mod || "").toLowerCase();
         return moduleLabels[m] || (m ? (m.charAt(0).toUpperCase() + m.slice(1)) : "Travel");
      }
      function detectModules(q) {
         var text = String(q || "").toLowerCase();
         if (!text) return [];
         var found = [];
         Object.keys(moduleKeywords).forEach(function (mod) {
            var words = moduleKeywords[mod] || [];
            for (var i = 0; i < words.length; i++) {
               if (hasWord(text, String(words[i]).toLowerCase())) {
                  found.push(mod);
                  return;
               }
            }
         });
         return found;
      }
      function disabledModulesMessage(q) {
         var requested = detectModules(q);
         if (!requested.length) return "";
         var allowed = requested.filter(function (m) { return enabledModules.indexOf(m) !== -1; });
         if (allowed.length) return "";
         var labels = requested.map(moduleLabel).filter(Boolean);
         var avail = enabledModules.map(moduleLabel).filter(Boolean);
         var msg = labels.length === 1
            ? (labels[0] + " is not available for AI Trip right now.")
            : "That travel option is not available for AI Trip right now.";
         if (avail.length) {
            var examples = avail.slice(0, 3);
            msg += " Try " + examples.join(", ")
               + (avail.length > 3 ? ", or another shown on top" : "")
               + ".";
         } else {
            msg += " Please try another travel option.";
         }
         return msg;
      }
      function isFlightAsk(q) {
         var text = String(q || "").toLowerCase();
         var i;
         for (i = 0; i < flightWords.length; i++) {
            if (hasWord(text, flightWords[i])) return true;
         }
         return text.indexOf("round trip") !== -1 || text.indexOf("one way") !== -1 || text.indexOf("one-way") !== -1;
      }
      function cityBeforeFlight(q) {
         var text = String(q || "").toLowerCase().replace(/[^a-z0-9\s-]/g, " ");
         var words = text.split(/\s+/);
         var clean = [];
         var i;
         for (i = 0; i < words.length; i++) {
            if (words[i]) clean.push(words[i]);
         }
         words = clean;
         var flightAt = -1;
         for (i = 0; i < words.length; i++) {
            if (words[i] === "flight" || words[i] === "flights" || words[i] === "fly") {
               flightAt = i;
               break;
            }
         }
         if (flightAt < 1) return "";
         for (i = flightAt - 1; i >= 0; i--) {
            if (words[i].length >= 3 && stops.indexOf(words[i]) === -1) return words[i];
         }
         return "";
      }
      function cityAfterFlight(q) {
         var text = String(q || "").toLowerCase();
         var m = text.match(/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (!m || !m[1]) return "";
         var place = String(m[1]).trim();
         var filler = /^(today|tomorrow|tonight|now|me|you|us|look|looking|find|get|show|give|need|want|book|search|please|cheap|cheapest|best|good|a|an|the|flights?|fly|hotels?|stays?|tours?|cars?|tickets?)$/i;
         if (!place || filler.test(place)) return "";
         return place;
      }
      function hasArrival(q) {
         var text = String(q || "").toLowerCase();
         if (text.indexOf(" to ") !== -1 || text.indexOf("to ") === 0) return true;
         if (cityAfterFlight(text) !== "") return true;
         return cityBeforeFlight(text) !== "";
      }
      function isStayAsk(q) {
         var text = String(q || "").toLowerCase();
         var words = ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"];
         var i;
         for (i = 0; i < words.length; i++) {
            if (hasWord(text, words[i])) return true;
         }
         return false;
      }
      function hasStayDestination(q) {
         var text = String(q || "").toLowerCase();
         if (hasArrival(text)) return true;
         var m = text.match(/\b(?:hotels?|stays?|stay|resort|hostel|apartment)\s+(?:in|at|near|around|for|to)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (m && m[1]) {
            var place = String(m[1]).trim();
            var filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|room|rooms|hotels?|stays?)$/i;
            if (place && !filler.test(place)) return true;
         }
         m = text.match(/\b([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\s+(?:hotels?|stays?|stay|resort|hostel|apartment)\b/i);
         if (m && m[1]) {
            place = String(m[1]).trim();
            filler = /^(find|look|looking|need|want|book|search|please|cheap|cheapest|best|good|a|an|the|my|our|for)$/i;
            if (place && !filler.test(place)) return true;
         }
         m = text.match(/\b(?:in|at|near|to)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (m && m[1]) {
            place = String(m[1]).trim();
            filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|room|rooms|hotels?|stays?)$/i;
            if (place && !filler.test(place)) return true;
         }
         return false;
      }
      function submit() {
         var q = readQuery();
         setError("");
         if (q.length < 10) {
            setError("Please enter at least 10 characters for better recommendations.");
            return false;
         }
         var disabledMsg = disabledModulesMessage(q);
         if (disabledMsg) {
            setError(disabledMsg);
            return false;
         }
         if (isFlightAsk(q) && !hasArrival(q)) {
            setError("Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).");
            return false;
         }
         if (isStayAsk(q) && !hasStayDestination(q)) {
            setError("Please mention a hotel destination in your prompt (for example, hotels in Dubai).");
            return false;
         }
         try { sessionStorage.setItem("ai_trip_pending_q", q); } catch (err) {}
         window.location.href = root + "ai-trip";
         return false;
      }
      document.addEventListener("click", function (e) {
         var t = e.target;
         if (!t || !t.closest) return;
         if (t.closest("#ai-home-search-btn")) {
            e.preventDefault();
            submit();
         }
      }, true);
      document.addEventListener("input", function (e) {
         if (e.target && e.target.id === "ai-home-q") updateChars();
      }, true);
      document.addEventListener("keydown", function (e) {
         if (!e.target || e.target.id !== "ai-home-q") return;
         if (e.key !== "Enter" || e.shiftKey) return;
         e.preventDefault();
         submit();
      }, true);
      if (document.readyState === "loading") {
         document.addEventListener("DOMContentLoaded", updateChars);
      } else {
         updateChars();
      }
      return { submit: submit };
   })();
   </script>
   <?php endif; ?>
   <?php else: ?>
   <!-- MESSAGE WHEN NO MODULES AVAILABLE -->
   <div class="xl:max-w-6xl w-full px-3 mx-auto relative z-10 xl:pb-16 md:pb-14 pb-0">
      <div class="w-full bg-white rounded-3xl p-6 text-center">
         <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-6">
               <div class="flex items-start justify-center">
                  <span class="material-symbols-outlined text-red-600 mr-3 text-lg">error</span>
                  <div>
                     <h3 class="text-red-800 font-medium text-lg mb-2"><?php echo T::no_travel_modules_enabled ?></h3>
                     <p class="text-red-700 text-sm mb-4"><?php echo T::enable_at_least_one_travel_module ?></p>
                     <a href="<?=root?>admin/settings/modules" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700">
                        <span class="material-symbols-outlined mr-2 text-sm">settings</span>
                        <?php echo T::enable_modules_now ?>
                     </a>
                  </div>
               </div>
            </div>
         <?php else: ?>
            <div class="w-16 h-16 mx-auto mb-4 bg-blue-100 rounded-full flex items-center justify-center">
               <span class="material-symbols-outlined text-blue-600 text-2xl">travel_explore</span>
            </div>
            <h3 class="text-gray-800 font-medium text-xl mb-2"><?php echo T::booking_services_coming_soon ?></h3>
            <p class="text-gray-600 text-sm"><?php echo T::check_back_soon_for_deals ?></p>
         <?php endif; ?>
      </div>
   </div>
   <?php endif; ?>
</div>

<div style="content-visibility:auto; contain-intrinsic-size: 1400px;">
<?php
// Show eSIM featured first, then other modules, then blogs
$enabledModules = array_column($modules, 'type');

// Show eSIM featured first if available (featured.php self-lazy-loads its data)
if (in_array('esim', $enabledModules) && file_exists(views.'modules/esim/featured.php')) {
    include views.'modules/esim/featured.php';
}

// Show featured sections for other module types.
// EACH SECTION IS SERVER-RENDERED AND STAYS IN THE DOM; A SKELETON SHOWS BY
// DEFAULT AND THE REAL CONTENT FADES IN WHEN THE SECTION SCROLLS INTO VIEW.
$featuredOrder = array_column($mods, 'type');
foreach ($featuredOrder as $modType) {
    if ($modType === 'esim') {
        continue; // Already shown above
    }
    $featuredFile = views.'modules/'.$modType.'/featured.php';
    if (!in_array($modType, $enabledModules) || !file_exists($featuredFile)) {
        continue;
    }
?>
<div x-data="{ shown: false }"
     x-init='const io = new IntersectionObserver((e) => { if (e[0].isIntersecting) { shown = true; io.disconnect() } }, { rootMargin: "140px" }); io.observe($el)'>
    <div x-show="!shown" class="container py-5 mt-5">
        <div class="animate-pulse">
            <div class="h-6 w-48 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-72 bg-gray-100 rounded mb-5"></div>
            <div class="flex gap-3 overflow-hidden">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="w-[250px] h-40 bg-gray-100 rounded-xl border border-gray-200 shrink-0"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <div x-show="shown" x-cloak
         x-transition:enter="transition-opacity duration-500"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <?php include $featuredFile; ?>
    </div>
</div>
<?php } ?>

<div x-data="{ shown: false }"
     x-init='const io = new IntersectionObserver((e) => { if (e[0].isIntersecting) { shown = true; io.disconnect() } }, { rootMargin: "140px" }); io.observe($el)'>
    <div x-show="!shown" class="container py-5 mt-5">
        <div class="animate-pulse">
            <div class="h-6 w-48 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-72 bg-gray-100 rounded mb-5"></div>
            <div class="flex gap-3 overflow-hidden">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="w-[250px] h-40 bg-gray-100 rounded-xl border border-gray-200 shrink-0"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <div x-show="shown" x-cloak
         x-transition:enter="transition-opacity duration-500"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <?php include views.'modules/blogs/featured.php'; ?>
    </div>
</div>

<?php
// Mobile App Section - Dynamic from already-loaded global settings
$showApps = (($GLOBALS['app']['show_apps'] ?? '1') == '1');
$androidLink = $GLOBALS['app']['android_store'] ?? '';
$iosLink = $GLOBALS['app']['ios_store'] ?? '';
$hasAppLinks = !empty($androidLink) || !empty($iosLink);

if ($showApps && $hasAppLinks):
?>
<div class="container">
<section class="my-5 relative min-h-[200px] flex items-center justify-start rounded-lg">
  <div class="absolute inset-0 z-0 rounded-lg overflow-hidden">
   <img src="<?=versionedAssetUrl('assets/img/mob.webp')?>" alt="<?=T::mobile_apps?>" class="w-full h-full object-cover" loading="lazy" decoding="async" fetchpriority="low">
    <div class="absolute inset-0 bg-black bg-opacity-10"></div>
  </div>
  <div class="relative z-10 text-start text-white px-4 sm:px-8 mx-start">
    <h1 class="text-xl sm:text-4xl md:text-3xl lg:text-3xl font-bold mb-1 leading-tight">
      <?=T::travel_on_the_go?>
    </h1>
    <p class="!text-sm sm:text-lg md:text-xl lg:text-2xl leading-relaxed max-w-3xl mx-auto">
      <?=T::book_from_your_phone?>
    </p>
    <div class="flex flex-wrap gap-2 sm:gap-3 mt-5">
      <?php if (!empty($iosLink)): ?>
      <a href="<?= htmlspecialchars($iosLink) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center bg-black hover:bg-gray-900 text-white rounded-lg px-2 sm:px-4 py-2.5 transition-all duration-200 hover:scale-105">
        <svg class="w-7 h-7 me-2" viewBox="0 0 384 512" fill="currentColor"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
        <div class="text-start">
          <div class="text-[10px] leading-none opacity-80"><?=T::download_on_the?></div>
          <div class="text-base font-semibold leading-tight">App Store</div>
        </div>
      </a>
      <?php endif; ?>
      <?php if (!empty($androidLink)): ?>
      <a href="<?= htmlspecialchars($androidLink) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center bg-black hover:bg-gray-900 text-white rounded-lg px-2 sm:px-4 py-2.5 transition-all duration-200 hover:scale-105">
        <svg class="w-6 h-6 me-3" viewBox="0 0 24 24" fill="currentColor"><path d="M3.609 1.814L13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm10.89 10.893l2.302 2.302-10.937 6.333 8.635-8.635zm3.199-3.199l2.807 1.627a1 1 0 0 1 0 1.73l-2.808 1.627L15.206 12l2.492-2.492zM5.864 2.658L16.802 8.99l-2.303 2.303-8.635-8.635z"/></svg>
        <div class="text-start">
          <div class="text-[10px] leading-none opacity-80"><?=T::get_it_on?></div>
          <div class="text-base font-semibold leading-tight">Google Play</div>
        </div>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>
</div>
<?php endif; ?>
</div>