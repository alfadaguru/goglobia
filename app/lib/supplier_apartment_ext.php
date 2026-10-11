<?php
// FILE: app/lib/supplier_apartment_ext.php
// MODULE 47 (Apartment & vacation-rental → full spec): the genuine sub-features
// missing from the base register. Base already ships:
//   · stays.accommodation_type ('apartment' etc) + cleaning_fee + security_deposit
//   · stays_physical_rooms (the physical units) + online check-in
// This extension adds, on NEW tables only, the spec gaps:
//   · UNIT-LEVEL listing config — extra-guest fee/threshold, pet fee/allowed, base
//     occupancy — per physical unit (stays_apartment_units).
//   · SELF CHECK-IN instructions — access method, lockbox/smart-lock code, wifi,
//     arrival directions — per physical unit (stays_apartment_checkin).
//   · TURNOVER management — a cleaning/turnover task between guests, tied to a unit,
//     with status workflow (stays_apartment_turnovers).
//   · DAMAGE reporting — record damage against a unit/reservation, estimate cost,
//     and (DB-GATED) post a trusted damage charge to the guest folio + a balanced GL
//     entry (stays_apartment_damage).
// All functions are property-scoped — the CALLER authorizes the stay via supplier_can.
// Money is NEVER taken from the client: damage charge amount is the staff-entered
// estimate, clamped to the property's security_deposit, posted via folio_add_item
// (trusted) and mirrored to the GL via gl_post (balanced). Defensive/non-fatal.
// NOT runtime-verified (no MySQL in the build env) — php -l only.

