<?php

namespace App\lib\ai;

/**
 * GIA tool: freeze a repriced trip draft into an IMMUTABLE checkout snapshot (R2).
 *
 * Produces a signed snapshot (gia_create_checkout) over the current priced draft.
 * The snapshot binds the total, currency, pax and per-item identity; any later
 * price/pax/date change invalidates the approval. No money moves — this only
 * records the quote the user is about to be asked to approve (BRD PAY-001/002).
 *
 * Input: { draft_hash }. The draft should have been repriced first (reprice_trip).
 */
class createCheckoutTool implements giaToolInterface
{
    public function name(): string
    {
        return 'create_checkout';
    }

    public function description(): string
    {
        return 'Freeze the current trip quote into a checkout the traveler can approve. '
            . 'Returns a checkout reference, the exact total and currency, and when it '
            . 'expires. Call reprice_trip first so the total is current. This does not '
            . 'charge anything; it only prepares the quote for approval.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_hash' => [
                    'type' => 'string',
                    'description' => 'The hash of the (already repriced) trip draft to freeze.',
                ],
            ],
            'required' => ['draft_hash'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // reversible: records an immutable quote; no money moves
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Checkout is unavailable.'];
        }
        $hash = trim((string) ($input['draft_hash'] ?? ''));
        if ($hash === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_DRAFT', 'message' => 'A valid trip draft reference is required.'];
        }

        require_once dirname(__DIR__) . '/gia_checkout.php';

        try {
            $draft = $db->get('logs_bookings', ['data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] create_checkout load: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ERROR', 'message' => 'Could not load your trip draft.'];
        }
        if (!$draft || empty($draft['data'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_NOT_FOUND', 'message' => 'That trip draft was not found or has expired.'];
        }
        $draftData = json_decode((string) $draft['data'], true);
        if (!is_array($draftData) || empty($draftData['items'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_EMPTY', 'message' => 'That draft has no trip items.'];
        }

        // Build the quote for snapshotting from the draft shape (pax from draft if present).
        $quote = [
            'currency'   => (string) ($draftData['currency'] ?? ($context['currency'] ?? 'USD')),
            'items'      => $draftData['items'],
            'total'      => (float) ($draftData['total'] ?? 0),
            'adults'     => (int) ($draftData['adults'] ?? ($draftData['pax']['adults'] ?? 0)),
            'children'   => (int) ($draftData['children'] ?? ($draftData['pax']['children'] ?? 0)),
            'draft_hash' => $hash,
        ];

        $userId = (string) ($draft['user_id'] ?? ($context['account_id'] ?? ''));
        $res = \gia_create_checkout($db, $quote, $userId !== '' ? $userId : null);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error_code' => 'AI_CHECKOUT_FAILED', 'message' => (string) ($res['error'] ?? 'Could not create the checkout.')];
        }

        return [
            'ok' => true,
            'data' => [
                'checkout_ref' => (string) $res['checkout_ref'],
                'amount'       => round((float) $res['amount'], 2),
                'currency'     => (string) $res['currency'],
                'expires_at'   => (string) $res['expires_at'],
            ],
            'message' => 'Checkout prepared. Total ' . $res['currency'] . ' ' . number_format((float) $res['amount'], 2)
                . '. Ask the traveler to approve this exact amount before any payment.',
        ];
    }
}
