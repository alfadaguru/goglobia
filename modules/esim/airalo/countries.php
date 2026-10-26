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
        'label' => 'Live packages',
        'icon' => 'public',
        'url' => '#',
        'onclick' => "airaloViewPackages('{iso}','{nicename}'); return false;",
        'class' => 'text-emerald-600 hover:bg-emerald-100',
        'title' => 'View Airalo\'s live packages + sell prices for this country',
        'position' => 'before_edit',
    ])
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
            (Airalo has no countries endpoint) — new countries arrive <strong>enabled</strong>.
        </p>
        <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
            <button type="button" id="airaloCountryEnableAllBtn"
                    class="btn secondary inline-flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">check_circle</span>
                <span>Enable all</span>
            </button>
            <button type="button" id="airaloCountryDisableAllBtn"
                    class="btn secondary inline-flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">block</span>
                <span>Disable all</span>
            </button>
            <button type="button" id="airaloCountrySyncBtn"
                    class="btn inline-flex items-center gap-2 text-sm whitespace-nowrap">
                <span id="airaloCountrySyncIcon" class="material-symbols-outlined text-base">sync</span>
                <span id="airaloCountrySyncLabel">Sync from Airalo</span>
            </button>
        </div>
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
        if (!window.confirm('Fetch the latest sellable countries from Airalo?\n\nNew countries are added enabled — your existing per-country choices are preserved.')) {
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

    // Bulk enable / disable all countries.
    function bulk(id, url, confirmMsg) {
        var b = document.getElementById(id);
        if (!b) return;
        b.addEventListener('click', function () {
            if (b.disabled) return;
            if (!window.confirm(confirmMsg)) return;
            b.disabled = true;
            var txt = b.querySelector('span:last-child');
            var orig = txt ? txt.textContent : '';
            if (txt) txt.textContent = 'Working…';
            fetch(url, { method: 'POST', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.alert(data.message || 'Done.');
                        window.location.reload();
                    } else {
                        window.alert((data && data.message) ? data.message : 'Action failed. Please try again.');
                        b.disabled = false; if (txt) txt.textContent = orig;
                    }
                })
                .catch(function () {
                    window.alert('Action failed: network error.');
                    b.disabled = false; if (txt) txt.textContent = orig;
                });
        });
    }
    bulk('airaloCountryEnableAllBtn',  '<?= root . admin ?>/settings/modules/airalo-countries/enable-all',  'Enable ALL Airalo countries for sale?');
    bulk('airaloCountryDisableAllBtn', '<?= root . admin ?>/settings/modules/airalo-countries/disable-all', 'Disable ALL Airalo countries (take everything off sale)?');
})();
</script>

<!-- Live packages modal -->
<div id="airaloPkgModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
            <h3 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600">public</span>
                <span id="airaloPkgTitle">Live packages</span>
            </h3>
            <button type="button" onclick="airaloClosePackages()" class="text-slate-400 hover:text-slate-700">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-5 overflow-auto" id="airaloPkgBody">
            <div class="text-sm text-slate-500">Loading…</div>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 text-xs text-slate-400" id="airaloPkgFoot"></div>
    </div>
</div>

<script>
function airaloClosePackages() {
    var m = document.getElementById('airaloPkgModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
function airaloViewPackages(iso, name) {
    var m = document.getElementById('airaloPkgModal');
    var body = document.getElementById('airaloPkgBody');
    var foot = document.getElementById('airaloPkgFoot');
    document.getElementById('airaloPkgTitle').textContent = 'Live packages — ' + (name || iso);
    body.innerHTML = '<div class="text-sm text-slate-500">Loading live packages from Airalo…</div>';
    foot.textContent = '';
    m.classList.remove('hidden'); m.classList.add('flex');

    fetch('<?= root . admin ?>/settings/modules/airalo-countries/packages/' + encodeURIComponent(iso), {
        headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data || data.status !== 'success') {
            body.innerHTML = '<div class="text-sm text-red-600">' + ((data && data.message) ? data.message : 'Failed to load packages.') + '</div>';
            return;
        }
        if (!data.packages || !data.packages.length) {
            body.innerHTML = '<div class="text-sm text-slate-500">Airalo returned no local packages for this country.</div>';
            foot.textContent = data.note || '';
            return;
        }
        var cur = data.currency || '';
        var rows = data.packages.map(function (p) {
            var net = (p.net_price != null) ? (cur + ' ' + Number(p.net_price).toFixed(2)) : '—';
            var sell = (p.sell_price != null) ? (cur + ' ' + Number(p.sell_price).toFixed(2)) : '<span class="text-amber-600">no rule</span>';
            var mk = (p.markup != null) ? (cur + ' ' + Number(p.markup).toFixed(2)) : '—';
            var voiceText = [];
            if (p.voice) voiceText.push(p.voice + ' min');
            if (p.text) voiceText.push(p.text + ' SMS');
            return '<tr class="border-b border-slate-100">' +
                '<td class="px-3 py-2 text-sm text-slate-800">' + (p.title || '') +
                    (voiceText.length ? '<div class="text-xs text-slate-400">' + voiceText.join(' · ') + '</div>' : '') + '</td>' +
                '<td class="px-3 py-2 text-sm text-slate-700 whitespace-nowrap">' + (p.data || '') + '</td>' +
                '<td class="px-3 py-2 text-sm text-slate-700 whitespace-nowrap">' + (p.validity || '') + '</td>' +
                '<td class="px-3 py-2 text-sm text-slate-500 whitespace-nowrap text-right">' + net + '</td>' +
                '<td class="px-3 py-2 text-sm text-slate-500 whitespace-nowrap text-right">' + mk + '</td>' +
                '<td class="px-3 py-2 text-sm font-semibold text-slate-900 whitespace-nowrap text-right">' + sell + '</td>' +
                '</tr>';
        }).join('');
        body.innerHTML =
            '<div class="overflow-x-auto"><table class="min-w-full"><thead><tr class="text-left text-xs font-medium text-slate-500 uppercase border-b border-slate-200">' +
            '<th class="px-3 py-2">Package</th><th class="px-3 py-2">Data</th><th class="px-3 py-2">Validity</th>' +
            '<th class="px-3 py-2 text-right">Airalo net</th><th class="px-3 py-2 text-right">Your markup</th><th class="px-3 py-2 text-right">Sell price</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        foot.textContent = (data.count + ' package(s) · ' + (data.env || '') + ' · ' + (data.note || ''));
    })
    .catch(function () {
        body.innerHTML = '<div class="text-sm text-red-600">Network error loading packages.</div>';
    });
}
</script>
