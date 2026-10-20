<?php
// app/routes/admin/campaignsRoutes.php
// Phase 2 — Email Campaign Manager (admin). Draft -> send to newsletter
// subscribers via the active email provider (Resend). Inline batched send with
// a per-recipient log for resume safety (never double-sends).
@$SECURE or die('Access Denied!');

// How many recipients to process per /send trigger. Large lists finish across
// repeated clicks (the per-recipient unique log makes re-triggers resume, not
// re-send). Kept modest so one request never risks a PHP timeout.
if (!defined('CAMPAIGN_SEND_BATCH')) { define('CAMPAIGN_SEND_BATCH', 400); }

/**
 * Wrap a campaign's HTML body in a self-contained branded email shell
 * (logo + body + unsubscribe footer). Deliberately NOT the booking-email chrome,
 * which needs invoice-specific vars a campaign doesn't have. Replaces the
 * {{unsubscribe_url}} token (and injects a footer link if absent) and simple
 * {$subscriber.first_name}/{$settings.business_name} placeholders.
 */
if (!function_exists('campaign_render_email')) {
    function campaign_render_email($db, string $bodyHtml, array $subscriber, string $unsubscribeUrl): string
    {
        $brand = function_exists('getBrandName') ? (string) getBrandName($db) : (string) ($GLOBALS['app']['business_name'] ?? 'Our newsletter');
        $logo  = rtrim((string) root, '/') . '/uploads/global/logo.png';
        $first = trim((string) ($subscriber['first_name'] ?? ''));

        // Placeholder substitution (escaped where it's user-facing text).
        $repl = [
            '{$subscriber.first_name}' => htmlspecialchars($first !== '' ? $first : 'there', ENT_QUOTES),
            '{$settings.business_name}' => htmlspecialchars($brand, ENT_QUOTES),
            '{{unsubscribe_url}}'       => htmlspecialchars($unsubscribeUrl, ENT_QUOTES),
        ];
        $body = strtr($bodyHtml, $repl);

        // Ensure an unsubscribe link exists even if the author didn't place one.
        if (stripos($body, $unsubscribeUrl) === false) {
            $body .= '<p style="margin-top:28px;font-size:12px;color:#9ca3af;text-align:center">'
                   . 'You received this because you subscribed to ' . htmlspecialchars($brand, ENT_QUOTES) . '. '
                   . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '" style="color:#6b7280;text-decoration:underline">Unsubscribe</a>.'
                   . '</p>';
        }

        return '<div style="background:#f3f4f6;padding:24px 0;font-family:Arial,Helvetica,sans-serif">'
             . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">'
             . '<div style="padding:20px 28px;border-bottom:1px solid #eef2f7;text-align:center">'
             . '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . htmlspecialchars($brand, ENT_QUOTES) . '" style="height:40px;width:auto">'
             . '</div>'
             . '<div style="padding:28px;color:#111;font-size:15px;line-height:1.6">' . $body . '</div>'
             . '</div></div>';
    }
}

/**
 * Build the Medoo WHERE for a campaign's audience over newsletter_subscribers.
 * Only ever targets confirmed subscribers unless a broader status is chosen.
 */
