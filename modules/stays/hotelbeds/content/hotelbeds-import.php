<?php
/**
 * Hotelbeds Content Import Interface
 * This file handles the UI for importing Hotelbeds hotel content data
 * Standard import functionality - always available
 */
?>

<!-- Content Import Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <!-- Card Header -->
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">cloud_download</span>
                <h3 class="text-sm font-semibold text-gray-900">Content Import</h3>
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
            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-900 font-medium">Total Hotels</p>
                        <p id="totalHotels" class="text-2xl font-bold text-black mt-1">0</p>
                    </div>
                    <span class="material-symbols-outlined text-gray-900 text-3xl">hotel</span>
                </div>
            </div>

            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-900 font-medium">Last Sync</p>
                        <p id="lastSync" class="text-sm font-semibold text-black mt-1">Never</p>
                    </div>
                    <span class="material-symbols-outlined text-gray-900 text-3xl">sync</span>
                </div>
            </div>

            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-900 font-medium">Destinations</p>
                        <p id="totalDestinations" class="text-2xl font-bold text-black mt-1">0</p>
                    </div>
                    <span class="material-symbols-outlined text-gray-900 text-3xl">location_on</span>
                </div>
            </div>

            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-900 font-medium">Status</p>
                        <p id="importStatusText" class="text-sm font-semibold text-black mt-1">Not Started</p>
                    </div>
                    <span class="material-symbols-outlined text-gray-900 text-3xl">info</span>
                </div>
            </div>
        </div>

        <!-- Import Actions -->
        <div id="importActions" class="bg-gray-50 rounded-lg p-6 border border-gray-200">
            <div class="text-center">
                <span class="material-symbols-outlined text-gray-400 text-6xl mb-4">database</span>
                <h4 class="text-lg font-semibold text-gray-900 mb-2">No Content Found</h4>
                <p class="text-gray-600 mb-6">Start importing hotel content data from Hotelbeds API</p>

                <button
                    type="button"
                    onclick="showImportOptions()"
                    class="btn primary inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    <span class="material-symbols-outlined">download</span>
                    Import Content
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
                        <p id="currentOperation" class="text-base font-semibold text-gray-900">Initializing...</p>
                    </div>
                </div>
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span id="currentChunk">Chunk 0 of 0</span>
                    <span id="currentRecords">0 records processed</span>
                    <span id="eta">ETA: Calculating...</span>
                </div>
            </div>

            <!-- Real-time Log -->
            <div class="bg-gray-900 rounded-lg p-4 max-h-64 overflow-y-auto font-mono text-sm">
                <div id="importLog" class="space-y-1">
                    <div class="text-green-400">[INIT] Import process starting...</div>
                </div>
            </div>

            <!-- Control Buttons -->
            <div class="flex items-center justify-between mt-4">
                <button
                    type="button"
                    onclick="pauseImport()"
                    id="pauseBtn"
                    class="btn secondary inline-flex items-center gap-2 px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg font-medium transition-colors">
                    <span class="material-symbols-outlined">pause</span>
                    Pause
                </button>
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

<!-- Import Options Modal -->
<div id="importOptionsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50" style="backdrop-filter: blur(4px);">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl">
        <!-- Modal Header -->
        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 rounded-t-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-600 text-lg">settings</span>
                    <h3 class="text-sm font-semibold text-gray-900">Choose Import Mode</h3>
                </div>
                <button onclick="closeImportOptions()" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <p class="text-gray-600 mb-6">Select how you want to import the content data:</p>

            <!-- Option 1: Fresh Install -->
            <div class="border-2 border-gray-200 rounded-lg p-6 mb-4 hover:border-red-500 transition-colors cursor-pointer" onclick="selectImportMode('fresh')">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                            <span class="material-symbols-outlined text-red-600 text-2xl">delete_sweep</span>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">Fresh Install (Delete All Previous)</h4>
                        <p class="text-gray-600 text-sm mb-3">This will delete all existing Hotelbeds content data and perform a complete fresh import from the API.</p>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                            <p class="text-red-800 text-sm font-medium">⚠️ Warning: This action cannot be undone!</p>
                        </div>
                    </div>
                    <input type="radio" name="importMode" value="fresh" class="mt-2">
                </div>
            </div>

            <!-- Option 2: Update Existing -->
            <div class="border-2 border-gray-200 rounded-lg p-6 hover:border-green-500 transition-colors cursor-pointer" onclick="selectImportMode('update')">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <span class="material-symbols-outlined text-green-600 text-2xl">sync</span>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">Update/Resume Import</h4>
                        <p class="text-gray-600 text-sm mb-3">This will resume any interrupted import or update existing records with new data. Safe for ongoing syncs.</p>
                        <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                            <p class="text-green-800 text-sm font-medium">✅ Safe: Resumes interrupted imports & preserves data</p>
                        </div>
                    </div>
                    <input type="radio" name="importMode" value="update" class="mt-2" checked>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="bg-gray-50 px-6 py-4 rounded-b-lg flex items-center justify-end gap-3">
            <button
                type="button"
                onclick="closeImportOptions()"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 font-medium transition-colors">
                Cancel
            </button>
            <button
                type="button"
                onclick="startImport(event)"
                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                <span class="material-symbols-outlined">play_arrow</span>
                Start Import
            </button>
        </div>
    </div>
