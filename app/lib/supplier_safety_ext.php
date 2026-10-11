<?php
// FILE: app/lib/supplier_safety_ext.php
// Safety / security / incident EXTENSION (PMS module 54 deepening). Builds the
// genuine gaps on top of app/lib/supplier_safety.php (which already covers the
// base incident log + visitor register + document register):
//   - incident evidence  (attachments/notes bound to an incident)
//   - incident escalation + closure (resolution note, closed_at, escalated flag)
//   - security shift log (on-duty guard shifts + handover notes)
//   - emergency procedures (fire/medical/evac runbooks per property)
//   - fire/safety inspections (scheduled + completed, pass/fail, next-due)
//   - damage claims (claim register; optional charge to an OPEN guest folio)
//
// Per-property; the CALLER authorizes the stay via supplier_can(...). Every fn is
// if_not_exists-guarded and defensive (try/catch → error_log, never throws to the
// route). NOT runtime-verified (no DB here) — logic + php -l only.
//
// MONEY: the only money path is sfx_damage_bill_folio(), which reuses the existing
// guarded folio_add_item() — it posts a single 'charge' line to an OPEN folio and
// does NOT touch the GL directly (folio checkout is what relieves to GL). The
// amount is supplier-entered by an authorized staff member and is clamped/rounded
// server-side; the folio is re-read + status-checked inside folio_add_item(). The
// claim↔folio link is DB-GATED on stays_folios existing for the booking.

// ============================================================================
// INCIDENT EVIDENCE + ESCALATION / CLOSURE  (deepens stays_incidents)
// ============================================================================

