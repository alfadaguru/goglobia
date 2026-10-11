<?php
// FILE: app/lib/supplier_comms_ext.php
// MODULE 19 (Unified guest communication) — DEEPENING EXTENSION.
// Builds the genuine spec gaps on top of the existing guest-message log
// (guestmsg_* in supplier_guestservice.php): canned message TEMPLATES with
// placeholder substitution, AUTOMATED-message rules, and RESPONSE/DELIVERY
// TRACKING for outbound sends. Also provides an honest, DB-GATED outbound
// SEND that reuses the platform senders (SENDEMAIL/SENDSMS/SENDWHATSAPP).
//
// New tables (additive, lead applies DDL): stays_msg_templates,
// stays_msg_automations, stays_msg_delivery. The companion delivery table is
// keyed by the existing stays_guest_messages.id so we do NOT alter a table
// another file owns.
//
// Per-property; caller authorizes the stay via supplier_can('reservations',…).
// Defensive/non-fatal. NOT runtime-verified (no MySQL) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// TEMPLATES (canned messages)
// ---------------------------------------------------------------------------
if (!function_exists('msgtpl_channels')) {
    /** Channels a template can target. 'any' = usable on any channel. */
    function msgtpl_channels(): array { return ['any'=>'Any','email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS']; }
}

