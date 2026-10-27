<?php
// app/routes/crons/supplierTicksRoutes.php
// Supplier PMS automation workers. These are the "single cron entry point" the two
// scheduler engines already advertise to the operator (supplier_notify_ext.php's
// notify_scheduler_status + supplier_workflow_ext.php's workflow_scheduler_status both
// name these exact routes) — until now those routes did not exist, so the engines were
// inert and the in-app banners truthfully said "no scheduler is wired".
//
// Each tick runs for ALL orgs (pass $orgId = null — every *_tick accepts it) and is
// idempotent + non-fatal by design, so a cron can hit them safely every 5–15 min.
// Schedule on the host, e.g.:
//   */10 * * * * curl -s "https://yourdomain.com/crons/notify-tick"   > /dev/null 2>&1
//   */10 * * * * curl -s "https://yourdomain.com/crons/workflow-tick" > /dev/null 2>&1
@$SECURE or die('Access Denied!');

// ALERT RULE ENGINE — fires supplier alert rules against new events since the cursor.
$router->get('/crons/notify-tick', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $r = function_exists('notify_tick') ? notify_tick($db, null) : ['rules_fired' => 0];
    echo json_encode([
        'status'      => true,
        'rules_fired' => $r['rules_fired'] ?? 0,
        'ran_at'      => $r['ran_at'] ?? date('Y-m-d H:i:s'),
    ]);
});

// WORKFLOW ENGINE — materialises due recurring tasks + runs workflow automation rules.
$router->get('/crons/workflow-tick', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $r = function_exists('workflow_tick') ? workflow_tick($db, null) : ['recurring_tasks_created' => 0, 'rule_actions_fired' => 0];
    echo json_encode([
        'status'                  => true,
        'recurring_tasks_created' => $r['recurring_tasks_created'] ?? 0,
        'rule_actions_fired'      => $r['rule_actions_fired'] ?? 0,
        'ran_at'                  => $r['ran_at'] ?? date('Y-m-d H:i:s'),
    ]);
});

// OUTBOUND WEBHOOK DELIVERY — POSTs pending supplier webhook deliveries (signed) to the
// supplier's own endpoint URLs, with retry accounting. The enqueue side already records
// deliveries; this is the worker that actually sends them. Schedule, e.g.:
//   */5 * * * * curl -s "https://yourdomain.com/crons/supplier-webhooks" > /dev/null 2>&1
$router->get('/crons/supplier-webhooks', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $r = function_exists('supplier_webhook_dispatch_due')
        ? supplier_webhook_dispatch_due($db, $limit)
        : ['delivered' => 0, 'failed' => 0, 'skipped' => 0, 'considered' => 0];
    echo json_encode([
        'status'     => true,
        'delivered'  => $r['delivered'] ?? 0,
        'failed'     => $r['failed'] ?? 0,
        'skipped'    => $r['skipped'] ?? 0,
        'considered' => $r['considered'] ?? 0,
        'ran_at'     => date('Y-m-d H:i:s'),
    ]);
});
