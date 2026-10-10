<?php
// FILE: app/lib/supplier_mice.php
// EVENTS / MICE (Phase 1 inc S33; docs 01b module 16). Function spaces + event
// bookings + an event folio. (Named supplier_mice.php to avoid clashing with the
// S17 platform event bus in app/lib/supplier_events.php.)
//
// Model: a property has function SPACES (hall/meeting room/lawn). An EVENT BOOKING
// reserves a space for a date with a pax count and runs enquiry → confirmed →
// completed (| cancelled). Each booking has an EVENT FOLIO (line items: venue hire,
// catering, extras, payments) with a running balance — mirrors the guest folio (S21).
// On COMPLETION, the event's net charges post a balanced GL entry
// DR City/Guest-AR (1200) / CR Room/venue Revenue (4000) + CR Tax (2100) — like the
// stay folio — so event revenue lands in the property books. Idempotent on completion.
//
// Scoping enforced by the caller routes (supplier_can('reservations', …, $stayId)).
// Amounts are server-tallied from folio lines. Non-fatal throughout.

if (!function_exists('mice_space_create')) {
    function mice_space_create($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_event_spaces', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 120), 'capacity' => max(0, (int) ($d['capacity'] ?? 0)),
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_space_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_event_create')) {
    /** Create an event booking (enquiry) for a space on a date. Returns id. */
    function mice_event_create($db, int $stayId, int $spaceId, array $d): int
    {
        if ($stayId <= 0 || $spaceId <= 0) { return 0; }
        $date = (string) ($d['event_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return 0; }
        try {
            $space = $db->get('stays_event_spaces', ['id', 'org_id'], ['id' => $spaceId, 'stay_id' => $stayId]);
            if (!$space) { return 0; }
            $ref = 'EVT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_events', [
                'org_id' => $space['org_id'] !== null ? (int) $space['org_id'] : null,
                'stay_id' => $stayId, 'space_id' => $spaceId, 'reference' => $ref,
                'title' => substr(trim((string) ($d['title'] ?? 'Event')), 0, 191) ?: 'Event',
                'client_name' => substr(trim((string) ($d['client_name'] ?? '')), 0, 120) ?: null,
                'client_email' => trim((string) ($d['client_email'] ?? '')) ?: null,
                'event_date' => $date, 'pax' => max(0, (int) ($d['pax'] ?? 0)),
                'currency' => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'status' => 'enquiry', 'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_event_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_item_types')) {
    function mice_item_types(): array { return ['venue', 'catering', 'extra', 'payment', 'refund']; }
}

if (!function_exists('mice_folio_add')) {
    /** Add a line to an event that is NOT completed/cancelled. Returns bool. */
    function mice_folio_add($db, int $eventId, int $stayId, string $type, float $amount, string $desc = ''): bool
    {
        $type = in_array($type, mice_item_types(), true) ? $type : '';
        $amount = round($amount, 2);
        if ($eventId <= 0 || $type === '' || $amount <= 0) { return false; }
        try {
            $e = $db->get('stays_events', ['id', 'status'], ['id' => $eventId, 'stay_id' => $stayId]);
            if (!$e || in_array($e['status'], ['completed', 'cancelled'], true)) { return false; }
            $db->insert('stays_event_items', [
                'event_id' => $eventId, 'type' => $type,
                'description' => substr(trim($desc), 0, 191) ?: ucfirst($type),
                'amount' => $amount, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('mice_folio_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_event_totals')) {
    /** ['charges','payments','balance'] for an event (venue/catering/extra = charges;
     *  payment reduces; refund adds back to owed). */
    function mice_event_totals($db, int $eventId): array
    {
        $out = ['charges' => 0.0, 'payments' => 0.0, 'balance' => 0.0];
        try {
            foreach ($db->select('stays_event_items', ['type', 'amount'], ['event_id' => $eventId]) ?: [] as $it) {
                $amt = round((float) $it['amount'], 2);
                if ((string) $it['type'] === 'payment') { $out['payments'] += $amt; }
                elseif ((string) $it['type'] === 'refund') { $out['payments'] -= $amt; }
                else { $out['charges'] += $amt; }
            }
            $out['charges'] = round($out['charges'], 2);
            $out['payments'] = round($out['payments'], 2);
            $out['balance'] = round($out['charges'] - $out['payments'], 2);
        } catch (\Throwable $e) { error_log('mice_event_totals: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('mice_event_set_status')) {
    /**
     * Transition an event: enquiry→confirmed→completed, or →cancelled. On COMPLETION
     * (and only once), post the event charges to the GL
     * (DR 1200 City-AR = charges; CR 4000 venue revenue + CR 2100 tax split — here we
     *  post the whole charges as revenue, no separate event tax line unless added).
     * Idempotent via a status guard. Returns bool.
     */
    function mice_event_set_status($db, int $eventId, int $stayId, string $to): bool
    {
        $allowed = ['enquiry' => ['confirmed', 'cancelled'], 'confirmed' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];
        try {
            $ev = $db->get('stays_events', '*', ['id' => $eventId, 'stay_id' => $stayId]);
            if (!$ev) { return false; }
            $from = (string) $ev['status'];
            if (!in_array($to, $allowed[$from] ?? [], true)) { return false; }

            if ($to === 'completed') {
                $totals = mice_event_totals($db, $eventId);
                $orgId = ($ev['org_id'] !== null) ? (int) $ev['org_id'] : 0;
                $ok = false;
                $db->action(function ($db) use ($eventId, $stayId, $ev, $orgId, $totals, &$ok) {
                    $lock = $db->query('SELECT status FROM stays_events WHERE id=:e FOR UPDATE', [':e' => $eventId]);
                    $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                    if (!$cur || $cur['status'] !== 'confirmed') { $ok = false; return false; }
                    // Post the event charges to the GL (revenue recognised on completion).
                    if ($orgId > 0 && $totals['charges'] > 0 && function_exists('gl_post')) {
                        $res = gl_post($db, $orgId, [
                            ['code' => '1200', 'debit' => $totals['charges']],   // City/events AR
                            ['code' => '4000', 'credit' => $totals['charges']],  // venue/event revenue
                        ], ['source' => 'event', 'reference' => (string) $ev['reference'], 'currency' => (string) $ev['currency'],
                            'property_id' => $stayId, 'memo' => 'Event ' . $ev['reference']]);
                        if (empty($res['ok'])) { error_log('mice complete GL: ' . ($res['message'] ?? '?')); }
                    }
                    $db->update('stays_events', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $eventId, 'status' => 'confirmed']);
                    $ok = true; return true;
                });
                return !empty($ok);
            }

            $db->update('stays_events', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $eventId, 'status' => $from]);
            return true;
        } catch (\Throwable $e) {
            error_log('mice_event_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mice_events_for_org')) {
    /** Events across the owner's properties (optional status filter). Read-only. */
    function mice_events_for_org($db, array $stayIds, string $status = '', int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if (in_array($status, ['enquiry', 'confirmed', 'completed', 'cancelled'], true)) { $where['status'] = $status; }
        $where['ORDER'] = ['event_date' => 'DESC']; $where['LIMIT'] = max(1, $limit);
        try {
            return $db->select('stays_events',
                ['id', 'stay_id', 'space_id', 'reference', 'title', 'client_name', 'event_date', 'pax', 'currency', 'status'],
                $where) ?: [];
        } catch (\Throwable $e) { error_log('mice_events_for_org: ' . $e->getMessage()); return []; }
    }
}