if (!function_exists('msgtpl_add')) {
    /** Create a canned template scoped to a property. Returns new id or 0. */
    function msgtpl_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        if ($stayId <= 0 || $name === '' || $body === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $ch = isset(msgtpl_channels()[$d['channel'] ?? '']) ? $d['channel'] : 'any';
            $db->insert('stays_msg_templates', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'name'       => substr($name, 0, 120),
                'channel'    => $ch,
                'subject'    => substr(trim((string) ($d['subject'] ?? '')), 0, 191) ?: null,
                'body'       => substr($body, 0, 4000),
                'active'     => !empty($d['active']) ? 1 : 1,
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('msgtpl_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('msgtpl_delete')) {
    /** Soft-remove a template (property-scoped). */
    function msgtpl_delete($db, int $id, int $stayId): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_msg_templates', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_msg_templates', ['active' => 0], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('msgtpl_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('msgtpl_list')) {
    /** Active templates for a set of stays. Read-only. */
    function msgtpl_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_msg_templates', '*', [
                'stay_id' => $stayIds, 'active' => 1,
                'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit),
            ]) ?: [];
        } catch (\Throwable $e) { error_log('msgtpl_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('msgtpl_render')) {
    /** Substitute {guest_name}/{property}/{invoice}/{checkin}/{checkout} placeholders. */
    function msgtpl_render(string $body, array $vars): string
    {
        $out = $body;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// OUTBOUND SEND (DB-GATED — reuses platform senders)
// ---------------------------------------------------------------------------
if (!function_exists('guestmsg_channel_ready')) {
    /**
     * Is the platform provider for this channel actually configured? Lets the
     * UI/route tell the difference between "logged only" and "really sent".
     */
    function guestmsg_channel_ready($db, string $channel): bool
    {
        try {
            $s = $db->get('settings', '*') ?: [];
            switch ($channel) {
                case 'email':
                    $cfg = json_decode($s['email_providers_config'] ?? '{}', true) ?: [];
                    $p = $s['email_provider'] ?? 'smtp';
                    return !empty($cfg[$p]);
                case 'sms':
                    $cfg = json_decode($s['sms_providers_config'] ?? '{}', true) ?: [];
                    $p = $s['sms_provider'] ?? '';
                    return $p !== '' && !empty($cfg[$p]);
                case 'whatsapp':
                    $cfg = json_decode($s['whatsapp_providers_config'] ?? '{}', true) ?: [];
                    $p = $s['whatsapp_provider'] ?? 'greenapi';
                    return !empty($cfg[$p]);
            }
        } catch (\Throwable $e) { error_log('guestmsg_channel_ready: ' . $e->getMessage()); }
        return false; // note/phone or unconfigured => not a live send channel
    }
}

if (!function_exists('guestmsg_send')) {
    /**
     * Log an OUTBOUND guest message AND attempt real delivery via the platform
     * senders, recording the delivery outcome for response tracking.
     *
     * DB-GATED: if the channel's provider is not configured, we still LOG the
     * message (honest) and mark delivery 'skipped' rather than faking a send.
     *
     * Reuses guestmsg_add() for the canonical log row. Never trusts a client to
     * say "sent" — the status is derived from the sender's own return value.
     *
     * @return array{message_id:int, delivery:string}  delivery ∈ sent|failed|skipped|logged
     */
    function guestmsg_send($db, int $stayId, array $d): array
    {
        $channel = (string) ($d['channel'] ?? 'note');
        $body    = trim((string) ($d['body'] ?? ''));
        if ($stayId <= 0 || $body === '') { return ['message_id' => 0, 'delivery' => 'failed']; }

        // 1) Canonical log (forces direction=out for a send).
        $d['direction'] = 'out';
        $mid = function_exists('guestmsg_add') ? guestmsg_add($db, $stayId, $d) : 0;
        if ($mid <= 0) { return ['message_id' => 0, 'delivery' => 'failed']; }

        // 2) note/phone are non-sendable channels — just a log entry.
        if (!in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
            guestmsg_track($db, $mid, 'logged', '');
            return ['message_id' => $mid, 'delivery' => 'logged'];
        }

        // 3) DB-GATED: provider must be configured to attempt a real send.
        if (!guestmsg_channel_ready($db, $channel)) {
            guestmsg_track($db, $mid, 'skipped', 'provider not configured');
            return ['message_id' => $mid, 'delivery' => 'skipped'];
        }

        $toEmail = trim((string) ($d['guest_email'] ?? ''));
        $toPhone = trim((string) ($d['guest_phone'] ?? ''));
        $toName  = trim((string) ($d['guest_name'] ?? '')) ?: 'Guest';
        $subject = trim((string) ($d['subject'] ?? '')) ?: 'Message from your host';

        $ok = false; $err = '';
        try {
            if ($channel === 'email') {
                if ($toEmail === '') { guestmsg_track($db, $mid, 'failed', 'no recipient email'); return ['message_id' => $mid, 'delivery' => 'failed']; }
                $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
                $ok = function_exists('SENDEMAIL') ? (bool) SENDEMAIL($toEmail, $toName, $subject, $html) : false;
            } elseif ($channel === 'sms') {
                if ($toPhone === '') { guestmsg_track($db, $mid, 'failed', 'no recipient phone'); return ['message_id' => $mid, 'delivery' => 'failed']; }
                $ok = function_exists('SENDSMS') ? (bool) SENDSMS($toPhone, $toName, $body) : false;
            } elseif ($channel === 'whatsapp') {
                if ($toPhone === '') { guestmsg_track($db, $mid, 'failed', 'no recipient phone'); return ['message_id' => $mid, 'delivery' => 'failed']; }
                $ok = function_exists('SENDWHATSAPP') ? (bool) SENDWHATSAPP($toPhone, $toName, $body) : false;
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); $ok = false; }

        $status = $ok ? 'sent' : 'failed';
        guestmsg_track($db, $mid, $status, $err);
        return ['message_id' => $mid, 'delivery' => $status];
    }
}

// ---------------------------------------------------------------------------
// RESPONSE / DELIVERY TRACKING (companion table, keyed by message id)
// ---------------------------------------------------------------------------
if (!function_exists('guestmsg_track')) {
    /**
     * Upsert the delivery/response state for a logged message.
     * status ∈ pending|sent|failed|skipped|logged|replied
     */
    function guestmsg_track($db, int $messageId, string $status, string $detail = ''): bool
    {
        if ($messageId <= 0) { return false; }
        $valid = ['pending','sent','failed','skipped','logged','replied'];
        if (!in_array($status, $valid, true)) { $status = 'pending'; }
        try {
            $now = date('Y-m-d H:i:s');
            $fields = ['status' => $status, 'detail' => substr($detail, 0, 255) ?: null, 'updated_at' => $now];
            if ($status === 'sent')    { $fields['sent_at'] = $now; }
            if ($status === 'replied') { $fields['replied_at'] = $now; }
            if ($db->has('stays_msg_delivery', ['message_id' => $messageId])) {
                $db->update('stays_msg_delivery', $fields, ['message_id' => $messageId]);
            } else {
                $fields['message_id'] = $messageId;
                $fields['created_at'] = $now;
                $db->insert('stays_msg_delivery', $fields);
            }
            return true;
        } catch (\Throwable $e) { error_log('guestmsg_track: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('guestmsg_delivery_map')) {
    /** message_id => delivery row, for a set of message ids. Read-only. */
    function guestmsg_delivery_map($db, array $messageIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $messageIds), fn($i) => $i > 0));
        if (empty($ids)) { return []; }
        try {
            $rows = $db->select('stays_msg_delivery', '*', ['message_id' => $ids]) ?: [];
            $out = [];
            foreach ($rows as $r) { $out[(int) $r['message_id']] = $r; }
            return $out;
        } catch (\Throwable $e) { error_log('guestmsg_delivery_map: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('guestmsg_response_stats')) {
    /**
     * Response-tracking rollup for a property set: how many outbound were
     * sent/failed/skipped and how many have a logged reply. Read-only.
     */
    function guestmsg_response_stats($db, array $stayIds): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'replied' => 0, 'awaiting' => 0];
        if (empty($stayIds)) { return $out; }
        try {
            // Join delivery rows to messages owned by these stays.
            $rows = $db->select('stays_msg_delivery', [
                '[>]stays_guest_messages' => ['message_id' => 'id'],
            ], [
                'stays_msg_delivery.status(status)',
            ], [
                'stays_guest_messages.stay_id' => $stayIds,
            ]) ?: [];
            foreach ($rows as $r) {
                $st = (string) ($r['status'] ?? '');
                if (isset($out[$st])) { $out[$st]++; }
                if ($st === 'sent') { $out['awaiting']++; }
            }
            $out['awaiting'] = max(0, $out['sent'] - $out['replied']);
        } catch (\Throwable $e) { error_log('guestmsg_response_stats: ' . $e->getMessage()); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// AUTOMATED MESSAGING (rules) — DB-GATED
// ---------------------------------------------------------------------------
if (!function_exists('msgauto_triggers')) {
    /** Lifecycle events a rule can fire on. */
    function msgauto_triggers(): array
    {
        return [
            'booking_confirmed' => 'On booking confirmed',
            'pre_arrival'       => 'Day before check-in',
            'checkin'           => 'On check-in',
            'checkout'          => 'On check-out',
            'post_stay'         => 'Day after check-out',
        ];
    }
}

if (!function_exists('msgauto_add')) {
    /** Create an automation rule (property-scoped). Returns id or 0. */
    function msgauto_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $trigger = (string) ($d['trigger_event'] ?? '');
        $tplId   = (int) ($d['template_id'] ?? 0);
        if (!isset(msgauto_triggers()[$trigger]) || $tplId <= 0) { return 0; }
        try {
            // Template must belong to the same property.
            if (!$db->has('stays_msg_templates', ['id' => $tplId, 'stay_id' => $stayId, 'active' => 1])) { return 0; }
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $ch = isset(msgtpl_channels()[$d['channel'] ?? '']) ? $d['channel'] : 'email';
            $db->insert('stays_msg_automations', [
                'org_id'        => $orgId > 0 ? $orgId : null,
                'stay_id'       => $stayId,
                'trigger_event' => $trigger,
                'template_id'   => $tplId,
                'channel'       => $ch,
                'active'        => 1,
                'created_by'    => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('msgauto_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('msgauto_toggle')) {
    /** Enable/disable a rule (property-scoped). */
    function msgauto_toggle($db, int $id, int $stayId, bool $active): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_msg_automations', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_msg_automations', ['active' => $active ? 1 : 0], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('msgauto_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('msgauto_list')) {
    /** Rules for a set of stays, template name joined in. Read-only. */
    function msgauto_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_msg_automations', [
                '[>]stays_msg_templates' => ['template_id' => 'id'],
            ], [
                'stays_msg_automations.id(id)',
                'stays_msg_automations.stay_id(stay_id)',
                'stays_msg_automations.trigger_event(trigger_event)',
                'stays_msg_automations.channel(channel)',
                'stays_msg_automations.active(active)',
                'stays_msg_automations.template_id(template_id)',
                'stays_msg_templates.name(template_name)',
            ], [
                'stays_msg_automations.stay_id' => $stayIds,
                'ORDER' => ['stays_msg_automations.id' => 'DESC'],
                'LIMIT' => max(1, $limit),
            ]) ?: [];
        } catch (\Throwable $e) { error_log('msgauto_list: ' . $e->getMessage()); return []; }
    }
}
