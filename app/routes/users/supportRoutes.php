<?php
// app/routes/users/supportRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /support/tickets
$router->get('support/tickets', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    $user_id = $_SESSION['user_id'];

    // META DATA
    $title = T::support.' '.T::tickets;

    require_once views."includes/header.php";
    require_once "app/views/auth/support/tickets.php";
    require_once views."includes/footer.php";
});

// ================================ GET /support/ticket/{ticket_id}
$router->get('support/ticket/(.+)', function ($ticket_id) use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    $user_id = $_SESSION['user_id'];

    // Verify ticket ownership
    $ticket = $db->get('tickets', '*', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'type' => 'parent'
    ]);

    if (!$ticket) {
        $_SESSION['error'] = T::ticket.' '.T::not_found;
        header('Location: ' . root . 'support/tickets');
        exit;
    }

    // META DATA
    $title = T::ticket.' #'.$ticket_id;

    require_once views."includes/header.php";
    require_once "app/views/auth/support/ticket.php";
    require_once views."includes/footer.php";
});

// ================================ POST /support/tickets/create
$router->post('support/tickets/create', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    $user_id = $_SESSION['user_id'];

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            throw new Exception('Invalid form submission');
        }

        // Input validation & sanitization
        $subject = trim(strip_tags($_POST['subject'] ?? ''));
        $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high']) ? $_POST['priority'] : 'normal';
        $desc = trim($_POST['desc'] ?? '');

        if (empty($subject) || strlen($subject) < 5) {
            throw new Exception('Subject must be at least 5 characters');
        }

        if (empty($desc) || strlen($desc) < 10) {
            throw new Exception('Description must be at least 10 characters');
        }

        // Generate unique ticket ID
        $ticket_id = date('dmy') . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Handle file attachment with security
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            // SECURITY: validate real MIME + safe extension (finfo).
            $chk = secureUploadCheck($_FILES['attachment'], ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'], 5 * 1024 * 1024);
            if (!$chk['ok']) {
                throw new Exception($chk['error'] ?? 'Invalid file. Only JPG, PNG, GIF, PDF, ZIP allowed');
            }

            $upload_dir = 'uploads/tickets/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = 'ticket_' . bin2hex(random_bytes(8)) . '_' . $user_id . '.' . $chk['ext'];
            $upload_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                @chmod($upload_path, 0644);
                $attachment = json_encode([$upload_path]);
            }
        }

        // Insert ticket
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
            throw new Exception('Failed to create ticket');
        }

        // Send email notification to all admins
        try {
            // Get user details
            $userDetails = $db->get('users', ['first_name', 'last_name'], ['user_id' => $user_id]);
            $userName = ($userDetails) ? $userDetails['first_name'] . ' ' . $userDetails['last_name'] : 'Customer';
            
            // Get all admin users
            $admins = $db->select('users', ['first_name', 'last_name', 'email'], ['role' => 'admin']);
            
            if ($admins && count($admins) > 0) {
                // Prepare email data
                $actionType = 'new_ticket';
                $ticketSubject = $subject;
                $senderName = $userName;
                $senderRole = 'Customer';
                $messagePreview = substr(strip_tags($desc), 0, 200);
                $ticketUrl = root . admin . '/support/ticket/' . $ticket_id;
                $ticketId = $ticket_id;
                $invoiceId = $ticket_id;
                
                // Send email to each admin
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $receiverName = $admin['first_name'] . ' ' . $admin['last_name'];
                        
                        // Load email template
                        ob_start();
                        include views . 'notifications/emails/header.php';
                        include views . 'notifications/emails/support/ticket_notification.php';
                        include views . 'notifications/emails/footer.php';
                        $emailBody = ob_get_clean();
                        
                        // Send email
                        $emailSubject = "New Support Ticket Created - #$ticket_id";
                        SENDEMAIL($admin['email'], $receiverName, $emailSubject, $emailBody);
                    }
                }
                
                error_log("New ticket notification sent to admins for ticket: $ticket_id");
            }
        } catch (Exception $emailError) {
            error_log('Failed to send new ticket notification to admins: ' . $emailError->getMessage());
            // Don't fail the ticket creation if email fails
        }

        $_SESSION['success'] = T::ticket.' '.T::created.' '.T::successfully;
        header('Location: ' . root . 'support/ticket/' . $ticket_id);
        exit;

    } catch (Exception $e) {
        error_log('User Ticket Creation Error: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . root . 'support/tickets');
        exit;
    }
});

