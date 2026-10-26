<?php
// app/routes/admin/supportDeskRoutes.php
// Customer Support module — LIVE CHAT DESK routes. The multi-channel staff
// takeover inbox for live GIA conversations (web widget + WhatsApp + Telegram +
// Messenger). Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md.
//
// The desk ENGINE (schema + queue/messages/reply/takeover helpers) lives in
// app/lib/support/livedesk.php — this file is the thin HTTP layer over it.
// rbac.php (support_can / support_current_staff) is loaded in config.php;
// livedesk.php is NOT, so every handler require_once's it then calls
// ensureSupportDeskSchema($db) at the top (self-healing, idempotent).
//
// Shape mirrors app/routes/admin/giaRoutes.php: ADMIN_AUTH() first, then a
// admin_can() capability gate, CSRF::guard() on the JSON POSTs. JSON denials
// return a 403 {status:false} body (so the desk UI can surface them) rather
// than die('Access Denied!').
@$SECURE or die('Access Denied!');

// ================================ DESK PAGE (header/page/footer triple)
// cap chat.view — anyone who may watch live chats can open the desk.
$router->get(admin.'/support/desk', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/support/livedesk.php';
    ensureSupportDeskSchema($db);
    if (!admin_can($db, 'support_desk', 'view')) { http_response_code(403); die('Access Denied!'); }

    $title = 'Live Desk'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/desk.php";
    require_once views."includes/footer.php";
});

// ================================ QUEUE (JSON) — conversation list for a filter.
// cap chat.view. filter: all | needs_human | assigned(mine) | unassigned |
// channel:<web|whatsapp|telegram|messenger>. staffId scopes the 'assigned' filter.
$router->get(admin.'/support/desk/queue', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/support/livedesk.php';
    ensureSupportDeskSchema($db);
    header('Content-Type: application/json');
    if (!admin_can($db, 'support_desk', 'view')) { http_response_code(403); echo json_encode(['status' => false, 'message' => 'Forbidden']); return; }

    $staffId = admin_staff_id($db);
    $filter  = (string) ($_GET['filter'] ?? 'all');

    $rows = support_desk_queue($db, $filter, 50, $staffId);
    echo json_encode(['status' => true, 'conversations' => $rows]);
});

// ================================ MESSAGES (JSON) — transcript for one conversation.
// cap chat.view. since = incremental poll cursor (id>since). Also returns the
// conversation's handoff state so the UI can reflect take/release + assignment.
$router->get(admin.'/support/desk/messages', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/support/livedesk.php';
    ensureSupportDeskSchema($db);
    header('Content-Type: application/json');
    if (!admin_can($db, 'support_desk', 'view')) { http_response_code(403); echo json_encode(['status' => false, 'message' => 'Forbidden']); return; }

    $id    = (int) ($_GET['id'] ?? 0);
    $since = (int) ($_GET['since'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => false, 'message' => 'Missing conversation id.']); return; }

    $messages = support_desk_messages($db, $id, $since);

    // Surface the live handoff state so the desk UI reflects who owns the chat.
    $conv = $db->get('gia_conversations', [
        'staff_active', 'needs_human', 'assigned_staff_id', 'channel',
    ], ['id' => $id]);

    echo json_encode([
        'status'            => true,
        'messages'          => $messages,
        'staff_active'      => (int) ($conv['staff_active'] ?? 0),
        'needs_human'       => (int) ($conv['needs_human'] ?? 0),
        // assigned_staff_id is a VARCHAR users.user_id (NOT an int) — ship it as a
        // string (or null) so an ownership check against admin_staff_id() matches.
        'assigned_staff_id' => (isset($conv['assigned_staff_id']) && $conv['assigned_staff_id'] !== '')
            ? (string) $conv['assigned_staff_id'] : null,
        'channel'           => (string) ($conv['channel'] ?? 'web'),
    ]);
});

// ================================ REPLY (JSON POST) — staff message out to the
// traveller on their actual channel. cap chat.takeover. CSRF::guard() for JSON.
$router->post(admin.'/support/desk/reply', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    require_once dirname(__DIR__, 2) . '/lib/support/livedesk.php';
    ensureSupportDeskSchema($db);
    header('Content-Type: application/json');
    if (!admin_can($db, 'support_desk', 'edit')) { http_response_code(403); echo json_encode(['status' => false, 'message' => 'Forbidden']); return; }

    $staffId = admin_staff_id($db);
    $id      = (int) ($_POST['id'] ?? 0);
    $text    = trim((string) ($_POST['text'] ?? ''));
    if ($id <= 0 || $text === '') { echo json_encode(['status' => false, 'message' => 'Missing message.']); return; }

    $res = support_desk_reply($db, $id, $text, $staffId);
    echo json_encode(array_merge(['status' => (bool) ($res['ok'] ?? false)], $res));
});

// ================================ TAKEOVER (JSON POST) — take or release a chat.
// cap chat.takeover. mode=take assigns + announces (by name) a human joined;
// anything else releases back to GIA. Name = display_name, else users first_name.
$router->post(admin.'/support/desk/takeover', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    require_once dirname(__DIR__, 2) . '/lib/support/livedesk.php';
    ensureSupportDeskSchema($db);
    header('Content-Type: application/json');
    if (!admin_can($db, 'support_desk', 'edit')) { http_response_code(403); echo json_encode(['status' => false, 'message' => 'Forbidden']); return; }

    $staffId = admin_staff_id($db);

    // Name to announce to the customer: the staff member's users.first_name
    // (the staff key IS users.user_id now).
    $displayName = '';
    if ($staffId !== '') {
        try {
            $fn = $db->get('users', 'first_name', ['user_id' => $staffId]);
            $displayName = trim((string) ($fn ?? ''));
        } catch (\Throwable $e) {
            error_log('[support] desk takeover name lookup: ' . $e->getMessage());
        }
    }

    $id   = (int) ($_POST['id'] ?? 0);
    $take = ((string) ($_POST['mode'] ?? '')) === 'take';
    if ($id <= 0) { echo json_encode(['status' => false, 'message' => 'Missing conversation id.']); return; }

    $res = support_desk_takeover($db, $id, $staffId, $displayName, $take);
    echo json_encode(array_merge(['status' => (bool) ($res['ok'] ?? false)], $res));
});
