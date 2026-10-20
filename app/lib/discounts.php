<?php
// app/lib/discounts.php
// ============================================================================
// DISCOUNT ENGINE  (automatic, non-code discounts — complements promo_codes)
// ----------------------------------------------------------------------------
// A discount auto-applies to a booking whose scope it matches; no code needed.
// It is the layer BETWEEN tax and promo codes:
//
//   supplier net -> markup(+agent) -> tax -> [AUTO DISCOUNT] -> [PROMO CODE]
//                                                    -> margin-floor clamp -> charged
//
// Scopes (narrowest wins, then higher `priority`, then larger discount):
//   all       — every booking
//   service   — one module_type (flights/stays/tours/…)
//   product   — a service + a local product id (stays.id, tours.id, cars.id,
//               bus.id, umrah.id, visa.id)   [needs the booking to pass item_id]
//   provider  — an integration supplier (bookings.module == modules.name) OR an
//               own-inventory seller (users.user_id who owns the product row)
//
// Types: percentage | fixed | tiered (JSON tiers by min order) | override (a flat
// % the scope always gets). All honour start/end window, min_order, max_discount
// cap, currency, active toggle, and a margin_floor_pct so stacking with a promo
// can never sell below supplier cost.
//
// This file is loaded by booking routes (via promoResolveForBooking's sibling
// call) and by the admin discounts screen. All functions function_exists-guarded.
// ============================================================================

if (!defined('DISCOUNT_SCOPES')) {
    define('DISCOUNT_SCOPES', 'all,service,product,provider');
}
if (!defined('DISCOUNT_TYPES')) {
    define('DISCOUNT_TYPES', 'percentage,fixed,tiered,override');
}

/**
 * Self-healing schema. Mirrors the house ensure*Schema() pattern. Also mirrored
 * into install/db.sql so the admin Database-Update tool can create it.
 */
