<?php
// ============================================================================
// app/routes/users/agentClientsRoutes.php — Agent ERP P6a: client book / CRM.
// The agency's own customers — does not exist elsewhere in the platform.
//   GET  /agent/clients            → list + search
//   GET  /agent/clients/new        → add form
//   GET  /agent/clients/{id}/edit  → edit form
//   POST /agent/clients/save       → create/update (owner-scoped)
//   POST /agent/clients/delete     → delete (owner-scoped)
//   GET  /agent/clients/lookup     → JSON (for "book for existing client" prefill)
// All owner-scoped via agent_acting_context; gated by agent_can('clients', …).
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_agent_clients_ctx')) {
    function _agent_clients_ctx($db): array
    {
        AGENT_OR_STAFF_AUTH($db);
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        return [$ctx, (string) $ctx['owner']];
    }
}
if (!function_exists('_agent_clients_deny')) {
    function _agent_clients_deny(string $msg = 'You do not have access to clients.'): void
    {
        $_SESSION['message'] = ['type'=>'error','text'=>$msg];
        header('Location: ' . root . 'agent/clients'); exit;
    }
}

// ---- GET /agent/clients -----------------------------------------------------
$router->get('/agent/clients', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'view')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to clients.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 300];
    if ($q !== '') {
        $where['OR'] = [
            'first_name[~]' => $q, 'last_name[~]' => $q, 'email[~]' => $q, 'phone[~]' => $q, 'tags[~]' => $q,
        ];
    }
    $clients = [];
    try {
        $clients = $db->select('agent_clients',
            ['id','title','first_name','last_name','email','phone','nationality','tags','created_at'],
            $where) ?: [];
    } catch (\Throwable $e) { error_log('agent/clients list: ' . $e->getMessage()); }
    $canEdit = agent_can($db, 'clients', 'add') || agent_can($db, 'clients', 'edit');

    $title = 'Clients';
    require_once views."agent/layout-top.php";
    require_once views."agent/clients.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/clients/new  &  /agent/clients/{id}/edit --------------------
$router->get('/agent/clients/new', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'add')) { _agent_clients_deny('You cannot add clients.'); }
    $client = null;
    $canPriceClient = !empty($ctx['is_owner']); // AG-G4b: owner-only per-client markup
    $title = 'Add client';
    require_once views."agent/layout-top.php";
    require_once views."agent/client-form.php";
    require_once views."agent/layout-bottom.php";
});

$router->get('/agent/clients/([0-9]+)/edit', function ($id) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'edit')) { _agent_clients_deny('You cannot edit clients.'); }
    $client = $db->get('agent_clients', '*', ['id' => (int)$id, 'owner_user_id' => $owner]);
    if (!$client) { _agent_clients_deny('Client not found.'); }
    $canPriceClient = !empty($ctx['is_owner']); // AG-G4b: owner-only per-client markup
    $title = 'Edit client';
    require_once views."agent/layout-top.php";
    require_once views."agent/client-form.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/clients/{id}  (detail: profile + booking history + documents).
