<?php
// Get PHP information
$php_version = phpversion();
$php_sapi = php_sapi_name();
$php_os = PHP_OS;
$php_arch = PHP_INT_SIZE * 8 . '-bit';

// Get server information
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$server_protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown';
$document_root = $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown';

// Get database information
try {
    $db_version = $db->query("SELECT VERSION()")->fetchColumn();
    $db_size_query = $db->query("SELECT 
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()");
    $db_size = $db_size_query->fetchColumn() . ' MB';
    
    $table_count_query = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    $table_count = $table_count_query->fetchColumn();
} catch (Exception $e) {
    $db_version = 'Error: ' . $e->getMessage();
    $db_size = 'N/A';
    $table_count = 0;
}

// Get PHP extensions
$loaded_extensions = get_loaded_extensions();
sort($loaded_extensions);

// Critical extensions
$critical_extensions = [
    'mysqli' => 'MySQL Native Driver',
    'pdo' => 'PDO Data Objects',
    'pdo_mysql' => 'PDO MySQL Driver',
    'curl' => 'cURL',
    'openssl' => 'OpenSSL',
    'mbstring' => 'Multibyte String',
    'gd' => 'GD Graphics',
    'zip' => 'ZIP Archive',
    'json' => 'JSON',
    'xml' => 'XML Parser',
    'fileinfo' => 'File Information',
    'hash' => 'Hash',
    'session' => 'Session'
];

// PHP configuration
$php_config = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . 's',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_vars' => ini_get('max_input_vars'),
    'max_input_time' => ini_get('max_input_time') . 's',
    'display_errors' => ini_get('display_errors') ? 'On' : 'Off',
    'error_reporting' => error_reporting(),
    'default_timezone' => date_default_timezone_get(),
    'session.save_handler' => ini_get('session.save_handler'),
    'session.save_path' => ini_get('session.save_path'),
    'opcache.enable' => extension_loaded('Zend OPcache') ? (ini_get('opcache.enable') ? 'Enabled' : 'Disabled') : 'Not installed'
];

// Directory permissions
$directories = [
    'uploads' => 'uploads/',
    'cache' => 'app/cache/',
    'vendor' => 'vendor/',
    'modules' => 'modules/'
];

$dir_permissions = [];
foreach ($directories as $name => $path) {
    $full_path = dirname(__DIR__, 4) . '/' . $path;
    if (file_exists($full_path)) {
        $dir_permissions[$name] = [
            'exists' => true,
            'writable' => is_writable($full_path),
            'readable' => is_readable($full_path),
            'path' => $path,
            'permissions' => substr(sprintf('%o', fileperms($full_path)), -4)
        ];
    } else {
        $dir_permissions[$name] = [
            'exists' => false,
            'path' => $path
        ];
    }
}

// Application information
$app_info = [
    'name' => $db->get('settings', 'business_name', ['id' => 1]) ?? 'PHPTRAVELS',
    'version' => $db->get('settings', 'version', ['id' => 1]) ?? '10.0.0',
    'license_key' => $db->get('settings', 'license_key', ['id' => 1]) ?? 'N/A',
    'installed' => file_exists(dirname(__DIR__, 4) . '/.env') ? 'Yes' : 'No',
    'timezone' => date_default_timezone_get()
];

// System load (if available)
$system_load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

// Disk space
$disk_free = disk_free_space(dirname(__DIR__, 4));
$disk_total = disk_total_space(dirname(__DIR__, 4));
$disk_used = $disk_total - $disk_free;
$disk_usage_percent = round(($disk_used / $disk_total) * 100, 2);
?>