</div>

<script>
// Global variables for import process
let importMode = 'update';
let importInProgress = false;
let importPaused = false;
let importRetryCount = 0; // consecutive transient (non-JSON / network) failures
let importRequestInFlight = false;
let importRetryTimer = null;

// Load initial statistics
document.addEventListener('DOMContentLoaded', function() {
    loadImportStatistics();
    checkAndRestoreImport(); // reconnect to a running/paused import after a refresh
});

// Reconnect to an in-progress import after a page refresh. The whole state lives
// in the database, so we restore the progress view and either resume the chunk
// loop or show the paused state. This is what makes refresh/pause survive.
function checkAndRestoreImport() {
    fetch('<?= root ?>modules/stays/hotelbeds/progress', { method: 'GET' })
        .then(r => r.json())
        .then(data => {
            if (!data.success || data.status !== 'in_progress') return;

            document.getElementById('importActions').classList.add('hidden');
            document.getElementById('importProgress').classList.remove('hidden');
            importInProgress = true;
            document.getElementById('importStatusText').textContent = 'in_progress';
            updateProgressUI(data);

            if (data.paused) {
                importPaused = true;
                document.getElementById('importStatusBadge').textContent = 'Paused';
                document.getElementById('importStatusBadge').className = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md';
                setPauseButtonToResume();
                addLogEntry('[PAUSED] Import is paused — click Resume to continue.', 'text-yellow-400');
            } else {
                importPaused = false;
                document.getElementById('importStatusBadge').textContent = 'Importing...';
                document.getElementById('importStatusBadge').className = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md animate-pulse';
                setPauseButtonToPause();
                addLogEntry('[RECONNECT] Reconnected to running import…', 'text-cyan-400');
                processImportChunk();
            }
        })
        .catch(() => {});
}

// Load statistics from database
function formatHotelbedsCount(n) {
    const num = Number(n) || 0;
    try {
        return num.toLocaleString();
    } catch (e) {
        return String(num);
    }
}

function applyHotelbedsStatCards(payload) {
    const stats = payload && payload.stats ? payload.stats : payload;
    if (!stats) return;
    if (typeof stats.total_hotels !== 'undefined') {
        document.getElementById('totalHotels').textContent = formatHotelbedsCount(stats.total_hotels);
    }
    if (typeof stats.total_destinations !== 'undefined') {
        document.getElementById('totalDestinations').textContent = formatHotelbedsCount(stats.total_destinations);
    }
    if (typeof stats.last_sync !== 'undefined' && stats.last_sync) {
        document.getElementById('lastSync').textContent = stats.last_sync;
    }
}

function loadImportStatistics() {
    fetch('<?= root ?>modules/stays/hotelbeds/stats', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        },
        cache: 'no-store'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            applyHotelbedsStatCards(data);
            // Do not let a slower stats response overwrite a restored live import.
            document.getElementById('importStatusText').textContent = importInProgress
                ? 'in_progress'
                : (data.stats.status || 'Not Started');

            if ((data.stats.total_hotels || 0) > 0) {
                updateUIForExistingContent();
            }
        }
    })
    .catch(error => {
        console.error('Error loading statistics:', error);
    });
}

