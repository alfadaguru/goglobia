<?php
/**
 * Agoda Content Import Interface – v2
 *
 * ── What changed on the front-end ────────────────────────────────────────────
 *  1. RESUME UI      – on load, if stats returns an interrupted_import the
 *                       button switches to "Resume Import" and a notice shows
 *                       the last known record position.
 *                       Selecting "Fresh Install" hides the notice and resets
 *                       the button so the user can override.
 *
 *  2. PROGRESS FIX    – the old code read data.current_chunk / data.total_chunks
 *                       which the server NEVER sent → progress bar was always 0 %.
 *                       NEW: reads data.progress.percent that /process now returns.
 *
 *  3. REMOVED POLL    – updateProgressFromServer() made a separate GET to
 *                       /progress after every batch.  That field is now inside
 *                       the /process JSON response, so no extra request is needed.
 *
 *  4. FASTER LOOP     – setTimeout delay reduced from 1 000 ms to 300 ms.
 *                       Combined with the byte-offset seeking on the server
 *                       side this roughly 3× the throughput.
 * ─────────────────────────────────────────────────────────────────────────────
 */
?>

<!-- Content Import Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <!-- Card Header -->
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">upload_file</span>
                <h3 class="text-sm font-semibold text-gray-900">CSV Import</h3>
            </div>
            <span id="importStatusBadge" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-md">
                Ready
            </span>
        </div>
    </div>

    <!-- Card Body -->
    <div class="p-6">
        <!-- Import Statistics -->
        <div id="importStats" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 rounded-lg p-4 border border-blue-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-blue-600 font-medium">Total Hotels</p>
                        <p id="totalHotels" class="text-2xl font-bold text-blue-900 mt-1">0</p>
                    </div>
                    <span class="material-symbols-outlined text-blue-400 text-3xl">hotel</span>
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-4 border border-green-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-green-600 font-medium">Last Sync</p>
                        <p id="lastSync" class="text-sm font-semibold text-green-900 mt-1">Never</p>
                    </div>
                    <span class="material-symbols-outlined text-green-400 text-3xl">sync</span>
                </div>
            </div>

            <div class="bg-purple-50 rounded-lg p-4 border border-purple-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-purple-600 font-medium">Destinations</p>
                        <p id="totalDestinations" class="text-2xl font-bold text-purple-900 mt-1">0</p>
                    </div>
                    <span class="material-symbols-outlined text-purple-400 text-3xl">location_on</span>
                </div>
            </div>

            <div class="bg-orange-50 rounded-lg p-4 border border-orange-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-orange-600 font-medium">Countries</p>
                        <p id="totalCountries" class="text-2xl font-bold text-orange-900 mt-1">0</p>
                    </div>
                    <span class="material-symbols-outlined text-orange-400 text-3xl">public</span>
                </div>
            </div>
        </div>

        <!-- CSV File Status -->
        <div id="csvFileStatus" class="bg-blue-50 rounded-lg p-4 border border-blue-200 mb-6">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">description</span>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-700">CSV File Location</p>
                    <p class="text-xs text-gray-600 mt-1 font-mono">modules/stays/agoda/content/agoda_db.csv</p>
                </div>
                <div id="csvFileIndicator">
                    <span class="text-xs text-gray-500">Checking…</span>
                </div>
            </div>
        </div>

        <!-- Import Actions -->
        <div id="importActions" class="bg-gray-50 rounded-lg p-6 border border-gray-200">
            <div class="text-center">
                <span class="material-symbols-outlined text-gray-400 text-6xl mb-4">sync</span>
                <h4 class="text-lg font-semibold text-gray-900 mb-2">Import Agoda Content</h4>
                <p class="text-gray-600 mb-6">Process hotel data from agoda_db.csv file</p>

                <!-- Import Mode Selection -->
                <div class="mb-6 max-w-md mx-auto">
                    <label class="block text-sm font-medium text-gray-700 mb-3">Import Mode</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Fresh Import -->
                        <div id="modeCardFresh"
                             class="import-mode-card border-2 border-gray-200 rounded-lg p-4 hover:border-red-400 transition-colors cursor-pointer"
                             onclick="selectImportMode('fresh')">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="importMode" value="fresh" class="mt-1">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-red-600">delete_sweep</span>
                                        <h4 class="font-semibold text-gray-900">Fresh Install</h4>
                                    </div>
                                    <p class="text-sm text-gray-600">Delete all existing data</p>
                                </div>
                            </div>
                        </div>

                        <!-- Update Mode -->
                        <div id="modeCardUpdate"
                             class="import-mode-card border-2 border-green-500 rounded-lg p-4 hover:border-green-600 transition-colors cursor-pointer"
                             onclick="selectImportMode('update')">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="importMode" value="update" class="mt-1" checked>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-green-600">sync</span>
                                        <h4 class="font-semibold text-gray-900">Update Existing</h4>
                                    </div>
                                    <p class="text-sm text-gray-600">Add new & update existing</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resume notice (hidden until an interrupted import is detected) -->
                <div id="resumeNotice" class="hidden bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-center">
                    <p class="text-sm text-amber-800">
                        <span class="material-symbols-outlined text-amber-600" style="font-size:18px;vertical-align:middle">warning</span>
                        Previous import interrupted at record <strong id="resumeRecord">0</strong> of <strong id="resumeTotal">0</strong>.
                        Click <strong>Resume Import</strong> to continue, or select <strong>Fresh Install</strong> to start over.
                    </p>
                </div>

                <button
                    type="button"
                    onclick="startCSVImport()"
                    id="startImportBtn"
                    class="btn primary inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <span class="material-symbols-outlined">play_arrow</span>
                    Start Import
                </button>
            </div>
        </div>

        <!-- Progress Section (Hidden by default) -->
        <div id="importProgress" class="hidden">
            <!-- Overall Progress -->
            <div class="mb-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">Overall Progress</span>
                    <span id="overallProgressText" class="text-sm font-semibold text-blue-600">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                    <div id="overallProgressBar" class="bg-gradient-to-r from-blue-500 to-indigo-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>

            <!-- Current Operation -->
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-4">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-700">Current Operation</p>
                        <p id="currentOperation" class="text-base font-semibold text-gray-900">Initializing…</p>
                    </div>
                </div>
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span id="currentRecords">0 records processed</span>
                    <span id="totalRecords">of 0 total</span>
                </div>
            </div>

            <!-- Real-time Log -->
            <div class="bg-gray-900 rounded-lg p-4 max-h-64 overflow-y-auto font-mono text-sm">
                <div id="importLog" class="space-y-1">
                    <div class="text-green-400">[INIT] Import process starting…</div>
                </div>
            </div>

            <!-- Control Buttons -->
            <div class="flex items-center justify-end gap-3 mt-4">
                <button
                    type="button"
                    onclick="cancelImport()"
                    id="cancelBtn"
                    class="btn secondary inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
                    <span class="material-symbols-outlined">cancel</span>
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ─── state ──────────────────────────────────────────────────────────────────
let importMode              = 'update';
let importInProgress        = false;
let hasInterruptedImport    = false;   // set true when stats shows an interrupted import

