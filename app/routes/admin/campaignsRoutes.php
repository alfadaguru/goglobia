<?php
// app/routes/admin/campaignsRoutes.php
// Phase 2 — Email Campaign Manager (admin). Draft -> send to newsletter
// subscribers via the active email provider (Resend). Inline batched send with
// a per-recipient log for resume safety (never double-sends).
@$SECURE or die('Access Denied!');


// Shared campaign send engine + render/audience helpers (also used by the
// scheduler cron). Defines CAMPAIGN_SEND_BATCH, campaign_render_email(),
// campaign_audience_where(), campaign_run_batch() — all function_exists-guarded.
require_once __DIR__ . '/../../lib/campaigns.php';


// ================================ LIST
$router->get(admin.'/campaigns', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignSchema'))  { ensureCampaignSchema($db); }
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }
    $title = 'Email Campaigns'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaigns/campaigns.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/campaigns/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignSchema')) { ensureCampaignSchema($db); }
    $title = 'New Campaign'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaigns/campaigns-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/campaigns/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignSchema')) { ensureCampaignSchema($db); }
    $title = 'Edit Campaign'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaigns/campaigns-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (create/update draft)
$router->post(admin.'/campaigns/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureCampaignSchema')) { ensureCampaignSchema($db); }

    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $subj = trim((string) ($_POST['subject'] ?? ''));
    $body = (string) ($_POST['body_html'] ?? '');

    if ($name === '' || $subj === '' || trim(strip_tags($body)) === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Name, subject and body are all required.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/campaigns');
        return;
    }

    // Audience groups: whitelist against the known set; default to subscribers if
    // nothing ticked (keeps a campaign from targeting "everyone" by accident).
    $knownGroups = defined('CAMPAIGN_GROUPS') ? explode(',', CAMPAIGN_GROUPS) : ['subscribers','customers','agents','suppliers','staff'];
    $postedGroups = (array) ($_POST['audience_groups'] ?? []);
    $groups = array_values(array_intersect(array_map('strval', $postedGroups), $knownGroups));
    if (empty($groups)) { $groups = ['subscribers']; }
    $role = strtolower(trim((string) ($_POST['audience_role'] ?? '')));
    if (!in_array($role, ['admin', 'employee'], true)) { $role = null; }

    $data = [
        'name'              => $name,
        'subject'           => $subj,
        'body_html'         => $body,
        'from_name'         => trim((string) ($_POST['from_name'] ?? '')) ?: null,
        'from_email'        => trim((string) ($_POST['from_email'] ?? '')) ?: null,
        'audience_groups'   => json_encode($groups),
        'audience_role'     => $role,
        'audience_status'   => in_array($_POST['audience_status'] ?? '', ['subscribed','pending','unsubscribed','bounced'], true) ? $_POST['audience_status'] : 'subscribed',
        'audience_country'  => trim((string) ($_POST['audience_country'] ?? '')) ?: null,
        'audience_language' => trim((string) ($_POST['audience_language'] ?? '')) ?: null,
    ];

    // Optional scheduling. A datetime-local value in the future flips the campaign
    // to 'scheduled' (the cron dispatches it); clearing it returns the draft. A
    // past/invalid time is treated as "no schedule" (admin can just hit Send).
    $scheduleRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
    $scheduleAt  = null;
    if ($scheduleRaw !== '') {
        $ts = strtotime($scheduleRaw);
        if ($ts !== false && $ts > time() + 30) { $scheduleAt = date('Y-m-d H:i:s', $ts); }
    }

    try {
        if ($id > 0) {
            $existing = $db->get('email_campaigns', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Campaign not found'); }
            // A sent/sending campaign is locked from edits.
            if (in_array($existing['status'], ['sending','sent'], true)) {
                throw new Exception('A campaign that is sending or already sent cannot be edited.');
            }
            // Schedule state transitions for an editable (draft/scheduled/failed) campaign.
            $data['scheduled_at'] = $scheduleAt;
            $data['status']       = $scheduleAt !== null ? 'scheduled' : 'draft';
            $db->update('email_campaigns', $data, ['id' => $id]);
        } else {
            $data['scheduled_at'] = $scheduleAt;
            $data['status']       = $scheduleAt !== null ? 'scheduled' : 'draft';
            $data['created_by']   = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            $db->insert('email_campaigns', $data);
            $id = (int) $db->id();
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => $scheduleAt !== null
            ? ('Campaign scheduled for ' . $scheduleAt . '.')
            : 'Campaign saved.'];
        redirect(root . admin . '/campaigns/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/campaigns');
    }
});

