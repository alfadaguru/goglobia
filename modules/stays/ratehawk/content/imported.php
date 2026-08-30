<?php
/**
 * RateHawk – Imported Hotels Browser
 * Uses the app CRUD library with ratehawk's separate DB connection.
 */

global $db;

$_rh_db = null;
try {
    $_rh_module = isset($db) ? $db->get('modules', ['host','database','username','password'], [
        'name' => 'ratehawk',
        'type' => 'stays'
    ]) : null;

    if ($_rh_module && !empty($_rh_module['database'])) {
        $_rh_db = new \Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $_rh_module['host'] ?? 'localhost',
            'database'  => $_rh_module['database'],
            'username'  => $_rh_module['username'],
            'password'  => $_rh_module['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }
} catch (\Throwable $e) {
    $_rh_db = null;
}

$_rh_hasData    = false;
$_rh_hotelCount = 0;
if ($_rh_db) {
    try {
        $_rh_tableExists = $_rh_db->query("SHOW TABLES LIKE 'ratehawk_hotels'")->fetch();
        if ($_rh_tableExists) {
            $_rh_hotelCount = (int)$_rh_db->count('ratehawk_hotels');
            $_rh_hasData    = $_rh_hotelCount > 0;
        }
    } catch (\Throwable $e) {}
}

if (!$_rh_hasData): ?>
<div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
    <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">hotel</span>
    <p class="text-sm font-semibold text-gray-700 mb-1">No hotels imported yet</p>
    <p class="text-xs text-gray-500 mb-4">Go to the <strong>Import</strong> tab to import hotel data from the RateHawk JSONL file.</p>
    <button type="button" onclick="switchTab('import')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
        <span class="material-symbols-outlined text-sm">upload_file</span>
        Go to Import
    </button>
</div>
<?php else:
    $crudInst = crud();
    $crudInst->db = $_rh_db;
    echo $crudInst
        ->table('ratehawk_hotels')
        ->title('RateHawk Hotels')
        ->col('id,name,city,country,star_rating,rating,kind')
        ->label([
            'id'          => '#',
            'name'        => 'Hotel Name',
            'city'        => 'City',
            'country'     => 'Country',
            'star_rating' => 'Stars',
            'rating'      => 'Rating',
            'kind'        => 'Type',
        ])
        ->row([
            'star_rating' => '<span class="text-yellow-500 font-medium">{{star_rating}}★</span>',
            'rating'      => '<span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{rating}}</span>',
        ])
        ->order('id', 'DESC')
        ->perPage(25)
        ->actions([
            'add'         => false,
            'edit'        => false,
            'view'        => false,
            'delete'      => false,
            'status'      => false,
            'search'      => false,
            'bulk_delete' => false,
        ])
        ->render();
endif;
