<?php
// FILE: app/lib/supplier_migration_ext.php
// DATA MIGRATION & ONBOARDING TOOLS — DEEPENING (PMS #60; docs 01b §2.60).
//
// The BASE already covers the go-live NUDGE (supplier_onboarding_state + the
// _onboarding.php / get-started.php checklist) and the per-screen CRUD a new
// operator would otherwise do by hand. What was MISSING — and what this file
// adds — is the bulk DATA-MIGRATION path a property needs on day one:
//
//   - CSV importers (rooms, guests, rate, inventory, opening balances) that are
//     REAL and internal — they write to the same tables the live app reads, via
//     the canonical writers where one exists (stays_inventory_set for
//     availability; gl_post for opening balances), so nothing here can oversell
//     or post an unbalanced journal.
//   - A strict two-phase flow: PARSE+VALIDATE (mig_import_preview — pure, NO
//     writes) → COMMIT (mig_import_commit — row-by-row, inside $db->action()).
//     The preview is what the UI shows before anything touches the database.
//   - A per-batch audit row (supplier_import_batches) recording kind, counts,
//     and a short error digest, so an operator can see what each run did.
//   - A go-live readiness report (mig_go_live_report) that AGGREGATES existing
//     signals (rooms present, a sellable rate/option, availability loaded, a
//     payout method, tax configured, GL opened) into a single checklist — it is
//     READ-ONLY and never fakes a "ready" it cannot prove.
//
// MONEY / GL: opening balances are the only money path. They go through gl_post
// (balanced, period-aware, idempotent by (source,reference)); the offset is
// always Owner Equity (GL 3000), so an opening trial balance nets to zero. No
// client-supplied GL amount is ever trusted beyond being summed into a balanced
// pair. DB-GATED: opening-balance import needs the GL tables (chart_of_accounts,
// journal_entries, journal_lines) from supplier_ledger.php's schema.
//
// EXTERNAL EDGES (honest, NOT faked): there is no live connector to any other
// PMS/channel manager. "Import from <other PMS>" is a CSV hand-off, not an API
// sync; mig_connector_status() reports every such connector as 'not_connected'.
//
// All functions if_not_exists-guarded, scope-checked by the caller (routes do
// SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can), defensive/non-fatal.
// DB-GATED: supplier_import_batches must be created (see deepen schema_sql).

if (!function_exists('mig_import_kinds')) {
    /**
     * Supported import kinds (key => meta). `scope` is 'property' (needs a
     * stay_id) or 'org' (whole business). `money` flags the GL path. `columns`
     * are the EXPECTED CSV headers (first row), in order, for the template.
     */
    function mig_import_kinds(): array
    {
        return [
            'rooms' => [
                'label'   => 'Rooms / unit types',
                'scope'   => 'property',
                'money'   => false,
                'columns' => ['room_type', 'status'],
                'hint'    => 'One row per room type. room_type matches your room-type taxonomy by name or id; status is 1 (sellable) or 0.',
            ],
            'guests' => [
                'label'   => 'Guest profiles',
                'scope'   => 'org',
                'money'   => false,
                'columns' => ['email', 'name', 'vip', 'tags', 'note'],
                'hint'    => 'One row per guest. email is the key (upsert). vip is 1/0; tags are comma-separated inside one cell.',
            ],
            'rate' => [
                'label'   => 'Base rates (per date)',
                'scope'   => 'property',
                'money'   => false,
                'columns' => ['room_id', 'option_id', 'date', 'price'],
                'hint'    => 'One row per room/option/date. Sets the calendar nightly price. room_id/option_id must already exist on this property.',
            ],
            'inventory' => [
                'label'   => 'Availability / inventory',
                'scope'   => 'property',
                'money'   => false,
                'columns' => ['room_id', 'option_id', 'date', 'available_count'],
                'hint'    => 'One row per room/option/date. Sets the per-night ceiling. Never dropped below rooms already committed that night.',
            ],
            'opening_balances' => [
                'label'   => 'Opening balances (GL)',
                'scope'   => 'org',
                'money'   => true,
                'columns' => ['account_code', 'debit', 'credit'],
                'hint'    => 'Your trial balance at go-live. Each row is one account (GL code) with a debit OR a credit. The whole file must balance; the difference, if any, is auto-posted to Owner Equity (3000).',
            ],
        ];
    }
}