<div class="container my-4">
    <!-- HEADER -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">System Information</h2>
            <p class="text-sm text-slate-600 mt-1">Comprehensive technical details about your system</p>
        </div>
        <button onclick="window.print()" class="btn secondary inline-flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">print</span>
            <span>Print Report</span>
        </button>
    </div>

    <!-- APPLICATION INFO -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">info</span>
                <h3>Application Information</h3>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Application Name</div>
                    <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($app_info['name']) ?></div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Version</div>
                    <div class="text-sm font-semibold text-slate-800"><?= $app_info['version'] ?></div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">License Key</div>
                    <div class="text-sm font-mono text-slate-800"><?= htmlspecialchars(substr($app_info['license_key'], 0, 20)) ?>...</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Timezone</div>
                    <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($app_info['timezone']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- PHP INFORMATION -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">code</span>
                    <h3>PHP Information</h3>
                </div>
            </div>
            <div class="card-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">PHP Version</span>
                        <span class="text-xs font-semibold text-slate-800"><?= $php_version ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">SAPI</span>
                        <span class="text-xs font-semibold text-slate-800"><?= $php_sapi ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">Operating System</span>
                        <span class="text-xs font-semibold text-slate-800"><?= $php_os ?> (<?= $php_arch ?>)</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">Extensions Loaded</span>
                        <span class="text-xs font-semibold text-slate-800"><?= count($loaded_extensions) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SERVER INFORMATION -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">dns</span>
                    <h3>Server Information</h3>
                </div>
            </div>
            <div class="card-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">Server Software</span>
                        <span class="text-xs font-semibold text-slate-800"><?= htmlspecialchars($server_software) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">Protocol</span>
                        <span class="text-xs font-semibold text-slate-800"><?= $server_protocol ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">Document Root</span>
                        <span class="text-xs font-mono text-slate-800 truncate max-w-xs"><?= htmlspecialchars($document_root) ?></span>
                    </div>
                    <?php if ($system_load): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-medium text-slate-600">System Load</span>
                        <span class="text-xs font-semibold text-slate-800"><?= round($system_load[0], 2) ?>, <?= round($system_load[1], 2) ?>, <?= round($system_load[2], 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- PHP CONFIGURATION -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">settings</span>
                <h3>PHP Configuration</h3>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach ($php_config as $key => $value): ?>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1"><?= str_replace('_', ' ', ucfirst($key)) ?></div>
                    <div class="text-xs font-semibold text-slate-800"><?= htmlspecialchars($value) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- CRITICAL EXTENSIONS -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">extension</span>
                <h3>Critical PHP Extensions</h3>
            </div>
            <div class="text-xs text-slate-600">Required for application functionality</div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <?php foreach ($critical_extensions as $ext => $name): ?>
                <?php $loaded = extension_loaded($ext); ?>
                <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border <?= $loaded ? 'border-green-200' : 'border-red-200' ?>">
                    <div>
                        <div class="text-xs font-semibold text-slate-800"><?= $name ?></div>
                        <div class="text-xs text-slate-500 mt-0.5"><?= $ext ?></div>
                    </div>
                    <?php if ($loaded): ?>
                        <span class="material-symbols-outlined text-green-600 text-lg">check_circle</span>
                    <?php else: ?>
                        <span class="material-symbols-outlined text-red-600 text-lg">cancel</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- DATABASE INFORMATION -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">storage</span>
                <h3>Database Information</h3>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Database Version</div>
                    <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($db_version) ?></div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Total Tables</div>
                    <div class="text-sm font-semibold text-slate-800"><?= $table_count ?></div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Database Size</div>
                    <div class="text-sm font-semibold text-slate-800"><?= $db_size ?></div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Character Set</div>
                    <div class="text-sm font-semibold text-slate-800">UTF8MB4</div>
                </div>
            </div>
        </div>
    </div>

    <!-- DIRECTORY PERMISSIONS -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">folder</span>
                <h3>Directory Permissions</h3>
            </div>
        </div>
        <div class="card-body">
            <div class="space-y-3">
                <?php foreach ($dir_permissions as $name => $info): ?>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 bg-slate-50 rounded-lg border <?= $info['exists'] && $info['writable'] ? 'border-green-200' : 'border-red-200' ?> gap-3">
                    <div class="flex-1">
                        <div class="text-sm font-semibold text-slate-800 capitalize"><?= $name ?></div>
                        <div class="text-xs font-mono text-slate-500 mt-1"><?= $info['path'] ?></div>
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <?php if ($info['exists']): ?>
                            <div class="text-xs">
                                <span class="text-slate-600">Permissions:</span>
                                <span class="font-mono font-semibold text-slate-800"><?= $info['permissions'] ?></span>
                            </div>
                            <div class="flex gap-2">
                                <?php if ($info['readable']): ?>
                                    <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded">Readable</span>
                                <?php endif; ?>
                                <?php if ($info['writable']): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded">Writable</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-700 text-xs font-medium rounded">Read-only</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-red-100 text-red-700 text-xs font-medium rounded">Not Found</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- DISK SPACE -->
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div>
                <span class="card-header-icon">hard_drive</span>
                <h3>Disk Space Usage</h3>
            </div>
        </div>
        <div class="card-body">
            <div class="mb-4">
                <div class="flex justify-between text-xs text-slate-600 mb-2">
                    <span><?= round($disk_used / 1024 / 1024 / 1024, 2) ?> GB used of <?= round($disk_total / 1024 / 1024 / 1024, 2) ?> GB</span>
                    <span><?= $disk_usage_percent ?>%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3">
                    <div class="h-3 rounded-full <?= $disk_usage_percent > 90 ? 'bg-red-600' : ($disk_usage_percent > 70 ? 'bg-yellow-500' : 'bg-green-600') ?>" style="width: <?= $disk_usage_percent ?>%"></div>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Total Space</div>
                    <div class="text-sm font-semibold text-slate-800"><?= round($disk_total / 1024 / 1024 / 1024, 2) ?> GB</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Used Space</div>
                    <div class="text-sm font-semibold text-slate-800"><?= round($disk_used / 1024 / 1024 / 1024, 2) ?> GB</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 mb-1">Free Space</div>
                    <div class="text-sm font-semibold text-slate-800"><?= round($disk_free / 1024 / 1024 / 1024, 2) ?> GB</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ALL PHP EXTENSIONS -->
    <div class="card p-0">
        <div class="card-header">
            <div>
                <span class="card-header-icon">widgets</span>
                <h3>All PHP Extensions</h3>
            </div>
            <div class="text-xs text-slate-600"><?= count($loaded_extensions) ?> loaded</div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                <?php foreach ($loaded_extensions as $ext): ?>
                <div class="px-3 py-2 bg-slate-50 border border-slate-200 rounded text-xs font-mono text-slate-700">
                    <?= htmlspecialchars($ext) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header-icon { display: none !important; }
    .card { page-break-inside: avoid; }
}
</style>