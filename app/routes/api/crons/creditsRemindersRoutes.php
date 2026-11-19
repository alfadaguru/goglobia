<?php
// app/routes/api/crons/creditsRemindersRoutes.php
//
// READ-ONLY sibling of /send_credits_reminders: returns the overdue-agent list as
// JSON (no emails sent). Because that list is agent PII + financial data, this is
// gated on the SAME cron secret (_cron_secret_ok) — see
// app/routes/crons/creditsRemindersRoutes.php for the shared gate + the AG-F1/F2/F3
// rationale. Keep the two files' credit math (floatval) + gate in sync.
@$SECURE or die('Access Denied!');

$router->get('/api/credits/reminders', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    // AG-F3/AG-F1: this endpoint returns overdue-agent data (PII). Gate it on the
    // cron secret just like /send_credits_reminders (fail-open only when no secret
    // is configured, so an existing schedule isn't broken). _cron_secret_ok lives
    // in app/routes/crons/creditsRemindersRoutes.php; guard for load order.
    if (function_exists('_cron_secret_ok') && !_cron_secret_ok($db)) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'Forbidden']);
        exit;
    }

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

            // Calculate used credits from credits table.
            // AG-F2: floatval+round (was intval, which truncated sub-unit amounts —
            // inconsistent with the admin credits page's money-integrity fix).
            $used_credits = round((float) ($db->sum('credits', 'credits', [
                'user_id' => $user['user_id'],
                'type'    => 'debit',
            ]) ?: 0), 2);

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
        // AG-F3: do NOT leak the raw exception message to the client; log it.
        error_log('api/credits/reminders: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status'  => false,
            'message' => 'Something went wrong',
        ]);
    }
});