// ================================ SEND (inline batched, resume-safe)
$router->post(admin.'/campaigns/send', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureCampaignSchema'))  { ensureCampaignSchema($db); }
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }
    @set_time_limit(300);

    $id = (int) ($_POST['id'] ?? 0);
    $campaign = $id > 0 ? $db->get('email_campaigns', '*', ['id' => $id]) : null;
    if (!$campaign) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Campaign not found.'];
        redirect(root . admin . '/campaigns');
        return;
    }
    if ($campaign['status'] === 'sent') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This campaign has already been fully sent.'];
        redirect(root . admin . '/campaigns/edit/' . $id);
        return;
    }
    if (!function_exists('SENDEMAIL')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Email sending is not available (SENDEMAIL missing).'];
        redirect(root . admin . '/campaigns/edit/' . $id);
        return;
    }

    // Sending is an explicit "send now" — clear any schedule so it doesn't also
    // get picked up by the cron later.
    if (!empty($campaign['scheduled_at'])) {
        $db->update('email_campaigns', ['scheduled_at' => null], ['id' => $id]);
        $campaign['scheduled_at'] = null;
    }

    $r = campaign_run_batch($db, $campaign);
    if (!$r['ok']) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Send failed: ' . $r['error']];
    } elseif ($r['done']) {
        $_SESSION['message'] = ['type' => 'success', 'text' => "Campaign sent. Delivered: {$r['sent_total']}, failed: {$r['failed_total']}."];
    } else {
        $_SESSION['message'] = ['type' => 'success', 'text' => "Sent {$r['sent_now']} this batch ({$r['sent_total']}/{$r['total']} total). {$r['remaining']} remaining — click Send again to continue."];
    }
    redirect(root . admin . '/campaigns/edit/' . $id);
});

// ================================ TEST SEND (one email to a chosen address)
// Renders the campaign exactly as a real send would (branded shell + a sample
// unsubscribe link) and sends a single copy so the admin can proof it. Does NOT
// touch the campaign status or the send log.
$router->post(admin.'/campaigns/test', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureCampaignSchema')) { ensureCampaignSchema($db); }

    $id = (int) ($_POST['id'] ?? 0);
    $campaign = $id > 0 ? $db->get('email_campaigns', '*', ['id' => $id]) : null;
    if (!$campaign) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Save the campaign first, then send a test.'];
        redirect(root . admin . '/campaigns' . ($id > 0 ? '/edit/' . $id : ''));
        return;
    }

    // Default to the logged-in admin's email; allow an explicit override.
    $to = strtolower(trim((string) ($_POST['test_email'] ?? '')));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $to = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
        if ($to === '') {
            $me = (int) ($_SESSION['user_id'] ?? 0);
            if ($me > 0) { $to = strtolower(trim((string) ($db->get('users', 'email', ['id' => $me]) ?? ''))); }
        }
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a valid test email address.'];
        redirect(root . admin . '/campaigns/edit/' . $id);
        return;
    }
    if (!function_exists('SENDEMAIL')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Email sending is not available (SENDEMAIL missing).'];
        redirect(root . admin . '/campaigns/edit/' . $id);
        return;
    }

    // A sample subscriber + a well-formed-but-nonexistent unsubscribe token (all
    // zeros) — this is a proof, not a real recipient, so the link resolves to the
    // "link not found" page rather than unsubscribing anyone real.
    // Render with the campaign's REAL marketing status so the test matches the live
    // send: an operational-only campaign (agents/suppliers/staff) shows NO
    // unsubscribe footer, a marketing one does.
    $marketing = false;
    if (function_exists('campaign_selected_groups') && function_exists('campaign_marketing_groups')) {
        $marketing = (bool) array_intersect(campaign_selected_groups($campaign), campaign_marketing_groups());
    }
    $sample = ['first_name' => 'there', 'email' => $to];
    $unsub  = rtrim((string) root, '/') . '/newsletter/unsubscribe/' . str_repeat('0', 32);
    $html   = campaign_render_email($db, (string) $campaign['body_html'], $sample, $unsub, $marketing);

    $ok = false;
    try {
        $ok = (bool) SENDEMAIL($to, 'Test', '[TEST] ' . (string) $campaign['subject'], $html);
    } catch (\Throwable $e) {
        error_log('campaigns/test: ' . $e->getMessage());
    }
    $_SESSION['message'] = $ok
        ? ['type' => 'success', 'text' => 'Test email sent to ' . $to . '.']
        : ['type' => 'error', 'text' => 'Could not send the test email — check your email provider settings.'];
    redirect(root . admin . '/campaigns/edit/' . $id);
});