if (!function_exists('mig_import_kind_meta')) {
    /** Meta for one kind, or null if unknown. */
    function mig_import_kind_meta(string $kind): ?array
    {
        $k = mig_import_kinds();
        return $k[$kind] ?? null;
    }
}

if (!function_exists('mig_template_csv')) {
    /** The header + one example row as a CSV string, for the download template. */
    function mig_template_csv(string $kind): string
    {
        $meta = mig_import_kind_meta($kind);
        if ($meta === null) { return ''; }
        $examples = [
            'rooms'            => ['Deluxe Double', '1'],
            'guests'           => ['guest@example.com', 'Jane Doe', '0', 'repeat,corporate', 'Prefers high floor'],
            'rate'             => ['0', '1', date('Y-m-d'), '120.00'],
            'inventory'        => ['0', '1', date('Y-m-d'), '5'],
            'opening_balances' => ['1000', '5000.00', '0.00'],
        ];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $meta['columns']);
        if (isset($examples[$kind])) { fputcsv($out, $examples[$kind]); }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string) $csv;
    }
}

if (!function_exists('mig_parse_csv')) {
    /**
     * Parse a CSV string into ['header'=>[...], 'rows'=>[[...],...]] with the
     * header lower-cased/trimmed. Pure, defensive, bounded. Returns
     * ['ok'=>bool,'message'=>?,'header'=>[],'rows'=>[]]. $maxRows caps the file
     * so a pasted/huge upload can never blow memory (default 5000).
     */
    function mig_parse_csv(string $raw, int $maxRows = 5000): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip UTF-8 BOM
        if (trim($raw) === '') { return ['ok' => false, 'message' => 'The file is empty.', 'header' => [], 'rows' => []]; }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh);
        if ($header === false || $header === null) {
            fclose($fh);
            return ['ok' => false, 'message' => 'Could not read a header row.', 'header' => [], 'rows' => []];
        }
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        $n = 0;
        while (($r = fgetcsv($fh)) !== false) {
            // Skip wholly blank lines.
            if (count($r) === 1 && trim((string) $r[0]) === '') { continue; }
            $rows[] = array_map(fn($v) => trim((string) $v), $r);
            if (++$n >= $maxRows) {
                fclose($fh);
                return ['ok' => false, 'message' => 'File too large (max ' . $maxRows . ' data rows). Split it into smaller files.', 'header' => $header, 'rows' => []];
            }
        }
        fclose($fh);
        if (empty($rows)) { return ['ok' => false, 'message' => 'No data rows found after the header.', 'header' => $header, 'rows' => []]; }
        return ['ok' => true, 'message' => '', 'header' => $header, 'rows' => $rows];
    }
}

