<?php if (strtolower($module['name'] ?? '') === 'airalo'): ?>
<?php include dirname(__DIR__, 5) . '/modules/esim/airalo/countries.php'; ?>
<?php endif; ?>

<?php if (in_array(strtolower($module['name'] ?? ''), ['hotelbeds', 'hotelston', 'stuba', 'agoda', 'ratehawk', 'tbo-holidays', 'wanderbeds', 'toursbms'])): ?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">storage</span>
                <h3 class="text-sm font-semibold text-gray-900"><?=T::database_configuration?></h3>
            </div>
            <span class="text-xs text-gray-500 bg-blue-100 px-2 py-1 rounded-md">
                <?=T::separate_content_database?>
            </span>
        </div>
    </div>
    <div class="p-4 space-y-4">
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-3">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                <div class="text-sm text-blue-900">
                    <p class="font-bold mb-2 text-base"><?=T::separate_database_setup?></p>
                    <p class="text-xs mb-3 text-blue-800"><?= ucfirst($module['name']) ?> <?=T::separate_database_description?></p>
                    <div class="flex items-start gap-2 text-xs">
                        <span class="text-green-600">✓</span>
                        <div>
                            <span class="font-medium"><?=T::benefits?>:</span> <?=T::separate_database_benefits?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <?=T::host?>
                </label>
                <input type="text"
                       name="host"
                       id="db_host"
                       value="<?= $module['host'] ?? 'localhost' ?>"
                       class="input w-full"
                       placeholder="localhost">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <?=T::database_name?>
                </label>
                <input type="text"
                       name="database"
                       id="db_database"
                       value="<?= $module['database'] ?? '' ?>"
                       class="input w-full"
                       placeholder="phptravels_hotelbeds">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <?=T::username?>
                </label>
                <input type="text"
                       name="username"
                       id="db_username"
                       value="<?= $module['username'] ?? '' ?>"
                       class="input w-full"
                       placeholder="root">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <?=T::password?>
                </label>
                <input type="password"
                       name="password"
                       id="db_password"
                       value="<?= $module['password'] ?? '' ?>"
                       class="input w-full"
                       placeholder="••••••••">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-2">
            <button type="button"
                    onclick="testDatabaseConnection()"
                    class="btn"
                    id="testDbButton">
                <span class="material-symbols-outlined text-sm">cable</span>
                <?=T::test_connection?>
            </button>
            <button type="button"
                    onclick="saveDatabaseCredentials()"
                    class="btn"
                    id="saveDbButton"
                    disabled>
                <span class="material-symbols-outlined text-sm">save</span>
                <?=T::save_credentials?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ============================================
// LOGS CONFIGURATION - Auto-detect log files
// ============================================
$showLogsSection = false;
$logFiles = [];
$logsPath = '';

if (in_array(strtolower($module['name']), ['hotelbeds', 'hotelston', 'stuba', 'agoda', 'ratehawk', 'tbo-holidays', 'wanderbeds', 'toursbms'])) {
    $possibleLogPaths = [
        __DIR__ . '/../../../../modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
        $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
        $_SERVER['DOCUMENT_ROOT'] . '/modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
        dirname(__FILE__) . '/../../../../modules/' . $module['type'] . '/' . $module['name'] . '/logs/'
    ];

    foreach ($possibleLogPaths as $path) {
        if (file_exists($path) && is_dir($path)) {
            $logsPath = $path;
            $files = scandir($path);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                    $filePath = $path . $file;
                    $logFiles[] = [
                        'name'      => $file,
                        'size'      => filesize($filePath),
                        'date'      => date('Y-m-d H:i:s', filemtime($filePath)),
                        'timestamp' => filemtime($filePath)
                    ];
                }
            }
            usort($logFiles, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
            if (!empty($logFiles) || (isset($module['logging_enabled']) && $module['logging_enabled'] == 1)) {
                $showLogsSection = true;
            }
            break;
        }
    }
}
?>

