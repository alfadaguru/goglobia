<?php
// FILE: app/lib/price_alerts.php
// Customer price alerts: a customer saves a "watch" (a module + search params +
// the price they saw + an optional target). A cron (price_alerts_sweep) re-checks
// each active watch and, when the current price drops to/below the target (or
// below the saved baseline if no target), drops an in-app notification
// (notify_user) and emails if configured.
//
// Re-pricing is intentionally behind a per-module resolver (price_alert_current_
// price): it returns a fresh price only for modules where a CHEAP lookup exists,
// and null otherwise — so an alert never fires a false "price dropped". This keeps
// the feature honest today and leaves live re-pricing as a clean extension point.

if (!function_exists('ensurePriceAlertsSchema')) {
    function ensurePriceAlertsSchema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `price_alerts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` VARCHAR(64) NOT NULL,
                `module` VARCHAR(24) NOT NULL,
                `label` VARCHAR(255) NOT NULL,
                `params` TEXT NULL,
                `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `baseline_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `target_price` DECIMAL(14,2) NULL,
                `last_price` DECIMAL(14,2) NULL,
                `last_checked_at` DATETIME NULL,
                `status` ENUM('active','triggered','paused') NOT NULL DEFAULT 'active',
                `triggered_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_pa_user` (`user_id`, `status`),
                KEY `idx_pa_sweep` (`status`, `last_checked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('ensurePriceAlertsSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('price_alerts_list')) {
    function price_alerts_list($db, string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') { return []; }
        ensurePriceAlertsSchema($db);
        try {
            return $db->select('price_alerts',
                ['id', 'module', 'label', 'currency', 'baseline_price', 'target_price', 'last_price', 'status', 'last_checked_at', 'created_at'],
                ['user_id' => $userId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]) ?: [];
        } catch (\Throwable $e) { error_log('price_alerts_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('price_alert_create')) {
    /** @return int new id, or 0 on validation/DB failure. */
    function price_alert_create($db, string $userId, array $in): int
    {
        $userId = trim($userId);
        $module = strtolower(trim((string) ($in['module'] ?? '')));
        $label  = trim((string) ($in['label'] ?? ''));
        $baseline = round((float) ($in['baseline_price'] ?? 0), 2);
        if ($userId === '' || $module === '' || $label === '' || $baseline <= 0) { return 0; }

        // Only modules the sweep can re-price (see the watchable list in
        // priceAlertsRoutes.php) — reject the rest so no dead watch is ever created.
        $allowed = ['flights', 'stays', 'tours', 'cars', 'cruises', 'umrah'];
        if (!in_array($module, $allowed, true)) { return 0; }

        $target = isset($in['target_price']) && $in['target_price'] !== '' ? round((float) $in['target_price'], 2) : null;
        if ($target !== null && ($target <= 0 || $target >= $baseline)) {
            // A target must be a real drop below what they saw; otherwise ignore it
            // and treat the baseline as the trigger threshold.
            $target = null;
        }
        ensurePriceAlertsSchema($db);
        try {
            // Cap: don't let one user hoard thousands of watches.
            if ((int) $db->count('price_alerts', ['user_id' => $userId, 'status' => 'active']) >= 50) { return 0; }
            $db->insert('price_alerts', [
                'user_id'        => $userId,
                'module'         => $module,
                'label'          => substr($label, 0, 255),
                'params'         => isset($in['params']) ? substr(json_encode($in['params']), 0, 4000) : null,
                'currency'       => substr((string) ($in['currency'] ?? 'USD'), 0, 8),
                'baseline_price' => $baseline,
                'target_price'   => $target,
                'status'         => 'active',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('price_alert_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('price_alert_delete')) {
    function price_alert_delete($db, string $userId, int $id): bool
    {
        $userId = trim($userId);
        if ($userId === '' || $id <= 0) { return false; }
        try { $db->delete('price_alerts', ['user_id' => $userId, 'id' => $id]); return true; }
        catch (\Throwable $e) { error_log('price_alert_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('price_alert_current_price')) {
    /**
     * Resolve the CURRENT price for a watch, or null when no cheap live re-price is
     * wired for that module (in which case the sweep skips it — never a false fire).
     * This is the extension point: as per-module cheap lookups are added, return a
     * fresh figure here. Returns a float in the alert's own currency, or null.
     */
    function price_alert_current_price($db, array $alert): ?float
    {
        $module = strtolower((string) ($alert['module'] ?? ''));
        switch ($module) {
            case 'flights':
                return price_alert_price_flights($db, $alert);
            case 'stays':
                return price_alert_price_stays($db, $alert);
            case 'tours':
                return price_alert_price_tours($db, $alert);
            case 'cars':
                return price_alert_price_cars($db, $alert);
            case 'cruises':
                return price_alert_price_cruises($db, $alert);
            case 'umrah':
                return price_alert_price_umrah($db, $alert);
            default:
                // Not wired yet for this module → skip (no false drop).
                return null;
        }
    }
}

if (!function_exists('price_alert_price_flights')) {
    /**
     * Cheap re-price for an OWN-INVENTORY flight watch. Mirrors the customer flight
     * search (app/routes/api/flights/homeRoutes.php /api/flights/search): query the
     * `flights` table by from/to airport (+ date when set), apply the module markup
     * + FX into the alert's currency, and return the CHEAPEST economy adult fare.
     * DB-only (no external supplier call) so it's cron-cheap. Returns null when the
     * watch lacks the needed params, no matching flight exists, or pricing helpers
     * are absent — so an alert never fires on a guess.
     *
     * Covers GoGlobia own-inventory flights. Real-supplier (e.g. Adivaha) live
     * re-pricing would need a full external search and is intentionally NOT done
     * from the cron; such a watch stays "watching" until own-inventory matches.
     */
    function price_alert_price_flights($db, array $alert): ?float
    {
        $p = [];
        if (!empty($alert['params'])) {
            $decoded = json_decode((string) $alert['params'], true);
            if (is_array($decoded)) { $p = $decoded; }
        }
        // A real-SUPPLIER flight watch (params.supplier set) is re-priced only by the
        // separate throttled supplier sweep — never here in the fast DB-only path.
        if (!empty($p['supplier'])) { return null; }
        $from = (int) ($p['from_airport_id'] ?? 0);
        $to   = (int) ($p['to_airport_id'] ?? 0);
        $date = trim((string) ($p['departure_date'] ?? ''));
        if ($from <= 0 || $to <= 0) { return null; } // not enough to re-price → skip
        if (!function_exists('MARKUP')) { return null; }

        try {
            $where = ['from_airport_id' => $from, 'to_airport_id' => $to, 'economy_adult_price[>]' => 0];
            if ($date !== '') { $where['departure_date'] = $date; }
            $where['ORDER'] = ['economy_adult_price' => 'ASC'];
            $where['LIMIT'] = 5;

            $rows = $db->select('flights', ['economy_adult_price', 'currency'], $where) ?: [];
            if (!$rows) { return null; }

            $module = $db->get('modules', '*', ['name' => 'flights', 'type' => 'flights']);
            $target = strtoupper((string) ($alert['currency'] ?? 'USD'));

            $cheapest = null;
            foreach ($rows as $r) {
                $base = (float) ($r['economy_adult_price'] ?? 0);
                if ($base <= 0) { continue; }
                $fromCcy = strtoupper((string) ($r['currency'] ?? 'USD'));
                // Marked-up + converted into the alert's currency — exactly what the
                // customer saw on the results card (applyFlightMarkup()).
                $mk = MARKUP($base, $module, $db, $fromCcy, $target);
                $price = round((float) ($mk['price'] ?? 0), 2);
                if ($price > 0 && ($cheapest === null || $price < $cheapest)) {
                    $cheapest = $price;
                }
            }
            return $cheapest; // null when nothing priced cleanly → skip
        } catch (\Throwable $e) {
            error_log('price_alert_price_flights: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('price_alert_price_flights_supplier')) {
    /**
     * REAL-SUPPLIER (Adivaha) flight re-price. Unlike the DB-only resolvers, this
     * makes an EXTERNAL search call, so it is run ONLY from the dedicated, throttled
     * supplier sweep (price_alerts_supplier_sweep) — never the fast every-30-min sweep.
     *
     * It POSTs the SAME search the storefront uses (/modules/flights/adivaha/search),
     * which returns a normalised offers array (each offer already marked-up + in the
     * requested currency). We take the cheapest offer's `price`. The call is wrapped
     * in a HARD curl timeout so one slow/failed supplier response can't hang the
     * cron, and returns null on ANY problem (timeout / empty / error) → the watch is
     * skipped, never a false fire.
     *
     * @param string $baseUrl absolute site base (e.g. https://host/goglobia/) built
     *                        by the sweep from the inbound request.
     */
    function price_alert_price_flights_supplier($db, array $alert, string $baseUrl, int $timeout = 40): ?float
    {
        $p = [];
        if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $origin = strtoupper(trim((string) ($p['origin'] ?? '')));
        $dest   = strtoupper(trim((string) ($p['destination'] ?? '')));
        $date   = trim((string) ($p['departure_date'] ?? ''));
        if ($origin === '' || $dest === '' || $date === '') { return null; }
        if ($origin === $dest) { return null; }

        $baseUrl = rtrim($baseUrl, '/') . '/';
        $url = $baseUrl . 'modules/flights/adivaha/search';

        $post = [
            'origin'         => $origin,
            'destination'    => $dest,
            'departure_date' => $date,
            'type'           => 'oneway',
            'class'          => strtolower((string) ($p['class'] ?? 'economy')),
            'adults'         => (int) ($p['adults'] ?? 1) ?: 1,
            'childrens'      => (int) ($p['children'] ?? 0),
            'infants'        => (int) ($p['infants'] ?? 0),
            'currency'       => strtoupper((string) ($alert['currency'] ?? 'USD')),
        ];

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($post),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(10, min(90, $timeout)),
                CURLOPT_CONNECTTIMEOUT => 8,
                // Internal server-to-server call; mark it so the endpoint treats it
                // like the site's own JS (not an external API client).
                CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
                error_log('price_alert_price_flights_supplier: http ' . $code . ' ' . $err);
                return null;
            }
            $offers = json_decode((string) $raw, true);
            if (!is_array($offers) || !$offers) { return null; } // no offers → skip

            $cheapest = null;
            foreach ($offers as $o) {
                if (!is_array($o)) { continue; }
                $pr = (float) str_replace(',', '', (string) ($o['price'] ?? $o['adult_price'] ?? 0));
                if ($pr > 0 && ($cheapest === null || $pr < $cheapest)) { $cheapest = $pr; }
            }
            return $cheapest;
        } catch (\Throwable $e) {
            error_log('price_alert_price_flights_supplier: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('price_alert_price_stays_supplier')) {
    /**
     * REAL-SUPPLIER (Adivaha) hotel re-price. Like the supplier-flight resolver, it
     * makes an EXTERNAL search and is run ONLY from the throttled supplier sweep. It
     * POSTs the SAME search the storefront uses (/modules/stays/adivaha/search),
     * which returns {status, results:[{price, ...}]} with each result already
     * marked-up and in the requested currency. We take the cheapest result's `price`
     * (the property's total for the stay window). Hard curl timeout; returns null on
     * ANY problem (timeout / empty / error) → the watch is skipped, never a false
     * fire.
     *
     * params: {supplier:'adivaha', destination (city NAME), checkin, checkout,
     *          rooms?, adults?, children?}. The storefront endpoint resolves the
     *          Adivaha destination code from the city name server-side.
     *
     * @param string $baseUrl absolute site base built by the sweep (e.g. https://host/goglobia/).
     */
    function price_alert_price_stays_supplier($db, array $alert, string $baseUrl, int $timeout = 15): ?float
    {
        $p = [];
        if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $dest     = trim((string) ($p['destination'] ?? ''));
        $checkin  = trim((string) ($p['checkin'] ?? ''));
        $checkout = trim((string) ($p['checkout'] ?? ''));
        if ($dest === '' || $checkin === '' || $checkout === '') { return null; }
        if (strtotime($checkin) === false || strtotime($checkout) === false) { return null; }
        if (strtotime($checkout) <= strtotime($checkin)) { return null; }

        $url  = rtrim($baseUrl, '/') . '/modules/stays/adivaha/search';
        $post = [
            'destination' => $dest,
            'checkin'     => $checkin,
            'checkout'    => $checkout,
            'rooms'       => (int) ($p['rooms'] ?? 1) ?: 1,
            'adults'      => (int) ($p['adults'] ?? 2) ?: 2,
            'children'    => (int) ($p['children'] ?? 0),
            'currency'    => strtoupper((string) ($alert['currency'] ?? 'USD')),
        ];

        $raw = price_alert_supplier_post($url, $post, $timeout, 'price_alert_price_stays_supplier');
        if ($raw === null) { return null; }

        $decoded = json_decode($raw, true);
        // Stays endpoint wraps offers in {status, results:[...]}.
        $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        if (!$results) { return null; }

        $cheapest = null;
        foreach ($results as $o) {
            if (!is_array($o)) { continue; }
            $pr = (float) str_replace(',', '', (string) ($o['price'] ?? 0));
            if ($pr > 0 && ($cheapest === null || $pr < $cheapest)) { $cheapest = $pr; }
        }
        return $cheapest;
    }
}

if (!function_exists('price_alert_price_tours_supplier')) {
    /**
     * REAL-SUPPLIER (Adivaha) tour/excursion re-price. Runs ONLY from the throttled
     * supplier sweep. POSTs /modules/tours/adivaha/search, which returns a BARE
     * offers array (each already marked-up + in the requested currency); we take the
     * cheapest offer's `price` (total for the party). Hard curl timeout; null on any
     * problem → skip.
     *
     * params: {supplier:'adivaha', destination (city NAME), start_date?, adults?,
     *          children?, infants?}. The endpoint resolves the Adivaha destination
     *          code from the city name and defaults the date to +3 days when absent.
     */
    function price_alert_price_tours_supplier($db, array $alert, string $baseUrl, int $timeout = 15): ?float
    {
        $p = [];
        if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $dest = trim((string) ($p['destination'] ?? ''));
        if ($dest === '') { return null; }
        $date = trim((string) ($p['start_date'] ?? $p['date'] ?? ''));
        if ($date !== '' && strtotime($date) === false) { $date = ''; }

        $url  = rtrim($baseUrl, '/') . '/modules/tours/adivaha/search';
        $post = [
            'destination' => $dest,
            'start_date'  => $date, // endpoint defaults to +3 days when empty
            'adults'      => (int) ($p['adults'] ?? 1) ?: 1,
            'children'    => (int) ($p['children'] ?? 0),
            'infants'     => (int) ($p['infants'] ?? 0),
            'currency'    => strtoupper((string) ($alert['currency'] ?? 'USD')),
        ];

        $raw = price_alert_supplier_post($url, $post, $timeout, 'price_alert_price_tours_supplier');
        if ($raw === null) { return null; }

        $offers = json_decode($raw, true);
        // Tours endpoint returns a BARE array of offers.
        if (!is_array($offers) || !$offers) { return null; }

        $cheapest = null;
        foreach ($offers as $o) {
            if (!is_array($o)) { continue; }
            // Per-party total; price_per_person also present but we compare totals.
            $pr = (float) str_replace(',', '', (string) ($o['price'] ?? $o['amount'] ?? 0));
            if ($pr > 0 && ($cheapest === null || $pr < $cheapest)) { $cheapest = $pr; }
        }
        return $cheapest;
    }
}

if (!function_exists('price_alert_supplier_post')) {
    /**
     * Shared server-to-server POST to a storefront supplier-search endpoint for the
     * throttled price sweep. HARD curl timeout so one slow/failed supplier response
     * can't hang the cron; marks itself X-Requested-With so the endpoint treats it
     * like the site's own JS. Returns the raw response body, or null on any
     * transport/HTTP failure (→ caller skips the watch, never a false fire).
     */
    function price_alert_supplier_post(string $url, array $post, int $timeout, string $ctx): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($post),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(10, min(90, $timeout)),
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
                error_log($ctx . ': http ' . $code . ' ' . $err);
                return null;
            }
            return (string) $raw;
        } catch (\Throwable $e) {
            error_log($ctx . ': ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('price_alert_price_stays')) {
    /**
     * Cheap re-price for an OWN-INVENTORY stay watch. Mirrors the own-stays search
     * (app/routes/api/stays/homeRoutes.php): for the watched property, take the
     * MINIMUM room-option price across its active rooms (the "from / starting"
     * price), then apply the 'hotels' module markup + FX into the alert's currency.
     * DB-only (decodes stays_rooms.room_options JSON — no external supplier call) so
     * it's cron-cheap. Returns null when the watch has no stay_id, the stay/rooms
     * are gone or unpriced, or MARKUP is absent — so an alert never fires on a guess.
     *
     * Covers GoGlobia own-inventory stays (module 'hotels'). Real-supplier hotels
     * (hotelbeds/stuba/ratehawk/…) need a full external availability search and are
     * intentionally NOT re-priced from the cron.
     */
    function price_alert_price_stays($db, array $alert): ?float
    {
        $p = [];
        if (!empty($alert['params'])) {
            $decoded = json_decode((string) $alert['params'], true);
            if (is_array($decoded)) { $p = $decoded; }
        }
        // A real-SUPPLIER stay watch (params.supplier set) is re-priced only by the
        // separate throttled supplier sweep — never here in the fast DB-only path.
        if (!empty($p['supplier'])) { return null; }
        $stayId = (int) ($p['stay_id'] ?? 0);
        if ($stayId <= 0) { return null; }               // nothing to re-price → skip
        if (!function_exists('MARKUP')) { return null; }

        try {
            $stay = $db->get('stays', ['id', 'currency', 'status'], ['id' => $stayId]);
            if (!$stay) { return null; }

            // Cheapest active room-option price for this property (same as the search).
            $rooms = $db->select('stays_rooms', ['room_options'], ['stay_id' => $stayId, 'status' => 1]) ?: [];
            $minPrice = null;
            foreach ($rooms as $r) {
                $opts = json_decode((string) ($r['room_options'] ?? '[]'), true);
                if (!is_array($opts)) { continue; }
                foreach ($opts as $o) {
                    if (!is_array($o) || !isset($o['price'])) { continue; }
                    // Respect an option's own active flag when present.
                    if (isset($o['status']) && (int) $o['status'] === 0) { continue; }
                    $pr = (float) $o['price'];
                    if ($pr > 0 && ($minPrice === null || $pr < $minPrice)) { $minPrice = $pr; }
                }
            }
            if ($minPrice === null || $minPrice <= 0) { return null; }

            $module  = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays']);
            $fromCcy = strtoupper((string) ($stay['currency'] ?? 'USD'));
            $target  = strtoupper((string) ($alert['currency'] ?? 'USD'));

            $mk = MARKUP($minPrice, $module ?: 'stays', $db, $fromCcy, $target);
            $price = round((float) ($mk['price'] ?? 0), 2);
            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            error_log('price_alert_price_stays: ' . $e->getMessage());
            return null;
        }
    }
}

// Small shared helper: marked-up + FX-converted price for an own-inventory base
// amount, using the given module row. Returns null on any failure (→ skip).
if (!function_exists('price_alert_marked_up')) {
    function price_alert_marked_up($db, ?array $module, float $base, string $fromCcy, string $toCcy): ?float
    {
        if ($base <= 0 || !function_exists('MARKUP')) { return null; }
        try {
            $mk = MARKUP($base, $module ?: [], $db, strtoupper($fromCcy ?: 'USD'), strtoupper($toCcy ?: 'USD'));
            $p = round((float) ($mk['price'] ?? 0), 2);
            return $p > 0 ? $p : null;
        } catch (\Throwable $e) {
            error_log('price_alert_marked_up: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('price_alert_price_tours')) {
    /**
     * OWN-INVENTORY tour re-price: the tour's adult_price, marked up + FX into the
     * alert's currency. Mirrors the tours listing (module 'tours'). DB-only.
     */
    function price_alert_price_tours($db, array $alert): ?float
    {
        $p = []; if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        // Real-SUPPLIER tour watch → handled only by the throttled supplier sweep.
        if (!empty($p['supplier'])) { return null; }
        $id = (int) ($p['tour_id'] ?? 0);
        if ($id <= 0) { return null; }
        try {
            $t = $db->get('tours', ['adult_price', 'currency', 'status'], ['id' => $id]);
            if (!$t) { return null; }
            $module = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours']);
            return price_alert_marked_up($db, $module, (float) ($t['adult_price'] ?? 0), (string) ($t['currency'] ?? 'USD'), (string) ($alert['currency'] ?? 'USD'));
        } catch (\Throwable $e) { error_log('price_alert_price_tours: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('price_alert_price_cars')) {
    /**
     * OWN-INVENTORY car re-price: the car's rate for the watched route (the min
     * `price` across matching entries in cars.routes JSON, or the car's cheapest
     * route when no route is pinned), marked up + FX. DB-only.
     */
    function price_alert_price_cars($db, array $alert): ?float
    {
        $p = []; if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $carId = (int) ($p['car_id'] ?? 0);
        if ($carId <= 0) { return null; }
        $fromLoc = (int) ($p['from_location_id'] ?? 0);
        $toLoc   = (int) ($p['to_location_id'] ?? 0);
        try {
            $car = $db->get('cars', ['currency', 'routes', 'status'], ['id' => $carId]);
            if (!$car) { return null; }
            $routes = json_decode((string) ($car['routes'] ?? '[]'), true);
            if (!is_array($routes) || !$routes) { return null; }

            $min = null; $rowCcy = (string) ($car['currency'] ?? 'USD');
            foreach ($routes as $r) {
                if (!is_array($r) || !isset($r['price'])) { continue; }
                // If a specific route was pinned, only that route counts.
                if ($fromLoc > 0 && $toLoc > 0) {
                    if ((int) ($r['from_location_id'] ?? 0) !== $fromLoc || (int) ($r['to_location_id'] ?? 0) !== $toLoc) { continue; }
                }
                $pr = (float) $r['price'];
                if ($pr > 0 && ($min === null || $pr < $min)) {
                    $min = $pr;
                    if (!empty($r['currency'])) { $rowCcy = (string) $r['currency']; }
                }
            }
            if ($min === null) { return null; }
            $module = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars']);
            return price_alert_marked_up($db, $module, $min, $rowCcy, (string) ($alert['currency'] ?? 'USD'));
        } catch (\Throwable $e) { error_log('price_alert_price_cars: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('price_alert_price_cruises')) {
    /**
     * OWN-INVENTORY cruise re-price: the cruise's adult price, marked up + FX.
     * Cruises are confirm-on-payment own inventory (module 'goglobia' at booking),
     * but the DISPLAY price uses the 'cruises' module markup — mirror the display.
     */
    function price_alert_price_cruises($db, array $alert): ?float
    {
        $p = []; if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $id = (int) ($p['cruise_id'] ?? 0);
        if ($id <= 0) { return null; }
        try {
            $c = $db->get('cruises', ['price', 'currency', 'status'], ['id' => $id]);
            if (!$c) { return null; }
            $module = $db->get('modules', '*', ['name' => 'cruises', 'type' => 'cruises', 'status' => '1'])
                   ?: $db->get('modules', '*', ['type' => 'cruises', 'name' => 'cruises']);
            return price_alert_marked_up($db, $module, (float) ($c['price'] ?? 0), (string) ($c['currency'] ?? 'USD'), (string) ($alert['currency'] ?? 'USD'));
        } catch (\Throwable $e) { error_log('price_alert_price_cruises: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('price_alert_price_umrah')) {
    /**
     * OWN-INVENTORY umrah re-price: the watched departure-tier's effective customer
     * price — the lower of promo_price (when set) and regular_price. A live promo is
     * literally a price drop. Umrah tiers are priced in the tier's own currency; use
     * it as the source and convert into the alert's currency (no module markup — the
     * tier price IS the sell price, same as the umrah service layer).
     */
    function price_alert_price_umrah($db, array $alert): ?float
    {
        $p = []; if (!empty($alert['params'])) { $d = json_decode((string) $alert['params'], true); if (is_array($d)) { $p = $d; } }
        $tierId = (int) ($p['tier_id'] ?? 0);
        if ($tierId <= 0) { return null; }
        try {
            $t = $db->get('umrah_departure_tiers',
                ['regular_price', 'promo_price', 'promo_active', 'promo_start', 'promo_end', 'currency'],
                ['id' => $tierId]);
            if (!$t) { return null; }
            $reg   = (float) ($t['regular_price'] ?? 0);
            $promo = (float) ($t['promo_price'] ?? 0);
            // Only honour the promo when it's flagged active AND within its window —
            // otherwise the customer pays the regular price (same rule the umrah
            // service layer uses). A live promo is a genuine price drop.
            $now = date('Y-m-d H:i:s');
            $promoLive = $promo > 0
                && (int) ($t['promo_active'] ?? 0) === 1
                && (empty($t['promo_start']) || (string) $t['promo_start'] <= $now)
                && (empty($t['promo_end'])   || (string) $t['promo_end']   >= $now);
            $eff = $promoLive ? min($promo, $reg > 0 ? $reg : $promo) : $reg;
            if ($eff <= 0) { return null; }
            $fromCcy = strtoupper((string) ($t['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));
            $toCcy   = strtoupper((string) ($alert['currency'] ?? 'USD'));
            if ($fromCcy === $toCcy || !function_exists('CURRENCY_CONVERT')) { return round($eff, 2); }
            $cv = CURRENCY_CONVERT($eff, $db, $fromCcy, $toCcy);
            $out = round((float) ($cv['price'] ?? 0), 2);
            return $out > 0 ? $out : null;
        } catch (\Throwable $e) { error_log('price_alert_price_umrah: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('price_alerts_sweep')) {
    /**
     * Cron worker: re-check active alerts, notify on a qualifying drop. Idempotent
     * (an alert flips to 'triggered' and won't re-fire). Bounded per run. Returns a
     * small summary array for the cron response.
     */
    function price_alerts_sweep($db, int $limit = 200): array
    {
        ensurePriceAlertsSchema($db);
        $checked = 0; $triggered = 0; $skipped = 0;
        try {
            $rows = $db->select('price_alerts', '*', [
                'status' => 'active',
                'ORDER'  => ['last_checked_at' => 'ASC'],
                'LIMIT'  => max(1, min(1000, $limit)),
            ]) ?: [];
        } catch (\Throwable $e) { error_log('price_alerts_sweep select: ' . $e->getMessage()); return ['ok' => false]; }

        foreach ($rows as $a) {
            $checked++;
            $cur = null;
            try { $cur = price_alert_current_price($db, $a); } catch (\Throwable $e) { $cur = null; }

            // Always stamp the check so the sweep rotates fairly, even when skipped.
            try {
                $db->update('price_alerts',
                    ['last_checked_at' => date('Y-m-d H:i:s'), 'last_price' => $cur],
                    ['id' => (int) $a['id']]);
            } catch (\Throwable $e) { /* non-fatal */ }

            if ($cur === null) { $skipped++; continue; }

            $baseline = (float) ($a['baseline_price'] ?? 0);
            $target   = ($a['target_price'] !== null && $a['target_price'] !== '') ? (float) $a['target_price'] : null;
            $threshold = $target !== null ? $target : $baseline;

            if ($cur <= $threshold && $cur > 0) {
                // Fire once: notify + mark triggered.
                $cur2 = number_format($cur, 2);
                $curr = (string) ($a['currency'] ?? 'USD');
                if (function_exists('notify_user')) {
                    notify_user($db, (string) $a['user_id'], [
                        'type'       => 'price_alert',
                        'title'      => 'Price drop: ' . (string) $a['label'],
                        'body'       => 'Now ' . $curr . ' ' . $cur2 . ' (you saw ' . $curr . ' ' . number_format($baseline, 2) . ').',
                        'url'        => root . 'price-alerts',
                        'icon'       => 'trending_down',
                        'ref_type'   => 'price_alert',
                        'ref_id'     => (string) $a['id'],
                        'dedupe_key' => 'pricealert-' . $a['id'] . '-trig',
                    ]);
                }
                try {
                    $db->update('price_alerts',
                        ['status' => 'triggered', 'triggered_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $a['id']]);
                } catch (\Throwable $e) { /* non-fatal */ }
                $triggered++;
            }
        }
        return ['ok' => true, 'checked' => $checked, 'triggered' => $triggered, 'skipped' => $skipped];
    }
}

if (!function_exists('price_alerts_supplier_sweep')) {
    /**
     * THROTTLED real-supplier sweep. Separate from the fast DB-only sweep because
     * each re-price is an EXTERNAL Adivaha search (seconds, can fail). Keep $maxCalls
     * small and the cadence low (hourly) so one slow/failed supplier call can neither
     * hang the fast sweep nor hammer the supplier. Processes the longest-unchecked
     * supplier watches first (fair rotation), across the wired supplier modules
     * (flights / stays / tours — all via the Adivaha storefront search).
     *
     * @param string $baseUrl absolute site base for the server-to-server search call.
     */
    function price_alerts_supplier_sweep($db, string $baseUrl, int $maxCalls = 15, int $perCallTimeout = 15): array
    {
        ensurePriceAlertsSchema($db);
        $checked = 0; $triggered = 0; $skipped = 0;
        if (trim($baseUrl) === '') { return ['ok' => false, 'message' => 'no base url']; }

        // Total wall-time budget so the run always finishes cleanly (and never trips
        // max_execution_time mid-loop). Stop starting new supplier calls past this.
        $deadline = time() + 240;

        // Module → supplier resolver. Any watch whose module is here AND carries
        // params.supplier is a real-supplier watch handled by this sweep only.
        $resolvers = [
            'flights' => 'price_alert_price_flights_supplier',
            'stays'   => 'price_alert_price_stays_supplier',
            'tours'   => 'price_alert_price_tours_supplier',
        ];

        try {
            // Supplier watches carry params.supplier — match on the raw JSON, across
            // all wired supplier modules.
            $rows = $db->select('price_alerts', '*', [
                'status'     => 'active',
                'module'     => array_keys($resolvers),
                'params[~]'  => '%"supplier"%',
                'ORDER'      => ['last_checked_at' => 'ASC'],
                'LIMIT'      => max(1, min(100, $maxCalls)),
            ]) ?: [];
        } catch (\Throwable $e) { error_log('price_alerts_supplier_sweep select: ' . $e->getMessage()); return ['ok' => false]; }

        foreach ($rows as $a) {
            // Budget guard: leave enough room for one more call + the DB updates.
            if (time() + $perCallTimeout + 5 > $deadline) { break; }
            $checked++;
            $mod = strtolower((string) ($a['module'] ?? ''));
            $fn  = $resolvers[$mod] ?? null;
            $cur = null;
            if ($fn !== null && function_exists($fn)) {
                try { $cur = $fn($db, $a, $baseUrl, $perCallTimeout); }
                catch (\Throwable $e) { $cur = null; }
            }

            try {
                $db->update('price_alerts',
                    ['last_checked_at' => date('Y-m-d H:i:s'), 'last_price' => $cur],
                    ['id' => (int) $a['id']]);
            } catch (\Throwable $e) { /* non-fatal */ }

            if ($cur === null) { $skipped++; continue; }

            $baseline = (float) ($a['baseline_price'] ?? 0);
            $target   = ($a['target_price'] !== null && $a['target_price'] !== '') ? (float) $a['target_price'] : null;
            $threshold = $target !== null ? $target : $baseline;

            if ($cur <= $threshold && $cur > 0) {
                $cur2 = number_format($cur, 2);
                $curr = (string) ($a['currency'] ?? 'USD');
                if (function_exists('notify_user')) {
                    notify_user($db, (string) $a['user_id'], [
                        'type'       => 'price_alert',
                        'title'      => 'Price drop: ' . (string) $a['label'],
                        'body'       => 'Now ' . $curr . ' ' . $cur2 . ' (you saw ' . $curr . ' ' . number_format($baseline, 2) . ').',
                        'url'        => root . 'price-alerts',
                        'icon'       => 'trending_down',
                        'ref_type'   => 'price_alert',
                        'ref_id'     => (string) $a['id'],
                        'dedupe_key' => 'pricealert-' . $a['id'] . '-trig',
                    ]);
                }
                try {
                    $db->update('price_alerts',
                        ['status' => 'triggered', 'triggered_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $a['id']]);
                } catch (\Throwable $e) { /* non-fatal */ }
                $triggered++;
            }
        }
        return ['ok' => true, 'checked' => $checked, 'triggered' => $triggered, 'skipped' => $skipped];
    }
}