if (!function_exists('mig_row_assoc')) {
    /** Map a positional row to a header-keyed assoc array (missing cols => ''). */
    function mig_row_assoc(array $header, array $row): array
    {
        $out = [];
        foreach ($header as $i => $col) { $out[$col] = isset($row[$i]) ? $row[$i] : ''; }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// PREVIEW — pure validation, NO writes. Returns a per-row verdict so the UI can
// show exactly what commit would do and why any row would be skipped.
// ---------------------------------------------------------------------------
if (!function_exists('mig_import_preview')) {
    /**
     * Validate a parsed CSV for $kind against the live DB, WITHOUT writing.
     * $ctx: ['org'=>int,'stay_id'=>int,'owned_stay_ids'=>int[]] (route supplies).
     * Returns:
     *   ['ok'=>bool, 'message'=>?, 'kind'=>..., 'valid'=>int, 'invalid'=>int,
     *    'rows'=>[ ['line'=>int,'ok'=>bool,'error'=>?,'data'=>[...]] , ... ],
     *    'summary'=>[ ... kind-specific totals ... ] ]
     * Guarantees: every property-scoped row is checked to belong to $ctx['stay_id'];
     * nothing cross-tenant can pass. No money is trusted beyond numeric parsing.
     */
    function mig_import_preview($db, string $kind, array $parsed, array $ctx): array
    {
        $meta = mig_import_kind_meta($kind);
        if ($meta === null) { return ['ok' => false, 'message' => 'Unknown import type.']; }
        if (empty($parsed['ok'])) { return ['ok' => false, 'message' => $parsed['message'] ?? 'Could not parse the file.']; }

        $header = $parsed['header'];
        $required = $meta['columns'];
        // Required columns present? (extra columns are ignored, not an error.)
        $missing = array_values(array_diff($required, $header));
        // For optional-trailing kinds (guests) only the key columns are mandatory.
        $hardRequired = $required;
        if ($kind === 'guests') { $hardRequired = ['email']; }
        if ($kind === 'rooms')  { $hardRequired = ['room_type']; }
        $hardMissing = array_values(array_diff($hardRequired, $header));
        if (!empty($hardMissing)) {
            return ['ok' => false, 'message' => 'Missing required column(s): ' . implode(', ', $hardMissing) . '. Expected header: ' . implode(', ', $required) . '.'];
        }

        $stayId = (int) ($ctx['stay_id'] ?? 0);
        $orgId  = (int) ($ctx['org'] ?? 0);

        // Pre-load reference data once, per kind, to validate rows cheaply.
        $roomIndex = [];     // room_id => [option_id,...]  (for rate/inventory)
        $roomTypeByName = []; // lower(name) => id          (for rooms)
        $roomTypeIds = [];    // id => true
        $glCodes = [];        // code => true               (for opening_balances)
        if (in_array($kind, ['rate', 'inventory'], true) && $stayId > 0) {
            try {
                $rooms = $db->select('stays_rooms', ['id', 'room_options'], ['stay_id' => $stayId]) ?: [];
                foreach ($rooms as $rm) {
                    $rid = (int) $rm['id'];
                    $opts = [];
                    if (!empty($rm['room_options'])) {
                        $dec = json_decode((string) $rm['room_options'], true);
                        if (is_array($dec)) {
                            foreach ($dec as $o) { if (isset($o['option_id'])) { $opts[] = (int) $o['option_id']; } }
                        }
                    }
                    if (empty($opts)) { $opts = [1]; } // option-1 default (matches calendar default)
                    $roomIndex[$rid] = $opts;
                }
            } catch (\Throwable $e) { error_log('mig_import_preview rooms: ' . $e->getMessage()); }
        }
        if ($kind === 'rooms' && $stayId > 0) {
            try {
                $types = $db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type', 'status' => 1]) ?: [];
                foreach ($types as $t) { $roomTypeByName[strtolower(trim((string) $t['name']))] = (int) $t['id']; $roomTypeIds[(int) $t['id']] = true; }
            } catch (\Throwable $e) { error_log('mig_import_preview types: ' . $e->getMessage()); }
        }
        if ($kind === 'opening_balances' && $orgId > 0) {
            try {
                if (function_exists('gl_seed_accounts')) { gl_seed_accounts($db, $orgId); }
                $accts = $db->select('chart_of_accounts', ['code'], ['org_id' => $orgId]) ?: [];
                foreach ($accts as $a) { $glCodes[(string) $a['code']] = true; }
            } catch (\Throwable $e) { error_log('mig_import_preview gl: ' . $e->getMessage()); }
        }

        $out = [];
        $valid = 0; $invalid = 0;
        $sumDebit = 0.0; $sumCredit = 0.0;
        $line = 1; // header is line 1
        foreach ($parsed['rows'] as $row) {
            $line++;
            $r = mig_row_assoc($header, $row);
            $err = null; $data = [];

            switch ($kind) {
                case 'rooms':
                    $rt = trim((string) ($r['room_type'] ?? ''));
                    if ($rt === '') { $err = 'room_type is required'; break; }
                    $typeId = 0;
                    if (ctype_digit($rt)) {
                        $typeId = (int) $rt;
                        if (!empty($roomTypeIds) && !isset($roomTypeIds[$typeId])) { $err = 'unknown room_type id ' . $typeId; break; }
                    } else {
                        $typeId = $roomTypeByName[strtolower($rt)] ?? 0;
                        if ($typeId <= 0) { $err = 'room type "' . $rt . '" not found in your taxonomy'; break; }
                    }
                    $status = isset($r['status']) && $r['status'] !== '' ? ((int) $r['status'] === 1 ? 1 : 0) : 1;
                    $data = ['room_type_id' => $typeId, 'status' => $status];
                    break;

                case 'guests':
                    $email = strtolower(trim((string) ($r['email'] ?? '')));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'invalid email'; break; }
                    $data = [
                        'email' => $email,
                        'name'  => mb_substr(trim((string) ($r['name'] ?? '')), 0, 191),
                        'vip'   => (isset($r['vip']) && (int) $r['vip'] === 1) ? 1 : 0,
                        'tags'  => mb_substr(trim((string) ($r['tags'] ?? '')), 0, 191),
                        'note'  => mb_substr(trim((string) ($r['note'] ?? '')), 0, 2000),
                    ];
                    break;

                case 'rate':
                case 'inventory':
                    $rid = (int) ($r['room_id'] ?? 0);
                    $oid = (int) ($r['option_id'] ?? 0);
                    $date = trim((string) ($r['date'] ?? ''));
                    if ($rid <= 0) { $err = 'room_id required'; break; }
                    if (!isset($roomIndex[$rid])) { $err = 'room_id ' . $rid . ' is not a room on this property'; break; }
                    if ($oid <= 0) { $oid = 1; }
                    if (!in_array($oid, $roomIndex[$rid], true)) { $err = 'option_id ' . $oid . ' not found on room ' . $rid; break; }
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) { $err = 'date must be YYYY-MM-DD'; break; }
                    if ($kind === 'rate') {
                        $price = (float) ($r['price'] ?? -1);
                        if ($price < 0) { $err = 'price must be >= 0'; break; }
                        $data = ['room_id' => $rid, 'option_id' => $oid, 'date' => $date, 'price' => round($price, 2)];
                    } else {
                        $avail = $r['available_count'] ?? '';
                        if ($avail === '' || !ctype_digit(ltrim((string) $avail, '-')) || (int) $avail < 0) { $err = 'available_count must be a non-negative integer'; break; }
                        $data = ['room_id' => $rid, 'option_id' => $oid, 'date' => $date, 'available_count' => (int) $avail];
                    }
                    break;

                case 'opening_balances':
                    $code = trim((string) ($r['account_code'] ?? ''));
                    if ($code === '') { $err = 'account_code required'; break; }
                    if (!empty($glCodes) && !isset($glCodes[$code])) { $err = 'unknown GL account code ' . $code; break; }
                    $debit = round((float) ($r['debit'] ?? 0), 2);
                    $credit = round((float) ($r['credit'] ?? 0), 2);
                    if ($debit < 0 || $credit < 0) { $err = 'amounts cannot be negative'; break; }
                    if (($debit > 0) === ($credit > 0)) { $err = 'each row must have exactly one of debit or credit'; break; }
                    $sumDebit = round($sumDebit + $debit, 2);
                    $sumCredit = round($sumCredit + $credit, 2);
                    $data = ['code' => $code, 'debit' => $debit, 'credit' => $credit];
                    break;

                default:
                    $err = 'unsupported type';
            }

            $ok = ($err === null);
            if ($ok) { $valid++; } else { $invalid++; }
            $out[] = ['line' => $line, 'ok' => $ok, 'error' => $err, 'data' => $data, 'raw' => $r];
        }

        $summary = ['valid' => $valid, 'invalid' => $invalid, 'total' => $valid + $invalid];
        if ($kind === 'opening_balances') {
            $diff = round($sumDebit - $sumCredit, 2);
            $summary['total_debit'] = $sumDebit;
            $summary['total_credit'] = $sumCredit;
            $summary['difference'] = $diff;
            // An imbalance is auto-offset to Owner Equity (3000) at commit, so it is
            // NOT a hard error — but it IS surfaced so the operator confirms it.
            $summary['equity_offset'] = $diff; // debit-positive => credit equity, and vice-versa
        }

        return [
            'ok' => true, 'message' => '', 'kind' => $kind,
            'valid' => $valid, 'invalid' => $invalid,
            'rows' => $out, 'summary' => $summary,
        ];
    }
}

