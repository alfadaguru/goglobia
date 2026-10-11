<?php
// FILE: app/lib/supplier_assets_ext.php
// Fixed assets & equipment — DEEPENING of #28 on top of stays_assets + supplier_facilities.php.
// Base (already shipped): asset register, categories, location, serial_no, purchase info,
//   condition status (asset_add / asset_set_condition / asset_list).
// This file adds ONLY the genuine spec gaps:
//   - tagging (QR/NFC/asset-tag code)              -> asset_set_tag + asset_find_by_tag
//   - warranty records                             -> asset_set_warranty
//   - depreciation records (straight-line, local)  -> asset_depreciation_add / _schedule / _list
//   - transfers (location / property)              -> asset_transfer (+ event log)
//   - repair / service history                     -> asset_event_add / asset_events
//   - disposal                                     -> asset_dispose
//   - asset reports (register value, book value)   -> asset_report
// House rules: every fn if_not_exists-guarded; property-scoped (CALLER authorizes the stay
// via supplier_can); defensive/non-fatal; NO money/folio/gateway touched — depreciation is a
// pure internal accounting register (local math only), NOT posted to any GL or folio.
// NOT runtime-verified (no MySQL in build env) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// Vocab
// ---------------------------------------------------------------------------
if (!function_exists('asset_event_types')) {
    function asset_event_types(): array
    {
        return [
            'acquired'     => 'Acquired',
            'repair'       => 'Repair / service',
            'inspection'   => 'Inspection',
            'transfer'     => 'Transfer',
            'warranty'     => 'Warranty note',
            'disposal'     => 'Disposal',
            'note'         => 'Note',
        ];
    }
}

if (!function_exists('asset_disposal_methods')) {
    function asset_disposal_methods(): array
    {
        return ['sold' => 'Sold', 'scrapped' => 'Scrapped', 'donated' => 'Donated', 'lost' => 'Lost / stolen', 'traded_in' => 'Traded in'];
    }
}

