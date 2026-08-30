<?php if (!isset($root)) $root = '/'; ?>

<?php
// Check if the JSONL file exists server-side for the PHP badge
$_rh_jsonlPath = __DIR__ . '/db.jsonl';
$_rh_fileExists = file_exists($_rh_jsonlPath);
$_rh_fileSizeMB = $_rh_fileExists ? round(filesize($_rh_jsonlPath) / 1024 / 1024, 1) : 0;
?>

<!-- DOWNLOAD INSTRUCTIONS -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
    <div class="flex items-start gap-3">
        <span class="material-symbols-outlined text-blue-500 text-xl mt-0.5">info</span>
        <div class="flex-1 space-y-2">
            <p class="text-sm font-semibold text-blue-900">How to get the hotel database file</p>
            <ol class="text-sm text-blue-800 space-y-1 list-decimal list-inside">
                <li>Log in to your <a href="https://www.ratehawk.com/" target="_blank" class="underline font-medium hover:text-blue-600">RateHawk / WorldOTA</a> partner account</li>
                <li>Go to <strong>Partner Tools → Content → Hotel Dump</strong></li>
                <li>Download the <strong>JSONL</strong> dump file (<code class="bg-blue-100 px-1 rounded text-xs">hotels_dump.jsonl</code> or similar)</li>
                <li>Rename it to <code class="bg-blue-100 px-1 rounded text-xs">db.jsonl</code></li>
                <li>Place it at: <code class="bg-blue-100 px-1 rounded text-xs font-mono">modules/stays/ratehawk/content/db.jsonl</code></li>
            </ol>
            <p class="text-xs text-blue-600 mt-1">If you received a <code class="bg-blue-100 px-1 rounded">.zst</code> file, decompress it first: <code class="bg-blue-100 px-1 rounded font-mono">zstd -d hotels_dump.jsonl.zst -o db.jsonl</code></p>
        </div>
    </div>
</div>

<!-- FILE STATUS BADGE -->
<?php if ($_rh_fileExists): ?>
<div class="flex items-center gap-2 px-4 py-2 bg-green-50 border border-green-200 rounded-lg mb-4 text-sm text-green-800">
    <span class="material-symbols-outlined text-green-500 text-base">check_circle</span>
    <span><strong>db.jsonl found</strong> — <?= number_format($_rh_fileSizeMB, 1) ?> MB ready to import.</span>
</div>
<?php else: ?>
<div class="flex items-center gap-2 px-4 py-2 bg-red-50 border border-red-200 rounded-lg mb-4 text-sm text-red-800">
    <span class="material-symbols-outlined text-red-500 text-base">error</span>
    <span><strong>db.jsonl not found.</strong> Follow the instructions above to download and place the file, then refresh this page.</span>
</div>
<?php endif; ?>

