<?php
// FILE: app/lib/supplier_docs_ext.php
// DOCUMENT & COMPLIANCE MANAGEMENT — EXTENSION (PMS module #55; docs 01b §2
// line 309-312).
//
// The base (app/lib/supplier_safety.php) ships a flat document REPOSITORY:
// document_add() / document_list() over stays_documents (title, doc_type,
// reference, expires_on, notes). That covers "document repository + property
// licenses / insurance / contracts" at a single-row level.
//
// This extension adds the genuine missing sub-features of #55 WITHOUT touching
// the base lib, the stays_documents table, or the shared gate:
//
//   1. DOCUMENT LIFECYCLE on the EXISTING stays_documents table — adds
//      owner_scope (who the doc concerns: property | employee | guest | vendor),
//      status (active | superseded | expired | archived), file_ref (relative
//      path to an already-uploaded file, NOT an uploader — we only store the
//      reference), retain_until (computed from the retention policy) and
//      reviewed_at. Columns are added by _docs_ext_ensure_schema() with
//      SHOW COLUMNS guards (self-healing, §5). The base document_add() still
//      works unchanged; docs_meta_set() backfills the new fields.
//
//   2. COMPLIANCE CHECKLISTS (stays_compliance_checklists +
//      stays_compliance_checklist_items) — a reusable checklist (e.g. "Annual
//      fire-safety") with ordered items. docs_checklist_item_attest() records a
//      completion with who/when/note. A checklist's completion % is REAL (done
//      items / total items).
//
//   3. EXPIRY REMINDERS (computed, no new table) — docs_expiring() scans real
//      stays_documents rows and buckets them expired / due-soon by a lead-day
//      window. docs_mark_status_from_expiry() flips status to 'expired' for rows
//      already past expires_on (idempotent, runs on page load). The actual
//      EMAIL/SMS send is a DB-GATED external edge — docs_reminder_dispatch() is
//      an HONEST STUB that reports 'not connected' and never fakes a send.
//
//   4. DATA-RETENTION RULES (stays_retention_rules) — per doc_type retention in
//      months. docs_apply_retention() computes retain_until = expires_on (or
//      created_at) + retention, and docs_retention_due() lists rows PAST their
//      retain_until (purge-eligible). We deliberately do NOT auto-delete files
//      or rows — purge is an operator decision; this module only surfaces the
//      exposure. (Honest: destructive retention enforcement is out of scope.)
//
//   5. COMPLIANCE REPORT — docs_compliance_report() rolls up counts by
//      doc_type/status, expiring-soon count, checklist completion, and
//      retention exposure from REAL rows only.
//
// GATE: #55 has no dedicated capability group in supplier_permissions_catalogue()
// (compliance is dormant). This extension MATCHES the base safety screens, which
// gate on supplier_can($db,'rooms',<action>): view => 'rooms','view';
// mutate => 'rooms','edit'. Do NOT invent a new capability key or the
// role-builder won't grant it.
//
// MONEY (honest): NOTHING in this module touches money. Compliance documents,
// checklists and retention rules carry no amounts, no guest charges, no payouts.
// There is therefore nothing to route through gl_post / folio_add_item /
// supplier_earnings — doing so would be wrong. No GL is posted here.
//
// All functions are if_not_exists-guarded, non-fatal (try/catch + error_log),
// and additive.

