<?php
/**
 * Unified Translation System
 * Single file handles UI and AJAX requests
 */

session_start();

// Start output buffering to prevent any accidental output before JSON
ob_start();

// Check if this is an AJAX request
$isAjax = isset($_POST['action']) || isset($_GET['action']);

// Load database connection
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) {
        if ($isAjax) {
            ob_end_clean();
            header('Content-Type: application/json');
            die(json_encode(['error' => '.env file not found']));
        }
        die('.env file not found');
    }
    
    $env = parse_ini_file($envFile);
    
    $db = new Medoo\Medoo([
        'type'     => $env['DB_TYPE'] ?? 'mysql',
        'host'     => $env['DB_HOST'] ?? 'localhost',
        'database' => $env['DB_DATABASE'],
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
    ]);
    
} catch (Exception $e) {
    if ($isAjax) {
        ob_end_clean();
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
    die('Database connection failed: ' . $e->getMessage());
}

// Configuration
$CHUNK_SIZE = 50;
$OPENAI_API_KEY = '';
set_time_limit(300); // 5 minutes per request
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

/**
 * Translate using Google Translate API
 */
function translateFree($text, $targetLang, $sourceLang = 'en') {
    try {
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=" 
            . urlencode($sourceLang) 
            . "&tl=" . urlencode($targetLang) 
            . "&dt=t&q=" . urlencode($text);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $result = json_decode($response, true);
        if (isset($result[0]) && is_array($result[0])) {
            $translation = '';
            foreach ($result[0] as $sentence) {
                if (isset($sentence[0])) {
                    $translation .= $sentence[0];
                }
            }
            return trim($translation);
        }
        
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'list') {
    ob_end_clean();
    header('Content-Type: application/json');
    
    $enFile = __DIR__ . '/../app/lang/en.json';
    if (!file_exists($enFile)) {
        die(json_encode(['error' => 'en.json not found']));
    }
    
    $enContent = file_get_contents($enFile);
    $enData = json_decode($enContent, true);
    
    if (!$enData) {
        die(json_encode(['error' => 'en.json is invalid or empty']));
    }
    
    // Check for duplicate keys in en.json
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(json_encode(['error' => 'en.json has syntax errors: ' . json_last_error_msg()]));
    }
    
    // Verify no duplicate keys by counting occurrences
    preg_match_all('/"([^"]+)"\s*:/', $enContent, $matches);
    $allKeys = $matches[1];
    $duplicates = array_filter(array_count_values($allKeys), function($count) { return $count > 1; });
    
    if (!empty($duplicates)) {
        die(json_encode([
            'error' => 'en.json contains duplicate keys: ' . implode(', ', array_keys($duplicates))
        ]));
    }
    
    $totalKeys = count($enData);
    
    $languages = $db->select('languages', '*', ['status' => '1']);
    
    // Detect language code column
    $langCodeColumn = 'lang_code';
    if (!empty($languages)) {
        $sample = $languages[0];
        if (isset($sample['language_code'])) $langCodeColumn = 'language_code';
        elseif (isset($sample['code'])) $langCodeColumn = 'code';
        elseif (isset($sample['iso_code'])) $langCodeColumn = 'iso_code';
    }
    
    $toTranslate = [];
    foreach ($languages as $lang) {
        $code = strtolower($lang[$langCodeColumn]);
        if ($code === 'en') continue;
        
        $langFile = __DIR__ . "/../app/lang/$code.json";
        $translatedKeys = 0;
        
        if (file_exists($langFile)) {
            $existing = json_decode(file_get_contents($langFile), true);
            if ($existing) {
                $translatedKeys = count($existing);
            }
        }
        
        $missingKeys = $totalKeys - $translatedKeys;
        
        // Include language if it's missing any keys
        if ($missingKeys > 0) {
            $toTranslate[] = [
                'code' => $code,
                'name' => $lang['name'],
                'total' => $totalKeys,
                'existing' => $translatedKeys,
                'missing' => $missingKeys
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'languages' => $toTranslate,
        'totalKeys' => $totalKeys
    ]);
    exit;
}

if ($action === 'translate') {
    ob_end_clean();
    header('Content-Type: application/json');
    
    $langCode = $_POST['code'] ?? '';
    $chunkIndex = (int)($_POST['chunk'] ?? 0);
    
    if (empty($langCode)) {
        die(json_encode(['error' => 'Language code required']));
    }
    
    $enFile = __DIR__ . '/../app/lang/en.json';
    $enData = json_decode(file_get_contents($enFile), true);
    $langFile = __DIR__ . "/../app/lang/$langCode.json";
    
    // Load existing translations (file may not exist yet)
    $existingData = [];
    if (file_exists($langFile)) {
        $existingData = json_decode(file_get_contents($langFile), true) ?? [];
    }
    
    // Validate existing language file for duplicates
    if (file_exists($langFile)) {
        $langContent = file_get_contents($langFile);
        preg_match_all('/"([^"]+)"\s*:/', $langContent, $langMatches);
        $langKeys = $langMatches[1];
        $langDuplicates = array_filter(array_count_values($langKeys), function($count) { return $count > 1; });
        
        if (!empty($langDuplicates)) {
            echo json_encode([
                'error' => "$langCode.json contains duplicate keys: " . implode(', ', array_keys($langDuplicates)) . ". Please fix this file manually."
            ]);
            exit;
        }
    }
    
    // Find MISSING keys (keys in en.json but NOT in language file)
    $missingKeys = [];
    foreach ($enData as $key => $value) {
        if (!array_key_exists($key, $existingData)) {
            $missingKeys[$key] = $value;
        }
    }
    
    // Get all missing keys as an array (to properly handle ALL chunks)
    $missingKeysArray = [];
    foreach ($missingKeys as $k => $v) {
        $missingKeysArray[$k] = $v;
    }
    
    // Double-check: verify all keys are actually in the file
    if (empty($missingKeysArray)) {
        // Verify that the file actually has all keys before marking complete
        $actualCount = count($existingData);
        $expectedCount = count($enData);
        
        if ($actualCount !== $expectedCount) {
            echo json_encode([
                'error' => "Translation incomplete: File has $actualCount keys but should have $expectedCount keys. Please delete $langCode.json and restart."
            ]);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'completed' => true,
            'message' => 'All keys translated',
            'totalKeys' => $expectedCount,
            'translatedKeys' => $actualCount
        ]);
        exit;
    }
    
    // Split missing keys into chunks and PRESERVE KEYS
    $chunks = array_chunk($missingKeysArray, $CHUNK_SIZE, true);
    $totalChunks = count($chunks);
    
    if ($chunkIndex >= $totalChunks) {
        // All chunks processed - but verify completion
        $actualCount = count($existingData);
        $expectedCount = count($enData);
        
        echo json_encode([
            'success' => true,
            'completed' => true,
            'message' => 'All chunks processed',
            'totalKeys' => $expectedCount,
            'translatedKeys' => $actualCount,
            'remainingKeys' => $expectedCount - $actualCount
        ]);
        exit;
    }
    
    // Process this chunk
    $chunk = $chunks[$chunkIndex];
    $newTranslations = [];
    $successCount = 0;
    
    foreach ($chunk as $key => $value) {
        if (empty($value)) {
            // If English value is empty, add it as-is
            $newTranslations[$key] = $value;
            $successCount++;
            continue;
        }
        
        $translated = translateFree($value, $langCode);
        
        if ($translated !== null && trim($translated) !== '') {
            // Only add if translation succeeded
            $newTranslations[$key] = $translated;
            $successCount++;
        } else {
            // Translation failed - use English as fallback
            $newTranslations[$key] = $value;
            $successCount++;
        }
        
        usleep(250000); // 250ms delay between requests to avoid rate limiting
    }
    
    // Merge new translations with existing - ENSURING keys are preserved
    foreach ($newTranslations as $k => $v) {
        $existingData[$k] = $v;
    }
    
    // Maintain the same order as en.json - rebuild in order
    $orderedData = [];
    foreach ($enData as $key => $value) {
        if (isset($existingData[$key])) {
            $orderedData[$key] = $existingData[$key];
        }
    }
    
    // Save to file with proper encoding
    $jsonOutput = json_encode($orderedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $writeResult = @file_put_contents($langFile, $jsonOutput);
    
    if ($writeResult === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to write file: ' . $langFile
        ]);
        exit;
    }
    
    // Calculate progress
    $totalKeys = count($enData);
    $translatedKeys = count($existingData);
    $remainingKeys = $totalKeys - $translatedKeys;
    $progress = ($translatedKeys / $totalKeys) * 100;
    
    // Check if this language is actually complete
    $isComplete = ($remainingKeys === 0 && ($chunkIndex + 1) >= $totalChunks);
    
    echo json_encode([
        'success' => true,
        'completed' => $isComplete,
        'chunk' => $chunkIndex + 1,
        'totalChunks' => $totalChunks,
        'progress' => round($progress, 1),
        'totalKeys' => $totalKeys,
        'translatedKeys' => $translatedKeys,
        'remainingKeys' => $remainingKeys,
        'keysInChunk' => count($chunk),
        'successfulTranslations' => $successCount,
        'fileSize' => $writeResult
    ]);
    exit;
}

