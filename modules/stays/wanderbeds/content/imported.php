<?php
require_once __DIR__ . '/../api.php';

try {
    $wbModule = wanderbedsGetModule($db);
    $wbContentDb = wanderbedsContentDb($wbModule);
    wanderbedsCreateSchema($wbContentDb);

    $__crudPrevDb = crud()->db;
    crud()->db = $wbContentDb;

    echo '<div class="mb-5">';
    echo crud()->table('wb_hotels')
        ->col('hotel_id,name,city_name,country_code,star_rating')
        ->title('Wanderbeds Hotels')
        ->actions([
            'view' => false,
            'delete' => false,
            'add' => false,
            'edit' => false,
            'status' => false,
            'search' => true,
        ])
        ->where([])
        ->order('id', 'DESC')
        ->label([
            'hotel_id' => 'Hotel ID',
            'name' => 'Hotel Name',
            'city_name' => 'City',
            'country_code' => 'Country',
            'star_rating' => 'Stars',
        ])
        ->row([
            'star_rating' => function ($row) {
                $stars = (float) ($row['star_rating'] ?? 0);
                return '<span class="inline-block px-2 py-1 rounded bg-slate-100 text-slate-800 border border-slate-300 text-xs font-semibold">'
                    . htmlspecialchars((string) $stars)
                    . '</span>';
            },
        ])
        ->render();
    echo '</div>';

    crud()->db = $__crudPrevDb;
} catch (Exception $e) {
    echo '<div class="alert-error mb-4"><span class="material-symbols-outlined">error</span><div>'
        . htmlspecialchars($e->getMessage())
        . ' — configure content database above first.</div></div>';
}
?>