// ---------------------------------------------------------------------------
// COMMIT — writes the VALID rows only. Reuses canonical writers for money /
// availability. Records a batch row. Returns ['ok','applied','skipped','message'].
// ---------------------------------------------------------------------------
if (!function_exists('mig_import_commit')) {
    function mig_import_commit($db, string $kind, array $parsed, array $ctx): array
    {
        $meta = mig_import_kind_meta($kind);
        if ($meta === null) { return ['ok' => false, 'message' => 'Unknown import type.']; }

        // Re-validate from scratch on commit (never trust a client-echoed preview).
        $preview = mig_import_preview($db, $kind, $parsed, $ctx);
        if (empty($preview['ok'])) { return ['ok' => false, 'message' => $preview['message'] ?? 'Validation failed.']; }

        $stayId = (int) ($ctx['stay_id'] ?? 0);
        $orgId  = (int) ($ctx['org'] ?? 0);
        $actor  = (string) ($ctx['actor'] ?? ($_SESSION['user_id'] ?? ''));
        $validRows = array_values(array_filter($preview['rows'], fn($x) => !empty($x['ok'])));

        $applied = 0; $skipped = (int) $preview['invalid']; $errors = [];

        try {
            switch ($kind) {
                case 'rooms':
                    $now = date('Y-m-d H:i:s');
                    foreach ($validRows as $vr) {
                        try {
                            $db->insert('stays_rooms', [
                                'stay_id'      => $stayId,         // FORCED
                                'room_type_id' => (int) $vr['data']['room_type_id'],
                                'amenities'    => null,
                                'status'       => (int) $vr['data']['status'],
                                'room_options' => null,
                                'room_images'  => null,
                                'created_at'   => $now,
                                'updated_at'   => $now,
                            ]);
                            $applied++;
                        } catch (\Throwable $e) {
                            $skipped++; $errors[] = 'line ' . $vr['line'] . ': ' . $e->getMessage();
                        }
                    }
                    break;

                case 'guests':
                    $now = date('Y-m-d H:i:s');
                    foreach ($validRows as $vr) {
                        $d = $vr['data'];
                        try {
                            $exists = $db->get('stays_guest_profiles', ['id'], ['org_id' => $orgId, 'email' => $d['email']]);
                            if ($exists) {
                                $db->update('stays_guest_profiles', [
                                    'vip'        => $d['vip'],
                                    'tags'       => $d['tags'] !== '' ? $d['tags'] : null,
                                    'note'       => $d['note'] !== '' ? $d['note'] : null,
                                    'updated_at' => $now,
                                ], ['id' => (int) $exists['id']]);
                            } else {
                                $db->insert('stays_guest_profiles', [
                                    'org_id'     => $orgId,
                                    'email'      => $d['email'],
                                    'vip'        => $d['vip'],
                                    'tags'       => $d['tags'] !== '' ? $d['tags'] : null,
                                    'note'       => $d['note'] !== '' ? $d['note'] : null,
                                    'created_at' => $now,
                                ]);
                            }
                            $applied++;
                        } catch (\Throwable $e) {
                            $skipped++; $errors[] = 'line ' . $vr['line'] . ': ' . $e->getMessage();
                        }
                    }
                    break;

                case 'rate':
                    // Upsert the per-date nightly price in stays_rooms_calendar (the same
                    // table the calendar screen + booking funnel read). option_id default 1.
                    $now = date('Y-m-d H:i:s');
                    foreach ($validRows as $vr) {
                        $d = $vr['data'];
                        try {
                            $ex = $db->get('stays_rooms_calendar', ['id'],
                                ['stay_id' => $stayId, 'room_id' => (int) $d['room_id'], 'option_id' => (int) $d['option_id'], 'date' => $d['date']]);
                            if ($ex) {
                                $db->update('stays_rooms_calendar', ['price' => $d['price'], 'updated_at' => $now], ['id' => (int) $ex['id']]);
                            } else {
                                $db->insert('stays_rooms_calendar', [
                                    'stay_id' => $stayId, 'room_id' => (int) $d['room_id'], 'option_id' => (int) $d['option_id'],
                                    'date' => $d['date'], 'price' => $d['price'], 'created_at' => $now, 'updated_at' => $now,
                                ]);
                            }
                            $applied++;
                        } catch (\Throwable $e) {
                            $skipped++; $errors[] = 'line ' . $vr['line'] . ': ' . $e->getMessage();
                        }
                    }
                    break;

                case 'inventory':
                    // Reuse the canonical, oversell-safe writer (FOR UPDATE, committed-usage
                    // guard). One call per row keeps each date's guard intact.
                    foreach ($validRows as $vr) {
                        $d = $vr['data'];
                        if (!function_exists('stays_inventory_set')) {
                            $skipped++; $errors[] = 'line ' . $vr['line'] . ': inventory engine unavailable';
                            continue;
                        }
                        $res = stays_inventory_set($db, $stayId, (int) $d['room_id'], (int) $d['option_id'], $d['date'], $d['date'],
                            ['available_count' => (int) $d['available_count']]);
                        if (!empty($res['ok'])) { $applied++; }
                        else { $skipped++; $errors[] = 'line ' . $vr['line'] . ': ' . ($res['message'] ?? 'rejected'); }
                    }
                    break;

                case 'opening_balances':
                    // ONE balanced opening journal. We NEVER post unbalanced GL: the file's
                    // net difference is offset to Owner Equity (3000) so debits == credits.
                    // Idempotent by (source,reference) so a re-run can't double-post.
                    if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'The general ledger is not available for this account (DB-gated).']; }
                    $lines = [];
                    foreach ($validRows as $vr) {
                        $d = $vr['data'];
                        $lines[] = ['code' => $d['code'], 'debit' => $d['debit'], 'credit' => $d['credit']];
                    }
                    if (empty($lines)) { return ['ok' => false, 'message' => 'No valid opening-balance rows to post.']; }
                    $diff = round((float) ($preview['summary']['difference'] ?? 0), 2);
                    if (abs($diff) >= 0.01) {
                        // debit-heavy file (diff>0) => credit equity to balance; else debit it.
                        if ($diff > 0) { $lines[] = ['code' => '3000', 'credit' => $diff]; }
                        else { $lines[] = ['code' => '3000', 'debit' => -$diff]; }
                    }
                    $ref = 'opening-' . $orgId . '-' . substr(sha1(json_encode($lines)), 0, 12);
                    $res = gl_post($db, $orgId, $lines, [
                        'date'      => $ctx['opening_date'] ?? date('Y-m-d'),
                        'source'    => 'opening_import',
                        'reference' => $ref,
                        'memo'      => 'Opening balances (data migration import)',
                    ]);
                    if (empty($res['ok'])) { return ['ok' => false, 'message' => 'Could not post opening balances: ' . ($res['message'] ?? 'rejected') . '.']; }
                    $applied = count($validRows);
                    if (!empty($res['duplicate'])) {
                        mig_record_batch($db, $orgId, $stayId, $kind, $applied, $skipped, $actor, 'Already posted (idempotent no-op).');
                        return ['ok' => true, 'applied' => 0, 'skipped' => $skipped, 'message' => 'These opening balances were already posted (no double entry).'];
                    }
                    break;
            }
        } catch (\Throwable $e) {
            error_log('mig_import_commit ' . $kind . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The import failed partway. Please re-check and retry.'];
        }

        $digest = empty($errors) ? '' : mb_substr(implode(' | ', array_slice($errors, 0, 10)), 0, 1000);
        mig_record_batch($db, $orgId, $stayId, $kind, $applied, $skipped, $actor, $digest);

        $msg = $applied . ' row(s) imported';
        if ($skipped > 0) { $msg .= ', ' . $skipped . ' skipped'; }
        $msg .= '.';
        return ['ok' => true, 'applied' => $applied, 'skipped' => $skipped, 'message' => $msg];
    }
}