if (!function_exists('_docs_ext_ensure_schema')) {
    /**
     * Idempotent self-healing schema (§5). Adds lifecycle columns to the
     * EXISTING stays_documents table (SHOW COLUMNS guarded) and creates the new
     * checklist / retention tables. Safe to call on every page load.
     */
    function _docs_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            // --- additive columns on stays_documents (guarded) -----------------
            $cols = [];
            foreach ($db->query("SHOW COLUMNS FROM `stays_documents`")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $c) {
                $cols[strtolower($c['Field'])] = true;
            }
            $add = [];
            if (!isset($cols['owner_scope'])) { $add[] = "ADD COLUMN `owner_scope` ENUM('property','employee','guest','vendor') NOT NULL DEFAULT 'property'"; }
            if (!isset($cols['status']))      { $add[] = "ADD COLUMN `status` ENUM('active','superseded','expired','archived') NOT NULL DEFAULT 'active'"; }
            if (!isset($cols['file_ref']))    { $add[] = "ADD COLUMN `file_ref` VARCHAR(255) DEFAULT NULL"; }
            if (!isset($cols['retain_until'])){ $add[] = "ADD COLUMN `retain_until` DATE DEFAULT NULL"; }
            if (!isset($cols['reviewed_at'])) { $add[] = "ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL"; }
            if ($add) { $db->query("ALTER TABLE `stays_documents` " . implode(', ', $add)); }
        } catch (\Throwable $e) { error_log('_docs_ext_ensure_schema cols: ' . $e->getMessage()); }

        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_compliance_checklists` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) DEFAULT NULL,
                `stay_id` INT(11) NOT NULL,
                `title` VARCHAR(191) NOT NULL,
                `category` VARCHAR(80) DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_compliance_checklist_items` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `checklist_id` INT(11) NOT NULL,
                `stay_id` INT(11) NOT NULL,
                `label` VARCHAR(191) NOT NULL,
                `sort` INT(11) NOT NULL DEFAULT 0,
                `done` TINYINT(1) NOT NULL DEFAULT 0,
                `done_by` VARCHAR(64) DEFAULT NULL,
                `done_at` DATETIME DEFAULT NULL,
                `note` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_checklist` (`checklist_id`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_retention_rules` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) DEFAULT NULL,
                `stay_id` INT(11) NOT NULL,
                `doc_type` ENUM('licence','insurance','certificate','contract','other') NOT NULL DEFAULT 'other',
                `retain_months` INT(11) NOT NULL DEFAULT 84,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_stay_type` (`stay_id`,`doc_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) { error_log('_docs_ext_ensure_schema tables: ' . $e->getMessage()); }
    }
}

if (!function_exists('docs_scopes')) {
    function docs_scopes(): array
    {
        return ['property' => 'Property', 'employee' => 'Employee', 'guest' => 'Guest', 'vendor' => 'Vendor/contractor'];
    }
}
if (!function_exists('docs_statuses')) {
    function docs_statuses(): array
    {
        return ['active' => 'Active', 'superseded' => 'Superseded', 'expired' => 'Expired', 'archived' => 'Archived'];
    }
}

