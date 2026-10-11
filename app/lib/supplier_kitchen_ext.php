<?php
// FILE: app/lib/supplier_kitchen_ext.php
// KITCHEN / RECIPE / FOOD-COST (#31) — DEEPENING EXTENSION.
//
// The base (app/lib/supplier_ancillary.php) gives a flat recipe card:
//   stays_recipes(name, yield_qty, food_cost, sell_price) + recipe_margin/add/list.
// That treats food_cost as a single hand-typed number. This file adds the genuine
// missing cost-control sub-features that a real F&B back-office needs, all built on
// an ingredient catalogue so cost becomes *derived*, not typed:
//
//   • Ingredient catalogue  — per-property raw items with a unit + last unit cost.
//   • BOM (bill of materials)— recipe → ingredient lines (qty per recipe yield). The
//     theoretical food cost of a recipe is COMPUTED from the BOM, not trusted input.
//   • Requisitions           — kitchen store → outlet issue of ingredients (the stock
//     movement spine that makes theoretical-vs-actual meaningful).
//   • Wastage                — logged spoilage/overportion/returns, valued at unit cost.
//   • Actual-vs-theoretical  — for a date window: theoretical usage (Σ recipe BOM ×
//     dishes sold, from the POS orders already in this platform) vs actual issued
//     (requisitions) + wastage, producing a per-ingredient variance in qty and value.
//   • Menu profitability     — per recipe: computed food cost, sell price, margin %,
//     dishes sold (POS), and total contribution; ranked.
//
// SCOPING CONTRACT: the CALLER (route) has already run SUPPLIER_OR_STAFF_AUTH +
// supplier_can for the stay. Every write here RE-VALIDATES that the target row
// (ingredient / recipe) belongs to the passed stay before acting, so it is safe even
// if a route forgets. All functions are defensive/non-fatal and guarded with
// function_exists so they never collide on re-require.
//
// MONEY NOTE (honest): this module is INTERNAL cost accounting only. Nothing here
// charges a guest, touches a folio, or moves gateway money — valuations are the
// property's own purchase costs, so there is NO coupling to folio_add_item / holds /
// stays_recompute_net and none is appropriate. The POS "dishes sold" figures are READ
// from the existing stays_pos_orders / stays_pos_order_items tables (populated by the
// real POS charge flow); this file never writes sales or revenue.
//
// NOT runtime-verified (no MySQL in the build env) — logic-reasoned + php -l only.

// ---------------------------------------------------------------------------
// Unit vocabulary + movement/wastage reason enums (display maps).
// ---------------------------------------------------------------------------
if (!function_exists('kitchen_units')) {
    function kitchen_units(): array
    {
        return ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'L', 'ea' => 'each', 'pack' => 'pack'];
    }
}
if (!function_exists('kitchen_waste_reasons')) {
    function kitchen_waste_reasons(): array
    {
        return ['spoilage' => 'Spoilage', 'overportion' => 'Over-portioning', 'prep' => 'Prep / trim loss',
                'return' => 'Guest return', 'expiry' => 'Expired', 'other' => 'Other'];
    }
}

