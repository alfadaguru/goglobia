<?php
/**
 * TBO Holidays content import UI (admin module settings)
 */
@$SECURE or die('Access Denied!');
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">cloud_download</span>
            <h3 class="text-sm font-semibold text-gray-900">TBO Holidays Content Import</h3>
        </div>
    </div>
    <div class="p-4 space-y-4" x-data="tboHolidaysImport()">
        <p class="text-sm text-gray-600">
            Countries and cities should already be synced. Click
            <strong>Continue Hotels Import</strong> to import hotels city by city.
            Cities that already have hotels (for example Dubai) are skipped.
            If you refresh this page, the import resumes automatically from the last saved progress.
            Use <strong>Stop</strong> only when you want to pause it on purpose.
            Images are fetched during import via HotelDetails (not on search).
            For hotels imported earlier without photos, use <strong>Enrich Missing Images</strong>.
        </p>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
            <div class="rounded border p-3">
                <div class="text-xs text-gray-500">Countries</div>
                <div class="text-xl font-semibold" x-text="stats.countries">0</div>
            </div>
            <div class="rounded border p-3">
                <div class="text-xs text-gray-500">Cities</div>
                <div class="text-xl font-semibold" x-text="stats.cities">0</div>
            </div>
            <div class="rounded border p-3">
                <div class="text-xs text-gray-500">Hotels</div>
                <div class="text-xl font-semibold" x-text="stats.hotels">0</div>
            </div>
            <div class="rounded border p-3">
                <div class="text-xs text-gray-500">Cities with hotels</div>
                <div class="text-xl font-semibold" x-text="stats.cities_with_hotels">0</div>
            </div>
        </div>

        <div class="w-full bg-gray-100 rounded h-2 overflow-hidden" x-show="progress.total > 0">
            <div class="bg-blue-600 h-2 transition-all" :style="'width:' + progressPct + '%'"></div>
        </div>
        <p class="text-xs text-gray-500" x-show="progress.total > 0"
           x-text="'Hotel cities progress: ' + progress.offset + ' / ' + progress.total + ' (' + progressPct + '%)'"></p>

        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" @click="continueHotels()" :disabled="running">
                Continue Hotels Import
            </button>
            <button type="button" class="btn light" @click="startFull('update')" :disabled="running">
                Sync Countries/Cities
            </button>
            <button type="button" class="btn light" @click="showFreshModal = true" :disabled="running">
                Fresh Install
            </button>
            <button type="button" class="btn light" @click="stopImport()" :disabled="!running" x-show="running">
                Stop
            </button>
            <button type="button" class="btn light" @click="loadStats()" :disabled="running">
                Refresh Stats
            </button>
            <button type="button" class="btn light" @click="enrichImages()" :disabled="running">
                Enrich Missing Images
            </button>
        </div>

        <div class="border-t pt-4 space-y-2">
            <h4 class="text-sm font-semibold">Import Single City (test)</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                <input type="text" class="input" placeholder="Country code e.g. AE" x-model="cityForm.country_code">
                <input type="text" class="input" placeholder="City code e.g. 115936" x-model="cityForm.city_code">
                <button type="button" class="btn" @click="importCity()" :disabled="running || !cityForm.city_code">
                    Import City Hotels
                </button>
            </div>
        </div>

        <div class="bg-gray-900 text-green-400 text-xs rounded p-3 h-48 overflow-y-auto font-mono" x-ref="log">
            <template x-for="(line, i) in logs" :key="i">
                <div x-text="line"></div>
            </template>
        </div>
        <p class="text-xs text-gray-500" x-show="running" x-text="statusText"></p>

        <!-- Fresh Install confirmation (product modal, same pattern as Hotelbeds) -->
        <div x-show="showFreshModal"
             x-cloak
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50"
             style="backdrop-filter: blur(4px);"
             @keydown.escape.window="showFreshModal = false">
            <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl" @click.outside="showFreshModal = false">
                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 rounded-t-lg">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-gray-600 text-lg">settings</span>
                            <h3 class="text-sm font-semibold text-gray-900">Choose Import Mode</h3>
                        </div>
                        <button type="button" class="text-gray-400 hover:text-gray-600" @click="showFreshModal = false">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <div class="p-6">
                    <p class="text-gray-600 mb-6">Select how you want to import the content data:</p>

                    <div class="border-2 border-red-500 rounded-lg p-6 mb-4 bg-red-50/30">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                                    <span class="material-symbols-outlined text-red-600 text-2xl">delete_sweep</span>
                                </div>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 mb-2">Fresh Install (Delete All Previous)</h4>
                                <p class="text-gray-600 text-sm mb-3">
                                    This will delete all existing TBO Holidays countries, cities, and hotels, then perform a complete fresh import from the API.
                                </p>
                                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                                    <p class="text-red-800 text-sm font-medium">Warning: This action cannot be undone!</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-2 border-gray-200 rounded-lg p-6 hover:border-green-500 transition-colors cursor-pointer"
                         @click="showFreshModal = false; startFull('update')">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                    <span class="material-symbols-outlined text-green-600 text-2xl">sync</span>
                                </div>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 mb-2">Update / Sync Existing</h4>
                                <p class="text-gray-600 text-sm mb-3">
                                    Sync countries and cities without wiping hotels. Safe for ongoing imports.
                                </p>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                    <p class="text-green-800 text-sm font-medium">Safe: Preserves existing hotel data</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 rounded-b-lg flex items-center justify-end gap-3">
                    <button type="button"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 font-medium transition-colors"
                            @click="showFreshModal = false">
                        Cancel
                    </button>
                    <button type="button"
                            class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors inline-flex items-center gap-2"
                            @click="showFreshModal = false; startFull('fresh')">
                        <span class="material-symbols-outlined">delete_sweep</span>
                        Start Fresh Install
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function tboHolidaysImport() {
    return {
        stats: { countries: 0, cities: 0, hotels: 0, cities_with_hotels: 0 },
        progress: { offset: 0, total: 0 },
        logs: ['Ready.'],
        running: false,
        stopRequested: false,
        statusText: '',
        showFreshModal: false,
        importStatus: null,
        importPhase: null,
        cityForm: { country_code: 'AE', city_code: '115936' },
        get progressPct() {
            if (!this.progress.total) return 0;
            return Math.min(100, Math.round((this.progress.offset / this.progress.total) * 100));
        },
        async init() {
            await this.loadStats();
            if (this.importStatus === 'in_progress' && !this.running) {
                this.addLog('Import already in progress — resuming automatically after page load...');
                await this.resumeProcessLoop();
            }
        },
        addLog(msg) {
            this.logs.push(msg);
            if (this.logs.length > 150) this.logs = this.logs.slice(-150);
            this.$nextTick(() => {
                const el = this.$refs.log;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        async stopImport() {
            this.stopRequested = true;
            this.addLog('Stop requested — pausing import...');
            try {
                const form = new FormData();
                form.append('action', 'pause');
                await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
            } catch (e) {
                // ignore network errors while pausing
            }
        },
        async loadStats() {
            try {
                const res = await fetch('<?= root ?>modules/stays/tbo-holidays/stats');
                const data = await res.json();
                if (data.success) {
                    this.stats = Object.assign({ cities_with_hotels: 0 }, data.stats || {});
                    if (data.import) {
                        this.importStatus = data.import.status || null;
                        this.importPhase = data.import.phase || null;
                        this.progress.offset = data.import.city_offset || 0;
                        this.progress.total = data.import.total_cities || this.stats.cities || 0;
                    }
                } else {
                    this.addLog('Stats error: ' + (data.message || 'unknown'));
                }
            } catch (e) {
                this.addLog('Stats failed: ' + e.message);
            }
        },
        async resumeProcessLoop() {
            if (this.running) return;
            this.running = true;
            this.stopRequested = false;
            this.statusText = 'Resuming import...';
            try {
                await this.processLoop();
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
                this.statusText = '';
                await this.loadStats();
            }
        },
        async startFull(mode) {
            if (this.running) return;
            this.showFreshModal = false;
            this.running = true;
            this.stopRequested = false;
            this.statusText = 'Initializing...';
            this.addLog('Starting ' + mode + ' sync (countries/cities, then hotels)...');
            try {
                const form = new FormData();
                form.append('action', 'init');
                form.append('mode', mode);
                const res = await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Init failed');
                this.importStatus = 'in_progress';
                this.addLog(data.message || 'Init OK');
                await this.processLoop();
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
                this.statusText = '';
                this.loadStats();
            }
        },
        async continueHotels() {
            if (this.running) return;
            this.running = true;
            this.stopRequested = false;
            this.statusText = 'Starting hotel import...';
            try {
                await this.loadStats();
                // If a job is already in progress, just continue processing (do not recreate job)
                if (this.importStatus === 'in_progress') {
                    this.addLog('Resuming existing hotel import from offset ' + (this.progress.offset || 0) + '...');
                    await this.processLoop();
                    return;
                }

                this.addLog('Continue Hotels Import — skipping cities that already have hotels...');
                const form = new FormData();
                form.append('action', 'resume_hotels');
                form.append('city_offset', String(this.progress.offset || 0));
                const res = await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Resume failed');
                this.importStatus = 'in_progress';
                this.addLog(data.message || 'Hotel import started');
                this.progress.total = data.total_cities || this.stats.cities || 0;
                this.progress.offset = data.city_offset || 0;
                await this.processLoop();
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
                this.statusText = '';
                this.loadStats();
            }
        },
        async processLoop() {
            let guard = 0;
            while (!this.stopRequested && guard < 100000) {
                guard++;
                this.statusText = 'Processing chunk ' + guard + '...';
                const form = new FormData();
                form.append('action', 'process');
                let data;
                try {
                    const res = await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    data = await res.json();
                } catch (e) {
                    this.addLog('Chunk error: ' + e.message + ' — retrying in 2s...');
                    await new Promise(r => setTimeout(r, 2000));
                    continue;
                }
                if (!data.success) throw new Error(data.message || 'Process failed');

                (data.logs || []).forEach(l => this.addLog(l));
                if (typeof data.city_offset !== 'undefined') this.progress.offset = data.city_offset;
                if (typeof data.total_cities !== 'undefined' && data.total_cities > 0) this.progress.total = data.total_cities;
                if (typeof data.hotels_in_db !== 'undefined') this.stats.hotels = data.hotels_in_db;

                if (data.done) {
                    this.importStatus = 'completed';
                    this.addLog('Import finished. Hotels in DB: ' + (data.hotels_in_db || data.hotels_imported || 0));
                    window.dispatchEvent(new CustomEvent('tbo-hotels-refresh'));
                    break;
                }
                if (guard % 10 === 0) this.loadStats();
                await new Promise(r => setTimeout(r, 200));
            }
            if (this.stopRequested) {
                this.importStatus = 'cancelled';
                this.addLog('Paused at offset ' + this.progress.offset + '. Click Continue Hotels Import to resume.');
            }
        },
        async importCity() {
            if (this.running) return;
            this.running = true;
            this.addLog('Importing city ' + this.cityForm.city_code + '...');
            try {
                const form = new FormData();
                form.append('action', 'import_city');
                form.append('city_code', this.cityForm.city_code);
                form.append('country_code', this.cityForm.country_code);
                const res = await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const ct = res.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    const text = await res.text();
                    throw new Error('Expected JSON, got: ' + text.slice(0, 120));
                }
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'City import failed');
                this.addLog(data.message);
                this.loadStats();
                window.dispatchEvent(new CustomEvent('tbo-hotels-refresh'));
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
            }
        },
        async enrichImages() {
            if (this.running) return;
            this.running = true;
            this.stopRequested = false;
            this.statusText = 'Enriching missing hotel images...';
            this.addLog('Enriching hotels with empty images via HotelDetails...');
            try {
                let guard = 0;
                while (!this.stopRequested && guard < 5000) {
                    guard++;
                    const form = new FormData();
                    form.append('action', 'enrich_images');
                    form.append('limit', '100');
                    const res = await fetch('<?= root ?>modules/stays/tbo-holidays/content_import', { method: 'POST', body: form });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.message || 'Enrich failed');
                    this.addLog((data.message || 'Enriched') + ' — remaining: ' + (data.remaining ?? '?'));
                    if (data.done || (data.enriched || 0) < 1) {
                        this.addLog('Image enrichment finished.');
                        break;
                    }
                    this.statusText = 'Enriching images... remaining ' + (data.remaining ?? 0);
                    await new Promise(r => setTimeout(r, 300));
                }
                if (this.stopRequested) {
                    this.addLog('Image enrichment paused.');
                }
                this.loadStats();
                window.dispatchEvent(new CustomEvent('tbo-hotels-refresh'));
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
                this.statusText = '';
            }
        }
    }
}
</script>

