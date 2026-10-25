<?php

namespace App\lib\ai;

/**
 * GIA tool: show the authoritative status of ONE of the signed-in traveller's
 * own bookings (R0 — read-only, owner-scoped). World-class-GIA GA5.
 *
 * Unlike get_order_status (which reads any invoice with no owner scope — gated to
 * verified users on channels), this REQUIRES the booking to belong to the
 * identified account, so a customer can safely ask "what's the status of INV-123?"
 * and get the truth from gia_commit_order_state() (never a guessed status).
 */
class myBookingDetailTool implements giaToolInterface
{
    public function name(): string
    {
        return 'get_my_booking_detail';
    }

    public function description(): string
    {
        return 'Show the real, authoritative status of ONE of the signed-in traveller\'s own '
            . 'bookings by its invoice reference (state, PNR, payment, amount). Use before '
            . 'cancelling or when they ask about a specific booking. Owner-scoped; never guess status.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'string', 'description' => 'The booking invoice reference.'],
            ],
            'required' => ['invoice_id'],
        ];
    }

    public function riskClass(): string
    {
        return 'R0';
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Booking details are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return ['ok' => false, 'error_code' => 'AI_NOT_IDENTIFIED', 'message' => 'I can only show your booking once I know who you are.'];
        }
        $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            return ['ok' => false, 'error_code' => 'AI_BAD_INVOICE', 'message' => 'Which booking? I need its reference.'];
        }

        // Owner scope first — only the traveller's own booking.
        $booking = $db->get('bookings', ['user_id', 'module_type', 'module', 'cancellation_request'], ['invoice_id' => $invoiceId]);
        if (!$booking) {
            return ['ok' => false, 'error_code' => 'AI_ORDER_NOT_FOUND', 'message' => 'I couldn\'t find that booking.'];
        }
        if ((string) ($booking['user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'error_code' => 'AI_NOT_OWNER', 'message' => 'That booking isn\'t on your account.'];
        }

        require_once dirname(__DIR__) . '/gia_commit.php';
        try {
            $st = \gia_commit_order_state($db, $invoiceId);
        } catch (\Throwable $e) {
            error_log('[gia] get_my_booking_detail: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_STATUS_ERROR', 'message' => 'Could not read that booking just now.'];
        }
        if (empty($st['ok'])) {
            return ['ok' => false, 'error_code' => 'AI_ORDER_NOT_FOUND', 'message' => (string) ($st['message'] ?? 'Booking not found.')];
        }

        return [
            'ok'   => true,
            'data' => [
                'invoice_id'           => $invoiceId,
                'service'              => (string) ($booking['module'] ?? $booking['module_type'] ?? ''),
                'state'                => (string) ($st['state'] ?? ''),
                'pnr'                  => (string) ($st['pnr'] ?? ''),
                'cancellation_pending' => (string) ($booking['cancellation_request'] ?? '0') === '1',
            ],
            'message' => (string) ($st['message'] ?? ''),
        ];
    }
}
