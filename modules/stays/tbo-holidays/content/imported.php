<?php
require_once __DIR__ . '/../api.php';

try {
    $tboModule = tboHolidaysGetModule($db);
    $tboContentDb = tboHolidaysContentDb($tboModule);
    tboHolidaysCreateSchema($tboContentDb);

    // Point existing CRUD helper at TBO content DB (same style as gateways.php)
    $__crudPrevDb = crud()->db;
    crud()->db = $tboContentDb;

    echo '<div class="tbo-hotels-crud mb-5">';
    echo crud()->table('tbo_hotels')
        ->col('hotel_code,name,city_name,country_name,star_rating')
        ->title('TBO Holidays Hotels')
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
            'hotel_code' => 'Hotel Code',
            'name' => 'Hotel Name',
            'city_name' => 'City',
            'country_name' => 'Country',
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

<style>
/* Local layout fix for TBO hotels CRUD header (do not change app/lib/crud.php) */
.tbo-hotels-crud > .bg-white > .border-b > .flex {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 12px !important;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child {
    width: 100%;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child h2 {
    white-space: nowrap;
    line-height: 1.3;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:first-child p {
    white-space: nowrap;
    margin-top: 2px;
}
.tbo-hotels-crud > .bg-white > .border-b > .flex > div:last-child {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: 100% !important;
}
.tbo-hotels-crud > .bg-white > .border-b form {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: auto !important;
    flex: 1 1 auto;
}
.tbo-hotels-crud > .bg-white > .border-b .select,
.tbo-hotels-crud > .bg-white > .border-b .input {
    width: auto !important;
    max-width: none !important;
}
.tbo-hotels-crud > .bg-white > .border-b #columnToggleBtn {
    width: 170px !important;
    flex: 0 0 170px;
}
.tbo-hotels-crud > .bg-white > .border-b select[name="search_col"] {
    width: 150px !important;
    flex: 0 0 150px;
}
.tbo-hotels-crud > .bg-white > .border-b input[name="q"] {
    width: 220px !important;
    flex: 1 1 180px;
    min-width: 160px;
}
</style>
