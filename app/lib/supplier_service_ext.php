<?php
// FILE: app/lib/supplier_service_ext.php
// Guest service & complaint — DEEPENING (#21). Builds the genuine spec gaps on top of
// the base ticket_* engine in app/lib/supplier_guestservice.php:
//   department routing · priorities/SLAs (+breach) · escalations · service recovery
//   (goodwill credit posted to guest folio) · completion confirmation · satisfaction feedback.
// New parallel tables only — the base `stays_service_tickets` row is never altered here.
// Owner/staff authorizes the stay via supplier_can in the route layer. Defensive/non-fatal.
// NOT runtime-verified (no MySQL) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// VOCABULARY
// ---------------------------------------------------------------------------
if (!function_exists('svc_departments')) {
    /** Routing targets. Stable keys so analytics can group by them. */
    function svc_departments(): array {
        return [
            'front_office' => 'Front office',
            'housekeeping' => 'Housekeeping',
            'maintenance'  => 'Maintenance / engineering',
            'food_bev'     => 'Food & beverage',
            'security'     => 'Security',
            'management'   => 'Management',
        ];
    }
}
if (!function_exists('svc_sla_minutes')) {
    /** First-response SLA target (minutes) by priority. Business rule, not client input. */
    function svc_sla_minutes(): array { return ['high' => 120, 'normal' => 480, 'low' => 1440]; }
}
if (!function_exists('svc_sla_due_at')) {
    /** Compute the SLA due timestamp from a created_at + priority. Returns 'Y-m-d H:i:s' or null. */
    function svc_sla_due_at(string $createdAt, string $priority): ?string {
        $mins = svc_sla_minutes()[$priority] ?? svc_sla_minutes()['normal'];
        $ts = strtotime($createdAt);
        if ($ts === false) { return null; }
        return date('Y-m-d H:i:s', $ts + ($mins * 60));
    }
}