// ---------------------------------------------------------------------------
// Internal: fetch an ingredient row scoped to a stay (unit cost is trusted = cost).
// ---------------------------------------------------------------------------
if (!function_exists('_kitchen_ingredient_for_stay')) {
    function _kitchen_ingredient_for_stay($db, int $ingredientId, int $stayId): ?array
    {
        if ($ingredientId <= 0 || $stayId <= 0) { return null; }
        try {
            $row = $db->get('stays_kitchen_ingredients',
                ['id', 'stay_id', 'name', 'unit', 'unit_cost', 'active'],
                ['id' => $ingredientId, 'stay_id' => $stayId]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('_kitchen_ingredient_for_stay: ' . $e->getMessage()); return null; }
    }
}
// Internal: confirm a recipe belongs to the stay (base table stays_recipes).
if (!function_exists('_kitchen_recipe_for_stay')) {
    function _kitchen_recipe_for_stay($db, int $recipeId, int $stayId): ?array
    {
        if ($recipeId <= 0 || $stayId <= 0) { return null; }
        try {
            $row = $db->get('stays_recipes', ['id', 'stay_id', 'name', 'yield_qty', 'food_cost', 'sell_price'],
                ['id' => $recipeId, 'stay_id' => $stayId]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('_kitchen_recipe_for_stay: ' . $e->getMessage()); return null; }
    }
}

// ===========================================================================
// INGREDIENT CATALOGUE
// ===========================================================================
if (!function_exists('kitchen_ingredient_save')) {
    /** Upsert an ingredient scoped to a stay. Returns id (0 on failure). If $id>0 it
     *  must already belong to the stay. unit_cost is the property's purchase cost. */
    function kitchen_ingredient_save($db, int $stayId, array $d, int $id = 0): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        $units = kitchen_units();
        $unit = (string) ($d['unit'] ?? 'ea');
        if (!isset($units[$unit])) { $unit = 'ea'; }
        $cost = max(0, round((float) ($d['unit_cost'] ?? 0), 4));
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            if ($id > 0) {
                if (!_kitchen_ingredient_for_stay($db, $id, $stayId)) { return 0; }
                $db->update('stays_kitchen_ingredients', [
                    'name' => substr($name, 0, 191), 'unit' => $unit, 'unit_cost' => $cost,
                    'active' => isset($d['active']) ? (int) ((bool) $d['active']) : 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $id, 'stay_id' => $stayId]);
                return $id;
            }
            $db->insert('stays_kitchen_ingredients', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 191), 'unit' => $unit, 'unit_cost' => $cost,
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('kitchen_ingredient_save: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('kitchen_ingredient_list')) {
    function kitchen_ingredient_list($db, array $stayIds, bool $activeOnly = false, int $limit = 1000): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if ($activeOnly) { $where['active'] = 1; }
        $where['ORDER'] = ['name' => 'ASC'];
        $where['LIMIT'] = max(1, $limit);
        try { return $db->select('stays_kitchen_ingredients', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('kitchen_ingredient_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// BOM — recipe ingredient lines. Qty is "per recipe yield" (the whole batch),
// so cost_per_serve = Σ(line qty × unit_cost) / yield_qty.
// ===========================================================================
if (!function_exists('kitchen_bom_set_line')) {
    /** Add/replace a BOM line (recipe × ingredient). Upsert keyed (recipe,ingredient).
     *  Re-validates BOTH recipe and ingredient belong to the stay. Returns bool. */
    function kitchen_bom_set_line($db, int $stayId, int $recipeId, int $ingredientId, float $qty): bool
    {
        if (!_kitchen_recipe_for_stay($db, $recipeId, $stayId)) { return false; }
        if (!_kitchen_ingredient_for_stay($db, $ingredientId, $stayId)) { return false; }
        $qty = max(0, round($qty, 4));
        try {
            $existing = $db->get('stays_recipe_bom', ['id'],
                ['recipe_id' => $recipeId, 'ingredient_id' => $ingredientId]);
            if ($existing) {
                if ($qty <= 0) { $db->delete('stays_recipe_bom', ['id' => (int) $existing['id']]); return true; }
                $db->update('stays_recipe_bom', ['qty' => $qty, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $existing['id']]);
            } elseif ($qty > 0) {
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_recipe_bom', [
                    'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                    'recipe_id' => $recipeId, 'ingredient_id' => $ingredientId, 'qty' => $qty,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            // Keep the base recipe's food_cost in sync with the computed theoretical cost.
            $tc = kitchen_recipe_theoretical_cost($db, $recipeId, $stayId);
            $db->update('stays_recipes', ['food_cost' => $tc['batch_cost'], 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $recipeId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('kitchen_bom_set_line: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('kitchen_bom_lines')) {
    /** BOM lines for one recipe, joined to ingredient name/unit/cost. */
    function kitchen_bom_lines($db, int $recipeId): array
    {
        if ($recipeId <= 0) { return []; }
        try {
            return $db->select('stays_recipe_bom',
                ['[>]stays_kitchen_ingredients' => ['ingredient_id' => 'id']],
                ['stays_recipe_bom.id', 'stays_recipe_bom.ingredient_id', 'stays_recipe_bom.qty',
                 'stays_kitchen_ingredients.name', 'stays_kitchen_ingredients.unit',
                 'stays_kitchen_ingredients.unit_cost'],
                ['stays_recipe_bom.recipe_id' => $recipeId,
                 'ORDER' => ['stays_kitchen_ingredients.name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('kitchen_bom_lines: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('kitchen_recipe_theoretical_cost')) {
    /** Compute a recipe's theoretical cost from its BOM. Pure-ish (reads DB only).
     *  Returns ['batch_cost','cost_per_serve','yield','lines']. */
    function kitchen_recipe_theoretical_cost($db, int $recipeId, int $stayId): array
    {
        $recipe = _kitchen_recipe_for_stay($db, $recipeId, $stayId);
        $yield = $recipe ? max(1, (int) $recipe['yield_qty']) : 1;
        $lines = kitchen_bom_lines($db, $recipeId);
        $batch = 0.0;
        foreach ($lines as $ln) {
            $batch += (float) $ln['qty'] * (float) $ln['unit_cost'];
        }
        $batch = round($batch, 2);
        return [
            'batch_cost'     => $batch,
            'cost_per_serve' => round($batch / $yield, 2),
            'yield'          => $yield,
            'lines'          => count($lines),
        ];
    }
}

// ===========================================================================
// REQUISITIONS — kitchen store → outlet issue of an ingredient (actual usage spine).
// ===========================================================================
if (!function_exists('kitchen_requisition_post')) {
    /** Record an issue of $qty of an ingredient to an outlet/station. Valued at the
     *  ingredient's current unit_cost (snapshotted onto the row). Returns id (0=fail). */
    function kitchen_requisition_post($db, int $stayId, int $ingredientId, float $qty, string $outlet = '', string $note = ''): int
    {
        $ing = _kitchen_ingredient_for_stay($db, $ingredientId, $stayId);
        if (!$ing) { return 0; }
        $qty = round($qty, 4);
        if ($qty <= 0) { return 0; }
        $unitCost = (float) $ing['unit_cost'];
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_kitchen_requisitions', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'ingredient_id' => $ingredientId, 'qty' => $qty,
                'unit_cost' => round($unitCost, 4), 'value' => round($qty * $unitCost, 2),
                'outlet' => substr(trim($outlet), 0, 80) ?: null,
                'note' => substr(trim($note), 0, 300) ?: null,
                'issued_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('kitchen_requisition_post: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('kitchen_requisition_list')) {
    function kitchen_requisition_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_kitchen_requisitions',
                ['[>]stays_kitchen_ingredients' => ['ingredient_id' => 'id']],
                ['stays_kitchen_requisitions.id', 'stays_kitchen_requisitions.stay_id',
                 'stays_kitchen_requisitions.ingredient_id', 'stays_kitchen_requisitions.qty',
                 'stays_kitchen_requisitions.unit_cost', 'stays_kitchen_requisitions.value',
                 'stays_kitchen_requisitions.outlet', 'stays_kitchen_requisitions.note',
                 'stays_kitchen_requisitions.issued_at',
                 'stays_kitchen_ingredients.name', 'stays_kitchen_ingredients.unit'],
                ['stays_kitchen_requisitions.stay_id' => $stayIds,
                 'ORDER' => ['stays_kitchen_requisitions.issued_at' => 'DESC'],
                 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('kitchen_requisition_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// WASTAGE — logged spoilage/overportion, valued at unit cost.
// ===========================================================================
if (!function_exists('kitchen_waste_post')) {
    function kitchen_waste_post($db, int $stayId, int $ingredientId, float $qty, string $reason = 'other', string $note = ''): int
    {
        $ing = _kitchen_ingredient_for_stay($db, $ingredientId, $stayId);
        if (!$ing) { return 0; }
        $qty = round($qty, 4);
        if ($qty <= 0) { return 0; }
        $reasons = kitchen_waste_reasons();
        if (!isset($reasons[$reason])) { $reason = 'other'; }
        $unitCost = (float) $ing['unit_cost'];
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_kitchen_wastage', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'ingredient_id' => $ingredientId, 'qty' => $qty,
                'unit_cost' => round($unitCost, 4), 'value' => round($qty * $unitCost, 2),
                'reason' => $reason, 'note' => substr(trim($note), 0, 300) ?: null,
                'logged_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('kitchen_waste_post: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('kitchen_waste_list')) {
    function kitchen_waste_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_kitchen_wastage',
                ['[>]stays_kitchen_ingredients' => ['ingredient_id' => 'id']],
                ['stays_kitchen_wastage.id', 'stays_kitchen_wastage.stay_id',
                 'stays_kitchen_wastage.ingredient_id', 'stays_kitchen_wastage.qty',
                 'stays_kitchen_wastage.unit_cost', 'stays_kitchen_wastage.value',
                 'stays_kitchen_wastage.reason', 'stays_kitchen_wastage.note',
                 'stays_kitchen_wastage.logged_at',
                 'stays_kitchen_ingredients.name', 'stays_kitchen_ingredients.unit'],
                ['stays_kitchen_wastage.stay_id' => $stayIds,
                 'ORDER' => ['stays_kitchen_wastage.logged_at' => 'DESC'],
                 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('kitchen_waste_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// POS "dishes sold" — READ ONLY, from the existing POS tables.
// A POS order item is linked to a recipe when its name matches a recipe name for the
// stay (the base POS menu has no recipe_id FK), so we resolve by name. This is a best-
// effort read used only for reporting; it never writes.
// ===========================================================================
if (!function_exists('kitchen_dishes_sold')) {
    /** Returns map recipeId => qtySold over [from,to] (Y-m-d) for the given stays.
     *  Best-effort: matches POS order-item name to recipe name (case-insensitive). */
    function kitchen_dishes_sold($db, array $stayIds, array $recipes, string $from, string $to): array
    {
        $out = [];
        if (empty($stayIds) || empty($recipes)) { return $out; }
        // Build name→recipeId index (lowercased).
        $byName = [];
        foreach ($recipes as $r) { $byName[strtolower(trim((string) $r['name']))] = (int) $r['id']; }
        if (empty($byName)) { return $out; }
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';
        try {
            // Join order items -> orders, filter by stay + paid window. Table/column names
            // follow the POS spine (stays_pos_orders / stays_pos_order_items). Guarded: any
            // schema mismatch is swallowed and simply yields no sold figures.
            $rows = $db->select('stays_pos_order_items',
                ['[>]stays_pos_orders' => ['order_id' => 'id']],
                ['stays_pos_order_items.name', 'stays_pos_order_items.qty'],
                ['stays_pos_orders.stay_id' => $stayIds,
                 'stays_pos_orders.created_at[<>]' => [$fromDt, $toDt]]) ?: [];
            foreach ($rows as $row) {
                $key = strtolower(trim((string) ($row['name'] ?? '')));
                if (isset($byName[$key])) {
                    $rid = $byName[$key];
                    $out[$rid] = ($out[$rid] ?? 0) + (int) ($row['qty'] ?? 0);
                }
            }
        } catch (\Throwable $e) { error_log('kitchen_dishes_sold: ' . $e->getMessage()); }
        return $out;
    }
}

// ===========================================================================
// ACTUAL-vs-THEORETICAL variance for a date window.
// theoretical usage(ingredient) = Σ over recipes [ dishesSold × BOM.qty / yield ]
// actual usage(ingredient)      = Σ requisitions in window  +  Σ wastage in window
// variance = actual − theoretical (positive = over-consumption / leakage).
// ===========================================================================
if (!function_exists('kitchen_variance_report')) {
    function kitchen_variance_report($db, array $stayIds, string $from, string $to): array
    {
        $result = ['from' => $from, 'to' => $to, 'rows' => [],
                   'totals' => ['theoretical_value' => 0.0, 'actual_value' => 0.0, 'variance_value' => 0.0]];
        if (empty($stayIds)) { return $result; }
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';
        try {
            // Ingredient master (id => [name,unit,unit_cost]).
            $ings = $db->select('stays_kitchen_ingredients',
                ['id', 'name', 'unit', 'unit_cost'], ['stay_id' => $stayIds]) ?: [];
            $ingMap = [];
            foreach ($ings as $i) { $ingMap[(int) $i['id']] = $i; }
            if (empty($ingMap)) { return $result; }

            // Recipes for these stays.
            $recipes = $db->select('stays_recipes', ['id', 'name', 'yield_qty'], ['stay_id' => $stayIds]) ?: [];
            $sold = kitchen_dishes_sold($db, $stayIds, $recipes, $from, $to);

            // Theoretical qty per ingredient.
            $theoQty = [];
            if (!empty($sold)) {
                $recipeIds = array_keys($sold);
                $bom = $db->select('stays_recipe_bom', ['recipe_id', 'ingredient_id', 'qty'],
                    ['recipe_id' => $recipeIds]) ?: [];
                $yieldMap = [];
                foreach ($recipes as $r) { $yieldMap[(int) $r['id']] = max(1, (int) $r['yield_qty']); }
                foreach ($bom as $b) {
                    $rid = (int) $b['recipe_id']; $iid = (int) $b['ingredient_id'];
                    if (!isset($sold[$rid]) || !isset($ingMap[$iid])) { continue; }
                    $perServe = (float) $b['qty'] / ($yieldMap[$rid] ?? 1);
                    $theoQty[$iid] = ($theoQty[$iid] ?? 0) + $perServe * $sold[$rid];
                }
            }

            // Actual qty per ingredient = requisitions + wastage in window.
            $actQty = [];
            foreach (['stays_kitchen_requisitions' => 'issued_at', 'stays_kitchen_wastage' => 'logged_at'] as $tbl => $dateCol) {
                $rows = $db->select($tbl, ['ingredient_id', 'qty'],
                    ['stay_id' => $stayIds, $dateCol . '[<>]' => [$fromDt, $toDt]]) ?: [];
                foreach ($rows as $row) {
                    $iid = (int) $row['ingredient_id'];
                    $actQty[$iid] = ($actQty[$iid] ?? 0) + (float) $row['qty'];
                }
            }

            // Build rows over the union of ingredient ids that appear in either side.
            $ids = array_unique(array_merge(array_keys($theoQty), array_keys($actQty)));
            foreach ($ids as $iid) {
                if (!isset($ingMap[$iid])) { continue; }
                $cost = (float) $ingMap[$iid]['unit_cost'];
                $tq = round((float) ($theoQty[$iid] ?? 0), 3);
                $aq = round((float) ($actQty[$iid] ?? 0), 3);
                $tv = round($tq * $cost, 2);
                $av = round($aq * $cost, 2);
                $vv = round($av - $tv, 2);
                $result['rows'][] = [
                    'ingredient_id' => $iid,
                    'name'          => $ingMap[$iid]['name'],
                    'unit'          => $ingMap[$iid]['unit'],
                    'theoretical_qty' => $tq,
                    'actual_qty'      => $aq,
                    'variance_qty'    => round($aq - $tq, 3),
                    'theoretical_value' => $tv,
                    'actual_value'      => $av,
                    'variance_value'    => $vv,
                ];
                $result['totals']['theoretical_value'] += $tv;
                $result['totals']['actual_value']      += $av;
                $result['totals']['variance_value']     += $vv;
            }
            // Rank by abs variance value desc (biggest leaks first).
            usort($result['rows'], function ($a, $b) {
                return abs($b['variance_value']) <=> abs($a['variance_value']);
            });
            foreach ($result['totals'] as $k => $v) { $result['totals'][$k] = round($v, 2); }
        } catch (\Throwable $e) { error_log('kitchen_variance_report: ' . $e->getMessage()); }
        return $result;
    }
}

// ===========================================================================
// MENU PROFITABILITY — per recipe: computed cost / sell / margin / sold / contribution.
// ===========================================================================
if (!function_exists('kitchen_menu_profitability')) {
    function kitchen_menu_profitability($db, array $stayIds, string $from, string $to): array
    {
        if (empty($stayIds)) { return []; }
        try {
            $recipes = $db->select('stays_recipes',
                ['id', 'stay_id', 'name', 'yield_qty', 'food_cost', 'sell_price'],
                ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC']]) ?: [];
            if (empty($recipes)) { return []; }
            $sold = kitchen_dishes_sold($db, $stayIds, $recipes, $from, $to);
            $out = [];
            foreach ($recipes as $r) {
                $rid = (int) $r['id'];
                $tc = kitchen_recipe_theoretical_cost($db, $rid, (int) $r['stay_id']);
                // Prefer BOM-computed per-serve cost; fall back to stored food_cost/yield.
                $yield = max(1, (int) $r['yield_qty']);
                $cps = $tc['lines'] > 0 ? $tc['cost_per_serve'] : round(((float) $r['food_cost']) / $yield, 2);
                $sell = ($r['sell_price'] !== null && $r['sell_price'] !== '') ? (float) $r['sell_price'] : null;
                $marginPct = ($sell && $sell > 0) ? round((($sell - $cps) / $sell) * 100, 1) : null;
                $qtySold = (int) ($sold[$rid] ?? 0);
                $contribution = ($sell !== null) ? round(($sell - $cps) * $qtySold, 2) : null;
                $out[] = [
                    'recipe_id'      => $rid,
                    'name'           => $r['name'],
                    'cost_per_serve' => $cps,
                    'sell_price'     => $sell,
                    'margin_pct'     => $marginPct,
                    'qty_sold'       => $qtySold,
                    'contribution'   => $contribution,
                    'has_bom'        => $tc['lines'] > 0,
                ];
            }
            // Rank by contribution desc (nulls last).
            usort($out, function ($a, $b) {
                return ($b['contribution'] ?? -1e18) <=> ($a['contribution'] ?? -1e18);
            });
            return $out;
        } catch (\Throwable $e) { error_log('kitchen_menu_profitability: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// Idempotent self-healing schema for the kitchen-ext tables. Mirrors the house
// pattern (CREATE TABLE IF NOT EXISTS, swallow failures). Call is cheap but guarded
// so it only runs when this module's control panel is first opened by a route.
// ---------------------------------------------------------------------------
if (!function_exists('ensureKitchenExtSchema')) {
    function ensureKitchenExtSchema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_kitchen_ingredients` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `name` varchar(191) NOT NULL,
                `unit` varchar(16) NOT NULL DEFAULT 'ea',
                `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`), KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->query("CREATE TABLE IF NOT EXISTS `stays_recipe_bom` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `recipe_id` int(11) NOT NULL,
                `ingredient_id` int(11) NOT NULL,
                `qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uniq_recipe_ing` (`recipe_id`,`ingredient_id`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->query("CREATE TABLE IF NOT EXISTS `stays_kitchen_requisitions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `ingredient_id` int(11) NOT NULL,
                `qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `value` decimal(14,2) NOT NULL DEFAULT 0.00,
                `outlet` varchar(80) DEFAULT NULL,
                `note` varchar(300) DEFAULT NULL,
                `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`), KEY `idx_stay` (`stay_id`), KEY `idx_ing` (`ingredient_id`),
                KEY `idx_issued` (`issued_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->query("CREATE TABLE IF NOT EXISTS `stays_kitchen_wastage` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `ingredient_id` int(11) NOT NULL,
                `qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
                `value` decimal(14,2) NOT NULL DEFAULT 0.00,
                `reason` varchar(24) NOT NULL DEFAULT 'other',
                `note` varchar(300) DEFAULT NULL,
                `logged_at` datetime NOT NULL DEFAULT current_timestamp(),
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`), KEY `idx_stay` (`stay_id`), KEY `idx_ing` (`ingredient_id`),
                KEY `idx_logged` (`logged_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) { error_log('ensureKitchenExtSchema: ' . $e->getMessage()); }
    }
}
