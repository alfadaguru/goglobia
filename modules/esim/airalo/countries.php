<?php
@$SECURE or die('Access Denied!');

global $crud;

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'disabled'], true)) {
    $statusFilter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
if ($statusFilter === 'active') {
    $where['status'] = 1;
} elseif ($statusFilter === 'disabled') {
    $where['status'] = 0;
}
if ($search !== '') {
    $where['OR'] = [
        'nicename[~]' => $search,
        'iso[~]' => strtoupper($search),
    ];
}

$rulesAgg = $db->query('SELECT country, COUNT(*) AS total, SUM(CASE WHEN featured = 1 AND status = 1 THEN 1 ELSE 0 END) AS featured_total FROM airalo_packages GROUP BY country')->fetchAll(PDO::FETCH_ASSOC);
$rulesCount = [];
$featuredCount = [];
foreach ((array) $rulesAgg as $row) {
    $iso = strtoupper((string) ($row['country'] ?? ''));
    if ($iso === '') {
        continue;
    }
    $rulesCount[$iso] = (int) ($row['total'] ?? 0);
    $featuredCount[$iso] = (int) ($row['featured_total'] ?? 0);
}

$crudTable = $crud->table('airalo_countries')
    ->title('Airalo Countries')
    ->col('iso,nicename,status')
    ->label([
        'iso' => 'ISO',
        'nicename' => 'Country',
        'status' => 'Status',
    ])
    ->row([
        'nicename' => function ($row) use ($rulesCount, $featuredCount) {
            $iso = strtoupper((string) ($row['iso'] ?? ''));
            $name = htmlspecialchars((string) ($row['nicename'] ?? $iso));
            $mapped = (int) ($rulesCount[$iso] ?? 0);
            $featured = (int) ($featuredCount[$iso] ?? 0);
            return '<div class="flex flex-col gap-1"><div class="font-medium text-slate-800">' . $name . '</div><div class="text-xs text-slate-500">Mapped packages: ' . $mapped . ' | Featured active: ' . $featured . '</div></div>';
        },
    ])
    ->actions([
        'add' => false,
        'view' => false,
        'edit' => false,
        'delete' => false,
        'status' => true,
    ])
    ->where($where)
    ->order('nicename', 'ASC')
    ->custom_button([
        'label' => 'Packages',
        'icon' => 'tune',
        'url' => root . admin . '/settings/modules/airalo-packages/edit/{iso}',
        'class' => 'text-blue-600 hover:bg-blue-100',
        'title' => 'Edit package rules for this country',
        'position' => 'before_edit',
    ]);
?>

<div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
        <p class="text-xs text-slate-500">Enable/disable countries and open package rules from the same table.</p>
    </div>
    <div class="p-4">
        <?= $crudTable->render(); ?>
    </div>
</div>
