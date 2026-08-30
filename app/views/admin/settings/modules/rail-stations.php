<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';

$stationStats = _train_station_counts($db);
$byType = $stationStats['by_type'];
?>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">directions_railway</span>
                <h3 class="text-sm font-semibold text-gray-900">Station Import</h3>
            </div>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-indigo-600 text-2xl">info</span>
                <div class="text-sm text-indigo-900">
                    <p class="font-bold mb-2">One-click station catalog sync</p>
                    <p class="text-xs text-indigo-800 mb-2">
                        Downloads the official China Railway (12306) station list, imports Laos-China stations,
                        and keeps Whoosh Indonesia stations. Existing China/Laos rows are refreshed; Whoosh stations are preserved.
                    </p>
                    <ul class="text-xs text-indigo-800 space-y-1 list-disc list-inside">
                        <li><strong>Type 1</strong> — China Railway (CN)</li>
                        <li><strong>Type 2</strong> — Laos-China (LA/CN)</li>
                        <li><strong>Type 3</strong> — Whoosh Indonesia (ID)</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <div class="text-2xl font-bold text-gray-900" id="rail-stations-total"><?= (int)$stationStats['total'] ?></div>
                <div class="text-xs text-gray-500 mt-1">Total stations</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <div class="text-2xl font-bold text-gray-900" id="rail-stations-cn"><?= (int)($byType[1] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">China (type 1)</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <div class="text-2xl font-bold text-gray-900" id="rail-stations-la"><?= (int)($byType[2] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">Laos (type 2)</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <div class="text-2xl font-bold text-gray-900" id="rail-stations-id"><?= (int)($byType[3] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">Whoosh ID (type 3)</div>
            </div>
        </div>

        <div id="rail-stations-alert" class="hidden"></div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <button type="button"
                    onclick="importRailStations()"
                    class="btn inline-flex items-center gap-2"
                    id="rail-import-stations-btn">
                <span class="material-symbols-outlined text-sm" id="rail-import-stations-icon">cloud_download</span>
                <span id="rail-import-stations-label">Import Stations</span>
            </button>
            <p class="text-xs text-gray-500">This may take up to 30 seconds while the 12306 catalog is downloaded.</p>
        </div>
    </div>
</div>

<script>
function showRailStationAlert(type, message) {
    const el = document.getElementById('rail-stations-alert');
    if (!el) return;
    const cls = type === 'success' ? 'alert-success' : 'alert-error';
    const icon = type === 'success' ? 'check_circle' : 'error';
    el.className = cls + ' mb-0';
    el.innerHTML = '<span class="material-symbols-outlined">' + icon + '</span><div><p class="text-sm font-medium">' + message + '</p></div>';
    el.classList.remove('hidden');
}

function updateRailStationCounts(data) {
    if (!data) return;
    const map = {
        'rail-stations-total': data.total_in_db,
        'rail-stations-cn': data.by_type && data.by_type[1],
        'rail-stations-la': data.by_type && data.by_type[2],
        'rail-stations-id': data.by_type && data.by_type[3],
    };
    Object.keys(map).forEach(function (id) {
        const node = document.getElementById(id);
        if (node && map[id] !== undefined && map[id] !== null) {
            node.textContent = map[id];
        }
    });
}

async function importRailStations() {
    const btn = document.getElementById('rail-import-stations-btn');
    const icon = document.getElementById('rail-import-stations-icon');
    const label = document.getElementById('rail-import-stations-label');
    if (!btn || btn.disabled) return;

    if (!confirm('Import / refresh the rail station catalog from 12306?\n\nChina and Laos stations will be replaced. Whoosh Indonesia stations are kept.')) {
        return;
    }

    btn.disabled = true;
    if (icon) icon.textContent = 'progress_activity';
    if (icon) icon.classList.add('animate-spin');
    if (label) label.textContent = 'Importing...';

    try {
        const response = await fetch('<?= root . admin ?>/settings/modules/rail/import-stations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({})
        });
        const data = await response.json();
        if (data.success) {
            showRailStationAlert('success', data.message + ' Total in database: ' + data.total_in_db + '.');
            updateRailStationCounts(data);
        } else {
            showRailStationAlert('error', data.error || data.message || 'Import failed.');
        }
    } catch (err) {
        showRailStationAlert('error', 'Import failed: ' + (err.message || 'Network error'));
    } finally {
        btn.disabled = false;
        if (icon) {
            icon.textContent = 'cloud_download';
            icon.classList.remove('animate-spin');
        }
        if (label) label.textContent = 'Import Stations';
    }
}
</script>
