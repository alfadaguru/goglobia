<?php
@$SECURE or die('Access Denied!');

// $dbTable and $dbTablePk are provided by the route.
// A missing table sets a 404 status and leaves $dbTable non-existent.
$tableExists = (bool) $db->query(
    "SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$dbTable}'"
)->fetchColumn();

// Table meta for the header
$meta = $tableExists ? $db->query(
    "SELECT TABLE_ROWS AS rows_count, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes,
            ENGINE AS engine, TABLE_COLLATION AS collation
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$dbTable}'"
)->fetch(\PDO::FETCH_ASSOC) : null;

$colCount = $tableExists ? (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$dbTable}'"
)->fetchColumn() : 0;

$fmtSize = function ($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min((int)floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
};
?>
<div class="container my-4">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="<?= root.admin ?>/database" class="text-slate-400 hover:text-slate-600" title="Back to Database">
                    <span class="material-symbols-outlined align-middle">arrow_back</span>
                </a>
                <h1 class="text-2xl font-bold text-slate-800 font-mono"><?= htmlspecialchars($dbTable) ?></h1>
            </div>
            <?php if ($tableExists && $meta): ?>
            <p class="text-sm text-slate-600 mt-1">
                <?= $colCount ?> columns · ~<?= number_format((int)$meta['rows_count']) ?> rows ·
                <?= $fmtSize($meta['size_bytes']) ?> · <?= htmlspecialchars((string)$meta['engine']) ?> ·
                <?= htmlspecialchars((string)$meta['collation']) ?>
            </p>
            <?php endif; ?>
        </div>
        <a href="<?= root.admin ?>/database" class="btn secondary">
            <span class="material-symbols-outlined text-lg">list</span>
            All Tables
        </a>
    </div>

    <?php if (!$tableExists): ?>
        <div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
            <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">error</span>
            <h3 class="text-lg font-medium text-gray-900 mb-1">Table not found</h3>
            <p class="text-sm text-gray-500">No table named <span class="font-mono"><?= htmlspecialchars($dbTable) ?></span> exists in this database.</p>
        </div>
    <?php else: ?>
        <?php
        // Read-only browse of every column + row via the shared CRUD library.
        echo crud()->table($dbTable)
            ->title($dbTable)
            ->perPage(50)
            ->id_column($dbTablePk)
            ->order($dbTablePk, 'DESC')
            ->actions([
                'add'         => false,
                'edit'        => false,
                'view'        => false,
                'delete'      => false,
                'status'      => false,
                'bulk_delete' => false,
                'search'      => true,
            ])
            ->render();
        ?>
    <?php endif; ?>
</div>