if (!function_exists('ensureDiscountSchema')) {
    function ensureDiscountSchema($db): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;

        try {
            if (!dbTableExists($db, 'discounts')) {
                $db->query("CREATE TABLE IF NOT EXISTS `discounts` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(191) NOT NULL,
                    `description` varchar(255) DEFAULT NULL,
                    `discount_type` enum('percentage','fixed','tiered','override') NOT NULL DEFAULT 'percentage',
                    `discount_value` decimal(14,2) NOT NULL DEFAULT 0.00,
                    `tiers_json` mediumtext DEFAULT NULL,
                    `scope_type` enum('all','service','product','provider') NOT NULL DEFAULT 'all',
                    `scope_module` varchar(50) DEFAULT NULL,
                    `scope_product_ids` mediumtext DEFAULT NULL,
                    `scope_provider` varchar(191) DEFAULT NULL,
                    `scope_owner` varchar(255) DEFAULT NULL,
                    `audience` varchar(50) DEFAULT NULL,
                    `currency` varchar(3) DEFAULT NULL,
                    `min_order_amount` decimal(14,2) DEFAULT NULL,
                    `max_discount_amount` decimal(14,2) DEFAULT NULL,
                    `margin_floor_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
                    `priority` int(11) NOT NULL DEFAULT 0,
                    `stackable` tinyint(1) NOT NULL DEFAULT 1,
                    `start_date` datetime DEFAULT NULL,
                    `end_date` datetime DEFAULT NULL,
                    `status` tinyint(1) NOT NULL DEFAULT 1,
                    `used_count` int(11) NOT NULL DEFAULT 0,
                    `created_by` int(11) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_active` (`status`, `scope_type`),
                    KEY `idx_module` (`scope_module`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (\Throwable $e) {
            error_log('ensureDiscountSchema: ' . $e->getMessage());
        }
    }
}

/**
 * Compute the raw discount a single rule yields on an order amount (before caps/
 * floor). Returns 0.0 if the rule's type/values don't apply.
 */
if (!function_exists('discount_rule_amount')) {
    function discount_rule_amount(array $rule, float $orderAmount): float
    {
        if ($orderAmount <= 0) { return 0.0; }
        $type = (string) ($rule['discount_type'] ?? 'percentage');

        if ($type === 'percentage' || $type === 'override') {
            $pct = (float) ($rule['discount_value'] ?? 0);
            if ($pct <= 0) { return 0.0; }
            return round($orderAmount * min($pct, 100) / 100, 2);
        }

        if ($type === 'fixed') {
            $amt = (float) ($rule['discount_value'] ?? 0);
            return $amt > 0 ? round(min($amt, $orderAmount), 2) : 0.0;
        }

        if ($type === 'tiered') {
            // tiers_json: [{"min":500,"type":"percentage","value":5}, …]
            $tiers = json_decode((string) ($rule['tiers_json'] ?? ''), true);
            if (!is_array($tiers) || empty($tiers)) { return 0.0; }
            // Pick the highest-min tier whose min <= orderAmount.
            $best = null;
            foreach ($tiers as $t) {
                $min = (float) ($t['min'] ?? 0);
                if ($orderAmount + 0.0001 >= $min && ($best === null || $min > (float) ($best['min'] ?? 0))) {
                    $best = $t;
                }
            }
            if (!$best) { return 0.0; }
            $tt = (string) ($best['type'] ?? 'percentage');
            $tv = (float) ($best['value'] ?? 0);
            if ($tv <= 0) { return 0.0; }
            return $tt === 'fixed'
                ? round(min($tv, $orderAmount), 2)
                : round($orderAmount * min($tv, 100) / 100, 2);
        }

        return 0.0;
    }
}

/**
 * Does $rule match this booking's scope/eligibility? $ctx carries:
 *   module (service type, e.g. 'flights'), provider (bookings.module / supplier
 *   name), owner (own-inventory user_id or ''), item_id (local product id|0),
 *   currency, audience (customer/agent/supplier/staff|''), order_amount.
 */
if (!function_exists('discount_rule_matches')) {
    function discount_rule_matches(array $rule, array $ctx): bool
    {
        if ((int) ($rule['status'] ?? 0) !== 1) { return false; }

        $now = time();
        if (!empty($rule['start_date']) && strtotime((string) $rule['start_date']) > $now) { return false; }
        if (!empty($rule['end_date'])   && strtotime((string) $rule['end_date'])   < $now) { return false; }

        $order = (float) ($ctx['order_amount'] ?? 0);
        if (!empty($rule['min_order_amount']) && $order + 0.0001 < (float) $rule['min_order_amount']) { return false; }

        // Audience (optional): blank = everyone.
        $aud = strtolower(trim((string) ($rule['audience'] ?? '')));
        if ($aud !== '' && $aud !== strtolower(trim((string) ($ctx['audience'] ?? '')))) { return false; }

        $scope = (string) ($rule['scope_type'] ?? 'all');
        if ($scope === 'all') { return true; }

        if ($scope === 'service') {
            return strtolower(trim((string) ($rule['scope_module'] ?? ''))) === strtolower(trim((string) ($ctx['module'] ?? '')));
        }

        if ($scope === 'product') {
            // Must be same service AND the booking's item_id in the id list.
            if (strtolower(trim((string) ($rule['scope_module'] ?? ''))) !== strtolower(trim((string) ($ctx['module'] ?? '')))) { return false; }
            $ids = json_decode((string) ($rule['scope_product_ids'] ?? ''), true);
            if (!is_array($ids) || empty($ids)) { return false; }
            $item = (int) ($ctx['item_id'] ?? 0);
            if ($item <= 0) { return false; } // booking didn't pass a product id → can't match
            return in_array($item, array_map('intval', $ids), true);
        }

        if ($scope === 'provider') {
            // Either an integration supplier (provider string) OR an own-inventory owner.
            $prov  = strtolower(trim((string) ($rule['scope_provider'] ?? '')));
            $owner = trim((string) ($rule['scope_owner'] ?? ''));
            if ($prov !== '' && $prov === strtolower(trim((string) ($ctx['provider'] ?? '')))) { return true; }
            if ($owner !== '' && $owner === trim((string) ($ctx['owner'] ?? ''))) { return true; }
            return false;
        }

        return false;
    }
}

/**
 * Resolve the BEST automatic discount for a booking context. Returns:
 *   ['discount'=>float, 'rule'=>array|null, 'json'=>string|null]
 * Selection: among all matching active rules, narrowest scope first
 * (product > provider > service > all), then higher priority, then larger
 * computed discount. Caps by max_discount_amount. Does NOT apply the margin
 * floor here — that's applied once at the end alongside promo (see
 * discount_apply_with_promo) so stacking is floor-protected as a whole.
 */
if (!function_exists('discount_resolve_for_booking')) {
    function discount_resolve_for_booking($db, array $ctx): array
    {
        $none = ['discount' => 0.0, 'rule' => null, 'json' => null];
        try {
            if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }
            if (!dbTableExists($db, 'discounts')) { return $none; }

            $rules = $db->select('discounts', '*', ['status' => 1]) ?: [];
            if (empty($rules)) { return $none; }

            $order = (float) ($ctx['order_amount'] ?? 0);
            $scopeRank = ['product' => 3, 'provider' => 2, 'service' => 1, 'all' => 0];

            $best = null; $bestAmt = 0.0; $bestRank = -1; $bestPrio = PHP_INT_MIN;
            foreach ($rules as $r) {
                if (!discount_rule_matches($r, $ctx)) { continue; }
                $amt = discount_rule_amount($r, $order);
                if (!empty($r['max_discount_amount'])) {
                    $amt = min($amt, (float) $r['max_discount_amount']);
                }
                if ($amt <= 0) { continue; }
                $rank = $scopeRank[(string) ($r['scope_type'] ?? 'all')] ?? 0;
                $prio = (int) ($r['priority'] ?? 0);
                // narrowest scope, then priority, then amount
                if ($rank > $bestRank
                    || ($rank === $bestRank && $prio > $bestPrio)
                    || ($rank === $bestRank && $prio === $bestPrio && $amt > $bestAmt)) {
                    $best = $r; $bestAmt = $amt; $bestRank = $rank; $bestPrio = $prio;
                }
            }
            if (!$best) { return $none; }

            $json = json_encode([
                'id'            => (int) $best['id'],
                'name'          => (string) $best['name'],
                'discount_type' => (string) $best['discount_type'],
                'discount_value'=> (float) $best['discount_value'],
                'scope_type'    => (string) $best['scope_type'],
                'discount_amount' => round($bestAmt, 2),
                'currency'      => $ctx['currency'] ?? ($best['currency'] ?? null),
            ]);
            return ['discount' => round($bestAmt, 2), 'rule' => $best, 'json' => $json];
        } catch (\Throwable $e) {
            error_log('discount_resolve_for_booking: ' . $e->getMessage());
            return $none;
        }
    }
}

/**
 * Compose an auto-discount with a promo discount on one order, enforcing the
 * margin floor so the combined discount can never take the CHARGED total below
 * supplier cost + the rule's margin_floor_pct.
 *
 *   $base       = post-tax total (what discounts reduce)
 *   $supplierNet= bookings.price_original (supplier cost; floor reference)
 *   $autoDisc   = from discount_resolve_for_booking()
 *   $promoDisc  = from promoResolveForBooking()
 *   $floorPct   = the auto-discount rule's margin_floor_pct (0 if none)
 *
 * Returns ['total'=>float, 'auto'=>float, 'promo'=>float, 'clamped'=>bool].
 * Auto applies first; promo applies to the already-reduced subtotal; then both
 * are scaled back proportionally if they'd breach the floor.
 */
if (!function_exists('discount_apply_with_promo')) {
    function discount_apply_with_promo(float $base, float $supplierNet, float $autoDisc, float $promoDisc, float $floorPct = 0.0): array
    {
        $base = max(0.0, round($base, 2));
        $autoDisc  = max(0.0, $autoDisc);
        $promoDisc = max(0.0, $promoDisc);

        // Promo is computed on the post-auto-discount subtotal (stacking order).
        $afterAuto = max(0.0, $base - $autoDisc);
        $promoDisc = min($promoDisc, $afterAuto);
        $total     = max(0.0, $afterAuto - $promoDisc);

        // Floor: never sell below supplierNet * (1 + floorPct/100).
        $floor = $supplierNet > 0 ? round($supplierNet * (1 + max(0.0, $floorPct) / 100), 2) : 0.0;
        $clamped = false;
        if ($floor > 0 && $total < $floor) {
            $clamped = true;
            $allowed = max(0.0, $base - $floor); // max total discount permitted
            $wanted  = $autoDisc + $promoDisc;
            if ($wanted <= 0) {
                $autoDisc = $promoDisc = 0.0;
            } else {
                // Scale both discounts down proportionally to hit the floor exactly.
                $scale    = $allowed / $wanted;
                $autoDisc = round($autoDisc * $scale, 2);
                $promoDisc= round($promoDisc * $scale, 2);
            }
            $total = round($base - $autoDisc - $promoDisc, 2);
        }

        return ['total' => round($total, 2), 'auto' => round($autoDisc, 2), 'promo' => round($promoDisc, 2), 'clamped' => $clamped];
    }
}