// ─── init ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadImportStatistics();
    checkCSVFile();
});

// ─── check CSV exists ───────────────────────────────────────────────────────
function checkCSVFile() {
    fetch('<?= root ?>modules/stays/agoda/check-csv', {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
        var indicator = document.getElementById('csvFileIndicator');
        if (data.exists) {
            indicator.innerHTML =
                '<div class="flex items-center gap-2">'
              + '<span class="material-symbols-outlined text-green-600">check_circle</span>'
              + '<span class="text-xs font-medium text-green-600">' + data.size + '</span>'
              + '</div>';
            document.getElementById('startImportBtn').disabled = false;
        } else {
            indicator.innerHTML =
                '<div class="flex items-center gap-2">'
              + '<span class="material-symbols-outlined text-red-600">error</span>'
              + '<span class="text-xs font-medium text-red-600">Not Found</span>'
              + '</div>';
            document.getElementById('startImportBtn').disabled = true;
        }
    })
    .catch(function (err) {
        console.error('checkCSVFile:', err);
        document.getElementById('csvFileIndicator').innerHTML =
            '<span class="text-xs text-red-600">Check Failed</span>';
    });
}

// ─── load statistics (also detects interrupted imports for resume) ──────────
function loadImportStatistics() {
    fetch('<?= root ?>modules/stays/agoda/stats', {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
        if (!data.success || !data.stats) return;

        document.getElementById('totalHotels').textContent       = data.stats.total_hotels        || 0;
        document.getElementById('totalDestinations').textContent  = data.stats.total_destinations  || 0;
        document.getElementById('totalCountries').textContent     = data.stats.total_countries     || 0;
        document.getElementById('lastSync').textContent           = data.stats.last_sync           || 'Never';

        // ── resume detection ─────────────────────────────────────────────
        if (data.stats.interrupted_import) {
            hasInterruptedImport = true;
            var ii = data.stats.interrupted_import;

            // fill notice text
            document.getElementById('resumeRecord').textContent = ii.records_processed;
            document.getElementById('resumeTotal').textContent  = ii.total_records;

            // show notice & switch button (only when update mode is active)
            applyResumeUI();
        }
    })
    .catch(function (err) { console.error('loadImportStatistics:', err); });
}

