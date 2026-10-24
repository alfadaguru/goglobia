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

/**
 * ONE orchestrator every booking route calls at the promo stage, so discount +
 * promo behave identically everywhere ("seamless"). It:
 *   1. resolves the best AUTO discount for this booking's scope,
 *   2. resolves the PROMO code (reusing promoResolveForBooking),
 *   3. detects whether the product is a PORTAL-supplier listing (role=supplier),
 *   4. applies the money model:
 *        - portal-supplier product → discount is PLATFORM-FUNDED: it reduces the
 *          customer's charge but price_original stays whole (supplier paid in
 *          full); NO net floor (the platform chose to absorb it). The funded
 *          amount is reported so the route can store it (bookings.discount).
 *        - API / own-service → clamp so price_markup NEVER drops below supplier
 *          net (price_original). This also closes the old promo-below-net leak.
 *
 * @param array $args {
 *   base          float  post-tax total BEFORE any discount (what gets reduced)
 *   supplier_net  float  bookings.price_original (floor + supplier earning ref)
 *   module        string service type ('flights','stays',…) for promo + scope
 *   currency      string base currency
 *   promo_code    string raw code from the customer ('' if none)
 *   booking_data  array|string  used to resolve the own-inventory owner
 *   item_id       int    local product id (for product-scope discounts) | 0
 *   provider      string bookings.module supplier name (for provider scope) | ''
 *   audience      string customer|agent|supplier|staff | ''
 *   user_id,user_email  for promo per-user limits
 * }
 * @return array {
 *   total            float  final charged total (store as price_markup)
 *   auto_discount    float  auto-discount applied
 *   promo_discount   float  promo applied
 *   total_discount   float  auto + promo
 *   platform_funded  float  portion the PLATFORM absorbs (supplier-product case)
 *   promo_json       ?string  bookings.promo_codes blob (null if none)
 *   discount_json    ?string  applied-auto-discount blob (null if none)
 *   promo_row        ?array   for recordPromoUsage
 *   discount_row     ?array   for bumping discounts.used_count
 *   is_supplier_product bool
 *   floor_clamped    bool
 * }
 */
