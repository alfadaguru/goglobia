<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $recipes = (isset($recipes) && is_array($recipes)) ? $recipes : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/recipes';
  // Local margin computation (mirrors recipe_margin) for display.
  $margin = function(array $r) {
    $yield = max(1, (int) ($r['yield_qty'] ?? 1));
    $cps = round(((float) ($r['food_cost'] ?? 0)) / $yield, 2);
    $sp = ($r['sell_price'] !== null && (float) $r['sell_price'] > 0) ? (float) $r['sell_price'] : null;
    $mp = $sp !== null ? round((($sp - $cps) / $sp) * 100, 1) : null;
    return ['cps' => $cps, 'mp' => $mp];
  };
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Recipes &amp; food cost</h1><p class="text-sm text-gray-600">Track food cost per dish and the gross margin against your sell price.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Dish name *" required>
    <input type="number" name="yield_qty" min="1" value="1" class="input text-sm" placeholder="Servings">
    <input type="number" name="food_cost" step="0.01" min="0" class="input text-sm" placeholder="Total food cost">
    <input type="number" name="sell_price" step="0.01" min="0" class="input text-sm" placeholder="Sell price / serve">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add recipe</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Dish</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Yield</th><th class="px-4 py-2 font-medium text-right">Cost/serve</th><th class="px-4 py-2 font-medium text-right">Sell</th><th class="px-4 py-2 font-medium text-right">Margin</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($recipes)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No recipes.</td></tr>
        <?php else: foreach ($recipes as $r): $m = $margin($r); ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['name']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $r['yield_qty'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format($m['cps'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $r['sell_price'] !== null ? number_format((float) $r['sell_price'], 2) : '—' ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $m['mp'] !== null && $m['mp'] < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $m['mp'] !== null ? number_format($m['mp'], 1) . '%' : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