// ─── mode selection ─────────────────────────────────────────────────────────
function selectImportMode(mode) {
    importMode = mode;
    document.querySelector('input[value="' + mode + '"]').checked = true;

    // border highlight
    var freshCard  = document.getElementById('modeCardFresh');
    var updateCard = document.getElementById('modeCardUpdate');
    freshCard.classList.remove('border-red-500');
    freshCard.classList.add('border-gray-200');
    updateCard.classList.remove('border-green-500');
    updateCard.classList.add('border-gray-200');

    if (mode === 'fresh') {
        freshCard.classList.remove('border-gray-200');
        freshCard.classList.add('border-red-500');
    } else {
        updateCard.classList.remove('border-gray-200');
        updateCard.classList.add('border-green-500');
    }

    // update button / notice based on current state
    applyResumeUI();
}

/**
 * Sync button text and resume-notice visibility to the current
 * importMode + hasInterruptedImport combo.
 */
function applyResumeUI() {
    var btn    = document.getElementById('startImportBtn');
    var notice = document.getElementById('resumeNotice');

    if (hasInterruptedImport && importMode !== 'fresh') {
        // ── resume available ──
        btn.innerHTML = '<span class="material-symbols-outlined">refresh</span> Resume Import';
        btn.className  = btn.className
            .replace(/bg-blue-600/g, '').replace(/hover:bg-blue-700/g, '')
            .replace(/bg-amber-600/g, '').replace(/hover:bg-amber-700/g, '');
        btn.classList.add('bg-amber-600', 'hover:bg-amber-700');
        notice.classList.remove('hidden');
    } else {
        // ── normal start (or fresh) ──
        var label = importMode === 'fresh' ? 'Start Fresh' : 'Start Import';
        btn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span> ' + label;
        btn.className  = btn.className
            .replace(/bg-amber-600/g, '').replace(/hover:bg-amber-700/g, '')
            .replace(/bg-blue-600/g, '').replace(/hover:bg-blue-700/g, '');
        btn.classList.add('bg-blue-600', 'hover:bg-blue-700');
        notice.classList.add('hidden');
    }
}