// Numeric-only pattern, so it never shadows /new or /lookup. Registered AFTER
// /new and /edit above; the router anchors the full path so /{id} and /{id}/edit
// are distinct matches. ------------------------------------------------------
$router->get('/agent/clients/([0-9]+)', function ($id) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'view')) { _agent_clients_deny(); }
    $client = $db->get('agent_clients', '*', ['id' => (int)$id, 'owner_user_id' => $owner]);
    if (!$client) { _agent_clients_deny('Client not found.'); }

    // BOOKING HISTORY — reuse the EXISTING attribution: bookings.agent_client_id is
    // stamped at agent settlement (resolved from the CRM by email). Scope to THIS
    // agency AND this client (defence in depth; agent_client_id alone is agency-unique
    // but we assert agency scope too). Newest first.
    $bookings = [];
    try {
        $bookings = $db->select('bookings',
            ['invoice_id','module_type','price_markup','agent_client_price','agent_client_margin',
             'currency_markup','payment_status','booking_status','paid_at','created_at'],
            ['agent_client_id' => (int)$id,
             'OR #scope' => ['agency_id' => $owner, 'user_id' => $owner],
             'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]) ?: [];
    } catch (\Throwable $e) { error_log('agent client history '.$id.': '.$e->getMessage()); }

    // Lifetime value: sum what the client paid (client price) + the agency margin,
    // over PAID bookings only (the honest basis — mirrors the reports page).
    $ltvClient = 0.0; $ltvMargin = 0.0; $paidCount = 0;
    foreach ($bookings as $b) {
        if (($b['payment_status'] ?? '') !== 'paid') { continue; }
        $cost = (float)($b['price_markup'] ?? 0);
        $ltvClient += (float)($b['agent_client_price'] ?? 0) ?: $cost;
        $ltvMargin += (float)($b['agent_client_margin'] ?? 0);
        $paidCount++;
    }

    // DOCUMENTS for this client (scoped to the agency).
    $documents = [];
    try {
        $documents = $db->select('agent_client_documents',
            ['id','doc_type','title','original_name','mime','size_bytes','created_at'],
            ['client_id' => (int)$id, 'owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
    } catch (\Throwable $e) { error_log('agent client docs '.$id.': '.$e->getMessage()); }

    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $canEdit   = agent_can($db, 'clients', 'edit');
    $canUpload = agent_can($db, 'clients', 'edit') || agent_can($db, 'clients', 'add');
    $csrf = CSRF::getToken();

    $title = trim(($client['first_name'] ?? '').' '.($client['last_name'] ?? '')) ?: 'Client';
    require_once views."agent/layout-top.php";
    require_once views."agent/client-detail.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/clients/save ----------------------------------------------
$router->post('/agent/clients/save', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $need = $id > 0 ? 'edit' : 'add';
    if (!agent_can($db, 'clients', $need)) { _agent_clients_deny('You do not have permission.'); }

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { _agent_clients_deny('Please enter a valid email.'); }
    $first = trim((string) ($_POST['first_name'] ?? ''));
    $last  = trim((string) ($_POST['last_name'] ?? ''));
    if ($first === '' && $last === '') { _agent_clients_deny('A client needs at least a name.'); }

    // Normalise optional dates to NULL when blank (DATE columns reject '').
    $dob = trim((string)($_POST['dob'] ?? '')); $dob = $dob !== '' ? $dob : null;
    $pexp = trim((string)($_POST['passport_expiry'] ?? '')); $pexp = $pexp !== '' ? $pexp : null;

    $data = [
        'title'              => substr(trim((string)($_POST['title'] ?? '')), 0, 16),
        'first_name'         => substr($first, 0, 120),
        'last_name'          => substr($last, 0, 120),
        'email'              => substr($email, 0, 255),
        'phone'              => substr(trim((string)($_POST['phone'] ?? '')), 0, 40),
        'phone_country_code' => substr(trim((string)($_POST['phone_country_code'] ?? '')), 0, 12),
        'dob'                => $dob,
        'nationality'        => substr(strtoupper(trim((string)($_POST['nationality'] ?? ''))), 0, 2) ?: null,
        'passport_number'    => substr(trim((string)($_POST['passport_number'] ?? '')), 0, 60),
        'passport_expiry'    => $pexp,
        'tags'               => substr(trim((string)($_POST['tags'] ?? '')), 0, 255),
        'notes'              => trim((string)($_POST['notes'] ?? '')),
        'updated_at'         => date('Y-m-d H:i:s'),
    ];

    // AG-G4b: per-client markup override — OWNER only (pricing is an owner lever,
    // mirroring branding self-markup). A blank value clears the override (falls back
    // to per-service/default). Clamped: >=0, and <=100 for a percentage.
    if (!empty($ctx['is_owner']) && array_key_exists('markup_value', $_POST)) {
        $mvRaw = trim((string) ($_POST['markup_value'] ?? ''));
        if ($mvRaw === '') {
            $data['markup_value'] = null;
        } else {
            $mv = max(0.0, (float) $mvRaw);
            $mt = (($_POST['markup_type'] ?? 'percentage') === 'fixed') ? 'fixed' : 'percentage';
            if ($mt === 'percentage' && $mv > 100) { $mv = 100; }
            $data['markup_value'] = $mv;
            $data['markup_type']  = $mt;
        }
    }
    try {
        if ($id > 0) {
            $own = $db->get('agent_clients', ['id'], ['id' => $id, 'owner_user_id' => $owner]);
            if (!$own) { _agent_clients_deny('Client not found.'); }
            $db->update('agent_clients', $data, ['id' => $id, 'owner_user_id' => $owner]);
            $_SESSION['message'] = ['type'=>'success','text'=>'Client updated.'];
        } else {
            $data['owner_user_id'] = $owner;
            $data['created_by']    = (string) $ctx['actor'];
            $data['created_at']    = date('Y-m-d H:i:s');
            $db->insert('agent_clients', $data);
            $_SESSION['message'] = ['type'=>'success','text'=>'Client added.'];
        }
    } catch (\Throwable $e) { error_log('agent clients save: ' . $e->getMessage()); _agent_clients_deny('Could not save the client.'); }
    header('Location: ' . root . 'agent/clients');
    exit;
});

// ---- POST /agent/clients/delete --------------------------------------------
$router->post('/agent/clients/delete', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'clients', 'delete')) { _agent_clients_deny('You cannot delete clients.'); }
    $id = (int) ($_POST['id'] ?? 0);
    $own = $id ? $db->get('agent_clients', ['id'], ['id' => $id, 'owner_user_id' => $owner]) : null;
    if (!$own) { _agent_clients_deny('Client not found.'); }
    try { $db->delete('agent_clients', ['id' => $id, 'owner_user_id' => $owner]);
          $_SESSION['message'] = ['type'=>'success','text'=>'Client deleted.']; }
    catch (\Throwable $e) { error_log('agent clients delete: ' . $e->getMessage()); _agent_clients_deny('Could not delete the client.'); }
    header('Location: ' . root . 'agent/clients');
    exit;
});

