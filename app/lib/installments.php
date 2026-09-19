<?php
// ============================================================================
// GENERIC INSTALLMENT ENGINE (PaySmallSmall Phase 2)
// ----------------------------------------------------------------------------
// A module-agnostic installment ledger for NON-umrah services (umrah keeps its
// own richer engine: umrah_payment_plans / umrah_installments / umrah_settle_payment
// with price-lock). This mirrors that engine's SHAPE and its money guarantees:
//   * schedule built from the scope's pay_small_small_rules (first % now + N
//     further parts every interval_days), stored in booking_installments.
//   * settle allocates a payment across pending installments in seq order.
//   * booking.payment_status: unpaid -> partially_paid -> paid; the generic row
//     is only 'paid' + 'confirmed' when the balance reaches zero.
//   * idempotent per (invoice, txn): a re-fired callback never double-applies.
// The umrah path is untouched; callers must gate on module_type !== 'umrah'.
// ============================================================================

if (!function_exists('installments_active_for')) {
    /** True when this booking has a generic installment schedule to settle. */
    function installments_active_for($db, string $invoiceId): bool
    {
        if ($invoiceId === '') { return false; }
        try { return (int) $db->count('booking_installments', ['invoice_id' => $invoiceId]) > 0; }
        catch (\Throwable $e) { return false; }
    }
}

