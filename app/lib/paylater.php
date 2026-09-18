<?php
// ============================================================================
// PAY-LATER ENGINE
// ----------------------------------------------------------------------------
// "Reserve now, pay later" with real business logic: a configurable payment
// deadline + reminder schedule + deadline policy (auto-cancel vs flag), scoped
// per module and per service (service -> module -> global), because each service
// has its own real-world rules (a flight PNR ticketing deadline is hours; a tour
// can hold for days). Backed by the pay_later_rules table (ensurePaymentScopingSchema).
//
// Lifecycle on a booking (bookings columns added by ensurePaymentScopingSchema):
//   payment_due_at        — when payment is due (set when Pay Later is chosen)
//   pay_later_status      — pending | reminded | overdue | cancelled | paid
//   pay_later_reminder_at — last reminder timestamp (dedup)
// Money is NOT moved by Pay Later; settlement is the normal Mark-Paid / gateway
// path, which clears pay_later_status to 'paid'.
// ============================================================================

if (!function_exists('pay_later_rule_for')) {
    /**
     * Resolve the effective Pay-Later rule for a (module_type, supplier) pair,
     * SERVICE -> MODULE -> GLOBAL. Supplier is resolved against the modules
     * registry first (never trusts a raw client value). Returns the pay_later_rules
     * row (array) — always at least the global default (seeded disabled). Returns
     * null only if the table/global row is somehow missing.
     */
    function pay_later_rule_for($db, string $moduleType, string $supplierRaw = ''): ?array
    {
        $moduleType = strtolower(trim($moduleType));
        $supplier   = '';
        if ($moduleType !== '' && function_exists('payment_gateway_resolve_supplier')) {
            $supplier = payment_gateway_resolve_supplier($db, $moduleType, $supplierRaw);
        }
        try {
            if ($moduleType !== '' && $supplier !== '') {
                $r = $db->get('pay_later_rules', '*', ['scope_type' => 'service', 'module_type' => $moduleType, 'supplier' => $supplier]);
                if ($r) { return $r; }
            }
            if ($moduleType !== '') {
                $r = $db->get('pay_later_rules', '*', ['scope_type' => 'module', 'module_type' => $moduleType, 'supplier' => '']);
                if ($r) { return $r; }
            }
            return $db->get('pay_later_rules', '*', ['scope_type' => 'global', 'module_type' => '', 'supplier' => '']) ?: null;
        } catch (\Throwable $e) {
            error_log('pay_later_rule_for: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pay_later_is_enabled_for')) {
    /** True when Pay Later is enabled for this scope (used to gate the gateway). */
    function pay_later_is_enabled_for($db, string $moduleType, string $supplierRaw = ''): bool
    {
        $rule = pay_later_rule_for($db, $moduleType, $supplierRaw);
        return $rule ? ((int) ($rule['enabled'] ?? 0) === 1) : false;
    }
}

if (!function_exists('pay_later_apply_to_booking')) {
    /**
     * Stamp the Pay-Later deadline onto a booking when the customer selects the
     * Pay Later gateway. Sets payment_due_at (now + rule.deadline_hours) and
     * pay_later_status='pending'. Idempotent: only sets a due date the first time
     * (won't keep pushing the deadline out on repeated gateway toggles). No-op for
     * an already-paid booking. Returns the due datetime string or null.
     */
    function pay_later_apply_to_booking($db, array $booking): ?string
    {
        $invoiceId = (string) ($booking['invoice_id'] ?? '');
        if ($invoiceId === '') { return null; }
        if (($booking['payment_status'] ?? '') === 'paid') { return null; }
        // Already stamped — keep the original deadline.
        if (!empty($booking['payment_due_at'])) { return (string) $booking['payment_due_at']; }

        $rule = pay_later_rule_for($db, (string) ($booking['module_type'] ?? ''), (string) ($booking['module'] ?? ''));
        $hours = $rule ? max(1, (int) ($rule['deadline_hours'] ?? 72)) : 72;
        $due = date('Y-m-d H:i:s', time() + $hours * 3600);
        try {
            $db->update('bookings', [
                'payment_due_at'   => $due,
                'pay_later_status' => 'pending',
            ], ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) {
            error_log('pay_later_apply_to_booking: ' . $e->getMessage());
            return null;
        }
        return $due;
    }
}

if (!function_exists('pay_later_clear_on_payment')) {
    /**
     * Clear the Pay-Later hold once a booking is actually paid (called from the
     * mark-paid / settlement path). Marks pay_later_status='paid' so the cron
     * stops chasing it. Safe no-op if the booking was never a Pay-Later one.
     */
    function pay_later_clear_on_payment($db, string $invoiceId): void
    {
        if ($invoiceId === '') { return; }
        try {
            $db->update('bookings', ['pay_later_status' => 'paid'], [
                'invoice_id' => $invoiceId,
                'pay_later_status[!]' => null,
            ]);
        } catch (\Throwable $e) { error_log('pay_later_clear_on_payment: ' . $e->getMessage()); }
    }
}

if (!function_exists('pay_later_sweep')) {
    /**
     * Cron worker: for every unpaid Pay-Later booking with a deadline, (1) send
     * reminder emails at the scope's configured offsets before the deadline
     * (once per offset window), and (2) at/after the deadline apply the scope's
     * policy — auto_cancel (cancel + optionally release inventory + notify) or
     * flag (mark overdue + alert, human decides).
     *
     * Reminder de-dup uses pay_later_reminder_at: we only send if the last
     * reminder was in an EARLIER offset window than the one now due. Idempotent
     * and safe to run every few minutes.
     *
     * @return array{reminded:int,cancelled:int,flagged:int}
     */
    function pay_later_sweep($db): array
    {
        $out = ['reminded' => 0, 'cancelled' => 0, 'flagged' => 0];
        try {
            $now = time();
            $nowStr = date('Y-m-d H:i:s', $now);
            // Candidates: unpaid, have a due date, not already resolved.
            $rows = $db->select('bookings', [
                'invoice_id', 'module_type', 'module', 'email', 'first_name', 'last_name',
                'phone', 'price_markup', 'currency_markup', 'payment_status',
                'booking_status', 'payment_due_at', 'pay_later_status', 'pay_later_reminder_at',
            ], [
                'payment_status'      => 'unpaid',
                'payment_due_at[!]'   => null,
                'pay_later_status'    => ['pending', 'reminded', 'overdue'],
                'ORDER'               => ['payment_due_at' => 'ASC'],
                'LIMIT'               => 500,
            ]) ?: [];

            foreach ($rows as $b) {
                $inv = (string) $b['invoice_id'];
                $dueTs = strtotime((string) $b['payment_due_at']);
                if ($dueTs === false) { continue; }
                $rule = pay_later_rule_for($db, (string) ($b['module_type'] ?? ''), (string) ($b['module'] ?? ''));
                if (!$rule) { continue; }

                // ---- Deadline reached: apply policy ----
                if ($now >= $dueTs) {
                    $policy = (string) ($rule['deadline_policy'] ?? 'flag');
                    if ($policy === 'auto_cancel') {
                        $db->update('bookings', [
                            'booking_status'   => 'cancelled',
                            'pay_later_status' => 'cancelled',
                            'cancelled_at'     => $nowStr,
                            'cancellation_response' => 'Auto-cancelled: Pay-Later payment not received by ' . $b['payment_due_at'],
                        ], ['invoice_id' => $inv]);
                        // Optional inventory release hook (umrah holds, etc.).
                        if ((int) ($rule['release_inventory'] ?? 1) === 1) {
                            pay_later_release_inventory($db, $b);
                        }
                        pay_later_notify($db, $b, 'pay_later_cancelled',
                            'Your reservation was cancelled — payment not received',
                            "Assalamu Alaikum,\n\nWe did not receive payment for booking {$inv} by the deadline ({$b['payment_due_at']}), so the reservation has been cancelled. You're welcome to book again.\n");
                        $out['cancelled']++;
                    } else {
                        // flag-only: mark overdue (once) + alert staff.
                        if (($b['pay_later_status'] ?? '') !== 'overdue') {
                            $db->update('bookings', ['pay_later_status' => 'overdue'], ['invoice_id' => $inv]);
                            pay_later_notify_admin($db, $b);
                            $out['flagged']++;
                        }
                    }
                    continue;
                }

                // ---- Before deadline: reminders at configured offsets ----
                $offsets = array_values(array_filter(array_map(
                    fn($x) => (int) trim($x),
                    explode(',', (string) ($rule['reminder_offsets_hours'] ?? ''))
                ), fn($h) => $h > 0));
                if (!$offsets) { continue; }
                rsort($offsets); // largest first (e.g. 48 then 12)
                $hoursLeft = ($dueTs - $now) / 3600;
                // The tightest offset we've now crossed (hoursLeft <= offset).
                $dueOffset = null;
                foreach ($offsets as $off) { if ($hoursLeft <= $off) { $dueOffset = $off; } }
                if ($dueOffset === null) { continue; } // not yet in any reminder window

                // Send only if we haven't already reminded within this (tighter) window.
                $lastRemTs = !empty($b['pay_later_reminder_at']) ? strtotime((string) $b['pay_later_reminder_at']) : 0;
                $lastHoursLeftAtReminder = $lastRemTs ? (($dueTs - $lastRemTs) / 3600) : PHP_INT_MAX;
                if ($lastHoursLeftAtReminder <= $dueOffset) { continue; } // already reminded this window or tighter

                $ccy = (string) ($b['currency_markup'] ?? '');
                $amt = number_format((float) ($b['price_markup'] ?? 0), 2) . ' ' . $ccy;
                $when = date('D, d M Y H:i', $dueTs);
                pay_later_notify($db, $b, 'pay_later_reminder',
                    'Payment reminder — booking ' . $inv,
                    "Assalamu Alaikum,\n\nThis is a reminder that payment of {$amt} for booking {$inv} is due by {$when}.\n\nPlease complete payment before the deadline to keep your reservation.\n");
                $db->update('bookings', [
                    'pay_later_reminder_at' => $nowStr,
                    'pay_later_status'      => 'reminded',
                ], ['invoice_id' => $inv]);
                $out['reminded']++;
            }
        } catch (\Throwable $e) {
            error_log('pay_later_sweep: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('pay_later_release_inventory')) {
    /**
     * Best-effort inventory release when a Pay-Later booking auto-cancels. Only
     * umrah holds a concrete seat inventory today; other modules book on payment
     * so an unpaid cancel has nothing to release. Kept small + guarded so a new
     * module can hook its own release here later.
     */
    function pay_later_release_inventory($db, array $booking): void
    {
        $inv = (string) ($booking['invoice_id'] ?? '');
        if ($inv === '') { return; }
        try {
            if (($booking['module_type'] ?? '') === 'umrah') {
                $ub = $db->get('umrah_bookings', ['id', 'hold_id'], ['invoice_id' => $inv]);
                if ($ub) {
                    $db->update('umrah_bookings', ['booking_status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $ub['id']]);
                    if (!empty($ub['hold_id'])) {
                        $db->update('umrah_inventory_holds', ['state' => 'released', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $ub['hold_id'], 'state' => 'held']);
                    }
                }
            }
        } catch (\Throwable $e) { error_log('pay_later_release_inventory: ' . $e->getMessage()); }
    }
}

if (!function_exists('pay_later_notify')) {
    /** Email the customer for a Pay-Later event (best-effort, non-fatal). */
    function pay_later_notify($db, array $booking, string $template, string $subject, string $body): void
    {
        $email = (string) ($booking['email'] ?? '');
        if ($email === '') { return; }
        $name = trim(((string) ($booking['first_name'] ?? '')) . ' ' . ((string) ($booking['last_name'] ?? '')));
        try {
            if (function_exists('SENDEMAIL')) { SENDEMAIL($email, $name, $subject, $body); }
        } catch (\Throwable $e) { error_log('pay_later_notify: ' . $e->getMessage()); }
    }
}

if (!function_exists('pay_later_notify_admin')) {
    /** Alert staff that a Pay-Later booking is overdue (flag-only policy). */
    function pay_later_notify_admin($db, array $booking): void
    {
        try {
            $to = '';
            $s = $db->get('settings', ['booking_notification_email'], ['id' => 1]);
            if ($s && !empty($s['booking_notification_email'])) { $to = (string) $s['booking_notification_email']; }
            if ($to === '') { return; }
            $inv = (string) ($booking['invoice_id'] ?? '');
            if (function_exists('SENDEMAIL')) {
                SENDEMAIL($to, 'Admin', 'Pay-Later booking overdue — ' . $inv,
                    "Booking {$inv} ({$booking['module_type']}) passed its Pay-Later deadline ({$booking['payment_due_at']}) and is flagged for manual review. Amount: " . number_format((float) ($booking['price_markup'] ?? 0), 2) . ' ' . ($booking['currency_markup'] ?? '') . ".\n");
            }
        } catch (\Throwable $e) { error_log('pay_later_notify_admin: ' . $e->getMessage()); }
    }
}
