<?php
@$SECURE or die('Access Denied!');
// AI Trip Planner — search + side-drawer wizard (one pick per module)
$aiLegacyQ  = $aiLegacyQ ?? '';
$aiCurrency = $_SESSION['app_currency'] ?? ($GLOBALS['app']['currency'] ?? 'USD');
$aiCurrencyRates = [];
$aiRatesDb = $GLOBALS['db'] ?? ($db ?? null);
if ($aiRatesDb) {
    try {
        $rateRows = $aiRatesDb->select('currencies', ['name', 'rate'], ['status' => '1']);
        if (!$rateRows) {
            $rateRows = $aiRatesDb->select('currencies', ['name', 'rate'], ['status' => 1]);
        }
        foreach (($rateRows ?: []) as $row) {
            $code = strtoupper(trim((string)($row['name'] ?? '')));
            $rate = (float)($row['rate'] ?? 0);
            if ($code !== '' && $rate > 0) {
                $aiCurrencyRates[$code] = $rate;
            }
        }
    } catch (\Throwable $e) {
        // keep empty — totals fall back to raw sum
    }
}
if (!empty($_SESSION['app_currency_rate']) && !empty($_SESSION['app_currency'])) {
    $sessCode = strtoupper(trim((string)$_SESSION['app_currency']));
    $sessRate = (float)$_SESSION['app_currency_rate'];
    if ($sessCode !== '' && $sessRate > 0) {
        $aiCurrencyRates[$sessCode] = $sessRate;
    }
}
$aiSuggestions = function_exists('aiSuggestionsForSearch')
    ? aiSuggestionsForSearch($GLOBALS['db'] ?? ($db ?? null), 0, 20)
    : [];

