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
    require_once views."includes/header.php";
    require_once views."agent/clients.php";
    require_once views."includes/footer.php";
});

// ---- GET /agent/clients/new  &  /agent/clients/{id}/edit --------------------
$router->get('/agent/clients/new', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'add')) { _agent_clients_deny('You cannot add clients.'); }
    $client = null;
    $title = 'Add client';
    require_once views."includes/header.php";
    require_once views."agent/client-form.php";
    require_once views."includes/footer.php";
});

$router->get('/agent/clients/([0-9]+)/edit', function ($id) use ($SECURE, $db) {
    [$ctx, $owner] = _agent_clients_ctx($db);
    if (!agent_can($db, 'clients', 'edit')) { _agent_clients_deny('You cannot edit clients.'); }
    $client = $db->get('agent_clients', '*', ['id' => (int)$id, 'owner_user_id' => $owner]);
    if (!$client) { _agent_clients_deny('Client not found.'); }
    $title = 'Edit client';
    require_once views."includes/header.php";
    require_once views."agent/client-form.php";
    require_once views."includes/footer.php";
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