if (!function_exists('sfx_evidence_add')) {
    /** Attach one piece of evidence (note / uploaded file ref) to an incident the
     *  caller has already authorized (incident must belong to $stayId). Returns new id or 0.
     *  File handling is the caller's job (handleFileUpload); this stores the ref + a note. */
    function sfx_evidence_add($db, int $incidentId, int $stayId, array $d): int
    {
        if ($incidentId <= 0 || $stayId <= 0) { return 0; }
        $note = trim((string) ($d['note'] ?? ''));
        $file = trim((string) ($d['file_path'] ?? ''));
        if ($note === '' && $file === '') { return 0; }
        try {
            // Confirm the incident is this property's (defence in depth; route also checks).
            if (!$db->has('stays_incidents', ['id' => $incidentId, 'stay_id' => $stayId])) { return 0; }
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind = in_array(($d['evidence_type'] ?? ''), ['photo', 'document', 'video', 'note'], true) ? $d['evidence_type'] : ($file !== '' ? 'document' : 'note');
            $db->insert('stays_incident_evidence', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'incident_id' => $incidentId,
                'stay_id'     => $stayId,
                'evidence_type' => $kind,
                'file_path'   => $file !== '' ? substr($file, 0, 255) : null,
                'note'        => $note !== '' ? substr($note, 0, 500) : null,
                'added_by'    => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('sfx_evidence_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('sfx_evidence_list')) {
    function sfx_evidence_list($db, int $incidentId, int $stayId, int $limit = 100): array
    {
        if ($incidentId <= 0 || $stayId <= 0) { return []; }
        try {
            return $db->select('stays_incident_evidence', '*',
                ['incident_id' => $incidentId, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('sfx_evidence_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('sfx_evidence_counts')) {
    /** incident_id => count, for the base safety incident list to badge "N evidence". */
    function sfx_evidence_counts($db, array $stayIds): array
    {
        if (empty($stayIds)) { return []; }
        $out = [];
        try {
            foreach ($db->select('stays_incident_evidence', ['incident_id'], ['stay_id' => $stayIds]) ?: [] as $r) {
                $k = (int) $r['incident_id']; $out[$k] = ($out[$k] ?? 0) + 1;
            }
        } catch (\Throwable $e) { error_log('sfx_evidence_counts: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('sfx_incident_escalate')) {
    /** Flag an incident as escalated (to management / authorities) with a note.
     *  Idempotent; does NOT change workflow status (use incident_set_status for that). */
    function sfx_incident_escalate($db, int $incidentId, int $stayId, string $to, string $note): bool
    {
        if ($incidentId <= 0 || $stayId <= 0) { return false; }
        $to = substr(trim($to), 0, 120);
        if ($to === '') { return false; }
        try {
            if (!$db->has('stays_incidents', ['id' => $incidentId, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_incidents', [
                'escalated'     => 1,
                'escalated_to'  => $to,
                'escalated_at'  => date('Y-m-d H:i:s'),
                'escalation_note' => substr(trim($note), 0, 500) ?: null,
                'status'        => 'investigating', // escalation implies it's live
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['id' => $incidentId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('sfx_incident_escalate: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('sfx_incident_close')) {
    /** Close an incident with a mandatory resolution note. Stamps closed_at + closed_by.
     *  Refuses if already closed. Returns bool. */
    function sfx_incident_close($db, int $incidentId, int $stayId, string $resolution): bool
    {
        if ($incidentId <= 0 || $stayId <= 0) { return false; }
        $resolution = substr(trim($resolution), 0, 1000);
        if ($resolution === '') { return false; } // closure requires an account of what happened
        try {
            $inc = $db->get('stays_incidents', ['id', 'status'], ['id' => $incidentId, 'stay_id' => $stayId]);
            if (!$inc || $inc['status'] === 'closed') { return false; }
            $db->update('stays_incidents', [
                'status'      => 'closed',
                'resolution'  => $resolution,
                'closed_at'   => date('Y-m-d H:i:s'),
                'closed_by'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'updated_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $incidentId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('sfx_incident_close: ' . $e->getMessage()); return false; }
    }
}

// ============================================================================
// SECURITY SHIFT LOG  (stays_security_shifts)
// ============================================================================

if (!function_exists('sfx_shift_open')) {
    /** Start an on-duty security shift. Refuses a second OPEN shift for the same
     *  property+officer (one active shift per officer per property). Returns id or 0. */
    function sfx_shift_open($db, int $stayId, array $d): int
    {
        $officer = trim((string) ($d['officer'] ?? ''));
        if ($stayId <= 0 || $officer === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            // Guard: an open shift already exists for this officer on this property.
            if ($db->has('stays_security_shifts', ['stay_id' => $stayId, 'officer' => substr($officer, 0, 120), 'ended_at' => null])) {
                return 0;
            }
            $db->insert('stays_security_shifts', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'officer'    => substr($officer, 0, 120),
                'post'       => substr(trim((string) ($d['post'] ?? '')), 0, 120) ?: null,
                'started_at' => date('Y-m-d H:i:s'),
                'opened_by'  => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('sfx_shift_open: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('sfx_shift_close')) {
    /** End an open shift with a handover note. Refuses an already-closed shift. */
    function sfx_shift_close($db, int $id, int $stayId, string $handover): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            $s = $db->get('stays_security_shifts', ['id', 'ended_at'], ['id' => $id, 'stay_id' => $stayId]);
            if (!$s || $s['ended_at'] !== null) { return false; }
            $db->update('stays_security_shifts', [
                'ended_at'  => date('Y-m-d H:i:s'),
                'handover'  => substr(trim($handover), 0, 1000) ?: null,
                'closed_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
            ], ['id' => $id, 'stay_id' => $stayId, 'ended_at' => null]);
            return true;
        } catch (\Throwable $e) { error_log('sfx_shift_close: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('sfx_shift_list')) {
    function sfx_shift_list($db, array $stayIds, int $limit = 100): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_security_shifts', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('sfx_shift_list: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// EMERGENCY PROCEDURES  (stays_emergency_procedures)
// ============================================================================

if (!function_exists('sfx_procedure_add')) {
    /** Store/replace an emergency runbook (fire/medical/evac/…) for a property.
     *  One procedure row per (stay, kind) — re-adding the same kind updates it. Returns id or 0. */
    function sfx_procedure_add($db, int $stayId, array $d): int
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($stayId <= 0 || $title === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind = in_array(($d['kind'] ?? ''), ['fire', 'medical', 'evacuation', 'security', 'natural', 'other'], true) ? $d['kind'] : 'other';
            $row = [
                'title'       => substr($title, 0, 191),
                'steps'       => substr(trim((string) ($d['steps'] ?? '')), 0, 4000) ?: null,
                'contacts'    => substr(trim((string) ($d['contacts'] ?? '')), 0, 500) ?: null,
                'assembly_point' => substr(trim((string) ($d['assembly_point'] ?? '')), 0, 191) ?: null,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            $existing = $db->get('stays_emergency_procedures', ['id'], ['stay_id' => $stayId, 'kind' => $kind]);
            if ($existing && (int) $existing['id'] > 0) {
                $db->update('stays_emergency_procedures', $row, ['id' => (int) $existing['id']]);
                return (int) $existing['id'];
            }
            $db->insert('stays_emergency_procedures', array_merge($row, [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'kind'       => $kind,
                'created_at' => date('Y-m-d H:i:s'),
            ]));
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('sfx_procedure_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('sfx_procedure_list')) {
    function sfx_procedure_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_emergency_procedures', '*', ['stay_id' => $stayIds, 'ORDER' => ['stay_id' => 'ASC', 'kind' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('sfx_procedure_list: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// FIRE / SAFETY INSPECTIONS  (stays_safety_inspections)
// ============================================================================

if (!function_exists('sfx_inspection_add')) {
    /** Record a fire/safety inspection result. result ∈ pass|fail|conditional.
     *  next_due is an optional date for the expiry/reminder report. Returns id or 0. */
    function sfx_inspection_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $type = in_array(($d['inspection_type'] ?? ''), ['fire', 'electrical', 'gas', 'water', 'general', 'other'], true) ? $d['inspection_type'] : 'general';
        $result = in_array(($d['result'] ?? ''), ['pass', 'fail', 'conditional'], true) ? $d['result'] : 'pass';
        $on = trim((string) ($d['inspected_on'] ?? ''));
        $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? $on : date('Y-m-d');
        $next = trim((string) ($d['next_due'] ?? ''));
        $next = preg_match('/^\d{4}-\d{2}-\d{2}$/', $next) ? $next : null;
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_safety_inspections', [
                'org_id'          => $orgId > 0 ? $orgId : null,
                'stay_id'         => $stayId,
                'inspection_type' => $type,
                'inspector'       => substr(trim((string) ($d['inspector'] ?? '')), 0, 120) ?: null,
                'inspected_on'    => $on,
                'result'          => $result,
                'findings'        => substr(trim((string) ($d['findings'] ?? '')), 0, 1000) ?: null,
                'next_due'        => $next,
                'created_by'      => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('sfx_inspection_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('sfx_inspection_list')) {
    function sfx_inspection_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_safety_inspections', '*', ['stay_id' => $stayIds, 'ORDER' => ['inspected_on' => 'DESC', 'id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('sfx_inspection_list: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// DAMAGE CLAIMS  (stays_damage_claims) — money path is DB-GATED on a real folio
// ============================================================================

if (!function_exists('sfx_damage_add')) {
    /** Register a damage claim (what was damaged, estimated cost, optional guest ref).
     *  Does NOT bill anything — status starts 'open'. Returns id or 0. */
    function sfx_damage_add($db, int $stayId, array $d): int
    {
        $item = trim((string) ($d['item'] ?? ''));
        if ($stayId <= 0 || $item === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            // Estimated cost is clamped server-side; it is a claim estimate, not a charge yet.
            $est = max(0, round((float) ($d['est_cost'] ?? 0), 2));
            $db->insert('stays_damage_claims', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'incident_id' => ($d['incident_id'] ?? '') !== '' ? (int) $d['incident_id'] : null,
                'invoice_id'  => substr(trim((string) ($d['invoice_id'] ?? '')), 0, 191) ?: null,
                'item'        => substr($item, 0, 191),
                'location'    => substr(trim((string) ($d['location'] ?? '')), 0, 120) ?: null,
                'description' => substr(trim((string) ($d['description'] ?? '')), 0, 1000) ?: null,
                'est_cost'    => $est,
                'billed_amount' => 0,
                'status'      => 'open',
                'reported_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('sfx_damage_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('sfx_damage_set_status')) {
    /** Move a claim through open → assessed → (resolved|waived). Billing is separate
     *  (sfx_damage_bill_folio). Returns bool. */
    function sfx_damage_set_status($db, int $id, int $stayId, string $status, string $note = ''): bool
    {
        if (!in_array($status, ['open', 'assessed', 'resolved', 'waived'], true)) { return false; }
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_damage_claims', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_damage_claims', [
                'status'       => $status,
                'resolution'   => $note !== '' ? substr(trim($note), 0, 1000) : null,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('sfx_damage_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('sfx_damage_list')) {
    function sfx_damage_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_damage_claims', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('sfx_damage_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('sfx_damage_bill_folio')) {
    /**
     * DB-GATED MONEY: bill a damage claim to the guest's OPEN folio as a single 'charge'
     * line. Reuses the existing guarded folio_add_item() — it re-reads the folio, refuses
     * a closed/missing folio, and inserts exactly one balanced ledger line + audit row.
     * This does NOT post to the GL directly; GL relief happens at folio checkout like any
     * other extra charge. The amount is the supplier-entered, server-clamped $amount
     * (NEVER a client-trusted total passed straight to an invoice).
     *
     * Returns ['ok'=>bool, 'msg'=>string]. Gated on: (1) claim exists & not already billed,
     * (2) claim.invoice_id set, (3) folio_get_or_create resolves an OPEN folio.
     * Wrapped in $db->action() with a FOR UPDATE re-read so two staff cannot double-bill.
     */
    function sfx_damage_bill_folio($db, int $id, int $stayId, float $amount, string $actor = ''): array
    {
        if ($id <= 0 || $stayId <= 0) { return ['ok' => false, 'msg' => 'Invalid claim.']; }
        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            return ['ok' => false, 'msg' => 'Folio engine not available on this install (DB-gated).'];
        }
        $amount = round($amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'msg' => 'Enter a positive amount to bill.']; }

        $result = ['ok' => false, 'msg' => 'Could not bill the claim.'];
        try {
            $db->action(function ($db) use ($id, $stayId, $amount, $actor, &$result) {
                // Lock the claim row so concurrent bills serialize.
                $claim = $db->get('stays_damage_claims', ['id', 'invoice_id', 'status', 'billed_amount', 'item'],
                    ['id' => $id, 'stay_id' => $stayId, 'FOR UPDATE' => true]);
                if (!$claim) { $result = ['ok' => false, 'msg' => 'Claim not found.']; return false; }
                if ((float) ($claim['billed_amount'] ?? 0) > 0) {
                    $result = ['ok' => false, 'msg' => 'This claim was already billed.']; return false;
                }
                $invoiceId = (string) ($claim['invoice_id'] ?? '');
                if ($invoiceId === '') {
                    $result = ['ok' => false, 'msg' => 'Link a booking (invoice) to the claim before billing.']; return false;
                }
                $folioId = folio_get_or_create($db, $invoiceId);
                if ($folioId <= 0) {
                    $result = ['ok' => false, 'msg' => 'No open folio for that booking (DB-gated).']; return false;
                }
                $desc = 'Damage: ' . substr((string) $claim['item'], 0, 150);
                $posted = folio_add_item($db, $folioId, 'charge', $amount, $desc, $actor !== '' ? $actor : 'safety');
                if (!$posted) {
                    $result = ['ok' => false, 'msg' => 'Folio is closed or the charge was rejected.']; return false;
                }
                $db->update('stays_damage_claims', [
                    'billed_amount' => $amount,
                    'billed_folio_id' => $folioId,
                    'status'        => 'resolved',
                    'updated_at'    => date('Y-m-d H:i:s'),
                ], ['id' => $id, 'stay_id' => $stayId]);
                $result = ['ok' => true, 'msg' => 'Billed ' . number_format($amount, 2) . ' to the guest folio.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('sfx_damage_bill_folio: ' . $e->getMessage());
            $result = ['ok' => false, 'msg' => 'Billing failed (see log).'];
        }
        return $result;
    }
}
