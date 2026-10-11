<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $ingredients  = (isset($ingredients) && is_array($ingredients)) ? $ingredients : [];
  $recipes      = (isset($recipes) && is_array($recipes)) ? $recipes : [];
  $requisitions = (isset($requisitions) && is_array($requisitions)) ? $requisitions : [];
  $wastage      = (isset($wastage) && is_array($wastage)) ? $wastage : [];
  $variance     = (isset($variance) && is_array($variance)) ? $variance : ['rows' => [], 'totals' => []];
  $menu         = (isset($menu) && is_array($menu)) ? $menu : [];
  $propList     = (isset($propList) && is_array($propList)) ? $propList : [];
  $ingByStay    = (isset($ingByStay) && is_array($ingByStay)) ? $ingByStay : [];
  $units        = (isset($units) && is_array($units)) ? $units : [];
  $reasons      = (isset($reasons) && is_array($reasons)) ? $reasons : [];
  $bomRecipe    = isset($bomRecipe) && is_array($bomRecipe) ? $bomRecipe : null;
  $bomLines     = (isset($bomLines) && is_array($bomLines)) ? $bomLines : [];
  $canEdit      = !empty($canEdit);
  $from         = isset($from) ? (string) $from : date('Y-m-01');
  $to           = isset($to) ? (string) $to : date('Y-m-d');
  $base         = root . 'supplier/kitchen';
  $vTotals      = isset($variance['totals']) && is_array($variance['totals']) ? $variance['totals'] : [];
  $vRows        = isset($variance['rows']) && is_array($variance['rows']) ? $variance['rows'] : [];
  $ingMap = [];
  foreach ($ingredients as $i) { $ingMap[(int) $i['id']] = (string) $i['name']; }
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Kitchen &amp; food cost</h1>
      <p class="text-sm text-gray-600">Ingredients, recipe bill-of-materials, requisitions, wastage, actual-vs-theoretical variance and menu profitability.</p>
    </div>
    <a href="<?= root ?>supplier/recipes" class="btn ghost text-sm">Recipe cards</a>
  </div>

  <!-- DATE WINDOW -->
  <form action="<?= $base ?>" method="GET" class="card p-4 flex flex-wrap items-end gap-2">
    <div><label class="block text-xs text-gray-500 mb-1">From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input text-sm"></div>
    <div><label class="block text-xs text-gray-500 mb-1">To</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input text-sm"></div>
    <button type="submit" class="btn text-sm">Apply window</button>
    <p class="text-xs text-gray-400 ml-auto self-center">Reports below (variance &amp; profitability) use this window.</p>
  </form>

  <?php if ($canEdit): ?>
  <!-- INGREDIENT + REQUISITION + WASTAGE -->
  <div class="grid gap-4 md:grid-cols-3">
    <form action="<?= $base ?>/ingredient" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Add / update ingredient</h3>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <input type="text" name="name" class="input text-sm w-full" placeholder="Ingredient name *" required maxlength="191">
      <div class="flex gap-2">
        <select name="unit" class="input text-sm w-1/2"><?php foreach ($units as $u => $ul): ?><option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($ul) ?></option><?php endforeach; ?></select>
        <input type="number" name="unit_cost" step="0.0001" min="0" value="0" class="input text-sm w-1/2" placeholder="unit cost">
      </div>
      <button type="submit" class="btn emerald text-sm w-full">Save ingredient</button>
    </form>

    <form action="<?= $base ?>/requisition" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Issue requisition</h3>
      <p class="text-xs text-gray-500">Store &rarr; outlet. Valued at the ingredient's unit cost.</p>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="ingredient_id" class="input text-sm w-full" required><option value="">Ingredient *</option><?php foreach ($ingredients as $ing): ?><option value="<?= (int) $ing['id'] ?>"><?= htmlspecialchars((string) $ing['name']) ?> (<?= htmlspecialchars((string) $ing['unit']) ?>)</option><?php endforeach; ?></select>
      <div class="flex gap-2">
        <input type="number" name="qty" step="0.0001" min="0" class="input text-sm w-1/2" placeholder="qty *" required>
        <input type="text" name="outlet" class="input text-sm w-1/2" placeholder="outlet/station">
      </div>
      <button type="submit" class="btn text-sm w-full">Issue</button>
    </form>

    <form action="<?= $base ?>/wastage" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Log wastage</h3>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="ingredient_id" class="input text-sm w-full" required><option value="">Ingredient *</option><?php foreach ($ingredients as $ing): ?><option value="<?= (int) $ing['id'] ?>"><?= htmlspecialchars((string) $ing['name']) ?> (<?= htmlspecialchars((string) $ing['unit']) ?>)</option><?php endforeach; ?></select>
      <div class="flex gap-2">
        <input type="number" name="qty" step="0.0001" min="0" class="input text-sm w-1/2" placeholder="qty *" required>
        <select name="reason" class="input text-sm w-1/2"><?php foreach ($reasons as $rk => $rl): ?><option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option><?php endforeach; ?></select>
      </div>
      <button type="submit" class="btn text-sm w-full">Log wastage</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- INGREDIENT CATALOGUE -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Ingredient catalogue</h2></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Ingredient</th><th class="px-4 py-2 font-medium">Unit</th><th class="px-4 py-2 font-medium text-right">Unit cost</th><th class="px-4 py-2 font-medium">Active</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($ingredients)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No ingredients yet.</td></tr>
        <?php else: foreach ($ingredients as $ing): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $ing['name']) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) $ing['unit']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $ing['unit_cost'], 4) ?></td>
            <td class="px-4 py-2"><?= ((int) ($ing['active'] ?? 1)) ? '<span class="text-xs text-emerald-700">Active</span>' : '<span class="text-xs text-gray-400">Inactive</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- RECIPE BOM EDITOR -->
  <div class="card p-5 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <h2 class="font-semibold text-gray-900">Recipe bill of materials</h2>
      <form action="<?= $base ?>" method="GET" class="flex items-end gap-2">
        <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
        <select name="recipe" class="input text-sm"><option value="">Choose a recipe…</option><?php foreach ($recipes as $r): ?><option value="<?= (int) $r['id'] ?>" <?= ($bomRecipe && (int) $bomRecipe['id'] === (int) $r['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $r['name']) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn text-sm">Open</button>
      </form>
    </div>

    <?php if ($bomRecipe): ?>
      <?php
        $recStayId = (int) $bomRecipe['stay_id'];
        $recYield  = max(1, (int) $bomRecipe['yield_qty']);
        $batch = 0.0; foreach ($bomLines as $bl) { $batch += (float) $bl['qty'] * (float) $bl['unit_cost']; }
        $cps = round($batch / $recYield, 2);
        $sell = ($bomRecipe['sell_price'] !== null && $bomRecipe['sell_price'] !== '') ? (float) $bomRecipe['sell_price'] : null;
        $mpct = ($sell && $sell > 0) ? round((($sell - $cps) / $sell) * 100, 1) : null;
        $stayIngredients = $ingByStay[$recStayId] ?? [];
      ?>
      <div class="flex flex-wrap gap-4 text-sm">
        <div><span class="text-gray-500">Yields</span> <span class="font-semibold tabular-nums"><?= (int) $recYield ?></span> serve(s)</div>
        <div><span class="text-gray-500">Batch cost</span> <span class="font-semibold tabular-nums"><?= number_format($batch, 2) ?></span></div>
        <div><span class="text-gray-500">Cost / serve</span> <span class="font-semibold tabular-nums"><?= number_format($cps, 2) ?></span></div>
        <div><span class="text-gray-500">Sell</span> <span class="font-semibold tabular-nums"><?= $sell !== null ? number_format($sell, 2) : '—' ?></span></div>
        <div><span class="text-gray-500">Margin</span> <span class="font-semibold tabular-nums <?= ($mpct !== null && $mpct < 0) ? 'text-red-600' : 'text-emerald-700' ?>"><?= $mpct !== null ? $mpct . '%' : '—' ?></span></div>
      </div>

      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Ingredient</th><th class="px-2 py-2 font-medium text-right">Qty / batch</th><th class="px-2 py-2 font-medium text-right">Unit cost</th><th class="px-2 py-2 font-medium text-right">Line cost</th><?php if ($canEdit): ?><th class="px-2 py-2"></th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($bomLines)): ?>
            <tr><td colspan="5" class="px-2 py-4 text-center text-gray-400">No ingredients on this recipe yet.</td></tr>
          <?php else: foreach ($bomLines as $bl): ?>
            <tr>
              <td class="px-2 py-2 text-gray-900"><?= htmlspecialchars((string) $bl['name']) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars((string) $bl['unit']) ?>)</span></td>
              <td class="px-2 py-2 text-right tabular-nums"><?= rtrim(rtrim(number_format((float) $bl['qty'], 4), '0'), '.') ?></td>
              <td class="px-2 py-2 text-right tabular-nums"><?= number_format((float) $bl['unit_cost'], 4) ?></td>
              <td class="px-2 py-2 text-right tabular-nums"><?= number_format((float) $bl['qty'] * (float) $bl['unit_cost'], 2) ?></td>
              <?php if ($canEdit): ?>
              <td class="px-2 py-2 text-right">
                <form action="<?= $base ?>/bom" method="POST" onsubmit="return confirm('Remove this ingredient from the recipe?');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="stay_id" value="<?= $recStayId ?>"><input type="hidden" name="recipe_id" value="<?= (int) $bomRecipe['id'] ?>"><input type="hidden" name="ingredient_id" value="<?= (int) $bl['ingredient_id'] ?>"><input type="hidden" name="qty" value="0">
                  <button type="submit" class="text-xs text-red-600 hover:underline">Remove</button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/bom" method="POST" class="flex flex-wrap items-end gap-2 pt-2 border-t border-gray-100">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="stay_id" value="<?= $recStayId ?>"><input type="hidden" name="recipe_id" value="<?= (int) $bomRecipe['id'] ?>">
        <select name="ingredient_id" class="input text-sm" required><option value="">Add ingredient *</option><?php foreach ($stayIngredients as $ing): ?><option value="<?= (int) $ing['id'] ?>"><?= htmlspecialchars((string) $ing['name']) ?> (<?= htmlspecialchars((string) $ing['unit']) ?>)</option><?php endforeach; ?></select>
        <input type="number" name="qty" step="0.0001" min="0" class="input text-sm w-32" placeholder="qty / batch *" required>
        <button type="submit" class="btn emerald text-sm">Set line</button>
        <p class="text-xs text-gray-400 self-center">Setting qty to 0 removes a line. Saving a line re-syncs the recipe card's food cost.</p>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-sm text-gray-400">Choose a recipe above to build its ingredient list. Recipe cards are created on the <a href="<?= root ?>supplier/recipes" class="text-violet-600 hover:underline">Recipes</a> screen.</p>
    <?php endif; ?>
  </div>

  <!-- ACTUAL vs THEORETICAL VARIANCE -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100">
      <h2 class="font-semibold text-gray-900">Actual vs theoretical usage</h2>
      <p class="text-xs text-gray-500">Theoretical = dishes sold (POS) &times; recipe BOM. Actual = requisitions + wastage. Window: <?= htmlspecialchars($from) ?> &rarr; <?= htmlspecialchars($to) ?>. Positive variance = over-consumption / leakage.</p>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Ingredient</th><th class="px-4 py-2 font-medium text-right">Theoretical qty</th><th class="px-4 py-2 font-medium text-right">Actual qty</th><th class="px-4 py-2 font-medium text-right">Var qty</th><th class="px-4 py-2 font-medium text-right">Theoretical value</th><th class="px-4 py-2 font-medium text-right">Actual value</th><th class="px-4 py-2 font-medium text-right">Var value</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($vRows)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No usage in this window (needs POS sales + requisitions/wastage).</td></tr>
        <?php else: foreach ($vRows as $vr): $vv = (float) $vr['variance_value']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $vr['name']) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars((string) $vr['unit']) ?>)</span></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $vr['theoretical_qty'], 3) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $vr['actual_qty'], 3) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= ((float) $vr['variance_qty'] > 0) ? 'text-red-600' : 'text-gray-500' ?>"><?= number_format((float) $vr['variance_qty'], 3) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $vr['theoretical_value'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $vr['actual_value'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= ($vv > 0) ? 'text-red-600 font-semibold' : 'text-emerald-700' ?>"><?= number_format($vv, 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($vRows)): ?>
      <tfoot><tr class="border-t border-gray-200 font-semibold"><td class="px-4 py-2">Totals</td><td colspan="3"></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) ($vTotals['theoretical_value'] ?? 0), 2) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) ($vTotals['actual_value'] ?? 0), 2) ?></td><td class="px-4 py-2 text-right tabular-nums <?= ((float) ($vTotals['variance_value'] ?? 0) > 0) ? 'text-red-600' : 'text-emerald-700' ?>"><?= number_format((float) ($vTotals['variance_value'] ?? 0), 2) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>

  <!-- MENU PROFITABILITY -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Menu profitability</h2><p class="text-xs text-gray-500">Ranked by contribution (margin &times; dishes sold) over the window. Cost per serve comes from the BOM where one exists.</p></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Recipe</th><th class="px-4 py-2 font-medium text-right">Cost/serve</th><th class="px-4 py-2 font-medium text-right">Sell</th><th class="px-4 py-2 font-medium text-right">Margin</th><th class="px-4 py-2 font-medium text-right">Sold</th><th class="px-4 py-2 font-medium text-right">Contribution</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($menu)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No recipes yet.</td></tr>
        <?php else: foreach ($menu as $m): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $m['name']) ?> <?php if (empty($m['has_bom'])): ?><span class="text-xs text-amber-600" title="No BOM — cost derived from the recipe card's typed food cost">no BOM</span><?php endif; ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $m['cost_per_serve'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $m['sell_price'] !== null ? number_format((float) $m['sell_price'], 2) : '—' ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= ($m['margin_pct'] !== null && (float) $m['margin_pct'] < 0) ? 'text-red-600' : 'text-gray-700' ?>"><?= $m['margin_pct'] !== null ? $m['margin_pct'] . '%' : '—' ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $m['qty_sold'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-semibold"><?= $m['contribution'] !== null ? number_format((float) $m['contribution'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- RECENT REQUISITIONS + WASTAGE -->
  <div class="grid gap-4 md:grid-cols-2">
    <div class="card overflow-x-auto">
      <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Recent requisitions</h2></div>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">Ingredient</th><th class="px-3 py-2 font-medium">Outlet</th><th class="px-3 py-2 font-medium text-right">Qty</th><th class="px-3 py-2 font-medium text-right">Value</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($requisitions)): ?>
            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">None yet.</td></tr>
          <?php else: foreach ($requisitions as $rq): ?>
            <tr>
              <td class="px-3 py-2 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($rq['issued_at'] ?? '')) ?></td>
              <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars((string) ($rq['name'] ?? ('#' . ($rq['ingredient_id'] ?? 0)))) ?></td>
              <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars((string) ($rq['outlet'] ?? '—') ?: '—') ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($rq['qty'] ?? 0), 3) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($rq['value'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card overflow-x-auto">
      <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Recent wastage</h2></div>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">Ingredient</th><th class="px-3 py-2 font-medium">Reason</th><th class="px-3 py-2 font-medium text-right">Qty</th><th class="px-3 py-2 font-medium text-right">Value</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($wastage)): ?>
            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">None yet.</td></tr>
          <?php else: foreach ($wastage as $w): ?>
            <tr>
              <td class="px-3 py-2 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($w['logged_at'] ?? '')) ?></td>
              <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars((string) ($w['name'] ?? ('#' . ($w['ingredient_id'] ?? 0)))) ?></td>
              <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars((string) ($reasons[$w['reason'] ?? ''] ?? ($w['reason'] ?? ''))) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($w['qty'] ?? 0), 3) ?></td>
              <td class="px-3 py-2 text-right tabular-nums text-red-600"><?= number_format((float) ($w['value'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