// ============================================================================
// GAP-2: CLIENT DOCUMENTS — upload / download / delete. Files are stored under a
// .htaccess-denied folder (uploads/agent/clients/{owner}/{client}) and served
// ONLY through the authed download route — never a public URL. Upload mirrors the
// proven umrah document validator (finfo MIME allow-list jpg/png/webp/pdf, size
// cap, embedded-PHP reject, MIME-derived extension). Gated clients.edit|add.
// ============================================================================
$router->post('/agent/clients/([0-9]+)/documents/upload', function ($id) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    CSRF::guard();
    if (!(agent_can($db, 'clients', 'edit') || agent_can($db, 'clients', 'add'))) { _agent_clients_deny('You cannot upload client documents.'); }
    $back = root . 'agent/clients/' . (int)$id;
    $client = $db->get('agent_clients', ['id'], ['id' => (int)$id, 'owner_user_id' => $owner]);
    if (!$client) { _agent_clients_deny('Client not found.'); }

    if (!isset($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Please choose a file to upload.']; header('Location: '.$back); exit;
    }
    $file = $_FILES['document'];
    if (!function_exists('finfo_open')) { $_SESSION['message']=['type'=>'error','text'=>'Server fileinfo missing.']; header('Location: '.$back); exit; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
    if (!isset($allowed[$mime])) { $_SESSION['message']=['type'=>'error','text'=>'Only JPG, PNG, WEBP or PDF files are allowed.']; header('Location: '.$back); exit; }
    $maxBytes = 8 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) { $_SESSION['message']=['type'=>'error','text'=>'File too large (max 8MB).']; header('Location: '.$back); exit; }
    // Embedded-PHP guard (skip for PDF, which legitimately contains binary).
    if ($mime !== 'application/pdf') {
        $head = (string) file_get_contents($file['tmp_name']);
        if (preg_match('/<\?(php|=)?\s/i', $head) || preg_match('/<script[^>]*language\s*=\s*["\']?php/i', $head)) {
            $_SESSION['message']=['type'=>'error','text'=>'Invalid file content.']; header('Location: '.$back); exit;
        }
    }
    $uploadsBase = defined('uploads') ? rtrim(uploads, '/') : dirname(__DIR__, 3) . '/uploads';
    // Folder keyed by owner+client. .htaccess at the agent/clients root denies web access.
    $dir = $uploadsBase . '/agent/clients/' . preg_replace('/[^A-Za-z0-9_-]/','', $owner) . '/' . (int)$id;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $_SESSION['message']=['type'=>'error','text'=>'Storage unavailable.']; header('Location: '.$back); exit;
    }
    $ht = $uploadsBase . '/agent/clients/.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }

    $docType = substr(trim((string)($_POST['doc_type'] ?? 'other')), 0, 48) ?: 'other';
    $validTypes = ['passport','id_card','visa','ticket','insurance','photo','other'];
    if (!in_array($docType, $validTypes, true)) { $docType = 'other'; }
    $title = substr(trim((string)($_POST['title'] ?? '')), 0, 191);
    $origName = substr((string)($file['name'] ?? ''), 0, 255);

    $fname = 'doc_' . (int)$id . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $fname;
    if (!@move_uploaded_file($file['tmp_name'], $dest) && !@rename($file['tmp_name'], $dest)) {
        $_SESSION['message']=['type'=>'error','text'=>'Could not store the file.']; header('Location: '.$back); exit;
    }
    $rel = 'uploads/agent/clients/' . preg_replace('/[^A-Za-z0-9_-]/','', $owner) . '/' . (int)$id . '/' . $fname;
    try {
        $db->insert('agent_client_documents', [
            'owner_user_id' => $owner, 'client_id' => (int)$id, 'doc_type' => $docType,
            'title' => $title !== '' ? $title : null, 'file_path' => $rel,
            'original_name' => $origName !== '' ? $origName : null, 'mime' => $mime,
            'size_bytes' => (int)$file['size'], 'uploaded_by' => (string)$ctx['actor'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'client.doc.upload', $docType.' #'.(int)$id); }
        $_SESSION['message'] = ['type'=>'success','text'=>'Document uploaded.'];
    } catch (\Throwable $e) {
        error_log('agent client doc insert: '.$e->getMessage());
        @unlink($dest); // don't leave an orphan file if the row failed
        $_SESSION['message'] = ['type'=>'error','text'=>'Could not save the document.'];
    }
    header('Location: '.$back); exit;
});

