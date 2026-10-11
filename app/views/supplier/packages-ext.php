<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Module 17 deepened — package builder (components), meal plans, folio post.
  $packages  = (isset($packages)  && is_array($packages))  ? $packages  : [];
  $itemsMap  = (isset($itemsMap)  && is_array($itemsMap))  ? $itemsMap  : [];
  $mealPlans = (isset($mealPlans) && is_array($mealPlans)) ? $mealPlans : [];
  $propMap   = (isset($propMap)   && is_array($propMap))   ? $propMap   : [];
  $propList  = (isset($propList)  && is_array($propList))  ? $propList  : [];
  $mealCodes = (isset($mealCodes) && is_array($mealCodes)) ? $mealCodes : [];
  $itemCats  = (isset($itemCats)  && is_array($itemCats))  ? $itemCats  : [];
  $canEdit   = !empty($canEdit);
  $canBill   = !empty($canBill);
  $base      = root . 'supplier/packages/builder';
  $fmt = function ($n) { return number_format((float) $n, 2); };
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Package builder &amp; meal plans</h1>
      <p class="text-sm text-gray-600">Decompose packages into priced components, define meal plans, and bill them to a live guest folio.</p>
    </div>
    <a href="<?= htmlspecialchars(root . 'supplier/packages') ?>" class="btn-secondary text-sm">Back to packages</a>
  </div>

  <?php if (empty($propList)): ?>
    <div class="card p-6 text-center text-sm text-gray-600">No properties yet. Add a property first.</div>
  <?php endif; ?>

  <!-- ===== PACKAGE COMPONENTS ===== -->
  <section class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900">Packages &amp; components</h2>
    <?php if (empty($packages)): ?>
      <div class="card p-6 text-center text-sm text-gray-600">No packages yet. Create one on the Packages page, then add components here.</div>
    <?php endif; ?>
    <?php foreach ($packages as $p): ?>
      <?php $pid = (int) $p['id']; $psid = (int) $p['stay_id']; $comps = $itemsMap[$pid] ?? []; $ctotal = 0.0; ?>
      <div class="card p-5 space-y-3">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></h3>
            <p class="text-xs text-gray-500"><?= htmlspecialchars($propMap[$psid] ?? ('Property #' . $psid)) ?> · base price <?= $fmt($p['price'] ?? 0) ?></p>
          </div>
        </div>
        <?php if (!empty($comps)): ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead><tr class="text-left text-gray-500 border-b"><th class="py-2 pr-3">Component</th><th class="py-2 pr-3">Category</th><th class="py-2 pr-3">Qty</th><th class="py-2 pr-3">Unit</th><th class="py-2 pr-3">Line</th><th class="py-2"></th></tr></thead>
              <tbody>
              <?php foreach ($comps as $c): ?>
                <?php $line = max(1, (int) ($c['qty'] ?? 1)) * (float) ($c['unit_price'] ?? 0); $ctotal += $line; ?>
                <tr class="border-b border-gray-100">
                  <td class="py-2 pr-3 text-gray-900"><?= htmlspecialchars((string) ($c['label'] ?? '')) ?></td>
                  <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($c['category'] ?? '')) ?></td>
                  <td class="py-2 pr-3 text-gray-600"><?= (int) ($c['qty'] ?? 1) ?></td>
                  <td class="py-2 pr-3 text-gray-600"><?= $fmt($c['unit_price'] ?? 0) ?></td>
                  <td class="py-2 pr-3 text-gray-900"><?= $fmt($line) ?></td>
                  <td class="py-2 text-right">
                    <?php if ($canEdit): ?>
                    <form action="<?= $base ?>/item/delete" method="POST" onsubmit="return confirm('Remove this component?');" class="inline">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <input type="hidden" name="package_id" value="<?= $pid ?>">
                      <input type="hidden" name="stay_id" value="<?= $psid ?>">
                      <button type="submit" class="text-red-600 hover:text-red-700 text-xs">Remove</button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="4" class="py-2 pr-3 text-right font-medium text-gray-700">Components total</td><td class="py-2 pr-3 font-semibold text-gray-900"><?= $fmt($ctotal) ?></td><td></td></tr></tfoot>
            </table>
          </div>
        <?php else: ?>
          <p class="text-xs text-gray-500">No components yet — folio posts will use the base price.</p>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/item/add" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-2 pt-2 border-t border-gray-100">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="package_id" value="<?= $pid ?>">
          <input type="hidden" name="stay_id" value="<?= $psid ?>">
          <input type="text" name="label" placeholder="Component label" maxlength="191" required class="form-input text-sm md:col-span-2">
          <select name="category" class="form-select text-sm">
            <?php foreach ($itemCats as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option><?php endforeach; ?>
          </select>
          <input type="number" name="qty" value="1" min="1" step="1" class="form-input text-sm" title="Qty">
          <div class="flex gap-2">
            <input type="number" name="unit_price" value="0" min="0" step="0.01" class="form-input text-sm" title="Unit price">
            <button type="submit" class="btn-primary text-sm whitespace-nowrap">Add</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- ===== MEAL PLANS ===== -->
  <section class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900">Meal plans</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= htmlspecialchars(root . 'supplier/meal-plans/add') ?>" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-4 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" required class="form-select text-sm">
        <option value="">Property…</option>
        <?php foreach ($propList as $sid => $nm): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $nm) ?></option><?php endforeach; ?>
      </select>
      <select name="code" required class="form-select text-sm">
        <option value="">Plan…</option>
        <?php foreach ($mealCodes as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code . ' — ' . $label) ?></option><?php endforeach; ?>
      </select>
      <input type="number" name="price_per_night" value="0" min="0" step="0.01" placeholder="Price / night" class="form-input text-sm">
      <button type="submit" class="btn-primary text-sm">Add plan</button>
    </form>
    <?php endif; ?>
    <div class="card p-0 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b"><th class="py-2 px-4">Property</th><th class="py-2 px-4">Plan</th><th class="py-2 px-4">Per night</th><th class="py-2 px-4">Status</th><th class="py-2 px-4"></th></tr></thead>
        <tbody>
        <?php if (empty($mealPlans)): ?>
          <tr><td colspan="5" class="py-6 px-4 text-center text-gray-500">No meal plans defined.</td></tr>
        <?php endif; ?>
        <?php foreach ($mealPlans as $mp): ?>
          <?php $mc = (string) ($mp['code'] ?? ''); $msid = (int) ($mp['stay_id'] ?? 0); $mactive = (int) ($mp['active'] ?? 0) === 1; ?>
          <tr class="border-b border-gray-100">
            <td class="py-2 px-4 text-gray-700"><?= htmlspecialchars($propMap[$msid] ?? ('#' . $msid)) ?></td>
            <td class="py-2 px-4 text-gray-900"><?= htmlspecialchars($mc . ' — ' . ($mealCodes[$mc] ?? $mc)) ?></td>
            <td class="py-2 px-4 text-gray-700"><?= $fmt($mp['price_per_night'] ?? 0) ?></td>
            <td class="py-2 px-4"><span class="<?= $mactive ? 'badge-success' : 'badge-gray' ?>"><?= $mactive ? 'Active' : 'Off' ?></span></td>
            <td class="py-2 px-4 text-right">
              <?php if ($canEdit): ?>
              <form action="<?= htmlspecialchars(root . 'supplier/meal-plans/toggle') ?>" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="id" value="<?= (int) $mp['id'] ?>">
                <input type="hidden" name="stay_id" value="<?= $msid ?>">
                <input type="hidden" name="active" value="<?= $mactive ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-blue-600 hover:text-blue-700"><?= $mactive ? 'Disable' : 'Enable' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ===== POST TO A GUEST FOLIO ===== -->
  <?php if ($canBill): ?>
  <section class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900">Bill to a guest folio</h2>
    <p class="text-sm text-gray-600">Post a package or meal plan to an open folio by invoice ID. The amount is priced server-side from the stored item — the guest balance updates on the folio.</p>
    <form action="<?= htmlspecialchars(root . 'supplier/packages/folio-post') ?>" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-6 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" required class="form-select text-sm md:col-span-2">
        <option value="">Property…</option>
        <?php foreach ($propList as $sid => $nm): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $nm) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="invoice_id" placeholder="Invoice ID" required class="form-input text-sm md:col-span-2">
      <select name="kind" class="form-select text-sm" onchange="document.getElementById('pkgSel').classList.toggle('hidden', this.value!=='package');document.getElementById('mpSel').classList.toggle('hidden', this.value!=='mealplan');">
        <option value="package">Package</option>
        <option value="mealplan">Meal plan</option>
      </select>
      <button type="submit" class="btn-primary text-sm">Post</button>
      <div id="pkgSel" class="md:col-span-3">
        <select name="package_id" class="form-select text-sm w-full">
          <option value="">Select package…</option>
          <?php foreach ($packages as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div id="mpSel" class="md:col-span-3 hidden flex gap-2">
        <select name="meal_plan_id" class="form-select text-sm w-full">
          <option value="">Select meal plan…</option>
          <?php foreach ($mealPlans as $mp): ?><option value="<?= (int) $mp['id'] ?>"><?= htmlspecialchars(($propMap[(int) ($mp['stay_id'] ?? 0)] ?? '') . ' · ' . (string) ($mp['code'] ?? '')) ?></option><?php endforeach; ?>
        </select>
        <input type="number" name="nights" value="1" min="1" max="60" step="1" class="form-input text-sm w-24" title="Nights">
      </div>
    </form>
  </section>
  <?php endif; ?>
</div>
