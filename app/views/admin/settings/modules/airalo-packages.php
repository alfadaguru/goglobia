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

$title = 'Airalo Country Package Mapping';
$description = 'Show all countries, filter them, and manage multiple package rules under each active country.';
?>

<div class="container py-6">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-4">
            <a href="<?= root . admin ?>/settings/modules#esim" class="btn secondary inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">Airalo Country Package Mapping</h1>
                <p class="text-sm text-slate-500">Use the CRUD table below to enable/disable countries and open package mapping for each active country.</p>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success mb-5' : 'alert-error mb-5' ?>">
            <span class="material-icon material-symbols-outlined">
                <?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?>
            </span>
            <p><?= htmlspecialchars($_SESSION['message']['text']) ?></p>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php
    echo $crud->table('airalo_countries')
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
        ->custom_button([
            'label' => 'Edit Packages',
            'icon' => 'tune',
            'url' => root . admin . '/settings/modules/airalo-packages/edit/{iso}',
            'class' => 'text-blue-600 hover:bg-blue-100',
            'title' => 'Edit package rules for this country',
            'position' => 'before_edit',
        ])
        ->render();
    ?>
</div>