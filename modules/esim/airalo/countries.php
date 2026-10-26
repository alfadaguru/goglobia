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
    <div class="px-5 py-4 border-b border-slate-200 bg-slate-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p class="text-xs text-slate-500">
            Enable/disable countries and open package rules from the same table.
            The sellable-country list comes from Airalo's live package feed
            (Airalo has no countries endpoint) — new countries arrive <strong>disabled</strong>.
        </p>
        <button type="button" id="airaloCountrySyncBtn"
                class="btn inline-flex items-center gap-2 text-sm whitespace-nowrap self-start sm:self-auto">
            <span id="airaloCountrySyncIcon" class="material-symbols-outlined text-base">sync</span>
            <span id="airaloCountrySyncLabel">Sync from Airalo</span>
        </button>
    </div>
    <div class="p-4">
        <?= $crudTable->render(); ?>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('airaloCountrySyncBtn');
    if (!btn) return;
    var icon = document.getElementById('airaloCountrySyncIcon');
    var label = document.getElementById('airaloCountrySyncLabel');

    btn.addEventListener('click', function () {
        if (btn.disabled) return;
        if (!window.confirm('Fetch the latest sellable countries from Airalo?\n\nNew countries are added disabled — your enabled/disabled choices are preserved.')) {
            return;
        }
        btn.disabled = true;
        icon.classList.add('animate-spin');
        var original = label.textContent;
        label.textContent = 'Syncing…';

        // app.js global interceptor attaches the X-CSRF-TOKEN header for same-origin
        // non-GET fetches, so the CSRF::guard() on the endpoint is satisfied.
        fetch('<?= root . admin ?>/settings/modules/airalo-countries/sync', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                window.alert(data.message || 'Countries synced.');
                window.location.reload();
            } else {
                window.alert((data && data.message) ? data.message : 'Sync failed. Please try again.');
                btn.disabled = false;
                icon.classList.remove('animate-spin');
                label.textContent = original;
            }
        })
        .catch(function () {
            window.alert('Sync failed: network error.');
            btn.disabled = false;
            icon.classList.remove('animate-spin');
            label.textContent = original;
        });
    });
})();
</script>