// ================================ POST /support/tickets/reply
$router->post('support/tickets/reply', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    $user_id = $_SESSION['user_id'];

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            throw new Exception('Invalid form submission');
        }

        // Input validation
        $ticket_id = trim($_POST['ticket_id'] ?? '');
        $desc = trim($_POST['desc'] ?? '');

        if (empty($desc) || strlen($desc) < 5) {
            throw new Exception('Reply must be at least 5 characters');
        }

        // Verify ticket ownership
        $parent_ticket = $db->get('tickets', '*', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'type' => 'parent'
        ]);

        if (!$parent_ticket) {
            throw new Exception('Ticket not found or access denied');
        }

        // Don't allow replies on closed tickets
        if ($parent_ticket['status'] === 'close') {
            throw new Exception('Cannot reply to closed ticket');
        }

        // Handle file attachment with security
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            // SECURITY: validate the REAL MIME (finfo) and derive a SAFE extension
            // from it — never from the client-sent type or the user filename. The
            // old check used $_FILES['type'] (attacker-spoofable) + the user's own
            // extension, so a logged-in user could upload shell.php (Content-Type:
            // image/png) into the web-served uploads/tickets/ dir. secureUploadCheck
            // also rejects PHP-in-image polyglots.
            $chk = secureUploadCheck(
                $_FILES['attachment'],
                ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
                5 * 1024 * 1024
            );
            if (!$chk['ok']) {
                throw new Exception($chk['error'] ?? 'Invalid file type');
            }

            $upload_dir = 'uploads/tickets/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = uniqid('reply_') . '_' . $user_id . '_' . bin2hex(random_bytes(4)) . '.' . $chk['ext'];
            $upload_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                @chmod($upload_path, 0644);
                $attachment = json_encode([$upload_path]);
            }
        }

        // Insert reply
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
            throw new Exception('Failed to add reply');
        }

        // Send email notification to all admins
        try {
            // Get user details
            $userDetails = $db->get('users', ['first_name', 'last_name'], ['user_id' => $user_id]);
            $userName = ($userDetails) ? $userDetails['first_name'] . ' ' . $userDetails['last_name'] : 'Customer';
            
            // Get all admin users
            $admins = $db->select('users', ['first_name', 'last_name', 'email'], ['role' => 'admin']);
            
            if ($admins && count($admins) > 0) {
                // Prepare email data
                $actionType = 'new_reply';
                $ticketSubject = $parent_ticket['subject'];
                $senderName = $userName;
                $senderRole = 'Customer';
                $messagePreview = substr(strip_tags($desc), 0, 200);
                $ticketUrl = root . admin . '/support/ticket/' . $ticket_id;
                $ticketId = $ticket_id;
                $invoiceId = $ticket_id;
                
                // Send email to each admin
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $receiverName = $admin['first_name'] . ' ' . $admin['last_name'];
                        
                        // Load email template
                        ob_start();
                        include views . 'notifications/emails/header.php';
                        include views . 'notifications/emails/support/ticket_notification.php';
                        include views . 'notifications/emails/footer.php';
                        $emailBody = ob_get_clean();
                        
                        // Send email
                        $emailSubject = "New Reply on Ticket #$ticket_id";
                        SENDEMAIL($admin['email'], $receiverName, $emailSubject, $emailBody);
                    }
                }
                
                error_log("Reply notification sent to admins for ticket: $ticket_id by user: $user_id");
            }
        } catch (Exception $emailError) {
            error_log('Failed to send reply notification to admins: ' . $emailError->getMessage());
            // Don't fail the reply if email fails
        }

        $_SESSION['success'] = T::reply.' '.T::added.' '.T::successfully;
        header('Location: ' . root . 'support/ticket/' . $ticket_id . '#replies');
        exit;

    } catch (Exception $e) {
        error_log('User Reply Error: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . root . 'support/ticket/' . ($ticket_id ?? ''));
        exit;
    }
});

// ================================ POST /support/tickets/close
$router->post('support/tickets/close', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $user_id = $_SESSION['user_id'];

    header('Content-Type: application/json');

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid form submission']);
            exit;
        }

        // Input validation
        $ticket_id = trim($_POST['ticket_id'] ?? '');

        if (empty($ticket_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing ticket ID']);
            exit;
        }

        // Verify ticket ownership
        $ticket = $db->get('tickets', '*', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'type' => 'parent'
        ]);

        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found']);
            exit;
        }

        // Update ticket status to close
        $result = $db->update('tickets',
            ['status' => 'close'],
            ['ticket_id' => $ticket_id, 'type' => 'parent']
        );

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Ticket closed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to close ticket']);
        }
        exit;

    } catch (Exception $e) {
        error_log('Close Ticket Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred']);
        exit;
    }
});