// ---------------------------------------------------------------------------
// ROUTING + SLA (per-ticket extension row — one-to-one with stays_service_tickets)
// ---------------------------------------------------------------------------
if (!function_exists('svc_ext_get')) {
    /** The extension row for a ticket (routing/sla/feedback), or [] if none yet. */
    function svc_ext_get($db, int $ticketId): array {
        if ($ticketId <= 0) { return []; }
        try { return $db->get('stays_service_ticket_ext', '*', ['ticket_id' => $ticketId]) ?: []; }
        catch (\Throwable $e) { error_log('svc_ext_get: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('svc_route')) {
    /**
     * Route a ticket to a department + assignee, (re)deriving its SLA from the ticket's
     * priority. Upserts the ext row. Property scope is enforced by the caller (supplier_can).
     */
    function svc_route($db, int $ticketId, int $stayId, array $d): bool {
        if ($ticketId <= 0 || $stayId <= 0) { return false; }
        try {
            $t = $db->get('stays_service_tickets', ['id', 'stay_id', 'priority', 'created_at'],
                ['id' => $ticketId, 'stay_id' => $stayId]);
            if (!$t) { return false; }
            $dept = isset(svc_departments()[$d['department'] ?? '']) ? $d['department'] : null;
            $assignee = substr(trim((string) ($d['assignee'] ?? '')), 0, 155) ?: null;
            $priority = in_array(($t['priority'] ?? 'normal'), ['low', 'normal', 'high'], true)
                ? $t['priority'] : 'normal';
            $createdAt = (string) ($t['created_at'] ?? date('Y-m-d H:i:s'));
            $dueAt = svc_sla_due_at($createdAt, $priority);
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;

            $exists = $db->has('stays_service_ticket_ext', ['ticket_id' => $ticketId]);
            $fields = [
                'department'  => $dept,
                'assignee'    => $assignee,
                'sla_due_at'  => $dueAt,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if ($exists) {
                $db->update('stays_service_ticket_ext', $fields, ['ticket_id' => $ticketId]);
            } else {
                $db->insert('stays_service_ticket_ext', array_merge($fields, [
                    'ticket_id'  => $ticketId,
                    'stay_id'    => $stayId,
                    'org_id'     => $orgId > 0 ? $orgId : null,
                    'esc_level'  => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]));
            }
            svc_event_log($db, $ticketId, $stayId, 'route',
                'Routed to ' . (svc_departments()[$dept] ?? 'unassigned') . ($assignee ? (' / ' . $assignee) : ''));
            return true;
        } catch (\Throwable $e) { error_log('svc_route: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('svc_first_response')) {
    /** Stamp first-response time (closes the SLA clock) the first time staff responds. */
    function svc_first_response($db, int $ticketId, int $stayId): bool {
        if ($ticketId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_service_tickets', ['id' => $ticketId, 'stay_id' => $stayId])) { return false; }
            $ext = svc_ext_get($db, $ticketId);
            if (!empty($ext['first_response_at'])) { return true; } // already stamped
            $now = date('Y-m-d H:i:s');
            if (empty($ext)) {
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_service_ticket_ext', [
                    'ticket_id' => $ticketId, 'stay_id' => $stayId,
                    'org_id' => $orgId > 0 ? $orgId : null,
                    'esc_level' => 0, 'first_response_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('stays_service_ticket_ext', ['first_response_at' => $now, 'updated_at' => $now],
                    ['ticket_id' => $ticketId]);
            }
            svc_event_log($db, $ticketId, $stayId, 'first_response', 'First response recorded');
            return true;
        } catch (\Throwable $e) { error_log('svc_first_response: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('svc_sla_state')) {
    /**
     * Derive SLA state for a ticket ext row + status. Pure (no DB):
     *   'met' (responded in time) · 'breached' · 'at_risk' (<60m left) · 'on_track' · 'none'.
     */
    function svc_sla_state(array $ext, string $status): string {
        $due = (string) ($ext['sla_due_at'] ?? '');
        if ($due === '') { return 'none'; }
        $dueTs = strtotime($due); if ($dueTs === false) { return 'none'; }
        if (!empty($ext['first_response_at'])) {
            $frTs = strtotime((string) $ext['first_response_at']);
            return ($frTs !== false && $frTs <= $dueTs) ? 'met' : 'breached';
        }
        if (in_array($status, ['resolved', 'closed'], true)) { return 'met'; }
        $now = time();
        if ($now > $dueTs) { return 'breached'; }
        if (($dueTs - $now) <= 3600) { return 'at_risk'; }
        return 'on_track';
    }
}

// ---------------------------------------------------------------------------
// ESCALATION
// ---------------------------------------------------------------------------
if (!function_exists('svc_escalate')) {
    /** Bump escalation level (+1), record who/why, and log the event. Caps at 3. */
    function svc_escalate($db, int $ticketId, int $stayId, string $reason): bool {
        if ($ticketId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_service_tickets', ['id' => $ticketId, 'stay_id' => $stayId])) { return false; }
            $ext = svc_ext_get($db, $ticketId);
            $level = min(3, (int) ($ext['esc_level'] ?? 0) + 1);
            $now = date('Y-m-d H:i:s');
            if (empty($ext)) {
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_service_ticket_ext', [
                    'ticket_id' => $ticketId, 'stay_id' => $stayId,
                    'org_id' => $orgId > 0 ? $orgId : null,
                    'esc_level' => $level, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('stays_service_ticket_ext', ['esc_level' => $level, 'updated_at' => $now],
                    ['ticket_id' => $ticketId]);
            }
            svc_event_log($db, $ticketId, $stayId, 'escalate',
                'Escalated to level ' . $level . (trim($reason) !== '' ? (': ' . substr(trim($reason), 0, 300)) : ''));
            return true;
        } catch (\Throwable $e) { error_log('svc_escalate: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// SERVICE RECOVERY — goodwill compensation posted to the guest folio.
// DB-GATED on an invoice_id that resolves to an OWN-INVENTORY stays folio
// (folio_get_or_create returns 0 otherwise). Never trusts a client amount beyond
// clamping; posts a negative 'credit_note' via the canonical folio engine.
// ---------------------------------------------------------------------------
if (!function_exists('svc_recovery_kinds')) {
    function svc_recovery_kinds(): array {
        return ['credit' => 'Folio credit', 'refund_note' => 'Refund (manual)', 'goodwill' => 'Goodwill / gift'];
    }
}
if (!function_exists('svc_recovery_add')) {
    /**
     * Record a service-recovery action against a ticket. For kind='credit' AND a
     * resolvable own-inventory folio, posts a credit_note line to the guest folio via
     * folio_add_item (reuse — no client price trusted; amount is clamped >0, <= a sane cap).
     * Other kinds are logged as a documented manual action (no money movement). Returns row id.
     */
    function svc_recovery_add($db, int $ticketId, int $stayId, array $d): int {
        if ($ticketId <= 0 || $stayId <= 0) { return 0; }
        try {
            $t = $db->get('stays_service_tickets', ['id', 'stay_id', 'invoice_id'],
                ['id' => $ticketId, 'stay_id' => $stayId]);
            if (!$t) { return 0; }
            $kind = isset(svc_recovery_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'goodwill';
            $amount = round((float) ($d['amount'] ?? 0), 2);
            if ($amount < 0) { $amount = 0; }
            if ($amount > 100000) { $amount = 100000; } // defensive clamp; never trust raw client value
            $note = substr(trim((string) ($d['note'] ?? '')), 0, 300);
            $invoiceId = trim((string) ($t['invoice_id'] ?? ''));
            $actor = (string) ($_SESSION['user_id'] ?? '');
            $posted = 0; $folioId = 0;

            if ($kind === 'credit' && $amount > 0 && $invoiceId !== ''
                && function_exists('folio_get_or_create') && function_exists('folio_add_item')) {
                $folioId = folio_get_or_create($db, $invoiceId); // 0 unless own-inventory stays folio
                if ($folioId > 0) {
                    $ok = folio_add_item($db, $folioId, 'credit_note', -1 * $amount,
                        'Service recovery (ticket #' . $ticketId . ')' . ($note !== '' ? ' — ' . $note : ''),
                        $actor);
                    $posted = $ok ? 1 : 0;
                }
            }

            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_service_recovery', [
                'ticket_id'  => $ticketId,
                'stay_id'    => $stayId,
                'org_id'     => $orgId > 0 ? $orgId : null,
                'invoice_id' => $invoiceId ?: null,
                'kind'       => $kind,
                'amount'     => $amount,
                'folio_id'   => $folioId > 0 ? $folioId : null,
                'posted'     => $posted,
                'note'       => $note ?: null,
                'created_by' => $actor ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $rid = (int) $db->id();
            svc_event_log($db, $ticketId, $stayId, 'recovery',
                svc_recovery_kinds()[$kind] . ' ' . number_format($amount, 2)
                . ($posted ? ' (posted to folio)' : ' (recorded)'));
            return $rid;
        } catch (\Throwable $e) { error_log('svc_recovery_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('svc_recovery_list')) {
    function svc_recovery_list($db, int $ticketId): array {
        if ($ticketId <= 0) { return []; }
        try { return $db->select('stays_service_recovery', '*', ['ticket_id' => $ticketId, 'ORDER' => ['id' => 'DESC']]) ?: []; }
        catch (\Throwable $e) { error_log('svc_recovery_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// COMPLETION CONFIRMATION + SATISFACTION FEEDBACK
// ---------------------------------------------------------------------------
if (!function_exists('svc_confirm_completion')) {
    /**
     * Mark the resolution as confirmed with the guest. Does NOT itself change the base
     * ticket status (the route does that via ticket_update) — it stamps confirmation on
     * the ext row so analytics can distinguish "staff-resolved" from "guest-confirmed".
     */
    function svc_confirm_completion($db, int $ticketId, int $stayId, string $by = 'guest'): bool {
        if ($ticketId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_service_tickets', ['id' => $ticketId, 'stay_id' => $stayId])) { return false; }
            $by = in_array($by, ['guest', 'staff'], true) ? $by : 'guest';
            $now = date('Y-m-d H:i:s');
            $ext = svc_ext_get($db, $ticketId);
            if (empty($ext)) {
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_service_ticket_ext', [
                    'ticket_id' => $ticketId, 'stay_id' => $stayId,
                    'org_id' => $orgId > 0 ? $orgId : null, 'esc_level' => 0,
                    'confirmed_at' => $now, 'confirmed_by' => $by,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('stays_service_ticket_ext',
                    ['confirmed_at' => $now, 'confirmed_by' => $by, 'updated_at' => $now],
                    ['ticket_id' => $ticketId]);
            }
            svc_event_log($db, $ticketId, $stayId, 'confirm', 'Completion confirmed by ' . $by);
            return true;
        } catch (\Throwable $e) { error_log('svc_confirm_completion: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('svc_feedback_add')) {
    /** Record a 1-5 satisfaction rating + optional comment on the ticket ext row. */
    function svc_feedback_add($db, int $ticketId, int $stayId, array $d): bool {
        if ($ticketId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_service_tickets', ['id' => $ticketId, 'stay_id' => $stayId])) { return false; }
            $rating = (int) ($d['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) { return false; }
            $comment = substr(trim((string) ($d['comment'] ?? '')), 0, 500) ?: null;
            $now = date('Y-m-d H:i:s');
            $ext = svc_ext_get($db, $ticketId);
            if (empty($ext)) {
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_service_ticket_ext', [
                    'ticket_id' => $ticketId, 'stay_id' => $stayId,
                    'org_id' => $orgId > 0 ? $orgId : null, 'esc_level' => 0,
                    'sat_rating' => $rating, 'sat_comment' => $comment,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('stays_service_ticket_ext',
                    ['sat_rating' => $rating, 'sat_comment' => $comment, 'updated_at' => $now],
                    ['ticket_id' => $ticketId]);
            }
            svc_event_log($db, $ticketId, $stayId, 'feedback', 'Satisfaction: ' . $rating . '/5');
            return true;
        } catch (\Throwable $e) { error_log('svc_feedback_add: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// ACTIVITY LOG + VIEW-MODEL HELPERS
// ---------------------------------------------------------------------------
if (!function_exists('svc_event_log')) {
    /** Append an immutable activity row. Internal — callers above use it. */
    function svc_event_log($db, int $ticketId, int $stayId, string $event, string $detail): void {
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_service_events', [
                'ticket_id' => $ticketId,
                'stay_id'   => $stayId,
                'org_id'    => $orgId > 0 ? $orgId : null,
                'event'     => substr($event, 0, 40),
                'detail'    => substr($detail, 0, 500),
                'actor'     => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('svc_event_log: ' . $e->getMessage()); }
    }
}
if (!function_exists('svc_events')) {
    function svc_events($db, int $ticketId, int $limit = 100): array {
        if ($ticketId <= 0) { return []; }
        try { return $db->select('stays_service_events', '*', ['ticket_id' => $ticketId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('svc_events: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('svc_ext_map')) {
    /** ext rows keyed by ticket_id for a set of stays — lets the list view show SLA/dept badges. */
    function svc_ext_map($db, array $stayIds): array {
        if (empty($stayIds)) { return []; }
        try {
            $rows = $db->select('stays_service_ticket_ext', '*', ['stay_id' => $stayIds]) ?: [];
            $out = []; foreach ($rows as $r) { $out[(int) $r['ticket_id']] = $r; }
            return $out;
        } catch (\Throwable $e) { error_log('svc_ext_map: ' . $e->getMessage()); return []; }
    }
}
