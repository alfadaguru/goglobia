<?php
/**
 * Hotelston Content Import Interface
 * Phase 1: getHotelList â†’ populate queue
 * Phase 2: getHotelDetails per hotel â†’ populate hotelston_hotels
 */
?>

<!-- API Requirements Notice -->
<div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-5 rounded">
    <div class="flex">
        <div class="flex-shrink-0">
            <span class="material-symbols-outlined text-blue-400">info</span>
        </div>
        <div class="ml-3">
            <p class="text-sm text-blue-700">
                <strong>API Requirements:</strong> This content importer requires <strong>Static Data Service API</strong> access from Hotelston.
                If you get an error about "Web services are not enabled", contact <a href="mailto:api@hotelston.com" class="underline font-medium">api@hotelston.com</a>
                with subject "XML [your agent name]" to request access. Note: <em>Booking Service API alone is not sufficient for content import.</em>
            </p>
            <p class="text-sm text-blue-600 mt-2">
                <strong>Import speed:</strong> Phase 2 fetches <strong>100 hotels per batch</strong> using <strong>4 parallel API calls</strong> (Hotelston hard limit).
                <strong>Code 301</strong> = hotel not in static data (skipped). <strong>Code 417</strong> = rate limit (auto-retry).
                Use <strong>Production</strong> environment for the full catalog when your account has production Static Data access.
            </p>
        </div>
    </div>
</div>

<!-- ================================================================
     HOTELSTON CONTENT IMPORT CARD
     Phase 1: fetch hotel list via getHotelList (one-time SOAP call)
     Phase 2: getHotelDetails per hotel, batch by batch
================================================================ -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <!-- Card Header -->
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">cloud_sync</span>
                <h3 class="text-sm font-semibold text-gray-900">ðŸ¨ Hotelston Hotels Import</h3>
            </div>
            <span id="hsStatus" class="text-xs px-3 py-1 rounded-full bg-gray-100 text-gray-600 font-medium">Loading...</span>
        </div>
    </div>

    <div class="p-4 space-y-4">

        <!-- Phase indicator -->
        <div id="phaseIndicator" class="hidden flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg text-sm">
            <span id="phaseIcon" class="material-symbols-outlined text-blue-500 text-base">hourglass_top</span>
            <span id="phaseLabel" class="font-medium text-gray-800">Idle</span>
            <span id="phaseDetail" class="text-gray-500 text-xs"></span>
        </div>

        <!-- Stats grid -->
        <div id="statsGrid" class="hidden grid grid-cols-4 gap-2">
            <div class="bg-blue-50 rounded p-2 border border-blue-200">
                <p class="text-xs text-blue-600">Total</p>
                <p id="statTotal" class="text-lg font-bold text-blue-900">0</p>
            </div>
            <div class="bg-green-50 rounded p-2 border border-green-200">
                <p class="text-xs text-green-600">Done</p>
                <p id="statProcessed" class="text-lg font-bold text-green-900">0</p>
            </div>
            <div class="bg-orange-50 rounded p-2 border border-orange-200">
                <p class="text-xs text-orange-600">Pending</p>
                <p id="statPending" class="text-lg font-bold text-orange-900">0</p>
            </div>
            <div class="bg-red-50 rounded p-2 border border-red-200">
                <p class="text-xs text-red-600">Failed</p>
                <p id="statFailed" class="text-lg font-bold text-red-900">0</p>
            </div>
        </div>

        <!-- Progress bar -->
        <div id="progressSection" class="hidden space-y-1">
            <div class="flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700">Progress</span>
                <span id="progressPct" class="text-sm font-bold text-gray-900">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-cyan-500 h-full transition-all duration-300" style="width: 0%"></div>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
                <span id="progressText">Ready to start...</span>
                <span id="etaText"></span>
            </div>
        </div>

        <!-- Terminal -->
        <div id="terminalSection" class="hidden">
            <div class="bg-gray-900 rounded overflow-hidden border border-gray-700">
                <div class="px-3 py-2 bg-gray-800 border-b border-gray-700 flex justify-between items-center">
                    <span class="text-xs font-mono text-gray-400">$ Import Console</span>
                    <button type="button" onclick="hsClearTerminal()" class="text-xs text-gray-400 hover:text-white">Clear</button>
                </div>
                <div id="hsTerminal" class="p-3 font-mono text-xs text-green-400 h-40 overflow-y-auto" style="background:#0d1117;">
                    <div class="text-gray-600">$ Waiting for commands...</div>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-2 flex-wrap pt-2">
            <button type="button" id="btnStart"  onclick="hsStart(event)"  class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">play_arrow</span> Start Import
            </button>
            <button type="button" id="btnPause"  onclick="hsPause(event)"  class="flex-1 bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">pause</span> Pause
            </button>
            <button type="button" id="btnResume" onclick="hsResume(event)" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">play_arrow</span> Resume
            </button>
            <button type="button" id="btnReset"  onclick="hsReset(event)"  class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition">
                <span class="material-symbols-outlined text-sm align-middle">delete_sweep</span> Reset All
            </button>
        </div>
    </div>
