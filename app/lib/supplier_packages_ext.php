<?php
// FILE: app/lib/supplier_packages_ext.php
// Module 17 — Packages & ancillary revenue, DEEPENED.
// Builds the genuine gaps on top of the base package CRUD in supplier_safety.php
// (package_add / package_set_active / package_list, table `stays_packages`):
//
//   1. PACKAGE COMPONENTS (builder) — decompose a package into priced line items
//      instead of a single free-text `inclusions` blob. Table: stays_package_items.
//   2. MEAL PLANS — first-class RO/BB/HB/FB/AI catalogue, per-night priced.
//      Table: stays_meal_plans.
//   3. FOLIO POST (cross-sell / upsell capture) — post a package OR a meal plan to a
//      LIVE guest folio as an `extra` line. Money is SERVER-PRICED from the stored
//      package/meal-plan rows (client price NEVER trusted) and written through the
//      existing folio engine (folio_add_item). This is the actual ancillary-revenue
//      capture the base lacked.
//
// Per-property; the ROUTE authorizes the stay (supplier_can). These helpers assume
// authorization already happened and re-check ownership defensively via stay_id.
// NOT runtime-verified — logic + php -l only.

// --- MEAL PLAN CODES --------------------------------------------------------------
if (!function_exists('pkg_meal_plan_codes')) {
    /** Canonical meal-plan codes (hospitality standard). code => human label. */
    function pkg_meal_plan_codes(): array
    {
        return [
            'RO' => 'Room only',
            'BB' => 'Bed & breakfast',
            'HB' => 'Half board',
            'FB' => 'Full board',
            'AI' => 'All inclusive',
        ];
    }
}

// --- PACKAGE COMPONENTS (builder) -------------------------------------------------
if (!function_exists('pkg_item_categories')) {
    /** Allowed component categories for the package builder. */
    function pkg_item_categories(): array
    {
        return ['room', 'meal', 'spa', 'transfer', 'activity', 'other'];
    }
}