// ---------------------------------------------------------------------------
// Internal: resolve a physical unit's owning stay_id (for IDOR re-checks in routes).
// ---------------------------------------------------------------------------
if (!function_exists('apartment_unit_stay_id')) {
    /** Return the stay_id that owns a physical unit (stays_physical_rooms) id, or 0. */
    function apartment_unit_stay_id($db, int $unitId): int
    {
        if ($unitId <= 0) { return 0; }
        try {
            $row = $db->get('stays_physical_rooms', ['stay_id'], ['id' => $unitId]);
            return $row ? (int) $row['stay_id'] : 0;
        } catch (\Throwable $e) { error_log('apartment_unit_stay_id: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('apartment_units_for_stay')) {
    /** The physical units of a property with their apartment config/check-in joined in.
     *  Read-only. Each row carries the base stays_physical_rooms fields plus cfg_* /
     *  ci_* keys ([] when not yet configured). */
    function apartment_units_for_stay($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try {
            $rooms = $db->select('stays_physical_rooms', ['id', 'room_number', 'floor', 'wing', 'active'],
                ['stay_id' => $stayId, 'ORDER' => ['room_number' => 'ASC']]) ?: [];
            if (empty($rooms)) { return []; }
            $ids = array_map(fn($r) => (int) $r['id'], $rooms);
            $cfg = []; $ci = [];
            foreach ($db->select('stays_apartment_units', '*', ['unit_id' => $ids]) ?: [] as $c) { $cfg[(int) $c['unit_id']] = $c; }
            foreach ($db->select('stays_apartment_checkin', '*', ['unit_id' => $ids]) ?: [] as $c) { $ci[(int) $c['unit_id']] = $c; }
            foreach ($rooms as &$r) {
                $r['cfg'] = $cfg[(int) $r['id']] ?? [];
                $r['checkin'] = $ci[(int) $r['id']] ?? [];
            }
            unset($r);
            return $rooms;
        } catch (\Throwable $e) { error_log('apartment_units_for_stay: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// UNIT-LEVEL CONFIG — extra-guest fee / threshold, pet policy, base occupancy.
// These are catalogue/pricing config, NOT a money movement: no folio/GL here.
// ---------------------------------------------------------------------------
if (!function_exists('apartment_unit_config_save')) {
    /**
     * Upsert the per-unit apartment config. $stayId MUST be the unit's owning stay (the
     * route resolves it from the unit, not client input). All numerics are clamped to
     * sane non-negative bounds. Returns bool.
     */
    function apartment_unit_config_save($db, int $unitId, int $stayId, array $d): bool
    {
        if ($unitId <= 0 || $stayId <= 0) { return false; }
        try {
            // Defence in depth: the unit must exist AND belong to $stayId.
            if (!$db->has('stays_physical_rooms', ['id' => $unitId, 'stay_id' => $stayId])) { return false; }

            $baseOcc  = max(1, min(64, (int) ($d['base_occupancy'] ?? 2)));
            $maxOcc   = max($baseOcc, min(64, (int) ($d['max_occupancy'] ?? $baseOcc)));
            $xGuest   = max(0, round((float) ($d['extra_guest_fee'] ?? 0), 2));
            $petFee   = max(0, round((float) ($d['pet_fee'] ?? 0), 2));
            $petsAllow = !empty($d['pets_allowed']) ? 1 : 0;

            $fields = [
                'base_occupancy'  => $baseOcc,
                'max_occupancy'   => $maxOcc,
                'extra_guest_fee' => $xGuest,
                'pets_allowed'    => $petsAllow,
                'pet_fee'         => $petsAllow ? $petFee : 0.00,
                'updated_at'      => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_apartment_units', ['unit_id' => $unitId])) {
                $db->update('stays_apartment_units', $fields, ['unit_id' => $unitId]);
            } else {
                $fields['unit_id'] = $unitId;
                $fields['stay_id'] = $stayId;
                $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_apartment_units', $fields);
            }
            return true;
        } catch (\Throwable $e) { error_log('apartment_unit_config_save: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// SELF CHECK-IN INSTRUCTIONS — per unit. Access method + lockbox/smart-lock code,
// wifi, arrival directions. RECORD-KEEPING ONLY — no smart-lock device is actuated
// (no device integration shipped; see apartment_smartlock_status / deferred[]).
// ---------------------------------------------------------------------------
if (!function_exists('apartment_checkin_methods')) {
    function apartment_checkin_methods(): array
    {
        return [
            'lockbox'     => 'Lockbox / key safe',
            'smart_lock'  => 'Smart lock (code)',
            'keypad'      => 'Keypad entry',
            'reception'   => 'Meet at reception / host',
            'concierge'   => 'Building concierge',
        ];
    }
}

if (!function_exists('apartment_checkin_save')) {
    /**
     * Upsert the per-unit self-check-in instructions. $stayId MUST be the unit's owning
     * stay. For device-backed methods (lockbox/smart_lock/keypad) an access code is
     * required. Returns bool.
     */
    function apartment_checkin_save($db, int $unitId, int $stayId, array $d): bool
    {
        if ($unitId <= 0 || $stayId <= 0) { return false; }
        $method = (string) ($d['access_method'] ?? '');
        if (!isset(apartment_checkin_methods()[$method])) { return false; }
        $code = trim((string) ($d['access_code'] ?? ''));
        if (in_array($method, ['lockbox', 'smart_lock', 'keypad'], true) && $code === '') { return false; }
        try {
            if (!$db->has('stays_physical_rooms', ['id' => $unitId, 'stay_id' => $stayId])) { return false; }
            $fields = [
                'access_method'  => $method,
                'access_code'    => $code !== '' ? substr($code, 0, 64) : null,
                'wifi_ssid'      => substr(trim((string) ($d['wifi_ssid'] ?? '')), 0, 120) ?: null,
                'wifi_password'  => substr(trim((string) ($d['wifi_password'] ?? '')), 0, 120) ?: null,
                'directions'     => substr(trim((string) ($d['directions'] ?? '')), 0, 2000) ?: null,
                'checkin_notes'  => substr(trim((string) ($d['checkin_notes'] ?? '')), 0, 2000) ?: null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_apartment_checkin', ['unit_id' => $unitId])) {
                $db->update('stays_apartment_checkin', $fields, ['unit_id' => $unitId]);
            } else {
                $fields['unit_id'] = $unitId;
                $fields['stay_id'] = $stayId;
                $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_apartment_checkin', $fields);
            }
            return true;
        } catch (\Throwable $e) { error_log('apartment_checkin_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('apartment_smartlock_status')) {
    /**
     * HONEST STUB: reports whether a live smart-lock integration is connected for a unit.
     * No smart-lock vendor (August/Nuki/Igloohome/etc.) is integrated — this always
     * reports 'not connected'. The stored access_code is the operational source of truth.
     */
    function apartment_smartlock_status($db, int $unitId): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'No smart-lock integration is connected. The stored access code is used for self-check-in.',
        ];
    }
}

// ---------------------------------------------------------------------------
// TURNOVER MANAGEMENT — a cleaning/turnover task between guests, tied to a unit.
// Status workflow: pending → in_progress → done (or blocked). No money.
// Complements base housekeeping hk_status on the physical room; this is the
// scheduled-task layer (due date, assignee, checklist notes).
// ---------------------------------------------------------------------------
if (!function_exists('apartment_turnover_statuses')) {
    function apartment_turnover_statuses(): array
    {
        return ['pending' => 'Pending', 'in_progress' => 'In progress', 'done' => 'Done', 'blocked' => 'Blocked'];
    }
}

if (!function_exists('apartment_turnover_create')) {
    /**
     * Create a turnover task for a unit. $stayId MUST be the unit's owning stay. due_date
     * optional (YYYY-MM-DD). Returns the new id or 0. No money.
     */
    function apartment_turnover_create($db, int $unitId, int $stayId, array $d): int
    {
        if ($unitId <= 0 || $stayId <= 0) { return 0; }
        try {
            if (!$db->has('stays_physical_rooms', ['id' => $unitId, 'stay_id' => $stayId])) { return 0; }
            $due = trim((string) ($d['due_date'] ?? ''));
            $db->insert('stays_apartment_turnovers', [
                'stay_id'     => $stayId,
                'unit_id'     => $unitId,
                'status'      => 'pending',
                'due_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                'assigned_to' => substr(trim((string) ($d['assigned_to'] ?? '')), 0, 155) ?: null,
                'checklist'   => substr(trim((string) ($d['checklist'] ?? '')), 0, 2000) ?: null,
                'notes'       => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_by'  => (string) ($_SESSION['user_id'] ?? '') ?: 'staff',
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('apartment_turnover_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('apartment_turnover_set_status')) {
    /**
     * Transition a turnover task's status. $stayId MUST be the task's owning stay (route
     * resolves it from the task). Stamps done_at when moving to 'done'. Returns bool.
     */
    function apartment_turnover_set_status($db, int $taskId, int $stayId, string $status): bool
    {
        if ($taskId <= 0 || $stayId <= 0) { return false; }
        if (!isset(apartment_turnover_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_apartment_turnovers', ['id' => $taskId, 'stay_id' => $stayId])) { return false; }
            $fields = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
            $fields['done_at'] = ($status === 'done') ? date('Y-m-d H:i:s') : null;
            $db->update('stays_apartment_turnovers', $fields, ['id' => $taskId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('apartment_turnover_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('apartment_turnover_board')) {
    /** Turnover tasks across $stayIds (optionally only open). Read-only, newest due first. */
    function apartment_turnover_board($db, array $stayIds, bool $openOnly = false, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            $where = ['stay_id' => $stayIds];
            if ($openOnly) { $where['status'] = ['pending', 'in_progress', 'blocked']; }
            $where['ORDER'] = ['due_date' => 'ASC', 'id' => 'DESC'];
            $where['LIMIT'] = max(1, $limit);
            return $db->select('stays_apartment_turnovers', '*', $where) ?: [];
        } catch (\Throwable $e) { error_log('apartment_turnover_board: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// DAMAGE REPORTING — record damage against a unit (+ optional reservation), estimate
// a cost, and optionally post it to the guest folio & GL. Append-only report rows;
// a report may later be marked billed/waived/resolved.
// ---------------------------------------------------------------------------
if (!function_exists('apartment_damage_statuses')) {
    function apartment_damage_statuses(): array
    {
        return ['reported' => 'Reported', 'billed' => 'Billed to guest', 'waived' => 'Waived', 'resolved' => 'Resolved'];
    }
}

if (!function_exists('apartment_damage_report')) {
    /**
     * File a damage report for a unit. $stayId MUST be the unit's owning stay. A
     * reservation (invoice_id) is accepted ONLY if its folio belongs to THIS stay
     * (IDOR guard, mirrors the folio engine). Estimated cost is clamped >= 0. No money
     * is posted here — that is the explicit apartment_damage_charge step. Returns new id / 0.
     */
    function apartment_damage_report($db, int $unitId, int $stayId, array $d): int
    {
        if ($unitId <= 0 || $stayId <= 0) { return 0; }
        $desc = trim((string) ($d['description'] ?? ''));
        if ($desc === '') { return 0; }
        try {
            if (!$db->has('stays_physical_rooms', ['id' => $unitId, 'stay_id' => $stayId])) { return 0; }

            // Validate the invoice (reservation) belongs to this stay, if supplied.
            $invoiceId = trim((string) ($d['invoice_id'] ?? ''));
            if ($invoiceId !== '' && function_exists('folio_get_or_create')) {
                $ok = false;
                try {
                    $fid = folio_get_or_create($db, $invoiceId);
                    if ($fid > 0) {
                        $f = $db->get('stays_folios', ['stay_id'], ['id' => $fid]);
                        $ok = $f && (int) ($f['stay_id'] ?? 0) === $stayId;
                    }
                } catch (\Throwable $e) { $ok = false; }
                if (!$ok) { $invoiceId = ''; }
            }

            $actor = (string) ($_SESSION['user_id'] ?? '') ?: 'staff';
            $db->insert('stays_apartment_damage', [
                'stay_id'        => $stayId,
                'unit_id'        => $unitId,
                'invoice_id'     => $invoiceId !== '' ? $invoiceId : null,
                'status'         => 'reported',
                'description'    => substr($desc, 0, 1000),
                'estimated_cost' => max(0, round((float) ($d['estimated_cost'] ?? 0), 2)),
                'charged_amount' => 0.00,
                'photos'         => null,
                'reported_by'    => $actor,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('apartment_damage_report: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('apartment_damage_set_status')) {
    /** Mark a damage report waived/resolved (non-billing transitions). $stayId owns it. */
    function apartment_damage_set_status($db, int $reportId, int $stayId, string $status): bool
    {
        if ($reportId <= 0 || $stayId <= 0) { return false; }
        if (!in_array($status, ['waived', 'resolved'], true)) { return false; }
        try {
            if (!$db->has('stays_apartment_damage', ['id' => $reportId, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_apartment_damage', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $reportId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('apartment_damage_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('apartment_damage_list')) {
    /** Damage reports across $stayIds (newest first). Read-only. */
    function apartment_damage_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_apartment_damage', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('apartment_damage_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// DAMAGE CHARGE (DB-GATED MONEY) — post a trusted damage charge to the guest folio
// and mirror it to the GL. The amount is the STAFF-ENTERED damage amount, NEVER the
// client; it is clamped to the property's security_deposit ceiling (if one is set).
// The folio charge + GL entry + report update are wrapped in one $db->action() so the
// three never diverge. Idempotent on the GL side via a per-report reference.
// ---------------------------------------------------------------------------
if (!function_exists('apartment_damage_charge')) {
    /**
     * Charge a reported damage to the guest's folio. $stayId MUST own the report.
     * $amount is the supplier-entered charge (not client-trusted on the storefront);
     * clamped to (0, security_deposit] when a deposit is configured, else just > 0.
     * DB-GATED: requires an open folio for the report's reservation; refuses otherwise
     * rather than recording an uncharged loss. Returns ['ok'=>bool,'message'=>..,'amount'=>float].
     */
    function apartment_damage_charge($db, int $reportId, int $stayId, float $amount): array
    {
        if ($reportId <= 0 || $stayId <= 0) { return ['ok' => false, 'message' => 'Bad report.', 'amount' => 0.0]; }
        $amount = round($amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Enter a positive charge amount.', 'amount' => 0.0]; }

        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            return ['ok' => false, 'message' => 'Folio engine unavailable.', 'amount' => 0.0];
        }
        try {
            $rep = $db->get('stays_apartment_damage', '*', ['id' => $reportId, 'stay_id' => $stayId]);
            if (!$rep) { return ['ok' => false, 'message' => 'Report not found.', 'amount' => 0.0]; }
            if ((string) $rep['status'] === 'billed') { return ['ok' => false, 'message' => 'Already billed.', 'amount' => 0.0]; }
            $invoiceId = trim((string) ($rep['invoice_id'] ?? ''));
            if ($invoiceId === '') { return ['ok' => false, 'message' => 'Link a reservation before charging.', 'amount' => 0.0]; }

            // Clamp to the property's security deposit ceiling if one is configured.
            try {
                $stay = $db->get('stays', ['security_deposit', 'org_id', 'currency'], ['id' => $stayId]);
            } catch (\Throwable $e) { $stay = null; }
            $deposit = ($stay && $stay['security_deposit'] !== null) ? round((float) $stay['security_deposit'], 2) : 0.0;
            if ($deposit > 0 && $amount > $deposit) {
                return ['ok' => false, 'message' => 'Charge exceeds the security deposit (' . number_format($deposit, 2) . ').', 'amount' => 0.0];
            }

            // Confirm an OPEN folio for THIS property (IDOR + lock-step with money).
            $folioId = folio_get_or_create($db, $invoiceId);
            if ($folioId <= 0) { return ['ok' => false, 'message' => 'No folio for this reservation.', 'amount' => 0.0]; }
            $folio = $db->get('stays_folios', ['id', 'stay_id', 'status'], ['id' => $folioId]);
            if (!$folio || (int) ($folio['stay_id'] ?? 0) !== $stayId) {
                return ['ok' => false, 'message' => 'Reservation is not at this property.', 'amount' => 0.0];
            }
            if (($folio['status'] ?? '') !== 'open') {
                return ['ok' => false, 'message' => 'Folio is closed; cannot post.', 'amount' => 0.0];
            }

            $orgId = ($stay && $stay['org_id'] !== null) ? (int) $stay['org_id'] : 0;
            $currency = $stay ? (string) ($stay['currency'] ?? 'USD') : 'USD';
            $actor = (string) ($_SESSION['user_id'] ?? '') ?: 'staff';
            $desc = 'Damage charge — unit #' . (int) $rep['unit_id'] . ' (report #' . $reportId . ')';

            // All-or-nothing: folio line + GL mirror + report flag.
            $result = ['ok' => false, 'message' => 'Could not post damage charge.', 'amount' => 0.0];
            $db->action(function ($db) use (&$result, $folioId, $amount, $desc, $actor, $orgId, $currency, $reportId, $stayId) {
                $posted = folio_add_item($db, $folioId, 'charge', $amount, $desc, $actor, 1);
                if (!$posted) { $result = ['ok' => false, 'message' => 'Folio rejected the charge.', 'amount' => 0.0]; return false; }

                // GL mirror (DB-GATED on an org chart of accounts). A failed/absent GL must
                // not silently drop revenue → we roll the whole action back.
                if ($orgId > 0 && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1100', 'debit'  => $amount], // Guest Ledger (AR) — guest now owes it
                        ['code' => '4900', 'credit' => $amount], // Other Revenue — damage recovery
                    ], [
                        'source'      => 'apartment_damage',
                        'reference'   => 'DMG-' . $reportId,     // idempotent: one entry per report
                        'currency'    => $currency,
                        'property_id' => $stayId,
                        'memo'        => $desc,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => 'GL rejected the entry: ' . ($gl['message'] ?? ''), 'amount' => 0.0]; return false; }
                }

                $db->update('stays_apartment_damage', [
                    'status'         => 'billed',
                    'charged_amount' => $amount,
                    'updated_at'     => date('Y-m-d H:i:s'),
                ], ['id' => $reportId, 'stay_id' => $stayId]);

                $result = ['ok' => true, 'message' => 'Charged ' . number_format($amount, 2) . ' to the folio.', 'amount' => $amount];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('apartment_damage_charge: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not post damage charge.', 'amount' => 0.0];
        }
    }
}