</div>

<script>
// ================================================================
// HOTELSTON IMPORT â€” Production JS
// ================================================================
const HS = {
    importing:        false,
    batchActive:      false,
    batchTimer:       null,
    pollTimer:        null,
    state:            null,
    batchNum:         0,
    pollInterval:     3000,
    retryDelay:       2000,
    batchDelay:       500,
    batchSize:        100,
    concurrency:      4,
    logCount:         0,
    handlerUrl:       '<?= root ?>modules/stays/hotelston/content/import-handler.php',
    stateUrl:         '<?= root ?>modules/stays/hotelston/content/import-state.php',

    log(type, msg) {
        if (this.logCount >= 100) {
            const term = document.getElementById('hsTerminal');
            if (term && term.firstChild) term.removeChild(term.firstChild);
        } else {
            this.logCount++;
        }
        const t    = new Date().toLocaleTimeString();
        const line = `[${t}] ${msg}`;
        const term = document.getElementById('hsTerminal');
        if (term) {
            const d = document.createElement('div');
            d.className = type === 'error' ? 'text-red-400' : type === 'warn' ? 'text-yellow-400' : type === 'success' ? 'text-cyan-400' : 'text-green-400';
            d.textContent = line;
            term.appendChild(d);
            term.scrollTop = term.scrollHeight;
        }
        document.getElementById('terminalSection').classList.remove('hidden');
    },

    async call(action, extra = {}) {
        const body = new URLSearchParams({ action, ...extra });
        const res  = await fetch(this.handlerUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
            signal:  AbortSignal.timeout(300000),
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.json();
    },

    async getState() {
        try {
            const res = await fetch(this.stateUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'action=get_state',
                signal:  AbortSignal.timeout(8000),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (data.success) {
                this.state = data;
                this.updateUI();
            }
            return data;
        } catch (e) {
            console.error('State poll error:', e.message);
            return null;
        }
    },

    updateUI() {
        if (!this.state) return;
        const { status, phase, total, processed, failed, pending, percentage, eta } = this.state;

        // Status badge
        const badge = document.getElementById('hsStatus');
        if (badge) {
            badge.textContent = (status.charAt(0).toUpperCase() + status.slice(1));
            badge.className = `text-xs px-3 py-1 rounded-full font-medium ${
                status === 'running'    ? 'bg-blue-100 text-blue-700'   :
                status === 'paused'     ? 'bg-yellow-100 text-yellow-700' :
                status === 'completed'  ? 'bg-green-100 text-green-700'  :
                status === 'error'      ? 'bg-red-100 text-red-700'      :
                'bg-gray-100 text-gray-700'
            }`;
        }

        // Phase indicator
        const pi = document.getElementById('phaseIndicator');
        const plab = document.getElementById('phaseLabel');
        const pdet = document.getElementById('phaseDetail');
        const pico = document.getElementById('phaseIcon');
        if (pi && phase && phase !== 'idle') {
            pi.classList.remove('hidden');
            if (phase === 'fetching_list') {
                plab.textContent = 'Phase 1: Fetching Hotel List';
                pdet.textContent = 'Calling getHotelList API...';
                pico.textContent = 'downloading';
            } else if (phase === 'importing_details') {
                plab.textContent = 'Phase 2: Importing Hotel Details';
                pdet.textContent = `${processed.toLocaleString()} / ${total.toLocaleString()} hotels`;
                pico.textContent = 'sync';
            } else if (phase === 'completed') {
                plab.textContent = 'Import Complete';
                pdet.textContent = `${processed.toLocaleString()} hotels saved`;
                pico.textContent = 'check_circle';
            }
        }

        // Stats
        if (total > 0) {
            document.getElementById('statsGrid').classList.remove('hidden');
            document.getElementById('statTotal').textContent     = total.toLocaleString();
            document.getElementById('statProcessed').textContent = processed.toLocaleString();
            document.getElementById('statPending').textContent   = (pending || 0).toLocaleString();
            document.getElementById('statFailed').textContent    = (failed  || 0).toLocaleString();
            document.getElementById('progressSection').classList.remove('hidden');
            document.getElementById('progressBar').style.width   = percentage + '%';
            document.getElementById('progressPct').textContent   = percentage.toFixed(2) + '%';
            document.getElementById('progressText').textContent  = `${processed.toLocaleString()} / ${total.toLocaleString()}`;
            document.getElementById('etaText').textContent       = eta ? 'ETA: ' + eta.formatted : '';
        }

        // Buttons
        document.getElementById('btnStart').classList.add('hidden');
        document.getElementById('btnPause').classList.add('hidden');
        document.getElementById('btnResume').classList.add('hidden');
        if (status === 'idle' || status === 'error') {
            document.getElementById('btnStart').classList.remove('hidden');
        } else if (status === 'running') {
            document.getElementById('btnPause').classList.remove('hidden');
        } else if (status === 'paused') {
            document.getElementById('btnResume').classList.remove('hidden');
        }
    },

    // â”€â”€ Phase 1: fetch hotel list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    async fetchList() {
        this.log('info', 'ðŸ“‹ Phase 1: Fetching hotel list from Hotelston API...');
        this.log('warn', 'â³ This may take 30-90 seconds for large datasets.');
        try {
            const data = await this.call('fetch_list');
            if (!data.success) {
                this.log('error', 'âŒ fetch_list: ' + data.error);
                this.importing = false;
                this.batchActive = false;
                return false;
            }
            this.log('success', `âœ… List fetched: ${data.total.toLocaleString()} hotels queued (${data.inserted} new, ${data.skipped} existing)`);
            return true;
        } catch (e) {
            this.log('error', 'âŒ fetch_list network error: ' + e.message);
            this.importing = false;
            this.batchActive = false;
            return false;
        }
    },

    scheduleNextBatch(delayMs = null) {
        if (!this.importing) {
            return;
        }
        clearTimeout(this.batchTimer);
        const wait = delayMs ?? this.batchDelay;
        this.batchTimer = setTimeout(() => this.runBatch(), wait);
    },

    formatBatchSummary(data) {
        const parts = [`+${data.imported || 0} saved`];
        if ((data.failed || 0) > 0) {
            parts.push(`${data.failed} failed`);
        }
        if ((data.retried || 0) > 0) {
            parts.push(`${data.retried} retry`);
        }
        if ((data.not_found || 0) > 0) {
            parts.push(`${data.not_found} not found`);
        }
        if (data.error_stats) {
            if (data.error_stats['417']) {
                parts.push(`${data.error_stats['417']} rate-limited`);
            }
            if (data.error_stats['301']) {
                parts.push(`${data.error_stats['301']} missing`);
            }
        }
        return parts.join(', ');
    },

    // Phase 2: process one batch
    async runBatch() {
        if (!this.importing || this.batchActive) {
            return;
        }

        this.batchActive = true;
        const batchId = ++this.batchNum;

        try {
            const data = await this.call('batch', {
                batch_size: String(this.batchSize),
                concurrency: String(this.concurrency),
            });

            if (data.skipped_lock) {
                this.scheduleNextBatch(data.retry_after_ms || 800);
                return;
            }

            if (data.paused) {
                this.log('warn', 'Import paused by server.');
                this.importing = false;
                return;
            }

            if (!data.success) {
                this.log('error', `Batch #${batchId}: ${data.error}`);
                this.scheduleNextBatch(this.retryDelay);
                return;
            }

            if (data.imported > 0 || data.failed > 0 || data.retried > 0) {
                this.log(
                    'success',
                    `Batch #${batchId}: ${this.formatBatchSummary(data)} | ${Number(data.percentage || 0).toFixed(2)}%`
                );
            }

            if (data.errors && data.errors.length) {
                data.errors.forEach(e => this.log('warn', e));
            }

            await this.getState();

            if (data.completed) {
                this.log('success', `Import complete! ${data.total_processed?.toLocaleString()} hotels saved.`);
                this.importing = false;
                clearInterval(this.pollTimer);
                this.pollTimer = null;
                return;
            }

            this.scheduleNextBatch(data.retry_after_ms || (data.rate_limited ? 1500 : this.batchDelay));
        } catch (e) {
            if (e.name !== 'AbortError') {
                this.log('warn', `Batch #${batchId} error: ${e.message} — retrying...`);
            }
            this.scheduleNextBatch(this.retryDelay);
        } finally {
            this.batchActive = false;
        }
    },

    async startImport() {
        this.log('success', 'ðŸš€ Starting Hotelston content import...');
        this.importing   = true;
        this.batchActive = false;
        this.batchNum    = 0;

        // Init / ensure schema
        try {
            await this.call('init');
        } catch (e) {}

        // Check current state
        const state = await this.getState();

        // Start polling
        if (!this.pollTimer) {
            this.pollTimer = setInterval(() => this.getState(), this.pollInterval);
        }

        if (state && state.phase === 'importing_details' && state.pending > 0) {
            // List already fetched, resume Phase 2
            this.log('info', `â–¶ï¸ Resuming Phase 2 (${state.pending.toLocaleString()} hotels pending)...`);
            await this.call('resume');
            this.runBatch();
        } else {
            // Phase 1: fetch list
            const ok = await this.fetchList();
            if (!ok) return;
            await this.getState();
            // Phase 2: batch import
            this.runBatch();
        }
    },
};

// â”€â”€ Button handlers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function hsStart(e) {
    if (e) e.preventDefault();
    await HS.startImport();
}

