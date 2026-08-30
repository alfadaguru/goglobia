<?php
/**
 * Agoda � Imported Hotels Browser
 * Uses the app CRUD library with a temporary DB swap for separate-DB support.
 */

global $db;
$_ag_mainDb = $db;

// Connect to agoda DB (separate or fall back to main)
$_ag_mod = @$db->get('modules', ['host','database','username','password'], ['name'=>'agoda','type'=>'stays']);
if ($_ag_mod && !empty($_ag_mod['host']) && !empty($_ag_mod['database'])) {
    try {
        $db = new Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $_ag_mod['host'],
            'database'  => $_ag_mod['database'],
            'username'  => $_ag_mod['username'],
            'password'  => $_ag_mod['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    } catch (Exception $e) {
        $db = $_ag_mainDb;
    }
}

// Check table exists before rendering
$_ag_tableExists = false;
try {
    $db->query("SELECT 1 FROM agoda_hotels LIMIT 1");
    $_ag_tableExists = true;
} catch (Exception $e) {}

if (!$_ag_tableExists): ?>
<div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
    <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">hotel</span>
    <p class="text-sm font-semibold text-gray-700 mb-1">No hotels imported yet</p>
    <p class="text-xs text-gray-500 mb-4">Go to the <strong>Import</strong> tab to import hotel data from the Agoda CSV file.</p>
    <button type="button" onclick="switchTab('import')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
        <span class="material-symbols-outlined text-sm">upload_file</span>
        Go to Import
    </button>
</div>
<?php else:
    echo crud()
        ->table('agoda_hotels')
        ->title('Agoda Hotels')
        ->col('hotel_id,hotel_name,city,country,stars,rating,latitude,longitude')
        ->id_column('hotel_id')
        ->label([
            'hotel_id'   => 'Hotel ID',
            'hotel_name' => 'Hotel Name',
            'city'       => 'City',
            'country'    => 'Country',
            'stars'      => 'Stars',
            'rating'     => 'Rating',
            'latitude'   => 'Lat',
            'longitude'  => 'Lng',
        ])
        ->order('hotel_id', 'ASC')
        ->actions([
            'add'    => false,
            'view'   => false,
            'edit'   => false,
            'delete' => false,
            'search' => false,
        ])
        ->render();
endif;

// Restore main DB
$db = $_ag_mainDb;
