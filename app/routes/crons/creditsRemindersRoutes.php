<?php
/**
 * CRON JOB: Credit Payment Reminders
 * FILE: app/routes/crons/creditsRemindersRoutes.php
 *
 * HOW IT WORKS:
 * -------------
 * Reminds active agents when EITHER:
 *   A) used credits >= (assigned * credit_usage_reminder_percent / 100), or
 *   B) payment window expired: first_credit_usage_date + credit_payment_days
 * Amount due is always SUM(debits) — actual credits used.
 *
 * CRON SETUP (on the server run this daily):
 *   0 9 * * * curl -s "https://yourdomain.com/send_credits_reminders" > /dev/null 2>&1
 *
 * ROUTE: GET /send_credits_reminders
 */

@$SECURE or die('Access Denied!');

$router->get('/send_credits_reminders', function () use ($SECURE, $db) {

    $results        = [];
    $emails_sent    = 0;
    $skipped        = 0;

    $today          = new DateTime();

    $currency = $db->get('currencies', 'name', ['default' => 1]) ?? 'USD';
    $settings = $db->get('settings', '*') ?? [];
    $companyName = getBrandName($settings);

    // Active agents — filter usage/due in PHP (usage can fire before due date)
    $agents = $db->select('users',
        [
            'user_id',
            'first_name',
            'last_name',
            'email',
            'credit_limits',
            'credit_payment_days',
            'first_credit_usage_date',
            'credit_usage_reminder_percent',
        ],
        [
            'role'   => 'agent',
            'status' => 'active',
        ]
    );

    if (empty($agents)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'     => true,
            'message'     => 'No eligible agents found.',
            'emails_sent' => 0,
        ]);
        exit;
    }

    $overdue_agents = [];
    $today_ts = $today->getTimestamp();

    foreach ($agents as $agent) {

        $user_id      = $agent['user_id'];
        $payment_days = intval($agent['credit_payment_days'] ?? 0);
        $first_usage  = $agent['first_credit_usage_date'] ?? null;
        $usage_pct    = max(0, min(100, intval($agent['credit_usage_reminder_percent'] ?? 0)));

        $used_credits = intval($db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type'    => 'debit',
        ]) ?: 0);

        if ($used_credits <= 0) {
            $skipped++;
            continue;
        }

        $total_added = intval($db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type'    => 'credit',
        ]) ?: 0);

        $threshold = 0;
        if ($usage_pct > 0 && $total_added > 0) {
            $threshold = (int) floor($total_added * $usage_pct / 100);
        }
        $usage_hit = ($usage_pct > 0 && $threshold > 0 && $used_credits >= $threshold);

        $due_date = null;
        $days_overdue = null;
        $days_used = null;
        $payment_due = false;

        if (!empty($first_usage) && $payment_days > 0) {
            try {
                $usage_date = new DateTime($first_usage);
                $due_date   = clone $usage_date;
                $due_date->modify("+{$payment_days} days");
                $due_ts = $due_date->getTimestamp();
                $payment_due = ($today_ts >= $due_ts);
                $days_overdue = intval(floor(($today_ts - $due_ts) / 86400));
                $days_used = intval($today->diff($usage_date)->days);
            } catch (Throwable $e) {
                $payment_due = false;
            }
        }

        if (!$usage_hit && !$payment_due) {
            $skipped++;
            continue;
        }

        $trigger = $usage_hit && $payment_due ? 'usage_and_due'
            : ($usage_hit ? 'usage_threshold' : 'payment_due');

        $overdue_agents[] = [
            'user_id'           => $user_id,
            'receiverName'      => trim($agent['first_name'] . ' ' . $agent['last_name']),
            'email'             => $agent['email'],
            'credit_limit'      => $total_added,
            'used_credits'      => $used_credits,
            'amount_due'        => $used_credits,
            'currency'          => $currency,
            'payment_days'      => $payment_days,
            'usage_percent'     => $usage_pct,
            'usage_threshold'   => $threshold,
            'first_usage_date'  => $first_usage,
            'due_date'          => $due_date ? $due_date->format('Y-m-d') : null,
            'days_overdue'      => $days_overdue !== null ? max(0, $days_overdue) : 0,
            'days_used'         => $days_used ?? 0,
            'trigger'           => $trigger,
        ];
    }

    $template_file = __DIR__ . '/../../views/notifications/emails/credits/payment_reminder.php';

    foreach ($overdue_agents as $agent) {

        if (empty($agent['email'])) continue;

        ob_start();
        $SECURE           = true;
        $receiverName     = $agent['receiverName'];
        $amount_due       = $agent['amount_due'];
        $currency         = $agent['currency'];
        $days_used        = $agent['days_used'];
        $payment_days     = $agent['payment_days'];
        $days_overdue     = $agent['days_overdue'];
        $credit_limit     = $agent['credit_limit'];
        $used_credits     = $agent['used_credits'];
        $first_usage_date = $agent['first_usage_date'] ?: date('Y-m-d');
        $due_date         = $agent['due_date'] ?: date('Y-m-d');
        $invoiceId        = null;
        $companyName      = $companyName;
        $settings         = $settings;

        include __DIR__ . '/../../views/notifications/emails/header.php';
        include $template_file;
        include __DIR__ . '/../../views/notifications/emails/footer.php';
        $emailBody = ob_get_clean();

        $subject = "⚠️ Credit Payment Reminder – {$currency} " . number_format($agent['amount_due'], 2) . " Due";

        $sent = SENDEMAIL($agent['email'], $agent['receiverName'], $subject, $emailBody);

        if ($sent) {
            $emails_sent++;
            $results[] = [
                'user_id'     => $agent['user_id'],
                'name'        => $agent['receiverName'],
                'email'       => $agent['email'],
                'amount_due'  => $agent['amount_due'],
                'trigger'     => $agent['trigger'],
                'days_overdue'=> $agent['days_overdue'],
                'status'      => 'email_sent',
            ];
        } else {
            $results[] = [
                'user_id' => $agent['user_id'],
                'email'   => $agent['email'],
                'status'  => 'email_failed',
            ];
        }
    }

    if (!empty($overdue_agents)) {
        $admins = $db->select('users', ['email', 'first_name', 'last_name'], [
            'role'   => ['admin', 'superadmin'],
            'status' => 'active',
        ]);

        if (!empty($admins)) {
            $admin_subject = "[ADMIN] " . count($overdue_agents) . " Agent(s) Need Credit Payment Attention";

            $admin_rows = '';
            foreach ($overdue_agents as $a) {
                $dueLabel = $a['due_date']
                    ? (date('d M Y', strtotime($a['due_date'])) . " ({$a['days_overdue']}d)")
                    : '—';
                $admin_rows .= "
                <tr>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;'>" . htmlspecialchars($a['receiverName']) . "</td>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;'>" . htmlspecialchars($a['email']) . "</td>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;'>{$a['used_credits']}</td>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;'>{$a['currency']} {$a['amount_due']}</td>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;'>{$a['trigger']}</td>
                    <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#dc2626;font-weight:600;'>{$dueLabel}</td>
                </tr>";
            }

            $admin_body = "
            <!DOCTYPE html><html><body style='font-family:sans-serif;background:#f8fafc;padding:20px;'>
            <div style='max-width:780px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;'>
                <div style='background:#dc2626;padding:16px 24px;'>
                    <h2 style='color:#fff;font-size:16px;margin:0;'>⚠️ Credit Payment Reminder Summary</h2>
                    <p style='color:#fecaca;font-size:12px;margin:4px 0 0;'>Generated: " . date('d M Y, h:i A') . "</p>
                </div>
                <div style='padding:24px;'>
                    <p style='font-size:14px;color:#4a5568;margin-bottom:16px;'>The following " . count($overdue_agents) . " agent(s) hit a usage threshold and/or payment due date. Reminder emails have been sent.</p>
                    <table style='width:100%;border-collapse:collapse;font-family:sans-serif;'>
                        <thead>
                            <tr style='background:#f8fafc;'>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Agent</th>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Email</th>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Used</th>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Amount Due</th>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Trigger</th>
                                <th style='padding:10px 12px;text-align:left;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0;'>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>$admin_rows</tbody>
                    </table>
                </div>
                <div style='background:#f8fafc;padding:12px 24px;border-top:1px solid #e2e8f0;text-align:center;'>
                    <a href='" . root . "admin/finance/credits' style='display:inline-block;background:#1e40af;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;'>View Credits Section</a>
                </div>
            </div>
            </body></html>";

            foreach ($admins as $admin) {
                if (empty($admin['email'])) continue;
                SENDEMAIL($admin['email'], $admin['first_name'] . ' ' . $admin['last_name'], $admin_subject, $admin_body);
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'         => true,
        'run_at'          => date('Y-m-d H:i:s'),
        'total_agents'    => count($agents),
        'overdue_agents'  => count($overdue_agents),
        'skipped'         => $skipped,
        'emails_sent'     => $emails_sent,
        'results'         => $results,
    ]);
    exit;
});