// ─── start / resume ─────────────────────────────────────────────────────────
async function startCSVImport() {
    var isResume = hasInterruptedImport && importMode !== 'fresh';

    // confirmation
    var msg = isResume
        ? 'Resume the interrupted import from where it stopped?'
        : 'Start a ' + importMode + ' import? This will process agoda_db.csv.';
    if (!confirm(msg)) return;

    // switch to progress view
    document.getElementById('importActions').classList.add('hidden');
    document.getElementById('importProgress').classList.remove('hidden');
    importInProgress = true;

    document.getElementById('importStatusBadge').textContent = 'Processing…';
    document.getElementById('importStatusBadge').className   = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md animate-pulse';

    // clear old log
    document.getElementById('importLog').innerHTML = '';

    addLogEntry('[START] ' + (isResume ? 'Resuming import…' : 'Starting ' + importMode + ' import…'), 'text-cyan-400');

    try {
        // ── initialize (or resume) ──
        var initResult = await initializeImport(isResume);
        if (!initResult.success) throw new Error(initResult.message || 'Initialization failed');

        if (initResult.resumed) {
            addLogEntry('[RESUMED] Continuing from phase ' + initResult.current_phase
                      + ', record ' + initResult.records_processed, 'text-amber-400');
        } else {
            addLogEntry('[INFO] ' + initResult.total_rows + ' rows detected', 'text-blue-400');
            if (initResult.detected_columns) {
                addLogEntry('[INFO] ' + initResult.detected_columns.length + ' columns detected', 'text-blue-400');
            }
        }

        document.getElementById('totalRecords').textContent = 'of ' + initResult.total_rows + ' total';

        // ── main processing loop ──
        addLogEntry('[PROCESS] Processing…', 'text-cyan-400');
        await processCSVFile();

    } catch (error) {
        console.error('Import error:', error);
        addLogEntry('[ERROR] ' + error.message, 'text-red-400');
        onImportError(error.message);
    }
}

// ─── initialize / resume POST ───────────────────────────────────────────────
function initializeImport(resume) {
    return new Promise(function (resolve, reject) {
        var formData = new FormData();
        formData.append('mode',   importMode);
        formData.append('resume', resume ? '1' : '0');   // ← tells the server to look for an interrupted import

        fetch('<?= root ?>modules/stays/agoda/initialize-import', {
            method: 'POST',
            body:   formData
        })
        .then(function (r) { return r.json(); })
        .then(resolve)
        .catch(reject);
    });
}

// ─── processing loop ────────────────────────────────────────────────────────
function processCSVFile() {
    return new Promise(function (resolve, reject) {
        var consecutiveErrors = 0;
        var maxErrors         = 3;

        var tick = function () {
            if (!importInProgress) { reject(new Error('Import cancelled')); return; }

            fetch('<?= root ?>modules/stays/agoda/process', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    consecutiveErrors = 0;

                    // ── update progress bar directly from response ──────────
                    // (this replaces the old separate GET to /progress that was
                    //  reading fields the server never sent)
                    if (data.progress) {
                        var p = data.progress;
                        var pctRounded = Math.round(p.percent);
                        document.getElementById('overallProgressText').textContent = pctRounded + '%';
                        document.getElementById('overallProgressBar').style.width  = p.percent + '%';
                        document.getElementById('currentOperation').textContent    = p.current_operation || 'Processing…';
                        document.getElementById('currentRecords').textContent      = p.processed + ' records processed';
                        document.getElementById('totalRecords').textContent        = 'of ' + p.total + ' total';
                    }

                    // ── log entries ──
                    if (Array.isArray(data.detailed_log)) {
                        data.detailed_log.forEach(function (line) {
                            if (line) addLogEntry(line, 'text-gray-400');
                        });
                    }

                    if (data.completed) {
                        resolve();
                        onImportComplete();
                    } else {
                        setTimeout(tick, 2000);  // 1 request / 2 s – avoids host rate-limit
                    }
                } else {
                    consecutiveErrors++;
                    if (consecutiveErrors >= maxErrors) {
                        reject(new Error(data.error || 'Processing failed'));
                        onImportError(data.error || 'Processing failed after retries');
                    } else {
                        addLogEntry('[WARN] Error – retry ' + consecutiveErrors + '/' + maxErrors + '…', 'text-yellow-400');
                        setTimeout(tick, 2000);
                    }
                }
            })
            .catch(function (err) {
                console.error('process fetch error:', err);
                consecutiveErrors++;
                if (consecutiveErrors >= maxErrors) {
                    reject(err);
                    onImportError(err.message);
                } else {
                    addLogEntry('[WARN] Connection error – retry ' + consecutiveErrors + '/' + maxErrors + '…', 'text-yellow-400');
                    setTimeout(tick, 3000);
                }
            });
        };

        tick();
    });
}