<!-- RATEHAWK JSONL IMPORT - CLEAN PRODUCTION VERSION -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">cloud_sync</span>
                <h3 class="text-sm font-semibold text-gray-900">🏨 JSONL Hotel Import</h3>
            </div>
            <span id="rhStatus" class="text-xs px-3 py-1 rounded-full bg-gray-100 text-gray-600 font-medium">Loading...</span>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <!-- File Status -->
        <div id="fileStatus"></div>

        <!-- Stats Grid -->
        <div id="statsGrid" class="hidden grid grid-cols-4 gap-2">
            <div class="bg-blue-50 rounded p-2 border border-blue-200">
                <p class="text-xs text-blue-600">Total</p>
                <p id="statTotal" class="text-lg font-bold text-blue-900">0</p>
            </div>
            <div class="bg-green-50 rounded p-2 border border-green-200">
                <p class="text-xs text-green-600">Processed</p>
                <p id="statProcessed" class="text-lg font-bold text-green-900">0</p>
            </div>
            <div class="bg-orange-50 rounded p-2 border border-orange-200">
                <p class="text-xs text-orange-600">Remaining</p>
                <p id="statRemaining" class="text-lg font-bold text-orange-900">0</p>
            </div>
            <div class="bg-purple-50 rounded p-2 border border-purple-200">
                <p class="text-xs text-purple-600">ETA</p>
                <p id="statETA" class="text-lg font-bold text-purple-900">--</p>
            </div>
        </div>

        <!-- Progress Bar -->
        <div id="progressSection" class="hidden space-y-1">
            <div class="flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700">Progress</span>
                <span id="progressPercent" class="text-sm font-bold text-gray-900">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-cyan-500 h-full transition-all duration-300" style="width: 0%"></div>
            </div>
            <p id="progressText" class="text-xs text-gray-600">Ready to start...</p>
        </div>

        <!-- Status Messages -->
        <div id="statusMessages" class="hidden space-y-1 p-2 bg-blue-50 border border-blue-200 rounded text-sm text-blue-800"></div>

        <!-- Terminal Logs -->
        <div id="terminalSection" class="hidden">
            <div class="bg-gray-900 rounded overflow-hidden border border-gray-700">
                <div class="px-3 py-2 bg-gray-800 border-b border-gray-700 flex justify-between items-center">
                    <span class="text-xs font-mono text-gray-400">$ Terminal Output</span>
                    <button type="button" onclick="clearTerminal()" class="text-xs text-gray-400 hover:text-white">Clear</button>
                </div>
                <div id="terminalBody" class="p-3 font-mono text-xs text-green-400 h-32 overflow-y-auto max-h-48" style="background: #0d1117;">
                    <div class="text-gray-600">$ Waiting...</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-2 flex-wrap pt-2">
            <button type="button" id="btnStart" onclick="rhStartImport(event)" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">play_arrow</span> Start Import
            </button>
            <button type="button" id="btnPause" onclick="rhPauseImport(event)" class="flex-1 bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">pause</span> Pause
            </button>
            <button type="button" id="btnResume" onclick="rhResumeImport(event)" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-medium transition hidden">
                <span class="material-symbols-outlined text-sm align-middle">play_arrow</span> Resume
            </button>
            <button type="button" id="btnReset" onclick="rhResetImport(event)" class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition">
                <span class="material-symbols-outlined text-sm align-middle">refresh</span> Reset
            </button>
        </div>
    </div>
</div>

<script>
// ================================================================
// RATEHAWK IMPORT - PRODUCTION VERSION
// ================================================================

