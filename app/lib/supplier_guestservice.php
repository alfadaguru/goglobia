<?php
// FILE: app/lib/supplier_guestservice.php
// Guest service tickets / complaints (#21) + guest communication log (#19). Per-property
// registers; caller authorizes the stay via supplier_can. Defensive/non-fatal.
// NOT runtime-verified (no MySQL) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// SERVICE TICKETS / COMPLAINTS (#21)
// ---------------------------------------------------------------------------
if (!function_exists('ticket_statuses')) {
    function ticket_statuses(): array { return ['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed']; }
}
if (!function_exists('ticket_categories')) {
    function ticket_categories(): array { return ['complaint'=>'Complaint','request'=>'Request','maintenance'=>'Maintenance','other'=>'Other']; }
}
if (!function_exists('ticket_add')) {
    function ticket_add($db, int $stayId, array $d): int
    {
        $subject = trim((string) ($d['subject'] ?? ''));
        if ($stayId <= 0 || $subject === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $cat = isset(ticket_categories()[$d['category'] ?? '']) ? $d['category'] : 'request';
            $pri = in_array(($d['priority'] ?? ''), ['low','normal','high'], true) ? $d['priority'] : 'normal';
            $db->insert('stays_service_tickets', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'invoice_id' => trim((string) ($d['invoice_id'] ?? '')) ?: null,
                'guest_name' => substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                'category'   => $cat, 'priority' => $pri,
                'subject'    => substr($subject, 0, 191),
                'detail'     => trim((string) ($d['detail'] ?? '')) ?: null,
                'status'     => 'open',
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('ticket_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('ticket_update')) {
    /** Update a ticket's status/assignee/resolution. Property-scoped. */
    function ticket_update($db, int $id, int $stayId, array $d): bool
    {
        try {
            if (!$db->has('stays_service_tickets', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $fields = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($d['status']) && isset(ticket_statuses()[$d['status']])) { $fields['status'] = $d['status']; }
            if (array_key_exists('assigned_to', $d)) { $fields['assigned_to'] = substr(trim((string) $d['assigned_to']), 0, 155) ?: null; }
            if (array_key_exists('resolution', $d)) { $fields['resolution'] = substr(trim((string) $d['resolution']), 0, 500) ?: null; }
            if (count($fields) === 1) { return false; } // nothing but updated_at
            $db->update('stays_service_tickets', $fields, ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('ticket_update: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('ticket_list')) {
    function ticket_list($db, array $stayIds, string $status = '', int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if (isset(ticket_statuses()[$status])) { $where['status'] = $status; }
        $where['ORDER'] = ['id' => 'DESC']; $where['LIMIT'] = max(1, $limit);
        try { return $db->select('stays_service_tickets', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('ticket_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// GUEST COMMUNICATION LOG (#19)
// ---------------------------------------------------------------------------
if (!function_exists('guestmsg_channels')) {
    function guestmsg_channels(): array { return ['note'=>'Note','email'=>'Email','phone'=>'Phone','whatsapp'=>'WhatsApp','sms'=>'SMS']; }
}
if (!function_exists('guestmsg_add')) {
    function guestmsg_add($db, int $stayId, array $d): int
    {
        $body = trim((string) ($d['body'] ?? ''));
        if ($stayId <= 0 || $body === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $channel = isset(guestmsg_channels()[$d['channel'] ?? '']) ? $d['channel'] : 'note';
            $dir = in_array(($d['direction'] ?? ''), ['in','out'], true) ? $d['direction'] : 'out';
            $db->insert('stays_guest_messages', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'invoice_id'  => trim((string) ($d['invoice_id'] ?? '')) ?: null,
                'guest_email' => trim((string) ($d['guest_email'] ?? '')) ?: null,
                'channel'     => $channel, 'direction' => $dir,
                'body'        => substr($body, 0, 4000),
                'logged_by'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('guestmsg_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('guestmsg_list')) {
    /** Messages for a set of stays, optionally filtered to one invoice. Read-only. */
    function guestmsg_list($db, array $stayIds, string $invoiceId = '', int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if ($invoiceId !== '') { $where['invoice_id'] = $invoiceId; }
        $where['ORDER'] = ['id' => 'DESC']; $where['LIMIT'] = max(1, $limit);
        try { return $db->select('stays_guest_messages', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('guestmsg_list: ' . $e->getMessage()); return []; }
    }
}