// --- 1. DOCUMENT LIFECYCLE --------------------------------------------------
if (!function_exists('docs_meta_set')) {
    /**
     * Backfill the lifecycle metadata on an existing stays_documents row the base
     * document_add() created. Only mutates rows that belong to $stayId. Returns
     * true on success. file_ref is stored VERBATIM as a relative path — this
     * function does NOT upload anything (see the honest note at the top).
     */
    function docs_meta_set($db, int $id, int $stayId, array $d): bool
    {
        _docs_ext_ensure_schema($db);
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_documents', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $scope  = in_array(($d['owner_scope'] ?? ''), array_keys(docs_scopes()), true) ? $d['owner_scope'] : 'property';
            $status = in_array(($d['status'] ?? ''), array_keys(docs_statuses()), true) ? $d['status'] : 'active';
            $upd = [
                'owner_scope' => $scope,
                'status'      => $status,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if (array_key_exists('file_ref', $d)) {
                $ref = trim((string) $d['file_ref']);
                // Store a relative path only; reject schemes / traversal.
                if ($ref !== '' && !preg_match('#^[a-z]+://#i', $ref) && strpos($ref, '..') === false) {
                    $upd['file_ref'] = substr(ltrim($ref, '/'), 0, 255);
                } elseif ($ref === '') {
                    $upd['file_ref'] = null;
                }
            }
            if (!empty($d['mark_reviewed'])) { $upd['reviewed_at'] = date('Y-m-d H:i:s'); }
            $db->update('stays_documents', $upd, ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('docs_meta_set: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('docs_set_status')) {
    function docs_set_status($db, int $id, int $stayId, string $status): bool
    {
        _docs_ext_ensure_schema($db);
        if (!in_array($status, array_keys(docs_statuses()), true)) { return false; }
        try {
            if (!$db->has('stays_documents', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_documents', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('docs_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('docs_mark_status_from_expiry')) {
    /** Flip status to 'expired' for any active doc already past expires_on. Idempotent. */
    function docs_mark_status_from_expiry($db, array $stayIds): int
    {
        _docs_ext_ensure_schema($db);
        if (empty($stayIds)) { return 0; }
        try {
            $rows = $db->select('stays_documents', ['id'], [
                'stay_id' => $stayIds,
                'status'  => 'active',
                'expires_on[<]' => date('Y-m-d'),
                'expires_on[!]' => null,
            ]) ?: [];
            $n = 0;
            foreach ($rows as $r) {
                $db->update('stays_documents', ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $r['id']]);
                $n++;
            }
            return $n;
        } catch (\Throwable $e) { error_log('docs_mark_status_from_expiry: ' . $e->getMessage()); return 0; }
    }
}

// --- 2. COMPLIANCE CHECKLISTS ----------------------------------------------
if (!function_exists('docs_checklist_create')) {
    function docs_checklist_create($db, int $stayId, array $d): int
    {
        _docs_ext_ensure_schema($db);
        $title = trim((string) ($d['title'] ?? ''));
        if ($stayId <= 0 || $title === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_compliance_checklists', [
                'org_id'   => $orgId > 0 ? $orgId : null,
                'stay_id'  => $stayId,
                'title'    => substr($title, 0, 191),
                'category' => substr(trim((string) ($d['category'] ?? '')), 0, 80) ?: null,
                'active'   => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('docs_checklist_create: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('docs_checklist_item_add')) {
    function docs_checklist_item_add($db, int $checklistId, int $stayId, array $d): int
    {
        _docs_ext_ensure_schema($db);
        $label = trim((string) ($d['label'] ?? ''));
        if ($checklistId <= 0 || $stayId <= 0 || $label === '') { return 0; }
        try {
            // Confirm the checklist belongs to this property before adding an item.
            if (!$db->has('stays_compliance_checklists', ['id' => $checklistId, 'stay_id' => $stayId])) { return 0; }
            $maxSort = (int) ($db->max('stays_compliance_checklist_items', 'sort', ['checklist_id' => $checklistId]) ?? 0);
            $db->insert('stays_compliance_checklist_items', [
                'checklist_id' => $checklistId,
                'stay_id'      => $stayId,
                'label'        => substr($label, 0, 191),
                'sort'         => $maxSort + 1,
                'done'         => 0,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('docs_checklist_item_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('docs_checklist_item_attest')) {
    /** Toggle an item done/undone, recording who/when/note on completion. */
    function docs_checklist_item_attest($db, int $itemId, int $stayId, bool $done, string $note = ''): bool
    {
        _docs_ext_ensure_schema($db);
        if ($itemId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_compliance_checklist_items', ['id' => $itemId, 'stay_id' => $stayId])) { return false; }
            if ($done) {
                $db->update('stays_compliance_checklist_items', [
                    'done'    => 1,
                    'done_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'done_at' => date('Y-m-d H:i:s'),
                    'note'    => substr(trim($note), 0, 255) ?: null,
                ], ['id' => $itemId, 'stay_id' => $stayId]);
            } else {
                $db->update('stays_compliance_checklist_items', [
                    'done' => 0, 'done_by' => null, 'done_at' => null,
                ], ['id' => $itemId, 'stay_id' => $stayId]);
            }
            return true;
        } catch (\Throwable $e) { error_log('docs_checklist_item_attest: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('docs_checklists_with_items')) {
    /** Returns checklists for the scope, each with ['items'=>[], 'done'=>n, 'total'=>n, 'pct'=>int]. */
    function docs_checklists_with_items($db, array $stayIds, int $limit = 100): array
    {
        _docs_ext_ensure_schema($db);
        if (empty($stayIds)) { return []; }
        try {
            $lists = $db->select('stays_compliance_checklists', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($lists as &$l) {
                $items = $db->select('stays_compliance_checklist_items', '*', ['checklist_id' => (int) $l['id'], 'ORDER' => ['sort' => 'ASC', 'id' => 'ASC']]) ?: [];
                $total = count($items);
                $done = 0; foreach ($items as $it) { if (!empty($it['done'])) { $done++; } }
                $l['items'] = $items;
                $l['total'] = $total;
                $l['done']  = $done;
                $l['pct']   = $total > 0 ? (int) round($done / $total * 100) : 0;
            }
            unset($l);
            return $lists;
        } catch (\Throwable $e) { error_log('docs_checklists_with_items: ' . $e->getMessage()); return []; }
    }
}

// --- 3. EXPIRY REMINDERS ----------------------------------------------------
if (!function_exists('docs_expiring')) {
    /**
     * Bucket real documents with an expires_on into 'expired' (past) and
     * 'due_soon' (within $leadDays). Returns ['expired'=>[], 'due_soon'=>[]].
     */
    function docs_expiring($db, array $stayIds, int $leadDays = 60): array
    {
        _docs_ext_ensure_schema($db);
        $out = ['expired' => [], 'due_soon' => []];
        if (empty($stayIds)) { return $out; }
        $leadDays = max(1, min(365, $leadDays));
        $today = date('Y-m-d');
        $limit = date('Y-m-d', strtotime('+' . $leadDays . ' days'));
        try {
            $rows = $db->select('stays_documents', '*', [
                'stay_id' => $stayIds,
                'expires_on[!]' => null,
                'status[!]' => 'archived',
                'ORDER' => ['expires_on' => 'ASC'],
                'LIMIT' => 500,
            ]) ?: [];
            foreach ($rows as $r) {
                $exp = (string) ($r['expires_on'] ?? '');
                if ($exp === '') { continue; }
                if ($exp < $today) { $out['expired'][] = $r; }
                elseif ($exp <= $limit) { $out['due_soon'][] = $r; }
            }
        } catch (\Throwable $e) { error_log('docs_expiring: ' . $e->getMessage()); }
        return $out;
    }
}
if (!function_exists('docs_reminder_dispatch')) {
    /**
     * DB-GATED / STUB. Honest external edge: emailing/SMSing an expiry reminder
     * to the property's compliance contact requires wiring to the notification
     * providers (settings.*_providers_config). This extension does NOT fake a
     * send. It reports what WOULD be sent and that the channel is not connected.
     *
     * @return array ['connected'=>false, 'would_notify'=>int, 'message'=>string]
     */
    function docs_reminder_dispatch($db, array $stayIds, int $leadDays = 60): array
    {
        $buckets = docs_expiring($db, $stayIds, $leadDays);
        $count = count($buckets['expired']) + count($buckets['due_soon']);
        return [
            'connected'    => false,
            'would_notify' => $count,
            'message'      => 'Reminder channel not connected. ' . $count . ' document(s) would be flagged to the compliance contact once a notification provider is configured.',
        ];
    }
}

// --- 4. DATA-RETENTION RULES ------------------------------------------------
if (!function_exists('docs_retention_default_months')) {
    /** Jurisdiction-neutral sensible defaults per doc_type (docs 01a §13 is the real source). */
    function docs_retention_default_months(): array
    {
        return ['licence' => 84, 'insurance' => 120, 'certificate' => 84, 'contract' => 120, 'other' => 60];
    }
}
if (!function_exists('docs_retention_set')) {
    /** Upsert a per-doc_type retention rule (months). */
    function docs_retention_set($db, int $stayId, string $docType, int $months): bool
    {
        _docs_ext_ensure_schema($db);
        $types = ['licence', 'insurance', 'certificate', 'contract', 'other'];
        if ($stayId <= 0 || !in_array($docType, $types, true)) { return false; }
        $months = max(1, min(600, $months));
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            if ($db->has('stays_retention_rules', ['stay_id' => $stayId, 'doc_type' => $docType])) {
                $db->update('stays_retention_rules', ['retain_months' => $months, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId, 'doc_type' => $docType]);
            } else {
                $db->insert('stays_retention_rules', [
                    'org_id' => $orgId > 0 ? $orgId : null,
                    'stay_id' => $stayId, 'doc_type' => $docType,
                    'retain_months' => $months, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('docs_retention_set: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('docs_retention_rules')) {
    /** Returns [doc_type => retain_months] for the property, filled with defaults. */
    function docs_retention_rules($db, int $stayId): array
    {
        _docs_ext_ensure_schema($db);
        $rules = docs_retention_default_months();
        if ($stayId <= 0) { return $rules; }
        try {
            foreach ($db->select('stays_retention_rules', ['doc_type', 'retain_months'], ['stay_id' => $stayId]) ?: [] as $r) {
                $rules[(string) $r['doc_type']] = (int) $r['retain_months'];
            }
        } catch (\Throwable $e) { error_log('docs_retention_rules: ' . $e->getMessage()); }
        return $rules;
    }
}
if (!function_exists('docs_apply_retention')) {
    /**
     * Recompute retain_until for every document in scope from the per-type
     * retention rule: retain_until = (expires_on | created_at date) + months.
     * Non-destructive: we NEVER delete rows or files, we only stamp the date.
     * Returns how many rows were updated. Rules are read per-property.
     */
    function docs_apply_retention($db, array $stayIds): int
    {
        _docs_ext_ensure_schema($db);
        if (empty($stayIds)) { return 0; }
        $n = 0;
        try {
            // Cache rules per stay_id to avoid a lookup per row.
            $rulesByStay = [];
            $docs = $db->select('stays_documents', ['id', 'stay_id', 'doc_type', 'expires_on', 'created_at'], ['stay_id' => $stayIds, 'LIMIT' => 2000]) ?: [];
            foreach ($docs as $d) {
                $sid = (int) $d['stay_id'];
                if (!isset($rulesByStay[$sid])) { $rulesByStay[$sid] = docs_retention_rules($db, $sid); }
                $months = (int) ($rulesByStay[$sid][(string) $d['doc_type']] ?? 60);
                $base = (string) ($d['expires_on'] ?? '');
                if ($base === '' || $base === null) { $base = substr((string) ($d['created_at'] ?? date('Y-m-d')), 0, 10); }
                $ts = strtotime($base . ' +' . $months . ' months');
                if ($ts === false) { continue; }
                $retainUntil = date('Y-m-d', $ts);
                $db->update('stays_documents', ['retain_until' => $retainUntil], ['id' => (int) $d['id']]);
                $n++;
            }
        } catch (\Throwable $e) { error_log('docs_apply_retention: ' . $e->getMessage()); }
        return $n;
    }
}
if (!function_exists('docs_retention_due')) {
    /** Docs whose retain_until is in the past — purge-eligible (operator decides). */
    function docs_retention_due($db, array $stayIds, int $limit = 300): array
    {
        _docs_ext_ensure_schema($db);
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_documents', '*', [
                'stay_id' => $stayIds,
                'retain_until[!]' => null,
                'retain_until[<]' => date('Y-m-d'),
                'ORDER' => ['retain_until' => 'ASC'],
                'LIMIT' => max(1, $limit),
            ]) ?: [];
        } catch (\Throwable $e) { error_log('docs_retention_due: ' . $e->getMessage()); return []; }
    }
}

// --- 5. COMPLIANCE REPORT ---------------------------------------------------
if (!function_exists('docs_compliance_report')) {
    /**
     * Roll up the compliance posture across the scope from REAL rows only:
     *   by_type[doc_type] => count
     *   by_status[status] => count
     *   expired / due_soon counts
     *   checklist_items_done / checklist_items_total / checklist_pct
     *   retention_due count
     */
    function docs_compliance_report($db, array $stayIds, int $leadDays = 60): array
    {
        _docs_ext_ensure_schema($db);
        $out = [
            'total' => 0, 'by_type' => [], 'by_status' => [],
            'expired' => 0, 'due_soon' => 0,
            'checklist_items_done' => 0, 'checklist_items_total' => 0, 'checklist_pct' => 0,
            'retention_due' => 0,
        ];
        if (empty($stayIds)) { return $out; }
        try {
            $docs = $db->select('stays_documents', ['doc_type', 'status'], ['stay_id' => $stayIds, 'LIMIT' => 5000]) ?: [];
            $out['total'] = count($docs);
            foreach ($docs as $d) {
                $t = (string) ($d['doc_type'] ?? 'other');
                $s = (string) ($d['status'] ?? 'active');
                $out['by_type'][$t] = ($out['by_type'][$t] ?? 0) + 1;
                $out['by_status'][$s] = ($out['by_status'][$s] ?? 0) + 1;
            }
            $buckets = docs_expiring($db, $stayIds, $leadDays);
            $out['expired']  = count($buckets['expired']);
            $out['due_soon'] = count($buckets['due_soon']);

            $items = $db->select('stays_compliance_checklist_items', ['done'], ['stay_id' => $stayIds, 'LIMIT' => 5000]) ?: [];
            $out['checklist_items_total'] = count($items);
            foreach ($items as $it) { if (!empty($it['done'])) { $out['checklist_items_done']++; } }
            $out['checklist_pct'] = $out['checklist_items_total'] > 0
                ? (int) round($out['checklist_items_done'] / $out['checklist_items_total'] * 100) : 0;

            $out['retention_due'] = count(docs_retention_due($db, $stayIds, 1000));
        } catch (\Throwable $e) { error_log('docs_compliance_report: ' . $e->getMessage()); }
        return $out;
    }
}