const RH = {
    importing: false,
    processingActive: false,
    batchTimeout: null,
    state: null,
    currentBatch: 1,
    lastProcessed: 0,
    lastProgressAt: 0,
    lastUpdate: 0,
    pollInterval: 1000,
    retryDelayMs: 1500,
    pollTimer: null,
    logCount: 0,
    
    log(type, msg) {
        // Limit logs to prevent terminal from getting too crowded
        if (this.logCount >= 80) {
            const term = document.getElementById('terminalBody');
            if (term && term.children.length > 0) {
                term.removeChild(term.children[0]);
            }
        } else {
            this.logCount++;
        }
        
        const time = new Date().toLocaleTimeString();
        const line = `[${time}] ${msg}`;
        
        if (type === 'error') console.error(line);
        else if (type === 'warn') console.warn(line);
        else console.log(line);
        
        const term = document.getElementById('terminalBody');
        if (term) {
            const div = document.createElement('div');
            div.className = type === 'error' ? 'text-red-400' : type === 'success' ? 'text-green-400' : type === 'warn' ? 'text-yellow-400' : 'text-green-400';
            div.textContent = line;
            term.appendChild(div);
            term.scrollTop = term.scrollHeight;
        }
    },
    
    async getState() {
        try {
            const res = await fetch('<?=root?>modules/stays/ratehawk/content/import-state.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_state',
                signal: AbortSignal.timeout(8000)
            });
            
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            
            const data = await res.json();
            if (data.success) {
                if (data.processed > this.lastProcessed) {
                    this.lastProcessed = data.processed;
                    this.lastProgressAt = Date.now();
                }

                // Only log if values changed
                if (this.state && this.state.processed !== data.processed) {
                    this.log('info', `📊 Fetched: ${data.processed.toLocaleString()} processed, ETA: ${data.eta?.formatted || '--'}`);
                }
                this.state = data;
                this.updateUI();

                // Auto-recover worker loop if backend says running but browser loop is idle.
                if (data.status === 'running' && this.importing && !this.processingActive) {
                    const idleFor = this.lastProgressAt ? (Date.now() - this.lastProgressAt) : 0;
                    if (idleFor > 10000) {
                        this.log('warn', '🛠️ Worker idle detected, auto-retrying batch...');
                        this.processBatch(this.currentBatch);
                    }
                }

                return data;
            } else {
                this.log('error', `Server: ${data.error}`);
                const badge = document.getElementById('rhStatus');
                if (badge && badge.textContent === 'Loading...') {
                    badge.textContent = 'Idle';
                    badge.className = 'text-xs px-3 py-1 rounded-full font-medium bg-gray-100 text-gray-700';
                }
            }
        } catch (e) {
            console.error('State error:', e.message);
            const badge = document.getElementById('rhStatus');
            if (badge && badge.textContent === 'Loading...') {
                badge.textContent = 'Idle';
                badge.className = 'text-xs px-3 py-1 rounded-full font-medium bg-gray-100 text-gray-700';
            }
        }
        return null;
    },
    
    updateUI() {
        if (!this.state) return;
        
        const {status, total, processed, remaining, percentage, eta} = this.state;
        
        // Status badge
        const badge = document.getElementById('rhStatus');
        badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        badge.className = `text-xs px-3 py-1 rounded-full font-medium ${
            status === 'running' ? 'bg-blue-100 text-blue-700' :
            status === 'paused' ? 'bg-yellow-100 text-yellow-700' :
            status === 'completed' ? 'bg-green-100 text-green-700' :
            status === 'error' ? 'bg-red-100 text-red-700' :
            'bg-gray-100 text-gray-700'
        }`;
        
        // Show stats
        if (total > 0) {
            document.getElementById('statsGrid').classList.remove('hidden');
            document.getElementById('statTotal').textContent = total.toLocaleString();
            document.getElementById('statProcessed').textContent = processed.toLocaleString();
            document.getElementById('statRemaining').textContent = remaining.toLocaleString();
            document.getElementById('statETA').textContent = eta?.formatted || '--';
            
            // Progress bar
            document.getElementById('progressSection').classList.remove('hidden');
            document.getElementById('progressBar').style.width = percentage + '%';
            document.getElementById('progressPercent').textContent = percentage.toFixed(1) + '%';
            document.getElementById('progressText').textContent = `${processed.toLocaleString()} / ${total.toLocaleString()} | ${percentage.toFixed(1)}%`;
        }
        
        // Button visibility
        const hideAll = () => {
            document.getElementById('btnStart').classList.add('hidden');
            document.getElementById('btnPause').classList.add('hidden');
            document.getElementById('btnResume').classList.add('hidden');
        };
        
        hideAll();
        
        // AUTO-RESUME: If status is running but we're not processing, start processing
        if (status === 'idle' || status === 'error') {
            document.getElementById('btnStart').classList.remove('hidden');
        } else if (status === 'running') {
            document.getElementById('btnPause').classList.remove('hidden');
        } else if (status === 'paused') {
            document.getElementById('btnResume').classList.remove('hidden');
        }
    },
    
    async resumeProcessing() {
        // Resume batch processing for an already-running import
        this.importing = true;
        this.processingActive = true;
        this.log('info', '▶️ Resuming batch processing...');
        
        // Clear any old timer
        clearInterval(this.pollTimer);
        
        // Start polling
        this.pollTimer = setInterval(() => this.getState(), this.pollInterval);
        
        // Resume from where we left off
        await this.processBatch(1);
    },
    
    async startImport() {
        this.importing = true;
        this.processingActive = false;
        this.currentBatch = (this.state?.batch || 0) + 1;
        if (!this.lastProgressAt) this.lastProgressAt = Date.now();
        this.log('success', '🚀 Import started - beginning batch processing...');
        
        // Start polling if not already running
        if (!this.pollTimer) {
            this.log('info', '📡 Starting real-time state polling...');
            this.pollTimer = setInterval(() => this.getState(), this.pollInterval);
        }
        
        // Kick off batch processing
        await this.processBatch(this.currentBatch);
    },
    
    async processBatch(batchNum) {
        if (!this.importing) {
            this.processingActive = false;
            return;
        }
        
        this.processingActive = true;
        this.currentBatch = batchNum;
        this.log('info', `⏳ Batch #${batchNum} processing...`);
        
        try {
            const res = await fetch('<?=root?>modules/stays/ratehawk/content/import-handler.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=resume',
                signal: AbortSignal.timeout(120000)
            });
            
            if (!res.ok) {
                this.log('error', `Batch #${batchNum} HTTP ${res.status}`);
                this.processingActive = false;
                if (this.importing) {
                    this.batchTimeout = setTimeout(() => this.processBatch(batchNum), this.retryDelayMs);
                }
                return;
            }
            
            const data = await res.json();
            
            if (!data.success) {
                this.log('error', `Batch #${batchNum}: ${data.error}`);
                this.processingActive = false;
                if (this.importing) {
                    this.batchTimeout = setTimeout(() => this.processBatch(batchNum), this.retryDelayMs);
                }
                return;
            }
            
            if (data.counted) {
                this.log('success', `✅ File ready: ${data.total.toLocaleString()} hotels`);
                // Counting was a separate pass — immediately kick off real import
                if (this.importing) {
                    this.batchTimeout = setTimeout(() => this.processBatch(batchNum + 1), 100);
                }
            } else if (data.completed) {
                this.log('success', `🎉 Complete! All ${data.processed.toLocaleString()} imported`);
                this.importing = false;
                this.processingActive = false;
                clearInterval(this.pollTimer);
            } else {
                // Log batch success
                this.log('success', `✅ Batch #${batchNum}: +${data.imported} hotels (${data.percentage.toFixed(1)}%)`);
                
                // Schedule next batch ONLY if still importing
                if (this.importing) {
                    this.batchTimeout = setTimeout(() => this.processBatch(batchNum + 1), 120);
                } else {
                    this.processingActive = false;
                }
            }
            
            // Always fetch fresh state after batch
            await this.getState();
        } catch (e) {
            console.error('Batch error:', e.message);
            if (this.importing && e.name !== 'AbortError') {
                this.log('warn', `Batch #${batchNum} retry in 2s...`);
                this.batchTimeout = setTimeout(() => this.processBatch(batchNum), 2000);
            } else {
                this.processingActive = false;
            }
        }
    }
};

