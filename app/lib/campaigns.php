<?php
// app/lib/campaigns.php
// Shared email-campaign helpers used by BOTH the admin routes
// (app/routes/admin/campaignsRoutes.php) and the scheduler cron
// (app/routes/crons/campaignsDispatchRoutes.php). Kept in lib/ — not in the
// admin route file — so the public cron can reuse the send engine without
// pulling in (and re-registering) the admin routes. All functions are
// function_exists-guarded so a double include is a no-op.

// How many recipients to process per /send trigger (or per cron tick). Large
// lists finish across repeated triggers — the per-recipient unique log makes a
// re-trigger resume, not re-send. Modest so one request never risks a timeout.
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

/**
 * Send ONE batch of a campaign (resume-safe, idempotent per recipient) and update
 * the campaign row + counts. Shared by the admin "Send" button AND the scheduler
 * cron so both behave identically. Returns a result array:
 *   ['ok'=>bool, 'done'=>bool, 'total'=>int, 'sent_total'=>int, 'failed_total'=>int,
 *    'sent_now'=>int, 'remaining'=>int, 'error'=>?string]
 * The per-recipient UNIQUE log key (campaign_id,email) makes repeated calls resume
 * rather than re-send. Requires SENDEMAIL to exist (caller checks).
 */
if (!function_exists('campaign_run_batch')) {
    function campaign_run_batch($db, array $campaign): array
    {
        $id = (int) $campaign['id'];
        try {
            $where = campaign_audience_where($campaign);
            $total = (int) $db->count('newsletter_subscribers', $where);

            // Recipients NOT already logged for this campaign (resume-safe).
            $alreadyRows = $db->select('email_campaign_log', 'email', ['campaign_id' => $id]) ?: [];
            $already = array_fill_keys(array_map('strval', $alreadyRows), true);

            $batchWhere = $where;
            // Deterministic order is REQUIRED: the already-logged set is skipped in
            // PHP, so an unordered scan could keep returning the same already-logged
            // rows and starve new ones — a large list would then never finish. With
            // ORDER id ASC the logged rows are always a contiguous prefix and each
            // batch advances past them.
            $batchWhere['ORDER'] = ['id' => 'ASC'];
            $batchWhere['LIMIT'] = CAMPAIGN_SEND_BATCH + count($already) + 50;
            $subs = $db->select('newsletter_subscribers',
                ['id','email','first_name','unsubscribe_token'], $batchWhere) ?: [];

            $db->update('email_campaigns', ['status' => 'sending', 'total_recipients' => $total], ['id' => $id]);

            $processed = 0; $sentNow = 0;
            foreach ($subs as $s) {
                if ($processed >= CAMPAIGN_SEND_BATCH) { break; }
                $email = strtolower(trim((string) ($s['email'] ?? '')));
                if ($email === '' || isset($already[$email])) { continue; }
                $processed++;

                $token = (string) ($s['unsubscribe_token'] ?? '');
                $unsub = $token !== '' ? rtrim((string) root, '/') . '/newsletter/unsubscribe/' . rawurlencode($token) : rtrim((string) root, '/');
                $html  = campaign_render_email($db, (string) $campaign['body_html'], (array) $s, $unsub);

                // Bulk-sender compliance (Gmail/Yahoo 2024): a List-Unsubscribe header
                // + one-click POST variant, alongside the in-body link. Only set when
                // we have a real per-recipient token so the link actually works.
                $headers = [];
                if ($token !== '') {
                    $headers['List-Unsubscribe']      = '<' . $unsub . '>';
                    $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                }

                $ok = false; $err = null;
                try {
                    $ok = (bool) SENDEMAIL($email, (string) ($s['first_name'] ?? ''), (string) $campaign['subject'], $html, null, $headers);
                } catch (\Throwable $e) {
                    $err = substr($e->getMessage(), 0, 240);
                }

                try {
                    $db->insert('email_campaign_log', [
                        'campaign_id'   => $id,
                        'subscriber_id' => (int) ($s['id'] ?? 0) ?: null,
                        'email'         => $email,
                        'status'        => $ok ? 'sent' : 'failed',
                        'error'         => $ok ? null : $err,
                    ]);
                } catch (\Throwable $e) { /* duplicate — ignore */ }

                if ($ok) { $sentNow++; }
            }

            $sentTotal   = (int) $db->count('email_campaign_log', ['campaign_id' => $id, 'status' => 'sent']);
            $failedTotal = (int) $db->count('email_campaign_log', ['campaign_id' => $id, 'status' => 'failed']);
            $doneAll     = ($sentTotal + $failedTotal) >= $total;

            $db->update('email_campaigns', [
                'sent_count'   => $sentTotal,
                'failed_count' => $failedTotal,
                'status'       => $doneAll ? 'sent' : 'sending',
                'sent_at'      => $doneAll ? date('Y-m-d H:i:s') : $campaign['sent_at'],
            ], ['id' => $id]);

            return [
                'ok' => true, 'done' => $doneAll, 'total' => $total,
                'sent_total' => $sentTotal, 'failed_total' => $failedTotal,
                'sent_now' => $sentNow, 'remaining' => max(0, $total - $sentTotal - $failedTotal),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $db->update('email_campaigns', ['status' => 'failed'], ['id' => $id]);
            return ['ok' => false, 'done' => false, 'total' => 0, 'sent_total' => 0,
                    'failed_total' => 0, 'sent_now' => 0, 'remaining' => 0, 'error' => $e->getMessage()];
        }
    }
}
