<?php
/**
 * Hotelston - Imported Hotels Browser
 * Renders the Contents tab from the configured Hotelston content database.
 */

global $db;

$_hs_db = $db;
$_hs_error = '';
$_hs_tableExists = false;
$_hs_hotelCount = 0;
$_hs_cityCount = 0;
$_hs_countryCount = 0;
$_hs_lastImport = null;

try {
    $_hs_module = isset($db) ? $db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelston',
        'type' => 'stays'
    ]) : null;

    if ($_hs_module && !empty($_hs_module['host']) && !empty($_hs_module['database'])) {
        $_hs_db = new \Medoo\Medoo([
            'type' => 'mysql',
            'host' => $_hs_module['host'],
            'database' => $_hs_module['database'],
            'username' => $_hs_module['username'],
            'password' => $_hs_module['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }

    $_hs_tableExists = (bool) $_hs_db->query("SHOW TABLES LIKE 'hotelston_hotels'")->fetch();
    if ($_hs_tableExists) {
        $_hs_hotelCount = (int) $_hs_db->count('hotelston_hotels');
        try {
            $_hs_cityCount = (int) ($_hs_db->query(
                "SELECT COUNT(DISTINCT city_id) FROM hotelston_hotels WHERE city_id IS NOT NULL AND city_id > 0"
            )->fetchColumn() ?: 0);
            if ($_hs_cityCount < 1) {
                $_hs_cityCount = (int) ($_hs_db->query(
                    "SELECT COUNT(DISTINCT city) FROM hotelston_hotels WHERE city IS NOT NULL AND city != ''"
                )->fetchColumn() ?: 0);
            }
        } catch (\Throwable $e) {}
        try {
            $_hs_countryCount = (int) ($_hs_db->query(
                "SELECT COUNT(DISTINCT country_code) FROM hotelston_hotels WHERE country_code IS NOT NULL AND country_code != ''"
            )->fetchColumn() ?: 0);
            if ($_hs_countryCount < 1) {
                $_hs_countryCount = (int) ($_hs_db->query(
                    "SELECT COUNT(DISTINCT country) FROM hotelston_hotels WHERE country IS NOT NULL AND country != ''"
                )->fetchColumn() ?: 0);
            }
        } catch (\Throwable $e) {}
        try {
            $_hs_lastImport = $_hs_db->get('hotelston_import_progress', ['status', 'updated_at', 'completed_at'], [
                'ORDER' => ['id' => 'DESC']
            ]);
        } catch (\Throwable $e) {
            try {
                $_hs_lastImport = $_hs_db->get('hotelston_import_log', ['status', 'updated_at', 'completed_at'], [
                    'ORDER' => ['id' => 'DESC']
                ]);
            } catch (\Throwable $e2) {}
        }
    }
} catch (\Throwable $e) {
    $_hs_error = $e->getMessage();
}
?>

<?php if ($_hs_error): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-5 text-sm">
    <?= htmlspecialchars($_hs_error) ?>
</div>
<?php endif; ?>

<?php if (!$_hs_tableExists || $_hs_hotelCount < 1): ?>
<div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
    <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">hotel</span>
    <p class="text-sm font-semibold text-gray-700 mb-1">No hotels imported yet</p>
    <p class="text-xs text-gray-500 mb-4">Go to the <strong>Import</strong> tab to import hotel content from the Hotelston API.</p>
    <button type="button" onclick="switchTab('import')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
        <span class="material-symbols-outlined text-sm">upload_file</span>
        Go to Import
    </button>
</div>
<?php else:
    $crudInst = crud();
    $crudInst->db = $_hs_db;
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Hotels</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hs_hotelCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Cities</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hs_cityCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Countries</div>
        <div class="text-xl font-bold text-gray-900"><?= number_format($_hs_countryCount) ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500">Last Sync</div>
        <div class="text-sm font-semibold text-gray-900">
            <?php
            $_hs_syncTime = $_hs_lastImport['completed_at'] ?? $_hs_lastImport['updated_at'] ?? null;
            echo $_hs_syncTime ? htmlspecialchars(date('M d, Y h:i A', strtotime($_hs_syncTime))) : 'Never';
            ?>
        </div>
    </div>
</div>

<?php
    echo $crudInst
        ->table('hotelston_hotels')
        ->title('Hotelston Hotels')
        ->col('hotel_id,name,city,country,email')
        ->id_column('hotel_id')
        ->label([
            'hotel_id' => 'Hotel ID',
            'name' => 'Hotel Name',
            'city' => 'City',
            'country' => 'Country',
            'email' => 'Email',
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