if (!function_exists('campaign_audience_where')) {
    function campaign_audience_where(array $campaign): array
    {
        $where = [];
        $status = (string) ($campaign['audience_status'] ?? 'subscribed');
        $where['status'] = $status !== '' ? $status : 'subscribed';
        if (!empty($campaign['audience_country']))  { $where['country']  = $campaign['audience_country']; }
        if (!empty($campaign['audience_language'])) { $where['language'] = $campaign['audience_language']; }
        return $where;
    }
}

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

    $data = [
        'name'              => $name,
        'subject'           => $subj,
        'body_html'         => $body,
        'from_name'         => trim((string) ($_POST['from_name'] ?? '')) ?: null,
        'from_email'        => trim((string) ($_POST['from_email'] ?? '')) ?: null,
        'audience_status'   => in_array($_POST['audience_status'] ?? '', ['subscribed','pending','unsubscribed','bounced'], true) ? $_POST['audience_status'] : 'subscribed',
        'audience_country'  => trim((string) ($_POST['audience_country'] ?? '')) ?: null,
        'audience_language' => trim((string) ($_POST['audience_language'] ?? '')) ?: null,
    ];

    try {
        if ($id > 0) {
            $existing = $db->get('email_campaigns', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Campaign not found'); }
            // A sent/sending campaign is locked from edits.
            if (in_array($existing['status'], ['sending','sent'], true)) {
                throw new Exception('A campaign that is sending or already sent cannot be edited.');
            }
            $db->update('email_campaigns', $data, ['id' => $id]);
        } else {
            $data['status']     = 'draft';
            $data['created_by'] = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            $db->insert('email_campaigns', $data);
            $id = (int) $db->id();
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Campaign saved.'];
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

    try {
        $where = campaign_audience_where($campaign);
        $total = (int) $db->count('newsletter_subscribers', $where);

        // Recipients NOT already logged for this campaign (resume-safe).
        $alreadyRows = $db->select('email_campaign_log', 'email', ['campaign_id' => $id]) ?: [];
        $already = array_fill_keys(array_map('strval', $alreadyRows), true);

        $batchWhere = $where;
        $batchWhere['LIMIT'] = CAMPAIGN_SEND_BATCH + count($already) + 50; // over-fetch, then skip logged
        $subs = $db->select('newsletter_subscribers',
            ['id','email','first_name','unsubscribe_token'], $batchWhere) ?: [];

        $db->update('email_campaigns', ['status' => 'sending', 'total_recipients' => $total], ['id' => $id]);

        $processed = 0; $sentNow = 0; $failedNow = 0;
        foreach ($subs as $s) {
            if ($processed >= CAMPAIGN_SEND_BATCH) { break; }
            $email = strtolower(trim((string) ($s['email'] ?? '')));
            if ($email === '' || isset($already[$email])) { continue; } // skip blanks + already-sent
            $processed++;

            $token = (string) ($s['unsubscribe_token'] ?? '');
            $unsub = $token !== '' ? rtrim((string) root, '/') . '/newsletter/unsubscribe/' . rawurlencode($token) : rtrim((string) root, '/');
            $html  = campaign_render_email($db, (string) $campaign['body_html'], (array) $s, $unsub);

            $ok = false; $err = null;
            try {
                $ok = (bool) SENDEMAIL($email, (string) ($s['first_name'] ?? ''), (string) $campaign['subject'], $html);
            } catch (\Throwable $e) {
                $err = substr($e->getMessage(), 0, 240);
            }

            // Log per recipient (unique key campaign+email => resume-safe).
            try {
                $db->insert('email_campaign_log', [
                    'campaign_id'   => $id,
                    'subscriber_id' => (int) ($s['id'] ?? 0) ?: null,
                    'email'         => $email,
                    'status'        => $ok ? 'sent' : 'failed',
                    'error'         => $ok ? null : $err,
                ]);
            } catch (\Throwable $e) { /* duplicate (already logged) — ignore */ }

            $ok ? $sentNow++ : $failedNow++;
        }

        // Recompute totals from the authoritative log + decide final status.
        $sentTotal   = (int) $db->count('email_campaign_log', ['campaign_id' => $id, 'status' => 'sent']);
        $failedTotal = (int) $db->count('email_campaign_log', ['campaign_id' => $id, 'status' => 'failed']);
        $doneAll     = ($sentTotal + $failedTotal) >= $total;

        $db->update('email_campaigns', [
            'sent_count'   => $sentTotal,
            'failed_count' => $failedTotal,
            'status'       => $doneAll ? 'sent' : 'sending',
            'sent_at'      => $doneAll ? date('Y-m-d H:i:s') : $campaign['sent_at'],
        ], ['id' => $id]);

        if ($doneAll) {
            $_SESSION['message'] = ['type' => 'success', 'text' => "Campaign sent. Delivered: $sentTotal, failed: $failedTotal."];
        } else {
            $remaining = max(0, $total - $sentTotal - $failedTotal);
            $_SESSION['message'] = ['type' => 'success', 'text' => "Sent $sentNow this batch ($sentTotal/$total total). $remaining remaining — click Send again to continue."];
        }
        redirect(root . admin . '/campaigns/edit/' . $id);
    } catch (\Throwable $e) {
        $db->update('email_campaigns', ['status' => 'failed'], ['id' => $id]);
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Send failed: ' . $e->getMessage()];
        redirect(root . admin . '/campaigns/edit/' . $id);
    }
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
