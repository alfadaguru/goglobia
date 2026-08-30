<?php
// app/routes/api/crons/creditsRemindersRoutes.php
@$SECURE or die('Access Denied!');

$router->get('/api/credits/reminders', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        $users = $db->select('users', [
            'user_id',
            'first_name',
            'last_name',
            'email',
            'credit_limits',
            'credit_payment_days',
            'first_credit_usage_date'
        ], [
            'role'                       => 'agent',
            'status'                     => 'active',
            'first_credit_usage_date[!]' => null,
            'credit_payment_days[>]'     => 0,
        ]);

        $reminder_users = [];

        foreach ($users as $user) {

            // Calculate used credits from credits table
            $used_credits = intval($db->sum('credits', 'credits', [
                'user_id' => $user['user_id'],
                'type'    => 'debit',
            ]) ?: 0);

            if ($used_credits <= 0) continue;

            $first_usage_date = new DateTime($user['first_credit_usage_date']);
            $current_date     = new DateTime();
            $today_ts         = $current_date->getTimestamp();
            $due_date         = clone $first_usage_date;
            $due_date->modify('+' . intval($user['credit_payment_days']) . ' days');
            $due_ts           = $due_date->getTimestamp();

            if ($today_ts < $due_ts) continue; // still within window

            $days_overdue = intval(floor(($today_ts - $due_ts) / 86400));

            $reminder_users[] = [
                'user_id'          => $user['user_id'],
                'name'             => $user['first_name'] . ' ' . $user['last_name'],
                'email'            => $user['email'],
                'used_credits'     => $used_credits,
                'credit_limit'     => $user['credit_limits'],
                'payment_days'     => $user['credit_payment_days'],
                'first_usage_date' => $user['first_credit_usage_date'],
                'due_date'         => $due_date->format('Y-m-d'),
                'days_overdue'     => $days_overdue,
                'amount_due'       => $used_credits,
            ];
        }

        echo json_encode([
            'status'  => true,
            'message' => 'Credit reminder data fetched successfully',
            'count'   => count($reminder_users),
            'data'    => $reminder_users
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status'  => false,
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ]);
    }
});