// Update UI when content already exists
function updateUIForExistingContent() {
    const importActions = document.getElementById('importActions');
    importActions.innerHTML = `
        <div class="text-center">
            <span class="material-symbols-outlined text-green-500 text-6xl mb-4">check_circle</span>
            <h4 class="text-lg font-semibold text-gray-900 mb-2">Content Available</h4>
            <p class="text-gray-600 mb-6">Your database contains Hotelbeds content. You can update or refresh it.</p>

            <button
                type="button"
                onclick="showImportOptions()"
                class="btn primary inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                <span class="material-symbols-outlined">refresh</span>
                Update Content
            </button>
        </div>
    `;
}

// Show import options modal
function showImportOptions() {
    document.getElementById('importOptionsModal').classList.remove('hidden');
}

// Close import options modal
function closeImportOptions() {
    document.getElementById('importOptionsModal').classList.add('hidden');
}

// Select import mode
function selectImportMode(mode) {
    importMode = mode;
    document.querySelector(`input[value="${mode}"]`).checked = true;
}

// Start the import process
function startImport(event) {
    if (event) event.preventDefault();

    closeImportOptions();

    // Hide actions, show progress
    document.getElementById('importActions').classList.add('hidden');
    document.getElementById('importProgress').classList.remove('hidden');

    importInProgress = true;
    importPaused = false;
    document.getElementById('importStatusText').textContent = 'in_progress';

    // Update status badge
    document.getElementById('importStatusBadge').textContent = 'Importing...';
    document.getElementById('importStatusBadge').className = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md animate-pulse';

    // Start the import via AJAX
    executeImport();
}

// Execute the import process
function executeImport() {
    // Always start a clean session when the user clicks Import/Update.
    // Auto-resume of a stuck in_progress row caused "credentials not found"
    // and a frozen progress bar while stats still showed old hotel counts.
    addLogEntry('[START] Starting a new import session…', 'text-cyan-400');
    startNewImport();
}

// Start a new import process
function startNewImport() {
    const formData = new FormData();
    formData.append('mode', importMode);
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('c1', '<?= $module['c1'] ?>'); // API Key
    formData.append('c2', '<?= $module['c2'] ?>'); // API Secret
    formData.append('env', '<?= (($module['dev_mode'] ?? '1') == '1') ? 'test' : 'live' ?>'); // from modules.dev_mode

    addLogEntry('[START] Initializing (cancels any stuck prior session)…', 'text-cyan-400');
    addLogEntry(`[MODE] Import mode: ${importMode === 'fresh' ? 'Fresh Install' : 'Update Existing'}`, 'text-blue-400');
    addLogEntry('[WAIT] Masters first, then rate comments (paginated), then hotels…', 'text-blue-400');
    document.getElementById('currentOperation').textContent = 'Initializing import & reference data…';

    fetch('<?= root ?>modules/stays/hotelbeds/content_import', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        const trimmed = (text || '').trim();
        if (!trimmed || (trimmed[0] !== '{' && trimmed[0] !== '[')) {
            // Init timed out after session create — /process can still continue.
            addLogEntry('[WARN] Init response incomplete (timeout?). Continuing via process loop…', 'text-yellow-400');
            processImportChunk();
            return;
        }
        let data;
        try { data = JSON.parse(trimmed); } catch (e) {
            addLogEntry('[WARN] Init JSON parse failed — continuing via process loop…', 'text-yellow-400');
            processImportChunk();
            return;
        }
        if (data.success) {
            addLogEntry('[SUCCESS] Import session ready' + (data.reference_records != null ? ` (masters: ${data.reference_records})` : ''), 'text-green-400');
            processImportChunk();
        } else {
            addLogEntry(`[ERROR] ${data.message || 'Init failed'}`, 'text-red-400');
            importInProgress = false;
        }
    })
    .catch(error => {
        // Network timeout on long reference import: session may already exist.
        addLogEntry(`[WARN] Init request ended (${error.message}). Trying process loop…`, 'text-yellow-400');
        processImportChunk();
    });
}

