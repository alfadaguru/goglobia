<?php
@$SECURE or die('Access Denied!');

// Compare the live database with the LATEST install/db.sql (pulled from the
// GitHub repo, cached 15 min; local file only as fallback). Additive only.
if (isset($_GET['refresh'])) {
    getDbSqlSource(true);
}
$dbSqlSource = getDbSqlSource();
$diff = getDatabaseSchemaDiff($db);
$missingTables  = $diff['tables'];
$missingColumns = $diff['columns'];
$modifiedColumns = $diff['modified'] ?? [];
$missingModules  = $diff['modules'] ?? [];
$duplicateModules = $diff['duplicates'] ?? [];
$missingGateways = $diff['gateways'] ?? [];
$totalChanges    = count($missingTables) + count($missingColumns) + count($modifiedColumns) + count($missingModules) + count($duplicateModules) + count($missingGateways);

// Flat list the front-end iterates over to drive the progress bar.
$changeQueue = [];
foreach ($missingTables as $t) {
    $changeQueue[] = [
        'kind'  => 'table',
        'table' => $t['table'],
        'label' => 'Create table `' . $t['table'] . '` (' . count($t['columns']) . ' columns)',
    ];
}
foreach ($missingColumns as $c) {
    $changeQueue[] = [
        'kind'   => 'column',
        'table'  => $c['table'],
        'column' => $c['column'],
        'label'  => 'Add column `' . $c['table'] . '`.`' . $c['column'] . '`',
    ];
}
// Enum extensions run BEFORE module inserts so new module types are accepted.
foreach ($modifiedColumns as $c) {
    $changeQueue[] = [
        'kind'   => 'modify',
        'table'  => $c['table'],
        'column' => $c['column'],
        'label'  => 'Extend column `' . $c['table'] . '`.`' . $c['column'] . '` (+' . implode(', ', $c['added']) . ')',
    ];
}
foreach ($missingModules as $m) {
    $changeQueue[] = [
        'kind'   => 'module',
        'module' => $m['name'],
        'label'  => (!empty($m['repair']) ? 'Repair module `' : 'Install module `') . $m['name'] . '`',
    ];
}
foreach ($missingGateways as $g) {
    $changeQueue[] = [
        'kind'    => 'gateway',
        'gateway' => $g['name'],
        'label'   => 'Install payment gateway `' . $g['name'] . '`',
    ];
}
// Duplicate cleanup runs LAST, after installs/repairs, so it sees the final rows.
foreach ($duplicateModules as $d) {
    $changeQueue[] = [
        'kind'   => 'dedupe',
        'module' => $d['name'],
        'type'   => $d['type'],
        'label'  => 'Remove ' . count($d['delete_ids']) . ' duplicate row' . (count($d['delete_ids']) === 1 ? '' : 's') . ' of module `' . $d['name'] . '` (' . $d['type'] . ')',
    ];
}
?>
<div class="container my-4">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-2">
            <a href="<?= root ?>updates" class="text-slate-400 hover:text-slate-600" title="Back to Updates">
                <span class="material-symbols-outlined align-middle">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Database Update</h1>
                <p class="text-sm text-slate-600 mt-1">Compares your database with the latest <span class="font-mono">install/db.sql</span> and adds any missing tables, columns or modules.</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <?php
                $srcIsGithub = in_array($dbSqlSource['source'], ['github', 'github-stale'], true);
                $srcLabel = $srcIsGithub
                    ? 'GitHub ' . htmlspecialchars((string)($dbSqlSource['branch'] ?? 'main'))
                    : 'Local file';
                $srcWhen = !empty($dbSqlSource['fetched_at']) ? date('M d, Y H:i', (int)$dbSqlSource['fetched_at']) : '—';
                $srcNote = $dbSqlSource['source'] === 'github-stale' ? ' (cached, refresh failed)' : ($dbSqlSource['source'] === 'local' ? ' (GitHub unavailable — may be outdated)' : '');
            ?>
            <span class="text-xs px-2.5 py-1.5 rounded-lg border <?= $srcIsGithub && $dbSqlSource['source'] === 'github' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-amber-50 border-amber-200 text-amber-700' ?>">
                <span class="material-symbols-outlined text-sm align-middle"><?= $srcIsGithub ? 'cloud_done' : 'folder' ?></span>
                Schema source: <strong><?= $srcLabel ?></strong> · <?= $srcWhen ?><?= $srcNote ?>
            </span>
            <a href="<?= root.admin ?>/updates/database?refresh=1" class="btn secondary">
                <span class="material-symbols-outlined text-lg">refresh</span>
                Refresh from GitHub
            </a>
        </div>
    </div>

    <?php if ($totalChanges === 0): ?>
        <!-- Up to date -->
        <div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 mb-4">
                <span class="material-symbols-outlined text-green-600 text-4xl">check_circle</span>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Your database is up to date</h3>
            <p class="text-sm text-gray-500">No missing tables or columns were found compared to <span class="font-mono">install/db.sql</span>.</p>
        </div>
    <?php else: ?>

        <!-- Summary -->
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-5 flex items-start gap-3">
            <span class="material-symbols-outlined">info</span>
            <div class="text-sm">
                <strong><?= $totalChanges ?> change<?= $totalChanges === 1 ? '' : 's' ?> pending.</strong>
                Found
                <?= count($missingTables) ?> new table<?= count($missingTables) === 1 ? '' : 's' ?>,
                <?= count($missingColumns) ?> new column<?= count($missingColumns) === 1 ? '' : 's' ?>,
                <?= count($modifiedColumns) ?> extended column<?= count($modifiedColumns) === 1 ? '' : 's' ?>,
                <?= count($missingModules) ?> new module<?= count($missingModules) === 1 ? '' : 's' ?>,
                <?= count($missingGateways) ?> new payment gateway<?= count($missingGateways) === 1 ? '' : 's' ?>,
                and <?= count($duplicateModules) ?> module<?= count($duplicateModules) === 1 ? '' : 's' ?> with duplicate rows.
                Additive only — nothing existing is dropped or modified<?= $duplicateModules ? ' (duplicate module rows are de-duplicated, keeping one row per module)' : '' ?>.
            </div>
        </div>

        <!-- Pending changes -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-900">Pending changes</h3>
            </div>
            <div class="p-4 space-y-5">

                <?php if ($missingTables): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">New tables (<?= count($missingTables) ?>)</h4>
                    <div class="space-y-2">
                        <?php foreach ($missingTables as $t): ?>
                        <div class="flex items-start gap-2 text-sm">
                            <span class="material-symbols-outlined text-green-600 text-lg mt-0.5">add_circle</span>
                            <div>
                                <span class="font-mono font-medium text-gray-900"><?= htmlspecialchars($t['table']) ?></span>
                                <span class="text-gray-400 text-xs">— <?= count($t['columns']) ?> columns: <?= htmlspecialchars(implode(', ', $t['columns'])) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($missingColumns): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">New columns (<?= count($missingColumns) ?>)</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-3">Table</th><th class="py-2 pr-3">Column</th><th class="py-2">Definition</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($missingColumns as $c): ?>
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($c['table']) ?></td>
                                    <td class="py-2 pr-3 font-mono text-xs font-medium text-gray-900"><?= htmlspecialchars($c['column']) ?></td>
                                    <td class="py-2 font-mono text-xs text-gray-500"><?= htmlspecialchars($c['definition']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($modifiedColumns): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Extended columns (<?= count($modifiedColumns) ?>)</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-3">Table</th><th class="py-2 pr-3">Column</th><th class="py-2">New values</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($modifiedColumns as $c): ?>
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($c['table']) ?></td>
                                    <td class="py-2 pr-3 font-mono text-xs font-medium text-gray-900"><?= htmlspecialchars($c['column']) ?></td>
                                    <td class="py-2 font-mono text-xs text-gray-500">+ <?= htmlspecialchars(implode(', ', $c['added'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($missingModules): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">New modules (<?= count($missingModules) ?>)</h4>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($missingModules as $m): ?>
                        <span class="inline-flex items-center gap-1.5 text-sm <?= !empty($m['repair']) ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-indigo-50 text-indigo-700 border-indigo-200' ?> border rounded-lg px-3 py-1.5">
                            <span class="material-symbols-outlined text-base"><?= !empty($m['repair']) ? 'build' : 'extension' ?></span>
                            <span class="font-medium"><?= htmlspecialchars($m['name']) ?></span>
                            <?php if (!empty($m['type'])): ?><span class="text-xs opacity-70">· <?= htmlspecialchars($m['type']) ?><?= !empty($m['repair']) ? ' (repair type)' : '' ?></span><?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($missingGateways): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">New payment gateways (<?= count($missingGateways) ?>)</h4>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($missingGateways as $g): ?>
                        <span class="inline-flex items-center gap-1.5 text-sm bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg px-3 py-1.5">
                            <span class="material-symbols-outlined text-base">credit_card</span>
                            <span class="font-medium"><?= htmlspecialchars($g['name']) ?></span>
                            <?php if (!empty($g['type'])): ?><span class="text-xs opacity-70">· <?= htmlspecialchars(str_replace('_', ' ', $g['type'])) ?></span><?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($duplicateModules): ?>
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Duplicate modules (<?= count($duplicateModules) ?>)</h4>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($duplicateModules as $d): ?>
                        <span class="inline-flex items-center gap-1.5 text-sm bg-rose-50 text-rose-700 border border-rose-200 rounded-lg px-3 py-1.5">
                            <span class="material-symbols-outlined text-base">cleaning_services</span>
                            <span class="font-medium"><?= htmlspecialchars($d['name']) ?></span>
                            <span class="text-xs opacity-70">· <?= htmlspecialchars($d['type']) ?> — keep 1, remove <?= count($d['delete_ids']) ?></span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress + action -->
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <div id="dbUpdateProgressWrap" class="hidden mb-4">
                <div class="flex justify-between text-xs text-gray-600 mb-1">
                    <span id="dbUpdateStatus">Starting…</span>
                    <span id="dbUpdatePercent">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                    <div id="dbUpdateBar" class="h-3 bg-green-600 rounded-full transition-all duration-200" style="width:0%"></div>
                </div>
                <div id="dbUpdateLog" class="mt-3 max-h-40 overflow-y-auto text-xs font-mono text-gray-500 space-y-0.5"></div>
            </div>

            <button type="button" id="dbUpdateBtn"
                class="btn bg-green-600 hover:bg-green-700 text-white border-green-600 inline-flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">bolt</span>
                Update Database
            </button>
            <div id="dbUpdateDone" class="hidden mt-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg p-3"></div>
        </div>

        <script>
        (function () {
            const changes = <?= json_encode($changeQueue, JSON_UNESCAPED_SLASHES) ?>;
            const btn     = document.getElementById('dbUpdateBtn');
            const wrap    = document.getElementById('dbUpdateProgressWrap');
            const bar     = document.getElementById('dbUpdateBar');
            const pct     = document.getElementById('dbUpdatePercent');
            const status  = document.getElementById('dbUpdateStatus');
            const log     = document.getElementById('dbUpdateLog');
            const doneBox = document.getElementById('dbUpdateDone');
            const applyUrl = '<?= root.admin ?>/updates/database/apply';

            function addLog(text, ok) {
                const line = document.createElement('div');
                line.textContent = (ok ? '✓ ' : '✗ ') + text;
                line.className = ok ? 'text-green-600' : 'text-red-600';
                log.appendChild(line);
                log.scrollTop = log.scrollHeight;
            }

            async function applyChange(change) {
                const body = new URLSearchParams({
                    kind: change.kind,
                    table: change.table || '',
                    column: change.column || '',
                    module: change.module || '',
                    gateway: change.gateway || '',
                    type: change.type || ''
                });
                const res = await fetch(applyUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body
                });
                return res.json();
            }

            btn.addEventListener('click', async function () {
                if (!confirm('Apply ' + changes.length + ' database change(s)? Missing tables and columns will be added.')) return;

                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
                wrap.classList.remove('hidden');

                let done = 0, failed = 0;
                for (const change of changes) {
                    status.textContent = change.label;
                    try {
                        const r = await applyChange(change);
                        if (r.success) { addLog(r.message || change.label, true); }
                        else { failed++; addLog((r.message || 'Failed') + ' — ' + change.label, false); }
                    } catch (e) {
                        failed++; addLog(e.message + ' — ' + change.label, false);
                    }
                    done++;
                    const percent = Math.round((done / changes.length) * 100);
                    bar.style.width = percent + '%';
                    pct.textContent = percent + '%';
                }

                status.textContent = 'Finished';
                doneBox.classList.remove('hidden');
                doneBox.innerHTML = failed === 0
                    ? '✅ Database updated successfully — ' + done + ' change(s) applied. <a href="" class="underline font-medium">Reload</a>'
                    : '⚠️ Completed with ' + failed + ' error(s). ' + (done - failed) + ' applied. Check the log above.';
                if (failed === 0) {
                    doneBox.classList.add('text-green-700');
                } else {
                    doneBox.className = 'mt-4 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3';
                }
            });
        })();
        </script>
    <?php endif; ?>
</div>