// Authed download — streams the file ONLY to the owning agency. Never a public URL.
$router->get('/agent/clients/([0-9]+)/documents/([0-9]+)/download', function ($id, $docId) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'view')) { _agent_clients_deny(); }
    $doc = $db->get('agent_client_documents', ['file_path','original_name','mime'],
        ['id' => (int)$docId, 'client_id' => (int)$id, 'owner_user_id' => $owner]);
    if (!$doc) { _agent_clients_deny('Document not found.'); }
    $uploadsBase = defined('uploads') ? rtrim(uploads, '/') : dirname(__DIR__, 3) . '/uploads';
    // Resolve + contain: the stored path is relative; realpath MUST stay inside uploads.
    $abs = $uploadsBase . '/' . preg_replace('#^uploads/#', '', (string)$doc['file_path']);
    $real = realpath($abs);
    $baseReal = realpath($uploadsBase);
    if (!$real || !$baseReal || strpos($real, $baseReal) !== 0 || !is_file($real)) { _agent_clients_deny('File unavailable.'); }
    $name = (string)($doc['original_name'] ?? '') ?: basename($real);
    header('Content-Type: ' . ((string)($doc['mime'] ?? '') ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/','_',$name) . '"');
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
});

$router->post('/agent/clients/documents/delete', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    CSRF::guard();
    if (!agent_can($db, 'clients', 'edit')) { _agent_clients_deny('You cannot delete client documents.'); }
    $docId = (int)($_POST['doc_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $back = root . 'agent/clients/' . $clientId;
    $doc = $db->get('agent_client_documents', ['id','file_path'], ['id' => $docId, 'owner_user_id' => $owner]);
    if (!$doc) { $_SESSION['message']=['type'=>'error','text'=>'Document not found.']; header('Location: '.$back); exit; }
    try {
        $db->delete('agent_client_documents', ['id' => $docId, 'owner_user_id' => $owner]);
        // Remove the file too (best-effort, contained to uploads).
        $uploadsBase = defined('uploads') ? rtrim(uploads, '/') : dirname(__DIR__, 3) . '/uploads';
        $abs = $uploadsBase . '/' . preg_replace('#^uploads/#', '', (string)$doc['file_path']);
        $real = realpath($abs); $baseReal = realpath($uploadsBase);
        if ($real && $baseReal && strpos($real, $baseReal) === 0 && is_file($real)) { @unlink($real); }
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'client.doc.delete', (string)$docId); }
        $_SESSION['message'] = ['type'=>'success','text'=>'Document removed.'];
    } catch (\Throwable $e) { error_log('agent client doc delete: '.$e->getMessage()); $_SESSION['message']=['type'=>'error','text'=>'Could not delete the document.']; }
    header('Location: '.$back); exit;
});

// ---- GET /agent/clients/lookup  (JSON prefill for Booking Centre) ----------
$router->get('/agent/clients/lookup', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    header('Content-Type: application/json');
    if (!agent_can($db, 'clients', 'view')) { echo json_encode(['ok'=>false,'clients'=>[]]); exit; }
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ['owner_user_id' => $owner, 'ORDER' => ['id'=>'DESC'], 'LIMIT' => 20];
    if ($q !== '') { $where['OR'] = ['first_name[~]'=>$q,'last_name[~]'=>$q,'email[~]'=>$q,'phone[~]'=>$q]; }
    $rows = [];
    try {
        $rows = $db->select('agent_clients',
            ['id','title','first_name','last_name','email','phone','phone_country_code','dob','nationality','passport_number','passport_expiry'],
            $where) ?: [];
    } catch (\Throwable $e) { error_log('agent clients lookup: ' . $e->getMessage()); }
    echo json_encode(['ok'=>true,'clients'=>$rows], JSON_UNESCAPED_SLASHES);
    exit;
});