// Process import chunks continuously
function processImportChunk() {
    if (!importInProgress || importPaused || importRequestInFlight) return;

    if (importRetryTimer) {
        clearTimeout(importRetryTimer);
        importRetryTimer = null;
    }
    importRequestInFlight = true;

    // Single round-trip: /process does the work AND returns the progress payload,
    // so there's no separate /progress poll per chunk.
    fetch('<?= root ?>modules/stays/hotelbeds/process', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
    .then(response => response.text())
    .then(text => {
        importRequestInFlight = false;
        if (importPaused) return;
        const trimmed = (text || '').trim();

        // On live/shared hosting the server layer (LiteSpeed / cPanel / mod_security)
        // occasionally returns an HTML error or throttle page instead of our JSON —
        // usually a transient rate-limit. Detect any non-JSON body, log it cleanly
        // (no giant HTML dump), and retry after a backoff instead of failing.
        if (!trimmed || (trimmed[0] !== '{' && trimmed[0] !== '[')) {
            importRetryCount++;
            if (importRetryCount > 5) {
                importPaused = true;
                document.getElementById('importStatusText').textContent = 'in_progress';
                document.getElementById('importStatusBadge').textContent = 'Paused';
                addLogEntry('[PAUSED] Server kept returning non-JSON responses. Same chunk is preserved.', 'text-yellow-400');
                setPauseButtonToResume();
                fetch('<?= root ?>modules/stays/hotelbeds/pause', { method: 'POST' }).catch(() => {});
                return;
            }
            addLogEntry(`[RETRY ${importRetryCount}] Server returned a non-JSON response. Applying backoff before retrying the same chunk.`, 'text-yellow-400');
            queueHotelbedsChunk(getHotelbedsRetryDelay(importRetryCount));
            return;
        }

        let data;
        try {
            data = JSON.parse(trimmed);
        } catch (e) {
            importRetryCount++;
            addLogEntry(`[RETRY ${importRetryCount}] Could not parse server response. Applying backoff before retrying the same chunk.`, 'text-yellow-400');
            queueHotelbedsChunk(getHotelbedsRetryDelay(importRetryCount));
            return;
        }

        if (data.paused) {
            // Import was paused server-side while this chunk was in flight.
            importPaused = true;
            setPauseButtonToResume();
            addLogEntry('[PAUSED] Import paused.', 'text-yellow-400');
            return;
        }

        if (data.success) {
            if (data.completed) {
                if (data.error) {
                    (data.detailed_log || []).forEach(l => addLogEntry(l, 'text-yellow-400'));
                    onImportError(data.error);
                } else {
                    onImportComplete(data);
                }
            } else {
                // A chunk actually succeeded — reset the failure counter, reflect
                // progress, then queue the next one with just a tiny yield.
                importRetryCount = 0;
                updateProgressUI(data);
                queueHotelbedsChunk(1500);
            }
        } else if (data.retryable || data.fatal) {
            // Real server-side error (e.g. DB / memory). Show the ACTUAL reason —
            // no masking — and keep the import resumable. Auto-retry this same
            // chunk a few times, then pause so the user can decide.
            (data.detailed_log || []).forEach(l => addLogEntry(l, 'text-yellow-400'));
            addLogEntry(`[SERVER ERROR] ${data.error || 'Unknown error'}`, 'text-red-400');
            importRetryCount++;
            if (importRetryCount > 5) {
                addLogEntry('[PAUSED] Auto-paused after repeated errors — click Resume to retry from this chunk.', 'text-yellow-400');
                importPaused = true;
                setPauseButtonToResume();
                fetch('<?= root ?>modules/stays/hotelbeds/pause', { method: 'POST' }).catch(() => {});
            } else {
                addLogEntry(`[RETRY ${importRetryCount}] Applying backoff before retrying the same chunk.`, 'text-yellow-400');
                queueHotelbedsChunk(getHotelbedsRetryDelay(importRetryCount));
            }
        } else {
            // Terminal error (e.g. bad API credentials) — stop and show it.
            (data.detailed_log || []).forEach(l => addLogEntry(l, 'text-yellow-400'));
            onImportError(data.error || 'Chunk processing failed');
        }
    })
    .catch(error => {
        // Network-level failure (connection dropped, timeout). Same transient
        // handling: back off and retry, giving up only after many attempts.
        console.error('Fetch error:', error);
        importRequestInFlight = false;
        if (importPaused) return;
        importRetryCount++;
        if (importRetryCount > 5) {
            importPaused = true;
            document.getElementById('importStatusText').textContent = 'in_progress';
            document.getElementById('importStatusBadge').textContent = 'Paused';
            addLogEntry('[PAUSED] Network kept failing. Same chunk is preserved.', 'text-yellow-400');
            setPauseButtonToResume();
            fetch('<?= root ?>modules/stays/hotelbeds/pause', { method: 'POST' }).catch(() => {});
            return;
        }
        addLogEntry(`[RETRY ${importRetryCount}] Network error (${error.message}). Applying backoff before retrying the same chunk.`, 'text-yellow-400');
        queueHotelbedsChunk(getHotelbedsRetryDelay(importRetryCount));
    });
}

function queueHotelbedsChunk(delay) {
    if (importRetryTimer) clearTimeout(importRetryTimer);
    importRetryTimer = setTimeout(() => {
        importRetryTimer = null;
        processImportChunk();
    }, delay);
}

// 15s, 30s, 60s, then 120s for subsequent transient failures.
function getHotelbedsRetryDelay(retryCount) {
    const delays = [15000, 30000, 60000, 120000];
    return delays[Math.min(Math.max(retryCount - 1, 0), delays.length - 1)];
}

// Update progress UI
function updateProgressUI(data) {
    // Update overall progress (guard against divide-by-zero)
    const total = data.total || 1;
    const percentage = Math.min(100, Math.round((data.processed / total) * 100));
    document.getElementById('overallProgressText').textContent = `${percentage}%`;
    document.getElementById('overallProgressBar').style.width = `${percentage}%`;

    // Update current operation
    document.getElementById('currentOperation').textContent = data.current_operation || 'Processing...';
    document.getElementById('currentChunk').textContent = `Chunk ${data.current_chunk} of ${data.total_chunks}`;
    document.getElementById('currentRecords').textContent = `${data.processed} of ${data.total} records`;
    document.getElementById('eta').textContent = `ETA: ${data.eta || 'Calculating...'}`;

    // Keep Total Hotels / Last Sync stable during import — refresh them after sync completes.
    if (typeof data.total_destinations !== 'undefined') {
        document.getElementById('totalDestinations').textContent = formatHotelbedsCount(data.total_destinations);
    }

    // Append only new server log lines. The backend keeps a sliding window of
    // 200 entries, so a numeric array index would stop moving once it reaches 200.
    if (Array.isArray(data.logs)) {
        window.__hbLogSeen = window.__hbLogSeen || new Set();
        data.logs.forEach(log => {
            const key = typeof log === 'string' ? log : JSON.stringify(log);
            if (window.__hbLogSeen.has(key)) return;
            window.__hbLogSeen.add(key);

            if (typeof log === 'string') {
                addLogEntry(log, 'text-gray-400');
            } else if (log && log.message) {
                addLogEntry(log.message, log.type || 'text-gray-400');
            }
        });

        // Bound browser memory during very long imports.
        if (window.__hbLogSeen.size > 500) {
            window.__hbLogSeen = new Set(Array.from(window.__hbLogSeen).slice(-300));
        }
    }
}

// Add log entry to terminal
function addLogEntry(message, colorClass = 'text-gray-400') {
    // Prevent undefined or null messages
    if (!message || message === 'undefined') {
        return;
    }

    const logContainer = document.getElementById('importLog');
    const timestamp = new Date().toLocaleTimeString();
    const logEntry = document.createElement('div');
    logEntry.className = colorClass;
    logEntry.textContent = `[${timestamp}] ${message}`;
    logContainer.appendChild(logEntry);

    // Auto-scroll to bottom
    logContainer.parentElement.scrollTop = logContainer.parentElement.scrollHeight;
}

// Import complete handler
function onImportComplete(data) {
    importInProgress = false;
    document.getElementById('importStatusText').textContent = 'completed';
    document.getElementById('importStatusBadge').textContent = 'Completed';
    document.getElementById('importStatusBadge').className = 'text-xs bg-green-100 text-green-700 px-2 py-1 rounded-md';

    if (data && Array.isArray(data.logs)) {
        data.logs.slice(-5).forEach(function (line) {
            addLogEntry(line, line.indexOf('[CLEANUP]') !== -1 ? 'text-yellow-400' : 'text-gray-400');
        });
    }
    if (data && typeof data.purged_hotels !== 'undefined' && data.purged_hotels > 0) {
        addLogEntry('[CLEANUP] Removed ' + data.purged_hotels + ' hotels no longer in the API catalogue', 'text-yellow-400');
    }
    if (data) {
        applyHotelbedsStatCards(data);
    }

    addLogEntry('[COMPLETE] Import process finished successfully!', 'text-green-400');

    // Reload statistics (unique hotel count + completed Last Sync)
    setTimeout(() => {
        loadImportStatistics();
        // Reset UI
        document.getElementById('importProgress').classList.add('hidden');
        document.getElementById('importActions').classList.remove('hidden');
    }, 1500);
}

// Import error handler
function onImportError(error) {
    importInProgress = false;
    document.getElementById('importStatusText').textContent = 'failed';
    document.getElementById('importStatusBadge').textContent = 'Error';
    document.getElementById('importStatusBadge').className = 'text-xs bg-red-100 text-red-700 px-2 py-1 rounded-md';

    addLogEntry(`[ERROR] ${error}`, 'text-red-400');
}

// Pause/Resume button state helpers
function setPauseButtonToResume() {
    const b = document.getElementById('pauseBtn');
    b.innerHTML = '<span class="material-symbols-outlined">play_arrow</span> Resume';
    b.onclick = resumeImport;
}
function setPauseButtonToPause() {
    const b = document.getElementById('pauseBtn');
    b.innerHTML = '<span class="material-symbols-outlined">pause</span> Pause';
    b.onclick = pauseImport;
}

// Pause import — persisted server-side so it survives a page refresh.
function pauseImport() {
    importPaused = true;
    if (importRetryTimer) {
        clearTimeout(importRetryTimer);
        importRetryTimer = null;
    }
    addLogEntry('[PAUSED] Pausing import…', 'text-yellow-400');
    document.getElementById('importStatusBadge').textContent = 'Paused';
    document.getElementById('importStatusBadge').className = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md';
    setPauseButtonToResume();
    fetch('<?= root ?>modules/stays/hotelbeds/pause', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.paused) {
                throw new Error(data.error || 'Server did not confirm pause');
            }

            document.getElementById('importStatusText').textContent = 'paused';
            document.getElementById('currentOperation').textContent = 'Import paused successfully';
            addLogEntry('[PAUSED] Import paused successfully. Click Resume to continue.', 'text-yellow-400');
        })
        .catch(error => {
            document.getElementById('currentOperation').textContent = 'Pause could not be confirmed';
            addLogEntry(`[ERROR] Pause failed: ${error.message}`, 'text-red-400');
        });
}