async function hsPause(e) {
    if (e) e.preventDefault();
    HS.log('warn', 'â¸ï¸ Pausing...');
    HS.importing   = false;
    HS.batchActive = false;
    if (HS.batchTimer) { clearTimeout(HS.batchTimer); HS.batchTimer = null; }
    try { await HS.call('pause'); } catch(_) {}
    await HS.getState();
}

async function hsResume(e) {
    if (e) e.preventDefault();
    HS.log('info', 'â–¶ï¸ Resuming import...');
    try {
        const data = await HS.call('resume');
        if (data.success) {
            HS.importing   = true;
            HS.batchActive = false;
            if (!HS.pollTimer) {
                HS.pollTimer = setInterval(() => HS.getState(), HS.pollInterval);
            }
            HS.runBatch();
        }
    } catch (e) {
        HS.log('error', 'Resume failed: ' + e.message);
    }
}

async function hsReset(e) {
    if (e) e.preventDefault();
    if (!confirm('Reset will DELETE all imported hotel data and the queue. Are you sure?')) return;
    HS.importing   = false;
    HS.batchActive = false;
    if (HS.batchTimer)  { clearTimeout(HS.batchTimer);   HS.batchTimer  = null; }
    if (HS.pollTimer)   { clearInterval(HS.pollTimer);   HS.pollTimer   = null; }
    try {
        const data = await HS.call('reset');
        HS.log('warn', 'ðŸ—‘ï¸ ' + (data.message || 'Reset complete'));
        HS.state = null;
        document.getElementById('hsStatus').textContent   = 'Idle';
        document.getElementById('hsStatus').className     = 'text-xs px-3 py-1 rounded-full font-medium bg-gray-100 text-gray-700';
        document.getElementById('statsGrid').classList.add('hidden');
        document.getElementById('progressSection').classList.add('hidden');
        document.getElementById('phaseIndicator').classList.add('hidden');
        document.getElementById('btnStart').classList.remove('hidden');
        document.getElementById('btnPause').classList.add('hidden');
        document.getElementById('btnResume').classList.add('hidden');
    } catch (ex) {
        HS.log('error', 'Reset failed: ' + ex.message);
    }
}

function hsClearTerminal() {
    const t = document.getElementById('hsTerminal');
    if (t) t.innerHTML = '<div class="text-gray-600">$ Terminal cleared.</div>';
    HS.logCount = 0;
}

// â”€â”€ Init on page load â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
(async function hsInit() {
    try {
        await HS.call('init');
    } catch (_) {}
    const state = await HS.getState();
    if (state && state.status === 'running' && state.phase === 'importing_details') {
        // Import was running — auto-resume batch loop (single chain only)
        HS.log('warn', 'Import was running — auto-resuming...');
        HS.importing = true;
        if (!HS.pollTimer) {
            HS.pollTimer = setInterval(() => HS.getState(), HS.pollInterval);
        }
        if (!HS.batchActive && !HS.batchTimer) {
            HS.scheduleNextBatch(500);
        }
    } else if (state && state.status === 'idle') {
        document.getElementById('btnStart').classList.remove('hidden');
        document.getElementById('hsStatus').textContent = 'Idle';
    }
})();
</script>

