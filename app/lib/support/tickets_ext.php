<?php
// app/lib/support/tickets_ext.php
// Customer Support module — Phase 6: small, SAFE extensions to the EXISTING ticket
// system (the tickets table + supportticketRoutes.php are already live — we only
// ADD columns + helpers, never rewrite the flow). Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§1 Tickets, §6 extensions).
//
// NOTE: LIBRARY (loaded from config.php during bootstrap, BEFORE $SECURE) — NO
// `@$SECURE or die` guard (that kills the app at boot; the P1/P2 regression).
//
// Adds (idempotent):
//   tickets.related_invoice VARCHAR(64)  — link a ticket to a specific booking
//   tickets.sla_due_at      DATETIME     — a soft SLA deadline for the ticket
//   tickets.is_internal     TINYINT      — a reply flagged internal (staff-only note)
// All are nullable/defaulted so the existing create/reply flow keeps working untouched.

if (!function_exists('ensureSupportTicketExtSchema')) {
    function ensureSupportTicketExtSchema($db): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;
        try {
            $cols = [
                'related_invoice' => "ALTER TABLE `tickets` ADD COLUMN `related_invoice` VARCHAR(64) DEFAULT NULL",
                'sla_due_at'      => "ALTER TABLE `tickets` ADD COLUMN `sla_due_at` DATETIME DEFAULT NULL",
                'is_internal'     => "ALTER TABLE `tickets` ADD COLUMN `is_internal` TINYINT(1) NOT NULL DEFAULT 0",
            ];
            foreach ($cols as $name => $alter) {
                $have = $db->query("SHOW COLUMNS FROM `tickets` LIKE " . $db->quote($name))->fetchAll();
                if (!$have) { $db->query($alter); }
            }
        } catch (\Throwable $e) {
            error_log('ensureSupportTicketExtSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('support_ticket_sla_default')) {
    /** Default SLA window (hours) by priority. Soft target only. */
    function support_ticket_sla_default(string $priority): int
    {
        $p = strtolower(trim($priority));
        if ($p === 'high' || $p === 'urgent') { return 4; }
        if ($p === 'low') { return 72; }
        return 24; // normal / unknown
    }
}

if (!function_exists('support_ticket_create_for_booking')) {
    /**
     * Raise a support ticket pre-linked to a booking (the "raise ticket from a
     * booking" action). Reuses the existing tickets table shape: a 'parent' row.
     * Returns the new ticket_id (string) or '' on failure. Fail-soft.
     *
     * @param array $booking a bookings row (needs invoice_id, user_id, email, module)
     */
    function support_ticket_create_for_booking($db, array $booking, string $subject, string $desc, string $priority = 'normal'): string
    {
        ensureSupportTicketExtSchema($db);
        $invoice = (string) ($booking['invoice_id'] ?? '');
        if ($invoice === '') { return ''; }
        try {
            $ticketId = 'T' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
            $slaHours = support_ticket_sla_default($priority);
            $db->insert('tickets', [
                'ticket_id'       => $ticketId,
                'user_id'         => (string) ($booking['user_id'] ?? ''),
                'subject'         => $subject !== '' ? $subject : ('Booking ' . $invoice),
                'desc'            => $desc,
                'date'            => date('Y-m-d H:i:s'),
                'status'          => 'open',
                'priority'        => $priority,
                'type'            => 'parent',
                'related'         => $invoice,          // existing column
                'related_invoice' => $invoice,          // explicit P6 link
                'sla_due_at'      => date('Y-m-d H:i:s', time() + $slaHours * 3600),
            ]);
            return $ticketId;
        } catch (\Throwable $e) {
            error_log('support_ticket_create_for_booking: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('support_ticket_sla_state')) {
    /** A UI-friendly SLA state for a ticket: 'none' | 'ok' | 'due-soon' | 'breached'. */
    function support_ticket_sla_state(?string $slaDueAt, string $status): string
    {
        if ($slaDueAt === null || $slaDueAt === '' || $slaDueAt === '0000-00-00 00:00:00') { return 'none'; }
        if (in_array(strtolower(trim($status)), ['close', 'closed', 'resolved'], true)) { return 'ok'; }
        $due = strtotime($slaDueAt);
        if ($due === false) { return 'none'; }
        $now = time();
        if ($now > $due) { return 'breached'; }
        if ($due - $now < 2 * 3600) { return 'due-soon'; }
        return 'ok';
    }
}
