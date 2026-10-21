<?php
// app/views/admin/discounts/manage-discount.php — add/edit a discount rule.
// Alpine-driven: type picker (percentage/fixed/tiered/override) + scope picker
// (all/service/product/provider). Product scope reuses the promo item-search.
// $discount (row|null) + $mode set by the route.
@$SECURE or die('Access Denied!');

$d = is_array($discount) ? $discount : [];
$isEdit = !empty($d['id']);

$activeModules = $db->select('modules', ['type'], ['status' => 1, 'active' => 1]) ?: [];
$moduleTypes   = array_values(array_unique(array_column($activeModules, 'type')));
sort($moduleTypes);

// Distinct integration supplier names (for provider scope), grouped nothing-fancy.
$providers = $db->select('modules', ['name', 'type'], ['status' => 1]) ?: [];

$tiers = [];
if (!empty($d['tiers_json'])) { $tiers = json_decode((string) $d['tiers_json'], true) ?: []; }
$productIds = '';
if (!empty($d['scope_product_ids'])) {
    $pids = json_decode((string) $d['scope_product_ids'], true);
    if (is_array($pids)) { $productIds = implode(',', array_map('intval', $pids)); }
}
$v = fn($k, $def = '') => htmlspecialchars((string) ($d[$k] ?? $def), ENT_QUOTES);
?>
<div class="container my-4" style="max-width:860px"
     x-data='discountForm(<?= json_encode([
        "type"     => $d["discount_type"] ?? "percentage",
        "scope"    => $d["scope_type"] ?? "all",
        "tiers"    => array_values($tiers),
     ]) ?>)'>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/discounts" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $isEdit ? 'Edit discount' : 'New discount' ?></h1>
  </div>

  <form method="POST" action="<?= root.admin ?>/discounts/save" class="space-y-5">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="id" value="<?= (int) ($d['id'] ?? 0) ?>">

    <!-- Basics -->
    <div class="rounded-xl border border-gray-100 p-4 space-y-3">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Name *</label>
        <input type="text" name="name" value="<?= $v('name') ?>" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Description</label>
        <input type="text" name="description" value="<?= $v('description') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
    </div>

    <!-- Type -->
    <div class="rounded-xl border border-gray-100 p-4 space-y-3">
      <h3 class="text-sm font-bold text-gray-900">Discount type</h3>
      <select name="discount_type" x-model="type" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value="percentage">Percentage (% off)</option>
        <option value="fixed">Fixed amount off</option>
        <option value="tiered">Tiered (by order amount)</option>
        <option value="override">Override (flat % for this scope)</option>
      </select>

      <div x-show="type==='percentage' || type==='override'">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Percent off</label>
        <input type="number" step="0.01" min="0" max="100" name="discount_value" value="<?= $v('discount_value') ?>" class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div x-show="type==='fixed'">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Amount off</label>
        <input type="number" step="0.01" min="0" name="discount_value" value="<?= $v('discount_value') ?>" class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>

      <!-- Tiers -->
      <div x-show="type==='tiered'" class="space-y-2">
        <p class="text-xs text-gray-500">Highest matching tier (by min order amount) applies.</p>
        <template x-for="(t,i) in tiers" :key="i">
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">min</span>
            <input type="number" step="0.01" :name="'tier_min['+i+']'" x-model="t.min" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm">
            <select :name="'tier_type['+i+']'" x-model="t.type" class="rounded border border-gray-300 px-2 py-1.5 text-sm">
              <option value="percentage">%</option>
              <option value="fixed">fixed</option>
            </select>
            <input type="number" step="0.01" :name="'tier_value['+i+']'" x-model="t.value" placeholder="value" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm">
            <button type="button" @click="tiers.splice(i,1)" class="text-red-500 text-xs">remove</button>
          </div>
        </template>
        <button type="button" @click="tiers.push({min:0,type:'percentage',value:0})" class="text-blue-600 text-sm font-medium">+ Add tier</button>
      </div>
    </div>

    <!-- Scope -->
    <div class="rounded-xl border border-gray-100 p-4 space-y-3">
      <h3 class="text-sm font-bold text-gray-900">Applies to</h3>
      <select name="scope_type" x-model="scope" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <option value="all">All services</option>
        <option value="service">One service</option>
        <option value="product">A specific product</option>
        <option value="provider">A provider / supplier</option>
      </select>

      <div x-show="scope==='service' || scope==='product'">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Service</label>
        <select name="scope_module" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <?php foreach ($moduleTypes as $mt): ?>
            <option value="<?= htmlspecialchars($mt, ENT_QUOTES) ?>" <?= ($d['scope_module'] ?? '') === $mt ? 'selected' : '' ?>><?= ucfirst($mt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div x-show="scope==='product'">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Product IDs <span class="text-gray-400 font-normal">(comma-separated local IDs for the service above)</span></label>
        <input type="text" name="scope_product_ids" value="<?= htmlspecialchars($productIds, ENT_QUOTES) ?>" placeholder="e.g. 12, 45, 78" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <p class="text-xs text-gray-400 mt-1">Product-level targeting works for services whose booking passes a product id (stays, tours, cars, umrah, visa).</p>
      </div>

      <div x-show="scope==='provider'" class="space-y-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Integration supplier (name)</label>
        <input type="text" name="scope_provider" value="<?= $v('scope_provider') ?>" list="dsc-providers" placeholder="e.g. duffel, hotelbeds, airalo" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <datalist id="dsc-providers">
          <?php foreach ($providers as $p): ?><option value="<?= htmlspecialchars((string) $p['name'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $p['name'] . ' (' . $p['type'] . ')') ?></option><?php endforeach; ?>
        </datalist>
        <label class="block text-xs font-semibold text-gray-600 mb-1 mt-2">OR own-inventory seller (user_id)</label>
        <input type="text" name="scope_owner" value="<?= $v('scope_owner') ?>" placeholder="supplier user_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <p class="text-xs text-amber-700 mt-1">Discounts on a portal-supplier's own listings are <strong>platform-funded</strong> — the supplier is still paid in full.</p>
      </div>
    </div>

    <!-- Controls -->
    <div class="rounded-xl border border-gray-100 p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <h3 class="text-sm font-bold text-gray-900 sm:col-span-2">Controls</h3>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Audience</label>
        <select name="audience" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <?php foreach (['' => 'Everyone', 'customer' => 'Customers', 'agent' => 'Agents', 'supplier' => 'Suppliers', 'staff' => 'Staff'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= (string) ($d['audience'] ?? '') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Currency <span class="text-gray-400 font-normal">(for fixed/caps)</span></label>
        <input type="text" name="currency" maxlength="3" value="<?= $v('currency') ?>" placeholder="e.g. USD" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Min order amount</label>
        <input type="number" step="0.01" min="0" name="min_order_amount" value="<?= $v('min_order_amount') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Max discount cap</label>
        <input type="number" step="0.01" min="0" name="max_discount_amount" value="<?= $v('max_discount_amount') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Margin floor % <span class="text-gray-400 font-normal">(keep this much over net)</span></label>
        <input type="number" step="0.01" min="0" name="margin_floor_pct" value="<?= $v('margin_floor_pct', '0') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Priority <span class="text-gray-400 font-normal">(higher wins ties)</span></label>
        <input type="number" name="priority" value="<?= $v('priority', '0') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Starts</label>
        <input type="datetime-local" name="start_date" value="<?= !empty($d['start_date']) ? date('Y-m-d\TH:i', strtotime((string) $d['start_date'])) : '' ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Ends</label>
        <input type="datetime-local" name="end_date" value="<?= !empty($d['end_date']) ? date('Y-m-d\TH:i', strtotime((string) $d['end_date'])) : '' ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>
      <label class="flex items-center gap-2 sm:col-span-2 mt-1">
        <input type="checkbox" name="status" value="1" <?= (!$isEdit || (int) ($d['status'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span class="text-sm text-gray-800">Active</span>
      </label>
    </div>

    <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black transition"><?= $isEdit ? 'Save discount' : 'Create discount' ?></button>
  </form>
</div>

<script>
function discountForm(init) {
  return {
    type:  init.type  || 'percentage',
    scope: init.scope || 'all',
    tiers: Array.isArray(init.tiers) ? init.tiers.map(t => ({min: t.min ?? 0, type: t.type ?? 'percentage', value: t.value ?? 0})) : [],
  };
}
</script>
