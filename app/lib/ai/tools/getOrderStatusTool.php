<?php

namespace App\lib\ai;

/**
 * GIA tool: truthfully report a booking's status (R1 — read-only).
 *
 * Reads the AUTHORITATIVE bookings row via gia_commit_order_state(). GIA must NEVER
 * claim a booking is confirmed from a plausible model response — only from this
 * evidence-gated state (BRD R-02, AC-004/AC-007). It distinguishes CONFIRMED (paid +
 * PNR) from PARTIAL (some items failed — charged but not fully booked), PAID_PENDING,
 * UNPAID and FAILED, and flags states that need operations attention.
 */
class getOrderStatusTool implements giaToolInterface
{
    public function name(): string
    {
        return 'get_order_status';
    }

    public function description(): string
    {
        return 'Check the real, authoritative status of a booking by its invoice reference. '
            . 'Returns one of: confirmed (paid and issued), partial (some items failed — needs '
            . 'attention), paid-pending (paid, issuance in progress), unpaid, or failed. '
            . 'Always use this to report status; never assume a booking is confirmed.';
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
        return 'R1'; // read-only authenticated
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Status is unavailable.'];
        }
        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9]{4,40}$/', $invoiceId)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_INVOICE', 'message' => 'A valid booking reference is required.'];
        }

        require_once dirname(__DIR__) . '/gia_commit.php';

        try {
            $st = \gia_commit_order_state($db, $invoiceId);
        } catch (\Throwable $e) {
            error_log('[gia] get_order_status: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_STATUS_ERROR', 'message' => 'Could not read the booking status.'];
        }

        if (empty($st['ok'])) {
            return ['ok' => false, 'error_code' => 'AI_ORDER_NOT_FOUND', 'message' => (string) ($st['message'] ?? 'Booking not found.')];
        }

        return [
            'ok' => true,
            'data' => [
                'state'        => (string) ($st['state'] ?? ''),
                'invoice_id'   => $invoiceId,
                'pnr'          => (string) ($st['pnr'] ?? ''),
                'failed_items' => is_array($st['failed_items'] ?? null) ? $st['failed_items'] : [],
                'needs_ops'    => (bool) ($st['needs_ops'] ?? false),
            ],
            'message' => (string) ($st['message'] ?? ''),
        ];
    }
}