// Tour types for AI trip filters (same source as normal tours search)
$tourTypeOptions = [
    ['value' => '', 'name' => 'Any Type', 'icon' => 'category'],
];
$aiSettingsDb = $GLOBALS['db'] ?? ($db ?? null);
if ($aiSettingsDb) {
    try {
        $typeRows = $aiSettingsDb->select('tours_settings', ['id', 'setting_label', 'icon'], [
            'setting_type' => 'tour_type',
            'status' => 1,
            'ORDER' => ['setting_label' => 'ASC'],
        ]);
        if (!$typeRows) {
            $typeRows = $aiSettingsDb->select('tours_settings', ['id', 'setting_label', 'icon'], [
                'setting_type' => 'tour_type',
                'status' => '1',
                'ORDER' => ['setting_label' => 'ASC'],
            ]);
        }
        foreach (($typeRows ?: []) as $row) {
            $label = trim((string)($row['setting_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $icon = trim((string)($row['icon'] ?? ''));
            if ($icon === '') {
                $icon = 'tour';
            }
            $tourTypeOptions[] = [
                'value' => (string)(int)($row['id'] ?? 0),
                'name'  => $label,
                'icon'  => $icon,
                'slug'  => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        // keep Any Type only
    }
}

// Umrah filters — same sources as normal umrah search (Duration / Type / Services)
$umrahDurationOptions = [
    ['value' => '', 'name' => 'Any duration', 'icon' => 'schedule'],
];
$umrahTypeOptions = [
    ['value' => '', 'name' => 'Any Type', 'icon' => 'category'],
];
$umrahServiceOptions = [];
$umrahLocationOptions = [
    ['value' => '', 'name' => 'Any location'],
];
$umrahServiceDefaultIcons = [
    'flight'  => 'flight',
    'car'     => 'transfer_within_a_station',
    'hotel'   => 'hotel',
    'service' => 'concierge',
];
if ($aiSettingsDb) {
    try {
        $seenLoc = [];
        // Distinct umrah.location from active packages (plain select — avoid Medoo [!] quirks)
        foreach (($aiSettingsDb->select('umrah', ['location'], [
            'status' => 1,
        ]) ?: []) as $row) {
            $loc = trim((string)($row['location'] ?? ''));
            if ($loc === '' || strcasecmp($loc, 'any') === 0) {
                continue;
            }
            $key = strtolower($loc);
            if (isset($seenLoc[$key])) {
                continue;
            }
            $seenLoc[$key] = true;
            $umrahLocationOptions[] = ['value' => $loc, 'name' => $loc];
        }
        usort($umrahLocationOptions, static function ($a, $b) {
            if (($a['value'] ?? '') === '') {
                return -1;
            }
            if (($b['value'] ?? '') === '') {
                return 1;
            }
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        foreach (($aiSettingsDb->select('umrah_settings', ['id', 'setting_label', 'icon', 'metadata'], [
            'setting_type' => 'duration',
            'status' => 1,
            'ORDER' => ['id' => 'ASC'],
        ]) ?: []) as $row) {
            $label = trim((string)($row['setting_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $meta = json_decode((string)($row['metadata'] ?? ''), true);
            $umrahDurationOptions[] = [
                'value'    => (string)(int)($row['id'] ?? 0),
                'name'     => $label,
                'icon'     => trim((string)($row['icon'] ?? '')) ?: 'schedule',
                'min_days' => is_array($meta) ? (int)($meta['min_days'] ?? 0) : 0,
                'max_days' => (is_array($meta) && array_key_exists('max_days', $meta) && $meta['max_days'] !== null && $meta['max_days'] !== '')
                    ? (int)$meta['max_days'] : null,
            ];
        }
        foreach (($aiSettingsDb->select('umrah_settings', ['id', 'setting_label', 'icon'], [
            'setting_type' => 'umrah_type',
            'status' => 1,
            'ORDER' => ['setting_label' => 'ASC'],
        ]) ?: []) as $row) {
            $label = trim((string)($row['setting_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $umrahTypeOptions[] = [
                'value' => (string)(int)($row['id'] ?? 0),
                'name'  => $label,
                'icon'  => trim((string)($row['icon'] ?? '')) ?: 'mosque',
            ];
        }
        foreach (($aiSettingsDb->select('umrah_settings', ['id', 'setting_label', 'setting_type', 'icon'], [
            'setting_type' => ['service', 'hotel', 'flight', 'car'],
            'status' => 1,
            'ORDER' => ['setting_type' => 'ASC', 'id' => 'ASC'],
        ]) ?: []) as $row) {
            $stype = (string)($row['setting_type'] ?? 'service');
            $umrahServiceOptions[] = [
                'id'    => (string)(int)($row['id'] ?? 0),
                'label' => trim((string)($row['setting_label'] ?? '')),
                'type'  => $stype,
                'icon'  => trim((string)($row['icon'] ?? '')) ?: ($umrahServiceDefaultIcons[$stype] ?? 'check_circle'),
            ];
        }
    } catch (\Throwable $e) {
        // keep defaults
    }
}

$aiStayCountries = [];
$sessionNationality = strtoupper(trim((string) ($_SESSION['hotel_nationality'] ?? '')));
if ($sessionNationality === 'NULL' || !preg_match('/^[A-Z]{2}$/', $sessionNationality)) {
    $sessionNationality = '';
}
if ($aiSettingsDb && function_exists('countriesList')) {
    try {
        foreach (countriesList($aiSettingsDb) as $c) {
            $iso = strtoupper(trim((string) ($c['iso'] ?? '')));
            $name = trim((string) ($c['nicename'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $iso) && $name !== '') {
                $aiStayCountries[] = ['iso' => $iso, 'nicename' => $name];
            }
        }
        if ($sessionNationality !== '' && function_exists('countryIsoFromLabel')) {
            $sessionNationality = countryIsoFromLabel($aiSettingsDb, $sessionNationality);
        }
    } catch (\Throwable $e) {
        $aiStayCountries = [];
    }
}

// Active Airalo countries for AI eSIM country picker (same source as /esim search).
$aiEsimCountries = [];
if ($aiSettingsDb) {
    try {
        $rows = $aiSettingsDb->select('airalo_countries', ['iso', 'nicename'], [
            'status' => 1,
            'ORDER' => ['nicename' => 'ASC'],
        ]);
        foreach ((array) $rows as $c) {
            $iso = strtoupper(trim((string) ($c['iso'] ?? '')));
            $name = trim((string) ($c['nicename'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $iso) && $name !== '') {
                $aiEsimCountries[] = ['iso' => $iso, 'nicename' => $name];
            }
        }
    } catch (\Throwable $e) {
        $aiEsimCountries = [];
    }
}

$aiEnabledModuleTypes = function_exists('aiTripEnabledModuleTypes')
    ? aiTripEnabledModuleTypes($GLOBALS['db'] ?? ($db ?? null))
    : [];

$cfg = json_encode([
    'root'                  => root,
    'currency'              => $aiCurrency,
    'currencyRates'         => $aiCurrencyRates,
    'legacyQ'               => $aiLegacyQ,
    'suggestions'           => $aiSuggestions,
    'enabledModules'        => array_values($aiEnabledModuleTypes),
    'tourTypeOptions'      => $tourTypeOptions,
    'umrahDurationOptions'  => $umrahDurationOptions,
    'umrahTypeOptions'      => $umrahTypeOptions,
    'umrahServiceOptions'   => $umrahServiceOptions,
    'umrahLocationOptions'  => $umrahLocationOptions,
    'stayCountries'         => $aiStayCountries,
    'esimCountries'         => $aiEsimCountries,
    'sessionNationality'    => $sessionNationality,
    'csrfToken'             => class_exists('CSRF') ? CSRF::getToken() : '',
    // Soft session fallback from the normal flights search form (IATA / city label).
    'sessionDeparture'      => [
        'airport' => strtoupper(trim((string) ($_SESSION['from_airport'] ?? ''))),
        'city' => trim((string) ($_SESSION['from_airport_name'] ?? ($_SESSION['from_city'] ?? ''))),
    ],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$brand = '#0058E6';
?>
<style>
[x-cloak]{display:none!important}
:root{--ai-brand:#0058E6;--ai-brand-soft:#E8F0FC;--ai-brand-mid:#3D7EF0}
.ai-suggestion-chip{max-width:16rem}
@media (min-width:640px){.ai-suggestion-chip{max-width:15rem}}
@media (min-width:1024px){.ai-suggestion-chip{max-width:16rem}}
.scrollbar-none{scrollbar-width:none;-ms-overflow-style:none}
.scrollbar-none::-webkit-scrollbar{display:none;width:0;height:0}
@keyframes plane-soar{
  0%{left:-90px;top:78px;transform:translateY(0) rotate(-6deg);opacity:0}
  10%{opacity:1}
  45%{left:45%;top:48px;transform:translateY(0) rotate(2deg)}
  100%{left:calc(100% + 40px);top:72px;transform:translateY(0) rotate(4deg);opacity:0}
}
@keyframes cloud-l{0%,100%{transform:translateX(0)}50%{transform:translateX(-14px)}}
@keyframes cloud-r{0%,100%{transform:translateX(0)}50%{transform:translateX(12px)}}
@keyframes pulse-dot{0%,100%{transform:scale(.75);opacity:.4}50%{transform:scale(1.15);opacity:1}}
@keyframes fade-path{to{stroke-dashoffset:0}}
@keyframes brand-glow{0%,100%{box-shadow:0 0 0 0 rgba(0,88,230,.25)}50%{box-shadow:0 0 0 10px rgba(0,88,230,0)}}
.anim-plane{position:absolute;animation:plane-soar 3.6s ease-in-out infinite;will-change:left,top,transform}
.anim-cl{animation:cloud-l 5s ease-in-out infinite alternate}
.anim-cr{animation:cloud-r 7s ease-in-out infinite alternate}
.anim-d1{animation:pulse-dot 1.4s .0s ease-in-out infinite}
.anim-d2{animation:pulse-dot 1.4s .45s ease-in-out infinite}
.anim-d3{animation:pulse-dot 1.4s .9s ease-in-out infinite}
.ai-loading-stage{overflow:hidden;contain:paint}
.ai-search-panel{border-color:#c7dbf8}
.ai-search-panel:focus-within{border-color:var(--ai-brand);box-shadow:0 0 0 3px rgba(0,88,230,.12)}
.ai-btn-brand{background:var(--ai-brand)}
.ai-btn-brand:hover{background:#0046b8}
.ai-loading-glow{animation:brand-glow 2s ease-in-out infinite}
/* Lock page scroll while suggestions modal is open */
html.ai-trip-scroll-lock,
html.ai-trip-scroll-lock body{
  overflow:hidden!important;
  overscroll-behavior:none;
}
html.ai-trip-scroll-lock body.ai-trip-scroll-lock-fixed{
  position:fixed;
  left:0;
  right:0;
  width:100%;
}
</style>
<?php
$aiTripJs = __DIR__ . '/../../../assets/js/ai/trip-page.js';
$aiTripJsV = is_file($aiTripJs) ? filemtime($aiTripJs) : time();
?>
<script src="<?= root ?>assets/js/ai/trip-page.js?v=<?= $aiTripJsV ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<div class="bg-gradient-to-b from-[#E8F0FC] via-white to-slate-50 min-h-[75vh] py-6 md:py-10"
     x-data='aiTripPage(<?= $cfg ?>)'>
  <div class="container max-w-7xl mx-auto px-3 md:px-4">
    
    <!-- Search and Selection View -->
    <div x-show="!showFinalSummary">
      <!-- Search accordion -->
      <div class="rounded-2xl border ai-search-panel bg-white shadow-sm mb-6 overflow-hidden">
      <!-- Collapsed accordion: Modify Search to Expand -->
      <button type="button"
              x-show="!searchExpanded"
              x-cloak
              class="w-full flex items-center justify-between gap-3 px-4 md:px-5 py-3.5 text-left hover:bg-[#F5F8FE] transition-colors"
              @click="openModifySearch()"
              :aria-expanded="searchExpanded.toString()">
        <div class="flex items-center gap-2.5 min-w-0">
          <span class="material-symbols-outlined text-[22px] shrink-0" style="color:<?= $brand ?>">edit_note</span>
          <div class="min-w-0">
            <p class="text-sm md:text-base font-semibold leading-snug" style="color:<?= $brand ?>">
              Modify Search to Expand
            </p>
            <p class="text-[11px] text-slate-500 mt-0.5 truncate"
               x-show="departureCity"
               x-text="'Departing from ' + departureCity"></p>
          </div>
        </div>
        <span class="material-symbols-outlined text-2xl shrink-0"
              style="color:<?= $brand ?>">expand_more</span>
      </button>

      <!-- Expanded form (no x-cloak — default open before Alpine hydrates) -->
      <div x-show="searchExpanded"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 -translate-y-1"
           x-transition:enter-end="opacity-100 translate-y-0">
        <div class="flex items-center justify-between gap-2 px-4 md:px-5 pt-4 md:pt-5 mb-3"
             x-show="loading || sections.length" x-cloak>
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-xl" style="color:<?= $brand ?>">auto_awesome</span>
            <h1 class="text-lg md:text-xl font-semibold text-gray-900">AI Trip Planner</h1>
          </div>
          <button type="button"
                  class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 px-2 py-1 rounded-lg hover:bg-gray-50"
                  @click="collapseSearch()">
            Collapse
            <span class="material-symbols-outlined text-base rotate-180">expand_more</span>
          </button>
        </div>

        <div class="px-4 md:px-5" :class="(loading || sections.length) ? 'pb-4 md:pb-5' : 'py-4 md:py-5'">
          <div class="flex items-center gap-2 mb-3" x-show="!loading && !sections.length">
            <span class="material-symbols-outlined text-xl" style="color:<?= $brand ?>">auto_awesome</span>
            <h1 class="text-lg md:text-xl font-semibold text-gray-900">AI Trip Planner</h1>
          </div>

          <!-- Editable departure context (not part of the free-text prompt) -->
          <div class="mb-3 relative" @click.outside="departureEditOpen = false">
            <button type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="departureNeedsInput
                      ? 'border-amber-300 bg-amber-50 text-amber-800'
                      : 'border-[#c7dbf8] bg-[#F5F8FE] text-slate-700 hover:border-primary/40'"
                    @click.stop="openDepartureEditor()">
              <span class="material-symbols-outlined text-[16px]"
                    x-text="departureLoading ? 'progress_activity' : (departureCity ? 'flight_takeoff' : 'add_location_alt')"
                    :class="departureLoading ? 'animate-spin' : ''"></span>
              <span x-text="departureIndicatorLabel()"></span>
              <span class="material-symbols-outlined text-[15px] opacity-70">edit</span>
            </button>
            <p x-show="departureNeedsInput && !departureEditOpen" x-cloak
               class="mt-1.5 text-[11px] text-amber-700">Where are you departing from?</p>

            <div x-show="departureEditOpen" x-cloak
                 class="absolute z-30 mt-2 w-full max-w-sm rounded-xl border border-gray-200 bg-white shadow-xl p-3">
              <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400 mb-1.5">
                Departing from
              </label>
              <input type="text"
                     x-model="departureSearch"
                     @input.debounce.300ms="searchDepartureAirports()"
                     @keydown.escape.prevent="departureEditOpen = false"
                     placeholder="City or airport…"
                     class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#0058E6]/30 focus:border-[#0058E6]"
                     autocomplete="off">
              <div class="mt-2 max-h-48 overflow-y-auto space-y-1">
                <p x-show="departureSearchLoading" class="text-xs text-slate-400 px-1 py-2">Searching…</p>
                <p x-show="!departureSearchLoading && departureSearch.trim().length >= 2 && !departureResults.length"
                   class="text-xs text-slate-400 px-1 py-2">No airports found</p>
                <template x-for="(a, ai) in departureResults" :key="'dep-'+ai+'-'+(a.id || a.ap || '')">
                  <button type="button"
                          class="w-full text-left rounded-lg px-2.5 py-2 hover:bg-[#F5F8FE] transition-colors"
                          @click="selectDepartureAirport(a)">
                    <div class="text-sm font-medium text-slate-800"
                         x-text="(a.cityname || a.city || a.name || a.airportname || '') + (a.id ? ' (' + a.id + ')' : '')"></div>
                    <div class="text-[11px] text-slate-400"
                         x-text="a.airportname || a.name || a.displayname || ''"></div>
                  </button>
                </template>
              </div>
              <div class="mt-2 flex justify-between gap-2">
                <button type="button" class="text-xs text-slate-500 hover:text-slate-700"
                        @click="clearDeparture()">Clear</button>
                <button type="button" class="text-xs font-semibold text-primary"
                        @click="departureEditOpen = false">Done</button>
              </div>
            </div>
          </div>

          <textarea id="ai-trip-q" x-model="query" rows="3" maxlength="500"
            placeholder="Describe the trip you need… (e.g. Dubai to Paris in October for 2 adults with hotel)"
            class="w-full rounded-xl border border-[#c7dbf8] bg-[#F5F8FE] px-4 py-3 text-sm md:text-base leading-relaxed focus:outline-none focus:ring-2 focus:ring-[#0058E6]/40 focus:border-[#0058E6] resize-y min-h-[88px]"
            @keydown.meta.enter.prevent="generateFromBox()" @keydown.ctrl.enter.prevent="generateFromBox()"></textarea>
          <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 border-t border-[#c7dbf8]/60 pt-3">
            <span class="inline-flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">info</span>
              Minimum 10 characters for better recommendations
            </span>
            <span x-text="(query||'').length + ' chars'"></span>
          </div>
          <div class="mt-4 flex items-center gap-2" x-show="suggestions.length" x-cloak>
            <div class="flex items-center gap-2 min-w-0 flex-1 overflow-x-auto scrollbar-none flex-nowrap pb-0.5">
              <span class="text-sm text-gray-500 shrink-0">Try these:</span>
              <template x-for="(s, i) in previewSuggestions()" :key="'sug-'+i">
                <button type="button"
                        class="ai-suggestion-chip inline-flex items-center shrink-0 rounded-full border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-700 hover:border-primary/40 hover:bg-primary/5 transition-colors"
                        :title="s.query || s.label"
                        @click="applySuggestion(s)">
                  <span class="inline-flex items-center gap-1 min-w-0 truncate"
                        x-html="formatSuggestionChip(s.chip || s.label)"></span>
                </button>
              </template>
            </div>
            <button type="button"
                    x-show="hasMoreSuggestions()"
                    class="inline-flex items-center gap-1.5 shrink-0 rounded-full border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/15 hover:border-primary/40 transition-colors"
                    @click="suggestionsMoreOpen = true">
              <span class="material-symbols-outlined text-base">apps</span>
              Try more
            </button>
          </div>

          <!-- Try more suggestions modal -->
          <div x-show="suggestionsMoreOpen"
               x-cloak
               class="fixed inset-0 z-[120] flex items-end sm:items-center justify-center p-0 sm:p-4"
               role="dialog"
               aria-modal="true"
               aria-labelledby="ai-trip-more-title"
               @keydown.escape.window="suggestionsMoreOpen = false">
            <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]" @click="suggestionsMoreOpen = false"></div>
            <div class="relative w-full sm:max-w-lg md:max-w-xl max-h-[85vh] sm:max-h-[80vh] flex flex-col rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl border border-primary/20 overflow-hidden"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 @click.stop>
              <div class="shrink-0 px-5 pt-5 pb-4 border-b border-primary/10 bg-gradient-to-br from-primary/10 via-white to-white">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex items-start gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-primary text-primary-foreground flex items-center justify-center shrink-0 shadow-sm">
                      <span class="material-symbols-outlined text-xl">auto_awesome</span>
                    </div>
                    <div class="min-w-0">
                      <h3 id="ai-trip-more-title" class="text-base font-semibold text-slate-900">More trip ideas</h3>
                      <p class="text-xs text-slate-500 mt-0.5">Pick one to fill your trip description</p>
                    </div>
                  </div>
                  <button type="button"
                          class="w-9 h-9 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 inline-flex items-center justify-center"
                          @click="suggestionsMoreOpen = false"
                          aria-label="Close">
                    <span class="material-symbols-outlined text-xl">close</span>
                  </button>
                </div>
              </div>
              <div class="flex-1 overflow-y-auto overscroll-contain p-4 sm:p-5 space-y-2">
                <template x-for="(s, i) in moreSuggestions()" :key="'sug-more-'+i">
                  <button type="button"
                          class="w-full text-left group rounded-xl border border-slate-200 bg-white hover:border-primary/40 hover:bg-primary/5 px-3.5 py-3 transition-all"
                          @click="applySuggestion(s)">
                    <div class="flex items-start gap-3">
                      <span class="mt-0.5 inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary border border-primary/20 shrink-0"
                            x-html="formatSuggestionChip(suggestionIconToken(s))"></span>
                      <span class="min-w-0 flex-1 text-sm text-slate-700 leading-snug" x-text="s.query || s.label"></span>
                      <span class="material-symbols-outlined text-slate-300 group-hover:text-primary text-lg shrink-0 mt-0.5">arrow_forward</span>
                    </div>
                  </button>
                </template>
              </div>
              <div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50/80 flex items-center justify-between gap-2">
                <span class="text-xs text-slate-500" x-text="moreSuggestions().length + ' more ideas'"></span>
                <button type="button" class="btn light text-xs py-1.5 px-3" @click="suggestionsMoreOpen = false">Close</button>
              </div>
            </div>
          </div>
          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button"
              id="ai-trip-search-btn"
              class="btn"
              onclick="if(window.aiTripGenerate){window.aiTripGenerate();}return false;">
              <span class="material-symbols-outlined text-base">auto_awesome</span>
              <span>Search with AI</span>
            </button>
            <a href="<?= root ?>" class="btn light">← Home</a>
          </div>
          <p id="ai-trip-error" class="mt-3 text-sm text-red-600 min-h-[1.25rem]" x-text="error"></p>
        </div>
      </div>
    </div>

    <?php require views . 'ai/partials/trip-loading.php'; ?>
    <?php require views . 'ai/partials/trip-drawer.php'; ?>

    </div>
    <!-- End Search and Selection View -->

    <!-- Final Verification Summary View -->
    <div x-show="showFinalSummary" x-cloak class="animate-fade-in mb-6">
      <div class="bg-white rounded-2xl shadow-sm border border-[#c7dbf8] overflow-hidden">
        <!-- Card header -->
        <div class="px-4 py-3 border-b flex items-center justify-between gap-3"
             style="background:rgba(0,88,230,.06);border-color:#c7dbf8">
          <div class="min-w-0 flex items-center gap-2">
            <span class="material-symbols-outlined" style="color:<?= $brand ?>">receipt_long</span>
            <div class="min-w-0">
              <h2 class="text-sm font-semibold text-gray-900">Summary</h2>
              <p class="text-[11px] text-gray-500 truncate">
                <span x-text="selectedCount"></span> item<span x-text="selectedCount===1?'':'s'"></span>
                <span class="mx-1">·</span>
                Due online
                <span x-text="currency"></span>
                <span x-text="formatMoney(selectedPayableTotal)"></span>
              </p>
            </div>
          </div>
          <button type="button"
                  class="shrink-0 inline-flex items-center gap-1 rounded-lg border border-[#c7dbf8] bg-white px-2.5 py-1.5 text-xs font-semibold text-[#0058E6] hover:bg-[#F5F8FE]"
                  @click="showFinalSummary = false; openDrawer()">
            <span class="material-symbols-outlined text-sm">edit</span>
            Edit
          </button>
        </div>

        <div class="divide-y divide-gray-100">
          <template x-for="mod in orderedModules" :key="'final-'+mod">
            <div class="px-4 py-3">
              <div class="flex items-center gap-2 mb-2.5">
                <span class="material-symbols-outlined text-[18px]" style="color:<?= $brand ?>"
                      x-text="moduleIcon(mod)"></span>
                <h3 class="text-xs font-bold uppercase tracking-wide text-gray-700" x-text="moduleLabel(mod)"></h3>
              </div>

              <div class="space-y-2">
                <template x-for="sel in selectedByModule[mod]" :key="sel.key">
                  <div>
                    <template x-if="mod === 'stays'">
                      <div class="card overflow-hidden p-0 mb-0">
                        <div class="flex flex-col sm:flex-row">
                          <div class="relative w-full h-36 sm:w-36 sm:h-auto sm:min-h-[120px] shrink-0 bg-gray-100 overflow-hidden">
                            <template x-if="selectionImage(sel)">
                              <img :src="selectionImage(sel)" :alt="sel.title || ''"
                                   class="absolute inset-0 w-full h-full object-cover"
                                   @error="$el.style.display='none'">
                            </template>
                            <template x-if="!selectionImage(sel)">
                              <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200">
                                <span class="material-symbols-outlined text-4xl text-gray-300">hotel</span>
                              </div>
                            </template>
                          </div>
                          <div class="flex-1 min-w-0 p-3 sm:p-4">
                            <div class="flex items-start justify-between gap-2">
                              <div class="min-w-0">
                                <h4 class="text-base font-bold text-gray-900 line-clamp-2" x-text="sel.title"></h4>
                                <p class="text-sm text-gray-600 mt-1 line-clamp-2" x-text="sel.subtitle"></p>
                              </div>
                              <button type="button"
                                      class="shrink-0 text-gray-300 hover:text-red-500 p-0.5"
                                      title="Remove"
                                      @click="removeSelected(sel.key); if (!selectedCount) showFinalSummary = false;">
                                <span class="material-symbols-outlined text-[20px]">close</span>
                              </button>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5" x-show="summaryMeta(sel).length">
                              <template x-for="(chip, cIdx) in summaryMeta(sel)" :key="sel.key+'-schip-'+cIdx">
                                <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700"
                                      x-text="chip"></span>
                              </template>
                            </div>
                            <div class="mt-2 space-y-1" x-show="staySelectedRoomNames(sel).length">
                              <template x-for="(roomName, rIdx) in staySelectedRoomNames(sel)" :key="sel.key+'-room-'+rIdx">
                                <p class="text-sm text-gray-700 inline-flex items-center gap-1.5">
                                  <span class="material-symbols-outlined text-base text-gray-400">bed</span>
                                  <span x-text="roomName"></span>
                                </p>
                              </template>
                            </div>
                            <p class="mt-3 text-lg font-bold text-gray-900">
                              <span x-text="sel.currency||currency"></span>
                              <span x-text="formatMoney(sel.price)"></span>
                            </p>
                          </div>
                        </div>
                      </div>
                    </template>

                    <template x-if="mod !== 'stays'">
                      <div class="flex gap-3 rounded-xl border border-gray-200 bg-gray-50/60 p-3 sm:p-4">
                        <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-lg bg-white border border-gray-100 overflow-hidden shrink-0 flex items-center justify-center p-1.5">
                          <template x-if="selectionImage(sel)">
                            <img :src="selectionImage(sel)" :alt="sel.title || ''"
                                 class="max-w-full max-h-full object-contain"
                                 @error="$el.style.display='none'">
                          </template>
                          <template x-if="!selectionImage(sel)">
                            <span class="material-symbols-outlined text-2xl text-gray-300" x-text="moduleIcon(mod)"></span>
                          </template>
                        </div>

                        <div class="min-w-0 flex-1">
                          <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                              <h4 class="text-sm sm:text-base font-semibold text-gray-900 line-clamp-2" x-text="sel.title"></h4>
                              <p class="text-xs sm:text-sm text-gray-500 mt-0.5 line-clamp-2" x-text="sel.subtitle"></p>
                            </div>
                            <button type="button"
                                    class="shrink-0 text-gray-300 hover:text-red-500 p-0.5"
                                    title="Remove"
                                    @click="removeSelected(sel.key); if (!selectedCount) showFinalSummary = false;">
                              <span class="material-symbols-outlined text-[18px]">close</span>
                            </button>
                          </div>

                          <div class="mt-2 flex flex-wrap gap-1.5" x-show="summaryMeta(sel).length">
                            <template x-for="(chip, cIdx) in summaryMeta(sel)" :key="sel.key+'-chip-'+cIdx">
                              <span class="inline-flex items-center rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-medium text-slate-600"
                                    x-text="chip"></span>
                            </template>
                          </div>

                          <p class="mt-2 text-sm sm:text-base font-bold text-gray-900">
                            <template x-if="isVisaSelection(sel) && (sel.is_inquiry_only || sel.item?.is_inquiry_only || !(Number(sel.price) > 0))">
                              <span class="text-blue-700">Inquiry · Price on request</span>
                            </template>
                            <template x-if="!(isVisaSelection(sel) && (sel.is_inquiry_only || sel.item?.is_inquiry_only || !(Number(sel.price) > 0)))">
                              <span>
                                <span x-text="sel.currency||currency"></span>
                                <span x-text="formatMoney(sel.price)"></span>
                                <span x-show="isVisaSelection(sel)" class="text-xs font-medium text-blue-700 ml-1">· no online pay</span>
                              </span>
                            </template>
                          </p>
                        </div>
                      </div>
                    </template>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>

        <!-- Totals + book CTA inside card -->
        <div class="px-4 py-3 border-t border-[#c7dbf8] bg-[#F5F8FE]/80">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="text-[11px] font-medium text-gray-500">Due online</p>
              <p class="text-base font-bold text-gray-900 leading-tight">
                <span x-text="currency"></span>
                <span x-text="formatMoney(selectedPayableTotal)"></span>
              </p>
              <p x-show="hasVisaInCart" class="text-[10px] text-blue-700 mt-0.5">Visa inquiry not included</p>
            </div>
            <button type="button"
                    class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white inline-flex items-center justify-center gap-1.5 shadow-md shadow-blue-600/20 hover:brightness-105 active:scale-[0.99] transition-all disabled:opacity-50"
                    style="background:<?= $brand ?>"
                    :disabled="bookingRedirecting || !selectedCount"
                    @click="goToBooking()">
              <span class="material-symbols-outlined text-[18px]"
                    x-text="bookingRedirecting ? 'progress_activity' : 'arrow_forward'"
                    :class="bookingRedirecting ? 'animate-spin' : ''"></span>
              <span x-text="bookingRedirecting ? 'Processing…' : 'Confirm & book'"></span>
            </button>
          </div>
        </div>
      </div>
    </div>
    <!-- End Final Verification Summary View -->

  </div>

  <template x-teleport="body">
    <div x-show="departureLocationRequired"
         x-cloak
         class="fixed inset-0 z-[200] flex items-center justify-center p-4"
         role="dialog"
         aria-modal="true"
         aria-labelledby="location-denied-title">
      <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[1px]"></div>
      <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl border border-gray-100 p-5"
           @click.stop>
        <div class="flex flex-col items-center text-center">
          <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center mb-3">
            <span class="material-symbols-outlined text-2xl">location_off</span>
          </div>
          <h3 id="location-denied-title" class="text-base font-bold text-slate-900">Turn on location</h3>
          <p class="text-xs text-slate-500 mt-2 leading-relaxed"
             x-text="departureLocationError || 'Turn on location in your browser settings. We use it as your departure city.'"></p>
        </div>
        <div class="mt-5 flex gap-2">
          <button type="button"
                  class="flex-1 rounded-xl bg-[#0058E6] hover:bg-[#0046b8] py-2 text-xs font-semibold text-white transition-colors"
                  @click="retryLocationForFlights()">
            Enable Location
          </button>
        </div>
      </div>
    </div>
  </template>
</div>
