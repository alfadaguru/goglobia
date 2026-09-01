<?php
// app/routes/admin/supportticketRoutes.php
@$SECURE or die('Access Denied!');

// ================================ GET /admin/support/tickets
$router->get(admin.'/support/tickets', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::support.' '.T::tickets;

    require_once views."includes/header.php";
    require_once "app/views/admin/support/tickets.php";
    require_once views."includes/footer.php";

});

// ================================ GET /admin/support/ticket/{ticket_id}
$router->get(admin.'/support/ticket/(.+)', function ($ticket_id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::support.' '.T::ticket;

    require_once views."includes/header.php";
    require_once "app/views/admin/support/ticket.php";
    require_once views."includes/footer.php";

});

// ================================ POST /admin/support/tickets/create
$router->post(admin.'/support/tickets/create', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            $_SESSION['ticket_error'] = 'Invalid form submission. Please try again.';
            header('Location: ' . root . admin . '/support/ticket/new');
            exit;
        }

        // Validate required fields
        $user_id = trim($_POST['user_id'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');
        $desc = $_POST['desc'] ?? '';

        // Detailed validation with specific error messages
        if (empty($user_id)) {
            $_SESSION['ticket_error'] = 'Please select a customer.';
            header('Location: ' . root . admin . '/support/ticket/new');
            exit;
        }

        if (empty($subject)) {
            $_SESSION['ticket_error'] = 'Please enter a ticket subject.';
            header('Location: ' . root . admin . '/support/ticket/new');
            exit;
        }

        if (empty($desc)) {
            $_SESSION['ticket_error'] = 'Please enter a description.';
            header('Location: ' . root . admin . '/support/ticket/new');
            exit;
        }

        // Check if user exists
        $customer = $db->get('users', '*', ['user_id' => $user_id]);
        if (!$customer) {
            $_SESSION['ticket_error'] = 'Selected customer not found.';
            header('Location: ' . root . admin . '/support/ticket/new');
            exit;
        }

        // Generate ticket ID
        $ticket_id = date('dmy') . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Handle attachment upload
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/tickets/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $chk = secureUploadCheck($_FILES['attachment'], ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'], 5 * 1024 * 1024);
            if ($chk['ok']) {
                $file_name = 'ticket_' . bin2hex(random_bytes(8)) . '.' . $chk['ext'];
                $upload_path = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                    @chmod($upload_path, 0644);
                    $attachment = json_encode([$upload_path]);
                }
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

        if ($result) {
            // Send email notification to assigned user
            try {
                // Fetch assigned user details
                $assignedUser = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $user_id]);
                
                if ($assignedUser && !empty($assignedUser['email'])) {
                    // Get admin details
                    $adminUser = $db->get('users', ['first_name', 'last_name'], ['user_id' => $_SESSION['user_id']]);
                    $adminName = ($adminUser) ? $adminUser['first_name'] . ' ' . $adminUser['last_name'] : 'Admin';
                    
                    // Prepare email data
                    $receiverName = $assignedUser['first_name'] . ' ' . $assignedUser['last_name'];
                    $actionType = 'new_ticket';
                    $ticketSubject = $subject;
                    $senderName = $adminName;
                    $senderRole = 'Admin';
                    $messagePreview = substr(strip_tags($desc), 0, 200);
                    $ticketUrl = root . 'support/ticket/' . $ticket_id;
                    $ticketId = $ticket_id;
                    $invoiceId = $ticket_id;
                    
                    // Load email template
                    ob_start();
                    include views . 'notifications/emails/header.php';
                    include views . 'notifications/emails/support/ticket_notification.php';
                    include views . 'notifications/emails/footer.php';
                    $emailBody = ob_get_clean();
                    
                    // Send email
                    $emailSubject = "New Support Ticket Assigned - #$ticket_id";
                    SENDEMAIL($assignedUser['email'], $receiverName, $emailSubject, $emailBody);
                    
                    error_log("Ticket notification sent to user: {$assignedUser['email']} for ticket: $ticket_id");
                }
            } catch (Exception $emailError) {
                error_log('Failed to send ticket notification email: ' . $emailError->getMessage());
                // Don't fail the ticket creation if email fails
            }

            $_SESSION['ticket_success'] = 'Ticket created successfully!';
            header('Location: ' . root . admin . '/support/ticket/' . $ticket_id);
            exit;
        } else {
            throw new Exception('Failed to create ticket.');
        }

    } catch (Exception $e) {
        error_log('Ticket Creation Error: ' . $e->getMessage());
        $_SESSION['ticket_error'] = 'Failed to create ticket: ' . $e->getMessage();
        header('Location: ' . root . admin . '/support/ticket/new');
        exit;
    }

});