if (!function_exists('mig_record_batch')) {
    /** Insert an audit row for one import run. Non-fatal. */
    function mig_record_batch($db, int $orgId, int $stayId, string $kind, int $applied, int $skipped, string $actor, string $digest): void
    {
        try {
            $db->insert('supplier_import_batches', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId > 0 ? $stayId : null,
                'kind'        => mb_substr($kind, 0, 32),
                'rows_applied' => $applied,
                'rows_skipped' => $skipped,
                'error_digest' => $digest !== '' ? $digest : null,
                'actor'       => mb_substr($actor, 0, 255),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('mig_record_batch: ' . $e->getMessage());
        }
    }
}

if (!function_exists('mig_recent_batches')) {
    /** Recent import runs for this org (newest first). Read-only; [] on error. */
    function mig_recent_batches($db, int $orgId, int $limit = 20): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_import_batches',
                ['id', 'stay_id', 'kind', 'rows_applied', 'rows_skipped', 'error_digest', 'actor', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(100, $limit))]) ?: [];
        } catch (\Throwable $e) {
            error_log('mig_recent_batches: ' . $e->getMessage());
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// GO-LIVE READINESS — read-only aggregation of signals already in the DB.
// ---------------------------------------------------------------------------
if (!function_exists('mig_go_live_report')) {
    /**
     * A go-live checklist for one property (+ its org). Each item:
     *   ['key','label','done'(bool),'detail','hint']
     * All checks READ existing data; none fakes readiness. Safe on partial DBs.
     */
    function mig_go_live_report($db, int $stayId, int $orgId, string $owner = ''): array
    {
        $items = [];
        $has = function (string $table, array $where) use ($db): bool {
            try { return (bool) $db->has($table, $where); } catch (\Throwable $e) { return false; }
        };
        $count = function (string $table, array $where) use ($db): int {
            try { return (int) $db->count($table, $where); } catch (\Throwable $e) { return 0; }
        };

        // 1. Property exists + has a name/location.
        $stay = null;
        try { $stay = $db->get('stays', ['id', 'name', 'location', 'listing_status', 'status'], ['id' => $stayId]); } catch (\Throwable $e) {}
        $items[] = [
            'key' => 'property', 'label' => 'Property details complete',
            'done' => is_array($stay) && trim((string) ($stay['name'] ?? '')) !== '' && trim((string) ($stay['location'] ?? '')) !== '',
            'detail' => is_array($stay) ? (string) ($stay['name'] ?? '') : 'Not found',
            'hint' => 'Name and location are set on the property form.',
        ];

        // 2. At least one room.
        $rooms = $count('stays_rooms', ['stay_id' => $stayId]);
        $items[] = [
            'key' => 'rooms', 'label' => 'Rooms / unit types added',
            'done' => $rooms > 0, 'detail' => $rooms . ' room type(s)',
            'hint' => 'Add rooms manually or via the Rooms importer.',
        ];

        // 3. A sellable rate/option — a room with a non-empty room_options JSON,
        //    OR a calendar price loaded.
        $sellable = false; $calCount = $count('stays_rooms_calendar', ['stay_id' => $stayId]);
        try {
            $withOpts = $db->select('stays_rooms', ['room_options'], ['stay_id' => $stayId]) ?: [];
            foreach ($withOpts as $w) {
                $dec = !empty($w['room_options']) ? json_decode((string) $w['room_options'], true) : null;
                if (is_array($dec) && !empty($dec)) { $sellable = true; break; }
            }
        } catch (\Throwable $e) {}
        $items[] = [
            'key' => 'rates', 'label' => 'Rates loaded',
            'done' => $sellable || $calCount > 0,
            'detail' => $calCount > 0 ? ($calCount . ' calendar price(s)') : ($sellable ? 'Option pricing set' : 'None'),
            'hint' => 'Set base rates on each room, or import them.',
        ];

        // 4. Availability loaded.
        $inv = $count('stays_inventory', ['stay_id' => $stayId]);
        $items[] = [
            'key' => 'inventory', 'label' => 'Availability loaded',
            'done' => $inv > 0, 'detail' => $inv . ' night-row(s)',
            'hint' => 'Load availability via the calendar or the Inventory importer.',
        ];

        // 5. Payout destination on file (owner-scoped) — reuse the canonical resolver
        //    (users.payout_* columns) rather than inventing a table. Verified is a plus.
        $hasBank = false; $bankDetail = 'None';
        if ($owner !== '' && function_exists('supplier_payout_destination')) {
            $dest = supplier_payout_destination($db, $owner);
            $hasBank = !empty($dest['has_bank']);
            $bankDetail = $hasBank ? (!empty($dest['verified']) ? 'Verified' : 'On file (unverified)') : 'None';
        }
        $items[] = [
            'key' => 'payout', 'label' => 'Payout method set',
            'done' => $hasBank, 'detail' => $bankDetail,
            'hint' => 'Add your bank account for payouts in Finance → Settlements.',
        ];

        // 6. Tax configured (org-scoped). DB-gated.
        $tax = $count('tax_profiles', ['org_id' => $orgId]);
        $items[] = [
            'key' => 'tax', 'label' => 'Tax configured',
            'done' => $tax > 0, 'detail' => $tax > 0 ? ($tax . ' profile(s)') : 'None',
            'hint' => 'Define your VAT/GST/city tax in Tax & fiscal.',
        ];

        // 7. GL opened (any journal entry for this org). DB-gated.
        $gl = $count('journal_entries', ['org_id' => $orgId]);
        $items[] = [
            'key' => 'gl', 'label' => 'Opening balances posted',
            'done' => $gl > 0, 'detail' => $gl > 0 ? ($gl . ' journal entry(ies)') : 'None',
            'hint' => 'Import your trial balance via Opening balances (optional).',
        ];

        // 8. Listing live.
        $live = is_array($stay) && (string) ($stay['listing_status'] ?? '') === 'live' && (int) ($stay['status'] ?? 0) === 1;
        $items[] = [
            'key' => 'live', 'label' => 'Listing approved & live',
            'done' => $live, 'detail' => is_array($stay) ? (string) ($stay['listing_status'] ?? 'draft') : 'n/a',
            'hint' => 'New listings go through admin approval before they sell.',
        ];

        // Readiness excludes the two optional items (payout/tax/gl are "recommended",
        // live is the outcome): "sellable" = property+rooms+rates+inventory.
        $coreKeys = ['property', 'rooms', 'rates', 'inventory'];
        $coreDone = 0;
        foreach ($items as $it) { if (in_array($it['key'], $coreKeys, true) && $it['done']) { $coreDone++; } }
        $done = 0; foreach ($items as $it) { if ($it['done']) { $done++; } }

        return [
            'items'       => $items,
            'total'       => count($items),
            'done'        => $done,
            'percent'     => count($items) > 0 ? (int) round($done / count($items) * 100) : 0,
            'sellable'    => $coreDone === count($coreKeys),
            'core_done'   => $coreDone,
            'core_total'  => count($coreKeys),
        ];
    }
}

// ---------------------------------------------------------------------------
// EXTERNAL CONNECTORS — HONEST stub. No live PMS/channel import API is wired.
// ---------------------------------------------------------------------------
if (!function_exists('mig_connector_status')) {
    /**
     * Status of "import from another system" connectors. These are a CSV
     * hand-off today, not a live sync — so every connector reports
     * connected=false with the honest reason. Never returns a faked 'connected'.
     */
    function mig_connector_status(): array
    {
        $msg = 'No live API connection. Export a CSV from your current system and use the importers above.';
        return [
            ['key' => 'opera',       'label' => 'Oracle OPERA',        'connected' => false, 'message' => $msg],
            ['key' => 'cloudbeds',   'label' => 'Cloudbeds',           'connected' => false, 'message' => $msg],
            ['key' => 'mews',        'label' => 'Mews',                'connected' => false, 'message' => $msg],
            ['key' => 'channel',     'label' => 'Channel manager',     'connected' => false, 'message' => $msg],
            ['key' => 'generic_csv', 'label' => 'Generic CSV / Excel', 'connected' => true,  'message' => 'Supported via the importers on this page.'],
        ];
    }
}