<?php if (in_array(strtolower($module['name'] ?? ''), ['hotelbeds', 'toursbms'])): ?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">description</span>
                <h3 class="text-sm font-semibold text-gray-900"><?=T::logs_configuration?></h3>
            </div>
            <div class="flex items-center gap-3">
                <?php if (!empty($logFiles)): ?>
                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-md font-medium">
                    <?= count($logFiles) ?> <?=T::log_files?>
                </span>
                <?php endif; ?>
                <label class="flex items-center gap-2 cursor-pointer">
                    <span class="text-xs text-gray-600"><?=T::enable?> <?=T::logging?></span>
                    <input type="checkbox"
                        name="logging_enabled"
                        id="logging_enabled_toggle"
                        value="1"
                        <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 ? 'checked' : '' ?>
                        class="w-10 h-5 appearance-none bg-gray-300 rounded-full relative cursor-pointer transition-colors checked:bg-blue-600
                                before:content-[''] before:absolute before:w-4 before:h-4 before:rounded-full before:bg-white before:top-0.5 before:left-0.5
                                before:transition-transform checked:before:translate-x-5">
                </label>
            </div>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                <div class="text-sm text-blue-900">
                    <p class="font-bold mb-2 text-base"><?=T::api_request_logging?></p>
                    <p class="text-xs mb-3 text-blue-800">
                        <?=T::logs_description?>: <?= ucfirst($module['name']) ?> API requests and responses are automatically saved as JSON files for debugging and monitoring.
                    </p>
                    <div class="space-y-1">
                        <div class="flex items-start gap-2 text-xs">
                            <span class="text-green-600">✓</span>
                            <div><span class="font-medium"><?=T::benefits?>:</span> Track API performance, debug issues, monitor request/response data</div>
                        </div>
                        <div class="flex items-start gap-2 text-xs">
                            <span class="text-orange-600">⚠</span>
                            <div><span class="font-medium"><?=T::warning?>:</span> Disabling logging will permanently delete ALL existing log files</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="logs_list_section" style="display: <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 && !empty($logFiles) ? 'block' : 'none' ?>;">
            <?php if (!empty($logFiles)): ?>
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                    <h4 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-blue-600">folder_open</span>
                        <?=T::available_log_files?>
                    </h4>
                </div>
                <div class="max-h-64 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr class="border-b border-gray-200">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::file_name?></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::size?></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::date?></th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-600"><?=T::actions?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($logFiles as $logFile): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-gray-400 text-base">description</span>
                                        <span class="font-mono text-xs text-gray-900"><?= htmlspecialchars($logFile['name']) ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600"><?= formatBytes($logFile['size']) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-600"><?= $logFile['date'] ?></td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" onclick="viewLogFile('<?= htmlspecialchars($logFile['name']) ?>')" class="text-blue-600 hover:text-blue-800">
                                            <span class="material-symbols-outlined text-base">visibility</span>
                                        </button>
                                        <button type="button" onclick="downloadLogFile('<?= htmlspecialchars($logFile['name']) ?>', event)" class="text-green-600 hover:text-green-800">
                                            <span class="material-symbols-outlined text-base">download</span>
                                        </button>
                                        <button type="button" onclick="deleteLogFile('<?= htmlspecialchars($logFile['name']) ?>')" class="text-red-600 hover:text-red-800">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex items-center justify-between pt-2">
                <span class="text-xs text-gray-500">
                    <?=T::total?>: <?= count($logFiles) ?> <?=T::files?> (<?= formatBytes(array_sum(array_column($logFiles, 'size'))) ?>)
                </span>
                <div class="flex gap-2">
                    <button type="button" onclick="downloadAllLogs(event)" class="btn light text-xs">
                        <span class="material-symbols-outlined text-sm">folder_zip</span>
                        <?=T::download_all?>
                    </button>
                    <button type="button" onclick="deleteAllLogs()" class="btn light text-xs">
                        <span class="material-symbols-outlined text-sm">delete_sweep</span>
                        <?=T::delete_all_logs?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div id="no_logs_message" style="display: <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 && empty($logFiles) ? 'block' : 'none' ?>;" class="text-center py-8">
            <span class="material-symbols-outlined text-gray-300 text-5xl mb-3">description</span>
            <p class="text-sm text-gray-600"><?=T::no_log_files_available?></p>
            <p class="text-xs text-gray-500 mt-1"><?=T::logs_will_appear_here_when_created?></p>
        </div>
    </div>
</div>
<?php endif; ?>