// ---------------------------------------------------------------------------
// Internal: verify an asset belongs to one of the owner's stays (IDOR guard inside lib).
// The route ALSO checks supplier_can($db,'rooms','edit',$stayId); this is defence-in-depth.
// ---------------------------------------------------------------------------
if (!function_exists('_asset_owned_row')) {
    function _asset_owned_row($db, int $id, int $stayId): ?array
    {
        if ($id <= 0 || $stayId <= 0) { return null; }
        try {
            $row = $db->get('stays_assets', '*', ['id' => $id, 'stay_id' => $stayId]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('_asset_owned_row: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('_asset_log_event')) {
    /** Append a history event. Internal; callers pass already-validated asset+stay. */
    function _asset_log_event($db, int $assetId, int $stayId, string $type, string $detail = '', ?float $cost = null): int
    {
        if (!isset(asset_event_types()[$type])) { $type = 'note'; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_asset_events', [
                'org_id'    => $orgId > 0 ? $orgId : null,
                'asset_id'  => $assetId,
                'stay_id'   => $stayId,
                'event_type'=> $type,
                'detail'    => substr(trim($detail), 0, 500) ?: null,
                'cost'      => $cost !== null ? max(0, round($cost, 2)) : null,
                'logged_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'=> date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('_asset_log_event: ' . $e->getMessage()); return 0; }
    }
}

// ---------------------------------------------------------------------------
// TAGGING (QR / NFC / asset-tag). The tag is a per-org unique short code the supplier
// prints/encodes. We store it on stays_assets.asset_tag (added additively).
// ---------------------------------------------------------------------------
if (!function_exists('asset_set_tag')) {
    /** Assign/replace an asset tag. Enforces per-org uniqueness. Returns bool. */
    function asset_set_tag($db, int $id, int $stayId, string $tag): bool
    {
        $tag = trim($tag);
        if ($tag === '') { return false; }
        if (strlen($tag) > 64) { $tag = substr($tag, 0, 64); }
        $row = _asset_owned_row($db, $id, $stayId);
        if ($row === null) { return false; }
        try {
            $orgId = (int) ($row['org_id'] ?? 0);
            // uniqueness within the same org (ignore NULL org, and ignore self)
            if ($orgId > 0) {
                $clash = $db->has('stays_assets', ['AND' => ['org_id' => $orgId, 'asset_tag' => $tag, 'id[!]' => $id]]);
                if ($clash) { return false; }
            }
            $db->update('stays_assets', ['asset_tag' => $tag, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('asset_set_tag: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('asset_find_by_tag')) {
    /** Resolve a scanned tag to an asset within the owner's stays. Read-only. Returns row|null. */
    function asset_find_by_tag($db, array $stayIds, string $tag): ?array
    {
        $tag = trim($tag);
        if ($tag === '' || empty($stayIds)) { return null; }
        try {
            $row = $db->get('stays_assets', '*', ['AND' => ['stay_id' => $stayIds, 'asset_tag' => $tag]]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('asset_find_by_tag: ' . $e->getMessage()); return null; }
    }
}

// ---------------------------------------------------------------------------
// WARRANTY records (provider + expiry + reference). Stored on stays_assets.
// ---------------------------------------------------------------------------
if (!function_exists('asset_set_warranty')) {
    /** Set warranty provider/expiry/reference. Empty expiry clears it. Returns bool. */
    function asset_set_warranty($db, int $id, int $stayId, array $d): bool
    {
        if (_asset_owned_row($db, $id, $stayId) === null) { return false; }
        $exp = trim((string) ($d['warranty_expiry'] ?? ''));
        try {
            $db->update('stays_assets', [
                'warranty_provider' => substr(trim((string) ($d['warranty_provider'] ?? '')), 0, 120) ?: null,
                'warranty_expiry'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null,
                'warranty_ref'      => substr(trim((string) ($d['warranty_ref'] ?? '')), 0, 120) ?: null,
                'updated_at'        => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'stay_id' => $stayId]);
            _asset_log_event($db, $id, $stayId, 'warranty', 'Warranty updated' . ($exp !== '' ? ' (expires ' . $exp . ')' : ''));
            return true;
        } catch (\Throwable $e) { error_log('asset_set_warranty: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// DEPRECIATION — local straight-line register ONLY. This is a bookkeeping record of
// the asset's declining book value; it is NOT posted to the GL/folio and touches no
// money spine. Supplier captures useful-life years + salvage; we compute a per-period
// schedule and persist each annual entry to stays_asset_depreciation.
// ---------------------------------------------------------------------------
if (!function_exists('asset_depreciation_schedule')) {
    /**
     * Pure helper (no DB). Straight-line schedule from purchase info.
     * Returns [ ['year'=>int 'period'=>'YYYY', 'depreciation'=>float, 'book_value'=>float], ... ]
     * cost/salvage in the property currency; life in whole years (1..100).
     */
    function asset_depreciation_schedule(float $cost, float $salvage, int $lifeYears, string $startDate): array
    {
        $cost = max(0.0, round($cost, 2));
        $salvage = max(0.0, min($cost, round($salvage, 2)));
        $lifeYears = max(1, min(100, $lifeYears));
        if ($cost <= 0) { return []; }
        $annual = round(($cost - $salvage) / $lifeYears, 2);
        $startYear = (int) date('Y', strtotime($startDate ?: date('Y-m-d')) ?: time());
        $rows = [];
        $book = $cost;
        for ($i = 0; $i < $lifeYears; $i++) {
            // final period absorbs any rounding remainder so book value lands exactly on salvage
            $dep = ($i === $lifeYears - 1) ? round($book - $salvage, 2) : $annual;
            if ($dep < 0) { $dep = 0.0; }
            $book = round($book - $dep, 2);
            if ($book < $salvage) { $book = $salvage; }
            $rows[] = [
                'year'         => $i + 1,
                'period'       => (string) ($startYear + $i),
                'depreciation' => $dep,
                'book_value'   => $book,
            ];
        }
        return $rows;
    }
}

if (!function_exists('asset_depreciation_set')) {
    /**
     * Capture depreciation parameters on the asset and (re)generate its straight-line
     * schedule into stays_asset_depreciation (prior rows for the asset are cleared first).
     * Uses the asset's own purchase_cost/purchase_date as the basis. Returns periods written.
     */
    function asset_depreciation_set($db, int $id, int $stayId, array $d): int
    {
        $row = _asset_owned_row($db, $id, $stayId);
        if ($row === null) { return 0; }
        $cost = (float) ($row['purchase_cost'] ?? 0);
        if ($cost <= 0) { return 0; }
        $salvage = ($d['salvage_value'] ?? '') !== '' ? max(0.0, (float) $d['salvage_value']) : 0.0;
        $life = max(1, min(100, (int) ($d['life_years'] ?? 0)));
        $method = in_array(($d['method'] ?? ''), ['straight_line'], true) ? $d['method'] : 'straight_line';
        $start = (string) ($row['purchase_date'] ?? '') ?: date('Y-m-d');
        $sched = asset_depreciation_schedule($cost, $salvage, $life, $start);
        if (empty($sched)) { return 0; }
        try {
            $orgId = (int) ($row['org_id'] ?? 0);
            // persist params on the asset
            $db->update('stays_assets', [
                'depr_method'  => $method,
                'depr_life'    => $life,
                'depr_salvage' => round($salvage, 2),
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'stay_id' => $stayId]);
            // regenerate schedule
            $db->delete('stays_asset_depreciation', ['asset_id' => $id, 'stay_id' => $stayId]);
            $now = date('Y-m-d H:i:s');
            foreach ($sched as $s) {
                $db->insert('stays_asset_depreciation', [
                    'org_id'       => $orgId > 0 ? $orgId : null,
                    'asset_id'     => $id,
                    'stay_id'      => $stayId,
                    'period'       => $s['period'],
                    'method'       => $method,
                    'depreciation' => $s['depreciation'],
                    'book_value'   => $s['book_value'],
                    'created_at'   => $now,
                ]);
            }
            _asset_log_event($db, $id, $stayId, 'note', 'Depreciation schedule set (' . $life . 'y, salvage ' . number_format($salvage, 2) . ')');
            return count($sched);
        } catch (\Throwable $e) { error_log('asset_depreciation_set: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('asset_depreciation_list')) {
    /** Persisted schedule rows for one asset. Read-only. */
    function asset_depreciation_list($db, int $id, int $stayId): array
    {
        if ($id <= 0 || $stayId <= 0) { return []; }
        try {
            return $db->select('stays_asset_depreciation', '*',
                ['AND' => ['asset_id' => $id, 'stay_id' => $stayId], 'ORDER' => ['period' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('asset_depreciation_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('asset_book_value')) {
    /**
     * Current net book value as of a given year (defaults to current). Pure read from the
     * persisted schedule; falls back to purchase_cost if no schedule exists.
     */
    function asset_book_value($db, array $assetRow, ?int $asOfYear = null): float
    {
        $id = (int) ($assetRow['id'] ?? 0);
        $stayId = (int) ($assetRow['stay_id'] ?? 0);
        $cost = (float) ($assetRow['purchase_cost'] ?? 0);
        if (($assetRow['condition_status'] ?? '') === 'disposed') { return 0.0; }
        $asOf = $asOfYear ?? (int) date('Y');
        try {
            $rows = $db->select('stays_asset_depreciation', ['period', 'book_value'],
                ['AND' => ['asset_id' => $id, 'stay_id' => $stayId], 'ORDER' => ['period' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { return $cost; }
        if (empty($rows)) { return $cost; }
        $bv = $cost;
        foreach ($rows as $r) {
            if ((int) $r['period'] <= $asOf) { $bv = (float) $r['book_value']; }
        }
        return round($bv, 2);
    }
}

// ---------------------------------------------------------------------------
// TRANSFERS — move an asset to a different location and/or another owned property.
// We re-validate the DESTINATION stay belongs to the same owner at the route layer
// via supplier_can($db,'rooms','edit',$toStayId); this fn trusts that check happened
// but still confirms both ids are in the owner's set it is given.
// ---------------------------------------------------------------------------
if (!function_exists('asset_transfer')) {
    /**
     * Transfer asset $id (currently under $fromStayId) to $toStayId / $toLocation.
     * $ownedStayIds is the caller's authorized stay set — destination MUST be inside it.
     * Returns bool. Logs a transfer event on the asset.
     */
    function asset_transfer($db, int $id, int $fromStayId, int $toStayId, string $toLocation, array $ownedStayIds): bool
    {
        $row = _asset_owned_row($db, $id, $fromStayId);
        if ($row === null) { return false; }
        $toLocation = trim($toLocation);
        if ($toStayId <= 0) { $toStayId = $fromStayId; }
        if ($toStayId !== $fromStayId && !in_array($toStayId, array_map('intval', $ownedStayIds), true)) {
            return false; // destination not owned -> hard stop
        }
        if ($toStayId === $fromStayId && ($toLocation === '' || $toLocation === (string) ($row['location'] ?? ''))) {
            // no-op transfer within same property/location
            return false;
        }
        try {
            $fromLoc = (string) ($row['location'] ?? '');
            $orgId = $toStayId !== $fromStayId && function_exists('supplier_property_org')
                ? supplier_property_org($db, $toStayId) : (int) ($row['org_id'] ?? 0);
            $upd = ['stay_id' => $toStayId, 'updated_at' => date('Y-m-d H:i:s')];
            if ($toLocation !== '') { $upd['location'] = substr($toLocation, 0, 120); }
            if ($orgId > 0) { $upd['org_id'] = $orgId; }
            $db->update('stays_assets', $upd, ['id' => $id, 'stay_id' => $fromStayId]);
            $detail = 'Transferred'
                . ($toStayId !== $fromStayId ? ' to property #' . $toStayId : '')
                . ($toLocation !== '' ? ' @ ' . $toLocation : '')
                . ($fromLoc !== '' ? ' (from ' . $fromLoc . ')' : '');
            _asset_log_event($db, $id, $toStayId, 'transfer', $detail);
            return true;
        } catch (\Throwable $e) { error_log('asset_transfer: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// REPAIR / SERVICE HISTORY — append-only events (also used by warranty/transfer/disposal).
// NOTE: the repair COST captured here is a maintenance-record figure only; it is NOT
// posted to any folio or ledger. Procurement/maintenance modules own real spend.
// ---------------------------------------------------------------------------
if (!function_exists('asset_event_add')) {
    function asset_event_add($db, int $id, int $stayId, string $type, string $detail, ?float $cost = null): int
    {
        if (_asset_owned_row($db, $id, $stayId) === null) { return 0; }
        return _asset_log_event($db, $id, $stayId, $type, $detail, $cost);
    }
}

if (!function_exists('asset_events')) {
    /** History timeline for one asset. Read-only. */
    function asset_events($db, int $id, int $stayId, int $limit = 200): array
    {
        if ($id <= 0 || $stayId <= 0) { return []; }
        try {
            return $db->select('stays_asset_events', '*',
                ['AND' => ['asset_id' => $id, 'stay_id' => $stayId], 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('asset_events: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// DISPOSAL — retire-with-finality. Sets condition_status='disposed' and records how/why.
// The disposal proceeds figure is a RECORD ONLY (no GL/folio posting — DB-GATED note).
// ---------------------------------------------------------------------------
if (!function_exists('asset_dispose')) {
    function asset_dispose($db, int $id, int $stayId, array $d): bool
    {
        $row = _asset_owned_row($db, $id, $stayId);
        if ($row === null) { return false; }
        if (($row['condition_status'] ?? '') === 'disposed') { return false; }
        $method = isset(asset_disposal_methods()[$d['method'] ?? '']) ? $d['method'] : 'scrapped';
        $dd = trim((string) ($d['disposal_date'] ?? ''));
        $proceeds = ($d['proceeds'] ?? '') !== '' ? max(0.0, round((float) $d['proceeds'], 2)) : null;
        try {
            $db->update('stays_assets', [
                'condition_status' => 'disposed',
                'disposal_method'  => $method,
                'disposal_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) ? $dd : date('Y-m-d'),
                'disposal_proceeds'=> $proceeds,
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'stay_id' => $stayId]);
            $detail = 'Disposed (' . (asset_disposal_methods()[$method]) . ')'
                . ($proceeds !== null ? ', proceeds ' . number_format($proceeds, 2) : '')
                . (trim((string) ($d['reason'] ?? '')) !== '' ? ' — ' . trim((string) $d['reason']) : '');
            _asset_log_event($db, $id, $stayId, 'disposal', $detail, $proceeds);
            return true;
        } catch (\Throwable $e) { error_log('asset_dispose: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// ASSET REPORTS — register summary across the owner's stays. Pure aggregation over
// stays_assets (+ persisted depreciation). No money spine touched.
// Returns:
//   ['count_total','count_active','count_disposed','by_category'=>[cat=>[count,cost,book]],
//    'by_property'=>[stayId=>[count,cost,book]],
//    'total_cost','total_book','warranty_expiring'=>[rows within 60d]]
// ---------------------------------------------------------------------------
if (!function_exists('asset_report')) {
    function asset_report($db, array $stayIds): array
    {
        $out = [
            'count_total' => 0, 'count_active' => 0, 'count_disposed' => 0,
            'by_category' => [], 'by_property' => [],
            'total_cost' => 0.0, 'total_book' => 0.0, 'warranty_expiring' => [],
        ];
        if (empty($stayIds)) { return $out; }
        try {
            $rows = $db->select('stays_assets', '*', ['stay_id' => $stayIds, 'LIMIT' => 5000]) ?: [];
        } catch (\Throwable $e) { error_log('asset_report: ' . $e->getMessage()); return $out; }
        $today = new \DateTimeImmutable('today');
        $soon = $today->modify('+60 days')->format('Y-m-d');
        foreach ($rows as $r) {
            $out['count_total']++;
            $disposed = (($r['condition_status'] ?? '') === 'disposed');
            if ($disposed) { $out['count_disposed']++; } else { $out['count_active']++; }
            $cost = (float) ($r['purchase_cost'] ?? 0);
            $book = $disposed ? 0.0 : asset_book_value($db, $r);
            $out['total_cost'] += $cost;
            $out['total_book'] += $book;
            $cat = trim((string) ($r['category'] ?? '')) ?: 'Uncategorised';
            if (!isset($out['by_category'][$cat])) { $out['by_category'][$cat] = ['count' => 0, 'cost' => 0.0, 'book' => 0.0]; }
            $out['by_category'][$cat]['count']++;
            $out['by_category'][$cat]['cost'] += $cost;
            $out['by_category'][$cat]['book'] += $book;
            $sid = (int) ($r['stay_id'] ?? 0);
            if (!isset($out['by_property'][$sid])) { $out['by_property'][$sid] = ['count' => 0, 'cost' => 0.0, 'book' => 0.0]; }
            $out['by_property'][$sid]['count']++;
            $out['by_property'][$sid]['cost'] += $cost;
            $out['by_property'][$sid]['book'] += $book;
            $we = (string) ($r['warranty_expiry'] ?? '');
            if (!$disposed && preg_match('/^\d{4}-\d{2}-\d{2}$/', $we) && $we >= $today->format('Y-m-d') && $we <= $soon) {
                $out['warranty_expiring'][] = $r;
            }
        }
        $out['total_cost'] = round($out['total_cost'], 2);
        $out['total_book'] = round($out['total_book'], 2);
        foreach ($out['by_category'] as &$c) { $c['cost'] = round($c['cost'], 2); $c['book'] = round($c['book'], 2); }
        unset($c);
        foreach ($out['by_property'] as &$p) { $p['cost'] = round($p['cost'], 2); $p['book'] = round($p['book'], 2); }
        unset($p);
        return $out;
    }
}