// ================================ POST /admin/support/tickets/update-status
$router->post(admin.'/support/tickets/update-status', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid form submission']);
            exit;
        }

        // Validate required fields
        $ticket_id = trim($_POST['ticket_id'] ?? '');
        $status = trim($_POST['status'] ?? '');

        if (empty($ticket_id) || empty($status)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        // Validate status value
        $valid_statuses = ['open', 'in progress', 'waiting', 'close'];
        if (!in_array($status, $valid_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status value']);
            exit;
        }

        // Update ticket status
        $result = $db->update('tickets',
            ['status' => $status],
            ['ticket_id' => $ticket_id, 'type' => 'parent']
        );

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        exit;

    } catch (Exception $e) {
        error_log('Status Update Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }

});

// ================================ POST /admin/support/tickets/reply
$router->post(admin.'/support/tickets/reply', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            $_SESSION['ticket_error'] = 'Invalid form submission. Please try again.';
            header('Location: ' . $_SERVER['HTTP_REFERER'] ?? root . admin . '/support/tickets');
            exit;
        }

        // Validate required fields
        $ticket_id = trim($_POST['ticket_id'] ?? '');
        $desc = $_POST['desc'] ?? '';

        if (empty($ticket_id) || empty($desc)) {
            $_SESSION['ticket_error'] = 'Please enter a reply message.';
            header('Location: ' . root . admin . '/support/ticket/' . $ticket_id);
            exit;
        }

        // Check if parent ticket exists
        $parent_ticket = $db->get('tickets', '*', ['ticket_id' => $ticket_id, 'type' => 'parent']);
        if (!$parent_ticket) {
            $_SESSION['ticket_error'] = 'Ticket not found.';
            header('Location: ' . root . admin . '/support/tickets');
            exit;
        }

        // Handle attachment upload
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/tickets/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid('reply_') . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $attachment = json_encode([$upload_path]);
            }
        }

        // Insert reply
        $result = $db->insert('tickets', [
            'ticket_id' => $ticket_id,
            'user_id' => $_SESSION['user_id'],
            'desc' => $desc,
            'status' => $parent_ticket['status'],
            'type' => 'reply',
            'attachments' => $attachment,
            'date' => date('Y-m-d H:i:s')
        ]);

        if ($result) {
            // Send email notification to ticket owner (user)
            try {
                // Get ticket owner details
                $ticketOwner = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $parent_ticket['user_id']]);
                
                // Only send if owner exists and is not the admin replying (no self-notification)
                if ($ticketOwner && !empty($ticketOwner['email']) && $parent_ticket['user_id'] != $_SESSION['user_id']) {
                    // Get admin details
                    $adminUser = $db->get('users', ['first_name', 'last_name'], ['user_id' => $_SESSION['user_id']]);
                    $adminName = ($adminUser) ? $adminUser['first_name'] . ' ' . $adminUser['last_name'] : 'Admin';
                    
                    // Prepare email data
                    $receiverName = $ticketOwner['first_name'] . ' ' . $ticketOwner['last_name'];
                    $actionType = 'new_reply';
                    $ticketSubject = $parent_ticket['subject'];
                    $senderName = $adminName;
                    $senderRole = 'Admin';
                    $messagePreview = substr(strip_tags($desc), 0, 200);
                    $ticketUrl = root . 'support/ticket/' . $ticket_id;
                    $ticketId = $ticket_id;
                    $invoiceId = $ticket_id;
                    
                    // Load email template
                    ob_start();
                    include views . 'notifications/emails/header.php';
                    include views . 'notifications/emails/support/ticket_notification.php';
                    include views . 'notifications/emails/footer.php';
                    $emailBody = ob_get_clean();
                    
                    // Send email
                    $emailSubject = "New Reply on Ticket #$ticket_id";
                    SENDEMAIL($ticketOwner['email'], $receiverName, $emailSubject, $emailBody);
                    
                    error_log("Reply notification sent to user: {$ticketOwner['email']} for ticket: $ticket_id");
                }
            } catch (Exception $emailError) {
                error_log('Failed to send reply notification email: ' . $emailError->getMessage());
                // Don't fail the reply if email fails
            }

            $_SESSION['ticket_success'] = 'Reply added successfully!';
            header('Location: ' . root . admin . '/support/ticket/' . $ticket_id);
            exit;
        } else {
            throw new Exception('Failed to add reply.');
        }

    } catch (Exception $e) {
        error_log('Reply Error: ' . $e->getMessage());
        $_SESSION['ticket_error'] = 'Failed to add reply: ' . $e->getMessage();
        header('Location: ' . root . admin . '/support/ticket/' . ($ticket_id ?? ''));
        exit;
    }

});

// ================================ POST /admin/support/tickets/delete
$router->post(admin.'/support/tickets/delete', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    header('Content-Type: application/json');

    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !CSRF::validateToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid form submission']);
            exit;
        }

        // Validate required fields
        $ticket_id = trim($_POST['ticket_id'] ?? '');

        if (empty($ticket_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing ticket ID']);
            exit;
        }

        // Delete all tickets (parent and replies) with this ticket_id
        $result = $db->delete('tickets', ['ticket_id' => $ticket_id]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Ticket deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete ticket']);
        }
        exit;

    } catch (Exception $e) {
        error_log('Delete Ticket Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }

});