async function rhStartImport(event) {
    if (event) event.preventDefault();
    await RH.startImport();
}

async function rhPauseImport(event) {
    if (event) event.preventDefault();
    RH.log('warn', '⏸️ Pausing - stopping batch loop...');
    
    // CRITICAL: Stop everything immediately
    RH.importing = false;
    RH.processingActive = false;
    
    // Clear any pending batch timeout
    if (RH.batchTimeout) {
        clearTimeout(RH.batchTimeout);
        RH.batchTimeout = null;
    }
    
    // Tell server to pause (update status in DB)
    try {
        const res = await fetch('<?=root?>modules/stays/ratehawk/content/import-state.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=pause'
        });
        const data = await res.json();
        if (data.success) {
            RH.log('success', '✅ Paused - click Resume to continue');
        }
    } catch (e) {
        RH.log('error', `Pause error: ${e.message}`);
    }
    
    // Fetch updated state to show paused status
    await RH.getState();
}

async function rhResumeImport(event) {
    if (event) event.preventDefault();
    RH.log('success', '▶️ Resuming import...');
    await RH.startImport();
}

async function rhResetImport(event) {
    if (event) event.preventDefault();
    if (!confirm('Reset import state? Processed data is NOT deleted, only UI state.')) return;
    RH.log('info', '🔄 Resetting...');
    RH.importing = false;
    RH.processingActive = false;
    clearInterval(RH.pollTimer);
    await fetch('<?=root?>modules/stays/ratehawk/content/import-state.php?action=reset');
    await RH.getState();
}

function clearTerminal() {
    document.getElementById('terminalBody').innerHTML = '<div class="text-gray-600">$ Terminal cleared</div>';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', async () => {
    const term = document.getElementById('terminalBody');
    const section = document.getElementById('terminalSection');
    
    if (!term || !section) {
        console.error('Terminal elements not found');
        return;
    }
    
    term.innerHTML = '';
    section.classList.remove('hidden');
    
    RH.log('success', '🚀 System initialized');
    
    try {
        // Get initial state
        const state = await RH.getState();
        if (state) {
            RH.log('info', `📊 Status: ${state.status.toUpperCase()}`);
        }
        
        // Start continuous polling for real-time updates
        RH.pollTimer = setInterval(() => RH.getState(), 1000);
        RH.log('info', '⏱️ Polling active (1s interval)');

        // If DB says "running" but we just loaded the page, the browser batch loop
        // was lost on refresh. Auto-restart it so import continues seamlessly.
        if (state && state.status === 'running') {
            RH.log('warn', '🔄 Detected in-progress import — auto-resuming batch loop...');
            await RH.startImport();
        }
    } catch (e) {
        RH.log('error', `Init error: ${e.message}`);
    }
});
</script>


