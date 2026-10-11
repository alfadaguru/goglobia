<?php
// FILE: app/routes/users/supplierMigrationExtRoutes.php
// DATA MIGRATION & ONBOARDING TOOLS — DEEPENING routes (PMS #60). CSV importers
// (rooms / guests / rate / inventory / opening balances) with a strict
// PREVIEW → COMMIT flow, plus a go-live readiness report. Lives alongside the
// stays/finance supplier screens and reuses their auth/owner/org helpers.
//
// Scope model:
//   - property-scoped imports (rooms/rate/inventory) require a stay the acting
//     supplier owns; supplier_can($db,'hotels'/'rooms','edit',$stayId) gates each
//     write and double-checks ownership.
//   - org-scoped imports (guests/opening_balances) are business-wide; opening
//     balances are money, so they are OWNER-ONLY and gated on 'finance','edit'.
// Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can. No client
// amount is trusted: rows are re-validated server-side on commit, availability
// goes through stays_inventory_set, money through gl_post (balanced).
//
// The two-phase flow holds the VALIDATED raw CSV in the session between preview
// and commit (keyed by a nonce) so the operator confirms exactly what they saw;
// commit RE-PARSES and RE-VALIDATES from that stored text (never a client echo).

@$SECURE or die('Access Denied!');

if (!function_exists('_mig_ext_ctx')) {
    /**
     * Resolve the acting supplier context for the migration screens, or redirect.
     * Returns ['owner'=>string,'actor'=>string,'org'=>int,'is_owner'=>bool].
     * Admins are bounced to their own screens (this area is owner-centric).
     */
    function _mig_ext_ctx($db): array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'actor' => (string) ($ctx['actor'] ?? $owner), 'org' => (int) $org, 'is_owner' => !empty($ctx['is_owner'])];
    }
}