if (!function_exists('pkg_owns_package')) {
    /** True iff $packageId belongs to $stayId (ownership fence for all item ops). */
    function pkg_owns_package($db, int $packageId, int $stayId): bool
    {
        if ($packageId <= 0 || $stayId <= 0) { return false; }
        try { return (bool) $db->has('stays_packages', ['id' => $packageId, 'stay_id' => $stayId]); }
        catch (\Throwable $e) { error_log('pkg_owns_package: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('pkg_item_add')) {
    /** Add a priced component to a package. Returns new id or 0. Server rounds money. */
    function pkg_item_add($db, int $packageId, int $stayId, array $d): int
    {
        if (!pkg_owns_package($db, $packageId, $stayId)) { return 0; }
        $label = trim((string) ($d['label'] ?? ''));
        if ($label === '') { return 0; }
        $cat = in_array(($d['category'] ?? ''), pkg_item_categories(), true) ? (string) $d['category'] : 'other';
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_package_items', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'package_id' => $packageId,
                'stay_id'    => $stayId,
                'category'   => $cat,
                'label'      => substr($label, 0, 191),
                'qty'        => max(1, (int) ($d['qty'] ?? 1)),
                'unit_price' => max(0, round((float) ($d['unit_price'] ?? 0), 2)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('pkg_item_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('pkg_item_delete')) {
    /** Remove a component. Ownership enforced by package_id + stay_id. */
    function pkg_item_delete($db, int $itemId, int $packageId, int $stayId): bool
    {
        if ($itemId <= 0 || !pkg_owns_package($db, $packageId, $stayId)) { return false; }
        try {
            if (!$db->has('stays_package_items', ['id' => $itemId, 'package_id' => $packageId, 'stay_id' => $stayId])) { return false; }
            $db->delete('stays_package_items', ['id' => $itemId, 'package_id' => $packageId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('pkg_item_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('pkg_items_for')) {
    /** All components of one package, ordered. */
    function pkg_items_for($db, int $packageId): array
    {
        if ($packageId <= 0) { return []; }
        try { return $db->select('stays_package_items', '*', ['package_id' => $packageId, 'ORDER' => ['id' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('pkg_items_for: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('pkg_items_map')) {
    /** package_id => [components] for a set of stays (one query). */
    function pkg_items_map($db, array $stayIds): array
    {
        $out = [];
        if (empty($stayIds)) { return $out; }
        try {
            foreach ($db->select('stays_package_items', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'ASC']]) ?: [] as $r) {
                $out[(int) $r['package_id']][] = $r;
            }
        } catch (\Throwable $e) { error_log('pkg_items_map: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('pkg_components_total')) {
    /** SERVER-SIDE sum of a package's components (qty * unit_price), rounded. */
    function pkg_components_total($db, int $packageId): float
    {
        $sum = 0.0;
        foreach (pkg_items_for($db, $packageId) as $it) {
            $sum += max(1, (int) ($it['qty'] ?? 1)) * max(0, (float) ($it['unit_price'] ?? 0));
        }
        return round($sum, 2);
    }
}

// --- MEAL PLANS -------------------------------------------------------------------
if (!function_exists('meal_plan_add')) {
    /** Add/define a priced meal plan for a property. Returns id or 0. */
    function meal_plan_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (!array_key_exists($code, pkg_meal_plan_codes())) { return 0; }
        try {
            // One row per (stay, code): dedupe so a property has a single price per plan.
            if ($db->has('stays_meal_plans', ['stay_id' => $stayId, 'code' => $code])) { return 0; }
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_meal_plans', [
                'org_id'          => $orgId > 0 ? $orgId : null,
                'stay_id'         => $stayId,
                'code'            => $code,
                'price_per_night' => max(0, round((float) ($d['price_per_night'] ?? 0), 2)),
                'active'          => 1,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('meal_plan_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('meal_plan_set_active')) {
    function meal_plan_set_active($db, int $id, int $stayId, bool $active): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_meal_plans', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_meal_plans', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('meal_plan_set_active: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('meal_plan_list')) {
    function meal_plan_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_meal_plans', '*', ['stay_id' => $stayIds, 'ORDER' => ['stay_id' => 'ASC', 'code' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('meal_plan_list: ' . $e->getMessage()); return []; }
    }
}

// --- FOLIO POST (ancillary-revenue capture) ---------------------------------------
if (!function_exists('pkg_folio_for_owned_invoice')) {
    /**
     * Resolve an OPEN folio by invoice_id, but ONLY if its stay is in $stayIds (the
     * caller's authorized set). Returns [folioId, stayId, currency] or null. This is
     * the ownership fence for posting: a supplier can only bill folios on their own
     * properties. Uses folio_get_or_create so a not-yet-materialized folio is created.
     */
    function pkg_folio_for_owned_invoice($db, string $invoiceId, array $stayIds): ?array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '' || empty($stayIds)) { return null; }
        if (!function_exists('folio_get_or_create')) { return null; }
        try {
            $folioId = folio_get_or_create($db, $invoiceId); // own-inventory stays only
            if ($folioId <= 0) { return null; }
            $f = $db->get('stays_folios', ['id', 'stay_id', 'currency', 'status'], ['id' => $folioId]);
            if (!$f || (string) ($f['status'] ?? '') !== 'open') { return null; }
            $sid = (int) ($f['stay_id'] ?? 0);
            if ($sid <= 0 || !in_array($sid, array_map('intval', $stayIds), true)) { return null; } // not your property
            return ['folio_id' => $folioId, 'stay_id' => $sid, 'currency' => (string) ($f['currency'] ?? 'USD')];
        } catch (\Throwable $e) { error_log('pkg_folio_for_owned_invoice: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('pkg_post_package_to_folio')) {
    /**
     * Post a package to a guest folio as a server-priced `extra` line. Price is taken
     * from the package's stored components (if any) else the package's own `price` —
     * NEVER from the client. Returns bool. $stayIds = caller's authorized stays.
     */
    function pkg_post_package_to_folio($db, string $invoiceId, int $packageId, array $stayIds, string $actor = ''): bool
    {
        $loc = pkg_folio_for_owned_invoice($db, $invoiceId, $stayIds);
        if ($loc === null || $packageId <= 0) { return false; }
        try {
            $pkg = $db->get('stays_packages', ['id', 'stay_id', 'name', 'price', 'active'], ['id' => $packageId]);
            if (!$pkg) { return false; }
            // Package's stay must match the folio's stay (bill with your own product).
            if ((int) $pkg['stay_id'] !== (int) $loc['stay_id']) { return false; }
            if ((int) ($pkg['active'] ?? 0) !== 1) { return false; }
            // SERVER-SIDE amount: components win if defined, else the package base price.
            $amount = pkg_components_total($db, $packageId);
            if ($amount <= 0) { $amount = round((float) ($pkg['price'] ?? 0), 2); }
            if ($amount <= 0) { return false; }
            $desc = 'Package: ' . substr((string) ($pkg['name'] ?? 'Package'), 0, 120);
            return folio_add_item($db, (int) $loc['folio_id'], 'extra', $amount, $desc, $actor !== '' ? $actor : 'desk');
        } catch (\Throwable $e) { error_log('pkg_post_package_to_folio: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('pkg_post_mealplan_to_folio')) {
    /**
     * Post a meal plan to a folio. Amount = price_per_night * nights (server clamps
     * nights to 1..60). Price from the stored meal-plan row, NEVER the client.
     */
    function pkg_post_mealplan_to_folio($db, string $invoiceId, int $mealPlanId, int $nights, array $stayIds, string $actor = ''): bool
    {
        $loc = pkg_folio_for_owned_invoice($db, $invoiceId, $stayIds);
        if ($loc === null || $mealPlanId <= 0) { return false; }
        $nights = max(1, min(60, $nights));
        try {
            $mp = $db->get('stays_meal_plans', ['id', 'stay_id', 'code', 'price_per_night', 'active'], ['id' => $mealPlanId]);
            if (!$mp) { return false; }
            if ((int) $mp['stay_id'] !== (int) $loc['stay_id']) { return false; }
            if ((int) ($mp['active'] ?? 0) !== 1) { return false; }
            $amount = round(max(0, (float) ($mp['price_per_night'] ?? 0)) * $nights, 2);
            if ($amount <= 0) { return false; }
            $labels = pkg_meal_plan_codes();
            $code = (string) ($mp['code'] ?? '');
            $desc = 'Meal plan: ' . ($labels[$code] ?? $code) . ' x' . $nights . ' night' . ($nights === 1 ? '' : 's');
            return folio_add_item($db, (int) $loc['folio_id'], 'extra', $amount, $desc, $actor !== '' ? $actor : 'desk');
        } catch (\Throwable $e) { error_log('pkg_post_mealplan_to_folio: ' . $e->getMessage()); return false; }
    }
}
