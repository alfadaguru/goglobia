<?php
/**
 * Stuba Content Import Interface
 * This file handles the UI for importing Stuba hotel content data
 * Standard import functionality - always available
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
                <strong>Automated Import Process:</strong><br>
                1. Click <strong>"Import All Hotels"</strong> to fetch hotels from ALL regions via Stuba Content API<br>
                2. System will automatically fetch hotels by regions (cities) for more accurate data<br>
                3. Finally enriches hotels with complete details (images, descriptions, amenities) in batches of 100<br>
                4. Progress tracked in real-time. Estimated time: 45-90 minutes for complete database
            </p>
        </div>
    </div>
</div>

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
            <div class="bg-blue-50 rounded-lg p-4 border border-blue-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-blue-600 font-medium">Total Regions</p>
                        <p class="text-2xl font-bold text-blue-900 mt-1" id="totalRegions">0</p>
                    </div>
                    <span class="material-symbols-outlined text-blue-400 text-3xl">location_city</span>
                </div>
            </div>

            <div class="bg-purple-50 rounded-lg p-4 border border-purple-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-purple-600 font-medium">Total Hotels</p>
                        <p class="text-2xl font-bold text-purple-900 mt-1" id="totalHotels">0</p>
                    </div>
                    <span class="material-symbols-outlined text-purple-400 text-3xl">hotel</span>
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-4 border border-green-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-green-600 font-medium">Countries</p>
                        <p class="text-2xl font-bold text-green-900 mt-1" id="totalCountries">0</p>
                    </div>
                    <span class="material-symbols-outlined text-green-400 text-3xl">public</span>
                </div>
            </div>

            <div class="bg-orange-50 rounded-lg p-4 border border-orange-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-orange-600 font-medium">Last Sync</p>
                        <p class="text-2xl font-bold text-orange-900 mt-1" id="lastSync">Never</p>
                    </div>
                    <span class="material-symbols-outlined text-orange-400 text-3xl">sync</span>
                </div>
            </div>
        </div>

        <!-- Import Mode Selection -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Import Mode</label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="relative">
                    <input type="radio" name="stuba_import_mode" id="stuba_mode_fresh" value="fresh" class="peer hidden">
                    <label for="stuba_mode_fresh" class="block p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50 transition-all">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-blue-500 text-2xl">refresh</span>
                            <div>
                                <h4 class="font-semibold text-gray-900">Fresh Install</h4>
                                <p class="text-xs text-gray-600 mt-1">Delete all existing data and import fresh content from Stuba API</p>
                            </div>
                        </div>
                    </label>
                </div>

                <div class="relative">
                    <input type="radio" name="stuba_import_mode" id="stuba_mode_update" value="update" class="peer hidden" checked>
                    <label for="stuba_mode_update" class="block p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-green-300 peer-checked:border-green-500 peer-checked:bg-green-50 transition-all">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-green-500 text-2xl">update</span>
                            <div>
                                <h4 class="font-semibold text-gray-900">Update Mode</h4>
                                <p class="text-xs text-gray-600 mt-1">Update existing records and add new ones without deleting current data</p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Progress Section (Hidden by default) -->
        <div id="progressSection" class="hidden mb-6">
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700">Overall Progress</span>
                        <span class="text-sm font-semibold text-blue-600" id="progressPercentage">0%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-300 flex items-center justify-center" style="width: 0%">
                            <span class="text-xs text-white font-medium" id="progressBarText"></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500 text-lg">animation</span>
                        <div>
                            <p class="text-xs text-gray-500">Current Operation</p>
                            <p class="text-sm font-medium text-gray-900" id="currentOperation">Processing...</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-green-500 text-lg">inventory_2</span>
                        <div>
                            <p class="text-xs text-gray-500">Progress</p>
                            <p class="text-sm font-medium text-gray-900" id="processedRecords">0</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-500 text-lg">schedule</span>
                        <div>
                            <p class="text-xs text-gray-500">Elapsed Time</p>
                            <p class="text-sm font-medium text-gray-900" id="elapsedTime">0s</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span id="currentStepInfo">Starting...</span>
                </div>
            </div>

            <!-- Terminal Console -->
            <div class="mt-4 bg-gray-900 rounded-lg overflow-hidden border border-gray-700">
                <div class="bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700">
                    <div class="flex items-center space-x-2">
                        <div class="flex space-x-2">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                        <span class="text-gray-300 text-xs font-mono ml-4">Import Console</span>
                    </div>
                </div>
                <div id="consoleOutput" class="p-4 font-mono text-xs text-green-400 h-48 overflow-y-auto">
                    <div class="text-cyan-400">[INIT] Import process starting...</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3">
            <button type="button" id="startImportBtn" onclick="startFullHotelImport(); return false;" class="btn flex-1 bg-gradient-to-r from-green-500 to-green-600 text-white">
                <span class="material-symbols-outlined text-sm">cloud_download</span>
                Import All Hotels from Stuba API
            </button>
            <button type="button" id="enrichHotelsBtn" onclick="enrichHotelsWithDetails(); return false;" class="btn flex-1 opacity-50 cursor-not-allowed" title="Run basic import first" disabled>
                <span class="material-symbols-outlined text-sm">auto_awesome</span>
                Enrich Hotels (Disabled - Run Import First)
            </button>
        </div>
    </div>
</div>

<script>
let importStopped = false;
let startTime = Date.now();
let elapsedInterval = null;

// Load statistics on page load
document.addEventListener('DOMContentLoaded', function() {
    loadStubaImportStats();
});

function loadStubaImportStats() {
    fetch('<?= root ?>modules/stays/stuba/content/stats')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalRegions').textContent = (data.data.total_regions || 0).toLocaleString();
                document.getElementById('totalHotels').textContent = (data.data.total_hotels || 0).toLocaleString();
                document.getElementById('totalCountries').textContent = (data.data.total_countries || 0).toLocaleString();
                
                if (data.data.last_sync) {
                    const lastSync = new Date(data.data.last_sync);
                    const now = new Date();
                    const diffHours = Math.floor((now - lastSync) / (1000 * 60 * 60));
                    
                    if (diffHours < 1) {
                        document.getElementById('lastSync').textContent = 'Just now';
                    } else if (diffHours < 24) {
                        document.getElementById('lastSync').textContent = diffHours + 'h ago';
                    } else {
                        document.getElementById('lastSync').textContent = Math.floor(diffHours / 24) + 'd ago';
                    }
                } else {
                    document.getElementById('lastSync').textContent = 'Never';
                }
                
                // Enable enrich button if hotels exist
                const enrichBtn = document.getElementById('enrichHotelsBtn');
                if (data.data.total_hotels > 0) {
                    enrichBtn.disabled = false;
                    enrichBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    enrichBtn.title = '';
                }
            }
        })
        .catch(error => {
            console.error('Failed to load stats:', error);
        });
}

function addStubaLog(message, color = 'green') {
    const console = document.getElementById('consoleOutput');
    const timestamp = new Date().toLocaleTimeString();
    
    const colorMap = {
        'green': 'text-green-400',
        'cyan': 'text-cyan-400',
        'blue': 'text-blue-400',
        'yellow': 'text-yellow-400',
        'orange': 'text-orange-400',
        'red': 'text-red-400',
        'white': 'text-white'
    };
    
    const logLine = document.createElement('div');
    logLine.className = colorMap[color] || 'text-green-400';
    logLine.textContent = `[${timestamp}] ${message}`;
    
    console.appendChild(logLine);
    console.scrollTop = console.scrollHeight;
}

// Full Hotel Import from Stuba Content API (4-step process)
function startFullHotelImport() {
    const btn = document.getElementById('startImportBtn');
    const enrichBtn = document.getElementById('enrichHotelsBtn');
    const progressSection = document.getElementById('progressSection');
    const progressBar = document.getElementById('progressBar');
    const progressPercentage = document.getElementById('progressPercentage');
    const currentOperation = document.getElementById('currentOperation');
    const consoleOutput = document.getElementById('consoleOutput');
    const processedRecords = document.getElementById('processedRecords');
    const elapsedTime = document.getElementById('elapsedTime');
    const currentStepInfo = document.getElementById('currentStepInfo');
    
    // Reset stop flag
    importStopped = false;
    startTime = Date.now();
    
    // Show progress section
    progressSection.classList.remove('hidden');
    
    // Change button to Stop
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm">stop</span> Stop Import';
    btn.onclick = stopImport;
    btn.classList.remove('bg-gradient-to-r', 'from-green-500', 'to-green-600');
    btn.classList.add('bg-red-500', 'hover:bg-red-600');
    
    enrichBtn.disabled = true;
    
    if (consoleOutput) consoleOutput.innerHTML = '';
    progressBar.style.width = '0%';
    progressPercentage.textContent = '0%';
    currentOperation.textContent = 'Starting import...';
    processedRecords.textContent = '0';
    
    // Start elapsed time counter
    if (elapsedInterval) clearInterval(elapsedInterval);
    elapsedInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        elapsedTime.textContent = elapsed + 's';
    }, 1000);
    
    addStubaLog('[INFO] Starting full hotel import from Stuba Content API', 'blue');
    
    let totalCountries = 0;
    let totalRegions = 0;
    let totalHotels = 0;
    
    // **ADD THIS: Initialize import log using existing endpoint**
    const formData = new FormData();
    formData.append('mode', 'update');
    
    fetch('<?= root ?>modules/stays/stuba/content_import', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            addStubaLog('[INFO] Import session initialized', 'cyan');
        }
        
        // Step 1: Import all countries
        addStubaLog('[STEP 1/4] Importing countries...', 'blue');
        currentStepInfo.textContent = 'Step 1/4: Importing countries...';
        
        return fetch('<?= root ?>modules/stays/stuba/content/import-countries', {
            method: 'POST'
        });
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw new Error(data.message || 'Failed to import countries');
        }
        
        totalCountries = data.countries_imported;
        addStubaLog(`[SUCCESS] Imported ${totalCountries} countries`, 'green');
        progressBar.style.width = '10%';
        progressPercentage.textContent = '10%';
        currentOperation.textContent = `Step 1/4 complete: ${totalCountries} countries`;
        currentStepInfo.textContent = `Step 1/4 complete: ${totalCountries} countries imported`;
        
        // Step 2: Import regions by countries
        addStubaLog('[STEP 2/4] Importing regions (cities) by country...', 'blue');
        currentStepInfo.textContent = 'Step 2/4: Importing regions from all countries...';
        
        return importRegionsByCountries();
    })
    .then(regionsImported => {
        totalRegions = regionsImported;
        addStubaLog(`[SUCCESS] Imported ${totalRegions.toLocaleString()} regions from all countries`, 'green');
        progressBar.style.width = '30%';
        progressPercentage.textContent = '30%';
        currentOperation.textContent = `Step 2/4 complete: ${totalRegions.toLocaleString()} regions`;
        currentStepInfo.textContent = `Step 2/4 complete: ${totalRegions.toLocaleString()} regions imported`;
        
        // Step 3: Import hotels by REGIONS (not countries)
        addStubaLog('[STEP 3/4] Importing hotels by regions...', 'blue');
        addStubaLog('[INFO] Fetching hotels from each region for accurate data', 'cyan');
        currentStepInfo.textContent = 'Step 3/4: Importing hotels from all regions...';
        
        return importHotelsByRegionBatch();
    })
    .then(hotelsImported => {
        totalHotels = hotelsImported;
        addStubaLog(`[SUCCESS] Imported ${totalHotels.toLocaleString()} hotels from all regions`, 'green');
        progressBar.style.width = '70%';
        progressPercentage.textContent = '70%';
        currentOperation.textContent = `Step 3/4 complete: ${totalHotels.toLocaleString()} hotels`;
        currentStepInfo.textContent = `Step 3/4 complete: ${totalHotels.toLocaleString()} hotels imported`;
        
        // Step 4: Enrich all hotels
        addStubaLog('[STEP 4/4] Enriching hotels with details (images, amenities)...', 'blue');
        currentStepInfo.textContent = 'Step 4/4: Enriching hotels with complete details...';
        
        enrichHotelsAfterImport();
    })
    .catch(error => {
        addStubaLog('[ERROR] ' + error.message, 'red');
        resetImportButton();
        currentOperation.textContent = 'Import failed';
        currentStepInfo.textContent = 'Import failed: ' + error.message;
        if (elapsedInterval) clearInterval(elapsedInterval);
    });
}

// Import regions by countries in batches
function importRegionsByCountries() {
    return new Promise((resolve, reject) => {
        let offset = 0;
        let totalRegionsImported = 0;
        const batchSize = 10; // Process 10 countries at a time
        
        function processNextBatch() {
            if (importStopped) {
                resolve(totalRegionsImported);
                return;
            }
            
            const formData = new FormData();
            formData.append('batch_size', batchSize);
            formData.append('offset', offset);
            
            fetch('<?= root ?>modules/stays/stuba/content/regions-by-countries', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to import regions');
                }
                
                totalRegionsImported += data.regions_imported;
                
                addStubaLog(`[BATCH] Processed ${data.countries_processed} countries, imported ${data.regions_imported} regions`, 'green');
                
                const progress = 10 + (20 * (data.progress_percent / 100));
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressPercentage').textContent = Math.round(progress) + '%';
                document.getElementById('currentOperation').textContent = 
                    `Step 2/4: ${data.progress_percent}% (${totalRegionsImported.toLocaleString()} regions)`;
                document.getElementById('processedRecords').textContent = `${data.countries_processed}/${data.total_countries} countries`;
                
                if (data.is_complete) {
                    resolve(totalRegionsImported);
                } else {
                    offset = data.next_offset;
                    setTimeout(processNextBatch, 500);
                }
            })
            .catch(error => {
                addStubaLog('[ERROR] Failed to import regions: ' + error.message, 'red');
                reject(error);
            });
        }
        
        processNextBatch();
    });
}

// NEW: Import hotels by REGIONS (not countries)
function importHotelsByRegionBatch() {
    return new Promise((resolve, reject) => {
        let offset = 0;
        let totalHotelsImported = 0;
        const batchSize = 10; // Process 10 regions at a time
        
        function processNextBatch() {
            if (importStopped) {
                resolve(totalHotelsImported);
                return;
            }
            
            const formData = new FormData();
            formData.append('batch_size', batchSize);
            formData.append('offset', offset);
            
            fetch('<?= root ?>modules/stays/stuba/content/import-hotels-batch', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to import hotels');
                }
                
                totalHotelsImported += data.hotels_imported;
                
                addStubaLog(`[BATCH] Processed ${data.regions_processed} regions, imported ${data.hotels_imported} hotels`, 'green');
                
                const progress = 30 + (40 * (data.progress_percent / 100));
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressPercentage').textContent = Math.round(progress) + '%';
                document.getElementById('currentOperation').textContent = 
                    `Step 3/4: ${data.progress_percent}% (${totalHotelsImported.toLocaleString()} hotels)`;
                document.getElementById('processedRecords').textContent = `${data.regions_processed}/${data.total_regions} regions`;
                
                if (data.is_complete) {
                    resolve(totalHotelsImported);
                } else {
                    offset = data.next_offset;
                    setTimeout(processNextBatch, 500);
                }
            })
            .catch(error => {
                addStubaLog('[ERROR] Failed to import hotels: ' + error.message, 'red');
                reject(error);
            });
        }
        
        processNextBatch();
    });
}

function enrichHotelsAfterImport() {
    let offset = 0;
    const batchSize = 100;
    let totalProcessed = 0;
    let totalImages = 0;
    let totalAmenities = 0;
    let grandTotal = 0;
    
    function processNextBatch() {
        // Check if import was stopped
        if (importStopped) {
            addStubaLog('[WARNING] Enrichment stopped by user', 'orange');
            resetImportButton();
            if (elapsedInterval) clearInterval(elapsedInterval);
            return;
        }
        
        const formData = new FormData();
        formData.append('batch_size', batchSize);
        formData.append('offset', offset);
        
        fetch('<?= root ?>modules/stays/stuba/content/enrich-hotels', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Enrichment failed');
            }
            
            if (data.total_hotels === 0) {
                addStubaLog('[ERROR] No hotels found in database', 'red');
                resetImportButton();
                return;
            }
            
            grandTotal = data.total_hotels;
            totalProcessed += data.processed;
            totalImages += data.images_added;
            totalAmenities += data.amenities_added;
            
            addStubaLog(`[BATCH] Enriched ${data.processed} hotels (+${data.images_added} images, +${data.amenities_added} amenities)`, 'green');
            
            const progress = 70 + (30 * (totalProcessed / grandTotal));
            document.getElementById('progressBar').style.width = progress + '%';
            document.getElementById('progressPercentage').textContent = Math.round(progress) + '%';
            document.getElementById('currentOperation').textContent = 
                `Step 4/4: ${totalProcessed.toLocaleString()}/${grandTotal.toLocaleString()} hotels enriched`;
            document.getElementById('processedRecords').textContent = `${totalProcessed.toLocaleString()} hotels enriched`;
            document.getElementById('currentStepInfo').textContent = 
                `Step 4/4: Enriched ${totalProcessed.toLocaleString()}/${grandTotal.toLocaleString()} hotels`;
            
            if (data.is_complete) {
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressPercentage').textContent = '100%';
                document.getElementById('currentOperation').textContent = 'Import Complete!';
                document.getElementById('processedRecords').textContent = grandTotal.toLocaleString();
                
                if (elapsedInterval) clearInterval(elapsedInterval);
                
                // **ADD THIS: Update import log completion using inline PHP**
                const completeData = new FormData();
                completeData.append('complete', '1');
                completeData.append('hotels_imported', grandTotal);
                completeData.append('images_imported', totalImages);
                
                fetch('<?= root ?>modules/stays/stuba/content_import', {
                    method: 'POST',
                    body: completeData
                }).catch(err => console.log('Log update failed:', err));
                
                addStubaLog('[SUCCESS] ========================================', 'green');
                addStubaLog(`[SUCCESS] IMPORT COMPLETE!`, 'green');
                addStubaLog(`[SUCCESS] Total Hotels: ${grandTotal.toLocaleString()}`, 'green');
                addStubaLog(`[SUCCESS] Total Regions: ${document.getElementById('totalRegions').textContent}`, 'green');
                addStubaLog(`[SUCCESS] Total Countries: ${document.getElementById('totalCountries').textContent}`, 'green');
                addStubaLog(`[SUCCESS] Total Images: ${totalImages.toLocaleString()}`, 'green');
                addStubaLog(`[SUCCESS] Total Amenities: ${totalAmenities.toLocaleString()}`, 'green');
                addStubaLog('[SUCCESS] ========================================', 'green');
                
                resetImportButton();
                loadStubaImportStats();
                
                if (typeof vt !== 'undefined') {
                    vt.success(`Import complete! ${grandTotal.toLocaleString()} hotels with ${totalImages.toLocaleString()} images`);
                }
            } else {
                offset = data.next_offset || (offset + batchSize);
                setTimeout(processNextBatch, 500);
            }
        })
        .catch(error => {
            addStubaLog('[ERROR] Enrichment failed: ' + error.message, 'red');
            resetImportButton();
            if (elapsedInterval) clearInterval(elapsedInterval);
        });
    }
    
    processNextBatch();
}

function stopImport() {
    importStopped = true;
    addStubaLog('[INFO] Stopping import... please wait', 'yellow');
    document.getElementById('currentStepInfo').textContent = 'Stopping import...';
}

function resetImportButton() {
    const btn = document.getElementById('startImportBtn');
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm">cloud_download</span> Import All Hotels from Stuba API';
    btn.onclick = startFullHotelImport;
    btn.classList.remove('bg-red-500', 'hover:bg-red-600');
    btn.classList.add('bg-gradient-to-r', 'from-green-500', 'to-green-600');
    
    const enrichBtn = document.getElementById('enrichHotelsBtn');
    enrichBtn.disabled = false;
    enrichBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    enrichBtn.title = '';
}

// Standalone enrich function
function enrichHotelsWithDetails() {
    const button = document.getElementById('enrichHotelsBtn');
    const progressSection = document.getElementById('progressSection');
    const terminalContent = document.getElementById('consoleOutput');
    
    // Reset stop flag
    importStopped = false;
    startTime = Date.now();
    
    // Show progress section
    progressSection.classList.remove('hidden');
    
    // Update buttons - change to Stop button
    button.disabled = false;
    button.innerHTML = '<span class="material-symbols-outlined text-sm">stop</span> Stop Enrichment';
    button.onclick = function() { stopImport(); };
    button.classList.remove('bg-blue-500', 'hover:bg-blue-600');
    button.classList.add('bg-red-500', 'hover:bg-red-600');
    
    document.getElementById('startImportBtn').disabled = true;
    
    // Clear console
    terminalContent.innerHTML = '';
    
    // Update status
    document.getElementById('importStatusBadge').textContent = 'Enriching';
    document.getElementById('importStatusBadge').className = 'text-xs text-purple-700 bg-purple-100 px-2 py-1 rounded-md';
    
    // Start elapsed time counter
    if (elapsedInterval) clearInterval(elapsedInterval);
    elapsedInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        document.getElementById('elapsedTime').textContent = elapsed + 's';
    }, 1000);
    
    addStubaLog('[INIT] Starting hotel enrichment process...', 'cyan');
    addStubaLog('[INFO] Fetching complete hotel details with images and amenities', 'cyan');
    
    let totalProcessed = 0;
    let totalImages = 0;
    let totalAmenities = 0;
    let currentOffset = 0;
    let isComplete = false;
    
    function processNextBatch() {
        // Check if enrichment was stopped
        if (importStopped) {
            addStubaLog('[WARNING] Enrichment stopped by user', 'orange');
            addStubaLog(`[INFO] Enriched ${totalProcessed} hotels with ${totalImages} images and ${totalAmenities} amenities`, 'blue');
            resetEnrichmentUI(button);
            if (elapsedInterval) clearInterval(elapsedInterval);
            return;
        }
        
        if (isComplete) {
            completeStubaEnrichment(totalProcessed, totalImages, totalAmenities);
            return;
        }
        
        const batchSize = 100;
        const formData = new FormData();
        formData.append('batch_size', batchSize);
        formData.append('offset', currentOffset);
        
        addStubaLog(`[BATCH] Processing hotels ${currentOffset + 1} to ${currentOffset + batchSize}...`, 'cyan');
        document.getElementById('currentOperation').textContent = `Processing hotels ${currentOffset + 1}-${currentOffset + batchSize}...`;
        document.getElementById('currentStepInfo').textContent = `Processing hotels ${currentOffset + 1}-${currentOffset + batchSize}...`;
        
        fetch('<?= root ?>modules/stays/stuba/content/enrich-hotels', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Check if no hotels found
                if (data.total_hotels === 0) {
                    addStubaLog('[ERROR] No hotels found in database!', 'red');
                    addStubaLog('[INFO] Please run "Import All Hotels" first', 'yellow');
                    resetEnrichmentUI(button);
                    if (elapsedInterval) clearInterval(elapsedInterval);
                    return;
                }
                
                totalProcessed += data.processed || 0;
                totalImages += data.images_added || 0;
                totalAmenities += data.amenities_added || 0;
                currentOffset = data.next_offset || 0;
                isComplete = data.is_complete;
                
                addStubaLog(`[SUCCESS] Processed ${data.processed || 0} hotels (+${data.images_added || 0} images, +${data.amenities_added || 0} amenities)`, 'green');
                
                const progressBar = document.getElementById('progressBar');
                const progressPercentage = document.getElementById('progressPercentage');
                const processedRecords = document.getElementById('processedRecords');
                
                if (progressBar) progressBar.style.width = data.progress_percent + '%';
                if (progressPercentage) progressPercentage.textContent = data.progress_percent + '%';
                if (processedRecords) processedRecords.textContent = totalProcessed.toLocaleString();
                
                document.getElementById('currentStepInfo').textContent = 
                    `Enriched ${totalProcessed.toLocaleString()}/${data.total_hotels.toLocaleString()} hotels`;
                
                if (!isComplete) {
                    setTimeout(processNextBatch, 500);
                } else {
                    completeStubaEnrichment(totalProcessed, totalImages, totalAmenities);
                }
            } else {
                addStubaLog('[ERROR] Enrichment failed: ' + data.message, 'red');
                resetEnrichmentUI(button);
                if (elapsedInterval) clearInterval(elapsedInterval);
            }
        })
        .catch(error => {
            addStubaLog('[ERROR] ' + error.message, 'red');
            resetEnrichmentUI(button);
            if (elapsedInterval) clearInterval(elapsedInterval);
        });
    }
    
    // Start processing
    processNextBatch();
}

function completeStubaEnrichment(totalProcessed, totalImages, totalAmenities) {
    addStubaLog('', 'white');
    addStubaLog('[COMPLETE] Hotel enrichment completed!', 'green');
    addStubaLog(`[STATS] Total hotels enriched: ${totalProcessed.toLocaleString()}`, 'green');
    addStubaLog(`[STATS] Total images imported: ${totalImages.toLocaleString()}`, 'green');
    addStubaLog(`[STATS] Total amenities imported: ${totalAmenities.toLocaleString()}`, 'green');
    addStubaLog('[INFO] All hotels now have complete details, images, and amenities', 'cyan');
    
    document.getElementById('importStatusBadge').textContent = 'Enriched';
    document.getElementById('importStatusBadge').className = 'text-xs text-green-700 bg-green-100 px-2 py-1 rounded-md';
    document.getElementById('currentOperation').textContent = 'Enrichment completed!';
    document.getElementById('currentStepInfo').textContent = `Enrichment complete: ${totalProcessed.toLocaleString()} hotels enriched`;
    
    if (elapsedInterval) clearInterval(elapsedInterval);
    
    const button = document.getElementById('enrichHotelsBtn');
    button.disabled = false;
    button.innerHTML = '<span class="material-symbols-outlined text-sm">auto_awesome</span> Enrich Hotels (Images & Details)';
    button.onclick = function() { enrichHotelsWithDetails(); return false; };
    button.classList.remove('bg-red-500', 'hover:bg-red-600');
    button.classList.add('bg-blue-500', 'hover:bg-blue-600');
    document.getElementById('startImportBtn').disabled = false;
    
    if (typeof vt !== 'undefined') {
        vt.success(`Enriched ${totalProcessed.toLocaleString()} hotels with ${totalImages.toLocaleString()} images!`);
    }
    
    // Reload stats
    loadStubaImportStats();
}

function resetEnrichmentUI(button) {
    button.disabled = false;
    button.innerHTML = '<span class="material-symbols-outlined text-sm">auto_awesome</span> Enrich Hotels (Images & Details)';
    button.onclick = function() { enrichHotelsWithDetails(); return false; };
    button.classList.remove('bg-red-500', 'hover:bg-red-600');
    button.classList.add('bg-blue-500', 'hover:bg-blue-600');
    document.getElementById('startImportBtn').disabled = false;
    document.getElementById('importStatusBadge').textContent = 'Ready';
    document.getElementById('importStatusBadge').className = 'text-xs text-gray-700 bg-gray-100 px-2 py-1 rounded-md';
}
</script>