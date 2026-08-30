<?php @$SECURE or die('Access Denied!');
// ToursBMS — Contents tab: summary of imported content (from the module DB).
require_once dirname(__DIR__) . '/api.php';
$tb_error = '';
$tb_products = $tb_active = $tb_regions = $tb_mapped = $tb_types = 0;
$tb_lastSync = null; $tb_sample = [];
try {
    $mdb = _toursbms_db($db);
    $tb_products = $mdb->count('products');
    $tb_active   = $mdb->count('products', ['status' => 1]);
    $tb_regions  = $mdb->count('regions');
    $tb_mapped   = $mdb->count('regions', ['location_id[!]' => null]);
    $tb_types    = $mdb->count('types');
    $tb_lastSync = $mdb->get('sync_log', ['sync_type', 'products', 'created_at'], ['ORDER' => ['id' => 'DESC']]);
    $tb_sample   = $mdb->select('products',
        ['product_id', 'name', 'departure_region_name', 'settlement_currency', 'status', 'updated_at'],
        ['ORDER' => ['updated_at' => 'DESC'], 'LIMIT' => 15]) ?: [];
} catch (\Throwable $e) {
    $tb_error = $e->getMessage();
}
?>
<?php if ($tb_error): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-5 text-sm">
    <?= htmlspecialchars($tb_error) ?>
</div>
<?php endif; ?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-600 text-lg">inventory_2</span>
        <h3 class="text-sm font-semibold text-gray-900">Imported Content</h3>
    </div>
    <div class="p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Products</div><div class="text-lg font-bold text-gray-900"><?= (int)$tb_products ?> <span class="text-xs font-normal text-gray-400">(<?= (int)$tb_active ?> active)</span></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Regions</div><div class="text-lg font-bold text-gray-900"><?= (int)$tb_regions ?> <span class="text-xs font-normal text-gray-400">(<?= (int)$tb_mapped ?> mapped)</span></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Product Types</div><div class="text-lg font-bold text-gray-900"><?= (int)$tb_types ?></div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Last Sync</div><div class="text-sm font-semibold text-gray-900"><?= $tb_lastSync ? htmlspecialchars($tb_lastSync['sync_type'] . ' · ' . $tb_lastSync['created_at']) : '—' ?></div></div>
        </div>

        <?php if ($tb_sample): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
                    <th class="py-2 pr-3">Product ID</th><th class="py-2 pr-3">Name</th>
                    <th class="py-2 pr-3">Region</th><th class="py-2 pr-3">Currency</th><th class="py-2">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tb_sample as $r): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($r['product_id']) ?></td>
                        <td class="py-2 pr-3"><?= htmlspecialchars(mb_strimwidth((string)$r['name'], 0, 60, '…')) ?></td>
                        <td class="py-2 pr-3"><?= htmlspecialchars((string)$r['departure_region_name']) ?></td>
                        <td class="py-2 pr-3"><?= htmlspecialchars((string)$r['settlement_currency']) ?></td>
                        <td class="py-2"><span class="text-xs px-2 py-0.5 rounded <?= $r['status'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $r['status'] ? 'active' : 'inactive' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-2">Showing latest <?= count($tb_sample) ?> of <?= (int)$tb_products ?> products.</p>
        <?php else: ?>
        <div class="text-center py-8">
            <span class="material-symbols-outlined text-gray-300 text-4xl block mb-2">inventory_2</span>
            <p class="text-sm text-gray-500">No products imported yet. Run a Full Import from the Import tab.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