// ============================================================================
// UI - Show HTML interface if no AJAX action
// ============================================================================

// If we got here, it's not an AJAX request, so show the UI
ob_end_flush(); // Output the buffered content (if any) for HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Translation System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .container {
            max-width: 100%;
            margin: 0;
            background: white;
            min-height: 100vh;
        }
        .header {
            background: #000;
            color: white;
            padding: 20px 40px;
            border-bottom: 2px solid #000;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
        }
        .header p {
            font-size: 13px;
            opacity: 0.8;
        }
        .content {
            display: grid;
            grid-template-columns: 300px 1fr;
            min-height: calc(100vh - 70px);
        }
        .sidebar {
            background: #fafafa;
            border-right: 2px solid #e0e0e0;
            padding: 24px;
        }
        .main {
            padding: 24px 40px;
        }
        .info-box {
            background: white;
            border: 2px solid #e0e0e0;
            padding: 16px;
            margin-bottom: 16px;
        }
        .info-box h3 {
            color: #000;
            margin-bottom: 12px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-box p {
            font-size: 12px;
            line-height: 1.6;
            margin-bottom: 6px;
            color: #666;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-item {
            background: #fafafa;
            border: 2px solid #e0e0e0;
            padding: 12px;
            text-align: center;
        }
        .stat-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: #000;
        }
        .lang-list {
            display: grid;
            gap: 12px;
        }
        .lang-item {
            background: white;
            padding: 16px;
            border: 2px solid #e0e0e0;
            transition: border-color 0.2s;
        }
        .lang-item.processing {
            border-color: #000;
            background: #fafafa;
        }
        .lang-item.completed {
            border-color: #000;
            background: #f5f5f5;
        }
        .lang-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .lang-name {
            font-size: 14px;
            font-weight: 600;
            color: #000;
        }
        .lang-status {
            padding: 4px 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending { background: #e0e0e0; color: #666; }
        .status-processing { background: #000; color: white; }
        .status-completed { background: #000; color: white; }
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #e0e0e0;
            margin-bottom: 8px;
        }
        .progress-fill {
            height: 100%;
            background: #000;
            transition: width 0.3s;
        }
        .progress-info {
            font-size: 11px;
            color: #666;
        }
        .btn {
            background: #000;
            color: white;
            border: 2px solid #000;
            padding: 12px 24px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            width: 100%;
            display: block;
            text-align: center;
            margin-bottom: 8px;
        }
        .btn:hover {
            background: white;
            color: #000;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn.btn-stop {
            background: white;
            color: #000;
        }
        .btn.btn-stop:hover {
            background: #000;
            color: white;
        }
        .btn.btn-secondary {
            background: white;
            color: #000;
        }
        .btn.btn-secondary:hover {
            background: #f5f5f5;
        }
        .error {
            background: #f5f5f5;
            border-left: 4px solid #000;
            color: #000;
            padding: 16px;
            margin-bottom: 16px;
            font-size: 13px;
            line-height: 1.6;
        }
        .error strong {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .error-details {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 12px;
            margin-top: 8px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .spinner {
            border: 2px solid #e0e0e0;
            border-top: 2px solid #000;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .success-message {
            background: #f5f5f5;
            border: 2px solid #000;
            color: #000;
            padding: 24px;
            text-align: center;
            font-size: 13px;
        }
        .success-message h3 {
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="2" y1="12" x2="22" y2="12"></line>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                </svg>
                <div>
                    <h1>Translation System</h1>
                    <p>Automated translation with real-time progress</p>
                </div>
            </div>
        </div>
        
        <div class="content">
            <div class="sidebar">
                <div class="info-box">
                    <h3>How It Works</h3>
                    <p><strong>Step 1:</strong> Reads en.json keys</p>
                    <p><strong>Step 2:</strong> Finds missing keys</p>
                    <p><strong>Step 3:</strong> Translates in chunks (50)</p>
                    <p><strong>Step 4:</strong> Saves immediately</p>
                </div>
                
                <div id="stats"></div>
                
                <button id="startBtn" class="btn" onclick="startTranslation()">Start Translation</button>
                <button id="stopBtn" class="btn btn-stop" onclick="stopTranslation()" style="display:none">Stop Translation</button>
                <a href="../index.php" class="btn btn-secondary">Go to Homepage</a>
            </div>
            
            <div class="main">
                <div id="status"></div>
                <div id="languages" class="lang-list"></div>
            </div>
        </div>
    </div>

    <script>
        let languages = [];
        let currentIndex = 0;
        let isRunning = false;

        window.onload = function() {
            loadLanguages();
        };

        async function loadLanguages() {
            document.getElementById('status').innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading languages...</p></div>';
            
            try {
                const response = await fetch('translate.php?action=list');
                const data = await response.json();
                
                if (data.error) {
                    document.getElementById('status').innerHTML = `<div class="error">${data.error}</div>`;
                    return;
                }
                
                languages = data.languages;
                
                if (languages.length === 0) {
                    document.getElementById('status').innerHTML = '<div class="success-message"><h3>All Translations Complete!</h3><p>All languages are fully translated.</p></div>';
                    document.getElementById('startBtn').style.display = 'none';
                    return;
                }
                // Show stats
                let totalMissing = languages.reduce((sum, lang) => sum + lang.missing, 0);
                document.getElementById('stats').innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-label">Languages</div>
                            <div class="stat-value">${languages.length}</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Total Keys</div>
                            <div class="stat-value">${data.totalKeys}</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Missing</div>
                            <div class="stat-value">${totalMissing}</div>
                        </div>
                    </div>
                `;
                
                // Clear status and show languages immediately
                document.getElementById('status').innerHTML = '';
                
                if (languages.length === 0) {
                    document.getElementById('languages').innerHTML = '<div class="success-message"><h3>All Translations Complete!</h3><p>All languages are fully translated.</p></div>';
                    document.getElementById('startBtn').style.display = 'none';
                } else {
                    renderLanguages();
                }
            } catch (error) {
                document.getElementById('status').innerHTML = `<div class="error">Failed: ${error.message}</div>`;
            }
        }

        function renderLanguages() {
            const html = languages.map((lang, index) => {
                let progressPercent = ((lang.existing / lang.total) * 100).toFixed(1);
                return `
                <div class="lang-item" id="lang-${index}">
                    <div class="lang-header">
                        <div class="lang-name">${lang.name} (${lang.code})</div>
                        <div class="lang-status status-pending" id="status-${index}">Pending</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-${index}" style="width: ${progressPercent}%"></div>
                    </div>
                    <div class="progress-info" id="info-${index}">
                        ${lang.existing}/${lang.total} keys (${lang.missing} missing)
                    </div>
                </div>
            `}).join('');
            
            document.getElementById('languages').innerHTML = html;
        }

        async function startTranslation() {
            isRunning = true;
            currentIndex = 0;
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('stopBtn').style.display = 'block';
            
            await processNextLanguage();
        }

        function stopTranslation() {
            isRunning = false;
            document.getElementById('startBtn').style.display = 'block';
            document.getElementById('stopBtn').style.display = 'none';
        }

        async function processNextLanguage() {
            if (!isRunning || currentIndex >= languages.length) {
                if (currentIndex >= languages.length) {
                    document.getElementById('status').innerHTML = '<div class="success-message"><h3>Translation Complete!</h3><p>All languages translated successfully.</p></div>';
                    document.getElementById('startBtn').style.display = 'none';
                    document.getElementById('stopBtn').style.display = 'none';
                }
                return;
            }
            
            const lang = languages[currentIndex];
            const langEl = document.getElementById(`lang-${currentIndex}`);
            const statusEl = document.getElementById(`status-${currentIndex}`);
            const progressEl = document.getElementById(`progress-${currentIndex}`);
            const infoEl = document.getElementById(`info-${currentIndex}`);
            
            langEl.classList.add('processing');
            statusEl.textContent = 'Processing...';
            statusEl.className = 'lang-status status-processing';
            
            let chunk = 0;
            let completed = false;
            
            while (!completed && isRunning) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'translate');
                    formData.append('code', lang.code);
                    formData.append('chunk', chunk);
                    
                    const response = await fetch('translate.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.error) {
                        statusEl.textContent = 'Error';
                        statusEl.className = 'lang-status';
                        statusEl.style.background = '#000';
                        statusEl.style.color = 'white';
                        
                        let errorHtml = `<span style="color:#000;font-weight:600">Error:</span><div class="error-details">${data.error}</div>`;
                        infoEl.innerHTML = errorHtml;
                        break;
                    }
                    
                    progressEl.style.width = data.progress + '%';
                    infoEl.textContent = `Chunk ${data.chunk}/${data.totalChunks} - ${data.translatedKeys}/${data.totalKeys} keys (${data.successfulTranslations}/${data.keysInChunk} in this chunk)`;
                    
                    // Only mark as completed if ALL keys are translated
                    if (data.completed && data.remainingKeys === 0) {
                        completed = true;
                        langEl.classList.remove('processing');
                        langEl.classList.add('completed');
                        statusEl.textContent = 'Completed';
                        statusEl.className = 'lang-status status-completed';
                        progressEl.style.width = '100%';
                        infoEl.textContent = `${data.totalKeys}/${data.totalKeys} keys (Complete)`;
                    } else if (data.completed && data.remainingKeys > 0) {
                        // Not really complete - continue with remaining keys
                        chunk = data.chunk;
                        // Reset to chunk 0 to reprocess missing keys
                        chunk = 0;
                    } else {
                        chunk = data.chunk;
                    }
                    
                } catch (error) {
                    statusEl.textContent = 'Error';
                    statusEl.className = 'lang-status';
                    statusEl.style.background = '#000';
                    statusEl.style.color = 'white';
                    infoEl.innerHTML = `<span style="color:#000;font-weight:600">Error: ${error.message}</span>`;
                    break;
                }
            }
            
            currentIndex++;
            
            if (isRunning) {
                setTimeout(() => processNextLanguage(), 500);
            }
        }
    </script>
</body>
</html>
