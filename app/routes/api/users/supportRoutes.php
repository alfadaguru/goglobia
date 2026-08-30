<?php
// FILE: app/routes/api/users/supportRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// SUPPORT TICKETS API
// ====================================

/**
 * Reads a request field regardless of how the client sent the body.
 * Order: $_POST (form-urlencoded / multipart) -> raw JSON -> raw urlencoded.
 * Mobile clients commonly POST JSON, which PHP never puts into $_POST.
 */
if (!function_exists('supportInput')) {
    function supportInput($key, $default = '')
    {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            return $_POST[$key];
        }

        static $parsedBody = null;
        if ($parsedBody === null) {
            $parsedBody = [];
            $raw = file_get_contents('php://input');

            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $parsedBody = $decoded;
                } else {
                    parse_str($raw, $parsedBody);
                }
            }
        }

        return (isset($parsedBody[$key]) && $parsedBody[$key] !== '') ? $parsedBody[$key] : $default;
    }
}

// POST ALL TICKETS
$router->post('/api/users/support/tickets', function () use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $ticketsRaw = $db->select('tickets', '*', [
        'user_id' => $user_id,
        'type' => 'parent',
        'ORDER' => ['id' => 'DESC']
    ]);

    $tickets = [];
    if (is_array($ticketsRaw)) {
        foreach ($ticketsRaw as $t) {
            $tickets[] = [
                'ticket_id' => $t['ticket_id'] ?? '',
                'subject' => $t['subject'] ?? '',
                'priority' => $t['priority'] ?? 'normal',
                'status' => $t['status'] ?? 'open',
                'date' => $t['date'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Tickets retrieved',
        'data' => $tickets
    ]);
    exit;
});

// ====================================
// CREATE SUPPORT TICKET API
// ====================================
$router->post('/api/users/support/ticket/create', function () use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $subject = trim(strip_tags(supportInput('subject')));
    $priorityInput = supportInput('priority');
    $priority = in_array($priorityInput, ['low', 'normal', 'high']) ? $priorityInput : 'normal';
    $desc = trim(supportInput('desc'));

    if (empty($subject) || strlen($subject) < 5) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Subject must be at least 5 characters']);
        exit;
    }

    if (empty($desc) || strlen($desc) < 10) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Description must be at least 10 characters']);
        exit;
    }

    $ticket_id = date('dmy') . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);

    $attachment = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/zip'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $file_type = $_FILES['attachment']['type'];
        $file_size = $_FILES['attachment']['size'];

        if (!in_array($file_type, $allowed_types)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, GIF, PDF, ZIP allowed']);
            exit;
        }

        if ($file_size > $max_size) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'File size exceeds 5MB limit']);
            exit;
        }

        $upload_dir = 'uploads/tickets/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid('ticket_') . '_' . $user_id . '.' . strtolower($file_extension);
        $upload_path = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
            $attachment = json_encode([$upload_path]);
        }
    }

    $result = $db->insert('tickets', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'subject' => $subject,
        'priority' => $priority,
        'desc' => $desc,
        'status' => 'open',
        'type' => 'parent',
        'attachments' => $attachment,
        'date' => date('Y-m-d H:i:s')
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to create ticket']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Ticket created successfully',
        'ticket_id' => $ticket_id
    ]);
    exit;
});

// ====================================
// REPLY SUPPORT TICKET API
// ====================================
$router->post('/api/users/support/ticket/reply', function () use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $ticket_id = trim(supportInput('ticket_id'));
    $desc = trim(supportInput('desc'));

    if (empty($ticket_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing ticket ID']);
        exit;
    }

    if (empty($desc) || strlen($desc) < 5) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Reply must be at least 5 characters']);
        exit;
    }

    $parent_ticket = $db->get('tickets', '*', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'type' => 'parent'
    ]);

    if (!$parent_ticket) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
        exit;
    }

    if ($parent_ticket['status'] === 'close') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Cannot reply to closed ticket']);
        exit;
    }

    $attachment = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/zip'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $file_type = $_FILES['attachment']['type'];
        $file_size = $_FILES['attachment']['size'];

        if (!in_array($file_type, $allowed_types)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
            exit;
        }

        if ($file_size > $max_size) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'File size exceeds 5MB']);
            exit;
        }

        $upload_dir = 'uploads/tickets/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid('reply_') . '_' . $user_id . '.' . strtolower($file_extension);
        $upload_path = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
            $attachment = json_encode([$upload_path]);
        }
    }

    $result = $db->insert('tickets', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'desc' => $desc,
        'status' => $parent_ticket['status'],
        'type' => 'reply',
        'attachments' => $attachment,
        'date' => date('Y-m-d H:i:s')
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to add reply']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Reply added successfully'
    ]);
    exit;
});

// ====================================
// CLOSE SUPPORT TICKET API
// ====================================
$router->post('/api/users/support/ticket/close', function () use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $ticket_id = trim(supportInput('ticket_id'));

    if (empty($ticket_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing ticket ID']);
        exit;
    }

    $parent_ticket = $db->get('tickets', '*', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'type' => 'parent'
    ]);

    if (!$parent_ticket) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Ticket not found',
            'received_ticket_id' => $ticket_id
        ]);
        exit;
    }

    if ($parent_ticket['status'] === 'close') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Ticket is already closed']);
        exit;
    }

    $result = $db->update('tickets',
        ['status' => 'close'],
        ['ticket_id' => $ticket_id, 'type' => 'parent']
    );

    if (!$result) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to close ticket']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Ticket closed successfully',
        'ticket_id' => $ticket_id
    ]);
    exit;
});

// ====================================
// POST SINGLE TICKET & REPLIES
// NOTE: must stay LAST - this is a catch-all pattern. The router matches the
// first registered route, so any specific /ticket/{action} route registered
// after this one would never be reached. The lookahead also guards against it.
// ====================================
$router->post('/api/users/support/ticket/(?!create$|reply$|close$)(.+)', function ($ticket_id) use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $ticketRaw = $db->get('tickets', '*', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'type' => 'parent'
    ]);

    if (!$ticketRaw) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
        exit;
    }

    $ticket = [
        'ticket_id' => $ticketRaw['ticket_id'] ?? '',
        'subject' => $ticketRaw['subject'] ?? '',
        'priority' => $ticketRaw['priority'] ?? 'normal',
        'status' => $ticketRaw['status'] ?? 'open',
        'desc' => $ticketRaw['desc'] ?? '',
        'attachments' => $ticketRaw['attachments'] ?? null,
        'date' => $ticketRaw['date'] ?? ''
    ];

    $repliesRaw = $db->select('tickets', '*', [
        'ticket_id' => $ticket_id,
        'type' => 'reply',
        'ORDER' => ['id' => 'ASC']
    ]);

    $replies = [];
    if (is_array($repliesRaw)) {
        foreach ($repliesRaw as $r) {
            $reply_user_id = $r['user_id'] ?? '';
            $user_name = '';

            if ($reply_user_id) {
                $user_info = $db->get('users', ['first_name', 'last_name'], ['user_id' => $reply_user_id]);
                if ($user_info) {
                    $user_name = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
                }
            }
            
            // Fallback if name is empty
            if (empty($user_name)) {
                $user_name = 'Support';
            }

            $replies[] = [
                'desc' => $r['desc'] ?? '',
                'attachments' => $r['attachments'] ?? null,
                'user_name' => $user_name,
                'date' => $r['date'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'ticket' => $ticket,
            'replies' => $replies
        ]
    ]);
    exit;
});
