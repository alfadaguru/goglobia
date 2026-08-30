<?php
/**
 * Hotelbeds - Imported Hotels Browser
 * Renders the Contents tab from the configured Hotelbeds content database.
 */

global $db;

$_hb_db = $db;
$_hb_error = '';
$_hb_tableExists = false;
$_hb_hotelCount = 0;
$_hb_destinationCount = 0;
$_hb_countryCount = 0;
$_hb_lastImport = null;

try {
    $_hb_module = isset($db) ? $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelbeds',
        'type' => 'stays'
    ]) : null;

    if ($_hb_module && !empty($_hb_module['host']) && !empty($_hb_module['database'])) {
        $_hb_db = new \Medoo\Medoo([
            'type' => 'mysql',
            'host' => $_hb_module['host'],
            'database' => $_hb_module['database'],
            'username' => $_hb_module['username'],
            'password' => $_hb_module['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }

    $_hb_tableExists = (bool) $_hb_db->query("SHOW TABLES LIKE 'hotelbeds_hotels'")->fetch();
    if ($_hb_tableExists) {
        try {
            $_hb_row = $_hb_db->query(
                "SELECT COUNT(DISTINCT hotel_code) AS c FROM hotelbeds_hotels WHERE hotel_code IS NOT NULL AND hotel_code != ''"
            )->fetch(\PDO::FETCH_ASSOC);
            $_hb_hotelCount = (int) ($_hb_row['c'] ?? 0);
        } catch (\Throwable $e) {
            $_hb_hotelCount = (int) $_hb_db->count('hotelbeds_hotels');
        }
        try { $_hb_destinationCount = (int) $_hb_db->count('hotelbeds_destinations'); } catch (\Throwable $e) {}
        try { $_hb_countryCount = (int) $_hb_db->count('hotelbeds_countries'); } catch (\Throwable $e) {}
        try {
            $_hb_lastImport = $_hb_db->get('hotelbeds_import_log', ['status', 'updated_at', 'completed_at'], [
                'ORDER' => ['id' => 'DESC']
            ]);
        } catch (\Throwable $e) {}
    }
} catch (\Throwable $e) {
    $_hb_error = $e->getMessage();
}
?>

<?php if ($_hb_error): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-5 text-sm">
    <?= htmlspecialchars($_hb_error) ?>
</div>
<?php endif; ?>

<?php if (!$_hb_tableExists || $_hb_hotelCount < 1): ?>
<div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
    <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">hotel</span>
    <p class="text-sm font-semibold text-gray-700 mb-1">No hotels imported yet</p>
    <p class="text-xs text-gray-500 mb-4">Go to the <strong>Import</strong> tab to import hotel content from the Hotelbeds API.</p>
    <button type="button" onclick="switchTab('import')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
        <span class="material-symbols-outlined text-sm">upload_file</span>
        Go to Import
    </button>
</div>
<?php else:
    $crudInst = crud();
    $crudInst->db = $_hb_db;
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Hotels</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hb_hotelCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Destinations</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hb_destinationCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Countries</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hb_countryCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Last Sync</div>
        <div class="text-sm font-semibold text-gray-900">
            <?php
            $_hb_syncTime = $_hb_lastImport['completed_at'] ?? $_hb_lastImport['updated_at'] ?? null;
            echo $_hb_syncTime ? htmlspecialchars(date('M d, Y h:i A', strtotime($_hb_syncTime))) : 'Never';
            ?>
        </div>
    </div>
</div>

<?php
    echo $crudInst
        ->table('hotelbeds_hotels')
        ->title('Hotelbeds Hotels')
        ->col('hotel_code,name,city,country_code,destination_code,category_name,ranking')
        ->id_column('hotel_code')
        ->label([
            'hotel_code' => 'Hotel Code',
            'name' => 'Hotel Name',
            'city' => 'City',
            'country_code' => 'Country',
            'destination_code' => 'Destination',
            'category_name' => 'Category',
            'ranking' => 'Ranking',
        ])
        ->order('id', 'DESC')
        ->perPage(25)
        ->actions([
            'add' => false,
            'edit' => false,
            'view' => false,
            'delete' => false,
            'status' => false,
            'search' => false,
            'bulk_delete' => false,
        ])
        ->render();
endif;
