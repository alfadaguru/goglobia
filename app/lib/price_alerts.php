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

        $allowed = ['flights', 'stays', 'tours', 'cars', 'bus', 'ferries', 'rail', 'cruises', 'esim', 'visa', 'umrah'];
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
