<?php
// ============================================================================
// FILE: app/lib/ai/gia_service_expiry.php
// Per-service GIA booking-expiry config (owner spec: default 20 min, but the
// admin can set the expiry PER SERVICE — umrah vs stays vs flights — on GIA).
// Stored as a JSON map in settings.gia_service_expiry, e.g. {"umrah":30}.
//
// Readers already in place:
//   - gia_umrah_expiry_minutes($db)            [app/lib/ai/gia_umrah.php]
//   - gia_bp_window_minutes($db,$module)       [app/lib/ai/gia_booking_payment.php]
// Both fall back to 20 when a service has no explicit value, so this config is
// purely additive and safe on installs that never set it.
//
// Self-healing schema (house pattern): the column is created on demand; nothing
// breaks if the DB user lacks ALTER (the readers still default to 20).
// ============================================================================

if (!function_exists('gia_service_expiry_ensure_column')) {
    /** Idempotently add settings.gia_service_expiry (TEXT, JSON map). */
    function gia_service_expiry_ensure_column($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $cols = $db->query("SHOW COLUMNS FROM `settings` LIKE 'gia_service_expiry'")->fetchAll();
            if (!$cols) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `gia_service_expiry` TEXT NULL DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            error_log('[gia_service_expiry] ensure column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_service_expiry_services')) {
    /** The services GIA can transact (so the admin form lists them all). */
    function gia_service_expiry_services(): array
    {
        return ['umrah', 'stays', 'flights', 'tours', 'cars', 'bus', 'visa', 'esim', 'rail', 'ferries'];
    }
}

if (!function_exists('gia_service_expiry_get')) {
    /**
     * The current per-service expiry map (minutes). Missing services are returned
     * as the default 20 so the admin form always shows a value.
     * @return array<string,int>
     */
    function gia_service_expiry_get($db): array
    {
        gia_service_expiry_ensure_column($db);
        $cfg = [];
        try {
            $raw = $db->get('settings', 'gia_service_expiry', ['id' => 1]);
            $cfg = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        } catch (\Throwable $e) { $cfg = []; }
        $out = [];
        foreach (gia_service_expiry_services() as $svc) {
            $v = isset($cfg[$svc]) ? (int) $cfg[$svc] : 20;
            $out[$svc] = max(1, min(1440, $v > 0 ? $v : 20));
        }
        return $out;
    }
}

if (!function_exists('gia_service_expiry_save')) {
    /**
     * Persist the per-service expiry map. Accepts a partial map; each value is
     * clamped to [1,1440] minutes; non-service keys ignored. Returns true on write.
     * @param array<string,mixed> $incoming
     */
    function gia_service_expiry_save($db, array $incoming): bool
    {
        gia_service_expiry_ensure_column($db);
        $allowed = gia_service_expiry_services();
        $clean = [];
        foreach ($allowed as $svc) {
            if (!isset($incoming[$svc])) { continue; }
            $v = (int) $incoming[$svc];
            if ($v > 0) { $clean[$svc] = max(1, min(1440, $v)); }
        }
        try {
            $db->update('settings', ['gia_service_expiry' => json_encode($clean)], ['id' => 1]);
            return true;
        } catch (\Throwable $e) {
            error_log('[gia_service_expiry] save: ' . $e->getMessage());
            return false;
        }
    }
}