// ─── completion ─────────────────────────────────────────────────────────────
function onImportComplete() {
    importInProgress     = false;
    hasInterruptedImport = false;

    document.getElementById('importStatusBadge').textContent = 'Completed';
    document.getElementById('importStatusBadge').className   = 'text-xs bg-green-100 text-green-700 px-2 py-1 rounded-md';
    addLogEntry('[COMPLETE] CSV import finished successfully!', 'text-green-400');

    setTimeout(function () {
        loadImportStatistics();
        checkCSVFile();
        document.getElementById('importProgress').classList.add('hidden');
        document.getElementById('importActions').classList.remove('hidden');
        resetButtonAndNotice();
    }, 2500);
}

// ─── error ──────────────────────────────────────────────────────────────────
function onImportError(error) {
    importInProgress = false;

    document.getElementById('importStatusBadge').textContent = 'Error';
    document.getElementById('importStatusBadge').className   = 'text-xs bg-red-100 text-red-700 px-2 py-1 rounded-md';
    addLogEntry('[ERROR] ' + error, 'text-red-400');
    addLogEntry('[INFO] Import stopped – you can resume next time or start fresh.', 'text-yellow-400');

    setTimeout(function () {
        document.getElementById('importProgress').classList.add('hidden');
        document.getElementById('importActions').classList.remove('hidden');
        // the import is still in pending/processing state so next load will offer resume
        hasInterruptedImport = true;
        applyResumeUI();
    }, 2500);
}

// ─── cancel ─────────────────────────────────────────────────────────────────
function cancelImport() {
    if (!confirm('Cancel the import? It will be marked cancelled and cannot be resumed.')) return;

    importInProgress = false;
    addLogEntry('[CANCEL] Cancelling…', 'text-yellow-400');

    fetch('<?= root ?>modules/stays/agoda/cancel', { method: 'POST' })
    .then(function (r) { return r.json(); })
    .then(function () {
        addLogEntry('[CANCELLED] Import cancelled.', 'text-red-400');
        setTimeout(function () {
            document.getElementById('importProgress').classList.add('hidden');
            document.getElementById('importActions').classList.remove('hidden');
            hasInterruptedImport = false;
            resetButtonAndNotice();
        }, 2000);
    })
    .catch(function (err) {
        console.error('cancel error:', err);
        addLogEntry('[ERROR] Cancel request failed', 'text-red-400');
    });
}

// ─── small helpers ──────────────────────────────────────────────────────────
function addLogEntry(message, colorClass) {
    if (!message || message === 'undefined' || message === 'null') return;
    colorClass = colorClass || 'text-gray-400';

    var container = document.getElementById('importLog');
    if (!container) return;

    var ts    = new Date().toLocaleTimeString();
    var entry = document.createElement('div');
    entry.className  = colorClass;
    entry.textContent = '[' + ts + '] ' + message;
    container.appendChild(entry);

    // auto-scroll
    var parent = container.parentElement;
    if (parent) parent.scrollTop = parent.scrollHeight;
}

function resetButtonAndNotice() {
    var btn = document.getElementById('startImportBtn');
    btn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span> Start Import';
    btn.className = btn.className
        .replace(/bg-amber-600/g, '').replace(/hover:bg-amber-700/g, '');
    btn.classList.add('bg-blue-600', 'hover:bg-blue-700');

    document.getElementById('resumeNotice').classList.add('hidden');

    // reset card borders
    document.getElementById('modeCardFresh').classList.remove('border-red-500');
    document.getElementById('modeCardFresh').classList.add('border-gray-200');
    document.getElementById('modeCardUpdate').classList.remove('border-gray-200');
    document.getElementById('modeCardUpdate').classList.add('border-green-500');
    importMode = 'update';
    document.querySelector('input[value="update"]').checked = true;
}
</script>