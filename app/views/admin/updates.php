<?php
/**
 * Updates Management View
 * Displays available GitHub updates and installation interface
 */

// ==================================================
// LOAD CONFIGURATION
// ==================================================

// Load updates configuration from same directory
$configPath = __DIR__ . '/updates-config.php';
if (!file_exists($configPath)) {
    echo '<div class="p-4 bg-red-100 text-red-700 rounded">Error: updates-config.php not found in ' . __DIR__ . '</div>';
    return;
}

$config = @require $configPath;

// Extract configuration
$githubRepo = $config['github_repo'] ?? '';
$githubToken = $config['github_token'] ?? '';
$currentVersion = $config['current_version'] ?? '10.0';
$defaultBranch = $config['default_branch'] ?? 'main';

// ==================================================
// LOAD UPDATES HISTORY
// ==================================================

$updatesJsonPath = __DIR__ . '/../../updates.json';
$updatesData = [
    'meta' => [
        'version' => $currentVersion,
        'last_check' => null,
        'total_updates' => 0
    ],
    'updates' => []
];

// Create updates.json if it doesn't exist
if (!file_exists($updatesJsonPath)) {
    file_put_contents($updatesJsonPath, json_encode($updatesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    $jsonContent = file_get_contents($updatesJsonPath);

    if ($jsonContent !== false) {
        $decoded = json_decode($jsonContent, true);

        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            $updatesData = $decoded;
        } else {
            // JSON is corrupted, backup and create new
            rename($updatesJsonPath, $updatesJsonPath . '.backup.' . time());
            file_put_contents($updatesJsonPath, json_encode($updatesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

// Get list of installed update UIDs for quick lookup
$installedUpdates = [];
foreach ($updatesData['updates'] as $update) {
    if ($update['status'] === 'installed') {
        $installedUpdates[] = $update['uid'];
    }
}

// ==================================================
// FETCH GITHUB COMMITS
// ==================================================

// Get filter settings from config
$updatesStartDate = $config['updates_start_date'] ?? null;
$sequentialMode = $config['sequential_installation'] ?? true;

// Pagination settings - fetch more to ensure we have enough after filtering
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 100; // Fetch more to filter by date

// Fetch recent commits from GitHub
$commits = [];
$error = null;

// ALWAYS fetch fresh data from GitHub API (no caching)
try {
    $apiUrl = "https://api.github.com/repos/{$githubRepo}/commits?per_page={$perPage}&page={$page}&t=" . time();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-GitHub-Updates');
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Get headers for pagination info
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        'Cache-Control: no-cache, no-store, must-revalidate',
        'Pragma: no-cache',
        $githubToken ? "Authorization: token {$githubToken}" : ''
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($httpCode === 200) {
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $allCommits = json_decode($body, true);

        // Filter commits by start date if specified
        if ($updatesStartDate) {
            $startTimestamp = strtotime($updatesStartDate);
            $allCommits = array_filter($allCommits, function($commit) use ($startTimestamp) {
                $commitDate = strtotime($commit['commit']['author']['date']);
                return $commitDate >= $startTimestamp;
            });
        }

        // Sort commits by date ASC (oldest first)
        usort($allCommits, function($a, $b) {
            $dateA = strtotime($a['commit']['author']['date']);
            $dateB = strtotime($b['commit']['author']['date']);
            return $dateA - $dateB; // ASC order
        });

        $commits = $allCommits;

        // Parse Link header for pagination
        $hasNextPage = false;
        $hasPrevPage = $page > 1;

        if (preg_match('/<([^>]+)>;\s*rel="next"/', $header, $matches)) {
            $hasNextPage = true;
        }

        // Update last check time in updates.json
        $updatesData['meta']['last_check'] = date('Y-m-d H:i:s');
        file_put_contents($updatesJsonPath, json_encode($updatesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $error = "Failed to fetch updates from GitHub (HTTP {$httpCode})";
    }
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle mark as installed
if (isset($_POST['mark_installed']) && isset($_POST['commit_sha'])) {
    $commitSha = $_POST['commit_sha'];
    if (!in_array($commitSha, $installedUpdates)) {
        $installedUpdates[] = $commitSha;
        $updatesConfig['installed_updates'] = $installedUpdates;
        file_put_contents($updatesJsonPath, json_encode($updatesConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Handle mark as uninstalled
if (isset($_POST['mark_uninstalled']) && isset($_POST['commit_sha'])) {
    $commitSha = $_POST['commit_sha'];
    $installedUpdates = array_diff($installedUpdates, [$commitSha]);
    $updatesConfig['installed_updates'] = array_values($installedUpdates);
    file_put_contents($updatesJsonPath, json_encode($updatesConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
?>

<div class="container my-4">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800"><?= T::system_updates ?></h1>
            <p class="text-sm text-slate-600 mt-1"><?= T::check_and_manage_updates ?></p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?refresh=1" class="btn secondary">
                <span class="material-symbols-outlined text-lg">refresh</span>
                <?= T::refresh ?>
            </a>
            <a href="?check_updates=1" class="btn">
                <span class="material-symbols-outlined text-lg">cloud_download</span>
                <?= T::check_for_updates ?>
            </a>
        </div>
    </div>

    <!-- Current Version Card -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-600 text-2xl">deployed_code</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium"><?= T::current_version ?></div>
                    <div class="text-lg font-bold text-slate-800"><?= $currentVersion ?></div>
                </div>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-600 text-2xl">check_circle</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium"><?= T::installed_updates ?></div>
                    <div class="text-lg font-bold text-slate-800"><?= count($installedUpdates) ?></div>
                </div>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-purple-600 text-2xl">schedule</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium"><?= T::last_checked ?></div>
                    <div class="text-sm font-medium text-slate-800">
                        <?= $updatesData['meta']['last_check'] ? date('M d, Y H:i', strtotime($updatesData['meta']['last_check'])) : T::never ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Updates Server Info -->
    <div class="card mb-6 p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-white text-xl">cloud_sync</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium"><?= T::updates_server ?></div>
                    <div class="text-sm font-medium text-slate-800"><?= T::connected ?>
                        <!-- • <?= $githubRepo ?> -->
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="px-2 py-1 bg-green-100 text-green-700 rounded font-medium">
                    <span class="material-symbols-outlined text-sm align-middle">check_circle</span>
                    <?= T::online ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
    <!-- Error Alert -->
    <div class="alert alert-error mb-6">
        <span class="material-symbols-outlined">error</span>
        <span><?= $error ?></span>
    </div>
    <?php endif; ?>

    <!-- Sequential Mode Info -->
    <?php if ($sequentialMode): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
            <div>
                <h3 class="font-semibold text-blue-900 mb-1"><?= T::sequential_installation_mode_active ?></h3>
                <p class="text-sm text-blue-700">
                    <?= T::updates_must_be_installed_in_order ?>
                </p>
                <?php if ($updatesStartDate): ?>
                <p class="text-xs text-blue-600 mt-2">
                    <span class="material-symbols-outlined text-sm align-middle">calendar_today</span>
                    <?= T::showing_updates_from ?>: <strong><?= date('M d, Y', strtotime($updatesStartDate)) ?></strong> <?= T::onwards ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Updates List -->
    <div class="card p-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-slate-800"><?= T::available_updates ?></h2>
            <?php if (!empty($commits)): ?>
            <span class="text-sm text-slate-500"><?= count($commits) ?> <?= T::updates_found ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($commits)): ?>
        <!-- Empty State -->
        <div class="text-center py-12">
            <span class="material-symbols-outlined text-6xl text-slate-300 mb-3">cloud_off</span>
            <p class="text-slate-500 text-sm"><?= T::no_updates_loaded_yet ?></p>
            <p class="text-slate-400 text-xs mt-1"><?= T::click_check_for_updates ?></p>
            <a href="?check_updates=1" class="btn mt-4">
                <span class="material-symbols-outlined text-lg">cloud_download</span>
                <?= T::check_for_updates_now ?>
            </a>
        </div>
        <?php else: ?>
        <!-- Commits Timeline -->
        <div class="space-y-3">
            <?php
            // Find the first uninstalled commit (oldest in sequential mode)
            $firstUninstalledSha = null;
            if ($sequentialMode) {
                foreach ($commits as $commit) {
                    if (!in_array($commit['sha'], $installedUpdates)) {
                        $firstUninstalledSha = $commit['sha'];
                        break; // Found the oldest uninstalled
                    }
                }
            }

            foreach ($commits as $index => $commit):
                $sha = $commit['sha'];
                $shortSha = substr($sha, 0, 7);
                $message = $commit['commit']['message'] ?? 'No message';
                $author = $commit['commit']['author']['name'] ?? 'Unknown';
                $date = $commit['commit']['author']['date'] ?? '';
                $isInstalled = in_array($sha, $installedUpdates);
                $commitUrl = $commit['html_url'] ?? '';

                // In sequential mode, only allow installing the first uninstalled commit
                $canInstall = !$isInstalled && (!$sequentialMode || $sha === $firstUninstalledSha);
            ?>
            <div class="flex gap-4 p-4 border border-slate-200 rounded-lg hover:border-blue-300 hover:bg-blue-50/50 transition-all <?= $isInstalled ? 'bg-green-50 border-green-200' : '' ?> <?= !$canInstall && !$isInstalled ? 'opacity-60' : '' ?>">
                <!-- Icon -->
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $isInstalled ? 'bg-green-500' : ($canInstall ? 'bg-blue-500' : 'bg-gray-400') ?>">
                        <span class="material-symbols-outlined text-white text-xl">
                            <?= $isInstalled ? 'check_circle' : ($canInstall ? 'commit' : 'lock') ?>
                        </span>
                    </div>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($message) ?></h3>
                                <?php if ($canInstall && !$isInstalled && $sequentialMode): ?>
                                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">NEXT</span>
                                <?php endif; ?>
                                <?php if (!$canInstall && !$isInstalled): ?>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-semibold rounded-full">LOCKED</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">person</span>
                                    <?= htmlspecialchars($author) ?>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">schedule</span>
                                    <?= date('M d, Y H:i', strtotime($date)) ?>
                                </span>
                                <span class="flex items-center gap-1 font-mono bg-slate-100 px-2 py-0.5 rounded">
                                    <span class="material-symbols-outlined text-sm">tag</span>
                                    <?= $shortSha ?>
                                </span>
                                <?php if ($index === 0): ?>
                                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs font-semibold rounded">OLDEST</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2">
                            <!-- Update Details Button -->
                            <button onclick="showUpdateDetails(this)"
                                    data-sha="<?= htmlspecialchars($sha) ?>"
                                    data-message="<?= htmlspecialchars($message) ?>"
                                    data-author="<?= htmlspecialchars($author) ?>"
                                    data-date="<?= htmlspecialchars($date) ?>"
                                    class="btn secondary text-xs border border-slate-300 hover:border-blue-500 hover:text-blue-600 w-36">
                                <span class="material-symbols-outlined text-sm">info</span>
                                <?= T::update_details ?>
                            </button>

                            <!-- Install Button -->
                            <?php if ($isInstalled): ?>
                            <button disabled class="btn text-xs bg-green-600 text-white cursor-default w-32">
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                <?= T::installed ?>
                            </button>
                            <?php elseif ($canInstall): ?>
                            <button onclick="confirmInstallUpdate(this)"
                                    data-sha="<?= htmlspecialchars($sha) ?>"
                                    data-message="<?= htmlspecialchars($message) ?>"
                                    class="btn text-xs bg-blue-600 text-white hover:bg-blue-700 w-32"
                                    id="install-btn-<?= $sha ?>">
                                <span class="material-symbols-outlined text-sm">download</span>
                                <?= T::install_update ?>
                            </button>
                            <?php else: ?>
                            <button disabled
                                    class="btn text-xs bg-gray-300 text-gray-600 cursor-not-allowed w-32"
                                    title="<?= T::install_previous_updates_first ?>">
                                <span class="material-symbols-outlined text-sm">lock</span>
                                <?= T::locked ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Summary -->
        <?php if (!empty($commits)): ?>
        <div class="mt-6 pt-4 border-t border-slate-200">
            <div class="flex items-center justify-between text-sm text-slate-600">
                <div>
                    Showing <span class="font-semibold text-slate-800"><?= count($commits) ?></span> commits
                    <?php if ($updatesStartDate): ?>
                    from <span class="font-semibold text-slate-800"><?= date('M d, Y', strtotime($updatesStartDate)) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="font-semibold text-green-600"><?= count($installedUpdates) ?></span> installed,
                    <span class="font-semibold text-blue-600"><?= count(array_filter($commits, fn($c) => !in_array($c['sha'], $installedUpdates))) ?></span> pending
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Update Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full my-8">
        <!-- Modal Header -->
        <div class="border-b border-slate-200 p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-slate-800"><?= T::update_details ?></h2>
                        <p class="text-sm text-slate-600" id="detailsCommitMessage"></p>
                    </div>
                </div>
                <button onclick="closeDetailsModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <span class="material-symbols-outlined text-2xl">close</span>
                </button>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6 max-h-[70vh] overflow-y-auto">
            <!-- Update Info -->
            <div class="grid grid-cols-2 gap-4 mb-6 p-4 bg-slate-50 rounded-lg">
                <div hidden>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?= T::commit_sha ?></div>
                    <div class="font-mono text-sm text-slate-800" id="detailsSha"></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?= T::author ?></div>
                    <div class="text-sm text-slate-800" id="detailsAuthor"></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?= T::date ?></div>
                    <div class="text-sm text-slate-800" id="detailsDate"></div>
                </div>
                <div hidden>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?= T::status ?></div>
                    <div class="text-sm" id="detailsStatus"></div>
                </div>
            </div>

            <!-- Files Section -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-600 text-xl">description</span>
                        <h3 class="font-semibold text-blue-800"><?= T::files_changed ?></h3>
                    </div>
                    <span class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded font-medium" id="detailsFilesCount"><?= T::loading ?>...</span>
                </div>
                <div id="detailsFilesList" class="space-y-2 max-h-96 overflow-y-auto">
                    <div class="text-sm text-blue-600 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                        <?= T::loading_file_information ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-slate-200 p-6 flex items-center justify-end gap-3">
            <button onclick="closeDetailsModal()" class="btn secondary">
                <?= T::close ?>
            </button>
        </div>
    </div>
</div>

<!-- Installation Modal -->
<div id="installModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full my-8">
        <!-- Modal Header -->
        <div class="border-b border-slate-200 p-6">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-yellow-600 text-2xl">warning</span>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-800">Install Update</h2>
                    <p class="text-sm text-slate-600" id="updateCommitMessage"></p>
                </div>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <!-- Warning Section -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex gap-3">
                    <span class="material-symbols-outlined text-red-600 text-xl flex-shrink-0">error</span>
                    <div>
                        <h3 class="font-semibold text-red-800 mb-2">⚠️ Important: Backup Your System</h3>
                        <ul class="text-sm text-red-700 space-y-1">
                            <li>• Make sure to backup all your files before installing updates</li>
                            <li>• Database backup is highly recommended</li>
                            <li>• This action will replace existing files</li>
                            <li>• Installation cannot be undone automatically</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Confirmation Checkbox -->
            <div class="mb-6">
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" id="backupConfirm" class="checkbox-input">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="backupConfirm" class="cursor-pointer text-sm text-slate-700">
                        I confirm that I have created a backup of my files and database, and I understand that this update will modify system files.
                    </label>
                </div>
            </div>

            <!-- Progress Section (Hidden Initially) -->
            <div id="progressSection" class="hidden">
                <div class="mb-4">
                    <div class="flex items-center justify-between text-sm mb-2">
                        <span class="font-medium text-slate-700" id="progressText">Preparing...</span>
                        <span class="font-semibold text-blue-600" id="progressPercent">0%</span>
                    </div>
                    <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-full transition-all duration-300 rounded-full" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <!-- Terminal Section - ALWAYS VISIBLE ONCE STARTED -->
            <div id="terminalSection" class="hidden mb-4">
                <div class="bg-slate-800 rounded-t-lg p-3 flex items-center justify-between border-2 border-slate-700 border-b-0">
                    <div class="flex items-center gap-3">
                        <div class="flex gap-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                        <h4 class="text-sm font-semibold text-white">Installation Log Terminal</h4>
                        <span class="text-xs text-slate-400" id="logStats">0 log entries</span>
                    </div>

                </div>
                <div class="bg-slate-900 rounded-b-lg p-4 font-mono text-xs text-green-400 overflow-y-auto border-2 border-slate-700 border-t-0" style="max-height: 500px; min-height: 300px;" id="logOutput">
                    <div class="text-slate-500">Waiting for process to start...</div>
                </div>
            </div>

            <!-- Result Section - SHOWS BELOW TERMINAL -->
            <div id="resultSection" class="hidden">
                <div id="successResult" class="hidden bg-green-50 border-2 border-green-300 rounded-lg p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
                        <div class="flex-1">
                            <h3 class="font-semibold text-green-800 text-lg">✓ Update Installed Successfully!</h3>
                            <p class="text-sm text-green-700 mt-1">All files have been updated. Review the terminal log above for details.</p>
                        </div>
                    </div>
                </div>
                <div id="errorResult" class="hidden bg-red-50 border-2 border-red-300 rounded-lg p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-red-600 text-3xl">error</span>
                        <div class="flex-1">
                            <h3 class="font-semibold text-red-800 text-lg">✗ Installation Failed</h3>
                            <p class="text-sm text-red-700 mt-1" id="errorMessage"></p>
                            <p class="text-xs text-red-600 mt-2 font-semibold">↑ Check the terminal log above for detailed error information</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-slate-200 p-6 flex items-center justify-end gap-3">
            <button onclick="closeInstallModal()" id="cancelBtn" class="btn secondary">
                Cancel
            </button>
            <button onclick="startInstallation()" id="confirmBtn" class="btn" disabled>
                <span class="material-symbols-outlined text-lg">download</span>
                Proceed with Installation
            </button>
        </div>
    </div>
</div>

<script>
let currentCommitSha = '';
let logEntryCount = 0;

// Confirm install update
function confirmInstallUpdate(btn) {
    const sha = btn.dataset.sha;
    const message = btn.dataset.message;
    currentCommitSha = sha;
    document.getElementById('updateCommitMessage').textContent = message;
    document.getElementById('installModal').classList.remove('hidden');
    document.getElementById('backupConfirm').checked = false;
    document.getElementById('confirmBtn').disabled = true;
    logEntryCount = 0;

    // Reset sections
    document.getElementById('progressSection').classList.add('hidden');
    document.getElementById('terminalSection').classList.add('hidden');
    document.getElementById('resultSection').classList.add('hidden');
    document.getElementById('successResult').classList.add('hidden');
    document.getElementById('errorResult').classList.add('hidden');

    // Clear terminal
    document.getElementById('logOutput').innerHTML = '<div class="text-slate-500">Waiting for process to start...</div>';
    document.getElementById('logStats').textContent = '0 log entries';
}

// Show update details modal
function showUpdateDetails(btn) {
    const sha = btn.dataset.sha;
    const message = btn.dataset.message;
    const author = btn.dataset.author;
    const date = btn.dataset.date;
    document.getElementById('detailsCommitMessage').textContent = message;
    document.getElementById('detailsSha').textContent = sha;
    document.getElementById('detailsAuthor').textContent = author;
    document.getElementById('detailsDate').textContent = date;

    // Check if already installed
    <?php if (!empty($installedUpdates)): ?>
    const installedShas = <?= json_encode($installedUpdates) ?>;
    const isInstalled = installedShas.includes(sha);
    const statusEl = document.getElementById('detailsStatus');
    if (isInstalled) {
        statusEl.innerHTML = '<span class="px-2 py-1 bg-green-100 text-green-700 rounded font-medium text-xs"><span class="material-symbols-outlined text-sm align-middle">check_circle</span> Installed</span>';
    } else {
        statusEl.innerHTML = '<span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded font-medium text-xs"><span class="material-symbols-outlined text-sm align-middle">pending</span> Pending</span>';
    }
    <?php endif; ?>

    document.getElementById('detailsModal').classList.remove('hidden');

    // Fetch and display files for this commit
    fetchCommitFiles(sha);
}

// Fetch commit files
async function fetchCommitFiles(sha) {
    const filesList = document.getElementById('detailsFilesList');
    const filesCount = document.getElementById('detailsFilesCount');

    try {
        filesList.innerHTML = `
            <div class="text-sm text-blue-600 flex items-center gap-2">
                <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                Loading file information...
            </div>
        `;

        const response = await fetch(`<?= root ?>admin/updates/files?sha=${sha}`);

        // Debug: Log response
        const responseText = await response.text();
        console.log('Response status:', response.status);
        console.log('Response text:', responseText);

        const data = JSON.parse(responseText);

        if (data.success && data.files && data.files.length > 0) {
            filesCount.textContent = `${data.files.length} file(s)`;

            const statusIcons = {
                'added': { icon: 'add_circle', color: 'text-green-600', bg: 'bg-green-50', label: 'Added' },
                'modified': { icon: 'edit', color: 'text-blue-600', bg: 'bg-blue-50', label: 'Modified' },
                'removed': { icon: 'delete', color: 'text-red-600', bg: 'bg-red-50', label: 'Removed' },
                'renamed': { icon: 'drive_file_move', color: 'text-purple-600', bg: 'bg-purple-50', label: 'Renamed' }
            };

            let html = '<div class="space-y-2">';
            data.files.forEach(file => {
                const status = statusIcons[file.status] || statusIcons['modified'];
                html += `
                    <div class="flex items-center justify-between p-2 ${status.bg} rounded text-xs border border-${status.color.replace('text-', '')}/20">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <span class="material-symbols-outlined text-sm ${status.color} flex-shrink-0">${status.icon}</span>
                            <span class="font-mono text-slate-700 truncate" title="${file.filename}">${file.filename}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="px-2 py-0.5 ${status.bg} ${status.color} rounded font-medium border border-current/20">
                                ${status.label}
                            </span>
                            ${file.changes ? `<span class="text-slate-500">±${file.changes}</span>` : ''}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            filesList.innerHTML = html;
        } else {
            filesCount.textContent = 'No files';
            filesList.innerHTML = `
                <div class="text-sm text-slate-500 text-center py-2">
                    <span class="material-symbols-outlined text-2xl">info</span>
                    <p>No file information available</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error fetching files:', error);
        filesCount.textContent = 'Error';
        filesList.innerHTML = `
            <div class="text-sm text-red-600 flex items-center gap-2">
                <span class="material-symbols-outlined text-base">error</span>
                Failed to load file information
            </div>
        `;
    }
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

// Close install modal
function closeInstallModal() {
    document.getElementById('installModal').classList.add('hidden');
    currentCommitSha = '';
}

// Enable/disable confirm button based on checkbox
document.getElementById('backupConfirm').addEventListener('change', function() {
    document.getElementById('confirmBtn').disabled = !this.checked;
});

// Start installation
async function startInstallation() {
    // Hide confirmation section, show progress AND terminal
    document.getElementById('backupConfirm').parentElement.classList.add('hidden');
    document.getElementById('progressSection').classList.remove('hidden');
    document.getElementById('terminalSection').classList.remove('hidden');
    document.getElementById('confirmBtn').disabled = true;
    document.getElementById('cancelBtn').disabled = true;

    const logOutput = document.getElementById('logOutput');
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const progressText = document.getElementById('progressText');

    // Clear terminal at start
    logOutput.innerHTML = '';

    function addLog(message, type = 'info') {
        const colors = {
            info: 'text-green-400',
            warning: 'text-yellow-400',
            error: 'text-red-400',
            success: 'text-blue-400'
        };
        const div = document.createElement('div');
        div.className = colors[type] || colors.info;
        div.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
        logOutput.appendChild(div);
        logOutput.scrollTop = logOutput.scrollHeight;

        // Update log counter
        logEntryCount++;
        const logStats = document.getElementById('logStats');
        if (logStats) {
            logStats.textContent = `${logEntryCount} log ${logEntryCount === 1 ? 'entry' : 'entries'}`;
        }
    }

    function updateProgress(percent, text) {
        progressBar.style.width = percent + '%';
        progressPercent.textContent = percent + '%';
        progressText.textContent = text;
    }

    try {
        addLog('═══════════════════════════════════════════', 'info');
        addLog('Starting update installation...', 'info');
        addLog('═══════════════════════════════════════════', 'info');
        updateProgress(5, 'Initializing...');

        await new Promise(resolve => setTimeout(resolve, 500));
        updateProgress(10, 'Fetching commit data from GitHub...');
        addLog('→ Connecting to Updates Server...', 'info');

        // Make request to install endpoint
        const response = await fetch('<?= root ?>admin/updates/install', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                commit_sha: currentCommitSha
            })
        });

        addLog('✓ Connected to Updates Server', 'success');
        updateProgress(20, 'Parsing response...');

        const result = await response.json();

        addLog('✓ Commit data received', 'success');
        updateProgress(30, 'Analyzing changes...');

        if (result.success) {
            const data = result.data || result;
            const filesUpdated = data.files_updated || 0;
            const filesFailed = data.files_failed || 0;
            const filesSkipped = data.files_skipped || 0;
            const totalFiles = filesUpdated + filesFailed + filesSkipped;

            addLog(`═══════════════════════════════════════════`, 'info');
            addLog(`Total Files: ${totalFiles} | Updated: ${filesUpdated} | Failed: ${filesFailed} | Skipped: ${filesSkipped}`, 'info');
            addLog(`═══════════════════════════════════════════`, 'info');

            updateProgress(40, 'Processing files...');
            await new Promise(resolve => setTimeout(resolve, 300));

            // Show debug info FIRST (before processing files)
            if (data.debug) {
                addLog('─────────────────────────────────────────', 'warning');
                addLog('DEBUG INFORMATION:', 'warning');
                addLog('─────────────────────────────────────────', 'warning');
                addLog(`Root Path: ${data.debug.root_path}`, 'info');
                addLog(`Temp Dir: ${data.debug.temp_dir || 'N/A'}`, 'info');
                addLog(`Total Files in Commit: ${data.debug.total_files_in_commit || 0}`, 'info');
                addLog(`PHP File: ${data.debug.php_file_location || 'N/A'}`, 'info');
                addLog('', 'info');

                // Process each file detail
                if (data.debug.details && data.debug.details.length > 0) {
                    let processedCount = 0;

                    for (let detail of data.debug.details) {
                        processedCount++;
                        const progress = 40 + (processedCount / data.debug.details.length) * 50;
                        updateProgress(Math.round(progress), `Processing ${processedCount}/${data.debug.details.length}...`);

                        const isSuccess = detail.result === 'success';
                        const isSkipped = detail.result === 'skipped';
                        const isFailed = !isSuccess && !isSkipped;

                        addLog(`[${processedCount}/${data.debug.details.length}] ${detail.filename}`, 'info');
                        addLog(`  Status: ${detail.status || 'unknown'}`, 'info');

                        // Download info
                        if (detail.raw_url) {
                            addLog(`  Raw URL: ${detail.raw_url.substring(0, 60)}...`, 'info');
                        }
                        if (detail.download_http_code) {
                            const httpColor = detail.download_http_code === 200 ? 'success' : 'error';
                            addLog(`  Download HTTP: ${detail.download_http_code}`, httpColor);
                        }
                        if (detail.download_size !== undefined) {
                            addLog(`  Download Size: ${detail.download_size} bytes`, 'info');
                        }
                        if (detail.curl_error) {
                            addLog(`  CURL Error: ${detail.curl_error}`, 'error');
                        }

                        // Path info
                        if (detail.normalized_filename) {
                            addLog(`  Normalized: ${detail.normalized_filename}`, 'info');
                        }
                        if (detail.target_path) {
                            addLog(`  Target: ${detail.target_path}`, 'info');
                        }
                        if (detail.target_dir) {
                            addLog(`  Directory: ${detail.target_dir}`, 'info');
                        }

                        // File system status
                        if (detail.file_exists_before !== undefined) {
                            addLog(`  File existed before: ${detail.file_exists_before ? 'Yes' : 'No'}`, 'info');
                        }
                        if (detail.dir_exists !== undefined) {
                            addLog(`  Directory exists: ${detail.dir_exists ? 'Yes' : 'No'}`, detail.dir_exists ? 'success' : 'error');
                        }
                        if (detail.dir_writable !== undefined) {
                            addLog(`  Directory writable: ${detail.dir_writable ? 'Yes' : 'No'}`, detail.dir_writable ? 'success' : 'error');
                        }
                        if (detail.content_size !== undefined) {
                            addLog(`  Content Size: ${detail.content_size} bytes`, 'info');
                        }
                        if (detail.bytes_written !== undefined) {
                            addLog(`  Bytes Written: ${detail.bytes_written}`, detail.bytes_written > 0 ? 'success' : 'error');
                        }
                        if (detail.file_exists_after !== undefined) {
                            addLog(`  File exists after: ${detail.file_exists_after ? 'Yes' : 'No'}`, detail.file_exists_after ? 'success' : 'error');
                        }

                        // Result
                        if (detail.result) {
                            if (isSuccess) {
                                addLog(`  ✓ RESULT: ${detail.result.toUpperCase()}`, 'success');
                            } else if (isSkipped) {
                                addLog(`  ⊘ RESULT: ${detail.result.toUpperCase()}`, 'warning');
                            } else {
                                addLog(`  ✗ RESULT: ${detail.result.toUpperCase()}`, 'error');
                            }
                        }

                        // Error message
                        if (detail.error) {
                            addLog(`  ERROR: ${detail.error}`, 'error');
                        }

                        addLog('', 'info'); // Blank line between files

                        await new Promise(resolve => setTimeout(resolve, 100));
                    }
                }
            }

            updateProgress(95, 'Finalizing...');
            addLog('─────────────────────────────────────────', 'info');
            addLog('Saving update information...', 'info');

            await new Promise(resolve => setTimeout(resolve, 500));

            updateProgress(100, 'Complete!');
            addLog('═══════════════════════════════════════════', 'info');

            if (filesFailed > 0) {
                addLog(`⚠ WARNING: ${filesFailed} file(s) failed to update!`, 'error');
                addLog('Check the detailed log above for specific errors.', 'warning');
                addLog('Update marked as installed but requires manual attention.', 'warning');
            } else if (filesUpdated > 0) {
                addLog(`✓ SUCCESS: All ${filesUpdated} file(s) updated successfully!`, 'success');
            } else {
                addLog(`⊘ No files were updated (${filesSkipped} skipped)`, 'warning');
            }

            addLog('═══════════════════════════════════════════', 'info');
            addLog('', 'info');
            addLog('Installation process completed. Check results below.', 'info');

            // Show success message BELOW terminal (don't hide anything!)
            document.getElementById('resultSection').classList.remove('hidden');
            document.getElementById('successResult').classList.remove('hidden');

            // Refresh immediately after success (no timer)
            window.location.reload();

        } else {
            // Handle failed response
            addLog('═══════════════════════════════════════════', 'error');
            addLog('⚠ INSTALLATION FAILED', 'error');
            addLog('═══════════════════════════════════════════', 'error');

            if (result.message) {
                addLog(`Error Message: ${result.message}`, 'error');
            }

            if (result.error) {
                addLog(`Error Details: ${result.error}`, 'error');
            }

            // Show debug info if available
            if (result.debug) {
                addLog('─────────────────────────────────────────', 'warning');
                addLog('DEBUG INFORMATION:', 'warning');
                addLog('─────────────────────────────────────────', 'warning');
                addLog(JSON.stringify(result.debug, null, 2), 'info');
            }

            throw new Error(result.message || 'Installation failed');
        }

    } catch (error) {
        addLog('═══════════════════════════════════════════', 'error');
        addLog('⚠ CRITICAL ERROR', 'error');
        addLog('═══════════════════════════════════════════', 'error');
        addLog(`Error: ${error.message}`, 'error');

        if (error.stack) {
            addLog('Stack Trace:', 'warning');
            addLog(error.stack, 'info');
        }

        addLog('', 'error');
        addLog('Installation process failed. Check error details below.', 'error');

        updateProgress(0, 'Failed');

        // Show error message BELOW terminal (don't hide anything!)
        document.getElementById('resultSection').classList.remove('hidden');
        document.getElementById('errorResult').classList.remove('hidden');
        document.getElementById('errorMessage').textContent = error.message;
        document.getElementById('cancelBtn').disabled = false;

        // No auto-refresh timer on error; keep log visible for manual review
    }
}

// Close modals on outside click
document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDetailsModal();
    }
});

document.getElementById('installModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeInstallModal();
    }
});
</script>