// ================================ DELETE
$router->post(admin.'/campaigns/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id > 0) {
            $db->delete('email_campaigns', ['id' => $id]);
            $db->delete('email_campaign_log', ['campaign_id' => $id]);
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Campaign deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/campaigns');
});

// ============================================================================
// SUBSCRIBERS — list / search / export / per-row actions.
// Gives admins visibility into the audience the campaigns target.
// ============================================================================

// ------------------------------- LIST (filter + search + paginate)
$router->get(admin.'/newsletter/subscribers', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }
    $title = 'Subscribers'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaigns/subscribers.php";
    require_once views."includes/footer.php";
});

// ------------------------------- EXPORT (CSV of the current filter)
$router->get(admin.'/newsletter/subscribers/export', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    $where = [];
    $status = (string) ($_GET['status'] ?? '');
    if (in_array($status, ['pending','subscribed','unsubscribed','bounced'], true)) { $where['status'] = $status; }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') { $where['email[~]'] = $q; }
    $where['ORDER'] = ['id' => 'DESC'];

    $rows = $db->select('newsletter_subscribers',
        ['email','first_name','status','source','country','language','confirmed_at','unsubscribed_at','created_at'],
        $where) ?: [];

    $csv = array_map(static function ($r) {
        return [
            $r['email'] ?? '', $r['first_name'] ?? '', $r['status'] ?? '',
            $r['source'] ?? '', $r['country'] ?? '', $r['language'] ?? '',
            $r['confirmed_at'] ?? '', $r['unsubscribed_at'] ?? '', $r['created_at'] ?? '',
        ];
    }, $rows);

    $header = ['Email','First name','Status','Source','Country','Language','Confirmed at','Unsubscribed at','Created at'];
    if (function_exists('_report_stream_csv')) {
        _report_stream_csv('subscribers-' . date('Ymd-His') . '.csv', $header, $csv);
    }
    // Self-sufficient fallback (the reports helper lives in a file not loaded on a
    // campaigns request). Carries the SAME formula-injection guard: a cell starting
    // with = + - @ or tab/CR would become a live formula in Excel/Sheets, so prefix
    // it with a quote to force text.
    $csvSafe = static function ($v): string {
        $v = (string) $v;
        if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) { return "'" . $v; }
        return $v;
    };
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map($csvSafe, $header));
    foreach ($csv as $r) { fputcsv($out, array_map($csvSafe, $r)); }
    fclose($out);
    exit;
});

// ------------------------------- PER-ROW ACTION (unsubscribe / resubscribe / delete)
$router->post(admin.'/newsletter/subscribers/action', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($id <= 0) { throw new Exception('Invalid subscriber.'); }
        if ($action === 'unsubscribe') {
            $db->update('newsletter_subscribers',
                ['status' => 'unsubscribed', 'unsubscribed_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            $msg = 'Subscriber unsubscribed.';
        } elseif ($action === 'resubscribe') {
            // Admin manually re-activating a previously-opted-in address. Clears the
            // unsubscribed marker; does NOT re-run double opt-in (admin override).
            $db->update('newsletter_subscribers',
                ['status' => 'subscribed', 'unsubscribed_at' => null, 'confirmed_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            $msg = 'Subscriber re-subscribed.';
        } elseif ($action === 'delete') {
            $db->delete('newsletter_subscribers', ['id' => $id]);
            $msg = 'Subscriber deleted.';
        } else {
            throw new Exception('Unknown action.');
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => $msg];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/newsletter/subscribers');
});
