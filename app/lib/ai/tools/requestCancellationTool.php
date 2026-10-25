<?php

namespace App\lib\ai;

/**
 * GIA tool: let a traveller REQUEST cancellation of THEIR OWN booking from chat
 * (R2 — flags a request; moves NO money). World-class-GIA GA5.
 *
 * DELIBERATELY a request, not an execution. It mirrors the existing, money-safe
 * web route POST /api/ai/trip/request-cancellation (app/routes/ai/
 * tripBookingRoutes.php:746): validate ownership, block already-cancelled /
 * already-requested, set bookings.cancellation_request=1. The refund itself is
 * executed by OPS via the existing admin path (which surfaces the amber
 * "Cancellation Requested" badge) using the canonical refund_gateway_payment().
 * GIA NEVER calls a refund primitive directly — there is one money path and it
 * stays with ops. There is also no fare-rule/eligibility engine in the platform,
 * so GIA must NOT promise a refund amount or timeline it cannot verify.
 */
class requestCancellationTool implements giaToolInterface
{
    public function name(): string
    {
        return 'request_cancellation';
    }

    public function description(): string
    {
        return 'Submit a cancellation REQUEST for the traveller\'s own booking (by invoice reference). '
            . 'This flags the booking for our team to review and process any refund — it does NOT '
            . 'cancel or refund instantly, and you must NOT promise a specific refund amount or '
            . 'timeline (that is decided on review). Only works for the signed-in traveller\'s own '
            . 'bookings. For anything urgent or complex, also offer request_human.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'string', 'description' => 'The booking invoice reference to cancel.'],
                'reason'     => ['type' => 'string', 'description' => 'Optional short reason the traveller gave.'],
            ],
            'required' => ['invoice_id'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // flags a request; no money moves
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Cancellations are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return ['ok' => false, 'error_code' => 'AI_NOT_IDENTIFIED', 'message' => 'I can only cancel a booking once I know who you are and it\'s on your account.'];
        }
        $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            return ['ok' => false, 'error_code' => 'AI_BAD_INVOICE', 'message' => 'Which booking? I need its reference.'];
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ai_trip']);
        if (!$booking) {
            return ['ok' => false, 'error_code' => 'AI_ORDER_NOT_FOUND', 'message' => 'I couldn\'t find that booking.'];
        }

        // OWNERSHIP: the booking must belong to THIS identified traveller. We scope
        // by user_id here (the tool's account_id is the trusted, route-resolved
        // user) — never let one user request cancellation of another's booking.
        if ((string) ($booking['user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'error_code' => 'AI_NOT_OWNER', 'message' => 'That booking isn\'t on your account, so I can\'t cancel it.'];
        }

        // Same guards as the existing web route.
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            return ['ok' => false, 'error_code' => 'AI_ALREADY_CANCELLED', 'message' => 'That booking is already cancelled.'];
        }
        if ((string) ($booking['cancellation_request'] ?? '0') === '1') {
            return ['ok' => true, 'data' => ['already_requested' => true], 'message' => 'You\'ve already requested to cancel this booking — our team is on it.'];
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        $note = 'Customer requested cancellation via GIA on ' . date('Y-m-d H:i');
        if ($reason !== '') { $note .= ' — reason: ' . substr($reason, 0, 180); }

        try {
            $ok = $db->update('bookings', [
                'cancellation_request'  => 1,
                'cancellation_response' => $note,
            ], ['invoice_id' => $invoiceId, 'module_type' => 'ai_trip']);
        } catch (\Throwable $e) {
            error_log('[gia] request_cancellation: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_CANCEL_FAILED', 'message' => 'Could not submit the request just now.'];
        }
        if (!$ok) {
            return ['ok' => false, 'error_code' => 'AI_CANCEL_FAILED', 'message' => 'Could not submit the request just now.'];
        }

        if (function_exists('logUserActivity')) {
            $row = $db->get('users', ['id'], ['user_id' => $userId]);
            if ($row) { \logUserActivity($db, $row['id'], 'cancellation_request', 'GIA cancellation request for ' . $invoiceId); }
        }

        return [
            'ok'   => true,
            'data' => ['invoice_id' => $invoiceId, 'requested' => true],
            'message' => 'Your cancellation request is in. Our team will review it and process any refund you\'re '
                . 'eligible for — I can\'t confirm the exact amount or timing here, but you\'ll be updated. '
                . 'Want me to connect you to a person as well?',
        ];
    }
}
