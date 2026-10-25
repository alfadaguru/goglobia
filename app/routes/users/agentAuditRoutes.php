<?php
// ============================================================================
// app/routes/users/agentAuditRoutes.php — Agent ERP P8: activity log view.
// Surfaces the agent_audit rows written throughout the portal (wallet.dva,
// staff.invite, booking.pay/resend/cancel/refund, branding.save, …). Owner-only
// — it is the agency's own audit trail of who did what.
//   GET /agent/activity → recent actions (owner-scoped)
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/agent/activity', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    // Owner-only: the full team activity trail is an owner view.
    if (empty($ctx['is_owner'])) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Only the agency owner can view the activity log.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $owner = (string) $ctx['owner'];

    $rows = [];
    try {
        $rows = $db->select('agent_audit',
            ['actor_user_id','action','detail','ip','created_at'],
            ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
    } catch (\Throwable $e) { error_log('agent/activity: ' . $e->getMessage()); }

    // Resolve actor names (small set).
    $names = [];
    foreach ($rows as $r) {
        $a = (string)($r['actor_user_id'] ?? '');
        if ($a !== '' && !isset($names[$a])) {
            try { $u = $db->get('users', ['first_name','last_name','email'], ['user_id' => $a]);
                  $names[$a] = $u ? (trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: (string)($u['email']??$a)) : $a; }
            catch (\Throwable $e) { $names[$a] = $a; }
        }
    }

    $title = 'Activity log';
    require_once views."includes/header.php";
    require_once views."agent/activity.php";
    require_once views."includes/footer.php";
});
