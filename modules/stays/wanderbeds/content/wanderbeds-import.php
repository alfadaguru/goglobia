<?php
/**
 * Wanderbeds content import UI (admin module settings)
 */
@$SECURE or die('Access Denied!');
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">cloud_download</span>
            <h3 class="text-sm font-semibold text-gray-900">Wanderbeds Content Import</h3>
        </div>
    </div>
    <div class="p-4 space-y-4" x-data="wanderbedsImport()">
        <p class="text-sm text-gray-600">
            Sync countries and cities, then import the Wanderbeds hotel list and enrich hotel details.
            If you refresh this page, an in-progress import resumes automatically.
            Use <strong>Stop</strong> to pause.
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

        <p class="text-xs text-gray-500" x-show="importPhase" x-text="'Current phase: ' + (importPhase || '-')"></p>

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
                Enrich Missing Details
            </button>
        </div>

        <div class="bg-gray-900 text-green-400 text-xs rounded p-3 h-48 overflow-y-auto font-mono" x-ref="log">
            <template x-for="(line, i) in logs" :key="i">
                <div x-text="line"></div>
            </template>
        </div>
        <p class="text-xs text-gray-500" x-show="running" x-text="statusText"></p>

        <div x-show="showFreshModal"
             x-cloak
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50"
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
                                    This will delete all existing Wanderbeds countries, cities, and hotels, then perform a complete fresh import.
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
                                    Sync countries, cities, and hotels without wiping the content database first.
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
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 font-medium"
                            @click="showFreshModal = false">
                        Cancel
                    </button>
                    <button type="button"
                            class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium inline-flex items-center gap-2"
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
function wanderbedsImport() {
    return {
        stats: { countries: 0, cities: 0, hotels: 0, cities_with_hotels: 0 },
        logs: ['Ready.'],
        running: false,
        stopRequested: false,
        statusText: '',
        showFreshModal: false,
        importStatus: null,
        importPhase: null,
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
                await fetch('<?= root ?>modules/stays/wanderbeds/content_import', { method: 'POST', body: form });
            } catch (e) {}
        },
        async loadStats() {
            try {
                const res = await fetch('<?= root ?>modules/stays/wanderbeds/stats');
                const data = await res.json();
                if (data.success) {
                    this.stats = Object.assign({ cities_with_hotels: 0 }, data.stats || {});
                    if (data.import) {
                        this.importStatus = data.import.status || null;
                        this.importPhase = data.import.phase || null;
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
            this.addLog('Starting ' + mode + ' sync...');
            try {
                const form = new FormData();
                form.append('action', 'init');
                form.append('mode', mode);
                const res = await fetch('<?= root ?>modules/stays/wanderbeds/content_import', { method: 'POST', body: form });
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
                if (this.importStatus === 'in_progress') {
                    this.addLog('Resuming existing import...');
                    await this.processLoop();
                    return;
                }
                this.addLog('Continue Hotels Import...');
                const form = new FormData();
                form.append('action', 'resume_hotels');
                const res = await fetch('<?= root ?>modules/stays/wanderbeds/content_import', { method: 'POST', body: form });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Resume failed');
                this.importStatus = 'in_progress';
                this.addLog(data.message || 'Hotel import started');
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
                    const res = await fetch('<?= root ?>modules/stays/wanderbeds/content_import', { method: 'POST', body: form });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    data = await res.json();
                } catch (e) {
                    this.addLog('Chunk error: ' + e.message + ' — retrying in 2s...');
                    await new Promise(r => setTimeout(r, 2000));
                    continue;
                }
                if (!data.success) throw new Error(data.message || 'Process failed');

                (data.logs || []).forEach(l => this.addLog(l));
                if (data.phase) this.importPhase = data.phase;
                if (data.stats) this.stats = Object.assign(this.stats, data.stats);

                if (data.done) {
                    this.importStatus = 'completed';
                    this.addLog('Import finished.');
                    window.dispatchEvent(new CustomEvent('wb-hotels-refresh'));
                    break;
                }
                if (guard % 10 === 0) this.loadStats();
                await new Promise(r => setTimeout(r, 200));
            }
            if (this.stopRequested) {
                this.importStatus = 'cancelled';
                this.addLog('Paused. Click Continue Hotels Import to resume.');
            }
        },
        async enrichImages() {
            if (this.running) return;
            this.running = true;
            this.addLog('Enriching hotels missing details...');
            try {
                const form = new FormData();
                form.append('action', 'enrich_images');
                const res = await fetch('<?= root ?>modules/stays/wanderbeds/content_import', { method: 'POST', body: form });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Enrich failed');
                this.addLog(data.message || 'Enriched');
                this.loadStats();
                window.dispatchEvent(new CustomEvent('wb-hotels-refresh'));
            } catch (e) {
                this.addLog('ERROR: ' + e.message);
            } finally {
                this.running = false;
            }
        }
    }
}
</script>