if (!function_exists('_mig_ext_owned_stay')) {
    /**
     * Validate that $stayId is a property the acting supplier owns; returns the
     * stay row (id,name) or null. Defense-in-depth behind supplier_can.
     */
    function _mig_ext_owned_stay($db, string $owner, int $stayId): ?array
    {
        if ($stayId <= 0 || $owner === '') { return null; }
        try {
            $row = $db->get('stays', ['id', 'name'], ['id' => $stayId, 'user_id' => $owner]);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('_mig_ext_owned_stay: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('_mig_ext_owned_stays')) {
    /** The acting supplier's own properties (id,name), for the picker. [] on error. */
    function _mig_ext_owned_stays($db, string $owner): array
    {
        if ($owner === '') { return []; }
        try {
            return $db->select('stays', ['id', 'name'], ['user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('_mig_ext_owned_stays: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================================
// GET /supplier/migration — dashboard: importers, go-live report, history
// ============================================================================
$router->get('/supplier/migration', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $cx = _mig_ext_ctx($db);

    $stays = _mig_ext_owned_stays($db, $cx['owner']);
    // Selected property for property-scoped reports (defaults to first owned).
    $stayId = isset($_GET['stay']) ? (int) $_GET['stay'] : (int) ($stays[0]['id'] ?? 0);
    if ($stayId > 0 && _mig_ext_owned_stay($db, $cx['owner'], $stayId) === null) { $stayId = (int) ($stays[0]['id'] ?? 0); }

    $kinds = mig_import_kinds();
    $goLive = ($stayId > 0 && function_exists('mig_go_live_report'))
        ? mig_go_live_report($db, $stayId, $cx['org'], $cx['owner'])
        : null;
    $batches = function_exists('mig_recent_batches') ? mig_recent_batches($db, $cx['org'], 20) : [];
    $connectors = function_exists('mig_connector_status') ? mig_connector_status() : [];

    // A just-computed preview carried across the POST→GET redirect (one-shot).
    $preview = null; $previewNonce = null;
    if (!empty($_SESSION['mig_preview']) && is_array($_SESSION['mig_preview'])) {
        $preview = $_SESSION['mig_preview']['preview'] ?? null;
        $previewNonce = $_SESSION['mig_preview']['nonce'] ?? null;
    }

    $title = 'Data migration'; $description = 'Import your data and go live';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/migration-ext.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// GET /supplier/migration/template/{kind} — download a CSV template
// ============================================================================
$router->get('/supplier/migration/template/([a-z_]+)', function ($kind) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    _mig_ext_ctx($db); // ensure a real supplier, no client scope needed for a template
    $kind = (string) $kind;
    $csv = function_exists('mig_template_csv') ? mig_template_csv($kind) : '';
    if ($csv === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Unknown import type.'];
        header('Location: ' . root . 'supplier/migration'); exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_' . $kind . '_template.csv"');
    header('X-Content-Type-Options: nosniff');
    echo $csv;
    exit;
});

// ============================================================================
// POST /supplier/migration/preview — parse + validate (NO writes)
// ============================================================================
$router->post('/supplier/migration/preview', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $cx = _mig_ext_ctx($db);

    $kind = (string) ($_POST['kind'] ?? '');
    $meta = function_exists('mig_import_kind_meta') ? mig_import_kind_meta($kind) : null;
    $back = root . 'supplier/migration';
    if ($meta === null) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Unknown import type.']; header('Location: ' . $back); exit; }

    // Authorise the write this preview is for (so we never preview what we couldn't commit).
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    if ($meta['scope'] === 'property') {
        if (_mig_ext_owned_stay($db, $cx['owner'], $stayId) === null || !supplier_can($db, 'hotels', 'edit', $stayId)) {
            _supplier_stays_deny('Pick one of your own properties for this import.');
        }
    } else {
        $action = ($kind === 'opening_balances') ? 'finance' : 'hotels';
        if ($kind === 'opening_balances' && empty($cx['is_owner'])) { _supplier_stays_deny('Only the account owner can import opening balances.'); }
        if (!supplier_can($db, $action, 'edit')) { _supplier_stays_deny('Not authorised for this import.'); }
    }

    // Read the uploaded file (bounded). Accept only a real upload.
    if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Please choose a CSV file to upload.'];
        header('Location: ' . $back . '?stay=' . $stayId); exit;
    }
    if ((int) ($_FILES['csv']['size'] ?? 0) > 2 * 1024 * 1024) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'File too large (max 2 MB).'];
        header('Location: ' . $back . '?stay=' . $stayId); exit;
    }
    $raw = @file_get_contents($_FILES['csv']['tmp_name']);
    if ($raw === false || $raw === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not read the uploaded file.'];
        header('Location: ' . $back . '?stay=' . $stayId); exit;
    }

    $parsed = mig_parse_csv($raw);
    $ctx = ['org' => $cx['org'], 'stay_id' => $stayId, 'actor' => $cx['actor']];
    $preview = mig_import_preview($db, $kind, $parsed, $ctx);
    if (empty($preview['ok'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $preview['message'] ?? 'Could not validate the file.'];
        header('Location: ' . $back . '?stay=' . $stayId); exit;
    }

    // Stash the VALIDATED raw text for a confirmed commit (one-shot, nonce-bound).
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['mig_preview'] = [
        'nonce'   => $nonce,
        'kind'    => $kind,
        'stay_id' => $stayId,
        'raw'     => $raw,
        'preview' => $preview,
        'ts'      => time(),
    ];
    header('Location: ' . $back . '?stay=' . $stayId); exit;
});

// ============================================================================
// POST /supplier/migration/commit — write the previously-previewed VALID rows
// ============================================================================
$router->post('/supplier/migration/commit', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $cx = _mig_ext_ctx($db);
    $back = root . 'supplier/migration';

    $stash = $_SESSION['mig_preview'] ?? null;
    $nonce = (string) ($_POST['nonce'] ?? '');
    if (!is_array($stash) || !hash_equals((string) ($stash['nonce'] ?? ''), $nonce) || $nonce === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This import session expired. Please upload and preview again.'];
        unset($_SESSION['mig_preview']);
        header('Location: ' . $back); exit;
    }
    // Expire stale previews (15 min).
    if ((time() - (int) ($stash['ts'] ?? 0)) > 900) {
        unset($_SESSION['mig_preview']);
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This import preview expired. Please preview again.'];
        header('Location: ' . $back); exit;
    }

    $kind = (string) $stash['kind'];
    $stayId = (int) $stash['stay_id'];
    $meta = mig_import_kind_meta($kind);
    if ($meta === null) { unset($_SESSION['mig_preview']); header('Location: ' . $back); exit; }

    // RE-AUTHORISE at commit (session role could have changed since preview).
    if ($meta['scope'] === 'property') {
        if (_mig_ext_owned_stay($db, $cx['owner'], $stayId) === null || !supplier_can($db, 'hotels', 'edit', $stayId)) {
            unset($_SESSION['mig_preview']);
            _supplier_stays_deny('Not authorised to import to this property.');
        }
    } else {
        if ($kind === 'opening_balances') {
            if (empty($cx['is_owner']) || !supplier_can($db, 'finance', 'edit')) {
                unset($_SESSION['mig_preview']);
                _supplier_stays_deny('Only the account owner can import opening balances.');
            }
        } elseif (!supplier_can($db, 'hotels', 'edit')) {
            unset($_SESSION['mig_preview']);
            _supplier_stays_deny('Not authorised for this import.');
        }
    }

    // RE-PARSE + RE-VALIDATE from the stored text — never trust a client echo.
    $parsed = mig_parse_csv((string) $stash['raw']);
    $ctx = [
        'org'          => $cx['org'],
        'stay_id'      => $stayId,
        'actor'        => $cx['actor'],
        'opening_date' => (string) ($_POST['opening_date'] ?? date('Y-m-d')),
    ];
    $res = mig_import_commit($db, $kind, $parsed, $ctx);

    unset($_SESSION['mig_preview']); // single-use
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Import finished.'];
    header('Location: ' . $back . ($stayId > 0 ? '?stay=' . $stayId : '')); exit;
});

// ============================================================================
// POST /supplier/migration/discard — drop a pending preview without committing
// ============================================================================
$router->post('/supplier/migration/discard', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    _mig_ext_ctx($db);
    unset($_SESSION['mig_preview']);
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Preview discarded.'];
    header('Location: ' . root . 'supplier/migration'); exit;
});
