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
    function campaign_render_email($db, string $bodyHtml, array $subscriber, string $unsubscribeUrl, bool $marketing = true): string
    {
        $brand = function_exists('getBrandName') ? (string) getBrandName($db) : (string) ($GLOBALS['app']['business_name'] ?? 'Our newsletter');
        $logo  = rtrim((string) root, '/') . '/uploads/global/logo.png';
        $first = trim((string) ($subscriber['first_name'] ?? ''));

        // Placeholder substitution (escaped where it's user-facing text). For a
        // non-marketing (operational/internal) send there is no unsubscribe URL, so
        // the {{unsubscribe_url}} token resolves to the brand home instead of a
        // broken/wrong opt-out.
        $repl = [
            '{$subscriber.first_name}' => htmlspecialchars($first !== '' ? $first : 'there', ENT_QUOTES),
            '{$settings.business_name}' => htmlspecialchars($brand, ENT_QUOTES),
            '{{unsubscribe_url}}'       => htmlspecialchars($unsubscribeUrl, ENT_QUOTES),
        ];
        $body = strtr($bodyHtml, $repl);

        // Append an unsubscribe footer ONLY for marketing sends that lack one.
        // Operational/internal mail (agents/suppliers/staff) gets no unsubscribe —
        // it's relationship email, not a promo.
        if ($marketing && stripos($body, $unsubscribeUrl) === false) {
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

// ---------------------------------------------------------------------------
// MULTI-AUDIENCE (Phase 5)
// A campaign targets one or more GROUPS; recipients are the de-duplicated union
// by email. Groups:
//   subscribers — newsletter_subscribers (opted-in; MARKETING)
//   customers   — users.role=customer (MARKETING)
//   agents      — users.role=agent      (operational)
//   suppliers   — users.role=supplier   (operational)
//   staff       — users.role in (admin, employee) (internal)
// "Marketing" groups get a one-click unsubscribe (header+footer) and honour the
// suppression list; operational/internal groups do not (they're relationship
// email, not promos). users.role casing is inconsistent in real data, so all
// role matches are case-insensitive.
// ---------------------------------------------------------------------------

if (!defined('CAMPAIGN_GROUPS')) {
    define('CAMPAIGN_GROUPS', 'subscribers,customers,agents,suppliers,staff');
}
if (!function_exists('campaign_marketing_groups')) {
    // Which selected groups are MARKETING (get unsubscribe + honour suppression).
    function campaign_marketing_groups(): array { return ['subscribers', 'customers']; }
}

/** Parse the stored audience_groups (JSON array) into a clean, known set. */
if (!function_exists('campaign_selected_groups')) {
    function campaign_selected_groups(array $campaign): array
    {
        $raw = $campaign['audience_groups'] ?? '';
        $groups = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) { $groups = $decoded; }
        } elseif (is_array($raw)) {
            $groups = $raw;
        }
        $known = explode(',', CAMPAIGN_GROUPS);
        $groups = array_values(array_intersect(array_map('strval', $groups), $known));
        // Back-compat: a campaign saved before Phase 5 (no groups) = subscribers only.
        if (empty($groups)) { $groups = ['subscribers']; }
        return $groups;
    }
}

/**
 * Signed one-click opt-out token for USER recipients (customers/agents/etc.) who
 * have no newsletter unsubscribe_token. token = hex(lower(email)) . '.' . hmac.
 * Verified by the /newsletter/optout route, which adds the email to
 * campaign_suppressions. Keyed on the server-only secret (mirrors
 * supplier_internal_secret()).
 */
if (!function_exists('campaign_optout_secret')) {
    function campaign_optout_secret(): string
    {
        if (function_exists('supplier_internal_secret')) { return supplier_internal_secret(); }
        $env = @parse_ini_file(dirname(__DIR__, 2) . '/.env');
        $s = is_array($env) ? trim((string) ($env['JWT_SECRET'] ?? '')) : '';
        return $s !== '' ? $s : hash('sha256', 'v10-campaign-optout');
    }
}
if (!function_exists('campaign_optout_token')) {
    function campaign_optout_token(string $email): string
    {
        $email = strtolower(trim($email));
        $sig = hash_hmac('sha256', 'campaign-optout:' . $email, campaign_optout_secret());
        return bin2hex($email) . '.' . $sig;
    }
}
if (!function_exists('campaign_optout_verify')) {
    /** Return the email if the token is valid, else ''. Constant-time compare. */
    function campaign_optout_verify(string $token): string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) { return ''; }
        [$hex, $sig] = $parts;
        if ($hex === '' || !ctype_xdigit($hex)) { return ''; }
        $email = @hex2bin($hex);
        if ($email === false || $email === '') { return ''; }
        $email = strtolower(trim($email));
        $expected = hash_hmac('sha256', 'campaign-optout:' . $email, campaign_optout_secret());
        return hash_equals($expected, (string) $sig) ? $email : '';
    }
}