// Resume import — clears the server pause flag and continues the chunk loop.
function resumeImport() {
    if (importRetryTimer) {
        clearTimeout(importRetryTimer);
        importRetryTimer = null;
    }
    importPaused = false;
    importInProgress = true;
    importRetryCount = 0;
    importRequestInFlight = false;
    addLogEntry('[RESUMED] Resuming import from saved chunk…', 'text-green-400');
    document.getElementById('importStatusBadge').textContent = 'Importing...';
    document.getElementById('importStatusBadge').className = 'text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-md animate-pulse';
    document.getElementById('importStatusText').textContent = 'in_progress';
    document.getElementById('currentOperation').textContent = 'Resuming…';
    setPauseButtonToPause();
    fetch('<?= root ?>modules/stays/hotelbeds/resume', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success === false) {
                addLogEntry('[ERROR] Resume failed: ' + (data.error || 'unknown'), 'text-red-400');
            }
        })
        .catch(() => {})
        .finally(() => {
            // Small delay so the paused flag is cleared in DB before /process.
            queueHotelbedsChunk(300);
        });
}

// Cancel import
function cancelImport() {
    if (confirm('Are you sure you want to cancel the import? Progress will be lost.')) {
        importInProgress = false;
        importPaused = false;
        if (importRetryTimer) {
            clearTimeout(importRetryTimer);
            importRetryTimer = null;
        }

        fetch('<?= root ?>modules/stays/hotelbeds/content_cancel', {
            method: 'POST'
        })
        .then(() => {
            addLogEntry('[CANCELLED] Import process cancelled by user', 'text-red-400');

            setTimeout(() => {
                document.getElementById('importProgress').classList.add('hidden');
                document.getElementById('importActions').classList.remove('hidden');
            }, 2000);
        });
    }
}


</script>