<?php
require_once __DIR__ . '/../api.php';

try {
    $tboModule = tboHolidaysGetModule($db);
    $tboContentDb = tboHolidaysContentDb($tboModule);
    tboHolidaysCreateSchema($tboContentDb);

    // Point existing CRUD helper at TBO content DB (same style as gateways.php)
    $__crudPrevDb = crud()->db;
    crud()->db = $tboContentDb;

    echo '<div class="tbo-hotels-crud mb-5">';
    echo crud()->table('tbo_hotels')
        ->col('hotel_code,name,city_name,country_name,star_rating')
        ->title('TBO Holidays Hotels')
        ->actions([
            'view' => false,
            'delete' => false,
            'add' => false,
            'edit' => false,
            'status' => false,
            'search' => true,
        ])
        ->where([])
        ->order('id', 'DESC')
        ->label([
            'hotel_code' => 'Hotel Code',
            'name' => 'Hotel Name',
            'city_name' => 'City',
            'country_name' => 'Country',
            'star_rating' => 'Stars',
        ])
        ->row([
            'star_rating' => function ($row) {
                $stars = (float) ($row['star_rating'] ?? 0);
                return '<span class="inline-block px-2 py-1 rounded bg-slate-100 text-slate-800 border border-slate-300 text-xs font-semibold">'
                    . htmlspecialchars((string) $stars)
                    . '</span>';
            },
        ])
        ->render();
    echo '</div>';

    crud()->db = $__crudPrevDb;
} catch (Exception $e) {
    echo '<div class="alert-error mb-4"><span class="material-symbols-outlined">error</span><div>'
        . htmlspecialchars($e->getMessage())
        . ' — configure content database above first.</div></div>';
}
?>

<style>
/* Local layout fix for TBO hotels CRUD header (do not change app/lib/crud.php) */
.tbo-hotels-crud > .bg-white > .border-b > .flex {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 12px !important;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child {
    width: 100%;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child h2 {
    white-space: nowrap;
    line-height: 1.3;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child p {
    white-space: nowrap;
    margin-top: 2px;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:last-child {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: 100% !important;
}
.tbo-hotels-crud > .bg-white > .border-b form {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: auto !important;
    flex: 1 1 auto;
}
.tbo-hotels-crud > .bg-white > .border-b .select,
.tbo-hotels-crud > .bg-white > .border-b .input {
    width: auto !important;
    max-width: none !important;
}
.tbo-hotels-crud > .bg-white > .border-b #columnToggleBtn {
    width: 170px !important;
    flex: 0 0 170px;
}
.tbo-hotels-crud > .bg-white > .border-b select[name="search_col"] {
    width: 150px !important;
    flex: 0 0 150px;
}
.tbo-hotels-crud > .bg-white > .border-b input[name="q"] {
    width: 220px !important;
    flex: 1 1 180px;
    min-width: 160px;
}
</style>