/**
 * Resolve a campaign's recipients across ALL selected groups into a single,
 * de-duplicated (by lowercased email) list. Each recipient:
 *   ['email','first_name','unsub_url'(string|null),'is_marketing'(bool),
 *    'subscriber_id'(int|null)]
 * Honours the campaign's country/language filters on EVERY group; excludes
 * banned / non-active users; excludes suppressed emails from marketing groups;
 * skips a group's subscriber-status filter for user groups (that status enum is
 * newsletter-specific). Ordered by email for deterministic batching.
 *
 * NOTE: for very large user bases this loads candidate emails into PHP to dedupe.
 * That mirrors the existing subscriber flow and is fine for the batch sizes here;
 * if a deployment ever has hundreds of thousands of users, revisit with a UNION
 * query. Flagged rather than silently assumed.
 */
if (!function_exists('campaign_resolve_recipients')) {
    function campaign_resolve_recipients($db, array $campaign): array
    {
        $groups      = campaign_selected_groups($campaign);
        $marketingGs = campaign_marketing_groups();
        $country     = trim((string) ($campaign['audience_country'] ?? ''));
        $language    = trim((string) ($campaign['audience_language'] ?? ''));
        $role        = strtolower(trim((string) ($campaign['audience_role'] ?? '')));

        // Suppression set (lowercased emails) — only applied to marketing groups.
        $suppressed = [];
        try {
            foreach (($db->select('campaign_suppressions', 'email') ?: []) as $e) {
                $suppressed[strtolower(trim((string) $e))] = true;
            }
        } catch (\Throwable $e) { /* table may not exist yet — treat as empty */ }

        $byEmail = []; // email => recipient (first writer wins; marketing OR'd in)

        $add = function (string $email, string $first, $unsubUrl, bool $marketing, $subId) use (&$byEmail) {
            $email = strtolower(trim($email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return; }
            if (!isset($byEmail[$email])) {
                $byEmail[$email] = [
                    'email' => $email, 'first_name' => $first,
                    'unsub_url' => $unsubUrl, 'is_marketing' => $marketing,
                    'subscriber_id' => $subId,
                ];
            } else {
                // Same person in two groups: if ANY source is marketing, the send
                // must carry an unsubscribe (safer default), and keep a real token.
                if ($marketing) { $byEmail[$email]['is_marketing'] = true; }
                if (empty($byEmail[$email]['unsub_url']) && $unsubUrl) { $byEmail[$email]['unsub_url'] = $unsubUrl; }
                if (empty($byEmail[$email]['subscriber_id']) && $subId) { $byEmail[$email]['subscriber_id'] = $subId; }
            }
        };

        $base = rtrim((string) root, '/');

        // ---- Group: newsletter subscribers -------------------------------------
        if (in_array('subscribers', $groups, true)) {
            $w = ['status' => ($campaign['audience_status'] ?: 'subscribed')];
            if ($country !== '')  { $w['country']  = $country; }
            if ($language !== '') { $w['language'] = $language; }
            foreach (($db->select('newsletter_subscribers',
                        ['id','email','first_name','unsubscribe_token'], $w) ?: []) as $s) {
                $em = strtolower(trim((string) ($s['email'] ?? '')));
                if ($em === '' || isset($suppressed[$em])) { continue; } // marketing: honour suppression
                $tok = (string) ($s['unsubscribe_token'] ?? '');
                $url = $tok !== '' ? $base . '/newsletter/unsubscribe/' . rawurlencode($tok) : null;
                $add($em, (string) ($s['first_name'] ?? ''), $url, true, (int) ($s['id'] ?? 0) ?: null);
            }
        }

        // ---- Groups backed by the users table ----------------------------------
        // group key => (list of lowercased roles, is_marketing)
        $userGroups = [
            'customers' => [['customer'], true],
            'agents'    => [['agent'],    false],
            'suppliers' => [['supplier'], false],
            'staff'     => [['admin', 'employee'], false],
        ];
        foreach ($userGroups as $gkey => [$roles, $isMarketing]) {
            if (!in_array($gkey, $groups, true)) { continue; }
            // Staff-role override: if the campaign picked a specific staff role, narrow to it.
            if ($gkey === 'staff' && $role !== '' && in_array($role, ['admin', 'employee'], true)) {
                $roles = [$role];
            }
            // Only mailable users. Role is matched case-insensitively in PHP below
            // (users.role casing is inconsistent in real data, e.g. 'Agent' vs
            // 'agent'), which also keeps the query parameterized with no raw SQL.
            $w = [
                'banned' => 0,
                'status' => 'active',
            ];
            if ($country !== '')  { $w['country']  = $country; }
            if ($language !== '') { $w['language'] = $language; }
            $rows = $db->select('users', ['id','email','first_name','role'], $w) ?: [];
            foreach ($rows as $u) {
                if (!in_array(strtolower(trim((string) ($u['role'] ?? ''))), $roles, true)) { continue; }
                $em = strtolower(trim((string) ($u['email'] ?? '')));
                if ($em === '') { continue; }
                if ($isMarketing && isset($suppressed[$em])) { continue; }
                // Marketing user groups (customers) get a signed opt-out link;
                // operational/internal groups get no unsubscribe link.
                $url = $isMarketing ? ($base . '/newsletter/optout/' . campaign_optout_token($em)) : null;
                $add($em, (string) ($u['first_name'] ?? ''), $url, $isMarketing, null);
            }
        }

        // Deterministic order for resume-safe batching.
        $recipients = array_values($byEmail);
        usort($recipients, fn($a, $b) => strcmp($a['email'], $b['email']));
        return $recipients;
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
            // Resolve the FULL de-duplicated recipient set across all selected
            // groups, then skip the ones already logged for this campaign and
            // process up to CAMPAIGN_SEND_BATCH new ones. The resolver returns a
            // stable (email-sorted) list, so repeated triggers always advance.
            $recipients = campaign_resolve_recipients($db, $campaign);
            $total = count($recipients);

            $alreadyRows = $db->select('email_campaign_log', 'email', ['campaign_id' => $id]) ?: [];
            $already = array_fill_keys(array_map('strval', $alreadyRows), true);

            $db->update('email_campaigns', ['status' => 'sending', 'total_recipients' => $total], ['id' => $id]);

            $processed = 0; $sentNow = 0;
            foreach ($recipients as $r) {
                if ($processed >= CAMPAIGN_SEND_BATCH) { break; }
                $email = $r['email'];
                if ($email === '' || isset($already[$email])) { continue; }
                $processed++;

                // Unsubscribe link: real one for marketing recipients, a harmless
                // home link otherwise (operational/internal sends have no unsub).
                $unsub = $r['unsub_url'] ?: rtrim((string) root, '/');
                $html  = campaign_render_email($db, (string) $campaign['body_html'],
                            ['first_name' => $r['first_name'], 'email' => $email], $unsub, !empty($r['is_marketing']));

                // Bulk-sender compliance (Gmail/Yahoo 2024): List-Unsubscribe header +
                // one-click POST — ONLY for marketing recipients with a real opt-out
                // URL. Operational/internal mail carries no unsubscribe header.
                $headers = [];
                if (!empty($r['is_marketing']) && !empty($r['unsub_url'])) {
                    $headers['List-Unsubscribe']      = '<' . $r['unsub_url'] . '>';
                    $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                }

                $ok = false; $err = null;
                try {
                    $ok = (bool) SENDEMAIL($email, (string) $r['first_name'], (string) $campaign['subject'], $html, null, $headers);
                } catch (\Throwable $e) {
                    $err = substr($e->getMessage(), 0, 240);
                }

                try {
                    $db->insert('email_campaign_log', [
                        'campaign_id'   => $id,
                        'subscriber_id' => $r['subscriber_id'] ?: null,
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