if (!function_exists('installments_create_schedule')) {
    /**
     * Build the installment schedule for a booking from a PaySmallSmall rule.
     * first_percent charged now (seq 1, due now), then `installments` further
     * equal parts every interval_days. Amounts are rounded and the final part
     * absorbs any rounding remainder so the sum == total exactly. Idempotent:
     * if a schedule already exists for the invoice it is returned unchanged.
     *
     * @return array{ok:bool,installments?:array,message?:string}
     */
    function installments_create_schedule($db, string $invoiceId, float $total, string $currency, array $rule): array
    {
        $invoiceId = trim($invoiceId);
        $total = round(max(0, $total), 2);
        if ($invoiceId === '' || $total <= 0) { return ['ok' => false, 'message' => 'Invalid booking total']; }

        if (installments_active_for($db, $invoiceId)) {
            return ['ok' => true, 'installments' => $db->select('booking_installments', '*', ['invoice_id' => $invoiceId, 'ORDER' => ['seq' => 'ASC']]) ?: [], 'already' => true];
        }

        $firstPct = max(1, min(100, (float) ($rule['first_percent'] ?? 50)));
        $further  = max(0, (int) ($rule['installments'] ?? 2));
        $interval = max(1, (int) ($rule['interval_days'] ?? 30));
        $now = time();

        $first = round($total * $firstPct / 100, 2);
        $remaining = round($total - $first, 2);
        $rows = [];
        // Seq 1 — due now (the slice paid at checkout).
        $rows[] = ['seq' => 1, 'amount' => $first, 'due_at' => date('Y-m-d H:i:s', $now)];
        if ($further > 0 && $remaining > 0.005) {
            $part = round($remaining / $further, 2);
            $allocated = 0.0;
            for ($i = 1; $i <= $further; $i++) {
                // Last part absorbs the rounding remainder so the sum ties out.
                $amt = ($i === $further) ? round($remaining - $allocated, 2) : $part;
                $allocated = round($allocated + $amt, 2);
                $rows[] = ['seq' => $i + 1, 'amount' => $amt, 'due_at' => date('Y-m-d H:i:s', $now + $interval * $i * 86400)];
            }
        } elseif ($remaining > 0.005) {
            // No further parts configured but money remains → one balance part now.
            $rows[] = ['seq' => 2, 'amount' => $remaining, 'due_at' => date('Y-m-d H:i:s', $now)];
        }

        try {
            foreach ($rows as $r) {
                $db->insert('booking_installments', [
                    'invoice_id' => $invoiceId, 'seq' => $r['seq'], 'amount' => $r['amount'],
                    'currency' => strtoupper($currency) ?: 'USD', 'due_at' => $r['due_at'],
                    'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('installments_create_schedule: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create schedule'];
        }
        return ['ok' => true, 'installments' => $rows];
    }
}

if (!function_exists('installments_amount_due')) {
    /** Next pending/overdue installment amount, or 0 if none/fully paid. */
    function installments_amount_due($db, string $invoiceId): float
    {
        if (!installments_active_for($db, $invoiceId)) { return 0.0; }
        $next = $db->get('booking_installments', ['amount'], [
            'invoice_id' => $invoiceId, 'status' => ['pending', 'overdue'], 'ORDER' => ['seq' => 'ASC'],
        ]);
        return $next ? round((float) $next['amount'], 2) : 0.0;
    }
}

if (!function_exists('installments_settle_payment')) {
    /**
     * Apply a payment to a booking's generic installment schedule. Allocates the
     * amount across pending installments (seq order), recomputes paid/balance, and
     * sets booking.payment_status (partially_paid until the balance clears, then
     * paid + booking_status confirmed). Idempotent per (invoice, txn): a repeat
     * txn is a no-op. Mirrors umrah_settle_payment's contract for non-umrah.
     *
     * @return array{ok:bool,status:string,amount_paid?:float,balance?:float,payment_status?:string}
     */
    function installments_settle_payment($db, string $invoiceId, float $amount, ?string $currency = null, ?string $txnId = null): array
    {
        $invoiceId = trim($invoiceId);
        $amount = round((float) $amount, 2);
        if ($invoiceId === '' || $amount <= 0) { return ['ok' => false, 'status' => 'error', 'message' => 'Invalid amount']; }
        if (!installments_active_for($db, $invoiceId)) { return ['ok' => false, 'status' => 'no_schedule']; }

        $now = date('Y-m-d H:i:s');
        // Idempotency: synthesize a txn key when the gateway gives none, suffixed
        // by the count of prior settlements so successive equal parts stay distinct
        // (same trick as umrah_settle_payment — two equal 25% tails must not collide).
        $txnId = (string) $txnId;
        if ($txnId === '') {
            $prior = (int) $db->count('booking_installments', ['invoice_id' => $invoiceId, 'transaction_id[!]' => null]);
            $txnId = 'INSTAUTO-' . $invoiceId . '-' . number_format($amount, 2, '', '') . '-' . ($prior + 1);
        }
        if ($db->get('booking_installments', 'id', ['invoice_id' => $invoiceId, 'transaction_id' => $txnId])) {
            return ['ok' => true, 'status' => 'already'];
        }

        $result = ['ok' => false, 'status' => 'error'];
        try {
            $db->action(function ($db) use ($invoiceId, $amount, $txnId, $now, &$result) {
                $pending = $db->select('booking_installments', '*', [
                    'invoice_id' => $invoiceId, 'status' => ['pending', 'overdue'], 'ORDER' => ['seq' => 'ASC'],
                ]) ?: [];
                $remaining = round($amount, 2);
                foreach ($pending as $ins) {
                    if ($remaining < 0.01) { break; }
                    $due = (float) $ins['amount'];
                    if ($remaining + 0.01 >= $due) {
                        $db->update('booking_installments', ['status' => 'paid', 'paid_at' => $now, 'transaction_id' => $txnId], ['id' => (int) $ins['id']]);
                        $remaining = round($remaining - $due, 2);
                    }
                }
                $total = (float) $db->sum('booking_installments', 'amount', ['invoice_id' => $invoiceId]);
                $paid  = (float) $db->sum('booking_installments', 'amount', ['invoice_id' => $invoiceId, 'status' => 'paid']);
                $balance = round(max(0, $total - $paid), 2);

                $paymentStatus = ($paid <= 0) ? 'unpaid' : ($balance < 0.01 ? 'paid' : 'partially_paid');
                $upd = ['payment_status' => $paymentStatus];
                if ($balance < 0.01) { $upd['booking_status'] = 'confirmed'; $upd['paid_at'] = $now; if ($txnId) { $upd['transaction_id'] = $txnId; } }
                $db->update('bookings', $upd, ['invoice_id' => $invoiceId]);

                $result = ['ok' => true, 'status' => 'settled', 'amount_paid' => $paid, 'balance' => $balance, 'payment_status' => $paymentStatus];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('installments_settle_payment: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'error', 'message' => 'Settlement failed'];
        }
        return $result;
    }
}

if (!function_exists('installments_reminder_sweep')) {
    /**
     * Cron worker for generic installment reminders + overdue marking. Emails the
     * customer once per installment when it falls due within $withinDays (deduped
     * via reminder_sent_at), and flips clearly past-due pending parts to 'overdue'.
     * Deadline POLICY (auto-cancel vs flag) is handled by the Pay-Later sweep's
     * shared machinery when a booking also carries a deadline; here we only chase
     * installments. Only touches non-umrah bookings (umrah has its own sweep).
     *
     * @return int number of reminders sent
     */
    function installments_reminder_sweep($db, int $withinDays = 3): int
    {
        $reminded = 0;
        try {
            $now = date('Y-m-d H:i:s');
            $soon = date('Y-m-d H:i:s', time() + max(1, $withinDays) * 86400);
            // Mark clearly past-due pending parts overdue.
            try {
                $db->update('booking_installments', ['status' => 'overdue'], [
                    'status' => 'pending', 'due_at[<]' => $now, 'due_at[!]' => null,
                ]);
            } catch (\Throwable $e) { /* non-fatal */ }

            $rows = $db->select('booking_installments', '*', [
                'status' => ['pending', 'overdue'],
                'reminder_sent_at' => null,
                'due_at[!]' => null,
                'due_at[<=]' => $soon,
                'ORDER' => ['due_at' => 'ASC'],
                'LIMIT' => 500,
            ]) ?: [];

            foreach ($rows as $r) {
                $inv = (string) $r['invoice_id'];
                $b = $db->get('bookings', ['first_name', 'last_name', 'email', 'module_type', 'booking_status', 'payment_status'], ['invoice_id' => $inv]);
                if (!$b) { $db->update('booking_installments', ['reminder_sent_at' => $now], ['id' => (int) $r['id']]); continue; }
                // Skip cancelled/fully-paid bookings.
                if (in_array(($b['booking_status'] ?? ''), ['cancelled'], true) || ($b['payment_status'] ?? '') === 'paid') {
                    $db->update('booking_installments', ['reminder_sent_at' => $now], ['id' => (int) $r['id']]);
                    continue;
                }
                $email = (string) ($b['email'] ?? '');
                if ($email !== '' && function_exists('SENDEMAIL')) {
                    $name = trim(((string) ($b['first_name'] ?? '')) . ' ' . ((string) ($b['last_name'] ?? '')));
                    $amt  = number_format((float) $r['amount'], 2) . ' ' . ((string) $r['currency']);
                    $due  = date('D, d M Y', strtotime((string) $r['due_at']));
                    $body = "Assalamu Alaikum " . $name . ",\n\nThis is a reminder that installment #{$r['seq']} for booking {$inv} is due on {$due}.\n\nAmount due: {$amt}\n\nPlease pay from your booking page to keep your reservation.\n";
                    try { SENDEMAIL($email, $name, 'Payment reminder — booking ' . $inv, $body); } catch (\Throwable $e) { /* non-fatal */ }
                }
                $db->update('booking_installments', ['reminder_sent_at' => $now], ['id' => (int) $r['id']]);
                $reminded++;
            }
        } catch (\Throwable $e) {
            error_log('installments_reminder_sweep: ' . $e->getMessage());
        }
        return $reminded;
    }
}
