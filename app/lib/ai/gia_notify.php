<?php

namespace App\lib\ai;

// ============================================================================
// FILE: app/lib/ai/gia_notify.php
// GIA outbound notifications — staff alerts (a chat needs a human) and lead
// nurture (follow up a captured lead with their options). Reuses the platform's
// existing senders (SENDEMAIL / SENDWHATSAPP, which self-resolve the active
// provider from settings). Everything here is FAIL-SOFT and deduped — it must
// never block or break the chat, and never double-send for one conversation/lead.
// ============================================================================

if (!function_exists('App\\lib\\ai\\gia_alert_staff_needs_human')) {
    /**
     * Email the ops inbox that a GIA conversation needs a human, with a deep link
     * to the admin conversation view. Dedups via gia_conversations.needs_human
     * (caller sets it) + a per-conversation session guard so repeated triggers in
     * one request don't spam. Returns true if an alert was sent.
     *
     * @param object $db
     * @param int    $convId
     * @param string $reason  short note (e.g. "traveller asked for a human")
     */
    function gia_alert_staff_needs_human($db, int $convId, string $reason = ''): bool
    {
        if ($convId <= 0) {
            return false;
        }
        // Per-request dedup (one alert per conversation per page-load at most).
        if (!isset($_SESSION['gia_alerted']) || !is_array($_SESSION['gia_alerted'])) {
            $_SESSION['gia_alerted'] = [];
        }
        if (!empty($_SESSION['gia_alerted'][$convId])) {
            return false;
        }

        try {
            $settings = $db->get('settings', ['booking_notification_email', 'email', 'name'], ['id' => 1]) ?: [];
            $to = trim((string) ($settings['booking_notification_email'] ?? ''));
            if ($to === '') {
                $to = trim((string) ($settings['email'] ?? ''));
            }
            if ($to === '' || !function_exists('SENDEMAIL')) {
                return false;
            }

            $conv = $db->get('gia_conversations', ['conv_key', 'context', 'last_message'], ['id' => $convId]) ?: [];
            $base = defined('root') ? root : '/';
            $link = $base . (defined('admin') ? admin : 'admin') . '/gia/conversation/' . $convId;
            $siteName = (string) ($settings['name'] ?? 'GoGlobia');

            $ctx = trim((string) ($conv['context'] ?? '')) ?: 'general';
            $last = trim((string) ($conv['last_message'] ?? ''));
            $reasonLine = $reason !== '' ? $reason : 'A traveller would like to speak with a person.';

            $subject = 'GIA: a chat needs a human (#' . $convId . ')';
            $html = '<p><strong>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' — GIA handoff request</strong></p>'
                . '<p>' . htmlspecialchars($reasonLine, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Topic: ' . htmlspecialchars($ctx, ENT_QUOTES, 'UTF-8') . '<br>'
                . ($last !== '' ? 'Last message: ' . htmlspecialchars(mb_substr($last, 0, 200), ENT_QUOTES, 'UTF-8') . '<br>' : '')
                . 'Conversation #' . $convId . '</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Open the conversation &amp; take over &rarr;</a></p>';

            $ok = (bool) SENDEMAIL($to, 'Support', $subject, $html);

            // ALSO WhatsApp any ONLINE support agent who can take over a chat, with
            // a deep link to the desk conversation (spec §4 "nudge an online agent").
            // Best-effort; never blocks the handoff. The email above remains the
            // guaranteed fallback.
            try {
                gia_nudge_online_agents($db, $convId, $reasonLine, $link);
            } catch (\Throwable $e) {
                error_log('[gia] online-agent nudge: ' . $e->getMessage());
            }

            if ($ok) {
                $_SESSION['gia_alerted'][$convId] = true;
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('[gia] staff alert: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_nudge_online_agents')) {
    /**
     * WhatsApp every ONLINE support agent who can take over a chat (support_desk.edit)
     * with a deep link to the desk conversation. "Online" = a fresh heartbeat
     * (support_who_online). Reuses SENDWHATSAPP (self-resolves the provider). Caps at
     * a handful of agents to avoid spam; fail-soft per-agent. Returns the count nudged.
     *
     * support_who_online() + admin_user_can() are GLOBAL (namespace-free), hence the
     * leading backslash. staff_id in the roster IS users.user_id after the RBAC
     * consolidation, so it doubles as the capability + phone lookup key.
     */
    function gia_nudge_online_agents($db, int $convId, string $reason, string $deskLink): int
    {
        if (!function_exists('support_who_online') || !function_exists('SENDWHATSAPP')) {
            return 0;
        }
        $nudged = 0;
        $roster = \support_who_online($db);
        if (!is_array($roster) || $roster === []) {
            return 0;
        }
        // Desk deep link (the live chat desk, not the raw gia conversation view).
        $base = defined('root') ? root : '/';
        $desk = $base . (defined('admin') ? admin : 'admin') . '/support/desk';

        foreach ($roster as $r) {
            if ($nudged >= 5) { break; } // cap — don't blast a big team
            $uid = (string) ($r['staff_id'] ?? '');
            if ($uid === '') { continue; }
            // Only agents allowed to take over a chat.
            if (function_exists('admin_user_can') && !\admin_user_can($db, $uid, 'support_desk', 'edit')) {
                continue;
            }
            // Resolve a nudge phone: dedicated nudge_phone, else the account phone.
            $u = $db->get('users', ['first_name', 'phone', 'nudge_phone'], ['user_id' => $uid]);
            if (!$u) { continue; }
            $phone = trim((string) ($u['nudge_phone'] ?? '')) ?: trim((string) ($u['phone'] ?? ''));
            if ($phone === '') { continue; }
            $name = trim((string) ($u['first_name'] ?? '')) ?: 'there';
            $msg = 'Hi ' . $name . " — a customer needs a human on GIA chat #" . $convId . '. '
                . ($reason !== '' ? $reason . ' ' : '')
                . 'Open the desk to take over: ' . $desk;
            try { \SENDWHATSAPP($phone, $name, $msg); $nudged++; } catch (\Throwable $e) { /* per-agent soft */ }
        }
        return $nudged;
    }
}

if (!function_exists('App\\lib\\ai\\gia_nurture_lead')) {
    /**
     * Follow up a freshly captured lead with their options: an email (always, if
     * an address was given) and a WhatsApp nudge (if a phone was given and the
     * WhatsApp provider is configured). Deduped via the lead row (sets a 'note'
     * marker so we never re-send for the same lead). Fail-soft.
     *
     * @param object $db
     * @param int    $leadId
     * @param int    $convId  the conversation this lead came from (for context)
     */
    function gia_nurture_lead($db, int $leadId, int $convId = 0): bool
    {
        if ($leadId <= 0) {
            return false;
        }
        try {
            $lead = $db->get('gia_leads', ['id', 'name', 'email', 'phone', 'note', 'context'], ['id' => $leadId]) ?: [];
            if (!$lead) {
                return false;
            }
            // Dedup: only nurture once per lead.
            if (strpos((string) ($lead['note'] ?? ''), 'nurtured') !== false) {
                return false;
            }
            $email = trim((string) ($lead['email'] ?? ''));
            $phone = trim((string) ($lead['phone'] ?? ''));
            $name = trim((string) ($lead['name'] ?? '')) ?: 'there';
            if ($email === '' && $phone === '') {
                return false;
            }

            $settings = $db->get('settings', ['name'], ['id' => 1]) ?: [];
            $siteName = (string) ($settings['name'] ?? 'GoGlobia');
            $base = defined('root') ? root : '/';

            // Build a short summary of what they were looking at, from the saved
            // search context (never invents prices; just the topic + a link back).
            $topic = trim((string) ($lead['context'] ?? '')) ?: 'your trip';
            $summary = gia_lead_summary($db, $convId);

            $sent = false;

            if ($email !== '' && function_exists('SENDEMAIL')) {
                $subject = 'Your ' . $topic . ' options with ' . $siteName;
                $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>Thanks for chatting with GIA! Here is what we looked at so you have it handy:</p>'
                    . ($summary !== '' ? '<div>' . $summary . '</div>' : '')
                    . '<p>Ready to continue? Just head back to <a href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</a> and GIA will pick up where you left off.</p>'
                    . '<p>Safe travels,<br>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>';
                $sent = (bool) SENDEMAIL($email, $name, $subject, $html) || $sent;
            }

            if ($phone !== '' && function_exists('SENDWHATSAPP')) {
                $msg = 'Hi ' . $name . "! Thanks for chatting with " . $siteName . ' about ' . $topic
                    . ". Your options are saved — reply here or visit " . $base . ' to continue and book. ✈️';
                // WhatsApp is best-effort: no-op if the provider isn't configured.
                try { SENDWHATSAPP($phone, $name, $msg); $sent = true; } catch (\Throwable $e) {}
            }

            if ($sent) {
                $note = trim((string) ($lead['note'] ?? ''));
                $note = ($note !== '' ? $note . '; ' : '') . 'nurtured ' . date('Y-m-d H:i');
                $db->update('gia_leads', ['note' => substr($note, 0, 255)], ['id' => $leadId]);
            }
            return $sent;
        } catch (\Throwable $e) {
            error_log('[gia] nurture lead: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_nurture_sweep')) {
    /**
     * Cron worker: re-engage QUIET, un-converted leads from ad campaigns with ONE warm
     * WhatsApp/email follow-up (via gia_nurture_lead, which is itself idempotent per lead
     * and best-effort). Scope — a conversation qualifies when:
     *   - it is attributed to a campaign (campaign_id set) that is STILL active/in-window,
     *   - it has NOT converted (booking_count = 0),
     *   - it has a resolved lead (lead_id) that has a phone and/or email,
     *   - it went quiet: last_at is older than $quietHours but within $lookbackHours
     *     (so we chase recent drop-offs, never an ancient backlog),
     *   - the lead is intent != 'serious' is NOT required — we skip ones already nurtured
     *     (gia_nurture_lead dedups on the lead 'note'), and never nurture a 'not_ready'
     *     lead who explicitly opted out (treated as nurtured).
     * Idempotent + bounded. Returns counts. Flights-style best-effort (never throws).
     *
     * @param object $db
     * @param int    $quietHours    min hours of silence before a nudge (default 6)
     * @param int    $lookbackHours only consider convs active within this window (default 72)
     */
    function gia_nurture_sweep($db, int $quietHours = 6, int $lookbackHours = 72): array
    {
        $sent = 0; $scanned = 0; $skipped = 0;
        if (!function_exists('ensureGiaAnalyticsSchema')) { return ['ok' => false]; }
        try {
            ensureGiaAnalyticsSchema($db);
            if (function_exists('ensureAdCampaignSchema')) { ensureAdCampaignSchema($db); }

            $now = time();
            $quietBefore = date('Y-m-d H:i:s', $now - $quietHours * 3600);
            $lookback    = date('Y-m-d H:i:s', $now - $lookbackHours * 3600);

            // Candidate conversations: campaign-attributed, unconverted, quiet, recent,
            // with a lead. Bounded scan.
            $rows = $db->select('gia_conversations',
                ['id', 'lead_id', 'campaign_id', 'booking_count', 'last_at', 'started_at', 'staff_active', 'closed'],
                [
                    'campaign_id[!]' => null,
                    'booking_count'  => 0,
                    'lead_id[!]'     => null,
                    'staff_active'   => 0,
                    'last_at[<=]'    => $quietBefore,
                    'last_at[>=]'    => $lookback,
                    'ORDER'          => ['last_at' => 'DESC'],
                    'LIMIT'          => 300,
                ]) ?: [];

            foreach ($rows as $c) {
                $scanned++;
                $campaignId = (int) ($c['campaign_id'] ?? 0);
                $leadId     = (int) ($c['lead_id'] ?? 0);
                $convId     = (int) ($c['id'] ?? 0);
                if ($campaignId <= 0 || $leadId <= 0) { $skipped++; continue; }

                // Campaign must still be active + in its time window (don't chase leads
                // for an offer that already ended).
                $camp = $db->get('gia_ad_campaigns', ['id', 'active', 'starts_at', 'ends_at'], ['id' => $campaignId]);
                if (!$camp || (int) ($camp['active'] ?? 0) !== 1) { $skipped++; continue; }
                $nowStr = date('Y-m-d H:i:s');
                if (!empty($camp['starts_at']) && $camp['starts_at'] > $nowStr) { $skipped++; continue; }
                if (!empty($camp['ends_at'])   && $camp['ends_at']   < $nowStr) { $skipped++; continue; }

                // Skip leads that explicitly said not now (respect their wish).
                try {
                    $lead = $db->get('gia_leads', ['intent'], ['id' => $leadId]);
                    if ($lead && (string) ($lead['intent'] ?? '') === 'not_ready') { $skipped++; continue; }
                } catch (\Throwable $e) { /* intent col may be absent on old installs — proceed */ }

                // gia_nurture_lead is idempotent (dedups on the lead 'note') + best-effort.
                if (gia_nurture_lead($db, $leadId, $convId)) { $sent++; }
                else { $skipped++; }
            }

            return ['ok' => true, 'scanned' => $scanned, 'sent' => $sent, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            error_log('[gia] nurture_sweep: ' . $e->getMessage());
            return ['ok' => false];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_lead_summary')) {
    /**
     * A short HTML summary of what the traveller searched/selected in this
     * conversation, from the recorded gia_events. Grounded in logged data only —
     * no invented prices. Returns '' if nothing useful.
     */
    function gia_lead_summary($db, int $convId): string
    {
        if ($convId <= 0) {
            return '';
        }
        try {
            $events = $db->select('gia_events', ['type', 'module', 'amount', 'currency', 'meta'], [
                'conv_id' => $convId,
                'type' => ['search', 'checkout', 'book'],
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 6,
            ]) ?: [];
            if ($events === []) {
                return '';
            }
            $lines = [];
            $seen = [];
            foreach ($events as $ev) {
                $mod = (string) ($ev['module'] ?? '');
                $type = (string) ($ev['type'] ?? '');
                $amt = ($ev['amount'] ?? null);
                $cur = (string) ($ev['currency'] ?? '');
                $keyed = $type . '|' . $mod;
                if (isset($seen[$keyed])) {
                    continue;
                }
                $seen[$keyed] = true;
                if ($type === 'search' && $mod !== '') {
                    $lines[] = '• ' . ucfirst($mod) . ' options';
                } elseif ($type === 'checkout' && $amt !== null) {
                    $lines[] = '• A quote for ' . ($cur !== '' ? htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') . ' ' : '') . number_format((float) $amt, 2);
                } elseif ($type === 'book') {
                    $lines[] = '• A booking you started';
                }
            }
            if ($lines === []) {
                return '';
            }
            return '<p>' . implode('<br>', array_map(static fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $lines)) . '</p>';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