if (!function_exists('apply_booking_discounts')) {
    function apply_booking_discounts($db, array $args): array
    {
        $base        = max(0.0, (float) ($args['base'] ?? 0));
        $supplierNet = max(0.0, (float) ($args['supplier_net'] ?? 0));
        $module      = (string) ($args['module'] ?? '');
        $currency    = (string) ($args['currency'] ?? '');
        $promoCode   = trim((string) ($args['promo_code'] ?? ''));
        $bookingData = $args['booking_data'] ?? null;

        // Is this a PORTAL-supplier-owned product? Reuse the authoritative resolver
        // (stays/tours/cars; fail-closed on role=supplier). If so, discounts are
        // platform-funded and the net floor does NOT apply to the supplier.
        $isSupplierProduct = false;
        if (function_exists('supplier_earning_resolve_owner')) {
            try {
                $owner = supplier_earning_resolve_owner($db, $module, $bookingData);
                $isSupplierProduct = ($owner !== null);
            } catch (\Throwable $e) { $isSupplierProduct = false; }
        }

        // 1. AUTO discount for this scope.
        $ctx = [
            'module'       => $module,
            'provider'     => (string) ($args['provider'] ?? ''),
            'owner'        => isset($owner['owner']) ? (string) $owner['owner'] : '',
            'item_id'      => (int) ($args['item_id'] ?? 0),
            'currency'     => $currency,
            'audience'     => (string) ($args['audience'] ?? ''),
            'order_amount' => $base,
        ];
        $auto = discount_resolve_for_booking($db, $ctx);
        $autoDisc   = (float) $auto['discount'];
        $autoRow    = $auto['rule'];
        $floorPct   = $autoRow ? (float) ($autoRow['margin_floor_pct'] ?? 0) : 0.0;

        // 2. PROMO on the post-auto subtotal (stacking order).
        $promoDisc = 0.0; $promoRow = null; $promoJson = null;
        if ($promoCode !== '' && function_exists('promoResolveForBooking')) {
            $afterAuto = max(0.0, $base - $autoDisc);
            $pr = promoResolveForBooking($db, $promoCode, $afterAuto, $module, $currency, [
                'user_id'    => $args['user_id'] ?? null,
                'user_email' => $args['user_email'] ?? null,
                'item_id'    => $ctx['item_id'] ?: null,
            ]);
            $promoDisc = (float) ($pr['discount'] ?? 0);
            $promoRow  = $pr['promo'] ?? null;
            $promoJson = $pr['json'] ?? null;
        }

        // 2b. REFERRAL — the referee's half of a referral: a fixed, platform-funded
        // discount on their FIRST booking. Stacks on the post-auto-and-promo subtotal
        // and is treated exactly like a promo for the floor/charge math below (folded
        // into $promoDisc so it can never push the charge below supplier cost). Only
        // applies when the user has a pending referral and no prior paid booking.
        $referralDisc = 0.0;
        if (function_exists('referral_referee_discount_for') && !empty($args['user_id'])) {
            $afterPromo = max(0.0, $base - $autoDisc - $promoDisc);
            $referralDisc = (float) referral_referee_discount_for($db, (string) $args['user_id'], $afterPromo);
            if ($referralDisc > 0) { $promoDisc += $referralDisc; }
        }

        // 3. Money model.
        if ($isSupplierProduct) {
            // Platform-funded: customer pays base - (auto+promo); supplier net is
            // untouched; NO floor (platform deliberately absorbs). Guard only against
            // a negative charge.
            $totalDisc = min($autoDisc + $promoDisc, $base);
            $total     = round($base - $totalDisc, 2);
            // Platform funds whatever the discount exceeds our margin (base - net).
            $ourMargin = max(0.0, $base - $supplierNet);
            $platformFunded = round(max(0.0, $totalDisc - $ourMargin), 2);
            $clamped = false;
            // Re-split (auto/promo) proportionally only if we capped at base.
            if ($totalDisc < $autoDisc + $promoDisc && ($autoDisc + $promoDisc) > 0) {
                $scale = $totalDisc / ($autoDisc + $promoDisc);
                $autoDisc = round($autoDisc * $scale, 2);
                $promoDisc = round($promoDisc * $scale, 2);
            }
        } else {
            // API / own-service: clamp so charged >= supplier net (closes promo leak).
            $res = discount_apply_with_promo($base, $supplierNet, $autoDisc, $promoDisc, $floorPct);
            $total = $res['total']; $autoDisc = $res['auto']; $promoDisc = $res['promo'];
            $totalDisc = round($autoDisc + $promoDisc, 2);
            $platformFunded = 0.0;
            $clamped = $res['clamped'];
        }

        // Auto-discount JSON for the booking row (mirrors promo_codes blob style).
        $discountJson = null;
        if ($autoRow && $autoDisc > 0) {
            $discountJson = json_encode([
                'id'              => (int) $autoRow['id'],
                'name'            => (string) $autoRow['name'],
                'discount_type'   => (string) $autoRow['discount_type'],
                'scope_type'      => (string) $autoRow['scope_type'],
                'discount_amount' => round($autoDisc, 2),
                'platform_funded' => $platformFunded,
                'currency'        => $currency,
            ]);
        }

        return [
            'total'               => round($total, 2),
            'auto_discount'       => round($autoDisc, 2),
            'promo_discount'      => round($promoDisc, 2),
            'referral_discount'   => round(min($referralDisc, $promoDisc), 2), // referee's share (folded into promo for the charge math)
            'total_discount'      => round($autoDisc + $promoDisc, 2),
            'platform_funded'     => round($platformFunded, 2),
            'promo_json'          => $promoJson,
            'discount_json'       => $discountJson,
            'promo_row'           => $promoRow,
            'discount_row'        => $autoRow,
            'is_supplier_product' => $isSupplierProduct,
            'floor_clamped'       => $clamped,
        ];
    }
}

/** Bump a discount's used_count idempotently is NOT needed (no per-use ledger);
 *  just increment on application. Separate from promo which has its own ledger. */
if (!function_exists('discount_record_use')) {
    function discount_record_use($db, array $discountRow): void
    {
        $id = (int) ($discountRow['id'] ?? 0);
        if ($id <= 0) { return; }
        try { $db->update('discounts', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]); }
        catch (\Throwable $e) { error_log('discount_record_use: ' . $e->getMessage()); }
    }
}
